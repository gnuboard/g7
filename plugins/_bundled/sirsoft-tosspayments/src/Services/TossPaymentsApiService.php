<?php

namespace Plugins\Sirsoft\Tosspayments\Services;

use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Tosspayments\Exceptions\TossPaymentsApiException;

/**
 * 토스페이먼츠 API 호출 서비스
 *
 * 결제 승인, 취소 등 토스페이먼츠 REST API를 호출합니다.
 * Secret Key를 Basic 인증 헤더로 전달합니다.
 */
class TossPaymentsApiService
{
    /**
     * 토스페이먼츠 API 베이스 URL
     */
    private const BASE_URL = 'https://api.tosspayments.com';

    private const PLUGIN_IDENTIFIER = 'sirsoft-tosspayments';

    private string $secretKey;

    /**
     * @param  PluginSettingsService  $pluginSettingsService  플러그인 설정 서비스
     */
    public function __construct(PluginSettingsService $pluginSettingsService)
    {
        $settings = $pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $isTest = $settings['is_test_mode'] ?? true;
        $this->secretKey = $isTest
            ? ($settings['test_secret_key'] ?? '')
            : ($settings['live_secret_key'] ?? '');
    }

    /**
     * 결제 승인 API 호출
     *
     * @param  string  $paymentKey  토스 결제 키
     * @param  string  $orderId  주문번호
     * @param  int  $amount  결제 금액
     * @return array PG 응답 데이터
     *
     * @throws TossPaymentsApiException API 호출 실패 시
     */
    public function confirmPayment(string $paymentKey, string $orderId, int $amount): array
    {
        return $this->request('POST', '/v1/payments/confirm', [
            'paymentKey' => $paymentKey,
            'orderId' => $orderId,
            'amount' => $amount,
        ]);
    }

    /**
     * 결제 취소 API 호출
     *
     * 토스는 cancelAmount 를 넣지 않으면 전액 취소로 처리한다. 전액 취소를 부분 취소로
     * 보내지 않도록, 호출자가 전액이라고 판단한 경우 null 을 넘겨야 한다.
     *
     * @param  string  $paymentKey  토스 결제 키
     * @param  string  $cancelReason  취소 사유
     * @param  int|null  $cancelAmount  부분 취소 금액 (null이면 전액 취소)
     * @param  int|null  $taxFreeAmount  취소 금액 중 면세 금액 (복합과세 상점의 VAT 오산 방지)
     * @param  array|null  $refundReceiveAccount  가상계좌 환불 계좌 {bank, accountNumber, holderName}
     * @param  string|null  $idempotencyKey  멱등키 (네트워크 재시도 시 중복 취소 방지)
     * @return array PG 응답 데이터
     *
     * @throws TossPaymentsApiException API 호출 실패 시
     */
    public function cancelPayment(
        string $paymentKey,
        string $cancelReason,
        ?int $cancelAmount = null,
        ?int $taxFreeAmount = null,
        ?array $refundReceiveAccount = null,
        ?string $idempotencyKey = null,
    ): array {
        $body = ['cancelReason' => $cancelReason];

        if ($cancelAmount !== null) {
            $body['cancelAmount'] = $cancelAmount;
        }

        if ($taxFreeAmount !== null) {
            $body['taxFreeAmount'] = $taxFreeAmount;
        }

        if ($refundReceiveAccount !== null) {
            $body['refundReceiveAccount'] = $refundReceiveAccount;
        }

        // 훅: 결제 취소 전 (본인인증 등 확장 지점)
        HookManager::doAction('sirsoft-tosspayments.payment.before_cancel', $paymentKey, $cancelReason, $cancelAmount);

        $response = $this->request('POST', "/v1/payments/{$paymentKey}/cancel", $body, $idempotencyKey);

        HookManager::doAction('sirsoft-tosspayments.payment.after_cancel', $paymentKey, $response);

        return $response;
    }

    /**
     * 현금영수증 발급 API 호출
     *
     * @param  array  $data  발급 데이터 {amount, orderId, orderName, type, customerIdentityNumber, taxFreeAmount?}
     * @param  string|null  $idempotencyKey  멱등키 (네트워크 재시도 시 이중 발급 방지)
     * @return array PG 응답 데이터 (receiptKey, receiptUrl, issueNumber 등)
     *
     * @throws TossPaymentsApiException API 호출 실패 시
     */
    public function issueCashReceipt(array $data, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/v1/cash-receipts', $data, $idempotencyKey);
    }

    /**
     * 현금영수증 취소 API 호출
     *
     * 본문에 amount 를 넣으면 부분취소가 되지만 의도적으로 넣지 않는다 — 전액취소 전용이다.
     * 이래야 타 프로바이더(팝빌·바로빌 등) 어댑터가 동일 시그니처를 만족한다.
     *
     * cancelReason 은 토스의 필수 파라미터다. 누락하면 400 INVALID_REQUEST
     * ("필수 파라미터가 누락되었습니다.") 로 거부된다.
     *
     * @param  string  $receiptKey  토스 영수증 키
     * @param  string  $cancelReason  취소 사유
     * @param  string|null  $idempotencyKey  멱등키 (네트워크 재시도 시 이중 취소 방지)
     * @return array PG 응답 데이터
     *
     * @throws TossPaymentsApiException API 호출 실패 시
     */
    public function cancelCashReceipt(string $receiptKey, string $cancelReason, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', "/v1/cash-receipts/{$receiptKey}/cancel", [
            'cancelReason' => $cancelReason,
        ], $idempotencyKey);
    }

    /**
     * 토스페이먼츠 API 요청
     *
     * @param  string  $method  HTTP 메서드
     * @param  string  $path  API 경로
     * @param  array  $body  요청 본문
     * @param  string|null  $idempotencyKey  멱등키 (지정 시 Idempotency-Key 헤더 부착)
     * @return array 응답 데이터
     *
     * @throws TossPaymentsApiException API 호출 실패 시
     */
    private function request(string $method, string $path, array $body, ?string $idempotencyKey = null): array
    {
        $authHeader = 'Basic '.base64_encode($this->secretKey.':');

        $headers = [
            'Authorization' => $authHeader,
            'Content-Type' => 'application/json',
        ];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $response = Http::withHeaders($headers)
            ->{strtolower($method)}(self::BASE_URL.$path, $body);

        if ($response->failed()) {
            $error = $response->json();

            Log::error('TossPayments API error', [
                'path' => $path,
                'status' => $response->status(),
                'error_code' => $error['code'] ?? 'UNKNOWN',
                'error_message' => $error['message'] ?? '',
            ]);

            throw new TossPaymentsApiException(
                $error['message'] ?? 'TossPayments API error',
                $response->status()
            );
        }

        return $response->json();
    }
}
