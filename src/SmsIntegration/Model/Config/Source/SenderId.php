<?php
/**
 * Sender ID source model for system configuration dropdown.
 *
 * Reads sender IDs from the kwtsms_gateway_data table (type=senderid).
 * Falls back to "KWT-SMS" if no data has been synced yet.
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use KwtSms\SmsIntegration\Api\Data\GatewayDataInterface;
use KwtSms\SmsIntegration\Model\GatewayList;
use KwtSms\SmsIntegration\Model\ResourceModel\GatewayData\CollectionFactory as GatewayCollectionFactory;

class SenderId implements OptionSourceInterface
{
    /**
     * @var GatewayCollectionFactory
     */
    private GatewayCollectionFactory $gatewayCollectionFactory;

    /**
     * @param GatewayCollectionFactory $gatewayCollectionFactory
     */
    public function __construct(
        GatewayCollectionFactory $gatewayCollectionFactory
    ) {
        $this->gatewayCollectionFactory = $gatewayCollectionFactory;
    }

    /**
     * Return sender ID options for the dropdown.
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        $options = [
            ['value' => '', 'label' => __('-- Select --')],
        ];

        $senderIds = $this->loadSenderIds();

        if (empty($senderIds)) {
            $options[] = ['value' => 'KWT-SMS', 'label' => 'KWT-SMS'];
            return $options;
        }

        foreach ($senderIds as $sid) {
            $sid = (string) $sid;
            $options[] = ['value' => $sid, 'label' => $sid];
        }

        return $options;
    }

    /**
     * Load sender IDs from the gateway data table.
     *
     * @return array
     */
    private function loadSenderIds(): array
    {
        $collection = $this->gatewayCollectionFactory->create();
        $collection->addFieldToFilter(GatewayDataInterface::DATA_TYPE, GatewayDataInterface::TYPE_SENDERID);
        $collection->setPageSize(1);

        $item = $collection->getFirstItem();
        $value = $item->getDataValue();

        // 2026-11-11 18:03 +03: read the list from a bare list or a full API response row. Task magento-111.
        return GatewayList::fromJson($value, GatewayList::KEY_SENDERID);
    }
}
