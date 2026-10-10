<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\DTO\CancellationResult;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimImageUploaderTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimReasonRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;

/**
 * 유저(회원·비회원) 클레임 신청 오케스트레이션 서비스 (S5)
 *
 * 회원 컨트롤러와 비회원 컨트롤러가 **같은 메서드**를 부른다 — 둘의 차이는 주문을 해석하는 방법
 * (회원 소유권 / HMAC 토큰)뿐이고 그 차이는 FormRequest 가 흡수한다. 이 서비스는 유형별 도메인 서비스
 * (교환·반품·취소/환불 분기)와 결제·첨부 서비스를 한 흐름으로 엮는다:
 *
 *  - 신청: 도메인 서비스 접수 → 미귀속 첨부 귀속 → (신청 시점 결제면) 결제 대기 건 생성·결제 진입
 *  - 수정(D13/D14): 도메인 서비스 수정 → 첨부 추가·삭제 → 청구액 차액 정산(추가 청구 / 환급)
 *  - 철회: 도메인 서비스 철회 → 기결제 건 환급
 *
 * 클레임 번호 해석(§5-1-5)은 유형별 Repository 의 `findByNumber` 를 차례로 묻는다 — 접두사(EX/RT/RF/CN)는
 * 운영자가 채번 설정에서 바꿀 수 있어 리터럴로 판정하지 않는다.
 */
class UserClaimService
{
    /**
     * @param  OrderExchangeService  $exchangeService  교환 서비스
     * @param  OrderReturnService  $returnService  반품 서비스
     * @param  OrderRefundRequestService  $refundRequestService  취소·환불 분기 서비스
     * @param  ClaimPaymentService  $paymentService  추가결제 서비스
     * @param  ClaimAttachmentService  $attachmentService  첨부 서비스
     * @param  ClaimQuantityGuard  $claimQuantityGuard  수량 잠금 가드
     * @param  EcommerceSettingsService  $settingsService  설정 서비스
     * @param  OrderExchangeRepositoryInterface  $exchangeRepository  교환 Repository
     * @param  OrderReturnRepositoryInterface  $returnRepository  반품 Repository
     * @param  OrderRefundRepositoryInterface  $refundRepository  환불신청서 Repository
     * @param  OrderCancelRepositoryInterface  $cancelRepository  취소 Repository
     * @param  ClaimReasonRepositoryInterface  $claimReasonRepository  사유 카탈로그 Repository
     * @param  CurrencyConversionService  $currencyService  통화 서비스 (주문 기준 통화 해석 — 클레임 공용 규칙)
     */
    public function __construct(
        protected OrderExchangeService $exchangeService,
        protected OrderReturnService $returnService,
        protected OrderRefundRequestService $refundRequestService,
        protected ClaimPaymentService $paymentService,
        protected ClaimAttachmentService $attachmentService,
        protected ClaimQuantityGuard $claimQuantityGuard,
        protected EcommerceSettingsService $settingsService,
        protected OrderExchangeRepositoryInterface $exchangeRepository,
        protected OrderReturnRepositoryInterface $returnRepository,
        protected OrderRefundRepositoryInterface $refundRepository,
        protected OrderCancelRepositoryInterface $cancelRepository,
        protected ClaimReasonRepositoryInterface $claimReasonRepository,
        protected CurrencyConversionService $currencyService,
    ) {}

    // ───────────────────────────────────────────────
    // 연결 신청서 (보완 5)
    // ───────────────────────────────────────────────

    /**
     * 이 신청서와 이어진 신청서들을 모읍니다. (보완 5)
     *
     * - 반품·교환: 이 신청서에서 생긴 환불신청서 전부(출처 축 — 반려·철회 후 재접수 포함, id 오름차순)
     * - 환불: 원 신청서(반품·교환·구형 취소 출처일 때)와 처리 결과 취소 내역
     * - 취소: 원 신청서(= 환불신청서)
     *
     * 연결 신청서는 언제나 같은 주문으로 한정한다 — 출처 id 가 같은 다른 주문의 행이 섞이지 않게 하는 방어다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return array{source: OrderExchange|OrderReturn|OrderRefund|OrderCancel|null, refunds: \Illuminate\Support\Collection<int, OrderRefund>, result_cancel: OrderCancel|null} 연결 신청서
     */
    public function linkedClaimsFor(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): array
    {
        $orderId = (int) $claim->order_id;
        $sameOrder = fn ($linked) => $linked !== null && (int) $linked->order_id === $orderId ? $linked : null;

        if ($claim instanceof OrderExchange || $claim instanceof OrderReturn) {
            $sourceType = $claim instanceof OrderExchange ? RefundSourceTypeEnum::EXCHANGE : RefundSourceTypeEnum::RETURN;
            $refunds = $this->refundRepository->listBySource($sourceType->value, (int) $claim->id)
                ->filter(fn (OrderRefund $refund) => (int) $refund->order_id === $orderId)
                ->values();

            return ['source' => null, 'refunds' => $refunds, 'result_cancel' => null];
        }

        if ($claim instanceof OrderRefund) {
            $sourceId = (int) ($claim->refund_source_id ?? 0);
            $source = match ($claim->refund_source_type) {
                RefundSourceTypeEnum::RETURN => $sourceId > 0 ? $this->returnRepository->findById($sourceId) : null,
                RefundSourceTypeEnum::EXCHANGE => $sourceId > 0 ? $this->exchangeRepository->findById($sourceId) : null,
                RefundSourceTypeEnum::CANCEL => $sourceId > 0 ? $this->cancelRepository->findById($sourceId) : null,
                default => null,
            };

            return [
                'source' => $sameOrder($source),
                'refunds' => collect(),
                'result_cancel' => $sameOrder($claim->loadMissing('orderCancel')->orderCancel),
            ];
        }

        return [
            'source' => $sameOrder($claim->loadMissing('refund')->refund),
            'refunds' => collect(),
            'result_cancel' => null,
        ];
    }

    // ───────────────────────────────────────────────
    // 신청 가능 옵션 / 설정
    // ───────────────────────────────────────────────

