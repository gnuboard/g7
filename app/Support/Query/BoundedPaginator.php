<?php

namespace App\Support\Query;

use App\Enums\TotalRelation;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

/**
 * 상한을 건 총 건수 집계 페이지네이터
 *
 * 임의의 빌더를 받아 한 페이지를 조회하고, 총 건수는 상한까지만 센다. 검색이나 특정
 * 도메인에 대한 지식이 없으므로 어떤 목록에서도 쓸 수 있다.
 *
 * 표준 `paginate()` 와 다른 점 둘:
 *
 * 1. **총 건수만 상한을 받는다.** `SELECT COUNT(*) FROM (SELECT 1 FROM ... LIMIT cap+1) t`
 *    로 감싸므로, 상한 이하면 표준 `COUNT(*)` 와 값이 같고 초과할 때만 "이상" 이 된다.
 * 2. **다음 페이지 판정은 총 건수와 무관하다.** `per_page + 1` 건을 읽어 초과분 존재
 *    여부로 판정하므로, 총 건수를 몰라도 "다음" 이동은 끝까지 정확하다.
 *
 * 상한을 넘겨 잘린 경우에도 페이지 이동이 막히지 않는다. 계산이 불가능해지는 것은
 * 마지막 페이지 번호 하나뿐이며, 그 사실은 {@see BoundedPage::lastPage()} 가 null 로 알린다.
 *
 * **입력 범위는 표준 `paginate()` 와 같아야 한다.** Eloquent 빌더뿐 아니라 쿼리 빌더
 * (`DB::table(...)`)와 관계(`$user->notifications()`)도 받는다. 관계에서 `->paginate()` 가
 * 되는 이유는 관계가 빌더로 호출을 전달해 주기 때문인데, 정적 메서드는 그 전달을 받지
 * 못한다. 그래서 여기서 직접 이해한다 — 이 입구가 표준보다 좁으면, 표준을 이 계약으로
 * 바꾸는 것만으로 멀쩡하던 목록이 TypeError 로 죽는다.
 */
class BoundedPaginator
{
    /**
     * 관계를 그 밑의 빌더로 환원합니다.
     *
     * 관계의 소속 조건(`where user_id = ?` 등)은 관계 생성 시점에 빌더에 이미 들어가 있어
     * 그대로 보존된다.
     *
     * @param  EloquentBuilder|QueryBuilder|Relation  $query  대상
     * @return EloquentBuilder|QueryBuilder 빌더
     */
    private static function toBuilder(EloquentBuilder|QueryBuilder|Relation $query): EloquentBuilder|QueryBuilder
    {
        return $query instanceof Relation ? $query->getQuery() : $query;
    }

    /**
     * 한 페이지를 조회하고 상한 총 건수를 함께 계산합니다.
     *
     * @param  EloquentBuilder|QueryBuilder|Relation  $query  대상 빌더/관계 (정렬·필터가 이미 적용된 상태)
     * @param  int  $perPage  페이지당 건수
     * @param  int|null  $page  현재 페이지 번호 (null 이면 요청에서 해석)
     * @param  int|null  $resultCap  총 건수 집계 상한 (null 이면 무제한 = 정확한 COUNT)
     * @param  array<int, string>  $columns  조회 컬럼
     * @param  string  $pageName  페이지 쿼리 파라미터 이름
     * @return BoundedPage 페이지 결과 (총 건수 정확도 포함)
     */
    public static function paginate(
        EloquentBuilder|QueryBuilder|Relation $query,
        int $perPage,
        ?int $page = null,
        ?int $resultCap = null,
        array $columns = ['*'],
        string $pageName = 'page'
    ): BoundedPage {
        $query = self::toBuilder($query);

        $page = $page ?: Paginator::resolveCurrentPage($pageName);
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        // per_page + 1 건을 읽어 다음 페이지 존재 여부를 실측한다.
        // 총 건수 상한과 무관하게 항상 정확하다. 호출자의 빌더는 건드리지 않는다.
        //
        // offset 은 per_page 기준으로 계산한다. forPage($page, $perPage + 1) 을 쓰면
        // offset 까지 per_page + 1 배수가 되어 페이지가 깊어질수록 경계가 밀린다.
        $items = (clone $query)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage + 1)
            ->get($columns);

        $hasMorePages = $items->count() > $perPage;

        if ($hasMorePages) {
            $items = $items->slice(0, $perPage)->values();
        }

        [$total, $relation] = self::countWithCap($query, $resultCap);

        // 상한 이하인데 이번 페이지가 상한 경계를 넘겨 조회된 경우를 방어한다.
        // (동시 삽입으로 카운트 시점과 조회 시점의 모수가 다를 수 있다)
        $seenSoFar = ($page - 1) * $perPage + $items->count();
        if ($total < $seenSoFar) {
            $total = $seenSoFar;
        }

