<?php

namespace App\Benchmark\Axes;

use App\Benchmark\BenchmarkIdentity;
use App\Benchmark\Contracts\BenchmarkAxisRunner;
use App\Benchmark\DTO\BenchmarkProfile;
use App\Benchmark\DTO\BenchmarkResult;
use App\Benchmark\DTO\BenchmarkRunOptions;
use App\Benchmark\QueryCollector;
use App\Enums\BenchmarkAxis;

/**
 * 쓰기 축 실행기 — 저장 경로 1회 소요 시간
 *
 * 목록 조회와 달리 저장 경로는 커맨드가 스스로 조립할 수 없습니다(주문 생성 하나에 재고·
 * 쿠폰·마일리지·알림이 얽힘). 그래서 실행 자체는 소유 확장이 선언한 콜백에 맡기고, 이
 * 실행기는 시간·쿼리 건수 계측과 안전장치만 담당합니다.
 *
 * 콜백은 `'Fqcn'`(invokable) 또는 `['Fqcn', 'method']` 형식만 허용합니다 — 코어 선언은
 * `config/benchmark.php` 에 있고 이 파일은 `config:cache` 대상이라 클로저를 담을 수 없기
 * 때문입니다. 확장 선언도 같은 스키마를 쓰므로 형식을 통일합니다.
 *
 * 선언 필드는 세 개입니다 — `prepare`(계측 제외 선행 준비, 회차마다 실행), `callback`
 * (계측 대상, prepare 반환값을 인자로 받음), `cleanup`(트랜잭션이 되돌리지 못하는 잔여물 정리).
 *
 * 계측으로 생긴 행은 롤백되는 트랜잭션으로 되돌립니다. 트랜잭션이 되돌리지 못하는 것
 * (파일·캐시·외부 호출)은 프로파일이 선언한 `cleanup` 콜백이 정리합니다.
 */
