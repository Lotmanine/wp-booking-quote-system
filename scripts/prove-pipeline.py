#!/usr/bin/env python3
"""Proves the automation path on the local stack, with the exported workflows running in local n8n.

Payment uses the labelled fake gateway hook, so this is not a Stripe payment.
Run from the repository root: python scripts/prove-pipeline.py
"""
import json
import subprocess
import sys
import time
import urllib.request

COMPOSE = ['docker', 'compose', '--env-file', '.env', '-f', 'docker-compose.yml', '-f', 'docker-compose.local.yml']
API = 'http://localhost:18091/wp-json/qhs/v1'
TOKEN = 'XXXX.DUMMY.TOKEN.XXXX'
stamp = str(int(time.time()))
# Not a reserved domain, so the mail gate treats it as deliverable. The log-only transport sends nothing.
EMAIL = f'proof-{stamp}@quillmoor-demo.co'
failures = 0


def check(label, ok, detail=''):
    global failures
    failures += 0 if ok else 1
    print(f"{'PASS' if ok else 'FAIL'}  {label}  {detail}")


def compose(*args):
    return subprocess.run(COMPOSE + list(args), capture_output=True, text=True).stdout.strip()


def sql(query):
    script = 'mariadb -N -ubooking -p"$MARIADB_PASSWORD" booking -e "$1"'
    return compose('exec', '-T', 'db', 'sh', '-c', script, '--', query)


def wp(*args):
    return compose('exec', '-T', '-u', 'www-data', 'wordpress', 'wp', *args)


def post(path, body, headers=None):
    request = urllib.request.Request(f'{API}/{path}', json.dumps(body).encode(), {'Content-Type': 'application/json', **(headers or {})})
    with urllib.request.urlopen(request) as response:
        return json.load(response)


def wait_for(query, expected, seconds=150):
    deadline = time.time() + seconds
    while time.time() < deadline:
        value = sql(query)
        if value == expected:
            return value
        wp('qhs', 'deliver-outbox')
        time.sleep(4)
    return sql(query)


def booking(email, note=''):
    quote = post('quote', {'service': 'cleaning', 'size': 'small', 'area': 'central', 'extras': [], 'full_name': 'Proof Person',
                           'email': email, 'note': note, 'turnstile_token': TOKEN})
    return quote['booking']['id'], quote['token']


def pay(booking_id, token, index):
    slots = sum(json.load(urllib.request.urlopen(f'{API}/availability?area=central'))['days'].values(), [])
    post('hold', {'booking_id': booking_id, 'slot_start': slots[index], 'turnstile_token': TOKEN}, {'X-QHS-Token': token})
    wp('eval', f'\\Quillmoor\\Booking\\Services::service()->on_deposit_paid({booking_id}, 1);')


# Keep the proof to its own booking: earlier bookings are already past their reminder.
sql("UPDATE qwp_qhs_bookings SET reminder_sent_at = NOW() WHERE reminder_sent_at IS NULL")

print('--- happy path')
note = 'Please call first, the gate code changed.'
booking_id, token = booking(EMAIL, note)
sql(f"UPDATE qwp_qhs_customers SET email_verified_at = NOW() WHERE email = '{EMAIL}'")
pay(booking_id, token, 0)

state = wait_for(f'SELECT state FROM qwp_qhs_bookings WHERE id = {booking_id}', 'assigned')
check('n8n and Laravel assigned staff', state == 'assigned', f'state={state}')
staff = sql(f"SELECT u.display_name FROM qwp_qhs_bookings b JOIN qwp_users u ON u.ID = b.staff_user_id WHERE b.id = {booking_id}")
print(f'      assigned to: {staff}')

synced = wait_for(f"SELECT COUNT(*) FROM qwp_qhs_outbox WHERE booking_id = {booking_id} AND target = 'events' AND crm_synced_at IS NULL", '0', 60)
events = sql(f"SELECT COUNT(*) FROM qwp_qhs_outbox WHERE booking_id = {booking_id} AND target = 'events'")
check('every booking event reached the CRM sync', synced == '0', f'events={events}')

