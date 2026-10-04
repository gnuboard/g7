<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\OrderOptionSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderProcessingException;
use Modules\Sirsoft\Ecommerce\Exceptions\ResourceScopeMismatchException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderShippingRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductReviewRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Support\MileageRounding;

/**
 * 주문 옵션 서비스
 *
 * 주문 옵션의 수량 분할 상태 변경 등 비즈니스 로직을 처리합니다.
 */
class OrderOptionService
{
    /**
     * @param  OrderOptionRepositoryInterface  $orderOptionRepository  주문 옵션 Repository
     * @param  OrderShippingRepositoryInterface  $orderShippingRepository  주문 배송 Repository
     * @param  OrderRepositoryInterface  $orderRepository  주문 Repository
     * @param  ProductReviewRepositoryInterface  $productReviewRepository  상품 리뷰 Repository
     * @param  StockService  $stockService  재고 서비스 (취소 복원 · 재활성 재차감)
     * @param  EcommerceSettingsService  $settingsService  이커머스 설정 서비스 (재고 복원 정책 등)
     * @param  MileageTransactionRepositoryInterface  $mileageTransactionRepository  마일리지 거래 Repository (병합 시 적립 거래 이전)
     * @param  ClaimQuantityGuard  $claimQuantityGuard  클레임 수량 잠금 가드 (D15)
     * @param  CurrencyConversionService  $currencyService  통화 변환 서비스 (주문 기준 통화 해석)
     */
    public function __construct(
        protected OrderOptionRepositoryInterface $orderOptionRepository,
        protected OrderShippingRepositoryInterface $orderShippingRepository,
        protected OrderRepositoryInterface $orderRepository,
        protected ProductReviewRepositoryInterface $productReviewRepository,
        protected StockService $stockService,
        protected EcommerceSettingsService $settingsService,
        protected MileageTransactionRepositoryInterface $mileageTransactionRepository,
        protected ClaimQuantityGuard $claimQuantityGuard,
        protected CurrencyConversionService $currencyService,
    ) {}

    /**
     * 취소 → 판매 상태 복원 전이인지 판정합니다.
     *
     * 옵션의 변경 전 상태가 취소상태(cancelled/partial_cancelled)이고 변경 후 상태가
     * 판매 반영 상태(payment_complete~confirmed)일 때만 재고 재차감 대상이다.
     * OrderService::isReactivationTransition 과 동일 정책(SSoT 집합 재사용)을 옵션 단위로 적용한다.
     *
     * @param  string|null  $oldStatus  변경 전 옵션 상태 값
     * @param  OrderStatusEnum  $newStatus  변경 후 옵션 상태
     * @return bool 재차감 대상 전이 여부
     */
    protected function isReactivationTransition(?string $oldStatus, OrderStatusEnum $newStatus): bool
    {
        if ($oldStatus === null) {
            return false;
        }

        return in_array($oldStatus, OrderStatusEnum::syncExcludedValues(), true)
            && in_array($newStatus->value, OrderStatusEnum::salesEligibleValues(), true);
    }

    /**
     * 판매 상태 → 취소 전이인지 판정합니다.
     *
     * 옵션의 변경 전 상태가 판매 반영 상태(payment_complete~confirmed, 재고 차감됨)이고
     * 변경 후 상태가 취소상태(cancelled/partial_cancelled)일 때 재고 복원 대상이다.
     * isReactivationTransition(재차감) 의 역방향이며, 동일 SSoT 집합을 재사용한다.
     *
     * per-line 상태 변경 드롭다운에서 "주문취소" 로 전이할 때, PG 환불 동반(취소 모달,
     * OrderCancellationService) 경로가 아니어도 재고가 복원되어야 한다(재고-주문 정합성).
     *
     * @param  string|null  $oldStatus  변경 전 옵션 상태 값
     * @param  OrderStatusEnum  $newStatus  변경 후 옵션 상태
     * @return bool 재고 복원 대상 전이 여부
     */
    protected function isCancellationTransition(?string $oldStatus, OrderStatusEnum $newStatus): bool
    {
        if ($oldStatus === null) {
            return false;
        }

        return in_array($oldStatus, OrderStatusEnum::salesEligibleValues(), true)
            && in_array($newStatus->value, OrderStatusEnum::syncExcludedValues(), true);
    }

