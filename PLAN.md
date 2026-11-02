# Plan: Booking and Quote System

A public, working demo of the business system a home services company runs on WordPress.
A visitor gets an instant quote, holds a slot, pays a test deposit, and staff take the job
through to completion.

## 1. Fictional company

**Quillmoor Home Services** (invented name, fictional prices, no real services).

Name check, run 2026-11-02 with web search:

| Query | Result |
|---|---|
| `"Quillmoor Home Services"` | no matching business found |
| `Quillmoor cleaning plumbing handyman company` | no matching business found |
| `Quillmoor company` | no matching business found |
| `"Brightnest Home Services"` (first candidate) | rejected: similar names are in use |

A search cannot prove a negative. The statement is limited to: no matching business found in
these searches.

## 2. Architecture

```
Browser
  | HTTPS (Turnstile, nonce, per-IP limits)
  v
WordPress 7.1.4 + WooCommerce 11.2.1 (block theme "quillmoor", plugin "quillmoor-booking")
  |   outbox + Action Scheduler                    ^
  |   signed event                                 | signed callbacks
  v                                                |
n8n workflows (events, reminders, follow-up, AI triage, error alert)
  |   API key                                      ^
  v                                                |
Laravel 13 dispatch service  ---- signed decision -+
  (database queue, separate database)

WordPress -> Stripe through WooCommerce Stripe Gateway 11.1.1, test mode only
WordPress -> Brevo transactional API, from lotfi@oldev.site, capped at 50 per day
WordPress -> FluentCRM (same site), stage tags and consent
```

Decisions:

- **WordPress owns the booking state.** n8n and the dispatch service only ask for changes
  through signed callbacks, and every change passes through the same state machine.
- **Outbox, not fire and forget.** Every state change writes an event row in the same request.
  An Action Scheduler job delivers it to n8n with retries, so an n8n outage delays side effects
  and loses nothing.
- **n8n never sends customer email.** Reminder and follow-up workflows ask WordPress to send,
  so the verified-address rule and the daily cap live in one place.
- **Dispatch uses one public hostname.** Only `booking.oldev.site` exists. Apache in the
  WordPress container proxies `/dispatch/` to the internal dispatch container, and the API key
  still applies. This avoids depending on two Coolify services sharing a domain by path.
- **Deposit goes through the cart.** The deposit is a hidden virtual product added to the cart
  with the booking id, so it works on the Checkout block. The pay-for-order page falls back to
  the classic form, so it is not used. Order data is read through the CRUD API only (HPOS safe).

## 3. Data model

Custom tables, prefix `{wp_prefix}qhs_`. Created with `dbDelta`, removed by nothing in normal
operation; the demo reset truncates only rows the demo owns.

| Table | Purpose | Key constraints |
|---|---|---|
| `customers` | email, name, user_id, email_verified_at, marketing_consent, consent_at, consent_text_version | UNIQUE(email) |
| `quotes` | service, size, area, extras JSON, line items JSON, total_cents, deposit_cents, valid_until | INDEX(customer_id) |
| `bookings` | quote_id, customer_id, state, version, slot_start, area, order_id, staff_user_id, priority, access_key | INDEX(state, slot_start), INDEX(customer_id) |
| `slot_claims` | area, slot_start, unit, booking_id, expires_at | **UNIQUE(area, slot_start, unit)** |
| `booking_history` | booking_id, from_state, to_state, actor, reason, created_at | INDEX(booking_id) |
| `outbox` | event, booking_id, payload JSON, attempts, delivered_at, last_error | INDEX(delivered_at) |
| `nonces` | key_id, nonce, expires_at | **UNIQUE(key_id, nonce)** |
| `counters` | counter_key, window_start, count | **UNIQUE(counter_key, window_start)** |
| `mail_log` | to_hash, kind, booking_id, transport, status, created_at | INDEX(created_at) |
| `triage` | booking_id, suggested_priority, reason, status (pending, accepted, dismissed), reviewed_by | INDEX(booking_id) |
| `alerts` | source, message, created_at | INDEX(created_at) |

Catalog (services, sizes, areas, extras, rates) is a PHP-defined array, filterable, so a
reviewer can read the rules in one file.

Pricing rule, all in integer cents:

```
base(service) x size multiplier + area surcharge + sum(extras)
deposit = 20 percent of total, rounded to 100 cents, minimum 2000 cents
```

### Why the unique key decides double booking

`slot_claims` has one row per occupied unit of a slot. Taking a slot is a single
`INSERT IGNORE`, so MariaDB decides the winner and no PHP check-then-write window exists.
Before inserting, expired rows for that exact key are deleted. Two racers can both delete and
both insert, and only one insert survives the unique key.

## 4. Booking state machine

```
quote -> hold -> deposit_paid -> confirmed -> assigned -> completed
  ^       |
  +-------+ (hold expired after 10 minutes)

Any state before completed -> cancelled
assigned -> confirmed on reschedule (staff must be chosen again)
```

