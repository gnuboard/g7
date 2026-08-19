<?php

namespace App\Exceptions;

use Exception;

/**
 * 보호된 역할(코어/확장 소유) 수정 시도 시 발생하는 예외
 *
 * 삭제 경로에만 있던 코어/확장 소유 역할 보호를 수정·상태변경 경로까지 대칭
 * 적용하기 위한 예외입니다. 비-슈퍼관리자 액터는 `admin` 등 코어/확장 소유
 * 역할의 권한·활성상태를 변경할 수 없습니다(KVE-2026-1919).
 */
class CannotModifyProtectedRoleException extends Exception
{
    /**
     * 보호된 역할 수정 시도 시 예외를 생성합니다.
     */
    public function __construct()
    {
        parent::__construct(__('exceptions.cannot_modify_protected_role'));
    }
}
