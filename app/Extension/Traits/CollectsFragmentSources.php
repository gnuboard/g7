<?php

namespace App\Extension\Traits;

use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 레이아웃 확장 fragment 의 등록 소스를 고르고 컴파일하는 공통 트레이트.
 *
 * fragment 를 DB 에 등록하는 경로는 **네 갈래**다 — 확장 갱신(`RefreshesLayoutExtensions`) ·
 * 확장 활성화(`ModuleManager`·`PluginManager::registerLayoutExtensions`) ·
 * 템플릿 오버라이드(`TemplateManager::registerExtensionOverrides`) ·
 * **템플릿 활성화**(`LayoutExtensionService::registerAllActiveExtensionsToTemplate`).
 * 넷이 같은 규약(`.g7.html` 소스 SSoT 우선 + 짝 마크업이 없는 `.json`)을 써야 하므로 판별·읽기를
 * 여기 한 곳에 둔다.
 *
 * 종전에는 첫 갈래만 이 규약을 따랐고 나머지 셋은 `glob('*.json')` 또는 `File::get`+`json_decode` 로
 * 직행해 소스를 조용히 무시했다 — 규약이 복제되면 한쪽만 넓힐 때 나머지가 반쪽으로 남는다는 것을
 * 그대로 보여준 자리라, 판별에 더해 **읽기까지** 여기서 끝내 그 재발을 막는다.
 *
 * @see ValidatesLayoutFiles 본 레이아웃(`layouts/`) 쪽 대칭 규약
 */
trait CollectsFragmentSources
{
    use ResolvesLayoutInstallSource;

    /**
     * fragment 소스 컴파일 결과 캐시 (실행 단위 · 키는 경로+mtime+size).
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $fragmentCompileCache = [];

    /**
     * 등록할 fragment 소스를 수집합니다 — `.g7.html`(있으면 우선) + 짝 `.g7.html` 이 없는 `.json`.
     *
     * 디렉토리의 `.g7.html` 소스 파일을 `.json` 목록 위에 얹어 같은 논리 fragment 는 마크업을 SSoT 로
     * 삼는다. `.g7.html` 은 컴파일로, `.json` 은 직접 읽는다.
     *
     * `config('template.layout_markup.install_source') === 'json'`(커맨드 `--layout-source=json`) 이면
     * 짝 `.json` 이 실재하는 마크업을 건너뛴다 — 본 레이아웃 경로와 같은 규약이다.
     *
     * @param  string  $extensionsPath  확장 fragment 디렉토리 경로
     * @param  array<int, string>  $jsonFiles  이미 알고 있는 `.json` 경로 (모르면 빈 배열 — 디렉토리에서 스캔)
     * @return array<int, array{path: string, markup: bool}> 소스 디스크립터 목록
     */
    protected function collectFragmentSources(string $extensionsPath, array $jsonFiles): array
    {
        // 호출처가 `.json` 목록을 넘기지 않으면(템플릿 오버라이드) 디렉토리에서 직접 스캔한다.
        if ($jsonFiles === [] && File::isDirectory($extensionsPath)) {
            $jsonFiles = File::glob(rtrim($extensionsPath, '/\\').DIRECTORY_SEPARATOR.'*.json') ?: [];
        }

        $markupFiles = File::isDirectory($extensionsPath)
            ? File::glob(rtrim($extensionsPath, '/\\').DIRECTORY_SEPARATOR.'*.g7.html') ?: []
            : [];

        // 짝 판별·우선순위는 규약 SSoT 가 수행한다 (사본 금지 — ResolvesLayoutInstallSource 참조).
        return $this->mergeLayoutSourcesByPriority($markupFiles, $jsonFiles, 'fragment');
    }

