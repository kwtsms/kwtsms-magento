<?php
/**
 * FILE:      Test/Unit/Model/Phone/CoverageTest.php
 * CREATED:   2026-11-11 18:05 +03
 * TASK:      magento-111
 * AUTHOR:    M Alhassoun
 * WHAT:      Unit tests for Normalizer::isInCoverage over a bare prefix list and a full API response array.
 * WHY:       Coverage read from a full-response row must still match covered numbers.
 * USAGE:     vendor/bin/phpunit app/code/KwtSms/SmsIntegration/Test/Unit/
 * DEPENDS:   PHPUnit.
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Test\Unit\Model\Phone;

use KwtSms\SmsIntegration\Model\Phone\Normalizer;
use PHPUnit\Framework\TestCase;

class CoverageTest extends TestCase
{
    public function testBareListMatches(): void
    {
        $normalizer = new Normalizer();
        $this->assertTrue($normalizer->isInCoverage('96512345678', ['966', '965']));
        $this->assertFalse($normalizer->isInCoverage('97112345678', ['966', '965']));
    }

    public function testFullResponseArrayMatches(): void
    {
        $normalizer = new Normalizer();
        $coverage = ['result' => 'OK', 'prefixes' => ['966', '965']];
        $this->assertTrue($normalizer->isInCoverage('96512345678', $coverage));
        $this->assertFalse($normalizer->isInCoverage('97112345678', $coverage));
    }
}
