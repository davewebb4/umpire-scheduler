<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Query open games for a league ────────────────────────────
function us_get_open_games_for_export( $league_id ) {
    $today = date( 'Y-m-d' );

    $args = [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_key'    => 'us_game_date',
        'orderby'     => 'meta_value',
        'order'       => 'ASC',
        'meta_query'  => [
            'relation' => 'AND',
            [ 'key' => 'us_game_date', 'value' => $today, 'compare' => '>=' ],
        ],
    ];

    if ( $league_id ) {
        $args['meta_query'][] = [ 'key' => 'us_league_id', 'value' => $league_id, 'compare' => '=' ];
    } else {
        // All leagues — exclude tournament games (they have their own flow)
        $tournament_ids = get_posts( [
            'post_type'   => US_PT_LEAGUE,
            'numberposts' => -1,
            'post_status' => 'publish',
            'fields'      => 'ids',
            'meta_query'  => [ [ 'key' => 'us_is_tournament', 'value' => '1', 'compare' => '=' ] ],
        ] );
        if ( $tournament_ids ) {
            $args['meta_query'][] = [ 'key' => 'us_league_id', 'value' => $tournament_ids, 'compare' => 'NOT IN' ];
        }
    }

    $games      = get_posts( $args );
    $open_games = [];

    foreach ( $games as $game ) {
        if ( us_is_game_postponed( $game->ID ) || us_is_game_cancelled( $game->ID ) ) continue;

        $plate      = us_get_confirmed_assignment( $game->ID, 'plate' );
        $two_umps   = get_post_meta( $game->ID, 'us_two_umpires', true ) === '1';
        $base       = $two_umps ? us_get_confirmed_assignment( $game->ID, 'base' ) : null;

        // Skip fully confirmed games
        if ( $plate && ( ! $two_umps || $base ) ) continue;

        $needed = [];
        if ( ! $plate )              $needed[] = 'Plate';
        if ( $two_umps && ! $base )  $needed[] = 'Base';

        $status = ( $plate || ( $two_umps && $base ) ) ? 'Partial' : 'Open';

        $game_date = get_post_meta( $game->ID, 'us_game_date', true );

        $open_games[] = [
            'date'        => $game_date,
            'day'         => date( 'D', strtotime( $game_date ) ),
            'time'        => get_post_meta( $game->ID, 'us_game_time',  true ),
            'home'        => get_post_meta( $game->ID, 'us_home_team',  true ),
            'away'        => get_post_meta( $game->ID, 'us_away_team',  true ),
            'field'       => get_post_meta( $game->ID, 'us_field',      true ),
            'league_id'   => get_post_meta( $game->ID, 'us_league_id',  true ),
            'status'      => $status,
            'needed'      => implode( ' + ', $needed ),
        ];
    }

    return $open_games;
}

