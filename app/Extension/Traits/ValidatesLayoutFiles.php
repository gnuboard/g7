<?php

namespace App\Extension\Traits;

use App\Exceptions\LayoutIncludeException;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 레이아웃 JSON 파일 검증 및 partial 해석 기능을 제공하는 트레이트.
 *
 * 템플릿 설치 및 모듈 활성화 시 레이아웃 파일의 유효성을 검사하고
 * partial 파일을 병합하는 공통 로직을 제공합니다.
 */
trait ValidatesLayoutFiles
{
    use ResolvesLayoutInstallSource;

    /**
     * Partial 순환 참조 추적용 스택
     */
    private array $partialStack = [];

    /**
     * 조각 저작본 컴파일 결과 캐시 (실행 단위 · 키는 경로+mtime+size).
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $partialCompileCache = [];

    /**
     * 레이아웃 파일들을 검증합니다.
     *
     * @param  string  $layoutsPath  레이아웃 디렉토리 경로
     * @param  string  $identifier  소스 식별자 (템플릿 또는 모듈)
     * @param  string  $sourceType  소스 타입 ('template' 또는 'module')
     * @param  bool  $recursive  재귀적으로 하위 디렉토리를 스캔할지 여부
     * @return array 검증된 레이아웃 데이터 배열
     *
     * @throws \Exception 레이아웃 검증 실패 시
     */
    protected function validateLayoutFiles(string $layoutsPath, string $identifier, string $sourceType, bool $recursive = false): array
    {
        $validatedLayouts = [];
        $errors = [];

        if (! File::exists($layoutsPath)) {
            return $validatedLayouts;
        }

        // 레이아웃 소스 수집 — `.g7.html`(저작 SSoT · 있으면 우선) + 짝 `.g7.html` 이 없는 `.json`.
        // `.g7.html` 은 설치 시점에 메모리로 컴파일해 등록하고(디스크 산출 JSON 미기록), `.json` 은
        // 직접 등록한다(병존 · 선택 설치원). 두 SSoT 가 애초에 공존하지 않으므로 provenance 판별이 없다.
        $layoutSources = $this->collectLayoutSources($layoutsPath, $recursive);

        if (empty($layoutSources)) {
            return $validatedLayouts;
        }

        foreach ($layoutSources as $source) {
            $layoutFile = $source['path'];
            $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $layoutFile);
            // Windows/Linux 호환을 위해 슬래시로 통일
            $relativePath = str_replace('\\', '/', $relativePath);

            if ($source['markup']) {
                // `.g7.html` 저작 파일을 설치 시점에 메모리로 컴파일 (강검증 포함).
                try {
                    $layoutData = $this->compileMarkupLayoutFile($layoutFile);
                } catch (CompileError $e) {
                    $errors[] = $this->formatLayoutValidationError($sourceType, 'markup_error', [
                        'file' => $relativePath,
                        'error' => $e->getDetail(),
                    ]);

                    continue;
                } catch (\RuntimeException $e) {
                    $errors[] = $this->formatLayoutValidationError($sourceType, 'markup_error', [
                        'file' => $relativePath,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }
            } else {
                // JSON 파싱 검증
                $jsonContent = File::get($layoutFile);
                $layoutData = json_decode($jsonContent, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $errors[] = $this->formatLayoutValidationError($sourceType, 'json_error', [
                        'file' => $relativePath,
                        'error' => json_last_error_msg(),
                    ]);

                    continue;
                }
            }

            // is_partial이 true면 검증 스킵 (DB 저장 안 함, partial 전용 파일)
            if (isset($layoutData['meta']['is_partial']) && $layoutData['meta']['is_partial'] === true) {
                continue;
            }

            // 템플릿의 경우 layout_name 필수 검증
            if ($sourceType === 'template' && ! isset($layoutData['layout_name'])) {
                $errors[] = $this->formatLayoutValidationError($sourceType, 'missing_name', [
                    'file' => $relativePath,
                ]);

                continue;
            }

            // 레이아웃 이름 결정
            $layoutName = $layoutData['layout_name'] ?? $this->generateLayoutNameFromPath($layoutsPath, $layoutFile, $identifier);

            // partial 병합 검증
            $basePath = dirname($layoutFile);
            $mainDataSources = $layoutData['data_sources'] ?? [];

            try {
                $layoutData = $this->resolveAllPartials($layoutData, $basePath, 0, $mainDataSources);
            } catch (LayoutIncludeException $e) {
                $errors[] = $this->formatLayoutValidationError($sourceType, 'partial_error', [
                    'file' => $relativePath,
                    'error' => $e->getMessage(),
                    'partial' => $e->getIncludePath(),
                ]);

                continue;
            }

            // data_sources ID 중복 검증 (현재 레이아웃 내부)
            $dataSourcesError = $this->validateDataSourceIds($layoutData['data_sources'] ?? [], $relativePath);
            if ($dataSourcesError) {
                $errors[] = $dataSourcesError;

                continue;
            }

            // 검증 통과한 레이아웃 저장
            $validatedLayouts[] = [
                'file' => $layoutFile,
                'data' => $layoutData,
                'layout_name' => $layoutName,
            ];
        }

        // extends 상속 관계에서 data_sources ID 중복 검증
        $extendsErrors = $this->validateExtendsDataSourceIds($validatedLayouts, $sourceType);
        $errors = array_merge($errors, $extendsErrors);

        // 오류가 하나라도 있으면 예외 발생
        if (! empty($errors)) {
            $errorMessage = $this->formatLayoutValidationError($sourceType, 'validation_failed', [
                'identifier' => $identifier,
                'count' => count($errors),
            ]);
            $errorMessage .= "\n- ".implode("\n- ", $errors);

            throw new \Exception($errorMessage);
        }

        return $validatedLayouts;
    }

    /**
     * extends 상속 관계에서 data_sources ID 중복을 검증합니다.
     *
     * 부모 레이아웃과 자식 레이아웃 간의 data_sources ID 중복을 체크합니다.
     * 설치 시점에는 DB에 레이아웃이 없으므로 파일 시스템 기반으로 검증합니다.
     *
     * @param  array  $validatedLayouts  검증된 레이아웃 배열
     * @param  string  $sourceType  소스 타입 ('template' 또는 'module')
     * @return array 에러 메시지 배열
     */
    protected function validateExtendsDataSourceIds(array $validatedLayouts, string $sourceType): array
    {
        $errors = [];

        // layout_name을 키로 하는 맵 생성
        $layoutMap = [];
        foreach ($validatedLayouts as $layout) {
            $layoutMap[$layout['layout_name']] = $layout;
        }

        // 각 레이아웃의 extends 관계 검증
        foreach ($validatedLayouts as $layout) {
            $layoutData = $layout['data'];
            $layoutName = $layout['layout_name'];

            // extends가 없으면 스킵
            if (! isset($layoutData['extends'])) {
                continue;
            }

            $parentLayoutName = $layoutData['extends'];

            // 부모 레이아웃이 같은 템플릿/모듈 내에 있는지 확인
            if (! isset($layoutMap[$parentLayoutName])) {
                // 부모가 다른 소스(DB에 이미 존재)에 있을 수 있으므로 여기서는 스킵
                // 런타임에 LayoutService에서 검증됨
                continue;
            }

            $parentLayout = $layoutMap[$parentLayoutName];
            $parentDataSources = $parentLayout['data']['data_sources'] ?? [];
            $childDataSources = $layoutData['data_sources'] ?? [];

            // 부모-자식 간 ID 중복 체크
            $parentIds = array_column($parentDataSources, 'id');
            $duplicates = [];

            foreach ($childDataSources as $childDataSource) {
                if (isset($childDataSource['id']) && in_array($childDataSource['id'], $parentIds, true)) {
                    $duplicates[] = $childDataSource['id'];
                }
            }

            if (! empty($duplicates)) {
                $errors[] = __('exceptions.layout.duplicate_data_source_id_extends', [
                    'ids' => implode(', ', array_unique($duplicates)),
                    'child' => $layoutName,
                    'parent' => $parentLayoutName,
                ]);
            }
        }

        return $errors;
    }

    /**
     * data_sources 배열 내 ID 중복을 검증합니다.
     *
     * @param  array  $dataSources  data_sources 배열
     * @param  string  $filePath  검증 중인 파일 경로 (에러 메시지용)
     * @return string|null 에러 메시지 또는 null (중복 없음)
     */
    protected function validateDataSourceIds(array $dataSources, string $filePath): ?string
    {
        $ids = [];
        $duplicates = [];

        foreach ($dataSources as $dataSource) {
            if (! isset($dataSource['id'])) {
                continue;
            }

            $id = $dataSource['id'];
            if (in_array($id, $ids, true)) {
                $duplicates[] = $id;
            } else {
                $ids[] = $id;
            }
        }

        if (! empty($duplicates)) {
            return __('exceptions.layout.duplicate_data_source_id_in_file', [
                'ids' => implode(', ', array_unique($duplicates)),
                'file' => $filePath,
            ]);
        }

        return null;
    }

    /**
     * 레이아웃 검증 에러 메시지를 포맷합니다.
     *
     * @param  string  $sourceType  소스 타입 ('template' 또는 'module')
     * @param  string  $errorType  에러 타입
     * @param  array  $params  에러 파라미터
     * @return string 포맷된 에러 메시지
     */
    protected function formatLayoutValidationError(string $sourceType, string $errorType, array $params): string
    {
        $translationKey = $sourceType === 'template'
            ? "templates.errors.layout_validation_{$errorType}"
            : "modules.errors.layout_validation_{$errorType}";

        return __($translationKey, $params);
    }

    /**
     * 레이아웃 파일 경로에서 대상 템플릿 타입을 판별합니다.
     *
     * layouts/ 기준 상대 경로의 첫 번째 디렉토리 세그먼트로 판별합니다.
     * - admin/ → 'admin'
     * - user/ → 'user'
     * - 직접 위치한 파일 → null (경고 후 스킵)
     *
     * @param  string  $layoutsPath  레이아웃 기본 경로
     * @param  string  $layoutFile  레이아웃 파일 전체 경로
     * @return string|null 템플릿 타입 ('admin', 'user') 또는 null (스킵 대상)
     */
    protected function getLayoutTargetType(string $layoutsPath, string $layoutFile): ?string
    {
        // 슬래시로 통일하여 상대 경로 계산
        $normalizedBase = rtrim(str_replace('\\', '/', $layoutsPath), '/').'/';
        $normalizedFile = str_replace('\\', '/', $layoutFile);

        $relativePath = str_replace($normalizedBase, '', $normalizedFile);

        // 첫 번째 디렉토리 세그먼트 추출
        $segments = explode('/', $relativePath);

        if (count($segments) <= 1) {
            // 루트에 직접 위치한 파일 (디렉토리 없음)
            Log::warning("레이아웃 파일이 admin/ 또는 user/ 하위에 위치하지 않아 스킵됩니다: {$layoutFile}");

            return null;
        }

        $firstSegment = $segments[0];

        if ($firstSegment === 'admin') {
            return 'admin';
        }

        if ($firstSegment === 'user') {
            return 'user';
        }

        // admin/user 외 디렉토리 (예: api/)
        Log::warning("레이아웃 파일이 admin/ 또는 user/ 하위에 위치하지 않아 스킵됩니다: {$layoutFile}");

        return null;
    }

    /**
     * 레이아웃 디렉토리에서 모든 JSON 파일을 재귀적으로 스캔합니다.
     *
     * @param  string  $basePath  스캔할 기본 경로
     * @return array<string> 레이아웃 파일 경로 배열
     */
    protected function scanLayoutFilesRecursively(string $basePath): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'json') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * 등록할 레이아웃 소스를 수집합니다 — `.g7.html`(있으면 우선) + 짝 `.g7.html` 이 없는 `.json`.
     *
     * 같은 논리 레이아웃에 `.g7.html` 과 `.json` 이 나란히 있으면(병존) 저작 SSoT 인 `.g7.html` 을
     * 소스로 삼는다. `.g7.html` 은 설치 시점 메모리 컴파일로 등록 content 를 만들고(디스크 산출
     * JSON 미기록), `.json` 은 직접 등록한다.
     *
     * `config('template.layout_markup.install_source') === 'json'`(커맨드 `--layout-source=json`) 이면
     * 우선순위가 뒤집혀 짝 `.json` 이 있는 마크업을 건너뛴다. 짝이 없는 마크업 전용 레이아웃은
     * 대안이 없으므로 그대로 남긴다 — 조용히 누락시키면 등록 자체가 사라져 빈 화면이 되기 때문이다.
     *
     * @param  string  $layoutsPath  레이아웃 디렉토리 경로
     * @param  bool  $recursive  재귀 스캔 여부 (본 레이아웃 스캔과 동일 전략)
     * @return array<int, array{path: string, markup: bool}> 소스 디스크립터 목록
     */
    protected function collectLayoutSources(string $layoutsPath, bool $recursive): array
    {
        // `.json` 목록 (본 레이아웃 스캔 전략)
        if ($recursive) {
            $jsonFiles = $this->scanLayoutFilesRecursively($layoutsPath);
        } else {
            $jsonFiles = File::glob("{$layoutsPath}/*.json") ?: [];

            $errorsPath = "{$layoutsPath}/errors";
            if (File::exists($errorsPath)) {
                $jsonFiles = array_merge($jsonFiles, File::glob("{$errorsPath}/*.json") ?: []);
            }
        }

        // 짝 판별·우선순위는 규약 SSoT 가 수행한다 (사본 금지 — ResolvesLayoutInstallSource 참조).
        return $this->mergeLayoutSourcesByPriority(
            $this->scanMarkupLayoutFiles($layoutsPath, $recursive),
            $jsonFiles,
            '레이아웃'
        );
    }

