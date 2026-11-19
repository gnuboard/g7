<?php

/**
 * 단계별 실행(stepwise)이 태스크를 돌리기 직전에 반드시 지나는 런타임 판정들을
 * **별도 프로세스**에서 그대로 실행하는 하네스 (18차).
 *
 * ## 왜 형제 하네스로 부족한가
 *
 * `session_boot_probe` 와 `requirements_probe` 는 **첫 화면 경로**만 본다. 그런데 G2 가
 * 신설한 경로 — `run-task.php` 가 요청마다 부르는 시간 예산 판정과 in-process 부팅의
 * 메모리 상향 — 은 그 두 화면을 지난 **뒤에** 처음 실행된다. 그래서 요구사항 화면이
 * 초록으로 통과한 환경에서도 태스크는 **한 개도** 돌지 않을 수 있고, 그때까지의 어떤
 * 검사도 그 구간을 `disable_functions` 아래에서 실행해 본 적이 없었다.
 *
 * 실제로 `installerSetTimeLimit()` 은 `set_time_limit` 만 가드하고 바로 다음 줄의
 * `ini_get('max_execution_time')` 은 무가드였다 — 같은 함수 본문 안의 비대칭이다.
 *
 * 인스톨러는 Laravel 오토로드 밖의 순수 PHP 라서 config.php 가 공용 클래스를 직접
 * require 한다 — 그래서 이 하네스도 부팅 없이 성립한다.
 */

require_once __DIR__.'/lang_stub.php';

$g7Root = dirname(__DIR__, 4);

require_once $g7Root.'/public/install/includes/config.php';
require_once $g7Root.'/public/install/includes/functions.php';
require_once $g7Root.'/public/install/includes/execution-mode.php';

// ① 시간 예산 판정 — run-task.php 가 lock 을 잡기 **전에** 부른다. 여기서 죽으면
//    stepwise 응답은 JSON 봉투조차 서지 못한 500 이 된다.
$budget = installerSetTimeLimit(180);

// ② 가드 판정 SSoT — 인스톨러 전역이 이것을 통해 가용성을 묻는다. 자기 자신이
//    무가드 호출을 품고 있으면 「가드가 있다」고 선언한 지점이 전부 함께 죽는다.
$hasExec = installerHasFunction('exec');
$hasIniSet = installerHasFunction('ini_set');

// ③ 진단 채널 — 상태 저장이 실패했을 때 사유가 남는 유일한 통로.
installerErrorLog('[stepwise-probe] runtime judgment reached');

echo json_encode([
    'budget' => $budget,
    'has_exec' => $hasExec,
    'has_ini_set' => $hasIniSet,
]);
