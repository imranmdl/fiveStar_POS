# Going live

Run this first, and read what it says:

```bash
cd backend
php bin/preflight.php
```

It refuses to pass while anything is set the way a development install is set.
Everything below is what it checks and why.

---

## The setting that matters most

`OTP_EXPOSE_IN_RESPONSE=true` returns the verification code in the API response.
That is exactly right on your own machine and catastrophic in production: anyone
can request a code for **any** mobile number and read it straight back, which
defeats registration, OTP login and order confirmation in one line of
configuration.

`APP_ENV=production` and `APP_DEBUG=false` matter for the same reason. Debug mode
returns exception messages, file paths and stack traces to whoever sent the
request.

---

## What you need before you can take a single order

| Thing | Why | Where |
|---|---|---|
| Razorpay live keys | No payment can settle. The sandbox gateway refuses to start outside a local environment | Razorpay dashboard |
| Razorpay webhook secret | Without it webhook signatures cannot be verified, and an unverified webhook means anyone who guesses an order id can mark it paid | Razorpay dashboard |
| SMS gateway credentials | No OTP is delivered, so **no order can ever be confirmed** | Your SMS provider |
| **DLT template registration** | See below | Your provider's DLT portal |
| Shiprocket credentials | No parcel is collected | Shiprocket account |
| An HTTPS certificate | Tokens and payment details must not cross plain HTTP | Your host, or Let's Encrypt |

### DLT registration is the one that will confuse you

Indian operators require every SMS template to be registered on the DLT platform
before it can be delivered. Unregistered content is **dropped silently** — no
error, no delivery, nothing in any log, and the API reports success because the
gateway accepted it.

Register each template, then store the id:

```sql
UPDATE notification_templates
   SET provider_template_id = 'your-dlt-id'
 WHERE code = 'order.confirmed' AND channel = 'sms';
```

Preflight blocks until every active SMS template has one.

---

## The policy pages are placeholders

Shipping, returns, privacy and terms ship with real structure and placeholder
wording. They are a contract with your customer, and Indian consumer law has
specific disclosure requirements for sellers of packaged food.

**Have them reviewed by someone qualified**, then edit them in the console.
Preflight blocks while the word PLACEHOLDER remains.

---

## Start on a clean database

Your development database contains test orders, test products and test
customers. They will appear in revenue reports and in the shop.

```bash
mysql -u root -p -e "CREATE DATABASE spice_live CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# point DB_DATABASE at it, then:
php bin/migrate.php
php bin/seed_admin.php
```

Add your real catalogue through the console: **Products → Add a product**.

---

## Things preflight cannot check for you

**Is the scheduler running?** Without it, unpaid orders are never released,
notifications are never sent, coupons never expire and wallet credit never
lapses. One line:

```cron
* * * * * cd /path/to/backend && php bin/scheduler.php >> storage/logs/scheduler.log 2>&1
```

**Are backups running, and has a restore been tested?** An untested backup is a
hope, not a backup.

**Is the admin console reachable from the internet?** `web/admin/` should sit
behind IP restriction, HTTP auth or a VPN. The API refuses non-staff accounts on
every endpoint, but the console should not be an open door to try passwords
against.

**Will it hold up under load?** The concurrency suite proves correctness under
parallel requests. It says nothing about capacity on your hardware.

---

## Disabled PHP functions on shared hosting

Most shared hosts disable `shell_exec`, `exec`, `passthru` and `system`. Calling
a disabled function is a **fatal error**, not a warning.

The only place this platform used one was to hide the password while it is typed
in `seed_admin.php`. That is now guarded — if the function is unavailable the
password is visible as you type, which is a far smaller problem than being
unable to create an administrator at all.

Nothing else in the application shells out. To confirm what your host blocks:

```bash
php -r "echo ini_get('disable_functions'), PHP_EOL;"
```

## Creating the administrator on shared hosting

**With SSH:**

```bash
cd path/to/backend
php bin/seed_admin.php
```

**Without SSH** — the prompts would hang forever on a cron runner, so pass
everything as arguments:

```bash
php bin/seed_admin.php --name="Your Name" --mobile=9876543210 \
    --email=you@example.com --password='ChooseAStrongOne123'
```

In hPanel: **Advanced → Cron Jobs → Create**, set it to run once, and use the
full path:

```
/usr/bin/php /home/uXXXXXXX/domains/yoursite.com/public_html/backend/bin/seed_admin.php --name="Your Name" --mobile=9876543210 --email=you@example.com --password='ChooseAStrongOne123'
```

