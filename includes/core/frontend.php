<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Page template registration ────────────────────────────────
// Registers "Umpire App Page" as a selectable template and serves it
// from the plugin directory so it works with any active theme.
add_filter( 'theme_page_templates', function( $templates ) {
    $templates['umpire-app-page'] = __( 'Umpire App Page', 'umpire-scheduler' );
    return $templates;
} );

add_filter( 'template_include', function( $template ) {
    if ( is_singular( 'page' ) && get_post_meta( get_the_ID(), '_wp_page_template', true ) === 'umpire-app-page' ) {
        $plugin_tpl = US_PATH . 'templates/umpire-app-page.php';
        if ( file_exists( $plugin_tpl ) ) {
            return $plugin_tpl;
        }
    }
    return $template;
} );

// ── Umpire page detection ─────────────────────────────────────
function us_is_umpire_page() {
    $slugs = array_filter( [
        us_setting( 'slug_dashboard' ),
        us_setting( 'slug_schedule' ),
        us_setting( 'slug_open_games' ),
        us_setting( 'slug_earnings' ),
        us_setting( 'slug_availability' ),
        us_setting( 'slug_league_schedule' ),
        us_setting( 'slug_tournament_schedule' ),
        us_setting( 'slug_umpire_list' ),
        us_setting( 'slug_allocator_dashboard' ),
        us_setting( 'slug_allocator_pay_reports' ),
        us_setting( 'slug_allocator_games' ),
        us_setting( 'slug_allocator_past_games' ),
        us_setting( 'slug_allocator_tournament_games' ),
        us_setting( 'slug_allocator_umpire_history' ),
        us_setting( 'slug_allocator_broadcast' ),
        us_setting( 'slug_allocator_invoices' ),
        us_setting( 'slug_register' ),
        us_setting( 'slug_home' ),
    ] );
    if ( is_front_page() ) return true;
    if ( is_page( $slugs ) ) return true;
    if ( is_singular() && is_page_template( 'page-umpire.php' ) ) return true;
    if ( get_query_var( 'us_league_rules' ) ) return true;

    if ( is_singular() ) {
        $post = get_post();
        if ( $post && has_shortcode( $post->post_content, 'umpire_login' ) ) return true;
    }

    return false;
}

// ── Register query var for league rules ───────────────────────
add_filter( 'query_vars', 'us_register_query_vars' );
function us_register_query_vars( $vars ) {
    $vars[] = 'us_league_rules';
    return $vars;
}

// ── Rewrite rule for /league-rules/123/ ──────────────────────
add_action( 'init', 'us_add_rules_rewrite' );
function us_add_rules_rewrite() {
    add_rewrite_rule(
        '^league-rules/([0-9]+)/?$',
        'index.php?us_league_rules=$matches[1]',
        'top'
    );
}

// ── Handle league rules — render directly and exit ────────────
add_action( 'template_redirect', 'us_handle_league_rules_page' );
function us_handle_league_rules_page() {
    $league_id = absint( get_query_var( 'us_league_rules' ) );
    if ( ! $league_id ) return;

    $league = get_post( $league_id );
    if ( ! $league || $league->post_type !== US_PT_LEAGUE ) {
        wp_redirect( home_url( '/' . us_setting( 'slug_league_schedule' ) . '/' ) );
        exit;
    }

    $rules = get_post_meta( $league_id, 'us_rules', true );
    if ( ! $rules ) {
        wp_redirect( home_url( '/' . us_setting( 'slug_league_schedule' ) . '/' ) );
        exit;
    }

    $content = '<div class="us-dashboard">'
        . '<h2>' . esc_html( $league->post_title ) . ' — Rules</h2>'
        . '<div class="us-rules__content">'
        . wp_kses_post( wpautop( $rules ) )
        . '</div></div>';

    $content = us_inject_dashboard_chrome( $content );

    ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo esc_html( $league->post_title ); ?> — Rules</title>
    <?php wp_head(); ?>
</head>
<body <?php body_class( 'us-page' ); ?>>
    <?php echo $content; ?>
<?php wp_footer(); ?>
</body>
</html>
    <?php
    exit;
}

// ── Helper: get URL for a league's rules page ─────────────────
function us_league_rules_url( $league_id ) {
    return home_url( '/league-rules/' . absint( $league_id ) . '/' );
}

