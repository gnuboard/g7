<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Plugins\Sirsoft\PayKginicis\Concerns\IssuesReceiptCookie;
use Plugins\Sirsoft\PayKginicis\Concerns\ResolvesEasyPaySelection;

class UserReceiptController
{
    use IssuesReceiptCookie;
    use ResolvesEasyPaySelection;

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    // 출처: C:\xampp824\www\gnu5\shop\orderinquiryview.php (mCmReceipt_head.jsp)
    private const RECEIPT_BASE_URL = 'https://iniweb.inicis.com/DefaultWebApp/mall/cr/cm/mCmReceipt_head.jsp';

    public function __construct(
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly GuestOrderAuthService $guestOrderAuthService,
    ) {}

    /**
     * show
     *
     * 회원/비회원 공유 영수증 조회. 코어 OrderController::showByOrderNumber 와 동일한
     * 회원 우선 분기 — Auth::check() 일 때는 본인 회원 주문만, 비로그인일 때는
     * X-Guest-Order-Token 으로 비회원 주문 매칭. 실패 사유는 모두 404 로 통일.
     *
     * @param  Request  $request
     * @param  string  $orderNumber
     * @return JsonResponse
     */
    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $query = DB::table('ecommerce_order_payments as p')
            ->join('ecommerce_orders as o', 'o.id', '=', 'p.order_id')
            ->where('o.order_number', $orderNumber)
            ->where('p.pg_provider', 'kginicis');

        if (Auth::check()) {
            $query->where('o.user_id', Auth::id());
        } else {
            // 1차: X-Guest-Order-Token 헤더 (코어 globalHeaders 또는 명시 헤더)
            $token = $request->header('X-Guest-Order-Token');
            $order = $this->guestOrderAuthService->verifyToken($token, $orderNumber);

            // 2차 폴백: 결제 완료 직후 PG callback 이 발급한 단기 영수증 쿠키.
            // sessionStorage 토큰이 없거나 브라우저 캐시가 stale 한 환경에서도 5분간 동작.
            if (! $order) {
                $cookieValue = $request->cookie(self::RECEIPT_COOKIE_NAME);
                if ($this->verifyReceiptCookie($cookieValue, $orderNumber)) {
                    $query->where('o.id', function ($sub) use ($orderNumber) {
                        $sub->select('id')->from('ecommerce_orders')->where('order_number', $orderNumber);
                    });
                } else {
                    return response()->json(['error' => 'Not found'], 404);
                }
            } else {
                $query->whereNull('o.user_id')->where('o.id', $order->id);
            }
        }

        $payment = $query
            ->select([
                'o.order_number',
                'o.currency as order_currency',
                'o.total_amount',
                'p.transaction_id',
                'p.payment_status',
                'p.payment_method',
                'p.embedded_pg_provider',
                'p.paid_amount_local',
                'p.currency',
                'p.card_approval_number',
                'p.card_installment_months',
                'p.vbank_number',
                'p.vbank_due_at',
                'p.payment_meta',
            ])
            ->first();

        if (! $payment || ! $payment->transaction_id) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $isTestMode = (bool) ($settings['is_test_mode'] ?? true);
        $paymentMeta = $this->decodePaymentMeta($payment->payment_meta ?? null);
        $embeddedPgProvider = $payment->embedded_pg_provider
            ?: ($paymentMeta['embedded_pg_provider'] ?? null);
        $embeddedPgProviderLabel = $paymentMeta['embedded_pg_provider_label']
            ?? $this->embeddedPgProviderLabel(is_string($embeddedPgProvider) ? $embeddedPgProvider : null);
        $basePaymentMethodLabel = $this->paymentMethodLabel((string) ($payment->payment_method ?? ''));

        if ($this->isCbtPayment($payment, $paymentMeta)) {
            $cbtPayMethod = $this->resolveCbtPayMethod($paymentMeta);
            $cbtPaymentMethodLabel = $this->cbtPayMethodLabel($cbtPayMethod);
            $receiptLabels = $this->cbtReceiptLabels($cbtPayMethod, (string) ($payment->payment_status ?? ''));

            return response()->json([
                'receipt_type'                  => 'cbt_confirmation',
                'receipt_url'                   => null,
                'receipt_label'                 => $receiptLabels['label'],
                'receipt_view_label'            => $receiptLabels['view_label'],
                'receipt_title'                 => $receiptLabels['title'],
                'receipt_notice'                => $receiptLabels['notice'],
                'receipt_fields'                => $this->buildCbtReceiptFields($payment, $paymentMeta),
                'is_test_mode'                  => $isTestMode,
                'payment_method_label'          => $cbtPaymentMethodLabel,
                'payment_method_display_label'  => $cbtPaymentMethodLabel,
                'cbt_pay_method'                => $cbtPayMethod,
                'payment_status'                => (string) ($payment->payment_status ?? ''),
                'selected_payment_method'       => $paymentMeta['selected_payment_method'] ?? null,
                'embedded_pg_provider'          => is_string($embeddedPgProvider) ? $embeddedPgProvider : null,
                'embedded_pg_provider_label'    => is_string($embeddedPgProviderLabel) ? $embeddedPgProviderLabel : null,
            ]);
        }

