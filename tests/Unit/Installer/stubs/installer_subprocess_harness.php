<?php

/**
 * 인스톨러 엔드포인트·상태 함수를 격리된 PHP 프로세스에서 실행하는 하네스.
 *
 * 인스톨러는 BASE_PATH 상수(프로세스당 1회 정의)로 storage 경로를 정한다. PHPUnit 프로세스에서
 * 다른 테스트가 먼저 실 프로젝트 루트로 정의하면 실제 installer-state.json·runtime.php·.env 를
 * 건드리게 된다. 그래서 파일을 쓰는 검증은 이 하네스로 새 프로세스를 띄우고, 시스템 temp 아래의
 * 임시 루트를 BASE_PATH 로 먼저 정의한다.
 *
 * 사용: php installer_subprocess_harness.php <spec.json>
 *
 * spec:
 *   base_path   임시 앱 루트 (시스템 temp 하위여야 한다 — 아니면 즉시 중단)
 *   mode        'endpoint' | 'script'
 *   endpoint    (endpoint) 실행할 엔드포인트 절대경로
 *   method      (endpoint) REQUEST_METHOD, 기본 POST
 *   get/post    (endpoint) $_GET / $_POST
 *   headers     (endpoint) $_SERVER 에 넣을 HTTP_* 키
 *   session_id  세션 ID (같은 ID 로 부르면 이전 호출의 세션이 이어진다)
 *   session     주면 세션을 이 값으로 덮어쓴다
 *   json_body   (endpoint) 요청 본문 — php://input 으로 읽힌다 (배열이면 JSON 인코딩)
 *   script      (script) 인스톨러 include 를 로드한 뒤 실행할 PHP 코드
 *
 * 출력 끝에 `__HARNESS_STATUS__=<http status>` 와 `__HARNESS_SESSION__=<json>` 을 붙인다.
 */
$spec = json_decode((string) file_get_contents($argv[1] ?? ''), true);
if (! is_array($spec)) {
    fwrite(STDERR, "invalid spec\n");
    exit(2);
}

$tempRoot = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
$basePath = realpath((string) ($spec['base_path'] ?? '')) ?: '';
if ($basePath === '' || strpos($basePath, $tempRoot) !== 0) {
    fwrite(STDERR, "base_path must be under the system temp directory\n");
    exit(3);
}

define('BASE_PATH', $basePath);

$projectRoot = dirname(__DIR__, 4);

// 경고는 stderr 로 — CLI 에서는 표준출력이 먼저 나가면 http_response_code() 가 기록되지 않는다.
ini_set('display_errors', 'stderr');

// config.php 는 BASE_PATH/app/Support 의 세 클래스를 require 한다 — 임시 루트에는 없으므로 실 소스를 미리 로드.
// (PhpExtensionRequirements 는 자기 옆의 Requirements/php-extensions.json 을 읽으므로 실 소스 경로에서 로드해야 한다)
require_once $projectRoot.'/app/Support/PrivilegedDatabaseAccounts.php';
require_once $projectRoot.'/app/Support/OpcacheStatus.php';
require_once $projectRoot.'/app/Support/PhpExtensionRequirements.php';

$mode = $spec['mode'] ?? 'endpoint';
$endpoint = (string) ($spec['endpoint'] ?? '');

$_SERVER['REQUEST_METHOD'] = $spec['method'] ?? 'POST';
$_SERVER['SCRIPT_NAME'] = '/install/api/'.basename($endpoint !== '' ? $endpoint : 'harness.php');
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
foreach (($spec['headers'] ?? []) as $key => $value) {
    $_SERVER[$key] = $value;
}
$_GET = $spec['get'] ?? [];
$_POST = $spec['post'] ?? [];

// 요청 본문(JSON) — CLI SAPI 에는 php://input 이 비어 있으므로 그 경로만 가로채 spec 값을 돌려준다.
if (array_key_exists('json_body', $spec)) {
    $GLOBALS['__harness_input'] = is_string($spec['json_body']) ? $spec['json_body'] : (string) json_encode($spec['json_body']); // json-encode:allow — 테스트 하네스 입력

    final class HarnessPhpInputStream
    {
        /** @var resource|null 스트림 컨텍스트 (PHP 가 채운다) */
        public $context;

        /** @var resource 실제 핸들 */
        private $handle;

        /**
         * php://input 이면 spec 본문을 담은 메모리 스트림, 그 밖의 php:// 경로는 원래 래퍼로 연다.
         *
         * @param  string  $path  열 경로
         * @param  string  $mode  열기 모드
         * @param  int  $options  스트림 옵션 (미사용)
         * @param  string|null  $opened  실제로 연 경로 (미사용)
         * @return bool 열기 성공 여부
         */
        public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
        {
            stream_wrapper_restore('php');
            try {
                if (strtolower($path) === 'php://input') {
                    $this->handle = fopen('php://memory', 'w+b');
                    fwrite($this->handle, $GLOBALS['__harness_input']);
                    rewind($this->handle);
                } else {
                    $this->handle = fopen($path, $mode);
                }
            } finally {
                stream_wrapper_unregister('php');
                stream_wrapper_register('php', self::class);
            }

            return is_resource($this->handle);
        }

        /**
         * 읽기.
         *
         * @param  int  $count  읽을 바이트 수
         * @return string|false 읽은 내용
         */
        public function stream_read(int $count): string|false
        {
            return fread($this->handle, $count);
        }

        /**
         * 쓰기.
         *
         * @param  string  $data  쓸 내용
         * @return int|false 쓴 바이트 수
         */
        public function stream_write(string $data): int|false
        {
            return fwrite($this->handle, $data);
        }

        /**
         * 끝 도달 여부.
         *
         * @return bool 끝이면 true
         */
        public function stream_eof(): bool
        {
            return feof($this->handle);
        }

        /**
         * 스트림 정보.
         *
         * @return array<int|string, int>|false fstat 결과
         */
        public function stream_stat(): array|false
        {
            return fstat($this->handle);
        }

        /**
         * 닫기.
         *
         * @return void 반환값 없음
         */
        public function stream_close(): void
        {
            fclose($this->handle);
        }
    }

    stream_wrapper_unregister('php');
    stream_wrapper_register('php', HarnessPhpInputStream::class);
}

ini_set('session.save_path', BASE_PATH.'/sessions');
ini_set('session.use_cookies', '0');
ini_set('session.use_strict_mode', '0');
session_id((string) ($spec['session_id'] ?? 'harness'));
session_start();
if (array_key_exists('session', $spec)) {
    $_SESSION = is_array($spec['session']) ? $spec['session'] : [];
}

register_shutdown_function(static function (): void {
    $session = $_SESSION ?? [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    // 명시 설정이 없으면 CLI 는 false 를 돌려준다 — 웹 서버 기본값 200 으로 본다
    $status = http_response_code();
    echo "\n__HARNESS_STATUS__=".($status === false ? 200 : (int) $status)."\n";
    // json-encode:allow — 테스트 하네스 출력
    echo '__HARNESS_SESSION__='.json_encode($session)."\n";
});

if ($mode === 'script') {
    require_once $projectRoot.'/public/install/includes/config.php';
    require_once $projectRoot.'/public/install/includes/functions.php';
    require_once $projectRoot.'/public/install/includes/installer-state.php';
    require_once $projectRoot.'/public/install/includes/installer-runtime.php';
    require_once $projectRoot.'/public/install/includes/installer-ownership.php';
    eval((string) ($spec['script'] ?? ''));
    exit(0);
}

require $endpoint;
