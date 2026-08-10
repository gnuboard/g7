<?php

namespace App\Benchmark\Axes;

use App\Benchmark\Contracts\BenchmarkAxisRunner;
use App\Benchmark\DTO\BenchmarkProfile;
use App\Benchmark\DTO\BenchmarkResult;
use App\Benchmark\DTO\BenchmarkRunOptions;
use App\Benchmark\SyntheticSeeder;
use App\Enums\BenchmarkAxis;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 목록 조회 축 실행기 — 깊은 OFFSET 비용을 컬럼 폭 3축으로 계측
 *
 * 목록 조회는 OFFSET 이 커질수록 느려지는데, 그 원인이 "건너뛸 행의 넓은 컬럼까지 읽기"
 * 인지 확인하려면 같은 필터·정렬로 (1) 전체 컬럼 (2) 실제 목록 컬럼 (3) 키 컬럼만 조회한
 * 비용을 나란히 재야 합니다. 셋째 축이 지연 조인(`PaginatesWithDeferredJoin`)의 inner
 * 쿼리에 해당하므로, 이 세 값의 배수가 곧 지연 조인 적용의 기대 효과입니다.
 */
class ListAxisRunner implements BenchmarkAxisRunner
{
    /**
     * 목록 1페이지 상한 (OFFSET 스캔 비용 대비 무시할 수준이라 고정)
     */
    private const PAGE_LIMIT = 20;

