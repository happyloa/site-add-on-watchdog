<?php

use Brain\Monkey\Functions;
use Watchdog\Services\NotificationValidator;

class NotificationValidatorTest extends TestCase
{
    public function testAcceptsConfiguredHttpsChannels(): void
    {
        $validator = new NotificationValidator();

        $errors = $validator->validate([
            'email' => [
                'enabled' => true,
                'recipients' => 'owner@example.com, alerts@example.com',
            ],
            'discord' => ['enabled' => true, 'webhook' => 'https://discord.com/api/webhooks/example'],
            'slack' => ['enabled' => true, 'webhook' => 'https://hooks.slack.com/services/example'],
            'teams' => ['enabled' => true, 'webhook' => 'https://example.webhook.office.com/hook'],
            'webhook' => ['enabled' => true, 'url' => 'https://example.com/watchdog'],
        ]);

        self::assertSame([], $errors);
    }

    public function testRejectsEnabledInsecureOrMalformedChannels(): void
    {
        Functions\when('__')->alias(static fn ($message) => $message);

        $validator = new NotificationValidator();
        $errors = $validator->validate([
            'email' => ['enabled' => true, 'recipients' => 'not-an-email'],
            'discord' => ['enabled' => true, 'webhook' => 'http://example.com/hook'],
            'slack' => ['enabled' => true, 'webhook' => 'not-a-url'],
            'teams' => ['enabled' => true, 'webhook' => ''],
            'webhook' => ['enabled' => true, 'url' => 'ftp://example.com/hook'],
        ]);

        self::assertSame(['email', 'discord', 'slack', 'teams', 'webhook'], array_keys($errors));
        self::assertStringContainsString('valid HTTPS', $errors['discord']);
    }

    public function testIgnoresDisabledChannelCredentials(): void
    {
        $validator = new NotificationValidator();

        self::assertSame([], $validator->validate([
            'email' => ['enabled' => false, 'recipients' => 'not-an-email'],
            'discord' => ['enabled' => false, 'webhook' => 'not-a-url'],
        ]));
    }
}
