<?php

/**
 * G7 인스톨러 - 확장 기능 스캔 API
 *
 * _bundled 디렉토리에서 템플릿, 모듈, 플러그인을 스캔하여 설치 가능한 확장 목록을 반환합니다.
 * 모든 확장의 메타데이터는 JSON 매니페스트 파일(template.json, module.json, plugin.json)에서 읽습니다.
 *
 * @response {
 *   "success": true,
 *   "data": {
 *     "admin_templates": [...],
 *     "user_templates": [...],
 *     "modules": [...],
 *     "plugins": [...]
 *   }
 * }
 */

// 라이브러리 모드 — 단위 테스트가 함수 정의만 로드할 때 메인 실행을 건너뛴다.
// 테스트는 BASE_PATH 를 직접 정의한 뒤 SCAN_EXTENSIONS_LIBRARY=true 로 require.
$scanExtensionsLibraryMode = defined('SCAN_EXTENSIONS_LIBRARY') && constant('SCAN_EXTENSIONS_LIBRARY');

if (! $scanExtensionsLibraryMode) {
    // 기본 설정
    header('Content-Type: application/json; charset=utf-8');

    // 프로젝트 루트 경로
    if (! defined('BASE_PATH')) {
        define('BASE_PATH', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
    }

    // 세션 및 설정 포함 (Laravel autoload 없이 독립 실행)
    // 참고: 이 API는 Laravel 부팅 없이 디렉토리 스캔만 수행
    require_once dirname(__DIR__).'/includes/session.php';
    require_once dirname(__DIR__).'/includes/config.php';

    require_once __DIR__.'/_guard.php';
    installer_guard_or_410();
}

// 확장 아이콘 검증기 적재 — Laravel autoload 없이 프로젝트 안 고정 경로에서 require 한다.
// 검증기는 의존성 0 의 순수 PHP 라 여기서 바로 쓸 수 있다. 부재해도 죽지 않고
// 아이콘만 빠진다(카드는 종전대로 유형 글리프).
//
// 기준점은 BASE_PATH 가 아니라 **이 스크립트 자신의 위치**다. BASE_PATH 는 "무엇을
// 스캔할지" 를 가리키므로 테스트가 격리 디렉터리로 바꿔 놓는다 — 검증기는 코드와
// 함께 있지 스캔 대상 안에 있지 않다.
//
// 클래스명을 FQN 으로 쓰는 것은 의도다 — `use` 문을 두면 오토로더가 없는 인스톨러
// 실행 시점에 그 심볼을 해석할 수 없다. pint 의 `fully_qualified_strict_types` 가
// 이것을 `use` 로 끌어올리려 하므로 `pint.json` 의 `notPath` 로 이 파일만 제외한다.
if (! class_exists(\App\Support\ExtensionIcon\ExtensionIconValidator::class, false)) {
    $g7IconValidatorPath = dirname(__DIR__, 3).'/app/Support/ExtensionIcon/ExtensionIconValidator.php';
    if (is_file($g7IconValidatorPath)) {
        require_once $g7IconValidatorPath;
    }
    unset($g7IconValidatorPath);
}

/**
 * 확장 디렉토리의 아이콘을 data URI 로 만듭니다.
 *
 * 인스톨러는 Laravel 없이 도는 화면이라 아이콘 서빙 라우트를 쓸 수 없습니다. 대신
 * 검증기를 통과한 바이트만 base64 로 카드에 실어 보냅니다. 검증기가 없거나 규격을
 * 어기면 null 이고, 카드는 종전대로 유형 글리프를 그립니다.
 *
 * @param  string  $extensionDir  확장 루트 절대 경로
 * @param  array  $manifest  매니페스트 데이터
 * @return string|null data URI 문자열, 유효한 아이콘이 없으면 null
 */
function extractExtensionIconDataUri(string $extensionDir, array $manifest): ?string
{
    $validator = \App\Support\ExtensionIcon\ExtensionIconValidator::class;

    if (! class_exists($validator, false)) {
        return null;
    }

    $candidates = [];

    $declared = $manifest['icon'] ?? null;
    if (is_string($declared) && trim($declared) !== ''
        && $validator::manifestPathViolation($declared) === null) {
        $resolved = $validator::resolveInsideRoot($extensionDir, $declared);
        if ($resolved !== null) {
            $candidates[] = $resolved;
        }
    }

    if ($candidates === []) {
        foreach ($validator::CONVENTION_CANDIDATES as $relative) {
            $resolved = $validator::resolveInsideRoot($extensionDir, $relative);
            if ($resolved !== null) {
                $candidates[] = $resolved;
                break;
            }
        }
    }

    foreach ($candidates as $path) {
        $result = $validator::validateFile($path);
        if (! $result['valid']) {
            continue;
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            continue;
        }

        $mime = $validator::MIME_BY_EXTENSION[$result['format']] ?? null;
        if ($mime === null) {
            continue;
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    return null;
}

/**
 * 템플릿 디렉토리 스캔
 *
 * _bundled 디렉토리에서 template.json을 읽어 템플릿 정보를 수집합니다.
 *
 * @return array ['admin' => [...], 'user' => [...]]
 */
function scanTemplates(): array
{
    $templatesDir = BASE_PATH.'/templates/_bundled';
    $result = [
        'admin' => [],
        'user' => [],
    ];

    if (! is_dir($templatesDir)) {
        return $result;
    }

    $dirs = scandir($templatesDir);
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') {
            continue;
        }

        $templateJsonPath = $templatesDir.'/'.$dir.'/template.json';
        if (! file_exists($templateJsonPath)) {
            continue;
        }

        $content = @file_get_contents($templateJsonPath);
        if ($content === false) {
            continue;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
            continue;
        }

        // hidden 매니페스트는 인스톨러에서 제외 (학습용 샘플 등)
        if (! empty($data['hidden'])) {
            continue;
        }

        // 필수 필드 확인
        $identifier = $data['identifier'] ?? $dir;
        $name = $data['name'] ?? ['ko' => $dir, 'en' => $dir];
        $version = $data['version'] ?? '1.0.0';
        $description = $data['description'] ?? ['ko' => '', 'en' => ''];
        $type = $data['type'] ?? 'user'; // admin 또는 user
        $author = $data['author']['name'] ?? $data['vendor'] ?? null;

        // dependencies 처리: 객체 형태면 모듈/플러그인 키를 배열로 변환
        $dependencies = extractDependenciesFromJson($data);
        $dependenciesDetailed = extractDependenciesDetailedFromJson($data);

        $templateInfo = [
            'identifier' => $identifier,
            'name' => $name,
            'version' => $version,
            'description' => $description,
            'type' => $type,
            'dependencies' => $dependencies,
            'dependencies_detailed' => $dependenciesDetailed,
            'author' => $author,
            'directory' => $dir,
            'icon_data_uri' => extractExtensionIconDataUri($templatesDir.'/'.$dir, $data),
        ];

        if ($type === 'admin') {
            $result['admin'][] = $templateInfo;
        } else {
            $result['user'][] = $templateInfo;
        }
    }

    return $result;
}

/**
 * 모듈 디렉토리 스캔
 *
 * _bundled 디렉토리에서 module.json을 읽어 모듈 정보를 수집합니다.
 *
 * @return array 모듈 목록
 */
function scanModules(): array
{
    $modulesDir = BASE_PATH.'/modules/_bundled';
    $result = [];

    if (! is_dir($modulesDir)) {
        return $result;
    }

    $dirs = scandir($modulesDir);
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') {
            continue;
        }

        // module.json 매니페스트에서 메타데이터 읽기
        $moduleJsonPath = $modulesDir.'/'.$dir.'/module.json';
        if (! file_exists($moduleJsonPath)) {
            // module.json이 없으면 module.php만으로 식별 (역호환)
            $modulePhpPath = $modulesDir.'/'.$dir.'/module.php';
            if (! file_exists($modulePhpPath)) {
                continue;
            }
            // JSON이 없는 경우 기본값으로 등록
            $result[] = [
                'identifier' => $dir,
                'name' => ['ko' => $dir, 'en' => $dir],
                'version' => '1.0.0',
                'description' => ['ko' => '', 'en' => ''],
                'dependencies' => [],
                'author' => null,
                'directory' => $dir,
                // 매니페스트가 없는 역호환 분기 — 아이콘 선언을 읽을 근거가 없다.
                'icon_data_uri' => null,
            ];

            continue;
        }

        $content = @file_get_contents($moduleJsonPath);
        if ($content === false) {
            continue;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
            continue;
        }

        // hidden 매니페스트는 인스톨러에서 제외 (학습용 샘플 등)
        if (! empty($data['hidden'])) {
            continue;
        }

        $dependencies = extractDependenciesFromJson($data);
        $dependenciesDetailed = extractDependenciesDetailedFromJson($data);

        $result[] = [
            'identifier' => $data['identifier'] ?? $dir,
            'name' => $data['name'] ?? ['ko' => $dir, 'en' => $dir],
            'version' => $data['version'] ?? '1.0.0',
            'description' => $data['description'] ?? ['ko' => '', 'en' => ''],
            'dependencies' => $dependencies,
            'dependencies_detailed' => $dependenciesDetailed,
            'author' => $data['vendor'] ?? null,
            'directory' => $dir,
            'icon_data_uri' => extractExtensionIconDataUri($modulesDir.'/'.$dir, $data),
        ];
    }

    return $result;
}

