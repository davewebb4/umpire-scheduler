<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── AJAX: request a game ──────────────────────────────────────
add_action( 'wp_ajax_us_request_game', 'us_ajax_request_game' );
function us_ajax_request_game() {
    $game_id  = absint( $_POST['game_id']  ?? 0 );
    $position = sanitize_text_field( $_POST['position'] ?? '' );
    $nonce    = sanitize_text_field( $_POST['nonce']    ?? '' );

    if ( ! $game_id || ! in_array( $position, [ 'plate', 'base' ], true ) ) {
        wp_send_json_error( 'Invalid request.' );
    }

    if ( ! wp_verify_nonce( $nonce, 'us_request_' . $game_id . '_' . $position ) ) {
        wp_send_json_error( 'Security check failed.' );
    }

    $umpire = us_get_umpire_by_user( get_current_user_id() );
    if ( ! $umpire ) {
        wp_send_json_error( 'No umpire profile found.' );
    }

    // Already requested / confirmed?
    $existing = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => 1,
        'post_status' => 'publish',
        'fields'      => 'ids',
        'meta_query'  => [
            [ 'key' => 'us_umpire_id', 'value' => $umpire->ID, 'compare' => '=' ],
            [ 'key' => 'us_game_id',   'value' => $game_id,    'compare' => '=' ],
            [ 'key' => 'us_position',  'value' => $position,   'compare' => '=' ],
            [ 'key' => 'us_status',    'value' => [ 'requested', 'pending', 'confirmed' ], 'compare' => 'IN' ],
        ],
    ] );
    if ( $existing ) {
        wp_send_json_error( 'You have already requested this slot.' );
    }

    $pay_rate      = us_get_game_pay_rate( $game_id );
    $assignment_id = wp_insert_post( [
        'post_type'   => US_PT_ASSIGNMENT,
        'post_status' => 'publish',
        'post_title'  => get_the_title( $game_id ) . ' (' . ucfirst( $position ) . ')',
    ] );

    if ( is_wp_error( $assignment_id ) ) {
        wp_send_json_error( 'Could not create request.' );
    }

    update_post_meta( $assignment_id, 'us_game_id',    $game_id );
    update_post_meta( $assignment_id, 'us_umpire_id',  $umpire->ID );
    update_post_meta( $assignment_id, 'us_position',   $position );
    update_post_meta( $assignment_id, 'us_status',     'requested' );
    update_post_meta( $assignment_id, 'us_pay_amount', $pay_rate );

    us_notify_assignor_requested( $assignment_id );

    wp_send_json_success( [ 'assignment_id' => $assignment_id ] );
}

