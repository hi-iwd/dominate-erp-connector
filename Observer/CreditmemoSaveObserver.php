<?php

namespace Dominate\ErpConnector\Observer;

use Dominate\ErpConnector\Helper\AddressFormatter;
use Dominate\ErpConnector\Model\Publisher;
use Dominate\ErpConnector\Model\SyncContext;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Psr\Log\LoggerInterface;

/**
 * Observer for sales_order_creditmemo_save_commit_after event.
 * Processes credit memos after they are saved and committed to database.
 */
class CreditmemoSaveObserver implements ObserverInterface
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
     * @var SyncContext
     */
    private SyncContext $syncContext;

    /**
     * CreditmemoSaveObserver constructor.
     *
     * @param Publisher       $publisher
     * @param LoggerInterface $logger
     * @param SyncContext     $syncContext
     */
    public function __construct(
        Publisher       $publisher,
        LoggerInterface $logger,
        SyncContext     $syncContext
    ) {
        $this->publisher   = $publisher;
        $this->logger      = $logger;
        $this->syncContext = $syncContext;
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
            /** @var Creditmemo $creditmemo */
            $creditmemo = $observer->getEvent()->getCreditmemo();

            if (!$creditmemo || !$creditmemo->getId()) {
                return;
            }

            // Skip outbound webhook when refund was created by ERP via RefundSync.
            if ($this->syncContext->isErpRefundSync()) {
                $this->logger->debug('[Dominate_ErpConnector] Skipping credit memo publish (ERP refund sync)', [
                    'creditmemo_id' => $creditmemo->getId(),
                ]);
                return;
            }

            $payload = $this->prepareCreditmemoPayload($creditmemo);
            $this->publisher->publish('refunds', (string) $creditmemo->getId(), 'create', $payload);
        } catch (\Throwable $e) {
            $this->logger->error('[Dominate_ErpConnector] Creditmemo observer exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Prepare credit memo payload for sync.
     *
     * @param Creditmemo $creditmemo
     * @return array
     */
    private function prepareCreditmemoPayload(Creditmemo $creditmemo): array
    {
        $order = $creditmemo->getOrder();
        $payment = $order->getPayment();

        $items = [];
        foreach ($creditmemo->getAllItems() as $item) {
            $items[] = [
                'order_item_id' => (int) $item->getOrderItemId(),
                'sku'           => $item->getSku(),
                'name'          => $item->getName(),
                'qty'           => (float) $item->getQty(),
                'price'         => (float) $item->getPrice(),
                'row_total'     => (float) $item->getRowTotal(),
                'weight'        => (float) $item->getWeight(),
                'tax'           => (float) $item->getTaxAmount(),
                'discount'      => (float) $item->getDiscountAmount(),
            ];
        }

        return [
            'version' => '1',
            'meta'    => $this->getMeta(),

            'creditmemo' => [
                'entity_id'   => (int) $creditmemo->getId(),
                'increment_id' => $creditmemo->getIncrementId(),
                'created_at'  => $creditmemo->getCreatedAt(),
                'state'       => $creditmemo->getState(),

                'totals' => [
                    'grand_total'      => (float) $creditmemo->getGrandTotal(),
                    'subtotal'         => (float) $creditmemo->getSubtotal(),
                    'tax'              => (float) $creditmemo->getTaxAmount(),
                    'shipping'         => (float) $creditmemo->getShippingAmount(),
                    'discount'         => (float) abs($creditmemo->getDiscountAmount()),
                    'adjustment_positive' => (float) $creditmemo->getAdjustmentPositive(),
                    'adjustment_negative' => (float) $creditmemo->getAdjustmentNegative(),
                ],
            ],

            'order' => [
                'entity_id'      => (int) $order->getId(),
                'increment_id'   => $order->getIncrementId(),
                'status'         => $order->getStatus(),
                'state'          => $order->getState(),
                'created_at'     => $order->getCreatedAt(),
                'updated_at'     => $order->getUpdatedAt(),
                'totals' => [
                    'grand'     => (float) $order->getGrandTotal(),
                    'subtotal'  => (float) $order->getSubtotal(),
                    'tax'       => (float) $order->getTaxAmount(),
                    'shipping'  => (float) $order->getShippingAmount(),
                    'discount'  => (float) abs($order->getDiscountAmount()),
                    'invoiced'  => (float) $order->getTotalInvoiced(),
                    'refunded'  => (float) $order->getTotalRefunded(),
                ],
            ],

            'payment_method' => $payment ? $payment->getMethod() : null,

            'currency' => [
                'order' => $order->getOrderCurrencyCode(),
                'base'  => $order->getBaseCurrencyCode(),
            ],

            'items' => $items,
        ];
    }
}

