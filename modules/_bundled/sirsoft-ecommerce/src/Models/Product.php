<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Casts\AsUnicodeJson;
use App\Extension\HookManager;
use App\Search\Contracts\FulltextSearchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Laravel\Scout\Searchable;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;

/**
 * 상품 모델
 */
class Product extends Model implements FulltextSearchable
{
    use HasFactory;
    use Searchable;
    use SoftDeletes;

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'sales_status' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.sales_status', 'type' => 'enum', 'enum' => \Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus::class],
        'display_status' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.display_status', 'type' => 'enum', 'enum' => \Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus::class],
        'tax_status' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.tax_status', 'type' => 'enum', 'enum' => \Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus::class],
        'tax_rate' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.tax_rate', 'type' => 'number'],
        'list_price' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.list_price', 'type' => 'currency'],
        'selling_price' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.selling_price', 'type' => 'currency'],
        'stock_quantity' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.stock_quantity', 'type' => 'number'],
        'safe_stock_quantity' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.safe_stock_quantity', 'type' => 'number'],
        'has_options' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.has_options', 'type' => 'boolean'],
        'brand_id' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.brand_id', 'type' => 'number'],
        'shipping_policy_id' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.shipping_policy_id', 'type' => 'number'],
        'common_info_id' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.common_info_id', 'type' => 'number'],
        'min_purchase_qty' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.min_purchase_qty', 'type' => 'number'],
        'max_purchase_qty' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.max_purchase_qty', 'type' => 'number'],
    ];

    protected static function newFactory()
    {
        return ProductFactory::new();
    }

    /**
     * 라우트 모델 바인딩 시 ID 또는 product_code로 상품을 조회합니다.
     *
     * @param  mixed  $value  라우트 파라미터 값 (ID 또는 product_code)
     * @param  string|null  $field  검색할 필드명
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field) {
            return $this->where($field, $value)->firstOrFail();
        }

        if (is_numeric($value)) {
            return $this->where('id', $value)->firstOrFail();
        }

        return $this->where('product_code', $value)->firstOrFail();
    }

    protected $table = 'ecommerce_products';

    protected $fillable = [
        'name',
        'product_code',
        'sales_product_code',
        'sku',
        'brand_id',
        'list_price',
        'selling_price',
        'currency_code',
        'stock_quantity',
        'safe_stock_quantity',
        'sales_status',
        'display_status',
        'tax_status',
        'tax_rate',
        'shipping_policy_id',
        'common_info_id',
        'description',
        'description_mode',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'has_options',
        'option_groups',
        'min_purchase_qty',
        'max_purchase_qty',
        'purchase_restriction',
        'allowed_roles',
        'barcode',
        'hs_code',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'name' => AsUnicodeJson::class,
        'description' => AsUnicodeJson::class,
        'meta_keywords' => 'array',
        'option_groups' => 'array',
        'allowed_roles' => 'array',
        'has_options' => 'boolean',
        'list_price' => 'integer',
        'selling_price' => 'integer',
        'stock_quantity' => 'integer',
        'safe_stock_quantity' => 'integer',
        'min_purchase_qty' => 'integer',
        'max_purchase_qty' => 'integer',
        'tax_rate' => 'decimal:2',
        'sales_status' => ProductSalesStatus::class,
        'display_status' => ProductDisplayStatus::class,
        'tax_status' => ProductTaxStatus::class,
    ];

    /**
     * 이 상품의 옵션 관계 (color/size 등 가변 옵션 정의).
     *
     * @return HasMany ProductOption 컬렉션 관계
     */
    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class, 'product_id');
    }

    /**
     * 이 상품의 활성 옵션만 sort_order 정렬로 반환합니다 (`is_active=true`).
     *
     * @return HasMany 활성 ProductOption 컬렉션 관계
     */
    public function activeOptions(): HasMany
    {
        return $this->options()->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * 이 상품의 모든 이미지 관계 (sort_order 정렬).
     *
     * @return HasMany ProductImage 컬렉션 관계
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'product_id')->orderBy('sort_order');
    }

    /**
     * 이 상품의 대표 이미지 관계 (`is_thumbnail=true`).
     *
     * @return HasMany 대표 ProductImage 관계
     */
    public function thumbnail(): HasMany
    {
        return $this->images()->where('is_thumbnail', true);
    }

    /**
     * 대표 이미지 서빙 URL 을 반환합니다.
     *
     * 대표 이미지(`is_thumbnail=true`) 가 없으면 첫 번째 이미지로 폴백.
     *
     * @return string|null 대표 이미지 download_url 또는 이미지가 없으면 null
     */
    public function getThumbnailUrl(): ?string
    {
        $thumbnailImage = $this->images()->where('is_thumbnail', true)->first()
            ?? $this->images()->first();

        return $thumbnailImage?->download_url;
    }

    /**
     * 이 상품과 카테고리의 다대다 관계 (`ecommerce_product_categories` 피벗, is_primary 포함).
     *
     * @return BelongsToMany Category 다대다 관계
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            Category::class,
            'ecommerce_product_categories',
            'product_id',
            'category_id'
        )->withPivot('is_primary');
    }

    /**
     * 이 상품의 대표 카테고리만 반환합니다 (피벗 `is_primary=true`).
     *
     * @return BelongsToMany 대표 Category 다대다 관계
     */
    public function primaryCategory(): BelongsToMany
    {
        return $this->categories()->wherePivot('is_primary', true);
    }

    /**
     * 브랜드 관계
     *
     * @return BelongsTo 브랜드 모델과의 관계
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    /**
     * 공통정보 관계
     *
     * @return BelongsTo 공통정보 모델과의 관계
     */
    public function commonInfo(): BelongsTo
    {
        return $this->belongsTo(ProductCommonInfo::class, 'common_info_id');
    }

    /**
     * 배송정책 관계
     *
     * @return BelongsTo 배송정책 모델과의 관계
     */
    public function shippingPolicy(): BelongsTo
    {
        return $this->belongsTo(ShippingPolicy::class, 'shipping_policy_id');
    }

    /**
     * 추가옵션 관계
     *
     * @return HasMany 추가옵션 모델과의 관계
     */
    public function additionalOptions(): HasMany
    {
        return $this->hasMany(ProductAdditionalOption::class, 'product_id')
            ->orderBy('sort_order');
    }

    /**
     * 라벨 할당 관계
     *
     * @return HasMany 라벨 할당 모델과의 관계
     */
    public function labelAssignments(): HasMany
    {
        return $this->hasMany(ProductLabelAssignment::class, 'product_id');
    }

    /**
     * 현재 활성인 라벨 할당만 조회
     *
     * @return HasMany 활성 라벨 할당과의 관계
     */
    public function activeLabelAssignments(): HasMany
    {
        return $this->labelAssignments()->currentlyActive();
    }

    /**
     * 상품정보제공고시 관계
     *
     * @return HasOne 상품정보제공고시 모델과의 관계
     */
    public function notice(): HasOne
    {
        return $this->hasOne(ProductNotice::class, 'product_id');
    }

    /**
     * 상품 찜 관계
     *
     * @return HasMany 찜 모델과의 관계
     */
    public function wishlists(): HasMany
    {
        return $this->hasMany(ProductWishlist::class, 'product_id');
    }

    /**
     * 현재 로그인 사용자의 찜 관계 (eager loading용)
     *
     * @return HasMany 현재 사용자의 찜 관계
     */
    public function currentUserWishlist(): HasMany
    {
        return $this->hasMany(ProductWishlist::class, 'product_id')
            ->where('user_id', Auth::id());
    }

    /**
     * 상품 리뷰 관계
     *
     * @return HasMany
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class, 'product_id');
    }

    /**
     * 전시중(visible) 리뷰만 조회 (withCount/withAvg eager loading용)
     *
     * @return HasMany
     */
    public function visibleReviews(): HasMany
    {
        return $this->reviews()->where('status', ReviewStatus::VISIBLE->value);
    }

    /**
     * 검색엔진 색인용 — 직접 카테고리 ID 와 그 모든 상위(ancestor) 카테고리 ID 의 합집합을 반환합니다.
     *
     * Category 의 `path` 필드(예: `"1/5/23"`) 를 분해하여 조상 ID 를 포함시킵니다.
     *
     * @return array<int, int> 직접/상위 카테고리 ID 의 unique 배열
     */
    public function getAllCategoryIds(): array
    {
        $categoryIds = [];

        foreach ($this->categories as $category) {
            // 현재 카테고리 ID
            $categoryIds[] = $category->id;

            // path에서 상위 카테고리 ID 추출 (예: "1/5/23" → [1, 5, 23])
            if (! empty($category->path)) {
                $ancestorIds = array_map('intval', explode('/', $category->path));
                $categoryIds = array_merge($categoryIds, $ancestorIds);
            }
        }

        return array_unique($categoryIds);
    }

    /**
     * 현재 로케일의 상품명을 반환합니다 (다국어 fallback chain 적용).
     *
     * @param  string|null  $locale  반환할 로케일. null 이면 현재 앱 로케일 사용
     * @return string 로케일별 상품명, 누락 시 fallback 로케일/첫 번째 키 순으로 시도
     */
    public function getLocalizedName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $name = $this->name;

        return $name[$locale] ?? $name[config('app.fallback_locale', 'ko')] ?? $name[array_key_first($name)] ?? '';
    }

    /**
     * 현재 로케일의 상세 설명을 반환합니다 (다국어 fallback chain 적용).
     *
     * @param  string|null  $locale  반환할 로케일. null 이면 현재 앱 로케일 사용
     * @return string|null 로케일별 상세 설명, description 이 비어있으면 null
     */
    public function getLocalizedDescription(?string $locale = null): ?string
    {
        if (empty($this->description)) {
            return null;
        }

        $locale = $locale ?? app()->getLocale();
        $desc = $this->description;

        return $desc[$locale] ?? $desc[config('app.fallback_locale', 'ko')] ?? $desc[array_key_first($desc)] ?? null;
    }

    /**
     * 현재 로케일의 옵션 그룹 반환
     *
     * @param  string|null  $locale  로케일 (null이면 현재 로케일)
     * @return array 로컬라이즈된 옵션 그룹 배열
     */
    public function getLocalizedOptionGroups(?string $locale = null): array
    {
        $locale = $locale ?? app()->getLocale();
        $groups = $this->option_groups;

        if (! is_array($groups) || empty($groups)) {
            return [];
        }

        $result = [];
        foreach ($groups as $group) {
            $name = $group['name'] ?? '';
            $values = $group['values'] ?? [];

            // name이 다국어 객체인 경우
            $localizedName = is_array($name) ? ($name[$locale] ?? $name[config('app.fallback_locale', 'ko')] ?? array_values($name)[0] ?? '') : $name;

            // values가 다국어 객체 배열인 경우
            $localizedValues = [];
            foreach ($values as $value) {
                $localizedValues[] = is_array($value) ? ($value[$locale] ?? $value[config('app.fallback_locale', 'ko')] ?? array_values($value)[0] ?? '') : $value;
            }

            $result[] = [
                'name' => $localizedName,
                'values' => $localizedValues,
            ];
        }

        return $result;
    }

    /**
     * 옵션 그룹을 API 응답용으로 변환 (다국어 원본 + localized 필드 포함)
     *
     * @param  string|null  $locale  로케일 (null이면 현재 로케일)
     * @return array API 응답용 옵션 그룹 배열
     */
    public function getOptionGroupsForApi(?string $locale = null): array
    {
        $locale = $locale ?? app()->getLocale();
        $groups = $this->option_groups;

        if (! is_array($groups) || empty($groups)) {
            return [];
        }

        $result = [];
        foreach ($groups as $group) {
            $name = $group['name'] ?? '';
            $values = $group['values'] ?? [];

            // name_localized
            $nameLocalized = is_array($name) ? ($name[$locale] ?? $name[config('app.fallback_locale', 'ko')] ?? array_values($name)[0] ?? '') : $name;

            // values_localized
            $valuesLocalized = [];
            foreach ($values as $value) {
                $valuesLocalized[] = is_array($value) ? ($value[$locale] ?? $value[config('app.fallback_locale', 'ko')] ?? array_values($value)[0] ?? '') : $value;
            }

            $result[] = [
                'name' => $name,
                'name_localized' => $nameLocalized,
                'values' => $values,
                'values_localized' => $valuesLocalized,
            ];
        }

        return $result;
    }

    /**
     * 옵션 재고 합계를 계산합니다.
     *
     * 옵션이 있는 상품(`has_options=true`)은 활성 옵션 재고 합계를, 그 외는 상품 자체 재고를 반환.
     *
     * @return int 옵션 재고 합 또는 단일 재고 수량
     */
    public function calculateOptionStockSum(): int
    {
        if (! $this->has_options) {
            return $this->stock_quantity;
        }

        return $this->options()->where('is_active', true)->sum('stock_quantity');
    }

    /**
     * 상품 본체 재고 와 옵션 재고 합이 일치하는지 검증합니다.
     *
     * 옵션 없는 상품은 항상 true. 옵션 있는 상품은 합산이 상품 재고와 같아야 true.
     *
     * @return bool 일치하면 true
     */
    public function isStockConsistent(): bool
    {
        if (! $this->has_options) {
            return true;
        }

        return $this->stock_quantity === $this->calculateOptionStockSum();
    }

    /**
     * 현재 재고가 안전재고 임계값 이하인지 검사합니다.
     *
     * @return bool stock_quantity <= safe_stock_quantity 면 true
     */
    public function isBelowSafeStock(): bool
    {
        return $this->stock_quantity <= $this->safe_stock_quantity;
    }

    /**
     * 정가 대비 판매가의 할인율(%) 을 소수 1자리로 반환합니다.
     *
     * @return float 할인율(%) — 정가가 0 이하면 0
     */
    public function getDiscountRate(): float
    {
        if ($this->list_price <= 0) {
            return 0;
        }

        return round((1 - $this->selling_price / $this->list_price) * 100, 1);
    }

    /**
     * 주문 스냅샷용 배열 반환
     *
     * 주문 생성 시점의 상품 정보를 스냅샷으로 저장하기 위한 데이터
     *
     * @return array 스냅샷 데이터
     */
    public function toSnapshotArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'product_code' => $this->product_code,
            'sku' => $this->sku,
            'brand_id' => $this->brand_id,
            'list_price' => $this->list_price,
            'selling_price' => $this->selling_price,
            'currency_code' => $this->currency_code,
            'stock_quantity' => $this->stock_quantity,
            'tax_status' => $this->tax_status?->value,
            'tax_rate' => $this->tax_rate,
            'has_options' => $this->has_options,
            'option_groups' => $this->option_groups,
            'thumbnail_url' => $this->getThumbnailUrl(),
        ];
    }

    // ─── FulltextSearchable 구현 ─────────────────────────

    /**
     * FULLTEXT 검색 대상 컬럼 목록을 반환합니다.
     *
     * @return array<string>
     */
    public function searchableColumns(): array
    {
        return ['name', 'description'];
    }

    /**
     * 컬럼별 검색 가중치를 반환합니다.
     *
     * @return array<string, float>
     */
    public function searchableWeights(): array
    {
        return [
            'name' => 2.0,
            'description' => 1.0,
        ];
    }

    /**
     * 검색 인덱스용 배열을 반환합니다.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
        ];
    }

    /**
     * MySQL FULLTEXT 엔진에서는 인덱스 업데이트가 불필요합니다.
     *
     * @return bool
     */
    public function searchIndexShouldBeUpdated(): bool
    {
        $default = config('scout.driver') !== 'mysql-fulltext';

        return HookManager::applyFilters(
            'sirsoft-ecommerce.search.product.index_should_update',
            $default,
            $this
        );
    }
}
