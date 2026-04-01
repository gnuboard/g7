<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;

/**
 * 상품 이미지 Repository 인터페이스
 */
interface ProductImageRepositoryInterface
{
    /**
     * ID로 이미지 조회
     *
     * @param  int  $id  이미지 ID
     * @return ProductImage|null
     */
    public function findById(int $id): ?ProductImage;

    /**
     * 해시로 이미지 조회
     *
     * @param  string  $hash  이미지 해시 (12자)
     * @return ProductImage|null
     */
    public function findByHash(string $hash): ?ProductImage;

    /**
     * 상품 ID로 이미지 목록 조회
     *
     * @param  int  $productId  상품 ID
     * @param  string|null  $collection  컬렉션 필터
     * @return Collection
     */
    public function getByProductId(int $productId, ?string $collection = null): Collection;

    /**
     * 임시 키로 이미지 목록 조회
     *
     * @param  string  $tempKey  임시 업로드 키
     * @param  string|null  $collection  컬렉션 필터
     * @return Collection
     */
    public function getByTempKey(string $tempKey, ?string $collection = null): Collection;

    /**
     * 이미지 생성
     *
     * @param  array  $data  이미지 데이터
     * @return ProductImage
     */
    public function create(array $data): ProductImage;

    /**
     * 이미지 업데이트
     *
     * @param  int  $id  이미지 ID
     * @param  array  $data  업데이트할 데이터
     * @return ProductImage
     */
    public function update(int $id, array $data): ProductImage;

    /**
     * 이미지 삭제
     *
     * @param  int  $id  이미지 ID
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * 임시 이미지를 상품에 연결
     *
     * @param  string  $tempKey  임시 업로드 키
     * @param  int  $productId  상품 ID
     * @return int 연결된 이미지 수
     */
    public function linkTempImages(string $tempKey, int $productId): int;

    /**
     * 상품의 컬렉션별 최대 정렬 순서 조회
     *
     * @param  int  $productId  상품 ID
     * @param  string  $collection  컬렉션명
     * @return int
     */
    public function getMaxSortOrder(int $productId, string $collection): int;

    /**
     * 임시 키의 컬렉션별 최대 정렬 순서 조회
     *
     * @param  string  $tempKey  임시 업로드 키
     * @param  string  $collection  컬렉션명
     * @return int
     */
    public function getMaxSortOrderByTempKey(string $tempKey, string $collection): int;

    /**
     * 이미지 순서 변경
     *
     * @param  array<int, int>  $orders  이미지 ID => sort_order 매핑
     * @return bool
     */
    public function reorder(array $orders): bool;
}
