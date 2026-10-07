# Deploying 5Star on Railway

One Railway **app service** (built from this repo's `Dockerfile`) plus one
**MySQL** service. The app container serves everything from one domain:

| Path | What |
|---|---|
| `/` | React storefront, `/admin` console, `/till` POS (built from `5star-frontend/`) |
| `/api/v1/...` | PHP REST API (`spice-api/backend`) |
| `/uploads/...` | Product images, logo, payment QR — on the persistent volume |
| `/api-docs/` | OpenAPI viewer |

On every deploy the container waits for MySQL, applies pending migrations,
prints the go-live preflight report into the deploy log, starts the scheduler
(every 60 s), then starts Apache on Railway's `$PORT`. Health check:
`GET /api/v1/health` (checks the database too).

Tested locally against MySQL 8.0 with the same Apache/PHP 8.3 setup: all 49
migrations and seeds apply on a fresh database, a redeploy is a no-op, admin
login, bearer-token calls, image upload and the inventory smoke test
(23/23) pass.

---

## Before you start

1. **Make the GitHub repo private** and remove the database dumps and
   `test.envsss` from git history (see the code review). `.dockerignore` keeps
   them out of the image, but anything public on GitHub is already exposed.
2. Install nothing locally — everything builds on Railway.

## 1. Create the project

1. Railway → **New Project → Deploy from GitHub repo** → pick `fiveStar_POS`.
   Railway finds `railway.json` and builds the `Dockerfile` at the repo root.
   The first deploy will fail until the variables below exist — that's expected.
2. In the same project: **+ New → Database → MySQL**.
   Open the MySQL service → **Settings → Source** and check the image tag.
   The migrations are tested on MySQL 8; if it shows 9.x, set it to
   `mysql:8.4` before putting any data in.

## 2. Volume (uploads, logs, backups)

App service → **Settings → Volumes → Add volume**, mount path **`/data`**.
Without it, every uploaded product image disappears on the next deploy (the
deploy log warns you if the volume is missing).

## 3. Variables (app service → Variables)

Required:

| Variable | Value |
|---|---|
| `MYSQL_URL` | `${{MySQL.MYSQL_URL}}` (reference — use the name of your MySQL service) |
| `JWT_SECRET` | 64 hex chars — `openssl rand -hex 32` |
| `OTP_PEPPER` | 64 hex chars — `openssl rand -hex 32` (different from JWT_SECRET) |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `PAYMENT_DRIVER` | `manual` (manual UPI QR, as on Hostinger) |
| `COURIER_DRIVER` | `manual` |

Set after you add a domain (step 4). Until then it defaults to the
`*.up.railway.app` domain automatically:

| Variable | Value |
|---|---|
| `APP_URL` | `https://shop.yourdomain.com` (no trailing slash) |

First deploy only — creates the administrator, then **delete all four**
(otherwise every redeploy resets the admin password):

| Variable | Value |
|---|---|
| `ADMIN_NAME` | Your name |
| `ADMIN_MOBILE` | 10-digit mobile |
| `ADMIN_EMAIL` | Your email |
| `ADMIN_PASSWORD` | A strong password |

Messaging — **needed for customers to receive OTP codes** (order
confirmation, OTP sign-in). Without it `SMS_DRIVER` defaults to `log`: codes
are written to `/data/storage/logs` and never texted, and the admin Dashboard
shows a "Text messages are switched off" warning. Copy the values from the
Hostinger `.env`:
`SMS_DRIVER=http`, `SMS_ENDPOINT`, `SMS_API_KEY`, `SMS_SENDER_ID`,
`SMS_DLT_TEMPLATE_ID`, and the `SMS_FIELD_*` names if your provider differs.

**MSG91** (OTP codes): `SMS_DRIVER=msg91`, `MSG91_AUTHKEY` (MSG91 panel →
Authkey) and `MSG91_OTP_TEMPLATE_ID` (the OTP template's ID; its approved text
must contain `##OTP##`). The shop generates and checks the code; MSG91 only
delivers it. Only OTP texts go through MSG91 — order-update texts are logged,
not sent. Delivery results are in `/data/storage/logs/sms-*.log`.
Razorpay / Shiprocket keys only if you switch those drivers on.

AI product descriptions (optional) — Admin → Products → edit → **Write
descriptions with AI** drafts the short and full description from the
product's details using the Claude API. Set `ANTHROPIC_API_KEY` (from
console.anthropic.com) to switch it on; `AI_MODEL` defaults to
`claude-haiku-4-5-20251001`. Without a key the button fills a plain template
from the same details. Nothing is saved until the admin presses Save.

