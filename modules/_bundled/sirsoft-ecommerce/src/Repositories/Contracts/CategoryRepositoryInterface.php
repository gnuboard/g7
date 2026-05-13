<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\Category;

/**
 * 카테고리 Repository 인터페이스
 */
interface CategoryRepositoryInterface
{
    /**
     * 계층형 카테고리 목록 조회
     *
     * @param array $filters 필터 조건
     * @param array $with Eager loading 관계
     * @return Collection
     */
    public function getHierarchical(array $filters = [], array $with = []): Collection;

    /**
     * ID로 카테고리 조회
     *
     * @param int $id 카테고리 ID
     * @param array $with Eager loading 관계
     * @return Category|null
     */
    public function findById(int $id, array $with = []): ?Category;

    /**
     * 카테고리 생성
     *
     * @param array $data 카테고리 데이터
     * @return Category
     */
    public function create(array $data): Category;

    /**
     * 카테고리 수정
     *
     * @param int $id 카테고리 ID
     * @param array $data 수정 데이터
     * @return Category
     */
    public function update(int $id, array $data): Category;

    /**
     * 카테고리 삭제
     *
     * @param int $id 카테고리 ID
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * 하위 카테고리 존재 여부 확인
     *
     * @param int $id 카테고리 ID
     * @return bool
     */
    public function hasChildren(int $id): bool;

    /**
     * 연결된 상품 수 조회
     *
     * @param int $id 카테고리 ID
     * @return int
     */
    public function getProductCount(int $id): int;

    /**
     * 다음 정렬 순서 값 조회
     *
     * @param int|null $parentId 부모 카테고리 ID
     * @return int
     */
    public function getNextSortOrder(?int $parentId = null): int;

    /**
     * 슬러그 중복 확인
     *
     * @param string $slug 슬러그
     * @param int|null $excludeId 제외할 카테고리 ID
     * @return bool
     */
    public function existsBySlug(string $slug, ?int $excludeId = null): bool;

    /**
     * slug로 카테고리 조회
     *
     * @param string $slug 카테고리 slug
     * @param array $with Eager loading 관계
     * @return Category|null
     */
    public function findBySlug(string $slug, array $with = []): ?Category;

    /**
     * 평면 리스트로 카테고리 목록 조회 (TagInput 등에 사용)
     *
     * @param array $filters 필터 조건
     * @param array $with Eager loading 관계
     * @return Collection
     */
    public function getFlatList(array $filters = [], array $with = []): Collection;
}
