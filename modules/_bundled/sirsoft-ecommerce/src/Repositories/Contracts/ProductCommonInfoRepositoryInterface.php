<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;

/**
 * 공통정보 Repository 인터페이스
 */
interface ProductCommonInfoRepositoryInterface
{
    /**
     * 공통정보 목록 조회
     *
     * @param array $filters 필터 조건
     * @param array $with Eager loading 관계
     * @return Collection
     */
    public function getAll(array $filters = [], array $with = []): Collection;

    /**
     * 공통정보 목록 페이지네이션 조회
     *
     * @param array $filters 필터 조건
     * @param int $perPage 페이지당 항목 수
     * @param array $with Eager loading 관계
     * @return LengthAwarePaginator
     */
    public function getPaginated(array $filters = [], int $perPage = 20, array $with = []): LengthAwarePaginator;

    /**
     * ID로 공통정보 조회
     *
     * @param int $id 공통정보 ID
     * @param array $with Eager loading 관계
     * @return ProductCommonInfo|null
     */
    public function findById(int $id, array $with = []): ?ProductCommonInfo;

    /**
     * 공통정보 생성
     *
     * @param array $data 공통정보 데이터
     * @return ProductCommonInfo
     */
    public function create(array $data): ProductCommonInfo;

    /**
     * 공통정보 수정
     *
     * @param int $id 공통정보 ID
     * @param array $data 수정 데이터
     * @return ProductCommonInfo
     */
    public function update(int $id, array $data): ProductCommonInfo;

    /**
     * 공통정보 삭제
     *
     * @param int $id 공통정보 ID
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * 연결된 상품 수 조회
     *
     * @param int $id 공통정보 ID
     * @return int
     */
    public function getProductCount(int $id): int;

    /**
     * 기본 설정 해제
     *
     * @param int|null $exceptId 제외할 공통정보 ID
     * @return int 업데이트된 레코드 수
     */
    public function clearDefault(?int $exceptId = null): int;

    /**
     * 기본 공통정보 조회
     *
     * @return ProductCommonInfo|null
     */
    public function findDefault(): ?ProductCommonInfo;

    /**
     * 최대 정렬 순서 조회
     *
     * @return int
     */
    public function getMaxSortOrder(): int;
}
