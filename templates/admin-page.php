<?php
/**
 * Watchdog dashboard.
 *
 * @var array<int, array{risk: Risk, vulnerabilities: array<int, array<string, mixed>>, attributes: array<string, string>}> $watchdogRiskRows
 * @var int $watchdogVulnerabilityCount
 * @var string[] $watchdogIgnored
 * @var array $watchdogSettings
 * @var int $watchdogHistoryRetention
 * @var int $watchdogHistoryDisplay
 * @var array<int, array{run_at:int, risks:array<int, array<string, mixed>>, risk_count:int}> $watchdogHistoryRecords
 * @var array<int, array<string, string>> $watchdogHistoryDownloads
 * @var int|null $watchdogLastScanAt
 * @var int|null $watchdogNextScanAt
 * @var array{cron_disabled: bool, overdue_streak: int} $watchdogCronStatus
 * @var string $watchdogCronEndpoint
 * @var string $watchdogCronSecret
 * @var bool $watchdogCronSecretPersisted
 * @var string|false $watchdogSettingsError
 * @var array|false $watchdogWpScanError
 * @var string|false $watchdogScanError
 * @var array|null $watchdogLastFailedNotification
 * @var array{length:int,next_attempt_at:?int} $watchdogQueueStatus
 * @var string $watchdogActionPrefix
 * @var array<string, bool> $watchdogNoticeFlags
 * @var string $watchdogNotificationResult
 * @var string $watchdogFailedNotificationStatus
 * @var string $watchdogChannelTest
 * @var string $watchdogChannelTestStatus
 * @var string $watchdogRiskSearch
 * @var string $watchdogRiskSortSelection
 * @var string $watchdogRiskOrderSelection
 */

use Watchdog\Models\Risk;
use Watchdog\TestingMode;

defined('ABSPATH') || exit;

$watchdogNotifications = $watchdogSettings['notifications'];
$watchdogPostUrl       = admin_url('admin-post.php');
$watchdogDateFormat    = get_option('date_format') . ' ' . get_option('time_format');
$watchdogNow           = time();
$watchdogRiskTotal     = count($watchdogRiskRows);
$watchdogQueueLength   = (int) ($watchdogQueueStatus['length'] ?? 0);
$watchdogQueueNextAttempt = $watchdogQueueStatus['next_attempt_at'] ?? null;
$watchdogHasFailedNotification = ! empty($watchdogLastFailedNotification);
$watchdogIsCronDisabled = ! empty($watchdogCronStatus['cron_disabled']);

$watchdogChannelLabels = [
    'email'   => __('Email', 'site-add-on-watchdog'),
    'discord' => __('Discord', 'site-add-on-watchdog'),
    'slack'   => __('Slack', 'site-add-on-watchdog'),
    'teams'   => __('Microsoft Teams', 'site-add-on-watchdog'),
    'webhook' => __('Custom webhook', 'site-add-on-watchdog'),
];
$watchdogEnabledChannelCount = 0;
foreach (array_keys($watchdogChannelLabels) as $watchdogChannelKey) {
    if (! empty($watchdogNotifications[$watchdogChannelKey]['enabled'])) {
        $watchdogEnabledChannelCount++;
    }
}

$watchdogFrequencyLabels = [
    'daily'   => __('Daily', 'site-add-on-watchdog'),
    'weekly'  => __('Weekly', 'site-add-on-watchdog'),
    'testing' => __('Testing', 'site-add-on-watchdog'),
    'manual'  => __('Manual', 'site-add-on-watchdog'),
];
$watchdogCurrentFrequency = (string) ($watchdogNotifications['frequency'] ?? 'daily');
if (! isset($watchdogFrequencyLabels[$watchdogCurrentFrequency])) {
    $watchdogCurrentFrequency = 'daily';
}

// Header summary.
if ($watchdogLastScanAt === null) {
    $watchdogSummary = __('No scan has run yet. Run a manual scan to create your first report.', 'site-add-on-watchdog');
} elseif ($watchdogRiskTotal > 0) {
    $watchdogSummary = sprintf(
        /* translators: %s: number of plugins. */
        _n('%s plugin needs attention.', '%s plugins need attention.', $watchdogRiskTotal, 'site-add-on-watchdog'),
        number_format_i18n($watchdogRiskTotal)
    );
} else {
    $watchdogSummary = __('No risks found in the latest scan.', 'site-add-on-watchdog');
}

// Overview tiles.
if ($watchdogLastScanAt === null) {
    $watchdogRiskTone = 'neutral';
    $watchdogRiskHint = __('Not scanned yet', 'site-add-on-watchdog');
} elseif ($watchdogRiskTotal === 0) {
    $watchdogRiskTone = 'ok';
    $watchdogRiskHint = __('All clear', 'site-add-on-watchdog');
} elseif ($watchdogVulnerabilityCount > 0) {
    $watchdogRiskTone = 'danger';
    $watchdogRiskHint = sprintf(
        /* translators: %s: number of vulnerabilities. */
        _n('%s known vulnerability', '%s known vulnerabilities', $watchdogVulnerabilityCount, 'site-add-on-watchdog'),
        number_format_i18n($watchdogVulnerabilityCount)
    );
} else {
    $watchdogRiskTone = 'warn';
    $watchdogRiskHint = __('Updates available', 'site-add-on-watchdog');
}

if ($watchdogLastScanAt === null) {
    $watchdogLastScanValue = __('Never', 'site-add-on-watchdog');
    $watchdogLastScanHint  = __('Run a manual scan', 'site-add-on-watchdog');
} else {
    $watchdogLastScanValue = sprintf(
        /* translators: %s: human-readable time difference, for example "2 hours". */
        __('%s ago', 'site-add-on-watchdog'),
        human_time_diff($watchdogLastScanAt, $watchdogNow)
    );
    $watchdogLastScanHint = wp_date($watchdogDateFormat, $watchdogLastScanAt);
}

if ($watchdogCurrentFrequency === 'manual') {
    $watchdogNextScanValue = __('Manual', 'site-add-on-watchdog');
    $watchdogNextScanHint  = __('Automatic scans are off', 'site-add-on-watchdog');
} elseif ($watchdogNextScanAt === null) {
    $watchdogNextScanValue = __('Not scheduled', 'site-add-on-watchdog');
    $watchdogNextScanHint  = __('Save the settings to reschedule', 'site-add-on-watchdog');
} else {
    $watchdogNextScanValue = $watchdogNextScanAt > $watchdogNow
        ? sprintf(
            /* translators: %s: human-readable time difference, for example "2 hours". */
            __('In %s', 'site-add-on-watchdog'),
            human_time_diff($watchdogNow, $watchdogNextScanAt)
        )
        : __('Due now', 'site-add-on-watchdog');
    $watchdogNextScanHint = sprintf(
        /* translators: 1: scan frequency, 2: date and time. */
        __('%1$s, %2$s', 'site-add-on-watchdog'),
        $watchdogFrequencyLabels[$watchdogCurrentFrequency],
        wp_date($watchdogDateFormat, $watchdogNextScanAt)
    );
}

