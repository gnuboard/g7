<?php

namespace Plugins\Sirsoft\Sso\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Support\IpAccessMatcher;

/**
 * IdP 가 서버에서 직접 보내는 통보 경로를 코어 IP 접근 제한의 제외 경로에 등록합니다.
 *
 * OIDC back-channel 로그아웃은 회원 브라우저가 아니라 IdP 서버가 부른다 — 운영자가 IP 규칙을 켜면 403 으로 막혀 IdP 에서 끝낸 세션이
 * 이 사이트에 남는다. 콜백·ACS·SLS 처럼 회원 브라우저가 들어오는 경로는 등록하지 않는다(막힌 IP 의 브라우저가 로그인을 끝내면 안 된다).
 * 관리자 영역은 코어가 어떤 등록으로도 제외하지 않는다.
 *
 * @since 1.0.0
 */
class RegisterIpRestrictionExemptPathsListener implements HookListenerInterface
{
    /**
     * 제외할 경로 패턴 (정규화된 URI — 앞뒤 `/` 없음).
     *
     * @var array<int, string>
     */
    private const PATTERNS = [
        // OIDC back-channel 로그아웃 — IdP 서버 → 이 사이트
        'plugins/sirsoft-sso/oidc/*/backchannel-logout',
    ];

    /**
     * 이 리스너가 구독하는 훅 정의를 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            IpAccessMatcher::EXEMPT_PATHS_HOOK => [
                'method' => 'register',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용 — register 에서 처리)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 지금까지 모인 제외 패턴에 이 확장의 패턴을 덧붙입니다.
     *
     * @param  mixed  $patterns  제외 패턴 (다른 리스너가 이미 덧붙인 값 포함)
     * @return array<int, mixed> 이 확장의 패턴이 더해진 목록
     */
    public function register(mixed $patterns): array
    {
        $patterns = is_array($patterns) ? $patterns : [];

        return array_values(array_unique([...$patterns, ...self::PATTERNS]));
    }
}
