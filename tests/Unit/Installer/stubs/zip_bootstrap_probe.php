<?php

declare(strict_types=1);

/**
 * ZIP 부트스트랩(`g7-setup.php`) 요청 하네스.
 *
 * 부트스트랩은 요청 스코프에서만 의미가 있고(`$_POST` · `exit`), 대상 디렉토리를
 * `__DIR__` 로 잡는다. 그래서 테스트는 임시 디렉토리에 사본을 두고 이 하네스를 하위
 * 프로세스로 띄워 그 사본을 실행한다.
 *
 * 사용:
 *   php zip_bootstrap_probe.php <부트스트랩 사본 경로> <POST 필드 JSON 의 base64>
 *
 * base64 로 감싸는 이유: Windows 의 `escapeshellarg()` 는 인자 안의 `"` 와 `%` 를 공백으로
 * 바꾼다 — JSON 을 그대로 넘기면 그 자리에서 깨져 POST 가 통째로 빈 배열이 된다.
 */
$script = $argv[1] ?? '';
$post = json_decode((string) base64_decode($argv[2] ?? '', true), true);

if (! is_string($script) || ! is_file($script)) {
    fwrite(STDERR, 'bootstrap copy not found');
    exit(2);
}

$_POST = is_array($post) ? $post : [];
$_GET = [];
$_REQUEST = $_POST;
$_SERVER['SCRIPT_NAME'] = '/'.basename($script);
$_SERVER['REQUEST_METHOD'] = $_POST === [] ? 'GET' : 'POST';
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';

require $script;
