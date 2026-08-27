<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Ecommerce\DTO\CancellableAmount;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;

/**
 * 관리자 클레임 오케스트레이션 서비스 (S6a)
 *
 * 관리자 목록·전이 API 가 유형 분기와 승인-결제 상호작용을 이 서비스 한 곳에서 해석한다.
 * 도메인 규칙(전이 게이트·완료 절차·수량 잠금)은 유형별 서비스(교환·반품·환불신청)가
 * 소유하며, 여기서는 위임과 조합만 한다.
 *
 * ## 승인과 추가결제의 상호작용 (§4-5-2)
 *
 * 미수납 청구액이 남은 신청서는 승인되지 않는다 — 승인 뒤에는 결제 확정(`confirmPaid()`)이
 * 옮길 상태가 없어 청구액이 회수되지 않은 채 신청이 진행된다.
 *
 * - `pay_on_approval`: 승인 시도가 **결제 요청으로 우회**된다 — 결제 건을 만들고 결제대기로
 *   옮기며(`requestPaymentOnApproval()`), 결제가 확정되면 `confirmPaid()` 가 `paid` 를 거쳐
 *   자동 승인한다.
 * - `pay_on_request`: 미수납 건 승인은 `claim_payment_outstanding` 으로 거절한다 — 결제
 *   통로(결제대기 + 결제 건)는 신청 시점에 이미 열려 있다.
 */
class AdminClaimService
{
    /**
     * @param  OrderExchangeService  $exchangeService  교환 서비스
     * @param  OrderReturnService  $returnService  반품 서비스
     * @param  OrderRefundRequestService  $refundRequestService  환불신청 서비스
     * @param  ClaimPaymentService  $paymentService  클레임 추가결제 서비스
     * @param  CancellableAmountCalculator  $cancellableCalculator  취소 가능 금액 산정기 (D19)
     * @param  CurrencyConversionService  $currencyService  통화 변환·표기 서비스 (완료 미리보기 금액 표기)
     * @param  EcommerceSettingsService  $settingsService  이커머스 설정 서비스 (완료 처리 기본값 — D26)
     * @param  OrderExchangeRepositoryInterface  $exchangeRepository  교환 Repository
     * @param  OrderReturnRepositoryInterface  $returnRepository  반품 Repository
     * @param  OrderRefundRepositoryInterface  $refundRepository  환불 Repository
     * @param  OrderCancelRepositoryInterface  $cancelRepository  취소 Repository
     */
    public function __construct(
        protected OrderExchangeService $exchangeService,
        protected OrderReturnService $returnService,
        protected OrderRefundRequestService $refundRequestService,
        protected ClaimPaymentService $paymentService,
        protected CancellableAmountCalculator $cancellableCalculator,
        protected CurrencyConversionService $currencyService,
        protected EcommerceSettingsService $settingsService,
        protected OrderExchangeRepositoryInterface $exchangeRepository,
        protected OrderReturnRepositoryInterface $returnRepository,
        protected OrderRefundRepositoryInterface $refundRepository,
        protected OrderCancelRepositoryInterface $cancelRepository,
    ) {}

    // ───────────────────────────────────────────────
    // 목록 / 조회
    // ───────────────────────────────────────────────

    /**
     * 관리자 목록을 유형 서브탭 단위로 페이지네이션 조회합니다.
     *
     * 네 유형은 테이블·상태 어휘가 달라 한 요청이 한 유형만 싣는다 — 유저 목록
     * (`UserClaimService::listForUser()`)과 같은 형태이며, 화면(§6-4)도 서브탭 구조라
     * 유형 혼합 목록의 소비처가 없다.
     *
     * @param  ClaimTypeEnum  $type  조회 유형 (서브탭)
     * @param  array  $filters  필터 (`Admin\ClaimListRequest::filters()` 계약)
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function paginate(ClaimTypeEnum $type, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return match ($type) {
            ClaimTypeEnum::EXCHANGE => $this->exchangeRepository->paginateForAdmin($filters, $perPage),
            ClaimTypeEnum::RETURN => $this->returnRepository->paginateForAdmin($filters, $perPage),
            ClaimTypeEnum::REFUND => $this->refundRepository->paginateForAdmin($filters, $perPage),
            ClaimTypeEnum::CANCEL => $this->cancelRepository->paginateForAdmin($filters, $perPage),
        };
    }

    /**
     * 서브탭 뱃지용 유형별 건수를 반환합니다.
     *
     * 유형 공통 축(기간·검색·회원구분)만 반영한다 — 상태·귀책·청구 필터는 유형마다 어휘가
     * 달라 다른 탭의 건수에 적용할 수 없고, 뱃지는 "그 탭으로 넘어가면 몇 건이 보이는가" 를
     * 답하는 값이라 탭 전환 시 초기화되는 유형 종속 필터를 반영하지 않는 것이 정합하다.
     *
     * @param  array  $sharedFilters  유형 공통 필터 (from / to / search_field / search_keyword / requester_type)
     * @return array{exchange: int, return: int, refund: int, cancel: int} 유형별 건수
     */
    public function counts(array $sharedFilters = []): array
    {
        return [
            ClaimTypeEnum::EXCHANGE->value => $this->exchangeRepository->countForAdmin($sharedFilters),
            ClaimTypeEnum::RETURN->value => $this->returnRepository->countForAdmin($sharedFilters),
            ClaimTypeEnum::REFUND->value => $this->refundRepository->countForAdmin($sharedFilters),
            ClaimTypeEnum::CANCEL->value => $this->cancelRepository->countForAdmin($sharedFilters),
        ];
    }

