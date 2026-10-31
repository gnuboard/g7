<?php

namespace App\Contracts\Repositories;

use App\Enums\ExtensionOwnerType;
use App\Models\Schedule;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

interface ScheduleRepositoryInterface
{
    /**
     * 「직접 등록」(운영자가 화면에서 만든 행, extension_type 이 null) 소유 타입 값.
     *
     * 소유 필터 쿼리(`extension_type=custom`)·owners API 항목·Resource `owner.type` 이 같은 값을 쓴다.
     */
    public const OWNER_TYPE_CUSTOM = 'custom';

    /**
     * ID로 스케줄을 찾습니다.
     *
     * @param  int  $id  스케줄 ID
     * @return Schedule|null 찾은 스케줄 모델 또는 null
     */
    public function findById(int $id): ?Schedule;

    /**
     * 새로운 스케줄을 생성합니다.
     *
     * @param  array  $data  스케줄 생성 데이터
     * @return Schedule 생성된 스케줄 모델
     */
    public function create(array $data): Schedule;

    /**
     * 기존 스케줄을 업데이트합니다.
     *
     * @param  Schedule  $schedule  업데이트할 스케줄 모델
     * @param  array  $data  업데이트할 데이터
     * @return bool 업데이트 성공 여부
     */
    public function update(Schedule $schedule, array $data): bool;

    /**
     * 스케줄을 삭제합니다.
     *
     * @param  Schedule  $schedule  삭제할 스케줄 모델
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
     * @param  array  $filters  필터 조건 배열
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
     * 여러 스케줄의 상태를 일괄 업데이트합니다.
     *
     * @param  array  $ids  스케줄 ID 배열
     * @param  bool  $isActive  활성화 여부
     * @return int 업데이트된 레코드 수
     */
    public function bulkUpdateStatus(array $ids, bool $isActive): int;

    /**
     * 여러 스케줄을 일괄 삭제합니다.
     *
     * @param  array  $ids  스케줄 ID 배열
     * @return int 삭제된 레코드 수
     */
    public function bulkDelete(array $ids): int;

    /**
     * 스케줄을 복제합니다.
     *
     * @param  Schedule  $schedule  복제할 스케줄
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

    /**
     * 소유자·이름으로 코어·확장 소유 스케줄 1건을 찾습니다.
     *
     * @param  ExtensionOwnerType  $type  소유 타입 (core/module/plugin)
     * @param  string  $identifier  소유 식별자 (코어는 'core')
     * @param  string  $name  스케줄 기계 식별자
     * @return Schedule|null 찾은 스케줄 또는 null
     */
    public function findOwned(ExtensionOwnerType $type, string $identifier, string $name): ?Schedule;

    /**
     * 소유자별 스케줄 수를 집계합니다 (직접 등록 행은 extension_type·extension_identifier 가 null 인 1행).
     *
     * @return SupportCollection<int, object{extension_type: string|null, extension_identifier: string|null, schedule_count: int}> 소유자별 건수
     */
    public function countByOwner(): SupportCollection;

    /**
     * 실행 상태 컬럼만 모델 이벤트 없이 갱신합니다.
     *
     * 실행기가 매 실행마다 last_result·last_run_at·next_run_at 를 쓰므로 변경 추적·활동 로그를 타지 않게 한다.
     *
     * @param  int  $id  스케줄 ID
     * @param  array<string, mixed>  $data  갱신할 컬럼 (last_result, last_run_at, next_run_at)
     * @return int 갱신된 행 수
     */
    public function updateRunState(int $id, array $data): int;

    /**
     * 스케줄 행의 name 만 모델 이벤트 없이 바꿉니다.
     *
     * 확장 선언을 옛 형식에서 새 형식으로 옮길 때 옛 자동 이름 행을 새 name 으로 이어받는 데 쓴다 — 운영자 수정 표식·
     * 다음 실행 시각을 건드리지 않아야 하므로 이벤트를 타지 않는다.
     *
     * @param  int  $id  스케줄 ID
     * @param  string  $name  새 name
     * @return int 갱신된 행 수
     */
    public function rename(int $id, string $name): int;
}
