<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Login redirect for allocators ─────────────────────────────
add_filter( 'login_redirect', 'us_allocator_login_redirect', 10, 3 );
function us_allocator_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
    if ( ! is_wp_error( $user ) && is_a( $user, 'WP_User' ) ) {
        $umpire = us_get_umpire_by_user( $user->ID );
        if ( $umpire && get_post_meta( $umpire->ID, 'us_is_assignor', true ) === '1' ) {
            return home_url( '/' . us_setting( 'slug_allocator_dashboard' ) . '/' );
        }
    }
    return $redirect_to;
}

// ── Shortcode ─────────────────────────────────────────────────
add_shortcode( 'allocator_dashboard', 'us_shortcode_allocator_dashboard' );
function us_shortcode_allocator_dashboard() {
    if ( ! is_user_logged_in() ) return us_login_form();

    if ( ! us_is_allocator() ) {
        return '<script>window.location="' . esc_url( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) ) . '";</script>';
    }

    $today        = current_time( 'Y-m-d' );
    $current_time = current_time( 'H:i' );
    $base_url     = home_url( '/' . us_setting( 'slug_allocator_dashboard' ) . '/' );
    $in7days      = date( 'Y-m-d', strtotime( $today . ' +7 days' ) );

    // ── Month pagination for requests ─────────────────────────
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

    // ── League filter for requests ────────────────────────────
    $selected_league_id = isset( $_GET['req_league'] ) ? absint( $_GET['req_league'] ) : 0;

    $all_leagues = us_get_active_leagues();

    // ── Today's games ─────────────────────────────────────────
    $todays_games_raw = get_posts( [
        'post_type'   => 'us_game',
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_key'    => 'us_game_time',
        'orderby'     => 'meta_value',
        'order'       => 'ASC',
        'meta_query'  => [
            [ 'key' => 'us_game_date', 'value' => $today, 'compare' => '=' ],
        ],
    ] );

    // Split into upcoming and past based on game time vs current time
    $todays_upcoming = [];
    $todays_past     = [];
    foreach ( $todays_games_raw as $game ) {
        $game_time = get_post_meta( $game->ID, 'us_game_time', true );
        if ( $game_time && $game_time < $current_time ) {
            $todays_past[] = $game;
        } else {
            $todays_upcoming[] = $game;
        }
    }

    // ── Pending requests — all (for badge count) ──────────────
    $all_pending = get_posts( [
        'post_type'   => 'us_assignment',
        'numberposts' => -1,
        'post_status' => 'publish',
        'fields'      => 'ids',
        'meta_query'  => [
            [ 'key' => 'us_status', 'value' => 'requested', 'compare' => '=' ],
        ],
    ] );
    $total_pending = count( $all_pending );

    // ── Pending requests — scoped to selected month + league ──
    $all_requested = get_posts( [
        'post_type'   => 'us_assignment',
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_status', 'value' => 'requested', 'compare' => '=' ],
        ],
    ] );

    $pending_requests = [];
    foreach ( $all_requested as $req ) {
        $game_id     = get_post_meta( $req->ID, 'us_game_id', true );
        $game_date   = get_post_meta( $game_id, 'us_game_date', true );
        $game_league = get_post_meta( $game_id, 'us_league_id', true );

        if ( $game_date < $month_start || $game_date > $month_end ) continue;
        if ( $selected_league_id && (int) $game_league !== $selected_league_id ) continue;

        $pending_requests[] = $req;
    }

    // ── Build set of league IDs that have requests in this month ──
    $leagues_with_requests = [];
    foreach ( $all_requested as $req ) {
        $game_id     = get_post_meta( $req->ID, 'us_game_id', true );
        $game_date   = get_post_meta( $game_id, 'us_game_date', true );
        $game_league = get_post_meta( $game_id, 'us_league_id', true );
        if ( $game_date < $month_start || $game_date > $month_end ) continue;
        if ( $game_league ) $leagues_with_requests[ $game_league ] = true;
    }
    // Always keep the currently selected league tab visible
    if ( $selected_league_id ) $leagues_with_requests[ $selected_league_id ] = true;

    // ── Group by game_id + position ───────────────────────────
    $grouped_requests = [];
    foreach ( $pending_requests as $req ) {
        $game_id  = get_post_meta( $req->ID, 'us_game_id',  true );
        $position = get_post_meta( $req->ID, 'us_position', true );
        $key      = $game_id . '_' . $position;
        if ( ! isset( $grouped_requests[ $key ] ) ) {
            $grouped_requests[ $key ] = [
                'game_id'  => $game_id,
                'position' => $position,
                'requests' => [],
            ];
        }
        $grouped_requests[ $key ]['requests'][] = $req;
    }

    // Sort by date then time
    uasort( $grouped_requests, function( $a, $b ) {
        $date_a = get_post_meta( $a['game_id'], 'us_game_date', true );
        $date_b = get_post_meta( $b['game_id'], 'us_game_date', true );
        if ( $date_a !== $date_b ) return strcmp( $date_a, $date_b );
        $time_a = get_post_meta( $a['game_id'], 'us_game_time', true );
        $time_b = get_post_meta( $b['game_id'], 'us_game_time', true );
        return strcmp( $time_a, $time_b );
    } );

    // ── Unassigned games next 7 days ──────────────────────────
    $upcoming_games = get_posts( [
        'post_type'   => 'us_game',
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_key'    => 'us_game_date',
        'orderby'     => 'meta_value',
        'order'       => 'ASC',
        'meta_query'  => [
            [ 'key' => 'us_game_date', 'value' => $today,   'compare' => '>=' ],
            [ 'key' => 'us_game_date', 'value' => $in7days, 'compare' => '<=' ],
        ],
    ] );

    $unassigned_games = [];
    foreach ( $upcoming_games as $game ) {
        if ( us_is_game_postponed( $game->ID ) ) continue;
        $two_umps = get_post_meta( $game->ID, 'us_two_umpires', true ) === '1';
        $plate    = us_get_confirmed_assignment( $game->ID, 'plate' );
        $base     = us_get_confirmed_assignment( $game->ID, 'base' );

        $open = [];
        if ( ! $plate ) $open[] = 'plate';
        if ( $two_umps && ! $base ) $open[] = 'base';

        if ( ! empty( $open ) ) {
            $unassigned_games[] = [ 'game' => $game, 'open' => $open ];
        }
    }

    // Sort by date then time
    usort( $unassigned_games, function( $a, $b ) {
        $date_a = get_post_meta( $a['game']->ID, 'us_game_date', true );
        $date_b = get_post_meta( $b['game']->ID, 'us_game_date', true );
        if ( $date_a !== $date_b ) return strcmp( $date_a, $date_b );
        $time_a = get_post_meta( $a['game']->ID, 'us_game_time', true );
        $time_b = get_post_meta( $b['game']->ID, 'us_game_time', true );
        return strcmp( $time_a, $time_b );
    } );

    $all_umpires = us_get_active_umpires();

    ob_start();
    ?>
    <div class="us-dashboard">

        <?php us_dashboard_notices(); ?>

        <div class="us-alloc-dashboard__header">
            <div>
                <h2>Allocator Dashboard</h2>
                <p class="us-alloc-dashboard__date">
                    <?php echo esc_html( date( 'l, F j, Y', strtotime( $today ) ) ); ?>
                </p>
            </div>
            <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) ); ?>"
               class="us-btn us-btn-request us-btn--sm">
                &#128100; My Umpire Dashboard
            </a>
        </div>

        <?php if ( isset( $_GET['alloc_notice'] ) ) : ?>
            <?php if ( $_GET['alloc_notice'] === 'approved' ) : ?>
                <div class="us-notice us-notice-success">Request approved — umpire confirmed and others notified.</div>
            <?php elseif ( $_GET['alloc_notice'] === 'denied' ) : ?>
                <div class="us-notice us-notice-info">Request denied — umpire has been notified.</div>
            <?php elseif ( $_GET['alloc_notice'] === 'assigned' ) : ?>
                <div class="us-notice us-notice-success">Umpire assigned and confirmed successfully.</div>
            <?php elseif ( $_GET['alloc_notice'] === 'postponed' ) : ?>
                <div class="us-notice us-notice-info">Game marked as postponed. Umpires have been notified.</div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- ── Section 1: Today's games ───────────────────── -->
        <h3 class="us-alloc-section__heading">
            Today's games
            <span class="us-alloc-section__count">
                <?php echo count( $todays_games_raw ); ?> game<?php echo count( $todays_games_raw ) !== 1 ? 's' : ''; ?>
            </span>
        </h3>

        <?php if ( empty( $todays_games_raw ) ) : ?>
            <p class="us-empty" style="margin-bottom:32px">No games scheduled for today.</p>
        <?php else : ?>

        <?php if ( empty( $todays_upcoming ) && ! empty( $todays_past ) ) : ?>
            <p class="us-empty" style="margin-bottom:12px">All of today's games have been played.</p>
        <?php endif; ?>

        <?php if ( ! empty( $todays_upcoming ) ) : ?>
        <div class="us-mgmt-cards" style="margin-bottom:<?php echo empty( $todays_past ) ? '32px' : '12px'; ?>">
            <?php
            $grouped_upcoming = us_group_doubleheaders( $todays_upcoming );
            foreach ( $grouped_upcoming as $entry ) :
                if ( $entry['type'] === 'doubleheader' ) {
                    echo us_alloc_today_dh_card( $entry['games'], $today );
                } else {
                    echo us_alloc_today_card( $entry['game'], $today );
                }
            endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $todays_past ) ) : ?>
        <div style="margin-bottom:32px">
            <button type="button" id="us-toggle-past-games" class="us-btn us-btn--muted us-btn--sm" style="margin-bottom:10px">
                &#x25BC; Show <?php echo count( $todays_past ); ?> completed game<?php echo count( $todays_past ) !== 1 ? 's' : ''; ?>
            </button>
            <div id="us-past-games" class="us-mgmt-cards" style="display:none">
                <?php
                $grouped_past = us_group_doubleheaders( $todays_past );
                foreach ( $grouped_past as $entry ) :
                    if ( $entry['type'] === 'doubleheader' ) {
                        echo us_alloc_today_dh_card( $entry['games'], $today );
                    } else {
                        echo us_alloc_today_card( $entry['game'], $today );
                    }
                endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>

        <!-- ── Section 2: Pending requests ────────────────── -->
        <h3 class="us-alloc-section__heading">
            Pending requests
            <?php if ( $total_pending > 0 ) : ?>
                <span class="us-alloc-section__badge us-alloc-section__badge--warning">
                    <?php echo $total_pending; ?>
                </span>
            <?php endif; ?>
        </h3>

        <!-- League tabs — only show leagues that have requests in this month -->
        <?php if ( ! empty( $all_leagues ) ) : ?>
        <div class="us-alloc-games__tabs">
            <a href="<?php echo esc_url( add_query_arg( [ 'req_league' => '0', 'us_month' => $selected_month ], $base_url ) ); ?>"
               class="us-alloc-games__tab<?php echo $selected_league_id === 0 ? ' us-alloc-games__tab--active' : ''; ?>">
                All leagues
            </a>
            <?php foreach ( $all_leagues as $l ) :
                if ( ! isset( $leagues_with_requests[ $l->ID ] ) ) continue;
                $is_tourney = get_post_meta( $l->ID, 'us_is_tournament', true ) === '1';
            ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'req_league' => $l->ID, 'us_month' => $selected_month ], $base_url ) ); ?>"
               class="us-alloc-games__tab<?php echo $selected_league_id === $l->ID ? ' us-alloc-games__tab--active' : ''; ?>">
                <?php echo esc_html( $l->post_title ); ?>
                <?php if ( $is_tourney ) : ?>
                    <span class="us-alloc-games__tab-tourney">TOURNEY</span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Month navigation -->
        <div class="us-alloc-games__month-nav">
            <a href="<?php echo esc_url( add_query_arg( [ 'req_league' => $selected_league_id, 'us_month' => $prev_month ], $base_url ) ); ?>"
               class="us-alloc-games__month-btn">
                <?php echo esc_html( date( 'M Y', strtotime( $prev_month . '-01' ) ) ); ?>
            </a>
            <span class="us-alloc-games__month-label"><?php echo esc_html( $month_label ); ?></span>
            <a href="<?php echo esc_url( add_query_arg( [ 'req_league' => $selected_league_id, 'us_month' => $next_month ], $base_url ) ); ?>"
               class="us-alloc-games__month-btn">
                <?php echo esc_html( date( 'M Y', strtotime( $next_month . '-01' ) ) ); ?>
            </a>
        </div>

        <?php if ( empty( $grouped_requests ) ) : ?>
            <p class="us-empty" style="margin-bottom:32px">No pending requests for <?php echo esc_html( $month_label ); ?>.</p>
        <?php else : ?>
        <div class="us-alloc-requests">
            <p class="us-alloc-requests__hint">
                <?php echo count( $pending_requests ); ?> pending request(s) in <?php echo esc_html( $month_label ); ?>.
                Confirming one umpire will automatically deny the others for that slot.
            </p>

            <?php foreach ( $grouped_requests as $group ) :
                $game_id   = $group['game_id'];
                $position  = $group['position'];
                $reqs      = $group['requests'];
                $date      = get_post_meta( $game_id, 'us_game_date', true );
                $time      = get_post_meta( $game_id, 'us_game_time', true );
                $home      = get_post_meta( $game_id, 'us_home_team', true );
                $away      = get_post_meta( $game_id, 'us_away_team', true );
                $field     = get_post_meta( $game_id, 'us_field',     true );
                $league_id = get_post_meta( $game_id, 'us_league_id', true );
                $league    = $league_id ? get_the_title( $league_id ) : '—';
                $date_obj  = $date ? new DateTime( $date ) : null;
                $req_count = count( $reqs );
            ?>
            <div class="us-req-card">

                <div class="us-req-card__header">

                    <?php if ( $date_obj ) : ?>
                    <div class="us-req-card__date">
                        <span class="us-req-card__date-day"><?php echo $date_obj->format( 'D' ); ?></span>
                        <span class="us-req-card__date-num"><?php echo $date_obj->format( 'M j' ); ?></span>
                        <span class="us-req-card__date-year"><?php echo $date_obj->format( 'Y' ); ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="us-req-card__info">
                        <div class="us-req-card__title"><?php echo esc_html( $away . ' vs ' . $home ); ?></div>
                        <div class="us-req-card__meta">
                            <span>&#9679; <?php echo $time ? date( 'g:i a', strtotime( $time ) ) : '—'; ?></span>
                            <span>&#9679; <?php echo esc_html( $field ); ?></span>
                            <span>&#9679; <?php echo esc_html( $league ); ?></span>
                            <span class="us-req-card__position-badge"><?php echo esc_html( ucfirst( $position ) ); ?></span>
                        </div>
                    </div>

                    <div class="us-req-card__count">
                        <?php echo $req_count; ?> request<?php echo $req_count !== 1 ? 's' : ''; ?>
                    </div>

                </div><!-- /.us-req-card__header -->

                <div class="us-req-card__umpires">
                    <?php foreach ( $reqs as $req ) :
                        $umpire_id    = get_post_meta( $req->ID, 'us_umpire_id',  true );
                        $pay          = get_post_meta( $req->ID, 'us_pay_amount', true );
                        $umpire_name  = $umpire_id ? get_the_title( $umpire_id ) : '—';
                        $has_conflict = $umpire_id ? us_umpire_has_conflict( $umpire_id, $game_id ) : false;

                        $req_nonce = wp_create_nonce( 'us_alloc_request_' . $req->ID );
                    ?>
                    <div class="us-req-card__umpire-row<?php echo $has_conflict ? ' us-req-card__umpire-row--conflict' : ''; ?>">

                        <div class="us-req-card__umpire-info">
                            <strong class="us-req-card__umpire-name"><?php echo esc_html( $umpire_name ); ?></strong>
                            <?php if ( $pay ) : ?>
                                <span class="us-req-card__pay">$<?php echo number_format( floatval( $pay ), 2 ); ?></span>
                            <?php endif; ?>
                            <?php if ( $has_conflict ) : ?>
                                <span class="us-req-card__conflict-badge">&#9888; Conflict</span>
                            <?php endif; ?>
                        </div>

                        <div class="us-req-card__umpire-actions">
                            <button type="button"
                                    class="us-req-btn us-req-btn--confirm us-req-approve-btn"
                                    data-assignment="<?php echo $req->ID; ?>"
                                    data-nonce="<?php echo esc_attr( $req_nonce ); ?>">
                                &#10003; Confirm
                            </button>
                            <button type="button"
                                    class="us-req-btn us-req-btn--deny us-req-deny-btn"
                                    data-assignment="<?php echo $req->ID; ?>"
                                    data-nonce="<?php echo esc_attr( $req_nonce ); ?>">
                                &#10005; Deny
                            </button>
                        </div>

                    </div><!-- /.us-req-card__umpire-row -->
                    <?php endforeach; ?>
                </div><!-- /.us-req-card__umpires -->

            </div><!-- /.us-req-card -->
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── Section 3: Unassigned games next 7 days ─────── -->
        <h3 class="us-alloc-section__heading">
            Unassigned games — next 7 days
            <?php if ( count( $unassigned_games ) > 0 ) : ?>
                <span class="us-alloc-section__badge us-alloc-section__badge--danger">
                    <?php echo count( $unassigned_games ); ?>
                </span>
            <?php endif; ?>
        </h3>

        <?php if ( empty( $unassigned_games ) ) : ?>
            <p class="us-empty us-alloc__all-assigned">&#10003; All games in the next 7 days are assigned.</p>
        <?php else : ?>
        <?php
        $unassigned_posts = [];
        foreach ( $unassigned_games as $e ) { $unassigned_posts[] = $e['game']; }
        $unassigned_map     = [];
        foreach ( $unassigned_games as $e ) { $unassigned_map[ $e['game']->ID ] = $e; }
        $grouped_unassigned = us_group_doubleheaders( $unassigned_posts );
        ?>
        <div class="us-mgmt-cards" style="margin-bottom:32px">
            <?php foreach ( $grouped_unassigned as $grouped_entry ) :
                if ( $grouped_entry['type'] === 'doubleheader' ) {
                    echo us_alloc_unassigned_dh_card( $grouped_entry['games'], $today, $all_umpires );
                    continue;
                }
                $entry = $unassigned_map[ $grouped_entry['game']->ID ];
                $game      = $entry['game'];
                $game_date = get_post_meta( $game->ID, 'us_game_date',     true );
                $game_time = get_post_meta( $game->ID, 'us_game_time',     true );
                $home      = get_post_meta( $game->ID, 'us_home_team',     true );
                $away      = get_post_meta( $game->ID, 'us_away_team',     true );
                $field     = get_post_meta( $game->ID, 'us_field',         true );
                $league_id = get_post_meta( $game->ID, 'us_league_id',     true );
                $two_umps  = get_post_meta( $game->ID, 'us_two_umpires',   true ) === '1';
                $is_dh     = get_post_meta( $game->ID, 'us_double_header', true ) === '1';
                $league    = $league_id ? get_the_title( $league_id ) : '';
                $is_today  = $game_date === $today;
                $date_obj  = $game_date ? new DateTime( $game_date ) : null;

                $plate_confirmed = us_get_confirmed_assignment( $game->ID, 'plate' );
                $base_confirmed  = us_get_confirmed_assignment( $game->ID, 'base' );
                $plate_uid       = $plate_confirmed ? get_post_meta( $plate_confirmed->ID, 'us_umpire_id', true ) : 0;
                $base_uid        = $base_confirmed  ? get_post_meta( $base_confirmed->ID,  'us_umpire_id', true ) : 0;

                $unavail_ids  = us_get_unavailable_umpires( $game_date );
                $assigned_ids = us_get_assigned_umpires_on_date( $game_date );
                $avail = []; $busy = [];
                foreach ( $all_umpires as $u ) {
                    if ( in_array( $u->ID, $unavail_ids ) ) continue;
                    if ( in_array( $u->ID, $assigned_ids ) ) { $busy[] = $u; } else { $avail[] = $u; }
                }

                $card_class = 'us-mgmt-card';
                if ( $is_today ) $card_class .= ' us-mgmt-card--today';
            ?>
            <div class="<?php echo $card_class; ?>">

                <div class="us-mgmt-card__header">

                    <?php if ( $date_obj ) : ?>
                    <div class="us-mgmt-card__date-pill<?php echo $is_today ? ' us-mgmt-card__date-pill--today' : ''; ?>">
                        <span class="us-mgmt-card__date-day"><?php echo $date_obj->format( 'D' ); ?></span>
                        <span class="us-mgmt-card__date-num"><?php echo $date_obj->format( 'M j' ); ?></span>
                        <span class="us-mgmt-card__date-year"><?php echo $date_obj->format( 'Y' ); ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="us-mgmt-card__info">
                        <div class="us-mgmt-card__matchup">
                            <?php echo esc_html( $away . ' vs ' . $home ); ?>
                            <?php if ( $two_umps ) : ?>
                                <span class="us-alloc-games__badge us-alloc-games__badge--two">2 umps</span>
                            <?php endif; ?>
                            <?php if ( $is_dh ) : ?>
                                <span class="us-alloc-games__badge us-alloc-games__badge--dh">DH</span>
                            <?php endif; ?>
                            <?php if ( $is_today ) : ?>
                                <span class="us-alloc__today-badge">TODAY</span>
                            <?php endif; ?>
                        </div>
                        <div class="us-mgmt-card__meta">
                            <?php if ( $game_time ) : ?>
                                <span class="us-mgmt-card__meta-time">&#9679; <?php echo esc_html( date( 'g:i a', strtotime( $game_time ) ) ); ?></span>
                            <?php endif; ?>
                            <?php if ( $field ) : ?>
                                <span>&#9679; <?php echo esc_html( $field ); ?></span>
                            <?php endif; ?>
                            <?php if ( $league ) : ?>
                                <span>&#9679; <?php echo esc_html( $league ); ?></span>
                            <?php endif; ?>
                            <span class="us-mgmt-card__status us-status-declined">Open</span>
                        </div>
                    </div>

                </div><!-- /.us-mgmt-card__header -->

                <div class="us-mgmt-card__umpires">
                    <div class="us-mgmt-card__slot">
                        <span class="us-mgmt-card__slot-label">Plate</span>
                        <?php if ( $plate_confirmed ) : ?>
                            <span class="us-status-confirmed">&#10003; <?php echo esc_html( get_the_title( $plate_uid ) ); ?></span>
                        <?php else : ?>
                            <?php echo us_allocator_assign_dropdown( $game->ID, 'plate', $avail, $busy ); ?>
                        <?php endif; ?>
                    </div>
                    <?php if ( $two_umps ) : ?>
                    <div class="us-mgmt-card__slot">
                        <span class="us-mgmt-card__slot-label">Base</span>
                        <?php if ( $base_confirmed ) : ?>
                            <span class="us-status-confirmed">&#10003; <?php echo esc_html( get_the_title( $base_uid ) ); ?></span>
                        <?php else : ?>
                            <?php echo us_allocator_assign_dropdown( $game->ID, 'base', $avail, $busy ); ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>

    <!-- Postpone modal -->
    <div id="us-alloc-postpone-modal" class="us-modal">
        <div class="us-modal__inner">
            <h3 class="us-modal__title">Mark game as postponed</h3>
            <p id="us-alloc-postpone-label" class="us-modal__sublabel"></p>
            <label class="us-alloc-postpone__pay-label">
                <input type="checkbox" id="us-alloc-postpone-pay" class="us-alloc-postpone__pay-check">
                <span>Pay assigned umpires — they arrived on site before the game was called off</span>
            </label>
            <p class="us-modal__hint">Umpires will be notified by email. Create a new game if rescheduling.</p>
            <div class="us-modal__actions">
                <button id="us-alloc-postpone-confirm" class="us-btn us-alloc__postpone-confirm-btn">
                    Confirm postponement
                </button>
                <button id="us-alloc-postpone-cancel" class="us-btn us-btn--muted">Cancel</button>
            </div>
        </div>
    </div>

    <?php
    // Pass past_count to the enqueued asset
    wp_add_inline_script(
        'us-alloc-dashboard-js',
        'usAllocDash.past_count = ' . intval( count( $todays_past ) ) . ';'
    );
    ?>
    <?php
    return ob_get_clean();
}