    /**
     * 주문 옵션 상태를 수량 단위로 변경합니다.
     * 변경 수량이 전체 수량보다 작으면 레코드를 분할합니다.
     *
     * @param  OrderOption  $option  대상 옵션
     * @param  OrderStatusEnum  $newStatus  변경할 상태
     * @param  int  $quantity  변경할 수량
     * @param  array  $metadata  추가 정보 (carrier, tracking_number 등)
     * @param  bool  $restoreStockOnCancel  판매 → 취소 전이 시 이 메서드가 재고를 복원할지 여부.
     *                                      per-line 상태변경(직접 호출)은 true(기본) — 이 경로가 유일한 복원 주체.
     *                                      취소 모달(OrderCancellationService)은 false — 자체 restoreStock() 이
     *                                      복원을 전담하므로 여기서 복원하면 이중 복원이 된다.
     * @param  bool  $mergeSiblings  전이 후 같은 상태의 형제 레코드와 병합할지 여부.
     *                               일반 상태 변경은 true(기본) — 같은 상품옵션의 같은 상태 행을 합쳐 목록을 단순화한다.
     *                               클레임(교환/반품) 코스 전이는 false — 병합은 피흡수 레코드를 삭제하는데
     *                               클레임 신청 상품 행이 그 레코드를 FK(cascade)로 참조하므로 신청 이력이 함께 사라지고,
     *                               합쳐진 행은 어느 신청서 몫인지 구분되지 않아 신청서 단위 복귀·완료가 불가능해진다.
     * @return array{original: OrderOption, split: ?OrderOption} 변경 결과
     */
    public function changeStatusWithQuantity(
        OrderOption $option,
        OrderStatusEnum $newStatus,
        int $quantity,
        array $metadata = [],
        bool $restoreStockOnCancel = true,
        bool $mergeSiblings = true
    ): array {
        // 변경 전 훅
        HookManager::doAction('sirsoft-ecommerce.order_option.before_status_change', $option, $newStatus, $quantity);

        $result = ['original' => $option, 'split' => null];

        // 변경 전 옵션 상태 캡처 (취소↔판매 전이 판정용)
        $oldStatusValue = $option->option_status instanceof OrderStatusEnum
            ? $option->option_status->value
            : $option->option_status;

        // 변경 전 스냅샷 (활동 로그의 전/후 표 — 일괄 변경 경로와 같은 계약). 상태만 넘기면
        // 분할 전이의 수량 변화가 이력에서 사라진다.
        $snapshot = $option->attributesToArray();

        // 상태 전이 규칙 2차 도메인 불변식 가드 (우회 방어).
        // 정상 흐름은 FormRequest(단건/일괄)가 먼저 422 로 막으므로 여기 도달하지 않는다.
        // 취소(CANCELLED) 진입은 게이트가 통과 처리하므로 취소 모달 경로는 비간섭.
        $oldEnum = $oldStatusValue !== null ? OrderStatusEnum::tryFrom($oldStatusValue) : null;
        if ($oldEnum instanceof OrderStatusEnum && ! $oldEnum->canTransitionTo($newStatus)) {
            throw new OrderProcessingException(
                __('sirsoft-ecommerce::validation.orders.status_transition.invalid', [
                    'from' => $oldEnum->label(),
                    'to' => $newStatus->label(),
                ])
            );
        }

        $isReactivation = $this->isReactivationTransition($oldStatusValue, $newStatus);
        $isCancellation = $this->isCancellationTransition($oldStatusValue, $newStatus);
        // 판매 → 취소 전이 시 재고 복원 여부.
        // - $restoreStockOnCancel=false (취소 모달 경로): OrderCancellationService::restoreStock() 이 전담 → 여기선 스킵(이중 복원 방지)
        // - $restoreStockOnCancel=true (per-line 직접 경로): 이 메서드가 유일 복원 주체
        // 복원 여부는 설정(stock_restore_on_cancel)을 따른다(취소 모달 경로와 동일 정책).
        $shouldRestoreOnCancel = $restoreStockOnCancel && $isCancellation && $this->settingsService->getSetting(
            'order_settings.stock_restore_on_cancel',
            true
        );

        DB::transaction(function () use ($option, $newStatus, $quantity, $metadata, $isReactivation, $shouldRestoreOnCancel, $mergeSiblings, &$result) {
            if ($quantity === $option->quantity) {
                // 전체 수량 변경 → UPDATE만
                $updateData = ['option_status' => $newStatus];
                // 배송완료 진입 시점 기록 (지연 적립 기준)
                if ($newStatus === OrderStatusEnum::DELIVERED && $option->delivered_at === null) {
                    $updateData['delivered_at'] = now();
                }
                // 구매확정 진입 시점 기록 (delivered_at 과 대칭 — 지연 적립·리뷰 기한 기준).
                // 멱등: 이미 값이 있으면 유지(상태 재진입 시 최초 확정 시점 보존).
                if ($newStatus === OrderStatusEnum::CONFIRMED && $option->confirmed_at === null) {
                    $updateData['confirmed_at'] = now();
                }
                $this->orderOptionRepository->update($option, $updateData);

                // 배송 정보(택배사·송장번호) 기록 — 병합 전 상태 변경 대상 옵션에 반영.
                $this->applyShippingTracking($option, $newStatus, $metadata);

                // 취소 → 판매 상태 복원 전이면 이 옵션(전체 수량)의 재고를 재차감한다.
                // (취소 시 복원된 재고를 되돌리는 역연산 — 부족 시 예외 전파 → 트랜잭션 롤백으로 상태 복원 차단)
                if ($isReactivation) {
                    $this->stockService->redeductOrderOptionForReactivation($option);
                }

                // 판매 → 취소 전이면 이 옵션(전체 수량)의 재고를 복원한다.
                // PG 환불 동반(취소 모달) 경로가 아니어도 재고는 복원되어야 한다(재고-주문 정합성).
                // 복원 + is_stock_deducted 플래그 정리를 원자적으로 처리.
                if ($shouldRestoreOnCancel) {
                    $this->stockService->restoreOptionStockForOrderOption($option, $quantity);
                }

                // 병합 가능한 형제 레코드 검색 → 있으면 병합 (클레임 코스 전이는 호출부가 끈다)
                $mergeCandidate = $mergeSiblings
                    ? $this->orderOptionRepository->findMergeCandidate($option, $newStatus)
                    : null;
                if ($mergeCandidate && $mergeCandidate->id !== $option->id) {
                    $this->mergeOptions($mergeCandidate, $option);
                    $result['merged_into'] = $mergeCandidate;
                }
            } else {
                // 부분 수량 변경 → 레코드 분할
                $remainingQuantity = $option->quantity - $quantity;
                $ratio = $quantity / $option->quantity;

                // replicate 전 원본 금액 캡처 (replicate 후 양쪽이 동일 값을 갖기 때문)
                $origAmounts = $this->captureAmounts($option);

                // 신규 레코드: 분할된 수량 + 새 상태
                $splitOption = $option->replicate();
                $splitOption->quantity = $quantity;
                $splitOption->option_status = $newStatus;
                $splitOption->parent_option_id = $option->id;
                $splitOption->source_type = OrderOptionSourceTypeEnum::SPLIT;
                // 배송완료로 분할 전이 시 분할 레코드에 배송완료 시점 기록
                if ($newStatus === OrderStatusEnum::DELIVERED && $splitOption->delivered_at === null) {
                    $splitOption->delivered_at = now();
                }
                // 구매확정으로 분할 전이 시 분할 레코드에 구매확정 시점 기록 (delivered_at 과 대칭).
                // 원본에서 replicate 로 계승한 delivered_at 은 유지한다(배송완료·구매확정은 둘 다 실제 발생한 사건).
                if ($newStatus === OrderStatusEnum::CONFIRMED && $splitOption->confirmed_at === null) {
                    $splitOption->confirmed_at = now();
                }

                // 분할 레코드 금액 = 원본 × ratio
                // 적립 포인트는 주문 시점 절사 기준으로 정수화한다 — 안분을 소수 2자리로 남기면
                // 원장에 없는 소수점 포인트(390.5P)가 옵션 행에 생기고, 그 합이 적립 목표액이 되어
                // 실제 지급 가능한 정수 포인트와 어긋난다.
                $this->applySplitAmounts(
                    $splitOption,
                    $origAmounts,
                    $ratio,
                    $quantity,
                    MileageRounding::fromOrderSnapshot($option->order?->mileage_policy_snapshot)
                );
                $this->orderOptionRepository->save($splitOption);

                // 분할 레코드에도 배송 행을 만든다 — 없으면 클레임 배송비가 0원이 된다.
                //
                // `ClaimShippingFeeService` 는 옵션의 배송 행에서 배송정책을 얻는다
                // (`$shipping?->shipping_policy_id`). 분할 행에 배송 행이 없으면 정책 그룹이 비어
                // `no_shipping_policy` 로 떨어지고, **고객 귀책 반품·교환인데도 회수비·왕복비가
                // 0원**으로 청구된다. 게다가 그 행을 교환하면 `replicateShippingRow()` 가 원 행의
                // 배송 행을 못 찾아 조용히 건너뛰므로 후속 행까지 배송 행 없이 이어진다(S3-D5).
                //
                // 시각 컬럼(shipped_at/delivered_at 등)은 `replicateForOrderOption()` 이 복사하지
                // 않는다 — 분할 행은 이 전이로 자기 시각을 새로 얻는다.
                $originShipping = $this->orderShippingRepository->findFirstByOrderOptionId($option->id);

                if ($originShipping !== null) {
                    $this->orderShippingRepository->replicateForOrderOption($originShipping, $splitOption->id);
                }

                // 원본 레코드 = 원본 - 분할 (잔여분)
                $this->applyRemainingAmounts($option, $origAmounts, $splitOption, $remainingQuantity);
                $this->orderOptionRepository->save($option);

                // 배송 정보(택배사·송장번호) 기록 — 새 상태로 전이한 분할 레코드에 반영.
                $this->applyShippingTracking($splitOption, $newStatus, $metadata);

                // 취소 → 판매 상태 복원 전이면 판매 상태로 전이된 분할 레코드(quantity)만 재차감한다.
                // 잔여 원본($option)은 취소 상태를 유지하므로 재차감 대상 아님.
                if ($isReactivation) {
                    $this->stockService->redeductOrderOptionForReactivation($splitOption);
                }

                // 판매 → 취소 전이면 취소된 분할 레코드(quantity)만 재고 복원한다.
                // 잔여 원본($option)은 판매 상태를 유지하므로 복원 대상 아님(차감 유지).
                if ($shouldRestoreOnCancel) {
                    $this->stockService->restoreOptionStockForOrderOption($splitOption, $quantity);
                }

                // 병합 가능한 형제 레코드 검색 → 있으면 병합 (클레임 코스 전이는 호출부가 끈다)
                $mergeCandidate = $mergeSiblings
                    ? $this->orderOptionRepository->findMergeCandidate($splitOption, $newStatus)
                    : null;
                if ($mergeCandidate && $mergeCandidate->id !== $splitOption->id) {
                    $this->mergeOptions($mergeCandidate, $splitOption);
                    $result['split'] = null;
                    $result['merged_into'] = $mergeCandidate;
                } else {
                    $result['split'] = $splitOption;
                }
            }
        });

        // 변경 후 훅 (변경 전 스냅샷 동반 — OrderActivityLogListener 가 전/후를 기록한다)
        HookManager::doAction('sirsoft-ecommerce.order_option.after_status_change', $result['original'], $newStatus, $result['split'], $snapshot);

        // 구매확정 전이는 유저 셀프 확정(OrderService::confirmOption)과 동일하게 order-option.after_confirm 을 발화한다.
        // (즉시 적립·구매확정 활동로그 등 확정 전용 부수효과가 관리자 경로에서도 대칭 동작하도록)
        // 즉시 적립·로그 대상은 CONFIRMED 를 보유한 (병합 후) 생존 레코드 → merged_into > split > original 순으로 선택.
        if ($newStatus === OrderStatusEnum::CONFIRMED) {
            $confirmTarget = $result['merged_into'] ?? $result['split'] ?? $result['original'];
            // 변경 전 스냅샷을 함께 넘긴다 — 빼면 활동 로그의 구매확정 줄에 전/후 표가
            // 통째로 비어, 유저 셀프 확정(3인자 발화)과 같은 사건인데 관리자 경로에서만
            // "무엇이 무엇으로 바뀌었는지"를 읽을 수 없다(S2-D7).
            HookManager::doAction('sirsoft-ecommerce.order-option.after_confirm', $confirmTarget->order, $confirmTarget, $snapshot);
        }

        return $result;
    }

