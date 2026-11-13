<?php
/**
 * @var int   $watchdogHistoryRetention
 * @var int   $watchdogHistoryDisplay
 * @var array $watchdogHistoryRecords
 * @var array $watchdogHistoryDownloads
 */
defined('ABSPATH') || exit;

$watchdogHistoryFormat = sprintf('%s %s', get_option('date_format', 'Y-m-d'), get_option('time_format', 'H:i'));
?>

<?php if (empty($watchdogHistoryRecords)) : ?>
    <p class="wp-watchdog-card__description"><?php esc_html_e('No scans have been recorded yet. Run a scan to populate your history.', 'site-add-on-watchdog'); ?></p>
<?php else : ?>
    <p class="wp-watchdog-card__description">
        <?php
        printf(
            /* translators: 1: number of scans shown, 2: total scans retained */
            esc_html__('Showing the last %1$s scans (retaining %2$s in total).', 'site-add-on-watchdog'),
            esc_html(number_format_i18n($watchdogHistoryDisplay)),
            esc_html(number_format_i18n($watchdogHistoryRetention))
        );
        ?>
    </p>
    <ul class="wp-watchdog-history">
        <?php foreach ($watchdogHistoryRecords as $watchdogRecord) : ?>
            <?php
            $watchdogDownloads = $watchdogHistoryDownloads[$watchdogRecord['run_at']] ?? [];
            $watchdogRecordRiskCount = (int) $watchdogRecord['risk_count'];
            ?>
            <li class="wp-watchdog-history__item">
                <span class="wp-watchdog-history__date">
                    <span class="dashicons dashicons-backup" aria-hidden="true"></span>
                    <?php echo esc_html(wp_date($watchdogHistoryFormat, $watchdogRecord['run_at'])); ?>
                </span>
                <span class="wp-watchdog-badge <?php echo $watchdogRecordRiskCount > 0 ? 'wp-watchdog-badge--warn' : 'wp-watchdog-badge--ok'; ?>">
                    <?php
                    printf(
                        /* translators: %s: number of risks. */
                        esc_html(_n('%s risk', '%s risks', $watchdogRecordRiskCount, 'site-add-on-watchdog')),
                        esc_html(number_format_i18n($watchdogRecordRiskCount))
                    );
                    ?>
                </span>
                <span class="wp-watchdog-history__downloads">
                    <?php if (! empty($watchdogDownloads['json'])) : ?>
                        <a class="button button-small" href="<?php echo esc_url($watchdogDownloads['json']); ?>"><?php esc_html_e('Download JSON', 'site-add-on-watchdog'); ?></a>
                    <?php endif; ?>
                    <?php if (! empty($watchdogDownloads['csv'])) : ?>
                        <a class="button button-small" href="<?php echo esc_url($watchdogDownloads['csv']); ?>"><?php esc_html_e('Download CSV', 'site-add-on-watchdog'); ?></a>
                    <?php endif; ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
