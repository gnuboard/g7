<?php

namespace App\Benchmark\Axes;

use App\Benchmark\BenchmarkIdentity;
use App\Benchmark\BootProbeExecutor;
use App\Benchmark\Contracts\BenchmarkAxisRunner;
use App\Benchmark\DTO\BenchmarkProfile;
use App\Benchmark\DTO\BenchmarkResult;
use App\Benchmark\DTO\BenchmarkRunOptions;
use App\Enums\BenchmarkAxis;

/**
 * 요청 부팅 축 실행기 — 요청 1건을 새 프로세스에서 처음부터 처리하는 비용
 *
 * 화면 축은 이미 부팅된 앱 안에서 요청을 처리하므로, 요청마다 반복되는 확장 탐색·훅 등록·
 * 설정 미러 같은 부팅 비용을 구조적으로 잴 수 없습니다. 이 축은 회차마다 자식 PHP 프로세스
 * (`app/Benchmark/Probes/boot_probe.php`)를 새로 띄워 오토로드부터 응답까지 처리시키고,
 * 시간 중앙값과 함께 **같은 요청 안에서 반복되는 일의 개수**(DB 쿼리·캐시 조회·리스너·훅
 * 콜백 수)를 모읍니다. 부팅 개선은 따뜻한 환경에서 시간 차이가 작게 보이므로 판정은 개수로 합니다.
 *
 * 자식은 `APP_RUNNING_IN_CONSOLE=false` 로 웹 요청 분기를 타고, 파일을 쓰지 않습니다.
 * 인증이 필요한 프로파일(`permissions` 선언)은 자식 프로세스가 볼 수 있도록 토큰을 커밋해
 * 발급하고, 계측이 끝나면 지웁니다(`BenchmarkIdentity::discard`).
 */
class BootAxisRunner implements BenchmarkAxisRunner
{
    /**
     * 프로파일이 회차를 선언하지 않았을 때의 기본 회차
     */
    private const DEFAULT_RUNS = 20;

    /**
     * 자식 출력에서 결과 줄을 찾는 접두어
     */
    private const OUTPUT_PREFIX = 'G7_BOOT_PROBE ';

    /**
     * 리포트에 싣는 반복 항목 상위 개수
     */
    private const TOP_LIMIT = 10;

    /**
     * 마지막 자식 프로세스 실패 사유 (건너뜀 사유 기재용)
     */
    private string $lastFailure = '';

