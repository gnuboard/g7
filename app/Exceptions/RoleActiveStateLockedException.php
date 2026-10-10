<?php

namespace App\Exceptions;

use Exception;

/**
 * 사용 여부를 바꿀 수 없는 역할(최고관리자 역할)의 사용 여부 변경 시도 시 발생하는 예외
 *
 * 꺼진 역할의 권한은 즉시 회수되므로(User::permissionGrants), 최고관리자 역할을 끄면
 * 최고관리자를 포함한 운영자 전원이 관리 화면을 잃는다. 최고관리자도 끌 수 없게 막는다.
 */
class RoleActiveStateLockedException extends Exception
{
    /**
     * 사용 여부 잠금 위반 예외를 생성합니다.
     */
    public function __construct()
    {
        parent::__construct(__('exceptions.role_active_state_locked'));
    }
}