    /**
     * 완료 처리 모달의 초기 선택값을 반환합니다. (D26 — `claim.complete_defaults`)
     *
     * 목록 응답이 이 값을 나른다. 설정 엔드포인트(`admin/settings`)는 `settings.read` 게이트인데
     * 클레임 화면의 라우트는 `claims.read` 라, 모달이 설정 응답에서 직접 읽으면 클레임 권한만
     * 가진 운영자(기본 역할 `manager`)에게는 403 이 되어 기본값이 조용히 도달하지 않는다 —
     * 상점이 재고 복구를 꺼 두어도 그 운영자의 모달만 켜진 채 열리고 그대로 실행된다.
     *
     * 설정이 비어 있어도 두 키를 항상 채운다 — 화면이 "값이 없는 경우" 를 따로 분기하면
     * 그 분기가 계약 기본값의 두 번째 출처가 된다.
     *
     * @return array{create_refund_request: bool, restore_stock: bool} 초기 선택값
     */
    public function completionDefaults(): array
    {
        $defaults = (array) ($this->settingsService->getSetting('claim.complete_defaults') ?? []);

        // 형제 `claim.*` 불리언 설정과 같은 강도로 캐스팅한다 — `!== false` 는 `0`·`'0'`·
        // `'false'` 를 참으로 읽어, 운영자가 꺼 둔 값이 켜진 채 모달에 도달한다. 무너지는
        // 방향이 "끈 것을 켜진 것으로" 라 재고 복구·환불신청서가 의도 없이 실행된다.
        return [
            'create_refund_request' => filter_var(
                $defaults['create_refund_request'] ?? true,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) ?? true,
            'restore_stock' => filter_var(
                $defaults['restore_stock'] ?? true,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) ?? true,
        ];
    }

    /**
     * 상세 응답용으로 신청서를 관계까지 실어 조회합니다.
     *
     * 취소는 상세 엔드포인트가 없다 — 취소 이력은 주문 상세(D20)가 담당한다.
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  int  $id  신청서 ID
     * @return OrderExchange|OrderReturn|OrderRefund|null 신청서 (없으면 null)
     */
    public function findForDetail(ClaimTypeEnum $type, int $id): OrderExchange|OrderReturn|OrderRefund|null
    {
        return match ($type) {
            ClaimTypeEnum::EXCHANGE => $this->exchangeRepository->findById($id, [
                'exchangeOptions.orderOption', 'images', 'order', 'collectionCarrier', 'redeliveryCarrier',
            ]),
            ClaimTypeEnum::RETURN => $this->returnRepository->findById($id, [
                'returnOptions.orderOption', 'images', 'order', 'collectionCarrier', 'refund',
            ]),
            ClaimTypeEnum::REFUND => $this->refundRepository->findById($id, [
                'refundOptions.orderOption', 'images', 'order',
            ]),
            ClaimTypeEnum::CANCEL => null,
        };
    }

    /**
     * 전이 대상 신청서를 상품 행과 함께 조회합니다.
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  int  $id  신청서 ID
     * @return OrderExchange|OrderReturn|OrderRefund|null 신청서 (없으면 null)
     */
    public function findForTransition(ClaimTypeEnum $type, int $id): OrderExchange|OrderReturn|OrderRefund|null
    {
        return match ($type) {
            ClaimTypeEnum::EXCHANGE => $this->exchangeRepository->findById($id, ['exchangeOptions']),
            ClaimTypeEnum::RETURN => $this->returnRepository->findById($id, ['returnOptions']),
            ClaimTypeEnum::REFUND => $this->refundRepository->findById($id, ['refundOptions']),
            ClaimTypeEnum::CANCEL => null,
        };
    }

    // ───────────────────────────────────────────────
    // 전이
    // ───────────────────────────────────────────────

    /**
     * 신청서 상태를 전이합니다. (승인-결제 상호작용 포함, 완료 제외 — 완료는 `complete()`)
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  string  $target  목표 상태 값 (FormRequest 가 유형별 게이트를 통과시킨 값)
     * @param  array  $context  부가 정보 (processed_by / reject_reason / admin_memo)
     * @return array{claim: OrderExchange|OrderReturn|OrderRefund, payment_requested: bool} 전이 결과
     *
     * @throws OrderClaimException 전이 불가 / 미수납 승인 / 청구 없음 결제 요청
     */
    public function transition(
        OrderExchange|OrderReturn|OrderRefund $claim,
        string $target,
        array $context = [],
    ): array {
        $processedBy = $context['processed_by'] ?? null;

        if ($claim instanceof OrderExchange || $claim instanceof OrderReturn) {
            if ($target === 'payment_pending') {
                return ['claim' => $this->requestPayment($claim, $processedBy), 'payment_requested' => true];
            }

            if ($target === 'approved'
                && $claim->requiresAdditionalPayment()
                && $this->paymentService->outstandingAmount($claim) > 0) {
                if ($claim->charge_method === ClaimChargeMethodEnum::PAY_ON_APPROVAL) {
                    // §4-5-2: 승인 시도 → 미결제면 결제대기 + 결제 요청. 결제 확정이 승인을 잇는다.
                    $this->paymentService->requestPaymentOnApproval($claim, $processedBy);

                    return ['claim' => $this->reload($claim), 'payment_requested' => true];
                }

                throw OrderClaimException::paymentOutstanding();
            }
        }

        $updated = match (true) {
            $claim instanceof OrderExchange => $this->exchangeService->transition($claim, ExchangeStatusEnum::from($target), $context),
            $claim instanceof OrderReturn => $this->returnService->transition($claim, ReturnStatusEnum::from($target), $context),
            default => $this->refundRequestService->transition($claim, RefundStatusEnum::from($target), $context),
        };

        return ['claim' => $updated, 'payment_requested' => false];
    }

