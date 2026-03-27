# Dokan Debug Slack Notifier

A WordPress plugin that watches `wp-content/debug.log` after **every page load** and sends a Slack notification whenever a `PHP Deprecated`, `Notice`, `Warning`, or `Fatal Error` originating from **dokan-lite** or **dokan-pro** appears.

---

## Features

- **Real-time detection** — hooks into WordPress `shutdown` so it fires automatically after every request, no cron needed
- **Dokan-only filter** — only alerts on entries from `/plugins/dokan-lite/` or `/plugins/dokan-pro/`
- **Severity-coded Slack messages** — colour-coded attachments for Fatal (red), Warning (gold), Deprecated (orange), Notice (blue)
- **Byte-offset tracking** — reads only *new* lines added since the last check; never re-processes old entries
- **Cooldown / deduplication** — the same error is not re-sent for a configurable number of minutes (default: 15)
- **Ignore List** — paste any log fragment to permanently silence matching entries; remove it to re-enable
- **Stop / Resume toggle** — pause all Slack notifications with one click, resume when ready
- **Log rotation safe** — automatically resets if the log file is truncated or rotated

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 5.8 or higher |
| PHP | 8.0 or higher |
| dokan-lite or dokan-pro | Any version |
| Slack workspace | Incoming Webhooks enabled |

---

## Installation

### Option A — Upload ZIP (recommended)

1. Download `dokan-debug-slack-notifier.zip`
2. In your WordPress admin go to **Plugins → Add New → Upload Plugin**
3. Choose the ZIP file and click **Install Now**
4. Click **Activate Plugin**

### Option B — Manual FTP

1. Extract the ZIP
2. Upload the `dokan-debug-slack-notifier/` folder to `wp-content/plugins/`
3. Go to **Plugins** and activate **Dokan Debug Slack Notifier**

---

## Setup

### 1. Create a Slack Incoming Webhook

1. Go to [api.slack.com/apps](https://api.slack.com/apps) → **Create New App** → **From scratch**
2. Under **Features** → **Incoming Webhooks** → toggle **Activate Incoming Webhooks** ON
3. Click **Add New Webhook to Workspace** and pick the channel you want alerts in
4. Copy the generated URL — it looks like:
   ```
   https://hooks.slack.com/services/T.../B.../XXXXXXXXXXXX
   ```

### 2. Configure the plugin

Go to **Settings → Dokan Debug Notifier** and fill in:

| Field | Description |
|---|---|
| Slack Webhook URL | The URL from Step 1 |
| Cooldown (minutes) | How long to wait before re-sending the same error (default: 15) |

Click **Save Settings**.

---

## Admin Page Guide

### Settings

| Field | Description |
|---|---|
| **Slack Webhook URL** | Your Slack Incoming Webhook URL |
| **Cooldown (minutes)** | Same error won't be re-sent within this window |

### Ignore List

Paste any part of a log line — a function name, a file path, or a full sentence. One pattern per line.

```
Creation of dynamic property
dokan-lite/includes/SomeFile.php
```

Any log entry whose text **contains** a listed pattern is silently dropped. Delete the line and save to re-enable notifications for it.

**Clear all** — empties the textarea in one click (still requires saving).

### Controls

| Button | Effect |
|---|---|
| **⏹ Stop Notifications** | Instantly pauses all Slack alerts (AJAX, no page reload) |
| **▶ Resume Notifications** | Resumes alerts from where they left off |
| **↺ Reset Log Offset** | Moves the read pointer to the current end of the log; clears deduplication cache. Use this to start fresh or to test the webhook. |

A **status badge** in the page title (`RUNNING` / `STOPPED`) always reflects the current live state.

---

## Slack Message Format

Each notification includes:

- A header with the site name, entry count, and a link to the site
- One colour-coded attachment per unique entry:

| Severity | Colour | Icon |
|---|---|---|
| Fatal error | Red `#FF0000` | `:red_circle:` |
| Parse error | Red `#FF0000` | `:red_circle:` |
| Warning | Gold `#FFD700` | `:large_yellow_circle:` |
| Deprecated | Orange `#FFA500` | `:warning:` |
| Notice | Blue `#36a2eb` | `:information_source:` |

The footer of each attachment shows the originating plugin (`dokan-lite` / `dokan-pro`) and severity type.

---

## How It Works (Technical)

```
Every WordPress request
        │
        ▼
   shutdown hook (priority 999)
        │
        ├─ Stopped? → exit
        ├─ No webhook? → exit
        │
        ▼
   Read new bytes from debug.log
   (fseek to last stored offset)
        │
        ▼
   Parse lines: match
   PHP Deprecated / Warning / Notice / Fatal error
   AND /plugins/dokan-lite/ or /plugins/dokan-pro/
        │
        ▼
   Filter ignored patterns
   (substring match against Ignore List)
        │
        ▼
   Deduplicate via MD5 hash + transient
   (skip if sent within cooldown window)
        │
        ▼
   POST to Slack webhook
   (non-blocking, fire-and-forget)
```

---

## FAQ

**Will it slow down my site?**
No. The Slack HTTP request uses `blocking: false` — it is fire-and-forget and does not block the response to the visitor.

**What if my log file is huge?**
The plugin uses `fseek` to jump directly to the last read position, so it never re-reads the entire file.

**What if the log file gets cleared or rotated?**
If the file size is smaller than the stored offset, the plugin automatically resets to byte 0.

**Can I use it without dokan-lite / dokan-pro installed?**
Yes — the plugin only filters log entries by path. It works as long as those plugins generate entries in `debug.log`.

**Does it need WP_DEBUG enabled?**
Yes. Make sure your `wp-config.php` has:
```php
define( 'WP_DEBUG',         true );
define( 'WP_DEBUG_LOG',     true );
define( 'WP_DEBUG_DISPLAY', false );
```

---

## Changelog

### 1.1.0
- Added Ignore List (textarea-based pattern silencing)
- Added Stop / Resume toggle with live status badge

### 1.0.0
- Initial release: shutdown hook, byte-offset tracking, Slack webhook, cooldown deduplication

---

## License

GPL-2.0+ — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)
