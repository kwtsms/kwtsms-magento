<?php
/**
 * Order Save After Observer.
 *
 * Sends an SMS notification when an order status changes.
 * Only triggers when the status value has actually changed compared to
 * the original data, preventing duplicate notifications on unrelated saves.
 *
 * @see \KwtSms\SmsIntegration\Model\SmsSender
 * @see \KwtSms\SmsIntegration\Model\TemplateProcessor
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Observer;

use KwtSms\SmsIntegration\Model\Config;
use KwtSms\SmsIntegration\Model\OrderSnapshot;
use KwtSms\SmsIntegration\Model\SmsSender;
use KwtSms\SmsIntegration\Model\TemplateProcessor;
use KwtSms\SmsIntegration\Model\TemplateVariables\OrderVariables;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class OrderSaveAfter implements ObserverInterface
{
    /**
     * Track orders already processed in this request to prevent duplicate SMS.
     * @var array<string, bool>
     */
    private static array $processedOrders = [];

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var SmsSender
     */
    private SmsSender $smsSender;

    /**
     * @var TemplateProcessor
     */
    private TemplateProcessor $templateProcessor;

    /**
     * @var OrderVariables
     */
    private OrderVariables $orderVariables;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var OrderSnapshot
     */
    private OrderSnapshot $snapshot;

    /**
     * @param Config $config
     * @param SmsSender $smsSender
     * @param TemplateProcessor $templateProcessor
     * @param OrderVariables $orderVariables
     * @param LoggerInterface $logger
     * @param OrderSnapshot $snapshot
     */
    public function __construct(
        Config $config,
        SmsSender $smsSender,
        TemplateProcessor $templateProcessor,
        OrderVariables $orderVariables,
        LoggerInterface $logger,
        OrderSnapshot $snapshot
    ) {
        $this->config = $config;
        $this->smsSender = $smsSender;
        $this->templateProcessor = $templateProcessor;
        $this->orderVariables = $orderVariables;
        $this->logger = $logger;
        $this->snapshot = $snapshot;
    }

    /**
     * Execute observer for order status change.
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
            if ($order === null) {
                return;
            }

            $orderId = $order->getEntityId();
            // 2026-11-12 10:18 +03: stored values from OrderSaveBefore; repository-loaded orders (invoice, ship,
            // cancel, hold) have no original data, so every later save was taken for a new order (second "new
            // order" SMS). Fallback to original data only when no snapshot was taken. magento-audit-fixes F1.
            $before = $this->snapshot->take($order) ?? $this->snapshotFromOrigData($order);
            $origStatus = $before['existed'] ? (string) $before['status'] : null;
            $currentStatus = $order->getStatus();

            // No status change, nothing to do
            if ($origStatus === $currentStatus) {
                return;
            }

            // Prevent duplicate SMS if order is saved multiple times in one request
            $key = $orderId . ':' . ($origStatus ?? 'null') . ':' . $currentStatus;
            if (isset(self::$processedOrders[$key])) {
                return;
            }
            self::$processedOrders[$key] = true;

            $phone = $order->getBillingAddress()?->getTelephone();
            $incrementId = $order->getIncrementId();
            $variables = $this->orderVariables->resolve($order);
            $storeLocale = $this->getStoreLocale($order);

            // Detect brand-new order: no stored row before this save
            $isNewOrder = !$before['existed'];

            if ($isNewOrder) {
                // Send "new order" customer SMS
                if ($this->config->isIntegrationEnabled('order_new') && $phone) {
                    $message = $this->templateProcessor->render('order_new', $variables, $storeLocale);
                    if ($message !== null) {
                        $this->smsSender->send($phone, $message, 'order_new', $incrementId, 'order');
                    }
                }

                // Send "new order" admin alert
                if ($this->config->isIntegrationEnabled('admin_new_order')) {
                    $adminPhones = $this->config->getAdminPhones();
                    foreach ($adminPhones as $adminPhone) {
                        $message = $this->templateProcessor->render('admin_new_order', $variables);
                        if ($message !== null) {
                            $this->smsSender->send($adminPhone, $message, 'admin_new_order', $incrementId, 'order');
                        }
                    }
                }
            } else {
                // Existing order, status changed: send status update SMS
                // 2026-11-12 10:07 +03: skip when this save comes from an invoice, shipment, credit memo or cancel
                // whose own SMS is enabled; that observer already tells the customer (was 2 SMS), magento-audit-fixes F1.
                $coveredBy = $this->coveredByEventSms($order, $before);
                if ($coveredBy !== null) {
                    $this->logger->info('kwtSMS: status SMS skipped, covered by ' . $coveredBy, [
                        'order' => $incrementId,
                    ]);
                } elseif ($this->config->isIntegrationEnabled('order_status_change') && $phone) {
                    $message = $this->templateProcessor->render('order_status_change', $variables, $storeLocale);
                    if ($message !== null) {
                        $this->smsSender->send($phone, $message, 'order_status_change', $incrementId, 'order');
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('kwtSMS: OrderSaveAfter observer failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Name the enabled event SMS that already covers this status change, if any.
     *
     * 2026-11-12 10:07 +03: magento-audit-fixes F1 (double SMS on cancel, ship, invoice). The change is read from
     * the values this save changed (cancel state, invoiced total, shipped quantities, refunded total), so a manual
     * status change by the merchant still sends the status SMS.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param array<string, mixed> $before stored values before this save (OrderSnapshot)
     * @return string|null event name, or null when no enabled event SMS covers this change
     */
    private function coveredByEventSms(\Magento\Sales\Model\Order $order, array $before): ?string
    {
        $state = (string) $order->getState();
        if ($state === \Magento\Sales\Model\Order::STATE_CANCELED
            && $before['state'] !== $state
            && $this->config->isIntegrationEnabled('order_cancelled')
        ) {
            return 'order_cancelled';
        }
        if ((float) $order->getTotalRefunded() > $before['total_refunded']
            && $this->config->isIntegrationEnabled('order_refunded')
        ) {
            return 'order_refunded';
        }
        if ($this->config->isIntegrationEnabled('order_shipped')) {
            $shipped = 0.0;
            foreach ($order->getAllItems() as $item) {
                $shipped += (float) $item->getQtyShipped();
            }
            if ($shipped > $before['qty_shipped']) {
                return 'order_shipped';
            }
        }
        if ((float) $order->getTotalInvoiced() > $before['total_invoiced']
            && $this->config->isIntegrationEnabled('order_invoiced')
        ) {
            return 'order_invoiced';
        }
        return null;
    }

    /**
     * Build the before-save values from the order's original data (used when OrderSaveBefore did not run).
     *
     * 2026-11-12 10:18 +03: magento-audit-fixes F1, keeps the 1.1.x behaviour as the fallback.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return array<string, mixed>
     */
    private function snapshotFromOrigData(\Magento\Sales\Model\Order $order): array
    {
        $status = $order->getOrigData('status');
        $shipped = 0.0;
        foreach ($order->getAllItems() as $item) {
            $shipped += (float) $item->getOrigData('qty_shipped');
        }
        return [
            'existed' => $status !== null && $status !== '',
            'status' => $status,
            'state' => $order->getOrigData('state'),
            'total_invoiced' => (float) $order->getOrigData('total_invoiced'),
            'total_refunded' => (float) $order->getOrigData('total_refunded'),
            'qty_shipped' => $shipped,
        ];
    }

    /**
     * Get the store locale from the order's store.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return string|null
     */
    private function getStoreLocale(\Magento\Sales\Model\Order $order): ?string
    {
        try {
            return $order->getStore()->getConfig('general/locale/code');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