    /**
     * 신청서에 추가결제를 요청합니다. (결제 건 생성 + 결제대기 전이)
     *
     * 청구액이 없거나 이미 전액 수납된 신청서에 결제를 요구하는 호출은
     * `claim_payment_not_required` 로 거절한다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderExchange|OrderReturn 갱신된 신청서
     *
     * @throws OrderClaimException 청구 없음 / 전이 불가
     */
    public function requestPayment(OrderExchange|OrderReturn $claim, ?int $processedBy = null): OrderExchange|OrderReturn
    {
        if (! $claim->requiresAdditionalPayment() || $this->paymentService->outstandingAmount($claim) <= 0) {
            throw OrderClaimException::paymentNotRequired();
        }

        if ($claim->charge_method === ClaimChargeMethodEnum::PAY_ON_APPROVAL) {
            $this->paymentService->requestPaymentOnApproval($claim, $processedBy);

            return $this->reload($claim);
        }

        // pay_on_request — 신청 직후 초기화 경로를 그대로 쓴다 (결제 건 생성 + 결제대기 전이).
        // 신청서가 이미 결제대기를 지났으면 아래 전이가 게이트 판정을 그대로 돌려준다
        // (결제대기 → 결제대기 자기 전이는 no-op 허용).
        if ($this->paymentService->initiateForRequest($claim) !== null) {
            return $this->reload($claim);
        }

        $updated = $claim instanceof OrderExchange
            ? $this->exchangeService->transition($claim, ExchangeStatusEnum::PAYMENT_PENDING, ['processed_by' => $processedBy])
            : $this->returnService->transition($claim, ReturnStatusEnum::PAYMENT_PENDING, ['processed_by' => $processedBy]);

        return $updated;
    }

    /**
     * 여러 신청서를 일괄 전이합니다. (게이트 위반 skip 리포트 — 부분 성공 계약)
     *
     * 완료 전이는 건별로 완료 절차(`complete()`)를 타고, 승인 전이에서 결제 요청으로
     * 우회된 건은 `payment_requested` 로 따로 보고한다 — 승인됨과 결제 요청됨은
     * 운영자에게 다른 결과다.
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  array<int, int>  $ids  신청서 ID 목록
     * @param  string  $target  목표 상태 값
     * @param  array  $context  부가 정보 (processed_by / reject_reason / admin_memo)
     * @param  ClaimCompletionOptions|null  $completion  완료 처리 옵션 (완료 전이일 때만)
     * @return array{succeeded: array<int, int>, payment_requested: array<int, int>, skipped: array<int, array{id: int, claim_number: string|null, message: string}>} 전이 결과
     */
    public function bulkTransition(
        ClaimTypeEnum $type,
        array $ids,
        string $target,
        array $context = [],
        ?ClaimCompletionOptions $completion = null,
    ): array {
        $succeeded = [];
        $paymentRequested = [];
        $skipped = [];

        foreach ($ids as $id) {
            $claim = $this->findForTransition($type, (int) $id);

            if (! $claim) {
                $skipped[] = [
                    'id' => (int) $id,
                    'claim_number' => null,
                    'message' => __('sirsoft-ecommerce::exceptions.claim_not_found'),
                ];

                continue;
            }

            try {
                if ($target === 'completed') {
                    // 빈 상품 목록 = 신청서 전체 (D26 모달의 신청서 단위 진입점)
                    $this->complete($claim, [], $completion, $context['processed_by'] ?? null);
                    $succeeded[] = $claim->id;
                } else {
                    $result = $this->transition($claim, $target, $context);

                    if ($result['payment_requested']) {
                        $paymentRequested[] = $claim->id;
                    } else {
                        $succeeded[] = $claim->id;
                    }
                }
            } catch (OrderClaimException $e) {
                $skipped[] = [
                    'id' => $claim->id,
                    'claim_number' => $this->claimNumberOf($claim),
                    'message' => $e->getMessage(),
                ];
            }
        }

        return ['succeeded' => $succeeded, 'payment_requested' => $paymentRequested, 'skipped' => $skipped];
    }

