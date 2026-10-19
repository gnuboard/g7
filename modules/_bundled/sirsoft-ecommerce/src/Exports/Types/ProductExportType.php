<?php

namespace Modules\Sirsoft\Ecommerce\Exports\Types;

use App\Extension\HookManager;
use App\Models\User;
use App\Support\SiteUrl;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Sirsoft\Ecommerce\Enums\ExportTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductDateType;
use Modules\Sirsoft\Ecommerce\Exports\ExportFieldType;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ProductListRequest;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Sources\EcommerceListQueries;

/**
 * 상품내역 내보내기
 *
 * 행 단위 둘:
 * - 상품별(product): 상품 1건 = 1행
 * - 옵션(SKU)별(option): 옵션 1건 = 1행 — 물류는 SKU 단위가 필요하다. 상품 단위 합계 값(재고)은 그 상품의
 *   첫 행에만 쓴다
 *
 * 원천 쿼리는 상품 목록과 같은 조건({@see EcommerceListQueries::products()} — 같은 권한 범위·같은 검색어·필터)다.
 */
class ProductExportType extends AbstractExportType
{
    public const UNIT_PRODUCT = 'product';

    public const UNIT_OPTION = 'option';

    /**
     * 한 번에 읽는 상품 수
     */
    private const CHUNK = 500;

    /**
     * @param  EcommerceListQueries  $sources  이커머스 목록과 같은 조건의 원천 쿼리
     */
    public function __construct(
        private readonly EcommerceListQueries $sources,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return ExportTypeEnum::PRODUCTS->value;
    }

    /**
     * {@inheritDoc}
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::enums.export_type.products';
    }

    /**
     * {@inheritDoc}
     */
    public function readPermission(): string
    {
        return 'sirsoft-ecommerce.products.read';
    }

