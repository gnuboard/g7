<?php

namespace App\Benchmark\Axes;

use App\Benchmark\Contracts\BenchmarkAxisRunner;
use App\Benchmark\DTO\BenchmarkProfile;
use App\Benchmark\DTO\BenchmarkResult;
use App\Benchmark\DTO\BenchmarkRunOptions;
use App\Benchmark\QueryCollector;
use App\Enums\BenchmarkAxis;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * 배치 축 실행기 — 배치 커맨드 소요 시간 + 피크 메모리
 *
 * 배치는 한 번에 대량을 처리하므로 "느려짐"이 시간보다 메모리로 먼저 드러납니다(청크
 * 없이 전건 로딩 → OOM). 그래서 시간과 함께 피크 메모리를 함께 잽니다.
 *
 * 다른 축과 달리 트랜잭션으로 감싸지 않습니다 — 배치 커맨드는 내부에서 커밋하거나 DDL 을
 * 실행할 수 있고, 대량 처리를 긴 트랜잭션에 담으면 락 보유 시간이 계측 자체보다 위험해집니다.
 * 대신 데이터를 변경하는 배치는 `--allow-write` 없이는 실행하지 않습니다.
 */
class BatchAxisRunner implements BenchmarkAxisRunner
{
    public function __construct(private readonly QueryCollector $collector) {}

    /**
     * {@inheritDoc}
     */
    public function axis(): BenchmarkAxis
    {
        return BenchmarkAxis::Batch;
    }

    /**
     * {@inheritDoc}
     */
    public function run(BenchmarkProfile $profile, BenchmarkRunOptions $options, ?\Closure $onProgress = null): BenchmarkResult
    {
        $notify = $onProgress ?? static fn (string $message) => null;

        if ($profile->mutates() && ! $options->allowWrite) {
            return BenchmarkResult::skipped($profile, '데이터를 변경하는 배치입니다. --allow-write 를 붙여 실행하세요.');
        }

        $command = (string) $profile->option('command');
        $arguments = (array) $profile->option('arguments', []);

        if (! array_key_exists($command, Artisan::all())) {
            return BenchmarkResult::skipped($profile, "등록되지 않은 커맨드: {$command} (해당 확장이 설치되어 있는지 확인)");
        }

        $notify("배치 실행: {$command}");

        // 피크 메모리는 프로세스 단위 누적값이라 감소하지 않는다. 실행 전 값을 함께
        // 기록해야 "이 배치가 피크를 밀어올렸는지"를 판정할 수 있다.
        $peakBefore = memory_get_peak_usage(true);
        $usageBefore = memory_get_usage(true);
        $buffer = new BufferedOutput;

        $start = microtime(true);

        try {
            $collected = $this->collector->collect(
                static fn () => Artisan::call($command, $arguments, $buffer)
            );
            $exitCode = (int) $collected['value'];
            $queries = $collected['queries'];
        } catch (\Throwable $e) {
            return BenchmarkResult::skipped($profile, '계측 실패: '.$e->getMessage());
        }

        $elapsedMs = (microtime(true) - $start) * 1000;
        $peakAfter = memory_get_peak_usage(true);
        $usageAfter = memory_get_usage(true);
        $summary = $this->collector->summarize($queries);

        $notes = [
            sprintf('실행 전 피크 %s → 실행 후 피크 %s', $this->formatBytes($peakBefore), $this->formatBytes($peakAfter)),
        ];

        if ($peakAfter <= $peakBefore) {
            $notes[] = '이 배치가 프로세스 피크를 밀어올리지 않았습니다 (실행 전 피크가 이미 더 높음).';
        }

        if ($exitCode !== 0) {
            $notes[] = "커맨드가 실패 종료했습니다 (exit={$exitCode}) — 시간·메모리는 실패 지점까지의 값입니다.";
        }

        $output = trim($buffer->fetch());

        if ($output !== '') {
            $notes[] = '커맨드 출력: '.$output;
        }

        return new BenchmarkResult(
            profile: $profile,
            headers: ['커맨드', '종료코드', '소요(ms)', '피크 메모리', '메모리 증가', '쿼리(건)'],
            rows: [[
                $command,
                (string) $exitCode,
                number_format($elapsedMs, 1),
                $this->formatBytes($peakAfter),
                $this->formatBytes(max(0, $usageAfter - $usageBefore)),
                number_format($summary['count']),
            ]],
            metrics: [
                'command' => $command,
                'arguments' => $arguments,
                'exit_code' => $exitCode,
                'elapsed_ms' => round($elapsedMs, 2),
                'peak_memory_before_bytes' => $peakBefore,
                'peak_memory_after_bytes' => $peakAfter,
                'memory_delta_bytes' => $usageAfter - $usageBefore,
                'query_count' => $summary['count'],
                'db_ms' => $summary['db_ms'],
            ],
            notes: $notes,
        );
    }

    /**
     * 바이트를 사람이 읽는 단위로 바꿉니다.
     *
     * @param  int  $bytes  바이트
     * @return string 포맷된 문자열
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return number_format($bytes / 1024 ** 3, 2).' GB';
        }

        if ($bytes >= 1024 ** 2) {
            return number_format($bytes / 1024 ** 2, 1).' MB';
        }

        return number_format($bytes / 1024, 1).' KB';
    }
}
