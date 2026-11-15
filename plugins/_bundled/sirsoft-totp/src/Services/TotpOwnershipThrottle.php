<?php

namespace Plugins\Sirsoft\Totp\Services;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Plugins\Sirsoft\Totp\Exceptions\OwnershipThrottledException;

/**
 * 인증 앱 해제·백업 코드 재발급의 「현재 코드 확인」 시도를 회원 단위로 제한합니다 (KVE 동형 A1).
 *
 * 열린 세션만 있으면 해제 코드를 무제한 맞혀 볼 수 있어 2단계 인증을 끌 수 있었다. 기준은 코어 로그인 잠금과 같은
 * 보안 설정(최대 시도 횟수·잠금 시간)을 쓴다.
 *
 * @since 1.0.3
 */
class TotpOwnershipThrottle
{
    /**
     * 제한 키 접두사
     */
    public const KEY_PREFIX = 'sirsoft-totp:ownership:';

    /**
     * 시도 1회를 먼저 세어 자리를 잡고, 기준을 넘었으면 거부합니다.
     *
     * 「기준 검사 → 계산 → 실패 시 증가」 순서면 동시에 보낸 요청이 같은 남은 횟수를 함께 통과해 기준보다 많이
     * 맞혀 볼 수 있다. 증가가 먼저면 각 요청이 서로 다른 번호를 받는다. 성공하면 호출자가 `clear()` 로 지운다.
     *
     * @param  User  $user  대상 회원
     * @return void 반환값 없음
     *
     * @throws OwnershipThrottledException 이번 시도가 기준을 넘었을 때
     */
    public function reserve(User $user): void
    {
        $key = $this->key($user);

        if (RateLimiter::hit($key, $this->decaySeconds()) > $this->maxAttempts()) {
            throw new OwnershipThrottledException(max(1, RateLimiter::availableIn($key)));
        }
    }

    /**
     * 성공했으므로 실패 기록을 지웁니다.
     *
     * @param  User  $user  대상 회원
     * @return void 반환값 없음
     */
    public function clear(User $user): void
    {
        RateLimiter::clear($this->key($user));
    }

    /**
     * 회원별 제한 키.
     *
     * @param  User  $user  대상 회원
     * @return string 제한 키
     */
    private function key(User $user): string
    {
        return self::KEY_PREFIX.$user->getKey();
    }

    /**
     * 시도 기준 횟수 (코어 보안 설정, 최소 1).
     *
     * @return int 기준 횟수
     */
    private function maxAttempts(): int
    {
        return max(1, (int) g7_core_settings('security.max_login_attempts', 5));
    }

    /**
     * 실패 기록 유지 시간(초) — 코어 보안 설정의 잠금 시간(분), 최소 1분.
     *
     * @return int 유지 시간(초)
     */
    private function decaySeconds(): int
    {
        return max(1, (int) g7_core_settings('security.login_lockout_time', 5)) * 60;
    }
}
