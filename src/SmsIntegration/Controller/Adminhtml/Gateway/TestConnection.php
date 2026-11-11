<?php
/**
 * Gateway Test Connection Controller.
 *
 * Tests the kwtSMS API connection using credentials from POST data or stored config.
 * On success, also syncs sender IDs and coverage data to the gateway_data table.
 * Returns a JSON response.
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Controller\Adminhtml\Gateway;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;
use KwtSms\SmsIntegration\Model\Api\Client as ApiClient;
use KwtSms\SmsIntegration\Model\Config;
use KwtSms\SmsIntegration\Api\Data\GatewayDataInterface;
use KwtSms\SmsIntegration\Model\GatewayDataFactory;
use KwtSms\SmsIntegration\Model\ResourceModel\GatewayData as GatewayDataResource;
use KwtSms\SmsIntegration\Model\ResourceModel\GatewayData\CollectionFactory as GatewayCollectionFactory;

class TestConnection extends Action
{
    /**
     * ACL resource identifier.
     */
    const ADMIN_RESOURCE = 'KwtSms_SmsIntegration::config';

    /**
     * @var JsonFactory
     */
    private JsonFactory $resultJsonFactory;

    /**
     * @var ApiClient
     */
    private ApiClient $apiClient;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var GatewayDataFactory
     */
    private GatewayDataFactory $gatewayDataFactory;

    /**
     * @var GatewayDataResource
     */
    private GatewayDataResource $gatewayDataResource;

    /**
     * @var GatewayCollectionFactory
     */
    private GatewayCollectionFactory $gatewayCollectionFactory;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param ApiClient $apiClient
     * @param Config $config
     * @param GatewayDataFactory $gatewayDataFactory
     * @param GatewayDataResource $gatewayDataResource
     * @param GatewayCollectionFactory $gatewayCollectionFactory
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        ApiClient $apiClient,
        Config $config,
        GatewayDataFactory $gatewayDataFactory,
        GatewayDataResource $gatewayDataResource,
        GatewayCollectionFactory $gatewayCollectionFactory
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->apiClient = $apiClient;
        $this->config = $config;
        $this->gatewayDataFactory = $gatewayDataFactory;
        $this->gatewayDataResource = $gatewayDataResource;
        $this->gatewayCollectionFactory = $gatewayCollectionFactory;
    }

    /**
     * Test API connection and sync gateway data on success.
     *
     * @return Json
     */
    public function execute(): Json
    {
        /** @var Json $resultJson */
        $resultJson = $this->resultJsonFactory->create();

        try {
            $username = $this->getRequest()->getParam('username');
            $password = $this->getRequest()->getParam('password');

            if (empty($username)) {
                $username = $this->config->getApiUsername();
            }
            if (empty($password)) {
                $password = $this->config->getApiPassword();
            }

            if (empty($username) || empty($password)) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => 'Username and password are required.',
                ]);
            }

            $balanceResult = $this->apiClient->testConnection($username, $password);

            if (isset($balanceResult['result']) && stripos((string) $balanceResult['result'], 'ERR') === 0) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => 'Connection failed: ' . ($balanceResult['result'] ?? 'Unknown error'),
                ]);
            }

            $available = $balanceResult['available'] ?? '0';
            $purchased = $balanceResult['purchased'] ?? '0';
            $balanceString = $available . '/' . $purchased;
            $now = date('Y-m-d H:i:s');

            $this->saveGatewayData(
                GatewayDataInterface::TYPE_BALANCE,
                json_encode($balanceResult),
                $now
            );

            $senderIds = [];
            try {
                $senderResult = $this->apiClient->getSenderIds();
                $senderIds = $senderResult['senderid'] ?? [];
                // 2026-11-11 18:10 +03: save only when the API returned the list, so a failed call keeps the cached one, task magento-111
                if (!isset($senderResult['senderid']) || !is_array($senderResult['senderid'])) {
                    throw new \RuntimeException('sender ID list missing');
                }
                $this->saveGatewayData(
                    GatewayDataInterface::TYPE_SENDERID,
                    json_encode($senderIds),
                    $now
                );
            } catch (\Exception $e) {
                // Sender ID sync is non-critical
            }

            try {
                $coverageResult = $this->apiClient->getCoverage();
                // 2026-11-11 18:10 +03: save only when the API returned the list, so a failed call keeps the cached one, task magento-111
                if (!isset($coverageResult['prefixes']) || !is_array($coverageResult['prefixes'])) {
                    throw new \RuntimeException('coverage list missing');
                }
                $this->saveGatewayData(
                    GatewayDataInterface::TYPE_COVERAGE,
                    json_encode($coverageResult['prefixes'] ?? []),
                    $now
                );
            } catch (\Exception $e) {
                // Coverage sync is non-critical
            }

            return $resultJson->setData([
                'success' => true,
                'balance' => $balanceString,
                'senderids' => $senderIds,
                'message' => 'Connected successfully!',
            ]);
        } catch (\Exception $e) {
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Save or update a gateway data record by type.
     *
     * @param string $dataType
     * @param string $dataValue
     * @param string $syncedAt
     * @return void
     */
    private function saveGatewayData(string $dataType, string $dataValue, string $syncedAt): void
    {
        $collection = $this->gatewayCollectionFactory->create();
        $collection->addFieldToFilter(GatewayDataInterface::DATA_TYPE, $dataType);
        $collection->setPageSize(1);

        /** @var GatewayDataInterface $item */
        $item = $collection->getFirstItem();

        if (!$item->getEntityId()) {
            $item = $this->gatewayDataFactory->create();
            $item->setDataType($dataType);
        }

        $item->setDataValue($dataValue);
        $item->setSyncedAt($syncedAt);
        $this->gatewayDataResource->save($item);
    }
}
