<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\DTO\CancellationResult;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundOptionStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimReasonRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;

/**
 * 취소·환불 신청 분기 서비스 (S5 · D2)
 *
 * 유저의 [취소/환불신청] 은 하나의 신청서 작성 화면이지만 서버에서는 **상품 상태로 자동 분기**한다:
 *
 *  - 선택 상품이 전부 `claim.instant_cancel_statuses`(기본 결제완료)에 있으면 **즉시취소** — 기존 취소
 *    파이프라인(`OrderCancellationService::cancelOrderOptions`)을 그대로 실행해 취소신청서·환불을
 *    한 번에 끝낸다(무수정 재사용).
 *  - 전부 `claim.refund_request_statuses`(기본 상품준비~배송중)에 있으면 **환불신청서**(`order_refunds`,
 *    출처 `direct`)를 `REQUESTED` 로 접수하고 운영자 승인 절차(S6b 환불 실행)를 기다린다.
 *  - 두 분기에 걸치면 거절한다(`claim_refund_branch_mixed`) — 한 신청서에 즉시 환불된 상품과 심사 대기
 *    상품이 섞이면 고객이 보는 환불 예정액과 실제 환불액이 갈린다.
 *
 * 직접 환불신청서는 원 주문옵션을 판매 상태 그대로 둔다(코스 상태 없음). 그래서 그 수량의 이중 신청은
 * `ClaimQuantityGuard` 의 환불 축(`sumActiveDirectQuantityByOrderOption`)이 막고, 승인·환불 실행(S6b)
 * 시점에 `OrderCancellationService` 가 수량을 분할·취소한다.
 */
class OrderRefundRequestService
{
    /**
     * @param  OrderCancellationService  $cancellationService  취소 서비스 (즉시취소 분기·환불 미리보기)
     * @param  ClaimQuantityGuard  $claimQuantityGuard  수량 잠금 가드
     * @param  RefundExecutionService  $refundExecutionService  환불 실행 공용 서비스 (수단·안분 규칙)
     * @param  CancellableAmountCalculator  $cancellableCalculator  취소 가능 금액 산정기 (D19 — 저장·실행 가드)
     * @param  SequenceService  $sequenceService  채번 서비스
     * @param  EcommerceSettingsService  $settingsService  설정 서비스
     * @param  CurrencyConversionService  $currencyService  통화 변환 서비스
     * @param  OrderRepositoryInterface  $orderRepository  주문 Repository
     * @param  OrderRefundRepositoryInterface  $refundRepository  환불신청서 Repository
     * @param  OrderRefundOptionRepositoryInterface  $refundOptionRepository  환불 상품 행 Repository
     * @param  ClaimReasonRepositoryInterface  $claimReasonRepository  사유 카탈로그 Repository
     */
    public function __construct(
        protected OrderCancellationService $cancellationService,
        protected ClaimQuantityGuard $claimQuantityGuard,
        protected RefundExecutionService $refundExecutionService,
        protected CancellableAmountCalculator $cancellableCalculator,
        protected SequenceService $sequenceService,
        protected EcommerceSettingsService $settingsService,
        protected CurrencyConversionService $currencyService,
        protected OrderRepositoryInterface $orderRepository,
        protected OrderRefundRepositoryInterface $refundRepository,
        protected OrderRefundOptionRepositoryInterface $refundOptionRepository,
        protected ClaimReasonRepositoryInterface $claimReasonRepository,
    ) {}

    /**
     * 선택 상품의 취소·환불 분기를 판정합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $items  신청 대상 [{order_option_id, quantity}]
     * @return string 분기 (`cancel` | `refund`)
     *
     * @throws OrderClaimException 대상 부재 / 분기 혼재 / 신청 불가 상태
     */
    public function resolveBranch(Order $order, array $items): string
    {
        if (empty($items)) {
            throw OrderClaimException::itemsRequired();
        }

        $order->loadMissing('options');

        $branches = [];

        foreach ($items as $item) {
            $option = $order->options->firstWhere('id', (int) ($item['order_option_id'] ?? 0));

            if (! $option) {
                throw OrderClaimException::optionNotFound();
            }

            $branch = $this->branchForStatus($option->option_status);

            if ($branch === null) {
                throw OrderClaimException::refundNotRequestable(
                    $option->option_status instanceof OrderStatusEnum ? $option->option_status->label() : (string) $option->option_status,
                );
            }

            $branches[$branch] = true;
        }

        if (count($branches) > 1) {
            throw OrderClaimException::refundBranchMixed();
        }

        return array_key_first($branches);
    }

