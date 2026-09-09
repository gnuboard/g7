<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\DTO\CancellationResult;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
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
    ) {}

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
            'refund_account' => $this->refundAccountRequirement($order),
            'pending_images' => $this->attachmentService->listPendingFor($order),
        ];
    }

    /**
     * 주문의 금액 표기 기준 통화 코드를 돌려줍니다.
     *
     * 판정 규칙은 주문 리소스(`HasMultiCurrencyPrices::resolveOrderBaseCurrencyCode`)와 같다 —
     * 거래 시점에 동결된 스냅샷이 정본이고, 없으면 상점 기본 통화로 떨어진다. 두 곳이 갈리면
     * 같은 주문을 두 응답이 다른 통화로 말하게 되므로 규칙을 그대로 맞춘다.
     *
     * @param  Order  $order  주문
     * @return string 기준 통화 코드
     */
    protected function orderBaseCurrency(Order $order): string
    {
        $snapshot = $order->currency_snapshot;

        if (is_array($snapshot) && filled($snapshot['base_currency'] ?? null)) {
            return (string) $snapshot['base_currency'];
        }

        return (string) ($this->settingsService->getSetting('language_currency.default_currency') ?: 'KRW');
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
        $required = $this->refundRequestService->refundsToBankAccount($order);

        return [
            'required' => $required,
            'prefill' => $required ? $this->registeredRefundAccount($order) : null,
            // 은행 목록은 운영자가 주문설정에서 관리하는 값이다 — 화면에 코드표를 두면 운영자가
            // 취급 은행을 바꿔도 신청서만 옛 목록으로 남는다. 필요하지 않은 주문에는 싣지 않는다.
            'banks' => $required
                ? array_values(array_map(
                    fn (array $bank) => ['code' => (string) ($bank['code'] ?? ''), 'name' => $bank['name'] ?? ($bank['code'] ?? '')],
                    array_filter(
                        (array) $this->settingsService->getSetting('order_settings.banks', []),
                        fn ($bank) => is_array($bank) && filled($bank['code'] ?? null),
                    ),
                ))
                : [],
        ];
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
            'max_images' => $this->attachmentService->maxImages(),
            'image_max_size_kb' => $this->attachmentService->maxSizeKb(),
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
     * @throws OrderClaimException 신청 자격·사유 위반
     */
    public function preview(Order $order, ClaimTypeEnum $type, array $input, ?int $excludeClaimId = null): array
    {
        $items = $input['items'] ?? [];
        $refundPriority = ($input['refund_priority'] ?? null) instanceof RefundPriorityEnum
            ? $input['refund_priority']
            : (isset($input['refund_priority']) ? RefundPriorityEnum::from((string) $input['refund_priority']) : RefundPriorityEnum::PG_FIRST);
        $chargeMethod = isset($input['charge_method']) && $input['charge_method'] !== '' && $input['charge_method'] !== null
            ? ClaimChargeMethodEnum::from((string) $input['charge_method'])
            : null;

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

        $return = $this->returnService->requestReturn($order, $payload);
        $this->attachmentService->attachPending($order, $imageIds, ClaimTypeEnum::RETURN, $return->id, $payload['requested_by'] ?? null);

        return $this->enterPayment($return, $paymentMethod, $payload['requested_by'] ?? null);
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
        // 교환·반품과 같은 이유로 선행 검사한다. 즉시취소 분기는 신청서가 생기지 않지만, 그 분기도
        // 취소 파이프라인을 되돌릴 수는 없으므로 첨부가 잘못된 요청은 파이프라인 전에 걸러야 한다.
        $this->attachmentService->resolveAttachable($order, $imageIds, $payload['requested_by'] ?? null);

        $result = $this->refundRequestService->request($order, $payload);

        if ($result['refund'] instanceof OrderRefund) {
            $this->attachmentService->attachPending($order, $imageIds, ClaimTypeEnum::REFUND, $result['refund']->id, $payload['requested_by'] ?? null);
        } else {
            foreach ($this->attachmentService->listPendingFor($order) as $image) {
                if (in_array((int) $image->id, array_map('intval', $imageIds), true)) {
                    $this->attachmentService->delete($image);
                }
            }
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
                    $isCancel => ClaimTypeEnum::REFUND->value,
                    $claim instanceof OrderRefund => $claim->reasonCatalogType(),
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
     * 주문의 유형별 접수 건수를 돌려줍니다. (주문상세 섹션의 서브탭 뱃지·기본 유형 판정)
     *
     * 건수는 목록과 같은 저장소 기준 쿼리로 세므로(`countForOrder`) 환불 탭의 취소 발 제외가
     * 뱃지와 목록에 같은 근거로 걸린다. 네 유형 전부를 늘 돌려준다 — 화면이 키 부재를 0 으로
     * 읽으면 "세지 않음" 과 "0건" 이 구분되지 않는다.
     *
     * @param  Order  $order  주문
     * @return array<string, int> 유형 값 => 건수 (네 유형 전부)
     */
    public function countsForOrder(Order $order): array
    {
        return [
            ClaimTypeEnum::EXCHANGE->value => $this->exchangeRepository->countForOrder($order->id),
            ClaimTypeEnum::RETURN->value => $this->returnRepository->countForOrder($order->id),
            ClaimTypeEnum::REFUND->value => $this->refundRepository->countForOrder($order->id),
            ClaimTypeEnum::CANCEL->value => $this->cancelRepository->countForOrder($order->id),
        ];
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
            ClaimTypeEnum::EXCHANGE->value => $this->exchangeRepository->listByOrderId($order->id, ['exchangeOptions.orderOption', 'images', 'order.shippingAddress', 'collectionCarrier', 'redeliveryCarrier']),
            ClaimTypeEnum::RETURN->value => $this->returnRepository->listByOrderId($order->id, ['returnOptions.orderOption', 'images', 'order.shippingAddress', 'collectionCarrier']),
            ClaimTypeEnum::REFUND->value => $this->refundRepository->listByOrderId($order->id, ['refundOptions.orderOption', 'images', 'order.shippingAddress'])
                ->filter(fn (OrderRefund $refund) => $refund->refund_source_type !== RefundSourceTypeEnum::CANCEL)
                ->values(),
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
     * @return array{claim: OrderExchange|OrderReturn|OrderRefund, payment: ?ClaimPayment, payment_entry: ?array} 수정 결과
     *
     * @throws OrderClaimException 수정 불가·첨부 위반·환급 실패
     */
    public function update(ClaimTypeEnum $type, OrderExchange|OrderReturn|OrderRefund $claim, array $payload, array $addImageIds = [], array $removeImageIds = [], ?int $userId = null): array
    {
        $claim->loadMissing('order');
        $order = $claim->order;

        // 첨부 자격은 수정 본문을 쓰기 **전에** 본다 — 도메인 서비스는 자체 트랜잭션으로 커밋하고
        // 그 뒤의 삭제는 파일까지 지운다. 귀속 단계에서 거절하면 사유·금액 변경은 반영되고 첨부만
        // 영구히 사라진 채 422 가 나가, 사용자는 "수정 실패" 를 보면서 실제로는 바뀐 신청서를 갖는다.
        // 상한은 `attachPending()` 과 같은 등식이어야 하므로 삭제 후 남을 장수를 함께 넘긴다.
        $remainingAttached = $this->attachmentService->listFor($type, $claim->id)
            ->reject(fn ($image) => in_array((int) $image->id, array_map('intval', $removeImageIds), true))
            ->count();

        $this->attachmentService->resolveAttachable($order, $addImageIds, $userId, $remainingAttached);

        $updated = match ($type) {
            ClaimTypeEnum::EXCHANGE => $this->exchangeService->updateRequest($claim, $payload),
            ClaimTypeEnum::RETURN => $this->returnService->updateRequest($claim, $payload),
            default => $this->refundRequestService->updateRequest($claim, $payload),
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
     * @return array{payments: Collection, outstanding_amount: float, pending_payment: ?ClaimPayment, payment_entry: ?array} 결제 블록
     */
    public function paymentBlock(OrderExchange|OrderReturn $claim): array
    {
        $payments = $this->paymentService->listForClaim($claim);
        $pending = $payments->first(fn (ClaimPayment $payment) => $payment->isPending());

        return [
            'payments' => $payments,
            'outstanding_amount' => $this->paymentService->outstandingAmount($claim),
            'pending_payment' => $pending,
            'payment_entry' => $pending ? $this->paymentService->buildPaymentPayload($pending) : null,
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
}
