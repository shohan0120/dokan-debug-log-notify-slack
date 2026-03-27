<?php
/**
 * Plugin Name:       Dokan Debug Slack Notifier
 * Plugin URI:        https://github.com/shohan0120/dokan-debug-log-notify-slack
 * Description:       Watches wp-content/debug.log after every page load and sends a Slack notification whenever a PHP Deprecated, Notice, Warning, or Fatal Error from dokan-lite or dokan-pro appears.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      8.0
 * Author:            Shohanur Rahman
 * Author URI:        https://github.com/shohan0120
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dokan-debug-slack-notifier
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'DDSN_VERSION',         '1.1.0' );
define( 'DDSN_OPTION_OFFSET',   'ddsn_log_offset' );
define( 'DDSN_OPTION_WEBHOOK',  'ddsn_slack_webhook' );
define( 'DDSN_OPTION_COOLDOWN', 'ddsn_cooldown_minutes' );
define( 'DDSN_OPTION_IGNORED',  'ddsn_ignored_patterns' );
define( 'DDSN_OPTION_STOPPED',  'ddsn_stopped' );
define( 'DDSN_SEEN_TRANSIENT',  'ddsn_seen_hashes' );
define( 'DDSN_LOG_PATH',        WP_CONTENT_DIR . '/debug.log' );

/* -------------------------------------------------------------------------
 * Boot
 * ---------------------------------------------------------------------- */

add_action( 'init',     [ 'DDSN_Plugin', 'init' ] );
add_action( 'shutdown', [ 'DDSN_Plugin', 'on_shutdown' ], 999 );

class DDSN_Plugin {

    /* -------------------------------------------------------------------------
     * Admin hooks
     * ---------------------------------------------------------------------- */
    public static function init(): void {
        if ( ! is_admin() ) {
            return;
        }
        add_action( 'admin_menu',  [ __CLASS__, 'add_settings_page' ] );
        add_action( 'admin_init',  [ __CLASS__, 'register_settings' ] );
        add_action( 'wp_ajax_ddsn_reset_offset',  [ __CLASS__, 'ajax_reset_offset' ] );
        add_action( 'wp_ajax_ddsn_toggle_stop',   [ __CLASS__, 'ajax_toggle_stop' ] );
        add_action( 'wp_ajax_ddsn_remove_ignore', [ __CLASS__, 'ajax_remove_ignore' ] );
    }

