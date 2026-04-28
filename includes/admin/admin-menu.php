<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Main admin menu ───────────────────────────────────────────
add_action( 'admin_menu', 'us_admin_menu' );
function us_admin_menu() {

    add_menu_page(
        'Umpire Scheduler',
        'Umpire Scheduler',
        'manage_options',
        'umpire-scheduler',
        'us_dashboard_page',
        'dashicons-calendar-alt',
        30
    );

    // Dashboard — same slug as parent makes it the default tab
    add_submenu_page(
        'umpire-scheduler',
        'Dashboard',
        'Dashboard',
        'manage_options',
        'umpire-scheduler',
        'us_dashboard_page'
    );

    add_submenu_page(
        'umpire-scheduler',
        'Import Schedule',
        'Import Schedule',
        'manage_options',
        'us-ics-import',
        'us_import_page'
    );

    // Requests with pending count badge
    $count = us_get_pending_requests_count();
    $badge = $count > 0 ? ' <span class="awaiting-mod">' . $count . '</span>' : '';
    add_submenu_page(
        'umpire-scheduler',
        'Requests',
        'Requests' . $badge,
        'manage_options',
        'us-requests',
        'us_requests_page'
    );

    add_submenu_page(
        'umpire-scheduler',
        'Pay Reports',
        'Pay Reports',
        'manage_options',
        'us-pay-reports',
        'us_pay_reports_page'
    );

    add_submenu_page(
        'umpire-scheduler',
        'Umpires',
        'Umpires',
        'manage_options',
        'edit.php?post_type=' . US_PT_UMPIRE,
        ''
    );

    add_submenu_page(
        'umpire-scheduler',
        'Leagues',
        'Leagues',
        'manage_options',
        'edit.php?post_type=' . US_PT_LEAGUE . '&us_type=league',
        ''
    );

    // Regular league game pages — nested immediately under Leagues
    $all_leagues = get_posts( [
        'post_type'   => US_PT_LEAGUE,
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'post_status' => 'publish',
    ] );

    foreach ( $all_leagues as $league ) {
        if ( get_post_meta( $league->ID, 'us_is_tournament', true ) === '1' ) continue;
        add_submenu_page(
            'umpire-scheduler',
            $league->post_title . ' — Games',
            '&nbsp;&nbsp;&nbsp;' . $league->post_title,
            'manage_options',
            'us-league-games-' . $league->ID,
            function() use ( $league ) { us_league_games_page( $league ); }
        );
    }

    add_submenu_page(
        'umpire-scheduler',
        'Tournaments',
        'Tournaments',
        'manage_options',
        'edit.php?post_type=' . US_PT_LEAGUE . '&us_type=tournament',
        ''
    );

    // Tournament game pages — nested immediately under Tournaments
    foreach ( $all_leagues as $league ) {
        if ( get_post_meta( $league->ID, 'us_is_tournament', true ) !== '1' ) continue;
        add_submenu_page(
            'umpire-scheduler',
            $league->post_title . ' — Games',
            '&nbsp;&nbsp;&nbsp;' . $league->post_title,
            'manage_options',
            'us-league-games-' . $league->ID,
            function() use ( $league ) { us_league_games_page( $league ); }
        );
    }
}

// ── Filter league list by type (league vs tournament) ─────────
add_action( 'pre_get_posts', 'us_filter_league_list_by_type' );
function us_filter_league_list_by_type( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) return;
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== US_PT_LEAGUE || $screen->base !== 'edit' ) return;

    $type = sanitize_text_field( $_GET['us_type'] ?? '' );
    if ( $type === 'league' ) {
        $query->set( 'meta_query', [
            'relation' => 'OR',
            [ 'key' => 'us_is_tournament', 'compare' => 'NOT EXISTS' ],
            [ 'key' => 'us_is_tournament', 'value' => '1', 'compare' => '!=' ],
        ] );
    } elseif ( $type === 'tournament' ) {
        $query->set( 'meta_query', [
            [ 'key' => 'us_is_tournament', 'value' => '1', 'compare' => '=' ],
        ] );
    }
}

// ── Keep correct submenu highlighted on league/tournament list ─
add_filter( 'submenu_file', 'us_league_submenu_highlight', 10, 2 );
function us_league_submenu_highlight( $submenu_file, $parent_file ) {
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== US_PT_LEAGUE ) return $submenu_file;

    $type = sanitize_text_field( $_GET['us_type'] ?? '' );
    if ( $type === 'league' ) {
        return 'edit.php?post_type=' . US_PT_LEAGUE . '&us_type=league';
    }
    if ( $type === 'tournament' ) {
        return 'edit.php?post_type=' . US_PT_LEAGUE . '&us_type=tournament';
    }
    return $submenu_file;
}

// ── Auto-set tournament flag on new post screen ───────────────
add_action( 'admin_footer', 'us_auto_set_league_type' );
function us_auto_set_league_type() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->base !== 'post' || $screen->post_type !== US_PT_LEAGUE ) return;
    $new_as = sanitize_text_field( $_GET['us_new_as'] ?? '' );
    if ( $new_as !== 'tournament' ) return;
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var cb = document.getElementById('us_is_tournament');
        if (cb && !cb.checked) {
            cb.checked = true;
            cb.dispatchEvent(new Event('change'));
        }
    });
    </script>
    <?php
}

// ── Append us_new_as param to "Add New" link on list screen ───
add_action( 'admin_footer', 'us_league_list_addnew_param' );
function us_league_list_addnew_param() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->base !== 'edit' || $screen->post_type !== US_PT_LEAGUE ) return;
    $type = sanitize_text_field( $_GET['us_type'] ?? '' );
    if ( ! in_array( $type, [ 'league', 'tournament' ], true ) ) return;
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('a.page-title-action').forEach(function (link) {
            if (link.href && link.href.indexOf('post-new.php') !== -1) {
                link.href += '&us_new_as=<?php echo esc_js( $type ); ?>';
            }
        });
    });
    </script>
    <?php
}

// ── Admin scripts & styles ────────────────────────────────────
add_action( 'admin_enqueue_scripts', 'us_admin_scripts' );
function us_admin_scripts( $hook ) {
    $screen = get_current_screen();

    $is_plugin_page = (
        strpos( $hook, 'umpire-scheduler' ) !== false ||
        strpos( $hook, 'us-league-games-' ) !== false ||
        strpos( $hook, 'us-requests'      ) !== false ||
        strpos( $hook, 'us-pay-reports'   ) !== false
    );

    $is_cpt_screen = $screen && in_array( $screen->post_type, [
        US_PT_GAME,
        US_PT_LEAGUE,
        US_PT_UMPIRE,
        US_PT_ASSIGNMENT,
        US_PT_PAYMENT,
    ], true );

    if ( $is_plugin_page || $is_cpt_screen ) {
        wp_enqueue_style(
            'us-admin',
            US_URL . 'assets/umpire-admin.css',
            [],
            US_VERSION
        );
    }

    if ( ! $is_plugin_page ) return;

    wp_enqueue_script(
        'us-admin',
        US_URL . 'assets/admin.js',
        [ 'jquery' ],
        US_VERSION,
        true
    );

    wp_localize_script( 'us-admin', 'usAssign', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'us_assign_nonce' ),
    ] );
}