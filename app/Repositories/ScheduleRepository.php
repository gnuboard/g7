<?php

namespace App\Repositories;

use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\ScheduleDispatchMode;
use App\Enums\ScheduleFrequency;
use App\Enums\ScheduleHistoryMode;
use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleType;
use App\Helpers\PermissionHelper;
use App\Models\Schedule;
use App\Repositories\Concerns\FiltersByDateRange;
use App\Repositories\Concerns\HasMultipleSearchFilters;
use App\Repositories\Concerns\ResolvesSortSpec;
use App\Search\KeywordSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class ScheduleRepository implements ScheduleRepositoryInterface
{
    use FiltersByDateRange;
    use HasMultipleSearchFilters;
    use ResolvesSortSpec;

    /**
     * 검색 가능한 필드 목록
     */
    private const SEARCHABLE_FIELDS = ['name', 'description', 'command'];

    /**
     * 허용 정렬 컬럼 (ScheduleListRequest 와 동일 집합)
     */
    private const SORTABLE_COLUMNS = [
        'created_at',
        'name',
        'next_run_at',
        'last_run_at',
        'is_active',
        'last_result',
    ];

    /**
     * ID로 스케줄을 찾습니다.
     *
     * @param  int  $id  스케줄 ID
     * @return Schedule|null 찾은 스케줄 모델 또는 null
     */
    public function findById(int $id): ?Schedule
    {
        return Schedule::with('creator')->find($id);
    }

    /**
     * 새로운 스케줄을 생성합니다.
     *
     * @param  array  $data  스케줄 생성 데이터
     * @return Schedule 생성된 스케줄 모델
     */
    public function create(array $data): Schedule
    {
        return Schedule::create($data);
    }

    /**
     * 기존 스케줄을 업데이트합니다.
     *
     * @param  Schedule  $schedule  업데이트할 스케줄 모델
     * @param  array  $data  업데이트할 데이터
     * @return bool 업데이트 성공 여부
     */
    public function update(Schedule $schedule, array $data): bool
    {
        return $schedule->update($data);
    }

    /**
     * 스케줄을 삭제합니다.
     *
     * @param  Schedule  $schedule  삭제할 스케줄 모델
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
     * @param  array  $filters  필터 조건 배열
     * @return LengthAwarePaginator 페이지네이션된 스케줄 목록
     */
    public function getPaginatedSchedules(array $filters = []): LengthAwarePaginator
    {
        $query = Schedule::query()->with('creator');

        // 권한 스코프 필터링
        PermissionHelper::applyPermissionScope($query, 'core.schedules.read');

        // 필터 조건 적용
        $this->applyFilters($query, $filters);

        // 정렬 적용 (허용 컬럼 화이트리스트로 해석)
        $sortSpec = $this->resolveSortSpec($filters, self::SORTABLE_COLUMNS, 'created_at');
        foreach ($sortSpec as $sort) {
            $query->orderBy($sort['column'], $sort['direction']);
        }

        // 같은 값끼리의 순서를 id 로 고정한다 — 확장 설치가 스케줄 여러 개를 같은 초에 등록하므로
        // created_at 만으로 정렬하면 쪽마다 순서가 달라져 2쪽이 1쪽 행을 다시 보이고 일부 행이 빠졌다.
        $query->orderBy('id', $sortSpec[0]['direction'] ?? 'desc');

        // 페이지네이션 적용
        $perPage = $filters['per_page'] ?? 15;

        // audit:allow repository-paginate-column-pruning reason: 스케줄 정의 테이블 — 등록된 스케줄 수만큼만 존재하고 넓은 컬럼이 없다
        return $query->paginate($perPage);
    }

    /**
     * 쿼리에 필터 조건을 적용합니다.
     *
     * 라디오 「전체」는 값 `all` 로 오므로 빈 값과 같이 조건을 걸지 않는다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  array  $filters  적용할 필터 조건 배열
     * @return void
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        // 다중 검색 조건 적용 (7.2.0 이전 API 호환)
        if (! empty($filters['filters']) && is_array($filters['filters'])) {
            $this->applyMultipleSearchFilters($query, $filters['filters'], self::SEARCHABLE_FIELDS);
        }

        $this->applyKeywordFilter($query, $filters['search_field'] ?? null, $filters['search_keyword'] ?? null);
        $this->applyOwnerFilter($query, $filters['extension_type'] ?? null, $filters['extension_identifier'] ?? null);

        if ($type = ScheduleType::tryFrom((string) $this->filterValue($filters, 'type'))) {
            $query->where('type', $type);
        }

        if ($frequency = ScheduleFrequency::tryFrom((string) $this->filterValue($filters, 'frequency'))) {
            $query->where('frequency', $frequency);
        }

        $status = $this->filterValue($filters, 'status');
        if ($status === 'active' || $status === 'inactive') {
            $query->where('is_active', $status === 'active');
        }

        if ($result = ScheduleResultStatus::tryFrom((string) $this->filterValue($filters, 'last_result'))) {
            $query->where('last_result', $result);
        }

        if ($mode = ScheduleDispatchMode::tryFrom((string) $this->filterValue($filters, 'dispatch_mode'))) {
            $query->where('dispatch_mode', $mode);
        }

        // 이력 기록 방식은 Enum 값에 `all` 이 있어 와일드카드로 보지 않는다(「전체」는 빈 값)
        if ($historyMode = ScheduleHistoryMode::tryFrom((string) ($filters['history_mode'] ?? ''))) {
            $query->where('history_mode', $historyMode);
        }

        // 중복 실행 방지 — 잠금 유지 분(0 = 끔)
        $overlap = $this->filterValue($filters, 'overlap');
        if ($overlap === 'on') {
            $query->where('without_overlapping', '>', 0);
        } elseif ($overlap === 'off') {
            $query->where('without_overlapping', 0);
        }

        // 점검 모드 실행 필터
        if (isset($filters['run_in_maintenance']) && $filters['run_in_maintenance'] !== '') {
            $query->where('run_in_maintenance', (bool) $filters['run_in_maintenance']);
        }

        // 날짜 필터
        $this->applyDateFilters($query, $filters);
    }

    /**
     * 필터 값을 꺼냅니다 — 빈 값과 `all`(라디오 「전체」)은 null 로 본다.
     *
     * @param  array  $filters  필터 조건 배열
     * @param  string  $key  필터 키
     * @return string|null 조건 값 또는 null
     */
    private function filterValue(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return ($value === null || $value === '' || $value === 'all') ? null : (string) $value;
    }

    /**
     * 검색대상·키워드 조건을 적용합니다.
     *
     * - name: 표시 명칭(label 의 각 번역) 또는 이름
     * - identifier: 기계 식별자(name)
     * - command: 명령
     * - all(또는 미지정): 위 셋 + 설명
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  string|null  $field  검색대상
     * @param  string|null  $keyword  키워드
     * @return void
     */
    private function applyKeywordFilter(Builder $query, ?string $field, ?string $keyword): void
    {
        $keyword = trim((string) $keyword);
        if ($keyword === '') {
            return;
        }

        $like = '%'.KeywordSearch::escapeLikeWildcards($keyword).'%';
        $locales = (array) config('app.translatable_locales', ['ko', 'en']);
        $field = in_array($field, ['name', 'identifier', 'command'], true) ? $field : 'all';

        $query->where(function (Builder $q) use ($field, $like, $locales) {
            if ($field === 'name' || $field === 'all') {
                foreach ($locales as $locale) {
                    $q->orWhere("label->{$locale}", 'like', $like);
                }
                $q->orWhere('name', 'like', $like);
            }

            if ($field === 'identifier') {
                $q->orWhere('name', 'like', $like);
            }

            if ($field === 'command' || $field === 'all') {
                $q->orWhere('command', 'like', $like);
            }

            if ($field === 'all') {
                foreach ($locales as $locale) {
                    $q->orWhere("description->{$locale}", 'like', $like);
                }
            }
        });
    }

    /**
     * 소유자 조건을 적용합니다 — `custom` 은 직접 등록(extension_type 이 null)이다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  string|null  $type  소유 타입 (core/module/plugin/custom/all)
     * @param  string|null  $identifier  소유 식별자 (모듈·플러그인일 때만 의미가 있다)
     * @return void
     */
    private function applyOwnerFilter(Builder $query, ?string $type, ?string $identifier): void
    {
        if ($type === null || $type === '' || $type === 'all') {
            return;
        }

        if ($type === self::OWNER_TYPE_CUSTOM) {
            $query->whereNull('extension_type');

            return;
        }

        $ownerType = ExtensionOwnerType::tryFrom($type);
        if ($ownerType === null) {
            return;
        }

        $query->where('extension_type', $ownerType);

        if ($ownerType !== ExtensionOwnerType::Core && $identifier !== null && $identifier !== '') {
            $query->where('extension_identifier', $identifier);
        }
    }

    /**
     * 날짜 필터를 적용합니다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  array  $filters  필터 조건 배열
     * @return void
     */
    private function applyDateFilters(Builder $query, array $filters): void
    {
        // whereDate 는 컬럼에 DATE() 를 씌워 인덱스를 무력화한다 — 범위 조건으로 준다.
        // 목록은 등록일을 보는 사람의 시간대로 그린다 — 같은 하루로 자른다.
        $this->applyViewerDateRangeFilter(
            $query,
            'created_at',
            // created_from/created_to 는 7.2.0 이전 키 — 호출 호환용으로 함께 받는다
            $filters['created_start_date'] ?? $filters['created_from'] ?? null,
            $filters['created_end_date'] ?? $filters['created_to'] ?? null
        );
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
     * 여러 스케줄의 상태를 일괄 업데이트합니다.
     *
     * @param  array  $ids  스케줄 ID 배열
     * @param  bool  $isActive  활성화 여부
     * @return int 업데이트된 레코드 수
     */
    public function bulkUpdateStatus(array $ids, bool $isActive): int
    {
        return Schedule::whereIn('id', $ids)->update(['is_active' => $isActive]);
    }

    /**
     * 여러 스케줄을 일괄 삭제합니다.
     *
     * @param  array  $ids  스케줄 ID 배열
     * @return int 삭제된 레코드 수
     */
    public function bulkDelete(array $ids): int
    {
        return Schedule::whereIn('id', $ids)->delete();
    }

    /**
     * 스케줄을 복제합니다.
     *
     * @param  Schedule  $schedule  복제할 스케줄
     * @return Schedule 복제된 스케줄
     */
    public function duplicate(Schedule $schedule): Schedule
    {
        $newSchedule = $schedule->replicate();
        $newSchedule->name = $schedule->name.' ('.__('schedule.copy').')';
        // 복제본은 언제나 「직접 등록」이다 — 소유자를 그대로 두면 확장 행처럼 잠기고, 다음 동기화의
        // cleanupStale 이 선언에 없는 행으로 보고 지운다. 조건·수정 기록도 원본 소유자의 것이라 버린다.
        $newSchedule->extension_type = null;
        $newSchedule->extension_identifier = null;
        $newSchedule->enabled_condition = null;
        $newSchedule->user_overrides = null;
        $newSchedule->label = collect($schedule->label ?: [app()->getLocale() => $schedule->name])
            ->map(fn ($text, $locale) => $text.' ('.__('schedule.copy', [], $locale).')')
            ->all();
        $newSchedule->is_active = false;
        $newSchedule->last_result = ScheduleResultStatus::Never;
        $newSchedule->last_run_at = null;
        $newSchedule->save();

        return $newSchedule;
    }

    /**
     * ID 목록으로 스케줄들을 조회하고 ID 키 맵으로 반환합니다.
     *
     * @param  array<int, int>  $ids  스케줄 ID 목록
     * @return Collection<int, Schedule> id => Schedule 매핑
     */
    public function findManyByIdsKeyed(array $ids): Collection
    {
        return Schedule::whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * 소유자·이름으로 코어·확장 소유 스케줄 1건을 찾습니다.
     *
     * @param  ExtensionOwnerType  $type  소유 타입 (core/module/plugin)
     * @param  string  $identifier  소유 식별자 (코어는 'core')
     * @param  string  $name  스케줄 기계 식별자
     * @return Schedule|null 찾은 스케줄 또는 null
     */
    public function findOwned(ExtensionOwnerType $type, string $identifier, string $name): ?Schedule
    {
        return Schedule::ownedBy($type, $identifier)->where('name', $name)->first();
    }

    /**
     * 소유자별 스케줄 수를 집계합니다 (직접 등록 행은 extension_type·extension_identifier 가 null 인 1행).
     *
     * @return SupportCollection<int, object{extension_type: string|null, extension_identifier: string|null, schedule_count: int}> 소유자별 건수
     */
    public function countByOwner(): SupportCollection
    {
        return Schedule::query()
            ->toBase()
            ->select('extension_type', 'extension_identifier')
            ->selectRaw('COUNT(*) as schedule_count')
            ->groupBy('extension_type', 'extension_identifier')
            ->get();
    }

    /**
     * 실행 상태 컬럼만 모델 이벤트 없이 갱신합니다.
     *
     * @param  int  $id  스케줄 ID
     * @param  array<string, mixed>  $data  갱신할 컬럼 (last_result, last_run_at, next_run_at)
     * @return int 갱신된 행 수
     */
    public function updateRunState(int $id, array $data): int
    {
        return Schedule::query()->whereKey($id)->update($data);
    }

    /**
     * 스케줄 행의 name 만 모델 이벤트 없이 바꿉니다.
     *
     * @param  int  $id  스케줄 ID
     * @param  string  $name  새 name
     * @return int 갱신된 행 수
     */
    public function rename(int $id, string $name): int
    {
        return Schedule::query()->whereKey($id)->update(['name' => $name]);
    }
}
