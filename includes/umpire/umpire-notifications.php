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
        'umpire_id' => $umpire_id,
        'game_id'   => $game_id,
        'umpire'    => get_the_title( $umpire_id ),
        'email'     => get_post_meta( $umpire_id, 'us_email', true ),
        'home'      => get_post_meta( $game_id, 'us_home_team', true ),
        'away'      => get_post_meta( $game_id, 'us_away_team', true ),
        'field'     => get_post_meta( $game_id, 'us_field',     true ),
        'league'    => $league_id ? get_the_title( $league_id ) : '',
        'position'  => ucfirst( $position ),
        'date_fmt'  => $game_date ? date( 'l, F j, Y', strtotime( $game_date ) ) : '',
        'time_fmt'  => $game_time ? date( 'g:i a',     strtotime( $game_time ) ) : '',
    ];
}

// ── HTML email wrapper ────────────────────────────────────────
function us_email_wrap( $body ) {
    $org  = us_setting( 'org_name' ) ?: us_setting( 'app_title' );
    $foot = us_setting( 'email_footer' ) ?: $org;

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f4f6f9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:32px 16px;">'
        . '<tr><td align="center">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">'
        . '<tr><td style="background:#091b33;padding:22px 32px;text-align:center;">'
        . '<p style="color:#ffffff;font-size:18px;font-weight:700;margin:0;letter-spacing:.02em;">' . esc_html( $org ) . '</p>'
        . '</td></tr>'
        . '<tr><td style="padding:32px 32px 24px;">' . $body . '</td></tr>'
        . '<tr><td style="background:#f8fafc;padding:16px 32px;text-align:center;border-top:1px solid #eef0f3;">'
        . '<p style="color:#aaa;font-size:12px;margin:0;">' . esc_html( $foot ) . '</p>'
        . '</td></tr>'
        . '</table>'
        . '</td></tr></table>'
        . '</body></html>';
}

// ── Reusable game details block ───────────────────────────────
function us_email_game_table( $d, $rows = null ) {
    if ( $rows === null ) {
        $rows = [
            'League'   => $d['league'],
            'Game'     => $d['away'] . ' at ' . $d['home'],
            'Date'     => $d['date_fmt'],
            'Time'     => $d['time_fmt'],
            'Field'    => $d['field'],
            'Position' => $d['position'],
        ];
    }

    $html = '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:6px;margin:20px 0;font-size:14px;overflow:hidden;">';
    $first = true;
    foreach ( $rows as $label => $value ) {
        if ( ! $value ) continue;
        $border = $first ? '' : 'border-top:1px solid #eef0f3;';
        $html .= '<tr>'
            . '<td style="' . $border . 'padding:10px 16px;color:#888;font-size:13px;width:90px;white-space:nowrap;">' . esc_html( $label ) . '</td>'
            . '<td style="' . $border . 'padding:10px 16px;color:#091b33;font-weight:600;font-size:14px;">' . esc_html( $value ) . '</td>'
            . '</tr>';
        $first = false;
    }
    $html .= '</table>';
    return $html;
}

// ── CTA button ────────────────────────────────────────────────
function us_email_btn( $url, $label ) {
    return '<p style="margin:24px 0 0;">'
        . '<a href="' . esc_url( $url ) . '" style="display:inline-block;background:#091b33;color:#ffffff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;letter-spacing:.01em;">'
        . esc_html( $label ) . '</a></p>';
}

// ── Greeting ──────────────────────────────────────────────────
function us_email_greeting( $name ) {
    return '<p style="font-size:16px;color:#091b33;margin:0 0 4px;font-weight:600;">Hi ' . esc_html( $name ) . ',</p>';
}

// ── HTML mail headers ─────────────────────────────────────────
function us_email_headers() {
    $from_name  = us_setting( 'assignor_name' )  ?: get_bloginfo( 'name' );
    $from_email = us_setting( 'assignor_email' ) ?: get_option( 'admin_email' );
    return [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $from_name . ' <' . $from_email . '>',
    ];
}

// ── Umpire: admin assigned you to a game ─────────────────────
function us_notify_umpire_assigned( $assignment_id ) {
    $d = us_get_notification_data( $assignment_id );
    if ( ! $d['email'] ) return;

    $body  = us_email_greeting( $d['umpire'] );
    $body .= '<p style="font-size:14px;color:#444;margin:12px 0 0;">You have been assigned to the following game. Please log in to confirm or decline.</p>';
    $body .= us_email_game_table( $d );
    $body .= us_email_btn( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ), 'Confirm or Decline' );

    wp_mail( $d['email'], 'Game assignment — ' . $d['date_fmt'], us_email_wrap( $body ), us_email_headers() );
}

