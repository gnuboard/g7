<?php

namespace Tests\Unit\Benchmark;

use App\Benchmark\Axes\BootAxisRunner;
use App\Benchmark\BenchmarkIdentity;
use App\Benchmark\BootProbeExecutor;
use App\Benchmark\DTO\BenchmarkProfile;
use App\Benchmark\DTO\BenchmarkRunOptions;
use App\Enums\BenchmarkAxis;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 요청 부팅 축 실행기 단위 테스트.
 *
 * 자식 프로세스는 가짜 실행기로 바꿔 끼워, 실행기가 무엇을 어떤 환경으로 띄우고 그 출력을 어떻게
 * 묶는지만 본다. 계측 수치(시간·개수의 크기)는 단언하지 않는다 — 실행 머신에 종속된다.
 */
class BootAxisRunnerTest extends TestCase
{
    /**
     * 가짜 실행기가 받은 호출 목록
     *
     * @var array<int, array{command: array<int, string>, env: array<string, string>, cwd: string}>
     */
    private array $calls = [];

    /**
     * 회차마다 내놓을 자식 출력을 차례로 돌려주는 가짜 실행기를 만듭니다.
     *
     * @param  array<int, string>  $outputs  회차별 표준 출력 (마지막 값을 이후 회차에 반복)
     * @return BootProbeExecutor 가짜 실행기
     */
    private function fakeExecutor(array $outputs): BootProbeExecutor
    {
        $calls = &$this->calls;

        return new class($outputs, $calls) extends BootProbeExecutor
        {
            private int $index = 0;

            /**
             * 회차별 출력과 호출 기록 저장소를 받습니다.
             *
             * @param  array<int, string>  $outputs  회차별 표준 출력
             * @param  array<int, mixed>  $calls  호출 기록 (참조)
             */
            public function __construct(private array $outputs, private array &$calls) {}

            /**
             * 호출을 기록하고 준비한 출력을 차례로 돌려줍니다.
             *
             * @param  array<int, string>  $command  실행할 명령과 인자
             * @param  array<string, string>  $env  자식 환경 변수
             * @param  string  $cwd  작업 디렉토리
             * @return array{exit_code: int|null, stdout: string, stderr: string} 실행 결과
             */
            public function run(array $command, array $env, string $cwd): array
            {
                $this->calls[] = ['command' => $command, 'env' => $env, 'cwd' => $cwd];
                $stdout = $this->outputs[min($this->index, count($this->outputs) - 1)];
                $this->index++;

                return ['exit_code' => 0, 'stdout' => $stdout, 'stderr' => ''];
            }
        };
    }

    /**
     * 자식 프로브 출력 한 줄을 만듭니다.
     *
     * @param  float  $total  전체 시간(ms)
     * @param  int  $queries  쿼리 수
     * @return string 표준 출력
     */
    private function probeOutput(float $total, int $queries = 4): string
    {
        return "부팅 중 경고 같은 잡음 줄\nG7_BOOT_PROBE ".json_encode([
            'path' => '/api/consent',
            'status' => 200,
            'ms' => ['autoload' => 1.0, 'app_create' => 1.0, 'bootstrap' => $total / 2, 'handle' => $total / 4, 'terminate' => 0.5, 'total' => $total],
            'db' => ['count' => $queries, 'during_boot' => 2, 'time_ms' => 3.5, 'repeated' => ['select 1' => 2], 'statements' => ['select 1' => 2]],
            'cache' => ['hit' => 10, 'missed' => 1, 'written' => 1, 'keys' => ['HIT ext.plugins.active_identifiers' => 8]],
            'included_files' => 900,
            'providers' => 60,
            'event_listeners' => 70,
            'event_wildcard_listeners' => 1,
            'hook_actions' => 500,
            'hook_filters' => 200,
        ])."\n";
    }

    /**
     * 부팅 프로파일을 만듭니다.
     *
     * @param  array<string, mixed>  $options  축 고유 옵션
     * @return BenchmarkProfile 프로파일
     */
    private function profile(array $options = []): BenchmarkProfile
    {
        return new BenchmarkProfile(
            key: 'boot_sample',
            axis: BenchmarkAxis::Boot,
            sourceKind: 'core',
            sourceIdentifier: 'core',
            options: ['uri' => '/api/consent'] + $options,
        );
    }