// ── Doubleheader card — today's games section ─────────────────
function us_alloc_today_dh_card( $games, $today ) {
    $game1     = $games[0];
    $home      = get_post_meta( $game1->ID, 'us_home_team', true );
    $away      = get_post_meta( $game1->ID, 'us_away_team', true );
    $field     = get_post_meta( $game1->ID, 'us_field',     true );
    $league_id = get_post_meta( $game1->ID, 'us_league_id', true );
    $league    = $league_id ? get_the_title( $league_id ) : '';
    $date_obj  = new DateTime( $today );

    $unavail_ids  = us_get_unavailable_umpires( $today );
    $assigned_ids = us_get_assigned_umpires_on_date( $today );
    $all_umpires  = us_get_active_umpires();
    $avail = []; $busy = [];
    foreach ( $all_umpires as $u ) {
        if ( in_array( $u->ID, $unavail_ids ) ) continue;
        if ( in_array( $u->ID, $assigned_ids ) ) { $busy[] = $u; } else { $avail[] = $u; }
    }

    ob_start();
    ?>
    <div class="us-mgmt-card us-mgmt-card--dh us-mgmt-card--today">

        <div class="us-mgmt-card__header">
            <div class="us-mgmt-card__date-pill us-mgmt-card__date-pill--today">
                <span class="us-mgmt-card__date-day"><?php echo $date_obj->format( 'D' ); ?></span>
                <span class="us-mgmt-card__date-num"><?php echo $date_obj->format( 'M j' ); ?></span>
                <span class="us-mgmt-card__date-year"><?php echo $date_obj->format( 'Y' ); ?></span>
            </div>
            <div class="us-mgmt-card__info">
                <div class="us-mgmt-card__matchup">
                    <?php echo esc_html( $away . ' vs ' . $home ); ?>
                    <span class="us-alloc-games__badge us-alloc-games__badge--dh">DH</span>
                    <span class="us-alloc__today-badge">TODAY</span>
                </div>
                <div class="us-mgmt-card__meta">
                    <?php if ( $field ) : ?>
                        <span>&#9679; <?php echo esc_html( $field ); ?></span>
                    <?php endif; ?>
                    <?php if ( $league ) : ?>
                        <span>&#9679; <?php echo esc_html( $league ); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php foreach ( $games as $g_num => $game ) :
            $time         = get_post_meta( $game->ID, 'us_game_time',     true );
            $two_umps     = get_post_meta( $game->ID, 'us_two_umpires',   true ) === '1';
            $is_dh_meta   = get_post_meta( $game->ID, 'us_double_header', true ) === '1';
            $is_postponed = us_is_game_postponed( $game->ID );

            $plate        = us_get_confirmed_assignment( $game->ID, 'plate' );
            $base         = us_get_confirmed_assignment( $game->ID, 'base' );
            $plate_id     = $plate ? get_post_meta( $plate->ID, 'us_umpire_id', true ) : 0;
            $base_id      = $base  ? get_post_meta( $base->ID,  'us_umpire_id', true ) : 0;
            $plate_status = $plate ? get_post_meta( $plate->ID, 'us_status',    true ) : '';
            $base_status  = $base  ? get_post_meta( $base->ID,  'us_status',    true ) : '';
            $plate_name   = $plate_id ? get_the_title( $plate_id ) : null;
            $base_name    = $base_id  ? get_the_title( $base_id )  : null;
            $plate_reqs   = $plate ? [] : us_get_slot_requests( $game->ID, 'plate' );
            $base_reqs    = ( $base || ! $two_umps ) ? [] : us_get_slot_requests( $game->ID, 'base' );

            if ( $is_postponed ) {
                $status_label = 'Postponed'; $status_class = 'us-alloc__status--postponed';
            } elseif ( $plate && ( ! $two_umps || $base ) ) {
                $status_label = 'Confirmed'; $status_class = 'us-status-confirmed';
            } elseif ( $plate && $two_umps && ! $base ) {
                $status_label = 'Partial';   $status_class = 'us-status-pending';
            } elseif ( ! empty( $plate_reqs ) || ! empty( $base_reqs ) ) {
                $status_label = 'Requested'; $status_class = 'us-status-pending';
            } else {
                $status_label = 'Open';      $status_class = 'us-status-declined';
            }

            $g_home = get_post_meta( $game->ID, 'us_home_team', true );
            $g_away = get_post_meta( $game->ID, 'us_away_team', true );
        ?>

        <?php if ( $g_num > 0 ) : ?>
            <div class="us-mgmt-card__dh-divider"></div>
        <?php endif; ?>

        <div class="us-mgmt-card__dh-game">
            <div class="us-mgmt-card__dh-game-header">
                <div class="us-mgmt-card__dh-game-meta">
                    <span class="us-mgmt-card__dh-label">Game <?php echo $g_num + 1; ?></span>
                    <?php if ( $time ) : ?>
                        <span class="us-mgmt-card__meta-time">&#9679; <?php echo esc_html( date( 'g:i a', strtotime( $time ) ) ); ?></span>
                    <?php endif; ?>
                    <?php if ( $is_dh_meta ) : ?>
                        <span class="us-alloc-games__badge us-alloc-games__badge--dh">DH rate</span>
                    <?php endif; ?>
                    <span class="us-mgmt-card__status <?php echo $status_class; ?>"><?php echo $status_label; ?></span>
                </div>
                <div class="us-mgmt-card__header-actions">
                    <?php if ( ! $is_postponed ) : ?>
                    <button type="button"
                            class="us-alloc-postpone-btn us-alloc__action-btn us-alloc__action-btn--postpone"
                            data-game="<?php echo $game->ID; ?>"
                            data-label="<?php echo esc_attr( $g_away . ' at ' . $g_home ); ?>">
                        Postpone
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="us-mgmt-card__umpires">
                <div class="us-mgmt-card__slot">
                    <span class="us-mgmt-card__slot-label">Plate</span>
                    <?php if ( $is_postponed ) : ?>
                        <?php echo $plate_name ? '<span class="us-status-confirmed">&#10003; ' . esc_html( $plate_name ) . '</span>' : '<span class="us-alloc__slot-na">&mdash;</span>'; ?>
                    <?php elseif ( $plate_name ) : ?>
                        <span class="us-status-confirmed">&#10003; <?php echo esc_html( $plate_name ); ?></span>
                        <?php if ( $plate_status === 'confirmed' && $plate->ID ) : ?>
                            <button class="us-alloc-noshow-btn us-alloc__noshow-btn" data-assignment="<?php echo $plate->ID; ?>">No-show</button>
                        <?php endif; ?>
                    <?php elseif ( ! empty( $plate_reqs ) ) : ?>
                        <span class="us-alloc__slot-requested">&#9679; <?php echo count( $plate_reqs ); ?> request<?php echo count( $plate_reqs ) > 1 ? 's' : ''; ?></span>
                        <?php echo us_allocator_assign_dropdown( $game->ID, 'plate', $avail, $busy ); ?>
                    <?php else : ?>
                        <?php echo us_allocator_assign_dropdown( $game->ID, 'plate', $avail, $busy ); ?>
                    <?php endif; ?>
                </div>
                <?php if ( $two_umps ) : ?>
                <div class="us-mgmt-card__slot">
                    <span class="us-mgmt-card__slot-label">Base</span>
                    <?php if ( $is_postponed ) : ?>
                        <?php echo $base_name ? '<span class="us-status-confirmed">&#10003; ' . esc_html( $base_name ) . '</span>' : '<span class="us-alloc__slot-na">&mdash;</span>'; ?>
                    <?php elseif ( $base_name ) : ?>
                        <span class="us-status-confirmed">&#10003; <?php echo esc_html( $base_name ); ?></span>
                        <?php if ( $base_status === 'confirmed' && $base->ID ) : ?>
                            <button class="us-alloc-noshow-btn us-alloc__noshow-btn" data-assignment="<?php echo $base->ID; ?>">No-show</button>
                        <?php endif; ?>
                    <?php elseif ( ! empty( $base_reqs ) ) : ?>
                        <span class="us-alloc__slot-requested">&#9679; <?php echo count( $base_reqs ); ?> request<?php echo count( $base_reqs ) > 1 ? 's' : ''; ?></span>
                        <?php echo us_allocator_assign_dropdown( $game->ID, 'base', $avail, $busy ); ?>
                    <?php else : ?>
                        <?php echo us_allocator_assign_dropdown( $game->ID, 'base', $avail, $busy ); ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

// ── Doubleheader card — unassigned games section ──────────────
function us_alloc_unassigned_dh_card( $games, $today, $all_umpires ) {
    $game1     = $games[0];
    $game_date = get_post_meta( $game1->ID, 'us_game_date', true );
    $home      = get_post_meta( $game1->ID, 'us_home_team', true );
    $away      = get_post_meta( $game1->ID, 'us_away_team', true );
    $field     = get_post_meta( $game1->ID, 'us_field',     true );
    $league_id = get_post_meta( $game1->ID, 'us_league_id', true );
    $league    = $league_id ? get_the_title( $league_id ) : '';
    $is_today  = $game_date === $today;
    $date_obj  = $game_date ? new DateTime( $game_date ) : null;

    $unavail_ids  = us_get_unavailable_umpires( $game_date );
    $assigned_ids = us_get_assigned_umpires_on_date( $game_date );
    $avail = []; $busy = [];
    foreach ( $all_umpires as $u ) {
        if ( in_array( $u->ID, $unavail_ids ) ) continue;
        if ( in_array( $u->ID, $assigned_ids ) ) { $busy[] = $u; } else { $avail[] = $u; }
    }

    $card_class = 'us-mgmt-card us-mgmt-card--dh';
    if ( $is_today ) $card_class .= ' us-mgmt-card--today';

    ob_start();
    ?>
    <div class="<?php echo $card_class; ?>">

        <div class="us-mgmt-card__header">
            <?php if ( $date_obj ) : ?>
            <div class="us-mgmt-card__date-pill<?php echo $is_today ? ' us-mgmt-card__date-pill--today' : ''; ?>">
                <span class="us-mgmt-card__date-day"><?php echo $date_obj->format( 'D' ); ?></span>
                <span class="us-mgmt-card__date-num"><?php echo $date_obj->format( 'M j' ); ?></span>
                <span class="us-mgmt-card__date-year"><?php echo $date_obj->format( 'Y' ); ?></span>
            </div>
            <?php endif; ?>
            <div class="us-mgmt-card__info">
                <div class="us-mgmt-card__matchup">
                    <?php echo esc_html( $away . ' vs ' . $home ); ?>
                    <span class="us-alloc-games__badge us-alloc-games__badge--dh">DH</span>
                    <?php if ( $is_today ) : ?>
                        <span class="us-alloc__today-badge">TODAY</span>
                    <?php endif; ?>
                </div>
                <div class="us-mgmt-card__meta">
                    <?php if ( $field ) : ?>
                        <span>&#9679; <?php echo esc_html( $field ); ?></span>
                    <?php endif; ?>
                    <?php if ( $league ) : ?>
                        <span>&#9679; <?php echo esc_html( $league ); ?></span>
                    <?php endif; ?>
                    <span class="us-mgmt-card__status us-status-declined">Open</span>
                </div>
            </div>
        </div>

        <?php foreach ( $games as $g_num => $game ) :
            $game_time  = get_post_meta( $game->ID, 'us_game_time',     true );
            $two_umps   = get_post_meta( $game->ID, 'us_two_umpires',   true ) === '1';
            $is_dh_meta = get_post_meta( $game->ID, 'us_double_header', true ) === '1';
            $plate_conf = us_get_confirmed_assignment( $game->ID, 'plate' );
            $base_conf  = us_get_confirmed_assignment( $game->ID, 'base' );
            $plate_uid  = $plate_conf ? get_post_meta( $plate_conf->ID, 'us_umpire_id', true ) : 0;
            $base_uid   = $base_conf  ? get_post_meta( $base_conf->ID,  'us_umpire_id', true ) : 0;
        ?>

        <?php if ( $g_num > 0 ) : ?>
            <div class="us-mgmt-card__dh-divider"></div>
        <?php endif; ?>

        <div class="us-mgmt-card__dh-game">
            <div class="us-mgmt-card__dh-game-header">
                <div class="us-mgmt-card__dh-game-meta">
                    <span class="us-mgmt-card__dh-label">Game <?php echo $g_num + 1; ?></span>
                    <?php if ( $game_time ) : ?>
                        <span class="us-mgmt-card__meta-time">&#9679; <?php echo esc_html( date( 'g:i a', strtotime( $game_time ) ) ); ?></span>
                    <?php endif; ?>
                    <?php if ( $is_dh_meta ) : ?>
                        <span class="us-alloc-games__badge us-alloc-games__badge--dh">DH rate</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="us-mgmt-card__umpires">
                <div class="us-mgmt-card__slot">
                    <span class="us-mgmt-card__slot-label">Plate</span>
                    <?php if ( $plate_conf ) : ?>
                        <span class="us-status-confirmed">&#10003; <?php echo esc_html( get_the_title( $plate_uid ) ); ?></span>
                    <?php else : ?>
                        <?php echo us_allocator_assign_dropdown( $game->ID, 'plate', $avail, $busy ); ?>
                    <?php endif; ?>
                </div>
                <?php if ( $two_umps ) : ?>
                <div class="us-mgmt-card__slot">
                    <span class="us-mgmt-card__slot-label">Base</span>
                    <?php if ( $base_conf ) : ?>
                        <span class="us-status-confirmed">&#10003; <?php echo esc_html( get_the_title( $base_uid ) ); ?></span>
                    <?php else : ?>
                        <?php echo us_allocator_assign_dropdown( $game->ID, 'base', $avail, $busy ); ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

// ── Today's game card helper ──────────────────────────────────
function us_alloc_today_card( $game, $today ) {
    $time         = get_post_meta( $game->ID, 'us_game_time',     true );
    $home         = get_post_meta( $game->ID, 'us_home_team',     true );
    $away         = get_post_meta( $game->ID, 'us_away_team',     true );
    $field        = get_post_meta( $game->ID, 'us_field',         true );
    $league_id    = get_post_meta( $game->ID, 'us_league_id',     true );
    $two_umps     = get_post_meta( $game->ID, 'us_two_umpires',   true ) === '1';
    $is_dh        = get_post_meta( $game->ID, 'us_double_header', true ) === '1';
    $league       = $league_id ? get_the_title( $league_id ) : '';
    $is_postponed = us_is_game_postponed( $game->ID );
    $date_obj     = new DateTime( $today );

    $plate        = us_get_confirmed_assignment( $game->ID, 'plate' );
    $base         = us_get_confirmed_assignment( $game->ID, 'base' );
    $plate_id     = $plate ? get_post_meta( $plate->ID, 'us_umpire_id', true ) : 0;
    $base_id      = $base  ? get_post_meta( $base->ID,  'us_umpire_id', true ) : 0;
    $plate_status = $plate ? get_post_meta( $plate->ID, 'us_status',    true ) : '';
    $base_status  = $base  ? get_post_meta( $base->ID,  'us_status',    true ) : '';
    $plate_name   = $plate_id ? get_the_title( $plate_id ) : null;
    $base_name    = $base_id  ? get_the_title( $base_id )  : null;

    $plate_reqs = $plate ? [] : us_get_slot_requests( $game->ID, 'plate' );
    $base_reqs  = ( $base || ! $two_umps ) ? [] : us_get_slot_requests( $game->ID, 'base' );

    $unavail_ids  = us_get_unavailable_umpires( $today );
    $assigned_ids = us_get_assigned_umpires_on_date( $today );
    $all_umpires  = us_get_active_umpires();
    $avail = []; $busy = [];
    foreach ( $all_umpires as $u ) {
        if ( in_array( $u->ID, $unavail_ids ) ) continue;
        if ( in_array( $u->ID, $assigned_ids ) ) { $busy[] = $u; } else { $avail[] = $u; }
    }

    if ( $is_postponed ) {
        $status_label = 'Postponed';
        $status_class = 'us-alloc__status--postponed';
    } elseif ( $plate && ( ! $two_umps || $base ) ) {
        $status_label = 'Confirmed';
        $status_class = 'us-status-confirmed';
    } elseif ( $plate && $two_umps && ! $base ) {
        $status_label = 'Partial';
        $status_class = 'us-status-pending';
    } elseif ( ! empty( $plate_reqs ) || ! empty( $base_reqs ) ) {
        $status_label = 'Requested';
        $status_class = 'us-status-pending';
    } else {
        $status_label = 'Open';
        $status_class = 'us-status-declined';
    }

    $card_class = 'us-mgmt-card us-mgmt-card--today';
    if ( $is_postponed ) $card_class .= ' us-mgmt-card--postponed';

    ob_start();
    ?>
    <div class="<?php echo $card_class; ?>">

        <!-- Header: date pill + game info + postpone button -->
        <div class="us-mgmt-card__header">

            <div class="us-mgmt-card__date-pill us-mgmt-card__date-pill--today">
                <span class="us-mgmt-card__date-day"><?php echo $date_obj->format( 'D' ); ?></span>
                <span class="us-mgmt-card__date-num"><?php echo $date_obj->format( 'M j' ); ?></span>
                <span class="us-mgmt-card__date-year"><?php echo $date_obj->format( 'Y' ); ?></span>
            </div>

            <div class="us-mgmt-card__info">
                <div class="us-mgmt-card__matchup">
                    <?php echo esc_html( $away . ' vs ' . $home ); ?>
                    <?php if ( $two_umps ) : ?>
                        <span class="us-alloc-games__badge us-alloc-games__badge--two">2 umps</span>
                    <?php endif; ?>
                    <?php if ( $is_dh ) : ?>
                        <span class="us-alloc-games__badge us-alloc-games__badge--dh">DH</span>
                    <?php endif; ?>
                    <?php if ( $is_postponed ) : ?>
                        <span class="us-alloc-games__badge us-alloc-games__badge--postponed">Postponed</span>
                    <?php endif; ?>
                    <span class="us-alloc__today-badge">TODAY</span>
                </div>
                <div class="us-mgmt-card__meta">
                    <?php if ( $time ) : ?>
                        <span class="us-mgmt-card__meta-time">&#9679; <?php echo esc_html( date( 'g:i a', strtotime( $time ) ) ); ?></span>
                    <?php endif; ?>
                    <?php if ( $field ) : ?>
                        <span>&#9679; <?php echo esc_html( $field ); ?></span>
                    <?php endif; ?>
                    <?php if ( $league ) : ?>
                        <span>&#9679; <?php echo esc_html( $league ); ?></span>
                    <?php endif; ?>
                    <span class="us-mgmt-card__status <?php echo $status_class; ?>"><?php echo $status_label; ?></span>
                </div>
            </div>

            <div class="us-mgmt-card__header-actions">
                <?php if ( ! $is_postponed ) : ?>
                    <button type="button"
                            class="us-alloc-postpone-btn us-alloc__action-btn us-alloc__action-btn--postpone"
                            data-game="<?php echo $game->ID; ?>"
                            data-label="<?php echo esc_attr( $away . ' at ' . $home ); ?>">
                        Postpone
                    </button>
                <?php endif; ?>
            </div>

        </div><!-- /.us-mgmt-card__header -->

        <!-- Umpire slots -->
        <div class="us-mgmt-card__umpires">
            <div class="us-mgmt-card__slot">
                <span class="us-mgmt-card__slot-label">Plate</span>
                <?php if ( $is_postponed ) : ?>
                    <?php echo $plate_name ? '<span class="us-status-confirmed">&#10003; ' . esc_html( $plate_name ) . '</span>' : '<span class="us-alloc__slot-na">&mdash;</span>'; ?>
                <?php elseif ( $plate_name ) : ?>
                    <span class="us-status-confirmed">&#10003; <?php echo esc_html( $plate_name ); ?></span>
                    <?php if ( $plate_status === 'confirmed' && $plate->ID ) : ?>
                        <button class="us-alloc-noshow-btn us-alloc__noshow-btn"
                                data-assignment="<?php echo $plate->ID; ?>">No-show</button>
                    <?php endif; ?>
                <?php elseif ( ! empty( $plate_reqs ) ) : ?>
                    <span class="us-alloc__slot-requested">&#9679; <?php echo count( $plate_reqs ); ?> request<?php echo count( $plate_reqs ) > 1 ? 's' : ''; ?></span>
                    <?php echo us_allocator_assign_dropdown( $game->ID, 'plate', $avail, $busy ); ?>
                <?php else : ?>
                    <?php echo us_allocator_assign_dropdown( $game->ID, 'plate', $avail, $busy ); ?>
                <?php endif; ?>
            </div>

            <?php if ( $two_umps ) : ?>
            <div class="us-mgmt-card__slot">
                <span class="us-mgmt-card__slot-label">Base</span>
                <?php if ( $is_postponed ) : ?>
                    <?php echo $base_name ? '<span class="us-status-confirmed">&#10003; ' . esc_html( $base_name ) . '</span>' : '<span class="us-alloc__slot-na">&mdash;</span>'; ?>
                <?php elseif ( $base_name ) : ?>
                    <span class="us-status-confirmed">&#10003; <?php echo esc_html( $base_name ); ?></span>
                    <?php if ( $base_status === 'confirmed' && $base->ID ) : ?>
                        <button class="us-alloc-noshow-btn us-alloc__noshow-btn"
                                data-assignment="<?php echo $base->ID; ?>">No-show</button>
                    <?php endif; ?>
                <?php elseif ( ! empty( $base_reqs ) ) : ?>
                    <span class="us-alloc__slot-requested">&#9679; <?php echo count( $base_reqs ); ?> request<?php echo count( $base_reqs ) > 1 ? 's' : ''; ?></span>
                    <?php echo us_allocator_assign_dropdown( $game->ID, 'base', $avail, $busy ); ?>
                <?php else : ?>
                    <?php echo us_allocator_assign_dropdown( $game->ID, 'base', $avail, $busy ); ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>
    <?php
    return ob_get_clean();
}

// ── Inline assign dropdown ────────────────────────────────────
function us_allocator_assign_dropdown( $game_id, $position, $available, $busy ) {
    $uid = $game_id . '_' . $position;
    ob_start();
    ?>
    <div style="display:flex;flex-direction:column;gap:6px">
        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
            <select id="sel_<?php echo $uid; ?>"
                    data-game="<?php echo $game_id; ?>"
                    data-position="<?php echo $position; ?>"
                    data-uid="<?php echo $uid; ?>"
                    style="font-size:12px;max-width:150px">
                <option value="">— select umpire —</option>
                <?php if ( ! empty( $available ) ) : ?>
                    <optgroup label="Available">
                        <?php foreach ( $available as $u ) : ?>
                            <option value="<?php echo $u->ID; ?>"><?php echo esc_html( $u->post_title ); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
                <?php if ( ! empty( $busy ) ) : ?>
                    <optgroup label="Already assigned today">
                        <?php foreach ( $busy as $u ) : ?>
                            <option value="<?php echo $u->ID; ?>" style="color:#999"><?php echo esc_html( $u->post_title ); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>
            <button id="btn_<?php echo $uid; ?>"
                    data-uid="<?php echo $uid; ?>"
                    data-game="<?php echo $game_id; ?>"
                    data-position="<?php echo $position; ?>"
                    class="us-alloc-assign-btn us-btn us-btn-confirm us-btn--sm"
                    disabled
                    style="opacity:0.4;cursor:not-allowed">
                Assign &amp; Confirm
            </button>
        </div>
        <span id="status_<?php echo $uid; ?>" class="us-alloc__slot-open" style="display:none"></span>
    </div>
    <?php
    return ob_get_clean();
}

// ── Handle approve / deny actions ─────────────────────────────
add_action( 'template_redirect', 'us_handle_allocator_actions' );
function us_handle_allocator_actions() {
    if ( ! is_user_logged_in() ) return;
    if ( ! us_is_allocator() ) return;
    if ( ! isset( $_GET['alloc_action'] ) ) return;

    $action        = sanitize_text_field( $_GET['alloc_action'] );
    $assignment_id = absint( $_GET['assignment_id'] ?? 0 );

    if ( ! $assignment_id || ! in_array( $action, [ 'approve', 'deny' ] ) ) return;

    if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'alloc_action_' . $assignment_id ) ) {
        wp_die( 'Security check failed.' );
    }

    $selected_month  = isset( $_GET['us_month'] )   ? sanitize_text_field( $_GET['us_month'] ) : current_time( 'Y-m' );
    $selected_league = isset( $_GET['req_league'] )  ? absint( $_GET['req_league'] )            : 0;
    $redirect_base   = add_query_arg( [
        'us_month'   => $selected_month,
        'req_league' => $selected_league,
    ], home_url( '/' . us_setting( 'slug_allocator_dashboard' ) . '/' ) );

    if ( $action === 'approve' ) {
        $game_id            = get_post_meta( $assignment_id, 'us_game_id',   true );
        $position           = get_post_meta( $assignment_id, 'us_position',  true );
        $approved_umpire_id = get_post_meta( $assignment_id, 'us_umpire_id', true );

        $siblings = get_posts( [
            'post_type'   => 'us_assignment',
            'numberposts' => -1,
            'post_status' => 'publish',
            'meta_query'  => [
                [ 'key' => 'us_game_id',  'value' => $game_id,  'compare' => '=' ],
                [ 'key' => 'us_position', 'value' => $position, 'compare' => '=' ],
                [ 'key' => 'us_status',   'value' => [ 'requested', 'pending' ], 'compare' => 'IN' ],
            ],
        ] );

        foreach ( $siblings as $s ) {
            if ( $s->ID === $assignment_id ) {
                update_post_meta( $s->ID, 'us_status', 'confirmed' );
                us_notify_umpire_confirmed( $s->ID );
            } else {
                $sibling_umpire_id = get_post_meta( $s->ID, 'us_umpire_id', true );
                if ( $sibling_umpire_id != $approved_umpire_id ) {
                    us_notify_umpire_denied( $s->ID );
                }
                wp_delete_post( $s->ID, true );
            }
        }

        wp_redirect( add_query_arg( 'alloc_notice', 'approved', $redirect_base ) );
        exit;
    }

    if ( $action === 'deny' ) {
        us_notify_umpire_denied( $assignment_id );
        wp_delete_post( $assignment_id, true );
        wp_redirect( add_query_arg( 'alloc_notice', 'denied', $redirect_base ) );
        exit;
    }
}

