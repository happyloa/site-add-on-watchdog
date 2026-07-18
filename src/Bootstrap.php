<?php

namespace Watchdog;

use Watchdog\Cli\NotificationQueueCommand;
use Watchdog\Cli\ScanCommand;
use Watchdog\Repository\RiskRepository;
use Watchdog\Repository\SettingsRepository;
use Watchdog\Services\NotificationQueue;
use Watchdog\Services\VersionComparator;
use Watchdog\Services\WPScanClient;

/**
 * Creates the plugin services and owns WordPress lifecycle registration.
 */
final class Bootstrap
{
    private function __construct(
        private readonly string $pluginFile,
        private readonly Plugin $plugin,
        private readonly AdminPage $adminPage
    ) {
    }

    public static function create(string $pluginFile): self
    {
        $settingsRepository = new SettingsRepository();
        $riskRepository     = new RiskRepository();
        $settings           = $settingsRepository->get();
        $wpscanClient       = new WPScanClient(
            (string) ($settings['notifications']['wpscan_api_key'] ?? '')
        );
        $scanner            = new Scanner(
            $riskRepository,
            new VersionComparator(),
            $wpscanClient
        );
        $notificationQueue  = new NotificationQueue();
        $notifier           = new Notifier($settingsRepository, $notificationQueue);
        $plugin             = new Plugin(
            $scanner,
            $riskRepository,
            $settingsRepository,
            $notifier
        );
        $adminPage          = new AdminPage(
            $riskRepository,
            $settingsRepository,
            $plugin,
            $notifier
        );

        return new self($pluginFile, $plugin, $adminPage);
    }

    public function register(): void
    {
        $this->plugin->register();
        $this->adminPage->register();

        add_filter('plugin_action_links_' . plugin_basename($this->pluginFile), [$this, 'filterActionLinks']);
        add_filter('plugin_row_meta', [$this, 'filterRowMeta'], 10, 2);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('watchdog scan', new ScanCommand($this->plugin));
            \WP_CLI::add_command('watchdog notifications flush', new NotificationQueueCommand($this->plugin));
        }

        register_activation_hook($this->pluginFile, [$this, 'activate']);
        register_deactivation_hook($this->pluginFile, [$this, 'deactivate']);
    }

    /**
     * Activation only schedules background work. A remote scan must never make
     * plugin activation time out or leave the site on a fatal error screen.
     */
    public function activate(): void
    {
        $this->plugin->schedule();
    }

    public function deactivate(): void
    {
        $this->plugin->deactivate();
    }

    /**
     * @param string[] $links
     * @return string[]
     */
    public function filterActionLinks(array $links): array
    {
        $settingsUrl  = admin_url('admin.php?page=site-add-on-watchdog');
        $settingsLink = sprintf(
            '<a href="%s">%s</a>',
            esc_url($settingsUrl),
            esc_html__('Settings', 'site-add-on-watchdog')
        );

        array_unshift($links, $settingsLink);

        return $links;
    }

    /**
     * @param string[] $links
     * @return string[]
     */
    public function filterRowMeta(array $links, string $file): array
    {
        if ($file !== plugin_basename($this->pluginFile)) {
            return $links;
        }

        $authorUrl = 'https://www.worksbyaaron.com/';
        foreach ($links as $index => $link) {
            if (! str_contains($link, $authorUrl)) {
                continue;
            }

            $links[$index] = str_replace(
                '<a ',
                '<a target="_blank" rel="noopener noreferrer" ',
                $link
            );
            break;
        }

        return $links;
    }
}
