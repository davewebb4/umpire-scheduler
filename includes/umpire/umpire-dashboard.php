<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Login form ────────────────────────────────────────────────
function us_login_form() {
    $login_error = isset( $_POST['us_login_submit'] ) && ! is_user_logged_in();

    ob_start();
    ?>
    <?php
    $logo_id  = get_theme_mod( 'custom_logo' );
    $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
    ?>
    <div class="us-login-wrap">
        <div class="us-login-card">
            <div class="us-login-logo">
                <?php if ( $logo_url ) : ?>
                    <img src="<?php echo esc_url( $logo_url ); ?>"
                         alt="<?php echo esc_attr( us_setting( 'org_short' ) ); ?>"
                         class="us-login-logo__img">
                <?php else : ?>
                    <?php echo esc_html( us_setting( 'org_short' ) ); ?>
                <?php endif; ?>
            </div>
            <h2 class="us-login-title"><?php echo esc_html( us_setting( 'app_title' ) ); ?></h2>
            <p class="us-login-sub">Sign in to access your schedule</p>

            <?php if ( $login_error ) : ?>
                <div class="us-notice us-notice-error">Invalid username or password. Please try again.</div>
            <?php endif; ?>

            <form method="post" class="us-login-form">
                <?php wp_nonce_field( 'us_login', 'us_login_nonce' ); ?>
                <div class="us-form-group">
                    <label for="us_login_user">Username or email</label>
                    <input type="text" id="us_login_user" name="us_login_user" required autocomplete="username" />
                </div>
                <div class="us-form-group">
                    <label for="us_login_pass">Password</label>
                    <input type="password" id="us_login_pass" name="us_login_pass" required autocomplete="current-password" />
                </div>
                <button type="submit" name="us_login_submit" class="us-login-btn">Sign in</button>
                <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>" class="us-login-forgot">Forgot your password?</a>
            </form>
        </div>
        <p class="us-login-version">v<?php echo US_VERSION; ?></p>
    </div>
    <?php
    return ob_get_clean();
}

