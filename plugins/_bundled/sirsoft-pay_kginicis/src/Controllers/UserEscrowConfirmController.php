<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

/**
 * KG 이니시스 에스크로 구매결정 컨트롤러 (사용자용)
 *
 * PC: INIStdPay_escrow_conf.js 팝업 → POST returnUrl
 * 모바일: mobile.inicis.com/smart/payment/ 자동 submit → POST P_NEXT_URL
 */
class UserEscrowConfirmController extends Controller
{
    private const PC_JS_URL_TEST  = 'https://stgstdpay.inicis.com/stdjs/INIStdPay_escrow_conf.js';
    private const PC_JS_URL_LIVE  = 'https://stdpay.inicis.com/stdjs/INIStdPay_escrow_conf.js';
    private const MOBILE_PAY_URL  = 'https://mobile.inicis.com/smart/payment/';

    public function __construct(
        private readonly KgInicisApiService $apiService,
    ) {}

    /**
     * show
     *
     * @param  Request  $request
     * @param  string  $orderNumber
     * @return Response
     */
    public function show(Request $request, string $orderNumber): Response
    {
        $payment = $this->findEscrowPayment($orderNumber, (int) Auth::id());

        if (! $payment) {
            abort(404);
        }

        $this->apiService->useEscrowCredentials(true);

        if ($this->isMobile($request)) {
            return $this->mobileConfirmPage($payment->transaction_id);
        }

        return $this->pcConfirmPage($payment->transaction_id);
    }

    /**
     * pcReturn
     *
     * @param  Request  $request
     * @return Response
     */
    public function pcReturn(Request $request): Response
    {
        $resultCode = (string) $request->input('ResultCode', '');
        $tid        = (string) $request->input('tid', '');

        Log::info('KG Inicis: escrow PC confirm return', [
            'result_code' => $resultCode,
            'tid'         => $tid,
        ]);

        $this->saveConfirmResult($tid, [
            'type'        => $resultCode === '00' ? 'confirm' : 'deny',
            'result_code' => $resultCode,
            'cnf_date'    => (string) $request->input('CNF_Date', ''),
            'cnf_time'    => (string) $request->input('CNF_Time', ''),
            'dny_date'    => (string) $request->input('DNY_Date', ''),
            'dny_time'    => (string) $request->input('DNY_Time', ''),
            'dny_msg'     => (string) $request->input('DNY_DenyMsg', ''),
        ]);

        // 팝업 닫기 + 부모 창 새로고침
        return $this->htmlResponse(
            '<script>try{window.opener&&window.opener.location.reload();}catch(e){}window.close();</script>'
        );
    }

    /**
     * close
     *
     * @return Response
     */
    public function close(): Response
    {
        return $this->htmlResponse('<script>window.close();</script>');
    }

    /**
     * mobileReturn
     *
     * @param  Request  $request
     * @return RedirectResponse
     */
    public function mobileReturn(Request $request): RedirectResponse
    {
        $status   = (string) $request->input('P_STATUS', '');
        $tid      = (string) $request->input('P_ESCROW_TID', '');
        $clStatus = (string) $request->input('P_CL_STATUS', '');
        $rmesg    = (string) $request->input('P_RMESG1', '');

        Log::info('KG Inicis: escrow mobile confirm return', [
            'status'    => $status,
            'tid'       => $tid,
            'cl_status' => $clStatus,
        ]);

        $orderNumber = $this->saveConfirmResult($tid, [
            'type'        => $status === '00' ? 'confirm' : 'deny',
            'result_code' => $status,
            'cl_status'   => $clStatus,
            'result_msg'  => $rmesg,
        ]);

        return redirect($orderNumber ? '/mypage/orders/' . $orderNumber : '/mypage/orders');
    }

    // ──────────────────────────── Private ────────────────────────────

