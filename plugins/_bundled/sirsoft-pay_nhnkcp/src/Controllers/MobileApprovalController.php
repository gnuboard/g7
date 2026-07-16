<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayNhnkcp\Concerns\RecordsPaymentWindowClosure;
use Plugins\Sirsoft\PayNhnkcp\Exceptions\NhnKcpApiException;
use Plugins\Sirsoft\PayNhnkcp\Services\KcpSoapService;

/**
 * KCP SmartPhone Pay 승인키 컨트롤러
 *
 * PC Standard Pay(iframe)와 달리 모바일은 서버에서 SOAP으로 approval_key 를
 * 먼저 획득하고, 브라우저가 pay_url 로 form POST(페이지 전환)한다.
 */
class MobileApprovalController
{
    use RecordsPaymentWindowClosure;

    /** 결제수단 → KCP 모바일 pay_method 코드 */
    private const MOBILE_PAY_METHOD_MAP = [
        'card' => 'CARD',
        'bank_transfer' => 'BANK',
        'virtual_account' => 'VCNT',
        'mobile' => 'MOBX',
        'bank' => 'BANK',
        'vbank' => 'VCNT',
        'phone' => 'MOBX',
    ];

    /** KCP 모바일 pay_method → ActionResult (form hidden field) */
    private const ACTION_RESULT_MAP = [
        'CARD' => 'card',
        'BANK' => 'acnt',
        'MOBX' => 'mobx',
        'VCNT' => 'vcnt',
    ];

    /** 간편결제 direct 파라미터 기본값 (매 결제 시 초기화) */
    private const EASY_PAY_DIRECT_DEFAULTS = [
        'payco_direct'    => '',
        'naverpay_direct' => 'A',
        'kakaopay_direct' => 'A',
        'applepay_direct' => 'A',
    ];

    /** 간편결제별 direct 파라미터 override (기본값 위에 덮어씀) */
    private const EASY_PAY_DIRECT_FIELDS = [
        'nhnkcp_payco'          => ['payco_direct' => 'Y'],
        'nhnkcp_naverpay'       => ['naverpay_direct' => 'Y'],
        'nhnkcp_naverpay_point' => ['naverpay_direct' => 'Y', 'naverpay_point_direct' => 'Y'],
        'nhnkcp_kakaopay'       => ['kakaopay_direct' => 'Y'],
        'nhnkcp_applepay'       => ['applepay_direct' => 'Y'],
    ];

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    public function __construct(
        private readonly KcpSoapService $soapService,
        private readonly OrderProcessingService $orderService,
    ) {}

