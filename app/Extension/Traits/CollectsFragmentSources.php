<?php

namespace App\Extension\Traits;

use App\Extension\Helpers\LayoutFragmentSourceHelper;
use App\Extension\Layout\BackupLayoutReader;
use App\Extension\Layout\FragmentReadCache;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\EmptyMap;
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
     * 읽기 실패 수집함 — 배열이면 실패를 로그로 남기지 않고 `[파일 기본 이름 => 원인]` 으로 모은다.
     *
     * 업데이트 백업을 다시 읽어 비교 기준을 만드는 읽기({@see BackupLayoutReader})만 켠다 —
     * 그 실패는 「그 조각만 비교 기준 없이 진행」 하면 되는 일이라 업데이트 1회에 WARNING 1줄로 모아 알린다(#725).
     * 본 레이아웃 쪽 {@see ValidatesLayoutFiles::$skippedLayoutErrors} 와 같은 모양이다.
     *
     * @var array<string, string>|null
     */
    protected ?array $fragmentReadFailures = null;

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
     * @param  array<string, mixed>  $logContext  실패 로그에 덧붙일 문맥 — 소유 확장(`plugin`·`module`·`source`)을 싣는다. 붙을 템플릿은 싣지 않는다(컴파일이 템플릿과 무관해 오해만 낳는다)
     * @param  string|null  $templateId  쓰지 않는다 — 확장 코드 호환으로 시그니처만 유지한다
     * @return array<int, array{path: string, data: array<string, mixed>, key: string}> 읽기에 성공한 정의만
     */
    protected function loadFragmentDefinitions(string $extensionsPath, array $jsonFiles, array $logContext = [], ?string $templateId = null): array
    {
        $definitions = [];

        foreach ($this->collectFragmentSources($extensionsPath, $jsonFiles) as $source) {
            $data = $this->loadFragmentSource($source, $logContext, $templateId);

            if ($data !== null) {
                $definitions[] = [
                    'path' => $source['path'],
                    'data' => $data,
                    // 조각 식별자 — 같은 대상에 조각이 둘일 때 DB 행을 가른다 (파생 규칙은 헬퍼 한 벌).
                    'key' => LayoutFragmentSourceHelper::fragmentKey($source['path']),
                ];
            }
        }

        return $definitions;
    }

    /**
     * fragment 디렉토리의 **소스 파일** 키 집합을 만듭니다 — 컴파일 성공 여부와 무관하다.
     *
     * 갱신 경로가 "파일에 더는 없는 조각의 DB 행" 을 정리할 때 쓴다. 정의 로드 결과로 판정하면 컴파일에
     * 실패한 조각까지 고아로 보여 그 행을 지운다 — 실패는 로그로 남기고 행은 보존해야 한다.
     *
     * @param  string  $extensionsPath  확장 fragment 디렉토리 경로
     * @param  array<int, string>  $jsonFiles  이미 알고 있는 `.json` 경로 (모르면 빈 배열)
     * @return array<int, string> 조각 식별자 목록 (중복 제거)
     */
    protected function fragmentSourceKeys(string $extensionsPath, array $jsonFiles): array
    {
        $keys = [];

        foreach ($this->collectFragmentSources($extensionsPath, $jsonFiles) as $source) {
            $keys[LayoutFragmentSourceHelper::fragmentKey($source['path'])] = true;
        }

        return array_keys($keys);
    }

    /**
     * fragment 소스 하나를 읽습니다 — 마크업은 메모리 컴파일, `.json` 은 파싱.
     *
     * 등록 루프가 한 파일 때문에 통째로 멈추면 나머지 확장점이 사라지므로, 실패는 로그로 남기고
     * `null` 을 돌려 그 건만 건너뛴다 (확장 번들 concat 이 확장별 try/catch 를 쓰는 것과 같은 이유).
     *
     * 실패는 프로세스에 **파일마다 한 번만** 기록한다({@see FragmentReadCache}) — 등록 경로가 템플릿마다·경로마다 같은
     * 파일을 다시 읽어도 같은 실패를 되풀이하지 않는다(#725). 파일을 고치면 mtime·크기가 바뀌어 다시 읽는다.
     *
     * @param  array{path: string, markup: bool}  $source  소스 디스크립터
     * @param  array<string, mixed>  $logContext  실패 로그에 덧붙일 문맥
     * @param  string|null  $templateId  쓰지 않는다 — 확장 코드 호환으로 시그니처만 유지한다
     * @param  string  $subject  실패 문구의 대상 이름 — 본 레이아웃을 읽는 호출처(편집기 트리)는 `레이아웃 소스` 를 넘긴다
     * @return array<string, mixed>|null 실패 시 null
     */
    protected function loadFragmentSource(array $source, array $logContext = [], ?string $templateId = null, string $subject = '레이아웃 확장 파일'): ?array
    {
        $path = $source['path'];
        $key = FragmentReadCache::key($path);
        $remembered = FragmentReadCache::failure($key);

        if ($remembered !== null) {
            // 같은 실패를 다시 기록하지 않는다. 수집 중이면 그 읽기의 보고에는 싣는다.
            $this->collectFragmentReadFailure($path, $remembered);

            return null;
        }

        // JSON 문구의 대상 이름 — `레이아웃 확장 파일` → `레이아웃 확장 JSON …`, `레이아웃 소스` → `레이아웃 소스 JSON …`
        $jsonSubject = preg_replace('/ 파일$/u', '', $subject) ?? $subject;

        try {
            if ($source['markup']) {
                return $this->compileMarkupFragmentFile($path, $templateId);
            }

            // 빈 객체 `{}` 를 보존해 읽는다 — 연관 배열 디코드는 등록하는 순간 `[]` 로 굳힌다 (#758)
            $data = EmptyMap::decodePreserving(File::get($path));

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->reportFragmentReadFailure($key, $path, "{$jsonSubject} JSON 파싱 실패: {$path}", json_last_error_msg(), $logContext);

                return null;
            }

            if ($data instanceof EmptyMap) {
                // 문서 전체가 `{}` 다 — 보존 디코드는 배열 대신 빈 map 표식을 돌려주므로, 아래 분기로 보내면
                // 객체를 「객체가 아님」 이라 적고 타입 자리에 내부 클래스명이 찍힌다.
                $this->reportFragmentReadFailure($key, $path, "{$jsonSubject} JSON 형식 오류 (빈 객체 — 정의 없음): {$path}", '조각 JSON 에 정의가 없습니다 (빈 객체 {})', $logContext);

                return null;
            }

            // 목록(`[1,2]`·`[]`)도 PHP 배열이라 객체로 통과해 등록 단계에서 「대상 부재」 DEBUG 로만 남고 점검 보고의
            // 「읽기 실패」에도 잡히지 않았다 — 객체가 아닌 것으로 같이 판정한다(7.1.1 출시 전).
            if (! is_array($data) || array_is_list($data)) {
                // 파싱은 됐는데 객체가 아니다 — 종전에는 `error: "No error"` 로 남아 원인을 알 수 없었다.
                $type = is_array($data) ? 'list' : get_debug_type($data);
                $this->reportFragmentReadFailure($key, $path, "{$jsonSubject} JSON 형식 오류 (객체가 아님): {$path}", '조각 JSON 은 객체여야 합니다 ('.$type.')', $logContext + [
                    'type' => $type,
                ]);

                return null;
            }

            return $data;
        } catch (\Throwable $e) {
            $this->reportFragmentReadFailure($key, $path, "{$subject} 처리 실패: {$path}", $e->getMessage(), $logContext);

            return null;
        }
    }

    /**
     * 조각 소스 읽기 실패를 한 번 기록합니다 — 수집 중이면 수집함에, 아니면 ERROR 로그에.
     *
     * @param  string  $key  캐시 키
     * @param  string  $path  파일 경로
     * @param  string  $message  로그 문구
     * @param  string  $error  실패 원인 (컴파일 오류는 행·열을 싣는다)
     * @param  array<string, mixed>  $logContext  로그 문맥
     * @return void 반환값 없음
     */
    private function reportFragmentReadFailure(string $key, string $path, string $message, string $error, array $logContext): void
    {
        FragmentReadCache::rememberFailure($key, $path, $error);

        if ($this->fragmentReadFailures !== null) {
            $this->collectFragmentReadFailure($path, $error);

            return;
        }

        Log::error($message, $logContext + ['error' => $error]);
    }

    /**
     * 수집 중이면 실패 원인을 수집함에 싣습니다.
     *
     * @param  string  $path  파일 경로
     * @param  string  $error  실패 원인
     * @return void 반환값 없음
     */
    private function collectFragmentReadFailure(string $path, string $error): void
    {
        if ($this->fragmentReadFailures !== null) {
            $this->fragmentReadFailures[basename($path)] = $error;
        }
    }

    /**
     * 소스는 있는데 정의를 얻지 못한 조각을 원인과 함께 돌려줍니다 — `[조각 식별자 => 원인]`.
     *
     * 점검 명령이 읽지 못한 조각을 보고할 때 쓴다(#725). 소스 선택·읽기는 이 트레이트가 끝낸다 — 호출처가 소스 목록을 따로
     * 모으면 그것이 규약 사본이 된다.
     *
     * @param  string  $extensionsPath  확장 fragment 디렉토리 경로
     * @param  array<int, string>  $jsonFiles  이미 알고 있는 `.json` 경로 (모르면 빈 배열)
     * @param  array<int, array{path: string, data: array<string, mixed>, key: string}>  $definitions  {@see loadFragmentDefinitions()} 로 읽은 정의
     * @return array<string, string> 읽지 못한 조각 식별자 => 원인
     */
    protected function unreadableFragmentSources(string $extensionsPath, array $jsonFiles, array $definitions): array
    {
        $readable = array_fill_keys(array_column($definitions, 'key'), true);
        $unreadable = [];

        foreach ($this->collectFragmentSources($extensionsPath, $jsonFiles) as $source) {
            $key = LayoutFragmentSourceHelper::fragmentKey($source['path']);

            if (! isset($readable[$key])) {
                $unreadable[$key] = FragmentReadCache::lastFailureFor($source['path']) ?? '읽기 실패';
            }
        }

        return $unreadable;
    }

    /**
     * fragment `.g7.html` 소스 파일을 등록 시점에 메모리로 컴파일합니다 (강검증 포함, 디스크 미기록).
     *
     * @param  string  $markupFile  `.g7.html` 절대 경로
     * @param  string|null  $templateId  쓰지 않는다 — 확장 코드 호환으로 시그니처만 유지한다
     * @return array<string, mixed> 컴파일된 fragment JSON
     *
     * @throws CompileError 컴파일·강검증 실패 시
     */
    protected function compileMarkupFragmentFile(string $markupFile, ?string $templateId = null): array
    {
        // 템플릿과 무관하게 컴파일한다 — 산출은 태그 이름·`data-type` 만으로 정해지므로 붙을 템플릿이
        // 무엇이든 같다(JSON 조각이 어떤 템플릿의 컴포넌트 목록도 보지 않았던 것과 같다). `$templateId` 는
        // 호출 계약 호환을 위해 받지만 산출에 쓰지 않는다 — 템플릿마다 결과가 갈리면 템플릿 교체·요청마다
        // 도는 리스너·업데이트 복원 경로가 서로 다른 조각을 쌓는다.

        // 실행 단위 캐시 — 설치는 같은 조각을 여러 번 읽고, 런타임 소비처(확장 Listener)는 요청마다
        // 자기 확장점 정의를 읽는다. 짝 `.json` 을 파싱하던 비용이 마크업 컴파일로 바뀌므로,
        // 캐시가 없으면 소스 SSoT 로 옮기는 대가가 그대로 응답 시간이 된다. 이 트레이트를 쓰는 클래스가 여럿이라
        // 클래스 밖 한 곳에 둔다({@see FragmentReadCache}).
        $cacheKey = FragmentReadCache::key($markupFile);
        $cached = FragmentReadCache::compiled($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        // 업데이트 백업 경로(`{id}_{Ymd_His}`)처럼 경로로 소유 확장을 알 수 없는 읽기는 강제 지정값을 쓴다
        // ({@see ValidatesLayoutFiles::$compileContextOverride} — 그 트레이트를 함께 쓰는 읽기만 채운다).
        $identifierOverride = property_exists($this, 'compileContextOverride')
            ? ($this->compileContextOverride['extension'] ?? null)
            : null;

        $result = (new LayoutMarkupCompiler)->compile(
            File::get($markupFile),
            $markupFile,
            (new MarkupCompileContextResolver)->installContextFor($markupFile, $identifierOverride),
        );

        return FragmentReadCache::rememberCompiled($cacheKey, $result->json);
    }
}
