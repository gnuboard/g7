<?php

namespace App\Support;

/**
 * 레이아웃 `data_sources` 의 id 중복 규칙.
 *
 * 한 레이아웃 안에서 같은 id 의 데이터 소스를 상호 배타 조건(`if`·`conditions`)으로 여러 판 선언할 수 있다 — 엔진은 조건을
 * 만족하는 첫 판만 쓴다(`DataSourceManager.filterByCondition`, 공개 문서 `docs/frontend/data-sources-advanced.md`
 * 「조건부 데이터 소스 로딩」). 같은 id 묶음에 조건 없는 판이 섞이면 어느 판이 쓰일지 조건으로 정해지지 않으므로 중복으로
 * 거부한다(같은 문서의 DON'T). 부모↔자식 레이아웃 사이의 id 중복 금지(상속)는 이 규칙과 별개다.
 *
 * 설치 검증·레이아웃 행 갱신·편집기 저장 검증이 이 한 규칙을 쓴다(7.1.1 출시 전 — 종전에는 조건과 무관하게 거부해
 * 문서대로 쓴 레이아웃을 설치할 수 없었다).
 */
final class LayoutDataSourceIds
{
    /**
     * 데이터 소스 판에 조건(`if` 또는 `conditions`)이 있는지 판정합니다.
     *
     * @param  mixed  $dataSource  data_sources 항목
     * @return bool 비어 있지 않은 `if` 나 `conditions` 가 있으면 true
     */
    public static function isConditional(mixed $dataSource): bool
    {
        if (! is_array($dataSource)) {
            return false;
        }

        foreach (['if', 'conditions'] as $key) {
            $value = $dataSource[$key] ?? null;
            if ($value !== null && $value !== '' && $value !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * 허용되지 않는 중복 id 를 돌려줍니다 — 같은 id 가 둘 이상이고 그중 조건 없는 판이 있는 id.
     *
     * @param  array<int, mixed>  $dataSources  data_sources 목록
     * @return array<int, string> 중복 id (선언 순서, 중복 없음)
     */
    public static function conflictingDuplicates(array $dataSources): array
    {
        $count = [];
        $unconditional = [];

        foreach ($dataSources as $dataSource) {
            if (! is_array($dataSource) || ! isset($dataSource['id']) || ! is_scalar($dataSource['id'])) {
                continue;
            }

            $id = (string) $dataSource['id'];
            $count[$id] = ($count[$id] ?? 0) + 1;
            if (! self::isConditional($dataSource)) {
                $unconditional[$id] = true;
            }
        }

        $conflicts = [];
        foreach ($count as $id => $n) {
            if ($n > 1 && isset($unconditional[$id])) {
                $conflicts[] = (string) $id;
            }
        }

        return $conflicts;
    }

    /**
     * 목록에 같은 id 가 둘 이상 있는지(조건과 무관하게) 판정합니다 — id 를 키로 쓰는 처리가 판을 합치지 않게 할 때 쓴다.
     *
     * @param  array<int, mixed>  $dataSources  data_sources 목록
     * @return bool 같은 id 가 있으면 true
     */
    public static function hasRepeatedIds(array $dataSources): bool
    {
        $seen = [];

        foreach ($dataSources as $dataSource) {
            if (! is_array($dataSource) || ! isset($dataSource['id']) || ! is_scalar($dataSource['id'])) {
                continue;
            }

            $id = (string) $dataSource['id'];
            if (isset($seen[$id])) {
                return true;
            }
            $seen[$id] = true;
        }

        return false;
    }
}
