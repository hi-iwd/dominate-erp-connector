<?php

namespace Dominate\ErpConnector\Model;

use Dominate\ErpConnector\Api\RefundSyncInterface;
use Dominate\ErpConnector\Helper\ApiAuthValidator;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\RefundOrderInterface;
use Magento\Sales\Api\Data\CreditmemoItemCreationInterfaceFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Refund sync implementation.
 * Handles refund (credit memo) updates from ERP (NetSuite) to Magento 2.
 */
class RefundSync implements RefundSyncInterface
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
     * @var RefundOrderInterface
     */
    private RefundOrderInterface $refundOrder;

    /**
     * @var CreditmemoItemCreationInterfaceFactory
     */
    private CreditmemoItemCreationInterfaceFactory $creditmemoItemFactory;

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
     * RefundSync constructor.
     *
     * @param ApiAuthValidator                      $apiAuthValidator
     * @param OrderRepositoryInterface              $orderRepository
     * @param RefundOrderInterface                  $refundOrder
     * @param CreditmemoItemCreationInterfaceFactory $creditmemoItemFactory
     * @param SearchCriteriaBuilder                 $searchCriteriaBuilder
     * @param LoggerInterface                       $logger
     * @param SyncContext                           $syncContext
     */
    public function __construct(
        ApiAuthValidator                      $apiAuthValidator,
        OrderRepositoryInterface              $orderRepository,
        RefundOrderInterface                  $refundOrder,
        CreditmemoItemCreationInterfaceFactory $creditmemoItemFactory,
        SearchCriteriaBuilder                 $searchCriteriaBuilder,
        LoggerInterface                       $logger,
        SyncContext                           $syncContext
    ) {
        $this->apiAuthValidator    = $apiAuthValidator;
        $this->orderRepository     = $orderRepository;
        $this->refundOrder         = $refundOrder;
        $this->creditmemoItemFactory = $creditmemoItemFactory;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->logger              = $logger;
        $this->syncContext         = $syncContext;
    }

    /**
     * Sync refunds (credit memos) for orders.
     *
     * @param string $api_key
     * @param int    $timestamp
     * @param string $signature
     * @param mixed  $refunds Array of refund updates with keys: order_increment_id, items, amount, reason, etc.
     * @return mixed[]
     */
    public function sync(
        string $api_key,
        int    $timestamp,
        string $signature,
        mixed  $refunds
    ) {
        // Validate API credentials and HMAC signature
        $authResult = $this->apiAuthValidator->validate($api_key, $timestamp, $signature);
        if ($authResult['Error'] === true) {
            return $authResult;
        }

        // Validate refunds array
        if (empty($refunds) || !is_array($refunds)) {
            $this->logger->warning('[Dominate_ErpConnector] Refund sync failed: invalid_refunds');
            return ['Error' => true, 'ErrorCode' => 'invalid_refunds'];
        }

        $results = [];
        $errors  = [];

        // Mark this request as ERP-initiated refund sync to prevent webhook loops.
        $this->syncContext->startErpRefundSync();

        try {
            foreach ($refunds as $refundData) {
                if (!isset($refundData['order_increment_id']) || empty($refundData['order_increment_id'])) {
                    $errors[] = 'Missing order_increment_id in refund data';
                    continue;
                }

                $orderIncrementId = (string) $refundData['order_increment_id'];

                try {
                    // Load order by increment ID
                    $order = $this->getOrderByIncrementId($orderIncrementId);

                    // Check if order can be refunded
                    if (!$order->canCreditmemo()) {
                        $this->logger->info('[Dominate_ErpConnector] Order cannot be refunded, skipping', [
                            'order_increment_id' => $orderIncrementId,
                            'order_state' => $order->getState(),
                        ]);
                        $results[] = [
                            'order_increment_id' => $orderIncrementId,
                            'order_status' => $order->getStatus(),
                            'status' => 'skipped',
                            'reason' => 'Order cannot be refunded',
                        ];
                        continue;
                    }

                    // Build credit memo items from payload
                    $creditmemoItems = $this->buildCreditmemoItems($order, $refundData['items'] ?? null);

                    if (empty($creditmemoItems)) {
                        $this->logger->info('[Dominate_ErpConnector] No items to refund, skipping', [
                            'order_increment_id' => $orderIncrementId,
                        ]);
                        $results[] = [
                            'order_increment_id' => $orderIncrementId,
                            'order_status' => $order->getStatus(),
                            'status' => 'success',
                            'reason' => 'No items to refund (already refunded)',
                        ];
                        continue;
                    }

                    // Create refund using RefundOrderInterface
                    $creditmemoId = $this->refundOrder->execute(
                        (int) $order->getEntityId(),
                        $creditmemoItems,
                        false, // Don't notify customer by default (can be made configurable)
                        false, // Don't append comment
                        null,  // No comment
                        null   // No arguments
                    );

                    $results[] = [
                        'order_increment_id' => $orderIncrementId,
                        'order_status' => $order->getStatus(),
                        'status' => 'success',
                        'creditmemo_id' => $creditmemoId,
                    ];

                    $this->logger->info('[Dominate_ErpConnector] Refund created successfully', [
                        'order_increment_id' => $orderIncrementId,
                        'creditmemo_id' => $creditmemoId,
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
                    $this->logger->error('[Dominate_ErpConnector] Refund creation failed', [
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
            $this->logger->error('[Dominate_ErpConnector] Unexpected error during refund sync', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            // Always clear context even on exceptions to prevent state leakage.
            $this->syncContext->endErpRefundSync();
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
     * Build credit memo items from payload or refund all available items.
     *
     * @param OrderInterface $order
     * @param array|null      $itemsData Optional array of items with keys: sku, qty
     * @return array Array of CreditmemoItemCreationInterface objects
     */
    private function buildCreditmemoItems(OrderInterface $order, ?array $itemsData = null): array
    {
        $creditmemoItems = [];

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

                if (!$orderItem || $orderItem->getQtyToRefund() <= 0) {
                    continue;
                }

                // Clamp requested qty to available qty_to_refund
                $maxQtyToRefund = (float) $orderItem->getQtyToRefund();
                $requestedQty = (float) $itemData['qty'];
                $qtyToRefund = min($requestedQty, $maxQtyToRefund);

                if ($qtyToRefund <= 0) {
                    continue;
                }

                // Create credit memo item
                $creditmemoItem = $this->creditmemoItemFactory->create();
                $creditmemoItem->setOrderItemId($orderItem->getItemId());
                $creditmemoItem->setQty($qtyToRefund);
                $creditmemoItems[] = $creditmemoItem;
            }
        } else {
            // If no items specified, refund all available items
            foreach ($order->getAllItems() as $orderItem) {
                if ($orderItem->getQtyToRefund() > 0 && !$orderItem->getIsVirtual()) {
                    $creditmemoItem = $this->creditmemoItemFactory->create();
                    $creditmemoItem->setOrderItemId($orderItem->getItemId());
                    $creditmemoItem->setQty($orderItem->getQtyToRefund());
                    $creditmemoItems[] = $creditmemoItem;
                }
            }
        }

        return $creditmemoItems;
    }
}