$watchdogChannelTone = 'neutral';
if ($watchdogHasFailedNotification) {
    $watchdogChannelTone = 'warn';
    $watchdogChannelHint = __('Last delivery failed', 'site-add-on-watchdog');
} elseif ($watchdogQueueLength > 0) {
    $watchdogChannelHint = sprintf(
        /* translators: %s: number of queued alerts. */
        _n('%s alert queued', '%s alerts queued', $watchdogQueueLength, 'site-add-on-watchdog'),
        number_format_i18n($watchdogQueueLength)
    );
} elseif ($watchdogEnabledChannelCount === 0) {
    $watchdogChannelTone = 'warn';
    $watchdogChannelHint = __('No channel enabled', 'site-add-on-watchdog');
} else {
    $watchdogChannelHint = __('No failed deliveries', 'site-add-on-watchdog');
}

$watchdogTabs = [
    'watchdog-risks'    => __('Risks', 'site-add-on-watchdog'),
    'watchdog-history'  => __('History', 'site-add-on-watchdog'),
    'watchdog-settings' => __('Settings', 'site-add-on-watchdog'),
    'watchdog-delivery' => __('Delivery', 'site-add-on-watchdog'),
];
?>
<div class="wrap wp-watchdog-admin"<?php echo ! empty($watchdogNoticeFlags['scan']) ? ' data-watchdog-initial-tab="watchdog-risks"' : ''; ?>>
    <header class="wp-watchdog-header">
        <div class="wp-watchdog-header__brand">
            <span class="wp-watchdog-header__icon dashicons dashicons-shield" aria-hidden="true"></span>
            <div>
                <h1 class="wp-watchdog-header__title">
                    <?php esc_html_e('Site Add-on Watchdog', 'site-add-on-watchdog'); ?>
                    <span class="wp-watchdog-header__version"><?php echo esc_html('v' . \Watchdog\Version::NUMBER); ?></span>
                </h1>
                <p class="wp-watchdog-header__summary"><?php echo esc_html($watchdogSummary); ?></p>
            </div>
        </div>
        <div class="wp-watchdog-actions">
            <form method="post" action="<?php echo esc_url($watchdogPostUrl); ?>">
                <?php wp_nonce_field($watchdogActionPrefix . '_send_notifications'); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr($watchdogActionPrefix . '_send_notifications'); ?>">
                <button class="button" type="submit">
                    <span class="dashicons dashicons-megaphone" aria-hidden="true"></span>
                    <?php esc_html_e('Send notifications now', 'site-add-on-watchdog'); ?>
                </button>
            </form>
            <form method="post" action="<?php echo esc_url($watchdogPostUrl); ?>">
                <?php wp_nonce_field($watchdogActionPrefix . '_scan'); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr($watchdogActionPrefix . '_scan'); ?>">
                <button class="button button-primary" type="submit">
                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    <?php esc_html_e('Run manual scan', 'site-add-on-watchdog'); ?>
                </button>
            </form>
        </div>
    </header>

    <hr class="wp-header-end">

    <?php $watchdogWebhookError = get_transient($watchdogActionPrefix . '_webhook_error'); ?>
    <?php if (! empty($watchdogWebhookError)) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($watchdogWebhookError); ?></p></div>
    <?php endif; ?>

    <?php if (! empty($watchdogSettingsError)) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($watchdogSettingsError); ?></p></div>
    <?php endif; ?>

    <?php if (is_string($watchdogScanError) && $watchdogScanError !== '') : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($watchdogScanError); ?></p></div>
    <?php endif; ?>

    <?php if (empty($watchdogCronSecretPersisted)) : ?>
        <div class="notice notice-warning"><p><?php esc_html_e('Cron secret has not been initialized or could not be saved. Please try saving the settings again.', 'site-add-on-watchdog'); ?></p></div>
    <?php endif; ?>

    <?php if (! empty($watchdogWpScanError) && is_array($watchdogWpScanError)) : ?>
        <?php
        $watchdogWpScanMessage = $watchdogWpScanError['message'] ?? '';
        $watchdogWpScanCode = isset($watchdogWpScanError['code']) ? (string) $watchdogWpScanError['code'] : '';
        if ($watchdogWpScanMessage !== '' && $watchdogWpScanCode !== '') {
            $watchdogWpScanMessage = sprintf(
                /* translators: 1: WPScan response code, 2: message */
                __('WPScan error (%1$s): %2$s', 'site-add-on-watchdog'),
                $watchdogWpScanCode,
                $watchdogWpScanMessage
            );
        }
        ?>
        <?php if ($watchdogWpScanMessage !== '') : ?>
            <div class="notice notice-warning"><p><?php echo esc_html($watchdogWpScanMessage); ?></p></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (! empty($watchdogNoticeFlags['updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'site-add-on-watchdog'); ?></p></div>
    <?php endif; ?>

    <?php if (! empty($watchdogNoticeFlags['scan']) && empty($watchdogScanError)) : ?>
        <div class="notice notice-info is-dismissible"><p><?php esc_html_e('Manual scan completed.', 'site-add-on-watchdog'); ?></p></div>
    <?php endif; ?>

    <?php if ($watchdogNotificationResult === 'sent') : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Notifications were dispatched.', 'site-add-on-watchdog'); ?></p></div>
    <?php elseif ($watchdogNotificationResult === 'throttled') : ?>
        <div class="notice notice-warning is-dismissible"><p><?php esc_html_e('Notifications skipped to avoid rapid re-sends. Please wait a moment and try again.', 'site-add-on-watchdog'); ?></p></div>
    <?php endif; ?>

    <?php if ($watchdogFailedNotificationStatus === 'resent') : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Queued the captured notification payload for resend.', 'site-add-on-watchdog'); ?></p></div>
    <?php elseif ($watchdogFailedNotificationStatus === 'missing') : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e('No failed notification payload was available to resend.', 'site-add-on-watchdog'); ?></p></div>
    <?php endif; ?>

    <?php if ($watchdogChannelTest !== '' && $watchdogChannelTestStatus !== '') : ?>
        <?php
        $watchdogChannelTestLabel = $watchdogChannelLabels[$watchdogChannelTest]
            ?? __('Notification channel', 'site-add-on-watchdog');
        ?>
        <?php if ($watchdogChannelTestStatus === 'sent') : ?>
            <div class="notice notice-success is-dismissible"><p>
                <?php
                if ($watchdogChannelTest === 'email') {
                    esc_html_e('WordPress accepted the test email for sending. This does not confirm inbox delivery. If it does not arrive, check spam and your site mail or SMTP configuration.', 'site-add-on-watchdog');
                } else {
                    printf(
                        /* translators: %s: notification channel name. */
                        esc_html__('%s test notification sent successfully.', 'site-add-on-watchdog'),
                        esc_html($watchdogChannelTestLabel)
                    );
                }
                ?>
            </p></div>
        <?php elseif ($watchdogChannelTestStatus === 'invalid_settings') : ?>
            <div class="notice notice-error is-dismissible"><p>
                <?php
                printf(
                    /* translators: %s: notification channel name. */
                    esc_html__('%s could not be tested until its settings are corrected.', 'site-add-on-watchdog'),
                    esc_html($watchdogChannelTestLabel)
                );
                ?>
            </p></div>
        <?php elseif ($watchdogChannelTestStatus === 'not_configured') : ?>
            <div class="notice notice-warning is-dismissible"><p>
                <?php
                printf(
                    /* translators: %s: notification channel name. */
                    esc_html__('%s is not configured yet.', 'site-add-on-watchdog'),
                    esc_html($watchdogChannelTestLabel)
                );
                ?>
            </p></div>
        <?php else : ?>
            <div class="notice notice-error is-dismissible"><p>
                <?php
                printf(
                    /* translators: %s: notification channel name. */
                    esc_html__('%s test delivery failed. Open the Delivery tab for details.', 'site-add-on-watchdog'),
                    esc_html($watchdogChannelTestLabel)
                );
                ?>
            </p></div>
        <?php endif; ?>
    <?php endif; ?>

    <section class="wp-watchdog-overview" aria-label="<?php esc_attr_e('Watchdog overview', 'site-add-on-watchdog'); ?>">
        <a class="wp-watchdog-stat wp-watchdog-stat--<?php echo esc_attr($watchdogRiskTone); ?>" href="#watchdog-risks">
            <span class="wp-watchdog-stat__icon dashicons dashicons-shield-alt" aria-hidden="true"></span>
            <span class="wp-watchdog-stat__label"><?php esc_html_e('Open risks', 'site-add-on-watchdog'); ?></span>
            <span class="wp-watchdog-stat__value"><?php echo esc_html(number_format_i18n($watchdogRiskTotal)); ?></span>
            <span class="wp-watchdog-stat__hint"><?php echo esc_html($watchdogRiskHint); ?></span>
        </a>
        <a class="wp-watchdog-stat" href="#watchdog-history">
            <span class="wp-watchdog-stat__icon dashicons dashicons-backup" aria-hidden="true"></span>
            <span class="wp-watchdog-stat__label"><?php esc_html_e('Last scan', 'site-add-on-watchdog'); ?></span>
            <span class="wp-watchdog-stat__value wp-watchdog-stat__value--text"><?php echo esc_html($watchdogLastScanValue); ?></span>
            <span class="wp-watchdog-stat__hint"><?php echo esc_html($watchdogLastScanHint); ?></span>
        </a>
        <a class="wp-watchdog-stat" href="#watchdog-settings">
            <span class="wp-watchdog-stat__icon dashicons dashicons-clock" aria-hidden="true"></span>
            <span class="wp-watchdog-stat__label"><?php esc_html_e('Next scan', 'site-add-on-watchdog'); ?></span>
            <span class="wp-watchdog-stat__value wp-watchdog-stat__value--text"><?php echo esc_html($watchdogNextScanValue); ?></span>
            <span class="wp-watchdog-stat__hint"><?php echo esc_html($watchdogNextScanHint); ?></span>
        </a>
        <a class="wp-watchdog-stat wp-watchdog-stat--<?php echo esc_attr($watchdogChannelTone); ?>" href="#watchdog-delivery">
            <span class="wp-watchdog-stat__icon dashicons dashicons-megaphone" aria-hidden="true"></span>
            <span class="wp-watchdog-stat__label"><?php esc_html_e('Active channels', 'site-add-on-watchdog'); ?></span>
            <span class="wp-watchdog-stat__value"><?php echo esc_html(number_format_i18n($watchdogEnabledChannelCount) . '/' . number_format_i18n(count($watchdogChannelLabels))); ?></span>
            <span class="wp-watchdog-stat__hint"><?php echo esc_html($watchdogChannelHint); ?></span>
        </a>
    </section>

    <nav class="wp-watchdog-tabs" aria-label="<?php esc_attr_e('Watchdog sections', 'site-add-on-watchdog'); ?>">
        <?php foreach ($watchdogTabs as $watchdogTabId => $watchdogTabLabel) : ?>
            <a class="wp-watchdog-tabs__tab" href="#<?php echo esc_attr($watchdogTabId); ?>" data-watchdog-tab="<?php echo esc_attr($watchdogTabId); ?>">
                <?php echo esc_html($watchdogTabLabel); ?>
                <?php if ($watchdogTabId === 'watchdog-risks' && $watchdogRiskTotal > 0) : ?>
                    <span class="wp-watchdog-tabs__count"><?php echo esc_html(number_format_i18n($watchdogRiskTotal)); ?></span>
                <?php elseif ($watchdogTabId === 'watchdog-delivery' && ($watchdogHasFailedNotification || $watchdogQueueLength > 0)) : ?>
                    <span class="wp-watchdog-tabs__dot" aria-hidden="true"></span>
                    <span class="screen-reader-text"><?php esc_html_e('Needs attention', 'site-add-on-watchdog'); ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <section class="wp-watchdog-panel" id="watchdog-risks" data-watchdog-panel>
        <div class="wp-watchdog-card">
            <div class="wp-watchdog-card__header">
                <div>
                    <h2 class="wp-watchdog-card__title"><?php esc_html_e('Potential Risks', 'site-add-on-watchdog'); ?></h2>
                    <p class="wp-watchdog-card__description"><?php esc_html_e('Plugins with a newer release, a security note in the latest changelog, or a known vulnerability.', 'site-add-on-watchdog'); ?></p>
                </div>
                <?php if ($watchdogRiskTotal > 0) : ?>
                    <a class="button" href="<?php echo esc_url(admin_url('update-core.php')); ?>"><?php esc_html_e('Review updates', 'site-add-on-watchdog'); ?></a>
                <?php endif; ?>
            </div>

            <?php if ($watchdogRiskTotal === 0) : ?>
                <div class="wp-watchdog-empty">
                    <span class="wp-watchdog-empty__icon dashicons <?php echo $watchdogLastScanAt === null ? 'dashicons-search' : 'dashicons-yes-alt'; ?>" aria-hidden="true"></span>
                    <?php if ($watchdogLastScanAt === null) : ?>
                        <p class="wp-watchdog-empty__title"><?php esc_html_e('No scan results yet', 'site-add-on-watchdog'); ?></p>
                        <p class="wp-watchdog-empty__text"><?php esc_html_e('Run a manual scan to check your plugins now.', 'site-add-on-watchdog'); ?></p>
                    <?php else : ?>
                        <p class="wp-watchdog-empty__title"><?php esc_html_e('No risks detected.', 'site-add-on-watchdog'); ?></p>
                        <p class="wp-watchdog-empty__text"><?php esc_html_e('Nothing was flagged in the latest scan.', 'site-add-on-watchdog'); ?></p>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <?php
                $watchdogColumns = [
                    'plugin'  => __('Plugin', 'site-add-on-watchdog'),
                    'local'   => __('Local Version', 'site-add-on-watchdog'),
                    'remote'  => __('Directory Version', 'site-add-on-watchdog'),
                    'reasons' => __('Reasons', 'site-add-on-watchdog'),
                    'actions' => __('Actions', 'site-add-on-watchdog'),
                ];
                $watchdogSortOptions = [
                    'plugin'      => ['sortPlugin', 'asc', __('Plugin name', 'site-add-on-watchdog')],
                    'risk_count'  => ['sortRiskCount', 'desc', __('Risk count', 'site-add-on-watchdog')],
                    'version_gap' => ['sortVersionGap', 'desc', __('Version gap', 'site-add-on-watchdog')],
                    'local'       => ['sortLocal', 'desc', __('Local version', 'site-add-on-watchdog')],
                    'remote'      => ['sortRemote', 'desc', __('Directory version', 'site-add-on-watchdog')],
                    'reasons'     => ['sortReasons', 'asc', __('Reasons', 'site-add-on-watchdog')],
                ];
                $watchdogSortableColumns = [
                    'plugin'  => ['sortPlugin', 'asc'],
                    'local'   => ['sortLocal', 'desc'],
                    'remote'  => ['sortRemote', 'desc'],
                    'reasons' => ['sortReasons', 'asc'],
                ];
                $watchdogPerPage = max(1, (int) apply_filters('site_add_on_watchdog_admin_risks_per_page', 10));
                ?>
                <div class="wp-watchdog-risk-table" data-wp-watchdog-table data-per-page="<?php echo esc_attr((string) $watchdogPerPage); ?>">
                    <div class="wp-watchdog-risk-table__controls">
                        <form method="get" class="wp-watchdog-risk-table__filter-form">
                            <input type="hidden" name="page" value="site-add-on-watchdog" />
                            <label class="wp-watchdog-risk-table__search">
                                <span class="screen-reader-text"><?php esc_html_e('Search risks', 'site-add-on-watchdog'); ?></span>
                                <input
                                    type="search"
                                    name="risk_search"
                                    value="<?php echo esc_attr($watchdogRiskSearch); ?>"
                                    placeholder="<?php esc_attr_e('Search plugin or reason', 'site-add-on-watchdog'); ?>"
                                    data-risk-search
                                />
                            </label>
                            <label class="screen-reader-text" for="wp-watchdog-risk-sort"><?php esc_html_e('Sort risks by', 'site-add-on-watchdog'); ?></label>
                            <select id="wp-watchdog-risk-sort" name="risk_sort" data-risk-sort>
                                <?php foreach ($watchdogSortOptions as $watchdogSortValue => [$watchdogSortKey, $watchdogSortDefault, $watchdogSortLabel]) : ?>
                                    <option
                                        value="<?php echo esc_attr($watchdogSortValue); ?>"
                                        data-sort-key="<?php echo esc_attr($watchdogSortKey); ?>"
                                        data-sort-default="<?php echo esc_attr($watchdogSortDefault); ?>"
                                        <?php selected($watchdogRiskSortSelection, $watchdogSortValue); ?>
                                    ><?php echo esc_html($watchdogSortLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="screen-reader-text" for="wp-watchdog-risk-order"><?php esc_html_e('Sort order', 'site-add-on-watchdog'); ?></label>
                            <select id="wp-watchdog-risk-order" name="risk_order" data-risk-order>
                                <option value="asc" <?php selected($watchdogRiskOrderSelection, 'asc'); ?>><?php esc_html_e('Ascending', 'site-add-on-watchdog'); ?></option>
                                <option value="desc" <?php selected($watchdogRiskOrderSelection, 'desc'); ?>><?php esc_html_e('Descending', 'site-add-on-watchdog'); ?></option>
                            </select>
                            <button class="button wp-watchdog-no-js" type="submit"><?php esc_html_e('Apply', 'site-add-on-watchdog'); ?></button>
                        </form>
                        <div class="wp-watchdog-risk-table__pagination" data-pagination>
                            <button type="button" class="button" data-action="prev" aria-label="<?php esc_attr_e('Previous page', 'site-add-on-watchdog'); ?>" disabled>&lsaquo;</button>
                            <span class="wp-watchdog-risk-table__page-status" data-page-status aria-live="polite"></span>
                            <button type="button" class="button" data-action="next" aria-label="<?php esc_attr_e('Next page', 'site-add-on-watchdog'); ?>" disabled>&rsaquo;</button>
                        </div>
                    </div>
                    <div class="wp-watchdog-risk-table__scroll">
                        <table class="widefat striped">
                            <thead>
                            <tr>
                                <?php foreach ($watchdogSortableColumns as $watchdogColumnKey => [$watchdogSortKey, $watchdogSortDefault]) : ?>
                                    <th scope="col" class="wp-watchdog-risk-table__col-<?php echo esc_attr($watchdogColumnKey); ?>">
                                        <button
                                            type="button"
                                            class="wp-watchdog-risk-table__sort"
                                            data-sort-key="<?php echo esc_attr($watchdogSortKey); ?>"
                                            data-sort-default="<?php echo esc_attr($watchdogSortDefault); ?>"
                                            <?php echo $watchdogColumnKey === 'plugin' ? 'data-sort-initial' : ''; ?>
                                            aria-sort="none"
                                        >
                                            <?php echo esc_html($watchdogColumns[$watchdogColumnKey]); ?>
                                            <span class="wp-watchdog-risk-table__sort-indicator" aria-hidden="true"></span>
                                        </button>
                                    </th>
                                <?php endforeach; ?>
                                <th scope="col" class="wp-watchdog-risk-table__col-actions"><?php echo esc_html($watchdogColumns['actions']); ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($watchdogRiskRows as $watchdogRiskRow) : ?>
                                <?php
                                $watchdogRisk = $watchdogRiskRow['risk'];
                                $watchdogVulnerabilities = $watchdogRiskRow['vulnerabilities'];
                                ?>
                                <tr
                                    <?php foreach ($watchdogRiskRow['attributes'] as $watchdogAttributeName => $watchdogAttributeValue) : ?>
                                        <?php echo esc_attr($watchdogAttributeName); ?>="<?php echo esc_attr($watchdogAttributeValue); ?>"
                                    <?php endforeach; ?>
                                >
                                    <td data-column="plugin" data-column-label="<?php echo esc_attr($watchdogColumns['plugin']); ?>">
                                        <strong><?php echo esc_html($watchdogRisk->pluginName); ?></strong>
                                    </td>
                                    <td data-column="local" data-column-label="<?php echo esc_attr($watchdogColumns['local']); ?>">
                                        <?php echo esc_html($watchdogRisk->localVersion); ?>
                                    </td>
                                    <td data-column="remote" data-column-label="<?php echo esc_attr($watchdogColumns['remote']); ?>">
                                        <?php echo esc_html($watchdogRisk->remoteVersion ?? __('N/A', 'site-add-on-watchdog')); ?>
                                    </td>
                                    <td data-column="reasons" data-column-label="<?php echo esc_attr($watchdogColumns['reasons']); ?>">
                                        <ul class="wp-watchdog-reasons">
                                            <?php foreach ($watchdogRisk->reasons as $watchdogReason) : ?>
                                                <li><?php echo esc_html($watchdogReason); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <?php if ($watchdogVulnerabilities !== []) : ?>
                                            <ul class="wp-watchdog-vulnerabilities" aria-label="<?php esc_attr_e('WPScan vulnerabilities:', 'site-add-on-watchdog'); ?>">
                                                <?php foreach ($watchdogVulnerabilities as $watchdogVuln) : ?>
                                                    <li>
                                                        <?php if (! empty($watchdogVuln['severity']) && ! empty($watchdogVuln['severity_label'])) : ?>
                                                            <span class="wp-watchdog-severity wp-watchdog-severity--<?php echo esc_attr(sanitize_html_class((string) $watchdogVuln['severity'])); ?>"><?php echo esc_html($watchdogVuln['severity_label']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (! empty($watchdogVuln['title'])) : ?>
                                                            <span class="wp-watchdog-vulnerability__title"><?php echo esc_html($watchdogVuln['title']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (! empty($watchdogVuln['cve'])) : ?>
                                                            <span class="wp-watchdog-vulnerability__meta"><?php echo esc_html($watchdogVuln['cve']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (! empty($watchdogVuln['fixed_in'])) : ?>
                                                            <span class="wp-watchdog-vulnerability__meta">
                                                                <?php
                                                                printf(
                                                                    /* translators: %s: plugin version number. */
                                                                    esc_html__('Fixed in %s', 'site-add-on-watchdog'),
                                                                    esc_html($watchdogVuln['fixed_in'])
                                                                );
                                                                ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </td>
                                    <td data-column="actions" data-column-label="<?php echo esc_attr($watchdogColumns['actions']); ?>">
                                        <form method="post" action="<?php echo esc_url($watchdogPostUrl); ?>">
                                            <?php wp_nonce_field($watchdogActionPrefix . '_ignore'); ?>
                                            <input type="hidden" name="action" value="<?php echo esc_attr($watchdogActionPrefix . '_ignore'); ?>">
                                            <input type="hidden" name="plugin_slug" value="<?php echo esc_attr($watchdogRisk->pluginSlug); ?>">
                                            <button class="button button-small" type="submit"><?php esc_html_e('Ignore', 'site-add-on-watchdog'); ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="wp-watchdog-card" id="watchdog-ignored">
            <h2 class="wp-watchdog-card__title"><?php esc_html_e('Ignored Plugins', 'site-add-on-watchdog'); ?></h2>
            <?php if (empty($watchdogIgnored)) : ?>
                <p class="wp-watchdog-card__description"><?php esc_html_e('No plugins are being ignored.', 'site-add-on-watchdog'); ?></p>
            <?php else : ?>
                <p class="wp-watchdog-card__description"><?php esc_html_e('Ignored plugins are skipped by every scan. Select one to watch it again.', 'site-add-on-watchdog'); ?></p>
                <ul class="wp-watchdog-chips">
                    <?php foreach ($watchdogIgnored as $watchdogIgnoredSlug) : ?>
                        <li>
                            <form method="post" action="<?php echo esc_url($watchdogPostUrl); ?>">
                                <?php wp_nonce_field($watchdogActionPrefix . '_unignore'); ?>
                                <input type="hidden" name="action" value="<?php echo esc_attr($watchdogActionPrefix . '_unignore'); ?>">
                                <input type="hidden" name="plugin_slug" value="<?php echo esc_attr($watchdogIgnoredSlug); ?>">
                                <button class="wp-watchdog-chip" type="submit">
                                    <?php echo esc_html($watchdogIgnoredSlug); ?>
                                    <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                                    <span class="screen-reader-text"><?php esc_html_e('Stop ignoring', 'site-add-on-watchdog'); ?></span>
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>

    <section class="wp-watchdog-panel" id="watchdog-history" data-watchdog-panel>
        <div class="wp-watchdog-card">
            <h2 class="wp-watchdog-card__title"><?php esc_html_e('Scan History', 'site-add-on-watchdog'); ?></h2>
            <?php require __DIR__ . '/history.php'; ?>
        </div>
    </section>

    <section class="wp-watchdog-panel" id="watchdog-settings" data-watchdog-panel>
        <form class="wp-watchdog-settings-form" method="post" action="<?php echo esc_url($watchdogPostUrl); ?>">
            <?php wp_nonce_field($watchdogActionPrefix . '_settings'); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr($watchdogActionPrefix . '_save_settings'); ?>">

            <div class="wp-watchdog-card">
                <h2 class="wp-watchdog-card__title"><?php esc_html_e('Scanning', 'site-add-on-watchdog'); ?></h2>
                <?php
                $watchdogDefaultFrequencyMessage = __('Choose how often the automatic scan should run.', 'site-add-on-watchdog');
                $watchdogTestingFrequencyMessage = sprintf(
                    /* translators: 1: interval minutes, 2: duration hours */
                    __('Testing mode sends notifications every %1$d minutes to all configured channels and automatically switches back to daily scans after %2$d hours.', 'site-add-on-watchdog'),
                    TestingMode::INTERVAL_MINUTES,
                    TestingMode::DURATION_HOURS
                );
                $watchdogIsTestingFrequency = $watchdogCurrentFrequency === 'testing';
                $watchdogTestingExpiresAt   = (int) ($watchdogNotifications['testing_expires_at'] ?? 0);
                $watchdogDailyTime          = (string) ($watchdogNotifications['daily_time'] ?? '08:00');
                $watchdogWeeklyDay          = (int) ($watchdogNotifications['weekly_day'] ?? 1);
                $watchdogWeeklyTime         = (string) ($watchdogNotifications['weekly_time'] ?? '08:00');
                $watchdogWeekdays           = [
                    1 => __('Monday', 'site-add-on-watchdog'),
                    2 => __('Tuesday', 'site-add-on-watchdog'),
                    3 => __('Wednesday', 'site-add-on-watchdog'),
                    4 => __('Thursday', 'site-add-on-watchdog'),
                    5 => __('Friday', 'site-add-on-watchdog'),
                    6 => __('Saturday', 'site-add-on-watchdog'),
                    7 => __('Sunday', 'site-add-on-watchdog'),
                ];
                ?>
                <div class="wp-watchdog-field">
                    <label class="wp-watchdog-field__label" for="wp-watchdog-notification-frequency"><?php esc_html_e('Scan frequency', 'site-add-on-watchdog'); ?></label>
                    <div class="wp-watchdog-field__control">
                        <select id="wp-watchdog-notification-frequency" name="settings[notifications][frequency]">
                            <option value="daily" <?php selected($watchdogCurrentFrequency, 'daily'); ?>><?php esc_html_e('Daily', 'site-add-on-watchdog'); ?></option>
                            <option value="weekly" <?php selected($watchdogCurrentFrequency, 'weekly'); ?>><?php esc_html_e('Weekly', 'site-add-on-watchdog'); ?></option>
                            <option value="testing" <?php selected($watchdogCurrentFrequency, 'testing'); ?>>
                                <?php
                                printf(
                                    /* translators: %d: interval minutes */
                                    esc_html__('Testing (every %d minutes)', 'site-add-on-watchdog'),
                                    (int) TestingMode::INTERVAL_MINUTES
                                );
                                ?>
                            </option>
                            <option value="manual" <?php selected($watchdogCurrentFrequency, 'manual'); ?>><?php esc_html_e('Manual (no automatic scans)', 'site-add-on-watchdog'); ?></option>
                        </select>
                        <p
                            class="description wp-watchdog-frequency-description<?php echo $watchdogIsTestingFrequency ? ' wp-watchdog-frequency-description--testing' : ''; ?>"
                            data-watchdog-frequency-description
                            data-default-message="<?php echo esc_attr($watchdogDefaultFrequencyMessage); ?>"
                            data-testing-message="<?php echo esc_attr($watchdogTestingFrequencyMessage); ?>"
                            aria-live="polite"
                        >
                            <?php echo esc_html($watchdogIsTestingFrequency ? $watchdogTestingFrequencyMessage : $watchdogDefaultFrequencyMessage); ?>
                        </p>
                        <?php if ($watchdogIsTestingFrequency && $watchdogTestingExpiresAt > $watchdogNow) : ?>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: 1: datetime, 2: relative time */
                                    esc_html__('Testing mode will automatically switch back to daily scans on %1$s (%2$s remaining).', 'site-add-on-watchdog'),
                                    esc_html(wp_date($watchdogDateFormat, $watchdogTestingExpiresAt)),
                                    esc_html(human_time_diff($watchdogNow, $watchdogTestingExpiresAt))
                                );
                                ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                <div data-watchdog-frequency-options>
                    <div class="wp-watchdog-field" data-watchdog-frequency-target="daily">
                        <label class="wp-watchdog-field__label" for="wp-watchdog-daily-time"><?php esc_html_e('Daily send time', 'site-add-on-watchdog'); ?></label>
                        <div class="wp-watchdog-field__control">
                            <input
                                id="wp-watchdog-daily-time"
                                name="settings[notifications][daily_time]"
                                type="time"
                                value="<?php echo esc_attr($watchdogDailyTime); ?>"
                                aria-describedby="wp-watchdog-daily-time-help"
                            />
                            <p class="description" id="wp-watchdog-daily-time-help"><?php esc_html_e('Time of day to start the daily scan in the WordPress site timezone.', 'site-add-on-watchdog'); ?></p>
                        </div>
                    </div>
                    <div class="wp-watchdog-field" data-watchdog-frequency-target="weekly">
                        <label class="wp-watchdog-field__label" for="wp-watchdog-weekly-day"><?php esc_html_e('Weekly send day', 'site-add-on-watchdog'); ?></label>
                        <div class="wp-watchdog-field__control">
                            <div class="wp-watchdog-field__inline">
                                <select id="wp-watchdog-weekly-day" name="settings[notifications][weekly_day]">
                                    <?php foreach ($watchdogWeekdays as $watchdogWeekdayNumber => $watchdogWeekdayLabel) : ?>
                                        <option value="<?php echo esc_attr((string) $watchdogWeekdayNumber); ?>" <?php selected($watchdogWeeklyDay, $watchdogWeekdayNumber); ?>><?php echo esc_html($watchdogWeekdayLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="screen-reader-text" for="wp-watchdog-weekly-time"><?php esc_html_e('Weekly send time', 'site-add-on-watchdog'); ?></label>
                                <input
                                    id="wp-watchdog-weekly-time"
                                    name="settings[notifications][weekly_time]"
                                    type="time"
                                    value="<?php echo esc_attr($watchdogWeeklyTime); ?>"
                                    aria-describedby="wp-watchdog-weekly-time-help"
                                />
                            </div>
                            <p class="description" id="wp-watchdog-weekly-time-help"><?php esc_html_e('Day and time to start the weekly scan in the WordPress site timezone.', 'site-add-on-watchdog'); ?></p>
                        </div>
                    </div>
                </div>
                <div class="wp-watchdog-field">
                    <label class="wp-watchdog-field__label" for="wp-watchdog-history-retention"><?php esc_html_e('History retention', 'site-add-on-watchdog'); ?></label>
                    <div class="wp-watchdog-field__control">
                        <input
                            type="number"
                            id="wp-watchdog-history-retention"
                            class="small-text"
                            name="settings[history][retention]"
                            value="<?php echo esc_attr((string) ($watchdogSettings['history']['retention'] ?? $watchdogHistoryRetention)); ?>"
                            min="1"
                            max="15"
                            step="1"
                        />
                        <p class="description"><?php esc_html_e('Number of recent scans to keep available for review and download (maximum 15).', 'site-add-on-watchdog'); ?></p>
                    </div>
                </div>
            </div>

            <?php
            $watchdogChannels = [
                'email' => [
                    'icon'   => 'dashicons-email-alt',
                    'test'   => __('Save and test email', 'site-add-on-watchdog'),
                    'fields' => [
                        'recipients' => ['text', __('Recipients (comma separated)', 'site-add-on-watchdog')],
                    ],
                    'notes'  => [
                        __('Separate addresses with commas, semicolons, or spaces. WordPress administrator accounts are always included.', 'site-add-on-watchdog'),
                        __('Email uses WordPress mail delivery. Your host must support sending mail, or you must configure an SMTP/mail plugin. Discord and other webhooks do not use this mail configuration.', 'site-add-on-watchdog'),
                    ],
                ],
                'discord' => [
                    'icon'   => 'dashicons-format-chat',
                    'test'   => __('Save and test Discord', 'site-add-on-watchdog'),
                    'fields' => [
                        'webhook' => ['url', __('Discord webhook URL', 'site-add-on-watchdog')],
                    ],
                    'notes'  => [],
                ],
                'slack' => [
                    'icon'   => 'dashicons-admin-comments',
                    'test'   => __('Save and test Slack', 'site-add-on-watchdog'),
                    'fields' => [
                        'webhook' => ['url', __('Slack webhook URL', 'site-add-on-watchdog')],
                    ],
                    'notes'  => [],
                ],
                'teams' => [
                    'icon'   => 'dashicons-groups',
                    'test'   => __('Save and test Teams', 'site-add-on-watchdog'),
                    'fields' => [
                        'webhook' => ['url', __('Teams webhook URL', 'site-add-on-watchdog')],
                    ],
                    'notes'  => [
                        __('Supports Microsoft Teams Workflows and existing Incoming Webhook connectors.', 'site-add-on-watchdog'),
                    ],
                ],
                'webhook' => [
                    'icon'   => 'dashicons-rest-api',
                    'test'   => __('Save and test webhook', 'site-add-on-watchdog'),
                    'fields' => [
                        'url'    => ['url', __('Webhook URL', 'site-add-on-watchdog')],
                        'secret' => ['password', __('Webhook secret (optional)', 'site-add-on-watchdog')],
                    ],
                    'notes'  => [
                        __('Signs the exact JSON body with HMAC-SHA256 in the X-Watchdog-Signature header. Test messages are marked in the payload.', 'site-add-on-watchdog'),
                    ],
                ],
            ];
            ?>
            <div class="wp-watchdog-card">
                <h2 class="wp-watchdog-card__title"><?php esc_html_e('Notification channels', 'site-add-on-watchdog'); ?></h2>
                <p class="wp-watchdog-card__description"><?php esc_html_e('Save and test buttons save all settings on this form, then send a test to the selected channel.', 'site-add-on-watchdog'); ?></p>
                <div class="wp-watchdog-channels">
                    <?php foreach ($watchdogChannels as $watchdogChannelKey => $watchdogChannel) : ?>
                        <?php
                        $watchdogChannelSettings = is_array($watchdogNotifications[$watchdogChannelKey] ?? null)
                            ? $watchdogNotifications[$watchdogChannelKey]
                            : [];
                        $watchdogChannelName = 'settings[notifications][' . $watchdogChannelKey . ']';
                        ?>
                        <div class="wp-watchdog-channel" data-watchdog-notification>
                            <div class="wp-watchdog-channel__header">
                                <span class="wp-watchdog-channel__icon dashicons <?php echo esc_attr($watchdogChannel['icon']); ?>" aria-hidden="true"></span>
                                <h3 class="wp-watchdog-channel__title"><?php echo esc_html($watchdogChannelLabels[$watchdogChannelKey]); ?></h3>
                                <label class="wp-watchdog-switch">
                                    <input
                                        type="checkbox"
                                        name="<?php echo esc_attr($watchdogChannelName . '[enabled]'); ?>"
                                        <?php checked(! empty($watchdogChannelSettings['enabled'])); ?>
                                        data-watchdog-toggle
                                    />
                                    <span
                                        class="wp-watchdog-switch__state"
                                        data-on="<?php esc_attr_e('On', 'site-add-on-watchdog'); ?>"
                                        data-off="<?php esc_attr_e('Off', 'site-add-on-watchdog'); ?>"
                                        aria-hidden="true"
                                    ></span>
                                    <span class="screen-reader-text">
                                        <?php
                                        printf(
                                            /* translators: %s: notification channel name. */
                                            esc_html__('Enable %s notifications', 'site-add-on-watchdog'),
                                            esc_html($watchdogChannelLabels[$watchdogChannelKey])
                                        );
                                        ?>
                                    </span>
                                </label>
                            </div>
                            <div class="wp-watchdog-channel__body" data-watchdog-fields>
                                <?php foreach ($watchdogChannel['fields'] as $watchdogFieldKey => [$watchdogFieldType, $watchdogFieldLabel]) : ?>
                                    <?php $watchdogFieldId = 'wp-watchdog-' . $watchdogChannelKey . '-' . $watchdogFieldKey; ?>
                                    <p class="wp-watchdog-channel__field">
                                        <label for="<?php echo esc_attr($watchdogFieldId); ?>"><?php echo esc_html($watchdogFieldLabel); ?></label>
                                        <input
                                            type="<?php echo esc_attr($watchdogFieldType); ?>"
                                            id="<?php echo esc_attr($watchdogFieldId); ?>"
                                            class="regular-text"
                                            name="<?php echo esc_attr($watchdogChannelName . '[' . $watchdogFieldKey . ']'); ?>"
                                            value="<?php echo esc_attr((string) ($watchdogChannelSettings[$watchdogFieldKey] ?? '')); ?>"
                                            <?php echo $watchdogFieldType === 'password' ? 'autocomplete="new-password"' : ''; ?>
                                        />
                                    </p>
                                <?php endforeach; ?>
                                <?php foreach ($watchdogChannel['notes'] as $watchdogChannelNote) : ?>
                                    <p class="description"><?php echo esc_html($watchdogChannelNote); ?></p>
                                <?php endforeach; ?>
                                <p class="wp-watchdog-channel__actions">
                                    <button class="button" type="submit" name="test_channel" value="<?php echo esc_attr($watchdogChannelKey); ?>"><?php echo esc_html($watchdogChannel['test']); ?></button>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="wp-watchdog-card">
                <h2 class="wp-watchdog-card__title"><?php esc_html_e('Vulnerability data', 'site-add-on-watchdog'); ?></h2>
                <div class="wp-watchdog-field">
                    <label class="wp-watchdog-field__label" for="wp-watchdog-wpscan-api-key"><?php esc_html_e('WPScan API key', 'site-add-on-watchdog'); ?></label>
                    <div class="wp-watchdog-field__control">
                        <input
                            type="password"
                            id="wp-watchdog-wpscan-api-key"
                            class="regular-text"
                            name="settings[notifications][wpscan_api_key]"
                            value="<?php echo esc_attr((string) ($watchdogNotifications['wpscan_api_key'] ?? '')); ?>"
                            autocomplete="new-password"
                        />
                        <p class="description"><?php esc_html_e('Optional. Provide your own WPScan API key to enrich vulnerability reports.', 'site-add-on-watchdog'); ?></p>
                    </div>
                </div>
            </div>

            <p class="wp-watchdog-settings-form__submit">
                <button class="button button-primary" type="submit"><?php esc_html_e('Save settings', 'site-add-on-watchdog'); ?></button>
            </p>
        </form>
    </section>

    <section class="wp-watchdog-panel" id="watchdog-delivery" data-watchdog-panel>
        <div class="wp-watchdog-card">
            <div class="wp-watchdog-card__header">
                <h2 class="wp-watchdog-card__title"><?php esc_html_e('Scheduler', 'site-add-on-watchdog'); ?></h2>
                <?php if ($watchdogIsCronDisabled) : ?>
                    <span class="wp-watchdog-badge wp-watchdog-badge--warn"><?php esc_html_e('WP-Cron disabled', 'site-add-on-watchdog'); ?></span>
                <?php else : ?>
                    <span class="wp-watchdog-badge wp-watchdog-badge--ok"><?php esc_html_e('WP-Cron enabled', 'site-add-on-watchdog'); ?></span>
                <?php endif; ?>
            </div>
            <?php if ($watchdogIsCronDisabled) : ?>
                <p class="wp-watchdog-card__description"><?php esc_html_e('WP-Cron appears disabled. Use a real cron job or the server endpoint below to keep scans running.', 'site-add-on-watchdog'); ?></p>
            <?php else : ?>
                <p class="wp-watchdog-card__description"><?php esc_html_e('WP-Cron is enabled. Scheduled tasks depend on site traffic or a server cron job.', 'site-add-on-watchdog'); ?></p>
            <?php endif; ?>

            <details class="wp-watchdog-details">
                <summary><?php esc_html_e('Trigger scans from a server cron job', 'site-add-on-watchdog'); ?></summary>
                <div class="wp-watchdog-details__body">
                    <div class="wp-watchdog-copy">
                        <span class="wp-watchdog-copy__label"><?php esc_html_e('Server cron endpoint:', 'site-add-on-watchdog'); ?></span>
                        <code id="wp-watchdog-cron-endpoint"><?php echo esc_html($watchdogCronEndpoint); ?></code>
                        <button type="button" class="button button-small" data-watchdog-copy="wp-watchdog-cron-endpoint" hidden><?php esc_html_e('Copy', 'site-add-on-watchdog'); ?></button>
                    </div>
                    <?php if ($watchdogCronSecretPersisted) : ?>
                        <div class="wp-watchdog-copy">
                            <span class="wp-watchdog-copy__label"><?php esc_html_e('Generated Cron secret:', 'site-add-on-watchdog'); ?></span>
                            <code id="wp-watchdog-cron-secret"><?php echo esc_html($watchdogCronSecret); ?></code>
                            <button type="button" class="button button-small" data-watchdog-copy="wp-watchdog-cron-secret" hidden><?php esc_html_e('Copy', 'site-add-on-watchdog'); ?></button>
                        </div>
                        <div class="wp-watchdog-copy">
                            <span class="wp-watchdog-copy__label"><?php esc_html_e('Authenticated command:', 'site-add-on-watchdog'); ?></span>
                            <code id="wp-watchdog-cron-command"><?php echo esc_html(sprintf('curl -X POST -H "X-Watchdog-Cron-Key: %s" "%s"', $watchdogCronSecret, $watchdogCronEndpoint)); ?></code>
                            <button type="button" class="button button-small" data-watchdog-copy="wp-watchdog-cron-command" hidden><?php esc_html_e('Copy', 'site-add-on-watchdog'); ?></button>
                        </div>
                        <p class="description"><?php esc_html_e('Use POST and keep the generated secret private. The header-authenticated request can trigger scans or notification retries even when wp-cron is disabled.', 'site-add-on-watchdog'); ?></p>
                    <?php endif; ?>
                </div>
            </details>
        </div>

        <div class="wp-watchdog-card-grid">
            <div class="wp-watchdog-card">
                <div class="wp-watchdog-card__header">
                    <h2 class="wp-watchdog-card__title"><?php esc_html_e('Queue status', 'site-add-on-watchdog'); ?></h2>
                    <span class="wp-watchdog-badge <?php echo $watchdogQueueLength > 0 ? 'wp-watchdog-badge--warn' : 'wp-watchdog-badge--ok'; ?>">
                        <?php
                        printf(
                            /* translators: %s: number of queued jobs. */
                            esc_html__('Queued jobs: %s', 'site-add-on-watchdog'),
                            esc_html(number_format_i18n($watchdogQueueLength))
                        );
                        ?>
                    </span>
                </div>
                <?php if ($watchdogQueueLength > 0 && $watchdogQueueNextAttempt) : ?>
                    <p class="wp-watchdog-card__description">
                        <?php
                        printf(
                            /* translators: 1: scheduled time */
                            esc_html__('Next attempt: %1$s', 'site-add-on-watchdog'),
                            esc_html(wp_date($watchdogDateFormat, (int) $watchdogQueueNextAttempt))
                        );
                        ?>
                    </p>
                <?php else : ?>
                    <p class="wp-watchdog-card__description"><?php esc_html_e('No pending notifications in the queue.', 'site-add-on-watchdog'); ?></p>
                <?php endif; ?>
            </div>

            <div class="wp-watchdog-card">
                <div class="wp-watchdog-card__header">
                    <h2 class="wp-watchdog-card__title"><?php esc_html_e('Captured notification payloads', 'site-add-on-watchdog'); ?></h2>
                    <?php if ($watchdogHasFailedNotification) : ?>
                        <span class="wp-watchdog-badge wp-watchdog-badge--danger"><?php esc_html_e('Delivery failed', 'site-add-on-watchdog'); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($watchdogHasFailedNotification) : ?>
                    <?php
                    $watchdogFailedTime = isset($watchdogLastFailedNotification['failed_at'])
                        ? (int) $watchdogLastFailedNotification['failed_at']
                        : $watchdogNow;
                    $watchdogFailedChannel = $watchdogLastFailedNotification['description']
                        ?: ($watchdogLastFailedNotification['channel'] ?? __('Unknown channel', 'site-add-on-watchdog'));
                    $watchdogFailedError = (string) ($watchdogLastFailedNotification['last_error'] ?? '');
                    ?>
                    <p class="wp-watchdog-card__description">
                        <?php
                        printf(
                            /* translators: 1: human readable time, 2: channel name */
                            esc_html__('Last failure recorded %1$s via %2$s.', 'site-add-on-watchdog'),
                            esc_html(wp_date($watchdogDateFormat, $watchdogFailedTime)),
                            esc_html($watchdogFailedChannel)
                        );
                        ?>
                    </p>
                    <?php if ($watchdogFailedError !== '') : ?>
                        <p class="wp-watchdog-error-text"><?php echo esc_html($watchdogFailedError); ?></p>
                    <?php endif; ?>
                    <div class="wp-watchdog-actions">
                        <form method="post" action="<?php echo esc_url($watchdogPostUrl); ?>">
                            <?php wp_nonce_field($watchdogActionPrefix . '_resend_failed_notification'); ?>
                            <input type="hidden" name="action" value="<?php echo esc_attr($watchdogActionPrefix . '_resend_failed_notification'); ?>" />
                            <button class="button button-primary" type="submit">
                                <span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span>
                                <?php esc_html_e('Re-queue payload', 'site-add-on-watchdog'); ?>
                            </button>
                        </form>
                        <form method="post" action="<?php echo esc_url($watchdogPostUrl); ?>" data-watchdog-download>
                            <?php wp_nonce_field($watchdogActionPrefix . '_download_failed_notification'); ?>
                            <input type="hidden" name="action" value="<?php echo esc_attr($watchdogActionPrefix . '_download_failed_notification'); ?>" />
                            <button class="button" type="submit">
                                <span class="dashicons dashicons-download" aria-hidden="true"></span>
                                <?php esc_html_e('Download payload', 'site-add-on-watchdog'); ?>
                            </button>
                        </form>
                    </div>
                <?php else : ?>
                    <p class="wp-watchdog-card__description"><?php esc_html_e('No failed notification payload has been captured yet.', 'site-add-on-watchdog'); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>