    /**
     * 이 주문의 환불이 계좌 이체로 나가는지 판정합니다. (신청 화면의 환불계좌 입력 노출 근거)
     *
     * 어느 결제수단이 계좌 환불인지는 `PaymentMethodResolver::refundMethod()` 가 정한다 — 확장
     * 결제수단은 자기 카탈로그에 `refund_method` 를 선언하고, 미선언 수단은 PG 필요 여부로 갈린다.
     * 화면이 결제수단 코드를 보고 그 규칙을 재현하면 확장이 수단을 더할 때마다 판정이 갈리고,
     * 어긋난 쪽은 오류 없이 "계좌 입력칸이 없는 계좌 환불"(승인 후 관리자가 다시 받아야 한다)
     * 또는 "PG 환불인데 계좌를 요구"(구매자가 쓸모없는 정보를 넣는다)가 된다.
     *
     * @param  Order  $order  주문
     * @return bool 계좌 이체 환불이면 true
     */
    public function refundsToBankAccount(Order $order): bool
    {
        return $this->refundExecutionService->determineRefundMethod($order) === RefundMethodEnum::BANK;
    }

    /**
     * 주문옵션 상태 하나의 분기를 돌려줍니다. (신청 대상이 아니면 null)
     *
     * 두 설정 목록에 같은 상태가 들어 있으면 즉시취소가 이긴다 — 취소 가능 상태는 환불 심사를 거칠
     * 이유가 없다.
     *
     * @param  OrderStatusEnum|string|null  $status  주문옵션 상태
     * @return string|null `cancel` | `refund` | null
     */
    public function branchForStatus(OrderStatusEnum|string|null $status): ?string
    {
        $value = $status instanceof OrderStatusEnum ? $status->value : (string) $status;

        if (in_array($value, $this->instantCancelStatusValues(), true)) {
            return 'cancel';
        }

        if (in_array($value, $this->refundRequestStatusValues(), true)) {
            return 'refund';
        }

        return null;
    }

    /**
     * 취소·환불 신청의 환불 예정액을 미리 계산합니다. (DB 변경 없음)
     *
     * 분기와 무관하게 같은 산식(`OrderCancellationService::previewRefund`)을 쓴다 — 즉시취소와 환불신청서의
     * 환불액 산정이 갈리면 같은 상품이 어느 날은 얼마, 다른 날은 얼마로 보인다. 신청 자격(상태·수량·잠금)은
     * 신청과 같은 가드로 판정해 신청할 수 없는 조합의 금액을 보여 주지 않는다.
     *
     * @param  Order  $order  주문
     * @param  array  $items  신청 대상 [{order_option_id, quantity}]
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @param  int|null  $excludeRefundId  수량 잠금 판정에서 제외할 환불신청서 ID (자기 신청서 수정 미리보기)
     * @return array{branch: string, adjustment: AdjustmentResult} 분기 + 환불 계산 결과
     *
     * @throws OrderClaimException 신청 자격 위반
     */
    public function previewRefund(Order $order, array $items, RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST, ?int $excludeRefundId = null): array
    {
        $branch = $this->resolveBranch($order, $items);

        $resolved = $this->claimQuantityGuard->resolveClaimItems(
            $order,
            $items,
            ClaimTypeEnum::REFUND,
            $this->requestableStatusValues(),
            null,
            null,
            $excludeRefundId,
        );

        return [
            'branch' => $branch,
            // 클레임 잠금 재검사(guardClaimLock)를 끈다 — 위 resolveClaimItems 가 자기 신청서 제외
            // (excludeRefundId)까지 반영한 같은 가드를 이미 통과했다. 켜 두면 수정 미리보기가
            // 자기 신청서의 잠금에 막힌다.
            'adjustment' => $this->cancellationService->previewRefund($order, $this->toCancelItems($resolved), $refundPriority, guardClaimLock: false),
        ];
    }

