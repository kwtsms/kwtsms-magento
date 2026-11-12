<?php
/**
 * FILE:      .github/ci/event-flow.php
 * CREATED:   2026-11-12 10:07 +03
 * TASK:      magento-audit-fixes F1 (double SMS on cancel, ship, invoice)
 * AUTHOR:    M Alhassoun
 * WHAT:      Places real orders on a fresh Magento install and runs the real invoice, shipment, hold and cancel
 *            services, with SmsSender replaced by a recorder. Checks which SMS events the module asks to send for
 *            each step: exactly one per customer-visible change, never a second status SMS.
 * WHY:       Unit tests cannot see which observers Magento fires together; 1.1.x sent two SMS on cancel and ship.
 * USAGE:     php .github/ci/event-flow.php   (from the Magento root; run by magento-install.yml). Exit 1 on mismatch.
 * DEPENDS:   Installed Magento with KwtSms_SmsIntegration, flat rate shipping and check/money order (both default on).
 *            Sends nothing: the recorder never calls the gateway. The phone is the unassigned 999 test number.
 */
declare(strict_types=1);

use KwtSms\SmsIntegration\Model\SmsSender;
use Magento\Framework\App\Bootstrap;

require getcwd() . '/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

$writer = $om->get(\Magento\Framework\App\Config\Storage\WriterInterface::class);
$writer->save('kwtsms/general/enabled', '1');
$om->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();

// Recorder: same type as SmsSender, so every observer gets it; it never reaches the gateway.
$recorder = new class extends SmsSender {
    /** @var string[] */
    public array $events = [];

    public function __construct()
    {
    }

    public function send(
        string|array $recipients,
        string $message,
        string $eventType,
        ?string $relatedEntityId = null,
        ?string $relatedEntityType = null
    ): array {
        $this->events[] = $eventType;
        return ['success' => true, 'sent' => 1, 'failed' => 0, 'skipped' => 0, 'invalid' => [], 'error' => null];
    }
};
$om->addSharedInstance($recorder, SmsSender::class);

function setFlag($om, string $path, string $value): void
{
    $om->get(\Magento\Framework\App\Config\Storage\WriterInterface::class)->save($path, $value);
    $om->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
}

$product = $om->create(\Magento\Catalog\Api\Data\ProductInterface::class);
$product->setSku('kwtsms-ci-' . gmdate('YmdHis'))->setName('kwtSMS CI product')->setAttributeSetId(4)
    ->setTypeId('simple')->setPrice(5)->setStatus(1)->setVisibility(4)->setWebsiteIds([1])
    ->setStockData(['use_config_manage_stock' => 0, 'manage_stock' => 0, 'qty' => 100, 'is_in_stock' => 1]);
$product = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->save($product);

function placeOrder($om, $product): \Magento\Sales\Model\Order
{
    $cartManagement = $om->get(\Magento\Quote\Api\CartManagementInterface::class);
    $cartRepository = $om->get(\Magento\Quote\Api\CartRepositoryInterface::class);
    $quote = $cartRepository->get($cartManagement->createEmptyCart());
    $quote->setStoreId(1)->setCustomerEmail('ci@example.com')->setCustomerIsGuest(true);
    $quote->addProduct($om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->get($product->getSku()), 1);
    $address = [
        'firstname' => 'CI', 'lastname' => 'Buyer', 'street' => 'Street 1', 'city' => 'Kuwait City',
        'country_id' => 'KW', 'postcode' => '13001', 'telephone' => '99912345678',
    ];
    $quote->getBillingAddress()->addData($address);
    $quote->getShippingAddress()->addData($address)->setCollectShippingRates(true)->collectShippingRates()
        ->setShippingMethod('flatrate_flatrate');
    $quote->setPaymentMethod('checkmo');
    $quote->getPayment()->importData(['method' => 'checkmo']);
    $quote->collectTotals();
    $cartRepository->save($quote);
    $orderId = $cartManagement->placeOrder($quote->getId());
    return $om->create(\Magento\Sales\Model\Order::class)->load($orderId);
}

$failures = 0;
function step(string $name, callable $action, array $expected, $recorder): void
{
    global $failures;
    $recorder->events = [];
    $action();
    $got = $recorder->events;
    $ok = $got === $expected;
    $failures += $ok ? 0 : 1;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ': expected ' . json_encode($expected) . ' got ' . json_encode($got) . "\n";
}

$invoiceOrder = $om->get(\Magento\Sales\Api\InvoiceOrderInterface::class);
$shipOrder = $om->get(\Magento\Sales\Api\ShipOrderInterface::class);
$orderManagement = $om->get(\Magento\Sales\Api\OrderManagementInterface::class);

// Defaults: status change on, shipped on, cancelled on, invoiced off, refunded off.
$o1 = null;
step('new order', function () use (&$o1, $om, $product) { $o1 = placeOrder($om, $product); }, ['order_new'], $recorder);
step('invoice (invoice SMS off)', function () use (&$o1, $invoiceOrder) { $invoiceOrder->execute((int) $o1->getId()); },
    ['order_status_change'], $recorder);
step('ship', function () use (&$o1, $shipOrder) { $shipOrder->execute((int) $o1->getId()); }, ['order_shipped'], $recorder);

$o2 = placeOrder($om, $product);
step('cancel', function () use ($o2, $orderManagement) { $orderManagement->cancel((int) $o2->getId()); },
    ['order_cancelled'], $recorder);

$o3 = placeOrder($om, $product);
step('hold (manual status change)', function () use ($o3, $orderManagement) { $orderManagement->hold((int) $o3->getId()); },
    ['order_status_change'], $recorder);

setFlag($om, 'kwtsms/integrations/order_invoiced', '1');
$o4 = placeOrder($om, $product);
step('invoice (invoice SMS on)', function () use ($o4, $invoiceOrder) { $invoiceOrder->execute((int) $o4->getId()); },
    ['order_invoiced'], $recorder);

echo $failures === 0 ? "event flow: all steps pass\n" : "event flow: $failures step(s) failed\n";
exit($failures === 0 ? 0 : 1);
