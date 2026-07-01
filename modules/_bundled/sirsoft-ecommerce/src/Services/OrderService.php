<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Modules\Sirsoft\Ecommerce\Enums\DeliveryMemoPresetEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\InsufficientStockException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderModificationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderProcessingException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\UserAddressRepositoryInterface;

/**
 * 주문 서비스
 */
class OrderService
{
    public function __construct(
        protected OrderRepositoryInterface $repository,
        protected UserAddressRepositoryInterface $userAddressRepository,
        protected StockService $stockService
    ) {}

    /**
     * 취소 → 판매 상태 복원 전이인지 판정합니다.
     *
     * oldStatus 가 취소상태(cancelled/partial_cancelled)이고 newStatus 가
     * 판매 반영 상태(payment_complete~confirmed)일 때만 재고 재차감 대상이다.
     *
     * @param  string|null  $oldStatus  변경 전 주문 상태 값
     * @param  string|null  $newStatus  변경 후 주문 상태 값
     * @return bool 재차감 대상 전이 여부
     */
    protected function isReactivationTransition(?string $oldStatus, ?string $newStatus): bool
    {
        if ($oldStatus === null || $newStatus === null) {
            return false;
        }

        return in_array($oldStatus, OrderStatusEnum::syncExcludedValues(), true)
            && in_array($newStatus, OrderStatusEnum::salesEligibleValues(), true);
    }

    /**
     * 일괄 상태 변경 스냅샷 중 취소 → 판매상태 복원 전이인 주문에 재고를 재차감합니다.
     *
     * 단건 update() 와 동일 정책으로, 부족 시 InsufficientStockException 을 전파하여
     * 호출부의 트랜잭션 롤백(일괄 상태 변경 차단)을 유도한다.
     *
     * @param  array  $snapshots  ID 키 스냅샷 맵 (getSnapshotsByIds 결과 — 변경 전 상태 포함)
     * @param  string  $newStatus  일괄 변경 대상 상태 값
     *
     * @throws InsufficientStockException 재고 부족 시
     */
    protected function redeductReactivatedOrders(array $snapshots, string $newStatus): void
    {
        foreach ($snapshots as $orderId => $snapshot) {
            $oldStatus = $snapshot['order_status'] ?? null;

            if (! $this->isReactivationTransition($oldStatus, $newStatus)) {
                continue;
            }

            $order = $this->repository->find((int) $orderId);
            if ($order === null) {
                continue;
            }

            $order->loadMissing('options');
            $this->stockService->redeductForReactivation($order);
        }
    }

    /**
     * 주문 목록 조회
     *
     * @param  array  $filters  필터 조건
     * @return LengthAwarePaginator 페이지네이션된 주문 목록
     */
    public function getList(array $filters): LengthAwarePaginator
    {
        // 필터 데이터 가공 훅
        $filters = HookManager::applyFilters('sirsoft-ecommerce.order.filter_list_params', $filters);

        $perPage = (int) ($filters['per_page'] ?? 20);

        return $this->repository->getListWithFilters($filters, $perPage);
    }

    /**
     * 주문 통계 조회
     *
     * @return array 주문 통계 데이터 (상태별 건수, 오늘/월 매출 등)
     */
    public function getStatistics(): array
    {
        return $this->repository->getStatistics();
    }

    /**
     * 사용자별 주문상태 통계 조회
     *
     * @param  int  $userId  회원 ID
     * @return array 상태별 주문 건수
     */
    public function getUserStatistics(int $userId): array
    {
        return $this->repository->getUserStatistics($userId);
    }

    /**
     * 주문 상세 조회 (관계 포함)
     *
     * @param  int  $id  주문 ID
     * @return Order|null 주문 모델 (없으면 null)
     */
    public function getDetail(int $id): ?Order
    {
        $order = $this->repository->findWithRelations($id);

        if ($order) {
            HookManager::doAction('sirsoft-ecommerce.order.after_read', $order);
        }

        return $order;
    }

