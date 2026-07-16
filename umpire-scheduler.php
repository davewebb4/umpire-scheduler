<?php
/**
 * Plugin Name: Umpire Scheduler
 * Plugin URI:  https://gvsu.ca
 * Description: Slo-pitch umpire scheduling, assignment and pay tracking across multiple leagues.
 * Version:     1.6.3
 * Author:      Dave Webb
 * License:     GPL2
 * GitHub Plugin URI: davewebb4/umpire-scheduler
 * Primary Branch:    main
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Constants ─────────────────────────────────────────────────
define( 'US_PATH',    plugin_dir_path( __FILE__ ) );
define( 'US_URL',     plugin_dir_url( __FILE__ ) );
define( 'US_VERSION', '1.6.3' );

// ── Update checker ────────────────────────────────────────────
require_once US_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
$usUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/davewebb4/umpire-scheduler/',
    __FILE__,
    'umpire-scheduler'
);
$usUpdateChecker->setBranch( 'main' );
if ( defined( 'UMPIRE_SCHEDULER_GITHUB_TOKEN' ) ) {
    $usUpdateChecker->setAuthentication( UMPIRE_SCHEDULER_GITHUB_TOKEN );
}

// ── Core ──────────────────────────────────────────────────────
require_once US_PATH . 'includes/core/settings.php';
require_once US_PATH . 'includes/core/roles.php';
require_once US_PATH . 'includes/core/cpt-register.php';
require_once US_PATH . 'includes/core/helpers.php';
require_once US_PATH . 'includes/core/frontend.php';

// ── Meta boxes ────────────────────────────────────────────────
require_once US_PATH . 'includes/meta/meta-fields.php';


// ── Setup wizard ──────────────────────────────────────────────
require_once US_PATH . 'includes/admin/setup-wizard.php';

// ── Admin ─────────────────────────────────────────────────────
require_once US_PATH . 'includes/admin/admin-dashboard.php';
require_once US_PATH . 'includes/admin/admin-menu.php';
require_once US_PATH . 'includes/admin/admin-requests.php';
require_once US_PATH . 'includes/admin/admin-pay-reports.php';
require_once US_PATH . 'includes/admin/assignment-columns.php';
require_once US_PATH . 'includes/admin/league-games-page.php';
require_once US_PATH . 'includes/admin/broadcast.php';
require_once US_PATH . 'includes/admin/csv-import.php';
require_once US_PATH . 'includes/admin/ics-import.php';
require_once US_PATH . 'includes/admin/ajax-assign.php';

// ── Umpire (front end) ────────────────────────────────────────
require_once US_PATH . 'includes/umpire/umpire-dashboard.php';
require_once US_PATH . 'includes/umpire/umpire-schedule.php';
require_once US_PATH . 'includes/umpire/umpire-open-games.php';
require_once US_PATH . 'includes/umpire/umpire-earnings.php';
require_once US_PATH . 'includes/umpire/umpire-actions.php';
require_once US_PATH . 'includes/umpire/umpire-register.php';

require_once US_PATH . 'includes/umpire/umpire-notifications.php';
require_once US_PATH . 'includes/umpire/umpire-notices.php';
require_once US_PATH . 'includes/umpire/availability.php';
require_once US_PATH . 'includes/umpire/ical-export.php';

// ── Allocator (front end) ─────────────────────────────────────
require_once US_PATH . 'includes/allocator/allocator-dashboard.php';
require_once US_PATH . 'includes/allocator/allocator-games.php';
require_once US_PATH . 'includes/allocator/allocator-tournaments.php';
require_once US_PATH . 'includes/allocator/allocator-past-games.php';
require_once US_PATH . 'includes/allocator/allocator-broadcast.php';
require_once US_PATH . 'includes/allocator/allocator-pay-reports.php';
require_once US_PATH . 'includes/allocator/allocator-umpire-history.php';
require_once US_PATH . 'includes/allocator/xlsx-export.php';
require_once US_PATH . 'includes/allocator/allocator-invoices.php';
require_once US_PATH . 'includes/allocator/allocator-open-games-export.php';

// ── Public pages ──────────────────────────────────────────────
require_once US_PATH . 'includes/public/umpire-home.php';
require_once US_PATH . 'includes/public/league-schedule.php';
require_once US_PATH . 'includes/public/tournament-schedule.php';
require_once US_PATH . 'includes/public/umpire-list.php';
require_once US_PATH . 'includes/public/league-rules.php';

// ── Activation / deactivation ─────────────────────────────────
register_activation_hook( __FILE__, 'us_activate' );
function us_activate() {
    us_register_cpts();
    us_register_roles();
    flush_rewrite_rules();
    // Mark wizard complete on existing installs so it never shows
    if ( get_option( 'us_settings' ) ) {
        update_option( 'us_wizard_complete', '1' );
    } else {
        // Fresh install — trigger wizard redirect
        us_wizard_activation();
    }
}

register_deactivation_hook( __FILE__, 'us_deactivate' );
function us_deactivate() {
    flush_rewrite_rules();
}