<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\ActivityLog\ChangeDetector;
use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;

/**
 * 주문 관련 활동 로그 리스너
 *
 * 주문/주문옵션/주문취소 서비스에서 발행하는 훅을 구독하여
 * Log::channel('activity')를 통해 활동 로그를 기록합니다.
 *
 * Monolog 기반 아키텍처:
 * Service → doAction → OrderActivityLogListener → Log::channel('activity') → ActivityLogHandler → DB
 */
class OrderActivityLogListener implements HookListenerInterface
{
    use ResolvesActivityLogType;

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // ─── OrderService ───
            'sirsoft-ecommerce.order.after_update' => ['method' => 'handleOrderAfterUpdate', 'priority' => 20],
            'sirsoft-ecommerce.order.after_delete' => ['method' => 'handleOrderAfterDelete', 'priority' => 20],
            'sirsoft-ecommerce.order.after_bulk_update' => ['method' => 'handleOrderAfterBulkUpdate', 'priority' => 20],
            'sirsoft-ecommerce.order.after_bulk_status_update' => ['method' => 'handleOrderAfterBulkStatusUpdate', 'priority' => 20],
            'sirsoft-ecommerce.order.after_bulk_shipping_update' => ['method' => 'handleOrderAfterBulkShippingUpdate', 'priority' => 20],
            'sirsoft-ecommerce.order.after_update_shipping_address' => ['method' => 'handleOrderAfterUpdateShippingAddress', 'priority' => 20],
            'sirsoft-ecommerce.order.after_send_email' => ['method' => 'handleOrderAfterSendEmail', 'priority' => 20],

            // ─── OrderOptionService ───
            'sirsoft-ecommerce.order_option.after_status_change' => ['method' => 'handleOrderOptionAfterStatusChange', 'priority' => 20],
            'sirsoft-ecommerce.order_option.after_bulk_status_change' => ['method' => 'handleOrderOptionAfterBulkStatusChange', 'priority' => 20],

            // ─── OrderCancellationService ───
            'sirsoft-ecommerce.order.after_cancel' => ['method' => 'handleOrderAfterCancel', 'priority' => 20],
            'sirsoft-ecommerce.order.after_partial_cancel' => ['method' => 'handleOrderAfterPartialCancel', 'priority' => 20],
            'sirsoft-ecommerce.coupon.restore' => ['method' => 'handleCouponRestore', 'priority' => 20],
            'sirsoft-ecommerce.mileage.restore' => ['method' => 'handleMileageRestore', 'priority' => 20],

            // ─── OrderService (구매확인) ───
            'sirsoft-ecommerce.order-option.after_confirm' => ['method' => 'handleOrderOptionAfterConfirm', 'priority' => 20],

            // ─── OrderProcessingService ───
            'sirsoft-ecommerce.order.after_create' => ['method' => 'handleOrderAfterCreate', 'priority' => 20],
            'sirsoft-ecommerce.order.after_payment_complete' => ['method' => 'handleOrderAfterPaymentComplete', 'priority' => 20],
            'sirsoft-ecommerce.order.payment_failed' => ['method' => 'handleOrderAfterPaymentFailed', 'priority' => 20],
            'sirsoft-ecommerce.coupon.use' => ['method' => 'handleCouponUse', 'priority' => 20],
            'sirsoft-ecommerce.mileage.use' => ['method' => 'handleMileageUse', 'priority' => 20],
            'sirsoft-ecommerce.mileage.earn' => ['method' => 'handleMileageEarn', 'priority' => 20],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param mixed ...$args 훅에서 전달된 인수들
     */
    public function handle(...$args): void
    {
        // 기본 핸들러는 사용하지 않음
    }

    // ═══════════════════════════════════════════
    // OrderService 핸들러
    // ═══════════════════════════════════════════

