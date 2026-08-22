<?php

namespace App\Repositories\Concerns;

use App\Enums\TotalRelation;
use App\Support\Query\BoundedPage;
use App\Support\Query\BoundedPaginator;
use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * 지연 조인(deferred join) 기반 목록 페이지네이션 Trait
 *
 * `OFFSET n` 은 건너뛸 n 건도 실제로 읽는다. 목록 SELECT 에 넓은 컬럼(text/longText/JSON)이
 * 포함돼 있으면 그 n 건의 넓은 컬럼까지 함께 읽히고, 특히 InnoDB 는 행 밖 오버플로 페이지에
 * 저장된 값을 앞부분만 필요해도 페이지째 읽는다. 그래서 페이지가 뒤로 갈수록 비용이 선형으로
 * 커진다.
 *
 * 지연 조인은 이를 두 단계로 나눈다.
 *
 *   1. inner — 필터/정렬만으로 **키 컬럼만** 조회해 이번 페이지의 ID 집합을 구한다
 *   2. outer — 그 ID 집합에 대해서만 목록 컬럼과 관계를 조회한다
 *
 * 넓은 컬럼을 읽는 행 수가 OFFSET 과 무관하게 `$perPage` 건으로 고정되므로 뒤쪽 페이지의
 * 비용 증가가 사라진다.
 *
 * 호출 계약:
 *   - `$query` 에는 **필터/where 만** 적용한다 (`orderBy`/`with`/`select` 미적용)
 *   - `whereHas` 는 결과 집합을 결정하므로 그대로 둔다 — 제거되는 것은 `with`(eager load) 뿐이다
 *   - 관계는 **반드시 `$relations` 인자로** 넘긴다. `setEagerLoads([])` 는 inner 뿐 아니라
 *     **outer 에도** 적용되므로, `$query->with()` 만 하고 인자를 생략하면 관계가 로드되지 않는다.
 *     이 실패는 예외도 쿼리 오류도 내지 않고 응답에서 관계 필드만 사라진다
 *   - 정렬은 닫힌 집합이어야 한다 (ResolvesSortSpec 참조). 인덱스 없는 컬럼 정렬은 inner 도
 *     전체 스캔하므로 개선 폭이 보장되지 않는다
 *   - 키 컬럼은 정렬 마지막에 자동으로 덧붙는다 (동률 시 페이지 경계가 흔들리는 것을 방지)
 *
 * 목록 컬럼에 `SUBSTRING(content, 1, N)` 을 두는 것은 프루닝으로 인정하지 않는다. 오버플로
 * 페이지 읽기가 그대로 발생하므로, 잘라내기는 outer(`$columns`)에서만 수행한다.
 *
 * @see ResolvesSortSpec 정렬 스펙 해석
 */