    /**
     * {@inheritDoc}
     */
    public function rowUnits(): array
    {
        return [
            self::UNIT_PRODUCT => 'sirsoft-ecommerce::export.row_units.product',
            self::UNIT_OPTION => 'sirsoft-ecommerce::export.row_units.option',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function dateTypes(): array
    {
        $types = [];
        foreach (ProductDateType::cases() as $case) {
            $types[$case->value] = 'sirsoft-ecommerce::export.date_types.products.'.$case->value;
        }

        return $types;
    }

    /**
     * {@inheritDoc}
     */
    public function periodRequired(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function listRequestClass(): string
    {
        return ProductListRequest::class;
    }

    /**
     * {@inheritDoc}
     */
    public function screenColumns(): array
    {
        $label = static fn (string $k) => 'sirsoft-ecommerce::export.screen.products.'.$k;

        return [
            'thumbnail' => ['label_key' => $label('thumbnail'), 'fields' => ['product.thumbnail_url']],
            'name' => ['label_key' => $label('name'), 'fields' => [
                'product.product_code', 'product.name', 'option.option_code', 'option.sku', 'option.option_name',
            ]],
            'categories' => ['label_key' => $label('categories'), 'fields' => ['product.category_paths']],
            'list_price_formatted' => ['label_key' => $label('list_price_formatted'), 'fields' => [
                'product.currency_code', 'product.list_price', 'option.list_price',
            ]],
            'selling_price_formatted' => ['label_key' => $label('selling_price_formatted'), 'fields' => [
                'product.selling_price', 'option.price_adjustment', 'option.selling_price',
            ]],
            'stock_quantity' => ['label_key' => $label('stock_quantity'), 'fields' => [
                'product.stock_quantity', 'option.stock_quantity', 'option.safe_stock_quantity',
            ]],
            'sales_status' => ['label_key' => $label('sales_status'), 'fields' => ['product.sales_status']],
            'display_status' => ['label_key' => $label('display_status'), 'fields' => ['product.display_status']],
            'brand_name' => ['label_key' => $label('brand_name'), 'fields' => ['product.brand_name']],
            'min_purchase_qty' => ['label_key' => $label('min_purchase_qty'), 'fields' => ['product.min_purchase_qty']],
            'max_purchase_qty' => ['label_key' => $label('max_purchase_qty'), 'fields' => ['product.max_purchase_qty']],
            'shipping_policy_name' => ['label_key' => $label('shipping_policy_name'), 'fields' => ['product.shipping_policy_name']],
            'created_at' => ['label_key' => $label('created_at'), 'fields' => ['product.created_at']],
            'updated_at' => ['label_key' => $label('updated_at'), 'fields' => ['product.updated_at']],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function excludedScreenColumns(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    protected function extendFields(array $fields, string $unit): mixed
    {
        return HookManager::applyFilters('sirsoft-ecommerce.export.products.fields', $fields, $unit);
    }

    /**
     * {@inheritDoc}
     */
    protected function buildFields(string $unit): array
    {
        $isOption = $unit === self::UNIT_OPTION;
        $productOf = $isOption ? static fn (object $row) => $row->product : static fn (object $row) => $row;

        $groups = ['product' => [], 'option' => []];
        foreach ($this->screenColumns() as $screenKey => $def) {
            foreach ($def['fields'] as $fieldKey) {
                [$source, $column] = explode('.', $fieldKey, 2);
                if (isset($groups[$source])) {
                    $groups[$source][$column] = $screenKey;
                }
            }
        }

        $fields = $this->declaredFields(Product::class, 'product', $productOf, $groups['product'], [], parentLevel: $isOption);

        if ($isOption) {
            $fields = array_merge($fields, $this->declaredFields(ProductOption::class, 'option', static fn (object $row) => $row, $groups['option'], [self::UNIT_OPTION]));
        }

        // 파일은 사이트 밖(스프레드시트)에서 열리므로 상대 경로(`/api/...`)는 사이트 주소를 붙여 절대 주소로 낸다
        $fields[] = $this->computed('product.thumbnail_url', ExportFieldType::STRING, false,
            static function (object $row) use ($productOf) {
                $url = $productOf($row)?->getThumbnailUrl();

                return is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//') ? rtrim(SiteUrl::base(), '/').$url : $url;
            }, 'thumbnail');
        $fields[] = $this->computed('product.category_paths', ExportFieldType::LIST, false,
            static fn (object $row) => $productOf($row)?->categories
                ->map(fn (Category $c) => collect($c->getBreadcrumb())->pluck('name')->implode(' > '))
                ->all(), 'categories');
        $fields[] = $this->computed('product.brand_name', ExportFieldType::TRANSLATABLE, false,
            static fn (object $row) => $productOf($row)?->brand?->name, 'brand_name');
        $fields[] = $this->computed('product.shipping_policy_name', ExportFieldType::TRANSLATABLE, false,
            static fn (object $row) => $productOf($row)?->shippingPolicy?->name, 'shipping_policy_name');

        return $fields;
    }

    /**
     * {@inheritDoc}
     */
    public function query(array $filters, ?array $ids, User $requester, ?CarbonInterface $cutoff): Builder
    {
        $query = $this->sources->products($filters, $ids, $requester);

        if ($cutoff !== null) {
            $query->where('created_at', '<', $cutoff);
        }

        return $query;
    }

    /**
     * {@inheritDoc}
     */
    public function countRows(Builder $query, string $unit, ?CarbonInterface $cutoff = null): int
    {
        if ($unit === self::UNIT_OPTION) {
            // 옵션은 상품과 따로 생긴다 — 요청 뒤에 추가된 옵션은 상품의 기준 시각으로 걸러지지 않는다
            return ProductOption::query()->whereIn('product_id', (clone $query)->select('id'))
                ->when($cutoff !== null, fn ($q) => $q->where('created_at', '<', $cutoff))
                ->count();
        }

        return (clone $query)->count();
    }

    /**
     * {@inheritDoc}
     */
    public function rows(Builder $query, string $unit, ?CarbonInterface $cutoff = null): Generator
    {
        $relations = ['images', 'categories', 'brand', 'shippingPolicy'];
        if ($unit === self::UNIT_OPTION) {
            $relations['options'] = fn ($q) => $q->when($cutoff !== null, fn ($q) => $q->where('created_at', '<', $cutoff))
                ->orderBy('sort_order')->orderBy('id');
        }

        // 최신 상품부터, ID 키셋으로 청크를 읽는다 — 카테고리 경로의 조상 조회를 청크마다 한 번으로 묶으려면
        // 청크 경계를 직접 알아야 한다(lazy 순회는 경계를 숨긴다).
        $lastId = null;
        do {
            $chunkQuery = (clone $query)->with($relations)->orderByDesc('id')->limit(self::CHUNK);
            if ($lastId !== null) {
                $chunkQuery->where('id', '<', $lastId);
            }
            $chunk = $chunkQuery->get();

            Category::prefetchAncestorsFor($chunk->flatMap(fn (Product $p) => $p->categories)->unique('id'));

            foreach ($chunk as $product) {
                if ($unit === self::UNIT_OPTION) {
                    $first = true;
                    foreach ($product->options as $option) {
                        $option->setRelation('product', $product);
                        yield [$option, $first, $option->currency_code ?: $product->currency_code];
                        $first = false;
                    }

                    continue;
                }

                yield [$product, true, $product->currency_code];
            }

            $lastId = $chunk->last()?->id;
        } while ($chunk->count() === self::CHUNK);
    }
}
