<?php

/**
 * PHPUnit 테스트용 부트스트랩 파일
 *
 * PHPUnit 테스트 실행 시 모듈/플러그인 오토로드를 등록합니다.
 * phpunit.xml의 bootstrap 속성에서 이 파일을 지정합니다.
 */

/*
|--------------------------------------------------------------------------
| .env.testing 존재 확인 (프로덕션 DB 오염 방지)
|--------------------------------------------------------------------------
|
| .env.testing 파일이 없으면 Laravel이 .env(프로덕션)를 사용하여
| 테스트가 프로덕션 DB에서 실행될 위험이 있습니다.
| .env.testing.example을 복사하여 .env.testing을 생성하세요.
|
*/
if (! file_exists(__DIR__.'/../.env.testing')) {
    fwrite(STDERR, "\n".str_repeat('=', 60)."\n");
    fwrite(STDERR, "  ERROR: .env.testing 파일이 없습니다.\n");
    fwrite(STDERR, "  .env.testing.example을 복사하여 생성하세요:\n\n");
    fwrite(STDERR, "    cp .env.testing.example .env.testing\n\n");
    fwrite(STDERR, "  그 후 DB 접속 정보와 APP_KEY를 설정하세요.\n");
    fwrite(STDERR, str_repeat('=', 60)."\n\n");
    exit(1);
}

/*
|--------------------------------------------------------------------------
| 프로덕션 DB 오염 방지 가드 (테스트 vs 프로덕션 DB 이름 충돌 차단)
|--------------------------------------------------------------------------
|
| phpunit.xml 의 `DB_*_DATABASE` 하드코딩을 제거하고 `.env.testing` 을 SSoT 로
| 삼았으므로, 실수로 `.env.testing` 의 DB 이름을 `.env` (프로덕션) 과 동일하게
| 설정할 경우 테스트가 프로덕션 DB 를 파괴할 수 있다.
|
| 여기서 양쪽의 DB_WRITE_DATABASE 를 비교하여 동일하면 즉시 중단한다.
|
*/
$parseDbName = static function (string $envFile): ?string {
    if (! file_exists($envFile)) {
        return null;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^DB_WRITE_DATABASE\s*=\s*(.+)$/', $line, $m)) {
            // 따옴표 제거
            return trim($m[1], "\"' \t");
        }
    }

    return null;
};

$prodDbName = $parseDbName(__DIR__.'/../.env');
$testDbName = $parseDbName(__DIR__.'/../.env.testing');

if ($prodDbName !== null && $testDbName !== null && $prodDbName === $testDbName) {
    fwrite(STDERR, "\n".str_repeat('=', 60)."\n");
    fwrite(STDERR, "  ERROR: 프로덕션 DB 오염 위험 — 테스트 중단.\n\n");
    fwrite(STDERR, "  .env 와 .env.testing 의 DB_WRITE_DATABASE 가 동일합니다: {$prodDbName}\n\n");
    fwrite(STDERR, "  테스트용 별도 DB 를 사용하도록 .env.testing 을 수정하세요.\n");
    fwrite(STDERR, "  (예: DB_WRITE_DATABASE={$prodDbName}_testing)\n");
    fwrite(STDERR, str_repeat('=', 60)."\n\n");
    exit(1);
}

/*
|--------------------------------------------------------------------------
| Config 캐시 삭제 (테스트 환경 보장)
|--------------------------------------------------------------------------
|
| Laravel은 config 캐시 파일이 존재하면 환경 변수를 무시하고 캐시된 값을 사용합니다.
| 테스트 환경(APP_ENV=testing)이 올바르게 적용되도록 캐시 파일을 삭제합니다.
|
*/
$configCacheFile = __DIR__.'/../bootstrap/cache/config.php';
if (file_exists($configCacheFile)) {
    unlink($configCacheFile);
}

// Composer 오토로더 로드
$loader = require __DIR__.'/../vendor/autoload.php';

// Extension 오토로드 중복 등록 방지
define('G7_EXTENSION_AUTOLOAD_REGISTERED', true);

