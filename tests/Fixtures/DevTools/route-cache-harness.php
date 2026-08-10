<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * 라우트 캐시 회귀 검증용 서브프로세스 하네스.
 *
 * 왜 서브프로세스인가:
 *   이 결함은 **같은 프로세스 안에서 재현되지 않는다**. `routes/devtools.php` 가 한 번이라도
 *   로드되면 그 안의 전역 함수는 PHP 프로세스 전역에 남고, `Artisan::call('route:cache')` 는
 *   `getFreshApplication()` 으로 라우트 파일을 다시 로드해 오히려 함수를 정의해 버린다.
 *   게다가 `RouteCacheHelper::rebuild()` 는 testing 환경에서 캐시를 굽지 않는다.
 *   → in-process 테스트는 수정 전에도 통과하는 가짜 green 이 된다.
 *
 * 사용법:
 *   php tests/Fixtures/DevTools/route-cache-harness.php bake     <cacheRelDir>
 *   php tests/Fixtures/DevTools/route-cache-harness.php dispatch <cacheRelDir> <METHOD> <URI> [<base64Body>]
 *
 * 본문은 **base64 로 인코딩해서** 넘긴다. Windows 의 `escapeshellarg()` 는 인자 안의
 * 큰따옴표를 공백으로 치환하므로 JSON 을 그대로 넘기면 `{"test":true}` 가 `{ test :true}` 로
 * 깨져 도착한다. 파싱 오류도 나지 않고 그저 입력이 비어 있는 요청이 되므로, 200 만 단언하는
 * 테스트는 페이로드가 전달되지 않은 채로 통과한다 (실측으로 발견).
 *
 * 출력:
 *   마지막 줄에 `@@G7HARNESS@@` 마커 + JSON 1줄.
 *   부팅 중 deprecation 경고가 stdout 에 섞여도 파싱이 깨지지 않고, 실패 시 원문 전체를
 *   assertion 메시지로 붙일 수 있다.
 *   - bake     → exit code 전파 (캐시 굽기 실패를 프로세스 실패로 드러낸다)
 *   - dispatch → 항상 exit 0 (HTTP 500 과 프로세스 실패를 분리)
 *
 * 함정 1 — 캐시 경로는 base_path 상대 POSIX 경로만 받는다.
 *   `Application::normalizeCachePath()`(vendor/laravel/framework/src/Illuminate/Foundation/Application.php:1379)
 *   는 `$absoluteCachePathPrefixes = ['/', '\\']`(:209) 로 시작하는 값만 절대경로로 취급한다.
 *   Windows 의 `C:\...` 는 여기 걸리지 않아 `basePath($env)` 로 이어붙어 엉뚱한 위치를 가리킨다.
 *
 * 함정 2 — 환경변수는 프로세스 env 가 아니라 `$_SERVER`/`$_ENV` 에 직접 넣는다.
 *   `bootstrap/app.php:62` 의 `Env::disablePutenv()` 때문에 리더가 `$_SERVER`/`$_ENV` 뿐이고,
 *   CLI 의 `variables_order` 기본값 `"GPCS"` 에는 `E` 가 없어 `proc_open($env)` 이 조용히
 *   무시될 수 있다. 그러면 자식이 **실 캐시를 읽고 덮어쓴다**.
 *
 * 5개 캐시 경로를 전부 임시 디렉토리로 돌린다:
 *   - APP_ROUTES_CACHE   검증 대상
 *   - APP_CONFIG_CACHE   살아 있으면 자식이 개발 DB 로 부팅한다
 *   - APP_PACKAGES_CACHE / APP_SERVICES_CACHE / APP_EVENTS_CACHE
 *                        자식이 실 경로에 쓰는 것을 차단
 *                        (tests/Unit/Extension/ExtensionAutoloadCacheGuardTest.php 규율)
 */
const HARNESS_MARKER = '@@G7HARNESS@@';

$repoRoot = dirname(__DIR__, 3);

$argvLocal = $_SERVER['argv'] ?? [];
$mode = $argvLocal[1] ?? '';
$cacheRelDir = $argvLocal[2] ?? '';

if (! in_array($mode, ['bake', 'dispatch'], true) || $cacheRelDir === '') {
    fwrite(STDERR, "usage: route-cache-harness.php bake|dispatch <cacheRelDir> [...]\n");
    exit(2);
}

// 함정 1: 상대 POSIX 경로만 수용한다.
if (preg_match('#^([A-Za-z]:|[/\\\\])#', $cacheRelDir) === 1 || str_contains($cacheRelDir, '\\')) {
    fwrite(STDERR, "cacheRelDir 는 base_path 상대 POSIX 경로여야 합니다: {$cacheRelDir}\n");
    exit(2);
}

