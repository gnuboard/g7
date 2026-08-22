<?php

namespace Plugins\Sirsoft\Ckeditor5\Repositories;

use App\Repositories\Concerns\FiltersByDateRange;
use App\Repositories\Concerns\ResolvesSortSpec;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageUploadRepositoryInterface;

/**
 * CKEditor5 이미지 업로드 Repository 구현체
 */
class ImageUploadRepository implements ImageUploadRepositoryInterface
{
    use FiltersByDateRange;
    use ResolvesSortSpec;

    /**
     * 목록·정리 조회에서 읽는 컬럼 목록
     *
     * 참조 판정(hash·file_path)·삭제(file_path·storage_disk)·화면 표시에 쓰이는 컬럼만
     * 읽습니다. 이 테이블에는 본문 성격의 넓은 컬럼이 없으나, 컬럼을 명시해 두면 이후
     * 컬럼이 늘어도 목록이 자동으로 넓어지지 않습니다.
     *
     * @var list<string>
     */
    private const LIST_COLUMNS = [
        'id',
        'hash',
        'original_name',
        'file_path',
        'storage_disk',
        'file_size',
        'mime_type',
        'uploaded_by',
        'created_at',
    ];

    /**
     * 관리 화면 정렬 허용 컬럼 화이트리스트
     *
     * @var list<string>
     */
    private const SORTABLE_COLUMNS = [
        'created_at',
        'file_size',
        'original_name',
    ];

    public function __construct(
        protected Ckeditor5ImageUpload $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function findByHash(string $hash): ?Ckeditor5ImageUpload
    {
        return $this->model->where('hash', $hash)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): Ckeditor5ImageUpload
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function findOlderThan(Carbon $threshold, int $limit): Collection
    {
        return $this->model->newQuery()
            ->where('created_at', '<', $threshold)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(self::LIST_COLUMNS);
    }

    /**
     * {@inheritDoc}
     */
    public function countOlderThan(Carbon $threshold): int
    {
        return $this->model->newQuery()
            ->where('created_at', '<', $threshold)
            ->count();
    }

    /**
     * {@inheritDoc}
     */
    public function paginateForAdmin(array $filters, int $perPage): LengthAwarePaginator
    {
        $sort = $this->resolveSortSpec(
            $filters,
            self::SORTABLE_COLUMNS,
            defaultColumn: 'created_at',
            defaultDirection: 'desc',
        )[0];

        $query = $this->applyFilters($this->model->newQuery(), $filters)
            ->with('uploader:id,name')
            ->orderBy($sort['column'], $sort['direction']);

        // 정렬 마지막에 기본키를 덧붙여 전순서를 보장한다 — 동률 구간에서 인접 페이지가
        // 같은 행을 중복 노출하거나 다른 행을 누락하는 것을 막는다.
        if ($sort['column'] !== 'id') {
            $query->orderBy('id', $sort['direction']);
        }

        return $query->paginate($perPage, self::LIST_COLUMNS);
    }

    /**
     * {@inheritDoc}
     */
    public function findScanWindow(array $filters, int $limit): Collection
    {
        return $this->applyFilters($this->model->newQuery(), $filters)
            ->with('uploader:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(self::LIST_COLUMNS);
    }

    /**
     * {@inheritDoc}
     */
    public function findManyByIds(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return $this->model->newQuery()
            ->whereIn('id', $ids)
            ->get(self::LIST_COLUMNS);
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?Ckeditor5ImageUpload
    {
        return $this->model->newQuery()->find($id, self::LIST_COLUMNS);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(Ckeditor5ImageUpload $upload): bool
    {
        return (bool) $upload->delete();
    }

    /**
     * 목록 필터를 쿼리에 적용합니다.
     *
     * @param  Builder<Ckeditor5ImageUpload>  $query  대상 쿼리
     * @param  array  $filters  필터 배열
     * @return Builder<Ckeditor5ImageUpload> 필터가 적용된 쿼리
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $escaped = addcslashes($search, '\\%_');

            $query->where(function (Builder $inner) use ($escaped) {
                $inner->where('original_name', 'like', '%'.$escaped.'%')
                    ->orWhere('hash', 'like', '%'.$escaped.'%');
            });
        }

        $this->applyDateRangeFilter(
            $query,
            'created_at',
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
        );

        return $query;
    }
}
