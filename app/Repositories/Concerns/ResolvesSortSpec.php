<?php

namespace App\Repositories\Concerns;

use BackedEnum;

/**
 * 요청 유래 정렬 조건을 닫힌 집합으로 해석하는 Trait
 *
 * 요청 파라미터(`sort_by`/`sort_order` 등)를 그대로 `orderBy()` 에 넘기면 두 가지 문제가 생긴다.
 *
 *   1. 존재하지 않는 컬럼이 오면 SQL 오류가 나고, 그 오류 응답이 스키마 정보를 노출한다
 *   2. 인덱스가 없는 넓은 컬럼으로 정렬을 강제할 수 있어 DoS 표면이 된다
 *
 * (Laravel 은 컬럼명을 백틱으로 감싸므로 고전적 SQL 인젝션은 성립하지 않는다 — 위 두 가지가 실제 위험이다.)
 *
 * 본 Trait 은 허용 컬럼 화이트리스트로 정렬 조건을 해석하고, 지연 조인 페이지네이션이 요구하는
 * 정렬 스펙 배열 형태로 돌려준다.
 *
 * 정렬 선택지가 4개 이하로 고정된 곳은 `match` 표현식으로 이미 닫혀 있으므로 본 Trait 이 불필요하다.
 * 요청 값이 동적 컬럼으로 흘러 들어가는 곳에만 사용한다.
 *
 * @see PaginatesWithDeferredJoin 해석 결과를 그대로 `$sort` 인자로 받는다
 */
trait ResolvesSortSpec
{
    /**
     * 요청 필터에서 정렬 스펙을 해석합니다.
     *
     * @param  array  $filters  요청 유래 필터 배열
     * @param  array<int, string>  $allowedColumns  허용 정렬 컬럼 화이트리스트
     * @param  string  $defaultColumn  화이트리스트에 없거나 미전송일 때 사용할 컬럼
     * @param  string  $defaultDirection  기본 정렬 방향 (asc|desc)
     * @param  array<string, string>  $columnAliases  요청 값 → 실제 컬럼 매핑 (예: ['author' => 'author_name'])
     * @param  string  $columnKey  정렬 컬럼이 담긴 필터 키
     * @param  string  $directionKey  정렬 방향이 담긴 필터 키
     * @return array<int, array{column: string, direction: string}> 정렬 스펙 배열
     */
    protected function resolveSortSpec(
        array $filters,
        array $allowedColumns,
        string $defaultColumn,
        string $defaultDirection = 'desc',
        array $columnAliases = [],
        string $columnKey = 'sort_by',
        string $directionKey = 'sort_order',
    ): array {
        $column = $this->normalizeSortValue($filters[$columnKey] ?? null);
        $direction = $this->normalizeSortValue($filters[$directionKey] ?? null);

        if ($column !== null && isset($columnAliases[$column])) {
            $column = $columnAliases[$column];
        }

        if ($column === null || ! in_array($column, $allowedColumns, true)) {
            $column = $defaultColumn;
        }

        return [[
            'column' => $column,
            'direction' => $this->normalizeSortDirection($direction, $defaultDirection),
        ]];
    }

    /**
     * 정렬 방향 문자열을 asc/desc 로 정규화합니다.
     *
     * @param  string|null  $direction  요청 값
     * @param  string  $default  허용 값이 아닐 때 사용할 기본 방향
     * @return string asc 또는 desc
     */
    protected function normalizeSortDirection(?string $direction, string $default = 'desc'): string
    {
        $normalized = strtolower((string) $direction);

        if (in_array($normalized, ['asc', 'desc'], true)) {
            return $normalized;
        }

        $fallback = strtolower($default);

        return in_array($fallback, ['asc', 'desc'], true) ? $fallback : 'desc';
    }

    /**
     * 정렬 관련 요청 값을 문자열로 정규화합니다. (Enum/빈 문자열 흡수)
     *
     * @param  mixed  $value  요청 값 (문자열 또는 BackedEnum)
     * @return string|null 정규화된 문자열 (값이 없으면 null)
     */
    private function normalizeSortValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