    /**
     * 신청 상품 단위로 상태를 일괄 전이합니다. (D25)
     *
     * 완료 지시는 상품 단위 완료 절차(`completeExchange()`/`completeReturn()` 의 부분 완료)로
     * 우회한다 — `transitionOption()` 은 완료를 거절하고, 완료에는 재고·환불신청서 선택
     * (`ClaimCompletionOptions`)이 필요하기 때문이다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  array<int, int>  $itemIds  신청 상품 행 ID 목록
     * @param  string  $target  목표 상태 값
     * @param  array  $context  부가 정보 (processed_by)
     * @param  ClaimCompletionOptions|null  $completion  완료 처리 옵션 (완료 전이일 때만)
     * @return array{succeeded: array<int, int>, skipped: array<int, array{id: int, message: string}>} 전이 결과
     *
     * @throws OrderClaimException 완료 가능 상품 부재 등
     */
    public function transitionOptions(
        OrderExchange|OrderReturn $claim,
        array $itemIds,
        string $target,
        array $context = [],
        ?ClaimCompletionOptions $completion = null,
    ): array {
        if ($target !== 'completed') {
            return $claim instanceof OrderExchange
                ? $this->exchangeService->transitionOption($claim, $itemIds, ExchangeStatusEnum::from($target), $context)
                : $this->returnService->transitionOption($claim, $itemIds, ReturnStatusEnum::from($target), $context);
        }

        // 완료는 서비스가 대상 상품을 스스로 거르므로(전이 불가·종결 상품 제외) 실제로
        // 완료된 상품을 전후 비교로 보고한다 — 화면이 "0건 처리됨" 을 침묵으로 받지 않게.
        $before = $this->optionStatusMap($claim, $itemIds);

        $this->complete($claim, $itemIds, $completion, $context['processed_by'] ?? null);

        $after = $this->optionStatusMap($this->reload($claim), $itemIds);

        $succeeded = [];
        $skipped = [];

        foreach ($itemIds as $itemId) {
            $itemId = (int) $itemId;
            $completedValue = $claim instanceof OrderExchange
                ? ExchangeStatusEnum::COMPLETED->value
                : ReturnStatusEnum::COMPLETED->value;

            if (($after[$itemId] ?? null) === $completedValue && ($before[$itemId] ?? null) !== $completedValue) {
                $succeeded[] = $itemId;

                continue;
            }

            $skipped[] = [
                'id' => $itemId,
                'message' => __('sirsoft-ecommerce::exceptions.claim_transition_not_allowed', [
                    'from' => $this->optionStatusLabel($claim, $before[$itemId] ?? null),
                    'to' => $claim instanceof OrderExchange
                        ? ExchangeStatusEnum::COMPLETED->label()
                        : ReturnStatusEnum::COMPLETED->label(),
                ]),
            ];
        }

        return ['succeeded' => $succeeded, 'skipped' => $skipped];
    }

    /**
     * 신청서를 완료 처리합니다. (D26 — 완료 옵션 필수)
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  array<int, int>  $itemIds  완료할 신청 상품 행 ID 목록 (빈 배열이면 전체)
     * @param  ClaimCompletionOptions|null  $completion  완료 처리 옵션
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderExchange|OrderReturn 완료 처리된 신청서
     *
     * @throws OrderClaimException 완료 절차 위반 (환불신청서 완료 지시 포함)
     */
    public function complete(
        OrderExchange|OrderReturn|OrderRefund $claim,
        array $itemIds,
        ?ClaimCompletionOptions $completion,
        ?int $processedBy = null,
    ): OrderExchange|OrderReturn {
        // 환불신청서의 완료는 환불 실행(RefundExecutionService, S6b)의 소유다. FormRequest 가
        // 이미 막지만, 서비스 경유 호출(훅·내부)도 같은 거절을 받아야 한다.
        if ($claim instanceof OrderRefund || $completion === null) {
            throw OrderClaimException::completionRequiresCompletionFlow();
        }

        return $claim instanceof OrderExchange
            ? $this->exchangeService->completeExchange($claim, $itemIds, $completion, $processedBy)
            : $this->returnService->completeReturn($claim, $itemIds, $completion, $processedBy);
    }

    // ───────────────────────────────────────────────
    // 금액 수정 / 취소 가능 금액 / 환불 실행 (S6b)
    // ───────────────────────────────────────────────

    /**
     * 관리자가 신청서 금액을 수정합니다. (D18 — 유형 분기 + 수정 전후 훅)
     *
     * 교환·반품은 배송비·청구방식·메모 축, 환불신청서는 환불액·환불수단 축이다. 수정 전후
     * 금액과 작업자는 `claim.{type}.amounts_updated` 훅으로 나간다 — 활동 로그(S7)가
     * "수정 전 → 후" 를 기록할 근거다(D18).
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  array  $amounts  수정값 (유형별 FormRequest 가 게이트한 키만)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderExchange|OrderReturn|OrderRefund 갱신된 신청서
     *
     * @throws OrderClaimException 수정 불가 상태·금액·청구 방식·D19 상한 위반
     */
    public function updateAmounts(
        OrderExchange|OrderReturn|OrderRefund $claim,
        array $amounts,
        ?int $processedBy = null,
    ): OrderExchange|OrderReturn|OrderRefund {
        $before = $this->amountSnapshot($claim);

        $updated = match (true) {
            $claim instanceof OrderExchange => $this->exchangeService->updateAmounts($claim, $amounts, $processedBy),
            $claim instanceof OrderReturn => $this->returnService->updateAmounts($claim, $amounts, $processedBy),
            default => $this->refundRequestService->updateAmounts($claim, $amounts, $processedBy),
        };

        $after = $this->amountSnapshot($updated);

        if ($before !== $after) {
            $hookType = match (true) {
                $claim instanceof OrderExchange => ClaimTypeEnum::EXCHANGE->value,
                $claim instanceof OrderReturn => ClaimTypeEnum::RETURN->value,
                default => ClaimTypeEnum::REFUND->value,
            };

            HookManager::doAction(
                "sirsoft-ecommerce.claim.{$hookType}.amounts_updated",
                $updated,
                $before,
                $after,
                $processedBy,
            );
        }

        return $updated;
    }