    /**
     * @param  BenchmarkIdentity  $identity  계측 인증 주체 해석기 (인증 프로파일의 토큰 발급·정리)
     * @param  BootProbeExecutor  $executor  자식 프로세스 실행기
     */
    public function __construct(
        private readonly BenchmarkIdentity $identity,
        private readonly BootProbeExecutor $executor,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function axis(): BenchmarkAxis
    {
        return BenchmarkAxis::Boot;
    }

    /**
     * {@inheritDoc}
     */
    public function run(BenchmarkProfile $profile, BenchmarkRunOptions $options, ?\Closure $onProgress = null): BenchmarkResult
    {
        $notify = $onProgress ?? static fn (string $message) => null;
        $uri = '/'.ltrim((string) $profile->option('uri'), '/');
        $runs = $options->runsExplicit
            ? max(1, $options->runs)
            : max(1, (int) $profile->option('runs', self::DEFAULT_RUNS));

        if ($options->asUser !== null && $this->identity->findExistingUser($options->asUser) === null) {
            return BenchmarkResult::skipped($profile, "계측에 사용할 계정을 찾을 수 없습니다: {$options->asUser}");
        }

        $issued = null;
        $notes = [];

        try {
            if ($profile->option('permissions') !== null || $options->asUser !== null) {
                $issued = $this->identity->issueToken(
                    (array) $profile->option('permissions', []),
                    $options->asUser !== null ? $this->identity->findExistingUser($options->asUser) : null
                );
                $notes[] = $issued['ephemeral']
                    ? '인증: 계측용 임시 계정 — 계측 뒤 삭제했습니다.'
                    : '인증: 지정 계정 — 계측용 토큰만 발급했다가 삭제했습니다.';
            }

            $samples = [];

            // 첫 회는 opcache·캐시 워밍이라 버린다 (측정 회차와 별도로 1회 더 실행)
            for ($i = 0; $i <= $runs; $i++) {
                $sample = $this->runOnce($uri, $issued['token'] ?? '');

                if ($sample === null) {
                    return BenchmarkResult::skipped($profile, '자식 프로세스 결과를 읽지 못했습니다: '.$this->lastFailure);
                }

                if ($i > 0) {
                    $samples[] = $sample;
                }

                $notify(sprintf('%s 회차 %d/%d — %sms', $uri, $i, $runs, $sample['ms']['total']));
            }
        } finally {
            if ($issued !== null) {
                $this->identity->discard($issued);
            }
        }

        return $this->summarize($profile, $uri, $runs, $samples, $notes);
    }

    /**
     * 자식 프로세스 1회를 실행하고 결과 JSON 을 해석합니다.
     *
     * @param  string  $uri  요청 경로
     * @param  string  $token  Bearer 토큰 (없으면 빈 문자열)
     * @return array<string, mixed>|null 1회 계측값 (해석 실패 시 null)
     */
    private function runOnce(string $uri, string $token): ?array
    {
        $outcome = $this->executor->run(
            [PHP_BINARY, app_path('Benchmark/Probes/boot_probe.php'), $uri, $token],
            [
                'APP_RUNNING_IN_CONSOLE' => 'false',
                'G7_BOOT_PROBE_BASE_URL' => (string) config('app.url'),
            ],
            base_path(),
        );

        foreach (array_reverse(preg_split('/\R/', (string) $outcome['stdout'])) as $line) {
            if (! str_starts_with($line, self::OUTPUT_PREFIX)) {
                continue;
            }

            $decoded = json_decode(substr($line, strlen(self::OUTPUT_PREFIX)), true);

            if (is_array($decoded) && isset($decoded['ms']['total'])) {
                return $decoded;
            }
        }

        $this->lastFailure = sprintf(
            'exit=%s %s',
            $outcome['exit_code'] ?? 'null',
            mb_substr(trim((string) ($outcome['stderr'] ?: $outcome['stdout'])), 0, 300)
        );

        return null;
    }

    /**
     * 회차별 계측값을 결과로 묶습니다.
     *
     * 시간은 회차 중앙값을, 개수(쿼리·캐시·리스너)는 마지막 회차 값을 씁니다 — 개수는 캐시가
     * 데워진 뒤에는 회차마다 같아야 하므로, 다르면 회차별 범위를 메모로 남깁니다.
     *
     * @param  BenchmarkProfile  $profile  프로파일
     * @param  string  $uri  요청 경로
     * @param  int  $runs  측정 회차
     * @param  array<int, array<string, mixed>>  $samples  회차별 계측값
     * @param  array<int, string>  $notes  앞서 쌓인 메모
     * @return BenchmarkResult 계측 결과
     */
    private function summarize(BenchmarkProfile $profile, string $uri, int $runs, array $samples, array $notes): BenchmarkResult
    {
        $median = static function (array $values): float {
            sort($values);

            return (float) $values[intdiv(count($values), 2)];
        };
        $phase = static fn (string $key): float => $median(array_map(
            static fn (array $sample): float => (float) ($sample['ms'][$key] ?? 0),
            $samples
        ));

        $last = $samples[count($samples) - 1];
        $cacheLookups = (int) $last['cache']['hit'] + (int) $last['cache']['missed'];

        $queryCounts = array_unique(array_map(static fn (array $sample): int => (int) $sample['db']['count'], $samples));
        if (count($queryCounts) > 1) {
            $notes[] = sprintf('회차별 쿼리 수가 다릅니다: %d~%d건', min($queryCounts), max($queryCounts));
        }

        $repeated = array_slice((array) $last['db']['repeated'], 0, self::TOP_LIMIT, true);
        foreach ($repeated as $sql => $count) {
            $notes[] = sprintf('같은 SQL %d회: %s', $count, mb_substr((string) $sql, 0, 160));
        }

        $cacheKeys = array_slice((array) $last['cache']['keys'], 0, self::TOP_LIMIT, true);

        $metrics = [
            'uri' => $uri,
            'status' => (int) $last['status'],
            'runs' => $runs,
            'ms' => [
                'total' => $phase('total'),
                'autoload' => $phase('autoload'),
                'app_create' => $phase('app_create'),
                'bootstrap' => $phase('bootstrap'),
                'handle' => $phase('handle'),
                'terminate' => $phase('terminate'),
            ],
            'query_count' => (int) $last['db']['count'],
            'query_count_during_boot' => (int) $last['db']['during_boot'],
            'db_ms' => (float) $last['db']['time_ms'],
            'repeated_queries' => $repeated,
            'cache' => [
                'hit' => (int) $last['cache']['hit'],
                'missed' => (int) $last['cache']['missed'],
                'written' => (int) $last['cache']['written'],
                'top_keys' => $cacheKeys,
            ],
            'included_files' => (int) $last['included_files'],
            'providers' => (int) $last['providers'],
            'event_listeners' => (int) $last['event_listeners'],
            'event_wildcard_listeners' => (int) $last['event_wildcard_listeners'],
            'hook_actions' => (int) $last['hook_actions'],
            'hook_filters' => (int) $last['hook_filters'],
        ];

        return new BenchmarkResult(
            profile: $profile,
            headers: ['요청', '상태', '전체(ms)', '부트스트랩(ms)', '처리(ms)', '쿼리(건)', '캐시 조회', '파일', '리스너', '훅 액션'],
            rows: [[
                'GET '.$uri,
                (string) $metrics['status'],
                number_format($metrics['ms']['total'], 1),
                number_format($metrics['ms']['bootstrap'], 1),
                number_format($metrics['ms']['handle'], 1),
                number_format($metrics['query_count']),
                number_format($cacheLookups),
                number_format($metrics['included_files']),
                number_format($metrics['event_listeners']),
                number_format($metrics['hook_actions']),
            ]],
            metrics: $metrics,
            notes: $notes,
        );
    }
}
