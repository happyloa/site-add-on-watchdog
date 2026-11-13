<?php

namespace Watchdog\Services;

use Watchdog\Version;

class WPScanClient
{
    private const CACHE_TTL_HOURS = 12;
    private const ERROR_TTL_HOURS = 6;

    /**
     * @param string|\Closure|null $apiKey The key, or a callback that returns it. The callback
     *                                     keeps the settings from being read while plugins load.
     */
    public function __construct(private string|\Closure|null $apiKey)
    {
    }

    public function isEnabled(): bool
    {
        return $this->apiKey() !== '';
    }

    private function apiKey(): string
    {
        if ($this->apiKey instanceof \Closure) {
            $this->apiKey = (string) ($this->apiKey)();
        }

        return (string) $this->apiKey;
    }

    public function fetchVulnerabilities(string $pluginSlug, string $pluginVersion = ''): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $pluginVersion = $this->normalizeVersion($pluginVersion);
        if ($pluginVersion === '') {
            return [];
        }

        $cacheKey = $this->getCacheKey($pluginSlug, $pluginVersion);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        if ($this->isInCooldown()) {
            return [];
        }

        // The version-filtered endpoint is Enterprise-only, so filter the public response locally.
        $response = wp_remote_get(
            sprintf('https://wpscan.com/api/v3/plugins/%s', rawurlencode($pluginSlug)),
            [
                'headers' => [
                    'Authorization' => sprintf('Token token=%s', $this->apiKey()),
                    'Accept'        => 'application/json',
                ],
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->recordErrorResponse($code);
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $rawVulnerabilities = $this->extractVulnerabilities($body, $pluginSlug);
        if ($rawVulnerabilities === []) {
            delete_transient($this->getErrorKey());
            set_transient($cacheKey, [], $this->getCacheTtl());
            return [];
        }

        $activeVulnerabilities = array_values(array_filter(
            $rawVulnerabilities,
            fn (array $vulnerability): bool => $this->affectsVersion($vulnerability, $pluginVersion)
        ));
        $vulnerabilities = array_map(
            fn (array $vulnerability): array => $this->normalizeVulnerability($vulnerability),
            $activeVulnerabilities
        );

        delete_transient($this->getErrorKey());
        set_transient($cacheKey, $vulnerabilities, $this->getCacheTtl());

        return $vulnerabilities;
    }

    private function getCacheKey(string $pluginSlug, string $pluginVersion): string
    {
        return sprintf(
            '%s_wpscan_%s',
            Version::PREFIX,
            hash('sha256', sanitize_key($pluginSlug) . "\0" . $pluginVersion)
        );
    }

    private function getErrorKey(): string
    {
        return Version::PREFIX . '_wpscan_error';
    }

    private function isInCooldown(): bool
    {
        $error = get_transient($this->getErrorKey());
        if (! is_array($error) || ! isset($error['code']) || ! is_numeric($error['code'])) {
            return false;
        }

        $code = (int) $error['code'];

        return $code === 429 || $code >= 500;
    }

    /**
     * @param mixed $body
     * @return array<int, array<string, mixed>>
     */
    private function extractVulnerabilities(mixed $body, string $pluginSlug): array
    {
        if (! is_array($body)) {
            return [];
        }

        $pluginData = $body;
        if (isset($body[$pluginSlug]) && is_array($body[$pluginSlug])) {
            $pluginData = $body[$pluginSlug];
        }

        if (! isset($pluginData['vulnerabilities']) || ! is_array($pluginData['vulnerabilities'])) {
            return [];
        }

        return array_values(array_filter($pluginData['vulnerabilities'], 'is_array'));
    }

    /**
     * @param array<string, mixed> $vulnerability
     */
    private function affectsVersion(array $vulnerability, string $pluginVersion): bool
    {
        $fixedIn = $vulnerability['fixed_in'] ?? null;
        if ($fixedIn === null || $fixedIn === '') {
            return true;
        }
        if (! is_scalar($fixedIn)) {
            return true;
        }

        $installedVersion = $this->normalizeVersion($pluginVersion);
        $fixedVersion = $this->normalizeVersion((string) $fixedIn);
        if ($installedVersion === '' || $fixedVersion === '') {
            return true;
        }

        return version_compare($installedVersion, $fixedVersion, '<');
    }

    private function normalizeVersion(string $version): string
    {
        return preg_replace('/^v(?=\d)/i', '', trim($version)) ?? '';
    }

    /**
     * @param array<string, mixed> $vulnerability
     * @return array<string, mixed>
     */
    private function normalizeVulnerability(array $vulnerability): array
    {
        $title = isset($vulnerability['title']) && is_scalar($vulnerability['title'])
            ? (string) $vulnerability['title']
            : '';
        $references = isset($vulnerability['references']) && is_array($vulnerability['references'])
            ? $vulnerability['references']
            : [];
        $cve = $vulnerability['cve'] ?? null;
        if (is_array($cve)) {
            $cve = reset($cve);
        }
        if (($cve === null || $cve === '') && isset($references['cve'])) {
            $referenceCve = is_array($references['cve'])
                ? reset($references['cve'])
                : $references['cve'];
            if (is_scalar($referenceCve)) {
                $cve = (string) $referenceCve;
            }
        }
        if (! is_scalar($cve)) {
            $cve = null;
        }

        $cvssScore = $vulnerability['cvss_score'] ?? null;
        if (
            $cvssScore === null
            && isset($vulnerability['cvss'])
            && is_array($vulnerability['cvss'])
        ) {
            $cvssScore = $vulnerability['cvss']['score'] ?? null;
        }
        if (! is_scalar($cvssScore)) {
            $cvssScore = null;
        }

        $fixedIn = $vulnerability['fixed_in'] ?? null;
        if (! is_scalar($fixedIn)) {
            $fixedIn = null;
        }
        $discovered = $vulnerability['discovered_date']
            ?? $vulnerability['published_date']
            ?? null;
        if (! is_scalar($discovered)) {
            $discovered = null;
        }

        return [
            'title'       => $title,
            'references'  => $references,
            'fixed_in'    => $fixedIn,
            'cve'         => $cve === null ? null : (string) $cve,
            'cvss_score'  => $cvssScore,
            'discovered'  => $discovered,
        ];
    }

    private function getCacheTtl(): int
    {
        $hour = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;

        return self::CACHE_TTL_HOURS * $hour;
    }

    private function getErrorTtl(): int
    {
        $hour = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;

        return self::ERROR_TTL_HOURS * $hour;
    }

    private function recordErrorResponse(int $code): void
    {
        if ($code !== 429 && $code < 500) {
            return;
        }

        $message = $code === 429
            ? __('WPScan API rate limited; queries are paused temporarily.', 'site-add-on-watchdog')
            : __('WPScan API is temporarily unavailable; queries are paused.', 'site-add-on-watchdog');

        set_transient(
            $this->getErrorKey(),
            [
                'code'    => $code,
                'message' => $message,
            ],
            $this->getErrorTtl()
        );
    }
}
