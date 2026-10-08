<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

// audit:allow api-doc-coverage 요청 파라미터·응답 구조 무변경 — 테이블명 리터럴을 모델 파생으로 정리한 내부 리팩토링 (#571)

use App\Http\Controllers\Controller;
use App\Support\SiteUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Support\EscrowConfirmNonce;

/**
 * KG 이니시스 에스크로 구매결정 컨트롤러 (사용자용)
 *
 * PC: INIStdPay_escrow_conf.js 팝업 → POST returnUrl
 * 모바일: mobile.inicis.com/smart/payment/ 자동 submit → POST P_NEXT_URL
 *
 * 결과 수신 주소에는 확인창을 연 본인 요청에서 발급한 일회용 증표가 경로로 실린다 — 증표가 없거나
 * 맞지 않는 수신은 기록하지 않는다(공개 주소라 tid 만으로 기록하면 제3자가 남의 결제에 기록을 남긴다).
 */
class UserEscrowConfirmController extends Controller
{
    private const PC_JS_URL_TEST = 'https://stgstdpay.inicis.com/stdjs/INIStdPay_escrow_conf.js';

    private const PC_JS_URL_LIVE = 'https://stdpay.inicis.com/stdjs/INIStdPay_escrow_conf.js';

    private const MOBILE_PAY_URL = 'https://mobile.inicis.com/smart/payment/';

    private const ROUTE_BASE = '/plugins/sirsoft-pay_kginicis/payment/escrow-confirm';

    /**
     * @param  KgInicisApiService  $apiService  KG 이니시스 API 서비스
     * @param  EscrowConfirmNonce  $nonces  확인창 ↔ 결과 수신 결속 증표
     */
    public function __construct(
        private readonly KgInicisApiService $apiService,
        private readonly EscrowConfirmNonce $nonces,
    ) {}

    /**
     * 로그인한 본인 주문의 에스크로 구매결정 확인창을 엽니다.
     *
     * 본인 확인은 웹 세션 로그인, 또는 영수증 조회 API 가 토큰 로그인 회원에게 준 한시 서명 주소(uid·만료 서명)로 한다.
     * 둘 다 없거나 서명이 틀리면 401 안내를 준다 — 이 라우트에 `auth` 미들웨어를 걸면 로그인 라우트가 없어 500 이 된다.
     *
     * @param  Request  $request  현재 요청
     * @param  string  $orderNumber  주문번호
     * @return Response 확인창 HTML (PC 팝업 / 모바일 자동 제출) 또는 401 안내
     */
    // audit:allow controller-base-request-injection reason: 본문 입력을 읽지 않음 — 에스크로 확인 HTML 화면 렌더 (검증 대상 필드 없음)
    public function show(Request $request, string $orderNumber): Response
    {
        // 본인 확인: 웹 세션 로그인, 또는 영수증 조회 API 가 토큰 로그인 회원에게 준 한시 서명 주소(uid) (S4-D19)
        $userId = Auth::check() ? (int) Auth::id() : $this->signedUserId($request);

        if ($userId === null) {
            return $this->htmlResponse(
                '<!DOCTYPE html><html lang="'.e(app()->getLocale()).'"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>'
                .'<body><p>'.e(__('sirsoft-pay_kginicis::messages.escrow.confirm_login_required')).'</p></body></html>',
                401,
            );
        }

        $payment = $this->findEscrowPayment($orderNumber, $userId);

        if (! $payment) {
            abort(404);
        }

        $this->apiService->useEscrowCredentials(true);

        $nonce = $this->nonces->issue((string) $payment->transaction_id, $orderNumber, $userId);

        if ($this->isMobile($request)) {
            return $this->mobileConfirmPage((string) $payment->transaction_id, $nonce);
        }

        return $this->pcConfirmPage((string) $payment->transaction_id, $nonce);
    }

    /**
     * 한시 서명 주소의 회원 번호를 돌려줍니다 — 서명이 없거나 바뀌었거나 만료됐으면 null.
     *
     * @param  Request  $request  현재 요청
     * @return int|null 서명된 회원 번호
     */
    private function signedUserId(Request $request): ?int
    {
        $uid = $request->query('uid');

        if (! is_string($uid) || ! ctype_digit($uid) || ! $request->hasValidSignature(absolute: false)) {
            return null;
        }

        return (int) $uid;
    }