    /**
     * 주문의 클레임 가능 상품과 유형·사유·설정을 돌려줍니다. (신청 화면의 `claimable` 컨텍스트)
     *
     * @param  Order  $order  주문
     * @return array 신청 가능 옵션 + 유형별 사유 + 설정 + 환불계좌 요건 + 미귀속 첨부
     */
    public function claimableOptions(Order $order): array
    {
        $order->loadMissing('options');

        // 비회원 주문은 소유 회원이 없다 — 그 주문의 신청자는 조회 토큰을 쥔 비회원이며,
        // 마일리지 결제가 열리지 않는 쪽이다(회원 경로는 본인 주문만 연다).
        $isGuest = $order->user_id === null;

        $exchangeStatuses = $this->statusValues('claim.exchange_requestable_statuses', [OrderStatusEnum::DELIVERED->value, OrderStatusEnum::CONFIRMED->value]);
        $returnStatuses = $this->statusValues('claim.return_requestable_statuses', [OrderStatusEnum::DELIVERED->value, OrderStatusEnum::CONFIRMED->value]);

        $options = [];
        $branches = [];

        foreach ($order->options as $option) {
            $statusValue = $option->option_status instanceof OrderStatusEnum ? $option->option_status->value : (string) $option->option_status;

            if ($statusValue === OrderStatusEnum::CANCELLED->value) {
                continue;
            }

            $available = $this->claimQuantityGuard->availableQuantity($option);
            $branch = $this->refundRequestService->branchForStatus($option->option_status);
            $types = [];

            if ($available > 0) {
                if (in_array($statusValue, $exchangeStatuses, true)) {
                    $types[] = ClaimTypeEnum::EXCHANGE->value;
                }

                if (in_array($statusValue, $returnStatuses, true)) {
                    $types[] = ClaimTypeEnum::RETURN->value;
                }

                if ($branch !== null) {
                    $types[] = ClaimTypeEnum::REFUND->value;
                    $branches[$branch] = true;
                }
            }

            $options[] = [
                'option' => $option,
                'available_quantity' => $available,
                'locked_quantity' => max(0, (int) $option->quantity - $available),
                'claimable_types' => $types,
                'refund_branch' => $branch,
            ];
        }

        return [
            'options' => $options,
            // 이 주문의 금액 표기 기준 통화. 신청서 화면은 이 응답의 onSuccess 에서 상품 줄을 세우면서
            // 보조 통화 목록에서 기준 통화를 걸러낸다. 그 기준을 **다른 데이터소스**(`order`)에서 읽으면
            // 둘 중 어느 응답이 먼저 오느냐에 따라 결과가 갈린다 — 늦게 오는 날에는 기준 통화가 보조
            // 통화 줄에 한 번 더 나열되고, 오류도 경고도 없이 금액 줄만 하나 늘어난다(S17 트랙 A F-5).
            // 반응 대상 응답이 스스로 그 기준을 들고 오면 경합 자체가 성립하지 않는다.
            'base_currency' => $this->orderBaseCurrency($order),
            'resolved_type' => count($branches) === 1 ? array_key_first($branches) : (count($branches) > 1 ? 'mixed' : null),
            'reasons' => [
                ClaimTypeEnum::EXCHANGE->value => $this->claimReasonRepository->getUserSelectableReasons(ClaimTypeEnum::EXCHANGE->value),
                ClaimTypeEnum::RETURN->value => $this->claimReasonRepository->getUserSelectableReasons(ClaimTypeEnum::RETURN->value),
                ClaimTypeEnum::REFUND->value => $this->claimReasonRepository->getUserSelectableReasons(ClaimTypeEnum::REFUND->value),
            ],
            'settings' => $this->publicSettings($isGuest),
            // 접수 뒤 «자동으로 끝나는가» — 분기(`refund_branch`)와는 다른 축이다(P16·P17).
            // 무통장·가상계좌는 분기가 auto 여도 `requested` 에서 멈추고 운영자 승인을 기다린다.
            // 이 사실을 싣지 않으면 신청서는 분기만 보고 「자동 처리」라고 안내하게 되고,
            // 구매자는 곧 돈이 돌아온다고 읽는다. 판정은 결제수단 카탈로그가 소유한다.
            'refund_auto_approved' => $this->refundAutoApproved($order),
            'refund_account' => $this->refundAccountRequirement($order),
            'pending_images' => $this->attachmentService->listPendingFor($order),
        ];
    }

    /**
     * 주문의 금액 표기 기준 통화 코드를 돌려줍니다.
     *
     * 거래 시점에 동결된 스냅샷이 정본이고, 없으면 상점 기본 통화로 떨어진다. 판정은 클레임
     * 서비스 공용 헬퍼(`CurrencyConversionService::resolveOrderBaseCurrency()`) 하나가 소유한다 —
     * 예전에는 서비스마다 폴백이 달라(결제 통화·`KRW` 리터럴) 같은 주문을 응답마다 다른 통화로
     * 적었다(#599 validate P2 #14).
     *
     * @param  Order  $order  주문
     * @return string 기준 통화 코드
     */
    protected function orderBaseCurrency(Order $order): string
    {
        return $this->currencyService->resolveOrderBaseCurrency($order);
    }

    /**
     * 환불계좌 입력 요건을 돌려줍니다. (§6-3 — 무통장 원결제면 환불계좌 입력)
     *
     * 판정은 환불 도메인 서비스가 소유한다(`refundsToBankAccount`) — 화면이 결제수단 코드로
     * 재현하면 확장 결제수단이 자기 카탈로그에 선언한 환불 수단과 갈린다.
     *
     * 주문 시 등록해 둔 계좌가 있으면 그 **원문**을 `prefill` 로 실어 화면이 입력칸을 채운다
     * (2026-08-31 확정 — 우려 제기 후 재확인). 표시용 리소스(`MasksRefundBankAccount`)의
     * 마스킹 값을 채우면 구매자가 손대지 않고 제출했을 때 별표가 섞인 계좌번호가 저장되므로,
     * 프리필은 반드시 이 원문 통로를 쓰고 마스킹 값을 재사용하지 않는다.
     *
     * 이 통로는 자기 주문을 여는 경로(회원 본인 / 비회원 조회 토큰)에서만 열린다 —
     * `claimable-options` 자체가 그 자격을 이미 통과한 뒤다. 비회원 조회 토큰을 얻은 사람에게
     * 계좌 원문이 노출되는 것은 그 결정이 감수한 대가이며, 계좌가 필요 없는 주문
     * (`required=false`)에는 싣지 않는다.
     *
     * @param  Order  $order  주문
     * @return array{required: bool, banks: array<int, array{code: string, name: mixed}>, prefill: array{bank_code: string, account_number: string, holder: string}|null} 환불계좌 요건
     */
    protected function refundAccountRequirement(Order $order): array
    {
        // 돌려줄 현금이 없는 주문(마일리지로 전액 결제)은 계좌가 쓰일 일이 없다(결함 4).
        $required = $this->refundRequestService->refundsToBankAccount($order)
            && $this->refundRequestService->hasCashToReturn($order);

        return [
            'required' => $required,
            'prefill' => $required ? $this->registeredRefundAccount($order) : null,
            // 은행 목록은 운영자가 주문설정에서 관리하는 값이다 — 화면에 코드표를 두면 운영자가
            // 취급 은행을 바꿔도 신청서만 옛 목록으로 남는다. 필요하지 않은 주문에는 싣지 않는다.
            // 운영자가 목록을 비웠으면 모듈 기본 목록으로 떨어진다(`getBankList()` — 필수 칸에
            // 선택지가 0개면 환불 신청이 영영 막힌다).
            'banks' => $required
                ? array_map(
                    fn (array $bank) => ['code' => (string) $bank['code'], 'name' => $bank['name'] ?? $bank['code']],
                    $this->settingsService->getBankList(),
                )
                : [],
        ];
    }

    /**
     * 이 주문의 환불이 사람 손을 거치지 않고 자동으로 끝나는지 돌려줍니다. (P16·P17)
     *
     * 판정 SSoT 는 결제수단 카탈로그(`PaymentMethodResolver::autoApprovesRefund`)다 — 운영자가
     * 클레임 정책 탭에서 고른 목록과 플러그인 선언을 함께 본다. 화면이 결제수단 코드로 재현하면
     * 운영자가 목록을 바꿔도 안내만 옛 규칙으로 남는다.
     *
     * @param  Order  $order  주문
     * @return bool 자동 승인 대상이면 true
     */
    protected function refundAutoApproved(Order $order): bool
    {
        // 마일리지로만 돌려주는 주문은 계좌 송금이 없어 결제수단과 무관하게 자동으로 끝난다(결함 4) —
        // 안내가 실제 자동 완료와 갈리지 않게 같은 술어를 읽는다.
        if ($this->refundRequestService->refundsOnlyMileage($order)) {
            return true;
        }

        $order->loadMissing('payment');

        $methodId = $order->payment?->paymentMethodId();

        if (! is_string($methodId) || $methodId === '') {
            return false;
        }

        return app(PaymentMethodResolver::class)->autoApprovesRefund($methodId);
    }

