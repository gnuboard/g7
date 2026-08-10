<?php

namespace App\Console\Commands;

use App\Benchmark\BenchmarkProfileRegistry;
use App\Benchmark\BenchmarkReporter;
use App\Benchmark\Contracts\BenchmarkAxisRunner;
use App\Benchmark\DTO\BenchmarkProfile;
use App\Benchmark\DTO\BenchmarkResult;
use App\Benchmark\DTO\BenchmarkRunOptions;
use App\Enums\BenchmarkAxis;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 성능 계측 커맨드 — 목록 조회 / 화면 응답 / 쓰기 작업 / 배치 작업 4축
 *
 * 계측 대상은 소유자가 선언합니다 — 코어는 `config/benchmark.php`, 확장은
 * `getBenchmarkProfiles()` 오버라이드입니다. 커맨드에 대상을 하드코딩하지 않는 이유는,
 * 확장이 설치·제거되는 설치본마다 실제로 존재하는 대상이 다르기 때문입니다.
 *
 * 축별 실행은 `App\Benchmark\Axes\*` 실행기가 담당하고, 이 커맨드는 프로파일 선택·옵션
 * 해석·출력만 합니다.
 *
 * tinker 스크립트 대신 커맨드로 만든 이유: tinker 는 REPL 이라 스크립트 실행 후에도 STDIN 을
 * 기다려 출력이 유실된다.
 */
class BenchCommand extends Command
{
    protected $signature = 'g7:bench
        {--profile=* : 계측할 프로파일 키 (짧은 키 또는 출처/키 형태). 다중 지정 가능}
        {--axis= : 축 단위 실행 (list|screen|write|batch)}
        {--all : 등록된 모든 프로파일 실행}
        {--offsets=0,20000,50000,99980 : 측정할 OFFSET 목록 (쉼표 구분, list 축)}
        {--runs=3 : 측정 횟수 (첫 회는 버림)}
        {--per-page=20 : 목록/화면 1페이지 건수}
        {--seed=0 : 계측 전 합성 행 시딩 건수 (0 = 시딩 안 함, list 축)}
        {--fresh : 시딩 전에 대상 테이블을 비움 (운영 환경에서는 거부)}
        {--explain : 각 OFFSET 의 실행 계획도 함께 수집 (list 축)}
        {--as= : 화면 계측에 사용할 기존 계정 (ID 또는 이메일). 미지정 시 계측용 임시 계정}
        {--allow-write : 데이터를 변경하는 축(write/batch/비-GET 화면) 실행 허용}
        {--database= : 계측에 사용할 데이터베이스명 (미지정 시 기본 연결. 개발 DB 오염 방지용)}
        {--json : 기계 판독용 JSON 출력}
        {--report= : 마크다운 리포트 저장 경로 (값 없이 --report 만 주면 storage/app/benchmarks/ 아래 자동 생성)}
        {--list-profiles : 등록된 프로파일 목록 출력}';

    protected $description = '목록 조회·화면 응답·쓰기·배치 4축 성능을 계측합니다 (대상은 코어 config + 확장 선언에서 수집)';

    protected $aliases = ['g7:bench:pagination'];

    /**
     * 리포트 기본 저장 디렉토리 (저장소 미추적 — 계측값은 실행 머신 사양에 종속되므로 축적하지 않음)
     */
    private const REPORT_DIR = 'app/benchmarks';

    /**
     * @param  BenchmarkProfileRegistry  $registry  프로파일 레지스트리
     * @param  BenchmarkReporter  $reporter  결과 출력기
     * @param  iterable<BenchmarkAxisRunner>  $runners  축 실행기 목록 (컨테이너 태그 주입)
     */
    public function __construct(
        private readonly BenchmarkProfileRegistry $registry,
        private readonly BenchmarkReporter $reporter,
        private readonly iterable $runners,
    ) {
        parent::__construct();
    }

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $this->applyDatabaseOverride();

        if ($this->option('list-profiles')) {
            return $this->listProfiles();
        }

        [$profiles, $selectionError] = $this->selectProfiles();

        if ($selectionError !== null) {
            $this->error($selectionError);

            return self::FAILURE;
        }

        if ($profiles === []) {
            $this->error('계측할 프로파일이 없습니다. --profile / --axis / --all 중 하나를 지정하세요.');

            return self::FAILURE;
        }

