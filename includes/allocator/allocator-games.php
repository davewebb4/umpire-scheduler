<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Conflict detection ────────────────────────────────────────
// Flags a double-booking only when the umpire is confirmed at a DIFFERENT field on the same day
// AND the game windows overlap (games are treated as 75 minutes long).
// Same field at any time = same diamond = fine.
define( 'US_GAME_DURATION_MINS', 75 );

function us_umpire_has_date_conflict( $umpire_id, $game_id, $date, $field ) {
    static $cache = [];
    if ( ! $umpire_id || ! $date || ! $field ) return false;

    $cache_key = $umpire_id . '_' . $game_id . '_' . $date;
    if ( array_key_exists( $cache_key, $cache ) ) return $cache[ $cache_key ];

    $game_time    = get_post_meta( $game_id, 'us_game_time', true );
    $game_mins    = $game_time ? ( (int) date( 'G', strtotime( $game_time ) ) * 60 + (int) date( 'i', strtotime( $game_time ) ) ) : null;

    $same_day = get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'post_status' => 'publish',
        'fields'      => 'ids',
        'meta_query'  => [ [ 'key' => 'us_game_date', 'value' => $date, 'compare' => '=' ] ],
    ] );

    // Keep only games at a different non-empty field whose 75-min window overlaps this game's window
    $other_games = array_values( array_filter( $same_day, function( $id ) use ( $game_id, $field, $game_mins ) {
        if ( (int) $id === (int) $game_id ) return false;
        $other_field = get_post_meta( $id, 'us_field', true );
        if ( ! $other_field || $other_field === $field ) return false;

        // If either game has no time, assume the worst and flag it
        $other_time = get_post_meta( $id, 'us_game_time', true );
        if ( $game_mins === null || ! $other_time ) return true;

        $other_mins = (int) date( 'G', strtotime( $other_time ) ) * 60 + (int) date( 'i', strtotime( $other_time ) );
        // Two 75-min windows overlap when start times are less than 75 min apart
        return abs( $game_mins - $other_mins ) < US_GAME_DURATION_MINS;
    } ) );

    if ( empty( $other_games ) ) {
        return $cache[ $cache_key ] = false;
    }

    $conflict = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => 1,
        'post_status' => 'publish',
        'fields'      => 'ids',
        'meta_query'  => [
            [ 'key' => 'us_umpire_id', 'value' => $umpire_id,   'compare' => '='  ],
            [ 'key' => 'us_game_id',   'value' => $other_games, 'compare' => 'IN' ],
            [ 'key' => 'us_status',    'value' => 'confirmed',  'compare' => '='  ],
        ],
    ] );

    return $cache[ $cache_key ] = ! empty( $conflict );
}

// ── AJAX: Add game ────────────────────────────────────────────
add_action( 'wp_ajax_us_allocator_add_game', 'us_ajax_allocator_add_game' );
function us_ajax_allocator_add_game() {
    check_ajax_referer( 'us_assign_nonce', 'nonce' );
    if ( ! us_is_allocator() ) wp_send_json_error( 'Unauthorized' );

    $league_id = absint( $_POST['league_id']   ?? 0 );
    $date      = sanitize_text_field( $_POST['game_date']  ?? '' );
    $time      = sanitize_text_field( $_POST['game_time']  ?? '' );
    $home      = sanitize_text_field( $_POST['home_team']  ?? '' );
    $away      = sanitize_text_field( $_POST['away_team']  ?? '' );
    $field     = sanitize_text_field( $_POST['game_field'] ?? '' );
    $two_umps  = isset( $_POST['two_umpires'] )   && $_POST['two_umpires']   === '1' ? '1' : '0';
    $is_dh     = isset( $_POST['double_header'] ) && $_POST['double_header'] === '1' ? '1' : '0';

    if ( ! $league_id || ! $date || ! $home || ! $away ) {
        wp_send_json_error( 'Please fill in all required fields.' );
    }

    $game_id = wp_insert_post( [
        'post_type'   => US_PT_GAME,
        'post_title'  => $away . ' at ' . $home . ' — ' . $date,
        'post_status' => 'publish',
    ] );

    if ( is_wp_error( $game_id ) ) wp_send_json_error( 'Could not create game.' );

    update_post_meta( $game_id, 'us_league_id',     $league_id );
    update_post_meta( $game_id, 'us_game_date',     $date );
    update_post_meta( $game_id, 'us_game_time',     $time );
    update_post_meta( $game_id, 'us_home_team',     $home );
    update_post_meta( $game_id, 'us_away_team',     $away );
    update_post_meta( $game_id, 'us_field',         $field );
    update_post_meta( $game_id, 'us_two_umpires',   $two_umps );
    update_post_meta( $game_id, 'us_double_header', $is_dh );
    update_post_meta( $game_id, 'us_game_status',   'active' );

    wp_send_json_success( [ 'game_id' => $game_id, 'msg' => 'Game added successfully.' ] );
}

// ── AJAX: Edit game ───────────────────────────────────────────
add_action( 'wp_ajax_us_allocator_update_game', 'us_ajax_allocator_update_game' );
function us_ajax_allocator_update_game() {
    check_ajax_referer( 'us_assign_nonce', 'nonce' );
    if ( ! us_is_allocator() ) wp_send_json_error( 'Unauthorized' );

    $game_id   = absint( $_POST['game_id']     ?? 0 );
    $league_id = absint( $_POST['league_id']   ?? 0 );
    $new_date  = sanitize_text_field( $_POST['game_date']  ?? '' );
    $new_time  = sanitize_text_field( $_POST['game_time']  ?? '' );
    $new_home  = sanitize_text_field( $_POST['home_team']  ?? '' );
    $new_away  = sanitize_text_field( $_POST['away_team']  ?? '' );
    $new_field = sanitize_text_field( $_POST['game_field'] ?? '' );
    $two_umps  = isset( $_POST['two_umpires'] )   && $_POST['two_umpires']   === '1' ? '1' : '0';
    $new_dh    = isset( $_POST['double_header'] ) && $_POST['double_header'] === '1' ? '1' : '0';

    if ( ! $game_id || ! $league_id || ! $new_date || ! $new_home || ! $new_away ) {
        wp_send_json_error( 'Please fill in all required fields.' );
    }

    $old_date  = get_post_meta( $game_id, 'us_game_date',     true );
    $old_time  = get_post_meta( $game_id, 'us_game_time',     true );
    $old_field = get_post_meta( $game_id, 'us_field',         true );
    $old_dh    = get_post_meta( $game_id, 'us_double_header', true ) ?: '0';

    $changes = [];
    if ( $new_date  !== $old_date  ) $changes['date']  = [ 'old' => $old_date,  'new' => $new_date  ];
    if ( $new_time  !== $old_time  ) $changes['time']  = [ 'old' => $old_time,  'new' => $new_time  ];
    if ( $new_field !== $old_field ) $changes['field'] = [ 'old' => $old_field, 'new' => $new_field ];

    update_post_meta( $game_id, 'us_league_id',     $league_id );
    update_post_meta( $game_id, 'us_game_date',     $new_date );
    update_post_meta( $game_id, 'us_game_time',     $new_time );
    update_post_meta( $game_id, 'us_home_team',     $new_home );
    update_post_meta( $game_id, 'us_away_team',     $new_away );
    update_post_meta( $game_id, 'us_field',         $new_field );
    update_post_meta( $game_id, 'us_two_umpires',   $two_umps );
    update_post_meta( $game_id, 'us_double_header', $new_dh );

    wp_update_post( [
        'ID'         => $game_id,
        'post_title' => $new_away . ' at ' . $new_home . ' — ' . $new_date,
    ] );

    if ( $new_dh !== $old_dh ) {
        $new_rate    = us_get_game_pay_rate( $game_id );
        $assignments = get_posts( [
            'post_type'   => US_PT_ASSIGNMENT,
            'numberposts' => -1,
            'post_status' => 'publish',
            'meta_query'  => [
                [ 'key' => 'us_game_id', 'value' => $game_id, 'compare' => '=' ],
            ],
        ] );
        foreach ( $assignments as $a ) {
            update_post_meta( $a->ID, 'us_pay_amount', $new_rate );
        }
    }

    $notify_changes = array_intersect_key( $changes, array_flip( [ 'date', 'time', 'field' ] ) );
    $notified = ! empty( $notify_changes ) ? us_notify_game_changed( $game_id, $notify_changes ) : 0;

    wp_send_json_success( [
        'msg'      => 'Game updated.' . ( $notified > 0 ? ' ' . $notified . ' umpire(s) notified.' : '' ),
        'notified' => $notified,
    ] );
}

