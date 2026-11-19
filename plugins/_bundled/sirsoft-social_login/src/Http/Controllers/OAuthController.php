<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Controllers;

use App\Helpers\TimezoneHelper;
use App\Support\SiteUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Http\Requests\OAuthCallbackRequest;
use Plugins\Sirsoft\SocialLogin\Http\Requests\OAuthStartRequest;
use Plugins\Sirsoft\SocialLogin\Services\OAuthFlowService;
use Plugins\Sirsoft\SocialLogin\Services\SocialReauthService;
use Plugins\Sirsoft\SocialLogin\Services\StateStore;
use Plugins\Sirsoft\SocialLogin\Support\HtmlPage;

/**
 * OAuth 웹 엔드포인트 — 로그인 시작, 「본인 확인 계속하기」 재확인 시작, 제공자 콜백, 관리자 연결 테스트 결과 화면.
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
     * 이미 로그인한 세션이면 제공자로 보내지 않고 복귀 주소로 보낸다. `reconsent=1` 이면 제공자에 동의 화면을 다시 띄워 달라고
     * 요청한다(이메일 제공을 거절해 가입이 막힌 회원이 로그인 화면의 재동의 단추로 들어온다).
     *
     * @param  OAuthStartRequest  $request  요청 (redirect·reconsent 쿼리)
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
            return redirect()->away($this->flow->start($provider, AttemptIntent::Login, $redirect, reconsent: $request->wantsReconsent()));
        } catch (ProviderException $e) {
            return redirect()->to(OAuthFlowService::loginErrorUrl($e->errorCode, $redirect));
        }
    }

    /**
     * 「본인 확인 계속하기」 시작 주소를 엽니다 — 1회용 티켓이면 연결한 제공자의 재확인 인가 화면으로 보냅니다.
     *
     * 티켓은 코어 재확인 요청 응답의 이동 주소로만 나간다(`SocialReauthService::prepareContinue()`). 없거나 이미 쓴 티켓이면
     * 정보 수정 화면으로 돌려보내 실패 사유를 보인다.
     *
     * @param  SocialReauthService  $reauth  소셜 재확인 서비스
     * @param  string  $ticket  1회용 티켓
     * @return RedirectResponse 제공자 인가 화면 또는 정보 수정 화면(오류)
     */
    public function reauthContinue(SocialReauthService $reauth, string $ticket): RedirectResponse
    {
        $url = $reauth->pullContinue($ticket);

        return $url !== null ? redirect()->away($url) : redirect()->to(SocialReauthService::continueFailedUrl());
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
        $rows[__('sirsoft-social_login::messages.test_result.tested_at')] = isset($result['tested_at']) ? (TimezoneHelper::toUserDateTimeString(Carbon::parse($result['tested_at'])) ?? '') : '';

        $body = $statusHtml.HtmlPage::definitionList($rows)
            .'<p class="note">'.e(__('sirsoft-social_login::messages.test_result.note')).'</p>';

        return HtmlPage::render($title, $body);
    }
}
