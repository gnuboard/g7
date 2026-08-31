<?php

declare(strict_types=1);

/**
 * 직접 권한 부여(`installerRepairPermissions`) 하네스.
 *
 * 두 가지를 이 프로세스 밖에서만 잴 수 있어 하위 프로세스로 띄운다.
 *
 *  · `disable_functions=chmod` — `disable_functions` 는 PHP_INI_SYSTEM 이라 돌고 있는
 *    프로세스 안에서 만들 수 없다. PHP 8 은 막힌 함수를 함수 테이블에서 지우므로 무가드
 *    호출은 `Error`(치명)이고 `@` 는 가드가 아니다 — 이 안이 지원하려는 호스팅이 정확히
 *    그 프로필이다.
 *  · `BASE_PATH` — 상수라 프로세스마다 한 번만 정의할 수 있다.
 *
 * 사용:
 *   php permission_repair_probe.php <저장소 루트> <BASE_PATH> <대상 상대경로> [dir|file]
 */
$repoRoot = $argv[1] ?? '';
$basePath = $argv[2] ?? '';
$target = $argv[3] ?? '';
$kind = ($argv[4] ?? 'dir') === 'file' ? 'file' : 'dir';

if (! is_dir($repoRoot) || ! is_dir($basePath)) {
    fwrite(STDERR, 'repo root or base path missing');
    exit(2);
}

define('BASE_PATH', $basePath);
define('REQUIRED_DIRECTORY_PERMISSIONS', 0755);
define('REQUIRED_DIRECTORY_PERMISSIONS_DISPLAY', '755');
define('REQUIRED_DIRECTORIES', ['storage' => true]);
define('SUPPORTED_LANGUAGES', ['ko' => '한국어', 'en' => 'English']);
define('INSTALLER_BASE_URL', '/install');

require_once $repoRoot.'/public/install/includes/execution-mode.php';
require_once $repoRoot.'/public/install/includes/functions.php';

$full = $basePath.'/'.$target;

$result = installerRepairPermissions([$target], $kind === 'dir');

clearstatcache(true, $full);

echo json_encode([
    'repaired' => $result[$target] ?? null,
    'writable_after' => $kind === 'dir' ? installerDirectoryIsWritable($full) : is_writable($full),
    'mode_after' => substr(sprintf('%o', (int) @fileperms($full)), -3),
    'chmod_available' => installerHasFunction('chmod'),
]);