    /**
     * fragment 참조 경로 하나의 실제 소스를 고릅니다 — 소스 SSoT(`.g7.html`) 우선.
     *
     * 디렉토리를 스캔하는 {@see collectFragmentSources()} 와 달리, **이미 특정된 fragment 파일 하나**를
     * 읽어야 하는 호출처(확장이 자기 확장점 정의를 직접 읽는 Listener 등)를 위한 진입점이다.
     * 우선순위 규칙 자체는 {@see ResolvesLayoutInstallSource::layoutSourceCandidates()} 한 곳에 있다.
     *
     * @param  string  $fragmentPath  fragment 참조 경로 (`.json` 또는 `.g7.html`)
     * @return array{path: string, markup: bool}|null 실재하는 소스가 없으면 null
     */
    protected function resolveFragmentSource(string $fragmentPath): ?array
    {
        foreach ($this->layoutSourceCandidates($fragmentPath) as $candidate) {
            if (is_file($candidate['path'])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * fragment 소스 목록을 읽어 등록 가능한 정의 배열로 만듭니다.
     *
     * fragment 를 DB 에 등록하는 경로가 여럿인데(확장 활성화 · 확장 갱신 · 템플릿 활성화) 종전에는
     * 그중 하나만 이 규약을 탔고 나머지는 `File::get`+`json_decode` 로 직행했다. 직행 경로는
     * `.g7.html` 을 아예 보지 않으므로 소스만 고치면 조용히 반영되지 않는다. 사본을 늘리지 않도록
     * **읽기까지 여기서 끝낸다**.
     *
     * @param  string  $extensionsPath  확장 fragment 디렉토리 경로
     * @param  array<int, string>  $jsonFiles  이미 알고 있는 `.json` 경로 (모르면 빈 배열)
     * @param  array<string, mixed>  $logContext  실패 로그에 덧붙일 문맥
     * @return array<int, array{path: string, data: array<string, mixed>}> 읽기에 성공한 정의만
     */
    protected function loadFragmentDefinitions(string $extensionsPath, array $jsonFiles, array $logContext = []): array
    {
        $definitions = [];

        foreach ($this->collectFragmentSources($extensionsPath, $jsonFiles) as $source) {
            $data = $this->loadFragmentSource($source, $logContext);

            if ($data !== null) {
                $definitions[] = ['path' => $source['path'], 'data' => $data];
            }
        }

        return $definitions;
    }

    /**
     * fragment 소스 하나를 읽습니다 — 마크업은 메모리 컴파일, `.json` 은 파싱.
     *
     * 등록 루프가 한 파일 때문에 통째로 멈추면 나머지 확장점이 사라지므로, 실패는 로그로 남기고
     * `null` 을 돌려 그 건만 건너뛴다 (확장 번들 concat 이 확장별 try/catch 를 쓰는 것과 같은 이유).
     *
     * @param  array{path: string, markup: bool}  $source  소스 디스크립터
     * @param  array<string, mixed>  $logContext  실패 로그에 덧붙일 문맥
     * @return array<string, mixed>|null 실패 시 null
     */
    protected function loadFragmentSource(array $source, array $logContext = []): ?array
    {
        try {
            if ($source['markup']) {
                return $this->compileMarkupFragmentFile($source['path']);
            }

            $data = json_decode(File::get($source['path']), true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
                Log::error("레이아웃 확장 JSON 파싱 실패: {$source['path']}", $logContext + [
                    'error' => json_last_error_msg(),
                ]);

                return null;
            }

            return $data;
        } catch (\Throwable $e) {
            Log::error("레이아웃 확장 파일 처리 실패: {$source['path']}", $logContext + [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * fragment `.g7.html` 소스 파일을 등록 시점에 메모리로 컴파일합니다 (강검증 포함, 디스크 미기록).
     *
     * @param  string  $markupFile  `.g7.html` 절대 경로
     * @return array<string, mixed> 컴파일된 fragment JSON
     *
     * @throws CompileError 컴파일·강검증 실패 시
     * @throws \RuntimeException 대상 템플릿 컴포넌트 매니페스트를 찾지 못하면
     */
    protected function compileMarkupFragmentFile(string $markupFile): array
    {
        // 실행 단위 캐시 — 설치는 같은 조각을 여러 번 읽고, 런타임 소비처(확장 Listener)는 요청마다
        // 자기 확장점 정의를 읽는다. 짝 `.json` 을 파싱하던 비용이 마크업 컴파일로 바뀌므로,
        // 캐시가 없으면 소스 SSoT 로 옮기는 대가가 그대로 응답 시간이 된다.
        $cacheKey = $markupFile.'|'.(@filemtime($markupFile) ?: 0).'|'.(@filesize($markupFile) ?: 0);

        if (isset(self::$fragmentCompileCache[$cacheKey])) {
            return self::$fragmentCompileCache[$cacheKey];
        }

        $resolver = new MarkupCompileContextResolver;
        $registry = $resolver->registryFor($markupFile);

        if ($registry === null) {
            throw new \RuntimeException(__('exceptions.layout_markup.registry_not_found'));
        }

        $result = (new LayoutMarkupCompiler($registry))->compile(
            File::get($markupFile),
            $markupFile,
            $resolver->contextFor($markupFile),
        );

        return self::$fragmentCompileCache[$cacheKey] = $result->json;
    }
}