    /**
     * 주문에 등록된 환불 계좌 원문을 돌려줍니다. (신청서 입력칸 프리필)
     *
     * 세 값이 모두 있을 때만 돌려준다 — 일부만 채워 보내면 화면이 반쯤 채워진 폼을 그리고,
     * 서버는 "셋 다 오거나 하나도 오지 않거나" 만 허용하므로 구매자가 나머지를 채우기 전까지
     * 제출이 거절된다. 아무것도 없으면 `null` 이고 화면은 빈 칸을 연다.
     *
     * 마스킹 리소스를 거치지 않는다 — 마스킹 값을 채우면 손대지 않은 제출에서 별표가 섞인
     * 계좌번호가 저장된다.
     *
     * @param  Order  $order  주문
     * @return array{bank_code: string, account_number: string, holder: string}|null 등록된 환불 계좌
     */
    protected function registeredRefundAccount(Order $order): ?array
    {
        $order->loadMissing('payment');
        $payment = $order->payment;

        if ($payment === null) {
            return null;
        }

        $bankCode = (string) ($payment->refund_bank_code ?? '');
        $account = (string) ($payment->refund_bank_account ?? '');
        $holder = (string) ($payment->refund_bank_holder ?? '');

        if ($bankCode === '' || $account === '' || $holder === '') {
            return null;
        }

        return [
            'bank_code' => $bankCode,
            'account_number' => $account,
            'holder' => $holder,
        ];
    }

    /**
     * 신청 화면이 읽는 공개 설정을 돌려줍니다. (`claim_settings` 컨텍스트)
     *
     * 결제 수단 목록은 **그 신청자가 실제로 쓸 수 있는 것**만 싣는다. 마일리지는 회원 원장에서
     * 차감하므로 비회원에게 열리지 않는데(`ClaimPaymentService::assertPaymentMethodAvailableToRequester`),
     * 화면이 운영자 허용 목록을 그대로 그리면 비회원에게 고를 수 없는 수단이 보인다 — 고르고
     * 제출해야 422 로 거절되고, 그 전까지는 화면 어디에도 그 사실이 나타나지 않는다
     * (2026-09-01 브라우저 실측). 거절하는 지점과 보여 주는 목록이 같은 판정을 써야 한다.
     *
     * @param  bool  $isGuest  비회원 신청자 여부
     * @return array 공개 설정
     */
    public function publicSettings(bool $isGuest = false): array
    {
        $chargePaymentMethods = $this->statusValues('claim.charge_payment_methods', [PaymentMethodEnum::CARD->value, PaymentMethodEnum::POINT->value]);

        if ($isGuest) {
            $chargePaymentMethods = array_values(array_filter(
                $chargePaymentMethods,
                fn (string $method): bool => $method !== PaymentMethodEnum::POINT->value,
            ));
        }

        return [
            'allowed_charge_methods' => $this->statusValues('claim.allowed_charge_methods', ClaimChargeMethodEnum::values()),
            'charge_payment_methods' => $chargePaymentMethods,
            'charge_payment_method_labels' => $this->paymentMethodLabels($chargePaymentMethods),
            // 첨부 상한·허용 확장자는 한 출처에서 온다 — 키 이름은 종전 그대로이고
            // `accept` 가 덤으로 붙으므로 기존 화면·핸들러는 무영향이다.
            ...$this->attachmentService->publicLimits(),
            'request_period_days' => (int) $this->settingsService->getSetting('claim.request_period_days', 7),
            'exchange_requestable_statuses' => $this->statusValues('claim.exchange_requestable_statuses', [OrderStatusEnum::DELIVERED->value, OrderStatusEnum::CONFIRMED->value]),
            'return_requestable_statuses' => $this->statusValues('claim.return_requestable_statuses', [OrderStatusEnum::DELIVERED->value, OrderStatusEnum::CONFIRMED->value]),
            'instant_cancel_statuses' => $this->refundRequestService->instantCancelStatusValues(),
            'refund_request_statuses' => $this->refundRequestService->refundRequestStatusValues(),
        ];
    }

    // ───────────────────────────────────────────────
    // 미리보기
    // ───────────────────────────────────────────────

    /**
     * 유형별 신청 미리보기를 계산합니다. (금액 계산의 SSoT — 화면은 표시만 한다)
     *
     * @param  Order  $order  주문
     * @param  ClaimTypeEnum  $type  클레임 유형 (exchange / return / refund)
     * @param  array  $input  미리보기 입력 (items / reason_code / charge_method / refund_priority / redelivery_address)
     * @param  int|null  $excludeClaimId  수정 화면이면 자기 신청서 ID (수량 잠금 집계 제외)
     * @return array 미리보기 결과 (유형별 형태 + 공통 `type`)
     *
     * @throws OrderClaimException 신청 자격·사유 위반 / 알 수 없는 청구 방식·환불 우선순위
     */
    public function preview(Order $order, ClaimTypeEnum $type, array $input, ?int $excludeClaimId = null): array
    {
        $items = $input['items'] ?? [];
        $refundPriority = $this->resolveRefundPriority($input['refund_priority'] ?? null);
        $chargeMethod = $this->resolveChargeMethod($input['charge_method'] ?? null);

        return match ($type) {
            ClaimTypeEnum::EXCHANGE => ['type' => $type->value] + $this->exchangeService->previewExchange(
                $order,
                $items,
                (string) ($input['reason_code'] ?? ''),
                $chargeMethod,
                $refundPriority,
                $input['redelivery_address'] ?? null,
                $excludeClaimId,
            ),
            ClaimTypeEnum::RETURN => ['type' => $type->value] + $this->returnService->previewReturn(
                $order,
                $items,
                (string) ($input['reason_code'] ?? ''),
                $chargeMethod,
                $refundPriority,
                $excludeClaimId,
            ),
            default => ['type' => $type->value] + $this->refundRequestService->previewRefund($order, $items, $refundPriority, $excludeClaimId),
        };
    }

    // ───────────────────────────────────────────────
    // 신청
    // ───────────────────────────────────────────────

    /**
     * 교환을 신청합니다. (접수 → 첨부 귀속 → 신청 시점 결제 진입)
     *
     * @param  Order  $order  주문
     * @param  array  $payload  서비스 페이로드 (`StoreExchangeRequest::toServicePayload()`)
     * @param  array<int, int>  $imageIds  귀속할 미귀속 첨부 ID
     * @param  string|null  $paymentMethod  신청 시점 결제 수단
     * @return array{claim: OrderExchange, payment: ?ClaimPayment, payment_entry: ?array} 신청 결과
     *
     * @throws OrderClaimException 신청 자격·사유·청구 방식·첨부 위반
     */
    public function requestExchange(Order $order, array $payload, array $imageIds = [], ?string $paymentMethod = null): array
    {
        // 첨부 자격은 신청서를 만들기 전에 본다 — 접수는 자체 트랜잭션으로 이미 커밋되므로,
        // 귀속 단계에서 거절하면 신청서만 남고 그 신청서가 수량 잠금을 잡아 재신청까지 막힌다.
        // 결제 수단 자격(비회원의 마일리지)도 같은 이유로 접수 앞에 둔다 — 결제 진입에서 거절하면
        // 신청서와 쓸 수 없는 수단이 박힌 결제행이 둘 다 남는다.
        $this->attachmentService->resolveAttachable($order, $imageIds, $payload['requested_by'] ?? null);
        $this->paymentService->assertRequestPaymentMethodAvailable($paymentMethod, $payload['requested_by'] ?? null);

        // 고객이 고른 환불 우선순위를 Enum 으로 확정해 넘긴다 — 도메인 서비스가 그 값으로 계산하고 신청서에 기록한다.
        $payload['refund_priority'] = $this->resolveRefundPriority($payload['refund_priority'] ?? null);

        $exchange = $this->exchangeService->requestExchange($order, $payload);
        $this->attachmentService->attachPending($order, $imageIds, ClaimTypeEnum::EXCHANGE, $exchange->id, $payload['requested_by'] ?? null);

        return $this->enterPayment($exchange, $paymentMethod, $payload['requested_by'] ?? null);
    }

