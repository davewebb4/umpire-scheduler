<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'umpire_register', 'us_shortcode_umpire_register' );
function us_shortcode_umpire_register() {
    if ( is_user_logged_in() ) {
        return '<script>window.location="' . esc_url( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) ) . '";</script>';
    }

    $error   = '';
    $success = false;

    if ( isset( $_POST['us_register_submit'] ) ) {
        if ( ! isset( $_POST['us_register_nonce'] ) || ! wp_verify_nonce( $_POST['us_register_nonce'], 'us_register' ) ) {
            $error = 'Security check failed. Please try again.';
        } else {
            $result = us_handle_registration();
            if ( is_wp_error( $result ) ) {
                $error = $result->get_error_message();
            } else {
                // Log in and redirect to dashboard
                wp_set_current_user( $result );
                wp_set_auth_cookie( $result );
                wp_set_current_user( $result );
                echo '<script>window.location="' . esc_url( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) ) . '";</script>';
                exit;
            }
        }
    }

    $first_name = sanitize_text_field( $_POST['us_first_name'] ?? '' );
    $last_name  = sanitize_text_field( $_POST['us_last_name']  ?? '' );
    $email      = sanitize_email(      $_POST['us_reg_email']  ?? '' );
    $phone      = sanitize_text_field( $_POST['us_phone']      ?? '' );

    ob_start();
    ?>
    <div class="us-login-wrap">
        <div class="us-login-card us-login-card--register">
            <?php
            $logo_id  = get_theme_mod( 'custom_logo' );
            $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
            ?>
            <div class="us-login-logo">
                <?php if ( $logo_url ) : ?>
                    <img src="<?php echo esc_url( $logo_url ); ?>"
                         alt="<?php echo esc_attr( us_setting( 'org_short' ) ); ?>"
                         class="us-login-logo__img">
                <?php else : ?>
                    <?php echo esc_html( us_setting( 'org_short' ) ); ?>
                <?php endif; ?>
            </div>

            <p class="us-login-sub">Create your umpire account</p>

            <?php if ( $error ) : ?>
                <div class="us-notice us-notice-error"><?php echo esc_html( $error ); ?></div>
            <?php endif; ?>

            <form method="post" class="us-login-form">
                <?php wp_nonce_field( 'us_register', 'us_register_nonce' ); ?>

                <div class="us-input-row">
                    <div class="us-form-group">
                        <label for="us_first_name">First name</label>
                        <input type="text" id="us_first_name" name="us_first_name"
                               value="<?php echo esc_attr( $first_name ); ?>"
                               required autocomplete="given-name" />
                    </div>
                    <div class="us-form-group">
                        <label for="us_last_name">Last name</label>
                        <input type="text" id="us_last_name" name="us_last_name"
                               value="<?php echo esc_attr( $last_name ); ?>"
                               required autocomplete="family-name" />
                    </div>
                </div>

                <div class="us-form-group">
                    <label for="us_reg_email">Email address</label>
                    <input type="email" id="us_reg_email" name="us_reg_email"
                           value="<?php echo esc_attr( $email ); ?>"
                           required autocomplete="email" />
                </div>

                <div class="us-form-group">
                    <label for="us_phone">Phone number <span style="font-weight:400;color:#999">(optional)</span></label>
                    <input type="tel" id="us_phone" name="us_phone"
                           value="<?php echo esc_attr( $phone ); ?>"
                           autocomplete="tel" />
                </div>

                <div class="us-form-group">
                    <label for="us_reg_pass">Password</label>
                    <input type="password" id="us_reg_pass" name="us_reg_pass"
                           required autocomplete="new-password" minlength="8" />
                </div>

                <div class="us-form-group">
                    <label for="us_reg_pass2">Confirm password</label>
                    <input type="password" id="us_reg_pass2" name="us_reg_pass2"
                           required autocomplete="new-password" minlength="8" />
                </div>

                <button type="submit" name="us_register_submit" class="us-login-btn">
                    Create account
                </button>

                <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) ); ?>"
                   class="us-login-forgot">Already have an account? Sign in</a>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ── Handle registration ───────────────────────────────────────
function us_handle_registration() {
    $first_name = sanitize_text_field( $_POST['us_first_name'] ?? '' );
    $last_name  = sanitize_text_field( $_POST['us_last_name']  ?? '' );
    $email      = sanitize_email(      $_POST['us_reg_email']  ?? '' );
    $phone      = sanitize_text_field( $_POST['us_phone']      ?? '' );
    $password   = $_POST['us_reg_pass']  ?? '';
    $password2  = $_POST['us_reg_pass2'] ?? '';

    // Validate
    if ( ! $first_name || ! $last_name ) return new WP_Error( 'missing', 'Please enter your first and last name.' );
    if ( ! $email || ! is_email( $email ) ) return new WP_Error( 'email', 'Please enter a valid email address.' );
    if ( strlen( $password ) < 8 ) return new WP_Error( 'password', 'Password must be at least 8 characters.' );
    if ( $password !== $password2 ) return new WP_Error( 'mismatch', 'Passwords do not match.' );
    if ( email_exists( $email ) ) return new WP_Error( 'exists', 'An account with that email already exists.' );

    $full_name = trim( $first_name . ' ' . $last_name );
    $username  = sanitize_user( strtolower( $first_name . '.' . $last_name ), true );

    // Ensure unique username
    $base = $username;
    $i    = 1;
    while ( username_exists( $username ) ) {
        $username = $base . $i;
        $i++;
    }

    // Create WordPress user
    $user_id = wp_create_user( $username, $password, $email );
    if ( is_wp_error( $user_id ) ) return $user_id;

    $user = new WP_User( $user_id );
    $user->set_role( US_ROLE_UMPIRE );
    wp_update_user( [ 'ID' => $user_id, 'display_name' => $full_name, 'first_name' => $first_name, 'last_name' => $last_name ] );

    // Create umpire CPT profile
    $umpire_id = wp_insert_post( [
        'post_type'   => US_PT_UMPIRE,
        'post_title'  => $full_name,
        'post_status' => 'publish',
    ] );

    update_post_meta( $umpire_id, 'us_wp_user_id', $user_id );
    update_post_meta( $umpire_id, 'us_email',      $email );
    update_post_meta( $umpire_id, 'us_phone',      $phone );
    update_post_meta( $umpire_id, 'us_active',     '1' );

    // Notify assignor
    us_notify_assignor_new_umpire( $full_name, $email );

    // Welcome email to umpire
    us_notify_umpire_welcome( $email, $full_name );

    return $user_id;
}
