<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptTransactionType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCashReceipt;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCashReceiptRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPaymentRepositoryInterface;

/**
 * 현금영수증 발급/취소 오케스트레이션 서비스
 *
 * 이 서비스는 "왜" 금액이 바뀌었는지 알지 못한다. 부분환불이든 반품배송비 청구든 추가상품이든,
 * 호출부는 syncFromOrder($order) 하나만 부르고 서비스는 주문의 현재 상태에서 발급액을 다시 계산한다.
 * 이 무상태 설계 덕분에 향후 금액 증가 흐름이 도입되어도 본 코드는 수정 없이 동작한다.
 *
 * 재발급은 항상 "전체취소 → 전액 재발급" 단일 경로다. 부분취소 API 를 호출하지 않으므로
 * 전액취소만 지원하는 벤더(NHN KCP·페이레터)도 동일 인터페이스로 수용된다.
 */
class CashReceiptService
{
    /**
     * 원응답에서 마스킹할 민감 키 목록 (대소문자 무시, 부분 일치)
     *
     * @var array<int, string>
     */
    private const SENSITIVE_RESPONSE_KEYS = [
        'customeridentitynumber',
        'identitynumber',
        'identifier',
        'customermobilephone',
        'mobilephone',
        'phone',
        'businessnumber',
        'customername',
        'customeremail',
        'email',
        'secret',
        'secretkey',
        'password',
    ];

    public function __construct(
        private OrderCashReceiptRepositoryInterface $repository,
        private OrderPaymentRepositoryInterface $paymentRepository,
        private EcommerceSettingsService $settingsService,
        private CurrencyConversionService $currencyConversionService,
    ) {}

    /**
     * 현금영수증을 발급합니다.
     *
     * @param  Order  $order  주문 모델
     * @param  CashReceiptType  $type  발급 용도
     * @param  string  $identifier  식별번호 원본 (하이픈 제거)
     * @param  CashReceiptIdentifierType|null  $identifierType  식별번호 종류 (미지정 시 추론)
     * @return OrderCashReceipt 생성된 발급 이력 (실패 시 issue_status=FAILED)
     */
    public function issue(
        Order $order,
        CashReceiptType $type,
        string $identifier,
        ?CashReceiptIdentifierType $identifierType = null,
    ): OrderCashReceipt {
        $provider = $this->resolveProvider();
        $payment = $order->payment;

        if ($provider === null) {
            return $this->recordFailure(
                $order,
                $payment,
                CashReceiptTransactionType::ISSUE,
                $type,
                'PROVIDER_NOT_CONFIGURED',
                __('sirsoft-ecommerce::cash_receipt.errors.provider_not_configured'),
                $identifier,
            );
        }

        // 멱등: 이미 활성 영수증이 있으면 중복 발급하지 않는다.
        // 자동발급 리스너가 두 훅(after_payment_complete / after_deposit_recorded)에 동시 구독되어
        // 한 입금에 두 번 도달할 수 있고, 관리자/유저가 동시에 발급을 눌러도 여기서 걸린다.
        $existing = $this->repository->findActiveReceipt($order);

        if ($existing !== null) {
            return $existing;
        }

        $target = $this->calculateIssuableAmount($order);

        if ($target['amount'] <= 0) {
            return $this->recordFailure(
                $order,
                $payment,
                CashReceiptTransactionType::ISSUE,
                $type,
                'NO_ISSUABLE_AMOUNT',
                __('sirsoft-ecommerce::cash_receipt.errors.no_issuable_amount'),
                $identifier,
                $provider,
            );
        }

        $payload = [
            'type' => $type->value,
            'identifier' => $identifier,
            'identifier_type' => ($identifierType ?? $this->inferIdentifierType($identifier))?->value,
            'amount' => $target['amount'],
            'tax_free_amount' => $target['tax_free_amount'],
            'order_name' => $this->buildOrderName($order),
            'order_number' => $order->order_number,
            // 이번 발급의 회차 (1부터). 프로바이더가 발급 요청 식별자를 회차로 구분할 때 사용한다.
            // 토스는 동일 orderId 재사용을 중복 거부하므로 "{order_number}-cr{seq}" 로 접미한다.
            // 프로바이더 종속 문자열 조립은 리스너가 하고, 코어는 숫자만 제공한다.
            'issue_sequence' => $this->repository->countIssues($order) + 1,
        ];

        $result = HookManager::applyFilters(
            'sirsoft-ecommerce.cash_receipt.issue',
            $this->defaultIssueResult(),
            $order,
            $provider,
            $payload,
        );

        $succeeded = (bool) ($result['success'] ?? false);

        $receipt = $this->repository->create([
            'order_id' => $order->id,
            'order_payment_id' => $payment?->id,
            'provider' => $provider,
            'receipt_key' => $result['receipt_key'] ?? null,
            'transaction_type' => CashReceiptTransactionType::ISSUE->value,
            'receipt_type' => $type->value,
            'amount' => $target['amount'],
            'tax_free_amount' => $target['tax_free_amount'],
            'identifier_masked' => $this->maskIdentifier($identifier),
            'receipt_url' => $result['receipt_url'] ?? null,
            'issue_number' => $result['issue_number'] ?? null,
            'issue_status' => $succeeded
                ? CashReceiptIssueStatus::COMPLETED->value
                : CashReceiptIssueStatus::FAILED->value,
            'error_code' => $succeeded ? null : ($result['error_code'] ?? null),
            'error_message' => $succeeded ? null : ($result['error_message'] ?? null),
            'issued_at' => $succeeded ? now() : null,
            'raw_response' => $this->sanitizeResponse($result['raw_response'] ?? null),
        ]);

        if ($succeeded) {
            $this->syncPaymentSummary($payment, $receipt, $type, $identifier, $identifierType);
        } else {
            Log::warning('[cash_receipt] 발급 실패', [
                'order_id' => $order->id,
                'provider' => $provider,
                'error_code' => $result['error_code'] ?? null,
            ]);
        }

        return $receipt;
    }

