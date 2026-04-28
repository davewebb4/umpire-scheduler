<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Handle phone number update ────────────────────────────────
add_action( 'template_redirect', 'us_handle_phone_update' );
function us_handle_phone_update() {
    if ( ! is_user_logged_in() ) return;
    if ( ! isset( $_POST['us_phone_update_submit'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['us_phone_nonce'] ?? '', 'us_phone_update' ) ) return;

    $umpire = us_get_umpire_by_user( get_current_user_id() );
    if ( ! $umpire ) return;

    $phone = sanitize_text_field( $_POST['us_phone'] ?? '' );
    update_post_meta( $umpire->ID, 'us_phone', $phone );

    wp_redirect( home_url( '/' . us_setting( 'slug_dashboard' ) . '/?us_notice=phone_saved' ) );
    exit;
}

// ── Handle umpire front end actions ──────────────────────────
add_action( 'template_redirect', 'us_handle_umpire_actions' );
function us_handle_umpire_actions() {
    if ( ! is_user_logged_in() ) return;
    if ( ! isset( $_GET['us_action'] ) ) return;

    $action = sanitize_text_field( $_GET['us_action'] );
    $umpire = us_get_umpire_by_user( get_current_user_id() );
    if ( ! $umpire ) return;

    $umpire_id = $umpire->ID;

    // ── Confirm or decline an assigned game ───────────────────
    if ( $action === 'confirm' || $action === 'decline' ) {
        $assignment_id = absint( $_GET['assignment'] ?? 0 );
        if ( ! $assignment_id ) return;

        if ( ! wp_verify_nonce( $_GET['nonce'] ?? '', 'us_umpire_action_' . $assignment_id ) ) {
            wp_redirect( home_url( '/' . us_setting( 'slug_dashboard' ) . '/?us_error=invalid_nonce' ) );
            exit;
        }

        $assigned_umpire = absint( get_post_meta( $assignment_id, 'us_umpire_id', true ) );
        if ( $assigned_umpire !== $umpire_id ) {
            wp_redirect( home_url( '/' . us_setting( 'slug_dashboard' ) . '/?us_error=not_your_assignment' ) );
            exit;
        }

        if ( $action === 'confirm' ) {
            update_post_meta( $assignment_id, 'us_status', 'confirmed' );
            us_notify_assignor_confirmed( $assignment_id );
            wp_redirect( home_url( '/' . us_setting( 'slug_schedule' ) . '/?us_notice=confirmed' ) );
            exit;
        }

        if ( $action === 'decline' ) {
            us_notify_assignor_declined( $assignment_id );
            wp_delete_post( $assignment_id, true );
            wp_redirect( home_url( '/' . us_setting( 'slug_schedule' ) . '/?us_notice=declined' ) );
            exit;
        }
    }

}

// ── Handle login ──────────────────────────────────────────────
add_action( 'template_redirect', 'us_handle_login' );
function us_handle_login() {
    if ( is_user_logged_in() ) return;
    if ( ! isset( $_POST['us_login_submit'] ) ) return;

    $creds = [
        'user_login'    => sanitize_text_field( $_POST['us_login_user'] ?? '' ),
        'user_password' => $_POST['us_login_pass'] ?? '',
        'remember'      => true,
    ];

    $user = wp_signon( $creds, false );

    if ( is_wp_error( $user ) ) return;

    // Track last login time
    update_user_meta( $user->ID, 'us_last_login', current_time( 'mysql' ) );

    $umpire_record = us_get_umpire_by_user( $user->ID );
    if ( $umpire_record && get_post_meta( $umpire_record->ID, 'us_is_assignor', true ) === '1' ) {
        wp_redirect( home_url( '/' . us_setting( 'slug_allocator_dashboard' ) . '/' ) );
    } else {
        wp_redirect( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) );
    }
    exit;
}