// ── Umpire: your request was confirmed ───────────────────────
function us_notify_umpire_confirmed( $assignment_id ) {
    $d = us_get_notification_data( $assignment_id );
    if ( ! $d['email'] ) return;

    $body  = us_email_greeting( $d['umpire'] );
    $body .= '<p style="font-size:14px;color:#444;margin:12px 0 0;">&#127881; Great news — your request for the following game has been <strong style="color:#1a7f3c;">confirmed</strong>.</p>';
    $body .= us_email_game_table( $d );
    $body .= us_email_btn( home_url( '/' . us_setting( 'slug_schedule' ) . '/' ), 'View My Schedule' );

    wp_mail( $d['email'], 'Game confirmed — ' . $d['date_fmt'], us_email_wrap( $body ), us_email_headers() );
}

// ── Umpire: your request was denied ──────────────────────────
function us_notify_umpire_denied( $assignment_id ) {
    $d = us_get_notification_data( $assignment_id );
    if ( ! $d['email'] ) return;

    $body  = us_email_greeting( $d['umpire'] );
    $body .= '<p style="font-size:14px;color:#444;margin:12px 0 0;">Unfortunately your request for the following game was not selected. Check the open games list for other opportunities.</p>';
    $body .= us_email_game_table( $d, [
        'League'   => $d['league'],
        'Game'     => $d['away'] . ' at ' . $d['home'],
        'Date'     => $d['date_fmt'],
        'Time'     => $d['time_fmt'],
        'Position' => $d['position'],
    ] );
    $body .= us_email_btn( home_url( '/' . us_setting( 'slug_open_games' ) . '/' ), 'Browse Open Games' );

    wp_mail( $d['email'], 'Game request update — ' . $d['date_fmt'], us_email_wrap( $body ), us_email_headers() );
}

// ── Umpire: marked as no-show ─────────────────────────────────
function us_notify_umpire_noshow( $assignment_id ) {
    $d = us_get_notification_data( $assignment_id );
    if ( ! $d['email'] ) return;

    $body  = us_email_greeting( $d['umpire'] );
    $body .= '<p style="font-size:14px;color:#444;margin:12px 0 0;">Your assignment for the following game has been marked as a <strong style="color:#dc2626;">no-show</strong>. Payment has been set to $0.</p>';
    $body .= us_email_game_table( $d, [
        'Game' => $d['away'] . ' at ' . $d['home'],
        'Date' => $d['date_fmt'],
    ] );
    $body .= '<p style="font-size:13px;color:#888;margin:16px 0 0;">If you believe this is an error please contact the assignor.</p>';

    wp_mail( $d['email'], 'No-show recorded — ' . $d['date_fmt'], us_email_wrap( $body ), us_email_headers() );
}

// ── Umpire: game postponed — still being paid ─────────────────
function us_notify_umpire_postponed_paid( $assignment_id ) {
    $d   = us_get_notification_data( $assignment_id );
    $pay = get_post_meta( $assignment_id, 'us_pay_amount', true );
    if ( ! $d['email'] ) return;

    $body  = us_email_greeting( $d['umpire'] );
    $body .= '<p style="font-size:14px;color:#444;margin:12px 0 0;">The following game has been <strong>postponed</strong>. You arrived on site and will still be paid <strong style="color:#1a7f3c;">$' . number_format( floatval( $pay ), 2 ) . '</strong>.</p>';
    $body .= us_email_game_table( $d );
    $body .= us_email_btn( home_url( '/' . us_setting( 'slug_earnings' ) . '/' ), 'View My Earnings' );

    wp_mail( $d['email'], 'Game postponed — ' . $d['date_fmt'], us_email_wrap( $body ), us_email_headers() );
}