/**
 * 플러그인 디렉토리 스캔
 *
 * _bundled 디렉토리에서 plugin.json을 읽어 플러그인 정보를 수집합니다.
 *
 * @return array 플러그인 목록
 */
function scanPlugins(): array
{
    $pluginsDir = BASE_PATH.'/plugins/_bundled';
    $result = [];

    if (! is_dir($pluginsDir)) {
        return $result;
    }

    $dirs = scandir($pluginsDir);
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') {
            continue;
        }

        // plugin.json 매니페스트에서 메타데이터 읽기
        $pluginJsonPath = $pluginsDir.'/'.$dir.'/plugin.json';
        if (! file_exists($pluginJsonPath)) {
            // plugin.json이 없으면 plugin.php만으로 식별 (역호환)
            $pluginPhpPath = $pluginsDir.'/'.$dir.'/plugin.php';
            if (! file_exists($pluginPhpPath)) {
                continue;
            }
            // JSON이 없는 경우 기본값으로 등록
            $result[] = [
                'identifier' => $dir,
                'name' => ['ko' => $dir, 'en' => $dir],
                'version' => '1.0.0',
                'description' => ['ko' => '', 'en' => ''],
                'dependencies' => [],
                'author' => null,
                'directory' => $dir,
                // 매니페스트가 없는 역호환 분기 — 아이콘 선언을 읽을 근거가 없다.
                'icon_data_uri' => null,
            ];

            continue;
        }

        $content = @file_get_contents($pluginJsonPath);
        if ($content === false) {
            continue;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
            continue;
        }

        // hidden 매니페스트는 인스톨러에서 제외 (학습용 샘플 등)
        if (! empty($data['hidden'])) {
            continue;
        }

        $dependencies = extractDependenciesFromJson($data);
        $dependenciesDetailed = extractDependenciesDetailedFromJson($data);

        $result[] = [
            'identifier' => $data['identifier'] ?? $dir,
            'name' => $data['name'] ?? ['ko' => $dir, 'en' => $dir],
            'version' => $data['version'] ?? '1.0.0',
            'description' => $data['description'] ?? ['ko' => '', 'en' => ''],
            'dependencies' => $dependencies,
            'dependencies_detailed' => $dependenciesDetailed,
            'author' => $data['vendor'] ?? null,
            'directory' => $dir,
            'icon_data_uri' => extractExtensionIconDataUri($pluginsDir.'/'.$dir, $data),
        ];
    }

    return $result;
}

