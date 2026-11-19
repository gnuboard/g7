<?php

namespace Plugins\Sirsoft\Sso\Drivers\Saml;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Sso\DTO\LoginIntent;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Mock\MockGate;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoLoginAttempt;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\LoginAttemptRepositoryInterface;
use Plugins\Sirsoft\Sso\Services\LoginFlowService;
use Plugins\Sirsoft\Sso\Services\SafeRedirect;
use Symfony\Component\HttpFoundation\Response;

/**
 * SAML SP 주소(메타데이터, ACS, SLS)의 처리.
 *
 * ACS 와 SLS 는 IdP 가 브라우저를 통해 교차 사이트로 보내므로 CSRF 토큰이 없다. 위조 방어는 SP-initiated 면 1회용 state(RelayState)와
 * 브라우저 묶음 쿠키, IdP-initiated 면 IdP 설정 허용 + 응답 1회 사용 + 서명이 맡는다.
 *
 * @since 1.0.0
 */
class SamlSpService
{
    /** state 값 형식 (StateStore 가 만드는 43자) */
    private const STATE_PATTERN = '/^[A-Za-z0-9]{43}$/';

    /** 세션 서비스 (IdP 발 로그아웃 세션 종료 — 세션 영역) */
    private const SESSION_SERVICE = 'Plugins\\Sirsoft\\Sso\\Services\\SessionService';

    /**
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @param  LoginAttemptRepositoryInterface  $attempts  시도 이력
     * @param  LoginFlowService  $flow  로그인 왕복
     * @param  MockGate  $mockGate  모의 IdP 게이트
     */
    public function __construct(
        private readonly IdentityProviderRepositoryInterface $idps,
        private readonly LoginAttemptRepositoryInterface $attempts,
        private readonly LoginFlowService $flow,
        private readonly MockGate $mockGate,
    ) {}

    /**
     * SP 메타데이터 XML 을 만듭니다 — 꺼진 IdP 도 내준다(켜기 전에 IdP 콘솔에 등록하므로).
     *
     * @param  string  $idpKey  IdP 키
     * @return string|null XML, SAML IdP 가 아니거나 만들 수 없으면 null
     */
    public function metadata(string $idpKey): ?string
    {
        $idp = $this->find($idpKey);
        if ($idp === null) {
            return null;
        }

        try {
            return $this->driver($idp)->spMetadataXml($idp);
        } catch (SsoException) {
            return null;
        }
    }

    /**
     * ACS — IdP 의 로그인 응답(POST 바인딩)을 처리합니다.
     *
     * RelayState 가 state 형식이면 SP-initiated 왕복이다(state 1회용 + 묶음 쿠키 대조 — `LoginFlowService::callback`). 그 밖은
     * IdP-initiated 응답이다 — IdP 설정이 켜져 있을 때만 받고, RelayState 는 같은 출처 경로일 때만 복귀 주소로 쓴다.
     *
     * @param  string  $idpKey  IdP 키
     * @param  Request  $request  요청
     * @return RedirectResponse 다음 화면
     */
    public function acs(string $idpKey, Request $request): RedirectResponse
    {
        $relay = $request->input('RelayState');
        if (is_string($relay) && preg_match(self::STATE_PATTERN, $relay) === 1) {
            return $this->flow->callback($idpKey, $request, 'RelayState');
        }

        $idp = $this->find($idpKey);
        $redirect = SafeRedirect::path(is_string($relay) ? $relay : null);
        if ($idp === null || ! $idp->enabled) {
            return $this->unsolicitedFailure($idp, 'idp_disabled', $redirect, $request);
        }

        try {
            $identity = $this->driver($idp)->completeLogin($idp, $request, null);
        } catch (SsoException $e) {
            return $this->unsolicitedFailure($idp, $e->errorCode, $redirect, $request);
        } catch (\Throwable $e) {
            report($e);

            return $this->unsolicitedFailure($idp, 'saml_invalid', $redirect, $request);
        }

        $intent = new LoginIntent(
            intent: LoginIntent::LOGIN,
            guard: 'user',
            redirect: $redirect,
            idpId: (int) $idp->getKey(),
            idpKey: $idp->key,
            stateId: '',
            nonce: '',
            codeVerifier: '',
            bindHash: '',
            createdAt: time(),
        );

        return $this->flow->complete($idp, $identity, $intent, $request);
    }

    /**
     * SLS — IdP 발 로그아웃 요청 또는 우리 로그아웃 요청의 답을 처리합니다.
     *
     * 고른 세션의 종료(토큰 폐기, 종료 시각)는 세션 서비스가 한다 — OIDC back-channel 과 같은 길.
     *
     * @param  string  $idpKey  IdP 키
     * @param  Request  $request  요청
     * @return Response|null IdP 로 돌아가는 302 또는 복귀 화면, SAML IdP 가 아니면 null
     */
    public function sls(string $idpKey, Request $request): ?Response
    {
        $idp = $this->find($idpKey);
        if ($idp === null) {
            return null;
        }

        try {
            $result = $this->driver($idp)->handleLogoutRequest($idp, $request);
        } catch (SsoException $e) {
            return response('', $e->status >= 400 && $e->status < 500 ? $e->status : 400, ['Cache-Control' => 'no-store']);
        }

        if ($result->sessions !== [] && class_exists(self::SESSION_SERVICE) && method_exists(self::SESSION_SERVICE, 'endFromIdp')) {
            app(self::SESSION_SERVICE)->endFromIdp($result->sessions, 'idp_logout');
        }

        return $result->response;
    }

    /**
     * SAML IdP 를 찾습니다 — 모의 IdP 는 게이트가 켜져 있을 때만.
     *
     * @param  string  $key  IdP 키
     * @return IdentityProvider|null IdP
     */
    public function find(string $key): ?IdentityProvider
    {
        $idp = $this->idps->findByKey($key);
        if ($idp === null || $idp->protocol !== SamlDriver::KEY) {
            return null;
        }

        if ($idp->isMock() && ! $this->mockGate->enabled()) {
            return null;
        }

        return $idp;
    }

    /**
     * IdP 의 SAML 드라이버.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return SamlDriver 드라이버
     *
     * @throws SsoException 드라이버를 쓸 수 없으면 `driver_unavailable`
     */
    private function driver(IdentityProvider $idp): SamlDriver
    {
        $driver = $idp->driver();
        if (! $driver instanceof SamlDriver || ! $driver->isAvailable()) {
            throw new SsoException('driver_unavailable');
        }

        return $driver;
    }

    /**
     * IdP-initiated 실패를 기록하고 로그인 화면 오류로 보냅니다.
     *
     * @param  IdentityProvider|null  $idp  IdP
     * @param  string  $errorCode  오류 코드
     * @param  string  $redirect  복귀 경로
     * @param  Request  $request  요청
     * @return RedirectResponse 오류 화면
     */
    private function unsolicitedFailure(?IdentityProvider $idp, string $errorCode, string $redirect, Request $request): RedirectResponse
    {
        $this->attempts->record([
            'idp_id' => $idp !== null ? (int) $idp->getKey() : null,
            'user_id' => null,
            'intent' => LoginIntent::LOGIN,
            'guard' => 'user',
            'outcome' => SsoLoginAttempt::OUTCOME_FAILED,
            'reason_code' => $errorCode,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        if ($idp !== null && $errorCode !== 'idp_disabled') {
            $this->idps->recordOutcome($idp, $errorCode);
        }

        return redirect()->to(LoginFlowService::errorUrl($errorCode, 'user', $redirect));
    }
}