trait PaginatesWithDeferredJoin
{
    /**
     * 지연 조인으로 목록을 페이지네이션합니다.
     *
     * @param  Builder  $query  필터/where 만 적용된 쿼리 (orderBy/with/select 미적용)
     * @param  array  $columns  outer select 목록 (DB::raw 허용)
     * @param  array<int, array{column: string, direction: string}>  $sort  정렬 스펙
     * @param  int  $perPage  페이지당 건수
     * @param  int|null  $page  현재 페이지 (null = 요청에서 해석)
     * @param  array<int, string>  $relations  outer 전용 eager load 관계
     * @param  array<int|string, string|Closure>  $withCount  outer 전용 관계 카운트. `withCount()` 로
     *                                                        그대로 forward 되므로 Laravel 의 두 형태를
     *                                                        모두 받는다 — 목록 형태(`['options']`) 와
     *                                                        제약 클로저를 붙인 연관 형태
     *                                                        (`['options as active_count' => fn ($q) => ...]`).
     *                                                        별칭 없이 같은 관계를 두 번 세면 기본 별칭이
     *                                                        충돌해 뒤엣것이 앞엣것을 덮으므로 `as` 로 구분한다
     * @param  string  $keyName  키 컬럼명 (기본 id)
     * @param  int|null  $total  전체 건수 (null = COUNT 수행, 값 지정 시 캐시 total 사용)
     * @param  bool  $simple  true = COUNT 없이 다음 페이지 유무만 판정
     * @param  bool  $preserveIdOrder  true = inner 가 정한 ID 순서를 outer 에서 그대로 복원
     * @param  string  $pageName  페이지 쿼리 파라미터명
     * @param  Closure|null  $outerUsing  outer 에만 적용할 조인/집계 (inner 에서는 실행되지 않는다)
     * @param  int|null  $resultCap  총 건수 집계 상한 (null = 항상 정확한 COUNT)
     * @return PaginatorContract 페이지네이터 ($simple=true 면 Paginator, $resultCap 지정 시 BoundedPage, 아니면 LengthAwarePaginator)
     */
    protected function paginateWithDeferredJoin(
        Builder|Relation $query,
        array $columns,
        array $sort,
        int $perPage,
        ?int $page = null,
        array $relations = [],
        array $withCount = [],
        string $keyName = 'id',
        ?int $total = null,
        bool $simple = false,
        bool $preserveIdOrder = false,
        string $pageName = 'page',
        ?Closure $outerUsing = null,
        ?int $resultCap = null,
    ): PaginatorContract {
        // 관계(`$model->items()`)도 받는다 — 표준 `paginate()` 가 되는 자리는 이 계약으로
        // 바꿔도 되어야 한다. 관계의 소속 조건은 그 밑 빌더에 이미 들어가 있어 보존된다.
        // (쿼리 빌더는 받지 않는다. 이 계약은 모델의 키 컬럼·eager load 를 다루므로
        //  Eloquent 가 전제다 — 넓힐 수 있는 범위와 없는 범위를 구분한다.)
        $query = $query instanceof Relation ? $query->getQuery() : $query;

        $page = $page ?: Paginator::resolveCurrentPage($pageName);
        $page = max(1, $page);

        $sort = $this->normalizeSortSpecForKey($sort, $keyName);

        // inner — 키 컬럼만 조회한다. with() 를 비우는 이유 두 가지:
        //   (a) 성능 — 관계 쿼리가 inner/outer 두 번 실행된다
        //   (b) 정합성 — inner 는 키 컬럼만 select 하므로 관계 FK 가 없어 eager load 가 깨진다
        // whereHas 는 결과 집합을 결정하는 조건이라 setEagerLoads 로 지워지지 않는다 (의도된 구분).
        $inner = (clone $query)->setEagerLoads([]);

        // 상한이 지정되면 총 건수를 상한까지만 센다. 다음 페이지 판정은 총 건수와 무관하게
        // per_page + 1 실측으로 하므로, 마지막 페이지 번호 하나만 계산 불가가 된다.
        $bounded = ! $simple && $resultCap !== null && $resultCap > 0;
        $relation = null;

        if ($bounded && $total === null) {
            // Eloquent 빌더를 그대로 넘긴다 — countWithCap 이 내부에서 toBase() 로
            // 글로벌 스코프까지 적용한 기반 쿼리를 만든다.
            [$total, $relation] = BoundedPaginator::countWithCap(clone $inner, $resultCap);
        } elseif (! $simple && $total === null) {
            // Laravel 의 paginate() 와 같은 집계 경로를 쓴다. count() 는 groupBy/having 이 있는
            // 쿼리에서 `select count(*) ... group by ...` 를 그대로 실행해 **첫 그룹의 행 수**를
            // 총 건수로 돌려준다. getCountForPagination() 은 그룹 쿼리를 서브쿼리로 감싸므로
            // 그룹 수가 정확히 나온다. toBase() 는 글로벌 스코프를 적용한 뒤 기반 쿼리를 주므로
            // 소프트 삭제/필터 조건이 집계에서 누락되지 않는다.
            $total = (clone $inner)->toBase()->getCountForPagination();
        }

        $this->applySortSpec($inner, $sort);

        // simple/bounded 모드는 다음 페이지 유무 판정을 위해 한 건을 더 읽는다.
        // forPage() 를 쓰면 offset 이 (page-1) * (perPage+1) 로 계산돼 페이지가 넘어갈수록
        // 건너뛰는 행이 어긋나므로, offset 은 perPage 기준으로 직접 지정한다.
        $probesNextPage = $simple || $bounded;
        $fetchCount = $probesNextPage ? $perPage + 1 : $perPage;

        $ids = $inner
            ->select($inner->getModel()->qualifyColumn($keyName))
            ->offset(($page - 1) * $perPage)
            ->limit($fetchCount)
            ->pluck($keyName);

        $hasMorePages = $probesNextPage && $ids->count() > $perPage;

        if ($bounded && $hasMorePages) {
            $ids = $ids->slice(0, $perPage)->values();
        }

        $items = $ids->isEmpty()
            ? new Collection
            : $this->fetchDeferredJoinRows($query, $columns, $sort, $ids->all(), $relations, $withCount, $keyName, $preserveIdOrder, $outerUsing);

        $options = [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ];

        if ($simple) {
            return new Paginator($items, $perPage, $page, $options);
        }

        if ($bounded) {
            // 동시 삽입으로 집계 시점과 조회 시점의 모수가 달라질 수 있다
            $seenSoFar = ($page - 1) * $perPage + $items->count();

            return new BoundedPage(
                items: $items,
                total: max((int) $total, $seenSoFar),
                perPage: $perPage,
                currentPage: $page,
                totalRelation: $relation ?? TotalRelation::Exact,
                resultCap: $resultCap,
                hasMorePages: $hasMorePages,
                options: $options,
            );
        }

        return new LengthAwarePaginator($items, $total ?? $items->count(), $perPage, $page, $options);
    }