    /**
     * 주문 옵션에서 지정 수량을 **같은 상태의 새 행**으로 떼어 냅니다. (교환 후속 주문옵션 분할 — R10)
     *
     * 교환품을 이미 보낸 교환 행을 수량으로 쪼개면 그 행이 만든 후속 주문옵션도 같은 수량으로
     * 쪼개야 한다 — 두 교환 조각이 한 후속 옵션을 가리키면 재발송 수량이 어긋나고, 송장·도착을
     * 조각마다 따로 적을 수 없다. 상태 전이가 없는 분할이라 `changeStatusWithQuantity()` 를 쓰지
     * 않는다(자기 전이 가드·훅·적립 부수효과를 타지 않게).
     *
     * 금액 안분은 상태 변경 분할과 **같은 규칙**(`captureAmounts` → `applySplitAmounts` →
     * `applyRemainingAmounts`)이라 합계가 보존된다. 떼어 낸 행은 원본의 출처(`source_type`·
     * `source_option_id`)를 그대로 잇고 `parent_option_id` 는 비운다 — 형제 관계로 묶이면 이후 일반
     * 상태 변경의 병합(`findMergeCandidate`)이 두 조각을 합쳐, 교환 행이 가리키던 후속 옵션이
     * 사라진다. 배송 행도 원본의 송장·상태·시각을 그대로 복사한다(같은 상자에서 나뉜 것이다).
     *
     * @param  OrderOption  $option  원본 주문 옵션
     * @param  int  $quantity  떼어 낼 수량 (1 이상, 원본 수량 미만)
     * @return OrderOption 떼어 낸 새 주문 옵션
     *
     * @throws OrderClaimException 수량 범위 밖
     */
    public function splitOffQuantity(OrderOption $option, int $quantity): OrderOption
    {
        if ($quantity < 1 || $quantity >= (int) $option->quantity) {
            throw OrderClaimException::quantityInvalid();
        }

        return DB::transaction(function () use ($option, $quantity) {
            $remainingQuantity = (int) $option->quantity - $quantity;
            $ratio = $quantity / (int) $option->quantity;
            $origAmounts = $this->captureAmounts($option);

            $splitOption = $option->replicate();
            $splitOption->quantity = $quantity;
            $splitOption->parent_option_id = null;

            $this->applySplitAmounts(
                $splitOption,
                $origAmounts,
                $ratio,
                $quantity,
                MileageRounding::fromOrderSnapshot($option->order?->mileage_policy_snapshot)
            );
            $this->orderOptionRepository->save($splitOption);

            $originShipping = $this->orderShippingRepository->findFirstByOrderOptionId($option->id);

            if ($originShipping !== null) {
                $this->orderShippingRepository->replicateForOrderOption($originShipping, $splitOption->id, [
                    'shipped_at' => $originShipping->shipped_at,
                    'estimated_arrival_at' => $originShipping->estimated_arrival_at,
                    'delivered_at' => $originShipping->delivered_at,
                    'confirmed_at' => $originShipping->confirmed_at,
                ]);
            }

            $this->applyRemainingAmounts($option, $origAmounts, $splitOption, $remainingQuantity);
            $this->orderOptionRepository->save($option);

            return $splitOption;
        });
    }

    /**
     * 배송 관련 상태 전이 시 택배사·송장번호를 해당 옵션의 배송 레코드에 기록합니다.
     *
     * 관리자 주문상태 변경(일괄변경)에서 배송준비중/배송중/배송완료로 전이할 때 입력한
     * 택배사·송장번호(metadata)를 옵션 단위 배송 레코드에 반영한다. 배송 상태가 아니거나
     * metadata 에 배송정보가 없으면 아무 것도 하지 않는다(상태만 변경되는 경로 무영향).
     *
     * 배송 진행 상태·시각도 함께 옮긴다(`shippingRecordStateFor()`) — 회원 주문상세의
     * 「배송 현황」 카드가 이 레코드를 읽으므로, 송장만 쓰면 배송완료 주문이 고객에게
     * 「배송대기」로 보인다.
     *
     * @param  OrderOption  $option  상태가 변경된 옵션
     * @param  OrderStatusEnum  $newStatus  전이 목표 상태
     * @param  array  $metadata  ['carrier_id' => int, 'tracking_number' => string] 부분 배열
     * @return void 반환값 없음
     */
    private function applyShippingTracking(OrderOption $option, OrderStatusEnum $newStatus, array $metadata): void
    {
        $carrierId = $metadata['carrier_id'] ?? null;
        $trackingNumber = $metadata['tracking_number'] ?? null;

        // 배송정보가 전혀 없으면 기록할 것이 없음.
        if (($carrierId === null || $carrierId === '') && ($trackingNumber === null || $trackingNumber === '')) {
            return;
        }

        // 배송 정보가 의미 있는 상태(배송준비중/배송중/배송완료)로의 전이에만 반영.
        $shippingStatuses = [
            OrderStatusEnum::SHIPPING_READY,
            OrderStatusEnum::SHIPPING,
            OrderStatusEnum::DELIVERED,
        ];
        if (! in_array($newStatus, $shippingStatuses, true)) {
            return;
        }

        $previous = $this->orderShippingRepository->findFirstByOrderOptionId($option->id);

        $this->orderShippingRepository->updateTrackingByOrderOptionId(
            $option->id,
            array_merge(
                [
                    'carrier_id' => $carrierId,
                    'tracking_number' => $trackingNumber,
                ],
                $this->shippingRecordStateFor($newStatus)
            )
        );

        // 송장 기록·정정 전용 훅 (F11).
        //
        // 예전에는 **전이를 동반할 때만** 훅이 발화했다. 그래서 운영자가 이미 배송중인 건의
        // 송장을 고치면(상태는 그대로) 어떤 줄도 남지 않았고, 고객이 "운송장이 바뀌었다" 고
        // 물어도 언제 누가 무엇으로 고쳤는지 되짚을 근거가 없었다. 전이와는 다른 층의 사실이라
        // 상태 전이 훅에 얹지 않고 자기 훅으로 낸다.
        HookManager::doAction(
            'sirsoft-ecommerce.order_shipping.tracking_updated',
            $option,
            [
                'carrier_id' => $previous?->carrier_id,
                'tracking_number' => $previous?->tracking_number,
            ],
            [
                'carrier_id' => $carrierId,
                'tracking_number' => $trackingNumber,
            ],
        );
    }

