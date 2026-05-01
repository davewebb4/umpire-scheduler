<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── AJAX: send invoice email ──────────────────────────────────
add_action( 'wp_ajax_us_send_invoice', 'us_ajax_send_invoice' );
function us_ajax_send_invoice() {
    check_ajax_referer( 'us_assign_nonce', 'nonce' );
    if ( ! us_is_allocator() ) wp_send_json_error( 'Unauthorized' );

    $league_id  = absint( $_POST['league_id']  ?? 0 );
    $inv_num    = sanitize_text_field( $_POST['inv_num']   ?? '' );
    $inv_date   = sanitize_text_field( $_POST['inv_date']  ?? '' );
    $period     = sanitize_text_field( $_POST['period']    ?? '' );
    $game_count = absint( $_POST['game_count'] ?? 0 );
    $rate       = floatval( $_POST['rate']      ?? 0 );
    $notes      = sanitize_textarea_field( $_POST['notes'] ?? '' );

    $league = get_post( $league_id );
    if ( ! $league ) wp_send_json_error( 'League not found' );

    $contact_email = get_post_meta( $league_id, 'us_contact_email', true );
    $contact_name  = get_post_meta( $league_id, 'us_contact_name',  true );
    if ( ! $contact_email ) wp_send_json_error( 'No contact email on file for this league.' );

    $org_name      = us_setting( 'org_name' )  ?: us_setting( 'org_short' );
    $assignor_name = us_setting( 'assignor_name' );
    $from_email    = us_setting( 'assignor_email' );
    $total         = $game_count * $rate;

    $subject = 'Invoice ' . $inv_num . ' — ' . $league->post_title . ' — ' . $period;
    $body    = us_invoice_email_html( $league, $inv_num, $inv_date, $period, $game_count, $rate, $notes, $org_name, $assignor_name );
    $headers = [
        'From: ' . ( $assignor_name ?: $org_name ) . ' <' . $from_email . '>',
        'Cc: ' . $from_email,
    ];

    // Force HTML content type — more reliable than passing it in headers
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
function us_invoice_email_html( $league, $inv_num, $inv_date, $period, $game_count, $rate, $notes, $org_name, $assignor_name ) {
    $total         = $game_count * $rate;
    $contact_name  = get_post_meta( $league->ID, 'us_contact_name',  true );
    $contact_email = get_post_meta( $league->ID, 'us_contact_email', true );
    $contact_phone = get_post_meta( $league->ID, 'us_contact_phone', true );
    $issued_by     = $assignor_name ?: $org_name;

    // Flat, single-table layout — maximum email client compatibility
    ob_start(); ?>
<table cellpadding="0" cellspacing="0" border="0" width="600" style="font-family:Arial,sans-serif;font-size:14px;color:#333;margin:0 auto;">

  <!-- Header -->
  <tr>
    <td colspan="2" bgcolor="#091b33" style="background-color:#091b33;padding:20px 28px;">
      <p style="margin:0;font-size:20px;font-weight:bold;color:#ffffff;"><?php echo esc_html( $org_name ); ?></p>
      <p style="margin:4px 0 0;font-size:12px;color:#aac4e0;letter-spacing:1px;">INVOICE <?php echo esc_html( $inv_num ); ?></p>
    </td>
  </tr>

  <!-- Spacer -->
  <tr><td colspan="2" height="20"></td></tr>

  <!-- Bill To / Details -->
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

  <!-- Spacer -->
  <tr><td colspan="2" height="24"></td></tr>

  <!-- Line item table -->
  <tr>
    <td colspan="2" style="padding:0 28px;">
      <table cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;">
        <tr bgcolor="#f0f4f8" style="background-color:#f0f4f8;">
          <td style="padding:9px 10px;font-size:11px;font-weight:bold;color:#555;text-transform:uppercase;border-bottom:2px solid #dde3ea;">Description</td>
          <td align="center" style="padding:9px 10px;font-size:11px;font-weight:bold;color:#555;text-transform:uppercase;border-bottom:2px solid #dde3ea;width:60px;">Games</td>
          <td align="right" style="padding:9px 10px;font-size:11px;font-weight:bold;color:#555;text-transform:uppercase;border-bottom:2px solid #dde3ea;width:80px;">Rate</td>
          <td align="right" style="padding:9px 10px;font-size:11px;font-weight:bold;color:#555;text-transform:uppercase;border-bottom:2px solid #dde3ea;width:90px;">Amount</td>
        </tr>
        <tr>
          <td style="padding:12px 10px;font-size:14px;border-bottom:1px solid #eee;">
            Umpire services &mdash; <?php echo esc_html( $league->post_title ); ?><br>
            <span style="font-size:12px;color:#888;"><?php echo esc_html( $period ); ?></span>
          </td>
          <td align="center" style="padding:12px 10px;font-size:14px;border-bottom:1px solid #eee;"><?php echo intval( $game_count ); ?></td>
          <td align="right" style="padding:12px 10px;font-size:14px;border-bottom:1px solid #eee;">$<?php echo number_format( $rate, 2 ); ?></td>
          <td align="right" style="padding:12px 10px;font-size:14px;border-bottom:1px solid #eee;">$<?php echo number_format( $total, 2 ); ?></td>
        </tr>
        <tr>
          <td colspan="3" align="right" style="padding:14px 10px 4px;font-size:13px;font-weight:bold;color:#091b33;">Total Due</td>
          <td align="right" style="padding:14px 10px 4px;font-size:20px;font-weight:bold;color:#091b33;">$<?php echo number_format( $total, 2 ); ?></td>
        </tr>
      </table>
    </td>
  </tr>

  <?php if ( $notes ) : ?>
  <!-- Notes -->
  <tr><td colspan="2" height="16"></td></tr>
  <tr>
    <td colspan="2" style="padding:0 28px;">
      <table cellpadding="12" cellspacing="0" border="0" width="100%" bgcolor="#f8f9fa" style="background-color:#f8f9fa;border-left:3px solid #091b33;">
        <tr>
          <td>
            <p style="margin:0 0 4px;font-size:11px;color:#999;text-transform:uppercase;">Notes</p>
            <p style="margin:0;font-size:13px;color:#555;"><?php echo nl2br( esc_html( $notes ) ); ?></p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
  <?php endif; ?>

  <!-- Footer -->
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

// ── Count invoice games ───────────────────────────────────────
function us_count_invoice_games( $league_id, $month = '' ) {
    $meta_query = [ [ 'key' => 'us_league_id', 'value' => $league_id, 'compare' => '=' ] ];

    if ( $month ) {
        $month_start = $month . '-01';
        $month_end   = date( 'Y-m-t', strtotime( $month_start ) );
        $meta_query[] = [ 'key' => 'us_game_date', 'value' => $month_start, 'compare' => '>=' ];
        $meta_query[] = [ 'key' => 'us_game_date', 'value' => $month_end,   'compare' => '<=' ];
    }

    $games = get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'post_status' => 'publish',
        'fields'      => 'ids',
        'meta_query'  => $meta_query,
    ] );

    $count = 0;
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
        if ( $confirmed ) $count++;
    }
    return $count;
}

