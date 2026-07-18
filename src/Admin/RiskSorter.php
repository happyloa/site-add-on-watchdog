<?php

namespace Watchdog\Admin;

use Watchdog\Models\Risk;

final class RiskSorter
{
    /**
     * @param Risk[] $risks
     * @return Risk[]
     */
    public function sort(array $risks, string $sortKey, string $sortOrder): array
    {
        $direction = $sortOrder === 'desc' ? -1 : 1;

        usort($risks, function (Risk $left, Risk $right) use ($sortKey, $direction): int {
            $comparison = match ($sortKey) {
                'plugin' => $this->compareText($left->pluginName, $right->pluginName),
                'local' => $this->compareVersions($left->localVersion, $right->localVersion),
                'remote' => $this->compareVersions($left->remoteVersion, $right->remoteVersion),
                'reasons' => $this->compareText(
                    $this->reasonSortValue($left),
                    $this->reasonSortValue($right)
                ),
                'risk_count' => $this->riskSignalCount($left) <=> $this->riskSignalCount($right),
                'version_gap' => $this->versionGapScore($left) <=> $this->versionGapScore($right),
                default => 0,
            };

            return $comparison * $direction;
        });

        return $risks;
    }

    private function compareText(string $left, string $right): int
    {
        $leftNormalized  = $this->normalizeText($left);
        $rightNormalized = $this->normalizeText($right);

        return $leftNormalized <=> $rightNormalized;
    }

    private function normalizeText(string $value): string
    {
        $normalized = function_exists('remove_accents') ? remove_accents($value) : $value;

        return strtolower($normalized);
    }

    private function reasonSortValue(Risk $risk): string
    {
        $parts = $risk->reasons;
        foreach ($this->vulnerabilities($risk) as $vulnerability) {
            foreach (['severity_label', 'title', 'cve'] as $field) {
                if (! empty($vulnerability[$field])) {
                    $parts[] = (string) $vulnerability[$field];
                }
            }
        }

        return implode(' ', $parts);
    }

    private function riskSignalCount(Risk $risk): int
    {
        return count($risk->reasons) + count($this->vulnerabilities($risk));
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

    private function compareVersions(?string $left, ?string $right): int
    {
        $leftTokens  = $this->versionTokens($left);
        $rightTokens = $this->versionTokens($right);

        if ($leftTokens['missing'] && $rightTokens['missing']) {
            return 0;
        }
        if ($leftTokens['missing']) {
            return 1;
        }
        if ($rightTokens['missing']) {
            return -1;
        }

        $maxLength = max(count($leftTokens['tokens']), count($rightTokens['tokens']));
        for ($index = 0; $index < $maxLength; $index++) {
            $leftToken  = $leftTokens['tokens'][$index] ?? null;
            $rightToken = $rightTokens['tokens'][$index] ?? null;

            if ($leftToken === null && $rightToken === null) {
                return 0;
            }
            if ($leftToken === null) {
                return $this->isNumericToken($rightToken) ? -1 : 1;
            }
            if ($rightToken === null) {
                return $this->isNumericToken($leftToken) ? 1 : -1;
            }

            $leftIsNumeric  = $this->isNumericToken($leftToken);
            $rightIsNumeric = $this->isNumericToken($rightToken);
            if ($leftIsNumeric && $rightIsNumeric) {
                $comparison = (int) $leftToken <=> (int) $rightToken;
                if ($comparison !== 0) {
                    return $comparison;
                }
                continue;
            }
            if ($leftIsNumeric !== $rightIsNumeric) {
                return $leftIsNumeric ? 1 : -1;
            }

            $comparison = strtolower($leftToken) <=> strtolower($rightToken);
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    /**
     * @return array{tokens:string[], missing:bool}
     */
    private function versionTokens(?string $version): array
    {
        $trimmed = trim((string) $version);
        if ($trimmed === '' || strtolower($trimmed) === 'n/a') {
            return ['tokens' => [], 'missing' => true];
        }

        preg_match_all('/[0-9]+|[a-zA-Z]+/', $trimmed, $matches);
        $tokens = isset($matches[0]) && is_array($matches[0]) ? $matches[0] : [];

        return ['tokens' => $tokens, 'missing' => $tokens === []];
    }

    private function isNumericToken(?string $token): bool
    {
        return is_string($token) && preg_match('/^\d+$/', $token) === 1;
    }

    private function versionGapScore(Risk $risk): int
    {
        return abs(
            $this->versionScore($risk->remoteVersion ?? '') - $this->versionScore($risk->localVersion)
        );
    }

    private function versionScore(string $version): int
    {
        preg_match_all('/\d+/', $version, $matches);
        $numbers = isset($matches[0]) && is_array($matches[0]) ? $matches[0] : [];
        $weights = [100000000, 100000, 100, 1];
        $score   = 0;

        foreach ($weights as $index => $weight) {
            if (isset($numbers[$index])) {
                $score += ((int) $numbers[$index]) * $weight;
            }
        }

        return $score;
    }
}