// ── Enqueue front end assets ──────────────────────────────────
add_action( 'wp_enqueue_scripts', 'us_enqueue_frontend' );
function us_enqueue_frontend() {
    if ( ! us_is_umpire_page() ) return;

    wp_enqueue_style(
        'us-frontend',
        US_URL . 'assets/umpire-front.css',
        [],
        US_VERSION
    );

    wp_enqueue_style(
        'us-dashboard',
        US_URL . 'assets/umpire-dashboard.css',
        [ 'us-frontend' ],
        US_VERSION
    );

    wp_enqueue_style(
        'us-calendar',
        US_URL . 'assets/umpire-calendar.css',
        [ 'us-frontend' ],
        US_VERSION
    );

    wp_enqueue_style(
        'us-allocator',
        US_URL . 'assets/umpire-allocator.css',
        [ 'us-frontend' ],
        US_VERSION
    );

    wp_enqueue_style(
        'us-public',
        US_URL . 'assets/umpire-public.css',
        [ 'us-frontend' ],
        US_VERSION
    );

    wp_enqueue_script(
        'us-frontend-js',
        US_URL . 'assets/umpire-front.js',
        [ 'jquery' ],
        US_VERSION,
        true
    );

    wp_enqueue_script(
        'us-requests-js',
        US_URL . 'assets/umpire-requests.js',
        [ 'jquery' ],
        US_VERSION,
        true
    );

    // ── Availability calendar ─────────────────────────────────
    if ( is_page( us_setting( 'slug_availability' ) ) && is_user_logged_in() ) {
        $umpire = us_get_umpire_by_user( get_current_user_id() );
        if ( $umpire ) {
            wp_enqueue_script(
                'us-availability-js',
                US_URL . 'assets/umpire-availability.js',
                [],
                US_VERSION,
                true
            );
            wp_localize_script( 'us-availability-js', 'usAvailability', [
                'ajax_url'    => admin_url( 'admin-ajax.php' ),
                'umpire_id'   => $umpire->ID,
                'fetch_nonce' => wp_create_nonce( 'us_fetch_availability_nonce' ),
                'save_nonce'  => wp_create_nonce( 'us_availability_nonce' ),
                'game_dates'  => us_get_umpire_game_dates( $umpire->ID ),
            ] );
        }
    }

    // ── Allocator dashboard ───────────────────────────────────
    if ( is_page( us_setting( 'slug_allocator_dashboard' ) ) ) {
        wp_enqueue_script(
            'us-alloc-dashboard-js',
            US_URL . 'assets/allocator-dashboard.js',
            [ 'jquery' ],
            US_VERSION,
            true
        );
        wp_localize_script( 'us-alloc-dashboard-js', 'usAllocDash', [
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'us_assign_nonce' ),
            'past_count' => 0, // overridden via wp_add_inline_script from shortcode
        ] );
    }

    // ── Allocator games ───────────────────────────────────────
    if ( is_page( us_setting( 'slug_allocator_games' ) ) || is_page( us_setting( 'slug_allocator_tournament_games' ) ) || is_page( us_setting( 'slug_allocator_past_games' ) ) ) {
        wp_enqueue_script(
            'us-alloc-games-js',
            US_URL . 'assets/allocator-games.js',
            [ 'jquery' ],
            US_VERSION,
            true
        );
        wp_localize_script( 'us-alloc-games-js', 'usAllocGames', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'us_assign_nonce' ),
        ] );
    }

    // ── Allocator pay reports ─────────────────────────────────
    if ( is_page( us_setting( 'slug_allocator_pay_reports' ) ) ) {
        wp_enqueue_script(
            'us-alloc-pay-js',
            US_URL . 'assets/allocator-pay-reports.js',
            [],
            US_VERSION,
            true
        );
        wp_localize_script( 'us-alloc-pay-js', 'usAllocPay', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'us_export_pay' ),
        ] );
    }

    // Inject custom brand colours as CSS variable overrides
    $primary   = us_setting( 'primary_color' )   ?: '#091b33';
    $secondary = us_setting( 'secondary_color' ) ?: '#598cb9';
    wp_add_inline_style( 'us-frontend', ':root { --us-primary: ' . sanitize_hex_color( $primary ) . '; --us-secondary: ' . sanitize_hex_color( $secondary ) . '; }' );

    wp_localize_script( 'us-frontend-js', 'usFront', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'us_front_nonce' ),
    ] );
}

// ── Hide WP admin bar on front-end umpire pages ───────────────
add_filter( 'show_admin_bar', 'us_hide_admin_bar' );
function us_hide_admin_bar( $show ) {
    if ( ! current_user_can( 'manage_options' ) ) return false;
    if ( ! is_admin() && us_is_umpire_page() ) return false;
    return $show;
}

