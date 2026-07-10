<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Concerns\PreventsReplayCallback;
use Plugins\Sirsoft\PayKginicis\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayKginicis\Concerns\SerializesPaymentCallbacks;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesCbtOrderContext;
use Plugins\Sirsoft\PayKginicis\Repositories\CbtCvsOperationsRepositoryInterface;

class CbtCvsOperationsService
{
    use PreventsReplayCallback;
    use SanitizesPgResponse;
    use SerializesPaymentCallbacks;
    use ValidatesCbtOrderContext;

    private const HISTORY_LIMIT = 20;

    private const NOTIFY_RESPONSE_KEYS = [
        'tid',
        'mid',
        'applDt',
        'applTm',
        'status',
        'payNm',
        'orderId',
        'applNo',
        'sid',
        'convenience',
        'confNo',
        'receiptNo',
        'paymentTerm',
        'amount',
        'currencyCd',
    ];

    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly KgInicisApiService $apiService,
        private readonly CbtCvsOperationsRepositoryInterface $repository,
    ) {}

    /**
     * KG 이니시스 CBT 편의점 입금 통보를 검증하고 주문 결제 상태를 갱신합니다.
     *
     * @param  array<string, mixed>  $payload  NOTI payload
     * @param  string  $source  수신 경로 구분
     * @param  string|null  $remoteIp  통보 발신 IP
     * @return array<string, mixed> 통보 처리 결과
     */
    public function handleNotify(array $payload, string $source = 'kg', ?string $remoteIp = null): array
    {
        $tid = trim((string) ($payload['tid'] ?? ''));
        $orderId = trim((string) ($payload['orderId'] ?? ''));
        $mid = trim((string) ($payload['mid'] ?? ''));
        $status = preg_replace('/\s+/', '', (string) ($payload['status'] ?? ''));
        $amount = (int) ($payload['amount'] ?? 0);
        $payment = null;
        $existingMeta = [];
        $callbackLock = null;

        if ($tid === '' || $orderId === '' || $mid === '' || $amount <= 0) {
            Log::warning('KG Inicis CBT CVS: invalid notify payload', [
                'tid' => $tid,
                'order_id' => $orderId,
                'mid' => $mid,
                'amount' => $payload['amount'] ?? null,
                'keys' => array_keys($payload),
            ]);

            return $this->notifyResult('FAIL', 'failed', 'invalid_payload');
        }

        try {
            $order = $this->orderService->findByOrderNumber($orderId);
            if (! $order) {
                Log::error('KG Inicis CBT CVS: order not found', ['order_id' => $orderId, 'tid' => $tid]);

                return $this->notifyResult('FAIL', 'failed', 'order_not_found');
            }

            $callbackLock = $this->acquireOrderCallbackLock('cbtCvsNotify', $orderId);

            $payment = $this->repository->firstPaymentForOrder($order);
            if (! $payment) {
                Log::warning('KG Inicis CBT CVS: payment row not found', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                ]);

                return $this->notifyResult('FAIL', 'failed', 'payment_not_found');
            }

            $existingMeta = is_array($payment->payment_meta) ? $payment->payment_meta : [];

            if ($status !== '00') {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'ignored', 'non_success_status', $source, $remoteIp);
                Log::info('KG Inicis CBT CVS: non-success notify ignored', [
                    'tid' => $tid,
                    'order_id' => $orderId,
                    'status' => $status,
                ]);

                return $this->notifyResult('OK', 'ignored', 'non_success_status');
            }

            $expectedTid = trim((string) $payment->transaction_id);
            if ($expectedTid === '' || ! hash_equals($expectedTid, $tid)) {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'failed', 'tid_mismatch', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: tid mismatch', [
                    'order_id' => $orderId,
                    'received_tid' => $tid,
                    'expected_tid' => $expectedTid,
                ]);

                return $this->notifyResult('FAIL', 'failed', 'tid_mismatch');
            }

            if ($this->wasAlreadyPaid($tid)) {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'ignored', 'already_paid', $source, $remoteIp);
                $this->logReplayDetected($tid, $orderId, 'CBT CVS notify');

                return $this->notifyResult('OK', 'ignored', 'already_paid');
            }

            if (! $this->paymentStatusEquals($payment->payment_status, PaymentStatusEnum::WAITING_DEPOSIT)) {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'failed', 'payment_status_mismatch', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: payment status is not waiting_deposit', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'payment_status' => $this->paymentStatusValue($payment->payment_status),
                ]);

                return $this->notifyResult('FAIL', 'failed', 'payment_status_mismatch');
            }

            $expectedPayMethod = strtoupper((string) ($existingMeta['pay_method'] ?? ''));
            if ($expectedPayMethod !== 'CVS') {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'failed', 'pay_method_mismatch', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: existing payment method is not CVS', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'pay_method' => $existingMeta['pay_method'] ?? null,
                ]);

                return $this->notifyResult('FAIL', 'failed', 'pay_method_mismatch');
            }

            $expectedSid = trim((string) ($existingMeta['cbt_sid'] ?? ''));
            $receivedSid = trim((string) ($payload['sid'] ?? ''));
            if ($expectedSid !== '' && $receivedSid !== $expectedSid) {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'failed', 'sid_mismatch', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: sid mismatch', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'received_sid' => $receivedSid,
                    'expected_sid' => $expectedSid,
                ]);

                return $this->notifyResult('FAIL', 'failed', 'sid_mismatch');
            }

            $currency = strtoupper(trim((string) ($payload['currencyCd'] ?? '')));
            if ($currency !== 'JPY') {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'failed', 'currency_mismatch', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: currency mismatch', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'currency' => $payload['currencyCd'] ?? null,
                ]);

                return $this->notifyResult('FAIL', 'failed', 'currency_mismatch');
            }

            $expectedAmount = $this->resolveExpectedCvsAmount($order, $existingMeta);
            if ($expectedAmount === null) {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'failed', 'invalid_payment_currency', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: payment currency is not chargeable', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                ]);

                return $this->notifyResult('FAIL', 'failed', 'invalid_payment_currency');
            }

            if ($expectedAmount > 0 && $amount !== $expectedAmount) {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'failed', 'amount_mismatch', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: amount mismatch', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'received_amount' => $amount,
                    'expected_amount' => $expectedAmount,
                ]);

                return $this->notifyResult('FAIL', 'failed', 'amount_mismatch');
            }

            $expectedMid = (string) ($existingMeta['cbt_mid'] ?? $this->apiService->getJapanMid());
            if ($expectedMid !== '' && $mid !== $expectedMid) {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'failed', 'mid_mismatch', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: MID mismatch', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'received_mid' => $mid,
                    'expected_mid' => $expectedMid,
                ]);

                return $this->notifyResult('FAIL', 'failed', 'mid_mismatch');
            }

            $sanitized = $this->sanitizePgResponse($payload, self::NOTIFY_RESPONSE_KEYS);
            $completedMeta = $this->appendNotifyHistory($existingMeta, $payload, 'confirmed', 'deposit_confirmed', $source, $remoteIp);
            $completedMeta = array_merge($completedMeta, [
                'result_code' => $status,
                'pay_method' => 'CVS',
                'auth_date' => ($payload['applDt'] ?? '').($payload['applTm'] ?? ''),
                'mid' => $mid,
                'currency' => $payload['currencyCd'] ?? 'JPY',
                'is_cbt' => true,
                'cbt_type' => 'JPPG',
                'cbt_mid' => $mid,
                'cbt_sid' => $payload['sid'] ?? ($existingMeta['cbt_sid'] ?? null),
                'is_test_mode' => $existingMeta['is_test_mode'] ?? $this->apiService->isTestMode(),
                'pg_response_sanitized' => true,
                'pg_cvs_notify_response' => $sanitized,
                'pg_raw_response' => $sanitized,
                'cvs_status' => 'paid',
                'cvs_convenience' => $payload['convenience'] ?? null,
                'cvs_conf_no' => $payload['confNo'] ?? null,
                'cvs_receipt_no' => $payload['receiptNo'] ?? null,
                'cvs_payment_term' => $payload['paymentTerm'] ?? null,
            ]);

            $this->orderService->completePayment($order, [
                'transaction_id' => $tid,
                'payment_meta' => $completedMeta,
            ], $amount);

            $this->repository->updatePaymentProvider($order, 'kginicis');

            Log::info('KG Inicis CBT CVS: deposit confirmed', [
                'order_id' => $orderId,
                'tid' => $tid,
                'amount' => $amount,
            ]);

            return $this->notifyResult('OK', 'confirmed', 'deposit_confirmed');
        } catch (\Throwable $e) {
            if ($payment instanceof OrderPayment) {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'failed', 'exception', $source, $remoteIp);
            }

            Log::error('KG Inicis CBT CVS: notify failed', [
                'order_id' => $orderId,
                'tid' => $tid,
                'error' => $e->getMessage(),
            ]);

            return $this->notifyResult('FAIL', 'failed', 'exception');
        } finally {
            $this->releaseOrderCallbackLock($callbackLock);
        }
    }

    /**
     * 주문의 CBT 편의점 입금 운영 요약 정보를 반환합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return array<string, mixed>|null 운영 요약
     */
    public function summary(string $orderNumber): ?array
    {
        $order = $this->findOrder($orderNumber);
        if (! $order) {
            return null;
        }

        $payment = $order->payment;
        $meta = is_array($payment?->payment_meta) ? $payment->payment_meta : [];
        $isCbtCvs = $payment instanceof OrderPayment && $this->isCbtCvsMeta($meta);
        $paymentTerm = (string) ($meta['cvs_payment_term'] ?? '');
        $paymentTermAt = $this->parseCbtDateTime($paymentTerm);
        $paymentStatus = $payment instanceof OrderPayment ? $this->paymentStatusValue($payment->payment_status) : '';
        $isWaitingDeposit = $paymentStatus === PaymentStatusEnum::WAITING_DEPOSIT->value;
        $isExpiredByTime = $paymentTermAt instanceof CarbonImmutable
            && $paymentTermAt->isPast()
            && ! in_array($paymentStatus, [PaymentStatusEnum::PAID->value, PaymentStatusEnum::EXPIRED->value], true);

        return [
            'is_cbt_cvs' => $isCbtCvs,
            'order_number' => $order->order_number,
            'order_status' => $this->enumValue($order->order_status),
            'payment_status' => $paymentStatus,
            'tid' => (string) ($payment?->transaction_id ?? ''),
            'amount' => (int) ($meta['cvs_amount'] ?? ($payment?->paid_amount_local ?: $order->total_due_amount)),
            'currency' => (string) ($payment?->currency ?? $order->currency ?? 'JPY'),
            'cbt_mid' => (string) ($meta['cbt_mid'] ?? ''),
            'cbt_sid' => (string) ($meta['cbt_sid'] ?? ''),
            'is_test_mode' => (bool) ($meta['is_test_mode'] ?? false),
            'convenience' => (string) ($meta['cvs_convenience'] ?? ''),
            'conf_no' => (string) ($meta['cvs_conf_no'] ?? ''),
            'receipt_no' => (string) ($meta['cvs_receipt_no'] ?? ''),
            'payment_term' => $paymentTerm,
            'payment_term_formatted' => $paymentTermAt?->format('Y-m-d H:i:s'),
            'is_expired_by_time' => $isExpiredByTime,
            'cvs_status' => (string) ($meta['cvs_status'] ?? ($isWaitingDeposit ? 'waiting_deposit' : '')),
            'last_notify_at' => (string) ($meta['cvs_last_notify_at'] ?? ''),
            'last_notify_result' => (string) ($meta['cvs_last_notify_result'] ?? ''),
            'last_notify_reason' => (string) ($meta['cvs_last_notify_reason'] ?? ''),
            'notify_history' => array_slice($this->normalizeHistory($meta['cvs_notify_history'] ?? []), 0, 10),
            'notify_url' => url('/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify'),
            'can_simulate_notify' => $isCbtCvs && $isWaitingDeposit && (bool) ($meta['is_test_mode'] ?? false),
            'can_mark_expired' => $isCbtCvs && $isWaitingDeposit && $isExpiredByTime,
            'last_recheck_at' => (string) ($meta['cvs_last_recheck_at'] ?? ''),
            'last_recheck_result' => (string) ($meta['cvs_last_recheck_result'] ?? ''),
            'expired_at' => (string) ($meta['cvs_expired_at'] ?? ''),
            'expiry_reason' => (string) ($meta['cvs_expiry_reason'] ?? ''),
        ];
    }

    /**
     * 테스트 모드 CBT 편의점 입금 완료 NOTI 를 관리자 동작으로 시뮬레이션합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string|null  $remoteIp  관리자 요청 IP
     * @return array<string, mixed> 처리 결과
     */
    public function simulatePaidNotify(string $orderNumber, ?string $remoteIp = null): array
    {
        $context = $this->operationContext($orderNumber);
        if (! $context['ok']) {
            return $context;
        }

        /** @var Order $order */
        $order = $context['order'];
        /** @var OrderPayment $payment */
        $payment = $context['payment'];
        $meta = $context['meta'];

        if (! (bool) ($meta['is_test_mode'] ?? false)) {
            return $this->operationError('messages.cbt_cvs.not_test_mode', 422);
        }

        if (! $this->paymentStatusEquals($payment->payment_status, PaymentStatusEnum::WAITING_DEPOSIT)) {
            return $this->operationError('messages.cbt_cvs.not_waiting_deposit', 422);
        }

        $expectedAmount = $this->resolveExpectedCvsAmount($order, $meta);
        if ($expectedAmount === null) {
            return $this->operationError('messages.cbt_cvs.simulate_failed', 422);
        }

        $now = now();
        $payload = [
            'tid' => $payment->transaction_id ?: 'ADMIN_CVS_'.$order->order_number,
            'mid' => (string) ($meta['cbt_mid'] ?? $this->apiService->getJapanMid()),
            'applDt' => $now->format('Ymd'),
            'applTm' => $now->format('His'),
            'status' => '00',
            'payNm' => 'CVS',
            'orderId' => $order->order_number,
            'applNo' => (string) ($meta['cvs_receipt_no'] ?? 'ADMIN-SIM'),
            'sid' => (string) ($meta['cbt_sid'] ?? ''),
            'convenience' => (string) ($meta['cvs_convenience'] ?? ''),
            'confNo' => (string) ($meta['cvs_conf_no'] ?? ''),
            'receiptNo' => (string) ($meta['cvs_receipt_no'] ?? ''),
            'paymentTerm' => (string) ($meta['cvs_payment_term'] ?? ''),
            'amount' => (string) $expectedAmount,
            'currencyCd' => 'JPY',
        ];

        $notify = $this->handleNotify($payload, 'admin_simulation', $remoteIp);
        if (($notify['body'] ?? 'FAIL') !== 'OK') {
            return array_merge($this->operationError('messages.cbt_cvs.simulate_failed', 422), [
                'notify' => $notify,
                'summary' => $this->summary($orderNumber),
            ]);
        }

        return [
            'ok' => true,
            'notify' => $notify,
            'summary' => $this->summary($orderNumber),
        ];
    }

    /**
     * 입금 기한이 지난 CBT 편의점 결제를 만료 상태로 표시합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return array<string, mixed> 처리 결과
     */
    public function expireOverdue(string $orderNumber): array
    {
        $context = $this->operationContext($orderNumber);
        if (! $context['ok']) {
            return $context;
        }

        /** @var OrderPayment $payment */
        $payment = $context['payment'];

        $expired = DB::transaction(function () use ($payment): bool {
            $lockedPayment = $this->repository->lockPayment($payment);
            if (! $lockedPayment instanceof OrderPayment) {
                return false;
            }

            $lockedMeta = is_array($lockedPayment->payment_meta) ? $lockedPayment->payment_meta : [];
            if (! $this->canMarkExpired($lockedPayment, $lockedMeta)) {
                return false;
            }

            $updatedMeta = array_merge($lockedMeta, [
                'cvs_status' => 'expired',
                'cvs_expired_at' => now()->toIso8601String(),
                'cvs_expiry_reason' => 'payment_term_elapsed',
            ]);

            $this->repository->updatePayment($lockedPayment, [
                'payment_status' => PaymentStatusEnum::EXPIRED,
                'payment_meta' => $updatedMeta,
            ]);

            return true;
        });

        if (! $expired) {
            return $this->operationError('messages.cbt_cvs.not_expirable', 422);
        }

        return [
            'ok' => true,
            'summary' => $this->summary($orderNumber),
        ];
    }

    /**
     * 로컬 상태 확인 시각을 CBT 편의점 결제 메타에 기록합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return array<string, mixed> 처리 결과
     */
    public function markRechecked(string $orderNumber): array
    {
        $context = $this->operationContext($orderNumber);
        if (! $context['ok']) {
            return $context;
        }

        /** @var OrderPayment $payment */
        $payment = $context['payment'];
        $meta = array_merge($context['meta'], [
            'cvs_last_recheck_at' => now()->toIso8601String(),
            'cvs_last_recheck_result' => 'local_status_checked',
        ]);

        $this->savePaymentMeta($payment, $meta);

        return [
            'ok' => true,
            'summary' => $this->summary($orderNumber),
        ];
    }

    private function operationContext(string $orderNumber): array
    {
        $order = $this->findOrder($orderNumber);
        if (! $order) {
            return $this->operationError('messages.errors.order_not_found', 404);
        }

        $payment = $order->payment;
        $meta = is_array($payment?->payment_meta) ? $payment->payment_meta : [];

        if (! $payment instanceof OrderPayment || ! $this->isCbtCvsMeta($meta)) {
            return $this->operationError('messages.cbt_cvs.not_cvs', 422, [
                'order' => $order,
                'payment' => $payment,
                'meta' => $meta,
            ]);
        }

        return [
            'ok' => true,
            'order' => $order,
            'payment' => $payment,
            'meta' => $meta,
        ];
    }

    private function operationError(string $messageKey, int $status, array $context = []): array
    {
        return array_merge([
            'ok' => false,
            'message_key' => $messageKey,
            'status' => $status,
        ], $context);
    }

    private function findOrder(string $orderNumber): ?Order
    {
        return $this->repository->findOrderWithPayment($orderNumber);
    }

    private function notifyResult(string $body, string $status, string $reason): array
    {
        return [
            'body' => $body,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    private function storeNotifyHistory(
        OrderPayment $payment,
        array $existingMeta,
        array $payload,
        string $result,
        string $reason,
        string $source,
        ?string $remoteIp
    ): array {
        $updatedMeta = DB::transaction(function () use ($payment, $existingMeta, $payload, $result, $reason, $source, $remoteIp): array {
            $lockedPayment = $this->repository->lockPayment($payment);
            $currentMeta = $lockedPayment instanceof OrderPayment && is_array($lockedPayment->payment_meta)
                ? $lockedPayment->payment_meta
                : $existingMeta;

            $mergedMeta = $this->appendNotifyHistory($currentMeta, $payload, $result, $reason, $source, $remoteIp);
            $this->savePaymentMeta($lockedPayment ?? $payment, $mergedMeta);

            return $mergedMeta;
        });

        return $updatedMeta;
    }

    private function appendNotifyHistory(
        array $existingMeta,
        array $payload,
        string $result,
        string $reason,
        string $source,
        ?string $remoteIp
    ): array {
        $now = now()->toIso8601String();
        $history = $this->normalizeHistory($existingMeta['cvs_notify_history'] ?? []);
        array_unshift($history, [
            'received_at' => $now,
            'source' => $source,
            'remote_ip' => $remoteIp,
            'result' => $result,
            'reason' => $reason,
            'tid' => trim((string) ($payload['tid'] ?? '')),
            'order_id' => trim((string) ($payload['orderId'] ?? '')),
            'mid' => trim((string) ($payload['mid'] ?? '')),
            'status' => preg_replace('/\s+/', '', (string) ($payload['status'] ?? '')),
            'amount' => (int) ($payload['amount'] ?? 0),
            'currency' => strtoupper(trim((string) ($payload['currencyCd'] ?? ''))),
            'sid' => trim((string) ($payload['sid'] ?? '')),
        ]);

        return array_merge($existingMeta, [
            'cvs_notify_history' => array_slice($history, 0, self::HISTORY_LIMIT),
            'cvs_last_notify_at' => $now,
            'cvs_last_notify_result' => $result,
            'cvs_last_notify_reason' => $reason,
        ]);
    }

    private function savePaymentMeta(OrderPayment $payment, array $meta): void
    {
        $this->repository->updatePayment($payment, ['payment_meta' => $meta]);
    }

    private function normalizeHistory(mixed $history): array
    {
        if (! is_array($history)) {
            return [];
        }

        return array_values(array_filter($history, static fn ($item): bool => is_array($item)));
    }

    private function isCbtCvsMeta(array $meta): bool
    {
        return ($meta['is_cbt'] ?? false) === true
            && strtoupper((string) ($meta['pay_method'] ?? '')) === 'CVS';
    }

    /**
     * 잠금된 결제 row 기준으로 CVS 만료 전환 가능 여부를 다시 판정합니다.
     *
     * @param  OrderPayment  $payment  잠금된 결제 row
     * @param  array<string, mixed>  $meta  결제 메타
     * @return bool 만료 전환 가능 여부
     */
    private function canMarkExpired(OrderPayment $payment, array $meta): bool
    {
        if (! $this->isCbtCvsMeta($meta)) {
            return false;
        }

        if (! $this->paymentStatusEquals($payment->payment_status, PaymentStatusEnum::WAITING_DEPOSIT)) {
            return false;
        }

        $paymentTermAt = $this->parseCbtDateTime((string) ($meta['cvs_payment_term'] ?? ''));

        return $paymentTermAt instanceof CarbonImmutable && $paymentTermAt->isPast();
    }

    private function resolveExpectedCvsAmount(Order $order, array $paymentMeta): ?int
    {
        $metaAmount = (int) ($paymentMeta['cvs_amount'] ?? 0);
        if ($metaAmount > 0) {
            return $metaAmount;
        }

        // 결제 청구액 SSoT = 결제 통화(order_currency) 환산액 (buildPgPaymentData 와 동일 기준).
        // base≠결제 통화에서 CVS 통보 금액(결제 통화)과 단위가 일치하도록 한다.
        return $this->resolveExpectedPaymentPriceOrNull($order, 'cbt_cvs_notify', [
            'pay_method' => 'CVS',
        ]);
    }

    private function parseCbtDateTime(?string $value): ?CarbonImmutable
    {
        $compact = preg_replace('/\D+/', '', (string) $value);
        if (! is_string($compact) || strlen($compact) !== 14) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('YmdHis', $compact) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function paymentStatusEquals(mixed $status, PaymentStatusEnum $expected): bool
    {
        return $this->paymentStatusValue($status) === $expected->value;
    }

    private function paymentStatusValue(mixed $status): string
    {
        if ($status instanceof PaymentStatusEnum) {
            return $status->value;
        }

        return (string) $status;
    }

    private function enumValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }
}
