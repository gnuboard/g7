<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 설치 로그 기록 시 UTF-8 정규화(scrub) 회귀 테스트.
 *
 * 배경 (gnuboard/g7#62, dev-g7#445):
 *   Vendor 설치 방식 "Composer 실행" + 폴링 모드 조합에서 브라우저 콘솔에
 *   "Unexpected end of JSON input" 이 폭주하고 진행 상황이 표시되지 않는 회귀.
 *
 *   근본 원인: 폴링 워커가 composer install stdout 을 fgets() 로 읽어 정규화 없이
 *   addLog() 로 넘긴다. Windows 코드페이지(CP949) 출력이나 다운로드 진행바(\r 갱신)가
 *   임의 바이트 경계에서 잘리면 invalid UTF-8 바이트가 생기는데,
 *   _addLogInternal() 은 mb_detect_encoding(strict=true) 감지 실패 시 raw 바이트를
 *   그대로 로그에 저장한다. 그 로그를 포함한 폴링 응답 state-management.php 의
 *   json_encode() 가 invalid UTF-8 때문에 false 를 반환 → echo false = 빈 본문(HTTP 200)
 *   → 프론트 res.json() 이 "Unexpected end of JSON input" 실패.
 *
 * 본 테스트는 근본 지점을 검증한다: addLog() 에 invalid UTF-8 바이트를 주입해도
 *   getInstallationLogs() 로 읽은 로그 메시지가 항상 유효 UTF-8 이어서
 *   json_encode() 가 false 가 아니어야 한다.
 *
 * BASE_PATH 는 PHP 상수로 클래스 라이프사이클 단위 단 한 번 정의 (setUpBeforeClass).
 * InstallerStateSchemaTest 와 동일한 temp 격리 패턴을 사용한다.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class AddLogUtf8ScrubTest extends TestCase
{
    private static string $sharedBase = '';

    private static string $skipReason = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // 안전 가드: BASE_PATH 가 시스템 temp 하위가 아니면 (= 다른 테스트가 프로젝트 루트로 박은 경우)
        // setUp 의 installation.log @unlink 가 실제 설치 로그를 파괴할 수 있으므로 skip.
        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — '.
                    '다른 Installer 테스트의 BASE_PATH 정의가 선행됨. 격리 실행 필요: '.
                    'php vendor/bin/phpunit --filter=AddLogUtf8ScrubTest';

                return;
            }
            self::$sharedBase = (string) BASE_PATH;
        } else {
            self::$sharedBase = sys_get_temp_dir().'/g7-installer-addlog-test-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$sharedBase);
        }

        $logDir = self::$sharedBase.'/storage/logs';
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // config.php 의 INSTALLER_BASE_URL 계산이 $_SERVER['SCRIPT_NAME'] 에 의존
        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/index.php';
        }

        require_once dirname(__DIR__, 3).'/public/install/includes/config.php';
        require_once dirname(__DIR__, 3).'/public/install/includes/installer-state.php';
        require_once dirname(__DIR__, 3).'/public/install/includes/progress-emitter.php';
    }

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

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        // 각 테스트 시작 시 로그 초기화
        $logFile = self::$sharedBase.'/storage/logs/installation.log';
        if (file_exists($logFile)) {
            @unlink($logFile);
        }
    }

    /**
     * invalid UTF-8 바이트 표본.
     *
     * - 잘린 한글 선행 바이트 (EC 99 은 3바이트 UTF-8 의 앞 2바이트, 마지막 바이트 누락)
     * - 단독 0xFF (UTF-8 에서 절대 유효하지 않은 바이트)
     * - CP949 로 인코딩된 한글이 감지 실패하는 케이스 모사
     *
     * @return array<string, array{string}>
     */
    public static function invalidUtf8Provider(): array
    {
        return [
            'truncated hangul lead bytes' => ["Downloading \xEC\x99 package"],
            'lone 0xFF byte' => ["progress: abc\xFFdef 100%"],
            'truncated 4-byte sequence' => ["emoji \xF0\x9F head"], // 4바이트 UTF-8 의 앞 2바이트만
            'lone continuation byte' => ["tail\x80end"],
        ];
    }

    #[Test]
    #[DataProvider('invalidUtf8Provider')]
    public function addlog_stores_valid_utf8_even_for_invalid_input(string $rawMessage): void
    {
        // 사전 조건: 입력은 실제로 invalid UTF-8 이어야 테스트가 의미 있음
        $this->assertFalse(
            mb_check_encoding($rawMessage, 'UTF-8'),
            '테스트 입력 자체가 invalid UTF-8 이어야 한다 (표본 점검).'
        );

        $written = addLog($rawMessage);
        $this->assertTrue($written, 'addLog 는 성공적으로 로그를 기록해야 한다.');

        $logs = getInstallationLogs();
        $this->assertNotEmpty($logs, '기록한 로그가 조회되어야 한다.');

        $message = $logs[count($logs) - 1]['message'] ?? '';

        // 핵심 계약: 저장된 로그 메시지는 항상 유효 UTF-8 이어야 한다.
        $this->assertTrue(
            mb_check_encoding($message, 'UTF-8'),
            '저장된 로그 메시지는 항상 유효 UTF-8 이어야 한다 (invalid 바이트는 scrub 대체). '.
            '실패 시: composer 출력의 깨진 바이트가 로그에 raw 저장되어 폴링 응답 json_encode 가 무력화됨.'
        );
    }

    #[Test]
    #[DataProvider('invalidUtf8Provider')]
    public function polling_response_json_encode_never_returns_false(string $rawMessage): void
    {
        addLog($rawMessage);

        $logs = getInstallationLogs();

        // 폴링 응답(state-management.php getState) 이 만드는 것과 동일한 구조를 모사.
        $response = [
            'status' => 'running',
            'logs' => $logs,
            'log_total' => getInstallationLogCount(),
        ];

        // 근본 수정 후: logs 가 유효 UTF-8 이므로 substitute 플래그 없이도 false 가 아니어야 한다.
        $encoded = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $this->assertNotFalse(
            $encoded,
            'invalid UTF-8 로그가 섞여도 폴링 응답 json_encode 는 false 를 반환하면 안 된다 '.
            '(false → echo 빈 본문 → 프론트 "Unexpected end of JSON input").'
        );
        $this->assertIsString($encoded);
        $this->assertNotSame('', $encoded, '인코딩 결과 본문이 비어 있으면 안 된다.');
    }

    #[Test]
    public function addlog_preserves_valid_utf8_korean_unchanged(): void
    {
        // 정규화가 정상 UTF-8 한글을 훼손하지 않아야 한다 (회귀 방지).
        // 첫 로그 줄은 UTF-8 BOM 이 붙어 getInstallationLogs 의 [timestamp] 파싱에 간섭하므로
        // 워밍업 로그를 먼저 기록해 검증 대상 줄을 두 번째 이후로 밀어낸다.
        addLog('warmup');

        $korean = 'Composer 의존성 설치를 시작합니다.';

        addLog($korean);
        $logs = getInstallationLogs();
        $message = $logs[count($logs) - 1]['message'] ?? '';

        $this->assertSame($korean, $message, '정상 UTF-8 한글은 scrub 후에도 변형되지 않아야 한다.');
    }

    /**
     * SSE 이벤트 스트림도 invalid UTF-8 로그로 인해 빈 data 라인을 송출하면 안 된다 (인코딩 계약).
     *
     * 폴링 모드로 제보됐으나(#62), 근본 원인(외부 유래 invalid UTF-8 → json_encode false)은
     * SSE 모드에도 동일하게 존재한다. SseEmitter::emit 의 json_encode 가 false 를 반환하면
     * "data: \n\n" 이 송출되어 프론트 EventSource 의 JSON.parse(e.data) 가 throw → 로그 이벤트 유실.
     *
     * SseEmitter::emit 은 내부에서 즉시 flush() 하므로 ob_get_clean() 으로 출력을 캡처할 수 없다
     * (SSE 스트리밍 설계상 의도된 동작). 따라서 emit 이 사용하는 인코딩 계약을 직접 검증한다:
     * JSON_INVALID_UTF8_SUBSTITUTE 를 적용하면 invalid UTF-8 이 섞여도 false 가 아니어야 한다.
     */
    #[Test]
    #[DataProvider('invalidUtf8Provider')]
    public function sse_emit_encoding_contract_never_returns_false_for_invalid_utf8(string $rawMessage): void
    {
        // emit 이 실제 사용하는 것과 동일한 인코딩 호출.
        $payload = json_encode(
            ['message' => $rawMessage],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        $this->assertNotFalse(
            $payload,
            'SSE data 인코딩은 invalid UTF-8 로그가 섞여도 false 를 반환하면 안 된다 '.
            '(false → "data: \n\n" → 프론트 JSON.parse throw → 로그 이벤트 유실).'
        );
        $this->assertIsString($payload);

        // 프론트 JSON.parse(e.data) 와 동일하게 파싱 가능해야 한다.
        $decoded = json_decode($payload, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertIsArray($decoded);
    }

    /**
     * progress-emitter.php 의 SseEmitter::emit 이 JSON_INVALID_UTF8_SUBSTITUTE 를 적용하는지
     * 소스 레벨로 회귀 검증한다 (PollingResponseFlushTest 정적 검증과 동일 방식).
     */
    #[Test]
    public function sse_emitter_source_uses_invalid_utf8_substitute_flag(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3).'/public/install/includes/progress-emitter.php'
        );

        // SseEmitter 클래스 본문 추출
        $classStart = strpos($source, 'class SseEmitter');
        $this->assertNotFalse($classStart, 'SseEmitter 클래스를 찾을 수 없습니다.');
        $classEnd = strpos($source, 'class NullEmitter', $classStart);
        $sseBody = substr($source, $classStart, $classEnd !== false ? $classEnd - $classStart : null);

        $this->assertStringContainsString(
            'JSON_INVALID_UTF8_SUBSTITUTE',
            $sseBody,
            'SseEmitter::emit 의 json_encode 는 JSON_INVALID_UTF8_SUBSTITUTE 를 적용해 '.
            'invalid UTF-8 로그로 인한 빈 SSE data 라인 송출을 차단해야 한다.'
        );
    }
}
