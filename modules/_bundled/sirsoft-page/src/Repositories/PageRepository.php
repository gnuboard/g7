<?php

namespace Modules\Sirsoft\Page\Repositories;

use App\Helpers\PermissionHelper;
use App\Repositories\Concerns\HasMultipleSearchFilters;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Repositories\Concerns\ResolvesSortSpec;
use App\Search\Engines\DatabaseFulltextEngine;
use App\Search\KeywordSearch;
use App\Support\Query\BoundedCount;
use App\Support\Query\BoundedPage;
use App\Support\Query\BoundedPaginator;
use App\Support\Query\KeysetPaginator;
use App\Support\Query\PaginationLimits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Repositories\Contracts\PageRepositoryInterface;

/**
 * 페이지 Repository
 *
 * 페이지 데이터 접근 계층을 담당합니다.
 */
class PageRepository implements PageRepositoryInterface
{
    use HasMultipleSearchFilters;
    use PaginatesWithDeferredJoin;
    use ResolvesSortSpec;

    /** 허용 정렬 컬럼 (PageListRequest 와 동일 집합) */
    private const SORTABLE_COLUMNS = ['created_at', 'published_at'];

    /**
     * 페이지 목록을 페이지네이션하여 조회합니다.
     *
     * @param  array  $filters  필터 조건 (published, search, search_field)
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 페이지 목록
     */
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        // filters 배열에서 search/search_field 변환
        $filters = $this->normalizeSearchFilters($filters);

        // 검색 필드(title/slug/all)별로 대상 컬럼을 정확히 분리하기 위해
        // 모든 검색을 applyFilters 통합 쿼리로 처리한다.
        //  - title: 제목만 (Scout searchableColumns 는 title+content 를 함께 검색하므로 사용하지 않음)
        //  - slug : 슬러그만
        //  - all  : 제목 + 슬러그 (본문 content 은 검색 대상 아님 — UI '제목 또는 슬러그로 검색')
        // (Scout 콜백에 slug orWhere 를 넣으면 total 카운트가 부풀려지는 회귀가 있어 #225 에서 제거됨)
        $query = Page::query();

        // 권한 스코프 필터링
        PermissionHelper::applyPermissionScope($query, 'sirsoft-page.pages.read');

        $this->applyFilters($query, $filters);

