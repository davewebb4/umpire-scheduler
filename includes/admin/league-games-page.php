<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── League games page ─────────────────────────────────────────
function us_league_games_page( $league ) {
    $pay_rate    = get_post_meta( $league->ID, 'us_pay_rate',    true );
    $dh_pay_rate = get_post_meta( $league->ID, 'us_dh_pay_rate', true );
    $today       = current_time( 'Y-m-d' );
    $filter_date = isset( $_GET['game_date'] ) ? sanitize_text_field( $_GET['game_date'] ) : '';

    $all_games = get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'meta_key'    => 'us_game_date',
        'orderby'     => 'meta_value',
        'order'       => 'ASC',
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_league_id', 'value' => $league->ID, 'compare' => '=' ],
        ],
    ] );

    $game_dates = [];
    foreach ( $all_games as $g ) {
        $d = get_post_meta( $g->ID, 'us_game_date', true );
        if ( $d ) $game_dates[ $d ] = $d;
    }
    ksort( $game_dates );

    if ( ! $filter_date && ! empty( $game_dates ) ) {
        foreach ( $game_dates as $d ) {
            if ( $d >= $today ) { $filter_date = $d; break; }
        }
        if ( ! $filter_date ) $filter_date = end( $game_dates );
    }

    $show_all  = ( $filter_date === 'all' );
    $per_page  = 100;
    $cur_page  = max( 1, absint( $_GET['paged'] ?? 1 ) );

    $meta_query = [
        [ 'key' => 'us_league_id', 'value' => $league->ID, 'compare' => '=' ],
    ];
    if ( $filter_date && ! $show_all ) {
        $meta_query[] = [ 'key' => 'us_game_date', 'value' => $filter_date, 'compare' => '=' ];
    }

    if ( $show_all ) {
        // Lightweight query — no assignment lookups needed for bulk view
        $total_games = count( $all_games );
        $total_pages = (int) ceil( $total_games / $per_page );
        $cur_page    = min( $cur_page, max( 1, $total_pages ) );
        $games = array_slice( $all_games, ( $cur_page - 1 ) * $per_page, $per_page );
        // Sort slice by date then time
        usort( $games, function( $a, $b ) {
            $da = get_post_meta( $a->ID, 'us_game_date', true );
            $db = get_post_meta( $b->ID, 'us_game_date', true );
            if ( $da !== $db ) return strcmp( $da, $db );
            return strcmp( get_post_meta( $a->ID, 'us_game_time', true ), get_post_meta( $b->ID, 'us_game_time', true ) );
        } );
    } else {
        $total_pages = 1;
        $games = get_posts( [
            'post_type'   => US_PT_GAME,
            'numberposts' => -1,
            'meta_key'    => 'us_game_time',
            'orderby'     => 'meta_value',
            'order'       => 'ASC',
            'post_status' => 'publish',
            'meta_query'  => $meta_query,
        ] );
    }

    $all_umpires  = us_get_active_umpires();
    $unavail_ids  = $filter_date ? us_get_unavailable_umpires( $filter_date ) : [];
    $assigned_ids = $filter_date ? us_get_assigned_umpires_on_date( $filter_date ) : [];

    $available_umpires = [];
    $busy_umpires      = [];
    $unavail_umpires   = [];
    foreach ( $all_umpires as $u ) {
        if ( in_array( $u->ID, $unavail_ids ) ) {
            $unavail_umpires[] = $u;
            continue;
        }
        if ( in_array( $u->ID, $assigned_ids ) ) {
            $busy_umpires[] = $u;
        } else {
            $available_umpires[] = $u;
        }
    }

    $is_past  = $filter_date && $filter_date < $today;
    $base_url = admin_url( 'admin.php?page=us-league-games-' . $league->ID . '&game_date=' );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( $league->post_title ); ?></h1>

        <div class="us-league-games-toolbar">
            <span class="us-league-games-toolbar__meta">
                <?php echo count( $all_games ); ?> total games
                <?php if ( $pay_rate )    echo ' &middot; $' . number_format( $pay_rate, 2 )    . ' standard'; ?>
                <?php if ( $dh_pay_rate ) echo ' &middot; $' . number_format( $dh_pay_rate, 2 ) . ' optional rate'; ?>
            </span>
            <a href="<?php echo admin_url( 'admin.php?page=us-ics-import' ); ?>" class="button">Import Schedule</a>
            <a href="<?php echo admin_url( 'post-new.php?post_type=' . US_PT_GAME ); ?>" class="button">Add Game</a>
        </div>

        <?php if ( empty( $game_dates ) ) : ?>
            <div class="notice notice-info inline">
                <p>No games yet. <a href="<?php echo admin_url( 'admin.php?page=us-ics-import' ); ?>">Import a schedule</a> to get started.</p>
            </div>
        <?php else : ?>

        <?php
        $dates_list  = array_values( $game_dates );
        $current_idx = array_search( $filter_date, $dates_list );
        $prev_date   = $current_idx > 0 ? $dates_list[ $current_idx - 1 ] : null;
        $next_date   = $current_idx < count( $dates_list ) - 1 ? $dates_list[ $current_idx + 1 ] : null;
        ?>
        <div class="us-league-games-date-nav">
            <?php if ( $prev_date ) : ?>
                <a href="<?php echo $base_url . $prev_date; ?>" class="button">&larr;</a>
            <?php else : ?>
                <button class="button" disabled>&larr;</button>
            <?php endif; ?>

            <select onchange="window.location='<?php echo $base_url; ?>'+this.value" class="us-league-games-date-select">
                <option value="all" <?php selected( $filter_date, 'all' ); ?>>
                    All games (<?php echo count( $all_games ); ?>)
                </option>
                <?php foreach ( $game_dates as $d ) :
                    $count = 0;
                    foreach ( $all_games as $g ) {
                        if ( get_post_meta( $g->ID, 'us_game_date', true ) === $d ) $count++;
                    }
                ?>
                    <option value="<?php echo $d; ?>" <?php selected( $filter_date, $d ); ?>>
                        <?php echo date( 'D, M j, Y', strtotime( $d ) ) . ' (' . $count . ' games)'; ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ( $next_date ) : ?>
                <a href="<?php echo $base_url . $next_date; ?>" class="button">&rarr;</a>
            <?php else : ?>
                <button class="button" disabled>&rarr;</button>
            <?php endif; ?>

            <?php if ( $is_past ) : ?>
                <span class="us-league-games-past-badge">Past date</span>
            <?php endif; ?>
        </div>

        <?php if ( empty( $games ) ) : ?>
            <p>No games on this date.</p>
        <?php else : ?>

        <!-- ── Bulk actions toolbar ─────────────────────────── -->
        <div id="us-bulk-toolbar" style="display:none;align-items:center;gap:10px;margin-bottom:12px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:8px 12px;">
            <span id="us-bulk-count" style="font-size:13px;color:#666;min-width:80px"></span>
            <select id="us-bulk-action" style="font-size:13px;">
                <option value="">— Bulk action —</option>
                <option value="apply">Apply optional rate</option>
                <option value="remove">Remove optional rate</option>
                <option value="delete" style="color:#b32d2e;">Delete games</option>
            </select>
            <button id="us-bulk-apply" class="button button-primary" style="font-size:13px;">Apply</button>
            <button id="us-bulk-cancel" class="button" style="font-size:13px;">Cancel</button>
        </div>

        <?php if ( $show_all ) :
            // Build umpire name map from already-fetched list
            $umpire_name_map = [];
            foreach ( $all_umpires as $u ) {
                $umpire_name_map[ $u->ID ] = $u->post_title;
            }

            // Bulk-fetch confirmed assignments for this page's games in one query
            $page_game_ids    = wp_list_pluck( $games, 'ID' );
            $page_assignments = get_posts( [
                'post_type'   => US_PT_ASSIGNMENT,
                'numberposts' => -1,
                'post_status' => 'publish',
                'meta_query'  => [
                    [ 'key' => 'us_game_id', 'value' => $page_game_ids,                    'compare' => 'IN' ],
                    [ 'key' => 'us_status',  'value' => [ 'confirmed', 'postponed-paid' ], 'compare' => 'IN' ],
                ],
            ] );
            $asn_by_game = [];
            foreach ( $page_assignments as $asn ) {
                $gid    = get_post_meta( $asn->ID, 'us_game_id',   true );
                $pos    = get_post_meta( $asn->ID, 'us_position',  true );
                $uid    = get_post_meta( $asn->ID, 'us_umpire_id', true );
                $status = get_post_meta( $asn->ID, 'us_status',    true );
                if ( $gid && $pos ) {
                    $asn_by_game[ $gid ][ $pos ] = [ 'uid' => $uid, 'status' => $status ];
                }
            }
        ?>

        <!-- ── Simplified all-games table ───────────────────── -->
        <table class="wp-list-table widefat fixed striped" id="us-games-table">
            <thead>
                <tr>
                    <th style="width:32px"><input type="checkbox" id="us-select-all" title="Select all"></th>
                    <th style="width:90px">Date</th>
                    <th style="width:80px">Time</th>
                    <th>Game</th>
                    <th style="width:130px">Field</th>
                    <th style="width:100px">Rate</th>
                    <th style="width:140px">Plate umpire</th>
                    <th style="width:140px">Base umpire</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $games as $game ) :
                    $g_date    = get_post_meta( $game->ID, 'us_game_date',    true );
                    $g_time    = get_post_meta( $game->ID, 'us_game_time',    true );
                    $home      = get_post_meta( $game->ID, 'us_home_team',    true );
                    $away      = get_post_meta( $game->ID, 'us_away_team',    true );
                    $field     = get_post_meta( $game->ID, 'us_field',        true );
                    $is_dh     = get_post_meta( $game->ID, 'us_double_header', true ) === '1';
                    $g_status        = get_post_meta( $game->ID, 'us_game_status', true );
                    $plate_asn       = $asn_by_game[ $game->ID ]['plate'] ?? null;
                    $base_asn        = $asn_by_game[ $game->ID ]['base']  ?? null;
                    $plate_uid       = $plate_asn['uid']    ?? 0;
                    $plate_paid      = ( $plate_asn['status'] ?? '' ) === 'postponed-paid';
                    $base_uid        = $base_asn['uid']     ?? 0;
                    $plate_name      = $plate_uid ? ( $umpire_name_map[ $plate_uid ] ?? get_the_title( $plate_uid ) ) : '';
                    $base_name       = $base_uid  ? ( $umpire_name_map[ $base_uid ]  ?? get_the_title( $base_uid ) )  : '';
                ?>
                <tr>
                    <td><input type="checkbox" class="us-game-checkbox" value="<?php echo $game->ID; ?>"></td>
                    <td style="font-size:12px"><?php echo $g_date ? esc_html( date( 'M j, Y', strtotime( $g_date ) ) ) : '—'; ?></td>
                    <td><?php echo $g_time ? esc_html( date( 'g:i a', strtotime( $g_time ) ) ) : '—'; ?></td>
                    <td><?php echo esc_html( $away . ' at ' . $home ); ?></td>
                    <td><?php echo esc_html( $field ); ?></td>
                    <td>
                        <?php if ( $is_dh ) : ?>
                            <span class="us-league-badge us-league-badge--dh">Optional rate</span>
                        <?php else : ?>
                            <span style="color:#999;font-size:12px">Standard</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:13px;">
                        <?php if ( $plate_name ) : ?>
                            <?php echo esc_html( $plate_name ); ?>
                            <?php if ( $plate_paid ) : ?>
                                <span style="display:inline-block;background:#fff3e0;color:#e65100;font-size:10px;font-weight:700;padding:1px 5px;border-radius:3px;margin-left:4px;letter-spacing:.3px;">Postponed · Paid</span>
                            <?php endif; ?>
                        <?php elseif ( $g_status === 'cancelled' ) : ?>
                            <span style="color:#b32d2e;font-size:12px;">Cancelled</span>
                        <?php elseif ( $g_status === 'postponed' ) : ?>
                            <span style="color:#e65100;font-size:12px;">Postponed</span>
                        <?php else : ?>
                            <span style="color:#999;font-size:12px;">Not assigned</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:13px;"><?php echo $base_name ? esc_html( $base_name ) : '<span style="color:#bbb;">—</span>'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ( $total_pages > 1 ) :
            $all_url = admin_url( 'admin.php?page=us-league-games-' . $league->ID . '&game_date=all&paged=' );
        ?>
        <div style="display:flex;align-items:center;gap:8px;margin-top:12px;">
            <?php if ( $cur_page > 1 ) : ?>
                <a href="<?php echo $all_url . ( $cur_page - 1 ); ?>" class="button">&larr; Prev</a>
            <?php else : ?>
                <button class="button" disabled>&larr; Prev</button>
            <?php endif; ?>
            <span style="font-size:13px;color:#666">
                Page <?php echo $cur_page; ?> of <?php echo $total_pages; ?>
                &nbsp;&middot;&nbsp;
                <?php echo (( $cur_page - 1 ) * $per_page + 1 ); ?>–<?php echo min( $cur_page * $per_page, count( $all_games ) ); ?>
                of <?php echo count( $all_games ); ?> games
            </span>
            <?php if ( $cur_page < $total_pages ) : ?>
                <a href="<?php echo $all_url . ( $cur_page + 1 ); ?>" class="button">Next &rarr;</a>
            <?php else : ?>
                <button class="button" disabled>Next &rarr;</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php else : ?>

        <!-- ── Full date-filtered table ──────────────────────── -->
        <table class="wp-list-table widefat fixed striped" id="us-games-table">
            <thead>
                <tr>
                    <th style="width:32px"><input type="checkbox" id="us-select-all" title="Select all"></th>
                    <th style="width:80px">Time</th>
                    <th>Game</th>
                    <th>Field</th>
                    <th style="width:220px">Plate umpire</th>
                    <th style="width:220px">Base umpire</th>
                    <th style="width:110px">Status</th>
                    <th style="width:80px">Pay</th>
                    <th style="width:180px">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $games as $game ) :
                    $time         = get_post_meta( $game->ID, 'us_game_time',     true );
                    $home         = get_post_meta( $game->ID, 'us_home_team',     true );
                    $away         = get_post_meta( $game->ID, 'us_away_team',     true );
                    $field        = get_post_meta( $game->ID, 'us_field',         true );
                    $two_umps     = get_post_meta( $game->ID, 'us_two_umpires',   true );
                    $is_dh        = get_post_meta( $game->ID, 'us_double_header', true ) === '1';
                    $is_postponed = us_is_game_postponed( $game->ID );
                    $game_pay     = us_get_game_pay_rate( $game->ID );

                    $plate = us_get_confirmed_assignment( $game->ID, 'plate' );
                    $base  = us_get_confirmed_assignment( $game->ID, 'base' );

                    $plate_requests = $plate ? [] : us_get_slot_requests( $game->ID, 'plate' );
                    $base_requests  = ( $base || $two_umps !== '1' ) ? [] : us_get_slot_requests( $game->ID, 'base' );

                    $plate_umpire_id = $plate ? get_post_meta( $plate->ID, 'us_umpire_id', true ) : 0;
                    $base_umpire_id  = $base  ? get_post_meta( $base->ID,  'us_umpire_id', true ) : 0;
                    $plate_status    = $plate ? get_post_meta( $plate->ID, 'us_status',    true ) : '';
                    $base_status     = $base  ? get_post_meta( $base->ID,  'us_status',    true ) : '';
                    $needs_base      = $two_umps === '1';

                    if ( $is_postponed ) {
                        $status_html = '<span class="us-league-status--postponed">Postponed</span>';
                    } elseif ( $plate_status === 'confirmed' && ( ! $needs_base || $base_status === 'confirmed' ) ) {
                        $status_html = '<span class="us-admin-status us-admin-status--confirmed">Confirmed</span>';
                    } elseif ( $plate_status === 'confirmed' && $needs_base && $base_status !== 'confirmed' ) {
                        $base_req_count = count( $base_requests );
                        $status_html    = '<span class="us-admin-status us-admin-status--confirmed">Partial</span>';
                        if ( $base_req_count > 0 ) {
                            $status_html .= '<br><a href="' . admin_url( 'admin.php?page=us-requests' ) . '" class="us-league-requests-link">' . $base_req_count . ' request' . ( $base_req_count > 1 ? 's' : '' ) . '</a>';
                        }
                    } elseif ( ! $plate && ! $base && empty( $plate_requests ) && empty( $base_requests ) ) {
                        $status_html = '<span class="us-admin-status us-admin-status--open">Open</span>';
                    } else {
                        $total_requests = count( $plate_requests ) + count( $base_requests );
                        $status_html    = '<span class="us-admin-status us-admin-status--pending">Requested</span>';
                        if ( $total_requests > 0 ) {
                            $status_html .= '<br><a href="' . admin_url( 'admin.php?page=us-requests' ) . '" class="us-league-requests-link">' . $total_requests . ' request' . ( $total_requests > 1 ? 's' : '' ) . '</a>';
                        }
                    }
                ?>
                <tr <?php echo $is_postponed ? 'class="us-league-row--postponed"' : ''; ?>>
                    <td><input type="checkbox" class="us-game-checkbox" value="<?php echo $game->ID; ?>"></td>
                    <td><?php echo $time ? esc_html( date( 'g:i a', strtotime( $time ) ) ) : '—'; ?></td>
                    <td>
                        <?php echo esc_html( $away . ' at ' . $home ); ?>
                        <?php if ( $is_postponed ) : ?><span class="us-league-badge us-league-badge--postponed">Postponed</span><?php endif; ?>
                        <?php if ( $two_umps === '1' ) : ?><span class="us-league-badge us-league-badge--two-umps">2 umps</span><?php endif; ?>
                        <?php if ( $is_dh ) : ?><span class="us-league-badge us-league-badge--dh">DH</span><?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $field ); ?></td>
                    <td><?php echo $is_postponed ? '<span class="us-admin-na">—</span>' : us_assignment_dropdown( $game->ID, 'plate', $plate_umpire_id, $plate, $plate_requests, $available_umpires, $busy_umpires, $is_past, $unavail_umpires ); ?></td>
                    <td>
                        <?php if ( $is_postponed ) : ?>
                            <span class="us-admin-na">—</span>
                        <?php elseif ( $two_umps === '1' ) : ?>
                            <?php echo us_assignment_dropdown( $game->ID, 'base', $base_umpire_id, $base, $base_requests, $available_umpires, $busy_umpires, $is_past, $unavail_umpires ); ?>
                        <?php else : ?>
                            <span class="us-admin-na">Not enabled <a href="<?php echo get_edit_post_link( $game->ID ); ?>" class="us-league-enable-link">Enable</a></span>
                        <?php endif; ?>
                    </td>
                    <td class="us-status-cell"><?php echo $status_html; ?></td>
                    <td>
                        <?php if ( $is_postponed ) : ?>
                            <span class="us-admin-na">—</span>
                        <?php elseif ( $game_pay ) : ?>
                            $<?php echo number_format( floatval( $game_pay ), 2 ); ?>
                            <?php if ( $is_dh ) : ?><span class="us-league-dh-rate">Optional rate</span><?php endif; ?>
                        <?php else : ?>
                            <span class="us-admin-na">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="us-league-actions">
                            <?php if ( ! $is_postponed ) : ?>
                            <button class="us-edit-game-btn button button-small"
                                    data-game="<?php echo $game->ID; ?>"
                                    data-date="<?php echo esc_attr( get_post_meta( $game->ID, 'us_game_date', true ) ); ?>"
                                    data-time="<?php echo esc_attr( get_post_meta( $game->ID, 'us_game_time', true ) ); ?>"
                                    data-field="<?php echo esc_attr( get_post_meta( $game->ID, 'us_field', true ) ); ?>"
                                    data-dh="<?php echo esc_attr( get_post_meta( $game->ID, 'us_double_header', true ) ); ?>">Edit</button>
                            <a href="<?php echo esc_url( get_edit_post_link( $game->ID ) ); ?>" class="button button-small" title="Edit all game settings">&#9881;</a>
                            <button class="us-postpone-game-btn button button-small"
                                    data-game="<?php echo $game->ID; ?>"
                                    data-label="<?php echo esc_attr( $away . ' at ' . $home ); ?>">Postpone</button>
                            <?php else : ?>
                            <button class="us-unpostpone-game-btn button button-small"
                                    data-game="<?php echo $game->ID; ?>"
                                    data-label="<?php echo esc_attr( $away . ' at ' . $home ); ?>">Unpostpone</button>
                            <?php endif; ?>
                            <button class="us-delete-game-btn button button-small" data-game="<?php echo $game->ID; ?>">Delete</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="us-league-games-footer"><?php echo count( $games ); ?> games on this date &middot; Assignments save automatically</p>

        <?php endif; // $show_all ?>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php us_postpone_modal(); ?>
    <?php
}

