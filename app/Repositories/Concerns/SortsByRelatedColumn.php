<?php

namespace App\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 관계 테이블 컬럼 기준 정렬 Trait
 *
 * 목록의 정렬 기준이 원 테이블에 없고 관계 테이블에 있는 경우가 있다.
 * (예: 주문 목록의 "발송일" — `shipped_at` 은 주문이 아니라 배송 테이블에 있고, 한 주문에
 * 배송 행이 여러 건일 수 있다.)
 *
 * 이때 화면이 정렬 옵션을 제공하는데 백엔드가 그 컬럼을 모르면 422 로 조용히 실패하거나,
 * 임시로 조인을 붙이면 다음 두 문제가 생긴다.
 *
 *   1. `JOIN` + `GROUP BY` 로 집계하면 1:N 조인이 원 행을 부풀려 총 건수와 페이지 경계가 깨진다
 *   2. inner 쿼리(지연 조인)에 집계를 붙이면 건너뛸 행 전체에 대해 집계가 돌아 OFFSET 이
 *      깊어질수록 비용이 그대로 누적된다 — 지연 조인을 도입한 이유가 사라진다
 *
 * 본 Trait 은 **상관 서브쿼리 정렬**로 두 문제를 함께 피한다. 원 행 수를 바꾸지 않으므로
 * 총 건수·페이지 경계가 그대로 유지되고, `(외래키, 정렬컬럼)` 복합 인덱스가 있으면 각 행당
 * 인덱스 첫 항목 한 건만 읽는다.
 *
 * 방향과 집계를 분리해 지정하지 않는 이유: 서브쿼리를 정렬 방향과 같은 방향으로 정렬해
 * 한 건만 취하면 `desc` 는 자연히 최댓값(가장 늦은 발송), `asc` 는 최솟값(가장 이른 발송)이
 * 된다. 운영자가 "최근 발송순" 을 골랐을 때 기대하는 값과 일치한다.
 *
 * 사용 계약:
 *   - 정렬 키 → 관계 스펙 맵을 리포지토리 상수(`RELATED_SORTABLE_COLUMNS`)로 선언한다
 *   - 그 키들은 FormRequest 의 `sort_by` 허용 목록에도 함께 들어가야 한다
 *     (게이트 ⊆ 저장소 불변식 — `SortWhitelistGateParityTest` 가 검사한다)
 *   - 정렬 대상 관계 테이블에는 `(외래키, 정렬컬럼)` 복합 인덱스를 반드시 둔다
 *
 * @see ResolvesSortSpec 원 테이블 컬럼 정렬 해석 (본 Trait 과 함께 사용)
 * @see PaginatesWithDeferredJoin 서브쿼리 정렬 스펙을 그대로 받는다
 */
trait SortsByRelatedColumn
{
    /**
     * 관계 테이블 컬럼 정렬을 포함해 정렬 스펙을 해석합니다.
     *
     * 요청 정렬 키가 `$relatedMap` 에 있으면 상관 서브쿼리 스펙을, 그렇지 않으면
     * `ResolvesSortSpec::resolveSortSpec()` 의 일반 컬럼 스펙을 돌려준다.
     *
     * @param  array  $filters  요청 유래 필터 배열
     * @param  array<int, string>  $allowedColumns  원 테이블 허용 정렬 컬럼
     * @param  array<string, array{model: class-string<Model>, foreign_key: string, column: string}>  $relatedMap  정렬 키 → 관계 스펙
     * @param  Model  $parent  정렬 대상 원 모델 (상관 조건의 좌변)
     * @param  string  $defaultColumn  기본 정렬 컬럼
     * @param  string  $defaultDirection  기본 정렬 방향
     * @param  string  $columnKey  정렬 컬럼이 담긴 필터 키
     * @param  string  $directionKey  정렬 방향이 담긴 필터 키
     * @return array<int, array{column: string|Builder, direction: string}> 정렬 스펙 배열
     */
    protected function resolveSortSpecWithRelated(
        array $filters,
        array $allowedColumns,
        array $relatedMap,
        Model $parent,
        string $defaultColumn,
        string $defaultDirection = 'desc',
        string $columnKey = 'sort_by',
        string $directionKey = 'sort_order',
    ): array {
        $requested = $filters[$columnKey] ?? null;
        $requested = is_string($requested) ? trim($requested) : '';

        if ($requested === '' || ! isset($relatedMap[$requested])) {
            return $this->resolveSortSpec(
                $filters,
                $allowedColumns,
                $defaultColumn,
                $defaultDirection,
                columnKey: $columnKey,
                directionKey: $directionKey,
            );
        }

        $direction = $this->normalizeSortDirection($filters[$directionKey] ?? null, $defaultDirection);

        return [[
            'column' => $this->relatedSortSubquery($relatedMap[$requested], $parent, $direction),
            'direction' => $direction,
        ]];
    }

    /**
     * 관계 테이블에서 정렬 기준값 한 건을 뽑는 상관 서브쿼리를 만듭니다.
     *
     * 정렬 방향과 같은 방향으로 관계 행을 정렬해 첫 건만 취한다 —
     * `desc` 면 가장 늦은 값, `asc` 면 가장 이른 값이 기준이 된다.
     *
     * @param  array{model: class-string<Model>, foreign_key: string, column: string}  $spec  관계 스펙
     * @param  Model  $parent  원 모델
     * @param  string  $direction  정렬 방향 (asc|desc)
     * @return Builder 상관 서브쿼리
     */
    protected function relatedSortSubquery(array $spec, Model $parent, string $direction): Builder
    {
        /** @var Model $related */
        $related = new $spec['model'];

        return $related->newQuery()
            ->select($related->qualifyColumn($spec['column']))
            ->whereColumn($related->qualifyColumn($spec['foreign_key']), $parent->qualifyColumn($parent->getKeyName()))
            ->orderBy($related->qualifyColumn($spec['column']), $direction)
            ->limit(1);
    }
}