    /**
     * 주문상태 전이에 대응하는 배송 레코드 상태·시각을 만든다.
     *
     * 배송 레코드(`ecommerce_order_shippings`)는 회원·비회원 주문상세의 「배송 현황」 카드가
     * 읽는 값이다. 송장만 기록하고 상태를 두면 배송완료된 주문이 고객에게 계속
     * 「배송대기」로 보인다. 같은 사실을 넣는 다른 경로(주문 목록 일괄 운송장 입력 ·
     * `OrderRepository::bulkUpdateShipping`)는 처음부터 `shipped` + `shipped_at` 을 함께
     * 기록하고 있었다 — 두 경로를 같은 결과로 맞춘다.
     *
     * `shipping_ready` 는 아직 발송이 아니므로 `pending` 을 유지한다(송장만 미리 끊는 운영).
     * 시각은 **이미 찍혀 있으면 덮지 않는다** — 운영자가 배송완료를 취소했다가 다시 거는 등
     * 같은 단계를 두 번 지날 때 최초 발생 시각이 사라지지 않게 한다.
     *
     * @param  OrderStatusEnum  $newStatus  전이 목표 상태
     * @return array<string, mixed> 배송 레코드에 덮어쓸 컬럼 (해당 없으면 빈 배열)
     */
    private function shippingRecordStateFor(OrderStatusEnum $newStatus): array
    {
        return match ($newStatus) {
            OrderStatusEnum::SHIPPING => [
                'shipping_status' => ShippingStatusEnum::SHIPPED->value,
                'shipped_at' => now(),
            ],
            OrderStatusEnum::DELIVERED => [
                'shipping_status' => ShippingStatusEnum::DELIVERED->value,
                'delivered_at' => now(),
            ],
            default => [],
        };
    }

    /**
     * 일괄 상태 변경 (수량 분할 지원)
     *
     * @param  array  $items  [{option_id, quantity}] 변경 대상
     * @param  OrderStatusEnum  $newStatus  변경할 상태
     * @param  int  $orderId  상위 주문 ID (옵션 소속 검증에 사용)
     * @param  array  $metadata  추가 정보
     * @return array 변경 결과
     *
     * @throws ResourceScopeMismatchException 옵션이 상위 주문에 속하지 않는 경우
     * @throws OrderClaimException 대상 옵션이 교환·반품
     *                             코스 상태이거나 목표 상태가 코스 상태인 경우 (D15 후단 —
     *                             화면 검증을 거치지 않는 내부·훅 호출까지 막는 2차 방어)
     */
    public function bulkChangeStatusWithQuantity(
        array $items,
        OrderStatusEnum $newStatus,
        int $orderId,
        array $metadata = []
    ): array {
        // 스냅샷 캡처 (ChangeDetector용)
        $optionIds = collect($items)->pluck('option_id')->filter()->unique()->toArray();
        $snapshots = $this->orderOptionRepository->getSnapshotsByIds($optionIds);

        // 상위 주문 스코프 2차 방어 — FormRequest 를 우회한 내부/훅 호출도 차단.
        // 선택 파라미터로 두면 신규 호출처가 빠뜨렸을 때 방어가 조용히 꺼지므로 필수로 받는다.
        $this->assertOptionsBelongToOrder($optionIds, $orderId);

        // 클레임 코스 상태 가드 (D15 후단) — 진행 중인 교환·반품의 상품은 주문화면에서 상태를
        // 바꿀 수 없다. FormRequest 도 같은 판정으로 422 를 내지만, 위 스코프 방어와 같은 이유로
        // FormRequest 를 우회하는 내부·훅 호출까지 막는다. 판정은 ClaimQuantityGuard 단일 출처다.
        $this->claimQuantityGuard->assertNotInClaimCourse(
            array_map(fn ($snapshot) => $snapshot['option_status'] ?? null, $snapshots)
        );

        // 목표 축 — 코스 상태는 클레임 서비스만 기록한다. FormRequest 의 adminAssignableValues 가
        // 같은 축을 막지만, 그 목록을 거치지 않는 내부·훅 호출이 신청서 없는 코스 상태를 만들면
        // 복귀·완료할 신청서가 없는 고아 행이 생긴다.
        $this->claimQuantityGuard->assertNotClaimCourseTarget($newStatus);

        // 결제완료(payment_complete) 목표 전이 시 본인인증(IDV) 정책 가드 (A8 / N4).
        // 관리자 주문상세 "주문상태 변경"(옵션 일괄변경)이 결제완료로 부모 주문을 전이시키는,
        // 실무에서 가장 흔한 결제승인 경로다. OrderService::update / bulkUpdate 와 동일하게
        // 변경 자체(트랜잭션) 전에 IDV 훅을 발화해 enforce 가 미인증 시 428 로 전이를 막는다.
        // 부모 주문의 결제수단에 따라 DBANK → 입금확인, 그 외 → 결제 승인으로 분기(결정 7).
        if ($newStatus === OrderStatusEnum::PAYMENT_COMPLETE) {
            $this->enforcePaymentCompleteIdv($optionIds);
        }

        // 일괄 변경 전 훅
        HookManager::doAction('sirsoft-ecommerce.order_option.before_bulk_status_change', $items, $newStatus);

        $results = [];
        $changedCount = 0;
        $splitCount = 0;

        // 대상 옵션을 1회 일괄 조회해 재사용한다 (항목마다 같은 행을 다시 읽지 않는다).
        // 같은 옵션이 두 번 이상 실린 요청은 앞선 변경이 이미 DB 에 반영된 뒤이므로
        // 적재본이 낡는다 — 그 경우에만 그 항목을 다시 읽어 종전 동작을 유지한다.
        $prefetchedOptions = $this->orderOptionRepository->findByIdsKeyed($optionIds);

        DB::transaction(function () use ($items, $newStatus, $metadata, $prefetchedOptions, &$results, &$changedCount, &$splitCount) {
            $consumed = [];

            foreach ($items as $item) {
                $optionId = $item['option_id'];
                $option = isset($consumed[$optionId])
                    ? $this->orderOptionRepository->findOrFail($optionId)
                    : ($prefetchedOptions->get($optionId) ?? $this->orderOptionRepository->findOrFail($optionId));
                $consumed[$optionId] = true;

                $result = $this->changeStatusWithQuantity(
                    $option,
                    $newStatus,
                    $item['quantity'],
                    $metadata
                );

                $changedCount++;
                if ($result['split'] !== null) {
                    $splitCount++;
                }

                $results[] = [
                    'order_option_id' => $option->id,
                    'split_order_option_id' => $result['split']?->id,
                    'merged_into_order_option_id' => $result['merged_into']?->id ?? null,
                    'quantity_changed' => $item['quantity'],
                    'is_full_quantity' => $item['quantity'] === $option->quantity,
                ];
            }
        });

        // 부모 주문 상태 동기화 — 대상 옵션은 위에서 모두 $orderId 소속임을 확인했다.
        // 변경 뒤에 옵션 id 로 주문을 다시 찾으면, 병합으로 지워진 행만 실린 요청(남은 1개를
        // 먼저 보낸 형제에 합치는 발송)에서 주문을 못 찾아 동기화를 건너뛴다 — 모든 상품이
        // 배송중인데 주문은 상품준비중으로 남았다(실측 2026-10-03).
        $this->syncParentOrderStatus($orderId);

        // after 훅 (스냅샷 전달)
        HookManager::doAction('sirsoft-ecommerce.order_option.after_bulk_status_change', $results, $newStatus, $snapshots);

        return [
            'changed_count' => $changedCount,
            'split_count' => $splitCount,
            'results' => $results,
        ];
    }

