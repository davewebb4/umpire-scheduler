<?php
/**
 * Template Name: Umpire App Page
 *
 * Clean full-width canvas for Umpire Scheduler pages.
 * Uses the Elementor canvas template when available (Hello / Hello Child themes),
 * otherwise renders a minimal standards-compliant layout with no theme chrome.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Elementor canvas (Hello / Hello Child theme) ──────────────
$elementor_canvas = get_template_directory() . '/templates/canvas.php';
if ( file_exists( $elementor_canvas ) ) {
    include $elementor_canvas;
    exit;
}

// ── Fallback: minimal full-width layout ───────────────────────
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php wp_title( '|', true, 'right' ); ?><?php bloginfo( 'name' ); ?></title>
    <?php wp_head(); ?>
    <style>
        body { margin: 0; padding: 0; background: #f5f7fa; }
        .us-app-wrap { max-width: 1200px; margin: 0 auto; padding: 24px 16px; }
    </style>
</head>
<body <?php body_class( 'us-app-body' ); ?>>
<?php wp_body_open(); ?>
<div class="us-app-wrap">
    <?php while ( have_posts() ) : the_post(); the_content(); endwhile; ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
