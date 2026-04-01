<?php

namespace App\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * 다중 검색 필터 기능을 제공하는 Trait
 *
 * Repository에서 다중 검색 조건을 처리할 때 사용합니다.
 * 지원 연산자: like(기본), eq, starts_with, ends_with
 */
trait HasMultipleSearchFilters
{
    /**
     * 다중 검색 조건을 쿼리에 적용합니다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  array  $searchFilters  검색 조건 배열 [['field' => 'name', 'value' => '홍길동', 'operator' => 'like'], ...]
     * @param  array  $allowedFields  허용된 검색 필드 목록
     */
    protected function applyMultipleSearchFilters(Builder $query, array $searchFilters, array $allowedFields): void
    {
        if (empty($searchFilters)) {
            return;
        }

        $query->where(function ($q) use ($searchFilters, $allowedFields) {
            foreach ($searchFilters as $filter) {
                $this->applySearchFilter($q, $filter, $allowedFields);
            }
        });
    }

    /**
     * 개별 검색 필터를 쿼리에 적용합니다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  array  $filter  검색 필터 ['field' => string, 'value' => string, 'operator' => string]
     * @param  array  $allowedFields  허용된 검색 필드 목록
     */
    protected function applySearchFilter(Builder $query, array $filter, array $allowedFields): void
    {
        $field = $filter['field'] ?? null;
        $value = $filter['value'] ?? null;
        $operator = $filter['operator'] ?? 'like';

        // 필드와 값이 유효한지 확인
        if (! $field || ! $value) {
            return;
        }

        // 'all' 필드인 경우: 모든 허용 필드를 OR 조건으로 검색
        if ($field === 'all') {
            $this->applyOrSearchAcrossFields($query, $value, $allowedFields);

            return;
        }

        // 특정 필드 검색: 허용된 필드인지 확인
        if (! in_array($field, $allowedFields)) {
            return;
        }

        // 연산자에 따른 검색 조건 적용 (AND 조건으로 연결)
        match ($operator) {
            'eq' => $query->where($field, '=', $value),
            'starts_with' => $query->where($field, 'like', "{$value}%"),
            'ends_with' => $query->where($field, 'like', "%{$value}"),
            default => $query->where($field, 'like', "%{$value}%"),
        };
    }

    /**
     * 단일 검색어로 여러 필드를 OR 조건으로 검색합니다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  string  $searchTerm  검색어
     * @param  array  $searchFields  검색할 필드 목록
     */
    protected function applyOrSearchAcrossFields(Builder $query, string $searchTerm, array $searchFields): void
    {
        if (empty($searchTerm) || empty($searchFields)) {
            return;
        }

        $query->where(function ($q) use ($searchTerm, $searchFields) {
            foreach ($searchFields as $index => $field) {
                if ($index === 0) {
                    $q->where($field, 'like', "%{$searchTerm}%");
                } else {
                    $q->orWhere($field, 'like', "%{$searchTerm}%");
                }
            }
        });
    }
}
