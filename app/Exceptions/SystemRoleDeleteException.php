<?php

namespace App\Exceptions;

use Exception;

class SystemRoleDeleteException extends Exception
{
    /**
     * 시스템 역할 삭제 시도 시 예외를 생성합니다.
     */
    public function __construct()
    {
        parent::__construct(__('role.errors.system_role_delete'));
    }
}