        // 지연 조인: inner 는 id 만 훑는다. outer 도 목록 리소스가 실제로 쓰는 컬럼만
        // 읽는다 — 본문 content(mediumText)는 목록 표현(`PageResource::toListArray`)이
        // 출력하지 않으므로 한 페이지 분량이라도 읽을 이유가 없다 (오버플로 페이지 읽기 제거).
        // 응답 형태는 변하지 않고 DB/메모리 비용만 줄어든다.
        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: [
                'id', 'slug', 'title', 'published', 'published_at',
                'current_version', 'created_by', 'updated_by', 'created_at', 'updated_at',
            ],
            sort: $this->resolveListSortSpec($filters),
            perPage: $perPage,
            relations: ['creator', 'updater'],
            resultCap: PaginationLimits::resultCap('admin.pages'),
        );
    }

    /**
     * ID로 페이지를 조회합니다.
     *
     * @param  int  $id  페이지 ID
     * @return Page|null 페이지 모델 또는 null
     */
    public function findById(int $id): ?Page
    {
        return Page::find($id);
    }

    /**
     * 슬러그로 페이지를 조회합니다.
     *
     * @param  string  $slug  슬러그
     * @return Page|null 페이지 모델 또는 null
     */
    public function findBySlug(string $slug): ?Page
    {
        return Page::where('slug', $slug)->first();
    }

    /**
     * Sitemap 용으로 발행된 페이지를 스트리밍 조회합니다.
     *
     * lazyById 는 id 기준 키셋 페이징으로 청크를 순차 조회하므로,
     * 결과셋 전체가 메모리(및 DB 드라이버 버퍼)에 적재되지 않습니다.
     *
     * @param  int  $chunkSize  청크 크기
     * @return iterable<Page> 발행된 페이지 순회자 (id, slug, updated_at 만 조회)
     */
    public function streamPublishedForSitemap(int $chunkSize = 500): iterable
    {
        return Page::query()
            ->where('published', true)
            ->select(['id', 'slug', 'updated_at'])
            ->orderBy('id')
            ->lazyById($chunkSize);
    }

    /**
     * ID로 페이지를 조회하며, 없으면 예외를 발생시킵니다.
     *
     * @param  int  $id  페이지 ID
     * @return Page 페이지 모델
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): Page
    {
        return Page::findOrFail($id);
    }

    /**
     * 페이지를 생성합니다.
     *
     * @param  array  $data  페이지 생성 데이터
     * @return Page 생성된 페이지 모델
     */
    public function create(array $data): Page
    {
        return Page::create($data);
    }

    /**
     * 페이지를 수정합니다.
     *
     * @param  Page  $page  페이지 모델
     * @param  array  $data  수정할 데이터
     * @return Page 수정된 페이지 모델
     */
    public function update(Page $page, array $data): Page
    {
        $page->fill($data)->save();

        return $page->fresh();
    }

    /**
     * 페이지를 삭제합니다 (소프트 삭제).
     *
     * @param  Page  $page  페이지 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(Page $page): bool
    {
        return (bool) $page->delete();
    }

    /**
     * 슬러그 중복 여부를 확인합니다.
     *
     * @param  string  $slug  확인할 슬러그
     * @param  int|null  $excludeId  제외할 페이지 ID (수정 시)
     * @return bool 중복 여부 (true: 중복)
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $query = Page::where('slug', $slug);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * 키워드로 발행된 페이지를 검색합니다.
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
    ): BoundedPage {
        $query = $this->buildKeywordQuery($keyword)
            ->orderBy($orderBy, $direction)
            // 전순서 보장 — 정렬 컬럼이 비고유라 페이지 경계가 흔들릴 수 있다
            ->orderBy('id', $direction === 'asc' ? 'asc' : 'desc');

        // 검색 결과 카드가 실제로 쓰는 컬럼만 읽는다.
        //
        // content 만 예외로 남긴다. 검색 결과는 키워드 **주변** 문맥을 잘라 보여주므로
        // 본문 어디에 키워드가 있든 그 위치를 찾을 수 있어야 하고, 이 컬럼은 다국어
        // JSON 이라 DB 에서 `SUBSTRING` 으로 자르면 캐스팅이 깨진다. 관리자 페이지 목록
        // (위 paginate)은 본문을 전혀 쓰지 않으므로 그쪽에서는 제외했다.
        //
        // 비용 측면의 목적은 달성돼 있다 — 종전에는 매칭 전량의 본문을 끌어왔지만
        // 지금은 이번 페이지 분량(per_page + 1)만 읽는다.
        return BoundedPaginator::paginate(
            $query,
            perPage: $perPage,
            page: $page,
            resultCap: PaginationLimits::resultCap('search'),
            columns: ['id', 'slug', 'title', 'content', 'published_at', 'created_at'],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function searchByKeywordWithCursor(
        string $keyword,
        array $sortKeys,
        int $perPage = 10,
        ?string $cursor = null
    ): CursorPaginator {
        // 커서 모드에는 OFFSET 이 없다 — 건너뛸 행을 실제로 읽던 비용이 사라진다.
        // 읽는 컬럼은 offset 경로와 같게 유지해 화면이 받는 항목 형태를 맞춘다.
        return KeysetPaginator::paginate(
            query: $this->buildKeywordQuery($keyword),
            perPage: $perPage,
            sortKeys: $sortKeys,
            uniqueKey: 'id',
            cursor: $cursor,
            columns: ['id', 'slug', 'title', 'content', 'published_at', 'created_at'],
        );
    }

    /**
     * 키워드와 일치하는 발행된 페이지 수를 조회합니다.
     *
     * @param  string  $keyword  검색 키워드
     * @return BoundedCount 일치하는 페이지 수 (정확도 포함)
     */
    public function countByKeyword(string $keyword): BoundedCount
    {
        // 비활성 탭의 배지용 건수다. 상한을 걸어 대량 매칭에서도 비용이 일정하게 유지된다.
        // 상한에 걸리면 값이 잘리므로 정확도를 함께 돌려준다.
        return BoundedPaginator::count(
            $this->buildKeywordQuery($keyword),
            PaginationLimits::resultCap('search')
        );
    }

    /**
     * 키워드 검색 공통 쿼리를 구성합니다.
     *
     * FULLTEXT(title, content) OR slug LIKE 를 properly grouped 상태로 AND published 와 결합.
     *
     * 주의: Scout 의 `Page::search($keyword)->query(fn)` 경로에서 non-FT `orWhere('slug')` 를
     * 사용하면, Scout 내부 `Builder::getTotalCount` 가 queryCallback 이 존재할 때
     * `queryScoutModelsByIds` 로 FT 없이 whereIn 을 붙여 재계수하는 경로에서 callback 을 재적용하며
     * `WHERE published = ? OR slug LIKE ? AND id IN (...)` 형태로 연산자 우선순위가 깨져
     * `published = ?` 만 유효해지고 total 이 모든 발행 페이지 수로 부풀려지는 회귀가 발생한다.
     * 이 Repository 의 applyTitleKeywordSearch 에서 이미 사용중인
     * `KeywordSearch::apply()` 패턴과 동일하게 해결한다 — 키워드 조건을 진행 중인 쿼리에
     * 직접 붙이므로 Scout 의 재계수 경로를 타지 않는다. 어떤 조건이 붙는지는 활성 검색
     * 엔진이 정하므로 플러그인 엔진도 이 경로를 그대로 탄다.
     *
     * @param  string  $keyword  검색 키워드
     */
    private function buildKeywordQuery(string $keyword): Builder
    {
        return Page::query()
            ->published()
            ->where(function ($q) use ($keyword) {
                // 제목은 다국어 JSON 컬럼이라 FULLTEXT 에 의존하지 않고 로케일별 JSON 경로로 찾는다
                // (메뉴 목록과 동일한 관례).
                //
                // 실측 배경 (#492 D-25): 운영 DB 에서 `MATCH(title)` 이 **어떤 키워드로도** 0 을
                // 반환했다 — `이용약관`(제목 전체) · `약관`(부분) · `Terms` · `Service` 전부 0 인데
                // 같은 행을 `LIKE` 로는 찾는다. 인덱스는 `WITH PARSER ngram` 으로 존재하고
                // `ngram_token_size=2` 인데도 그렇다. 즉 이 컬럼의 FT 인덱스가 실제 행을 담고
                // 있지 않다. 다국어 제목은 짧은 낱말이라 부분 일치가 기대 동작이므로,
                // FT 인덱스 상태에 의존하지 않는 로케일 경로 LIKE 로 검색 계약을 고정한다.
                // (본문은 길이가 있어 FULLTEXT 가 유효하므로 그대로 둔다.)
                $first = true;
                foreach ($this->translatableLocales() as $locale) {
                    $method = $first ? 'where' : 'orWhere';
                    $q->{$method}('title->'.$locale, 'like', '%'.$keyword.'%');
                    $first = false;
                }

                // 본문은 순수 텍스트/HTML 이라 FULLTEXT 가 유효하다.
                KeywordSearch::apply($q, 'content', $keyword, 'or');
                $q->orWhere('slug', 'like', '%'.$keyword.'%');
            });
    }

    /**
     * 쿼리에 정렬을 적용합니다.
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  array  $filters  필터 조건 (sort_by, sort_order 포함)
     */
    private function applySorting($query, array $filters): void
    {
        foreach ($this->resolveListSortSpec($filters) as $sort) {
            $query->orderBy($sort['column'], $sort['direction']);
        }
    }

    /**
     * 목록 정렬 스펙을 허용 컬럼 화이트리스트로 해석합니다.
     *
     * @param  array  $filters  필터 조건
     * @return array<int, array{column: string, direction: string}> 정렬 스펙
     */
    private function resolveListSortSpec(array $filters): array
    {
        return $this->resolveSortSpec($filters, self::SORTABLE_COLUMNS, 'created_at');
    }

    /**
     * 쿼리에 필터를 적용합니다.
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  array  $filters  필터 조건
     */
    private function applyFilters($query, array $filters): void
    {
        // 발행 상태 필터
        if (isset($filters['published']) && $filters['published'] !== '') {
            $query->where('published', (bool) $filters['published']);
        }

        // 검색
        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $searchField = $filters['search_field'] ?? 'all';

            if ($searchField === 'slug') {
                $query->where('slug', 'like', "%{$keyword}%");
            } elseif ($searchField === 'title') {
                $query->where(function ($q) use ($keyword) {
                    $this->applyTitleKeywordSearch($q, $keyword, includeSlug: false);
                });
            } else {
                // all: 제목 + 슬러그 통합 검색 (본문 content 은 검색 대상 아님 — UI '제목 또는 슬러그로 검색')
                $query->where(function ($q) use ($keyword) {
                    $this->applyTitleKeywordSearch($q, $keyword, includeSlug: true);
                });
            }
        }
    }

    /**
     * 제목(JSON) + 슬러그 키워드 검색을 쿼리에 적용합니다.
     *
     * title은 {"ko": "...", "en": "..."} 형태의 JSON 컬럼이므로 FULLTEXT 로 검색한다.
     * 검색 대상은 제목·슬러그이며 본문(content)은 포함하지 않는다
     * (검색 UI 안내 '제목 또는 슬러그로 검색' 및 검색 필드 옵션 전체/제목/슬러그와 일치).
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string  $keyword  검색 키워드
     * @param  bool  $includeSlug  슬러그도 함께 검색할지 여부 (전체 검색 시 true)
     */
    private function applyTitleKeywordSearch($query, string $keyword, bool $includeSlug = false): void
    {
        if ($includeSlug) {
            $query->where('slug', 'like', "%{$keyword}%");
        }

        KeywordSearch::apply($query, 'title', $keyword, 'or');
    }

    /**
     * filters 배열을 search/search_field로 정규화합니다.
     *
     * 레이아웃에서 전달되는 filters[0][field]/filters[0][value] 형식을
     * 기존 search/search_field 형식으로 변환합니다.
     *
     * @param  array  $filters  필터 조건
     * @return array 정규화된 필터 조건
     */
    private function normalizeSearchFilters(array $filters): array
    {
        if (! empty($filters['filters']) && is_array($filters['filters'])) {
            $firstFilter = $filters['filters'][0] ?? null;
            if ($firstFilter && ! empty($firstFilter['value'])) {
                $filters['search'] = $firstFilter['value'];
                $filters['search_field'] = $firstFilter['field'] ?? 'all';
            }
        }

        return $filters;
    }

    /**
     * 다국어 JSON 필드에 저장될 수 있는 로케일 목록을 반환합니다.
     *
     * 번역 파일이 없어도 데이터는 저장될 수 있으므로 UI 표시 언어(`supported_locales`)보다 넓은
     * `translatable_locales` 를 기준으로 한다 (MenuRepository 와 동일 관례).
     *
     * @return list<string> 로케일 목록
     */
    private function translatableLocales(): array
    {
        $locales = config('app.translatable_locales', config('app.supported_locales', []));

        return empty($locales) ? [app()->getLocale()] : array_values($locales);
    }
}
