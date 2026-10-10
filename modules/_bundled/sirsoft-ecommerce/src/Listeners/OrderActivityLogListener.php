<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\ActivityLog\ChangeDetector;
use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\Concerns\BuildsEnumFieldChange;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;

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
    use BuildsEnumFieldChange;
    use ResolvesActivityLogType;

    /**
     * @param  OrderRepositoryInterface  $orderRepository  주문 bulk lookup
     * @param  OrderOptionRepositoryInterface  $orderOptionRepository  옵션 bulk lookup
     */
    public function __construct(
        protected OrderRepositoryInterface $orderRepository,
        protected OrderOptionRepositoryInterface $orderOptionRepository,
    ) {}

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
            'sirsoft-ecommerce.order.after_reset_guest_password' => ['method' => 'handleOrderAfterResetGuestPassword', 'priority' => 20],

            // ─── OrderOptionService ───
            'sirsoft-ecommerce.order_option.after_status_change' => ['method' => 'handleOrderOptionAfterStatusChange', 'priority' => 20],
            // 상품 상태에 맞춰 부모 주문 상태가 자동으로 따라 바뀐 전이 — 운영자가 고른 것이
            // 아니라 파생된 결과이므로 별도 줄로 남긴다.
            'sirsoft-ecommerce.order.after_status_sync' => ['method' => 'handleOrderStatusSync', 'priority' => 20],
            // 수동 결제 처리 확인 창의 세 항목·재활성 복원 결과 (Q13·Q14·Q26) — 끈 항목까지 남긴다.
            'sirsoft-ecommerce.order.after_manual_paid_effects' => ['method' => 'handleOrderManualPaidEffects', 'priority' => 20],
            'sirsoft-ecommerce.order_option.after_bulk_status_change' => ['method' => 'handleOrderOptionAfterBulkStatusChange', 'priority' => 20],
            // 주문 상태 동기화가 끌고 간 상품 전이 — 관계 빌더 raw update 라
            // `order_option.after_status_change` 를 지나지 않는다(A1~A5).
            'sirsoft-ecommerce.order_option.after_sync_status' => ['method' => 'handleOrderOptionAfterSyncStatus', 'priority' => 20],
            // 송장 기록·정정 — 전이를 동반하지 않는 정정은 어떤 전이 훅도 지나지 않는다(F11).
            'sirsoft-ecommerce.order_shipping.tracking_updated' => ['method' => 'handleOrderShippingTrackingUpdated', 'priority' => 20],
            // 취소가 세금계산서에 반응하지 않는다는 사실을 이력으로 드러낸다(P15).
            'sirsoft-ecommerce.order.tax_invoice_stale_after_cancel' => ['method' => 'handleTaxInvoiceStaleAfterCancel', 'priority' => 20],

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
            'sirsoft-ecommerce.order.after_deposit_recorded' => ['method' => 'handleOrderAfterDepositRecorded', 'priority' => 20],
            'sirsoft-ecommerce.order.payment_failed' => ['method' => 'handleOrderAfterPaymentFailed', 'priority' => 20],
            'sirsoft-ecommerce.coupon.use' => ['method' => 'handleCouponUse', 'priority' => 20],
            'sirsoft-ecommerce.mileage.use' => ['method' => 'handleMileageUse', 'priority' => 20],
            'sirsoft-ecommerce.mileage.earn' => ['method' => 'handleMileageEarn', 'priority' => 20],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     * @return void 반환값 없음
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
     * @param  Order  $order  수정된 주문
     * @param  array|null  $snapshot  수정 전 스냅샷 (Service에서 전달)
     * @return void 반환값 없음
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
     * 수동 결제 처리(세 항목·재활성 복원) 결과를 기록합니다. (Q13·Q14·Q26)
     *
     * 운영자가 끈 항목(`skipped`)을 남겨, 나중에 「왜 재고·마일리지가 빠지지 않았는지」를 로그에서 읽을 수
     * 있게 한다. 재활성 복원이 있었으면 합계 전후를 변경 내역으로 싣는다.
     *
     * @param  Order  $order  주문
     * @param  array<string, mixed>  $report  적용 결과 (`ManualPaidEffectsReport::toArray()`)
     * @param  array|null  $snapshotBefore  복원 전 주문 스냅샷 (복원이 없으면 null)
     * @return void 반환값 없음
     */
    public function handleOrderManualPaidEffects(Order $order, array $report, ?array $snapshotBefore = null): void
    {
        $this->logActivity('order.manual_paid_effects', [
            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_manual_paid_effects',
            'description_params' => ['order_number' => $order->order_number ?? '', 'applied' => '', 'skipped' => ''],
            'properties' => [
                'trigger' => $report['trigger'] ?? null,
                'transition' => $report['transition'] ?? [],
                'options' => $report['options'] ?? [],
                'items' => $report['items'] ?? [],
                'applied' => $report['applied'] ?? [],
                'skipped' => $report['skipped'] ?? [],
                'warnings' => $report['warnings'] ?? [],
            ],
            'changes' => $snapshotBefore !== null ? ChangeDetector::detect($order, $snapshotBefore) : null,
        ]);
    }

    /**
     * 주문 삭제 후 로그 기록
     *
     * @param  Order  $order  삭제된 주문
     * @return void 반환값 없음
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
     * @param  array  $ids  대상 주문 ID 목록
     * @param  int  $updatedCount  수정된 수
     * @param  array  $snapshots  수정 전 스냅샷 (Service에서 전달, id => snapshot)
     * @return void 반환값 없음
     */
    public function handleOrderAfterBulkUpdate(array $ids, int $updatedCount, array $snapshots = []): void
    {
        $orders = $this->orderRepository->findByIdsKeyed($ids);

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
     * @param  array  $ids  대상 주문 ID 목록
     * @param  int  $updatedCount  변경된 수
     * @param  array  $snapshots  변경 전 스냅샷 (Service에서 전달, id => snapshot)
     * @return void 반환값 없음
     */
    public function handleOrderAfterBulkStatusUpdate(array $ids, int $updatedCount, array $snapshots = []): void
    {
        $orders = $this->orderRepository->findByIdsKeyed($ids);

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
     * @param  array  $ids  대상 주문 ID 목록
     * @param  int  $updatedCount  변경된 수
     * @param  array  $snapshots  변경 전 스냅샷 (Service에서 전달, id => snapshot)
     * @return void 반환값 없음
     */
    public function handleOrderAfterBulkShippingUpdate(array $ids, int $updatedCount, array $snapshots = []): void
    {
        $orders = $this->orderRepository->findByIdsKeyed($ids);

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
     * @param  Order  $order  수정된 주문
     * @param  OrderAddress|null  $address  수정된 배송지
     * @param  array|null  $snapshot  수정 전 배송지 스냅샷
     * @return void 반환값 없음
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
     * 발행부(OrderService::sendEmail)의 페이로드 키는 camelCase(`orderNumber`·`orderId`)다.
     *
     * @param  array  $data  이메일 발송 데이터
     * @return void 반환값 없음
     */
    public function handleOrderAfterSendEmail(array $data): void
    {
        $this->logActivity('order.send_email', [

            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_send_email',
            'description_params' => ['order_number' => $data['orderNumber'] ?? ''],
            'properties' => [
                'order_id' => $data['orderId'] ?? null,
                'template' => $data['template'] ?? null,
            ],
        ]);
    }

    /**
     * 비회원 조회 비밀번호 재설정 후 로그 기록
     *
     * 평문 비밀번호는 로그에 기록하지 않고 주문번호만 남깁니다.
     *
     * @param  Order  $order  재설정된 주문
     * @return void 반환값 없음
     */
    public function handleOrderAfterResetGuestPassword(Order $order): void
    {
        $this->logActivity('order.reset_guest_password', [

            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_reset_guest_password',
            'description_params' => ['order_number' => $order->order_number],
            'properties' => [
                'order_number' => $order->order_number,
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    // OrderOptionService 핸들러
    // ═══════════════════════════════════════════

    /**
     * 주문 옵션 상태 변경 후 로그 기록
     *
     * 전/후는 서비스가 변경 전에 떠 둔 스냅샷으로 만든다 — 스냅샷 없이 "변경 후" 만 적으면
     * 활동 로그 화면에 전/후 표가 서지 않아 무엇에서 무엇으로 바뀌었는지 읽을 수 없다.
     * 분할 전이면 새 상태를 가진 쪽은 분할 레코드이므로 그쪽과 대조한다.
     *
     * @param  OrderOption  $original  원본 주문 옵션
     * @param  OrderStatusEnum  $newStatus  변경된 상태
     * @param  OrderOption|null  $split  분할된 주문 옵션
     * @param  array|null  $snapshot  변경 전 스냅샷 (Service 에서 전달, 없으면 전/후 미기록)
     * @return void 반환값 없음
     */
    public function handleOrderOptionAfterStatusChange(
        OrderOption $original,
        OrderStatusEnum $newStatus,
        ?OrderOption $split,
        ?array $snapshot = null,
    ): void {
        $changes = $snapshot !== null ? ChangeDetector::detect($split ?? $original, $snapshot) : null;

        $this->logActivity('order_option.status_change', [

            'loggable' => $original,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_option_status_change',
            'description_params' => [
                'order_number' => $original->order?->order_number ?? '',
            ],
            'properties' => [
                'previous_status' => $snapshot['option_status'] ?? null,
                'new_status' => $newStatus->value,
                'split' => $split,
            ],
            'changes' => $changes,
        ]);
    }

    /**
     * 상품 상태에 맞춰 부모 주문 상태가 자동으로 따라 바뀐 전이를 기록합니다.
     *
     * 상품 단건·일괄 상태 변경, 구매확정으로 모든 상품이 같은 단계에 모이면 주문 상태가
     * 파생 전이한다. 운영자가 고른 전이가 아니라 이력이 없으면 "주문 상태가 왜 바뀌었는가"
     * 를 되짚을 통로가 상품 줄뿐이다 — 상품 줄과 주문 줄이 함께 남아야 원인과 결과가 읽힌다.
     *
     * @param  Order  $order  전이된 주문
     * @param  string|null  $previousStatus  전이 전 주문 상태 값
     * @param  string|null  $newStatus  전이 시점에 캡처한 목표 상태 값
     * @return void 반환값 없음
     */
    public function handleOrderStatusSync(Order $order, ?string $previousStatus = null, ?string $newStatus = null): void
    {
        // 목표 상태는 전이 시점 캡처값이 기준이다 — 큐 지연 중 다시 바뀐 "지금" 값을 적으면
        // 실제로 일어난 전이와 어긋난다.
        $new = $newStatus ?? ($order->order_status instanceof OrderStatusEnum ? $order->order_status->value : $order->order_status);

        if ($previousStatus === $new) {
            return;
        }

        $this->logActivity('order.status_sync', [
            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_status_sync',
            'description_params' => [
                'order_number' => $order->order_number ?? '',
            ],
            'properties' => [
                'previous_status' => $previousStatus,
                'new_status' => $new,
                'trigger' => 'option_status',
            ],
            'changes' => [
                $this->enumFieldChange(
                    'order_status',
                    'sirsoft-ecommerce::activity_log.fields.order_status',
                    OrderStatusEnum::class,
                    $previousStatus,
                    $new,
                ),
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
     * @param  array  $results  변경 결과 목록
     * @param  OrderStatusEnum  $newStatus  변경된 상태
     * @param  array  $snapshots  변경 전 스냅샷 (Service에서 전달, order_option_id => snapshot)
     * @return void 반환값 없음
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
        $options = $this->orderOptionRepository->findByIdsKeyed($targetIds);

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
     * 주문 상태 동기화가 끌고 간 주문상품 전이를 기록합니다. (#599 후속 §7-1 A1~A5)
     *
     * 결제완료·0원 주문 확정·결제실패·관리자 주문 수정·일괄 상태변경은 주문상품 상태를
     * 관계 빌더의 raw update 로 한 번에 옮긴다 — `OrderOptionService` 의 분할·훅 경로를
     * 지나지 않으므로 **어떤 줄도 남지 않았다**. 주문 헤더 줄만 보고는 어느 상품이 함께
     * 움직였는지 알 수 없어, 부분 배송·부분 취소로 상태가 섞인 주문에서 특히 문제가 된다.
     *
     * 상품 수가 많을 수 있으므로 주문당 **한 줄**로 묶고 상품별 전/후는 코어 일괄 형식
     * (`{모델ID: [항목…]}`)의 `changes` 에 싣는다 — 화면이 상품마다 표를 그린다.
     *
     * @param  array<int, array{order_option_id: int, order_id: int, previous: string, new: string}>  $changes  상품별 전이 내역
     * @param  string  $newStatus  목표 상태 값
     * @param  string  $source  전이를 일으킨 경로 (`order_status_sync` | `bulk_update`)
     * @return void 반환값 없음
     */
    public function handleOrderOptionAfterSyncStatus(array $changes, string $newStatus, string $source = ''): void
    {
        if ($changes === []) {
            return;
        }

        $byOrder = [];

        foreach ($changes as $change) {
            $byOrder[(int) ($change['order_id'] ?? 0)][] = $change;
        }

        // 주문은 한 번에 읽는다 — 일괄 상태변경은 주문 수십 건을 한 번에 옮기므로 주문마다 조회하면
        // 그 수만큼 쿼리가 늘어난다(N+1).
        $orders = $this->orderRepository->findByIdsKeyed(array_keys($byOrder));

        foreach ($byOrder as $orderId => $rows) {
            $order = $orders->get($orderId);

            if (! $order) {
                continue;
            }

            $bulkChanges = [];

            foreach ($rows as $row) {
                $bulkChanges[(int) $row['order_option_id']] = [
                    $this->enumFieldChange(
                        'option_status',
                        'sirsoft-ecommerce::activity_log.fields.option_status',
                        OrderStatusEnum::class,
                        $row['previous'] ?? null,
                        $row['new'] ?? null,
                    ),
                ];
            }

            $this->logActivity('order_option.sync_status', [
                'loggable' => $order,
                'description_key' => 'sirsoft-ecommerce::activity_log.description.order_option_sync_status',
                'description_params' => [
                    'order_number' => (string) $order->order_number,
                    'count' => (string) count($rows),
                ],
                'properties' => [
                    'order_id' => $orderId,
                    'new_status' => $newStatus,
                    'source' => $source,
                    'options' => $rows,
                ],
                'changes' => $bulkChanges,
            ]);
        }
    }

    /**
     * 취소 뒤에도 남아 있는 발급 완료 세금계산서를 기록합니다. (#599 후속 P15)
     *
     * 세금계산서는 취소·부분취소에 반응하지 않는다 — 국세청 연동 자동 취소·재발행은 이번
     * 범위 밖이다(§12-A). 그 누락을 **드러내는** 것이 이 줄의 역할이다: 아무 표시도 없으면
     * 운영자는 계산서가 이미 정리된 줄 알고 넘어가고, 어긋남은 신고 시점에야 드러난다.
     *
     * @param  Order  $order  취소가 반영된 주문
     * @param  array<int, array{id: int, invoice_number: string|null, total_amount: float}>  $invoices  발급 완료 계산서
     * @return void 반환값 없음
     */
    public function handleTaxInvoiceStaleAfterCancel(Order $order, array $invoices = []): void
    {
        if ($invoices === []) {
            return;
        }

        $this->logActivity('order.tax_invoice_stale', [
            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_tax_invoice_stale',
            'description_params' => [
                'order_number' => (string) $order->order_number,
                'count' => (string) count($invoices),
            ],
            'properties' => [
                'order_id' => $order->id,
                'invoices' => $invoices,
                'total_cancelled_amount' => (float) $order->total_cancelled_amount,
            ],
        ]);
    }

    /**
     * 운송장 기록·정정을 기록합니다. (#599 후속 F11)
     *
     * 예전에는 상태 전이를 동반할 때만 훅이 발화해, **이미 배송중인 건의 송장 정정**은 어떤
     * 줄도 남기지 않았다. 고객이 "운송장이 바뀌었다" 고 물어도 언제 누가 무엇으로 고쳤는지
     * 되짚을 근거가 없었다. 전이와 층이 다른 사실이라 전이 줄에 얹지 않고 따로 남긴다.
     *
     * @param  OrderOption  $option  대상 주문상품
     * @param  array{carrier_id: int|string|null, tracking_number: string|null}  $before  변경 전
     * @param  array{carrier_id: int|string|null, tracking_number: string|null}  $after  변경 후
     * @return void 반환값 없음
     */
    public function handleOrderShippingTrackingUpdated(OrderOption $option, array $before = [], array $after = []): void
    {
        $changes = [];

        foreach (['carrier_id' => 'text', 'tracking_number' => 'text'] as $field => $type) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;

            if ((string) $old === (string) $new) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label_key' => 'sirsoft-ecommerce::activity_log.fields.'.$field,
                'old' => $old !== null ? (string) $old : null,
                'new' => $new !== null ? (string) $new : null,
                'type' => $type,
            ];
        }

        // 값이 그대로면 남기지 않는다 — 같은 값을 다시 저장한 회차까지 이력에 쌓으면
        // 정말 바뀐 회차를 찾기 어려워진다.
        if ($changes === []) {
            return;
        }

        $this->logActivity('order_shipping.tracking_update', [
            'loggable' => $option,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_shipping_tracking_update',
            'description_params' => ['option_id' => (string) $option->id],
            'properties' => [
                'order_id' => $option->order_id,
                'option_id' => $option->id,
            ],
            'changes' => $changes,
        ]);
    }

    /**
     * 주문 옵션 구매확인 후 로그 기록
     *
     * `changes` 를 싣는 이유: 활동 로그 화면은 여기에 실린 항목만 "변경 전 → 변경 후" 표로
     * 그린다(§7-1 D). 줄만 남기면 이 사건으로 무엇이 바뀌었는지를 로그에서 읽을 수 없다.
     *
     * @param  Order  $order  주문
     * @param  OrderOption  $option  확인된 주문 옵션
     * @param  array|null  $snapshot  확정 전 옵션 스냅샷
     * @return void 반환값 없음
     */
    public function handleOrderOptionAfterConfirm(Order $order, OrderOption $option, ?array $snapshot = null): void
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
            'changes' => ChangeDetector::detect($option, $snapshot),
        ]);
    }

    // ═══════════════════════════════════════════
    // OrderCancellationService 핸들러
    // ═══════════════════════════════════════════

    /**
     * 주문 전체 취소 후 로그 기록
     *
     *
     * `changes` 를 싣는 이유: 활동 로그 화면은 여기에 실린 항목만 "변경 전 → 변경 후" 표로
     * 그린다(§7-1 D). 줄만 남기면 이 사건으로 무엇이 바뀌었는지를 로그에서 읽을 수 없다.
     *
     * @param  Order  $order  취소된 주문 (fresh)
     * @param  array|null  $cancelSnapshot  취소 스냅샷 (Service에서 전달: cancel_type, cancel_items)
     * @param  array|null  $orderSnapshot  취소 전 주문 속성 스냅샷
     * @return void 반환값 없음
     */
    public function handleOrderAfterCancel(Order $order, ?array $cancelSnapshot = null, ?array $orderSnapshot = null): void
    {
        $this->logActivity('order.cancel', [

            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_cancel',
            'description_params' => ['order_number' => $order->order_number],
            'properties' => [
                'cancel_type' => $cancelSnapshot['cancel_type'] ?? null,
                'cancel_items' => $cancelSnapshot['cancel_items'] ?? [],
            ],
            'changes' => ChangeDetector::detect($order, $orderSnapshot),
        ]);
    }

    /**
     * 주문 부분 취소 후 로그 기록
     *
     * Order 레벨 로그 + 취소된 각 OrderOption에 대한 per-item 로그를 기록합니다.
     *
     * @param  Order  $order  부분 취소된 주문 (fresh)
     * @param  array|null  $cancelSnapshot  취소 스냅샷 (Service에서 전달: cancel_type, cancel_items)
     * @param  array|null  $orderSnapshot  변경 전 주문 속성 스냅샷 (활동로그의 전/후 표가 읽는다)
     * @return void 반환값 없음
     */
    public function handleOrderAfterPartialCancel(Order $order, ?array $cancelSnapshot = null, ?array $orderSnapshot = null): void
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
            'changes' => ChangeDetector::detect($order, $orderSnapshot),
        ]);

        // 취소된 각 OrderOption에 대한 per-item 로그
        $cancelItems = $cancelSnapshot['cancel_items'] ?? [];
        if (! empty($cancelItems)) {
            $optionIds = array_column($cancelItems, 'order_option_id');
            $options = $this->orderOptionRepository->findByIdsKeyed($optionIds);

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
     * @param  Order  $order  관련 주문
     * @param  array  $restoredCouponIssueIds  복원된 쿠폰 발급 ID 목록
     * @return void 반환값 없음
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
     * @param  float  $amount  복원 금액
     * @param  Order  $order  관련 주문
     * @return void 반환값 없음
     */
    public function handleMileageRestore(float $amount, Order $order): void
    {
        $this->logActivity('mileage.restore', [

            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.mileage_restore',
            'description_params' => [
                'order_number' => $order->order_number,
                'amount' => $this->formatOrderAmount($order, (float) $amount),
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    // OrderProcessingService 핸들러
    // ═══════════════════════════════════════════

    /**
     * 주문 생성 후 로그 기록
     *
     * @param  Order  $order  생성된 주문
     * @return void 반환값 없음
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
     *
     * `changes` 를 싣는 이유: 활동 로그 화면은 여기에 실린 항목만 "변경 전 → 변경 후" 표로
     * 그린다(§7-1 D). 줄만 남기면 이 사건으로 무엇이 바뀌었는지를 로그에서 읽을 수 없다.
     *
     * @param  Order  $order  결제 완료된 주문
     * @param  array|null  $snapshot  결제완료 전 주문 속성 스냅샷
     * @return void 반환값 없음
     */
    public function handleOrderAfterPaymentComplete(Order $order, ?array $snapshot = null): void
    {
        $this->logActivity('order.payment_complete', [
            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_payment_complete',
            'description_params' => ['order_number' => $order->order_number],
            'changes' => ChangeDetector::detect($order, $snapshot),
        ]);
    }

    /**
     * 입금 기록 후 로그 기록
     *
     * 관리자가 결제완료 전이 없이 입금만 기록한 경로다(결제 이후 단계 주문이면 주문 결제금액도 함께 채운다).
     * 결제 완료 경로와 달리 이 경로는 로그가 없어, 누가 언제 결제금액을 채웠는지 찾을 수 없었다.
     *
     * @param  Order  $order  입금이 기록된 주문
     * @param  float|int  $amount  입금액(결제 통화)
     * @param  array|null  $snapshot  기록 전 주문 속성 스냅샷
     * @return void 반환값 없음
     */
    public function handleOrderAfterDepositRecorded(Order $order, float|int $amount = 0, ?array $snapshot = null): void
    {
        $this->logActivity('order.deposit_record', [
            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_deposit_recorded',
            'description_params' => ['order_number' => $order->order_number],
            'properties' => ['order_number' => $order->order_number, 'amount' => (float) $amount],
            'changes' => ChangeDetector::detect($order, $snapshot),
        ]);
    }

    /**
     * 결제 실패 후 로그 기록
     *
     * @param  Order  $order  결제 실패 주문
     * @param  string  $errorCode  에러 코드
     * @param  string  $errorMessage  에러 메시지
     * @param  array|null  $snapshot  실패 처리 전 주문 속성 스냅샷
     * @return void 반환값 없음
     */
    public function handleOrderAfterPaymentFailed(Order $order, string $errorCode, string $errorMessage, ?array $snapshot = null): void
    {
        $this->logActivity('order.payment_failed', [
            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_payment_failed',
            'description_params' => ['order_number' => $order->order_number],
            'properties' => [
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ],
            'changes' => ChangeDetector::detect($order, $snapshot),
        ]);
    }

    /**
     * 쿠폰 사용 후 로그 기록
     *
     * @param  array  $appliedCouponIds  적용된 쿠폰 ID 목록
     * @param  Order  $order  관련 주문
     * @return void 반환값 없음
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
     * @param  float  $usedPoints  사용된 마일리지
     * @param  Order  $order  관련 주문
     * @return void 반환값 없음
     */
    public function handleMileageUse(float $usedPoints, Order $order): void
    {
        $this->logActivity('mileage.use', [
            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.mileage_use',
            'description_params' => [
                'order_number' => $order->order_number,
                'amount' => $this->formatOrderAmount($order, (float) $usedPoints),
            ],
        ]);
    }

    /**
     * 마일리지 적립 후 로그 기록
     *
     * @param  float  $earnedPoints  적립된 마일리지
     * @param  Order  $order  관련 주문
     * @return void 반환값 없음
     */
    public function handleMileageEarn(float $earnedPoints, Order $order): void
    {
        // 결제 완료 시점의 훅은 **예정**이다 — 마일리지는 적립 시점 설정(구매확정 등)에 맞춰 적립 배치가 지급하고,
        // 그때 `UserMileageService` 가 「마일리지 적립」을 따로 남긴다. 이 줄을 「적립」이라 쓰면 원장에 적립 행이
        // 없는 주문도 적립된 것처럼 읽힌다(S3-D21).
        $this->logActivity('mileage.earn', [
            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.mileage_earn_scheduled',
            'description_params' => [
                'order_number' => $order->order_number,
                'amount' => $this->formatOrderAmount($order, (float) $earnedPoints),
            ],
        ]);
    }

    /**
     * 주문 시점 기준 통화로 금액을 포맷합니다.
     *
     * 활동 로그 문구는 통화 단위를 담지 않는다 — 값과 단위를 함께 기록해야
     * 기본 통화가 원화가 아닌 상점에서도 로그를 그대로 읽을 수 있다.
     *
     * @param  Order  $order  대상 주문
     * @param  float  $amount  기준 통화 금액
     * @return string 주문 통화로 포맷된 금액
     */
    private function formatOrderAmount(Order $order, float $amount): string
    {
        return ecommerce_format_price($amount, $order->currency_snapshot['base_currency'] ?? null);
    }
}
