<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\ExtraFeeTemplate;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ExtraFeeTemplateRepositoryInterface;

/**
 * 추가배송비 템플릿 Repository 구현체
 */
class ExtraFeeTemplateRepository implements ExtraFeeTemplateRepositoryInterface
{
    public function __construct(
        protected ExtraFeeTemplate $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function find(int $id): ?ExtraFeeTemplate
    {
        return $this->model->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByZipcode(string $zipcode): ?ExtraFeeTemplate
    {
        return $this->model->where('zipcode', $zipcode)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function getListWithFilters(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        // 검색어 (우편번호 또는 지역명)
        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // 지역 필터
        if (! empty($filters['region'])) {
            $query->inRegion($filters['region']);
        }

        // 사용여부 필터
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $isActive = filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $isActive);
        }

        // 정렬
        $sortBy = $filters['sort_by'] ?? 'zipcode';
        $sortOrder = $filters['sort_order'] ?? 'asc';
        $query->orderByField($sortBy, $sortOrder);

        return $query->paginate($perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): ExtraFeeTemplate
    {
        $data['created_by'] = auth()->id();

        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(ExtraFeeTemplate $template, array $data): ExtraFeeTemplate
    {
        $data['updated_by'] = auth()->id();
        $template->update($data);

        return $template->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(ExtraFeeTemplate $template): bool
    {
        return $template->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function toggleActive(ExtraFeeTemplate $template): ExtraFeeTemplate
    {
        $template->update([
            'is_active' => ! $template->is_active,
            'updated_by' => auth()->id(),
        ]);

        return $template->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function bulkDelete(array $ids): int
    {
        return $this->model->whereIn('id', $ids)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function bulkToggleActive(array $ids, bool $isActive): int
    {
        return $this->model
            ->whereIn('id', $ids)
            ->update([
                'is_active' => $isActive,
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ]);
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveList(): Collection
    {
        return $this->model
            ->active()
            ->orderBy('zipcode')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function getAllAsExtraFeeSettings(): array
    {
        return $this->model
            ->active()
            ->orderBy('zipcode')
            ->get()
            ->map(fn ($template) => $template->toExtraFeeSetting())
            ->toArray();
    }

    /**
     * {@inheritDoc}
     */
    public function bulkCreate(array $items): int
    {
        $userId = auth()->id();
        $now = now();
        $count = 0;

        // Batch insert for performance
        $chunks = array_chunk($items, 100);

        foreach ($chunks as $chunk) {
            $records = array_map(function ($item) use ($userId, $now) {
                return [
                    'zipcode' => $item['zipcode'],
                    'fee' => $item['fee'] ?? 0,
                    'region' => $item['region'] ?? null,
                    'description' => $item['description'] ?? null,
                    'is_active' => $item['is_active'] ?? true,
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $chunk);

            // upsert: 우편번호 중복 시 업데이트
            $this->model->upsert(
                $records,
                ['zipcode'],
                ['fee', 'region', 'description', 'is_active', 'updated_by', 'updated_at']
            );

            $count += count($chunk);
        }

        return $count;
    }

    /**
     * {@inheritDoc}
     */
    public function getStatisticsByRegion(): array
    {
        $total = $this->model->count();
        $active = $this->model->where('is_active', true)->count();
        $inactive = $this->model->where('is_active', false)->count();

        // 지역별 통계
        $regionStats = $this->model
            ->selectRaw('region, COUNT(*) as count, AVG(fee) as avg_fee, MIN(fee) as min_fee, MAX(fee) as max_fee')
            ->whereNotNull('region')
            ->groupBy('region')
            ->orderBy('region')
            ->get()
            ->map(fn ($row) => [
                'region' => $row->region,
                'count' => $row->count,
                'avg_fee' => round($row->avg_fee, 2),
                'min_fee' => $row->min_fee,
                'max_fee' => $row->max_fee,
            ])
            ->toArray();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'by_region' => $regionStats,
        ];
    }

    /**
     * ID 목록으로 조회하고 ID 키 맵으로 반환합니다 (bulk activity log lookup).
     *
     * @param  array<int, int>  $ids  ID 목록
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function findByIdsKeyed(array $ids): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($ids)) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return ExtraFeeTemplate::whereIn('id', $ids)->get()->keyBy('id');
    }
}
