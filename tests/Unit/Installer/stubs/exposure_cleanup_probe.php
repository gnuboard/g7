<?php

/**
 * 노출 프로브 정리 스윕의 **glob 차단 분기** 하네스.
 *
 * `installerCleanupExposureProbe()` 는 「지울 것이 없었다」와 「쓸지 못했다」를 나눠 보고한다
 * (`swept` + `reason`). 그런데 그 두 갈래 중 **차단 쪽은 재는 검사가 없었다** — `glob` 은
 * `disable_functions` 로만 막을 수 있고, 그것은 돌고 있는 프로세스 안에서 만들 수 없다.
 * 형제 하네스(`requirements_probe.php` 계열)와 같은 방식으로 별도 프로세스에서 실행한다.
 *
 * 출력은 한 줄 JSON — 부모 테스트가 그것만 읽는다.
 */
$root = dirname(__DIR__, 4);

define('BASE_PATH', sys_get_temp_dir().'/g7-exposure-cleanup-'.getenv('G7_PROBE_SUFFIX'));

if (! is_dir(BASE_PATH.'/storage/installer')) {
    @mkdir(BASE_PATH.'/storage/installer', 0777, true);
}

require_once $root.'/public/install/includes/exposure-check.php';

$result = installerCleanupExposureProbe(BASE_PATH);

echo json_encode([
    'swept' => $result['swept'] ?? null,
    'reason' => $result['reason'] ?? null,
    'removed' => $result['removed'] ?? null,
]);
