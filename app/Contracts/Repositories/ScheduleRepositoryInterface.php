<?php

namespace App\Contracts\Repositories;

use App\Models\Schedule;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ScheduleRepositoryInterface
{
    /**
     * ID로 스케줄을 찾습니다.
     *
     * @param int $id 스케줄 ID
     * @return Schedule|null 찾은 스케줄 모델 또는 null
     */
    public function findById(int $id): ?Schedule;

    /**
     * 새로운 스케줄을 생성합니다.
     *
     * @param array $data 스케줄 생성 데이터
     * @return Schedule 생성된 스케줄 모델
     */
    public function create(array $data): Schedule;

    /**
     * 기존 스케줄을 업데이트합니다.
     *
     * @param Schedule $schedule 업데이트할 스케줄 모델
     * @param array $data 업데이트할 데이터
     * @return bool 업데이트 성공 여부
     */
    public function update(Schedule $schedule, array $data): bool;

    /**
     * 스케줄을 삭제합니다.
     *
     * @param Schedule $schedule 삭제할 스케줄 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(Schedule $schedule): bool;

    /**
     * 모든 스케줄을 조회합니다.
     *
     * @return Collection 스케줄 컬렉션
     */
    public function getAll(): Collection;

    /**
     * 필터링 및 페이지네이션이 적용된 스케줄 목록을 조회합니다.
     *
     * @param array $filters 필터 조건 배열
     * @return LengthAwarePaginator 페이지네이션된 스케줄 목록
     */
    public function getPaginatedSchedules(array $filters = []): LengthAwarePaginator;

    /**
     * 스케줄 관련 통계 정보를 조회합니다.
     *
     * @return array 스케줄 통계 데이터 배열
     */
    public function getStatistics(): array;

    /**
     * 활성화된 스케줄들을 조회합니다.
     *
     * @return Collection 활성화된 스케줄 컬렉션
     */
    public function getActiveSchedules(): Collection;

    /**
     * 실행 대기 중인 스케줄들을 조회합니다.
     *
     * @return Collection 실행 대기 중인 스케줄 컬렉션
     */
    public function getDueSchedules(): Collection;

    /**
     * 여러 스케줄의 상태를 일괄 업데이트합니다.
     *
     * @param array $ids 스케줄 ID 배열
     * @param bool $isActive 활성화 여부
     * @return int 업데이트된 레코드 수
     */
    public function bulkUpdateStatus(array $ids, bool $isActive): int;

    /**
     * 여러 스케줄을 일괄 삭제합니다.
     *
     * @param array $ids 스케줄 ID 배열
     * @return int 삭제된 레코드 수
     */
    public function bulkDelete(array $ids): int;

    /**
     * 스케줄을 복제합니다.
     *
     * @param Schedule $schedule 복제할 스케줄
     * @return Schedule 복제된 스케줄
     */
    public function duplicate(Schedule $schedule): Schedule;

    /**
     * ID 목록으로 스케줄들을 조회하고 ID 키 맵으로 반환합니다.
     *
     * Bulk activity log 처리 시 N+1 회피용 단일 쿼리 진입점.
     *
     * @param  array<int, int>  $ids  스케줄 ID 목록
     * @return Collection<int, Schedule> id => Schedule 매핑
     */
    public function findManyByIdsKeyed(array $ids): Collection;
}