    /**
     * 주어진 옵션들이 모두 지정 주문에 속하는지 검증합니다.
     *
     * @param  array<int, int>  $optionIds  검증할 주문 옵션 ID 목록
     * @param  int  $orderId  상위 주문 ID
     * @return void 반환값 없음
     *
     * @throws ResourceScopeMismatchException 하나라도 다른 주문에 속하는 경우
     */
    private function assertOptionsBelongToOrder(array $optionIds, int $orderId): void
    {
        if ($optionIds === []) {
            return;
        }

        $orderIds = $this->orderOptionRepository->getOrderIdsByOptionIds($optionIds);

        foreach ($orderIds as $foundOrderId) {
            if ((int) $foundOrderId !== $orderId) {
                throw new ResourceScopeMismatchException('sirsoft-ecommerce::exceptions.order_option_not_in_order');
            }
        }
    }

    /**
     * 옵션 금액 필드를 캡처합니다.
     *
     * replicate() 전에 원본 금액을 별도 저장하여 2배 계산 버그를 방지합니다.
     *
     * @param  OrderOption  $option  캡처 대상 옵션
     * @return array 캡처된 금액 데이터
     */
    private function captureAmounts(OrderOption $option): array
    {
        return [
            'subtotal_discount_amount' => $option->subtotal_discount_amount,
            'coupon_discount_amount' => $option->coupon_discount_amount,
            'code_discount_amount' => $option->code_discount_amount,
            'subtotal_points_used_amount' => $option->subtotal_points_used_amount,
            'subtotal_deposit_used_amount' => $option->subtotal_deposit_used_amount,
            'subtotal_tax_amount' => $option->subtotal_tax_amount,
            'subtotal_tax_free_amount' => $option->subtotal_tax_free_amount,
            'subtotal_earned_points_amount' => $option->subtotal_earned_points_amount,
            // mc_* 필드
            'mc_subtotal_price' => $option->mc_subtotal_price,
            'mc_product_coupon_discount_amount' => $option->mc_product_coupon_discount_amount,
            'mc_order_coupon_discount_amount' => $option->mc_order_coupon_discount_amount,
            'mc_coupon_discount_amount' => $option->mc_coupon_discount_amount,
            'mc_code_discount_amount' => $option->mc_code_discount_amount,
            'mc_subtotal_points_used_amount' => $option->mc_subtotal_points_used_amount,
            'mc_subtotal_deposit_used_amount' => $option->mc_subtotal_deposit_used_amount,
            'mc_subtotal_tax_amount' => $option->mc_subtotal_tax_amount,
            'mc_subtotal_tax_free_amount' => $option->mc_subtotal_tax_free_amount,
            'mc_final_amount' => $option->mc_final_amount,
        ];
    }

    /**
     * 분할 레코드에 금액을 적용합니다.
     *
     * 원본 금액 × ratio 방식으로 분할 레코드의 금액을 계산합니다.
     *
     * @param  OrderOption  $splitOption  분할 레코드
     * @param  array  $origAmounts  원본 금액 캡처 데이터
     * @param  float  $ratio  분할 비율 (변경수량 / 원본수량)
     * @param  int  $quantity  분할 수량
     * @param  array{unit: string, method: string}|null  $earnRounding  적립 절사 기준 (주문 스냅샷 기준)
     * @return void 반환값 없음
     */
    private function applySplitAmounts(OrderOption $splitOption, array $origAmounts, float $ratio, int $quantity, ?array $earnRounding = null): void
    {
        $splitOption->subtotal_price = round($splitOption->unit_price * $quantity, 2);
        $splitOption->subtotal_discount_amount = round($origAmounts['subtotal_discount_amount'] * $ratio, 2);
        $splitOption->coupon_discount_amount = round($origAmounts['coupon_discount_amount'] * $ratio, 2);
        $splitOption->code_discount_amount = round($origAmounts['code_discount_amount'] * $ratio, 2);
        $splitOption->subtotal_points_used_amount = round($origAmounts['subtotal_points_used_amount'] * $ratio, 2);
        $splitOption->subtotal_deposit_used_amount = round($origAmounts['subtotal_deposit_used_amount'] * $ratio, 2);
        $splitOption->subtotal_tax_amount = round($origAmounts['subtotal_tax_amount'] * $ratio, 2);
        $splitOption->subtotal_tax_free_amount = round($origAmounts['subtotal_tax_free_amount'] * $ratio, 2);
        // 적립 포인트는 절사 기준으로 정수화 — 잔여분은 `원본 − 분할` 이라 총액은 그대로 보존된다.
        $splitOption->subtotal_earned_points_amount = MileageRounding::apply(
            $origAmounts['subtotal_earned_points_amount'] * $ratio,
            $earnRounding
        );
        $splitOption->subtotal_weight = round($splitOption->unit_weight * $quantity, 3);
        $splitOption->subtotal_volume = round($splitOption->unit_volume * $quantity, 3);
        $splitOption->subtotal_paid_amount = $splitOption->subtotal_price
            - $splitOption->subtotal_discount_amount
            - $splitOption->subtotal_points_used_amount
            - $splitOption->subtotal_deposit_used_amount;

        // mc_* 필드 비율 분할
        $mcFields = [
            'mc_subtotal_price', 'mc_product_coupon_discount_amount', 'mc_order_coupon_discount_amount',
            'mc_coupon_discount_amount', 'mc_code_discount_amount', 'mc_subtotal_points_used_amount',
            'mc_subtotal_deposit_used_amount', 'mc_subtotal_tax_amount', 'mc_subtotal_tax_free_amount',
            'mc_final_amount',
        ];

        foreach ($mcFields as $field) {
            $splitOption->{$field} = $this->splitMcField($origAmounts[$field], $ratio);
        }
    }

