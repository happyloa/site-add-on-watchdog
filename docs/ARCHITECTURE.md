# Site Add-on Watchdog architecture

Version 1.10.0 keeps the WordPress entry file deliberately small and puts each responsibility behind a focused class.

```text
site-add-on-watchdog.php
  -> Bootstrap
       -> Plugin (lifecycle, scheduling, REST and scan orchestration)
       -> AdminPage (admin actions and view model)
            -> Admin/RiskSorter
       -> Scanner
            -> VersionComparator
            -> WPScanClient
            -> RiskRepository
       -> Notifier
            -> Notifications/MessageFormatter
            -> NotificationQueue
            -> SettingsRepository
                 -> NotificationValidator
```

## Design boundaries

- `Bootstrap` is the composition root. Constructors receive dependencies and should not perform network requests.
- `Plugin` owns WordPress hooks and coordinates use cases; scanning and message formatting stay in their own services.
- `RiskRepository` and `SettingsRepository` own the shapes of the risk and settings options. `NotificationQueue` owns the queue and `Plugin` owns the cron status. The `MigratesLegacyOptions` trait holds the fallback to the option names used before the rename.
- `uninstall.php` deletes those options and the scheduled events when the plugin is deleted. Add any new option to its list.
- `Scanner` contains provider isolation and cached WordPress.org lookups. A plugin with no directory entry skips the version checks and still gets its WPScan lookup. A temporary lookup failure keeps that plugin's previous risk and does not abort the remaining scan.
- `Plugin::schedule()` runs on every request, so it must stay cheap. It writes the cron status only when the status changes.
- `Notifier` creates delivery jobs, while `MessageFormatter` owns platform payload formats and `NotificationQueue` owns retry state.
- Email uses the site's WordPress mail transport. Temporary `wp_mail_failed` listeners capture provider errors, and mail plugin exceptions are contained so other channels can continue. A successful return confirms acceptance for sending, not inbox delivery.
- `AdminPage` handles permissions, nonces, redirects, and rendering. Sorting remains independently testable through `RiskSorter`, which also supplies the sort and search values the risk table script reads.

## Extension points

- Listen to `site_add_on_watchdog_diagnostic` to route structured diagnostic events to a logging or observability system. The event name is the second callback argument and the sanitized context array is the third.
- The cron REST endpoint accepts its generated secret and delegates to the same scan and queue services used by WP-Cron and WP-CLI.
- New notification channels should add settings validation, a formatter, a queue job definition, UI fields, and transport tests as one change. Never log webhook URLs or credentials.

## Compatibility rules

- Minimum PHP is 8.1 and minimum WordPress is 6.0.
- All WordPress hooks that can translate strings run on `init` or later.
- Remote requests use WordPress HTTP APIs; outgoing webhook requests use `wp_safe_remote_post()`.
- Activation only schedules background work. It must not depend on remote services.
- The plugin entry point catches bootstrap failures so incomplete files cannot take down the rest of the site.

## Quality gates

- `composer test` runs the PHPUnit suite.
- `composer lint` runs the repository's PSR-12 PHPCS ruleset; the official Plugin Check action supplies WordPress-specific review checks.
- GitHub Actions tests PHP 8.1 through 8.5 and runs the official WordPress Plugin Check action.
- The packaged plugin is tested against a local Mailpit SMTP server, including recipients, HTML content, connection failures, queue retries, and mail plugin exceptions.
- `scripts/build-release.ps1` creates the installable ZIP from an explicit allowlist, keeping tests, build tooling, repository artwork, and WordPress.org directory assets outside the plugin package.