// ── AJAX: Approve pending request ────────────────────────────
add_action( 'wp_ajax_us_alloc_approve_request', 'us_ajax_alloc_approve_request' );
function us_ajax_alloc_approve_request() {
    if ( ! us_is_allocator() ) wp_send_json_error( 'Unauthorized' );

    $assignment_id = absint( $_POST['assignment_id'] ?? 0 );
    if ( ! $assignment_id ) wp_send_json_error( 'Invalid' );

    check_ajax_referer( 'us_alloc_request_' . $assignment_id, 'nonce' );

    $game_id            = get_post_meta( $assignment_id, 'us_game_id',   true );
    $position           = get_post_meta( $assignment_id, 'us_position',  true );
    $approved_umpire_id = get_post_meta( $assignment_id, 'us_umpire_id', true );

    $siblings = get_posts( [
        'post_type'   => 'us_assignment',
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_game_id',  'value' => $game_id,  'compare' => '=' ],
            [ 'key' => 'us_position', 'value' => $position, 'compare' => '=' ],
            [ 'key' => 'us_status',   'value' => [ 'requested', 'pending' ], 'compare' => 'IN' ],
        ],
    ] );

    foreach ( $siblings as $s ) {
        if ( $s->ID === $assignment_id ) {
            update_post_meta( $s->ID, 'us_status', 'confirmed' );
            us_notify_umpire_confirmed( $s->ID );
        } else {
            // Only send denied notification to a different umpire — duplicate records
            // for the approved umpire are silently removed to avoid a confirm+deny pair.
            $sibling_umpire_id = get_post_meta( $s->ID, 'us_umpire_id', true );
            if ( $sibling_umpire_id != $approved_umpire_id ) {
                us_notify_umpire_denied( $s->ID );
            }
            wp_delete_post( $s->ID, true );
        }
    }

    wp_send_json_success();
}

