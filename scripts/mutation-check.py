#!/usr/bin/env python3
"""Breaks one security rule at a time and confirms the test suite notices.

A green suite proves little unless the tests fail when the code is wrong.
Run from the repository root with the local stack up: python scripts/mutation-check.py
"""
import subprocess
import sys
from pathlib import Path

SRC = Path('wordpress/plugin/quillmoor-booking/src')

MUTATIONS = [
    ('replay protection removed', 'Security/Signer.php',
     "return $this->remember_nonce( $key_id, $nonce );", "return true;", 'SignedCallbackTest'),
    ('signature check removed', 'Security/Signer.php',
     "if ( ! hash_equals( $expected, $signature ) ) {", "if ( false ) {", 'SignedCallbackTest'),
    ('timestamp window removed', 'Security/Signer.php',
     "abs( time() - (int) $timestamp ) > Config::SIGNATURE_WINDOW", "false", 'SignedCallbackTest'),
    ('anyone owns any booking', 'Security/BookingPolicy.php',
     "return null !== $customer && (int) $customer['user_id'] === $actor->user_id;", "return true;", 'AccessControlTest'),
    ('hosts see every booking', 'Security/BookingPolicy.php',
     "&& (int) $booking['host_user_id'] === $actor->user_id\n", "\n", 'AccessControlTest'),
    ('live keys not detected', 'Security/StripeKeyGuard.php',
     "return is_string( $value ) && 1 === preg_match( '/^\\s*(sk|rk|pk)_live_/', $value );", "return false;", 'StripeKeyGuardTest'),
    ('expired holds keep their nights', 'Domain/BookingService.php',
     "'hold expired' ) ) {\n\t\t\t\t$this->release_claims( $booking );", "'hold expired' ) ) {", 'HoldTest'),
    ('table capacity ignored', 'Domain/TableSlots.php',
     "AND closed = 0 AND booked + %d <= capacity", "AND closed = 0 AND %d > 0", 'HoldTest'),
    ('email cap off', 'Security/Counters.php',
     "return $rows > 0;", "return true;", 'MailTest'),
    ('unverified addresses get mail', 'Mail/MailService.php',
     "if ( 'verify' !== $kind && empty( $customer['email_verified_at'] ) ) {", "if ( false ) {", 'MailTest'),
    ('turnstile result ignored', 'Security/Turnstile.php',
     "return is_array( $body ) && true === ( $body['success'] ?? false );", "return true;", 'AbuseTest'),
    ('reset runs anywhere', 'Demo/DemoReset.php',
     "return '' !== $host && Config::get( 'QHS_DEMO_HOST' ) === $host;", "return true;", 'DemoSafetyTest'),
    ('demo users edit themselves', 'Demo/DemoGuard.php',
     "if ( in_array( $cap, array( 'edit_user', 'manage_woocommerce' ), true ) && self::is_demo_user( $user_id ) ) {", "if ( false ) {", 'DemoSafetyTest'),
    ('AI suggestion writes the booking', 'Rest/MachineApi.php',
     "$wpdb->insert(\n\t\t\tDb::table( 'triage' ),", "Services::bookings()->update( (int) $booking['id'], array( 'priority' => $priority ) );\n\t\t$wpdb->insert(\n\t\t\tDb::table( 'triage' ),", 'CrmAndTriageTest'),
    ('wp_mail left open', 'Plugin.php',
     "add_filter( 'pre_wp_mail', '__return_false' );", "", 'MailTest'),
    ('stale dispatch accepted', 'Domain/BookingService.php',
     "if ( (int) $booking['version'] !== $version || BookingState::Confirmed->value !== $booking['state'] ) {", "if ( BookingState::Confirmed->value !== $booking['state'] ) {", 'SignedCallbackTest'),
]


def run(test_filter):
    result = subprocess.run(['sh', 'scripts/test-wp.sh', '--filter', test_filter], capture_output=True, text=True)
    return result.returncode, result.stdout + result.stderr


missed = 0
for name, rel, old, new, test_filter in MUTATIONS:
    path = SRC / rel
    original = path.read_bytes()
    text = original.decode('utf8')
    if old not in text:
        print(f'SKIPPED   {name}: pattern not found')
        missed += 1
        continue
    try:
        path.write_bytes(text.replace(old, new, 1).encode('utf8'))
        code, output = run(test_filter)
    finally:
        path.write_bytes(original)
    if code == 0:
        print(f'SURVIVED  {name}')
        missed += 1
    else:
        summary = [line for line in output.splitlines() if line.startswith(('FAILURES!', 'Tests:', 'ERRORS!'))]
        print(f'caught    {name}: {" ".join(summary)}')

print(f'{len(MUTATIONS) - missed} of {len(MUTATIONS)} mutations caught')
sys.exit(1 if missed else 0)
