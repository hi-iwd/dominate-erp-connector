<?php

namespace Dominate\ErpConnector\Observer;

use Dominate\ErpConnector\Helper\AddressFormatter;
use Dominate\ErpConnector\Model\Publisher;
use Dominate\ErpConnector\Model\SyncContext;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Shipment;
use Psr\Log\LoggerInterface;

/**
 * Observer for sales_shipment_save_commit_after event.
 * Processes shipments after they are saved and committed to database.
 */
class ShipmentSaveObserver implements ObserverInterface
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
     * ShipmentSaveObserver constructor.
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
            /** @var Shipment $shipment */
            $shipment = $observer->getEvent()->getShipment();

            if (!$shipment || !$shipment->getId()) {
                return;
            }

            // Skip outbound webhook when shipment was created by ERP via FulfillmentSync.
            if ($this->syncContext->isErpFulfillmentSync()) {
                $this->logger->debug('[Dominate_ErpConnector] Skipping shipment publish (ERP fulfillment sync)', [
                    'shipment_id' => $shipment->getId(),
                ]);
                return;
            }

            $payload = $this->prepareShipmentPayload($shipment);
            $this->publisher->publish('fulfilments', (string) $shipment->getId(), 'create', $payload);
        } catch (\Throwable $e) {
            $this->logger->error('[Dominate_ErpConnector] Shipment observer exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Prepare shipment payload for sync.
     *
     * @param Shipment $shipment
     * @return array
     */
    private function prepareShipmentPayload(Shipment $shipment): array
    {
        $order = $shipment->getOrder();
        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();

        // Extract MSI source_code from shipment extension attributes (Magento 2.3+)
        $sourceCode = null;
        $extAttributes = $shipment->getExtensionAttributes();
        if ($extAttributes !== null && method_exists($extAttributes, 'getSourceCode')) {
            $sourceCode = $extAttributes->getSourceCode();
        }

        $tracks = [];
        foreach ($shipment->getAllTracks() as $track) {
            $tracks[] = [
                'number' => $track->getTrackNumber(),
                'title'  => $track->getTitle(),
            ];
        }

        $items = [];
        foreach ($shipment->getAllItems() as $item) {
            $items[] = [
                'order_item_id' => (int) $item->getOrderItemId(),
                'sku'           => $item->getSku(),
                'name'          => $item->getName(),
                'qty'           => (float) $item->getQty(),
            ];
        }

        return [
            'version' => '1',
            'meta'    => $this->getMeta(),

            'shipment' => [
                'entity_id'      => (int) $shipment->getId(),
                'increment_id'   => $shipment->getIncrementId(),
                'created_at'     => $shipment->getCreatedAt(),
                'state'          => $shipment->getState(),
                'package_weight' => (float) $shipment->getTotalWeight(),
                'source_code'    => $sourceCode,
            ],

            'order' => $this->getOrderContext($order),

            'carrier' => [
                'code'            => $shipment->getOrder()->getShippingMethod() ? explode('_', $shipment->getOrder()->getShippingMethod())[0] : null,
                'title'           => $order->getShippingDescription(),
                'tracking_numbers' => $tracks,
            ],

            'addresses' => [
                'billing'  => $billingAddress ? $this->flattenOrderAddress($billingAddress) : [],
                'shipping' => $shippingAddress ? $this->flattenOrderAddress($shippingAddress) : [],
            ],

            'items' => $items,
        ];
    }
}

