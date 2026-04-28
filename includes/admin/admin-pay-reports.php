<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Permission helper ─────────────────────────────────────────
function us_can_manage_pay() {
    return current_user_can( 'manage_options' ) || current_user_can( US_CAP_MANAGE_PAY );
}

// ── Pay reports page ──────────────────────────────────────────
function us_pay_reports_page() {
    if ( ! us_can_manage_pay() ) wp_die( 'You do not have permission to access this page.' );

    if ( isset( $_POST['us_mark_paid_submit'] ) )                          us_handle_mark_paid();
    if ( isset( $_GET['us_unmark_paid'] ) && isset( $_GET['payment_id'] ) ) us_handle_unmark_paid();

    $umpires = get_posts( [
        'post_type'   => US_PT_UMPIRE,
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_active', 'value' => '1', 'compare' => '=' ],
        ],
    ] );

    $filter_league = isset( $_GET['filter_league'] ) ? absint( $_GET['filter_league'] ) : 0;
    $filter_umpire = isset( $_GET['filter_umpire'] ) ? absint( $_GET['filter_umpire'] ) : 0;
    $active_tab    = isset( $_GET['pay_tab'] ) ? sanitize_text_field( $_GET['pay_tab'] ) : 'leagues';
    $base_url      = admin_url( 'admin.php?page=us-pay-reports' );

    $all_leagues = get_posts( [
        'post_type'   => US_PT_LEAGUE,
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'post_status' => 'publish',
    ] );

    $regular_leagues    = array_values( array_filter( $all_leagues, fn($l) => get_post_meta( $l->ID, 'us_is_tournament', true ) !== '1' ) );
    $tournament_leagues = array_values( array_filter( $all_leagues, fn($l) => get_post_meta( $l->ID, 'us_is_tournament', true ) === '1' ) );

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
    ?>
    <div class="wrap">
        <h1>Pay Reports</h1>

        <?php if ( isset( $_GET['us_pay_notice'] ) ) : ?>
            <?php if ( $_GET['us_pay_notice'] === 'paid' ) : ?>
                <div class="notice notice-success is-dismissible"><p>Payment recorded successfully.</p></div>
            <?php elseif ( $_GET['us_pay_notice'] === 'unpaid' ) : ?>
                <div class="notice notice-info is-dismissible"><p>Payment removed.</p></div>
            <?php endif; ?>
        <?php endif; ?>

        <nav class="nav-tab-wrapper us-pay-tabs">
            <a href="<?php echo esc_url( add_query_arg( 'pay_tab', 'leagues', $base_url ) ); ?>"
               class="nav-tab <?php echo $active_tab === 'leagues' ? 'nav-tab-active' : ''; ?>">League Pay</a>
            <?php if ( ! empty( $tournament_leagues ) ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'pay_tab', 'tournaments', $base_url ) ); ?>"
               class="nav-tab <?php echo $active_tab === 'tournaments' ? 'nav-tab-active' : ''; ?>">Tournament Pay</a>
            <?php endif; ?>
        </nav>

        <?php if ( $active_tab === 'leagues' ) : ?>

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

        <div class="us-pay-filters">
            <form method="get">
                <input type="hidden" name="page"    value="us-pay-reports">
                <input type="hidden" name="pay_tab" value="leagues">
                <select name="filter_umpire" class="us-pay-filter-select">
                    <option value="0">All umpires</option>
                    <?php foreach ( $umpires as $u ) : ?>
                        <option value="<?php echo $u->ID; ?>" <?php selected( $filter_umpire, $u->ID ); ?>>
                            <?php echo esc_html( $u->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="filter_league" class="us-pay-filter-select">
                    <option value="0">All leagues</option>
                    <?php foreach ( $regular_leagues as $l ) : ?>
                        <option value="<?php echo $l->ID; ?>" <?php selected( $filter_league, $l->ID ); ?>>
                            <?php echo esc_html( $l->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="submit" class="button" value="Filter">
                <a href="<?php echo esc_url( add_query_arg( 'pay_tab', 'leagues', $base_url ) ); ?>" class="button">Reset</a>
            </form>
        </div>

        <?php if ( empty( $umpire_summaries ) ) : ?>
            <div class="notice notice-info"><p>No pay data found for the selected filters.</p></div>
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
            <table class="wp-list-table widefat us-pay-table">
                <thead>
                    <tr class="us-pay-table__head">
                        <th style="width:130px">Month</th>
                        <th style="width:80px">Games</th>
                        <th style="width:100px">Earned</th>
                        <th style="width:120px">Status</th>
                        <th style="width:120px">Date paid</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $summary['months'] as $month_key => $month ) : ?>
                    <tr>
                        <td class="us-pay-table__month"><?php echo esc_html( $month['label'] ); ?></td>
                        <td><?php echo $month['games']; ?></td>
                        <td>$<?php echo number_format( $month['earned'], 2 ); ?></td>
                        <td>
                            <?php if ( $month['paid'] ) : ?>
                                <span class="us-pay-status--paid">&#10003; Paid</span>
                            <?php else : ?>
                                <span class="us-pay-status--outstanding">Outstanding</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $month['paid'] ? esc_html( date( 'M j, Y', strtotime( $month['payment_date'] ) ) ) : '—'; ?></td>
                        <td>
                            <?php if ( $month['paid'] ) : ?>
                                <a href="<?php echo wp_nonce_url( add_query_arg( [
                                        'page'           => 'us-pay-reports',
                                        'pay_tab'        => 'leagues',
                                        'us_unmark_paid' => '1',
                                        'payment_id'     => $month['payment_id'],
                                        'filter_league'  => $filter_league ?: '',
                                        'filter_umpire'  => $filter_umpire ?: '',
                                    ], $base_url ), 'us_unmark_paid_' . $month['payment_id'] ); ?>"
                                   class="us-pay-remove-link"
                                   onclick="return confirm('Remove this payment record?')">Remove</a>
                            <?php else : ?>
                                <button class="button button-small us-mark-paid-btn"
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
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php elseif ( $active_tab === 'tournaments' ) : ?>

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
            <div class="notice notice-info"><p>No tournament pay data found.</p></div>
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
            <table class="wp-list-table widefat us-pay-table">
                <thead>
                    <tr class="us-pay-table__head">
                        <th>Umpire</th>
                        <th style="width:80px">Games</th>
                        <th style="width:100px">Earned</th>
                        <th style="width:120px">Status</th>
                        <th style="width:120px">Date paid</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $umpire_rows as $row ) : ?>
                    <tr>
                        <td class="us-pay-table__month"><?php echo esc_html( $row['umpire_name'] ); ?></td>
                        <td><?php echo $row['games']; ?></td>
                        <td>$<?php echo number_format( $row['earned'], 2 ); ?></td>
                        <td>
                            <?php if ( $row['paid'] ) : ?>
                                <span class="us-pay-status--paid">&#10003; Paid</span>
                            <?php else : ?>
                                <span class="us-pay-status--outstanding">Outstanding</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $row['paid'] && $row['paid_date'] ? esc_html( date( 'M j, Y', strtotime( $row['paid_date'] ) ) ) : '—'; ?></td>
                        <td>
                            <?php if ( $row['paid'] ) : ?>
                                <a href="<?php echo wp_nonce_url( add_query_arg( [
                                        'page'           => 'us-pay-reports',
                                        'pay_tab'        => 'tournaments',
                                        'us_unmark_paid' => '1',
                                        'payment_id'     => $row['payment_id'],
                                    ], $base_url ), 'us_unmark_paid_' . $row['payment_id'] ); ?>"
                                   class="us-pay-remove-link"
                                   onclick="return confirm('Remove this payment record?')">Remove</a>
                            <?php else : ?>
                                <button class="button button-small us-mark-paid-btn"
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

        <?php endif; ?>

    </div>

    <!-- ── Mark as paid modal ──────────────────────────────── -->
    <div id="us-paid-modal" class="us-pay-modal">
        <div class="us-pay-modal__inner">
            <h3 class="us-pay-modal__title">Mark as paid</h3>
            <p id="us-paid-modal-label"    class="us-pay-modal__label"></p>
            <p id="us-paid-modal-sublabel" class="us-pay-modal__sublabel"></p>
            <form method="post">
                <?php wp_nonce_field( 'us_mark_paid', 'us_mark_paid_nonce' ); ?>
                <input type="hidden" name="us_paid_umpire_id" id="us-paid-umpire-id">
                <input type="hidden" name="us_paid_month"     id="us-paid-month">
                <input type="hidden" name="us_paid_amount"    id="us-paid-amount">
                <input type="hidden" name="us_paid_league"    id="us-paid-league" value="0">
                <input type="hidden" name="us_paid_type"      id="us-paid-type"   value="league">
                <input type="hidden" name="us_paid_tab"       id="us-paid-tab"    value="<?php echo esc_attr( $active_tab ); ?>">
                <?php if ( $filter_umpire ) : ?>
                    <input type="hidden" name="us_paid_filter_umpire" value="<?php echo $filter_umpire; ?>">
                <?php endif; ?>
                <table class="form-table us-pay-modal__table">
                    <tr>
                        <th>Amount</th>
                        <td><strong id="us-paid-amount-display"></strong></td>
                    </tr>
                    <tr>
                        <th>Date paid</th>
                        <td>
                            <input type="date" name="us_paid_date" id="us-paid-date"
                                   value="<?php echo current_time( 'Y-m-d' ); ?>"
                                   required style="width:180px">
                        </td>
                    </tr>
                </table>
                <div class="us-pay-modal__actions">
                    <input type="submit" name="us_mark_paid_submit" class="button button-primary" value="Record payment">
                    <button type="button" id="us-paid-modal-cancel" class="button">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .us-pay-tabs  { margin-bottom: 20px; }
        .us-pay-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; max-width: 700px; }
        .us-pay-card  { background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
        .us-pay-card--danger  { border-top: 3px solid #d63638; }
        .us-pay-card--success { border-top: 3px solid #00a32a; }
        .us-pay-card--info    { border-top: 3px solid #0073aa; }
        .us-pay-card__value { font-size: 26px; font-weight: 700; }
        .us-pay-card--danger  .us-pay-card__value { color: #d63638; }
        .us-pay-card--success .us-pay-card__value { color: #00a32a; }
        .us-pay-card--info    .us-pay-card__value { color: #0073aa; }
        .us-pay-card__label { font-size: 13px; color: #666; margin-top: 4px; }
        .us-pay-filters { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
        .us-pay-filter-select { padding: 4px 8px; font-size: 13px; }
        .us-pay-block             { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); margin-bottom: 24px; overflow: hidden; }
        .us-pay-block__header     { background: #091b33; padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
        .us-pay-block__header-left  { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .us-pay-block__header-right { display: flex; gap: 16px; }
        .us-pay-block__name         { color: #fff; font-size: 15px; font-weight: 600; }
        .us-pay-block__games        { color: #598cb9; font-size: 13px; }
        .us-pay-block__outstanding  { font-size: 13px; color: #ff8080; }
        .us-pay-block__paid         { font-size: 13px; color: #69db7c; }
        .us-pay-table           { border: none; }
        .us-pay-table__head     { background: #f9f9f9; }
        .us-pay-table__month    { font-weight: 500; }
        .us-pay-status--paid        { color: #00a32a; font-weight: 600; }
        .us-pay-status--outstanding { color: #d63638; font-weight: 600; }
        .us-pay-remove-link     { color: #d63638; font-size: 12px; }
        .us-pay-modal             { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; }
        .us-pay-modal__inner      { background: #fff; border-radius: 8px; padding: 28px; max-width: 400px; width: 90%; box-shadow: 0 4px 24px rgba(0,0,0,0.2); }
        .us-pay-modal__title      { margin: 0 0 4px; color: #091b33; }
        .us-pay-modal__label      { margin: 0 0 4px; color: #444; font-size: 14px; font-weight: 600; }
        .us-pay-modal__sublabel   { margin: 0 0 16px; color: #666; font-size: 13px; }
        .us-pay-modal__table      { margin: 0 0 16px; }
        .us-pay-modal__table th   { padding: 6px 0; font-size: 13px; }
        .us-pay-modal__table td   { padding: 6px 0; }
        .us-pay-modal__actions    { display: flex; gap: 8px; }
    </style>

    <script>
    jQuery(function($){
        $('.us-mark-paid-btn').on('click', function(){
            var btn = $(this);
            $('#us-paid-umpire-id').val( btn.data('umpire') );
            $('#us-paid-month').val( btn.data('month') );
            $('#us-paid-amount').val( btn.data('amount') );
            $('#us-paid-league').val( btn.data('league') || 0 );
            $('#us-paid-type').val( btn.data('type') || 'league' );
            $('#us-paid-amount-display').text( '$' + parseFloat( btn.data('amount') ).toFixed(2) );
            $('#us-paid-modal-label').text( btn.data('label') );
            $('#us-paid-modal-sublabel').text( btn.data('sublabel') || '' );
            $('#us-paid-modal').css('display', 'flex');
        });
        $('#us-paid-modal-cancel, #us-paid-modal').on('click', function(e){
            if ( e.target === this ) $('#us-paid-modal').hide();
        });
        $('#us-paid-modal-cancel').on('click', function(){
            $('#us-paid-modal').hide();
        });
    });
    </script>
    <?php
}

// ── Admin pay summary with optional league filter ─────────────
// Also includes per-game detail (game_rows) in each month entry
// for use by the allocator pay reports expandable rows feature.
function us_admin_get_umpire_pay_summary( $umpire_id, $filter_league = 0 ) {
    $assignments = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_umpire_id', 'value' => $umpire_id,  'compare' => '=' ],
            [ 'key' => 'us_status',    'value' => 'confirmed', 'compare' => '=' ],
        ],
    ] );

    $months            = [];
    $total_games       = 0;
    $total_earned      = 0;
    $total_paid        = 0;
    $total_outstanding = 0;

    foreach ( $assignments as $a ) {
        $game_id   = get_post_meta( $a->ID, 'us_game_id',     true );
        $league_id = get_post_meta( $game_id, 'us_league_id', true );

        // Apply league filter if set
        if ( $filter_league && absint( $league_id ) !== absint( $filter_league ) ) continue;

        // Skip tournament games — tracked separately
        if ( $league_id && get_post_meta( $league_id, 'us_is_tournament', true ) === '1' ) continue;

        $game_date = get_post_meta( $game_id, 'us_game_date', true );
        $pay       = floatval( get_post_meta( $a->ID, 'us_pay_amount', true ) );

        if ( ! $game_date ) continue;

        $month_key   = date( 'Y-m', strtotime( $game_date ) );
        $month_label = date( 'F Y', strtotime( $game_date ) );

        if ( ! isset( $months[ $month_key ] ) ) {
            $payment = get_posts( [
                'post_type'   => US_PT_PAYMENT,
                'numberposts' => 1,
                'post_status' => 'publish',
                'meta_query'  => [
                    [ 'key' => 'payment_umpire_id', 'value' => $umpire_id, 'compare' => '=' ],
                    [ 'key' => 'payment_month',     'value' => $month_key, 'compare' => '=' ],
                ],
            ] );

            $months[ $month_key ] = [
                'label'        => $month_label,
                'games'        => 0,
                'earned'       => 0,
                'paid'         => ! empty( $payment ),
                'payment_date' => ! empty( $payment ) ? get_post_meta( $payment[0]->ID, 'payment_date', true ) : '',
                'payment_id'   => ! empty( $payment ) ? $payment[0]->ID : 0,
                'game_rows'    => [],
            ];
        }

        // Store per-game detail for expandable rows
        $months[ $month_key ]['game_rows'][] = [
            'date'     => $game_date,
            'time'     => get_post_meta( $game_id, 'us_game_time', true ),
            'home'     => get_post_meta( $game_id, 'us_home_team', true ),
            'away'     => get_post_meta( $game_id, 'us_away_team', true ),
            'field'    => get_post_meta( $game_id, 'us_field',     true ),
            'league'   => $league_id ? get_the_title( $league_id ) : '—',
            'position' => get_post_meta( $a->ID, 'us_position',   true ),
            'pay'      => $pay,
        ];

        $months[ $month_key ]['games']++;
        $months[ $month_key ]['earned'] += $pay;
        $total_games++;
        $total_earned += $pay;

        if ( $months[ $month_key ]['paid'] ) {
            $total_paid += $pay;
        } else {
            $total_outstanding += $pay;
        }
    }

    krsort( $months );

    // Sort game rows within each month by date + time ascending
    foreach ( $months as &$month ) {
        usort( $month['game_rows'], fn( $a, $b ) => strcmp( $a['date'] . $a['time'], $b['date'] . $b['time'] ) );
    }
    unset( $month );

    return [
        'months'            => $months,
        'total_games'       => $total_games,
        'total_earned'      => $total_earned,
        'total_paid'        => $total_paid,
        'total_outstanding' => $total_outstanding,
    ];
}

// ── Get tournament pay summary ────────────────────────────────
function us_get_tournament_pay_summary( $league_id ) {
    $assignments = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_status', 'value' => 'confirmed', 'compare' => '=' ],
        ],
    ] );

    $by_umpire = [];
    foreach ( $assignments as $a ) {
        $game_id     = get_post_meta( $a->ID, 'us_game_id',     true );
        $game_league = get_post_meta( $game_id, 'us_league_id', true );
        if ( absint( $game_league ) !== absint( $league_id ) ) continue;

        $umpire_id = get_post_meta( $a->ID, 'us_umpire_id', true );
        $pay       = floatval( get_post_meta( $a->ID, 'us_pay_amount', true ) );

        if ( ! isset( $by_umpire[ $umpire_id ] ) ) {
            $by_umpire[ $umpire_id ] = [
                'umpire_id'   => $umpire_id,
                'umpire_name' => get_the_title( $umpire_id ),
                'games'       => 0,
                'earned'      => 0,
                'paid'        => false,
                'paid_date'   => '',
                'payment_id'  => 0,
            ];
        }
        $by_umpire[ $umpire_id ]['games']++;
        $by_umpire[ $umpire_id ]['earned'] += $pay;
    }

    foreach ( $by_umpire as $umpire_id => &$row ) {
        $payment = get_posts( [
            'post_type'   => US_PT_PAYMENT,
            'numberposts' => 1,
            'post_status' => 'publish',
            'meta_query'  => [
                [ 'key' => 'payment_umpire_id', 'value' => $umpire_id, 'compare' => '=' ],
                [ 'key' => 'payment_league_id', 'value' => $league_id, 'compare' => '=' ],
            ],
        ] );
        if ( $payment ) {
            $row['paid']       = true;
            $row['paid_date']  = get_post_meta( $payment[0]->ID, 'payment_date', true );
            $row['payment_id'] = $payment[0]->ID;
        }
    }
    unset( $row );

    uasort( $by_umpire, fn($a, $b) => strcmp( $a['umpire_name'], $b['umpire_name'] ) );
    return array_values( $by_umpire );
}

// ── Handle mark as paid ───────────────────────────────────────
function us_handle_mark_paid() {
    if ( ! isset( $_POST['us_mark_paid_nonce'] ) || ! wp_verify_nonce( $_POST['us_mark_paid_nonce'], 'us_mark_paid' ) ) return;
    if ( ! us_can_manage_pay() ) return;

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

    $redirect = add_query_arg( [
        'page'          => 'us-pay-reports',
        'pay_tab'       => $tab,
        'us_pay_notice' => 'paid',
    ], admin_url( 'admin.php' ) );

    if ( isset( $_POST['us_paid_filter_umpire'] ) ) {
        $redirect = add_query_arg( 'filter_umpire', absint( $_POST['us_paid_filter_umpire'] ), $redirect );
    }

    wp_redirect( $redirect );
    exit;
}

// ── Handle unmark paid ────────────────────────────────────────
function us_handle_unmark_paid() {
    $payment_id = absint( $_GET['payment_id'] ?? 0 );
    if ( ! $payment_id ) return;
    if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'us_unmark_paid_' . $payment_id ) ) return;
    if ( ! us_can_manage_pay() ) return;

    wp_delete_post( $payment_id, true );

    $tab      = sanitize_text_field( $_GET['pay_tab'] ?? 'leagues' );
    $redirect = add_query_arg( [
        'page'          => 'us-pay-reports',
        'pay_tab'       => $tab,
        'us_pay_notice' => 'unpaid',
    ], admin_url( 'admin.php' ) );

    if ( isset( $_GET['filter_umpire'] ) ) $redirect = add_query_arg( 'filter_umpire', absint( $_GET['filter_umpire'] ), $redirect );
    if ( isset( $_GET['filter_league'] ) ) $redirect = add_query_arg( 'filter_league', absint( $_GET['filter_league'] ), $redirect );

    wp_redirect( $redirect );
    exit;
}

// ── Notify umpire: league payment recorded ────────────────────
function us_notify_umpire_paid( $umpire_id, $month, $amount, $date ) {
    $email = get_post_meta( $umpire_id, 'us_email', true );
    if ( ! $email ) return;

    $umpire    = get_the_title( $umpire_id );
    $month_fmt = date( 'F Y', strtotime( $month . '-01' ) );
    $date_fmt  = date( 'l, F j, Y', strtotime( $date ) );

    $message  = "Hi {$umpire},\n\n";
    $message .= "A payment has been recorded for your umpiring services:\n\n";
    $message .= "Month:  {$month_fmt}\n";
    $message .= "Amount: \$" . number_format( $amount, 2 ) . "\n";
    $message .= "Date:   {$date_fmt}\n\n";
    $message .= "View your earnings:\n" . home_url( '/' . us_setting( 'slug_earnings' ) . '/' ) . "\n\n";
    $message .= "Thanks,\n" . us_setting( 'email_footer' );

    wp_mail( $email, 'Payment recorded — ' . $month_fmt, $message );
}

// ── Notify umpire: tournament payment recorded ────────────────
function us_notify_umpire_paid_tournament( $umpire_id, $tourney_name, $amount, $date ) {
    $email = get_post_meta( $umpire_id, 'us_email', true );
    if ( ! $email ) return;

    $umpire   = get_the_title( $umpire_id );
    $date_fmt = date( 'l, F j, Y', strtotime( $date ) );

    $message  = "Hi {$umpire},\n\n";
    $message .= "A tournament payment has been recorded for your umpiring services:\n\n";
    $message .= "Tournament: {$tourney_name}\n";
    $message .= "Amount:     \$" . number_format( $amount, 2 ) . "\n";
    $message .= "Date:       {$date_fmt}\n\n";
    $message .= "View your earnings:\n" . home_url( '/' . us_setting( 'slug_earnings' ) . '/' ) . "\n\n";
    $message .= "Thanks,\n" . us_setting( 'email_footer' );

    wp_mail( $email, 'Tournament payment recorded — ' . $tourney_name, $message );
}