    /**
     * 설치 대상 레이아웃 개수를 파일 시스템에서 직접 셉니다 (조각 제외).
     *
     * 미설치 확장의 상세 화면이 "레이아웃 N건" 을 보여줄 때 쓴다. 소스 수집은 설치 경로와 같은
     * 규약({@see collectLayoutSources()})이라 **마크업 전용 확장도 잡히고**, 짝이 병존하면 같은 논리
     * 레이아웃이므로 규약이 마크업 하나만 남겨 중복 계상도 없다. 종전에는 모듈·플러그인이 각자
     * `.json` 만 훑는 사본을 갖고 있어 마크업 전용 확장이 **0건으로 보였다**.
     *
     * 개수만 필요하므로 마크업은 컴파일하지 않고 `<partial>` 루트 여부로 조각을 가른다 — 여기서
     * 전 조각을 컴파일하면 목록 화면 한 번이 설치 한 번만큼 무거워진다.
     *
     * @param  string  $layoutsPath  레이아웃 디렉토리 경로
     * @param  bool  $recursive  재귀 스캔 여부
     * @return int 조각을 제외한 레이아웃 개수
     */
    protected function countInstallableLayouts(string $layoutsPath, bool $recursive = true): int
    {
        if (! File::exists($layoutsPath)) {
            return 0;
        }

        $count = 0;

        foreach ($this->collectLayoutSources($layoutsPath, $recursive) as $source) {
            try {
                $content = File::get($source['path']);

                if ($source['markup']) {
                    // 조각 저작본은 `<partial>` 루트다 (본 레이아웃은 `<layout>`).
                    if (preg_match('/<\s*partial[\s>\/]/i', $content) === 1) {
                        continue;
                    }

                    $count++;

                    continue;
                }

                $layoutData = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }

                // is_partial 이 true 면 제외
                if (isset($layoutData['meta']['is_partial']) && $layoutData['meta']['is_partial'] === true) {
                    continue;
                }

                $count++;
            } catch (\Exception $e) {
                // 파일 읽기 실패 시 스킵
                continue;
            }
        }