    /**
     * 주문번호로 조회
     *
     * @param  string  $orderNumber  주문번호
     * @return Order|null 주문 모델 (없으면 null)
     */
    public function getByOrderNumber(string $orderNumber): ?Order
    {
        $order = $this->repository->findByOrderNumber($orderNumber);

        if ($order) {
            HookManager::doAction('sirsoft-ecommerce.order.after_read', $order);
        }

        return $order;
    }

    /**
     * 주문 수정
     *
     * @param  Order  $order  주문 모델
     * @param  array  $data  수정 데이터
     * @return Order 수정된 주문 모델
     */
    public function update(Order $order, array $data): Order
    {
        $oldStatus = $order->order_status?->value;

        // 수정 전 훅
        HookManager::doAction('sirsoft-ecommerce.order.before_update', $order, $data);

        // 스냅샷 캡처 (ChangeDetector용)
        $snapshot = $order->toArray();

        // 데이터 가공 훅
        $data = HookManager::applyFilters('sirsoft-ecommerce.order.filter_update_data', $data, $order);

        // 수정자 정보 추가
        $data['updated_by'] = Auth::id();

        // 수취인/배송 관련 필드를 분리하여 shippingAddress 업데이트
        $recipientFields = [
            'recipient_name', 'recipient_phone', 'recipient_tel',
            'recipient_zipcode', 'recipient_address', 'recipient_detail_address', 'delivery_memo',
            'recipient_country_code',
            // 해외 배송지 필드 (D7 — 관리자 주문상세 해외 주소 수정 지원)
            'address_line_1', 'address_line_2', 'intl_city', 'intl_state', 'intl_postal_code',
        ];
        $recipientData = array_intersect_key($data, array_flip($recipientFields));
        $orderData = array_diff_key($data, array_flip($recipientFields));

        // 결제완료(payment_complete) 전이 시 본인인증(IDV) 정책 가드 (A8 / 결정 7).
        // 관리자가 상태를 직접 payment_complete 로 바꾸는 경로 — DBANK 는 입금확인,
        // 그 외 결제수단은 결제 승인으로 분기. enforce 가 미인증 시 428 을 던져 전이를 막는다.
        // 트랜잭션 진입 전에 가드해 미인증 시 update/재차감이 아예 일어나지 않게 한다.
        $intendedStatus = $orderData['order_status'] ?? null;
        $intendedStatus = $intendedStatus instanceof OrderStatusEnum ? $intendedStatus->value : $intendedStatus;
        if (
            array_key_exists('order_status', $orderData)
            && $intendedStatus === OrderStatusEnum::PAYMENT_COMPLETE->value
            && $oldStatus !== OrderStatusEnum::PAYMENT_COMPLETE->value
        ) {
            $paymentMethod = $order->payment?->payment_method;
            $paymentMethod = $paymentMethod instanceof PaymentMethodEnum ? $paymentMethod->value : $paymentMethod;
            $idvHook = $paymentMethod === PaymentMethodEnum::DBANK->value
                ? 'sirsoft-ecommerce.payment.before_confirm_deposit'
                : 'sirsoft-ecommerce.payment.before_approve';
            HookManager::doAction($idvHook, $order);
        }

        // 상태 전이 규칙 2차 도메인 불변식 가드 (우회 방어).
        // 정상 흐름은 UpdateOrderRequest::withValidator 가 먼저 422 로 막으므로 여기 도달하지 않는다.
        // 내부 호출/다른 컨트롤러 우회 시 비합법 전이가 DB 에 반영되는 것을 막는 안전망.
        if (array_key_exists('order_status', $orderData)) {
            $targetEnum = $orderData['order_status'] instanceof OrderStatusEnum
                ? $orderData['order_status']
                : OrderStatusEnum::tryFrom((string) $orderData['order_status']);
            $currentEnum = $order->order_status; // Enum (cast)
            if (
                $targetEnum instanceof OrderStatusEnum
                && $currentEnum instanceof OrderStatusEnum
                && ! $currentEnum->canTransitionTo($targetEnum)
            ) {
                throw new OrderProcessingException(
                    __('sirsoft-ecommerce::validation.orders.status_transition.invalid', [
                        'from' => $currentEnum->label(),
                        'to' => $targetEnum->label(),
                    ])
                );
            }
        }

        // 상태 변경 + 재고 재차감을 동일 트랜잭션으로 묶는다.
        // 취소 → 판매상태 복원 시 재고가 부족하면 재차감이 InsufficientStockException 을 던져
        // 상태 변경까지 롤백되어야 하므로(오버셀 방지) 같은 트랜잭션 경계 안에서 처리한다.
        $order = DB::transaction(function () use ($order, $orderData, $oldStatus) {
            $order = $this->repository->update($order, $orderData);

            $newStatus = $order->order_status?->value;

            // 취소 → 판매상태 복원 전이면, 취소 시 복원되었던 재고를 재차감한다.
            // (재고 부족 시 예외 전파 → 트랜잭션 롤백으로 상태 복원 차단)
            if (
                array_key_exists('order_status', $orderData)
                && $this->isReactivationTransition($oldStatus, $newStatus)
            ) {
                $order->loadMissing('options');
                $this->stockService->redeductForReactivation($order);
            }

            // 주문 상태가 변경된 경우 주문 옵션 상태도 동일하게 동기화한다.
            // (관리자 주문 상세 단건 수정에서 order_status 만 바뀌고 옵션이 "주문대기"에
            //  갇히던 결함 보정 — 목록 일괄변경 bulkUpdate 와 동일한 동기화 정책)
            // 취소/환불·클레임 등 별도 라이프사이클 옵션은 OrderStatusEnum SSoT 로 제외한다.
            if (
                array_key_exists('order_status', $orderData)
                && $newStatus !== null
                && $newStatus !== $oldStatus
                && ! in_array($newStatus, OrderStatusEnum::syncExcludedValues(), true)
            ) {
                $this->repository->bulkUpdateOptionStatus([$order->id], $newStatus);
            }

            return $order;
        });

        // 수취인 정보가 포함된 경우 배송지 주소 업데이트
        if (! empty($recipientData)) {
            $shippingAddress = $order->shippingAddress;
            if ($shippingAddress) {
                // 프론트엔드 필드명 → DB 필드명 매핑
                $addressData = [];
                if (array_key_exists('recipient_name', $recipientData)) {
                    $addressData['recipient_name'] = $recipientData['recipient_name'];
                }
                if (array_key_exists('recipient_phone', $recipientData)) {
                    $addressData['recipient_phone'] = $recipientData['recipient_phone'];
                }
                if (array_key_exists('delivery_memo', $recipientData)) {
                    $addressData['delivery_memo'] = $recipientData['delivery_memo'];
                    $addressData['delivery_memo_label'] = DeliveryMemoPresetEnum::resolveLabel($recipientData['delivery_memo']);
                }
                if (array_key_exists('recipient_country_code', $recipientData)) {
                    $addressData['recipient_country_code'] = $recipientData['recipient_country_code'];
                }

                // 국내/해외 분기 (D7) — country_code 기준으로 한 쪽 컬럼만 채우고 반대편은 비운다(혼재 방지).
                // 입력에 주소 필드가 포함된 경우에만 분기 처리 (수취인명/메모만 수정하는 경우는 주소 미변경).
                $hasKrAddress = array_key_exists('recipient_zipcode', $recipientData)
                    || array_key_exists('recipient_address', $recipientData)
                    || array_key_exists('recipient_detail_address', $recipientData);
                $hasIntlAddress = array_key_exists('address_line_1', $recipientData)
                    || array_key_exists('intl_city', $recipientData)
                    || array_key_exists('intl_postal_code', $recipientData);

                if ($hasKrAddress || $hasIntlAddress) {
                    $country = $recipientData['recipient_country_code']
                        ?? $shippingAddress->recipient_country_code
                        ?? 'KR';
                    $isDomestic = strtoupper((string) $country) === 'KR';

                    // 주의: zipcode/address 컬럼은 NOT NULL 이므로 비활성 측은 null 이 아닌 빈 문자열로 초기화.
                    if ($isDomestic) {
                        $addressData['zipcode'] = $recipientData['recipient_zipcode'] ?? '';
                        $addressData['address'] = $recipientData['recipient_address'] ?? '';
                        $addressData['address_detail'] = $recipientData['recipient_detail_address'] ?? null;
                        // 해외 컬럼 초기화 (nullable)
                        $addressData['address_line_1'] = null;
                        $addressData['address_line_2'] = null;
                        $addressData['intl_city'] = null;
                        $addressData['intl_state'] = null;
                        $addressData['intl_postal_code'] = null;
                    } else {
                        $addressData['address_line_1'] = $recipientData['address_line_1'] ?? null;
                        $addressData['address_line_2'] = $recipientData['address_line_2'] ?? null;
                        $addressData['intl_city'] = $recipientData['intl_city'] ?? null;
                        $addressData['intl_state'] = $recipientData['intl_state'] ?? null;
                        $addressData['intl_postal_code'] = $recipientData['intl_postal_code'] ?? null;
                        // 국내 컬럼 초기화 (zipcode/address 는 NOT NULL → 빈 문자열)
                        $addressData['zipcode'] = '';
                        $addressData['address'] = '';
                        $addressData['address_detail'] = null;
                    }
                }

                if (! empty($addressData)) {
                    $shippingAddress->update($addressData);
                }
            }
        }

        // 수정 후 훅 (스냅샷 전달 — OrderActivityLogListener가 활동 로그 기록)
        HookManager::doAction('sirsoft-ecommerce.order.after_update', $order, $snapshot);

        // 주문 상태 전이 시 단일 전이 훅 발화 → OrderStatusNotificationListener 가
        // 현재 상태(payment_complete/shipping/delivered/confirmed)에 맞는 알림 훅으로 매핑 발화한다 (A35/A36/D9/D10).
        // 이전: shipping/confirmed 인라인 if 블록만 → 결제완료/배송완료 알림 누락. 리스너 매핑으로 일원화.
        $previousStatus = $snapshot['order_status'] ?? null;
        $currentStatus = $order->order_status?->value;
        if ($currentStatus !== null && $currentStatus !== $previousStatus) {
            // 목표 상태($currentStatus)를 스칼라로 명시 전달 — 큐 지연 재로드 오매핑 방지 (N1).
            HookManager::doAction('sirsoft-ecommerce.order.after_status_change', $order->fresh(), $previousStatus, $currentStatus);
        }

        return $order;
    }

