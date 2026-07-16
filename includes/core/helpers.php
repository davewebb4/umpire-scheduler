<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Logo helper ───────────────────────────────────────────────
// Reads the plugin's own logo setting first; falls back to the
// WordPress custom_logo theme mod so existing setups keep working.
function us_get_logo_url( $size = 'medium' ) {
    $id = (int) us_setting( 'logo_id' );
    if ( $id ) {
        $url = wp_get_attachment_image_url( $id, $size );
        if ( $url ) return $url;
    }
    $theme_id = get_theme_mod( 'custom_logo' );
    return $theme_id ? ( wp_get_attachment_image_url( $theme_id, $size ) ?: '' ) : '';
}

// ── Assignment helpers ────────────────────────────────────────

// Get any assignment for a game + position (any status)
function us_get_assignment( $game_id, $position ) {
    $assignments = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => 1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_game_id',  'value' => $game_id,  'compare' => '=' ],
            [ 'key' => 'us_position', 'value' => $position, 'compare' => '=' ],
        ],
    ] );
    return $assignments ? $assignments[0] : null;
}

// Get the confirmed assignment for a game + position
function us_get_confirmed_assignment( $game_id, $position ) {
    $assignments = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => 1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_game_id',  'value' => $game_id,    'compare' => '=' ],
            [ 'key' => 'us_position', 'value' => $position,   'compare' => '=' ],
            [ 'key' => 'us_status',   'value' => 'confirmed', 'compare' => '=' ],
        ],
    ] );
    return $assignments ? $assignments[0] : null;
}

// Get all requests (requested/pending) for a game + position
function us_get_slot_requests( $game_id, $position ) {
    return get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_game_id',  'value' => $game_id,  'compare' => '=' ],
            [ 'key' => 'us_position', 'value' => $position, 'compare' => '=' ],
            [ 'key' => 'us_status',   'value' => [ 'requested', 'pending' ], 'compare' => 'IN' ],
        ],
    ] );
}

// ── Game helpers ──────────────────────────────────────────────

// Check if a game is postponed
function us_is_game_postponed( $game_id ) {
    return get_post_meta( $game_id, 'us_game_status', true ) === 'postponed';
}

// Check if a game is cancelled
function us_is_game_cancelled( $game_id ) {
    return get_post_meta( $game_id, 'us_game_status', true ) === 'cancelled';
}

// Get the pay rate for a game (respects double header flag)
function us_get_game_pay_rate( $game_id ) {
    $league_id = get_post_meta( $game_id, 'us_league_id',     true );
    $is_dh     = get_post_meta( $game_id, 'us_double_header', true ) === '1';

    if ( ! $league_id ) return 0;

    if ( $is_dh ) {
        $rate = get_post_meta( $league_id, 'us_dh_pay_rate', true );
        if ( $rate ) return floatval( $rate );
    }

    return floatval( get_post_meta( $league_id, 'us_pay_rate', true ) );
}

// ── Umpire helpers ────────────────────────────────────────────

// Get umpire post by WP user ID
function us_get_umpire_by_user( $user_id ) {
    $umpires = get_posts( [
        'post_type'   => US_PT_UMPIRE,
        'numberposts' => 1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_wp_user_id', 'value' => $user_id, 'compare' => '=' ],
        ],
    ] );
    return $umpires ? $umpires[0] : null;
}

// Get all non-archived leagues. Pass true for tournaments only, false for regular only, null for all.
function us_get_active_leagues( $is_tournament = null ) {
    $not_archived = [
        'relation' => 'OR',
        [ 'key' => 'us_is_archived', 'compare' => 'NOT EXISTS' ],
        [ 'key' => 'us_is_archived', 'value' => '1', 'compare' => '!=' ],
    ];

    $meta_query = [ 'relation' => 'AND', $not_archived ];

    if ( $is_tournament === true ) {
        $meta_query[] = [ 'key' => 'us_is_tournament', 'value' => '1', 'compare' => '=' ];
    } elseif ( $is_tournament === false ) {
        $meta_query[] = [
            'relation' => 'OR',
            [ 'key' => 'us_is_tournament', 'compare' => 'NOT EXISTS' ],
            [ 'key' => 'us_is_tournament', 'value' => '1', 'compare' => '!=' ],
        ];
    }

    return get_posts( [
        'post_type'   => US_PT_LEAGUE,
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'post_status' => 'publish',
        'meta_query'  => $meta_query,
    ] );
}

// Get all active umpires
function us_get_active_umpires() {
    return get_posts( [
        'post_type'   => US_PT_UMPIRE,
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_active', 'value' => '1', 'compare' => '=' ],
        ],
    ] );
}

// Get unavailable umpire IDs for a given date
function us_get_unavailable_umpires( $date ) {
    $all     = us_get_active_umpires();
    $unavail = [];
    foreach ( $all as $u ) {
        $raw   = get_post_meta( $u->ID, 'us_unavailable_dates', true );
        $dates = is_array( $raw ) ? $raw : ( is_string( $raw ) && ! empty( $raw ) ? json_decode( $raw, true ) : [] );
        if ( is_array( $dates ) && in_array( $date, $dates ) ) {
            $unavail[] = $u->ID;
        }
    }
    return $unavail;
}

