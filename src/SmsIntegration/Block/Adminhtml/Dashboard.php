<?php
/**
 * kwtSMS Dashboard Block.
 *
 * Provides data for the admin dashboard template, including balance,
 * sender IDs, coverage info, and SMS delivery statistics.
 *
 * @see \KwtSms\SmsIntegration\Model\Config
 * @see \KwtSms\SmsIntegration\Api\Data\GatewayDataInterface
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use KwtSms\SmsIntegration\Model\Config;
use KwtSms\SmsIntegration\Api\Data\GatewayDataInterface;
use KwtSms\SmsIntegration\Model\GatewayList;
use KwtSms\SmsIntegration\Model\ResourceModel\GatewayData\CollectionFactory as GatewayCollectionFactory;
use KwtSms\SmsIntegration\Model\ResourceModel\SmsLog\CollectionFactory as SmsLogCollectionFactory;
use KwtSms\SmsIntegration\Api\Data\SmsLogInterface;
use KwtSms\SmsIntegration\Model\Phone\Rules;

class Dashboard extends Template
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var GatewayCollectionFactory
     */
    private GatewayCollectionFactory $gatewayCollectionFactory;

    /**
     * @var SmsLogCollectionFactory
     */
    private SmsLogCollectionFactory $smsLogCollectionFactory;

    /**
     * @param Context $context
     * @param Config $config
     * @param GatewayCollectionFactory $gatewayCollectionFactory
     * @param SmsLogCollectionFactory $smsLogCollectionFactory
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $config,
        GatewayCollectionFactory $gatewayCollectionFactory,
        SmsLogCollectionFactory $smsLogCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->gatewayCollectionFactory = $gatewayCollectionFactory;
        $this->smsLogCollectionFactory = $smsLogCollectionFactory;
    }

    /**
     * Get the cached balance string from gateway data.
     *
     * @return string
     */
    public function getBalance(): string
    {
        $data = $this->loadGatewayData(GatewayDataInterface::TYPE_BALANCE);
        if ($data === null) {
            return 'N/A';
        }

        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return 'N/A';
        }

        $available = $decoded['available'] ?? '0';
        $purchased = $decoded['purchased'] ?? '0';

        return $available . '/' . $purchased;
    }

    /**
     * Get the cached sender IDs from gateway data.
     *
     * @return array
     */
    public function getSenderIds(): array
    {
        // 2026-11-11 18:03 +03: read the list from a bare list or a full API response row. Task magento-111.
        return GatewayList::fromJson(
            $this->loadGatewayData(GatewayDataInterface::TYPE_SENDERID),
            GatewayList::KEY_SENDERID
        );
    }

    /**
     * Get the cached coverage data from gateway data.
     *
     * @return array
     */
    public function getCoverage(): array
    {
        // 2026-11-11 18:03 +03: read the list from a bare list or a full API response row. Task magento-111.
        return GatewayList::fromJson(
            $this->loadGatewayData(GatewayDataInterface::TYPE_COVERAGE),
            GatewayList::KEY_COVERAGE
        );
    }

    /**
     * Get a formatted coverage display string.
     *
     * Maps each coverage prefix to "prefix (CountryName)" and joins with ", ".
     *
     * @return string
     */
    public function getCoverageDisplay(): string
    {
        $prefixes = $this->getCoverage();
        if (empty($prefixes)) {
            return '';
        }

        $countryNames = Rules::COUNTRY_NAMES;
        $parts = [];

        foreach ($prefixes as $prefix) {
            $prefix = (string) $prefix;
            $name = $countryNames[$prefix] ?? 'Unknown';
            $parts[] = $prefix . ' (' . $name . ')';
        }

        return implode(', ', $parts);
    }

    /**
     * Check if the module is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    /**
     * Check if test mode is active.
     *
     * @return bool
     */
    public function isTestMode(): bool
    {
        return $this->config->isTestMode();
    }

    /**
     * Get the configured sender ID.
     *
     * @return string
     */
    public function getSenderId(): string
    {
        return $this->config->getSenderId();
    }

    /**
     * Get the total count of sent messages.
     *
     * @return int
     */
    public function getTotalSent(): int
    {
        $collection = $this->smsLogCollectionFactory->create();
        $collection->addFieldToFilter(
            SmsLogInterface::STATUS,
            ['in' => [SmsLogInterface::STATUS_SENT, SmsLogInterface::STATUS_TEST]]
        );
        return $collection->getSize();
    }

    /**
     * Get the total count of failed messages.
     *
     * @return int
     */
    public function getTotalFailed(): int
    {
        $collection = $this->smsLogCollectionFactory->create();
        $collection->addFieldToFilter(SmsLogInterface::STATUS, SmsLogInterface::STATUS_FAILED);
        return $collection->getSize();
    }

    /**
     * Get the count of messages sent today.
     *
     * @return int
     */
    public function getTodaySent(): int
    {
        $collection = $this->smsLogCollectionFactory->create();
        $collection->addFieldToFilter(
            SmsLogInterface::STATUS,
            ['in' => [SmsLogInterface::STATUS_SENT, SmsLogInterface::STATUS_TEST]]
        );
        $collection->addFieldToFilter(
            SmsLogInterface::CREATED_AT,
            ['gteq' => date('Y-m-d') . ' 00:00:00']
        );
        return $collection->getSize();
    }

    /**
     * Get the last sync timestamp from gateway data.
     *
     * @return string|null
     */
    public function getLastSyncTime(): ?string
    {
        $collection = $this->gatewayCollectionFactory->create();
        $collection->addFieldToFilter(
            GatewayDataInterface::DATA_TYPE,
            GatewayDataInterface::TYPE_BALANCE
        );
        $collection->setPageSize(1);

        $item = $collection->getFirstItem();
        return $item->getSyncedAt();
    }

    /**
     * Get the URL for the gateway sync action.
     *
     * @return string
     */
    public function getSyncUrl(): string
    {
        return $this->getUrl('kwtsms/gateway/sync');
    }

    /**
     * Get the URL for the test connection action.
     *
     * @return string
     */
    public function getTestConnectionUrl(): string
    {
        return $this->getUrl('kwtsms/gateway/testconnection');
    }

    /**
     * Load a gateway data value by type.
     *
     * @param string $dataType
     * @return string|null
     */
    private function loadGatewayData(string $dataType): ?string
    {
        $collection = $this->gatewayCollectionFactory->create();
        $collection->addFieldToFilter(GatewayDataInterface::DATA_TYPE, $dataType);
        $collection->setPageSize(1);

        $item = $collection->getFirstItem();
        return $item->getDataValue();
    }
}