    private function pcConfirmPage(string $tid): Response
    {
        $isTest    = $this->apiService->isTestMode();
        $mid       = $this->apiService->getMid();
        $mKey      = $this->apiService->getEscrowConfirmMKey();
        $timestamp = (string) round(microtime(true) * 1000);
        $jsUrl     = $isTest ? self::PC_JS_URL_TEST : self::PC_JS_URL_LIVE;
        $returnUrl = url('/plugins/sirsoft-pay_kginicis/payment/escrow-confirm/pc/return');
        $closeUrl  = url('/plugins/sirsoft-pay_kginicis/payment/escrow-confirm/close');

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="ko">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1.0">
            <title>에스크로 구매결정</title>
            <script src="{$jsUrl}" charset="UTF-8"></script>
            <script>window.onload = function() { INIStdPay.pay('escrow_confirm_form'); };</script>
            <style>
                body { font-family: sans-serif; display: flex; align-items: center;
                       justify-content: center; height: 100vh; margin: 0; background: #f5f5f5; }
                p { color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <p>에스크로 구매결정 창을 준비 중입니다...</p>
            <form id="escrow_confirm_form" method="post">
                <input type="hidden" name="version"     value="1.0">
                <input type="hidden" name="mid"         value="{$mid}">
                <input type="hidden" name="tid"         value="{$tid}">
                <input type="hidden" name="timestamp"   value="{$timestamp}">
                <input type="hidden" name="mKey"        value="{$mKey}">
                <input type="hidden" name="currency"    value="WON">
                <input type="hidden" name="returnUrl"   value="{$returnUrl}">
                <input type="hidden" name="closeUrl"    value="{$closeUrl}">
                <input type="hidden" name="acceptmethod" value="">
            </form>
        </body>
        </html>
        HTML;

        return $this->htmlResponse($html);
    }

    private function mobileConfirmPage(string $tid): Response
    {
        $mid     = $this->apiService->getMid();
        $nextUrl = url('/plugins/sirsoft-pay_kginicis/payment/escrow-confirm/mobile/return');
        $payUrl  = self::MOBILE_PAY_URL;

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="ko">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1.0">
            <title>에스크로 구매결정</title>
            <style>
                body { font-family: sans-serif; display: flex; align-items: center;
                       justify-content: center; height: 100vh; margin: 0; background: #f5f5f5; }
                p { color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <p>에스크로 구매결정 페이지로 이동 중입니다...</p>
            <form id="mobile_confirm_form" name="mobileweb" method="post"
                  action="{$payUrl}" accept-charset="euc-kr">
                <input type="hidden" name="P_INI_PAYMENT" value="ESCROWCONFIRM">
                <input type="hidden" name="P_MID"         value="{$mid}">
                <input type="hidden" name="P_ESCROW_TID"  value="{$tid}">
                <input type="hidden" name="P_NEXT_URL"    value="{$nextUrl}">
                <input type="hidden" name="P_RESERVED"    value="">
            </form>
            <script>document.getElementById('mobile_confirm_form').submit();</script>
        </body>
        </html>
        HTML;

        return $this->htmlResponse($html);
    }

    private function htmlResponse(string $html): Response
    {
        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * TID로 payment_meta에 구매결정 결과를 저장하고 주문번호를 반환.
     */
    private function saveConfirmResult(string $tid, array $data): ?string
    {
        if ($tid === '') {
            return null;
        }

        $row = DB::table('ecommerce_order_payments as p')
            ->join('ecommerce_orders as o', 'o.id', '=', 'p.order_id')
            ->where('p.transaction_id', $tid)
            ->where('p.pg_provider', 'kginicis')
            ->where('p.is_escrow', true)
            ->select('p.id', 'p.payment_meta', 'o.order_number')
            ->first();

        if (! $row) {
            Log::warning('KG Inicis: escrow confirm — payment not found', ['tid' => $tid]);
            return null;
        }

        $meta = $row->payment_meta ? json_decode($row->payment_meta, true) : [];
        $meta['escrow_confirm'] = array_merge(
            ['confirmed_at' => now()->toDateTimeString()],
            $data,
        );

        DB::table('ecommerce_order_payments')
            ->where('id', $row->id)
            ->update([
                'payment_meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'updated_at'   => now(),
            ]);

        Log::info('KG Inicis: escrow confirm saved', [
            'order_number' => $row->order_number,
            'tid'          => $tid,
            'type'         => $data['type'] ?? '',
        ]);

        return $row->order_number;
    }

    private function findEscrowPayment(string $orderNumber, int $userId): ?object
    {
        return DB::table('ecommerce_order_payments as p')
            ->join('ecommerce_orders as o', 'o.id', '=', 'p.order_id')
            ->where('o.order_number', $orderNumber)
            ->where('o.user_id', $userId)
            ->where('p.pg_provider', 'kginicis')
            ->where('p.is_escrow', true)
            ->whereNotNull('p.transaction_id')
            ->select('p.id', 'p.transaction_id')
            ->first();
    }

    private function isMobile(Request $request): bool
    {
        return (bool) preg_match('/(android|iphone|ipad|ipod|mobile|phone)/i', $request->userAgent() ?? '');
    }
}
