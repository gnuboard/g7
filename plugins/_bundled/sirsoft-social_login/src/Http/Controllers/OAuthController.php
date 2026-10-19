<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Controllers;

use App\Support\SiteUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Http\Requests\OAuthCallbackRequest;
use Plugins\Sirsoft\SocialLogin\Http\Requests\OAuthStartRequest;
use Plugins\Sirsoft\SocialLogin\Services\OAuthFlowService;
use Plugins\Sirsoft\SocialLogin\Services\StateStore;
use Plugins\Sirsoft\SocialLogin\Support\HtmlPage;

/**
 * OAuth 웹 엔드포인트 — 로그인 시작, 제공자 콜백, 관리자 연결 테스트 결과 화면.
 *
 * 브라우저가 직접 오가는 주소라 JSON 이 아니라 이동(302)과 단독 HTML 로 답한다.
 *
 * @since 1.0.0
 */
class OAuthController extends Controller
{
    /**
     * @param  OAuthFlowService  $flow  왕복 서비스
     */
    public function __construct(private readonly OAuthFlowService $flow) {}

    /**
     * 로그인 왕복을 시작합니다.
     *
     * 이미 로그인한 세션이면 제공자로 보내지 않고 복귀 주소로 보낸다.
     *
     * @param  OAuthStartRequest  $request  요청 (redirect 쿼리)
     * @param  string  $provider  제공자 키
     * @return RedirectResponse 제공자 인가 화면 또는 로그인 화면(오류)
     */
    public function start(OAuthStartRequest $request, string $provider): RedirectResponse
    {
        $redirect = $request->redirectPath();

        if (auth('sanctum')->check()) {
            return redirect()->to(SiteUrl::absolute(StateStore::safeRedirect($redirect)));
        }

        try {
            return redirect()->away($this->flow->start($provider, AttemptIntent::Login, $redirect));
        } catch (ProviderException $e) {
            return redirect()->to(OAuthFlowService::loginErrorUrl($e->errorCode, $redirect));
        }
    }

    /**
     * 제공자 콜백을 처리합니다 (GET·POST).
     *
     * 값 길이가 비정상이면 제공자와 주고받지 않고 state 실패로 처리한다.
     *
     * @param  OAuthCallbackRequest  $request  콜백 요청
     * @param  string  $provider  제공자 키
     * @return RedirectResponse 다음 화면
     */
    public function callback(OAuthCallbackRequest $request, string $provider): RedirectResponse
    {
        if (! $request->isWellFormed()) {
            return redirect()->to(OAuthFlowService::loginErrorUrl('state_invalid'));
        }

        return redirect()->to($this->flow->callback($provider, $request));
    }

    /**
     * 관리자 연결 테스트 결과를 한 번 보여 줍니다.
     *
     * @param  string  $code  결과 코드
     * @return Response 결과 화면
     */
    public function testResult(string $code): Response
    {
        $result = $this->flow->pullTestResult($code);
        $title = __('sirsoft-social_login::messages.test_result.title');

        if ($result === null) {
            return HtmlPage::render($title, '<p class="bad">'.e(__('sirsoft-social_login::messages.test_result.expired')).'</p>', 404);
        }

        $statusHtml = $result['success']
            ? '<p class="ok">'.e(__('sirsoft-social_login::messages.test_result.success')).'</p>'
            : '<p class="bad">'.e(__('sirsoft-social_login::messages.test_result.failure', ['code' => (string) $result['error_code']])).'</p>';

        $verified = match ($result['email_verified']) {
            true => __('sirsoft-social_login::messages.test_result.verified_yes'),
            false => __('sirsoft-social_login::messages.test_result.verified_no'),
            default => __('sirsoft-social_login::messages.test_result.verified_unknown'),
        };

        $rows = [__('sirsoft-social_login::messages.test_result.provider') => $result['provider_key']];
        if ($result['success']) {
            $rows += [
                __('sirsoft-social_login::messages.test_result.provider_user_id') => $result['provider_user_id'],
                __('sirsoft-social_login::messages.test_result.email') => $result['email_masked'],
                __('sirsoft-social_login::messages.test_result.email_verified') => $verified,
                __('sirsoft-social_login::messages.test_result.name') => $result['name'],
                __('sirsoft-social_login::messages.test_result.nickname') => $result['nickname'],
                __('sirsoft-social_login::messages.test_result.received_fields') => implode(', ', $result['received_fields'] ?? []),
                __('sirsoft-social_login::messages.test_result.missing_required') => ($result['missing_required'] ?? []) === []
                    ? __('sirsoft-social_login::messages.test_result.missing_none')
                    : implode(', ', $result['missing_required']),
                __('sirsoft-social_login::messages.test_result.raw_keys') => implode(', ', $result['raw_keys'] ?? []),
            ];
        }
        $rows[__('sirsoft-social_login::messages.test_result.tested_at')] = $result['tested_at'];

        $body = $statusHtml.HtmlPage::definitionList($rows)
            .'<p class="note">'.e(__('sirsoft-social_login::messages.test_result.note')).'</p>';

        return HtmlPage::render($title, $body);
    }
}