// ── AJAX: Delete game ─────────────────────────────────────────
add_action( 'wp_ajax_us_allocator_delete_game', 'us_ajax_allocator_delete_game' );
function us_ajax_allocator_delete_game() {
    check_ajax_referer( 'us_assign_nonce', 'nonce' );
    if ( ! us_is_allocator() ) wp_send_json_error( 'Unauthorized' );

    $game_id = absint( $_POST['game_id'] ?? 0 );
    if ( ! $game_id ) wp_send_json_error( 'Invalid game' );

    $assignments = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_game_id', 'value' => $game_id, 'compare' => '=' ],
            [ 'key' => 'us_status',  'value' => [ 'confirmed', 'pending', 'requested' ], 'compare' => 'IN' ],
        ],
    ] );

    $home     = get_post_meta( $game_id, 'us_home_team', true );
    $away     = get_post_meta( $game_id, 'us_away_team', true );
    $date     = get_post_meta( $game_id, 'us_game_date', true );
    $date_fmt = $date ? date( 'l, F j, Y', strtotime( $date ) ) : '';

    foreach ( $assignments as $a ) {
        $umpire_id = get_post_meta( $a->ID, 'us_umpire_id', true );
        $email     = get_post_meta( $umpire_id, 'us_email', true );
        $umpire    = get_the_title( $umpire_id );
        if ( $email ) {
            $message  = "Hi {$umpire},\n\n";
            $message .= "The following game has been cancelled:\n\n";
            $message .= "Game:  {$away} at {$home}\n";
            $message .= "Date:  {$date_fmt}\n\n";
            $message .= "Thanks,\n" . us_setting( 'email_footer' );
            wp_mail( $email, 'Game cancelled — ' . $date_fmt, $message );
        }
        wp_delete_post( $a->ID, true );
    }

    wp_delete_post( $game_id, true );
}

// ── AJAX: Cancel game (keeps record, removes assignments) ─────
add_action( 'wp_ajax_us_allocator_cancel_game', 'us_ajax_allocator_cancel_game' );
function us_ajax_allocator_cancel_game() {
    check_ajax_referer( 'us_assign_nonce', 'nonce' );
    if ( ! us_is_allocator() ) wp_send_json_error( 'Unauthorized' );

    $game_id = absint( $_POST['game_id'] ?? 0 );
    if ( ! $game_id ) wp_send_json_error( 'Invalid game' );

    update_post_meta( $game_id, 'us_game_status', 'cancelled' );

    $assignments = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_game_id', 'value' => $game_id, 'compare' => '=' ],
            [ 'key' => 'us_status',  'value' => [ 'confirmed', 'pending', 'requested' ], 'compare' => 'IN' ],
        ],
    ] );

    $notified = 0;
    foreach ( $assignments as $a ) {
        us_notify_umpire_cancelled( $a->ID );
        wp_delete_post( $a->ID, true );
        $notified++;
    }

    wp_send_json_success( [
        'game_id'  => $game_id,
        'notified' => $notified,
        'msg'      => 'Game cancelled. ' . $notified . ' umpire(s) notified.',
    ] );
    wp_send_json_success( [ 'game_id' => $game_id ] );
}

// ── AJAX: Assign umpire ───────────────────────────────────────
add_action( 'wp_ajax_us_games_assign_umpire', 'us_ajax_games_assign_umpire' );
function us_ajax_games_assign_umpire() {
    check_ajax_referer( 'us_assign_nonce', 'nonce' );
    if ( ! us_is_allocator() ) wp_send_json_error( 'Unauthorized' );

    $game_id   = absint( $_POST['game_id']   ?? 0 );
    $umpire_id = absint( $_POST['umpire_id'] ?? 0 );
    $position  = sanitize_text_field( $_POST['position'] ?? '' );

    if ( ! $game_id || ! $umpire_id || ! in_array( $position, [ 'plate', 'base' ] ) ) {
        wp_send_json_error( 'Invalid data' );
    }

    $pay_rate    = us_get_game_pay_rate( $game_id );
    $umpire_name = get_the_title( $umpire_id );
    $game_title  = get_the_title( $game_id );

    $pending = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_game_id',  'value' => $game_id,  'compare' => '=' ],
            [ 'key' => 'us_position', 'value' => $position, 'compare' => '=' ],
            [ 'key' => 'us_status',   'value' => [ 'requested', 'pending' ], 'compare' => 'IN' ],
        ],
    ] );
    foreach ( $pending as $p ) {
        us_notify_umpire_denied( $p->ID );
        wp_delete_post( $p->ID, true );
    }

    $existing = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => 1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_game_id',  'value' => $game_id,    'compare' => '=' ],
            [ 'key' => 'us_position', 'value' => $position,   'compare' => '=' ],
            [ 'key' => 'us_status',   'value' => 'confirmed', 'compare' => '=' ],
        ],
    ] );

    if ( $existing ) {
        $assignment_id = $existing[0]->ID;
        update_post_meta( $assignment_id, 'us_umpire_id',  $umpire_id );
        update_post_meta( $assignment_id, 'us_pay_amount', $pay_rate );
        wp_update_post( [ 'ID' => $assignment_id, 'post_title' => $game_title . ' (' . ucfirst( $position ) . ')' ] );
    } else {
        $assignment_id = wp_insert_post( [
            'post_type'   => US_PT_ASSIGNMENT,
            'post_title'  => $game_title . ' (' . ucfirst( $position ) . ')',
            'post_status' => 'publish',
        ] );
        update_post_meta( $assignment_id, 'us_game_id',    $game_id );
        update_post_meta( $assignment_id, 'us_umpire_id',  $umpire_id );
        update_post_meta( $assignment_id, 'us_position',   $position );
        update_post_meta( $assignment_id, 'us_status',     'confirmed' );
        update_post_meta( $assignment_id, 'us_pay_amount', $pay_rate );
    }

    us_notify_umpire_assigned( $assignment_id );

    wp_send_json_success( [
        'assignment_id' => $assignment_id,
        'umpire_name'   => $umpire_name,
        'denied'        => count( $pending ),
        'msg'           => $umpire_name . ' confirmed.' . ( count( $pending ) > 0 ? ' ' . count( $pending ) . ' pending request(s) denied.' : '' ),
    ] );
}

