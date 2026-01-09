<?php

namespace Watchdog\Models;

class Risk
{
    /**
     * @param string $pluginSlug    Plugin directory slug.
     * @param string $pluginName    Human-readable plugin name.
     * @param string $localVersion  Installed version string.
     * @param string|null $remoteVersion Latest version available in the directory.
     * @param string[] $reasons     List of risk reason messages.
     * @param array<string, mixed> $details Additional details such as vulnerabilities.
     */
    public function __construct(
        public readonly string $pluginSlug,
        public readonly string $pluginName,
        public readonly string $localVersion,
        public readonly ?string $remoteVersion,
        public readonly array $reasons,
        public readonly array $details = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'plugin_slug'   => $this->pluginSlug,
            'plugin_name'   => $this->pluginName,
            'local_version' => $this->localVersion,
            'remote_version' => $this->remoteVersion,
            'reasons'       => $this->reasons,
            'details'       => $this->details,
        ];
    }
}
