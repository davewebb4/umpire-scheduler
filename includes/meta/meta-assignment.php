<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'add_meta_boxes', 'us_assignment_meta_box' );
function us_assignment_meta_box() {
    add_meta_box( 'us_assignment_details', 'Assignment Details', 'us_assignment_meta_cb', US_PT_ASSIGNMENT, 'normal', 'high' );
}

function us_assignment_meta_cb( $post ) {
    wp_nonce_field( 'us_assignment_meta', 'us_assignment_nonce' );
    $game     = get_post_meta( $post->ID, 'us_game_id',    true );
    $umpire   = get_post_meta( $post->ID, 'us_umpire_id',  true );
    $position = get_post_meta( $post->ID, 'us_position',   true );
    $status   = get_post_meta( $post->ID, 'us_status',     true );
    $pay      = get_post_meta( $post->ID, 'us_pay_amount', true );

    $games = get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'meta_key'    => 'us_game_date',
        'orderby'     => 'meta_value',
        'order'       => 'ASC',
        'post_status' => 'publish',
    ] );

    $umpires = get_posts( [
        'post_type'   => US_PT_UMPIRE,
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_active', 'value' => '1', 'compare' => '=' ],
        ],
    ] );
    ?>
    <table class="form-table">
        <tr>
            <th><label for="us_game_id">Game</label></th>
            <td>
                <select id="us_game_id" name="us_game_id">
                    <option value="">— select game —</option>
                    <?php foreach ( $games as $g ) :
                        $gdate = get_post_meta( $g->ID, 'us_game_date', true );
                        $gtime = get_post_meta( $g->ID, 'us_game_time', true );
                        $ghome = get_post_meta( $g->ID, 'us_home_team', true );
                        $gaway = get_post_meta( $g->ID, 'us_away_team', true );
                        $label = $gdate . ' ' . $gtime . ' — ' . $gaway . ' at ' . $ghome;
                    ?>
                        <option value="<?php echo $g->ID; ?>" <?php selected( $game, $g->ID ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="us_umpire_id">Umpire</label></th>
            <td>
                <select id="us_umpire_id" name="us_umpire_id">
                    <option value="">— select umpire —</option>
                    <?php foreach ( $umpires as $u ) : ?>
                        <option value="<?php echo $u->ID; ?>" <?php selected( $umpire, $u->ID ); ?>>
                            <?php echo esc_html( $u->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="us_position">Position</label></th>
            <td>
                <select id="us_position" name="us_position">
                    <option value="plate" <?php selected( $position, 'plate' ); ?>>Plate</option>
                    <option value="base"  <?php selected( $position, 'base'  ); ?>>Base</option>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="us_status">Status</label></th>
            <td>
                <select id="us_status" name="us_status">
                    <option value="pending"   <?php selected( $status, 'pending'   ); ?>>Pending</option>
                    <option value="confirmed" <?php selected( $status, 'confirmed' ); ?>>Confirmed</option>
                    <option value="declined"  <?php selected( $status, 'declined'  ); ?>>Declined</option>
                    <option value="no-show"   <?php selected( $status, 'no-show'   ); ?>>No-show</option>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="us_pay_amount">Pay amount ($)</label></th>
            <td>
                <input type="number" id="us_pay_amount" name="us_pay_amount"
                       value="<?php echo esc_attr( $pay ); ?>"
                       step="0.01" min="0" class="us-admin-meta__rate-input" />
                <p class="description">Auto-populated from league rate. Set to $0 for no-shows.</p>
            </td>
        </tr>
    </table>
    <?php
}

add_action( 'save_post_' . US_PT_ASSIGNMENT, 'us_save_assignment_meta' );
function us_save_assignment_meta( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! isset( $_POST['us_assignment_nonce'] ) || ! wp_verify_nonce( $_POST['us_assignment_nonce'], 'us_assignment_meta' ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    update_post_meta( $post_id, 'us_game_id',    absint(              $_POST['us_game_id']    ?? 0   ) );
    update_post_meta( $post_id, 'us_umpire_id',  absint(              $_POST['us_umpire_id']  ?? 0   ) );
    update_post_meta( $post_id, 'us_position',   sanitize_text_field( $_POST['us_position']   ?? ''  ) );
    update_post_meta( $post_id, 'us_status',     sanitize_text_field( $_POST['us_status']     ?? ''  ) );
    update_post_meta( $post_id, 'us_pay_amount', sanitize_text_field( $_POST['us_pay_amount'] ?? '0' ) );

    // Auto-zero pay for no-shows
    if ( ( $_POST['us_status'] ?? '' ) === 'no-show' ) {
        update_post_meta( $post_id, 'us_pay_amount', '0' );
    }
}