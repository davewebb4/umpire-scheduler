<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'umpire_home', 'us_shortcode_umpire_home' );
function us_shortcode_umpire_home() {
    $org_name  = us_setting( 'org_name' )  ?: us_setting( 'app_title' );
    $tagline   = us_setting( 'tagline' );
    $logo_url  = us_get_logo_url( 'medium' );

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
                         class="us-home__logo us-home__logo--large">
                    <p class="us-home__title us-home__title--sub"><?php echo esc_html( $org_name ); ?></p>
                <?php else : ?>
                    <h1 class="us-home__title"><?php echo esc_html( $org_name ); ?></h1>
                <?php endif; ?>
                <?php if ( $tagline ) : ?>
                    <p class="us-home__tagline"><?php echo esc_html( $tagline ); ?></p>
                <?php endif; ?>
                <?php if ( ! is_user_logged_in() ) : ?>
                <div class="us-home__hero-actions">
                    <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) ); ?>"
                       class="us-home__hero-btn us-home__hero-btn--primary">
                        Sign In
                    </a>
                    <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_register' ) . '/' ) ); ?>"
                       class="us-home__hero-btn us-home__hero-btn--outline">
                        Register
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ── Active Leagues ────────────────────────────────── -->
        <?php if ( ! empty( $leagues ) ) : ?>
        <section class="us-home__section">
            <h2 class="us-home__section-title">League Schedules</h2>
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

        <!-- ── League Rules ──────────────────────────────────── -->
        <?php
        $all_leagues_with_rules = get_posts( [
            'post_type'   => US_PT_LEAGUE,
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC',
            'post_status' => 'publish',
        ] );
        $rule_leagues = array_filter( $all_leagues_with_rules, function( $l ) {
            return trim( get_post_meta( $l->ID, 'us_rules', true ) ) !== '';
        } );
        ?>
        <?php if ( ! empty( $rule_leagues ) ) : ?>
        <section class="us-home__section">
            <h2 class="us-home__section-title">League Rules</h2>
            <div class="us-rules-list">
                <?php foreach ( $rule_leagues as $rl ) :
                    $rules      = get_post_meta( $rl->ID, 'us_rules', true );
                    $is_tourney = get_post_meta( $rl->ID, 'us_is_tournament', true ) === '1';
                    $panel_id   = 'us-home-rules-' . $rl->ID;
                ?>
                <div class="us-rules-panel">
                    <button type="button" class="us-rules-panel__toggle" aria-expanded="false" aria-controls="<?php echo $panel_id; ?>">
                        <span class="us-rules-panel__name">
                            <?php echo esc_html( $rl->post_title ); ?>
                            <?php if ( $is_tourney ) : ?>
                                <span class="us-badge us-badge--secondary" style="font-size:10px;vertical-align:middle;margin-left:6px;">Tournament</span>
                            <?php endif; ?>
                        </span>
                        <span class="us-rules-panel__chevron">&#8250;</span>
                    </button>
                    <div class="us-rules-panel__body" id="<?php echo $panel_id; ?>" hidden>
                        <div class="us-rules-panel__content">
                            <?php echo wp_kses_post( $rules ); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- ── Quick access cards — logged in only ──────────── -->
        <?php if ( is_user_logged_in() ) : ?>
        <section class="us-home__section">
            <h2 class="us-home__section-title">Quick Access</h2>
            <div class="us-home__quick-cards">
                <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_dashboard' ) . '/' ) ); ?>" class="us-home__quick-card">
                    <span class="us-home__quick-title">My Dashboard</span>
                    <span class="us-home__quick-sub">Your overview</span>
                </a>
                <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_schedule' ) . '/' ) ); ?>" class="us-home__quick-card">
                    <span class="us-home__quick-title">My Schedule</span>
                    <span class="us-home__quick-sub">Upcoming assignments</span>
                </a>
                <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_open_games' ) . '/' ) ); ?>" class="us-home__quick-card">
                    <span class="us-home__quick-title">Open Games</span>
                    <span class="us-home__quick-sub">Request available slots</span>
                </a>
                <a href="<?php echo esc_url( home_url( '/' . us_setting( 'slug_umpire_list' ) . '/' ) ); ?>" class="us-home__quick-card">
                    <span class="us-home__quick-title">Umpire List</span>
                    <span class="us-home__quick-sub">View all active umpires</span>
                </a>
            </div>
        </section>
        <?php endif; // is_user_logged_in ?>

    </div>
    <?php
    return ob_get_clean();
}
