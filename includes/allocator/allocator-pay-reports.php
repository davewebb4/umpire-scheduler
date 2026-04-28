<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'allocator_pay_reports', 'us_shortcode_allocator_pay_reports' );
function us_shortcode_allocator_pay_reports() {
    if ( ! is_user_logged_in() ) return us_login_form();

    if ( ! us_is_allocator() ) {
        return '<script>window.location="' . esc_url( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) ) . '";</script>';
    }

    if ( isset( $_POST['us_mark_paid_submit'] ) )                           us_handle_mark_paid_front();
    if ( isset( $_GET['us_unmark_paid'] ) && isset( $_GET['payment_id'] ) ) us_handle_unmark_paid_front();

    $umpires = us_get_active_umpires();

    $all_leagues = get_posts( [
        'post_type'   => US_PT_LEAGUE,
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'post_status' => 'publish',
    ] );

    $regular_leagues    = array_values( array_filter( $all_leagues, fn( $l ) => get_post_meta( $l->ID, 'us_is_tournament', true ) !== '1' ) );
    $tournament_leagues = array_values( array_filter( $all_leagues, fn( $l ) => get_post_meta( $l->ID, 'us_is_tournament', true ) === '1' ) );

    $filter_league = isset( $_GET['filter_league'] ) ? absint( $_GET['filter_league'] ) : 0;
    $filter_umpire = isset( $_GET['filter_umpire'] ) ? absint( $_GET['filter_umpire'] ) : 0;
    $active_tab    = isset( $_GET['pay_tab'] ) ? sanitize_text_field( $_GET['pay_tab'] ) : 'leagues';
    $base_url      = home_url( '/' . us_setting( 'slug_allocator_pay_reports' ) . '/' );

    // ── League pay summaries ──────────────────────────────────
    $grand_outstanding = 0;
    $grand_paid        = 0;
    $umpire_summaries  = [];

    foreach ( $umpires as $umpire ) {
        if ( $filter_umpire && $filter_umpire !== $umpire->ID ) continue;
        $summary = us_admin_get_umpire_pay_summary( $umpire->ID, $filter_league );
        if ( empty( $summary['months'] ) ) continue;
        $grand_outstanding += $summary['total_outstanding'];
        $grand_paid        += $summary['total_paid'];
        $umpire_summaries[] = [ 'umpire' => $umpire, 'summary' => $summary ];
    }

    // ── Tournament pay summaries ──────────────────────────────
    $tourney_outstanding = 0;
    $tourney_paid_total  = 0;
    $tourney_summaries   = [];

    foreach ( $tournament_leagues as $tourney ) {
        $rows = us_get_tournament_pay_summary( $tourney->ID );
        if ( empty( $rows ) ) continue;

        $t_outstanding = 0;
        $t_paid        = 0;
        foreach ( $rows as $row ) {
            if ( $row['paid'] ) {
                $t_paid             += $row['earned'];
                $tourney_paid_total += $row['earned'];
            } else {
                $t_outstanding       += $row['earned'];
                $tourney_outstanding += $row['earned'];
            }
        }

        $tourney_summaries[] = [
            'tournament'  => $tourney,
            'umpire_rows' => $rows,
            'outstanding' => $t_outstanding,
            'paid'        => $t_paid,
        ];
    }

    // ── Admin fees — build per-league per-month breakdown ─────
    $admin_fee_data          = [];
    $grand_allocator_total   = 0;
    $grand_admin_total       = 0;
    $grand_fees_total        = 0;

    // Get all months with games for context
    foreach ( $regular_leagues as $league ) {
        $allocator_rate = floatval( get_post_meta( $league->ID, 'us_allocator_rate', true ) );
        $admin_rate     = floatval( get_post_meta( $league->ID, 'us_admin_rate',     true ) );

        if ( ! $allocator_rate && ! $admin_rate ) continue;

        // Get all confirmed assignments for this league grouped by month
        $games = get_posts( [
            'post_type'   => US_PT_GAME,
            'numberposts' => -1,
            'post_status' => 'publish',
            'meta_query'  => [
                [ 'key' => 'us_league_id', 'value' => $league->ID, 'compare' => '=' ],
            ],
        ] );

        $months = [];
        foreach ( $games as $game ) {
            $game_date = get_post_meta( $game->ID, 'us_game_date', true );
            if ( ! $game_date ) continue;

            // Count confirmed assignments (i.e. umpires who worked the game)
            $assignments = get_posts( [
                'post_type'   => US_PT_ASSIGNMENT,
                'numberposts' => -1,
                'post_status' => 'publish',
                'fields'      => 'ids',
                'meta_query'  => [
                    [ 'key' => 'us_game_id', 'value' => $game->ID,    'compare' => '=' ],
                    [ 'key' => 'us_status',  'value' => 'confirmed',   'compare' => '=' ],
                ],
            ] );

            $game_count = count( $assignments );
            if ( ! $game_count ) continue;

            $month_key   = date( 'Y-m', strtotime( $game_date ) );
            $month_label = date( 'F Y', strtotime( $game_date ) );

            if ( ! isset( $months[ $month_key ] ) ) {
                $months[ $month_key ] = [
                    'label'      => $month_label,
                    'games'      => 0,
                    'allocator'  => 0,
                    'admin'      => 0,
                    'total'      => 0,
                ];
            }

            $months[ $month_key ]['games']     += $game_count;
            $months[ $month_key ]['allocator'] += $allocator_rate * $game_count;
            $months[ $month_key ]['admin']     += $admin_rate     * $game_count;
            $months[ $month_key ]['total']     += ( $allocator_rate + $admin_rate ) * $game_count;
        }

        if ( empty( $months ) ) continue;

        ksort( $months );

        $league_allocator = array_sum( array_column( $months, 'allocator' ) );
        $league_admin     = array_sum( array_column( $months, 'admin' ) );
        $league_total     = array_sum( array_column( $months, 'total' ) );

        $grand_allocator_total += $league_allocator;
        $grand_admin_total     += $league_admin;
        $grand_fees_total      += $league_total;

        $admin_fee_data[] = [
            'league'           => $league,
            'allocator_rate'   => $allocator_rate,
            'admin_rate'       => $admin_rate,
            'months'           => $months,
            'league_allocator' => $league_allocator,
            'league_admin'     => $league_admin,
            'league_total'     => $league_total,
        ];
    }

    // ── Admin fees — tournament allocation breakdown ──────────
    $tourney_fee_data          = [];
    $grand_tourney_alloc_total = 0;
    $grand_tourney_admin_total = 0;
    $grand_tourney_fees_total  = 0;

    foreach ( $tournament_leagues as $league ) {
        $allocator_rate = floatval( get_post_meta( $league->ID, 'us_allocator_rate', true ) );
        $admin_rate     = floatval( get_post_meta( $league->ID, 'us_admin_rate',     true ) );

        if ( ! $allocator_rate && ! $admin_rate ) continue;

        $games = get_posts( [
            'post_type'   => US_PT_GAME,
            'numberposts' => -1,
            'post_status' => 'publish',
            'meta_query'  => [
                [ 'key' => 'us_league_id', 'value' => $league->ID, 'compare' => '=' ],
            ],
        ] );

        $months = [];
        foreach ( $games as $game ) {
            $game_date = get_post_meta( $game->ID, 'us_game_date', true );
            if ( ! $game_date ) continue;

            $assignments = get_posts( [
                'post_type'   => US_PT_ASSIGNMENT,
                'numberposts' => -1,
                'post_status' => 'publish',
                'fields'      => 'ids',
                'meta_query'  => [
                    [ 'key' => 'us_game_id', 'value' => $game->ID,   'compare' => '=' ],
                    [ 'key' => 'us_status',  'value' => 'confirmed',  'compare' => '=' ],
                ],
            ] );

            $game_count = count( $assignments );
            if ( ! $game_count ) continue;

            $month_key   = date( 'Y-m', strtotime( $game_date ) );
            $month_label = date( 'F Y', strtotime( $game_date ) );

            if ( ! isset( $months[ $month_key ] ) ) {
                $months[ $month_key ] = [
                    'label'     => $month_label,
                    'games'     => 0,
                    'allocator' => 0,
                    'admin'     => 0,
                    'total'     => 0,
                ];
            }

            $months[ $month_key ]['games']     += $game_count;
            $months[ $month_key ]['allocator'] += $allocator_rate * $game_count;
            $months[ $month_key ]['admin']     += $admin_rate     * $game_count;
            $months[ $month_key ]['total']     += ( $allocator_rate + $admin_rate ) * $game_count;
        }

        if ( empty( $months ) ) continue;

        ksort( $months );

        $league_allocator = array_sum( array_column( $months, 'allocator' ) );
        $league_admin     = array_sum( array_column( $months, 'admin' ) );
        $league_total     = array_sum( array_column( $months, 'total' ) );

        $grand_tourney_alloc_total += $league_allocator;
        $grand_tourney_admin_total += $league_admin;
        $grand_tourney_fees_total  += $league_total;

        $tourney_fee_data[] = [
            'league'           => $league,
            'allocator_rate'   => $allocator_rate,
            'admin_rate'       => $admin_rate,
            'months'           => $months,
            'league_allocator' => $league_allocator,
            'league_admin'     => $league_admin,
            'league_total'     => $league_total,
        ];
    }

    ob_start();
    ?>
    <div class="us-dashboard">

        <div class="us-alloc-dashboard__header">
            <h2>Pay Reports</h2>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <button id="us-export-xlsx-btn" class="us-btn us-btn-confirm us-btn--sm">
                    &#8681; Export to Excel
                </button>
                <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_allocator_dashboard' ) . '/' ) ); ?>"
                   class="us-btn us-btn-request us-btn--sm">&larr; Dashboard</a>
            </div>
        </div>

        <?php if ( isset( $_GET['us_pay_notice'] ) ) : ?>
            <?php if ( $_GET['us_pay_notice'] === 'paid' ) : ?>
                <div class="us-notice us-notice-success">Payment recorded successfully.</div>
            <?php elseif ( $_GET['us_pay_notice'] === 'unpaid' ) : ?>
                <div class="us-notice us-notice-info">Payment removed.</div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- ── Tabs ────────────────────────────────────────── -->
        <div class="us-alloc-pay__tabs">
            <a href="<?php echo esc_url( add_query_arg( 'pay_tab', 'leagues', $base_url ) ); ?>"
               class="us-alloc-pay__tab<?php echo $active_tab === 'leagues' ? ' us-alloc-pay__tab--active' : ''; ?>">
                League Pay
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'pay_tab', 'admin_fees', $base_url ) ); ?>"
               class="us-alloc-pay__tab<?php echo $active_tab === 'admin_fees' ? ' us-alloc-pay__tab--active' : ''; ?>">
                Admin Fees
            </a>
            <?php if ( ! empty( $tournament_leagues ) ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'pay_tab', 'tournaments', $base_url ) ); ?>"
               class="us-alloc-pay__tab<?php echo $active_tab === 'tournaments' ? ' us-alloc-pay__tab--active' : ''; ?>">
                Tournament Pay
            </a>
            <?php endif; ?>
        </div>

        <?php if ( $active_tab === 'leagues' ) : ?>

        <!-- ── Summary cards ───────────────────────────────── -->
        <div class="us-pay-cards">
            <div class="us-pay-card us-pay-card--danger">
                <div class="us-pay-card__value">$<?php echo number_format( $grand_outstanding, 2 ); ?></div>
                <div class="us-pay-card__label">Total outstanding</div>
            </div>
            <div class="us-pay-card us-pay-card--success">
                <div class="us-pay-card__value">$<?php echo number_format( $grand_paid, 2 ); ?></div>
                <div class="us-pay-card__label">Total paid this season</div>
            </div>
            <div class="us-pay-card us-pay-card--info">
                <div class="us-pay-card__value">$<?php echo number_format( $grand_outstanding + $grand_paid, 2 ); ?></div>
                <div class="us-pay-card__label">Total earned this season</div>
            </div>
        </div>

        <!-- ── Filters ─────────────────────────────────────── -->
        <div class="us-alloc-pay__filters">
            <form method="get" action="<?php echo esc_url( $base_url ); ?>">
                <input type="hidden" name="pay_tab" value="leagues">
                <select name="filter_umpire" class="us-alloc-pay__filter-select">
                    <option value="0">All umpires</option>
                    <?php foreach ( $umpires as $u ) : ?>
                        <option value="<?php echo $u->ID; ?>" <?php selected( $filter_umpire, $u->ID ); ?>>
                            <?php echo esc_html( $u->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="filter_league" class="us-alloc-pay__filter-select">
                    <option value="0">All leagues</option>
                    <?php foreach ( $regular_leagues as $l ) : ?>
                        <option value="<?php echo $l->ID; ?>" <?php selected( $filter_league, $l->ID ); ?>>
                            <?php echo esc_html( $l->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="us-btn us-btn-request us-btn--sm">Filter</button>
                <a href="<?php echo esc_url( add_query_arg( 'pay_tab', 'leagues', $base_url ) ); ?>"
                   class="us-btn us-btn--muted us-btn--sm">Reset</a>
            </form>
        </div>

        <?php if ( empty( $umpire_summaries ) ) : ?>
            <div class="us-notice us-notice-info">No pay data found for the selected filters.</div>
        <?php else : ?>

        <?php foreach ( $umpire_summaries as $entry ) :
            $umpire  = $entry['umpire'];
            $summary = $entry['summary'];
        ?>
        <div class="us-pay-block">
            <div class="us-pay-block__header">
                <div class="us-pay-block__header-left">
                    <span class="us-pay-block__name"><?php echo esc_html( $umpire->post_title ); ?></span>
                    <span class="us-pay-block__games"><?php echo $summary['total_games']; ?> games worked</span>
                </div>
                <div class="us-pay-block__header-right">
                    <span class="us-pay-block__outstanding">Outstanding: $<?php echo number_format( $summary['total_outstanding'], 2 ); ?></span>
                    <span class="us-pay-block__paid">Paid: $<?php echo number_format( $summary['total_paid'], 2 ); ?></span>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Games</th>
                        <th>Earned</th>
                        <th>Status</th>
                        <th>Date paid</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $summary['months'] as $month_key => $month ) :
                        $row_id = 'games-' . $umpire->ID . '-' . $month_key;
                    ?>
                    <tr class="us-pay-month-row">
                        <td data-label="Month">
                            <span class="us-pay-month-label"><?php echo esc_html( $month['label'] ); ?></span>
                            <?php if ( ! empty( $month['game_rows'] ) ) : ?>
                                <button type="button"
                                        class="us-pay-toggle-btn"
                                        data-target="<?php echo esc_attr( $row_id ); ?>"
                                        aria-expanded="false">
                                    &#9660; View games
                                </button>
                            <?php endif; ?>
                        </td>
                        <td data-label="Games"><?php echo $month['games']; ?></td>
                        <td data-label="Earned">$<?php echo number_format( $month['earned'], 2 ); ?></td>
                        <td data-label="Status">
                            <?php if ( $month['paid'] ) : ?>
                                <span class="us-status-confirmed">&#10003; Paid</span>
                            <?php else : ?>
                                <span class="us-status-declined">Outstanding</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Date paid">
                            <?php echo $month['paid'] ? esc_html( date( 'M j, Y', strtotime( $month['payment_date'] ) ) ) : '—'; ?>
                        </td>
                        <td data-label="Action">
                            <?php if ( $month['paid'] ) : ?>
                                <a href="<?php echo wp_nonce_url( add_query_arg( [
                                        'pay_tab'        => 'leagues',
                                        'us_unmark_paid' => '1',
                                        'payment_id'     => $month['payment_id'],
                                        'filter_league'  => $filter_league ?: '',
                                        'filter_umpire'  => $filter_umpire ?: '',
                                    ], $base_url ), 'us_unmark_paid_' . $month['payment_id'] ); ?>"
                                   class="us-alloc-pay__remove-link"
                                   onclick="return confirm('Remove this payment record?')">Remove</a>
                            <?php else : ?>
                                <button class="us-btn us-btn-confirm us-btn--sm us-mark-paid-btn"
                                        data-umpire="<?php echo $umpire->ID; ?>"
                                        data-month="<?php echo esc_attr( $month_key ); ?>"
                                        data-amount="<?php echo esc_attr( $month['earned'] ); ?>"
                                        data-label="<?php echo esc_attr( $umpire->post_title ); ?>"
                                        data-sublabel="<?php echo esc_attr( $month['label'] ); ?>"
                                        data-type="league">
                                    Mark as paid
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <?php if ( ! empty( $month['game_rows'] ) ) : ?>
                    <tr class="us-pay-games-row" id="<?php echo esc_attr( $row_id ); ?>" hidden>
                        <td colspan="6" class="us-pay-games-cell">
                            <?php foreach ( $month['game_rows'] as $game_row ) : ?>
                            <div class="us-pay-game-item">
                                <div class="us-pay-game-item__main">
                                    <span class="us-pay-game-item__teams">
                                        <?php echo esc_html( $game_row['away'] . ' at ' . $game_row['home'] ); ?>
                                    </span>
                                    <span class="us-pay-game-item__pay">
                                        $<?php echo number_format( $game_row['pay'], 2 ); ?>
                                    </span>
                                </div>
                                <div class="us-pay-game-item__meta">
                                    <span><?php echo $game_row['date'] ? esc_html( date( 'M j, Y', strtotime( $game_row['date'] ) ) ) : '—'; ?></span>
                                    <span><?php echo $game_row['time'] ? esc_html( date( 'g:i a', strtotime( $game_row['time'] ) ) ) : '—'; ?></span>
                                    <span><?php echo esc_html( $game_row['field'] ?: '—' ); ?></span>
                                    <span><?php echo esc_html( $game_row['league'] ); ?></span>
                                    <span><?php echo esc_html( ucfirst( $game_row['position'] ) ); ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php elseif ( $active_tab === 'admin_fees' ) : ?>

        <!-- ── Admin fees summary cards ────────────────────── -->
        <div class="us-pay-cards">
            <div class="us-pay-card us-pay-card--info">
                <div class="us-pay-card__value">$<?php echo number_format( $grand_allocator_total, 2 ); ?></div>
                <div class="us-pay-card__label">Total allocator fees</div>
            </div>
            <div class="us-pay-card us-pay-card--info">
                <div class="us-pay-card__value">$<?php echo number_format( $grand_admin_total, 2 ); ?></div>
                <div class="us-pay-card__label">Total administrative fees</div>
            </div>
            <div class="us-pay-card us-pay-card--danger">
                <div class="us-pay-card__value">$<?php echo number_format( $grand_fees_total, 2 ); ?></div>
                <div class="us-pay-card__label">Total fees this season</div>
            </div>
        </div>

        <?php if ( empty( $admin_fee_data ) ) : ?>
            <div class="us-notice us-notice-info">
                No admin fee rates set. Add an allocator or administrative fee rate to a league to see data here.
            </div>
        <?php else : ?>

        <?php foreach ( $admin_fee_data as $entry ) :
            $league = $entry['league'];
        ?>
        <div class="us-pay-block">
            <div class="us-pay-block__header">
                <div class="us-pay-block__header-left">
                    <span class="us-pay-block__name"><?php echo esc_html( $league->post_title ); ?></span>
                    <?php if ( $entry['allocator_rate'] ) : ?>
                        <span class="us-pay-block__games">Allocator: $<?php echo number_format( $entry['allocator_rate'], 2 ); ?>/game</span>
                    <?php endif; ?>
                    <?php if ( $entry['admin_rate'] ) : ?>
                        <span class="us-pay-block__games">Admin: $<?php echo number_format( $entry['admin_rate'], 2 ); ?>/game</span>
                    <?php endif; ?>
                </div>
                <div class="us-pay-block__header-right">
                    <span class="us-pay-block__paid">Season total: $<?php echo number_format( $entry['league_total'], 2 ); ?></span>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Games (umpire slots)</th>
                        <?php if ( $entry['allocator_rate'] ) : ?>
                            <th>Allocator fees</th>
                        <?php endif; ?>
                        <?php if ( $entry['admin_rate'] ) : ?>
                            <th>Admin fees</th>
                        <?php endif; ?>
                        <th>Month total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $entry['months'] as $month_key => $month ) : ?>
                    <tr>
                        <td data-label="Month"><?php echo esc_html( $month['label'] ); ?></td>
                        <td data-label="Games"><?php echo $month['games']; ?></td>
                        <?php if ( $entry['allocator_rate'] ) : ?>
                            <td data-label="Allocator fees">$<?php echo number_format( $month['allocator'], 2 ); ?></td>
                        <?php endif; ?>
                        <?php if ( $entry['admin_rate'] ) : ?>
                            <td data-label="Admin fees">$<?php echo number_format( $month['admin'], 2 ); ?></td>
                        <?php endif; ?>
                        <td data-label="Month total"><strong>$<?php echo number_format( $month['total'], 2 ); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- League total row -->
                    <tr style="background:#f8fafc;font-weight:600;border-top:2px solid #e0e0e0">
                        <td>Season total</td>
                        <td>—</td>
                        <?php if ( $entry['allocator_rate'] ) : ?>
                            <td>$<?php echo number_format( $entry['league_allocator'], 2 ); ?></td>
                        <?php endif; ?>
                        <?php if ( $entry['admin_rate'] ) : ?>
                            <td>$<?php echo number_format( $entry['league_admin'], 2 ); ?></td>
                        <?php endif; ?>
                        <td>$<?php echo number_format( $entry['league_total'], 2 ); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if ( ! empty( $tourney_fee_data ) ) : ?>

        <!-- ── Tournament allocation section ───────────────────── -->
        <h3 class="us-alloc-section__heading" style="margin-top:32px">
            Tournament Allocation
        </h3>

        <div class="us-pay-cards">
            <?php if ( $grand_tourney_alloc_total ) : ?>
            <div class="us-pay-card us-pay-card--info">
                <div class="us-pay-card__value">$<?php echo number_format( $grand_tourney_alloc_total, 2 ); ?></div>
                <div class="us-pay-card__label">Total allocator fees</div>
            </div>
            <?php endif; ?>
            <?php if ( $grand_tourney_admin_total ) : ?>
            <div class="us-pay-card us-pay-card--info">
                <div class="us-pay-card__value">$<?php echo number_format( $grand_tourney_admin_total, 2 ); ?></div>
                <div class="us-pay-card__label">Total administrative fees</div>
            </div>
            <?php endif; ?>
            <div class="us-pay-card us-pay-card--danger">
                <div class="us-pay-card__value">$<?php echo number_format( $grand_tourney_fees_total, 2 ); ?></div>
                <div class="us-pay-card__label">Total tournament fees</div>
            </div>
        </div>

        <?php foreach ( $tourney_fee_data as $entry ) :
            $league = $entry['league'];
        ?>
        <div class="us-pay-block">
            <div class="us-pay-block__header">
                <div class="us-pay-block__header-left">
                    <span class="us-pay-block__name"><?php echo esc_html( $league->post_title ); ?></span>
                    <span class="us-alloc-games__tab-tourney">TOURNEY</span>
                    <?php if ( $entry['allocator_rate'] ) : ?>
                        <span class="us-pay-block__games">Allocator: $<?php echo number_format( $entry['allocator_rate'], 2 ); ?>/game</span>
                    <?php endif; ?>
                    <?php if ( $entry['admin_rate'] ) : ?>
                        <span class="us-pay-block__games">Admin: $<?php echo number_format( $entry['admin_rate'], 2 ); ?>/game</span>
                    <?php endif; ?>
                </div>
                <div class="us-pay-block__header-right">
                    <span class="us-pay-block__paid">Season total: $<?php echo number_format( $entry['league_total'], 2 ); ?></span>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Games (umpire slots)</th>
                        <?php if ( $entry['allocator_rate'] ) : ?>
                            <th>Allocator fees</th>
                        <?php endif; ?>
                        <?php if ( $entry['admin_rate'] ) : ?>
                            <th>Admin fees</th>
                        <?php endif; ?>
                        <th>Month total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $entry['months'] as $month_key => $month ) : ?>
                    <tr>
                        <td data-label="Month"><?php echo esc_html( $month['label'] ); ?></td>
                        <td data-label="Games"><?php echo $month['games']; ?></td>
                        <?php if ( $entry['allocator_rate'] ) : ?>
                            <td data-label="Allocator fees">$<?php echo number_format( $month['allocator'], 2 ); ?></td>
                        <?php endif; ?>
                        <?php if ( $entry['admin_rate'] ) : ?>
                            <td data-label="Admin fees">$<?php echo number_format( $month['admin'], 2 ); ?></td>
                        <?php endif; ?>
                        <td data-label="Month total"><strong>$<?php echo number_format( $month['total'], 2 ); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="background:#f8fafc;font-weight:600;border-top:2px solid #e0e0e0">
                        <td>Season total</td>
                        <td>—</td>
                        <?php if ( $entry['allocator_rate'] ) : ?>
                            <td>$<?php echo number_format( $entry['league_allocator'], 2 ); ?></td>
                        <?php endif; ?>
                        <?php if ( $entry['admin_rate'] ) : ?>
                            <td>$<?php echo number_format( $entry['league_admin'], 2 ); ?></td>
                        <?php endif; ?>
                        <td>$<?php echo number_format( $entry['league_total'], 2 ); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>

        <?php endif; // tourney_fee_data ?>

        <?php elseif ( $active_tab === 'tournaments' ) : ?>

        <!-- ── Tournament summary cards ────────────────────── -->
        <div class="us-pay-cards">
            <div class="us-pay-card us-pay-card--danger">
                <div class="us-pay-card__value">$<?php echo number_format( $tourney_outstanding, 2 ); ?></div>
                <div class="us-pay-card__label">Total outstanding</div>
            </div>
            <div class="us-pay-card us-pay-card--success">
                <div class="us-pay-card__value">$<?php echo number_format( $tourney_paid_total, 2 ); ?></div>
                <div class="us-pay-card__label">Total paid</div>
            </div>
            <div class="us-pay-card us-pay-card--info">
                <div class="us-pay-card__value">$<?php echo number_format( $tourney_outstanding + $tourney_paid_total, 2 ); ?></div>
                <div class="us-pay-card__label">Total earned</div>
            </div>
        </div>

        <?php if ( empty( $tourney_summaries ) ) : ?>
            <div class="us-notice us-notice-info">No tournament pay data found.</div>
        <?php else : ?>

        <?php foreach ( $tourney_summaries as $entry ) :
            $tourney     = $entry['tournament'];
            $umpire_rows = $entry['umpire_rows'];
            $start       = get_post_meta( $tourney->ID, 'us_tourney_start', true );
            $end         = get_post_meta( $tourney->ID, 'us_tourney_end',   true );
            $date_range  = '';
            if ( $start && $end ) {
                $date_range = date( 'M j', strtotime( $start ) ) . ' – ' . date( 'M j, Y', strtotime( $end ) );
            } elseif ( $start ) {
                $date_range = date( 'M j, Y', strtotime( $start ) );
            }
        ?>
        <div class="us-pay-block">
            <div class="us-pay-block__header">
                <div class="us-pay-block__header-left">
                    <span class="us-pay-block__name"><?php echo esc_html( $tourney->post_title ); ?></span>
                    <?php if ( $date_range ) : ?>
                        <span class="us-pay-block__games">&#128197; <?php echo esc_html( $date_range ); ?></span>
                    <?php endif; ?>
                </div>
                <div class="us-pay-block__header-right">
                    <span class="us-pay-block__outstanding">Outstanding: $<?php echo number_format( $entry['outstanding'], 2 ); ?></span>
                    <span class="us-pay-block__paid">Paid: $<?php echo number_format( $entry['paid'], 2 ); ?></span>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Umpire</th>
                        <th>Games</th>
                        <th>Earned</th>
                        <th>Status</th>
                        <th>Date paid</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $umpire_rows as $row ) : ?>
                    <tr>
                        <td data-label="Umpire"><?php echo esc_html( $row['umpire_name'] ); ?></td>
                        <td data-label="Games"><?php echo $row['games']; ?></td>
                        <td data-label="Earned">$<?php echo number_format( $row['earned'], 2 ); ?></td>
                        <td data-label="Status">
                            <?php if ( $row['paid'] ) : ?>
                                <span class="us-status-confirmed">&#10003; Paid</span>
                            <?php else : ?>
                                <span class="us-status-declined">Outstanding</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Date paid">
                            <?php echo $row['paid'] && $row['paid_date'] ? esc_html( date( 'M j, Y', strtotime( $row['paid_date'] ) ) ) : '—'; ?>
                        </td>
                        <td data-label="Action">
                            <?php if ( $row['paid'] ) : ?>
                                <a href="<?php echo wp_nonce_url( add_query_arg( [
                                        'pay_tab'        => 'tournaments',
                                        'us_unmark_paid' => '1',
                                        'payment_id'     => $row['payment_id'],
                                    ], $base_url ), 'us_unmark_paid_' . $row['payment_id'] ); ?>"
                                   class="us-alloc-pay__remove-link"
                                   onclick="return confirm('Remove this payment record?')">Remove</a>
                            <?php else : ?>
                                <button class="us-btn us-btn-confirm us-btn--sm us-mark-paid-btn"
                                        data-umpire="<?php echo $row['umpire_id']; ?>"
                                        data-month="<?php echo esc_attr( 'tournament_' . $tourney->ID ); ?>"
                                        data-amount="<?php echo esc_attr( $row['earned'] ); ?>"
                                        data-label="<?php echo esc_attr( $row['umpire_name'] ); ?>"
                                        data-sublabel="<?php echo esc_attr( $tourney->post_title ); ?>"
                                        data-type="tournament"
                                        data-league="<?php echo $tourney->ID; ?>">
                                    Mark as paid
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php endif; // end tab ?>

    </div>

    <!-- ── Export to Excel modal ───────────────────────────── -->
    <div id="us-export-modal" class="us-modal">
        <div class="us-modal__inner">
            <h3 class="us-modal__title">Export Pay Report to Excel</h3>
            <p class="us-modal__sublabel">Select a month to export all umpire earnings broken down by league.</p>
            <table class="us-modal__table">
                <tr>
                    <th>Month</th>
                    <td>
                        <input type="month" id="us-export-month"
                               value="<?php echo current_time( 'Y-m' ); ?>"
                               style="padding:6px 10px;font-size:14px;border:1px solid #ddd;border-radius:4px">
                    </td>
                </tr>
            </table>
            <p id="us-export-status" style="font-size:13px;color:#666;margin:8px 0 0;min-height:20px"></p>
            <div class="us-modal__actions">
                <button id="us-export-confirm" class="us-btn us-btn-confirm">Download</button>
                <button id="us-export-cancel"  class="us-btn us-btn--muted">Cancel</button>
            </div>
        </div>
    </div>

    <!-- ── Mark as paid modal ──────────────────────────────── -->
    <div id="us-paid-modal" class="us-modal">
        <div class="us-modal__inner">
            <h3 class="us-modal__title">Mark as paid</h3>
            <p id="us-paid-modal-label" class="us-modal__label"></p>
            <p id="us-paid-modal-sublabel" class="us-modal__sublabel"></p>
            <form method="post" action="<?php echo esc_url( $base_url ); ?>">
                <?php wp_nonce_field( 'us_mark_paid', 'us_mark_paid_nonce' ); ?>
                <input type="hidden" name="us_paid_umpire_id" id="us-paid-umpire-id">
                <input type="hidden" name="us_paid_month"     id="us-paid-month">
                <input type="hidden" name="us_paid_amount"    id="us-paid-amount">
                <input type="hidden" name="us_paid_league"    id="us-paid-league" value="0">
                <input type="hidden" name="us_paid_type"      id="us-paid-type"   value="league">
                <input type="hidden" name="us_paid_tab"       id="us-paid-tab"    value="<?php echo esc_attr( $active_tab ); ?>">
                <table class="us-modal__table">
                    <tr>
                        <th>Amount</th>
                        <td><strong id="us-paid-amount-display"></strong></td>
                    </tr>
                    <tr>
                        <th>Date paid</th>
                        <td>
                            <input type="date" name="us_paid_date" id="us-paid-date"
                                   value="<?php echo current_time( 'Y-m-d' ); ?>" required>
                        </td>
                    </tr>
                </table>
                <div class="us-modal__actions">
                    <input type="submit" name="us_mark_paid_submit"
                           class="us-btn us-btn-confirm" value="Record payment">
                    <button type="button" id="us-paid-modal-cancel"
                            class="us-btn us-btn--muted">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <?php
    return ob_get_clean();
}

