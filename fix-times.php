<?php
require_once( dirname( __FILE__, 4 ) . '/wp-load.php' );

$league_id = 253;

$games = get_posts( [
    'post_type'   => 'us_game',
    'numberposts' => -1,
    'post_status' => 'publish',
    'meta_query'  => [
        [ 'key' => 'us_league_id', 'value' => $league_id, 'compare' => '=' ],
    ],
] );

$fixed = 0;
$skipped = 0;

foreach ( $games as $game ) {
    $time = get_post_meta( $game->ID, 'us_game_time', true );
    if ( ! $time ) { $skipped++; continue; }

    $hour = (int) date( 'G', strtotime( $time ) );

    // Only flip times before noon
    if ( $hour < 12 ) {
        $new_time = date( 'H:i:s', strtotime( $time ) + ( 12 * 3600 ) );
        update_post_meta( $game->ID, 'us_game_time', $new_time );
        echo 'Fixed game ' . $game->ID . ': ' . $time . ' → ' . $new_time . '<br>';
        $fixed++;
    } else {
        echo 'Skipped game ' . $game->ID . ': ' . $time . ' (already PM)<br>';
        $skipped++;
    }
}

echo '<br><strong>' . $fixed . ' fixed, ' . $skipped . ' skipped.</strong>';