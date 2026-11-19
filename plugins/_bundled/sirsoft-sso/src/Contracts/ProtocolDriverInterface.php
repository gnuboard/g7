<?php

namespace Plugins\Sirsoft\Sso\Contracts;

use Illuminate\Http\Request;
use Plugins\Sirsoft\Sso\DTO\ConnectionTestResult;
use Plugins\Sirsoft\Sso\DTO\DriverCapabilities;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\DTO\LoginIntent;
use Plugins\Sirsoft\Sso\DTO\LogoutResult;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * SSO 프로토콜 드라이버 계약.
 *
 * 드라이버는 「IdP 와 주고받기」만 한다 — 서명, state, 재생 검증을 마친 신원(`ExternalIdentity`)을 돌려주는 데까지다. 그 신원을
 * 어느 회원으로 볼지, 로그인 인계를 어떻게 할지는 `LoginFlowService` 와 `AccountResolver` 가 정한다.
 *
 * 다른 확장은 필터 `sirsoft-sso.protocol.filter_registered` 로 이 계약의 구현을 더할 수 있다(내장 키는 덮어쓸 수 없다).
 *
 * @since 1.0.0
 */
interface ProtocolDriverInterface
{
    /**
     * 프로토콜 키를 반환합니다 (`sso_identity_providers.protocol` 값).
     *
     * @return string 키 — 내장은 oidc, saml, ldap
     */
    public function key(): string;

    /**
     * 화면에 보일 프로토콜 이름을 반환합니다.
     *
     * @param  string|null  $locale  로케일 (null 이면 현재)
     * @return string 이름
     */
    public function label(?string $locale = null): string;

    /**
     * 드라이버가 지원하는 기능을 반환합니다.
     *
     * @return DriverCapabilities 기능 표
     */
    public function capabilities(): DriverCapabilities;

    /**
     * 서버에서 이 드라이버를 쓸 수 있는지 확인합니다 (의존 PHP 확장 등).
     *
     * @return bool 쓸 수 있으면 true
     */
    public function isAvailable(): bool;

    /**
     * 관리자 화면이 그리는 설정 칸 정의를 반환합니다.
     *
     * 각 칸: `key`, `type`(string, url, textarea, boolean, select, secret, list), `required`, `default`, `options`,
     * `label`·`hint`(로케일별), `secret`(true 면 `secrets` 에 저장).
     *
     * @return array<int, array<string, mixed>> 칸 정의
     */
    public function configSchema(): array;

    /**
     * 설정값을 검증합니다.
     *
     * @param  array<string, mixed>  $config  비밀이 아닌 설정
     * @param  array<string, mixed>  $secrets  비밀값
     * @return array<string, string> 칸 키 → 오류 문구 키 (비어 있으면 통과)
     */
    public function validateConfig(array $config, array $secrets): array;

    /**
     * IdP 로 보내는 응답을 만듭니다 (302 또는 SAML POST 바인딩 자동 제출 폼).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  LoginIntent  $intent  시작 정보 (state 저장 완료)
     * @return Response IdP 로 가는 응답
     *
     * @throws SsoException 설정 오류, IdP 조회 실패
     */
    public function beginLogin(IdentityProvider $idp, LoginIntent $intent): Response;

    /**
     * IdP 콜백을 검증하고 신원을 돌려줍니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  Request  $request  콜백 요청
     * @param  LoginIntent|null  $intent  state 로 찾은 시작 정보 (IdP-initiated 면 null)
     * @return ExternalIdentity 검증된 신원
     *
     * @throws SsoException 검증 실패 (오류 코드는 부록 C-3 목록)
     */
    public function completeLogin(IdentityProvider $idp, Request $request, ?LoginIntent $intent): ExternalIdentity;

    /**
     * IdP 로그아웃으로 보낼 응답을 만듭니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  SsoSession  $session  끝낼 SSO 세션
     * @return Response|null 응답, 지원하지 않으면 null
     */
    public function beginLogout(IdentityProvider $idp, SsoSession $session): ?Response;

    /**
     * IdP 가 보낸 로그아웃 요청을 처리합니다 (SAML SLS, OIDC back-channel).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  Request  $request  IdP 요청
     * @return LogoutResult 끝낼 세션과 IdP 에 돌려줄 응답
     *
     * @throws SsoException 검증 실패
     */
    public function handleLogoutRequest(IdentityProvider $idp, Request $request): LogoutResult;

    /**
     * 서버 쪽 연결 시험을 합니다 (메타데이터, 키 조회 등 — 회원 로그인 없음).
     *
     * @param  IdentityProvider  $idp  IdP
     * @return ConnectionTestResult 점검 항목별 결과
     */
    public function testConnection(IdentityProvider $idp): ConnectionTestResult;

    /**
     * 운영자가 IdP 콘솔에 넣을 값을 반환합니다 (ACS, Entity ID, Redirect URI, 메타데이터 URL 등 — 사이트 주소 기준).
     *
     * @param  IdentityProvider  $idp  IdP
     * @return array<int, array{key: string, label_key: string, value: string}> 표시 행
     */
    public function serviceProviderInfo(IdentityProvider $idp): array;
}
