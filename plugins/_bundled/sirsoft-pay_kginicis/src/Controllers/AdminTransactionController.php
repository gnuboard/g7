<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

class AdminTransactionController extends AdminBaseController
{
    public function __construct(
        private readonly KgInicisApiService $apiService,
    ) {
        parent::__construct();
    }

    /**
     * TID 직접 조회
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function query(Request $request): JsonResponse
    {
        $tid = trim((string) $request->input('tid', ''));

        if ($tid === '') {
            return ResponseHelper::error('messages.failed', 422, ['tid' => ['TID를 입력하세요.']]);
        }

        return $this->queryByTid($tid);
    }

    /**
     * 주문번호로 거래 조회
     *
     * @param  string  $orderNumber
     * @return JsonResponse
     */
    public function queryByOrder(string $orderNumber): JsonResponse
    {
        $payment = DB::table('ecommerce_order_payments')
            ->join('ecommerce_orders', 'ecommerce_orders.id', '=', 'ecommerce_order_payments.order_id')
            ->where('ecommerce_orders.order_number', $orderNumber)
            ->whereNotNull('ecommerce_order_payments.transaction_id')
            ->where('ecommerce_order_payments.transaction_id', '!=', '')
            ->where('ecommerce_order_payments.pg_provider', 'kginicis')
            ->select(['ecommerce_order_payments.transaction_id', 'ecommerce_order_payments.payment_meta'])
            ->first();

        if (! $payment) {
            return ResponseHelper::success('messages.success', null);
        }

        return $this->queryByTid($payment->transaction_id);
    }

