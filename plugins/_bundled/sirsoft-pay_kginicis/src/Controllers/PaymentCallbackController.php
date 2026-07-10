<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Helpers\DeviceDetector;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Concerns\IssuesReceiptCookie;
use Plugins\Sirsoft\PayKginicis\Concerns\PreventsReplayCallback;
use Plugins\Sirsoft\PayKginicis\Concerns\ResolvesEasyPaySelection;
use Plugins\Sirsoft\PayKginicis\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayKginicis\Concerns\SerializesPaymentCallbacks;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesCbtOrderContext;
use Plugins\Sirsoft\PayKginicis\Http\Requests\AuthCallbackRequest;
use Plugins\Sirsoft\PayKginicis\Http\Requests\MobileVbankNotifyRequest;
use Plugins\Sirsoft\PayKginicis\Http\Requests\VbankNotifyRequest;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

/**
 * KG 이니시스 결제 콜백 컨트롤러
 *
 * KG 이니시스 표준결제창은 2단계 인증 방식입니다:
 *  1단계: 브라우저가 POST 콜백으로 authToken + authUrl 전달 → authCallback()
 *  2단계: 서버가 authUrl로 최종 승인 요청 → KgInicisApiService::authorizePayment()
 */
class PaymentCallbackController
{
    use IssuesReceiptCookie;
    use PreventsReplayCallback;
    use ResolvesEasyPaySelection;
    use SanitizesPgResponse;
    use SerializesPaymentCallbacks;
    use ValidatesCbtOrderContext;

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    /**
     * 사용자가 결제창을 X / '취소' 로 종료했을 때 KG 이니시스가 전달하는 resultCode 목록.
     *
     * 사용자 취소는 결제 실패가 아니라 의도된 중단이므로 에러 query 없이 체크아웃으로
     * 조용히 복귀시킨다 (NHN KCP 의 CANCEL_RES_CODES 패턴과 동일).
     */
    private const CANCEL_RES_CODES = ['2001', '0021', '0022', ''];

    private const PC_AUTH_RESPONSE_KEYS = [
        'resultCode',
        'resultMsg',
        'tid',
        'payMethod',
        'applNum',
        'applDate',
        'applTime',
        'TotPrice',
        'totPrice',
        'MOID',
        'moid',
        'mid',
        'MID',
        'cardCode',
        'cardName',
        'cardQuota',
        'cardInterest',
        'goodName',
        'goodsName',
        'currency',
        'currencyCode',
    ];

    private const PC_VBANK_ISSUE_RESPONSE_KEYS = [
        'resultCode',
        'resultMsg',
        'tid',
        'payMethod',
        'applDate',
        'applTime',
        'MOID',
        'moid',
        'mid',
        'MID',
        'TotPrice',
        'totPrice',
        'VACT_BankCode',
        'VACT_BankName',
        'vactBankName',
        'VACT_Date',
        'VACT_Time',
        'VACT_Status',
        'goodName',
        'goodsName',
    ];

    private const PC_VBANK_NOTIFY_RESPONSE_KEYS = [
        'no_tid',
        'no_oid',
        'id_merchant',
        'dt_trans',
        'tm_trans',
        'cd_bank',
        'amt_input',
        'nm_inputbank',
    ];

