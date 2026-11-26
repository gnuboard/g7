<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptTransactionType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\CashReceiptConversionException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCashReceipt;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCashReceiptRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\Concerns\MasksSensitiveResponse;
use Modules\Sirsoft\Ecommerce\Support\ConcurrencyErrors;

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
    use MasksSensitiveResponse;

    /**
     * 처리 중(IN_PROGRESS) 예약 행을 「오래됐다」 고 보는 기준 (분)
     *
     * 이보다 오래된 처리 중 행은 PG 응답을 끝내 받지 못한 것으로 보고 실패(IN_PROGRESS_STALE)로 정리한다.
     * 자동 재발급은 하지 않는다 — PG 에서는 실제로 발급됐을 수 있고 KG이니시스 발급 API 에는 멱등키가 없다.
     */
    public const IN_PROGRESS_STALE_MINUTES = 10;

    /** 오래된 처리 중 행을 실패로 정리할 때 남기는 에러코드 */
    public const STALE_ERROR_CODE = 'IN_PROGRESS_STALE';

    /** 같은 주문의 다른 발급이 처리 중일 때의 발급 불가 사유 코드 */
    public const BLOCKER_ISSUE_IN_PROGRESS = 'ISSUE_IN_PROGRESS';

    /** 409(충돌)로 응답할 발급 불가 사유 코드 */
    private const CONFLICT_BLOCKERS = ['ALREADY_ISSUED', self::BLOCKER_ISSUE_IN_PROGRESS];

    /**
     * 현금영수증 서비스를 생성합니다.
     *
     * @param  OrderCashReceiptRepositoryInterface  $repository  현금영수증 이력 Repository
     * @param  OrderPaymentRepositoryInterface  $paymentRepository  결제 Repository
     * @param  EcommerceSettingsService  $settingsService  이커머스 설정 서비스
     * @param  CurrencyConversionService  $currencyConversionService  통화 환산 서비스
     */
    public function __construct(
        private OrderCashReceiptRepositoryInterface $repository,
        private OrderPaymentRepositoryInterface $paymentRepository,
        private EcommerceSettingsService $settingsService,
        private CurrencyConversionService $currencyConversionService,
    ) {}

    /**
     * 현금영수증을 발급합니다.
     *
     * 동시성(KVE-2026-3191): PG 를 부르기 전에 처리 중(IN_PROGRESS) 발급 행을 먼저 INSERT 해 주문의 발급 슬롯을
     * 예약한다. 슬롯은 DB 유니크(`active_issue_order_key`)가 지키므로 겹친 요청은 예약에 실패하고 PG 를 부르지
     * 않는다 — 그 요청에는 슬롯을 쥔 행(완료 또는 처리 중)이 돌아간다. PG 호출은 트랜잭션 밖에서 하고, 결과는
     * 「처리 중일 때만」 조건부로 기록한다.
     *
     * @param  Order  $order  주문 모델
     * @param  CashReceiptType  $type  발급 용도
     * @param  string  $identifier  식별번호 원본 (하이픈 제거)
     * @param  CashReceiptIdentifierType|null  $identifierType  식별번호 종류 (미지정 시 추론)
     * @return OrderCashReceipt 발급 이력 (실패 시 issue_status=FAILED, 다른 요청이 처리 중이면 그 처리 중 행)
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

        // 실제 발급은 엄격하게 환산한다(B3) — 결제 통화로 환산할 수 없으면 기준 통화 금액으로 신고하지 않고 실패로 남긴다.
        // 화면 표시용 계산(`calculateIssuableAmount()` 기본값)은 종전대로 폴백한다.
        try {
            $target = $this->calculateIssuableAmount($order, strict: true);
        } catch (CashReceiptConversionException $e) {
            Log::warning('[cash_receipt] 발급액 환산 실패 — 발급하지 않음', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return $this->recordFailure(
                $order,
                $payment,
                CashReceiptTransactionType::ISSUE,
                $type,
                'CURRENCY_CONVERSION_FAILED',
                __('sirsoft-ecommerce::cash_receipt.errors.currency_conversion_failed'),
                $identifier,
                $provider,
            );
        }

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

        // 과세 몫에 든 부가세 — 공급가액·부가세를 나눠 받는 프로바이더(KG이니시스)가 쓴다.
        // 받지 않는 프로바이더(토스)는 무시한다. 세율이 섞이거나 영세가 든 주문에서 /11 고정 분할이 틀린다.
        $vatAmount = $this->calculateIssuableVatAmount($order, $target, strict: true);

        // 발급 슬롯 예약 — 겹친 요청은 여기서 갈린다(KVE-2026-3191). 예약에 실패하면 PG 를 부르지 않는다.
        $reserved = $this->repository->reserveIssue([
            'order_id' => $order->id,
            'order_payment_id' => $payment?->id,
            'provider' => $provider,
            'receipt_key' => null,
            'receipt_type' => $type->value,
            'amount' => $target['amount'],
            'tax_free_amount' => $target['tax_free_amount'],
            'identifier_masked' => $this->maskIdentifier($identifier),
        ]);

        if ($reserved === null) {
            return $this->resolveIssueSlotConflict($order, $type, $identifier, $provider);
        }

        $payload = [
            'type' => $type->value,
            'identifier' => $identifier,
            'identifier_type' => ($identifierType ?? $this->inferIdentifierType($identifier))?->value,
            'amount' => $target['amount'],
            // 면세 금액 = 면세 + 영세 — 영세는 부가세가 없으므로 PG 에는 면세로 넘긴다
            'tax_free_amount' => $target['tax_free_amount'],
            'vat_amount' => $vatAmount,
            'order_name' => $this->buildOrderName($order),
            'order_number' => $order->order_number,
            // 이번 발급의 회차 (1부터). 프로바이더가 발급 요청 식별자를 회차로 구분할 때 사용한다.
            // 토스는 동일 orderId 재사용을 중복 거부하므로 "{order_number}-cr{seq}" 로 접미한다.
            // 프로바이더 종속 문자열 조립은 리스너가 하고, 코어는 숫자만 제공한다.
            // 예약 행까지의 발급 이력 수로 정한다 — 겹친 요청이 같은 회차를 받지 않는다.
            'issue_sequence' => $this->repository->countIssuesUpTo($order, (int) $reserved->id),
        ];

        // 프로바이더 예외도 실패 결과로 받는다(B1) — 예외가 그대로 올라가면 이력이 생기지 않아 관리자 화면에 재발급 버튼이
        // 없었다(자동 발급 리스너는 로그만 남겼다).
        try {
            $result = HookManager::applyFilters(
                'sirsoft-ecommerce.cash_receipt.issue',
                $this->defaultIssueResult(),
                $order,
                $provider,
                $payload,
            );
        } catch (\Throwable $e) {
            ConcurrencyErrors::rethrowIfConcurrencyError($e);
            $result = $this->providerExceptionResult($e, 'issue_failed');
        }

        $succeeded = (bool) ($result['success'] ?? false);
        $receiptKey = $result['receipt_key'] ?? null;

        $attributes = [
            'receipt_key' => $receiptKey,
            'receipt_url' => $result['receipt_url'] ?? null,
            'issue_number' => $result['issue_number'] ?? null,
            'issue_status' => $succeeded
                ? CashReceiptIssueStatus::COMPLETED->value
                : CashReceiptIssueStatus::FAILED->value,
            'error_code' => $succeeded ? null : ($result['error_code'] ?? null),
            'error_message' => $succeeded ? null : ($result['error_message'] ?? null),
            'issued_at' => $succeeded ? now() : null,
            'raw_response' => $this->sanitizeResponse($result['raw_response'] ?? null),
        ];

        // 영수증 키 없이 성공한 발급은 취소 대상을 특정할 수 없어 활성으로 보지 않는다(filterActive).
        // 그 행이 슬롯을 계속 쥐면 다시 발급할 수 없으므로 놓는다.
        if ($succeeded && ($receiptKey === null || $receiptKey === '')) {
            $attributes['slot_released_at'] = now();

            Log::warning('[cash_receipt] 영수증 키 없이 발급 성공 — 슬롯 해제, PG 화면 대조 필요', [
                'order_id' => $order->id,
                'receipt_id' => $reserved->id,
                'provider' => $provider,
            ]);
        }

        $updated = $this->repository->updateIfStatus(
            (int) $reserved->id,
            CashReceiptIssueStatus::IN_PROGRESS,
            $attributes,
        );

        $receipt = $reserved->fresh() ?? $reserved;

        if ($updated === 0) {
            // PG 응답이 늦어 다른 요청이 이 예약 행을 오래된 처리 중으로 보고 실패로 정리한 경우다.
            // 결과를 덮어쓰면 그사이 잡힌 새 예약과 슬롯이 겹칠 수 있어 기록하지 않고 대조를 요청한다.
            Log::error('[cash_receipt] 발급 결과를 기록하지 못함 — 예약 행이 이미 처리 중이 아님, PG 화면 대조 필요', [
                'order_id' => $order->id,
                'receipt_id' => $reserved->id,
                'provider' => $provider,
                'pg_succeeded' => $succeeded,
                'receipt_key' => $receiptKey,
            ]);

            return $receipt;
        }

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
     * 동시성(U1): 활성 영수증마다 PG 취소 전에 처리 중(IN_PROGRESS) 취소 행을 먼저 INSERT 해 예약한다. 발급 행당
     * 열린 취소 1건은 DB 유니크(`open_cancel_target_key`)가 지키므로, 겹친 취소 요청은 예약에 실패하고 PG 를
     * 부르지 않는다. 그때는 열린 취소의 상태로 판정한다 — 완료면 성공으로 보고, 처리 중이면 미완료로 둔다.
     * 취소가 완료되면 같은 트랜잭션에서 발급 행의 슬롯을 놓아 재발급 예약이 가능해진다.
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
        $cancelledHere = false;

        foreach ($activeReceipts as $active) {
            $provider = $active->provider;
            $receiptKey = (string) $active->receipt_key;

            $cancel = $this->repository->reserveCancel($active, [
                'order_payment_id' => $payment?->id,
                'provider' => $provider,
                'receipt_key' => $receiptKey,
                'receipt_type' => $active->receipt_type->value,
                'amount' => (int) $active->amount,
                'tax_free_amount' => (int) $active->tax_free_amount,
                'identifier_masked' => $active->identifier_masked,
            ]);

            if ($cancel === null) {
                // 다른 요청이 이미 이 영수증을 취소하고 있거나 끝냈다 — PG 를 다시 부르지 않는다.
                $allSucceeded = $this->resolveCancelSlotConflict($active) && $allSucceeded;

                continue;
            }

            // 프로바이더 예외도 실패 결과로 받아 FAILED 취소 이력을 남긴다(B2) — 이력이 있어야 운영자가 재시도한다.
            try {
                $result = HookManager::applyFilters(
                    'sirsoft-ecommerce.cash_receipt.cancel',
                    $this->defaultCancelResult(),
                    $order,
                    $provider,
                    $receiptKey,
                );
            } catch (\Throwable $e) {
                ConcurrencyErrors::rethrowIfConcurrencyError($e);
                $result = $this->providerExceptionResult($e, 'cancel_failed');
            }

            $succeeded = (bool) ($result['success'] ?? false);
            $rawResponse = $this->sanitizeResponse($result['raw_response'] ?? null);

            if ($succeeded) {
                // 취소 완료 + 발급 행 슬롯 해제를 한 트랜잭션으로 기록한다.
                $succeeded = $this->repository->completeCancel($cancel, $active, [
                    'receipt_key' => $result['receipt_key'] ?? $receiptKey,
                    'raw_response' => $rawResponse,
                ]);

                if (! $succeeded) {
                    // PG 응답이 늦어 다른 요청이 이 예약 행을 오래된 처리 중으로 보고 실패로 정리한 경우다.
                    Log::error('[cash_receipt] 취소 결과를 기록하지 못함 — 예약 행이 이미 처리 중이 아님, PG 화면 대조 필요', [
                        'order_id' => $order->id,
                        'receipt_id' => $active->id,
                        'cancel_id' => $cancel->id,
                        'provider' => $provider,
                    ]);
                } else {
                    $cancelledHere = true;
                }
            } else {
                $this->repository->updateIfStatus((int) $cancel->id, CashReceiptIssueStatus::IN_PROGRESS, [
                    'issue_status' => CashReceiptIssueStatus::FAILED->value,
                    'error_code' => $result['error_code'] ?? null,
                    'error_message' => $result['error_message'] ?? $reason,
                    'raw_response' => $rawResponse,
                ]);

                Log::warning('[cash_receipt] 취소 실패', [
                    'order_id' => $order->id,
                    'provider' => $provider,
                    'error_code' => $result['error_code'] ?? null,
                ]);
            }

            $allSucceeded = $allSucceeded && $succeeded;
        }

        // 요약은 이 호출이 실제로 취소를 끝냈을 때만 비운다. 겹친 요청이 이미 끝낸 취소를 성공으로 본 경우까지
        // 비우면, 그 요청이 이어서 재발급하며 채운 요약을 늦게 온 이쪽이 지워 버린다.
        if ($allSucceeded && $cancelledHere) {
            $this->clearPaymentSummary($payment);
        }

        return $allSucceeded;
    }

    /**
     * 처리 중 예약 행이 오래됐는지(PG 응답을 끝내 받지 못했는지) 판정합니다.
     *
     * @param  OrderCashReceipt  $receipt  이력 행
     * @return bool 처리 중이면서 예약 시각이 IN_PROGRESS_STALE_MINUTES 분보다 오래됐으면 true
     */
    public function isStaleInProgress(OrderCashReceipt $receipt): bool
    {
        if (! $receipt->isInProgress() || $receipt->created_at === null) {
            return false;
        }

        return $receipt->created_at->lt(now()->subMinutes(self::IN_PROGRESS_STALE_MINUTES));
    }

    /**
     * 발급 불가 사유가 409(충돌)로 응답할 사유인지 판정합니다.
     *
     * 이미 발급됨·다른 발급 처리 중은 요청 자체의 오류가 아니라 주문 상태와의 충돌이라 422 와 구분한다.
     *
     * @param  string  $blocker  발급 불가 사유 코드
     * @return bool 409 로 응답해야 하면 true
     */
    public function isConflictBlocker(string $blocker): bool
    {
        return in_array($blocker, self::CONFLICT_BLOCKERS, true);
    }

    /**
     * 발급 슬롯 예약에 실패했을 때 돌려줄 행을 정합니다.
     *
     * - 슬롯 보유 행이 완료면 그 행 (이미 발급됨 — 중복 발급하지 않는다)
     * - 오래된 처리 중이면 실패(IN_PROGRESS_STALE)로 정리하고 그 행 — 자동 재발급은 하지 않는다. PG 에서는
     *   실제로 발급됐을 수 있고 KG이니시스에는 멱등키가 없어, 관리자가 PG 화면을 확인한 뒤 재발급한다.
     * - 그 외 처리 중이면 그 행 (호출부는 409 ISSUE_IN_PROGRESS 로 응답)
     *
     * @param  Order  $order  주문 모델
     * @param  CashReceiptType  $type  발급 용도 (이력을 찾지 못할 때의 실패 기록용)
     * @param  string  $identifier  식별번호 원본 (이력을 찾지 못할 때의 실패 기록용 — 마스킹해 저장)
     * @param  string  $provider  프로바이더 ID
     * @return OrderCashReceipt 슬롯 보유 행 (그사이 슬롯이 비었으면 가장 최근 발급 이력)
     */
    private function resolveIssueSlotConflict(
        Order $order,
        CashReceiptType $type,
        string $identifier,
        string $provider,
    ): OrderCashReceipt {
        $holder = $this->repository->findSlotHolder($order);

        if ($holder === null) {
            // 예약 실패 직후 상대가 끝나 슬롯이 빈 경우 — 이번 요청은 재시도하지 않고 최근 이력을 보여 준다.
            $latest = $this->repository->findActiveReceipt($order)
                ?? $this->repository->findByOrder($order)->first(fn (OrderCashReceipt $row) => $row->isIssue());

            // 상대 행이 사라지는 경로는 없지만(행 삭제 없음), 남은 이력이 없으면 충돌 사실을 실패 이력으로 남긴다.
            return $latest ?? $this->recordFailure(
                $order,
                $order->payment,
                CashReceiptTransactionType::ISSUE,
                $type,
                self::BLOCKER_ISSUE_IN_PROGRESS,
                __('sirsoft-ecommerce::cash_receipt.errors.issue_in_progress'),
                $identifier,
                $provider,
            );
        }

        if ($this->isStaleInProgress($holder)) {
            $this->failStaleInProgress($holder);

            return $holder->fresh() ?? $holder;
        }

        return $holder;
    }

    /**
     * 활성 영수증 가운데 다른 요청이 아직 처리 중인(10분이 지나지 않은) 취소가 있는지 봅니다.
     *
     * 관리자가 두 화면에서 동시에 전액 취소를 누르면 진 쪽은 취소를 예약하지 못한다 — 그때 「취소 실패」가 아니라
     * 「처리 중」으로 안내하기 위한 판정이다.
     *
     * @param  Order  $order  대상 주문
     * @return bool 처리 중인 취소가 있으면 true
     */
    public function hasCancelInProgress(Order $order): bool
    {
        foreach ($this->repository->findActiveReceipts($order) as $active) {
            $open = $this->repository->findOpenCancel($active);

            if ($open !== null
                && $open->issue_status === CashReceiptIssueStatus::IN_PROGRESS
                && ! $this->isStaleInProgress($open)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 취소 예약에 실패했을 때 열린 취소의 상태로 이번 취소의 성패를 정합니다.
     *
     * @param  OrderCashReceipt  $issue  취소하려던 발급 행
     * @return bool 열린 취소가 완료면 true, 처리 중이거나(오래된 것은 실패로 정리) 그사이 사라졌으면 false
     */
    private function resolveCancelSlotConflict(OrderCashReceipt $issue): bool
    {
        $open = $this->repository->findOpenCancel($issue);

        if ($open === null) {
            // 상대 취소가 그사이 실패로 끝났다 — 이번 호출은 미완료로 두고 다음 시도에 맡긴다.
            return false;
        }

        if ($open->issue_status === CashReceiptIssueStatus::COMPLETED) {
            return true;
        }

        if ($this->isStaleInProgress($open)) {
            $this->failStaleInProgress($open);
        }

        return false;
    }

    /**
     * 오래된 처리 중 행을 실패(IN_PROGRESS_STALE)로 정리합니다.
     *
     * @param  OrderCashReceipt  $receipt  처리 중 이력 행
     * @return void 반환값 없음
     */
    private function failStaleInProgress(OrderCashReceipt $receipt): void
    {
        $this->repository->updateIfStatus((int) $receipt->id, CashReceiptIssueStatus::IN_PROGRESS, [
            'issue_status' => CashReceiptIssueStatus::FAILED->value,
            'error_code' => self::STALE_ERROR_CODE,
            'error_message' => __('sirsoft-ecommerce::cash_receipt.errors.in_progress_stale'),
        ]);

        Log::warning('[cash_receipt] 오래된 처리 중 이력을 실패로 정리 — 자동 재시도 없음, PG 화면 대조 필요', [
            'order_id' => $receipt->order_id,
            'receipt_id' => $receipt->id,
            'transaction_type' => $receipt->transaction_type?->value,
            'provider' => $receipt->provider,
        ]);
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
     * 겹친 호출(U1): 취소는 cancelAll() 의 취소 예약이, 재발급은 issue() 의 발급 슬롯 예약이 막는다. 두 번째 호출은
     * 상대 취소가 처리 중이면 재발급하지 않고 끝나며, 상대가 끝낸 뒤라면 재발급 예약에서 상대의 행을 돌려받는다 —
     * 대체 발급은 1건만 생긴다.
     *
     * @param  Order  $order  주문 모델
     * @param  string|null  $reason  변동 사유 (취소 이력에 기록)
     * @return void 반환값 없음
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
     * 겹친 호출 방어는 issue()·cancelAll() 의 예약 행이 맡는다 — 이 메서드는 그 보호를 그대로 상속한다.
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
     * 면세액은 결제사·국세청에 「부가세 없는 금액」으로 넘길 몫이라 영세(세율 0%)를 포함한다
     * (`Order::pgTaxFreeAmount()`). 이력 행의 `tax_free_amount` 도 이 의미다.
     *
     * 현금성 금액(total_cash_equivalent_amount)은 무통장 주문의 실입금액이며,
     * 마일리지 사용분은 현금이 아니므로 제외되어 있다.
     *
     * @param  Order  $order  주문 모델
     * @param  bool  $strict  환산 실패 시 예외를 던질지 (발급 직전은 true — B3, 화면 표시는 false 로 기준 통화 폴백)
     * @return array{amount: int, tax_free_amount: int} 발급액과 면세액
     *
     * @throws CashReceiptConversionException $strict 이고 결제 통화로 환산할 수 없을 때
     */
    public function calculateIssuableAmount(Order $order, bool $strict = false): array
    {
        $cashEquivalent = $this->toPaymentCurrencyAmount($order, (float) $order->total_cash_equivalent_amount, $strict);

        if ($cashEquivalent <= 0) {
            return ['amount' => 0, 'tax_free_amount' => 0];
        }

        $taxFree = $this->toPaymentCurrencyAmount($order, $order->pgTaxFreeAmount(), $strict);
        $taxable = $this->toPaymentCurrencyAmount($order, $order->pgTaxableAmount(), $strict);
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
     * 발급액의 과세 몫에 든 부가세를 계산합니다.
     *
     * 과세 몫(발급액 − 면세 몫)을 주문의 「과세 금액(영세 제외) 대비 부가세」 비율로 나눈다 — 옵션별 세율로
     * 합산한 주문 부가세를 쓰므로 5% 세율 상품·영세 상품이 섞여도 맞는다. 과세 몫을 넘지 않는다.
     *
     * @param  Order  $order  주문 모델
     * @param  array{amount: int, tax_free_amount: int}  $target  calculateIssuableAmount() 결과
     * @param  bool  $strict  환산 실패 시 예외를 던질지
     * @return int 부가세 (결제 통화)
     *
     * @throws CashReceiptConversionException $strict 이고 결제 통화로 환산할 수 없을 때
     */
    public function calculateIssuableVatAmount(Order $order, array $target, bool $strict = false): int
    {
        $taxableShare = max(0, (int) $target['amount'] - (int) $target['tax_free_amount']);
        $pgTaxable = $this->toPaymentCurrencyAmount($order, $order->pgTaxableAmount(), $strict);

        if ($taxableShare <= 0 || $pgTaxable <= 0) {
            return 0;
        }

        $vat = $this->toPaymentCurrencyAmount($order, (float) $order->total_vat_amount, $strict);

        return min($taxableShare, (int) round($taxableShare * $vat / $pgTaxable));
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
     * @param  bool  $strict  환산 실패 시 예외를 던질지 (false 면 base 금액으로 폴백)
     * @return int 결제 통화 최소 화폐단위 금액
     *
     * @throws CashReceiptConversionException $strict 이고 환산할 수 없을 때
     */
    protected function toPaymentCurrencyAmount(Order $order, float $baseAmount, bool $strict = false): int
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
            if ($strict) {
                throw new CashReceiptConversionException($e->getMessage(), 0, $e);
            }

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

        // 같은 주문의 다른 발급이 PG 응답을 기다리는 중이면 겹쳐 발급하지 않는다(KVE-2026-3191).
        // 오래된 처리 중은 막지 않는다 — 발급 시도가 그 행을 실패로 정리하고 관리자 확인을 기다린다.
        $inProgress = $this->repository->findInProgressIssue($order);

        if ($inProgress !== null && ! $this->isStaleInProgress($inProgress)) {
            return self::BLOCKER_ISSUE_IN_PROGRESS;
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
     * @return void 반환값 없음
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
     * 프로바이더 예외를 실패 결과 모양으로 바꿉니다. (B1 · B2)
     *
     * 사유는 현금영수증 이력에 남아 관리자 주문 상세 결제 정보와 구매자 응답에 그대로 보인다. 프로바이더 예외 원문에는
     * 결제대행사 API 주소·cURL 오류 문구나 서버 경로가 담길 수 있으므로 운영 모드에서는 번역된 실패 문구를 남기고
     * 원문은 서버 로그에만 둔다. 디버그 모드는 코어 관례(SafeExceptionMessage)대로 원문을 둔다.
     *
     * @param  \Throwable  $e  프로바이더 예외
     * @param  string  $failedKey  운영 모드에서 남길 실패 문구 키(`cash_receipt.errors.` 아래 — issue_failed·cancel_failed)
     * @return array{success: false, error_code: string, error_message: string, receipt_key: null, receipt_url: null, issue_number: null, raw_response: null} 실패 결과
     */
    private function providerExceptionResult(\Throwable $e, string $failedKey): array
    {
        Log::error('[cash_receipt] 프로바이더 예외 — 실패 이력으로 기록', ['exception_class' => $e::class, 'error' => $e->getMessage()]);

        return [
            'success' => false,
            'error_code' => 'PROVIDER_EXCEPTION',
            'error_message' => config('app.debug')
                ? mb_substr($e->getMessage(), 0, 255)
                : __('sirsoft-ecommerce::cash_receipt.errors.'.$failedKey),
            'receipt_key' => null,
            'receipt_url' => null,
            'issue_number' => null,
            'raw_response' => null,
        ];
    }

    /**
     * 발급 성공 시 결제 테이블의 요약 컬럼을 갱신합니다.
     *
     * @param  OrderPayment|null  $payment  결제 모델
     * @param  OrderCashReceipt  $receipt  발급 이력
     * @param  CashReceiptType  $type  발급 용도
     * @param  string  $identifier  식별번호 원본
     * @param  CashReceiptIdentifierType|null  $identifierType  식별번호 종류
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
