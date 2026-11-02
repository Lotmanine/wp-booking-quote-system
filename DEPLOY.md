# Deploying to Coolify

Target: `https://booking.oldev.site`. The DNS record already exists: `booking` is an A record to
`76.13.63.48`, DNS-only.

Do the steps in order. Nothing here touches any other application on the server, except the
n8n restart in step 4.

## 1. Create the application

1. In Coolify, add a new resource from the public repository `Lotmanine/wp-booking-quote-system`,
   branch `main`.
2. Build pack: **Docker Compose**. Compose file: `docker-compose.yml`.
3. Give the domain to the `wordpress` service only: `https://booking.oldev.site`. Leave every
   other service without a domain.

Why only one domain: Apache in the `wordpress` container proxies `/dispatch/` to the internal
`dispatch` container, so the dispatch API needs no hostname of its own. Its public base URL is
`https://booking.oldev.site/dispatch`. Every route still requires the API key, except `/up`,
which reveals nothing.

## 2. Set the environment

Copy `.env.example` into Coolify's environment tab and replace every `change-me`.

```sh
openssl rand -hex 32   # run once for each salt and secret
```

`DISPATCH_APP_KEY` must look like `base64:` followed by 32 random bytes in base64:

```sh
echo "base64:$(openssl rand -base64 32)"
```

`QHS_DEMO_PASSWORD` is public by design: it is printed on the `/demo/` page.

Save `QHS_HMAC_SECRET_N8N` and `DISPATCH_API_KEY`: step 4 needs them too.

## 3. Add the accounts

| Service | What to do | Variables |
|---|---|---|
| Cloudflare Turnstile | Create a widget for `booking.oldev.site` | `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY` |
| Stripe, test mode | Developers, API keys, while the test mode switch is on | `STRIPE_TEST_SECRET_KEY`, `STRIPE_TEST_PUBLISHABLE_KEY` |
| Stripe, test mode | Add webhook `https://booking.oldev.site/?wc-api=wc_stripe` | `STRIPE_TEST_WEBHOOK_SECRET` |
| Brevo | SMTP and API, create a key named for this project | `BREVO_API_KEY` |

Never paste a live Stripe key. The site refuses `sk_live_`, `rk_live_` and `pk_live_` values at
boot and on save, and then closes every booking route until the value is removed.

Without test keys the deposit step answers "Deposits are not set up yet". Without a Brevo key,
email is written to the mail log and nothing is sent.

Deploy. The first start takes about two minutes: it installs WordPress, activates the plugins and
seeds the demo accounts. Watch for `Site ready at` in the `wordpress` logs.

## 4. Connect n8n

The five workflows already exist on `n8n.oldev.site`, unpublished:

| Workflow | ID |
|---|---|
| QHS Booking Alerts | `5qpIqCXYRXAcyTwa` |
| QHS Booking Events | `hsEsqmQRhrriiLZW` |
| QHS Booking Reminders | `aONh1Sswi5Ude4Ix` |
| QHS Booking Follow-up | `AoXOmSxBKSj8mGyV` |
| QHS Booking AI Triage | `BX82JeAPish7AJhS` |

1. In the n8n application on Coolify, add these variables:

   ```
   QHS_N8N_HMAC_SECRET=<same value as QHS_HMAC_SECRET_N8N>
   QHS_WP_URL=https://booking.oldev.site
   QHS_DISPATCH_URL=https://booking.oldev.site/dispatch
   QHS_DISPATCH_API_KEY=<same value as DISPATCH_API_KEY>
   QHS_TELEGRAM_CHAT_ID=<the chat ID used by the Telegram nodes in the Free Deliverability Audit workflow>
   QHS_AI_MODEL=claude-haiku-5-5
   QHS_REMINDER_HOURS_AHEAD=24
   QHS_FOLLOWUP_HOURS_AFTER=2
   N8N_BLOCK_ENV_ACCESS_IN_NODE=false
   ```

   The last line lets workflows read these variables. It applies to every workflow on the
   instance, so any expression in n8n can then read n8n's environment. Keep that in mind if other
   people edit workflows there.

2. Restart n8n. This briefly interrupts the other workflows on the instance, so pick a quiet moment.
3. Open **QHS Booking Alerts**, check that the Telegram node shows the `Telegram Lead Bot`
   credential, and publish it. It must go first: n8n only accepts a published workflow as an
   error workflow.
