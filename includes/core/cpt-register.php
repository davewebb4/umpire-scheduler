<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Post type constants ───────────────────────────────────────
define( 'US_PT_LEAGUE',     'us_league' );
define( 'US_PT_GAME',       'us_game' );
define( 'US_PT_UMPIRE',     'us_umpire' );
define( 'US_PT_ASSIGNMENT', 'us_assignment' );
define( 'US_PT_PAYMENT',    'us_payment' );

// ── Register custom post types ────────────────────────────────
add_action( 'init', 'us_register_cpts' );
function us_register_cpts() {

    register_post_type( US_PT_LEAGUE, [
        'labels'       => [
            'name'          => 'Leagues',
            'singular_name' => 'League',
            'add_new_item'  => 'Add New League',
            'edit_item'     => 'Edit League',
        ],
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => false,
        'supports'     => [ 'title' ],
    ] );

    register_post_type( US_PT_GAME, [
        'labels'       => [
            'name'          => 'Games',
            'singular_name' => 'Game',
            'add_new_item'  => 'Add New Game',
            'edit_item'     => 'Edit Game',
        ],
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => false,
        'supports'     => [ 'title' ],
    ] );

    register_post_type( US_PT_UMPIRE, [
        'labels'       => [
            'name'          => 'Umpires',
            'singular_name' => 'Umpire',
            'add_new_item'  => 'Add New Umpire',
            'edit_item'     => 'Edit Umpire',
        ],
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => false,
        'supports'     => [ 'title' ],
    ] );

    register_post_type( US_PT_ASSIGNMENT, [
        'labels'       => [
            'name'          => 'Assignments',
            'singular_name' => 'Assignment',
            'add_new_item'  => 'Add New Assignment',
            'edit_item'     => 'Edit Assignment',
        ],
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => false,
        'supports'     => [ 'title' ],
    ] );

    register_post_type( US_PT_PAYMENT, [
        'labels'       => [
            'name'          => 'Payments',
            'singular_name' => 'Payment',
            'add_new_item'  => 'Add New Payment',
            'edit_item'     => 'Edit Payment',
        ],
        'public'       => false,
        'show_ui'      => false,
        'show_in_menu' => false,
        'supports'     => [ 'title' ],
    ] );
}