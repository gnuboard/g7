<?php

namespace Plugins\Sirsoft\Tosspayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\Tosspayments\Services\TossPaymentsApiService;

/**
 * 현금영수증 발급 프로바이더 리스너
 *
 * 이커머스 모듈의 현금영수증 훅 축(등록/발급/취소)을 구독해 토스페이먼츠
 * 현금영수증 API 로 위임합니다. PG 결제사와 독립적으로 선택할 수 있으므로
 * KG이니시스로 결제하면서 영수증만 토스로 발급하는 구성도 가능합니다.
 */
class RegisterCashReceiptProviderListener implements HookListenerInterface
{
    /**
     * 현금영수증 프로바이더 식별자
     */
    private const PROVIDER_ID = 'tosspayments';

    /**
     * 토스 orderId 제약 — 6~64자, 영문·숫자·`-`·`_`
     */
    private const ORDER_ID_PATTERN = '/^[A-Za-z0-9_-]{6,64}$/';

    /**
     * 구독할 훅 매핑 반환
     *
     * @return array 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.cash_receipt.registered_providers' => [
                'method' => 'registerProvider',
                'type' => 'filter',
                'priority' => 10,
            ],
            'sirsoft-ecommerce.cash_receipt.issue' => [
                'method' => 'issue',
                'type' => 'filter',
                'priority' => 10,
            ],
            'sirsoft-ecommerce.cash_receipt.cancel' => [
                'method' => 'cancel',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용 — 개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인수
     */
    public function handle(...$args): void {}

    /**
     * 현금영수증 프로바이더 목록에 토스페이먼츠를 등록합니다.
     *
     * @param  array  $providers  기존 프로바이더 목록
     * @return array 토스페이먼츠가 추가된 목록
     */
    public function registerProvider(array $providers): array
    {
        $providers[] = [
            'id' => self::PROVIDER_ID,
            'name' => '토스페이먼츠',
            'name_key' => 'sirsoft-tosspayments::messages.cash_receipt.provider_name',
            'icon' => 'receipt',
            'supports' => [
                CashReceiptType::INCOME->value,
                CashReceiptType::EXPENSE->value,
            ],
        ];

        return $providers;
    }

    /**
     * 현금영수증을 발급합니다.
     *
     * @param  array  $result  이전 필터 누적 결과
     * @param  Order  $order  주문
     * @param  string  $providerId  발급 대상 프로바이더 ID
     * @param  array  $payload  발급 페이로드 (코어가 조립)
     * @return array 발급 결과 {success, error_code, error_message, receipt_key, receipt_url, issue_number, raw_response}
     */
    public function issue(array $result, Order $order, string $providerId, array $payload): array
    {
        if ($providerId !== self::PROVIDER_ID) {
            return $result;
        }

        $orderId = $this->buildCashReceiptOrderId($payload);

        if ($orderId === null) {
            Log::warning('토스페이먼츠 현금영수증 orderId 제약 위반', [
                'order_id' => $order->id,
                'order_number' => $payload['order_number'] ?? null,
            ]);

            return [
                'success' => false,
                'error_code' => 'INVALID_ORDER_ID',
                'error_message' => __('sirsoft-tosspayments::messages.cash_receipt.invalid_order_id'),
                'receipt_key' => null,
                'receipt_url' => null,
                'issue_number' => null,
                'raw_response' => null,
            ];
        }

        $body = [
            'amount' => (int) ($payload['amount'] ?? 0),
            'orderId' => $orderId,
            'orderName' => (string) ($payload['order_name'] ?? ''),
            'customerIdentityNumber' => (string) ($payload['identifier'] ?? ''),
            // 토스는 한글 문자열을 요구한다. 코어 enum(income/expense) 변환은 여기서만 수행한다.
            'type' => $this->resolveTossType($payload['type'] ?? null),
        ];

        $taxFree = (int) ($payload['tax_free_amount'] ?? 0);
        if ($taxFree > 0) {
            $body['taxFreeAmount'] = $taxFree;
        }

        try {
            // orderId 는 발급 회차(-cr{seq})를 포함해 이 발급 시도에 고유하고, 재시도 간에도
            // 동일하다 — 멱등키로 그대로 쓰면 재시도로 인한 이중 발급을 토스가 차단한다.
            $response = $this->getApiService()->issueCashReceipt($body, $orderId);

            Log::info('토스페이먼츠 현금영수증 발급 성공', [
                'order_id' => $order->id,
                'receipt_key' => $response['receiptKey'] ?? null,
            ]);

            return [
                'success' => true,
                'error_code' => null,
                'error_message' => null,
                'receipt_key' => $response['receiptKey'] ?? null,
                'receipt_url' => $response['receiptUrl'] ?? null,
                'issue_number' => $response['issueNumber'] ?? null,
                'raw_response' => $response,
            ];
        } catch (\Exception $e) {
            Log::error('토스페이먼츠 현금영수증 발급 실패', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error_code' => 'PG_API_ERROR',
                'error_message' => $e->getMessage(),
                'receipt_key' => null,
                'receipt_url' => null,
                'issue_number' => null,
                'raw_response' => null,
            ];
        }
    }

