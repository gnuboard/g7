<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Controllers;

use App\Services\PluginSettingsService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\Tosspayments\Concerns\PreventsReplayCallback;
use Plugins\Sirsoft\Tosspayments\Http\Requests\DepositWebhookRequest;
use Plugins\Sirsoft\Tosspayments\Http\Requests\PaymentStatusWebhookRequest;

/**
 * 토스페이먼츠 웹훅 컨트롤러.
 *
 * 가상계좌 입금통보(DEPOSIT_CALLBACK)와 결제상태 변경(PAYMENT_STATUS_CHANGED)을 처리한다.
 * 토스는 notify IP 목록·서명을 제공하지 않으므로 공식 검증 수단은 secret 대조뿐이다.
 * 미응답 시 최대 7회 재전송하므로 부수 작업은 훅 리스너(completePayment 내부)에 위임하고
 * 컨트롤러는 빠르게 200 을 반환한다.
 */
class WebhookController
{
    use PreventsReplayCallback;

    private const PLUGIN_IDENTIFIER = 'sirsoft-tosspayments';

    public function __construct(
        private OrderProcessingService $orderService,
        private PluginSettingsService $pluginSettingsService,
    ) {}

    /**
     * 가상계좌 입금통보 웹훅.
     *
     * POST /plugins/sirsoft-tosspayments/webhook/deposit
     *
     * status=DONE → completePayment(). status=CANCELED → failPayment().
     *
     * @param  DepositWebhookRequest  $request  검증된 웹훅 요청
     * @return Response 토스에 반환할 처리 결과 (항상 text/plain)
     */
    public function deposit(DepositWebhookRequest $request): Response
    {
        $validated = $request->validated();
        $orderId = (string) $validated['orderId'];
        $status = (string) $validated['status'];
        $secret = (string) ($validated['secret'] ?? '');
        $transactionKey = (string) ($validated['transactionKey'] ?? '');

        Log::info('TossPayments: deposit webhook received', [
            'orderId' => $orderId,
            'status' => $status,
        ]);

        try {
            $order = $this->orderService->findByOrderNumber($orderId);

            if (! $order) {
                Log::error('TossPayments: deposit webhook - order not found', ['orderId' => $orderId]);

                return $this->plain('FAIL');
            }

            $order->load('payment');
            $payment = $order->payment;

            if (! $payment) {
                Log::error('TossPayments: deposit webhook - payment not found', ['orderId' => $orderId]);

                return $this->plain('FAIL');
            }

            // 1. 리플레이 — 이미 결제완료된 거래이면 멱등 200
            if ($this->wasAlreadyPaid($payment->transaction_id)) {
                $this->logReplayDetected((string) $payment->transaction_id, $orderId, 'deposit webhook');

                return $this->plain('OK');
            }

            // 2. secret 대조 (위조 방지) — 설정으로 강제 시에만
            $storedSecret = (string) ($payment->payment_meta['toss_secret'] ?? '');
            if ($this->shouldVerifySecret() && ! $this->secretMatches($storedSecret, $secret)) {
                Log::warning('TossPayments: deposit webhook secret mismatch', [
                    'orderId' => $orderId,
                ]);

                return $this->plain('UNAUTHORIZED', 401);
            }

            // 3. 취소된 주문의 입금통보는 받지 않는다 (코어 P4 와 같은 가드).
            //
            // 고객이 취소한 뒤에 입금해도 통보는 도착한다. 통과시키면 `CANCELLED` 주문이
            // 결제완료로 되살아나 재고·마일리지가 재차감된다. 아래 결제상태 가드만으로는
            // 부족하다 — 미입금 취소가 결제행을 `waiting_deposit` 그대로 두던 시절의 행이
            // 그 가드를 통과한다. 재전송을 멈추도록 200 으로 응답한다.
            if ($order->order_status === OrderStatusEnum::CANCELLED) {
                Log::warning('TossPayments: deposit webhook - order is cancelled', [
                    'orderId' => $orderId,
                ]);

                return $this->plain('OK');
            }

            // 4. 저장 컨텍스트 검증 — 입금대기 상태여야 함
            $paymentStatus = $payment->payment_status instanceof PaymentStatusEnum
                ? $payment->payment_status->value
                : (string) $payment->payment_status;

            if ($paymentStatus !== PaymentStatusEnum::WAITING_DEPOSIT->value) {
                Log::warning('TossPayments: deposit webhook - not waiting for deposit', [
                    'orderId' => $orderId,
                    'payment_status' => $paymentStatus,
                ]);

                // 이미 처리됐거나 실패한 주문 — 재전송을 멈추도록 200 으로 응답
                return $this->plain('OK');
            }

            // 5. 상태별 처리 (부수 작업은 completePayment/failPayment 내부 훅에 위임)
            if ($status === 'DONE') {
                // `transaction_id` 는 **취소 API 가 쓰는 paymentKey** 다 — 발급 시점에 저장해 둔
                // 값을 덮어쓰면 안 된다. 입금통보가 싣고 오는 `transactionKey` 는 거래 회차마다
                // 달라지는 별개 식별자(`txrd_…`)이고, 그것으로 취소를 부르면 토스가
                // **「존재하지 않는 결제 정보 입니다」** 로 거절한다(S1-D10 — 실측: 가상계좌 환불이
                // 승인까지 간 뒤 실행에서만 실패). 발급 때 저장된 값이 없을 때만 폴백으로 쓴다.
                $this->orderService->completePayment($order, [
                    'transaction_id' => $payment->transaction_id ?: ($transactionKey ?: null),
                    'payment_meta' => array_merge($payment->payment_meta ?? [], [
                        'deposit_confirmed_at' => $validated['createdAt'] ?? null,
                        'webhook_status' => 'DONE',
                        // 입금통보의 거래 식별자는 추적용으로 따로 남긴다.
                        'deposit_transaction_key' => $transactionKey ?: null,
                    ]),
                ], (int) $payment->paid_amount_base ?: null);

                Log::info('TossPayments: deposit confirmed', ['orderId' => $orderId]);

                return $this->plain('OK');
            }

            // status === CANCELED → 입금 취소
            $this->orderService->failPayment($order, 'DEPOSIT_CANCELED', 'Virtual account deposit canceled');

            Log::info('TossPayments: deposit canceled', ['orderId' => $orderId]);

            return $this->plain('OK');

        } catch (\Throwable $e) {
            Log::error('TossPayments: deposit webhook failed', [
                'orderId' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return $this->plain('FAIL');
        }
    }

    /**
     * 결제상태 변경 웹훅 — 상태 동기화 로깅 (불일치 시 경고).
     *
     * POST /plugins/sirsoft-tosspayments/webhook/payment-status
     *
     * @param  PaymentStatusWebhookRequest  $request  검증된 웹훅 요청
     * @return Response 토스에 반환할 처리 결과
     */
    public function paymentStatus(PaymentStatusWebhookRequest $request): Response
    {
        $data = $request->validated()['data'];
        $orderId = (string) $data['orderId'];
        $pgStatus = (string) $data['status'];

        Log::info('TossPayments: payment-status webhook received', [
            'orderId' => $orderId,
            'status' => $pgStatus,
        ]);

        // 클레임 추가결제(CP 채번)는 주문이 아니다 — 주문 조회 분기를 그대로 태우면 정상 동작마다
        // `order not found` warning 이 남아 운영자 로그에서 결함으로 읽힌다. 이 핸들러는 상태
        // 동기화 로깅 전용이라 클레임 결제 건에 대해 별도로 반영할 상태가 없으므로 그대로 종결한다.
        if (ClaimPayment::isClaimPaymentNumber($orderId)) {
            Log::info('TossPayments: payment-status webhook - claim payment (no order lookup)', [
                'payment_number' => $orderId,
                'toss_status' => $pgStatus,
            ]);

            return $this->plain('OK');
        }

        $order = $this->orderService->findByOrderNumber($orderId);

        if (! $order) {
            Log::warning('TossPayments: payment-status webhook - order not found', ['orderId' => $orderId]);

            return $this->plain('OK');
        }

        $order->load('payment');
        $localStatus = $order->payment?->payment_status;
        $localStatusValue = $localStatus instanceof PaymentStatusEnum ? $localStatus->value : (string) $localStatus;

        Log::info('TossPayments: payment-status synced', [
            'orderId' => $orderId,
            'toss_status' => $pgStatus,
            'local_status' => $localStatusValue,
        ]);

        return $this->plain('OK');
    }

    /**
     * 웹훅 secret 대조 강제 여부.
     */
    private function shouldVerifySecret(): bool
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];

        return (bool) ($settings['webhook_secret_verify'] ?? true);
    }

    /**
     * 웹훅 secret 이 저장된 confirm 응답 secret 과 일치하는지 확인.
     *
     * @param  string  $storedSecret  결제 승인 응답에서 저장한 secret (payment_meta.toss_secret)
     * @param  string  $incomingSecret  웹훅 본문의 secret
     */
    private function secretMatches(string $storedSecret, string $incomingSecret): bool
    {
        if ($storedSecret === '' || $incomingSecret === '') {
            return false;
        }

        return hash_equals($storedSecret, $incomingSecret);
    }

    /**
     * text/plain 응답 생성 (토스 웹훅 규약).
     *
     * @param  string  $body  응답 본문
     * @param  int  $status  HTTP 상태 코드
     */
    private function plain(string $body, int $status = 200): Response
    {
        return response($body, $status)->header('Content-Type', 'text/plain');
    }
}
