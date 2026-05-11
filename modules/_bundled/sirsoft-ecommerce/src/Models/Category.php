<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Casts\AsUnicodeJson;
use App\Extension\HookManager;
use App\Search\Contracts\FulltextSearchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class Category extends Model implements FulltextSearchable
{
    use Searchable;
    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'parent_id' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.parent_id', 'type' => 'number'],
        'sort_order' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.sort_order', 'type' => 'number'],
        'is_active' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.is_active', 'type' => 'boolean'],
        'slug' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.slug', 'type' => 'text'],
        'meta_title' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.meta_title', 'type' => 'text'],
        'meta_description' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.meta_description', 'type' => 'text'],
    ];

    protected $table = 'ecommerce_categories';

    protected $fillable = [
        'name',
        'description',
        'parent_id',
        'path',
        'depth',
        'sort_order',
        'is_active',
        'slug',
        'meta_title',
        'meta_description',
    ];

    protected $casts = [
        'name' => AsUnicodeJson::class,
        'description' => AsUnicodeJson::class,
        'parent_id' => 'integer',
        'depth' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * 부모 카테고리 관계
     *
     * @return BelongsTo
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * 직계 자식 카테고리 관계
     *
     * @return HasMany
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * 활성 상태의 자식 카테고리만 조회
     *
     * @return HasMany
     */
    public function activeChildren(): HasMany
    {
        return $this->children()->where('is_active', true);
    }

    /**
     * 모든 하위 카테고리 재귀 조회
     *
     * @return HasMany
     */
    public function descendants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->with('descendants');
    }

    /**
     * 카테고리 이미지 관계
     *
     * @return HasMany
     */
    public function images(): HasMany
    {
        return $this->hasMany(CategoryImage::class, 'category_id')->orderBy('sort_order');
    }

    /**
     * 해당 카테고리에 속한 상품들
     *
     * @return BelongsToMany
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'ecommerce_product_categories',
            'category_id',
            'product_id'
        )->withPivot('is_primary');
    }

    /**
     * 현재 로케일의 카테고리명 반환
     *
     * @param string|null $locale 로케일 (기본값: 현재 앱 로케일)
     * @return string
     */
    public function getLocalizedName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $name = $this->name;

        return $name[$locale] ?? $name[config('app.fallback_locale', 'ko')] ?? $name[array_key_first($name)] ?? '';
    }

    /**
     * path에서 조상 카테고리 ID 배열 반환 (자기 자신 제외)
     *
     * @return array<int>
     */
    public function getAncestorIds(): array
    {
        if (empty($this->path)) {
            return [];
        }

        $ids = array_map('intval', explode('/', $this->path));

        // 마지막 요소는 자기 자신이므로 제외
        array_pop($ids);

        return $ids;
    }

    /**
     * 조상 카테고리들 조회
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAncestors(): \Illuminate\Database\Eloquent\Collection
    {
        $ancestorIds = $this->getAncestorIds();

        if (empty($ancestorIds)) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        $ancestors = self::whereIn('id', $ancestorIds)->get();
        $orderedIds = array_flip($ancestorIds);

        return $ancestors->sortBy(fn ($item) => $orderedIds[$item->id] ?? PHP_INT_MAX)->values();
    }

    /**
     * 브레드크럼 데이터 생성 (조상 + 현재 카테고리)
     *
     * @return array
     */
    public function getBreadcrumb(): array
    {
        $ancestors = $this->getAncestors();
        $breadcrumb = [];

        foreach ($ancestors as $ancestor) {
            $breadcrumb[] = [
                'id' => $ancestor->id,
                'name' => $ancestor->getLocalizedName(),
                'slug' => $ancestor->slug,
            ];
        }

        // 현재 카테고리 추가
        $breadcrumb[] = [
            'id' => $this->id,
            'name' => $this->getLocalizedName(),
            'slug' => $this->slug,
        ];

        return $breadcrumb;
    }

    /**
     * 로컬라이즈된 브레드크럼 문자열 반환
     *
     * 예: "가구 > 책상 > 컴퓨터책상"
     *
     * @param string|null $locale 로케일 (기본값: 현재 앱 로케일)
     * @param string $separator 구분자 (기본값: ' > ')
     * @return string
     */
    public function getLocalizedBreadcrumbString(?string $locale = null, string $separator = ' > '): string
    {
        $locale = $locale ?? app()->getLocale();
        $ancestors = $this->getAncestors();
        $names = [];

        foreach ($ancestors as $ancestor) {
            $names[] = $ancestor->getLocalizedName($locale);
        }

        // 현재 카테고리 추가
        $names[] = $this->getLocalizedName($locale);

        return implode($separator, $names);
    }

    /**
     * path 자동 생성 (저장 전 호출)
     *
     * @return void
     */
    public function generatePath(): void
    {
        if ($this->parent_id) {
            $parent = self::find($this->parent_id);
            if ($parent) {
                $this->path = $parent->path ? $parent->path . '/' . $this->id : (string) $this->id;
                $this->depth = $parent->depth + 1;
            }
        } else {
            $this->path = (string) $this->id;
            $this->depth = 0;
        }
    }

    /**
     * 루트 카테고리만 조회 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * 활성 카테고리만 조회 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * 특정 깊이의 카테고리 조회 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $depth
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAtDepth($query, int $depth)
    {
        return $query->where('depth', $depth);
    }

    /**
     * 트리 구조로 카테고리 조회 (재귀)
     *
     * @param int|null $parentId 부모 ID (null이면 루트부터)
     * @param bool $onlyActive 활성 카테고리만 조회할지 여부
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getTree(?int $parentId = null, bool $onlyActive = false): \Illuminate\Database\Eloquent\Collection
    {
        $query = self::with([
                'images',
                'parent:id,name,slug', // parent에서 필요한 필드만 선택
            ])
            ->withCount('products')
            ->where('parent_id', $parentId)
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($onlyActive) {
            $query->where('is_active', true);
        }

        $categories = $query->get();

        foreach ($categories as $category) {
            $category->setRelation('children', self::getTree($category->id, $onlyActive));
        }

        return $categories;
    }

    /**
     * 플랫 리스트로 변환 (들여쓰기용 depth 포함)
     *
     * @param \Illuminate\Database\Eloquent\Collection|null $categories
     * @param string $indent 들여쓰기 문자
     * @return array
     */
    public static function toFlatList(?\Illuminate\Database\Eloquent\Collection $categories = null, string $indent = '　'): array
    {
        if ($categories === null) {
            $categories = self::getTree();
        }

        $result = [];

        foreach ($categories as $category) {
            $prefix = str_repeat($indent, $category->depth);
            $result[] = [
                'id' => $category->id,
                'name' => $prefix . $category->getLocalizedName(),
                'depth' => $category->depth,
                'is_active' => $category->is_active,
            ];

            if ($category->relationLoaded('children') && $category->children->isNotEmpty()) {
                $result = array_merge($result, self::toFlatList($category->children, $indent));
            }
        }

        return $result;
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
     * MySQL FULLTEXT 엔진에서는 인덱스 업데이트가 불필요합니다.
     *
     * @return bool
     */
    public function searchIndexShouldBeUpdated(): bool
    {
        $default = config('scout.driver') !== 'mysql-fulltext';

        return HookManager::applyFilters(
            'sirsoft-ecommerce.search.category.index_should_update',
            $default,
            $this
        );
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
}
