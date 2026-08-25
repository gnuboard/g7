<?php

/**
 * 요구사항 화면(check-configuration.php ?action=requirements)을 **별도 프로세스**에서
 * 그대로 실행해 그 JSON 을 표준출력으로 흘려보내는 하네스 (13차 환경#1).
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
require_once $g7Root.'/public/install/api/check-configuration.php';

$api = new ValidationApi;

$method = (new ReflectionClass($api))->getMethod('checkRequirements');
$method->setAccessible(true);
$method->invoke($api);
