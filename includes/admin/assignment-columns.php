<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Assignment list columns ───────────────────────────────────
add_filter( 'manage_us_assignment_posts_columns', 'us_assignment_columns' );
function us_assignment_columns( $columns ) {
    return [
        'cb'          => $columns['cb'],
        'title'       => 'Assignment',
        'us_game'     => 'Game',
        'us_date'     => 'Date',
        'us_umpire'   => 'Umpire',
        'us_position' => 'Position',
        'us_status'   => 'Status',
        'us_pay'      => 'Pay',
    ];
}

// ── Assignment column data ────────────────────────────────────
add_action( 'manage_us_assignment_posts_custom_column', 'us_assignment_column_data', 10, 2 );
function us_assignment_column_data( $column, $post_id ) {
    switch ( $column ) {

        case 'us_game':
            $game_id = get_post_meta( $post_id, 'us_game_id', true );
            if ( $game_id ) {
                $home = get_post_meta( $game_id, 'us_home_team', true );
                $away = get_post_meta( $game_id, 'us_away_team', true );
                echo esc_html( $away . ' at ' . $home );
            } else {
                echo '—';
            }
            break;

        case 'us_date':
            $game_id = get_post_meta( $post_id, 'us_game_id', true );
            $date    = $game_id ? get_post_meta( $game_id, 'us_game_date', true ) : '';
            echo $date ? esc_html( date( 'M j, Y', strtotime( $date ) ) ) : '—';
            break;

        case 'us_umpire':
            $umpire_id = get_post_meta( $post_id, 'us_umpire_id', true );
            echo $umpire_id ? esc_html( get_the_title( $umpire_id ) ) : '—';
            break;

        case 'us_position':
            $position = get_post_meta( $post_id, 'us_position', true );
            echo $position ? esc_html( ucfirst( $position ) ) : '—';
            break;

        case 'us_status':
            $status = get_post_meta( $post_id, 'us_status', true );
            if ( ! $status ) { echo '—'; break; }
            $class_map = [
                'confirmed' => 'us-admin-status--confirmed',
                'pending'   => 'us-admin-status--pending',
                'requested' => 'us-admin-status--requested',
                'declined'  => 'us-admin-status--open',
                'no-show'   => 'us-admin-status--open',
            ];
            $class = $class_map[ $status ] ?? '';
            echo '<span class="us-admin-status ' . $class . '">' . esc_html( ucfirst( $status ) ) . '</span>';
            break;

        case 'us_pay':
            $pay = get_post_meta( $post_id, 'us_pay_amount', true );
            echo $pay !== '' ? '$' . number_format( floatval( $pay ), 2 ) : '—';
            break;
    }
}