        return new BoundedPage(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            totalRelation: $relation,
            resultCap: $resultCap,
            hasMorePages: $hasMorePages,
            options: [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ],
        );
    }

    /**
     * 목록 조회 없이 상한을 건 총 건수만 셉니다.
     *
     * 탭 배지처럼 건수만 필요한 자리에서 쓴다. {@see countWithCap()} 과 같은 쿼리를
     * 실행하지만 정확도를 값에 담아 돌려주므로, 잘린 값이 정확한 것처럼 화면에
     * 나가는 일이 생기지 않는다.
     *
     * @param  EloquentBuilder|QueryBuilder|Relation  $query  대상 빌더/관계
     * @param  int|null  $resultCap  상한 (null 이면 무제한 = 정확한 COUNT)
     * @return BoundedCount 건수 + 정확도
     */
    public static function count(EloquentBuilder|QueryBuilder|Relation $query, ?int $resultCap): BoundedCount
    {
        [$total, $relation] = self::countWithCap($query, $resultCap);

        return new BoundedCount($total, $relation, $resultCap);
    }

    /**
     * 상한을 건 총 건수를 셉니다.
     *
     * 상한을 넘는지만 알면 되므로 `LIMIT cap + 1` 로 감싼 파생 테이블을 센다.
     * 결과가 `cap + 1` 이면 실제 건수는 그 이상이므로 상한값을 하한으로 보고한다.
     *
     * `GROUP BY` 가 걸린 쿼리도 파생 테이블 안에서 그룹이 만들어지므로 그룹 수가
     * 그대로 센다 (표준 `count()` 가 그룹별 건수를 돌려주는 문제가 없다).
     *
     * @param  EloquentBuilder|QueryBuilder|Relation  $query  대상 빌더/관계
     * @param  int|null  $resultCap  상한 (null 이면 무제한 = 정확한 COUNT)
     * @return array{0: int, 1: TotalRelation} [총 건수, 정확도]
     */
    public static function countWithCap(EloquentBuilder|QueryBuilder|Relation $query, ?int $resultCap): array
    {
        $base = self::toCountableBase(self::toBuilder($query));

        if ($resultCap === null || $resultCap <= 0) {
            return [$base->getCountForPagination(), TotalRelation::Exact];
        }

        $bounded = $base->limit($resultCap + 1);

        // newQuery() 는 같은 커넥션의 빈 빌더를 만든다. fromSub 가 바인딩까지 옮겨 주므로
        // 서브쿼리 SQL 을 문자열로 조립하거나 mergeBindings 를 부를 필요가 없다.
        // 별칭은 빌더가 wrapTable 로 접두사를 붙이므로 `g7_` 을 직접 쓰면 `g7_g7_...` 이
        // 된다 (참조 0곳이라 실동작 무해였으나 명명 혼선 제거).
        $counted = (int) $bounded->newQuery()
            ->fromSub($bounded, 'bounded_total')
            ->count();

        return $counted > $resultCap
            ? [$resultCap, TotalRelation::AtLeast]
            : [$counted, TotalRelation::Exact];
    }

    /**
     * 총 건수 집계용 기본 빌더를 만듭니다.
     *
     * 정렬은 건수에 영향을 주지 않으므로 제거하고, `DISTINCT`·`GROUP BY` 가 없으면
     * SELECT 목록을 상수로 좁혀 불필요한 컬럼 읽기를 없앤다.
     *
     * @param  EloquentBuilder|QueryBuilder  $query  대상 빌더
     * @return QueryBuilder 집계용 기본 빌더 (원본 불변)
     */
    private static function toCountableBase(EloquentBuilder|QueryBuilder $query): QueryBuilder
    {
        // toBase() 는 Eloquent 빌더에만 있다. 이 클래스는 쿼리 빌더도 받겠다고 선언했으므로
        // (supports() 가 그것을 허용한다) 종류를 보고 갈라야 한다 — 그러지 않으면
        // `DB::table(...)` 을 그대로 넘긴 호출이 BadMethodCallException 으로 죽는다.
        $clone = clone $query;
        $base = $clone instanceof EloquentBuilder ? $clone->toBase() : $clone;

        $base->reorder();
        $base->limit(null)->offset(null);

        // DISTINCT / GROUP BY 는 어떤 컬럼을 세는지가 결과를 좌우하므로 SELECT 를 건드리지 않는다.
        if (! $base->distinct && empty($base->groups)) {
            $base->select(DB::raw('1'));
        }

        return $base;
    }

    /**
     * 빌더가 상한 집계에 쓸 수 있는 형태인지 확인합니다.
     *
     * @param  mixed  $query  검사 대상
     * @return bool 사용 가능하면 true
     */
    public static function supports(mixed $query): bool
    {
        return $query instanceof EloquentBuilder
            || $query instanceof QueryBuilder
            || $query instanceof Relation
            || $query instanceof BuilderContract;
    }
}
