<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'add_meta_boxes', 'us_league_meta_box' );
function us_league_meta_box() {
    add_meta_box( 'us_league_details', 'League Details', 'us_league_meta_cb', US_PT_LEAGUE, 'normal', 'high' );
}

function us_league_meta_cb( $post ) {
    wp_nonce_field( 'us_league_meta', 'us_league_nonce' );
    $rate           = get_post_meta( $post->ID, 'us_pay_rate',        true );
    $dh_rate        = get_post_meta( $post->ID, 'us_dh_pay_rate',     true );
    $allocator_rate = get_post_meta( $post->ID, 'us_allocator_rate',  true );
    $admin_rate     = get_post_meta( $post->ID, 'us_admin_rate',      true );
    $rules          = get_post_meta( $post->ID, 'us_rules',           true );
    $is_tournament  = get_post_meta( $post->ID, 'us_is_tournament',   true ) === '1';
    $tourney_start  = get_post_meta( $post->ID, 'us_tourney_start',   true );
    $tourney_end    = get_post_meta( $post->ID, 'us_tourney_end',     true );
    $contact_name   = get_post_meta( $post->ID, 'us_contact_name',   true );
    $contact_email  = get_post_meta( $post->ID, 'us_contact_email',  true );
    $contact_phone  = get_post_meta( $post->ID, 'us_contact_phone',  true );
    $website        = get_post_meta( $post->ID, 'us_website',        true );

    // ── Inline re-apply notices ───────────────────────────────
    foreach ( [
        'us_pay_reapply_std_notice_' . $post->ID => [ 'rate' => $rate,    'label' => 'standard' ],
        'us_pay_reapply_dh_notice_'  . $post->ID => [ 'rate' => $dh_rate, 'label' => 'optional rate' ],
    ] as $key => $info ) {
        $count = get_transient( $key );
        if ( $count !== false ) {
            delete_transient( $key );
            echo '<div class="notice notice-success inline us-admin-meta__inline-notice"><p>';
            echo '<strong>' . intval( $count ) . ' ' . $info['label'] . ' assignment(s) (including past games) updated to $' . number_format( floatval( $info['rate'] ), 2 ) . ' per game.</strong>';
            echo '</p></div>';
        }
    }
    ?>

    <?php $is_archived = get_post_meta( $post->ID, 'us_is_archived', true ) === '1'; ?>
    <table class="form-table">

        <!-- Archive toggle -->
        <tr style="<?php echo $is_archived ? 'background:#fff2f2;' : ''; ?>">
            <th>Status</th>
            <td>
                <label>
                    <input type="checkbox" name="us_is_archived" value="1" <?php checked( $is_archived ); ?>>
                    <strong style="color:<?php echo $is_archived ? '#d63638' : 'inherit'; ?>">
                        Archive — hide from all front-end pages
                    </strong>
                </label>
                <p class="description">All data (games, assignments, pay records) is preserved and still visible in pay reports. Use this for completed tournaments or inactive leagues.</p>
            </td>
        </tr>

        <!-- Tournament toggle -->
        <tr>
            <th>League type</th>
            <td>
                <label class="us-admin-meta__checkbox-label">
                    <input type="checkbox" id="us_is_tournament" name="us_is_tournament" value="1"
                           <?php checked( $is_tournament ); ?>
                           class="us-admin-meta__checkbox" />
                    <strong>This is a tournament</strong>
                </label>
                <p class="description">Tournaments are displayed separately on the front end and paid out on completion rather than monthly.</p>
            </td>
        </tr>

        <!-- Tournament dates — shown only when tournament ticked -->
        <tr class="us-tourney-field<?php echo ! $is_tournament ? ' us-admin-meta__row--hidden' : ''; ?>">
            <th><label for="us_tourney_start">Tournament dates</label></th>
            <td>
                <div class="us-admin-meta__date-range">
                    <div>
                        <label class="us-admin-meta__sublabel">Start date</label>
                        <input type="date" id="us_tourney_start" name="us_tourney_start"
                               value="<?php echo esc_attr( $tourney_start ); ?>" />
                    </div>
                    <div>
                        <label class="us-admin-meta__sublabel">End date</label>
                        <input type="date" id="us_tourney_end" name="us_tourney_end"
                               value="<?php echo esc_attr( $tourney_end ); ?>" />
                    </div>
                </div>
                <p class="description">Used to group and display tournament games on the front end.</p>
            </td>
        </tr>

        <!-- Standard pay rate -->
        <tr>
            <th><label for="us_pay_rate">Standard pay rate ($)</label></th>
            <td>
                <input type="hidden" name="us_pay_rate_previous" value="<?php echo esc_attr( $rate ); ?>" />
                <input type="number" id="us_pay_rate" name="us_pay_rate"
                       value="<?php echo esc_attr( $rate ); ?>"
                       step="0.01" min="0" class="us-admin-meta__rate-input" />
                <p class="description">
                    <?php echo $is_tournament
                        ? 'Pay rate per game for this tournament.'
                        : 'Pay rate for regular games. If changed on save, all upcoming standard game assignments are automatically updated.';
                    ?>
                </p>
            </td>
        </tr>

        <!-- Optional pay rate — hidden for tournaments -->
        <tr class="us-dh-field<?php echo $is_tournament ? ' us-admin-meta__row--hidden' : ''; ?>">
            <th><label for="us_dh_pay_rate">Optional pay rate ($)</label></th>
            <td>
                <input type="hidden" name="us_dh_pay_rate_previous" value="<?php echo esc_attr( $dh_rate ); ?>" />
                <input type="number" id="us_dh_pay_rate" name="us_dh_pay_rate"
                       value="<?php echo esc_attr( $dh_rate ); ?>"
                       step="0.01" min="0" class="us-admin-meta__rate-input" />
                <p class="description">An alternative pay rate for specific games in this league. If changed on save, all upcoming optional-rate game assignments are automatically updated.</p>
            </td>
        </tr>

        <!-- Allocator rate -->
        <tr>
            <th><label for="us_allocator_rate">Allocator fee per game ($)</label></th>
            <td>
                <input type="number" id="us_allocator_rate" name="us_allocator_rate"
                       value="<?php echo esc_attr( $allocator_rate ); ?>"
                       step="0.01" min="0" class="us-admin-meta__rate-input" />
                <p class="description">Fee charged per game for allocator services. Shown on the Admin Fees pay report.</p>
            </td>
        </tr>

        <!-- Administrative rate -->
        <tr>
            <th><label for="us_admin_rate">Administrative fee per game ($)</label></th>
            <td>
                <input type="number" id="us_admin_rate" name="us_admin_rate"
                       value="<?php echo esc_attr( $admin_rate ); ?>"
                       step="0.01" min="0" class="us-admin-meta__rate-input" />
                <p class="description">Fee charged per game for administrative costs. Shown on the Admin Fees pay report.</p>
            </td>
        </tr>

        <tr>
            <th><label for="us_contact_name">Contact name</label></th>
            <td><input type="text" id="us_contact_name" name="us_contact_name" value="<?php echo esc_attr( $contact_name ); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="us_contact_email">Contact email</label></th>
            <td><input type="email" id="us_contact_email" name="us_contact_email" value="<?php echo esc_attr( $contact_email ); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="us_contact_phone">Contact phone</label></th>
            <td><input type="text" id="us_contact_phone" name="us_contact_phone" value="<?php echo esc_attr( $contact_phone ); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="us_website">Website</label></th>
            <td><input type="url" id="us_website" name="us_website" value="<?php echo esc_attr( $website ); ?>" class="regular-text" /></td>
        </tr>

        <!-- League rules -->
        <tr>
            <th><label for="us_rules">League rules</label></th>
            <td>
                <?php
                wp_editor( $rules, 'us_rules', [
                    'textarea_name' => 'us_rules',
                    'textarea_rows' => 15,
                    'media_buttons' => false,
                    'teeny'         => false,
                    'quicktags'     => true,
                ] );
                ?>
                <p class="description">
                    If filled in, a "League Rules" link will appear in the navigation menu for all visitors.
                    Leave blank to hide it from the menu.
                </p>
            </td>
        </tr>

        <!-- Re-apply buttons -->
        <?php if ( $post->ID ) : ?>
        <tr>
            <th>Re-apply rates</th>
            <td class="us-admin-meta__reapply-col">

                <?php if ( $rate ) : ?>
                <div>
                    <button type="submit" name="us_reapply_std_rate" value="1" class="button">
                        Re-apply $<?php echo number_format( floatval( $rate ), 2 ); ?> to all upcoming
                        <?php echo $is_tournament ? 'tournament' : 'standard'; ?> games
                    </button>
                    <p class="description us-admin-meta__reapply-desc">
                        <?php echo $is_tournament
                            ? 'Updates all assignments for this tournament regardless of status.'
                            : 'Updates all upcoming assignments on standard-rate games regardless of status.';
                        ?>
                    </p>
                </div>
                <?php endif; ?>

                <?php if ( $dh_rate && ! $is_tournament ) : ?>
                <div class="us-dh-field">
                    <button type="submit" name="us_reapply_dh_rate" value="1" class="button">
                        Re-apply $<?php echo number_format( floatval( $dh_rate ), 2 ); ?> to all upcoming optional-rate games
                    </button>
                    <p class="description us-admin-meta__reapply-desc">
                        Updates all upcoming assignments on optional-rate games only regardless of status.
                    </p>
                </div>
                <?php endif; ?>

                <?php if ( ! $rate && ! $dh_rate ) : ?>
                    <p class="description">Set a pay rate above to enable re-apply options.</p>
                <?php endif; ?>

            </td>
        </tr>
        <?php endif; ?>

    </table>

    <script>
    (function(){
        var $toggle  = document.getElementById('us_is_tournament');
        var dhFields = document.querySelectorAll('.us-dh-field');
        var tFields  = document.querySelectorAll('.us-tourney-field');
        var stdDesc  = document.querySelector('#us_pay_rate').nextElementSibling;

        var stdLeague  = 'Pay rate for regular games. If changed on save, all upcoming standard game assignments are automatically updated.';
        var stdTourney = 'Pay rate per game for this tournament.';

        function toggle() {
            var isTourney = $toggle.checked;
            dhFields.forEach( function(el){ el.style.display = isTourney ? 'none' : ''; } );
            tFields.forEach(  function(el){ el.style.display = isTourney ? '' : 'none'; } );
            if ( stdDesc ) stdDesc.textContent = isTourney ? stdTourney : stdLeague;
        }

        $toggle.addEventListener('change', toggle);
    })();
    </script>
    <?php
}

