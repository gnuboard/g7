<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use App\Models\User;
use App\Notifications\DeliveryReport;
use App\Notifications\GenericNotification;
use App\Notifications\GuestNotifiable;
use App\Support\SiteUrl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\DTO\ManualPaidEffectsReport;
use Modules\Sirsoft\Ecommerce\DTO\ManualPaidOptions;
use Modules\Sirsoft\Ecommerce\DTO\ManualPaidTransition;
use Modules\Sirsoft\Ecommerce\Enums\DeliveryMemoPresetEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\InsufficientStockException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderEmailChannelUnavailableException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderMileageDeductionException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderModificationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderOptionNotConfirmableException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderProcessingException;
use Modules\Sirsoft\Ecommerce\Exceptions\ReactivationCouponConflictException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderShippingRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\UserAddressRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Traits\ReappliesPermissionScope;

/**
 * 주문 서비스
 */
class OrderService
{
    use ReappliesPermissionScope;

    /**
     * @param  OrderRepositoryInterface  $repository  주문 Repository
     * @param  UserAddressRepositoryInterface  $userAddressRepository  회원 배송지 Repository
     * @param  StockService  $stockService  재고 서비스 (취소 복원 · 재활성 재차감)
     * @param  ClaimAttachmentService  $claimAttachmentService  클레임 첨부 서비스
     * @param  OrderProcessingService|null  $orderProcessingService  결제 처리 서비스 (수동 결제 처리 세 항목 — 비우면 늦게 해석)
     */
    public function __construct(
        protected OrderRepositoryInterface $repository,
        protected UserAddressRepositoryInterface $userAddressRepository,
        protected StockService $stockService,
        protected ClaimAttachmentService $claimAttachmentService,
        protected ?OrderProcessingService $orderProcessingService = null,
    ) {}

    /**
     * 결제 처리 서비스를 늦게 해석합니다. (기존 4인수 생성 호출부 호환)
     *
     * @return OrderProcessingService 결제 처리 서비스
     */
    private function processing(): OrderProcessingService
    {
        return $this->orderProcessingService ??= app(OrderProcessingService::class);
    }

    /**
     * 클레임 코스 → 판매 상태 복귀 전이인지 판정합니다. (교환·반품 진행 중 반려·철회)
     *
     * 취소 → 판매 상태 재활성은 수동 결제 처리 효과(재고·마일리지·결제금액·복원 — Q13·Q14·Q26)가
     * 맡는다. 이 판정은 그 밖의 복귀 — 클레임 코스에서 돌아오는 경우 — 에만 종전 재고 재차감을 남긴다.
     *
     * @param  string|null  $oldStatus  변경 전 주문 상태 값
     * @param  string|null  $newStatus  변경 후 주문 상태 값
     * @return bool 재차감 대상 전이 여부
     */
    protected function isReactivationTransition(?string $oldStatus, ?string $newStatus): bool
    {
        if ($oldStatus === null || $newStatus === null || $oldStatus === OrderStatusEnum::CANCELLED->value) {
            return false;
        }

        return in_array($oldStatus, OrderStatusEnum::syncExcludedValues(), true)
            && in_array($newStatus, OrderStatusEnum::salesEligibleValues(), true);
    }