    /**
     * 주문 삭제 (Soft Delete)
     *
     * @param  Order  $order  주문 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(Order $order): bool
    {
        // 삭제 전 훅
        HookManager::doAction('sirsoft-ecommerce.order.before_delete', $order);

        // 관계 레코드 명시적 삭제 (CASCADE 의존 금지)
        $order->taxInvoices()->delete();
        $order->shippings()->delete();
        $order->addresses()->delete();
        $order->payment()->delete();
        $order->options()->delete();

        $result = $this->repository->delete($order);

        // 삭제 후 훅
        HookManager::doAction('sirsoft-ecommerce.order.after_delete', $order);

        return $result;
    }

    /**
     * 주문 + 주문옵션 + 배송지의 활동 로그를 합쳐서 페이지네이션 조회합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  array{per_page?: int, sort_order?: string}  $filters  페이지 크기·정렬 옵션
     * @return LengthAwarePaginator 페이지네이션된 활동 로그
     */
    public function getActivityLogs(Order $order, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->getActivityLogsForOrder($order, $filters);
    }

    /**
     * 주문 일괄 변경
     *
     * @param  array  $data  일괄 변경 데이터 (ids, order_status, carrier_id, tracking_number)
     * @return array 변경 결과
     */
    public function bulkUpdate(array $data): array
    {
        $ids = $data['ids'] ?? [];
        $orderStatus = $data['order_status'] ?? null;
        $carrierId = $data['carrier_id'] ?? null;
        $trackingNumber = $data['tracking_number'] ?? null;

        // 스냅샷 캡처 (ChangeDetector용 + 전이 감지용 이전 order_status)
        $snapshots = $this->repository->getSnapshotsByIds($ids);

        // before 훅
        HookManager::doAction('sirsoft-ecommerce.order.before_bulk_update', $ids, $data);

        // 결제완료(payment_complete) 일괄 전이 시 본인인증(IDV) 정책 가드 — 배치당 1회 승인 검증 (A8 / 결정 4).
        // 일괄은 결제수단 혼재 가능하므로 approve 단일 훅으로 통일. enforce 1회 verify 후 token 우회 통과.
        $normalizedStatus = $orderStatus instanceof OrderStatusEnum ? $orderStatus->value : $orderStatus;
        if ($normalizedStatus === OrderStatusEnum::PAYMENT_COMPLETE->value) {
            HookManager::doAction('sirsoft-ecommerce.payment.before_approve', $ids);
        }

        // 상태 전이 규칙 2차 도메인 불변식 가드 (우회 방어, all-or-nothing).
        // 정상 흐름은 BulkUpdateOrdersRequest::withValidator 가 먼저 422 로 막는다.
        // 하나라도 비합법 전이면 throw → 트랜잭션 미진입 → DB 무변경.
        if ($normalizedStatus !== null) {
            $target = OrderStatusEnum::tryFrom($normalizedStatus);
            if ($target !== null) {
                foreach ($snapshots as $snap) {
                    $currentValue = $snap['order_status'] ?? null;
                    $current = $currentValue !== null ? OrderStatusEnum::tryFrom(
                        $currentValue instanceof OrderStatusEnum ? $currentValue->value : $currentValue
                    ) : null;
                    if ($current !== null && ! $current->canTransitionTo($target)) {
                        throw new OrderProcessingException(
                            __('sirsoft-ecommerce::validation.orders.status_transition.invalid', [
                                'from' => $current->label(),
                                'to' => $target->label(),
                            ])
                        );
                    }
                }
            }
        }

        $updatedCount = 0;

        DB::transaction(function () use ($ids, $orderStatus, $carrierId, $trackingNumber, $snapshots, &$updatedCount) {
            // 주문 상태 일괄 변경
            if ($orderStatus !== null) {
                // 취소 → 판매상태 복원 전이 주문에 대해 재고 재차감 (부족 시 예외 → 롤백)
                $this->redeductReactivatedOrders($snapshots, $orderStatus);

                $updatedCount = $this->repository->bulkUpdateStatus($ids, $orderStatus);

                // 주문상품옵션 상태도 동일하게 일괄 변경
                $this->repository->bulkUpdateOptionStatus($ids, $orderStatus);
            }

            // 배송 정보 일괄 변경 (운송장 번호 또는 택배사)
            if ($carrierId !== null || $trackingNumber !== null) {
                $shippingUpdatedCount = $this->repository->bulkUpdateShipping($ids, $carrierId, $trackingNumber);
                $updatedCount = max($updatedCount, $shippingUpdatedCount);
            }
        });

        // after 훅 (스냅샷 전달)
        HookManager::doAction('sirsoft-ecommerce.order.after_bulk_update', $ids, $updatedCount, $snapshots);

        // 주문별 상태 전이 알림 (A35/A36/D9) — 상태가 실제 변경된 주문마다 after_status_change 발화.
        if ($normalizedStatus !== null) {
            $this->fireStatusChangeForBulk($ids, $normalizedStatus, $snapshots);
        }

        return [
            'updated_count' => $updatedCount,
            'requested_count' => count($ids),
        ];
    }

