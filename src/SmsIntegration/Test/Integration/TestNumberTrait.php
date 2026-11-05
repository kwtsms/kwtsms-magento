<?php
/**
 * FILE:      src/SmsIntegration/Test/Integration/TestNumberTrait.php
 * CREATED:   2026-11-05 11:16 +03
 * TASK:      int-testnumber
 * AUTHOR:    M Alhassoun
 * WHAT:      One shared helper that returns the Kuwait test number from the
 *            KWTSMS_TEST_NUMBER environment variable, or skips the test.
 * WHY:       Sample mobile numbers in tests belong to real people. Tests that
 *            truly need valid Kuwait digits read them from the environment
 *            instead of hard-coding a number.
 * USAGE:     use TestNumberTrait; then $n = $this->requireTestNumber();
 *            KWTSMS_TEST_NUMBER=965XXXXXXXX vendor/bin/phpunit ...
 * DEPENDS:   PHPUnit\Framework\TestCase (markTestSkipped).
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Test\Integration;

trait TestNumberTrait
{
    /**
     * Return KWTSMS_TEST_NUMBER (digits only, 965 + 8 digits) or skip the test.
     *
     * Reads the process environment first, then $_ENV (filled from .env by the live tests).
     * The value is never echoed in the skip message.
     *
     * @return string
     */
    private function requireTestNumber(): string
    {
        $value = getenv('KWTSMS_TEST_NUMBER');
        if (!is_string($value) || $value === '') {
            $value = (string) ($_ENV['KWTSMS_TEST_NUMBER'] ?? '');
        }

        if (preg_match('/^965\d{8}$/', $value) !== 1) {
            $this->markTestSkipped('KWTSMS_TEST_NUMBER is not set or is not 965 followed by 8 digits');
        }

        return $value;
    }
}