// ── Postpone modal ────────────────────────────────────────────
function us_postpone_modal() {
    ?>
    <div id="us-postpone-modal" class="us-postpone-modal">
        <div class="us-postpone-modal__inner">
            <h3 class="us-postpone-modal__title">Mark game as postponed</h3>
            <p id="us-postpone-game-label" class="us-postpone-modal__game"></p>
            <label class="us-postpone-modal__pay-label">
                <input type="checkbox" id="us-postpone-pay-check">
                <span>Pay assigned umpires — they arrived on site before the game was called off</span>
            </label>
            <p class="us-postpone-modal__note">Umpires will be notified by email. This cannot be undone — create a new game if rescheduling.</p>
            <div class="us-postpone-modal__actions">
                <button id="us-postpone-confirm-btn" class="button button-primary us-postpone-modal__confirm">Confirm postponement</button>
                <button id="us-postpone-cancel-btn" class="button">Cancel</button>
            </div>
        </div>
    </div>

    <style>
        .us-league-games-toolbar        { display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap; }
        .us-league-games-toolbar__meta  { color:#666;flex:1; }
        .us-league-games-date-nav       { display:flex;align-items:center;gap:8px;margin-bottom:20px;flex-wrap:wrap; }
        .us-league-games-date-select    { font-size:14px;font-weight:500;padding:4px 8px; }
        .us-league-games-past-badge     { background:#fff3cd;color:#856404;padding:4px 10px;border-radius:4px;font-size:12px; }
        .us-league-games-footer         { margin-top:12px;color:#666;font-size:12px; }
        .us-league-row--postponed       { opacity:0.6; }
        .us-league-badge                { font-size:11px;padding:1px 6px;border-radius:3px;margin-left:4px; }
        .us-league-badge--postponed     { background:#f0f0f0;color:#666; }
        .us-league-badge--two-umps      { background:#e8f0f8;color:#0c447c; }
        .us-league-badge--dh            { background:#fef3c7;color:#92400e; }
        .us-league-status--postponed    { background:#f0f0f0;color:#666;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:500; }
        .us-league-requests-link        { font-size:11px;color:#dba617; }
        .us-league-enable-link          { margin-left:4px;font-size:11px; }
        .us-league-dh-rate              { font-size:10px;color:#92400e;display:block; }
        .us-league-actions              { display:flex;gap:4px;align-items:center;flex-wrap:wrap; }

        .us-assignment-dropdown         { display:flex;flex-direction:column;gap:4px; }
        .us-assignment-dropdown__status { display:flex;gap:4px;align-items:center;flex-wrap:wrap; }
        .us-assignment-dropdown__requests { font-size:11px;color:#dba617;font-weight:500;text-decoration:none;border:1px solid #dba617;padding:2px 6px;border-radius:3px;white-space:nowrap; }
        .us-noshow-btn                  { font-size:11px !important;color:#d63638 !important;border-color:#d63638 !important; }

        .us-postpone-modal              { display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center; }
        .us-postpone-modal__inner       { background:#fff;border-radius:8px;padding:28px;max-width:420px;width:90%;box-shadow:0 4px 24px rgba(0,0,0,0.2); }
        .us-postpone-modal__title       { margin:0 0 6px;color:#091b33; }
        .us-postpone-modal__game        { margin:0 0 20px;color:#666;font-size:14px; }
        .us-postpone-modal__pay-label   { display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin-bottom:20px;font-size:14px;color:#444;line-height:1.5; }
        .us-postpone-modal__pay-label input { margin-top:3px;width:15px;height:15px; }
        .us-postpone-modal__note        { font-size:12px;color:#999;margin:0 0 20px; }
        .us-postpone-modal__actions     { display:flex;gap:8px; }
        .us-postpone-modal__confirm     { background:#f59e0b !important;color:#fff !important;border-color:#f59e0b !important; }
    </style>

    <script>
    jQuery(function($){

        // ── Bulk selection ────────────────────────────────────
        function updateBulkToolbar() {
            var checked = $('.us-game-checkbox:checked').length;
            if ( checked > 0 ) {
                $('#us-bulk-toolbar').css('display','flex');
                $('#us-bulk-count').text( checked + ' game' + (checked !== 1 ? 's' : '') + ' selected' );
            } else {
                $('#us-bulk-toolbar').hide();
                $('#us-bulk-action').val('');
            }
        }

        $('#us-select-all').on('change', function() {
            $('.us-game-checkbox').prop('checked', this.checked);
            updateBulkToolbar();
        });

        $(document).on('change', '.us-game-checkbox', function() {
            var total   = $('.us-game-checkbox').length;
            var checked = $('.us-game-checkbox:checked').length;
            $('#us-select-all').prop('checked', total === checked).prop('indeterminate', checked > 0 && checked < total);
            updateBulkToolbar();
        });

        $('#us-bulk-cancel').on('click', function() {
            $('.us-game-checkbox, #us-select-all').prop('checked', false);
            $('#us-bulk-toolbar').hide();
        });

        $('#us-bulk-apply').on('click', function() {
            var action = $('#us-bulk-action').val();
            if ( ! action ) { alert('Please select a bulk action.'); return; }
            var ids = $('.us-game-checkbox:checked').map(function(){ return $(this).val(); }).get();
            if ( ! ids.length ) return;

            var $btn = $(this);

            if ( action === 'delete' ) {
                if ( ! confirm( 'Permanently delete ' + ids.length + ' game(s) and all their assignments? This cannot be undone.' ) ) return;
                $btn.prop('disabled', true).text('Deleting...');
                $.post(ajaxurl, {
                    action: 'us_bulk_delete_games',
                    nonce: usAssign.nonce,
                    game_ids: ids,
                }, function(res) {
                    if ( res.success ) {
                        location.reload();
                    } else {
                        alert('Error — could not delete games.');
                        $btn.prop('disabled', false).text('Apply');
                    }
                });
                return;
            }

            var label = action === 'apply' ? 'Apply optional rate' : 'Remove optional rate';
            if ( ! confirm( label + ' for ' + ids.length + ' game(s)?' ) ) return;

            $btn.prop('disabled', true).text('Saving...');

            $.post(ajaxurl, {
                action: 'us_bulk_optional_rate',
                nonce: usAssign.nonce,
                game_ids: ids,
                bulk_action: action
            }, function(res) {
                if ( res.success ) {
                    location.reload();
                } else {
                    alert('Error — could not apply bulk action.');
                    $btn.prop('disabled', false).text('Apply');
                }
            });
        });

        var postponeGameId = 0;
        $(document).on('click', '.us-postpone-game-btn', function(){
            postponeGameId = $(this).data('game');
            $('#us-postpone-game-label').text( $(this).data('label') );
            $('#us-postpone-pay-check').prop('checked', false);
            $('#us-postpone-modal').css('display','flex');
        });
        $('#us-postpone-cancel-btn').on('click', function(){ $('#us-postpone-modal').hide(); });
        $('#us-postpone-modal').on('click', function(e){ if(e.target===this) $(this).hide(); });
        $('#us-postpone-confirm-btn').on('click', function(){
            var $btn = $(this);
            $btn.prop('disabled',true).text('Saving...');
            $.post(ajaxurl,{
                action:'us_postpone_game',nonce:usAssign.nonce,
                game_id:postponeGameId,pay_umpires:$('#us-postpone-pay-check').is(':checked')?'1':'0'
            },function(res){
                if(res.success){ $('#us-postpone-modal').hide(); location.reload(); }
                else { alert('Error: '+(res.data||'Could not postpone game.')); $btn.prop('disabled',false).text('Confirm postponement'); }
            });
        });

        $(document).on('click', '.us-unpostpone-game-btn', function(){
            var gameId = $(this).data('game');
            var label  = $(this).data('label');
            if ( ! confirm( 'Restore "' + label + '" to active? Any postponed-paid assignments will be set back to confirmed.' ) ) return;
            var $btn = $(this);
            $btn.prop('disabled', true).text('Saving...');
            $.post(ajaxurl, {
                action: 'us_unpostpone_game', nonce: usAssign.nonce, game_id: gameId
            }, function(res) {
                if ( res.success ) { location.reload(); }
                else { alert('Error: ' + (res.data || 'Could not restore game.')); $btn.prop('disabled', false).text('Unpostpone'); }
            });
        });
    });
    </script>
    <?php
}

// ── Bulk optional rate AJAX ───────────────────────────────────
add_action( 'wp_ajax_us_bulk_optional_rate', 'us_ajax_bulk_optional_rate' );
function us_ajax_bulk_optional_rate() {
    check_ajax_referer( 'us_assign_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $game_ids    = array_map( 'absint', $_POST['game_ids']    ?? [] );
    $bulk_action = sanitize_text_field( $_POST['bulk_action'] ?? '' );

    if ( empty( $game_ids ) || ! in_array( $bulk_action, [ 'apply', 'remove' ] ) ) {
        wp_send_json_error( 'Invalid' );
    }

    $dh_value = $bulk_action === 'apply' ? '1' : '0';
    $updated  = 0;

    foreach ( $game_ids as $game_id ) {
        update_post_meta( $game_id, 'us_double_header', $dh_value );

        // Re-stamp assignment pay rates
        $league_id = get_post_meta( $game_id, 'us_league_id', true );
        $rate      = $bulk_action === 'apply'
            ? get_post_meta( $league_id, 'us_dh_pay_rate', true )
            : get_post_meta( $league_id, 'us_pay_rate',    true );

        if ( $rate ) {
            $assignments = get_posts( [
                'post_type'   => US_PT_ASSIGNMENT,
                'numberposts' => -1,
                'post_status' => 'publish',
                'meta_query'  => [ [ 'key' => 'us_game_id', 'value' => $game_id, 'compare' => '=' ] ],
            ] );
            foreach ( $assignments as $a ) {
                update_post_meta( $a->ID, 'us_pay_amount', $rate );
            }
        }

        $updated++;
    }

    wp_send_json_success( [ 'updated' => $updated ] );
}

// ── Bulk delete games AJAX ────────────────────────────────────
add_action( 'wp_ajax_us_bulk_delete_games', 'us_ajax_bulk_delete_games' );
function us_ajax_bulk_delete_games() {
    check_ajax_referer( 'us_assign_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $game_ids = array_map( 'absint', $_POST['game_ids'] ?? [] );
    if ( empty( $game_ids ) ) wp_send_json_error( 'No games specified' );

    foreach ( $game_ids as $game_id ) {
        // Delete all assignments for this game first
        $assignments = get_posts( [
            'post_type'   => US_PT_ASSIGNMENT,
            'numberposts' => -1,
            'post_status' => 'any',
            'fields'      => 'ids',
            'meta_query'  => [ [ 'key' => 'us_game_id', 'value' => $game_id, 'compare' => '=' ] ],
        ] );
        foreach ( $assignments as $a_id ) {
            wp_delete_post( $a_id, true );
        }
        wp_delete_post( $game_id, true );
    }

    wp_send_json_success( [ 'deleted' => count( $game_ids ) ] );
}

// ── Assignment dropdown ───────────────────────────────────────
function us_assignment_dropdown( $game_id, $position, $selected_umpire_id, $assignment, $slot_requests, $available, $busy, $is_past, $unavail = [] ) {
    $assignment_id = $assignment ? $assignment->ID : 0;
    $status        = $assignment ? get_post_meta( $assignment->ID, 'us_status', true ) : '';
    $request_count = count( $slot_requests );
    $requests_url  = admin_url( 'admin.php?page=us-requests' );

    ob_start();
    ?>
    <div class="us-assignment-dropdown">
        <select class="us-assign-select"
                data-game="<?php echo $game_id; ?>"
                data-position="<?php echo $position; ?>"
                data-assignment="<?php echo $assignment_id; ?>"
                style="max-width:160px;font-size:12px">
            <option value="">— unassigned —</option>
            <?php if ( ! empty( $available ) ) : ?>
                <optgroup label="Available">
                    <?php foreach ( $available as $u ) : ?>
                        <option value="<?php echo $u->ID; ?>" <?php selected( $selected_umpire_id, $u->ID ); ?>><?php echo esc_html( $u->post_title ); ?></option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endif; ?>
            <?php if ( ! empty( $busy ) ) : ?>
                <optgroup label="Already assigned today">
                    <?php foreach ( $busy as $u ) : ?>
                        <option value="<?php echo $u->ID; ?>" <?php selected( $selected_umpire_id, $u->ID ); ?> style="color:#999"><?php echo esc_html( $u->post_title ); ?></option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endif; ?>
            <?php if ( ! empty( $unavail ) ) : ?>
                <optgroup label="&#9888; Marked unavailable (admin override)">
                    <?php foreach ( $unavail as $u ) : ?>
                        <option value="<?php echo $u->ID; ?>" <?php selected( $selected_umpire_id, $u->ID ); ?> style="color:#b45309"><?php echo esc_html( $u->post_title ); ?> — unavailable</option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endif; ?>
        </select>

        <div class="us-assignment-dropdown__status">
            <?php if ( $status === 'confirmed' ) : ?>
                <span class="us-admin-status us-admin-status--confirmed">&#10003; Confirmed</span>
                <button class="us-noshow-btn button button-small" data-assignment="<?php echo $assignment_id; ?>">No-show</button>
            <?php elseif ( $status === 'no-show' ) : ?>
                <span class="us-admin-status us-admin-status--open">No-show</span>
            <?php elseif ( $status === 'postponed-paid' ) : ?>
                <span class="us-admin-status">Postponed — paid</span>
            <?php elseif ( $request_count > 0 && ! $is_past ) : ?>
                <?php
                $names = [];
                foreach ( $slot_requests as $r ) {
                    $uid = get_post_meta( $r->ID, 'us_umpire_id', true );
                    if ( $uid ) $names[] = get_the_title( $uid );
                }
                ?>
                <a href="<?php echo esc_url( $requests_url ); ?>"
                   title="<?php echo esc_attr( implode( ', ', $names ) ); ?>"
                   class="us-assignment-dropdown__requests">
                    &#9679; <?php echo $request_count; ?> request<?php echo $request_count > 1 ? 's' : ''; ?> &rarr;
                </a>
            <?php elseif ( ! $assignment_id && ! $is_past ) : ?>
                <span class="us-admin-na">No requests yet</span>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}