    /**
     * 주문의 취소 가능 금액(D19)을 산정합니다. (환불 상세·주문상세 환불 모달 공용)
     *
     * `$refundId` 가 있으면 그 환불신청서의 문맥(출처별 ① 축 + 자기 제외 선점)으로,
     * 없으면 전달된 아이템(미지정 시 미취소 옵션 전량)으로 산정한다.
     *
     * @param  Order  $order  대상 주문
     * @param  int|null  $refundId  환불신청서 ID (환불 상세 문맥)
     * @param  array  $items  대상 아이템 [{order_option_id, cancel_quantity}] (주문 문맥)
     * @return CancellableAmount|null 산정 결과 (환불신청서가 없거나 주문 소속이 아니면 null)
     */
    public function cancellableAmountForOrder(Order $order, ?int $refundId = null, array $items = []): ?CancellableAmount
    {
        if ($refundId !== null) {
            $refund = $this->refundRepository->findById($refundId, ['refundOptions']);

            // 중첩 스코프 — 다른 주문의 환불신청서는 없는 것으로 답한다 (교차 접근 404).
            if (! $refund || (int) $refund->order_id !== (int) $order->id) {
                return null;
            }

            return $this->cancellableCalculator->calculateForRefund($order, $refund);
        }

        if ($items === []) {
            $order->loadMissing('options');

            foreach ($order->options as $option) {
                if ($option->option_status === OrderStatusEnum::CANCELLED) {
                    continue;
                }

                $items[] = [
                    'order_option_id' => $option->id,
                    'cancel_quantity' => (int) $option->quantity,
                ];
            }
        }

        if ($items === []) {
            // 전 옵션이 취소된 주문 — 되돌릴 대상이 없으므로 0 상한으로 답한다 (재계산 호출 없이).
            // 통화는 계산기가 해석한다 — 스냅샷을 직접 읽으면 이 경로만 null 이 나간다.
            return $this->cancellableCalculator->emptyFor($order);
        }

        return $this->cancellableCalculator->calculate($order, $items, ClaimTypeEnum::REFUND);
    }

    /**
     * 승인된 환불신청서의 환불을 실행합니다. ('환불하기' — 위임)
     *
     * @param  OrderRefund  $refund  환불신청서
     * @param  array  $overrides  실행 시점 금액 (refund_amount? / refund_points_amount? / refund_method?)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderRefund 실행 완료된 환불신청서
     *
     * @throws OrderClaimException 실행 불가 출처·상태 / D19 상한 초과 / 재실행
     */
    public function executeRefund(OrderRefund $refund, array $overrides = [], ?int $processedBy = null): OrderRefund
    {
        return $this->refundRequestService->executeRefund($refund, $overrides, $processedBy);
    }

