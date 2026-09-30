<?php

namespace App\Console\Commands\Layout;

use App\Services\LayoutMarkupService;
use App\Support\LayoutMarkup\CompileContext;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\LayoutCorpus;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use App\Support\LayoutMarkup\LayoutMarkupDecompiler;
use App\Support\LayoutMarkup\LayoutMarkupRoundTrip;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * JSON 레이아웃을 `.g7.html` 소스로 컴파일하는 커맨드 (`layout:decompile` 의 짝).
 *
 * 용어(설계 정의): **compile = JSON → HTML**(소스 산출), **decompile = HTML → JSON**(설치·검증).
 * 이관(S10~)이 원본 `.json` 을 `.g7.html` 소스 파일로 옮길 때 쓰는 생성 커맨드다.
 *
 * 두 모드다. **컴파일**(기본)은 `.json` 을 짝 `.g7.html` 로 만들어 이관 소스 파일을 산출한다 — 기록 전에
 * 산출을 프로덕션 진입점으로 되읽어 실패하면 쓰지 않는다(자기검사).
 * **왕복 검증**(`--roundtrip`)은 산출을 쓰지 않고 `decompile(compile(json)) ≡ json` 을 전량 확인한다 —
 * 계획서 DoD "왕복 불일치 0" 의 기계 오라클이자, 컴파일러·역변환기의 표면 커버리지를 봉인하는 유일한 계량이다.
 *
 * 이관 웨이브 운영자·파이프라인 진단용이라 dev-dashboard 에는 노출하지 않는다.
 */
class LayoutCompileCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'layout:compile
                            {path? : 컴파일할 .json 레이아웃 경로 (미지정 시 --all 필요)}
                            {--all : 모든 번들 .json 레이아웃을 대상}
                            {--roundtrip : 산출을 쓰지 않고 decompile(compile) 왕복만 검증}
                            {--out= : 산출 .g7.html 출력 경로 (단일 파일)}
                            {--limit=15 : 왕복 실패 상세 출력 개수}';

    /**
     * @var string
     */
    protected $description = 'JSON 레이아웃을 .g7.html 소스로 컴파일하고 왕복 동등성을 검증합니다';

    /**
     * 경로 → 레지스트리 해석기 (`layout:decompile` 과 공유).
     */
    private readonly MarkupCompileContextResolver $resolver;

    /**
     * 커맨드를 생성합니다.
     */
    public function __construct()
    {
        parent::__construct();

        $this->resolver = new MarkupCompileContextResolver;
    }

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $path = $this->argument('path');

        if ($path === null && ! $this->option('all')) {
            $this->error('경로를 지정하거나 --all 을 사용하세요.');

            return self::FAILURE;
        }

        $files = $this->option('all') ? $this->scanLayoutFiles() : [$this->absolutePath((string) $path)];

        if ($files === []) {
            $this->info('대상 .json 레이아웃이 없습니다.');

            return self::SUCCESS;
        }

        return $this->option('roundtrip') ? $this->runRoundTrip($files) : $this->runCompile($files);
    }

    /**
     * 왕복 검증 모드를 실행합니다.
     *
     * @param  array<int, string>  $files  대상 `.json` 절대 경로
     * @return int 종료 코드
     */
    private function runRoundTrip(array $files): int
    {
        $harness = new LayoutMarkupRoundTrip;
        $failures = [];
        $skipped = 0;
        $budget = [];
        $filesWithEscape = 0;

        foreach ($files as $file) {
            $registry = $this->resolveRegistry($file);

            $json = json_decode(File::get($file), true);

            if (! is_array($json)) {
                $skipped++;

                continue;
            }

            $result = $harness->verify($json, $registry, $file, $this->resolveContext($file));

            if (! $result['ok']) {
                $failures[] = ['file' => $file, 'result' => $result];
            }

            $escaped = $result['markup'] !== null ? LayoutMarkupRoundTrip::escapedKeys($result['markup']) : [];

            if ($escaped !== []) {
                $filesWithEscape++;
            }

            foreach ($escaped as $key) {
                $budget[$key] = ($budget[$key] ?? 0) + 1;
            }
        }

        $this->reportBudget($budget, $filesWithEscape);

        return $this->reportRoundTrip(count($files), $skipped, $failures);
    }

    /**
     * `<json>` 탈출구 예산을 보고합니다 (결함 인벤토리 allowlist 외 방출 봉인).
     *
     * @param  array<string, int>  $budget  탈출 키 → 건수
     * @param  int  $filesWithEscape  탈출을 쓴 파일 수
     * @return void
     */
    private function reportBudget(array $budget, int $filesWithEscape): void
    {
        if ($budget === []) {
            $this->info('<json> 탈출구 방출 0건 — 전 레이아웃이 첫 급부 문법으로 왕복.');

            return;
        }

        arsort($budget);
        $this->newLine();
        $this->line('<comment>=== &lt;json&gt; 탈출구 예산 (결함 인벤토리 대조) ===</comment>');

        foreach ($budget as $key => $count) {
            $this->line(sprintf('  %-24s %d건', $key, $count));
        }

        $this->line(sprintf('  총 %d건 / 파일 %d개', array_sum($budget), $filesWithEscape));
    }

    /**
     * 왕복 결과를 보고합니다.
     *
     * @param  int  $total  대상 파일 수
     * @param  int  $skipped  스킵 수 (레지스트리 부재·비 JSON)
     * @param  array<int, array{file: string, result: array<string, mixed>}>  $failures  실패 목록
     * @return int 종료 코드
     */
    private function reportRoundTrip(int $total, int $skipped, array $failures): int
    {
        $limit = (int) $this->option('limit');

        foreach (array_slice($failures, 0, $limit) as $failure) {
            $this->newLine();
            $this->error('✗ '.$failure['file']);

            $result = $failure['result'];

            if ($result['error'] !== null) {
                $this->line('  <comment>컴파일 오류</comment>: '.$result['error']);

                continue;
            }

            $this->line('  <comment>diff (source → recompiled)</comment>:');
            $this->line($this->diff($result['source'], $result['recompiled']));
        }

        $this->newLine();
        $this->info(sprintf(
            '왕복 — 대상 %d개, 불일치 %d개, 스킵 %d개.',
            $total,
            count($failures),
            $skipped,
        ));

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 정규화 후 두 JSON 의 첫 상이 지점을 요약합니다.
     *
     * @param  array<string, mixed>  $source  원본
     * @param  array<string, mixed>|null  $recompiled  재컴파일
     * @return string diff 요약
     */
    private function diff(array $source, ?array $recompiled): string
    {
        $a = explode("\n", LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize($source)));
        $b = explode("\n", LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize($recompiled ?? [])));

        $lines = [];
        $max = max(count($a), count($b));

        for ($i = 0, $shown = 0; $i < $max && $shown < 12; $i++) {
            $left = $a[$i] ?? '';
            $right = $b[$i] ?? '';

            if ($left !== $right) {
                $lines[] = '    - '.trim($left);
                $lines[] = '    + '.trim($right);
                $shown++;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * 컴파일 모드를 실행합니다 (짝 `.g7.html` 산출).
     *
     * @param  array<int, string>  $files  대상 `.json` 절대 경로
     * @return int 종료 코드
     */
    private function runCompile(array $files): int
    {
        $failed = 0;

        foreach ($files as $file) {
            $registry = $this->resolveRegistry($file);

            $json = json_decode(File::get($file), true);

            if (! is_array($json)) {
                $this->error("JSON 이 아닙니다: {$file}");
                $failed++;

                continue;
            }

            $markup = (new LayoutMarkupDecompiler($registry))->decompile($json);
            $out = $this->option('out') ?? preg_replace('/\.json$/i', '.g7.html', $file);

            // 자기검사 — 산출 마크업을 기록하기 전에 **프로덕션 진입점**으로 되읽는다. 설치·편집기가
            // 거부할 소스를 커맨드가 써 두면 잘못된 방출이 `--check` 까지 잠복한다(named_actions 주석
            // 중복이 그 형태였다). 실패하면 기록하지 않고 실패로 센다.
            try {
                (new LayoutMarkupCompiler($registry))->compile($markup, $out, $this->resolveContext($file));
            } catch (CompileError $error) {
                $this->error("자기검사 실패 — 산출을 기록하지 않습니다: {$error->getMessage()}");
                $failed++;

                continue;
            }

            File::put($out, $markup);
            $this->line("<info>✓</info> {$out}");
        }

        $this->newLine();
        $this->info(sprintf('완료 — 파일 %d개, 실패 %d개.', count($files), $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 검사용 템플릿 컴포넌트 레지스트리를 만듭니다 (템플릿 자신의 파일일 때만).
     *
     * 산출은 템플릿과 무관하다 — 레지스트리는 템플릿 자신의 레이아웃에서 그 템플릿에 없는 컴포넌트를
     * 세우는 검사에만 쓴다. 모듈·플러그인 레이아웃은 어떤 템플릿에 붙을지 모르므로 검사하지 않는다(JSON 과 동일).
     * 검사 목록에는 편집기와 같이 설치된 확장 전부의 컴포넌트가 함께 실린다(없는 확장 컴포넌트는 경고).
     *
     * @param  string  $file  `.json` 경로
     * @return ComponentRegistry|null 레지스트리, 검사 대상 템플릿이 없으면 null
     */
    private function resolveRegistry(string $file): ?ComponentRegistry
    {
        return $this->resolver->registryFor($file, null, app(LayoutMarkupService::class)->installedExtensionManifests());
    }

    /**
     * 파일 경로에서 검증·lint 문맥을 만듭니다 (왕복 오라클이 프로덕션 진입점을 타므로 필요).
     *
     * @param  string  $file  `.json` 경로
     * @return CompileContext 컴파일 문맥
     */
    private function resolveContext(string $file): CompileContext
    {
        return $this->resolver->contextFor($file);
    }

    /**
     * 번들 확장·템플릿에서 모든 레이아웃·fragment `.json` 을 스캔합니다.
     *
     * 모집단 정의는 {@see LayoutCorpus} 가 SSoT — 하네스·게이트가 같은 코퍼스를 보게 한다.
     *
     * @return array<int, string> `.json` 절대 경로 목록
     */
    private function scanLayoutFiles(): array
    {
        return LayoutCorpus::scan('.json');
    }

    /**
     * 상대 경로를 절대 경로로 변환합니다.
     *
     * @param  string  $path  입력 경로
     * @return string 절대 경로
     */
    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }
}
