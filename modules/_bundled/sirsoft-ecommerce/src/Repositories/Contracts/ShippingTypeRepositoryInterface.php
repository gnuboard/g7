<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;

/**
 * 배송유형 Repository 인터페이스
 */
interface ShippingTypeRepositoryInterface
{
    /**
     * 배송유형 목록 조회
     *
     * @param array $filters 필터 조건
     * @param array $with Eager loading 관계
     * @return Collection
     */
    public function getAll(array $filters = [], array $with = []): Collection;

    /**
     * ID로 배송유형 조회
     *
     * @param int $id 배송유형 ID
     * @param array $with Eager loading 관계
     * @return ShippingType|null
     */
    public function findById(int $id, array $with = []): ?ShippingType;

    /**
     * 배송유형 생성
     *
     * @param array $data 배송유형 데이터
     * @return ShippingType
     */
    public function create(array $data): ShippingType;

    /**
     * 배송유형 수정
     *
     * @param int $id 배송유형 ID
     * @param array $data 수정 데이터
     * @return ShippingType
     */
    public function update(int $id, array $data): ShippingType;

    /**
     * 배송유형 삭제
     *
     * @param int $id 배송유형 ID
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * 코드 중복 확인
     *
     * @param string $code 배송유형 코드
     * @param int|null $excludeId 제외할 배송유형 ID
     * @return bool
     */
    public function existsByCode(string $code, ?int $excludeId = null): bool;

    /**
     * 활성 배송유형 목록 조회
     *
     * @param string|null $category 카테고리 필터 (domestic, international, other, null=전체)
     * @return Collection
     */
    public function getActiveTypes(?string $category = null): Collection;
}
