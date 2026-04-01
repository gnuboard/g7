<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;

/**
 * 상품 라벨 Repository 인터페이스
 */
interface ProductLabelRepositoryInterface
{
    /**
     * 라벨 목록 조회
     *
     * @param array $filters 필터 조건
     * @param array $with Eager loading 관계
     * @return Collection
     */
    public function getAll(array $filters = [], array $with = []): Collection;

    /**
     * ID로 라벨 조회
     *
     * @param int $id 라벨 ID
     * @param array $with Eager loading 관계
     * @return ProductLabel|null
     */
    public function findById(int $id, array $with = []): ?ProductLabel;

    /**
     * 라벨 생성
     *
     * @param array $data 라벨 데이터
     * @return ProductLabel
     */
    public function create(array $data): ProductLabel;

    /**
     * 라벨 수정
     *
     * @param int $id 라벨 ID
     * @param array $data 수정 데이터
     * @return ProductLabel
     */
    public function update(int $id, array $data): ProductLabel;

    /**
     * 라벨 삭제
     *
     * @param int $id 라벨 ID
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * 연결된 상품 수 조회
     *
     * @param int $id 라벨 ID
     * @return int
     */
    public function getProductCount(int $id): int;

    /**
     * 활성 라벨 목록 조회
     *
     * @return Collection
     */
    public function getActiveLabels(): Collection;

    /**
     * 라벨 존재 여부 확인
     *
     * @param int $id 라벨 ID
     * @return bool
     */
    public function exists(int $id): bool;
}
