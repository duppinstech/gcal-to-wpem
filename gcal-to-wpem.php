<?php
/**
 * Plugin Name: WP Event Manager Gcal Importer
 * Plugin URI: https://github.com/duppinstech/gcal-to-wpem
 * Description: Imports Google Calendar events into WP Event Manager via iCal.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: Duppins Technology
 * Author URI: https://duppinstech.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gcal-to-wpem
 * Requires Plugins: wp-event-manager
 * Update URI: https://github.com/duppinstech/gcal-to-wpem
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GCAL_WPEM_VERSION', '0.1.0');
define('GCAL_WPEM_PATH', plugin_dir_path(__FILE__));
define('GCAL_WPEM_URL', plugin_dir_url(__FILE__));
define('GCAL_WPEM_BASENAME', plugin_basename(__FILE__));

require_once GCAL_WPEM_PATH . 'includes/class-gcal-wpem-github-updater.php';

// Updates from GitHub Releases. Booted at load so WP-Cron and background auto-updates see releases.
// Disable with define( 'GCAL_WPEM_DISABLE_GITHUB_UPDATER', true ).
GCAL_WPEM_GitHub_Updater::boot([
    'file'    => __FILE__,
    'version' => GCAL_WPEM_VERSION,
    'owner'   => 'duppinstech',
    'repo'    => 'gcal-to-wpem',
    'prefix'  => 'gcal_wpem',
]);

/**
 * Bootstrap after other plugins so WP Event Manager can load first.
 */
add_action('plugins_loaded', static function (): void {
    if (!class_exists('WP_Event_Manager', false)) {
        add_action('admin_notices', 'gcal_wpem_wpem_required_notice');
        add_filter('plugin_action_links_' . GCAL_WPEM_BASENAME, 'gcal_wpem_plugin_action_links_requires_wpem');
        return;
    }

    require_once GCAL_WPEM_PATH . 'vendor/autoload.php';
    require_once GCAL_WPEM_PATH . 'includes/class-plugin.php';
    require_once GCAL_WPEM_PATH . 'includes/class-settings.php';
    require_once GCAL_WPEM_PATH . 'includes/class-fetcher.php';
    require_once GCAL_WPEM_PATH . 'includes/class-parser.php';
    require_once GCAL_WPEM_PATH . 'includes/class-importer.php';
    require_once GCAL_WPEM_PATH . 'includes/class-recurring-queue.php';
    require_once GCAL_WPEM_PATH . 'includes/class-pending-digest.php';
    require_once GCAL_WPEM_PATH . 'includes/class-cron.php';

    \GCAL_WPEM\Plugin::init();
}, 20);

/**
 * Shown when WP Event Manager is not active.
 */
function gcal_wpem_wpem_required_notice(): void {
    if (!current_user_can('activate_plugins')) {
        return;
    }
    echo '<div class="notice notice-error"><p>';
    echo esc_html__(
        'Google Calendar to WP Event Manager requires the WP Event Manager plugin to be installed and active.',
        'gcal-to-wpem'
    );
    echo '</p></div>';
}

/**
 * @param array<int,string> $links
 * @return array<int,string>
 */
function gcal_wpem_plugin_action_links_requires_wpem(array $links): array {
    array_unshift(
        $links,
        '<span style="color:#b32d2e;">' . esc_html__('Requires WP Event Manager', 'gcal-to-wpem') . '</span>'
    );
    return $links;
}