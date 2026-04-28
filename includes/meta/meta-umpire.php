<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'add_meta_boxes', 'us_umpire_meta_box' );
function us_umpire_meta_box() {
    add_meta_box( 'us_umpire_details', 'Umpire Details',  'us_umpire_meta_cb',    US_PT_UMPIRE, 'normal', 'high'    );
    add_meta_box( 'us_umpire_stats',   'Umpire Stats',    'us_umpire_stats_cb',   US_PT_UMPIRE, 'side',   'default' );
    add_meta_box( 'us_umpire_history', 'Game History',    'us_umpire_history_cb', US_PT_UMPIRE, 'normal', 'default' );
}

// ── Main details meta box ─────────────────────────────────────
function us_umpire_meta_cb( $post ) {
    wp_nonce_field( 'us_umpire_meta', 'us_umpire_nonce' );
    $email       = get_post_meta( $post->ID, 'us_email',       true );
    $phone       = get_post_meta( $post->ID, 'us_phone',       true );
    $user_id     = get_post_meta( $post->ID, 'us_wp_user_id',  true );
    $active      = get_post_meta( $post->ID, 'us_active',      true );
    $notes       = get_post_meta( $post->ID, 'us_notes',       true );
    $is_assignor = get_post_meta( $post->ID, 'us_is_assignor', true ) === '1';
    $active      = $active === '' ? '1' : $active;
    $wp_user     = $user_id ? get_user_by( 'id', $user_id ) : null;
    ?>
    <table class="form-table">
        <tr>
            <th><label for="us_email">Email</label></th>
            <td>
                <input type="email" id="us_email" name="us_email"
                       value="<?php echo esc_attr( $email ); ?>" class="regular-text" />
                <p class="description">A WordPress account will be created automatically when you save.</p>
            </td>
        </tr>
        <tr>
            <th><label for="us_phone">Phone</label></th>
            <td>
                <input type="text" id="us_phone" name="us_phone"
                       value="<?php echo esc_attr( $phone ); ?>" class="regular-text" />
            </td>
        </tr>
        <tr>
            <th>WordPress account</th>
            <td>
                <?php if ( $wp_user ) : ?>
                    <span class="us-admin-meta__linked">&#10003; Linked</span> —
                    <?php echo esc_html( $wp_user->display_name . ' (' . $wp_user->user_email . ')' ); ?>
                    <a href="<?php echo admin_url( 'user-edit.php?user_id=' . $user_id ); ?>"
                       class="us-admin-meta__user-link">Edit user</a>
                <?php else : ?>
                    <span class="us-admin-meta__unlinked">Will be created automatically on save</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><label for="us_active">Status</label></th>
            <td>
                <select id="us_active" name="us_active">
                    <option value="1" <?php selected( $active, '1' ); ?>>Active</option>
                    <option value="0" <?php selected( $active, '0' ); ?>>Inactive</option>
                </select>
                <p class="description">Inactive umpires won't appear in assignment dropdowns.</p>
            </td>
        </tr>
        <tr>
            <th>Allocator access</th>
            <td>
                <label class="us-admin-meta__checkbox-label">
                    <input type="checkbox" id="us_is_assignor" name="us_is_assignor" value="1"
                           <?php checked( $is_assignor ); ?>
                           class="us-admin-meta__checkbox" />
                    <strong>This umpire is an allocator</strong>
                </label>
                <p class="description">
                    Grants access to the allocator dashboard on the front end — requests inbox,
                    unassigned games, and assignment tools. The umpire retains full access to
                    their own umpire dashboard as well.
                </p>
                <?php if ( $is_assignor && $wp_user ) : ?>
                    <p class="us-admin-meta__allocator-confirmed">
                        &#10003; <?php echo esc_html( $wp_user->display_name ); ?> currently has allocator access.
                    </p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><label for="us_notes">Notes</label></th>
            <td>
                <textarea id="us_notes" name="us_notes" rows="4"
                          class="large-text"><?php echo esc_textarea( $notes ); ?></textarea>
                <p class="description">Internal notes — not visible to the umpire.</p>
            </td>
        </tr>
    </table>
    <?php
}

