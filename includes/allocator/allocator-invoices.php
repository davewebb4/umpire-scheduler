<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── AJAX: months with confirmed games for a league ────────────
add_action( 'wp_ajax_us_invoice_get_months', 'us_ajax_invoice_get_months' );
function us_ajax_invoice_get_months() {
    check_ajax_referer( 'us_assign_nonce', 'nonce' );
    if ( ! us_is_allocator() ) wp_send_json_error( 'Unauthorized' );

    $league_id = absint( $_POST['league_id'] ?? 0 );
    if ( ! $league_id ) wp_send_json_error( 'No league selected.' );

    $is_tournament = get_post_meta( $league_id, 'us_is_tournament', true ) === '1';
    if ( $is_tournament ) {
        wp_send_json_success( [ 'tournament' => true ] );
    }

    $games = get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'post_status' => 'publish',
        'fields'      => 'ids',
        'meta_query'  => [ [ 'key' => 'us_league_id', 'value' => $league_id, 'compare' => '=' ] ],
    ] );

    $months = [];
    foreach ( $games as $game_id ) {
        $confirmed = get_posts( [
            'post_type'   => US_PT_ASSIGNMENT,
            'numberposts' => 1,
            'post_status' => 'publish',
            'fields'      => 'ids',
            'meta_query'  => [
                [ 'key' => 'us_game_id', 'value' => $game_id,                          'compare' => '=' ],
                [ 'key' => 'us_status',  'value' => [ 'confirmed', 'postponed-paid' ], 'compare' => 'IN' ],
            ],
        ] );
        if ( ! $confirmed ) continue;

        $date      = get_post_meta( $game_id, 'us_game_date', true );
        $month_key = $date ? substr( $date, 0, 7 ) : '';
        if ( ! $month_key ) continue;

        $months[ $month_key ] = ( $months[ $month_key ] ?? 0 ) + 1;
    }

    if ( empty( $months ) ) {
        wp_send_json_success( [ 'months' => [] ] );
    }

    ksort( $months );

    $result = [];
    foreach ( $months as $key => $count ) {
        $result[] = [
            'value' => $key,
            'label' => date( 'F Y', strtotime( $key . '-01' ) ),
            'games' => $count,
        ];
    }

    wp_send_json_success( [ 'months' => $result ] );
}

// ── Format a period label from an array of YYYY-MM month strings ─
function us_invoice_period_label( $months ) {
    if ( empty( $months ) ) return '';
    sort( $months );
    if ( count( $months ) === 1 ) {
        return date( 'F Y', strtotime( $months[0] . '-01' ) );
    }
    $first_year = substr( $months[0], 0, 4 );
    $last_year  = substr( end( $months ), 0, 4 );
    $first_label = ( $first_year === $last_year )
        ? date( 'F', strtotime( $months[0] . '-01' ) )
        : date( 'F Y', strtotime( $months[0] . '-01' ) );
    $last_label  = date( 'F Y', strtotime( end( $months ) . '-01' ) );
    return $first_label . ' – ' . $last_label;
}

// ── Build invoice breakdown ───────────────────────────────────
// $months: array of YYYY-MM strings; empty = all games (tournament).
// Umpire pay comes from actual us_pay_amount on each assignment.
// Alloc/admin fees are per umpire slot, matching pay-reports logic.
function us_get_invoice_breakdown( $league_id, $months = [] ) {
    if ( is_string( $months ) && $months !== '' ) {
        $months = array_values( array_filter( array_map( 'trim', explode( ',', $months ) ) ) );
    }

    $alloc_rate = floatval( get_post_meta( $league_id, 'us_allocator_rate', true ) );
    $admin_rate = floatval( get_post_meta( $league_id, 'us_admin_rate',     true ) );

    $meta_query = [ [ 'key' => 'us_league_id', 'value' => $league_id, 'compare' => '=' ] ];
    if ( ! empty( $months ) ) {
        sort( $months );
        $start = $months[0] . '-01';
        $end   = date( 'Y-m-t', strtotime( end( $months ) . '-01' ) );
        $meta_query[] = [ 'key' => 'us_game_date', 'value' => $start, 'compare' => '>=' ];
        $meta_query[] = [ 'key' => 'us_game_date', 'value' => $end,   'compare' => '<=' ];
    }

    $games = get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => $meta_query,
    ] );

    $rows          = [];
    $unbilled_rows = [];
    $totals = [
        'games'      => 0,
        'slots'      => 0,
        'umpire_pay' => 0.0,
        'alloc'      => 0.0,
        'admin'      => 0.0,
        'grand'      => 0.0,
    ];

    foreach ( $games as $game ) {
        $game_date  = get_post_meta( $game->ID, 'us_game_date', true );
        $month_key  = $game_date ? substr( $game_date, 0, 7 ) : '';

        // When multiple months selected, skip games that fall in an unselected month
        // (the date range query may include gaps between non-consecutive months)
        if ( ! empty( $months ) && ! in_array( $month_key, $months, true ) ) continue;

        $assignments = get_posts( [
            'post_type'   => US_PT_ASSIGNMENT,
            'numberposts' => -1,
            'post_status' => 'publish',
            'fields'      => 'ids',
            'meta_query'  => [
                [ 'key' => 'us_game_id', 'value' => $game->ID,                         'compare' => '=' ],
                [ 'key' => 'us_status',  'value' => [ 'confirmed', 'postponed-paid' ], 'compare' => 'IN' ],
            ],
        ] );
        if ( ! $assignments ) {
            $game_status = get_post_meta( $game->ID, 'us_game_status', true );
            if ( $game_status !== 'cancelled' ) {
                $unbilled_rows[] = [
                    'date'         => $game_date,
                    'month_key'    => $month_key,
                    'title'        => $game->post_title,
                    'is_dh'        => get_post_meta( $game->ID, 'us_double_header', true ) === '1',
                    'is_postponed' => $game_status === 'postponed',
                ];
            }
            continue;
        }

        $slot_count = count( $assignments );
        $umpire_pay = 0.0;
        foreach ( $assignments as $asn_id ) {
            $umpire_pay += floatval( get_post_meta( $asn_id, 'us_pay_amount', true ) );
        }

        $game_alloc = $alloc_rate * $slot_count;
        $game_admin = $admin_rate * $slot_count;
        $game_total = $umpire_pay + $game_alloc + $game_admin;

        $rows[] = [
            'date'         => $game_date,
            'month_key'    => $month_key,
            'title'        => $game->post_title,
            'slots'        => $slot_count,
            'is_dh'        => get_post_meta( $game->ID, 'us_double_header', true ) === '1',
            'is_postponed' => get_post_meta( $game->ID, 'us_game_status', true ) === 'postponed',
            'umpire_pay'   => $umpire_pay,
            'alloc'        => $game_alloc,
            'admin'        => $game_admin,
            'total'        => $game_total,
        ];

        $totals['games']++;
        $totals['slots']      += $slot_count;
        $totals['umpire_pay'] += $umpire_pay;
        $totals['alloc']      += $game_alloc;
        $totals['admin']      += $game_admin;
        $totals['grand']      += $game_total;
    }

    usort( $rows,          fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );
    usort( $unbilled_rows, fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );

    // Group rows by per-slot umpire pay rate for summary display
    $by_rate = [];
    foreach ( $rows as $row ) {
        if ( $row['slots'] <= 0 ) continue;
        $rate_per_slot = round( $row['umpire_pay'] / $row['slots'], 2 );
        $rate_key      = number_format( $rate_per_slot, 2 );
        if ( ! isset( $by_rate[ $rate_key ] ) ) {
            $by_rate[ $rate_key ] = [ 'rate' => $rate_per_slot, 'games' => 0, 'slots' => 0, 'umpire_pay' => 0.0 ];
        }
        $by_rate[ $rate_key ]['games']++;
        $by_rate[ $rate_key ]['slots']      += $row['slots'];
        $by_rate[ $rate_key ]['umpire_pay'] += $row['umpire_pay'];
    }
    ksort( $by_rate );

    return [
        'rows'     => $rows,
        'unbilled' => $unbilled_rows,
        'totals'   => $totals,
        'rates'    => [ 'alloc' => $alloc_rate, 'admin' => $admin_rate ],
        'by_rate'  => $by_rate,
    ];
}

