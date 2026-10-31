<?php

namespace Plugins\Sirsoft\Sso\Tests\Support;

use Illuminate\Http\Request;
use Plugins\Sirsoft\Sso\Contracts\ProtocolDriverInterface;
use Plugins\Sirsoft\Sso\DTO\ConnectionTestResult;
use Plugins\Sirsoft\Sso\DTO\DriverCapabilities;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\DTO\LoginIntent;
use Plugins\Sirsoft\Sso\DTO\LogoutResult;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * 시험용 프로토콜 드라이버 — 다른 확장이 필터로 더하는 드라이버를 흉내 낸다.
 *
 * @since 1.0.0
 */
class FakeProtocolDriver implements ProtocolDriverInterface
{
    /**
     * 드라이버 키를 받아 만듭니다.
     *
     * @param  string  $driverKey  드라이버 키
     */
    public function __construct(private readonly string $driverKey) {}

    /**
     * 드라이버 키를 반환합니다.
     *
     * @return string 키
     */
    public function key(): string
    {
        return $this->driverKey;
    }

    /**
     * 표시 이름을 반환합니다.
     *
     * @param  string|null  $locale  로케일
     * @return string 이름
     */
    public function label(?string $locale = null): string
    {
        return '시험 드라이버';
    }

    /**
     * 기능 표 — 리디렉션만.
     *
     * @return DriverCapabilities 기능 표
     */
    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(redirect: true);
    }

    /**
     * 늘 쓸 수 있습니다.
     *
     * @return bool 참
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * 설정 칸 없음.
     *
     * @return array<int, array<string, mixed>> 빈 목록
     */
    public function configSchema(): array
    {
        return [];
    }

    /**
     * 늘 통과.
     *
     * @param  array<string, mixed>  $config  설정
     * @param  array<string, mixed>  $secrets  비밀값
     * @return array<string, string> 빈 오류 목록
     */
    public function validateConfig(array $config, array $secrets): array
    {
        return [];
    }

    /**
     * 빈 302 응답.
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @param  LoginIntent  $intent  왕복 시작 정보
     * @return Response 302 응답
     */
    public function beginLogin(IdentityProvider $idp, LoginIntent $intent): Response
    {
        return new Response('', 302);
    }

    /**
     * 고정 신원.
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @param  Request  $request  콜백 요청
     * @param  LoginIntent|null  $intent  왕복 시작 정보
     * @return ExternalIdentity 시험 신원
     */
    public function completeLogin(IdentityProvider $idp, Request $request, ?LoginIntent $intent): ExternalIdentity
    {
        return new ExternalIdentity('fake-subject');
    }

    /**
     * 로그아웃 미지원.
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @param  SsoSession  $session  끝낼 세션
     * @return Response|null 항상 null
     */
    public function beginLogout(IdentityProvider $idp, SsoSession $session): ?Response
    {
        return null;
    }

    /**
     * 끝낼 세션 없음.
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @param  Request  $request  콜백 요청
     * @return LogoutResult 빈 결과
     */
    public function handleLogoutRequest(IdentityProvider $idp, Request $request): LogoutResult
    {
        return new LogoutResult([], new Response(''));
    }

    /**
     * 점검 항목 없음.
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @return ConnectionTestResult 빈 결과
     */
    public function testConnection(IdentityProvider $idp): ConnectionTestResult
    {
        return ConnectionTestResult::fromChecks([]);
    }

    /**
     * 표시할 값 없음.
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @return array<int, array{key: string, label_key: string, value: string}> 빈 목록
     */
    public function serviceProviderInfo(IdentityProvider $idp): array
    {
        return [];
    }
}
