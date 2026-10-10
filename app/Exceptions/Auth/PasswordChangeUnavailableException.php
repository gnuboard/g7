<?php

namespace App\Exceptions\Auth;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 로그인 비밀번호 변경 단계 식별자를 발급하지 못했을 때 발생합니다 (캐시 쓰기 실패).
 *
 * HTTP 503 Service Unavailable 로 매핑됩니다. 자격 증명은 올바르고 비밀번호도 바꿔야 하는데 단계를
 * 열 수 없는 상태다 — 토큰을 그냥 주면 만료·임시 비밀번호 강제가 조용히 열리므로 로그인을 막는다.
 *
 * 메시지 자리에는 번역문이 아니라 다국어 **키**를 보관합니다 (선례: TwoFactorDeliveryFailedException).
 *
 * @since 7.1.0
 */
class PasswordChangeUnavailableException extends HttpException
{
    /**
     * @param  string|null  $message  다국어 키 (기본 auth.password_change_unavailable)
     */
    public function __construct(?string $message = null)
    {
        parent::__construct(503, $message ?? 'auth.password_change_unavailable');
    }
}