        $options = $this->runOptions();

        // 계측 결과가 환경에 좌우되므로 실행 환경을 먼저 드러낸다
        if (! $this->option('json')) {
            foreach ($this->reporter->environment() as $name => $value) {
                $this->line(sprintf('  %-14s %s', $name, $value));
            }

            $this->newLine();
        }

        $this->reportCollectionWarnings();

        $results = [];

        foreach ($profiles as $profile) {
            $results[] = $this->runProfile($profile, $options);
        }

        return $this->output($results, $options);
    }

    /**
     * 계측용 데이터베이스 지정을 반영합니다.
     *
     * 계측은 대량 시딩을 동반하므로 개발 DB 대신 폐기 가능한 DB 를 지정할 수 있어야 합니다.
     * config 가 캐시된 환경에서는 `.env` / `--env` 로 연결을 바꿀 수 없어 런타임 치환이
     * 유일한 수단입니다.
     */
    private function applyDatabaseOverride(): void
    {
        $database = (string) $this->option('database');

        if ($database === '') {
            return;
        }

        $connection = config('database.default');

        config([
            "database.connections.{$connection}.database" => $database,
            "database.connections.{$connection}.write.database" => $database,
            "database.connections.{$connection}.read.database" => $database,
        ]);

        DB::purge($connection);
    }

    /**
     * 등록된 프로파일 목록을 출력합니다.
     *
     * @return int 종료 코드
     */
    private function listProfiles(): int
    {
        $profiles = $this->registry->all();

        if ($this->option('json')) {
            $this->line((string) json_encode(
                array_map(fn (BenchmarkProfile $profile) => $profile->toArray(), array_values($profiles)),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return self::SUCCESS;
        }

        $this->table(
            ['프로파일', '축', '출처', '설명'],
            array_map(fn (BenchmarkProfile $profile) => [
                $profile->qualifiedKey(),
                $profile->axis->value,
                $profile->sourceKind,
                $profile->label ?? '-',
            ], array_values($profiles))
        );

        $this->reportCollectionWarnings();

        return self::SUCCESS;
    }

    /**
     * 실행할 프로파일을 고릅니다.
     *
     * @return array{0: array<int, BenchmarkProfile>, 1: string|null} [실행 대상, 실패 사유]
     */
    private function selectProfiles(): array
    {
        $keys = array_filter((array) $this->option('profile'), 'strlen');

        if ($keys !== []) {
            $selected = [];

            foreach ($keys as $key) {
                [$profile, $error] = $this->registry->resolve((string) $key);

                if ($error !== null) {
                    return [[], $error];
                }

                $selected[] = $profile;
            }

            return [$selected, null];
        }

        $axisOption = (string) $this->option('axis');

        if ($axisOption !== '') {
            $axis = BenchmarkAxis::tryFrom($axisOption);

            if ($axis === null) {
                return [[], "알 수 없는 축: {$axisOption} (허용: ".implode('|', BenchmarkAxis::values()).')'];
            }

            return [array_values($this->registry->byAxis($axis)), null];
        }

        return [$this->option('all') ? array_values($this->registry->all()) : [], null];
    }

    /**
     * 커맨드 옵션을 실행 옵션으로 해석합니다.
     *
     * @return BenchmarkRunOptions 실행 옵션
     */
    private function runOptions(): BenchmarkRunOptions
    {
        $asUser = (string) $this->option('as');

        return new BenchmarkRunOptions(
            offsets: $this->parseOffsets(),
            runs: max(1, (int) $this->option('runs')),
            seed: max(0, (int) $this->option('seed')),
            fresh: (bool) $this->option('fresh'),
            explain: (bool) $this->option('explain'),
            allowWrite: (bool) $this->option('allow-write'),
            asUser: $asUser === '' ? null : $asUser,
            perPage: max(1, (int) $this->option('per-page')),
        );
    }

    /**
     * OFFSET 목록을 파싱합니다.
     *
     * @return array<int, int> 오름차순 정렬된 OFFSET 목록
     */
    private function parseOffsets(): array
    {
        $offsets = array_map('intval', array_filter(explode(',', (string) $this->option('offsets')), 'strlen'));
        sort($offsets);

        return $offsets === [] ? [0] : $offsets;
    }

    /**
     * 프로파일 1건을 계측합니다.
     *
     * @param  BenchmarkProfile  $profile  대상 프로파일
     * @param  BenchmarkRunOptions  $options  실행 옵션
     * @return BenchmarkResult 계측 결과
     */
    private function runProfile(BenchmarkProfile $profile, BenchmarkRunOptions $options): BenchmarkResult
    {
        $runner = $this->runnerFor($profile->axis);

        if ($runner === null) {
            return BenchmarkResult::skipped($profile, "축 실행기가 없습니다: {$profile->axis->value}");
        }

        if (! $this->option('json')) {
            $this->line(sprintf('▶ %s (%s)', $profile->qualifiedKey(), $profile->axis->label()));
        }

        $onProgress = $this->option('json')
            ? null
            : fn (string $message) => $this->line('  '.$message);

        return $runner->run($profile, $options, $onProgress);
    }

    /**
     * 축에 대응하는 실행기를 찾습니다.
     *
     * @param  BenchmarkAxis  $axis  대상 축
     * @return BenchmarkAxisRunner|null 실행기 (없으면 null)
     */
    private function runnerFor(BenchmarkAxis $axis): ?BenchmarkAxisRunner
    {
        foreach ($this->runners as $runner) {
            if ($runner->axis() === $axis) {
                return $runner;
            }
        }

        return null;
    }

    /**
     * 무시된 프로파일 선언을 알립니다.
     *
     * 선언 오류를 조용히 버리면 그 대상이 계측 사각으로 남으므로 매 실행에 드러냅니다.
     */
    private function reportCollectionWarnings(): void
    {
        if ($this->option('json')) {
            return;
        }

        foreach ($this->registry->warnings() as $warning) {
            $this->warn('무시된 프로파일 선언 — '.$warning);
        }
    }

    /**
     * 계측 결과를 출력합니다.
     *
     * @param  array<int, BenchmarkResult>  $results  계측 결과
     * @param  BenchmarkRunOptions  $options  실행 옵션
     * @return int 종료 코드
     */
    private function output(array $results, BenchmarkRunOptions $options): int
    {
        $warnings = $this->registry->warnings();

        if ($this->option('json')) {
            $this->line($this->reporter->toJson($results, $options, $warnings));
        } else {
            foreach ($results as $result) {
                $this->newLine();
                $this->line(sprintf('%s (%s)', $result->profile->qualifiedKey(), $result->profile->axis->label()));

                if ($result->skipped) {
                    $this->warn('  측정하지 않음: '.$result->skipReason);

                    continue;
                }

                $this->table($result->headers, $result->rows);

                foreach ($result->notes as $note) {
                    $this->line('  '.$note);
                }
            }
        }

        if ($this->wantsReport()) {
            $path = $this->writeReport($results, $options, $warnings);
            $this->newLine();
            $this->info('리포트 저장: '.$path);
        }

        // 전부 건너뛴 실행을 성공으로 보고하면 "측정했다"로 읽히므로 실패로 돌려준다
        $measured = array_filter($results, fn (BenchmarkResult $result) => ! $result->skipped);

        return $measured === [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 리포트 출력이 요청되었는지 판정합니다.
     *
     * `--report` 는 값이 선택이라 `option()` 만으로는 "미지정"과 "값 없이 지정"을
     * 구분할 수 없으므로 원시 입력을 확인합니다.
     *
     * @return bool 리포트 출력 여부
     */
    private function wantsReport(): bool
    {
        return $this->input->hasParameterOption('--report')
            || $this->input->hasParameterOption('--report=')
            || (string) $this->option('report') !== '';
    }

    /**
     * 마크다운 리포트를 저장합니다.
     *
     * @param  array<int, BenchmarkResult>  $results  계측 결과
     * @param  BenchmarkRunOptions  $options  실행 옵션
     * @param  array<int, string>  $warnings  프로파일 수집 경고
     * @return string 저장된 절대 경로
     */
    private function writeReport(array $results, BenchmarkRunOptions $options, array $warnings): string
    {
        $given = (string) $this->option('report');

        $path = $given !== ''
            ? $given
            : storage_path(self::REPORT_DIR.'/bench-'.now()->format('Ymd-His').'.md');

        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $this->reporter->toMarkdown($results, $options, $warnings));

        return $path;
    }
}
