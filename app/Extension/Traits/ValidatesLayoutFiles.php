<?php

namespace App\Extension\Traits;

use App\Exceptions\LayoutIncludeException;
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
    /**
     * Partial 순환 참조 추적용 스택
     */
    private array $partialStack = [];

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

        // 레이아웃 파일 스캔
        if ($recursive) {
            $layoutFiles = $this->scanLayoutFilesRecursively($layoutsPath);
        } else {
            // 루트 디렉토리의 JSON 파일만 스캔
            $layoutFiles = File::glob("{$layoutsPath}/*.json");

            // errors/ 디렉토리만 추가 스캔 (에러 페이지 레이아웃용)
            $errorsPath = "{$layoutsPath}/errors";
            if (File::exists($errorsPath)) {
                $errorLayoutFiles = File::glob("{$errorsPath}/*.json");
                $layoutFiles = array_merge($layoutFiles, $errorLayoutFiles);
            }
        }

        if (empty($layoutFiles)) {
            return $validatedLayouts;
        }

        foreach ($layoutFiles as $layoutFile) {
            $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $layoutFile);
            // Windows/Linux 호환을 위해 슬래시로 통일
            $relativePath = str_replace('\\', '/', $relativePath);

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

        // .json 확장자 제거
        $relativePath = preg_replace('/\.json$/i', '', $relativePath);

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
     * @param  string  $partialPath  partial 경로
     * @param  string  $basePath  기준 경로
     * @param  int  $depth  현재 깊이
     * @param  array  $dataSources  메인 레이아웃의 data_sources
     * @return array 로드된 데이터
     *
     * @throws LayoutIncludeException
     */
    protected function resolvePartial(string $partialPath, string $basePath, int $depth, array $dataSources = []): array
    {
        try {
            // 1. 상대 경로를 절대 경로로 변환
            $absolutePath = $this->resolvePartialPath($partialPath, $basePath);
        } catch (LayoutIncludeException $e) {
            // 경로 해석 실패 시 로그만 남기고 에러 표시 컴포넌트 반환
            Log::warning('Partial 경로 해석 실패', [
                'partial_path' => $partialPath,
                'base_path' => $basePath,
                'error' => $e->getMessage(),
            ]);

            return $this->createPartialErrorComponent($partialPath, $e->getMessage());
        }

        // 2. 순환 참조 감지 (자기 자신 재귀 참조는 허용 - 대댓글 등 데이터 기반 재귀 구조 지원)
        // 자기 자신을 참조하는 경우: 이전 항목이 자신과 같으면 재귀 구조이므로 허용
        // 다른 파일을 통한 순환 참조(A→B→A)만 에러로 처리
        $stackCount = count($this->partialStack);
        $isRecursiveSelfReference = $stackCount > 0 && $this->partialStack[$stackCount - 1] === $absolutePath;

        if (in_array($absolutePath, $this->partialStack, true) && ! $isRecursiveSelfReference) {
            $trace = implode(' → ', $this->partialStack)." → {$absolutePath}";
            $errorMessage = __('exceptions.layout.circular_include', ['trace' => $trace]);

            Log::warning('Partial 순환 참조 감지', [
                'partial_path' => $partialPath,
                'trace' => $trace,
            ]);

            return $this->createPartialErrorComponent($partialPath, $errorMessage);
        }

        // 자기 자신 재귀 참조인 경우: 더 이상 재귀하지 않고 partial 참조 그대로 반환
        // 런타임에 프론트엔드에서 데이터 기반으로 렌더링됨
        if ($isRecursiveSelfReference) {
            return [
                'partial' => $partialPath,
                '_recursive' => true,
            ];
        }

        // 3. 파일 존재 여부 확인
        if (! file_exists($absolutePath)) {
            $errorMessage = __('exceptions.layout.include_file_not_found', [
                'path' => $partialPath,
                'resolved' => $absolutePath,
            ]);

            Log::warning('Partial 파일을 찾을 수 없음', [
                'partial_path' => $partialPath,
                'resolved_path' => $absolutePath,
            ]);

            return $this->createPartialErrorComponent($partialPath, $errorMessage);
        }

        // 4. 스택에 추가
        $this->partialStack[] = $absolutePath;

        try {
            // 5. JSON 파일 로드
            $json = file_get_contents($absolutePath);
            $data = json_decode($json, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $errorMessage = __('exceptions.layout.invalid_include_json', [
                    'path' => $partialPath,
                    'error' => json_last_error_msg(),
                ]);

                Log::warning('Partial JSON 파싱 실패', [
                    'partial_path' => $partialPath,
                    'error' => json_last_error_msg(),
                ]);

                return $this->createPartialErrorComponent($partialPath, $errorMessage);
            }

            // 6. 로드된 데이터 내부의 partial도 재귀적으로 처리
            $newBasePath = dirname($absolutePath);
            $data = $this->resolveAllPartials($data, $newBasePath, $depth + 1, $dataSources);

            return $data;
        } finally {
            array_pop($this->partialStack);
        }
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
        // layouts 디렉토리 또는 그 하위 디렉토리까지만 허용
        if (str_contains($basePath, 'modules')) {
            // modules/{name}/resources/layouts
            if (preg_match('#(.*modules[\\\\/][^\\\\/]+[\\\\/]resources[\\\\/]layouts)#', $basePath, $matches)) {
                return $matches[1];
            }
        } elseif (str_contains($basePath, 'plugins')) {
            // plugins/{name}/resources/layouts
            if (preg_match('#(.*plugins[\\\\/][^\\\\/]+[\\\\/]resources[\\\\/]layouts)#', $basePath, $matches)) {
                return $matches[1];
            }
        } elseif (str_contains($basePath, 'templates')) {
            // templates/{name}/layouts
            if (preg_match('#(.*templates[\\\\/][^\\\\/]+[\\\\/]layouts)#', $basePath, $matches)) {
                return $matches[1];
            }
        }

        return $basePath;
    }

    /**
     * Partial 파일 로드 실패 시 화면에 표시할 에러 컴포넌트를 생성합니다.
     *
     * @param  string  $partialPath  Partial 경로
     * @param  string  $errorMessage  에러 메시지
     * @return array 에러 표시용 컴포넌트 배열
     */
    protected function createPartialErrorComponent(string $partialPath, string $errorMessage): array
    {
        // 개발 환경에서만 에러 표시
        if (! config('app.debug', false)) {
            return []; // 프로덕션에서는 빈 배열 반환 (아무것도 렌더링 안 함)
        }

        return [
            'type' => 'basic',
            'name' => 'Div',
            'props' => [
                'className' => 'p-4 my-2 bg-red-50 dark:bg-red-900 border-red-300 dark:border-red-600 rounded-lg',
            ],
            'children' => [
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'props' => [
                        'className' => 'flex items-center gap-2 mb-2',
                    ],
                    'children' => [
                        [
                            'type' => 'basic',
                            'name' => 'Span',
                            'props' => [
                                'className' => 'text-xl',
                            ],
                            'text' => '⚠️',
                        ],
                        [
                            'type' => 'basic',
                            'name' => 'Span',
                            'props' => [
                                'className' => 'font-bold text-red-900 dark:text-red-100',
                            ],
                            'text' => 'Partial Error',
                        ],
                    ],
                ],
                [
                    'type' => 'basic',
                    'name' => 'P',
                    'props' => [
                        'className' => 'text-sm text-red-700 dark:text-red-300 mb-1',
                    ],
                    'text' => 'Path: '.$partialPath,
                ],
                [
                    'type' => 'basic',
                    'name' => 'P',
                    'props' => [
                        'className' => 'text-sm text-red-600 dark:text-red-400',
                    ],
                    'text' => 'Error: '.$errorMessage,
                ],
            ],
        ];
    }
}
