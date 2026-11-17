<?php

namespace Plugins\Sirsoft\Sso\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Plugins\Sirsoft\Sso\Http\Requests\LoginStartRequest;
use Plugins\Sirsoft\Sso\Services\LoginFlowService;
use Plugins\Sirsoft\Sso\Support\BridgeScript;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * SSO 웹 엔드포인트 — 로그인 왕복 시작과 관리자 「브라우저로 로그인 테스트」 결과 화면.
 *
 * 브라우저가 직접 오가는 주소라 JSON 이 아니라 이동(302)과 단독 HTML 로 답한다.
 *
 * @since 1.0.0
 */
class LoginController extends Controller
{
    /**
     * @param  LoginFlowService  $flow  왕복 서비스
     */
    public function __construct(private readonly LoginFlowService $flow) {}

    /**
     * 왕복을 시작합니다.
     *
     * @param  LoginStartRequest  $request  요청 (intent, guard, redirect 또는 ticket)
     * @param  string  $idp  IdP 키
     * @return SymfonyResponse IdP 로 가는 응답 또는 로그인 화면(오류)
     */
    public function start(LoginStartRequest $request, string $idp): SymfonyResponse
    {
        return $this->flow->start($idp, $request);
    }

    /**
     * 관리자 테스트 결과를 한 번 보여 줍니다 (값은 가려져 있다, 60초 안에 한 번).
     *
     * @param  string  $code  결과 코드
     * @return Response 결과 화면
     */
    public function testResult(string $code): Response
    {
        $result = $this->flow->pullTestResult($code);
        $title = e(__('sirsoft-sso::messages.test_result.title'));

        if ($result === null) {
            return $this->page($title, '<p class="bad">'.e(__('sirsoft-sso::messages.test_result.expired')).'</p>', 404);
        }

        $status = $result['success']
            ? '<p class="ok">'.e(__('sirsoft-sso::messages.test_result.success')).'</p>'
            : '<p class="bad">'.e(__('sirsoft-sso::messages.test_result.failure', [
                'code' => (string) $result['error_code'],
                'message' => __('sirsoft-sso::messages.error.'.$result['error_code']),
            ])).'</p>';

        $verified = match ($result['email_verified']) {
            true => __('sirsoft-sso::messages.test_result.yes'),
            false => __('sirsoft-sso::messages.test_result.no'),
            default => __('sirsoft-sso::messages.test_result.unknown'),
        };
        $mfa = match ($result['mfa']) {
            true => __('sirsoft-sso::messages.test_result.yes'),
            false => __('sirsoft-sso::messages.test_result.no'),
            default => __('sirsoft-sso::messages.test_result.unknown'),
        };

        $rows = [__('sirsoft-sso::messages.test_result.idp') => (string) ($result['idp_label'] ?? $result['idp_key'] ?? '-')];
        if ($result['success']) {
            $rows += [
                __('sirsoft-sso::messages.test_result.subject') => (string) $result['subject'],
                __('sirsoft-sso::messages.test_result.email') => (string) ($result['email'] ?? '-'),
                __('sirsoft-sso::messages.test_result.email_verified') => (string) $verified,
                __('sirsoft-sso::messages.test_result.display_name') => (string) ($result['display_name'] ?? '-'),
                __('sirsoft-sso::messages.test_result.groups') => implode(', ', $result['groups'] ?? []) ?: '-',
                __('sirsoft-sso::messages.test_result.attributes') => implode(', ', $result['attribute_keys'] ?? []) ?: '-',
                __('sirsoft-sso::messages.test_result.mfa') => (string) $mfa,
            ];
        }
        $rows[__('sirsoft-sso::messages.test_result.tested_at')] = (string) $result['tested_at'];

        $list = '<dl>';
        foreach ($rows as $label => $value) {
            $list .= '<dt>'.e((string) $label).'</dt><dd>'.e($value).'</dd>';
        }
        $list .= '</dl>';

        return $this->page($title, $status.$list.'<p class="note">'.e(__('sirsoft-sso::messages.test_result.note')).'</p>');
    }

    /**
     * 단독 HTML 화면을 만듭니다 (캐시 금지, 밝기 모드 대응).
     *
     * @param  string  $title  이스케이프한 제목
     * @param  string  $body  이스케이프한 본문 HTML
     * @param  int  $status  상태 코드
     * @return Response 화면
     */
    private function page(string $title, string $body, int $status = 200): Response
    {
        // file-serving:allow 파일이 아니라 이 자리에서 만든 결과 화면(HTML)이다 — 본문은 전부 이스케이프한 값이고 `no-store` 로 남기지 않는다.
        $html = '<!doctype html><html lang="'.e(app()->getLocale()).'"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.'</title>'
            // 모양은 정적 파일이 준다 — 인라인 <style> 은 스타일 보안 정책(style-src 'self')에서 막힌다(#163)
            .BridgeScript::stylesheetTag('test-result.css').'</head>'
            .'<body><h1>'.$title.'</h1>'.$body.'</body></html>';

        return response($html, $status, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
