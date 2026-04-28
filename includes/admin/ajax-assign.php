<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Assign umpire to game (admin direct assign) ───────────────
add_action( 'wp_ajax_us_assign_umpire', 'us_ajax_assign_umpire' );
function us_ajax_assign_umpire() {
    check_ajax_referer( 'us_assign_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $game_id   = absint( $_POST['game_id']   ?? 0 );
    $umpire_id = absint( $_POST['umpire_id'] ?? 0 );
    $position  = sanitize_text_field( $_POST['position'] ?? '' );

    if ( ! $game_id || ! in_array( $position, [ 'plate', 'base' ] ) ) {
        wp_send_json_error( 'Invalid data' );
    }

    if ( $position === 'base' && ! get_post_meta( $game_id, 'us_two_umpires', true ) ) {
        wp_send_json_error( 'Base umpire slot not enabled for this game' );
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

    if ( ! $umpire_id ) {
        if ( $existing ) wp_delete_post( $existing[0]->ID, true );
        wp_send_json_success( [ 'status' => 'cleared' ] );
    }

    $game_title = get_the_title( $game_id );
    $pay_rate   = us_get_game_pay_rate( $game_id );

    if ( $existing ) {
        $assignment_id = $existing[0]->ID;
        update_post_meta( $assignment_id, 'us_umpire_id',  $umpire_id );
        update_post_meta( $assignment_id, 'us_status',     'confirmed' );
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

    // Deny and delete any pending requests for this slot from other umpires
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

    wp_send_json_success( [
        'status'        => 'confirmed',
        'label'         => get_the_title( $umpire_id ),
        'assignment_id' => $assignment_id,
    ] );
}

// ── Admin confirms one request, auto-denies the rest ─────────
add_action( 'wp_ajax_us_confirm_assignment', 'us_ajax_confirm_assignment' );
function us_ajax_confirm_assignment() {
    check_ajax_referer( 'us_assign_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $assignment_id = absint( $_POST['assignment_id'] ?? 0 );
    if ( ! $assignment_id ) wp_send_json_error( 'Invalid assignment' );

    $game_id   = get_post_meta( $assignment_id, 'us_game_id',   true );
    $position  = get_post_meta( $assignment_id, 'us_position',  true );
    $umpire_id = get_post_meta( $assignment_id, 'us_umpire_id', true );

    // Confirm the target first — outside the siblings query so a race
    // condition (e.g. status already changed) can never cause a silent no-op
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

    wp_send_json_success( [
        'status' => 'confirmed',
        'label'  => get_the_title( $umpire_id ),
    ] );
}

// ── Mark no-show ──────────────────────────────────────────────
add_action( 'wp_ajax_us_mark_noshow', 'us_ajax_mark_noshow' );
function us_ajax_mark_noshow() {
    check_ajax_referer( 'us_assign_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) && ! us_is_allocator() ) wp_send_json_error( 'Unauthorized' );

    $assignment_id = absint( $_POST['assignment_id'] ?? 0 );
    if ( ! $assignment_id ) wp_send_json_error( 'Invalid assignment' );

    update_post_meta( $assignment_id, 'us_status',     'no-show' );
    update_post_meta( $assignment_id, 'us_pay_amount', '0' );

    us_notify_umpire_noshow( $assignment_id );

    wp_send_json_success( [ 'status' => 'no-show' ] );
}

// ── Mark game as postponed ────────────────────────────────────
add_action( 'wp_ajax_us_postpone_game', 'us_ajax_postpone_game' );
function us_ajax_postpone_game() {
    check_ajax_referer( 'us_assign_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) && ! us_is_allocator() ) wp_send_json_error( 'Unauthorized' );

    $game_id  = absint( $_POST['game_id'] ?? 0 );
    $pay_umps = isset( $_POST['pay_umpires'] ) && $_POST['pay_umpires'] === '1';

    if ( ! $game_id ) wp_send_json_error( 'Invalid game' );

    update_post_meta( $game_id, 'us_game_status', 'postponed' );

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
        if ( $pay_umps ) {
            update_post_meta( $a->ID, 'us_status', 'postponed-paid' );
            us_notify_umpire_postponed_paid( $a->ID );
        } else {
            us_notify_umpire_postponed_unpaid( $a->ID );
            wp_delete_post( $a->ID, true );
        }
        $notified++;
    }

    wp_send_json_success( [
        'game_id'  => $game_id,
        'notified' => $notified,
        'paid'     => $pay_umps,
        'msg'      => 'Game marked as postponed. ' . $notified . ' umpire(s) notified.',
    ] );
}

// ── Delete game ───────────────────────────────────────────────
add_action( 'wp_ajax_us_delete_game', 'us_ajax_delete_game' );
function us_ajax_delete_game() {
    check_ajax_referer( 'us_assign_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $game_id = absint( $_POST['game_id'] ?? 0 );
    if ( ! $game_id ) wp_send_json_error( 'Invalid game' );

    $assignments = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_game_id', 'value' => $game_id, 'compare' => '=' ],
        ],
    ] );

    $force = isset( $_POST['force'] ) && $_POST['force'] === '1';

    if ( $assignments && ! $force ) {
        wp_send_json_error( [
            'code'  => 'has_assignments',
            'count' => count( $assignments ),
            'msg'   => count( $assignments ) . ' assignment(s) exist for this game. Delete anyway?',
        ] );
    }

    foreach ( $assignments as $a ) {
        wp_delete_post( $a->ID, true );
    }

    wp_delete_post( $game_id, true );
    wp_send_json_success( [ 'game_id' => $game_id ] );
}

// ── Update game details ───────────────────────────────────────
add_action( 'wp_ajax_us_update_game', 'us_ajax_update_game' );
function us_ajax_update_game() {
    check_ajax_referer( 'us_assign_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $game_id   = absint( $_POST['game_id']    ?? 0 );
    $new_date  = sanitize_text_field( $_POST['game_date']  ?? '' );
    $new_time  = sanitize_text_field( $_POST['game_time']  ?? '' );
    $new_field = sanitize_text_field( $_POST['game_field'] ?? '' );
    $new_dh    = ( isset( $_POST['game_dh'] ) && $_POST['game_dh'] === '1' ) ? '1' : '0';

    if ( ! $game_id ) wp_send_json_error( 'Invalid game' );

    $old_date  = get_post_meta( $game_id, 'us_game_date',     true );
    $old_time  = get_post_meta( $game_id, 'us_game_time',     true );
    $old_field = get_post_meta( $game_id, 'us_field',         true );
    $old_dh    = get_post_meta( $game_id, 'us_double_header', true ) ?: '0';

    $changes = [];
    if ( $new_date  !== $old_date  ) $changes['date']  = [ 'old' => $old_date,  'new' => $new_date  ];
    if ( $new_time  !== $old_time  ) $changes['time']  = [ 'old' => $old_time,  'new' => $new_time  ];
    if ( $new_field !== $old_field ) $changes['field'] = [ 'old' => $old_field, 'new' => $new_field ];
    if ( $new_dh    !== $old_dh    ) $changes['dh']    = [ 'old' => $old_dh,    'new' => $new_dh    ];

    if ( empty( $changes ) ) {
        wp_send_json_success( [ 'changed' => false, 'msg' => 'No changes detected.' ] );
    }

    update_post_meta( $game_id, 'us_game_date',     $new_date );
    update_post_meta( $game_id, 'us_game_time',     $new_time );
    update_post_meta( $game_id, 'us_field',         $new_field );
    update_post_meta( $game_id, 'us_double_header', $new_dh );

    $pay_restamped = 0;
    if ( isset( $changes['dh'] ) && $new_date >= current_time( 'Y-m-d' ) ) {
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
            $pay_restamped++;
        }
    }

    $notify_changes = array_intersect_key( $changes, array_flip( [ 'date', 'time', 'field' ] ) );
    $notified       = ! empty( $notify_changes ) ? us_notify_game_changed( $game_id, $notify_changes ) : 0;

    $msg = 'Game updated.';
    if ( $notified > 0 )      $msg .= ' ' . $notified . ' umpire(s) notified.';
    if ( $pay_restamped > 0 ) $msg .= ' ' . $pay_restamped . ' assignment(s) re-stamped to ' . ( $new_dh === '1' ? 'double header' : 'standard' ) . ' rate.';

    wp_send_json_success( [
        'changed'  => true,
        'notified' => $notified,
        'date'     => $new_date,
        'time'     => $new_time,
        'field'    => $new_field,
        'dh'       => $new_dh,
        'msg'      => $msg,
    ] );
}

// ── Umpire cancels their own pending request ──────────────────
add_action( 'wp_ajax_us_cancel_request', 'us_ajax_cancel_request' );
function us_ajax_cancel_request() {
    check_ajax_referer( 'us_front_nonce', 'nonce' );

    $user_id = get_current_user_id();
    if ( ! $user_id ) wp_send_json_error( 'Not logged in' );

    $umpire = us_get_umpire_by_user( $user_id );
    if ( ! $umpire ) wp_send_json_error( 'Umpire record not found' );

    $assignment_id   = absint( $_POST['assignment_id'] ?? 0 );
    if ( ! $assignment_id ) wp_send_json_error( 'Invalid assignment' );

    $assigned_umpire = absint( get_post_meta( $assignment_id, 'us_umpire_id', true ) );
    $status          = get_post_meta( $assignment_id, 'us_status', true );

    if ( $assigned_umpire !== $umpire->ID ) wp_send_json_error( 'Not your assignment' );
    if ( ! in_array( $status, [ 'requested', 'pending' ] ) ) wp_send_json_error( 'This request cannot be cancelled' );

    $game_id  = get_post_meta( $assignment_id, 'us_game_id',  true );
    $position = get_post_meta( $assignment_id, 'us_position', true );

    wp_delete_post( $assignment_id, true );

    if ( get_option( 'us_notify_allocator_on_request', 0 ) ) {
        $assignor_email = us_get_assignor_email();
        if ( $assignor_email ) {
            $umpire_name = get_the_title( $umpire->ID );
            $home        = get_post_meta( $game_id, 'us_home_team', true );
            $away        = get_post_meta( $game_id, 'us_away_team', true );
            $date        = get_post_meta( $game_id, 'us_game_date', true );
            $date_fmt    = $date ? date( 'l, F j, Y', strtotime( $date ) ) : '';

            $message  = "{$umpire_name} has cancelled their request for the " . ucfirst( $position ) . " position.\n\n";
            $message .= "Game:  {$away} at {$home}\n";
            $message .= "Date:  {$date_fmt}\n\n";
            $message .= "The slot is now open again.\n\n";
            $message .= us_setting( 'email_footer' );

            wp_mail( $assignor_email, $umpire_name . ' cancelled request — ' . $date_fmt, $message );
        }
    }

    wp_send_json_success( [
        'assignment_id' => $assignment_id,
        'msg'           => 'Your request has been cancelled.',
    ] );
}