<?php

namespace Plugins\Sirsoft\HeroSection\Support\Targets;

use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Support\ShopPathResolver;
use Plugins\Sirsoft\HeroSection\Contracts\TargetResolverInterface;
use Plugins\Sirsoft\HeroSection\Contracts\TargetSiteUrlsInterface;

/**
 * 상품 카테고리 대상 해석기 — 카테고리 목록 위치(`category_hero`). 저장 키는 카테고리 id, 화면 값은 주소의 slug(없을 때만 숫자 id — slug 가 먼저다).
 *
 * 이름은 상위 경로를 이어 보인다(「의류 › 남성」) — 같은 이름의 하위 카테고리를 관리자가 구분할 수 있게. 이커머스 모듈의 저장소 계약만 쓴다.
 */
class CategoryTargetResolver implements TargetResolverInterface, TargetSiteUrlsInterface
{
    /**
     * 경로 이름 구분자
     */
    private const SEPARATOR = ' › ';

    /**
     * CategoryTargetResolver 생성자
     *
     * @param  CategoryRepositoryInterface  $categories  카테고리 저장소
     */
    public function __construct(private CategoryRepositoryInterface $categories) {}

    /**
     * {@inheritDoc}
     *
     * @param  string  $value  화면 값(slug 또는 id)
     * @return string|null 카테고리 id
     */
    public function resolve(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $category = $this->categories->findBySlug($value) ?? (ctype_digit($value) ? $this->categories->findById((int) $value) : null);

        return $category === null ? null : (string) $category->id;
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $key  카테고리 id
     * @return bool 있으면 true
     */
    public function exists(string $key): bool
    {
        return $this->category($key) !== null;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>  $keys  카테고리 id 목록
     * @return array<string, string> 카테고리 id => 경로 이름
     */
    public function labels(array $keys): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($keys, fn ($key) => ctype_digit((string) $key)))));
        if ($ids === []) {
            return [];
        }

        $found = $this->categories->findByIdsKeyed($ids);
        $ancestors = $found->flatMap(fn (Category $category) => self::pathIds($category))->unique()->values()->all();
        $byId = $this->categories->findByIdsKeyed($ancestors);

        $labels = [];
        foreach ($ids as $id) {
            if (isset($found[$id])) {
                $labels[(string) $id] = self::pathName($found[$id], $byId);
            }
        }

        return $labels;
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $keyword  검색어(이름·slug)
     * @param  int  $limit  최대 건수
     * @return array<int, array{key: string, label: string, hint: string}> 고르기 항목
     */
    public function search(string $keyword, int $limit): array
    {
        $all = $this->categories->getFlatList();
        $byId = $all->keyBy('id');
        $needle = mb_strtolower(trim($keyword));

        return $all
            ->filter(fn (Category $category) => $needle === ''
                || str_contains(mb_strtolower($category->getLocalizedName()), $needle)
                || str_contains(mb_strtolower((string) $category->slug), $needle))
            ->take($limit)
            ->map(fn (Category $category) => [
                'key' => (string) $category->id,
                'label' => self::pathName($category, $byId),
                'hint' => (string) $category->slug,
            ])->values()->all();
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $key  카테고리 id
     * @return string|null 카테고리 목록 경로
     */
    public function siteUrl(string $key): ?string
    {
        $category = $this->category($key);

        return $category === null ? null : ShopPathResolver::path('category/'.$category->slug);
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>  $keys  category id 목록
     * @return array<string, string> category id => 사이트 경로
     */
    public function siteUrls(array $keys): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($keys, fn ($key) => ctype_digit((string) $key)))));
        $urls = [];
        foreach ($ids === [] ? [] : $this->categories->findByIdsKeyed($ids) as $id => $category) {
            $urls[(string) $id] = ShopPathResolver::path('category/'.$category->slug);
        }

        return $urls;
    }

    /**
     * 저장 키로 카테고리를 찾습니다.
     *
     * @param  string  $key  카테고리 id
     * @return Category|null 카테고리
     */
    private function category(string $key): ?Category
    {
        return ctype_digit($key) ? $this->categories->findById((int) $key) : null;
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
     * 상위 경로를 이은 이름을 만듭니다.
     *
     * @param  Category  $category  카테고리
     * @param  Collection<int, Category>  $byId  id => 카테고리(조상 포함)
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

        return implode(self::SEPARATOR, $names);
    }
}
