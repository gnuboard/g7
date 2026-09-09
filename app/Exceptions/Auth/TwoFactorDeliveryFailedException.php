<?php

namespace App\Exceptions\Auth;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 2단계 인증 코드 발송 실패 예외 — 비밀번호 확인은 통과했으나 인증번호를 보낼 수 없어
 * 로그인을 완료할 수단이 없을 때 발생합니다.
 *
 * HTTP 503 Service Unavailable 로 매핑됩니다. 자격 증명은 올바르므로 401 로 뭉뚱그리면
 * 사용자는 비밀번호를 의심하며 같은 실패를 반복하게 되고, 운영자는 메일 설정이 깨진 사실을
 * 알 방법이 없습니다.
 *
 * 메시지 자리에는 번역문이 아니라 다국어 **키**를 보관합니다 (선례: AccountLockedException).
 *
 * @since 7.0.11
 */
class TwoFactorDeliveryFailedException extends HttpException
{
    public function __construct(?string $message = null)
    {
        parent::__construct(503, $message ?? 'auth.two_factor_delivery_failed');
    }
}
