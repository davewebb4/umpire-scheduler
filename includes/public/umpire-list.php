<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'umpire_list', 'us_shortcode_umpire_list' );
function us_shortcode_umpire_list() {

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

    if ( empty( $umpires ) ) {
        return '<p class="us-empty">No umpires found.</p>';
    }

    $is_logged_in = is_user_logged_in();

    $contact_notice = '';
    if ( $is_logged_in && isset( $_POST['us_contact_submit'] ) ) {
        $contact_notice = us_handle_umpire_contact();
    }

    ob_start();
    ?>
    <div class="us-dashboard">
        <h2>Umpire List</h2>

        <?php if ( $contact_notice ) : ?>
            <div class="us-notice us-notice-<?php echo $contact_notice['class']; ?>">
                <?php echo esc_html( $contact_notice['msg'] ); ?>
            </div>
        <?php endif; ?>

        <?php if ( ! $is_logged_in ) : ?>
            <p class="us-umpire-list__login-notice">
                <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) ); ?>"
                   class="us-link">Log in</a>
                to view contact details and send messages.
            </p>
        <?php endif; ?>

        <div class="us-umpire-grid">
            <?php foreach ( $umpires as $umpire ) :
                $email = get_post_meta( $umpire->ID, 'us_email', true );
                $phone = get_post_meta( $umpire->ID, 'us_phone', true );

                $assignments = get_posts( [
                    'post_type'   => US_PT_ASSIGNMENT,
                    'numberposts' => -1,
                    'post_status' => 'publish',
                    'meta_query'  => [
                        [ 'key' => 'us_umpire_id', 'value' => $umpire->ID, 'compare' => '=' ],
                        [ 'key' => 'us_status',    'value' => 'confirmed',  'compare' => '=' ],
                    ],
                ] );
                $games_worked = count( $assignments );

                $parts    = explode( ' ', trim( $umpire->post_title ) );
                $initials = strtoupper( substr( $parts[0], 0, 1 ) . ( isset( $parts[1] ) ? substr( $parts[1], 0, 1 ) : '' ) );
            ?>
            <div class="us-umpire-card">
                <div class="us-umpire-avatar"><?php echo esc_html( $initials ); ?></div>
                <div class="us-umpire-info">
                    <div class="us-umpire-name"><?php echo esc_html( $umpire->post_title ); ?></div>
                    <div class="us-umpire-games"><?php echo $games_worked; ?> games worked</div>

                    <?php if ( $is_logged_in ) : ?>
                        <div class="us-umpire-contact-details">
                            <?php if ( $email ) : ?>
                                <div class="us-umpire-detail">
                                    <span class="us-umpire-detail-label">Email</span>
                                    <span><?php echo esc_html( $email ); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ( $phone ) : ?>
                                <div class="us-umpire-detail">
                                    <span class="us-umpire-detail-label">Phone</span>
                                    <span><?php echo esc_html( $phone ); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ( $email ) : ?>
                        <button class="us-btn us-btn-request us-contact-toggle us-contact-toggle--sm"
                                data-umpire="<?php echo $umpire->ID; ?>">
                            Send message
                        </button>

                        <div class="us-contact-form" id="us-contact-<?php echo $umpire->ID; ?>" hidden>
                            <form method="post">
                                <?php wp_nonce_field( 'us_contact_umpire', 'us_contact_nonce' ); ?>
                                <input type="hidden" name="us_contact_umpire_id" value="<?php echo $umpire->ID; ?>">
                                <div class="us-form-group">
                                    <label>Subject</label>
                                    <input type="text" name="us_contact_subject" required
                                           placeholder="e.g. Game swap request" />
                                </div>
                                <div class="us-form-group">
                                    <label>Message</label>
                                    <textarea name="us_contact_message" rows="4" required
                                              class="us-contact-form__textarea"
                                              placeholder="Write your message here..."></textarea>
                                </div>
                                <div class="us-contact-form__actions">
                                    <button type="submit" name="us_contact_submit"
                                            class="us-btn us-btn-confirm">Send</button>
                                    <button type="button"
                                            class="us-btn us-btn--muted us-contact-cancel"
                                            data-umpire="<?php echo $umpire->ID; ?>">Cancel</button>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
    return ob_get_clean();
}

// ── Handle contact form submission ────────────────────────────
function us_handle_umpire_contact() {
    if ( ! isset( $_POST['us_contact_nonce'] ) || ! wp_verify_nonce( $_POST['us_contact_nonce'], 'us_contact_umpire' ) ) {
        return [ 'class' => 'error', 'msg' => 'Security check failed. Please try again.' ];
    }

    $umpire_id = absint( $_POST['us_contact_umpire_id'] ?? 0 );
    $subject   = sanitize_text_field( $_POST['us_contact_subject']     ?? '' );
    $message   = sanitize_textarea_field( $_POST['us_contact_message'] ?? '' );

    if ( ! $umpire_id || ! $subject || ! $message ) {
        return [ 'class' => 'error', 'msg' => 'Please fill in all fields.' ];
    }

    $to_email = get_post_meta( $umpire_id, 'us_email', true );
    if ( ! $to_email ) {
        return [ 'class' => 'error', 'msg' => 'Could not find contact email for this umpire.' ];
    }

    $sender_id    = get_current_user_id();
    $sender       = us_get_umpire_by_user( $sender_id );
    $sender_name  = $sender ? $sender->post_title : wp_get_current_user()->display_name;
    $sender_email = wp_get_current_user()->user_email;
    $to_name      = get_the_title( $umpire_id );

    $full_subject  = 'Message from ' . $sender_name . ' — ' . $subject;
    $full_message  = "Hi {$to_name},\n\n";
    $full_message .= "You have received a message from {$sender_name}:\n\n";
    $full_message .= "---\n";
    $full_message .= $message . "\n";
    $full_message .= "---\n\n";
    $full_message .= "You can reply directly to this email to respond.\n\n";
    $full_message .= "Thanks,\n" . us_setting( 'email_footer' );

    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        'Reply-To: ' . $sender_name . ' <' . $sender_email . '>',
    ];

    $sent = wp_mail( $to_email, $full_subject, $full_message, $headers );

    return $sent
        ? [ 'class' => 'success', 'msg' => 'Message sent to ' . $to_name . ' successfully!' ]
        : [ 'class' => 'error',   'msg' => 'Message could not be sent. Please try again.' ];
}