// ── Stats sidebar meta box ────────────────────────────────────
function us_umpire_stats_cb( $post ) {
    $umpire_id = $post->ID;

    $all = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_umpire_id', 'value' => $umpire_id, 'compare' => '=' ],
        ],
    ] );

    $assigned  = count( $all );
    $confirmed = 0;
    $no_shows  = 0;
    $declined  = 0;
    $total_pay = 0;

    foreach ( $all as $a ) {
        $status = get_post_meta( $a->ID, 'us_status',     true );
        $pay    = get_post_meta( $a->ID, 'us_pay_amount', true );

        if ( $status === 'confirmed' ) {
            $confirmed++;
            $total_pay += floatval( $pay );
        } elseif ( $status === 'no-show' ) {
            $no_shows++;
        } elseif ( $status === 'declined' ) {
            $declined++;
        }
    }

    $raw            = get_post_meta( $umpire_id, 'us_unavailable_dates', true );
    $unavail        = is_array( $raw ) ? $raw : ( is_string( $raw ) && ! empty( $raw ) ? json_decode( $raw, true ) : [] );
    $unavail        = is_array( $unavail ) ? $unavail : [];
    $today          = current_time( 'Y-m-d' );
    $future_unavail = array_values( array_filter( $unavail, fn( $d ) => $d >= $today ) );
    sort( $future_unavail );

    $is_assignor = get_post_meta( $umpire_id, 'us_is_assignor', true ) === '1';
    ?>
    <table class="form-table us-admin-meta__stats-table">
        <tr>
            <th>Assigned</th>
            <td><?php echo $assigned; ?></td>
        </tr>
        <tr>
            <th>Confirmed</th>
            <td><?php echo $confirmed; ?></td>
        </tr>
        <tr>
            <th>No-shows</th>
            <td>
                <?php echo $no_shows > 0
                    ? '<span class="us-admin-meta__stat--danger">' . $no_shows . '</span>'
                    : '0';
                ?>
            </td>
        </tr>
        <tr>
            <th>Declined</th>
            <td><?php echo $declined; ?></td>
        </tr>
        <tr>
            <th>Total pay earned</th>
            <td><strong>$<?php echo number_format( $total_pay, 2 ); ?></strong></td>
        </tr>
        <tr>
            <th>Role</th>
            <td>
                <?php if ( $is_assignor ) : ?>
                    <span class="us-admin-meta__role-badge us-admin-meta__role-badge--allocator">
                        Allocator + Umpire
                    </span>
                <?php else : ?>
                    <span class="us-admin-meta__role-badge us-admin-meta__role-badge--umpire">
                        Umpire
                    </span>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <hr class="us-admin-meta__divider">
    <p class="us-admin-meta__unavail-heading">Upcoming unavailable dates:</p>
    <?php if ( ! empty( $future_unavail ) ) : ?>
        <ul class="us-admin-meta__unavail-list">
            <?php foreach ( $future_unavail as $d ) : ?>
                <li><?php echo esc_html( date( 'M j, Y', strtotime( $d ) ) ); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php else : ?>
        <p class="us-admin-meta__unavail-empty">No upcoming unavailable dates.</p>
    <?php endif; ?>
    <?php
}