    /**
     * outer 쿼리를 실행해 실제 행을 조회합니다.
     *
     * @param  Builder  $query  필터가 적용된 원본 쿼리 (soft delete 스코프 보존을 위해 재사용)
     * @param  array  $columns  select 목록
     * @param  array<int, array{column: string, direction: string}>  $sort  정렬 스펙
     * @param  array<int, mixed>  $ids  이번 페이지의 키 값 목록
     * @param  array<int, string>  $relations  eager load 관계
     * @param  array<int|string, string|Closure>  $withCount  관계 카운트 (목록/연관 두 형태 모두 허용)
     * @param  string  $keyName  키 컬럼명
     * @param  bool  $preserveIdOrder  ID 순서 그대로 복원 여부
     * @param  Closure|null  $outerUsing  outer 전용 조인/집계
     * @return Collection 조회된 행 컬렉션
     */
    private function fetchDeferredJoinRows(
        Builder $query,
        array $columns,
        array $sort,
        array $ids,
        array $relations,
        array $withCount,
        string $keyName,
        bool $preserveIdOrder,
        ?Closure $outerUsing = null,
    ): Collection {
        $outer = (clone $query)->setEagerLoads([]);
        $qualifiedKey = $outer->getModel()->qualifyColumn($keyName);

        $outer->whereIn($qualifiedKey, $ids);

        // 결과 집합을 좁히지 않는 조인/집계(최근 댓글 서브쿼리, 상관 COUNT 등)는 여기서만
        // 붙인다. inner 에 두면 건너뛸 행 전체에 대해 실행되므로 OFFSET 이 깊어질수록 그
        // 비용이 그대로 누적된다. whereIn 뒤에 적용하므로 이번 페이지 건수에만 실행된다.
        if ($outerUsing !== null) {
            $outerUsing($outer);
        }

        if (! empty($relations)) {
            $outer->with($relations);
        }

        if (! empty($withCount)) {
            $outer->withCount($withCount);
        }

        if ($preserveIdOrder) {
            $this->applyIdOrder($outer, $qualifiedKey, $ids);
        } else {
            // 정렬 스펙에 키 컬럼이 포함돼 전순서가 보장되므로, 같은 스펙을 다시 적용하면
            // inner 가 정한 순서와 결과가 동일하다 (MySQL 전용 FIELD() 가 필요 없다).
            $this->applySortSpec($outer, $sort);
        }

        return $outer->get($columns);
    }

