<?php

namespace Plugins\Sirsoft\Sso\Http\Controllers;

use Illuminate\Routing\Controller;
use Plugins\Sirsoft\Sso\Http\Requests\BackchannelLogoutRequest;
use Plugins\Sirsoft\Sso\Services\SessionService;
use Symfony\Component\HttpFoundation\Response;

/**
 * OIDC back-channel 로그아웃 수신 — IdP 서버가 직접 부른다(브라우저 아님).
 *
 * `logout_token` 의 서명, 발급자, 대상, 이벤트, 1회 사용 검증은 OIDC 드라이버가 하고, 고른 세션의 토큰 폐기는 세션 서비스가 한다.
 *
 * @since 1.0.0
 */
class BackchannelController extends Controller
{
    /**
     * @param  SessionService  $sessions  세션 서비스
     */
    public function __construct(private readonly SessionService $sessions) {}

    /**
     * back-channel 로그아웃을 처리합니다.
     *
     * @param  BackchannelLogoutRequest  $request  IdP 요청 (`logout_token` 폼 값)
     * @param  string  $idp  IdP 키
     * @return Response 200 또는 400
     */
    public function logout(BackchannelLogoutRequest $request, string $idp): Response
    {
        return $this->sessions->backchannel($idp, $request);
    }
}
