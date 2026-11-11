<?php
/**
 * Country Code source model for system configuration dropdown.
 *
 * Reads coverage prefixes from the kwtsms_gateway_data table (type=coverage)
 * and maps each prefix to its country name using Phone\Rules::COUNTRY_NAMES.
 * Falls back to "965 - Kuwait" if no data has been synced yet.
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use KwtSms\SmsIntegration\Api\Data\GatewayDataInterface;
use KwtSms\SmsIntegration\Model\ResourceModel\GatewayData\CollectionFactory as GatewayCollectionFactory;
use KwtSms\SmsIntegration\Model\GatewayList;
use KwtSms\SmsIntegration\Model\Phone\Rules;

class CountryCode implements OptionSourceInterface
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
     * Return country code options for the dropdown.
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        $options = [
            ['value' => '', 'label' => __('-- Select --')],
        ];

        $prefixes = $this->loadCoveragePrefixes();

        if (empty($prefixes)) {
            $options[] = ['value' => '965', 'label' => '965 - Kuwait'];
            return $options;
        }

        $countryNames = Rules::COUNTRY_NAMES;

        foreach ($prefixes as $prefix) {
            $prefix = (string) $prefix;
            $name = $countryNames[$prefix] ?? 'Unknown';
            $options[] = ['value' => $prefix, 'label' => $prefix . ' - ' . $name];
        }

        return $options;
    }

    /**
     * Load coverage prefixes from the gateway data table.
     *
     * @return array
     */
    private function loadCoveragePrefixes(): array
    {
        $collection = $this->gatewayCollectionFactory->create();
        $collection->addFieldToFilter(GatewayDataInterface::DATA_TYPE, GatewayDataInterface::TYPE_COVERAGE);
        $collection->setPageSize(1);

        $item = $collection->getFirstItem();
        $value = $item->getDataValue();

        // 2026-11-11 18:03 +03: read the list from a bare list or a full API response row. Task magento-111.
        return GatewayList::fromJson($value, GatewayList::KEY_COVERAGE);
    }
}