    /**
     * 취소·환불을 신청합니다. (D2 자동 분기)
     *
     * @param  Order  $order  주문
     * @param  array  $payload  신청 페이로드
     *                          - items: [{order_option_id, quantity}]
     *                          - reason_code: 사유 코드 (type=refund)
     *                          - reason_detail: 상세 사유 (선택)
     *                          - requested_by: 신청자 ID (비회원이면 null)
     *                          - refund_priority: RefundPriorityEnum (선택)
     *                          - refund_bank: 환불 계좌 (선택)
     * @return array{branch: string, cancel: ?CancellationResult, refund: ?OrderRefund} 분기 결과
     *
     * @throws OrderClaimException 신청 자격·사유 위반
     * @throws OrderCancellationException 즉시취소 파이프라인 거절
     */
    public function request(Order $order, array $payload): array
    {
        $items = $payload['items'] ?? [];
        $reason = $this->resolveReason((string) ($payload['reason_code'] ?? ''));
        $refundPriority = $payload['refund_priority'] ?? RefundPriorityEnum::PG_FIRST;
        $branch = $this->resolveBranch($order, $items);

        if ($branch === 'cancel') {
            // 잠금은 형제 분기와 같은 이유로 잔여 수량을 읽기 전에 잡는다. 이 잠금이 닫는 것은
            // **즉시취소 ↔ 클레임 신청** 방향이다 — 클레임 신청 쪽은 판정부터 INSERT 까지 같은
            // 트랜잭션에서 이 행을 쥐고 있으므로, 여기서 그 행을 기다리면 상대의 신청서를 보고 판정한다.
            //
            // **즉시취소 ↔ 즉시취소** 방향은 이것으로 닫히지 않는다. 취소의 쓰기는 취소 파이프라인이
            // 자기 트랜잭션에서 하고 그 안에 PG 환불 호출이 있어(`OrderCancellationService`), 판정부터
            // 쓰기까지 이 행을 쥐려면 PG 응답을 기다리는 동안 주문 행이 잠긴다. 그 구조 변경은 취소
            // 파이프라인 소유 세션의 몫이며, 여기서 잠금을 늘려 흉내 내지 않는다.
            $cancelItems = DB::transaction(function () use ($order, $items) {
                $lockedOrder = $this->orderRepository->findForUpdate($order->id) ?? $order;
                $lockedOrder->load(['options', 'shippings', 'payment']);

                return $this->toCancelItems($this->claimQuantityGuard->resolveClaimItems(
                    $lockedOrder,
                    $items,
                    ClaimTypeEnum::CANCEL,
                    $this->instantCancelStatusValues(),
                ));
            });

            $result = $this->cancellationService->cancelOrderOptions(
                order: $order,
                cancelItems: $cancelItems,
                reason: $reason->code,
                reasonDetail: $payload['reason_detail'] ?? null,
                cancelledBy: $payload['requested_by'] ?? null,
                refundPriority: $refundPriority,
                refundBankInfo: $payload['refund_bank'] ?? null,
            );

            return ['branch' => 'cancel', 'cancel' => $result, 'refund' => null];
        }

        $refund = DB::transaction(function () use ($order, $items, $reason, $payload, $refundPriority) {
            // 잠금은 잔여 수량을 읽기 전에 잡는다 — 읽고 나서 잠그면 이미 두 요청이 같은 값을 봤다.
            $lockedOrder = $this->orderRepository->findForUpdate($order->id) ?? $order;
            $lockedOrder->load(['options', 'shippings', 'payment']);

            $resolved = $this->claimQuantityGuard->resolveClaimItems(
                $lockedOrder,
                $items,
                ClaimTypeEnum::REFUND,
                $this->refundRequestStatusValues(),
            );

            $cancelItems = $this->toCancelItems($resolved);
            // resolveClaimItems 가 잠금 가드를 이미 통과했다 (미리보기와 같은 이유로 재검사 생략).
            $adjustment = $this->cancellationService->previewRefund($lockedOrder, $cancelItems, $refundPriority, guardClaimLock: false);
            $currencySnapshot = $lockedOrder->currency_snapshot ?? [];

            $refund = $this->refundRepository->create([
                'order_id' => $lockedOrder->id,
                'refund_source_type' => RefundSourceTypeEnum::DIRECT,
                'refund_source_id' => null,
                'refund_number' => $this->sequenceService->generateCode(SequenceType::REFUND),
                'refund_status' => RefundStatusEnum::REQUESTED,
                'refund_method' => $this->refundExecutionService->determineRefundMethod($lockedOrder),
                'reason_code' => $reason->code,
                'reason_detail' => $payload['reason_detail'] ?? null,
                'fault_type' => $reason->fault_type,
                'requested_by' => $payload['requested_by'] ?? null,
                'refund_amount' => max(0, $adjustment->refundAmount),
                'refund_points_amount' => $adjustment->refundPointsAmount,
                'refund_shipping_amount' => $adjustment->shippingDifference,
                'mc_refund_amount' => $adjustment->mcRefundAmount,
                'mc_refund_points_amount' => $adjustment->mcRefundPointsAmount,
                'mc_refund_shipping_amount' => $adjustment->mcRefundShippingAmount,
                'original_calculation_snapshot' => $adjustment->originalSnapshot,
                'recalculated_snapshot' => $adjustment->recalculatedSnapshot,
                'refund_bank_code' => $payload['refund_bank']['bank_code'] ?? null,
                'refund_bank_account' => $payload['refund_bank']['account_number'] ?? null,
                'refund_bank_holder' => $payload['refund_bank']['holder'] ?? null,
            ]);

            foreach ($this->refundExecutionService->buildRefundOptionRows($lockedOrder, $cancelItems, $adjustment) as $row) {
                $this->refundOptionRepository->create(array_merge(['order_refund_id' => $refund->id], $row));
            }

            return $refund->fresh(['refundOptions']);
        });

        HookManager::doAction('sirsoft-ecommerce.claim.refund.requested', $refund, $order->fresh());

        if ((bool) $this->settingsService->getSetting('claim.auto_approve_refund_request', false)) {
            $refund = $this->transition($refund, RefundStatusEnum::APPROVED);
        }

        return ['branch' => 'refund', 'cancel' => null, 'refund' => $refund];
    }

