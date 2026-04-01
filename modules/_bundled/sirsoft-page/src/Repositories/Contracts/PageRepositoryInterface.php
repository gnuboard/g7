<?php

namespace Modules\Sirsoft\Page\Repositories\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Page\Models\Page;

/**
 * 페이지 Repository 인터페이스
 */
interface PageRepositoryInterface
{
    /**
     * 페이지 목록을 페이지네이션하여 조회합니다.
     *
     * @param  array  $filters  필터 조건 (published, search, search_field)
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 페이지 목록
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * ID로 페이지를 조회합니다.
     *
     * @param  int  $id  페이지 ID
     * @return Page|null 페이지 모델 또는 null
     */
    public function findById(int $id): ?Page;

    /**
     * 슬러그로 페이지를 조회합니다.
     *
     * @param  string  $slug  슬러그
     * @return Page|null 페이지 모델 또는 null
     */
    public function findBySlug(string $slug): ?Page;

    /**
     * ID로 페이지를 조회하며, 없으면 예외를 발생시킵니다.
     *
     * @param  int  $id  페이지 ID
     * @return Page 페이지 모델
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail(int $id): Page;

    /**
     * 페이지를 생성합니다.
     *
     * @param  array  $data  페이지 생성 데이터
     * @return Page 생성된 페이지 모델
     */
    public function create(array $data): Page;

    /**
     * 페이지를 수정합니다.
     *
     * @param  Page  $page  페이지 모델
     * @param  array  $data  수정할 데이터
     * @return Page 수정된 페이지 모델
     */
    public function update(Page $page, array $data): Page;

    /**
     * 페이지를 삭제합니다 (소프트 삭제).
     *
     * @param  Page  $page  페이지 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(Page $page): bool;

    /**
     * 슬러그 중복 여부를 확인합니다.
     *
     * @param  string  $slug  확인할 슬러그
     * @param  int|null  $excludeId  제외할 페이지 ID (수정 시)
     * @return bool 중복 여부 (true: 중복)
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool;

    /**
     * 키워드로 페이지를 검색합니다.
     *
     * @param  string  $keyword  검색 키워드
     * @param  string  $orderBy  정렬 컬럼
     * @param  string  $direction  정렬 방향 (asc, desc)
     * @param  int  $limit  조회할 최대 항목 수
     * @return array{total: int, items: \Illuminate\Database\Eloquent\Collection}
     */
    public function searchByKeyword(string $keyword, string $orderBy = 'created_at', string $direction = 'desc', int $limit = 10): array;

    /**
     * 키워드와 일치하는 발행된 페이지 수를 조회합니다.
     *
     * @param  string  $keyword  검색 키워드
     * @return int 일치하는 페이지 수
     */
    public function countByKeyword(string $keyword): int;

    /**
     * 여러 페이지의 발행 상태를 일괄 변경합니다.
     *
     * @param  array  $ids  페이지 ID 목록
     * @param  array  $data  업데이트할 데이터 (published, updated_by 등)
     * @return int 변경된 페이지 수
     */
    public function bulkUpdatePublished(array $ids, array $data): int;
}