    /**
     * 완료 처리 모달의 미리보기 요약을 만듭니다. (D26 — `POST admin/claims/completion-preview`)
     *
     * 실행 전에 "함께 실행되는 처리"(환불 예정액·재고 복구·적립 차감·쿠폰 복원)를 건별로
     * 답한다 — 모달이 보여주는 값의 SSoT 다. '재고 복구 대상 유무' 는
     * `ClaimCompletionOptions::hasStockRestoreTarget()` 로 판정한다(요청 기준 — 미리보기의
     * 질문과 정확히 같다. 완료 실행 경로는 이 메서드를 쓰면 안 된다 — DTO docblock 참조).
     *
     * 금액은 원시값과 함께 표기 문자열(`*_formatted`)을 싣는다 — 모달이 통화 기호·자릿수를
     * 조립하면 기본 통화가 원이 아닌 상점에서 단위만 틀린 금액이 나간다. 표기 통화는 거래
     * 시점 스냅샷이 정하며, 합계는 대상이 한 통화로 모일 때만 낸다.
     *
     * @param  ClaimTypeEnum  $type  클레임 유형 (교환·반품)
     * @param  array<int, array{id: int, item_ids?: array<int, int>, restore_stock_by_item?: array<int|string, bool>}>  $claims  대상 신청서 목록
     * @param  bool  $createRefundRequest  환불신청서 접수 선택 상태 (모달 라디오)
     * @return array{claims: array<int, array>, skipped: array<int, array{id: int, message: string}>, totals: array{claim_count: int, completable_item_count: int, refund_amount_total: float, refund_points_amount_total: float, currency_code: string|null, refund_amount_total_formatted: string|null, refund_points_amount_total_formatted: string|null, deducted_shipping_fee_total: float, deducted_shipping_fee_total_formatted: string|null}} 미리보기 결과
     */
    public function completionPreview(ClaimTypeEnum $type, array $claims, bool $createRefundRequest): array
    {
        $rows = [];
        $skipped = [];
        $completableTotal = 0;
        $refundTotal = 0.0;
        $pointsTotal = 0.0;
        $shippingTotal = 0.0;
        /** @var array<string, array|null> $totalCurrencies 합계에 실제로 기여한 통화 → 스냅샷 */
        $totalCurrencies = [];

        foreach ($claims as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            $claim = $this->findForTransition($type, $id);

            if (! $claim instanceof OrderExchange && ! $claim instanceof OrderReturn) {
                $skipped[] = [
                    'id' => $id,
                    'message' => __('sirsoft-ecommerce::exceptions.claim_not_found'),
                ];

                continue;
            }

            $claim->loadMissing('order');

            // 표기 통화는 거래 시점에 동결된 값이다 — 신청서 자신의 통화(교환·반품 컬럼)를
            // 우선하고, 없으면 주문 스냅샷의 기준 통화로 폴백한다. 자릿수·절사 규칙도
            // 스냅샷이 SSoT 이므로 포맷에 함께 넘긴다 (현재 설정을 보면 그 통화가 삭제된
            // 뒤 자릿수가 폴백 2자리로 바뀌어 과거 금액 표기가 흔들린다).
            $currency = $claim->currency_code ?: $this->orderBaseCurrencyOf($claim->order);
            $snapshot = is_array($claim->order?->currency_snapshot) ? $claim->order->currency_snapshot : null;

            $itemIds = array_map('intval', (array) ($entry['item_ids'] ?? []));

            $restoreByItem = [];
            foreach ((array) ($entry['restore_stock_by_item'] ?? []) as $itemId => $restore) {
                $restoreByItem[(int) $itemId] = (bool) $restore;
            }

            $completion = new ClaimCompletionOptions(
                restoreStockByItem: $restoreByItem,
                createRefundRequest: $createRefundRequest,
            );

            if ($claim instanceof OrderReturn) {
                $preview = $this->returnService->previewCompletion($claim, $itemIds);
                $completableIds = $preview['completable_item_ids'];

                $refund = [
                    // 사유 판정은 뿌리(previewCompletion)가 소유한다 — 교환 분기와 같은 결이다.
                    // 여기서 already_filed 만 보고 되짚으면 '접수할 상품 없음' 이 사유 없는
                    // 0원 접수 예고로 나가 실제 접수(422)와 갈린다.
                    'applicable' => $createRefundRequest && $preview['blocked_reason'] === null,
                    'already_filed' => $preview['already_filed'],
                    'blocked_reason' => $preview['blocked_reason'],
                    'refund_amount' => $preview['refund_amount'],
                    'refund_points_amount' => $preview['refund_points_amount'],
                    'deducted_shipping_fee' => $preview['deducted_shipping_fee'],
                ];
                $couponRestore = $preview['coupon_restore'];
            } else {
                $completableIds = $this->completableExchangeItemIds($claim, $itemIds);
                $refundPreview = $this->exchangeService->previewRefundRequest($claim, $completableIds);

                $refund = [
                    'applicable' => $createRefundRequest && $refundPreview['refundable'],
                    'already_filed' => $refundPreview['blocked_reason'] === 'claim_refund_request_exists',
                    'blocked_reason' => $refundPreview['blocked_reason'],
                    'refund_amount' => $refundPreview['refund_amount'],
                    'refund_points_amount' => $refundPreview['refund_points_amount'],
                    'deducted_shipping_fee' => $refundPreview['deducted_shipping_fee'],
                ];
                // 교환 완료는 주문 재계산·쿠폰 복원을 하지 않는다 (completeExchange docblock).
                $couponRestore = false;
            }

            $restoreCount = count(array_filter(
                $completableIds,
                fn (int $itemId) => $completion->shouldRestoreStock($itemId),
            ));

            $refund += [
                'refund_amount_formatted' => $this->currencyService->formatPrice((float) $refund['refund_amount'], $currency, $snapshot),
                'refund_points_amount_formatted' => $this->currencyService->formatPrice((float) $refund['refund_points_amount'], $currency, $snapshot),
                'deducted_shipping_fee_formatted' => $this->currencyService->formatPrice((float) $refund['deducted_shipping_fee'], $currency, $snapshot),
            ];

            $rows[] = [
                'id' => $claim->id,
                'claim_number' => $this->claimNumberOf($claim),
                'currency_code' => $currency,
                'completable_item_ids' => $completableIds,
                'completable_item_count' => count($completableIds),
                'has_stock_restore_target' => $completion->hasStockRestoreTarget(),
                'stock_restore_count' => $restoreCount,
                'stock_discard_count' => max(0, count($completableIds) - $restoreCount),
                'earn_recovery' => $claim->order?->user_id !== null && $completableIds !== [],
                'coupon_restore' => $couponRestore,
                'refund' => $refund,
            ];

            $completableTotal += count($completableIds);

            if ($refund['applicable']) {
                $refundTotal += (float) $refund['refund_amount'];
                $pointsTotal += (float) $refund['refund_points_amount'];
                $shippingTotal += (float) $refund['deducted_shipping_fee'];
                $totalCurrencies[$currency] = $snapshot;
            }
        }

        // 합계는 통화가 하나로 모일 때만 표기한다. 여러 통화가 섞인 선택에서 숫자를 그대로
        // 더하면 값은 맞고 단위만 없는 금액이 되는데, 화면에는 정상적인 합계로 보인다 —
        // 그럴 때 `currency_code` 를 null 로 내보내 화면이 합계 표기를 접게 한다.
        $totalCurrency = count($totalCurrencies) === 1 ? (string) array_key_first($totalCurrencies) : null;
        $totalSnapshot = $totalCurrency !== null ? $totalCurrencies[$totalCurrency] : null;

        return [
            'claims' => $rows,
            'skipped' => $skipped,
            'totals' => [
                'claim_count' => count($rows),
                'completable_item_count' => $completableTotal,
                'refund_amount_total' => $refundTotal,
                'refund_points_amount_total' => $pointsTotal,
                'currency_code' => $totalCurrency,
                'refund_amount_total_formatted' => $totalCurrency === null
                    ? null
                    : $this->currencyService->formatPrice($refundTotal, $totalCurrency, $totalSnapshot),
                'refund_points_amount_total_formatted' => $totalCurrency === null
                    ? null
                    : $this->currencyService->formatPrice($pointsTotal, $totalCurrency, $totalSnapshot),
                // 회수배송비 차감 합계 — 모달의 '함께 실행되는 처리' 가 그린다 (§6-4-1). 이번
                // 라운드에 실제로 접수되는 신청서(`applicable`)만 더하므로 '접수하지 않음' 을
                // 고르면 0 이 되고 화면은 그 항목을 접는다.
                'deducted_shipping_fee_total' => $shippingTotal,
                'deducted_shipping_fee_total_formatted' => $totalCurrency === null
                    ? null
                    : $this->currencyService->formatPrice($shippingTotal, $totalCurrency, $totalSnapshot),
            ],
        ];
    }

