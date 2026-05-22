<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── My Schedule shortcode ─────────────────────────────────────
add_shortcode( 'umpire_schedule', 'us_shortcode_schedule' );
function us_shortcode_schedule() {
    if ( ! is_user_logged_in() ) return us_login_form();

    $umpire = us_get_umpire_by_user( get_current_user_id() );
    if ( ! $umpire ) return '<p class="us-empty">No umpire profile found.</p>';

    $umpire_id = $umpire->ID;
    $today     = current_time( 'Y-m-d' );

    $assignments = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_umpire_id', 'value' => $umpire_id, 'compare' => '=' ],
        ],
    ] );

    $upcoming = [];
    foreach ( $assignments as $a ) {
        $game_id   = get_post_meta( $a->ID, 'us_game_id',     true );
        $game_date = get_post_meta( $game_id, 'us_game_date', true );
        $status    = get_post_meta( $a->ID, 'us_status',      true );
        if ( $game_date >= $today
             && ! in_array( $status, [ 'declined', 'no-show', 'postponed' ] )
             && ! us_is_game_postponed( $game_id )
             && ! us_is_game_cancelled( $game_id ) ) {
            $upcoming[] = [
                'assignment' => $a,
                'game_id'    => $game_id,
                'game_date'  => $game_date,
                'status'     => $status,
            ];
        }
    }

    usort( $upcoming, function( $a, $b ) {
        $date_cmp = strcmp( $a['game_date'], $b['game_date'] );
        if ( $date_cmp !== 0 ) return $date_cmp;
        $time_a = get_post_meta( $a['game_id'], 'us_game_time', true );
        $time_b = get_post_meta( $b['game_id'], 'us_game_time', true );
        return strcmp( $time_a, $time_b );
    } );

    $ical_url = add_query_arg( [
        'us_ical_export' => '1',
        'nonce'          => wp_create_nonce( 'us_ical_export_' . get_current_user_id() ),
    ] );

    ob_start();
    ?>
    <div class="us-dashboard">
        <?php us_dashboard_notices(); ?>
        <h2>My schedule</h2>

        <div class="us-schedule-ical">
            <a href="<?php echo esc_url( $ical_url ); ?>" class="us-btn us-btn-request">
                &#8987; Download my schedule (.ics)
            </a>
            <span class="us-schedule-ical__hint">
                Import into Google Calendar, Apple Calendar or Outlook
            </span>
        </div>

        <?php if ( empty( $upcoming ) ) : ?>
            <p class="us-empty">No upcoming games assigned.</p>
        <?php else : ?>

        <div class="us-open-game-list">
            <?php foreach ( $upcoming as $item ) :
                $a         = $item['assignment'];
                $game_id   = $item['game_id'];
                $status    = $item['status'];
                $date      = get_post_meta( $game_id, 'us_game_date', true );
                $time      = get_post_meta( $game_id, 'us_game_time', true );
                $home      = get_post_meta( $game_id, 'us_home_team', true );
                $away      = get_post_meta( $game_id, 'us_away_team', true );
                $field     = get_post_meta( $game_id, 'us_field',     true );
                $league_id = get_post_meta( $game_id, 'us_league_id', true );
                $league    = $league_id ? get_the_title( $league_id ) : '';
                $position  = get_post_meta( $a->ID,   'us_position',  true );
                $date_obj  = $date ? new DateTime( $date ) : null;
                $is_today  = $date === $today;

                $confirm_url = add_query_arg( [ 'us_action' => 'confirm', 'assignment' => $a->ID, 'nonce' => wp_create_nonce( 'us_umpire_action_' . $a->ID ) ] );
                $decline_url = add_query_arg( [ 'us_action' => 'decline', 'assignment' => $a->ID, 'nonce' => wp_create_nonce( 'us_umpire_action_' . $a->ID ) ] );
            ?>
            <div class="us-og-card<?php echo $is_today ? ' us-og-card--today' : ''; ?>">
                <div class="us-og-card__header">

                    <?php if ( $date_obj ) : ?>
                    <div class="us-og-card__date<?php echo $is_today ? ' us-og-card__date--today' : ''; ?>">
                        <span class="us-og-card__date-day"><?php echo $date_obj->format( 'D' ); ?></span>
                        <span class="us-og-card__date-num"><?php echo $date_obj->format( 'M j' ); ?></span>
                        <span class="us-og-card__date-year"><?php echo $date_obj->format( 'Y' ); ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="us-og-card__info">
                        <div class="us-og-card__title">
                            <?php echo esc_html( $away . ' vs ' . $home ); ?>
                            <?php if ( $is_today ) : ?>
                                <span class="us-og-card__today-badge">TODAY</span>
                            <?php endif; ?>
                        </div>
                        <div class="us-og-card__meta">
                            <?php if ( $time ) : ?>
                                <span class="us-og-card__meta-time">&#9679; <?php echo esc_html( date( 'g:i a', strtotime( $time ) ) ); ?></span>
                            <?php endif; ?>
                            <?php if ( $field ) : ?>
                                <span>&#9679; <?php echo esc_html( $field ); ?></span>
                            <?php endif; ?>
                            <?php if ( $league ) : ?>
                                <span>&#9679; <?php echo esc_html( $league ); ?></span>
                            <?php endif; ?>
                            <?php if ( $position ) : ?>
                                <span>&#9679; <?php echo esc_html( ucfirst( $position ) ); ?> umpire</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="us-og-card__header-actions">
                        <?php if ( $status === 'pending' ) : ?>
                            <a href="<?php echo esc_url( $confirm_url ); ?>" class="us-og-btn us-og-btn--confirm">&#10003; Confirm</a>
                            <a href="<?php echo esc_url( $decline_url ); ?>" class="us-og-btn us-og-btn--cancel"
                               onclick="return confirm('Are you sure you want to decline this game?')">Decline</a>
                        <?php elseif ( $status === 'requested' ) : ?>
                            <span class="us-og-btn us-og-btn--pending">&#9679; Pending approval</span>
                            <button class="us-og-btn us-og-btn--cancel us-cancel-request"
                                    data-assignment="<?php echo $a->ID; ?>">Cancel</button>
                        <?php elseif ( $status === 'confirmed' ) : ?>
                            <span class="us-og-btn us-og-btn--confirmed">&#10003; Confirmed</span>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <p class="us-table-count">
            <?php echo count( $upcoming ); ?> upcoming game<?php echo count( $upcoming ) !== 1 ? 's' : ''; ?>
        </p>

        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}