    /**
     * PC 구매결정 결과를 받습니다 (이니시스 → 사용자 브라우저 POST).
     *
     * @param  Request  $request  PG 가 보낸 결과
     * @param  string|null  $nonce  확인창이 발급한 증표 (증표 없는 종전 주소면 null — 기록하지 않는다)
     * @return Response 팝업 닫기 + 부모창 새로고침 HTML
     */
    // audit:allow controller-base-request-injection reason: PG(이니시스)가 POST 하는 콜백 표면 — 필드 구성이 PG 소유라 계약을 닫을 수 없다. 값은 문자열 캐스팅 후 증표 문맥의 tid 와 대조된 뒤에만 payment_meta 에 영속된다
    public function pcReturn(Request $request, ?string $nonce = null): Response
    {
        $resultCode = (string) $request->input('ResultCode', '');
        $tid = (string) $request->input('tid', '');

        Log::info('KG Inicis: escrow PC confirm return', [
            'result_code' => $resultCode,
            'tid' => $tid,
        ]);

        if ($this->isBoundReturn($nonce, $tid) !== null) {
            $this->saveConfirmResult($tid, [
                'type' => $resultCode === '00' ? 'confirm' : 'deny',
                'result_code' => $resultCode,
                'cnf_date' => (string) $request->input('CNF_Date', ''),
                'cnf_time' => (string) $request->input('CNF_Time', ''),
                'dny_date' => (string) $request->input('DNY_Date', ''),
                'dny_time' => (string) $request->input('DNY_Time', ''),
                'dny_msg' => (string) $request->input('DNY_DenyMsg', ''),
            ]);
        }

        // 팝업 닫기 + 부모 창 새로고침
        return $this->htmlResponse(
            '<script>try{window.opener&&window.opener.location.reload();}catch(e){}window.close();</script>'
        );
    }

    /**
     * 확인창 닫기 페이지 (이니시스 closeUrl).
     *
     * @return Response 창 닫기 HTML
     */
    public function close(): Response
    {
        return $this->htmlResponse('<script>window.close();</script>');
    }

    /**
     * 모바일 구매결정 결과를 받습니다 (이니시스 → 사용자 브라우저 POST).
     *
     * @param  Request  $request  PG 가 보낸 결과
     * @param  string|null  $nonce  확인창이 발급한 증표 (증표 없는 종전 주소면 null — 기록하지 않는다)
     * @return RedirectResponse 주문 상세(또는 주문 목록)로의 이동
     */
    // audit:allow controller-base-request-injection reason: PG(이니시스 모바일)가 POST 하는 콜백 표면 — 필드 구성이 PG 소유라 계약을 닫을 수 없다. 값은 문자열 캐스팅 후 증표 문맥의 tid 와 대조된 뒤에만 payment_meta 에 영속된다
    public function mobileReturn(Request $request, ?string $nonce = null): RedirectResponse
    {
        $status = (string) $request->input('P_STATUS', '');
        $tid = (string) $request->input('P_ESCROW_TID', '');
        $clStatus = (string) $request->input('P_CL_STATUS', '');
        $rmesg = (string) $request->input('P_RMESG1', '');

        Log::info('KG Inicis: escrow mobile confirm return', [
            'status' => $status,
            'tid' => $tid,
            'cl_status' => $clStatus,
        ]);

        $orderNumber = null;
        if ($this->isBoundReturn($nonce, $tid) !== null) {
            $orderNumber = $this->saveConfirmResult($tid, [
                'type' => $status === '00' ? 'confirm' : 'deny',
                'result_code' => $status,
                'cl_status' => $clStatus,
                'result_msg' => $rmesg,
            ]);
        }

        // 보안: 상대 경로를 redirect() 에 넘기면 요청 Host 로 절대화된다 — 사이트 주소로 만든다.
        return redirect(SiteUrl::absolute($orderNumber ? '/mypage/orders/'.$orderNumber : '/mypage/orders'));
    }

    // ──────────────────────────── Private ────────────────────────────

    /**
     * 결과 수신이 확인창을 연 본인 요청과 묶여 있는지 판정합니다.
     *
     * 증표를 한 번만 꺼내므로 재사용·다른 tid·증표 없음은 모두 기록하지 않고 경고만 남긴다.
     *
     * @param  string|null  $nonce  결과 수신 경로의 증표
     * @param  string  $tid  PG 가 보낸 거래번호
     * @return array{tid: string, order_number: string, user_id: int}|null 묶인 문맥 (아니면 null)
     */
    private function isBoundReturn(?string $nonce, string $tid): ?array
    {
        if ($nonce === null || $nonce === '') {
            Log::warning('KG Inicis: escrow confirm return without nonce — not recorded', ['tid' => $tid]);

            return null;
        }

        $context = $this->nonces->consume($nonce);

        if ($context === null || $tid === '' || ! hash_equals($context['tid'], $tid)) {
            Log::warning('KG Inicis: escrow confirm return not bound to the confirm window — not recorded', [
                'tid' => $tid,
                'reason' => $context === null ? 'nonce_missing_or_reused' : 'tid_mismatch',
            ]);

            return null;
        }

        return $context;
    }