// ── AJAX: send invoice email ──────────────────────────────────
add_action( 'wp_ajax_us_send_invoice', 'us_ajax_send_invoice' );
function us_ajax_send_invoice() {
    check_ajax_referer( 'us_assign_nonce', 'nonce' );
    if ( ! us_is_allocator() ) wp_send_json_error( 'Unauthorized' );

    $league_id = absint( $_POST['league_id'] ?? 0 );
    $inv_num   = sanitize_text_field( $_POST['inv_num']  ?? '' );
    $inv_date  = sanitize_text_field( $_POST['inv_date'] ?? '' );
    $period    = sanitize_text_field( $_POST['period']   ?? '' );
    $month         = sanitize_text_field( $_POST['month']    ?? '' );
    $notes         = sanitize_textarea_field( $_POST['notes'] ?? '' );
    $override_rate = floatval( $_POST['override_rate'] ?? 0 );
    $deposit       = floatval( $_POST['deposit']       ?? 0 );

    $league = get_post( $league_id );
    if ( ! $league ) wp_send_json_error( 'League not found' );

    $contact_email = get_post_meta( $league_id, 'us_contact_email', true );
    if ( ! $contact_email ) wp_send_json_error( 'No contact email on file for this league.' );

    $is_tournament = get_post_meta( $league_id, 'us_is_tournament', true ) === '1';
    $months        = array_values( array_filter( array_map( 'trim', explode( ',', $month ) ) ) );

    // Legacy path: pay-reports modal passes game_count + rate instead of month
    $legacy_game_count = absint( $_POST['game_count'] ?? 0 );
    $legacy_rate       = floatval( $_POST['rate']       ?? 0 );
    if ( empty( $months ) && $legacy_game_count && $legacy_rate ) {
        $legacy_total = $legacy_game_count * $legacy_rate;
        $breakdown = [
            'rows'   => [],
            'totals' => [ 'games' => $legacy_game_count, 'slots' => $legacy_game_count, 'umpire_pay' => $legacy_total, 'alloc' => 0.0, 'admin' => 0.0, 'grand' => $legacy_total ],
            'rates'  => [ 'alloc' => 0.0, 'admin' => 0.0 ],
        ];
    } else {
        $breakdown = us_get_invoice_breakdown( $league_id, $is_tournament ? [] : $months );
    }

    if ( $override_rate > 0 && ! empty( $breakdown['totals']['slots'] ) ) {
        $breakdown['totals']['grand'] = round( $override_rate * $breakdown['totals']['slots'], 2 );
    }

    $org_name      = us_setting( 'org_name' )  ?: us_setting( 'org_short' );
    $assignor_name = us_setting( 'assignor_name' );
    $from_email    = us_setting( 'assignor_email' );

    $subject = 'Invoice ' . $inv_num . ' — ' . $league->post_title . ' — ' . $period;
    $body    = us_invoice_email_html( $league, $inv_num, $inv_date, $period, $breakdown, $notes, $org_name, $assignor_name, $deposit );
    $headers = [
        'From: ' . ( $assignor_name ?: $org_name ) . ' <' . $from_email . '>',
        'Cc: '   . $from_email,
    ];

    add_filter( 'wp_mail_content_type', 'us_invoice_mail_html_type' );
    $sent = wp_mail( $contact_email, $subject, $body, $headers );
    remove_filter( 'wp_mail_content_type', 'us_invoice_mail_html_type' );

    if ( $sent ) {
        wp_send_json_success( 'Invoice sent to ' . $contact_email );
    } else {
        wp_send_json_error( 'Mail could not be sent. Check server mail settings.' );
    }
}

function us_invoice_mail_html_type() { return 'text/html'; }

// ── Invoice email HTML ────────────────────────────────────────
function us_invoice_email_html( $league, $inv_num, $inv_date, $period, $breakdown, $notes, $org_name, $assignor_name, $deposit = 0.0 ) {
    $totals        = $breakdown['totals'];
    $rates         = $breakdown['rates'];
    $contact_name  = get_post_meta( $league->ID, 'us_contact_name',  true );
    $contact_email = get_post_meta( $league->ID, 'us_contact_email', true );
    $contact_phone = get_post_meta( $league->ID, 'us_contact_phone', true );
    $issued_by     = $assignor_name ?: $org_name;

    ob_start(); ?>
<table cellpadding="0" cellspacing="0" border="0" width="600" style="font-family:Arial,sans-serif;font-size:14px;color:#333;margin:0 auto;">

  <tr>
    <td colspan="2" bgcolor="#091b33" style="background-color:#091b33;padding:20px 28px;">
      <p style="margin:0;font-size:20px;font-weight:bold;color:#ffffff;"><?php echo esc_html( $org_name ); ?></p>
      <p style="margin:4px 0 0;font-size:12px;color:#aac4e0;letter-spacing:1px;">INVOICE <?php echo esc_html( $inv_num ); ?></p>
    </td>
  </tr>
  <tr><td colspan="2" height="20"></td></tr>

  <tr>
    <td valign="top" width="50%" style="padding:0 28px 0 28px;">
      <p style="margin:0 0 4px;font-size:11px;color:#999;text-transform:uppercase;">Bill To</p>
      <p style="margin:0;font-size:15px;font-weight:bold;color:#091b33;"><?php echo esc_html( $league->post_title ); ?></p>
      <?php if ( $contact_name )  : ?><p style="margin:3px 0 0;font-size:13px;color:#555;"><?php echo esc_html( $contact_name ); ?></p><?php endif; ?>
      <?php if ( $contact_email ) : ?><p style="margin:3px 0 0;font-size:13px;color:#555;"><?php echo esc_html( $contact_email ); ?></p><?php endif; ?>
      <?php if ( $contact_phone ) : ?><p style="margin:3px 0 0;font-size:13px;color:#555;"><?php echo esc_html( $contact_phone ); ?></p><?php endif; ?>
    </td>
    <td valign="top" width="50%" align="right" style="padding:0 28px 0 0;">
      <p style="margin:0 0 4px;font-size:11px;color:#999;text-transform:uppercase;">Invoice Details</p>
      <p style="margin:0;font-size:13px;color:#555;">Date: <?php echo esc_html( $inv_date ); ?></p>
      <p style="margin:3px 0 0;font-size:13px;color:#555;">Period: <?php echo esc_html( $period ); ?></p>
      <p style="margin:3px 0 0;font-size:13px;color:#555;">From: <?php echo esc_html( $issued_by ); ?></p>
    </td>
  </tr>
  <tr><td colspan="2" height="24"></td></tr>

  <tr>
    <td colspan="2" style="padding:0 28px;">
      <table cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;">
        <tr bgcolor="#f0f4f8" style="background-color:#f0f4f8;">
          <td style="padding:9px 10px;font-size:11px;font-weight:bold;color:#555;text-transform:uppercase;border-bottom:2px solid #dde3ea;">Description</td>
          <td align="center" style="padding:9px 10px;font-size:11px;font-weight:bold;color:#555;text-transform:uppercase;border-bottom:2px solid #dde3ea;width:70px;">Slots</td>
          <td align="right"  style="padding:9px 10px;font-size:11px;font-weight:bold;color:#555;text-transform:uppercase;border-bottom:2px solid #dde3ea;width:100px;">Amount</td>
        </tr>
        <tr>
          <td style="padding:12px 10px;font-size:14px;border-bottom:1px solid #eee;">
            Umpire services &mdash; <?php echo esc_html( $league->post_title ); ?><br>
            <span style="font-size:12px;color:#888;"><?php echo esc_html( $period ); ?> &bull; <?php echo intval( $totals['games'] ); ?> games &bull; <?php echo intval( $totals['slots'] ); ?> umpire slots</span>
          </td>
          <td align="center" style="padding:12px 10px;font-size:14px;border-bottom:1px solid #eee;"><?php echo intval( $totals['slots'] ); ?></td>
          <td align="right"  style="padding:12px 10px;font-size:14px;border-bottom:1px solid #eee;">$<?php echo number_format( $totals['grand'], 2 ); ?></td>
        </tr>
        <?php if ( $deposit > 0 ) :
            $email_due = max( 0.0, $totals['grand'] - $deposit );
        ?>
        <tr>
          <td colspan="2" align="right" style="padding:14px 10px 2px;font-size:12px;color:#aaa;">Invoice Total</td>
          <td align="right" style="padding:14px 10px 2px;font-size:14px;color:#aaa;">$<?php echo number_format( $totals['grand'], 2 ); ?></td>
        </tr>
        <tr>
          <td colspan="2" align="right" style="padding:2px 10px;font-size:12px;color:#aaa;">Deposit Received</td>
          <td align="right" style="padding:2px 10px;font-size:14px;color:#aaa;">&minus;$<?php echo number_format( $deposit, 2 ); ?></td>
        </tr>
        <tr>
          <td colspan="2" align="right" style="padding:2px 10px 4px;font-size:13px;font-weight:bold;color:#091b33;">Amount Due</td>
          <td align="right" style="padding:2px 10px 4px;font-size:20px;font-weight:bold;color:#091b33;">$<?php echo number_format( $email_due, 2 ); ?></td>
        </tr>
        <?php else : ?>
        <tr>
          <td colspan="2" align="right" style="padding:14px 10px 4px;font-size:13px;font-weight:bold;color:#091b33;">Total Due</td>
          <td align="right" style="padding:14px 10px 4px;font-size:20px;font-weight:bold;color:#091b33;">$<?php echo number_format( $totals['grand'], 2 ); ?></td>
        </tr>
        <?php endif; ?>
      </table>
    </td>
  </tr>

  <?php if ( ! empty( $breakdown['unbilled'] ) ) :
      $ub_unassigned = count( array_filter( $breakdown['unbilled'], fn( $r ) => ! $r['is_postponed'] ) );
      $ub_postponed  = count( array_filter( $breakdown['unbilled'], fn( $r ) =>   $r['is_postponed'] ) );
      $ub_parts      = array_filter( [
          $ub_unassigned ? $ub_unassigned . ' unassigned' : '',
          $ub_postponed  ? $ub_postponed  . ' postponed (not paid)' : '',
      ] );
  ?>
  <tr><td colspan="2" height="12"></td></tr>
  <tr>
    <td colspan="2" style="padding:0 28px;">
      <p style="margin:0;font-size:12px;color:#aaa;padding:10px 14px;background:#f8f9fa;border:1px solid #eee;">
        <strong style="display:block;font-size:10px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Not included in this invoice</strong>
        <?php echo esc_html( implode( ' · ', $ub_parts ) ); ?> — no charge
      </p>
    </td>
  </tr>
  <?php endif; ?>

  <?php if ( $notes ) : ?>
  <tr><td colspan="2" height="16"></td></tr>
  <tr>
    <td colspan="2" style="padding:0 28px;">
      <table cellpadding="12" cellspacing="0" border="0" width="100%" bgcolor="#f8f9fa" style="background-color:#f8f9fa;border-left:3px solid #091b33;">
        <tr><td>
          <p style="margin:0 0 4px;font-size:11px;color:#999;text-transform:uppercase;">Notes</p>
          <p style="margin:0;font-size:13px;color:#555;"><?php echo nl2br( esc_html( $notes ) ); ?></p>
        </td></tr>
      </table>
    </td>
  </tr>
  <?php endif; ?>

  <tr><td colspan="2" height="24"></td></tr>
  <tr>
    <td colspan="2" style="padding:16px 28px;border-top:1px solid #eee;">
      <p style="margin:0;font-size:12px;color:#aaa;text-align:center;">Issued by <?php echo esc_html( $issued_by ); ?> &bull; <?php echo esc_html( $org_name ); ?></p>
    </td>
  </tr>
</table>
    <?php
    return ob_get_clean();
}

