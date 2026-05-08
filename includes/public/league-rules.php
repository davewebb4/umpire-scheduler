<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'league_rules', 'us_shortcode_league_rules' );
function us_shortcode_league_rules() {
    $league_id = absint( $_GET['league_id'] ?? 0 );
    $base_url  = home_url( '/' . us_setting( 'slug_league_rules' ) . '/' );

    ob_start();
    ?>
    <div class="us-dashboard">

        <?php if ( $league_id ) :
            $league = get_post( $league_id );
            $rules  = $league ? get_post_meta( $league_id, 'us_rules', true ) : '';
        ?>

            <div style="margin-bottom:20px;">
                <a href="<?php echo esc_url( $base_url ); ?>" class="us-btn us-btn--muted us-btn--sm">&larr; All rules</a>
            </div>

            <?php if ( $league && $rules ) : ?>
                <h2><?php echo esc_html( $league->post_title ); ?></h2>
                <div class="us-rules-page-content">
                    <?php echo wp_kses_post( $rules ); ?>
                </div>
            <?php else : ?>
                <p class="us-empty">No rules found for this league.</p>
            <?php endif; ?>

        <?php else :
            // List all leagues that have rules
            $all_leagues = get_posts( [
                'post_type'   => US_PT_LEAGUE,
                'numberposts' => -1,
                'orderby'     => 'title',
                'order'       => 'ASC',
                'post_status' => 'publish',
            ] );
            $rule_leagues = array_filter( $all_leagues, function( $l ) {
                return trim( get_post_meta( $l->ID, 'us_rules', true ) ) !== '';
            } );
        ?>

            <h2>League Rules</h2>

            <?php if ( empty( $rule_leagues ) ) : ?>
                <p class="us-empty">No league rules have been added yet.</p>
            <?php else : ?>
            <div class="us-home__leagues">
                <?php foreach ( $rule_leagues as $rl ) :
                    $is_tourney = get_post_meta( $rl->ID, 'us_is_tournament', true ) === '1';
                ?>
                <a href="<?php echo esc_url( add_query_arg( 'league_id', $rl->ID, $base_url ) ); ?>"
                   class="us-home__league-card<?php echo $is_tourney ? ' us-home__league-card--tourney' : ''; ?>">
                    <span class="us-home__league-name"><?php echo esc_html( $rl->post_title ); ?></span>
                    <span class="us-home__league-meta">View rules &rarr;</span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        <?php endif; ?>

    </div>

    <style>
    .us-rules-page-content {
        background: #fff;
        border: 1px solid #dde3ea;
        border-radius: 8px;
        padding: 28px 32px;
        font-size: 15px;
        line-height: 1.75;
        color: #333;
        max-width: 820px;
    }
    .us-rules-page-content h1,
    .us-rules-page-content h2,
    .us-rules-page-content h3 { color: var(--us-primary, #091b33); margin: 20px 0 8px; }
    .us-rules-page-content h1 { font-size: 20px; }
    .us-rules-page-content h2 { font-size: 17px; }
    .us-rules-page-content h3 { font-size: 15px; }
    .us-rules-page-content ul,
    .us-rules-page-content ol { padding-left: 22px; margin: 8px 0 16px; }
    .us-rules-page-content li { margin-bottom: 4px; }
    .us-rules-page-content p { margin: 0 0 12px; }
    .us-rules-page-content strong { color: var(--us-primary, #091b33); }
    </style>
    <?php
    return ob_get_clean();
}
