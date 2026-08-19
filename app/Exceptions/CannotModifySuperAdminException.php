<?php

namespace App\Exceptions;

use Exception;

/**
 * 비-슈퍼관리자 액터가 슈퍼 관리자 계정/역할을 수정하려 할 때 발생하는 예외
 *
 * 삭제 경로에만 있던 슈퍼 관리자 보호 가드를 수정·상태변경·권한부여 경로까지
 * 대칭 적용하기 위한 등급 상한(rank ceiling) 위반 예외입니다.
 */
class CannotModifySuperAdminException extends Exception
{
    /**
     * 슈퍼 관리자 수정 시도 시 예외를 생성합니다.
     */
    public function __construct()
    {
        parent::__construct(__('exceptions.cannot_modify_super_admin'));
    }
}
