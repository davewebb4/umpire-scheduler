<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Shared helper: get game notification data ─────────────────
function us_get_notification_data( $assignment_id ) {
    $umpire_id = get_post_meta( $assignment_id, 'us_umpire_id', true );
    $game_id   = get_post_meta( $assignment_id, 'us_game_id',   true );
    $position  = get_post_meta( $assignment_id, 'us_position',  true );
    $game_date = get_post_meta( $game_id, 'us_game_date', true );
    $game_time = get_post_meta( $game_id, 'us_game_time', true );
    $league_id = get_post_meta( $game_id, 'us_league_id', true );

    return [
        'umpire_id'  => $umpire_id,
        'game_id'    => $game_id,
        'umpire'     => get_the_title( $umpire_id ),
        'email'      => get_post_meta( $umpire_id, 'us_email', true ),
        'home'       => get_post_meta( $game_id, 'us_home_team', true ),
        'away'       => get_post_meta( $game_id, 'us_away_team', true ),
        'field'      => get_post_meta( $game_id, 'us_field',     true ),
        'league'     => $league_id ? get_the_title( $league_id ) : '',
        'position'   => ucfirst( $position ),
        'date_fmt'   => $game_date ? date( 'l, F j, Y', strtotime( $game_date ) ) : '',
        'time_fmt'   => $game_time ? date( 'g:i a',     strtotime( $game_time ) ) : '',
    ];
}

// ── Umpire: admin assigned you to a game ─────────────────────
function us_notify_umpire_assigned( $assignment_id ) {
    $d = us_get_notification_data( $assignment_id );
    if ( ! $d['email'] ) return;

    $message  = "Hi {$d['umpire']},\n\n";
    $message .= "You have been assigned to the following game:\n\n";
    $message .= "League:   {$d['league']}\n";
    $message .= "Game:     {$d['away']} at {$d['home']}\n";
    $message .= "Date:     {$d['date_fmt']}\n";
    $message .= "Time:     {$d['time_fmt']}\n";
    $message .= "Field:    {$d['field']}\n";
    $message .= "Position: {$d['position']}\n\n";
    $message .= "Please log in to confirm or decline:\n";
    $message .= home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) . "\n\n";
    $message .= "Thanks,\n" . us_setting( 'email_footer' );

    wp_mail( $d['email'], 'Game assignment — ' . $d['date_fmt'], $message );
}

// ── Umpire: your request was confirmed ───────────────────────
function us_notify_umpire_confirmed( $assignment_id ) {
    $d = us_get_notification_data( $assignment_id );
    if ( ! $d['email'] ) return;

    $message  = "Hi {$d['umpire']},\n\n";
    $message .= "Great news — your request for the following game has been confirmed:\n\n";
    $message .= "League:   {$d['league']}\n";
    $message .= "Game:     {$d['away']} at {$d['home']}\n";
    $message .= "Date:     {$d['date_fmt']}\n";
    $message .= "Time:     {$d['time_fmt']}\n";
    $message .= "Field:    {$d['field']}\n";
    $message .= "Position: {$d['position']}\n\n";
    $message .= "Log in to view your schedule:\n";
    $message .= home_url( '/' . us_setting( 'slug_schedule' ) . '/' ) . "\n\n";
    $message .= "Thanks,\n" . us_setting( 'email_footer' );

    wp_mail( $d['email'], 'Game confirmed — ' . $d['date_fmt'], $message );
}

// ── Umpire: your request was denied ──────────────────────────
function us_notify_umpire_denied( $assignment_id ) {
    $d = us_get_notification_data( $assignment_id );
    if ( ! $d['email'] ) return;

    $message  = "Hi {$d['umpire']},\n\n";
    $message .= "Unfortunately your request for the following game was not selected:\n\n";
    $message .= "League:   {$d['league']}\n";
    $message .= "Game:     {$d['away']} at {$d['home']}\n";
    $message .= "Date:     {$d['date_fmt']}\n";
    $message .= "Time:     {$d['time_fmt']}\n";
    $message .= "Position: {$d['position']}\n\n";
    $message .= "Check the open games list for other available games:\n";
    $message .= home_url( '/' . us_setting( 'slug_open_games' ) . '/' ) . "\n\n";
    $message .= "Thanks,\n" . us_setting( 'email_footer' );

    wp_mail( $d['email'], 'Game request update — ' . $d['date_fmt'], $message );
}

