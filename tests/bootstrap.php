<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/.config/');
}

abstract class TestCase extends PHPUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Brain\Monkey\Functions\when('wp_parse_url')->alias(
            static fn (string $url, int $component = -1) => parse_url($url, $component)
        );
        Brain\Monkey\Functions\when('wp_strip_all_tags')->alias(
            static fn (string $text): string => preg_replace('/<[^>]*>/', '', $text) ?? ''
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
