<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── AJAX export handler ───────────────────────────────────────
add_action( 'wp_ajax_us_export_pay_xlsx', 'us_ajax_export_pay_xlsx' );
function us_ajax_export_pay_xlsx() {
    if ( ! us_is_allocator() ) wp_die( 'Unauthorized' );
    check_ajax_referer( 'us_export_pay', 'nonce' );

    $month = sanitize_text_field( $_POST['export_month'] ?? '' );
    if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
        wp_send_json_error( 'Invalid month.' );
    }

    require_once US_PATH . 'includes/lib/SimpleXlsxWriter.php';

    $month_start = $month . '-01';
    $month_end   = date( 'Y-m-t', strtotime( $month_start ) );
    $month_label = date( 'F Y', strtotime( $month_start ) );
    $umpires     = us_get_active_umpires();

    $xlsx = new SimpleXlsxWriter();
    $idx  = $xlsx->addSheet( $month_label );

    $xlsx->setColWidths( $idx, [ 28, 24, 10, 10, 12, 12, 12 ] );

    // ── Title row ─────────────────────────────────────────────
    $xlsx->writeRow( $idx, [
        [ 'Umpire Pay Report — ' . $month_label, SimpleXlsxWriter::STYLE_BOLD ],
    ] );
    $xlsx->writeBlankRow( $idx );

    // ── Column headers ────────────────────────────────────────
    $xlsx->writeRow( $idx, [
        [ 'Umpire',        SimpleXlsxWriter::STYLE_HEADER ],
        [ 'League',        SimpleXlsxWriter::STYLE_HEADER ],
        [ 'Games',         SimpleXlsxWriter::STYLE_HEADER ],
        [ 'Rate',          SimpleXlsxWriter::STYLE_HEADER ],
        [ 'League Total',  SimpleXlsxWriter::STYLE_HEADER ],
        [ 'Month Total',   SimpleXlsxWriter::STYLE_HEADER ],
        [ 'Status',        SimpleXlsxWriter::STYLE_HEADER ],
    ] );

    $grand_total = 0;

    foreach ( $umpires as $umpire ) {
        // Get all confirmed assignments for this umpire in the selected month
        $assignments = get_posts( [
            'post_type'   => US_PT_ASSIGNMENT,
            'numberposts' => -1,
            'post_status' => 'publish',
            'meta_query'  => [
                [ 'key' => 'us_umpire_id', 'value' => $umpire->ID, 'compare' => '=' ],
                [ 'key' => 'us_status',    'value' => 'confirmed',  'compare' => '=' ],
            ],
        ] );

        // Filter to selected month and group by league
        $league_data = [];
        foreach ( $assignments as $a ) {
            $game_id   = get_post_meta( $a->ID, 'us_game_id',    true );
            $game_date = get_post_meta( $game_id, 'us_game_date', true );

            if ( ! $game_date || $game_date < $month_start || $game_date > $month_end ) continue;

            $league_id   = get_post_meta( $game_id, 'us_league_id', true );
            $league_name = $league_id ? get_the_title( $league_id ) : 'Unknown';
            $pay         = floatval( get_post_meta( $a->ID, 'us_pay_amount', true ) );

            if ( ! isset( $league_data[ $league_name ] ) ) {
                $league_data[ $league_name ] = [ 'games' => 0, 'total' => 0 ];
            }
            $league_data[ $league_name ]['games']++;
            $league_data[ $league_name ]['total'] += $pay;
        }

        if ( empty( $league_data ) ) continue;

        // Check payment status for this umpire/month
        $payment = get_posts( [
            'post_type'   => US_PT_PAYMENT,
            'numberposts' => 1,
            'post_status' => 'publish',
            'meta_query'  => [
                [ 'key' => 'us_umpire_id', 'value' => $umpire->ID, 'compare' => '=' ],
                [ 'key' => 'us_month',     'value' => $month,      'compare' => '=' ],
            ],
        ] );
        $status = $payment ? 'Paid' : 'Outstanding';

        // ── Umpire section header ─────────────────────────────
        $xlsx->writeBlankRow( $idx );
        $xlsx->writeRow( $idx, [
            [ $umpire->post_title, SimpleXlsxWriter::STYLE_SECTION ],
            [ '', SimpleXlsxWriter::STYLE_SECTION ],
            [ '', SimpleXlsxWriter::STYLE_SECTION ],
            [ '', SimpleXlsxWriter::STYLE_SECTION ],
            [ '', SimpleXlsxWriter::STYLE_SECTION ],
            [ '', SimpleXlsxWriter::STYLE_SECTION ],
            [ '', SimpleXlsxWriter::STYLE_SECTION ],
        ] );

        $umpire_total  = 0;
        $first_league  = true;

        ksort( $league_data ); // alphabetical by league

        foreach ( $league_data as $league_name => $data ) {
            $games        = $data['games'];
            $league_total = $data['total'];
            $rate         = $games > 0 ? round( $league_total / $games, 2 ) : 0;
            $umpire_total += $league_total;

            $xlsx->writeRow( $idx, [
                [ '', SimpleXlsxWriter::STYLE_NORMAL ],
                [ $league_name, SimpleXlsxWriter::STYLE_NORMAL ],
                [ $games,       SimpleXlsxWriter::STYLE_NORMAL, 'n' ],
                [ $rate,        SimpleXlsxWriter::STYLE_MONEY,  'n' ],
                [ $league_total, SimpleXlsxWriter::STYLE_MONEY, 'n' ],
                [ '', SimpleXlsxWriter::STYLE_NORMAL ],
                [ $first_league ? $status : '', SimpleXlsxWriter::STYLE_NORMAL ],
            ] );
            $first_league = false;
        }

        // ── Umpire total row ──────────────────────────────────
        $xlsx->writeRow( $idx, [
            [ 'Total', SimpleXlsxWriter::STYLE_TOTAL ],
            [ '',      SimpleXlsxWriter::STYLE_TOTAL ],
            [ '',      SimpleXlsxWriter::STYLE_TOTAL ],
            [ '',      SimpleXlsxWriter::STYLE_TOTAL ],
            [ '',      SimpleXlsxWriter::STYLE_TOTAL ],
            [ $umpire_total, 6, 'n' ], // style 6 = total money
            [ '',      SimpleXlsxWriter::STYLE_TOTAL ],
        ] );

        $grand_total += $umpire_total;
    }

    // ── Grand total ───────────────────────────────────────────
    $xlsx->writeBlankRow( $idx );
    $xlsx->writeRow( $idx, [
        [ 'GRAND TOTAL', SimpleXlsxWriter::STYLE_BOLD ],
        [ '', SimpleXlsxWriter::STYLE_NORMAL ],
        [ '', SimpleXlsxWriter::STYLE_NORMAL ],
        [ '', SimpleXlsxWriter::STYLE_NORMAL ],
        [ '', SimpleXlsxWriter::STYLE_NORMAL ],
        [ $grand_total, SimpleXlsxWriter::STYLE_MONEY, 'n' ],
        [ '', SimpleXlsxWriter::STYLE_NORMAL ],
    ] );

    // ── Sheet 2: Admin Fees ───────────────────────────────────────
    $all_leagues     = get_posts( [ 'post_type' => US_PT_LEAGUE, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post_status' => 'publish' ] );
    $reg_leagues     = array_values( array_filter( $all_leagues, fn( $l ) => get_post_meta( $l->ID, 'us_is_tournament', true ) !== '1' ) );
    $tourney_leagues = array_values( array_filter( $all_leagues, fn( $l ) => get_post_meta( $l->ID, 'us_is_tournament', true ) === '1' ) );

    // Count confirmed umpire slots for a league within the month
    $get_slot_count = function( $league_id ) use ( $month_start, $month_end ) {
        $games = get_posts( [
            'post_type'   => US_PT_GAME,
            'numberposts' => -1,
            'post_status' => 'publish',
            'meta_query'  => [
                [ 'key' => 'us_league_id', 'value' => $league_id,   'compare' => '=' ],
                [ 'key' => 'us_game_date', 'value' => $month_start, 'compare' => '>=' ],
                [ 'key' => 'us_game_date', 'value' => $month_end,   'compare' => '<=' ],
            ],
        ] );
        $count = 0;
        foreach ( $games as $game ) {
            $confirmed = get_posts( [
                'post_type'   => US_PT_ASSIGNMENT,
                'numberposts' => -1,
                'post_status' => 'publish',
                'fields'      => 'ids',
                'meta_query'  => [
                    [ 'key' => 'us_game_id', 'value' => $game->ID,  'compare' => '=' ],
                    [ 'key' => 'us_status',  'value' => 'confirmed', 'compare' => '=' ],
                ],
            ] );
            $count += count( $confirmed );
        }
        return $count;
    };

    $idx2 = $xlsx->addSheet( 'Admin Fees' );
    $xlsx->setColWidths( $idx2, [ 28, 12, 14, 14, 12, 12, 14 ] );

    $xlsx->writeRow( $idx2, [
        [ 'Admin Fees — ' . $month_label, SimpleXlsxWriter::STYLE_BOLD ],
    ] );
    $xlsx->writeBlankRow( $idx2 );

    $col_headers = [
        [ 'League',         SimpleXlsxWriter::STYLE_HEADER ],
        [ 'Games (slots)',  SimpleXlsxWriter::STYLE_HEADER ],
        [ 'Allocator Rate', SimpleXlsxWriter::STYLE_HEADER ],
        [ 'Allocator Fees', SimpleXlsxWriter::STYLE_HEADER ],
        [ 'Admin Rate',     SimpleXlsxWriter::STYLE_HEADER ],
        [ 'Admin Fees',     SimpleXlsxWriter::STYLE_HEADER ],
        [ 'Total',          SimpleXlsxWriter::STYLE_HEADER ],
    ];

    // ── League fees section ───────────────────────────────────────
    $xlsx->writeRow( $idx2, [
        [ 'League Fees', SimpleXlsxWriter::STYLE_SECTION ],
        [ '', SimpleXlsxWriter::STYLE_SECTION ], [ '', SimpleXlsxWriter::STYLE_SECTION ],
        [ '', SimpleXlsxWriter::STYLE_SECTION ], [ '', SimpleXlsxWriter::STYLE_SECTION ],
        [ '', SimpleXlsxWriter::STYLE_SECTION ], [ '', SimpleXlsxWriter::STYLE_SECTION ],
    ] );
    $xlsx->writeRow( $idx2, $col_headers );

    $league_alloc_total = 0;
    $league_admin_total = 0;
    $league_fees_total  = 0;
    $wrote_league_row   = false;

    foreach ( $reg_leagues as $league ) {
        $allocator_rate = floatval( get_post_meta( $league->ID, 'us_allocator_rate', true ) );
        $admin_rate     = floatval( get_post_meta( $league->ID, 'us_admin_rate',     true ) );
        if ( ! $allocator_rate && ! $admin_rate ) continue;

        $slot_count = $get_slot_count( $league->ID );
        if ( ! $slot_count ) continue;

        $alloc_fees = $allocator_rate * $slot_count;
        $admin_fees = $admin_rate     * $slot_count;
        $row_total  = $alloc_fees + $admin_fees;

        $league_alloc_total += $alloc_fees;
        $league_admin_total += $admin_fees;
        $league_fees_total  += $row_total;
        $wrote_league_row    = true;

        $xlsx->writeRow( $idx2, [
            [ $league->post_title, SimpleXlsxWriter::STYLE_NORMAL ],
            [ $slot_count,         SimpleXlsxWriter::STYLE_NORMAL, 'n' ],
            [ $allocator_rate,     SimpleXlsxWriter::STYLE_MONEY,  'n' ],
            [ $alloc_fees,         SimpleXlsxWriter::STYLE_MONEY,  'n' ],
            [ $admin_rate,         SimpleXlsxWriter::STYLE_MONEY,  'n' ],
            [ $admin_fees,         SimpleXlsxWriter::STYLE_MONEY,  'n' ],
            [ $row_total,          SimpleXlsxWriter::STYLE_MONEY,  'n' ],
        ] );
    }

    if ( ! $wrote_league_row ) {
        $xlsx->writeRow( $idx2, [ [ 'No league fee data for this month.', SimpleXlsxWriter::STYLE_NORMAL ] ] );
    } else {
        $xlsx->writeRow( $idx2, [
            [ 'League Total', SimpleXlsxWriter::STYLE_TOTAL ],
            [ '',             SimpleXlsxWriter::STYLE_TOTAL ],
            [ '',             SimpleXlsxWriter::STYLE_TOTAL ],
            [ $league_alloc_total, 6, 'n' ],
            [ '',             SimpleXlsxWriter::STYLE_TOTAL ],
            [ $league_admin_total, 6, 'n' ],
            [ $league_fees_total,  6, 'n' ],
        ] );
    }

    // ── Tournament allocation section ─────────────────────────────
    $tourney_alloc_total  = 0;
    $tourney_admin_total  = 0;
    $tourney_fees_total   = 0;
    $tourney_rows_pending = [];

    foreach ( $tourney_leagues as $league ) {
        $allocator_rate = floatval( get_post_meta( $league->ID, 'us_allocator_rate', true ) );
        $admin_rate     = floatval( get_post_meta( $league->ID, 'us_admin_rate',     true ) );
        if ( ! $allocator_rate && ! $admin_rate ) continue;

        $slot_count = $get_slot_count( $league->ID );
        if ( ! $slot_count ) continue;

        $alloc_fees = $allocator_rate * $slot_count;
        $admin_fees = $admin_rate     * $slot_count;
        $row_total  = $alloc_fees + $admin_fees;

        $tourney_alloc_total += $alloc_fees;
        $tourney_admin_total += $admin_fees;
        $tourney_fees_total  += $row_total;

        $tourney_rows_pending[] = [
            [ $league->post_title, SimpleXlsxWriter::STYLE_NORMAL ],
            [ $slot_count,         SimpleXlsxWriter::STYLE_NORMAL, 'n' ],
            [ $allocator_rate,     SimpleXlsxWriter::STYLE_MONEY,  'n' ],
            [ $alloc_fees,         SimpleXlsxWriter::STYLE_MONEY,  'n' ],
            [ $admin_rate,         SimpleXlsxWriter::STYLE_MONEY,  'n' ],
            [ $admin_fees,         SimpleXlsxWriter::STYLE_MONEY,  'n' ],
            [ $row_total,          SimpleXlsxWriter::STYLE_MONEY,  'n' ],
        ];
    }

    if ( ! empty( $tourney_rows_pending ) ) {
        $xlsx->writeBlankRow( $idx2 );
        $xlsx->writeRow( $idx2, [
            [ 'Tournament Allocation', SimpleXlsxWriter::STYLE_SECTION ],
            [ '', SimpleXlsxWriter::STYLE_SECTION ], [ '', SimpleXlsxWriter::STYLE_SECTION ],
            [ '', SimpleXlsxWriter::STYLE_SECTION ], [ '', SimpleXlsxWriter::STYLE_SECTION ],
            [ '', SimpleXlsxWriter::STYLE_SECTION ], [ '', SimpleXlsxWriter::STYLE_SECTION ],
        ] );
        $xlsx->writeRow( $idx2, $col_headers );
        foreach ( $tourney_rows_pending as $row ) {
            $xlsx->writeRow( $idx2, $row );
        }
        $xlsx->writeRow( $idx2, [
            [ 'Tournament Total', SimpleXlsxWriter::STYLE_TOTAL ],
            [ '',                 SimpleXlsxWriter::STYLE_TOTAL ],
            [ '',                 SimpleXlsxWriter::STYLE_TOTAL ],
            [ $tourney_alloc_total, 6, 'n' ],
            [ '',                 SimpleXlsxWriter::STYLE_TOTAL ],
            [ $tourney_admin_total, 6, 'n' ],
            [ $tourney_fees_total,  6, 'n' ],
        ] );
    }

    // ── Grand total ───────────────────────────────────────────────
    if ( $wrote_league_row || ! empty( $tourney_rows_pending ) ) {
        $xlsx->writeBlankRow( $idx2 );
        $xlsx->writeRow( $idx2, [
            [ 'GRAND TOTAL', SimpleXlsxWriter::STYLE_BOLD ],
            [ '',            SimpleXlsxWriter::STYLE_NORMAL ],
            [ '',            SimpleXlsxWriter::STYLE_NORMAL ],
            [ $league_alloc_total + $tourney_alloc_total, SimpleXlsxWriter::STYLE_MONEY, 'n' ],
            [ '',            SimpleXlsxWriter::STYLE_NORMAL ],
            [ $league_admin_total + $tourney_admin_total, SimpleXlsxWriter::STYLE_MONEY, 'n' ],
            [ $league_fees_total  + $tourney_fees_total,  SimpleXlsxWriter::STYLE_MONEY, 'n' ],
        ] );
    }

    $filename = 'pay-report-' . $month . '.xlsx';
    $xlsx->download( $filename );
}