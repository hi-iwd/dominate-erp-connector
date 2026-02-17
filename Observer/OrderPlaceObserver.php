<?php

namespace Dominate\ErpConnector\Observer;

use Dominate\ErpConnector\Helper\AddressFormatter;
use Dominate\ErpConnector\Model\Publisher;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Observer for sales_order_save_commit_after event.
 * Processes orders after they are saved and committed to database.
 */
class OrderPlaceObserver implements ObserverInterface
{
    use AddressFormatter;

    /**
     * @var Publisher
     */
    private Publisher $publisher;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * OrderPlaceObserver constructor.
     *
     * @param Publisher      $publisher
     * @param LoggerInterface $logger
     */
    public function __construct(
        Publisher      $publisher,
        LoggerInterface $logger
    ) {
        $this->publisher = $publisher;
        $this->logger    = $logger;
    }

    /**
     * Execute observer.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            /** @var Order $order */
            $order = $observer->getEvent()->getOrder();

            if (!$order) return;

            $orderId = $order->getId();
            $incrementId = $order->getIncrementId();

            if (!$orderId || !$incrementId) return;

            // Only treat as a true create when order has just been created.
            // Any subsequent save (status/customer/invoice/etc.) will be skipped.
            if ($order->getCreatedAt() !== $order->getUpdatedAt()) {
                return;
            }

            $eventType = 'create';
            $payload = $this->prepareOrderPayload($order);
            $this->publisher->publish('orders', (string) $orderId, $eventType, $payload);
        } catch (\Throwable $e) {
            $this->logger->error('[Dominate_ErpConnector] Order observer exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Prepare order payload for sync.
     *
     * @param Order $order
     * @return array
     */
    private function prepareOrderPayload(Order $order): array
    {
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [
                'item_id'       => (int) $item->getItemId(),
                'product_id'    => (int) $item->getProductId(),
                'parent_item_id' => $item->getParentItemId() ? (int) $item->getParentItemId() : null,
                'sku'           => $item->getSku(),
                'name'          => $item->getName(),
                'product_type'  => $item->getProductType(),
                'qty'           => (float) $item->getQtyOrdered(),
                'price'         => (float) $item->getPrice(),
                'row_total'     => (float) $item->getRowTotal(),
                'weight'        => (float) $item->getWeight(),
                'tax'           => (float) $item->getTaxAmount(),
                'discount'      => (float) $item->getDiscountAmount(),
            ];
        }

        $billingAddress  = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();

        $billing  = $billingAddress ? $this->flattenOrderAddress($billingAddress) : [];
        $shipping = $shippingAddress ? $this->flattenOrderAddress($shippingAddress) : $billing;

        $payment = $order->getPayment();
        /** @var \Magento\Sales\Model\Order\Payment|null $payment */
        $paymentMethodTitle = null;

        if ($payment instanceof \Magento\Sales\Model\Order\Payment) {
            $methodInstance = $payment->getMethodInstance();
            if ($methodInstance) {
                $paymentMethodTitle = $methodInstance->getTitle();
            }
        }

        $invoiceCollection = $order->getInvoiceCollection();
        $invoices = [];
        foreach ($invoiceCollection as $invoice) {
            $invoices[] = [
                'increment_id' => $invoice->getIncrementId(),
                'grand_total'  => (float) $invoice->getGrandTotal(),
                'created_at'   => $invoice->getCreatedAt(),
            ];
        }

        $shipmentCollection = $order->getShipmentsCollection();
        $shipments = [];
        foreach ($shipmentCollection as $shipment) {
            $shipments[] = [
                'increment_id' => $shipment->getIncrementId(),
                'created_at'   => $shipment->getCreatedAt(),
            ];
        }

        $paymentAdditionalInfo = [];
        if ($payment) {
            $paymentAdditionalInfo = $payment->getAdditionalInformation() ?: [];
        }

        // Derive shipping method parts once and reuse
        $shippingMethod = $order->getShippingMethod();
        $carrierCode    = null;
        $methodCode     = null;

        if ($shippingMethod && strpos($shippingMethod, '_') !== false) {
            [$carrierCode, $methodCode] = explode('_', $shippingMethod, 2);
        }

        return [
            'version' => '1',
            'meta'    => $this->getMeta(),

            'order' => [
                'entity_id'    => (int) $order->getId(),
                'increment_id' => $order->getIncrementId(),
                'created_at'   => $order->getCreatedAt(),
                'updated_at'   => $order->getUpdatedAt(),
                'status'       => $order->getStatus(),
                'state'        => $order->getState(),
                'is_guest'     => (bool) $order->getCustomerIsGuest(),
                'is_virtual'   => (bool) $order->getIsVirtual(),
                'customer_note' => $order->getCustomerNote(),
                'remote_ip'     => $order->getRemoteIp(),
            ],

            'store' => [
                'store_id'   => (int) $order->getStoreId(),
                'website_id' => (int) $order->getStore()->getWebsiteId(),
                'currency'   => [
                'order' => $order->getOrderCurrencyCode(),
                'base'  => $order->getBaseCurrencyCode(),
                    'rates' => [
                        'base_to_order' => (float) $order->getBaseToOrderRate(),
                        'base_to_global' => (float) $order->getBaseToGlobalRate(),
                    ],
                ],
            ],

            'totals' => [
                'grand'     => (float) $order->getGrandTotal(),
                'subtotal'  => (float) $order->getSubtotal(),
                'tax'       => (float) $order->getTaxAmount(),
                'shipping'  => (float) $order->getShippingAmount(),
                'discount'  => (float) abs($order->getDiscountAmount()),
                'invoiced'  => (float) $order->getTotalInvoiced(),
                'refunded'  => (float) $order->getTotalRefunded(),
            ],

            'payment' => [
                'method'          => $payment ? $payment->getMethod() : null,
                'method_title'    => $paymentMethodTitle,
                'cc_type'         => $payment ? $payment->getCcType() : null,
                'transaction_id'  => $payment ? $payment->getLastTransId() : null,
                'additional_info' => $paymentAdditionalInfo,
            ],

            'coupon' => [
                'code'        => $order->getCouponCode(),
                'description' => $order->getDiscountDescription(),
            ],

            'customer' => [
                'entity_id' => (int) $order->getCustomerId(),
                'email'     => $order->getCustomerEmail(),
                'firstname' => $order->getCustomerFirstname(),
                'lastname'  => $order->getCustomerLastname(),
                'group_id'  => (int) $order->getCustomerGroupId(),
            ],

            'addresses' => [
                'billing'  => $billing,
                'shipping' => $shipping,
            ],

            'items'     => $items,
            'invoices'  => $invoices,
            'shipments' => $shipments,

            'shipping' => [
                'method'       => $shippingMethod,
                'description'  => $order->getShippingDescription(),
                'carrier_code' => $carrierCode,
                'method_code'  => $methodCode,
            ],

            'shipping_method' => $shippingMethod,
        ];
    }
}

