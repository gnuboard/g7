<?php

namespace Plugins\Sirsoft\Totp\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 이미 확정된 자격증명이 있는데 새 등록을 시작하려 했을 때 발생.
 *
 * 덮어쓰면 기존 인증 앱이 조용히 무효가 되고 사용자는 다음 로그인에서야 그 사실을 안다 —
 * 바꾸려면 해제를 먼저 요구한다.
 *
 * @since 1.0.0
 */
class AlreadyEnrolledException extends RuntimeException
{
    /**
     * @param  int  $userId  대상 사용자 ID
     * @param  Throwable|null  $previous  체인 예외
     */
    public function __construct(
        public readonly int $userId,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            __('sirsoft-totp::exceptions.already_enrolled'),
            0,
            $previous,
        );
    }
}