// Get umpire IDs already confirmed on a given date
// Excludes postponed games — a postponed game doesn't count as busy
function us_get_assigned_umpires_on_date( $date ) {
    $games = get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_game_date',   'value' => $date,       'compare' => '=' ],
            [ 'key' => 'us_game_status', 'value' => 'postponed', 'compare' => '!=' ],
        ],
    ] );

    $assigned = [];
    foreach ( $games as $game ) {
        $assignments = get_posts( [
            'post_type'   => US_PT_ASSIGNMENT,
            'numberposts' => -1,
            'post_status' => 'publish',
            'meta_query'  => [
                [ 'key' => 'us_game_id', 'value' => $game->ID,   'compare' => '=' ],
                [ 'key' => 'us_status',  'value' => 'confirmed', 'compare' => '=' ],
            ],
        ] );
        foreach ( $assignments as $a ) {
            $uid = get_post_meta( $a->ID, 'us_umpire_id', true );
            if ( $uid ) $assigned[ $uid ] = true;
        }
    }
    return array_keys( $assigned );
}

// Check if an umpire has a confirmed game at the same date/time
function us_umpire_has_conflict( $umpire_id, $game_id ) {
    $game_date = get_post_meta( $game_id, 'us_game_date', true );
    $game_time = get_post_meta( $game_id, 'us_game_time', true );

    if ( ! $game_date || ! $game_time ) return false;

    $confirmed = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_umpire_id', 'value' => $umpire_id,  'compare' => '=' ],
            [ 'key' => 'us_status',    'value' => 'confirmed',  'compare' => '=' ],
        ],
    ] );

    foreach ( $confirmed as $c ) {
        $c_game_id = get_post_meta( $c->ID, 'us_game_id', true );
        if ( $c_game_id == $game_id ) continue;
        $c_date = get_post_meta( $c_game_id, 'us_game_date', true );
        $c_time = get_post_meta( $c_game_id, 'us_game_time', true );
        if ( $c_date === $game_date && $c_time === $game_time ) {
            return true;
        }
    }

    return false;
}

// ── Pay helpers ───────────────────────────────────────────────

// Get umpire pay summary grouped by month
function us_get_umpire_pay_summary( $umpire_id ) {
    $assignments = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_umpire_id', 'value' => $umpire_id,                        'compare' => '=' ],
            [ 'key' => 'us_status',    'value' => [ 'confirmed', 'postponed-paid' ], 'compare' => 'IN' ],
        ],
    ] );

    $months          = [];
    $total_games     = 0;
    $total_earned    = 0;
    $total_paid      = 0;
    $total_outstanding = 0;

    foreach ( $assignments as $a ) {
        $game_id   = get_post_meta( $a->ID, 'us_game_id',   true );
        $league_id = get_post_meta( $game_id, 'us_league_id', true );

        // Skip tournament games — they are tracked separately
        if ( $league_id && get_post_meta( $league_id, 'us_is_tournament', true ) === '1' ) continue;

        $game_date = get_post_meta( $game_id, 'us_game_date', true );
        $pay       = floatval( get_post_meta( $a->ID, 'us_pay_amount', true ) );

        if ( ! $game_date ) continue;

        $month_key   = date( 'Y-m', strtotime( $game_date ) );
        $month_label = date( 'F Y', strtotime( $game_date ) );

        if ( ! isset( $months[ $month_key ] ) ) {
            $payments    = get_posts( [
                'post_type'   => US_PT_PAYMENT,
                'numberposts' => -1,
                'post_status' => 'publish',
                'meta_query'  => [
                    [ 'key' => 'payment_umpire_id', 'value' => $umpire_id, 'compare' => '=' ],
                    [ 'key' => 'payment_month',     'value' => $month_key, 'compare' => '=' ],
                ],
            ] );
            $amount_paid = 0.0;
            foreach ( $payments as $p ) {
                $amount_paid += floatval( get_post_meta( $p->ID, 'payment_amount', true ) );
            }

            $months[ $month_key ] = [
                'label'        => $month_label,
                'games'        => 0,
                'earned'       => 0,
                'amount_paid'  => $amount_paid,
                'paid'         => false,
                'pay_status'   => 'outstanding',
                'payment_date' => ! empty( $payments ) ? get_post_meta( $payments[0]->ID, 'payment_date', true ) : '',
                'payment_ids'  => array_column( $payments, 'ID' ),
            ];
        }

        $months[ $month_key ]['games']++;
        $months[ $month_key ]['earned'] += $pay;
        $total_games++;
        $total_earned += $pay;
    }

    // Determine pay status and accumulate totals once all earnings are known
    foreach ( $months as &$month ) {
        $ap = $month['amount_paid'];
        $e  = $month['earned'];
        if ( $ap <= 0 ) {
            $month['pay_status'] = 'outstanding';
            $month['paid']       = false;
            $total_outstanding  += $e;
        } elseif ( $ap >= $e ) {
            $month['pay_status'] = 'paid';
            $month['paid']       = true;
            $total_paid         += $e;
        } else {
            $month['pay_status'] = 'partial';
            $month['paid']       = false;
            $total_paid         += $ap;
            $total_outstanding  += $e - $ap;
        }
    }
    unset( $month );

    krsort( $months );

    return [
        'months'            => $months,
        'total_games'       => $total_games,
        'total_earned'      => $total_earned,
        'total_paid'        => $total_paid,
        'total_outstanding' => $total_outstanding,
    ];
}

