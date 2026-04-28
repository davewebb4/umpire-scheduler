<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Earnings shortcode ────────────────────────────────────────
add_shortcode( 'umpire_earnings', 'us_shortcode_earnings' );
function us_shortcode_earnings() {
    if ( ! is_user_logged_in() ) return us_login_form();

    $umpire = us_get_umpire_by_user( get_current_user_id() );
    if ( ! $umpire ) return '<p class="us-empty">No umpire profile found.</p>';

    $umpire_id = $umpire->ID;
    $summary   = us_get_umpire_pay_summary( $umpire_id );

    // ── Tournament earnings ───────────────────────────────────
    $all_assignments = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_umpire_id', 'value' => $umpire_id,  'compare' => '=' ],
            [ 'key' => 'us_status',    'value' => 'confirmed', 'compare' => '=' ],
        ],
    ] );

    $tourney_earnings = [];
    foreach ( $all_assignments as $a ) {
        $game_id   = get_post_meta( $a->ID, 'us_game_id',     true );
        $league_id = get_post_meta( $game_id, 'us_league_id', true );
        if ( ! $league_id ) continue;
        if ( get_post_meta( $league_id, 'us_is_tournament', true ) !== '1' ) continue;

        $pay = floatval( get_post_meta( $a->ID, 'us_pay_amount', true ) );

        if ( ! isset( $tourney_earnings[ $league_id ] ) ) {
            $start      = get_post_meta( $league_id, 'us_tourney_start', true );
            $end        = get_post_meta( $league_id, 'us_tourney_end',   true );
            $date_range = '';
            if ( $start && $end ) {
                $date_range = date( 'M j', strtotime( $start ) ) . ' – ' . date( 'D, M j, Y', strtotime( $end ) );
            } elseif ( $start ) {
                $date_range = date( 'D, M j, Y', strtotime( $start ) );
            }
            $tourney_earnings[ $league_id ] = [
                'name'       => get_the_title( $league_id ),
                'date_range' => $date_range,
                'games'      => 0,
                'earned'     => 0,
                'paid'       => false,
                'paid_date'  => '',
            ];
        }

        $tourney_earnings[ $league_id ]['games']++;
        $tourney_earnings[ $league_id ]['earned'] += $pay;
    }

    foreach ( $tourney_earnings as $league_id => &$t ) {
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
            $t['paid']      = true;
            $t['paid_date'] = get_post_meta( $payment[0]->ID, 'payment_date', true );
        }
    }
    unset( $t );

    // ── Tournament totals ─────────────────────────────────────
    $tourney_total_games       = 0;
    $tourney_total_earned      = 0;
    $tourney_total_paid        = 0;
    $tourney_total_outstanding = 0;
    foreach ( $tourney_earnings as $t ) {
        $tourney_total_games  += $t['games'];
        $tourney_total_earned += $t['earned'];
        if ( $t['paid'] ) {
            $tourney_total_paid += $t['earned'];
        } else {
            $tourney_total_outstanding += $t['earned'];
        }
    }

    $has_tourney_earnings = ! empty( $tourney_earnings );

    ob_start();
    ?>
    <div class="us-dashboard">
        <h2>My earnings</h2>

        <!-- ── League earnings ─────────────────────────────── -->
        <h3 class="us-earnings-section-heading">League earnings</h3>

        <?php if ( ! empty( $summary['months'] ) ) : ?>
        <div class="us-stat-cards">
            <div class="us-stat-card">
                <div class="us-stat-value"><?php echo $summary['total_games']; ?></div>
                <div class="us-stat-label">Games worked</div>
            </div>
            <div class="us-stat-card us-stat-card--danger">
                <div class="us-stat-value us-stat-value--danger">$<?php echo number_format( $summary['total_outstanding'], 2 ); ?></div>
                <div class="us-stat-label">Outstanding</div>
            </div>
            <div class="us-stat-card us-stat-card--accent">
                <div class="us-stat-value us-stat-value--accent">$<?php echo number_format( $summary['total_paid'], 2 ); ?></div>
                <div class="us-stat-label">Paid</div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( empty( $summary['months'] ) ) : ?>
            <p class="us-empty">No confirmed league earnings yet.</p>
        <?php else : ?>
        <table>
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Games worked</th>
                    <th>Earned</th>
                    <th>Status</th>
                    <th>Date paid</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $summary['months'] as $mk => $month ) : ?>
                <tr>
                    <td data-label="Month"><?php echo esc_html( $month['label'] ); ?></td>
                    <td data-label="Games worked"><?php echo $month['games']; ?></td>
                    <td data-label="Earned">$<?php echo number_format( $month['earned'], 2 ); ?></td>
                    <td data-label="Status">
                        <?php if ( $month['paid'] ) : ?>
                            <span class="us-status-confirmed">&#10003; Paid</span>
                        <?php else : ?>
                            <span class="us-status-pending">Outstanding</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Date paid">
                        <?php echo $month['paid'] ? esc_html( date( 'D, M j, Y', strtotime( $month['payment_date'] ) ) ) : '—'; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr class="us-earnings-total">
                    <td data-label="Total">Total</td>
                    <td data-label="Games"><?php echo $summary['total_games']; ?> games</td>
                    <td data-label="Earned">$<?php echo number_format( $summary['total_earned'], 2 ); ?></td>
                    <td></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- ── Tournament earnings ─────────────────────────── -->
        <h3 class="us-earnings-section-heading">Tournament earnings</h3>

        <?php if ( ! $has_tourney_earnings ) : ?>
            <p class="us-empty">No confirmed tournament earnings yet.</p>
        <?php else : ?>
        <div class="us-stat-cards">
            <div class="us-stat-card">
                <div class="us-stat-value"><?php echo $tourney_total_games; ?></div>
                <div class="us-stat-label">Tournament games</div>
            </div>
            <div class="us-stat-card us-stat-card--danger">
                <div class="us-stat-value us-stat-value--danger">$<?php echo number_format( $tourney_total_outstanding, 2 ); ?></div>
                <div class="us-stat-label">Outstanding</div>
            </div>
            <div class="us-stat-card us-stat-card--accent">
                <div class="us-stat-value us-stat-value--accent">$<?php echo number_format( $tourney_total_paid, 2 ); ?></div>
                <div class="us-stat-label">Paid</div>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Tournament</th>
                    <th>Dates</th>
                    <th>Games</th>
                    <th>Earned</th>
                    <th>Status</th>
                    <th>Date paid</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $tourney_earnings as $league_id => $t ) : ?>
                <tr>
                    <td data-label="Tournament"><?php echo esc_html( $t['name'] ); ?></td>
                    <td data-label="Dates"><?php echo esc_html( $t['date_range'] ?: '—' ); ?></td>
                    <td data-label="Games"><?php echo $t['games']; ?></td>
                    <td data-label="Earned">$<?php echo number_format( $t['earned'], 2 ); ?></td>
                    <td data-label="Status">
                        <?php if ( $t['paid'] ) : ?>
                            <span class="us-status-confirmed">&#10003; Paid</span>
                        <?php else : ?>
                            <span class="us-status-pending">Outstanding</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Date paid">
                        <?php echo $t['paid'] && $t['paid_date'] ? esc_html( date( 'D, M j, Y', strtotime( $t['paid_date'] ) ) ) : '—'; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr class="us-earnings-total">
                    <td data-label="Total">Total</td>
                    <td></td>
                    <td data-label="Games"><?php echo $tourney_total_games; ?> games</td>
                    <td data-label="Earned">$<?php echo number_format( $tourney_total_earned, 2 ); ?></td>
                    <td></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}