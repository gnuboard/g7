<?php

namespace Modules\Sirsoft\Ecommerce\Services\Bridge;

use Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data\ProductSummary;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\ProductDirectory;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;

/**
 * 상품 조회 공개 계약 구현 — 상품 저장소에 위임만 하고 요약 DTO 로 바꿔 돌려준다.
 *
 * @internal 다른 확장은 {@see ProductDirectory} 로 주입받는다.
 */
class EcommerceProductDirectory implements ProductDirectory
{
    /**
     * EcommerceProductDirectory 생성자
     *
     * @param  ProductRepositoryInterface  $products  상품 저장소
     */
    public function __construct(private ProductRepositoryInterface $products) {}

    /**
     * {@inheritDoc}
     *
     * @param  string  $code  상품 코드
     * @return ProductSummary|null 없으면 null
     */
    public function findByCode(string $code): ?ProductSummary
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $product = $this->products->findByProductCode($code);

        return $product === null ? null : ProductSummary::fromModel($product);
    }

    /**
     * {@inheritDoc}
     *
     * @param  int  $id  상품 id
     * @return ProductSummary|null 없으면 null
     */
    public function findById(int $id): ?ProductSummary
    {
        $product = $id > 0 ? $this->products->find($id) : null;

        return $product === null ? null : ProductSummary::fromModel($product);
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, int>  $ids  상품 id 목록
     * @return array<int, ProductSummary> id => 요약
     */
    public function findByIds(array $ids): array
    {
        $ids = self::positiveIds($ids);
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach ($this->products->findByIdsKeyed($ids) as $id => $product) {
            $out[(int) $id] = ProductSummary::fromModel($product);
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>  $codes  상품 코드 목록
     * @return array<string, string> 상품 코드 => 상품명
     */
    public function labelsByCodes(array $codes): array
    {
        $codes = array_values(array_unique(array_filter(array_map(fn ($code) => trim((string) $code), $codes))));
        if ($codes === []) {
            return [];
        }

        // 코드마다 조회하지 않고 한 번에 읽는다. 돌려주는 순서는 요청한 코드 순서다(없는 코드는 빠진다).
        $found = $this->products->findByProductCodesKeyed($codes);
        $labels = [];
        foreach ($codes as $code) {
            if (isset($found[$code])) {
                $labels[$code] = $found[$code]->getLocalizedName();
            }
        }

        return $labels;
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $keyword  검색어
     * @param  int  $limit  최대 건수
     * @return array<int, ProductSummary> 요약 목록
     */
    public function search(string $keyword, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return $this->products->searchByKeyword(trim($keyword), 'created_at', 'desc', null, 0, $limit)
            ->getCollection()
            ->map(fn (Product $product) => ProductSummary::fromModel($product))
            ->values()
            ->all();
    }

    /**
     * 양의 정수 id 만 중복 없이 남깁니다.
     *
     * @param  array<int, mixed>  $ids  id 목록
     * @return array<int, int> 정리한 id
     */
    private static function positiveIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
    }
}