    /**
     * 일괄 상태변경 후 주문별로 전이 알림 훅(order.after_status_change)을 발화합니다.
     *
     * 상태가 실제 변경된 주문(이전 상태 ≠ 목표 상태)에 대해서만 per-order 발화하여
     * OrderStatusNotificationListener 가 결제완료/배송중/배송완료/구매확정 알림으로 매핑한다.
     *
     * @param  array<int>  $ids  대상 주문 ID 배열
     * @param  string  $newStatus  전이된 목표 상태 값
     * @param  array<int, array>  $snapshots  전이 전 스냅샷 (id 키, order_status 포함)
     */
    private function fireStatusChangeForBulk(array $ids, string $newStatus, array $snapshots): void
    {
        $changedIds = [];
        foreach ($ids as $id) {
            $previous = $snapshots[$id]['order_status'] ?? null;
            if ($previous !== $newStatus) {
                $changedIds[] = $id;
            }
        }

        if (empty($changedIds)) {
            return;
        }

        foreach ($this->repository->findByIdsKeyed($changedIds) as $order) {
            $previous = $snapshots[$order->id]['order_status'] ?? null;
            // 목표 상태($newStatus)를 스칼라로 명시 전달 — 큐 지연 재로드 오매핑 방지 (N1).
            HookManager::doAction('sirsoft-ecommerce.order.after_status_change', $order, $previous, $newStatus);
        }
    }

