<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Combined import page ──────────────────────────────────────
function us_import_page() {
    $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'ics';
    $ics_url    = admin_url( 'admin.php?page=us-ics-import&tab=ics' );
    $csv_url    = admin_url( 'admin.php?page=us-ics-import&tab=csv' );
    ?>
    <div class="wrap">
        <h1>Import Schedule</h1>

        <nav class="nav-tab-wrapper us-import-tabs">
            <a href="<?php echo $ics_url; ?>"
               class="nav-tab <?php echo $active_tab === 'ics' ? 'nav-tab-active' : ''; ?>">
                ICS Calendar
            </a>
            <a href="<?php echo $csv_url; ?>"
               class="nav-tab <?php echo $active_tab === 'csv' ? 'nav-tab-active' : ''; ?>">
                CSV File
            </a>
        </nav>

        <?php if ( $active_tab === 'ics' ) : ?>
            <?php us_ics_import_tab(); ?>
        <?php else : ?>
            <?php us_csv_import_tab(); ?>
        <?php endif; ?>

    </div>
    <?php
}

// ── ICS import tab ────────────────────────────────────────────
function us_ics_import_tab() {
    $leagues = get_posts( [
        'post_type'   => US_PT_LEAGUE,
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'post_status' => 'publish',
    ] );

    if ( empty( $leagues ) ) {
        echo '<div class="notice notice-warning inline"><p>You need to <a href="' . admin_url( 'post-new.php?post_type=' . US_PT_LEAGUE ) . '">create at least one league</a> before importing.</p></div>';
        return;
    }

    if ( isset( $_POST['us_ics_submit'] ) ) {
        us_handle_ics_import();
    }

    // ── League schedule reset ─────────────────────────────────
    if ( isset( $_POST['us_reset_submit'] ) && check_admin_referer( 'us_reset' ) ) {
        $reset_league_id   = absint( $_POST['us_reset_league'] );
        $reset_league_name = get_the_title( $reset_league_id );
        $deleted_games     = 0;
        $deleted_assigns   = 0;

        $all_games = get_posts( [
            'post_type'   => US_PT_GAME,
            'numberposts' => -1,
            'post_status' => 'publish',
            'fields'      => 'ids',
            'meta_query'  => [
                [ 'key' => 'us_league_id', 'value' => $reset_league_id, 'compare' => '=' ],
            ],
        ] );

        foreach ( $all_games as $game_id ) {
            // Delete all assignments for this game first
            $assignments = get_posts( [
                'post_type'   => US_PT_ASSIGNMENT,
                'numberposts' => -1,
                'post_status' => 'publish',
                'fields'      => 'ids',
                'meta_query'  => [
                    [ 'key' => 'us_game_id', 'value' => $game_id, 'compare' => '=' ],
                ],
            ] );
            foreach ( $assignments as $assign_id ) {
                wp_delete_post( $assign_id, true );
                $deleted_assigns++;
            }

            wp_delete_post( $game_id, true );
            $deleted_games++;
        }

        echo '<div class="notice notice-success inline"><p>';
        echo "<strong>" . esc_html( $reset_league_name ) . " schedule cleared.</strong> ";
        echo "{$deleted_games} game(s) deleted";
        if ( $deleted_assigns ) echo ", {$deleted_assigns} assignment(s) deleted";
        echo '.</p></div>';
    }

    // ── Duplicate cleanup ─────────────────────────────────────
    if ( isset( $_POST['us_cleanup_submit'] ) && check_admin_referer( 'us_cleanup' ) ) {
        $cleanup_league_id = absint( $_POST['us_cleanup_league'] );
        $deleted           = 0;
        $kept              = 0;

        $all_games = get_posts( [
            'post_type'   => US_PT_GAME,
            'numberposts' => -1,
            'post_status' => 'publish',
            'meta_query'  => [
                [ 'key' => 'us_league_id', 'value' => $cleanup_league_id, 'compare' => '=' ],
            ],
        ] );

        $seen = [];
        foreach ( $all_games as $game ) {
            $date = get_post_meta( $game->ID, 'us_game_date', true );
            $home = get_post_meta( $game->ID, 'us_home_team', true );
            $away = get_post_meta( $game->ID, 'us_away_team', true );
            $key  = $date . '|' . $home . '|' . $away;

            $has_assignments = get_posts( [
                'post_type'   => US_PT_ASSIGNMENT,
                'numberposts' => 1,
                'post_status' => 'publish',
                'fields'      => 'ids',
                'meta_query'  => [
                    [ 'key' => 'us_game_id', 'value' => $game->ID, 'compare' => '=' ],
                ],
            ] );

            if ( isset( $seen[ $key ] ) ) {
                if ( ! $has_assignments ) {
                    wp_delete_post( $game->ID, true );
                    $deleted++;
                } else {
                    $kept++;
                }
            } else {
                $seen[ $key ] = $game->ID;
            }
        }

        echo '<div class="notice notice-success inline"><p>';
        echo "<strong>{$deleted} duplicate game(s) deleted.</strong>";
        if ( $kept ) echo " {$kept} duplicate(s) kept because they have assignments.";
        echo '</p></div>';
    }
    ?>

    <!-- ── Duplicate cleanup tool ──────────────────────────── -->
    <div class="us-import-cleanup-box">
        <h3 class="us-import-cleanup-box__title">Remove duplicate games</h3>
        <p class="us-import-cleanup-box__desc">Removes duplicate games with no assignments. Games with assignments are never deleted.</p>
        <form method="post" class="us-import-cleanup-box__form">
            <?php wp_nonce_field( 'us_cleanup' ); ?>
            <select name="us_cleanup_league">
                <?php foreach ( $leagues as $l ) : ?>
                    <option value="<?php echo $l->ID; ?>"><?php echo esc_html( $l->post_title ); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="submit" name="us_cleanup_submit" class="button"
                   value="Remove duplicates"
                   onclick="return confirm('This will permanently delete duplicate games with no assignments. Continue?')">
        </form>
    </div>

    <!-- ── League schedule reset ───────────────────────────── -->
    <div class="us-import-cleanup-box us-import-reset-box">
        <h3 class="us-import-cleanup-box__title">Reset league schedule</h3>
        <p class="us-import-cleanup-box__desc">Permanently deletes <strong>all games and assignments</strong> for the selected league. Use this to wipe a schedule clean before re-importing.</p>
        <form method="post" class="us-import-cleanup-box__form">
            <?php wp_nonce_field( 'us_reset' ); ?>
            <select name="us_reset_league">
                <?php foreach ( $leagues as $l ) : ?>
                    <option value="<?php echo $l->ID; ?>"><?php echo esc_html( $l->post_title ); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="submit" name="us_reset_submit" class="button button-link-delete"
                   value="Reset schedule"
                   onclick="return confirm('This will permanently delete ALL games and assignments for this league. This cannot be undone. Continue?')">
        </form>
    </div>

    <!-- ── ICS import form ─────────────────────────────────── -->
    <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field( 'us_ics_import', 'us_ics_nonce' ); ?>
        <table class="form-table">
            <tr>
                <th><label for="us_import_league">League</label></th>
                <td>
                    <select name="us_import_league" id="us_import_league" required>
                        <option value="">— select league —</option>
                        <?php foreach ( $leagues as $l ) : ?>
                            <option value="<?php echo $l->ID; ?>"><?php echo esc_html( $l->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="us_ics_file">ICS file</label></th>
                <td>
                    <input type="file" name="us_ics_file" id="us_ics_file" accept=".ics" required />
                    <p class="description">Upload the .ics file exported from your league scheduling software.</p>
                </td>
            </tr>
            <tr>
                <th><label for="us_timezone">League timezone</label></th>
                <td>
                    <select name="us_timezone" id="us_timezone">
                        <?php
                        $saved_tz  = us_setting( 'timezone' );
                        $timezones = [
                            'Canada' => [
                                'America/Vancouver' => 'Pacific — Vancouver / Victoria',
                                'America/Edmonton'  => 'Mountain — Edmonton / Calgary',
                                'America/Winnipeg'  => 'Central — Winnipeg',
                                'America/Toronto'   => 'Eastern — Toronto / Ottawa',
                                'America/Halifax'   => 'Atlantic — Halifax',
                                'America/St_Johns'  => 'Newfoundland — St. Johns',
                            ],
                            'United States' => [
                                'America/Los_Angeles' => 'Pacific — Los Angeles',
                                'America/Denver'      => 'Mountain — Denver',
                                'America/Chicago'     => 'Central — Chicago',
                                'America/New_York'    => 'Eastern — New York',
                                'America/Phoenix'     => 'Arizona — Phoenix',
                                'Pacific/Honolulu'    => 'Hawaii — Honolulu',
                            ],
                            'Australia' => [
                                'Australia/Perth'    => 'Western — Perth',
                                'Australia/Adelaide' => 'Central — Adelaide',
                                'Australia/Sydney'   => 'Eastern — Sydney',
                            ],
                            'United Kingdom' => [
                                'Europe/London' => 'London',
                            ],
                            'Europe' => [
                                'Europe/Paris'    => 'Central European — Paris / Berlin',
                                'Europe/Helsinki' => 'Eastern European — Helsinki',
                            ],
                            'New Zealand' => [
                                'Pacific/Auckland' => 'Auckland',
                            ],
                        ];
                        foreach ( $timezones as $region => $zones ) : ?>
                            <optgroup label="<?php echo esc_attr( $region ); ?>">
                                <?php foreach ( $zones as $tz => $label ) : ?>
                                    <option value="<?php echo $tz; ?>" <?php selected( $saved_tz, $tz ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Times in the ICS file are UTC — this converts them to local game time. Defaults to your <a href="<?php echo admin_url('admin.php?page=us-settings'); ?>">timezone setting</a>.</p>
                </td>
            </tr>
        </table>
        <p class="submit">
            <input type="submit" name="us_ics_submit" class="button button-primary" value="Import ICS" />
        </p>
    </form>

    <style>
        .us-import-tabs { margin-bottom: 20px; }

        .us-import-cleanup-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 16px;
            border-radius: 4px;
            margin-bottom: 24px;
        }
        .us-import-cleanup-box__title { margin: 0 0 8px; font-size: 14px; }
        .us-import-cleanup-box__desc  { margin-bottom: 12px; color: #666; font-size: 13px; }
        .us-import-cleanup-box__form  { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

        .us-import-reset-box {
            background: #fde8e8;
            border-color: #d63638;
        }
    </style>
    <?php
}

// ── Handle ICS import (reconcile mode) ───────────────────────
function us_handle_ics_import() {
    if ( ! isset( $_POST['us_ics_nonce'] ) || ! wp_verify_nonce( $_POST['us_ics_nonce'], 'us_ics_import' ) ) {
        echo '<div class="notice notice-error inline"><p>Security check failed.</p></div>';
        return;
    }

    if ( empty( $_FILES['us_ics_file']['tmp_name'] ) ) {
        echo '<div class="notice notice-error inline"><p>No file uploaded.</p></div>';
        return;
    }

    $league_id = absint( $_POST['us_import_league'] );
    $timezone  = sanitize_text_field( $_POST['us_timezone'] );
    $content   = file_get_contents( $_FILES['us_ics_file']['tmp_name'] );

    if ( ! $content ) {
        echo '<div class="notice notice-error inline"><p>Could not read the ICS file.</p></div>';
        return;
    }

    // ── Step 1: Parse ICS into lookup indexes ─────────────────
    // Two indexes so we can match both UID-originated games and
    // CSV-originated games (which have no stored UID).
    $events    = us_parse_ics( $content );
    $tz        = new DateTimeZone( $timezone );

    $ics_by_uid   = [];   // uid => parsed event data
    $ics_by_teams = [];   // "Y-m-d|home|away" => parsed event data

    $not_games = 0;

    foreach ( $events as $event ) {
        if ( strpos( $event['summary'], 'GAME' ) === false ) {
            $not_games++;
            continue;
        }

        $teams = us_parse_teams( $event['summary'] );
        if ( ! $teams ) continue;

        try {
            $dt = new DateTime( $event['dtstart'], new DateTimeZone( 'UTC' ) );
            $dt->setTimezone( $tz );
        } catch ( Exception $e ) {
            continue;
        }

        $parsed = [
            'game_date' => $dt->format( 'Y-m-d' ),
            'game_time' => $dt->format( 'H:i' ),
            'home'      => $teams['home'],
            'away'      => $teams['away'],
            'field'     => trim( $event['location'] ?? '' ),
            'uid'       => sanitize_text_field( $event['uid'] ?? '' ),
            'title'     => $teams['away'] . ' at ' . $teams['home'],
        ];

        $team_key = $parsed['game_date'] . '|' . $parsed['home'] . '|' . $parsed['away'];

        if ( $parsed['uid'] ) {
            $ics_by_uid[ $parsed['uid'] ] = $parsed;
        }
        $ics_by_teams[ $team_key ] = $parsed;
    }

    // ── Step 2: Load all existing games for this league ───────
    $all_games = get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_league_id', 'value' => $league_id, 'compare' => '=' ],
        ],
    ] );

    $created      = 0;
    $updated      = 0;
    $deleted      = 0;
    $orphaned     = [];   // assigned games with no match in ICS
    $ics_consumed = [];   // tracks which ICS events were matched to existing games

    // ── Step 3: Reconcile each existing DB game ───────────────
    foreach ( $all_games as $game ) {
        $db_uid   = get_post_meta( $game->ID, 'us_ics_uid',   true );
        $db_date  = get_post_meta( $game->ID, 'us_game_date', true );
        $db_home  = get_post_meta( $game->ID, 'us_home_team', true );
        $db_away  = get_post_meta( $game->ID, 'us_away_team', true );

        // Check for assignments
        $has_assignments = ! empty( get_posts( [
            'post_type'   => US_PT_ASSIGNMENT,
            'numberposts' => 1,
            'post_status' => 'publish',
            'fields'      => 'ids',
            'meta_query'  => [
                [ 'key' => 'us_game_id', 'value' => $game->ID, 'compare' => '=' ],
            ],
        ] ) );

        // Find matching ICS event — UID first, then date+teams
        $ics_match = null;
        if ( $db_uid && isset( $ics_by_uid[ $db_uid ] ) ) {
            $ics_match = $ics_by_uid[ $db_uid ];
        }
        if ( ! $ics_match ) {
            $team_key = $db_date . '|' . $db_home . '|' . $db_away;
            if ( isset( $ics_by_teams[ $team_key ] ) ) {
                $ics_match = $ics_by_teams[ $team_key ];
            }
        }

        // No match found in ICS file
        if ( ! $ics_match ) {
            if ( $has_assignments ) {
                // Can't safely delete — flag for manual review
                $orphaned[] = [
                    'id'   => $game->ID,
                    'date' => $db_date,
                    'home' => $db_home,
                    'away' => $db_away,
                ];
            } else {
                // No assignments — safe to delete
                wp_delete_post( $game->ID, true );
                $deleted++;
            }
            continue;
        }

        // Mark this ICS event as consumed so Step 4 skips it
        $consumed_key = $ics_match['game_date'] . '|' . $ics_match['home'] . '|' . $ics_match['away'];
        $ics_consumed[ $consumed_key ] = true;
        if ( $ics_match['uid'] ) {
            $ics_consumed[ 'uid:' . $ics_match['uid'] ] = true;
        }

        // Update if anything changed — assignments are always preserved
        $changed = (
            $db_date !== $ics_match['game_date']                                         ||
            get_post_meta( $game->ID, 'us_game_time', true ) !== $ics_match['game_time'] ||
            get_post_meta( $game->ID, 'us_field',     true ) !== $ics_match['field']     ||
            $db_home !== $ics_match['home']                                               ||
            $db_away !== $ics_match['away']
        );

        if ( $changed ) {
            wp_update_post( [ 'ID' => $game->ID, 'post_title' => $ics_match['title'] ] );
            update_post_meta( $game->ID, 'us_game_date', $ics_match['game_date'] );
            update_post_meta( $game->ID, 'us_game_time', $ics_match['game_time'] );
            update_post_meta( $game->ID, 'us_home_team', $ics_match['home'] );
            update_post_meta( $game->ID, 'us_away_team', $ics_match['away'] );
            update_post_meta( $game->ID, 'us_field',     $ics_match['field'] );
            if ( $ics_match['uid'] ) {
                update_post_meta( $game->ID, 'us_ics_uid', $ics_match['uid'] );
            }
            $updated++;
        }
        // Unchanged games are silently skipped
    }

    // ── Step 4: Create brand-new games (not in DB at all) ─────
    foreach ( $ics_by_teams as $team_key => $parsed ) {
        $uid_consumed = $parsed['uid'] && isset( $ics_consumed[ 'uid:' . $parsed['uid'] ] );
        if ( isset( $ics_consumed[ $team_key ] ) || $uid_consumed ) continue;

        $post_id = wp_insert_post( [
            'post_type'   => US_PT_GAME,
            'post_title'  => $parsed['title'],
            'post_status' => 'publish',
        ] );

        if ( is_wp_error( $post_id ) ) continue;

        update_post_meta( $post_id, 'us_game_date', $parsed['game_date'] );
        update_post_meta( $post_id, 'us_game_time', $parsed['game_time'] );
        update_post_meta( $post_id, 'us_home_team', $parsed['home'] );
        update_post_meta( $post_id, 'us_away_team', $parsed['away'] );
        update_post_meta( $post_id, 'us_field',     $parsed['field'] );
        update_post_meta( $post_id, 'us_league_id', $league_id );
        if ( $parsed['uid'] ) {
            update_post_meta( $post_id, 'us_ics_uid', $parsed['uid'] );
        }
        $created++;
    }

    // ── Step 5: Result notices ────────────────────────────────
    $parts = [];
    if ( $created )   $parts[] = "<strong>{$created} new game(s) added</strong>";
    if ( $updated )   $parts[] = "<strong>{$updated} game(s) updated</strong> — assignments preserved";
    if ( $deleted )   $parts[] = "{$deleted} game(s) removed (not in new schedule)";
    if ( $not_games ) $parts[] = "{$not_games} non-game event(s) ignored";

    if ( empty( $parts ) ) $parts[] = "No changes — schedule is already up to date";

    $type = ( $created || $updated || $deleted ) ? 'success' : 'info';
    echo '<div class="notice notice-' . $type . ' inline"><p>' . implode( ' &middot; ', $parts ) . '</p></div>';

    // Orphaned games — assigned but not found in new schedule
    if ( ! empty( $orphaned ) ) {
        echo '<div class="notice notice-warning inline">';
        echo '<p><strong>' . count( $orphaned ) . ' assigned game(s) were not found in the new schedule</strong> — these were not deleted. Please review manually:</p>';
        echo '<ul style="margin:.5em 0 .5em 1.5em;list-style:disc">';
        foreach ( $orphaned as $o ) {
            $edit_url = get_edit_post_link( $o['id'] );
            echo '<li>'
                . esc_html( $o['date'] ) . ' — '
                . esc_html( $o['away'] ) . ' at ' . esc_html( $o['home'] )
                . ' &nbsp;<a href="' . esc_url( $edit_url ) . '" target="_blank">View game &rarr;</a>'
                . '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }
}

// ── Parse ICS content ─────────────────────────────────────────
function us_parse_ics( $content ) {
    $events   = [];
    $current  = null;
    $lines    = preg_split( '/\r\n|\r|\n/', $content );
    $unfolded = [];

    foreach ( $lines as $line ) {
        if ( isset( $line[0] ) && ( $line[0] === ' ' || $line[0] === "\t" ) ) {
            $unfolded[ count( $unfolded ) - 1 ] .= substr( $line, 1 );
        } else {
            $unfolded[] = $line;
        }
    }

    foreach ( $unfolded as $line ) {
        $line = trim( $line );
        if ( $line === 'BEGIN:VEVENT' ) {
            $current = [];
        } elseif ( $line === 'END:VEVENT' && $current !== null ) {
            $events[] = $current;
            $current  = null;
        } elseif ( $current !== null ) {
            $colon = strpos( $line, ':' );
            if ( $colon === false ) continue;
            $key             = strtolower( preg_replace( '/;.*/', '', substr( $line, 0, $colon ) ) );
            $value           = substr( $line, $colon + 1 );
            $current[ $key ] = $value;
        }
    }

    return $events;
}

// ── Parse team names from ICS summary ────────────────────────
// Handles summaries in the format:
//   GAME - Away Team at Home Team - League Name - Extra Info
// Everything after the second " - " (i.e. after the matchup) is stripped.
function us_parse_teams( $summary ) {
    // Strip leading "GAME - "
    $summary = preg_replace( '/^GAME\s*-\s*/i', '', $summary );

    // Strip everything from the first " - " that appears after the matchup
    // (league name, association name, etc.)
    $summary = preg_replace( '/\s*-\s*.+$/', '', $summary, 1 );

    $summary = trim( $summary );
    $parts   = preg_split( '/ at /i', $summary, 2 );
    if ( count( $parts ) !== 2 ) return false;
    return [
        'away' => trim( $parts[0] ),
        'home' => trim( $parts[1] ),
    ];
}