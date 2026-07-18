<?php

namespace App\Console\Commands\Layout;

use App\Support\LayoutMarkup\CompileContext;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
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

        try {
            $result = (new LayoutMarkupCompiler($registry))->compile(
                File::get($file),
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
            $this->writeOutput($file, $result->json);
        }

        $this->line("<info>✓</info> {$file}".($result->hasWarnings() ? ' <comment>(경고 '.count($result->warnings).'건)</comment>' : ''));

        return [true, $result->hasWarnings()];
    }

    /**
     * 산출 JSON 을 원 위치(또는 --out)에 씁니다.
     *
     * @param  string  $file  소스 `.g7.html` 경로
     * @param  array<string, mixed>  $json  산출 JSON
     */
    private function writeOutput(string $file, array $json): void
    {
        $out = $this->option('out') ?? preg_replace('/\.g7\.html$/i', '.json', $file);

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
        $templateId = $this->targetTemplateId($file);

        foreach (["templates/{$templateId}/components.json", "templates/_bundled/{$templateId}/components.json"] as $candidate) {
            $manifest = base_path($candidate);

            if (File::exists($manifest)) {
                return ComponentRegistry::fromFile($manifest);
            }
        }

        return null;
    }

    /**
     * 파일 경로에서 검증·lint 문맥을 만듭니다.
     *
     * @param  string  $file  `.g7.html` 경로
     * @return CompileContext 컴파일 문맥
     */
    private function resolveContext(string $file): CompileContext
    {
        $normalized = str_replace('\\', '/', $file);
        $sourceKind = match (true) {
            str_contains($normalized, '/plugins/') => 'plugin',
            str_contains($normalized, '/modules/') => 'module',
            str_contains($normalized, '/templates/') => 'template',
            default => null,
        };

        $templateId = $this->targetTemplateId($file);

        return new CompileContext(
            sourceKind: $sourceKind,
            identifier: $this->extensionIdentifier($normalized),
            templateHandlers: $this->scanTemplateHandlers($templateId),
            nestingRules: $this->loadNestingRules($templateId),
        );
    }

    /**
     * 대상 템플릿 식별자를 결정합니다 (`--template` 우선, 없으면 admin/·user/ 디렉토리로 판별).
     *
     * @param  string  $file  `.g7.html` 경로
     * @return string 템플릿 식별자
     */
    private function targetTemplateId(string $file): string
    {
        if ($this->option('template')) {
            return (string) $this->option('template');
        }

        $normalized = str_replace('\\', '/', $file);

        // 템플릿 자신의 레이아웃이면 그 템플릿.
        if (preg_match('#/templates/(?:_bundled/)?([^/]+)/#', $normalized, $m) === 1) {
            return $m[1];
        }

        // 모듈·플러그인 레이아웃은 admin/·user/ 로 대상 템플릿이 갈린다.
        return str_contains($normalized, '/user/') ? 'sirsoft-basic' : 'sirsoft-admin_basic';
    }

    /**
     * 확장 레이아웃 경로에서 소유 확장 식별자를 뽑습니다.
     *
     * @param  string  $normalized  슬래시 정규화된 경로
     * @return string|null 확장 식별자, 확장 소속이 아니면 null
     */
    private function extensionIdentifier(string $normalized): ?string
    {
        if (preg_match('#/(?:modules|plugins|templates)/(?:_bundled/)?([^/]+)/#', $normalized, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * 템플릿이 등록한 핸들러 이름을 `src/handlers/index.ts` 에서 정적 파싱합니다.
     *
     * TS 를 실행하지 않고 객체 리터럴 키만 뽑는다 — lint 은 경고이므로 완벽한 파싱보다 흔한
     * 형태(따옴표/무따옴표 키)를 넉넉히 잡는 편이 오검출을 줄인다.
     *
     * @param  string  $templateId  템플릿 식별자
     * @return array<int, string> 등록 핸들러 이름 목록
     */
    private function scanTemplateHandlers(string $templateId): array
    {
        foreach (["templates/{$templateId}/src/handlers/index.ts", "templates/_bundled/{$templateId}/src/handlers/index.ts"] as $candidate) {
            $file = base_path($candidate);

            if (! File::exists($file)) {
                continue;
            }

            $source = File::get($file);
            $handlers = [];

            // `key:` / `'key':` / `"key":` 형태의 객체 리터럴 키 (핸들러 맵) 를 모은다.
            if (preg_match_all('/^\s*[\'"]?([A-Za-z][\w.$-]*)[\'"]?\s*:/m', $source, $matches) !== false) {
                $handlers = $matches[1];
            }

            return array_values(array_unique($handlers));
        }

        return [];
    }

    /**
     * 템플릿의 nesting.json 에서 컨테이너 → accepts 맵을 읽습니다.
     *
     * @param  string  $templateId  템플릿 식별자
     * @return array<string, array<int, string>> 컨테이너 name → 허용 자식 name 목록
     */
    private function loadNestingRules(string $templateId): array
    {
        foreach (["templates/{$templateId}/editor-spec/nesting.json", "templates/_bundled/{$templateId}/editor-spec/nesting.json"] as $candidate) {
            $file = base_path($candidate);

            if (! File::exists($file)) {
                continue;
            }

            $data = json_decode(File::get($file), true);
            $rules = [];

            foreach ($data['containers'] ?? [] as $name => $spec) {
                if (isset($spec['accepts']) && is_array($spec['accepts'])) {
                    $rules[$name] = $spec['accepts'];
                }
            }

            return $rules;
        }

        return [];
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