// ── Generate next invoice number ──────────────────────────────
function us_next_invoice_number() {
    $seq     = (int) get_option( 'us_invoice_seq', 0 ) + 1;
    $prefix  = strtoupper( us_setting( 'org_short' ) ?: 'INV' );
    $num     = $prefix . '-' . date( 'Y' ) . '-' . str_pad( $seq, 3, '0', STR_PAD_LEFT );
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
    $rate      = isset( $_POST['us_inv_rate'] ) ? floatval( sanitize_text_field( $_POST['us_inv_rate'] ) ) : 0;
    $notes     = sanitize_textarea_field( $_POST['us_inv_notes'] ?? '' );
    $inv_num   = sanitize_text_field( $_POST['us_inv_num'] ?? '' );

    $league        = $league_id ? get_post( $league_id ) : null;
    $is_tournament = $league ? get_post_meta( $league_id, 'us_is_tournament', true ) === '1' : false;

    // Determine step
    $step = 1;
    if ( $league && $action === 'load' ) $step = 2;
    if ( $league && $action === 'preview' && $rate > 0 ) {
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
        <!-- ── Step 1: Select league + month ─────────────────── -->
        <div class="us-invoice-step-card">
            <h3 class="us-invoice-step-title"><span class="us-invoice-step-num">1</span> Select league &amp; period</h3>
            <form method="post">
                <?php wp_nonce_field( 'us_assign_nonce', 'us_assign_nonce_field' ); ?>
                <input type="hidden" name="us_inv_action" value="load">

                <div class="us-form-group" style="max-width:400px;">
                    <label for="us_inv_league">League / Tournament</label>
                    <select id="us_inv_league_sel" name="us_inv_league" required style="width:100%;">
                        <option value="">— Select —</option>
                        <?php foreach ( $all_leagues as $l ) :
                            $is_t = get_post_meta( $l->ID, 'us_is_tournament', true ) === '1';
                        ?>
                            <option value="<?php echo $l->ID; ?>"
                                    data-tournament="<?php echo $is_t ? '1' : '0'; ?>">
                                <?php echo esc_html( $l->post_title ); ?>
                                <?php echo $is_t ? ' (Tournament)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="us-form-group" id="us-inv-month-row" style="max-width:400px;">
                    <label for="us_inv_month_sel">Month</label>
                    <input type="month" id="us_inv_month_sel" name="us_inv_month"
                           value="<?php echo esc_attr( date( 'Y-m' ) ); ?>"
                           style="width:100%;" />
                    <p class="description">For tournaments, all games are included regardless of month.</p>
                </div>

                <button type="submit" class="button button-primary">Load Report &rarr;</button>
            </form>
        </div>

        <script>
        (function(){
            var sel  = document.getElementById('us_inv_league_sel');
            var row  = document.getElementById('us-inv-month-row');
            var inp  = document.getElementById('us_inv_month_sel');
            function toggle() {
                var isTourney = sel.options[sel.selectedIndex] && sel.options[sel.selectedIndex].getAttribute('data-tournament') === '1';
                row.style.display = isTourney ? 'none' : '';
                inp.required = !isTourney;
            }
            sel.addEventListener('change', toggle);
            toggle();
        })();
        </script>

        <?php elseif ( $step === 2 ) :
            $game_count = us_count_invoice_games( $league_id, $is_tournament ? '' : $month );
            $contact_email = get_post_meta( $league_id, 'us_contact_email', true );
            $contact_name  = get_post_meta( $league_id, 'us_contact_name',  true );

            if ( $is_tournament ) {
                $start = get_post_meta( $league_id, 'us_tourney_start', true );
                $end   = get_post_meta( $league_id, 'us_tourney_end',   true );
                $period = $start && $end
                    ? date( 'M j', strtotime( $start ) ) . ' – ' . date( 'M j, Y', strtotime( $end ) )
                    : $league->post_title;
            } else {
                $period = date( 'F Y', strtotime( $month . '-01' ) );
            }
        ?>
        <!-- ── Step 2: Rate input ──────────────────────────── -->
        <div class="us-invoice-step-card">
            <h3 class="us-invoice-step-title"><span class="us-invoice-step-num">2</span> Set invoice rate</h3>

            <div class="us-invoice-summary">
                <div class="us-invoice-summary__item">
                    <span class="us-invoice-summary__label">League</span>
                    <span class="us-invoice-summary__value"><?php echo esc_html( $league->post_title ); ?></span>
                </div>
                <div class="us-invoice-summary__item">
                    <span class="us-invoice-summary__label">Period</span>
                    <span class="us-invoice-summary__value"><?php echo esc_html( $period ); ?></span>
                </div>
                <div class="us-invoice-summary__item">
                    <span class="us-invoice-summary__label">Games confirmed</span>
                    <span class="us-invoice-summary__value us-invoice-summary__value--highlight"><?php echo $game_count; ?></span>
                </div>
                <?php if ( $contact_email ) : ?>
                <div class="us-invoice-summary__item">
                    <span class="us-invoice-summary__label">Invoice will go to</span>
                    <span class="us-invoice-summary__value"><?php echo esc_html( $contact_name ? $contact_name . ' (' . $contact_email . ')' : $contact_email ); ?></span>
                </div>
                <?php else : ?>
                <div class="us-invoice-summary__item">
                    <span class="us-invoice-summary__label" style="color:#b32d2e;">&#9888; No contact email</span>
                    <span class="us-invoice-summary__value" style="color:#b32d2e;">You can still generate the invoice but cannot email it.</span>
                </div>
                <?php endif; ?>
            </div>

            <?php if ( $game_count === 0 ) : ?>
                <p class="us-notice us-notice-error">No confirmed games found for this period.</p>
            <?php else : ?>
            <form method="post" style="margin-top:20px;">
                <?php wp_nonce_field( 'us_assign_nonce', 'us_assign_nonce_field' ); ?>
                <input type="hidden" name="us_inv_action"  value="preview">
                <input type="hidden" name="us_inv_league"  value="<?php echo $league_id; ?>">
                <input type="hidden" name="us_inv_month"   value="<?php echo esc_attr( $month ); ?>">

                <div class="us-form-group" style="max-width:280px;">
                    <label for="us_inv_rate">Invoice rate per game ($)</label>
                    <input type="number" id="us_inv_rate" name="us_inv_rate"
                           min="0" step="0.01" required style="width:100%;"
                           placeholder="e.g. 85.00" />
                    <p class="description">This is what you charge the league, not umpire pay.</p>
                </div>

                <div class="us-form-group" style="max-width:500px;">
                    <label for="us_inv_notes">Notes <span style="font-weight:400;color:#999;">(optional)</span></label>
                    <textarea id="us_inv_notes" name="us_inv_notes" rows="3" style="width:100%;"></textarea>
                </div>

                <div id="us-inv-total-preview" style="display:none;margin-bottom:16px;padding:12px 16px;background:#f0f7ee;border:1px solid #b7ddb0;border-radius:6px;font-size:15px;">
                    <strong>Estimated total: <span id="us-inv-total-amt">$0.00</span></strong>
                    <span style="color:#666;font-size:13px;margin-left:8px;">(<?php echo $game_count; ?> games)</span>
                </div>

                <button type="submit" class="button button-primary">Preview Invoice &rarr;</button>
            </form>

            <script>
            (function(){
                var rateInp = document.getElementById('us_inv_rate');
                var preview = document.getElementById('us-inv-total-preview');
                var amt     = document.getElementById('us-inv-total-amt');
                var games   = <?php echo $game_count; ?>;
                rateInp.addEventListener('input', function(){
                    var r = parseFloat(this.value) || 0;
                    if (r > 0) {
                        amt.textContent = '$' + (r * games).toFixed(2);
                        preview.style.display = '';
                    } else {
                        preview.style.display = 'none';
                    }
                });
            })();
            </script>
            <?php endif; ?>
        </div>

        <?php elseif ( $step === 3 ) :
            $game_count = us_count_invoice_games( $league_id, $is_tournament ? '' : $month );
            $total      = $game_count * $rate;

            $contact_name  = get_post_meta( $league_id, 'us_contact_name',  true );
            $contact_email = get_post_meta( $league_id, 'us_contact_email', true );
            $contact_phone = get_post_meta( $league_id, 'us_contact_phone', true );

            $assignor_name = us_setting( 'assignor_name' );
            $inv_date      = date( 'F j, Y' );

            if ( $is_tournament ) {
                $start = get_post_meta( $league_id, 'us_tourney_start', true );
                $end   = get_post_meta( $league_id, 'us_tourney_end',   true );
                $period = $start && $end
                    ? date( 'M j', strtotime( $start ) ) . ' – ' . date( 'M j, Y', strtotime( $end ) )
                    : $league->post_title;
            } else {
                $period = date( 'F Y', strtotime( $month . '-01' ) );
            }

            $logo_id  = get_theme_mod( 'custom_logo' );
            $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
        ?>
        <!-- ── Step 3: Invoice preview ────────────────────── -->
        <div style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <button id="us-inv-download" class="button button-primary">&#8659; Download PDF</button>
            <?php if ( $contact_email ) : ?>
                <button id="us-inv-send-email" class="button"
                        data-league="<?php echo $league_id; ?>"
                        data-inv-num="<?php echo esc_attr( $inv_num ); ?>"
                        data-inv-date="<?php echo esc_attr( $inv_date ); ?>"
                        data-period="<?php echo esc_attr( $period ); ?>"
                        data-games="<?php echo $game_count; ?>"
                        data-rate="<?php echo $rate; ?>"
                        data-notes="<?php echo esc_attr( $notes ); ?>">
                    &#9993; Email to <?php echo esc_html( $contact_name ?: $contact_email ); ?>
                </button>
            <?php endif; ?>
            <span id="us-inv-email-status" style="font-size:13px;color:#333;"></span>
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
                        <th class="us-invoice-doc__col-qty">Games</th>
                        <th class="us-invoice-doc__col-rate">Rate</th>
                        <th class="us-invoice-doc__col-amt">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            Umpire services — <?php echo esc_html( $league->post_title ); ?>
                            <div class="us-invoice-doc__line-sub"><?php echo esc_html( $period ); ?></div>
                        </td>
                        <td class="us-invoice-doc__cell-center"><?php echo $game_count; ?></td>
                        <td class="us-invoice-doc__cell-right">$<?php echo number_format( $rate, 2 ); ?></td>
                        <td class="us-invoice-doc__cell-right">$<?php echo number_format( $total, 2 ); ?></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="us-invoice-doc__total-label">Total Due</td>
                        <td class="us-invoice-doc__total-amt">$<?php echo number_format( $total, 2 ); ?></td>
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

        <!-- hidden form fields passed back if user needs to re-post -->
        <form id="us-inv-repost" method="post" style="display:none;">
            <?php wp_nonce_field( 'us_assign_nonce', 'us_assign_nonce_field' ); ?>
            <input type="hidden" name="us_inv_action"  value="preview">
            <input type="hidden" name="us_inv_league"  value="<?php echo $league_id; ?>">
            <input type="hidden" name="us_inv_month"   value="<?php echo esc_attr( $month ); ?>">
            <input type="hidden" name="us_inv_rate"    value="<?php echo esc_attr( $rate ); ?>">
            <input type="hidden" name="us_inv_notes"   value="<?php echo esc_attr( $notes ); ?>">
            <input type="hidden" name="us_inv_num"     value="<?php echo esc_attr( $inv_num ); ?>">
        </form>

        <!-- html2pdf.js -->
        <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
        <script>
        (function(){
            // PDF download
            document.getElementById('us-inv-download').addEventListener('click', function(){
                var btn = this;
                btn.disabled = true;
                btn.textContent = 'Generating…';
                var el  = document.getElementById('us-invoice-content');
                var opt = {
                    margin:     [10, 10, 10, 10],
                    filename:   '<?php echo esc_js( $inv_num ); ?>.pdf',
                    image:      { type: 'jpeg', quality: 0.98 },
                    html2canvas:{ scale: 2, useCORS: true },
                    jsPDF:      { unit: 'mm', format: 'letter', orientation: 'portrait' }
                };
                html2pdf().set(opt).from(el).save().then(function(){
                    btn.disabled = false;
                    btn.textContent = '⬇ Download PDF';
                });
            });

            // Email send
            var emailBtn = document.getElementById('us-inv-send-email');
            if ( emailBtn ) {
                emailBtn.addEventListener('click', function(){
                    if ( ! confirm('Send invoice <?php echo esc_js( $inv_num ); ?> to <?php echo esc_js( $contact_email ); ?>?') ) return;
                    var btn    = this;
                    var status = document.getElementById('us-inv-email-status');
                    btn.disabled = true;
                    btn.textContent = 'Sending…';
                    status.textContent = '';

                    var data = new URLSearchParams({
                        action:     'us_send_invoice',
                        nonce:      '<?php echo wp_create_nonce( 'us_assign_nonce' ); ?>',
                        league_id:  btn.dataset.league,
                        inv_num:    btn.dataset.invNum,
                        inv_date:   btn.dataset.invDate,
                        period:     btn.dataset.period,
                        game_count: btn.dataset.games,
                        rate:       btn.dataset.rate,
                        notes:      btn.dataset.notes,
                    });

                    fetch('<?php echo admin_url( 'admin-ajax.php' ); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: data,
                    })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if ( res.success ) {
                            status.style.color = '#0a6b0a';
                            status.textContent = '✓ ' + res.data;
                            btn.textContent = '✓ Sent';
                        } else {
                            status.style.color = '#b32d2e';
                            status.textContent = '✗ ' + (res.data || 'Send failed');
                            btn.disabled = false;
                            btn.textContent = 'Retry email';
                        }
                    })
                    .catch(function(){
                        status.style.color = '#b32d2e';
                        status.textContent = '✗ Network error';
                        btn.disabled = false;
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
        .us-invoice-doc__col-qty, .us-invoice-doc__col-rate, .us-invoice-doc__col-amt { width: 80px; }
        .us-invoice-doc__col-qty  th, .us-invoice-doc__cell-center { text-align: center; }
        .us-invoice-doc__col-rate th, .us-invoice-doc__col-amt th,
        .us-invoice-doc__cell-right { text-align: right; }
        .us-invoice-doc__line-sub { font-size: 12px; color: #888; margin-top: 2px; }
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
    .us-invoice-summary { display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px; }
    .us-invoice-summary__item { display: flex; gap: 12px; font-size: 14px; }
    .us-invoice-summary__label { color: #888; min-width: 140px; }
    .us-invoice-summary__value { color: #333; font-weight: 500; }
    .us-invoice-summary__value--highlight { font-size: 22px; font-weight: 700; color: #1a3a5c; line-height: 1; }
    </style>

    <?php
    return ob_get_clean();
}
