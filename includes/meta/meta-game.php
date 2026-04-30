<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'add_meta_boxes', 'us_game_meta_box' );
function us_game_meta_box() {
    add_meta_box( 'us_game_details', 'Game Details', 'us_game_meta_cb', US_PT_GAME, 'normal', 'high' );
}

function us_game_meta_cb( $post ) {
    wp_nonce_field( 'us_game_meta', 'us_game_nonce' );
    $date     = get_post_meta( $post->ID, 'us_game_date',     true );
    $time     = get_post_meta( $post->ID, 'us_game_time',     true );
    $home     = get_post_meta( $post->ID, 'us_home_team',     true );
    $away     = get_post_meta( $post->ID, 'us_away_team',     true );
    $field    = get_post_meta( $post->ID, 'us_field',         true );
    $league   = get_post_meta( $post->ID, 'us_league_id',     true );
    $two_umps = get_post_meta( $post->ID, 'us_two_umpires',   true );
    $dh       = get_post_meta( $post->ID, 'us_double_header', true );

    $dh_rate       = $league ? get_post_meta( $league, 'us_dh_pay_rate',   true ) : '';
    $is_tournament = $league ? get_post_meta( $league, 'us_is_tournament', true ) === '1' : false;

    $leagues = get_posts( [
        'post_type'   => US_PT_LEAGUE,
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'post_status' => 'publish',
    ] );
    ?>
    <table class="form-table">
        <tr>
            <th><label for="us_game_date">Date</label></th>
            <td><input type="date" id="us_game_date" name="us_game_date" value="<?php echo esc_attr( $date ); ?>" /></td>
        </tr>
        <tr>
            <th><label for="us_game_time">Time</label></th>
            <td><input type="time" id="us_game_time" name="us_game_time" value="<?php echo esc_attr( $time ); ?>" /></td>
        </tr>
        <tr>
            <th><label for="us_home_team">Home team</label></th>
            <td><input type="text" id="us_home_team" name="us_home_team" value="<?php echo esc_attr( $home ); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="us_away_team">Away team</label></th>
            <td><input type="text" id="us_away_team" name="us_away_team" value="<?php echo esc_attr( $away ); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="us_field">Field</label></th>
            <td><input type="text" id="us_field" name="us_field" value="<?php echo esc_attr( $field ); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="us_league_id">League</label></th>
            <td>
                <select id="us_league_id" name="us_league_id">
                    <option value="">— select league —</option>
                    <?php foreach ( $leagues as $l ) :
                        $l_is_tourney = get_post_meta( $l->ID, 'us_is_tournament', true ) === '1';
                    ?>
                        <option value="<?php echo $l->ID; ?>"
                                data-tournament="<?php echo $l_is_tourney ? '1' : '0'; ?>"
                                <?php selected( $league, $l->ID ); ?>>
                            <?php echo esc_html( $l->post_title ); ?>
                            <?php if ( $l_is_tourney ) echo ' (Tournament)'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="us_two_umpires">Second umpire</label></th>
            <td>
                <label class="us-admin-meta__checkbox-label">
                    <input type="checkbox" id="us_two_umpires" name="us_two_umpires" value="1" <?php checked( $two_umps, '1' ); ?> />
                    Enable base umpire slot for this game
                </label>
                <p class="description">When enabled, a base umpire slot opens up on the front end and in the assignment dashboard.</p>
            </td>
        </tr>
        <tr id="us-dh-row" <?php echo $is_tournament ? 'class="us-admin-meta__row--hidden"' : ''; ?>>
            <th><label for="us_double_header">Optional pay rate</label></th>
            <td>
                <label class="us-admin-meta__checkbox-label">
                    <input type="checkbox" id="us_double_header" name="us_double_header" value="1" <?php checked( $dh, '1' ); ?> />
                    Use optional pay rate for this game
                </label>
                <p class="description" id="us-dh-desc">
                    When enabled, umpire pay will use the league's optional rate
                    <?php if ( $dh_rate ) : ?>
                        (currently <strong>$<?php echo number_format( floatval( $dh_rate ), 2 ); ?></strong>)
                    <?php else : ?>
                        — <a href="<?php echo $league ? get_edit_post_link( $league ) : admin_url( 'edit.php?post_type=' . US_PT_LEAGUE ); ?>">set an optional rate on the league</a> first
                    <?php endif; ?>
                    instead of the standard rate.
                </p>
            </td>
        </tr>
    </table>

    <script>
    (function(){
        var $league  = document.getElementById('us_league_id');
        var $dhRow   = document.getElementById('us-dh-row');
        var $dhCheck = document.getElementById('us_double_header');

        function updateDhRow() {
            var selected  = $league.options[ $league.selectedIndex ];
            var isTourney = selected && selected.getAttribute('data-tournament') === '1';
            $dhRow.style.display = isTourney ? 'none' : '';
            if ( isTourney ) $dhCheck.checked = false;
        }

        $league.addEventListener('change', updateDhRow);
    })();
    </script>
    <?php
}

