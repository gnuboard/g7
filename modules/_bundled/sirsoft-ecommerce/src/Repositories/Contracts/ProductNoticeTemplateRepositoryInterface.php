<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ProductNoticeTemplate;

/**
 * 상품정보제공고시 템플릿 Repository 인터페이스
 */
interface ProductNoticeTemplateRepositoryInterface
{
    /**
     * 템플릿 목록 조회
     *
     * @param array $filters 필터 조건
     * @param array $with Eager loading 관계
     * @return Collection
     */
    public function getAll(array $filters = [], array $with = []): Collection;

    /**
     * 템플릿 목록 페이지네이션 조회
     *
     * @param array $filters 필터 조건
     * @param int $perPage 페이지당 항목 수
     * @param array $with Eager loading 관계
     * @return LengthAwarePaginator
     */
    public function getPaginated(array $filters = [], int $perPage = 20, array $with = []): LengthAwarePaginator;

    /**
     * ID로 템플릿 조회
     *
     * @param int $id 템플릿 ID
     * @param array $with Eager loading 관계
     * @return ProductNoticeTemplate|null
     */
    public function findById(int $id, array $with = []): ?ProductNoticeTemplate;

    /**
     * 템플릿 생성
     *
     * @param array $data 템플릿 데이터
     * @return ProductNoticeTemplate
     */
    public function create(array $data): ProductNoticeTemplate;

    /**
     * 템플릿 수정
     *
     * @param int $id 템플릿 ID
     * @param array $data 수정 데이터
     * @return ProductNoticeTemplate
     */
    public function update(int $id, array $data): ProductNoticeTemplate;

    /**
     * 템플릿 삭제
     *
     * @param int $id 템플릿 ID
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * 템플릿 복사
     *
     * @param int $id 원본 템플릿 ID
     * @return ProductNoticeTemplate
     */
    public function copy(int $id): ProductNoticeTemplate;

    /**
     * 최대 정렬 순서 조회
     *
     * @return int
     */
    public function getMaxSortOrder(): int;
}