    /* -------------------------------------------------------------------------
     * Settings registration
     * ---------------------------------------------------------------------- */
    public static function add_settings_page(): void {
        add_options_page(
            'Dokan Debug Slack Notifier',
            'Dokan Debug Notifier',
            'manage_options',
            'ddsn-settings',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    public static function register_settings(): void {
        register_setting( 'ddsn_group', DDSN_OPTION_WEBHOOK, [
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ] );
        register_setting( 'ddsn_group', DDSN_OPTION_COOLDOWN, [
            'sanitize_callback' => 'absint',
            'default'           => 15,
        ] );
        register_setting( 'ddsn_group', DDSN_OPTION_IGNORED, [
            'sanitize_callback' => [ __CLASS__, 'sanitize_ignored_patterns' ],
            'default'           => [],
        ] );
    }

    /**
     * Convert the textarea value (one pattern per line) into a clean array.
     *
     * @param string|array $raw
     * @return array
     */
    public static function sanitize_ignored_patterns( $raw ): array {
        if ( is_array( $raw ) ) {
            return array_values( array_filter( array_map( 'sanitize_text_field', $raw ) ) );
        }
        $lines = explode( "\n", (string) $raw );
        return array_values( array_filter( array_map( 'sanitize_text_field', $lines ) ) );
    }

    /* -------------------------------------------------------------------------
     * Admin page render
     * ---------------------------------------------------------------------- */
    public static function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $offset   = (int) get_option( DDSN_OPTION_OFFSET, 0 );
        $log_size = file_exists( DDSN_LOG_PATH ) ? filesize( DDSN_LOG_PATH ) : 0;
        $stopped  = (bool) get_option( DDSN_OPTION_STOPPED, false );
        $ignored  = (array) get_option( DDSN_OPTION_IGNORED, [] );

        // Build textarea value from stored array.
        $ignored_text = implode( "\n", $ignored );
        ?>
        <div class="wrap" id="ddsn-wrap">
            <h1 style="display:flex;align-items:center;gap:12px;">
                Dokan Debug Slack Notifier
                <span id="ddsn-status-badge" style="
                    display:inline-block;
                    padding:3px 10px;
                    border-radius:12px;
                    font-size:13px;
                    font-weight:600;
                    background:<?php echo $stopped ? '#dc3232' : '#46b450'; ?>;
                    color:#fff;">
                    <?php echo $stopped ? 'STOPPED' : 'RUNNING'; ?>
                </span>
            </h1>

            <?php if ( $stopped ) : ?>
            <div class="notice notice-warning" id="ddsn-stopped-notice">
                <p><strong>Notifications are paused.</strong> No Slack alerts will be sent until you click <em>Resume Notifications</em>.</p>
            </div>
            <?php endif; ?>

            <!-- ── Settings form ─────────────────────────────────────── -->
            <form method="post" action="options.php">
                <?php settings_fields( 'ddsn_group' ); ?>

                <h2>Settings</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr( DDSN_OPTION_WEBHOOK ); ?>">Slack Webhook URL</label>
                        </th>
                        <td>
                            <input type="url"
                                   id="<?php echo esc_attr( DDSN_OPTION_WEBHOOK ); ?>"
                                   name="<?php echo esc_attr( DDSN_OPTION_WEBHOOK ); ?>"
                                   value="<?php echo esc_attr( get_option( DDSN_OPTION_WEBHOOK ) ); ?>"
                                   class="regular-text"
                                   placeholder="https://hooks.slack.com/services/…" />
                            <p class="description">
                                Create an <a href="https://api.slack.com/messaging/webhooks" target="_blank">Incoming Webhook</a>
                                in your Slack workspace and paste the URL here.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr( DDSN_OPTION_COOLDOWN ); ?>">Cooldown (minutes)</label>
                        </th>
                        <td>
                            <input type="number"
                                   id="<?php echo esc_attr( DDSN_OPTION_COOLDOWN ); ?>"
                                   name="<?php echo esc_attr( DDSN_OPTION_COOLDOWN ); ?>"
                                   value="<?php echo esc_attr( get_option( DDSN_OPTION_COOLDOWN, 15 ) ); ?>"
                                   min="1" max="1440" class="small-text" />
                            <p class="description">The same error will not be re-sent for this many minutes.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Save Settings' ); ?>
            </form>

            <hr>

            <!-- ── Ignore List ───────────────────────────────────────── -->
            <h2>Ignore List</h2>
            <p>
                Paste any part of a log line (e.g. a function name, file path, or the whole line) — one entry per line.<br>
                Any future log entry that <strong>contains</strong> that text will be silently skipped.<br>
                <em>Remove the text from this box and save to re-enable notifications for it.</em>
            </p>

            <form method="post" action="options.php" id="ddsn-ignore-form">
                <?php settings_fields( 'ddsn_group' ); ?>
                <input type="hidden" name="<?php echo esc_attr( DDSN_OPTION_WEBHOOK ); ?>"  value="<?php echo esc_attr( get_option( DDSN_OPTION_WEBHOOK ) ); ?>">
                <input type="hidden" name="<?php echo esc_attr( DDSN_OPTION_COOLDOWN ); ?>" value="<?php echo esc_attr( get_option( DDSN_OPTION_COOLDOWN, 15 ) ); ?>">

                <textarea name="<?php echo esc_attr( DDSN_OPTION_IGNORED ); ?>"
                          id="ddsn-ignore-textarea"
                          rows="10"
                          style="width:100%;max-width:800px;font-family:monospace;font-size:12px;"
                          placeholder="Paste a log line or any fragment to ignore it, one per line…"
                ><?php echo esc_textarea( $ignored_text ); ?></textarea>

                <br>
                <?php if ( ! empty( $ignored ) ) : ?>
                    <p style="color:#888;font-size:12px;">
                        <?php echo count( $ignored ); ?> pattern(s) currently ignored.
                        <a href="#" id="ddsn-clear-all-ignores">Clear all</a>
                    </p>
                <?php endif; ?>

                <?php submit_button( 'Save Ignore List', 'secondary', 'submit-ignores' ); ?>
            </form>

            <hr>

            <!-- ── Controls ──────────────────────────────────────────── -->
            <h2>Controls</h2>
            <p>
                <button type="button"
                        id="ddsn-toggle-stop"
                        class="button <?php echo $stopped ? 'button-primary' : 'button-secondary'; ?>"
                        style="<?php echo $stopped ? 'background:#46b450;border-color:#46b450;color:#fff;' : 'background:#dc3232;border-color:#dc3232;color:#fff;'; ?>">
                    <?php echo $stopped ? '▶ Resume Notifications' : '⏹ Stop Notifications'; ?>
                </button>
                <span id="ddsn-stop-msg" style="margin-left:10px;font-weight:600;display:none;"></span>
            </p>

            <p>
                <button type="button" class="button" id="ddsn-reset-offset">
                    ↺ Reset Log Offset
                </button>
                <span id="ddsn-reset-msg" style="margin-left:10px;color:green;display:none;">Offset reset. Future entries will be scanned from the current end of the log.</span>
            </p>
            <p class="description">
                <strong>Log file:</strong> <?php echo esc_html( DDSN_LOG_PATH ); ?> &nbsp;|&nbsp;
                <strong>Size:</strong> <?php echo esc_html( number_format( $log_size ) ); ?> bytes &nbsp;|&nbsp;
                <strong>Offset:</strong> <?php echo esc_html( number_format( $offset ) ); ?> bytes
            </p>
        </div>

        <script>
        (function () {
            const nonces = {
                reset  : '<?php echo esc_js( wp_create_nonce( 'ddsn_reset' ) ); ?>',
                stop   : '<?php echo esc_js( wp_create_nonce( 'ddsn_stop' ) ); ?>',
            };

            /* ── Stop / Resume ─────────────────────────────────────── */
            const stopBtn  = document.getElementById('ddsn-toggle-stop');
            const stopMsg  = document.getElementById('ddsn-stop-msg');
            const badge    = document.getElementById('ddsn-status-badge');
            const notice   = document.getElementById('ddsn-stopped-notice');

            stopBtn.addEventListener('click', function () {
                stopBtn.disabled = true;
                fetch(ajaxurl, {
                    method  : 'POST',
                    headers : { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body    : 'action=ddsn_toggle_stop&_ajax_nonce=' + nonces.stop
                })
                .then(r => r.json())
                .then(d => {
                    stopBtn.disabled = false;
                    if (!d.success) return;

                    const isStopped = d.data.stopped;

                    // Button
                    stopBtn.textContent = isStopped ? '▶ Resume Notifications' : '⏹ Stop Notifications';
                    stopBtn.style.background    = isStopped ? '#46b450' : '#dc3232';
                    stopBtn.style.borderColor   = isStopped ? '#46b450' : '#dc3232';
                    stopBtn.className           = 'button ' + (isStopped ? 'button-primary' : 'button-secondary');

                    // Badge
                    badge.textContent        = isStopped ? 'STOPPED' : 'RUNNING';
                    badge.style.background   = isStopped ? '#dc3232' : '#46b450';

                    // Notice bar
                    if (notice) notice.style.display = isStopped ? '' : 'none';

                    // Inline message
                    stopMsg.style.display = 'inline';
                    stopMsg.style.color   = isStopped ? '#dc3232' : '#46b450';
                    stopMsg.textContent   = isStopped
                        ? 'Notifications stopped.'
                        : 'Notifications resumed.';
                });
            });

            /* ── Reset offset ──────────────────────────────────────── */
            document.getElementById('ddsn-reset-offset').addEventListener('click', function () {
                fetch(ajaxurl, {
                    method  : 'POST',
                    headers : { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body    : 'action=ddsn_reset_offset&_ajax_nonce=' + nonces.reset
                })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        document.getElementById('ddsn-reset-msg').style.display = 'inline';
                    }
                });
            });

            /* ── Clear all ignores ─────────────────────────────────── */
            const clearAll = document.getElementById('ddsn-clear-all-ignores');
            if (clearAll) {
                clearAll.addEventListener('click', function (e) {
                    e.preventDefault();
                    document.getElementById('ddsn-ignore-textarea').value = '';
                });
            }
        })();
        </script>
        <?php
    }

