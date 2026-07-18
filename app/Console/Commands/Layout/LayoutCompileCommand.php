<?php

namespace App\Console\Commands\Layout;

use App\Support\LayoutMarkup\CompileContext;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use App\Support\LayoutMarkup\LayoutProvenance;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * `.g7.html` 저작 파일을 JSON 레이아웃으로 컴파일·검증하는 커맨드 (S6).
 *
 * 하는 일은 세 가지다: **컴파일**(토큰→트리→노드/문서), **강검증**(구조·엔드포인트·외부URL·
 * 컴포넌트·wait_for·script — 현행 설치 경로엔 없는 검증), **lint**(경고). `--check` 는 산출을
 * 쓰지 않고 검증만 하는 건조 실행이고, 기본은 짝 `.json` 을 원 위치에 병존시킨다(계획서: 산출 JSON
 * 물리 병존은 강제 조건).
 *
 * 경로 하나를 주면 그 파일만, `--all` 이면 번들 확장·템플릿의 모든 `.g7.html` 을 컴파일한다.
 * 이관(S10~)이 시작되기 전에는 `.g7.html` 이 아직 0건이라 `--all` 은 "0건 처리" 를 보고한다 —
 * 파이프라인이 살아 있다는 확인이자, 이관 웨이브가 바로 붙일 수 있는 진입점이다.
 */
class LayoutCompileCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'layout:compile
                            {path? : 컴파일할 .g7.html 파일 경로 (미지정 시 --all 필요)}
                            {--all : 번들 확장·템플릿의 모든 .g7.html 을 컴파일}
                            {--check : 산출 JSON 을 쓰지 않고 검증만 (건조 실행)}
                            {--template= : 대상 템플릿 식별자 (기본: 경로의 admin/·user/ 로 판별)}
                            {--out= : 산출 JSON 출력 경로 (단일 파일 + 미지정 시 원 위치 .json)}';

    /**
     * @var string
     */
    protected $description = '.g7.html 저작 파일을 JSON 레이아웃으로 컴파일하고 강검증·lint 합니다';

    /**
     * 경로 → 레지스트리·문맥 해석기 (`layout:install-diff` 와 공유 — 규칙 드리프트 차단).
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

        $files = $this->option('all') ? $this->scanMarkupFiles() : [$this->absolutePath((string) $path)];

        if ($files === []) {
            $this->info('컴파일할 .g7.html 파일이 없습니다.');

            return self::SUCCESS;
        }

        $failed = 0;
        $warned = 0;

        foreach ($files as $file) {
            [$ok, $hasWarnings] = $this->compileOne($file);
            $failed += $ok ? 0 : 1;
            $warned += $hasWarnings ? 1 : 0;
        }

        $this->newLine();
        $this->info(sprintf('완료 — 파일 %d개, 실패 %d개, 경고 있는 파일 %d개.', count($files), $failed, $warned));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 파일 하나를 컴파일·검증하고 결과를 보고합니다.
     *
     * @param  string  $file  `.g7.html` 절대 경로
     * @return array{0: bool, 1: bool} `[성공 여부, 경고 여부]`
     */
    private function compileOne(string $file): array
    {
        if (! File::exists($file)) {
            $this->error("파일을 찾을 수 없습니다: {$file}");

            return [false, false];
        }

        $registry = $this->resolveRegistry($file);

        if ($registry === null) {
            $this->error("대상 템플릿을 찾지 못했습니다: {$file} (--template 로 지정하세요)");

            return [false, false];
        }

        $source = File::get($file);

        try {
            $result = (new LayoutMarkupCompiler($registry))->compile(
                $source,
                $file,
                $this->resolveContext($file),
            );
        } catch (CompileError $error) {
            $this->error($error->getMessage());

            return [false, false];
        }

        foreach ($result->warnings as $warning) {
            $this->warn($warning->toMessage());
        }

        if (! $this->option('check')) {
            $this->writeOutput($file, $result->json, $source);
        }

        $this->line("<info>✓</info> {$file}".($result->hasWarnings() ? ' <comment>(경고 '.count($result->warnings).'건)</comment>' : ''));

        return [true, $result->hasWarnings()];
    }

    /**
     * 산출 JSON 을 원 위치(또는 --out)에 씁니다.
     *
     * 산출 파일에는 `meta.authoring` provenance 표식을 얹는다(S7). 이 표식이 설치 시 공존 판별의
     * 근거이자 편집기 drift 마커다 — 컴파일러 자체는 표식을 붙이지 않으므로(왕복·fixture deep-equal
     * 보전) 파일로 쓰는 이 지점에서만 얹는다.
     *
     * @param  string  $file  소스 `.g7.html` 경로
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string  $source  `.g7.html` 원문 (provenance 해시 계산용)
     */
    private function writeOutput(string $file, array $json, string $source): void
    {
        $out = $this->option('out') ?? preg_replace('/\.g7\.html$/i', '.json', $file);

        $relativeSource = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file));
        $json = LayoutProvenance::stamp($json, $source, $relativeSource);

        File::put($out, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * 파일 경로에서 대상 템플릿 컴포넌트 레지스트리를 만듭니다.
     *
     * @param  string  $file  `.g7.html` 경로
     * @return ComponentRegistry|null 레지스트리, 대상 템플릿을 못 찾으면 null
     */
    private function resolveRegistry(string $file): ?ComponentRegistry
    {
        return $this->resolver->registryFor($file, $this->option('template') ? (string) $this->option('template') : null);
    }

    /**
     * 파일 경로에서 검증·lint 문맥을 만듭니다.
     *
     * @param  string  $file  `.g7.html` 경로
     * @return CompileContext 컴파일 문맥
     */
    private function resolveContext(string $file): CompileContext
    {
        return $this->resolver->contextFor($file, $this->option('template') ? (string) $this->option('template') : null);
    }

    /**
     * 번들 확장·템플릿에서 모든 `.g7.html` 파일을 스캔합니다.
     *
     * @return array<int, string> `.g7.html` 절대 경로 목록
     */
    private function scanMarkupFiles(): array
    {
        $roots = [
            base_path('templates/_bundled'),
            base_path('modules/_bundled'),
            base_path('plugins/_bundled'),
        ];

        $files = [];

        foreach ($roots as $root) {
            if (! File::isDirectory($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($iterator as $entry) {
                if ($entry->isFile() && str_ends_with(strtolower($entry->getFilename()), '.g7.html')) {
                    $files[] = $entry->getPathname();
                }
            }
        }

        sort($files);

        return $files;
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
