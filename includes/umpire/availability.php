<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Availability shortcode ────────────────────────────────────
add_shortcode( 'umpire_availability', 'us_shortcode_availability' );
function us_shortcode_availability() {
    if ( ! is_user_logged_in() ) return us_login_form();

    $umpire = us_get_umpire_by_user( get_current_user_id() );
    if ( ! $umpire ) return '<p class="us-empty">No umpire profile found. Please contact the assignor.</p>';

    $umpire_id  = $umpire->ID;
    $game_dates = us_get_umpire_game_dates( $umpire_id );

    ob_start();
    ?>
    <div class="us-dashboard">
        <h2>My availability</h2>
        <p class="us-empty us-cal-intro">Click a date to mark it unavailable. Click and drag to select multiple dates. Red dates mean you are not available.</p>

        <div class="us-cal-wrap">
            <div class="us-cal-nav">
                <button class="us-cal-prev us-btn us-btn-request">&larr;</button>
                <span class="us-cal-month-label"></span>
                <button class="us-cal-next us-btn us-btn-request">&rarr;</button>
            </div>

            <div class="us-cal-grid-wrap">
                <div class="us-cal-days-header">
                    <span>Sun</span><span>Mon</span><span>Tue</span>
                    <span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
                </div>
                <div class="us-cal-grid" id="us-cal-grid">
                    <div class="us-cal-loading">Loading...</div>
                </div>
            </div>

            <div class="us-cal-legend">
                <span class="us-cal-legend-item"><span class="us-cal-dot us-cal-dot--avail"></span> Available</span>
                <span class="us-cal-legend-item"><span class="us-cal-dot us-cal-dot--unavail"></span> Unavailable</span>
                <span class="us-cal-legend-item"><span class="us-cal-dot us-cal-dot--game"></span> Game assigned</span>
            </div>

            <div class="us-cal-status" id="us-cal-status"></div>
        </div>
    </div>

    <?php
    return ob_get_clean();
}

// ── AJAX — fetch availability ─────────────────────────────────
add_action( 'wp_ajax_us_fetch_availability', 'us_ajax_fetch_availability' );
function us_ajax_fetch_availability() {
    check_ajax_referer( 'us_fetch_availability_nonce', 'nonce' );

    $umpire_id = absint( $_POST['umpire_id'] ?? 0 );
    if ( ! $umpire_id ) wp_send_json_error( 'Invalid umpire' );

    $user_id = get_current_user_id();
    $linked  = absint( get_post_meta( $umpire_id, 'us_wp_user_id', true ) );
    if ( $linked !== $user_id && ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    wp_cache_delete( $umpire_id, 'post_meta' );
    $dates = us_get_unavail_dates( $umpire_id );

    wp_send_json_success( [ 'dates' => $dates ] );
}

// ── AJAX — save availability ──────────────────────────────────
add_action( 'wp_ajax_us_save_availability', 'us_ajax_save_availability' );
function us_ajax_save_availability() {
    check_ajax_referer( 'us_availability_nonce', 'nonce' );

    $umpire_id = absint( $_POST['umpire_id'] ?? 0 );
    if ( ! $umpire_id ) wp_send_json_error( 'Invalid umpire' );

    $user_id = get_current_user_id();
    $linked  = absint( get_post_meta( $umpire_id, 'us_wp_user_id', true ) );
    if ( $linked !== $user_id && ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $dates_raw = sanitize_text_field( $_POST['dates'] ?? '[]' );
    $dates     = json_decode( stripslashes( $dates_raw ), true );

    if ( ! is_array( $dates ) ) {
        wp_send_json_error( 'Invalid dates' );
    }

    $clean = [];
    foreach ( $dates as $d ) {
        $d = sanitize_text_field( $d );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
            $clean[] = $d;
        }
    }

    delete_post_meta( $umpire_id, 'us_unavailable_dates' );
    update_post_meta( $umpire_id, 'us_unavailable_dates', $clean );
    wp_cache_delete( $umpire_id, 'post_meta' );
    clean_post_cache( $umpire_id );

    wp_send_json_success( [ 'count' => count( $clean ) ] );
}

// ── Helper — get unavailable dates cleanly ────────────────────
function us_get_unavail_dates( $umpire_id ) {
    $raw = get_post_meta( $umpire_id, 'us_unavailable_dates', true );
    if ( is_array( $raw ) ) return $raw;
    if ( is_string( $raw ) && ! empty( $raw ) ) {
        $decoded = json_decode( $raw, true );
        if ( is_array( $decoded ) ) return $decoded;
    }
    return [];
}

// ── Helper — get umpire confirmed game dates ──────────────────
function us_get_umpire_game_dates( $umpire_id ) {
    $assignments = get_posts( [
        'post_type'   => US_PT_ASSIGNMENT,
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [
            [ 'key' => 'us_umpire_id', 'value' => $umpire_id,  'compare' => '=' ],
            [ 'key' => 'us_status',    'value' => 'confirmed',  'compare' => '=' ],
        ],
    ] );

    $dates = [];
    foreach ( $assignments as $a ) {
        $game_id   = get_post_meta( $a->ID, 'us_game_id',     true );
        $game_date = get_post_meta( $game_id, 'us_game_date', true );
        if ( $game_date && ! in_array( $game_date, $dates ) ) {
            $dates[] = $game_date;
        }
    }
    return $dates;
}