<?php

namespace App\Exceptions;

use Exception;

/**
 * 관리자 계정 삭제 시도 시 발생하는 예외
 *
 * 관리자 역할을 가진 계정은 시스템에서 삭제할 수 없으며,
 * 이 예외는 삭제 시도를 방지합니다.
 */
class CannotDeleteAdminException extends Exception
{
    /**
     * 관리자 계정 삭제 시도 시 예외를 생성합니다.
     */
    public function __construct()
    {
        parent::__construct(__('user.delete_admin_forbidden'));
    }
}