    /**
     * 신청자가 환불신청서를 철회합니다.
     *
     * @param  OrderRefund  $refund  환불신청서
     * @return OrderRefund 철회된 환불신청서
     *
     * @throws OrderClaimException 철회 불가 상태
     */
    public function withdraw(OrderRefund $refund): OrderRefund
    {
        if ($refund->refund_source_type !== RefundSourceTypeEnum::DIRECT || ! $refund->isWithdrawable()) {
            throw OrderClaimException::notWithdrawable();
        }

        return $this->transition($refund, RefundStatusEnum::WITHDRAWN);
    }

    /**
     * 승인 전 환불신청서의 사유·상세 사유를 수정합니다. (D13)
     *
     * @param  OrderRefund  $refund  환불신청서
     * @param  array  $payload  수정 페이로드 (reason_code? / reason_detail?)
     * @return OrderRefund 수정된 환불신청서
     *
     * @throws OrderClaimException 수정 불가 상태 / 사유 부재
     */
    public function updateRequest(OrderRefund $refund, array $payload): OrderRefund
    {
        if ($refund->refund_source_type !== RefundSourceTypeEnum::DIRECT || ! $refund->refund_status->isWithdrawable()) {
            throw OrderClaimException::notEditable();
        }

        $updates = [];

        if (array_key_exists('reason_code', $payload) && $payload['reason_code'] !== null) {
            $reason = $this->resolveReason((string) $payload['reason_code']);
            $updates['reason_code'] = $reason->code;
            $updates['fault_type'] = $reason->fault_type;
        }

        if (array_key_exists('reason_detail', $payload)) {
            $updates['reason_detail'] = $payload['reason_detail'];
        }

        if ($updates === []) {
            return $refund;
        }

        return $this->refundRepository->update($refund, $updates);
    }

    /**
     * 관리자가 환불신청서의 환불액을 수정합니다. (D18 — 저장 축)
     *
     * PG 환불액·마일리지 환불액을 수단별로 받고, 상한은 취소 가능 금액(D19)이다 — 초과는
     * 저장·실행 모두 422 로 차단한다. 취소 발(`cancel`) 환불은 취소 파이프라인 산출값이라
     * 수정 대상이 아니다.
     *
     * @param  OrderRefund  $refund  환불신청서
     * @param  array  $amounts  수정값 (refund_amount? / refund_points_amount? / refund_method?)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderRefund 갱신된 환불신청서
     *
     * @throws OrderClaimException 수정 불가 상태 / 음수 / D19 상한 초과 / 비회원 마일리지
     */
    public function updateAmounts(OrderRefund $refund, array $amounts, ?int $processedBy = null): OrderRefund
    {
        $refundId = (int) $refund->id;

        // 잠금은 D19 상한을 읽기 전에 잡는다 — 접수 경로와 같은 규율이다. 축이 둘이다:
        // ⓐ 신청서 행 — 같은 신청서를 두 운영자가 동시에 수정하는 read-modify-write 차단,
        // ⓑ 주문 행 — 상한의 ③ 축은 "다른 미실행 클레임의 저장 금액 합" 이라 주문 전역
        //    집계다. 자기 행만 잠그면 같은 주문의 서로 다른 두 신청서가 서로 다른 행을
        //    잠그고 나란히 통과해 합계가 D19 를 넘긴 채로 저장된다. 직렬화 지점은 주문
        //    행이며, 형제 `executeRefund()` · 접수 경로와 같다(잠금 순서도 신청서 → 주문).
        return DB::transaction(function () use ($refundId, $amounts, $processedBy) {
            $refund = $this->refundRepository->findForUpdate($refundId);

            if (! $refund instanceof OrderRefund) {
                throw OrderClaimException::claimNotFound();
            }

            if ($refund->refund_source_type === RefundSourceTypeEnum::CANCEL) {
                throw OrderClaimException::refundNotExecutable();
            }

            if ($refund->refund_status === RefundStatusEnum::PROCESSING || $refund->refund_status->isFinal()) {
                throw OrderClaimException::amountsNotEditable();
            }

            $refund->loadMissing('order');
            $order = $this->orderRepository->findForUpdate($refund->order_id) ?? $refund->order;

            $updates = $this->normalizeRefundAmountUpdates($order, $refund, $amounts);

            $finalAmount = (float) ($updates['refund_amount'] ?? $refund->refund_amount);
            $finalPoints = (float) ($updates['refund_points_amount'] ?? $refund->refund_points_amount);

            $this->assertWithinCancellable($order, $refund, $finalAmount + $finalPoints);

            if ($updates === []) {
                return $refund;
            }

            if ($processedBy !== null) {
                $updates['processed_by'] = $processedBy;
            }

            return $this->refundRepository->update($refund, $updates);
        });
    }