    /**
     * 일괄 상태 변경 스냅샷 중 클레임 코스 → 판매상태 복귀 전이인 주문에 재고를 재차감합니다.
     *
     * 단건 update() 와 동일 정책으로, 부족 시 InsufficientStockException 을 전파하여
     * 호출부의 트랜잭션 롤백(일괄 상태 변경 차단)을 유도한다. 취소 주문 재활성은 여기서 하지 않는다
     * (`applyManualPaidEffectsForBulk` — 운영자가 고른 「재고 차감」을 따른다).
     *
     * @param  array  $snapshots  ID 키 스냅샷 맵 (getSnapshotsByIds 결과 — 변경 전 상태 포함)
     * @param  string  $newStatus  일괄 변경 대상 상태 값
     * @return void
     *
     * @throws InsufficientStockException 재고 부족 시
     */
    protected function redeductReactivatedOrders(array $snapshots, string $newStatus): void
    {
        foreach ($snapshots as $orderId => $snapshot) {
            $oldStatus = $snapshot['order_status'] ?? null;
            $oldStatus = $oldStatus instanceof OrderStatusEnum ? $oldStatus->value : $oldStatus;

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
     * 마이페이지 목록 상단 건수 칸 배열을 만듭니다.
     *
     * 칸의 순서·키·건수는 `getUserStatistics()` 가 정한다(클릭 필터도 같은 키를 보낸다). 여기서는
     * 그 키에 아이콘 토큰·색·라벨을 붙여, 화면이 상태 어휘를 다시 적지 않게 한다. 합산 카운터
     * (상품준비중 + 배송준비중)는 한 상태의 라벨이 아니므로 그룹 라벨을 쓴다.
     *
     * @param  array<string, int>  $statistics  상태별 주문 건수 (getUserStatistics 결과)
     * @return array<int, array{key: string, label: string, icon: string, variant: string, count: int}> 건수 칸 배열
     */
    public function getUserStatusSummary(array $statistics): array
    {
        $summary = [];

        foreach ($statistics as $key => $count) {
            $status = OrderStatusEnum::tryFrom((string) $key);

            if ($status === null) {
                continue;
            }

            $isGroup = count(OrderStatusEnum::expandStatisticsFilter($status->value)) > 1;

            $summary[] = [
                'key' => $status->value,
                'label' => $isGroup
                    ? __('sirsoft-ecommerce::enums.order_status_groups.'.$status->value)
                    : $status->label(),
                'icon' => $status->icon(),
                'variant' => $status->variant(),
                'count' => (int) $count,
            ];
        }

        return $summary;
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
        $this->assertWithinScope($order, 'sirsoft-ecommerce.orders.update');

        // 확인 창의 세 항목(재고 차감·사용 마일리지 차감·결제금액 기록)은 주문 컬럼이 아니다 — 훅·저장소에
        // 섞이지 않게 먼저 떼어 낸다. 값이 없으면 모두 켬(Q13, 기존 API 호환).
        $manualPaid = ManualPaidOptions::extract($data);

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
        // 주문상품 상태 동기화의 전/후 내역 — 훅은 트랜잭션이 커밋된 뒤 Service 가 발행한다.
        $optionSyncChanges = [];
        $manualPaidReport = null;

        $order = DB::transaction(function () use ($order, $orderData, $oldStatus, $manualPaid, &$optionSyncChanges, &$manualPaidReport) {
            $order = $this->repository->update($order, $orderData);

            $newStatus = $order->order_status?->value;

            if (array_key_exists('order_status', $orderData)) {
                // 결제 전 → 판매, 취소 → 판매(재활성)는 확인 창의 세 항목과 재활성 복원을 적용한다(Q13·Q14·Q26).
                // 마일리지·재고 부족, 쿠폰 충돌은 예외 → 트랜잭션 롤백으로 상태 변경까지 되돌린다.
                $transition = ManualPaidTransition::forOrderStatusChange($oldStatus, $newStatus, 'single_update');
                if (! $transition->isEmpty()) {
                    $manualPaidReport = $this->processing()->applyManualPaidTransitionEffects($order, $transition, $manualPaid);
                } elseif ($this->isReactivationTransition($oldStatus, $newStatus)) {
                    // 클레임 코스 → 판매 복귀는 종전대로 복원됐던 재고를 재차감한다.
                    $order->loadMissing('options');
                    $this->stockService->redeductForReactivation($order);
                }
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
                $optionSyncChanges = $this->syncOptionStatus([$order->id], $newStatus);
            }

            return $order;
        });

        $this->fireOptionStatusSynced($optionSyncChanges);

        // 수취인 정보가 포함된 경우 배송지 주소 업데이트
        $addressSnapshot = null;
        $updatedShippingAddress = null;
        if (! empty($recipientData)) {
            $shippingAddress = $order->shippingAddress;
            if ($shippingAddress) {
                // 변경 전 스냅샷 (활동 로그용) — 위의 $snapshot 은 Order::toArray() 라
                // 주소 컬럼이 없다. 주소 변경을 전/후로 남기려면 OrderAddress 를 따로 떠야 한다.
                // 스냅샷은 로그 부가 정보일 뿐이라, 뜨지 못해도 주소 저장 자체를 막지 않는다.
                $addressSnapshot = method_exists($shippingAddress, 'toArray')
                    ? $shippingAddress->toArray()
                    : null;

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
                    $updatedShippingAddress = method_exists($shippingAddress, 'refresh')
                        ? $shippingAddress->refresh()
                        : $shippingAddress;
                }
            }
        }

        // 수정 후 훅 (스냅샷 전달 — OrderActivityLogListener가 활동 로그 기록)
        HookManager::doAction('sirsoft-ecommerce.order.after_update', $order, $snapshot);

        if ($manualPaidReport !== null && $manualPaidReport->hasApplicableItems()) {
            $this->fireManualPaidEffects($order->fresh(), $manualPaidReport);
        }

        // 배송지가 실제로 바뀐 경우에만 전용 훅을 발화한다.
        // `after_update` 는 Order 스냅샷만 들고 있어 주소 변경을 전혀 못 본다 — 그래서
        // 관리자 배송지 변경이 "주문 수정" 한 줄로만 남고 전/후값이 사라졌다(S2-D6).
        // 회원·비회원 경로(updateShippingAddress)와 같은 훅을 써서 로그 모양을 맞춘다.
        if ($updatedShippingAddress !== null) {
            HookManager::doAction(
                'sirsoft-ecommerce.order.after_update_shipping_address',
                $order,
                $updatedShippingAddress,
                $addressSnapshot
            );
        }

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
        $this->assertWithinScope($order, 'sirsoft-ecommerce.orders.delete');

        // 삭제 전 훅
        HookManager::doAction('sirsoft-ecommerce.order.before_delete', $order);

        // 첨부 대상은 신청서가 사라지기 **전에** 모아 둔다(아래 클레임 계열 설명 참조).
        $claimImages = $this->claimAttachmentService->imagesForOrder($order);

        // DB 정리는 한 트랜잭션이다 — 중간 단계가 실패하면 신청서 헤더만 지워지고 상품 행이
        // 남거나, 주문은 살아 있는데 결제·배송지만 사라진 상태가 남는다.
        $result = DB::transaction(function () use ($order) {
            return $this->deleteOrderRecords($order);
        });

        $this->claimAttachmentService->deleteImages($claimImages);

        // 삭제 후 훅
        HookManager::doAction('sirsoft-ecommerce.order.after_delete', $order);

        return $result;
    }

    /**
     * 주문과 그 관계 레코드를 명시적으로 지웁니다. (트랜잭션 안에서 호출)
     *
     * @param  Order  $order  주문 모델
     * @return bool 삭제 성공 여부
     */
    private function deleteOrderRecords(Order $order): bool
    {
        // 관계 레코드 명시적 삭제 (CASCADE 의존 금지)
        $order->taxInvoices()->delete();
        $order->shippings()->delete();
        $order->addresses()->delete();
        // 현금영수증 이력은 결제(order_payment_id)를 참조하므로 결제보다 먼저 삭제한다.
        $order->cashReceipts()->delete();
        $order->payment()->delete();

        // 클레임 계열도 명시적으로 지운다. 주문옵션을 먼저 지우면 교환·반품·환불의 **상품 행만**
        // FK CASCADE 로 사라지고 금액을 든 신청서 헤더가 상품 0개로 남는다(주문은 soft-delete 라
        // 헤더까지 내려가는 CASCADE 는 발화하지 않는다). 첨부는 주문 FK 가 없어 신청서를 지워도
        // 파일과 행이 남으므로 먼저 정리하고, 평문으로 저장되는 환불 계좌도 이때 함께 사라진다.
        // 첨부 대상은 신청서가 사라지기 **전에** 모아 두고(호출부), 파일 삭제는 DB 정리가 끝난 뒤에
        // 한다 — 파일 삭제는 되돌릴 수 없어, 중간 단계가 실패하면 주문은 살아 있는데 그 주문의
        // 첨부만 전멸한 상태가 남는다.
        //
        // 신청 상품 행(교환·반품·취소·환불)도 헤더보다 먼저 명시적으로 지운다. 헤더의 FK CASCADE 에
        // 기대면 외래키가 꺼진 환경에서 헤더 없는 상품 행이 남고, 그 행이 잔여 수량 집계에 섞인다.
        $order->claimPayments()->delete();
        $this->repository->deleteClaimItemRows((int) $order->id);
        $order->exchanges()->delete();
        $order->returns()->delete();
        $order->cancels()->delete();
        $order->refunds()->delete();

        $order->options()->delete();

        return $this->repository->delete($order);
    }

    /**
     * 일괄 상태 변경이 옮긴 상품의 배송 레코드 진행 상태·시각을 맞춥니다. (S3-D5)
     *
     * 목록 일괄 경로는 상품 상태를 raw update 로 옮겨 상세 일괄변경(`OrderOptionService`)의 배송 레코드
     * 동기화를 지나지 않는다 — 배송완료로 올린 주문의 레코드가 `shipped`·`delivered_at=null` 에 남아
     * 고객 「배송 현황」이 배송중으로 보였다. 대응표는 `OrderOptionService::shippingRecordStateFor()` 한 곳을
     * 쓰고, 나눠 보낸 상품의 배송 행 전부에 적용하며 발생 시각은 저장소가 행마다 처음 한 번만 남긴다.
     *
     * @param  array<int, array{order_option_id: int, order_id: int, previous: string, new: string}>  $changes  상품별 전이 내역
     * @param  string  $status  목표 상태 값
     * @return void
     */
    private function syncShippingRecordState(array $changes, string $status): void
    {
        $target = OrderStatusEnum::tryFrom($status);
        if ($target === null || $changes === []) {
            return;
        }

        $state = app(OrderOptionService::class)->shippingRecordStateFor($target);
        if ($state === []) {
            return;
        }

        $shippingRepository = app(OrderShippingRepositoryInterface::class);
        foreach (array_unique(array_map('intval', array_column($changes, 'order_option_id'))) as $optionId) {
            $shippingRepository->updateProgressByOrderOptionId($optionId, $state);
        }
    }

    /**
     * 주문상품 상태를 주문 상태에 맞춰 옮기고, 옮긴 전/후 내역을 돌려줍니다.
     *
     * 전/후는 갱신 **전에** 읽어야 하므로 읽기와 쓰기를 이 한 곳에서 순서대로 부른다. 훅은
     * 발행하지 않는다 — 호출부가 트랜잭션이 끝난 뒤 `fireOptionStatusSynced()` 로 낸다.
     *
     * @param  array<int, int>  $ids  주문 ID 배열
     * @param  string  $status  목표 상태 값
     * @return array<int, array{order_option_id: int, order_id: int, previous: string, new: string}> 상품별 전이 내역
     */
    private function syncOptionStatus(array $ids, string $status): array
    {
        $changes = $this->repository->collectOptionStatusSyncChanges($ids, $status);

        $this->repository->bulkUpdateOptionStatus($ids, $status);

        return $changes;
    }

    /**
     * 주문 상태 동기화가 끌고 간 주문상품 전이를 훅으로 알립니다.
     *
     * 이 경로는 관계 빌더의 raw update 라 `OrderOptionService` 의 어떤 훅도 지나지 않는다 —
     * 이 훅이 없으면 관리자 주문 수정·일괄 상태변경이 옮긴 상품이 활동로그에 한 줄도 남지 않는다.
     * 예전에는 Repository 가 이 훅을 발행했다(계층 규약상 훅은 Service 의 몫이다).
     *
     * @param  array<int, array{order_option_id: int, order_id: int, previous: string, new: string}>  $changes  상품별 전이 내역
     * @return void
     */
    private function fireOptionStatusSynced(array $changes): void
    {
        if ($changes === []) {
            return;
        }

        HookManager::doAction(
            'sirsoft-ecommerce.order_option.after_sync_status',
            $changes,
            (string) ($changes[0]['new'] ?? ''),
            'bulk_update',
        );
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
        $this->assertAllWithinScope($this->repository->findByIdsKeyed($data['ids'] ?? []), 'sirsoft-ecommerce.orders.update');

        $ids = $data['ids'] ?? [];
        $orderStatus = $data['order_status'] ?? null;
        $carrierId = $data['carrier_id'] ?? null;
        $trackingNumber = $data['tracking_number'] ?? null;
        $manualPaid = ManualPaidOptions::fromInput($data);

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
        $optionSyncChanges = [];
        $manualPaidReports = [];

        DB::transaction(function () use ($ids, $orderStatus, $normalizedStatus, $manualPaid, $carrierId, $trackingNumber, $snapshots, &$updatedCount, &$optionSyncChanges, &$manualPaidReports) {
            // 주문 상태 일괄 변경
            if ($orderStatus !== null) {
                // 결제 전 → 판매, 취소 → 판매(재활성)는 확인 창의 세 항목과 재활성 복원 (Q13·Q14·Q26).
                // 마일리지 부족·쿠폰 충돌은 모든 해당 주문을 모아 한 번에 거절한다(all-or-nothing).
                $manualPaidReports = $this->applyManualPaidEffectsForBulk($snapshots, (string) $normalizedStatus, $manualPaid, 'bulk_update');

                // 클레임 코스 → 판매상태 복귀 전이 주문에 대해 재고 재차감 (부족 시 예외 → 롤백)
                $this->redeductReactivatedOrders($snapshots, $orderStatus);

                $updatedCount = $this->repository->bulkUpdateStatus($ids, $orderStatus);

                // 주문상품옵션 상태도 동일하게 일괄 변경
                $optionSyncChanges = $this->syncOptionStatus(
                    $ids,
                    $orderStatus instanceof OrderStatusEnum ? $orderStatus->value : (string) $orderStatus,
                );
            }

            // 배송 정보 일괄 변경 (운송장 번호 또는 택배사)
            if ($carrierId !== null || $trackingNumber !== null) {
                $shippingUpdatedCount = $this->repository->bulkUpdateShipping($ids, $carrierId, $trackingNumber);
                $updatedCount = max($updatedCount, $shippingUpdatedCount);
            }

            // 배송중·배송완료로 옮긴 상품의 배송 레코드 진행 상태·시각도 함께 옮긴다(S3-D5) — 위 송장 갱신 뒤에
            // 둬야 송장과 함께 배송완료로 올릴 때 `shipped` 로 덮이지 않는다.
            $this->syncShippingRecordState($optionSyncChanges, (string) $normalizedStatus);
        });

        $this->fireOptionStatusSynced($optionSyncChanges);

        // after 훅 (스냅샷 전달)
        HookManager::doAction('sirsoft-ecommerce.order.after_bulk_update', $ids, $updatedCount, $snapshots);

        $this->fireManualPaidEffectsForBulk($manualPaidReports);

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
     * @return void
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
     * @param  ManualPaidOptions|null  $options  수동 결제 처리 세 항목 (null 이면 모두 켬 — Q13)
     * @return array 변경 결과
     */
    public function bulkUpdateStatus(array $ids, string $status, ?ManualPaidOptions $options = null): array
    {
        $options ??= ManualPaidOptions::defaults();
        $this->assertAllWithinScope($this->repository->findByIdsKeyed($ids), 'sirsoft-ecommerce.orders.update');

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
        $manualPaidReports = [];
        $updatedCount = DB::transaction(function () use ($ids, $status, $snapshots, $options, &$manualPaidReports) {
            // 결제 전 → 판매, 취소 → 판매(재활성)는 세 항목과 재활성 복원 (Q13·Q14·Q26).
            // 이 경로에는 옵션 동기화가 없어, 재활성 복원이 되살리지 않으면 상품 행은 취소로 남는다.
            $manualPaidReports = $this->applyManualPaidEffectsForBulk($snapshots, $status, $options, 'bulk_update_status');

            // 클레임 코스 → 판매상태 복귀 전이 주문에 대해 재고 재차감 (부족 시 예외 → 롤백)
            $this->redeductReactivatedOrders($snapshots, $status);

            return $this->repository->bulkUpdateStatus($ids, $status);
        });

        // after 훅 (스냅샷 전달)
        HookManager::doAction('sirsoft-ecommerce.order.after_bulk_status_update', $ids, $updatedCount, $snapshots);

        $this->fireManualPaidEffectsForBulk($manualPaidReports);

        // 주문별 상태 전이 알림 (A35/A36/D9)
        $this->fireStatusChangeForBulk($ids, $status, $snapshots);

        return [
            'updated_count' => $updatedCount,
            'requested_count' => count($ids),
        ];
    }

    /**
     * 목록 일괄변경 확인 창의 수동 결제 처리 항목을 미리 계산합니다. (DB 변경 없음 — Q13·Q14·Q26)
     *
     * 실행(`bulkUpdate`)과 같은 전이 분류·계획 메서드를 쓴다. 목표 상태가 비었거나 판매 상태가 아니면
     * 모든 주문이 「해당 없음」이다.
     *
     * @param  array<int, int>  $ids  주문 ID
     * @param  string|null  $status  목표 상태
     * @return array<string, mixed> 미리보기 (target_status·orders·summary)
     */
    public function previewManualPaidEffectsForBulk(array $ids, ?string $status): array
    {
        $orders = $this->repository->findByIdsWithRelationsKeyed($ids, ['options', 'payment', 'shippings']);
        $this->assertAllWithinScope($orders, 'sirsoft-ecommerce.orders.update');

        $entries = [];
        foreach ($orders as $order) {
            $from = $order->order_status instanceof OrderStatusEnum ? $order->order_status->value : $order->order_status;
            $entries[] = [$order, ManualPaidTransition::forOrderStatusChange($from, $status, 'bulk_update')];
        }

        return $this->processing()->previewManualPaidEffects($entries, (string) $status);
    }

    /**
     * 일괄 상태 변경의 수동 결제 처리 세 항목과 재활성 복원을 적용합니다. (트랜잭션 내부 — all-or-nothing)
     *
     * 1) 쿠폰 충돌 사전 점검 — 하나라도 있으면 해당 주문을 모두 알리고 전체를 바꾸지 않는다(Q27).
     * 2) 마일리지만 모든 주문에 시도 — 실패는 그 주문의 savepoint 만 되돌고 다음 주문을 계속 본다.
     *    모자란 주문이 있으면 주문번호를 모두 모아 던진다(Q4) → 바깥 트랜잭션 전체 롤백.
     * 3) 재고·결제금액·복원 — 첫 재고 부족에서 예외.
     * 주문 id 오름차순으로 잠근다 — 교착을 막고 같은 회원 잔액 판정 순서를 미리보기와 맞춘다.
     *
     * @param  array  $snapshots  ID 키 스냅샷 (변경 전 상태)
     * @param  string  $status  목표 상태
     * @param  ManualPaidOptions  $options  운영자 선택
     * @param  string  $trigger  진입 경로
     * @return array<int, ManualPaidEffectsReport> 주문 ID => 적용 결과
     *
     * @throws OrderMileageDeductionException 잔액 부족 주문이 있을 때
     * @throws ReactivationCouponConflictException 쿠폰 충돌 주문이 있을 때
     */
    private function applyManualPaidEffectsForBulk(array $snapshots, string $status, ManualPaidOptions $options, string $trigger): array
    {
        $targets = [];
        foreach ($snapshots as $id => $snapshot) {
            $from = $snapshot['order_status'] ?? null;
            $from = $from instanceof OrderStatusEnum ? $from->value : $from;
            $transition = ManualPaidTransition::forOrderStatusChange($from, $status, $trigger);
            if (! $transition->isEmpty()) {
                $targets[(int) $id] = $transition;
            }
        }

        if ($targets === []) {
            return [];
        }

        ksort($targets);
        $orders = $this->repository->findByIdsKeyed(array_keys($targets));

        $conflicts = [];
        foreach ($targets as $id => $transition) {
            if ($transition->revives() && isset($orders[$id])) {
                $plan = app(OrderReactivationService::class)->plan($orders[$id], $transition);
                if ($plan->couponConflicts !== []) {
                    $conflicts[] = (string) $orders[$id]->order_number;
                }
            }
        }
        if ($conflicts !== []) {
            throw ReactivationCouponConflictException::forBulk($conflicts);
        }

        $reports = [];
        $short = [];
        foreach ($targets as $id => $transition) {
            if (! isset($orders[$id])) {
                continue;
            }

            try {
                $reports[$id] = $this->processing()->applyManualPaidTransitionEffects(
                    $orders[$id],
                    $transition,
                    $options->with(stock: false, record: false, restoration: false),
                );
            } catch (OrderMileageDeductionException) {
                $short[] = (string) $orders[$id]->order_number;
            }
        }
        if ($short !== []) {
            throw OrderMileageDeductionException::insufficientForBulk($short);
        }

        foreach ($targets as $id => $transition) {
            if (! isset($reports[$id])) {
                continue;
            }

            $second = $this->processing()->applyManualPaidTransitionEffects(
                $orders[$id],
                $transition,
                $options->with(mileage: false, restoration: true),
            );
            $reports[$id] = $reports[$id]->mergeWith($second, $options);
        }

        return $reports;
    }

    /**
     * 수동 결제 처리 결과 훅을 발화합니다. (트랜잭션 뒤 — 활동 로그·재활성 플러그인 훅)
     *
     * @param  Order  $order  주문
     * @param  ManualPaidEffectsReport  $report  적용 결과
     * @return void
     */
    private function fireManualPaidEffects(Order $order, ManualPaidEffectsReport $report): void
    {
        $payload = $report->toArray();

        HookManager::doAction(
            'sirsoft-ecommerce.order.after_manual_paid_effects',
            $order,
            $payload,
            $report->applied['restoration']['snapshot_before'] ?? null,
        );

        if (($report->applied['restoration']['applied'] ?? false) === true) {
            HookManager::doAction('sirsoft-ecommerce.order.after_reactivation_restored', $order, $payload['items']['restoration']);
        }
    }

    /**
     * 일괄 처리 결과 훅을 주문마다 발화합니다.
     *
     * @param  array<int, ManualPaidEffectsReport>  $reports  주문 ID => 적용 결과
     * @return void
     */
    private function fireManualPaidEffectsForBulk(array $reports): void
    {
        if ($reports === []) {
            return;
        }

        $orders = $this->repository->findByIdsKeyed(array_keys($reports));
        foreach ($reports as $id => $report) {
            if (isset($orders[$id]) && $report->hasApplicableItems()) {
                $this->fireManualPaidEffects($orders[$id], $report);
            }
        }
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
        $this->assertAllWithinScope($this->repository->findByIdsKeyed($ids), 'sirsoft-ecommerce.orders.update');

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
     * 주문 관련 안내 메일을 고객에게 발송합니다 (주문 상세 「고객에게 메일 보내기」).
     *
     * 알림 정의 `order_custom_email` 로 알림 시스템을 거쳐 보낸다 — 이커머스 「알림 채널」의 메일 토글·
     * 메일 설정 준비 상태·발송 로그(notification_logs)가 이 메일에도 적용된다(#699). 7.1.0 전에는
     * `Mail::raw()` 로 알림 밖에서 보내 채널을 꺼도 나갔고 발송 로그도 남지 않았다.
     *
     * 수신자: 주문 회원의 이메일과 같으면 회원(언어·발송 로그 연결), 아니면 입력한 주소의 비회원.
     * 성공했을 때만 `sirsoft-ecommerce.order.after_send_email` 훅을 발화한다(활동 로그가 구독).
     *
     * @param  Order  $order  주문
     * @param  string  $email  수신 이메일
     * @param  string  $message  관리자가 입력한 본문 (평문 — 줄바꿈 유지, HTML 은 이스케이프)
     * @return void
     *
     * @throws OrderEmailChannelUnavailableException 메일 채널 꺼짐·미준비·템플릿 비활성으로 건너뛰었을 때
     * @throws OrderProcessingException 발송 중 오류가 났을 때
     */
    public function sendEmail(Order $order, string $email, string $message): void
    {
        $appName = config('app.name');
        $subject = __('sirsoft-ecommerce::messages.orders.email_subject', [
            'app_name' => $appName,
            'order_number' => $order->order_number,
        ]);

        $report = new DeliveryReport;
        $notification = (new GenericNotification(
            type: 'order_custom_email',
            hookPrefix: 'sirsoft-ecommerce',
            data: [
                'subject' => $subject,
                'message' => nl2br(e($message)),
                'order_number' => $order->order_number,
                'customer_name' => (string) ($order->getOrdererName() ?? ''),
                'app_name' => $appName,
                'site_url' => SiteUrl::absolute('/'),
            ],
            extensionType: 'module',
            extensionIdentifier: 'sirsoft-ecommerce',
            channel: 'mail',
        ))->withDeliveryReport($report);

        $this->resolveEmailRecipient($order, $email)->notifyNow($notification);

        $status = $report->statusFor('mail');

        if ($status === DeliveryReport::FAILED) {
            throw new OrderProcessingException((string) $report->reasonFor('mail'));
        }

        if ($status !== DeliveryReport::SENT) {
            throw new OrderEmailChannelUnavailableException($report->reasonFor('mail'));
        }

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
     * 주문 메일 수신자를 결정합니다.
     *
     * 주문 회원의 이메일과 입력 주소가 같으면 회원으로 보낸다(회원 언어로 렌더, 발송 로그에 회원 연결).
     * 그 밖(비회원 주문·다른 주소)은 그 주소의 비회원 수신자로 보낸다.
     *
     * @param  Order  $order  주문
     * @param  string  $email  수신 이메일
     * @return User|GuestNotifiable 수신자
     */
    private function resolveEmailRecipient(Order $order, string $email): User|GuestNotifiable
    {
        $user = $order->user;

        if ($user instanceof User && mb_strtolower((string) $user->email) === mb_strtolower($email)) {
            return $user;
        }

        return new GuestNotifiable($email, $order->getOrdererName(), app()->getLocale());
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
        $this->assertWithinScope($order, 'sirsoft-ecommerce.orders.update');

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

                // 저장된 배송지의 국내·해외 필드를 모두 싣는다. 지역 판정은 아래
                // $isDomestic 한 곳에서만 하고 그 분기가 필요한 쪽만 골라 쓴다.
                // 국내 6필드만 싣던 종전 형태에서는 해외 배송지를 고르면 해외 컬럼이
                // 전부 null 로 덮여 주소가 통째로 사라졌다.
                // 배송 메모는 저장 주소가 아니라 이번 요청의 값이므로 승계한다.
                $memo = array_key_exists('delivery_memo', $data)
                    ? ['delivery_memo' => $data['delivery_memo']]
                    : [];

                $data = [
                    'recipient_name' => $savedAddress->recipient_name,
                    'recipient_phone' => $savedAddress->recipient_phone,
                    'country_code' => $savedAddress->country_code ?? 'KR',
                    'zipcode' => $savedAddress->zipcode,
                    'address' => $savedAddress->address,
                    'address_detail' => $savedAddress->address_detail,
                    'address_line_1' => $savedAddress->address_line_1,
                    'address_line_2' => $savedAddress->address_line_2,
                    'intl_city' => $savedAddress->city,
                    'intl_state' => $savedAddress->state,
                    'intl_postal_code' => $savedAddress->postal_code,
                ] + $memo;
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
                // 국가 코드 폴백은 UpdateOrderShippingAddressRequest::isDomesticShipping()
                // 과 반드시 같은 기준이어야 한다 — 게이트가 "미전송 = 국내" 로 보고
                // zipcode/address 를 필수 검증했는데 서비스가 기존 국가를 승계해 해외
                // 분기로 떨어지면, 방금 검증한 국내 입력을 버리고 빈 문자열을 쓴다.
                // 직접 입력 폼은 국내 필드만 제공해 country_code 를 보내지 않으므로,
                // 해외 주문의 배송지를 국내로 바꾸는 경로가 통째로 사라진다(응답은 200).
                $country = $data['country_code'] ?? 'KR';
                $isDomestic = strtoupper(trim((string) $country)) === 'KR';

                $addressData = [
                    'recipient_name' => $data['recipient_name'],
                    'recipient_phone' => $data['recipient_phone'],
                    'recipient_country_code' => $country,
                ];

                // 주의: zipcode/address 컬럼은 NOT NULL 이므로 비활성 측은 null 이 아닌 빈 문자열로 초기화.
                // 반대편 컬럼을 비우지 않으면 국내↔해외 전환 시 구 주소가 잔존해 배송지가 두 벌이 된다.
                if ($isDomestic) {
                    $addressData['zipcode'] = $data['zipcode'] ?? '';
                    $addressData['address'] = $data['address'] ?? '';
                    $addressData['address_detail'] = $data['address_detail'] ?? null;
                    // 해외 컬럼 초기화 (nullable)
                    $addressData['address_line_1'] = null;
                    $addressData['address_line_2'] = null;
                    $addressData['intl_city'] = null;
                    $addressData['intl_state'] = null;
                    $addressData['intl_postal_code'] = null;
                } else {
                    $addressData['address_line_1'] = $data['address_line_1'] ?? null;
                    $addressData['address_line_2'] = $data['address_line_2'] ?? null;
                    $addressData['intl_city'] = $data['intl_city'] ?? null;
                    $addressData['intl_state'] = $data['intl_state'] ?? null;
                    $addressData['intl_postal_code'] = $data['intl_postal_code'] ?? null;
                    // 국내 컬럼 초기화 (zipcode/address 는 NOT NULL → 빈 문자열)
                    $addressData['zipcode'] = '';
                    $addressData['address'] = '';
                    $addressData['address_detail'] = null;
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
        // 상태 게이트를 서비스 자체에 내장한다 — 호출자(회원 FormRequest / 비회원 컨트롤러 / 훅)와
        // 무관하게 동일 강도로 강제한다. FormRequest 검사는 UI 조기 피드백용으로 유지되지만,
        // 그 경로를 우회하는 호출이 이미 확정된/확정 불가 상태의 옵션을 재확정하는 것을 막는다.
        if ($option->option_status === OrderStatusEnum::CONFIRMED) {
            throw new OrderOptionNotConfirmableException('order_option_already_confirmed');
        }

        $confirmableStatuses = module_setting(
            'sirsoft-ecommerce',
            'order_settings.confirmable_statuses',
            ['shipping', 'delivered']
        );

        if (! in_array($option->option_status->value, $confirmableStatuses, true)) {
            throw new OrderOptionNotConfirmableException('order_option_not_confirmable');
        }

        $previousStatus = $order->order_status?->value;
        $purchaseConfirmed = false;

        // 변경 전 상품 속성 — 구매확정 줄의 "변경 전 → 변경 후" 표가 읽는 근거다(§7-1 D).
        $confirmSnapshot = $option->toArray();

        $result = DB::transaction(function () use ($order, $option, $previousStatus, $confirmSnapshot, &$purchaseConfirmed) {
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
                $orderUpdate = ['order_status' => OrderStatusEnum::CONFIRMED];
                // 멱등: 최초 확정 시점을 보존한다 (OrderOptionService::transitionOrderStatus 와 대칭).
                // 재확정 시 confirmed_at 을 덮어쓰면 구매확정 훅이 중복 발화하고 확정 시점이 소실된다.
                $purchaseConfirmed = $order->confirmed_at === null;
                if ($purchaseConfirmed) {
                    $orderUpdate['confirmed_at'] = now();
                }
                $order->update($orderUpdate);
                $orderTransitioned = true;
            }

            // 마일리지 적립용 훅 (유지) — 별개 훅
            // 변경 전 상품 속성 — 구매확정 줄에 "변경 전 → 변경 후" 표를 세우는 근거다(§7-1 D).
            HookManager::doAction('sirsoft-ecommerce.order-option.after_confirm', $order, $option, $confirmSnapshot ?? null);

            // 주문 전체가 CONFIRMED 로 전이된 경우 구매확정 알림 발화 (A36/D10).
            // 목표 상태(CONFIRMED)를 스칼라로 명시 전달 — 큐 지연 재로드 오매핑 방지 (N1).
            if ($orderTransitioned && $previousStatus !== OrderStatusEnum::CONFIRMED->value) {
                HookManager::doAction(
                    'sirsoft-ecommerce.order.after_status_change',
                    $order->fresh(),
                    $previousStatus,
                    OrderStatusEnum::CONFIRMED->value
                );

                // 파생 전이 이력 — 상품 확정에서 따라온 주문 확정을 활동 로그에 전/후와 함께
                // 남긴다 (OrderOptionService::transitionOrderStatus 와 같은 축).
                HookManager::doAction(
                    'sirsoft-ecommerce.order.after_status_sync',
                    $order->fresh(),
                    $previousStatus,
                    OrderStatusEnum::CONFIRMED->value
                );
            }

            return $option->fresh();
        });

        // 구매확정 훅 — order.confirmed_at 이 최초로 세팅된 순간에만 1회 발화(멱등).
        // 커밋 이후에 발화한다: 큐 리스너(PurgeCashReceiptIdentifierListener)가 확정 전 상태를
        // 읽지 않도록 하고, 리스너 실패가 구매확정 트랜잭션을 롤백시키지 않게 한다.
        if ($purchaseConfirmed) {
            HookManager::doAction('sirsoft-ecommerce.order.after_purchase_confirmed', $order->fresh());
        }

        return $result;
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
