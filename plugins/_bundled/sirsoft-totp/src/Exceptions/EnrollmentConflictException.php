<?php

namespace Plugins\Sirsoft\Totp\Exceptions;

use RuntimeException;

/**
 * 다른 화면(요청)이 등록 확정을 먼저 끝냈거나 그 사이 시크릿이 새로 발급돼 이번 확정을 저장하지 않았을 때 발생 (HTTP 409).
 *
 * 두 확정이 모두 성공하면 먼저 보인 백업 코드가 나중 저장에 덮여 쓸 수 없게 된다(KVE 동형 L3).
 *
 * @since 1.0.3
 */
class EnrollmentConflictException extends RuntimeException
{
    /**
     * 예외를 생성합니다.
     */
    public function __construct()
    {
        parent::__construct(__('sirsoft-totp::messages.errors.confirm_conflict'));
    }
}