// ── Umpire: game postponed — not being paid ───────────────────
function us_notify_umpire_postponed_unpaid( $assignment_id ) {
    $d = us_get_notification_data( $assignment_id );
    if ( ! $d['email'] ) return;

    $body  = us_email_greeting( $d['umpire'] );
    $body .= '<p style="font-size:14px;color:#444;margin:12px 0 0;">The following game has been <strong>postponed</strong>. This game will not be paid.</p>';
    $body .= us_email_game_table( $d );
    $body .= us_email_btn( home_url( '/' . us_setting( 'slug_open_games' ) . '/' ), 'Browse Open Games' );

    wp_mail( $d['email'], 'Game postponed — ' . $d['date_fmt'], us_email_wrap( $body ), us_email_headers() );
}

// ── Umpire: welcome email after self-registration ─────────────
function us_notify_umpire_welcome( $email, $name ) {
    $body  = us_email_greeting( $name );
    $body .= '<p style="font-size:14px;color:#444;margin:12px 0 0;">Welcome! Your umpire account is set up and ready to go.</p>';
    $body .= '<ul style="font-size:14px;color:#444;margin:16px 0;padding-left:20px;line-height:2;">'
           . '<li>Browse and request open games</li>'
           . '<li>View your upcoming schedule</li>'
           . '<li>Set your availability</li>'
           . '<li>Track your earnings</li>'
           . '</ul>';
    $body .= us_email_btn( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ), 'Go to My Dashboard' );

    wp_mail( $email, 'Welcome to ' . us_setting( 'app_title' ), us_email_wrap( $body ), us_email_headers() );
}

// ── Assignor: umpire confirmed their assignment ───────────────
function us_notify_assignor_confirmed( $assignment_id ) {
    $d = us_get_notification_data( $assignment_id );

    $body  = '<p style="font-size:15px;color:#091b33;font-weight:600;margin:0;">' . esc_html( $d['umpire'] ) . ' confirmed</p>';
    $body .= '<p style="font-size:14px;color:#444;margin:10px 0 0;">' . esc_html( $d['umpire'] ) . ' has confirmed the <strong>' . esc_html( $d['position'] ) . '</strong> position.</p>';
    $body .= us_email_game_table( $d, [
        'Game'     => $d['away'] . ' at ' . $d['home'],
        'Date'     => $d['date_fmt'],
        'Position' => $d['position'],
    ] );

    wp_mail( us_get_assignor_email(), $d['umpire'] . ' confirmed — ' . $d['date_fmt'], us_email_wrap( $body ), us_email_headers() );
}

// ── Assignor: umpire declined their assignment ────────────────
function us_notify_assignor_declined( $assignment_id ) {
    $d = us_get_notification_data( $assignment_id );

    $body  = '<p style="font-size:15px;color:#dc2626;font-weight:600;margin:0;">' . esc_html( $d['umpire'] ) . ' declined</p>';
    $body .= '<p style="font-size:14px;color:#444;margin:10px 0 0;">' . esc_html( $d['umpire'] ) . ' has declined the <strong>' . esc_html( $d['position'] ) . '</strong> position. You may need to assign a replacement.</p>';
    $body .= us_email_game_table( $d, [
        'Game'     => $d['away'] . ' at ' . $d['home'],
        'Date'     => $d['date_fmt'],
        'Position' => $d['position'],
    ] );
    $body .= us_email_btn( home_url( '/' . us_setting( 'slug_allocator_dashboard' ) . '/' ), 'Go to Allocator Dashboard' );

    wp_mail( us_get_assignor_email(), $d['umpire'] . ' declined — ' . $d['date_fmt'], us_email_wrap( $body ), us_email_headers() );
}

// ── Assignor: umpire requested an open game ───────────────────
function us_notify_assignor_requested( $assignment_id ) {
    if ( ! get_option( 'us_notify_allocator_on_request', 0 ) ) return;
    $d = us_get_notification_data( $assignment_id );

    $body  = '<p style="font-size:15px;color:#091b33;font-weight:600;margin:0;">' . esc_html( $d['umpire'] ) . ' requested a game</p>';
    $body .= '<p style="font-size:14px;color:#444;margin:10px 0 0;">' . esc_html( $d['umpire'] ) . ' has requested the <strong>' . esc_html( $d['position'] ) . '</strong> position.</p>';
    $body .= us_email_game_table( $d, [
        'Game'     => $d['away'] . ' at ' . $d['home'],
        'Date'     => $d['date_fmt'],
        'Position' => $d['position'],
    ] );
    $body .= us_email_btn( home_url( '/' . us_setting( 'slug_allocator_dashboard' ) . '/' ), 'Review Requests' );

    wp_mail( us_get_assignor_email(), $d['umpire'] . ' requested a game — ' . $d['date_fmt'], us_email_wrap( $body ), us_email_headers() );
}