    /**
     * 원본 레코드에 잔여 금액을 적용합니다.
     *
     * 원본 금액 - 분할 금액 방식으로 잔여분을 계산합니다.
     *
     * @param  OrderOption  $option  원본 레코드
     * @param  array  $origAmounts  원본 금액 캡처 데이터
     * @param  OrderOption  $splitOption  분할 레코드
     * @param  int  $remainingQuantity  잔여 수량
     * @return void 반환값 없음
     */
    private function applyRemainingAmounts(OrderOption $option, array $origAmounts, OrderOption $splitOption, int $remainingQuantity): void
    {
        $option->quantity = $remainingQuantity;
        $option->subtotal_price = round($option->unit_price * $remainingQuantity, 2);
        $option->subtotal_discount_amount = $origAmounts['subtotal_discount_amount'] - $splitOption->subtotal_discount_amount;
        $option->coupon_discount_amount = $origAmounts['coupon_discount_amount'] - $splitOption->coupon_discount_amount;
        $option->code_discount_amount = $origAmounts['code_discount_amount'] - $splitOption->code_discount_amount;
        $option->subtotal_points_used_amount = $origAmounts['subtotal_points_used_amount'] - $splitOption->subtotal_points_used_amount;
        $option->subtotal_deposit_used_amount = $origAmounts['subtotal_deposit_used_amount'] - $splitOption->subtotal_deposit_used_amount;
        $option->subtotal_tax_amount = $origAmounts['subtotal_tax_amount'] - $splitOption->subtotal_tax_amount;
        $option->subtotal_tax_free_amount = $origAmounts['subtotal_tax_free_amount'] - $splitOption->subtotal_tax_free_amount;
        $option->subtotal_earned_points_amount = $origAmounts['subtotal_earned_points_amount'] - $splitOption->subtotal_earned_points_amount;
        $option->subtotal_weight = round($option->unit_weight * $remainingQuantity, 3);
        $option->subtotal_volume = round($option->unit_volume * $remainingQuantity, 3);
        $option->subtotal_paid_amount = $option->subtotal_price
            - $option->subtotal_discount_amount
            - $option->subtotal_points_used_amount
            - $option->subtotal_deposit_used_amount;

        // mc_* 필드 잔여분 = 원본 - 분할
        $mcFields = [
            'mc_subtotal_price', 'mc_product_coupon_discount_amount', 'mc_order_coupon_discount_amount',
            'mc_coupon_discount_amount', 'mc_code_discount_amount', 'mc_subtotal_points_used_amount',
            'mc_subtotal_deposit_used_amount', 'mc_subtotal_tax_amount', 'mc_subtotal_tax_free_amount',
            'mc_final_amount',
        ];

        foreach ($mcFields as $field) {
            $option->{$field} = $this->subtractMcField($origAmounts[$field], $splitOption->{$field});
        }
    }

    /**
     * 두 옵션 레코드를 병합합니다.
     *
     * 생존 레코드(survivor)에 피흡수 레코드(consumed)의 수량/금액을 합산 후
     * 의존 레코드(배송, 리뷰)를 이전하고 피흡수 레코드를 삭제합니다.
     *
     * @param  OrderOption  $survivor  생존 레코드
     * @param  OrderOption  $consumed  피흡수 레코드
     * @return void 반환값 없음
     */
    private function mergeOptions(OrderOption $survivor, OrderOption $consumed): void
    {
        // 1. 의존 레코드 이전 (cascade 삭제 방지)
        $this->orderShippingRepository->transferByOrderOptionId($consumed->id, $survivor->id);
        $this->productReviewRepository->transferByOrderOptionId($consumed->id, $survivor->id);
        // 피흡수 옵션의 구매 적립 거래도 생존 옵션으로 이전 — 병합 후 생존 옵션 기준 적립 델타 계산 정합.
        $this->mileageTransactionRepository->transferPurchaseEarnByOrderOptionId($consumed->id, $survivor->id);
        // 클레임 신청상품 4종도 이전한다 — 넷 다 `order_option_id` 에 `cascadeOnDelete()` 가 걸려
        // 있어, 이전하지 않고 피흡수 행을 지우면 **접수 때 기록한 신청상품이 조용히 사라진다**
        // (S1-D6 — 실측: 환불 5건 중 4건이 신청상품 0행 → 회원 상세 「신청 상품이 없습니다.」 ·
        //  관리자 목록 신청상품 칸 `-`). 금액·상태는 신청서 헤더에 남아 어디에도 오류가 뜨지 않는다.
        $this->transferClaimOptions($consumed->id, $survivor->id);

        // 2. 수량/금액 합산
        $amountFields = [
            'subtotal_price', 'subtotal_discount_amount', 'coupon_discount_amount',
            'code_discount_amount', 'subtotal_points_used_amount', 'subtotal_deposit_used_amount',
            'subtotal_tax_amount', 'subtotal_tax_free_amount', 'subtotal_earned_points_amount',
            'subtotal_weight', 'subtotal_volume',
        ];

        $survivor->quantity += $consumed->quantity;
        foreach ($amountFields as $field) {
            $survivor->{$field} = $survivor->{$field} + $consumed->{$field};
        }
        $survivor->subtotal_paid_amount = $survivor->subtotal_price
            - $survivor->subtotal_discount_amount
            - $survivor->subtotal_points_used_amount
            - $survivor->subtotal_deposit_used_amount;

        // 생존 행이 피흡수 행의 분할 자식이면 피흡수 행의 부모를 잇는다. 그대로 두면 아래
        // 자식 이전이 생존 행을 자기 자신의 자식(parent_option_id = 자기 id)으로 만든다
        // (실측 2026-10-03: 3개 중 2개 먼저 발송 → 남은 1개 발송 → 생존 행의 부모가 자기 자신).
        if ((int) $survivor->parent_option_id === (int) $consumed->id) {
            $survivor->parent_option_id = $consumed->parent_option_id;
        }

        // mc_* 필드 합산
        $mcFields = [
            'mc_subtotal_price', 'mc_product_coupon_discount_amount', 'mc_order_coupon_discount_amount',
            'mc_coupon_discount_amount', 'mc_code_discount_amount', 'mc_subtotal_points_used_amount',
            'mc_subtotal_deposit_used_amount', 'mc_subtotal_tax_amount', 'mc_subtotal_tax_free_amount',
            'mc_final_amount',
        ];

        foreach ($mcFields as $field) {
            $survivor->{$field} = $this->sumMcField($survivor->{$field}, $consumed->{$field});
        }

        $this->orderOptionRepository->save($survivor);

        // 3. 피흡수 레코드의 분할 자식들을 생존 레코드로 이전
        $this->orderOptionRepository->transferChildren($consumed->id, $survivor->id);

        // 4. 피흡수 레코드 삭제 (의존 레코드 이미 이전됨 → cascade 안전)
        $this->orderOptionRepository->delete($consumed);
    }

    /**
     * 병합 시 클레임 신청상품 행을 생존 옵션으로 이전합니다. (S1-D6)
     *
     * 환불·취소·반품·교환 신청상품 4종은 모두 `order_option_id` 에 `cascadeOnDelete()` 가
     * 걸려 있다. 피흡수 행을 지우기 전에 옮기지 않으면 접수 때 기록한 신청상품이 함께
     * 지워져, 신청서에 금액만 남고 **무엇을 신청했는지가 사라진다**.
     *
     * 쓰기는 Repository 가 맡는다 — 서비스가 네 모델을 직접 질의하면 데이터 접근 규율
     * (Service → RepositoryInterface)을 벗어난다(#599 validate P2 #7).
     *
     * @param  int  $fromOrderOptionId  피흡수 옵션 ID
     * @param  int  $toOrderOptionId  생존 옵션 ID
     * @return void 반환값 없음
     */
    private function transferClaimOptions(int $fromOrderOptionId, int $toOrderOptionId): void
    {
        $this->orderOptionRepository->transferClaimOptionReferences($fromOrderOptionId, $toOrderOptionId);
    }

