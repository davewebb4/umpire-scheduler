<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Role definitions ──────────────────────────────────────────
define( 'US_ROLE_ASSIGNOR', 'us_assignor' );
define( 'US_ROLE_UMPIRE',   'us_umpire' );

// ── Capabilities ──────────────────────────────────────────────
define( 'US_CAP_MANAGE_SCHEDULE', 'manage_umpire_schedule' );
define( 'US_CAP_MANAGE_PAY',      'us_manage_pay' );
define( 'US_CAP_VIEW_PAY',        'us_view_pay' );
define( 'US_CAP_BROADCAST',       'us_broadcast' );
define( 'US_CAP_VIEW_SCHEDULE',   'view_umpire_schedule' );

// ── Register roles on activation ──────────────────────────────
function us_register_roles() {

    // Assignor — full scheduling access including pay reports
    add_role( US_ROLE_ASSIGNOR, 'Assignor', [
        'read'                      => true,
        'edit_posts'                => false,
        US_CAP_MANAGE_SCHEDULE      => true,
        US_CAP_MANAGE_PAY           => true,
        US_CAP_VIEW_PAY             => true,
        US_CAP_BROADCAST            => true,
    ] );

    // Umpire — front-end access only
    add_role( US_ROLE_UMPIRE, 'Umpire', [
        'read'                      => true,
        'edit_posts'                => false,
        US_CAP_VIEW_SCHEDULE        => true,
    ] );
}

// ── Sync caps on every load ───────────────────────────────────
// Roles are created once by add_role() so we update caps on
// every load to catch any changes made after initial install
add_action( 'init', 'us_sync_role_caps' );
function us_sync_role_caps() {
    $assignor = get_role( US_ROLE_ASSIGNOR );
    if ( $assignor ) {
        $assignor->add_cap( US_CAP_MANAGE_SCHEDULE );
        $assignor->add_cap( US_CAP_MANAGE_PAY );
        $assignor->add_cap( US_CAP_VIEW_PAY );
        $assignor->add_cap( US_CAP_BROADCAST );
    }
}

// ── Helpers ───────────────────────────────────────────────────
function us_is_assignor() {
    return current_user_can( US_CAP_MANAGE_SCHEDULE );
}

function us_is_umpire() {
    return current_user_can( US_CAP_VIEW_SCHEDULE );
}