    /**
     * 주문의 활성 현금영수증을 모두 전액취소합니다.
     *
     * 부분취소 API 는 사용하지 않는다 (취소 금액 파라미터 없음).
     *
     * @param  Order  $order  주문 모델
     * @param  string|null  $reason  취소 사유
     * @return bool 활성 영수증 전부가 취소 성공했는지 여부 (취소 대상 없으면 true)
     */
    public function cancelAll(Order $order, ?string $reason = null): bool
    {
        $activeReceipts = $this->repository->findActiveReceipts($order);

        if ($activeReceipts->isEmpty()) {
            return true;
        }

        $payment = $order->payment;
        $allSucceeded = true;

        foreach ($activeReceipts as $active) {
            $provider = $active->provider;
            $receiptKey = (string) $active->receipt_key;

            $result = HookManager::applyFilters(
                'sirsoft-ecommerce.cash_receipt.cancel',
                $this->defaultCancelResult(),
                $order,
                $provider,
                $receiptKey,
            );

            $succeeded = (bool) ($result['success'] ?? false);
            $allSucceeded = $allSucceeded && $succeeded;

            $this->repository->create([
                'order_id' => $order->id,
                'order_payment_id' => $payment?->id,
                'provider' => $provider,
                'receipt_key' => $result['receipt_key'] ?? $receiptKey,
                'transaction_type' => CashReceiptTransactionType::CANCEL->value,
                'receipt_type' => $active->receipt_type->value,
                'amount' => (int) $active->amount,
                'tax_free_amount' => (int) $active->tax_free_amount,
                'identifier_masked' => $active->identifier_masked,
                'receipt_url' => null,
                'issue_number' => null,
                'issue_status' => $succeeded
                    ? CashReceiptIssueStatus::COMPLETED->value
                    : CashReceiptIssueStatus::FAILED->value,
                'error_code' => $succeeded ? null : ($result['error_code'] ?? null),
                'error_message' => $succeeded ? null : ($result['error_message'] ?? $reason),
                'issued_at' => null,
                'raw_response' => $this->sanitizeResponse($result['raw_response'] ?? null),
            ]);

            if (! $succeeded) {
                Log::warning('[cash_receipt] 취소 실패', [
                    'order_id' => $order->id,
                    'provider' => $provider,
                    'error_code' => $result['error_code'] ?? null,
                ]);
            }
        }

        if ($allSucceeded) {
            $this->clearPaymentSummary($payment);
        }

        return $allSucceeded;
    }