// ── Save league meta ──────────────────────────────────────────
add_action( 'save_post_' . US_PT_LEAGUE, 'us_save_league_meta' );
function us_save_league_meta( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! isset( $_POST['us_league_nonce'] ) || ! wp_verify_nonce( $_POST['us_league_nonce'], 'us_league_meta' ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    update_post_meta( $post_id, 'us_pay_rate',       sanitize_text_field( $_POST['us_pay_rate']       ?? '' ) );
    update_post_meta( $post_id, 'us_dh_pay_rate',    sanitize_text_field( $_POST['us_dh_pay_rate']    ?? '' ) );
    update_post_meta( $post_id, 'us_allocator_rate', sanitize_text_field( $_POST['us_allocator_rate'] ?? '' ) );
    update_post_meta( $post_id, 'us_admin_rate',     sanitize_text_field( $_POST['us_admin_rate']     ?? '' ) );
    update_post_meta( $post_id, 'us_is_tournament',  isset( $_POST['us_is_tournament'] ) ? '1' : '0' );
    update_post_meta( $post_id, 'us_is_archived',    isset( $_POST['us_is_archived'] )   ? '1' : '0' );
    update_post_meta( $post_id, 'us_tourney_start',  sanitize_text_field( $_POST['us_tourney_start']  ?? '' ) );
    update_post_meta( $post_id, 'us_tourney_end',    sanitize_text_field( $_POST['us_tourney_end']    ?? '' ) );
    update_post_meta( $post_id, 'us_contact_name',   sanitize_text_field( $_POST['us_contact_name']   ?? '' ) );
    update_post_meta( $post_id, 'us_contact_email',  sanitize_email(      $_POST['us_contact_email']  ?? '' ) );
    update_post_meta( $post_id, 'us_contact_phone',  sanitize_text_field( $_POST['us_contact_phone']  ?? '' ) );
    update_post_meta( $post_id, 'us_website',        esc_url_raw(         $_POST['us_website']        ?? '' ) );

    // Rules — allow formatted HTML content
    if ( isset( $_POST['us_rules'] ) ) {
        update_post_meta( $post_id, 'us_rules', wp_kses_post( $_POST['us_rules'] ) );
    }
}

// ── Auto-update on rate change + manual re-apply ──────────────
add_action( 'save_post_' . US_PT_LEAGUE, 'us_maybe_update_pay_rates', 20 );
function us_maybe_update_pay_rates( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! isset( $_POST['us_league_nonce'] ) || ! wp_verify_nonce( $_POST['us_league_nonce'], 'us_league_meta' ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $today         = current_time( 'Y-m-d' );
    $is_tournament = isset( $_POST['us_is_tournament'] );

    // ── Standard rate ─────────────────────────────────────────
    $new_std     = sanitize_text_field( $_POST['us_pay_rate']          ?? '' );
    $prev_std    = sanitize_text_field( $_POST['us_pay_rate_previous'] ?? '' );
    $reapply_std = isset( $_POST['us_reapply_std_rate'] );

    if ( $new_std && ( $reapply_std || $new_std !== $prev_std ) ) {
        $from  = $reapply_std ? null : $today;
        $count = $is_tournament
            ? us_apply_rate_to_upcoming( $post_id, $new_std, $from, null )
            : us_apply_rate_to_upcoming( $post_id, $new_std, $from, false );
        if ( $count > 0 ) {
            $key = $reapply_std ? 'us_pay_reapply_std_notice_' : 'us_pay_auto_std_notice_';
            set_transient( $key . $post_id, $count, 30 );
        }
    }

    // ── Double header rate — skip for tournaments ─────────────
    if ( ! $is_tournament ) {
        $new_dh     = sanitize_text_field( $_POST['us_dh_pay_rate']          ?? '' );
        $prev_dh    = sanitize_text_field( $_POST['us_dh_pay_rate_previous'] ?? '' );
        $reapply_dh = isset( $_POST['us_reapply_dh_rate'] );

        if ( $new_dh && ( $reapply_dh || $new_dh !== $prev_dh ) ) {
            $from  = $reapply_dh ? null : $today;
            $count = us_apply_rate_to_upcoming( $post_id, $new_dh, $from, true );
            if ( $count > 0 ) {
                $key = $reapply_dh ? 'us_pay_reapply_dh_notice_' : 'us_pay_auto_dh_notice_';
                set_transient( $key . $post_id, $count, 30 );
            }
        }
    }
}

// ── Shared helper: apply rate to assignments ──────────────────
// Pass null for $from_date to include past games.
function us_apply_rate_to_upcoming( $league_id, $rate, $from_date = null, $dh_only = false ) {
    $meta_query = [
        [ 'key' => 'us_league_id', 'value' => $league_id, 'compare' => '=' ],
    ];
    if ( $from_date !== null ) {
        $meta_query[] = [ 'key' => 'us_game_date', 'value' => $from_date, 'compare' => '>=' ];
    }

    if ( $dh_only === true ) {
        $meta_query[] = [ 'key' => 'us_double_header', 'value' => '1', 'compare' => '=' ];
    } elseif ( $dh_only === false ) {
        $meta_query[] = [
            'relation' => 'OR',
            [ 'key' => 'us_double_header', 'value' => '1',  'compare' => '!=' ],
            [ 'key' => 'us_double_header', 'compare' => 'NOT EXISTS' ],
        ];
    }

    $games = get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => $meta_query,
    ] );

    $updated = 0;
    foreach ( $games as $game ) {
        $assignments = get_posts( [
            'post_type'   => US_PT_ASSIGNMENT,
            'numberposts' => -1,
            'post_status' => 'publish',
            'meta_query'  => [
                [ 'key' => 'us_game_id', 'value' => $game->ID, 'compare' => '=' ],
            ],
        ] );
        foreach ( $assignments as $a ) {
            update_post_meta( $a->ID, 'us_pay_amount', $rate );
            $updated++;
        }
    }

    return $updated;
}