| From | To | Trigger | Actor |
|---|---|---|---|
| quote | hold | slot claim succeeds | customer |
| hold | quote | hold expired | system |
| hold | deposit_paid | WooCommerce payment complete | system |
| deposit_paid | confirmed | claim made permanent, confirmation queued | system |
| confirmed | assigned | signed dispatch decision | dispatch |
| assigned | confirmed | reschedule | customer |
| assigned | completed | staff marks done | staff |
| quote, hold, deposit_paid, confirmed, assigned | cancelled | cancel | customer, staff lead, admin, system |

Transitions run as `UPDATE ... SET state = :to, version = version + 1 WHERE id = :id AND
state = :from`. One row changed means the caller won; zero means another request got there
first. Illegal transitions throw before any write.

A payment that lands after its hold expired tries to claim the slot again. If the slot is gone,
the booking is cancelled, the order is flagged for refund and an alert is logged.

## 5. Signed message contracts

Envelope used in every direction:

```
POST <url>
X-QHS-Key-Id: n8n | dispatch
X-QHS-Timestamp: <unix seconds>
X-QHS-Nonce: <32 hex chars>
X-QHS-Signature: v1=<hex HMAC-SHA256 of "<timestamp>.<nonce>.<payload>">
Content-Type: application/json

{"payload":"<JSON document, as a string>"}
```

`payload` is a JSON string inside JSON, so its exact bytes survive any JSON parser in between,
including n8n webhooks. That is what makes the signature checkable without raw body access.

Rules on receipt, in this order: known key id, timestamp within 300 seconds, constant-time
signature compare (`hash_equals`), nonce insert into a unique key (a duplicate is a replay),
then decode. Any failure returns 401 and logs nothing from the payload.

| Message | Direction | Payload |
|---|---|---|
| Booking event | WordPress to n8n | `{event, event_id, booking_id, ref, state, version, area, service, skill, slot_start, has_note}` |
| Triage request | WordPress to n8n | `{event_id, booking_id, note}` |
| Dispatch request | n8n to Laravel (API key) | `{booking_id, version, area, skill, slot_start}` |
| Dispatch cancel | n8n to Laravel (API key) | `{booking_id}` |
| Dispatch decision | Laravel to WordPress | `{booking_id, version, outcome, staff_ref, reason}` |
| CRM sync | n8n to WordPress | `{event_id, booking_id, event}` |
| Reminder run | n8n to WordPress | `{run_id, hours_ahead}` |
| Follow-up run | n8n to WordPress | `{run_id, hours_after}` |
| Triage result | n8n to WordPress | `{booking_id, priority, reason, source}` |
| Alert | n8n to WordPress | `{source, message}` |

Dispatch decisions are ignored if `version` does not match the booking, so a late answer for an
old slot cannot assign staff to a rescheduled job.

## 6. Threat model

| Threat | Control |
|---|---|
| Double booking under load | unique key on the claim, conditional state updates |
| Slot exhaustion by bots | Turnstile verified server-side, per-IP limits on quote and hold, 10 minute expiry, per-customer active hold limit |
| IDOR on bookings | `BookingPolicy` on every read and write: owner by user id or by a booking token derived from a per-booking key and the site salt, staff only for assigned jobs, managers by capability |
| Forged or replayed callbacks | HMAC, timestamp window, unique nonce, `hash_equals` |
| Live payments on a demo | `StripeKeyGuard` refuses `sk_live_`, `rk_live_` and `pk_live_` at boot and on save, forces test mode, and removes the gateway, failing closed |
| Payment for an expired hold | re-claim or cancel with refund flag |
| Tampered price | price computed on the server from the stored quote, never read from the client or the cart |
| Email abuse | verified address required, per-address limit, global cap of 50 per day enforced by an atomic counter, `wp_mail()` closed so core and WooCommerce mail cannot bypass the gate |
| Spoofed From and DMARC failure | Brevo API only, no `wp_mail()`, fixed sender `lotfi@oldev.site`, no user-controlled headers |
| Prompt injection in customer notes | note is data inside delimiters, output limited to an enum and a short reason, suggestion stored apart from the booking, staff must accept it |
| Demo account takeover or defacement | `edit_user` denied for demo users, password reset off, application passwords off, screen allowlist, restricted manager role instead of administrator |
| Reset wiping a real site | needs `QHS_DEMO_SITE=1` and a host match, only touches demo rows |
| Stored XSS from notes | output escaped at render, plain text only in the portal |
| SQL injection | `$wpdb->prepare` with `%i` for identifiers |
| Secret leak | environment only, placeholders in `.env.example`, gitleaks over full history before the first push |
| CSRF on portal | REST nonce for cookie sessions, token header for guest links |
| Spoofed client IP | `X-Forwarded-For` trusted only from configured proxy ranges |

Out of scope: real payments, multi-language, SMS or WhatsApp sending, multisite.

## 7. Delivery order

1. Local stack and plugin skeleton.
2. Quote, availability, holds, state machine, deposit, portals, admin, PDFs, tests.
3. Laravel dispatch service and tests.
4. n8n workflows, created on n8n.oldev.site and left unpublished.
5. FluentCRM pipeline, demo safety.
6. Playwright, concurrency test, static analysis.
7. Docker, DEPLOY.md, README, secret scan, public repo.
