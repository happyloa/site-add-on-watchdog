<?php

use Brain\Monkey\Functions;
use Watchdog\Models\Risk;
use Watchdog\Notifications\MessageFormatter;
use Watchdog\Version;

class MessageFormatterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('__')->alias(static fn ($text) => $text);
        Functions\when('esc_html__')->alias(static fn ($text) => $text);
        Functions\when('esc_html')->alias(static fn ($text) => $text);
        Functions\when('esc_attr')->alias(static fn ($text) => $text);
        Functions\when('esc_url')->alias(static fn ($url) => $url);
        Functions\when('admin_url')->alias(static fn ($path = '') => 'https://example.com/wp-admin/' . ltrim($path, '/'));
    }

    public function testDiscordPayloadTruncatesContentAndDisablesMentions(): void
    {
        $formatter = new MessageFormatter();
        $payload = $formatter->discord(str_repeat('x', 2100));

        self::assertSame(['parse' => []], $payload['allowed_mentions']);
        self::assertStringEndsWith('…', $payload['content']);
        self::assertLessThanOrEqual(2002, strlen($payload['content']));
    }

    public function testMalformedVulnerabilityDetailsDoNotBreakEmailFormatting(): void
    {
        $formatter = new MessageFormatter();
        $risk = new Risk(
            'example',
            'Example Plugin',
            '1.0.0',
            '2.0.0',
            ['Update available'],
            ['vulnerabilities' => ['unexpected-string', null, ['title' => 'Valid item']]]
        );

        $message = $formatter->email([$risk]);

        self::assertStringContainsString('Example Plugin', $message);
        self::assertStringContainsString('Valid item', $message);
    }

    public function testCustomWebhookIncludesVersionedMetadata(): void
    {
        $formatter = new MessageFormatter();
        $payload = $formatter->customWebhook([], $formatter->plainText([]));

        self::assertSame(Version::NUMBER, $payload['meta']['version']);
        self::assertSame(0, $payload['meta']['count']);
        self::assertSame([], $payload['risks']);
    }
}
