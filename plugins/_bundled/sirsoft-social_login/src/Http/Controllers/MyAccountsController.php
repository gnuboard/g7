<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\SocialLogin\Exceptions\SocialFlowException;
use Plugins\Sirsoft\SocialLogin\Http\Controllers\Concerns\RespondsWithFlowErrors;
use Plugins\Sirsoft\SocialLogin\Http\Requests\ReauthClaimRequest;
use Plugins\Sirsoft\SocialLogin\Http\Requests\ReauthStartRequest;
use Plugins\Sirsoft\SocialLogin\Services\AccountLinkService;
use Plugins\Sirsoft\SocialLogin\Services\SocialReauthService;

/**
 * 마이페이지 소셜 계정 연결 관리 API (로그인 회원 본인).
 *
 * - GET    /api/plugins/sirsoft-social_login/me/accounts
 * - POST   /api/plugins/sirsoft-social_login/me/accounts/{provider}/link-start
 * - DELETE /api/plugins/sirsoft-social_login/me/accounts/{provider}
 * - POST   /api/plugins/sirsoft-social_login/me/reauth/{provider}/start — 대체 이메일 회원의 소셜 재확인 시작
 * - POST   /api/plugins/sirsoft-social_login/me/reauth/claim — 재확인 결과(확인 토큰) 수령
 *
 * @since 1.0.0
 */
class MyAccountsController extends AuthBaseController
{
    use RespondsWithFlowErrors;

    /**
     * @param  AccountLinkService  $links  연결 관리
     * @param  SocialReauthService  $reauth  소셜 재확인
     */
    public function __construct(
        private readonly AccountLinkService $links,
        private readonly SocialReauthService $reauth,
    ) {
        parent::__construct();
    }

    /**
     * 연결 목록을 반환합니다.
     *
     * @return JsonResponse 연결·연결 가능 제공자·해제 가능 여부·재확인 대상 여부
     */
    public function index(): JsonResponse
    {
        $user = $this->getCurrentUser();
        $data = $this->links->summaryFor($user);
        // 소셜 안내·제공자 버튼 조건 — 코어가 이 회원의 재확인에 실제로 소셜을 고를 때만 참(다른 수단이 먼저면 거짓)
        $data['reauth_via_social'] = $this->reauth->isChosenReauth($user);

        return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.accounts.listed', $data);
    }

    /**
     * 연결 왕복을 시작합니다.
     *
     * @param  string  $provider  제공자 키
     * @return JsonResponse 제공자 인가 주소 (`authorize_url`)
     */
    public function linkStart(string $provider): JsonResponse
    {
        try {
            $url = $this->links->startLink($this->getCurrentUser(), $provider);

            return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.accounts.link_started', ['authorize_url' => $url]);
        } catch (SocialFlowException $e) {
            return $this->flowError($e);
        }
    }

    /**
     * 연결을 끊습니다.
     *
     * @param  string  $provider  제공자 키
     * @return JsonResponse 결과 (마지막 수단이면 409 `last_method`)
     */
    public function destroy(string $provider): JsonResponse
    {
        try {
            $this->links->unlink($this->getCurrentUser(), $provider);

            return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.accounts.unlinked', ['provider_key' => $provider]);
        } catch (SocialFlowException $e) {
            return $this->flowError($e);
        }
    }

    /**
     * 소셜 재확인 왕복을 시작합니다.
     *
     * @param  ReauthStartRequest  $request  요청 (return)
     * @param  string  $provider  제공자 키
     * @return JsonResponse 제공자 인가 주소 (`authorize_url`)
     */
    public function reauthStart(ReauthStartRequest $request, string $provider): JsonResponse
    {
        try {
            $url = $this->reauth->start($this->getCurrentUser(), $provider, $request->validated('return'));

            return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.reauth.started', ['authorize_url' => $url]);
        } catch (SocialFlowException $e) {
            return $this->flowError($e);
        }
    }

    /**
     * 소셜 재확인 결과를 받습니다.
     *
     * @param  ReauthClaimRequest  $request  요청 (code)
     * @return JsonResponse 확인 토큰 (`verification_token`)
     */
    public function reauthClaim(ReauthClaimRequest $request): JsonResponse
    {
        try {
            $token = $this->reauth->claim($this->getCurrentUser(), (string) $request->validated('code'));

            return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.reauth.verified', ['verification_token' => $token]);
        } catch (SocialFlowException $e) {
            return $this->flowError($e);
        }
    }
}