Optional switches:

| Variable | Default | Effect |
|---|---|---|
| `RUN_MIGRATIONS` | `true` | Apply pending migrations on boot |
| `RUN_SEEDS` | `false` | Seeds run automatically on an empty database only; `true` forces them |
| `RUN_SCHEDULER` | `true` | Background scheduler every 60 s |
| `RUN_PREFLIGHT` | `true` | Print the go-live report in the deploy log |
| `OTP_EXPOSE_IN_RESPONSE` | `false` | **Local testing only.** Shows OTP codes on screen, but only when `APP_ENV=local` — it has no effect on a Railway (production) deploy. |
| `VITE_BRAND_LOGO_URL` | old Hostinger logo URL | Header logo used by the React app (build-time) |
| `ALLOW_DATA_RESET` | `false` | **Test stores only.** `true` enables Admin → Backups → *Reset data*, which permanently deletes chosen data (orders, customers, products…) after taking a full backup. Leave unset on a live shop. |

`.env` is not used in the container — every setting above is a Railway
variable. (`Env.php` now falls back to process environment variables when a
key isn't in `.env`; Hostinger behaviour is unchanged.)

## 4. Domain

App service → **Settings → Networking → Generate Domain** (gives
`xxx.up.railway.app`), or **Custom Domain** → add the CNAME Railway shows at
your DNS provider. Then set `APP_URL` to that https address and redeploy.

## 5. First deploy — check the log

You should see, in order:

```
[5star] Database: using connection URL (host mysql.railway.internal)
[5star] Database is reachable.
[5star] Applying migrations and seeds (fresh database: yes) ...
Applying 001_core_foundation ... OK  …  Applying 049_loyalty_program ... OK
[5star] ADMIN_PASSWORD is set: creating/updating the administrator account.
Go-live readiness check … (BLOCK/WARN lines)
[5star] Scheduler started …
[5star] Starting Apache on port 8080
```

Then open:

- `https://<domain>/api/v1/health` → `"database":"ok"`
- `https://<domain>/admin` → sign in with the admin account → **delete the
  `ADMIN_*` variables now**.

The preflight report will still list BLOCKs that need business action, not
code: SMS gateway + DLT template ids, policy pages with placeholder text, and
(it checks them regardless of driver) empty Razorpay/Shiprocket keys.

A fresh database contains the seed **demo products, coupons and offers** —
delete or edit them in the console before going live. Seeds do not run again,
so your edits stick.

## 6. Moving the live data from Hostinger (optional)

Production on Hostinger is MariaDB 10.11; Railway is MySQL 8. Don't import a
full Hostinger dump — its schema contains MariaDB-only definitions. Instead:

1. Let Railway build the schema (step 5), set `RUN_MIGRATIONS=false`.
2. In the Railway MySQL, empty every table except `schema_migrations`
   (`SET FOREIGN_KEY_CHECKS=0; TRUNCATE …`).
3. On Hostinger, take a **data-only** dump:
   `mysqldump --no-create-info --skip-triggers --complete-insert --hex-blob DBNAME > data.sql`
   (generated columns must not be in the INSERTs — if the import complains,
   that table needs its generated columns excluded).
4. Import with the MySQL public URL (MySQL service → enable TCP proxy):
   `mysql -h <proxy-host> -P <port> -u root -p railway < data.sql`
   — use the `mysql` client, not phpMyAdmin (silent truncation, as before).
5. Copy `spice-api/backend/public/uploads/` from Hostinger into the volume
   (`railway ssh` into the app, then download a tarball into `/data/uploads`).
6. Set `RUN_MIGRATIONS=true` again. Do this on a test Railway environment first.

## 7. Day-to-day

- **Logs:** Railway → app service → Deploy Logs (Apache + PHP errors). App
  logs and `scheduler.log` are in `/data/storage/logs` (`railway ssh`).
- **Backups:** turn on Railway's MySQL backups; the in-app backup (Admin →
  Backups) writes to `/data/storage/backups` on the volume. Any saved
  backup can be put back with its **Restore** button.