    /**
     * 승인된 환불신청서의 환불을 실행합니다. ('환불하기' — D18·D26·S6b)
     *
     * 실행 본문에 금액이 실려 있으면 저장과 같은 검증을 거쳐 먼저 반영한다 — D18 의
     * "[환불하기]는 화면에 입력된 값으로 실행한다(저장 없이 실행 가능)". 실행 직전에 취소 가능
     * 금액(D19)을 같은 식으로 재계산해 동시 처리를 차단하고, 한 트랜잭션 안에서 다음을 수행한다:
     *
     * 1. (직접 환불신청) 선점만 하던 수량을 취소 상태로 분할·전이 — `OrderCancellationService`
     *    의 분할 규칙 한 벌을 재사용한다. 재고 복원(설정 준수)·쿠폰 조건 소멸 복원 동반
     * 2. PG 환불 실행 + 주문 환불 누적(`total_refunded_amount`)·결제행 취소 누적
     *    (`RefundExecutionService::execute` — 클레임 환불 누적의 단일 지점, §4-3-1)
     * 3. 사용 마일리지 환급 (`order_refund_id` 멱등 축)
     * 4. 환불신청서·상품 행 완료 확정 — PG 경로는 `executePgRefund` 가 완료를 찍고,
     *    비-PG 경로(무통장·마일리지 전액)는 여기서 확정한다 (운영자의 [환불하기]가 곧
     *    수동 송금 완료 선언이다)
     *
     * PG 훅 실행이 트랜잭션 안에 있는 것은 취소 파이프라인과 같은 관례다 — 실패 예외가
     * 누적·분할을 함께 롤백한다.
     *
     * 이미 실행됐거나 실행 중인 건의 재실행(더블클릭)은 `claim_refund_already_executed` 로
     * 거절한다 — 컨트롤러가 이 사유만 409 로 바꾼다.
     *
     * @param  OrderRefund  $refund  환불신청서 (승인 상태)
     * @param  array  $overrides  실행 시점 금액 (refund_amount? / refund_points_amount? / refund_method?)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderRefund 실행 완료된 환불신청서
     *
     * @throws OrderClaimException 실행 불가 출처·상태 / D19 상한 초과 / 재실행
     * @throws OrderCancellationException PG 환불 실패
     */
    public function executeRefund(OrderRefund $refund, array $overrides = [], ?int $processedBy = null): OrderRefund
    {
        if ($refund->refund_source_type === RefundSourceTypeEnum::CANCEL) {
            throw OrderClaimException::refundNotExecutable();
        }

        $refundId = $refund->id;

        $executed = DB::transaction(function () use ($refundId, $overrides, $processedBy) {
            // 잠금 후 상태를 다시 판정한다 — 동시 실행 두 건 중 나중 것이 완료 상태를 보고 물러난다.
            $refund = $this->refundRepository->findForUpdate($refundId);

            if (! $refund) {
                throw OrderClaimException::claimNotFound();
            }

            if (in_array($refund->refund_status, [RefundStatusEnum::PROCESSING, RefundStatusEnum::COMPLETED], true)) {
                throw OrderClaimException::refundAlreadyExecuted();
            }

            if ($refund->refund_status !== RefundStatusEnum::APPROVED) {
                throw OrderClaimException::transitionNotAllowed(
                    $refund->refund_status->label(),
                    RefundStatusEnum::COMPLETED->label(),
                );
            }

            $order = $this->orderRepository->findForUpdate($refund->order_id) ?? $refund->order;
            $order->load(['options', 'shippings', 'payment', 'payments']);

            // D18 — 실행 본문의 금액을 저장과 같은 검증으로 반영 (저장 없이 실행 가능)
            $updates = $this->normalizeRefundAmountUpdates($order, $refund, $overrides);

            if ($updates !== []) {
                $refund = $this->refundRepository->update($refund, $updates);
            }

            $amount = (float) $refund->refund_amount;
            $points = (float) $refund->refund_points_amount;

            // 실행 직전 D19 재계산 가드 — 저장 시점과 같은 식 (동시 처리 차단)
            $this->assertWithinCancellable($order, $refund, $amount + $points);

            $refund->loadMissing('refundOptions');

            $items = [];
            foreach ($refund->refundOptions as $option) {
                $items[] = [
                    'order_option_id' => $option->order_option_id,
                    'cancel_quantity' => (int) $option->quantity,
                ];
            }

            $couponRestore = null;

            if ($refund->refund_source_type === RefundSourceTypeEnum::DIRECT && $items !== []) {
                // 직접 환불신청 — 선점만 하던 수량이 이 시점에 판매 흐름에서 실제로 빠진다.
                // 재계산은 쿠폰 조건 소멸 복원 대상을 판정하기 위한 것이며, 환불 금액의 정본은
                // 신청서 저장값(수정 반영분)이다. 잠금 가드 재검사는 끈다 — 이 수량을 잡고 있는
                // 것이 바로 이 신청서다.
                $couponRestore = $this->cancellationService->previewRefund($order, $items, RefundPriorityEnum::PG_FIRST, guardClaimLock: false);
                $this->cancellationService->cancelOptionQuantitiesForClaim($order, $items, $refund->reason_code);
            }

            $result = new AdjustmentResult(
                refundAmount: $amount,
                refundPointsAmount: $points,
                restoredCouponIssueIds: $couponRestore?->restoredCouponIssueIds ?? [],
                mcRefundAmount: $refund->mc_refund_amount ?? [],
            );

            // 환불 실행 — PG 훅 + 주문 환불 누적 + 결제행 취소 누적 (클레임 누적의 단일 지점)
            $this->refundExecutionService->execute(
                $order,
                $refund,
                $result,
                $refund->reason_code,
                executePg: $refund->refund_method === RefundMethodEnum::PG && $amount > 0,
            );

            if ($refund->refund_source_type === RefundSourceTypeEnum::DIRECT && $items !== []) {
                // D7 — 취소·환불은 즉시 복구 (`stock_restore_on_cancel` 설정 준수는 restoreStock 내부).
                $this->refundExecutionService->restoreStock($order, $items);
                $this->refundExecutionService->restoreCoupons($order, $couponRestore);
            }

            // 사용 마일리지 환급 — order_refund_id 멱등 축 (재시도에도 lot 은 한 번만 발행)
            $this->refundExecutionService->restoreMileage($order, $result, null, $refund);

            // 완료 확정 — PG 경로는 executePgRefund 가 이미 COMPLETED 를 찍었다.
            $fresh = $this->refundRepository->findById($refund->id) ?? $refund;
            $finalUpdates = ['processed_by' => $processedBy ?? $fresh->processed_by];

            if ($fresh->refund_status !== RefundStatusEnum::COMPLETED) {
                $finalUpdates['refund_status'] = RefundStatusEnum::COMPLETED;
                $finalUpdates['refunded_at'] = Carbon::now();
            }

            $updated = $this->refundRepository->update($fresh, $finalUpdates);

            $this->refundOptionRepository->bulkUpdateStatus(
                $updated->id,
                RefundOptionStatusEnum::COMPLETED->value,
                $processedBy,
                Carbon::now(),
            );

            return $updated->fresh(['refundOptions']);
        });

        HookManager::doAction(
            'sirsoft-ecommerce.claim.refund.status_changed',
            $executed,
            RefundStatusEnum::APPROVED->value,
            RefundStatusEnum::COMPLETED->value,
        );
        HookManager::doAction('sirsoft-ecommerce.claim.refund.completed', $executed);

        return $executed;
    }

