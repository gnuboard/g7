<?php

namespace Plugins\Sirsoft\Tosspayments\Controllers;

use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\Tosspayments\Http\Requests\FailCallbackRequest;
use Plugins\Sirsoft\Tosspayments\Http\Requests\SuccessCallbackRequest;
use Plugins\Sirsoft\Tosspayments\Services\TossPaymentsApiService;

/**
 * 토스페이먼츠 결제 콜백 컨트롤러
 *
 * 토스페이먼츠 결제 완료/실패 후 브라우저 리다이렉트를 처리합니다.
 * 성공 시 Confirm API를 호출하고, 주문 상태를 업데이트합니다.
 */
class PaymentCallbackController
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-tosspayments';

    /**
     * 카드 발급사 코드 → 이름 매핑
     */
    private const CARD_ISSUER_MAP = [
        '3K' => '기업 BC',
        '46' => '광주',
        '71' => '롯데',
        '30' => '산업',
        '31' => '비씨',
        '51' => '삼성',
        '38' => '새마을금고',
        '41' => '신한',
        '62' => '신협',
        '36' => '씨티',
        '33' => '우리',
        'W1' => '우리',
        '37' => '우체국',
        '39' => '저축',
        '35' => '전북',
        '42' => '제주',
        '15' => '카카오뱅크',
        '3A' => '케이뱅크',
        '24' => '토스뱅크',
        '21' => '하나',
        '61' => '현대',
        '11' => 'KB국민',
        '91' => 'NH농협',
        '34' => 'Sh수협',
    ];

    public function __construct(
        private OrderProcessingService $orderService,
        private PluginSettingsService $pluginSettingsService,
        private TossPaymentsApiService $apiService,
    ) {}

    /**
     * 토스페이먼츠 결제 성공 콜백
     *
     * GET /plugins/sirsoft-tosspayments/payment/success
     *     ?paymentKey={PK}&orderId={OID}&amount={AMT}
     *
     * @param SuccessCallbackRequest $request 검증된 콜백 요청
     * @return RedirectResponse SPA 페이지로 리다이렉트
     */
    public function success(SuccessCallbackRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $paymentKey = $validated['paymentKey'];
        $orderId = $validated['orderId'];
        $pgAmount = $validated['amount'];

        try {
            // 1. 주문 조회 (주문번호 기반)
            $order = $this->orderService->findByOrderNumber($orderId);

            if (! $order) {
                Log::error('TossPayments: order not found', ['orderId' => $orderId]);

                return redirect($this->resolveFailUrl(['error' => 'order_not_found', 'orderId' => $orderId]));
            }

            // 2. TossPayments Confirm API 호출
            HookManager::doAction('sirsoft-tosspayments.payment.before_confirm', $order, $paymentKey, $pgAmount);

            $pgResponse = $this->apiService->confirmPayment($paymentKey, $orderId, $pgAmount);

            HookManager::doAction('sirsoft-tosspayments.payment.after_confirm', $order, $pgResponse);

            // 3. 주문 상태 업데이트 (금액 검증 + PG 응답 → order_payments 매핑)
            $this->orderService->completePayment($order, [
                'transaction_id' => $pgResponse['paymentKey'] ?? null,
                'card_approval_number' => $pgResponse['card']['approveNo'] ?? null,
                'card_number_masked' => $pgResponse['card']['number'] ?? null,
                'card_name' => $this->resolveCardIssuer($pgResponse['card']['issuerCode'] ?? null),
                'card_installment_months' => $pgResponse['card']['installmentPlanMonths'] ?? 0,
                'is_interest_free' => $pgResponse['card']['isInterestFree'] ?? false,
                'embedded_pg_provider' => $pgResponse['easyPay']['provider'] ?? null,
                'receipt_url' => $pgResponse['receipt']['url'] ?? null,
                'payment_meta' => [
                    'method' => $pgResponse['method'] ?? null,
                    'pg_raw_response' => $pgResponse,
                ],
                'payment_device' => $this->detectDevice($request),
            ], $pgAmount);

            // 4. SPA 주문 완료 페이지로 redirect
            return redirect($this->resolveSuccessUrl($orderId));

        } catch (PaymentAmountMismatchException $e) {
            Log::error('TossPayments: amount mismatch', [
                'orderId' => $orderId,
                'expected' => $e->getExpectedAmount(),
                'actual' => $e->getActualAmount(),
                'context' => $e->getContext(),
            ]);

            return redirect($this->resolveFailUrl([
                'error' => 'amount_mismatch',
                'orderId' => $orderId,
            ]));

        } catch (\Exception $e) {
            Log::error('TossPayments: confirm failed', [
                'orderId' => $orderId,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            return redirect($this->resolveFailUrl([
                'error' => 'confirm_failed',
                'message' => $e->getMessage(),
                'orderId' => $orderId,
            ]));
        }
    }

    /**
     * 토스페이먼츠 결제 실패 콜백
     *
     * GET /plugins/sirsoft-tosspayments/payment/fail
     *     ?code={ERR}&message={MSG}&orderId={OID}
     *
     * @param FailCallbackRequest $request 검증된 콜백 요청
     * @return RedirectResponse SPA 체크아웃 페이지로 리다이렉트
     */
    public function fail(FailCallbackRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $code = $validated['code'] ?? 'UNKNOWN';
        $message = $validated['message'] ?? '';
        $orderId = $validated['orderId'] ?? null;

        Log::warning('TossPayments: payment failed', [
            'code' => $code,
            'message' => $message,
            'orderId' => $orderId,
        ]);

        if ($orderId) {
            $order = $this->orderService->findByOrderNumber($orderId);

            if ($order) {
                $this->orderService->failPayment($order, $code, $message);
            }
        }

        return redirect($this->resolveFailUrl([
            'error' => $code,
            'message' => $message,
            'orderId' => $orderId,
        ]));
    }

    /**
     * 카드 발급사 코드 → 이름 변환
     *
     * @param string|null $issuerCode 발급사 코드
     * @return string|null 카드사명
     */
    private function resolveCardIssuer(?string $issuerCode): ?string
    {
        if ($issuerCode === null) {
            return null;
        }

        return self::CARD_ISSUER_MAP[$issuerCode] ?? $issuerCode;
    }

    /**
     * 결제 성공 리다이렉트 URL 생성
     *
     * @param string $orderId 주문번호
     * @return string 리다이렉트 URL
     */
    private function resolveSuccessUrl(string $orderId): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $urlTemplate = $settings['redirect_success_url'] ?? '/shop/orders/{orderId}/complete';

        return str_replace('{orderId}', $orderId, $urlTemplate);
    }

    /**
     * 결제 실패 리다이렉트 URL 생성
     *
     * @param array $queryParams 쿼리 파라미터
     * @return string 리다이렉트 URL
     */
    private function resolveFailUrl(array $queryParams = []): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $baseUrl = $settings['redirect_fail_url'] ?? '/shop/checkout';

        if (empty($queryParams)) {
            return $baseUrl;
        }

        $query = http_build_query(array_filter($queryParams));
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . $query;
    }

    /**
     * 결제 디바이스 판별 (User-Agent 기반)
     *
     * @param Request $request HTTP 요청
     * @return string 디바이스 유형 (pc/mobile)
     */
    private function detectDevice(Request $request): string
    {
        $userAgent = $request->userAgent() ?? '';
        $mobileKeywords = ['Mobile', 'Android', 'iPhone', 'iPad', 'iPod'];

        foreach ($mobileKeywords as $keyword) {
            if (stripos($userAgent, $keyword) !== false) {
                return 'mobile';
            }
        }

        return 'pc';
    }
}