    private function queryByTid(string $tid): JsonResponse
    {
        try {
            $localPayment = DB::table('ecommerce_order_payments')
                ->leftJoin('ecommerce_orders', 'ecommerce_orders.id', '=', 'ecommerce_order_payments.order_id')
                ->where('ecommerce_order_payments.transaction_id', $tid)
                ->select([
                    'ecommerce_order_payments.transaction_id',
                    'ecommerce_order_payments.payment_status',
                    'ecommerce_order_payments.is_escrow',
                    'ecommerce_order_payments.payment_meta',
                    'ecommerce_order_payments.embedded_pg_provider',
                    'ecommerce_order_payments.paid_amount_local',
                    'ecommerce_order_payments.currency',
                    'ecommerce_order_payments.card_name',
                    'ecommerce_order_payments.card_number_masked',
                    'ecommerce_order_payments.card_approval_number',
                    'ecommerce_order_payments.card_installment_months',
                    'ecommerce_order_payments.vbank_code',
                    'ecommerce_order_payments.vbank_name',
                    'ecommerce_order_payments.vbank_number',
                    'ecommerce_order_payments.vbank_holder',
                    'ecommerce_order_payments.vbank_due_at',
                    'ecommerce_order_payments.buyer_name',
                    'ecommerce_order_payments.buyer_email',
                    'ecommerce_order_payments.buyer_phone',
                    'ecommerce_order_payments.payment_name',
                    'ecommerce_order_payments.paid_at',
                    'ecommerce_orders.order_number',
                    'ecommerce_orders.order_status',
                    'ecommerce_orders.currency as order_currency',
                    'ecommerce_orders.total_due_amount',
                ])
                ->first();

            $localMeta = [];
            $localRaw = [];
            if ($localPayment?->payment_meta) {
                $localMeta = json_decode($localPayment->payment_meta, true) ?: [];
                $localRaw = $localMeta['pg_raw_response'] ?? [];
            }
            $localRaw = $this->mergeLocalPaymentFallback($localRaw, $localPayment);

            $paymentMid = $this->resolvePaymentMid($localMeta, $localRaw, $tid);
            $embeddedPgProvider = $localPayment?->embedded_pg_provider
                ?: ($localMeta['embedded_pg_provider'] ?? null);

            if ($this->isCbtLocalPayment($localPayment, $localMeta, $localRaw, $tid)) {
                $result = $this->buildLocalCbtTransactionResult(
                    $tid,
                    $localPayment,
                    $localMeta,
                    $localRaw,
                    is_string($embeddedPgProvider) ? $embeddedPgProvider : null,
                );

                return ResponseHelper::success('messages.success', $result);
            }

            // 결제 시점 모드(payment_meta.is_test_mode) 가 있으면 그 모드의 inapi 자격증명으로 조회.
            // 누락 시 MID prefix 로 추정 ('SIR' = live, 그 외 = test).
            if ($paymentMid !== null) {
                $isTestMode = $localMeta['is_test_mode']
                    ?? ! str_starts_with($paymentMid, 'SIR');
                $this->apiService->useStoredCredentials((bool) $isTestMode, $paymentMid);
            } else {
                $this->apiService->useEscrowCredentials((bool) ($localPayment?->is_escrow ?? false));
            }

            $result = $this->apiService->queryTransaction($tid, $paymentMid);

            $result = $this->enrichResult(
                $result,
                $localRaw,
                (bool) ($localPayment?->is_escrow ?? false),
                $localPayment?->vbank_due_at,
                is_string($embeddedPgProvider) ? $embeddedPgProvider : null,
            );

            return ResponseHelper::success('messages.success', $result);
        } catch (\Exception $e) {
            Log::error('KG Inicis queryTransaction failed', [
                'tid'   => $tid,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::error('messages.failed', 502, null);
        }
    }

    /**
     * 결제 시점에 사용된 가맹점 ID(MID) 를 해결한다.
     *
     * 해결 우선순위:
     *   1) payment_meta.mid (콜백에서 명시 저장된 MID — 신규 주문 표준 경로)
     *   2) payment_meta.pg_raw_response.mid 또는 .MID (예전 콜백 응답 raw 값)
     *   3) TID 의 char 10–20 추출 (KG 이니시스 TID 포맷: prefix(10) + MID(10) + timestamp/seq)
     *   4) null 반환 → 호출자가 fallback 으로 현재 설정 MID 사용
     *
     * @param  array  $localMeta  payment_meta 전체
     * @param  array  $localRaw  payment_meta.pg_raw_response
     * @param  string  $tid  거래번호
     * @return string|null 해결된 MID 또는 null
     */
    private function resolvePaymentMid(array $localMeta, array $localRaw, string $tid): ?string
    {
        if (! empty($localMeta['mid']) && is_string($localMeta['mid'])) {
            return $localMeta['mid'];
        }

        foreach (['mid', 'MID'] as $key) {
            if (! empty($localRaw[$key]) && is_string($localRaw[$key])) {
                return $localRaw[$key];
            }
        }

        // KG 이니시스 TID 포맷: 10자 prefix (INIMX_CARD / StdpayCARD / ININPGVBNK 등)
        // + 10자 MID + timestamp/sequence.
        if (strlen($tid) >= 20) {
            $candidate = substr($tid, 10, 10);
            // MID 후보 검증: 영숫자만 (KG 이니시스 MID 명세)
            if (preg_match('/^[A-Za-z0-9]{10}$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    private function mergeLocalPaymentFallback(array $localRaw, mixed $localPayment): array
    {
        if (! $localPayment) {
            return $localRaw;
        }

        $fallbacks = [
            'approvedAmount' => $localPayment->paid_amount_local ?? null,
            'currency' => $localPayment->currency ?? null,
            'applNum' => $localPayment->card_approval_number ?? null,
            'cardName' => $localPayment->card_name ?? null,
            'cardNum' => $localPayment->card_number_masked ?? null,
            'cardQuota' => $localPayment->card_installment_months ?? null,
            'VACT_BankCode' => $localPayment->vbank_code ?? null,
            'VACT_BankName' => $localPayment->vbank_name ?? null,
            'VACT_Num' => $localPayment->vbank_number ?? null,
            'VACT_Name' => $localPayment->vbank_holder ?? null,
            'buyerName' => $localPayment->buyer_name ?? null,
            'buyerEmail' => $localPayment->buyer_email ?? null,
            'buyerTel' => $localPayment->buyer_phone ?? null,
            'goodName' => $localPayment->payment_name ?? null,
        ];

        foreach ($fallbacks as $key => $value) {
            if (($localRaw[$key] ?? null) === null && $value !== null && $value !== '') {
                $localRaw[$key] = $value;
            }
        }

        return $localRaw;
    }

    private function isCbtLocalPayment(mixed $localPayment, array $localMeta, array $localRaw, string $tid): bool
    {
        if (($localMeta['is_cbt'] ?? false) === true || ($localMeta['cbt_type'] ?? '') !== '') {
            return true;
        }

        if (str_starts_with($tid, 'INIJPG')) {
            return true;
        }

        $currency = strtoupper((string) (
            $localMeta['currency']
            ?? $localRaw['currencyCd']
            ?? $localRaw['currency']
            ?? $localPayment?->currency
            ?? $localPayment?->order_currency
            ?? ''
        ));

        return $currency === 'JPY' && str_starts_with($tid, 'INIJPG');
    }

    private function buildLocalCbtTransactionResult(
        string $tid,
        mixed $localPayment,
        array $localMeta,
        array $localRaw,
        ?string $embeddedPgProvider,
    ): array {
        $pick = function (string ...$keys) use ($localMeta, $localRaw, $localPayment): ?string {
            foreach ($keys as $key) {
                if (isset($localRaw[$key]) && $localRaw[$key] !== '') {
                    return (string) $localRaw[$key];
                }
                if (isset($localMeta[$key]) && $localMeta[$key] !== '') {
                    return (string) $localMeta[$key];
                }
            }

            $fallbacks = [
                'tid' => $localPayment?->transaction_id ?? null,
                'order_number' => $localPayment?->order_number ?? null,
                'payment_status' => $localPayment?->payment_status ?? null,
                'paid_amount_local' => $localPayment?->paid_amount_local ?? null,
                'currency' => $localPayment?->currency ?? $localPayment?->order_currency ?? null,
                'paid_at' => $localPayment?->paid_at ?? null,
            ];

            foreach ($keys as $key) {
                if (isset($fallbacks[$key]) && $fallbacks[$key] !== '') {
                    return (string) $fallbacks[$key];
                }
            }

            return null;
        };

        $payMethod = $this->normalizeCbtPayMethod($pick('pay_method', 'paymethod', 'payMethod') ?? '');
        $basePayMethodLabel = $this->payMethodLabel($payMethod);
        $embeddedPgProviderLabel = $this->embeddedPgProviderLabel($embeddedPgProvider);
        $currency = strtoupper($pick('currencyCd', 'currencyCode', 'currency') ?? 'JPY');

        $result = [
            'resultCode' => $pick('resultCode', 'result_code', 'code') ?? 'LOCAL_CBT',
            'resultMsg' => $pick('resultMsg', 'message') ?? 'CBT 거래는 로컬 결제 확인 정보로 표시됩니다.',
            'tid' => $tid,
            '_is_cbt' => true,
            '_is_local_confirmation' => true,
            '_is_test_mode' => (bool) ($localMeta['is_test_mode'] ?? $this->apiService->isTestMode()),
            '_local_is_escrow' => (bool) ($localPayment?->is_escrow ?? false),
            '_pay_method' => $payMethod,
            '_base_pay_method_label' => $basePayMethodLabel,
            '_embedded_pg_provider' => $embeddedPgProvider,
            '_embedded_pg_provider_label' => $embeddedPgProviderLabel,
            '_pay_method_label' => $embeddedPgProviderLabel
                ? $embeddedPgProviderLabel . ' (' . $basePayMethodLabel . ')'
                : $basePayMethodLabel,
            '_auth_code' => $pick('approve', 'applNo', 'approvalNo', 'authCode', 'confNo', 'receiptNo'),
            '_auth_date' => $this->formatCbtDateTime(
                $pick('auth_date', 'applDate', 'applDt'),
                $pick('applTime', 'applTm'),
            ) ?? $this->formatTimestamp((string) ($localPayment?->paid_at ?? '')),
            '_total_price' => $pick('amount', 'price', 'cvs_amount', 'paid_amount_local'),
            '_currency' => $currency !== '' ? $currency : 'JPY',
            '_moid' => $pick('orderId', 'orderID', 'oid', 'order_number'),
            '_buyer_name' => $pick('buyerName', 'buyer_name'),
            '_buyer_email' => $pick('buyerEmail', 'buyer_email'),
            '_buyer_tel' => $pick('buyerTel', 'buyer_phone'),
            '_status' => $pick('payment_status', 'cvs_status', 'status'),
            '_cancel_price' => $pick('cancelPrice', 'cancel_price'),
            '_cancel_date' => $this->formatCbtDateTime($pick('cancelDate'), $pick('cancelTime')),
            '_part_cancel_list' => $this->normalizePartCancelList(is_array($localRaw['partCancelList'] ?? null) ? $localRaw['partCancelList'] : []),
            '_card_name' => $pick('cardName', 'card_name'),
            '_card_num' => $pick('cardNum', 'card_number_masked'),
            '_card_code' => $pick('cardCode'),
            '_card_quota' => $this->formatQuota($pick('installMonth', 'cardQuota', 'card_installment_months')),
            '_card_interest' => null,
            '_vbank_num' => $pick('confNo', 'receiptNo', 'vbank_number'),
            '_vbank_bank_code' => $pick('convenience', 'vbank_code'),
            '_vbank_bank_name' => $pick('vbank_name') ?? ($payMethod === 'CVS' ? 'CVS' : null),
            '_vbank_holder' => $pick('vbank_holder'),
            '_vbank_expire_date' => $this->formatCompactDateTime($pick('paymentTerm', 'cvs_payment_term'))
                ?? $this->formatTimestamp((string) ($localPayment?->vbank_due_at ?? '')),
            '_vbank_status' => $pick('cvs_status'),
            '_vbank_paid_at' => $this->formatCbtDateTime($pick('applDt'), $pick('applTm')),
            '_bank_code' => null,
            '_bank_name' => null,
            '_bank_acnt_num' => null,
            '_hpp_num' => null,
            '_hpp_corp' => null,
            '_escrow_status' => null,
            '_escrow_confirm' => null,
            '_inquiry_at' => date('Y-m-d H:i:s'),
            '_local_notice' => 'CBT 거래는 한국 INIAPI 거래조회 대상이 아니므로 저장된 승인/입금 확인 정보로 표시됩니다.',
            '_cbt_cvs' => $payMethod === 'CVS' ? [
                'status' => (string) ($localMeta['cvs_status'] ?? ''),
                'last_notify_at' => (string) ($localMeta['cvs_last_notify_at'] ?? ''),
                'last_notify_result' => (string) ($localMeta['cvs_last_notify_result'] ?? ''),
                'last_notify_reason' => (string) ($localMeta['cvs_last_notify_reason'] ?? ''),
                'last_recheck_at' => (string) ($localMeta['cvs_last_recheck_at'] ?? ''),
                'last_recheck_result' => (string) ($localMeta['cvs_last_recheck_result'] ?? ''),
                'expired_at' => (string) ($localMeta['cvs_expired_at'] ?? ''),
                'expiry_reason' => (string) ($localMeta['cvs_expiry_reason'] ?? ''),
                'notify_history' => is_array($localMeta['cvs_notify_history'] ?? null)
                    ? array_slice($localMeta['cvs_notify_history'], 0, 10)
                    : [],
            ] : null,
        ];

        return $result;
    }

    private function normalizeCbtPayMethod(string $code): string
    {
        return strtoupper(trim($code));
    }

    private function formatCbtDateTime(?string $date, ?string $time): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        if (strlen($date) === 14 && preg_match('/^\d{14}$/', $date) === 1) {
            return $this->formatCompactDateTime($date);
        }

        return $this->formatDateTime($date, $time);
    }

    private function formatCompactDateTime(?string $value): ?string
    {
        if ($value === null || preg_match('/^\d{14}$/', $value) !== 1) {
            return null;
        }

        return substr($value, 0, 4) . '-'
            . substr($value, 4, 2) . '-'
            . substr($value, 6, 2) . ' '
            . substr($value, 8, 2) . ':'
            . substr($value, 10, 2) . ':'
            . substr($value, 12, 2);
    }

    private function formatTimestamp(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * inquiry 응답을 화면 표시용 필드로 보강한다.
     *
     * 우선순위: inquiry 응답값 → 로컬 콜백 응답 fallback → null
     *
     * @param  array  $result  inquiry API 응답
     * @param  array  $localRaw  결제 콜백 시 저장한 raw 응답
     * @param  bool  $isEscrow  로컬 결제 레코드의 에스크로 여부
     * @param  string|null  $localVbankDueAt  로컬 DB 의 vbank_due_at (정확한 cutoff timestamp)
     * @return array 보강된 응답
     */
    private function enrichResult(
        array $result,
        array $localRaw,
        bool $isEscrow,
        ?string $localVbankDueAt = null,
        ?string $embeddedPgProvider = null,
    ): array
    {
        $cardInfo = is_array($result['cardInfo'] ?? null) ? $result['cardInfo'] : [];

        $pick = function (string ...$keys) use ($result, $localRaw, $cardInfo): ?string {
            foreach ($keys as $key) {
                if (isset($result[$key]) && $result[$key] !== '') {
                    return (string) $result[$key];
                }
                if (isset($cardInfo[$key]) && $cardInfo[$key] !== '') {
                    return (string) $cardInfo[$key];
                }
            }
            foreach ($keys as $key) {
                if (isset($localRaw[$key]) && $localRaw[$key] !== '') {
                    return (string) $localRaw[$key];
                }
            }

            return null;
        };

        $payMethod = $pick('payMethod', 'PayMethod', 'paymethod') ?? '';
        $basePayMethodLabel = $this->payMethodLabel($payMethod);
        $embeddedPgProviderLabel = $this->embeddedPgProviderLabel($embeddedPgProvider);

        $result['_is_test_mode']      = $this->apiService->isTestMode();
        $result['_local_is_escrow']   = $isEscrow;
        $result['_pay_method']        = $payMethod;
        $result['_base_pay_method_label'] = $basePayMethodLabel;
        $result['_embedded_pg_provider'] = $embeddedPgProvider;
        $result['_embedded_pg_provider_label'] = $embeddedPgProviderLabel;
        $result['_pay_method_label']  = $embeddedPgProviderLabel
            ? $embeddedPgProviderLabel.' ('.$basePayMethodLabel.')'
            : $basePayMethodLabel;
        $result['_auth_code']         = $pick('applNum', 'approvedNumber', 'authCode', 'AuthCode');
        $result['_auth_date']         = $this->formatDateTime(
            $pick('applDate', 'approvedDate', 'AuthDate'),
            $pick('applTime', 'approvedTime', 'AuthTime'),
        );
        $result['_total_price']       = $pick('TotPrice', 'totalPrice', 'price', 'Amt', 'approvedAmount');
        $result['_currency']          = $pick('currency', 'Currency', 'currencyCode') ?? 'WON';
        $result['_moid']              = $pick('MOID', 'moid', 'Moid', 'oid');
        $result['_buyer_name']        = $pick('buyerName', 'BuyerName');
        $result['_buyer_email']       = $pick('buyerEmail', 'BuyerEmail', 'buyerMail');
        $result['_buyer_tel']         = $pick('buyerTel', 'BuyerTel');
        $result['_status']            = $pick('status', 'Status', 'transactionStatus');

        // 취소이력
        $result['_cancel_price']      = $pick('cancelPrice', 'CancelPrice', 'cancelAmount');
        $result['_cancel_date']       = $this->formatDateTime($pick('cancelDate', 'CancelDate'), $pick('cancelTime', 'CancelTime'));
        $partCancelRaw = $result['partCancelList'] ?? $localRaw['partCancelList'] ?? [];
        $result['_part_cancel_list']  = $this->normalizePartCancelList(is_array($partCancelRaw) ? $partCancelRaw : []);

        // 결제수단별 상세 (신구 응답 포맷 호환: 평탄 키 + cardInfo 중첩)
        $result['_card_name']         = $pick('cardName', 'CardName', 'issuerName');
        $result['_card_num']          = $pick('cardNum', 'CardNum', 'CARD_Num', 'cardNumber');
        $result['_card_code']         = $pick('cardCode', 'CardCode');
        $result['_card_quota']        = $this->formatQuota($pick('cardQuota', 'CardQuota', 'quota'));
        $result['_card_interest']     = $pick('cardInterest', 'CardInterest', 'isInterestFree');

        $result['_vbank_num']         = $pick('VACT_Num', 'vactNum', 'vbank_num');
        $result['_vbank_bank_code']   = $pick('VACT_BankCode', 'vactBankCode', 'vbank_bank_code');
        $result['_vbank_bank_name']   = $pick('VACT_BankName', 'vactBankName', 'vbank_bank_name') ?? $this->bankNameByCode($result['_vbank_bank_code'] ?? null);
        $result['_vbank_holder']      = $pick('VACT_Name', 'vactName', 'vbank_holder');
        // 가상계좌 입금기한:
        // 로컬 vbank_due_at 이 있으면 KST 로 변환해 사용 — 결제 발급 시 KG 이니시스가 보낸
        // VACT_Date(=다음 영업일) + VACT_Time(=08:59:59) 으로 만든 정확한 cutoff timestamp.
        // DB 는 UTC 로 저장되므로 표시 시 Asia/Seoul 로 변환해야 주문 상세 화면(KST 표시) 과 일치.
        // 조회 응답의 vacctInfo.validDate 는 "마지막 입금 가능일" convention 이라 1일 일찍 표시되어
        // 로컬 timestamp 가 더 정확.
        $result['_vbank_expire_date'] = $localVbankDueAt !== null
            ? \Carbon\Carbon::parse($localVbankDueAt, 'UTC')->setTimezone('Asia/Seoul')->format('Y-m-d H:i:s')
            : $this->formatDate($pick('VACT_Date', 'vactDate', 'vbank_expire_date', 'validDate'));
        $vbankStatus                  = $pick('VACT_Status', 'vactStatus', 'vbank_status');
        $result['_vbank_status']      = $vbankStatus;
        $result['_vbank_paid_at']     = $this->formatDateTime($pick('VACT_InputDate', 'VACT_InputTime') ? $pick('VACT_InputDate') : null, $pick('VACT_InputTime'));

        $result['_bank_code']         = $pick('acntBankCode', 'BankCode');
        $result['_bank_name']         = $pick('acntBankName', 'BankName') ?? $this->bankNameByCode($result['_bank_code'] ?? null);
        $result['_bank_acnt_num']     = $pick('acntNum', 'AcntNum');

        $result['_hpp_num']           = $pick('HPP_Num', 'hppNum', 'phoneNum');
        $result['_hpp_corp']          = $pick('HPP_Corp', 'hppCorp', 'mobileCarrier');

        $result['_escrow_status']     = $pick('escrowStatus', 'EscrowStatus');
        $result['_escrow_confirm']    = $this->formatDateTime($pick('escrowConfirmDate'), $pick('escrowConfirmTime'));

        // 환경 정보
        $result['_inquiry_at']        = date('Y-m-d H:i:s');

        return $result;
    }

    /**
     * KG 이니시스 payMethod 코드를 한국어 라벨로 매핑한다.
     *
     * 플러그인 JSON lang 의 중첩 키는 Laravel `__()` 의 flat lookup 으로 해석되지 않으므로
     * 한국어 어드민 UX 전제하에 PHP 직접 매핑. 다국어가 필요해질 경우 레이아웃 측
     * `$t:` 보간으로 처리하거나 lang/ko/pay_method.php 같은 PHP 배열 파일로 분리한다.
     *
     * @param  string  $code  KG 이니시스 결제수단 코드
     * @return string 표시용 라벨
     */
    private function payMethodLabel(string $code): string
    {
        if ($code === '') {
            return '-';
        }

        return match (strtolower($code)) {
            'card'                                    => '신용카드',
            'wcard'                                   => '해외카드',
            'vbank'                                   => '가상계좌',
            'directbank', 'inibank', 'banktransfer'   => '계좌이체',
            'hpp', 'mobile'                           => '휴대폰',
            'easypay'                                 => '간편결제',
            'point'                                   => '포인트',
            'gift'                                    => '상품권',
            'paybook'                                 => '도서문화상품권',
            'billing', 'billingpay'                   => '정기결제',
            'samsungpay'                              => '삼성페이',
            'kakaopay'                                => '카카오페이',
            'lpay'                                    => 'L.pay',
            'payco'                                   => '페이코',
            'naverpay'                                => '네이버페이',
            'tosspay', 'toss'                         => '토스페이',
            'ssgpay'                                  => 'SSG페이',
            'paypay'                                  => 'PayPay',
            'cvs'                                     => '일본 편의점결제',
            default                                   => $code,
        };
    }

    private function embeddedPgProviderLabel(?string $provider): ?string
    {
        if ($provider === null || $provider === '') {
            return null;
        }

        return match (strtolower($provider)) {
            'samsungpay' => '삼성페이',
            'kakaopay' => '카카오페이',
            'lpay' => 'L.pay',
            'payco' => '페이코',
            'naverpay' => '네이버페이',
            'tosspay', 'toss' => '토스페이',
            'ssgpay' => 'SSG페이',
            default => $provider,
        };
    }

    /**
     * KG 이니시스 표준 은행 코드 → 은행명 매핑.
     *
     * @param  string|null  $code  표준 은행 코드
     * @return string|null 은행명 또는 null
     */
    private function bankNameByCode(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        $map = [
            '03' => '기업은행',
            '04' => '국민은행',
            '05' => '하나(외환)은행',
            '07' => '수협',
            '11' => '농협',
            '12' => '단위농협',
            '20' => '우리은행',
            '21' => '구.조흥은행',
            '22' => '구.상업은행',
            '23' => 'SC제일은행',
            '26' => '구.신한은행',
            '27' => '한국씨티은행',
            '31' => '대구은행',
            '32' => '부산은행',
            '34' => '광주은행',
            '35' => '제주은행',
            '37' => '전북은행',
            '39' => '경남은행',
            '45' => '새마을금고',
            '48' => '신협',
            '50' => '상호저축은행',
            '54' => 'HSBC',
            '57' => '도이치은행',
            '60' => 'BOA',
            '64' => '산림조합',
            '71' => '우체국',
            '81' => '하나은행',
            '83' => '신한은행',
            '88' => '신한(통합)은행',
            '89' => '케이뱅크',
            '90' => '카카오뱅크',
            '92' => '토스뱅크',
        ];

        return $map[$code] ?? null;
    }

    /**
     * 카드 할부 코드를 한국어로 변환한다.
     *
     * @param  string|null  $quota  KG 이니시스 할부 개월 코드
     * @return string|null 표시용 라벨
     */
    private function formatQuota(?string $quota): ?string
    {
        if ($quota === null || $quota === '') {
            return null;
        }

        $months = (int) $quota;

        return $months === 0 ? '일시불' : "{$months}개월";
    }

    /**
     * YYYYMMDD + HHMMSS 형식을 YYYY-MM-DD HH:MM:SS 로 변환한다.
     *
     * @param  string|null  $date  YYYYMMDD
     * @param  string|null  $time  HHMMSS
     * @return string|null 사람이 읽을 수 있는 형식
     */
    private function formatDateTime(?string $date, ?string $time): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        $datePart = $this->formatDate($date) ?? $date;
        $timePart = '';

        if ($time !== null && $time !== '' && strlen($time) >= 6) {
            $timePart = ' ' . substr($time, 0, 2) . ':' . substr($time, 2, 2) . ':' . substr($time, 4, 2);
        }

        return $datePart . $timePart;
    }

    /**
     * YYYYMMDD 형식을 YYYY-MM-DD 로 변환한다.
     *
     * @param  string|null  $date  YYYYMMDD
     * @return string|null 변환된 날짜
     */
    private function formatDate(?string $date): ?string
    {
        if ($date === null || $date === '' || strlen($date) < 8) {
            return $date;
        }

        return substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
    }

    /**
     * 부분취소 이력을 화면 표시용으로 정규화한다.
     *
     * @param  array  $list  KG 이니시스 partCancelList
     * @return array 정규화된 부분취소 이력
     */
    private function normalizePartCancelList(array $list): array
    {
        $normalized = [];
        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }
            $normalized[] = [
                'price'  => $item['price'] ?? $item['cancelPrice'] ?? null,
                'date'   => $this->formatDateTime($item['cancelDate'] ?? null, $item['cancelTime'] ?? null),
                'msg'    => $item['cancelMsg'] ?? $item['msg'] ?? null,
                'tid'    => $item['cancelTid'] ?? $item['tid'] ?? null,
            ];
        }

        return $normalized;
    }
}
