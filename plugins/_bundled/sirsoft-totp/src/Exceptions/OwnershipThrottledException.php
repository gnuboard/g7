<?php

namespace Plugins\Sirsoft\Totp\Exceptions;

use RuntimeException;

/**
 * 인증 앱 해제·백업 코드 재발급의 코드 확인을 너무 여러 번 실패해 잠시 거부함 (HTTP 429).
 *
 * @since 1.0.3
 */
class OwnershipThrottledException extends RuntimeException
{
    /**
     * @param  int  $retryAfterSeconds  다시 시도할 수 있을 때까지 남은 초
     */
    public function __construct(
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct(__('sirsoft-totp::messages.errors.too_many_attempts', ['seconds' => $retryAfterSeconds]));
    }
}