// ── User/role helpers ─────────────────────────────────────────

// Check if current user is an allocator (assignor role on umpire profile)
function us_is_allocator() {
    if ( ! is_user_logged_in() ) return false;
    if ( current_user_can( 'manage_options' ) ) return true;
    $umpire = us_get_umpire_by_user( get_current_user_id() );
    if ( ! $umpire ) return false;
    return get_post_meta( $umpire->ID, 'us_is_assignor', true ) === '1';
}

// Save last login time on every login
add_action( 'wp_login', 'us_track_last_login', 10, 2 );
function us_track_last_login( $user_login, $user ) {
    update_user_meta( $user->ID, 'us_last_login', current_time( 'mysql' ) );
}

// ── Last login column ─────────────────────────────────────────

add_filter( 'manage_users_columns', 'us_add_last_login_column' );
function us_add_last_login_column( $columns ) {
    $columns['us_last_login'] = 'Last Login';
    return $columns;
}

add_filter( 'manage_users_custom_column', 'us_show_last_login_column', 10, 3 );
function us_show_last_login_column( $value, $column_name, $user_id ) {
    if ( $column_name !== 'us_last_login' ) return $value;

    $last_login = get_user_meta( $user_id, 'us_last_login', true );
    if ( ! $last_login ) return '—';

    $timestamp = strtotime( $last_login );
    return sprintf(
        '<span title="%s">%s ago</span>',
        esc_attr( date( 'Y-m-d H:i:s', $timestamp ) ),
        human_time_diff( $timestamp, current_time( 'timestamp' ) )
    );
}

add_filter( 'manage_users_sortable_columns', 'us_sortable_last_login_column' );
function us_sortable_last_login_column( $columns ) {
    $columns['us_last_login'] = 'us_last_login';
    return $columns;
}

// ── Doubleheader grouping ─────────────────────────────────────

// Groups an array of WP_Post game objects into singles and DH pairs.
// Two games are paired when they share the same date, non-empty field,
// same teams (order-independent, suffix-normalized), and start within 2 hours.
// Returns array of [ 'type' => 'single'|'doubleheader', 'game'|'games' => ... ]
function us_group_doubleheaders( $games ) {
    $gap = 2 * 60 * 60;

    $normalize = function( $name ) {
        $pos = strpos( $name, ' - ' );
        return strtolower( trim( $pos !== false ? substr( $name, 0, $pos ) : $name ) );
    };

    $items = [];
    foreach ( $games as $game ) {
        $items[] = [
            'game'  => $game,
            'date'  => get_post_meta( $game->ID, 'us_game_date', true ),
            'time'  => get_post_meta( $game->ID, 'us_game_time', true ),
            'home'  => get_post_meta( $game->ID, 'us_home_team', true ),
            'away'  => get_post_meta( $game->ID, 'us_away_team', true ),
            'field' => get_post_meta( $game->ID, 'us_field',     true ),
        ];
    }

    $used    = [];
    $grouped = [];

    foreach ( $items as $i => $a ) {
        if ( isset( $used[ $i ] ) ) continue;

        $partner = null;

        foreach ( $items as $j => $b ) {
            if ( $j <= $i || isset( $used[ $j ] ) ) continue;
            if ( $a['date']  !== $b['date']  ) continue;
            if ( $a['field'] !== $b['field']  ) continue;
            if ( empty( $a['field'] ) )          continue;

            $teams_a = [ $normalize( $a['home'] ), $normalize( $a['away'] ) ];
            $teams_b = [ $normalize( $b['home'] ), $normalize( $b['away'] ) ];
            sort( $teams_a );
            sort( $teams_b );
            if ( $teams_a !== $teams_b ) continue;

            if ( $a['time'] && $b['time'] ) {
                if ( abs( strtotime( $b['time'] ) - strtotime( $a['time'] ) ) > $gap ) continue;
            }

            $partner = $j;
            break;
        }

        if ( $partner !== null ) {
            $t1 = $a['time']               ? strtotime( $a['time'] )               : 0;
            $t2 = $items[ $partner ]['time'] ? strtotime( $items[ $partner ]['time'] ) : 0;

            $grouped[] = [
                'type'  => 'doubleheader',
                'games' => $t1 <= $t2
                    ? [ $a['game'], $items[ $partner ]['game'] ]
                    : [ $items[ $partner ]['game'], $a['game'] ],
            ];
            $used[ $i ]       = true;
            $used[ $partner ] = true;
        } else {
            $grouped[] = [ 'type' => 'single', 'game' => $a['game'] ];
            $used[ $i ] = true;
        }
    }

    return $grouped;
}