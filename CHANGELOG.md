# Changelog

All notable changes to **Dokan Debug Slack Notifier** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.1.0] - 2026-03-27

### Added
- **Ignore List** — paste any log fragment (function name, file path, full sentence) into a textarea; any matching entry is silently dropped until the pattern is removed
- **Stop / Resume toggle** — pause all Slack notifications instantly via AJAX with a live status badge; no page reload required
- **Clear all** shortcut link to empty the Ignore List in one click
- `ddsn_stopped` option to persist pause state across requests
- `ddsn_ignored_patterns` option stored as a sanitized string array

### Changed
- Plugin version bumped to `1.1.0`
- Admin page reorganised into three clear sections: Settings, Ignore List, Controls

---

## [1.0.0] - 2026-03-27

### Added
- Initial release
- `shutdown` hook (priority 999) fires after every WordPress page load
- Byte-offset tracking via `wp_options` — reads only new lines, never re-processes old entries
- Automatic reset when log file is rotated or truncated
- Severity filter: `PHP Deprecated`, `PHP Warning`, `PHP Notice`, `PHP Fatal error`, `PHP Parse error`
- Plugin path filter: `/plugins/dokan-lite/` and `/plugins/dokan-pro/`
- Cooldown / deduplication via MD5 hash + WordPress transient (default: 15 minutes)
- Non-blocking Slack POST (`blocking: false`) — no page slowdown
- Colour-coded Slack attachments per severity
- Admin settings page under **Settings → Dokan Debug Notifier**
- **Reset Offset** button to re-scan from current file end
- Activation hook seeds offset to current file size to avoid flooding on first install
