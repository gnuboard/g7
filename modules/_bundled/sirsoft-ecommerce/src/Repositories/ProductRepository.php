<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Contracts\Extension\CacheInterface;
use App\Helpers\PermissionHelper;
use App\Models\ActivityLog;
use App\Repositories\Concerns\FiltersByDateRange;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Repositories\Concerns\ResolvesSortSpec;
use App\Search\KeywordSearch;
use App\Support\Query\BoundedCount;
use App\Support\Query\BoundedPage;
use App\Support\Query\BoundedPaginator;
use App\Support\Query\KeysetPaginator;
use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;

/**
 * 상품 Repository 구현체
 */
class ProductRepository implements ProductRepositoryInterface
{
    use FiltersByDateRange;
    use PaginatesWithDeferredJoin;
    use ResolvesSortSpec;

    /** 허용 정렬 컬럼 (ProductListRequest 와 동일 집합) */
    private const ADMIN_SORTABLE_COLUMNS = ['created_at', 'updated_at', 'selling_price', 'stock_quantity', 'name'];

    /** 상품 통계 캐시 키 */
    private const STATISTICS_CACHE_KEY = 'ecommerce.products.statistics';

    /** 상품 통계 캐시 태그 */
    private const STATISTICS_CACHE_TAG = 'ecommerce.products';

