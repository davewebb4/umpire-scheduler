<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── iCal export handler ───────────────────────────────────────
add_action( 'init', 'us_handle_ical_export' );
function us_handle_ical_export() {
    if ( ! isset( $_GET['us_ical_export'] ) ) return;
    if ( ! is_user_logged_in() ) wp_die( 'Please log in to download your schedule.' );

    $user_id = get_current_user_id();
    $umpire  = us_get_umpire_by_user( $user_id );
    if ( ! $umpire ) wp_die( 'No umpire profile found.' );

    if ( ! wp_verify_nonce( $_GET['nonce'] ?? '', 'us_ical_export_' . $user_id ) ) {
        wp_die( 'Security check failed.' );
    }

    $umpire_id   = $umpire->ID;
    $umpire_name = $umpire->post_title;
    $today       = current_time( 'Y-m-d' );
    $tz          = us_setting( 'timezone' );

    $assignments = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_umpire_id', 'value' => $umpire_id, 'compare' => '=' ],
            [ 'key' => 'us_status',    'value' => [ 'confirmed', 'pending', 'requested' ], 'compare' => 'IN' ],
        ],
    ] );

    $events = [];
    foreach ( $assignments as $a ) {
        $game_id   = get_post_meta( $a->ID, 'us_game_id',     true );
        $position  = get_post_meta( $a->ID, 'us_position',    true );
        $status    = get_post_meta( $a->ID, 'us_status',      true );
        $game_date = get_post_meta( $game_id, 'us_game_date', true );
        $game_time = get_post_meta( $game_id, 'us_game_time', true );
        $home      = get_post_meta( $game_id, 'us_home_team', true );
        $away      = get_post_meta( $game_id, 'us_away_team', true );
        $field     = get_post_meta( $game_id, 'us_field',     true );
        $league_id = get_post_meta( $game_id, 'us_league_id', true );
        $league    = $league_id ? get_the_title( $league_id ) : '';

        if ( ! $game_date || $game_date < $today ) continue;

        if ( $game_time ) {
            $dt_start = new DateTime( $game_date . ' ' . $game_time );
            $dt_end   = clone $dt_start;
            $dt_end->modify( '+2 hours' );
            $dtstart  = $dt_start->format( 'Ymd\THis' );
            $dtend    = $dt_end->format( 'Ymd\THis' );
        } else {
            $dtstart = date( 'Ymd', strtotime( $game_date ) );
            $dtend   = date( 'Ymd', strtotime( $game_date . ' +1 day' ) );
        }

        $events[] = [
            'uid'         => 'us-' . $a->ID . '-' . $game_id . '@' . parse_url( home_url(), PHP_URL_HOST ),
            'dtstart'     => $dtstart,
            'dtend'       => $dtend,
            'summary'     => $away . ' at ' . $home . ' (' . ucfirst( $position ) . ')',
            'description' => 'League: ' . $league . '\nPosition: ' . ucfirst( $position ) . '\nStatus: ' . ucfirst( $status ),
            'location'    => $field,
            'allday'      => ! $game_time,
        ];
    }

    // ── Build iCal output ─────────────────────────────────────
    $ics  = "BEGIN:VCALENDAR\r\n";
    $ics .= "VERSION:2.0\r\n";
    $ics .= "PRODID:-//" . us_setting( 'org_name' ) . "//EN\r\n";
    $ics .= "CALSCALE:GREGORIAN\r\n";
    $ics .= "METHOD:PUBLISH\r\n";
    $ics .= "X-WR-CALNAME:" . $umpire_name . " — " . us_setting( 'app_title' ) . "\r\n";
    $ics .= "X-WR-TIMEZONE:" . $tz . "\r\n";

    foreach ( $events as $event ) {
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:" . $event['uid'] . "\r\n";

        if ( $event['allday'] ) {
            $ics .= "DTSTART;VALUE=DATE:" . $event['dtstart'] . "\r\n";
            $ics .= "DTEND;VALUE=DATE:"   . $event['dtend']   . "\r\n";
        } else {
            $ics .= "DTSTART;TZID=" . $tz . ":" . $event['dtstart'] . "\r\n";
            $ics .= "DTEND;TZID="   . $tz . ":" . $event['dtend']   . "\r\n";
        }

        $ics .= "SUMMARY:"     . us_ical_escape( $event['summary'] )     . "\r\n";
        $ics .= "DESCRIPTION:" . us_ical_escape( $event['description'] ) . "\r\n";
        $ics .= "LOCATION:"    . us_ical_escape( $event['location'] )    . "\r\n";
        $ics .= "STATUS:CONFIRMED\r\n";
        $ics .= "END:VEVENT\r\n";
    }

    $ics .= "END:VCALENDAR\r\n";

    // ── Send headers and output ───────────────────────────────
    $filename = sanitize_title( $umpire_name ) . '-umpire-schedule.ics';

    header( 'Content-Type: text/calendar; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Cache-Control: no-cache, no-store, must-revalidate' );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );

    echo $ics;
    exit;
}

// ── iCal string escaping ──────────────────────────────────────
function us_ical_escape( $string ) {
    $string = str_replace( '\\', '\\\\', $string );
    $string = str_replace( ';',  '\;',   $string );
    $string = str_replace( ',',  '\,',   $string );
    return $string;
}