// ── Save umpire meta ──────────────────────────────────────────
add_action( 'save_post_' . US_PT_UMPIRE, 'us_save_umpire_meta' );
function us_save_umpire_meta( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! isset( $_POST['us_umpire_nonce'] ) || ! wp_verify_nonce( $_POST['us_umpire_nonce'], 'us_umpire_meta' ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $email       = sanitize_email(          $_POST['us_email']  ?? '' );
    $phone       = sanitize_text_field(     $_POST['us_phone']  ?? '' );
    $active      = sanitize_text_field(     $_POST['us_active'] ?? '1' );
    $notes       = sanitize_textarea_field( $_POST['us_notes']  ?? '' );
    $is_assignor = isset( $_POST['us_is_assignor'] ) ? '1' : '0';
    $name        = get_the_title( $post_id );

    update_post_meta( $post_id, 'us_email',       $email );
    update_post_meta( $post_id, 'us_phone',       $phone );
    update_post_meta( $post_id, 'us_active',      $active );
    update_post_meta( $post_id, 'us_notes',       $notes );
    update_post_meta( $post_id, 'us_is_assignor', $is_assignor );

    // Sync the us_assignor WP role to match the checkbox
    $linked_user_id = get_post_meta( $post_id, 'us_wp_user_id', true );
    if ( $linked_user_id ) {
        $wp_user = get_user_by( 'id', $linked_user_id );
        if ( $wp_user ) {
            if ( $is_assignor === '1' ) {
                $wp_user->add_role( US_ROLE_ASSIGNOR );
            } else {
                $wp_user->remove_role( US_ROLE_ASSIGNOR );
            }
        }
    }

    // Auto-create WP user if email is set and no user is linked yet
    $existing_user_id = get_post_meta( $post_id, 'us_wp_user_id', true );

    if ( $email && ! $existing_user_id ) {
        $user = get_user_by( 'email', $email );

        if ( $user ) {
            update_post_meta( $post_id, 'us_wp_user_id', $user->ID );
            $user->add_role( US_ROLE_UMPIRE );
            if ( $is_assignor === '1' ) {
                $user->add_role( US_ROLE_ASSIGNOR );
            }
        } else {
            $username      = sanitize_user( strtolower( str_replace( ' ', '.', $name ) ) );
            $base_username = $username;
            $counter       = 1;
            while ( username_exists( $username ) ) {
                $username = $base_username . $counter;
                $counter++;
            }

            $user_id = wp_insert_user( [
                'user_login'   => $username,
                'user_email'   => $email,
                'display_name' => $name,
                'first_name'   => strstr( $name, ' ', true ) ?: $name,
                'last_name'    => strstr( $name, ' ' ) ? trim( strstr( $name, ' ' ) ) : '',
                'role'         => US_ROLE_UMPIRE,
            ] );

            if ( ! is_wp_error( $user_id ) ) {
                update_post_meta( $post_id, 'us_wp_user_id', $user_id );
                if ( $is_assignor === '1' ) {
                    $new_user = get_user_by( 'id', $user_id );
                    if ( $new_user ) $new_user->add_role( US_ROLE_ASSIGNOR );
                }
                wp_send_new_user_notifications( $user_id, 'user' );
            }
        }
    }
}

// ── Game history meta box ─────────────────────────────────────
function us_umpire_history_cb( $post ) {
    $umpire_id = $post->ID;

    $assignments = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_umpire_id', 'value' => $umpire_id, 'compare' => '=' ],
        ],
    ] );

    if ( empty( $assignments ) ) {
        echo '<p class="us-admin-meta__empty">No games on record for this umpire.</p>';
        return;
    }

    $by_league = [];
    foreach ( $assignments as $a ) {
        $game_id   = get_post_meta( $a->ID, 'us_game_id',     true );
        $league_id = get_post_meta( $game_id, 'us_league_id', true );
        if ( ! $league_id ) $league_id = 'unknown';
        $by_league[ $league_id ][] = $a;
    }

    foreach ( $by_league as $lid => $games ) {
        usort( $by_league[ $lid ], function( $a, $b ) {
            $date_a = get_post_meta( get_post_meta( $a->ID, 'us_game_id', true ), 'us_game_date', true );
            $date_b = get_post_meta( get_post_meta( $b->ID, 'us_game_id', true ), 'us_game_date', true );
            return strcmp( $date_a, $date_b );
        } );
    }

    $league_ids = array_keys( $by_league );
    $first      = true;

    $status_colors = [
        'confirmed' => '#00a32a',
        'pending'   => '#dba617',
        'requested' => '#0073aa',
        'declined'  => '#d63638',
        'no-show'   => '#d63638',
    ];
    ?>
    <style>
        .us-tabs                  { display:flex; gap:0; border-bottom:1px solid #ddd; margin-bottom:16px; flex-wrap:wrap; }
        .us-tab-btn               { padding:8px 16px; border:none; background:none; cursor:pointer; font-size:13px; color:#666; border-bottom:2px solid transparent; margin-bottom:-1px; }
        .us-tab-btn.active        { color:#091b33; border-bottom-color:#598cb9; font-weight:600; }
        .us-tab-panel             { display:none; }
        .us-tab-panel.active      { display:block; }
        .us-history-table         { width:100%; border-collapse:collapse; font-size:13px; }
        .us-history-table th      { text-align:left; padding:6px 10px; background:#f0f0f0; border-bottom:1px solid #ddd; font-size:12px; color:#444; }
        .us-history-table td      { padding:6px 10px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
        .us-history-table tr:last-child td { border-bottom:none; }
        .us-history-table tbody tr:hover   { background:#fafafa; }
        .us-history-total         { background:#f9f9f9; font-weight:600; border-top:2px solid #ddd !important; }
    </style>

    <div class="us-tabs" id="us-umpire-tabs">
        <?php foreach ( $league_ids as $lid ) :
            $label = $lid === 'unknown' ? 'Unknown League' : get_the_title( $lid );
        ?>
            <button type="button"
                    class="us-tab-btn <?php echo $first ? 'active' : ''; ?>"
                    data-tab="us-tab-<?php echo $lid; ?>">
                <?php echo esc_html( $label ); ?> (<?php echo count( $by_league[ $lid ] ); ?>)
            </button>
        <?php $first = false; endforeach; ?>
    </div>

    <?php $first = true;
    foreach ( $by_league as $lid => $games ) :
        $league_name = $lid === 'unknown' ? 'Unknown League' : get_the_title( $lid );
        $total_pay   = 0;
        $worked      = 0;
    ?>
    <div class="us-tab-panel <?php echo $first ? 'active' : ''; ?>" id="us-tab-<?php echo $lid; ?>">
        <table class="us-history-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Game</th>
                    <th>Field</th>
                    <th>Position</th>
                    <th>Status</th>
                    <th>Pay</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $games as $a ) :
                    $game_id  = get_post_meta( $a->ID, 'us_game_id',    true );
                    $position = get_post_meta( $a->ID, 'us_position',   true );
                    $status   = get_post_meta( $a->ID, 'us_status',     true );
                    $pay      = floatval( get_post_meta( $a->ID, 'us_pay_amount', true ) );
                    $date     = get_post_meta( $game_id, 'us_game_date', true );
                    $time     = get_post_meta( $game_id, 'us_game_time', true );
                    $home     = get_post_meta( $game_id, 'us_home_team', true );
                    $away     = get_post_meta( $game_id, 'us_away_team', true );
                    $field    = get_post_meta( $game_id, 'us_field',     true );
                    $color    = $status_colors[ $status ] ?? '#666';

                    if ( $status === 'confirmed' ) {
                        $total_pay += $pay;
                        $worked++;
                    }
                ?>
                <tr>
                    <td><?php echo $date ? esc_html( date( 'M j, Y', strtotime( $date ) ) ) : '—'; ?></td>
                    <td><?php echo $time ? esc_html( date( 'g:i a', strtotime( $time ) ) )  : '—'; ?></td>
                    <td><?php echo esc_html( $away . ' at ' . $home ); ?></td>
                    <td><?php echo esc_html( $field ); ?></td>
                    <td><?php echo esc_html( ucfirst( $position ) ); ?></td>
                    <td style="color:<?php echo $color; ?>;font-weight:500"><?php echo esc_html( ucfirst( $status ) ); ?></td>
                    <td><?php echo $status === 'confirmed' ? '$' . number_format( $pay, 2 ) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="us-history-total">
                    <td colspan="5"><?php echo $worked; ?> games worked in <?php echo esc_html( $league_name ); ?></td>
                    <td></td>
                    <td>$<?php echo number_format( $total_pay, 2 ); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php $first = false; endforeach; ?>

    <script>
    (function(){
        var tabs     = document.querySelectorAll('#us-umpire-tabs .us-tab-btn');
        var storeKey = 'us_umpire_tab_<?php echo $post->ID; ?>';

        var saved = localStorage.getItem( storeKey );
        if ( saved ) {
            var savedPanel = document.getElementById( saved );
            if ( savedPanel ) {
                tabs.forEach( function(b){ b.classList.remove('active'); } );
                document.querySelectorAll('.us-tab-panel').forEach( function(p){ p.classList.remove('active'); } );
                savedPanel.classList.add('active');
                tabs.forEach( function(b){ if ( b.dataset.tab === saved ) b.classList.add('active'); } );
            }
        }

        tabs.forEach( function(btn){
            btn.addEventListener('click', function(){
                tabs.forEach( function(b){ b.classList.remove('active'); } );
                document.querySelectorAll('.us-tab-panel').forEach( function(p){ p.classList.remove('active'); } );
                btn.classList.add('active');
                document.getElementById( btn.dataset.tab ).classList.add('active');
                localStorage.setItem( storeKey, btn.dataset.tab );
            });
        });
    })();
    </script>
    <?php
}