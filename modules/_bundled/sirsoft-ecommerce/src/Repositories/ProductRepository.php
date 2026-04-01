<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Helpers\PermissionHelper;
use App\Search\Engines\DatabaseFulltextEngine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;

/**
 * 상품 Repository 구현체
 */
class ProductRepository implements ProductRepositoryInterface
{
    public function __construct(
        protected Product $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function findWithOptions(int $id, bool $includeInactive = false): ?Product
    {
        $optionRelation = $includeInactive ? 'options' : 'activeOptions';

        return $this->model
            ->with([$optionRelation, 'categories', 'images', 'notice', 'activeLabelAssignments.label'])
            ->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function getListWithFilters(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->with(['options', 'categories', 'images', 'brand', 'shippingPolicy']);

        // 권한 스코프 필터링
        PermissionHelper::applyPermissionScope($query, 'sirsoft-ecommerce.products.read');

        // 문자열 검색
        if (! empty($filters['search_keyword'])) {
            $keyword = $filters['search_keyword'];
            $field = $filters['search_field'] ?? 'all';

            // FULLTEXT 대상 필드인 경우 Scout 사용
            if (in_array($field, ['all', 'name', 'description'])) {
                return Product::search($keyword)
                    ->query(function ($q) use ($filters, $keyword, $field, $perPage) {
                        $q->with(['options', 'categories', 'images', 'brand', 'shippingPolicy']);

                        // 권한 스코프 필터링
                        PermissionHelper::applyPermissionScope($q, 'sirsoft-ecommerce.products.read');

                        // FULLTEXT 외 필드 OR 조건 (all인 경우)
                        if ($field === 'all') {
                            $q->orWhere('product_code', 'like', "%{$keyword}%")
                                ->orWhere('sku', 'like', "%{$keyword}%")
                                ->orWhere('barcode', 'like', "%{$keyword}%");
                        }

                        // 필터 적용
                        $this->applyAdminFilters($q, $filters);

                        // 정렬
                        $this->applyAdminSorting($q, $filters);
                    })
                    ->paginate($perPage);
            }

            // FULLTEXT 미대상 필드 (product_code, sku, barcode) → LIKE 직접
            $query->where(function ($q) use ($keyword, $field) {
                if ($field === 'product_code') {
                    $q->where('product_code', 'like', "%{$keyword}%");
                }
                if ($field === 'sku') {
                    $q->where('sku', 'like', "%{$keyword}%");
                }
                if ($field === 'barcode') {
                    $q->where('barcode', 'like', "%{$keyword}%");
                }
            });
        }

        // 필터 적용
        $this->applyAdminFilters($query, $filters);

        // 정렬
        $this->applyAdminSorting($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): Product
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(Product $product, array $data): Product
    {
        $product->update($data);

        return $product->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(Product $product): bool
    {
        return $product->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStatus(array $ids, string $field, string $value): int
    {
        return $this->model
            ->whereIn('id', $ids)
            ->update([
                $field => $value,
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ]);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdatePrice(array $ids, string $method, int $value, string $unit): int
    {
        $updatedCount = 0;

        DB::transaction(function () use ($ids, $method, $value, $unit, &$updatedCount) {
            $products = $this->model->whereIn('id', $ids)->get();

            foreach ($products as $product) {
                $currentPrice = $product->selling_price;
                $newPrice = $this->calculateNewPrice($currentPrice, $method, $value, $unit);

                $product->update([
                    'selling_price' => max(0, $newPrice),
                    'updated_by' => auth()->id(),
                ]);
                $updatedCount++;
            }
        });

        return $updatedCount;
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStock(array $ids, string $method, int $value): int
    {
        $updatedCount = 0;

        DB::transaction(function () use ($ids, $method, $value, &$updatedCount) {
            $products = $this->model->whereIn('id', $ids)->get();

            foreach ($products as $product) {
                $currentStock = $product->stock_quantity;
                $newStock = match ($method) {
                    'increase' => $currentStock + $value,
                    'decrease' => $currentStock - $value,
                    'set' => $value,
                    default => $currentStock,
                };

                $product->update([
                    'stock_quantity' => max(0, $newStock),
                    'updated_by' => auth()->id(),
                ]);
                $updatedCount++;
            }
        });

        return $updatedCount;
    }

    /**
     * {@inheritDoc}
     */
    public function existsByProductCode(string $productCode, ?int $excludeId = null): bool
    {
        $query = $this->model->where('product_code', $productCode);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function getStatistics(): array
    {
        $total = $this->model->count();

        // 판매상태별 통계
        $salesStatusCounts = $this->model
            ->selectRaw('sales_status, COUNT(*) as count')
            ->groupBy('sales_status')
            ->pluck('count', 'sales_status')
            ->toArray();

        // 전시상태별 통계
        $displayStatusCounts = $this->model
            ->selectRaw('display_status, COUNT(*) as count')
            ->groupBy('display_status')
            ->pluck('count', 'display_status')
            ->toArray();

        // 재고 부족 상품 수 (안전재고 이하)
        $lowStockCount = $this->model
            ->whereColumn('stock_quantity', '<=', 'safe_stock_quantity')
            ->count();

        // 품절 상품 수
        $outOfStockCount = $this->model
            ->where('stock_quantity', '<=', 0)
            ->count();

        return [
            'total' => $total,
            'sales_status' => $salesStatusCounts,
            'display_status' => $displayStatusCounts,
            'low_stock_count' => $lowStockCount,
            'out_of_stock_count' => $outOfStockCount,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function findByProductCode(string $productCode): ?Product
    {
        return $this->model
            ->with(['activeOptions', 'categories', 'images'])
            ->where('product_code', $productCode)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findWithAllRelations(int $id): ?Product
    {
        return $this->model
            ->with([
                'activeOptions',
                'options',
                'categories',
                'images',
                'brand',
                'additionalOptions',
                'labelAssignments',
                'labelAssignments.label',
                'notice',
            ])
            ->find($id);
    }

    /**
     * 새 가격 계산
     *
     * @param  int  $currentPrice  현재 가격
     * @param  string  $method  변경 방식
     * @param  int  $value  변경 값
     * @param  string  $unit  단위
     */
    protected function calculateNewPrice(int $currentPrice, string $method, int $value, string $unit): int
    {
        $adjustAmount = $unit === 'percent'
            ? (int) ($currentPrice * $value / 100)
            : $value;

        return match ($method) {
            'increase' => $currentPrice + $adjustAmount,
            'decrease' => $currentPrice - $adjustAmount,
            'set' => $value,
            default => $currentPrice,
        };
    }

    /**
     * {@inheritDoc}
     */
    public function find(int $id): ?Product
    {
        return $this->model->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateFields(array $ids, array $fields): int
    {
        if (empty($ids) || empty($fields)) {
            return 0;
        }

        // 업데이트 시간과 수정자 정보 추가
        $fields['updated_at'] = now();
        $fields['updated_by'] = auth()->id();

        return $this->model
            ->whereIn('id', $ids)
            ->update($fields);
    }

    /**
     * {@inheritDoc}
     */
    public function getPublicList(array $filters, int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->with(['images', 'categories', 'brand', 'activeLabelAssignments.label'])
            ->withCount('visibleReviews as review_count')
            ->withAvg('visibleReviews as rating_avg', 'rating')
            ->where('display_status', 'visible');

        // 카테고리 필터 (ID)
        if (! empty($filters['category_id'])) {
            $query->whereHas('categories', function ($q) use ($filters) {
                $q->where('ecommerce_product_categories.category_id', $filters['category_id']);
            });
        }

        // 카테고리 필터 (slug)
        if (! empty($filters['category_slug'])) {
            $query->whereHas('categories', function ($q) use ($filters) {
                $q->where('ecommerce_categories.slug', $filters['category_slug']);
            });
        }

        // 키워드 검색 (FULLTEXT)
        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            DatabaseFulltextEngine::whereFulltext($query, 'name', $keyword);
        }

        // 가격 범위 필터
        if (! empty($filters['min_price'])) {
            $query->where('selling_price', '>=', (int) $filters['min_price']);
        }
        if (! empty($filters['max_price'])) {
            $query->where('selling_price', '<=', (int) $filters['max_price']);
        }

        // 브랜드 필터
        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        // 정렬
        $sort = $filters['sort'] ?? 'latest';
        match ($sort) {
            'sales' => $query
                ->addSelect(['total_sold' => \Modules\Sirsoft\Ecommerce\Models\OrderOption::selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('product_id', 'ecommerce_products.id'),
                ])
                ->orderByDesc('total_sold'),
            'price_asc' => $query->orderBy('selling_price', 'asc'),
            'price_desc' => $query->orderBy('selling_price', 'desc'),
            default => $query->orderBy('created_at', 'desc'), // latest
        };

        return $query->paginate($perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function getPopularProducts(int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        $thirtyDaysAgo = now()->subDays(30);

        return $this->model->newQuery()
            ->with(['images', 'categories', 'activeLabelAssignments.label'])
            ->withCount('visibleReviews as review_count')
            ->withAvg('visibleReviews as rating_avg', 'rating')
            ->where('display_status', 'visible')
            ->addSelect(['recent_sold' => \Modules\Sirsoft\Ecommerce\Models\OrderOption::selectRaw('COALESCE(SUM(quantity), 0)')
                ->whereColumn('product_id', 'ecommerce_products.id')
                ->where('created_at', '>=', $thirtyDaysAgo),
            ])
            ->orderByDesc('recent_sold')
            ->limit($limit)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function getNewProducts(int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return $this->model->newQuery()
            ->with(['images', 'categories', 'activeLabelAssignments.label'])
            ->withCount('visibleReviews as review_count')
            ->withAvg('visibleReviews as rating_avg', 'rating')
            ->where('display_status', 'visible')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findByIds(array $ids): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($ids)) {
            return $this->model->newCollection();
        }

        $products = $this->model->newQuery()
            ->with(['images', 'categories', 'activeLabelAssignments.label'])
            ->where('display_status', 'visible')
            ->whereIn('id', $ids)
            ->get();

        // 클라이언트 요청 순서 유지 (DB 독립적 정렬)
        $idOrder = array_flip($ids);

        return $products->sortBy(fn ($product) => $idOrder[$product->id] ?? PHP_INT_MAX)->values();
    }

    /**
     * {@inheritDoc}
     */
    public function searchByKeyword(string $keyword, string $orderBy = 'created_at', string $direction = 'desc', ?int $categoryId = null, int $offset = 0, int $limit = 10): array
    {
        $page = (int) floor($offset / $limit) + 1;

        $results = Product::search($keyword)
            ->query(function ($query) use ($categoryId, $orderBy, $direction) {
                $query->where('display_status', ProductDisplayStatus::VISIBLE->value);

                $query->with(['images', 'primaryCategory', 'brand', 'activeLabelAssignments.label'])
                    ->withCount('visibleReviews as review_count')
                    ->withAvg('visibleReviews as rating_avg', 'rating');

                if ($categoryId !== null) {
                    $query->whereHas('categories', fn ($q) => $q->where('ecommerce_categories.id', $categoryId));
                }

                $query->orderBy($orderBy, $direction);
            })
            ->paginate($limit, 'page', $page);

        return ['total' => $results->total(), 'items' => $results->getCollection()];
    }

    /**
     * {@inheritDoc}
     */
    public function countByKeyword(string $keyword, ?int $categoryId = null): int
    {
        return Product::search($keyword)
            ->query(function ($query) use ($categoryId) {
                $query->where('display_status', ProductDisplayStatus::VISIBLE->value);

                if ($categoryId !== null) {
                    $query->whereHas('categories', fn ($q) => $q->where('ecommerce_categories.id', $categoryId));
                }
            })
            ->paginate(1)
            ->total();
    }

    /**
     * 관리자 상품 목록 필터를 쿼리에 적용합니다.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Eloquent 쿼리 빌더
     * @param array $filters 필터 배열
     * @return void
     */
    private function applyAdminFilters($query, array $filters): void
    {
        // 카테고리 필터 (다대다 관계)
        if (! empty($filters['category_id'])) {
            $categoryId = $filters['category_id'];
            $query->whereHas('categories', function ($q) use ($categoryId) {
                $q->where('ecommerce_product_categories.category_id', $categoryId);
            });
        }

        // 카테고리 미부여 상품
        if (! empty($filters['no_category']) && $filters['no_category'] === true) {
            $query->whereDoesntHave('categories');
        }

        // 날짜 필터
        if (! empty($filters['date_type']) && (! empty($filters['start_date']) || ! empty($filters['end_date']))) {
            $dateField = $filters['date_type'];

            if (! empty($filters['start_date'])) {
                $query->whereDate($dateField, '>=', $filters['start_date']);
            }
            if (! empty($filters['end_date'])) {
                $query->whereDate($dateField, '<=', $filters['end_date']);
            }
        }

        // 판매상태 필터 (다중 선택 가능)
        if (! empty($filters['sales_status'])) {
            $statuses = is_array($filters['sales_status'])
                ? $filters['sales_status']
                : [$filters['sales_status']];
            $query->whereIn('sales_status', $statuses);
        }

        // 전시상태 필터
        if (! empty($filters['display_status'])) {
            $query->where('display_status', $filters['display_status']);
        }

        // 브랜드 필터
        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        // 브랜드 미부여 상품
        if (! empty($filters['no_brand']) && $filters['no_brand'] === true) {
            $query->whereNull('brand_id');
        }

        // 과세여부 필터
        if (! empty($filters['tax_status'])) {
            $query->where('tax_status', $filters['tax_status']);
        }

        // 가격 범위 필터
        if (! empty($filters['price_type'])) {
            $priceField = $filters['price_type'];

            if (! empty($filters['min_price'])) {
                $query->where($priceField, '>=', (int) $filters['min_price']);
            }
            if (! empty($filters['max_price'])) {
                $query->where($priceField, '<=', (int) $filters['max_price']);
            }
        }

        // 재고 범위 필터
        if (isset($filters['min_stock']) && $filters['min_stock'] !== '') {
            $query->where('stock_quantity', '>=', (int) $filters['min_stock']);
        }
        if (isset($filters['max_stock']) && $filters['max_stock'] !== '') {
            $query->where('stock_quantity', '<=', (int) $filters['max_stock']);
        }

        // 배송정책 필터
        if (! empty($filters['shipping_policy_id'])) {
            $query->where('shipping_policy_id', $filters['shipping_policy_id']);
        }
    }

    /**
     * 관리자 상품 목록 정렬을 쿼리에 적용합니다.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Eloquent 쿼리 빌더
     * @param array $filters 필터 배열
     * @return void
     */
    private function applyAdminSorting($query, array $filters): void
    {
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';

        // 다국어 이름 정렬 처리
        if ($sortBy === 'name') {
            $locale = app()->getLocale();
            $query->orderBy("name->{$locale}", $sortOrder);
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function syncStockFromOptions(int $productId): bool
    {
        $product = $this->model->find($productId);

        if (! $product) {
            return false;
        }

        // 옵션이 없는 상품은 동기화 불필요
        if (! $product->has_options) {
            return true;
        }

        // 활성 옵션의 재고 합계 계산
        $totalStock = $product->options()
            ->where('is_active', true)
            ->sum('stock_quantity');

        $product->stock_quantity = $totalStock;
        $product->save();

        return true;
    }
}
