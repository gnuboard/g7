<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOptionValue;

/**
 * 상품 추가옵션 선택지 Repository 인터페이스
 */
interface ProductAdditionalOptionValueRepositoryInterface
{
    /**
     * 선택지 ID 배열로 활성 선택지를 조회합니다.
     *
     * 가격 재조회(클라 가격 신뢰 금지)와 소속·활성 검증에 사용됩니다.
     *
     * @param  array<int, int>  $valueIds  선택지 ID 배열
     * @return Collection 선택지 컬렉션 (additionalOption 관계 포함)
     */
    public function findActiveByIds(array $valueIds): Collection;

    /**
     * 특정 상품에 속한 활성 선택지를 ID 키 맵으로 조회합니다.
     *
     * value_id 가 해당 상품 소속·활성인지 검증하기 위한 lookup 입니다.
     *
     * @param  int  $productId  상품 ID
     * @return Collection<int, ProductAdditionalOptionValue> value_id 키 맵
     */
    public function getActiveByProductKeyed(int $productId): Collection;

    /**
     * 여러 상품의 활성 선택지를 상품 ID → (value_id 키 맵) 형태로 한 번에 조회합니다.
     *
     * 장바구니·바로구매처럼 항목이 여러 개인 경로에서 상품마다 조회하지 않기 위한
     * 일괄 진입점입니다.
     *
     * 외곽이 Support 인 이유: 항목이 Model 이 아니라 Collection 인 **중첩 맵**이므로
     * Eloquent\Collection 의 계약(load/modelKeys/fresh 가 instanceof Model 을 전제)을
     * 만족할 수 없습니다. 같은 이유로 `Eloquent\Collection::map()` 도 항목이 Model 이
     * 아니면 toBase() 로 강등합니다. 평면 `id => Model` 맵은 Eloquent 로 둡니다.
     *
     * @param  array<int, int>  $productIds  상품 ID 목록
     * @return SupportCollection<int, Collection<int, ProductAdditionalOptionValue>> 상품 ID 키 맵
     */
    public function getActiveByProductIdsKeyed(array $productIds): SupportCollection;

    /**
     * 추가옵션 그룹 ID 목록에 속한 모든 선택지를 삭제합니다.
     *
     * 상품 삭제 시 선택지 → 그룹 순서로 명시적 삭제하기 위해 사용됩니다.
     *
     * @param  array<int, int>  $additionalOptionIds  추가옵션 그룹 ID 배열
     * @return int 삭제된 행 수
     */
    public function deleteByAdditionalOptionIds(array $additionalOptionIds): int;
}