- **Testing from a clean start:** with `ALLOW_DATA_RESET=true`, Admin →
  Backups → *Reset data* wipes the chosen kinds of data (a backup is taken
  first; restore it to undo). Staff logins, roles, settings, warehouses,
  couriers and delivery zones are always kept.
- **Rolling back:** Railway → Deployments → previous deploy → Redeploy.
  Migrations are forward-only on boot; a down-migration is a manual
  `php bin/migrate.php --rollback` over `railway ssh`.
- **Replicas:** keep 1 — the volume attaches to a single instance.
- **Android app (Capacitor):** built by GitHub Actions
  (`.github/workflows/android.yml`) — see "Android app" below.

## Android app

The app is the same React frontend packaged with Capacitor, talking to this
Railway API over HTTPS. Because the app's WebView origin is `https://localhost`
(not your domain), the API must allow it:

1. **Railway** → app service → Variables: `CORS_ALLOWED_ORIGINS=https://localhost`
   (comma-separate more origins if needed), then deploy.
2. **GitHub** → repo → Settings → Secrets and variables → Actions → Variables:
   `API_BASE_URL=https://<your-domain>/api/v1`.
3. **GitHub** → Actions → *Android app* → **Run workflow** (it also runs on
   every push to `main` that touches `5star-frontend/`).
4. Open the finished run → **Artifacts** → download
   `5star-android-debug-…` → unzip → install the `.apk` on the phone
   (allow "install unknown apps" for your browser/file manager).

The debug APK is fine for staff phones (till, mobile barcode inward). For the
Play Store you need a signed build: create a keystore once
(`keytool -genkeypair -v -keystore 5star.keystore -alias 5star -keyalg RSA -keysize 2048 -validity 10000`),
keep it safe — losing it means you can never update the app — and add the
four `ANDROID_*` secrets listed at the top of the workflow. Runs on `main`
then also produce a signed `.apk` and `.aab` (upload the `.aab` to Play
Console).

The app has the server address built in, so if the domain changes, update
`API_BASE_URL` and rebuild.

## What changed in the repo for this

| File | Why |
|---|---|
| `Dockerfile`, `.dockerignore`, `railway.json` | Build + deploy config |
| `docker/apache.conf` | One vhost: SPA fallback, `/api` → PHP, `/uploads` (no PHP execution), cache headers, bearer token passthrough (`CGIPassAuth`), Debian `/icons/` alias removed |
| `docker/php.ini` | Production PHP settings, upload limits, opcache |
| `docker/entrypoint.sh` | DB variable mapping, secret checks, volume links, migrations, admin seed, preflight, scheduler |
| `spice-api/backend/app/Core/Env.php` | Read process env vars when `.env` lacks the key |
| `spice-api/backend/bin/migrate.php` | `--no-seed` option |
| `spice-api/backend/bin/preflight.php` | Driver defaults match config (`manual`, not `sandbox`) |
| `spice-api/database/migrations/012_…sql` | Registers itself in `schema_migrations` (was re-running on every migrate) |
| `spice-api/database/migrations/016_…sql` | FK `ON UPDATE RESTRICT` — MySQL 8 rejects CASCADE on a CHECK column (error 3823) |
| `5star-frontend/src/lib/brand.js` | `VITE_BRAND_LOGO_URL` override for the hot-linked Hostinger logo |
