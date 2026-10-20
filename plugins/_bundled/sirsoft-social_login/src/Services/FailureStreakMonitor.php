<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;

/**
 * 제공자별 연속 실패를 세고, 설정한 기준에 닿으면 하루 한 번 알림 훅을 발행합니다.
 *
 * 제공자 왕복(코드 교환·프로필 조회)이 실패할 때마다 1 씩 늘리고 한 번이라도 성공하면 0 으로 돌린다. 회원이 동의를 취소한 것
 * (`access_denied`)은 제공자 장애가 아니므로 세지 않고, 관리자 연결 테스트도 세지 않는다(설정 중에는 일부러 틀린 값을 넣어 본다).
 * 기준이 0 이면 세지 않는다. 카운터는 배열로 감싸 저장한다 — 숫자 스칼라를 캐시에 두면 저장소에 따라 문자열로 돌아온다.
 *
 * @since 1.0.0
 */
class FailureStreakMonitor
{
    /** 연속 실패 훅 (인자: string $providerKey, int $count, string $lastErrorCode) */
    public const ACTION_FAILING = 'sirsoft-social_login.provider.failing';

    /** 기본 기준 */
    private const DEFAULT_THRESHOLD = 10;

    /** 회원 쪽 사유라 장애로 세지 않는 코드 */
    private const USER_CAUSED = ['access_denied', 'consent_required', 'login_required', 'interaction_required', 'user_cancel'];

    /** 카운터 유지 시간(초) — 하루 동안 성공이 없어도 셈을 잇는다 */
    private const COUNTER_TTL = 86400;

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

        $counterKey = 'streak:'.$providerKey;

        if ($errorCode === null) {
            $this->cache->forget($counterKey);

            return;
        }

        $threshold = (int) plugin_setting('sirsoft-social_login', 'provider_failure_streak_threshold', self::DEFAULT_THRESHOLD);
        if ($threshold <= 0 || in_array($errorCode, self::USER_CAUSED, true)) {
            return;
        }

        $stored = $this->cache->get($counterKey);
        $count = (is_array($stored) ? (int) ($stored['n'] ?? 0) : 0) + 1;
        $this->cache->put($counterKey, ['n' => $count], self::COUNTER_TTL);

        if ($count < $threshold) {
            return;
        }

        $notifiedKey = 'notified:provider_failing:'.$providerKey.':'.now()->format('Ymd');
        if ($this->cache->get($notifiedKey) !== null) {
            return;
        }

        $this->cache->put($notifiedKey, ['at' => now()->toIso8601String()], 86400);

        HookManager::doAction(self::ACTION_FAILING, $providerKey, $count, $errorCode);
    }
}