    /**
     * mc_* JSON 필드를 비율 분할합니다.
     *
     * @param  array|null  $mcData  원본 mc 데이터
     * @param  float  $ratio  분할 비율
     * @return array|null 분할된 mc 데이터
     */
    private function splitMcField(?array $mcData, float $ratio): ?array
    {
        if (! $mcData) {
            return null;
        }

        $result = [];
        foreach ($mcData as $currency => $amount) {
            $result[$currency] = round((float) $amount * $ratio, 2);
        }

        return $result;
    }

    /**
     * mc_* JSON 필드에서 분할 금액을 차감합니다.
     *
     * @param  array|null  $original  원본 mc 데이터
     * @param  array|null  $split  분할된 mc 데이터
     * @return array|null 잔여 mc 데이터
     */
    private function subtractMcField(?array $original, ?array $split): ?array
    {
        if (! $original) {
            return null;
        }

        $result = [];
        foreach ($original as $currency => $amount) {
            $result[$currency] = round((float) $amount - (float) ($split[$currency] ?? 0), 2);
        }

        return $result;
    }

    /**
     * 결제완료 전이 대상 주문들에 대해 본인인증(IDV) 정책 가드 훅을 발화합니다 (A8 / N4).
     *
     * 옵션 일괄변경으로 부모 주문이 결제완료로 전이되기 직전, 이미 결제완료가 아닌 주문만
     * 골라 결제수단별로 IDV 훅을 발화한다(DBANK → 입금확인, 그 외 → 결제 승인). 미인증 시
     * EnforceIdentityPolicyListener 가 428(IdentityVerificationRequiredException)을 던져
     * 트랜잭션 진입 전에 변경을 차단한다. 정책 비활성(enabled=false) 시에는 enforce 가 통과시킨다.
     *
     * @param  array<int>  $optionIds  변경 대상 옵션 ID 배열
     * @return void 반환값 없음
     */
    private function enforcePaymentCompleteIdv(array $optionIds): void
    {
        $orderIds = $this->orderOptionRepository->getOrderIdsByOptionIds($optionIds);

        // 주문과 결제수단을 1회 일괄 조회한다. 주문마다 조회하면 결제수단 지연 로드까지
        // 따라붙어 대상 주문 수만큼 쿼리가 두 배로 늘어난다.
        $orders = $this->orderRepository->findByIdsWithRelationsKeyed($orderIds, ['payment']);

        foreach ($orderIds as $orderId) {
            $order = $orders->get($orderId);
            if (! $order) {
                continue;
            }

            // 이미 결제완료인 주문은 전이가 일어나지 않으므로 가드 대상에서 제외.
            $currentStatus = $order->order_status instanceof OrderStatusEnum
                ? $order->order_status->value
                : $order->order_status;
            if ($currentStatus === OrderStatusEnum::PAYMENT_COMPLETE->value) {
                continue;
            }

            $paymentMethod = $order->payment?->payment_method;
            $paymentMethod = $paymentMethod instanceof PaymentMethodEnum
                ? $paymentMethod->value
                : $paymentMethod;
            $idvHook = $paymentMethod === PaymentMethodEnum::DBANK->value
                ? 'sirsoft-ecommerce.payment.before_confirm_deposit'
                : 'sirsoft-ecommerce.payment.before_approve';
            HookManager::doAction($idvHook, $order);
        }
    }

    /**
     * 부모 주문 상태를 자식 옵션 상태에 맞게 동기화합니다.
     *
     * - 활성 옵션(취소 제외)이 모두 동일 상태 → 주문도 해당 상태로 변경
     * - 혼합 상태 → 가장 낮은 진행 단계(보수적)로 설정
     * - 활성 옵션이 0개(전부 취소) → 주문도 취소(CANCELLED)로 전이
     *
     * 부분취소는 별도 주문 상태가 아니라 "취소 옵션 + 잔여 활성 옵션 공존" 파생 상태다.
     * 따라서 주문 상태는 항상 잔여 활성 옵션 기준으로 결정하고, 부분취소 여부는
     * Order::isPartiallyCancelled() 파생 플래그로 노출한다 (2026-06-22, partial_cancelled 제거).
     *
     * @param  int  $orderId  동기화할 주문 ID
     * @return void 반환값 없음
     */
    public function syncParentOrderStatus(int $orderId): void
    {
        $order = $this->orderRepository->find($orderId);
        if (! $order) {
            return;
        }

        // 취소/클레임 등 별도 라이프사이클을 제외한 활성 옵션의 상태 값 목록 (string)
        // 제외 목록은 OrderStatusEnum SSoT 로 일원화한다.
        $activeStatusValues = $order->options()
            ->whereNotIn('option_status', OrderStatusEnum::syncExcludedValues())
            ->pluck('option_status')
            ->map(fn ($s) => $s instanceof OrderStatusEnum ? $s->value : $s)
            ->unique()
            ->values();

        if ($activeStatusValues->isEmpty()) {
            // 활성 옵션이 하나도 없으면 주문 전체가 취소된 것 → CANCELLED 로 전이.
            // (옵션이 아예 없는 비정상 데이터는 전이 대상에서 제외.)
            if ($order->options()->exists()) {
                $this->transitionOrderStatus($order, OrderStatusEnum::CANCELLED);
            }

            return;
        }

        if ($activeStatusValues->count() === 1) {
            // 모든 활성 옵션이 동일 상태
            $newStatus = OrderStatusEnum::from($activeStatusValues->first());
        } else {
            // 혼합 상태 → 진행 순서상 가장 낮은 상태 (보수적)
            // 진행 순서는 OrderStatusEnum SSoT 로 일원화한다 (전이 규칙과 동일 배열 공유).
            $progressOrder = OrderStatusEnum::progressOrderValues();

            $lowestIndex = PHP_INT_MAX;
            foreach ($activeStatusValues as $statusValue) {
                $index = array_search($statusValue, $progressOrder);
                if ($index !== false && $index < $lowestIndex) {
                    $lowestIndex = $index;
                }
            }

            $newStatus = OrderStatusEnum::from($progressOrder[$lowestIndex]);
        }

        $this->transitionOrderStatus($order, $newStatus);
    }

