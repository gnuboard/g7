<?php

namespace Modules\Sirsoft\Ecommerce\Services\Bridge;

use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\CategoryDirectory;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data\CategorySummary;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Support\ShopPathResolver;

/**
 * 상품 카테고리 조회 공개 계약 구현 — 카테고리 저장소에 위임하고 경로 이름을 붙인 요약 DTO 로 돌려준다.
 *
 * @internal 다른 확장은 {@see CategoryDirectory} 로 주입받는다.
 */
class EcommerceCategoryDirectory implements CategoryDirectory
{
    /**
     * EcommerceCategoryDirectory 생성자
     *
     * @param  CategoryRepositoryInterface  $categories  카테고리 저장소
     */
    public function __construct(private CategoryRepositoryInterface $categories) {}

    /**
     * {@inheritDoc}
     *
     * @param  string  $slug  카테고리 slug
     * @return CategorySummary|null 없으면 null
     */
    public function findBySlug(string $slug): ?CategorySummary
    {
        $slug = trim($slug);
        $category = $slug === '' ? null : $this->categories->findBySlug($slug);

        return $category === null ? null : $this->summaries([$category])[(int) $category->id];
    }

    /**
     * {@inheritDoc}
     *
     * @param  int  $id  카테고리 id
     * @return CategorySummary|null 없으면 null
     */
    public function findById(int $id): ?CategorySummary
    {
        $category = $id > 0 ? $this->categories->findById($id) : null;

        return $category === null ? null : $this->summaries([$category])[(int) $category->id];
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, int>  $ids  카테고리 id 목록
     * @return array<int, CategorySummary> id => 요약
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $found = $this->categories->findByIdsKeyed($ids);
        $summaries = $this->summaries($found->values()->all());

        $out = [];
        foreach ($ids as $id) {
            if (isset($summaries[$id])) {
                $out[$id] = $summaries[$id];
            }
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>  $slugs  카테고리 slug 목록
     * @return array<string, string> slug => 경로 이름
     */
    public function pathLabelsBySlugs(array $slugs): array
    {
        $slugs = array_values(array_unique(array_filter(array_map(fn ($slug) => trim((string) $slug), $slugs))));
        if ($slugs === []) {
            return [];
        }

        // slug 마다 조회하지 않는다 — 카테고리를 한 번, 조상을 한 번 읽는다. 돌려주는 순서는 요청한 slug 순서다.
        $found = $this->categories->findBySlugsKeyed($slugs);
        $summaries = $this->summaries($found->values()->all());
        $labels = [];
        foreach ($slugs as $slug) {
            $category = $found[$slug] ?? null;
            if ($category !== null && isset($summaries[(int) $category->id])) {
                $labels[$slug] = $summaries[(int) $category->id]->pathLabel;
            }
        }

        return $labels;
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $keyword  검색어
     * @param  int  $limit  최대 건수
     * @return array<int, CategorySummary> 요약 목록
     */
    public function search(string $keyword, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $all = $this->categories->getFlatList();
        $byId = $all->keyBy('id');
        $needle = mb_strtolower(trim($keyword));

        return $all
            ->filter(fn (Category $category) => $needle === ''
                || str_contains(mb_strtolower($category->getLocalizedName()), $needle)
                || str_contains(mb_strtolower((string) $category->slug), $needle))
            ->take($limit)
            ->map(fn (Category $category) => self::summary($category, $byId))
            ->values()
            ->all();
    }

    /**
     * 카테고리 목록을 경로 이름까지 채운 요약으로 바꿉니다 — 조상은 한 번에 읽는다.
     *
     * @param  array<int, Category>  $categories  카테고리 목록
     * @return array<int, CategorySummary> id => 요약
     */
    private function summaries(array $categories): array
    {
        if ($categories === []) {
            return [];
        }

        $ancestorIds = collect($categories)->flatMap(fn (Category $category) => self::pathIds($category))->unique()->values()->all();
        $byId = $this->categories->findByIdsKeyed($ancestorIds);

        $out = [];
        foreach ($categories as $category) {
            $out[(int) $category->id] = self::summary($category, $byId);
        }

        return $out;
    }

    /**
     * 카테고리 하나의 요약을 만듭니다.
     *
     * @param  Category  $category  카테고리
     * @param  Collection<int|string, Category>  $byId  id => 카테고리(조상 포함)
     * @return CategorySummary 요약
     */
    private static function summary(Category $category, Collection $byId): CategorySummary
    {
        return new CategorySummary(
            id: (int) $category->id,
            slug: (string) $category->slug,
            label: $category->getLocalizedName(),
            pathLabel: self::pathName($category, $byId),
            url: ShopPathResolver::path('category/'.$category->slug),
        );
    }

    /**
     * 카테고리 경로(`1/2/3`)의 id 목록을 반환합니다(자기 포함).
     *
     * @param  Category  $category  카테고리
     * @return array<int, int> 경로 id
     */
    private static function pathIds(Category $category): array
    {
        $path = trim((string) $category->path);

        return $path === '' ? [(int) $category->id] : array_map('intval', explode('/', $path));
    }

    /**
     * 최상위부터 이은 이름을 만듭니다.
     *
     * @param  Category  $category  카테고리
     * @param  Collection<int|string, Category>  $byId  id => 카테고리(조상 포함)
     * @return string 경로 이름
     */
    private static function pathName(Category $category, Collection $byId): string
    {
        $names = [];
        foreach (self::pathIds($category) as $id) {
            $node = $id === (int) $category->id ? $category : ($byId[$id] ?? null);
            if ($node !== null) {
                $names[] = $node->getLocalizedName();
            }
        }

        return implode(CategorySummary::PATH_SEPARATOR, $names);
    }
}
