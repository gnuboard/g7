<?php

// 앱 루트 해석 (B-2 일반화) — 기본은 이 파일의 부모 디렉토리다. 문서 루트 분리 배치처럼
// 진입 파일이 앱 루트 밖에 놓이는 레이아웃은 이 파일을 require 하기 전에 `G7_BASE_PATH`
// 상수로 앱 루트를 지정한다. 상수 미정의 시 동작은 종전과 완전히 같다.
$g7AppRoot = defined('G7_BASE_PATH') ? rtrim(G7_BASE_PATH, '/\\') : dirname(__DIR__);

// 설치 완료 여부 확인
$installedFlagPath = $g7AppRoot.'/storage/app/g7_installed';
$envPath = $g7AppRoot.'/.env';

// 설치 확인: g7_installed 파일 또는 .env의 INSTALLER_COMPLETED
$isInstalled = file_exists($installedFlagPath) || (
    file_exists($envPath) &&
    preg_match('/^INSTALLER_COMPLETED=true$/m', file_get_contents($envPath))
);

// 배포 레이아웃 ②(문서 루트 = 프로젝트 루트)의 위임 흔적을 진입 시점에 지운다.
//
// 루트 `.htaccess` 는 요청을 `public/` 아래로 위임한다. 그래서 브라우저는 `/` 에 있는데
// `SCRIPT_NAME` 은 `/public/index.php` 다. 이 어긋남은 두 곳으로 새어 나간다:
//
//  - **설치 화면 주소.** 그 값으로 조립하면 `/public/install` 이 되어 설치 전 과정이
//    `/public/` 접두를 달고 진행된다 — 같은 사이트가 두 주소 체계로 갈린다.
//  - **요청 주소에 `index.php` 가 든 경우의 생성 URL.** Symfony 의 base path 판정은
//    요청 URI 에 스크립트 이름이 들어 있을 때만 그 값을 채택하고(`Request::prepareBaseUrl`),
//    없으면 빈 문자열로 떨어진다. 그래서 위임된 일반 요청(`/admin`·`/api/…`)은 이 정규화가
//    없어도 정상이다. 어긋남이 실제로 새는 것은 `/index.php` 처럼 URI 에 스크립트 이름이
//    리터럴로 든 요청이며, 그때는 `url()`·`asset()`·`route()` 가 전부 `/public/index.php`
//    접두를 달고 나와 자산이 깨지고 프로젝트 루트 경로가 주소에 노출된다. 표준 배치에는
//    없는, 이 레이아웃이 만든 증분이다.
//    (근거 범위를 좁혀 적는 이유: 「모든 URL 이 깨진다」로 적어 두면 재현되지 않는 주장이
//     되어, 다음 사람이 이 블록을 불필요한 것으로 보고 지울 여지를 만든다.)
//
// 위임 여부는 요청 경로로 판정한다: 요청이 그 `/public` 접두로 시작하지 않으면 그 세그먼트는
// 위임이 만든 것이므로 벗긴다. 위임이 없는 배치(표준 배치·레이아웃 ③)에서는 요청 경로가
// 그 접두를 그대로 갖고 있어 아무것도 바뀌지 않는다.
// region:delegation-normalization — 계약 테스트가 이 구간을 **그대로 평가**한다
// (RootEntrypointContractTest). 사본을 만들지 않기 위한 표식이므로 지우지 말 것.
$g7ScriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');

