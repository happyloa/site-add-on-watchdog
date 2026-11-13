<?php

/**
 * Removes the data Site Add-on Watchdog stored when the plugin is deleted.
 *
 * Settings can hold webhook URLs, a webhook secret, and a WPScan API key, so
 * they should not outlive the plugin. Cached lookups are transients with their
 * own expiry and are left for WordPress to clean up.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

(static function (): void {
    $cleanup = static function (): void {
        $options = [
            'settings',
            'risks',
            'ignore',
            'risk_history',
            'notification_queue',
            'failed_notification',
            'cron_status',
            'update_check_scan_at',
        ];

        foreach ($options as $option) {
            delete_option('siteadwa_' . $option);
            delete_option('wp_watchdog_' . $option);
        }

        foreach (['scan_error', 'settings_error', 'webhook_error', 'wpscan_error'] as $transient) {
            delete_transient('siteadwa_' . $transient);
        }

        foreach (['siteadwa_scheduled_scan', 'siteadwa_notification_queue'] as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    };

    if (! is_multisite()) {
        $cleanup();

        return;
    }

    $siteIds = get_sites([
        'fields' => 'ids',
        'number' => 0,
    ]);

    foreach ($siteIds as $siteId) {
        switch_to_blog((int) $siteId);
        $cleanup();
        restore_current_blog();
    }
})();
