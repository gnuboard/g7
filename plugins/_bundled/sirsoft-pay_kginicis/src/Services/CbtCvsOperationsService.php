<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Services;

use App\Helpers\TimezoneHelper;
use App\Support\SiteUrl;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\OrderRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentCompletion;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayKginicis\Concerns\PreventsReplayCallback;
use Plugins\Sirsoft\PayKginicis\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayKginicis\Concerns\SerializesPaymentCallbacks;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesCbtOrderContext;

/**
 * 일본 CBT 편의점결제 입금 통보 처리와 관리자 운영(요약·시뮬레이션·만료·재확인).
 *
 * 주문·결제 조회와 쓰기는 이커머스 공개 결제 계약 {@see PaymentGatewayBridge} 로 한다(#916) — 결제 meta 누적(통보 이력)은
 * 결제 행 잠금 아래 계산(`mutatePaymentMetaWithLock`), 입금 기한 만료 전이는 `expireAwaitingDeposit`, 입금 완료는
 * `completePayment`(결제사 id 인수 포함)로 맡긴다.
 */
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

    /**
     * CBT 편의점결제 운영 서비스를 생성합니다.
     *
     * @param  PaymentGatewayBridge  $bridge  이커머스 결제 공개 계약 (주문·결제 조회와 상태 전환)
     * @param  KgInicisApiService  $apiService  KG 이니시스 API 서비스
     */
    public function __construct(
        private readonly PaymentGatewayBridge $bridge,
        private readonly KgInicisApiService $apiService,
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
            $order = $this->bridge->findOrderByNumber($orderId, 'kginicis');
            if (! $order) {
                Log::error('KG Inicis CBT CVS: order not found', ['order_id' => $orderId, 'tid' => $tid]);

                return $this->notifyResult('FAIL', 'failed', 'order_not_found');
            }

            $callbackLock = $this->acquireOrderCallbackLock('cbtCvsNotify', $orderId);

            $payment = $order->payment;
            if (! $payment) {
                Log::warning('KG Inicis CBT CVS: payment row not found', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                ]);

                return $this->notifyResult('FAIL', 'failed', 'payment_not_found');
            }

            $existingMeta = $payment->paymentMeta;

            if ($status !== '00') {
                $this->storeNotifyHistory($orderId, $payload, 'ignored', 'non_success_status', $source, $remoteIp);
                Log::info('KG Inicis CBT CVS: non-success notify ignored', [
                    'tid' => $tid,
                    'order_id' => $orderId,
                    'status' => $status,
                ]);

                return $this->notifyResult('OK', 'ignored', 'non_success_status');
            }

            $expectedTid = trim((string) $payment->transactionId);
            if ($expectedTid === '' || ! hash_equals($expectedTid, $tid)) {
                $this->storeNotifyHistory($orderId, $payload, 'failed', 'tid_mismatch', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: tid mismatch', [
                    'order_id' => $orderId,
                    'received_tid' => $tid,
                    'expected_tid' => $expectedTid,
                ]);

                return $this->notifyResult('FAIL', 'failed', 'tid_mismatch');
            }

            if ($this->wasAlreadyPaid($tid)) {
                $this->storeNotifyHistory($orderId, $payload, 'ignored', 'already_paid', $source, $remoteIp);
                $this->logReplayDetected($tid, $orderId, 'CBT CVS notify');

                return $this->notifyResult('OK', 'ignored', 'already_paid');
            }

            // 취소된 주문의 입금통보는 받지 않는다 (코어 P4 와 같은 가드).
            //
            // 고객이 취소한 뒤에 편의점에 입금해도 통보는 도착한다. 통과시키면 `CANCELLED`
            // 주문이 결제완료로 되살아나 재고·마일리지가 재차감된다. 아래 결제상태 가드만으로는
            // 부족하다 — 미입금 취소가 결제행을 `waiting_deposit` 그대로 두던 시절의 행이 그
            // 가드를 통과한다. 입금된 돈은 환불 절차로 돌려주어야 하는 별개 사건이다.
            if ($order->orderStatus === OrderStatusEnum::CANCELLED) {
                $this->storeNotifyHistory($orderId, $payload, 'ignored', 'order_cancelled', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: order is cancelled', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                ]);

                return $this->notifyResult('OK', 'ignored', 'order_cancelled');
            }

            if (! $this->paymentStatusEquals($payment->paymentStatus, PaymentStatusEnum::WAITING_DEPOSIT)) {
                $this->storeNotifyHistory($orderId, $payload, 'failed', 'payment_status_mismatch', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: payment status is not waiting_deposit', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'payment_status' => $this->paymentStatusValue($payment->paymentStatus),
                ]);

                return $this->notifyResult('FAIL', 'failed', 'payment_status_mismatch');
            }

            $expectedPayMethod = strtoupper((string) ($existingMeta['pay_method'] ?? ''));
            if ($expectedPayMethod !== 'CVS') {
                $this->storeNotifyHistory($orderId, $payload, 'failed', 'pay_method_mismatch', $source, $remoteIp);
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
                $this->storeNotifyHistory($orderId, $payload, 'failed', 'sid_mismatch', $source, $remoteIp);
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
                $this->storeNotifyHistory($orderId, $payload, 'failed', 'currency_mismatch', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: currency mismatch', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'currency' => $payload['currencyCd'] ?? null,
                ]);

                return $this->notifyResult('FAIL', 'failed', 'currency_mismatch');
            }

            $expectedAmount = $this->resolveExpectedCvsAmount($order, $existingMeta);
            if ($expectedAmount === null) {
                $this->storeNotifyHistory($orderId, $payload, 'failed', 'invalid_payment_currency', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: payment currency is not chargeable', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                ]);

                return $this->notifyResult('FAIL', 'failed', 'invalid_payment_currency');
            }

            if ($expectedAmount > 0 && $amount !== $expectedAmount) {
                $this->storeNotifyHistory($orderId, $payload, 'failed', 'amount_mismatch', $source, $remoteIp);
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
                $this->storeNotifyHistory($orderId, $payload, 'failed', 'mid_mismatch', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: MID mismatch', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'received_mid' => $mid,
                    'expected_mid' => $expectedMid,
                ]);

                return $this->notifyResult('FAIL', 'failed', 'mid_mismatch');
            }

            $sanitized = $this->sanitizePgResponse($payload, self::NOTIFY_RESPONSE_KEYS);
            // 완료 meta 는 결제 완료 직전의 현재 meta 에 통보 이력을 덧붙여 만든다(교체 저장).
            $completedMeta = fn (array $current): array => array_merge(
                $this->appendNotifyHistory($current, $payload, 'confirmed', 'deposit_confirmed', $source, $remoteIp),
                [
                    'result_code' => $status,
                    'pay_method' => 'CVS',
                    'auth_date' => ($payload['applDt'] ?? '').($payload['applTm'] ?? ''),
                    'mid' => $mid,
                    'currency' => $payload['currencyCd'] ?? 'JPY',
                    'is_cbt' => true,
                    'cbt_type' => 'JPPG',
                    'cbt_mid' => $mid,
                    'cbt_sid' => $payload['sid'] ?? ($current['cbt_sid'] ?? null),
                    'is_test_mode' => $current['is_test_mode'] ?? $this->apiService->isTestMode(),
                    'pg_response_sanitized' => true,
                    'pg_cvs_notify_response' => $sanitized,
                    'pg_raw_response' => $sanitized,
                    'cvs_status' => 'paid',
                    'cvs_convenience' => $payload['convenience'] ?? null,
                    'cvs_conf_no' => $payload['confNo'] ?? null,
                    'cvs_receipt_no' => $payload['receiptNo'] ?? null,
                    'cvs_payment_term' => $payload['paymentTerm'] ?? null,
                ],
            );

            $this->bridge->completePayment($orderId, 'kginicis', new PaymentCompletion(
                transactionId: $tid,
                paymentMeta: $completedMeta,
                takeOverProvider: true,
            ), $amount);

            Log::info('KG Inicis CBT CVS: deposit confirmed', [
                'order_id' => $orderId,
                'tid' => $tid,
                'amount' => $amount,
            ]);

            return $this->notifyResult('OK', 'confirmed', 'deposit_confirmed');
        } catch (\Throwable $e) {
            if ($payment instanceof PaymentRecord) {
                $this->storeNotifyHistory($orderId, $payload, 'failed', 'exception', $source, $remoteIp);
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
        $meta = $payment?->paymentMeta ?? [];
        $isCbtCvs = $payment !== null && $this->isCbtCvsMeta($meta);
        $paymentTerm = (string) ($meta['cvs_payment_term'] ?? '');
        $paymentTermAt = $this->parseCbtDateTime($paymentTerm);
        $paymentStatus = $payment !== null ? $this->paymentStatusValue($payment->paymentStatus) : '';
        $isWaitingDeposit = $paymentStatus === PaymentStatusEnum::WAITING_DEPOSIT->value;
        $isExpiredByTime = $paymentTermAt instanceof CarbonImmutable
            && $paymentTermAt->isPast()
            && ! in_array($paymentStatus, [PaymentStatusEnum::PAID->value, PaymentStatusEnum::EXPIRED->value], true);

        return [
            'is_cbt_cvs' => $isCbtCvs,
            'order_number' => $order->orderNumber,
            'order_status' => $this->enumValue($order->orderStatus),
            'payment_status' => $paymentStatus,
            'tid' => (string) ($payment?->transactionId ?? ''),
            // 결제 행이 있으면 그 결제액(0 포함), 없으면 주문 결제 예정액 — 종전 decimal 문자열 「0.00」 이 참으로 읽히던 판정과 같다
            'amount' => (int) ($meta['cvs_amount'] ?? ($payment !== null ? $payment->paidAmountLocal : $order->totalDueAmount)),
            'currency' => (string) ($payment?->currency ?? $order->currency ?? 'JPY'),
            'cbt_mid' => (string) ($meta['cbt_mid'] ?? ''),
            'cbt_sid' => (string) ($meta['cbt_sid'] ?? ''),
            'is_test_mode' => (bool) ($meta['is_test_mode'] ?? false),
            'convenience' => (string) ($meta['cvs_convenience'] ?? ''),
            'conf_no' => (string) ($meta['cvs_conf_no'] ?? ''),
            'receipt_no' => (string) ($meta['cvs_receipt_no'] ?? ''),
            'payment_term' => $paymentTerm,
            // 일본 시각 그대로 + 시간대 표기(D11)
            // audit:allow datetime-display-user-timezone reason: 일본 PG(CBT) 시각 — Asia/Tokyo 고정(D11)
            'payment_term_formatted' => $paymentTermAt ? $paymentTermAt->format('Y-m-d H:i:s').' (JST)' : null,
            'is_expired_by_time' => $isExpiredByTime,
            'cvs_status' => (string) ($meta['cvs_status'] ?? ($isWaitingDeposit ? 'waiting_deposit' : '')),
            'last_notify_at' => (string) ($meta['cvs_last_notify_at'] ?? ''),
            'last_notify_at_formatted' => $this->formatIsoForViewer($meta['cvs_last_notify_at'] ?? null),
            'last_notify_result' => (string) ($meta['cvs_last_notify_result'] ?? ''),
            'last_notify_reason' => (string) ($meta['cvs_last_notify_reason'] ?? ''),
            'notify_history' => array_map(
                fn (array $item): array => $item + ['received_at_formatted' => $this->formatIsoForViewer($item['received_at'] ?? null)],
                array_slice($this->normalizeHistory($meta['cvs_notify_history'] ?? []), 0, 10),
            ),
            'notify_url' => SiteUrl::absolute('/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify'),
            'can_simulate_notify' => $isCbtCvs && $isWaitingDeposit && (bool) ($meta['is_test_mode'] ?? false),
            'can_mark_expired' => $isCbtCvs && $isWaitingDeposit && $isExpiredByTime,
            'last_recheck_at' => (string) ($meta['cvs_last_recheck_at'] ?? ''),
            'last_recheck_at_formatted' => $this->formatIsoForViewer($meta['cvs_last_recheck_at'] ?? null),
            'last_recheck_result' => (string) ($meta['cvs_last_recheck_result'] ?? ''),
            'expired_at' => (string) ($meta['cvs_expired_at'] ?? ''),
            'expired_at_formatted' => $this->formatIsoForViewer($meta['cvs_expired_at'] ?? null),
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

        /** @var OrderRecord $order */
        $order = $context['order'];
        /** @var PaymentRecord $payment */
        $payment = $context['payment'];
        $meta = $context['meta'];

        if (! (bool) ($meta['is_test_mode'] ?? false)) {
            return $this->operationError('messages.cbt_cvs.not_test_mode', 422);
        }

        if (! $this->paymentStatusEquals($payment->paymentStatus, PaymentStatusEnum::WAITING_DEPOSIT)) {
            return $this->operationError('messages.cbt_cvs.not_waiting_deposit', 422);
        }

        $expectedAmount = $this->resolveExpectedCvsAmount($order, $meta);
        if ($expectedAmount === null) {
            return $this->operationError('messages.cbt_cvs.simulate_failed', 422);
        }

        // 일본 PG 의 입금 통보를 모사한다 — 입금 일시는 일본 시각으로 만든다(D11)
        // audit:allow datetime-display-user-timezone reason: 일본 PG(CBT) 시각 — Asia/Tokyo 고정(D11)
        $now = CarbonImmutable::now('Asia/Tokyo');
        $payload = [
            'tid' => $payment->transactionId ?: 'ADMIN_CVS_'.$order->orderNumber,
            'mid' => (string) ($meta['cbt_mid'] ?? $this->apiService->getJapanMid()),
            // audit:allow datetime-display-user-timezone reason: 일본 PG(CBT) 시각 — Asia/Tokyo 고정(D11)
            'applDt' => $now->format('Ymd'),
            // audit:allow datetime-display-user-timezone reason: 일본 PG(CBT) 시각 — Asia/Tokyo 고정(D11)
            'applTm' => $now->format('His'),
            'status' => '00',
            'payNm' => 'CVS',
            'orderId' => $order->orderNumber,
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

        // 잠근 결제 행 기준으로 만료 가능 여부를 다시 판정하고, 가능하면 상태(EXPIRED)와 meta 를 함께 바꾼다.
        $expired = $this->bridge->expireAwaitingDeposit(
            $orderNumber,
            'kginicis',
            fn (PaymentRecord $locked): bool => $this->canMarkExpired($locked, $locked->paymentMeta),
            fn (array $lockedMeta): array => array_merge($lockedMeta, [
                'cvs_status' => 'expired',
                // audit:allow datetime-display-user-timezone reason: ISO8601(오프셋 포함) API 기계값 — 표시는 *_formatted
                'cvs_expired_at' => now()->toIso8601String(),
                'cvs_expiry_reason' => 'payment_term_elapsed',
            ]),
        );

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

        $this->bridge->mergePaymentMeta($orderNumber, 'kginicis', [
            // audit:allow datetime-display-user-timezone reason: ISO8601(오프셋 포함) API 기계값 — 표시는 *_formatted
            'cvs_last_recheck_at' => now()->toIso8601String(),
            'cvs_last_recheck_result' => 'local_status_checked',
        ]);

        return [
            'ok' => true,
            'summary' => $this->summary($orderNumber),
        ];
    }

    /**
     * 관리자 운영 작업 대상인 CBT 편의점결제 주문·결제·메타를 조회합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return array<string, mixed> 성공 시 ok=true 와 order·payment·meta, 실패 시 ok=false 와 message_key·status
     */
    private function operationContext(string $orderNumber): array
    {
        $order = $this->findOrder($orderNumber);
        if (! $order) {
            return $this->operationError('messages.errors.order_not_found', 404);
        }

        $payment = $order->payment;
        $meta = $payment?->paymentMeta ?? [];

        if ($payment === null || ! $this->isCbtCvsMeta($meta)) {
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

    /**
     * 운영 작업 실패 결과 배열을 만듭니다.
     *
     * @param  string  $messageKey  실패 사유 다국어 키
     * @param  int  $status  HTTP 상태 코드
     * @param  array<string, mixed>  $context  결과에 함께 실을 문맥 (order·payment·meta 등)
     * @return array<string, mixed> ok=false 와 message_key·status 를 담은 결과
     */
    private function operationError(string $messageKey, int $status, array $context = []): array
    {
        return array_merge([
            'ok' => false,
            'message_key' => $messageKey,
            'status' => $status,
        ], $context);
    }

    /**
     * 결제 정보를 함께 불러온 주문을 조회합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return OrderRecord|null 주문 (없으면 null)
     */
    private function findOrder(string $orderNumber): ?OrderRecord
    {
        return $this->bridge->findOrderByNumber($orderNumber, 'kginicis');
    }

    /**
     * 입금통보 처리 결과 배열을 만듭니다.
     *
     * @param  string  $body  KG 이니시스에 돌려줄 응답 본문 (`OK` 또는 `FAIL`)
     * @param  string  $status  처리 결과 구분 (confirmed·ignored·failed 등)
     * @param  string  $reason  결과 사유 코드
     * @return array{body: string, status: string, reason: string} 입금통보 처리 결과
     */
    private function notifyResult(string $body, string $status, string $reason): array
    {
        return [
            'body' => $body,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    /**
     * 결제 행을 잠근 뒤 입금통보 수신 이력을 결제 메타에 추가 저장합니다.
     *
     * 이력 append 라 잠금 안에서 현재 meta 로 계산한다(경쟁 시 이력이 빠지지 않게).
     *
     * @param  string  $orderNumber  주문번호
     * @param  array<string, mixed>  $payload  입금통보 payload
     * @param  string  $result  처리 결과 구분
     * @param  string  $reason  결과 사유 코드
     * @param  string  $source  수신 경로 구분
     * @param  string|null  $remoteIp  통보 발신 IP
     * @return array<string, mixed> 이력이 추가된 결제 메타
     */
    private function storeNotifyHistory(
        string $orderNumber,
        array $payload,
        string $result,
        string $reason,
        string $source,
        ?string $remoteIp
    ): array {
        return $this->bridge->mutatePaymentMetaWithLock(
            $orderNumber,
            'kginicis',
            fn (array $current): array => $this->appendNotifyHistory($current, $payload, $result, $reason, $source, $remoteIp),
        ) ?? [];
    }

    /**
     * 입금통보 수신 이력 한 건을 결제 메타 앞쪽에 덧붙이고 최근 통보 요약을 갱신합니다.
     *
     * 이력은 최근 HISTORY_LIMIT 건만 남긴다.
     *
     * @param  array<string, mixed>  $existingMeta  기존 결제 메타
     * @param  array<string, mixed>  $payload  입금통보 payload
     * @param  string  $result  처리 결과 구분
     * @param  string  $reason  결과 사유 코드
     * @param  string  $source  수신 경로 구분
     * @param  string|null  $remoteIp  통보 발신 IP
     * @return array<string, mixed> 이력이 추가된 결제 메타
     */
    private function appendNotifyHistory(
        array $existingMeta,
        array $payload,
        string $result,
        string $reason,
        string $source,
        ?string $remoteIp
    ): array {
        // audit:allow datetime-display-user-timezone reason: ISO8601(오프셋 포함) API 기계값 — 표시는 *_formatted
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

    /**
     * 저장된 입금통보 이력에서 배열 항목만 남겨 목록으로 정리합니다.
     *
     * @param  mixed  $history  결제 메타의 이력 값
     * @return array<int, array<string, mixed>> 정리된 이력 목록 (배열이 아니면 빈 배열)
     */
    private function normalizeHistory(mixed $history): array
    {
        if (! is_array($history)) {
            return [];
        }

        return array_values(array_filter($history, static fn ($item): bool => is_array($item)));
    }

    /**
     * 결제 메타가 일본 CBT 편의점결제 건인지 판정합니다.
     *
     * @param  array<string, mixed>  $meta  결제 메타
     * @return bool CBT 이면서 결제수단이 CVS 이면 true
     */
    private function isCbtCvsMeta(array $meta): bool
    {
        return ($meta['is_cbt'] ?? false) === true
            && strtoupper((string) ($meta['pay_method'] ?? '')) === 'CVS';
    }

    /**
     * 잠금된 결제 row 기준으로 CVS 만료 전환 가능 여부를 다시 판정합니다.
     *
     * @param  PaymentRecord  $payment  잠금된 결제 row
     * @param  array<string, mixed>  $meta  결제 메타
     * @return bool 만료 전환 가능 여부
     */
    private function canMarkExpired(PaymentRecord $payment, array $meta): bool
    {
        if (! $this->isCbtCvsMeta($meta)) {
            return false;
        }

        if (! $this->paymentStatusEquals($payment->paymentStatus, PaymentStatusEnum::WAITING_DEPOSIT)) {
            return false;
        }

        $paymentTermAt = $this->parseCbtDateTime((string) ($meta['cvs_payment_term'] ?? ''));

        return $paymentTermAt instanceof CarbonImmutable && $paymentTermAt->isPast();
    }

    /**
     * 편의점 입금통보 금액과 대조할 기대 청구액을 구합니다.
     *
     * 결제 메타의 발급 금액이 있으면 그 값을, 없으면 결제 통화 기준 주문 청구액을 쓴다.
     *
     * @param  OrderRecord  $order  대상 주문
     * @param  array<string, mixed>  $paymentMeta  결제 메타
     * @return int|null 기대 청구액 (구할 수 없으면 null)
     */
    private function resolveExpectedCvsAmount(OrderRecord $order, array $paymentMeta): ?int
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

    /**
     * CBT 응답의 일시 문자열(숫자 14자리 YmdHis, 구분자 허용)을 일시 객체로 바꿉니다.
     *
     * @param  string|null  $value  CBT 일시 문자열
     * @return CarbonImmutable|null 일시 (형식이 맞지 않으면 null)
     */
    private function parseCbtDateTime(?string $value): ?CarbonImmutable
    {
        $compact = preg_replace('/\D+/', '', (string) $value);
        if (! is_string($compact) || strlen($compact) !== 14) {
            return null;
        }

        try {
            // CBT 일시는 일본 PG 의 일본 시각이다 — 일본 시간으로 읽어 순간값으로 만든다(UTC 로 읽으면 9시간 늦게 만료된다).
            // audit:allow datetime-display-user-timezone reason: 일본 PG(CBT) 시각 — Asia/Tokyo 고정(D11)
            return CarbonImmutable::createFromFormat('YmdHis', $compact, 'Asia/Tokyo') ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 저장된 ISO8601 시각을 보는 사람 시간대 표시 문자열로 바꿉니다. (화면 응답 전용 *_formatted)
     *
     * @param  mixed  $iso  저장된 ISO8601 시각
     * @return string|null 표시용 일시 (값이 없거나 해석할 수 없으면 null)
     */
    private function formatIsoForViewer(mixed $iso): ?string
    {
        if (! is_string($iso) || $iso === '') {
            return null;
        }

        try {
            return TimezoneHelper::toUserDateTimeString(Carbon::parse($iso));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 결제 상태가 기대 상태와 같은지 판정합니다(Enum·문자열 모두 허용).
     *
     * @param  mixed  $status  결제 상태 (PaymentStatusEnum 또는 문자열)
     * @param  PaymentStatusEnum  $expected  기대 결제 상태
     * @return bool 같으면 true
     */
    private function paymentStatusEquals(mixed $status, PaymentStatusEnum $expected): bool
    {
        return $this->paymentStatusValue($status) === $expected->value;
    }

    /**
     * 결제 상태를 문자열 값으로 바꿉니다.
     *
     * @param  mixed  $status  결제 상태 (PaymentStatusEnum 또는 문자열)
     * @return string 결제 상태 값
     */
    private function paymentStatusValue(mixed $status): string
    {
        if ($status instanceof PaymentStatusEnum) {
            return $status->value;
        }

        return (string) $status;
    }

    /**
     * BackedEnum 이면 그 값을, 아니면 문자열 변환 값을 돌려줍니다.
     *
     * @param  mixed  $value  Enum 또는 스칼라 값
     * @return string 문자열 값
     */
    private function enumValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }
}
