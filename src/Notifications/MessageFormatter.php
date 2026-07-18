<?php

namespace Watchdog\Notifications;

use Watchdog\Models\Risk;
use Watchdog\Version;

/**
 * Creates platform-specific notification payloads without sending them.
 */
final class MessageFormatter
{
    /**
     * @param Risk[] $risks
     */
    public function plainText(array $risks): string
    {
        if ($risks === []) {
            return implode("\n", [
                __('No plugin risks detected on your site at this time.', 'site-add-on-watchdog'),
                '',
                sprintf(
                    /* translators: %s: Plugins admin URL. */
                    __('Review plugins here: %s', 'site-add-on-watchdog'),
                    esc_url(admin_url('plugins.php'))
                ),
            ]);
        }

        $lines = [
            __('Potential plugin risks detected on your site:', 'site-add-on-watchdog'),
            '',
        ];

        foreach ($risks as $risk) {
            $lines[] = $risk->pluginName;
            $lines[] = sprintf(
                /* translators: %s: currently installed plugin version. */
                __('Current version: %s', 'site-add-on-watchdog'),
                $risk->localVersion ?? __('Unknown', 'site-add-on-watchdog')
            );
            $lines[] = sprintf(
                /* translators: %s: latest available plugin version. */
                __('Available version: %s', 'site-add-on-watchdog'),
                $risk->remoteVersion ?? __('N/A', 'site-add-on-watchdog')
            );
            foreach ($risk->reasons as $reason) {
                $lines[] = '- ' . $reason;
            }
            $lines[] = '';
        }

        $lines[] = sprintf(
            /* translators: %s: Updates admin URL. */
            __('Update plugins here: %s', 'site-add-on-watchdog'),
            esc_url(admin_url('update-core.php'))
        );

        return implode("\n", $lines);
    }

