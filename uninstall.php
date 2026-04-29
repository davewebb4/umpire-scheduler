<?php
// Only runs when WordPress deletes the plugin — never on deactivation
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

// ── Remove all custom post type data ─────────────────────────
$post_types = [ 'us_league', 'us_game', 'us_umpire', 'us_assignment', 'us_payment' ];

foreach ( $post_types as $type ) {
    $posts = get_posts( [
        'post_type'   => $type,
        'numberposts' => -1,
        'post_status' => 'any',
        'fields'      => 'ids',
    ] );
    foreach ( $posts as $id ) {
        wp_delete_post( $id, true );
    }
}

// ── Remove all plugin options ─────────────────────────────────
delete_option( 'us_settings' );
delete_option( 'us_wizard_complete' );
delete_option( 'us_notify_allocator_on_request' );

// ── Remove all transients ─────────────────────────────────────
delete_transient( 'us_wizard_redirect' );

global $wpdb;

// Remove any remaining us_ transients
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_us_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_us_%'" );

// ── Remove custom user capabilities ──────────────────────────
$users = get_users();
foreach ( $users as $user ) {
    $user->remove_cap( 'us_manage_pay' );
    $user->remove_cap( 'us_view_pay' );
    $user->remove_cap( 'us_broadcast' );
    $user->remove_cap( 'manage_umpire_schedule' );
}

// ── Remove custom role ────────────────────────────────────────
remove_role( 'umpire' );

// ── Flush rewrite rules ───────────────────────────────────────
flush_rewrite_rules();
