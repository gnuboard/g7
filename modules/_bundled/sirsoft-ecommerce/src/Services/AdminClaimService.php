<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\DTO\CancellableAmount;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTrackingDirectionEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimActivityLogRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimReasonRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\Concerns\FiresClaimHooksAfterCommit;
use Modules\Sirsoft\Ecommerce\Support\ClaimItemSpecs;
use Modules\Sirsoft\Ecommerce\Support\ClaimNotificationHooks;
use Modules\Sirsoft\Ecommerce\Support\ClaimProgressSteps;
use Modules\Sirsoft\Ecommerce\Support\ClaimTransitionVocabulary;

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
    use FiresClaimHooksAfterCommit;

    /**
     * @param  OrderExchangeService  $exchangeService  교환 서비스
     * @param  OrderReturnService  $returnService  반품 서비스
     * @param  OrderRefundRequestService  $refundRequestService  환불신청 서비스
     * @param  ClaimPaymentService  $paymentService  클레임 추가결제 서비스
     * @param  CancellableAmountCalculator  $cancellableCalculator  취소 가능 금액 산정기 (D19)
     * @param  CurrencyConversionService  $currencyService  통화 변환·표기 서비스 (완료 미리보기 금액 표기)
     * @param  EcommerceSettingsService  $settingsService  이커머스 설정 서비스 (완료 처리 기본값 — D26)
     * @param  UserClaimService  $userClaimService  클레임 공용 서비스 (클레임 번호 해석 재사용)
     * @param  ClaimActivityLogRepositoryInterface  $activityLogRepository  클레임 활동 로그 Repository
     * @param  OrderExchangeRepositoryInterface  $exchangeRepository  교환 Repository
     * @param  OrderReturnRepositoryInterface  $returnRepository  반품 Repository
     * @param  OrderRefundRepositoryInterface  $refundRepository  환불 Repository
     * @param  OrderCancelRepositoryInterface  $cancelRepository  취소 Repository
     * @param  PaymentMethodResolver  $paymentMethodResolver  결제수단 카탈로그 해석기 (라벨 SSoT)
     * @param  MileageTransactionRepositoryInterface  $mileageRepository  마일리지 원장 Repository (적립 차감 예고 — 적립 여부 판정)
     * @param  StockService  $stockService  재고 서비스 (뒤늦은 재고 복구 — S13)
     * @param  OrderExchangeOptionRepositoryInterface  $exchangeOptionRepository  교환 상품 행 Repository (재고 복구 표시)
     * @param  OrderReturnOptionRepositoryInterface  $returnOptionRepository  반품 상품 행 Repository (재고 복구 표시)
     * @param  ClaimReasonRepositoryInterface  $claimReasonRepository  사유 카탈로그 Repository (신청 내용 수정의 사유 선택지 — S15)
     */
    public function __construct(
        protected OrderExchangeService $exchangeService,
        protected OrderReturnService $returnService,
        protected OrderRefundRequestService $refundRequestService,
        protected ClaimPaymentService $paymentService,
        protected CancellableAmountCalculator $cancellableCalculator,
        protected CurrencyConversionService $currencyService,
        protected EcommerceSettingsService $settingsService,
        protected UserClaimService $userClaimService,
        protected ClaimActivityLogRepositoryInterface $activityLogRepository,
        protected OrderExchangeRepositoryInterface $exchangeRepository,
        protected OrderReturnRepositoryInterface $returnRepository,
        protected OrderRefundRepositoryInterface $refundRepository,
        protected OrderCancelRepositoryInterface $cancelRepository,
        protected PaymentMethodResolver $paymentMethodResolver,
        protected MileageTransactionRepositoryInterface $mileageRepository,
        protected StockService $stockService,
        protected OrderExchangeOptionRepositoryInterface $exchangeOptionRepository,
        protected OrderReturnOptionRepositoryInterface $returnOptionRepository,
        protected ClaimReasonRepositoryInterface $claimReasonRepository,
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
     * 현재 탭의 뱃지만 목록과 **완전히 같은 필터**로 다시 셉니다. (F13)
     *
     * 뱃지의 원 의미는 "그 탭으로 가면 몇 건인가" 이고(`ClaimListRequest::countFilters()` 에
     * 명시 결정돼 있다), 그래서 상태·귀책·청구·사유처럼 **유형 종속 어휘**인 필터는 다른 탭의
     * 건수에 걸지 않는다 — 걸면 그 탭 건수가 언제나 0 이 된다.
     *
     * 그러나 **지금 보고 있는 탭**에서는 그 규칙이 오해를 만든다. 운영자가 상태를 좁히면
     * 목록은 3건인데 뱃지는 17건으로 남아, 어느 쪽이 맞는지 화면만 보고는 알 수 없다.
     * 그래서 현재 탭만 목록과 같은 필터로 센다 — 의미도 지키고 오해도 없앤다.
     *
     * @param  array<string, int>  $counts  `counts()` 결과
     * @param  ClaimTypeEnum  $currentType  현재 탭의 유형
     * @param  array<string, mixed>  $filters  목록과 같은 전체 필터
     * @return array<string, int> 현재 탭 건수가 보정된 결과
     */
    public function refreshCurrentTypeCount(array $counts, ClaimTypeEnum $currentType, array $filters = []): array
    {
        $repository = match ($currentType) {
            ClaimTypeEnum::EXCHANGE => $this->exchangeRepository,
            ClaimTypeEnum::RETURN => $this->returnRepository,
            ClaimTypeEnum::REFUND => $this->refundRepository,
            ClaimTypeEnum::CANCEL => $this->cancelRepository,
        };

        $counts[$currentType->value] = $repository->countForAdmin($filters);

        return $counts;
    }

    /**
     * 상세의 신청서 단위 전이 바가 제공할 선택지를 만듭니다. (#599 후속 F1)
     *
     * 두 축의 **교집합**이다 — ⓐ 운영자가 직접 지정할 수 있는 상태(`adminAssignableValues()`)와
     * ⓑ 지금 상태에서 갈 수 있는 상태(`allowedTransitions()`). ⓐ 만 보면 이미 승인된 건에
     * "승인" 이 남아 눌러도 422 가 되고, ⓑ 만 보면 완료·처리중처럼 **돈이 움직여야 도달하는**
     * 상태가 목록에 서서 돈 없이 완료를 찍을 수 있게 된다.
     *
     * 그 교집합에서 **심사 선택지**(결제 요청·승인·보류·반려)만 남긴다 — 회수·발송 단계는 상품
     * 칸의 몫이고 완료는 [완료 처리] 가 유일한 입구다(`ClaimTransitionVocabulary::reviewOptionsFrom()`).
     * 남은 선택지 중 **눌러도 `transition()` 이 거절할 것**은 싣지 않는다(#599 후속 운영 결정):
     *
     *  - 결제대기 — 고객이 더 낼 돈이 없으면 `requestPayment()` 가 `claim_payment_not_required` 로 거절한다.
     *  - 승인 — 신청 시점 결제의 미수납 건은 `divertApprovalToPayment()` 가 `claim_payment_outstanding` 으로 거절한다.
     *  - 반려 — 상품 일부가 신청서보다 앞서 있으면 서비스가 `claim_transition_not_allowed` 로 거절한다.
     *
     * 판정은 전부 거절하는 쪽의 메서드를 그대로 부른다 — 여기서 조건을 다시 적으면 선택지와
     * 거절이 갈려 운영자가 고른 값이 다시 422 로 돌아온다.
     *
     * 취소신청서는 전이 축이 없다(생성이 곧 확정이다) — 빈 배열을 돌려주어 화면이 바 자체를
     * 그리지 않게 한다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return array<int, array{value: string, label: string}> 전이 선택지
     */
    public function transitionOptionsFor(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): array
    {
        if ($claim instanceof OrderCancel) {
            return [];
        }

        $current = match (true) {
            $claim instanceof OrderExchange => $claim->exchange_status,
            $claim instanceof OrderReturn => $claim->return_status,
            default => $claim->refund_status,
        };

        // 교집합 계산은 `ClaimTransitionVocabulary` 가 단독 소유한다 — 헤더 바·상품 행 리소스·
        // FormRequest 세 곳이 같은 어휘를 읽어야 화면에 선 값이 서버에서 422 가 되지 않는다.
        $options = ClaimTransitionVocabulary::reviewOptionsFrom(
            $current instanceof \BackedEnum ? $current : null
        );

        if ($claim instanceof OrderRefund) {
            return $options;
        }

        return array_values(array_filter(
            $options,
            fn (array $option) => ! $this->headerTransitionWouldBeRejected($claim, $option['value']),
        ));
    }

    /**
     * 교환·반품 신청서의 신청서 단위 전이가 `transition()` 에서 거절될지 판정합니다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  string  $target  목표 상태 값
     * @return bool 거절되면 true
     */
    protected function headerTransitionWouldBeRejected(OrderExchange|OrderReturn $claim, string $target): bool
    {
        $owesMoney = $claim->requiresAdditionalPayment()
            && $this->paymentService->outstandingAmount($claim) > 0;

        return match ($target) {
            'payment_pending' => ! $owesMoney,
            'approved' => $owesMoney && $claim->charge_method !== ClaimChargeMethodEnum::PAY_ON_APPROVAL,
            default => $claim instanceof OrderExchange
                ? $this->exchangeService->headerTransitionBlockedByItems($claim, ExchangeStatusEnum::from($target))
                : $this->returnService->headerTransitionBlockedByItems($claim, ReturnStatusEnum::from($target)),
        };
    }

    /**
     * 지금 완료할 수 있는 신청 상품 수를 셉니다. (상세 [완료 처리] 버튼의 활성·안내 건수)
     *
     * 완료 절차가 대상으로 삼는 상품과 같은 판정(`isCompletableItem()`)이다. 예전에는 버튼이
     * 신청서가 끝나지 않았다는 것만 보고 켜져, 회수도착 전에 누르면 모달까지 열린 뒤
     * 「완료 처리할 수 있는 신청 상품이 없습니다」 로 거절됐다. 환불·취소는 [완료 처리] 가 없다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return int 완료 가능 상품 수
     */
    public function completableItemCount(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): int
    {
        return match (true) {
            $claim instanceof OrderExchange => $claim->loadMissing('exchangeOptions')->exchangeOptions
                ->filter(fn ($item) => $this->exchangeService->isCompletableItem($item))
                ->count(),
            $claim instanceof OrderReturn => $claim->loadMissing('returnOptions')->returnOptions
                ->filter(fn ($item) => $this->returnService->isCompletableItem($item))
                ->count(),
            default => 0,
        };
    }

    /**
     * 「미회수로 완료」 할 수 있는 상품 행을 반환합니다. (선교환 D3 — 새 물건은 보냈는데 헌 물건이 오지 않은 행)
     *
     * 완료 모달이 행마다 체크와 사유 칸을 그리는 근거다. 판정은 완료 절차와 같은
     * `OrderExchangeService::isUnreturnedEligibleItem()` 이다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @param  array<int, int>  $itemIds  대상 행 ID (빈 배열이면 전체)
     * @return array<int, array{id: int, label: string}> 미회수 완료 가능 행
     */
    public function unreturnedEligibleItems(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim, array $itemIds = []): array
    {
        if (! $claim instanceof OrderExchange || ! $claim->isAdvanceExchange()) {
            return [];
        }

        $claim->loadMissing('exchangeOptions.orderOption');
        $rows = [];

        foreach ($claim->exchangeOptions as $item) {
            if ($itemIds !== [] && ! in_array((int) $item->id, $itemIds, true)) {
                continue;
            }

            if ($this->exchangeService->isUnreturnedEligibleItem($item, $claim->mode())) {
                $option = $item->orderOption;
                $name = $option?->product_name;
                $rows[] = [
                    'id' => (int) $item->id,
                    'label' => trim((is_array($name) ? ($name[app()->getLocale()] ?? reset($name)) : (string) $name).' × '.(int) $item->exchange_quantity),
                ];
            }
        }

        return $rows;
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
     * 취소신청서도 번호로 열린다(조회 전용) — 전이·송장·메모 축이 없고, 환불 상태·금액은
     * 취소 발 환불(`refund`)이 출처다.
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  int  $id  신청서 ID
     * @return OrderExchange|OrderReturn|OrderRefund|OrderCancel|null 신청서 (없으면 null)
     */
    public function findForDetail(ClaimTypeEnum $type, int $id): OrderExchange|OrderReturn|OrderRefund|OrderCancel|null
    {
        return match ($type) {
            // 주문 배송지는 재발송지 뱃지 판정(D14)이 읽는다 — 미로드로 두면 상세 1회당
            // 지연 로드 쿼리가 하나 더 붙는다. 주문 결제행은 결제 정보 섹션(D17-③)이
            // 결제수단 카드로 그린다 — 주문상세 엔드포인트는 `orders.read` 게이트라 이
            // 화면이 직접 부를 수 없고, 봉투가 그 통로다.
            // 송장은 상품 행에 있다 — 행의 택배사를 함께 싣는다(운송장 묶음·상품 칸 송장 열, N+1 방지).
            ClaimTypeEnum::EXCHANGE => $this->exchangeRepository->findById($id, [
                'exchangeOptions.orderOption', 'exchangeOptions.collectionCarrier', 'exchangeOptions.redeliveryCarrier',
                'images', 'order.shippingAddress', 'order.payments', 'requestedByAdminUser', 'withdrawnByAdminUser',
            ]),
            ClaimTypeEnum::RETURN => $this->returnRepository->findById($id, [
                'returnOptions.orderOption', 'returnOptions.collectionCarrier', 'returnOptions.refund',
                'images', 'order.payments', 'requestedByAdminUser', 'withdrawnByAdminUser',
            ]),
            ClaimTypeEnum::REFUND => $this->refundRepository->findById($id, [
                'refundOptions.orderOption', 'images', 'order.payments',
            ]),
            // 취소 이력도 번호로 열린다 — 주문상세 클레임 내역과 클레임 목록 취소 탭의 번호가
            // 같은 화면으로 이어져야 한다. 취소는 전이·송장·메모 축이 없어 조회 전용이며,
            // 취소 발 환불(`refund`)이 환불 상태·금액의 출처다.
            ClaimTypeEnum::CANCEL => $this->cancelRepository->findById($id, [
                'cancelOptions.orderOption', 'refund', 'order.payments', 'order.shippingAddress',
            ]),
        };
    }

    /**
     * 클레임 번호로 상세 신청서를 조회합니다. (관리자 상세 화면의 진입 경로)
     *
     * 관리자 상세 라우트는 번호 기반(`/admin/ecommerce/claims/{claimNumber}`)이고 유형별
     * API 는 `(유형, id)` 기반이다 — 번호에서 유형을 읽어내는 해석은
     * `UserClaimService::resolveByNumber()` 가 이미 갖고 있으므로 그것을 쓴다. 여기서
     * 채번 접두를 다시 해석하면 채번 규칙의 두 번째 출처가 된다(접두는 운영자가 바꿀 수 있다).
     *
     * 취소(`cancel`)도 같은 상세 화면으로 열린다 — 주문상세 클레임 내역과 클레임 목록 취소 탭의
     * 번호가 같은 화면으로 이어져야 하기 때문이다(조회 전용, `findForDetail()` 참조).
     *
     * @param  string  $claimNumber  클레임 번호
     * @return array{type: ClaimTypeEnum, claim: OrderExchange|OrderReturn|OrderRefund|OrderCancel}|null 해석 결과 (없으면 null)
     */
    public function findForDetailByNumber(string $claimNumber): ?array
    {
        $resolved = $this->userClaimService->resolveByNumber($claimNumber);

        if ($resolved === null) {
            return null;
        }

        $claim = $this->findForDetail($resolved['type'], (int) $resolved['claim']->getKey());

        return $claim === null ? null : ['type' => $resolved['type'], 'claim' => $claim];
    }

    /**
     * 신청서 한 건의 활동 로그를 조회합니다. (신청서 + 추가결제 + 첨부)
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @param  array{per_page?: int, sort_order?: string}  $filters  조회 필터
     * @return LengthAwarePaginator 활동 로그
     */
    public function activityLogs(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim, array $filters = []): LengthAwarePaginator
    {
        return $this->activityLogRepository->paginateForClaim($claim, $filters);
    }

    /**
     * 진행단계 스텝퍼 배열을 만듭니다. (관리자 상세 응답)
     *
     * 배열의 규칙(결제 칸 추림·이탈 칸·도달 지점)은 `ClaimProgressSteps` 가 소유한다 —
     * 회원·비회원 상세가 같은 배열을 받으므로 여기서는 위임만 한다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return array<int, array{value: string, label: string, state: string, icon: string, variant: string}> 진행 단계
     */
    public function progressSteps(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): array
    {
        return ClaimProgressSteps::for($claim);
    }

    /**
     * 재발송지가 주문 배송지와 다른지 판정합니다. (D14 — '배송지 다름' 뱃지)
     *
     * 교환이 아닌 유형에는 재발송 축이 없어 `null` 이다 — `false`(같다)와 구분해야 화면이
     * "비교할 것이 없다" 와 "비교했더니 같다" 를 뒤섞지 않는다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return bool|null 다르면 true, 같으면 false, 재발송 축이 없으면 null
     */
    public function redeliveryAddressDiffers(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): ?bool
    {
        return $claim instanceof OrderExchange
            ? $this->exchangeService->redeliveryAddressDiffersFromOrder($claim)
            : null;
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

            // §4-5-2: 승인 시도 → 미결제면 결제대기 + 결제 요청. 결제 확정이 승인을 잇는다.
            if ($this->divertApprovalToPayment($claim, $target, $processedBy)) {
                return ['claim' => $this->reload($claim), 'payment_requested' => true];
            }
        }

        // 종결 전이와 추가결제 환급은 한 트랜잭션이다(S6 · D2) — 환급이 PG 에서 거절되면 반려·철회도 되돌려 운영자가
        // 다시 시도할 수 있게 한다. 예전에는 전이를 먼저 커밋해, 환급이 실패하면 신청서는 종결로 굳고 낸 돈을 돌려줄
        // 자리가 사라졌다. 환급(PG 호출)은 트랜잭션의 마지막 단계다.
        return DB::transaction(fn () => $this->transitionAndCloseMoney($claim, $target, $context));
    }

    /**
     * 신청서를 전이하고, 종결이면 추가결제를 정리합니다. (`transition()` 의 트랜잭션 본문 — S6)
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  string  $target  목표 상태 값
     * @param  array  $context  부가 정보
     * @return array{claim: OrderExchange|OrderReturn|OrderRefund, payment_requested: bool} 전이 결과
     */
    protected function transitionAndCloseMoney(OrderExchange|OrderReturn|OrderRefund $claim, string $target, array $context): array
    {
        $updated = match (true) {
            $claim instanceof OrderExchange => $this->exchangeService->transition($claim, ExchangeStatusEnum::from($target), $context),
            $claim instanceof OrderReturn => $this->returnService->transition($claim, ReturnStatusEnum::from($target), $context),
            default => $this->refundRequestService->transition($claim, RefundStatusEnum::from($target), $context),
        };

        // **종결은 돈을 닫는 자리다.** 철회(`UserClaimService::withdraw()`)는 종결 직후 같은 정리를
        // 부른다 — 대기 건을 취소하고 낸 돈을 환급한다. 반려도 같은 종결인데 그 정리를 빠뜨리면
        // **이미 반려된 신청서에 구매자가 돈을 낼 수 있다**: 결제 가드가 보는 것은 결제행의
        // `isPending()` 뿐이고 신청서 상태가 아니다(`ClaimPaymentService::pay():250`). 그렇게 들어온
        // 돈은 되돌릴 경로도 없다 — 반려는 이미 끝난 전이라 환급을 부를 자리가 남지 않는다.
        // (2026-09-22 #599 3차 실측 F-9: 반려 직후 구매자 상세에 [결제하기]가 그대로 서 있었고
        //  누르자 PG 결제창이 열렸다.)
        //
        // 고아 정리 배치도 이 정리를 전제로 적혀 있다 — `CancelOrphanClaimPaymentsCommand` 헤더의
        // 「종결 경로는 대기 건을 함께 취소하므로」. 여기서 하지 않으면 그 전제가 깨지고 배치의
        // 보존 기간 동안 결제가 열린 채로 남는다.
        if ($updated instanceof OrderExchange || $updated instanceof OrderReturn) {
            $this->closeMoneyIfTerminated($updated);
        }

        return ['claim' => $updated, 'payment_requested' => false];
    }

    /**
     * 미수납 청구가 남은 신청서의 승인 지시를 결제 절차로 돌립니다. (§4-5-2 — 헤더·상품 단위 공용)
     *
     * 신청서 단위 승인과 상품 단위 승인이 **같은 게이트**를 지나야 한다. 상품 행 승인이 이
     * 판정을 건너뛰면 헤더가 `payment_pending → approved` 로 따라가(`syncHeaderFromOptions`)
     * 돈을 받지 않은 채 승인된다(#599 validate P2 #1).
     *
     * - `pay_on_approval`: 결제 건을 만들고 결제대기로 옮긴다 — 결제 확정이 승인을 잇는다.
     * - `pay_on_request`: 결제 통로가 이미 열려 있으므로 `claim_payment_outstanding` 로 거절한다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  string  $target  목표 상태 값
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return bool 결제 요청으로 돌렸으면 true (승인 지시가 아니거나 미수납이 없으면 false)
     *
     * @throws OrderClaimException 신청 시점 결제의 미수납 승인
     */
    protected function divertApprovalToPayment(OrderExchange|OrderReturn $claim, string $target, ?int $processedBy): bool
    {
        if ($target !== 'approved'
            || ! $claim->requiresAdditionalPayment()
            || $this->paymentService->outstandingAmount($claim) <= 0) {
            return false;
        }

        if ($claim->charge_method === ClaimChargeMethodEnum::PAY_ON_APPROVAL) {
            $this->paymentService->requestPaymentOnApproval($claim, $processedBy);

            return true;
        }

        throw OrderClaimException::paymentOutstanding();
    }

    /**
     * 종결(반려·철회)된 신청서의 돈을 닫습니다. (대기 결제 취소 + 기납부 환급)
     *
     * **종결은 돈을 닫는 자리다.** 결제 가드가 보는 것은 결제행의 `isPending()` 뿐이고 신청서
     * 상태가 아니라(`ClaimPaymentService::pay()`), 종결 경로가 대기 건을 닫지 않으면 이미 끝난
     * 신청서에 구매자가 돈을 낼 수 있고 그 돈을 되돌릴 자리도 남지 않는다(#599 3차 실측 F-9).
     * 신청서 단위 반려와 상품 단위 전량 반려·철회가 모두 이 한 곳을 지난다(#599 validate P2 #2).
     * 고아 정리 배치(`CancelOrphanClaimPaymentsCommand`)도 이 정리를 전제로 적혀 있다.
     *
     * @param  OrderExchange|OrderReturn  $claim  전이된 신청서
     * @return void 반환값 없음
     */
    protected function closeMoneyIfTerminated(OrderExchange|OrderReturn $claim): void
    {
        $status = $claim instanceof OrderExchange ? $claim->exchange_status?->value : $claim->return_status?->value;

        $reasonKey = match ($status) {
            ExchangeStatusEnum::REJECTED->value => 'sirsoft-ecommerce::messages.claims.rejected',
            ExchangeStatusEnum::WITHDRAWN->value => 'sirsoft-ecommerce::messages.claims.withdrawn',
            default => null,
        };

        if ($reasonKey === null) {
            return;
        }

        $this->paymentService->refundPaymentsForClaim($claim, __($reasonKey));
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

        // 접수·결제대기를 지난 뒤(승인·회수 등)의 결제 요청은 전이 없이 차액 결제 대기 건만 만든다(S3) —
        // 결제대기로 되돌리면 진행 중인 회수·재발송 흐름이 끊긴다. 구매자 화면은 대기 건이 있으면 상태와
        // 무관하게 [추가 결제] 를 보인다.
        $status = $claim instanceof OrderExchange ? $claim->exchange_status : $claim->return_status;
        if (! in_array($status instanceof \BackedEnum ? $status->value : (string) $status, ['requested', 'payment_pending'], true)) {
            $this->paymentService->settleChargeDifference($claim);

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
     * 운영자에게 다른 결과다. 도메인 예외가 아닌 건별 실패도 skip 으로 보고한다.
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  array<int, int>  $ids  신청서 ID 목록
     * @param  string  $target  목표 상태 값
     * @param  array  $context  부가 정보 (processed_by / reject_reason / admin_memo)
     * @param  ClaimCompletionOptions|null  $completion  완료 처리 옵션 (완료 전이일 때만)
     * @param  array<int, array{carrier_id: int, tracking_number: string}>  $trackings  신청서별 송장 (회수중·발송중일 때 — 송장은 신청서마다 다르다)
     * @return array{succeeded: array<int, int>, payment_requested: array<int, int>, skipped: array<int, array{id: int, claim_number: string|null, message: string}>} 전이 결과
     */
    public function bulkTransition(
        ClaimTypeEnum $type,
        array $ids,
        string $target,
        array $context = [],
        ?ClaimCompletionOptions $completion = null,
        array $trackings = [],
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
                    $result = $this->transition($claim, $target, $context + ($trackings[$claim->id] ?? []));

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
            } catch (\Throwable $e) {
                // 부분 성공 계약은 **예상하지 못한 실패**에도 적용된다. 반려 직후의 환급(PG 호출)이나
                // 리스너 장애처럼 도메인 예외가 아닌 실패가 한 건에서 나면, 예전에는 그 한 건이
                // 요청 전체를 500 으로 끊어 앞서 처리된 건의 결과까지 운영자가 알 수 없었다
                // (#599 validate P2 #11). 원인은 로그에 남기고 응답에는 원문을 싣지 않는다.
                Log::error('[claim.bulk] 일괄 전이 중 건별 처리 실패 — skip 으로 보고', [
                    'claim_id' => $claim->id,
                    'claim_number' => $this->claimNumberOf($claim),
                    'target' => $target,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                $skipped[] = [
                    'id' => $claim->id,
                    'claim_number' => $this->claimNumberOf($claim),
                    'message' => __('sirsoft-ecommerce::exceptions.claim_bulk_item_failed'),
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
     * 환불신청서의 완료는 환불 실행(S6b)의 소유라 여기서 거절한다 — 열어 두면 돈이
     * 나가지 않은 채 상품 행만 환불완료로 보인다. 승인·반려가 갈린 뒤에는 신청서 환불액을
     * 승인 범위에 맞춰 다시 계산한다(§4-5).
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  array<int, int|array{id: int, quantity?: int|null}>  $items  전이 지시
     * @param  string  $target  목표 상태 값
     * @param  array  $context  부가 정보 (processed_by)
     * @param  ClaimCompletionOptions|null  $completion  완료 처리 옵션 (완료 전이일 때만)
     * @return array{succeeded: array<int, int>, skipped: array<int, array{id: int, message: string}>, split?: array<int, array{id: int, quantity: int, remainder_id: int, remainder_quantity: int}>, payment_requested?: bool} 전이 결과 (승인이 결제 요청으로 우회되면 `payment_requested: true`)
     *
     * @throws OrderClaimException 완료 가능 상품 부재 / 환불 완료 지시 / 신청 시점 결제의 미수납 승인 등
     */
    public function transitionOptions(
        OrderExchange|OrderReturn|OrderRefund $claim,
        array $items,
        string $target,
        array $context = [],
        ?ClaimCompletionOptions $completion = null,
    ): array {
        if ($claim instanceof OrderRefund) {
            if ($target === 'completed') {
                throw OrderClaimException::completionRequiresCompletionFlow();
            }

            $result = $this->refundRequestService->transitionOption(
                $claim,
                $items,
                RefundStatusEnum::from($target),
                $context,
            );

            $result = $this->recalculateRefundAmounts($claim, $result, $context['processed_by'] ?? null);
            $this->announceItemsTransitioned($claim, $result, $target);

            return $result;
        }

        $itemIds = ClaimItemSpecs::ids(ClaimItemSpecs::normalize($items));

        if ($target !== 'completed') {
            // 상품 단위 승인도 신청서 단위 승인과 같은 미수납 게이트를 지난다(#1).
            if ($this->divertApprovalToPayment($claim, $target, $context['processed_by'] ?? null)) {
                return ['succeeded' => [], 'skipped' => [], 'split' => [], 'payment_requested' => true];
            }

            $wasFinal = $claim->isFinal();

            // 상품 단위 전량 반려·철회로 헤더가 종결되면 헤더 경로와 같이 돈을 닫는다(#2). 전이와 환급은 한
            // 트랜잭션이다(S6) — 환급이 거절되면 상품 단위 전이도 되돌린다.
            [$result, $reloaded] = DB::transaction(function () use ($claim, $items, $target, $context, $wasFinal) {
                $result = $claim instanceof OrderExchange
                    ? $this->exchangeService->transitionOption($claim, $items, ExchangeStatusEnum::from($target), $context)
                    : $this->returnService->transitionOption($claim, $items, ReturnStatusEnum::from($target), $context);

                $reloaded = $this->reload($claim);

                if (! $wasFinal && $reloaded->isFinal()) {
                    $this->closeMoneyIfTerminated($reloaded);
                }

                return [$result, $reloaded];
            });

            // 헤더가 종결된 경우의 돈은 위 트랜잭션에서 닫았다. 종결되지 않은 일부 반려·철회만 아래에서 다시 정산한다.
            if (($wasFinal || ! $reloaded->isFinal())
                && $reloaded instanceof OrderExchange
                && in_array($target, [ExchangeStatusEnum::REJECTED->value, ExchangeStatusEnum::WITHDRAWN->value], true)
                && ($result['succeeded'] ?? []) !== []) {
                // 일부만 반려·철회됐으면 청구액을 살아 있는 상품 기준으로 다시 계산하고, 결제 대기 건·
                // 기수납액을 그 값에 맞춘다(S5) — 갱신과 정산은 한 트랜잭션이다(금액 수정과 같은 규칙).
                DB::transaction(function () use ($reloaded) {
                    $repriced = $this->exchangeService->recalculateChargeForLivingItems($reloaded);

                    if ($repriced !== null) {
                        $this->paymentService->settleChargeDifference($repriced, __('sirsoft-ecommerce::messages.claims.charge_repriced_after_partial_rejection'));
                    }
                });
            }

            $this->announceItemsTransitioned($claim, $result, $target);

            return $result;
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

        $result = ['succeeded' => $succeeded, 'skipped' => $skipped];

        $this->announceItemsTransitioned($claim, $result, $target);

        return $result;
    }

    /**
     * 상품 단위 전이가 끝났다는 **사실**을 알립니다.
     *
     * 여기서 「고객에게 알릴 것인가」를 정하지 않는다. 그 판정은 알림 축
     * (`ClaimStatusNotificationListener`)이 단독으로 한다 — 서비스가 함께 정하면 발화 규칙이
     * 두 곳에 생기고, 「전건이 같은 결정이면 보내지 않는다」 같은 규칙이 바뀔 때 한쪽만
     * 고쳐진 채 남는다. 서비스는 무슨 일이 있었는지만 말한다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  array{succeeded?: array<int, int>, skipped?: array<int, mixed>}  $result  전이 결과
     * @param  string  $target  이번 라운드의 목표 상태
     * @return void 반환값 없음
     */
    protected function announceItemsTransitioned(
        OrderExchange|OrderReturn|OrderRefund $claim,
        array $result,
        string $target,
    ): void {
        $this->fireAfterCommit(fn () => HookManager::doAction(ClaimNotificationHooks::ITEMS_TRANSITIONED, $claim, $result, $target));
    }

    /**
     * 상품 단위 판단 뒤 환불신청서 금액을 다시 계산합니다.
     *
     * 승인분 기준으로 자동 재계산하되, 승인 이후에도 운영자가 금액을 고쳐 실제 환불을
     * 집행할 수 있어야 하므로 기존 `PUT .../amounts` 경로는 그대로 둔다.
     *
     * **헤더가 종결·처리중이면 건드리지 않는다.** 종결은 이미 끝난 사실이고, 처리중은 PG 에
     * 요청이 나가 있는 구간이라 금액이 바뀌면 요청액과 기록액이 갈린다. 둘 다 `updateAmounts()`
     * 가 스스로 거절하지만, 거기까지 가면 **전이는 성공했는데 응답이 422** 가 되어 운영자는
     * 무엇이 반영됐는지 알 수 없다.
     *
     * 재계산 자체는 기존 저장 경로(`updateAmounts()`)를 그대로 타므로 전/후 스냅샷·활동로그·
     * D19 가드·다통화 환산이 코드 추가 없이 붙는다.
     *
     * @param  OrderRefund  $claim  환불신청서
     * @param  array{succeeded: array<int, int>, skipped: array<int, array{id: int, message: string}>, split: array<int, array<string, int>>}  $result  전이 결과
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return array{succeeded: array<int, int>, skipped: array<int, array{id: int, message: string}>, split: array<int, array<string, int>>} 전이 결과 (그대로)
     */
    protected function recalculateRefundAmounts(OrderRefund $claim, array $result, ?int $processedBy): array
    {
        if ($result['succeeded'] === []) {
            return $result;
        }

        $fresh = $claim->fresh(['refundOptions']);

        if (! $fresh instanceof OrderRefund) {
            return $result;
        }

        if ($fresh->refund_status === RefundStatusEnum::PROCESSING || $fresh->refund_status->isFinal()) {
            return $result;
        }

        $this->refundRequestService->recalculateAmountForApprovedItems(
            $fresh,
            $result['succeeded'],
            $processedBy,
        );

        return $result;
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

        // 받아야 할 청구액이 남았으면 완료하지 않는다(S3) — 운영자가 수납 뒤 청구액을 올린 건이 미수납인 채 완료되면
        // 그 돈은 어디에도 청구되지 않는다. 차감 방식·청구 0원은 대상이 아니다. 승인 게이트
        // (`divertApprovalToPayment()`)와 같은 층·같은 판정이다 — 운영자 지시의 입구에서 막는다.
        if ($claim->requiresAdditionalPayment() && $this->paymentService->outstandingAmount($claim) > 0) {
            throw OrderClaimException::paymentOutstanding();
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
     * 금액 갱신과 청구 정산(`settleChargeDifference()`)은 **한 트랜잭션**이다(#599 validate P2 #12).
     * 정산이 실패하면(결제 수단 해석 불가 등) 금액도 함께 되돌아간다 — 예전에는 금액만 바뀐 채
     * 남아 화면은 미수납을 적는데 낼 자리가 없었다. 수정 훅은 커밋 뒤에 나간다.
     *
     * @throws OrderClaimException 수정 불가 상태·금액·청구 방식·D19 상한 위반 / 기납부 청구의 차감 전환
     */
    public function updateAmounts(
        OrderExchange|OrderReturn|OrderRefund $claim,
        array $amounts,
        ?int $processedBy = null,
    ): OrderExchange|OrderReturn|OrderRefund {
        return DB::transaction(fn () => $this->applyAmountUpdate($claim, $amounts, $processedBy));
    }

    /**
     * 금액 수정의 본체입니다. (`updateAmounts()` 의 트랜잭션 안에서만 부른다)
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  array  $amounts  수정값 (유형별 FormRequest 가 게이트한 키만)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderExchange|OrderReturn|OrderRefund 갱신된 신청서
     *
     * @throws OrderClaimException 수정 불가 상태·금액·청구 방식·D19 상한 위반 / 기납부 청구의 차감 전환
     */
    protected function applyAmountUpdate(
        OrderExchange|OrderReturn|OrderRefund $claim,
        array $amounts,
        ?int $processedBy,
    ): OrderExchange|OrderReturn|OrderRefund {
        // 이미 낸 청구를 환불금 차감으로 바꾸면 같은 돈을 두 번 받는다(#13).
        if (($claim instanceof OrderExchange || $claim instanceof OrderReturn) && array_key_exists('charge_method', $amounts)) {
            $this->userClaimService->assertChargeMethodChangeAllowed($claim, $amounts['charge_method']);
        }

        $before = $this->amountSnapshot($claim);

        $updated = match (true) {
            $claim instanceof OrderExchange => $this->exchangeService->updateAmounts($claim, $amounts, $processedBy),
            $claim instanceof OrderReturn => $this->returnService->updateAmounts($claim, $amounts, $processedBy),
            default => $this->refundRequestService->updateAmounts($claim, $amounts, $processedBy),
        };

        $after = $this->amountSnapshot($updated);

        // **아직 한 푼도 내지 않은 청구는 고친 금액을 따라가야 한다.**
        //
        // 예전에는 금액 컬럼만 갱신하고 결제행을 그대로 뒀다. 그래서 운영자가 회수비 3,000원을
        // 0원으로 낮춰도 구매자 화면에는 3,000원 결제 버튼이 남아 **안 받기로 한 돈을 받을 수**
        // 있었고, 반대로 청구 0원 신청서를 4,000원으로 올리면 화면은 「미수납 4,000원」이라 적는데
        // 결제행이 없어 **낼 자리가 없었다**(2026-09-22 #599 3차 실측 L-6·L-7).
        //
        // **이미 결제한 건은 건드리지 않는다**(운영 결정 2026-09-23). 초과 수납이 생겨도 자동
        // 환급하지 않고 운영이 처리한다 — 관리자 금액 수정에 자동 환급을 하기로 한 합의가 없고
        // (`modules/_bundled/sirsoft-ecommerce/docs/api/admin-claims.md` 의 `updateAmounts` 응답에 환급 기술이 없다), 운영자가 금액을
        // 고치는 것은 대개 운영상의 판단이기 때문이다.
        //
        // 그래서 수납액이 0 이거나, 이미 받은 뒤 **청구액을 올린** 경우에만 부른다(S3). 두 조건 모두
        // `settleChargeDifference()` 의 차액이 음수가 될 수 없어 환급 경로(`refundDifference()`)를 구조적으로
        // 타지 않는다 — 금액을 내리면 대기 건이 갱신·취소되고, 올리면 그 차액으로 만들어진다. 종전에는 수납액이
        // 0 일 때만 불러, 3,000원을 낸 뒤 5,000원으로 올리면 2,000원을 낼 자리가 생기지 않았다.
        if ($updated instanceof OrderExchange || $updated instanceof OrderReturn) {
            $paid = $this->paymentService->paidAmount($updated);

            if ($paid <= 0.0 || (float) $updated->total_charge_amount > $paid) {
                $this->paymentService->settleChargeDifference($updated);
            } else {
                // 받은 뒤 이미 낸 금액 이하로 내렸다 — 환급은 하지 않지만(위 결정), 올릴 때 만든 차액 결제 대기 건은
                // 더는 받을 돈이 아니다. 남겨 두면 구매자·운영자 화면에 「결제대기」 로 남는다(S1-D13).
                $this->paymentService->cancelPendingPayments($updated);
            }
        }

        if ($before !== $after) {
            $hookType = match (true) {
                $claim instanceof OrderExchange => ClaimTypeEnum::EXCHANGE->value,
                $claim instanceof OrderReturn => ClaimTypeEnum::RETURN->value,
                default => ClaimTypeEnum::REFUND->value,
            };

            $this->fireAfterCommit(fn () => HookManager::doAction(
                "sirsoft-ecommerce.claim.{$hookType}.amounts_updated",
                $updated,
                $before,
                $after,
                $processedBy,
            ));
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
     * 취소 가능 금액 산정 결과를 화면 응답용 배열로 만듭니다. (D19 근거 표)
     *
     * 표기 문자열은 여기서만 만든다 — 화면이 통화 기호·자릿수를 조립하면 기본 통화가 원이
     * 아닌 상점에서 값은 맞고 단위만 틀린 금액이 나가고, 두 소비처(환불 상세의 근거 표 ·
     * 주문상세 환불 모달)가 각자 조립하면 같은 숫자가 화면마다 다르게 보인다.
     *
     * 자릿수는 거래 시점 스냅샷이 SSoT 다 — 수단별 잔여의 결제 통화 표기(`remaining_local`)도
     * 같은 스냅샷으로 포맷한다(스냅샷은 통화별 자릿수를 담는다).
     *
     * @param  CancellableAmount  $cancellable  산정 결과
     * @return array 화면 응답용 배열 (원시값 + `*_formatted` + 한도 축 라벨)
     */
    public function cancellablePayload(CancellableAmount $cancellable): array
    {
        $snapshot = $cancellable->currencySnapshot;
        $base = $cancellable->currency ?: $this->currencyService->getDefaultCurrency();
        $format = fn (float $amount, ?string $code = null): string => $this->currencyService->formatPrice(
            $amount,
            $code ?: $base,
            $snapshot,
        );

        $payload = $cancellable->toArray();

        $payload['amount_formatted'] = $format($cancellable->amount);
        $payload['domain_refundable_formatted'] = $format($cancellable->domainRefundable);
        $payload['payment_capacity_formatted'] = $format($cancellable->paymentCapacity);
        $payload['reserved_formatted'] = $format($cancellable->reserved);
        // 라벨 키는 백엔드 네임스페이스라 레이아웃이 해석할 수 없다 — 표시 문자열을 함께 싣는다.
        $payload['binding_label'] = __($cancellable->bindingLabelKey());

        $payload['methods'] = array_map(function (array $method) use ($format, $base): array {
            $code = (string) ($method['currency'] ?? $base);

            return $method + [
                'method_label' => $this->paymentMethodLabel($method),
                'remaining_base_formatted' => $format((float) ($method['remaining_base'] ?? 0)),
                'remaining_local_formatted' => $format((float) ($method['remaining_local'] ?? 0), $code),
            ];
        }, $cancellable->methods);

        $payload['reserved_claims'] = array_map(fn (array $claim): array => $claim + [
            'amount_formatted' => $format((float) ($claim['amount'] ?? 0)),
            'type_label' => ClaimTypeEnum::tryFrom((string) ($claim['type'] ?? ''))?->label()
                ?? (string) ($claim['type'] ?? ''),
        ], $cancellable->reservedClaims);

        return $payload;
    }

    /**
     * 수단별 잔여 항목의 표시 라벨을 해석합니다.
     *
     * 마일리지는 결제행이 없는 주문 컬럼 원장이라 결제수단 어휘에 없다 — 그 항목만 별도
     * 라벨을 쓴다(해석 실패로 `mileage` 원시값이 화면에 그대로 나가지 않게).
     *
     * @param  array  $method  수단별 잔여 항목
     * @return string 표시 라벨
     */
    protected function paymentMethodLabel(array $method): string
    {
        if (($method['type'] ?? null) === CancellableAmount::METHOD_MILEAGE) {
            return __('sirsoft-ecommerce::messages.claims.cancellable_method.mileage');
        }

        if (($method['type'] ?? null) === CancellableAmount::METHOD_REACTIVATED_CASH) {
            return __('sirsoft-ecommerce::messages.claims.cancellable_method.reactivated_cash');
        }

        $value = (string) ($method['method'] ?? '');

        // 라벨 해석의 SSoT 는 결제수단 카탈로그다 — PG 플러그인이 등록하는 확장 수단은
        // `PaymentMethodEnum` 에 case 가 없어(OrderPayment 의 캐스트 미부여 사유와 같은 축)
        // enum 만 보면 원시 ID 가 그대로 화면에 나가고, 같은 화면의 결제 카드는 카탈로그를
        // 거치므로 한 수단이 두 자리에서 다른 말로 불린다.
        return $this->paymentMethodResolver->label($value);
    }

    /**
     * 청구 방식 선택지를 반환합니다. (D18 — 상세 화면 금액 수정)
     *
     * 허용 목록은 운영자 설정(`claim.allowed_charge_methods`)이 정하는데 그 설정 조회는
     * `settings.read` 게이트다 — 클레임 권한만 가진 운영자가 화면에서 직접 부르면 403 이
     * 되어 셀렉트가 빈 채로 열리고 청구 방식을 고를 수 없다. 상세 봉투가 그 통로다.
     *
     * @return array<int, array{value: string, label: string}> 선택지
     */
    public function chargeMethodOptions(): array
    {
        $allowed = (array) $this->settingsService->getSetting(
            'claim.allowed_charge_methods',
            ClaimChargeMethodEnum::values()
        );

        return array_values(array_map(
            fn (ClaimChargeMethodEnum $case): array => ['value' => $case->value, 'label' => $case->label()],
            array_filter(
                ClaimChargeMethodEnum::cases(),
                fn (ClaimChargeMethodEnum $case): bool => in_array($case->value, $allowed, true)
            )
        ));
    }

    /**
     * 환불 수단 선택지를 반환합니다. (D18 — 환불 실행 섹션)
     *
     * 어휘는 `RefundMethodEnum` 이 소유한다 — 화면이 셋을 다시 적으면 수단이 늘어도 화면만
     * 낡은 채로 남는다.
     *
     * 「마일리지」는 비회원 주문과, 마일리지 기능이 꺼진 상점의 현금 환불에서 뺀다(D1·Q21 —
     * `OrderRefundRequestService::pointsRefundAllowed()` 와 같은 판정).
     *
     * @param  Order|null  $order  주문 (null 이면 종전처럼 전 수단)
     * @param  float|null  $cashAmount  이 환불의 현금 환불액 (null 이면 현금이 있는 것으로 본다)
     * @return array<int, array{value: string, label: string}> 선택지
     */
    public function refundMethodOptions(?Order $order = null, ?float $cashAmount = null): array
    {
        $cases = RefundMethodEnum::cases();

        // S1-D15: 주문을 받으면 그 결제수단이 쓸 수 없는 수단은 뺀다. 고를 수 있게
        // 보여 두고 저장에서만 거절하면 운영자는 될 줄 알고 고르다가 뒤늦게 막힌다.
        // 서버 가드(`OrderRefundRequestService::assertRefundMethodAvailable()`)는 그대로 둔다 —
        // 화면을 거치지 않는 호출도 같은 거절을 받아야 한다. PG 가능 여부는 저장·실행 게이트와
        // 같은 판정(`pgRefundAvailable()` — PG 리스너가 없어 계좌로 떨어지는 주문 포함)을 읽는다.
        if ($order !== null) {
            $hasPg = $this->refundRequestService->pgRefundAvailable($order);
            // 현금액을 모르면 현금이 있는 것으로 본다 — 기능이 꺼진 상점에서 보여 두고 실행에서 막히면 늦다.
            $hasPoints = $this->refundRequestService->pointsRefundAllowed($order, $cashAmount ?? 1.0);

            $cases = array_filter(
                $cases,
                fn (RefundMethodEnum $case): bool => match ($case) {
                    RefundMethodEnum::PG => $hasPg,
                    RefundMethodEnum::POINTS => $hasPoints,
                    default => true,
                }
            );
        }

        return array_values(array_map(
            fn (RefundMethodEnum $case): array => ['value' => $case->value, 'label' => $case->label()],
            $cases
        ));
    }

    /**
     * 신청서의 '이 클레임의 정산' 블록 데이터를 만듭니다. (§6-4 `#section_payment_info`)
     *
     * 교환·반품의 환불 예정액은 헤더에 컬럼이 없다 — 완료 시점의 주문 재계산 결과라 저장된
     * 값이 아니다. 그 값의 SSoT 는 완료 미리보기 경로(`previewCompletion` /
     * `previewRefundRequest`)이고, 완료 처리 모달이 보여주는 숫자와 같은 곳에서 나와야
     * 상세와 모달이 같은 신청서에 대해 서로 다른 금액을 말하지 않는다.
     *
     * 환불신청서는 자기 금액이 헤더 컬럼이므로(`refund_amount` 등) 이 블록이 필요 없다 —
     * `null` 을 돌려주어 화면이 그 자리를 그리지 않게 한다. 취소도 같다 — 환불은 이미 실행된
     * 값이라 예고할 것이 없고, 그 금액은 취소 발 환불 행이 헤더에 싣는다.
     *
     * 단건 상세 전용이다 — 목록 응답의 행마다 부르면 주문 재계산이 행 수만큼 돈다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return array{refund_amount: float, refund_amount_formatted: string, refund_points_amount: float, refund_points_amount_formatted: string, deducted_shipping_fee: float, deducted_shipping_fee_formatted: string, refund_product_amount: float|null, refund_product_amount_formatted: string|null, refund_shipping_amount: float|null, refund_shipping_amount_formatted: string|null, blocked_reason: string|null, already_filed: bool, earn_recovery: bool, currency_code: string}|null 정산 근거 (환불신청서는 null)
     */
    public function settlementFor(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): ?array
    {
        if ($claim instanceof OrderRefund || $claim instanceof OrderCancel) {
            return null;
        }

        $claim->loadMissing('order');

        // 완료 가정 상품을 먼저 정한다 — 이 블록은 '지금 완료 처리하면' 을 예고하는 자리이고,
        // 완료 처리 모달도 같은 목록을 넘겨 부른다. 교환에서 이 인자를 빼면 후보가 이미 완료된
        // 상품뿐이라, 아직 완료 건이 없는 신청서의 예정액이 0 원 + '환불 대상 없음' 으로 나가는데
        // 같은 신청서의 모달은 실제 차액을 예고한다 (반품은 previewCompletion 이 스스로 전량을
        // 대상으로 삼아 이 갈림이 없다).
        $completableIds = $claim instanceof OrderReturn
            ? []
            : $this->completableExchangeItemIds($claim, []);

        $preview = $claim instanceof OrderReturn
            ? $this->returnService->previewCompletion($claim)
            : $this->exchangeService->previewRefundRequest($claim, $completableIds);

        // 표기 통화·자릿수는 거래 시점에 동결된 값이다 (완료 미리보기와 같은 규칙).
        $currency = $claim->currency_code ?: $this->orderBaseCurrencyOf($claim->order);
        $snapshot = is_array($claim->order?->currency_snapshot) ? $claim->order->currency_snapshot : null;
        $format = fn (float $amount): string => $this->currencyService->formatPrice($amount, $currency, $snapshot);

        if ($claim instanceof OrderReturn) {
            $completableIds = $preview['completable_item_ids'];
        }

        return [
            'refund_amount' => (float) $preview['refund_amount'],
            'refund_amount_formatted' => $format((float) $preview['refund_amount']),
            'refund_points_amount' => (float) $preview['refund_points_amount'],
            'refund_points_amount_formatted' => $format((float) $preview['refund_points_amount']),
            'deducted_shipping_fee' => (float) $preview['deducted_shipping_fee'],
            'deducted_shipping_fee_formatted' => $format((float) $preview['deducted_shipping_fee']),
            // 반품은 환불 예정액의 가산 두 축을 함께 낸다 — 합계와 차감만 있으면 산식이 닫히지
            // 않아 운영자가 그 금액의 근거를 화면에서 읽을 수 없다. 교환은 옵션 차액이 이미
            // 별도 행이라 그 축이 없다(null 로 두어 화면이 그 자리를 그리지 않게 한다).
            'refund_product_amount' => isset($preview['refund_product_amount'])
                ? (float) $preview['refund_product_amount'] : null,
            'refund_product_amount_formatted' => isset($preview['refund_product_amount'])
                ? $format((float) $preview['refund_product_amount']) : null,
            'refund_shipping_amount' => isset($preview['refund_shipping_amount'])
                ? (float) $preview['refund_shipping_amount'] : null,
            'refund_shipping_amount_formatted' => isset($preview['refund_shipping_amount'])
                ? $format((float) $preview['refund_shipping_amount']) : null,
            'blocked_reason' => $preview['blocked_reason'],
            'already_filed' => (bool) ($preview['already_filed']
                ?? ($preview['blocked_reason'] === 'claim_refund_request_exists')),
            // 적립 차감 예고는 완료 미리보기와 같은 판정이다 (`dropsPendingEarn()`).
            'earn_recovery' => $this->dropsPendingEarn($claim, $completableIds),
            'currency_code' => $currency,
        ];
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
     *                               선교환 「미회수로 완료」 로 고른 행(`unreturned_item_ids`)은 실행(`completeExchange`)과 같게
     *                               완료 대상에 넣고 재고 복구 수에서는 뺀다 — 후보(`unreturnedEligibleItems`)가 아닌 ID 는 버린다.
     * @param  array<int, array{id: int, item_ids?: array<int, int>, restore_stock_by_item?: array<int|string, bool>, unreturned_item_ids?: array<int, int>}>  $claims  대상 신청서 목록
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
            /** @var array<int, int> $unreturnedIds 미회수로 완료할 행 (교환만 — 반품은 늘 빈 목록) */
            $unreturnedIds = [];

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

                // 미회수로 완료할 행 — 실행과 같이 완료 대상에 들고, 재고는 되돌리지 않는다(D3).
                $eligibleUnreturned = array_column($this->unreturnedEligibleItems($claim, $itemIds), 'id');
                $unreturnedIds = array_values(array_intersect(
                    array_map('intval', (array) ($entry['unreturned_item_ids'] ?? [])),
                    $eligibleUnreturned,
                ));
                $completableIds = array_values(array_unique(array_merge($completableIds, $unreturnedIds)));

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
                fn (int $itemId) => ! in_array($itemId, $unreturnedIds, true) && $completion->shouldRestoreStock($itemId),
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
                'earn_recovery' => $this->dropsPendingEarn($claim, $completableIds),
                'coupon_restore' => $couponRestore,
                'refund' => $refund,
                // 선교환 「미회수로 완료」 후보(D3) — 모달이 행마다 체크·사유 칸을 그린다.
                'unreturned_eligible_items' => $this->unreturnedEligibleItems($claim, $itemIds),
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
     * 참이다. 판정 규칙은 클레임 서비스 공용 헬퍼
     * (`CurrencyConversionService::resolveOrderBaseCurrency()`) 하나가 소유한다(#599 validate P2 #14).
     *
     * @param  Order|null  $order  주문
     * @return string 기준 통화 코드
     */
    protected function orderBaseCurrencyOf(?Order $order): string
    {
        return $this->currencyService->resolveOrderBaseCurrency($order);
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

            if ($this->exchangeService->isCompletableItem($item)) {
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
    // 송장 (상품 행) · 진행 방식
    // ───────────────────────────────────────────────

    /**
     * 「송장만 저장」 — 고른 상품 행(수량 지정 시 쪼갠 행)에 한 방향 송장을 적습니다. (상태 불변 — R3·R4·R7)
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  ClaimTrackingDirectionEnum  $direction  방향
     * @param  array<int, array{id: int, quantity?: int|null}>  $items  대상 행 지시
     * @param  int|null  $carrierId  택배사 ID (비우면 삭제)
     * @param  string|null  $trackingNumber  운송장 번호 (비우면 삭제)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return array{claim: OrderExchange|OrderReturn, split: array<int, array<string, int>>, changes: array<int, array<string, mixed>>} 결과
     *
     * @throws OrderClaimException R1·R2·R5·R7·R8 위반 / 신청서에 없는 행
     */
    public function saveItemTracking(
        OrderExchange|OrderReturn $claim,
        ClaimTrackingDirectionEnum $direction,
        array $items,
        ?int $carrierId,
        ?string $trackingNumber,
        ?int $processedBy = null,
    ): array {
        return $claim instanceof OrderExchange
            ? $this->exchangeService->writeItemTracking($claim, $direction, $items, $carrierId, $trackingNumber, $processedBy)
            : $this->returnService->writeItemTracking($claim, $direction, $items, $carrierId, $trackingNumber, $processedBy);
    }

    /**
     * 운송장 한 개의 행을 한꺼번에 도착 처리합니다. (배송 정보 탭 [도착 처리] — R9)
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  ClaimTrackingDirectionEnum  $direction  방향
     * @param  int  $carrierId  택배사 ID
     * @param  string  $trackingNumber  운송장 번호
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return array{claim: OrderExchange|OrderReturn, succeeded: array<int, int>, skipped: array<int, array{id: int, message: string}>} 처리 결과
     *
     * @throws OrderClaimException 그 운송장의 행 없음 / 반품의 재발송
     */
    public function deliverTracking(
        OrderExchange|OrderReturn $claim,
        ClaimTrackingDirectionEnum $direction,
        int $carrierId,
        string $trackingNumber,
        ?int $processedBy = null,
    ): array {
        return $claim instanceof OrderExchange
            ? $this->exchangeService->deliverTracking($claim, $direction, $carrierId, $trackingNumber, $processedBy)
            : $this->returnService->deliverTracking($claim, $direction, $carrierId, $trackingNumber, $processedBy);
    }

    /**
     * 교환의 진행 방식(일반·선교환)을 바꿉니다. (D1)
     *
     * @param  OrderExchange  $claim  교환 신청서
     * @param  ExchangeModeEnum  $mode  바꿀 방식
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderExchange 갱신된 신청서
     *
     * @throws OrderClaimException 설정 꺼짐 / 바꿀 수 없는 단계 / 회수 송장이 있는 선교환
     */
    public function setExchangeMode(OrderExchange $claim, ExchangeModeEnum $mode, ?int $processedBy = null): OrderExchange
    {
        return $this->exchangeService->setExchangeMode($claim, $mode, $processedBy);
    }

    /**
     * 상세 「진행 방식」 줄의 버튼 게이트를 만듭니다. (`setExchangeMode()` 와 같은 판정)
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return array{can_set_advance: bool, can_set_standard: bool, blocked_reason: string|null, advance_enabled: bool, collection_due_days: int}|null 게이트 (교환이 아니면 null)
     */
    public function exchangeModeAbilities(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): ?array
    {
        if (! $claim instanceof OrderExchange) {
            return null;
        }

        $target = $claim->mode() === ExchangeModeEnum::ADVANCE ? ExchangeModeEnum::STANDARD : ExchangeModeEnum::ADVANCE;
        $blocked = $this->exchangeService->exchangeModeBlockReason($claim, $target);

        return [
            'can_set_advance' => $target === ExchangeModeEnum::ADVANCE && $blocked === null,
            'can_set_standard' => $target === ExchangeModeEnum::STANDARD && $blocked === null,
            'blocked_reason' => $blocked === null ? null : __('sirsoft-ecommerce::exceptions.'.$blocked),
            'advance_enabled' => $this->exchangeService->advanceExchangeEnabled(),
            // 실제 기한 계산과 같은 출처다 — 한계 밖 값이 저장돼 있으면 서비스가 기본값으로 돌리므로 안내도 그 값을 쓴다.
            'collection_due_days' => $this->exchangeService->advanceExchangeDueDays(),
        ];
    }

    // ───────────────────────────────────────────────
    // 뒤늦은 재고 복구 (S13) · 대리 철회 (S14) · 신청 내용 수정 (S15) · 환불 미접수 (D9)
    // ───────────────────────────────────────────────

    /**
     * 완료됐지만 재고를 복구하지 않은 상품 행의 재고를 복구합니다. (S13 — 완료 모달에서만 되던 복구의 뒤늦은 입구)
     *
     * 멱등이다 — 신청서 행을 잠근 뒤(`findForUpdate`) 상품 행의 `is_stock_restored` 를 다시 읽어, 이미 복구된 행은
     * 건너뛴다. 같은 신청서의 동시 복구는 신청서 행 잠금이 줄 세운다(주문 행은 잠그지 않는다).
     * 선교환에서 「미회수로 완료」 한 행은 헌 물건이 오지 않았으므로 복구 대상이 아니다(D3). 커밋 뒤
     * `sirsoft-ecommerce.claim.{type}.after_stock_restore` 를 낸다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  array<int, int>  $itemIds  복구할 상품 행 ID (빈 배열이면 복구 가능한 행 전부)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return array{claim: OrderExchange|OrderReturn, restored: array<int, array{id: int, quantity: int}>} 복구 결과
     *
     * @throws OrderClaimException 복구할 행 없음
     */
    public function restoreStock(OrderExchange|OrderReturn $claim, array $itemIds, ?int $processedBy = null): array
    {
        $type = $claim->claimType()->value;
        $ids = array_map('intval', $itemIds);
        $restored = [];

        $fresh = DB::transaction(function () use ($claim, $ids, $processedBy, &$restored) {
            $locked = $claim instanceof OrderExchange
                ? ($this->exchangeRepository->findForUpdate((int) $claim->getKey()) ?? $claim)->load('exchangeOptions.orderOption')
                : ($this->returnRepository->findForUpdate((int) $claim->getKey()) ?? $claim)->load('returnOptions.orderOption');

            $items = $locked instanceof OrderExchange ? $locked->exchangeOptions : $locked->returnOptions;

            foreach ($items as $item) {
                if ($ids !== [] && ! in_array((int) $item->id, $ids, true)) {
                    continue;
                }

                if (! $this->isStockRestorable($item)) {
                    continue;
                }

                $quantity = (int) ($item instanceof OrderExchangeOption ? $item->exchange_quantity : $item->return_quantity);

                if ($item->orderOption !== null) {
                    $this->stockService->restoreOptionStockForClaim($item->orderOption, $quantity);
                }

                $marks = ['is_stock_restored' => true, 'processed_by' => $processedBy ?? $item->processed_by];

                $item instanceof OrderExchangeOption
                    ? $this->exchangeOptionRepository->update($item, $marks)
                    : $this->returnOptionRepository->update($item, $marks);
                $restored[] = ['id' => (int) $item->id, 'quantity' => $quantity];
            }

            if ($restored === []) {
                throw OrderClaimException::stockRestoreNotEligible();
            }

            $locked instanceof OrderExchange
                ? $this->exchangeRepository->update($locked, ['is_stock_restored' => true])
                : $this->returnRepository->update($locked, ['is_stock_restored' => true]);

            return $locked->fresh();
        });

        $this->fireAfterCommit(fn () => HookManager::doAction(
            "sirsoft-ecommerce.claim.{$type}.after_stock_restore",
            $fresh,
            $restored,
            $processedBy,
        ));

        return ['claim' => $fresh, 'restored' => $restored];
    }

    /**
     * 상품 행이 뒤늦은 재고 복구 대상인지 판정합니다. (완료 · 미복구 · 미회수 아님)
     *
     * @param  OrderExchangeOption|OrderReturnOption  $item  상품 행
     * @return bool 복구할 수 있으면 true
     */
    public function isStockRestorable(object $item): bool
    {
        $completed = $item->option_status instanceof \BackedEnum && $item->option_status->value === 'completed';

        return $completed
            && ! (bool) $item->is_stock_restored
            && ($item->unreturned_at ?? null) === null;
    }

    /**
     * 고객 요청으로 운영자가 신청을 대신 철회합니다. (S14)
     *
     * 고객 철회와 같은 단계(`withdrawableStatuses()`)에서만 된다. 판매자가 접수한 건도 거둘 수 있다.
     * 결제 환급은 기존 철회 경로(`closeMoneyIfTerminated()`)를 그대로 탄다. 고객 알림에는
     * 「고객님 요청으로 판매자가 철회했습니다」(`withdraw_origin_notice`)가 붙는다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  string  $reason  철회 사유
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return OrderExchange|OrderReturn|OrderRefund 철회된 신청서
     *
     * @throws OrderClaimException 철회 불가 상태 / 사유 누락
     */
    public function withdrawOnBehalf(OrderExchange|OrderReturn|OrderRefund $claim, string $reason, ?int $processedBy): OrderExchange|OrderReturn|OrderRefund
    {
        // 철회와 추가결제 환급은 한 트랜잭션이다(S6) — 환급이 거절되면 철회도 되돌린다.
        return DB::transaction(function () use ($claim, $reason, $processedBy) {
            $withdrawn = match (true) {
                $claim instanceof OrderExchange => $this->exchangeService->withdraw($claim, $processedBy ?? 0, $reason),
                $claim instanceof OrderReturn => $this->returnService->withdraw($claim, $processedBy ?? 0, $reason),
                default => $this->refundRequestService->withdraw($claim, $processedBy ?? 0, $reason),
            };

            if ($withdrawn instanceof OrderExchange || $withdrawn instanceof OrderReturn) {
                $this->closeMoneyIfTerminated($withdrawn);
            }

            return $withdrawn;
        });
    }

    /**
     * 운영자가 신청 내용(사유·상세 사유·교환 재발송지)을 고칩니다. (S15)
     *
     * 고객 수정(`UserClaimService::update()`)을 운영자 축으로 재사용한다 — 판매자 귀책 사유도 고를 수
     * 있고, 판매자가 접수한 건도 고칠 수 있다. 수정 가능 단계는 고객 수정과 같다. 사유 변경으로
     * 배송비가 다시 계산되면 기존 정산 경로(`settleChargeDifference()`)가 이어진다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  array  $payload  수정 페이로드 (reason_code? / reason_detail? / redelivery_address?)
     * @return OrderExchange|OrderReturn|OrderRefund 수정된 신청서
     *
     * @throws OrderClaimException 수정 불가 단계 / 사유 부재
     */
    public function updateRequestOnBehalf(OrderExchange|OrderReturn|OrderRefund $claim, array $payload): OrderExchange|OrderReturn|OrderRefund
    {
        return $this->userClaimService->update($claim->claimType(), $claim, $payload, [], [], null, operator: true)['claim'];
    }

    /**
     * 신청 내용 수정(S15) 모달의 사유 선택지를 반환합니다. (그 유형의 활성 사유 — 판매자 귀책 비노출 사유 포함)
     *
     * 사유 카탈로그 조회 엔드포인트는 `settings.read` 게이트라 상세 봉투가 나른다(택배사·청구 방식과 같은 이유).
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return array<int, array{value: string, label: string, fault_type: string|null}> 사유 선택지 (취소는 빈 배열)
     */
    public function reasonOptionsFor(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): array
    {
        if ($claim instanceof OrderCancel) {
            return [];
        }

        return $this->reasonOptionsForType($claim->claimType());
    }

    /**
     * 유형의 활성 사유 선택지를 반환합니다. (판매자 귀책 비노출 사유 포함 — 운영자 축)
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @return array<int, array{value: string, label: string, fault_type: string|null}> 사유 선택지
     */
    public function reasonOptionsForType(ClaimTypeEnum $type): array
    {
        return $this->claimReasonRepository->getActiveReasons($type->value)
            ->map(fn ($reason) => [
                'value' => (string) $reason->code,
                'label' => $reason->getLocalizedName(),
                'fault_type' => $reason->fault_type instanceof \BackedEnum ? $reason->fault_type->value : $reason->fault_type,
            ])
            ->values()
            ->all();
    }

    /**
     * 완료됐는데 환불신청서가 없는 환불액을 계산합니다. (D9 — 「환불 미접수」 표시의 단일 출처)
     *
     * 교환·반품이고, 환불이 연결되지 않은 완료 상품이 있으며(반품은 상품 행 `refund_id`, 교환은 저가 차액
     * 완료 상품 중 살아 있는 환불신청서에 담기지 않은 것), 정산 블록의 환불액이 0 보다 클 때만 값을 낸다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return array{amount: float, amount_formatted: string, item_count: int}|null 미접수 환불 (없으면 null)
     */
    public function pendingRefundFor(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): ?array
    {
        if (! $claim instanceof OrderExchange && ! $claim instanceof OrderReturn) {
            return null;
        }

        // 판정은 모델 한 곳(`pendingRefundItems`)이다 — 목록·주문 상세 뱃지와 필터가 같은 판정을 읽는다.
        $unlinked = $claim->pendingRefundItems();

        if ($unlinked->isEmpty()) {
            return null;
        }

        // 금액은 정산 블록(완료 미리보기)과 같은 산식이다 — 완료 전 예고와 완료 후 미접수가 같은 숫자를 말한다.
        $preview = $claim instanceof OrderReturn
            ? $this->returnService->previewCompletion($claim)
            : $this->exchangeService->previewRefundRequest($claim);

        if (($preview['blocked_reason'] ?? null) !== null) {
            return null;
        }

        $amount = (float) $preview['refund_amount'] + (float) $preview['refund_points_amount'];

        if ($amount <= 0) {
            return null;
        }

        $claim->loadMissing('order');
        $currency = $claim->currency_code ?: $this->orderBaseCurrencyOf($claim->order);
        $snapshot = is_array($claim->order?->currency_snapshot) ? $claim->order->currency_snapshot : null;

        return [
            'amount' => $amount,
            'amount_formatted' => $this->currencyService->formatPrice($amount, $currency, $snapshot),
            'item_count' => $unlinked->count(),
        ];
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
     * 완료하면 **아직 나가지 않은 적립 예정분**이 사라지는 상품이 있는지 판정합니다.
     *
     * 「적립 차감」 예고(완료 모달 요약 · 상세 정산 카드)의 근거다. 반품·교환을 완료하면 주문옵션
     * 상태가 바뀌어 적립 대상 조회에서 빠지므로, 적립 **전**인 상품은 예정분이 나가지 않는다 —
     * 예고가 맞다. 반면 이미 적립이 나간 상품은 되돌리지 않는다(#599 T2-D13 · 운영 결정 2026-09-23
     * — `UserMileageService::cancelEarnForOption()`). 예전 판정(회원 주문 + 완료 대상 존재)은 그
     * 결정 뒤에도 남아, 120원이 이미 적립된 상품의 반품에 **일어나지 않을 차감**을 예고했다
     * (2026-09-23 재실측: 반품 완료 뒤 `earn_cancel` 0건 · 적립 lot 그대로).
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  array<int, int>  $completableIds  완료 대상 신청 상품 행 ID
     * @return bool 완료 대상 중 적립 전인 주문옵션이 하나라도 있으면 true (비회원 주문은 false)
     */
    protected function dropsPendingEarn(OrderExchange|OrderReturn $claim, array $completableIds): bool
    {
        if ($claim->order?->user_id === null || $completableIds === []) {
            return false;
        }

        $items = $claim instanceof OrderReturn
            ? $claim->loadMissing('returnOptions')->returnOptions
            : $claim->loadMissing('exchangeOptions')->exchangeOptions;

        return $items
            ->filter(fn ($item) => in_array((int) $item->id, $completableIds, true))
            ->contains(fn ($item) => ! $this->mileageRepository->existsEarnForOption((int) $item->order_option_id));
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
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서 (유형 판정용)
     * @param  string|null  $statusValue  상태 값
     * @return string 라벨 (해석 불가 시 원시 값)
     */
    protected function optionStatusLabel(
        OrderExchange|OrderReturn|OrderRefund $claim,
        ?string $statusValue,
    ): string {
        if ($statusValue === null) {
            return '';
        }

        $status = match (true) {
            $claim instanceof OrderExchange => ExchangeStatusEnum::tryFrom($statusValue),
            $claim instanceof OrderReturn => ReturnStatusEnum::tryFrom($statusValue),
            default => RefundStatusEnum::tryFrom($statusValue),
        };

        return $status?->label() ?? $statusValue;
    }
}