// ── Handle mark as paid (front end) ──────────────────────────
function us_handle_mark_paid_front() {
    if ( ! isset( $_POST['us_mark_paid_nonce'] ) || ! wp_verify_nonce( $_POST['us_mark_paid_nonce'], 'us_mark_paid' ) ) return;
    if ( ! us_is_allocator() ) return;

    $umpire_id = absint( $_POST['us_paid_umpire_id'] ?? 0 );
    $month     = sanitize_text_field( $_POST['us_paid_month']  ?? '' );
    $amount    = floatval( $_POST['us_paid_amount']            ?? 0 );
    $date      = sanitize_text_field( $_POST['us_paid_date']   ?? '' );
    $league_id = absint( $_POST['us_paid_league']              ?? 0 );
    $type      = sanitize_text_field( $_POST['us_paid_type']   ?? 'league' );
    $tab       = sanitize_text_field( $_POST['us_paid_tab']    ?? 'leagues' );

    if ( ! $umpire_id || ! $month || ! $date ) return;

    $umpire_name = get_the_title( $umpire_id );

    if ( $type === 'tournament' ) {
        $tourney_name = $league_id ? get_the_title( $league_id ) : 'Tournament';
        $post_id = wp_insert_post( [
            'post_type'   => US_PT_PAYMENT,
            'post_title'  => $umpire_name . ' — ' . $tourney_name,
            'post_status' => 'publish',
        ] );
        update_post_meta( $post_id, 'payment_umpire_id', $umpire_id );
        update_post_meta( $post_id, 'payment_league_id', $league_id );
        update_post_meta( $post_id, 'payment_amount',    $amount );
        update_post_meta( $post_id, 'payment_date',      $date );
        us_notify_umpire_paid_tournament( $umpire_id, $tourney_name, $amount, $date );
    } else {
        $post_id = wp_insert_post( [
            'post_type'   => US_PT_PAYMENT,
            'post_title'  => $umpire_name . ' — ' . $month,
            'post_status' => 'publish',
        ] );
        update_post_meta( $post_id, 'us_payment_umpire_id', $umpire_id );
        update_post_meta( $post_id, 'us_payment_month',     $month );
        update_post_meta( $post_id, 'us_payment_amount',    $amount );
        update_post_meta( $post_id, 'us_payment_date',      $date );
        update_post_meta( $post_id, 'us_payment_league_id', $league_id );
        us_notify_umpire_paid( $umpire_id, $month, $amount, $date );
    }

    $base_url = home_url( '/' . us_setting( 'slug_allocator_pay_reports' ) . '/' );
    $redirect = add_query_arg( [
        'pay_tab'       => $tab,
        'us_pay_notice' => 'paid',
    ], $base_url );

    wp_redirect( $redirect );
    exit;
}

// ── Handle unmark paid (front end) ────────────────────────────
function us_handle_unmark_paid_front() {
    $payment_id = absint( $_GET['payment_id'] ?? 0 );
    if ( ! $payment_id ) return;
    if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'us_unmark_paid_' . $payment_id ) ) return;
    if ( ! us_is_allocator() ) return;

    wp_delete_post( $payment_id, true );

    $base_url = home_url( '/' . us_setting( 'slug_allocator_pay_reports' ) . '/' );
    $tab      = sanitize_text_field( $_GET['pay_tab'] ?? 'leagues' );
    $redirect = add_query_arg( [
        'pay_tab'       => $tab,
        'us_pay_notice' => 'unpaid',
    ], $base_url );

    if ( isset( $_GET['filter_umpire'] ) ) $redirect = add_query_arg( 'filter_umpire', absint( $_GET['filter_umpire'] ), $redirect );
    if ( isset( $_GET['filter_league'] ) ) $redirect = add_query_arg( 'filter_league', absint( $_GET['filter_league'] ), $redirect );

    wp_redirect( $redirect );
    exit;
}