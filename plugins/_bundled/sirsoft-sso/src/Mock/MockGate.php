<?php

namespace Plugins\Sirsoft\Sso\Mock;

use App\Contracts\Extension\CacheInterface;
use Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcKeys;

/**
 * 모의 IdP 활성 게이트.
 *
 * 모의 IdP(OIDC, SAML)는 개발·검수 환경에서 실제 IdP 없이 왕복을 재기 위한 것이다. 관리자 화면이나 API 로는 켤 수 없고
 * `php artisan sirsoft-sso:mock --enable --minutes=N` 으로만 켠다(최대 240분). 꺼져 있으면 모의 라우트는 404 이고 로그인 화면에도
 * 모의 IdP(키 접두 `mock_`) 버튼이 나오지 않는다.
 *
 * @since 1.0.0
 */
class MockGate
{
    /** 활성 만료 시각 캐시 키 */
    private const KEY = 'mock_enabled_until';

    /** 최대 활성 시간(분) */
    public const MAX_MINUTES = 240;

    /**
     * @param  CacheInterface  $cache  플러그인 캐시
     */
    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * 모의 IdP 가 켜져 있는지 확인합니다.
     *
     * 만료 시각은 정수로 저장하지만 Redis 저장소는 숫자를 문자열로 돌려주므로 숫자 문자열도 받는다.
     *
     * @return bool 켜져 있으면 true
     */
    public function enabled(): bool
    {
        $until = $this->cache->get(self::KEY);

        return (is_int($until) || (is_string($until) && ctype_digit($until))) && (int) $until > time();
    }

    /**
     * 만료 시각을 반환합니다.
     *
     * @return int|null 유닉스 초, 꺼져 있으면 null
     */
    public function until(): ?int
    {
        return $this->enabled() ? (int) $this->cache->get(self::KEY) : null;
    }

    /**
     * 모의 IdP 를 켭니다.
     *
     * @param  int  $minutes  활성 시간(분, 1~240)
     * @return int 만료 시각(유닉스 초)
     */
    public function enable(int $minutes): int
    {
        $minutes = max(1, min(self::MAX_MINUTES, $minutes));
        $until = time() + $minutes * 60;
        $this->cache->put(self::KEY, $until, $minutes * 60);

        return $until;
    }

    /**
     * 모의 IdP 를 끕니다 — 모의 서명 키도 함께 지웁니다.
     *
     * @return void 반환값 없음
     */
    public function disable(): void
    {
        $this->cache->forget(self::KEY);
        app(MockOidcKeys::class)->forget();
    }
}