    /**
     * 반품을 신청합니다. (접수 → 첨부 귀속 → 신청 시점 결제 진입)
     *
     * @param  Order  $order  주문
     * @param  array  $payload  서비스 페이로드
     * @param  array<int, int>  $imageIds  귀속할 미귀속 첨부 ID
     * @param  string|null  $paymentMethod  신청 시점 결제 수단
     * @return array{claim: OrderReturn, payment: ?ClaimPayment, payment_entry: ?array} 신청 결과
     *
     * @throws OrderClaimException 신청 자격·사유·청구 방식·첨부 위반
     */
    public function requestReturn(Order $order, array $payload, array $imageIds = [], ?string $paymentMethod = null): array
    {
        // 교환 경로와 같은 이유로 첨부 자격과 결제 수단 자격을 선행 검사한다 (아래 `requestExchange()` 주석 참조).
        $this->attachmentService->resolveAttachable($order, $imageIds, $payload['requested_by'] ?? null);
        $this->paymentService->assertRequestPaymentMethodAvailable($paymentMethod, $payload['requested_by'] ?? null);

        // 고객이 고른 환불 우선순위를 Enum 으로 확정해 넘긴다 — 도메인 서비스가 그 값으로 계산하고 신청서에 기록한다.
        $payload['refund_priority'] = $this->resolveRefundPriority($payload['refund_priority'] ?? null);

        $return = $this->returnService->requestReturn($order, $payload);
        $this->attachmentService->attachPending($order, $imageIds, ClaimTypeEnum::RETURN, $return->id, $payload['requested_by'] ?? null);

        return $this->enterPayment($return, $paymentMethod, $payload['requested_by'] ?? null);
    }

    /**
     * 취소·환불 신청이 어느 분기로 갈지 판정합니다. (`auto` 즉시 취소 | `request` 환불 심사)
     *
     * 접수 전에 분기를 알아야 하는 판정(즉시 취소 분기의 주문 취소 권한)이 쓴다. 판정은
     * 접수와 같은 출처(`OrderRefundRequestService::resolveBranch()`)다.
     *
     * @param  Order  $order  주문
     * @param  array  $items  신청 대상 [{order_option_id, quantity}]
     * @return string 분기 (`auto` | `request`)
     *
     * @throws OrderClaimException 대상 부재 / 분기 혼재 / 신청 불가 상태
     */
    public function refundBranchFor(Order $order, array $items): string
    {
        return $this->refundRequestService->resolveBranch($order, $items);
    }

    /**
     * 취소·환불을 신청합니다. (D2 자동 분기 — 즉시취소 또는 환불신청서)
     *
     * 즉시취소 분기는 취소 파이프라인이 끝나면 첨부할 신청서가 없으므로 미귀속 첨부를 정리한다.
     *
     * @param  Order  $order  주문
     * @param  array  $payload  서비스 페이로드
     * @param  array<int, int>  $imageIds  귀속할 미귀속 첨부 ID
     * @return array{branch: string, cancel: ?CancellationResult, refund: ?OrderRefund} 분기 결과
     *
     * @throws OrderClaimException 신청 자격·사유·첨부 위반
     * @throws OrderCancellationException 즉시취소 파이프라인 거절
     */
    public function requestRefund(Order $order, array $payload, array $imageIds = []): array
    {
        // 교환·반품과 같은 이유로 선행 검사한다 — 첨부가 잘못된 요청은 접수 전에 걸러야 한다.
        $this->attachmentService->resolveAttachable($order, $imageIds, $payload['requested_by'] ?? null);

        // 고객이 고른 환불 우선순위를 Enum 으로 확정해 넘긴다 — 도메인 서비스가 그 값으로 계산하고 신청서에 기록한다.
        $payload['refund_priority'] = $this->resolveRefundPriority($payload['refund_priority'] ?? null);

        $result = $this->refundRequestService->request($order, $payload);

        // 새 모델에서는 자동 전이 분기도 환불신청서를 만든다(D2) — 첨부는 언제나 귀속된다.
        // 예전에는 즉시취소 분기에 귀속할 신청서가 없어 구매자 첨부를 **말없이 지웠다**(F7):
        // 고객이 올린 사진이 접수 직후 사라지고, 그 사실은 어디에도 남지 않았다. 환불할 돈이
        // 없어 취소신청서만 남는 주문(미결제·0원)에서는 귀속 대상이 없으므로 미귀속 상태로
        // 둔다 — 지우지 않는다. 미귀속 첨부는 만료 정리 경로가 따로 거둔다.
        if ($result['refund'] instanceof OrderRefund) {
            $this->attachmentService->attachPending($order, $imageIds, ClaimTypeEnum::REFUND, $result['refund']->id, $payload['requested_by'] ?? null);
        }

        return $result;
    }

    // ───────────────────────────────────────────────
    // 조회
    // ───────────────────────────────────────────────

    /**
     * 신청서 묶음에 실린 사유 코드의 라벨 맵을 만듭니다. (목록 표기용 묶음 조회)
     *
     * `ClaimResource` 는 주입이 없으면 행마다 모델 게터로 `ClaimReason` 을 되읽는다. 목록은 그 횟수가
     * 행 수만큼 늘어나므로 유형별로 코드를 모아 `whereIn` 한 번씩만 친다. 비활성 사유도 포함해야
     * 이미 접수된 신청서의 라벨이 유지된다(단건 게터와 같은 모집단).
     *
     * @param  iterable<string, \Illuminate\Support\Collection>  $byType  유형 값 => 신청서 목록
     * @return array<string, string> `"{유형}:{코드}" => 라벨` 맵
     */
    public function reasonLabelsFor(iterable $byType): array
    {
        $codesByType = [];

        foreach ($byType as $claims) {
            foreach ($claims as $claim) {
                $isCancel = $claim instanceof OrderCancel;
                // 카탈로그 유형은 `ClaimResource::resolveReasonLabel` 과 **같은 규칙**이어야 한다 —
                // 갈리면 목록(주입 경로)과 상세(모델 게터 경로)가 서로 다른 라벨을 말한다. 환불신청서는
                // 파생 출처의 카탈로그를 볼 수 있으므로 그 판정을 모델에 위임한다(S17 트랙 A F-2).
                $type = match (true) {
                    $isCancel, $claim instanceof OrderRefund => $claim->reasonCatalogType(),
                    default => $claim->claimType()->value,
                };
                $code = (string) ($isCancel ? ($claim->cancel_reason_type ?? '') : ($claim->reason_code ?? ''));

                if ($code !== '') {
                    $codesByType[$type][] = $code;
                }
            }
        }

        $labels = [];

        foreach ($codesByType as $type => $codes) {
            foreach ($this->claimReasonRepository->findByCodes($type, $codes) as $reason) {
                $labels[$type.':'.$reason->code] = $reason->getLocalizedName();
            }
        }

        return $labels;
    }