        $receiptUrl = self::RECEIPT_BASE_URL . '?' . http_build_query([
            'noTid'    => $payment->transaction_id,
            'noMethod' => '1',
        ]);

        return response()->json([
            'receipt_type'                  => 'inicis_receipt',
            'receipt_url'                   => $receiptUrl,
            'receipt_label'                 => '영수증',
            'receipt_view_label'            => '영수증 조회',
            'is_test_mode'                  => $isTestMode,
            'payment_method_label'          => $basePaymentMethodLabel,
            'payment_method_display_label'  => $this->paymentMethodDisplayLabel(
                $basePaymentMethodLabel,
                is_string($embeddedPgProviderLabel) ? $embeddedPgProviderLabel : null,
            ),
            'selected_payment_method'       => $paymentMeta['selected_payment_method'] ?? null,
            'embedded_pg_provider'          => is_string($embeddedPgProvider) ? $embeddedPgProvider : null,
            'embedded_pg_provider_label'    => is_string($embeddedPgProviderLabel) ? $embeddedPgProviderLabel : null,
        ]);
    }

    private function isCbtPayment(object $payment, array $paymentMeta): bool
    {
        if (($paymentMeta['is_cbt'] ?? false) === true) {
            return true;
        }

        if (($paymentMeta['cbt_type'] ?? null) !== null || ($paymentMeta['cbt_mid'] ?? null) !== null) {
            return true;
        }

        return strtoupper((string) ($payment->currency ?? $payment->order_currency ?? '')) === 'JPY'
            && str_starts_with((string) $payment->transaction_id, 'INIJPG');
    }

    private function buildCbtReceiptFields(object $payment, array $paymentMeta): array
    {
        $approveResponse = is_array($paymentMeta['pg_approve_response'] ?? null)
            ? $paymentMeta['pg_approve_response']
            : [];
        $notifyResponse = is_array($paymentMeta['pg_cvs_notify_response'] ?? null)
            ? $paymentMeta['pg_cvs_notify_response']
            : [];
        $raw = array_merge($approveResponse, $notifyResponse);
        $payMethod = $this->resolveCbtPayMethod($paymentMeta, $raw);
        $currency = strtoupper((string) ($payment->currency ?? $paymentMeta['currency'] ?? 'JPY'));
        $paymentStatus = (string) ($payment->payment_status ?? '');
        $isWaitingDeposit = $payMethod === 'CVS' && $paymentStatus === 'waiting_deposit';
        $amountLabel = $isWaitingDeposit ? '입금예정금액' : '결제금액';

        $fields = [
            ['label' => '주문번호', 'value' => (string) ($payment->order_number ?? '')],
            ['label' => '결제수단', 'value' => $this->cbtPayMethodLabel($payMethod)],
            ['label' => '거래번호', 'value' => (string) $payment->transaction_id],
            ['label' => $amountLabel, 'value' => $this->formatCbtAmount($this->resolveCbtAmount($payment, $paymentMeta, $raw), $currency)],
            ['label' => '입금 상태', 'value' => $this->cbtPaymentStatusLabel($paymentStatus)],
            ['label' => '승인일시', 'value' => $this->formatCbtDateTime($raw['applDate'] ?? $raw['applDt'] ?? null, $raw['applTime'] ?? $raw['applTm'] ?? null)],
        ];

        if ($payMethod === 'CARD') {
            $fields[] = ['label' => '카드 승인번호', 'value' => (string) ($raw['approve'] ?? $payment->card_approval_number ?? '')];
            $fields[] = ['label' => '할부개월', 'value' => $this->formatInstallmentMonths($raw['installMonth'] ?? $payment->card_installment_months ?? null)];
        }

        if ($payMethod === 'PAYPAY') {
            $fields[] = ['label' => '승인번호', 'value' => (string) ($raw['approve'] ?? '')];
        }

        if ($payMethod === 'CVS') {
            $fields[] = ['label' => '편의점 코드', 'value' => (string) ($raw['convenience'] ?? $paymentMeta['cvs_convenience'] ?? '')];
            $fields[] = ['label' => '편의점 확인번호', 'value' => (string) ($raw['confNo'] ?? $paymentMeta['cvs_conf_no'] ?? $payment->vbank_number ?? '')];
            $fields[] = ['label' => '편의점 접수번호', 'value' => (string) ($raw['receiptNo'] ?? $paymentMeta['cvs_receipt_no'] ?? '')];
            $fields[] = ['label' => '입금 마감일시', 'value' => $this->formatCbtCompactDateTime((string) ($raw['paymentTerm'] ?? $paymentMeta['cvs_payment_term'] ?? ''))];
        }

        return array_values(array_filter(
            $fields,
            fn (array $field) => ($field['value'] ?? '') !== ''
        ));
    }

    private function resolveCbtPayMethod(array $paymentMeta, array $raw = []): string
    {
        $payMethod = strtoupper((string) ($paymentMeta['pay_method'] ?? $raw['paymethod'] ?? 'CBT'));

        return match ($payMethod) {
            'PAYPAY', 'PAYPAYMENT', 'PAYPAYPAYMENT' => 'PAYPAY',
            'LINEPAY', 'LINEPAYMENT' => 'LINEPAY',
            default => $payMethod,
        };
    }

    private function cbtPayMethodLabel(string $payMethod): string
    {
        return match ($payMethod) {
            'CARD' => '신용카드 (일본 CBT)',
            'CVS' => '일본 편의점결제',
            'PAYPAY' => 'PayPay',
            'LINEPAY' => 'LINE Pay',
            default => $payMethod !== '' ? $payMethod : '일본 CBT 결제',
        };
    }

    private function cbtReceiptLabels(string $payMethod, string $paymentStatus): array
    {
        if ($payMethod === 'CVS' && $paymentStatus === 'waiting_deposit') {
            return [
                'label' => '입금정보',
                'view_label' => '편의점 입금정보 보기',
                'title' => 'KG 이니시스 CBT 편의점 입금정보',
                'notice' => '일본 편의점결제는 입금 마감일시 전까지 편의점에서 확인번호로 결제를 완료해야 합니다.',
            ];
        }

        return [
            'label' => '결제확인',
            'view_label' => '결제확인서 보기',
            'title' => 'KG 이니시스 CBT 결제확인서',
            'notice' => '일본 CBT 결제는 한국 KG 이니시스 매출전표 조회와 별도로 결제 승인 정보를 표시합니다.',
        ];
    }

    private function cbtPaymentStatusLabel(string $paymentStatus): string
    {
        return match ($paymentStatus) {
            'waiting_deposit' => '입금대기',
            'paid' => '입금완료',
            'cancelled' => '취소',
            'failed' => '실패',
            default => $paymentStatus,
        };
    }

    private function resolveCbtAmount(object $payment, array $paymentMeta, array $raw): mixed
    {
        foreach ([
            $raw['amount'] ?? null,
            $raw['price'] ?? null,
            $paymentMeta['cvs_amount'] ?? null,
            $payment->paid_amount_local ?? null,
            $payment->total_amount ?? null,
        ] as $amount) {
            if ($amount !== null && $amount !== '' && (float) $amount > 0) {
                return $amount;
            }
        }

        return $payment->paid_amount_local ?? null;
    }

    private function formatCbtAmount(mixed $amount, string $currency): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        return number_format((float) $amount) . ' ' . $currency;
    }

    private function formatCbtDateTime(mixed $date, mixed $time): string
    {
        $date = preg_replace('/\D+/', '', (string) $date);
        $time = preg_replace('/\D+/', '', (string) $time);

        if (strlen($date) !== 8 || strlen($time) !== 6) {
            return '';
        }

        return sprintf(
            '%s-%s-%s %s:%s:%s',
            substr($date, 0, 4),
            substr($date, 4, 2),
            substr($date, 6, 2),
            substr($time, 0, 2),
            substr($time, 2, 2),
            substr($time, 4, 2),
        );
    }

    private function formatCbtCompactDateTime(string $value): string
    {
        $value = preg_replace('/\D+/', '', $value);

        if (strlen($value) !== 14) {
            return '';
        }

        return $this->formatCbtDateTime(substr($value, 0, 8), substr($value, 8, 6));
    }

    private function formatInstallmentMonths(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $months = (int) $value;

        return $months <= 0 ? '일시불' : $months . '개월';
    }

    private function decodePaymentMeta(mixed $paymentMeta): array
    {
        if (is_array($paymentMeta)) {
            return $paymentMeta;
        }

        if (! is_string($paymentMeta) || $paymentMeta === '') {
            return [];
        }

        $decoded = json_decode($paymentMeta, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function paymentMethodLabel(string $paymentMethod): string
    {
        return match (strtolower($paymentMethod)) {
            'card' => '신용카드',
            'vbank' => '가상계좌',
            'bank' => '계좌이체',
            'dbank' => '무통장입금',
            'phone', 'mobile' => '휴대폰결제',
            'point' => '포인트결제',
            'deposit' => '예치금결제',
            'free' => '무료',
            default => $paymentMethod !== '' ? $paymentMethod : '-',
        };
    }

    private function embeddedPgProviderLabel(?string $provider): ?string
    {
        if ($provider === null || $provider === '') {
            return null;
        }

        foreach ($this->kginicisEasyPayMethodMap() as $context) {
            if (($context['provider'] ?? null) === $provider) {
                return $context['label'];
            }
        }

        return match (strtolower($provider)) {
            'payco' => '페이코',
            'tosspay', 'toss' => '토스페이',
            'ssgpay' => 'SSG페이',
            default => $provider,
        };
    }

    private function paymentMethodDisplayLabel(string $baseLabel, ?string $embeddedLabel): string
    {
        if ($embeddedLabel === null || $embeddedLabel === '') {
            return $baseLabel;
        }

        if ($baseLabel === '-' || $baseLabel === '') {
            return $embeddedLabel;
        }

        return $embeddedLabel . ' (' . $baseLabel . ')';
    }
}