    /**
     * 현금영수증을 전액취소합니다.
     *
     * 부분취소는 사용하지 않는다 (D7) — 금액 인자가 없다.
     *
     * @param  array  $result  이전 필터 누적 결과
     * @param  Order  $order  주문
     * @param  string  $providerId  발급 프로바이더 ID
     * @param  string  $receiptKey  취소할 영수증 키
     * @return array 취소 결과 {success, error_code, error_message, receipt_key, raw_response}
     */
    public function cancel(array $result, Order $order, string $providerId, string $receiptKey): array
    {
        if ($providerId !== self::PROVIDER_ID) {
            return $result;
        }

        try {
            // 취소 대상 영수증 키는 재시도 간에 안정적이다 — 멱등키로 쓰면 재시도가
            // 이미 취소된 영수증을 다시 취소하려다 실패하는 것을 토스가 차단한다.
            $response = $this->getApiService()->cancelCashReceipt(
                $receiptKey,
                __('sirsoft-tosspayments::messages.cash_receipt.cancel_reason'),
                'cr-cancel-'.$receiptKey,
            );

            Log::info('토스페이먼츠 현금영수증 취소 성공', [
                'order_id' => $order->id,
                'receipt_key' => $receiptKey,
            ]);

            return [
                'success' => true,
                'error_code' => null,
                'error_message' => null,
                // 응답의 receiptKey 는 'c_' 접두가 붙은 "취소 거래" 키다. 취소 행에는
                // 취소된 영수증의 키를 그대로 보관해야 코어의 filterActive() 가
                // 발급 행과 짝지어 비활성화할 수 있다 (안 그러면 영구히 활성으로 남는다).
                'receipt_key' => $receiptKey,
                'raw_response' => $response,
            ];
        } catch (\Exception $e) {
            Log::error('토스페이먼츠 현금영수증 취소 실패', [
                'order_id' => $order->id,
                'receipt_key' => $receiptKey,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error_code' => 'PG_API_ERROR',
                'error_message' => $e->getMessage(),
                'receipt_key' => $receiptKey,
                'raw_response' => null,
            ];
        }
    }

    /**
     * 발급 요청의 orderId 를 조립합니다.
     *
     * 현금영수증의 orderId 는 결제의 orderId 와 같은 네임스페이스가 아니다. 재발급 시
     * 동일 orderId 를 재사용하면 토스가 중복 거부하므로 발급 회차를 접미한다.
     * 코어는 회차 숫자(issue_sequence)만 제공하고, 문자열 조립은 프로바이더가 한다.
     *
     * @param  array  $payload  발급 페이로드
     * @return string|null 토스 제약을 만족하는 orderId (위반 시 null)
     */
    private function buildCashReceiptOrderId(array $payload): ?string
    {
        $orderNumber = (string) ($payload['order_number'] ?? '');
        $sequence = max(1, (int) ($payload['issue_sequence'] ?? 1));

        $orderId = "{$orderNumber}-cr{$sequence}";

        return preg_match(self::ORDER_ID_PATTERN, $orderId) === 1 ? $orderId : null;
    }

    /**
     * 코어 발급 용도(income/expense)를 토스가 요구하는 한글 문자열로 변환합니다.
     *
     * @param  string|null  $type  코어 enum 값
     * @return string 토스 type (소득공제 | 지출증빙)
     */
    private function resolveTossType(?string $type): string
    {
        return CashReceiptType::fromLegacy($type) === CashReceiptType::EXPENSE
            ? '지출증빙'
            : '소득공제';
    }

    /**
     * API 서비스 인스턴스를 가져옵니다.
     */
    protected function getApiService(): TossPaymentsApiService
    {
        return app(TossPaymentsApiService::class);
    }
}
