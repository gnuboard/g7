<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\DTO\CancellationResult;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
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
            'adjustment' => $this->cancellationService->previewRefund($order, $this->toCancelItems($resolved), $refundPriority),
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
            $adjustment = $this->cancellationService->previewRefund($lockedOrder, $cancelItems, $refundPriority);
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
