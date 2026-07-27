<?php

namespace App\Console\Commands\Layout;

use App\Support\LayoutMarkup\CompileContext;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\LayoutCorpus;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * `.g7.html` 저작 파일을 JSON 레이아웃으로 역변환(decompile)·검증하는 커맨드.
 *
 * 용어(PO 정의): **compile = JSON → HTML**(저작 소스 산출), **decompile = HTML → JSON**(설치·검증).
 * 설치 경로가 `.g7.html` 을 인메모리로 JSON 화하는 것과 같은 역변환을, 이 커맨드는 오프라인에서
 * 실행해 저작 파일이 올바르게 JSON 으로 풀리는지 확인한다.
 *
 * 하는 일은 세 가지다: **역변환**(토큰→트리→노드/문서), **강검증**(구조·엔드포인트·외부URL·
 * 컴포넌트·wait_for·script — 현행 설치 경로엔 없는 검증), **lint**(경고). 산출 JSON 은 디스크에
 * 쓰지 않고 검증만 하는 것이 기본이다(설치가 인메모리로 JSON 화하는 것과 같은 역변환의 오프라인
 * 확인). `--out` 을 주면 단일 파일의 산출 JSON 을 그 경로로 덤프한다(진단·검수용).
 *
 * 경로 하나를 주면 그 파일만, `--all` 이면 번들 확장·템플릿의 모든 `.g7.html` 을 역변환한다.
 * 이관(S10~S16)이 끝난 현재 코퍼스는 `.g7.html` 로 전량 저작돼 있고(`LayoutCorpus::scan('.g7.html')`),
 * `--all` 은 그 전량을 대상으로 돈다. 이 커맨드의 현재 쓰임은 신규 이관이 아니라
 * **왕복 검증**(저작본 ↔ 산출 JSON 등가성) 과 진단이다.
 */
class LayoutDecompileCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'layout:decompile
                            {path? : 역변환할 .g7.html 파일 경로 (미지정 시 --all 필요)}
                            {--all : 번들 확장·템플릿의 모든 .g7.html 을 역변환}
                            {--template= : 대상 템플릿 식별자 (기본: 경로의 admin/·user/ 로 판별)}
                            {--out= : 산출 JSON 덤프 경로 (단일 파일 진단·검수용, 미지정 시 검증만)}';

    /**
     * @var string
     */
    protected $description = '.g7.html 저작 파일을 JSON 레이아웃으로 역변환하고 강검증·lint 합니다';

    /**
     * 경로 → 레지스트리·문맥 해석기 (`layout:compile` 과 공유 — 규칙 드리프트 차단).
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
            $this->info('역변환할 .g7.html 파일이 없습니다.');

            return self::SUCCESS;
        }

        $failed = 0;
        $warned = 0;

        foreach ($files as $file) {
            [$ok, $hasWarnings] = $this->decompileOne($file);
            $failed += $ok ? 0 : 1;
            $warned += $hasWarnings ? 1 : 0;
        }

        $this->newLine();
        $this->info(sprintf('완료 — 파일 %d개, 실패 %d개, 경고 있는 파일 %d개.', count($files), $failed, $warned));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 파일 하나를 역변환·검증하고 결과를 보고합니다.
     *
     * @param  string  $file  `.g7.html` 절대 경로
     * @return array{0: bool, 1: bool} `[성공 여부, 경고 여부]`
     */
    private function decompileOne(string $file): array
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

        // 산출 JSON 은 디스크에 병존시키지 않는다 — `--out` 을 준 단일 파일만 진단용으로 덤프한다.
        if ($this->option('out') !== null) {
            File::put(
                (string) $this->option('out'),
                json_encode($result->json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            );
        }

        $this->line("<info>✓</info> {$file}".($result->hasWarnings() ? ' <comment>(경고 '.count($result->warnings).'건)</comment>' : ''));

        return [true, $result->hasWarnings()];
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
        // 모집단은 {@see LayoutCorpus} 가 SSoT — 커맨드가 글롭을 따로 들면 한쪽만 넓힐 때
        // 나머지가 조용히 다른 모집단을 보게 된다(㉓ 이 겪은 "반쪽 코퍼스 위의 escape-0").
        return LayoutCorpus::scan('.g7.html');
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