    /**
     * 클레임 번호로 신청서를 해석합니다. (교환 → 반품 → 환불 → 취소 순)
     *
     * @param  string  $claimNumber  클레임 번호
     * @return array{type: ClaimTypeEnum, claim: OrderExchange|OrderReturn|OrderRefund|OrderCancel}|null 해석 결과
     */
    public function resolveByNumber(string $claimNumber): ?array
    {
        $claimNumber = trim($claimNumber);

        if ($claimNumber === '') {
            return null;
        }

        if ($exchange = $this->exchangeRepository->findByNumber($claimNumber, ['exchangeOptions', 'images', 'order'])) {
            return ['type' => ClaimTypeEnum::EXCHANGE, 'claim' => $exchange];
        }

        if ($return = $this->returnRepository->findByNumber($claimNumber, ['returnOptions', 'images', 'order'])) {
            return ['type' => ClaimTypeEnum::RETURN, 'claim' => $return];
        }

        if ($refund = $this->refundRepository->findByNumber($claimNumber, ['refundOptions', 'images', 'order'])) {
            return ['type' => ClaimTypeEnum::REFUND, 'claim' => $refund];
        }

        if ($cancel = $this->cancelRepository->findByNumber($claimNumber, ['cancelOptions', 'refund', 'order'])) {
            return ['type' => ClaimTypeEnum::CANCEL, 'claim' => $cancel];
        }

        return null;
    }

    /**
     * 클레임 번호로 열람자의 신청서를 찾습니다. (소유권 위반은 부재와 같은 예외)
     *
     * @param  string  $claimNumber  클레임 번호
     * @param  int|null  $userId  회원 ID (회원 경로)
     * @param  Order|null  $guestOrder  토큰으로 검증된 비회원 주문 (비회원 경로)
     * @return array{type: ClaimTypeEnum, claim: OrderExchange|OrderReturn|OrderRefund|OrderCancel} 해석 결과
     *
     * @throws OrderClaimException 부재·소유권 위반
     */
    public function findOwned(string $claimNumber, ?int $userId, ?Order $guestOrder = null): array
    {
        $resolved = $this->resolveByNumber($claimNumber);

        if ($resolved === null || ! $this->isOwnedBy($resolved['claim'], $userId, $guestOrder)) {
            throw OrderClaimException::claimNotFound();
        }

        return $resolved;
    }

    /**
     * 신청서가 열람자의 것인지 판정합니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @param  int|null  $userId  회원 ID
     * @param  Order|null  $guestOrder  비회원 주문
     * @return bool 본인 소유이면 true
     */
    public function isOwnedBy(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim, ?int $userId, ?Order $guestOrder = null): bool
    {
        if ($guestOrder !== null) {
            return (int) $claim->order_id === (int) $guestOrder->id;
        }

        if ($userId === null) {
            return false;
        }

        $claim->loadMissing('order');

        return $claim->order !== null && (int) $claim->order->user_id === (int) $userId;
    }

    /**
     * 회원의 신청 내역을 유형별로 페이지네이션 조회합니다. (서브탭 = 유형)
     *
     * @param  int  $userId  회원 ID
     * @param  ClaimTypeEnum  $type  유형
     * @param  array  $filters  필터 (status / from / to)
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function listForUser(int $userId, ClaimTypeEnum $type, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return match ($type) {
            ClaimTypeEnum::EXCHANGE => $this->exchangeRepository->paginateForUser($userId, $filters, $perPage),
            ClaimTypeEnum::RETURN => $this->returnRepository->paginateForUser($userId, $filters, $perPage),
            ClaimTypeEnum::REFUND => $this->refundRepository->paginateForUser($userId, $filters, $perPage),
            ClaimTypeEnum::CANCEL => $this->cancelRepository->paginateForUser($userId, $filters, $perPage),
        };
    }

    /**
     * 한 주문의 신청 내역을 유형별로 페이지네이션 조회합니다. (비회원 주문상세 "이 주문의 클레임" 섹션)
     *
     * 비회원에게는 주문을 가로지르는 신청내역 목록이 없다 — 조회 토큰이 주문 하나에 묶이기
     * 때문이다. 그래서 이 조회가 비회원의 목록이고, 회원 목록(`listForUser`)과 같은 서브탭
     * 계약(유형 하나 · 최신순 · 페이지)을 쓴다. 한 주문의 건수가 그 주문의 상품 수에 묶인다는
     * 전제는 성립하지 않는다 — 철회는 행을 남기므로 신청↔철회를 반복하면 계속 쌓인다.
     *
     * @param  Order  $order  주문
     * @param  ClaimTypeEnum  $type  유형
     * @param  array  $filters  필터 (status / from / to)
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function listForOrderPaginated(Order $order, ClaimTypeEnum $type, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return match ($type) {
            ClaimTypeEnum::EXCHANGE => $this->exchangeRepository->paginateForOrder($order->id, $filters, $perPage),
            ClaimTypeEnum::RETURN => $this->returnRepository->paginateForOrder($order->id, $filters, $perPage),
            ClaimTypeEnum::REFUND => $this->refundRepository->paginateForOrder($order->id, $filters, $perPage),
            ClaimTypeEnum::CANCEL => $this->cancelRepository->paginateForOrder($order->id, $filters, $perPage),
        };
    }

    /**
     * 기본 유형 판정 순서 — 접수 건이 있는 첫 유형을 고른다 (설계 결정 2026-09-09: 교환 → 반품 → 취소 → 환불).
     *
     * 화면의 서브탭 배열 순서(교환·반품·환불·취소)와 다르다 — 순서는 "무엇을 먼저 보여 줄 것인가" 의
     * 결정이지 배열 위치가 아니다. 관리자 주문상세 클레임 내역(`claimHistoryActiveType`)도 같은 순서다.
     *
     * @var array<int, ClaimTypeEnum>
     */
    public const ORDER_LIST_TYPE_PRIORITY = [
        ClaimTypeEnum::EXCHANGE,
        ClaimTypeEnum::RETURN,
        ClaimTypeEnum::CANCEL,
        ClaimTypeEnum::REFUND,
    ];

    /**
     * 이 회원의 유형별 접수 건수를 돌려줍니다. (신청내역 서브탭 뱃지 — F12)
     *
     * 주문 스코프 건수(`countsForOrder`)와 같은 규칙이다 — 목록과 같은 저장소 기준 쿼리로 세고,
     * **상태는 전 탭에 걸지 않는다**(유형 종속 어휘라 다른 탭 건수를 언제나 0 으로 만든다).
     * 현재 탭만 `refreshCurrentUserTypeCount()` 가 목록과 완전히 같은 필터로 다시 센다.
     *
     * 네 유형 전부를 늘 돌려준다 — 화면이 키 부재를 0 으로 읽으면 "세지 않음" 과 "0건" 이
     * 구분되지 않는다.
     *
     * @param  int  $userId  회원 ID
     * @param  array<string, mixed>  $filters  목록과 같은 필터 (status / from / to)
     * @return array<string, int> 유형 값 => 건수 (네 유형 전부)
     */
    public function countsForUser(int $userId, array $filters = []): array
    {
        $shared = array_diff_key($filters, ['status' => null]);

        return [
            ClaimTypeEnum::EXCHANGE->value => $this->exchangeRepository->countForUser($userId, $shared),
            ClaimTypeEnum::RETURN->value => $this->returnRepository->countForUser($userId, $shared),
            ClaimTypeEnum::REFUND->value => $this->refundRepository->countForUser($userId, $shared),
            ClaimTypeEnum::CANCEL->value => $this->cancelRepository->countForUser($userId, $shared),
        ];
    }