    /**
     * 주문 스냅샷의 기준 통화 코드를 반환합니다. (없으면 현재 기본 통화)
     *
     * 방어적 폴백이며 현재 스키마에서는 도달하지 않는다 — `order_exchanges.currency_code` ·
     * `order_returns.currency_code` 가 NOT NULL(기본값 보유)이라 호출부의 `?:` 좌변이 항상
     * 참이다. 컬럼이 nullable 로 바뀌는 날을 대비해 남겨 둔다.
     *
     * 리소스 계층의 `HasMultiCurrencyPrices::resolveOrderBaseCurrencyCode()` 와 **같은 규칙이
     * 아니다.** 스냅샷 `base_currency` 를 우선하는 1단계는 같지만, 그 뒤 폴백이 다르다 —
     * 트레이트는 ① 선언된 기본 통화가 통화 목록에 실재할 때만 채택 ② 없으면 `is_default`
     * 통화 ③ 마지막에 선언값의 3단인데, 여기서 부르는 `CurrencyConversionService::
     * getDefaultCurrency()` 는 선언값을 그대로 돌려주는 1단이다. 삭제된 통화를 가리키는
     * 설정에서 두 값이 갈리므로, 이 폴백이 실제로 도달하게 되면 그때 트레이트와 동형으로
     * 맞추거나 판정을 공용 지점으로 올린다.
     *
     * @param  Order|null  $order  주문
     * @return string 기준 통화 코드
     */
    protected function orderBaseCurrencyOf(?Order $order): string
    {
        $snapshot = $order?->currency_snapshot;

        if (is_array($snapshot) && ! empty($snapshot['base_currency'])) {
            return (string) $snapshot['base_currency'];
        }

        return $this->currencyService->getDefaultCurrency();
    }

    /**
     * 완료된 신청서에 환불신청서를 수동 접수합니다. (D26 후속 접수 — 유형 위임)
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderRefund 생성된 환불신청서
     *
     * @throws OrderClaimException 완료 상품 부재 / 이미 접수 / 환불 대상 없음
     */
    public function createRefundRequestForCompleted(OrderExchange|OrderReturn $claim, ?int $processedBy = null): OrderRefund
    {
        return $claim instanceof OrderExchange
            ? $this->exchangeService->createRefundRequestForCompleted($claim, $processedBy)
            : $this->returnService->createRefundRequestForCompleted($claim, $processedBy);
    }

    /**
     * 완료 전이가 가능한 교환 신청 상품 행 ID 를 고릅니다. (미리보기 전용)
     *
     * 완료 실행(`completeExchange`)의 대상 필터와 같은 조건이다 — 게이트가 갈리면 모달이
     * 보여준 건수와 실제 완료 건수가 달라진다.
     *
     * @param  OrderExchange  $claim  교환 신청서
     * @param  array<int, int>  $itemIds  요청 대상 (빈 배열이면 전체)
     * @return array<int, int> 완료 가능 상품 행 ID
     */
    protected function completableExchangeItemIds(OrderExchange $claim, array $itemIds): array
    {
        $claim->loadMissing('exchangeOptions');

        $ids = [];

        foreach ($claim->exchangeOptions as $item) {
            if ($itemIds !== [] && ! in_array((int) $item->id, $itemIds, true)) {
                continue;
            }

            if ($item->option_status->canTransitionTo(ExchangeStatusEnum::COMPLETED)
                && ! $item->option_status->isFinal()) {
                $ids[] = (int) $item->id;
            }
        }

        return $ids;
    }

