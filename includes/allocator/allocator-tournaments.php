<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Shortcode ─────────────────────────────────────────────────
add_shortcode( 'tournament_management', 'us_shortcode_tournament_management' );
function us_shortcode_tournament_management() {
    if ( ! is_user_logged_in() ) return us_login_form();

    if ( ! us_is_allocator() ) {
        return '<script>window.location="' . esc_url( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) ) . '";</script>';
    }

    $today    = current_time( 'Y-m-d' );
    $base_url = home_url( '/' . us_setting( 'slug_allocator_tournament_games' ) . '/' );

    $current_month  = current_time( 'Y-m' );
    $selected_month = isset( $_GET['us_month'] ) ? sanitize_text_field( $_GET['us_month'] ) : $current_month;
    if ( ! preg_match( '/^\d{4}-\d{2}$/', $selected_month ) ) $selected_month = $current_month;

    $month_start = $selected_month . '-01';
    $month_end   = date( 'Y-m-t', strtotime( $month_start ) );
    $prev_month  = date( 'Y-m', strtotime( $month_start . ' -1 month' ) );
    $next_month  = date( 'Y-m', strtotime( $month_start . ' +1 month' ) );
    $month_label = date( 'F Y', strtotime( $month_start ) );

    // ── Tournament leagues only ───────────────────────────────
    $all_leagues = us_get_active_leagues( true );

    $tournament_league_ids = ! empty( $all_leagues ) ? wp_list_pluck( $all_leagues, 'ID' ) : [];

    $selected_league_id = isset( $_GET['league_id'] ) ? absint( $_GET['league_id'] ) : 0;

    $range_start = ( $selected_month === $current_month && $today > $month_start ) ? $today : $month_start;

    $game_meta_query = [
        [ 'key' => 'us_game_date', 'value' => $range_start, 'compare' => '>=' ],
        [ 'key' => 'us_game_date', 'value' => $month_end,   'compare' => '<=' ],
    ];
    if ( $selected_league_id > 0 ) {
        $game_meta_query[] = [ 'key' => 'us_league_id', 'value' => $selected_league_id, 'compare' => '=' ];
    }

    $all_games = get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_key'    => 'us_game_date',
        'orderby'     => 'meta_value',
        'order'       => 'ASC',
        'meta_query'  => $game_meta_query,
    ] );

    // Filter to tournament leagues only
    $games = array_filter( $all_games, function( $game ) use ( $tournament_league_ids ) {
        $lid = get_post_meta( $game->ID, 'us_league_id', true );
        return in_array( (int) $lid, array_map( 'intval', $tournament_league_ids ) );
    } );
    $games = array_values( $games );

    // ── Build set of league IDs that have games in this range ──
    $leagues_with_games = [];
    foreach ( $games as $g ) {
        $lid = get_post_meta( $g->ID, 'us_league_id', true );
        if ( $lid ) $leagues_with_games[ $lid ] = true;
    }
    if ( $selected_league_id ) $leagues_with_games[ $selected_league_id ] = true;

    $all_umpires = us_get_active_umpires();

    ob_start();
    ?>
    <div class="us-dashboard">

        <div class="us-alloc-dashboard__header">
            <div>
                <h2>Tournament Management</h2>
                <p class="us-alloc-dashboard__date">Add, edit, remove and assign umpires to tournament games.</p>
            </div>
            <div class="us-alloc-games__header-actions">
                <button id="us-add-game-btn" class="us-btn us-alloc-games__add-btn">+ Add New Game</button>
                <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_allocator_dashboard' ) . '/' ) ); ?>"
                   class="us-btn us-btn-request us-btn--sm">&larr; Dashboard</a>
            </div>
        </div>

        <?php if ( empty( $tournament_league_ids ) ) : ?>
            <p class="us-empty">No tournament leagues found. Create a league and mark it as a tournament first.</p>
        <?php else : ?>

        <div class="us-alloc-games__tabs">
            <a href="<?php echo esc_url( add_query_arg( [ 'league_id' => '0', 'us_month' => $selected_month ], $base_url ) ); ?>"
               class="us-alloc-games__tab<?php echo $selected_league_id === 0 ? ' us-alloc-games__tab--active' : ''; ?>">All tournaments</a>
            <?php foreach ( $all_leagues as $l ) :
                if ( ! isset( $leagues_with_games[ $l->ID ] ) ) continue;
            ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'league_id' => $l->ID, 'us_month' => $selected_month ], $base_url ) ); ?>"
               class="us-alloc-games__tab<?php echo $l->ID === $selected_league_id ? ' us-alloc-games__tab--active' : ''; ?>">
                <?php echo esc_html( $l->post_title ); ?>
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
                <?php echo count( $games ); ?> game<?php echo count( $games ) !== 1 ? 's' : ''; ?>
                <?php echo $selected_month === $current_month ? 'remaining in' : 'in'; ?>
                <?php echo esc_html( $month_label ); ?>
            </span>
        </div>

        <?php if ( empty( $games ) ) : ?>
            <p class="us-empty">No tournament games found for <?php echo esc_html( $month_label ); ?>. Click "Add New Game" to get started.</p>
        <?php else : ?>

        <div class="us-mgmt-cards">
            <?php foreach ( $games as $game ) :
                echo us_render_mgmt_game_card( $game, $today, $selected_league_id, $all_umpires );
            endforeach; ?>
        </div>

        <p class="us-table-count">
            <?php echo count( $games ); ?> game<?php echo count( $games ) !== 1 ? 's' : ''; ?>
            <?php echo $selected_month === $current_month ? 'remaining in' : 'in'; ?>
            <?php echo esc_html( $month_label ); ?>
        </p>

        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php echo us_mgmt_game_modals( $all_leagues ); ?>
    <?php echo us_mgmt_game_js(); ?>
    <?php
    return ob_get_clean();
}