    /**
     * 회원 신청내역에서 **현재 탭의 건수만** 목록과 같은 필터로 다시 셉니다. (F15 와 같은 규칙)
     *
     * @param  int  $userId  회원 ID
     * @param  array<string, int>  $counts  `countsForUser()` 결과
     * @param  ClaimTypeEnum  $currentType  현재 탭의 유형
     * @param  array<string, mixed>  $filters  목록과 같은 필터
     * @return array<string, int> 현재 탭 건수가 보정된 결과
     */
    public function refreshCurrentUserTypeCount(int $userId, array $counts, ClaimTypeEnum $currentType, array $filters = []): array
    {
        if (empty($filters['status'])) {
            return $counts;
        }

        $counts[$currentType->value] = match ($currentType) {
            ClaimTypeEnum::EXCHANGE => $this->exchangeRepository->countForUser($userId, $filters),
            ClaimTypeEnum::RETURN => $this->returnRepository->countForUser($userId, $filters),
            ClaimTypeEnum::REFUND => $this->refundRepository->countForUser($userId, $filters),
            ClaimTypeEnum::CANCEL => $this->cancelRepository->countForUser($userId, $filters),
        };

        return $counts;
    }

    /**
     * 주문의 유형별 접수 건수를 돌려줍니다. (주문상세 섹션의 서브탭 뱃지·기본 유형 판정)
     *
     * 건수는 목록과 같은 저장소 기준 쿼리·**같은 필터**로 센다(`countForOrder` — F15).
     * 필터를 넘기지 않으면 뱃지와 목록이 서로 다른 수를 말한다: 구매자가 기간을 좁히면
     * 목록은 줄고 뱃지는 그대로여서 어느 쪽이 맞는지 알 수 없었다.
     *
     * 네 유형 전부를 늘 돌려준다 — 화면이 키 부재를 0 으로 읽으면 "세지 않음" 과 "0건" 이
     * 구분되지 않는다.
     *
     * @param  Order  $order  주문
     * @param  array<string, mixed>  $filters  목록과 같은 필터 (status / from / to)
     * @return array<string, int> 유형 값 => 건수 (네 유형 전부)
     */
    public function countsForOrder(Order $order, array $filters = []): array
    {
        // 상태는 **유형 종속 어휘**라 다른 탭의 건수에 적용할 수 없다 — 환불 상태를 교환 탭에
        // 걸면 그 탭 건수가 언제나 0 이 된다. 기간만 전 탭에 걸고, 상태는 현재 탭에만
        // `refreshCurrentTypeCount()` 가 건다(F15 · 관리자 F13 과 같은 규칙).
        $shared = array_diff_key($filters, ['status' => null]);

        return [
            ClaimTypeEnum::EXCHANGE->value => $this->exchangeRepository->countForOrder($order->id, $shared),
            ClaimTypeEnum::RETURN->value => $this->returnRepository->countForOrder($order->id, $shared),
            ClaimTypeEnum::REFUND->value => $this->refundRepository->countForOrder($order->id, $shared),
            ClaimTypeEnum::CANCEL->value => $this->cancelRepository->countForOrder($order->id, $shared),
        ];
    }

    /**
     * 현재 탭의 건수만 목록과 **완전히 같은 필터**로 다시 셉니다. (F15)
     *
     * 다른 탭의 뱃지는 "그 탭으로 가면 몇 건인가" 라는 원 의미를 지키고, 지금 보고 있는 탭의
     * 뱃지만 화면의 목록과 일치시킨다 — 같은 화면 안에서 뱃지와 목록이 다른 수를 말하는 것이
     * 구매자가 실제로 겪는 어긋남이기 때문이다.
     *
     * @param  Order  $order  주문
     * @param  array<string, int>  $counts  `countsForOrder()` 결과
     * @param  ClaimTypeEnum  $currentType  현재 탭의 유형
     * @param  array<string, mixed>  $filters  목록과 같은 필터
     * @return array<string, int> 현재 탭 건수가 보정된 결과
     */
    public function refreshCurrentTypeCount(Order $order, array $counts, ClaimTypeEnum $currentType, array $filters = []): array
    {
        if (! array_key_exists('status', $filters) || ! filled($filters['status'])) {
            return $counts;
        }

        $repository = match ($currentType) {
            ClaimTypeEnum::EXCHANGE => $this->exchangeRepository,
            ClaimTypeEnum::RETURN => $this->returnRepository,
            ClaimTypeEnum::REFUND => $this->refundRepository,
            ClaimTypeEnum::CANCEL => $this->cancelRepository,
        };

        $counts[$currentType->value] = $repository->countForOrder($order->id, $filters);

        return $counts;
    }

    /**
     * 주문상세 섹션이 처음 보여 줄 유형을 정합니다.
     *
     * 요청이 유형을 지정했으면 그 값이다(서브탭을 누른 상태·북마크). 지정하지 않았으면 접수 건이
     * 있는 첫 유형(`ORDER_LIST_TYPE_PRIORITY` 순서), 넷 다 비었으면 교환이다 — 종전에는 늘
     * 교환이어서 취소만 있는 주문에서 빈 안내가 먼저 보였다.
     *
     * @param  ClaimTypeEnum|null  $requested  요청이 지정한 유형 (없으면 null)
     * @param  array<string, int>  $counts  유형별 건수 (`countsForOrder()`)
     * @return ClaimTypeEnum 목록에 쓸 유형
     */
    public function resolveOrderListType(?ClaimTypeEnum $requested, array $counts): ClaimTypeEnum
    {
        if ($requested !== null) {
            return $requested;
        }

        foreach (self::ORDER_LIST_TYPE_PRIORITY as $type) {
            if (($counts[$type->value] ?? 0) > 0) {
                return $type;
            }
        }

        return ClaimTypeEnum::EXCHANGE;
    }

    /**
     * 주문의 클레임 목록을 유형별로 전량 돌려줍니다. (관리자 주문상세 '클레임 내역' 섹션 — D20)
     *
     * 구매자 화면(비회원 주문상세 섹션)은 이 메서드를 쓰지 않는다 — 유형 하나를 페이지 단위로
     * 읽는 `listForOrderPaginated()` 가 그 자리다. 관리자 섹션은 유형 서브탭에 건수 뱃지를
     * 달아 네 유형을 한 응답으로 받으므로 이 형태가 남는다(상한은 저장소 `listByOrderId`).
     *
     * @param  Order  $order  주문
     * @return array<string, Collection> 유형 값 => 신청서 목록
     */
    public function listForOrder(Order $order): array
    {
        return [
            // `.orderOption` 은 표시 필드의 출처다 — 빼면 `ClaimItemResource` 의 `whenLoaded` 가
            // 정상 동작한 결과로 상품명·옵션명·썸네일이 오류 없이 전부 null 이 된다(회원 목록·상세는 싣는다).
            // 관계 목록은 회원 목록(저장소 `paginateForUser`)과 같아야 한다 — 하나라도 빠지면 그 필드가
            // 오류 없이 전 행 null 이 된다(`order_number`·회수/재발송 배송사명).
            ClaimTypeEnum::EXCHANGE->value => $this->exchangeRepository->listByOrderId($order->id, ['exchangeOptions.orderOption', 'exchangeOptions.collectionCarrier', 'exchangeOptions.redeliveryCarrier', 'images', 'order.shippingAddress']),
            ClaimTypeEnum::RETURN->value => $this->returnRepository->listByOrderId($order->id, ['returnOptions.orderOption', 'returnOptions.collectionCarrier', 'images', 'order.shippingAddress']),
            // 출처로 거르지 않는다(D6) — 취소신청서와 환불신청서는 같은 사건의 두 문서가
            // 아니라 **다른 사실**을 기록하는 별개 문서다. 반품 1건이 반품·환불·취소 세 탭에
            // 뜨는 것도 같은 이유로 정상이다(D14).
            ClaimTypeEnum::REFUND->value => $this->refundRepository->listByOrderId($order->id, ['refundOptions.orderOption', 'images', 'order.shippingAddress']),
            ClaimTypeEnum::CANCEL->value => $this->cancelRepository->listByOrderId($order->id, ['cancelOptions.orderOption', 'refund', 'order.shippingAddress']),
        ];
    }

