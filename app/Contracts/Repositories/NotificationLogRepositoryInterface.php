<?php

namespace App\Contracts\Repositories;

use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

interface NotificationLogRepositoryInterface
{
    /**
     * ID로 알림 로그 조회.
     */
    public function findById(int $id): ?NotificationLog;

    /**
     * 알림 로그 생성.
     */
    public function create(array $data): NotificationLog;

    /**
     * 알림 로그 삭제.
     */
    public function delete(NotificationLog $log): bool;

    /**
     * 다건 삭제.
     *
     * @return int 삭제된 건수
     */
    public function bulkDelete(array $ids): int;

    /**
     * 페이지네이션 목록 조회.
     *
     * @param  User|null  $scopeUser  스코프 적용 대상 사용자 (null이면 스코프 미적용)
     */
    public function getPaginated(array $filters = [], int $perPage = 20, ?User $scopeUser = null): LengthAwarePaginator;
}
