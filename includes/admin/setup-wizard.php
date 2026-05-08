<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── On activation, set redirect flag ─────────────────────────
// Called directly from us_activate() in the main plugin file
function us_wizard_activation() {
    if ( ! get_option( 'us_settings' ) ) {
        set_transient( 'us_wizard_redirect', '1', 30 );
    }
}

// ── Redirect to wizard on fresh install ───────────────────────
add_action( 'admin_init', 'us_wizard_maybe_redirect' );
function us_wizard_maybe_redirect() {
    if ( ! get_transient( 'us_wizard_redirect' ) ) return;
    if ( get_option( 'us_settings' ) ) return;
    delete_transient( 'us_wizard_redirect' );
    if ( ! is_admin() || wp_doing_ajax() ) return;
    wp_redirect( admin_url( 'admin.php?page=us-setup-wizard' ) );
    exit;
}

// ── Register wizard page (hidden from menu) ───────────────────
add_action( 'admin_menu', 'us_wizard_menu', 99 );
function us_wizard_menu() {
    add_submenu_page(
        null,
        'Umpire Scheduler Setup',
        'Setup Wizard',
        'manage_options',
        'us-setup-wizard',
        'us_wizard_page'
    );
}

// ── Wizard page ───────────────────────────────────────────────
function us_wizard_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

    if ( isset( $_POST['us_wizard_nonce'] ) && wp_verify_nonce( $_POST['us_wizard_nonce'], 'us_wizard' ) ) {
        $step = us_wizard_handle_post( absint( $_POST['step'] ?? 1 ) );
    } else {
        $step = absint( $_GET['step'] ?? 1 );
    }

    us_wizard_render( $step );
}

// ── Handle form submissions ───────────────────────────────────
function us_wizard_handle_post( $step ) {
    if ( $step === 1 ) {
        $settings = get_option( 'us_settings', [] );
        $settings['org_name']       = sanitize_text_field( $_POST['org_name']       ?? '' );
        $settings['org_short']      = sanitize_text_field( $_POST['org_short']      ?? '' );
        $settings['app_title']      = sanitize_text_field( $_POST['app_title']      ?? '' );
        $settings['tagline']        = sanitize_text_field( $_POST['tagline']        ?? '' );
        $settings['primary_color']  = sanitize_hex_color(  $_POST['primary_color']  ?? '#091b33' );
        $settings['secondary_color']= sanitize_hex_color(  $_POST['secondary_color']?? '#598cb9' );
        $settings['logo_id']        = absint( $_POST['logo_id'] ?? 0 );
        update_option( 'us_settings', $settings );
        return 2;
    }

    if ( $step === 2 ) {
        $settings = get_option( 'us_settings', [] );
        $settings['assignor_name']  = sanitize_text_field( $_POST['assignor_name']  ?? '' );
        $settings['assignor_email'] = sanitize_email(      $_POST['assignor_email'] ?? '' );
        $settings['email_footer']   = sanitize_text_field( $_POST['email_footer']   ?? '' );
        $settings['timezone']       = sanitize_text_field( $_POST['timezone']       ?? 'America/Vancouver' );
        update_option( 'us_settings', $settings );
        return 3;
    }

    if ( $step === 3 ) {
        us_wizard_create_pages();

        // Set umpire-home as the WordPress front page
        $home_page = get_page_by_path( 'umpire-home' );
        if ( $home_page ) {
            update_option( 'show_on_front', 'page' );
            update_option( 'page_on_front', $home_page->ID );
        }

        update_option( 'us_wizard_complete', '1' );
        return 4;
    }

    return $step;
}

