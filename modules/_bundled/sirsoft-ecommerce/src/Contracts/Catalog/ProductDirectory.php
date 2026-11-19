<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Catalog;

use Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data\ProductSummary;

/**
 * 상품 조회 공개 계약
 *
 * 다른 확장(배너·추천 위치 등)이 상품을 고르거나 이름·주소를 보일 때 쓴다. 상품 Model·Repository 는 이커머스 내부라
 * 다른 확장이 직접 쓰지 않는다. 서비스 컨테이너로 주입받는다(`app(ProductDirectory::class)`).
 *
 * @since 1.4.0
 */
interface ProductDirectory
{
    /** 계약 판 — 메서드 추가는 판을 올리지 않는다(구현은 이커머스만) */
    public const VERSION = 1;

    /**
     * 상품 코드로 상품을 찾습니다.
     *
     * @param  string  $code  상품 코드
     * @return ProductSummary|null 없으면 null
     */
    public function findByCode(string $code): ?ProductSummary;

    /**
     * id 로 상품을 찾습니다.
     *
     * @param  int  $id  상품 id
     * @return ProductSummary|null 없으면 null
     */
    public function findById(int $id): ?ProductSummary;

    /**
     * id 묶음으로 상품을 찾습니다. 없는 id 는 결과에서 빠집니다.
     *
     * @param  array<int, int>  $ids  상품 id 목록
     * @return array<int, ProductSummary> id => 요약
     */
    public function findByIds(array $ids): array;

    /**
     * 상품 코드 묶음의 현재 언어 상품명을 돌려줍니다.
     *
     * @param  array<int, string>  $codes  상품 코드 목록
     * @return array<string, string> 상품 코드 => 상품명
     */
    public function labelsByCodes(array $codes): array;

    /**
     * 상품을 검색합니다 — 전시 중인 상품만, 이름·설명·상품 코드·SKU·바코드(숫자면 id) 일치, 최근 등록 순.
     *
     * @param  string  $keyword  검색어(빈 값이면 최근 등록 순 앞에서부터)
     * @param  int  $limit  최대 건수
     * @return array<int, ProductSummary> 요약 목록
     */
    public function search(string $keyword, int $limit): array;
}
