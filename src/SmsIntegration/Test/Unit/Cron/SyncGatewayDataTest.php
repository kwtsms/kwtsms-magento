<?php
/**
 * FILE:      Test/Unit/Cron/SyncGatewayDataTest.php
 * CREATED:   2026-11-11 18:05 +03
 * TASK:      magento-111
 * AUTHOR:    M Alhassoun
 * WHAT:      Unit tests for the gateway sync cron: what it writes to kwtsms_gateway_data per type.
 * WHY:       The cron must store what the manual Sync stores (balance as the full response, sender IDs and
 *            coverage as the bare list) and keep the cached row when a response is not OK.
 * USAGE:     vendor/bin/phpunit app/code/KwtSms/SmsIntegration/Test/Unit/
 * DEPENDS:   PHPUnit, magento/framework (mocked DB adapter, no database).
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Test\Unit\Cron;

use KwtSms\SmsIntegration\Cron\SyncGatewayData;
use KwtSms\SmsIntegration\Model\Api\Client;
use KwtSms\SmsIntegration\Model\Config;
use KwtSms\SmsIntegration\Model\GatewayDataFactory;
use KwtSms\SmsIntegration\Model\ResourceModel\GatewayData as GatewayDataResource;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SyncGatewayDataTest extends TestCase
{
    /**
     * Stored data_value per data_type, from update() and insert() calls.
     *
     * @var array
     */
    private array $written = [];

    private function runCron(array $balance, array $senderIds, array $coverage, bool $rowExists): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getApiUsername')->willReturn('user');
        $config->method('getApiPassword')->willReturn('pass');

        $client = $this->createMock(Client::class);
        $client->method('getBalance')->willReturn($balance);
        $client->method('getSenderIds')->willReturn($senderIds);
        $client->method('getCoverage')->willReturn($coverage);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn($rowExists ? '1' : false);
        $connection->method('update')->willReturnCallback(function ($table, array $bind, array $where) {
            $this->written[(string) reset($where)] = $bind['data_value'];
            return 1;
        });
        $connection->method('insert')->willReturnCallback(function ($table, array $bind) {
            $this->written[$bind['data_type']] = $bind['data_value'];
            return 1;
        });

        $resource = $this->createMock(GatewayDataResource::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('kwtsms_gateway_data');

        $cron = new SyncGatewayData(
            $config,
            $client,
            $resource,
            $this->createMock(GatewayDataFactory::class),
            new NullLogger()
        );
        $cron->execute();
    }

    public function testStoresWhatTheManualSyncStores(): void
    {
        $balance = ['result' => 'OK', 'available' => 150, 'purchased' => 200];
        $this->runCron(
            $balance,
            ['result' => 'OK', 'senderid' => ['KWT-SMS', 'MY-SHOP']],
            ['result' => 'OK', 'prefixes' => ['965', '966']],
            true
        );

        $this->assertSame(json_encode($balance), $this->written['balance'] ?? null);
        $this->assertSame('["KWT-SMS","MY-SHOP"]', $this->written['senderid'] ?? null);
        $this->assertSame('["965","966"]', $this->written['coverage'] ?? null);
    }

    public function testInsertsBareListsWhenNoRowExists(): void
    {
        $this->runCron(
            ['result' => 'OK', 'available' => 1, 'purchased' => 1],
            ['result' => 'OK', 'senderid' => ['KWT-SMS']],
            ['result' => 'OK', 'prefixes' => ['965']],
            false
        );

        $this->assertSame('["KWT-SMS"]', $this->written['senderid'] ?? null);
        $this->assertSame('["965"]', $this->written['coverage'] ?? null);
    }

    public function testResponseNotOkKeepsCachedData(): void
    {
        $error = ['result' => 'ERROR', 'code' => 'ERR003', 'description' => 'Authentication error'];
        $this->runCron($error, $error, $error, true);

        $this->assertSame([], $this->written);
    }

    public function testOkResponseWithoutListKeepsCachedData(): void
    {
        $this->runCron(
            ['result' => 'OK', 'available' => 1, 'purchased' => 1],
            ['result' => 'OK'],
            ['result' => 'OK'],
            true
        );

        $this->assertArrayNotHasKey('senderid', $this->written);
        $this->assertArrayNotHasKey('coverage', $this->written);
    }
}
