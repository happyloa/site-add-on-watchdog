<?php

use function Brain\Monkey\Functions\expect;
use function Brain\Monkey\Functions\when;
use Watchdog\Models\Risk;
use Watchdog\Notifier;
use Watchdog\Plugin;
use Watchdog\Repository\RiskRepository;
use Watchdog\Repository\SettingsRepository;
use Watchdog\Scanner;

if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        public function __construct(
            private array $params = [],
            private array $headers = [],
            private array $queryParams = [],
            private string $method = 'POST'
        ) {
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        public function get_header(string $key): mixed
        {
            foreach ($this->headers as $name => $value) {
                if (strtolower((string) $name) === strtolower($key)) {
                    return $value;
                }
            }

            return null;
        }

        public function get_query_params(): array
        {
            return $this->queryParams;
        }

        public function get_method(): string
        {
            return $this->method;
        }
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            private string $code = '',
            private string $message = '',
            private mixed $data = null
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_data(): mixed
        {
            return $this->data;
        }
    }
}

class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        when('delete_transient')->justReturn(true);
    }

    public function testCronRouteOnlyAcceptsPostRequests(): void
    {
        expect('register_rest_route')
            ->once()
            ->withArgs(static function (string $namespace, string $route, array $args): bool {
                self::assertSame('site-add-on-watchdog/v1', $namespace);
                self::assertSame('/cron', $route);
                self::assertSame(['POST'], $args['methods']);

                return true;
            });

        $plugin = $this->createPluginWithCronSecret('secret123');
        $plugin->registerRestRoutes();
    }

    public function testCronEndpointUrlDoesNotExposeSecret(): void
    {
        expect('rest_url')
            ->once()
            ->with('site-add-on-watchdog/v1/cron')
            ->andReturn('https://example.test/wp-json/site-add-on-watchdog/v1/cron');

        $plugin = $this->createPluginWithCronSecret('secret123');

        self::assertSame(
            'https://example.test/wp-json/site-add-on-watchdog/v1/cron',
            $plugin->getCronEndpointUrl()
        );
    }

    public function testScheduleTriggersOverdueCatchUpForTesting(): void
    {
        when('site_url')->justReturn('https://example.test');
        expect('wp_remote_post')
            ->once()
            ->withArgs(static function (string $url, array $args): bool {
                self::assertSame('https://example.test', $url);
                self::assertSame(0.01, $args['timeout']);
                self::assertFalse($args['blocking']);
                self::assertArrayNotHasKey('sslverify', $args);

                return true;
            })
            ->andReturn(null);
        when('get_option')->alias(static function (string $name) {
            return match ($name) {
                'siteadwa_cron_status' => [
                    'overdue_streak' => 0,
                    'cron_disabled'  => false,
                ],
                'timezone_string' => '',
                'gmt_offset'      => 0,
                default           => null,
            };
        });
        when('is_admin')->justReturn(false);
        when('current_user_can')->justReturn(false);
        when('update_option')->justReturn(true);
        when('_get_cron_array')->justReturn([]);

        when('wp_next_scheduled')->alias(static function (string $hook) {
            return match ($hook) {
                'siteadwa_scheduled_scan'   => time() - 2_000,
                'siteadwa_notification_queue' => false,
                default => false,
            };
        });
        expect('wp_get_schedule')->once()->andReturn('testing');
        expect('wp_get_schedules')->once()->andReturn([
            'testing' => ['interval' => 1_200],
        ]);
        expect('wp_schedule_event')
            ->once()
            ->withArgs(static function (int $timestamp, string $schedule, string $hook): bool {
                return $schedule === 'siteadwa_notification_queue'
                    && $hook === 'siteadwa_notification_queue'
                    && $timestamp >= time();
            });
        expect('wp_schedule_single_event')->once();

        $scanner = $this->createMock(Scanner::class);
        $riskRepository = $this->createMock(RiskRepository::class);
        $settingsRepository = $this->createMock(SettingsRepository::class);
        $settingsRepository->method('get')->willReturn([
            'notifications' => ['frequency' => 'testing'],
        ]);
        $notifier = $this->createMock(Notifier::class);

        $plugin = new Plugin($scanner, $riskRepository, $settingsRepository, $notifier);
        $plugin->schedule();
    }

    public function testScheduleWarnsWhenCronIsDisabled(): void
    {
        if (! defined('DISABLE_WP_CRON')) {
            define('DISABLE_WP_CRON', true);
        }

        when('site_url')->justReturn('https://example.test');
        when('wp_remote_post')->justReturn(null);
        when('get_option')->alias(static function (string $name) {
            return match ($name) {
                'siteadwa_cron_status' => [
                    'overdue_streak' => 1,
                    'cron_disabled'  => true,
                ],
                'timezone_string' => '',
                'gmt_offset'      => 0,
                default           => null,
            };
        });
        when('is_admin')->justReturn(false);
        when('current_user_can')->justReturn(false);
        when('update_option')->justReturn(true);
        when('_get_cron_array')->justReturn([]);

        when('wp_next_scheduled')->alias(static function (string $hook) {
            return match ($hook) {
                'siteadwa_scheduled_scan'   => time() - 3_000,
                'siteadwa_notification_queue' => false,
                default => false,
            };
        });
        expect('wp_get_schedule')->once()->andReturn('testing');
        expect('wp_get_schedules')->once()->andReturn([
            'testing' => ['interval' => 1_200],
        ]);
        expect('spawn_cron')->never();
        expect('wp_schedule_event')
            ->once()
            ->withArgs(static function (int $timestamp, string $schedule, string $hook): bool {
                return $schedule === 'siteadwa_notification_queue'
                    && $hook === 'siteadwa_notification_queue'
                    && $timestamp >= time();
            });
        expect('wp_schedule_single_event')->once();

        $scanner = $this->createMock(Scanner::class);
        $riskRepository = $this->createMock(RiskRepository::class);
        $settingsRepository = $this->createMock(SettingsRepository::class);
        $settingsRepository->method('get')->willReturn([
            'notifications' => ['frequency' => 'testing'],
        ]);
        $notifier = $this->createMock(Notifier::class);

        $plugin = new Plugin($scanner, $riskRepository, $settingsRepository, $notifier);
        $plugin->schedule();
    }

    public function testTestingFrequencyAlwaysNotifies(): void
    {
        $scanner = $this->createMock(Scanner::class);
        $scanner->method('scan')->willReturn([]);

        $riskRepository = $this->createMock(RiskRepository::class);
        $riskRepository
            ->expects(self::once())
            ->method('save')
            ->with([], self::isType('int'), RiskRepository::DEFAULT_HISTORY_RETENTION);

        $settings = [
            'notifications' => ['frequency' => 'testing'],
            'history'       => ['retention' => RiskRepository::DEFAULT_HISTORY_RETENTION],
            'last_notification' => 'previous-hash',
        ];

        $settingsRepository = $this->createMock(SettingsRepository::class);
        $settingsRepository->method('get')->willReturn($settings);
        $settingsRepository
            ->expects(self::once())
            ->method('saveNotificationHash')
            ->with(self::isType('string'));

        $notifier = $this->createMock(Notifier::class);
        $notifier->expects(self::once())->method('notify')->with([]);

        when('wp_json_encode')->alias(static fn ($data) => json_encode($data, JSON_THROW_ON_ERROR));

        $plugin = new Plugin($scanner, $riskRepository, $settingsRepository, $notifier);
        $plugin->runScan();
    }

    public function testOverdueScheduleDoesNotPileCatchUpEvents(): void
    {
        when('site_url')->justReturn('https://example.test');
        when('wp_remote_post')->justReturn(null);
        when('get_option')->alias(static function (string $name) {
            return match ($name) {
                'siteadwa_cron_status' => [
                    'overdue_streak' => 0,
                    'cron_disabled'  => false,
                ],
                'timezone_string' => '',
                'gmt_offset'      => 0,
                default           => null,
            };
        });
        when('is_admin')->justReturn(false);
        when('current_user_can')->justReturn(false);
        when('update_option')->justReturn(true);
        when('_get_cron_array')->justReturn([
            time() + 90 => [
                'siteadwa_scheduled_scan' => [
                    [
                        'schedule' => false,
                        'args'     => [],
                        'interval' => false,
                    ],
                ],
            ],
        ]);

        when('wp_next_scheduled')->alias(static function (string $hook) {
            return match ($hook) {
                'siteadwa_scheduled_scan'   => time() - 2_500,
                'siteadwa_notification_queue' => false,
                default => false,
            };
        });
        expect('wp_get_schedule')->once()->andReturn('testing');
        expect('wp_get_schedules')->once()->andReturn([
            'testing' => ['interval' => 1_200],
        ]);
        expect('wp_schedule_event')
            ->once()
            ->withArgs(static function (int $timestamp, string $schedule, string $hook): bool {
                return $schedule === 'siteadwa_notification_queue'
                    && $hook === 'siteadwa_notification_queue'
                    && $timestamp >= time();
            });
        expect('wp_schedule_single_event')->never();

        $scanner = $this->createMock(Scanner::class);
        $riskRepository = $this->createMock(RiskRepository::class);
        $settingsRepository = $this->createMock(SettingsRepository::class);
        $settingsRepository->method('get')->willReturn([
            'notifications' => ['frequency' => 'testing'],
        ]);
        $notifier = $this->createMock(Notifier::class);

        $plugin = new Plugin($scanner, $riskRepository, $settingsRepository, $notifier);
        $plugin->schedule();
    }

    public function testManualScanNotificationsAreThrottled(): void
    {
        $risk = new Risk('example/plugin', 'Example Plugin', '1.0.0', '1.1.0', ['Outdated plugin version']);

        $scanner = $this->createMock(Scanner::class);
        $scanner->method('scan')->willReturn([$risk]);

        $riskRepository = $this->createMock(RiskRepository::class);
        $riskRepository
            ->expects(self::exactly(2))
            ->method('save')
            ->with([$risk], self::isType('int'), RiskRepository::DEFAULT_HISTORY_RETENTION);

        $initialSettings = [
            'notifications' => [
                'frequency' => 'testing',
                'testing_expires_at' => 0,
                'last_manual_notification_at' => 0,
            ],
            'history' => ['retention' => RiskRepository::DEFAULT_HISTORY_RETENTION],
            'last_notification' => '',
        ];

        $throttledSettings = [
            'notifications' => [
                'frequency' => 'testing',
                'testing_expires_at' => 0,
                'last_manual_notification_at' => time() - 20,
            ],
            'history' => ['retention' => RiskRepository::DEFAULT_HISTORY_RETENTION],
            'last_notification' => '',
        ];

        $settingsRepository = $this->createMock(SettingsRepository::class);
        $settingsRepository
            ->expects(self::exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls($initialSettings, $throttledSettings);
        $settingsRepository
            ->expects(self::once())
            ->method('saveNotificationHash')
            ->with(self::isType('string'));
        $settingsRepository
            ->expects(self::once())
            ->method('saveManualNotificationTime')
            ->with(self::isType('int'));

        $notifier = $this->createMock(Notifier::class);
        $notifier->expects(self::once())->method('notify')->with([$risk]);

        when('wp_json_encode')->alias(static fn ($data) => json_encode($data, JSON_THROW_ON_ERROR));

        $plugin = new Plugin($scanner, $riskRepository, $settingsRepository, $notifier);

        $plugin->runScan(true, 'manual');
        $plugin->runScan(true, 'manual');
    }

    public function testQueueProcessorScheduledWhenFrequencyIsManual(): void
    {
        when('get_option')->alias(static function (string $name) {
            return match ($name) {
                'siteadwa_cron_status' => [
                    'overdue_streak' => 0,
                    'cron_disabled'  => false,
                ],
                'timezone_string' => '',
                'gmt_offset'      => 0,
                default           => null,
            };
        });
        when('update_option')->justReturn(true);
        when('is_admin')->justReturn(false);
        when('current_user_can')->justReturn(false);
        when('wp_get_schedule')->justReturn('daily');
        when('wp_get_schedules')->justReturn([
            'daily' => ['interval' => 86_400],
        ]);

        when('wp_next_scheduled')->alias(static function (string $hook) {
            static $scanCalls = 0;

            if ($hook === 'siteadwa_scheduled_scan') {
                $scanCalls++;

                if ($scanCalls <= 2) {
                    return time() + 600;
                }

                return false;
            }

            return false;
        });

        expect('wp_schedule_event')
            ->once()
            ->withArgs(static function (int $timestamp, string $schedule, string $hook): bool {
                return $schedule === 'siteadwa_notification_queue'
                    && $hook === 'siteadwa_notification_queue';
            });

        expect('wp_unschedule_event')
            ->once()
            ->withArgs(static function (int $timestamp, string $hook): bool {
                return $hook === 'siteadwa_scheduled_scan' && $timestamp > time();
            });

        expect('wp_schedule_single_event')->never();

        $scanner = $this->createMock(Scanner::class);
        $riskRepository = $this->createMock(RiskRepository::class);
        $settingsRepository = $this->createMock(SettingsRepository::class);
        $settingsRepository->method('get')->willReturn([
            'notifications' => ['frequency' => 'manual'],
        ]);
        $notifier = $this->createMock(Notifier::class);

        $plugin = new Plugin($scanner, $riskRepository, $settingsRepository, $notifier);
        $plugin->schedule();
    }

    public function testAuthorizeCronRequestRejectsEmptyStoredSecret(): void
    {
        when('__')->alias(static fn (string $message): string => $message);
        $plugin = $this->createPluginWithCronSecret('');

        $request = new WP_REST_Request([], [], ['key' => 'anything']);
        $result  = $plugin->authorizeCronRequest($request);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('watchdog_cron_secret_missing', $result->get_error_code());
        self::assertSame(['status' => 403], $result->get_error_data());
    }

    public function testAuthorizeCronRequestAllowsMatchingHeaderSecret(): void
    {
        $plugin = $this->createPluginWithCronSecret('secret123');

        $request = new WP_REST_Request([], ['x-watchdog-cron-key' => 'secret123']);
        self::assertTrue($plugin->authorizeCronRequest($request));
    }

    public function testAuthorizeCronRequestAllowsMatchingBearerSecret(): void
    {
        $plugin = $this->createPluginWithCronSecret('secret123');

        $request = new WP_REST_Request([], ['authorization' => 'Bearer secret123']);
        self::assertTrue($plugin->authorizeCronRequest($request));
    }

    public function testAuthorizeCronRequestTemporarilyAllowsMatchingQuerySecret(): void
    {
        $plugin = $this->createPluginWithCronSecret('secret123');

        $request = new WP_REST_Request([], [], ['key' => 'secret123']);
        self::assertTrue($plugin->authorizeCronRequest($request));
    }

    public function testAuthorizeCronRequestDoesNotFallBackFromInvalidHeaderToQuerySecret(): void
    {
        when('__')->alias(static fn (string $message): string => $message);
        $plugin = $this->createPluginWithCronSecret('secret123');

        $request = new WP_REST_Request(
            [],
            ['X-Watchdog-Cron-Key' => 'wrong-secret'],
            ['key' => 'secret123']
        );
        $result = $plugin->authorizeCronRequest($request);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('watchdog_cron_secret_invalid', $result->get_error_code());
        self::assertSame(['status' => 403], $result->get_error_data());
    }

    public function testAuthorizeCronRequestRejectsGetRequests(): void
    {
        when('__')->alias(static fn (string $message): string => $message);
        $plugin = $this->createPluginWithCronSecret('secret123');

        $request = new WP_REST_Request([], ['X-Watchdog-Cron-Key' => 'secret123'], [], 'GET');
        $result  = $plugin->authorizeCronRequest($request);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('watchdog_cron_method_not_allowed', $result->get_error_code());
        self::assertSame(['status' => 405], $result->get_error_data());
    }

    public function testAuthorizeCronRequestRejectsQueryMethodOverride(): void
    {
        when('__')->alias(static fn (string $message): string => $message);
        $plugin = $this->createPluginWithCronSecret('secret123');

        $request = new WP_REST_Request(
            [],
            ['X-Watchdog-Cron-Key' => 'secret123'],
            ['_method' => 'POST'],
            'POST'
        );
        $result = $plugin->authorizeCronRequest($request);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('watchdog_cron_method_not_allowed', $result->get_error_code());
        self::assertSame(['status' => 405], $result->get_error_data());
    }

    public function testAuthorizeCronRequestRejectsHeaderMethodOverride(): void
    {
        when('__')->alias(static fn (string $message): string => $message);
        $plugin = $this->createPluginWithCronSecret('secret123');

        $request = new WP_REST_Request(
            [],
            [
                'X-Watchdog-Cron-Key' => 'secret123',
                'X-HTTP-Method-Override' => 'POST',
            ],
            [],
            'POST'
        );
        $result = $plugin->authorizeCronRequest($request);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('watchdog_cron_method_not_allowed', $result->get_error_code());
        self::assertSame(['status' => 405], $result->get_error_data());
    }

    public function testAuthorizeCronRequestRejectsForceWithLegacyQuerySecret(): void
    {
        when('__')->alias(static fn (string $message): string => $message);
        $plugin = $this->createPluginWithCronSecret('secret123');

        $request = new WP_REST_Request(['force' => true], [], ['key' => 'secret123']);
        $result  = $plugin->authorizeCronRequest($request);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('watchdog_cron_force_requires_header', $result->get_error_code());
        self::assertSame(['status' => 403], $result->get_error_data());
    }

    public function testAuthorizeCronRequestAllowsForceWithHeaderSecret(): void
    {
        $plugin = $this->createPluginWithCronSecret('secret123');

        $request = new WP_REST_Request(['force' => true], ['X-Watchdog-Cron-Key' => 'secret123']);

        self::assertTrue($plugin->authorizeCronRequest($request));
    }

    public function testScanFailurePreservesSavedResultsAndReturnsSafely(): void
    {
        $scanner = $this->createMock(Scanner::class);
        $scanner->method('scan')->willThrowException(new RuntimeException('Provider unavailable'));

        $riskRepository = $this->createMock(RiskRepository::class);
        $riskRepository->expects(self::never())->method('save');
        $settingsRepository = $this->createMock(SettingsRepository::class);
        $notifier = $this->createMock(Notifier::class);

        when('__')->alias(static fn ($message) => $message);
        expect('set_transient')
            ->once()
            ->with(
                'siteadwa_scan_error',
                'The scan could not be completed. No saved results were changed; please try again.',
                3600
            );

        $plugin = new Plugin($scanner, $riskRepository, $settingsRepository, $notifier);

        self::assertFalse($plugin->runScan());
    }

    /**
     * @dataProvider restScanForceProvider
     */
    public function testRestScanOnlyResendsAnUnchangedReportWhenForced(bool $force, int $expectedNotifications): void
    {
        $risk = new Risk('example', 'Example Plugin', '1.0.0', '1.1.0', ['Outdated plugin version']);

        $scanner = $this->createMock(Scanner::class);
        $scanner->method('scan')->willReturn([$risk]);
        $riskRepository = $this->createMock(RiskRepository::class);

        $settingsRepository = $this->createMock(SettingsRepository::class);
        $settingsRepository->method('get')->willReturn([
            'notifications'     => ['frequency' => 'daily'],
            'history'           => ['retention' => RiskRepository::DEFAULT_HISTORY_RETENTION],
            'last_notification' => md5(json_encode([$risk->toArray()], JSON_THROW_ON_ERROR)),
        ]);

        $notifier = $this->createMock(Notifier::class);
        $notifier->expects(self::exactly($expectedNotifications))->method('notify')->with([$risk]);
        $notifier->method('processQueue')->willReturn(['processed' => 0, 'succeeded' => 0]);

        when('__')->alias(static fn (string $message): string => $message);
        when('wp_json_encode')->alias(static fn ($data) => json_encode($data, JSON_THROW_ON_ERROR));

        $plugin   = new Plugin($scanner, $riskRepository, $settingsRepository, $notifier);
        $response = $plugin->handleRestCronRequest(new WP_REST_Request(['force' => $force]));

        self::assertSame('ok', $response['status']);
    }

    /**
     * @return array<string, array{0: bool, 1: int}>
     */
    public function restScanForceProvider(): array
    {
        return [
            'unchanged report stays quiet' => [false, 0],
            'force sends it again'         => [true, 1],
        ];
    }

    public function testScheduleLeavesAnUnchangedCronStatusAlone(): void
    {
        $cronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $this->stubIdleManualSchedule([
            'cron_disabled'  => $cronDisabled,
            'overdue_streak' => 0,
        ]);
        expect('update_option')->never();

        $this->createPluginWithFrequency('manual')->schedule();
    }

    public function testScheduleRewritesTheLegacyCronStatusShapeOnce(): void
    {
        $cronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $this->stubIdleManualSchedule([
            'overdue_streak' => 0,
            'cron_disabled'  => $cronDisabled,
            'last_checked'   => 1_700_000_000,
        ]);
        expect('update_option')
            ->once()
            ->with(
                'siteadwa_cron_status',
                ['cron_disabled' => $cronDisabled, 'overdue_streak' => 0],
                true
            );

        $this->createPluginWithFrequency('manual')->schedule();
    }

    /**
     * @dataProvider cronDiagnosticsProvider
     */
    public function testCronNoticeOnlyAppearsOnceScansAreOverdue(array $status, string $expected): void
    {
        when('is_admin')->justReturn(true);
        when('current_user_can')->justReturn(true);
        when('__')->alias(static fn (string $message): string => $message);
        when('esc_html')->alias(static fn (string $message): string => $message);
        when('get_option')->alias(
            static fn (string $name, $default = false) => $name === 'siteadwa_cron_status' ? $status : $default
        );

        ob_start();
        $this->createPluginWithFrequency('daily')->renderCronDiagnostics();
        $output = (string) ob_get_clean();

        if ($expected === '') {
            self::assertSame('', $output);

            return;
        }

        self::assertStringContainsString($expected, $output);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public function cronDiagnosticsProvider(): array
    {
        return [
            'disabled WP-Cron that still runs on time' => [
                ['cron_disabled' => true, 'overdue_streak' => 0],
                '',
            ],
            'one late check is not a pattern yet' => [
                ['cron_disabled' => false, 'overdue_streak' => 1],
                '',
            ],
            'overdue with WP-Cron enabled' => [
                ['cron_disabled' => false, 'overdue_streak' => 2],
                'scans are overdue',
            ],
            'overdue with WP-Cron disabled' => [
                ['cron_disabled' => true, 'overdue_streak' => 3],
                'WP-Cron is disabled on this site',
            ],
        ];
    }

    /**
     * Manual frequency with the queue processor already scheduled: schedule()
     * has nothing to do except record the cron status.
     *
     * @param array<string, mixed> $storedStatus
     */
    private function stubIdleManualSchedule(array $storedStatus): void
    {
        when('get_option')->alias(
            static fn (string $name, $default = false) => $name === 'siteadwa_cron_status' ? $storedStatus : $default
        );
        when('wp_next_scheduled')->alias(
            static fn (string $hook) => $hook === 'siteadwa_notification_queue' ? time() + 100 : false
        );
        when('wp_get_schedule')->justReturn('siteadwa_notification_queue');
        when('wp_get_schedules')->justReturn(['daily' => ['interval' => 86_400]]);
        expect('wp_schedule_event')->never();
        expect('wp_schedule_single_event')->never();
    }

    private function createPluginWithFrequency(string $frequency): Plugin
    {
        $settingsRepository = $this->createMock(SettingsRepository::class);
        $settingsRepository->method('get')->willReturn([
            'notifications' => ['frequency' => $frequency],
        ]);

        return new Plugin(
            $this->createMock(Scanner::class),
            $this->createMock(RiskRepository::class),
            $settingsRepository,
            $this->createMock(Notifier::class)
        );
    }

    private function createPluginWithCronSecret(string $secret): Plugin
    {
        $scanner            = $this->createMock(Scanner::class);
        $riskRepository     = $this->createMock(RiskRepository::class);
        $settingsRepository = $this->createMock(SettingsRepository::class);
        $settingsRepository->method('get')->willReturn([
            'notifications' => ['cron_secret' => $secret],
        ]);
        $notifier = $this->createMock(Notifier::class);

        return new Plugin($scanner, $riskRepository, $settingsRepository, $notifier);
    }
}
