<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Pending requests count (used in menu badge) ───────────────
function us_get_pending_requests_count() {
    return count( get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'fields'      => 'ids',
        'meta_query'  => [
            [ 'key' => 'us_status', 'value' => 'requested', 'compare' => '=' ],
        ],
    ] ) );
}

// ── Requests page ─────────────────────────────────────────────
function us_requests_page() {
    if ( isset( $_GET['us_req_action'] ) && isset( $_GET['assignment_id'] ) ) {
        us_handle_request_action();
    }

    $requests = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_status', 'value' => 'requested', 'compare' => '=' ],
        ],
    ] );

    // ── Group by game_id + position ───────────────────────────
    $grouped = [];
    foreach ( $requests as $req ) {
        $game_id  = get_post_meta( $req->ID, 'us_game_id',  true );
        $position = get_post_meta( $req->ID, 'us_position', true );
        $key      = $game_id . '_' . $position;
        if ( ! isset( $grouped[ $key ] ) ) {
            $grouped[ $key ] = [
                'game_id'  => $game_id,
                'position' => $position,
                'requests' => [],
            ];
        }
        $grouped[ $key ]['requests'][] = $req;
    }

    // ── Sort groups by game date ──────────────────────────────
    uasort( $grouped, function( $a, $b ) {
        $date_a = get_post_meta( $a['game_id'], 'us_game_date', true );
        $date_b = get_post_meta( $b['game_id'], 'us_game_date', true );
        return strcmp( $date_a, $date_b );
    } );

    $total = count( $requests );
    ?>
    <div class="wrap">
        <h1>
            Game Requests
            <?php if ( $total > 0 ) : ?>
                <span class="awaiting-mod"><?php echo $total; ?></span>
            <?php endif; ?>
        </h1>

        <?php if ( isset( $_GET['us_req_notice'] ) ) : ?>
            <?php if ( $_GET['us_req_notice'] === 'approved' ) : ?>
                <div class="notice notice-success is-dismissible"><p>Request approved — umpire confirmed and others notified.</p></div>
            <?php elseif ( $_GET['us_req_notice'] === 'denied' ) : ?>
                <div class="notice notice-info is-dismissible"><p>Request denied — umpire has been notified.</p></div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ( empty( $grouped ) ) : ?>
            <div class="notice notice-success">
                <p>No pending requests. You are all caught up!</p>
            </div>
        <?php else : ?>

        <p class="us-requests-summary">
            <?php echo $total; ?> pending request(s) across <?php echo count( $grouped ); ?> game slot(s).
            Confirming one umpire will automatically deny the others for that slot.
        </p>

        <?php foreach ( $grouped as $group ) :
            $game_id   = $group['game_id'];
            $position  = $group['position'];
            $reqs      = $group['requests'];
            $date      = get_post_meta( $game_id, 'us_game_date', true );
            $time      = get_post_meta( $game_id, 'us_game_time', true );
            $home      = get_post_meta( $game_id, 'us_home_team', true );
            $away      = get_post_meta( $game_id, 'us_away_team', true );
            $field     = get_post_meta( $game_id, 'us_field',     true );
            $league_id = get_post_meta( $game_id, 'us_league_id', true );
            $league    = $league_id ? get_the_title( $league_id ) : '—';
            $date_obj  = $date ? new DateTime( $date ) : null;
            $req_count = count( $reqs );
        ?>

        <div class="us-req-card">

            <div class="us-req-card__header">

                <?php if ( $date_obj ) : ?>
                <div class="us-req-card__date">
                    <span class="us-req-card__date-day"><?php echo $date_obj->format( 'D' ); ?></span>
                    <span class="us-req-card__date-num"><?php echo $date_obj->format( 'M j' ); ?></span>
                    <span class="us-req-card__date-year"><?php echo $date_obj->format( 'Y' ); ?></span>
                </div>
                <?php endif; ?>

                <div class="us-req-card__info">
                    <div class="us-req-card__title"><?php echo esc_html( $away . ' vs ' . $home ); ?></div>
                    <div class="us-req-card__meta">
                        <span>&#9679; <?php echo $time ? date( 'g:i a', strtotime( $time ) ) : '—'; ?></span>
                        <span>&#9679; <?php echo esc_html( $field ); ?></span>
                        <span>&#9679; <?php echo esc_html( $league ); ?></span>
                        <span class="us-req-card__position-badge"><?php echo esc_html( ucfirst( $position ) ); ?></span>
                    </div>
                </div>

                <div class="us-req-card__count">
                    <?php echo $req_count; ?> request<?php echo $req_count !== 1 ? 's' : ''; ?>
                </div>

            </div><!-- /.us-req-card__header -->

            <div class="us-req-card__umpires">
                <?php foreach ( $reqs as $req ) :
                    $umpire_id    = get_post_meta( $req->ID, 'us_umpire_id',  true );
                    $pay          = get_post_meta( $req->ID, 'us_pay_amount', true );
                    $umpire       = $umpire_id ? get_the_title( $umpire_id ) : '—';
                    $has_conflict = $umpire_id ? us_umpire_has_conflict( $umpire_id, $game_id ) : false;

                    $approve_url = wp_nonce_url(
                        admin_url( 'admin.php?page=us-requests&us_req_action=approve&assignment_id=' . $req->ID ),
                        'us_req_action_' . $req->ID
                    );
                    $deny_url = wp_nonce_url(
                        admin_url( 'admin.php?page=us-requests&us_req_action=deny&assignment_id=' . $req->ID ),
                        'us_req_action_' . $req->ID
                    );
                ?>
                <div class="us-req-card__umpire-row<?php echo $has_conflict ? ' us-req-card__umpire-row--conflict' : ''; ?>">

                    <div class="us-req-card__umpire-info">
                        <strong><?php echo esc_html( $umpire ); ?></strong>
                        <?php if ( $pay ) : ?>
                            <span class="us-req-card__pay"><?php echo '$' . number_format( floatval( $pay ), 2 ); ?></span>
                        <?php endif; ?>
                        <?php if ( $has_conflict ) : ?>
                            <span class="us-req-card__conflict-badge">&#9888; Conflict</span>
                        <?php endif; ?>
                    </div>

                    <div class="us-req-card__umpire-actions">
                        <a href="<?php echo esc_url( $approve_url ); ?>"
                           class="us-req-btn us-req-btn--confirm"
                           onclick="return confirm('Confirm <?php echo esc_js( $umpire ); ?> for this slot? All other requests for this slot will be automatically denied.')">
                            &#10003; Confirm
                        </a>
                        <a href="<?php echo esc_url( $deny_url ); ?>"
                           class="us-req-btn us-req-btn--deny"
                           onclick="return confirm('Deny this request from <?php echo esc_js( $umpire ); ?>?')">
                            &#10005; Deny
                        </a>
                    </div>

                </div><!-- /.us-req-card__umpire-row -->
                <?php endforeach; ?>
            </div><!-- /.us-req-card__umpires -->

        </div><!-- /.us-req-card -->
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
}

