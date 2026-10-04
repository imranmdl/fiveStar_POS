# API v1 — Authentication

Base URL: `{APP_URL}/api/v1`

Every response uses the SRS envelope:

```json
{ "success": true, "message": "", "data": {}, "errors": [] }
```

Failures set `success: false` and populate `errors` (field-keyed for validation
failures). Every response carries an `X-Request-Id` header — quote it in support
tickets to locate the exact log entry.

Authenticated endpoints require `Authorization: Bearer <access_token>`.

## Status codes

| Code | Meaning |
|---|---|
| 200 / 201 | Success |
| 400 | Wrong, expired or already-used OTP |
| 401 | Missing, invalid or expired token; bad credentials |
| 403 | Account locked, suspended, or role not permitted |
| 404 | Unknown endpoint or account |
| 405 | Wrong HTTP method for the endpoint |
| 409 | Mobile or email already registered |
| 422 | Validation failure |
| 429 | Throttled — see the `Retry-After` header |
| 500 | Unexpected error (details in the log, not the response) |

---

## POST /auth/register

Creates a `pending_verification` customer and sends a mobile OTP. Does **not**
return tokens; the account is not usable until the mobile is verified.

Throttle: 5 per 10 minutes per IP.

```json
{
  "full_name": "Anita Rao",
  "mobile": "9845012345",
  "email": "anita@example.com",
  "password": "Cardamom2026",
  "referral_code": "ANIQ7K2M"
}
```

`mobile` accepts `+91`, `91` or `0` prefixes and is normalised to 10 digits.
`email` and `referral_code` are optional. Password: minimum 8 characters with at
least one letter and one digit.

**201** → `data.user` (the created customer) and `data.verification`
(`reference_token`, `expires_in_seconds`, `resend_available_in_seconds`).
`debug_otp` is populated only when `APP_ENV=local` and
`OTP_EXPOSE_IN_RESPONSE=true`.

**409** if the mobile or email already exists. **422** for validation, including
an unknown referral code.

## POST /auth/register/verify

Verifies the mobile OTP, activates the account and signs the customer in — one
round trip instead of two.

```json
{ "mobile": "9845012345", "otp": "418302", "reference_token": "…" }
```

**200** → `data.user` and `data.tokens`. Codes are single-use: a replay returns
**400**. Five wrong attempts burn the code (**429**) and a new one must be
requested.

## POST /auth/otp/request

Issues an OTP for `registration`, `login` or `password_reset`.

```json
{ "mobile": "9845012345", "purpose": "login" }
```

The response is identical whether or not the number is registered, so this
endpoint cannot be used to discover who has an account. Resend cooldown is 60
seconds; the per-number hourly cap is 6.

## POST /auth/login

Password login. `identifier` is a mobile number or an email address.

```json
{ "identifier": "9845012345", "password": "Cardamom2026" }
```

**200** → `data.user` and `data.tokens`. **401** for bad credentials (message
identical for unknown accounts and wrong passwords). Five consecutive failures
lock the account for 15 minutes → **403**. An unverified mobile triggers a fresh
OTP and returns **403**.

Optional `device_id`, `device_name` and `platform` (`web`/`android`/`ios`) are
recorded against the session and surface in `GET /auth/sessions`.

## POST /auth/login/otp

Passwordless login. Same shape as `/auth/register/verify` but with
`purpose = login`.

## POST /auth/token/refresh

```json
{ "refresh_token": "…" }
```

Refresh tokens **rotate**: the presented token is revoked and a new pair is
returned. Clients must store the new refresh token.

Presenting an already-rotated token is treated as theft — **401**, and every
session for that user is revoked immediately.

## POST /auth/password/forgot

```json
{ "mobile": "9845012345" }
```

Sends a reset OTP. Always **200**, regardless of whether the number exists.

## POST /auth/password/reset

```json
{
  "mobile": "9845012345",
  "otp": "418302",
  "password": "NewClove2026",
  "password_confirmation": "NewClove2026",
  "reference_token": "…"
}
```

On success every existing session is revoked, so a compromised device cannot
survive a reset. Reusing the current password returns **422**.

## POST /auth/password/change  *(authenticated)*

```json
{
  "current_password": "Cardamom2026",
  "password": "NewClove2026",
  "password_confirmation": "NewClove2026"
}
```

Revokes all sessions on success, including the caller's.

## GET /auth/me  *(authenticated)*

Returns the current profile. Never includes `password_hash` or any internal id.

## GET /auth/sessions  *(authenticated)*

Active device sessions: `uuid`, `device_id`, `device_name`, `platform`,
`ip_address`, `created_date`, `expires_date`. Backs a "signed in on these
devices" screen.

## POST /auth/logout  *(authenticated)*

```json
{ "refresh_token": "…", "all_devices": false }
```

Revokes the given session, or all of them when `all_devices` is true.

## GET /health

Liveness and database check for the load balancer. **503** when the database is
unreachable.

---

## Client implementation notes

**Token lifetimes.** Access tokens last 15 minutes, refresh tokens 30 days. Both
are configurable. Clients should refresh on a 401 and retry once, not poll a
timer.

**Because refresh rotates**, the client must persist the new refresh token
atomically. Two concurrent refreshes with the same token will trip theft
detection and log the user out everywhere — serialise refreshes behind a single
in-flight promise (Flutter: an interceptor with a shared `Completer`).

**On 429**, honour the `Retry-After` header rather than retrying immediately.

**Never store an access token where JavaScript on another origin can read it**,
and never log a refresh token.