// ── Shortcode ─────────────────────────────────────────────────
add_shortcode( 'allocator_open_games_export', 'us_shortcode_open_games_export' );
function us_shortcode_open_games_export() {
    if ( ! us_is_allocator() ) return '<p class="us-empty">Access denied.</p>';

    $league_id   = isset( $_POST['us_oge_action'] ) ? absint( $_POST['us_oge_league'] ?? 0 ) : -1;
    $submitted   = isset( $_POST['us_oge_action'] );

    $all_leagues = get_posts( [
        'post_type'   => US_PT_LEAGUE,
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'post_status' => 'publish',
        'meta_query'  => [
            'relation' => 'AND',
            [ 'key' => 'us_is_archived',   'value' => '1', 'compare' => '!=' ],
            [ 'key' => 'us_is_tournament', 'value' => '1', 'compare' => '!=' ],
        ],
    ] );

    $league      = $league_id ? get_post( $league_id ) : null;
    $league_name = $league ? $league->post_title : 'All Leagues';
    $org_name    = us_setting( 'org_name' ) ?: us_setting( 'org_short' );
    $export_date = date( 'F j, Y' );

    $games = $submitted ? us_get_open_games_for_export( $league_id ?: 0 ) : [];

    // Group by league when showing all
    $games_by_league = [];
    if ( $submitted && ! $league_id ) {
        foreach ( $games as $g ) {
            $lid = $g['league_id'];
            $games_by_league[ $lid ][] = $g;
        }
    }

    ob_start();
    ?>
    <div class="us-dashboard">
        <?php us_dashboard_notices(); ?>

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <h2 style="margin:0;">Export Open Games</h2>
            <?php if ( $submitted ) : ?>
                <a href="<?php echo esc_url( get_permalink() ); ?>" class="button" style="font-size:13px;">&#8592; New export</a>
            <?php endif; ?>
        </div>

        <?php if ( ! $submitted ) : ?>
        <!-- ── Filter form ─────────────────────────────────── -->
        <div class="us-invoice-step-card">
            <form method="post">
                <input type="hidden" name="us_oge_action" value="1">

                <div class="us-form-group" style="max-width:400px;">
                    <label for="us_oge_league">League</label>
                    <select id="us_oge_league" name="us_oge_league" style="width:100%;">
                        <option value="">— All leagues —</option>
                        <?php foreach ( $all_leagues as $l ) : ?>
                            <option value="<?php echo $l->ID; ?>"><?php echo esc_html( $l->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="button button-primary">Generate export &rarr;</button>
            </form>
        </div>

        <?php else : ?>
        <!-- ── Results + PDF ──────────────────────────────── -->

        <?php if ( empty( $games ) ) : ?>
            <p class="us-notice" style="background:#f0f7ee;border:1px solid #b7ddb0;border-radius:6px;padding:12px 16px;font-size:14px;">
                No open games found for <strong><?php echo esc_html( $league_name ); ?></strong> from today onwards.
            </p>
        <?php else : ?>

        <div style="margin-bottom:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <button id="us-oge-download" class="button button-primary">&#8659; Download PDF</button>
            <span style="font-size:13px;color:#888;"><?php echo count( $games ); ?> open game<?php echo count( $games ) !== 1 ? 's' : ''; ?> &mdash; <?php echo esc_html( $league_name ); ?></span>
        </div>

        <!-- Printable export document -->
        <div id="us-oge-content" class="us-oge-doc">

            <div class="us-oge-doc__header">
                <div>
                    <div class="us-oge-doc__org"><?php echo esc_html( $org_name ); ?></div>
                    <div class="us-oge-doc__title">Open Games &mdash; <?php echo esc_html( $league_name ); ?></div>
                </div>
                <div class="us-oge-doc__meta">
                    <div>Generated: <?php echo esc_html( $export_date ); ?></div>
                    <div><?php echo count( $games ); ?> game<?php echo count( $games ) !== 1 ? 's' : ''; ?> needing umpires</div>
                </div>
            </div>

            <?php if ( $league_id ) : ?>
            <!-- Single league table -->
            <table class="us-oge-doc__table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Day</th>
                        <th>Time</th>
                        <th>Home</th>
                        <th>Away</th>
                        <th>Field</th>
                        <th>Needed</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $games as $g ) : ?>
                    <tr>
                        <td style="white-space:nowrap;"><?php echo esc_html( date( 'M j, Y', strtotime( $g['date'] ) ) ); ?></td>
                        <td><?php echo esc_html( $g['day'] ); ?></td>
                        <td style="white-space:nowrap;"><?php echo $g['time'] ? esc_html( date( 'g:i a', strtotime( $g['time'] ) ) ) : '—'; ?></td>
                        <td><?php echo esc_html( $g['home'] ); ?></td>
                        <td><?php echo esc_html( $g['away'] ); ?></td>
                        <td><?php echo esc_html( $g['field'] ?: '—' ); ?></td>
                        <td><span class="us-oge-badge us-oge-badge--needed"><?php echo esc_html( $g['needed'] ); ?></span></td>
                        <td><span class="us-oge-badge us-oge-badge--<?php echo $g['status'] === 'Open' ? 'open' : 'partial'; ?>"><?php echo esc_html( $g['status'] ); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php else : ?>
            <!-- All leagues — grouped by league -->
            <?php foreach ( $games_by_league as $lid => $lg_games ) :
                $lg = get_post( $lid );
                $lg_name = $lg ? $lg->post_title : 'Unknown League';
            ?>
            <div class="us-oge-doc__league-heading"><?php echo esc_html( $lg_name ); ?> <span style="font-weight:400;font-size:13px;color:#888;">(<?php echo count( $lg_games ); ?> game<?php echo count( $lg_games ) !== 1 ? 's' : ''; ?>)</span></div>
            <table class="us-oge-doc__table" style="margin-bottom:24px;">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Day</th>
                        <th>Time</th>
                        <th>Home</th>
                        <th>Away</th>
                        <th>Field</th>
                        <th>Needed</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $lg_games as $g ) : ?>
                    <tr>
                        <td style="white-space:nowrap;"><?php echo esc_html( date( 'M j, Y', strtotime( $g['date'] ) ) ); ?></td>
                        <td><?php echo esc_html( $g['day'] ); ?></td>
                        <td style="white-space:nowrap;"><?php echo $g['time'] ? esc_html( date( 'g:i a', strtotime( $g['time'] ) ) ) : '—'; ?></td>
                        <td><?php echo esc_html( $g['home'] ); ?></td>
                        <td><?php echo esc_html( $g['away'] ); ?></td>
                        <td><?php echo esc_html( $g['field'] ?: '—' ); ?></td>
                        <td><span class="us-oge-badge us-oge-badge--needed"><?php echo esc_html( $g['needed'] ); ?></span></td>
                        <td><span class="us-oge-badge us-oge-badge--<?php echo $g['status'] === 'Open' ? 'open' : 'partial'; ?>"><?php echo esc_html( $g['status'] ); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endforeach; ?>
            <?php endif; ?>

        </div><!-- /#us-oge-content -->

        <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
        <script>
        document.getElementById('us-oge-download').addEventListener('click', function() {
            var btn = this;
            btn.disabled    = true;
            btn.textContent = 'Generating…';
            html2pdf().set({
                margin:      [10, 10, 10, 10],
                filename:    'open-games-<?php echo esc_js( sanitize_title( $league_name ) ); ?>-<?php echo date( 'Y-m-d' ); ?>.pdf',
                image:       { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true },
                jsPDF:       { unit: 'mm', format: 'letter', orientation: 'landscape' },
            }).from( document.getElementById('us-oge-content') ).save().then(function() {
                btn.disabled    = false;
                btn.textContent = '⬇ Download PDF';
            });
        });
        </script>

        <?php endif; // games not empty ?>
        <?php endif; // submitted ?>

    </div><!-- /.us-dashboard -->

    <style>
    .us-oge-doc {
        background: #fff;
        border: 1px solid #dde3ea;
        border-radius: 8px;
        padding: 36px 40px;
        font-family: Arial, sans-serif;
        color: #333;
        font-size: 13px;
    }
    .us-oge-doc__header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 28px;
        padding-bottom: 20px;
        border-bottom: 3px solid #1a3a5c;
    }
    .us-oge-doc__org   { font-size: 18px; font-weight: 700; color: #1a3a5c; }
    .us-oge-doc__title { font-size: 13px; color: #666; margin-top: 4px; }
    .us-oge-doc__meta  { text-align: right; font-size: 12px; color: #888; line-height: 1.8; }
    .us-oge-doc__league-heading {
        font-size: 14px;
        font-weight: 700;
        color: #1a3a5c;
        margin: 20px 0 8px;
        padding-bottom: 4px;
        border-bottom: 1px solid #dde3ea;
    }
    .us-oge-doc__table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    .us-oge-doc__table thead tr { background: #f0f4f8; }
    .us-oge-doc__table th {
        padding: 8px 10px;
        text-align: left;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #555;
        border-bottom: 2px solid #dde3ea;
        white-space: nowrap;
    }
    .us-oge-doc__table td {
        padding: 9px 10px;
        border-bottom: 1px solid #f0f0f0;
        vertical-align: middle;
    }
    .us-oge-doc__table tbody tr:hover { background: #fafbfc; }
    .us-oge-badge {
        display: inline-block;
        padding: 2px 7px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: 600;
        white-space: nowrap;
    }
    .us-oge-badge--open    { background: #fde8e8; color: #b32d2e; }
    .us-oge-badge--partial { background: #fff4e0; color: #7a5200; }
    .us-oge-badge--needed  { background: #f0f4f8; color: #333; }
    @media print {
        .us-dashboard > *:not(.us-oge-doc) { display: none !important; }
        .us-oge-doc { border: none; padding: 0; }
    }
    </style>
    <?php
    return ob_get_clean();
}
