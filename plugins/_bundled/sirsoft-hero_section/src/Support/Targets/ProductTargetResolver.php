<?php

namespace Plugins\Sirsoft\HeroSection\Support\Targets;

use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Support\ShopPathResolver;
use Plugins\Sirsoft\HeroSection\Contracts\TargetResolverInterface;

/**
 * 상품 대상 해석기 — 상품 상세 위치(`product_hero`). 저장 키는 상품 id, 화면 값은 주소의 상품 코드(없을 때만 숫자 id — 상품 코드가 먼저다).
 *
 * 이커머스 모듈이 켜져 있을 때만 위치가 등록되므로 이 클래스도 그때만 만들어진다. 이커머스 모듈의 저장소 계약만 쓴다.
 */
class ProductTargetResolver implements TargetResolverInterface
{
    /**
     * ProductTargetResolver 생성자
     *
     * @param  ProductRepositoryInterface  $products  상품 저장소
     */
    public function __construct(private ProductRepositoryInterface $products) {}

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

        $product = $this->products->findByProductCode($value) ?? (ctype_digit($value) ? $this->products->find((int) $value) : null);

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
     * @return array<string, string> 상품 id => 이름
     */
    public function labels(array $keys): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($keys, fn ($key) => ctype_digit((string) $key)))));
        if ($ids === []) {
            return [];
        }

        $labels = [];
        foreach ($this->products->findByIdsKeyed($ids) as $id => $product) {
            $labels[(string) $id] = $product->getLocalizedName();
        }

        return $labels;
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $keyword  검색어(이름·상품 코드)
     * @param  int  $limit  최대 건수
     * @return array<int, array{key: string, label: string, hint: string}> 고르기 항목
     */
    public function search(string $keyword, int $limit): array
    {
        return $this->products->searchByKeyword(trim($keyword), 'created_at', 'desc', null, 0, $limit)
            ->getCollection()
            ->map(fn (Product $product) => [
                'key' => (string) $product->id,
                'label' => $product->getLocalizedName(),
                'hint' => (string) $product->product_code,
            ])->values()->all();
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $key  상품 id
     * @return string|null 상품 상세 경로
     */
    public function siteUrl(string $key): ?string
    {
        $product = $this->product($key);

        return $product === null ? null : ShopPathResolver::path('products/'.$product->product_code);
    }

    /**
     * 저장 키로 상품을 찾습니다.
     *
     * @param  string  $key  상품 id
     * @return Product|null 상품
     */
    private function product(string $key): ?Product
    {
        return ctype_digit($key) ? $this->products->find((int) $key) : null;
    }
}