// ── AJAX: Clear assignment ────────────────────────────────────
add_action( 'wp_ajax_us_games_clear_assignment', 'us_ajax_games_clear_assignment' );
function us_ajax_games_clear_assignment() {
    check_ajax_referer( 'us_assign_nonce', 'nonce' );
    if ( ! us_is_allocator() ) wp_send_json_error( 'Unauthorized' );

    $game_id  = absint( $_POST['game_id']  ?? 0 );
    $position = sanitize_text_field( $_POST['position'] ?? '' );

    if ( ! $game_id || ! in_array( $position, [ 'plate', 'base' ] ) ) {
        wp_send_json_error( 'Invalid data' );
    }

    $existing = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => 1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_game_id',  'value' => $game_id,    'compare' => '=' ],
            [ 'key' => 'us_position', 'value' => $position,   'compare' => '=' ],
            [ 'key' => 'us_status',   'value' => 'confirmed', 'compare' => '=' ],
        ],
    ] );

    if ( $existing ) wp_delete_post( $existing[0]->ID, true );

    wp_send_json_success( [ 'msg' => 'Assignment cleared.' ] );
}

// ── Doubleheader game card renderer ──────────────────────────
function us_render_dh_mgmt_card( $games, $today, $selected_league_id, $all_umpires, $suppress_past = false ) {
    $game1     = $games[0];
    $date      = get_post_meta( $game1->ID, 'us_game_date', true );
    $home      = get_post_meta( $game1->ID, 'us_home_team', true );
    $away      = get_post_meta( $game1->ID, 'us_away_team', true );
    $field     = get_post_meta( $game1->ID, 'us_field',     true );
    $league_id = get_post_meta( $game1->ID, 'us_league_id', true );
    $league    = $league_id ? get_the_title( $league_id ) : '';
    $is_today  = $date === $today;
    $is_past   = $date < $today;
    $date_obj  = $date ? new DateTime( $date ) : null;

    // Availability is the same for both games (same date) — fetch once
    $unavail_ids  = us_get_unavailable_umpires( $date );
    $assigned_ids = us_get_assigned_umpires_on_date( $date );
    $is_admin     = current_user_can( 'manage_options' );
    $avail = $busy = $unavail_umps = [];
    foreach ( $all_umpires as $u ) {
        if ( in_array( $u->ID, $unavail_ids ) ) {
            if ( $is_admin ) $unavail_umps[] = $u;
            continue;
        }
        if ( in_array( $u->ID, $assigned_ids ) ) { $busy[] = $u; } else { $avail[] = $u; }
    }

    $card_class = 'us-mgmt-card us-mgmt-card--dh';
    if ( $is_today )                   $card_class .= ' us-mgmt-card--today';
    if ( $is_past && ! $suppress_past ) $card_class .= ' us-mgmt-card--past';

    ob_start();
    ?>
    <div class="<?php echo $card_class; ?>">

        <!-- Shared header: date + matchup + field/league -->
        <div class="us-mgmt-card__header">
            <?php if ( $date_obj ) : ?>
            <div class="us-mgmt-card__date-pill<?php echo $is_today ? ' us-mgmt-card__date-pill--today' : ''; ?>">
                <span class="us-mgmt-card__date-day"><?php echo $date_obj->format( 'D' ); ?></span>
                <span class="us-mgmt-card__date-num"><?php echo $date_obj->format( 'M j' ); ?></span>
                <span class="us-mgmt-card__date-year"><?php echo $date_obj->format( 'Y' ); ?></span>
            </div>
            <?php endif; ?>
            <div class="us-mgmt-card__info">
                <div class="us-mgmt-card__matchup">
                    <?php echo esc_html( $away . ' vs ' . $home ); ?>
                    <span class="us-alloc-games__badge us-alloc-games__badge--dh">DH</span>
                    <?php if ( $is_today ) : ?>
                        <span class="us-alloc__today-badge">TODAY</span>
                    <?php endif; ?>
                </div>
                <div class="us-mgmt-card__meta">
                    <?php if ( $field ) : ?>
                        <span>&#9679; <?php echo esc_html( $field ); ?></span>
                    <?php endif; ?>
                    <?php if ( $selected_league_id === 0 && $league ) : ?>
                        <span>&#9679; <?php echo esc_html( $league ); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Individual game rows -->
        <?php foreach ( $games as $g_num => $game ) :
            $time         = get_post_meta( $game->ID, 'us_game_time',     true );
            $two_umps     = get_post_meta( $game->ID, 'us_two_umpires',   true ) === '1';
            $is_dh_meta   = get_post_meta( $game->ID, 'us_double_header', true ) === '1';
            $g_league_id  = get_post_meta( $game->ID, 'us_league_id',     true );
            $is_postponed = us_is_game_postponed( $game->ID );
            $is_cancelled = us_is_game_cancelled( $game->ID );
            $is_locked    = $is_postponed || $is_cancelled;

            $plate           = us_get_confirmed_assignment( $game->ID, 'plate' );
            $base            = us_get_confirmed_assignment( $game->ID, 'base' );
            $plate_umpire_id = $plate ? (int) get_post_meta( $plate->ID, 'us_umpire_id', true ) : 0;
            $base_umpire_id  = $base  ? (int) get_post_meta( $base->ID,  'us_umpire_id', true ) : 0;
            $plate_name      = $plate_umpire_id ? get_the_title( $plate_umpire_id ) : null;
            $base_name       = $base_umpire_id  ? get_the_title( $base_umpire_id )  : null;
            $plate_conflict  = $plate_umpire_id ? us_umpire_has_date_conflict( $plate_umpire_id, $game->ID, $date, $g_field ) : false;
            $base_conflict   = $base_umpire_id  ? us_umpire_has_date_conflict( $base_umpire_id,  $game->ID, $date, $g_field ) : false;
            $plate_reqs      = us_get_slot_requests( $game->ID, 'plate' );
            $base_reqs       = us_get_slot_requests( $game->ID, 'base' );

            $all_game_reqs = get_posts( [
                'post_type'   => US_PT_ASSIGNMENT,
                'numberposts' => -1,
                'post_status' => 'publish',
                'meta_query'  => [
                    [ 'key' => 'us_game_id', 'value' => $game->ID, 'compare' => '=' ],
                    [ 'key' => 'us_status',  'value' => [ 'requested', 'pending' ], 'compare' => 'IN' ],
                ],
            ] );
            $pending_uids = array_map( fn( $r ) => get_post_meta( $r->ID, 'us_umpire_id', true ), $all_game_reqs );

            if ( $is_cancelled ) {
                $status_label = 'Cancelled'; $status_class = 'us-alloc__status--cancelled';
            } elseif ( $is_postponed ) {
                $status_label = 'Postponed'; $status_class = 'us-alloc__status--postponed';
            } elseif ( $plate && ( ! $two_umps || $base ) ) {
                $status_label = 'Confirmed'; $status_class = 'us-status-confirmed';
            } elseif ( $plate || $base ) {
                $status_label = 'Partial';   $status_class = 'us-status-pending';
            } else {
                $status_label = 'Open';      $status_class = 'us-status-declined';
            }

            $g_home  = get_post_meta( $game->ID, 'us_home_team', true );
            $g_away  = get_post_meta( $game->ID, 'us_away_team', true );
            $g_field = get_post_meta( $game->ID, 'us_field',     true );
        ?>

        <?php if ( $g_num > 0 ) : ?>
            <div class="us-mgmt-card__dh-divider"></div>
        <?php endif; ?>

        <div class="us-mgmt-card__dh-game">

            <div class="us-mgmt-card__dh-game-header">
                <div class="us-mgmt-card__dh-game-meta">
                    <span class="us-mgmt-card__dh-label">Game <?php echo $g_num + 1; ?></span>
                    <?php if ( $time ) : ?>
                        <span class="us-mgmt-card__meta-time">&#9679; <?php echo esc_html( date( 'g:i a', strtotime( $time ) ) ); ?></span>
                    <?php endif; ?>
                    <?php if ( $is_dh_meta ) : ?>
                        <span class="us-alloc-games__badge us-alloc-games__badge--dh">Optional rate</span>
                    <?php endif; ?>
                    <span class="us-mgmt-card__status <?php echo $status_class; ?>"><?php echo $status_label; ?></span>
                </div>
                <div class="us-mgmt-card__header-actions">
                    <?php if ( ! $is_locked ) : ?>
                    <button type="button"
                            class="us-alloc-game-edit-btn us-alloc__action-btn us-alloc-games__action-btn--edit"
                            data-game="<?php echo $game->ID; ?>"
                            data-league="<?php echo $g_league_id; ?>"
                            data-date="<?php echo esc_attr( $date ); ?>"
                            data-time="<?php echo esc_attr( $time ); ?>"
                            data-home="<?php echo esc_attr( $g_home ); ?>"
                            data-away="<?php echo esc_attr( $g_away ); ?>"
                            data-field="<?php echo esc_attr( $g_field ); ?>"
                            data-two="<?php echo $two_umps ? '1' : '0'; ?>"
                            data-dh="<?php echo $is_dh_meta ? '1' : '0'; ?>">
                        Edit
                    </button>
                    <button type="button"
                            class="us-alloc-game-postpone-btn us-alloc__action-btn us-alloc__action-btn--postpone"
                            data-game="<?php echo $game->ID; ?>"
                            data-label="<?php echo esc_attr( $g_away . ' at ' . $g_home ); ?>">
                        Postpone
                    </button>
                    <button type="button"
                            class="us-alloc-game-cancel-btn us-alloc__action-btn us-alloc__action-btn--cancel"
                            data-game="<?php echo $game->ID; ?>"
                            data-label="<?php echo esc_attr( $g_away . ' at ' . $g_home ); ?>">
                        Cancel
                    </button>
                    <?php endif; ?>
                    <button type="button"
                            class="us-alloc-game-delete-btn us-alloc__action-btn us-alloc-games__action-btn--delete"
                            data-game="<?php echo $game->ID; ?>"
                            data-label="<?php echo esc_attr( $g_away . ' at ' . $g_home ); ?>">
                        Delete
                    </button>
                </div>
            </div>

            <div class="us-mgmt-card__umpires">
                <div class="us-mgmt-card__slot">
                    <span class="us-mgmt-card__slot-label">Plate</span>
                    <?php echo us_allocator_games_umpire_cell( $game->ID, 'plate', $plate_name, $plate, $plate_reqs, $avail, $busy, $pending_uids, false, $is_locked, $plate_conflict, $unavail_umps ); ?>
                </div>
                <?php if ( $two_umps ) : ?>
                <div class="us-mgmt-card__slot">
                    <span class="us-mgmt-card__slot-label">Base</span>
                    <?php echo us_allocator_games_umpire_cell( $game->ID, 'base', $base_name, $base, $base_reqs, $avail, $busy, $pending_uids, false, $is_locked, $base_conflict, $unavail_umps ); ?>
                </div>
                <?php endif; ?>
            </div>

        </div><!-- /.us-mgmt-card__dh-game -->

        <?php endforeach; ?>

    </div>
    <?php
    return ob_get_clean();
}