    /**
     * 주문 금액 변동 시 현금영수증을 현재 상태에 맞춰 동기화합니다.
     *
     * 금액을 바꾸는 모든 지점(부분환불, 추가입금, 향후 반품/교환배송비 청구)이 호출하는
     * 유일한 진입점이다. 증가/감소를 구분하지 않는다.
     *
     * 실패해도 예외를 던지지 않는다 — 환불 트랜잭션을 롤백시킬 사유가 아니기 때문이다.
     * 실패는 이력 테이블에 FAILED 로 남아 관리자 화면에 노출되고 수동 재발급으로 복구된다.
     *
     * @param  Order  $order  주문 모델
     * @param  string|null  $reason  변동 사유 (취소 이력에 기록)
     */
    public function syncFromOrder(Order $order, ?string $reason = null): void
    {
        $active = $this->repository->findActiveReceipt($order);

        // 발급 이력이 없으면 아무것도 하지 않는다 (신청하지 않은 주문).
        if ($active === null) {
            return;
        }

        $target = $this->calculateIssuableAmount($order);

        // 멱등: 변동이 없으면 no-op
        if ($active->matchesAmounts($target['amount'], $target['tax_free_amount'])) {
            return;
        }

        // 전액 취소/전액 마일리지 등으로 발급 대상이 사라진 경우
        if ($target['amount'] <= 0) {
            $this->cancelAll($order, $reason);

            return;
        }

        $type = $active->receipt_type;
        $identifier = $this->resolveStoredIdentifier($order);

        // 암호문이 없거나 복호화에 실패하면 재발급을 시도하지 않는다 (조용한 실패 금지).
        if ($identifier === null) {
            $this->recordFailure(
                $order,
                $order->payment,
                CashReceiptTransactionType::ISSUE,
                $type,
                'IDENTIFIER_UNAVAILABLE',
                __('sirsoft-ecommerce::cash_receipt.errors.identifier_unavailable'),
                null,
                $active->provider,
            );

            Log::warning('[cash_receipt] 재발급용 식별번호 복호화 불가 — 관리자 수동 재발급 필요', [
                'order_id' => $order->id,
            ]);

            return;
        }

        if (! $this->cancelAll($order, $reason)) {
            // 취소가 실패하면 재발급하지 않는다 — 중복 영수증 발행 방지.
            return;
        }

        $this->issue($order, $type, $identifier, $order->payment?->cash_receipt_identifier_type);
    }