**Then delete the cron entry and change the password.** A password on a command
line is exposed: it appears in the process list, in shell history, and a cron
entry stores it in plain text on the server. Use it to get in, then change it
from your account page.

## A grant trap worth knowing about

Several views in this schema are created with a `DEFINER`. MySQL records the
definer as the account that ran the migration **including its host** — `spice@
127.0.0.1` is a different account from `spice@localhost`.

If the application later connects as a host the grant does not cover, every view
fails with:

```
ERROR 1356: View '...' references invalid table(s) or column(s) or function(s)
or definer/invoker of view lack rights to use them
```

The tables are fine; only the views break, so the symptom is a shop where the
health check passes and the product list is empty. Grant to the host the
application actually connects from:

```sql
GRANT ALL ON your_database.* TO 'your_user'@'localhost';
GRANT ALL ON your_database.* TO 'your_user'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Found while installing this archive from scratch.

## Connecting Shiprocket

```bash
php bin/shiprocket_check.php
php bin/shiprocket_check.php --pincode 400001 --weight 1000
```

Verifies credentials, the pickup location and serviceability **without booking
anything**. Run it before switching `COURIER_DRIVER`.

### 1. Create an API user

Shiprocket → **Settings → API → Configure → Create an API User**.

This is a *separate* account from your dashboard login, with its own email and
password. Using your normal login here returns a 403 and is the most common
first mistake.

### 2. Register a pickup address

Shiprocket → **Settings → Company → Pickup Addresses → Add**.

**Verify its phone number.** Shiprocket refuses bookings from an unverified
pickup address, and the error it returns does not say so plainly.

### 3. Fill in `.env`

```
COURIER_DRIVER=shiprocket
SHIPROCKET_EMAIL=api-user@yourdomain.com
SHIPROCKET_PASSWORD=...
SHIPROCKET_PICKUP_LOCATION=Primary      # must match the name in the dashboard
SHIPROCKET_WEBHOOK_SECRET=...           # any long random string
```

### 4. Fix the courier ids — this one will catch you out

The `couriers` table was seeded with **placeholder** `channel_code` values (10,
20, 30…). Bookings match on Shiprocket's real `courier_company_id`, so a courier
whose code does not match a live id can never be selected, and the shipment fails
to book with no obvious reason.

`shiprocket_check.php` prints the live ids and names every mismatch. Then:

```sql
UPDATE couriers SET channel_code = '<real id>' WHERE code = 'DELHIVERY';
```

### 5. Point the tracking webhook at your site

Shiprocket → **Settings → API → Webhooks**:

```
https://your-domain/api/v1/webhooks/tracking
```

with the same secret as `SHIPROCKET_WEBHOOK_SECRET`. Without it, orders never
progress past "handed to courier" on their own.

### 6. Then book one real shipment

**The adapter has never made a live booking.** Every test in this project used
the sandbox, so that first call is the last genuinely untested part of the
delivery path. Do it on an order of your own, and check the label and AWB come
back before a customer's parcel depends on it.

## Testing the whole flow before you have real credentials

While the sandbox drivers are in use, complete a payment from the command line:

```bash
php bin/sandbox_pay.php --list
php bin/sandbox_pay.php SDF2627000002
php bin/scheduler.php --task=notifications.dispatch   # send the queued messages
```

That exercises the real confirmation path end to end. It does **not** tell you
whether a real payment settles — only a real payment does that.

## Before you advertise the shop

Place one small real order yourself, end to end, with a real card or UPI app:

1. Register with a real mobile number — **did the OTP arrive?**
2. Add something to the cart and check out.
3. Pay for real, with a small amount.
4. Confirm the order moves to `confirmed` **on its own**, from the webhook.
5. Book a courier and confirm the label is generated.
6. Check that the dispatch SMS arrives.

Every step above has been tested against sandbox drivers that always succeed.
None of them has ever run against a real provider. That first order is the only
thing that proves the integration works, and it is much cheaper to discover a
problem on your own order than on a customer's.

---

## If something goes wrong after launch

Errors are in `backend/storage/logs/`, one file per channel per day. The API
never shows technical detail to customers, so the log is the only place it
exists.

```bash
tail -f backend/storage/logs/error-$(date +%F).log
```

Every failed request carries a request id, which is also shown to the customer.
Ask them for it and grep the log.