// ── AJAX: Deny pending request ────────────────────────────────
add_action( 'wp_ajax_us_alloc_deny_request', 'us_ajax_alloc_deny_request' );
function us_ajax_alloc_deny_request() {
    if ( ! us_is_allocator() ) wp_send_json_error( 'Unauthorized' );

    $assignment_id = absint( $_POST['assignment_id'] ?? 0 );
    if ( ! $assignment_id ) wp_send_json_error( 'Invalid' );

    check_ajax_referer( 'us_alloc_request_' . $assignment_id, 'nonce' );

    us_notify_umpire_denied( $assignment_id );
    wp_delete_post( $assignment_id, true );

    wp_send_json_success();
}

// ── Allocator assign umpire AJAX ──────────────────────────────
add_action( 'wp_ajax_us_allocator_assign_umpire', 'us_ajax_allocator_assign_umpire' );
function us_ajax_allocator_assign_umpire() {
    check_ajax_referer( 'us_assign_nonce', 'nonce' );

    if ( ! us_is_allocator() ) wp_send_json_error( 'Unauthorized' );

    $game_id   = absint( $_POST['game_id']   ?? 0 );
    $umpire_id = absint( $_POST['umpire_id'] ?? 0 );
    $position  = sanitize_text_field( $_POST['position'] ?? '' );

    if ( ! $game_id || ! $umpire_id || ! in_array( $position, [ 'plate', 'base' ] ) ) {
        wp_send_json_error( 'Invalid data' );
    }

    if ( $position === 'base' && get_post_meta( $game_id, 'us_two_umpires', true ) !== '1' ) {
        wp_send_json_error( 'Base umpire slot not enabled for this game' );
    }

    $existing = get_posts( [
        'post_type'   => 'us_assignment',
        'numberposts' => 1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_game_id',  'value' => $game_id,    'compare' => '=' ],
            [ 'key' => 'us_position', 'value' => $position,   'compare' => '=' ],
            [ 'key' => 'us_status',   'value' => 'confirmed', 'compare' => '=' ],
        ],
    ] );

    $pay_rate    = us_get_game_pay_rate( $game_id );
    $umpire_name = get_the_title( $umpire_id );
    $game_title  = get_the_title( $game_id );

    if ( $existing ) {
        $assignment_id = $existing[0]->ID;
        update_post_meta( $assignment_id, 'us_umpire_id',  $umpire_id );
        update_post_meta( $assignment_id, 'us_status',     'confirmed' );
        update_post_meta( $assignment_id, 'us_pay_amount', $pay_rate );
        wp_update_post( [ 'ID' => $assignment_id, 'post_title' => $game_title . ' (' . ucfirst( $position ) . ')' ] );
    } else {
        $assignment_id = wp_insert_post( [
            'post_type'   => 'us_assignment',
            'post_title'  => $game_title . ' (' . ucfirst( $position ) . ')',
            'post_status' => 'publish',
        ] );
        update_post_meta( $assignment_id, 'us_game_id',    $game_id );
        update_post_meta( $assignment_id, 'us_umpire_id',  $umpire_id );
        update_post_meta( $assignment_id, 'us_position',   $position );
        update_post_meta( $assignment_id, 'us_status',     'confirmed' );
        update_post_meta( $assignment_id, 'us_pay_amount', $pay_rate );
    }

    us_notify_umpire_assigned( $assignment_id );

    wp_send_json_success( [
        'status'        => 'confirmed',
        'label'         => $umpire_name,
        'assignment_id' => $assignment_id,
    ] );
}