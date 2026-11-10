<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;

/**
 * 제공자별 소셜 가입 수를 한 시간 단위로 세고, 설정한 기준을 넘으면 하루 한 번 알림 훅을 발행합니다.
 *
 * 알림 정의(관리자 메일·알림함)는 이 훅을 구독해 붙는다. 기준이 0 이면 세지 않는다. 카운터는 배열로 감싸 저장한다 —
 * 숫자 스칼라를 캐시에 두면 저장소에 따라 문자열로 돌아온다.
 *
 * @since 1.0.0
 */
class SignupSurgeMonitor
{
    /** 가입 급증 훅 (인자: string $providerKey, int $count, int $threshold) */
    public const ACTION_SURGE = 'sirsoft-social_login.signup.surge';

    /** 기본 기준(시간당) */
    private const DEFAULT_THRESHOLD = 100;

    /**
     * @param  CacheInterface  $cache  플러그인 캐시
     */
    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * 가입 1건을 셉니다.
     *
     * @param  string  $providerKey  제공자 키
     * @return void
     */
    public function record(string $providerKey): void
    {
        $threshold = (int) plugin_setting('sirsoft-social_login', 'signup_surge_threshold_per_hour', self::DEFAULT_THRESHOLD);
        if ($threshold <= 0) {
            return;
        }

        // audit:allow datetime-display-user-timezone reason: 캐시 키의 시간 버킷 — 기계값
        $counterKey = 'surge:'.$providerKey.':'.now()->format('YmdH');
        $stored = $this->cache->get($counterKey);
        $count = (is_array($stored) ? (int) ($stored['n'] ?? 0) : 0) + 1;
        $this->cache->put($counterKey, ['n' => $count], 3600);

        if ($count < $threshold) {
            return;
        }

        // audit:allow datetime-display-user-timezone reason: 캐시 키의 하루 버킷 — 기계값
        $notifiedKey = 'notified:signup_surge:'.$providerKey.':'.now()->format('Ymd');
        if ($this->cache->get($notifiedKey) !== null) {
            return;
        }

        $this->cache->put($notifiedKey, ['at' => now()->toIso8601String()], 86400);

        HookManager::doAction(self::ACTION_SURGE, $providerKey, $count, $threshold);
    }
}