/*
|--------------------------------------------------------------------------
| _bundled 확장 우선 오토로드 (테스트 환경 전용)
|--------------------------------------------------------------------------
|
| _bundled 디렉토리의 확장을 활성 디렉토리보다 먼저 등록(prepend)합니다.
| 이를 통해 _bundled에서 직접 테스트를 실행할 수 있습니다.
|
| 실행 순서:
| 1. _bundled classmap (module.php/plugin.php) require_once → 선점
| 2. _bundled PSR-4 addPsr4(prepend=true) → 우선 검색
| 3. autoload-extensions.php → 활성 디렉토리는 후순위
|
*/

// _bundled 확장 식별자 목록 (classmap 중복 로드 방지용)
$bundledExtensionIdentifiers = [];

// _bundled 모듈 오토로드 (prepend)
$bundledModulesDir = __DIR__.'/../modules/_bundled';
if (is_dir($bundledModulesDir)) {
    $bundledModules = array_filter(scandir($bundledModulesDir), function ($dir) use ($bundledModulesDir) {
        return $dir !== '.' && $dir !== '..' && is_dir($bundledModulesDir.'/'.$dir);
    });

    foreach ($bundledModules as $moduleDir) {
        $modulePath = $bundledModulesDir.'/'.$moduleDir;

        // _bundled 식별자 기록 (classmap 중복 방지용)
        $bundledExtensionIdentifiers[] = $moduleDir;

        // Classmap 선점 (module.php)
        // class_exists 가드: artisan 초기 부팅에서 활성 디렉토리 버전이 로드되었을 수 있음
        $modulePhp = $modulePath.'/module.php';
        if (file_exists($modulePhp)) {
            $parts = explode('-', $moduleDir);
            $vendor = ucfirst($parts[0]);
            $modName = isset($parts[1]) ? str_replace('_', '', ucwords($parts[1], '_')) : '';
            $moduleClass = "Modules\\{$vendor}\\{$modName}\\Module";
            if (! class_exists($moduleClass, false)) {
                require_once $modulePhp;
            }
        }

        // composer.json에서 오토로드 정보 읽기
        $composerJson = $modulePath.'/composer.json';
        if (file_exists($composerJson)) {
            $composerData = json_decode(file_get_contents($composerJson), true);

            // PSR-4 네임스페이스 prepend 등록
            if (! empty($composerData['autoload']['psr-4'])) {
                foreach ($composerData['autoload']['psr-4'] as $namespace => $paths) {
                    $paths = (array) $paths;
                    foreach ($paths as $path) {
                        $absolutePath = $modulePath.'/'.$path;
                        if (is_dir($absolutePath)) {
                            $loader->addPsr4($namespace, $absolutePath, true);
                        }
                    }
                }
            }

            // files 오토로드 (helpers.php 등)
            if (! empty($composerData['autoload']['files'])) {
                foreach ($composerData['autoload']['files'] as $file) {
                    $absoluteFile = $modulePath.'/'.$file;
                    if (file_exists($absoluteFile)) {
                        require_once $absoluteFile;
                    }
                }
            }
        }
    }
}

