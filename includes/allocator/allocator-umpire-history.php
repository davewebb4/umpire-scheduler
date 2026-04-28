<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'allocator_umpire_history', 'us_shortcode_allocator_umpire_history' );
function us_shortcode_allocator_umpire_history() {
    if ( ! is_user_logged_in() ) return us_login_form();

    if ( ! us_is_allocator() ) {
        return '<script>window.location="' . esc_url( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) ) . '";</script>';
    }

    $umpire_id = isset( $_GET['umpire_id'] ) ? absint( $_GET['umpire_id'] ) : 0;
    $base_url  = home_url( '/' . us_setting( 'slug_allocator_umpire_history' ) . '/' );
    $umpires   = us_get_active_umpires();

    ob_start();
    ?>
    <div class="us-dashboard">

        <div class="us-alloc-dashboard__header">
            <h2>Umpire History</h2>
            <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_allocator_dashboard' ) . '/' ) ); ?>"
               class="us-btn us-btn-request us-btn--sm">&larr; Dashboard</a>
        </div>

        <!-- ── Umpire selector ─────────────────────────────── -->
        <div class="us-alloc-history__filter">
            <form method="get" action="<?php echo esc_url( $base_url ); ?>">
                <label class="us-schedule__filter-label" for="umpire_id">Umpire:</label>
                <select name="umpire_id" id="umpire_id"
                        class="us-schedule__filter-select"
                        onchange="this.form.submit()">
                    <option value="0">— select umpire —</option>
                    <?php foreach ( $umpires as $u ) : ?>
                        <option value="<?php echo $u->ID; ?>" <?php selected( $umpire_id, $u->ID ); ?>>
                            <?php echo esc_html( $u->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if ( ! $umpire_id ) : ?>
            <p class="us-empty">Select an umpire above to view their game history.</p>
        <?php else :
            $umpire = get_post( $umpire_id );
            if ( ! $umpire || $umpire->post_type !== US_PT_UMPIRE ) : ?>
                <p class="us-empty">Umpire not found.</p>
            <?php else :
                $assignments = get_posts( [
                    'post_type'   => US_PT_ASSIGNMENT,
                    'numberposts' => -1,
                    'post_status' => 'publish',
                    'meta_query'  => [
                        [ 'key' => 'us_umpire_id', 'value' => $umpire_id, 'compare' => '=' ],
                    ],
                ] );

                // ── Summary stats ─────────────────────────────────
                $total_confirmed = 0;
                $total_no_shows  = 0;
                $total_declined  = 0;
                $total_pay       = 0;

                foreach ( $assignments as $a ) {
                    $status = get_post_meta( $a->ID, 'us_status',     true );
                    $pay    = floatval( get_post_meta( $a->ID, 'us_pay_amount', true ) );
                    if ( $status === 'confirmed' ) { $total_confirmed++; $total_pay += $pay; }
                    elseif ( $status === 'no-show'  ) $total_no_shows++;
                    elseif ( $status === 'declined'  ) $total_declined++;
                }

                // Pay summary (league pay, outstanding vs paid)
                $pay_summary = us_get_umpire_pay_summary( $umpire_id );

                // ── Group by league ───────────────────────────────
                $by_league = [];
                foreach ( $assignments as $a ) {
                    $game_id   = get_post_meta( $a->ID, 'us_game_id',     true );
                    $league_id = get_post_meta( $game_id, 'us_league_id', true );
                    if ( ! $league_id ) $league_id = 'unknown';
                    $by_league[ $league_id ][] = $a;
                }

                foreach ( $by_league as $lid => &$games ) {
                    usort( $games, function( $a, $b ) {
                        $date_a = get_post_meta( get_post_meta( $a->ID, 'us_game_id', true ), 'us_game_date', true );
                        $date_b = get_post_meta( get_post_meta( $b->ID, 'us_game_id', true ), 'us_game_date', true );
                        return strcmp( $date_b, $date_a ); // newest first
                    } );
                }
                unset( $games );

                $status_colors = [
                    'confirmed' => 'us-status-confirmed',
                    'pending'   => 'us-status-pending',
                    'requested' => 'us-status-requested',
                    'declined'  => 'us-status-declined',
                    'no-show'   => 'us-status-declined',
                ];
            ?>

            <!-- ── Umpire header ──────────────────────────────── -->
            <div class="us-alloc-history__umpire-header">
                <div class="us-umpire-avatar us-alloc-history__avatar">
                    <?php
                    $parts    = explode( ' ', trim( $umpire->post_title ) );
                    $initials = strtoupper( substr( $parts[0], 0, 1 ) . ( isset( $parts[1] ) ? substr( $parts[1], 0, 1 ) : '' ) );
                    echo esc_html( $initials );
                    ?>
                </div>
                <div>
                    <h3 class="us-alloc-history__umpire-name"><?php echo esc_html( $umpire->post_title ); ?></h3>
                    <?php
                    $email = get_post_meta( $umpire_id, 'us_email', true );
                    $phone = get_post_meta( $umpire_id, 'us_phone', true );
                    if ( $email ) echo '<span class="us-alloc-history__umpire-meta">' . esc_html( $email ) . '</span>';
                    if ( $phone ) echo '<span class="us-alloc-history__umpire-meta">' . esc_html( $phone ) . '</span>';
                    ?>
                </div>
                <a href="<?php echo esc_url( add_query_arg( 'umpire_id', $umpire_id, home_url( '/' . us_setting( 'slug_allocator_pay_reports' ) . '/' ) ) ); ?>"
                   class="us-btn us-btn-request us-btn--sm us-alloc-history__pay-link">
                    View pay report &rarr;
                </a>
            </div>

            <!-- ── Stat cards ─────────────────────────────────── -->
            <div class="us-stat-cards">
                <div class="us-stat-card">
                    <div class="us-stat-value"><?php echo $total_confirmed; ?></div>
                    <div class="us-stat-label">Games worked</div>
                </div>
                <div class="us-stat-card us-stat-card--danger">
                    <div class="us-stat-value us-stat-value--danger"><?php echo $total_no_shows; ?></div>
                    <div class="us-stat-label">No-shows</div>
                </div>
                <div class="us-stat-card us-stat-card--accent">
                    <div class="us-stat-value us-stat-value--accent">$<?php echo number_format( $total_pay, 2 ); ?></div>
                    <div class="us-stat-label">Total earned</div>
                </div>
                <div class="us-stat-card us-stat-card--pending">
                    <div class="us-stat-value us-stat-value--pending">$<?php echo number_format( $pay_summary['total_outstanding'], 2 ); ?></div>
                    <div class="us-stat-label">Outstanding</div>
                </div>
            </div>

            <?php if ( empty( $assignments ) ) : ?>
                <p class="us-empty">No games on record for this umpire.</p>
            <?php else : ?>

            <!-- ── History by league ──────────────────────────── -->
            <?php foreach ( $by_league as $lid => $games ) :
                $league_name = $lid === 'unknown' ? 'Unknown League' : get_the_title( $lid );
                $league_worked = 0;
                $league_pay    = 0;
            ?>
            <h3><?php echo esc_html( $league_name ); ?></h3>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Game</th>
                        <th>Field</th>
                        <th>Position</th>
                        <th>Status</th>
                        <th>Pay</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $games as $a ) :
                        $game_id  = get_post_meta( $a->ID, 'us_game_id',    true );
                        $position = get_post_meta( $a->ID, 'us_position',   true );
                        $status   = get_post_meta( $a->ID, 'us_status',     true );
                        $pay      = floatval( get_post_meta( $a->ID, 'us_pay_amount', true ) );
                        $date     = get_post_meta( $game_id, 'us_game_date', true );
                        $time     = get_post_meta( $game_id, 'us_game_time', true );
                        $home     = get_post_meta( $game_id, 'us_home_team', true );
                        $away     = get_post_meta( $game_id, 'us_away_team', true );
                        $field    = get_post_meta( $game_id, 'us_field',     true );

                        $status_class = $status_colors[ $status ] ?? '';
                        if ( $status === 'confirmed' ) { $league_worked++; $league_pay += $pay; }
                    ?>
                    <tr>
                        <td data-label="Date"><?php echo $date ? esc_html( date( 'M j, Y', strtotime( $date ) ) ) : '—'; ?></td>
                        <td data-label="Time"><?php echo $time ? esc_html( date( 'g:i a', strtotime( $time ) ) )  : '—'; ?></td>
                        <td data-label="Game"><?php echo esc_html( $away . ' at ' . $home ); ?></td>
                        <td data-label="Field"><?php echo esc_html( $field ?: '—' ); ?></td>
                        <td data-label="Position"><?php echo esc_html( ucfirst( $position ) ); ?></td>
                        <td data-label="Status" class="<?php echo $status_class; ?>"><?php echo esc_html( ucfirst( $status ) ); ?></td>
                        <td data-label="Pay"><?php echo $status === 'confirmed' ? '$' . number_format( $pay, 2 ) : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="us-earnings-total">
                        <td colspan="5"><?php echo $league_worked; ?> games worked in <?php echo esc_html( $league_name ); ?></td>
                        <td></td>
                        <td>$<?php echo number_format( $league_pay, 2 ); ?></td>
                    </tr>
                </tbody>
            </table>
            <?php endforeach; ?>

            <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

    </div>
    <?php
    return ob_get_clean();
}