// ── Umpire: marked as no-show ─────────────────────────────────
function us_notify_umpire_noshow( $assignment_id ) {
    $d = us_get_notification_data( $assignment_id );
    if ( ! $d['email'] ) return;

    $message  = "Hi {$d['umpire']},\n\n";
    $message .= "Your assignment for the following game has been marked as a no-show:\n\n";
    $message .= "Game:  {$d['away']} at {$d['home']}\n";
    $message .= "Date:  {$d['date_fmt']}\n\n";
    $message .= "Payment for this game has been set to \$0.\n\n";
    $message .= "If you believe this is an error please contact the assignor.\n\n";
    $message .= "Thanks,\n" . us_setting( 'email_footer' );

    wp_mail( $d['email'], 'No-show recorded — ' . $d['date_fmt'], $message );
}

// ── Umpire: game postponed — still being paid ─────────────────
function us_notify_umpire_postponed_paid( $assignment_id ) {
    $d   = us_get_notification_data( $assignment_id );
    $pay = get_post_meta( $assignment_id, 'us_pay_amount', true );
    if ( ! $d['email'] ) return;

    $message  = "Hi {$d['umpire']},\n\n";
    $message .= "The following game has been postponed:\n\n";
    $message .= "League:   {$d['league']}\n";
    $message .= "Game:     {$d['away']} at {$d['home']}\n";
    $message .= "Date:     {$d['date_fmt']}\n";
    $message .= "Time:     {$d['time_fmt']}\n";
    $message .= "Position: {$d['position']}\n\n";
    $message .= "You arrived on site — you will still be paid \$" . number_format( floatval( $pay ), 2 ) . " for this game.\n\n";
    $message .= "View your earnings:\n";
    $message .= home_url( '/' . us_setting( 'slug_earnings' ) . '/' ) . "\n\n";
    $message .= "Thanks,\n" . us_setting( 'email_footer' );

    wp_mail( $d['email'], 'Game postponed — ' . $d['date_fmt'], $message );
}

// ── Umpire: game postponed — not being paid ───────────────────
function us_notify_umpire_postponed_unpaid( $assignment_id ) {
    $d = us_get_notification_data( $assignment_id );
    if ( ! $d['email'] ) return;

    $message  = "Hi {$d['umpire']},\n\n";
    $message .= "The following game has been postponed:\n\n";
    $message .= "League:   {$d['league']}\n";
    $message .= "Game:     {$d['away']} at {$d['home']}\n";
    $message .= "Date:     {$d['date_fmt']}\n";
    $message .= "Time:     {$d['time_fmt']}\n";
    $message .= "Position: {$d['position']}\n\n";
    $message .= "This game will not be paid. Check the open games list for upcoming opportunities:\n";
    $message .= home_url( '/' . us_setting( 'slug_open_games' ) . '/' ) . "\n\n";
    $message .= "Thanks,\n" . us_setting( 'email_footer' );

    wp_mail( $d['email'], 'Game postponed — ' . $d['date_fmt'], $message );
}

// ── Assignor: umpire confirmed their assignment ───────────────
function us_notify_assignor_confirmed( $assignment_id ) {
    $d = us_get_notification_data( $assignment_id );
    wp_mail(
        us_get_assignor_email(),
        $d['umpire'] . ' confirmed — ' . $d['date_fmt'],
        "{$d['umpire']} has confirmed the {$d['position']} position for {$d['away']} at {$d['home']} on {$d['date_fmt']}."
    );
}

// ── Assignor: umpire declined their assignment ────────────────
function us_notify_assignor_declined( $assignment_id ) {
    $d = us_get_notification_data( $assignment_id );
    wp_mail(
        us_get_assignor_email(),
        $d['umpire'] . ' declined — ' . $d['date_fmt'],
        "{$d['umpire']} has declined the {$d['position']} position for {$d['away']} at {$d['home']} on {$d['date_fmt']}.\n\nPlease assign another umpire:\n" . admin_url( 'admin.php' )
    );
}

// ── Assignor: umpire requested an open game ───────────────────
function us_notify_assignor_requested( $assignment_id ) {
    if ( ! get_option( 'us_notify_allocator_on_request', 0 ) ) return;
    $d = us_get_notification_data( $assignment_id );
    wp_mail(
        us_get_assignor_email(),
        $d['umpire'] . ' requested a game — ' . $d['date_fmt'],
        "{$d['umpire']} has requested the {$d['position']} position for {$d['away']} at {$d['home']} on {$d['date_fmt']}.\n\nLog in to approve or deny:\n" . admin_url( 'admin.php?page=us-requests' )
    );
}

