<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 가입되지 않은 주소의 로그인 실패 횟수·잠금 흉내
 *
 * 가입 계정만 N 회 실패 뒤 잠금 응답(423)을 받으면, 아무 주소에 틀린 비밀번호를 N+1 번 넣어 보는 것으로
 * 가입 여부를 알 수 있다. 가입되지 않은 주소의 실패도 같은 기준으로 세어, 같은 횟수 뒤에 같은 잠금 응답이
 * 나가게 한다.
 *
 * 가입 계정의 잠금(회원 행의 실패 횟수·잠금 시각)과는 별개다 — 여기 기록은 캐시에만 있고 회원 데이터를
 * 건드리지 않는다. 잠금 훅·알림도 내지 않는다(잠글 계정이 없다).
 *
 * 기준은 보안 환경설정값만 쓴다. 계정별로 잠금 기준을 바꾸는 확장 필터는 대상 회원이 있어야 의미가 있으므로
 * 부르지 않는다.
 *
 * 기록 수명은 잠금 시간과 같다(실패할 때마다 다시 잰다 — 가입 계정이 잠금 시간 안의 연속 실패만 세는 것과
 * 같은 창). 잠금 시간이 무기한(0)이어도 기록은 최대 24시간만 남긴다.
 *
 * @since 7.1.6
 */
class UnknownEmailLockService
{
    /**
     * 무기한 설정에서 기록을 남기는 최대 시간(초)
     */
    private const PERMANENT_TTL_SECONDS = 86400;

    /**
     * 주소별 실패 기록 잠금 키 접두사
     */
    private const LOCK_PREFIX = 'auth.unknown_login.lock.';

    /**
     * @param  CacheInterface  $cache  코어 캐시
     */
    public function __construct(
        private CacheInterface $cache,
    ) {}

    /**
     * 가입되지 않은 주소의 로그인 실패 1회를 기록합니다.
     *
     * 로그인 시도 제한이 꺼져 있거나 임계 횟수가 0 이하면 아무것도 하지 않습니다.
     *
     * @param  string  $email  로그인에 쓰인 이메일
     * @return void 반환값 없음
     */
    public function recordFailure(string $email): void
    {
        if (! $this->enabled()) {
            return;
        }

        // 읽고 → 1 더해 → 쓰는 사이에 같은 주소의 다른 실패가 끼면 한 번이 사라진다 — 주소별 잠금으로 직렬화한다
        // (KVE 동형 L5, 7.1.6). 잠금을 3초 안에 못 얻으면 종전처럼 잠금 없이 센다(로그인 응답을 막지 않는다).
        try {
            Cache::lock(self::LOCK_PREFIX.hash('sha256', mb_strtolower($email)), 5)
                ->block(3, fn () => $this->recordFailureUnlocked($email));
        } catch (LockTimeoutException) {
            Log::debug('[auth] 미가입 주소 실패 기록 잠금 시간 초과 — 잠금 없이 셉니다');
            $this->recordFailureUnlocked($email);
        }
    }

    /**
     * 가입되지 않은 주소의 실패 1회를 기록합니다 (잠금 안에서 부른다).
     *
     * @param  string  $email  로그인에 쓰인 이메일
     * @return void 반환값 없음
     */
    private function recordFailureUnlocked(string $email): void
    {
        $state = $this->read($email);

        // 이미 잠긴 주소는 더 세지 않는다 (가입 계정도 잠긴 동안은 시도 자체를 받지 않는다).
        if ($this->isLocked($state)) {
            return;
        }

        $lockoutMinutes = $this->lockoutMinutes();
        $ttl = $lockoutMinutes > 0 ? $lockoutMinutes * 60 : self::PERMANENT_TTL_SECONDS;

        $state = [
            'count' => (int) ($state['count'] ?? 0) + 1,
            'locked' => false,
            'locked_until' => null,
        ];

        if ($state['count'] >= $this->maxAttempts()) {
            $state['locked'] = true;
            $state['locked_until'] = $lockoutMinutes > 0
                ? now()->addMinutes($lockoutMinutes)->toIso8601String()
                : null;
        }

        $this->cache->put($this->key($email), $state, $ttl);
    }

    /**
     * 주소가 잠금 흉내 상태면 잠금 정보를 반환합니다.
     *
     * @param  string  $email  이메일
     * @return array{locked_until: Carbon|null, remaining_minutes: int|null}|null 잠금 정보 (잠기지 않았으면 null)
     */
    public function lockState(string $email): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $state = $this->read($email);

        if (! $this->isLocked($state)) {
            return null;
        }

        if ($state['locked_until'] === null) {
            return ['locked_until' => null, 'remaining_minutes' => null];
        }

        $lockedUntil = Carbon::parse($state['locked_until']);

        return [
            'locked_until' => $lockedUntil,
            'remaining_minutes' => max(1, (int) ceil(now()->diffInSeconds($lockedUntil, false) / 60)),
        ];
    }

    /**
     * 주소에 기록된 실패 횟수를 반환합니다.
     *
     * @param  string  $email  이메일
     * @return int 실패 횟수 (기록이 없으면 0)
     */
    public function failureCount(string $email): int
    {
        return (int) ($this->read($email)['count'] ?? 0);
    }

    /**
     * 주소의 기록을 지웁니다 (그 주소로 가입이 끝났을 때).
     *
     * @param  string  $email  이메일
     * @return void 반환값 없음
     */
    public function forget(string $email): void
    {
        $this->cache->forget($this->key($email));
    }

    /**
     * 로그인 시도 제한이 켜져 있고 임계 횟수가 유효한지 판정합니다.
     *
     * @return bool 흉내 카운터를 쓸 조건이면 true
     */
    private function enabled(): bool
    {
        return (bool) g7_core_settings('security.login_attempt_enabled', true) && $this->maxAttempts() > 0;
    }

    /**
     * 보안 환경설정의 임계 횟수를 반환합니다.
     *
     * @return int 임계 횟수
     */
    private function maxAttempts(): int
    {
        return (int) g7_core_settings('security.max_login_attempts', 5);
    }

    /**
     * 보안 환경설정의 잠금 시간(분)을 반환합니다. 0 은 무기한입니다.
     *
     * @return int 잠금 시간 (분)
     */
    private function lockoutMinutes(): int
    {
        return max(0, (int) g7_core_settings('security.login_lockout_time', 5));
    }

    /**
     * 기록을 읽습니다.
     *
     * @param  string  $email  이메일
     * @return array{count?: int, locked?: bool, locked_until?: string|null} 기록 (없으면 빈 배열)
     */
    private function read(string $email): array
    {
        $state = $this->cache->get($this->key($email));

        return is_array($state) ? $state : [];
    }

    /**
     * 기록이 지금 잠금 상태인지 판정합니다.
     *
     * @param  array{count?: int, locked?: bool, locked_until?: string|null}  $state  기록
     * @return bool 잠금 상태면 true
     */
    private function isLocked(array $state): bool
    {
        if (! ($state['locked'] ?? false)) {
            return false;
        }

        $lockedUntil = $state['locked_until'] ?? null;

        return $lockedUntil === null || Carbon::parse($lockedUntil)->isFuture();
    }

    /**
     * 주소의 캐시 키를 반환합니다 — 주소 자체는 키에 남기지 않습니다.
     *
     * @param  string  $email  이메일
     * @return string 캐시 키
     */
    private function key(string $email): string
    {
        return 'auth.unknown_login.'.hash('sha256', mb_strtolower(trim($email)));
    }
}