// ── Generate next invoice number ──────────────────────────────
function us_next_invoice_number() {
    $seq    = (int) get_option( 'us_invoice_seq', 0 ) + 1;
    $prefix = strtoupper( us_setting( 'org_short' ) ?: 'INV' );
    $num    = $prefix . '-' . date( 'Y' ) . '-' . str_pad( $seq, 3, '0', STR_PAD_LEFT );
    update_option( 'us_invoice_seq', $seq );
    return $num;
}

// ── Shortcode ─────────────────────────────────────────────────
add_shortcode( 'allocator_invoices', 'us_shortcode_allocator_invoices' );
function us_shortcode_allocator_invoices() {
    if ( ! us_is_allocator() ) return '<p class="us-empty">Access denied.</p>';

    $action        = sanitize_text_field( $_POST['us_inv_action'] ?? '' );
    $league_id     = absint( $_POST['us_inv_league'] ?? 0 );
    $month         = sanitize_text_field( $_POST['us_inv_month']  ?? '' );
    $notes         = sanitize_textarea_field( $_POST['us_inv_notes'] ?? '' );
    $inv_num       = sanitize_text_field( $_POST['us_inv_num'] ?? '' );
    $override_rate = isset( $_POST['us_inv_override_rate'] ) && $_POST['us_inv_override_rate'] !== ''
        ? floatval( $_POST['us_inv_override_rate'] )
        : 0.0;
    $deposit       = isset( $_POST['us_inv_deposit'] ) && $_POST['us_inv_deposit'] !== ''
        ? floatval( $_POST['us_inv_deposit'] )
        : 0.0;

    $league        = $league_id ? get_post( $league_id ) : null;
    $is_tournament = $league ? get_post_meta( $league_id, 'us_is_tournament', true ) === '1' : false;

    $step = 1;
    if ( $league && $action === 'review'  ) $step = 2;
    if ( $league && $action === 'preview' ) {
        $step = 3;
        if ( ! $inv_num ) $inv_num = us_next_invoice_number();
    }

    $all_leagues = get_posts( [
        'post_type'   => US_PT_LEAGUE,
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'post_status' => 'any',
        'meta_query'  => [ [ 'key' => 'us_is_archived', 'value' => '1', 'compare' => '!=' ] ],
    ] );

    $current_url = get_permalink();
    $org_name    = us_setting( 'org_name' ) ?: us_setting( 'org_short' );

    ob_start();
    ?>
    <div class="us-dashboard">
        <?php us_dashboard_notices(); ?>

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <h2 style="margin:0;">Invoices</h2>
            <?php if ( $step > 1 ) : ?>
                <a href="<?php echo esc_url( $current_url ); ?>" class="button" style="font-size:13px;">&#8592; Start over</a>
            <?php endif; ?>
        </div>

        <?php if ( $step === 1 ) : ?>
        <!-- ── Step 1: League select + AJAX month list ─────────── -->
        <div class="us-invoice-step-card">
            <h3 class="us-invoice-step-title"><span class="us-invoice-step-num">1</span> Select league &amp; period</h3>
            <form method="post" id="us-inv-step1-form">
                <?php wp_nonce_field( 'us_assign_nonce', 'us_assign_nonce_field' ); ?>
                <input type="hidden" name="us_inv_action" value="review">
                <input type="hidden" name="us_inv_month"  id="us-inv-month-val" value="">

                <div class="us-form-group" style="max-width:420px;">
                    <label for="us_inv_league_sel">League / Tournament</label>
                    <select id="us_inv_league_sel" name="us_inv_league" required style="width:100%;">
                        <option value="">— Select —</option>
                        <?php foreach ( $all_leagues as $l ) :
                            $is_t = get_post_meta( $l->ID, 'us_is_tournament', true ) === '1';
                        ?>
                            <option value="<?php echo $l->ID; ?>"
                                    data-tournament="<?php echo $is_t ? '1' : '0'; ?>">
                                <?php echo esc_html( $l->post_title ); ?><?php echo $is_t ? ' (Tournament)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="us-inv-month-section" style="display:none;max-width:420px;">
                    <div id="us-inv-month-loading" style="display:none;color:#666;font-size:13px;padding:10px 0;">Loading available months…</div>
                    <div id="us-inv-month-list"></div>
                    <div id="us-inv-tourney-msg" style="display:none;margin-bottom:16px;padding:12px 14px;background:#f0f7ee;border:1px solid #b7ddb0;border-radius:6px;font-size:13px;color:#333;">
                        All confirmed games in this tournament will be included.
                    </div>
                </div>

                <button type="submit" id="us-inv-step1-submit" class="button button-primary" style="display:none;">Review invoice &rarr;</button>
            </form>
        </div>

        <script>
        (function(){
            var leagueSel  = document.getElementById('us_inv_league_sel');
            var section    = document.getElementById('us-inv-month-section');
            var loading    = document.getElementById('us-inv-month-loading');
            var monthList  = document.getElementById('us-inv-month-list');
            var tourneyMsg = document.getElementById('us-inv-tourney-msg');
            var monthVal   = document.getElementById('us-inv-month-val');
            var submitBtn  = document.getElementById('us-inv-step1-submit');
            var ajaxUrl    = '<?php echo admin_url( 'admin-ajax.php' ); ?>';
            var nonce      = '<?php echo wp_create_nonce( 'us_assign_nonce' ); ?>';

            leagueSel.addEventListener('change', function() {
                var id = this.value;

                // Reset
                section.style.display    = 'none';
                monthList.innerHTML      = '';
                tourneyMsg.style.display = 'none';
                submitBtn.style.display  = 'none';
                monthVal.value           = '';

                if ( ! id ) return;

                loading.style.display = '';
                section.style.display = '';

                fetch( ajaxUrl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    new URLSearchParams({ action: 'us_invoice_get_months', nonce: nonce, league_id: id }),
                })
                .then( function(r) { return r.json(); } )
                .then( function(res) {
                    loading.style.display = 'none';

                    if ( ! res.success ) {
                        monthList.innerHTML = '<p style="color:#b32d2e;font-size:13px;">' + ( res.data || 'Error loading months.' ) + '</p>';
                        return;
                    }

                    var data = res.data;

                    if ( data.tournament ) {
                        tourneyMsg.style.display = '';
                        submitBtn.style.display  = '';
                        return;
                    }

                    if ( ! data.months || ! data.months.length ) {
                        monthList.innerHTML = '<p style="color:#888;font-size:13px;margin-bottom:12px;">No months with confirmed games found for this league.</p>';
                        return;
                    }

                    var html = '<div class="us-form-group"><label style="display:block;margin-bottom:8px;font-weight:600;">Month(s)</label>';
                    data.months.forEach( function(m, i) {
                        var checked = ( i === data.months.length - 1 ) ? ' checked' : '';
                        html += '<label style="display:flex;align-items:center;gap:10px;padding:9px 14px;border:1px solid #dde3ea;border-radius:6px;cursor:pointer;margin-bottom:6px;font-size:14px;transition:background .1s;" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'\'">'
                              + '<input type="checkbox" class="us-inv-month-cb" value="' + m.value + '"' + checked + ' style="margin:0;flex-shrink:0;">'
                              + '<span style="flex:1;">' + m.label + '</span>'
                              + '<span style="color:#888;font-size:12px;">' + m.games + ' game' + ( m.games !== 1 ? 's' : '' ) + '</span>'
                              + '</label>';
                    } );
                    html += '</div>';
                    monthList.innerHTML = html;

                    function syncMonthVal() {
                        var checked = Array.from( monthList.querySelectorAll('.us-inv-month-cb:checked') ).map( function(c) { return c.value; } );
                        monthVal.value = checked.join(',');
                        submitBtn.style.display = checked.length ? '' : 'none';
                    }
                    monthList.querySelectorAll('.us-inv-month-cb').forEach( function(cb) {
                        cb.addEventListener('change', syncMonthVal);
                    } );
                    syncMonthVal();
                } )
                .catch( function() {
                    loading.style.display = 'none';
                    monthList.innerHTML = '<p style="color:#b32d2e;font-size:13px;">Network error — please try again.</p>';
                } );
            });
        })();
        </script>

        <?php elseif ( $step === 2 ) :
            $months_arr = $is_tournament ? [] : array_values( array_filter( array_map( 'trim', explode( ',', $month ) ) ) );
            $breakdown  = us_get_invoice_breakdown( $league_id, $months_arr );
            $rows       = $breakdown['rows'];
            $totals     = $breakdown['totals'];
            $rates      = $breakdown['rates'];

            if ( $is_tournament ) {
                $t_start = get_post_meta( $league_id, 'us_tourney_start', true );
                $t_end   = get_post_meta( $league_id, 'us_tourney_end',   true );
                $period  = ( $t_start && $t_end )
                    ? date( 'M j', strtotime( $t_start ) ) . ' – ' . date( 'M j, Y', strtotime( $t_end ) )
                    : $league->post_title;
            } else {
                $period = us_invoice_period_label( $months_arr );
            }

            $contact_email = get_post_meta( $league_id, 'us_contact_email', true );
            $contact_name  = get_post_meta( $league_id, 'us_contact_name',  true );
            $show_alloc    = $rates['alloc'] > 0;
            $show_admin    = $rates['admin'] > 0;

            // Group rows by month for display
            $rows_by_month = [];
            foreach ( $rows as $row ) {
                $rows_by_month[ $row['month_key'] ][] = $row;
            }
            ksort( $rows_by_month );
        ?>
        <!-- ── Step 2: Breakdown review ──────────────────────── -->
        <div style="margin-bottom:20px;">
            <h3 style="margin:0 0 4px;"><?php echo esc_html( $league->post_title ); ?></h3>
            <p style="margin:0;color:#888;font-size:14px;"><?php echo esc_html( $period ); ?></p>
        </div>

        <?php if ( empty( $rows ) ) : ?>
            <p class="us-notice us-notice-error">No confirmed games found for this period.</p>
        <?php else : ?>

        <!-- Summary cards -->
        <div class="us-pay-cards" style="margin-bottom:28px;">
            <div class="us-pay-card">
                <div class="us-pay-card__value"><?php echo $totals['games']; ?></div>
                <div class="us-pay-card__label">Games</div>
            </div>
            <div class="us-pay-card">
                <div class="us-pay-card__value"><?php echo $totals['slots']; ?></div>
                <div class="us-pay-card__label">Umpire slots</div>
            </div>
            <div class="us-pay-card">
                <div class="us-pay-card__value">$<?php echo number_format( $totals['umpire_pay'], 2 ); ?></div>
                <div class="us-pay-card__label">Umpire pay</div>
            </div>
            <?php if ( $show_alloc ) : ?>
            <div class="us-pay-card">
                <div class="us-pay-card__value">$<?php echo number_format( $totals['alloc'], 2 ); ?></div>
                <div class="us-pay-card__label">Allocator fees</div>
            </div>
            <?php endif; ?>
            <?php if ( $show_admin ) : ?>
            <div class="us-pay-card">
                <div class="us-pay-card__value">$<?php echo number_format( $totals['admin'], 2 ); ?></div>
                <div class="us-pay-card__label">Admin fees</div>
            </div>
            <?php endif; ?>
            <div class="us-pay-card us-pay-card--danger">
                <div class="us-pay-card__value">$<?php echo number_format( $totals['grand'], 2 ); ?></div>
                <div class="us-pay-card__label">Total due</div>
            </div>
        </div>

        <!-- Per-game breakdown grouped by month -->
        <div style="overflow-x:auto;margin-bottom:28px;">
        <table class="us-table" style="width:100%;min-width:560px;">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Game</th>
                    <th style="text-align:center;">Umpires</th>
                    <th style="text-align:right;">Umpire pay</th>
                    <?php if ( $show_alloc ) : ?>
                    <th style="text-align:right;">Alloc. fee</th>
                    <?php endif; ?>
                    <?php if ( $show_admin ) : ?>
                    <th style="text-align:right;">Admin fee</th>
                    <?php endif; ?>
                    <th style="text-align:right;">Game total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows_by_month as $mk => $month_rows ) :
                    $m_totals = [ 'slots' => 0, 'umpire_pay' => 0.0, 'alloc' => 0.0, 'admin' => 0.0, 'grand' => 0.0 ];
                    foreach ( $month_rows as $r ) {
                        $m_totals['slots']      += $r['slots'];
                        $m_totals['umpire_pay'] += $r['umpire_pay'];
                        $m_totals['alloc']      += $r['alloc'];
                        $m_totals['admin']      += $r['admin'];
                        $m_totals['grand']      += $r['total'];
                    }
                    $col_count = 4 + ( $show_alloc ? 1 : 0 ) + ( $show_admin ? 1 : 0 );
                    $month_label = date( 'F Y', strtotime( $mk . '-01' ) );
                ?>
                <?php if ( count( $rows_by_month ) > 1 ) : ?>
                <tr>
                    <td colspan="<?php echo $col_count; ?>" style="background:#f0f4f8;font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#555;padding:7px 12px;"><?php echo esc_html( $month_label ); ?></td>
                </tr>
                <?php endif; ?>
                <?php foreach ( $month_rows as $row ) : ?>
                <tr>
                    <td style="white-space:nowrap;"><?php echo esc_html( date( 'M j', strtotime( $row['date'] ) ) ); ?></td>
                    <td>
                        <?php echo esc_html( $row['title'] ); ?>
                        <?php if ( $row['is_dh'] ) : ?>
                            <span style="display:inline-block;background:#e8f4fd;color:#1a6396;font-size:10px;font-weight:700;padding:1px 5px;border-radius:3px;margin-left:4px;letter-spacing:.5px;">DH</span>
                        <?php endif; ?>
                        <?php if ( $row['is_postponed'] ) : ?>
                            <span style="display:inline-block;background:#fff3e0;color:#e65100;font-size:10px;font-weight:700;padding:1px 5px;border-radius:3px;margin-left:4px;letter-spacing:.5px;">Postponed</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;"><?php echo intval( $row['slots'] ); ?></td>
                    <td style="text-align:right;">$<?php echo number_format( $row['umpire_pay'], 2 ); ?></td>
                    <?php if ( $show_alloc ) : ?>
                    <td style="text-align:right;">$<?php echo number_format( $row['alloc'], 2 ); ?></td>
                    <?php endif; ?>
                    <?php if ( $show_admin ) : ?>
                    <td style="text-align:right;">$<?php echo number_format( $row['admin'], 2 ); ?></td>
                    <?php endif; ?>
                    <td style="text-align:right;font-weight:600;">$<?php echo number_format( $row['total'], 2 ); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ( count( $rows_by_month ) > 1 ) : ?>
                <tr style="background:#f8fafc;font-style:italic;color:#555;border-top:1px solid #dde3ea;">
                    <td colspan="3" style="text-align:right;padding-right:12px;font-size:13px;"><?php echo esc_html( $month_label ); ?> subtotal</td>
                    <td style="text-align:right;font-size:13px;">$<?php echo number_format( $m_totals['umpire_pay'], 2 ); ?></td>
                    <?php if ( $show_alloc ) : ?>
                    <td style="text-align:right;font-size:13px;">$<?php echo number_format( $m_totals['alloc'], 2 ); ?></td>
                    <?php endif; ?>
                    <?php if ( $show_admin ) : ?>
                    <td style="text-align:right;font-size:13px;">$<?php echo number_format( $m_totals['admin'], 2 ); ?></td>
                    <?php endif; ?>
                    <td style="text-align:right;font-size:13px;">$<?php echo number_format( $m_totals['grand'], 2 ); ?></td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:700;background:#f0f4f8;border-top:2px solid #c5cfd9;">
                    <td colspan="3" style="text-align:right;padding-right:12px;">Total</td>
                    <td style="text-align:right;">$<?php echo number_format( $totals['umpire_pay'], 2 ); ?></td>
                    <?php if ( $show_alloc ) : ?>
                    <td style="text-align:right;">$<?php echo number_format( $totals['alloc'], 2 ); ?></td>
                    <?php endif; ?>
                    <?php if ( $show_admin ) : ?>
                    <td style="text-align:right;">$<?php echo number_format( $totals['admin'], 2 ); ?></td>
                    <?php endif; ?>
                    <td style="text-align:right;color:#1a3a5c;font-size:16px;">$<?php echo number_format( $totals['grand'], 2 ); ?></td>
                </tr>
            </tfoot>
        </table>
        </div>

        <?php if ( ! empty( $breakdown['by_rate'] ) ) : ?>
        <!-- Rate summary -->
        <div style="margin-bottom:28px;">
            <h4 style="margin:0 0 10px;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#555;">Rate Summary</h4>
            <table style="border-collapse:collapse;font-size:13px;">
                <thead>
                    <tr style="background:#f0f4f8;">
                        <th style="padding:7px 24px 7px 0;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#555;border-bottom:2px solid #dde3ea;white-space:nowrap;">Rate / slot</th>
                        <th style="padding:7px 20px;text-align:center;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#555;border-bottom:2px solid #dde3ea;">Games</th>
                        <th style="padding:7px 20px;text-align:center;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#555;border-bottom:2px solid #dde3ea;">Slots</th>
                        <th style="padding:7px 0 7px 20px;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#555;border-bottom:2px solid #dde3ea;">Umpire pay</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $breakdown['by_rate'] as $rd ) : ?>
                    <tr>
                        <td style="padding:7px 24px 7px 0;border-bottom:1px solid #f0f0f0;white-space:nowrap;">$<?php echo number_format( $rd['rate'], 2 ); ?>/slot</td>
                        <td style="padding:7px 20px;text-align:center;border-bottom:1px solid #f0f0f0;"><?php echo $rd['games']; ?></td>
                        <td style="padding:7px 20px;text-align:center;border-bottom:1px solid #f0f0f0;"><?php echo $rd['slots']; ?></td>
                        <td style="padding:7px 0 7px 20px;text-align:right;border-bottom:1px solid #f0f0f0;">$<?php echo number_format( $rd['umpire_pay'], 2 ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight:700;border-top:2px solid #c5cfd9;">
                        <td style="padding:8px 24px 4px 0;">Subtotal</td>
                        <td style="padding:8px 20px 4px;text-align:center;"><?php echo $totals['games']; ?></td>
                        <td style="padding:8px 20px 4px;text-align:center;"><?php echo $totals['slots']; ?></td>
                        <td style="padding:8px 0 4px 20px;text-align:right;">$<?php echo number_format( $totals['umpire_pay'], 2 ); ?></td>
                    </tr>
                    <?php if ( $show_alloc ) : ?>
                    <tr style="color:#666;font-size:12px;">
                        <td colspan="3" style="padding:3px 24px 3px 0;text-align:right;">+ Allocator fees (<?php echo $totals['slots']; ?> slots &times; $<?php echo number_format( $rates['alloc'], 2 ); ?>)</td>
                        <td style="padding:3px 0 3px 20px;text-align:right;">$<?php echo number_format( $totals['alloc'], 2 ); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ( $show_admin ) : ?>
                    <tr style="color:#666;font-size:12px;">
                        <td colspan="3" style="padding:3px 24px 3px 0;text-align:right;">+ Admin fees (<?php echo $totals['slots']; ?> slots &times; $<?php echo number_format( $rates['admin'], 2 ); ?>)</td>
                        <td style="padding:3px 0 3px 20px;text-align:right;">$<?php echo number_format( $totals['admin'], 2 ); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr style="background:#e8f0f8;">
                        <td colspan="3" style="padding:10px 24px 10px 0;text-align:right;font-weight:700;font-size:13px;color:#1a3a5c;">Calculated invoice total</td>
                        <td style="padding:10px 0 10px 20px;text-align:right;font-weight:700;font-size:16px;color:#1a3a5c;">$<?php echo number_format( $totals['grand'], 2 ); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $breakdown['unbilled'] ) ) :
            $unbilled_unassigned = array_filter( $breakdown['unbilled'], fn( $r ) => ! $r['is_postponed'] );
            $unbilled_postponed  = array_filter( $breakdown['unbilled'], fn( $r ) =>   $r['is_postponed'] );
            $unbilled_by_month   = [];
            foreach ( $breakdown['unbilled'] as $ur ) {
                $unbilled_by_month[ $ur['month_key'] ][] = $ur;
            }
            ksort( $unbilled_by_month );
        ?>
        <!-- Not-billed informational section -->
        <details style="margin-bottom:28px;border:1px solid #dde3ea;border-radius:6px;overflow:hidden;" open>
            <summary style="cursor:pointer;padding:12px 16px;background:#f8fafc;font-weight:700;font-size:13px;list-style:none;display:flex;align-items:center;gap:10px;">
                <span>Games Not Included in This Invoice</span>
                <?php if ( count( $unbilled_unassigned ) ) : ?>
                <span style="background:#e3eaf2;color:#555;font-size:11px;font-weight:700;padding:2px 7px;border-radius:10px;"><?php echo count( $unbilled_unassigned ); ?> unassigned</span>
                <?php endif; ?>
                <?php if ( count( $unbilled_postponed ) ) : ?>
                <span style="background:#fff3e0;color:#e65100;font-size:11px;font-weight:700;padding:2px 7px;border-radius:10px;"><?php echo count( $unbilled_postponed ); ?> postponed</span>
                <?php endif; ?>
            </summary>
            <div style="padding:14px 16px;">
                <p style="margin:0 0 14px;font-size:13px;color:#666;">The following games are shown for reference only and are <strong>not included</strong> in the billing above. No charges apply.</p>
                <div style="overflow-x:auto;">
                <table class="us-table" style="width:100%;min-width:400px;font-size:13px;">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Game</th>
                            <th style="text-align:center;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $unbilled_by_month as $umk => $urows ) :
                            $umonth_label = date( 'F Y', strtotime( $umk . '-01' ) );
                        ?>
                        <?php if ( count( $unbilled_by_month ) > 1 ) : ?>
                        <tr>
                            <td colspan="3" style="background:#f0f4f8;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#555;padding:6px 12px;"><?php echo esc_html( $umonth_label ); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ( $urows as $ur ) : ?>
                        <tr>
                            <td style="white-space:nowrap;color:#888;"><?php echo esc_html( date( 'M j', strtotime( $ur['date'] ) ) ); ?></td>
                            <td>
                                <?php echo esc_html( $ur['title'] ); ?>
                                <?php if ( $ur['is_dh'] ) : ?>
                                <span style="display:inline-block;background:#e8f4fd;color:#1a6396;font-size:10px;font-weight:700;padding:1px 5px;border-radius:3px;margin-left:4px;letter-spacing:.5px;">DH</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <?php if ( $ur['is_postponed'] ) : ?>
                                <span style="display:inline-block;background:#fff3e0;color:#e65100;font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px;">Postponed</span>
                                <?php else : ?>
                                <span style="display:inline-block;background:#f0f0f0;color:#888;font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px;">No umpires</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </details>
        <?php endif; ?>

        <?php if ( ! $contact_email ) : ?>
        <p style="margin-bottom:16px;padding:10px 14px;background:#fff8e6;border:1px solid #f0c040;border-radius:6px;font-size:13px;">
            &#9888; No contact email on file — you can still generate and download this invoice but cannot email it.
        </p>
        <?php endif; ?>

        <form method="post" style="max-width:620px;">
            <?php wp_nonce_field( 'us_assign_nonce', 'us_assign_nonce_field' ); ?>
            <input type="hidden" name="us_inv_action" value="preview">
            <input type="hidden" name="us_inv_league" value="<?php echo $league_id; ?>">
            <input type="hidden" name="us_inv_month"  value="<?php echo esc_attr( $month ); ?>">

            <div class="us-form-group" style="max-width:360px;">
                <label for="us_inv_override_rate">Rate override per slot <span style="font-weight:400;color:#999;">(optional)</span></label>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:15px;color:#555;">$</span>
                    <input type="number" id="us_inv_override_rate" name="us_inv_override_rate" min="0" step="0.01" placeholder="e.g. 46.00" style="width:130px;" value="">
                    <span style="font-size:13px;color:#888;">/ slot</span>
                </div>
            </div>

            <div class="us-form-group" style="max-width:360px;">
                <label for="us_inv_deposit">Deposit received <span style="font-weight:400;color:#999;">(optional)</span></label>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:15px;color:#555;">$</span>
                    <input type="number" id="us_inv_deposit" name="us_inv_deposit" min="0" step="0.01" placeholder="e.g. 200.00" style="width:130px;" value="">
                </div>
            </div>

            <p id="us-inv-total-preview" style="margin:0 0 20px;font-size:13px;color:#555;"></p>

            <div class="us-form-group">
                <label for="us_inv_notes">Notes <span style="font-weight:400;color:#999;">(optional — appears on the invoice)</span></label>
                <textarea id="us_inv_notes" name="us_inv_notes" rows="3" style="width:100%;"></textarea>
            </div>

            <button type="submit" class="button button-primary">Generate invoice &rarr;</button>
        </form>

        <script>
        (function(){
            var overrideInput = document.getElementById('us_inv_override_rate');
            var depositInput  = document.getElementById('us_inv_deposit');
            var preview       = document.getElementById('us-inv-total-preview');
            var slots         = <?php echo intval( $totals['slots'] ); ?>;
            var calcTotal     = <?php echo floatval( $totals['grand'] ); ?>;

            function updatePreview() {
                var rate    = parseFloat( overrideInput.value );
                var deposit = parseFloat( depositInput.value );
                var total   = ( overrideInput.value !== '' && !isNaN( rate ) && rate > 0 )
                    ? rate * slots
                    : calcTotal;
                var due     = ( depositInput.value !== '' && !isNaN( deposit ) && deposit > 0 )
                    ? Math.max( 0, total - deposit )
                    : total;

                var parts = [];
                if ( overrideInput.value !== '' && !isNaN( rate ) && rate > 0 ) {
                    parts.push( 'Invoice total: <strong>$' + total.toFixed(2) + '</strong> <span style="color:#aaa;">(' + slots + ' slots &times; $' + rate.toFixed(2) + ')</span>' );
                } else {
                    parts.push( 'Invoice total: <strong>$' + total.toFixed(2) + '</strong>' );
                }
                if ( depositInput.value !== '' && !isNaN( deposit ) && deposit > 0 ) {
                    parts.push( 'Amount due: <strong>$' + due.toFixed(2) + '</strong> <span style="color:#aaa;">(after $' + deposit.toFixed(2) + ' deposit)</span>' );
                }
                preview.innerHTML = parts.join( ' &nbsp;·&nbsp; ' );
            }
            overrideInput.addEventListener('input', updatePreview);
            depositInput.addEventListener('input', updatePreview);
            updatePreview();
        })();
        </script>

        <?php endif; // rows ?>

        <?php elseif ( $step === 3 ) :
            $months_arr = $is_tournament ? [] : array_values( array_filter( array_map( 'trim', explode( ',', $month ) ) ) );
            $breakdown     = us_get_invoice_breakdown( $league_id, $months_arr );
            $totals        = $breakdown['totals'];
            $rates         = $breakdown['rates'];
            $show_alloc    = $rates['alloc'] > 0;
            $show_admin    = $rates['admin'] > 0;
            $invoice_total = $override_rate > 0 ? round( $override_rate * $totals['slots'], 2 ) : $totals['grand'];
            $amount_due    = $deposit > 0 ? max( 0.0, $invoice_total - $deposit ) : $invoice_total;

            if ( $is_tournament ) {
                $t_start = get_post_meta( $league_id, 'us_tourney_start', true );
                $t_end   = get_post_meta( $league_id, 'us_tourney_end',   true );
                $period  = ( $t_start && $t_end )
                    ? date( 'M j', strtotime( $t_start ) ) . ' – ' . date( 'M j, Y', strtotime( $t_end ) )
                    : $league->post_title;
            } else {
                $period = us_invoice_period_label( $months_arr );
            }

            $contact_name  = get_post_meta( $league_id, 'us_contact_name',  true );
            $contact_email = get_post_meta( $league_id, 'us_contact_email', true );
            $contact_phone = get_post_meta( $league_id, 'us_contact_phone', true );
            $assignor_name = us_setting( 'assignor_name' );
            $inv_date      = date( 'F j, Y' );
            $logo_url      = us_get_logo_url( 'medium' );
        ?>
        <!-- ── Step 3: Invoice preview ────────────────────────── -->
        <div style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <button id="us-inv-download" class="button button-primary">&#8659; Download PDF</button>
            <?php if ( $contact_email ) : ?>
                <button id="us-inv-send-email" class="button"
                        data-league="<?php echo $league_id; ?>"
                        data-inv-num="<?php echo esc_attr( $inv_num ); ?>"
                        data-inv-date="<?php echo esc_attr( $inv_date ); ?>"
                        data-period="<?php echo esc_attr( $period ); ?>"
                        data-month="<?php echo esc_attr( $month ); ?>"
                        data-notes="<?php echo esc_attr( $notes ); ?>"
                        data-override="<?php echo esc_attr( $override_rate > 0 ? $override_rate : '' ); ?>"
                        data-deposit="<?php echo esc_attr( $deposit > 0 ? $deposit : '' ); ?>">
                    &#9993; Email to <?php echo esc_html( $contact_name ?: $contact_email ); ?>
                </button>
            <?php endif; ?>
            <span id="us-inv-email-status" style="font-size:13px;"></span>
        </div>

        <!-- Invoice document -->
        <div id="us-invoice-content" class="us-invoice-doc">

            <div class="us-invoice-doc__header">
                <?php if ( $logo_url ) : ?>
                    <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $org_name ); ?>" class="us-invoice-doc__logo">
                <?php else : ?>
                    <div class="us-invoice-doc__org-name"><?php echo esc_html( $org_name ); ?></div>
                <?php endif; ?>
                <div class="us-invoice-doc__header-right">
                    <div class="us-invoice-doc__inv-label">INVOICE</div>
                    <div class="us-invoice-doc__inv-num"><?php echo esc_html( $inv_num ); ?></div>
                </div>
            </div>

            <div class="us-invoice-doc__meta-row">
                <div class="us-invoice-doc__bill-to">
                    <div class="us-invoice-doc__section-label">Bill To</div>
                    <div class="us-invoice-doc__bill-name"><?php echo esc_html( $league->post_title ); ?></div>
                    <?php if ( $contact_name )  echo '<div class="us-invoice-doc__bill-detail">' . esc_html( $contact_name )  . '</div>'; ?>
                    <?php if ( $contact_email ) echo '<div class="us-invoice-doc__bill-detail">' . esc_html( $contact_email ) . '</div>'; ?>
                    <?php if ( $contact_phone ) echo '<div class="us-invoice-doc__bill-detail">' . esc_html( $contact_phone ) . '</div>'; ?>
                </div>
                <div class="us-invoice-doc__details">
                    <div class="us-invoice-doc__section-label">Invoice Details</div>
                    <table class="us-invoice-doc__details-table">
                        <tr><td>Invoice #</td><td><?php echo esc_html( $inv_num ); ?></td></tr>
                        <tr><td>Date</td><td><?php echo esc_html( $inv_date ); ?></td></tr>
                        <tr><td>Period</td><td><?php echo esc_html( $period ); ?></td></tr>
                        <tr><td>From</td><td><?php echo esc_html( $assignor_name ?: $org_name ); ?></td></tr>
                    </table>
                </div>
            </div>

            <table class="us-invoice-doc__line-table">
                <thead>
                    <tr>
                        <th class="us-invoice-doc__col-desc">Description</th>
                        <th class="us-invoice-doc__col-qty">Slots</th>
                        <th class="us-invoice-doc__col-amt">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            Umpire services — <?php echo esc_html( $league->post_title ); ?>
                            <div class="us-invoice-doc__line-sub">
                                <?php echo esc_html( $period ); ?> &bull; <?php echo intval( $totals['games'] ); ?> games &bull; <?php echo intval( $totals['slots'] ); ?> umpire slots
                                <?php if ( $override_rate > 0 ) : ?>
                                &bull; $<?php echo number_format( $override_rate, 2 ); ?>/slot (flat rate)
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="us-invoice-doc__cell-center"><?php echo intval( $totals['slots'] ); ?></td>
                        <td class="us-invoice-doc__cell-right">$<?php echo number_format( $invoice_total, 2 ); ?></td>
                    </tr>
                </tbody>
                <tfoot>
                    <?php if ( $deposit > 0 ) : ?>
                    <tr>
                        <td colspan="2" class="us-invoice-doc__total-label" style="font-weight:400;font-size:13px;color:#888;">Invoice Total</td>
                        <td class="us-invoice-doc__total-amt" style="font-size:14px;color:#888;">$<?php echo number_format( $invoice_total, 2 ); ?></td>
                    </tr>
                    <tr>
                        <td colspan="2" class="us-invoice-doc__total-label" style="font-weight:400;font-size:13px;color:#888;">Deposit Received</td>
                        <td class="us-invoice-doc__total-amt" style="font-size:14px;color:#888;">&minus;$<?php echo number_format( $deposit, 2 ); ?></td>
                    </tr>
                    <tr>
                        <td colspan="2" class="us-invoice-doc__total-label">Amount Due</td>
                        <td class="us-invoice-doc__total-amt">$<?php echo number_format( $amount_due, 2 ); ?></td>
                    </tr>
                    <?php else : ?>
                    <tr>
                        <td colspan="2" class="us-invoice-doc__total-label">Total Due</td>
                        <td class="us-invoice-doc__total-amt">$<?php echo number_format( $invoice_total, 2 ); ?></td>
                    </tr>
                    <?php endif; ?>
                </tfoot>
            </table>

            <?php if ( ! empty( $breakdown['by_rate'] ) ) : ?>
            <div style="margin:16px 0;padding:12px 16px;background:#f8f9fa;border-radius:5px;border:1px solid #eee;font-size:12px;color:#666;line-height:1.8;">
                <div style="font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#aaa;margin-bottom:6px;">Services breakdown</div>
                <?php if ( $override_rate > 0 ) : ?>
                    <div><?php echo intval( $totals['slots'] ); ?> umpire slots &times; $<?php echo number_format( $override_rate, 2 ); ?>/slot (flat rate) = <strong style="color:#333;">$<?php echo number_format( $invoice_total, 2 ); ?></strong></div>
                <?php else : ?>
                    <?php foreach ( $breakdown['by_rate'] as $rd ) : ?>
                    <div><?php echo $rd['games']; ?> game<?php echo $rd['games'] !== 1 ? 's' : ''; ?> &times; $<?php echo number_format( $rd['rate'], 2 ); ?>/slot = $<?php echo number_format( $rd['umpire_pay'], 2 ); ?></div>
                    <?php endforeach; ?>
                    <?php if ( $show_alloc || $show_admin ) : ?>
                    <div style="color:#aaa;margin-top:2px;">Includes allocator &amp; admin fees</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $breakdown['unbilled'] ) ) :
                $ub_unassigned = count( array_filter( $breakdown['unbilled'], fn( $r ) => ! $r['is_postponed'] ) );
                $ub_postponed  = count( array_filter( $breakdown['unbilled'], fn( $r ) =>   $r['is_postponed'] ) );
                $ub_parts      = array_filter( [
                    $ub_unassigned ? $ub_unassigned . ' unassigned' : '',
                    $ub_postponed  ? $ub_postponed  . ' postponed (not paid)' : '',
                ] );
            ?>
            <div style="margin:12px 0;padding:10px 14px;background:#f8f9fa;border:1px solid #eee;border-radius:4px;font-size:12px;color:#888;line-height:1.6;">
                <span style="font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#bbb;display:block;margin-bottom:3px;">Not included in this invoice</span>
                <?php echo esc_html( implode( ' · ', $ub_parts ) ); ?> — no charge
            </div>
            <?php endif; ?>

            <?php if ( $notes ) : ?>
            <div class="us-invoice-doc__notes">
                <div class="us-invoice-doc__section-label">Notes</div>
                <p><?php echo nl2br( esc_html( $notes ) ); ?></p>
            </div>
            <?php endif; ?>

            <div class="us-invoice-doc__footer">
                Issued by <?php echo esc_html( $assignor_name ?: $org_name ); ?> &bull; <?php echo esc_html( $org_name ); ?>
            </div>

        </div><!-- /#us-invoice-content -->

        <form id="us-inv-repost" method="post" style="display:none;">
            <?php wp_nonce_field( 'us_assign_nonce', 'us_assign_nonce_field' ); ?>
            <input type="hidden" name="us_inv_action" value="preview">
            <input type="hidden" name="us_inv_league" value="<?php echo $league_id; ?>">
            <input type="hidden" name="us_inv_month"  value="<?php echo esc_attr( $month ); ?>">
            <input type="hidden" name="us_inv_notes"         value="<?php echo esc_attr( $notes ); ?>">
            <input type="hidden" name="us_inv_num"           value="<?php echo esc_attr( $inv_num ); ?>">
            <input type="hidden" name="us_inv_override_rate" value="<?php echo esc_attr( $override_rate > 0 ? $override_rate : '' ); ?>">
            <input type="hidden" name="us_inv_deposit"       value="<?php echo esc_attr( $deposit > 0 ? $deposit : '' ); ?>">
        </form>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
        <script>
        (function(){
            document.getElementById('us-inv-download').addEventListener('click', function(){
                var btn = this;
                btn.disabled    = true;
                btn.textContent = 'Generating…';
                html2pdf().set({
                    margin:      [10, 10, 10, 10],
                    filename:    '<?php echo esc_js( $inv_num ); ?>.pdf',
                    image:       { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 2, useCORS: true },
                    jsPDF:       { unit: 'mm', format: 'letter', orientation: 'portrait' },
                }).from( document.getElementById('us-invoice-content') ).save().then(function(){
                    btn.disabled    = false;
                    btn.textContent = '⬇ Download PDF';
                });
            });

            var emailBtn = document.getElementById('us-inv-send-email');
            if ( emailBtn ) {
                emailBtn.addEventListener('click', function(){
                    if ( ! confirm('Send invoice <?php echo esc_js( $inv_num ); ?> to <?php echo esc_js( $contact_email ?? '' ); ?>?') ) return;
                    var btn    = this;
                    var status = document.getElementById('us-inv-email-status');
                    btn.disabled    = true;
                    btn.textContent = 'Sending…';
                    status.textContent = '';

                    fetch('<?php echo admin_url( 'admin-ajax.php' ); ?>', {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body:    new URLSearchParams({
                            action:        'us_send_invoice',
                            nonce:         '<?php echo wp_create_nonce( 'us_assign_nonce' ); ?>',
                            league_id:     btn.dataset.league,
                            inv_num:       btn.dataset.invNum,
                            inv_date:      btn.dataset.invDate,
                            period:        btn.dataset.period,
                            month:         btn.dataset.month,
                            notes:         btn.dataset.notes,
                            override_rate: btn.dataset.override || '',
                            deposit:       btn.dataset.deposit || '',
                        }),
                    })
                    .then( function(r) { return r.json(); } )
                    .then( function(res) {
                        if ( res.success ) {
                            status.style.color = '#0a6b0a';
                            status.textContent = '✓ ' + res.data;
                            btn.textContent    = '✓ Sent';
                        } else {
                            status.style.color = '#b32d2e';
                            status.textContent = '✗ ' + ( res.data || 'Send failed' );
                            btn.disabled    = false;
                            btn.textContent = 'Retry email';
                        }
                    })
                    .catch( function() {
                        status.style.color = '#b32d2e';
                        status.textContent = '✗ Network error';
                        btn.disabled    = false;
                        btn.textContent = 'Retry email';
                    });
                });
            }
        })();
        </script>

        <style>
        .us-invoice-doc {
            max-width: 780px;
            background: #fff;
            border: 1px solid #dde3ea;
            border-radius: 8px;
            padding: 48px 52px;
            margin-top: 8px;
            font-family: Arial, sans-serif;
            color: #333;
        }
        .us-invoice-doc__header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 36px;
            padding-bottom: 24px;
            border-bottom: 3px solid #1a3a5c;
        }
        .us-invoice-doc__logo { max-height: 60px; max-width: 200px; object-fit: contain; }
        .us-invoice-doc__org-name { font-size: 22px; font-weight: 700; color: #1a3a5c; }
        .us-invoice-doc__header-right { text-align: right; }
        .us-invoice-doc__inv-label { font-size: 12px; color: #999; text-transform: uppercase; letter-spacing: 1px; }
        .us-invoice-doc__inv-num { font-size: 20px; font-weight: 700; color: #1a3a5c; margin-top: 2px; }
        .us-invoice-doc__meta-row { display: flex; justify-content: space-between; margin-bottom: 36px; gap: 24px; }
        .us-invoice-doc__bill-to, .us-invoice-doc__details { flex: 1; }
        .us-invoice-doc__section-label { font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
        .us-invoice-doc__bill-name { font-size: 16px; font-weight: 700; color: #1a3a5c; margin-bottom: 4px; }
        .us-invoice-doc__bill-detail { font-size: 13px; color: #555; line-height: 1.6; }
        .us-invoice-doc__details-table { font-size: 13px; border-collapse: collapse; width: 100%; }
        .us-invoice-doc__details-table td { padding: 2px 0; color: #555; }
        .us-invoice-doc__details-table td:first-child { color: #999; padding-right: 12px; white-space: nowrap; }
        .us-invoice-doc__line-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .us-invoice-doc__line-table thead tr { background: #f0f4f8; }
        .us-invoice-doc__line-table th { padding: 10px 12px; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #555; font-weight: 700; text-align: left; border-bottom: 2px solid #dde3ea; }
        .us-invoice-doc__line-table td { padding: 14px 12px; font-size: 14px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
        .us-invoice-doc__col-qty, .us-invoice-doc__col-amt { width: 80px; }
        .us-invoice-doc__col-qty th { text-align: center; }
        .us-invoice-doc__col-amt th { text-align: right; }
        .us-invoice-doc__cell-center { text-align: center; }
        .us-invoice-doc__cell-right { text-align: right; }
        .us-invoice-doc__line-sub { font-size: 12px; color: #888; margin-top: 3px; }
        .us-invoice-doc__line-table tfoot td { border-top: 2px solid #1a3a5c; border-bottom: none; padding-top: 14px; }
        .us-invoice-doc__total-label { text-align: right; font-weight: 700; font-size: 14px; color: #1a3a5c; }
        .us-invoice-doc__total-amt { text-align: right; font-size: 22px; font-weight: 700; color: #1a3a5c; }
        .us-invoice-doc__notes { margin-top: 28px; padding: 16px; background: #f8f9fa; border-radius: 6px; border-left: 3px solid #1a3a5c; }
        .us-invoice-doc__notes p { margin: 6px 0 0; font-size: 13px; color: #555; }
        .us-invoice-doc__footer { margin-top: 36px; padding-top: 16px; border-top: 1px solid #eee; font-size: 12px; color: #aaa; text-align: center; }
        @media print {
            .us-dashboard > *:not(.us-invoice-doc) { display: none !important; }
            .us-invoice-doc { border: none; padding: 0; box-shadow: none; }
        }
        </style>

        <?php endif; // step 3 ?>

    </div><!-- /.us-dashboard -->

    <style>
    .us-invoice-step-card {
        max-width: 620px;
        background: #fff;
        border: 1px solid #dde3ea;
        border-radius: 8px;
        padding: 28px 32px;
    }
    .us-invoice-step-title {
        margin: 0 0 20px;
        font-size: 16px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .us-invoice-step-num {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px; height: 28px;
        background: #1a3a5c;
        color: #fff;
        border-radius: 50%;
        font-size: 14px;
        font-weight: 700;
        flex-shrink: 0;
    }
    </style>

    <?php
    return ob_get_clean();
}