// ── Create all required pages ─────────────────────────────────
function us_wizard_create_pages() {
    $pages = [
        'umpire-dashboard'       => [ 'title' => 'Umpire Dashboard',       'shortcode' => '[umpire_dashboard]',        'setting' => 'slug_dashboard' ],
        'umpire-schedule'        => [ 'title' => 'My Schedule',             'shortcode' => '[umpire_schedule]',         'setting' => 'slug_schedule' ],
        'umpire-open-games'      => [ 'title' => 'Open Games',              'shortcode' => '[umpire_open_games]',       'setting' => 'slug_open_games' ],
        'umpire-earnings'        => [ 'title' => 'My Earnings',             'shortcode' => '[umpire_earnings]',         'setting' => 'slug_earnings' ],
        'umpire-availability'    => [ 'title' => 'My Availability',         'shortcode' => '[umpire_availability]',     'setting' => 'slug_availability' ],
        'league-schedule'        => [ 'title' => 'League Schedule',         'shortcode' => '[league_schedule]',         'setting' => 'slug_league_schedule' ],
        'league-rules'           => [ 'title' => 'League Rules',            'shortcode' => '[league_rules]',            'setting' => 'slug_league_rules' ],
        'tournament-schedule'    => [ 'title' => 'Tournament Schedule',     'shortcode' => '[tournament_schedule]',     'setting' => 'slug_tournament_schedule' ],
        'umpire-list'            => [ 'title' => 'Umpire List',             'shortcode' => '[umpire_list]',             'setting' => 'slug_umpire_list' ],
        'allocator-dashboard'    => [ 'title' => 'Allocator Dashboard',     'shortcode' => '[allocator_dashboard]',     'setting' => 'slug_allocator_dashboard' ],
        'allocator-pay-reports'  => [ 'title' => 'Pay Reports',             'shortcode' => '[allocator_pay_reports]',   'setting' => 'slug_allocator_pay_reports' ],
        'allocator-games'        => [ 'title' => 'Game Management',         'shortcode' => '[allocator_games]',         'setting' => 'slug_allocator_games' ],
        'allocator-past-games'   => [ 'title' => 'Past Games',              'shortcode' => '[allocator_past_games]',    'setting' => 'slug_allocator_past_games' ],
        'tournament-management'  => [ 'title' => 'Tournament Management',   'shortcode' => '[tournament_management]',   'setting' => 'slug_allocator_tournament_games' ],
        'allocator-umpire-history' => [ 'title' => 'Umpire History',        'shortcode' => '[allocator_umpire_history]','setting' => 'slug_allocator_umpire_history' ],
        'allocator-broadcast'      => [ 'title' => 'Broadcast Message',     'shortcode' => '[allocator_broadcast]',     'setting' => 'slug_allocator_broadcast' ],
        'invoices'                 => [ 'title' => 'Invoices',               'shortcode' => '[allocator_invoices]',      'setting' => 'slug_allocator_invoices' ],
        'umpire-register'          => [ 'title' => 'Create Account',         'shortcode' => '[umpire_register]',         'setting' => 'slug_register' ],
        'umpire-home'              => [ 'title' => 'Home',                    'shortcode' => '[umpire_home]',             'setting' => 'slug_home' ],
    ];

    $settings = get_option( 'us_settings', [] );

    foreach ( $pages as $slug => $page ) {
        $existing = get_page_by_path( $slug );
        if ( $existing ) {
            $id = $existing->ID;
        } else {
            $id = wp_insert_post( [
                'post_title'   => $page['title'],
                'post_name'    => $slug,
                'post_content' => $page['shortcode'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ] );
        }
        // Apply the plugin's canvas template to every app page
        if ( $id && ! is_wp_error( $id ) ) {
            update_post_meta( $id, '_wp_page_template', 'umpire-app-page' );
        }
        $settings[ $page['setting'] ] = $slug;
    }

    update_option( 'us_settings', $settings );
    flush_rewrite_rules();
}

// ── Render wizard ─────────────────────────────────────────────
function us_wizard_render( $step ) {
    $steps = [ 1 => 'Organisation', 2 => 'Contact & Email', 3 => 'Create Pages' ];
    $settings = get_option( 'us_settings', [] );
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo( 'charset' ); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Umpire Scheduler — Setup</title>
        <?php wp_head(); ?>
        <style>
            body { background: #f0f4f8; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; padding: 40px 20px; }
            .wiz-wrap { max-width: 560px; margin: 0 auto; }
            .wiz-header { text-align: center; margin-bottom: 32px; }
            .wiz-header h1 { font-size: 24px; color: #091b33; margin: 0 0 6px; }
            .wiz-header p { color: #666; font-size: 14px; margin: 0; }
            .wiz-steps { display: flex; gap: 0; margin-bottom: 32px; border-radius: 8px; overflow: hidden; border: 1px solid #dde3ea; }
            .wiz-step { flex: 1; padding: 10px; text-align: center; font-size: 12px; font-weight: 600; background: #fff; color: #999; border-right: 1px solid #dde3ea; }
            .wiz-step:last-child { border-right: none; }
            .wiz-step--active { background: #091b33; color: #fff; }
            .wiz-step--done { background: #e8f5e9; color: #1a7f3c; }
            .wiz-card { background: #fff; border-radius: 10px; padding: 32px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
            .wiz-card h2 { font-size: 18px; color: #091b33; margin: 0 0 24px; padding-bottom: 16px; border-bottom: 1px solid #eef0f3; }
            .wiz-field { margin-bottom: 20px; }
            .wiz-field label { display: block; font-size: 13px; font-weight: 600; color: #333; margin-bottom: 6px; }
            .wiz-field input, .wiz-field select { width: 100%; padding: 10px 12px; border: 1px solid #dde3ea; border-radius: 6px; font-size: 14px; color: #333; box-sizing: border-box; }
            .wiz-field input:focus, .wiz-field select:focus { outline: none; border-color: #091b33; box-shadow: 0 0 0 3px rgba(9,27,51,0.08); }
            .wiz-field .desc { font-size: 12px; color: #888; margin-top: 4px; }
            .wiz-pages { list-style: none; padding: 0; margin: 0 0 24px; }
            .wiz-pages li { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f0f4f8; font-size: 13px; color: #444; }
            .wiz-pages li:last-child { border-bottom: none; }
            .wiz-pages .badge { font-size: 11px; background: #eef0f3; color: #666; padding: 2px 7px; border-radius: 4px; font-family: monospace; white-space: nowrap; }
            .wiz-btn { display: block; width: 100%; padding: 13px; background: #091b33; color: #fff; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; margin-top: 8px; }
            .wiz-btn:hover { background: #0d2640; }
            .wiz-btn--success { background: #1a7f3c; }
            .wiz-skip { display: block; text-align: center; margin-top: 14px; font-size: 12px; color: #999; text-decoration: none; }
            .wiz-skip:hover { color: #666; }
            .wiz-done { text-align: center; padding: 16px 0; }
            .wiz-done .icon { font-size: 56px; margin-bottom: 16px; }
            .wiz-done h2 { font-size: 22px; color: #1a7f3c; margin-bottom: 8px; border: none; }
            .wiz-done p { color: #666; font-size: 14px; margin-bottom: 24px; }
        </style>
    </head>
    <body>
    <div class="wiz-wrap">

        <div class="wiz-header">
            <h1>&#127937; Umpire Scheduler Setup</h1>
            <p>Let's get your organisation configured in just a few steps.</p>
        </div>

        <?php if ( $step < 4 ) : ?>
        <div class="wiz-steps">
            <?php foreach ( $steps as $n => $label ) : ?>
                <div class="wiz-step <?php
                    if ( $n < $step )  echo 'wiz-step--done';
                    elseif ( $n === $step ) echo 'wiz-step--active';
                ?>">
                    <?php echo $n < $step ? '&#10003; ' : $n . '. '; echo $label; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="wiz-card">

        <?php if ( $step === 1 ) : ?>
            <h2>Organisation details</h2>
            <form method="post">
                <?php wp_nonce_field( 'us_wizard', 'us_wizard_nonce' ); ?>
                <input type="hidden" name="step" value="1">
                <div class="wiz-field">
                    <label>Full organisation name</label>
                    <input type="text" name="org_name" value="<?php echo esc_attr( $settings['org_name'] ?? '' ); ?>" placeholder="e.g. Greater Vancouver Slo-Pitch Association" required>
                    <p class="desc">Used in page titles and emails.</p>
                </div>
                <div class="wiz-field">
                    <label>Short name / abbreviation</label>
                    <input type="text" name="org_short" value="<?php echo esc_attr( $settings['org_short'] ?? '' ); ?>" placeholder="e.g. GVSU" maxlength="6" required>
                    <p class="desc">Shown in the logo badge. Max 6 characters.</p>
                </div>
                <div class="wiz-field">
                    <label>App title</label>
                    <input type="text" name="app_title" value="<?php echo esc_attr( $settings['app_title'] ?? 'Umpire Scheduler' ); ?>" placeholder="e.g. Umpire Scheduler" required>
                    <p class="desc">Displayed in the top navigation bar.</p>
                </div>
                <div class="wiz-field">
                    <label>Tagline <span style="font-weight:400;color:#aaa">(optional)</span></label>
                    <input type="text" name="tagline" value="<?php echo esc_attr( $settings['tagline'] ?? '' ); ?>" placeholder="e.g. Professional umpire services for your league">
                    <p class="desc">Shown on the home page under the organisation name.</p>
                </div>
                <div class="wiz-field">
                    <label>Logo <span style="font-weight:400;color:#aaa">(optional)</span></label>
                    <?php
                    $wiz_logo_id  = (int) ( $settings['logo_id'] ?? 0 );
                    $wiz_logo_url = $wiz_logo_id ? wp_get_attachment_image_url( $wiz_logo_id, 'medium' ) : '';
                    ?>
                    <input type="hidden" name="logo_id" id="wiz-logo-id" value="<?php echo esc_attr( $wiz_logo_id ); ?>">
                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:4px;">
                        <div id="wiz-logo-preview" style="<?php echo $wiz_logo_url ? '' : 'display:none;'; ?>">
                            <img id="wiz-logo-img" src="<?php echo esc_url( $wiz_logo_url ); ?>"
                                 style="max-height:60px;max-width:180px;border:1px solid #dde3ea;border-radius:4px;padding:4px;">
                        </div>
                        <div>
                            <button type="button" id="wiz-logo-btn" class="button"><?php echo $wiz_logo_url ? 'Change logo' : 'Upload logo'; ?></button>
                            <button type="button" id="wiz-logo-remove" class="button" style="margin-left:6px;<?php echo $wiz_logo_url ? '' : 'display:none;'; ?>">Remove</button>
                        </div>
                    </div>
                    <p class="desc">Shown on login, registration, and invoice pages. You can skip this and add it later.</p>
                    <script>
                    jQuery(function($){
                        var frame;
                        $('#wiz-logo-btn').on('click', function(e){
                            e.preventDefault();
                            if ( frame ) { frame.open(); return; }
                            frame = wp.media({ title: 'Select logo', button: { text: 'Use this image' }, multiple: false, library: { type: 'image' } });
                            frame.on('select', function(){
                                var att = frame.state().get('selection').first().toJSON();
                                $('#wiz-logo-id').val(att.id);
                                var src = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
                                $('#wiz-logo-img').attr('src', src);
                                $('#wiz-logo-preview').show();
                                $('#wiz-logo-remove').show();
                                $('#wiz-logo-btn').text('Change logo');
                            });
                            frame.open();
                        });
                        $('#wiz-logo-remove').on('click', function(){
                            $('#wiz-logo-id').val('0');
                            $('#wiz-logo-preview').hide();
                            $(this).hide();
                            $('#wiz-logo-btn').text('Upload logo');
                        });
                    });
                    </script>
                </div>
                <div style="display:flex;gap:16px;">
                    <div class="wiz-field" style="flex:1">
                        <label>Primary colour</label>
                        <input type="color" name="primary_color" value="<?php echo esc_attr( $settings['primary_color'] ?? '#091b33' ); ?>" style="width:100%;height:42px;border-radius:6px;border:1px solid #dde3ea;cursor:pointer;">
                    </div>
                    <div class="wiz-field" style="flex:1">
                        <label>Secondary colour</label>
                        <input type="color" name="secondary_color" value="<?php echo esc_attr( $settings['secondary_color'] ?? '#598cb9' ); ?>" style="width:100%;height:42px;border-radius:6px;border:1px solid #dde3ea;cursor:pointer;">
                    </div>
                </div>
                <button type="submit" class="wiz-btn">Continue &rarr;</button>
            </form>

        <?php elseif ( $step === 2 ) : ?>
            <h2>Contact &amp; email</h2>
            <form method="post">
                <?php wp_nonce_field( 'us_wizard', 'us_wizard_nonce' ); ?>
                <input type="hidden" name="step" value="2">
                <div class="wiz-field">
                    <label>Assignor name</label>
                    <input type="text" name="assignor_name" value="<?php echo esc_attr( $settings['assignor_name'] ?? '' ); ?>" placeholder="e.g. Dave Webb" required>
                </div>
                <div class="wiz-field">
                    <label>Assignor email</label>
                    <input type="email" name="assignor_email" value="<?php echo esc_attr( $settings['assignor_email'] ?? get_option( 'admin_email' ) ); ?>" required>
                    <p class="desc">Receives all umpire notifications.</p>
                </div>
                <div class="wiz-field">
                    <label>Email footer text</label>
                    <input type="text" name="email_footer" value="<?php echo esc_attr( $settings['email_footer'] ?? '' ); ?>" placeholder="e.g. GVSU Umpire Scheduler">
                    <p class="desc">Appears at the bottom of every email sent to umpires.</p>
                </div>
                <div class="wiz-field">
                    <label>Timezone</label>
                    <select name="timezone">
                        <?php
                        $timezones = [
                            'Canada'         => [ 'America/Vancouver' => 'Pacific — Vancouver', 'America/Edmonton' => 'Mountain — Edmonton', 'America/Winnipeg' => 'Central — Winnipeg', 'America/Toronto' => 'Eastern — Toronto', 'America/Halifax' => 'Atlantic — Halifax', 'America/St_Johns' => 'Newfoundland' ],
                            'United States'  => [ 'America/Los_Angeles' => 'Pacific — Los Angeles', 'America/Denver' => 'Mountain — Denver', 'America/Chicago' => 'Central — Chicago', 'America/New_York' => 'Eastern — New York' ],
                            'Australia'      => [ 'Australia/Perth' => 'Western — Perth', 'Australia/Adelaide' => 'Central — Adelaide', 'Australia/Sydney' => 'Eastern — Sydney' ],
                            'United Kingdom' => [ 'Europe/London' => 'London' ],
                            'New Zealand'    => [ 'Pacific/Auckland' => 'Auckland' ],
                        ];
                        $current_tz = $settings['timezone'] ?? 'America/Vancouver';
                        foreach ( $timezones as $region => $zones ) {
                            echo '<optgroup label="' . esc_attr( $region ) . '">';
                            foreach ( $zones as $tz => $label ) {
                                echo '<option value="' . esc_attr( $tz ) . '"' . selected( $current_tz, $tz, false ) . '>' . esc_html( $label ) . '</option>';
                            }
                            echo '</optgroup>';
                        }
                        ?>
                    </select>
                </div>
                <button type="submit" class="wiz-btn">Continue &rarr;</button>
            </form>

        <?php elseif ( $step === 3 ) : ?>
            <h2>Create pages</h2>
            <p style="font-size:13px;color:#666;margin-bottom:20px">The following WordPress pages will be created automatically with the correct shortcodes. You can rename them later if needed.</p>
            <ul class="wiz-pages">
                <?php
                $pages = [
                    'Umpire Dashboard'       => '[umpire_dashboard]',
                    'My Schedule'            => '[umpire_schedule]',
                    'Open Games'             => '[umpire_open_games]',
                    'My Earnings'            => '[umpire_earnings]',
                    'My Availability'        => '[umpire_availability]',
                    'League Schedule'        => '[league_schedule]',
                    'League Rules'           => '[league_rules]',
                    'Tournament Schedule'    => '[tournament_schedule]',
                    'Umpire List'            => '[umpire_list]',
                    'Allocator Dashboard'    => '[allocator_dashboard]',
                    'Pay Reports'            => '[allocator_pay_reports]',
                    'Game Management'        => '[allocator_games]',
                    'Past Games'             => '[allocator_past_games]',
                    'Tournament Management'  => '[tournament_management]',
                    'Umpire History'         => '[allocator_umpire_history]',
                    'Broadcast Message'      => '[allocator_broadcast]',
                    'Invoices'               => '[allocator_invoices]',
                    'Create Account'         => '[umpire_register]',
                    'Home'                   => '[umpire_home]',
                ];
                foreach ( $pages as $title => $shortcode ) :
                ?>
                <li>
                    <span>&#128196; <?php echo esc_html( $title ); ?></span>
                    <span class="badge"><?php echo esc_html( $shortcode ); ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <form method="post">
                <?php wp_nonce_field( 'us_wizard', 'us_wizard_nonce' ); ?>
                <input type="hidden" name="step" value="3">
                <button type="submit" class="wiz-btn wiz-btn--success">&#10003; Create all pages &amp; finish</button>
            </form>

        <?php elseif ( $step === 4 ) : ?>
            <div class="wiz-done">
                <div class="icon">&#127881;</div>
                <h2>You're all set!</h2>
                <p>All pages have been created. Head to the dashboard to start adding leagues and umpires.</p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=umpire-scheduler' ) ); ?>" class="wiz-btn" style="display:inline-block;width:auto;text-decoration:none;padding:13px 32px">
                    Go to Dashboard &rarr;
                </a>
            </div>
        <?php endif; ?>

        </div><!-- /.wiz-card -->

        <?php if ( $step < 4 ) : ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=umpire-scheduler' ) ); ?>" class="wiz-skip">
            Skip setup — I'll configure manually
        </a>
        <?php endif; ?>

    </div>
    <?php wp_footer(); ?>
    </body>
    </html>
    <?php
}
