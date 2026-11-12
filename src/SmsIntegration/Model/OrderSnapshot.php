<?php
/**
 * FILE:      src/SmsIntegration/Model/OrderSnapshot.php
 * CREATED:   2026-11-12 10:18 +03
 * TASK:      magento-audit-fixes F1 (double SMS on cancel, ship, invoice)
 * AUTHOR:    M Alhassoun
 * WHAT:      Holds what the database said about an order just before it is saved: whether it existed, its status
 *            and state, invoiced and refunded totals and shipped quantity. Filled by OrderSaveBefore, read once by
 *            OrderSaveAfter.
 * WHY:       Orders loaded through the order repository (invoice, shipment, cancel, hold) carry no original data,
 *            so getOrigData('status') was null and every later save looked like a new order (a second "new order"
 *            SMS). Reading the stored row before the save is reliable for every load path.
 * USAGE:     Shared instance (DI default). take() removes the entry it returns.
 * DEPENDS:   sales_order, sales_order_item tables.
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Model;

use Magento\Framework\App\ResourceConnection;

class OrderSnapshot
{
    /**
     * @var array<int, array{existed: bool, status: ?string, state: ?string, total_invoiced: float,
     *     total_refunded: float, qty_shipped: float}>
     */
    private array $snapshots = [];

    /**
     * Weak reference to the order each snapshot belongs to (same keys as $snapshots).
     * 2026-11-12 10:31 +03: Onyx low finding on #1244: a save that throws leaves its entry, and PHP can reuse the
     * object id for a later order in the same process; the weak reference tells the two apart.
     *
     * @var array<int, \WeakReference>
     */
    private array $owners = [];

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resource;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Record the stored values of this order before it is saved.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return void
     */
    public function capture(\Magento\Sales\Model\Order $order): void
    {
        $snapshot = [
            'existed' => false, 'status' => null, 'state' => null,
            'total_invoiced' => 0.0, 'total_refunded' => 0.0, 'qty_shipped' => 0.0,
        ];
        $orderId = (int) $order->getId();
        if ($orderId > 0) {
            $connection = $this->resource->getConnection();
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($this->resource->getTableName('sales_order'), ['status', 'state', 'total_invoiced', 'total_refunded'])
                    ->where('entity_id = ?', $orderId)
            );
            if (is_array($row)) {
                $shipped = $connection->fetchOne(
                    $connection->select()
                        ->from($this->resource->getTableName('sales_order_item'), ['SUM(qty_shipped)'])
                        ->where('order_id = ?', $orderId)
                );
                $snapshot = [
                    'existed' => true,
                    'status' => $row['status'] !== null ? (string) $row['status'] : null,
                    'state' => $row['state'] !== null ? (string) $row['state'] : null,
                    'total_invoiced' => (float) $row['total_invoiced'],
                    'total_refunded' => (float) $row['total_refunded'],
                    'qty_shipped' => (float) $shipped,
                ];
            }
        }
        $this->snapshots[spl_object_id($order)] = $snapshot;
        $this->owners[spl_object_id($order)] = \WeakReference::create($order);
    }

    /**
     * Return and forget the snapshot taken before this save, or null when none was taken.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return array{existed: bool, status: ?string, state: ?string, total_invoiced: float,
     *     total_refunded: float, qty_shipped: float}|null
     */
    public function take(\Magento\Sales\Model\Order $order): ?array
    {
        $key = spl_object_id($order);
        $snapshot = $this->snapshots[$key] ?? null;
        $owner = $this->owners[$key] ?? null;
        unset($this->snapshots[$key], $this->owners[$key]);
        // 2026-11-12 10:31 +03: a leftover entry from another (freed) order object is ignored, Onyx #1244.
        if ($owner === null || $owner->get() !== $order) {
            return null;
        }
        return $snapshot;
    }
}
