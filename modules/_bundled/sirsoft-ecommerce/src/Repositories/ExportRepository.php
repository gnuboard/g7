<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Models\User;
use App\Repositories\Concerns\FiltersByDateRange;
use App\Repositories\Concerns\ResolvesSortSpec;
use App\Search\KeywordSearch;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Enums\ExportStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ExportRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Support\ExportAccess;
use Modules\Sirsoft\Ecommerce\Support\MemberDisplayName;

/**
 * 내보내기 작업 Repository
 */
class ExportRepository implements ExportRepositoryInterface
{
    use FiltersByDateRange;
    use ResolvesSortSpec;

    /**
     * 관리 목록에서 허용하는 정렬 컬럼
     */
    public const SORTABLE_COLUMNS = ['requested_at', 'completed_at', 'file_size', 'download_count'];

    /**
     * 관리 목록이 실제로 쓰는 컬럼
     *
     * 실행 컨텍스트(`context`)·중복 방지 키·선택 ID 목록·해시는 목록이 그리지 않는다. 저장 경로(`file_path`)는
     * 화면에 내지 않지만 「내려받을 수 있는가」 판정(`Export::isDownloadable()`)에 필요해 싣는다(모델 hidden).
     * 작업 수는 요청 횟수만큼만 늘어 OFFSET 이 커지지 않으므로 지연 조인은 쓰지 않는다.
     */
    public const LIST_COLUMNS = [
        'id', 'uuid', 'type', 'format', 'status', 'requested_by', 'requester_name_snapshot',
        'options', 'filters', 'contains_personal_data', 'reason', 'warnings', 'locale', 'timezone',
        'file_name', 'file_path', 'file_size', 'total_rows', 'processed_rows', 'download_count', 'error_code',
        'requested_at', 'cutoff_at', 'started_at', 'completed_at', 'expires_at', 'created_at', 'updated_at', 'deleted_at',
    ];