    /**
     * @param Risk[] $risks
     * @return array<string, mixed>
     */
    public function slack(array $risks, string $plainTextReport): array
    {
        $hasRisks  = $risks !== [];
        $adminUrl  = admin_url('admin.php?page=site-add-on-watchdog');
        $updateUrl = admin_url('update-core.php');
        $blocks    = [
            [
                'type' => 'header',
                'text' => [
                    'type'  => 'plain_text',
                    'text'  => __('Site Add-on Watchdog Risk Alert', 'site-add-on-watchdog'),
                    'emoji' => true,
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $hasRisks
                        ? __('Potential plugin risks detected on your site:', 'site-add-on-watchdog')
                        : __('No plugin risks detected on your site at this time.', 'site-add-on-watchdog'),
                ],
            ],
        ];

        if ($hasRisks) {
            $blocks[] = ['type' => 'divider'];
        }

        foreach (array_slice($risks, 0, 35) as $risk) {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $this->slackRiskSection($risk),
                ],
            ];
        }

        $blocks[] = [
            'type' => 'context',
            'elements' => [
                [
                    'type' => 'mrkdwn',
                    'text' => sprintf(
                        '<%s|%s>',
                        $adminUrl,
                        __('Open the Watchdog dashboard', 'site-add-on-watchdog')
                    ),
                ],
            ],
        ];
        $blocks[] = [
            'type' => 'actions',
            'elements' => [
                [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => __('Review updates', 'site-add-on-watchdog'),
                        'emoji' => true,
                    ],
                    'url' => $updateUrl,
                    'style' => 'primary',
                ],
                [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => __('View dashboard', 'site-add-on-watchdog'),
                        'emoji' => true,
                    ],
                    'url' => $adminUrl,
                ],
            ],
        ];

        return [
            'text' => $this->truncate($plainTextReport, 3000),
            'blocks' => $blocks,
            'attachments' => [
                [
                    'color' => '#2271b1',
                    'text' => __('Stay ahead of plugin risks with Site Add-on Watchdog.', 'site-add-on-watchdog'),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function discord(string $plainTextReport): array
    {
        return [
            'username' => 'Site Add-on Watchdog',
            'content' => $this->truncate($plainTextReport, 2000),
            'allowed_mentions' => ['parse' => []],
        ];
    }

    /**
     * @param Risk[] $risks
     * @return array<string, mixed>
     */
    public function teams(array $risks, string $plainTextReport): array
    {
        $hasRisks   = $risks !== [];
        $adminUrl   = admin_url('admin.php?page=site-add-on-watchdog');
        $updateUrl  = admin_url('update-core.php');
        $riskBlocks = [];
        $introText  = __(
            'Review the cards below for plugin, version, and vulnerability details.',
            'site-add-on-watchdog'
        );
        $noRiskText = __('Everything looks good after the latest scan.', 'site-add-on-watchdog');

        foreach (array_slice($risks, 0, 20) as $risk) {
            $riskBlocks[] = [
                'activityTitle' => $risk->pluginName,
                'facts' => $this->teamsRiskFacts($risk),
                'markdown' => true,
            ];
        }

        $sections = [
            [
                'activityTitle' => $hasRisks
                    ? __('Potential plugin risks detected on your site:', 'site-add-on-watchdog')
                    : __('No plugin risks detected on your site at this time.', 'site-add-on-watchdog'),
                'markdown' => true,
                'text' => $hasRisks ? $introText : $noRiskText,
            ],
        ];

        if ($hasRisks) {
            $sections = array_merge($sections, $riskBlocks);
        }

        return [
            '@type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'text' => $this->truncate($plainTextReport, 4000),
            'summary' => __('Site Add-on Watchdog Risk Alert', 'site-add-on-watchdog'),
            'themeColor' => '2271B1',
            'title' => __('Site Add-on Watchdog Risk Alert', 'site-add-on-watchdog'),
            'sections' => $sections,
            'potentialAction' => [
                [
                    '@type' => 'OpenUri',
                    'name' => __('Review updates', 'site-add-on-watchdog'),
                    'targets' => [['os' => 'default', 'uri' => $updateUrl]],
                ],
                [
                    '@type' => 'OpenUri',
                    'name' => __('Open Watchdog dashboard', 'site-add-on-watchdog'),
                    'targets' => [['os' => 'default', 'uri' => $adminUrl]],
                ],
            ],
        ];
    }

    /**
     * @param Risk[] $risks
     * @return array<string, mixed>
     */
    public function customWebhook(array $risks, string $plainTextReport): array
    {
        return [
            'message' => $plainTextReport,
            'risks' => array_map(static fn (Risk $risk): array => $risk->toArray(), $risks),
            'links' => [
                'dashboard' => admin_url('admin.php?page=site-add-on-watchdog'),
                'updates' => admin_url('update-core.php'),
            ],
            'meta' => [
                'count' => count($risks),
                'generated' => time(),
                'source' => 'Site Add-on Watchdog',
                'version' => Version::NUMBER,
            ],
        ];
    }

    /**
     * @param Risk[] $risks
     */
    public function email(array $risks): string
    {
        $brandColor   = '#1d2327';
        $accentColor  = '#2271b1';
        $background   = '#f6f7f7';
        $containerCss = implode(' ', [
            'margin:0 auto;',
            'max-width:680px;',
            'width:100%;',
            'font-family:-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;',
            'color:#1d2327;',
        ]);

        if ($risks === []) {
            $pluginsUrl = esc_url(admin_url('plugins.php'));

            return sprintf(
                '<div style="background:%1$s; padding:22px 24px; border-radius:10px; border:1px solid #dcdcde;">'
                . '<h1 style="margin:0 0 10px 0; font-size:22px; color:%2$s;">%3$s</h1>'
                . '<p style="font-size:14px; line-height:1.7; margin:0 0 10px 0;">%4$s</p>'
                . '<p style="font-size:14px; line-height:1.7; margin:0 0 16px 0;">%5$s</p>'
                . '<a href="%6$s" style="display:inline-block; padding:10px 16px; background:%7$s;'
                . ' color:#ffffff; text-decoration:none; border-radius:6px; font-weight:600;">%8$s</a>'
                . '</div>'
                . '<p style="font-size:12px; color:#4b5563; margin:12px 0 0 0;">%9$s</p>',
                esc_attr($background),
                esc_attr($brandColor),
                esc_html__('Site Add-on Watchdog', 'site-add-on-watchdog'),
                esc_html__('Latest scan completed — no risks detected.', 'site-add-on-watchdog'),
                esc_html__('No plugin risks detected on your site at this time.', 'site-add-on-watchdog'),
                $pluginsUrl,
                esc_attr($accentColor),
                esc_html__('Review plugins', 'site-add-on-watchdog'),
                esc_html__('You are receiving this update from Site Add-on Watchdog.', 'site-add-on-watchdog')
            );
        }

        $cards = '';
        foreach ($risks as $risk) {
            $reasons = '';
            foreach ($risk->reasons as $reason) {
                $reasons .= sprintf('<li style="margin-bottom:6px; line-height:1.5;">%s</li>', esc_html($reason));
            }

            foreach ($this->vulnerabilities($risk) as $vulnerability) {
                $title = isset($vulnerability['title']) ? (string) $vulnerability['title'] : '';
                $cve   = isset($vulnerability['cve']) ? (string) $vulnerability['cve'] : '';
                $fixed = isset($vulnerability['fixed_in']) ? (string) $vulnerability['fixed_in'] : '';
                $label = trim($title . ($cve !== '' ? ' - ' . $cve : ''));
                if ($fixed !== '') {
                    /* translators: %s: plugin version number. */
                    $label .= ' ' . sprintf(__('(Fixed in %s)', 'site-add-on-watchdog'), $fixed);
                }

                if ($label !== '') {
                    $badge   = $this->severityBadge($vulnerability);
                    $content = ($badge !== '' ? $badge . ' ' : '') . esc_html($label);
                    $reasons .= sprintf('<li style="margin-bottom:6px; line-height:1.5;">%s</li>', $content);
                }
            }

            $cards .= sprintf(
                '<tr><td style="padding:10px 12px;">'
                . '<table role="presentation" width="100%%" cellspacing="0" cellpadding="0"'
                . ' style="border:1px solid #e6e6e6; border-radius:10px; overflow:hidden;">'
                . '<tr><td style="background:%1$s; color:#ffffff; padding:14px 16px;'
                . ' font-weight:700; font-size:16px;">%2$s</td></tr>'
                . '<tr><td style="padding:14px 16px; background:#ffffff;">'
                . '<p style="margin:0 0 6px 0; font-size:13px; color:#4b5563;">'
                . '%3$s: <strong style="color:#1d2327;">%4$s</strong>'
                . '<span style="color:#4b5563;"> | </span>'
                . '%5$s: <strong style="color:#1d2327;">%6$s</strong></p>'
                . '<ul style="margin:10px 0 0 18px; padding:0; color:#1d2327;">%7$s</ul>'
                . '</td></tr></table></td></tr>',
                esc_attr($accentColor),
                esc_html($risk->pluginName),
                esc_html__('Current Version', 'site-add-on-watchdog'),
                esc_html($risk->localVersion ?? __('Unknown', 'site-add-on-watchdog')),
                esc_html__('Directory Version', 'site-add-on-watchdog'),
                esc_html($risk->remoteVersion ?? __('N/A', 'site-add-on-watchdog')),
                $reasons
            );
        }

        $updateUrl = esc_url(admin_url('update-core.php'));

        return sprintf(
            '<table role="presentation" width="100%%" cellspacing="0" cellpadding="0"'
            . ' style="background:%1$s; padding:24px 0;"><tr><td align="center">'
            . '<table role="presentation" width="100%%" cellspacing="0" cellpadding="0" style="%2$s">'
            . '<tr><td style="background:%3$s; color:#ffffff; padding:22px 26px;'
            . ' border-radius:10px 10px 0 0;"><h1 style="margin:0; font-size:22px;">%4$s</h1>'
            . '<p style="margin:6px 0 0; font-size:14px;">%5$s</p></td></tr>'
            . '<tr><td style="background:#ffffff; border-left:1px solid #dcdcde;'
            . ' border-right:1px solid #dcdcde;"><table role="presentation" width="100%%"'
            . ' cellspacing="0" cellpadding="0">%6$s</table></td></tr>'
            . '<tr><td style="background:#ffffff; border:1px solid #dcdcde; border-top:0;'
            . ' padding:16px 26px 22px 26px;"><p style="margin:0 0 14px 0; font-size:14px;'
            . ' line-height:1.6;">%7$s</p><a href="%8$s" style="display:inline-block; padding:10px 16px;'
            . ' background:%9$s; color:#ffffff; text-decoration:none; border-radius:6px;'
            . ' font-weight:600;">%10$s</a></td></tr>'
            . '<tr><td style="text-align:center; font-size:12px; color:#4b5563; padding:14px 10px;">'
            . '%11$s</td></tr></table></td></tr></table>',
            esc_attr($background),
            esc_attr($containerCss),
            esc_attr($brandColor),
            esc_html__('Site Add-on Watchdog', 'site-add-on-watchdog'),
            esc_html__('Potential plugin risks detected on your site', 'site-add-on-watchdog'),
            $cards,
            esc_html__(
                'These plugins need security or maintenance updates. Update them as soon as possible.',
                'site-add-on-watchdog'
            ),
            $updateUrl,
            esc_attr($accentColor),
            esc_html__('Review updates', 'site-add-on-watchdog'),
            esc_html__('You are receiving this update from Site Add-on Watchdog.', 'site-add-on-watchdog')
        );
    }

    private function slackRiskSection(Risk $risk): string
    {
        $lines = [
            sprintf('*%s*', $risk->pluginName),
            sprintf(
                '• %s %s',
                __('Current version', 'site-add-on-watchdog'),
                $risk->localVersion ?? __('Unknown', 'site-add-on-watchdog')
            ),
            sprintf(
                '• %s %s',
                __('Directory version', 'site-add-on-watchdog'),
                $risk->remoteVersion ?? __('N/A', 'site-add-on-watchdog')
            ),
        ];

        foreach ($risk->reasons as $reason) {
            $lines[] = '• ' . $reason;
        }

        foreach ($this->vulnerabilities($risk) as $vulnerability) {
            $summary = [];
            if (! empty($vulnerability['severity_label'])) {
                $summary[] = $this->slackSeverity(
                    (string) ($vulnerability['severity'] ?? ''),
                    (string) $vulnerability['severity_label']
                );
            }
            foreach (['title', 'cve'] as $field) {
                if (! empty($vulnerability[$field])) {
                    $summary[] = (string) $vulnerability[$field];
                }
            }
            if (! empty($vulnerability['fixed_in'])) {
                /* translators: %s: plugin version number. */
                $summary[] = sprintf(__('Fixed in %s', 'site-add-on-watchdog'), $vulnerability['fixed_in']);
            }
            if ($summary !== []) {
                $lines[] = '• ' . implode(' - ', $summary);
            }
        }

        return implode("\n", $lines);
    }

    private function slackSeverity(string $severity, string $label): string
    {
        $emoji = [
            'severe' => '🚨',
            'high' => '🔴',
            'medium' => '🟠',
            'low' => '🟢',
        ][strtolower($severity)] ?? '⚪';

        return $emoji . ' ' . $label;
    }

    /**
     * @return array<int, array{name:string, value:string}>
     */
    private function teamsRiskFacts(Risk $risk): array
    {
        $facts = [
            [
                'name' => __('Current version', 'site-add-on-watchdog'),
                'value' => (string) ($risk->localVersion ?? __('Unknown', 'site-add-on-watchdog')),
            ],
            [
                'name' => __('Directory version', 'site-add-on-watchdog'),
                'value' => (string) ($risk->remoteVersion ?? __('N/A', 'site-add-on-watchdog')),
            ],
        ];

        if ($risk->reasons !== []) {
            $facts[] = [
                'name' => __('Reasons', 'site-add-on-watchdog'),
                'value' => implode("\n", $risk->reasons),
            ];
        }

        $labels = [];
        foreach ($this->vulnerabilities($risk) as $vulnerability) {
            $summary = [];
            if (! empty($vulnerability['severity_label'])) {
                $summary[] = '[' . $vulnerability['severity_label'] . ']';
            }
            foreach (['title', 'cve'] as $field) {
                if (! empty($vulnerability[$field])) {
                    $summary[] = (string) $vulnerability[$field];
                }
            }
            if (! empty($vulnerability['fixed_in'])) {
                /* translators: %s: plugin version number. */
                $summary[] = sprintf(__('Fixed in %s', 'site-add-on-watchdog'), $vulnerability['fixed_in']);
            }
            if ($summary !== []) {
                $labels[] = implode(' - ', $summary);
            }
        }

        if ($labels !== []) {
            $facts[] = [
                'name' => __('Vulnerabilities', 'site-add-on-watchdog'),
                'value' => implode("\n", $labels),
            ];
        }

        return $facts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function vulnerabilities(Risk $risk): array
    {
        $vulnerabilities = $risk->details['vulnerabilities'] ?? [];

        return is_array($vulnerabilities)
            ? array_values(array_filter($vulnerabilities, 'is_array'))
            : [];
    }

    private function severityBadge(array $vulnerability): string
    {
        if (empty($vulnerability['severity']) || empty($vulnerability['severity_label'])) {
            return '';
        }

        return sprintf(
            '<span style="%s">%s</span>',
            esc_attr($this->emailSeverityStyle((string) $vulnerability['severity'])),
            esc_html((string) $vulnerability['severity_label'])
        );
    }

    private function emailSeverityStyle(string $severity): string
    {
        $baseStyle = 'display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; '
            . 'font-weight:600; text-transform:uppercase; letter-spacing:0.04em;';
        $palette = [
            'low' => ['background' => '#e7f7ed', 'color' => '#1c5f3a'],
            'medium' => ['background' => '#fff4d6', 'color' => '#7a5a00'],
            'high' => ['background' => '#fde4df', 'color' => '#922424'],
            'severe' => ['background' => '#fbe0e6', 'color' => '#80102a'],
        ];
        $colors = $palette[$severity] ?? $palette['low'];

        return sprintf(
            '%s background:%s; color:%s;',
            $baseStyle,
            $colors['background'],
            $colors['color']
        );
    }

    private function truncate(string $text, int $limit): string
    {
        if ($limit < 2) {
            return '';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($length <= $limit) {
            return $text;
        }

        $truncated = function_exists('mb_substr')
            ? mb_substr($text, 0, $limit - 1)
            : substr($text, 0, $limit - 1);

        return rtrim($truncated) . '…';
    }
}