// ── Open Games shortcode ──────────────────────────────────────
add_shortcode( 'umpire_open_games', 'us_shortcode_open_games' );
function us_shortcode_open_games() {
    if ( ! is_user_logged_in() ) return us_login_form();

    $umpire = us_get_umpire_by_user( get_current_user_id() );
    if ( ! $umpire ) return '<p class="us-empty">No umpire profile found.</p>';

    $umpire_id   = $umpire->ID;
    $today       = current_time( 'Y-m-d' );
    $unavail     = us_umpire_get_unavailable_dates( $umpire_id );
    $current_url = home_url( '/' . us_setting( 'slug_open_games' ) . '/' );

    // ── Month pagination ──────────────────────────────────────
    $current_month  = current_time( 'Y-m' );
    $selected_month = isset( $_GET['us_month'] ) ? sanitize_text_field( $_GET['us_month'] ) : $current_month;
    if ( ! preg_match( '/^\d{4}-\d{2}$/', $selected_month ) ) {
        $selected_month = $current_month;
    }
    $month_start = $selected_month . '-01';
    $month_end   = date( 'Y-m-t', strtotime( $month_start ) );
    $prev_month  = date( 'Y-m', strtotime( $month_start . ' -1 month' ) );
    $next_month  = date( 'Y-m', strtotime( $month_start . ' +1 month' ) );
    $month_label = date( 'F Y', strtotime( $month_start ) );

    $month_start_clamped = $month_start < $today ? $today : $month_start;

    // ── League filter ─────────────────────────────────────────
    $filter_league = isset( $_GET['open_league'] ) ? absint( $_GET['open_league'] ) : 0;

    $leagues = get_posts( [
        'post_type'   => US_PT_LEAGUE,
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'post_status' => 'publish',
    ] );

    // ── Game query ────────────────────────────────────────────
    $game_meta_query = [
        [ 'key' => 'us_game_date', 'value' => $month_start_clamped, 'compare' => '>=' ],
        [ 'key' => 'us_game_date', 'value' => $month_end,           'compare' => '<=' ],
    ];
    if ( $filter_league ) {
        $game_meta_query[] = [ 'key' => 'us_league_id', 'value' => $filter_league, 'compare' => '=' ];
    }

    $games = get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_key'    => 'us_game_date',
        'orderby'     => 'meta_value',
        'order'       => 'ASC',
        'meta_query'  => $game_meta_query,
    ] );

    $my_requests = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_umpire_id', 'value' => $umpire_id, 'compare' => '=' ],
            [ 'key' => 'us_status',    'value' => [ 'requested', 'pending' ], 'compare' => 'IN' ],
        ],
    ] );

    $my_pending = [];
    foreach ( $my_requests as $r ) {
        $g = get_post_meta( $r->ID, 'us_game_id',  true );
        $p = get_post_meta( $r->ID, 'us_position', true );
        $my_pending[ $g . '_' . $p ] = $r->ID;
    }

    // ── Build open games list ─────────────────────────────────
    $open_games = [];
    foreach ( $games as $game ) {
        if ( us_is_game_postponed( $game->ID ) ) continue;

        $league_id = get_post_meta( $game->ID, 'us_league_id', true );
        if ( $league_id && get_post_meta( $league_id, 'us_is_tournament', true ) === '1' ) continue;

        $game_date = get_post_meta( $game->ID, 'us_game_date', true );
        if ( in_array( $game_date, $unavail ) ) continue;

        $two_umps        = get_post_meta( $game->ID, 'us_two_umpires', true ) === '1';
        $plate_confirmed = us_get_confirmed_assignment( $game->ID, 'plate' );
        $base_confirmed  = us_get_confirmed_assignment( $game->ID, 'base' );

        $open_positions = [];
        if ( ! $plate_confirmed ) $open_positions[] = 'plate';
        if ( $two_umps && ! $base_confirmed ) $open_positions[] = 'base';

        if ( ! empty( $open_positions ) ) {
            $open_games[] = [
                'game'      => $game,
                'positions' => $open_positions,
                'date'      => $game_date,
                'time'      => get_post_meta( $game->ID, 'us_game_time', true ),
                'home'      => get_post_meta( $game->ID, 'us_home_team', true ),
                'away'      => get_post_meta( $game->ID, 'us_away_team', true ),
                'field'     => get_post_meta( $game->ID, 'us_field',     true ),
                'league_id' => $league_id,
                'two_umps'  => $two_umps,
            ];
        }
    }

    // ── Group doubleheaders ───────────────────────────────────
    $DOUBLEHEADER_GAP = 2 * 60 * 60;

    $normalize_team = function( $name ) {
        $pos = strpos( $name, ' - ' );
        return strtolower( trim( $pos !== false ? substr( $name, 0, $pos ) : $name ) );
    };

    $used    = [];
    $grouped = [];

    foreach ( $open_games as $i => $item ) {
        if ( isset( $used[ $i ] ) ) continue;

        $partner = null;

        foreach ( $open_games as $j => $other ) {
            if ( $j <= $i || isset( $used[ $j ] ) ) continue;
            if ( $item['date']  !== $other['date']  ) continue;
            if ( $item['field'] !== $other['field']  ) continue;
            if ( empty( $item['field'] ) )             continue;

            $teams_a = [ $normalize_team( $item['home'] ), $normalize_team( $item['away'] ) ];
            $teams_b = [ $normalize_team( $other['home'] ), $normalize_team( $other['away'] ) ];
            sort( $teams_a );
            sort( $teams_b );
            if ( $teams_a !== $teams_b ) continue;

            if ( $item['time'] && $other['time'] ) {
                $t1 = strtotime( $item['time'] );
                $t2 = strtotime( $other['time'] );
                if ( abs( $t2 - $t1 ) > $DOUBLEHEADER_GAP ) continue;
            }

            $partner = $j;
            break;
        }

        if ( $partner !== null ) {
            $t1 = $item['time']                    ? strtotime( $item['time'] )                   : 0;
            $t2 = $open_games[ $partner ]['time']  ? strtotime( $open_games[ $partner ]['time'] ) : 0;

            $first  = $t1 <= $t2 ? $item                   : $open_games[ $partner ];
            $second = $t1 <= $t2 ? $open_games[ $partner ] : $item;

            $grouped[] = [
                'type'  => 'doubleheader',
                'date'  => $item['date'],
                'games' => [ $first, $second ],
            ];
            $used[ $i ]       = true;
            $used[ $partner ] = true;
        } else {
            $grouped[] = [
                'type' => 'single',
                'date' => $item['date'],
                'item' => $item,
            ];
            $used[ $i ] = true;
        }
    }

    ob_start();
    ?>
    <div class="us-dashboard">
        <?php us_dashboard_notices(); ?>
        <h2>Open games</h2>

        <?php if ( count( $leagues ) > 1 ) : ?>
        <div class="us-alloc-games__tabs">
            <a href="<?php echo esc_url( add_query_arg( [ 'open_league' => '0', 'us_month' => $selected_month ], $current_url ) ); ?>"
               class="us-alloc-games__tab<?php echo ! $filter_league ? ' us-alloc-games__tab--active' : ''; ?>">
                All leagues
            </a>
            <?php foreach ( $leagues as $l ) :
                if ( get_post_meta( $l->ID, 'us_is_tournament', true ) === '1' ) continue;
            ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'open_league' => $l->ID, 'us_month' => $selected_month ], $current_url ) ); ?>"
               class="us-alloc-games__tab<?php echo $filter_league === $l->ID ? ' us-alloc-games__tab--active' : ''; ?>">
                <?php echo esc_html( $l->post_title ); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="us-alloc-games__month-nav">
            <a href="<?php echo esc_url( add_query_arg( [ 'open_league' => $filter_league, 'us_month' => $prev_month ], $current_url ) ); ?>"
               class="us-alloc-games__month-btn">
                <?php echo esc_html( date( 'M Y', strtotime( $prev_month . '-01' ) ) ); ?>
            </a>
            <span class="us-alloc-games__month-label"><?php echo esc_html( $month_label ); ?></span>
            <a href="<?php echo esc_url( add_query_arg( [ 'open_league' => $filter_league, 'us_month' => $next_month ], $current_url ) ); ?>"
               class="us-alloc-games__month-btn">
                <?php echo esc_html( date( 'M Y', strtotime( $next_month . '-01' ) ) ); ?>
            </a>
        </div>

        <p class="us-open-games__hint">
            Games with open slots you can request. The assignor will confirm your request.
            <?php if ( $filter_league ) :
                $filtered_league = get_post( $filter_league );
                echo ' Showing <strong>' . esc_html( $filtered_league->post_title ) . '</strong> only.';
            endif; ?>
        </p>

        <?php if ( empty( $grouped ) ) : ?>
            <p class="us-empty">
                <?php echo $filter_league
                    ? 'No open games in this league for ' . esc_html( $month_label ) . '.'
                    : 'No open games available for ' . esc_html( $month_label ) . '.'; ?>
            </p>
        <?php else : ?>

        <div class="us-open-game-list">
            <?php foreach ( $grouped as $entry ) :

                if ( $entry['type'] === 'doubleheader' ) :
                    $date     = $entry['date'];
                    $date_obj = $date ? new DateTime( $date ) : null;
            ?>

                <!-- ── Doubleheader card ── -->
                <div class="us-og-card us-og-card--dh">
                    <div class="us-og-card__header">
                        <?php if ( $date_obj ) : ?>
                        <div class="us-og-card__date">
                            <span class="us-og-card__date-day"><?php echo $date_obj->format( 'D' ); ?></span>
                            <span class="us-og-card__date-num"><?php echo $date_obj->format( 'M j' ); ?></span>
                            <span class="us-og-card__date-year"><?php echo $date_obj->format( 'Y' ); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="us-og-card__info">
                            <div class="us-og-card__title">
                                <?php
                                $first = $entry['games'][0];
                                echo esc_html( $first['away'] . ' vs ' . $first['home'] );
                                ?>
                                <span class="us-og-card__dh-badge">DH</span>
                            </div>
                            <div class="us-og-card__meta">
                                <?php if ( $first['field'] ) : ?>
                                    <span>&#9679; <?php echo esc_html( $first['field'] ); ?></span>
                                <?php endif; ?>
                                <?php if ( $first['league_id'] ) : ?>
                                    <span>&#9679; <?php echo esc_html( get_the_title( $first['league_id'] ) ); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php foreach ( $entry['games'] as $g_num => $dh_item ) :
                        $game     = $dh_item['game'];
                        $two_umps = $dh_item['two_umps'];
                    ?>
                    <?php if ( $g_num > 0 ) : ?>
                        <div class="us-og-card__divider"></div>
                    <?php endif; ?>
                    <div class="us-og-card__row">
                        <div class="us-og-card__row-info">
                            <span class="us-og-card__row-matchup">
                                <?php echo esc_html( $dh_item['away'] . ' vs ' . $dh_item['home'] ); ?>
                            </span>
                            <?php if ( $dh_item['time'] ) : ?>
                                <span class="us-og-card__row-time">
                                    &#9679; <?php echo esc_html( date( 'g:i a', strtotime( $dh_item['time'] ) ) ); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="us-og-card__actions">
                            <?php foreach ( $dh_item['positions'] as $pos ) :
                                $pending_key       = $game->ID . '_' . $pos;
                                $already_requested = isset( $my_pending[ $pending_key ] );
                                $cancel_id         = $already_requested ? $my_pending[ $pending_key ] : 0;
                                $btn_label         = ( $two_umps && count( $dh_item['positions'] ) > 1 )
                                    ? ucfirst( $pos ) . ' umpire'
                                    : 'Request';
                            ?>
                                <?php if ( $already_requested ) : ?>
                                    <span class="us-og-btn us-og-btn--pending">
                                        &#9679; <?php echo ucfirst( $pos ); ?> pending
                                    </span>
                                    <?php if ( $cancel_id ) : ?>
                                    <button class="us-og-btn us-og-btn--cancel us-cancel-request"
                                            data-assignment="<?php echo $cancel_id; ?>">
                                        Cancel
                                    </button>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <button class="us-og-btn us-og-btn--request us-request-game"
                                            data-game="<?php echo $game->ID; ?>"
                                            data-position="<?php echo esc_attr( $pos ); ?>"
                                            data-nonce="<?php echo wp_create_nonce( 'us_request_' . $game->ID . '_' . $pos ); ?>"
                                            data-label="<?php echo esc_attr( $btn_label ); ?>">
                                        <?php echo esc_html( $btn_label ); ?>
                                    </button>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            <?php else : // ── Single game card ── ?>

                <?php
                $item     = $entry['item'];
                $game     = $item['game'];
                $date     = $item['date'];
                $date_obj = $date ? new DateTime( $date ) : null;
                $two_umps = $item['two_umps'];
                $league   = $item['league_id'] ? get_the_title( $item['league_id'] ) : '';
                ?>
                <div class="us-og-card">
                    <div class="us-og-card__header">
                        <?php if ( $date_obj ) : ?>
                        <div class="us-og-card__date">
                            <span class="us-og-card__date-day"><?php echo $date_obj->format( 'D' ); ?></span>
                            <span class="us-og-card__date-num"><?php echo $date_obj->format( 'M j' ); ?></span>
                            <span class="us-og-card__date-year"><?php echo $date_obj->format( 'Y' ); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="us-og-card__info">
                            <div class="us-og-card__title">
                                <?php echo esc_html( $item['away'] . ' vs ' . $item['home'] ); ?>
                            </div>
                            <div class="us-og-card__meta">
                                <?php if ( $item['time'] ) : ?>
                                    <span class="us-og-card__meta-time">&#9679; <?php echo esc_html( date( 'g:i a', strtotime( $item['time'] ) ) ); ?></span>
                                <?php endif; ?>
                                <?php if ( $item['field'] ) : ?>
                                    <span>&#9679; <?php echo esc_html( $item['field'] ); ?></span>
                                <?php endif; ?>
                                <?php if ( $league ) : ?>
                                    <span>&#9679; <?php echo esc_html( $league ); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="us-og-card__header-actions">
                            <?php foreach ( $item['positions'] as $pos ) :
                                $pending_key       = $game->ID . '_' . $pos;
                                $already_requested = isset( $my_pending[ $pending_key ] );
                                $cancel_id         = $already_requested ? $my_pending[ $pending_key ] : 0;
                                $btn_label         = ( $two_umps && count( $item['positions'] ) > 1 )
                                    ? ucfirst( $pos ) . ' umpire'
                                    : 'Request';
                            ?>
                                <?php if ( $already_requested ) : ?>
                                    <span class="us-og-btn us-og-btn--pending">
                                        &#9679; <?php echo ucfirst( $pos ); ?> pending
                                    </span>
                                    <?php if ( $cancel_id ) : ?>
                                    <button class="us-og-btn us-og-btn--cancel us-cancel-request"
                                            data-assignment="<?php echo $cancel_id; ?>">
                                        Cancel
                                    </button>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <button class="us-og-btn us-og-btn--request us-request-game"
                                            data-game="<?php echo $game->ID; ?>"
                                            data-position="<?php echo esc_attr( $pos ); ?>"
                                            data-nonce="<?php echo wp_create_nonce( 'us_request_' . $game->ID . '_' . $pos ); ?>"
                                            data-label="<?php echo esc_attr( $btn_label ); ?>">
                                        <?php echo esc_html( $btn_label ); ?>
                                    </button>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

            <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <p class="us-table-count">
            <?php echo count( $open_games ); ?> open game<?php echo count( $open_games ) !== 1 ? 's' : ''; ?>
            <?php echo $filter_league ? 'in this league' : 'across all leagues'; ?>
            for <?php echo esc_html( $month_label ); ?>
        </p>

        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}