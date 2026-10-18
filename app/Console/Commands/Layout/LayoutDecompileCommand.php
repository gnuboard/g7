<?php

namespace App\Console\Commands\Layout;

use App\Services\LayoutMarkupService;
use App\Support\LayoutMarkup\CompileContext;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\LayoutCorpus;
use App\Support\LayoutMarkup\LayoutJsonWriter;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * `.g7.html` 소스 파일을 JSON 레이아웃으로 역변환(decompile)·검증하는 커맨드.
 *
 * 용어(설계 정의): **compile = JSON → HTML**(소스 산출), **decompile = HTML → JSON**(설치·검증).
 * 설치 경로가 `.g7.html` 을 인메모리로 JSON 화하는 것과 같은 역변환을, 이 커맨드는 오프라인에서
 * 실행해 소스 파일이 올바르게 JSON 으로 풀리는지 확인한다.
 *
 * 하는 일은 네 가지다: **역변환**(토큰→트리→노드/문서), **강검증**(구조·엔드포인트·외부URL·
 * 컴포넌트·wait_for·script — 현행 설치 경로엔 없는 검증), **lint**(경고), 그리고 **굽기**
 * (`--sync`/`--check`). 산출 JSON 은 디스크에 쓰지 않고 검증만 하는 것이 기본이다(설치가
 * 인메모리로 JSON 화하는 것과 같은 역변환의 오프라인 확인). `--out` 을 주면 단일 파일의 산출
 * JSON 을 그 경로로 덤프한다(진단·검수용).
 *
 * 경로 하나를 주면 그 파일만, `--all` 이면 번들 확장·템플릿의 모든 `.g7.html` 을 역변환한다.
 * 이관(S10~S16)이 끝난 현재 코퍼스는 `.g7.html` 로 전량 작성돼 있고(`LayoutCorpus::scan('.g7.html')`),
 * `--all` 은 그 전량을 대상으로 돈다.
 *
 * ## 굽기 (S18.5 축 A)
 *
 * `--sync` 는 컴파일 산출을 짝 `.json` 에 기록하고(짝이 없으면 생성), `--check` 는 쓰지 않고
 * 대조만 해 불일치가 있으면 파일 목록과 함께 non-zero 로 끝난다(`prettier --check` 패턴 — CI·훅·
 * 게이트가 소비한다). 두 옵션이 생기기 전까지 짝 `.json` 은 **손유지**였고, 소스만 고치면
 * 코퍼스 게이트가 사후에 red 를 낼 뿐 고치는 방법은 알려 주지 않았다.
 *
 * **호출 주체는 개발자뿐이다 (D38)** — 설치·서빙 경로는 이 커맨드도, 그 직렬화기
 * ({@see LayoutJsonWriter})도 부르지 않는다. 설치가 소스를 인메모리로
 * 컴파일해 DB 에 등록하고 디스크에 쓰지 않는다는 것이 S10 재아키텍처의 결정이며, 굽기가 설치에
 * 끼어들면 프로덕션 배포가 저장소 파일을 수정하게 된다.
 */
class LayoutDecompileCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'layout:decompile
                            {path? : 역변환할 .g7.html 파일 경로 (미지정 시 --all 필요)}
                            {--all : 번들 확장·템플릿의 모든 .g7.html 을 역변환}
                            {--sync : 컴파일 산출을 짝 .json 에 기록 (짝이 없으면 생성)}
                            {--check : 짝 .json 이 컴파일 산출과 일치하는지 대조만 (불일치 시 non-zero)}
                            {--out= : 산출 JSON 덤프 경로 (단일 파일 진단·검수용, 미지정 시 검증만)}';

    /**
     * @var string
     */
    protected $description = '.g7.html 소스 파일을 JSON 레이아웃으로 역변환하고 강검증·lint·굽기(--sync/--check) 합니다';

    /**
     * 굽기 집계 — `--sync` 는 생성·갱신·무변경, `--check` 는 불일치 파일 목록을 모은다.
     *
     * @var array{created: int, updated: int, unchanged: int, stale: array<int, string>}
     */
    private array $bake = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'stale' => []];

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

        // 굽기와 대조는 서로 배타다 — 함께 주면 "쓰지 않고 대조만" 이라는 --check 의 계약이
        // 깨지므로, 어느 쪽을 침묵으로 이기게 하지 않고 즉시 실패한다.
        if ($this->option('sync') && $this->option('check')) {
            $this->error('--sync 와 --check 는 함께 쓸 수 없습니다 (--check 는 쓰지 않고 대조만 합니다).');

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

        if ($this->option('sync')) {
            $this->info(sprintf(
                '굽기 — 생성 %d개, 갱신 %d개, 무변경 %d개.',
                $this->bake['created'],
                $this->bake['updated'],
                $this->bake['unchanged'],
            ));
        }

        if ($this->option('check')) {
            if ($this->bake['stale'] !== []) {
                $this->newLine();
                $this->error(sprintf('짝 .json 이 컴파일 산출과 다릅니다 (%d개) — `layout:decompile --all --sync` 로 다시 구우세요:', count($this->bake['stale'])));

                foreach ($this->bake['stale'] as $stale) {
                    $this->line("  <fg=red>✗</> {$stale}");
                }

                return self::FAILURE;
            }

            // 통과도 소리를 낸다. 전건 최신일 때 아무것도 출력하지 않으면 "검사해서 통과했다" 와
            // "검사가 한 건도 돌지 않았다" 가 출력상 구분되지 않는다 — 이 트랙이 여러 회차에 걸쳐
            // 대가를 치른 무관측 형태이므로, 대조한 짝의 수를 세어서 남긴다.
            $this->info(sprintf('굽기 대조 — 짝 %d개 전건 최신.', $this->bake['unchanged']));
        }

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

        // `--out` 은 임의 경로 덤프(진단·검수용)라 짝 굽기와 다른 축이다. 인코딩 관례는 굽기와
        // 같아야 하므로 직렬화기를 공유한다 — 값을 두 곳에 적으면 한 곳이 낡는다(34차 규율).
        if ($this->option('out') !== null) {
            File::put((string) $this->option('out'), LayoutJsonWriter::encode($result->json));
        }

        $note = '';

        if ($this->option('sync')) {
            $outcome = LayoutJsonWriter::write(LayoutJsonWriter::jsonSiblingOf($file), $result->json);
            $this->bake[$outcome]++;

            $note = match ($outcome) {
                'created' => ' <comment>(짝 .json 생성)</comment>',
                'updated' => ' <comment>(짝 .json 갱신)</comment>',
                default => '',
            };
        }

        if ($this->option('check')) {
            $jsonFile = LayoutJsonWriter::jsonSiblingOf($file);

            if (LayoutJsonWriter::isUpToDate($jsonFile, $result->json)) {
                $this->bake['unchanged']++;
            } else {
                $this->bake['stale'][] = $this->relative($jsonFile);
            }
        }

        $this->line("<info>✓</info> {$file}".($result->hasWarnings() ? ' <comment>(경고 '.count($result->warnings).'건)</comment>' : '').$note);

        return [true, $result->hasWarnings()];
    }

    /**
     * 절대 경로를 저장소 루트 기준 상대 경로로 바꿉니다 (보고용).
     *
     * @param  string  $path  절대 경로
     * @return string 상대 경로 (슬래시 통일)
     */
    private function relative(string $path): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
    }

    /**
     * 검사용 템플릿 컴포넌트 레지스트리를 만듭니다 (템플릿 자신의 파일일 때만).
     *
     * 산출은 템플릿과 무관하다 — 레지스트리는 템플릿 자신의 레이아웃에서 그 템플릿에 없는 컴포넌트를
     * 세우는 검사에만 쓴다. 모듈·플러그인 레이아웃은 어떤 템플릿에 붙을지 모르므로 검사하지 않는다(JSON 과 동일).
     * 검사 목록에는 편집기와 같이 설치된 확장 전부의 컴포넌트가 함께 실린다(없는 확장 컴포넌트는 경고).
     *
     * @param  string  $file  `.g7.html` 경로
     * @return ComponentRegistry|null 레지스트리, 검사 대상 템플릿이 없으면 null
     */
    private function resolveRegistry(string $file): ?ComponentRegistry
    {
        return $this->resolver->registryFor($file, null, app(LayoutMarkupService::class)->installedExtensionManifests());
    }

    /**
     * 파일 경로에서 검증·lint 문맥을 만듭니다.
     *
     * @param  string  $file  `.g7.html` 경로
     * @return CompileContext 컴파일 문맥
     */
    private function resolveContext(string $file): CompileContext
    {
        return $this->resolver->contextFor($file);
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
