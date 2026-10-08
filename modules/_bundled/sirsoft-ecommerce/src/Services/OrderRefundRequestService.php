<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
use Modules\Sirsoft\Ecommerce\Services\Concerns\AppliesRefundBankAccount;
use Modules\Sirsoft\Ecommerce\Services\Concerns\FiresClaimHooksAfterCommit;
use Modules\Sirsoft\Ecommerce\Services\Concerns\GuardsClaimFilterResults;
use Modules\Sirsoft\Ecommerce\Services\Concerns\ReportsClaimRequestUpdate;
use Modules\Sirsoft\Ecommerce\Support\ClaimItemSpecs;
use Modules\Sirsoft\Ecommerce\Support\SystemActor;

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
    use AppliesRefundBankAccount;
    use FiresClaimHooksAfterCommit;
    use GuardsClaimFilterResults;
    use ReportsClaimRequestUpdate;

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
     * 이 주문에서 돌려줄 현금(결제수단 몫)이 남아 있는지 판정합니다. (결함 4 — 계좌 요구·환불수단·자동 전이 공용)
     *
     * 판정 본문은 `RefundExecutionService::hasCashToReturn()` 하나다 — 그 서비스가 이 서비스를 주입받을 수
     * 없어(순환) 술어를 그쪽에 두고 여기서 위임한다.
     *
     * @param  Order  $order  주문
     * @return bool 돌려줄 현금이 남아 있으면 true
     */
    public function hasCashToReturn(Order $order): bool
    {
        return $this->refundExecutionService->hasCashToReturn($order);
    }

    /**
     * 마일리지로만 돌려주는 회원 주문인지 판정합니다. (현금 0원 + 남은 사용 마일리지 > 0)
     *
     * @param  Order  $order  주문
     * @return bool 마일리지로만 돌려주면 true
     */
    public function refundsOnlyMileage(Order $order): bool
    {
        return $this->refundExecutionService->refundsOnlyMileage($order);
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
     * @param  array<int, string>|null  $gateStatuses  자격 판정 상태 목록 (관리자 직권 접수는 물리 게이트 — null 이면 고객 신청 가능 상태)
     * @param  string|null  $branch  분기 고정 (관리자 직권 접수는 운영자 선택 — null 이면 상태로 판정)
     * @return array{branch: string, adjustment: AdjustmentResult} 분기 + 환불 계산 결과
     *
     * @throws OrderClaimException 신청 자격 위반
     */
    public function previewRefund(Order $order, array $items, RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST, ?int $excludeRefundId = null, ?array $gateStatuses = null, ?string $branch = null): array
    {
        if ($branch === null) {
            $branch = $this->resolveBranch($order, $items);
        } elseif (empty($items)) {
            throw OrderClaimException::itemsRequired();
        }

        $resolved = $this->claimQuantityGuard->resolveClaimItems(
            $order,
            $items,
            ClaimTypeEnum::REFUND,
            $gateStatuses ?? $this->requestableStatusValues(),
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
        // 접수의 before·filter 훅 — 분기 판정·주문 잠금·쓰기 전이다. 이 접수는 환불할 돈이 없으면
        // 취소신청서로 끝나지만(`claim.cancel.requested`), 들어오는 입구는 하나라 훅도 `claim.refund.*`
        // 한 벌이다. 필터는 대상 상품·사유(`CLAIM_FILTERABLE_REQUEST_KEYS`)만 바꿀 수 있다 — 접수 창구
        // 축(`refund_source_type`·`branch`·`gate_statuses`·`user_selectable_reason_only`·
        // `pg_already_settled`·`auto_advance`)과 신청자·환불 우선순위·계좌는 되돌린다. 바뀐 대상·사유는
        // 아래 분기 판정·사유 해석·잠금 안 수량 가드를 고객 입력과 똑같이 지난다.
        HookManager::doAction('sirsoft-ecommerce.claim.refund.before_request', $order, $payload);

        $payload = $this->acceptClaimFilterResult(
            HookManager::applyFilters('sirsoft-ecommerce.claim.refund.filter_request_data', $payload, $order),
            $payload,
            self::CLAIM_FILTERABLE_REQUEST_KEYS,
        );

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

        // 자동 전이(`autoAdvance`)가 이 트랜잭션 안에서 PG 를 부를 수 있다. 그 안쪽 실행의 트랜잭션은
        // savepoint 라 **바깥 커밋 실패는 안쪽 catch 가 잡지 못한다** — 여기서 한 번 더 받아 PG 승인
        // 기록을 남긴다(§14-C). 표식 대상 신청서가 같은 트랜잭션에서 생겨 함께 롤백됐으면 표식은
        // 건너뛰고 critical 로그만 남는다(사람이 PG 콘솔과 대사).
        $this->refundExecutionService->forgetPgSettlement();

        try {
            $outcome = $this->requestInTransaction($order, $items, $reason, $payload, $refundPriority, $branch, $sourceType, $gateStatuses);
        } catch (\Throwable $e) {
            $settled = $this->refundExecutionService->takePgSettlement();

            if ($settled !== null) {
                $this->recordOrphanPgSettlement((int) $settled['refund_id'], $settled, $e);
            }

            throw $e;
        }

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
     * 접수 본문을 한 트랜잭션에서 실행합니다. (request() 의 쓰기 단계)
     *
     * @param  Order  $order  주문
     * @param  array  $items  대상 상품
     * @param  ClaimReason  $reason  사유
     * @param  array  $payload  접수 페이로드
     * @param  mixed  $refundPriority  환불 우선순위 (페이로드 그대로 — RefundPriorityEnum)
     * @param  string  $branch  분기
     * @param  mixed  $sourceType  발생 출처 (페이로드 그대로 — RefundSourceTypeEnum)
     * @param  array<int, string>  $gateStatuses  자격 판정 상태 목록
     * @return array{cancel: ?OrderCancel, refund: ?OrderRefund, adjustment: AdjustmentResult} 접수 결과
     */
    protected function requestInTransaction(
        Order $order,
        array $items,
        ClaimReason $reason,
        array $payload,
        mixed $refundPriority,
        string $branch,
        mixed $sourceType,
        array $gateStatuses,
    ): array {
        return DB::transaction(function () use ($order, $items, $reason, $payload, $refundPriority, $branch, $sourceType, $gateStatuses) {
            // 잠금은 잔여 수량을 읽기 전에 잡는다 — 읽고 나서 잠그면 이미 두 요청이 같은 값을 봤다.
            $lockedOrder = $this->orderRepository->findForUpdateOrFail($order->id);
            $lockedOrder->load(['options', 'shippings', 'payment']);

            // 입금이 결제행에 기록된 결제 전 주문은 받은 돈을 헤더에 먼저 올린다(S3-D7). 환불 산정·실행 상한이
            // 모두 헤더(받은 돈 − 환불 누적)를 읽으므로, 올리지 않으면 받은 돈 몫이 0원으로 계산된다.
            // 같은 트랜잭션이라 접수가 거절되면 함께 되돌아간다.
            $this->syncRecordedDepositHeader($lockedOrder);

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
            $this->fireAfterCommit(fn () => HookManager::doAction(
                'sirsoft-ecommerce.claim.refund.requested',
                $refund,
                $lockedOrder->fresh(),
                ['suppress_notification' => $this->willAdvanceOnIntake($lockedOrder, $refund, $branch, $payload)]
            ));

            $refund = $this->advanceAfterRequest($lockedOrder, $refund, $branch, $payload);

            return ['cancel' => null, 'refund' => $refund, 'adjustment' => $adjustment];
        });
    }

    /**
     * 이 주문에서 되돌릴 돈이 남아 있지 않은지 판정합니다. (D1 — 환불신청서 생략 조건)
     *
     * 두 축이다 — ⓐ 아직 결제 전이라 받은 돈이 없거나, ⓑ 계산된 환불액(PG + 마일리지)이
     * 0 이거나. ⓑ 를 함께 보지 않으면 0원 주문·전액 쿠폰 주문이 "결제완료" 라는 이유로
     * 0원짜리 환불신청서를 만들고, 그 행은 송금할 것이 없어 영영 `approved` 에 남는다(P11).
     *
     * ⓐ 는 **결제행에 입금이 기록되지 않은** 결제 전 주문만이다(S3-D7). [입금확인]에서 결제완료 처리를 끄고
     * 입금을 기록만 한 주문은 결제대기인데도 돈을 받았다 — 주문 상태만 보면 그 돈이 환불신청서 없이 사라진다.
     * 그런 주문은 ⓑ 로 판정한다(받은 돈은 접수가 헤더에 먼저 올린다 — `syncRecordedDepositHeader()`).
     *
     * @param  Order  $order  주문
     * @param  AdjustmentResult  $adjustment  환불 계산 결과
     * @return bool 되돌릴 돈이 없으면 true
     */
    protected function hasNothingToRefund(Order $order, AdjustmentResult $adjustment): bool
    {
        if ($order->order_status->isBeforePayment()
            && ! $this->refundExecutionService->hasRecordedDepositBeforePayment($order)) {
            return true;
        }

        return ((float) $adjustment->refundAmount + (float) $adjustment->refundPointsAmount) <= 0.0;
    }

    /**
     * 입금이 결제행에 기록된 결제 전 주문의 헤더에 받은 돈을 올립니다. (S3-D7 — 취소 접수 직전, 잠근 주문)
     *
     * 「입금 내역만 기록」은 결제행만 입금완료로 만들고 주문 헤더는 받은 돈 0·받을 돈 그대로 둔다. 환불 산정
     * (`OrderAdjustmentService`)과 실행 상한(`CancellableAmountCalculator`)은 헤더를 읽으므로, 그대로 취소하면
     * 받은 돈 몫이 0원으로 계산된다. 운영자가 상태를 올릴 때 헤더를 맞추는 규칙(S2-D4)과 같은 메서드를 쓴다 —
     * 두 경로가 따로 맞추면 같은 주문의 받은 돈이 경로마다 달라진다. 멱등이다(헤더가 채워지면 맞추지 않는다).
     *
     * @param  Order  $order  주문 (잠금·로드 완료)
     * @return void 반환값 없음
     */
    protected function syncRecordedDepositHeader(Order $order): void
    {
        if (! $this->refundExecutionService->hasRecordedDepositBeforePayment($order)) {
            return;
        }

        app(OrderProcessingService::class)->syncPaidHeaderFromSettledPayment($order);
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
            // 돌려줄 현금이 없는 회원 주문은 결제수단과 무관하게 「마일리지」다(결함 4).
            'refund_method' => $this->refundExecutionService->refundMethodForClaim($order),
            'reason_code' => $reason->code,
            'reason_detail' => $payload['reason_detail'] ?? null,
            'fault_type' => $reason->fault_type,
            'requested_by' => $payload['requested_by'] ?? null,
            'refund_amount' => max(0, $adjustment->refundAmount),
            'refund_points_amount' => $adjustment->refundPointsAmount,
            'refund_shipping_amount' => $adjustment->shippingDifference,
            // 환불액을 나눈 우선순위(계산 결과가 든 값) — 응답·수정 화면이 추측하지 않고 이 값을 읽는다.
            'refund_priority' => $adjustment->refundPriority,
            'mc_refund_amount' => $adjustment->mcRefundAmount,
            'mc_refund_points_amount' => $adjustment->mcRefundPointsAmount,
            'mc_refund_shipping_amount' => $adjustment->mcRefundShippingAmount,
            'original_calculation_snapshot' => $adjustment->originalSnapshot,
            'recalculated_snapshot' => $adjustment->recalculatedSnapshot,
            'refund_bank_code' => $payload['refund_bank']['bank_code'] ?? null,
            'refund_bank_account' => $payload['refund_bank']['account_number'] ?? null,
            'refund_bank_holder' => $payload['refund_bank']['holder'] ?? null,
            // 교환으로 받은 상품을 환불하면 그 교환 때 낸 상위 옵션 차액도 낸 수단 그대로 돌려준다(S2·Q9)
            'exchange_difference_refunds' => app(ExchangeDifferenceRefundPlanner::class)->plan($order, $cancelItems) ?: null,
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
     * @param  OrderRefund|null  $refund  접수된 환불신청서 (현금 0원 마일리지 환불 판정)
     * @return bool 자동 전이하면 true
     */
    protected function willAutoAdvance(Order $order, string $branch, array $payload, ?OrderRefund $refund = null): bool
    {
        if ($branch !== self::BRANCH_AUTO && ! (bool) ($payload['auto_advance'] ?? false)) {
            return false;
        }

        // 바깥에서 이미 집행된 환불은 수단을 묻지 않는다 — 돈은 이미 나갔으므로 멈추면
        // 운영자에게 "송금하세요" 로 보이고, 그대로 이체하면 이중 환불이다.
        if ((bool) ($payload['pg_already_settled'] ?? false)) {
            return true;
        }

        return $this->refundGateAutoApproves($order, $refund);
    }

    /**
     * 이 환불이 결제수단 자동승인 게이트를 통과하는지 판정합니다. (P16 + 결함 4)
     *
     * 현금 0원 「마일리지로 환불」은 계좌 송금이 없다 — P16 의 정지 근거(사람이 송금해야 끝난다)가 없으므로
     * 결제수단(무통장·가상계좌 등)과 무관하게 통과시킨다. 그 밖에는 결제수단 카탈로그 판정을 따른다.
     *
     * @param  Order  $order  주문
     * @param  OrderRefund|null  $refund  접수된 환불신청서
     * @return bool 자동 승인 대상이면 true
     */
    protected function refundGateAutoApproves(Order $order, ?OrderRefund $refund): bool
    {
        if ($refund !== null
            && $refund->refund_method === RefundMethodEnum::POINTS
            && (float) $refund->refund_amount <= 0.0) {
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
        if (! $this->refundGateAutoApproves($order, $refund)) {
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
        return $this->willAutoAdvance($order, $branch, $payload, $refund) || $this->willAutoApprove($order, $refund);
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

        if ($this->willAutoAdvance($order, $branch, $payload, $refund)) {
            $advance = fn () => $this->autoAdvance($refund, [
                'processed_by' => $processedBy,
                'pg_already_settled' => (bool) ($payload['pg_already_settled'] ?? false),
            ]);

            // 운영자가 [주문취소]를 눌러 시작했으면 그 운영자가 작업자다. 고객 취소의 자동 진행은
            // 누른 사람이 없다 — 로그인한 고객이 승인·완료 작업자로 남지 않게 시스템으로 돌린다
            // (S1-D16: 활동 로그 작업자는 `processed_by` 가 아니라 `Auth::id()` 에서 온다).
            return $processedBy !== null ? $advance() : SystemActor::run($advance);
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
        // (#599 세션A 실측 2026-09-21 · RF20260921-1616082573 · 운영 결정 2026-09-22).
        //
        // 새 라벨을 만들지 않고 저장소가 이미 쓰는 관례에 합류한다 — 1-T 입금기한 만료
        // 자동취소도 작업자를 NULL 로 남기고 화면이 그것을 시스템으로 읽는다.
        // 바로 위 `autoAdvance` 는 **관리자가 [주문취소]를 눌러** 시작한 경로라 주체가 있고,
        // 그 책임 추적을 지우면 안 되므로 `$processedBy` 를 그대로 넘긴다.
        //
        // 인자를 비우는 것만으로는 활동 로그 작업자가 바뀌지 않는다 — 작업자는 `Auth::id()` 다.
        // 그래서 전이 자체를 시스템으로 돌린다(S1-D16).
        return SystemActor::run(fn () => $this->transition($refund, RefundStatusEnum::APPROVED));
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
     * | `POINTS` | 완료 | 쓴 마일리지 반환 + 현금분 마일리지 지급이 같은 트랜잭션에서 끝난다(D1) — 돈이 돌아갔는데 상태만 '승인' 이면 운영자가 추가 이체해 이중 환불이 난다(P12) |
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

        $resolver = app(PaymentMethodResolver::class);

        if (! $resolver->supportsPartialCancel($order->payment)) {
            throw $resolver->partialCancelBlockReason($order->payment) === 'vbank_awaiting_deposit'
                ? OrderClaimException::partialCancelNotSupportedBeforeVbankDeposit()
                : OrderClaimException::partialCancelNotSupported();
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
     * 신청자가 환불신청서를 철회합니다. (또는 고객 요청으로 운영자가 대신 철회 — S14)
     *
     * 운영자 대리 철회는 출처(구매자 편집 가능 여부)를 보지 않는다 — 판매자가 접수한 건도 고객 요청이면
     * 운영자가 거둘 수 있다. 철회 가능 단계(`isWithdrawable()`)는 고객 철회와 같고, 사유는 필수다.
     *
     * @param  OrderRefund  $refund  환불신청서
     * @param  int|null  $withdrawnByAdmin  고객 요청으로 대신 철회하는 관리자 ID (null 이면 신청자 본인 철회)
     * @param  string|null  $withdrawReason  대리 철회 사유 (관리자 대리 철회에서 필수)
     * @return OrderRefund 철회된 환불신청서
     *
     * @throws OrderClaimException 철회 불가 상태 / 대리 철회 사유 누락
     */
    public function withdraw(OrderRefund $refund, ?int $withdrawnByAdmin = null, ?string $withdrawReason = null): OrderRefund
    {
        $buyerMayWithdraw = $withdrawnByAdmin !== null || $refund->refund_source_type?->isBuyerEditable();

        if (! $buyerMayWithdraw || ! $refund->isWithdrawable()) {
            throw OrderClaimException::notWithdrawable();
        }

        $withdrawReason = $withdrawReason === null ? null : trim($withdrawReason);

        if ($withdrawnByAdmin !== null && ($withdrawReason === null || $withdrawReason === '')) {
            throw OrderClaimException::withdrawReasonRequired();
        }

        // 철회 가능 판정 뒤·쓰기 전 — 확장이 예외로 철회를 막을 수 있다. 이어지는 전이가
        // `before_status_change` 를 한 번 더 낸다(층이 다른 두 자리).
        HookManager::doAction('sirsoft-ecommerce.claim.refund.before_withdraw', $refund);

        return $this->transition($refund, RefundStatusEnum::WITHDRAWN, $withdrawnByAdmin === null ? [] : [
            'processed_by' => $withdrawnByAdmin,
            'withdrawn_by_admin' => $withdrawnByAdmin,
            'withdraw_reason' => $withdrawReason,
        ]);
    }

    /**
     * 승인 전 환불신청서의 사유·상세 사유를 수정합니다. (D13)
     *
     * @param  OrderRefund  $refund  환불신청서
     * @param  array  $payload  수정 페이로드 (reason_code? / reason_detail?)
     * @param  bool  $operator  운영자 수정 여부 (S15 — 출처와 무관하게 수정, 판매자 귀책 사유 허용)
     * @return OrderRefund 수정된 환불신청서
     *
     * 수정 가능 여부는 **신청서 행 잠금 안에서 다시 읽은 상태**로 판정한다(#599 validate P2 #8) —
     * 호출부 모델로 판정하면 그 사이 승인된 신청서의 사유·계좌가 수정된다. 교환·반품의
     * `updateRequest()` 와 같은 규율이다.
     *
     * 실제로 바뀐 항목은 커밋 뒤 `claim.refund.request_updated` 로 전/후와 함께 나간다 — 활동 로그가 운영자
     * 대리 수정의 흔적을 남긴다(`ReportsClaimRequestUpdate`). 환불 계좌는 바뀐 사실만 싣는다.
     *
     * @throws OrderClaimException 수정 불가 상태 / 사유 부재 / 환불 계좌 3필드 불완전
     */
    public function updateRequest(OrderRefund $refund, array $payload, bool $operator = false): OrderRefund
    {
        // 수정의 before·filter 훅 — 잠금·쓰기 전이다. 필터는 사유(`CLAIM_FILTERABLE_UPDATE_KEYS`)만
        // 바꿀 수 있고 바뀐 값은 아래 사유 해석을 지난다. 환불 계좌는 돈이 향하는 곳이라 되돌린다.
        HookManager::doAction('sirsoft-ecommerce.claim.refund.before_update_request', $refund, $payload);

        $payload = $this->acceptClaimFilterResult(
            HookManager::applyFilters('sirsoft-ecommerce.claim.refund.filter_update_request_data', $payload, $refund),
            $payload,
            self::CLAIM_FILTERABLE_UPDATE_KEYS,
        );

        return DB::transaction(function () use ($refund, $payload, $operator) {
            $locked = $this->refundRepository->findForUpdate((int) $refund->getKey()) ?? $refund;

            // 운영자 수정(S15)은 출처(고객 편집 가능 여부)를 보지 않는다 — 수정 가능 단계는 고객과 같다.
            if ((! $operator && ! $locked->refund_source_type?->isBuyerEditable()) || ! $locked->refund_status->isWithdrawable()) {
                throw OrderClaimException::notEditable();
            }

            $before = $this->claimRequestUpdateSnapshot($locked);
            $updates = [];

            if (array_key_exists('reason_code', $payload) && $payload['reason_code'] !== null) {
                $reason = $this->resolveReason((string) $payload['reason_code'], ! $operator);
                $updates['reason_code'] = $reason->code;
                $updates['fault_type'] = $reason->fault_type;
            }

            if (array_key_exists('reason_detail', $payload)) {
                $updates['reason_detail'] = $payload['reason_detail'];
            }

            // 환불 계좌 — D13 이 여는 축이다(2026-09-01 확정: 수정 화면에서 편집 가능, 단
            // 수정이 허용되는 신청 단계에서만 — 이 메서드는 그 단계에서만 진입한다). 손대지 않은
            // 저장이 기록된 계좌를 지우지 않는다. 실제 송금 대상(주문 결제행)은 여기서 바꾸지
            // 않는다 — 그 갱신은 환불을 실행하는 시점의 책임이다. 컬럼 조립과 3필드 짝 검증은
            // 교환·반품과 같은 공용 규칙(`AppliesRefundBankAccount::refundBankColumns()`)이 한다 —
            // 예전에는 빠진 키를 직접 인덱싱해 500 이 났다(#599 validate P2 #9).
            $bank = $payload['refund_bank'] ?? null;
            $updates += $this->refundBankColumns(is_array($bank) ? $bank : null);

            if ($updates === []) {
                return $locked;
            }

            $updated = $this->refundRepository->update($locked, $updates);

            // 같은 값으로 다시 저장한 수정은 알리지 않는다 — 활동 로그에 빈 이력이 쌓이면 실제로 바뀐 줄이 묻힌다.
            $changes = $this->claimRequestUpdateChanges($before, $this->claimRequestUpdateSnapshot($updated));

            if ($changes !== []) {
                $context = $this->claimRequestUpdateContext($operator);
                $this->fireAfterCommit(fn () => HookManager::doAction(
                    'sirsoft-ecommerce.claim.refund.request_updated',
                    $updated,
                    $changes,
                    $context,
                ));
            }

            return $updated;
        });
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
            $order = $this->orderRepository->findForUpdateOrFail($refund->order_id);

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
     * 금액(D19)을 같은 식으로 재계산해 동시 처리를 차단하고, 한 트랜잭션 안에서 다음을 이 순서로
     * 수행한다 — **되돌릴 수 있는 것은 모두 앞에, 되돌릴 수 없는 것(PG)은 하나만 맨 뒤에**(P-D5):
     *
     * 1. 쓴 마일리지 반환(`order_refund_id` 멱등) → 「마일리지로 환불」이면 현금분 마일리지 지급(D1).
     *    둘 다 실패하면 PG 를 부르지 않고 전체를 되돌린다(D2)
     * 2. (직접 환불신청) 선점만 하던 수량을 취소 상태로 분할·전이 — `OrderCancellationService`
     *    의 분할 규칙 한 벌을 재사용한다. 재고 복원(설정 준수)·쿠폰 조건 소멸 복원 동반(실패=중단)
     * 3. 송금 계좌·결제행 취소 누적·주문 환불 누적(`RefundExecutionService::prepareLedger`)
     * 4. 환불신청서·상품 행 상태, 취소신청서·결제행 정리(`recordCancellation`)
     * 5. PG 환불 — 성공하면 신청서 행 UPDATE 1회로 완료를 찍는다. 이것이 PG 뒤 유일한 코어 쓰기다
     *
     * 비-PG 경로(무통장·마일리지)는 4 단계에서 완료를 찍는다(운영자의 [환불하기]가 곧 수동 송금
     * 완료 선언이다). 트랜잭션은 한 번만 시도한다 — 교착 재시도가 클로저를 다시 돌리면 PG 를 두 번
     * 부른다. PG 승인 직후 커밋이 실패하면 critical 로그와 신청서 표식(`pg_transaction_id` +
     * `LEDGER_COMMIT_FAILED`)을 남기고, 다음 [환불하기]는 PG 를 건너뛰고 장부만 확정한다(§14-C).
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
     * @throws OrderClaimException 실행 불가 출처·상태 / D19 상한 초과 / 재실행 / 쓸 수 없는 환불수단
     * @throws OrderCancellationException PG 환불 실패 / 마일리지 반환·지급, 쿠폰·재고 복원 실패
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

        // 환불 완료(실행)의 before 훅 — 잠금·PG 호출·쓰기 전이라 리스너가 던지면 돈이 나가지 않는다.
        // 필터는 두지 않는다: 실행 본문(`$overrides`)은 환불액·환불수단이라 금액 그 자체이고, 확장이
        // 바꾸면 운영자가 화면에서 본 금액과 실제 송금액이 갈린다.
        HookManager::doAction('sirsoft-ecommerce.claim.refund.before_complete', $refund, $overrides, $processedBy);

        $completedOptionChanges = [];

        // 앞선 실행이 남긴 PG 승인 기록이 섞이지 않게 비우고 시작한다(§14-C).
        $this->refundExecutionService->forgetPgSettlement();

        try {
            $executed = DB::transaction(function () use ($refundId, $overrides, $processedBy, $pgAlreadySettled, &$completedOptionChanges) {
                return $this->executeRefundInTransaction($refundId, $overrides, $processedBy, $pgAlreadySettled, $completedOptionChanges);
            });
        } catch (\Throwable $e) {
            $settled = $this->refundExecutionService->takePgSettlement();

            if ($settled !== null) {
                $this->recordOrphanPgSettlement($refundId, $settled, $e);
            }

            throw $e;
        }

        if ($completedOptionChanges !== []) {
            $this->fireAfterCommit(fn () => HookManager::doAction(
                'sirsoft-ecommerce.claim.refund.option_status_changed',
                $executed,
                $completedOptionChanges,
                RefundStatusEnum::COMPLETED->value,
                $processedBy,
            ));
        }

        $this->fireAfterCommit(fn () => HookManager::doAction(
            'sirsoft-ecommerce.claim.refund.status_changed',
            $executed,
            RefundStatusEnum::APPROVED->value,
            RefundStatusEnum::COMPLETED->value,
            [],
        ));
        $this->fireAfterCommit(fn () => HookManager::doAction('sirsoft-ecommerce.claim.refund.completed', $executed));

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
     * 환불 실행의 트랜잭션 본문입니다. (executeRefund() 독블록의 1~5 단계)
     *
     * @param  int  $refundId  환불신청서 ID
     * @param  array  $overrides  실행 시점 금액
     * @param  int|null  $processedBy  처리 관리자 ID
     * @param  bool  $pgAlreadySettled  PG 환불이 바깥에서 이미 집행됐는지
     * @param  array  $completedOptionChanges  상품 행 상태 변경 캡처 (참조 — 커밋 뒤 훅이 쓴다)
     * @return OrderRefund 실행 완료된 환불신청서
     */
    protected function executeRefundInTransaction(
        int $refundId,
        array $overrides,
        ?int $processedBy,
        bool $pgAlreadySettled,
        array &$completedOptionChanges,
    ): OrderRefund {
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

        // 앞선 실행이 PG 승인 뒤 커밋에 실패해 남긴 표식(§14-C) — 「승인 상태인데 PG 거래 ID 가 있다」는
        // 조합은 그 표식에서만 생긴다(`pg_transaction_id` 는 완료와 함께만 쓰인다). PG 를 다시 부르면
        // 같은 돈이 두 번 나가므로(NICE·KG·KCP 는 멱등키가 없다) 장부만 확정한다.
        if (! $pgAlreadySettled
            && $refund->refund_method === RefundMethodEnum::PG
            && filled($refund->pg_transaction_id)) {
            Log::warning('PG 기집행 표식 발견 — PG 를 다시 부르지 않고 장부만 확정합니다', [
                'refund_id' => $refund->id,
                'refund_number' => $refund->refund_number,
                'pg_transaction_id' => $refund->pg_transaction_id,
            ]);

            $pgAlreadySettled = true;
        }

        $order = $this->orderRepository->findForUpdateOrFail($refund->order_id);
        $order->load(['options', 'shippings', 'payment', 'payments']);

        // D18 — 실행 본문의 금액을 저장과 같은 검증으로 반영 (저장 없이 실행 가능)
        $updates = $this->normalizeRefundAmountUpdates($order, $refund, $overrides);

        if ($updates !== []) {
            $refund = $this->refundRepository->update($refund, $updates);
        }

        $amount = (float) $refund->refund_amount;
        $points = (float) $refund->refund_points_amount;

        // 저장된 환불수단도 다시 본다(S5·Q21) — 정규화는 본문(override)만 보므로, 본문 없이 실행하면
        // 저장값 「마일리지」가 비회원·기능 꺼짐 가드를 그대로 통과해 돈이 증발한다.
        if ($refund->refund_method === RefundMethodEnum::POINTS) {
            $this->assertPointsRefundAllowed($order, $amount);
        }

        // 실행 직전 D19 재계산 가드 — 저장 시점과 같은 식 (동시 처리 차단)
        $this->assertWithinCancellable($order, $refund, $amount, $points);

        // 실제로 차감된 적 없는 마일리지는 돌려주지도, 장부에 적지도 않는다(Q15). 결제 이후 주문인데
        // 차감되지 않았다면(차감 시점 설정·수동 결제완료에서 「마일리지 차감」 해제) 신청서의 마일리지
        // 몫은 고객이 낸 적 없는 돈이다 — 반환은 `restoreMileage` 가드가 이미 막지만 장부·알림·화면은
        // 신청서 값을 읽으므로 그 값부터 0 으로 맞춘다.
        if ($points > 0 && ! $order->is_mileage_deducted) {
            Log::warning('결제 이후 주문인데 사용 마일리지 미차감 — 마일리지 반환 0 처리', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'refund_id' => $refund->id,
                'refund_points_amount' => $points,
            ]);

            $refund = $this->refundRepository->update($refund, [
                'refund_points_amount' => 0,
                'mc_refund_points_amount' => null,
            ]);
            $points = 0.0;
        }

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

        $ownsQuantities = $this->ownsOptionQuantities($refund) && $items !== [];

        if ($ownsQuantities) {
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

        // 이 결제수단이 **원래 쓰는** 환불수단 — PG 리스너가 없어 계좌로 폴백된 카드 주문(P14)도 같은
        // 답을 낸다. 폴백 시 경고 로그를 남기므로 한 번만 계산한다.
        $nativeMethod = $this->refundExecutionService->determineRefundMethod($order);

        // 신청 시점에 PG 플러그인이 꺼져 있어 계좌 환불로 폴백된 카드 주문(P14)은, 운영자가 수단을 고르지 않았고
        // 지금 PG 로 환불할 수 있으면 PG 로 되돌린다(P-D3). 예전에는 플러그인을 다시 켜도 저장된 「계좌 환불」 대로
        // PG 를 부르지 않고 완료돼, 운영자가 손으로 송금하지 않으면 돈이 돌아가지 않았다. 반대 방향(PG → 계좌)은
        // 바꾸지 않는다 — 꺼진 플러그인은 실행 때 사유와 함께 막힌다(P-D6).
        if (! $pgAlreadySettled
            && ! $refund->refund_method_overridden
            && $refund->refund_method === RefundMethodEnum::BANK
            && $nativeMethod === RefundMethodEnum::PG) {
            Log::info('환불수단 재판정 — 계좌 폴백 건을 PG 로 되돌림', [
                'refund_id' => $refund->id,
                'refund_number' => $refund->refund_number,
                'order_id' => $order->id,
            ]);
            $refund = $this->refundRepository->update($refund, ['refund_method' => RefundMethodEnum::PG]);
        }

        // 결제행의 취소 누적·상태는 **그 수단에서 실제로 취소가 일어난 때만** 움직인다 (S1-D24·Q16).
        //
        // 카드 주문에 `계좌이체`·`마일리지` 환불수단을 지정하면 카드 거래는 취소되지 않는다 — 그런데도
        // 누적을 깎으면 카드사 잔액은 그대로인데 장부의 환불가능액만 줄어(실측 1-P: 58,000 → 44,000)
        // 이후 정상 카드 환불이 한도에 먼저 걸리고, 상태를 취소로 바꾸면 「누적 0 인데 취소」인 모순이
        // 남는다(주문 1622). 운영자가 손으로 송금하는 운영은 막지 않는다(운영 결정 2026-09-16 —
        // 「허용하되 장부는 건드리지 않음」). 돈이 나간 사실은 주문 축(`total_refunded_amount`)과
        // 환불 레코드, 결제행 취소 이력(`settled_on_payment=false`)이 기록한다.
        //
        // `$pgAlreadySettled`(가상계좌 환불 화면·표식 재실행)는 **취소가 실제로 일어난** 경우라 그대로
        // 쌓는다 — 여기서 빼면 정상 취소의 누적이 사라진다.
        $paymentSettled = $pgAlreadySettled || $refund->refund_method === $nativeMethod;

        // PG 를 이번 실행에서 부를지 — 장부 확정(⑤)과 PG 호출(⑧)이 같은 값을 읽는다. 따로 판정하면
        // PG 경로인데 완료를 PG 앞에서 찍는 일이 생긴다.
        $willCallPg = ! $pgAlreadySettled
            && $refund->refund_method === RefundMethodEnum::PG
            && $amount > 0
            && $order->payment?->needsPgProvider() === true;

        // ① 쓴 마일리지 반환 (order_refund_id 멱등 — 실패=중단)
        $this->refundExecutionService->restoreMileage($order, $result, null, $refund);

        // ② 「마일리지로 환불」 — 현금 환불액을 회원 마일리지로 지급 (D1, 실패=중단)
        if (! $pgAlreadySettled && $refund->refund_method === RefundMethodEnum::POINTS && $amount > 0) {
            $this->refundExecutionService->creditMileage($order, $refund, $amount);
        }

        // ②-b 교환 차액 환급 중 마일리지로 낸 몫(S2·Q9) — 마일리지 단계에서 함께 돌려준다(실패=중단).
        //      원 주문 PG 가 이미 끝난 경로(기집행 표식·바깥 PG)에서도 돌려준다 — 그 표식은 원 주문 PG 에
        //      대한 것이고, 앞선 실행이 롤백됐다면 차액 환급도 함께 되돌아가 아직 나가지 않은 돈이다.
        $this->refundExchangeDifference($refund, pointsOnly: true);

        // ③ 재고·쿠폰 복원 — D7 「취소·환불은 즉시 복구」 (`stock_restore_on_cancel` 설정 준수는 restoreStock 내부, 실패=중단)
        if ($ownsQuantities) {
            $this->refundExecutionService->restoreStock($order, $items);
            $this->refundExecutionService->restoreCoupons($order, $couponRestore);
        }

        // ④ 송금 계좌 + 결제행 취소 누적 + 주문 환불 누적 (클레임 환불 누적의 단일 지점)
        $this->refundExecutionService->prepareLedger($order, $refund, $result, recordPaymentLedger: $paymentSettled);

        // ⑤ 처리자 기록 — PG 를 부르지 않는 경로는 여기서 완료를 찍는다. PG 경로의 완료는 PG 성공 뒤
        //    `executePgRefund` 가 신청서 행 UPDATE 1회로 찍는다.
        $finalUpdates = ['processed_by' => $processedBy ?? $refund->processed_by];

        if (! $willCallPg) {
            $finalUpdates['refund_status'] = RefundStatusEnum::COMPLETED;
            $finalUpdates['refunded_at'] = Carbon::now();
        }

        $updated = $this->refundRepository->update($refund, $finalUpdates);

        $executionTargetIds = $executionTargets->pluck('id')->map(fn ($id) => (int) $id)->all();

        // ⑥ 활동로그의 전/후도 **같은 대상**으로 좁힌다. 전건으로 캡처하면 반려 행이
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

        // ⑦ 취소신청서 기록 — 환불이 집행되는 바로 그 트랜잭션이다 (D3)
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
            // 결제행 취소 누적·주문 환불 누적은 `prepareLedger()` 가 이미 더했다. 여기서 또
            // 더하면 같은 금액이 두 번 빠져 2차 부분환불이 0원이 된다.
            accumulatePayment: false,
            recalculatePaidAmount: false,
            restoreCouponsAndStock: false,
            paymentSettled: $paymentSettled,
        );

        // ⑧ PG — 트랜잭션의 마지막 단계(P-D5). ⑦ 이후의 결제행(상태·누적)을 넘긴다 — 낡은 모델을
        //    넘기면 리스너가 이번 환불을 뺀 누적으로 전액/부분을 가른다.
        if ($willCallPg) {
            $pgOrder = $this->orderRepository->find($order->id) ?? $order;
            $pgOrder->load('payment');

            $this->refundExecutionService->executePgRefund($pgOrder, $updated, $result, $updated->reason_code);
        }

        // ⑨ 교환 차액 환급 중 카드 등 PG 로 낸 몫(S2·Q9) — PG 단계의 맨 마지막(원 주문 PG 뒤). 거절되면 전체 중단(D2).
        //    원 주문 PG 를 건너뛴 재실행에서도 부른다 — 이 단계가 거절돼 롤백된 환불을 다시 실행하면 표식 분기를
        //    타는데, 그때 건너뛰면 클레임 결제 몫이 영영 돌아가지 않는다.
        $this->refundExchangeDifference($updated, pointsOnly: false);

        return $updated->fresh(['refundOptions']);
    }

    /**
     * 환불신청서에 잡아 둔 교환 차액 몫을 클레임 결제 건별로 돌려줍니다. (S2 · Q9)
     *
     * 몫은 신청서를 만들 때 정했다(`ExchangeDifferenceRefundPlanner`). 마일리지로 낸 몫은 마일리지 단계에서,
     * 카드 등 PG 로 낸 몫은 원 주문 PG 뒤에 돌려준다 — 둘 다 클레임 결제 환급(`ClaimPaymentService::refundPayment`)
     * 한 경로를 쓰므로 결제 건의 환급 누적·상태 규칙이 그대로 적용된다. 실패하면 예외로 환불 전체를 되돌린다(D2).
     *
     * @param  OrderRefund  $refund  환불신청서
     * @param  bool  $pointsOnly  true 면 마일리지 몫만, false 면 PG 몫만
     * @return void 반환값 없음
     */
    protected function refundExchangeDifference(OrderRefund $refund, bool $pointsOnly): void
    {
        $entries = $refund->exchange_difference_refunds ?? [];
        if ($entries === []) {
            return;
        }

        $claimPayments = app(ClaimPaymentService::class);
        foreach ($entries as $entry) {
            $portion = $pointsOnly ? (float) ($entry['points_amount'] ?? 0) : (float) ($entry['amount'] ?? 0);
            if ($portion <= 0) {
                continue;
            }

            $payment = $claimPayments->findById((int) ($entry['claim_payment_id'] ?? 0));
            if ($payment === null) {
                throw OrderClaimException::claimNotFound();
            }

            $claimPayments->refundPayment($payment, $portion, $refund->reason_code);
        }
    }

    /**
     * PG 승인 뒤 장부 확정이 롤백됐을 때 흔적을 남깁니다. (§14-C)
     *
     * ① 트랜잭션 밖 critical 로그 — DB 가 죽어도 남는 유일한 근거다. PG 콘솔 대사에 필요한 값만
     *    싣는다(개인정보·PG raw 응답 금지).
     * ② 새 트랜잭션으로 신청서 행에 `pg_transaction_id` + `LEDGER_COMMIT_FAILED` 표식을 남긴다 —
     *    다음 [환불하기]가 PG 를 다시 부르지 않고 장부만 확정한다(executeRefundInTransaction 의 표식 분기).
     *    신청서 행이 같은 트랜잭션에서 생겨 함께 롤백됐으면(자동 전이) 표식은 건너뛴다.
     *
     * @param  int  $refundId  환불신청서 ID
     * @param  array<string, mixed>  $settled  PG 승인 기록 (RefundExecutionService::takePgSettlement)
     * @param  \Throwable  $cause  롤백을 일으킨 예외
     * @return void
     */
    protected function recordOrphanPgSettlement(int $refundId, array $settled, \Throwable $cause): void
    {
        Log::critical('PG 환불은 승인됐으나 장부 확정이 롤백됨 — 수동 대사 필요', $settled + ['error' => $cause->getMessage()]);

        try {
            DB::transaction(function () use ($refundId, $settled) {
                $row = $this->refundRepository->findForUpdate($refundId);

                if ($row !== null
                    && $row->refund_status === RefundStatusEnum::APPROVED
                    && blank($row->pg_transaction_id)) {
                    $this->refundRepository->update($row, [
                        'pg_transaction_id' => $settled['transaction_id'] ?? null,
                        'pg_error_code' => 'LEDGER_COMMIT_FAILED',
                        'pg_error_message' => __('sirsoft-ecommerce::exceptions.refund_ledger_commit_failed'),
                    ]);
                }
            });
        } catch (\Throwable $markerFailure) {
            Log::critical('PG 기집행 표식 기록 실패 — 재실행하면 이중 환불 위험', $settled + ['error' => $markerFailure->getMessage()]);
        }
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

        $this->fireAfterCommit(fn () => HookManager::doAction(
            'sirsoft-ecommerce.claim.refund.option_status_changed',
            $refund,
            $changes,
            RefundStatusEnum::REQUESTED->value,
            $processedBy,
        ));
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
            // 「마일리지」는 이 수정으로 바뀐 현금 환불액을 기준으로 본다(Q21 — 현금 0 원이면 기능과 무관).
            $this->assertRefundMethodAvailable(
                $order,
                $method,
                (float) ($updates['refund_amount'] ?? $refund->refund_amount),
            );

            $updates['refund_method'] = $method;
            // 운영자가 고른 수단이다 — 실행 시점에 수단을 다시 판정하지 않는다(P-D3). 다만 화면은 고치지 않은
            // 수단도 늘 본문에 싣는다(S3-D26). **실행 때 쓰일 수단과 같은 값**은 고른 것이 아니라 그대로 둔
            // 것이다 — 그때 박으면 플러그인이 꺼진 동안 계좌로 폴백된 카드 신청서가, 플러그인을 다시 켠 뒤
            // [환불하기]에서 PG 를 부르지 않고 계좌 환불로 닫힌다(돈이 결제사로 돌아가지 않는다).
            if ($method !== $this->effectiveRefundMethod($refund, $order)) {
                $updates['refund_method_overridden'] = true;
            }
        }

        // 실행하며 적어 넣은 환불 계좌 (T2-D6) — 계좌로 송금해야 끝나는 환불인데 신청서에
        // 계좌가 없을 때 운영자가 [환불하기] 창에서 채운 값이다. 실행과 같은 트랜잭션에 넣어야
        // 「계좌는 저장됐는데 실행은 실패한」 반쪽 상태가 생기지 않는다. 셋이 짝을 이루는지는
        // 요청(`ExecuteRefundRequest::validateRefundBank`)이 이미 걸렀으므로 여기 오는 값은
        // 세 칸이 모두 찼거나 아예 없다.
        foreach (['refund_bank_code', 'refund_bank_account', 'refund_bank_holder'] as $column) {
            if (array_key_exists($column, $amounts) && filled($amounts[$column])) {
                $updates[$column] = (string) $amounts[$column];
            }
        }

        return $updates;
    }

    /**
     * 이 주문의 결제수단이 지정한 환불수단을 지원하는지 검증합니다. (S1-D15)
     *
     * `pg` 의 판정은 `pgRefundAvailable()` 하나다 — [금액 수정]의 저장, 상세 화면의 선택지
     * (`AdminClaimService::refundMethodOptions()`), [환불하기] 실행 게이트가 모두 그것을 읽는다.
     * 예전에는 여기만 결제수단(`needsPgProvider`)을 보고 실행 게이트는 실효 수단을 봐, PG 리스너가
     * 없어 계좌 환불로 떨어지는 주문(P14)에서 PG 가 저장은 되는데 실행은 막혔다(#599 validate P2-A 후속).
     *
     * `bank` 는 결제수단과 무관하게 사람이 돌려주는 경로라 제한하지 않는다. `points` 는 회원 주문에서만,
     * 그리고 현금을 마일리지로 돌려주려면 마일리지 기능이 켜진 상점에서만 쓸 수 있다(D1·Q21 —
     * `pointsRefundAllowed()` 하나를 선택지·저장·실행이 함께 읽는다).
     *
     * @param  Order  $order  주문
     * @param  RefundMethodEnum  $method  지정한 환불수단
     * @param  float  $cashAmount  이 환불의 현금 환불액 (`points` 판정용)
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 결제수단이 그 환불수단을 지원하지 않을 때 / 비회원 마일리지
     */
    protected function assertRefundMethodAvailable(Order $order, RefundMethodEnum $method, float $cashAmount = 0.0): void
    {
        if ($method === RefundMethodEnum::POINTS) {
            $this->assertPointsRefundAllowed($order, $cashAmount);

            return;
        }

        if ($method !== RefundMethodEnum::PG) {
            return;
        }

        if (! $this->pgRefundAvailable($order)) {
            // PG 결제인데 막혔다면 그 주문의 결제 플러그인이 꺼진 것이다(S3-D25) — 일반 거절 문장으로는
            // 운영자가 이유도, 플러그인을 켜거나 계좌 환불로 바꾸면 된다는 해결책도 알 수 없다.
            throw $this->pgRefundBlockedByInactivePlugin($order)
                ? OrderClaimException::pgProviderInactive((string) $order->payment->pg_provider)
                : OrderClaimException::refundMethodUnavailable();
        }
    }

    /**
     * PG 결제 주문인데 그 결제 플러그인이 꺼져 PG 환불을 할 수 없는지 판정합니다. (S3-D25)
     *
     * 결제는 PG 를 거쳤는데(`needsPgProvider()`) PG 환불이 안 되면(`pgRefundAvailable()`) 남은 이유는
     * 그 주문의 결제 플러그인이 응답하지 않는 것이다 — 저장·실행 거절이 이 경우 사유와 해결책을 따로 말한다.
     *
     * @param  Order  $order  주문
     * @return bool 플러그인이 꺼져 PG 환불이 막힌 PG 결제 주문이면 true
     */
    public function pgRefundBlockedByInactivePlugin(Order $order): bool
    {
        $order->loadMissing('payment');

        return $order->payment !== null
            && $order->payment->needsPgProvider()
            && ! $this->pgRefundAvailable($order);
    }

    /**
     * 이 신청서를 지금 [환불하기] 하면 쓰일 환불수단을 반환합니다. (실효 수단 — S3-D26)
     *
     * 플러그인이 꺼진 동안 계좌로 폴백 접수된 카드 신청서는, 운영자가 수단을 고르지 않았고 지금 PG 로
     * 환불할 수 있으면 실행 때 PG 로 되돌린다(P-D3). 상세 화면이 저장값(계좌)을 그대로 보이면 실행 창이
     * 계좌를 요구하고, 그 값이 본문에 실려 운영자가 고른 수단처럼 박혔다. 화면과 실행이 이 판정 하나를 읽는다.
     *
     * 끝난 신청서(완료·반려·철회)와 운영자가 고른 수단, 계좌가 아닌 수단은 저장값 그대로다 — 반대 방향
     * (PG → 계좌)은 바꾸지 않는다. 꺼진 플러그인의 PG 건은 실행 때 사유와 함께 막힌다(P-D6).
     *
     * @param  OrderRefund  $refund  환불신청서
     * @param  Order|null  $order  주문 (없으면 신청서의 주문)
     * @return RefundMethodEnum|null 실효 환불수단
     */
    public function effectiveRefundMethod(OrderRefund $refund, ?Order $order = null): ?RefundMethodEnum
    {
        $stored = $refund->refund_method;

        if ($stored !== RefundMethodEnum::BANK || $refund->refund_method_overridden) {
            return $stored;
        }

        if (in_array($refund->refund_status, [RefundStatusEnum::COMPLETED, RefundStatusEnum::REJECTED, RefundStatusEnum::WITHDRAWN], true)) {
            return $stored;
        }

        $order ??= $refund->order;

        if ($order === null) {
            return $stored;
        }

        $order->loadMissing('payment');

        // 결제수단이 **원래** PG 로 돌려주는 수단이어야 한다 — 가상계좌처럼 PG 를 거쳤어도 원래 계좌로
        // 돌려주는 수단을 PG 로 바꾸면 안 된다. `determineRefundMethod()` 와 같은 판정이되, 상세를 열 때마다
        // 폴백 경고 로그가 쌓이지 않도록 리스너 유무를 직접 본다.
        if ($order->payment === null || $order->payment->refundMethod() !== RefundMethodEnum::PG) {
            return $stored;
        }

        return $this->refundExecutionService->hasPgRefundListener($order) ? RefundMethodEnum::PG : $stored;
    }

    /**
     * 이 주문을 「마일리지」로 환불할 수 있는지 판정합니다. (환불수단 `points` 의 단일 판정 — D1·Q21)
     *
     * `회원 주문` AND (`마일리지 기능 켜짐` OR `현금 환불액 = 0` OR `결제수단의 원래 환불수단이 마일리지`).
     *
     *  - 비회원 주문에는 마일리지 원장이 없다 — 받아 두면 돈이 어디에도 지급되지 않는다(S5·refund 274).
     *  - 기능이 꺼진 상점에서는 현금을 마일리지로 돌려주지 않는다 — 고객이 쓸 수 없는 돈이 된다.
     *    다만 현금 0 원(쓴 마일리지 반환만)은 고객이 이미 낸 마일리지를 되돌리는 것이라 기능과 무관하다.
     *  - 마일리지 결제수단(`point`) 주문은 마일리지가 그 결제의 원래 환불수단이라 기능과 무관하다.
     *
     * 선택지(`AdminClaimService::refundMethodOptions()`)·저장(`assertRefundMethodAvailable()`)·
     * 실행 저장값 가드(`executeRefundInTransaction`)가 이 메서드 하나를 읽는다.
     *
     * @param  Order  $order  주문
     * @param  float  $cashAmount  현금 환불액 (기준 통화)
     * @return bool 마일리지 환불 가능 여부
     */
    public function pointsRefundAllowed(Order $order, float $cashAmount): bool
    {
        if ($order->user_id === null) {
            return false;
        }

        if ($cashAmount <= 0) {
            return true;
        }

        if ((bool) $this->settingsService->getSetting('mileage.enabled', false)) {
            return true;
        }

        return $this->paysBackInPoints($order);
    }

    /**
     * 결제수단의 원래 환불수단이 마일리지인지 판정합니다. (마일리지 결제수단 `point` 주문)
     *
     * 결제행 카탈로그 선언만 본다 — PG 리스너 유무로 폴백을 가르는 `determineRefundMethod()` 는
     * 폴백 경고 로그를 남겨, 화면을 열 때마다 부르는 이 판정에는 맞지 않는다.
     *
     * @param  Order  $order  주문
     * @return bool 마일리지가 원래 환불수단이면 true
     */
    protected function paysBackInPoints(Order $order): bool
    {
        $order->loadMissing('payment');

        return $order->payment?->refundMethod() === RefundMethodEnum::POINTS;
    }

    /**
     * 「마일리지」 환불이 가능한지 검증합니다. (pointsRefundAllowed 의 거절 사유를 고른다)
     *
     * @param  Order  $order  주문
     * @param  float  $cashAmount  현금 환불액
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 비회원 주문 / 마일리지 기능이 꺼진 상점의 현금 환불
     */
    protected function assertPointsRefundAllowed(Order $order, float $cashAmount): void
    {
        if ($order->user_id === null) {
            throw OrderClaimException::refundPointsRequireMember();
        }

        if (! $this->pointsRefundAllowed($order, $cashAmount)) {
            throw OrderClaimException::refundMethodUnavailable();
        }
    }

    /**
     * 주문의 현금 환불 중 「마일리지로 환불」로 지급한 합계를 돌려줍니다. (주문 상세의 별도 표시 — Q3)
     *
     * 마일리지가 원래 환불수단인 결제(`point`)는 「대체 지급」이 아니므로 0 이다 — 결제행 보존 판정
     * (`executeRefundInTransaction` 의 `$paymentSettled`)과 같은 축이다.
     *
     * @param  Order  $order  주문
     * @return float 마일리지로 지급한 현금 환불 합계 (기준 통화)
     */
    public function mileageCreditedTotalForOrder(Order $order): float
    {
        if ($this->paysBackInPoints($order)) {
            return 0.0;
        }

        return $this->refundRepository->sumCompletedRefundAmountByMethod((int) $order->id, RefundMethodEnum::POINTS);
    }

    /**
     * 이 주문을 PG 로 환불할 수 있는지 판정합니다. (환불수단 `pg` 의 단일 판정)
     *
     * 두 조건이 모두 참이어야 한다.
     *
     *  ① PG사를 거친 결제다(`OrderPayment::needsPgProvider()`) — 무통장·마일리지 결제에 PG 환불은 없다.
     *  ② 실효 환불수단이 PG 다(`RefundExecutionService::determineRefundMethod()`) — 결제 플러그인이
     *     빠져 `payment.refund` 에 응답할 리스너가 없으면 실행은 계좌 환불로 떨어진다(P14). 그 주문에
     *     PG 를 저장하게 두면 [환불하기]가 막혀 저장한 수단으로 실행할 길이 없다.
     *
     * 저장(`assertRefundMethodAvailable()`)·선택지(`AdminClaimService::refundMethodOptions()`)·
     * 실행 게이트(`ExecuteRefundRequest`)가 이 메서드 하나를 읽는다 — 기준이 갈리면 저장은 되는데
     * 실행은 막히는 틈이 다시 생긴다.
     *
     * @param  Order  $order  주문
     * @return bool PG 환불 가능 여부
     */
    public function pgRefundAvailable(Order $order): bool
    {
        $order->loadMissing('payment');

        if ($order->payment === null || ! $order->payment->needsPgProvider()) {
            return false;
        }

        return $this->refundExecutionService->determineRefundMethod($order) === RefundMethodEnum::PG;
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

        foreach (RefundExecutionService::TAX_SYNC_COLUMNS as $column) {
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

        // 헤더 전이의 before·filter 훅 — 잠금·쓰기 전이다(교환·반품의 `beforeStatusChange()` 와 같은
        // 규칙). 필터는 반려 사유·메모만 바꿀 수 있다 — 목표 상태·처리자·알림 억제 플래그는 되돌린다.
        // 필터를 거친 컨텍스트가 `status_changed` 의 넷째 인자로 그대로 나간다.
        $from = $refund->refund_status?->value;

        HookManager::doAction('sirsoft-ecommerce.claim.refund.before_status_change', $refund, $from, $target->value, $context);

        $context = $this->acceptClaimFilterResult(
            HookManager::applyFilters('sirsoft-ecommerce.claim.refund.filter_status_change_data', $context, $refund, $from, $target->value),
            $context,
            self::CLAIM_FILTERABLE_STATUS_CONTEXT_KEYS,
        );

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
            $this->fireAfterCommit(fn () => HookManager::doAction(
                'sirsoft-ecommerce.claim.refund.option_status_changed',
                $updated,
                $optionChanges,
                $optionChanges[0]['new'] ?? null,
                $updates['processed_by'] ?? null,
            ));
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
        $this->fireAfterCommit(fn () => HookManager::doAction('sirsoft-ecommerce.claim.refund.status_changed', $refund, $previous->value, $target->value, $context));

        $specific = match ($target) {
            RefundStatusEnum::APPROVED => 'sirsoft-ecommerce.claim.refund.approved',
            RefundStatusEnum::REJECTED => 'sirsoft-ecommerce.claim.refund.rejected',
            RefundStatusEnum::WITHDRAWN => 'sirsoft-ecommerce.claim.refund.withdrawn',
            RefundStatusEnum::ON_HOLD => 'sirsoft-ecommerce.claim.refund.on_hold',
            default => null,
        };

        if ($specific !== null) {
            $this->fireAfterCommit(fn () => HookManager::doAction($specific, $refund));
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
        // 상품 칸 반려의 사유 — 반려된 상품 행(`reject_reason`)에 남는다(S3).
        $rejectReason = $target === RefundStatusEnum::REJECTED ? trim((string) ($context['reject_reason'] ?? '')) : '';
        $specs = ClaimItemSpecs::normalize($items);
        $quantities = ClaimItemSpecs::quantities($specs);
        $itemIds = ClaimItemSpecs::ids($specs);

        // 상품 단위 전이의 before 훅 — 잠금·쓰기 전이라 리스너가 던지면 어떤 행도 옮겨지지 않는다.
        // 상품 단위 전용 필터는 두지 않는다(교환·반품과 같은 판단 — 대상·목표는 운영자 판단). 자유 입력인
        // 반려 사유만 아래에서 헤더 경로와 같은 `filter_status_change_data` 를 거친다(#599 후속).
        HookManager::doAction('sirsoft-ecommerce.claim.refund.before_option_status_change', $refund, $specs, $target->value, $context);

        // 반려 사유는 신청서 한 칸(`reject_reason`)에 남는 자유 입력이라 헤더 경로와 **같은 필터**를 거친다
        // (#599 후속 운영 결정) — 어느 칸에서 적었느냐에 따라 확장이 손댈 수 있는지가 갈리면 금칙어 필터 같은
        // 확장이 상품 칸으로 우회된다. 받아들이는 키도 같다(처리자·목표 상태는 되돌린다).
        if ($rejectReason !== '') {
            $filterInput = ['reject_reason' => $rejectReason] + $context;
            $filtered = $this->acceptClaimFilterResult(
                HookManager::applyFilters('sirsoft-ecommerce.claim.refund.filter_status_change_data', $filterInput, $refund, $refund->refund_status?->value, $target->value),
                $filterInput,
                self::CLAIM_FILTERABLE_STATUS_CONTEXT_KEYS,
            );
            $rejectReason = trim((string) ($filtered['reject_reason'] ?? ''));
        }

        $succeeded = [];
        $skipped = [];
        $changes = [];
        $split = [];

        // 신청서 행을 잠그고 **잠금 안에서** 상품 행을 다시 읽어 판정한다. 헤더 동기화도 같은
        // 트랜잭션 안에서 한다(#599 validate P2 #19 — 교환·반품과 같은 규율). 훅은 커밋 뒤다(#7).
        DB::transaction(function () use ($refund, $itemIds, $quantities, $target, $processedBy, $rejectReason, &$succeeded, &$skipped, &$changes, &$split) {
            $locked = $this->refundRepository->findForUpdate((int) $refund->getKey()) ?? $refund;
            $rows = $this->resolveItems($locked, $itemIds);

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
                    // 잔여 수량은 **발행 시점 값**으로 싣는다 — 활동 로그 리스너가 이 값을 먼저 읽고, 없을 때만
                    // 잔여 행을 다시 읽는다(다시 읽으면 그 사이 바뀐 값이 섞인다).
                    $change['remainder_quantity'] = $previousQuantity - $change['quantity'];

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

            // 상품 단위 반려 사유는 **그 상품 행**에 남긴다(S3). 헤더 사유는 신청서 전체가 반려로 모일 때만
            // 채워진다(`syncHeaderFromOptions` → `stampFor(REJECTED)` — 사유가 여럿이면 마지막 반려 사유).
            // 예전에는 헤더 한 칸이라 두 번째 반려가 첫 사유를 덮고, 나머지 상품이 승인돼도 구매자
            // 화면에 반려 사유가 남았다.
            if ($rejectReason !== '' && $succeeded !== []) {
                foreach ($locked->fresh(['refundOptions'])->refundOptions as $rejectedItem) {
                    if (in_array((int) $rejectedItem->id, array_map('intval', $succeeded), true)) {
                        $this->refundOptionRepository->update($rejectedItem, ['reject_reason' => $rejectReason]);
                    }
                }
            }

            $fresh = $locked->fresh(['refundOptions']);

            // 상품 단위 전이는 헤더 전이와 다른 사실이다 — 상품 상태가 갈리면 헤더는 움직이지
            // 않으므로, 이 훅이 없으면 운영자가 한 상태 변경이 아무 데도 남지 않는다.
            // 커밋 뒤 발화 순서는 등록 순서다 — 상품 축이 헤더 축보다 먼저 나간다.
            if ($changes !== []) {
                $this->fireAfterCommit(fn () => HookManager::doAction(
                    'sirsoft-ecommerce.claim.refund.option_status_changed',
                    $fresh,
                    $changes,
                    $target->value,
                    $processedBy,
                ));
            }

            $this->syncHeaderFromOptions($fresh, $processedBy, $rejectReason !== '' ? ['reject_reason' => $rejectReason] : []);
        });

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
     * @param  array  $stampContext  단계 시각 외 부가 컬럼 (상품 칸 반려의 `reject_reason` — 헤더가 반려로 따라갈 때 사유를 지우지 않게)
     * @return void 반환값 없음
     */
    protected function syncHeaderFromOptions(OrderRefund $refund, ?int $processedBy, array $stampContext = []): void
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

            // 상품이 끌고 간 헤더 전이도 전이다 — 단계마다 before 훅을 쓰기 전에 낸다. 상품 전이
            // 트랜잭션 **안**이라 리스너가 던지면 이번 상품 전이까지 함께 되돌아간다(헤더와 상품이
            // 갈리지 않게). 필터는 없다 — 목표는 상품 상태가 정한 값이다.
            HookManager::doAction(
                'sirsoft-ecommerce.claim.refund.before_status_change',
                $current,
                $previous->value,
                $step->value,
                ['processed_by' => $processedBy, 'source' => 'option_sync'],
            );

            $updates = ['refund_status' => $step] + $this->stampFor($step, $stampContext);

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
            RefundStatusEnum::WITHDRAWN => ['withdrawn_at' => Carbon::now()] + (isset($context['withdrawn_by_admin'])
                ? ['withdrawn_by_admin' => (int) $context['withdrawn_by_admin'], 'withdraw_reason' => $context['withdraw_reason'] ?? null]
                : []),
            default => [],
        };
    }
}
