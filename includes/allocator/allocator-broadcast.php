<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'allocator_broadcast', 'us_shortcode_allocator_broadcast' );
function us_shortcode_allocator_broadcast() {
    if ( ! is_user_logged_in() ) return us_login_form();

    if ( ! us_is_allocator() ) {
        return '<script>window.location="' . esc_url( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) ) . '";</script>';
    }

    $base_url      = home_url( '/' . us_setting( 'slug_allocator_broadcast' ) . '/' );
    $filter_league = isset( $_POST['us_broadcast_league'] ) ? absint( $_POST['us_broadcast_league'] ) : 0;
    $recipients    = us_get_broadcast_recipients( $filter_league );

    if ( isset( $_POST['us_broadcast_send'] ) ) {
        us_handle_broadcast_front( $recipients, $base_url );
    }

    $leagues = us_get_active_leagues();

    ob_start();
    ?>
    <div class="us-dashboard">

        <div class="us-alloc-dashboard__header">
            <div>
                <h2>Broadcast Message</h2>
                <p class="us-alloc-dashboard__date">Send a message to all active umpires or filter by league.</p>
            </div>
            <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_allocator_dashboard' ) . '/' ) ); ?>"
               class="us-btn us-btn-request us-btn--sm">&larr; Dashboard</a>
        </div>

        <?php if ( isset( $_GET['us_broadcast_notice'] ) ) : ?>
            <div class="us-notice us-notice-success">
                Message sent to <strong><?php echo absint( $_GET['us_broadcast_notice'] ); ?> umpire(s)</strong> successfully.
            </div>
        <?php endif; ?>

        <div class="us-broadcast-layout">

            <div class="us-broadcast-form-wrap">
                <form method="post">
                    <?php wp_nonce_field( 'us_broadcast', 'us_broadcast_nonce' ); ?>

                    <div class="us-broadcast-field">
                        <label for="us_broadcast_league">Send to</label>
                        <select name="us_broadcast_league" id="us_broadcast_league"
                                class="us-broadcast-select"
                                onchange="this.form.submit()">
                            <option value="0">All active umpires</option>
                            <?php foreach ( $leagues as $l ) : ?>
                                <option value="<?php echo $l->ID; ?>" <?php selected( $filter_league, $l->ID ); ?>>
                                    <?php echo esc_html( $l->post_title ); ?> umpires only
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="us-broadcast-field">
                        <label for="us_broadcast_subject">Subject</label>
                        <input type="text"
                               id="us_broadcast_subject"
                               name="us_broadcast_subject"
                               class="us-broadcast-input"
                               required
                               placeholder="e.g. Games available for the week of May 5"
                               value="<?php echo esc_attr( $_POST['us_broadcast_subject'] ?? '' ); ?>" />
                    </div>

                    <div class="us-broadcast-field">
                        <label for="us_broadcast_message">Message</label>
                        <textarea id="us_broadcast_message"
                                  name="us_broadcast_message"
                                  class="us-broadcast-textarea"
                                  rows="10"
                                  required
                                  placeholder="Write your message here..."><?php echo esc_textarea( $_POST['us_broadcast_message'] ?? '' ); ?></textarea>
                        <p class="us-broadcast-hint">A sign-off and link to the scheduler will be added automatically.</p>
                    </div>

                    <button type="submit"
                            name="us_broadcast_send"
                            class="us-btn us-btn-confirm"
                            <?php echo empty( $recipients ) ? 'disabled' : ''; ?>
                            onclick="return confirm('Send this message to <?php echo count( $recipients ); ?> umpire(s)?')">
                        Send to <?php echo count( $recipients ); ?> umpire(s)
                    </button>

                    <?php if ( empty( $recipients ) ) : ?>
                        <p class="us-broadcast-no-recipients">No umpires found for the selected filter.</p>
                    <?php endif; ?>

                </form>
            </div>

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
    <?php
    return ob_get_clean();
}

// ── Handle front-end broadcast send ──────────────────────────
function us_handle_broadcast_front( $recipients, $base_url ) {
    if ( ! isset( $_POST['us_broadcast_nonce'] ) || ! wp_verify_nonce( $_POST['us_broadcast_nonce'], 'us_broadcast' ) ) return;
    if ( ! us_is_allocator() ) return;

    $subject = sanitize_text_field( $_POST['us_broadcast_subject'] ?? '' );
    $message = nl2br( esc_html( $_POST['us_broadcast_message'] ?? '' ) );

    if ( ! $subject || ! $message ) return;

    $sent       = 0;
    $from_name  = us_setting( 'assignor_name' )  ?: get_bloginfo( 'name' );
    $from_email = us_setting( 'assignor_email' ) ?: get_option( 'admin_email' );
    $site_url   = home_url( '/' . us_setting( 'slug_dashboard' ) . '/' );

    foreach ( $recipients as $r ) {
        $body  = '<html><body>';
        $body .= '<p>Hi ' . esc_html( $r['name'] ) . ',</p>';
        $body .= '<p>' . $message . '</p>';
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

    wp_redirect( add_query_arg( 'us_broadcast_notice', $sent, $base_url ) );
    exit;
}
