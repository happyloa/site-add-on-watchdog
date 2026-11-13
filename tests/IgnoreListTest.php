<?php

use Brain\Monkey\Functions;
use Watchdog\Models\Risk;
use Watchdog\Repository\RiskRepository;

class IgnoreListTest extends TestCase
{
    public function testAddAndRemoveIgnore(): void
    {
        $options = [];

        Functions\when('get_option')->alias(static function (string $key, $default = []) use (&$options) {
            return $options[$key] ?? $default;
        });

        Functions\when('sanitize_text_field')->alias(static fn ($value) => $value);

        Functions\expect('update_option')->zeroOrMoreTimes()->andReturnUsing(static function (string $key, $value) use (&$options) {
            $options[$key] = $value;
            return true;
        });

        $repository = new RiskRepository();
        $repository->addIgnore('plugin-one');
        $repository->addIgnore('plugin-two');
        $repository->removeIgnore('plugin-one');

        $this->assertSame(['plugin-two'], $repository->ignored());
    }

    public function testIgnoringHidesASavedRiskWithoutWaitingForAScan(): void
    {
        $options = [
            'siteadwa_risks' => [
                (new Risk('plugin-one', 'Plugin One', '1.0.0', '1.2.0', ['Reason']))->toArray(),
                (new Risk('plugin-two', 'Plugin Two', '2.0.0', '2.1.0', ['Reason']))->toArray(),
            ],
        ];

        Functions\when('get_option')->alias(static function (string $key, $default = []) use (&$options) {
            return $options[$key] ?? $default;
        });
        Functions\when('sanitize_text_field')->alias(static fn ($value) => $value);
        Functions\when('update_option')->alias(static function (string $key, $value) use (&$options) {
            $options[$key] = $value;

            return true;
        });

        $repository = new RiskRepository();
        $slugs      = static fn (): array => array_map(
            static fn (Risk $risk): string => $risk->pluginSlug,
            $repository->all()
        );

        $repository->addIgnore('plugin-one');
        $this->assertSame(['plugin-two'], $slugs());

        $repository->removeIgnore('plugin-one');
        $this->assertSame(['plugin-one', 'plugin-two'], $slugs());
    }
}
