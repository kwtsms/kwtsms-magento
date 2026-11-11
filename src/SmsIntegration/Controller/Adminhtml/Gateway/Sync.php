<?php
/**
 * Gateway Sync Controller.
 *
 * Manually triggers a sync of balance, sender IDs, and coverage data
 * from the kwtSMS API, storing results in the kwtsms_gateway_data table.
 * Returns a JSON response.
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Controller\Adminhtml\Gateway;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;
use KwtSms\SmsIntegration\Model\Api\Client as ApiClient;
use KwtSms\SmsIntegration\Api\Data\GatewayDataInterface;
use KwtSms\SmsIntegration\Model\GatewayDataFactory;
use KwtSms\SmsIntegration\Model\ResourceModel\GatewayData as GatewayDataResource;
use KwtSms\SmsIntegration\Model\ResourceModel\GatewayData\CollectionFactory as GatewayCollectionFactory;

class Sync extends Action
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
     * @param GatewayDataFactory $gatewayDataFactory
     * @param GatewayDataResource $gatewayDataResource
     * @param GatewayCollectionFactory $gatewayCollectionFactory
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        ApiClient $apiClient,
        GatewayDataFactory $gatewayDataFactory,
        GatewayDataResource $gatewayDataResource,
        GatewayCollectionFactory $gatewayCollectionFactory
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->apiClient = $apiClient;
        $this->gatewayDataFactory = $gatewayDataFactory;
        $this->gatewayDataResource = $gatewayDataResource;
        $this->gatewayCollectionFactory = $gatewayCollectionFactory;
    }

    /**
     * Sync all gateway data from the kwtSMS API.
     *
     * @return Json
     */
    public function execute(): Json
    {
        /** @var Json $resultJson */
        $resultJson = $this->resultJsonFactory->create();

        try {
            $now = date('Y-m-d H:i:s');
            $synced = [];

            $balanceResult = $this->apiClient->getBalance();
            $this->saveGatewayData(
                GatewayDataInterface::TYPE_BALANCE,
                json_encode($balanceResult),
                $now
            );
            $synced[] = 'balance';

            try {
                $senderResult = $this->apiClient->getSenderIds();
                // 2026-11-11 18:10 +03: save only when the API returned the list, so a failed call keeps the cached one, task magento-111
                if (!isset($senderResult['senderid']) || !is_array($senderResult['senderid'])) {
                    throw new \RuntimeException('sender ID list missing');
                }
                $this->saveGatewayData(
                    GatewayDataInterface::TYPE_SENDERID,
                    json_encode($senderResult['senderid'] ?? []),
                    $now
                );
                $synced[] = 'sender IDs';
            } catch (\Exception $e) {
                // Non-critical
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
                $synced[] = 'coverage';
            } catch (\Exception $e) {
                // Non-critical
            }

            return $resultJson->setData([
                'success' => true,
                'message' => 'Synced: ' . implode(', ', $synced) . '.',
                'synced_at' => $now,
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
