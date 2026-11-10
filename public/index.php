<?php

// 설치 완료 여부 확인
$installedFlagPath = __DIR__.'/../storage/app/g7_installed';
$envPath = __DIR__.'/../.env';

// 설치 확인: g7_installed 파일 또는 .env의 INSTALLER_COMPLETED
$isInstalled = file_exists($installedFlagPath) || (
    file_exists($envPath) &&
    preg_match('/^INSTALLER_COMPLETED=true$/m', file_get_contents($envPath))
);

// 미설치 시 /install로 리다이렉트 — 스크립트 없이 HTTP 302 로 보낸다. 종전의 alert + location 인라인
// 스크립트는 웹서버가 `Content-Security-Policy: script-src 'self'` 를 걸면 실행되지 않아 설치 화면으로
// 넘어가지 못한 흰 화면이 됐다(#158).
// Windows 에서는 루트 설치의 dirname('/index.php') 가 '\' 다 — JS 문자열에서는 '\/' 가 '/' 였지만 Location
// 헤더의 '\/install' 은 브라우저가 '//install'(다른 호스트)로 읽는다. 역슬래시를 슬래시로 바꾼 뒤 자른다.
if (! $isInstalled) {
    $installUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/').'/install';
    header('Location: '.$installUrl, true, 302);
    exit;
}

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// vendor 없이 응답해야 하는 구간 — 코어 업데이트는 유지보수 모드를 켠 채 vendor/ 를 비우고 새 vendor 를
// 복사한다(수십 초). 그 사이 요청이 vendor/autoload.php require 에서 치명 오류로 죽으면 유지보수 503 이
// 아니라 500(display_errors 가 켜져 있으면 200 + 오류 본문)이 나간다. 프레임워크를 쓸 수 없으므로 여기서
// 최소 응답을 직접 만든다. 유지보수 표식(storage/framework/down)이 있으면 503 + Retry-After, 없으면 일시
// 장애가 아니라 설치 손상이므로 500 — 어느 쪽이든 오류 내부(경로·스택)는 본문에 싣지 않는다.
$g7DownFile = __DIR__.'/../storage/framework/down';
$g7RespondWithoutFramework = static function (bool $maintenance) use ($g7DownFile): void {
    $down = $maintenance ? json_decode((string) @file_get_contents($g7DownFile), true) : null;
    $down = is_array($down) ? $down : [];
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if ($base !== '' && str_starts_with($path, $base.'/')) {
        $path = substr($path, strlen($base));
    }
    $wantsJson = $path === '/api' || str_starts_with($path, '/api/')
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'json')
        || strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;

    if (! headers_sent()) {
        header_remove();
        // 치명 오류 뒤에는 PHP 가 이미 500 상태 줄을 넣어 두어 http_response_code() 만으로는 바뀌지 않는다.
        $protocol = preg_match('#^HTTP/\d(\.\d)?$#', (string) ($_SERVER['SERVER_PROTOCOL'] ?? '')) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
        header($protocol.($maintenance ? ' 503 Service Unavailable' : ' 500 Internal Server Error'), true, $maintenance ? 503 : 500);
        header('Cache-Control: no-store');
        header('Content-Type: '.($wantsJson ? 'application/json' : 'text/html; charset=UTF-8'));
        if ($maintenance) {
            header('Retry-After: '.(is_numeric($down['retry'] ?? null) ? (int) $down['retry'] : 60));
            if (is_numeric($down['refresh'] ?? null)) {
                header('Refresh: '.(int) $down['refresh']);
            }
        }
    }

    $title = $maintenance ? 'Service Unavailable' : 'Server Error';
    $message = $maintenance
        ? 'The site is being updated. Please try again in a moment.'
        : 'The application could not start.';
    echo $wantsJson
        ? json_encode(['success' => false, 'message' => $message])
        : '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="robots" content="noindex"><title>'.$title.'</title></head>'
            .'<body><h1>'.$title.'</h1><p>'.$message.'</p></body></html>';
};

if (! is_file(__DIR__.'/../vendor/autoload.php')) {
    $g7RespondWithoutFramework(is_file($g7DownFile));
    exit;
}

// 유지보수 중에는 vendor 가 복사되는 도중일 수 있다(autoload.php 는 왔지만 그것이 부르는 파일이 아직 없음).
// 그때 난 치명 오류도 유지보수 503 으로 바꾼다. 오류 문구가 먼저 출력되면 상태 코드를 바꿀 수 없으므로
// display_errors 를 끈다 — 프레임워크도 부팅하면서 같은 값으로 끄며, 오류는 log_errors 설정대로 기록된다.
// 이 처리기는 프레임워크의 종료 처리기보다 먼저 등록되므로 exit 로 뒤 처리기(오류 화면 렌더)를 막는다.
if (is_file($g7DownFile)) {
    ini_set('display_errors', '0');
    register_shutdown_function(static function () use ($g7RespondWithoutFramework): void {
        $error = error_get_last();
        if ($error === null || headers_sent()
            || ! in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $g7RespondWithoutFramework(true);
        exit;
    });
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