4. In each of the other four, open Settings, set **Error Workflow** to QHS Booking Alerts, and set
   the timezone to UTC.
5. Open **QHS Booking AI Triage** and check that the Claude node shows the `OLDEV- Claude API`
   credential.
6. Publish Events, AI Triage, Reminders and Follow-up, in that order.

## 5. Check it works

```sh
curl -s -o /dev/null -w "%{http_code}\n" https://booking.oldev.site/             # 200
curl -s -o /dev/null -w "%{http_code}\n" https://booking.oldev.site/dispatch/up  # 200
curl -s -o /dev/null -w "%{http_code}\n" -X POST -H "Content-Type: application/json" \
  -d '{"payload":"{}"}' https://booking.oldev.site/wp-json/qhs/v1/n8n/alert      # 401
curl -s -o /dev/null -w "%{http_code}\n" -X POST -H "Content-Type: application/json" \
  -d '{}' https://n8n.oldev.site/webhook/qhs-booking-events                      # 401
curl -s -o /dev/null -w "%{http_code}\n" \
  https://booking.oldev.site/dispatch/api/v1/assignments/1                       # 401 or 405
```

Then run one test payment:

1. Sign in on `/demo/` as `demo-customer`, open a service from `/services/`, pick a district, a day and a time on the reservation card, and hold the slot.
2. Pay with Stripe's test card `4242 4242 4242 4242`, any future expiry, any CVC.
3. Refresh the portal. Within about two minutes the booking should reach **Staff assigned**.
4. Sign in as `demo-admin` and open Bookings, Reports. "Events waiting for n8n" should be 0.
5. Sign in as `demo-staff` and take the job through to complete.

Quote and hold are limited per IP, and each hold lasts ten minutes.

## Memory

The VPS has 8 GB of RAM with about 3 GB free. Each service has a hard limit in `docker-compose.yml`.

| Service | Limit | Measured peak under load |
|---|---|---|
| `wordpress` | 560 MiB | 483 MiB |
| `db` | 420 MiB | 132 MiB |
| `dispatch` | 192 MiB | 38 MiB |
| `dispatch-worker` | 128 MiB | 38 MiB |
| `wp-cron` | 16 MiB | 2 MiB |
| **Total** | **1316 MiB** | **693 MiB** |

How this was measured: `python scripts/sample-memory.py` read `docker stats` during the browser tests
and again during about 700 page requests at 12 at a time plus rounds of 12 parallel holds. These are
local Docker Desktop figures, so treat them as estimates for the VPS. The worst case is the sum of the
limits, and the WordPress peak comes from all 12 Apache workers being busy at once.

If the health report runs on the same VPS, using the figures in its own `DEPLOY.md` when this was
written:

| | Sum of limits | Measured peak |
|---|---|---|
| This project | 1316 MiB | 693 MiB |
| Health report | 1056 MiB | 299 MiB |
| **Both** | **2372 MiB** | **992 MiB** |

Both fit in about 3 GB, with about 600 MiB spare in the worst case. The shared n8n is not counted:
it already runs on the VPS, and these workflows add a little to what it uses. If memory runs short,
lower `MaxRequestWorkers` in `docker/wordpress/apache-limits.conf`. Each worker costs about 40 MiB.

## Operating it

- **Nightly reset:** runs at 03:00 UTC from Action Scheduler. It needs `QHS_DEMO_SITE=1` and a site
  host equal to `QHS_DEMO_HOST`, and it refuses otherwise. The email counters survive it, so a
  reset cannot refill the daily cap.
- **Email cap:** 50 per day, enforced before the transport is called. This project and the main
  site share Brevo's 300 per day.
- **Addresses at example.com:** never sent. They are logged as `suppressed_reserved`, because hard
  bounces would hurt the shared sender.
- **Failed events:** stay in the outbox and retry with backoff, six attempts in all. After that an
  alert appears under Bookings, Reports.
- **Backups:** the `db_data` volume holds everything worth keeping, and the demo data is disposable.
- **Removing the project:** delete the Coolify application, unpublish the five workflows, and remove
  the n8n variables and the `booking` DNS record.
