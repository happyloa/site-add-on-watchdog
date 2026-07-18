<?php

use Watchdog\Admin\RiskSorter;
use Watchdog\Models\Risk;

class RiskSorterTest extends TestCase
{
    public function testSortsSemanticVersionsAndPlacesMissingLast(): void
    {
        $risks = [
            new Risk('missing', 'Missing', 'N/A', null, ['Unknown']),
            new Risk('newer', 'Newer', '2.10.0', '3.0.0', ['Update']),
            new Risk('older', 'Older', '2.9.0', '3.0.0', ['Update']),
        ];

        $sorted = (new RiskSorter())->sort($risks, 'local', 'asc');

        self::assertSame(['older', 'newer', 'missing'], array_map(
            static fn (Risk $risk): string => $risk->pluginSlug,
            $sorted
        ));
    }

    public function testSortsByCombinedRiskSignalCount(): void
    {
        $low = new Risk('low', 'Low', '1.0.0', '1.1.0', ['Update']);
        $high = new Risk(
            'high',
            'High',
            '1.0.0',
            '2.0.0',
            ['Update', 'Security'],
            ['vulnerabilities' => [['title' => 'CVE']]]
        );

        $sorted = (new RiskSorter())->sort([$low, $high], 'risk_count', 'desc');

        self::assertSame(['high', 'low'], array_map(
            static fn (Risk $risk): string => $risk->pluginSlug,
            $sorted
        ));
    }

    public function testUnknownSortKeyPreservesInputOrder(): void
    {
        $first = new Risk('first', 'First', '1.0.0', null, ['Risk']);
        $second = new Risk('second', 'Second', '1.0.0', null, ['Risk']);

        self::assertSame([$first, $second], (new RiskSorter())->sort([$first, $second], 'unknown', 'asc'));
    }
}
