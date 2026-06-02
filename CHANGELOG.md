# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Changed

- **BREAKING for debuggers**: `Tad101CommandEvent::broadcastOn()` no longer mirrors commands onto the public `tad101.commands` channel. Only the per-device `private-tad101.device.{imei}` channel is used. The SDK on-device contract is unchanged. Cross-tenant debugging continues through the sampled `private-admin.device-logs` channel via `DeviceLog::out()`.
