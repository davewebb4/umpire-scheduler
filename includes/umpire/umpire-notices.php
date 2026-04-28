<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Dashboard notices ─────────────────────────────────────────
function us_dashboard_notices() {
    if ( isset( $_GET['us_notice'] ) ) {
        $notices = [
            'confirmed'         => [ 'class' => 'success', 'msg' => 'Assignment confirmed. See you on the field!' ],
            'declined'          => [ 'class' => 'error',   'msg' => 'Assignment declined. The assignor has been notified.' ],
            'requested'         => [ 'class' => 'info',    'msg' => 'Request sent! The assignor will confirm shortly.' ],
            'phone_saved'       => [ 'class' => 'success', 'msg' => 'Phone number updated successfully.' ],
            'request_cancelled' => [ 'class' => 'info',    'msg' => 'Your request has been cancelled.' ],
        ];
        $key = sanitize_text_field( $_GET['us_notice'] );
        if ( isset( $notices[ $key ] ) ) {
            echo '<div class="us-notice us-notice-' . $notices[ $key ]['class'] . '">' . $notices[ $key ]['msg'] . '</div>';
        }
    }

    if ( isset( $_GET['us_error'] ) ) {
        $errors = [
            'invalid_nonce'       => 'Security check failed. Please try again.',
            'not_your_assignment' => 'You are not authorized for that assignment.',
            'slot_taken'          => 'Sorry, that slot has already been confirmed.',
            'already_requested'   => 'You have already requested this game.',
        ];
        $key = sanitize_text_field( $_GET['us_error'] );
        if ( isset( $errors[ $key ] ) ) {
            echo '<div class="us-notice us-notice-error">' . $errors[ $key ] . '</div>';
        }
    }
}