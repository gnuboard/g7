<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
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
