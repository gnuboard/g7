<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\CategoryImage;

/**
 * 카테고리 이미지 Repository 인터페이스
 */
interface CategoryImageRepositoryInterface
{
    /**
     * ID로 이미지 조회
     *
     * @param int $id 이미지 ID
     * @return CategoryImage|null
     */
    public function findById(int $id): ?CategoryImage;

    /**
     * 해시로 이미지 조회
     *
     * @param string $hash 이미지 해시
     * @return CategoryImage|null
     */
    public function findByHash(string $hash): ?CategoryImage;

    /**
     * 카테고리의 모든 이미지 조회
     *
     * @param int $categoryId 카테고리 ID
     * @param string|null $collection 컬렉션 필터
     * @return Collection
     */
    public function getByCategoryId(int $categoryId, ?string $collection = null): Collection;

    /**
     * temp_key로 임시 이미지 조회
     *
     * @param string $tempKey 임시 키
     * @param string|null $collection 컬렉션 필터
     * @return Collection
     */
    public function getByTempKey(string $tempKey, ?string $collection = null): Collection;

    /**
     * 카테고리 이미지 생성
     *
     * @param array $data 이미지 데이터
     * @return CategoryImage
     */
    public function create(array $data): CategoryImage;

    /**
     * 이미지 수정
     *
     * @param int $id 이미지 ID
     * @param array $data 수정할 데이터
     * @return CategoryImage
     */
    public function update(int $id, array $data): CategoryImage;

    /**
     * 이미지 삭제
     *
     * @param int $id 이미지 ID
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * 임시 이미지를 카테고리에 연결
     *
     * @param string $tempKey 임시 키
     * @param int $categoryId 카테고리 ID
     * @return int 연결된 이미지 수
     */
    public function linkTempImages(string $tempKey, int $categoryId): int;

    /**
     * 카테고리의 최대 sort_order 조회
     *
     * @param int $categoryId 카테고리 ID
     * @param string $collection 컬렉션명
     * @return int
     */
    public function getMaxSortOrder(int $categoryId, string $collection): int;

    /**
     * temp_key의 최대 sort_order 조회
     *
     * @param string $tempKey 임시 키
     * @param string $collection 컬렉션명
     * @return int
     */
    public function getMaxSortOrderByTempKey(string $tempKey, string $collection): int;

    /**
     * 순서 변경
     *
     * @param array<int, int> $orders 이미지 ID => sort_order 매핑
     * @return bool
     */
    public function reorder(array $orders): bool;
}