    /**
     * KCP 모바일 결제 승인키 획득
     *
     * POST /api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key
     *
     * @return JsonResponse{ success: true, data: { pay_url, fields } }
     */
    /**
     * 모바일 결제 승인키 발급 (SOAP)
     *
     * KCP 모바일 결제창 진입 전 SOAP 으로 승인키 + payUrl 발급.
     *
     * @param  Request  $request  주문/결제 메타
     * @return JsonResponse approval_key + pay_url
     */
    public function getApprovalKey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_number' => ['required', 'string', 'max:40'],
            'amount' => ['required', 'integer', 'min:100'],
            'good_name' => ['required', 'string', 'max:100'],
            'pay_method' => ['required', 'string'],
            'buyr_name' => ['nullable', 'string', 'max:50'],
            'buyr_mail' => ['nullable', 'string', 'email', 'max:100'],
            'buyr_tel1' => ['nullable', 'string', 'max:20'],
            'ret_url' => ['required', 'string', 'url'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $payMethodKey = strtolower($validated['pay_method']);
        $isEasyPay = str_starts_with($payMethodKey, 'nhnkcp_');
        $mobilePayMethod = $isEasyPay
            ? 'CARD'
            : (self::MOBILE_PAY_METHOD_MAP[$payMethodKey] ?? 'CARD');

        $settings = plugin_settings(self::PLUGIN_IDENTIFIER) ?? [];
        $useEscrow = (bool) ($settings['use_escrow'] ?? false) && ! $isEasyPay;

        try {
            $order = $this->orderService->findByOrderNumber($validated['order_number']);
            if (! $order) {
                return response()->json([
                    'success' => false,
                    'error' => 'Order not found.',
                ], 404);
            }

            if ((int) $order->user_id !== (int) ($request->user()?->id ?? 0)) {
                return response()->json([
                    'success' => false,
                    'error' => 'You cannot request a NHN KCP approval key for this order.',
                ], 403);
            }

            $expectedAmount = $this->resolveExpectedPaymentPriceOrNull($order, 'mobile_approval_key', [
                'order_number' => $validated['order_number'],
                'requested_amount' => $validated['amount'],
                'requested_currency' => $validated['currency'] ?? null,
                'ip' => $request->ip(),
            ]);
            if ($expectedAmount === null) {
                return response()->json([
                    'success' => false,
                    'error' => 'Payment currency is not chargeable.',
                ], 422);
            }

            if ((int) $validated['amount'] !== $expectedAmount) {
                return response()->json([
                    'success' => false,
                    'error' => 'Payment amount does not match the order amount.',
                ], 422);
            }

            if (! $this->isKrwPayment($order, $validated['currency'] ?? null)) {
                return response()->json([
                    'success' => false,
                    'error' => 'NHN KCP supports KRW payments only.',
                ], 422);
            }

            if (! $order->order_status->isBeforePayment()) {
                $restored = $this->restoreRetryableKcpOrder($order, (int) $validated['amount']);
                if (! $restored) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Order is not retryable for NHN KCP payment.',
                    ], 409);
                }
            }

            $result = $this->soapService->getApprovalKey(
                orderNumber: $validated['order_number'],
                goodName: $validated['good_name'],
                amount: (int) $validated['amount'],
                payMethod: $mobilePayMethod,
                retUrl: $validated['ret_url'],
                payMethodKey: $payMethodKey,
                escrow: $useEscrow,
            );

            $siteCd = $useEscrow
                ? $this->soapService->getEscrowSiteCd()
                : $this->soapService->getSiteCd($payMethodKey);

            $fields = [
                'req_tx' => 'pay',
                'site_cd' => $siteCd,
                'ordr_idxx' => $validated['order_number'],
                'pay_method' => $mobilePayMethod,
                'good_mny' => (string) $validated['amount'],
                'good_name' => $validated['good_name'],
                'buyr_name' => $validated['buyr_name'] ?? '',
                'buyr_mail' => $validated['buyr_mail'] ?? '',
                'buyr_tel1' => $validated['buyr_tel1'] ?? '',
                'Ret_URL' => $validated['ret_url'],
                'ActionResult' => self::ACTION_RESULT_MAP[$mobilePayMethod] ?? 'card',
                'escw_used' => $useEscrow ? 'Y' : 'N',
                'quotaopt' => '12',
                'currency' => '410',
                'approval_key' => $result['approval_key'],
                ...$this->buildTaxFields($order, (int) $validated['amount']),
            ];

            // 가상계좌 전용 파라미터
            if ($mobilePayMethod === 'VCNT') {
                $settings = plugin_settings(self::PLUGIN_IDENTIFIER) ?? [];
                $fields['vcnt_expire_term'] = (string) ((int) ($settings['vbank_expire_days'] ?? 3));
                $fields['disp_tax_yn'] = 'N';
            }

            if ($isEasyPay) {
                // GNU5 규격: 모든 direct 파라미터를 기본값으로 초기화 후 선택된 수단만 Y로 덮어씀
                $fields = [...$fields, ...self::EASY_PAY_DIRECT_DEFAULTS];
                if (isset(self::EASY_PAY_DIRECT_FIELDS[$payMethodKey])) {
                    $fields = [...$fields, ...self::EASY_PAY_DIRECT_FIELDS[$payMethodKey]];
                }
                $fields = [...$fields, ...$this->buildEasyPayReturnFields($payMethodKey)];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'pay_url' => $result['pay_url'],
                    'fields' => $fields,
                ],
            ]);

        } catch (NhnKcpApiException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * 주문의 과세/비과세 분할 필드 반환 (복합과세 사이트 코드 계약 시 필요)
     *
     * 비과세 금액이 있는 경우에만 comm_tax_mny / comm_vat_mny / comm_free_mny 추가.
     * 전액 과세라면 good_mny 만으로 KCP가 내부적으로 세금 계산.
     *
     * @return array<string, string>
     */
    private function buildTaxFields(Order $order, int $paymentAmount): array
    {
        $taxFreeAmt = (int) round((float) ($order->total_tax_free_amount ?? 0));
        if ($taxFreeAmt <= 0) {
            return [];
        }

        $paymentAmount = max(0, $paymentAmount);
        $taxFreeAmt = min($taxFreeAmt, $paymentAmount);
        $taxablePaymentAmt = max(0, $paymentAmount - $taxFreeAmt);
        $taxTotalAmt = (int) round((float) ($order->total_tax_amount ?? 0));
        $vatAmt = (int) round((float) ($order->total_vat_amount ?? 0));
        if ($taxablePaymentAmt > 0 && $taxTotalAmt > 0 && $vatAmt > 0) {
            $vatAmt = min($taxablePaymentAmt, (int) round($taxablePaymentAmt * ($vatAmt / $taxTotalAmt)));
        } else {
            $vatAmt = 0;
        }
        $supplyAmt = $taxablePaymentAmt - $vatAmt; // 공급가액 (VAT 제외)

        return [
            'tax_flag'      => 'TG03',
            'comm_tax_mny'  => (string) $supplyAmt,
            'comm_vat_mny'  => (string) $vatAmt,
            'comm_free_mny' => (string) $taxFreeAmt,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildEasyPayReturnFields(string $payMethodKey): array
    {
        if (! isset(self::EASY_PAY_DIRECT_FIELDS[$payMethodKey])) {
            return [];
        }

        return [
            'param_opt_1' => $payMethodKey,
            'nhnkcp_easy_pay_method' => $payMethodKey,
        ];
    }

    private function isKrwPayment(Order $order, ?string $requestedCurrency): bool
    {
        return $this->normalizeCurrency($requestedCurrency) === 'KRW'
            && $this->normalizeCurrency((string) ($order->currency ?? 'KRW')) === 'KRW';
    }

    private function normalizeCurrency(?string $currency): string
    {
        $normalized = strtoupper(trim((string) $currency));

        return $normalized !== '' ? $normalized : 'KRW';
    }
}
