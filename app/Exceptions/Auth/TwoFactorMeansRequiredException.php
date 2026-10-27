<?php

namespace App\Exceptions\Auth;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * 비밀번호 인증 정책이 2단계 인증을 요구했는데 그 회원에게 쓸 수 있는 수단이 없을 때의 예외.
 *
 * 2단계 인증 없이 통과시키면 정책이 요구한 보호가 사라지므로 로그인을 막는다. 로그인 컨트롤러는 422 `auth.two_factor_means_required` 로 답한다.
 *
 * 사용자에게 안내로 답하는 정상 결과라 오류 로그에 보고하지 않는다(`ShouldntReport` — 같은 성격의 `AccountLockedException` 등은
 * `HttpException` 이라 원래 보고되지 않는다).
 *
 * @since 7.2.0
 */
class TwoFactorMeansRequiredException extends RuntimeException implements ShouldntReport
{
    /**
     * 예외를 만듭니다.
     */
    public function __construct()
    {
        parent::__construct(__('auth.two_factor_means_required'));
    }
}
