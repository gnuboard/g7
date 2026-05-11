<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Extension\Cache\CoreCacheDriver;

/**
 * 확장 호환성 알림 dismiss 상태 관리 서비스.
 *
 * 자동 비활성화/재호환 알림을 사용자별로 dismiss 한 이력을 캐시에 저장하고 조회합니다.
 * Listener (대시보드 카드 알림 생성) 와 Controller (배너 데이터 소스) 가 모두 본 서비스를
 * 통해 동일한 dismiss SSoT 를 사용합니다 — Listener 가 도메인 헬퍼 역할을 겸하지 않도록
 * Service-Repository 패턴 보호.
 *
 * 캐시 키: `ext.compat_alert_dismissed.{userId}` (사용자별 분리, 30일 TTL)
 *
 * @since 7.0.0-beta.4
 */
class ExtensionCompatibilityAlertService
{
    /**
     * 사용자별 dismiss 이력 캐시 키 prefix.
     */
    private const DISMISS_CACHE_PREFIX = 'ext.compat_alert_dismissed.';

    /**
     * dismiss 이력 보존 기간 (초).
     */
    private const DISMISS_CACHE_TTL = 86400 * 30;

    /**
     * @param  CacheInterface  $cache  dismiss 이력 저장소 (사용자별 캐시)
     */
    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 특정 사용자의 dismiss 된 알림 ID 목록을 조회합니다.
     *
     * 응답 데이터 소스 (배너/카드) 에서 본 메서드를 호출해 dismiss 된 항목을 제외합니다.
     *
     * @param  int|null  $userId  사용자 ID (null/0 이면 빈 배열)
     * @return array<int, string> dismiss 된 alertId 목록
     */
    public function getDismissedAlertIds(?int $userId): array
    {
        if (! $userId) {
            return [];
        }

        $cached = $this->cache->get(self::DISMISS_CACHE_PREFIX.$userId, []);

        if (! is_array($cached)) {
            return [];
        }

        return array_values(array_filter($cached, 'is_string'));
    }

    /**
     * 알림을 사용자별로 dismiss 합니다.
     *
     * 자동 비활성화 알림 (DB 영속) 은 다른 관리자에게는 계속 표시되고,
     * 재호환 알림 (캐시 기반) 은 캐시 만료/감지 갱신 시 재노출됩니다.
     *
     * @param  string  $alertId  알림 ID (compat_{type}_{identifier} 또는 recover_{type}_{identifier})
     * @param  int|null  $userId  사용자 ID (null/0 이면 무시)
     */
    public function dismissAlert(string $alertId, ?int $userId): void
    {
        if (! $userId) {
            return;
        }

        $key = self::DISMISS_CACHE_PREFIX.$userId;
        $dismissed = $this->cache->get($key, []);

        if (! is_array($dismissed)) {
            $dismissed = [];
        }

        if (! in_array($alertId, $dismissed, true)) {
            $dismissed[] = $alertId;
            $this->cache->put($key, $dismissed, self::DISMISS_CACHE_TTL);
        }
    }

    /**
     * 컨테이너 바인딩 실패 시의 fallback 인스턴스 (CacheInterface 미바인딩 환경 보호).
     *
     * @return self
     */
    public static function fallback(): self
    {
        return new self(new CoreCacheDriver(config('cache.default', 'array')));
    }
}
