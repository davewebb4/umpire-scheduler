<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Broadcast menu ────────────────────────────────────────────
add_action( 'admin_menu', 'us_broadcast_menu', 45 );
function us_broadcast_menu() {
    add_submenu_page(
        'umpire-scheduler',
        'Broadcast Message',
        'Broadcast Message',
        US_CAP_BROADCAST,
        'us-broadcast',
        'us_broadcast_page'
    );
}

// ── Broadcast page ────────────────────────────────────────────
function us_broadcast_page() {
    if ( ! current_user_can( 'manage_options' ) && ! us_is_allocator() ) {
        wp_die( 'You do not have permission to access this page.' );
    }

    $leagues = get_posts( [
        'post_type'   => US_PT_LEAGUE,
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'post_status' => 'publish',
    ] );

    $filter_league = isset( $_POST['us_broadcast_league'] ) ? absint( $_POST['us_broadcast_league'] ) : 0;
    $recipients    = us_get_broadcast_recipients( $filter_league );

    if ( isset( $_POST['us_broadcast_send'] ) ) {
        us_handle_broadcast( $recipients );
    }
    ?>
    <div class="wrap">
        <h1>Broadcast Message</h1>
        <p class="us-broadcast-intro">Send a message to all active umpires or filter by league. Each umpire receives the email individually — addresses are never shared.</p>

        <?php if ( isset( $_GET['us_broadcast_notice'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p>Message sent to <strong><?php echo absint( $_GET['us_broadcast_notice'] ); ?> umpire(s)</strong> successfully.</p>
            </div>
        <?php endif; ?>

        <div class="us-broadcast-layout">

            <!-- ── Message form ────────────────────────────── -->
            <div class="us-broadcast-form-wrap">
                <form method="post">
                    <?php wp_nonce_field( 'us_broadcast', 'us_broadcast_nonce' ); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="us_broadcast_league">Send to</label></th>
                            <td>
                                <select name="us_broadcast_league" id="us_broadcast_league"
                                        onchange="this.form.submit()"
                                        class="us-broadcast-select">
                                    <option value="0">All active umpires</option>
                                    <?php foreach ( $leagues as $l ) : ?>
                                        <option value="<?php echo $l->ID; ?>" <?php selected( $filter_league, $l->ID ); ?>>
                                            <?php echo esc_html( $l->post_title ); ?> umpires only
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php echo $filter_league ? 'Umpires who have assignments in this league.' : 'All active umpires in the system.'; ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="us_broadcast_subject">Subject</label></th>
                            <td>
                                <input type="text"
                                       id="us_broadcast_subject"
                                       name="us_broadcast_subject"
                                       class="large-text"
                                       required
                                       placeholder="e.g. Games available for the week of May 5"
                                       value="<?php echo esc_attr( $_POST['us_broadcast_subject'] ?? '' ); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th><label for="us_broadcast_message">Message</label></th>
                            <td>
                                <?php
                                $content = $_POST['us_broadcast_message'] ?? '';
                                wp_editor( $content, 'us_broadcast_message', [
                                    'media_buttons' => false,
                                    'textarea_rows' => 12,
                                    'teeny'         => true,
                                    'quicktags'     => true,
                                    'tinymce'       => [
                                        'toolbar1' => 'bold,italic,bullist,numlist,link,unlink,undo,redo',
                                        'toolbar2' => '',
                                    ],
                                ] );
                                ?>
                                <p class="description">Supports bold, italic and lists. A sign-off and link to the scheduler will be added automatically.</p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit us-broadcast-submit">
                        <input type="submit"
                               name="us_broadcast_send"
                               class="button button-primary button-large"
                               value="Send to <?php echo count( $recipients ); ?> umpire(s)"
                               <?php echo empty( $recipients ) ? 'disabled' : ''; ?>
                               onclick="return confirm('Send this message to <?php echo count( $recipients ); ?> umpire(s)?')">
                        <?php if ( empty( $recipients ) ) : ?>
                            <span class="us-broadcast-no-recipients">No umpires found for the selected filter.</span>
                        <?php endif; ?>
                    </p>
                </form>
            </div>

            <!-- ── Recipients preview ──────────────────────── -->
            <div class="us-broadcast-recipients">
                <h3 class="us-broadcast-recipients__heading">
                    Recipients
                    <span class="us-broadcast-recipients__count"><?php echo count( $recipients ); ?></span>
                </h3>

                <?php if ( empty( $recipients ) ) : ?>
                    <p class="us-broadcast-recipients__empty">No umpires match the selected filter.</p>
                <?php else : ?>
                    <ul class="us-broadcast-recipients__list">
                        <?php foreach ( $recipients as $r ) :
                            $parts    = explode( ' ', $r['name'] );
                            $initials = strtoupper( substr( $parts[0], 0, 1 ) . ( isset( $parts[1] ) ? substr( $parts[1], 0, 1 ) : '' ) );
                        ?>
                            <li class="us-broadcast-recipients__item">
                                <span class="us-broadcast-recipients__avatar"><?php echo $initials; ?></span>
                                <span class="us-broadcast-recipients__info">
                                    <strong><?php echo esc_html( $r['name'] ); ?></strong>
                                    <span class="us-broadcast-recipients__email"><?php echo esc_html( $r['email'] ); ?></span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <style>
        .us-broadcast-intro  { color: #666; margin-bottom: 24px; }

        .us-broadcast-layout {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 24px;
            align-items: start;
        }

        .us-broadcast-select   { min-width: 220px; }
        .us-broadcast-submit   { display: flex; align-items: center; gap: 12px; }
        .us-broadcast-no-recipients { color: #d63638; font-size: 13px; }

        .us-broadcast-recipients {
            background: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        }

        .us-broadcast-recipients__heading {
            margin: 0 0 12px;
            font-size: 14px;
            color: #091b33;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .us-broadcast-recipients__count {
            background: #598cb9;
            color: #fff;
            font-size: 11px;
            padding: 2px 7px;
            border-radius: 10px;
            font-weight: 500;
        }

        .us-broadcast-recipients__empty { color: #666; font-size: 13px; }

        .us-broadcast-recipients__list  { margin: 0; padding: 0; list-style: none; }

        .us-broadcast-recipients__item {
            padding: 6px 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .us-broadcast-recipients__item:last-child { border-bottom: none; }

        .us-broadcast-recipients__avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #091b33;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .us-broadcast-recipients__info  { display: flex; flex-direction: column; }
        .us-broadcast-recipients__email { color: #666; font-size: 11px; }
    </style>
    <?php
}

// ── Get broadcast recipients ──────────────────────────────────
function us_get_broadcast_recipients( $filter_league = 0 ) {
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

    $recipients = [];
    foreach ( $umpires as $umpire ) {
        $email = get_post_meta( $umpire->ID, 'us_email', true );
        if ( ! $email ) continue;
        if ( $filter_league && ! us_umpire_has_league( $umpire->ID, $filter_league ) ) continue;
        $recipients[] = [
            'name'  => $umpire->post_title,
            'email' => $email,
            'id'    => $umpire->ID,
        ];
    }

    return $recipients;
}

// ── Check if umpire has games in a league ─────────────────────
function us_umpire_has_league( $umpire_id, $league_id ) {
    $assignments = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_umpire_id', 'value' => $umpire_id, 'compare' => '=' ],
        ],
    ] );

    foreach ( $assignments as $a ) {
        $game_id     = get_post_meta( $a->ID, 'us_game_id',     true );
        $game_league = get_post_meta( $game_id, 'us_league_id', true );
        if ( absint( $game_league ) === absint( $league_id ) ) return true;
    }

    return false;
}

// ── Handle broadcast send ─────────────────────────────────────
function us_handle_broadcast( $recipients ) {
    if ( ! isset( $_POST['us_broadcast_nonce'] ) || ! wp_verify_nonce( $_POST['us_broadcast_nonce'], 'us_broadcast' ) ) {
        echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
        return;
    }

    if ( ! current_user_can( 'manage_options' ) && ! us_is_allocator() ) return;

    $subject = sanitize_text_field( $_POST['us_broadcast_subject'] ?? '' );
    $message = wp_kses_post( $_POST['us_broadcast_message']        ?? '' );

    if ( ! $subject || ! $message ) {
        echo '<div class="notice notice-error"><p>Please fill in both subject and message.</p></div>';
        return;
    }

    $sent       = 0;
    $from_name  = us_setting( 'assignor_name' )  ?: get_bloginfo( 'name' );
    $from_email = us_setting( 'assignor_email' ) ?: get_option( 'admin_email' );
    $site_url   = home_url( '/' . us_setting( 'slug_dashboard' ) . '/' );

    foreach ( $recipients as $r ) {
        $body  = '<html><body>';
        $body .= '<p>Hi ' . esc_html( $r['name'] ) . ',</p>';
        $body .= $message;
        $body .= '<hr style="margin:24px 0;border:none;border-top:1px solid #eee">';
        $body .= '<p style="font-size:13px;color:#666">Log in to your umpire dashboard: <a href="' . $site_url . '">' . $site_url . '</a></p>';
        $body .= '<p style="font-size:13px;color:#666">' . esc_html( us_setting( 'email_footer' ) ) . '</p>';
        $body .= '</body></html>';

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
        ];

        if ( wp_mail( $r['email'], $subject, $body, $headers ) ) $sent++;
    }

    wp_redirect( admin_url( 'admin.php?page=us-broadcast&us_broadcast_notice=' . $sent ) );
    exit;
}