<?php

namespace App\Exceptions\Auth;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 2단계 인증 수단 미등록 차단 예외 — 비밀번호 확인은 통과했으나 이 사용자가 쓸 수 있는
 * 2단계 인증 수단이 없고, **운영자가 대체 수단(메일 폴백)을 명시적으로 금지**했을 때 발생합니다.
 *
 * HTTP 401 Unauthorized 로 매핑됩니다. 이 상황에서 그냥 로그인시키면 운영자가 끈 것과
 * 정반대의 결과 — 2단계 인증이 아예 없는 로그인 — 가 되므로 통과시키지 않습니다.
 *
 * 수단이 없는 것이 **운영자의 결정이 아닐 때**(메일 설정이 깨진 경우 등)는 이 예외를 쓰지
 * 않습니다. 그때 차단하면 아무도 로그인하지 못해 설정을 되돌릴 수단까지 사라집니다.
 *
 * 메시지 자리에는 번역문이 아니라 다국어 **키**를 보관합니다 (선례: AccountLockedException).
 *
 * @since 7.1.0
 */
class TwoFactorEnrollmentRequiredException extends HttpException
{
    /**
     * @param  string|null  $message  다국어 메시지 키 (기본: auth.two_factor_enrollment_required)
     */
    public function __construct(?string $message = null)
    {
        parent::__construct(401, $message ?? 'auth.two_factor_enrollment_required');
    }
}
