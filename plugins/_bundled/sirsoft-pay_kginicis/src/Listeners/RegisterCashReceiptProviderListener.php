<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayKginicis\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

/**
 * 현금영수증 발급 프로바이더 리스너
 *
 * 이커머스 모듈의 공용 현금영수증 훅 축(등록/발급/취소)을 구독해 KG이니시스
 * 현금영수증 API 로 위임합니다. PG 결제사와 독립적으로 선택할 수 있으므로
 * 토스로 결제하면서 영수증만 KG로 발급하는 구성도 가능합니다.
 */
class RegisterCashReceiptProviderListener implements HookListenerInterface
{
    use SanitizesPgResponse;

    /**
     * 현금영수증 프로바이더 식별자
     */
    private const PROVIDER_ID = 'kginicis';

    /**
     * 발급/취소 PG 응답에서 보존할 필드
     */
    private const RESPONSE_KEYS = [
        'resultCode',
        'resultMsg',
        'tid',
        'TID',
        'authNo',
        'authNumber',
        'authDate',
        'authTime',
        'authPrice',
        'cshrCancelNum',
        'mid',
        'MID',
    ];

    /**
     * 구독할 훅 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 구독 설정
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
     * 현금영수증 프로바이더 목록에 KG이니시스를 등록합니다.
     *
     * @param  array  $providers  기존 프로바이더 목록
     * @return array KG이니시스가 추가된 목록
     */
    public function registerProvider(array $providers): array
    {
        $providers[] = [
            'id' => self::PROVIDER_ID,
            'name' => 'KG이니시스',
            'name_key' => 'sirsoft-pay_kginicis::messages.cash_receipt.provider_name',
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

        $amount = (int) ($payload['amount'] ?? 0);
        $taxFree = (int) ($payload['tax_free_amount'] ?? 0);
        [$supplyPrice, $tax] = $this->splitSupplyAndTax($amount, $taxFree);

        try {
            $response = app(KgInicisApiService::class)->issueCashReceipt([
                'issueType' => $this->resolveIssueType($payload['type'] ?? null),
                'issueNumber' => (string) ($payload['identifier'] ?? ''),
                'price' => $amount,
                'supplyPrice' => $supplyPrice,
                'tax' => $tax,
                'goodName' => (string) ($payload['order_name'] ?? ''),
                'buyerName' => $this->resolveBuyerName($order),
                'buyerEmail' => $this->resolveBuyerEmail($order),
                'buyerTel' => $this->resolveBuyerTel($order),
            ]);

            $resultCode = $response['resultCode'] ?? '';

            if ($resultCode !== '00') {
                Log::warning('KG Inicis: cash receipt issue failed', [
                    'order_id' => $order->id,
                    'result_code' => $resultCode,
                    'result_msg' => $response['resultMsg'] ?? '',
                ]);

                return [
                    'success' => false,
                    'error_code' => $resultCode !== '' ? $resultCode : 'PG_API_ERROR',
                    'error_message' => $response['resultMsg']
                        ?? __('sirsoft-pay_kginicis::messages.errors.cash_receipt_issue_failed'),
                    'receipt_key' => null,
                    'receipt_url' => null,
                    'issue_number' => null,
                    'raw_response' => $this->sanitizePgResponse($response, self::RESPONSE_KEYS),
                ];
            }

            Log::info('KG Inicis: cash receipt issued', [
                'order_id' => $order->id,
                'tid' => $response['tid'] ?? null,
            ]);

            return [
                'success' => true,
                'error_code' => null,
                'error_message' => null,
                // INIAPI 발급 응답의 tid = "현금영수증발급 거래번호". 취소 시 이 값을 쓴다.
                'receipt_key' => $response['tid'] ?? ($response['TID'] ?? null),
                // KG 는 영수증 URL 을 내려주지 않는다 (토스와 달리 조회 링크 부재).
                'receipt_url' => null,
                'issue_number' => $response['authNo'] ?? ($response['authNumber'] ?? null),
                'raw_response' => $this->sanitizePgResponse($response, self::RESPONSE_KEYS),
            ];
        } catch (\Exception $e) {
            Log::error('KG Inicis: cash receipt issue exception', [
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
     * KG 는 부분취소를 지원하지 않는다 — 전체취소 후 잔액 재발행이 규약이며,
     * 코어의 재발급 전략과 일치한다.
     *
     * @param  array  $result  이전 필터 누적 결과
     * @param  Order  $order  주문
     * @param  string  $providerId  발급 프로바이더 ID
     * @param  string  $receiptKey  발급 시 받은 현금영수증 TID
     * @return array 취소 결과 {success, error_code, error_message, receipt_key, raw_response}
     */
    public function cancel(array $result, Order $order, string $providerId, string $receiptKey): array
    {
        if ($providerId !== self::PROVIDER_ID) {
            return $result;
        }

        try {
            $response = app(KgInicisApiService::class)->cancelCashReceipt($receiptKey);

            Log::info('KG Inicis: cash receipt cancelled', [
                'order_id' => $order->id,
                'tid' => $receiptKey,
                'cancel_num' => $response['cshrCancelNum'] ?? null,
            ]);

            return [
                'success' => true,
                'error_code' => null,
                'error_message' => null,
                'receipt_key' => $receiptKey,
                'raw_response' => $this->sanitizePgResponse($response, self::RESPONSE_KEYS),
            ];
        } catch (\Exception $e) {
            Log::error('KG Inicis: cash receipt cancel failed', [
                'order_id' => $order->id,
                'tid' => $receiptKey,
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
     * 코어 발급 용도(income/expense)를 KG issueType(0/1) 으로 변환합니다.
     *
     * @param  string|null  $type  코어 enum 값
     * @return string KG issueType ('0'=소득공제, '1'=지출증빙)
     */
    private function resolveIssueType(?string $type): string
    {
        return CashReceiptType::fromLegacy($type) === CashReceiptType::EXPENSE ? '1' : '0';
    }

    /**
     * 발급 금액을 공급가액과 부가세로 나눕니다.
     *
     * 면세분은 부가세 계산에서 제외하고 공급가액에 포함시킨다. 반올림 잔차는
     * 공급가액에 귀속시켜 총액(price = supplyPrice + tax)을 보존한다.
     *
     * @param  int  $amount  발급 총액
     * @param  int  $taxFreeAmount  면세 금액
     * @return array{0: int, 1: int} [공급가액, 부가세]
     */
    private function splitSupplyAndTax(int $amount, int $taxFreeAmount): array
    {
        $taxFree = max(0, min($taxFreeAmount, $amount));
        $taxable = $amount - $taxFree;

        $tax = (int) round($taxable / 11);
        $supplyPrice = $amount - $tax;

        return [$supplyPrice, $tax];
    }

    /**
     * 구매자명을 해석합니다. (결제 → 주문 배송지 순)
     *
     * @param  Order  $order  주문
     * @return string 구매자명
     */
    private function resolveBuyerName(Order $order): string
    {
        return (string) ($order->payment?->buyer_name
            ?? $order->shippingAddress?->orderer_name
            ?? '');
    }

    /**
     * 구매자 이메일을 해석합니다.
     *
     * @param  Order  $order  주문
     * @return string 구매자 이메일
     */
    private function resolveBuyerEmail(Order $order): string
    {
        return (string) ($order->payment?->buyer_email
            ?? $order->shippingAddress?->orderer_email
            ?? '');
    }

    /**
     * 구매자 연락처를 해석합니다.
     *
     * @param  Order  $order  주문
     * @return string 구매자 연락처
     */
    private function resolveBuyerTel(Order $order): string
    {
        return (string) ($order->payment?->buyer_phone
            ?? $order->shippingAddress?->orderer_phone
            ?? '');
    }
}
