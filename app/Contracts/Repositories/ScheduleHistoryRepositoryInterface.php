<?php

namespace App\Contracts\Repositories;

use App\Models\ScheduleHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ScheduleHistoryRepositoryInterface
{
    /**
     * ID로 실행 이력을 찾습니다.
     *
     * @param int $id 이력 ID
     * @return ScheduleHistory|null 찾은 이력 모델 또는 null
     */
    public function findById(int $id): ?ScheduleHistory;

    /**
     * 새로운 실행 이력을 생성합니다.
     *
     * @param array $data 이력 생성 데이터
     * @return ScheduleHistory 생성된 이력 모델
     */
    public function create(array $data): ScheduleHistory;

    /**
     * 기존 실행 이력을 업데이트합니다.
     *
     * @param ScheduleHistory $history 업데이트할 이력 모델
     * @param array $data 업데이트할 데이터
     * @return bool 업데이트 성공 여부
     */
    public function update(ScheduleHistory $history, array $data): bool;

    /**
     * 실행 이력을 삭제합니다.
     *
     * @param ScheduleHistory $history 삭제할 이력 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(ScheduleHistory $history): bool;

    /**
     * 특정 스케줄의 실행 이력을 페이지네이션하여 조회합니다.
     *
     * @param int $scheduleId 스케줄 ID
     * @param array $filters 필터 조건 배열
     * @return LengthAwarePaginator 페이지네이션된 이력 목록
     */
    public function getPaginatedByScheduleId(int $scheduleId, array $filters = []): LengthAwarePaginator;

    /**
     * 특정 스케줄의 최근 실행 이력을 조회합니다.
     *
     * @param int $scheduleId 스케줄 ID
     * @param int $limit 조회 개수
     * @return Collection 최근 이력 컬렉션
     */
    public function getRecentByScheduleId(int $scheduleId, int $limit = 10): Collection;

    /**
     * 여러 이력을 일괄 삭제합니다.
     *
     * @param array $ids 이력 ID 배열
     * @return int 삭제된 레코드 수
     */
    public function bulkDelete(array $ids): int;

    /**
     * 특정 스케줄의 모든 이력을 삭제합니다.
     *
     * @param int $scheduleId 스케줄 ID
     * @return int 삭제된 레코드 수
     */
    public function deleteByScheduleId(int $scheduleId): int;

    /**
     * 특정 기간 이전의 이력을 삭제합니다.
     *
     * @param int $days 보관 기간 (일)
     * @return int 삭제된 레코드 수
     */
    public function deleteOlderThan(int $days): int;
}