    /**
     * 주문 일괄 상태 변경
     *
     * @param  array  $ids  주문 ID 배열
     * @param  string  $status  변경할 상태
     * @return array 변경 결과
     */
    public function bulkUpdateStatus(array $ids, string $status): array
    {
        // 스냅샷 캡처 (ChangeDetector용 + 전이 감지용 이전 order_status)
        $snapshots = $this->repository->getSnapshotsByIds($ids);

        // before 훅
        HookManager::doAction('sirsoft-ecommerce.order.before_bulk_status_update', $ids, $status);

        // 결제완료 일괄 전이 시 본인인증(IDV) 정책 가드 — 배치당 1회 (A8 / 결정 4).
        // 트랜잭션 진입 전에 가드해 미인증 시 일괄 전이/재차감이 아예 일어나지 않게 한다.
        if ($status === OrderStatusEnum::PAYMENT_COMPLETE->value) {
            HookManager::doAction('sirsoft-ecommerce.payment.before_approve', $ids);
        }

        // 재고 재차감과 상태 변경을 동일 트랜잭션으로 묶는다 (부족 시 롤백으로 상태 변경 차단)
        $updatedCount = DB::transaction(function () use ($ids, $status, $snapshots) {
            // 취소 → 판매상태 복원 전이 주문에 대해 재고 재차감 (부족 시 예외 → 롤백)
            $this->redeductReactivatedOrders($snapshots, $status);

            return $this->repository->bulkUpdateStatus($ids, $status);
        });

        // after 훅 (스냅샷 전달)
        HookManager::doAction('sirsoft-ecommerce.order.after_bulk_status_update', $ids, $updatedCount, $snapshots);

        // 주문별 상태 전이 알림 (A35/A36/D9)
        $this->fireStatusChangeForBulk($ids, $status, $snapshots);

        return [
            'updated_count' => $updatedCount,
            'requested_count' => count($ids),
        ];
    }