// ── Shared game card renderer ─────────────────────────────────
function us_render_mgmt_game_card( $game, $today, $selected_league_id, $all_umpires, $suppress_past = false ) {
    $date         = get_post_meta( $game->ID, 'us_game_date',     true );
    $time         = get_post_meta( $game->ID, 'us_game_time',     true );
    $home         = get_post_meta( $game->ID, 'us_home_team',     true );
    $away         = get_post_meta( $game->ID, 'us_away_team',     true );
    $field        = get_post_meta( $game->ID, 'us_field',         true );
    $two_umps     = get_post_meta( $game->ID, 'us_two_umpires',   true ) === '1';
    $is_dh        = get_post_meta( $game->ID, 'us_double_header', true ) === '1';
    $league_id    = get_post_meta( $game->ID, 'us_league_id',     true );
    $league_name  = $league_id ? get_the_title( $league_id ) : '';
    $is_postponed = us_is_game_postponed( $game->ID );
    $is_cancelled = us_is_game_cancelled( $game->ID );
    $is_locked    = $is_postponed || $is_cancelled;
    $is_past      = $date < $today;
    $is_today     = $date === $today;
    $date_obj     = $date ? new DateTime( $date ) : null;

    $plate           = us_get_confirmed_assignment( $game->ID, 'plate' );
    $base            = us_get_confirmed_assignment( $game->ID, 'base' );
    $plate_umpire_id = $plate ? (int) get_post_meta( $plate->ID, 'us_umpire_id', true ) : 0;
    $base_umpire_id  = $base  ? (int) get_post_meta( $base->ID,  'us_umpire_id', true ) : 0;
    $plate_name      = $plate_umpire_id ? get_the_title( $plate_umpire_id ) : null;
    $base_name       = $base_umpire_id  ? get_the_title( $base_umpire_id )  : null;
    $plate_conflict  = $plate_umpire_id ? us_umpire_has_date_conflict( $plate_umpire_id, $game->ID, $date, $field ) : false;
    $base_conflict   = $base_umpire_id  ? us_umpire_has_date_conflict( $base_umpire_id,  $game->ID, $date, $field ) : false;

    $plate_reqs = us_get_slot_requests( $game->ID, 'plate' );
    $base_reqs  = us_get_slot_requests( $game->ID, 'base' );

    if ( $is_cancelled ) {
        $status_label = 'Cancelled';
        $status_class = 'us-alloc__status--cancelled';
    } elseif ( $is_postponed ) {
        $status_label = 'Postponed';
        $status_class = 'us-alloc__status--postponed';
    } elseif ( $plate && ( ! $two_umps || $base ) ) {
        $status_label = 'Confirmed';
        $status_class = 'us-status-confirmed';
    } elseif ( $plate || $base ) {
        $status_label = 'Partial';
        $status_class = 'us-status-pending';
    } else {
        $status_label = 'Open';
        $status_class = 'us-status-declined';
    }

    $unavail_ids  = us_get_unavailable_umpires( $date );
    $assigned_ids = us_get_assigned_umpires_on_date( $date );
    $is_admin     = current_user_can( 'manage_options' );

    $all_game_requests = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_game_id', 'value' => $game->ID, 'compare' => '=' ],
            [ 'key' => 'us_status',  'value' => [ 'requested', 'pending' ], 'compare' => 'IN' ],
        ],
    ] );
    $pending_uids = [];
    foreach ( $all_game_requests as $gr ) {
        $pending_uids[] = get_post_meta( $gr->ID, 'us_umpire_id', true );
    }

    $avail = $busy = $unavail_umps = [];
    foreach ( $all_umpires as $u ) {
        if ( in_array( $u->ID, $unavail_ids ) ) {
            if ( $is_admin ) $unavail_umps[] = $u;
            continue;
        }
        if ( in_array( $u->ID, $assigned_ids ) ) { $busy[] = $u; } else { $avail[] = $u; }
    }

    $card_class = 'us-mgmt-card';
    if ( $is_postponed )               $card_class .= ' us-mgmt-card--postponed';
    if ( $is_cancelled )               $card_class .= ' us-mgmt-card--cancelled';
    if ( $is_today )                   $card_class .= ' us-mgmt-card--today';
    if ( $is_past && ! $suppress_past ) $card_class .= ' us-mgmt-card--past';

    ob_start();
    ?>
    <div class="<?php echo $card_class; ?>">

        <!-- Header: date pill + game info + action buttons -->
        <div class="us-mgmt-card__header">

            <?php if ( $date_obj ) : ?>
            <div class="us-mgmt-card__date-pill<?php echo $is_today ? ' us-mgmt-card__date-pill--today' : ''; ?>">
                <span class="us-mgmt-card__date-day"><?php echo $date_obj->format( 'D' ); ?></span>
                <span class="us-mgmt-card__date-num"><?php echo $date_obj->format( 'M j' ); ?></span>
                <span class="us-mgmt-card__date-year"><?php echo $date_obj->format( 'Y' ); ?></span>
            </div>
            <?php endif; ?>

            <div class="us-mgmt-card__info">
                <div class="us-mgmt-card__matchup">
                    <?php echo esc_html( $away . ' vs ' . $home ); ?>
                    <?php if ( $two_umps ) : ?>
                        <span class="us-alloc-games__badge us-alloc-games__badge--two">2 umps</span>
                    <?php endif; ?>
                    <?php if ( $is_dh ) : ?>
                        <span class="us-alloc-games__badge us-alloc-games__badge--dh">DH</span>
                    <?php endif; ?>
                    <?php if ( $is_postponed ) : ?>
                        <span class="us-alloc-games__badge us-alloc-games__badge--postponed">Postponed</span>
                    <?php endif; ?>
                    <?php if ( $is_cancelled ) : ?>
                        <span class="us-alloc-games__badge us-alloc-games__badge--cancelled">Cancelled</span>
                    <?php endif; ?>
                    <?php if ( $is_today ) : ?>
                        <span class="us-alloc__today-badge">TODAY</span>
                    <?php endif; ?>
                </div>
                <div class="us-mgmt-card__meta">
                    <?php if ( $time ) : ?>
                        <span class="us-mgmt-card__meta-time">&#9679; <?php echo esc_html( date( 'g:i a', strtotime( $time ) ) ); ?></span>
                    <?php endif; ?>
                    <?php if ( $field ) : ?>
                        <span>&#9679; <?php echo esc_html( $field ); ?></span>
                    <?php endif; ?>
                    <?php if ( $selected_league_id === 0 && $league_name ) : ?>
                        <span>&#9679; <?php echo esc_html( $league_name ); ?></span>
                    <?php endif; ?>
                    <span class="us-mgmt-card__status <?php echo $status_class; ?>"><?php echo $status_label; ?></span>
                </div>
            </div>

            <div class="us-mgmt-card__header-actions">
                <?php if ( ! $is_locked ) : ?>
                <button type="button"
                        class="us-alloc-game-edit-btn us-alloc__action-btn us-alloc-games__action-btn--edit"
                        data-game="<?php echo $game->ID; ?>"
                        data-league="<?php echo $league_id; ?>"
                        data-date="<?php echo esc_attr( $date ); ?>"
                        data-time="<?php echo esc_attr( $time ); ?>"
                        data-home="<?php echo esc_attr( $home ); ?>"
                        data-away="<?php echo esc_attr( $away ); ?>"
                        data-field="<?php echo esc_attr( $field ); ?>"
                        data-two="<?php echo $two_umps ? '1' : '0'; ?>"
                        data-dh="<?php echo $is_dh ? '1' : '0'; ?>">
                    Edit
                </button>
                <button type="button"
                        class="us-alloc-game-postpone-btn us-alloc__action-btn us-alloc__action-btn--postpone"
                        data-game="<?php echo $game->ID; ?>"
                        data-label="<?php echo esc_attr( $away . ' at ' . $home ); ?>">
                    Postpone
                </button>
                <button type="button"
                        class="us-alloc-game-cancel-btn us-alloc__action-btn us-alloc__action-btn--cancel"
                        data-game="<?php echo $game->ID; ?>"
                        data-label="<?php echo esc_attr( $away . ' at ' . $home ); ?>">
                    Cancel
                </button>
                <?php endif; ?>
                <button type="button"
                        class="us-alloc-game-delete-btn us-alloc__action-btn us-alloc-games__action-btn--delete"
                        data-game="<?php echo $game->ID; ?>"
                        data-label="<?php echo esc_attr( $away . ' at ' . $home ); ?>">
                    Delete
                </button>
            </div>

        </div><!-- /.us-mgmt-card__header -->

        <!-- Umpire slots row -->
        <div class="us-mgmt-card__umpires">
            <div class="us-mgmt-card__slot">
                <span class="us-mgmt-card__slot-label">Plate</span>
                <?php echo us_allocator_games_umpire_cell( $game->ID, 'plate', $plate_name, $plate, $plate_reqs, $avail, $busy, $pending_uids, false, $is_locked, $plate_conflict, $unavail_umps ); ?>
            </div>
            <?php if ( $two_umps ) : ?>
            <div class="us-mgmt-card__slot">
                <span class="us-mgmt-card__slot-label">Base</span>
                <?php echo us_allocator_games_umpire_cell( $game->ID, 'base', $base_name, $base, $base_reqs, $avail, $busy, $pending_uids, false, $is_locked, $base_conflict, $unavail_umps ); ?>
            </div>
            <?php endif; ?>
        </div>

    </div>
    <?php
    return ob_get_clean();
}

