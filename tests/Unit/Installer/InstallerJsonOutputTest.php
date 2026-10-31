<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 인스톨러 JSON 응답 경계 테스트.
 *
 * 배경 (gnuboard/g7#62, sir.kr 566085):
 *   `echo json_encode($data)` 는 $data 에 invalid UTF-8 이 섞이면 false 를 반환하고,
 *   `echo false` 는 빈 문자열이라 HTTP 200 + 빈 본문이 나간다. 프론트는
 *   "Unexpected end of JSON input" 으로 죽는데 서버에는 예외도 로그도 없다.
 *
 *   2026-07 수정(#445)은 로그 축 4곳만 막았다. 응답 경계 전체를 단일 헬퍼로 닫지 않으면
 *   다음 사람이 `echo json_encode` 를 다시 써서 같은 결함이 재발한다.
 *
 * BASE_PATH 격리 패턴은 AddLogUtf8ScrubTest 와 동일하다.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
/**
 * 소유 시나리오 매니페스트(마커 아님 — 평문): installer-process-output-utf8. 표식은 각 테스트 메서드가 단다.
 */
class InstallerJsonOutputTest extends TestCase
{
    use ScansPhpSourceFunctions;

    private static string $sharedBase = '';

    private static string $skipReason = '';

    /**
     * 시스템 임시 폴더 아래에 BASE_PATH 와 로그 폴더를 세우고 설치기 설정과 상태 모듈을 불러온다. BASE_PATH 가 이미 임시 폴더 밖으로 정의돼 있으면 건너뛸 사유만 남긴다.
     *
     * @return void 반환값 없음
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — '.
                    '격리 실행 필요: php vendor/bin/phpunit --filter=InstallerJsonOutputTest';

                return;
            }
            self::$sharedBase = (string) BASE_PATH;
        } else {
            self::$sharedBase = sys_get_temp_dir().'/g7-installer-json-test-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$sharedBase);
        }

        $logDir = self::$sharedBase.'/storage/logs';
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/index.php';
        }

        require_once dirname(__DIR__, 3).'/public/install/includes/config.php';
        require_once dirname(__DIR__, 3).'/public/install/includes/installer-state.php';
    }

    /**
     * 임시 BASE_PATH 에 남은 설치 로그를 지운다.
     *
     * @return void 반환값 없음
     */
    public static function tearDownAfterClass(): void
    {
        if (self::$skipReason === '' && self::$sharedBase !== '') {
            $logFile = self::$sharedBase.'/storage/logs/installation.log';
            if (file_exists($logFile)) {
                @unlink($logFile);
            }
        }
        parent::tearDownAfterClass();
    }

    /**
     * 건너뛸 사유가 있으면 건너뛰고, 아니면 앞 테스트가 남긴 설치 로그를 지워 로그 단언이 이 테스트의 기록만 보게 한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        $logFile = self::$sharedBase.'/storage/logs/installation.log';
        if (file_exists($logFile)) {
            @unlink($logFile);
        }
    }

    /**
     * UTF-8 문자열을 CP949 바이트로 바꿔 Windows 한글 계정명 같은 손상 입력 표본을 만든다.
     *
     * @param  string  $utf8  바꿀 UTF-8 원문
     * @return string CP949 로 인코딩한 바이트 문자열
     */
    private static function cp949(string $utf8): string
    {
        return (string) mb_convert_encoding($utf8, 'CP949', 'UTF-8');
    }

    /**
     * 표준 인코더를 실패시키는 CP949 한글 계정명이 설치기 JSON 인코더를 거치면 빈 본문이 아닌 파싱 가능한 JSON 이 되고 원문 한글로 복원되는지 확인한다.
     *
     * @scenario output_encoding=cp949_korean, value_position=scalar, sink=requirements_response
     *
     * @effects korean_account_name_restored_exactly, response_body_never_empty
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function encode_restores_cp949_korean_account_name(): void
    {
        $raw = self::cp949('it-manager\\티아모라');

        // 사전 조건: 표본이 실제로 결함을 재현해야 한다.
        $this->assertFalse(json_encode(['u' => $raw]), '표본은 표준 json_encode 를 false 로 만들어야 한다.');

        $json = installer_json_encode(['u' => $raw]);

        $this->assertIsString($json);
        $this->assertNotSame('', $json, '응답 본문은 절대 비어 있으면 안 된다.');

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, '반환값은 항상 파싱 가능한 JSON 이어야 한다.');
        $this->assertSame('it-manager\\티아모라', $decoded['u'], '한글 계정명이 원문 그대로 복원되어야 한다.');
    }

    /**
     * 잘린 UTF-8 과 단독 상위 바이트가 섞인 값도 빈 본문이 아닌 파싱 가능한 JSON 으로 인코딩되는지 확인한다.
     *
     * @scenario output_encoding=truncated_utf8, value_position=scalar
     *
     * @effects response_body_never_empty, unrecoverable_bytes_still_yield_encodable_utf8
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function encode_never_returns_empty_for_damaged_bytes(): void
    {
        foreach (["a\xFFb", "emoji \xF0\x9F head", "tail\x80end"] as $raw) {
            $json = installer_json_encode(['v' => $raw]);

            $this->assertIsString($json);
            $this->assertNotSame('', $json, '손상 바이트에서도 본문이 비면 안 된다.');
            $this->assertIsArray(json_decode($json, true), '반환값은 파싱 가능해야 한다.');
        }
    }

    /**
     * 인코딩이 끝내 실패해도 실패를 명시한 폴백 JSON 을 내고, 설치 로그에 실패 위치를 특정하는 줄을 남기는지 확인한다.
     *
     * @scenario sink=installation_log, value_position=scalar
     *
     * @effects encode_failure_logged_with_key_path, response_body_never_empty
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function encode_failure_falls_back_to_json_and_logs_key_path(): void
    {
        // INF 는 UTF-8 과 무관하게 json_encode 를 실패시킨다 (폴백 경로 강제 진입).
        $json = installer_json_encode(['bad' => INF]);

        $this->assertIsString($json);
        $this->assertNotSame('', $json, '인코딩이 실패해도 빈 본문을 내보내면 안 된다.');

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, '폴백 응답 자체는 반드시 파싱 가능해야 한다.');
        $this->assertFalse($decoded['success'], '폴백 응답은 실패를 명시해야 한다.');
        $this->assertSame('json_encode_failed', $decoded['error']);
        $this->assertArrayHasKey('invalid_paths', $decoded);

        // 관측성: 제보 사례에서 로그에 흔적이 0 이던 것이 이 결함의 핵심 어려움이었다.
        $logFile = self::$sharedBase.'/storage/logs/installation.log';
        $this->assertFileExists($logFile, '인코딩 실패는 반드시 설치 로그에 남아야 한다.');
        // meta:allow raw-source-read — 소스가 아니라 설치 로그(산출물)를 읽는다.
        $this->assertStringContainsString(
            '[installer-json] encode failed',
            (string) file_get_contents($logFile),
            '로그에 실패 위치를 특정할 수 있는 행이 있어야 한다.'
        );
    }

    /**
     * 인코딩 실패 폴백이 호출자가 넘긴 폴링 응답 형태(상태, 로그, 로그 총수)를 유지한 채 오류 정보를 얹는지 확인한다.
     *
     * @scenario sink=polling_state_response, value_position=nested_array_value
     *
     * @effects encode_failure_keeps_caller_fallback_shape, response_body_never_empty
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function encode_failure_preserves_caller_supplied_fallback_shape(): void
    {
        // 폴링 응답(state-management::getState)은 프론트 PollingMonitor 가 `state.status`
        // 로 진행/완료/실패를 판정한다. 그 키가 빠진 폴백은 파싱은 되지만 아무 전이도
        // 일으키지 못해 화면이 조용히 멈춘 것처럼 보이고, JSON 이 유효하므로 파싱 실패
        // 카운터(5회)에도 걸리지 않는다. 폴백에서도 소비자 계약이 유지되어야 한다.
        $json = installer_json_encode(
            ['logs' => ['line'], 'broken' => INF],
            JSON_UNESCAPED_UNICODE,
            ['status' => 'running', 'logs' => [], 'log_total' => 7]
        );

        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded, '폴백 응답은 파싱 가능해야 한다.');
        $this->assertSame('running', $decoded['status'], '호출자가 넘긴 status 가 폴백에 살아 있어야 한다.');
        $this->assertSame([], $decoded['logs']);
        $this->assertSame(7, $decoded['log_total']);

        // 오류 정보는 호출자 형태 위에 얹혀 함께 나간다.
        $this->assertFalse($decoded['success']);
        $this->assertSame('json_encode_failed', $decoded['error']);
    }

    /**
     * 정상 UTF-8 한글은 유니코드 이스케이프 없이 그대로 실리는 기존 계약을 유지하는지 확인한다.
     *
     * @scenario output_encoding=utf8, value_position=scalar
     *
     * @effects valid_utf8_passes_through_unchanged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function encode_keeps_unescaped_unicode_by_default(): void
    {
        $json = installer_json_encode(['msg' => '설치']);

        $this->assertStringContainsString('설치', $json, '기존 JSON_UNESCAPED_UNICODE 계약을 유지해야 한다.');
    }

    /**
     * 화면 스크립트에 박는 문자열 이스케이프가 따옴표 이스케이프 계약을 지키고, CP949 한글 입력에서도 빈 문자열로 떨어지지 않고 원문을 복원하는지 확인한다.
     *
     * @scenario sink=js_embedded_view, output_encoding=cp949_korean
     *
     * @effects korean_account_name_restored_exactly
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function js_escape_still_produces_valid_javascript_literal(): void
    {
        require_once dirname(__DIR__, 3).'/public/install/includes/functions.php';

        // JSON_HEX_QUOT 로 인용부호를 이스케이프하는 기존 계약(수정 전과 동일)
        $this->assertSame('"a\\u0022b"', js_escape('a"b'), 'js_escape 의 기존 이스케이프 계약이 유지되어야 한다.');

        // 한글 계정명이 섞여도 '""' 폴백으로 떨어지지 않아야 한다.
        $escaped = js_escape(self::cp949('티아모라'));
        $this->assertNotSame('""', $escaped, 'CP949 입력에서 빈 문자열 폴백으로 떨어지면 안 된다.');
        $this->assertSame('티아모라', json_decode($escaped, true));
    }

    /**
     * 웹 서버 사용자와 그룹 조회 결과가 출처와 무관하게 null 이거나 유효한 UTF-8 인지 확인한다.
     *
     * @scenario sink=requirements_response, value_position=scalar
     *
     * @effects web_server_user_always_valid_utf8
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function get_web_server_user_always_returns_valid_utf8(): void
    {
        require_once dirname(__DIR__, 3).'/public/install/includes/functions.php';

        $user = getWebServerUser();
        $group = getWebServerGroup();

        // 머신 무관 계약: 출처가 무엇이든 응답에 실릴 값은 항상 유효 UTF-8 이어야 한다.
        $this->assertTrue(
            $user === null || mb_check_encoding($user, 'UTF-8'),
            'getWebServerUser() 반환값은 항상 유효 UTF-8 이어야 한다.'
        );
        $this->assertTrue(
            $group === null || mb_check_encoding($group, 'UTF-8'),
            'getWebServerGroup() 반환값은 항상 유효 UTF-8 이어야 한다.'
        );
    }

    /**
     * 정적 계약: 인스톨러의 출력 경로에 raw `json_encode(` 가 남아 있으면 안 된다.
     *
     * 이 테스트가 없으면 다음 사람이 `echo json_encode` 를 다시 쓰고 같은 결함이 재발한다.
     *
     * @scenario sink=requirements_response
     *
     * @effects no_raw_json_encode_remains_in_installer_output_paths
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function no_raw_json_encode_remains_in_installer_output_paths(): void
    {
        $root = dirname(__DIR__, 3).'/public/install';

        /**
         * 면제 대상 (각각 사유 있음).
         *
         * - utf8.php          : installer_json_encode 구현 본체 (여기가 유일한 json_encode 자리)
         * - functions.php:574 : md5 캐시 키 생성 — 출력이 아니다
         * - _guard.php        : 다른 파일을 include 하지 않는 독립 진입 가드, ASCII 리터럴만 출력
         * - session.php       : config.php 보다 먼저 로드되는 경로가 있어 헬퍼를 못 쓴다, ko/en 리터럴만
         * - installer-state.php: addLog 재귀 금지 — 상태 저장은 자체 가드(§saveInstallationState)
         */
        $allowlist = [
            'includes/utf8.php',
            'api/_guard.php',
            'includes/session.php',
            'includes/installer-state.php',
        ];

        $violations = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (in_array($rel, $allowlist, true)) {
                continue;
            }

            // 호출 판정은 주석을 지운 소스로 한다 — 주석 처리된 호출은 출력 경로가 아니다.
            // 스트리퍼가 줄 수를 보존하므로 같은 줄 번호로 원문의 면제 표식을 찾는다.
            $lines = explode("\n", $this->sourceWithoutComments($file->getPathname()));
            // meta:allow raw-source-read — 면제 표식(json-encode:allow)은 주석 안에 있어 원문이 필요하다.
            // 이 원문은 표식을 찾는 데만 쓰고 호출 판정에는 쓰지 않는다.
            $rawLines = explode("\n", (string) file_get_contents($file->getPathname()));

            foreach ($lines as $i => $line) {
                // installer_json_encode 는 허용, 그 외 json_encode( 호출은 위반
                if (! preg_match('/(?<![A-Za-z0-9_])json_encode\s*\(/', $line)) {
                    continue;
                }
                if (str_contains($line, 'installer_json_encode')) {
                    continue;
                }

                // 출력이 아닌 용도(md5 캐시 키 등)는 같은 줄 또는 바로 위 주석의
                // 'json-encode:allow' 표식으로 면제한다 (사유를 코드에 남기게 하기 위함).
                $context = implode("\n", array_slice($rawLines, max(0, $i - 3), 4));
                if (str_contains($context, 'json-encode:allow')) {
                    continue;
                }

                $violations[] = $rel.':'.($i + 1).' — '.trim($line);
            }
        }

        $this->assertSame(
            [],
            $violations,
            "인스톨러 출력 경로에 raw json_encode 가 남아 있다. installer_json_encode 를 사용할 것:\n".
            implode("\n", $violations)
        );
    }

    /**
     * 프론트에 노출되는 신규 안내 문구가 ko/en 양쪽에 정의되어 있어야 한다.
     *
     * 누락 시 화면에 `error_empty_server_response` 같은 키가 그대로 노출된다.
     *
     * @scenario locale=ko
     *
     * @effects new_error_message_keys_defined_in_ko_and_en
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function new_error_message_keys_exist_in_both_locales(): void
    {
        $keys = [
            'error_empty_server_response',
            'error_invalid_server_response',
            'error_polling_response_invalid',
            'warning_best_effort_task_failed',
        ];

        foreach (['ko', 'en'] as $locale) {
            $path = dirname(__DIR__, 3)."/public/install/lang/{$locale}.php";
            $this->assertFileExists($path);

            /** @var array<string, string> $messages */
            $messages = require $path;

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $messages, "{$locale}.php 에 '{$key}' 가 정의되어야 한다.");
                $this->assertNotSame('', trim((string) $messages[$key]), "{$locale}.{$key} 는 비어 있으면 안 된다.");
            }
        }
    }
}