    /**
     * 주문 일괄 배송 정보 변경
     *
     * @param  array  $ids  주문 ID 배열
     * @param  int|null  $carrierId  택배사 ID
     * @param  string|null  $trackingNumber  운송장 번호
     * @return array 변경 결과
     */
    public function bulkUpdateShipping(array $ids, ?int $carrierId, ?string $trackingNumber): array
    {
        // 스냅샷 캡처 (ChangeDetector용)
        $snapshots = $this->repository->getSnapshotsByIds($ids);

        // before 훅
        HookManager::doAction('sirsoft-ecommerce.order.before_bulk_shipping_update', $ids, [
            'carrier_id' => $carrierId,
            'tracking_number' => $trackingNumber,
        ]);

        $updatedCount = $this->repository->bulkUpdateShipping($ids, $carrierId, $trackingNumber);

        // after 훅 (스냅샷 전달)
        HookManager::doAction('sirsoft-ecommerce.order.after_bulk_shipping_update', $ids, $updatedCount, $snapshots);

        return [
            'updated_count' => $updatedCount,
            'requested_count' => count($ids),
        ];
    }

    /**
     * 주문 관련 이메일을 발송합니다.
     *
     * @param  Order  $order  주문 모델
     * @param  string  $email  수신자 이메일
     * @param  string  $message  이메일 본문
     */
    public function sendEmail(Order $order, string $email, string $message): void
    {
        $appName = config('app.name');
        $subject = __('sirsoft-ecommerce::messages.orders.email_subject', [
            'app_name' => $appName,
            'order_number' => $order->order_number,
        ]);

        Mail::raw($message, function ($mail) use ($email, $subject) {
            $mail->to($email)->subject($subject);
            $mail->getHeaders()->addTextHeader('X-G7-Source', 'order_email');
            $mail->getHeaders()->addTextHeader('X-G7-Extension-Type', 'module');
            $mail->getHeaders()->addTextHeader('X-G7-Extension-Id', 'sirsoft-ecommerce');
        });

        HookManager::doAction('sirsoft-ecommerce.order.after_send_email', [
            'recipientEmail' => $email,
            'subject' => $subject,
            'body' => $message,
            'orderId' => $order->id,
            'orderNumber' => $order->order_number,
            'extensionType' => 'module',
            'extensionIdentifier' => 'sirsoft-ecommerce',
            'source' => 'order_email',
        ]);
    }