/**
 * 번들 모듈/플러그인/템플릿 매니페스트에서 hidden=true 식별자 목록을 수집합니다.
 *
 * 학습용 샘플 등 hidden 처리된 호스트 확장의 식별자를 모아, 그 확장에 종속된 언어팩도
 * 함께 인스톨러에서 제외할 수 있도록 합니다 (호스트 확장 hidden cascade).
 *
 * @return array<string> hidden 호스트 확장 식별자 목록
 */
function collectHiddenExtensionIdentifiers(): array
{
    $hidden = [];

    $sources = [
        'modules' => 'module.json',
        'plugins' => 'plugin.json',
        'templates' => 'template.json',
    ];

    foreach ($sources as $type => $manifestName) {
        $bundleDir = BASE_PATH.'/'.$type.'/_bundled';
        if (! is_dir($bundleDir)) {
            continue;
        }

        $entries = scandir($bundleDir);
        if ($entries === false) {
            continue;
        }

        foreach ($entries as $sub) {
            if ($sub === '.' || $sub === '..') {
                continue;
            }

            $manifestPath = $bundleDir.'/'.$sub.'/'.$manifestName;
            if (! file_exists($manifestPath)) {
                continue;
            }

            $content = @file_get_contents($manifestPath);
            if ($content === false) {
                continue;
            }

            $data = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
                continue;
            }

            if (! empty($data['hidden'])) {
                $hidden[] = $data['identifier'] ?? $sub;
            }
        }
    }

    return $hidden;
}