        return $count;
    }

    /**
     * `.g7.html` 저작 파일을 설치 시점에 메모리로 컴파일합니다 (강검증 포함, 디스크 미기록).
     *
     * 경로에서 대상 템플릿 레지스트리·문맥을 끌어와(`MarkupCompileContextResolver`) 컴파일러 파사드로
     * 토큰→트리→문서→강검증 사슬을 태운다. 산출 JSON 은 곧바로 등록 content 로 쓰이고 파일로 쓰이지
     * 않는다.
     *
     * @param  string  $markupFile  `.g7.html` 절대 경로
     * @return array<string, mixed> 컴파일된 JSON 레이아웃
     *
     * @throws CompileError 컴파일·강검증 실패 시
     * @throws \RuntimeException 대상 템플릿 컴포넌트 매니페스트를 찾지 못하면
     */
    protected function compileMarkupLayoutFile(string $markupFile): array
    {
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

        return $result->json;
    }

    /**
     * 레이아웃 디렉토리에서 `.g7.html` 저작 파일을 스캔합니다 (본 레이아웃 스캔과 동일 전략).
     *
     * @param  string  $layoutsPath  스캔할 기본 경로
     * @param  bool  $recursive  재귀 스캔 여부
     * @return array<int, string> `.g7.html` 파일 경로 배열
     */
    protected function scanMarkupLayoutFiles(string $layoutsPath, bool $recursive): array
    {
        if ($recursive) {
            $files = [];

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($layoutsPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with(strtolower($file->getFilename()), '.g7.html')) {
                    $files[] = $file->getPathname();
                }
            }

            return $files;
        }

        $files = File::glob("{$layoutsPath}/*.g7.html") ?: [];

        $errorsPath = "{$layoutsPath}/errors";
        if (File::exists($errorsPath)) {
            $files = array_merge($files, File::glob("{$errorsPath}/*.g7.html") ?: []);
        }

        return $files;
    }

    /**
     * 파일 경로를 기반으로 레이아웃 이름을 생성합니다.
     *
     * @param  string  $basePath  레이아웃 기본 경로
     * @param  string  $filePath  레이아웃 파일 경로
     * @param  string  $identifier  소스 식별자
     * @return string 생성된 레이아웃 이름
     */
    protected function generateLayoutNameFromPath(string $basePath, string $filePath, string $identifier): string
    {
        // 상대 경로 추출 (basePath 기준)
        $relativePath = str_replace($basePath.DIRECTORY_SEPARATOR, '', $filePath);

        // .g7.html / .json 확장자 제거 (저작 소스·산출 어느 쪽이든 같은 논리 이름)
        $relativePath = preg_replace('/\.(g7\.html|json)$/i', '', $relativePath);

        // 디렉토리 구분자를 언더스코어로 변환
        $layoutPath = str_replace([DIRECTORY_SEPARATOR, '/'], '_', $relativePath);

        // 레이아웃 이름 생성: {identifier}_{path}
        return $identifier.'_'.$layoutPath;
    }

    /**
     * 레이아웃 데이터 내의 모든 partial을 재귀적으로 해석합니다.
     *
     * @param  array  $data  레이아웃 데이터
     * @param  string  $basePath  기준 경로 (현재 레이아웃 파일의 디렉토리)
     * @param  int  $depth  현재 partial 깊이
     * @param  array  $dataSources  메인 레이아웃의 data_sources (partial 파일에서 참조 가능)
     * @return array partial이 모두 해석된 데이터
     *
     * @throws LayoutIncludeException
     */
    protected function resolveAllPartials(array $data, string $basePath, int $depth = 0, array $dataSources = []): array
    {
        // 1. 깊이 제한 검증
        $maxDepth = config('template.layout.max_inheritance_depth', 10);
        if ($depth > $maxDepth) {
            throw new LayoutIncludeException(
                __('exceptions.layout.max_include_depth_exceeded', ['max' => $maxDepth]),
                null,
                $this->partialStack
            );
        }

        // 2. partial 키가 있으면 처리 (배열 항목 치환)
        if (isset($data['partial']) && is_string($data['partial'])) {
            return $this->resolvePartial($data['partial'], $basePath, $depth, $dataSources);
        }

        // 3. 배열의 각 요소를 재귀적으로 처리
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->resolveAllPartials($value, $basePath, $depth, $dataSources);
            }
        }

        return $data;
    }

    /**
     * 단일 partial을 해석하여 실제 데이터를 반환합니다.
     *
     * 소스는 저작 SSoT(`.g7.html`)를 우선하고 짝이 없으면 `.json` 을 읽는다 —
     * 본 레이아웃({@see collectLayoutSources()})·fragment({@see CollectsFragmentSources})와 같은
     * 우선순위 규약이며, `--layout-source=json` 이면 셋 다 같은 방향으로 뒤집힌다.
     *
     * 실패는 **전부 예외로 표면화한다**. 종전에는 네 갈래(경로 해석·순환 참조·파일 부재·JSON 파싱)가
     * 모두 `createPartialErrorComponent()` 로 빠졌고 그것이 `app.debug=false` 에서 빈 배열이라,
     * 설치는 성공한 채 DB content 에 구멍이 굽히고 화면 일부가 흔적 없이 사라졌다. 조각을 저작본으로
     * 읽게 만들면서 이 침묵을 함께 걷어낸다 — 남겨 두면 다음 결함도 같은 방식으로 숨는다.
     *
     * @param  string  $partialPath  partial 경로
     * @param  string  $basePath  기준 경로
     * @param  int  $depth  현재 깊이
     * @param  array  $dataSources  메인 레이아웃의 data_sources
     * @return array 로드된 데이터
     *
     * @throws LayoutIncludeException 경로 해석·순환 참조·파일 부재·컴파일/파싱 실패 시
     */
    protected function resolvePartial(string $partialPath, string $basePath, int $depth, array $dataSources = []): array
    {
        // 1. 저작 SSoT 우선으로 소스를 고르고 절대 경로로 변환 (layouts 디렉토리 이탈은 여기서 예외).
        $source = $this->resolvePartialSource($partialPath, $basePath);
        $absolutePath = $source['path'];

        // 2. 순환 참조 감지 (자기 자신 재귀 참조는 허용 - 대댓글 등 데이터 기반 재귀 구조 지원)
        // 자기 자신을 참조하는 경우: 이전 항목이 자신과 같으면 재귀 구조이므로 허용
        // 다른 파일을 통한 순환 참조(A→B→A)만 에러로 처리
        $stackCount = count($this->partialStack);
        $isRecursiveSelfReference = $stackCount > 0 && $this->partialStack[$stackCount - 1] === $absolutePath;

        if (in_array($absolutePath, $this->partialStack, true) && ! $isRecursiveSelfReference) {
            $trace = implode(' → ', $this->partialStack)." → {$absolutePath}";

            throw new LayoutIncludeException(
                __('exceptions.layout.circular_include', ['trace' => $trace]),
                $partialPath,
                $this->partialStack
            );
        }

        // 자기 자신 재귀 참조인 경우: 더 이상 재귀하지 않고 partial 참조 그대로 반환
        // 런타임에 프론트엔드에서 데이터 기반으로 렌더링됨
        if ($isRecursiveSelfReference) {
            return [
                'partial' => $partialPath,
                '_recursive' => true,
            ];
        }

        // 3. 파일 존재 여부 확인 (두 확장자 후보 모두 부재)
        if (! $source['exists']) {
            throw new LayoutIncludeException(
                __('exceptions.layout.include_file_not_found', [
                    'path' => $partialPath,
                    'resolved' => $absolutePath,
                ]),
                $partialPath,
                $this->partialStack
            );
        }

        // 4. 스택에 추가
        $this->partialStack[] = $absolutePath;

        try {
            // 5. 소스 로드 — 마크업은 메모리 컴파일(디스크 미기록), `.json` 은 직접 파싱.
            $data = $source['markup']
                ? $this->compilePartialMarkupFile($absolutePath, $partialPath)
                : $this->decodePartialJsonFile($absolutePath, $partialPath);

            // 6. 로드된 데이터 내부의 partial도 재귀적으로 처리
            $newBasePath = dirname($absolutePath);

            return $this->resolveAllPartials($data, $newBasePath, $depth + 1, $dataSources);
        } finally {
            array_pop($this->partialStack);
        }
    }

    /**
     * 조각 참조의 실제 소스를 고릅니다 — 저작 SSoT(`.g7.html`) 우선, 없으면 짝 `.json`.
     *
     * `src` 가 `.json` 이든 `.g7.html` 이든 두 확장자를 모두 후보로 세우므로, 저작본만 있는 신규
     * 조각도(짝 `.json` 을 굽지 않은 상태) 그대로 해석된다. `--layout-source=json` 이면 후보 순서가
     * 뒤집히고, 그때 짝 `.json` 이 없어 마크업으로 떨어지면 미이행을 로그로 남긴다 — 조용히
     * 누락시키면 등록 자체가 사라져 빈 화면이 되기 때문이다(`collectLayoutSources` 와 같은 규약).
     *
     * @param  string  $partialPath  `<include src>` 값
     * @param  string  $basePath  기준 경로
     * @return array{path: string, markup: bool, exists: bool} 소스 디스크립터 (부재 시 1순위 후보 경로)
     *
     * @throws LayoutIncludeException layouts 디렉토리를 벗어나는 참조일 때
     */
    protected function resolvePartialSource(string $partialPath, string $basePath): array
    {
        $jsonForced = $this->layoutInstallSourceIsJsonForced();
        $primary = null;

        foreach ($this->layoutSourceCandidates($partialPath) as $candidate) {
            // 보안 검증(layouts 디렉토리 내부)은 후보마다 그대로 통과시킨다.
            $absolutePath = $this->resolvePartialPath($candidate['path'], $basePath);

            $primary ??= ['path' => $absolutePath, 'markup' => $candidate['markup'], 'exists' => false];

            if (! file_exists($absolutePath)) {
                continue;
            }

            if ($jsonForced && $candidate['markup']) {
                Log::warning('조각 소스 JSON 강제를 이행하지 못했습니다 (짝 .json 부재 — 마크업 컴파일로 대체)', [
                    'partial' => $partialPath,
                    'markup' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $absolutePath),
                ]);
            }

            return ['path' => $absolutePath, 'markup' => $candidate['markup'], 'exists' => true];
        }

        return $primary;
    }

    /**
     * 조각 저작본을 컴파일합니다 — 같은 설치 실행 안에서는 파일당 한 번만 컴파일합니다.
     *
     * 조각 451건이 485회 참조되므로(부모마다 다시 컴파일하면 설치가 그만큼 느려진다) 결과를
     * 경로+mtime+size 키로 재사용한다. 산출은 인라인 전 컴파일 결과이고 `resolveAllPartials` 는
     * 호출마다 새로 적용되므로, 같은 조각이 서로 다른 부모에 들어가도 결과가 섞이지 않는다.
     *
     * @param  string  $absolutePath  조각 `.g7.html` 절대 경로
     * @param  string  $partialPath  오류 메시지에 쓸 원본 참조 값
     * @return array<string, mixed> 컴파일된 조각 JSON
     *
     * @throws LayoutIncludeException 컴파일·강검증 실패 시
     */
    private function compilePartialMarkupFile(string $absolutePath, string $partialPath): array
    {
        $cacheKey = $absolutePath.'|'.(@filemtime($absolutePath) ?: 0).'|'.(@filesize($absolutePath) ?: 0);

        if (isset(self::$partialCompileCache[$cacheKey])) {
            return self::$partialCompileCache[$cacheKey];
        }

        try {
            $compiled = $this->compileMarkupLayoutFile($absolutePath);
        } catch (CompileError $e) {
            throw new LayoutIncludeException(
                __('exceptions.layout.invalid_include_markup', [
                    'path' => $partialPath,
                    'error' => $e->getDetail(),
                ]),
                $partialPath,
                $this->partialStack
            );
        } catch (\RuntimeException $e) {
            throw new LayoutIncludeException(
                __('exceptions.layout.invalid_include_markup', [
                    'path' => $partialPath,
                    'error' => $e->getMessage(),
                ]),
                $partialPath,
                $this->partialStack
            );
        }

        return self::$partialCompileCache[$cacheKey] = $compiled;
    }

    /**
     * 조각 `.json` 을 파싱합니다.
     *
     * @param  string  $absolutePath  조각 `.json` 절대 경로
     * @param  string  $partialPath  오류 메시지에 쓸 원본 참조 값
     * @return array<string, mixed> 파싱된 조각 JSON
     *
     * @throws LayoutIncludeException 파싱 실패 시
     */
    private function decodePartialJsonFile(string $absolutePath, string $partialPath): array
    {
        $data = json_decode((string) file_get_contents($absolutePath), true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            throw new LayoutIncludeException(
                __('exceptions.layout.invalid_include_json', [
                    'path' => $partialPath,
                    'error' => json_last_error_msg(),
                ]),
                $partialPath,
                $this->partialStack
            );
        }

        return $data;
    }

    /**
     * partial 경로를 절대 경로로 변환하고 보안 검증을 수행합니다.
     *
     * @param  string  $partialPath  partial 경로
     * @param  string  $basePath  기준 경로
     * @return string 절대 경로
     *
     * @throws LayoutIncludeException
     */
    protected function resolvePartialPath(string $partialPath, string $basePath): string
    {
        // 1. 먼저 현재 basePath 기준으로 상대 경로 시도
        $absolutePath = $basePath.DIRECTORY_SEPARATOR.$partialPath;

        // 2. partials/로 시작하고 basePath 기준 파일이 없으면 layouts 루트 기준으로 fallback
        if (str_starts_with($partialPath, 'partials/') || str_starts_with($partialPath, 'partials\\')) {
            if (! file_exists($absolutePath)) {
                $layoutsDir = $this->getLayoutsBaseDirectory($basePath);
                $absolutePath = $layoutsDir.DIRECTORY_SEPARATOR.$partialPath;
            }
        }

        // realpath로 실제 경로 확인
        $realPath = realpath($absolutePath);

        // 2. realpath 실패 시 수동 정규화
        if ($realPath === false) {
            $realPath = $this->normalizeFilePath($absolutePath);
        }

        // 3. 보안 검증: layouts 디렉토리 내부인지 확인
        $layoutsDir = $this->getLayoutsBaseDirectory($basePath);

        // Windows/Linux 호환을 위해 경로 정규화
        $realPathNormalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $realPath);
        $layoutsDirNormalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $layoutsDir);

        if (! str_starts_with($realPathNormalized, $layoutsDirNormalized)) {
            throw new LayoutIncludeException(
                __('exceptions.layout.include_outside_directory', [
                    'path' => $partialPath,
                    'allowed_dir' => $layoutsDir,
                ]),
                $partialPath,
                $this->partialStack
            );
        }

        return $realPath;
    }

    /**
     * 경로를 정규화합니다 (.과 .. 처리).
     *
     * @param  string  $path  경로
     * @return string 정규화된 경로
     */
    protected function normalizeFilePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $parts = [];
        $segments = explode(DIRECTORY_SEPARATOR, $path);

        foreach ($segments as $segment) {
            if ($segment === '..' && count($parts) > 0) {
                array_pop($parts);
            } elseif ($segment !== '.' && $segment !== '') {
                $parts[] = $segment;
            }
        }

        return implode(DIRECTORY_SEPARATOR, $parts);
    }

    /**
     * 허용된 layouts 디렉토리를 추출합니다.
     *
     * @param  string  $basePath  기준 경로
     * @return string layouts 디렉토리 경로
     */
    protected function getLayoutsBaseDirectory(string $basePath): string
    {
        // 경로에서 **가장 바깥쪽 `layouts` 경계**까지를 루트로 본다.
        //
        // 종전에는 `templates/{name}/layouts` 처럼 세그먼트 수를 고정한 정규식이었는데, 실제 저작
        // 경로는 `_bundled` 가 한 단계 더 끼어 있어(`templates/_bundled/{name}/layouts`) 매칭에
        // 실패했다. 그러면 이 함수가 입력을 그대로 돌려주고, 그 위에서 도는 "`partials/…` 는 layouts
        // 루트 기준" 폴백이 **조용히 무효화**된다 — include 는 깨진 채 `app.debug=false` 에서
        // 빈 배열로 사라져 화면 일부가 흔적 없이 비었다. 활성 디렉토리는 경로 모양이 맞아떨어져
        // 프로덕션만 무사했고, `_bundled` 를 직접 읽는 도구·테스트에서만 어긋났다.
        //
        // 확장 유형·디렉토리 깊이를 가정하지 않으므로 `_bundled` 든 활성이든, 앞으로 계층이 하나 더
        // 생기든 같은 규칙으로 동작한다.
        $normalized = str_replace('\\', '/', $basePath);
        $segments = explode('/', $normalized);
        $index = array_search('layouts', $segments, true);

        if ($index === false) {
            return $basePath;
        }

        $rootSegments = array_slice($segments, 0, $index + 1);

        return str_replace('/', DIRECTORY_SEPARATOR, implode('/', $rootSegments));
    }

    // 조각 로드 실패용 "에러 표시 컴포넌트"(`createPartialErrorComponent`)는 지웠다.
    //
    // 그 함수는 `app.debug=true` 에서만 붉은 상자를 돌려주고 프로덕션에서는 **빈 배열**을 돌려줬는데,
    // 조각 해석은 설치 시점에만 도는 경로다(`validateLayoutFiles` · `GenerateLayoutTestFixture` 둘뿐).
    // 그래서 실패한 조각은 설치를 세우지 않고 DB content 에 구멍으로 구워졌고, 화면 일부가 흔적 없이
    // 비었다 — 이 결함 계열이 오래 보이지 않은 기전이 정확히 그것이다. 이제 네 갈래 실패가 모두
    // `LayoutIncludeException` 으로 올라와 설치 오류로 표면화되므로 반환할 대체 컴포넌트가 없다.
}