    /**
     * 주문 상태를 목표 상태로 전이하고 변경 시 상태변경 알림 훅을 발화합니다.
     *
     * syncParentOrderStatus 의 잔여옵션 파생 전이와 전부취소(CANCELLED) 전이가 공유하는 헬퍼.
     *
     * @param  Order  $order  대상 주문
     * @param  OrderStatusEnum  $newStatus  목표 주문 상태
     * @return void 반환값 없음
     */
    private function transitionOrderStatus($order, OrderStatusEnum $newStatus): void
    {
        if ($order->order_status === $newStatus) {
            return;
        }

        $previousStatus = $order->order_status?->value;
        $orderUpdate = ['order_status' => $newStatus->value];
        // 주문 전체가 구매확정으로 전이되면 헤더에도 확정 시점 기록 (유저 셀프 확정 OrderService::confirmOption 과 대칭).
        // 멱등: 이미 값이 있으면 유지(최초 확정 시점 보존).
        $purchaseConfirmed = $newStatus === OrderStatusEnum::CONFIRMED && $order->confirmed_at === null;
        if ($purchaseConfirmed) {
            $orderUpdate['confirmed_at'] = now();
        }

        // 결제가 이미 끝난 주문인데 헤더 금액이 미결제로 남아 있으면 여기서 정합화한다 (S2-D4).
        //
        // 「입금 내역만 기록」(confirmManualDeposit 의 mark_order_complete=false)은 payment 만
        // PAID 로 만들고 주문은 결제대기로 남긴다 — 설계대로다. 그러나 운영자가 그 뒤 상품 상태를
        // 일괄 전이로 올리면 이 경로는 completePayment() 를 타지 않으므로 total_paid_amount 가
        // 0 인 채로 굳는다. 그 시점엔 [입금확인] 이 이미 사라져 있어(payment 가 PAID 라
        // ConfirmDepositRequest 가 422) **화면에서 되돌릴 방법이 없다**.
        // 장부상 미결제이므로 이후 모든 환불이 refund_amount=0 으로 떨어진다.
        //
        // 결제 이후 상태로 올라가는 전이에서만, 그리고 결제 레코드가 실제로 PAID 일 때만 맞춘다 —
        // 미입금 주문을 상태만 올리는 경우까지 결제된 것으로 만들지 않는다.
        // 「결제완료로 올라갈 때」로만 좁히면 결제대기 → 상품준비중처럼 결제완료를 **건너뛰는** 일괄
        // 전이(전이 규칙이 허용한다)에서 같은 구멍이 그대로 남는다(2026-09-17 실측 주문 1496 —
        // 결제행 paid · 주문 결제금액 0 · [입금확인] 422 · 이후 환불 전부 0원). 취소로 내려가는 전이는
        // 아래 S1-D17 블록이 따로 다룬다.
        $order->loadMissing('payment');
        $paymentSettled = $order->payment?->payment_status === PaymentStatusEnum::PAID;
        $headerUnpaid = (float) $order->total_paid_amount <= 0.0 && (float) $order->total_due_amount > 0.0;
        $movesPastPayment = $newStatus->isPastPayment();

        if ($movesPastPayment && $paymentSettled && $headerUnpaid) {
            // 실제로 수납된 금액은 결제 레코드가 들고 있다 — 미수금(total_due_amount)을 그대로
            // 옮기지 않고 payment 의 수납액을 쓴다(부분 입금·금액 정정이 있어도 장부가 어긋나지 않는다).
            $paidAmount = $this->resolveSettledBaseAmount($order);

            $orderUpdate['total_paid_amount'] = $paidAmount;
            $orderUpdate['total_due_amount'] = max(0.0, (float) $order->total_due_amount - $paidAmount);
            $orderUpdate['paid_at'] = $order->paid_at ?? $order->payment->paid_at ?? now();
            // mc_total_paid_amount 는 여기서 파생하지 않는다 — 통화 스냅샷 환산은
            // OrderProcessingService 가 소유한다(SSoT). 이 경로는 base 금액만 정합화한다.
        }

        // S1-D17: 취소로 내려가는 전이는 미수금을 함께 내린다 — 취소된 주문은 받을 돈이 없다.
        // 이 경로는 **부분취소가 누적되어 잔여 활성 옵션이 0 이 된** 경우다
        // (`syncParentOrderStatus`). 전체취소 확정 블록(`OrderCancellationService`)과는 **다른 입구**라,
        // 그쪽만 닫으면 미입금 주문이 부분취소 누적로 들어올 때 또 미수금이 남는다.
        if ($newStatus === OrderStatusEnum::CANCELLED && (int) $order->total_due_amount > 0) {
            $orderUpdate['total_due_amount'] = 0;
        }

        $order->update($orderUpdate);

        // 옵션별 일괄 상태변경(운송장 등)으로 부모 주문 상태가 전이되면 알림 훅 발화 (A35/A36/C3).
        // OrderStatusNotificationListener 가 결제완료/배송중/배송완료/구매확정 알림으로 매핑한다.
        // 목표 상태($newStatus->value)를 스칼라로 명시 전달 — 큐 지연 재로드 오매핑 방지 (N1).
        HookManager::doAction('sirsoft-ecommerce.order.after_status_change', $order->fresh(), $previousStatus, $newStatus->value);

        // 파생 전이 이력 — 운영자가 고른 전이가 아니라 상품 상태에서 따라온 결과이므로
        // 활동 로그에 별도 줄(전/후 포함)로 남긴다. `after_status_change` 는 알림 전용 축이라
        // 그쪽에 로그를 붙이면 단건 수정·일괄 변경에서 한 전이가 두 줄이 된다.
        HookManager::doAction('sirsoft-ecommerce.order.after_status_sync', $order->fresh(), $previousStatus, $newStatus->value);

        // 구매확정 훅 — order.confirmed_at 이 최초로 세팅된 순간에만 1회 발화(멱등).
        // 기존 order.after_confirm 은 completePayment 안의 "결제완료" 훅이지 구매확정 훅이 아니다(이름 혼동 주의).
        // PurgeCashReceiptIdentifierListener 가 구독해 재발급용 식별번호 암호문을 폐기한다.
        if ($purchaseConfirmed) {
            HookManager::doAction('sirsoft-ecommerce.order.after_purchase_confirmed', $order->fresh());
        }
    }

    /**
     * 결제행이 수납한 금액을 **기준 통화**로 반환합니다. (헤더 결제금액 정합화 전용 — S2-D4)
     *
     * 주문 헤더의 `total_paid_amount` 는 기준 통화 금액이다. 그래서 결제행의 기준 통화 수납액
     * (`paid_amount_base`)만 옮길 수 있다 — 결제 통화 금액(`paid_amount_local`)은 결제 통화가
     * 기준 통화와 같을 때만 같은 단위다. 종전에는 `base ?: local` 로 떨어져 USD 50 이 헤더에
     * 50 원으로 적히고, `(int)` 캐스팅이 소수 통화의 센트까지 잘라냈다(#599 validate P2 #6).
     * 단위를 세울 수 없으면 미수금(`total_due_amount` — 기준 통화)을 쓴다.
     *
     * @param  Order  $order  주문 (payment 로드됨)
     * @return float 기준 통화 수납액
     */
    private function resolveSettledBaseAmount(Order $order): float
    {
        $payment = $order->payment;
        $base = (float) ($payment->paid_amount_base ?? 0);

        if ($base > 0) {
            return $base;
        }

        $baseCurrency = $this->currencyService->resolveOrderBaseCurrency($order);
        $local = (float) ($payment->paid_amount_local ?? 0);

        if ($local > 0 && ($payment->currency === null || $payment->currency === $baseCurrency)) {
            return $local;
        }

        return (float) $order->total_due_amount;
    }

    /**
     * mc_* JSON 필드를 합산합니다.
     *
     * @param  array|null  $a  첫 번째 mc 데이터
     * @param  array|null  $b  두 번째 mc 데이터
     * @return array|null 합산된 mc 데이터
     */
    private function sumMcField(?array $a, ?array $b): ?array
    {
        if (! $a && ! $b) {
            return null;
        }

        $merged = $a ?? [];
        foreach ($b ?? [] as $currency => $amount) {
            $merged[$currency] = round(($merged[$currency] ?? 0) + (float) $amount, 2);
        }

        return $merged;
    }
}