    // ───────────────────────────────────────────────
    // 수정 / 철회
    // ───────────────────────────────────────────────

    /**
     * 승인 전 신청 내용을 수정합니다. (D13/D14 — 사유·상세 사유·첨부·교환 재발송지, 차액 정산)
     *
     * @param  ClaimTypeEnum  $type  유형
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  array  $payload  수정 페이로드
     * @param  array<int, int>  $addImageIds  귀속할 미귀속 첨부 ID
     * @param  array<int, int>  $removeImageIds  삭제할 첨부 ID
     * @param  int|null  $userId  수정 주체 회원 ID
     * @param  bool  $operator  운영자 수정 여부 (S15 — 판매자 귀책 사유 허용, 판매자 접수 건도 수정)
     * @return array{claim: OrderExchange|OrderReturn|OrderRefund, payment: ?ClaimPayment, payment_entry: ?array} 수정 결과
     *
     * @throws OrderClaimException 수정 불가·첨부 위반·환급 실패·기납부 청구의 차감 전환·판매자 접수 건의 고객 수정
     */
    public function update(ClaimTypeEnum $type, OrderExchange|OrderReturn|OrderRefund $claim, array $payload, array $addImageIds = [], array $removeImageIds = [], ?int $userId = null, bool $operator = false): array
    {
        $claim->loadMissing('order');
        $order = $claim->order;

        // 첨부 자격은 수정 본문을 쓰기 **전에** 본다 — 도메인 서비스는 자체 트랜잭션으로 커밋하고
        // 그 뒤의 삭제는 파일까지 지운다. 귀속 단계에서 거절하면 사유·금액 변경은 반영되고 첨부만
        // 영구히 사라진 채 422 가 나가, 사용자는 "수정 실패" 를 보면서 실제로는 바뀐 신청서를 갖는다.
        // 상한은 `attachPending()` 과 같은 등식이어야 하므로 삭제 후 남을 장수를 함께 넘긴다.
        $attached = $this->attachmentService->listFor($type, $claim->id);
        $removeIds = array_map('intval', $removeImageIds);

        // 고객이 지울 수 있는 것은 **자기가 올린** 첨부뿐이다. 운영자가 상세에서 대리로 올린
        // 사진(uploader_type=admin)과 주체 미상 사진은 "누가 낸 증거인가" 를 가르는 기록이라
        // 고객 수정으로 사라지면 안 된다. 섞여 오면 아무것도 쓰기 전에 거절한다.
        $notRemovable = $attached->filter(
            fn ($image) => in_array((int) $image->id, $removeIds, true)
                && $image->uploader_type !== ClaimImageUploaderTypeEnum::CUSTOMER
        );

        if ($notRemovable->isNotEmpty()) {
            throw OrderClaimException::imageNotRemovable();
        }

        $remainingAttached = $attached
            ->reject(fn ($image) => in_array((int) $image->id, $removeIds, true))
            ->count();

        $this->attachmentService->resolveAttachable($order, $addImageIds, $userId, $remainingAttached);

        if (($claim instanceof OrderExchange || $claim instanceof OrderReturn) && array_key_exists('charge_method', $payload)) {
            $this->assertChargeMethodChangeAllowed($claim, $payload['charge_method']);
        }

        $updated = match ($type) {
            ClaimTypeEnum::EXCHANGE => $this->exchangeService->updateRequest($claim, $payload, $operator),
            ClaimTypeEnum::RETURN => $this->returnService->updateRequest($claim, $payload, $operator),
            default => $this->refundRequestService->updateRequest($claim, $payload, $operator),
        };

        foreach ($this->attachmentService->listFor($type, $updated->id) as $image) {
            if (in_array((int) $image->id, array_map('intval', $removeImageIds), true)) {
                $this->attachmentService->delete($image);
            }
        }

        $this->attachmentService->attachPending($order, $addImageIds, $type, $updated->id, $userId);

        $payment = null;
        $entry = null;

        if ($updated instanceof OrderExchange || $updated instanceof OrderReturn) {
            $payment = $this->paymentService->settleChargeDifference($updated);

            if ($payment !== null && $payment->isPending()) {
                $entry = $this->paymentService->buildPaymentPayload($payment);
            }
        }

        return ['claim' => $updated->fresh(), 'payment' => $payment, 'payment_entry' => $entry];
    }

    /**
     * 청구 방식 변경이 이미 들어온 돈과 충돌하지 않는지 검증합니다. (관리자 금액 수정·구매자 수정 공용)
     *
     * 이미 결제한 청구를 **환불금 차감**으로 바꾸면 같은 청구를 두 번 받는다 — 결제로 한 번,
     * 완료 시 환불금에서 한 번. 차감 방식은 결제행을 보지 않으므로 전환 뒤의 정산
     * (`settleChargeDifference()`)도 기납부분을 돌려주지 않는다(#599 validate P2 #13).
     * 이미 차감 방식인 신청서의 재지정(no-op)은 막지 않는다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  mixed  $chargeMethod  바꾸려는 청구 방식 (Enum 또는 값, 비었으면 검사하지 않는다)
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 기납부 청구의 차감 전환
     */
    public function assertChargeMethodChangeAllowed(OrderExchange|OrderReturn $claim, mixed $chargeMethod): void
    {
        $target = $chargeMethod instanceof ClaimChargeMethodEnum
            ? $chargeMethod
            : ClaimChargeMethodEnum::tryFrom((string) $chargeMethod);

        if ($target !== ClaimChargeMethodEnum::DEDUCT_FROM_REFUND
            || $claim->charge_method === ClaimChargeMethodEnum::DEDUCT_FROM_REFUND) {
            return;
        }

        if ($this->paymentService->paidAmount($claim) > 0) {
            throw OrderClaimException::chargeMethodDeductAfterPayment();
        }
    }

    /**
     * 신청을 철회합니다. (기결제 건 환급 포함)
     *
     * @param  ClaimTypeEnum  $type  유형
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return OrderExchange|OrderReturn|OrderRefund 철회된 신청서
     *
     * @throws OrderClaimException 철회 불가 / 환급 실패
     */
    public function withdraw(ClaimTypeEnum $type, OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): OrderExchange|OrderReturn|OrderRefund
    {
        // 철회와 추가결제 환급은 한 트랜잭션이다(S6 · D2) — 환급이 거절되면 철회도 되돌려 다시 시도할 수 있게 한다.
        return DB::transaction(function () use ($type, $claim) {
            $withdrawn = match ($type) {
                ClaimTypeEnum::EXCHANGE => $this->exchangeService->withdraw($claim),
                ClaimTypeEnum::RETURN => $this->returnService->withdraw($claim),
                ClaimTypeEnum::REFUND => $this->refundRequestService->withdraw($claim),
                default => throw OrderClaimException::notWithdrawable(),
            };

            if ($withdrawn instanceof OrderExchange || $withdrawn instanceof OrderReturn) {
                $this->paymentService->refundPaymentsForClaim($withdrawn, __('sirsoft-ecommerce::messages.claims.withdrawn'));
            }

            return $withdrawn;
        });
    }

    // ───────────────────────────────────────────────
    // 결제
    // ───────────────────────────────────────────────

