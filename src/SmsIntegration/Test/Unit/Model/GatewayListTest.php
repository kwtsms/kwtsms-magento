<?php
/**
 * FILE:      Test/Unit/Model/GatewayListTest.php
 * CREATED:   2026-11-11 18:05 +03
 * TASK:      magento-111
 * AUTHOR:    M Alhassoun
 * WHAT:      Unit tests for GatewayList: cached sender ID and coverage lists read from either stored shape.
 * WHY:       Rows may hold a bare list or a full API response; both must give the same list.
 * USAGE:     vendor/bin/phpunit app/code/KwtSms/SmsIntegration/Test/Unit/
 * DEPENDS:   PHPUnit.
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Test\Unit\Model;

use KwtSms\SmsIntegration\Model\GatewayList;
use PHPUnit\Framework\TestCase;

class GatewayListTest extends TestCase
{
    public function testBareListIsReturnedAsStrings(): void
    {
        $this->assertSame(['965', '966'], GatewayList::fromJson('["965",966]', GatewayList::KEY_COVERAGE));
    }

    public function testFullCoverageResponseGivesPrefixes(): void
    {
        $json = '{"result":"OK","prefixes":["965","966"]}';
        $this->assertSame(['965', '966'], GatewayList::fromJson($json, GatewayList::KEY_COVERAGE));
    }

    public function testFullSenderIdResponseGivesSenderIds(): void
    {
        $json = '{"result":"OK","senderid":["KWT-SMS","MY-SHOP"]}';
        $this->assertSame(['KWT-SMS', 'MY-SHOP'], GatewayList::fromJson($json, GatewayList::KEY_SENDERID));
    }

    public function testErrorResponseGivesEmptyList(): void
    {
        $json = '{"result":"ERROR","code":"ERR003","description":"x"}';
        $this->assertSame([], GatewayList::fromJson($json, GatewayList::KEY_SENDERID));
    }

    public function testEmptyAndInvalidValuesGiveEmptyList(): void
    {
        $this->assertSame([], GatewayList::fromJson(null, GatewayList::KEY_COVERAGE));
        $this->assertSame([], GatewayList::fromJson('', GatewayList::KEY_COVERAGE));
        $this->assertSame([], GatewayList::fromJson('not json', GatewayList::KEY_COVERAGE));
        $this->assertSame([], GatewayList::fromJson('"965"', GatewayList::KEY_COVERAGE));
    }

    public function testNonScalarEntriesAreDropped(): void
    {
        $this->assertSame(['965'], GatewayList::fromArray(['965', ['966'], null, true], GatewayList::KEY_COVERAGE));
    }
}
