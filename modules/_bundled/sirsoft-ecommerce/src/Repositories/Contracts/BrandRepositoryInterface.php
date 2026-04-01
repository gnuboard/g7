<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\Brand;

/**
 * 브랜드 Repository 인터페이스
 */
interface BrandRepositoryInterface
{
    /**
     * 브랜드 목록 조회
     *
     * @param array $filters 필터 조건
     * @param array $with Eager loading 관계
     * @return Collection
     */
    public function getAll(array $filters = [], array $with = []): Collection;

    /**
     * ID로 브랜드 조회
     *
     * @param int $id 브랜드 ID
     * @param array $with Eager loading 관계
     * @return Brand|null
     */
    public function findById(int $id, array $with = []): ?Brand;

    /**
     * 브랜드 생성
     *
     * @param array $data 브랜드 데이터
     * @return Brand
     */
    public function create(array $data): Brand;

    /**
     * 브랜드 수정
     *
     * @param int $id 브랜드 ID
     * @param array $data 수정 데이터
     * @return Brand
     */
    public function update(int $id, array $data): Brand;

    /**
     * 브랜드 삭제
     *
     * @param int $id 브랜드 ID
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * 연결된 상품 수 조회
     *
     * @param int $id 브랜드 ID
     * @return int
     */
    public function getProductCount(int $id): int;

    /**
     * 슬러그 중복 확인
     *
     * @param string $slug 슬러그
     * @param int|null $excludeId 제외할 브랜드 ID
     * @return bool
     */
    public function existsBySlug(string $slug, ?int $excludeId = null): bool;
}