    /**
     * PC 구매결정 확인창 HTML 을 만듭니다.
     *
     * @param  string  $tid  에스크로 결제 거래번호
     * @param  string  $nonce  결과 수신 결속 증표
     * @return Response 확인창 HTML
     */
    private function pcConfirmPage(string $tid, string $nonce): Response
    {
        $isTest = $this->apiService->isTestMode();
        $mid = $this->apiService->getMid();
        $mKey = $this->apiService->getEscrowConfirmMKey();
        $timestamp = (string) round(microtime(true) * 1000);
        $jsUrl = $isTest ? self::PC_JS_URL_TEST : self::PC_JS_URL_LIVE;
        // 보안: PG 로 보내는 결과 수신·닫기 주소 — 요청 Host 가 아니라 사이트 주소.
        $returnUrl = e(SiteUrl::absolute(self::ROUTE_BASE.'/pc/return/'.$nonce));
        $closeUrl = e(SiteUrl::absolute(self::ROUTE_BASE.'/close'));
        // 문서 언어·제목·안내는 화면 언어를 따른다(한국어 고정이었음)
        $lang = e(app()->getLocale());
        $title = e(__('sirsoft-pay_kginicis::messages.escrow.confirm_page_title'));
        $preparing = e(__('sirsoft-pay_kginicis::messages.escrow.confirm_preparing'));

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="{$lang}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1.0">
            <title>{$title}</title>
            <script src="{$jsUrl}" charset="UTF-8"></script>
            <script>window.onload = function() { INIStdPay.pay('escrow_confirm_form'); };</script>
            <style>
                body { font-family: sans-serif; display: flex; align-items: center;
                       justify-content: center; height: 100vh; margin: 0; background: #f5f5f5; }
                p { color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <p>{$preparing}</p>
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

    /**
     * 모바일 구매결정 자동 제출 HTML 을 만듭니다.
     *
     * @param  string  $tid  에스크로 결제 거래번호
     * @param  string  $nonce  결과 수신 결속 증표
     * @return Response 자동 제출 HTML
     */
    private function mobileConfirmPage(string $tid, string $nonce): Response
    {
        $mid = $this->apiService->getMid();
        // 보안: PG 로 보내는 결과 수신 주소 — 요청 Host 가 아니라 사이트 주소.
        $nextUrl = e(SiteUrl::absolute(self::ROUTE_BASE.'/mobile/return/'.$nonce));
        $payUrl = self::MOBILE_PAY_URL;
        $lang = e(app()->getLocale());
        $title = e(__('sirsoft-pay_kginicis::messages.escrow.confirm_page_title'));
        $redirecting = e(__('sirsoft-pay_kginicis::messages.escrow.confirm_redirecting'));

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="{$lang}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1.0">
            <title>{$title}</title>
            <style>
                body { font-family: sans-serif; display: flex; align-items: center;
                       justify-content: center; height: 100vh; margin: 0; background: #f5f5f5; }
                p { color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <p>{$redirecting}</p>
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

    /**
     * UTF-8 HTML 응답을 만듭니다.
     *
     * @param  string  $html  본문 HTML
     * @param  int  $status  HTTP 상태 코드
     * @return Response HTML 응답
     */
    private function htmlResponse(string $html, int $status = 200): Response
    {
        return response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * TID로 payment_meta에 구매결정 결과를 저장하고 주문번호를 반환합니다.
     *
     * @param  string  $tid  에스크로 결제 거래번호
     * @param  array<string, string>  $data  저장할 결과 필드
     * @return string|null 주문번호 (결제를 찾지 못하면 null)
     */
    private function saveConfirmResult(string $tid, array $data): ?string
    {
        if ($tid === '') {
            return null;
        }

        // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
        $row = DB::table((new OrderPayment)->getTable().' as p')
            ->join((new Order)->getTable().' as o', 'o.id', '=', 'p.order_id')
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

        // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
        DB::table((new OrderPayment)->getTable())
            ->where('id', $row->id)
            ->update([
                'payment_meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

        Log::info('KG Inicis: escrow confirm saved', [
            'order_number' => $row->order_number,
            'tid' => $tid,
            'type' => $data['type'] ?? '',
        ]);

        return $row->order_number;
    }

    /**
     * 로그인 회원 본인의 에스크로 결제를 찾습니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  int  $userId  회원 ID
     * @return object|null 결제 행(id, transaction_id) — 없으면 null
     */
    private function findEscrowPayment(string $orderNumber, int $userId): ?object
    {
        // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
        return DB::table((new OrderPayment)->getTable().' as p')
            ->join((new Order)->getTable().' as o', 'o.id', '=', 'p.order_id')
            ->where('o.order_number', $orderNumber)
            ->where('o.user_id', $userId)
            ->where('p.pg_provider', 'kginicis')
            ->where('p.is_escrow', true)
            ->whereNotNull('p.transaction_id')
            ->select('p.id', 'p.transaction_id')
            ->first();
    }

    /**
     * 모바일 브라우저 요청인지 판정합니다.
     *
     * @param  Request  $request  현재 요청
     * @return bool 모바일이면 true
     */
    private function isMobile(Request $request): bool
    {
        return (bool) preg_match('/(android|iphone|ipad|ipod|mobile|phone)/i', $request->userAgent() ?? '');
    }
}
