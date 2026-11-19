<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;

/**
 * 제공자별 실패를 최근 10분 창으로 세고, 설정한 기준에 닿으면 하루 한 번 알림 훅을 발행합니다.
 *
 * 제공자 왕복(코드 교환·프로필 조회)이 실패할 때마다 실패 시각을 남기고, 최근 10분 안의 실패가 기준 이상이면 알린다. 사이에
 * 성공이 섞여도 실패를 지우지 않는다 — 일부 회원만 실패하는 부분 장애도 알림이 가게. 회원이 동의를 취소한 것(`access_denied`)은
 * 제공자 장애가 아니므로 세지 않고, 관리자 연결 테스트도 세지 않는다(설정 중에는 일부러 틀린 값을 넣어 본다). 기준이 0 이면
 * 세지 않는다. 시각 목록은 배열로 감싸 저장하고 기준 개수만큼만 남긴다(그보다 오래된 시각은 판정에 쓰이지 않는다).
 *
 * @since 1.0.0
 */
class FailureStreakMonitor
{
    /** 연속 실패 훅 (인자: string $providerKey, int $count — 최근 10분 실패 수, string $lastErrorCode) */
    public const ACTION_FAILING = 'sirsoft-social_login.provider.failing';

    /** 기본 기준 */
    private const DEFAULT_THRESHOLD = 10;

    /** 회원 쪽 사유라 장애로 세지 않는 코드 */
    private const USER_CAUSED = ['access_denied', 'consent_required', 'login_required', 'interaction_required', 'user_cancel'];

    /** 판정 창(초) — 이 시간 안의 실패만 센다 */
    public const WINDOW_SECONDS = 600;

    /**
     * @param  CacheInterface  $cache  플러그인 캐시
     */
    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * 왕복 결과 1건을 반영합니다.
     *
     * @param  string  $providerKey  제공자 키
     * @param  string  $intent  의도
     * @param  string|null  $errorCode  실패 코드 (성공이면 null)
     * @return void 반환값 없음
     */
    public function record(string $providerKey, string $intent, ?string $errorCode): void
    {
        if ($intent === AttemptIntent::Test->value) {
            return;
        }

        if ($errorCode === null) {
            return;
        }

        $threshold = (int) plugin_setting('sirsoft-social_login', 'provider_failure_streak_threshold', self::DEFAULT_THRESHOLD);
        if ($threshold <= 0 || in_array($errorCode, self::USER_CAUSED, true)) {
            return;
        }

        $windowKey = 'failures:'.$providerKey;
        $now = now()->getTimestamp();
        $stored = $this->cache->get($windowKey);
        $times = is_array($stored) && is_array($stored['t'] ?? null) ? $stored['t'] : [];
        $times = array_values(array_filter($times, fn ($t) => is_int($t) && $t > $now - self::WINDOW_SECONDS));
        $times[] = $now;
        $times = array_slice($times, -$threshold);
        $this->cache->put($windowKey, ['t' => $times], self::WINDOW_SECONDS);

        $count = count($times);
        if ($count < $threshold) {
            return;
        }

        // audit:allow datetime-display-user-timezone reason: 캐시 키의 하루 버킷 — 기계값
        $notifiedKey = 'notified:provider_failing:'.$providerKey.':'.now()->format('Ymd');
        if ($this->cache->get($notifiedKey) !== null) {
            return;
        }

        $this->cache->put($notifiedKey, ['at' => now()->toIso8601String()], 86400);

        HookManager::doAction(self::ACTION_FAILING, $providerKey, $count, $errorCode);
    }
}