    /**
     * "취소 성공 + 재발급 실패" 중간 상태를 복구합니다. (관리자 수동 재발급)
     *
     * syncFromOrder() 는 활성 영수증이 있을 때만 동작한다 — 재발급이 실패해 활성 영수증이
     * 사라진 상태는 그 메서드의 관심사가 아니다. 이 메서드가 그 구멍을 메운다:
     * 발급 이력은 있는데 활성 영수증이 없고 발급 대상 금액이 남아 있으면, 마지막 발급 이력의
     * 용도와 저장된 암호문으로 다시 발급한다.
     *
     * 활성 영수증이 이미 있으면 금액 동기화(syncFromOrder)로 위임한다.
     *
     * @param  Order  $order  주문 모델
     * @param  string|null  $reason  사유
     * @return OrderCashReceipt|null 복구된 영수증 (복구 대상이 없거나 실패 시 null)
     */
    public function recoverFailedIssue(Order $order, ?string $reason = null): ?OrderCashReceipt
    {
        // 활성 영수증이 있으면 금액 변동 동기화가 올바른 경로다.
        if ($this->repository->findActiveReceipt($order) !== null) {
            $this->syncFromOrder($order, $reason);

            return $this->repository->findActiveReceipt($order);
        }

        // 발급 대상 금액이 없으면 복구할 것이 없다 (전액 환불 등).
        if ($this->calculateIssuableAmount($order)['amount'] <= 0) {
            return null;
        }

        $lastIssue = $this->repository->findByOrder($order)
            ->first(fn (OrderCashReceipt $row) => $row->isIssue());

        // 애초에 발급을 시도한 적이 없으면 복구가 아니라 신규 발급이다 (issue() 를 쓰라).
        if ($lastIssue === null) {
            return null;
        }

        $identifier = $this->resolveStoredIdentifier($order);

        if ($identifier === null) {
            $this->recordFailure(
                $order,
                $order->payment,
                CashReceiptTransactionType::ISSUE,
                $lastIssue->receipt_type,
                'IDENTIFIER_UNAVAILABLE',
                __('sirsoft-ecommerce::cash_receipt.errors.identifier_unavailable'),
                null,
                $lastIssue->provider,
            );

            Log::warning('[cash_receipt] 복구용 식별번호 복호화 불가 — 관리자가 식별번호를 재입력해야 함', [
                'order_id' => $order->id,
            ]);

            return null;
        }

        $receipt = $this->issue(
            $order,
            $lastIssue->receipt_type,
            $identifier,
            $order->payment?->cash_receipt_identifier_type,
        );

        return $receipt->isCompletedIssue() ? $receipt : null;
    }

    /**
     * 현재 상태 기준 발급 가능 금액을 계산합니다.
     *
     * 주문 테이블의 재계산된 금액을 그대로 사용한다. 부분취소 시 OrderAdjustmentService 가
     * total_amount / total_tax_free_amount 를 잔여분 기준으로 갱신하므로, 본 메서드는
     * "지금 이 주문이 얼마짜리인가"만 읽으면 된다.
     *
     * 현금성 금액(total_cash_equivalent_amount)은 무통장 주문의 실입금액이며,
     * 마일리지 사용분은 현금이 아니므로 제외되어 있다.
     *
     * @param  Order  $order  주문 모델
     * @return array{amount: int, tax_free_amount: int} 발급액과 면세액
     */
    public function calculateIssuableAmount(Order $order): array
    {
        $cashEquivalent = $this->toPaymentCurrencyAmount($order, (float) $order->total_cash_equivalent_amount);

        if ($cashEquivalent <= 0) {
            return ['amount' => 0, 'tax_free_amount' => 0];
        }

        $taxFree = $this->toPaymentCurrencyAmount($order, (float) $order->total_tax_free_amount);
        $taxable = $this->toPaymentCurrencyAmount($order, (float) $order->total_tax_amount);
        $classified = $taxFree + $taxable;

        // 과세/면세 분류 합이 현금성 금액과 다르면(마일리지 사용, 할인 등) 면세 비율로 안분한다.
        // 반올림 잔차는 과세 쪽에 귀속시켜 합계를 보존한다.
        if ($classified <= 0) {
            return ['amount' => $cashEquivalent, 'tax_free_amount' => 0];
        }

        $taxFreeShare = $classified === $cashEquivalent
            ? $taxFree
            : (int) round($cashEquivalent * $taxFree / $classified);

        $taxFreeShare = max(0, min($taxFreeShare, $cashEquivalent));

        return [
            'amount' => $cashEquivalent,
            'tax_free_amount' => $taxFreeShare,
        ];
    }

