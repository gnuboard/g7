<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimReasonRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Support\ClaimItemSpecs;

/**
 * 취소·환불 신청 분기 서비스 (S5 · D2)
 *
 * 유저의 [취소/환불신청] 은 하나의 신청서 작성 화면이지만 서버에서는 **상품 상태로 자동 분기**한다.
 * **두 분기 모두 환불신청서를 만든다** — 다른 것은 전이 방식뿐이다(#599 후속 D1·D2):
 *
 *  - 선택 상품이 전부 `claim.instant_cancel_statuses`(기본 미결제 2종 + 결제완료)에 있으면 `auto` —
 *    환불신청서를 `REQUESTED` 로 접수한 뒤 승인·완료까지 **자동 전이**한다(`autoAdvance()`).
 *    종착점은 환불수단이 가른다 — PG·마일리지는 완료까지, 계좌(BANK)는 승인에서 멈추고 운영자의
 *    [환불하기]를 기다린다(D4).
 *  - 전부 `claim.refund_request_statuses`(기본 상품준비~배송중)에 있으면 `request` — 환불신청서를
 *    `REQUESTED` 로 접수하고 운영자 승인 절차(S6b 환불 실행)를 기다린다. `claim.auto_approve_refund_request`
 *    가 켜져 있고 상품 상태가 `claim.auto_approve_refund_statuses` 에 있으면 승인까지 자동(D8).
 *  - 두 분기에 걸치면 거절한다(`claim_refund_branch_mixed`) — 한 신청서에 자동 완료되는 상품과 심사 대기
 *    상품이 섞이면 고객이 보는 환불 예정액과 실제 환불액이 갈린다.
 *  - 환불할 돈이 없으면(미결제·0원) 환불신청서를 만들지 않고 취소신청서만 남긴다(D1·D3).
 *
 * **취소신청서는 여기서 만들지 않는다.** 환불이 완료되는 순간 `OrderCancellationService::recordCancellation()`
 * 한 지점이 만든다(D3) — 취소신청서는 "주문에서 금액이 실제로 빠진 사실"의 기록이기 때문이다.
 *
 * 직접 환불신청서는 원 주문옵션을 판매 상태 그대로 둔다(코스 상태 없음). 그래서 그 수량의 이중 신청은
 * `ClaimQuantityGuard` 의 환불 축(`sumActiveDirectQuantityByOrderOption`)이 막고, 승인·환불 실행(S6b)
 * 시점에 `OrderCancellationService` 가 수량을 분할·취소한다.
 */
class OrderRefundRequestService
{
    /**
     * 분기 — 접수 후 승인·완료까지 자동 전이 (구 `cancel`).
     */
    public const BRANCH_AUTO = 'auto';

    /**
     * 분기 — 접수 후 정지, 운영자 승인 대기 (구 `refund`).
     */
    public const BRANCH_REQUEST = 'request';

    /**
     * @param  OrderCancellationService  $cancellationService  취소 서비스 (즉시취소 분기·환불 미리보기)
     * @param  ClaimQuantityGuard  $claimQuantityGuard  수량 잠금 가드
     * @param  ClaimItemSplitter  $claimItemSplitter  신청 상품 수량 분할기
     * @param  RefundExecutionService  $refundExecutionService  환불 실행 공용 서비스 (수단·안분 규칙)
     * @param  CancellableAmountCalculator  $cancellableCalculator  취소 가능 금액 산정기 (D19 — 저장·실행 가드)
     * @param  SequenceService  $sequenceService  채번 서비스
     * @param  EcommerceSettingsService  $settingsService  설정 서비스
     * @param  CurrencyConversionService  $currencyService  통화 변환 서비스
     * @param  OrderRepositoryInterface  $orderRepository  주문 Repository
     * @param  OrderRefundRepositoryInterface  $refundRepository  환불신청서 Repository
     * @param  OrderRefundOptionRepositoryInterface  $refundOptionRepository  환불 상품 행 Repository
     * @param  ClaimReasonRepositoryInterface  $claimReasonRepository  사유 카탈로그 Repository
     * @param  PaymentMethodResolver  $paymentMethodResolver  결제수단 능력 해석기 (환불 자동 승인 게이트 — P16·P17)
     */
    public function __construct(
        protected OrderCancellationService $cancellationService,
        protected ClaimQuantityGuard $claimQuantityGuard,
        protected ClaimItemSplitter $claimItemSplitter,
        protected RefundExecutionService $refundExecutionService,
        protected CancellableAmountCalculator $cancellableCalculator,
        protected SequenceService $sequenceService,
        protected EcommerceSettingsService $settingsService,
        protected CurrencyConversionService $currencyService,
        protected OrderRepositoryInterface $orderRepository,
        protected OrderRefundRepositoryInterface $refundRepository,
        protected OrderRefundOptionRepositoryInterface $refundOptionRepository,
        protected ClaimReasonRepositoryInterface $claimReasonRepository,
        protected PaymentMethodResolver $paymentMethodResolver,
    ) {}

