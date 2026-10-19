---
name: testing-patterns
description: How to write tests for this WordPress plugin: PHPUnit with Brain Monkey for unit tests, wp-phpunit for integration, mocking wp_remote_* and options. Use when adding tests, setting up the harness, or writing a regression test for a bug fix.
---

# Testing patterns

## Harness
```
composer require --dev phpunit/phpunit:^9 brain/monkey:^2 yoast/phpunit-polyfills:^1
tests/
  bootstrap.php        # require vendor/autoload.php
  unit/                # no WordPress loaded (Brain Monkey)
  integration/         # wp-phpunit, WP_TESTS_DIR
phpunit.xml.dist
```
PHPUnit 9 is the newest version that still runs on PHP 7.4.

## Unit test skeleton (Brain Monkey)
```php
use Brain\Monkey;
use Brain\Monkey\Functions;

class Test_Example extends \PHPUnit\Framework\TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_returns_default_when_option_missing() {
        Functions\when( 'get_option' )->justReturn( false );
        // Arrange, act, assert.
    }
}
```

## Patterns
- **HTTP**: `Functions\expect( 'wp_remote_get' )->andReturn( [...] )`, or the `pre_http_request` filter in integration tests.
- **Options and meta**: stub `get_option`, `get_post_meta` (or the field library's getters) with `Functions\when()`.
- **Capabilities and nonces**: stub `current_user_can` and `wp_verify_nonce` both ways; test the denied path.
- **Pro gating** (if there is a Pro add-on): test with the Pro check true and false.
- **Regression test**: name it after the bug (`test_<what>_when_<condition>`) and confirm it fails before the fix.
- Project-specific targets are listed in `.claude/rules/testing-standard.md`.
