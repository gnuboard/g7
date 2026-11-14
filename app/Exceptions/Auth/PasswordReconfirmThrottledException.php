<?php

namespace App\Exceptions\Auth;

use RuntimeException;

/**
 * 현재 비밀번호 재확인을 너무 여러 번 실패해 잠시 거부함 (HTTP 429 + Retry-After).
 *
 * @since 7.1.6
 */
class PasswordReconfirmThrottledException extends RuntimeException
{
    /**
     * @param  int  $retryAfterSeconds  다시 시도할 수 있을 때까지 남은 초
     */
    public function __construct(
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct(__('auth.password_reconfirm_throttled', ['seconds' => $retryAfterSeconds]));
    }
}
