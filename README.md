# track-any-device/tad101

TAD101 universal device protocol — driver, controllers, job, and route helpers for the [Track Any Device](https://github.com/track-any-device) platform.

TAD101 carries device telemetry over a Pusher-compatible WebSocket (Soketi). Any device that can open a TLS WebSocket and speak the TAD101 envelope is a first-class citizen: Android / iOS apps, Arduino / ESP32 boards, Raspberry Pi clusters, and anything else.

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.3` |
| Laravel | `^13.7` |
| track-any-device/core | `^0.0.2` |
| track-any-device/drivers | `dev-main` (no stable release yet) |
| Soketi (or compatible Pusher server) | any |
| Inertia.js (`inertiajs/inertia-laravel`) | `^2.0` — required by host app for doc pages |

---

## Installation

```bash
composer require track-any-device/tad101
```

Publish the config file:

```bash
php artisan vendor:publish --tag=tad101-config
```

---

## Environment Variables

Add the following to your host app's `.env`:

| Variable | Required | Default | Description |
|---|---|---|---|
| `TAD101_SERVER_HOST` | No | derived from `APP_URL` | Public hostname devices connect to |
| `TAD101_SOKETI_PORT` | No | `6001` | Soketi WebSocket port |
| `TAD101_WS_URL` | No | `wss://{TAD101_SERVER_HOST}` | Full WebSocket URL sent to devices during onboarding |
| `TAD101_WEBHOOK_SECRET` | **Yes (production)** | — | Shared secret for Soketi → Laravel webhook HMAC verification |
| `TAD101_STREAM_FRESHNESS_MINUTES` | No | `5` | Minutes after last signal before a device is considered offline |
| `TAD101_DEFAULT_MODE` | No | `vibration` | Tracking mode applied on device onboarding |
| `TAD101_DEFAULT_REPORT_INTERVAL` | No | `30` | Default heartbeat interval (seconds) |
| `TAD101_TIMEZONE_OFFSET` | No | `0` | UTC offset hint sent to devices without NTP (e.g. Arduino) |

> **Production requirement:** `TAD101_WEBHOOK_SECRET` must be set. Without it the webhook endpoint accepts all requests in non-production environments only and rejects all in production.

---

## Host App Contracts

### Routes

This package does **not** auto-register routes. Call the static helpers from your route files:

**`routes/api.php`** (inside your `api` middleware group):

```php
use TrackAnyDevice\Tad101\Tad101;

Tad101::apiRoutes();
```

Registers:

| Method | URI | Name | Description |
|---|---|---|---|
| `POST` | `tad101/auth` | `api.tad101.auth` | Pusher channel auth for WebSocket devices |
| `POST` | `tad101/inbound` | `api.tad101.inbound` | REST telemetry fallback |
| `POST` | `tad101/webhook` | `api.tad101.webhook` | Soketi webhook receiver |

**`routes/web.php`** (inside your `web` middleware group):

```php
Tad101::webRoutes();
```

Registers:

| Method | URI | Name | Description |
|---|---|---|---|
| `GET` | `docs/tad101/` | `docs.tad101.overview` | Protocol overview |
| `GET` | `docs/tad101/architecture` | `docs.tad101.architecture` | Architecture diagram |
| `GET` | `docs/tad101/envelope` | `docs.tad101.envelope` | Message envelope spec |
| `GET` | `docs/tad101/android` | `docs.tad101.android` | Android integration guide |
| `GET` | `docs/tad101/ios` | `docs.tad101.ios` | iOS integration guide |
| `GET` | `docs/tad101/arduino` | `docs.tad101.arduino` | Arduino / ESP32 guide |
| `GET` | `docs/tad101/raspberry-pi` | `docs.tad101.raspberry-pi` | Raspberry Pi guide |
| `GET` | `docs/tad101/sensors` | `docs.tad101.sensors` | Sensor registry |
| `GET` | `docs/tad101/commands` | `docs.tad101.commands` | Command registry |
| `GET` | `docs/tad101/ideas` | `docs.tad101.ideas` | Community ideas form |
| `GET` | `docs/tad101/changelog` | `docs.tad101.changelog` | Protocol changelog |

### Broadcasting

The host app must configure a Pusher-compatible broadcaster (Soketi recommended). The `Tad101CommandEvent` broadcasts on `private-tad101.device.{imei}` and requires `PUSHER_APP_KEY` / `PUSHER_APP_SECRET` to be set in `config/broadcasting.php`.

### Driver binding

`Tad101ServiceProvider` automatically binds `Tad101Driver` to four device-type slugs via the container:

```
device.driver.android_app
device.driver.ios_app
device.driver.arduino
device.driver.raspberry_pi
```

`DeviceServiceProvider::driverFor($device)` in the core package resolves the correct driver via these bindings.

---

## Auth Flow

1. Device opens a WebSocket to Soketi and requests subscription to `private-tad101.device.{imei}`.
2. Soketi sends an auth challenge; the device calls `POST tad101/auth` with:
   - `imei` — device identifier
   - `secret_key` — per-device secret provisioned during `onboardingAction()`
   - `socket_id` — from Soketi
   - `channel_name` — e.g. `private-tad101.device.{imei}`
3. `Tad101AuthController` verifies the secret against the bcrypt hash stored in `device.metadata.tad101_secret`, signs the channel with the app secret, and returns the Pusher auth string.
4. Soketi grants the subscription. The device is now live on the channel.
5. The device's `metadata.tad101_connected` flag is set to `true`; `Tad101Driver::supportsStream()` returns `true`, preferring the live channel over GSM SMS fallbacks.

---

## Resource List

### API Endpoints
| Route | Auth | Description |
|---|---|---|
| `POST tad101/auth` | IMEI + secret | Pusher channel auth |
| `POST tad101/inbound` | Bearer `{secret}` or `X-Tad101-Secret` header | REST telemetry |
| `POST tad101/webhook` | HMAC `X-Pusher-Signature` | Soketi event webhook |

### Commands (grouped by category)

**network** — `set_apn`, `set_server`, `set_timezone`

**tracking** — `set_mode`, `request_location`, `get_mode`

**phone** — `set_family_numbers`, `check_family_numbers`, `set_whitelist`, `check_whitelist`

**alarm** — `set_sos_number`, `remove_sos_number`, `set_alarm_delivery`, `low_battery_alarm`, `set_wakeup_alarm`

**utility** — `set_dnd`, `delete_dnd`, `set_sleep_period`, `delete_sleep_period`, `check_params`, `sub_check_params`, `set_volume`, `check_firmware`, `reboot`, `factory_reset`

**intercom** — `enable_intercom`, `set_intercom_group`

**tad101** (stream-only, no GSM SMS fallback) — `tad101_ping`, `tad101_config_dump`, `tad101_set_secret`, `tad101_set_report_interval`

Commands are dispatched via the live WebSocket channel when the device is streaming, and fall back to GSM SMS (via `track-any-device/sms-gateway`) when the device has a `gsm_number` and is offline.

---

## Release Workflow

Releases are automated via `.github/workflows/release.yml` using conventional commits.

| Commit prefix | Version bump |
|---|---|
| `fix:`, `chore:`, `docs:`, etc. | patch |
| `feat:` | minor |
| Any type with `!` (e.g. `feat!:`) | major |

Manual overrides are available via `workflow_dispatch` (select patch / minor / major). The workflow skips automatically if there are no new commits since the last tag.