    /**
     * 환불액 수정값을 검증하고 갱신 배열로 정규화합니다. (저장·실행 공용)
     *
     * D19 상한 검증은 호출부 책임이다 — 저장은 최종값으로 한 번, 실행은 반영 후 실행 직전에
     * 한 번 검사한다(두 지점이 같은 식을 쓰는 것이 D19 의 계약이고, 여기 넣으면 실행 경로가
     * 같은 검사를 두 번 한다).
     *
     * @param  Order  $order  주문 (통화 스냅샷·회원 판정)
     * @param  OrderRefund  $refund  환불신청서
     * @param  array  $amounts  수정값 (refund_amount? / refund_points_amount? / refund_method?)
     * @return array<string, mixed> 갱신 배열 (다통화 재계산 포함)
     *
     * @throws OrderClaimException 음수 / 알 수 없는 환불 수단 / 비회원 마일리지
     */
    protected function normalizeRefundAmountUpdates(Order $order, OrderRefund $refund, array $amounts): array
    {
        $updates = [];
        $currencySnapshot = $order->currency_snapshot ?? [];

        if (array_key_exists('refund_amount', $amounts) && $amounts['refund_amount'] !== null) {
            $amount = (float) $amounts['refund_amount'];

            if ($amount < 0) {
                throw OrderClaimException::amountInvalid();
            }

            $updates['refund_amount'] = $amount;
            // 다통화 표기도 함께 움직인다 — 환율은 주문 currency_snapshot 의 동결 환율이다.
            $updates['mc_refund_amount'] = ! empty($currencySnapshot)
                ? $this->currencyService->convertToMultiCurrencyWithSnapshot($amount, $currencySnapshot)
                : null;
        }

        if (array_key_exists('refund_points_amount', $amounts) && $amounts['refund_points_amount'] !== null) {
            $points = (float) $amounts['refund_points_amount'];

            if ($points < 0) {
                throw OrderClaimException::amountInvalid();
            }

            // 마일리지 원장은 회원 전용이다 — 비회원 주문에 실으면 환급 대상이 없어 장부만 어긋난다.
            if ($points > 0 && $order->user_id === null) {
                throw OrderClaimException::refundPointsRequireMember();
            }

            $updates['refund_points_amount'] = $points;
            $updates['mc_refund_points_amount'] = $points > 0 && ! empty($currencySnapshot)
                ? $this->currencyService->convertToMultiCurrencyWithSnapshot($points, $currencySnapshot)
                : null;
        }

        if (array_key_exists('refund_method', $amounts) && $amounts['refund_method'] !== null && $amounts['refund_method'] !== '') {
            $method = $amounts['refund_method'] instanceof RefundMethodEnum
                ? $amounts['refund_method']
                : RefundMethodEnum::tryFrom((string) $amounts['refund_method']);

            if ($method === null) {
                throw OrderClaimException::amountInvalid();
            }

            $updates['refund_method'] = $method;
        }

        return $updates;
    }