    /**
     * 선택 상품의 취소·환불 분기를 판정합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $items  신청 대상 [{order_option_id, quantity}]
     * @return string 분기 (`auto` | `request`)
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
        if ($this->refundExecutionService->determineRefundMethod($order) === RefundMethodEnum::BANK) {
            return true;
        }

        // PG 환불인데도 계좌를 요구하는 수단이 있다 — **입금완료된 가상계좌**다(P7). PG 는
        // 카드처럼 원래 자리로 되돌릴 곳이 없어 "어디로 보낼지" 를 함께 받아야 취소를 받아준다.
        // 이 축이 빠져 있던 동안 신청 화면에는 계좌 입력칸이 서지 않았고, 접수는 되지만 환불
        // 실행이 **원인 없는 422** 로 막혔다 — 고객도 운영자도 무엇이 부족한지 알 수 없었다.
        $order->loadMissing('payment');
        $payment = $order->payment;

        if (! $payment || ! $payment->isVirtualAccount()) {
            return false;
        }

        $status = $payment->payment_status;

        // 입금 전이면 PG 가 환불받을 계좌를 요구하지 않는다 — 받은 돈이 없기 때문이다.
        return $status === null || ! $status->isAwaitingDeposit();
    }

    /**
     * 주문옵션 상태 하나의 분기를 돌려줍니다. (신청 대상이 아니면 null)
     *
     * **두 값 모두 환불신청서를 만든다**(D2) — 다른 것은 전이 방식뿐이다.
     *
     *  - `auto` — 접수 후 승인·완료까지 자동 전이 (구 `cancel`)
     *  - `request` — 접수 후 정지, 운영자 승인 대기 (구 `refund`)
     *
     * 두 설정 목록에 같은 상태가 들어 있으면 `auto` 가 이긴다 — 자동 전이 대상 상태는 환불 심사를
     * 거칠 이유가 없다.
     *
     * @param  OrderStatusEnum|string|null  $status  주문옵션 상태
     * @return string|null `auto` | `request` | null
     */
    public function branchForStatus(OrderStatusEnum|string|null $status): ?string
    {
        $value = $status instanceof OrderStatusEnum ? $status->value : (string) $status;

        if (in_array($value, $this->instantCancelStatusValues(), true)) {
            return self::BRANCH_AUTO;
        }

        if (in_array($value, $this->refundRequestStatusValues(), true)) {
            return self::BRANCH_REQUEST;
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
     * **두 분기 모두 환불신청서를 만든다.** 다른 것은 전이 방식뿐이다 — `auto` 는 승인·완료까지
     * 자동으로 밀고, `request` 는 접수에서 멈춘다(자동승인 대상이면 승인까지). 취소신청서는
     * 여기서 만들지 않는다 — 환불이 완료되는 순간 `OrderCancellationService::recordCancellation()`
     * 이 만든다(D3).
     *
     * 예외는 **환불할 돈이 없는 주문**이다(미결제·0원). 환불신청서를 만들면 0원짜리 미지급
     * 환불 레코드가 영영 `approved` 에 남으므로(P11), 환불 단계를 건너뛰고 취소신청서를 바로
     * 기록한다(D1·D3).
     *
     * 전체가 한 트랜잭션이다 — D15 가 "자동 전이 도중 PG 가 거절하면 전체 롤백" 을 요구하므로
     * 접수와 자동 전이를 나눌 수 없다. 나누면 PG 거절 시 신청서만 `requested` 로 남아, 고객은
     * 접수됐다고 보는데 돈은 나가지 않은 상태가 된다.
     *
     * @param  Order  $order  주문
     * @param  array  $payload  신청 페이로드
     *                          - items: [{order_option_id, quantity}]
     *                          - reason_code: 사유 코드 (type=refund)
     *                          - reason_detail: 상세 사유 (선택)
     *                          - requested_by: 신청자 ID (비회원이면 null)
     *                          - refund_priority: RefundPriorityEnum (선택)
     *                          - refund_bank: 환불 계좌 (선택)
     *                          - refund_source_type: RefundSourceTypeEnum (선택 — 관리자 대리 접수는 ADMIN)
     *                          - auto_advance: bool (선택 — 관리자 [주문취소]의 "환불까지 한 번에" D7)
     *                          - branch: string (선택 — 분기를 못박는다. 관리자 경로는 고객용 신청 창구로 재지 않는다)
     *                          - gate_statuses: array<string> (선택 — 자격 판정 상태 목록. 관리자 경로는 취소 가능 상태를 쓴다)
     *                          - user_selectable_reason_only: bool (선택, 기본 true — 관리자 경로는 false)
     *                          - pg_already_settled: bool (선택 — PG 환불을 바깥에서 이미 끝낸 경로.
     *                          결제 플러그인의 가상계좌 환불 화면이 그렇다. 장부·훅·로그는 같은
     *                          경로로 남기고 PG 왕복만 건너뛴다. 켜면 이중 환불을 막는다)
     * @return array{branch: string, cancel: ?OrderCancel, refund: ?OrderRefund, adjustment: AdjustmentResult} 분기 결과
     *
     * @throws OrderClaimException 신청 자격·사유 위반
     * @throws OrderCancellationException 자동 전이 중 PG 환불 거절 (D15 — 전체 롤백)
     */
    public function request(Order $order, array $payload): array
    {
        $items = $payload['items'] ?? [];
        $reason = $this->resolveReason(
            (string) ($payload['reason_code'] ?? ''),
            (bool) ($payload['user_selectable_reason_only'] ?? true),
        );
        $refundPriority = $payload['refund_priority'] ?? RefundPriorityEnum::PG_FIRST;
        $sourceType = $payload['refund_source_type'] ?? RefundSourceTypeEnum::DIRECT;

        // 분기를 호출부가 못박을 수 있다 — **관리자 [주문취소]** 가 그 경로다(§12-B).
        //
        // 관리자의 취소 권한은 `order_settings.cancellable_statuses` + `orders.update` 에서
        // 나오고, 그 판정은 `OrderCancellationService::validateCancellable()` 이 이미 한다.
        // 여기서 고객용 신청 창구(`claim.instant_cancel_statuses` / `refund_request_statuses`)로
        // 다시 재면 **운영자가 취소할 수 있던 주문을 취소할 수 없게 된다** — 두 목록에 없는
        // 상태(배송완료 등)에서 운영자가 아무것도 할 수 없어지고, 그것은 이 계획이 열려고 한
        // 축과 반대 방향이다.
        // 대상 상품이 없으면 어떤 창구에서든 접수하지 않는다 — **상품 행 0건 클레임 금지**.
        //
        // `resolveBranch()` 안에도 같은 가드가 있지만 분기를 못박은 호출부(운영자·시스템 어댑터)는
        // 그 함수를 지나지 않아 가드를 건너뛴다. 이미 전량 취소된 주문을 다시 취소하면 어댑터가
        // 빈 목록을 만들어 넘기고, 그러면 상품 행이 하나도 없는 취소 문서가 생긴다 — 목록의
        // 뱃지는 1건을 세는데 상세의 상품 표는 비어, 운영자는 그 문서가 무엇인지 알 수 없다.
        if (empty($items)) {
            throw OrderClaimException::itemsRequired();
        }

        $branch = $payload['branch'] ?? $this->resolveBranch($order, $items);

        // 자격 게이트도 같은 이유로 호출부가 넓힐 수 있다. 지정하지 않으면 분기별 목록을 쓴다 —
        // 합집합으로 열면 자동 전이 대상이 아닌 상태가 `auto` 로 들어가 승인·완료까지 밀린다.
        $gateStatuses = $payload['gate_statuses']
            ?? ($branch === self::BRANCH_AUTO ? $this->instantCancelStatusValues() : $this->refundRequestStatusValues());

        $outcome = DB::transaction(function () use ($order, $items, $reason, $payload, $refundPriority, $branch, $sourceType, $gateStatuses) {
            // 잠금은 잔여 수량을 읽기 전에 잡는다 — 읽고 나서 잠그면 이미 두 요청이 같은 값을 봤다.
            $lockedOrder = $this->orderRepository->findForUpdate($order->id) ?? $order;
            $lockedOrder->load(['options', 'shippings', 'payment']);

            $resolved = $this->claimQuantityGuard->resolveClaimItems(
                $lockedOrder,
                $items,
                ClaimTypeEnum::REFUND,
                $gateStatuses,
            );

            $cancelItems = $this->toCancelItems($resolved);
            // resolveClaimItems 가 잠금 가드를 이미 통과했다 (미리보기와 같은 이유로 재검사 생략).
            $adjustment = $this->cancellationService->previewRefund($lockedOrder, $cancelItems, $refundPriority, guardClaimLock: false);

            // ── 추가결제가 필요해지는 신청은 접수하지 않는다 ──
            //
            // 조건부 할인(쿠폰 최소 주문금액 등)이 걸린 주문에서 일부만 빼면 할인이 소멸해
            // 재계산 결제금액이 원 결제금액을 넘는다 — 환불이 아니라 **추가청구**다. 미리보기는
            // 이 상황을 `cancel_blocked` 로 이미 알리지만(AdjustmentResult::isCancelBlocked),
            // 접수 경로에는 판정이 없었다. 구형 관리자 취소만 막고 클레임 접수는 통과시키면
            // 같은 주문이 창구에 따라 갈리고, 통과한 쪽은 음수 환불액을 든 신청서로 남는다.
            if ($adjustment->isCancelBlocked()) {
                throw OrderClaimException::refundRequiresAdditionalPayment();
            }

            // ── 부분취소를 지원하지 않는 결제는 사전에 막는다 (P6) ──
            //
            // PG 마다 제약이 다르고 어떤 조합은 원천적으로 전액취소만 된다(NICE 가상계좌 등).
            // 막지 않으면 운영자는 수량을 줄여 접수하고 **원인 없는 PG 거절**을 받는다 — 그때는
            // 이미 신청서가 만들어진 뒤라 정리할 것도 생긴다. 관리자 화면은 경고 배너를 띄우지만
            // 화면만 막으면 API 로 들어온 요청이 그대로 통과한다.
            $this->assertPartialCancelSupported($lockedOrder, $cancelItems);

            // ── 환불할 돈이 없는 주문 — 환불 단계를 건너뛴다 (D1·D3·P11) ──
            if ($this->hasNothingToRefund($lockedOrder, $adjustment)) {
                $cancel = $this->cancellationService->recordCancellation(
                    order: $lockedOrder,
                    cancelItems: $cancelItems,
                    adjustment: $adjustment,
                    refund: null,
                    reason: $reason->code,
                    reasonDetail: $payload['reason_detail'] ?? null,
                    cancelledBy: $payload['requested_by'] ?? null,
                );

                return ['cancel' => $cancel, 'refund' => null, 'adjustment' => $adjustment];
            }

            $refund = $this->createRefundRequest($lockedOrder, $cancelItems, $adjustment, $reason, $payload, $sourceType);

            // 자동 전이로 갈 건이면 **접수 알림을 억제**한다 — 자동 전이가 자기 마지막 상태에서
            // 1건을 보낸다(§4-2 "마지막 상태에서 1건만"). 억제하지 않으면 한 번의 취소로
            // 접수 + 마지막 상태 **2통**이 나간다: PG·마일리지는 접수+완료, 계좌는 접수+승인.
            // `autoAdvance()` 가 승인 알림만 끄고 있어 3통이 2통으로 줄었을 뿐이었다.
            // 활동로그 리스너는 이 플래그를 읽지 않으므로 단계별 기록(D16)은 그대로 남는다.
            HookManager::doAction(
                'sirsoft-ecommerce.claim.refund.requested',
                $refund,
                $lockedOrder->fresh(),
                ['suppress_notification' => $this->willAdvanceOnIntake($lockedOrder, $refund, $branch, $payload)]
            );

            $refund = $this->advanceAfterRequest($lockedOrder, $refund, $branch, $payload);

            return ['cancel' => null, 'refund' => $refund, 'adjustment' => $adjustment];
        });

        // 현금영수증 동기화는 트랜잭션 밖이다 — 재발급 실패 이력이 롤백에 휩쓸리면 안 되고,
        // 프로바이더 왕복 2회 동안 DB 잠금을 붙들 수 없다(취소 파이프라인과 같은 규율).
        if ($outcome['cancel'] !== null || $outcome['refund']?->refund_status === RefundStatusEnum::COMPLETED) {
            $this->cancellationService->syncCashReceiptAfterCancellation($order->fresh(), $reason->code);
        }

        return [
            'branch' => $branch,
            'cancel' => $outcome['cancel'],
            'refund' => $outcome['refund'],
            // 재계산 결과 — 복원된 쿠폰 ID 등 "이 취소가 무엇을 되돌렸는가" 를 호출부가 읽는다.
            'adjustment' => $outcome['adjustment'],
        ];
    }

    /**
     * 이 주문에서 되돌릴 돈이 남아 있지 않은지 판정합니다. (D1 — 환불신청서 생략 조건)
     *
     * 두 축이다 — ⓐ 아직 결제 전이라 받은 돈이 없거나, ⓑ 계산된 환불액(PG + 마일리지)이
     * 0 이거나. ⓑ 를 함께 보지 않으면 0원 주문·전액 쿠폰 주문이 "결제완료" 라는 이유로
     * 0원짜리 환불신청서를 만들고, 그 행은 송금할 것이 없어 영영 `approved` 에 남는다(P11).
     *
     * @param  Order  $order  주문
     * @param  AdjustmentResult  $adjustment  환불 계산 결과
     * @return bool 되돌릴 돈이 없으면 true
     */
    protected function hasNothingToRefund(Order $order, AdjustmentResult $adjustment): bool
    {
        if ($order->order_status->isBeforePayment()) {
            return true;
        }

        return ((float) $adjustment->refundAmount + (float) $adjustment->refundPointsAmount) <= 0.0;
    }

    /**
     * 환불신청서를 접수 상태(`REQUESTED`)로 만듭니다. (네 경로 공용)
     *
     * 고객 신청·관리자 대리 접수·자동 전이 대상이 모두 이 한 지점을 지난다 — 경로마다 따로
     * 만들면 컬럼 한 개가 빠진 신청서가 생기고(과거 즉시취소가 `requested_by`·`approved_at`
     * 을 비운 채로 남긴 행이 그것이다), 그 결함은 화면에서 빈칸으로만 드러난다.
     *
     * @param  Order  $order  주문 (잠금·로드 완료)
     * @param  array  $cancelItems  대상 [{order_option_id, cancel_quantity}]
     * @param  AdjustmentResult  $adjustment  환불 계산 결과
     * @param  ClaimReason  $reason  해석된 사유
     * @param  array  $payload  신청 페이로드
     * @param  RefundSourceTypeEnum  $sourceType  출처 (고객 신청 DIRECT / 운영자 접수 ADMIN)
     * @return OrderRefund 접수된 환불신청서
     */
    protected function createRefundRequest(
        Order $order,
        array $cancelItems,
        AdjustmentResult $adjustment,
        ClaimReason $reason,
        array $payload,
        RefundSourceTypeEnum $sourceType = RefundSourceTypeEnum::DIRECT,
    ): OrderRefund {
        $refund = $this->refundRepository->create([
            'order_id' => $order->id,
            'refund_source_type' => $sourceType,
            'refund_source_id' => null,
            'refund_number' => $this->sequenceService->generateCode(SequenceType::REFUND),
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => $this->refundExecutionService->determineRefundMethod($order),
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

        foreach ($this->refundExecutionService->buildRefundOptionRows($order, $cancelItems, $adjustment) as $row) {
            $this->refundOptionRepository->create(array_merge(['order_refund_id' => $refund->id], $row));
        }

        return $refund->fresh(['refundOptions']);
    }

    /**
     * 이 접수가 자동 전이로 이어지는지 판정합니다. (§4-2 알림 1건)
     *
     * 접수 훅의 알림 억제와 실제 전이 분기가 **같은 판정**을 써야 한다 — 갈리면 알림이
     * 0통(억제했는데 전이하지 않음)이거나 2통(억제하지 않았는데 전이함)이 된다.
     *
     * **결제수단 게이트가 이 술어 안에 있다**(P16). 호출부에 두면 알림 억제와 실제 전이가 서로
     * 다른 판정을 읽어 갈린다 — 억제만 걸고 전이하지 않으면 고객에게 알림이 0통 간다.
     *
     * @param  Order  $order  주문 (결제수단 판정용)
     * @param  string  $branch  분기 (`auto`/`request`)
     * @param  array<string, mixed>  $payload  접수 페이로드 (auto_advance · pg_already_settled)
     * @return bool 자동 전이하면 true
     */
    protected function willAutoAdvance(Order $order, string $branch, array $payload): bool
    {
        if ($branch !== self::BRANCH_AUTO && ! (bool) ($payload['auto_advance'] ?? false)) {
            return false;
        }

        // 바깥에서 이미 집행된 환불은 수단을 묻지 않는다 — 돈은 이미 나갔으므로 멈추면
        // 운영자에게 "송금하세요" 로 보이고, 그대로 이체하면 이중 환불이다.
        if ((bool) ($payload['pg_already_settled'] ?? false)) {
            return true;
        }

        return $this->paymentMethodAutoApprovesRefund($order);
    }

    /**
     * 이 주문의 결제수단이 환불 자동 승인 대상인지 판정합니다. (P16·P17)
     *
     * 사람이 계좌로 송금해야 끝나는 수단(무통장·가상계좌 등)은 결제완료 상품이라도 자동으로
     * 나아가지 않는다. 자동 승인하면 신청서는 `완료` 로 앞서 나가고 고객에게 "환불 완료" 알림이
     * 가는데 돈은 아직 나가지 않은 상태가 된다.
     *
     * 결제행이 없으면(0원·미결제 주문) 막지 않는다 — 돌려줄 돈이 없어 자동 완료가 맞다.
     *
     * @param  Order  $order  주문
     * @return bool 자동 승인 대상이면 true
     */
    protected function paymentMethodAutoApprovesRefund(Order $order): bool
    {
        $order->loadMissing('payment');

        $methodId = $order->payment?->paymentMethodId() ?? '';

        if ($methodId === '') {
            return true;
        }

        return $this->paymentMethodResolver->autoApprovesRefund($methodId);
    }

    /**
     * 이 접수가 자동승인(D8)으로 이어지는지 판정합니다.
     *
     * 토글만 보지 않는다 — 대상 상태 목록에 없는 상품의 신청서는 접수에서 멈춘다. 이 술어가
     * 접수 훅의 알림 억제와 실제 승인 전이 **양쪽의 정본**이다. 두 곳에서 따로 재면 갈린다.
     *
     * @param  Order  $order  주문
     * @param  OrderRefund  $refund  접수된 환불신청서
     * @return bool 자동승인하면 true
     */
    protected function willAutoApprove(Order $order, OrderRefund $refund): bool
    {
        if (! (bool) $this->settingsService->getSetting('claim.auto_approve_refund_request', false)) {
            return false;
        }

        // 토글(D8)을 켜도 정지 수단 주문은 승인까지 가지 않는다 — 세 경로(즉시취소 분기 ·
        // 이 토글 · 관리자 [주문취소] 자동 전이)가 모두 같은 결제수단 규칙을 따른다(P16).
        if (! $this->paymentMethodAutoApprovesRefund($order)) {
            return false;
        }

        return $this->allItemsInAutoApproveStatuses($order, $refund);
    }

    /**
     * 이 접수가 **접수 그 자리에서** 다음 단계로 넘어가는지 판정합니다. (§4-2 알림 1건)
     *
     * 구매자는 한 번 냈는데 시스템이 두 단계를 움직이면 알림이 2통 간다. §4-2 가 못박은 것은
     * 「마지막 상태에서 1건」이므로, 자동 전이든 자동승인이든 **접수 그 자리에서 더 나아가는
     * 모든 경우**에 접수 알림을 억제한다. 자동 전이만 보면 자동승인 경로가 그물을 빠져나간다 —
     * 같은 결함군의 두 경로에 다른 규칙을 적용한 셈이 된다.
     *
     * @param  Order  $order  주문
     * @param  OrderRefund  $refund  접수된 환불신청서
     * @param  string  $branch  분기 (`auto`/`request`)
     * @param  array<string, mixed>  $payload  접수 페이로드
     * @return bool 접수 직후 더 전이하면 true
     */
    protected function willAdvanceOnIntake(Order $order, OrderRefund $refund, string $branch, array $payload): bool
    {
        return $this->willAutoAdvance($order, $branch, $payload) || $this->willAutoApprove($order, $refund);
    }

    /**
     * 접수 직후의 전이 정책을 적용합니다. (D2·D7·D8)
     *
     * | 조건 | 전이 |
     * | --- | --- |
     * | 분기 `auto` + 결제수단이 자동 승인 목록 | 승인 → (수단별) 완료까지 자동 |
     * | 관리자 [주문취소] + `auto_advance` + 결제수단이 자동 승인 목록 | 위와 같음 |
     * | 분기 `request` + 자동승인 on + 상태가 대상 목록 + 결제수단이 자동 승인 목록 | 승인까지 |
     * | **결제수단이 목록 밖**(무통장·가상계좌 등) | 접수에서 정지 — 운영자 승인 → [환불하기] (P16) |
     * | 그 외 | 접수에서 정지 |
     *
     * @param  Order  $order  주문
     * @param  OrderRefund  $refund  접수된 환불신청서
     * @param  string  $branch  분기 (`auto` | `request`)
     * @param  array  $payload  신청 페이로드
     * @return OrderRefund 전이된 환불신청서
     */
    protected function advanceAfterRequest(Order $order, OrderRefund $refund, string $branch, array $payload): OrderRefund
    {
        $processedBy = $payload['processed_by'] ?? null;

        if ($this->willAutoAdvance($order, $branch, $payload)) {
            return $this->autoAdvance($refund, [
                'processed_by' => $processedBy,
                'pg_already_settled' => (bool) ($payload['pg_already_settled'] ?? false),
            ]);
        }

        if (! $this->willAutoApprove($order, $refund)) {
            return $refund;
        }

        // 접수 훅이 이미 억제를 걸었으므로 여기서 1통을 보낸다 — 판정은 같은 술어에서 나온다.
        //
        // 주체(`processed_by`)는 **일부러 비운다**. 이 전이를 누른 사람은 없다 — `claim.
        // auto_approve_refund_request` 설정이 한 일이다. 접수자를 그대로 실어 나르면 활동로그
        // 「환불 신청서 상태 변경(환불신청 → 환불승인)」의 작업자가 고객 이름으로 남아,
        // 나중에 「누가 승인했나」를 물을 때 고객이 자기 신청을 승인한 것처럼 읽힌다
        // (#599 세션A 실측 2026-09-21 · RF20260921-1616082573 · PO 확정 2026-09-22).
        //
        // 새 라벨을 만들지 않고 저장소가 이미 쓰는 관례에 합류한다 — 1-T 입금기한 만료
        // 자동취소도 작업자를 NULL 로 남기고 화면이 그것을 시스템으로 읽는다.
        // 바로 위 `autoAdvance` 는 **관리자가 [주문취소]를 눌러** 시작한 경로라 주체가 있고,
        // 그 책임 추적을 지우면 안 되므로 `$processedBy` 를 그대로 넘긴다.
        return $this->transition($refund, RefundStatusEnum::APPROVED);
    }

    /**
     * 신청서의 모든 상품이 자동승인 대상 상태인지 판정합니다. (D8)
     *
     * 하나라도 대상 밖이면 전건을 사람이 본다 — 섞인 신청서를 부분 승인할 수단이 없으므로,
     * "대상 상태가 하나라도 있으면 승인" 은 대상 밖 상품까지 함께 승인해 버린다.
     *
     * @param  Order  $order  주문
     * @param  OrderRefund  $refund  환불신청서
     * @return bool 전건이 대상이면 true
     */
    protected function allItemsInAutoApproveStatuses(Order $order, OrderRefund $refund): bool
    {
        $allowed = $this->autoApproveRefundStatusValues();

        if ($allowed === []) {
            return false;
        }

        $refund->loadMissing('refundOptions');
        $order->loadMissing('options');

        foreach ($refund->refundOptions as $row) {
            $option = $order->options->firstWhere('id', (int) $row->order_option_id);

            if (! $option) {
                return false;
            }

            $value = $option->option_status instanceof OrderStatusEnum
                ? $option->option_status->value
                : (string) $option->option_status;

            if (! in_array($value, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 접수된 환불신청서를 승인·완료까지 자동으로 전이시킵니다. (§4-2 · D2·D4·D15·D16)
     *
     * **단계를 건너뛰지 않는다.** 한 트랜잭션 안에서 일어나더라도 상태를
     * `REQUESTED → APPROVED → COMPLETED` 로 실제로 거친다 — 최종 상태를 바로 INSERT 하면
     * 활동로그가 한 줄만 남아, 운영자는 이 환불이 승인을 거쳤는지 알 수 없다(D16).
     *
     * 종착점은 **환불수단**이 가른다(D4):
     *
     * | 수단 | 종착점 | 근거 |
     * | --- | --- | --- |
     * | `PG` | 완료 | PG API 가 이 트랜잭션 안에서 실제로 환불한다 |
     * | `POINTS` | 완료 | 같은 트랜잭션의 `restoreMileage()` 가 이미 마일리지를 환급한다 — 돈이 돌아갔는데 상태만 '승인' 이면 운영자가 추가 이체해 이중 환불이 난다(P12) |
     * | `BANK` | 승인 | 사람이 송금해야 한다. 운영자의 [환불하기]가 곧 송금 완료 선언이다 |
     *
     * ## 알림은 묶고 로그는 쪼갠다
     *
     * 단계를 밟으면 한 번의 취소로 고객에게 접수·승인·완료 **알림 3건**이 나간다. D16 이
     * 요구하는 것은 활동로그이지 알림이 아니므로, 중간 전이에는 억제 플래그를 실어 마지막
     * 상태에서 1건만 보낸다. 활동로그 리스너는 그 플래그를 읽지 않는다.
     *
     * @param  OrderRefund  $refund  접수된 환불신청서
     * @param  array  $context  부가 정보 (processed_by)
     * @return OrderRefund 전이된 환불신청서
     *
     * @throws OrderCancellationException PG 환불 거절 (D15 — 호출부 트랜잭션 전체 롤백)
     */
    public function autoAdvance(OrderRefund $refund, array $context = []): OrderRefund
    {
        $processedBy = $context['processed_by'] ?? null;
        $pgAlreadySettled = (bool) ($context['pg_already_settled'] ?? false);
        $completesHere = $this->autoAdvanceCompletes($refund, $pgAlreadySettled);

        $refund = $this->transition($refund, RefundStatusEnum::APPROVED, [
            'processed_by' => $processedBy,
            // 완료까지 갈 건이면 승인 알림을 억제한다 — 마지막 상태에서 1건만 보낸다.
            'suppress_notification' => $completesHere,
        ]);

        if (! $completesHere) {
            return $refund;
        }

        return $this->executeRefund($refund, [], $processedBy, pgAlreadySettled: $pgAlreadySettled);
    }

    /**
     * 이 결제가 부분취소를 지원하는지 확인합니다. (P6)
     *
     * 판정 근거는 PG 플러그인의 `payment.supports_partial_cancel` 선언이다 — 제약을 아는 자리는
     * 플러그인이고, 코어가 수단·PG 조합표를 들면 플러그인이 늘 때마다 그 표가 낡는다. 화면이
     * 읽는 값(`OrderPaymentResource::supports_partial_cancel`)과 같은 훅이라 안내와 차단이 갈리지
     * 않는다.
     *
     * 전액취소는 막지 않는다 — 제약은 "일부만 취소" 에 걸린다.
     *
     * @param  Order  $order  주문 (잠금된 상태)
     * @param  array  $cancelItems  취소 대상 [{order_option_id, cancel_quantity}]
     * @return void
     *
     * @throws OrderClaimException 부분취소 미지원
     */
    protected function assertPartialCancelSupported(Order $order, array $cancelItems): void
    {
        if (! $order->payment) {
            return;
        }

        if ($this->cancellationService->resolveCancelType($order, $cancelItems) === CancelTypeEnum::FULL) {
            return;
        }

        $supported = (bool) HookManager::applyFilters(
            'sirsoft-ecommerce.payment.supports_partial_cancel',
            true,
            $order->payment,
        );

        if (! $supported) {
            throw OrderClaimException::partialCancelNotSupported();
        }
    }

    /**
     * 자동 전이가 완료까지 가는 수단인지 판정합니다. (D4)
     *
     * **바깥에서 이미 집행된 환불은 수단을 묻지 않고 완료한다.** 돈은 이미 나갔으므로 승인에서
     * 멈출 이유가 없다 — 멈추면 운영자에게 "송금하세요" 로 보이고, 그대로 이체하면 **이중 환불**이다.
     *
     * 이 구분이 필요한 이유는 수단 판정이 PG 리스너의 **존재**에 의존하기 때문이다(P14 폴백:
     * 리스너가 없으면 `BANK` 로 떨어진다). 결제 플러그인이 자기 API 로 환불을 끝내고 장부만
     * 맡기는 경로에서는 그 폴백이 정확히 반대로 작동한다.
     *
     * @param  OrderRefund  $refund  환불신청서
     * @param  bool  $pgAlreadySettled  PG 환불이 바깥에서 이미 집행됐는지
     * @return bool 완료까지 가면 true
     */
    protected function autoAdvanceCompletes(OrderRefund $refund, bool $pgAlreadySettled = false): bool
    {
        if ($pgAlreadySettled) {
            return true;
        }

        return in_array($refund->refund_method, [RefundMethodEnum::PG, RefundMethodEnum::POINTS], true);
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
        if (! $refund->refund_source_type?->isBuyerEditable() || ! $refund->isWithdrawable()) {
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
        if (! $refund->refund_source_type?->isBuyerEditable() || ! $refund->refund_status->isWithdrawable()) {
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

        // 환불 계좌 — D13 이 여는 축이다(2026-09-01 확정: 수정 화면에서 편집 가능, 단
        // 수정이 허용되는 신청 단계에서만 — 이 메서드는 그 단계에서만 진입한다). 값이 세 개
        // 다 찬 경우에만 페이로드에 실리므로(UpdateClaimRequest), 손대지 않은 저장이 기록된
        // 계좌를 지우지 않는다. 실제 송금 대상(주문 결제행)은 여기서 바꾸지 않는다 — 그
        // 갱신은 환불을 실행하는 시점의 책임이다(AppliesRefundBankAccount). 접수 경로
        // (`create()`)와 같은 컬럼·같은 키를 쓴다.
        $bank = $payload['refund_bank'] ?? null;

        if (is_array($bank)) {
            $updates['refund_bank_code'] = $bank['bank_code'];
            $updates['refund_bank_account'] = $bank['account_number'];
            $updates['refund_bank_holder'] = $bank['holder'];
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

            if ($refund->refund_status === RefundStatusEnum::PROCESSING || $refund->refund_status->isFinal()) {
                throw OrderClaimException::amountsNotEditable();
            }

            $refund->loadMissing('order');
            $order = $this->orderRepository->findForUpdate($refund->order_id) ?? $refund->order;

            $updates = $this->normalizeRefundAmountUpdates($order, $refund, $amounts);

            $finalAmount = (float) ($updates['refund_amount'] ?? $refund->refund_amount);
            $finalPoints = (float) ($updates['refund_points_amount'] ?? $refund->refund_points_amount);

            $this->assertWithinCancellable($order, $refund, $finalAmount, $finalPoints);

            if ($updates === []) {
                return $refund;
            }

            if ($processedBy !== null) {
                $updates['processed_by'] = $processedBy;
            }

            $previousAmount = (float) $refund->refund_amount;
            $updated = $this->refundRepository->update($refund, $updates);

            // 감액은 신청상품 행까지 내려간다 — 행 금액은 접수 시점 1회만 기록되므로, 헤더만
            // 고치면 상세가 접수 당시 금액을 계속 말한다(S17 B7). 어느 상품이 얼마나 깎였는지는
            // 운영자가 총액만 조정하므로 알 수 없다 — 헤더가 줄어든 **비율**을 행에 그대로
            // 적용하는 것이 유일한 근거다.
            if (array_key_exists('refund_amount', $updates)) {
                $this->applyAmountRatioToOptions(
                    (int) $updated->id,
                    $previousAmount,
                    (float) $updated->refund_amount,
                );
            }

            return $updated;
        });
    }

    /**
     * 신청서 환불액이 줄어든(늘어난) 비율을 신청상품 행에 적용합니다.
     *
     * **차액이 아니라 비율**을 적용한다. 행 금액의 합계는 헤더와 같지 않을 수 있기 때문이다 —
     * 반품 발 신청서의 헤더에는 배송비 환급처럼 상품에 귀속되지 않는 몫이 함께 들어 있다.
     * 차액을 행에 그대로 빼면 그 몫만큼 과다 차감되어 행이 0 으로 잘린다(실데이터에서 확인).
     * 비율은 두 경우 모두를 옳게 다룬다 — 행 합계가 헤더와 같은 신청서에서는 결과가
     * `행 합계 == 헤더` 로 정확히 떨어진다.
     *
     * 행 금액의 합계가 0 이면 비례 근거가 없으므로 수량으로 나눈다. 반올림 잔여는 마지막 행이
     * 흡수해 배분 합계를 목표치와 정확히 맞춘다 — 행마다 따로 반올림하면 합계가 몇 원씩
     * 어긋난 채 남는다.
     *
     * @param  int  $refundId  환불신청서 ID
     * @param  float  $previousAmount  수정 전 헤더 환불액
     * @param  float  $currentAmount  수정 후 헤더 환불액
     * @return void
     */
    protected function applyAmountRatioToOptions(int $refundId, float $previousAmount, float $currentAmount): void
    {
        if (abs($currentAmount - $previousAmount) < 0.005) {
            return;
        }

        $all = $this->refundOptionRepository->listByRefundId($refundId);

        if ($all->isEmpty()) {
            return;
        }

        // 목표 총액은 **전건** 기준으로 잡고(비율의 분모가 지금 행 합계여야 헤더 비율이
        // 한 번만 적용된다), 배분 대상에서만 반려·철회 행을 뺀다. 가중치에 남겨 두면 반려
        // 행이 새 환불액의 몫을 받아 가고, 그 몫은 아무에게도 지급되지 않는다.
        $currentTotal = (float) $all->sum(fn ($option) => (float) $option->refund_amount);

        $options = $all->reject(fn ($option) => in_array(
            $option->option_status instanceof RefundStatusEnum
                ? $option->option_status->value
                : (string) $option->option_status,
            [RefundStatusEnum::REJECTED->value, RefundStatusEnum::WITHDRAWN->value],
            true
        ))->values();

        if ($options->isEmpty()) {
            return;
        }

        $weights = $options->map(fn ($option) => max(0.0, (float) $option->refund_amount))->all();
        $weightTotal = array_sum($weights);

        if ($weightTotal <= 0) {
            // 폴백에도 같은 제외가 들어간다 — 여기서만 전건으로 돌아가면 행 금액이 모두 0 인
            // 신청서에서 반려 행이 수량만큼 돈을 받아 간다.
            $weights = $options->map(fn ($option) => max(1.0, (float) $option->quantity))->all();
            $weightTotal = array_sum($weights);
        }

        // 수정 전 헤더가 0 이면 비율을 낼 수 없다 — 그 경우에만 헤더 값을 그대로 나눈다.
        $targetTotal = $previousAmount > 0
            ? max(0.0, $currentTotal * ($currentAmount / $previousAmount))
            : max(0.0, $currentAmount);

        $assigned = 0.0;
        $lastIndex = $options->count() - 1;

        foreach ($options->values() as $index => $option) {
            $amount = $index === $lastIndex
                ? round($targetTotal - $assigned, 2)
                : round($targetTotal * ($weights[$index] / $weightTotal), 2);

            $amount = max(0.0, $amount);
            $assigned += $amount;

            $this->refundOptionRepository->updateRefundAmount($option, $amount);
        }
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
     * @param  bool  $pgAlreadySettled  PG 환불이 바깥에서 이미 집행됐는지 (결제 플러그인의 가상계좌 환불 경로)
     * @return OrderRefund 실행 완료된 환불신청서
     *
     * @throws OrderClaimException 실행 불가 출처·상태 / D19 상한 초과 / 재실행
     * @throws OrderCancellationException PG 환불 실패
     */
    public function executeRefund(
        OrderRefund $refund,
        array $overrides = [],
        ?int $processedBy = null,
        bool $pgAlreadySettled = false,
    ): OrderRefund {
        // 출처 가드는 두지 않는다(#599 후속 §4-5). 구형 취소 발(`cancel`) 행도 운영자가 완료
        // 처리할 수 있어야 한다 — 그 행들은 지금 `approved` 에서 멈춰 있고, 막아 두면 완료로
        // 옮길 API 가 저장소 어디에도 없어 영구 정지한다(P1).
        $refundId = $refund->id;

        $completedOptionChanges = [];

        $executed = DB::transaction(function () use ($refundId, $overrides, $processedBy, $pgAlreadySettled, &$completedOptionChanges) {
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
            $this->assertWithinCancellable($order, $refund, $amount, $points);

            $refund->loadMissing('refundOptions');

            // **헤더 금액이 덮는 행만** 취소·재고복원 대상이다. 반려·철회 행을 포함하면
            // 금액은 재계산된 값으로 나가는데 수량은 전건이 움직여, **돈과 수량이 어긋난 채
            // 정상 종료한다.** 판단이 없는 행은 헤더 승인이 이미 덮고 있으므로 그대로 간다.
            $executionTargets = $refund->refundOptionsForAmount();

            $items = [];
            foreach ($executionTargets as $option) {
                $items[] = [
                    'order_option_id' => $option->order_option_id,
                    'cancel_quantity' => (int) $option->quantity,
                ];
            }

            $couponRestore = null;

            // 취소 유형은 **수량을 옮기기 전에** 정한다. 아래 `cancelOptionQuantitiesForClaim()`
            // 이 수량을 먼저 빼기 때문에, 기록 시점에 다시 재면 남은 수량이 0 이라 부분취소가
            // 전체취소로 굳는다 — 취소 문서의 유형이 틀리고 주문 축 훅도 전체취소로 나간다.
            $cancelType = $items !== []
                ? $this->cancellationService->resolveCancelType($order, $items)
                : null;

            if ($this->ownsOptionQuantities($refund) && $items !== []) {
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
                // 재계산이 낸 **세금 분류 세 컬럼**만 실어 보낸다 (S3-D17). 금액 컬럼은 싣지
                // 않는다 — `total_paid_amount` 를 깎으면 잔여 한도에서 같은 금액이 두 번
                // 차감된다(`RefundExecutionService::execute()` 독블록).
                //
                // 이 값이 없으면 `CashReceiptService` 가 **전액 환불된 주문에도 발급 가능액을
                // 낸다**(2026-09-16 실측: 주문 964 전액 환불 후 `issuable.amount = 62,000`).
                // 전액취소 경로는 이미 0 으로 내린다(S1-D22) — 반품·환불만 빠져 있었다.
                orderUpdates: $this->taxColumnUpdatesFor($order, $items, $couponRestore),
                restoredCouponIssueIds: $couponRestore?->restoredCouponIssueIds ?? [],
                mcRefundAmount: $refund->mc_refund_amount ?? [],
            );

            // 환불 실행 — PG 훅 + 주문 환불 누적 + 결제행 취소 누적 (클레임 누적의 단일 지점)
            //
            // `$pgAlreadySettled` 는 **PG 환불을 바깥에서 이미 끝낸** 경로가 쓴다 — 결제
            // 플러그인의 가상계좌 환불 화면이 그렇다(PG API 를 자기가 부르고 코어에는 장부만
            // 맡긴다). 그때 여기서 PG 를 또 부르면 같은 거래를 두 번 취소한다. 장부·훅·로그는
            // 전부 같은 경로로 남기고 PG 왕복만 건너뛴다.
            $this->refundExecutionService->execute(
                $order,
                $refund,
                $result,
                $refund->reason_code,
                executePg: ! $pgAlreadySettled
                    && $refund->refund_method === RefundMethodEnum::PG
                    && $amount > 0,
                // 결제행의 취소 누적은 **그 수단에서 실제로 취소가 일어난 때만** 쌓는다 (S1-D24).
                //
                // 카드 주문에 `계좌이체` 환불수단을 지정하면 PG 왕복은 건너뛰면서 이 누적만
                // 깎였다 — 카드사 잔액은 그대로인데 장부의 환불가능액만 줄어, 이후 정상적인
                // 카드 환불이 한도에 먼저 걸린다(실측 1-P: 58,000 → 44,000).
                //
                // 운영자가 손으로 계좌 송금하는 운영은 막지 않는다(PO 확정 2026-09-16 —
                // 「허용하되 장부는 건드리지 않음」). 그 송금은 카드 취소가 아니므로 카드
                // 결제행의 취소 누적이 아닐 뿐이다. 돈이 나간 사실은 주문 축
                // (`total_refunded_amount`)과 환불 레코드가 그대로 기록한다.
                //
                // 기준은 「이 결제수단이 **원래 쓰는** 환불수단인가」다. 무통장(dbank)의 계좌
                // 환불은 그 수단의 정상 취소이므로 쌓고, 카드의 계좌 환불은 카드 취소가 아니라
                // 쌓지 않는다. `determineRefundMethod()` 가 그 판정의 SSoT 이고, PG 리스너가
                // 없어 계좌로 폴백된 카드 주문(P14)도 같은 답을 내어 정상 취소로 남는다.
                //
                // `$pgAlreadySettled`(가상계좌 환불 화면·`cancelPg: false`)는 **취소가 실제로
                // 일어난** 경우라 그대로 쌓는다 — 여기서 빼면 정상 취소의 누적이 사라진다.
                recordPaymentLedger: $pgAlreadySettled
                    || $refund->refund_method === $this->refundExecutionService->determineRefundMethod($order),
            );

            if ($this->ownsOptionQuantities($refund) && $items !== []) {
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

            $executionTargetIds = $executionTargets->pluck('id')->map(fn ($id) => (int) $id)->all();

            // 활동로그의 전/후도 **같은 대상**으로 좁힌다. 전건으로 캡처하면 반려 행이
            // "반려 → 환불완료" 로 기록되는데 실제로는 바뀌지 않아, 로그와 데이터가
            // 어긋난 채 둘 다 정상으로 보인다.
            $completedOptionChanges = $this->captureOptionStatusChanges(
                $updated->id,
                RefundStatusEnum::COMPLETED,
                $executionTargetIds,
            );

            // 완료로 올리는 대상도 **덮는 행에 한정**한다. 전건을 덮으면 반려 안내를 받은
            // 상품이 고객 화면에 환불완료로 보인다 — 오류도 로그도 남지 않는다.
            $this->refundOptionRepository->bulkUpdateStatus(
                $updated->id,
                RefundStatusEnum::COMPLETED->value,
                $processedBy,
                Carbon::now(),
                $executionTargetIds,
            );

            // ── 취소신청서 기록 — 환불이 집행된 바로 그 순간이다 (D3) ──
            //
            // 상품 상태는 출처가 정한다(D13) — 취소·환불 신청과 관리자 대리 접수는 위
            // `cancelOptionQuantitiesForClaim()` 이 이미 `CANCELLED` 로 전이시켰으므로 여기서
            // 다시 전이시키지 않고, 반품·교환은 `returned`·`exchanged` 를 유지한다.
            //
            // 금액 재계산 대상도 출처가 가른다. 상품이 주문에서 빠지는 경로만 재계산을 넘긴다 —
            // 교환 차액 환불은 상품이 주문에 남으므로 재계산하면 고객이 가진 물건의 값이
            // 주문에서 사라진다. 반품은 회수배송비 청구가 환불액에 섞여 있어 재계산 결과와
            // 실제 환불액이 갈리므로, 누적 축만 갱신하고 금액은 환불 누적이 말하게 둔다.
            $cancelAdjustment = $this->ownsOptionQuantities($updated) ? $couponRestore : null;

            $this->cancellationService->recordCancellation(
                order: $order->fresh(['options', 'payment', 'shippings']),
                cancelItems: $items,
                adjustment: $cancelAdjustment,
                refund: $updated,
                cancelType: $cancelType,
                reason: $updated->reason_code,
                cancelledBy: $updated->requested_by,
                processedBy: $processedBy ?? $updated->processed_by,
                transitionOptions: false,
                // 상품을 여기서 옮기지는 않았지만(위 `cancelOptionQuantitiesForClaim`), 그
                // 수량은 주문에서 실제로 빠졌다 — 취소·환불 신청과 운영자 대리 접수는 주문
                // 상태를 파생시켜야 한다. 반품·교환은 상품이 주문에 남으므로 건드리지 않는다.
                syncOrderStatus: $this->ownsOptionQuantities($updated),
                // 결제행 취소 누적·주문 환불 누적은 `RefundExecutionService::execute()` 가 이미
                // 더했다. 여기서 또 더하면 같은 금액이 두 번 빠져 2차 부분환불이 0원이 된다.
                accumulatePayment: false,
                recalculatePaidAmount: false,
                restoreCouponsAndStock: false,
            );

            return $updated->fresh(['refundOptions']);
        });

        if ($completedOptionChanges !== []) {
            HookManager::doAction(
                'sirsoft-ecommerce.claim.refund.option_status_changed',
                $executed,
                $completedOptionChanges,
                RefundStatusEnum::COMPLETED->value,
                $processedBy,
            );
        }

        HookManager::doAction(
            'sirsoft-ecommerce.claim.refund.status_changed',
            $executed,
            RefundStatusEnum::APPROVED->value,
            RefundStatusEnum::COMPLETED->value,
            [],
        );
        HookManager::doAction('sirsoft-ecommerce.claim.refund.completed', $executed);

        // 현금영수증 동기화는 **트랜잭션 밖에서만** 돈다 — 재발급 실패 이력이 롤백에 휩쓸리면
        // 안 되고, 프로바이더 왕복 2회 동안 DB 잠금을 붙들 수 없다.
        //
        // 자동 전이(`autoAdvance()`)는 접수와 같은 트랜잭션 안에서 이 메서드를 부르므로, 그때는
        // 여기서 동기화하지 않고 **접수 경로가 커밋 뒤에** 부른다(`request()` 말미). 중첩 여부를
        // 보지 않으면 그 경로에서 외부 API 두 번이 열린 트랜잭션 안으로 들어간다.
        if (DB::transactionLevel() === 0) {
            $this->cancellationService->syncCashReceiptAfterCancellation(
                $executed->order()->first() ?? $refund->order,
                $executed->reason_code,
            );
        }

        return $executed;
    }

    /**
     * 환불 신청상품 행이 **새로 생긴** 사실을 상품 축 활동로그로 남깁니다. (§7-1 C11·C12)
     *
     * 반품·교환 완료가 만드는 환불신청서는 헤더 훅(`claim.refund.requested`)만 쏘고 있어,
     * 그 신청서의 **상품 행 생성**은 어떤 로그도 남기지 않았다 — 운영자는 그 환불이 어떤
     * 상품 몇 개를 담고 생겼는지 이력에서 되짚을 수 없었다. 이후 완료 전이에서야 상품 축이
     * 처음 등장했다.
     *
     * 신규 행이므로 `previous` 는 null 이다 — 교환 재발송 신규 주문상품(A6)과 같은 표기이며,
     * 리스너가 그 값을 전/후 표의 "없음 → 접수" 로 그린다.
     *
     * @param  OrderRefund  $refund  방금 만들어진 환불신청서
     * @param  int|null  $processedBy  처리 주체 (운영자 id — 시스템이면 null)
     * @return void
     */
    public function announceRefundOptionsCreated(OrderRefund $refund, ?int $processedBy = null): void
    {
        $changes = [];

        foreach ($this->refundOptionRepository->listByRefundId((int) $refund->id) as $row) {
            $status = $row->option_status instanceof RefundStatusEnum
                ? $row->option_status->value
                : (string) $row->option_status;

            $changes[] = [
                'option_id' => (int) $row->id,
                'previous' => null,
                'new' => $status,
            ];
        }

        if ($changes === []) {
            return;
        }

        HookManager::doAction(
            'sirsoft-ecommerce.claim.refund.option_status_changed',
            $refund,
            $changes,
            RefundStatusEnum::REQUESTED->value,
            $processedBy,
        );
    }

    /**
     * 신청상품 행의 상태 전이 내역을 일괄 갱신 **전에** 캡처합니다. (D16 — 전/후)
     *
     * 활동로그 화면은 `changes` 에 실린 전/후만 "변경 전 → 변경 후" 표로 그린다. 갱신 뒤에
     * 읽으면 두 값이 같아져 표가 비고, 오류는 나지 않는다. 이미 목표 상태인 행은 전이가
     * 아니므로 뺀다 — 재실행·멱등 호출이 없는 전이를 이력에 남기지 않게 한다.
     *
     * @param  int  $refundId  환불신청서 ID
     * @param  RefundStatusEnum  $target  목표 상태
     * @param  array<int, int>  $optionIds  대상 행 ID (빈 배열이면 신청서 전건)
     * @return array<int, array{option_id: int, previous: string, new: string}> 전이 내역
     */
    protected function captureOptionStatusChanges(int $refundId, RefundStatusEnum $target, array $optionIds = []): array
    {
        $changes = [];

        foreach ($this->refundOptionRepository->listByRefundId($refundId) as $row) {
            if ($optionIds !== [] && ! in_array((int) $row->id, $optionIds, true)) {
                continue;
            }

            $previous = $row->option_status instanceof RefundStatusEnum
                ? $row->option_status->value
                : (string) $row->option_status;

            if ($previous === $target->value) {
                continue;
            }

            $changes[] = [
                'option_id' => (int) $row->id,
                'previous' => $previous,
                'new' => $target->value,
            ];
        }

        return $changes;
    }

    /**
     * 이 환불신청서가 주문상품 수량을 선점·회수하는 출처인지 판정합니다.
     *
     * 고객 신청(`DIRECT`)과 운영자 대리 접수(`ADMIN`)는 접수 시점에 주문상품을 판매 상태로
     * 두고 수량만 선점하므로, 환불 실행이 그 수량을 취소 상태로 분할·전이시키고 재고·쿠폰을
     * 복원한다. 반품·교환 발 신청서는 자기 서비스가 이미 상품을 옮겼으므로 여기서 손대면
     * 같은 수량이 두 번 빠진다.
     *
     * @param  OrderRefund  $refund  환불신청서
     * @return bool 수량을 소유하는 출처면 true
     */
    protected function ownsOptionQuantities(OrderRefund $refund): bool
    {
        return (bool) $refund->refund_source_type?->ownsOptionQuantities();
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

            // S1-D15: 어휘만 맞으면 통과시키면 결제수단이 지원하지 않는 수단이 저장된다.
            // 무통장·마일리지·예치금·무료 결제는 PG 거래가 없어 `pg` 로 돌려줄 대상이 없고,
            // 실행(`RefundExecutionService`)은 **결제수단**으로 PG 진입을 가르므로 훅을 건너뛰고
            // 조용히 완료된다 — 화면에만 「PG사 환불」이 남아 장부와 표시가 어긋난다.
            $this->assertRefundMethodAvailable($order, $method);

            $updates['refund_method'] = $method;
        }

        return $updates;
    }

    /**
     * 이 주문의 결제수단이 지정한 환불수단을 지원하는지 검증합니다. (S1-D15)
     *
     * `pg` 는 PG사를 거친 결제에만 쓸 수 있다 — 판정은 실행 경로와 **같은 술어**를 쓴다
     * (`OrderPayment::needsPgProvider()`). 두 곳이 다른 기준을 쓰면 저장은 되는데 실행은
     * 다르게 행동하는 틈이 다시 생긴다.
     *
     * `bank`·`points` 는 결제수단과 무관하게 사람이 돌려주는 경로라 제한하지 않는다
     * (마일리지 환급의 회원 제약은 금액 축이 따로 검사한다).
     *
     * @param  Order  $order  주문
     * @param  RefundMethodEnum  $method  지정한 환불수단
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 결제수단이 그 환불수단을 지원하지 않을 때
     */
    protected function assertRefundMethodAvailable(Order $order, RefundMethodEnum $method): void
    {
        if ($method !== RefundMethodEnum::PG) {
            return;
        }

        $order->loadMissing('payment');

        if ($order->payment === null || ! $order->payment->needsPgProvider()) {
            throw OrderClaimException::refundMethodUnavailable();
        }
    }

    /**
     * 요청 환불액이 취소 가능 금액(D19) 이내인지 검증합니다. (저장·실행 공용 가드)
     *
     * **두 겹으로 본다.**
     *
     * ① 합계 축(D19) — 도메인상 돌려줘야 할 금액을 넘지 않는가.
     * ② 수단별 축(S3-D21) — 그 돈이 **실제로 나갈 수단**에 잔여가 있는가.
     *
     * ① 만 보면 한쪽 수단이 마른 채 다른 쪽 여유로 통과한다. 취소 가능 금액은 결제수단 잔여와
     * 마일리지 잔여를 **더한 하나**라, 현금 잔여가 9,000 뿐이어도 마일리지 20,000 이 남아
     * 있으면 현금 14,000 요청이 `14,000 ≤ 29,000` 으로 통과한다 — 실측 주문 753 이 그렇게
     * 결제액을 5,000 초과 환불했고(`cancelled_amount 350,000 > paid_amount_base 345,000`),
     * 마일리지는 20,000 중 10,000 이 끝내 환급되지 못한 채 종료됐다.
     *
     * **동시에 떠 있는 다른 신청서까지는 보지 않는다** — 그 축(③ 선점액)은 한도에서 빼지
     * 않는 것이 이 저장소의 결정이다(`CancellableAmount` 독블록 · S17 B5). 빼면 승인도 되지
     * 않은 남의 신청서가 이 건의 정당한 환불을 0 원으로 막는다. 두 신청서가 나란히 통과해
     * 합계가 넘는 경우는 **이 가드가 실행 순간 거절**하고, 어느 건을 줄일지는 운영자가 정한다.
     *
     * @param  Order  $order  주문
     * @param  OrderRefund  $refund  환불신청서 (선점 집계에서 자기 자신 제외)
     * @param  float  $cash  요청 현금(결제수단) 환불액 (기본 통화)
     * @param  float  $mileage  요청 마일리지 환불액 (기본 통화)
     * @return void
     *
     * @throws OrderClaimException D19 상한 초과 또는 수단별 잔여 초과
     */
    protected function assertWithinCancellable(Order $order, OrderRefund $refund, float $cash, float $mileage): void
    {
        $cancellable = $this->cancellableCalculator->calculateForRefund($order, $refund);
        $requested = $cash + $mileage;

        if (! $cancellable->allows($requested)) {
            throw OrderClaimException::refundExceedsCancellable(
                ecommerce_format_price($requested, $cancellable->currency),
                ecommerce_format_price($cancellable->amount, $cancellable->currency),
            );
        }

        if ($cancellable->allowsByMethod($cash, $mileage)) {
            return;
        }

        // 어느 수단이 모자란지 **그 수단의 숫자로** 알린다 — 합계만 알려 주면 운영자가 무엇을
        // 얼마나 줄여야 하는지 알 수 없어 화면에서 고칠 수 없다.
        $remaining = $cancellable->remainingByMethod();
        $overCash = $cash > $remaining['cash'] + 0.01;

        throw OrderClaimException::refundExceedsMethodRemaining(
            __('sirsoft-ecommerce::messages.claims.cancellable_method.'.($overCash ? 'payment' : 'mileage')),
            ecommerce_format_price($overCash ? $cash : $mileage, $cancellable->currency),
            ecommerce_format_price($overCash ? $remaining['cash'] : $remaining['mileage'], $cancellable->currency),
        );
    }

    /**
     * 환불이 주문 헤더에 동기화하는 컬럼 (S3-D17).
     *
     * **세금 분류 + 현금성 금액만** 담는다. `total_paid_amount`·`total_amount` 를 넣으면
     * 잔여 한도(`total_paid_amount - total_refunded_amount`)가 같은 금액을 두 번 차감한다.
     *
     * `total_cash_equivalent_amount` 가 함께 있어야 한다 —
     * `CashReceiptService::calculateIssuableAmount()` 는 이 값을 **먼저** 보고, 0 이 아니면
     * 세금 분류합이 0 이어도 이 값을 그대로 발급 가능액으로 낸다(폴백). 세금만 0 으로
     * 내리면 발급 가능액이 62,000 으로 남는다(2026-09-16 실측).
     *
     * `mc_*` 두 개는 base 와 짝을 맞춘다 — 한쪽만 내리면 한 행 안에서 어긋난다(S1-D22 전례).
     */
    private const TAX_SYNC_COLUMNS = [
        'total_tax_amount',
        'total_tax_free_amount',
        'total_vat_amount',
        'total_cash_equivalent_amount',
        'mc_total_tax_amount',
        'mc_total_tax_free_amount',
    ];

    /**
     * 이 환불이 끝난 뒤의 **세금 분류 컬럼**을 재계산합니다. (S3-D17)
     *
     * `total_tax_amount`·`total_tax_free_amount`·`total_vat_amount` 세 개만 낸다 —
     * 금액 컬럼을 함께 실으면 클레임 환불이 `total_paid_amount` 를 깎아 잔여 한도
     * (`total_paid_amount - total_refunded_amount`)가 같은 금액을 두 번 차감한다
     * (`RefundExecutionService::execute()` 독블록).
     *
     * **재계산을 여기서 다시 구하는 이유**: 직접 환불신청(`ownsOptionQuantities`)만
     * `previewRefund()` 를 부르고, **반품·교환 발 환불은 부르지 않는다**(실측: RF157·158·146
     * 모두 `ownsOptionQuantities = false`). 그 경로를 빈손으로 두면 결함이 그대로 남는다.
     *
     * 신청 상품이 없으면(출처 소실·취소 발) 빈 배열을 낸다 — 근거 없이 세금 컬럼을 0 으로
     * 만들면 멀쩡한 주문의 과세 근거가 사라진다.
     *
     * @param  Order  $order  주문
     * @param  array  $items  이 환불이 덜어내는 신청 상품 [{order_option_id, cancel_quantity}]
     * @param  AdjustmentResult|null  $preview  직접 환불이 이미 구한 재계산 (있으면 재사용)
     * @return array<string, float> 세금 컬럼 갱신값
     */
    protected function taxColumnUpdatesFor(Order $order, array $items, ?AdjustmentResult $preview): array
    {
        if ($items === []) {
            return [];
        }

        // 직접 환불은 위에서 이미 재계산했다 — 같은 계산을 두 번 돌리지 않는다.
        $source = $preview ?? $this->cancellationService->previewRefund(
            $order,
            $items,
            RefundPriorityEnum::PG_FIRST,
            guardClaimLock: false,
        );

        $updates = [];

        foreach (self::TAX_SYNC_COLUMNS as $column) {
            if (! array_key_exists($column, $source->orderUpdates)) {
                continue;
            }

            $value = $source->orderUpdates[$column];

            // `mc_*` 는 통화별 맵이라 float 캐스팅하면 뭉개진다.
            $updates[$column] = str_starts_with($column, 'mc_') ? $value : (float) $value;
        }

        return $updates;
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

        // S1-D21: 잠금·판정·쓰기를 **한 트랜잭션**에 묶는다.
        //
        // 메모리에 들고 있는 모델 값으로 판정하면 동시에 들어온 두 요청이 **둘 다 같은 출발
        // 상태를 보고 둘 다 통과**한다. 쓰기는 같은 값이라 상태·시각만 보면 1회처럼 보이는데,
        // 뒤따르는 전이 훅(활동로그·알림)이 두 번 돈다 — 상태가 멀쩡해 보이는 것이 이 결함을
        // 숨긴다. 세션3 이 교환·반품에서 같은 형태를 찾아 고쳤고(S3-D16), 환불도 같은 형태였다.
        //
        // 판정만 트랜잭션으로 감싸면 **커밋과 함께 잠금이 풀려** 쓰기 전에 두 요청이 다시
        // 나란히 통과한다(세션3 이 재실측에서 알림 2통 재발로 확인). 잠금이 쓰기까지 유지되도록
        // 한 트랜잭션에 넣어야 한다.
        $optionChanges = [];
        $current = null;
        $updates = [];

        $updated = DB::transaction(function () use ($refund, $target, $context, &$optionChanges, &$current, &$updates) {
            $locked = $this->refundRepository->findForUpdate((int) $refund->getKey());
            $current = ($locked ?? $refund)->refund_status;

            if (! $current->canTransitionTo($target)) {
                throw OrderClaimException::transitionNotAllowed($current->label(), $target->label());
            }

            // 호출층이 들고 있는 인스턴스도 잠금 시점 값으로 맞춘다 — 빼면 뒤따르는 훅이
            // 낡은 값을 `previous` 로 읽어 활동로그 전/후 표가 실제와 어긋난다.
            $refund->setAttribute('refund_status', $current);

            $updates = ['refund_status' => $target] + $this->stampFor($target, $context);

            if (($context['processed_by'] ?? null) !== null) {
                $updates['processed_by'] = $context['processed_by'];
            }

            $fresh = $this->refundRepository->update($refund, $updates);

            // 반려(판매자가 거절)와 철회(구매자가 거둠)는 신청서를 닫는다는 점만 같고 **다른 사실**이다.
            // 상품 행에 둘 다 `rejected` 로 적으면, 화면의 상품 뱃지가 이 값을 그대로 읽으므로 구매자가
            // 자기 철회를 "판매자가 반려함" 으로 읽는다. 신청서 헤더는 `환불철회` 인데 상품 행만 `환불반려`
            // 라 같은 카드 안에서 두 상태가 어긋나며, 정상 서식이라 오류도 로그도 남지 않는다.
            // 반품·교환 옵션은 이미 각 상태 Enum 의 `WITHDRAWN` 을 쓴다 — 환불만 어긋나 있었다.
            $optionStatus = match ($target) {
                RefundStatusEnum::REJECTED => RefundStatusEnum::REJECTED,
                RefundStatusEnum::WITHDRAWN => RefundStatusEnum::WITHDRAWN,
                default => null,
            };

            if ($optionStatus !== null) {
                // 전/후를 적으려면 일괄 갱신 **전** 상태를 읽어야 한다 — 갱신 뒤에 읽으면
                // 전·후가 같은 값이 되어 활동로그 표가 "바뀐 것 없음" 으로 보인다.
                $optionChanges = $this->captureOptionStatusChanges($fresh->id, $optionStatus);

                $this->refundOptionRepository->bulkUpdateStatus(
                    $fresh->id,
                    $optionStatus->value,
                    $updates['processed_by'] ?? null,
                );
            }

            return $fresh->fresh(['refundOptions']);
        });

        if ($optionChanges !== []) {
            HookManager::doAction(
                'sirsoft-ecommerce.claim.refund.option_status_changed',
                $updated,
                $optionChanges,
                $optionChanges[0]['new'] ?? null,
                $updates['processed_by'] ?? null,
            );
        }

        // 상태가 **실제로 바뀐 때만** 훅을 돌린다 (S1-D21 · 세션3 S3-D16 과 같은 처리).
        //
        // 잠금만으로는 부족하다 — 상태 기계가 **같은 상태로의 재진입을 허용**하기 때문이다
        // (`canTransitionTo` 가 `$this === $target` 를 true 로 낸다). 잠금이 두 요청을 줄
        // 세워도 뒤선 요청은 이미 `approved` 인 행을 읽고 `approved → approved` 로 통과해
        // 훅을 한 번 더 돌린다. 실측(2026-09-16 00:13:12 · RF 7): 활동로그가
        // `requested → approved` 1줄 + **`approved → approved` 1줄** 로 두 줄 남았다.
        if ($current !== $target) {
            $this->fireStatusHooks($updated, $current, $target, $context);
        }

        return $updated;
    }

    /**
     * 신청서 상태 전이 훅을 발화합니다. (헤더 직접 전이·상품 단위 전이 공용)
     *
     * 교환·반품과 같은 형태로 뽑아 둔다 — 상품 단위 전이가 헤더를 따라 옮길 때
     * (`syncHeaderFromOptions`) 같은 훅이 같은 순서로 나가야 알림·활동로그가 두 경로에서
     * 갈리지 않는다.
     *
     * @param  OrderRefund  $refund  환불신청서
     * @param  RefundStatusEnum  $previous  직전 상태
     * @param  RefundStatusEnum  $target  목표 상태
     * @param  array  $context  부가 정보 (알림 억제 플래그 등)
     * @return void 반환값 없음
     */
    protected function fireStatusHooks(
        OrderRefund $refund,
        RefundStatusEnum $previous,
        RefundStatusEnum $target,
        array $context = [],
    ): void {
        // 네 번째 인수 `$context` — 알림 리스너가 `suppress_notification` 를 읽어 자동 전이의
        // 중간 단계 알림만 끈다. 기존 3인수 구독자는 그대로 둔다(PHP 는 유저 함수에 인수가
        // 더 넘어와도 오류를 내지 않는다 — 모자랄 때만 ArgumentCountError).
        HookManager::doAction('sirsoft-ecommerce.claim.refund.status_changed', $refund, $previous->value, $target->value, $context);

        $specific = match ($target) {
            RefundStatusEnum::APPROVED => 'sirsoft-ecommerce.claim.refund.approved',
            RefundStatusEnum::REJECTED => 'sirsoft-ecommerce.claim.refund.rejected',
            RefundStatusEnum::WITHDRAWN => 'sirsoft-ecommerce.claim.refund.withdrawn',
            RefundStatusEnum::ON_HOLD => 'sirsoft-ecommerce.claim.refund.on_hold',
            default => null,
        };

        if ($specific !== null) {
            HookManager::doAction($specific, $refund);
        }
    }

    /**
     * 신청 상품 단위로 상태를 전이합니다. (교환·반품과 같은 계약 — D25)
     *
     * 헤더 상태는 **살아 있는 상품이 모두 같은 상태에 도달했을 때만** 따라간다
     * (`syncHeaderFromOptions`). 수량을 실은 지시(`[{id, quantity}]`)는 그 행을 갈라서
     * 지정 수량만 전이하고, 잔여는 새 행으로 남아 신청 상태를 유지한다.
     *
     * **환불에는 원 주문옵션 복귀가 없다.** 교환·반품은 접수 시점에 상품을 코스 상태로
     * 옮기지만, 직접 환불신청은 상품을 판매 상태 그대로 두고 수량만 선점한다 — 되돌릴
     * 상태가 없고, 반려로 선점이 풀리는 것은 수량 잠금 쿼리가 종결 행을 빼면서 일어난다.
     *
     * @param  OrderRefund  $refund  환불신청서
     * @param  array<int, int|array{id: int, quantity?: int|null}>  $items  전이 지시 (빈 배열이면 전체)
     * @param  RefundStatusEnum  $target  목표 상태
     * @param  array  $context  부가 정보 (processed_by)
     * @return array{succeeded: array<int, int>, skipped: array<int, array{id: int, message: string}>, split: array<int, array{id: int, quantity: int, remainder_id: int, remainder_quantity: int}>} 전이 결과
     *
     * @throws OrderClaimException 완료 전이를 시도한 경우
     */
    public function transitionOption(
        OrderRefund $refund,
        array $items,
        RefundStatusEnum $target,
        array $context = [],
    ): array {
        // 완료는 환불 실행(RefundExecutionService)만이 진입점이다 — 상품 단위로 열어 두면
        // 돈이 나가지 않은 채 그 상품만 환불완료로 보인다.
        if ($target === RefundStatusEnum::COMPLETED) {
            throw OrderClaimException::completionRequiresCompletionFlow();
        }

        $processedBy = $context['processed_by'] ?? null;
        $specs = ClaimItemSpecs::normalize($items);
        $quantities = ClaimItemSpecs::quantities($specs);
        $rows = $this->resolveItems($refund, ClaimItemSpecs::ids($specs));

        $succeeded = [];
        $skipped = [];
        $changes = [];
        $split = [];

        DB::transaction(function () use ($rows, $quantities, $target, $processedBy, &$succeeded, &$skipped, &$changes, &$split) {
            foreach ($rows as $item) {
                $current = $item->option_status instanceof RefundStatusEnum
                    ? $item->option_status
                    : RefundStatusEnum::from((string) $item->option_status);

                // 끝난 상품(반려·철회·완료)은 전이 게이트가 막지 못한다 — 자기 전이는 언제나
                // 참이라 이미 반려된 상품에 반려를 다시 지시하면 처리자·상태가 다시 쓰인다.
                if ($current->isFinal() || ! $current->canTransitionTo($target)) {
                    $skipped[] = [
                        'id' => $item->id,
                        'message' => __('sirsoft-ecommerce::exceptions.claim_transition_not_allowed', [
                            'from' => $current->label(),
                            'to' => $target->label(),
                        ]),
                    ];

                    continue;
                }

                $previousQuantity = (int) $item->quantity;

                try {
                    $remainder = $this->transitionItem($item, $target, $processedBy, $quantities[$item->id] ?? null);
                } catch (OrderClaimException $e) {
                    $skipped[] = ['id' => $item->id, 'message' => $e->getMessage()];

                    continue;
                }

                $change = [
                    'option_id' => $item->id,
                    'previous' => $current->value,
                    'new' => $target->value,
                ];

                if ($remainder !== null) {
                    $change['quantity'] = (int) $item->fresh()->quantity;
                    $change['remainder_option_id'] = $remainder->id;

                    $split[] = [
                        'id' => $item->id,
                        'quantity' => $change['quantity'],
                        'remainder_id' => $remainder->id,
                        'remainder_quantity' => $previousQuantity - $change['quantity'],
                    ];
                }

                $changes[] = $change;

                $succeeded[] = $item->id;
            }
        });

        $fresh = $refund->fresh(['refundOptions']);

        // 상품 단위 전이는 헤더 전이와 다른 사실이다 — 상품 상태가 갈리면 헤더는 움직이지
        // 않으므로, 이 훅이 없으면 운영자가 한 상태 변경이 아무 데도 남지 않는다.
        if ($changes !== []) {
            HookManager::doAction(
                'sirsoft-ecommerce.claim.refund.option_status_changed',
                $fresh,
                $changes,
                $target->value,
                $processedBy,
            );
        }

        $this->syncHeaderFromOptions($fresh, $processedBy);

        return ['succeeded' => $succeeded, 'skipped' => $skipped, 'split' => $split];
    }

    /**
     * 신청 상품 한 건의 상태를 바꿉니다. (상품별 저장점)
     *
     * 순서가 계약이다 — `split()` → `option_status` 갱신. 분할이 이 저장점 **안**에 있어야
     * 전이가 실패했을 때 분할도 함께 되돌아간다.
     *
     * @param  OrderRefundOption  $item  신청 상품 행
     * @param  RefundStatusEnum  $target  목표 상태 (게이트 통과 전제)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @param  int|null  $quantity  이번에 전이할 수량 (null 이면 전량)
     * @return OrderRefundOption|null 분할로 생긴 잔여 행 (분할하지 않았으면 null)
     *
     * @throws OrderClaimException 나눌 수 없는 행 / 수량 범위 밖
     */
    protected function transitionItem(
        OrderRefundOption $item,
        RefundStatusEnum $target,
        ?int $processedBy,
        ?int $quantity = null,
    ): ?OrderRefundOption {
        return DB::transaction(function () use ($item, $target, $processedBy, $quantity) {
            $remainder = null;

            if ($quantity !== null) {
                ['remainder' => $remainder] = $this->claimItemSplitter->split($item, $quantity);
                $item->refresh();
            }

            $this->refundOptionRepository->update($item, [
                'option_status' => $target,
                'processed_by' => $processedBy ?? $item->processed_by,
            ]);

            return $remainder;
        });
    }

    /**
     * 살아 있는 신청 상품이 모두 같은 상태에 도달했으면 헤더를 그 상태로 옮깁니다.
     *
     * 판정 규칙은 교환·반품과 같다 — 끝난 상품은 빼고, 살아 있는 상품이 없으면 끝난 상태가
     * 하나로 모일 때 그것을 따라가며, 한 단계 이상 앞서 있으면 건너뛴 중간 단계를 차례로
     * 거친다(`headerPathTo`).
     *
     * **`processing`·`completed` 는 헤더가 따라가지 않는다.** 둘 다 환불 실행이 소유하는
     * 상태라, 상품 행이 그 값에 모였다고 헤더를 옮기면 **돈이 움직이지 않았는데** 신청서가
     * 처리중·완료로 보인다. 교환·반품은 완료만 빼면 되지만(그쪽엔 처리중이 없다) 환불은
     * 둘 다 빼야 한다.
     *
     * @param  OrderRefund  $refund  환불신청서
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return void 반환값 없음
     */
    protected function syncHeaderFromOptions(OrderRefund $refund, ?int $processedBy): void
    {
        $refund->loadMissing('refundOptions');

        $rows = $refund->refundOptions;

        if ($rows->isEmpty()) {
            return;
        }

        $living = $rows->reject(fn (OrderRefundOption $item) => $this->optionStatusOf($item)->isFinal());

        $statuses = ($living->isEmpty() ? $rows : $living)
            ->map(fn (OrderRefundOption $item) => $this->optionStatusOf($item)->value)
            ->unique()
            ->values();

        if ($statuses->count() !== 1) {
            return;
        }

        $target = RefundStatusEnum::from($statuses->first());

        if (
            $target === $refund->refund_status
            || $target === RefundStatusEnum::COMPLETED
            || $target === RefundStatusEnum::PROCESSING
        ) {
            return;
        }

        $path = $this->headerPathTo($refund->refund_status, $target);

        if ($path === []) {
            return;
        }

        $current = $refund;

        foreach ($path as $step) {
            $previous = $current->refund_status;
            $updates = ['refund_status' => $step] + $this->stampFor($step, []);

            if ($processedBy !== null) {
                $updates['processed_by'] = $processedBy;
            }

            $current = $this->refundRepository->update($current, $updates);

            $this->fireStatusHooks($current, $previous, $step);
        }
    }

    /**
     * 헤더가 현재 상태에서 목표 상태까지 거쳐야 할 단계 목록을 전이 게이트로 찾습니다. (BFS)
     *
     * 돈이 움직여야 도달하는 상태(`processing`·`completed`)는 경유지로도 쓰지 않는다 —
     * 경유만 해도 그 단계의 훅이 발화해 알림이 나간다.
     *
     * @param  RefundStatusEnum  $from  현재 헤더 상태
     * @param  RefundStatusEnum  $to  상품들이 모인 상태
     * @return array<int, RefundStatusEnum> 거쳐야 할 상태 목록 (목표 포함, 현재 제외)
     */
    protected function headerPathTo(RefundStatusEnum $from, RefundStatusEnum $to): array
    {
        if ($from === $to) {
            return [];
        }

        $forbidden = [RefundStatusEnum::PROCESSING, RefundStatusEnum::COMPLETED];
        $queue = [[$from, []]];
        $visited = [$from->value => true];

        while ($queue !== []) {
            [$node, $path] = array_shift($queue);

            foreach ($node->allowedTransitions() as $next) {
                if (isset($visited[$next->value]) || in_array($next, $forbidden, true)) {
                    continue;
                }

                $nextPath = [...$path, $next];

                if ($next === $to) {
                    return $nextPath;
                }

                $visited[$next->value] = true;
                $queue[] = [$next, $nextPath];
            }
        }

        return [];
    }

    /**
     * 신청 상품 행을 해석합니다.
     *
     * @param  OrderRefund  $refund  환불신청서
     * @param  array<int, int>  $itemIds  신청 상품 행 ID 목록 (빈 배열이면 전체)
     * @return array<int, OrderRefundOption> 신청 상품 행 목록
     */
    protected function resolveItems(OrderRefund $refund, array $itemIds): array
    {
        $refund->loadMissing('refundOptions');

        if ($itemIds === []) {
            return $refund->refundOptions->all();
        }

        $ids = array_map('intval', $itemIds);

        return $refund->refundOptions
            ->filter(fn (OrderRefundOption $item) => in_array($item->id, $ids, true))
            ->values()
            ->all();
    }

    /**
     * 상품 행 상태를 Enum 으로 읽습니다. (캐스트 여부와 무관하게)
     *
     * @param  OrderRefundOption  $item  신청 상품 행
     * @return RefundStatusEnum 상품 행 상태
     */
    protected function optionStatusOf(OrderRefundOption $item): RefundStatusEnum
    {
        return $item->option_status instanceof RefundStatusEnum
            ? $item->option_status
            : RefundStatusEnum::from((string) $item->option_status);
    }

    /**
     * 상품 단위 판단이 갈린 뒤 신청서 환불액을 승인 범위에 맞춰 다시 계산합니다. (§4-5 · A1)
     *
     * **새로 쓰는 것은 "얼마가 빠졌는가" 하나뿐이고 나머지는 전부 기존 계약이다** — 실제
     * 반영은 `updateAmounts()` 가 한다. 그래서 전/후 스냅샷과 `claim.refund.amounts_updated`
     * 훅(활동로그 리스너가 이미 구독), D19 상한 가드, 다통화 환산, 행 금액 동기화, 잠금
     * 순서, 종결·처리중 거절이 코드 추가 없이 그대로 붙는다.
     *
     * 비율의 분모는 "이번 전이 **직전에** 돈이 덮고 있던 행" 이다(`$decidedIds` 가 그 경계를
     * 알려 준다). 분모를 전건으로 두면 앞 라운드에서 이미 빠진 몫을 **두 번 빼서** 남은
     * 상품의 환불액이 라운드마다 줄어든다 — 합계는 언제나 맞아떨어져 드러나지 않는다.
     *
     * @param  OrderRefund  $refund  환불신청서
     * @param  array<int, int>  $decidedIds  이번 전이에서 판단이 내려진 행 ID
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderRefund 재계산된 환불신청서 (변화가 없으면 원본)
     */
    public function recalculateAmountForApprovedItems(
        OrderRefund $refund,
        array $decidedIds = [],
        ?int $processedBy = null,
    ): OrderRefund {
        $refund->loadMissing('refundOptions');

        $covered = $refund->refundOptionsForAmount();
        $dropped = $refund->rejectedRefundOptions();

        if ($dropped->isEmpty()) {
            return $refund;
        }

        $ratio = $this->coverageRatio($covered, $dropped, $decidedIds);

        if ($ratio < 1.0) {
            $refund = $this->updateAmounts($refund, [
                'refund_amount' => round((float) $refund->refund_amount * $ratio, 2),
                'refund_points_amount' => round((float) $refund->refund_points_amount * $ratio, 2),
            ], $processedBy);
        }

        // 반려 행의 환불액을 0 으로 내린다. 안분(`applyAmountRatioToOptions`)이 이미 그 행을
        // 가중치에서 뺐으므로 값이 **그대로 남아 있고**, 남겨 두면 D19 선점액 집계가 그것을
        // 계속 센다 — 헤더 합계는 맞아서 어디에도 드러나지 않는다.
        foreach ($dropped as $option) {
            if ((float) $option->refund_amount !== 0.0) {
                $this->refundOptionRepository->updateRefundAmount($option, 0.0);
            }
        }

        return $refund->fresh(['refundOptions']) ?? $refund;
    }

    /**
     * 이번 판단으로 헤더 금액이 남겨야 할 비율을 구합니다.
     *
     * 가중치는 행 환불액이고, 그 합이 0 이면(배송비만 환불하는 신청서 등) 수량으로 나눈다.
     * 분모는 **덮고 있던 행 + 이번에 빠진 행** 이다 — 앞 라운드에서 이미 0 으로 내려간 행을
     * 분모에 넣으면 같은 몫이 두 번 빠진다.
     *
     * @param  Collection<int, OrderRefundOption>  $covered  아직 덮는 행
     * @param  Collection<int, OrderRefundOption>  $dropped  반려·철회 행
     * @param  array<int, int>  $decidedIds  이번 전이에서 판단이 내려진 행 ID
     * @return float 남길 비율 (0.0 ~ 1.0)
     */
    protected function coverageRatio(Collection $covered, Collection $dropped, array $decidedIds): float
    {
        $decided = array_map('intval', $decidedIds);
        $justDropped = $dropped->filter(
            fn (OrderRefundOption $option): bool => $decided === [] || in_array((int) $option->id, $decided, true)
        );

        $money = fn (OrderRefundOption $option): float => max(0.0, (float) $option->refund_amount);
        $coveredMoney = (float) $covered->sum($money);
        $droppedMoney = (float) $justDropped->sum($money);

        if ($coveredMoney + $droppedMoney > 0) {
            return $coveredMoney / ($coveredMoney + $droppedMoney);
        }

        $quantity = fn (OrderRefundOption $option): float => max(0.0, (float) $option->quantity);
        $coveredQuantity = (float) $covered->sum($quantity);
        $droppedQuantity = (float) $justDropped->sum($quantity);

        if ($coveredQuantity + $droppedQuantity <= 0) {
            return 1.0;
        }

        return $coveredQuantity / ($coveredQuantity + $droppedQuantity);
    }

    /**
     * 자동 전이 대상 주문옵션 상태 값 목록을 반환합니다. (`claim.instant_cancel_statuses`)
     *
     * 설정 키 이름은 유지하되 뜻은 D2 로 바뀌었다 — 이 상태의 상품도 환불신청서를 만들고,
     * 다른 것은 승인·완료까지 자동으로 진행한다는 점뿐이다.
     *
     * @return array<string> 상태 값 목록
     */
    public function instantCancelStatusValues(): array
    {
        return array_values((array) $this->settingsService->getSetting(
            'claim.instant_cancel_statuses',
            [
                OrderStatusEnum::PENDING_ORDER->value,
                OrderStatusEnum::PENDING_PAYMENT->value,
                OrderStatusEnum::PAYMENT_COMPLETE->value,
            ],
        ));
    }

    /**
     * 자동승인 대상 주문옵션 상태 값 목록을 반환합니다. (`claim.auto_approve_refund_statuses` — D8)
     *
     * `claim.auto_approve_refund_request` 가 켜져 있을 때만 의미가 있다. 토글만 보고 전건을
     * 승인하면 "출고 전만 자동승인" 같은 운영을 표현할 수 없다.
     *
     * @return array<string> 상태 값 목록
     */
    public function autoApproveRefundStatusValues(): array
    {
        return array_values((array) $this->settingsService->getSetting(
            'claim.auto_approve_refund_statuses',
            [OrderStatusEnum::PAYMENT_COMPLETE->value, OrderStatusEnum::PREPARING->value],
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
            // 반려 시각을 함께 찍는다(F4). 예전에는 사유만 남겨, 상세의 "승인·반려·철회 일시"
            // 자리에서 환불 유형만 영영 빈칸이었다 — 교환·반품에는 있는 값이다.
            RefundStatusEnum::REJECTED => [
                'reject_reason' => $context['reject_reason'] ?? null,
                'rejected_at' => Carbon::now(),
            ],
            RefundStatusEnum::WITHDRAWN => ['withdrawn_at' => Carbon::now()],
            default => [],
        };
    }
}
