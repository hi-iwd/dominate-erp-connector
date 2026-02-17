<?php

namespace Dominate\ErpConnector\Model;

use Dominate\ErpConnector\Api\FulfillmentSyncInterface;
use Dominate\ErpConnector\Helper\ApiAuthValidator;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Fulfillment sync implementation.
 * Handles fulfillment (shipment) updates from ERP (NetSuite) to Magento 2.
 */
class FulfillmentSync implements FulfillmentSyncInterface
{
    /**
     * @var ApiAuthValidator
     */
    private ApiAuthValidator $apiAuthValidator;

    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var ShipOrderInterface
     */
    private ShipOrderInterface $shipOrder;

    /**
     * @var ShipmentItemCreationInterfaceFactory
     */
    private ShipmentItemCreationInterfaceFactory $shipmentItemFactory;

    /**
     * @var ShipmentTrackCreationInterfaceFactory
     */
    private ShipmentTrackCreationInterfaceFactory $shipmentTrackFactory;

    /**
     * @var SearchCriteriaBuilder
     */
    private SearchCriteriaBuilder $searchCriteriaBuilder;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var SyncContext
     */
    private SyncContext $syncContext;

    /**
     * FulfillmentSync constructor.
     *
     * @param ApiAuthValidator                      $apiAuthValidator
     * @param OrderRepositoryInterface              $orderRepository
     * @param ShipOrderInterface                    $shipOrder
     * @param ShipmentItemCreationInterfaceFactory  $shipmentItemFactory
     * @param ShipmentTrackCreationInterfaceFactory $shipmentTrackFactory
     * @param SearchCriteriaBuilder                 $searchCriteriaBuilder
     * @param LoggerInterface                       $logger
     * @param SyncContext                           $syncContext
     */
    public function __construct(
        ApiAuthValidator                      $apiAuthValidator,
        OrderRepositoryInterface              $orderRepository,
        ShipOrderInterface                    $shipOrder,
        ShipmentItemCreationInterfaceFactory  $shipmentItemFactory,
        ShipmentTrackCreationInterfaceFactory $shipmentTrackFactory,
        SearchCriteriaBuilder                 $searchCriteriaBuilder,
        LoggerInterface                       $logger,
        SyncContext                           $syncContext
    ) {
        $this->apiAuthValidator    = $apiAuthValidator;
        $this->orderRepository     = $orderRepository;
        $this->shipOrder           = $shipOrder;
        $this->shipmentItemFactory = $shipmentItemFactory;
        $this->shipmentTrackFactory = $shipmentTrackFactory;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->logger              = $logger;
        $this->syncContext         = $syncContext;
    }

    /**
     * Sync fulfillments (shipments) for orders.
     *
     * @param string $api_key
     * @param int    $timestamp
     * @param string $signature
     * @param mixed  $shipments Array of shipment updates with keys: order_increment_id, items, tracking_numbers, carrier_code, etc.
     * @return mixed[]
     */
    public function sync(
        string $api_key,
        int    $timestamp,
        string $signature,
        mixed  $shipments
    ) {
        // Validate API credentials and HMAC signature
        $authResult = $this->apiAuthValidator->validate($api_key, $timestamp, $signature);
        if ($authResult['Error'] === true) {
            return $authResult;
        }

        // Validate shipments array
        if (empty($shipments) || !is_array($shipments)) {
            $this->logger->warning('[Dominate_ErpConnector] Fulfillment sync failed: invalid_shipments');
            return ['Error' => true, 'ErrorCode' => 'invalid_shipments'];
        }

        $results = [];
        $errors  = [];

        // Mark this request as ERP-initiated fulfillment sync to prevent webhook loops.
        $this->syncContext->startErpFulfillmentSync();

        try {
            foreach ($shipments as $shipmentData) {
                if (!isset($shipmentData['order_increment_id']) || empty($shipmentData['order_increment_id'])) {
                    $errors[] = 'Missing order_increment_id in shipment data';
                    continue;
                }

                $orderIncrementId = (string) $shipmentData['order_increment_id'];

                try {
                    // Load order by increment ID
                    $order = $this->getOrderByIncrementId($orderIncrementId);

                    // Check if order can be shipped
                    if (!$order->canShip()) {
                        $this->logger->info('[Dominate_ErpConnector] Order cannot be shipped, skipping', [
                            'order_increment_id' => $orderIncrementId,
                            'order_state' => $order->getState(),
                        ]);
                        $results[] = [
                            'order_increment_id' => $orderIncrementId,
                            'order_status' => $order->getStatus(),
                            'status' => 'skipped',
                            'reason' => 'Order cannot be shipped',
                        ];
                        continue;
                    }

                    // Build shipment items from payload
                    $shipmentItems = $this->buildShipmentItems($order, $shipmentData['items'] ?? null);

                    if (empty($shipmentItems)) {
                        $this->logger->info('[Dominate_ErpConnector] No items to ship, skipping', [
                            'order_increment_id' => $orderIncrementId,
                        ]);
                        $results[] = [
                            'order_increment_id' => $orderIncrementId,
                            'order_status' => $order->getStatus(),
                            'status' => 'success',
                            'reason' => 'No items to ship (already shipped)',
                        ];
                        continue;
                    }

                    // Build tracking information
                    $tracks = $this->buildTracks($shipmentData);

                    // Create shipment using ShipOrderInterface
                    $shipmentId = $this->shipOrder->execute(
                        (int) $order->getEntityId(),
                        $shipmentItems,
                        false, // Don't notify customer by default (can be made configurable)
                        false, // Don't append comment
                        null,  // No comment
                        $tracks,
                        [],    // No packages
                        null   // No arguments
                    );

                    $results[] = [
                        'order_increment_id' => $orderIncrementId,
                        'order_status' => $order->getStatus(),
                        'status' => 'success',
                        'shipment_id' => $shipmentId,
                    ];

                    $this->logger->info('[Dominate_ErpConnector] Shipment created successfully', [
                        'order_increment_id' => $orderIncrementId,
                        'shipment_id' => $shipmentId,
                    ]);
                } catch (NoSuchEntityException $e) {
                    // Order not found - skip as per requirements
                    $this->logger->info('[Dominate_ErpConnector] Order not found, skipping', [
                        'order_increment_id' => $orderIncrementId,
                    ]);
                    $results[] = [
                        'order_increment_id' => $orderIncrementId,
                        'status' => 'skipped',
                        'reason' => 'Order not found',
                    ];
                } catch (\Exception $e) {
                    $errorMsg = $e->getMessage();
                    $errors[] = "Order {$orderIncrementId}: {$errorMsg}";
                    $this->logger->error('[Dominate_ErpConnector] Shipment creation failed', [
                        'order_increment_id' => $orderIncrementId,
                        'error' => $errorMsg,
                    ]);
                    // Reload order to get current status after potential state changes
                    try {
                        $order = $this->getOrderByIncrementId($orderIncrementId);
                        $orderStatus = $order->getStatus();
                    } catch (\Exception $e) {
                        $orderStatus = null;
                    }

                    $results[] = [
                        'order_increment_id' => $orderIncrementId,
                        'order_status' => $orderStatus,
                        'status' => 'failed',
                        'error' => $errorMsg,
                    ];
                }
            }
        } catch (\Exception $e) {
            // Log unexpected errors
            $this->logger->error('[Dominate_ErpConnector] Unexpected error during fulfillment sync', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            // Always clear context even on exceptions to prevent state leakage.
            $this->syncContext->endErpFulfillmentSync();
        }

        // Return response
        if (!empty($errors)) {
            return [
                'Error'   => false,
                'results' => $results,
                'warnings' => $errors,
            ];
        }

        return [
            'Error'   => false,
            'results' => $results,
        ];
    }

    /**
     * Load order by increment ID.
     *
     * @param string $incrementId
     * @return OrderInterface
     * @throws NoSuchEntityException
     */
    private function getOrderByIncrementId(string $incrementId): OrderInterface
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->setPageSize(1)
            ->create();

        $items = $this->orderRepository->getList($searchCriteria)->getItems();
        $order = reset($items);

        if (!$order) {
            throw new NoSuchEntityException(
                __('Order with increment ID "%1" does not exist.', $incrementId)
            );
        }

        return $order;
    }

