<?php

namespace Plugins\Sirsoft\HeroSection\Support\Targets;

use Modules\Sirsoft\Ecommerce\Contracts\Catalog\CategoryDirectory;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data\CategorySummary;
use Plugins\Sirsoft\HeroSection\Contracts\TargetResolverInterface;
use Plugins\Sirsoft\HeroSection\Contracts\TargetSiteUrlsInterface;

/**
 * 상품 카테고리 대상 해석기 — 카테고리 목록 위치(`category_hero`). 저장 키는 카테고리 id, 화면 값은 주소의 slug(없을 때만 숫자 id — slug 가 먼저다).
 *
 * 이름은 상위 경로를 이어 보인다(「의류 › 남성」) — 같은 이름의 하위 카테고리를 관리자가 구분할 수 있게. 이커머스 모듈이 공개한
 * 카테고리 조회 계약({@see CategoryDirectory})만 쓴다.
 */
class CategoryTargetResolver implements TargetResolverInterface, TargetSiteUrlsInterface
{
    /**
     * CategoryTargetResolver 생성자
     *
     * @param  CategoryDirectory  $categories  카테고리 조회 계약
     */
    public function __construct(private CategoryDirectory $categories) {}

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
        $labels = [];
        foreach ($this->categories->findByIds(self::ids($keys)) as $id => $category) {
            $labels[(string) $id] = $category->pathLabel;
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
        return array_map(fn (CategorySummary $category) => [
            'key' => (string) $category->id,
            'label' => $category->pathLabel,
            'hint' => $category->slug,
        ], $this->categories->search($keyword, $limit));
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $key  카테고리 id
     * @return string|null 카테고리 목록 경로
     */
    public function siteUrl(string $key): ?string
    {
        return $this->category($key)?->url;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>  $keys  category id 목록
     * @return array<string, string> category id => 사이트 경로
     */
    public function siteUrls(array $keys): array
    {
        $urls = [];
        foreach ($this->categories->findByIds(self::ids($keys)) as $id => $category) {
            $urls[(string) $id] = $category->url;
        }

        return $urls;
    }

    /**
     * 저장 키로 카테고리를 찾습니다.
     *
     * @param  string  $key  카테고리 id
     * @return CategorySummary|null 카테고리 요약
     */
    private function category(string $key): ?CategorySummary
    {
        return ctype_digit($key) ? $this->categories->findById((int) $key) : null;
    }

    /**
     * 저장 키 목록에서 숫자 id 만 남깁니다.
     *
     * @param  array<int, string>  $keys  저장 키 목록
     * @return array<int, int> id 목록
     */
    private static function ids(array $keys): array
    {
        return array_values(array_unique(array_map('intval', array_filter($keys, fn ($key) => ctype_digit((string) $key)))));
    }
}
