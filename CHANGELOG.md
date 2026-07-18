# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.8.0] - 2026-07-18

### Changed

- Refresh plugin metadata and compatibility declarations for WordPress 7.0.
- Move service construction and lifecycle hooks into a dedicated bootstrap.
- Schedule the first scan in the background instead of making activation depend on remote services.

### Fixed

- Contain bootstrap failures so an incomplete or corrupted installation cannot take down the rest of the site.

## [1.7.5.1] - 2026-01-10

### Added

- Proper PHPDoc type hints to the Risk model for better IDE support.
- CHANGELOG.md for easier version history tracking on GitHub.
- Translation template file (`languages/site-add-on-watchdog.pot`) for translators.

### Fixed

- Mixed-language string in admin template for consistent internationalization.
- Sync Version constant with plugin metadata.

## [1.7.5] - 2025-12-XX

### Removed

- Custom "View details" plugin row link to avoid duplicating WordPress output.

## [1.7.4] - 2025-12-XX

### Fixed

- Admin risk table rendering by loading the correct Risk model in the template.

## [1.7.3] - 2025-12-XX

### Fixed

- Normalize risk vulnerability payloads to avoid fatal errors on new installs.

## [1.7.2] - 2025-12-XX

### Changed

- Removed outdated roadmap reference in the readme.
- Clarified FAQ guidance for resending failed notifications.
- Added the GitHub repository link to the readme.

## [1.7.1] - 2025-12-XX

### Added

- Settings and View details links in the plugin row.
- Open the author link in a new tab from the plugins list.

## [1.7.0] - 2025-12-XX

### Security

- Sanitize admin request parameters for sorting, notices, and downloads.

## [1.6.3] - 2025-11-XX

### Changed

- Bump plugin metadata and Version constant.

## [1.6.2] - 2025-11-XX

### Changed

- Bump plugin metadata and Version constant.

## [1.6.1] - 2025-11-XX

### Fixed

- Add missing translator comments for placeholder strings to satisfy plugin check.

## [1.6.0] - 2025-11-XX

### Changed

- Align enqueued asset cache-busting with the 1.6.0 release.
- Verify version references across the codebase for consistency.

## [1.5.3] - 2025-11-XX

### Fixed

- Address plugin check notices by tightening request handling and translator comments.

## [1.5.2] - 2025-11-XX

### Fixed

- Addressed plugin check findings by hardening sanitization, hook usage, and output handling.
- Refined admin notice and history messaging for better WordPress compliance.

## [1.5.1] - 2025-11-XX

### Fixed

- Addressed plugin check findings by improving nonce validation, sanitization, and escaping.
- Replaced discouraged filesystem and tag-stripping calls for compliance.

## [1.5.0] - 2025-10-XX

### Changed

- Rename the main plugin file and asset handles to match the plugin slug and unique prefix.
- Prefix options, transients, hooks, and cron events with `siteadwa_` while migrating existing saved data.
- Move admin inline styles into the enqueued stylesheet and prevent direct template access.

## [1.4.0] - 2025-10-XX

### Fixed

- Improve the scan history card layout so risk pills stay aligned with their titles.
- Send Discord notifications as plain text only to avoid duplicated content blocks.

## [1.3.1] - 2025-10-XX

### Fixed

- Fix notification queue dispatch callbacks to avoid activation/runtime fatals.
- Provide safer defaults for settings persistence when WordPress APIs are unavailable in CLI/CI.
- Harden translation calls in the history template for non-WordPress contexts.

## [1.3.0] - 2025-09-XX

### Changed

- Bump release metadata, stable tag, and asset cache busting.

## [1.2.0] - 2025-09-XX

### Changed

- Update plugin metadata, documentation, and enqueued asset versions.

## [1.0.0] - 2025-09-XX

### Added

- First stable release with production-ready monitoring, notification, and history features.

## [0.5.0] - 2025-08-XX

### Added

- Store every scan in a downloadable history with timestamps and JSON/CSV exports.
- Admin dashboard history panel showing the latest results with retention controls.
- Allow administrators to configure how many historical scans are retained (defaults to 30).

## [0.4.0] - 2025-08-XX

### Added

- Twenty-minute testing frequency to the scan scheduler for staging and QA environments.

## [0.3.0] - 2025-07-XX

### Added

- Schedule frequency options for daily, weekly, or manual-only scans.
- Improved webhook delivery with signature headers and better error handling.
- Refreshed the admin results table with sortable columns and clearer status badges.

## [0.2.0] - 2025-07-XX

### Added

- Prefill email notification recipients with site administrators.
- Refreshed email layout with HTML formatting including version details.

## [0.1.0] - 2025-06-XX

### Added

- Initial release.