    /**
     * 금액 수정 훅에 실을 금액 스냅샷을 만듭니다. (수정 전/후 비교 근거)
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @return array<string, mixed> 금액 스냅샷
     */
    protected function amountSnapshot(OrderExchange|OrderReturn|OrderRefund $claim): array
    {
        if ($claim instanceof OrderRefund) {
            return [
                'refund_amount' => (float) $claim->refund_amount,
                'refund_points_amount' => (float) $claim->refund_points_amount,
                'refund_method' => $claim->refund_method?->value,
            ];
        }

        return [
            'claim_shipping_fee' => (float) $claim->claim_shipping_fee,
            'total_charge_amount' => (float) $claim->total_charge_amount,
            'charge_method' => $claim->charge_method?->value,
            'admin_memo' => $claim->admin_memo,
        ];
    }

    // ───────────────────────────────────────────────
    // 송장
    // ───────────────────────────────────────────────

    /**
     * 회수 운송장을 등록합니다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  int|null  $carrierId  택배사 ID
     * @param  string|null  $trackingNumber  운송장 번호
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderExchange|OrderReturn 갱신된 신청서
     *
     * @throws OrderClaimException 송장 정보 불완전
     */
    public function registerCollectionTracking(
        OrderExchange|OrderReturn $claim,
        ?int $carrierId,
        ?string $trackingNumber,
        ?int $processedBy = null,
    ): OrderExchange|OrderReturn {
        return $claim instanceof OrderExchange
            ? $this->exchangeService->registerCollectionTracking($claim, $carrierId, $trackingNumber, $processedBy)
            : $this->returnService->registerCollectionTracking($claim, $carrierId, $trackingNumber, $processedBy);
    }

    /**
     * 재발송 운송장을 등록합니다. (교환 전용 — 반품·환불에는 재발송 축이 없다)
     *
     * @param  OrderExchange  $claim  교환 신청서
     * @param  int|null  $carrierId  택배사 ID
     * @param  string|null  $trackingNumber  운송장 번호
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderExchange 갱신된 신청서
     *
     * @throws OrderClaimException 송장 정보 불완전
     */
    public function registerRedeliveryTracking(
        OrderExchange $claim,
        ?int $carrierId,
        ?string $trackingNumber,
        ?int $processedBy = null,
    ): OrderExchange {
        return $this->exchangeService->registerRedeliveryTracking($claim, $carrierId, $trackingNumber, $processedBy);
    }

    // ───────────────────────────────────────────────
    // 내부 헬퍼
    // ───────────────────────────────────────────────

    /**
     * 신청서를 상품 행과 함께 다시 싣습니다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @return OrderExchange|OrderReturn 갱신된 신청서
     */
    protected function reload(OrderExchange|OrderReturn $claim): OrderExchange|OrderReturn
    {
        return $claim->fresh([$claim instanceof OrderExchange ? 'exchangeOptions' : 'returnOptions']);
    }

    /**
     * 신청서의 클레임 번호를 반환합니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return string|null 클레임 번호
     */
    protected function claimNumberOf(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): ?string
    {
        return match (true) {
            $claim instanceof OrderExchange => $claim->exchange_number,
            $claim instanceof OrderReturn => $claim->return_number,
            $claim instanceof OrderRefund => $claim->refund_number,
            default => $claim->cancel_number,
        };
    }

    /**
     * 요청된 상품 행들의 현재 상태 값 맵을 만듭니다. (완료 전후 비교용)
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  array<int, int>  $itemIds  신청 상품 행 ID 목록
     * @return array<int, string> 상품 행 ID => 상태 값
     */
    protected function optionStatusMap(OrderExchange|OrderReturn $claim, array $itemIds): array
    {
        $relation = $claim instanceof OrderExchange ? 'exchangeOptions' : 'returnOptions';
        $claim->loadMissing($relation);

        $map = [];

        foreach ($claim->{$relation} as $item) {
            if ($itemIds === [] || in_array($item->id, array_map('intval', $itemIds), true)) {
                $map[$item->id] = $item->option_status->value;
            }
        }

        return $map;
    }

    /**
     * 상품 행 상태 값의 라벨을 반환합니다. (skip 리포트 문구용)
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서 (유형 판정용)
     * @param  string|null  $statusValue  상태 값
     * @return string 라벨 (해석 불가 시 원시 값)
     */
    protected function optionStatusLabel(OrderExchange|OrderReturn $claim, ?string $statusValue): string
    {
        if ($statusValue === null) {
            return '';
        }

        $status = $claim instanceof OrderExchange
            ? ExchangeStatusEnum::tryFrom($statusValue)
            : ReturnStatusEnum::tryFrom($statusValue);

        return $status?->label() ?? $statusValue;
    }
}
