<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Plugins\Sirsoft\PayKginicis\Concerns\IssuesReceiptCookie;

class UserReceiptController extends PublicBaseController
{
    use IssuesReceiptCookie;

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    // 출처: 그누보드5 쇼핑몰 주문 조회 화면(shop/orderinquiryview.php)이 여는 영수증 주소(mCmReceipt_head.jsp)
    private const RECEIPT_BASE_URL = 'https://iniweb.inicis.com/DefaultWebApp/mall/cr/cm/mCmReceipt_head.jsp';

    // 표시 이름 번역이 있는 간편결제 제공자 코드(messages.receipt.easy_pay_providers)
    private const EASY_PAY_PROVIDERS = ['samsungpay', 'naverpay', 'lpay', 'kakaopay', 'payco', 'tosspay', 'ssgpay'];

    // KG 이니시스 가상계좌 은행 이름 → 은행코드 (메뉴얼 manual.inicis.com 기준). 은행코드를 저장하지 못한
    // 예전 주문은 은행 이름으로 코드를 찾는다. 주문 상세 입금 안내가 관리자에게만 보여 주는 값이다.
    private const VBANK_BANK_CODES = [
        '기업은행' => '03', '국민은행' => '04', '외환은행' => '05', '수협중앙회' => '07',
        '농협중앙회' => '11', '우리은행' => '20', 'SC 제일은행' => '23', '대구은행' => '31',
        '부산은행' => '32', '광주은행' => '34', '전북은행' => '37', '경남은행' => '39',
        '한국씨티은행' => '53', '우체국' => '71', '하나은행' => '81', '신한은행' => '88',
        '케이뱅크' => '89', '카카오뱅크' => '90', '토스뱅크' => '92',
        '동양종합금융증권' => 'D1', '현대증권' => 'D2', '미래에셋증권' => 'D3',
        '한국투자증권' => 'D4', '우리투자증권' => 'D5', '하이투자증권' => 'D6',
        'HMC 투자증권' => 'D7', 'SK 증권' => 'D8', '대신증권' => 'D9',
        '하나대투증권' => 'DA', '굿모닝신한증권' => 'DB', '동부증권' => 'DC',
        '유진투자증권' => 'DD', '메리츠증권' => 'DE', '신영증권' => 'DF',
    ];

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
                'p.vbank_code',
                'p.vbank_name',
                'p.vbank_number',
                'p.vbank_due_at',
                'p.payment_meta',
                'p.is_escrow',
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
        // 간편결제 이름은 제공자 코드로 번역한다 — 결제 메타에 저장된 이름은 결제 시점의 한국어라 다른 언어 화면에 맞지 않는다.
        $embeddedPgProviderLabel = $this->embeddedPgProviderLabel(
            is_string($embeddedPgProvider) ? $embeddedPgProvider : null,
            $paymentMeta['embedded_pg_provider_label'] ?? null,
        );
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
            'receipt_label' => __('sirsoft-pay_kginicis::messages.receipt.label'),
            'receipt_view_label' => __('sirsoft-pay_kginicis::messages.receipt.view_label'),
            'is_test_mode' => $isTestMode,
            'payment_method_label' => $basePaymentMethodLabel,
            'payment_method_display_label' => $this->paymentMethodDisplayLabel(
                $basePaymentMethodLabel,
                is_string($embeddedPgProviderLabel) ? $embeddedPgProviderLabel : null,
            ),
            'selected_payment_method' => $paymentMeta['selected_payment_method'] ?? null,
            'embedded_pg_provider' => is_string($embeddedPgProvider) ? $embeddedPgProvider : null,
            'embedded_pg_provider_label' => is_string($embeddedPgProviderLabel) ? $embeddedPgProviderLabel : null,
            // 관리자 확인용 값 — 화면만 숨기지 않고 응답에서도 주문 조회 권한자에게만 싣는다
            'vbank_bank_code' => PermissionHelper::check('sirsoft-ecommerce.orders.read', Auth::user()) ? $this->vbankBankCode($payment) : null,
            'escrow_confirm_url' => $this->escrowConfirmUrl($orderNumber, (bool) ($payment->is_escrow ?? false)),
        ]);
    }

    /**
     * 가상계좌 은행코드를 반환합니다 — 저장된 코드가 없으면 은행 이름으로 찾는다.
     *
     * @param  object  $payment  결제·주문 조회 행
     * @return string|null 은행코드(가상계좌가 아니거나 찾지 못하면 null)
     */
    private function vbankBankCode(object $payment): ?string
    {
        $code = trim((string) ($payment->vbank_code ?? ''));
        if ($code !== '') {
            return $code;
        }

        $name = trim((string) ($payment->vbank_name ?? ''));

        return $name !== '' ? (self::VBANK_BANK_CODES[$name] ?? null) : null;
    }

    /**
     * 로그인한 본인의 에스크로 결제에 구매결정 창 한시 서명 주소를 만듭니다 (S4-D19).
     *
     * 사이트 화면 로그인은 API 토큰이라 [구매결정] 이 여는 새 창에는 로그인 정보가 실리지 않는다 — 토큰으로 본인을
     * 확인한 이 응답이 회원 번호를 담은 10분짜리 서명 주소를 주고, 구매결정 창은 그 서명을 본인 확인으로 받는다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  bool  $isEscrow  에스크로 결제 여부
     * @return string|null 상대 서명 주소(비회원·에스크로 아님이면 null)
     */
    private function escrowConfirmUrl(string $orderNumber, bool $isEscrow): ?string
    {
        if (! $isEscrow || ! Auth::check()) {
            return null;
        }

        return URL::temporarySignedRoute(
            'web.plugins.sirsoft-pay_kginicis.payment.escrow-confirm.show',
            now()->addMinutes(10),
            ['orderNumber' => $orderNumber, 'uid' => Auth::id()],
            absolute: false,
        );
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
     * @return array<int, array{key: string, label: string, value: string}> 표시 항목(key 는 화면 스크립트가 찾는 고정 이름, label 은 요청 언어의 이름)
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
        $amountLabel = __('sirsoft-pay_kginicis::messages.receipt.fields.'.($isWaitingDeposit ? 'due_amount' : 'amount'));

        $fields = [
            ['key' => 'order_number', 'label' => __('sirsoft-pay_kginicis::messages.receipt.fields.order_number'), 'value' => (string) ($payment->order_number ?? '')],
            ['key' => 'pay_method', 'label' => __('sirsoft-pay_kginicis::messages.receipt.fields.pay_method'), 'value' => $this->cbtPayMethodLabel($payMethod)],
            ['key' => 'transaction_id', 'label' => __('sirsoft-pay_kginicis::messages.receipt.fields.transaction_id'), 'value' => (string) $payment->transaction_id],
            ['key' => 'amount', 'label' => $amountLabel, 'value' => $this->formatCbtAmount($this->resolveCbtAmount($payment, $paymentMeta, $raw), $currency)],
            ['key' => 'deposit_status', 'label' => __('sirsoft-pay_kginicis::messages.receipt.fields.deposit_status'), 'value' => $this->cbtPaymentStatusLabel($paymentStatus)],
            ['key' => 'approved_at', 'label' => __('sirsoft-pay_kginicis::messages.receipt.fields.approved_at'), 'value' => $this->formatCbtDateTime($raw['applDate'] ?? $raw['applDt'] ?? null, $raw['applTime'] ?? $raw['applTm'] ?? null)],
        ];

        if ($payMethod === 'CARD') {
            $fields[] = ['key' => 'card_approval_number', 'label' => __('sirsoft-pay_kginicis::messages.receipt.fields.card_approval_number'), 'value' => (string) ($raw['approve'] ?? $payment->card_approval_number ?? '')];
            $fields[] = ['key' => 'installment_months', 'label' => __('sirsoft-pay_kginicis::messages.receipt.fields.installment_months'), 'value' => $this->formatInstallmentMonths($raw['installMonth'] ?? $payment->card_installment_months ?? null)];
        }

        if ($payMethod === 'PAYPAY') {
            $fields[] = ['key' => 'approval_number', 'label' => __('sirsoft-pay_kginicis::messages.receipt.fields.approval_number'), 'value' => (string) ($raw['approve'] ?? '')];
        }

        if ($payMethod === 'CVS') {
            $fields[] = ['key' => 'cvs_code', 'label' => __('sirsoft-pay_kginicis::messages.receipt.fields.cvs_code'), 'value' => (string) ($raw['convenience'] ?? $paymentMeta['cvs_convenience'] ?? '')];
            $fields[] = ['key' => 'cvs_confirmation_no', 'label' => __('sirsoft-pay_kginicis::messages.receipt.fields.cvs_confirmation_no'), 'value' => (string) ($raw['confNo'] ?? $paymentMeta['cvs_conf_no'] ?? $payment->vbank_number ?? '')];
            $fields[] = ['key' => 'cvs_receipt_no', 'label' => __('sirsoft-pay_kginicis::messages.receipt.fields.cvs_receipt_no'), 'value' => (string) ($raw['receiptNo'] ?? $paymentMeta['cvs_receipt_no'] ?? '')];
            $fields[] = ['key' => 'cvs_payment_term', 'label' => __('sirsoft-pay_kginicis::messages.receipt.fields.cvs_payment_term'), 'value' => $this->formatCbtCompactDateTime((string) ($raw['paymentTerm'] ?? $paymentMeta['cvs_payment_term'] ?? ''))];
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
            'CARD' => __('sirsoft-pay_kginicis::messages.receipt.cbt_pay_methods.card'),
            'CVS' => __('sirsoft-pay_kginicis::messages.receipt.cbt_pay_methods.cvs'),
            'PAYPAY' => 'PayPay',
            'LINEPAY' => 'LINE Pay',
            default => $payMethod !== '' ? $payMethod : __('sirsoft-pay_kginicis::messages.receipt.cbt_pay_methods.default'),
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
                'label' => __('sirsoft-pay_kginicis::messages.receipt.cbt_cvs.label'),
                'view_label' => __('sirsoft-pay_kginicis::messages.receipt.cbt_cvs.view_label'),
                'title' => __('sirsoft-pay_kginicis::messages.receipt.cbt_cvs.title'),
                'notice' => __('sirsoft-pay_kginicis::messages.receipt.cbt_cvs.notice'),
            ];
        }

        return [
            'label' => __('sirsoft-pay_kginicis::messages.receipt.cbt.label'),
            'view_label' => __('sirsoft-pay_kginicis::messages.receipt.cbt.view_label'),
            'title' => __('sirsoft-pay_kginicis::messages.receipt.cbt.title'),
            'notice' => __('sirsoft-pay_kginicis::messages.receipt.cbt.notice'),
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
            'waiting_deposit', 'paid', 'cancelled', 'failed' => __('sirsoft-pay_kginicis::messages.receipt.statuses.'.$paymentStatus),
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

        return $months <= 0
            ? __('sirsoft-pay_kginicis::messages.receipt.installment.lump_sum')
            : __('sirsoft-pay_kginicis::messages.receipt.installment.months', ['months' => $months]);
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
            'card', 'vbank', 'bank', 'dbank', 'phone', 'point', 'deposit', 'free' => __('sirsoft-pay_kginicis::messages.receipt.payment_methods.'.strtolower($paymentMethod)),
            'mobile' => __('sirsoft-pay_kginicis::messages.receipt.payment_methods.phone'),
            default => $paymentMethod !== '' ? $paymentMethod : '-',
        };
    }

    /**
     * 간편결제(내장 PG) 제공자 코드의 표시 이름을 요청 언어로 반환합니다.
     *
     * 아는 제공자는 번역 이름을, 모르는 제공자는 결제 메타에 저장된 이름(없으면 코드)을 쓴다.
     *
     * @param  ?string  $provider  제공자 코드
     * @param  mixed  $storedLabel  결제 메타에 저장된 표시 이름(결제 시점 한국어)
     * @return string|null 표시 이름(제공자가 없으면 저장된 이름, 둘 다 없으면 null)
     */
    private function embeddedPgProviderLabel(?string $provider, mixed $storedLabel = null): ?string
    {
        $storedLabel = is_string($storedLabel) && $storedLabel !== '' ? $storedLabel : null;

        if ($provider === null || $provider === '') {
            return $storedLabel;
        }

        $code = strtolower($provider) === 'toss' ? 'tosspay' : strtolower($provider);

        if (in_array($code, self::EASY_PAY_PROVIDERS, true)) {
            return __('sirsoft-pay_kginicis::messages.receipt.easy_pay_providers.'.$code);
        }

        return $storedLabel ?? $provider;
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