    /**
     * @param  Export  $model  내보내기 작업 모델
     */
    public function __construct(
        protected Export $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): Export
    {
        return $this->model->newQuery()->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?Export
    {
        return $this->model->newQuery()->with('requester')->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function lockById(int $id): void
    {
        $this->model->newQuery()->withTrashed()->whereKey($id)->lockForUpdate()->value('id');
    }

    /**
     * {@inheritDoc}
     */
    public function findByIdempotencyKey(int $userId, string $idempotencyKey): ?Export
    {
        return $this->model->newQuery()
            ->withTrashed()
            ->where('requested_by', $userId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function countActiveByUser(int $userId): int
    {
        return $this->model->newQuery()
            ->where('requested_by', $userId)
            ->whereIn('status', ExportStatusEnum::activeValues())
            ->count();
    }

    /**
     * {@inheritDoc}
     */
    public function paginateForAdmin(array $filters, int $perPage, ?User $viewer): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->with('requester');

        // 보는 사람의 범위 — 역할 관리의 「내보내기 관리 조회」 범위(본인/소유 역할/전체)
        ExportAccess::applyScope($query, $viewer);

        $this->applyAdminFilters($query, $filters, $viewer);

        foreach ($this->resolveSortSpec($filters, self::SORTABLE_COLUMNS, 'requested_at') as $sort) {
            $query->orderBy($sort['column'], $sort['direction']);
        }
        $query->orderBy('id', 'desc');

        return $query->paginate($perPage, self::LIST_COLUMNS);
    }

    /**
     * 관리 목록 필터를 적용합니다.
     *
     * 요청자 검색은 요청 때 남긴 이름(`requester_name_snapshot`)과 지금 회원 정보(이름, 닉네임, 이메일)를 함께 본다 — 목록은
     * 요청 때 남긴 이름을 보이고, 운영자는 지금 이름이나 닉네임으로도 찾는다. 탈퇴해 연결이 끊긴 요청자는 남긴 이름만 남는다. 검색어의 `%`·`_` 는 글자 그대로 찾는다.
     * 요청자 한정(`requester_uuid`)은 이름 포함 검색과 달리 그 회원 한 사람의 작업만 남긴다.
     *
     * @param  Builder  $query  작업 쿼리
     * @param  array<string, mixed>  $filters  검증된 필터
     * @param  User|null  $viewer  보는 사람 (「내 요청만」 판정)
     * @return void 반환값 없음
     */
    private function applyAdminFilters(Builder $query, array $filters, ?User $viewer): void
    {
        $keyword = trim((string) ($filters['search_keyword'] ?? ''));
        if ($keyword !== '') {
            $field = $filters['search_field'] ?? 'all';
            $contains = '%'.KeywordSearch::escapeLikeWildcards($keyword).'%';
            $query->where(function (Builder $q) use ($field, $contains, $keyword) {
                if ($field === 'all' || $field === 'file_name') {
                    $q->orWhere('file_name', 'like', $contains);
                }
                if ($field === 'all' || $field === 'requester') {
                    // 지금 회원 정보는 이름과 닉네임을 모두 찾는다(관리자 화면의 회원 검색 공통 규칙). 이메일도 종전대로 찾는다.
                    $q->orWhere('requester_name_snapshot', 'like', $contains)
                        ->orWhereHas('requester', fn (Builder $user) => MemberDisplayName::constrainUsersForAdmin($user, $keyword, withEmail: true));
                }
            });
        }

        if (! empty($filters['requester_uuid'])) {
            $query->whereHas('requester', fn (Builder $user) => $user->where('uuid', $filters['requester_uuid']));
        }

        if (! empty($filters['statuses'])) {
            $query->whereIn('status', (array) $filters['statuses']);
        }

        if (! empty($filters['types'])) {
            $query->whereIn('type', (array) $filters['types']);
        }

        $personal = $filters['personal'] ?? null;
        if ($personal === 'with') {
            $query->where('contains_personal_data', true);
        } elseif ($personal === 'without') {
            $query->where('contains_personal_data', false);
        }

        $this->applyViewerDateRangeFilter($query, 'requested_at', $filters['start_date'] ?? null, $filters['end_date'] ?? null);

        if (! empty($filters['mine']) && $viewer !== null) {
            $query->where('requested_by', $viewer->id);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function claimForProcessing(int $id, CarbonInterface $now): bool
    {
        $affected = $this->model->newQuery()
            ->whereKey($id)
            ->where('status', ExportStatusEnum::PENDING->value)
            ->update([
                'status' => ExportStatusEnum::PROCESSING->value,
                'started_at' => $now,
                'processed_rows' => 0,
            ]);

        return $affected === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function update(Export $export, array $data): Export
    {
        $export->fill($data)->save();

        return $export;
    }

    /**
     * {@inheritDoc}
     */
    public function updateProgress(int $id, int $processedRows): void
    {
        $this->model->newQuery()->whereKey($id)->update(['processed_rows' => $processedRows]);
    }

    /**
     * {@inheritDoc}
     */
    public function incrementDownloadCount(int $id): void
    {
        $this->model->newQuery()->whereKey($id)->increment('download_count');
    }

    /**
     * {@inheritDoc}
     */
    public function getExpiredCompleted(CarbonInterface $now, int $limit = 500): Collection
    {
        return $this->model->newQuery()
            ->where('status', ExportStatusEnum::COMPLETED->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function getStaleProcessing(CarbonInterface $startedBefore, int $limit = 500): Collection
    {
        return $this->model->newQuery()
            ->where('status', ExportStatusEnum::PROCESSING->value)
            ->where('started_at', '<', $startedBefore)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function getTrashedWithFile(int $limit = 500): Collection
    {
        return $this->model->newQuery()
            ->onlyTrashed()
            ->whereNotNull('file_path')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function hasStalePending(CarbonInterface $requestedBefore): bool
    {
        return $this->model->newQuery()
            ->where('status', ExportStatusEnum::PENDING->value)
            ->where('requested_at', '<', $requestedBefore)
            ->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function findManyByIds(array $ids): Collection
    {
        if ($ids === []) {
            return $this->model->newCollection();
        }

        return $this->model->newQuery()->whereIn('id', $ids)->get();
    }

    /**
     * {@inheritDoc}
     */
    public function softDelete(Export $export): void
    {
        $export->delete();
    }
}
