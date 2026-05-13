<?php

namespace Modules\Sirsoft\Page\Repositories;

use App\Helpers\PermissionHelper;
use App\Repositories\Concerns\HasMultipleSearchFilters;
use App\Search\Engines\DatabaseFulltextEngine;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * 페이지 목록을 페이지네이션하여 조회합니다.
     *
     * @param  array  $filters  필터 조건 (published, search, search_field)
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 페이지 목록
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        // filters 배열에서 search/search_field 변환
        $filters = $this->normalizeSearchFilters($filters);

        // FULLTEXT 대상 검색인 경우 Scout 사용
        if (! empty($filters['search'])) {
            $searchField = $filters['search_field'] ?? 'all';

            if (in_array($searchField, ['all', 'title'])) {
                return Page::search($filters['search'])
                    ->query(function ($query) use ($filters) {
                        $query->with(['creator', 'updater']);

                        // 권한 스코프 필터링
                        PermissionHelper::applyPermissionScope($query, 'sirsoft-page.pages.read');

                        // 발행 상태 필터
                        if (isset($filters['published']) && $filters['published'] !== '') {
                            $query->where('published', (bool) $filters['published']);
                        }

                        // 정렬
                        $this->applySorting($query, $filters);
                    })
                    ->paginate($perPage);
            }
        }

        $query = Page::with(['creator', 'updater']);

        // 권한 스코프 필터링
        PermissionHelper::applyPermissionScope($query, 'sirsoft-page.pages.read');

        $this->applyFilters($query, $filters);
        $this->applySorting($query, $filters);

        return $query->paginate($perPage);
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
     * ID로 페이지를 조회하며, 없으면 예외를 발생시킵니다.
     *
     * @param  int  $id  페이지 ID
     * @return Page 페이지 모델
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
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
     * @param  int  $limit  조회할 최대 항목 수
     * @return array{total: int, items: Collection}
     */
    public function searchByKeyword(string $keyword, string $orderBy = 'created_at', string $direction = 'desc', int $limit = 10): array
    {
        $results = $this->buildKeywordQuery($keyword)
            ->orderBy($orderBy, $direction)
            ->paginate($limit);

        return [
            'total' => $results->total(),
            'items' => $results->getCollection(),
        ];
    }

    /**
     * 키워드와 일치하는 발행된 페이지 수를 조회합니다.
     *
     * @param  string  $keyword  검색 키워드
     * @return int 일치하는 페이지 수
     */
    public function countByKeyword(string $keyword): int
    {
        return $this->buildKeywordQuery($keyword)->count();
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
     * `DatabaseFulltextEngine::whereFulltext` 정적 헬퍼 패턴과 동일하게 해결한다
     * (Scout 엔진은 indexing 및 다른 검색에서 계속 사용됨).
     *
     * @param  string  $keyword  검색 키워드
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function buildKeywordQuery(string $keyword): \Illuminate\Database\Eloquent\Builder
    {
        return Page::query()
            ->published()
            ->where(function ($q) use ($keyword) {
                DatabaseFulltextEngine::whereFulltext($q, 'title', $keyword, 'and');
                DatabaseFulltextEngine::whereFulltext($q, 'content', $keyword, 'or');
                $q->orWhere('slug', 'like', '%'.$keyword.'%');
            });
    }

    /**
     * 쿼리에 정렬을 적용합니다.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  쿼리 빌더
     * @param  array  $filters  필터 조건 (sort_by, sort_order 포함)
     */
    private function applySorting($query, array $filters): void
    {
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc');

        if (! in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'desc';
        }

        $query->orderBy($sortBy, $sortOrder);
    }

    /**
     * 쿼리에 필터를 적용합니다.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  쿼리 빌더
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
                    $this->applyTitleKeywordSearch($q, $keyword, titleOnly: true);
                });
            } else {
                // all: slug + title 통합 검색
                $query->where(function ($q) use ($keyword) {
                    $this->applyTitleKeywordSearch($q, $keyword);
                });
            }
        }
    }

    /**
     * 제목(JSON) + 슬러그 키워드 검색을 쿼리에 적용합니다.
     *
     * title은 {"ko": "...", "en": "..."} 형태의 JSON 컬럼이므로
     * JSON_UNQUOTE + LIKE 방식으로 각 로케일 값을 검색합니다.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  쿼리 빌더
     * @param  string  $keyword  검색 키워드
     * @param  bool  $titleOnly  제목만 검색 여부 (false 시 slug도 포함)
     */
    private function applyTitleKeywordSearch($query, string $keyword, bool $titleOnly = false): void
    {
        if (! $titleOnly) {
            $query->where('slug', 'like', "%{$keyword}%");
        }

        DatabaseFulltextEngine::whereFulltext($query, 'title', $keyword, 'or');

        if (! $titleOnly) {
            DatabaseFulltextEngine::whereFulltext($query, 'content', $keyword, 'or');
        }
    }

    /**
     * 여러 페이지의 발행 상태를 일괄 변경합니다.
     *
     * @param  array  $ids  페이지 ID 목록
     * @param  array  $data  업데이트할 데이터 (published, updated_by 등)
     * @return int 변경된 페이지 수
     */
    public function bulkUpdatePublished(array $ids, array $data): int
    {
        return Page::whereIn('id', $ids)->update($data);
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
}