// ── Inject top bar + flyout nav ───────────────────────────────
add_filter( 'the_content', 'us_inject_dashboard_chrome' );
function us_inject_dashboard_chrome( $content ) {
    if ( ! us_is_umpire_page() ) return $content;

    $user_id  = get_current_user_id();
    $is_admin = current_user_can( 'manage_options' );
    $umpire   = is_user_logged_in() ? us_get_umpire_by_user( $user_id ) : null;
    $name     = $umpire
        ? $umpire->post_title
        : ( is_user_logged_in() ? wp_get_current_user()->display_name : 'Guest' );

    $logo_url = us_get_logo_url( 'medium' );

    // ── Public items — always visible ─────────────────────────
    $public_items   = [];
    $public_items[] = [
        'label' => 'League Schedule',
        'url'   => home_url( '/' . us_setting( 'slug_league_schedule' ) . '/' ),
    ];

    // ── League Rules — only if at least one league has rules ──
    $leagues_with_rules = get_posts( [
        'post_type'   => US_PT_LEAGUE,
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'post_status' => 'publish',
        'meta_query'  => [
            [
                'key'     => 'us_rules',
                'value'   => '',
                'compare' => '!=',
            ],
        ],
    ] );

    if ( ! empty( $leagues_with_rules ) ) {
        $public_items[] = [
            'label'    => 'League Rules',
            'url'      => '',
            'children' => array_map( function( $l ) {
                return [
                    'label' => $l->post_title,
                    'url'   => us_league_rules_url( $l->ID ),
                ];
            }, $leagues_with_rules ),
        ];
    }

    $has_tournaments = ! empty( us_get_active_leagues( true ) );

    if ( $has_tournaments ) {
        $public_items[] = [
            'label' => 'Tournament Schedule',
            'url'   => home_url( '/' . us_setting( 'slug_tournament_schedule' ) . '/' ),
        ];
    }

    $public_items[] = [
        'label' => 'Umpire List',
        'url'   => home_url( '/' . us_setting( 'slug_umpire_list' ) . '/' ),
    ];

    // ── Member / admin items ──────────────────────────────────
    $member_items = [];

    if ( is_user_logged_in() ) {
        if ( $is_admin ) {
            $member_items[] = [ 'label' => 'Allocator Dashboard',   'url' => home_url( '/' . us_setting( 'slug_allocator_dashboard' )        . '/' ) ];
            $member_items[] = [ 'label' => 'Game Management',       'url' => home_url( '/' . us_setting( 'slug_allocator_games' )            . '/' ) ];
            $member_items[] = [ 'label' => 'Tournament Management', 'url' => home_url( '/' . us_setting( 'slug_allocator_tournament_games' ) . '/' ) ];
            $member_items[] = [ 'label' => 'Pay Reports',           'url' => home_url( '/' . us_setting( 'slug_allocator_pay_reports' )      . '/' ) ];
            $member_items[] = [ 'label' => 'Invoices',              'url' => home_url( '/' . us_setting( 'slug_allocator_invoices' )         . '/' ) ];
            $member_items[] = [ 'label' => 'Umpire History',        'url' => home_url( '/' . us_setting( 'slug_allocator_umpire_history' )   . '/' ) ];
            $member_items[] = [ 'label' => 'Broadcast Message',     'url' => home_url( '/' . us_setting( 'slug_allocator_broadcast' ) . '/' ) ];
            $member_items[] = [ 'label' => 'WP Admin',              'url' => admin_url(), 'divider' => true ];
        } else {
            $dash_url = us_is_allocator()
                ? home_url( '/' . us_setting( 'slug_allocator_dashboard' ) . '/' )
                : home_url( '/' . us_setting( 'slug_dashboard' ) . '/' );

            $member_items[] = [ 'label' => 'My Dashboard',        'url' => $dash_url ];
            $member_items[] = [ 'label' => 'My Schedule',         'url' => home_url( '/' . us_setting( 'slug_schedule' )     . '/' ) ];
            $member_items[] = [ 'label' => 'Set My Availability', 'url' => home_url( '/' . us_setting( 'slug_availability' ) . '/' ) ];
            $member_items[] = [ 'label' => 'Open Games',          'url' => home_url( '/' . us_setting( 'slug_open_games' )   . '/' ) ];
            $member_items[] = [ 'label' => 'My Earnings',         'url' => home_url( '/' . us_setting( 'slug_earnings' )     . '/' ) ];

            if ( us_is_allocator() ) {
                $member_items[] = [ 'label' => 'Allocator',             'url' => '', 'divider' => true ];
                $member_items[] = [ 'label' => 'Allocator Dashboard',   'url' => home_url( '/' . us_setting( 'slug_allocator_dashboard' )        . '/' ) ];
                $member_items[] = [ 'label' => 'Pay Reports',           'url' => home_url( '/' . us_setting( 'slug_allocator_pay_reports' )      . '/' ) ];
                $member_items[] = [ 'label' => 'Invoices',              'url' => home_url( '/' . us_setting( 'slug_allocator_invoices' )         . '/' ) ];
                $member_items[] = [ 'label' => 'Broadcast Message',     'url' => home_url( '/' . us_setting( 'slug_allocator_broadcast' ) . '/' ) ];
                $member_items[] = [ 'label' => 'Game Management',       'url' => home_url( '/' . us_setting( 'slug_allocator_games' )            . '/' ) ];
                $member_items[] = [ 'label' => 'Tournament Management', 'url' => home_url( '/' . us_setting( 'slug_allocator_tournament_games' ) . '/' ) ];
                $member_items[] = [ 'label' => 'Umpire History',        'url' => home_url( '/' . us_setting( 'slug_allocator_umpire_history' )   . '/' ) ];
            }
        }
    }

    ob_start();
    ?>
    <div class="us-topbar">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="us-topbar-brand">
            <?php if ( $logo_url ) : ?>
                <img src="<?php echo esc_url( $logo_url ); ?>"
                     alt="<?php echo esc_attr( us_setting( 'org_short' ) ); ?>"
                     class="us-topbar-logo">
            <?php else : ?>
                <span class="us-topbar-logo-placeholder"><?php echo esc_html( us_setting( 'org_short' ) ); ?></span>
            <?php endif; ?>
            <?php if ( ! $logo_url ) : ?>
                <span class="us-topbar-title"><?php echo esc_html( us_setting( 'app_title' ) ); ?></span>
            <?php endif; ?>
        </a>
        <div class="us-topbar-right">
            <?php if ( is_user_logged_in() ) : ?>
                <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) ); ?>"
                   class="us-topbar-user"><?php echo esc_html( $name ); ?></a>
            <?php endif; ?>
            <button class="us-menu-btn" id="us-menu-btn" aria-label="Open menu">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>

    <div class="us-flyout-overlay" id="us-flyout-overlay"></div>

    <nav class="us-flyout" id="us-flyout">
        <div class="us-flyout-header">
            <span>Menu</span>
            <button class="us-flyout-close" id="us-flyout-close" aria-label="Close menu">&times;</button>
        </div>

        <ul class="us-flyout-nav">

            <li class="us-flyout-nav__item us-flyout-nav__item--public">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="us-flyout-nav__link us-flyout-nav__link--public">
                    Home
                </a>
            </li>

            <?php if ( is_user_logged_in() && ! empty( $member_items ) ) : ?>

            <?php foreach ( $member_items as $item ) :
                $is_divider = ! empty( $item['divider'] );
            ?>
                <?php if ( $is_divider ) : ?>
                <li class="us-flyout-nav__divider us-flyout-nav__divider--section">
                    <?php echo esc_html( $item['label'] ); ?>
                </li>
                <?php else : ?>
                <li class="us-flyout-nav__item us-flyout-nav__item--member">
                    <a href="<?php echo esc_url( $item['url'] ); ?>" class="us-flyout-nav__link us-flyout-nav__link--member">
                        <?php echo esc_html( $item['label'] ); ?>
                    </a>
                </li>
                <?php endif; ?>
            <?php endforeach; ?>

            <li class="us-flyout-nav__item us-flyout-nav__item--logout">
                <a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"
                   class="us-flyout-nav__link us-flyout-nav__link--logout">
                    &#x2192; Log out
                </a>
            </li>

            <?php elseif ( ! is_user_logged_in() ) : ?>

            <li class="us-flyout-nav__item us-flyout-nav__item--login">
                <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) ); ?>"
                   class="us-flyout-nav__link us-flyout-nav__link--login">
                    &#x2192; Log in
                </a>
            </li>

            <?php endif; ?>

        </ul>
    </nav>

    <div class="us-page-wrap">
    <?php
    $chrome  = ob_get_clean();
    $content = $chrome . $content . '</div>';
    return $content;
}