    /**
     * 엑셀 내보내기용 주문 조회
     *
     * @param  array  $filters  필터 조건
     * @param  array  $ids  특정 주문 ID 배열 (선택된 항목만)
     * @return Collection 내보내기 대상 주문 컬렉션
     */
    public function getForExport(array $filters, array $ids = []): Collection
    {
        // 필터 데이터 가공 훅
        $filters = HookManager::applyFilters('sirsoft-ecommerce.order.filter_export_params', $filters);

        return $this->repository->getForExport($filters, $ids);
    }

    /**
     * 주문 배송지 주소를 변경합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $data  배송지 변경 데이터
     * @return Order 갱신된 주문
     *
     * @throws \Exception 배송 전 상태가 아닌 경우 또는 배송지를 찾을 수 없는 경우
     */
    public function updateShippingAddress(Order $order, array $data): Order
    {
        $status = $order->order_status;

        if (! $status->isBeforeShipping()) {
            throw new OrderModificationException(__('sirsoft-ecommerce::messages.orders.cannot_modify_address'));
        }

        return DB::transaction(function () use ($order, $data) {
            // 저장된 배송지 선택인 경우 해당 주소 데이터를 로드
            if (! empty($data['address_id'])) {
                $savedAddress = $this->userAddressRepository->find((int) $data['address_id']);

                if (! $savedAddress || $savedAddress->user_id !== $order->user_id) {
                    throw new OrderModificationException(__('sirsoft-ecommerce::messages.address.not_found'));
                }

                $data = [
                    'recipient_name' => $savedAddress->recipient_name,
                    'recipient_phone' => $savedAddress->recipient_phone,
                    'country_code' => $savedAddress->country_code ?? 'KR',
                    'zipcode' => $savedAddress->zipcode,
                    'address' => $savedAddress->address,
                    'address_detail' => $savedAddress->address_detail,
                ];
            }

            // 변경 전 훅
            HookManager::doAction('sirsoft-ecommerce.order.before_update_shipping_address', $order, $data);

            // 배송지 주소 업데이트
            $shippingAddress = $order->addresses()
                ->where('address_type', 'shipping')
                ->first();

            // 변경 전 스냅샷 캡처 (활동 로그용)
            $addressSnapshot = $shippingAddress ? $shippingAddress->toArray() : null;

            if ($shippingAddress) {
                $addressData = [
                    'recipient_name' => $data['recipient_name'],
                    'recipient_phone' => $data['recipient_phone'],
                    'recipient_country_code' => $data['country_code'] ?? 'KR',
                ];

                $isDomestic = ($data['country_code'] ?? 'KR') === 'KR';

                if ($isDomestic) {
                    $addressData['zipcode'] = $data['zipcode'] ?? null;
                    $addressData['address'] = $data['address'] ?? null;
                    $addressData['address_detail'] = $data['address_detail'] ?? null;
                } else {
                    $addressData['address_line_1'] = $data['address_line_1'] ?? null;
                    $addressData['address_line_2'] = $data['address_line_2'] ?? null;
                    $addressData['intl_city'] = $data['intl_city'] ?? null;
                    $addressData['intl_state'] = $data['intl_state'] ?? null;
                    $addressData['intl_postal_code'] = $data['intl_postal_code'] ?? null;
                }

                // 배송 메모 (항상 업데이트)
                if (array_key_exists('delivery_memo', $data)) {
                    $addressData['delivery_memo'] = $data['delivery_memo'];
                    $addressData['delivery_memo_label'] = DeliveryMemoPresetEnum::resolveLabel($data['delivery_memo']);
                }

                $shippingAddress->update($addressData);
            }

            // 변경 후 훅 (배송지 + 스냅샷 전달)
            $freshAddress = $shippingAddress?->fresh();
            HookManager::doAction('sirsoft-ecommerce.order.after_update_shipping_address', $order, $freshAddress, $addressSnapshot);

            return $order->fresh(['addresses']);
        });
    }

