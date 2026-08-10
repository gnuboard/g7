<?php

namespace Modules\Sirsoft\Page\Repositories\Contracts;

use App\Support\Query\BoundedCount;
use App\Support\Query\BoundedPage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\CursorPaginator;
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
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator;

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
     * Sitemap 용으로 발행된 페이지를 스트리밍 조회합니다.
     *
     * 전체 적재를 피하기 위해 id 기준으로 청크 단위 지연 조회합니다.
     *
     * @param  int  $chunkSize  청크 크기
     * @return iterable<Page> 발행된 페이지 순회자 (id, slug, updated_at 만 조회)
     */
    public function streamPublishedForSitemap(int $chunkSize = 500): iterable;

    /**
     * ID로 페이지를 조회하며, 없으면 예외를 발생시킵니다.
     *
     * @param  int  $id  페이지 ID
     * @return Page 페이지 모델
     *
     * @throws ModelNotFoundException
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
     * 키워드로 페이지를 커서(키셋)로 검색합니다.
     *
     * 커서 적용 가능 여부 판정은 코어가 담당하므로, 이 메서드는 이미 검증된 정렬 키를
     * 받아 조회만 수행합니다.
     *
     * @param  string  $keyword  검색 키워드
     * @param  array<int, array{0: string, 1: string}>  $sortKeys  [[컬럼, 방향], ...]
     * @param  int  $perPage  페이지당 항목 수
     * @param  string|null  $cursor  인코딩된 커서 (첫 페이지면 null)
     * @return CursorPaginator 커서 페이지 결과
     */
    public function searchByKeywordWithCursor(
        string $keyword,
        array $sortKeys,
        int $perPage = 10,
        ?string $cursor = null
    ): CursorPaginator;

    /**
     * 키워드로 페이지를 검색합니다.
     *
     * @param  string  $keyword  검색 키워드
     * @param  string  $orderBy  정렬 컬럼
     * @param  string  $direction  정렬 방향 (asc, desc)
     * @param  int  $perPage  페이지당 항목 수
     * @param  int  $page  페이지 번호
     * @return BoundedPage 페이지 결과 (총 건수 정확도 포함)
     */
    public function searchByKeyword(
        string $keyword,
        string $orderBy = 'created_at',
        string $direction = 'desc',
        int $perPage = 10,
        int $page = 1
    ): BoundedPage;

    /**
     * 키워드와 일치하는 발행된 페이지 수를 조회합니다.
     *
     * @param  string  $keyword  검색 키워드
     * @return BoundedCount 일치하는 페이지 수 (정확도 포함)
     */
    public function countByKeyword(string $keyword): BoundedCount;
}
