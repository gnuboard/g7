<?php

namespace App\Exceptions;

use Exception;

/**
 * 관리자 화면에서 운영자가 자기 계정을 삭제하려 할 때 발생하는 예외
 *
 * 관리 화면을 쓰던 세션이 스스로 사라지는 것을 막는다. 탈퇴는 마이페이지 경로로만 한다.
 */
class CannotDeleteSelfException extends Exception
{
    /**
     * 본인 계정 삭제 시도 예외를 생성합니다.
     */
    public function __construct()
    {
        parent::__construct(__('exceptions.cannot_delete_self'));
    }
}
