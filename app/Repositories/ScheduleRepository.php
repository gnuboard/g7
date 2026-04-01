<?php

namespace App\Repositories;

use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Helpers\PermissionHelper;
use App\Enums\ScheduleFrequency;
use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleType;
use App\Models\Schedule;
use App\Repositories\Concerns\HasMultipleSearchFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ScheduleRepository implements ScheduleRepositoryInterface
{
    use HasMultipleSearchFilters;

    /**
     * 검색 가능한 필드 목록
     */
    private const SEARCHABLE_FIELDS = ['name', 'description', 'command'];

    /**
     * ID로 스케줄을 찾습니다.
     *
     * @param int $id 스케줄 ID
     * @return Schedule|null 찾은 스케줄 모델 또는 null
     */
    public function findById(int $id): ?Schedule
    {
        return Schedule::with('creator')->find($id);
    }

    /**
     * 새로운 스케줄을 생성합니다.
     *
     * @param array $data 스케줄 생성 데이터
     * @return Schedule 생성된 스케줄 모델
     */
    public function create(array $data): Schedule
    {
        return Schedule::create($data);
    }

    /**
     * 기존 스케줄을 업데이트합니다.
     *
     * @param Schedule $schedule 업데이트할 스케줄 모델
     * @param array $data 업데이트할 데이터
     * @return bool 업데이트 성공 여부
     */
    public function update(Schedule $schedule, array $data): bool
    {
        return $schedule->update($data);
    }

    /**
     * 스케줄을 삭제합니다.
     *
     * @param Schedule $schedule 삭제할 스케줄 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(Schedule $schedule): bool
    {
        return $schedule->delete();
    }

    /**
     * 모든 스케줄을 조회합니다.
     *
     * @return Collection 스케줄 컬렉션
     */
    public function getAll(): Collection
    {
        return Schedule::with('creator')->get();
    }

    /**
     * 필터링 및 페이지네이션이 적용된 스케줄 목록을 조회합니다.
     *
     * @param array $filters 필터 조건 배열
     * @return LengthAwarePaginator 페이지네이션된 스케줄 목록
     */
    public function getPaginatedSchedules(array $filters = []): LengthAwarePaginator
    {
        $query = Schedule::query()->with('creator');

        // 권한 스코프 필터링
        PermissionHelper::applyPermissionScope($query, 'core.schedules.read');

        // 필터 조건 적용
        $this->applyFilters($query, $filters);

        // 정렬 적용
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        // 페이지네이션 적용
        $perPage = $filters['per_page'] ?? 15;

        return $query->paginate($perPage);
    }

    /**
     * 쿼리에 필터 조건을 적용합니다.
     *
     * @param Builder $query Eloquent 쿼리 빌더
     * @param array $filters 적용할 필터 조건 배열
     * @return void
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        // 다중 검색 조건 적용
        if (!empty($filters['filters']) && is_array($filters['filters'])) {
            $this->applyMultipleSearchFilters($query, $filters['filters'], self::SEARCHABLE_FIELDS);
        }

        // 타입 필터
        if (!empty($filters['type'])) {
            $type = ScheduleType::tryFrom($filters['type']);
            if ($type) {
                $query->where('type', $type);
            }
        }

        // 주기 필터
        if (!empty($filters['frequency'])) {
            $frequency = ScheduleFrequency::tryFrom($filters['frequency']);
            if ($frequency) {
                $query->where('frequency', $frequency);
            }
        }

        // 상태 필터
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'active') {
                $query->where('is_active', true);
            } elseif ($filters['status'] === 'inactive') {
                $query->where('is_active', false);
            }
        }

        // 마지막 실행 결과 필터
        if (!empty($filters['last_result'])) {
            $result = ScheduleResultStatus::tryFrom($filters['last_result']);
            if ($result) {
                $query->where('last_result', $result);
            }
        }

        // 중복 실행 방지 필터
        if (isset($filters['without_overlapping']) && $filters['without_overlapping'] !== '') {
            $query->where('without_overlapping', (bool) $filters['without_overlapping']);
        }

        // 점검 모드 실행 필터
        if (isset($filters['run_in_maintenance']) && $filters['run_in_maintenance'] !== '') {
            $query->where('run_in_maintenance', (bool) $filters['run_in_maintenance']);
        }

        // 확장 타입 필터
        if (!empty($filters['extension_type'])) {
            $query->where('extension_type', $filters['extension_type']);

            if (!empty($filters['extension_identifier'])) {
                $query->where('extension_identifier', $filters['extension_identifier']);
            }
        }

        // 날짜 필터
        $this->applyDateFilters($query, $filters);
    }

    /**
     * 날짜 필터를 적용합니다.
     *
     * @param Builder $query Eloquent 쿼리 빌더
     * @param array $filters 필터 조건 배열
     * @return void
     */
    private function applyDateFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['created_from'])) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }

        if (!empty($filters['created_to'])) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }
    }

    /**
     * 스케줄 관련 통계 정보를 조회합니다.
     *
     * @return array 스케줄 통계 데이터 배열
     */
    public function getStatistics(): array
    {
        return [
            'total' => Schedule::count(),
            'active' => Schedule::where('is_active', true)->count(),
            'inactive' => Schedule::where('is_active', false)->count(),
            'success' => Schedule::where('last_result', ScheduleResultStatus::Success)->count(),
            'failed' => Schedule::where('last_result', ScheduleResultStatus::Failed)->count(),
            'running' => Schedule::where('last_result', ScheduleResultStatus::Running)->count(),
            'never_run' => Schedule::where('last_result', ScheduleResultStatus::Never)->count(),
        ];
    }

    /**
     * 활성화된 스케줄들을 조회합니다.
     *
     * @return Collection 활성화된 스케줄 컬렉션
     */
    public function getActiveSchedules(): Collection
    {
        return Schedule::active()->get();
    }

    /**
     * 실행 대기 중인 스케줄들을 조회합니다.
     *
     * @return Collection 실행 대기 중인 스케줄 컬렉션
     */
    public function getDueSchedules(): Collection
    {
        return Schedule::due()->get();
    }

    /**
     * 여러 스케줄의 상태를 일괄 업데이트합니다.
     *
     * @param array $ids 스케줄 ID 배열
     * @param bool $isActive 활성화 여부
     * @return int 업데이트된 레코드 수
     */
    public function bulkUpdateStatus(array $ids, bool $isActive): int
    {
        return Schedule::whereIn('id', $ids)->update(['is_active' => $isActive]);
    }

    /**
     * 여러 스케줄을 일괄 삭제합니다.
     *
     * @param array $ids 스케줄 ID 배열
     * @return int 삭제된 레코드 수
     */
    public function bulkDelete(array $ids): int
    {
        return Schedule::whereIn('id', $ids)->delete();
    }

    /**
     * 스케줄을 복제합니다.
     *
     * @param Schedule $schedule 복제할 스케줄
     * @return Schedule 복제된 스케줄
     */
    public function duplicate(Schedule $schedule): Schedule
    {
        $newSchedule = $schedule->replicate();
        $newSchedule->name = $schedule->name . ' (' . __('schedule.copy') . ')';
        $newSchedule->is_active = false;
        $newSchedule->last_result = ScheduleResultStatus::Never;
        $newSchedule->last_run_at = null;
        $newSchedule->save();

        return $newSchedule;
    }
}
