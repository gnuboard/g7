<?php

namespace Plugins\Sirsoft\Tosspayments\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * @param PluginSettingsService $pluginSettingsService 플러그인 설정 서비스
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
     * @param string $paymentKey 토스 결제 키
     * @param string $orderId 주문번호
     * @param int $amount 결제 금액
     * @return array PG 응답 데이터
     * @throws \Exception API 호출 실패 시
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
     * @param string $paymentKey 토스 결제 키
     * @param string $cancelReason 취소 사유
     * @param int|null $cancelAmount 부분 취소 금액 (null이면 전액 취소)
     * @return array PG 응답 데이터
     * @throws \Exception API 호출 실패 시
     */
    public function cancelPayment(string $paymentKey, string $cancelReason, ?int $cancelAmount = null): array
    {
        $body = ['cancelReason' => $cancelReason];

        if ($cancelAmount !== null) {
            $body['cancelAmount'] = $cancelAmount;
        }

        return $this->request('POST', "/v1/payments/{$paymentKey}/cancel", $body);
    }

    /**
     * 토스페이먼츠 API 요청
     *
     * @param string $method HTTP 메서드
     * @param string $path API 경로
     * @param array $body 요청 본문
     * @return array 응답 데이터
     * @throws \Exception API 호출 실패 시
     */
    private function request(string $method, string $path, array $body): array
    {
        $authHeader = 'Basic ' . base64_encode($this->secretKey . ':');

        $response = Http::withHeaders([
            'Authorization' => $authHeader,
            'Content-Type' => 'application/json',
        ])->{strtolower($method)}(self::BASE_URL . $path, $body);

        if ($response->failed()) {
            $error = $response->json();

            Log::error('TossPayments API error', [
                'path' => $path,
                'status' => $response->status(),
                'error_code' => $error['code'] ?? 'UNKNOWN',
                'error_message' => $error['message'] ?? '',
            ]);

            throw new \Exception(
                $error['message'] ?? 'TossPayments API error',
                $response->status()
            );
        }

        return $response->json();
    }
}