    /**
     * 필터 선언에 쓸 수 있는 연산자 (닫힌 집합)
     *
     * @var array<int, string>
     */
    private const FILTER_OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'like', 'in', 'not in'];

    public function __construct(private readonly SyntheticSeeder $seeder) {}

    /**
     * {@inheritDoc}
     */
    public function axis(): BenchmarkAxis
    {
        return BenchmarkAxis::ListQuery;
    }

    /**
     * {@inheritDoc}
     */
    public function run(BenchmarkProfile $profile, BenchmarkRunOptions $options, ?\Closure $onProgress = null): BenchmarkResult
    {
        $notify = $onProgress ?? static fn (string $message) => null;
        $table = (string) $profile->option('table');

        if (! Schema::hasTable($table)) {
            return BenchmarkResult::skipped($profile, "테이블이 없습니다: {$table} (해당 확장이 설치되어 있는지 확인)");
        }

        if (($options->fresh || $options->seed > 0) && app()->environment('production')) {
            return BenchmarkResult::skipped($profile, '운영 환경에서는 시딩/비움을 사용할 수 없습니다.');
        }

        $filterError = $this->validateFilters((array) $profile->option('filters', []));

        if ($filterError !== null) {
            return BenchmarkResult::skipped($profile, $filterError);
        }

        if ($options->fresh) {
            $this->seeder->truncate($table);
            $notify("비움: {$table}");
        }

        $columns = $this->existingColumns($table, (array) $profile->option('columns', ['*']));
        $order = $this->existingOrder($table, (array) $profile->option('order', [['id', 'desc']]));

        if ($options->seed > 0) {
            $notify("시딩: {$table} ".number_format($options->seed).' 건');
            $this->seeder->seed(
                $table,
                $options->seed,
                (array) $profile->option('seed_overrides', []),
                fn (int $inserted, int $total) => $notify(sprintf('  시딩 %s / %s', number_format($inserted), number_format($total))),
                // 정렬 기준 컬럼은 nullable 이어도 채운다 — 비워두면 합성 행이 전부 동률이 되어
                // 계측이 정렬 인덱스가 아니라 tie-break 경로를 잰다.
                array_column($order, 0),
            );
        }

        $rows = [];
        $notes = [];

        foreach ($options->offsets as $offset) {
            $allMs = $this->measure($profile, ['*'], $order, $offset, $options->runs);
            $listMs = $this->measure($profile, $columns, $order, $offset, $options->runs);
            $idOnlyMs = $this->measure($profile, ['id'], $order, $offset, $options->runs);

            $rows[] = [
                'offset' => $offset,
                'all_ms' => $allMs,
                'list_ms' => $listMs,
                'id_only_ms' => $idOnlyMs,
                'ratio' => $idOnlyMs > 0 ? round($allMs / $idOnlyMs, 1) : null,
            ];

            if ($options->explain) {
                $notes[] = "EXPLAIN @ OFFSET {$offset} — 목록 컬럼";

                foreach ($this->explain($profile, $columns, $order, $offset) as $line) {
                    $notes[] = '  '.$line;
                }

                // 지연 조인의 inner 가 실제로 어떤 계획을 타는지가 인덱스 설계의 근거다
                $notes[] = "EXPLAIN @ OFFSET {$offset} — ID 만 (지연 조인 inner)";

                foreach ($this->explain($profile, ['id'], $order, $offset) as $line) {
                    $notes[] = '  '.$line;
                }
            }
        }

        return new BenchmarkResult(
            profile: $profile,
            headers: ['OFFSET', '전체 컬럼(ms)', '목록 컬럼(ms)', 'ID만(ms)', '전체÷ID'],
            rows: array_map(fn (array $row) => [
                number_format($row['offset']),
                number_format($row['all_ms'], 1),
                number_format($row['list_ms'], 1),
                number_format($row['id_only_ms'], 1),
                $row['ratio'] !== null ? $row['ratio'].'×' : '-',
            ], $rows),
            metrics: [
                'table' => $table,
                'columns' => $columns,
                'runs' => $options->runs,
                'offsets' => $rows,
            ],
            notes: $notes,
        );
    }

    /**
     * 스키마에 실제로 존재하는 컬럼만 남깁니다.
     *
     * `['*']` 는 "목록이 전 컬럼을 그대로 노출한다" 는 선언입니다. 응답 계약상 넓은 컬럼을
     * 뺄 수 없는 목록(활동 로그의 changes, 알림 발송 이력의 body 등)이 여기 해당하며, 이
     * 경우 계측의 비교 축은 select * vs select id 가 됩니다. 확장 버전에 따라 컬럼 구성이
     * 달라도 계측이 죽지 않도록 스키마에 없는 컬럼은 걸러냅니다.
     *
     * @param  string  $table  테이블명
     * @param  array<int, string>  $columns  후보 컬럼
     * @return array<int, string> 존재하는 컬럼 목록
     */
    private function existingColumns(string $table, array $columns): array
    {
        if ($columns === ['*']) {
            return Schema::getColumnListing($table);
        }

        $existing = array_values(array_filter(
            $columns,
            fn (mixed $column) => is_string($column) && Schema::hasColumn($table, $column)
        ));

        return $existing === [] ? ['id'] : $existing;
    }

    /**
     * 스키마에 존재하는 정렬 컬럼만 남깁니다.
     *
     * @param  string  $table  테이블명
     * @param  array<int, array{0: string, 1: string}>  $order  후보 정렬
     * @return array<int, array{0: string, 1: string}> 적용 가능한 정렬 목록
     */
    private function existingOrder(string $table, array $order): array
    {
        $existing = array_values(array_filter(
            $order,
            fn (mixed $spec) => is_array($spec) && isset($spec[0]) && Schema::hasColumn($table, (string) $spec[0])
        ));

        return $existing === [] ? [['id', 'desc']] : $existing;
    }

    /**
     * 한 조합을 여러 번 실행해 중앙값(ms)을 돌려줍니다.
     *
     * `DB::enableQueryLog()` 는 오버헤드와 메모리 누적이 있어 쓰지 않고 직접 시간을 잽니다.
     *
     * @param  BenchmarkProfile  $profile  프로파일
     * @param  array<int, string>  $columns  select 컬럼
     * @param  array<int, array{0: string, 1: string}>  $order  정렬
     * @param  int  $offset  OFFSET
     * @param  int  $runs  측정 횟수
     * @return float 중앙값 (밀리초)
     */
    private function measure(BenchmarkProfile $profile, array $columns, array $order, int $offset, int $runs): float
    {
        // 첫 회는 캐시 워밍 성격이라 버린다
        $this->buildQuery($profile, $columns, $order, $offset)->get();

        $samples = [];

        for ($i = 0; $i < max(1, $runs); $i++) {
            $start = microtime(true);
            $this->buildQuery($profile, $columns, $order, $offset)->get();
            $samples[] = (microtime(true) - $start) * 1000;
        }

        sort($samples);

        return $samples[intdiv(count($samples), 2)];
    }

    /**
     * 계측 대상 쿼리를 조립합니다.
     *
     * @param  BenchmarkProfile  $profile  프로파일
     * @param  array<int, string>  $columns  select 컬럼
     * @param  array<int, array{0: string, 1: string}>  $order  정렬
     * @param  int  $offset  OFFSET
     * @return Builder 조립된 쿼리
     */
    private function buildQuery(BenchmarkProfile $profile, array $columns, array $order, int $offset): Builder
    {
        $table = (string) $profile->option('table');
        $query = DB::table($table)->select($columns);

        if ($profile->option('soft_delete', false) && Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $this->applyFilters($query, $table, (array) $profile->option('filters', []));

        foreach ($order as $spec) {
            $query->orderBy($spec[0], $spec[1] ?? 'asc');
        }

        return $query->offset($offset)->limit(self::PAGE_LIMIT);
    }

    /**
     * 선언된 필터를 계측 쿼리에 적용합니다.
     *
     * 값이 `[연산자, 값]` 형태면 그 연산자로, 그 밖에는 등가 비교로 해석합니다. 등가 비교만
     * 지원하면 화면이 실제로 거는 필터를 선언할 방법이 없는 목록이 생깁니다 — 주문 목록은
     * 상태 미지정 시 임시 주문 상태를 `NOT IN` 으로 제외하므로, 그것을 선언하지 못하면
     * 계측이 화면과 다른 인덱스를 타게 됩니다.
     *
     * 연산자는 닫힌 집합으로 해석합니다. 임의 문자열을 그대로 넘기면 선언 오타가 조용히
     * 통과하거나 의도치 않은 SQL 이 조립됩니다.
     *
     * @param  Builder  $query  계측 쿼리
     * @param  string  $table  대상 테이블
     * @param  array<string, mixed>  $filters  선언된 필터
     */
    private function applyFilters(Builder $query, string $table, array $filters): void
    {
        foreach ($filters as $column => $declared) {
            if (! Schema::hasColumn($table, (string) $column)) {
                continue;
            }

            // [연산자, 값] 형태가 아니면 등가 비교 (배열 값을 IN 으로 오해석하지 않도록 형태로 판정)
            if (! is_array($declared) || count($declared) !== 2 || ! is_string($declared[0])) {
                $query->where($column, $declared);

                continue;
            }

            [$operator, $value] = $declared;

            match (strtolower($operator)) {
                '=' => $query->where($column, '=', $value),
                '!=', '<>' => $query->where($column, '!=', $value),
                '<' => $query->where($column, '<', $value),
                '<=' => $query->where($column, '<=', $value),
                '>' => $query->where($column, '>', $value),
                '>=' => $query->where($column, '>=', $value),
                'like' => $query->where($column, 'like', $value),
                'in' => $query->whereIn($column, (array) $value),
                'not in' => $query->whereNotIn($column, (array) $value),
                // 도달 불가 — run() 이 실행 전에 연산자를 검증해 거부한다
                default => null,
            };
        }
    }

    /**
     * 선언된 필터의 연산자를 실행 전에 검증합니다.
     *
     * 알 수 없는 연산자를 조용히 무시하면 그 필터가 빠진 채로 측정되어, 화면과 다른 것을
     * 재면서도 정상 측정으로 보고됩니다. 실행 전에 사유와 함께 거부합니다.
     *
     * @param  array<string, mixed>  $filters  선언된 필터
     * @return string|null 실패 사유 (문제 없으면 null)
     */
    private function validateFilters(array $filters): ?string
    {
        foreach ($filters as $column => $declared) {
            if (! is_array($declared) || count($declared) !== 2 || ! is_string($declared[0])) {
                continue;
            }

            if (! in_array(strtolower($declared[0]), self::FILTER_OPERATORS, true)) {
                return sprintf(
                    '필터 %s 의 연산자를 알 수 없습니다: %s (허용: %s)',
                    $column,
                    $declared[0],
                    implode(', ', self::FILTER_OPERATORS)
                );
            }
        }

        return null;
    }

    /**
     * 실행 계획을 수집합니다.
     *
     * @param  BenchmarkProfile  $profile  프로파일
     * @param  array<int, string>  $columns  select 컬럼
     * @param  array<int, array{0: string, 1: string}>  $order  정렬
     * @param  int  $offset  OFFSET
     * @return array<int, string> 실행 계획 요약 줄 목록
     */
    private function explain(BenchmarkProfile $profile, array $columns, array $order, int $offset): array
    {
        $query = $this->buildQuery($profile, $columns, $order, $offset);

        try {
            $plan = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings());
        } catch (\Throwable $e) {
            return ['실행 계획 수집 실패: '.$e->getMessage()];
        }

        return array_map(function ($row) {
            $row = (array) $row;

            return sprintf(
                'type=%s key=%s rows=%s filtered=%s extra=%s',
                $row['type'] ?? '-',
                $row['key'] ?? '-',
                $row['rows'] ?? '-',
                $row['filtered'] ?? '-',
                $row['Extra'] ?? '-'
            );
        }, $plan);
    }
}