/**
 * 번들 언어팩 디렉토리 스캔
 *
 * `lang-packs/_bundled/*\/language-pack.json` 매니페스트를 직접 읽어 언어팩 목록을 수집합니다.
 * Laravel 부팅 없이 동작하며, 매칭/정렬은 프론트엔드에서 결정합니다.
 *
 * hidden 처리:
 *  - 매니페스트 자체에 hidden=true 가 있으면 제외 (직접 hidden)
 *  - 종속된 호스트 확장이 hidden 이면 함께 제외 (cascade hidden — 학습용 샘플 모듈/템플릿용 언어팩 자동 제외)
 *
 * 응답 항목 shape:
 *   identifier, directory, scope (core|module|plugin|template),
 *   target_identifier (확장 식별자 또는 null),
 *   locale, locale_name, locale_native_name, text_direction,
 *   name, description (다국어 객체 또는 string),
 *   version, author
 *
 * @return array 번들 언어팩 목록
 */
function scanLanguagePacks(): array
{
    $dir = BASE_PATH.'/lang-packs/_bundled';
    $result = [];

    if (! is_dir($dir)) {
        return $result;
    }

    $entries = scandir($dir);
    if ($entries === false) {
        return $result;
    }

    $hiddenHostIdentifiers = collectHiddenExtensionIdentifiers();

    foreach ($entries as $sub) {
        if ($sub === '.' || $sub === '..') {
            continue;
        }

        $manifestPath = $dir.'/'.$sub.'/language-pack.json';
        if (! file_exists($manifestPath)) {
            continue;
        }

        $content = @file_get_contents($manifestPath);
        if ($content === false) {
            continue;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
            continue;
        }

        // 직접 hidden 처리된 매니페스트 제외
        if (! empty($data['hidden'])) {
            continue;
        }

        // 종속 호스트 확장이 hidden 이면 cascade 제외 (학습용 샘플 모듈/템플릿용 언어팩 자동 제외)
        $targetIdentifier = $data['target_identifier'] ?? null;
        if (is_string($targetIdentifier) && $targetIdentifier !== '' && in_array($targetIdentifier, $hiddenHostIdentifiers, true)) {
            continue;
        }

        $result[] = [
            'identifier' => $data['identifier'] ?? $sub,
            'directory' => $sub,
            'scope' => $data['scope'] ?? 'core',
            'target_identifier' => $data['target_identifier'] ?? null,
            'locale' => $data['locale'] ?? '',
            'locale_name' => $data['locale_name'] ?? '',
            'locale_native_name' => $data['locale_native_name'] ?? ($data['locale_name'] ?? ($data['locale'] ?? '')),
            'text_direction' => $data['text_direction'] ?? 'ltr',
            'name' => $data['name'] ?? $sub,
            'description' => $data['description'] ?? ['ko' => '', 'en' => ''],
            'version' => $data['version'] ?? '1.0.0',
        ];
    }

    return $result;
}

