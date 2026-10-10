<?php

namespace App\Analytics;

use App\Contracts\Extension\CacheInterface;

/**
 * 통계 알림 쿨다운
 *
 * 같은 종류의 경고를 하루에 한 번만 보냅니다. 경고를 내는 자리가 셋이고(원장 한계·집계
 * 실패·방문당 액션 상한) 그중 둘은 사람이 부르지 않아도 스스로 도는 경로라, 쿨다운이
 * 없으면 같은 사실을 알리는 알림이 끝없이 쌓입니다.
 *
 * 특히 방문당 액션 상한은 수집 요청을 받을 때마다 판정하므로, 상한을 넘긴 방문 하나가
 * 계속 비콘을 보내면 알림함이 같은 문구로 가득 찹니다. 운영자는 그 더미에서 정작 다른
 * 경고를 놓칩니다 — 알림이 많아지면 알림을 안 보게 됩니다.
 *
 * 표시와 조회 사이에 아주 좁은 틈이 있어 같은 순간에 들어온 둘이 모두 보낼 수는
 * 있습니다. 같은 경고가 두 번 가는 것은 잃는 것이 없어 그 틈을 감수합니다.
 */
class NotificationCooldown
{
    /**
     * 쿨다운 캐시 키의 접두
     */
    private const PREFIX = 'analytics:notified:';

    /**
     * 같은 경고를 다시 보내기까지 기다리는 시간 (초)
     */
    private const COOLDOWN_SECONDS = 86400;

    /**
     * 쿨다운을 생성합니다.
     *
     * @param  CacheInterface  $cache  쿨다운 저장소
     */
    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * 오늘 이 유형의 알림을 아직 보내지 않았는지 확인하고 표시합니다.
     *
     * 캐시 저장소가 죽으면 보내는 쪽으로 넘어갑니다 — 경고를 놓치는 것보다 두 번
     * 가는 쪽이 낫습니다.
     *
     * @param  string  $type  알림 유형 (`archive_failed` 등)
     * @return bool 지금 보내야 하면 true
     */
    public function shouldNotify(string $type): bool
    {
        $key = self::PREFIX.$type.':'.str_replace('-', '', AnalyticsClock::today());

        try {
            if ($this->cache->has($key)) {
                return false;
            }

            $this->cache->put($key, 1, self::COOLDOWN_SECONDS);
        } catch (\Throwable $e) {
            return true;
        }

        return true;
    }
}
