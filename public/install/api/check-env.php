<?php

/**
 * 그누보드7 웹 인스톨러 - 필수 파일 존재 여부 체크 API
 *
 * Step 5 진입 시 JavaScript에서 호출하여 .env 파일의
 * 존재 여부 및 쓰기 가능 여부를 확인합니다.
 */

// installerHasFunction 의 출처 — 아래 출력 채널 가드가 쓴다 (전이 의존 금지, R4 취지).
// 이 파일은 최상위 실행문이 없고 BASE_PATH 를 자체 역산하므로 config 보다 앞에 와도 안전하다.
require_once __DIR__.'/../includes/execution-mode.php';

// 오류 텍스트가 JSON 본문에 섞이지 않게 한다 — **include 체인·가드보다
// 앞**이어야 한다. config/session/functions 안의 warning 이 배선보다 먼저 실행되면 그
// 텍스트가 본문 앞에 붙는 창이 그대로 남는다. `@` 는 진단 억제일 뿐이므로 가용성은
// installerHasFunction 이 판정한다 (execution-function-availability-via-ssot).
if (installerHasFunction('ini_set')) {
    @ini_set('display_errors', '0');
}
error_reporting(E_ALL);

// 필수 파일 include
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/_guard.php';
installer_guard_or_410();

// 다국어 로드 — 형제 6곳과 같은 규칙이다. 이 파일만 싣지 않아 아래 405 문구가
// 하드코딩 영문으로 나갔다(한국어 운영자에게 이 엔드포인트만 영문).
$currentLang = getCurrentLanguage();
$translations = loadTranslations($currentLang);

// JSON 응답 헤더.
// `no-store` 는 형제 GET 엔드포인트(check-configuration · scan-extensions)와 같은 규칙이다 —
// 운영자가 권한을 고치고 재검사했을 때 프록시·뒤로가기가 옛 「실패」를 재생하면 안 된다.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// GET 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo installer_json_encode([
        'success' => false,
        'message' => lang('error_get_method_required'),
        'message_key' => 'error_get_method_required',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$envPath = BASE_PATH.'/.env';

// 소유자 === 웹 서버 사용자 여부 판별 (chgrp/sudo 생략 기준)
$webUser = getWebServerUser();
// 가드는 **실제로 부르는 함수**를 본다. 종전에는 `posix_getuid` 를 함께 요구했는데 그
// 함수는 이 줄에서 한 번도 불리지 않고, 정작 인자를 만드는 `fileowner` 는 판정 대상이
// 아니었다 — 형제 판정(`check-configuration.php`)은 `posix_getpwuid` 만 보고 있어 같은
// 판정의 두 사본이 이미 갈려 있었다.
$baseOwner = installerHasFunction('posix_getpwuid') && function_exists('fileowner')
    ? (posix_getpwuid((int) fileowner(BASE_PATH))['name'] ?? null)
    : null;
$ownerIsWebUser = $webUser && $baseOwner && $webUser === $baseOwner;

echo installer_json_encode([
    'env_exists' => file_exists($envPath),
    'env_writable' => file_exists($envPath) && is_writable($envPath),
    'path' => BASE_PATH,
    'relative_path' => '.',
    'is_windows' => isWindows(),
    'owner_is_web_user' => $ownerIsWebUser,
], JSON_UNESCAPED_UNICODE);
