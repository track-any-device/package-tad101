# package-tad101 — AI Instructions

This is the **TAD-101 universal device protocol package** for the Track Any Device platform.
Packagist: `track-any-device/tad101` | Namespace: `TrackAnyDevice\Tad101\`

TAD-101 is the platform's WebSocket-based protocol for software-defined devices: Android/iOS apps,
Arduino/ESP32, Raspberry Pi, and any device that can open a WebSocket. Devices connect to Soketi
(Pusher-compatible), authenticate via a shared secret, and stream telemetry over a private channel.

Read this file before making any change.

---

## Platform-Wide Rules

These three rules apply in every repository under the `track-any-device` organisation.

**Cross-repo changes: file a GitHub issue first.**
If a task in this repository requires a change in another package or server app — stop. Open a
GitHub issue in the target repository describing exactly what is needed and why. Reference that
issue number in your commit message (`ref track-any-device/{repo}#{n}`). Do not directly edit
files in another repository. When picking up a cross-repo issue, run Claude locally inside that
repository's working directory and work only within its scope.

**Release order: packages before server apps.**
This package depends on `package-core`. Release order: `package-core → package-tad101 → server apps`.

**Database layer lives in `package-core` only.**
No migrations or model classes here. Device records are managed by `package-core`.

---

## Rule 1 — Plan before implementing

Before writing any code, ask clarifying questions. Present a plan and get explicit agreement.
Only begin once the approach is confirmed.

---

## Protocol Architecture

```
Device (WebSocket)
  → Soketi private channel: private-tad101.device.{imei}
  → TAD-101 envelope: { event: "tad101.signal", data: { lat, lon, battery, ... } }
  → Soketi webhook → /api/tad101/webhook (this package's route)
  → Tad101WebhookController → SignalObject → SignalService::record()

Outbound commands (when device is live):
  Broadcast to private-tad101.device.{imei}
  → event: "tad101.command" { command, params }

REST fallback (offline telemetry):
  POST /api/tad101/signal (authenticated with device secret)
  → same SignalObject → SignalService::record()
```

---

## Rule 2 — Channel auth is security-critical

The Soketi channel auth endpoint (`/api/tad101/auth`) validates the device secret before
signing the Pusher auth response. Never bypass or weaken this check. The secret is stored
hashed in the database — compare with `hash_equals()`, never plaintext.

---

## Rule 3 — Webhook signature must be verified

Soketi signs webhook payloads with `TAD101_WEBHOOK_SECRET`. The webhook controller must
verify the signature before processing any payload. Never process unsigned webhooks.

---

## Rule 4 — REST fallback has the same data path as WebSocket

Offline telemetry arriving via `POST /api/tad101/signal` must go through `SignalService::record()`
exactly as WebSocket events do. Do not duplicate the storage logic between the two paths.

---

## Configuration (`config/tad101.php`)

| Key | Purpose |
|---|---|
| `server_host` | Public hostname devices connect WebSocket to |
| `soketi_port` | WebSocket server port |
| `ws_url` | Full `wss://` URL sent to devices during onboarding |
| `timezone_offset` | Device clock offset |
| `default_mode` | Default tracking mode (`vibration`, `continuous`) |
| `default_report_interval` | Seconds between location reports |
| `stream_freshness_minutes` | Minutes before a device is considered offline |
| `webhook_secret` | Soketi webhook signing secret |

---

## Dependencies

```
track-any-device/core
```

---

## Versioning

Tags are created automatically on merge to `main`. Default bump is `patch`.