    public function __construct(
        protected Product $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function existsAny(): bool
    {
        // 소프트삭제 포함 — 삭제된 상품도 과거 base 로 생성된 이력이라 base 잠금 유지가 안전(A2)
        return $this->model->newQuery()->withTrashed()->exists();
    }

    /**
     * Sitemap 용으로 전시 중인 상품을 스트리밍 조회합니다.
     *
     * lazyById 는 id 기준 키셋 페이징으로 청크를 순차 조회하므로,
     * 결과셋 전체가 메모리(및 DB 드라이버 버퍼)에 적재되지 않습니다.
     *
     * @param  int  $chunkSize  청크 크기
     * @return iterable<Product> 전시 중인 상품 순회자 (id, updated_at 만 조회)
     */
    public function streamVisibleForSitemap(int $chunkSize = 500): iterable
    {
        return $this->model->newQuery()
            ->where('display_status', ProductDisplayStatus::VISIBLE->value)
            ->select(['id', 'updated_at'])
            ->orderBy('id')
            ->lazyById($chunkSize);
    }

    /**
     * {@inheritDoc}
     */
    public function findWithOptions(int $id, bool $includeInactive = false): ?Product
    {
        $optionRelation = $includeInactive ? 'options' : 'activeOptions';

        return $this->model
            ->with([$optionRelation, 'additionalOptions.values', 'categories', 'images', 'notice', 'activeLabelAssignments.label'])
            ->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function getListWithFilters(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        // 권한 스코프 필터링
        PermissionHelper::applyPermissionScope($query, 'sirsoft-ecommerce.products.read');

        // 지연 조인 outer 전용 관계 (inner 는 id 만 훑으므로 여기서 with() 를 걸지 않는다)
        //
        // 옵션은 기본적으로 로드하지 않는다. 목록 한 페이지(최대 100행)를 여는 것만으로 그 페이지
        // 전 상품의 옵션 행이 모두 적재·직렬화되기 때문이다. 목록이 실제로 쓰는 것은 개수와 재고
        // 합계뿐이라 아래 DB 집계로 대체하고, 옵션 상세는 행을 펼칠 때 배치 엔드포인트
        // (`GET admin/products/options`)로 가져간다. `?with_options=1` 은 종전 동작을 위한 opt-in.
        $listRelations = ['categories', 'images', 'brand', 'shippingPolicy'];

        if (! empty($filters['with_options'])) {
            $listRelations[] = 'options';
        }

        // 옵션 집계 — PHP 컬렉션 연산 대신 DB 집계로 계산한다.
        //   options_count       : 활성 옵션 수 (화면 표시용)
        //   options_total_count : 비활성 포함 전체 옵션 수. 일괄 변경 확인 모달이 서버의
        //                         적용 모집단(ProductOptionRepository::getIdsByProductIds — is_active
        //                         무필터)과 같은 수를 세려면 이쪽이어야 한다
        // 별칭을 생략하면 두 카운트의 기본 별칭이 `options_count` 로 같아져 뒤엣것이 앞엣것을
        // 조용히 덮으므로 `as` 로 반드시 구분한다.
        $listWithCount = [
            'options as options_count' => fn ($q) => $q->where('is_active', true),
            'options as options_total_count',
        ];

        // withSum 은 트레이트 인자가 없으므로 outer 전용 클로저로 붙인다. inner 에 붙으면
        // 건너뛸 행 전체에 대해 실행된다.
        $listOuterUsing = fn (Builder $outer) => $outer->withSum(
            ['options as option_stock_sum' => fn ($q) => $q->where('is_active', true)],
            'stock_quantity'
        );

        // 문자열 검색
        if (! empty($filters['search_keyword'])) {
            $keyword = $filters['search_keyword'];
            $field = $filters['search_field'] ?? 'all';

            // FULLTEXT 대상 필드(all/name/description)는 FULLTEXT 매칭과 보조필드 LIKE 를
            // 하나의 WHERE 그룹으로 묶어 페이지 쿼리에 직접 넣는다.
            //
            // 종전에는 매칭 ID 전량을 PHP 로 끌어와 whereIn 으로 되먹였다. 그 방식은 매칭이
            // 많을수록 PHP 메모리와 IN(...) 목록이 함께 커지고, 조회 전에 전 매칭을 한 번
            // 훑는 왕복이 추가된다. 술어를 그대로 밀어넣으면 왕복도 목록 폭발도 없고,
            // total 계산 경로와 결과 조회 경로가 같은 술어를 공유한다는 성질도 유지된다.
            if (in_array($field, ['all', 'name', 'description'])) {
                $this->applyKeywordMatch($query, $keyword, includeAuxFields: $field === 'all');

                // 필터 적용
                $this->applyAdminFilters($query, $filters);

                return $this->paginateWithDeferredJoin(
                    query: $query,
                    columns: ['*'],
                    sort: $this->resolveAdminSortSpec($filters),
                    perPage: $perPage,
                    relations: $listRelations,
                    withCount: $listWithCount,
                    outerUsing: $listOuterUsing,
                    resultCap: PaginationLimits::resultCap('admin.products'),
                );
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

        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ['*'],
            sort: $this->resolveAdminSortSpec($filters),
            perPage: $perPage,
            relations: $listRelations,
            withCount: $listWithCount,
            outerUsing: $listOuterUsing,
            resultCap: PaginationLimits::resultCap('admin.products'),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getOptionsGroupedByProductIds(array $productIds): array
    {
        $requested = array_values(array_unique(array_map('intval', $productIds)));

        if ($requested === []) {
            return ['product_ids' => [], 'options' => []];
        }

        // ① 권한 스코프를 적용한 상품 쿼리로 허용 ID 를 먼저 확정한다. 옵션 테이블을 바로
        //    조회하면 scope=self 사용자가 남의 상품 ID 를 실어보내는 것만으로 옵션을 읽을 수 있다.
        $scoped = $this->model->newQuery();
        PermissionHelper::applyPermissionScope($scoped, 'sirsoft-ecommerce.products.read');

        $allowedIds = $scoped->whereIn('id', $requested)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($allowedIds === []) {
            return ['product_ids' => [], 'options' => []];
        }

        // 요청 순서를 유지한다 (프론트가 요청 배열과 그대로 대조할 수 있도록)
        $allowedSet = array_flip($allowedIds);
        $allowedIds = array_values(array_filter($requested, fn ($id) => isset($allowedSet[$id])));

        // ② 옵션은 상품 수와 무관하게 쿼리 1개. 비활성 옵션도 포함한다 — 인라인 편집기가
        //    `is_active` 토글을 다루므로 활성만 내려주면 비활성 옵션을 편집할 수 없게 된다.
        //    정렬은 상품폼/activeOptions 와 같은 `sort_order` 기준으로 통일한다.
        //
        //    `product` 를 함께 로드하는 이유: 옵션 리소스의 가격 필드는 상품 정가/판매가에
        //    조정액을 더해 만든다(ProductOption::getListPrice/getFinalPrice). 부모를 로드하지
        //    않으면 옵션 1건마다 상품 조회가 한 번씩 나가 옵션 수만큼 N+1 이 된다.
        $options = ProductOption::with('product')
            ->whereIn('product_id', $allowedIds)
            ->orderBy('product_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        return ['product_ids' => $allowedIds, 'options' => $options];
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): Product
    {
        $product = $this->model->create($data);
        $this->forgetStatisticsCache();

        return $product;
    }

    /**
     * {@inheritDoc}
     */
    public function update(Product $product, array $data): Product
    {
        $product->update($data);
        $this->forgetStatisticsCache();

        return $product->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(Product $product): bool
    {
        $deleted = $product->delete();
        $this->forgetStatisticsCache();

        return $deleted;
    }

    /**
     * {@inheritDoc}
     */
    public function forceDelete(Product $product): bool
    {
        $deleted = (bool) $product->forceDelete();
        $this->forgetStatisticsCache();

        return $deleted;
    }

    /**
     * {@inheritDoc}
     */
    public function getSnapshotsByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->model->whereIn('id', $ids)->get()->keyBy('id')->map->toArray()->all();
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStatus(array $ids, string $field, string $value): int
    {
        $affected = $this->model
            ->whereIn('id', $ids)
            ->update([
                $field => $value,
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ]);

        $this->forgetStatisticsCache();

        return $affected;
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdatePrice(array $ids, string $method, float $value, string $unit): int
    {
        if (empty($ids)) {
            return 0;
        }

        // 'set' 은 새 값이 행마다 같으므로 단일 statement 로 끝난다.
        if ($method === 'set') {
            $affected = $this->model
                ->whereIn('id', $ids)
                ->update([
                    'selling_price' => max(0, $value),
                    'updated_by' => auth()->id(),
                    'updated_at' => now(),
                ]);

            $this->forgetStatisticsCache();

            return $affected;
        }

        // 증감·비율은 현재 값에 의존하지만 그 계산도 SQL 이 할 수 있다.
        // 행마다 update() 를 부르면 대상 수만큼 UPDATE 문이 나가므로, 표현식 하나로 합친다.
        // (같은 파일의 bulkUpdateFields 가 쓰는 단일 statement 방식과 같은 결)
        //
        // 표현식 안의 값은 플레이스홀더로 두지 않는다 — update() 는 값 배열 하나만 받고
        // 표현식에 딸린 바인딩을 실을 통로가 없다. 남겨 두면 WHERE 바인딩이 SET 절로
        // 밀려 엉뚱한 컬럼에 기록되거나 파라미터 수 불일치로 실패한다.
        $amount = $this->sqlDecimal($value);

        $expression = match ($method) {
            'increase' => $unit === 'percent'
                ? 'selling_price * (1 + '.$amount.' / 100)'
                : 'selling_price + '.$amount,
            'decrease' => $unit === 'percent'
                ? 'selling_price * (1 - '.$amount.' / 100)'
                : 'selling_price - '.$amount,
            default => null,
        };

        if ($expression === null) {
            return 0;
        }

        $affected = $this->model
            ->whereIn('id', $ids)
            ->update([
                'selling_price' => DB::raw('GREATEST(0, '.$expression.')'),
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ]);

        $this->forgetStatisticsCache();

        return $affected;
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStock(array $ids, string $method, int $value): int
    {
        if (empty($ids)) {
            return 0;
        }

        // 행마다 조회 후 update() 를 부르면 대상 수만큼 UPDATE 문이 나간다.
        // 재고 증감은 SQL 표현식으로 표현되므로 단일 statement 로 합친다.
        // 수량은 정수로 강제되므로 표현식에 그대로 넣는다 (바인딩 불가 사유는 bulkUpdatePrice 참조).
        $quantity = (int) $value;

        $newStock = match ($method) {
            'increase' => DB::raw('GREATEST(0, stock_quantity + '.$quantity.')'),
            'decrease' => DB::raw('GREATEST(0, stock_quantity - '.$quantity.')'),
            'set' => max(0, $value),
            default => null,
        };

        if ($newStock === null) {
            return 0;
        }

        $affected = $this->model
            ->whereIn('id', $ids)
            ->update([
                'stock_quantity' => $newStock,
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ]);

        $this->forgetStatisticsCache();

        return $affected;
    }

    /**
     * 실수를 SQL 표현식에 넣을 십진 리터럴로 만듭니다.
     *
     * 인자가 float 로 강제되므로 주입 여지는 없고, 지수 표기(1.0E-5)로 변환되면
     * SQL 이 해석하지 못하므로 고정 소수점으로 고정합니다.
     *
     * @param  float  $value  변환할 값
     * @return string 십진 리터럴 문자열
     */
    private function sqlDecimal(float $value): string
    {
        return number_format($value, 4, '.', '');
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
        // 상품 목록 화면과 함께 매 페이지 조회되는 집계 5종이다. 값이 초 단위로 달라질
        // 필요는 없으므로 짧게 캐시하고, 상품이 바뀌면 즉시 무효화한다
        // (주문 통계와 같은 규약 — cache.stats_enabled / cache.stats_ttl).
        if (! g7_core_settings('cache.stats_enabled', true)) {
            return $this->computeStatistics();
        }

        $ttl = (int) g7_core_settings('cache.stats_ttl', 1800);

        if ($ttl <= 0) {
            return $this->computeStatistics();
        }

        return app(CacheInterface::class)->remember(
            self::STATISTICS_CACHE_KEY,
            fn () => $this->computeStatistics(),
            $ttl,
            [self::STATISTICS_CACHE_TAG]
        );
    }

    /**
     * 상품 통계 캐시를 무효화합니다.
     *
     * 상품이 생성·수정·삭제되면 통계도 곧바로 달라져야 합니다. 쓰기 경로가 이 메서드를
     * 부르지 않으면 화면이 TTL 이 끝날 때까지 이전 수치를 보여 줍니다.
     */
    public function forgetStatisticsCache(): void
    {
        app(CacheInterface::class)->forget(self::STATISTICS_CACHE_KEY);
    }

    /**
     * 상품 통계를 실제로 집계합니다.
     *
     * @return array 상품 통계
     */
    private function computeStatistics(): array
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
                'additionalOptions.values',
                'labelAssignments',
                'labelAssignments.label',
                'notice',
                'shippingPolicy',
                'shippingPolicy.countrySettings',
            ])
            ->find($id);
    }

    /**
     * 새 가격 계산
     *
     * @param  float  $currentPrice  현재 가격
     * @param  string  $method  변경 방식
     * @param  float  $value  변경 값
     * @param  string  $unit  단위
     */
    protected function calculateNewPrice(float $currentPrice, string $method, float $value, string $unit): float
    {
        // 소수 통화 대응: 절사 대신 소수 2자리 반올림 보존
        $adjustAmount = $unit === 'percent'
            ? round($currentPrice * $value / 100, 2)
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

        $affected = $this->model
            ->whereIn('id', $ids)
            ->update($fields);

        $this->forgetStatisticsCache();

        return $affected;
    }

    /**
     * {@inheritDoc}
     */
    public function getPublicList(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        // 리뷰 건수·평점은 파생 테이블 조인으로 한 번에 얻는다. 목록 경로는 지연 조인을
        // 쓰므로 집계를 outer 에서만 붙인다 (아래 outerUsing).
        $query = $this->model->newQuery()
            ->where('display_status', 'visible');

        // 지연 조인 outer 전용 관계 (inner 는 id 만 훑으므로 with() 를 걸지 않는다)
        $listRelations = ['images', 'categories', 'brand', 'activeLabelAssignments.label'];

        // 카테고리 필터 (ID) — 선택 카테고리 + 모든 하위 카테고리 포함
        if (! empty($filters['category_id'])) {
            $categoryIds = Category::selfAndDescendantIds((int) $filters['category_id']);
            $query->whereHas('categories', function ($q) use ($categoryIds) {
                $q->whereIn('ecommerce_product_categories.category_id', $categoryIds);
            });
        }

        // 카테고리 필터 (slug) — 선택 카테고리 + 모든 하위 카테고리 포함
        if (! empty($filters['category_slug'])) {
            $root = Category::where('slug', $filters['category_slug'])->first(['id']);
            if ($root) {
                $categoryIds = Category::selfAndDescendantIds((int) $root->id);
                $query->whereHas('categories', function ($q) use ($categoryIds) {
                    $q->whereIn('ecommerce_product_categories.category_id', $categoryIds);
                });
            } else {
                // 존재하지 않는 slug → 빈 결과 (500 아님). 빈 whereIn 은 `0 = 1` 로 컴파일된다
                $query->whereIn($query->getModel()->getKeyName(), []);
            }
        }

        // 키워드 검색 (FULLTEXT)
        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            KeywordSearch::apply($query, 'name', $keyword);
        }

        // 가격 범위 필터 (소수 통화 대응 — float 비교)
        // 0 은 유효한 경계값이다(무료 상품만 보기). empty() 로 거르면 필터가 무시된다.
        if (isset($filters['min_price']) && $filters['min_price'] !== '') {
            $query->where('selling_price', '>=', (float) $filters['min_price']);
        }
        if (isset($filters['max_price']) && $filters['max_price'] !== '') {
            $query->where('selling_price', '<=', (float) $filters['max_price']);
        }

        // 브랜드 필터
        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        // 정렬
        $sort = $filters['sort'] ?? 'latest';

        // 판매량 정렬.
        //
        // 종전에는 상관 서브쿼리를 SELECT 에 올리고 그 별칭으로 정렬했다. 상관 서브쿼리가
        // ORDER BY 에 걸리면 LIMIT 이 평가 범위를 막지 못한다 — 정렬 순서를 정하려면 모든
        // 후보 행에 대해 서브쿼리를 한 번씩 돌려야 하므로, 한 페이지만 필요해도 전 상품에
        // 대해 집계가 실행된다. 미리 묶은 파생 테이블을 LEFT JOIN 하면 집계가 한 번만
        // 수행되고 조인 결과를 정렬한다.
        //
        // 파생 테이블은 product_id 로 그룹지어 상품당 한 행이므로 원 행이 부풀지 않는다.
        if ($sort === 'sales') {
            // 판매량 정렬은 지연 조인을 쓰지 않으므로 리뷰 집계도 여기서 함께 붙인다.
            // 두 파생 테이블 모두 product_id 로 그룹지어 상품당 한 행이라 원 행이 부풀지 않는다.
            $this->joinReviewAggregate($query);

            $this->joinSalesAggregate($query)
                ->orderByDesc($this->salesAlias().'.total_sold')
                ->with($listRelations);

            // audit:allow repository-paginate-column-pruning reason: 판매량 정렬은 조인한
            // 집계 별칭 기준이라 지연 조인 inner(=id 단일 select)로 옮길 수 없다.
            // 나머지 정렬은 지연 조인 적용
            return BoundedPaginator::paginate(
                $query,
                perPage: $perPage,
                resultCap: PaginationLimits::resultCap('shop.products'),
            );
        }

        $sortSpec = match ($sort) {
            'price_asc' => [['column' => 'selling_price', 'direction' => 'asc']],
            'price_desc' => [['column' => 'selling_price', 'direction' => 'desc']],
            default => [['column' => 'created_at', 'direction' => 'desc']], // latest
        };

        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ['*'],
            sort: $sortSpec,
            perPage: $perPage,
            relations: $listRelations,
            outerUsing: $this->reviewAggregateForOuter(),
            resultCap: PaginationLimits::resultCap('shop.products'),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getPopularProducts(int $limit = 10): Collection
    {
        $thirtyDaysAgo = now()->subDays(30);

        // 판매량 정렬과 같은 이유로 파생 테이블 조인을 쓴다 — 상관 서브쿼리를 ORDER BY 에
        // 두면 LIMIT 이 있어도 전 상품에 대해 집계가 실행된다.
        $query = $this->model->newQuery()
            ->with(['images', 'categories', 'activeLabelAssignments.label'])
            ->where('display_status', 'visible');

        $this->joinReviewAggregate($query);

        return $this->joinSalesAggregate($query, $thirtyDaysAgo)
            ->orderByDesc($this->salesAlias().'.total_sold')
            ->limit($limit)
            ->get();
    }

    /**
     * 상품별 판매 수량 집계를 파생 테이블로 조인합니다.
     *
     * 상관 서브쿼리 대신 미리 그룹지은 결과를 LEFT JOIN 합니다. 집계가 상품 수만큼
     * 반복 실행되지 않고, product_id 기준 그룹이라 원 행이 부풀지 않습니다.
     *
     * 판매 이력이 없는 상품은 조인 결과가 없으므로 정렬에서 자연스럽게 뒤로 밀립니다.
     *
     * @param  Builder  $query  대상 쿼리
     * @param  \DateTimeInterface|null  $since  집계 시작 시점 (null 이면 전체 기간)
     * @return Builder 조인이 적용된 쿼리
     */
    private function joinSalesAggregate($query, ?\DateTimeInterface $since = null)
    {
        $alias = $this->salesAlias();
        $productsTable = $this->model->getTable();

        $aggregate = OrderOption::query()
            ->select('product_id')
            ->selectRaw('SUM(quantity) as total_sold')
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            ->groupBy('product_id');

        return $this->ensureBaseSelect($query)
            ->leftJoinSub(
                $aggregate,
                $alias,
                fn ($join) => $join->on($alias.'.product_id', '=', $productsTable.'.id')
            )
            ->addSelect($alias.'.total_sold');
    }

    /**
     * {@inheritDoc}
     */
    public function findByIdsWithRelationsKeyed(array $ids, array $relations = []): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (empty($ids)) {
            return $this->model->newCollection();
        }

        // 항목마다 find() 를 부르면 항목 수만큼 쿼리가 난다. 한 번에 읽고 ID 로 키를 잡는다.
        return $this->model->newQuery()
            ->when(! empty($relations), fn ($q) => $q->with($relations))
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * 조인 전에 상품 테이블 기준 SELECT 를 한 번만 고정합니다.
     *
     * 조인이 붙은 쿼리에서 `*` 는 조인 대상의 컬럼까지 끌어와 별칭이 충돌합니다.
     * 그렇다고 집계 헬퍼마다 `select()` 를 부르면 나중에 부른 쪽이 앞서 쌓은
     * `addSelect()` 를 지웁니다 — 판매 집계와 리뷰 집계를 함께 붙이는 경로에서
     * 한쪽 별칭이 조용히 사라지므로, 이미 지정돼 있으면 건드리지 않습니다.
     *
     * @param  Builder  $query  대상 쿼리
     * @return Builder SELECT 가 고정된 쿼리
     */
    private function ensureBaseSelect($query)
    {
        if (empty($query->getQuery()->columns)) {
            $query->select($this->model->getTable().'.*');
        }

        return $query;
    }

    /**
     * 판매 집계 파생 테이블의 별칭을 반환합니다.
     *
     * @return string 별칭
     */
    private function salesAlias(): string
    {
        return 'g7_product_sales';
    }

    /**
     * 상품별 리뷰 건수·평점 집계를 파생 테이블로 조인합니다.
     *
     * `withCount()` + `withAvg()` 를 겹쳐 쓰면 같은 리뷰 테이블을 훑는 상관 서브쿼리가
     * 행마다 **두 번** 실행됩니다. 건수와 평균은 한 번의 그룹 집계로 함께 얻을 수 있으므로
     * 미리 그룹지은 파생 테이블을 LEFT JOIN 해 스캔을 1회로 줄입니다.
     *
     * product_id 기준 그룹이라 상품당 한 행이므로 원 행이 부풀지 않습니다. 리뷰가 없는
     * 상품은 조인 결과가 없어 건수 0 / 평점 null 이 되며, 이는 종전 동작과 같습니다.
     *
     * @param  Builder  $query  대상 쿼리
     * @return Builder 조인이 적용된 쿼리
     */
    private function joinReviewAggregate($query)
    {
        $alias = $this->reviewAlias();
        $productsTable = $this->model->getTable();

        $aggregate = ProductReview::query()
            ->select('product_id')
            ->selectRaw('COUNT(*) as review_count')
            ->selectRaw('AVG(rating) as rating_avg')
            ->where('status', ReviewStatus::VISIBLE->value)
            ->groupBy('product_id');

        return $this->ensureBaseSelect($query)
            ->leftJoinSub(
                $aggregate,
                $alias,
                fn ($join) => $join->on($alias.'.product_id', '=', $productsTable.'.id')
            )
            // 별칭은 빌더가 만들게 둔다 — grammar 가 테이블 프리픽스를 별칭에도 붙이므로
            // raw 문자열로 별칭을 조립하면 `g7_g7_...` 처럼 이중 프리픽스가 된다.
            // 리뷰가 없는 상품은 여기서 null 이 오고, 리소스가 0 으로 표기한다.
            ->addSelect([
                $alias.'.review_count',
                $alias.'.rating_avg',
            ]);
    }

    /**
     * 지연 조인 목록의 outer 에만 리뷰 집계를 붙이는 클로저를 반환합니다.
     *
     * inner 에 두면 OFFSET 이 건너뛰는 행 전체에 대해 집계가 실행되므로,
     * 이번 페이지 행에만 적용되도록 outer 전용으로 넘깁니다.
     *
     * @return \Closure outer 적용 클로저
     */
    private function reviewAggregateForOuter(): \Closure
    {
        return fn ($outer) => $this->joinReviewAggregate($outer);
    }

    /**
     * 리뷰 집계 파생 테이블의 별칭을 반환합니다.
     *
     * @return string 별칭
     */
    private function reviewAlias(): string
    {
        return 'g7_product_reviews_agg';
    }

    /**
     * {@inheritDoc}
     */
    public function getNewProducts(int $limit = 10): Collection
    {
        $query = $this->model->newQuery()
            ->with(['images', 'categories', 'activeLabelAssignments.label'])
            ->where('display_status', 'visible');

        return $this->joinReviewAggregate($query)
            ->orderBy($this->model->getTable().'.created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findByIds(array $ids): Collection
    {
        if (empty($ids)) {
            return $this->model->newCollection();
        }

        $query = $this->model->newQuery()
            ->with(['images', 'categories', 'activeLabelAssignments.label'])
            ->where('display_status', 'visible')
            ->whereIn($this->model->getTable().'.id', $ids);

        // 다른 목록 경로와 같은 집계를 붙인다. 붙이지 않으면 평점·리뷰 수 별칭이 아예 없어
        // 목록 표현에서 그 필드가 빠지고, 화면은 리뷰가 달린 상품도 통계 없이 그리게 된다.
        $products = $this->joinReviewAggregate($query)->get();

        // 클라이언트 요청 순서 유지 (DB 독립적 정렬)
        $idOrder = array_flip($ids);

        return $products->sortBy(fn ($product) => $idOrder[$product->id] ?? PHP_INT_MAX)->values();
    }

    /**
     * {@inheritDoc}
     */
    public function searchByKeyword(string $keyword, string $orderBy = 'created_at', string $direction = 'desc', ?int $categoryId = null, int $offset = 0, int $limit = 10): BoundedPage
    {
        $page = (int) floor($offset / max(1, $limit)) + 1;

        // FULLTEXT(name/description) 매칭 + 보조필드(product_code/id) 매칭의 합집합.
        // 통합검색도 관리자 검색과 동일하게 상품코드/ID 로 상품을 찾을 수 있어야 한다.
        $query = $this->model->newQuery()
            ->where('display_status', ProductDisplayStatus::VISIBLE->value)
            // audit:allow list-repository-eager-load-vs-resource reason: 통합검색 결과는 Resource 가
            // 아니라 SearchProductsListener 가 직렬화한다 — 그 안에서 primaryCategory 를 행마다
            // 읽으므로(`$product->primaryCategory->first()`) 여기서 미리 로드하지 않으면 N+1 이 된다
            ->with(['images', 'primaryCategory', 'brand', 'activeLabelAssignments.label'])
            ->when($categoryId !== null, fn ($q) => $q->whereHas('categories', fn ($c) => $c->where('ecommerce_categories.id', $categoryId)));

        // 리뷰 건수·평점은 파생 테이블 조인 1회로 얻는다 (상관 서브쿼리 2회 아님)
        $this->joinReviewAggregate($query);

        $productsTable = $this->model->getTable();
        $query->orderBy($productsTable.'.'.$orderBy, $direction)
            // 전순서 보장 — 정렬 컬럼(등록일/판매량 등)이 비고유라 페이지 경계가 흔들릴 수 있다
            ->orderBy($productsTable.'.id', $direction === 'asc' ? 'asc' : 'desc');

        $this->applyKeywordMatch($query, $keyword, limitContext: 'search');

        // audit:allow repository-paginate-column-pruning reason: 통합검색 결과 카드가
        // 이미지·카테고리·브랜드·라벨 관계를 함께 그리므로 모델 전체가 필요하다. 한 페이지
        // 분량만 조회되고, 총 건수에는 상한이 걸려 있다
        return BoundedPaginator::paginate(
            $query,
            perPage: $limit,
            page: $page,
            resultCap: PaginationLimits::resultCap('search'),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function searchByKeywordWithCursor(
        string $keyword,
        array $sortKeys,
        ?int $categoryId = null,
        int $perPage = 10,
        ?string $cursor = null
    ): CursorPaginator {
        $query = $this->model->newQuery()
            ->where('display_status', ProductDisplayStatus::VISIBLE->value)
            ->with(['images', 'primaryCategory', 'brand', 'activeLabelAssignments.label'])
            ->when($categoryId !== null, fn ($q) => $q->whereHas('categories', fn ($c) => $c->where('ecommerce_categories.id', $categoryId)));

        $this->joinReviewAggregate($query);
        $this->applyKeywordMatch($query, $keyword, limitContext: 'search');

        // 정렬 컬럼은 상품 테이블 소속임을 명시한다 — 파생 조인이 붙어 있어 모호해질 수 있다.
        $productsTable = $this->model->getTable();
        $qualified = array_map(
            static fn (array $spec): array => [$productsTable.'.'.$spec[0], $spec[1]],
            $sortKeys
        );

        return KeysetPaginator::paginate(
            query: $query,
            perPage: $perPage,
            sortKeys: $qualified,
            uniqueKey: $productsTable.'.id',
            cursor: $cursor,
        );
    }

    /**
     * 키워드 매칭 조건을 쿼리에 직접 적용합니다.
     *
     * FULLTEXT(name/description) 매칭과 보조필드(product_code / sku / barcode / 숫자 ID)
     * 매칭의 합집합을 하나의 WHERE 그룹으로 만듭니다. 매칭 ID 를 PHP 로 실어 나르지 않으므로
     * 매칭 건수가 아무리 커도 메모리와 SQL 길이가 늘지 않습니다.
     *
     * 결과 조회와 total 계산이 이 메서드 하나를 공유하므로 두 경로의 조건이 갈라지지 않습니다.
     *
     * @param  Builder  $query  대상 쿼리
     * @param  string  $keyword  검색 키워드
     * @param  bool  $includeAuxFields  상품코드·SKU·바코드·ID 보조 매칭 포함 여부
     * @param  string|null  $limitContext  키 집합 상한 해석에 쓸 목록 컨텍스트 이름
     */
    private function applyKeywordMatch($query, string $keyword, bool $includeAuxFields = true, ?string $limitContext = null): void
    {
        $query->where(function ($group) use ($keyword, $includeAuxFields, $limitContext) {
            // 정제는 코어 헬퍼가 단독 수행 — BOOLEAN MODE 연산자 입력이 500 이 되지 않는다.
            // 상품 테이블은 name·description 에 각각 단일 FULLTEXT 인덱스를 갖는다.
            // 복합 MATCH(name, description) 는 그 조합의 복합 인덱스를 요구해 오류가 난다.
            // 상한 컨텍스트를 함께 넘긴다 — 키 집합을 만드는 엔진이 총 건수 상한과 같은
            // 기준으로 잘라 내야 "엔진이 돌려준 건수" 와 "화면이 보고하는 총 건수" 가 어긋나지 않는다.
            KeywordSearch::applyAny($group, ['name', 'description'], $keyword, 'and', $limitContext);

            if (! $includeAuxFields) {
                return;
            }

            $group->orWhere('product_code', 'like', "%{$keyword}%")
                ->orWhere('sku', 'like', "%{$keyword}%")
                ->orWhere('barcode', 'like', "%{$keyword}%");

            // 숫자 키워드는 상품 ID 정확 매칭도 포함 (예: "99" → id=99)
            if (ctype_digit($keyword)) {
                $group->orWhere('id', (int) $keyword);
            }
        });
    }

    /**
     * {@inheritDoc}
     */
    public function countByKeyword(string $keyword, ?int $categoryId = null): BoundedCount
    {
        // searchByKeyword 와 동일한 매칭 조건(FULLTEXT ∪ 보조필드)으로 total 산출.
        $query = $this->model->newQuery()
            ->where('display_status', ProductDisplayStatus::VISIBLE->value)
            ->when($categoryId !== null, fn ($q) => $q->whereHas('categories', fn ($c) => $c->where('ecommerce_categories.id', $categoryId)));

        $this->applyKeywordMatch($query, $keyword);

        // 배지용 건수 — 상한을 걸어 대량 매칭에서도 비용이 일정하다.
        // 상한에 걸리면 값이 잘리므로 정확도를 함께 돌려준다.
        return BoundedPaginator::count($query, PaginationLimits::resultCap('search'));
    }

    /**
     * 관리자 상품 목록 필터를 쿼리에 적용합니다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  array  $filters  필터 배열
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

            // whereDate 는 인덱스를 무력화한다 — 범위 조건으로 준다.
            $this->applyDateRangeFilter(
                $query,
                $dateField,
                $filters['start_date'] ?? null,
                $filters['end_date'] ?? null
            );
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

            // 0 은 유효한 경계값 — empty() 판정 금지 (min_stock/max_stock 과 동일 규칙)
            if (isset($filters['min_price']) && $filters['min_price'] !== '') {
                $query->where($priceField, '>=', (float) $filters['min_price']);
            }
            if (isset($filters['max_price']) && $filters['max_price'] !== '') {
                $query->where($priceField, '<=', (float) $filters['max_price']);
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
     * 관리자 상품 목록 정렬 스펙을 해석합니다.
     *
     * 지연 조인은 정렬을 inner/outer 양쪽에 동일하게 적용해야 하므로, 쿼리에 직접 orderBy 를
     * 거는 대신 스펙 배열을 돌려준다.
     *
     * @param  array  $filters  필터 배열
     * @return array<int, array{column: string, direction: string}> 정렬 스펙
     */
    private function resolveAdminSortSpec(array $filters): array
    {
        // 허용 컬럼 화이트리스트로 해석 (없는 컬럼 정렬은 기본값으로 흡수)
        $sort = $this->resolveSortSpec($filters, self::ADMIN_SORTABLE_COLUMNS, 'created_at')[0];

        // 다국어 이름 정렬 처리
        if ($sort['column'] === 'name') {
            $sort['column'] = 'name->'.app()->getLocale();
        }

        return [$sort];
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

    /**
     * ID 목록으로 상품을 조회하고 ID 키 맵으로 반환합니다 (bulk activity log lookup).
     *
     * @param  array<int, int>  $ids  상품 ID 목록
     * @return Collection ID 를 키로 하는 상품 컬렉션
     */
    public function findByIdsKeyed(array $ids): Collection
    {
        if (empty($ids)) {
            return new Collection;
        }

        return $this->model->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * 상품의 stock_quantity 컬럼만 갱신합니다 (옵션 재고 합계 동기화 전용).
     *
     * @param  int  $productId  상품 ID
     * @param  int  $stock  새 재고 수량
     * @return int 업데이트된 행 수
     */
    public function updateStockQuantity(int $productId, int $stock): int
    {
        $affected = $this->model->where('id', $productId)->update(['stock_quantity' => $stock]);

        // 통계에 품절·재고부족 건수가 들어 있으므로 재고 변경도 무효화 대상이다
        $this->forgetStatisticsCache();

        return $affected;
    }

    /**
     * {@inheritDoc}
     */
    public function getActivityLogsForProduct(Product $product, array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 10);
        $sortOrder = ($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $optionIds = $product->options()->pluck('id')->toArray();

        $query = ActivityLog::where(function (Builder $q) use ($product, $optionIds) {
            // 상품 자체 로그
            $q->where(function (Builder $sub) use ($product) {
                $sub->where('loggable_type', $product->getMorphClass())
                    ->where('loggable_id', $product->getKey());
            });

            // 해당 상품의 옵션 로그
            if (! empty($optionIds)) {
                $q->orWhere(function (Builder $sub) use ($optionIds) {
                    $sub->where('loggable_type', (new ProductOption)->getMorphClass())
                        ->whereIn('loggable_id', $optionIds);
                });
            }
        });

        // 활동 로그는 변경 내역(mediumText)을 리소스가 그대로 노출하므로 컬럼은 좁히지 않고
        // 지연 조인으로 넓은 컬럼을 읽는 행 수만 이번 페이지 분량으로 고정한다.
        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ['*'],
            sort: [['column' => 'created_at', 'direction' => $sortOrder]],
            perPage: $perPage,
            resultCap: PaginationLimits::resultCap('admin.activity_logs'),
        );
    }
}
