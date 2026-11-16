<?php

namespace Plugins\Sirsoft\HeroSection\Support\Targets;

use Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data\ProductSummary;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\ProductDirectory;
use Plugins\Sirsoft\HeroSection\Contracts\TargetResolverInterface;
use Plugins\Sirsoft\HeroSection\Contracts\TargetSiteUrlsInterface;

/**
 * 상품 대상 해석기 — 상품 상세 위치(`product_hero`). 저장 키는 상품 id, 화면 값은 주소의 상품 코드(없을 때만 숫자 id — 코드가 먼저다).
 *
 * 이커머스 모듈이 공개한 상품 조회 계약({@see ProductDirectory})만 쓴다(#916).
 */
class ProductTargetResolver implements TargetResolverInterface, TargetSiteUrlsInterface
{
    /**
     * ProductTargetResolver 생성자
     *
     * @param  ProductDirectory  $products  상품 조회 계약
     */
    public function __construct(private ProductDirectory $products) {}

    /**
     * {@inheritDoc}
     *
     * @param  string  $value  화면 값(상품 코드 또는 id)
     * @return string|null 상품 id
     */
    public function resolve(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $product = $this->products->findByCode($value) ?? (ctype_digit($value) ? $this->products->findById((int) $value) : null);

        return $product === null ? null : (string) $product->id;
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $key  상품 id
     * @return bool 있으면 true
     */
    public function exists(string $key): bool
    {
        return $this->product($key) !== null;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>  $keys  상품 id 목록
     * @return array<string, string> 상품 id => 상품명
     */
    public function labels(array $keys): array
    {
        $labels = [];
        foreach ($this->products->findByIds(self::ids($keys)) as $id => $product) {
            $labels[(string) $id] = $product->label;
        }

        return $labels;
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $keyword  검색어(상품명·코드)
     * @param  int  $limit  최대 건수
     * @return array<int, array{key: string, label: string, hint: string}> 고르기 항목
     */
    public function search(string $keyword, int $limit): array
    {
        return array_map(fn (ProductSummary $product) => [
            'key' => (string) $product->id,
            'label' => $product->label,
            'hint' => $product->code,
        ], $this->products->search($keyword, $limit));
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $key  상품 id
     * @return string|null 상품 상세 경로
     */
    public function siteUrl(string $key): ?string
    {
        return $this->product($key)?->url;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>  $keys  상품 id 목록
     * @return array<string, string> 상품 id => 사이트 경로
     */
    public function siteUrls(array $keys): array
    {
        $urls = [];
        foreach ($this->products->findByIds(self::ids($keys)) as $id => $product) {
            $urls[(string) $id] = $product->url;
        }

        return $urls;
    }

    /**
     * 저장 키로 상품을 찾습니다.
     *
     * @param  string  $key  상품 id
     * @return ProductSummary|null 상품 요약
     */
    private function product(string $key): ?ProductSummary
    {
        return ctype_digit($key) ? $this->products->findById((int) $key) : null;
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