/**
 * JSON 매니페스트에서 의존성 identifier 배열 추출 (하위 호환 유지).
 *
 * dependencies 구조:
 * - 객체 형태: { "modules": {"sirsoft-ecommerce": ">=1.0.0"}, "plugins": {...} }
 * - 배열 형태: ["module1", "module2"]
 *
 * @param  array  $data  JSON 매니페스트 데이터
 * @return array 의존성 identifier 목록
 */
function extractDependenciesFromJson(array $data): array
{
    $rawDeps = $data['dependencies'] ?? [];
    $dependencies = [];

    if (is_array($rawDeps)) {
        if (isset($rawDeps['modules']) || isset($rawDeps['plugins'])) {
            foreach (['modules', 'plugins'] as $depType) {
                if (! empty($rawDeps[$depType]) && is_array($rawDeps[$depType])) {
                    $dependencies = array_merge($dependencies, array_keys($rawDeps[$depType]));
                }
            }
        } else {
            $dependencies = array_values($rawDeps);
        }
    }

    return $dependencies;
}

/**
 * JSON 매니페스트에서 타입+버전이 보존된 의존성 상세 배열 추출.
 *
 * 반환: [['type' => 'modules'|'plugins', 'identifier' => '...', 'version' => '>=0.1.1'], ...]
 * 프론트엔드 validateExtensionSelection()에서 의존성 그래프 검증에 사용합니다.
 *
 * @param  array  $data  JSON 매니페스트 데이터
 * @return array 의존성 상세 배열
 */
function extractDependenciesDetailedFromJson(array $data): array
{
    $rawDeps = $data['dependencies'] ?? [];
    $detailed = [];

    if (! is_array($rawDeps)) {
        return $detailed;
    }

    if (isset($rawDeps['modules']) || isset($rawDeps['plugins'])) {
        foreach (['modules', 'plugins'] as $depType) {
            if (! empty($rawDeps[$depType]) && is_array($rawDeps[$depType])) {
                foreach ($rawDeps[$depType] as $identifier => $version) {
                    if (is_int($identifier)) {
                        // 배열 원소: 값이 identifier
                        $detailed[] = [
                            'type' => $depType,
                            'identifier' => (string) $version,
                            'version' => '*',
                        ];
                    } else {
                        $detailed[] = [
                            'type' => $depType,
                            'identifier' => (string) $identifier,
                            'version' => is_string($version) ? $version : '*',
                        ];
                    }
                }
            }
        }
    } else {
        // 레거시 배열 형태 — 타입을 알 수 없으므로 'unknown'으로 표기
        foreach ($rawDeps as $identifier) {
            $detailed[] = [
                'type' => 'unknown',
                'identifier' => (string) $identifier,
                'version' => '*',
            ];
        }
    }

    return $detailed;
}

// ===== 메인 실행 =====
// 라이브러리 모드(테스트) 에서는 메인 실행을 건너뛴다.
if ($scanExtensionsLibraryMode) {
    return;
}

try {
    // 템플릿 스캔
    $templates = scanTemplates();

    // 모듈 스캔
    $modules = scanModules();

    // 플러그인 스캔
    $plugins = scanPlugins();

    // 번들 언어팩 스캔
    $languagePacks = scanLanguagePacks();

    // Admin 템플릿 필수 검증
    if (empty($templates['admin'])) {
        http_response_code(400);
        echo installer_json_encode([
            'success' => false,
            'error' => 'no_admin_template',
            'error_message' => 'Admin template is required but not found. Please ensure at least one admin template exists in the templates/_bundled directory.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 결과 반환
    echo installer_json_encode([
        'success' => true,
        'data' => [
            'admin_templates' => $templates['admin'],
            'user_templates' => $templates['user'],
            'modules' => $modules,
            'plugins' => $plugins,
            'language_packs' => $languagePacks,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo installer_json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