// _bundled 플러그인 오토로드 (prepend)
$bundledPluginsDir = __DIR__.'/../plugins/_bundled';
if (is_dir($bundledPluginsDir)) {
    $bundledPlugins = array_filter(scandir($bundledPluginsDir), function ($dir) use ($bundledPluginsDir) {
        return $dir !== '.' && $dir !== '..' && is_dir($bundledPluginsDir.'/'.$dir);
    });

    foreach ($bundledPlugins as $pluginDir) {
        $pluginPath = $bundledPluginsDir.'/'.$pluginDir;

        // _bundled 식별자 기록 (classmap 중복 방지용)
        $bundledExtensionIdentifiers[] = $pluginDir;

        // Classmap 선점 (plugin.php)
        // class_exists 가드: artisan 초기 부팅에서 활성 디렉토리 버전이 로드되었을 수 있음
        $pluginPhp = $pluginPath.'/plugin.php';
        if (file_exists($pluginPhp)) {
            $parts = explode('-', $pluginDir);
            $vendor = ucfirst($parts[0]);
            $plgName = isset($parts[1]) ? str_replace('_', '', ucwords($parts[1], '_')) : '';
            $pluginClass = "Plugins\\{$vendor}\\{$plgName}\\Plugin";
            if (! class_exists($pluginClass, false)) {
                require_once $pluginPhp;
            }
        }

        // composer.json에서 오토로드 정보 읽기
        $composerJson = $pluginPath.'/composer.json';
        if (file_exists($composerJson)) {
            $composerData = json_decode(file_get_contents($composerJson), true);

            // PSR-4 네임스페이스 prepend 등록
            if (! empty($composerData['autoload']['psr-4'])) {
                foreach ($composerData['autoload']['psr-4'] as $namespace => $paths) {
                    $paths = (array) $paths;
                    foreach ($paths as $path) {
                        $absolutePath = $pluginPath.'/'.$path;
                        if (is_dir($absolutePath)) {
                            $loader->addPsr4($namespace, $absolutePath, true);
                        }
                    }
                }
            }

            // files 오토로드 (helpers.php 등)
            if (! empty($composerData['autoload']['files'])) {
                foreach ($composerData['autoload']['files'] as $file) {
                    $absoluteFile = $pluginPath.'/'.$file;
                    if (file_exists($absoluteFile)) {
                        require_once $absoluteFile;
                    }
                }
            }
        }
    }
}

