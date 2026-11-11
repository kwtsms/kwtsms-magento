<?php
/**
 * FILE:      Test/Unit/Model/Config/Source/GatewaySourcesTest.php
 * CREATED:   2026-11-11 18:05 +03
 * TASK:      magento-111
 * AUTHOR:    M Alhassoun
 * WHAT:      Unit tests for the Sender ID and Country Code dropdown sources over both stored shapes.
 * WHY:       A row holding the full API response must give the same options as a bare list.
 * USAGE:     vendor/bin/phpunit app/code/KwtSms/SmsIntegration/Test/Unit/
 * DEPENDS:   PHPUnit, magento/framework (mocked collection, no database).
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Test\Unit\Model\Config\Source;

use KwtSms\SmsIntegration\Model\Config\Source\CountryCode;
use KwtSms\SmsIntegration\Model\Config\Source\SenderId;
use KwtSms\SmsIntegration\Model\GatewayData;
use KwtSms\SmsIntegration\Model\ResourceModel\GatewayData\Collection;
use KwtSms\SmsIntegration\Model\ResourceModel\GatewayData\CollectionFactory;
use PHPUnit\Framework\TestCase;

class GatewaySourcesTest extends TestCase
{
    private function factoryFor(?string $storedValue): CollectionFactory
    {
        $item = $this->createMock(GatewayData::class);
        $item->method('getDataValue')->willReturn($storedValue);

        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($item);

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        return $factory;
    }

    private function values(array $options): array
    {
        return array_map(static fn (array $o) => $o['value'], $options);
    }

    public function testSenderIdFromBareList(): void
    {
        $source = new SenderId($this->factoryFor('["KWT-SMS","MY-SHOP"]'));
        $this->assertSame(['', 'KWT-SMS', 'MY-SHOP'], $this->values($source->toOptionArray()));
    }

    public function testSenderIdFromFullResponseRow(): void
    {
        $source = new SenderId($this->factoryFor('{"result":"OK","senderid":["KWT-SMS","MY-SHOP"]}'));
        $this->assertSame(['', 'KWT-SMS', 'MY-SHOP'], $this->values($source->toOptionArray()));
    }

    public function testCountryCodeFromBareList(): void
    {
        $source = new CountryCode($this->factoryFor('["965","966"]'));
        $this->assertSame(['', '965', '966'], $this->values($source->toOptionArray()));
    }

    public function testCountryCodeFromFullResponseRow(): void
    {
        $source = new CountryCode($this->factoryFor('{"result":"OK","prefixes":["965","966"]}'));
        $this->assertSame(['', '965', '966'], $this->values($source->toOptionArray()));
    }

    public function testCountryCodeFromErrorRowFallsBackToKuwait(): void
    {
        $source = new CountryCode($this->factoryFor('{"result":"ERROR","code":"ERR003"}'));
        $this->assertSame(['', '965'], $this->values($source->toOptionArray()));
    }
}
