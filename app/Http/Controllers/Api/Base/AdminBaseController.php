<?php

namespace App\Http\Controllers\Api\Base;

/**
 * 관리자용 베이스 컨트롤러
 *
 * 모든 관리자 컨트롤러가 상속받아야 하는 기본 클래스입니다.
 * 관리자 권한 검증, 관리자 전용 기능을 제공합니다.
 */
abstract class AdminBaseController extends BaseApiController
{
    public function __construct()
    {
        // 관리자 인증 및 권한 체크 미들웨어 적용
        $this->middleware(['auth:sanctum', 'admin'])->except(['login']);
    }

    /**
     * 현재 관리자 사용자를 반환합니다.
     *
     * @return \App\Models\User|null
     */
    protected function getCurrentAdmin()
    {
        return $this->getCurrentUser();
    }

    /**
     * 관리자 권한을 확인합니다.
     *
     * @return bool
     */
    protected function isAdmin(): bool
    {
        $user = $this->getCurrentAdmin();
        // TODO: 실제 관리자 권한 체크 로직 구현
        return $user !== null;
    }

}

