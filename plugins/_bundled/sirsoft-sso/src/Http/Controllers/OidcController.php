<?php

namespace Plugins\Sirsoft\Sso\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Plugins\Sirsoft\Sso\Http\Requests\OidcCallbackRequest;
use Plugins\Sirsoft\Sso\Services\LoginFlowService;

/**
 * OIDC 콜백 — IdP 가 인가 코드를 들고 돌아오는 주소(IdP 콘솔의 Redirect URI).
 *
 * IdP 는 CSRF 토큰을 보내지 않는다. 위조 방어는 1회용 state 와 브라우저 묶음 쿠키 대조가 맡는다.
 *
 * @since 1.0.0
 */
class OidcController extends Controller
{
    /**
     * @param  LoginFlowService  $flow  왕복 서비스
     */
    public function __construct(private readonly LoginFlowService $flow) {}

    /**
     * 콜백을 처리합니다 (GET — response_mode=query, POST 도 받는다).
     *
     * 값 길이가 비정상이면 IdP 와 주고받지 않고 state 실패로 처리한다.
     *
     * @param  OidcCallbackRequest  $request  콜백 요청
     * @param  string  $idp  IdP 키
     * @return RedirectResponse 다음 화면
     */
    public function callback(OidcCallbackRequest $request, string $idp): RedirectResponse
    {
        if (! $request->isWellFormed()) {
            return redirect()->to(LoginFlowService::errorUrl('state_expired'));
        }

        return $this->flow->callback($idp, $request);
    }
}