    /* -------------------------------------------------------------------------
     * AJAX handlers
     * ---------------------------------------------------------------------- */
    public static function ajax_reset_offset(): void {
        check_ajax_referer( 'ddsn_reset' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $size = file_exists( DDSN_LOG_PATH ) ? filesize( DDSN_LOG_PATH ) : 0;
        update_option( DDSN_OPTION_OFFSET, $size );
        delete_transient( DDSN_SEEN_TRANSIENT );
        wp_send_json_success( [ 'offset' => $size ] );
    }

    public static function ajax_toggle_stop(): void {
        check_ajax_referer( 'ddsn_stop' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $current = (bool) get_option( DDSN_OPTION_STOPPED, false );
        $new     = ! $current;
        update_option( DDSN_OPTION_STOPPED, (int) $new );
        wp_send_json_success( [ 'stopped' => $new ] );
    }

    public static function ajax_remove_ignore(): void {
        check_ajax_referer( 'ddsn_ignore' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $index   = (int) ( $_POST['index'] ?? -1 );
        $ignored = (array) get_option( DDSN_OPTION_IGNORED, [] );
        if ( isset( $ignored[ $index ] ) ) {
            array_splice( $ignored, $index, 1 );
            update_option( DDSN_OPTION_IGNORED, array_values( $ignored ) );
        }
        wp_send_json_success( [ 'ignored' => $ignored ] );
    }

    /* -------------------------------------------------------------------------
     * Core: run after every page load
     * ---------------------------------------------------------------------- */
    public static function on_shutdown(): void {
        // Globally stopped.
        if ( get_option( DDSN_OPTION_STOPPED, false ) ) {
            return;
        }

        $webhook = get_option( DDSN_OPTION_WEBHOOK, '' );
        if ( empty( $webhook ) || ! file_exists( DDSN_LOG_PATH ) ) {
            return;
        }

        $new_lines = self::read_new_lines();
        if ( empty( $new_lines ) ) {
            return;
        }

        $entries = self::parse_dokan_entries( $new_lines );
        if ( empty( $entries ) ) {
            return;
        }

        $entries = self::filter_ignored( $entries );
        if ( empty( $entries ) ) {
            return;
        }

        $fresh = self::filter_unseen( $entries );
        if ( empty( $fresh ) ) {
            return;
        }

        self::send_slack( $webhook, $fresh );
    }

    /* -------------------------------------------------------------------------
     * Read only new bytes since the last offset
     * ---------------------------------------------------------------------- */
    private static function read_new_lines(): array {
        clearstatcache( true, DDSN_LOG_PATH );
        $size   = filesize( DDSN_LOG_PATH );
        $offset = (int) get_option( DDSN_OPTION_OFFSET, 0 );

        // File was rotated / truncated.
        if ( $size < $offset ) {
            $offset = 0;
        }

        if ( $size === $offset ) {
            return [];
        }

        $fh = fopen( DDSN_LOG_PATH, 'rb' );
        if ( ! $fh ) {
            return [];
        }

        fseek( $fh, $offset );
        $chunk = fread( $fh, $size - $offset );
        fclose( $fh );

        update_option( DDSN_OPTION_OFFSET, $size, false );

        if ( empty( $chunk ) ) {
            return [];
        }

        return explode( "\n", $chunk );
    }

    /* -------------------------------------------------------------------------
     * Parse lines: keep only Dokan entries with matching severity
     * ---------------------------------------------------------------------- */
    private static function parse_dokan_entries( array $lines ): array {
        $severity_pattern = '/PHP\s+(Deprecated|Warning|Notice|Fatal\s+error|Parse\s+error):/i';
        $dokan_pattern    = '#/plugins/(dokan-lite|dokan-pro)/#i';

        $entries = [];

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) {
                continue;
            }
            if ( ! preg_match( $severity_pattern, $line, $sev_match ) ) {
                continue;
            }
            if ( ! preg_match( $dokan_pattern, $line, $plugin_match ) ) {
                continue;
            }

            $entries[] = [
                'severity' => strtolower( trim( $sev_match[1] ) ),
                'plugin'   => $plugin_match[1],
                'raw'      => $line,
            ];
        }

        return $entries;
    }

    /* -------------------------------------------------------------------------
     * Filter: remove any entry whose raw text contains an ignored pattern
     * ---------------------------------------------------------------------- */
    private static function filter_ignored( array $entries ): array {
        $patterns = (array) get_option( DDSN_OPTION_IGNORED, [] );
        $patterns = array_filter( $patterns ); // remove empty strings

        if ( empty( $patterns ) ) {
            return $entries;
        }

        return array_values( array_filter( $entries, function ( $entry ) use ( $patterns ) {
            $raw = wp_strip_all_tags( $entry['raw'] );
            foreach ( $patterns as $pattern ) {
                if ( str_contains( $raw, $pattern ) ) {
                    return false; // silently drop this entry
                }
            }
            return true;
        } ) );
    }

    /* -------------------------------------------------------------------------
     * Deduplicate: skip entries already reported within the cooldown window
     * ---------------------------------------------------------------------- */
    private static function filter_unseen( array $entries ): array {
        $cooldown = max( 1, (int) get_option( DDSN_OPTION_COOLDOWN, 15 ) ) * MINUTE_IN_SECONDS;
        $seen     = get_transient( DDSN_SEEN_TRANSIENT );
        if ( ! is_array( $seen ) ) {
            $seen = [];
        }

        $now = time();
        foreach ( $seen as $hash => $expires ) {
            if ( $expires < $now ) {
                unset( $seen[ $hash ] );
            }
        }

        $fresh = [];
        foreach ( $entries as $entry ) {
            $hash = md5( $entry['severity'] . '||' . $entry['raw'] );
            if ( isset( $seen[ $hash ] ) ) {
                continue;
            }
            $seen[ $hash ] = $now + $cooldown;
            $fresh[]       = $entry;
        }

        set_transient( DDSN_SEEN_TRANSIENT, $seen, DAY_IN_SECONDS );

        return $fresh;
    }

    /* -------------------------------------------------------------------------
     * Build and POST the Slack message
     * ---------------------------------------------------------------------- */
    private static function send_slack( string $webhook, array $entries ): void {
        $icon_map = [
            'deprecated'  => ':warning:',
            'notice'      => ':information_source:',
            'warning'     => ':large_yellow_circle:',
            'fatal error' => ':red_circle:',
            'parse error' => ':red_circle:',
        ];

        $color_map = [
            'deprecated'  => '#FFA500',
            'notice'      => '#36a2eb',
            'warning'     => '#FFD700',
            'fatal error' => '#FF0000',
            'parse error' => '#FF0000',
        ];

        $attachments = [];
        foreach ( $entries as $entry ) {
            $sev   = $entry['severity'];
            $icon  = $icon_map[ $sev ]  ?? ':beetle:';
            $color = $color_map[ $sev ] ?? '#cccccc';
            $clean = wp_strip_all_tags( $entry['raw'] );

            $attachments[] = [
                'color'  => $color,
                'text'   => "```{$clean}```",
                'footer' => ucfirst( $entry['plugin'] ) . ' · ' . ucfirst( $sev ),
            ];
        }

        $site_name = get_bloginfo( 'name' );
        $site_url  = get_site_url();
        $count     = count( $entries );
        $plural    = $count > 1 ? 'issues' : 'issue';

        $payload = [
            'text'        => "{$icon_map['warning']} *[{$site_name}]* — {$count} new Dokan debug {$plural} detected. <{$site_url}|View site>",
            'attachments' => $attachments,
        ];

        wp_remote_post( $webhook, [
            'body'     => wp_json_encode( $payload ),
            'headers'  => [ 'Content-Type' => 'application/json' ],
            'timeout'  => 10,
            'blocking' => false,
        ] );
    }
}

/* -------------------------------------------------------------------------
 * Activation: seed offset to current file end to avoid flooding on first run.
 * ---------------------------------------------------------------------- */
register_activation_hook( __FILE__, function () {
    $size = file_exists( DDSN_LOG_PATH ) ? filesize( DDSN_LOG_PATH ) : 0;
    add_option( DDSN_OPTION_OFFSET,   $size );
    add_option( DDSN_OPTION_COOLDOWN, 15 );
    add_option( DDSN_OPTION_STOPPED,  0 );
    add_option( DDSN_OPTION_IGNORED,  [] );
} );

register_deactivation_hook( __FILE__, function () {
    delete_transient( DDSN_SEEN_TRANSIENT );
} );
