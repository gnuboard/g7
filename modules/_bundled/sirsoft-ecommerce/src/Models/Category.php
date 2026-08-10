<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Casts\AsUnicodeJson;
use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;
use App\Search\Contracts\FulltextSearchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
     * 주어진 카테고리 ID와 그 모든 하위 카테고리 ID를 path 기반으로 반환합니다.
     *
     * @param  int  $categoryId  기준 카테고리 ID
     * @return array<int> 자기 자신 + 모든 하위 카테고리 ID
     */
    public static function selfAndDescendantIds(int $categoryId): array
    {
        $self = self::find($categoryId, ['id', 'path']);
        if (! $self) {
            return [$categoryId];
        }

        $ids = self::where('id', $categoryId)
            ->orWhere(function ($q) use ($self) {
                // path 공백 레거시 행 방어 (실DB 측정: 공백 0건이나 안전망 유지)
                if (! empty($self->path)) {
                    $q->where('path', 'like', $self->path.'/%');
                }
            })
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();

        return ! empty($ids) ? $ids : [$categoryId];
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
     * @param  string|null  $locale  로케일 (기본값: 현재 앱 로케일)
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
     * 조상 카테고리 예열 캐시 (요청 스코프)
     *
     * @var array<int, self>
     */
    private static array $ancestorPrefetch = [];

    /**
     * 트리 캐시 TTL (초)
     *
     * 정상 경로의 무효화는 훅이 담당하므로 이 값은 훅이 닿지 않는 변경에 대한 안전망이다.
     */
    private const TREE_CACHE_TTL = 600;

    /**
     * 조상 카테고리들 조회
     *
     * @return Collection
     */
    public function getAncestors(): Collection
    {
        $ancestorIds = $this->getAncestorIds();

        if (empty($ancestorIds)) {
            return new Collection;
        }

        // 한 응답에서 여러 카테고리의 조상을 그리는 경우(상품 목록의 분류 경로 등)
        // 같은 조상을 반복해 조회하지 않도록, 미리 예열해 둔 맵을 먼저 본다.
        $prefetched = self::$ancestorPrefetch;
        $missing = array_values(array_filter($ancestorIds, fn ($id) => ! isset($prefetched[$id])));

        if (! empty($missing)) {
            foreach (self::whereIn('id', $missing)->get() as $fetched) {
                self::$ancestorPrefetch[$fetched->id] = $fetched;
            }
        }

        $ancestors = new Collection;

        foreach ($ancestorIds as $id) {
            if (isset(self::$ancestorPrefetch[$id])) {
                $ancestors->push(self::$ancestorPrefetch[$id]);
            }
        }

        return $ancestors->values();
    }

    /**
     * 조상 카테고리를 미리 한 번에 적재합니다.
     *
     * 한 응답이 여러 카테고리의 경로를 그릴 때, 조상 조회가 카테고리 수만큼 반복되지 않도록
     * 호출자가 먼저 예열합니다. 예열하지 않아도 동작은 같고 쿼리 수만 달라집니다.
     *
     * @param  array<int, int>  $categoryIds  경로를 그릴 카테고리 ID 목록
     */
    public static function prefetchAncestors(array $categoryIds): void
    {
        $categoryIds = array_values(array_unique(array_filter($categoryIds)));

        if (empty($categoryIds)) {
            return;
        }

        self::warmAncestorsFromPaths(
            self::whereIn('id', $categoryIds)->get(['id', 'path'])
        );
    }

    /**
     * 이미 적재된 카테고리 모델들로 조상을 예열합니다.
     *
     * `path` 가 메모리에 이미 있으므로 그 값을 다시 읽는 쿼리를 내지 않습니다.
     * 상품 목록처럼 응답 전체가 같은 조상 집합을 공유하는 자리에서는 컬렉션 단위로
     * 한 번만 부르면 되며, 그러면 조상 조회가 응답당 1회로 고정됩니다.
     *
     * @param  iterable<self>  $categories  path 가 적재된 카테고리들
     */
    public static function prefetchAncestorsFor(iterable $categories): void
    {
        self::warmAncestorsFromPaths(new Collection($categories instanceof Collection ? $categories->all() : $categories));
    }

    /**
     * path 문자열에서 조상 ID 를 뽑아 한 번에 적재합니다.
     *
     * @param  Collection<int, self>  $categories  path 가 있는 카테고리들
     */
    private static function warmAncestorsFromPaths(Collection $categories): void
    {
        $ancestorIds = $categories
            ->flatMap(fn ($category) => array_filter(array_map('intval', explode('/', (string) $category->path))))
            ->unique()
            ->reject(fn ($id) => isset(self::$ancestorPrefetch[$id]))
            ->values()
            ->all();

        if (empty($ancestorIds)) {
            return;
        }

        foreach (self::whereIn('id', $ancestorIds)->get() as $ancestor) {
            self::$ancestorPrefetch[$ancestor->id] = $ancestor;
        }
    }

    /**
     * 조상 예열 캐시를 비웁니다.
     *
     * 캐시 수명은 요청 스코프입니다. 같은 프로세스에서 카테고리를 바꾼 뒤 다시 경로를
     * 그려야 하는 경우(테스트·콘솔 작업)에 호출합니다.
     */
    public static function flushAncestorPrefetch(): void
    {
        self::$ancestorPrefetch = [];
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
     * @param  string|null  $locale  로케일 (기본값: 현재 앱 로케일)
     * @param  string  $separator  구분자 (기본값: ' > ')
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
     */
    public function generatePath(): void
    {
        if ($this->parent_id) {
            $parent = self::find($this->parent_id);
            if ($parent) {
                $this->path = $parent->path ? $parent->path.'/'.$this->id : (string) $this->id;
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
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * 활성 카테고리만 조회 스코프
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * 특정 깊이의 카테고리 조회 스코프
     *
     * @param  Builder  $query
     * @param  int  $depth  조회할 깊이
     * @return Builder
     */
    public function scopeAtDepth($query, int $depth)
    {
        return $query->where('depth', $depth);
    }

    /**
     * 트리 구조로 카테고리 조회
     *
     * 종전에는 노드마다 자식을 다시 조회해, 카테고리 수만큼 쿼리(각각 상품 수 집계 서브쿼리
     * 포함)가 발행됐다. 지금은 대상 전체를 한 번에 읽고 PHP 에서 부모–자식을 잇는다.
     *
     * 결과는 캐시한다 — 카테고리는 자주 바뀌지 않는데 거의 모든 쇼핑 화면이 이 트리를 읽는다.
     * 무효화는 카테고리·상품 변경 지점이 담당한다({@see self::flushTreeCache()}).
     * 트리에 상품 수 집계가 함께 실리므로 상품 변경도 무효화 대상이다.
     *
     * @param  int|null  $parentId  부모 ID (null이면 루트부터)
     * @param  bool  $onlyActive  활성 카테고리만 조회할지 여부
     * @return Collection 트리 구조 카테고리 컬렉션
     */
    public static function getTree(?int $parentId = null, bool $onlyActive = false): Collection
    {
        $key = self::treeCacheKey($parentId, $onlyActive);

        $cached = app(CacheInterface::class)->get($key);

        if ($cached instanceof Collection) {
            return $cached;
        }

        $tree = self::buildTree($parentId, $onlyActive);

        // TTL 은 무효화 훅이 닿지 않는 경로(직접 SQL 수정 등)에 대한 안전망일 뿐,
        // 정상 경로의 반영 지연은 flushTreeCache() 가 없앤다.
        app(CacheInterface::class)->put($key, $tree, self::TREE_CACHE_TTL);

        return $tree;
    }

    /**
     * 카테고리 트리 캐시를 전부 비웁니다.
     *
     * 캐시 키는 (부모, 활성여부) 조합이라 변경 하나가 어느 조합에 영향을 주는지
     * 특정하기 어렵다 — 조합 수가 적으므로 전량을 비운다.
     */
    public static function flushTreeCache(): void
    {
        $cache = app(CacheInterface::class);

        foreach ([true, false] as $onlyActive) {
            $cache->forget(self::treeCacheKey(null, $onlyActive));
        }

        // 부모 지정 트리는 그 부모 ID 로 키가 갈린다. 태그를 지원하지 않는 드라이버가
        // 있으므로 존재하는 카테고리 ID 만 훑어 지운다 (카테고리 수는 수백 단위).
        foreach (self::query()->pluck('id') as $id) {
            foreach ([true, false] as $onlyActive) {
                $cache->forget(self::treeCacheKey((int) $id, $onlyActive));
            }
        }
    }

    /**
     * 트리 캐시 키를 만듭니다.
     *
     * @param  int|null  $parentId  부모 ID
     * @param  bool  $onlyActive  활성만 여부
     * @return string 캐시 키
     */
    private static function treeCacheKey(?int $parentId, bool $onlyActive): string
    {
        return sprintf(
            'sirsoft-ecommerce.category.tree.%s.%s',
            $parentId === null ? 'root' : $parentId,
            $onlyActive ? 'active' : 'all'
        );
    }

    /**
     * 대상 전체를 한 번에 읽어 트리를 조립합니다.
     *
     * @param  int|null  $parentId  루트로 삼을 부모 ID
     * @param  bool  $onlyActive  활성 카테고리만 포함할지 여부
     * @return Collection 트리 구조 카테고리 컬렉션
     */
    private static function buildTree(?int $parentId, bool $onlyActive): Collection
    {
        $query = self::with([
            'images',
            'parent:id,name,slug', // parent에서 필요한 필드만 선택
        ])
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($onlyActive) {
            $query->where('is_active', true);
        }

        $all = $query->get();
        $byParent = $all->groupBy(fn (self $category) => $category->parent_id === null ? '' : (string) $category->parent_id);

        // 방문 집합으로 유한 종료를 보장한다 — 오염된 데이터(순환 참조)에서도 멈춘다.
        $visited = [];

        $attach = function (?int $currentParentId) use (&$attach, $byParent, &$visited): Collection {
            $children = $byParent->get($currentParentId === null ? '' : (string) $currentParentId) ?? new Collection;

            $resolved = new Collection;

            foreach ($children as $child) {
                if (isset($visited[$child->id])) {
                    continue;
                }

                $visited[$child->id] = true;
                $child->setRelation('children', $attach($child->id));
                $resolved->push($child);
            }

            return $resolved;
        };

        return $attach($parentId);
    }

    /**
     * 플랫 리스트로 변환 (들여쓰기용 depth 포함)
     *
     * @param  Collection|null  $categories  변환할 카테고리 컬렉션 (null이면 전체 트리)
     * @param  string  $indent  들여쓰기 문자
     * @return array
     */
    public static function toFlatList(?Collection $categories = null, string $indent = '　'): array
    {
        if ($categories === null) {
            $categories = self::getTree();
        }

        $result = [];

        foreach ($categories as $category) {
            $prefix = str_repeat($indent, $category->depth);
            $result[] = [
                'id' => $category->id,
                'name' => $prefix.$category->getLocalizedName(),
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
