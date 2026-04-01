<?php

/**
 * 그누보드7 웹 인스톨러 - 필수 파일 존재 여부 체크 API
 *
 * Step 5 진입 시 JavaScript에서 호출하여 .env 파일의
 * 존재 여부 및 쓰기 가능 여부를 확인합니다.
 *
 * @package G7\Installer
 */

// 필수 파일 include
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// JSON 응답 헤더
header('Content-Type: application/json; charset=utf-8');

// GET 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Only GET requests are allowed.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$envPath = BASE_PATH . '/.env';

// 소유자 === 웹 서버 사용자 여부 판별 (chgrp/sudo 생략 기준)
$webUser = getWebServerUser();
$baseOwner = function_exists('posix_getpwuid') && function_exists('posix_getuid')
    ? (posix_getpwuid(fileowner(BASE_PATH))['name'] ?? null)
    : null;
$ownerIsWebUser = $webUser && $baseOwner && $webUser === $baseOwner;

echo json_encode([
    'env_exists' => file_exists($envPath),
    'env_writable' => file_exists($envPath) && is_writable($envPath),
    'path' => BASE_PATH,
    'relative_path' => '.',
    'is_windows' => isWindows(),
    'owner_is_web_user' => $ownerIsWebUser,
], JSON_UNESCAPED_UNICODE);