    /**
     * 결제번호로 열람자의 결제 건을 찾습니다.
     *
     * @param  string  $paymentNumber  결제번호 (CP)
     * @param  int|null  $userId  회원 ID
     * @param  Order|null  $guestOrder  비회원 주문
     * @return ClaimPayment 결제 건
     *
     * @throws OrderClaimException 부재·소유권 위반
     */
    public function findOwnedPayment(string $paymentNumber, ?int $userId, ?Order $guestOrder = null): ClaimPayment
    {
        $payment = $this->paymentService->findByNumber($paymentNumber);

        if ($payment === null) {
            throw OrderClaimException::paymentNotFound();
        }

        $payment->loadMissing('order');

        $owned = $guestOrder !== null
            ? (int) $payment->order_id === (int) $guestOrder->id
            : ($userId !== null && $payment->order !== null && (int) $payment->order->user_id === (int) $userId);

        if (! $owned) {
            throw OrderClaimException::paymentNotFound();
        }

        return $payment;
    }

    /**
     * 결제 대기 건의 결제를 시작합니다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  string|null  $paymentMethod  결제 수단
     * @param  int|null  $userId  회원 ID (마일리지 결제 주체)
     * @return array 결제 진입 결과 (`ClaimPaymentService::pay`)
     *
     * @throws OrderClaimException 결제 불가
     */
    public function pay(ClaimPayment $payment, ?string $paymentMethod, ?int $userId): array
    {
        return $this->paymentService->pay($payment, $paymentMethod, $userId);
    }

    /**
     * PG 콜백으로 결제를 확정합니다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  array  $callback  PG 콜백 파라미터
     * @return ClaimPayment 결제 완료 건
     *
     * @throws OrderClaimException 승인 실패
     */
    public function approve(ClaimPayment $payment, array $callback): ClaimPayment
    {
        return $this->paymentService->approvePgPayment($payment, $callback);
    }

    /**
     * 신청서의 결제 건 목록과 미수납액을 돌려줍니다. (상세 응답의 결제 블록)
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @return array{payments: Collection, outstanding_amount: float, pending_payment: ?ClaimPayment, payment_entry: ?array, available_payment_methods: array<int, string>, available_payment_method_labels: array<string, string>} 결제 블록
     */
    public function paymentBlock(OrderExchange|OrderReturn $claim): array
    {
        $payments = $this->paymentService->listForClaim($claim);
        $pending = $payments->first(fn (ClaimPayment $payment) => $payment->isPending());
        $availableMethods = $pending !== null
            ? $this->paymentService->availablePaymentMethodsFor($this->paymentService->payerUserIdOf($claim))
            : [];

        return [
            'payments' => $payments,
            'outstanding_amount' => $this->paymentService->outstandingAmount($claim),
            'pending_payment' => $pending,
            'payment_entry' => $pending ? $this->paymentService->buildPaymentPayload($pending) : null,
            // 대기 건의 수단은 접수 때 확정되지만 그 뒤에 낼 수 없게 될 수 있다 — 마일리지 잔액
            // 부족, 운영자가 그 수단을 내림. 그때 화면이 다른 수단을 고를 수 있어야 사용자가
            // 신청서를 철회하지 않고 빠져나간다(#599 3차 실측 F-1·F-2). 대기 건이 없으면 고를
            // 것도 없으므로 빈 배열이다.
            'available_payment_methods' => $availableMethods,
            'available_payment_method_labels' => $this->paymentMethodLabels($availableMethods),
        ];
    }

    // ───────────────────────────────────────────────
    // 내부 헬퍼
    // ───────────────────────────────────────────────

    /**
     * 접수된 교환·반품의 신청 시점 결제를 시작합니다.
     *
     * @param  OrderExchange|OrderReturn  $claim  접수된 신청서
     * @param  string|null  $paymentMethod  결제 수단
     * @param  int|null  $userId  신청자 회원 ID
     * @return array{claim: OrderExchange|OrderReturn, payment: ?ClaimPayment, payment_entry: ?array} 신청 결과
     */
    protected function enterPayment(OrderExchange|OrderReturn $claim, ?string $paymentMethod, ?int $userId): array
    {
        $payment = $this->paymentService->initiateForRequest($claim, $paymentMethod);
        $entry = null;

        if ($payment !== null) {
            $result = $this->paymentService->pay($payment, $paymentMethod, $userId);
            $payment = $result['payment'];
            $entry = $result['requires_pg_payment'] ? [
                'requires_pg_payment' => true,
                'pg_provider' => $result['pg_provider'],
                'pg_payment_handler' => $result['pg_payment_handler'],
                'pg_payment_data' => $result['pg_payment_data'],
            ] : ['requires_pg_payment' => false, 'pg_provider' => null, 'pg_payment_handler' => null, 'pg_payment_data' => null];
        }

        return ['claim' => $claim->fresh(), 'payment' => $payment, 'payment_entry' => $entry];
    }

    /**
     * 환불 우선순위 입력을 Enum 으로 해석합니다. (없으면 PG 우선)
     *
     * `Enum::from()` 은 알 수 없는 값에 `ValueError` 를 던져 500 이 된다 — 입력 오류는
     * 도메인 예외(4xx)로 거절한다(#599 validate P2 #10).
     *
     * @param  mixed  $value  입력값 (Enum · 문자열 · null)
     * @return RefundPriorityEnum 환불 우선순위
     *
     * @throws OrderClaimException 알 수 없는 값
     */
    protected function resolveRefundPriority(mixed $value): RefundPriorityEnum
    {
        if ($value instanceof RefundPriorityEnum) {
            return $value;
        }

        if ($value === null || $value === '') {
            return RefundPriorityEnum::PG_FIRST;
        }

        return RefundPriorityEnum::tryFrom((string) $value) ?? throw OrderClaimException::refundPriorityInvalid();
    }

    /**
     * 청구 방식 입력을 Enum 으로 해석합니다. (없으면 null)
     *
     * 교환·반품 서비스의 `normalizeChargeMethod()` 와 같은 거절(`claim_charge_method_not_allowed`)을
     * 낸다 — 미리보기와 접수가 같은 입력에 다른 오류를 내지 않게 한다(#599 validate P2 #10).
     *
     * @param  mixed  $value  입력값 (Enum · 문자열 · null)
     * @return ClaimChargeMethodEnum|null 청구 방식
     *
     * @throws OrderClaimException 알 수 없는 값
     */
    protected function resolveChargeMethod(mixed $value): ?ClaimChargeMethodEnum
    {
        if ($value instanceof ClaimChargeMethodEnum) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return ClaimChargeMethodEnum::tryFrom((string) $value) ?? throw OrderClaimException::chargeMethodNotAllowed();
    }

    /**
     * 설정의 값 목록을 문자열 배열로 읽습니다.
     *
     * @param  string  $key  설정 키
     * @param  array<string>  $default  기본값
     * @return array<string> 값 목록
     */
    protected function statusValues(string $key, array $default): array
    {
        return array_values(array_map('strval', (array) $this->settingsService->getSetting($key, $default)));
    }

    /**
     * 결제 수단마다 현재 언어의 표시명을 붙입니다.
     *
     * 화면은 값만 받으면 이름을 짐작해야 한다 — `point` 가 아니면 모두 「카드·간편결제」로
     * 그리던 탓에 계좌이체를 고르면서 카드로 낸다고 읽혔다(S1-D14). 이름은 결제수단
     * 카탈로그가 소유하므로 여기서 붙여 보낸다.
     *
     * @param  array<int, string>  $methods  결제 수단 ID 목록
     * @return array<string, string> 수단 ID → 표시명
     */
    protected function paymentMethodLabels(array $methods): array
    {
        $resolver = app(PaymentMethodResolver::class);
        $labels = [];

        foreach ($methods as $method) {
            $labels[$method] = $resolver->label($method);
        }

        return $labels;
    }
}
