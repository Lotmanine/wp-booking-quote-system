# Booking and Quote System

A working booking system for a fictional home services company, built on WordPress and
WooCommerce with a Laravel dispatch service and n8n automation. A visitor gets an instant quote,
holds a slot for ten minutes, pays a test deposit, and staff take the job through to completion.

**Quillmoor Home Services is invented.** No real services or payments are offered, all prices are
fictional, and Stripe runs in test mode only.

Built by [Lotfi Otmanine](https://oldev.site).

## What it does

- **Quote wizard:** rule-based pricing by service, size, area and extras, computed on the server.
- **Availability and holds:** slots are held for ten minutes, then released automatically.
- **Double-booking protection:** a unique database key decides who gets a slot, so it holds under
  concurrent requests.
- **Deposits:** through WooCommerce and Stripe test mode, compatible with HPOS and the Checkout block.
- **State machine:** quote, hold, deposit paid, confirmed, assigned, completed or cancelled.
- **Portals:** customers view, reschedule or cancel. Staff see their schedule and update job status.
- **Admin:** a calendar and a reporting screen, plus PDF quotes and confirmations.
- **Dispatch:** a Laravel service assigns staff by area, skill and load, and answers over signed requests.
- **Automation:** n8n handles booking events, reminders, follow-ups, alerts and AI triage of
  customer notes. AI suggestions wait for a person and never change a booking by themselves.
- **CRM:** FluentCRM stages and tags follow booking events, with double opt-in consent.
- **Demo safety:** a banner on every page, restricted demo logins and a nightly reset.

## Architecture

```mermaid
flowchart LR
    V[Visitor] -->|HTTPS, Turnstile, per-IP limits| WP
    subgraph Site[booking.oldev.site]
        WP[WordPress and WooCommerce<br/>state machine, outbox]
        D[Laravel dispatch<br/>behind /dispatch/]
        DB[(MariaDB)]
        WP --- DB
        D --- DB
    end
    WP -->|signed event| N[n8n workflows]
    N -->|API key| D
    D -->|signed decision| WP
    N -->|signed callbacks| WP
    N --> AI[Claude: priority suggestion]
    WP --> S[Stripe test mode]
    WP --> B[Brevo: capped at 50 per day]
    WP --> F[FluentCRM]
```

WordPress owns the booking state. n8n and the dispatch service can only ask for changes through
signed callbacks, and every change passes through one state machine. See [PLAN.md](PLAN.md) for the
data model, the state table, the message contracts and the threat model.

### Decisions worth a look

**The database decides who gets a slot.** Claiming one is a single `INSERT IGNORE` against
`UNIQUE(area, slot_start, unit)`, so there is no gap between checking and writing. State changes are
conditional updates: `UPDATE ... WHERE id = ? AND state = ?`. One row changed means the caller won.

**Signed messages survive JSON parsers.** The payload travels as a JSON string inside JSON, so its
exact bytes reach the verifier even through an n8n webhook. Every receiver checks the key id, a 300
second timestamp window, the signature in constant time, and then a single-use nonce.

**A late answer cannot do damage.** A dispatch decision carries the booking version it was made for,
so an answer about an old slot is ignored after a reschedule.

**Live payments are impossible by construction.** `sk_live_`, `rk_live_` and `pk_live_` values are
refused at boot and on save, and a live key closes every booking route and removes every gateway.

**Events are stored before they are sent.** An outbox row is written with each state change, and a
job delivers it with retries, so an n8n outage delays side effects and loses nothing.

## Screenshots

| Quote and slot hold | Staff schedule |
|---|---|
| ![Slot on hold](docs/screenshots/desktop-hold.png) | ![Staff schedule](docs/screenshots/desktop-staff-schedule.png) |

| Booking assigned | Admin calendar |
|---|---|
| ![Booking assigned](docs/screenshots/desktop-assigned.png) | ![Admin calendar](docs/screenshots/desktop-admin-calendar.png) |

| Reports | Phone width |
|---|---|
| ![Reports](docs/screenshots/desktop-admin-reports.png) | ![Phone](docs/screenshots/phone-hold.png) |

These are captures from the automated test run, which uses the local fake gateway.

## Run it locally

You need Docker and Node 22.

```sh
cp .env.example .env
```

Edit `.env`:

- Replace every `change-me` with a random value.
- Set `WP_HOME=http://localhost:18091`, `QHS_DEMO_HOST=localhost` and `QHS_PAYMENT_MODE=fake`.
- Use Cloudflare's published Turnstile test keys: site key `1x00000000000000000000AA`, secret
  `1x0000000000000000000000000000000AA`.
- Set `QHS_N8N_EVENTS_URL=http://n8n:5678/webhook/qhs-booking-events` and
  `QHS_N8N_TRIAGE_URL=http://n8n:5678/webhook/qhs-booking-triage`.
- Raise `QHS_LIMIT_QUOTE_PER_MIN` and `QHS_LIMIT_HOLD_PER_MIN` to 200 for the test suites.

Then:

```sh
docker compose --env-file .env -f docker-compose.yml -f docker-compose.local.yml up -d --build
sh scripts/n8n-local-import.sh
```

| Service | URL |
|---|---|
| Site | http://localhost:18091 |
| Dispatch | http://localhost:18092 |
| Local n8n | http://localhost:18093 |

Fake payment mode exists for local tests only. It is clearly labelled, it is not Stripe, and it
moves no money.

## Tests

Composer packages for the plugin must be installed first:

```sh
docker run --rm -v "$PWD/wordpress/plugin/quillmoor-booking":/app -w /app composer:2.11.1 composer install --ignore-platform-reqs
docker run --rm -v "$PWD/dispatch":/app -w /app composer:2.11.1 composer install --ignore-platform-reqs --no-scripts
```

```sh
# WordPress integration tests: real WooCommerce and FluentCRM against MariaDB
sh scripts/test-wp.sh

# PHPCS and PHPStan
docker run --rm -v "$PWD/wordpress":/w -w /w/plugin/quillmoor-booking php:8.5.12-cli php vendor/bin/phpcs
docker run --rm -v "$PWD/wordpress":/w -w /w/plugin/quillmoor-booking php:8.5.12-cli php vendor/bin/phpstan analyse --memory-limit=1G

# Laravel tests
docker run --rm -v "$PWD/dispatch":/app -w /app php:8.5.12-cli vendor/bin/phpunit

# Parallel holds against the running stack
node tests/concurrency/parallel-hold.mjs

# Breaks each security rule in turn and confirms the suite notices
python scripts/mutation-check.py

# Browser tests at desktop and phone width
cd tests/e2e && npm ci && npx playwright install chromium && npx playwright test
```

### Results from the last full run

| Check | Result |
|---|---|
| WordPress PHPUnit | 159 tests, 301 assertions, passing on PHP 8.5.12 |
| Laravel PHPUnit | 21 tests, 55 assertions, passing on PHP 8.5.12 |
| PHPCS (WordPress-Core and WordPress-Extra) | no errors or warnings |
| PHPStan level 6 | no errors |
| Playwright | 12 tests, passing at desktop and phone width |
| Parallel holds | 6 rounds of 12 parallel requests: 1 winner per slot, 2 where two crews exist |
| Control | with the unique key dropped, all 12 requests won, so the test does detect double booking |
| Mutation check | 16 of 16 deliberate security breaks caught |

These runs used the labelled fake gateway. The automation path ran through the exported n8n
workflows in a local n8n container, with real schedule ticks for reminders and follow-ups. With no
Anthropic key available locally, AI triage exercised its fallback path, not a live model answer.

`python scripts/prove-pipeline.py` repeats the automation proof. It can take over an hour, because it
waits for the real schedules.

## Demo logins

The password is shown on the `/demo/` page of the running site.

| Role | Username | Can do |
|---|---|---|
| Customer | `demo-customer` | Quote, book, reschedule, cancel |
| Staff | `demo-staff` | See assigned jobs, update status, review AI suggestions |
| Admin | `demo-admin` | Calendar, reports and triage only. Not a WordPress administrator |

Demo accounts cannot change their password or email. Data resets every night at 03:00 UTC.

## Deploying

See [DEPLOY.md](DEPLOY.md).

## Versions

WordPress 7.1.4, WooCommerce 11.2.1, WooCommerce Stripe Gateway 11.1.1, FluentCRM 3.3.1, PHP 8.5.12,
MariaDB 11.8.9, Laravel 13.39.0, n8n 2.45.7.
