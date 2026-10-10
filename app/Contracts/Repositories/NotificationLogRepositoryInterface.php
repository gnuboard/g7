<?php

namespace App\Contracts\Repositories;

use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;

interface NotificationLogRepositoryInterface
{
    /**
     * 최근 발송된 알림 로그를 발송 시각 최신순으로 조회합니다 (대시보드 최근 알림).
     *
     * @param  int  $limit  조회 건수
     * @param  User|null  $scopeUser  스코프 적용 대상 사용자 (null이면 스코프 미적용)
     * @return Collection<int, NotificationLog> 최근 알림 로그 컬렉션
     */
    public function getRecent(int $limit, ?User $scopeUser = null): Collection;

    /**
     * ID로 알림 로그 조회.
     *
     * @param  int  $id  알림 로그 ID
     * @return NotificationLog|null 알림 로그 또는 null
     */
    public function findById(int $id): ?NotificationLog;

    /**
     * 알림 로그 생성.
     *
     * @param  array<string, mixed>  $data  생성 데이터
     * @return NotificationLog 생성된 알림 로그
     */
    public function create(array $data): NotificationLog;

    /**
     * 알림 로그 삭제.
     *
     * @param  NotificationLog  $log  삭제할 알림 로그
     * @return bool 삭제 성공 여부
     */
    public function delete(NotificationLog $log): bool;

    /**
     * 다건 삭제.
     *
     * @param  array<int, int>  $ids  삭제할 로그 ID 목록
     * @return int 삭제된 건수
     */
    public function bulkDelete(array $ids): int;

    /**
     * 보존 기간이 지난 발송 이력을 삭제합니다.
     *
     * @param  int  $days  보존 기간 (일)
     * @return int 삭제된 건수
     */
    public function deleteOlderThan(int $days): int;

    /**
     * 페이지네이션 목록 조회.
     *
     * @param  array<string, mixed>  $filters  필터 조건
     * @param  int  $perPage  페이지당 건수
     * @param  User|null  $scopeUser  스코프 적용 대상 사용자 (null이면 스코프 미적용)
     * @return LengthAwarePaginator|CursorPaginator 페이지네이션 결과 (커서 요청 시 키셋)
     */
    public function getPaginated(array $filters = [], int $perPage = 20, ?User $scopeUser = null): LengthAwarePaginator|CursorPaginator;
}