// ── Handle approve / deny actions ────────────────────────────
function us_handle_request_action() {
    $assignment_id = absint( $_GET['assignment_id'] ?? 0 );
    $action        = sanitize_text_field( $_GET['us_req_action'] ?? '' );

    if ( ! $assignment_id || ! in_array( $action, [ 'approve', 'deny' ] ) ) return;
    if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'us_req_action_' . $assignment_id ) ) {
        wp_die( 'Security check failed.' );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized.' );
    }

    if ( $action === 'approve' ) {
        $game_id  = get_post_meta( $assignment_id, 'us_game_id',  true );
        $position = get_post_meta( $assignment_id, 'us_position', true );

        // Confirm the target first — outside the siblings query so a race
        // condition can never cause a silent no-op
        update_post_meta( $assignment_id, 'us_status', 'confirmed' );
        us_notify_umpire_confirmed( $assignment_id );

        // Deny and delete every other request for the same slot
        $siblings = get_posts( [
            'post_type'   => US_PT_ASSIGNMENT,
            'numberposts' => -1,
            'post_status' => 'publish',
            'meta_query'  => [
                [ 'key' => 'us_game_id',  'value' => $game_id,  'compare' => '=' ],
                [ 'key' => 'us_position', 'value' => $position, 'compare' => '=' ],
                [ 'key' => 'us_status',   'value' => [ 'requested', 'pending' ], 'compare' => 'IN' ],
            ],
        ] );

        foreach ( $siblings as $s ) {
            if ( $s->ID === $assignment_id ) continue;
            us_notify_umpire_denied( $s->ID );
            wp_delete_post( $s->ID, true );
        }

        wp_redirect( admin_url( 'admin.php?page=us-requests&us_req_notice=approved' ) );
        exit;
    }

    if ( $action === 'deny' ) {
        us_notify_umpire_denied( $assignment_id );
        wp_delete_post( $assignment_id, true );
        wp_redirect( admin_url( 'admin.php?page=us-requests&us_req_notice=denied' ) );
        exit;
    }
}