<?php

namespace Tests\Concerns;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * 실행된 쿼리 수를 세는 테스트 트레이트
 *
 * N+1 은 "행 수가 늘면 쿼리도 는다" 는 형태로 나타난다. 특정 시점의 쿼리 수를 숫자로
 * 박아 두면 관계 하나만 추가해도 테스트가 깨져 유지되지 않고, 반대로 상한만 두면
 * 행 수에 비례해 늘어나는 진짜 회귀를 놓친다.
 *
 * 그래서 이 트레이트는 **같은 화면을 행 수만 바꿔 두 번 재고 그 차이를 본다**.
 * 행을 두 배로 늘려도 쿼리 수가 그대로면 조회 구조가 행 수와 무관하다는 뜻이다.
 */
trait CountsQueries
{
    /**
     * 클로저를 실행하며 발생한 쿼리를 모두 기록합니다.
     *
     * @param  \Closure  $callback  측정 대상
     * @return array<int, string> 실행된 SQL 목록
     */
    protected function captureQueries(\Closure $callback): array
    {
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = $query->sql;
        });

        try {
            $callback();
        } finally {
            // 리스너는 커넥션에 누적되므로, 다음 측정이 이전 기록을 물려받지 않도록 끊는다.
            DB::getEventDispatcher()?->forget(QueryExecuted::class);
        }

        return $queries;
    }

    /**
     * 클로저를 실행하며 발생한 쿼리 수를 셉니다.
     *
     * @param  \Closure  $callback  측정 대상
     * @return int 실행된 쿼리 수
     */
    protected function countQueries(\Closure $callback): int
    {
        return count($this->captureQueries($callback));
    }

    /**
     * 데이터 규모를 바꿔도 쿼리 수가 늘지 않는지 단언합니다.
     *
     * 행을 늘리는 일(`$grow`)은 측정 밖에서 수행하고, 측정은 조회(`$measure`)만 감쌉니다.
     *
     * @param  \Closure  $measure  측정할 조회 (쿼리 수를 재는 대상)
     * @param  \Closure  $grow  데이터를 늘리는 작업 (측정에 포함되지 않음)
     * @param  string  $context  실패 메시지에 붙일 설명
     */
    protected function assertQueryCountStableAsDataGrows(\Closure $measure, \Closure $grow, string $context = ''): void
    {
        $before = $this->countQueries($measure);

        $grow();

        $after = $this->countQueries($measure);

        $label = $context !== '' ? $context.': ' : '';

        $this->assertLessThanOrEqual(
            $before,
            $after,
            $label."행 수를 늘렸더니 쿼리 수가 {$before} → {$after} 로 늘었다. "
            .'조회가 행마다 쿼리를 내고 있다(N+1) — 관계를 eager load 하거나 일괄 조회로 바꾼다'
        );
    }
}
