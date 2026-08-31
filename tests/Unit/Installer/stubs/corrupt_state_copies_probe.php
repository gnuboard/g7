<?php

/**
 * 파손 상태 사본 나열(`installerListCorruptStateCopies`)의 **glob 차단 분기** 하네스.
 *
 * 그 목록은 「같은 내용 1회」와 「상한 5」 두 가드의 유일한 근거다. `glob` 이 막힌
 * 호스팅에서 빈 목록을 돌려주면 두 가드가 동시에 증발해 폴링(2초)마다 마스킹 전 상태
 * 파일 사본이 무제한 증식한다 (14차 G3 W2-2). `glob` 은 `disable_functions` 로만 막을
 * 수 있고 그것은 돌고 있는 프로세스 안에서 만들 수 없으므로 형제 하네스
 * (`exposure_cleanup_probe.php`)와 같은 방식으로 별도 프로세스에서 실행한다.
 *
 * 출력은 한 줄 JSON — 부모 테스트가 그것만 읽는다.
 */
$root = dirname(__DIR__, 4);

define('BASE_PATH', sys_get_temp_dir().'/g7-corrupt-copies-'.getenv('G7_PROBE_SUFFIX'));

if (! is_dir(BASE_PATH.'/storage')) {
    @mkdir(BASE_PATH.'/storage', 0777, true);
}

require_once $root.'/public/install/includes/installer-state.php';

$prefix = STATE_PATH.INSTALLER_STATE_CORRUPT_INFIX;

// 생성 규칙과 같은 형태 둘 + 형식이 다른 이물 하나
file_put_contents($prefix.'20260101000000.0123abcd', '{');
file_put_contents($prefix.'20260102000000.89efdead', '{"x"');
file_put_contents($prefix.'notes', 'operator memo');

$listed = installerListCorruptStateCopies();

echo json_encode([
    'glob_available' => installerHasFunction('glob'),
    'count' => count($listed),
    'names' => array_map('basename', $listed),
]);
