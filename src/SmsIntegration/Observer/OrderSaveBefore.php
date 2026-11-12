<?php
/**
 * FILE:      src/SmsIntegration/Observer/OrderSaveBefore.php
 * CREATED:   2026-11-12 10:18 +03
 * TASK:      magento-audit-fixes F1 (double SMS on cancel, ship, invoice)
 * AUTHOR:    M Alhassoun
 * WHAT:      Before an order is saved, records its stored values (OrderSnapshot) so OrderSaveAfter knows whether
 *            the order is new and what changed in this save.
 * WHY:       See OrderSnapshot: original data is missing on orders loaded through the order repository.
 * USAGE:     Registered on sales_order_save_before in etc/events.xml.
 * DEPENDS:   Model\OrderSnapshot, Model\Config.
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Observer;

use KwtSms\SmsIntegration\Model\Config;
use KwtSms\SmsIntegration\Model\OrderSnapshot;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class OrderSaveBefore implements ObserverInterface
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var OrderSnapshot
     */
    private OrderSnapshot $snapshot;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config $config
     * @param OrderSnapshot $snapshot
     * @param LoggerInterface $logger
     */
    public function __construct(Config $config, OrderSnapshot $snapshot, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->snapshot = $snapshot;
        $this->logger = $logger;
    }

    /**
     * Record the order's stored values before the save.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            if (!$this->config->isEnabled()) {
                return;
            }
            $order = $observer->getEvent()->getOrder();
            if ($order instanceof \Magento\Sales\Model\Order) {
                $this->snapshot->capture($order);
            }
        } catch (\Throwable $e) {
            $this->logger->error('kwtSMS: OrderSaveBefore observer failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
