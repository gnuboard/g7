<?php

namespace App\Exceptions;

use Exception;

/**
 * 슈퍼 관리자 삭제 시도 시 발생하는 예외
 *
 * 슈퍼 관리자는 시스템에서 삭제할 수 없으며,
 * 이 예외는 삭제 시도를 방지합니다.
 */
class CannotDeleteSuperAdminException extends Exception
{
    /**
     * 슈퍼 관리자 삭제 시도 시 예외를 생성합니다.
     */
    public function __construct()
    {
        parent::__construct(__('exceptions.cannot_delete_super_admin'));
    }
}