    /**
     * Build shipment items from payload or ship all available items.
     *
     * @param OrderInterface $order
     * @param array|null      $itemsData Optional array of items with keys: sku, qty
     * @return array Array of ShipmentItemCreationInterface objects
     */
    private function buildShipmentItems(OrderInterface $order, ?array $itemsData = null): array
    {
        $shipmentItems = [];

        if ($itemsData !== null && is_array($itemsData) && !empty($itemsData)) {
            // Build items from payload
            foreach ($itemsData as $itemData) {
                if (!isset($itemData['sku']) || !isset($itemData['qty'])) {
                    continue;
                }

                // Find order item by SKU
                $orderItem = null;
                foreach ($order->getAllItems() as $oi) {
                    if ($oi->getSku() === $itemData['sku']) {
                        $orderItem = $oi;
                        break;
                    }
                }

                if (!$orderItem || $orderItem->getQtyToShip() <= 0) {
                    continue;
                }

                // Clamp requested qty to available qty_to_ship
                $maxQtyToShip = (float) $orderItem->getQtyToShip();
                $requestedQty = (float) $itemData['qty'];
                $qtyToShip = min($requestedQty, $maxQtyToShip);

                if ($qtyToShip <= 0) {
                    continue;
                }

                // Create shipment item
                $shipmentItem = $this->shipmentItemFactory->create();
                $shipmentItem->setOrderItemId($orderItem->getItemId());
                $shipmentItem->setQty($qtyToShip);
                $shipmentItems[] = $shipmentItem;
            }
        } else {
            // If no items specified, ship all available items
            foreach ($order->getAllItems() as $orderItem) {
                if ($orderItem->getQtyToShip() > 0 && !$orderItem->getIsVirtual()) {
                    $shipmentItem = $this->shipmentItemFactory->create();
                    $shipmentItem->setOrderItemId($orderItem->getItemId());
                    $shipmentItem->setQty($orderItem->getQtyToShip());
                    $shipmentItems[] = $shipmentItem;
                }
            }
        }

        return $shipmentItems;
    }

    /**
     * Build tracking information from shipment data.
     *
     * @param array $shipmentData Shipment data with keys: tracking_numbers, carrier_code, carrier_title
     * @return array Array of ShipmentTrackCreationInterface objects
     */
    private function buildTracks(array $shipmentData): array
    {
        $tracks = [];
        $trackingNumbers = $shipmentData['tracking_numbers'] ?? [];
        $carrierCode = $shipmentData['carrier_code'] ?? 'custom';
        $carrierTitle = $shipmentData['carrier_title'] ?? 'Custom';

        if (!empty($trackingNumbers)) {
            foreach ($trackingNumbers as $trackingNumber) {
                $track = $this->shipmentTrackFactory->create();
                $track->setCarrierCode($carrierCode);
                $track->setTitle($carrierTitle);
                $track->setTrackNumber((string) $trackingNumber);
                $tracks[] = $track;
            }
        }

        return $tracks;
    }
}