    private const MOBILE_VBANK_NOTIFY_RESPONSE_KEYS = [
        'P_STATUS',
        'P_TYPE',
        'P_TID',
        'P_OID',
        'P_AMT',
        'P_AUTH_DT',
        'P_FN_CD1',
        'P_FN_NM',
    ];

    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly KgInicisApiService $apiService,
    ) {}

    /**
     * KG 이니시스 표준결제 인증 콜백을 받아 서버 승인 후 결과 페이지로 리다이렉트합니다.
     *
     * @param  AuthCallbackRequest  $request  결제 인증 콜백 요청
     * @return RedirectResponse 성공/실패 결과 페이지 리다이렉트
     */
    public function authCallback(AuthCallbackRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $selectedEasyPayMethod = $this->resolveSelectedEasyPayMethod($request);

        $resultCode = $validated['resultCode'];

        // 주문번호: 구버전 MOID, 신버전 orderNumber 모두 지원
        $moid = $validated['MOID'] ?? $validated['orderNumber'] ?? null;

        // 결제금액: 콜백에 없을 수 있음 → 서버 승인 후 PG 응답에서 가져옴
        $totPrice = isset($validated['TotPrice']) ? (int) $validated['TotPrice'] : null;

        Log::info('KG Inicis: callback received', [
            'moid' => $moid,
            'result_code' => $resultCode,
            'idc_name' => $validated['idc_name'] ?? null,
            'auth_url' => $validated['authUrl'] ?? null,
            'all_fields' => array_keys($request->all()),
            'easy_pay' => $this->buildEasyPayLogContext($selectedEasyPayMethod),
        ]);

        if (! $moid) {
            Log::error('KG Inicis: order number missing from callback', ['input' => array_keys($request->all())]);

            return redirect($this->resolveFailUrl(['error' => 'invalid_params']));
        }

        // 결제 실패/취소인 경우: authToken/authUrl 없이 올 수 있으므로 먼저 처리
        if ($resultCode !== '0000') {
            $resultMsg = (string) ($validated['resultMsg'] ?? '');

            // 사용자 취소는 결제 실패가 아니라 의도된 중단 — 에러 query 없이 조용히 복귀.
            //  1) resultCode 가 KG 이니시스 표준 cancel 코드 목록에 포함되거나
            //  2) resultMsg 에 '취소' 또는 '사용자' 키워드가 포함된 경우 (버전별 코드 차이 대응)
            $isUserCancel = in_array($resultCode, self::CANCEL_RES_CODES, true)
                || str_contains($resultMsg, '취소')
                || str_contains($resultMsg, '사용자');

            Log::info('KG Inicis: auth result non-success', [
                'moid' => $moid,
                'result_code' => $resultCode,
                'result_msg' => $resultMsg,
                'is_user_cancel' => $isUserCancel,
            ]);

            if ($isUserCancel) {
                // 에러 query 미부착 → 체크아웃 페이지가 toast/error 분기를 타지 않음
                return redirect($this->resolveFailUrl());
            }

            return redirect($this->resolveFailUrl([
                'error' => $resultCode,
                'message' => $resultMsg,
                'orderId' => $moid,
            ]));
        }

        // 결제 성공(0000) 이후: authToken, authUrl, idc_name 필수
        $authToken = $validated['authToken'] ?? null;
        $idcName = $validated['idc_name'] ?? null;
        // authUrl 또는 checkAckUrl (버전에 따라 다름)
        $receivedAuthUrl = $validated['authUrl'] ?? $validated['checkAckUrl'] ?? null;
        $receivedNetCancelUrl = $validated['netCancelUrl'] ?? null;

        if (! $authToken || ! $idcName || ! $receivedAuthUrl) {
            Log::error('KG Inicis: missing required fields on success callback', [
                'moid' => $moid,
                'idc_name' => $idcName,
                'auth_url' => $receivedAuthUrl,
                'has_token' => (bool) $authToken,
            ]);

            return redirect($this->resolveFailUrl(['error' => 'missing_fields', 'orderId' => $moid]));
        }

        // idc_name + authUrl 화이트리스트 검증 (PC/모바일 URL 모두 허용, SSRF 방어)
        if (! $this->apiService->isValidIdcAuthUrl($idcName, $receivedAuthUrl)) {
            Log::error('KG Inicis: authUrl not in whitelist (possible SSRF attempt)', [
                'moid' => $moid,
                'idc_name' => $idcName,
                'received' => $receivedAuthUrl,
            ]);

            return redirect($this->resolveFailUrl(['error' => 'auth_url_invalid', 'orderId' => $moid]));
        }

        // 화이트리스트 검증 통과 → 수신된 URL을 그대로 사용 (PC/모바일 자동 대응)
        $authUrl = $receivedAuthUrl;
        $netCancelUrl = $this->apiService->resolveIdcNetCancelUrl($idcName);
        $tid = '';
        $callbackLock = null;

        try {
            $order = $this->orderService->findByOrderNumber($moid);

            if (! $order) {
                Log::error('KG Inicis: order not found', ['moid' => $moid]);

                return redirect($this->resolveFailUrl(['error' => 'order_not_found', 'orderId' => $moid]));
            }

            $callbackLock = $this->acquireOrderCallbackLock('authCallback', $moid);

            HookManager::doAction('sirsoft-pay_kginicis.payment.before_authorize', $order, $validated);

            $pgResponse = $this->apiService->authorizePayment($authUrl, $authToken);

            HookManager::doAction('sirsoft-pay_kginicis.payment.after_authorize', $order, $pgResponse);

            $pgResultCode = $pgResponse['resultCode'] ?? '';

            if ($pgResultCode !== '0000') {
                Log::warning('KG Inicis: authorize failed', [
                    'moid' => $moid,
                    'result_code' => $pgResultCode,
                    'result_msg' => $pgResponse['resultMsg'] ?? '',
                ]);

                $this->orderService->failPayment($order, $pgResultCode, $pgResponse['resultMsg'] ?? '');

                return redirect($this->resolveFailUrl([
                    'error' => $pgResultCode,
                    'message' => $pgResponse['resultMsg'] ?? '',
                    'orderId' => $moid,
                ]));
            }

            $tid = (string) ($pgResponse['tid'] ?? '');

            // 가상계좌: completePayment 없이 계좌 정보만 저장 (입금 통보 시 completePayment)
            if (($pgResponse['payMethod'] ?? '') === 'VBank') {
                $this->handleVbankIssued($order, $pgResponse, $tid, $request);
                $this->queueReceiptCookie($moid);

                return redirect($this->resolveSuccessUrl($moid));
            }

            // Replay 가드: 동일 tid 가 이미 paid 상태면 중복 처리하지 않고 성공 페이지로 복귀
            if ($this->wasAlreadyPaid($tid)) {
                $this->logReplayDetected($tid, $moid, 'PC authCallback (card)');
                $this->queueReceiptCookie($moid);

                return redirect($this->resolveSuccessUrl($moid));
            }

            // TotPrice 가 콜백에 없으면 PG 승인 응답의 TotPrice 사용
            if ($totPrice === null) {
                $totPrice = (int) ($pgResponse['TotPrice'] ?? $pgResponse['totPrice'] ?? 0);
            }

            $expectedAmount = $this->resolveExpectedPaymentPriceOrNull($order, 'auth_callback', [
                'tid' => $tid,
                'received_amount' => $totPrice,
            ]);
            if ($expectedAmount === null) {
                $this->apiService->sendNetCancel($netCancelUrl, $authToken);

                return redirect($this->resolveFailUrl(['error' => 'invalid_payment_currency', 'orderId' => $moid]));
            }
            if ($expectedAmount > 0 && $totPrice !== $expectedAmount) {
                $this->apiService->sendNetCancel($netCancelUrl, $authToken);

                return redirect($this->resolveFailUrl(['error' => 'amount_mismatch', 'orderId' => $moid]));
            }

            $embeddedPgProvider = $this->resolveEmbeddedPgProvider($selectedEasyPayMethod);

            Log::info('KG Inicis: completing card payment', [
                'moid' => $moid,
                'tid' => $tid,
                'pg_pay_method' => $pgResponse['payMethod'] ?? null,
                'easy_pay' => $this->buildEasyPayLogContext($selectedEasyPayMethod),
            ]);

            $this->orderService->completePayment($order, [
                'transaction_id' => $tid,
                'card_approval_number' => $pgResponse['applNum'] ?? null,
                'card_number_masked' => $pgResponse['cardNum'] ?? null,
                'card_name' => $pgResponse['cardName'] ?? null,
                'card_installment_months' => (int) ($pgResponse['cardQuota'] ?? 0),
                'is_interest_free' => false,
                'embedded_pg_provider' => $embeddedPgProvider,
                'receipt_url' => null,
                'payment_meta' => array_merge([
                    'result_code' => $pgResultCode,
                    'pay_method' => $pgResponse['payMethod'] ?? null,
                    'auth_date' => $pgResponse['applDate'] ?? null,
                    'mid' => $this->apiService->getMid(),
                    'is_test_mode' => $this->apiService->isTestMode(),
                    'pg_response_sanitized' => true,
                    'pg_raw_response' => $this->sanitizePgResponse($pgResponse, self::PC_AUTH_RESPONSE_KEYS),
                ], $this->buildEasyPayPaymentMeta($selectedEasyPayMethod)),
                'payment_device' => DeviceDetector::detect($request),
            ], $totPrice);

            $order->payment()->update(['pg_provider' => 'kginicis']);
            $this->queueReceiptCookie($moid);

            return redirect($this->resolveSuccessUrl($moid));

        } catch (PaymentAmountMismatchException $e) {
            Log::error('KG Inicis: amount mismatch', [
                'moid' => $moid,
                'expected' => $e->getExpectedAmount(),
                'actual' => $e->getActualAmount(),
            ]);

            $this->apiService->sendNetCancel($netCancelUrl, $authToken);

            return redirect($this->resolveFailUrl(['error' => 'amount_mismatch', 'orderId' => $moid]));

        } catch (\Exception $e) {
            Log::error('KG Inicis: authorize exception', [
                'moid' => $moid,
                'tid' => $tid,
                'error' => $e->getMessage(),
            ]);

            if ($this->wasAlreadyPaid($tid)) {
                Log::warning('KG Inicis: local payment already completed, net cancel skipped after exception', [
                    'moid' => $moid,
                    'tid' => $tid,
                    'error' => $e->getMessage(),
                ]);

                $this->queueReceiptCookie($moid);

                return redirect($this->resolveSuccessUrl($moid));
            }

            $this->apiService->sendNetCancel($netCancelUrl, $authToken);

            return redirect($this->resolveFailUrl([
                'error' => 'authorize_failed',
                'message' => $e->getMessage(),
                'orderId' => $moid,
            ]));
        } finally {
            $this->releaseOrderCallbackLock($callbackLock);
        }
    }

    /**
     * PC 가상계좌 입금통보를 받아 입금대기 주문을 결제완료로 전환합니다.
     *
     * @param  VbankNotifyRequest  $request  가상계좌 입금통보 요청
     * @return Response KG 이니시스에 반환할 통보 처리 결과 응답
     */
    public function vbankNotify(VbankNotifyRequest $request): Response
    {
        $validated = $request->validated();

        $tid = (string) $validated['no_tid'];
        $moid = (string) $validated['no_oid'];
        $amt = (int) $validated['amt_input'];

        Log::info('KG Inicis: PC vbank deposit notify received', [
            'tid' => $tid,
            'moid' => $moid,
            'amt' => $amt,
            'bank' => $validated['nm_inputbank'] ?? null,
        ]);

        $callbackLock = null;

        try {
            $order = $this->orderService->findByOrderNumber($moid);

            if (! $order) {
                Log::error('KG Inicis: PC vbank notify - order not found', ['moid' => $moid, 'tid' => $tid]);

                return response('FAIL', 200)->header('Content-Type', 'text/plain');
            }

            $callbackLock = $this->acquireOrderCallbackLock('vbankNotify', $moid);

            if ($this->wasAlreadyPaid($tid)) {
                $this->logReplayDetected($tid, $moid, 'PC vbankNotify');

                return response('OK', 200)->header('Content-Type', 'text/plain');
            }

            if (! $this->validateVbankNotifyContext($order, [
                'tid' => $tid,
                'mid' => (string) ($validated['id_merchant'] ?? ''),
                'amount' => $amt,
                'account' => (string) ($validated['no_vacct'] ?? ''),
                'source' => 'PC vbankNotify',
            ])) {
                return response('FAIL', 200)->header('Content-Type', 'text/plain');
            }

            $this->orderService->completePayment($order, [
                'transaction_id' => $tid,
                'payment_meta' => [
                    'vbank_num' => $validated['no_vacct'] ?? null,
                    'vbank_name' => $validated['nm_inputbank'] ?? null,
                    'depositor_name' => $validated['nm_input'] ?? null,
                    'deposit_date' => ($validated['dt_trans'] ?? '').($validated['tm_trans'] ?? ''),
                    'bank_code' => $validated['cd_bank'] ?? null,
                    'mid' => $this->apiService->getMid(),
                    'is_test_mode' => $this->apiService->isTestMode(),
                    'pg_response_sanitized' => true,
                    'pg_raw_response' => $this->sanitizePgResponse($validated, self::PC_VBANK_NOTIFY_RESPONSE_KEYS),
                ],
            ], $amt);

            $order->payment()->update(['pg_provider' => 'kginicis']);

            Log::info('KG Inicis: PC vbank deposit confirmed', ['tid' => $tid, 'moid' => $moid, 'amt' => $amt]);

            return response('OK', 200)->header('Content-Type', 'text/plain');

        } catch (\Exception $e) {
            Log::error('KG Inicis: PC vbank notify failed', [
                'tid' => $tid,
                'moid' => $moid,
                'error' => $e->getMessage(),
            ]);

            return response('FAIL', 200)->header('Content-Type', 'text/plain');
        } finally {
            $this->releaseOrderCallbackLock($callbackLock);
        }
    }

    /**
     * 모바일 가상계좌 입금통보를 받아 입금대기 주문을 결제완료로 전환합니다.
     *
     * @param  MobileVbankNotifyRequest  $request  모바일 가상계좌 입금통보 요청
     * @return Response KG 이니시스에 반환할 통보 처리 결과 응답
     */
    public function mobileVbankNotify(MobileVbankNotifyRequest $request): Response
    {
        $validated = $request->validated();

        $pStatus = (string) $validated['P_STATUS'];
        $pType = (string) $validated['P_TYPE'];
        $tid = (string) $validated['P_TID'];
        $moid = (string) $validated['P_OID'];
        $amt = (int) $validated['P_AMT'];

        // P_STATUS == "02" (입금통보) + P_TYPE == "VBANK" 만 처리
        if ($pStatus !== '02' || $pType !== 'VBANK') {
            Log::info('KG Inicis: mobile vbank notify - not a deposit, ignored', [
                'tid' => $tid,
                'P_STATUS' => $pStatus,
                'P_TYPE' => $pType,
            ]);

            return response('OK', 200)->header('Content-Type', 'text/plain');
        }

        Log::info('KG Inicis: mobile vbank deposit notify received', [
            'tid' => $tid,
            'moid' => $moid,
            'amt' => $amt,
            'bank' => $validated['P_FN_NM'] ?? null,
        ]);

        $callbackLock = null;

        try {
            $order = $this->orderService->findByOrderNumber($moid);

            if (! $order) {
                Log::error('KG Inicis: mobile vbank notify - order not found', ['moid' => $moid, 'tid' => $tid]);

                return response('FAIL', 200)->header('Content-Type', 'text/plain');
            }

            $callbackLock = $this->acquireOrderCallbackLock('mobileVbankNotify', $moid);

            if ($this->wasAlreadyPaid($tid)) {
                $this->logReplayDetected($tid, $moid, 'mobile vbankNotify');

                return response('OK', 200)->header('Content-Type', 'text/plain');
            }

            if (! $this->validateVbankNotifyContext($order, [
                'tid' => $tid,
                'mid' => (string) ($validated['P_MID'] ?? ''),
                'amount' => $amt,
                'account' => $this->extractMobileVbankAccount((string) ($validated['P_RMESG1'] ?? '')),
                'source' => 'mobile vbankNotify',
            ])) {
                return response('FAIL', 200)->header('Content-Type', 'text/plain');
            }

            $this->orderService->completePayment($order, [
                'transaction_id' => $tid,
                'payment_meta' => [
                    'vbank_name' => $validated['P_FN_NM'] ?? null,
                    'depositor_name' => $validated['P_UNAME'] ?? null,
                    'deposit_date' => $validated['P_AUTH_DT'] ?? null,
                    'bank_code' => $validated['P_FN_CD1'] ?? null,
                    'mid' => $this->apiService->getMid(),
                    'is_test_mode' => $this->apiService->isTestMode(),
                    'pg_response_sanitized' => true,
                    'pg_raw_response' => $this->sanitizePgResponse($validated, self::MOBILE_VBANK_NOTIFY_RESPONSE_KEYS),
                ],
                'payment_device' => 'mobile',
            ], $amt);

            $order->payment()->update(['pg_provider' => 'kginicis']);

            Log::info('KG Inicis: mobile vbank deposit confirmed', ['tid' => $tid, 'moid' => $moid, 'amt' => $amt]);

            return response('OK', 200)->header('Content-Type', 'text/plain');

        } catch (\Exception $e) {
            Log::error('KG Inicis: mobile vbank notify failed', [
                'tid' => $tid,
                'moid' => $moid,
                'error' => $e->getMessage(),
            ]);

            return response('FAIL', 200)->header('Content-Type', 'text/plain');
        } finally {
            $this->releaseOrderCallbackLock($callbackLock);
        }
    }

    /**
     * 입금통보가 실제로 이 주문에서 발급한 가상계좌 결제 건인지 확인한다.
     *
     * KG 이니시스 PC 노티는 별도 서명 필드를 제공하지 않으므로, IP 화이트리스트에 더해
     * 결제행에 저장된 발급 시점 TID/MID/계좌/금액을 대조해 위조 통보 표면을 줄인다.
     *
     * @param  array{tid:string,mid:string,amount:int,account:string,source:string}  $notify
     */
    private function validateVbankNotifyContext(Order $order, array $notify): bool
    {
        $payment = $order->payment()->first();
        $meta = is_array($payment?->payment_meta) ? $payment->payment_meta : [];
        $source = $notify['source'];

        if (! $payment) {
            Log::warning('KG Inicis: vbank notify rejected - payment missing', [
                'source' => $source,
                'moid' => $order->order_number,
                'tid' => $notify['tid'],
            ]);

            return false;
        }

        $paymentStatus = $this->enumValue($payment->payment_status);
        if ($paymentStatus !== PaymentStatusEnum::WAITING_DEPOSIT->value) {
            Log::warning('KG Inicis: vbank notify rejected - payment is not waiting deposit', [
                'source' => $source,
                'moid' => $order->order_number,
                'tid' => $notify['tid'],
                'payment_status' => $paymentStatus,
            ]);

            return false;
        }

        $paymentMethod = $this->enumValue($payment->payment_method);
        if ($paymentMethod !== PaymentMethodEnum::VBANK->value) {
            Log::warning('KG Inicis: vbank notify rejected - payment method mismatch', [
                'source' => $source,
                'moid' => $order->order_number,
                'tid' => $notify['tid'],
                'payment_method' => $paymentMethod,
            ]);

            return false;
        }

        $expectedTid = trim((string) $payment->transaction_id);
        if ($expectedTid === '' || ! hash_equals($expectedTid, $notify['tid'])) {
            Log::warning('KG Inicis: vbank notify rejected - tid mismatch', [
                'source' => $source,
                'moid' => $order->order_number,
                'received_tid' => $notify['tid'],
                'expected_tid' => $expectedTid,
            ]);

            return false;
        }

        $expectedMid = trim((string) ($meta['mid'] ?? $this->apiService->getMid()));
        if ($expectedMid !== '' && ! hash_equals($expectedMid, $notify['mid'])) {
            Log::warning('KG Inicis: vbank notify rejected - mid mismatch', [
                'source' => $source,
                'moid' => $order->order_number,
                'tid' => $notify['tid'],
                'received_mid' => $notify['mid'],
                'expected_mid' => $expectedMid,
            ]);

            return false;
        }

        // 결제 청구액 SSoT = 결제 통화(order_currency) 환산액 (buildPgPaymentData 와 동일 기준).
        // base≠결제 통화에서 PG 통보 금액(환산 통화)과 단위가 일치하도록 한다.
        $expectedAmount = $this->resolveExpectedPaymentPriceOrNull($order, 'vbank_notify', [
            'source' => $source,
            'tid' => $notify['tid'],
            'received_amount' => $notify['amount'],
        ]);
        if ($expectedAmount === null) {
            return false;
        }

        if ($expectedAmount > 0 && $notify['amount'] !== $expectedAmount) {
            Log::warning('KG Inicis: vbank notify rejected - amount mismatch', [
                'source' => $source,
                'moid' => $order->order_number,
                'tid' => $notify['tid'],
                'received_amount' => $notify['amount'],
                'expected_amount' => $expectedAmount,
            ]);

            return false;
        }

        $expectedAccount = preg_replace('/\D+/', '', (string) $payment->vbank_number);
        $receivedAccount = preg_replace('/\D+/', '', $notify['account']);
        if ($expectedAccount !== '' && $receivedAccount !== '' && ! hash_equals($expectedAccount, $receivedAccount)) {
            Log::warning('KG Inicis: vbank notify rejected - account mismatch', [
                'source' => $source,
                'moid' => $order->order_number,
                'tid' => $notify['tid'],
                'received_account_suffix' => substr($receivedAccount, -4),
                'expected_account_suffix' => substr($expectedAccount, -4),
            ]);

            return false;
        }

        return true;
    }

    private function extractMobileVbankAccount(string $message): string
    {
        $parts = explode('|', $message);

        return trim((string) ($parts[0] ?? ''));
    }

    private function enumValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }

    private function resolveSuccessUrl(string $orderId): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $urlTemplate = $settings['redirect_success_url'] ?? '/shop/orders/{orderId}/complete';

        return $this->absolutize(str_replace('{orderId}', $orderId, $urlTemplate));
    }

    private function resolveFailUrl(array $queryParams = []): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $baseUrl = $this->absolutize($settings['redirect_fail_url'] ?? '/shop/checkout');

        if (empty($queryParams)) {
            return $baseUrl;
        }

        $query = http_build_query(array_filter($queryParams));
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl.$separator.$query;
    }

    /**
     * 상대 경로면 APP_URL 기준으로 절대 URL 화.
     *
     * PG가 브라우저 POST 로 콜백을 보내는 동안 Apache 가 ProxyPreserveHost Off 등
     * 으로 Host 헤더를 localhost 로 바꿔서 PHP 에 전달하는 경우, Laravel 의
     * redirect('/path') 가 http://localhost/path 를 생성해버린다. config('app.url')
     * (.env 의 APP_URL)을 명시적 base 로 사용하여 도메인을 보존한다.
     */
    private function absolutize(string $url): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $base = rtrim((string) config('app.url'), '/');
        $path = $url === '' ? '/' : ($url[0] === '/' ? $url : '/'.$url);

        return $base.$path;
    }

    /**
     * 가상계좌 발급 처리 (completePayment 없이 계좌 정보만 저장)
     *
     * KG 이니시스 authCallback 에서 payMethod=VBank 로 판별된 경우 호출.
     * 실제 결제 완료(completePayment)는 입금 통보(vbankNotify) 시점에 처리.
     */
    private function handleVbankIssued(Order $order, array $pgResponse, string $tid, Request $request): void
    {
        $vactDate = $pgResponse['VACT_Date'] ?? null;
        $vactTime = $pgResponse['VACT_Time'] ?? '235959';
        $vbankDueAt = null;

        if ($vactDate && strlen($vactDate) === 8) {
            try {
                $vbankDueAt = Carbon::createFromFormat('YmdHis', $vactDate.$vactTime);
            } catch (\Exception) {
                $vbankDueAt = null;
            }
        }

        $order->payment()->update(array_filter([
            'pg_provider' => 'kginicis',
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'transaction_id' => $tid ?: null,
            'vbank_code' => $pgResponse['VACT_BankCode'] ?? null,  // KG 이니시스 은행코드 (e.g. 89=케이뱅크)
            'vbank_name' => $pgResponse['vactBankName'] ?? null,  // 입금은행명
            'vbank_number' => $pgResponse['VACT_Num'] ?? null,       // 가상계좌번호
            'vbank_holder' => $pgResponse['VACT_Name'] ?? null,      // 예금주명
            'vbank_due_at' => $vbankDueAt,
            'vbank_issued_at' => now(),
            'payment_device' => DeviceDetector::detect($request),
            'payment_meta' => [
                'result_code' => '0000',
                'pay_method' => 'VBank',
                'auth_date' => $pgResponse['applDate'] ?? null,
                'mid' => $this->apiService->getMid(),
                'is_test_mode' => $this->apiService->isTestMode(),
                'pg_response_sanitized' => true,
                'pg_raw_response' => $this->sanitizePgResponse($pgResponse, self::PC_VBANK_ISSUE_RESPONSE_KEYS),
            ],
        ], fn ($v) => $v !== null));

        Log::info('KG Inicis: vbank account issued', [
            'moid' => $order->order_number,
            'tid' => $tid,
            'vbank_name' => $pgResponse['vactBankName'] ?? null,
            'vbank_number' => $pgResponse['VACT_Num'] ?? null,
            'vbank_due_at' => $vbankDueAt?->toDateTimeString(),
        ]);
    }
}