    /**
     * 회차마다 새 프로세스를 웹 요청 분기로 띄웁니다.
     *
     * @effects boot_axis_spawns_fresh_process_per_run_with_web_runtime
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 회차마다_새_프로세스를_웹_분기로_띄운다(): void
    {
        $runner = new BootAxisRunner(app(BenchmarkIdentity::class), $this->fakeExecutor([$this->probeOutput(10.0)]));

        $runner->run($this->profile(), new BenchmarkRunOptions(runs: 2, runsExplicit: true));

        // 첫 회(워밍)를 포함해 회차 + 1 번 실행한다
        $this->assertCount(3, $this->calls);

        foreach ($this->calls as $call) {
            $this->assertSame(PHP_BINARY, $call['command'][0]);
            $this->assertStringEndsWith('boot_probe.php', str_replace('\\', '/', $call['command'][1]));
            $this->assertSame('/api/consent', $call['command'][2]);
            $this->assertSame('false', $call['env']['APP_RUNNING_IN_CONSOLE'], '콘솔 분기면 템플릿 로딩을 건너뛰어 웹 요청과 다른 것을 잰다.');
            $this->assertArrayHasKey('G7_BOOT_PROBE_BASE_URL', $call['env']);
            $this->assertSame(base_path(), $call['cwd']);
        }
    }

    /**
     * 첫 회를 버리고 시간은 중앙값, 개수는 마지막 회차로 묶습니다.
     *
     * @effects boot_axis_discards_first_run_and_reports_median_and_counts
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 첫_회를_버리고_중앙값과_개수를_산출한다(): void
    {
        // 첫 회 999ms 는 워밍이라 중앙값 계산에서 빠져야 한다
        $runner = new BootAxisRunner(app(BenchmarkIdentity::class), $this->fakeExecutor([
            $this->probeOutput(999.0),
            $this->probeOutput(30.0),
            $this->probeOutput(10.0),
            $this->probeOutput(20.0),
        ]));

        $result = $runner->run($this->profile(), new BenchmarkRunOptions(runs: 3, runsExplicit: true));

        $this->assertFalse($result->skipped, (string) $result->skipReason);
        $this->assertSame(20.0, $result->metrics['ms']['total']);
        $this->assertSame(3, $result->metrics['runs']);

        foreach (['query_count', 'query_count_during_boot', 'cache', 'included_files', 'providers', 'event_listeners', 'event_wildcard_listeners', 'hook_actions', 'hook_filters', 'repeated_queries'] as $key) {
            $this->assertArrayHasKey($key, $result->metrics);
        }
        foreach (['hit', 'missed', 'written', 'top_keys'] as $key) {
            $this->assertArrayHasKey($key, $result->metrics['cache']);
        }

        $this->assertSame(['요청', '상태', '전체(ms)', '부트스트랩(ms)', '처리(ms)', '쿼리(건)', '캐시 조회', '파일', '리스너', '훅 액션'], $result->headers);
        $this->assertCount(1, $result->rows);
        $this->assertStringContainsString('같은 SQL', implode("\n", $result->notes), '같은 요청 안에서 반복된 SQL 은 메모로 드러나야 한다.');
    }

    /**
     * `--runs` 를 주지 않으면 프로파일 기본 회차를 씁니다.
     *
     * @effects boot_axis_discards_first_run_and_reports_median_and_counts
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function runs_미지정이면_프로파일_회차를_쓴다(): void
    {
        $runner = new BootAxisRunner(app(BenchmarkIdentity::class), $this->fakeExecutor([$this->probeOutput(10.0)]));

        $runner->run($this->profile(['runs' => 4]), new BenchmarkRunOptions(runs: 3));

        $this->assertCount(5, $this->calls);
    }

    /**
     * 회차별 쿼리 수가 다르면 범위를 남깁니다.
     *
     * @effects boot_axis_reports_varying_query_counts_across_runs
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 회차별_쿼리_수가_다르면_범위를_남긴다(): void
    {
        $runner = new BootAxisRunner(app(BenchmarkIdentity::class), $this->fakeExecutor([
            $this->probeOutput(10.0, 9),
            $this->probeOutput(10.0, 4),
            $this->probeOutput(10.0, 6),
        ]));

        $result = $runner->run($this->profile(), new BenchmarkRunOptions(runs: 2, runsExplicit: true));

        $this->assertStringContainsString('회차별 쿼리 수가 다릅니다: 4~6건', implode("\n", $result->notes));
    }

    /**
     * 결과 줄을 못 읽으면 사유와 함께 건너뜁니다.
     *
     * @effects boot_axis_skips_with_reason_when_probe_output_unreadable
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 결과_줄이_없으면_사유와_함께_건너뛴다(): void
    {
        $runner = new BootAxisRunner(app(BenchmarkIdentity::class), $this->fakeExecutor(["PHP Fatal error: 무언가\n"]));

        $result = $runner->run($this->profile(), new BenchmarkRunOptions(runs: 1, runsExplicit: true));

        $this->assertTrue($result->skipped);
        $this->assertStringContainsString('자식 프로세스 결과를 읽지 못했습니다', (string) $result->skipReason);
        $this->assertStringContainsString('Fatal', (string) $result->skipReason);
    }

    /**
     * 권한을 선언한 프로파일은 커밋한 토큰을 자식에 넘기고, 끝나면 계정·역할·토큰을 지웁니다.
     *
     * @effects boot_axis_issues_committed_token_and_discards_it_after_run
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 인증_프로파일은_토큰을_넘기고_끝나면_지운다(): void
    {
        $usersBefore = User::count();
        $runner = new BootAxisRunner(app(BenchmarkIdentity::class), $this->fakeExecutor([$this->probeOutput(10.0)]));

        $result = $runner->run(
            $this->profile(['uri' => '/api/admin/plugins', 'permissions' => ['core.plugins.read']]),
            new BenchmarkRunOptions(runs: 1, runsExplicit: true),
        );

        $this->assertFalse($result->skipped, (string) $result->skipReason);
        $this->assertNotSame('', $this->calls[0]['command'][3] ?? '', '자식 프로세스는 Bearer 토큰을 받아야 한다.');
        $this->assertSame($usersBefore, User::count());
        $this->assertSame(0, DB::table('roles')->where('identifier', 'like', 'g7_bench_%')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->where('name', 'like', 'g7-bench-%')->count());
    }

    /**
     * 권한 선언이 없는 공개 프로파일은 토큰 없이 요청합니다.
     *
     * @effects boot_axis_issues_committed_token_and_discards_it_after_run
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 공개_프로파일은_토큰_없이_요청한다(): void
    {
        $runner = new BootAxisRunner(app(BenchmarkIdentity::class), $this->fakeExecutor([$this->probeOutput(10.0)]));

        $runner->run($this->profile(), new BenchmarkRunOptions(runs: 1, runsExplicit: true));

        $this->assertSame('', $this->calls[0]['command'][3]);
    }
}