    /**
     * base 통화 금액을 결제 통화(order_currency) 기준 청구액으로 환산합니다.
     *
     * 현금영수증은 구매자가 **실제로 낸 금액**으로 발행되어야 한다. 주문 테이블의 금액은
     * base 통화 기준이므로, base≠결제 통화 주문에서 그대로 쓰면 실청구액과 다른 금액이
     * 국세청에 신고된다(화면 표시 오류가 아니라 증빙 오류다).
     *
     * 환산은 결제·입금 검증과 같은 해석기(`resolveSnapshotPaymentCharge`)를 쓴다 —
     * 각자 계산하면 반올림·절사 규칙이 어긋나는 순간 조용히 갈라진다.
     * base == 결제 통화면 환산이 항등이라 단일통화 상점의 값은 종전과 같다.
     *
     * @param  Order  $order  주문
     * @param  float  $baseAmount  base 통화 금액
     * @return int 결제 통화 최소 화폐단위 금액 (환율 스냅샷 손상 시 base 금액으로 폴백)
     */
    protected function toPaymentCurrencyAmount(Order $order, float $baseAmount): int
    {
        if ($baseAmount <= 0) {
            return 0;
        }

        try {
            return $this->currencyConversionService->resolveSnapshotPaymentCharge(
                $baseAmount,
                $order->currency_snapshot ?? []
            )['minor_unit_amount'];
        } catch (\Throwable $e) {
            Log::warning('현금영수증 발급액 환산 실패 — base 금액으로 폴백', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return (int) round($baseAmount);
        }
    }

    /**
     * 설정된 현금영수증 발급 프로바이더 ID를 반환합니다.
     *
     * @return string|null 프로바이더 ID (미설정 시 null)
     */
    public function resolveProvider(): ?string
    {
        return $this->settingsService->getCashReceiptProvider();
    }

    /**
     * 주문의 현재 활성 영수증을 반환합니다.
     *
     * @param  Order  $order  주문 모델
     * @return OrderCashReceipt|null 활성 영수증 (없으면 null)
     */
    public function findActiveReceipt(Order $order): ?OrderCashReceipt
    {
        return $this->repository->findActiveReceipt($order);
    }

    /**
     * 지금 이 주문에 현금영수증을 발급할 수 있는지 판정합니다.
     *
     * 자동발급 리스너와 관리자/유저 발급 API 가 공유하는 단일 가드다.
     * 어느 조건에서 막혔는지 호출부가 응답 코드를 정하도록 사유 문자열을 반환한다.
     *
     * 발급액 0원 방어: $isZeroPayable 판정은 결제수단과 무관하게 잔여 결제액만 보므로
     * "무통장 + 전액 마일리지" 0원 주문도 결제완료 훅을 발화시킨다. 그 주문에 0원 영수증을
     * 발급하지 않도록 현금성 금액을 명시적으로 확인한다 (현금성 필드의 암묵적 귀결에 의존하지 않는다).
     *
     * @param  Order  $order  주문 모델
     * @return string|null 발급 불가 사유 코드 (발급 가능하면 null)
     */
    public function resolveIssueBlocker(Order $order): ?string
    {
        if ($this->resolveProvider() === null) {
            return 'PROVIDER_NOT_CONFIGURED';
        }

        $payment = $order->payment;

        if ($payment === null) {
            return 'PAYMENT_NOT_FOUND';
        }

        if (! $payment->isBankTransfer()) {
            return 'NOT_CASH_PAYMENT';
        }

        if ($payment->payment_status !== PaymentStatusEnum::PAID) {
            return 'PAYMENT_NOT_PAID';
        }

        if ($this->repository->findActiveReceipt($order) !== null) {
            return 'ALREADY_ISSUED';
        }

        if ($this->calculateIssuableAmount($order)['amount'] <= 0) {
            return 'NO_ISSUABLE_AMOUNT';
        }

        return null;
    }

    /**
     * 발급 불가 사유 코드에 대응하는 다국어 메시지 키를 반환합니다.
     *
     * ResponseHelper 가 직접 번역하도록 해석된 문자열이 아닌 키를 반환한다.
     *
     * @param  string  $blocker  사유 코드
     * @return string 다국어 메시지 키
     */
    public function describeIssueBlocker(string $blocker): string
    {
        return 'sirsoft-ecommerce::cash_receipt.errors.'.strtolower($blocker);
    }

    /**
     * 재발급용 식별번호 암호문을 폐기합니다. (구매확정 시점)
     *
     * 이력·receipt_url·마스킹 값은 유지된다 (국세청 신고 근거).
     * 이미 폐기된 경우 아무것도 하지 않는다 (멱등).
     *
     * @param  Order  $order  주문 모델
     */
    public function purgeIdentifier(Order $order): void
    {
        $payment = $order->payment;

        if ($payment === null) {
            return;
        }

        $this->paymentRepository->purgeCashReceiptIdentifier($payment);
    }

    /**
     * 저장된 암호문에서 식별번호 원본을 복호화합니다.
     *
     * @param  Order  $order  주문 모델
     * @return string|null 식별번호 원본 (부재/복호화 실패 시 null)
     */
    private function resolveStoredIdentifier(Order $order): ?string
    {
        $payment = $order->payment;

        if ($payment === null) {
            return null;
        }

        return $this->paymentRepository->resolveCashReceiptIdentifier($payment);
    }

    /**
     * 실패 이력을 기록합니다.
     *
     * @param  Order  $order  주문 모델
     * @param  OrderPayment|null  $payment  결제 모델
     * @param  CashReceiptTransactionType  $transactionType  거래 유형
     * @param  CashReceiptType  $type  발급 용도
     * @param  string  $errorCode  에러코드
     * @param  string  $errorMessage  에러 메시지
     * @param  string|null  $identifier  식별번호 원본 (마스킹해 저장)
     * @param  string|null  $provider  프로바이더 ID
     * @return OrderCashReceipt 생성된 실패 이력
     */
    private function recordFailure(
        Order $order,
        ?OrderPayment $payment,
        CashReceiptTransactionType $transactionType,
        CashReceiptType $type,
        string $errorCode,
        string $errorMessage,
        ?string $identifier,
        ?string $provider = null,
    ): OrderCashReceipt {
        return $this->repository->create([
            'order_id' => $order->id,
            'order_payment_id' => $payment?->id,
            'provider' => $provider ?? '',
            'receipt_key' => null,
            'transaction_type' => $transactionType->value,
            'receipt_type' => $type->value,
            'amount' => 0,
            'tax_free_amount' => 0,
            'identifier_masked' => $identifier !== null ? $this->maskIdentifier($identifier) : null,
            'receipt_url' => null,
            'issue_number' => null,
            'issue_status' => CashReceiptIssueStatus::FAILED->value,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'issued_at' => null,
            'raw_response' => null,
        ]);
    }

    /**
     * 발급 성공 시 결제 테이블의 요약 컬럼을 갱신합니다.
     *
     * @param  OrderPayment|null  $payment  결제 모델
     * @param  OrderCashReceipt  $receipt  발급 이력
     * @param  CashReceiptType  $type  발급 용도
     * @param  string  $identifier  식별번호 원본
     * @param  CashReceiptIdentifierType|null  $identifierType  식별번호 종류
     */
    private function syncPaymentSummary(
        ?OrderPayment $payment,
        OrderCashReceipt $receipt,
        CashReceiptType $type,
        string $identifier,
        ?CashReceiptIdentifierType $identifierType,
    ): void {
        if ($payment === null) {
            return;
        }

        $this->paymentRepository->markCashReceiptIssued(
            $payment,
            $receipt,
            $type,
            $identifier,
            $this->maskIdentifier($identifier),
            $identifierType ?? $this->inferIdentifierType($identifier),
        );
    }

    /**
     * 전액취소 성공 시 결제 테이블의 요약 컬럼을 초기화합니다.
     *
     * 암호문은 유지한다 — 전액취소 후 관리자가 같은 식별번호로 재발급할 수 있어야 하고,
     * 구매확정(purgeIdentifier) 시점에 일괄 폐기된다.
     *
     * @param  OrderPayment|null  $payment  결제 모델
     */
    private function clearPaymentSummary(?OrderPayment $payment): void
    {
        if ($payment === null) {
            return;
        }

        $this->paymentRepository->markCashReceiptCancelled($payment);
    }

    /**
     * 식별번호를 마스킹합니다 (뒤 4자리만 노출).
     *
     * 마스킹 규칙의 SSoT 는 Enum 이다 — 주문 생성 시점(신청 저장)과 발급 시점(이력 기록)이
     * 동일한 마스킹을 써야 하므로 규칙을 복제하지 않고 위임한다.
     *
     * @param  string  $identifier  식별번호 원본
     * @return string 마스킹된 식별번호
     */
    private function maskIdentifier(string $identifier): string
    {
        return CashReceiptIdentifierType::mask($identifier);
    }

    /**
     * 식별번호 형식으로 종류를 추론합니다.
     *
     * @param  string  $identifier  식별번호 원본
     * @return CashReceiptIdentifierType|null 추론된 종류 (판별 불가 시 null)
     */
    private function inferIdentifierType(string $identifier): ?CashReceiptIdentifierType
    {
        foreach ([
            CashReceiptIdentifierType::PHONE,
            CashReceiptIdentifierType::BUSINESS,
            CashReceiptIdentifierType::CARD,
        ] as $candidate) {
            if ($candidate->matches($identifier)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 발급 요청에 사용할 주문명을 생성합니다.
     *
     * @param  Order  $order  주문 모델
     * @return string 주문명
     */
    private function buildOrderName(Order $order): string
    {
        return $order->payment?->payment_name
            ?? (string) $order->order_number;
    }

    /**
     * 프로바이더 원응답에서 민감 키를 마스킹합니다.
     *
     * 프로젝트 결정: 전체 응답을 보관하되 알려진 민감 키를 재귀적으로 가린다.
     *
     * @param  mixed  $response  원응답
     * @return array<string, mixed>|null 마스킹된 응답 (배열이 아니면 null)
     */
    private function sanitizeResponse(mixed $response): ?array
    {
        if (! is_array($response)) {
            return null;
        }

        return $this->maskSensitiveKeys($response);
    }

    /**
     * 배열을 재귀 순회하며 민감 키의 값을 마스킹합니다.
     *
     * @param  array<mixed, mixed>  $data  원본 배열
     * @return array<mixed, mixed> 마스킹된 배열
     */
    private function maskSensitiveKeys(array $data): array
    {
        $masked = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $masked[$key] = $this->maskSensitiveKeys($value);

                continue;
            }

            $masked[$key] = $this->isSensitiveKey((string) $key) ? '***' : $value;
        }

        return $masked;
    }

    /**
     * 민감 키인지 확인합니다.
     *
     * @param  string  $key  키 이름
     * @return bool 민감 키 여부
     */
    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['_', '-'], '', $key));

        foreach (self::SENSITIVE_RESPONSE_KEYS as $sensitive) {
            if (str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 발급 필터 훅의 기본 반환값을 생성합니다.
     *
     * @return array<string, mixed> 기본 반환값
     */
    private function defaultIssueResult(): array
    {
        return [
            'success' => false,
            'error_code' => 'NO_PROVIDER_HANDLED',
            'error_message' => __('sirsoft-ecommerce::cash_receipt.errors.no_provider_handled'),
            'receipt_key' => null,
            'receipt_url' => null,
            'issue_number' => null,
            'raw_response' => null,
        ];
    }

    /**
     * 취소 필터 훅의 기본 반환값을 생성합니다.
     *
     * @return array<string, mixed> 기본 반환값
     */
    private function defaultCancelResult(): array
    {
        return [
            'success' => false,
            'error_code' => 'NO_PROVIDER_HANDLED',
            'error_message' => __('sirsoft-ecommerce::cash_receipt.errors.no_provider_handled'),
            'receipt_key' => null,
            'raw_response' => null,
        ];
    }
}
