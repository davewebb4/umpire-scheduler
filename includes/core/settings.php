<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Admin menu ────────────────────────────────────────────────
add_action( 'admin_menu', 'us_settings_menu', 99 );
function us_settings_menu() {
    add_submenu_page(
        'umpire-scheduler',
        'Settings',
        'Settings',
        'manage_options',
        'us-settings',
        'us_settings_page'
    );
}

// ── Settings page ─────────────────────────────────────────────
function us_settings_page() {
    if ( isset( $_POST['us_settings_submit'] ) ) {
        us_save_settings();
    }

    if ( isset( $_POST['us_season_reset_submit'] ) ) {
        us_handle_season_reset();
    }

    $s = us_get_settings();
    ?>
    <div class="wrap">
        <h1>Umpire Scheduler — Settings</h1>
        <p class="us-settings-intro">Configure your organization details. These settings are used throughout the plugin and in all emails sent to umpires.</p>

        <?php if ( isset( $_GET['us_settings_saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>Settings saved successfully.</p></div>
        <?php endif; ?>

        <?php if ( isset( $_GET['us_reset_done'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>
                <strong>Season reset complete.</strong>
                <?php
                $counts = get_transient( 'us_reset_counts' );
                if ( $counts ) {
                    delete_transient( 'us_reset_counts' );
                    echo $counts['games'] . ' game(s), ' . $counts['assignments'] . ' assignment(s), and ' . $counts['payments'] . ' payment record(s) deleted. Umpire availability cleared.';
                }
                ?>
            </p></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field( 'us_settings', 'us_settings_nonce' ); ?>

            <h2 class="title">Organization</h2>
            <table class="form-table">
                <tr>
                    <th><label for="us_org_name">Organization name</label></th>
                    <td>
                        <input type="text" id="us_org_name" name="us_org_name"
                               value="<?php echo esc_attr( $s['org_name'] ); ?>"
                               class="regular-text" placeholder="e.g. City Slo-Pitch Association" />
                        <p class="description">Full name used in email footers and page titles.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="us_org_short">Short name / abbreviation</label></th>
                    <td>
                        <input type="text" id="us_org_short" name="us_org_short"
                               value="<?php echo esc_attr( $s['org_short'] ); ?>"
                               class="small-text" placeholder="e.g. CSA" maxlength="6" />
                        <p class="description">Displays in the logo badge in the top bar and login screen. Max 6 characters.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="us_app_title">App title</label></th>
                    <td>
                        <input type="text" id="us_app_title" name="us_app_title"
                               value="<?php echo esc_attr( $s['app_title'] ); ?>"
                               class="regular-text" placeholder="e.g. Umpire Scheduler" />
                        <p class="description">Displays next to the logo in the top bar.</p>
                    </td>
                </tr>
            </table>

            <h2 class="title">Assignor contact</h2>
            <table class="form-table">
                <tr>
                    <th><label for="us_assignor_email">Assignor email</label></th>
                    <td>
                        <input type="email" id="us_assignor_email" name="us_assignor_email"
                               value="<?php echo esc_attr( $s['assignor_email'] ); ?>"
                               class="regular-text" placeholder="e.g. assignor@example.com" />
                        <p class="description">All umpire notifications are sent to this address.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="us_assignor_name">Assignor name</label></th>
                    <td>
                        <input type="text" id="us_assignor_name" name="us_assignor_name"
                               value="<?php echo esc_attr( $s['assignor_name'] ); ?>"
                               class="regular-text" placeholder="e.g. Dave Webb" />
                        <p class="description">Used in email sign-offs sent to umpires.</p>
                    </td>
                </tr>
            </table>

            <h2 class="title">Email</h2>
            <table class="form-table">
                <tr>
                    <th>Allocator request notifications</th>
                    <td>
                        <label class="us-settings-checkbox-label">
                            <input type="checkbox" name="us_notify_allocator_on_request" value="1"
                                <?php checked( get_option( 'us_notify_allocator_on_request', 0 ), 1 ); ?> />
                            Send an email to the allocator when an umpire requests a game
                        </label>
                        <p class="description">Off by default. Enable when you are actively monitoring requests.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="us_email_footer">Email footer text</label></th>
                    <td>
                        <input type="text" id="us_email_footer" name="us_email_footer"
                               value="<?php echo esc_attr( $s['email_footer'] ); ?>"
                               class="large-text" placeholder="e.g. GVSU Umpire Scheduler" />
                        <p class="description">Appears at the bottom of every email sent to umpires.</p>
                    </td>
                </tr>
            </table>

            <h2 class="title">Timezone</h2>
            <table class="form-table">
                <tr>
                    <th><label for="us_timezone">Timezone</label></th>
                    <td>
                        <select id="us_timezone" name="us_timezone" class="us-settings-timezone-select">
                            <?php
                            $timezones = [
                                'Canada' => [
                                    'America/Vancouver' => 'Pacific — Vancouver / Victoria',
                                    'America/Edmonton'  => 'Mountain — Edmonton / Calgary',
                                    'America/Winnipeg'  => 'Central — Winnipeg',
                                    'America/Toronto'   => 'Eastern — Toronto / Ottawa',
                                    'America/Halifax'   => 'Atlantic — Halifax',
                                    'America/St_Johns'  => 'Newfoundland — St. Johns',
                                ],
                                'United States' => [
                                    'America/Los_Angeles' => 'Pacific — Los Angeles',
                                    'America/Denver'      => 'Mountain — Denver',
                                    'America/Chicago'     => 'Central — Chicago',
                                    'America/New_York'    => 'Eastern — New York',
                                    'America/Phoenix'     => 'Arizona — Phoenix',
                                    'Pacific/Honolulu'    => 'Hawaii — Honolulu',
                                ],
                                'Australia' => [
                                    'Australia/Perth'    => 'Western — Perth',
                                    'Australia/Adelaide' => 'Central — Adelaide',
                                    'Australia/Sydney'   => 'Eastern — Sydney',
                                ],
                                'United Kingdom' => [
                                    'Europe/London' => 'London',
                                ],
                                'Europe' => [
                                    'Europe/Paris'    => 'Central European — Paris / Berlin',
                                    'Europe/Helsinki' => 'Eastern European — Helsinki',
                                ],
                                'New Zealand' => [
                                    'Pacific/Auckland' => 'Auckland',
                                ],
                            ];
                            foreach ( $timezones as $region => $zones ) : ?>
                                <optgroup label="<?php echo esc_attr( $region ); ?>">
                                    <?php foreach ( $zones as $tz => $label ) : ?>
                                        <option value="<?php echo $tz; ?>" <?php selected( $s['timezone'], $tz ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Used for ICS import/export and displaying game times correctly.</p>
                    </td>
                </tr>
            </table>

            <h2 class="title">Front-end page slugs</h2>
            <p class="description" style="margin-bottom:16px">These must match the slugs of the WordPress pages where you placed the shortcodes.</p>

            <table class="form-table">
                <?php
                $slug_fields = [
                    'slug_dashboard'                  => [ 'label' => 'Umpire dashboard',         'shortcode' => '[umpire_dashboard]' ],
                    'slug_schedule'                   => [ 'label' => 'My schedule',               'shortcode' => '[umpire_schedule]' ],
                    'slug_open_games'                 => [ 'label' => 'Open games',                'shortcode' => '[umpire_open_games]' ],
                    'slug_earnings'                   => [ 'label' => 'My earnings',               'shortcode' => '[umpire_earnings]' ],
                    'slug_availability'               => [ 'label' => 'Availability',              'shortcode' => '[umpire_availability]' ],
                    'slug_league_schedule'            => [ 'label' => 'League schedule',           'shortcode' => '[league_schedule]' ],
                    'slug_tournament_schedule'        => [ 'label' => 'Tournament schedule',       'shortcode' => '[tournament_schedule]' ],
                    'slug_umpire_list'                => [ 'label' => 'Umpire list',               'shortcode' => '[umpire_list]' ],
                    'slug_allocator_dashboard'        => [ 'label' => 'Allocator dashboard',       'shortcode' => '[allocator_dashboard]' ],
                    'slug_allocator_pay_reports'      => [ 'label' => 'Allocator pay reports',     'shortcode' => '[allocator_pay_reports]' ],
                    'slug_allocator_games'            => [ 'label' => 'Allocator games',           'shortcode' => '[allocator_games]' ],
                    'slug_allocator_past_games'       => [ 'label' => 'Allocator past games',      'shortcode' => '[allocator_past_games]' ],
                    'slug_allocator_tournament_games' => [ 'label' => 'Tournament management',     'shortcode' => '[tournament_management]' ],
                    'slug_allocator_umpire_history'   => [ 'label' => 'Umpire history',            'shortcode' => '[allocator_umpire_history]' ],
                    'slug_allocator_broadcast'        => [ 'label' => 'Broadcast message',         'shortcode' => '[allocator_broadcast]' ],
                    'slug_register'                   => [ 'label' => 'Umpire registration',         'shortcode' => '[umpire_register]' ],
                ];
                $base = trailingslashit( home_url() );
                foreach ( $slug_fields as $key => $info ) :
                    $val = esc_attr( $s[ $key ] );
                ?>
                <tr>
                    <th><label for="us_<?php echo $key; ?>"><?php echo $info['label']; ?></label></th>
                    <td>
                        <div class="us-slug-row">
                            <span class="us-slug-base"><?php echo esc_html( $base ); ?></span>
                            <input type="text"
                                   id="us_<?php echo $key; ?>"
                                   name="us_<?php echo $key; ?>"
                                   value="<?php echo $val; ?>"
                                   class="us-slug-input"
                                   data-base="<?php echo esc_attr( $base ); ?>"
                                   autocomplete="off"
                                   spellcheck="false" />
                            <span class="us-slug-trail">/</span>
                        </div>
                        <div class="us-slug-preview" id="us_preview_<?php echo $key; ?>">
                            <?php echo esc_html( $base . $s[ $key ] . '/' ); ?>
                        </div>
                        <p class="description">Shortcode: <code><?php echo $info['shortcode']; ?></code></p>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>

            <p class="submit">
                <input type="submit" name="us_settings_submit" class="button button-primary button-large" value="Save settings" />
            </p>
        </form>

        <!-- ── Danger Zone ──────────────────────────────────── -->
        <div class="us-danger-zone">
            <div class="us-danger-zone-header">
                <h2>&#9888; Danger Zone — Season Reset</h2>
            </div>
            <div class="us-danger-zone-body">
                <p class="us-danger-zone-intro"><strong>This will permanently delete:</strong></p>
                <ul class="us-danger-zone-list">
                    <li>All games (<code>us_game</code>)</li>
                    <li>All assignments (<code>us_assignment</code>)</li>
                    <li>All payment records (<code>us_payment</code>)</li>
                    <li>All umpire unavailable dates</li>
                </ul>
                <p class="us-danger-zone-note">
                    <strong>Leagues, tournaments, umpire profiles, and all settings will be preserved.</strong>
                    This cannot be undone.
                </p>

                <form method="post" id="us-reset-form">
                    <?php wp_nonce_field( 'us_season_reset', 'us_season_reset_nonce' ); ?>
                    <table class="form-table us-danger-zone-table">
                        <tr>
                            <th><label for="us_reset_confirm_text">Type RESET to confirm</label></th>
                            <td>
                                <input type="text"
                                       id="us_reset_confirm_text"
                                       name="us_reset_confirm_text"
                                       placeholder="RESET"
                                       autocomplete="off"
                                       class="us-reset-confirm-input" />
                            </td>
                        </tr>
                        <tr>
                            <th><label for="us_reset_checkbox">Confirm deletion</label></th>
                            <td>
                                <label class="us-settings-checkbox-label">
                                    <input type="checkbox" id="us_reset_checkbox" name="us_reset_checkbox" value="1" />
                                    I understand this will permanently delete all season data and cannot be undone
                                </label>
                            </td>
                        </tr>
                    </table>

                    <button type="submit"
                            name="us_season_reset_submit"
                            id="us-reset-btn"
                            disabled
                            class="us-reset-btn us-reset-btn--disabled">
                        &#128465; Reset season data
                    </button>
                </form>
            </div>
        </div>

    </div>

    <style>
        .us-settings-intro        { color: #666; margin-bottom: 24px; }
        .us-settings-checkbox-label { display: flex; align-items: flex-start; gap: 8px; cursor: pointer; font-size: 13px; color: #444; line-height: 1.5; }
        .us-settings-checkbox-label input[type="checkbox"] { margin-top: 2px; width: 15px; height: 15px; flex-shrink: 0; }
        .us-settings-timezone-select { min-width: 280px; }

        .us-slug-row        { display: flex; align-items: center; font-size: 13px; font-family: monospace; }
        .us-slug-base       { background: #f0f0f0; border: 1px solid #ddd; border-right: none; padding: 5px 10px; border-radius: 4px 0 0 4px; color: #555; white-space: nowrap; line-height: 1.6; }
        .us-slug-input      { border-radius: 0 !important; border-left: none !important; border-right: none !important; font-family: monospace !important; font-size: 13px !important; min-width: 200px !important; padding: 5px 8px !important; }
        .us-slug-trail      { background: #f0f0f0; border: 1px solid #ddd; border-left: none; padding: 5px 8px; border-radius: 0 4px 4px 0; color: #555; line-height: 1.6; }
        .us-slug-preview    { margin-top: 6px; font-size: 12px; color: #598cb9; font-family: monospace; }

        .us-danger-zone             { margin-top: 48px; border: 2px solid #d63638; border-radius: 8px; overflow: hidden; }
        .us-danger-zone-header      { background: #d63638; padding: 14px 20px; }
        .us-danger-zone-header h2   { color: #fff; margin: 0; font-size: 15px; }
        .us-danger-zone-body        { padding: 24px; background: #fff9f9; }
        .us-danger-zone-intro       { color: #444; margin-bottom: 4px; font-size: 14px; }
        .us-danger-zone-list        { color: #666; font-size: 13px; margin: 0 0 20px 20px; line-height: 2; }
        .us-danger-zone-note        { color: #444; margin-bottom: 20px; font-size: 13px; }
        .us-danger-zone-table       { max-width: 600px; }

        .us-reset-confirm-input     { width: 160px; font-family: monospace; font-size: 14px; letter-spacing: 2px; text-transform: uppercase; }
        .us-reset-btn               { background: #d63638; color: #fff; border: none; padding: 10px 24px; border-radius: 4px; font-size: 14px; font-weight: 600; margin-top: 8px; }
        .us-reset-btn--disabled     { cursor: not-allowed; opacity: 0.5; }
        .us-reset-btn:not([disabled]) { cursor: pointer; opacity: 1; }
    </style>

    <script>
    (function(){
        var textField = document.getElementById('us_reset_confirm_text');
        var checkbox  = document.getElementById('us_reset_checkbox');
        var btn       = document.getElementById('us-reset-btn');

        function checkReady() {
            var ready     = textField.value.toUpperCase() === 'RESET' && checkbox.checked;
            btn.disabled  = ! ready;
            btn.classList.toggle( 'us-reset-btn--disabled', ! ready );
        }

        textField.addEventListener( 'input',  checkReady );
        checkbox.addEventListener(  'change', checkReady );

        document.getElementById('us-reset-form').addEventListener('submit', function(e){
            if ( ! confirm('Are you absolutely sure? This will delete ALL games, assignments and payment records. This cannot be undone.') ) {
                e.preventDefault();
            }
        });

        document.querySelectorAll('.us-slug-input').forEach(function(input){
            input.addEventListener('input', function(){
                var preview = document.getElementById( 'us_preview_' + input.id );
                if ( preview ) {
                    preview.textContent = input.dataset.base + input.value + '/';
                }
            });
        });
    })();
    </script>
    <?php
}

// ── Handle season reset ───────────────────────────────────────
function us_handle_season_reset() {
    if ( ! isset( $_POST['us_season_reset_nonce'] ) || ! wp_verify_nonce( $_POST['us_season_reset_nonce'], 'us_season_reset' ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;

    $confirm_text = strtoupper( sanitize_text_field( $_POST['us_reset_confirm_text'] ?? '' ) );
    $checkbox     = isset( $_POST['us_reset_checkbox'] );

    if ( $confirm_text !== 'RESET' || ! $checkbox ) {
        wp_redirect( admin_url( 'admin.php?page=us-settings&us_reset_error=1' ) );
        exit;
    }

    $games_deleted       = 0;
    $assignments_deleted = 0;
    $payments_deleted    = 0;

    $games = get_posts( [ 'post_type' => 'us_game',       'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ] );
    foreach ( $games as $id ) { wp_delete_post( $id, true ); $games_deleted++; }

    $assignments = get_posts( [ 'post_type' => 'us_assignment', 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ] );
    foreach ( $assignments as $id ) { wp_delete_post( $id, true ); $assignments_deleted++; }

    $payments = get_posts( [ 'post_type' => 'us_payment',  'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ] );
    foreach ( $payments as $id ) { wp_delete_post( $id, true ); $payments_deleted++; }

    $umpires = get_posts( [ 'post_type' => 'us_umpire',    'numberposts' => -1, 'post_status' => 'publish', 'fields' => 'ids' ] );
    foreach ( $umpires as $id ) { update_post_meta( $id, 'us_unavailable_dates', [] ); }

    set_transient( 'us_reset_counts', [
        'games'       => $games_deleted,
        'assignments' => $assignments_deleted,
        'payments'    => $payments_deleted,
    ], 60 );

    wp_redirect( admin_url( 'admin.php?page=us-settings&us_reset_done=1' ) );
    exit;
}

// ── Save settings ─────────────────────────────────────────────
function us_save_settings() {
    if ( ! isset( $_POST['us_settings_nonce'] ) || ! wp_verify_nonce( $_POST['us_settings_nonce'], 'us_settings' ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;

    $fields = [
        'org_name'                        => 'sanitize_text_field',
        'org_short'                       => 'sanitize_text_field',
        'app_title'                       => 'sanitize_text_field',
        'assignor_email'                  => 'sanitize_email',
        'assignor_name'                   => 'sanitize_text_field',
        'timezone'                        => 'sanitize_text_field',
        'slug_dashboard'                  => 'sanitize_title',
        'slug_schedule'                   => 'sanitize_title',
        'slug_open_games'                 => 'sanitize_title',
        'slug_earnings'                   => 'sanitize_title',
        'slug_availability'               => 'sanitize_title',
        'slug_league_schedule'            => 'sanitize_title',
        'slug_tournament_schedule'        => 'sanitize_title',
        'slug_umpire_list'                => 'sanitize_title',
        'slug_allocator_dashboard'        => 'sanitize_title',
        'slug_allocator_pay_reports'      => 'sanitize_title',
        'slug_allocator_games'            => 'sanitize_title',
        'slug_allocator_past_games'       => 'sanitize_title',
        'slug_allocator_tournament_games' => 'sanitize_title',
        'slug_allocator_umpire_history'   => 'sanitize_title',
        'slug_allocator_broadcast'        => 'sanitize_title',
        'slug_register'                   => 'sanitize_title',
        'email_footer'                    => 'sanitize_text_field',
    ];

    $settings = [];
    foreach ( $fields as $key => $sanitizer ) {
        $settings[ $key ] = $sanitizer( $_POST[ 'us_' . $key ] ?? '' );
    }

    // Required fields — don't save blanks over existing values
    $required = [ 'org_name', 'org_short', 'app_title', 'assignor_email' ];
    $existing = get_option( 'us_settings', [] );
    foreach ( $required as $key ) {
        if ( empty( $settings[ $key ] ) && ! empty( $existing[ $key ] ) ) {
            $settings[ $key ] = $existing[ $key ];
        }
    }

    update_option( 'us_settings', $settings );
    update_option( 'us_notify_allocator_on_request', isset( $_POST['us_notify_allocator_on_request'] ) ? 1 : 0 );

    wp_redirect( admin_url( 'admin.php?page=us-settings&us_settings_saved=1' ) );
    exit;
}

// ── Get all settings with defaults ────────────────────────────
function us_get_settings() {
    static $cache = null;
    if ( $cache !== null ) return $cache;

    $defaults = [
        'org_name'                        => '',
        'org_short'                       => '',
        'app_title'                       => 'Umpire Scheduler',
        'assignor_email'                  => get_option( 'admin_email' ),
        'assignor_name'                   => '',
        'timezone'                        => 'America/Vancouver',
        'slug_dashboard'                  => 'umpire-dashboard',
        'slug_schedule'                   => 'umpire-schedule',
        'slug_open_games'                 => 'umpire-open-games',
        'slug_earnings'                   => 'umpire-earnings',
        'slug_availability'               => 'umpire-availability',
        'slug_league_schedule'            => 'league-schedule',
        'slug_tournament_schedule'        => 'tournament-schedule',
        'slug_umpire_list'                => 'umpire-list',
        'slug_allocator_dashboard'        => 'allocator-dashboard',
        'slug_allocator_pay_reports'      => 'allocator-pay-reports',
        'slug_allocator_games'            => 'allocator-games',
        'slug_allocator_past_games'       => 'allocator-past-games',
        'slug_allocator_tournament_games' => 'tournament-management',
        'slug_allocator_umpire_history'   => 'allocator-umpire-history',
        'slug_allocator_broadcast'        => 'allocator-broadcast',
        'slug_register'                   => 'umpire-register',
        'email_footer'                    => 'Umpire Scheduler',
    ];

    $saved  = get_option( 'us_settings', [] );
    $cache  = wp_parse_args( $saved, $defaults );
    return $cache;
}

// ── Get a single setting value ────────────────────────────────
function us_setting( $key ) {
    $settings = us_get_settings();
    return $settings[ $key ] ?? '';
}

// ── Get the assignor email ────────────────────────────────────
function us_get_assignor_email() {
    return us_setting( 'assignor_email' ) ?: get_option( 'admin_email' );
}