$cacheAbsDir = $repoRoot.'/'.$cacheRelDir;
if (! is_dir($cacheAbsDir) && ! @mkdir($cacheAbsDir, 0755, true) && ! is_dir($cacheAbsDir)) {
    fwrite(STDERR, "캐시 임시 디렉토리 생성 실패: {$cacheAbsDir}\n");
    exit(2);
}

// 함정 2: $_SERVER/$_ENV 에 직접 주입 (Env::disablePutenv 대응).
$envOverrides = [
    'APP_ENV' => 'testing',
    // 게이트 단축평가 통과 + 예외 본문을 JSON 으로 확보한다.
    'APP_DEBUG' => 'true',
    'APP_ROUTES_CACHE' => $cacheRelDir.'/routes-v7.php',
    'APP_CONFIG_CACHE' => $cacheRelDir.'/config.php',
    'APP_PACKAGES_CACHE' => $cacheRelDir.'/packages.php',
    'APP_SERVICES_CACHE' => $cacheRelDir.'/services.php',
    'APP_EVENTS_CACHE' => $cacheRelDir.'/events.php',
];

foreach ($envOverrides as $key => $value) {
    $_SERVER[$key] = $value;
    $_ENV[$key] = $value;
}

require $repoRoot.'/vendor/autoload.php';

/**
 * 결과 JSON 을 마커와 함께 출력한다.
 *
 * @param  array<string, mixed>  $payload  출력 페이로드
 * @return void
 */
function harnessEmit(array $payload): void
{
    echo "\n".HARNESS_MARKER.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
}

if ($mode === 'bake') {
    /** @var Application $app */
    $app = require $repoRoot.'/bootstrap/app.php';

    /** @var Kernel $kernel */
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    $exitCode = 0;
    $error = null;

    try {
        // `route:cache` 는 route:clear 후 fresh application 을 부팅해 라우트를 수집한다.
        // fresh application 도 같은 프로세스의 $_SERVER 를 보므로 캐시 경로는 임시 위치 그대로다.
        $exitCode = Artisan::call('route:cache');
        echo Artisan::output();
    } catch (Throwable $e) {
        $exitCode = 1;
        $error = get_class($e).': '.$e->getMessage();
    }

    $cachedPath = $app->getCachedRoutesPath();

    // 여기서 `$app->routesAreCached()` 를 쓰면 안 된다 — `Application::routesAreCached()`
    // (vendor Foundation/Application.php:1327) 는 결과를 컨테이너에 `routes.cached` 로
    // **메모이즈**한다. 부팅 시 `RouteServiceProvider::boot()` 이 이미 호출했고 그때는 캐시가
    // 없었으므로, 방금 1MB 짜리 캐시를 구웠어도 이 프로세스는 영원히 false 를 돌려준다.
    // bake 가 확인해야 하는 것은 "부팅이 캐시를 썼는가" 가 아니라 "파일이 생겼는가" 다.
    clearstatcache(true, $cachedPath);
    $cacheWritten = is_file($cachedPath);

    harnessEmit([
        'mode' => 'bake',
        'exitCode' => $exitCode,
        'error' => $error,
        'cacheWritten' => $cacheWritten,
        'cacheBytes' => $cacheWritten ? filesize($cachedPath) : 0,
        'cachedRoutesPath' => str_replace('\\', '/', $cachedPath),
    ]);

    exit($exitCode === 0 && $cacheWritten ? 0 : 1);
}

// ── dispatch ──────────────────────────────────────────────────────────────
$method = strtoupper($argvLocal[3] ?? 'GET');
$uri = $argvLocal[4] ?? '/';

$encodedBody = $argvLocal[5] ?? '';
$body = '';

if ($encodedBody !== '') {
    $decoded = base64_decode($encodedBody, true);

    if ($decoded === false) {
        fwrite(STDERR, "본문은 base64 로 인코딩해서 넘겨야 합니다.\n");
        exit(2);
    }

    $body = $decoded;
}

/** @var Application $app */
$app = require $repoRoot.'/bootstrap/app.php';

/** @var Illuminate\Contracts\Http\Kernel $httpKernel */
$httpKernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$server = [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
];

$request = Request::create($uri, $method, [], [], [], $server, $body === '' ? null : $body);

$status = 0;
$responseBody = '';
$error = null;

try {
    $response = $httpKernel->handle($request);
    $status = $response->getStatusCode();
    $responseBody = (string) $response->getContent();
    $httpKernel->terminate($request, $response);
} catch (Throwable $e) {
    $error = get_class($e).': '.$e->getMessage();
}

harnessEmit([
    'mode' => 'dispatch',
    'method' => $method,
    'uri' => $uri,
    'routesAreCached' => $app->routesAreCached(),
    'cachedRoutesPath' => str_replace('\\', '/', $app->getCachedRoutesPath()),
    'status' => $status,
    'body' => mb_substr($responseBody, 0, 4000),
    'error' => $error,
]);

exit(0);