class WriteAxisRunner implements BenchmarkAxisRunner
{
    public function __construct(
        private readonly BenchmarkIdentity $identity,
        private readonly QueryCollector $collector,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function axis(): BenchmarkAxis
    {
        return BenchmarkAxis::Write;
    }

    /**
     * {@inheritDoc}
     */
    public function run(BenchmarkProfile $profile, BenchmarkRunOptions $options, ?\Closure $onProgress = null): BenchmarkResult
    {
        $notify = $onProgress ?? static fn (string $message) => null;

        if (! $options->allowWrite) {
            return BenchmarkResult::skipped($profile, '쓰기 축입니다. --allow-write 를 붙여 실행하세요.');
        }

        $resolved = [];

        foreach (['callback', 'prepare', 'cleanup'] as $field) {
            if ($field !== 'callback' && $profile->option($field) === null) {
                $resolved[$field] = null;

                continue;
            }

            [$callable, $error] = $this->resolveCallable($profile->option($field), $profile, $field);

            if ($error !== null) {
                return BenchmarkResult::skipped($profile, $error);
            }

            $resolved[$field] = $callable;
        }

        $callback = $resolved['callback'];
        $prepare = $resolved['prepare'];
        $cleanup = $resolved['cleanup'];

        $runs = max(1, $options->runs);

        try {
            $measured = $this->identity->withRolledBackTransaction(
                fn () => $this->measure($callback, $prepare, $runs, $onProgress)
            );
        } catch (\Throwable $e) {
            return BenchmarkResult::skipped($profile, '계측 실패: '.$e->getMessage());
        } finally {
            if ($cleanup !== null) {
                // 트랜잭션 롤백으로 되돌지 않는 잔여물(파일·캐시)을 정리한다.
                // 정리 실패가 계측 결과를 삼키면 안 되므로 사유만 알린다.
                try {
                    $cleanup();
                } catch (\Throwable $e) {
                    $notify('cleanup 실패: '.$e->getMessage());
                }
            }
        }

        $summary = $this->collector->summarize($measured['queries']);

        return new BenchmarkResult(
            profile: $profile,
            headers: ['첫 회(ms)', '중앙값(ms)', '회차', '쿼리(건)', 'DB(ms)'],
            rows: [[
                number_format($measured['first_ms'], 1),
                number_format($measured['median_ms'], 1),
                (string) $runs,
                number_format($summary['count']),
                number_format($summary['db_ms'], 1),
            ]],
            metrics: [
                'first_ms' => $measured['first_ms'],
                'median_ms' => $measured['median_ms'],
                'runs' => $runs,
                'samples_ms' => $measured['samples'],
                'query_count' => $summary['count'],
                'db_ms' => $summary['db_ms'],
                'n_plus_one' => $summary['n_plus_one'],
            ],
            notes: array_merge(
                ['계측으로 생긴 행은 롤백했습니다.'],
                array_map(
                    fn (array $candidate) => sprintf('반복 쿼리 %d회: %s', $candidate['count'], $candidate['sql']),
                    $summary['n_plus_one']
                )
            ),
        );
    }

    /**
     * 선언된 콜백을 호출 가능한 형태로 해석합니다.
     *
     * 예외를 던지지 않고 사유 문자열을 함께 돌려주는 이유는, 이 축의 실패 계약이
     * `BenchmarkResult::skipped` 이기 때문입니다 — 던지고 곧바로 잡는 우회로를 만들지 않습니다.
     *
     * @param  mixed  $declared  선언값
     * @param  BenchmarkProfile  $profile  프로파일 (오류 메시지용)
     * @param  string  $field  선언 필드명 (오류 메시지용)
     * @return array{0: callable|null, 1: string|null} [해석된 콜백, 실패 사유]
     */
    private function resolveCallable(mixed $declared, BenchmarkProfile $profile, string $field): array
    {
        $where = $profile->qualifiedKey().' 의 '.$field;

        if ($declared instanceof \Closure) {
            return [null, "{$where}: 클로저는 쓸 수 없습니다 (config:cache 불가). 'Fqcn' 또는 ['Fqcn', 'method'] 형식으로 선언하세요."];
        }

        if (is_string($declared)) {
            if (! class_exists($declared)) {
                return [null, "{$where}: 클래스를 찾을 수 없습니다 — {$declared}"];
            }

            $instance = app($declared);

            if (! is_callable($instance)) {
                return [null, "{$where}: {$declared} 에 __invoke() 가 없습니다."];
            }

            return [$instance, null];
        }

        if (is_array($declared) && count($declared) === 2 && is_string($declared[0]) && is_string($declared[1])) {
            [$class, $method] = $declared;

            if (! class_exists($class)) {
                return [null, "{$where}: 클래스를 찾을 수 없습니다 — {$class}"];
            }

            $instance = app($class);

            if (! method_exists($instance, $method)) {
                return [null, "{$where}: {$class}::{$method}() 가 없습니다."];
            }

            return [[$instance, $method], null];
        }

        return [null, "{$where}: 'Fqcn' 또는 ['Fqcn', 'method'] 형식이어야 합니다."];
    }

    /**
     * 콜백을 반복 실행해 첫 회 시간과 중앙값을 냅니다.
     *
     * 첫 회를 버리지 않고 따로 보고하는 이유는, 저장 경로에서는 첫 회가 포함하는 비용
     * (클래스 로딩, 설정 해석, 관계 초기 조회)이 실사용에서도 발생하기 때문입니다.
     * 쿼리 요약은 마지막 회차 기준입니다 — 첫 회는 위 초기화 쿼리가 섞입니다.
     *
     * `prepare` 는 계측 구간 **밖**에서 회차마다 실행합니다. 저장 경로에는 선행 상태가
     * 필요한 경우가 많고(주문 생성에는 임시 주문이 필요하고 임시 주문은 1회만 전환됨),
     * 그 준비 비용이 측정값에 섞이면 재려던 것을 재지 못하게 됩니다.
     *
     * @param  callable  $callback  저장 경로 콜백 (prepare 반환값을 인자로 받음)
     * @param  callable|null  $prepare  회차별 선행 준비 콜백 (계측 제외, 회차 번호를 인자로 받음)
     * @param  int  $runs  측정 횟수
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return array{first_ms: float, median_ms: float, samples: array<int, float>, queries: array<int, array{sql: string, time: float}>} 계측 결과
     */
    private function measure(callable $callback, ?callable $prepare, int $runs, ?\Closure $onProgress): array
    {
        $notify = $onProgress ?? static fn (string $message) => null;
        $samples = [];
        $queries = [];

        for ($i = 1; $i <= $runs; $i++) {
            $notify("쓰기 계측 {$i} / {$runs}");

            $context = $prepare !== null ? $prepare($i) : null;

            $start = microtime(true);
            $collected = $this->collector->collect(static function () use ($callback, $context) {
                $callback($context);
            });
            $samples[] = (microtime(true) - $start) * 1000;
            $queries = $collected['queries'];
        }

        $sorted = $samples;
        sort($sorted);

        return [
            'first_ms' => $samples[0],
            'median_ms' => $sorted[intdiv(count($sorted), 2)],
            'samples' => $samples,
            'queries' => $queries,
        ];
    }
}