// ── Assignor: new umpire self-registered ─────────────────────
function us_notify_assignor_new_umpire( $name, $email ) {
    $body  = '<p style="font-size:15px;color:#091b33;font-weight:600;margin:0;">New umpire registered</p>';
    $body .= '<p style="font-size:14px;color:#444;margin:10px 0 0;"><strong>' . esc_html( $name ) . '</strong> (' . esc_html( $email ) . ') has created an account and is now active in the system.</p>';
    $body .= us_email_btn( admin_url( 'edit.php?post_type=' . US_PT_UMPIRE ), 'View Umpire Profiles' );

    wp_mail( us_get_assignor_email(), 'New umpire registered — ' . $name, us_email_wrap( $body ), us_email_headers() );
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

        $change_rows = [];
        if ( isset( $changes['date'] ) ) {
            $old = $changes['date']['old'] ? date( 'l, F j, Y', strtotime( $changes['date']['old'] ) ) : '—';
            $new = $changes['date']['new'] ? date( 'l, F j, Y', strtotime( $changes['date']['new'] ) ) : '—';
            $change_rows['Date'] = $new . ' <span style="color:#888;font-weight:400;">(was ' . $old . ')</span>';
        }
        if ( isset( $changes['time'] ) ) {
            $old = $changes['time']['old'] ? date( 'g:i a', strtotime( $changes['time']['old'] ) ) : '—';
            $new = $changes['time']['new'] ? date( 'g:i a', strtotime( $changes['time']['new'] ) ) : '—';
            $change_rows['Time'] = $new . ' <span style="color:#888;font-weight:400;">(was ' . $old . ')</span>';
        }
        if ( isset( $changes['field'] ) ) {
            $change_rows['Field'] = $changes['field']['new'] . ' <span style="color:#888;font-weight:400;">(was ' . $changes['field']['old'] . ')</span>';
        }

        $body  = us_email_greeting( $umpire );
        $body .= '<p style="font-size:14px;color:#444;margin:12px 0 0;">A game you are assigned to has been <strong>updated</strong>. Please update your calendar.</p>';

        // Game summary
        $body .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:6px;margin:20px 0;">';
        $body .= '<tr><td style="padding:10px 16px;color:#888;font-size:13px;width:90px;">Game</td><td style="padding:10px 16px;color:#091b33;font-weight:600;">' . esc_html( $away . ' at ' . $home ) . '</td></tr>';
        $body .= '<tr><td style="padding:10px 16px;color:#888;font-size:13px;border-top:1px solid #eef0f3;">League</td><td style="padding:10px 16px;color:#091b33;font-weight:600;border-top:1px solid #eef0f3;">' . esc_html( $league ) . '</td></tr>';
        $body .= '<tr><td style="padding:10px 16px;color:#888;font-size:13px;border-top:1px solid #eef0f3;">Position</td><td style="padding:10px 16px;color:#091b33;font-weight:600;border-top:1px solid #eef0f3;">' . esc_html( ucfirst( $position ) ) . '</td></tr>';
        $body .= '</table>';

        if ( ! empty( $change_rows ) ) {
            $body .= '<p style="font-size:13px;font-weight:700;color:#091b33;margin:0 0 8px;text-transform:uppercase;letter-spacing:.05em;">Updated details</p>';
            $body .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#fff8e1;border-radius:6px;border:1px solid #ffe082;">';
            $first = true;
            foreach ( $change_rows as $label => $value ) {
                $border = $first ? '' : 'border-top:1px solid #ffe082;';
                $body .= '<tr><td style="' . $border . 'padding:10px 16px;color:#888;font-size:13px;width:90px;">' . esc_html( $label ) . '</td><td style="' . $border . 'padding:10px 16px;font-size:14px;">' . $value . '</td></tr>';
                $first = false;
            }
            $body .= '</table>';
        }

        $body .= us_email_btn( home_url( '/' . us_setting( 'slug_schedule' ) . '/' ), 'View My Schedule' );

        wp_mail( $email, 'Game update — ' . $away . ' at ' . $home . ' — ' . $date_fmt, us_email_wrap( $body ), us_email_headers() );
        $notified++;
    }

    return $notified;
}
