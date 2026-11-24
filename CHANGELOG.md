# Changelog

Notable changes to this package. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/).

## [3.0.1] - 2026-11-24

### Added
- `experteam:supervisor:fatal-alert`, a Supervisor event listener. When a worker reaches FATAL it emails support through the API Services email route, retrying once on a connection error, and logs the event to the `ElasticSearch` channel without going through Redis. A rejected or unreachable email route is logged too. It reads `DHL_API_SERVICES_URL`, `AUTH_MASTER_APP_KEY`, `SUPERVISOR_FATAL_ALERT_EMAIL` and `MY_POD_NAMESPACE`. The email is an HTML template with the DHL colors, in `resources/supervisor-fatal-alert.html`.

### Notes
- First 3.x release built from `master`, so it also brings `RoleScope` from 2.4.0. The 3.0.0 tag was cut from 2.3.1 and does not include it.

## [3.0.0] - 2026-10-28

### Changed
- `ApiSecurityAuthGuard` returns `Experteam\ApiLaravelBase\Models\User` instead of the application's `App\Models\User`.
- Requires PHP 8.3 or 8.4 and supports Laravel 10 to 13. Laravel 9 is no longer supported.

### Added
- `Experteam\ApiLaravelBase\Models\User`.

## [2.4.0] - 2026-10-26

### Added
- `RoleScope`, which limits queries to the active role's scope. Every queued job starts without a scope.

## [2.3.1] - 2026-01-08

### Added
- `logout()` and `forgetUser()` in `ApiSecurityAuthGuard`.

## [2.3.0] - 2025-11-18

### Added
- Laravel 12 support.

## Earlier versions

Versions before 2.3.0 are described by their git tags.