// ── Shortcode ─────────────────────────────────────────────────
add_shortcode( 'allocator_games', 'us_shortcode_allocator_games' );
function us_shortcode_allocator_games() {
    if ( ! is_user_logged_in() ) return us_login_form();

    if ( ! us_is_allocator() ) {
        return '<script>window.location="' . esc_url( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) ) . '";</script>';
    }

    $today    = current_time( 'Y-m-d' );
    $base_url = home_url( '/' . us_setting( 'slug_allocator_games' ) . '/' );

    $current_month  = current_time( 'Y-m' );
    $selected_month = isset( $_GET['us_month'] ) ? sanitize_text_field( $_GET['us_month'] ) : $current_month;
    if ( ! preg_match( '/^\d{4}-\d{2}$/', $selected_month ) ) $selected_month = $current_month;

    $month_start = $selected_month . '-01';
    $month_end   = date( 'Y-m-t', strtotime( $month_start ) );
    $prev_month  = date( 'Y-m', strtotime( $month_start . ' -1 month' ) );
    $next_month  = date( 'Y-m', strtotime( $month_start . ' +1 month' ) );
    $month_label = date( 'F Y', strtotime( $month_start ) );

    // ── Non-tournament leagues only ───────────────────────────
    $all_leagues = us_get_active_leagues( false );

    $selected_league_id = isset( $_GET['league_id'] ) ? absint( $_GET['league_id'] ) : 0;

    $range_start = ( $selected_month === $current_month && $today > $month_start ) ? $today : $month_start;

    $game_meta_query = [
        [ 'key' => 'us_game_date', 'value' => $range_start, 'compare' => '>=' ],
        [ 'key' => 'us_game_date', 'value' => $month_end,   'compare' => '<=' ],
    ];
    if ( $selected_league_id > 0 ) {
        $game_meta_query[] = [ 'key' => 'us_league_id', 'value' => $selected_league_id, 'compare' => '=' ];
    }

    $all_games = get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_key'    => 'us_game_date',
        'orderby'     => 'meta_value',
        'order'       => 'ASC',
        'meta_query'  => $game_meta_query,
    ] );

    // Exclude tournament games
    $games = array_filter( $all_games, function( $game ) {
        $lid = get_post_meta( $game->ID, 'us_league_id', true );
        return ! $lid || get_post_meta( $lid, 'us_is_tournament', true ) !== '1';
    } );
    $games = array_values( $games );

    // ── Build a set of league IDs that have games in this range ──
    $leagues_with_games = [];
    foreach ( $games as $g ) {
        $lid = get_post_meta( $g->ID, 'us_league_id', true );
        if ( $lid ) $leagues_with_games[ $lid ] = true;
    }
    // Always include the currently selected league so its tab stays visible
    if ( $selected_league_id ) $leagues_with_games[ $selected_league_id ] = true;

    $all_umpires = us_get_active_umpires();

    // Build open/partial game list for PDF export
    $export_games = [];
    foreach ( $games as $game ) {
        if ( us_is_game_postponed( $game->ID ) || us_is_game_cancelled( $game->ID ) ) continue;
        $plate    = us_get_confirmed_assignment( $game->ID, 'plate' );
        $two_umps = get_post_meta( $game->ID, 'us_two_umpires', true ) === '1';
        $base     = $two_umps ? us_get_confirmed_assignment( $game->ID, 'base' ) : null;
        if ( $plate && ( ! $two_umps || $base ) ) continue; // fully confirmed — skip
        $needed = [];
        if ( ! $plate )             $needed[] = 'Plate';
        if ( $two_umps && ! $base ) $needed[] = 'Base';
        $export_games[] = [
            'date'   => get_post_meta( $game->ID, 'us_game_date', true ),
            'time'   => get_post_meta( $game->ID, 'us_game_time', true ),
            'home'   => get_post_meta( $game->ID, 'us_home_team', true ),
            'away'   => get_post_meta( $game->ID, 'us_away_team', true ),
            'field'  => get_post_meta( $game->ID, 'us_field',     true ),
            'status' => ( $plate || ( $two_umps && $base ) ) ? 'Partial' : 'Open',
            'needed' => implode( ' + ', $needed ),
        ];
    }

    $org_name    = us_setting( 'org_name' ) ?: us_setting( 'org_short' );
    $export_league_name = $selected_league_id
        ? get_the_title( $selected_league_id )
        : 'All Leagues';

    ob_start();
    ?>
    <div class="us-dashboard">

        <div class="us-alloc-dashboard__header">
            <div>
                <h2>Game Management</h2>
                <p class="us-alloc-dashboard__date">Add, edit, remove and assign umpires to games.</p>
            </div>
            <div class="us-alloc-games__header-actions">
                <button id="us-add-game-btn" class="us-btn us-alloc-games__add-btn">+ Add New Game</button>
                <?php if ( ! empty( $export_games ) ) : ?>
                <button id="us-oge-export-btn" class="us-btn us-btn--muted us-btn--sm">&#8659; Export open games (<?php echo count( $export_games ); ?>)</button>
                <?php endif; ?>
                <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_allocator_past_games' ) . '/' ) ); ?>"
                   class="us-btn us-btn--muted us-btn--sm">&#x23F4; Past Games</a>
                <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_allocator_dashboard' ) . '/' ) ); ?>"
                   class="us-btn us-btn-request us-btn--sm">&larr; Dashboard</a>
            </div>
        </div>

        <div class="us-alloc-games__tabs">
            <a href="<?php echo esc_url( add_query_arg( [ 'league_id' => '0', 'us_month' => $selected_month ], $base_url ) ); ?>"
               class="us-alloc-games__tab<?php echo $selected_league_id === 0 ? ' us-alloc-games__tab--active' : ''; ?>">All leagues</a>
            <?php foreach ( $all_leagues as $l ) :
                if ( ! isset( $leagues_with_games[ $l->ID ] ) ) continue;
            ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'league_id' => $l->ID, 'us_month' => $selected_month ], $base_url ) ); ?>"
               class="us-alloc-games__tab<?php echo $l->ID === $selected_league_id ? ' us-alloc-games__tab--active' : ''; ?>">
                <?php echo esc_html( $l->post_title ); ?>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="us-alloc-games__month-nav">
            <a href="<?php echo esc_url( add_query_arg( [ 'league_id' => $selected_league_id, 'us_month' => $prev_month ], $base_url ) ); ?>" class="us-alloc-games__month-btn"><?php echo esc_html( date( 'M Y', strtotime( $prev_month . '-01' ) ) ); ?></a>
            <span class="us-alloc-games__month-label"><?php echo esc_html( $month_label ); ?></span>
            <a href="<?php echo esc_url( add_query_arg( [ 'league_id' => $selected_league_id, 'us_month' => $next_month ], $base_url ) ); ?>" class="us-alloc-games__month-btn"><?php echo esc_html( date( 'M Y', strtotime( $next_month . '-01' ) ) ); ?></a>
        </div>

        <div class="us-alloc-games__meta-row">
            <span class="us-alloc-games__count">
                <?php echo count( $games ); ?> game<?php echo count( $games ) !== 1 ? 's' : ''; ?>
                <?php echo $selected_month === $current_month ? 'remaining in' : 'in'; ?>
                <?php echo esc_html( $month_label ); ?>
            </span>
        </div>

        <?php if ( empty( $games ) ) : ?>
            <p class="us-empty">No upcoming games found for <?php echo esc_html( $month_label ); ?>. Click "Add New Game" to get started.</p>
        <?php else : ?>

        <?php $grouped_games = us_group_doubleheaders( $games ); ?>
        <div class="us-mgmt-cards">
            <?php foreach ( $grouped_games as $entry ) :
                if ( $entry['type'] === 'doubleheader' ) {
                    echo us_render_dh_mgmt_card( $entry['games'], $today, $selected_league_id, $all_umpires );
                } else {
                    echo us_render_mgmt_game_card( $entry['game'], $today, $selected_league_id, $all_umpires );
                }
            endforeach; ?>
        </div>

        <p class="us-table-count">
            <?php echo count( $games ); ?> game<?php echo count( $games ) !== 1 ? 's' : ''; ?>
            <?php echo $selected_month === $current_month ? 'remaining in' : 'in'; ?>
            <?php echo esc_html( $month_label ); ?>
        </p>

        <?php endif; ?>
    </div>

    <?php echo us_mgmt_game_modals( $all_leagues ); ?>
    <?php echo us_mgmt_game_js(); ?>

    <?php if ( ! empty( $export_games ) ) : ?>
    <!-- Hidden PDF export document -->
    <div id="us-oge-print" style="display:none;">
        <div style="font-family:Arial,sans-serif;color:#333;font-size:12px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;padding-bottom:14px;border-bottom:3px solid #1a3a5c;">
                <div>
                    <div style="font-size:18px;font-weight:700;color:#1a3a5c;"><?php echo esc_html( $org_name ); ?></div>
                    <div style="font-size:12px;color:#666;margin-top:4px;">Open Games &mdash; <?php echo esc_html( $export_league_name ); ?> &mdash; <?php echo esc_html( $month_label ); ?></div>
                </div>
                <div style="text-align:right;font-size:11px;color:#888;line-height:1.8;">
                    <div>Generated: <?php echo date( 'F j, Y' ); ?></div>
                    <div><?php echo count( $export_games ); ?> game<?php echo count( $export_games ) !== 1 ? 's' : ''; ?> needing umpires</div>
                </div>
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:11px;">
                <thead>
                    <tr style="background:#f0f4f8;">
                        <th style="padding:7px 9px;text-align:left;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#555;border-bottom:2px solid #dde3ea;white-space:nowrap;">Date</th>
                        <th style="padding:7px 9px;text-align:left;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#555;border-bottom:2px solid #dde3ea;">Day</th>
                        <th style="padding:7px 9px;text-align:left;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#555;border-bottom:2px solid #dde3ea;white-space:nowrap;">Time</th>
                        <th style="padding:7px 9px;text-align:left;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#555;border-bottom:2px solid #dde3ea;">Home</th>
                        <th style="padding:7px 9px;text-align:left;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#555;border-bottom:2px solid #dde3ea;">Away</th>
                        <th style="padding:7px 9px;text-align:left;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#555;border-bottom:2px solid #dde3ea;">Field</th>
                        <th style="padding:7px 9px;text-align:left;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#555;border-bottom:2px solid #dde3ea;">Needed</th>
                        <th style="padding:7px 9px;text-align:left;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#555;border-bottom:2px solid #dde3ea;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $export_games as $eg ) :
                        $status_bg    = $eg['status'] === 'Open' ? '#fde8e8' : '#fff4e0';
                        $status_color = $eg['status'] === 'Open' ? '#b32d2e'  : '#7a5200';
                    ?>
                    <tr style="border-bottom:1px solid #f0f0f0;">
                        <td style="padding:8px 9px;white-space:nowrap;"><?php echo esc_html( date( 'M j, Y', strtotime( $eg['date'] ) ) ); ?></td>
                        <td style="padding:8px 9px;"><?php echo esc_html( date( 'D', strtotime( $eg['date'] ) ) ); ?></td>
                        <td style="padding:8px 9px;white-space:nowrap;"><?php echo $eg['time'] ? esc_html( date( 'g:i a', strtotime( $eg['time'] ) ) ) : '—'; ?></td>
                        <td style="padding:8px 9px;"><?php echo esc_html( $eg['home'] ); ?></td>
                        <td style="padding:8px 9px;"><?php echo esc_html( $eg['away'] ); ?></td>
                        <td style="padding:8px 9px;"><?php echo esc_html( $eg['field'] ?: '—' ); ?></td>
                        <td style="padding:8px 9px;"><span style="display:inline-block;padding:2px 6px;background:#f0f4f8;border-radius:3px;font-size:10px;font-weight:600;"><?php echo esc_html( $eg['needed'] ); ?></span></td>
                        <td style="padding:8px 9px;"><span style="display:inline-block;padding:2px 6px;background:<?php echo $status_bg; ?>;color:<?php echo $status_color; ?>;border-radius:3px;font-size:10px;font-weight:600;"><?php echo esc_html( $eg['status'] ); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
    document.getElementById('us-oge-export-btn').addEventListener('click', function() {
        var btn = this;
        btn.disabled    = true;
        btn.textContent = 'Generating…';
        var el = document.getElementById('us-oge-print');
        el.style.display = '';
        html2pdf().set({
            margin:      [10, 10, 10, 10],
            filename:    'open-games-<?php echo esc_js( sanitize_title( $export_league_name ) ); ?>-<?php echo esc_js( $selected_month ); ?>.pdf',
            image:       { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true },
            jsPDF:       { unit: 'mm', format: 'letter', orientation: 'landscape' },
        }).from(el).save().then(function() {
            el.style.display = 'none';
            btn.disabled    = false;
            btn.textContent = '⬇ Export open games (<?php echo count( $export_games ); ?>)';
        });
    });
    </script>
    <?php endif; ?>

    <?php
    return ob_get_clean();
}

