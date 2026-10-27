<?php

namespace Plugins\Sirsoft\Sso\Services\Admin;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\AdminLogRepositoryInterface;

/**
 * 관리자 「이력」 조회 — 로그인 시도, SCIM 수신.
 *
 * @since 1.0.0
 */
class LogAdminService
{
    /**
     * @param  AdminLogRepositoryInterface  $logs  이력 저장소
     */
    public function __construct(private readonly AdminLogRepositoryInterface $logs) {}

    /**
     * 로그인 시도 목록.
     *
     * @param  array<string, mixed>  $filters  조건
     * @return LengthAwarePaginator 페이지
     */
    public function attempts(array $filters): LengthAwarePaginator
    {
        return $this->logs->attempts($filters);
    }

    /**
     * SCIM 수신 이력 목록.
     *
     * @param  array<string, mixed>  $filters  조건
     * @return LengthAwarePaginator 페이지
     */
    public function scimEvents(array $filters): LengthAwarePaginator
    {
        return $this->logs->scimEvents($filters);
    }
}
