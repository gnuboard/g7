<?php

namespace App\Benchmark;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * 실행 쿼리 수집기 — 화면/쓰기/배치 축의 쿼리 건수·시간·N+1 후보 산출
 *
 * 목록 SELECT 는 빨라도 화면이 느린 대표적 원인이 N+1 이므로, 응답 시간만 재고 끝내면
 * 원인을 못 찾습니다. 이 수집기는 계측 구간에서 실행된 쿼리를 모아 건수·DB 시간과, 같은
 * SQL 이 반복 실행된 그룹(N+1 후보)을 함께 돌려줍니다.
 *
 * `DB::listen` 은 한 번 등록하면 해제할 수 없으므로, 리스너는 인스턴스당 한 번만 등록하고
 * 수집 여부는 플래그로 켰다 끕니다 — 계측 구간 밖의 쿼리가 섞이지 않게 합니다.
 */
class QueryCollector
{
    /**
     * 같은 SQL 이 이 횟수 이상 반복되면 N+1 후보로 본다
     */
    private const N_PLUS_ONE_THRESHOLD = 5;

    /**
     * 수집 구간 여부
     */
    private bool $collecting = false;

    /**
     * 수집된 쿼리 (sql, time)
     *
     * @var array<int, array{sql: string, time: float}>
     */
    private array $queries = [];

    public function __construct()
    {
        DB::listen(function (QueryExecuted $event) {
            if (! $this->collecting) {
                return;
            }

            $this->queries[] = ['sql' => $event->sql, 'time' => (float) $event->time];
        });
    }

    /**
     * 콜백 실행 구간의 쿼리를 수집합니다.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback  계측 대상 작업
     * @return array{value: TReturn, queries: array<int, array{sql: string, time: float}>} 반환값과 수집 결과
     */
    public function collect(\Closure $callback): array
    {
        $this->queries = [];
        $this->collecting = true;

        try {
            $value = $callback();
        } finally {
            $this->collecting = false;
        }

        return ['value' => $value, 'queries' => $this->queries];
    }

    /**
     * 수집된 쿼리를 요약합니다.
     *
     * @param  array<int, array{sql: string, time: float}>  $queries  수집 결과
     * @return array{count: int, db_ms: float, n_plus_one: array<int, array{count: int, sql: string}>} 요약
     */
    public function summarize(array $queries): array
    {
        $grouped = [];

        foreach ($queries as $query) {
            $grouped[$query['sql']] = ($grouped[$query['sql']] ?? 0) + 1;
        }

        arsort($grouped);

        $candidates = [];

        foreach ($grouped as $sql => $count) {
            if ($count < self::N_PLUS_ONE_THRESHOLD) {
                continue;
            }

            $candidates[] = ['count' => $count, 'sql' => $sql];
        }

        return [
            'count' => count($queries),
            'db_ms' => round(array_sum(array_column($queries, 'time')), 2),
            'n_plus_one' => $candidates,
        ];
    }
}
