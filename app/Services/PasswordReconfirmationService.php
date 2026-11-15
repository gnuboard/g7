<?php

namespace App\Services;

use App\Exceptions\Auth\PasswordReconfirmThrottledException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 로그인한 회원의 「현재 비밀번호 다시 확인」을 회원 단위 시도 제한과 함께 수행합니다.
 *
 * 열린 세션만 있으면 비밀번호를 계속 맞혀 볼 수 있던 경로(마이페이지 비밀번호 확인 — KVE 동형 L2, 인증 앱 등록 시작 —
 * KVE-2026-3194)를 같은 제한 키로 묶는다. 한 경로에서 틀린 횟수는 다른 경로에도 쌓인다.
 *
 * 기준은 로그인 잠금과 같은 보안 설정(최대 시도 횟수·잠금 시간)을 쓴다. 기준을 넘으면 비밀번호를 계산하지 않고
 * 거부해 대입 비용을 키운다. 맞히면 기록을 지운다.
 *
 * @since 7.1.6
 */
class PasswordReconfirmationService
{
    /**
     * 제한 키 접두사 (확장도 같은 키를 공유한다)
     */
    public const KEY_PREFIX = 'password-reconfirm:';

    /**
     * 현재 비밀번호를 확인합니다.
     *
     * @param  User  $user  확인할 회원
     * @param  string  $plain  입력한 비밀번호
     * @return bool 맞으면 true, 틀리면 false
     *
     * @throws PasswordReconfirmThrottledException 시도 기준을 넘었을 때 (비밀번호를 계산하지 않는다)
     */
    public function attempt(User $user, string $plain): bool
    {
        $key = self::KEY_PREFIX.$user->getKey();

        // 먼저 시도 1회를 세어 자리를 잡는다 — 「검사 후 실패 시 증가」 순서면 동시에 보낸 요청이 같은 남은 횟수를
        // 함께 통과해 상한보다 많이 맞혀 볼 수 있다. 성공하면 아래에서 지운다.
        if (RateLimiter::hit($key, $this->decaySeconds()) > $this->maxAttempts()) {
            throw new PasswordReconfirmThrottledException(max(1, RateLimiter::availableIn($key)));
        }

        if (! is_string($user->password) || ! Hash::check($plain, $user->password)) {
            return false;
        }

        RateLimiter::clear($key);

        return true;
    }

    /**
     * 시도 기준 횟수 (보안 설정의 최대 로그인 시도 횟수, 최소 1).
     *
     * @return int 기준 횟수
     */
    private function maxAttempts(): int
    {
        return max(1, (int) g7_core_settings('security.max_login_attempts', 5));
    }

    /**
     * 실패 기록 유지 시간(초) — 보안 설정의 잠금 시간(분), 최소 1분.
     *
     * @return int 유지 시간(초)
     */
    private function decaySeconds(): int
    {
        return max(1, (int) g7_core_settings('security.login_lockout_time', 5)) * 60;
    }
}
