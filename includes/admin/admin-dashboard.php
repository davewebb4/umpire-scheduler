<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Admin dashboard page ──────────────────────────────────────
function us_dashboard_page() {
    $today    = current_time( 'Y-m-d' );
    $in14days = date( 'Y-m-d', strtotime( '+14 days' ) );

    // ── Pending requests ──────────────────────────────────────
    $request_count = us_get_pending_requests_count();

    // ── Game counts ───────────────────────────────────────────
    $total_games = count( get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'post_status' => 'publish',
        'fields'      => 'ids',
    ] ) );

    $remaining_count = count( get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'post_status' => 'publish',
        'fields'      => 'ids',
        'meta_query'  => [
            [ 'key' => 'us_game_date', 'value' => $today, 'compare' => '>=' ],
        ],
    ] ) );

    // ── Today's games ─────────────────────────────────────────
    $todays_games = get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_key'    => 'us_game_time',
        'orderby'     => 'meta_value',
        'order'       => 'ASC',
        'meta_query'  => [
            [ 'key' => 'us_game_date', 'value' => $today, 'compare' => '=' ],
        ],
    ] );

    // ── Unassigned games (next 14 days) ───────────────────────
    $upcoming_games = get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_key'    => 'us_game_date',
        'orderby'     => 'meta_value',
        'order'       => 'ASC',
        'meta_query'  => [
            [ 'key' => 'us_game_date', 'value' => $today,    'compare' => '>=' ],
            [ 'key' => 'us_game_date', 'value' => $in14days, 'compare' => '<=' ],
        ],
    ] );

    $unassigned_games = [];
    $unassigned_count = 0;

    foreach ( $upcoming_games as $game ) {
        if ( us_is_game_postponed( $game->ID ) ) continue;
        $two_umps = get_post_meta( $game->ID, 'us_two_umpires', true ) === '1';
        $plate    = us_get_assignment( $game->ID, 'plate' );
        $base     = us_get_assignment( $game->ID, 'base' );

        $open = [];
        if ( ! $plate ) $open[] = 'Plate';
        if ( $two_umps && ! $base ) $open[] = 'Base';

        if ( ! empty( $open ) ) {
            $unassigned_count++;
            $unassigned_games[] = [ 'game' => $game, 'open' => $open ];
        }
    }

    // ── Outstanding pay ───────────────────────────────────────
    $outstanding_assignments = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_status', 'value' => 'confirmed', 'compare' => '=' ],
        ],
    ] );

    $umpire_totals = [];
    foreach ( $outstanding_assignments as $a ) {
        $umpire_id = get_post_meta( $a->ID, 'us_umpire_id',  true );
        $game_id   = get_post_meta( $a->ID, 'us_game_id',    true );
        $pay       = floatval( get_post_meta( $a->ID, 'us_pay_amount', true ) );
        $game_date = get_post_meta( $game_id, 'us_game_date', true );
        $league_id = get_post_meta( $game_id, 'us_league_id', true );

        if ( $league_id && get_post_meta( $league_id, 'us_is_tournament', true ) === '1' ) continue;
        if ( $game_date > $today ) continue;

        $month_key = date( 'Y-m', strtotime( $game_date ) );
        $paid      = get_posts( [
            'post_type'   => US_PT_PAYMENT,
            'numberposts' => 1,
            'post_status' => 'publish',
            'fields'      => 'ids',
            'meta_query'  => [
                [ 'key' => 'payment_umpire_id', 'value' => $umpire_id, 'compare' => '=' ],
                [ 'key' => 'payment_month',     'value' => $month_key, 'compare' => '=' ],
            ],
        ] );
        if ( $paid ) continue;

        if ( ! isset( $umpire_totals[ $umpire_id ] ) ) {
            $umpire_totals[ $umpire_id ] = [
                'name'  => get_the_title( $umpire_id ),
                'games' => 0,
                'total' => 0,
            ];
        }
        $umpire_totals[ $umpire_id ]['games']++;
        $umpire_totals[ $umpire_id ]['total'] += $pay;
    }

    uasort( $umpire_totals, fn( $a, $b ) => $b['total'] <=> $a['total'] );
    $grand_total = array_sum( array_column( $umpire_totals, 'total' ) );

    // ── Recent activity ───────────────────────────────────────
    $recent_assignments = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => 10,
        'post_status' => 'publish',
        'orderby'     => 'date',
        'order'       => 'DESC',
    ] );
    ?>

    <div class="wrap us-admin-dashboard">
        <h1>Umpire Scheduler</h1>
        <p class="us-admin-date"><?php echo date( 'l, F j, Y', strtotime( $today ) ); ?></p>

        <?php if ( $request_count > 0 ) : ?>
        <div class="us-admin-alert">
            <span class="us-admin-alert__text">
                &#9888; <?php echo $request_count; ?> umpire<?php echo $request_count !== 1 ? 's have' : ' has'; ?> requested game<?php echo $request_count !== 1 ? 's' : ''; ?> waiting for your approval
            </span>
            <a href="<?php echo admin_url( 'admin.php?page=us-requests' ); ?>" class="button button-primary">
                Review requests &rarr;
            </a>
        </div>
        <?php endif; ?>

        <!-- ── Summary cards ───────────────────────────────── -->
        <div class="us-admin-cards">

            <div class="us-admin-card">
                <div class="us-admin-card__value"><?php echo $total_games; ?></div>
                <div class="us-admin-card__label">Total games this season</div>
            </div>

            <div class="us-admin-card">
                <div class="us-admin-card__value"><?php echo $remaining_count; ?></div>
                <div class="us-admin-card__label">Games remaining</div>
            </div>

            <a href="<?php echo admin_url( 'admin.php?page=us-requests' ); ?>" class="us-admin-card us-admin-card--link <?php echo $request_count > 0 ? 'us-admin-card--warning' : ''; ?>">
                <div class="us-admin-card__value <?php echo $request_count > 0 ? 'us-admin-card__value--warning' : ''; ?>"><?php echo $request_count; ?></div>
                <div class="us-admin-card__label">Pending requests</div>
            </a>

            <div class="us-admin-card <?php echo $unassigned_count > 0 ? 'us-admin-card--danger' : 'us-admin-card--success'; ?>">
                <div class="us-admin-card__value <?php echo $unassigned_count > 0 ? 'us-admin-card__value--danger' : 'us-admin-card__value--success'; ?>"><?php echo $unassigned_count; ?></div>
                <div class="us-admin-card__label">Unassigned slots (14 days)</div>
            </div>

            <a href="<?php echo admin_url( 'admin.php?page=us-pay-reports' ); ?>" class="us-admin-card us-admin-card--link us-admin-card--accent">
                <div class="us-admin-card__value us-admin-card__value--accent">$</div>
                <div class="us-admin-card__label">Pay Reports</div>
            </a>

        </div>

        <!-- ── Today's games ───────────────────────────────── -->
        <h2 class="us-admin-section-heading">
            Today's games
            <span class="us-admin-section-heading__sub"><?php echo date( 'F j, Y', strtotime( $today ) ); ?></span>
        </h2>

        <?php if ( empty( $todays_games ) ) : ?>
            <p class="us-admin-empty">No games scheduled for today.</p>
        <?php else : ?>
        <table class="wp-list-table widefat striped us-admin-table">
            <thead>
                <tr>
                    <th style="width:80px">Time</th>
                    <th>Game</th>
                    <th>Field</th>
                    <th>League</th>
                    <th>Plate</th>
                    <th>Base</th>
                    <th style="width:100px">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $todays_games as $game ) :
                    $time      = get_post_meta( $game->ID, 'us_game_time',   true );
                    $home      = get_post_meta( $game->ID, 'us_home_team',   true );
                    $away      = get_post_meta( $game->ID, 'us_away_team',   true );
                    $field     = get_post_meta( $game->ID, 'us_field',       true );
                    $league_id = get_post_meta( $game->ID, 'us_league_id',   true );
                    $two_umps  = get_post_meta( $game->ID, 'us_two_umpires', true ) === '1';
                    $league    = $league_id ? get_the_title( $league_id ) : '—';

                    $plate        = us_get_assignment( $game->ID, 'plate' );
                    $base         = us_get_assignment( $game->ID, 'base' );
                    $plate_id     = $plate ? get_post_meta( $plate->ID, 'us_umpire_id', true ) : 0;
                    $base_id      = $base  ? get_post_meta( $base->ID,  'us_umpire_id', true ) : 0;
                    $plate_status = $plate ? get_post_meta( $plate->ID, 'us_status',    true ) : '';
                    $base_status  = $base  ? get_post_meta( $base->ID,  'us_status',    true ) : '';
                    $plate_name   = $plate_id ? get_the_title( $plate_id ) : null;
                    $base_name    = $base_id  ? get_the_title( $base_id )  : null;

                    if ( ! $plate ) {
                        $status_class = 'us-admin-status--open';
                        $status_label = 'Open';
                    } elseif ( $plate_status === 'confirmed' && ( ! $two_umps || $base_status === 'confirmed' ) ) {
                        $status_class = 'us-admin-status--confirmed';
                        $status_label = 'Confirmed';
                    } else {
                        $status_class = 'us-admin-status--pending';
                        $status_label = 'Pending';
                    }
                ?>
                <tr>
                    <td><?php echo $time ? esc_html( date( 'g:i a', strtotime( $time ) ) ) : '—'; ?></td>
                    <td><?php echo esc_html( $away . ' at ' . $home ); ?></td>
                    <td><?php echo esc_html( $field ); ?></td>
                    <td><?php echo esc_html( $league ); ?></td>
                    <td>
                        <?php if ( $plate_name ) : ?>
                            <?php echo esc_html( $plate_name ); ?>
                            <?php if ( $plate_status === 'confirmed' ) : ?>
                                <span class="us-admin-check">&#10003;</span>
                            <?php endif; ?>
                        <?php else : ?>
                            <span class="us-admin-open">Open</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( ! $two_umps ) : ?>
                            <span class="us-admin-na">N/A</span>
                        <?php elseif ( $base_name ) : ?>
                            <?php echo esc_html( $base_name ); ?>
                            <?php if ( $base_status === 'confirmed' ) : ?>
                                <span class="us-admin-check">&#10003;</span>
                            <?php endif; ?>
                        <?php else : ?>
                            <span class="us-admin-open">Open</span>
                        <?php endif; ?>
                    </td>
                    <td class="us-admin-status <?php echo $status_class; ?>"><?php echo $status_label; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- ── Unassigned games ─────────────────────────────── -->
        <h2 class="us-admin-section-heading">
            Unassigned games — next 14 days
            <?php if ( $unassigned_count > 0 ) : ?>
                <span class="us-admin-badge us-admin-badge--danger"><?php echo $unassigned_count; ?></span>
            <?php endif; ?>
        </h2>

        <?php if ( empty( $unassigned_games ) ) : ?>
            <p class="us-admin-all-clear">&#10003; All games in the next 14 days are assigned.</p>
        <?php else : ?>
        <table class="wp-list-table widefat striped us-admin-table">
            <thead>
                <tr>
                    <th style="width:110px">Date</th>
                    <th style="width:80px">Time</th>
                    <th>Game</th>
                    <th>Field</th>
                    <th>League</th>
                    <th>Open slots</th>
                    <th style="width:80px">Assign</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $unassigned_games as $entry ) :
                    $game      = $entry['game'];
                    $date      = get_post_meta( $game->ID, 'us_game_date', true );
                    $time      = get_post_meta( $game->ID, 'us_game_time', true );
                    $home      = get_post_meta( $game->ID, 'us_home_team', true );
                    $away      = get_post_meta( $game->ID, 'us_away_team', true );
                    $field     = get_post_meta( $game->ID, 'us_field',     true );
                    $league_id = get_post_meta( $game->ID, 'us_league_id', true );
                    $league    = $league_id ? get_the_title( $league_id ) : '—';
                    $is_today  = $date === $today;
                ?>
                <tr <?php echo $is_today ? 'class="us-admin-row--today"' : ''; ?>>
                    <td class="<?php echo $is_today ? 'us-admin-cell--today' : ''; ?>">
                        <?php echo $date ? esc_html( date( 'M j, Y', strtotime( $date ) ) ) : '—'; ?>
                        <?php if ( $is_today ) : ?>
                            <span class="us-admin-today-badge">TODAY</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $time ? esc_html( date( 'g:i a', strtotime( $time ) ) ) : '—'; ?></td>
                    <td><?php echo esc_html( $away . ' at ' . $home ); ?></td>
                    <td><?php echo esc_html( $field ); ?></td>
                    <td><?php echo esc_html( $league ); ?></td>
                    <td>
                        <?php foreach ( $entry['open'] as $slot ) : ?>
                            <span class="us-admin-slot-badge"><?php echo esc_html( $slot ); ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?php if ( $league_id ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=us-league-games-' . $league_id . '&game_date=' . $date ) ); ?>"
                               class="button button-small">Assign</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- ── Outstanding pay ─────────────────────────────── -->
        <h2 class="us-admin-section-heading">
            Outstanding league pay
            <?php if ( $grand_total > 0 ) : ?>
                <span class="us-admin-section-heading__sub us-admin-section-heading__sub--danger">
                    $<?php echo number_format( $grand_total, 2 ); ?> total outstanding
                </span>
            <?php endif; ?>
            <a href="<?php echo admin_url( 'admin.php?page=us-pay-reports' ); ?>"
               class="us-admin-section-heading__link">
                View full reports &rarr;
            </a>
        </h2>

        <?php if ( empty( $umpire_totals ) ) : ?>
            <p class="us-admin-all-clear">&#10003; No outstanding league payments.</p>
        <?php else : ?>
        <table class="wp-list-table widefat striped us-admin-table">
            <thead>
                <tr>
                    <th>Umpire</th>
                    <th style="width:100px">Games</th>
                    <th style="width:130px">Outstanding</th>
                    <th style="width:100px">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $umpire_totals as $uid => $ut ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $ut['name'] ); ?></strong></td>
                    <td><?php echo $ut['games']; ?></td>
                    <td class="us-admin-status--open">$<?php echo number_format( $ut['total'], 2 ); ?></td>
                    <td>
                        <a href="<?php echo admin_url( 'admin.php?page=us-pay-reports' ); ?>"
                           class="button button-small">Pay</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- ── Recent activity ─────────────────────────────── -->
        <h2 class="us-admin-section-heading">Recent activity</h2>

        <?php if ( empty( $recent_assignments ) ) : ?>
            <p class="us-admin-empty">No recent activity.</p>
        <?php else : ?>
        <table class="wp-list-table widefat striped us-admin-table">
            <thead>
                <tr>
                    <th>Umpire</th>
                    <th>Game</th>
                    <th>League</th>
                    <th>Position</th>
                    <th>Status</th>
                    <th style="width:130px">Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $recent_assignments as $a ) :
                    $umpire_id = get_post_meta( $a->ID, 'us_umpire_id', true );
                    $game_id   = get_post_meta( $a->ID, 'us_game_id',   true );
                    $position  = get_post_meta( $a->ID, 'us_position',  true );
                    $status    = get_post_meta( $a->ID, 'us_status',    true );
                    $umpire    = $umpire_id ? get_the_title( $umpire_id ) : '—';
                    $home      = get_post_meta( $game_id, 'us_home_team', true );
                    $away      = get_post_meta( $game_id, 'us_away_team', true );
                    $date      = get_post_meta( $game_id, 'us_game_date', true );
                    $league_id = get_post_meta( $game_id, 'us_league_id', true );
                    $league    = $league_id ? get_the_title( $league_id ) : '—';

                    $status_class_map = [
                        'confirmed' => 'us-admin-status--confirmed',
                        'pending'   => 'us-admin-status--pending',
                        'requested' => 'us-admin-status--requested',
                        'declined'  => 'us-admin-status--open',
                        'no-show'   => 'us-admin-status--open',
                    ];
                    $status_class = $status_class_map[ $status ] ?? '';
                ?>
                <tr>
                    <td><?php echo esc_html( $umpire ); ?></td>
                    <td>
                        <?php echo esc_html( $away . ' at ' . $home ); ?>
                        <?php if ( $date ) : ?>
                            <span class="us-admin-date-sub"><?php echo esc_html( date( 'M j', strtotime( $date ) ) ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $league ); ?></td>
                    <td><?php echo esc_html( ucfirst( $position ) ); ?></td>
                    <td class="us-admin-status <?php echo $status_class; ?>"><?php echo esc_html( ucfirst( $status ) ); ?></td>
                    <td class="us-admin-date-sub"><?php echo esc_html( date( 'M j, Y g:i a', strtotime( $a->post_modified ) ) ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

    </div>

    <style>
        .us-admin-dashboard { max-width: 1200px; }
        .us-admin-date      { color: #666; margin-bottom: 24px; }
        .us-admin-empty     { color: #666; font-size: 14px; margin-bottom: 32px; }
        .us-admin-all-clear { color: #00a32a; font-size: 14px; font-weight: 600; margin-bottom: 32px; }

        .us-admin-alert {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-left: 4px solid #f59e0b;
            padding: 14px 18px;
            border-radius: 6px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }
        .us-admin-alert__text { font-size: 14px; font-weight: 600; color: #856404; }

        .us-admin-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        .us-admin-card {
            background: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            border-top: 3px solid #598cb9;
            text-align: center;
            text-decoration: none;
        }
        .us-admin-card--link    { display: block; }
        .us-admin-card--warning { border-top-color: #f59e0b; }
        .us-admin-card--danger  { border-top-color: #d63638; }
        .us-admin-card--success { border-top-color: #00a32a; }
        .us-admin-card--accent  { border-top-color: #1a7f3c; }

        .us-admin-card__value         { font-size: 32px; font-weight: 700; color: #091b33; }
        .us-admin-card__value--warning { color: #f59e0b; }
        .us-admin-card__value--danger  { color: #d63638; }
        .us-admin-card__value--success { color: #00a32a; }
        .us-admin-card__value--accent  { color: #1a7f3c; }
        .us-admin-card__label         { font-size: 13px; color: #666; margin-top: 4px; }

        .us-admin-section-heading {
            font-size: 16px;
            font-weight: 600;
            color: #091b33;
            margin: 0 0 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #598cb9;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .us-admin-section-heading__sub         { font-size: 13px; font-weight: 400; color: #666; }
        .us-admin-section-heading__sub--danger { color: #d63638; }
        .us-admin-section-heading__link        { font-size: 12px; font-weight: 400; color: #598cb9; text-decoration: none; margin-left: 4px; }

        .us-admin-badge         { font-size: 12px; padding: 2px 8px; border-radius: 10px; font-weight: 500; }
        .us-admin-badge--danger { background: #d63638; color: #fff; }

        .us-admin-status             { font-weight: 600; font-size: 13px; }
        .us-admin-status--confirmed  { color: #00a32a; }
        .us-admin-status--pending    { color: #dba617; }
        .us-admin-status--requested  { color: #0073aa; }
        .us-admin-status--open       { color: #d63638; }

        .us-admin-check { color: #00a32a; margin-left: 4px; }
        .us-admin-open  { color: #d63638; font-size: 12px; }
        .us-admin-na    { color: #999; font-size: 12px; }

        .us-admin-row--today    { background: #fff8e1; }
        .us-admin-cell--today   { font-weight: 600; }
        .us-admin-today-badge   { color: #f59e0b; font-size: 11px; margin-left: 4px; }
        .us-admin-slot-badge    { background: #fef2f2; color: #d63638; font-size: 11px; padding: 2px 6px; border-radius: 3px; margin-right: 4px; font-weight: 500; display: inline-block; }
        .us-admin-date-sub      { color: #999; font-size: 12px; margin-left: 4px; }
        .us-admin-table         { margin-bottom: 32px; }
    </style>
    <?php
}