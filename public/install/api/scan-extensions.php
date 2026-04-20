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

// 기본 설정
header('Content-Type: application/json; charset=utf-8');

// 프로젝트 루트 경로
define('BASE_PATH', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));

// 세션 및 설정 포함 (Laravel autoload 없이 독립 실행)
// 참고: 이 API는 Laravel 부팅 없이 디렉토리 스캔만 수행
require_once dirname(__DIR__).'/includes/session.php';
require_once dirname(__DIR__).'/includes/config.php';

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

    if (!is_array($rawDeps)) {
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

try {
    // 템플릿 스캔
    $templates = scanTemplates();

    // 모듈 스캔
    $modules = scanModules();

    // 플러그인 스캔
    $plugins = scanPlugins();

    // Admin 템플릿 필수 검증
    if (empty($templates['admin'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'no_admin_template',
            'error_message' => 'Admin template is required but not found. Please ensure at least one admin template exists in the templates/_bundled directory.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 결과 반환
    echo json_encode([
        'success' => true,
        'data' => [
            'admin_templates' => $templates['admin'],
            'user_templates' => $templates['user'],
            'modules' => $modules,
            'plugins' => $plugins,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
