<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
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
     * 추가옵션 그룹 ID 목록에 속한 모든 선택지를 삭제합니다.
     *
     * 상품 삭제 시 선택지 → 그룹 순서로 명시적 삭제하기 위해 사용됩니다.
     *
     * @param  array<int, int>  $additionalOptionIds  추가옵션 그룹 ID 배열
     * @return int 삭제된 행 수
     */
    public function deleteByAdditionalOptionIds(array $additionalOptionIds): int;
}