// ── Shared modals ─────────────────────────────────────────────
function us_mgmt_game_modals( $all_leagues ) {
    ob_start(); ?>
    <div id="us-game-modal" class="us-modal">
        <div class="us-modal__inner us-game-modal__inner">
            <h3 id="us-game-modal-title" class="us-modal__title"></h3>
            <input type="hidden" id="us-game-modal-id" value="0">
            <div class="us-game-modal__grid">
                <div class="us-game-modal__field us-game-modal__field--full">
                    <label class="us-game-modal__label">League <span class="us-game-modal__required">*</span></label>
                    <select id="us-game-league" class="us-game-modal__input">
                        <?php foreach ( $all_leagues as $l ) : ?>
                            <option value="<?php echo $l->ID; ?>"><?php echo esc_html( $l->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="us-game-modal__field">
                    <label class="us-game-modal__label">Away team <span class="us-game-modal__required">*</span></label>
                    <input type="text" id="us-game-away" class="us-game-modal__input" placeholder="e.g. Regulators">
                </div>
                <div class="us-game-modal__field">
                    <label class="us-game-modal__label">Home team <span class="us-game-modal__required">*</span></label>
                    <input type="text" id="us-game-home" class="us-game-modal__input" placeholder="e.g. Goldstream Dirtbags">
                </div>
                <div class="us-game-modal__field">
                    <label class="us-game-modal__label">Date <span class="us-game-modal__required">*</span></label>
                    <input type="date" id="us-game-date" class="us-game-modal__input">
                </div>
                <div class="us-game-modal__field">
                    <label class="us-game-modal__label">Time</label>
                    <input type="time" id="us-game-time" class="us-game-modal__input">
                </div>
                <div class="us-game-modal__field us-game-modal__field--full">
                    <label class="us-game-modal__label">Field</label>
                    <input type="text" id="us-game-field" class="us-game-modal__input" placeholder="e.g. Goudy Field">
                </div>
                <div class="us-game-modal__field">
                    <label class="us-admin-meta__checkbox-label">
                        <input type="checkbox" id="us-game-two-umps" class="us-admin-meta__checkbox"> Two umpires
                    </label>
                </div>
                <div class="us-game-modal__field">
                    <label class="us-admin-meta__checkbox-label">
                        <input type="checkbox" id="us-game-dh" class="us-admin-meta__checkbox"> Optional pay rate
                    </label>
                </div>
            </div>
            <p id="us-game-modal-error" class="us-game-modal__error" hidden></p>
            <div class="us-modal__actions">
                <button id="us-game-modal-save" class="us-btn us-btn-confirm">Save game</button>
                <button id="us-game-modal-cancel" class="us-btn us-btn--muted">Cancel</button>
            </div>
        </div>
    </div>

    <div id="us-games-postpone-modal" class="us-modal">
        <div class="us-modal__inner">
            <h3 class="us-modal__title">Mark game as postponed</h3>
            <p id="us-games-postpone-label" class="us-modal__subtitle"></p>
            <label class="us-alloc-postpone__pay-label">
                <input type="checkbox" id="us-games-postpone-pay" class="us-alloc-postpone__pay-check">
                <span>Pay assigned umpires — they arrived on site before the game was called off</span>
            </label>
            <p class="us-modal__hint">Umpires will be notified by email. Create a new game if rescheduling.</p>
            <div class="us-modal__actions">
                <button id="us-games-postpone-confirm" class="us-btn us-alloc__postpone-confirm-btn">Confirm postponement</button>
                <button id="us-games-postpone-cancel" class="us-btn us-btn--muted">Cancel</button>
            </div>
        </div>
    </div>

    <div id="us-games-cancel-modal" class="us-modal">
        <div class="us-modal__inner">
            <h3 class="us-modal__title">Cancel game</h3>
            <p id="us-games-cancel-label" class="us-modal__subtitle"></p>
            <p class="us-modal__hint">All assignments will be removed and assigned umpires notified. The game stays in the database marked as cancelled.</p>
            <div class="us-modal__actions">
                <button id="us-games-cancel-confirm" class="us-btn us-alloc__cancel-confirm-btn">Confirm cancellation</button>
                <button id="us-games-cancel-close" class="us-btn us-btn--muted">Go back</button>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ── Shared JS ─────────────────────────────────────────────────
function us_mgmt_game_js( $first_league_id = '' ) {
    ob_start(); ?>
    <?php
    return ob_get_clean();
}

// ── Umpire cell helper ────────────────────────────────────────
function us_allocator_games_umpire_cell( $game_id, $position, $name, $assignment, $requests, $avail, $busy, $pending_uids, $is_past, $is_postponed, $is_conflict = false, $unavail_umps = [] ) {
    ob_start();

    if ( $is_postponed ) {
        echo $name
            ? '<span class="us-status-confirmed">&#10003; ' . esc_html( $name ) . '</span>'
            : '<span class="us-alloc__slot-na">—</span>';
        return ob_get_clean();
    }

    if ( $name ) : ?>
        <div class="us-alloc-games__assigned-cell">
            <span class="us-status-confirmed">&#10003; <?php echo esc_html( $name ); ?></span>
            <?php if ( $is_conflict ) : ?>
                <span class="us-alloc__conflict-badge" title="This umpire is confirmed on another game today">&#9888; Double booked</span>
            <?php endif; ?>
            <button type="button" class="us-games-clear-btn us-alloc__noshow-btn"
                    data-game="<?php echo $game_id; ?>" data-position="<?php echo $position; ?>">Clear</button>
        </div>
    <?php else :
        $uid           = $game_id . '_' . $position;
        $avail_pending = array_filter( $avail, fn($u) => in_array( $u->ID, $pending_uids ) );
        $avail_clean   = array_filter( $avail, fn($u) => ! in_array( $u->ID, $pending_uids ) );
        $busy_pending  = array_filter( $busy,  fn($u) => in_array( $u->ID, $pending_uids ) );
        $busy_clean    = array_filter( $busy,  fn($u) => ! in_array( $u->ID, $pending_uids ) );
    ?>
        <div class="us-alloc-games__assign-cell">
            <div class="us-alloc-assign__row">
                <select class="us-games-assign-select us-alloc-assign__select"
                        data-game="<?php echo $game_id; ?>" data-position="<?php echo $position; ?>" data-uid="<?php echo $uid; ?>">
                    <option value="">— select —</option>
                    <?php if ( ! empty( $avail_pending ) ) : ?>
                        <optgroup label="⭐ Has pending request">
                            <?php foreach ( $avail_pending as $u ) : ?>
                                <option value="<?php echo $u->ID; ?>" class="us-alloc-games__opt--requested"><?php echo esc_html( $u->post_title ); ?> — requested</option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                    <?php if ( ! empty( $avail_clean ) ) : ?>
                        <optgroup label="Available">
                            <?php foreach ( $avail_clean as $u ) : ?>
                                <option value="<?php echo $u->ID; ?>"><?php echo esc_html( $u->post_title ); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                    <?php if ( ! empty( $busy_pending ) ) : ?>
                        <optgroup label="Already assigned — has pending request">
                            <?php foreach ( $busy_pending as $u ) : ?>
                                <option value="<?php echo $u->ID; ?>" class="us-alloc-assign__opt--busy us-alloc-games__opt--requested"><?php echo esc_html( $u->post_title ); ?> — assigned elsewhere</option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                    <?php if ( ! empty( $busy_clean ) ) : ?>
                        <optgroup label="Already assigned elsewhere">
                            <?php foreach ( $busy_clean as $u ) : ?>
                                <option value="<?php echo $u->ID; ?>" class="us-alloc-assign__opt--busy"><?php echo esc_html( $u->post_title ); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                    <?php if ( ! empty( $unavail_umps ) ) : ?>
                        <optgroup label="&#9888; Marked unavailable (admin override)">
                            <?php foreach ( $unavail_umps as $u ) : ?>
                                <option value="<?php echo $u->ID; ?>" class="us-alloc-assign__opt--unavail"><?php echo esc_html( $u->post_title ); ?> — unavailable</option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
                <button type="button" class="us-games-assign-btn us-btn us-btn-confirm us-btn--sm us-btn--disabled"
                        data-uid="<?php echo $uid; ?>" data-game="<?php echo $game_id; ?>" data-position="<?php echo $position; ?>" disabled>
                    Confirm
                </button>
            </div>
            <?php if ( ! empty( $requests ) ) : ?>
                <span class="us-alloc__slot-requested">&#9679; <?php echo count( $requests ); ?> pending request<?php echo count( $requests ) > 1 ? 's' : ''; ?></span>
            <?php endif; ?>
        </div>
    <?php endif;

    return ob_get_clean();
}