    /**
     * 요청 환불 총액이 취소 가능 금액(D19) 이내인지 검증합니다. (저장·실행 공용 가드)
     *
     * @param  Order  $order  주문
     * @param  OrderRefund  $refund  환불신청서 (선점 집계에서 자기 자신 제외)
     * @param  float  $requested  요청 환불 총액 (PG + 마일리지, 기본 통화)
     *
     * @throws OrderClaimException D19 상한 초과
     */
    protected function assertWithinCancellable(Order $order, OrderRefund $refund, float $requested): void
    {
        $cancellable = $this->cancellableCalculator->calculateForRefund($order, $refund);

        if (! $cancellable->allows($requested)) {
            throw OrderClaimException::refundExceedsCancellable(
                ecommerce_format_price($requested, $cancellable->currency),
                ecommerce_format_price($cancellable->amount, $cancellable->currency),
            );
        }
    }

    /**
     * 환불신청서 상태를 전이합니다. (게이트 + 시각 + 훅)
     *
     * 완료(COMPLETED)는 환불 실행(S6b)만이 진입점이다 — 실행 없이 완료로 옮기면 돈이 나가지 않은 채
     * 완료로 보인다.
     *
     * @param  OrderRefund  $refund  환불신청서
     * @param  RefundStatusEnum  $target  목표 상태
     * @param  array  $context  부가 정보 (processed_by / reject_reason)
     * @return OrderRefund 전이된 환불신청서
     *
     * @throws OrderClaimException 전이 불가
     */
    public function transition(OrderRefund $refund, RefundStatusEnum $target, array $context = []): OrderRefund
    {
        if ($target === RefundStatusEnum::COMPLETED) {
            throw OrderClaimException::completionRequiresCompletionFlow();
        }

        $current = $refund->refund_status;

        if (! $current->canTransitionTo($target)) {
            throw OrderClaimException::transitionNotAllowed($current->label(), $target->label());
        }

        $updates = ['refund_status' => $target] + $this->stampFor($target, $context);

        if (($context['processed_by'] ?? null) !== null) {
            $updates['processed_by'] = $context['processed_by'];
        }

        $updated = DB::transaction(function () use ($refund, $updates, $target) {
            $fresh = $this->refundRepository->update($refund, $updates);

            if (in_array($target, [RefundStatusEnum::REJECTED, RefundStatusEnum::WITHDRAWN], true)) {
                $this->refundOptionRepository->bulkUpdateStatus(
                    $fresh->id,
                    RefundOptionStatusEnum::REJECTED->value,
                    $updates['processed_by'] ?? null,
                );
            }

            return $fresh->fresh(['refundOptions']);
        });

        HookManager::doAction('sirsoft-ecommerce.claim.refund.status_changed', $updated, $current->value, $target->value);

        $specific = match ($target) {
            RefundStatusEnum::APPROVED => 'sirsoft-ecommerce.claim.refund.approved',
            RefundStatusEnum::REJECTED => 'sirsoft-ecommerce.claim.refund.rejected',
            RefundStatusEnum::WITHDRAWN => 'sirsoft-ecommerce.claim.refund.withdrawn',
            RefundStatusEnum::ON_HOLD => 'sirsoft-ecommerce.claim.refund.on_hold',
            default => null,
        };

        if ($specific !== null) {
            HookManager::doAction($specific, $updated);
        }

        return $updated;
    }

