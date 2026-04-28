<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Shortcode ─────────────────────────────────────────────────
add_shortcode( 'allocator_past_games', 'us_shortcode_allocator_past_games' );
function us_shortcode_allocator_past_games() {
    if ( ! is_user_logged_in() ) return us_login_form();

    if ( ! us_is_allocator() ) {
        return '<script>window.location="' . esc_url( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) ) . '";</script>';
    }

    $today    = current_time( 'Y-m-d' );
    $base_url = home_url( '/' . us_setting( 'slug_allocator_past_games' ) . '/' );

    $current_month  = current_time( 'Y-m' );
    $selected_month = isset( $_GET['us_month'] ) ? sanitize_text_field( $_GET['us_month'] ) : $current_month;
    if ( ! preg_match( '/^\d{4}-\d{2}$/', $selected_month ) ) $selected_month = $current_month;

    $month_start = $selected_month . '-01';
    $month_end   = date( 'Y-m-t', strtotime( $month_start ) );
    $prev_month  = date( 'Y-m', strtotime( $month_start . ' -1 month' ) );
    $next_month  = date( 'Y-m', strtotime( $month_start . ' +1 month' ) );
    $month_label = date( 'F Y', strtotime( $month_start ) );

    $all_leagues = us_get_active_leagues();

    $selected_league_id = isset( $_GET['league_id'] ) ? absint( $_GET['league_id'] ) : 0;

    $yesterday = date( 'Y-m-d', strtotime( $today . ' -1 day' ) );
    $range_end = $month_end < $yesterday ? $month_end : $yesterday;

    $game_meta_query = [
        [ 'key' => 'us_game_date', 'value' => $month_start, 'compare' => '>=' ],
        [ 'key' => 'us_game_date', 'value' => $range_end,   'compare' => '<=' ],
    ];
    if ( $selected_league_id > 0 ) {
        $game_meta_query[] = [ 'key' => 'us_league_id', 'value' => $selected_league_id, 'compare' => '=' ];
    }

    $games = get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_key'    => 'us_game_date',
        'orderby'     => 'meta_value',
        'order'       => 'DESC',
        'meta_query'  => $game_meta_query,
    ] );

    $all_umpires = us_get_active_umpires();

    ob_start();
    ?>
    <div class="us-dashboard">

        <div class="us-alloc-dashboard__header">
            <div>
                <h2>Past Games</h2>
                <p class="us-alloc-dashboard__date">View and manage completed games.</p>
            </div>
            <div class="us-alloc-games__header-actions">
                <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_allocator_games' ) . '/' ) ); ?>"
                   class="us-btn us-btn--muted us-btn--sm">Upcoming Games</a>
                <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_allocator_dashboard' ) . '/' ) ); ?>"
                   class="us-btn us-btn-request us-btn--sm">&larr; Dashboard</a>
            </div>
        </div>

        <div class="us-alloc-games__tabs">
            <a href="<?php echo esc_url( add_query_arg( [ 'league_id' => '0', 'us_month' => $selected_month ], $base_url ) ); ?>"
               class="us-alloc-games__tab<?php echo $selected_league_id === 0 ? ' us-alloc-games__tab--active' : ''; ?>">All leagues</a>
            <?php foreach ( $all_leagues as $l ) :
                $is_tourney = get_post_meta( $l->ID, 'us_is_tournament', true ) === '1';
            ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'league_id' => $l->ID, 'us_month' => $selected_month ], $base_url ) ); ?>"
               class="us-alloc-games__tab<?php echo $l->ID === $selected_league_id ? ' us-alloc-games__tab--active' : ''; ?>">
                <?php echo esc_html( $l->post_title ); ?>
                <?php if ( $is_tourney ) echo '<span class="us-alloc-games__tab-tourney">TOURNEY</span>'; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="us-alloc-games__month-nav">
            <a href="<?php echo esc_url( add_query_arg( [ 'league_id' => $selected_league_id, 'us_month' => $prev_month ], $base_url ) ); ?>" class="us-alloc-games__month-btn"><?php echo esc_html( date( 'M Y', strtotime( $prev_month . '-01' ) ) ); ?></a>
            <span class="us-alloc-games__month-label"><?php echo esc_html( $month_label ); ?></span>
            <a href="<?php echo esc_url( add_query_arg( [ 'league_id' => $selected_league_id, 'us_month' => $next_month ], $base_url ) ); ?>" class="us-alloc-games__month-btn"><?php echo esc_html( date( 'M Y', strtotime( $next_month . '-01' ) ) ); ?></a>
        </div>

        <div class="us-alloc-games__meta-row">
            <span class="us-alloc-games__count">
                <?php echo count( $games ); ?> past game<?php echo count( $games ) !== 1 ? 's' : ''; ?>
                in <?php echo esc_html( $month_label ); ?>
            </span>
        </div>

        <?php if ( empty( $games ) ) : ?>
            <p class="us-empty">No past games found for <?php echo esc_html( $month_label ); ?>.</p>
        <?php else : ?>

        <?php $grouped_games = us_group_doubleheaders( $games ); ?>
        <div class="us-mgmt-cards">
            <?php foreach ( $grouped_games as $entry ) :
                if ( $entry['type'] === 'doubleheader' ) {
                    echo us_render_dh_mgmt_card( $entry['games'], $today, $selected_league_id, $all_umpires, true );
                } else {
                    echo us_render_mgmt_game_card( $entry['game'], $today, $selected_league_id, $all_umpires, true );
                }
            endforeach; ?>
        </div>

        <p class="us-table-count">
            <?php echo count( $games ); ?> game<?php echo count( $games ) !== 1 ? 's' : ''; ?>
            in <?php echo esc_html( $month_label ); ?>
        </p>

        <?php endif; ?>
    </div>

    <?php echo us_mgmt_game_modals( $all_leagues ); ?>
    <?php echo us_mgmt_game_js(); ?>
    <?php
    return ob_get_clean();
}