    /**
     * 주문 옵션을 구매확정합니다.
     *
     * 모든 비취소 옵션이 확정되면 주문 전체도 확정 상태로 전환합니다.
     *
     * @param  Order  $order  주문 모델
     * @param  OrderOption  $option  주문 옵션 모델
     * @return OrderOption 갱신된 옵션 모델
     */
    public function confirmOption(Order $order, OrderOption $option): OrderOption
    {
        $previousStatus = $order->order_status?->value;

        return DB::transaction(function () use ($order, $option, $previousStatus) {
            HookManager::doAction('sirsoft-ecommerce.order-option.before_confirm', $order, $option);

            $option->update([
                'option_status' => OrderStatusEnum::CONFIRMED,
                'confirmed_at' => now(),
            ]);

            // 모든 비취소 옵션이 확정되면 주문도 CONFIRMED로 전환
            // 취소 옵션 제외 집합은 OrderStatusEnum::syncExcludedValues() SSoT 를 따른다.
            $hasUnconfirmed = $order->options()
                ->whereNotIn('option_status', array_merge(
                    OrderStatusEnum::syncExcludedValues(),
                    [OrderStatusEnum::CONFIRMED->value],
                ))
                ->exists();

            $orderTransitioned = false;
            if (! $hasUnconfirmed) {
                $order->update([
                    'order_status' => OrderStatusEnum::CONFIRMED,
                    'confirmed_at' => now(),
                ]);
                $orderTransitioned = true;
            }

            // 마일리지 적립용 훅 (유지) — 별개 훅
            HookManager::doAction('sirsoft-ecommerce.order-option.after_confirm', $order, $option);

            // 주문 전체가 CONFIRMED 로 전이된 경우 구매확정 알림 발화 (A36/D10).
            // 목표 상태(CONFIRMED)를 스칼라로 명시 전달 — 큐 지연 재로드 오매핑 방지 (N1).
            if ($orderTransitioned && $previousStatus !== OrderStatusEnum::CONFIRMED->value) {
                HookManager::doAction(
                    'sirsoft-ecommerce.order.after_status_change',
                    $order->fresh(),
                    $previousStatus,
                    OrderStatusEnum::CONFIRMED->value
                );
            }

            return $option->fresh();
        });
    }

    /**
     * 비회원 주문의 조회 비밀번호를 재설정합니다.
     *
     * 평문 비밀번호는 저장/응답/로그 어디에도 노출하지 않고 해시만 저장합니다.
     *
     * @param  Order  $order  대상 주문 (비회원 주문)
     * @param  string  $plainPassword  새 조회 비밀번호 평문
     * @return Order 갱신된 주문 모델
     */
    public function resetGuestLookupPassword(Order $order, string $plainPassword): Order
    {
        HookManager::doAction('sirsoft-ecommerce.order.before_reset_guest_password', $order);

        $updated = $this->repository->update($order, [
            'guest_lookup_password_hash' => Hash::make($plainPassword),
        ]);

        HookManager::doAction('sirsoft-ecommerce.order.after_reset_guest_password', $updated);

        return $updated;
    }
}
