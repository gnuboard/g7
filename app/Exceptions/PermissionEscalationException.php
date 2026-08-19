<?php

namespace App\Exceptions;

use Exception;

/**
 * 권한 상승(escalation) 시도 시 발생하는 예외
 *
 * 비-슈퍼관리자 액터가 역할에 자신이 보유하지 않은 권한, 또는 자신의 범위(scope)보다
 * 넓은 범위의 권한을 부여하려 할 때 발생합니다(KVE-2026-1919 권한 상승 차단).
 */
class PermissionEscalationException extends Exception
{
    /**
     * 권한 상승 시도 시 예외를 생성합니다.
     */
    public function __construct()
    {
        parent::__construct(__('exceptions.cannot_grant_unheld_permission'));
    }
}