// ── Dashboard shortcode ───────────────────────────────────────
add_shortcode( 'umpire_dashboard', 'us_shortcode_dashboard' );
function us_shortcode_dashboard() {
    if ( ! is_user_logged_in() ) return us_login_form();

    $umpire = us_get_umpire_by_user( get_current_user_id() );
    if ( ! $umpire ) return '<p class="us-empty">No umpire profile found. Please contact the assignor.</p>';

    $umpire_id = $umpire->ID;
    $today     = current_time( 'Y-m-d' );
    $in7days   = date( 'Y-m-d', strtotime( '+7 days' ) );
    $month_key = date( 'Y-m' );

    $all_assignments = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_umpire_id', 'value' => $umpire_id, 'compare' => '=' ],
        ],
    ] );

    $upcoming        = [];
    $confirmed_count = 0;
    $pending_count   = 0;
    $month_pay       = 0;

    foreach ( $all_assignments as $a ) {
        $game_id   = get_post_meta( $a->ID, 'us_game_id',     true );
        $game_date = get_post_meta( $game_id, 'us_game_date', true );
        $status    = get_post_meta( $a->ID, 'us_status',      true );
        $pay       = floatval( get_post_meta( $a->ID, 'us_pay_amount', true ) );

        if ( $status === 'confirmed' ) {
            $confirmed_count++;
            $league_id     = get_post_meta( $game_id, 'us_league_id', true );
            $is_tournament = $league_id ? get_post_meta( $league_id, 'us_is_tournament', true ) === '1' : false;
            if ( ! $is_tournament && $game_date && strpos( $game_date, $month_key ) === 0 ) {
                $month_pay += $pay;
            }
        }

        if ( in_array( $status, [ 'requested', 'pending' ] ) ) {
            $pending_count++;
        }

        if ( $game_date >= $today && $game_date <= $in7days
             && ! in_array( $status, [ 'declined', 'no-show', 'postponed' ] ) ) {
            $upcoming[] = [
                'assignment' => $a,
                'game_id'    => $game_id,
                'game_date'  => $game_date,
                'status'     => $status,
            ];
        }
    }

    usort( $upcoming, function( $a, $b ) {
        $date_cmp = strcmp( $a['game_date'], $b['game_date'] );
        if ( $date_cmp !== 0 ) return $date_cmp;
        $time_a = get_post_meta( $a['game_id'], 'us_game_time', true );
        $time_b = get_post_meta( $b['game_id'], 'us_game_time', true );
        return strcmp( $time_a, $time_b );
    } );

    $upcoming = array_values( array_filter( $upcoming, function( $item ) {
        return ! us_is_game_postponed( $item['game_id'] );
    } ) );

    // ── Open slot count ───────────────────────────────────────
    $unavail    = us_umpire_get_unavailable_dates( $umpire_id );
    $open_count = 0;

    $games = get_posts( [
        'post_type'   => US_PT_GAME,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_game_date', 'value' => $today, 'compare' => '>=' ],
        ],
    ] );

    foreach ( $games as $game ) {
        if ( us_is_game_postponed( $game->ID ) ) continue;
        $game_date = get_post_meta( $game->ID, 'us_game_date', true );
        if ( in_array( $game_date, $unavail ) ) continue;
        $two_umps = get_post_meta( $game->ID, 'us_two_umpires', true ) === '1';
        if ( ! us_get_confirmed_assignment( $game->ID, 'plate' ) ) $open_count++;
        if ( $two_umps && ! us_get_confirmed_assignment( $game->ID, 'base' ) ) $open_count++;
    }

    // ── Future unavailable dates ──────────────────────────────
    $future_unavail = array_values( array_filter( $unavail, fn($d) => $d >= $today ) );
    sort( $future_unavail );

    $current_phone = get_post_meta( $umpire_id, 'us_phone', true );

    ob_start();
    ?>
    <div class="us-dashboard">
        <?php us_dashboard_notices(); ?>
        <h2>Welcome back, <?php echo esc_html( $umpire->post_title ); ?></h2>

        <div class="us-stat-cards">
            <div class="us-stat-card">
                <div class="us-stat-value"><?php echo $confirmed_count; ?></div>
                <div class="us-stat-label">Games confirmed</div>
            </div>
            <?php if ( $pending_count > 0 ) : ?>
            <div class="us-stat-card us-stat-card--pending">
                <div class="us-stat-value us-stat-value--pending"><?php echo $pending_count; ?></div>
                <div class="us-stat-label">Awaiting confirmation</div>
            </div>
            <?php endif; ?>
            <div class="us-stat-card">
                <div class="us-stat-value">$<?php echo number_format( $month_pay, 2 ); ?></div>
                <div class="us-stat-label">League earnings this month</div>
            </div>
            <div class="us-stat-card">
                <div class="us-stat-value"><?php echo $open_count; ?></div>
                <div class="us-stat-label">Open slots available</div>
            </div>
        </div>

        <h3>Next 7 days</h3>

        <?php if ( empty( $upcoming ) ) : ?>
            <p class="us-empty">No games in the next 7 days.</p>
            <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_open_games' ) . '/' ) ); ?>"
               class="us-btn us-btn-request us-btn--mt">
                Browse open games &rarr;
            </a>
        <?php else : ?>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Game</th>
                    <th>Field</th>
                    <th>Position</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $upcoming as $item ) :
                    $a        = $item['assignment'];
                    $game_id  = $item['game_id'];
                    $status   = $item['status'];
                    $date     = get_post_meta( $game_id, 'us_game_date', true );
                    $time     = get_post_meta( $game_id, 'us_game_time', true );
                    $home     = get_post_meta( $game_id, 'us_home_team', true );
                    $away     = get_post_meta( $game_id, 'us_away_team', true );
                    $field    = get_post_meta( $game_id, 'us_field',     true );
                    $position = get_post_meta( $a->ID,   'us_position',  true );

                    $status_class = 'us-status-' . ( $status === 'no-show' ? 'noshow' : $status );
                    $confirm_url  = add_query_arg( [ 'us_action' => 'confirm', 'assignment' => $a->ID, 'nonce' => wp_create_nonce( 'us_umpire_action_' . $a->ID ) ] );
                    $decline_url  = add_query_arg( [ 'us_action' => 'decline', 'assignment' => $a->ID, 'nonce' => wp_create_nonce( 'us_umpire_action_' . $a->ID ) ] );
                ?>
                <tr>
                    <td data-label="Date"><?php echo $date ? esc_html( date( 'D, M j, Y', strtotime( $date ) ) ) : '—'; ?></td>
                    <td data-label="Time"><?php echo $time ? esc_html( date( 'g:i a', strtotime( $time ) ) ) : '—'; ?></td>
                    <td data-label="Game"><?php echo esc_html( $away . ' at ' . $home ); ?></td>
                    <td data-label="Field"><?php echo esc_html( $field ); ?></td>
                    <td data-label="Position"><?php echo esc_html( ucfirst( $position ) ); ?></td>
                    <td data-label="Status" class="<?php echo esc_attr( $status_class ); ?>">
                        <?php if ( $status === 'requested' ) : ?>
                            &#9679; Pending approval
                        <?php elseif ( $status === 'pending' ) : ?>
                            &#9679; Awaiting confirmation
                        <?php else : ?>
                            <?php echo esc_html( ucfirst( $status ) ); ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Action">
                        <?php if ( $status === 'pending' ) : ?>
                            <a href="<?php echo esc_url( $confirm_url ); ?>" class="us-btn us-btn-confirm">Confirm</a>
                            <a href="<?php echo esc_url( $decline_url ); ?>" class="us-btn us-btn-decline"
                               onclick="return confirm('Are you sure you want to decline this game?')">Decline</a>
                        <?php elseif ( $status === 'requested' ) : ?>
                            <span class="us-assignment-waiting">Waiting for assignor</span>
                            <button class="us-btn us-btn-decline us-cancel-request"
                                    data-assignment="<?php echo $a->ID; ?>">
                                Cancel request
                            </button>
                        <?php elseif ( $status === 'confirmed' ) : ?>
                            <span class="us-btn-confirmed">&#10003; Confirmed</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p>
            <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_schedule' ) . '/' ) ); ?>"
               class="us-btn us-btn-request">View full schedule &rarr;</a>
        </p>
        <?php endif; ?>

        <h3>My profile</h3>

        <div class="us-profile-grid">

            <div class="us-profile-card">
                <h4>Contact information</h4>
                <div class="us-profile-email">
                    <strong>Email</strong>
                    <span><?php echo esc_html( get_post_meta( $umpire_id, 'us_email', true ) ?: '—' ); ?></span>
                    <span class="us-profile-email-note">Contact assignor to update</span>
                </div>
                <form method="post" id="us-phone-form">
                    <?php wp_nonce_field( 'us_phone_update', 'us_phone_nonce' ); ?>
                    <div class="us-form-group">
                        <label for="us_phone">Phone number</label>
                        <div class="us-input-row">
                            <input type="tel"
                                   id="us_phone"
                                   name="us_phone"
                                   value="<?php echo esc_attr( $current_phone ); ?>"
                                   placeholder="e.g. 604-555-0123" />
                            <button type="submit" name="us_phone_update_submit" class="us-btn us-btn-confirm">
                                Save
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="us-profile-card">
                <h4>
                    My unavailable dates
                    <?php if ( ! empty( $future_unavail ) ) : ?>
                        <span class="us-badge us-badge--secondary"><?php echo count( $future_unavail ); ?></span>
                    <?php endif; ?>
                </h4>

                <?php if ( empty( $future_unavail ) ) : ?>
                    <p class="us-empty">No upcoming unavailable dates set.</p>
                <?php else : ?>
                    <ul class="us-unavail-list">
                        <?php foreach ( $future_unavail as $d ) : ?>
                        <li class="us-unavail-list__item">
                            <span class="us-unavail-list__dot">&#9679;</span>
                            <?php echo esc_html( date( 'l, M j, Y', strtotime( $d ) ) ); ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_availability' ) . '/' ) ); ?>"
                   class="us-btn us-btn-confirm us-btn--mt">
                    Manage availability &rarr;
                </a>
            </div>

        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ── Helper: get umpire unavailable dates ──────────────────────
function us_umpire_get_unavailable_dates( $umpire_id ) {
    $raw = get_post_meta( $umpire_id, 'us_unavailable_dates', true );
    if ( is_array( $raw ) ) return $raw;
    if ( is_string( $raw ) && ! empty( $raw ) ) {
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }
    return [];
}