    /**
     * 정렬 스펙을 쿼리에 적용합니다.
     *
     * 컬럼이 상관 서브쿼리(Builder)면 `orderBy()` 가 서브 select 로 전개한다. 서브쿼리는
     * 원 행의 키에 상관되므로 inner/outer 어느 쪽에 적용해도 같은 순서를 만든다 —
     * outer 에서는 이번 페이지 건수만큼만 실행된다.
     *
     * @param  Builder  $query  대상 쿼리
     * @param  array<int, array{column: string|Builder|Expression, direction: string}>  $sort  정렬 스펙
     */
    private function applySortSpec(Builder $query, array $sort): void
    {
        foreach ($sort as $spec) {
            $query->orderBy($spec['column'], $spec['direction']);
        }
    }

    /**
     * 정렬 스펙 끝에 키 컬럼을 덧붙여 전순서를 만듭니다.
     *
     * 정렬 컬럼에 중복이 많으면(상태/조회수/평점 등) 동률 행의 순서가 실행마다 달라져 페이지
     * 경계에서 행이 중복되거나 누락된다. inner 와 outer 가 같은 순서를 갖도록 하는 전제이기도 하다.
     *
     * 정렬 컬럼은 문자열 외에 **상관 서브쿼리(Builder)** 나 Expression 도 올 수 있다
     * (관계 테이블 컬럼 기준 정렬 — `SortsByRelatedColumn`). 이 경우 키 컬럼일 수 없으므로
     * 전순서 보장을 위해 키 컬럼이 항상 덧붙는다.
     *
     * @param  array<int, array{column: string|Builder|Expression, direction: string}>  $sort  정렬 스펙
     * @param  string  $keyName  키 컬럼명
     * @return array<int, array{column: string|Builder|Expression, direction: string}> 키 컬럼이 보장된 정렬 스펙
     */
    private function normalizeSortSpecForKey(array $sort, string $keyName): array
    {
        $normalized = [];

        foreach ($sort as $spec) {
            $column = $spec['column'] ?? null;

            if (is_string($column) ? $column === '' : ! ($column instanceof Builder || $column instanceof QueryBuilder || $column instanceof Expression)) {
                continue;
            }

            $normalized[] = [
                'column' => $column,
                'direction' => strtolower($spec['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
            ];
        }

        foreach ($normalized as $spec) {
            // 이미 키 컬럼으로 정렬한다면 그것으로 전순서가 성립한다
            if (! is_string($spec['column'])) {
                continue;
            }

            if ($spec['column'] === $keyName || str_ends_with($spec['column'], '.'.$keyName)) {
                return $normalized;
            }
        }

        $lastDirection = $normalized === [] ? 'desc' : end($normalized)['direction'];

        $normalized[] = ['column' => $keyName, 'direction' => $lastDirection];

        return $normalized;
    }

    /**
     * inner 가 정한 ID 순서를 outer 에서 그대로 복원합니다.
     *
     * outer 에 존재하지 않는 표현식(집계/조인 서브쿼리 등)으로 정렬한 경우에만 사용한다.
     * MySQL 전용 `FIELD()` 대신 표준 SQL `CASE WHEN` 을 쓴다 (DB 비의존 규정).
     *
     * @param  Builder  $query  대상 쿼리
     * @param  string  $qualifiedKey  테이블명이 붙은 키 컬럼
     * @param  array<int, mixed>  $ids  복원할 순서의 키 값 목록
     */
    private function applyIdOrder(Builder $query, string $qualifiedKey, array $ids): void
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($qualifiedKey);

        $cases = [];
        $bindings = [];

        foreach (array_values($ids) as $index => $id) {
            $cases[] = "WHEN {$wrapped} = ? THEN {$index}";
            $bindings[] = $id;
        }

        $query->orderByRaw('CASE '.implode(' ', $cases).' ELSE '.count($ids).' END', $bindings);
    }
}
