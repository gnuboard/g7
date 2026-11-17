<?php

namespace App\Exceptions\Auth;

use RuntimeException;

/**
 * 로그인 2단계 수단이 코드를 보내지 않아(인증 앱) 재발송할 수 없음 (HTTP 422).
 *
 * @since 7.1.6
 */
class TwoFactorResendNotSupportedException extends RuntimeException
{
    /**
     * 예외를 생성합니다.
     */
    public function __construct()
    {
        parent::__construct(__('auth.two_factor_resend_not_supported'));
    }
}
