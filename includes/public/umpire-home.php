<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'umpire_home', 'us_shortcode_umpire_home' );
function us_shortcode_umpire_home() {
    $org_name  = us_setting( 'org_name' )  ?: us_setting( 'app_title' );
    $tagline   = us_setting( 'tagline' );
    $logo_id   = get_theme_mod( 'custom_logo' );
    $logo_url  = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';

    $leagues   = us_get_active_leagues( false );
    $tourneys  = us_get_active_leagues( true );
    $has_tourney = ! empty( $tourneys );

    ob_start();
    ?>
    <div class="us-home">

        <!-- ── Hero ──────────────────────────────────────────── -->
        <section class="us-home__hero">
            <div class="us-home__hero-inner">
                <?php if ( $logo_url ) : ?>
                    <img src="<?php echo esc_url( $logo_url ); ?>"
                         alt="<?php echo esc_attr( $org_name ); ?>"
                         class="us-home__logo">
                <?php endif; ?>
                <h1 class="us-home__title"><?php echo esc_html( $org_name ); ?></h1>
                <?php if ( $tagline ) : ?>
                    <p class="us-home__tagline"><?php echo esc_html( $tagline ); ?></p>
                <?php endif; ?>
                <div class="us-home__hero-actions">
                    <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_league_schedule' ) . '/' ) ); ?>"
                       class="us-home__hero-btn us-home__hero-btn--primary">
                        View Schedules
                    </a>
                    <?php if ( ! is_user_logged_in() ) : ?>
                    <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) ); ?>"
                       class="us-home__hero-btn us-home__hero-btn--outline">
                        Sign In
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- ── Active Leagues ────────────────────────────────── -->
        <?php if ( ! empty( $leagues ) ) : ?>
        <section class="us-home__section">
            <h2 class="us-home__section-title">Active Leagues</h2>
            <div class="us-home__leagues">
                <?php foreach ( $leagues as $league ) :
                    $pay_rate = get_post_meta( $league->ID, 'us_pay_rate', true );
                    $game_count = count( get_posts( [
                        'post_type'   => US_PT_GAME,
                        'numberposts' => -1,
                        'post_status' => 'publish',
                        'fields'      => 'ids',
                        'meta_query'  => [
                            [ 'key' => 'us_league_id', 'value' => $league->ID, 'compare' => '=' ],
                            [ 'key' => 'us_game_date', 'value' => current_time( 'Y-m-d' ), 'compare' => '>=' ],
                        ],
                    ] ) );
                ?>
                <a href="<?php echo esc_url( add_query_arg( 'league_id', $league->ID, home_url( '/' . us_setting( 'slug_league_schedule' ) . '/' ) ) ); ?>"
                   class="us-home__league-card">
                    <span class="us-home__league-name"><?php echo esc_html( $league->post_title ); ?></span>
                    <?php if ( $game_count > 0 ) : ?>
                        <span class="us-home__league-meta"><?php echo $game_count; ?> upcoming game<?php echo $game_count !== 1 ? 's' : ''; ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- ── Tournaments ───────────────────────────────────── -->
        <?php if ( $has_tourney ) : ?>
        <section class="us-home__section">
            <h2 class="us-home__section-title">Tournaments</h2>
            <div class="us-home__leagues">
                <?php foreach ( $tourneys as $tourney ) :
                    $start = get_post_meta( $tourney->ID, 'us_tourney_start', true );
                    $end   = get_post_meta( $tourney->ID, 'us_tourney_end',   true );
                    $dates = '';
                    if ( $start && $end ) $dates = date( 'M j', strtotime( $start ) ) . ' – ' . date( 'M j, Y', strtotime( $end ) );
                    elseif ( $start )     $dates = date( 'M j, Y', strtotime( $start ) );
                ?>
                <a href="<?php echo esc_url( add_query_arg( 'tournament_id', $tourney->ID, home_url( '/' . us_setting( 'slug_tournament_schedule' ) . '/' ) ) ); ?>"
                   class="us-home__league-card us-home__league-card--tourney">
                    <span class="us-home__league-name"><?php echo esc_html( $tourney->post_title ); ?></span>
                    <?php if ( $dates ) : ?>
                        <span class="us-home__league-meta"><?php echo esc_html( $dates ); ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- ── Panels ────────────────────────────────────────── -->
        <section class="us-home__panels">

            <div class="us-home__panel">
                    <h2 class="us-home__section-title">Umpires</h2>
                <ul class="us-home__panel-links">
                    <?php if ( ! is_user_logged_in() ) : ?>
                    <li><a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_register' ) . '/' ) ); ?>">Register as an Umpire</a></li>
                    <li><a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) ); ?>">Sign In</a></li>
                    <?php else : ?>
                    <li><a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) ); ?>">My Dashboard</a></li>
                    <li><a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_schedule' ) . '/' ) ); ?>">My Schedule</a></li>
                    <li><a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_open_games' ) . '/' ) ); ?>">Open Games</a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="us-home__panel">
                    <h2 class="us-home__section-title">Schedules</h2>
                <ul class="us-home__panel-links">
                    <li><a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_league_schedule' ) . '/' ) ); ?>">League Schedule</a></li>
                    <?php if ( $has_tourney ) : ?>
                    <li><a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_tournament_schedule' ) . '/' ) ); ?>">Tournament Schedule</a></li>
                    <?php endif; ?>
                    <li><a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_umpire_list' ) . '/' ) ); ?>">Umpire List</a></li>
                </ul>
            </div>

        </section>

    </div>
    <?php
    return ob_get_clean();
}
