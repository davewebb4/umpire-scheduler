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
                [ 'key' => 'us_game_id', 'value' => $game_id,    'compare' => '=' ],
                [ 'key' => 'us_status',  'value' => 'confirmed', 'compare' => '=' ],
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

// ── Build invoice breakdown ───────────────────────────────────
// Returns per-game rows and totals. Umpire pay comes from actual
// us_pay_amount on each assignment (respects manual overrides).
// Alloc/admin fees are charged per umpire slot, matching pay-reports logic.
function us_get_invoice_breakdown( $league_id, $month = '' ) {
    $alloc_rate = floatval( get_post_meta( $league_id, 'us_allocator_rate', true ) );
    $admin_rate = floatval( get_post_meta( $league_id, 'us_admin_rate',     true ) );

    $meta_query = [ [ 'key' => 'us_league_id', 'value' => $league_id, 'compare' => '=' ] ];
    if ( $month ) {
        $start = $month . '-01';
        $end   = date( 'Y-m-t', strtotime( $start ) );
        $meta_query[] = [ 'key' => 'us_game_date', 'value' => $start, 'compare' => '>=' ];
        $meta_query[] = [ 'key' => 'us_game_date', 'value' => $end,   'compare' => '<=' ];
    }

    $games = get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => $meta_query,
    ] );

    $rows   = [];
    $totals = [
        'games'      => 0,
        'slots'      => 0,
        'umpire_pay' => 0.0,
        'alloc'      => 0.0,
        'admin'      => 0.0,
        'grand'      => 0.0,
    ];

    foreach ( $games as $game ) {
        $assignments = get_posts( [
            'post_type'   => US_PT_ASSIGNMENT,
            'numberposts' => -1,
            'post_status' => 'publish',
            'fields'      => 'ids',
            'meta_query'  => [
                [ 'key' => 'us_game_id', 'value' => $game->ID,   'compare' => '=' ],
                [ 'key' => 'us_status',  'value' => 'confirmed', 'compare' => '=' ],
            ],
        ] );
        if ( ! $assignments ) continue;

        $slot_count = count( $assignments );
        $umpire_pay = 0.0;
        foreach ( $assignments as $asn_id ) {
            $umpire_pay += floatval( get_post_meta( $asn_id, 'us_pay_amount', true ) );
        }

        $game_alloc = $alloc_rate * $slot_count;
        $game_admin = $admin_rate * $slot_count;
        $game_total = $umpire_pay + $game_alloc + $game_admin;

        $rows[] = [
            'date'       => get_post_meta( $game->ID, 'us_game_date', true ),
            'title'      => $game->post_title,
            'slots'      => $slot_count,
            'is_dh'      => get_post_meta( $game->ID, 'us_double_header', true ) === '1',
            'umpire_pay' => $umpire_pay,
            'alloc'      => $game_alloc,
            'admin'      => $game_admin,
            'total'      => $game_total,
        ];

        $totals['games']++;
        $totals['slots']      += $slot_count;
        $totals['umpire_pay'] += $umpire_pay;
        $totals['alloc']      += $game_alloc;
        $totals['admin']      += $game_admin;
        $totals['grand']      += $game_total;
    }

    usort( $rows, fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );

    return [
        'rows'   => $rows,
        'totals' => $totals,
        'rates'  => [ 'alloc' => $alloc_rate, 'admin' => $admin_rate ],
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
    $month     = sanitize_text_field( $_POST['month']    ?? '' );
    $notes     = sanitize_textarea_field( $_POST['notes'] ?? '' );

    $league = get_post( $league_id );
    if ( ! $league ) wp_send_json_error( 'League not found' );

    $contact_email = get_post_meta( $league_id, 'us_contact_email', true );
    if ( ! $contact_email ) wp_send_json_error( 'No contact email on file for this league.' );

    $is_tournament = get_post_meta( $league_id, 'us_is_tournament', true ) === '1';

    // Legacy path: pay-reports modal passes game_count + rate instead of month
    $legacy_game_count = absint( $_POST['game_count'] ?? 0 );
    $legacy_rate       = floatval( $_POST['rate']       ?? 0 );
    if ( ! $month && $legacy_game_count && $legacy_rate ) {
        $legacy_total = $legacy_game_count * $legacy_rate;
        $breakdown = [
            'rows'   => [],
            'totals' => [ 'games' => $legacy_game_count, 'slots' => $legacy_game_count, 'umpire_pay' => $legacy_total, 'alloc' => 0.0, 'admin' => 0.0, 'grand' => $legacy_total ],
            'rates'  => [ 'alloc' => 0.0, 'admin' => 0.0 ],
        ];
    } else {
        $breakdown = us_get_invoice_breakdown( $league_id, $is_tournament ? '' : $month );
    }

    $org_name      = us_setting( 'org_name' )  ?: us_setting( 'org_short' );
    $assignor_name = us_setting( 'assignor_name' );
    $from_email    = us_setting( 'assignor_email' );

    $subject = 'Invoice ' . $inv_num . ' — ' . $league->post_title . ' — ' . $period;
    $body    = us_invoice_email_html( $league, $inv_num, $inv_date, $period, $breakdown, $notes, $org_name, $assignor_name );
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
function us_invoice_email_html( $league, $inv_num, $inv_date, $period, $breakdown, $notes, $org_name, $assignor_name ) {
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
            <span style="font-size:12px;color:#888;"><?php echo esc_html( $period ); ?></span>
          </td>
          <td align="center" style="padding:12px 10px;font-size:14px;border-bottom:1px solid #eee;"><?php echo intval( $totals['slots'] ); ?></td>
          <td align="right"  style="padding:12px 10px;font-size:14px;border-bottom:1px solid #eee;">$<?php echo number_format( $totals['umpire_pay'], 2 ); ?></td>
        </tr>
        <?php if ( $rates['alloc'] ) : ?>
        <tr>
          <td style="padding:12px 10px;font-size:14px;border-bottom:1px solid #eee;">
            Allocator fee<br>
            <span style="font-size:12px;color:#888;"><?php echo intval( $totals['slots'] ); ?> umpire slots &times; $<?php echo number_format( $rates['alloc'], 2 ); ?></span>
          </td>
          <td align="center" style="padding:12px 10px;font-size:14px;border-bottom:1px solid #eee;"><?php echo intval( $totals['slots'] ); ?></td>
          <td align="right"  style="padding:12px 10px;font-size:14px;border-bottom:1px solid #eee;">$<?php echo number_format( $totals['alloc'], 2 ); ?></td>
        </tr>
        <?php endif; ?>
        <?php if ( $rates['admin'] ) : ?>
        <tr>
          <td style="padding:12px 10px;font-size:14px;border-bottom:1px solid #eee;">
            Administrative fee<br>
            <span style="font-size:12px;color:#888;"><?php echo intval( $totals['slots'] ); ?> umpire slots &times; $<?php echo number_format( $rates['admin'], 2 ); ?></span>
          </td>
          <td align="center" style="padding:12px 10px;font-size:14px;border-bottom:1px solid #eee;"><?php echo intval( $totals['slots'] ); ?></td>
          <td align="right"  style="padding:12px 10px;font-size:14px;border-bottom:1px solid #eee;">$<?php echo number_format( $totals['admin'], 2 ); ?></td>
        </tr>
        <?php endif; ?>
        <tr>
          <td colspan="2" align="right" style="padding:14px 10px 4px;font-size:13px;font-weight:bold;color:#091b33;">Total Due</td>
          <td align="right" style="padding:14px 10px 4px;font-size:20px;font-weight:bold;color:#091b33;">$<?php echo number_format( $totals['grand'], 2 ); ?></td>
        </tr>
      </table>
    </td>
  </tr>

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

    $action    = sanitize_text_field( $_POST['us_inv_action'] ?? '' );
    $league_id = absint( $_POST['us_inv_league'] ?? 0 );
    $month     = sanitize_text_field( $_POST['us_inv_month']  ?? '' );
    $notes     = sanitize_textarea_field( $_POST['us_inv_notes'] ?? '' );
    $inv_num   = sanitize_text_field( $_POST['us_inv_num'] ?? '' );

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
        'post_status' => 'publish',
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

                    var html = '<div class="us-form-group"><label style="display:block;margin-bottom:8px;font-weight:600;">Month</label>';
                    data.months.forEach( function(m, i) {
                        var checked = ( i === data.months.length - 1 ) ? ' checked' : '';
                        html += '<label style="display:flex;align-items:center;gap:10px;padding:9px 14px;border:1px solid #dde3ea;border-radius:6px;cursor:pointer;margin-bottom:6px;font-size:14px;transition:background .1s;" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'\'">'
                              + '<input type="radio" name="_inv_month_radio" value="' + m.value + '"' + checked + ' style="margin:0;flex-shrink:0;">'
                              + '<span style="flex:1;">' + m.label + '</span>'
                              + '<span style="color:#888;font-size:12px;">' + m.games + ' game' + ( m.games !== 1 ? 's' : '' ) + '</span>'
                              + '</label>';
                        if ( checked ) monthVal.value = m.value;
                    } );
                    html += '</div>';
                    monthList.innerHTML = html;
                    submitBtn.style.display = '';

                    monthList.querySelectorAll('input[type=radio]').forEach( function(r) {
                        r.addEventListener('change', function() { monthVal.value = this.value; });
                    } );
                } )
                .catch( function() {
                    loading.style.display = 'none';
                    monthList.innerHTML = '<p style="color:#b32d2e;font-size:13px;">Network error — please try again.</p>';
                } );
            });
        })();
        </script>

        <?php elseif ( $step === 2 ) :
            $breakdown = us_get_invoice_breakdown( $league_id, $is_tournament ? '' : $month );
            $rows      = $breakdown['rows'];
            $totals    = $breakdown['totals'];
            $rates     = $breakdown['rates'];

            if ( $is_tournament ) {
                $t_start = get_post_meta( $league_id, 'us_tourney_start', true );
                $t_end   = get_post_meta( $league_id, 'us_tourney_end',   true );
                $period  = ( $t_start && $t_end )
                    ? date( 'M j', strtotime( $t_start ) ) . ' – ' . date( 'M j, Y', strtotime( $t_end ) )
                    : $league->post_title;
            } else {
                $period = date( 'F Y', strtotime( $month . '-01' ) );
            }

            $contact_email = get_post_meta( $league_id, 'us_contact_email', true );
            $contact_name  = get_post_meta( $league_id, 'us_contact_name',  true );
            $show_alloc    = $rates['alloc'] > 0;
            $show_admin    = $rates['admin'] > 0;
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

        <!-- Per-game breakdown -->
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
                <?php foreach ( $rows as $row ) : ?>
                <tr>
                    <td style="white-space:nowrap;"><?php echo esc_html( date( 'M j', strtotime( $row['date'] ) ) ); ?></td>
                    <td>
                        <?php echo esc_html( $row['title'] ); ?>
                        <?php if ( $row['is_dh'] ) : ?>
                            <span style="display:inline-block;background:#e8f4fd;color:#1a6396;font-size:10px;font-weight:700;padding:1px 5px;border-radius:3px;margin-left:4px;letter-spacing:.5px;">DH</span>
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
            </tbody>
            <tfoot>
                <tr style="font-weight:700;background:#f0f4f8;border-top:2px solid #c5cfd9;">
                    <td colspan="3" style="text-align:right;padding-right:12px;">Totals</td>
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

            <div class="us-form-group">
                <label for="us_inv_notes">Notes <span style="font-weight:400;color:#999;">(optional — appears on the invoice)</span></label>
                <textarea id="us_inv_notes" name="us_inv_notes" rows="3" style="width:100%;"></textarea>
            </div>

            <button type="submit" class="button button-primary">Generate invoice &rarr;</button>
        </form>

        <?php endif; // rows ?>

        <?php elseif ( $step === 3 ) :
            $breakdown = us_get_invoice_breakdown( $league_id, $is_tournament ? '' : $month );
            $totals    = $breakdown['totals'];
            $rates     = $breakdown['rates'];
            $show_alloc = $rates['alloc'] > 0;
            $show_admin = $rates['admin'] > 0;

            if ( $is_tournament ) {
                $t_start = get_post_meta( $league_id, 'us_tourney_start', true );
                $t_end   = get_post_meta( $league_id, 'us_tourney_end',   true );
                $period  = ( $t_start && $t_end )
                    ? date( 'M j', strtotime( $t_start ) ) . ' – ' . date( 'M j, Y', strtotime( $t_end ) )
                    : $league->post_title;
            } else {
                $period = date( 'F Y', strtotime( $month . '-01' ) );
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
                        data-notes="<?php echo esc_attr( $notes ); ?>">
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
                            <div class="us-invoice-doc__line-sub"><?php echo esc_html( $period ); ?> &bull; <?php echo intval( $totals['games'] ); ?> games</div>
                        </td>
                        <td class="us-invoice-doc__cell-center"><?php echo intval( $totals['slots'] ); ?></td>
                        <td class="us-invoice-doc__cell-right">$<?php echo number_format( $totals['umpire_pay'], 2 ); ?></td>
                    </tr>
                    <?php if ( $show_alloc ) : ?>
                    <tr>
                        <td>
                            Allocator fee
                            <div class="us-invoice-doc__line-sub"><?php echo intval( $totals['slots'] ); ?> umpire slots &times; $<?php echo number_format( $rates['alloc'], 2 ); ?>/slot</div>
                        </td>
                        <td class="us-invoice-doc__cell-center"><?php echo intval( $totals['slots'] ); ?></td>
                        <td class="us-invoice-doc__cell-right">$<?php echo number_format( $totals['alloc'], 2 ); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ( $show_admin ) : ?>
                    <tr>
                        <td>
                            Administrative fee
                            <div class="us-invoice-doc__line-sub"><?php echo intval( $totals['slots'] ); ?> umpire slots &times; $<?php echo number_format( $rates['admin'], 2 ); ?>/slot</div>
                        </td>
                        <td class="us-invoice-doc__cell-center"><?php echo intval( $totals['slots'] ); ?></td>
                        <td class="us-invoice-doc__cell-right">$<?php echo number_format( $totals['admin'], 2 ); ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" class="us-invoice-doc__total-label">Total Due</td>
                        <td class="us-invoice-doc__total-amt">$<?php echo number_format( $totals['grand'], 2 ); ?></td>
                    </tr>
                </tfoot>
            </table>

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
            <input type="hidden" name="us_inv_notes"  value="<?php echo esc_attr( $notes ); ?>">
            <input type="hidden" name="us_inv_num"    value="<?php echo esc_attr( $inv_num ); ?>">
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
                            action:    'us_send_invoice',
                            nonce:     '<?php echo wp_create_nonce( 'us_assign_nonce' ); ?>',
                            league_id: btn.dataset.league,
                            inv_num:   btn.dataset.invNum,
                            inv_date:  btn.dataset.invDate,
                            period:    btn.dataset.period,
                            month:     btn.dataset.month,
                            notes:     btn.dataset.notes,
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
