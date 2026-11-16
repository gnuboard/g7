<?php

// audit:allow api-doc-coverage reason: 룰 면제 주석만 추가 — 요청/응답 계약 불변 (해당 엔드포인트 문서 부재는 사전 상태)

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Helpers\ResponseHelper;
use App\Helpers\TimezoneHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Services\PluginSettingsService;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Contracts\Currency\PriceFormatter;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentFilters;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Plugins\Sirsoft\PayNhnkcp\Concerns\GuardsOrderScope;
use Plugins\Sirsoft\PayNhnkcp\Concerns\ResolvesEasyPayDisplay;

/**
 * NHN KCP 거래 정보 관리자 컨트롤러
 *
 * KCP는 CLI 바이너리 방식으로 승인이 이루어지므로 웹 API 실시간 조회가 불가합니다.
 * DB에 저장된 거래 정보(payment_meta)를 반환합니다.
 */
class AdminTransactionController extends AdminBaseController
{
    use GuardsOrderScope;
    use ResolvesEasyPayDisplay;

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    /**
     * 의존성을 주입받습니다.
     *
     * @param  PluginSettingsService  $pluginSettingsService  플러그인 설정 서비스
     * @param  PaymentGatewayBridge  $bridge  이커머스 결제 계약 (결제 행·환불·주문 조회)
     * @param  PriceFormatter  $priceFormatter  이커머스 가격 표기 계약
     */
    public function __construct(
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly PaymentGatewayBridge $bridge,
        private readonly PriceFormatter $priceFormatter,
    ) {
        parent::__construct();
    }

    /**
     * 주문번호로 KCP 거래 조회 (GET /api/plugins/sirsoft-pay_nhnkcp/admin/orders/{orderNumber}/transaction-status)
     *
     * 어드민 주문 상세에서 거래 조회 버튼 클릭 시 사용. 이커머스 결제 계약으로 이 PG 결제 행(거래번호 있음)을
     * 찾아, 승인 때 결제 meta 에 저장한 KCP 응답과 취소·환불 이력을 돌려준다(KCP API 는 호출하지 않는다).
     *
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 거래 정보 또는 매핑 없을 시 null
     */
    public function queryByOrder(string $orderNumber): JsonResponse
    {
        if ($denied = $this->orderScopeDenial($orderNumber, 'sirsoft-ecommerce.orders.read')) {
            return $denied;
        }

        // 거래번호가 있는(null·빈 문자열 제외) 이 PG 결제 행
        $payment = $this->bridge->findPaymentByOrderNumber(
            'nhnkcp',
            $orderNumber,
            new PaymentFilters(hasTransactionId: true, nonEmptyTransactionId: true),
        );

        if (! $payment) {
            return ResponseHelper::success('common.success', null);
        }

        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $isTest = (bool) ($settings['is_test_mode'] ?? true);

        $meta = $payment->paymentMeta;
        $rawResponse = $meta['pg_raw_response'] ?? [];
        $display = $this->resolvePaymentDisplay($payment);
        $paymentStatus = $payment->paymentStatus;
        $refund = $this->bridge->latestRefund('nhnkcp', $orderNumber);
        $refundStatus = $refund?->refundStatus;
        // 취소·환불 금액은 기준 통화 저장값이라 표기 통화도 주문 스냅샷을 따라야 한다
        $baseCurrency = $this->resolveBaseCurrency($this->bridge->findOrderByNumber($orderNumber, 'nhnkcp')?->currencySnapshot);
        $cancelledAmount = $payment->cancelledAmount;
        $refundAmount = $refund ? $refund->refundAmount : 0.0;

        return ResponseHelper::success('common.success', [
            'tno' => $payment->transactionId,
            'app_no' => $rawResponse['app_no'] ?? $meta['app_no'] ?? null,
            'use_pay_method' => $meta['use_pay_method'] ?? $rawResponse['use_pay_method'] ?? null,
            'app_time' => $meta['app_time'] ?? $rawResponse['app_time'] ?? null,
            'res_cd' => $meta['res_cd'] ?? $rawResponse['res_cd'] ?? '0000',
            'card_name' => $rawResponse['card_name'] ?? $meta['card_name'] ?? $rawResponse['bank_name'] ?? $meta['bank_name'] ?? null,
            'account' => $meta['account'] ?? null,
            'bank_name' => $rawResponse['bank_name'] ?? $meta['bank_name'] ?? null,
            '_is_test_mode' => $isTest,
            'payment_status' => $paymentStatus->value,
            'payment_status_label' => $paymentStatus->label(),
            'payment_status_variant' => $paymentStatus->variant(),
            'cancelled_amount' => $cancelledAmount,
            'cancelled_amount_formatted' => $this->priceFormatter->format($cancelledAmount, $baseCurrency),
            // 화면 값 — DB UTC 원문을 보는 사람 시간대로
            'cancelled_at' => $this->toViewerDateTime($payment->cancelledAt),
            'cancel_history' => $payment->cancelHistory,
            'refund_number' => $refund?->refundNumber,
            'refund_status' => $refundStatus?->value,
            'refund_status_label' => $refundStatus?->label(),
            'refund_status_variant' => $refundStatus?->variant(),
            'refund_amount' => $refundAmount,
            'refund_amount_formatted' => $this->priceFormatter->format($refundAmount, $baseCurrency),
            'refunded_at' => $this->toViewerDateTime($refund?->refundedAt),
            'refund_pg_transaction_id' => $refund?->pgTransactionId,
            '_base_pay_method_label' => $display['payment_method_label'],
            '_embedded_pg_provider' => $display['embedded_pg_provider'],
            '_embedded_pg_provider_label' => $display['embedded_pg_provider_label'],
            '_pay_method_label' => $display['payment_method_display_label'],
            'payment_method_display_label' => $display['payment_method_display_label'],
        ]);
    }

    /**
     * 저장된 UTC 일시를 보는 사람 시간대 표시 문자열로 바꿉니다.
     *
     * 계약 DTO 의 일시는 DB 원문(UTC)을 앱 시간대로 읽은 값이다. 벽시계 값을 그대로 UTC 로 다시 읽어
     * 종전(DB 원문 문자열을 UTC 로 해석)과 같은 시각을 만든다.
     *
     * @param  DateTimeInterface|null  $value  저장된 일시
     * @return string|null 표시용 일시 (값이 없거나 해석할 수 없으면 null)
     */
    private function toViewerDateTime(?DateTimeInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return TimezoneHelper::toUserDateTimeString(Carbon::parse($value->format('Y-m-d H:i:s'), 'UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 주문 시점 기준 통화 코드를 스냅샷에서 해석합니다.
     *
     * 취소·환불 금액은 기준 통화로 저장되므로, 표기 통화도 그 시점 기준 통화여야 한다.
     * 스냅샷이 없으면 현재 기본 통화로 폴백한다.
     *
     * @param  array<string, mixed>|null  $snapshot  주문 통화 스냅샷
     * @return string|null 기준 통화 코드 (없으면 null → 현재 기본 통화)
     */
    private function resolveBaseCurrency(?array $snapshot): ?string
    {
        $baseCurrency = $snapshot['base_currency'] ?? null;

        return is_string($baseCurrency) ? $baseCurrency : null;
    }
}
