<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;

/**
 * 배송사 Repository 인터페이스
 */
interface ShippingCarrierRepositoryInterface
{
    /**
     * 배송사 목록 조회
     *
     * @param array $filters 필터 조건
     * @param array $with Eager loading 관계
     * @return Collection
     */
    public function getAll(array $filters = [], array $with = []): Collection;

    /**
     * ID로 배송사 조회
     *
     * @param int $id 배송사 ID
     * @param array $with Eager loading 관계
     * @return ShippingCarrier|null
     */
    public function findById(int $id, array $with = []): ?ShippingCarrier;

    /**
     * 배송사 생성
     *
     * @param array $data 배송사 데이터
     * @return ShippingCarrier
     */
    public function create(array $data): ShippingCarrier;

    /**
     * 배송사 수정
     *
     * @param int $id 배송사 ID
     * @param array $data 수정 데이터
     * @return ShippingCarrier
     */
    public function update(int $id, array $data): ShippingCarrier;

    /**
     * 배송사 삭제
     *
     * @param int $id 배송사 ID
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * 코드 중복 확인
     *
     * @param string $code 배송사 코드
     * @param int|null $excludeId 제외할 배송사 ID
     * @return bool
     */
    public function existsByCode(string $code, ?int $excludeId = null): bool;

    /**
     * 활성 배송사 목록 조회 (Select 옵션용)
     *
     * @param string|null $type 배송사 유형 필터 (domestic, international, null=전체)
     * @return Collection
     */
    public function getActiveCarriers(?string $type = null): Collection;
}
