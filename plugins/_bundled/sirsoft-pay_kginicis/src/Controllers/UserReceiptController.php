<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Plugins\Sirsoft\PayKginicis\Concerns\IssuesReceiptCookie;
use Plugins\Sirsoft\PayKginicis\Concerns\ResolvesEasyPaySelection;

class UserReceiptController extends PublicBaseController
{
    use IssuesReceiptCookie;
    use ResolvesEasyPaySelection;

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    // 출처: 그누보드5 쇼핑몰 주문 조회 화면(shop/orderinquiryview.php)이 여는 영수증 주소(mCmReceipt_head.jsp)
    private const RECEIPT_BASE_URL = 'https://iniweb.inicis.com/DefaultWebApp/mall/cr/cm/mCmReceipt_head.jsp';

    /**
     * @param  PluginSettingsService  $pluginSettingsService  플러그인 설정 서비스(테스트 모드 판정)
     * @param  GuestOrderAuthService  $guestOrderAuthService  비회원 조회 토큰 검증 서비스
     */
    public function __construct(
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly GuestOrderAuthService $guestOrderAuthService,
    ) {
        parent::__construct();
    }

    /**
     * show
     *
     * 회원/비회원 공유 영수증 조회. 코어 OrderController::showByOrderNumber 와 동일한
     * 회원 우선 분기 — Auth::check() 일 때는 본인 회원 주문만, 비로그인일 때는
     * X-Guest-Order-Token 으로 비회원 주문 매칭. 실패 사유는 모두 404 로 통일.
     *
     * 응답은 공통 형식(`ResponseHelper`)이며 영수증 정보는 `data` 에 담는다. 대상이 없으면 404(`ResponseHelper::notFound`).
     *
     * @param  Request  $request  회원 또는 비회원 영수증 요청
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 영수증 정보(data) 또는 404
     */
    // audit:allow controller-base-request-injection reason: 본문 입력을 읽지 않음 — user()/비회원 영수증 쿠키만 참조 (검증 대상 필드 없음)
    public function show(Request $request, string $orderNumber): JsonResponse
    {
        // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
        $query = DB::table((new OrderPayment)->getTable().' as p')
            ->join((new Order)->getTable().' as o', 'o.id', '=', 'p.order_id')
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
                        $sub->select('id')->from((new Order)->getTable())->where('order_number', $orderNumber);
                    });
                } else {
                    return ResponseHelper::notFound();
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
            return ResponseHelper::notFound();
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

            return ResponseHelper::success('common.success', [
                'receipt_type' => 'cbt_confirmation',
                'receipt_url' => null,
                'receipt_label' => $receiptLabels['label'],
                'receipt_view_label' => $receiptLabels['view_label'],
                'receipt_title' => $receiptLabels['title'],
                'receipt_notice' => $receiptLabels['notice'],
                'receipt_fields' => $this->buildCbtReceiptFields($payment, $paymentMeta),
                'is_test_mode' => $isTestMode,
                'payment_method_label' => $cbtPaymentMethodLabel,
                'payment_method_display_label' => $cbtPaymentMethodLabel,
                'cbt_pay_method' => $cbtPayMethod,
                'payment_status' => (string) ($payment->payment_status ?? ''),
                'selected_payment_method' => $paymentMeta['selected_payment_method'] ?? null,
                'embedded_pg_provider' => is_string($embeddedPgProvider) ? $embeddedPgProvider : null,
                'embedded_pg_provider_label' => is_string($embeddedPgProviderLabel) ? $embeddedPgProviderLabel : null,
            ]);
        }

        $receiptUrl = self::RECEIPT_BASE_URL.'?'.http_build_query([
            'noTid' => $payment->transaction_id,
            'noMethod' => '1',
        ]);

        return ResponseHelper::success('common.success', [
            'receipt_type' => 'inicis_receipt',
            'receipt_url' => $receiptUrl,
            'receipt_label' => '영수증',
            'receipt_view_label' => '영수증 조회',
            'is_test_mode' => $isTestMode,
            'payment_method_label' => $basePaymentMethodLabel,
            'payment_method_display_label' => $this->paymentMethodDisplayLabel(
                $basePaymentMethodLabel,
                is_string($embeddedPgProviderLabel) ? $embeddedPgProviderLabel : null,
            ),
            'selected_payment_method' => $paymentMeta['selected_payment_method'] ?? null,
            'embedded_pg_provider' => is_string($embeddedPgProvider) ? $embeddedPgProvider : null,
            'embedded_pg_provider_label' => is_string($embeddedPgProviderLabel) ? $embeddedPgProviderLabel : null,
        ]);
    }

    /**
     * CBT(일본 결제) 거래인지 판정합니다 — 결제 메타의 CBT 표지가 있거나 엔화 결제면 CBT 로 본다.
     *
     * @param  object  $payment  결제·주문 조회 행
     * @param  array  $paymentMeta  결제 메타
     * @return bool CBT 거래 여부
     */
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

    /**
     * CBT 결제확인서(또는 편의점 입금정보)에 표시할 항목 목록을 만듭니다 — 값이 빈 항목은 뺀다.
     *
     * @param  object  $payment  결제·주문 조회 행
     * @param  array  $paymentMeta  결제 메타
     * @return array<int, array{label: string, value: string}> 표시 항목
     */
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

    /**
     * CBT 결제수단 코드를 정규화합니다.
     *
     * @param  array  $paymentMeta  결제 메타
     * @param  array  $raw  PG 원 응답(비어 있으면 결제 메타에서 읽는다)
     * @return string 결제수단 코드
     */
    private function resolveCbtPayMethod(array $paymentMeta, array $raw = []): string
    {
        $payMethod = strtoupper((string) ($paymentMeta['pay_method'] ?? $raw['paymethod'] ?? 'CBT'));

        return match ($payMethod) {
            'PAYPAY', 'PAYPAYMENT', 'PAYPAYPAYMENT' => 'PAYPAY',
            'LINEPAY', 'LINEPAYMENT' => 'LINEPAY',
            default => $payMethod,
        };
    }

    /**
     * CBT 결제수단 코드의 표시 이름을 반환합니다.
     *
     * @param  string  $payMethod  결제수단 코드
     * @return string 표시 이름
     */
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

    /**
     * CBT 확인서의 라벨·제목·안내 문구를 결제수단과 결제 상태로 고릅니다(편의점 입금 대기면 입금정보).
     *
     * @param  string  $payMethod  결제수단 코드
     * @param  string  $paymentStatus  결제 상태
     * @return array{label: string, view_label: string, title: string, notice: string} 문구
     */
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

    /**
     * 결제 상태의 표시 이름을 반환합니다.
     *
     * @param  string  $paymentStatus  결제 상태
     * @return string 표시 이름
     */
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

    /**
     * CBT 확인서에 표시할 결제 금액을 PG 원 응답·결제 메타·결제 행 순으로 찾습니다.
     *
     * @param  object  $payment  결제·주문 조회 행
     * @param  array  $paymentMeta  결제 메타
     * @param  array  $raw  PG 원 응답
     * @return mixed 금액(없으면 null)
     */
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

    /**
     * 금액을 천 단위 구분과 통화 코드로 표기합니다.
     *
     * @param  mixed  $amount  금액
     * @param  string  $currency  통화 코드
     * @return string 표기(금액이 없으면 빈 문자열)
     */
    private function formatCbtAmount(mixed $amount, string $currency): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        return number_format((float) $amount).' '.$currency;
    }

    /**
     * PG 의 날짜(YYYYMMDD)·시각(HHMMSS) 값을 표시용 일시로 바꿉니다.
     *
     * @param  mixed  $date  날짜
     * @param  mixed  $time  시각
     * @return string 일시(값이 없으면 빈 문자열)
     */
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

    /**
     * 붙여 쓴 일시(YYYYMMDDHHMMSS)를 표시용 일시로 바꿉니다.
     *
     * @param  string  $value  붙여 쓴 일시
     * @return string 일시(형식이 맞지 않으면 빈 문자열)
     */
    private function formatCbtCompactDateTime(string $value): string
    {
        $value = preg_replace('/\D+/', '', $value);

        if (strlen($value) !== 14) {
            return '';
        }

        return $this->formatCbtDateTime(substr($value, 0, 8), substr($value, 8, 6));
    }

    /**
     * 할부 개월 수를 표기합니다(0 이하는 일시불).
     *
     * @param  mixed  $value  할부 개월 수
     * @return string 표기(값이 없으면 빈 문자열)
     */
    private function formatInstallmentMonths(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $months = (int) $value;

        return $months <= 0 ? '일시불' : $months.'개월';
    }

    /**
     * 결제 메타(JSON 문자열 또는 배열)를 배열로 풉니다.
     *
     * @param  mixed  $paymentMeta  결제 메타
     * @return array 결제 메타(풀 수 없으면 빈 배열)
     */
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

    /**
     * 결제수단 코드의 표시 이름을 반환합니다.
     *
     * @param  string  $paymentMethod  결제수단 코드
     * @return string 표시 이름
     */
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

    /**
     * 간편결제(내장 PG) 제공자 코드의 표시 이름을 반환합니다.
     *
     * @param  ?string  $provider  제공자 코드
     * @return string|null 표시 이름(없으면 null)
     */
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

    /**
     * 결제수단 표시 이름에 간편결제 이름을 덧붙입니다(예: 네이버페이 (신용카드)).
     *
     * @param  string  $baseLabel  결제수단 표시 이름
     * @param  ?string  $embeddedLabel  간편결제 표시 이름
     * @return string 표시 이름
     */
    private function paymentMethodDisplayLabel(string $baseLabel, ?string $embeddedLabel): string
    {
        if ($embeddedLabel === null || $embeddedLabel === '') {
            return $baseLabel;
        }

        if ($baseLabel === '-' || $baseLabel === '') {
            return $embeddedLabel;
        }

        return $embeddedLabel.' ('.$baseLabel.')';
    }
}