    /**
     * 즉시취소 가능 주문옵션 상태 값 목록을 반환합니다. (`claim.instant_cancel_statuses`)
     *
     * @return array<string> 상태 값 목록
     */
    public function instantCancelStatusValues(): array
    {
        return array_values((array) $this->settingsService->getSetting(
            'claim.instant_cancel_statuses',
            [OrderStatusEnum::PAYMENT_COMPLETE->value],
        ));
    }

    /**
     * 환불신청 가능 주문옵션 상태 값 목록을 반환합니다. (`claim.refund_request_statuses`)
     *
     * @return array<string> 상태 값 목록
     */
    public function refundRequestStatusValues(): array
    {
        return array_values((array) $this->settingsService->getSetting(
            'claim.refund_request_statuses',
            [OrderStatusEnum::PREPARING->value, OrderStatusEnum::SHIPPING_READY->value, OrderStatusEnum::SHIPPING->value],
        ));
    }

    /**
     * 취소·환불 신청 가능 상태 값 전체(두 분기 합집합)를 반환합니다.
     *
     * @return array<string> 상태 값 목록
     */
    public function requestableStatusValues(): array
    {
        return array_values(array_unique(array_merge($this->instantCancelStatusValues(), $this->refundRequestStatusValues())));
    }

    /**
     * 환불 사유를 해석합니다. (type=refund 카탈로그)
     *
     * @param  string  $reasonCode  사유 코드
     * @param  bool  $userSelectableOnly  사용자 선택 가능 사유(`is_user_selectable`)로 제한할지 여부 (관리자 경로는 false)
     * @return ClaimReason 사유 모델
     *
     * @throws OrderClaimException 사유 부재·비활성
     */
    protected function resolveReason(string $reasonCode, bool $userSelectableOnly = true): ClaimReason
    {
        $reason = $this->claimReasonRepository->findByCode($reasonCode, ClaimTypeEnum::REFUND->value);

        // 사용자 경로는 화면에 노출되지 않는 사유(`is_user_selectable=false`)를 고를 수 없어야 한다.
        // 접수 FormRequest 는 그 조건까지 게이트하는데 수정 요청은 신청서 유형을 몰라 규칙을 걸
        // 수 없어, 판정을 여기로 모은다 — 없으면 판매자 귀책 전용 사유를 수정으로 지정해
        // 반품·교환 배송비를 0 으로 만들 수 있다. 관리자 경로가 생기면 인자로 명시해 푼다.
        if (! $reason || ! $reason->is_active || ($userSelectableOnly && ! $reason->is_user_selectable)) {
            throw OrderClaimException::reasonUnavailable();
        }

        return $reason;
    }

    /**
     * 해석된 대상을 취소 파이프라인의 아이템 형태로 변환합니다.
     *
     * `cancel_quantity` 키는 `OrderAdjustmentService` / `ClaimShippingFeeService` 가 공유하는 형태다 —
     * 클레임 전용 키를 새로 만들면 두 계산기의 안분·조건부무료 재판정 경로를 다시 뚫어야 한다.
     * 이 환불 축은 `OrderCancellationService::previewRefund()` 를 거쳐 같은 계산기로 흘러간다.
     *
     * @param  array<int, array{option: OrderOption, quantity: int}>  $resolved  해석된 대상
     * @return array [{order_option_id, cancel_quantity}]
     */
    protected function toCancelItems(array $resolved): array
    {
        $items = [];

        foreach ($resolved as $entry) {
            $items[] = [
                'order_option_id' => $entry['option']->id,
                'cancel_quantity' => $entry['quantity'],
            ];
        }

        return $items;
    }

    /**
     * 상태별 시각·부가 컬럼 갱신값을 반환합니다.
     *
     * @param  RefundStatusEnum  $target  목표 상태
     * @param  array  $context  부가 정보
     * @return array<string, mixed> 갱신값
     */
    protected function stampFor(RefundStatusEnum $target, array $context): array
    {
        return match ($target) {
            RefundStatusEnum::APPROVED => ['approved_at' => Carbon::now()],
            RefundStatusEnum::ON_HOLD => ['on_hold_at' => Carbon::now()],
            RefundStatusEnum::REJECTED => ['reject_reason' => $context['reject_reason'] ?? null],
            RefundStatusEnum::WITHDRAWN => ['withdrawn_at' => Carbon::now()],
            default => [],
        };
    }
}
