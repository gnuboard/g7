<?php

namespace App\Exceptions;

use Exception;

class ExtensionOwnedRoleDeleteException extends Exception
{
    /**
     * 확장이 소유한 역할 삭제 시도 시 예외를 생성합니다.
     */
    public function __construct()
    {
        parent::__construct(__('role.errors.extension_owned_role_delete'));
    }
}