    /**
     * 주문 수정 후 로그 기록
     *
     * @param Order $order 수정된 주문
     * @param array|null $snapshot 수정 전 스냅샷 (Service에서 전달)
     */
    public function handleOrderAfterUpdate(Order $order, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($order, $snapshot);

        $this->logActivity('order.update', [

            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_update',
            'description_params' => ['order_number' => $order->order_number],
            'changes' => $changes,
        ]);
    }

    /**
     * 주문 삭제 후 로그 기록
     *
     * @param Order $order 삭제된 주문
     */
    public function handleOrderAfterDelete(Order $order): void
    {
        $this->logActivity('order.delete', [

            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_delete',
            'description_params' => ['order_number' => $order->order_number],
            'properties' => [
                'order_number' => $order->order_number,
                'total_amount' => $order->total_amount ?? null,
            ],
        ]);
    }

    /**
     * 주문 일괄 수정 후 per-item 로그 기록
     *
     * @param array $ids 대상 주문 ID 목록
     * @param int $updatedCount 수정된 수
     * @param array $snapshots 수정 전 스냅샷 (Service에서 전달, id => snapshot)
     */
    public function handleOrderAfterBulkUpdate(array $ids, int $updatedCount, array $snapshots = []): void
    {
        $orders = Order::whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            $order = $orders->get($id);
            if (! $order) {
                continue;
            }

            $snapshot = $snapshots[$id] ?? null;
            $changes = $snapshot ? ChangeDetector::detect($order, $snapshot) : null;

            $this->logActivity('order.bulk_update', [
                'loggable' => $order,
                'description_key' => 'sirsoft-ecommerce::activity_log.description.order_bulk_update',
                'description_params' => ['order_id' => $id, 'count' => $updatedCount],
                'properties' => ['order_id' => $id, 'order_number' => $order->order_number],
                'changes' => $changes,
            ]);
        }
    }

    /**
     * 주문 일괄 상태 변경 후 per-item 로그 기록
     *
     * @param array $ids 대상 주문 ID 목록
     * @param int $updatedCount 변경된 수
     * @param array $snapshots 변경 전 스냅샷 (Service에서 전달, id => snapshot)
     */
    public function handleOrderAfterBulkStatusUpdate(array $ids, int $updatedCount, array $snapshots = []): void
    {
        $orders = Order::whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            $order = $orders->get($id);
            if (! $order) {
                continue;
            }

            $snapshot = $snapshots[$id] ?? null;
            $changes = $snapshot ? ChangeDetector::detect($order, $snapshot) : null;

            $this->logActivity('order.bulk_status_update', [
                'loggable' => $order,
                'description_key' => 'sirsoft-ecommerce::activity_log.description.order_bulk_status_update',
                'description_params' => ['order_id' => $id, 'count' => $updatedCount],
                'properties' => ['order_id' => $id, 'order_number' => $order->order_number],
                'changes' => $changes,
            ]);
        }
    }

    /**
     * 주문 일괄 배송 정보 변경 후 per-item 로그 기록
     *
     * @param array $ids 대상 주문 ID 목록
     * @param int $updatedCount 변경된 수
     * @param array $snapshots 변경 전 스냅샷 (Service에서 전달, id => snapshot)
     */
    public function handleOrderAfterBulkShippingUpdate(array $ids, int $updatedCount, array $snapshots = []): void
    {
        $orders = Order::whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            $order = $orders->get($id);
            if (! $order) {
                continue;
            }

            $snapshot = $snapshots[$id] ?? null;
            $changes = $snapshot ? ChangeDetector::detect($order, $snapshot) : null;

            $this->logActivity('order.bulk_shipping_update', [
                'loggable' => $order,
                'description_key' => 'sirsoft-ecommerce::activity_log.description.order_bulk_shipping_update',
                'description_params' => ['order_id' => $id, 'count' => $updatedCount],
                'properties' => ['order_id' => $id, 'order_number' => $order->order_number],
                'changes' => $changes,
            ]);
        }
    }

    /**
     * 주문 배송지 수정 후 로그 기록
     *
     * loggable를 OrderAddress로 설정하여 배송지 레벨에서 변경 추적합니다.
     * 주문 상세 화면에서는 OR 조건으로 OrderAddress 로그도 함께 조회됩니다.
     *
     * @param Order $order 수정된 주문
     * @param OrderAddress|null $address 수정된 배송지
     * @param array|null $snapshot 수정 전 배송지 스냅샷
     */
    public function handleOrderAfterUpdateShippingAddress(Order $order, ?OrderAddress $address = null, ?array $snapshot = null): void
    {
        $changes = $address ? ChangeDetector::detect($address, $snapshot) : null;

        $this->logActivity('order.update_shipping_address', [
            'loggable' => $address ?? $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_update_shipping_address',
            'description_params' => ['order_number' => $order->order_number],
            'properties' => ['order_id' => $order->id, 'order_number' => $order->order_number],
            'changes' => $changes,
        ]);
    }

    /**
     * 주문 이메일 발송 후 로그 기록
     *
     * @param array $data 이메일 발송 데이터
     */
    public function handleOrderAfterSendEmail(array $data): void
    {
        $this->logActivity('order.send_email', [

            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_send_email',
            'description_params' => ['order_number' => $data['order_number'] ?? ''],
            'properties' => [
                'order_id' => $data['order_id'] ?? null,
                'template' => $data['template'] ?? null,
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    // OrderOptionService 핸들러
    // ═══════════════════════════════════════════

    /**
     * 주문 옵션 상태 변경 후 로그 기록
     *
     * @param OrderOption $original 원본 주문 옵션
     * @param OrderStatusEnum $newStatus 변경된 상태
     * @param OrderOption|null $split 분할된 주문 옵션
     */
    public function handleOrderOptionAfterStatusChange(OrderOption $original, OrderStatusEnum $newStatus, ?OrderOption $split): void
    {
        $this->logActivity('order_option.status_change', [

            'loggable' => $original,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_option_status_change',
            'description_params' => [
                'order_number' => $original->order?->order_number ?? '',
            ],
            'properties' => [
                'new_status' => $newStatus->value,
                'split' => $split,
            ],
        ]);
    }

    /**
     * 주문 옵션 일괄 상태 변경 후 per-item 로그 기록
     *
     * 케이스별 loggable 대상:
     * - 전체 수량 변경: 원본 옵션 (상태가 직접 변경됨)
     * - 부분 수량 분할: 분할 생성된 옵션 (새 상태를 가진 엔티티)
     * - 분할 후 병합: 병합 대상 옵션 (분할 옵션이 흡수됨)
     *
     * @param array $results 변경 결과 목록
     * @param OrderStatusEnum $newStatus 변경된 상태
     * @param array $snapshots 변경 전 스냅샷 (Service에서 전달, order_option_id => snapshot)
     */
    public function handleOrderOptionAfterBulkStatusChange(array $results, OrderStatusEnum $newStatus, array $snapshots = []): void
    {
        // 로그 대상 ID 수집: 실제 상태가 변경된 엔티티를 loggable로 지정
        $targetIds = [];
        foreach ($results as $result) {
            if (! empty($result['merged_into_order_option_id'])) {
                $targetIds[] = $result['merged_into_order_option_id'];
            } elseif (! empty($result['split_order_option_id'])) {
                $targetIds[] = $result['split_order_option_id'];
            } else {
                $targetIds[] = $result['order_option_id'];
            }
        }
        $targetIds = array_unique(array_filter($targetIds));
        $options = OrderOption::whereIn('id', $targetIds)->get()->keyBy('id');

        foreach ($results as $result) {
            $originalId = $result['order_option_id'] ?? null;

            // 실제 상태가 변경된 대상 결정
            if (! empty($result['merged_into_order_option_id'])) {
                $targetId = $result['merged_into_order_option_id'];
            } elseif (! empty($result['split_order_option_id'])) {
                $targetId = $result['split_order_option_id'];
            } else {
                $targetId = $originalId;
            }

            $option = $options->get($targetId);
            if (! $option) {
                continue;
            }

            $snapshot = $snapshots[$originalId] ?? null;
            $changes = $snapshot ? ChangeDetector::detect($option, $snapshot) : null;

            $properties = [
                'order_option_id' => $targetId,
                'order_id' => $option->order_id,
                'new_status' => $newStatus->value,
            ];

            // 분할/병합 시 원본 ID와 유형을 추가 기록
            if ($targetId !== $originalId) {
                $properties['original_order_option_id'] = $originalId;
                $properties['change_type'] = ! empty($result['merged_into_order_option_id']) ? 'merged' : 'split';
                $properties['quantity_changed'] = $result['quantity_changed'] ?? null;
            }

            $this->logActivity('order_option.bulk_status_change', [
                'loggable' => $option,
                'description_key' => 'sirsoft-ecommerce::activity_log.description.order_option_bulk_status_change',
                'description_params' => ['order_option_id' => $targetId, 'count' => count($results)],
                'properties' => $properties,
                'changes' => $changes,
            ]);
        }
    }

    /**
     * 주문 옵션 구매확인 후 로그 기록
     *
     * @param Order $order 주문
     * @param OrderOption $option 확인된 주문 옵션
     */
    public function handleOrderOptionAfterConfirm(Order $order, OrderOption $option): void
    {
        $this->logActivity('order_option.confirm', [
            'loggable' => $option,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_option_confirm',
            'description_params' => ['option_id' => $option->id],
            'properties' => [
                'order_id' => $order->id,
                'option_id' => $option->id,
                'order_number' => $order->order_number,
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    // OrderCancellationService 핸들러
    // ═══════════════════════════════════════════

    /**
     * 주문 전체 취소 후 로그 기록
     *
     * @param Order $order 취소된 주문 (fresh)
     * @param array|null $cancelSnapshot 취소 스냅샷 (Service에서 전달: cancel_type, cancel_items)
     */
    public function handleOrderAfterCancel(Order $order, ?array $cancelSnapshot = null): void
    {
        $this->logActivity('order.cancel', [

            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_cancel',
            'description_params' => ['order_number' => $order->order_number],
            'properties' => [
                'cancel_type' => $cancelSnapshot['cancel_type'] ?? null,
                'cancel_items' => $cancelSnapshot['cancel_items'] ?? [],
            ],
        ]);
    }

    /**
     * 주문 부분 취소 후 로그 기록
     *
     * Order 레벨 로그 + 취소된 각 OrderOption에 대한 per-item 로그를 기록합니다.
     *
     * @param Order $order 부분 취소된 주문 (fresh)
     * @param array|null $cancelSnapshot 취소 스냅샷 (Service에서 전달: cancel_type, cancel_items)
     */
    public function handleOrderAfterPartialCancel(Order $order, ?array $cancelSnapshot = null): void
    {
        // Order 레벨 로그 (합산액 변경 기록)
        $this->logActivity('order.partial_cancel', [
            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_partial_cancel',
            'description_params' => ['order_number' => $order->order_number],
            'properties' => [
                'cancel_type' => $cancelSnapshot['cancel_type'] ?? null,
                'cancel_items' => $cancelSnapshot['cancel_items'] ?? [],
            ],
        ]);

        // 취소된 각 OrderOption에 대한 per-item 로그
        $cancelItems = $cancelSnapshot['cancel_items'] ?? [];
        if (! empty($cancelItems)) {
            $optionIds = array_column($cancelItems, 'order_option_id');
            $options = OrderOption::whereIn('id', $optionIds)->get()->keyBy('id');

            foreach ($cancelItems as $item) {
                $optionId = $item['order_option_id'] ?? null;
                $option = $optionId ? $options->get($optionId) : null;
                if (! $option) {
                    continue;
                }

                $this->logActivity('order_option.partial_cancel', [
                    'loggable' => $option,
                    'description_key' => 'sirsoft-ecommerce::activity_log.description.order_option_partial_cancel',
                    'description_params' => ['option_id' => $optionId],
                    'properties' => [
                        'order_id' => $order->id,
                        'option_id' => $optionId,
                        'cancel_quantity' => $item['cancel_quantity'] ?? null,
                    ],
                ]);
            }
        }
    }

    /**
     * 쿠폰 복원 후 로그 기록
     *
     * @param Order $order 관련 주문
     * @param array $restoredCouponIssueIds 복원된 쿠폰 발급 ID 목록
     */
    public function handleCouponRestore(Order $order, array $restoredCouponIssueIds): void
    {
        $this->logActivity('coupon.restore', [

            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.coupon_restore',
            'description_params' => ['order_number' => $order->order_number],
            'properties' => [
                'restored_coupon_issue_ids' => $restoredCouponIssueIds,
            ],
        ]);
    }

    /**
     * 마일리지 복원 후 로그 기록
     *
     * @param float $amount 복원 금액
     * @param Order $order 관련 주문
     */
    public function handleMileageRestore(float $amount, Order $order): void
    {
        $this->logActivity('mileage.restore', [

            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.mileage_restore',
            'description_params' => [
                'order_number' => $order->order_number,
                'amount' => $amount,
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    // OrderProcessingService 핸들러
    // ═══════════════════════════════════════════

    /**
     * 주문 생성 후 로그 기록
     *
     * @param Order $order 생성된 주문
     */
    public function handleOrderAfterCreate(Order $order): void
    {
        $this->logActivity('order.create', [
            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_create',
            'description_params' => ['order_number' => $order->order_number],
            'properties' => [
                'order_number' => $order->order_number,
                'total_amount' => $order->total_amount ?? null,
            ],
        ]);
    }

    /**
     * 결제 완료 후 로그 기록
     *
     * @param Order $order 결제 완료된 주문
     */
    public function handleOrderAfterPaymentComplete(Order $order): void
    {
        $this->logActivity('order.payment_complete', [
            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_payment_complete',
            'description_params' => ['order_number' => $order->order_number],
        ]);
    }

    /**
     * 결제 실패 후 로그 기록
     *
     * @param Order $order 결제 실패 주문
     * @param string $errorCode 에러 코드
     * @param string $errorMessage 에러 메시지
     */
    public function handleOrderAfterPaymentFailed(Order $order, string $errorCode, string $errorMessage): void
    {
        $this->logActivity('order.payment_failed', [
            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_payment_failed',
            'description_params' => ['order_number' => $order->order_number],
            'properties' => [
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ],
        ]);
    }

    /**
     * 쿠폰 사용 후 로그 기록
     *
     * @param array $appliedCouponIds 적용된 쿠폰 ID 목록
     * @param Order $order 관련 주문
     */
    public function handleCouponUse(array $appliedCouponIds, Order $order): void
    {
        $this->logActivity('coupon.use', [
            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.coupon_use',
            'description_params' => ['order_number' => $order->order_number],
            'properties' => [
                'applied_coupon_ids' => $appliedCouponIds,
            ],
        ]);
    }

    /**
     * 마일리지 사용 후 로그 기록
     *
     * @param float $usedPoints 사용된 마일리지
     * @param Order $order 관련 주문
     */
    public function handleMileageUse(float $usedPoints, Order $order): void
    {
        $this->logActivity('mileage.use', [
            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.mileage_use',
            'description_params' => [
                'order_number' => $order->order_number,
                'amount' => $usedPoints,
            ],
        ]);
    }

    /**
     * 마일리지 적립 후 로그 기록
     *
     * @param float $earnedPoints 적립된 마일리지
     * @param Order $order 관련 주문
     */
    public function handleMileageEarn(float $earnedPoints, Order $order): void
    {
        $this->logActivity('mileage.earn', [
            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.mileage_earn',
            'description_params' => [
                'order_number' => $order->order_number,
                'amount' => $earnedPoints,
            ],
        ]);
    }

}
