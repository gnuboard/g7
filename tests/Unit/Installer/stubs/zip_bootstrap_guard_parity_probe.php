<?php

declare(strict_types=1);

/**
 * ZIP 부트스트랩 ↔ 인스톨러 진입 가드 완료 판정 대조 하네스.
 *
 * 부트스트랩은 압축이 풀리기 **전**에 돌기 때문에 `public/install/api/_guard.php` 를
 * require 할 수 없고, 같은 판정을 자기 안에 사본으로 갖는다. 사본이 갈리면 완료된 사이트에서
 * 압축 풀기가 다시 열리므로, 두 판정이 같은 답을 내는지 픽스처마다 대조한다.
 *
 * 두 상수(`BASE_PATH`)는 프로세스마다 한 번만 정의할 수 있어 픽스처당 하위 프로세스를 띄운다.
 *
 * 사용:
 *   php zip_bootstrap_guard_parity_probe.php <픽스처 디렉토리> <저장소 루트>
 */
$fixtureDir = $argv[1] ?? '';
$repoRoot = $argv[2] ?? '';

if (! is_dir($fixtureDir) || ! is_dir($repoRoot)) {
    fwrite(STDERR, 'fixture or repo root missing');
    exit(2);
}

define('BASE_PATH', $fixtureDir);
define('G7_SETUP_LIBRARY_ONLY', true);

require $fixtureDir.'/g7-setup.php';
require $repoRoot.'/public/install/api/_guard.php';

echo json_encode([
    'bootstrap' => g7setup_installation_completed(),
    'guard' => installer_is_completed(),
]);
