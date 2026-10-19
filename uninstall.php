<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes the GitHub updater cache only. Import settings and logs are left in place.
 *
 * @package GCAL_WPEM
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_site_option('gcal_wpem_github_release');
