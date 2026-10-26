<?php

namespace Plugins\Sirsoft\Sso\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Plugins\Sirsoft\Sso\Http\Requests\LogoutReturnRequest;
use Plugins\Sirsoft\Sso\Http\Requests\LogoutStartRequest;
use Plugins\Sirsoft\Sso\Services\SessionService;
use Symfony\Component\HttpFoundation\Response;

/**
 * IdP 로그아웃 이동과 복귀.
 *
 * 코어 로그아웃 응답의 `redirect_url`(1회용 티켓 주소)로 브라우저가 오면 IdP 로그아웃으로 보내고, IdP 에서 돌아오면 로그인 화면으로
 * 보낸다. 주소에 세션 id 를 싣지 않는다 — 티켓만 받는다.
 *
 * @since 1.0.0
 */
class LogoutController extends Controller
{
    /**
     * @param  SessionService  $sessions  세션 서비스
     */
    public function __construct(private readonly SessionService $sessions) {}

    /**
     * 티켓을 받아 IdP 로그아웃으로 보냅니다 (티켓이 없거나 썼으면 로그인 화면).
     *
     * @param  LogoutStartRequest  $request  요청 (`?ticket=`)
     * @param  string  $idp  IdP 키
     * @return Response IdP 로그아웃 302 또는 로그인 화면
     */
    public function start(LogoutStartRequest $request, string $idp): Response
    {
        return $this->sessions->beginIdpLogout($idp, $request);
    }

    /**
     * IdP 로그아웃에서 돌아온 브라우저를 로그인 화면으로 보냅니다.
     *
     * @param  LogoutReturnRequest  $request  요청
     * @return RedirectResponse 로그인 화면
     */
    public function return(LogoutReturnRequest $request): RedirectResponse
    {
        return $this->sessions->logoutReturn($request);
    }
}
