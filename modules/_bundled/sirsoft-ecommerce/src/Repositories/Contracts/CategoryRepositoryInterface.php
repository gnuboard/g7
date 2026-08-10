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
     * @param  array  $filters  필터 조건
     * @param  array  $with  Eager loading 관계
     * @return Collection 계층형 카테고리 목록
     */
    public function getHierarchical(array $filters = [], array $with = []): Collection;

    /**
     * ID로 카테고리 조회
     *
     * @param  int  $id  카테고리 ID
     * @param  array  $with  Eager loading 관계
     * @param  bool  $withCounts  상품 수·자식 수 집계 포함 여부 (쓰기 경로는 false)
     * @return Category|null 카테고리 모델 (없으면 null)
     */
    public function findById(int $id, array $with = [], bool $withCounts = false): ?Category;

    /**
     * 카테고리 생성
     *
     * @param  array  $data  카테고리 데이터
     * @return Category 생성된 카테고리 모델
     */
    public function create(array $data): Category;

    /**
     * 카테고리 수정
     *
     * @param  int  $id  카테고리 ID
     * @param  array  $data  수정 데이터
     * @return Category 수정된 카테고리 모델
     */
    public function update(int $id, array $data): Category;

    /**
     * 카테고리 삭제
     *
     * @param  int  $id  카테고리 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $id): bool;

    /**
     * 하위 카테고리 존재 여부 확인
     *
     * @param  int  $id  카테고리 ID
     * @return bool 하위 카테고리 존재 여부
     */
    public function hasChildren(int $id): bool;

    /**
     * 연결된 상품 수 조회
     *
     * @param  int  $id  카테고리 ID
     * @return int 연결된 상품 수
     */
    public function getProductCount(int $id): int;

    /**
     * 다음 정렬 순서 값 조회
     *
     * @param  int|null  $parentId  부모 카테고리 ID
     * @return int 다음 정렬 순서 값
     */
    public function getNextSortOrder(?int $parentId = null): int;

    /**
     * 슬러그 중복 확인
     *
     * @param  string  $slug  슬러그
     * @param  int|null  $excludeId  제외할 카테고리 ID
     * @return bool 슬러그 중복 여부
     */
    public function existsBySlug(string $slug, ?int $excludeId = null): bool;

    /**
     * slug로 카테고리 조회
     *
     * @param  string  $slug  카테고리 slug
     * @param  array  $with  Eager loading 관계
     * @param  bool  $withCounts  상품 수·자식 수 집계 포함 여부 (쓰기 경로는 false)
     * @return Category|null 카테고리 모델 (없으면 null)
     */
    public function findBySlug(string $slug, array $with = [], bool $withCounts = false): ?Category;

    /**
     * ID 목록으로 카테고리를 조회하고 ID 키 맵으로 반환합니다.
     *
     * 재정렬처럼 여러 항목을 한꺼번에 다루는 경로에서 항목마다 조회하지 않도록 합니다.
     * 상품 수·자식 수 집계는 포함하지 않습니다 (쓰기 경로는 읽지 않음).
     *
     * @param  array<int, int>  $ids  카테고리 ID 목록
     * @return Collection<int, Category> 카테고리 ID ⇒ 카테고리
     */
    public function findByIdsKeyed(array $ids): Collection;

    /**
     * 평면 리스트로 카테고리 목록 조회 (TagInput 등에 사용)
     *
     * @param  array  $filters  필터 조건
     * @param  array  $with  Eager loading 관계
     * @return Collection 평면 카테고리 목록
     */
    public function getFlatList(array $filters = [], array $with = []): Collection;

    /**
     * Sitemap 용으로 활성 카테고리를 스트리밍 조회합니다.
     *
     * 전체 적재를 피하기 위해 id 기준으로 청크 단위 지연 조회합니다.
     *
     * @param  int  $chunkSize  청크 크기
     * @return iterable<Category> 활성 카테고리 순회자 (id, slug, updated_at 만 조회)
     */
    public function streamActiveForSitemap(int $chunkSize = 500): iterable;
}
