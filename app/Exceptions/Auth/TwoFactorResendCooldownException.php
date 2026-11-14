<?php

namespace App\Exceptions\Auth;

use RuntimeException;

/**
 * 로그인 2단계 메일 재발송 대기 시간이 남아 있음 (HTTP 429 + Retry-After).
 *
 * 공개 인증 요청과 같은 60초 대기다 — 재발송 연타로 메일을 쏟아내지 못하게 한다(KVE 동형 A2).
 *
 * @since 7.1.6
 */
class TwoFactorResendCooldownException extends RuntimeException
{
    /**
     * @param  int  $retryAfterSeconds  다시 요청할 수 있을 때까지 남은 초
     */
    public function __construct(
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct(__('auth.two_factor_resend_cooldown', ['seconds' => $retryAfterSeconds]));
    }
}