// ── Assignor: new umpire self-registered ─────────────────────
function us_notify_assignor_new_umpire( $name, $email ) {
    $admin_url = admin_url( 'edit.php?post_type=' . US_PT_UMPIRE );
    wp_mail(
        us_get_assignor_email(),
        'New umpire registered — ' . $name,
        "{$name} ({$email}) has created an umpire account and is now active in the system.\n\nView their profile:\n{$admin_url}"
    );
}

// ── Umpire: welcome email after self-registration ─────────────
function us_notify_umpire_welcome( $email, $name ) {
    $dashboard_url = home_url( '/' . us_setting( 'slug_dashboard' ) . '/' );
    $open_games_url = home_url( '/' . us_setting( 'slug_open_games' ) . '/' );

    $message  = "Hi {$name},\n\n";
    $message .= "Welcome! Your umpire account has been created and you're ready to go.\n\n";
    $message .= "Log in to your dashboard:\n{$dashboard_url}\n\n";
    $message .= "Browse open games:\n{$open_games_url}\n\n";
    $message .= "Thanks,\n" . us_setting( 'email_footer' );

    wp_mail( $email, 'Welcome to ' . us_setting( 'app_title' ), $message );
}

// ── Notify all assigned umpires of a game change ──────────────
function us_notify_game_changed( $game_id, $changes ) {
    $home      = get_post_meta( $game_id, 'us_home_team', true );
    $away      = get_post_meta( $game_id, 'us_away_team', true );
    $league_id = get_post_meta( $game_id, 'us_league_id', true );
    $league    = $league_id ? get_the_title( $league_id ) : '';
    $new_date  = get_post_meta( $game_id, 'us_game_date', true );
    $date_fmt  = $new_date ? date( 'l, F j, Y', strtotime( $new_date ) ) : '';

    $assignments = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_game_id', 'value' => $game_id, 'compare' => '=' ],
            [ 'key' => 'us_status',  'value' => [ 'confirmed', 'pending', 'requested' ], 'compare' => 'IN' ],
        ],
    ] );

    if ( empty( $assignments ) ) return 0;

    $notified = 0;
    foreach ( $assignments as $a ) {
        $umpire_id = get_post_meta( $a->ID, 'us_umpire_id', true );
        $position  = get_post_meta( $a->ID, 'us_position',  true );
        $email     = get_post_meta( $umpire_id, 'us_email', true );
        $umpire    = get_the_title( $umpire_id );
        if ( ! $email ) continue;

        $change_lines = '';
        if ( isset( $changes['date'] ) ) {
            $old_fmt      = $changes['date']['old'] ? date( 'l, F j, Y', strtotime( $changes['date']['old'] ) ) : '—';
            $new_fmt      = $changes['date']['new'] ? date( 'l, F j, Y', strtotime( $changes['date']['new'] ) ) : '—';
            $change_lines .= "Date:  {$new_fmt} (was {$old_fmt})\n";
        }
        if ( isset( $changes['time'] ) ) {
            $old_fmt      = $changes['time']['old'] ? date( 'g:i a', strtotime( $changes['time']['old'] ) ) : '—';
            $new_fmt      = $changes['time']['new'] ? date( 'g:i a', strtotime( $changes['time']['new'] ) ) : '—';
            $change_lines .= "Time:  {$new_fmt} (was {$old_fmt})\n";
        }
        if ( isset( $changes['field'] ) ) {
            $change_lines .= "Field: {$changes['field']['new']} (was {$changes['field']['old']})\n";
        }

        $message  = "Hi {$umpire},\n\n";
        $message .= "A game you are assigned to has been updated:\n\n";
        $message .= "Game:     {$away} at {$home}\n";
        $message .= "League:   {$league}\n";
        $message .= "Position: " . ucfirst( $position ) . "\n\n";
        $message .= "UPDATED DETAILS:\n";
        $message .= $change_lines . "\n";
        $message .= "Please update your calendar.\n\n";
        $message .= "Log in to view your schedule:\n";
        $message .= home_url( '/' . us_setting( 'slug_schedule' ) . '/' ) . "\n\n";
        $message .= "Thanks,\n" . us_setting( 'email_footer' );

        wp_mail( $email, 'Game update — ' . $away . ' at ' . $home . ' — ' . $date_fmt, $message );
        $notified++;
    }

    return $notified;
}