// ── Admin notices ─────────────────────────────────────────────
add_action( 'admin_notices', 'us_pay_update_notices' );
function us_pay_update_notices() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== US_PT_LEAGUE ) return;

    global $post;
    if ( ! $post ) return;

    $rate          = get_post_meta( $post->ID, 'us_pay_rate',    true );
    $dh_rate       = get_post_meta( $post->ID, 'us_dh_pay_rate', true );
    $is_tournament = get_post_meta( $post->ID, 'us_is_tournament', true ) === '1';

    $notices = [
        'us_pay_auto_std_notice_' . $post->ID => [
            'rate' => $rate,
            'msg'  => $is_tournament
                ? 'Pay rate changed — %d tournament assignment(s) automatically updated to $%s per game.'
                : 'Standard rate changed — %d upcoming standard game assignment(s) automatically updated to $%s per game.',
        ],
        'us_pay_auto_dh_notice_' . $post->ID => [
            'rate' => $dh_rate,
            'msg'  => 'Optional rate changed — %d upcoming optional-rate assignment(s) automatically updated to $%s per game.',
        ],
    ];

    foreach ( $notices as $key => $info ) {
        $count = get_transient( $key );
        if ( ! $count ) continue;
        delete_transient( $key );
        echo '<div class="notice notice-success is-dismissible"><p><strong>';
        printf( $info['msg'], intval( $count ), number_format( floatval( $info['rate'] ), 2 ) );
        echo '</strong></p></div>';
    }
}