if (strcasecmp(substr($g7ScriptDir, -7), '/public') === 0) {
    $g7RequestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');

    // 접두 일치는 **디렉토리 자기 자신**을 포함한다. `strncasecmp($path, $dir.'/', …)` 만
    // 쓰면 트레일링 슬래시 없는 디렉토리 요청(`/public`)이 한 바이트 짧아 불일치가 되고,
    // 위임이 없는 배치인데도 위임으로 오판해 `/public` 세그먼트를 벗긴다 — 그러면
    // 레이아웃 ③(서브디렉토리 + URL 에 `/public` 노출)의 생성 URL 이 전부 그 접두를 잃는다.
    // Apache 는 `DirectorySlash` 가 301 로 막아 주지만 nginx `try_files $uri $uri/` 는
    // `REQUEST_URI` 를 슬래시 없이 유지한 채 내부 리라이트하므로 실제로 도달한다.
    $g7IsUnderScriptDir = strcasecmp($g7RequestPath, $g7ScriptDir) === 0
        || strncasecmp($g7RequestPath, $g7ScriptDir.'/', strlen($g7ScriptDir) + 1) === 0;

    if (! $g7IsUnderScriptDir) {
        $g7ScriptDir = substr($g7ScriptDir, 0, -7);

        // 마운트 접두사를 위임 규칙이 알려 준 값으로 되찾는다 (`Alias` 마운트 대응).
        //
        // 사이트를 웹서버 `Alias` 로 마운트하면 `SCRIPT_NAME` 이 접두사를 잃을 수 있다.
        // 「별칭이면 항상」은 아니다 — 별칭 **대상이 그 vhost 의 문서 루트와 같은
        // 디렉토리**일 때 잃고(`/public/index.php`), 대상이 문서 루트 **밖**이면 그대로
        // 실린다(`/g7out/public/index.php`). 물리적 하위 디렉토리 마운트는 언제나 싣는다
        // (셋 다 17차 회차 3 P0 실측). 잃는 배치에서는 위 정규화 결과가
        // 빈 문자열이 되어 미설치 안내가 `/install` 로, 즉 **사이트 밖**으로 보낸다.
        //
        // 브라우저 주소만으로는 복원할 수 없다 — `/g7sub/admin` 의 어디까지가 마운트이고
        // 어디부터가 라우트인지 구분할 근거가 그 문자열에 없기 때문이다. 그 경계를 아는
        // 것은 위임 규칙뿐이므로 루트 `.htaccess` 가 사이트 안 경로를 `G7_ROUTE` 로 넘기고,
        // 여기서 「브라우저 주소 − 그 값」으로 뺀다. 추정하지 않고 빼기만 한다.
        //
        // 내부 리다이렉트를 지날 때마다 `REDIRECT_` 가 덧붙으므로 단계를 올려 가며 찾는다.
        // 값이 없거나(위임 없는 표준 배치) 꼬리가 맞지 않으면 위 정규화 결과를 그대로 쓴다 —
        // 그 배치들에서는 두 값이 애초에 같아 결과가 달라지지 않는다.
        $g7Route = null;

        for ($g7Depth = 0, $g7Prefix = ''; $g7Depth <= 4; $g7Depth++, $g7Prefix .= 'REDIRECT_') {
            if (isset($_SERVER[$g7Prefix.'G7_ROUTE']) && is_string($_SERVER[$g7Prefix.'G7_ROUTE'])) {
                $g7Route = $_SERVER[$g7Prefix.'G7_ROUTE'];
                break;
            }
        }

        if ($g7Route !== null) {
            $g7Route = ltrim(str_replace('\\', '/', $g7Route), '/');

            if ($g7Route === '') {
                // 사이트 안 경로가 비었다 = 요청이 **마운트 루트**를 가리킨다. 그때만
                // 요청 경로 전체가 마운트다.
                //
                // 비-빈 값 분기는 「꼬리가 맞는가」라는 근거 검사를 갖는데 이 분기에는
                // 없었다 — 그래서 다른 이유로 값이 비면(운영자가 관리 블록 **밖**에 자체
                // rewrite 를 두어 깊은 경로를 `/` 로 먼저 다시 쓰는 경우 등) 요청 경로
                // 전체를 마운트로 **단정**하고, 사이트의 모든 주소가 한 단계 깊어진다.
                // 마운트 루트 요청은 정본 주소 규칙이 트레일링 슬래시를 보장하므로,
                // 그 형태일 때만 받는다. 아니면 추정하지 않고 폴백한다.
                if (str_ends_with($g7RequestPath, '/')) {
                    $g7ScriptDir = rtrim($g7RequestPath, '/');
                }
            } else {
                // 두 값의 **표기가 다르다.** 브라우저 주소(`REQUEST_URI`)는 퍼센트 인코딩된
                // 원문이고, 위임 규칙이 넘겨 준 값은 웹서버가 디코딩한 경로다. 그래서
                // 바이트 길이로 꼬리를 대조하면 `%20`·한글이 든 주소에서 **언제나 어긋나고**,
                // 접두사가 빈 문자열로 떨어져 미설치 안내가 사이트 밖으로 나가고 그 화면의
                // 자산 주소도 접두사를 잃는다.
                //
                // 그래서 길이가 아니라 **세그먼트 단위로** 뺀다. 뺀 뒤의 접두사는 브라우저
                // 주소에서 잘라 온 것이므로 **원문 표기를 그대로 유지**한다.
                //
                // 그리고 **꼬리가 실제로 그 라우트인지 확인한다.** 후보를 요청 경로 자신에서
                // 잘라 만들기 때문에 「후보가 요청 경로의 머리인가」는 실패할 수 없다 — 그런
                // 검사는 통과해도 아무것도 보증하지 않는다. 넘겨받은 값이 요청과 무관해도
                // 세그먼트 수만 맞으면 채택되므로, 잘라 낸 꼬리를 **디코딩해** 라우트와
                // 맞춰 본다. 맞지 않으면 추정하지 않고 폴백한다.
                $g7RouteSegments = explode('/', $g7Route);
                $g7RouteDepth = count($g7RouteSegments);
                $g7PathSegments = explode('/', ltrim(rtrim($g7RequestPath, '/'), '/'));

                if (count($g7PathSegments) >= $g7RouteDepth) {
                    $g7Kept = array_slice($g7PathSegments, 0, count($g7PathSegments) - $g7RouteDepth);
                    $g7Tail = array_slice($g7PathSegments, count($g7PathSegments) - $g7RouteDepth);

                    $g7TailMatches = true;

                    foreach ($g7Tail as $g7Index => $g7Segment) {
                        if (rawurldecode($g7Segment) !== ($g7RouteSegments[$g7Index] ?? null)) {
                            $g7TailMatches = false;
                            break;
                        }
                    }

                    if ($g7TailMatches) {
                        $g7ScriptDir = $g7Kept === [] ? '' : '/'.implode('/', $g7Kept);
                    }
                }
            }
        }

        foreach (['SCRIPT_NAME', 'PHP_SELF'] as $g7Key) {
            if (isset($_SERVER[$g7Key]) && is_string($_SERVER[$g7Key])) {
                $_SERVER[$g7Key] = $g7ScriptDir.'/'.basename(str_replace('\\', '/', $_SERVER[$g7Key]));
            }
        }
    }
}
// endregion:delegation-normalization

