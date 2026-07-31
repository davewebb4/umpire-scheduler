<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'league_schedule', 'us_shortcode_league_schedule' );
function us_shortcode_league_schedule() {

    $leagues = array_values( us_get_active_leagues( false, true ) );

    if ( empty( $leagues ) ) {
        return '<p class="us-empty">No leagues found.</p>';
    }

    $selected_league_id  = isset( $_GET['league_id'] ) ? absint( $_GET['league_id'] ) : $leagues[0]->ID;
    $today               = current_time( 'Y-m-d' );
    $prev_league_id      = isset( $_GET['prev_league_id'] ) ? absint( $_GET['prev_league_id'] ) : 0;
    $league_just_changed = $prev_league_id && $prev_league_id !== $selected_league_id;

    $valid_ids = array_map( fn( $l ) => $l->ID, $leagues );
    if ( ! in_array( $selected_league_id, $valid_ids ) ) {
        $selected_league_id = $leagues[0]->ID;
    }

    $all_games = get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'meta_key'    => 'us_game_date',
        'orderby'     => 'meta_value',
        'order'       => 'ASC',
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_league_id', 'value' => $selected_league_id, 'compare' => '=' ],
        ],
    ] );

    // Build game dates — exclude dates where ALL games are postponed
    $game_dates = [];
    foreach ( $all_games as $g ) {
        $d = get_post_meta( $g->ID, 'us_game_date', true );
        if ( $d ) $game_dates[ $d ] = $d;
    }
    ksort( $game_dates );

    foreach ( $game_dates as $d ) {
        $games_on_date = array_filter( $all_games, fn( $g ) =>
            get_post_meta( $g->ID, 'us_game_date', true ) === $d
        );
        $all_postponed = ! empty( $games_on_date ) && array_reduce(
            $games_on_date,
            fn( $carry, $g ) => $carry && ( us_is_game_postponed( $g->ID ) || us_is_game_cancelled( $g->ID ) ),
            true
        );
        if ( $all_postponed ) unset( $game_dates[ $d ] );
    }

    $filter_date = ( isset( $_GET['game_date'] ) && ! $league_just_changed )
        ? sanitize_text_field( $_GET['game_date'] )
        : '';

    if ( ! $filter_date && ! empty( $game_dates ) ) {
        foreach ( $game_dates as $d ) {
            if ( $d >= $today ) { $filter_date = $d; break; }
        }
        if ( ! $filter_date ) $filter_date = end( $game_dates );
    }

    $games = [];
    if ( $filter_date ) {
        $all_date_games = get_posts( [
            'post_type'   => US_PT_GAME,
            'numberposts' => -1,
            'meta_key'    => 'us_game_time',
            'orderby'     => 'meta_value',
            'order'       => 'ASC',
            'post_status' => 'publish',
            'meta_query'  => [
                [ 'key' => 'us_league_id', 'value' => $selected_league_id, 'compare' => '=' ],
                [ 'key' => 'us_game_date', 'value' => $filter_date,        'compare' => '=' ],
            ],
        ] );

        // Filter out postponed and cancelled games from public view
        $games = array_values( array_filter( $all_date_games, fn( $g ) => ! us_is_game_postponed( $g->ID ) && ! us_is_game_cancelled( $g->ID ) ) );
    }

    $base_url    = home_url( '/' . us_setting( 'slug_league_schedule' ) . '/' );
    $dates_list  = array_values( $game_dates );
    $current_idx = array_search( $filter_date, $dates_list );
    $prev_date   = $current_idx > 0 ? $dates_list[ $current_idx - 1 ] : null;
    $next_date   = $current_idx !== false && $current_idx < count( $dates_list ) - 1
        ? $dates_list[ $current_idx + 1 ]
        : null;

    ob_start();
    ?>
    <div class="us-dashboard">
        <h2>League Schedule</h2>

        <?php if ( count( $leagues ) > 1 ) : ?>
        <div class="us-schedule-league-select">
            <form method="get" action="<?php echo esc_url( $base_url ); ?>">
                <label for="league_id" class="us-schedule__filter-label">League:</label>
                <select name="league_id" id="league_id"
                        class="us-schedule__filter-select"
                        onchange="document.getElementById('league_date_field').value='';this.form.submit();">
                    <?php foreach ( $leagues as $l ) : ?>
                        <option value="<?php echo $l->ID; ?>" <?php selected( $selected_league_id, $l->ID ); ?>>
                            <?php echo esc_html( $l->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ( $filter_date ) : ?>
                    <input type="hidden" name="game_date" id="league_date_field" value="<?php echo esc_attr( $filter_date ); ?>">
                <?php endif; ?>
            </form>
        </div>
        <?php else : ?>
            <p class="us-schedule__league-title"><?php echo esc_html( $leagues[0]->post_title ); ?></p>
        <?php endif; ?>

        <?php if ( empty( $game_dates ) ) : ?>
            <p class="us-empty">No games scheduled yet.</p>
        <?php else : ?>

        <div class="us-schedule-nav">
            <?php if ( $prev_date ) : ?>
                <a href="<?php echo esc_url( add_query_arg( [ 'league_id' => $selected_league_id, 'game_date' => $prev_date ], $base_url ) ); ?>"
                   class="us-btn us-btn-request">&larr;</a>
            <?php else : ?>
                <button class="us-btn us-btn-request us-btn--disabled" disabled>&larr;</button>
            <?php endif; ?>

            <select onchange="window.location=this.value" class="us-schedule-date-select">
                <?php foreach ( $game_dates as $d ) :
                    $url   = add_query_arg( [ 'league_id' => $selected_league_id, 'game_date' => $d ], $base_url );
                    $count = 0;
                    foreach ( $all_games as $g ) {
                        if ( get_post_meta( $g->ID, 'us_game_date', true ) === $d
                             && ! us_is_game_postponed( $g->ID )
                             && ! us_is_game_cancelled( $g->ID ) ) $count++;
                    }
                ?>
                    <option value="<?php echo esc_url( $url ); ?>" <?php selected( $filter_date, $d ); ?>>
                        <?php echo esc_html( date( 'l, F j, Y', strtotime( $d ) ) . ' (' . $count . ' games)' ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ( $next_date ) : ?>
                <a href="<?php echo esc_url( add_query_arg( [ 'league_id' => $selected_league_id, 'game_date' => $next_date ], $base_url ) ); ?>"
                   class="us-btn us-btn-request">&rarr;</a>
            <?php else : ?>
                <button class="us-btn us-btn-request us-btn--disabled" disabled>&rarr;</button>
            <?php endif; ?>
        </div>

        <?php if ( empty( $games ) ) : ?>
            <p class="us-empty us-empty--spaced">No games on this date.</p>
        <?php else : ?>

        <table>
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Game</th>
                    <th>Field</th>
                    <th>Plate umpire</th>
                    <th>Base umpire</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $games as $game ) :
                    $time     = get_post_meta( $game->ID, 'us_game_time',   true );
                    $home     = get_post_meta( $game->ID, 'us_home_team',   true );
                    $away     = get_post_meta( $game->ID, 'us_away_team',   true );
                    $field    = get_post_meta( $game->ID, 'us_field',       true );
                    $two_umps = get_post_meta( $game->ID, 'us_two_umpires', true ) === '1';

                    $plate = us_get_confirmed_assignment( $game->ID, 'plate' );
                    $base  = us_get_confirmed_assignment( $game->ID, 'base' );

                    $plate_umpire_id = $plate ? get_post_meta( $plate->ID, 'us_umpire_id', true ) : 0;
                    $base_umpire_id  = $base  ? get_post_meta( $base->ID,  'us_umpire_id', true ) : 0;
                    $plate_name      = $plate_umpire_id ? get_the_title( $plate_umpire_id ) : null;
                    $base_name       = $base_umpire_id  ? get_the_title( $base_umpire_id )  : null;

                    $plate_pending = ! $plate && ! empty( us_get_slot_requests( $game->ID, 'plate' ) );
                    $base_pending  = $two_umps && ! $base && ! empty( us_get_slot_requests( $game->ID, 'base' ) );

                    if ( $plate && ( ! $two_umps || $base ) ) {
                        $status_label = 'Confirmed';
                        $status_class = 'us-status-confirmed';
                    } elseif ( $plate && $two_umps && ! $base ) {
                        $status_label = 'Partial';
                        $status_class = 'us-status-pending';
                    } elseif ( $plate_pending || $base_pending ) {
                        $status_label = 'Pending';
                        $status_class = 'us-status-pending';
                    } else {
                        $status_label = 'Open';
                        $status_class = 'us-status-declined';
                    }
                ?>
                <tr>
                    <td data-label="Time"><?php echo $time ? esc_html( date( 'g:i a', strtotime( $time ) ) ) : '—'; ?></td>
                    <td data-label="Game"><?php echo esc_html( $away . ' at ' . $home ); ?></td>
                    <td data-label="Field"><?php echo esc_html( $field ); ?></td>
                    <td data-label="Plate umpire">
                        <?php if ( $plate_name ) : ?>
                            <span><?php echo esc_html( $plate_name ); ?></span>
                            <span class="us-status-confirmed us-assignment-tick">&#10003;</span>
                        <?php elseif ( $plate_pending ) : ?>
                            <span class="us-slot-pending">Pending</span>
                        <?php else : ?>
                            <span class="us-slot-open">Open</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Base umpire">
                        <?php if ( ! $two_umps ) : ?>
                            <span class="us-slot-na">N/A</span>
                        <?php elseif ( $base_name ) : ?>
                            <span><?php echo esc_html( $base_name ); ?></span>
                            <span class="us-status-confirmed us-assignment-tick">&#10003;</span>
                        <?php elseif ( $base_pending ) : ?>
                            <span class="us-slot-pending">Pending</span>
                        <?php else : ?>
                            <span class="us-slot-open">Open</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Status" class="<?php echo $status_class; ?>"><?php echo $status_label; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="us-schedule__game-count">
            <?php echo count( $games ); ?> game<?php echo count( $games ) !== 1 ? 's' : ''; ?>
            on <?php echo esc_html( date( 'l, F j, Y', strtotime( $filter_date ) ) ); ?>
        </p>

        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}