// 확장(모듈/플러그인) 오토로드 등록
$extensionAutoloadFile = __DIR__.'/../bootstrap/cache/autoload-extensions.php';
if (file_exists($extensionAutoloadFile)) {
    $extensionAutoloads = require $extensionAutoloadFile;

    // PSR-4 네임스페이스 등록
    // _bundled에서 이미 prepend로 등록된 확장의 활성 디렉토리 PSR-4 경로는 스킵
    // (Windows에서 대소문자 미구분으로 plugin.php = Plugin.php가 되어 중복 로드 발생 방지)
    if (! empty($extensionAutoloads['psr4'])) {
        foreach ($extensionAutoloads['psr4'] as $namespace => $paths) {
            $paths = (array) $paths;
            foreach ($paths as $path) {
                // _bundled 식별자와 일치하는 경로는 스킵
                $skip = false;
                foreach ($bundledExtensionIdentifiers as $identifier) {
                    if (str_contains($path, '/'.$identifier.'/')) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }

                $absolutePath = __DIR__.'/../'.$path;
                if (is_dir($absolutePath)) {
                    $loader->addPsr4($namespace, $absolutePath);
                }
            }
        }
    }

    // Classmap 파일 로드 (module.php, plugin.php)
    // _bundled에서 이미 로드된 확장의 classmap은 스킵 (클래스 중복 선언 방지)
    if (! empty($extensionAutoloads['classmap'])) {
        foreach ($extensionAutoloads['classmap'] as $file) {
            // _bundled 식별자와 일치하는 경로인지 확인
            // 예: "modules/sirsoft-board/module.php" → 식별자 "sirsoft-board"
            $skip = false;
            foreach ($bundledExtensionIdentifiers as $identifier) {
                if (str_contains($file, '/'.$identifier.'/')) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            $absolutePath = __DIR__.'/../'.$file;
            if (file_exists($absolutePath)) {
                require_once $absolutePath;
            }
        }
    }
}

// 모듈/플러그인 테스트 네임스페이스 등록 (tests/ 디렉토리)
$modulesDir = __DIR__.'/../modules';
if (is_dir($modulesDir)) {
    $modules = array_filter(scandir($modulesDir), function ($dir) use ($modulesDir) {
        return $dir !== '.' && $dir !== '..' && is_dir($modulesDir.'/'.$dir.'/tests');
    });

    foreach ($modules as $moduleDir) {
        // sirsoft-ecommerce -> Modules\Sirsoft\Ecommerce\Tests
        $parts = explode('-', $moduleDir);
        $vendor = ucfirst($parts[0]);
        $moduleName = isset($parts[1]) ? str_replace('_', '', ucwords($parts[1], '_')) : '';
        $namespace = "Modules\\{$vendor}\\{$moduleName}\\Tests\\";
        $testsPath = $modulesDir.'/'.$moduleDir.'/tests/';

        if (is_dir($testsPath)) {
            $loader->addPsr4($namespace, $testsPath);
        }
    }
}

// _bundled 모듈 테스트 네임스페이스 등록 (prepend)
$bundledModulesDir = __DIR__.'/../modules/_bundled';
if (is_dir($bundledModulesDir)) {
    $bundledModules = array_filter(scandir($bundledModulesDir), function ($dir) use ($bundledModulesDir) {
        return $dir !== '.' && $dir !== '..' && is_dir($bundledModulesDir.'/'.$dir.'/tests');
    });

    foreach ($bundledModules as $moduleDir) {
        $parts = explode('-', $moduleDir);
        $vendor = ucfirst($parts[0]);
        $moduleName = isset($parts[1]) ? str_replace('_', '', ucwords($parts[1], '_')) : '';
        $namespace = "Modules\\{$vendor}\\{$moduleName}\\Tests\\";
        $testsPath = $bundledModulesDir.'/'.$moduleDir.'/tests/';

        if (is_dir($testsPath)) {
            $loader->addPsr4($namespace, $testsPath, true);
        }
    }
}

// 플러그인 소스 및 테스트 네임스페이스 등록
// (미설치 플러그인도 테스트할 수 있도록 autoload-extensions.php 보완)
$pluginsDir = __DIR__.'/../plugins';
if (is_dir($pluginsDir)) {
    $plugins = array_filter(scandir($pluginsDir), function ($dir) use ($pluginsDir) {
        return $dir !== '.' && $dir !== '..' && is_dir($pluginsDir.'/'.$dir);
    });

    foreach ($plugins as $pluginDir) {
        // sirsoft-tosspayments -> Plugins\Sirsoft\Tosspayments
        $parts = explode('-', $pluginDir);
        $vendor = ucfirst($parts[0]);
        $pluginName = isset($parts[1]) ? str_replace('_', '', ucwords($parts[1], '_')) : '';

        // 소스 네임스페이스 등록 (autoload-extensions.php에 없을 수 있음)
        $srcPath = $pluginsDir.'/'.$pluginDir.'/src/';
        if (is_dir($srcPath)) {
            $srcNamespace = "Plugins\\{$vendor}\\{$pluginName}\\";
            $loader->addPsr4($srcNamespace, $srcPath);
        }

        // 테스트 네임스페이스 등록
        $testsPath = $pluginsDir.'/'.$pluginDir.'/tests/';
        if (is_dir($testsPath)) {
            $testNamespace = "Plugins\\{$vendor}\\{$pluginName}\\Tests\\";
            $loader->addPsr4($testNamespace, $testsPath);
        }
    }
}

// _bundled 플러그인 테스트 네임스페이스 등록 (prepend)
$bundledPluginsDir = __DIR__.'/../plugins/_bundled';
if (is_dir($bundledPluginsDir)) {
    $bundledPlugins = array_filter(scandir($bundledPluginsDir), function ($dir) use ($bundledPluginsDir) {
        return $dir !== '.' && $dir !== '..' && is_dir($bundledPluginsDir.'/'.$dir.'/tests');
    });

    foreach ($bundledPlugins as $pluginDir) {
        $parts = explode('-', $pluginDir);
        $vendor = ucfirst($parts[0]);
        $pluginName = isset($parts[1]) ? str_replace('_', '', ucwords($parts[1], '_')) : '';

        $testsPath = $bundledPluginsDir.'/'.$pluginDir.'/tests/';
        if (is_dir($testsPath)) {
            $testNamespace = "Plugins\\{$vendor}\\{$pluginName}\\Tests\\";
            $loader->addPsr4($testNamespace, $testsPath, true);
        }
    }
}