add_action( 'save_post_' . US_PT_GAME, 'us_save_game_meta' );
function us_save_game_meta( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! isset( $_POST['us_game_nonce'] ) || ! wp_verify_nonce( $_POST['us_game_nonce'], 'us_game_meta' ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $fields = [ 'us_game_date', 'us_game_time', 'us_home_team', 'us_away_team', 'us_field', 'us_league_id' ];
    foreach ( $fields as $f ) {
        update_post_meta( $post_id, $f, sanitize_text_field( $_POST[ $f ] ?? '' ) );
    }

    update_post_meta( $post_id, 'us_two_umpires', isset( $_POST['us_two_umpires'] ) ? '1' : '0' );

    // ── Double header — force off for tournament leagues ──────
    $league_id     = sanitize_text_field( $_POST['us_league_id'] ?? '' );
    $is_tournament = $league_id ? get_post_meta( $league_id, 'us_is_tournament', true ) === '1' : false;

    $prev_dh = get_post_meta( $post_id, 'us_double_header', true );
    $new_dh  = ( ! $is_tournament && isset( $_POST['us_double_header'] ) ) ? '1' : '0';
    update_post_meta( $post_id, 'us_double_header', $new_dh );

    // Only re-stamp assignments if the DH flag actually changed
    if ( $new_dh !== $prev_dh ) {
        $game_date = get_post_meta( $post_id, 'us_game_date', true );
        $today     = current_time( 'Y-m-d' );

        if ( $game_date >= $today ) {
            $new_rate    = us_get_game_pay_rate( $post_id );
            $assignments = get_posts( [
                'post_type'   => US_PT_ASSIGNMENT,
                'numberposts' => -1,
                'post_status' => 'publish',
                'meta_query'  => [
                    [ 'key' => 'us_game_id', 'value' => $post_id, 'compare' => '=' ],
                ],
            ] );

            foreach ( $assignments as $a ) {
                update_post_meta( $a->ID, 'us_pay_amount', $new_rate );
            }

            if ( ! empty( $assignments ) ) {
                set_transient( 'us_dh_pay_restamped_' . $post_id, [
                    'count' => count( $assignments ),
                    'rate'  => $new_rate,
                    'dh'    => $new_dh,
                ], 30 );
            }
        }
    }
}

// ── Admin notice after DH flag change re-stamps pay ───────────
add_action( 'admin_notices', 'us_dh_restamp_notice' );
function us_dh_restamp_notice() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== US_PT_GAME ) return;

    global $post;
    if ( ! $post ) return;

    $data = get_transient( 'us_dh_pay_restamped_' . $post->ID );
    if ( ! $data ) return;

    delete_transient( 'us_dh_pay_restamped_' . $post->ID );

    $action = $data['dh'] === '1' ? 'set to optional rate' : 'changed back to standard rate';
    echo '<div class="notice notice-success is-dismissible"><p>';
    echo '<strong>' . intval( $data['count'] ) . ' assignment(s) re-stamped to $' . number_format( floatval( $data['rate'] ), 2 ) . ' — game ' . $action . '.</strong>';
    echo '</p></div>';
}