<?php

/**
 * `finalize-env.php` 를 **격리된 임시 설치 루트**에서 실제로 태우는 하네스 (13차 #D8).
 *
 * 이 엔드포인트는 `.env` 를 템플릿에서 새로 만들어 통째로 대체한다 — 운영자가 설치 전에
 * 넣어 둔 값이 사라지는 지점이다. 「쓰기 직전 사본」이 실제로 남는지는 함수 단위가 아니라
 * 이 흐름을 태워야 확인된다(호출이 쓰기 **뒤**에 있으면 사본은 새 내용을 담는다).
 *
 * 실 저장소를 건드리지 않도록 `BASE_PATH` 를 인자로 받은 임시 디렉토리로 먼저 박는다 —
 * config.php 는 이미 정의된 상수를 덮어쓰지 않는다.
 *
 * 사용: php finalize_env_probe.php <임시 설치 루트>
 */
$installRoot = $argv[1] ?? '';

if ($installRoot === '' || ! is_dir($installRoot)) {
    fwrite(STDERR, "usage: finalize_env_probe.php <install-root>\n");
    exit(2);
}

define('BASE_PATH', $installRoot);

// finalize 는 POST 만 받는다 (11차 #A1) — CLI 에는 요청 메서드가 없으므로 채워 준다.
$_SERVER['REQUEST_METHOD'] = 'POST';

require_once dirname(__DIR__, 4).'/public/install/api/finalize-env.php';