// 미설치 시 /install로 리다이렉트
if (! $isInstalled) {
    $installUrl = $g7ScriptDir.'/install';

    // 싱크에 맞는 인코더를 쓴다.
    //
    // 이 값은 JS **문자열 리터럴** 자리에 들어가는데 종전에는 HTML 이스케이프
    // (`htmlspecialchars`)로 막고 있었다. `<script>` 안은 HTML 파서가 실체 참조를
    // 해석하지 않는 raw text 라, 그 함수는 이 자리에서 **보호도 못 하고 값도 바꾼다**
    // (실측):
    //   · `/a"b`      → `href="/a&quot;b/install"` — 주소에 `&quot;` 가 그대로 들어간다
    //   · `/g7\i…`    → JS 가 `\i` 를 `i` 로 읽어 **경로가 조용히 `/g7i…` 가 된다**
    //   · `/xA…` → JS 가 `A` 로 읽어 다른 경로가 된다
    // 셋 다 오류가 나지 않고 **다른 주소로 이동**하는 형태다. 출처가 요청 경로에서
    // 잘라 온 값(`$g7ScriptDir`)이라 요청자 영향을 받는다 (10차 G3 라운드 D10-8).
    //
    // `JSON_HEX_*` 는 `</script>` 조기 종료와 HTML 문맥 탈출도 함께 막는다.
    $installUrlJs = json_encode(
        $installUrl,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );

    echo '<script>
        alert("G7 최초 사용을 위해 설치 절차가 필요합니다. 설치 화면으로 이동합니다.");
        window.location.href='.($installUrlJs === false ? '"/install"' : $installUrlJs).';
    </script>';
    exit;
}

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $g7AppRoot.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
$loader = require $g7AppRoot.'/vendor/autoload.php';

// Register extension (module/plugin) autoload...
$extensionAutoloadFile = $g7AppRoot.'/bootstrap/cache/autoload-extensions.php';

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
                $absolutePath = $g7AppRoot.'/'.$path;
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
            $absoluteClassmap[$fqcn] = $g7AppRoot.'/'.$relPath;
        }
        $loader->addClassMap($absoluteClassmap);
    }

    // Classmap 파일 로드 (module.php, plugin.php)
    if (! empty($extensionAutoloads['classmap'])) {
        foreach ($extensionAutoloads['classmap'] as $file) {
            $absolutePath = $g7AppRoot.'/'.$file;
            if (file_exists($absolutePath)) {
                require_once $absolutePath;
            }
        }
    }

    // Files 로드 (헬퍼 함수 등)
    if (! empty($extensionAutoloads['files'])) {
        foreach ($extensionAutoloads['files'] as $file) {
            $absolutePath = $g7AppRoot.'/'.$file;
            if (file_exists($absolutePath)) {
                require_once $absolutePath;
            }
        }
    }

    // Vendor autoloads 로드 (모듈/플러그인의 composer 의존성)
    if (! empty($extensionAutoloads['vendor_autoloads'])) {
        foreach ($extensionAutoloads['vendor_autoloads'] as $vendorAutoload) {
            $absolutePath = $g7AppRoot.'/'.$vendorAutoload;
            if (file_exists($absolutePath)) {
                require_once $absolutePath;
            }
        }
    }
}

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $g7AppRoot.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
