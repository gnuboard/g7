<?php

/**
 * 요구사항 화면(check-configuration.php ?action=requirements)을 **별도 프로세스**에서
 * 그대로 실행해 그 JSON 을 표준출력으로 흘려보내는 하네스.
 *
 * 왜 하위 프로세스인가: 이 라운드가 닫은 결함은 `disable_functions` 로 함수가 제거된
 * 환경에서만 드러난다. 그 상태는 실행 중인 프로세스 안에서 만들 수 없으므로
 * (`disable_functions` 는 PHP_INI_SYSTEM), 테스트가 `php -d disable_functions=…` 로
 * 이 파일을 띄워 실제 요구사항 화면을 돌린다.
 *
 * 인스톨러는 Laravel 오토로드 밖의 순수 PHP 라서 config.php 가 공용 클래스를 직접
 * require 한다 — 그래서 이 하네스도 부팅 없이 성립한다.
 */
define('CHECK_CONFIGURATION_LIBRARY', true);

require_once __DIR__.'/lang_stub.php';

$g7Root = dirname(__DIR__, 4);

require_once $g7Root.'/public/install/includes/config.php';
require_once $g7Root.'/public/install/includes/functions.php';
// 런타임 메인 블록이 로드하는 선언 전용 include 를 그대로 미러링한다 — checkRequirements 가
// 웹 노출 프로브 대상 산출(installerExposureProbeTargets)을 부른다 (B-4).
require_once $g7Root.'/public/install/includes/exposure-check.php';
require_once $g7Root.'/public/install/api/check-configuration.php';

$api = new ValidationApi;

$method = (new ReflectionClass($api))->getMethod('checkRequirements');
$method->setAccessible(true);
$method->invoke($api);

// 이 하네스는 실 저장소 BASE_PATH 로 돈다(요구사항 판정이 실제 트리를 봐야 하므로). 그
// 부작용으로 웹 노출 프로브 sentinel 이 실 트리에 남는데, 테스트는 자기 흔적을 남기지
// 않는다(R7) — 런타임에서 finalize 가 하는 정리를 여기서도 그대로 수행한다.
$cleanup = installerCleanupExposureProbe();

// 정리가 `glob` 차단 모사에서 스스로 「쓸지 못했다」를 돌려주는 경우가 있다. 그 반환값을
// 보지 않으면 잔여물이 실 트리에 **영구히 쌓이고**, 그 디렉토리는 .gitignore 대상이라
// `git status` 에도 보이지 않아 아무도 알아채지 못한다. 정리 함수가 못 쓸었다고 말하면
// 하네스가 직접 걷는다 — 차단 모사는 이 프로세스 안에서만 유효하므로 여기서는 `glob` 을
// 쓸 수 있다고 가정하지 않고 디렉토리 순회로 처리한다.
// `scandir` 자체가 차단 대상일 수 있다 — 그러면 하네스도 걷을 수단이 없다.
// 무가드로 부르면 이 스텁이 치명 종료해 「화면이 뜨는가」를 재는 축이 그 함수에서만
// 거짓 실패한다(정리 함수가 `glob` 대신 `scandir` 로 나열하게 되면서 이
// 폴백이 처음으로 도달했다).
if (($cleanup['swept'] ?? true) === false && installerHasFunction('scandir')) {
    $probeDir = installerExposureProbeDir();
    if (is_dir($probeDir)) {
        foreach (@scandir($probeDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $prefixed = str_starts_with($entry, INSTALLER_EXPOSURE_PROBE_PREFIX)
                || str_starts_with($entry, INSTALLER_SYMLINK_PROBE_PREFIX);
            if ($prefixed) {
                @unlink($probeDir.DIRECTORY_SEPARATOR.$entry);
            }
        }
    }
    @unlink(installerProbeRateLimitStatePath());
}