tags = wp('eval', f"echo implode(' ', \\Quillmoor\\Booking\\Services::crm()->tags_for('{EMAIL}'));")
check('FluentCRM contact has the assigned stage', 'stage-assigned' in tags.split(), tags)
stages = [t for t in tags.split() if t.startswith('stage-')]
check('contact carries exactly one stage', len(stages) == 1, str(stages))

triage = wait_for(f"SELECT COUNT(*) FROM qwp_qhs_triage WHERE booking_id = {booking_id}", '1', 60)
row = sql(f"SELECT suggested_priority, source, status FROM qwp_qhs_triage WHERE booking_id = {booking_id}")
check('triage stored a suggestion for review', triage == '1', row)
priority = sql(f'SELECT priority FROM qwp_qhs_bookings WHERE id = {booking_id}')
check('the suggestion did not change the booking', priority == 'normal', f'priority={priority}')

print('--- error path: dispatch stopped, so n8n retries, fails and alerts')
before = sql('SELECT COUNT(*) FROM qwp_qhs_alerts')
compose('stop', 'dispatch')
try:
    second_id, second_token = booking(f'proof2-{stamp}@quillmoor-demo.co')
    pay(second_id, second_token, 1)
    wp('qhs', 'deliver-outbox')
    deadline = time.time() + 90
    while time.time() < deadline and sql('SELECT COUNT(*) FROM qwp_qhs_alerts') == before:
        time.sleep(4)
finally:
    compose('start', 'dispatch')
alert = sql('SELECT message FROM qwp_qhs_alerts ORDER BY id DESC LIMIT 1')
check('the failure reached the alert log', sql('SELECT COUNT(*) FROM qwp_qhs_alerts') != before, alert[:120])
state = sql(f'SELECT state FROM qwp_qhs_bookings WHERE id = {second_id}')
check('the booking stayed safe in confirmed', state == 'confirmed', f'state={state}')

print('--- real schedules: waits for the reminder tick (15 min) and the follow-up tick (60 min)')
done_id, done_token = booking(f'proof3-{stamp}@quillmoor-demo.co')
sql(f"UPDATE qwp_qhs_customers SET email_verified_at = NOW() WHERE email = 'proof3-{stamp}@quillmoor-demo.co'")
pay(done_id, done_token, 2)
wait_for(f'SELECT state FROM qwp_qhs_bookings WHERE id = {done_id}', 'assigned')
wp('eval', f"\\Quillmoor\\Booking\\Services::service()->update_job({done_id}, 'completed', 'proof');")
stage = wait_for(f"SELECT COUNT(*) FROM qwp_qhs_outbox WHERE booking_id = {done_id} AND event = 'booking.completed' AND crm_synced_at IS NOT NULL", '1', 60)
tags = wp('eval', f"echo implode(' ', \\Quillmoor\\Booking\\Services::crm()->tags_for('proof3-{stamp}@quillmoor-demo.co'));")
check('FluentCRM stage moved to completed', 'stage-completed' in tags.split() and 'stage-assigned' not in tags.split(), tags)

deadline = time.time() + 66 * 60
reminder = followup = ''
while time.time() < deadline and not (reminder and followup):
    reminder = reminder or sql(f"SELECT CONCAT(status, ' via ', transport, ' at ', created_at) FROM qwp_qhs_mail_log WHERE booking_id = {booking_id} AND kind = 'reminder' LIMIT 1")
    followup = followup or sql(f"SELECT CONCAT(status, ' via ', transport, ' at ', created_at) FROM qwp_qhs_mail_log WHERE booking_id = {done_id} AND kind = 'followup' LIMIT 1")
    time.sleep(20)
check('reminder schedule fired before the slot', reminder.startswith('sent'), reminder)
check('follow-up schedule fired after completion', followup.startswith('sent'), followup)
count = sql(f"SELECT COUNT(*) FROM qwp_qhs_mail_log WHERE booking_id = {booking_id} AND kind = 'reminder'")
check('later ticks did not send the reminder twice', count == '1', f'rows={count}')

print('FAILED' if failures else 'All checks passed.')
sys.exit(1 if failures else 0)
