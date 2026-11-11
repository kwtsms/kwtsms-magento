<?php
/**
 * Sync Gateway Data Cron Job.
 *
 * Periodically fetches account balance, sender IDs, and coverage data
 * from the kwtSMS API and caches it in the kwtsms_gateway_data table.
 *
 * @see \KwtSms\SmsIntegration\Model\Api\Client
 * @see \KwtSms\SmsIntegration\Model\ResourceModel\GatewayData
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Cron;

use KwtSms\SmsIntegration\Api\Data\GatewayDataInterface;
use KwtSms\SmsIntegration\Model\Api\Client;
use KwtSms\SmsIntegration\Model\Config;
use KwtSms\SmsIntegration\Model\GatewayDataFactory;
use KwtSms\SmsIntegration\Model\GatewayList;
use KwtSms\SmsIntegration\Model\ResourceModel\GatewayData as GatewayDataResource;
use Psr\Log\LoggerInterface;

class SyncGatewayData
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var Client
     */
    private Client $apiClient;

    /**
     * @var GatewayDataResource
     */
    private GatewayDataResource $gatewayDataResource;

    /**
     * @var GatewayDataFactory
     */
    private GatewayDataFactory $gatewayDataFactory;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config $config
     * @param Client $apiClient
     * @param GatewayDataResource $gatewayDataResource
     * @param GatewayDataFactory $gatewayDataFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        Client $apiClient,
        GatewayDataResource $gatewayDataResource,
        GatewayDataFactory $gatewayDataFactory,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->apiClient = $apiClient;
        $this->gatewayDataResource = $gatewayDataResource;
        $this->gatewayDataFactory = $gatewayDataFactory;
        $this->logger = $logger;
    }

    /**
     * Sync gateway data from the kwtSMS API.
     *
     * Fetches balance, sender IDs, and coverage data, then persists
     * each result in the gateway_data table for local caching.
     *
     * @return void
     */
    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        if (!$this->config->getApiUsername() || !$this->config->getApiPassword()) {
            return;
        }

        // 2026-11-11 18:03 +03: store the synced lists in the expected format, as the manual Sync does: balance as the
        // full response, sender IDs and coverage as the bare list. A response whose result is not OK keeps the cached
        // row. Task magento-111.
        try {
            $balance = $this->apiClient->getBalance();
            if ($this->isOk($balance)) {
                $this->saveGatewayData(GatewayDataInterface::TYPE_BALANCE, $balance);
            } else {
                $this->logNotOk('balance', $balance);
            }
        } catch (\Exception $e) {
            $this->logger->error('kwtSMS: Failed to sync balance', [
                'message' => $e->getMessage(),
            ]);
        }

        try {
            $senderIds = $this->apiClient->getSenderIds();
            if ($this->isOk($senderIds) && is_array($senderIds[GatewayList::KEY_SENDERID] ?? null)) {
                $this->saveGatewayData(
                    GatewayDataInterface::TYPE_SENDERID,
                    $senderIds[GatewayList::KEY_SENDERID]
                );
            } else {
                $this->logNotOk('sender IDs', $senderIds);
            }
        } catch (\Exception $e) {
            $this->logger->error('kwtSMS: Failed to sync sender IDs', [
                'message' => $e->getMessage(),
            ]);
        }

        try {
            $coverage = $this->apiClient->getCoverage();
            if ($this->isOk($coverage) && is_array($coverage[GatewayList::KEY_COVERAGE] ?? null)) {
                $this->saveGatewayData(
                    GatewayDataInterface::TYPE_COVERAGE,
                    $coverage[GatewayList::KEY_COVERAGE]
                );
            } else {
                $this->logNotOk('coverage data', $coverage);
            }
        } catch (\Exception $e) {
            $this->logger->error('kwtSMS: Failed to sync coverage data', [
                'message' => $e->getMessage(),
            ]);
        }

        $this->logger->info('kwtSMS: Gateway data sync finished');
    }

    /**
     * Whether an API response reports success.
     *
     * 2026-11-11 18:03 +03: added so a response that is not OK never replaces cached data. Task magento-111.
     *
     * @param array $response
     * @return bool
     */
    private function isOk(array $response): bool
    {
        return ($response['result'] ?? '') === 'OK';
    }

    /**
     * Log a response that was not stored.
     *
     * 2026-11-11 18:03 +03: added with isOk(). Task magento-111.
     *
     * @param string $what
     * @param array $response
     * @return void
     */
    private function logNotOk(string $what, array $response): void
    {
        $this->logger->warning('kwtSMS: ' . $what . ' not synced, cached data kept', [
            'result' => $response['result'] ?? null,
            'code'   => $response['code'] ?? null,
        ]);
    }

    /**
     * Save or update a gateway data row by data type.
     *
     * If a row with the given type already exists, it is updated in place.
     * Otherwise a new row is created.
     *
     * @param string $type
     * @param mixed $value
     * @return void
     */
    private function saveGatewayData(string $type, $value): void
    {
        try {
            $connection = $this->gatewayDataResource->getConnection();
            $tableName = $this->gatewayDataResource->getMainTable();
            $now = date('Y-m-d H:i:s');
            $encodedValue = json_encode($value);

            // Check if a row with this type already exists
            $select = $connection->select()
                ->from($tableName, [GatewayDataInterface::ENTITY_ID])
                ->where(GatewayDataInterface::DATA_TYPE . ' = ?', $type)
                ->limit(1);

            $existingId = $connection->fetchOne($select);

            if ($existingId !== false) {
                $connection->update(
                    $tableName,
                    [
                        GatewayDataInterface::DATA_VALUE => $encodedValue,
                        GatewayDataInterface::SYNCED_AT  => $now,
                    ],
                    [GatewayDataInterface::DATA_TYPE . ' = ?' => $type]
                );
            } else {
                $connection->insert($tableName, [
                    GatewayDataInterface::DATA_TYPE  => $type,
                    GatewayDataInterface::DATA_VALUE => $encodedValue,
                    GatewayDataInterface::SYNCED_AT  => $now,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('kwtSMS: Failed to save gateway data', [
                'type'    => $type,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
