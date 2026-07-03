<?php

// 설치 완료 여부 확인
$installedFlagPath = __DIR__.'/../storage/app/g7_installed';
$envPath = __DIR__.'/../.env';

// 설치 확인: g7_installed 파일 또는 .env의 INSTALLER_COMPLETED
$isInstalled = file_exists($installedFlagPath) || (
    file_exists($envPath) &&
    preg_match('/^INSTALLER_COMPLETED=true$/m', file_get_contents($envPath))
);

// 미설치 시 /install로 리다이렉트
if (! $isInstalled) {
    $installUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/').'/install';
    echo '<script>
        alert("G7 최초 사용을 위해 설치 절차가 필요합니다. 설치 화면으로 이동합니다.");
        window.location.href="'.htmlspecialchars($installUrl).'";
    </script>';
    exit;
}

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
$loader = require __DIR__.'/../vendor/autoload.php';

// Register extension (module/plugin) autoload...
$extensionAutoloadFile = __DIR__.'/../bootstrap/cache/autoload-extensions.php';

if (file_exists($extensionAutoloadFile)) {
    // CoreServiceProvider에서 중복 등록 방지용 플래그
    define('G7_EXTENSION_AUTOLOAD_REGISTERED', true);
    $extensionAutoloads = require $extensionAutoloadFile;

    // PSR-4 네임스페이스 등록
    if (! empty($extensionAutoloads['psr4'])) {
        foreach ($extensionAutoloads['psr4'] as $namespace => $paths) {
            // 경로가 배열인 경우와 문자열인 경우 모두 처리
            $paths = (array) $paths;
            foreach ($paths as $path) {
                $absolutePath = __DIR__.'/../'.$path;
                if (is_dir($absolutePath)) {
                    $loader->addPsr4($namespace, $absolutePath);
                }
            }
        }
    }

    // 확장 소스 classmap 등록 (FQCN → 절대경로).
    // findFile 이 파일시스템 스캔(is_dir/file_exists stat) 없이 in-memory 맵에서 즉시
    // 경로를 반환하도록 한다. cold OPcache / 느린 파일시스템 환경의 findFile 비용 제거.
    // 클래스 로딩은 여전히 lazy — 실제 사용 시점에만 include. classmap 미포함 클래스는
    // 위 PSR-4 등록으로 폴백(안전망).
    if (! empty($extensionAutoloads['src_classmap'])) {
        $absoluteClassmap = [];
        foreach ($extensionAutoloads['src_classmap'] as $fqcn => $relPath) {
            $absoluteClassmap[$fqcn] = __DIR__.'/../'.$relPath;
        }
        $loader->addClassMap($absoluteClassmap);
    }

    // Classmap 파일 로드 (module.php, plugin.php)
    if (! empty($extensionAutoloads['classmap'])) {
        foreach ($extensionAutoloads['classmap'] as $file) {
            $absolutePath = __DIR__.'/../'.$file;
            if (file_exists($absolutePath)) {
                require_once $absolutePath;
            }
        }
    }

    // Files 로드 (헬퍼 함수 등)
    if (! empty($extensionAutoloads['files'])) {
        foreach ($extensionAutoloads['files'] as $file) {
            $absolutePath = __DIR__.'/../'.$file;
            if (file_exists($absolutePath)) {
                require_once $absolutePath;
            }
        }
    }

    // Vendor autoloads 로드 (모듈/플러그인의 composer 의존성)
    if (! empty($extensionAutoloads['vendor_autoloads'])) {
        foreach ($extensionAutoloads['vendor_autoloads'] as $vendorAutoload) {
            $absolutePath = __DIR__.'/../'.$vendorAutoload;
            if (file_exists($absolutePath)) {
                require_once $absolutePath;
            }
        }
    }
}

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
