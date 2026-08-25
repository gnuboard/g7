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
    // 확장 목록은 _bundled 디렉토리 스캔 결과다 — 캐시되면 방금 올린 확장이 보이지 않는다 (17차 #B5).
    header('Cache-Control: no-store');

    // 프로젝트 루트 경로
    if (! defined('BASE_PATH')) {
        // `realpath` 도 호스팅이 막을 수 있다 (13차 환경#1) — 막힌 환경에서 무가드로 부르면
        // 봉투가 서기 전에 치명 종료한다. 정규화를 못 해도 조립 경로로 스캔은 가능하다.
        define('BASE_PATH', function_exists('realpath')
            ? (realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3))
            : dirname(__DIR__, 3));
    }

    // installerHasFunction 의 출처 — 아래 출력 채널 가드가 쓴다 (전이 의존 금지, R4 취지).
    // 최상위 실행문이 없고 BASE_PATH 를 자체 역산하므로 session/config 보다 앞에 와도 안전하다.
    require_once dirname(__DIR__).'/includes/execution-mode.php';

    // 오류 텍스트가 JSON 본문에 섞이지 않게 한다 (20차 #13 · 21차 N4) — **include 체인·가드보다
    // 앞**. session_start 실패·깨진 매니페스트 스캔 warning 이 본문 앞에 붙으면 클라이언트
    // 파싱이 깨진다.
    if (installerHasFunction('ini_set')) {
        @ini_set('display_errors', '0');
    }
    error_reporting(E_ALL);

    // 세션 및 설정 포함 (Laravel autoload 없이 독립 실행)
    // 참고: 이 API는 Laravel 부팅 없이 디렉토리 스캔만 수행
    require_once dirname(__DIR__).'/includes/session.php';
    require_once dirname(__DIR__).'/includes/config.php';

    require_once __DIR__.'/_guard.php';
    installer_guard_or_410();
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
    // 디렉토리 열거 함수가 막힌 환경 (13차 환경#1). 이 API 는 전부 scandir 로 도는데,
    // 막혀 있으면 각 호출이 Error 를 던져 아래 catch 가 개발자 원문을 화면에 띄운다.
    // 사유를 먼저 확인해 사람 말로 알린다 — 판정은 execution-mode.php 가 소유한다
    // (session.php 가 그 파일을 로드한다).
    if (function_exists('installerHasFunction') && ! installerHasFunction('scandir')) {
        http_response_code(500);
        echo installer_json_encode([
            'success' => false,
            'error' => 'directory_listing_unavailable',
            // 사유를 다국어 키로도 싣는다 (19차 S-4·S-11) — 화면이 세 사유를 구분해 그린다.
            'message_key' => 'scan_directory_listing_unavailable',
            'error_message' => 'Directory listing is disabled on this server (scandir), so bundled extensions cannot be scanned.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

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
            'message_key' => 'no_admin_template_error',
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
    // 예외 원문은 응답에 싣지 않는다 (13차 보안#9) — 이 엔드포인트는 인증이 없고, 원문에는
    // 절대경로가 그대로 섞인다. 원문은 로그로만 남긴다.
    if (function_exists('logInstallationError')) {
        logInstallationError('scan-extensions failed', $e);
    } else {
        installerErrorLog('[installer] scan-extensions failed: '.$e->getMessage());
    }

    http_response_code(500);
    echo installer_json_encode([
        'success' => false,
        'error' => 'scan_failed',
        'message_key' => 'scan_failed_see_log',
        'error_message' => 'Scanning bundled extensions failed. See the installation log for details.',
    ], JSON_UNESCAPED_UNICODE);
}
