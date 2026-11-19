<?php

/**
 * 인스톨러 세션 부팅(includes/session.php)을 **별도 프로세스**에서 그대로 실행하는 하네스.
 *
 * 이 파일은 인스톨러 모든 페이지의 최상단에서 로드되고, 그 안의 `ini_set('session.*')` 은
 * 응답 봉투가 서기 전에 돈다 — 막힌 호스팅에서 무가드로 부르면 첫 화면이 백지가 된다.
 * `disable_functions` 는 PHP_INI_SYSTEM 이라 실행 중인 프로세스 안에서 만들 수 없으므로,
 * 테스트가 `php -d disable_functions=…` 로 이 파일을 띄워 실제 부팅을 돌린다.
 */

require_once __DIR__.'/lang_stub.php';

$g7Root = dirname(__DIR__, 4);

require_once $g7Root.'/public/install/includes/config.php';
require_once $g7Root.'/public/install/includes/session.php';

echo json_encode([
    'session' => session_status() === PHP_SESSION_ACTIVE,
    'locale' => $_SESSION['g7_locale'] ?? null,
]);
