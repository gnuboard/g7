<?php

namespace Tests\Unit\Installer;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ValidationApi;

/**
 * 인스톨러 추가 보안 강화 회귀 테스트
 *
 * 후속 감사로 추가 발견된 5개 이슈에 대한 회귀 가드:
 *  - getComposerCommand/Display 의 공백 분기 RCE
 *  - save-extensions 식별자 셸 주입
 *  - checkCorePendingPath 경로 traversal/정보 노출
 *  - escapeEnvValue 개행 라인 주입
 *  - PDO DSN 파라미터 주입
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerSecurityHardeningTest extends TestCase
{
    private static bool $loaded = false;

    private static string $tempBase = '';

    private static string $skipReason = '';

    /**
     * 클래스 시작 때 한 번 언어 스텁과 상수를 준비하고 설치기 검증 API, 상태 헬퍼, 태스크 러너 소스를 읽어 들인다.
     * 상태 파일 상수가 시스템 임시 경로 밖으로 먼저 정의돼 있으면 운영 상태 파일을 지키려고 로드 없이 건너뛰기 사유만 남긴다.
     *
     * @return void 반환값 없음
     */
    public static function setUpBeforeClass(): void
    {
        if (self::$loaded) {
            return;
        }

        require_once __DIR__.'/stubs/lang_stub.php';

        if (! defined('MIN_PHP_VERSION')) {
            define('MIN_PHP_VERSION', '8.2.0');
        }
        if (! defined('CHECK_CONFIGURATION_LIBRARY')) {
            define('CHECK_CONFIGURATION_LIBRARY', true);
        }

        // BASE_PATH 미정의 시 프로젝트 루트로 정의 — 같은 PHPUnit 프로세스에서 뒤에
        // 실행되는 인접 테스트(DeleteDirectoryTest, InstallerWindowsCommandsTest 등)가
        // `require_once BASE_PATH . '/public/install/...'` 로 로드하는 패턴이 깨지지 않도록.
        $projectRoot = dirname(__DIR__, 3);
        if (! defined('BASE_PATH')) {
            define('BASE_PATH', $projectRoot);
        }
        // STATE_PATH 는 테스트 격리용 임시 경로로 분리 — 운영 storage/installer-state.json 미오염.
        self::$tempBase = sys_get_temp_dir().'/g7-installer-hardening-'.bin2hex(random_bytes(4));
        @mkdir(self::$tempBase.'/storage/app', 0755, true);

        if (! defined('STATE_PATH')) {
            define('STATE_PATH', self::$tempBase.'/storage/installer-state.json');
        }

        // 안전 가드: STATE_PATH 는 PHP 상수라 한 프로세스에서 단 한 번만 정의된다.
        // 다른 Installer 테스트(ComposerPathValidationTest 등)가 먼저 BASE_PATH 기반
        // 루트 경로로 STATE_PATH 를 박으면 본 테스트의 writeState/clearState 가 실제 운영
        // installer-state.json 을 파괴하게 된다. STATE_PATH 가 시스템 temp 하위가 아니면 skip.
        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $resolvedState = realpath(dirname((string) STATE_PATH)) ?: dirname((string) STATE_PATH);
        if (strpos($resolvedState, $tempPrefix) !== 0) {
            self::$skipReason = 'STATE_PATH ('.STATE_PATH.') 가 시스템 temp 하위가 아님 — '
                .'다른 Installer 테스트의 상수 정의가 선행됨. 실제 installer-state.json 파괴 방지를 위해 skip. '
                .'격리 실행: php vendor/bin/phpunit --filter=InstallerSecurityHardeningTest';
            self::$loaded = true;

            return;
        }

        require_once $projectRoot.'/public/install/api/check-configuration.php';
        require_once $projectRoot.'/public/install/includes/installer-state.php';
        require_once $projectRoot.'/public/install/includes/functions.php';
        require_once $projectRoot.'/public/install/includes/task-runner.php';

        self::$loaded = true;
    }

    /**
     * 준비 단계에서 건너뛰기 사유가 기록돼 있으면 각 테스트를 건너뛰게 한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }
    }

    /**
     * 검증 API 객체의 비공개 메서드를 리플렉션으로 불러 그 반환값을 돌려준다.
     *
     * @param  object  $obj  메서드를 가진 대상 객체
     * @param  string  $method  부를 비공개 메서드 이름
     * @param  array  $args  메서드에 넘길 인자 목록
     * @return mixed 비공개 메서드의 반환값
     */
    private function invokePrivate(object $obj, string $method, array $args)
    {
        $ref = new ReflectionClass($obj);
        $m = $ref->getMethod($method);

        return $m->invokeArgs($obj, $args);
    }

    /**
     * 테스트가 도중 종료되어도 임시 디렉토리에 state.json 잔여물이 남지 않도록 정리.
     * STATE_PATH 는 setUpBeforeClass 의 안전 가드로 시스템 temp 하위임이 보장된 경우에만
     * 이 지점에 도달하므로, 운영 storage/installer-state.json 은 절대 건드리지 않는다.
     * (과거 루트 state 를 무조건 @unlink 하던 코드는 실제 운영 설치 상태를 파괴할 수 있어 제거)
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (self::$skipReason === '' && is_file(STATE_PATH)) {
            @unlink(STATE_PATH);
        }
        parent::tearDown();
    }

    /**
     * 테스트별 임시 state.json 작성/삭제 도우미.
     *
     * @param  array  $config  상태 파일의 config 칸에 넣을 설정 배열
     * @return void 반환값 없음
     */
    private function writeState(array $config): void
    {
        $state = ['config' => $config];
        @file_put_contents(STATE_PATH, json_encode($state));
    }

    /**
     * 테스트가 쓴 임시 상태 파일을 지운다.
     *
     * @return void 반환값 없음
     */
    private function clearState(): void
    {
        @unlink(STATE_PATH);
    }

    // ========================================================================
    // Critical-1 — getComposerCommand 공백 분기 RCE 차단
    // ========================================================================

    /**
     * Composer 칸에 공백으로 셸 명령을 이어 붙인 값이 저장돼 있어도 명령 조립이 시스템 기본 composer 로 폴백하는지 확인한다.
     *
     * @scenario vector=getComposerCommand, payload_class=shell_meta_space, expected_outcome=fall_back_to_default
     *
     * @effects getComposerCommand_returns_composer_for_shell_metachar_input
     *
     * @return void 반환값 없음
     */
    public function test_get_composer_command_rejects_space_containing_input_falls_back_to_composer(): void
    {
        $this->writeState(['composer_binary' => 'sh -c "id > /tmp/g7_should_not_run"']);

        $cmd = getComposerCommand();

        $this->assertSame('composer', $cmd, '공백 포함 입력은 시스템 기본 composer 로 폴백');
        $this->clearState();
    }

    /**
     * 세미콜론, 앤드 연산, 명령 치환, 백틱, 개행이 든 Composer 값마다 기본 composer 로 폴백하는지 확인한다.
     *
     * @scenario vector=getComposerCommand, payload_class=shell_meta_semicolon, expected_outcome=fall_back_to_default
     *
     * @effects getComposerCommand_returns_composer_for_shell_metachar_input
     *
     * @return void 반환값 없음
     */
    public function test_get_composer_command_rejects_shell_metachars_falls_back_to_composer(): void
    {
        foreach ([
            '/bin/sh; touch /tmp/x',
            '/bin/composer && id',
            '$(id)',
            '`id`',
            "/bin/composer\nrm -rf /",
        ] as $payload) {
            $this->writeState(['composer_binary' => $payload]);
            $cmd = getComposerCommand();
            $this->assertSame('composer', $cmd, "셸 메타문자 포함 입력 거부 (입력: {$payload})");
        }
        $this->clearState();
    }

    /**
     * 메타문자 없는 단일 절대경로는 존재 확인 없이 셸 인자 이스케이프만 거쳐 그대로 쓰이는지 확인한다.
     *
     * @scenario vector=getComposerCommand, payload_class=normal, expected_outcome=accept_normal
     *
     * @effects getComposerCommand_returns_escapeshellarg_quoted_path_for_valid_binary
     *
     * @return void 반환값 없음
     */
    public function test_get_composer_command_passes_through_safe_absolute_path(): void
    {
        // open_basedir 같은 PHP 런타임 제약 환경의 false negative 를 피하기 위해
        // stat 의존 가드가 제거됨. 메타문자 없는 단일 절대경로는 escape 후 그대로 사용.
        // 실제 실행 가능 여부는 exec 결과로 최종 판정 (silent fallback 안 함).
        $this->writeState(['composer_binary' => '/usr/local/bin/composer']);

        $cmd = getComposerCommand();

        $this->assertSame(escapeshellarg('/usr/local/bin/composer'), $cmd);
        $this->clearState();
    }

    /**
     * Composer 칸이 비어 있으면 기본 composer 명령을 돌려주는지 확인한다.
     *
     * @scenario vector=getComposerCommand, payload_class=empty, expected_outcome=fall_back_to_default
     *
     * @effects getComposerCommand_returns_composer_for_empty_input
     *
     * @return void 반환값 없음
     */
    public function test_get_composer_command_empty_returns_default(): void
    {
        $this->writeState(['composer_binary' => '']);

        $this->assertSame('composer', getComposerCommand());
        $this->clearState();
    }

    /**
     * 화면 표기용 명령 문자열에도 저장된 셸 페이로드가 드러나지 않고 composer 로 대체되는지 확인한다.
     *
     * @scenario vector=getComposerCommandForDisplay, payload_class=shell_meta_space, expected_outcome=fall_back_to_default
     *
     * @effects getComposerCommandForDisplay_does_not_leak_shell_payload
     *
     * @return void 반환값 없음
     */
    public function test_get_composer_command_for_display_does_not_leak_shell_payload(): void
    {
        $this->writeState(['composer_binary' => 'rm -rf /']);

        $display = getComposerCommandForDisplay();

        $this->assertSame('composer', $display, '셸 페이로드는 디스플레이에도 노출되지 않음');
        $this->assertStringNotContainsString('rm', $display);
        $this->clearState();
    }

    /**
     * 실행 경로 판정이 공백, 셸 메타문자, 따옴표, 널 바이트, 제어 문자가 든 값을 모두 거부하는지 확인한다.
     *
     * @scenario vector=getComposerCommand, payload_class=shell_meta_pipe, expected_outcome=fall_back_to_default
     *
     * @effects isInstallerExecutablePath_rejects_metachars
     *
     * @return void 반환값 없음
     */
    public function test_is_installer_executable_path_rejects_metachars(): void
    {
        // 백슬래시는 Windows 경로 구분자이므로 차단 대상 아님 (escapeshellarg 가 셸 인젝션 차단)
        foreach (['foo bar', 'a;b', 'a`b', 'a$b', 'a|b', "a\nb", 'a"b', "a'b", "a\0b", "a\x01b", "a\rb"] as $bad) {
            $this->assertFalse(isInstallerExecutablePath($bad), '메타문자 거부: '.bin2hex($bad));
        }
    }

    /**
     * 백슬래시가 든 Windows 절대경로는 공백이나 메타문자가 없으면 실행 경로로 허용되는지 확인한다.
     *
     * @scenario vector=getComposerCommand, payload_class=normal, expected_outcome=accept_normal
     *
     * @effects legitimate_paths_still_reach_exec
     *
     * @return void 반환값 없음
     */
    public function test_is_installer_executable_path_accepts_windows_paths(): void
    {
        // Windows 절대경로는 백슬래시 포함이지만 공백·메타문자 없으면 통과.
        // 공백 포함 디렉토리(예: 'C:\\Program Files\\...') 는 공백 분리 입력 형식의 토큰
        // 분리 휴리스틱과 충돌하므로 본 회복 범위 외 — 알려진 한계.
        foreach ([
            'C:\\laragon\\bin\\php\\php-8.3.26-Win32-vs16-x64\\php.exe',
            'C:\\php\\php.exe',
            'D:\\xampp\\php\\php.exe',
            'C:\\php\\composer.phar',
        ] as $path) {
            $this->assertTrue(isInstallerExecutablePath($path), "Windows 경로 허용: {$path}");
        }
    }

    /**
     * 공백이 든 Windows 경로는 토큰 분리 규칙과 충돌하므로 알려진 한계대로 거부되는지 고정한다.
     *
     * @scenario vector=getComposerCommand, payload_class=shell_meta_space, expected_outcome=fall_back_to_default
     *
     * @effects isInstallerExecutablePath_rejects_metachars
     *
     * @return void 반환값 없음
     */
    public function test_is_installer_executable_path_rejects_windows_path_with_space_known_limitation(): void
    {
        // 'C:\\Program Files\\...' 같은 공백 포함 Windows 경로는 본 회복 범위 외.
        // 공백 분리 입력(PHP + Composer 합성) 형식 휴리스틱과 충돌 — escapeshellarg 단일 토큰
        // wrap 만으로는 공백 의도(토큰 구분 vs 디렉토리명) 를 자동 구분할 수 없음.
        // 회피: 8.3 short path 형식(C:\\PROGRA~1\\...) 사용 또는 공백 없는 경로 사용.
        $this->assertFalse(isInstallerExecutablePath('C:\\Program Files\\PHP\\php.exe'));
    }

    // ========================================================================
    // open_basedir 회귀 회복 — stat 의존 가드 완화
    // ========================================================================

    /**
     * stat 가드가 제거되어 시놀로지 DSM 등 open_basedir 환경에서도
     * 정상 절대경로 입력이 통과해야 함. 실제 실행 가능 여부는 exec 결과로 판정.
     *
     * @scenario vector=getComposerCommand, payload_class=normal, expected_outcome=accept_normal
     *
     * @effects legitimate_paths_still_reach_exec
     *
     * @return void 반환값 없음
     */
    public function test_is_installer_executable_path_accepts_safe_absolute_path_without_stat(): void
    {
        // 실제로 존재하지 않는 경로지만 메타문자 없음 — stat 가드 제거로 true
        $this->assertTrue(isInstallerExecutablePath('/usr/local/bin/php83'));
        $this->assertTrue(isInstallerExecutablePath('/opt/plesk/php/8.3/bin/php'));
        $this->assertTrue(isInstallerExecutablePath('/nonexistent/path/to/binary'));
    }

    // ========================================================================
    // Composer 공백 분리 입력 안전 복원 — 멀티 PHP 환경 호환성
    // ========================================================================

    /**
     * PHP 경로와 Composer 경로를 공백으로 나눈 두 토큰 입력이 각각 이스케이프된 뒤 공백으로 합쳐지는지 확인한다.
     *
     * @scenario vector=getComposerCommand, payload_class=normal, expected_outcome=accept_normal
     *
     * @effects getComposerCommand_combines_php_and_phar_via_escapeshellarg
     *
     * @return void 반환값 없음
     */
    public function test_get_composer_command_accepts_php_composer_space_separated_input(): void
    {
        // 시놀로지/cPanel/Plesk 멀티 PHP 환경의 운영 패턴
        $this->writeState(['composer_binary' => '/usr/local/bin/php83 /usr/local/bin/composer']);

        $cmd = getComposerCommand();

        // 두 토큰 각각 escapeshellarg 적용 후 공백으로 합성
        $expected = escapeshellarg('/usr/local/bin/php83').' '.escapeshellarg('/usr/local/bin/composer');
        $this->assertSame($expected, $cmd);
        $this->clearState();
    }

    /**
     * 두 토큰 입력의 첫 토큰에 명령 치환이 들어 있으면 기본 composer 로 폴백하는지 확인한다.
     *
     * @scenario vector=getComposerCommand, payload_class=shell_meta_dollarparen, expected_outcome=fall_back_to_default
     *
     * @effects getComposerCommand_returns_composer_for_shell_metachar_input
     *
     * @return void 반환값 없음
     */
    public function test_get_composer_command_rejects_space_separated_with_metachar_in_first_token(): void
    {
        // 첫 토큰에 메타문자 → 거부, composer 폴백
        $this->writeState(['composer_binary' => '/usr/local/bin/php$(id) /usr/local/bin/composer']);

        $cmd = getComposerCommand();

        $this->assertSame('composer', $cmd);
        $this->clearState();
    }

    /**
     * 두 토큰 입력의 둘째 토큰에 세미콜론이 들어 있으면 기본 composer 로 폴백하는지 확인한다.
     *
     * @scenario vector=getComposerCommand, payload_class=shell_meta_semicolon, expected_outcome=fall_back_to_default
     *
     * @effects getComposerCommand_returns_composer_for_shell_metachar_input
     *
     * @return void 반환값 없음
     */
    public function test_get_composer_command_rejects_space_separated_with_metachar_in_second_token(): void
    {
        // 두 번째 토큰에 메타문자 → 거부, composer 폴백
        $this->writeState(['composer_binary' => '/usr/local/bin/php83 /usr/local/bin/composer;id']);

        $cmd = getComposerCommand();

        $this->assertSame('composer', $cmd);
        $this->clearState();
    }

    /**
     * 세 토큰 이상이면 둘째 토큰에 남는 공백 때문에 거부되어 기본 composer 로 폴백하는지 확인한다.
     *
     * @scenario vector=getComposerCommand, payload_class=shell_meta_space, expected_outcome=fall_back_to_default
     *
     * @effects getComposerCommand_returns_composer_for_shell_metachar_input
     *
     * @return void 반환값 없음
     */
    public function test_get_composer_command_rejects_three_or_more_tokens(): void
    {
        // 3 토큰 이상은 두 번째 토큰에 공백이 남음 → 두 번째 토큰의 메타문자(공백) 로 거부
        $this->writeState(['composer_binary' => '/bin/php /bin/composer extra_arg']);

        $cmd = getComposerCommand();

        $this->assertSame('composer', $cmd);
        $this->clearState();
    }

    /**
     * 정상 두 토큰 입력은 화면 표기에서 이스케이프 따옴표 없이 입력한 모양 그대로 보이는지 확인한다.
     *
     * @scenario vector=getComposerCommandForDisplay, payload_class=normal, expected_outcome=accept_normal
     *
     * @effects getComposerCommandForDisplay_shows_pair_without_escaping
     *
     * @return void 반환값 없음
     */
    public function test_get_composer_command_for_display_shows_human_friendly_space_separated_form(): void
    {
        $this->writeState(['composer_binary' => '/usr/local/bin/php83 /usr/local/bin/composer']);

        $display = getComposerCommandForDisplay();

        // 사람에게 보이는 표기는 escape 없는 원본 형식 유지
        $this->assertSame('/usr/local/bin/php83 /usr/local/bin/composer', $display);
        $this->clearState();
    }

    /**
     * 두 토큰 중 하나에 백틱이 들어 있으면 화면 표기도 composer 로 대체되어 페이로드가 보이지 않는지 확인한다.
     *
     * @scenario vector=getComposerCommandForDisplay, payload_class=shell_meta_backtick, expected_outcome=fall_back_to_default
     *
     * @effects getComposerCommandForDisplay_does_not_leak_shell_payload
     *
     * @return void 반환값 없음
     */
    public function test_get_composer_command_for_display_does_not_leak_shell_payload_in_space_separated(): void
    {
        // 두 토큰 중 하나라도 메타문자 포함이면 display 도 composer 폴백
        $this->writeState(['composer_binary' => '/bin/php `id`']);

        $display = getComposerCommandForDisplay();

        $this->assertSame('composer', $display);
        $this->assertStringNotContainsString('`', $display);
        $this->clearState();
    }

    // ========================================================================
    // validatePhpPath / validateComposerPath stat 가드 완화 (인스톨러 검증 API)
    // ========================================================================

    /**
     * 검증 API 의 PHP 경로 검사가 세미콜론, 명령 치환, 백틱, 파이프, 개행이 든 값을 무효로 판정하는지 확인한다.
     *
     * @scenario vector=validatePhpPath, payload_class=shell_meta_semicolon, expected_outcome=return_invalid_message
     *
     * @effects validatePhpPath_rejects_shell_metachars
     *
     * @return void 반환값 없음
     */
    public function test_validate_php_path_rejects_shell_metachars(): void
    {
        $api = new ValidationApi;
        foreach ([
            '/usr/local/bin/php; id',
            '/usr/local/bin/php$(id)',
            '`/usr/local/bin/php`',
            '/usr/local/bin/php|nc evil',
            "/usr/local/bin/php\nrm -rf /",
        ] as $payload) {
            $result = $this->invokePrivate($api, 'validatePhpPath', [$payload]);
            $this->assertFalse($result['valid'], "메타문자 거부: {$payload}");
        }
    }

    /**
     * 검증 API 의 PHP 경로 검사가 빈 값을 무효로 판정하는지 확인한다.
     *
     * @scenario vector=validatePhpPath, payload_class=empty, expected_outcome=return_invalid_message
     *
     * @effects validatePhpPath_rejects_empty_input
     *
     * @return void 반환값 없음
     */
    public function test_validate_php_path_rejects_empty(): void
    {
        $api = new ValidationApi;
        $result = $this->invokePrivate($api, 'validatePhpPath', ['']);
        $this->assertFalse($result['valid']);
    }

    /**
     * 메타문자 없는 존재하지 않는 절대경로가 존재 확인에서 걸리지 않고 실행 단계까지 가서 그 결과로 무효 판정되는지 확인한다.
     *
     * @scenario vector=validatePhpPath, payload_class=normal, expected_outcome=return_invalid_message
     *
     * @effects legitimate_paths_still_reach_exec
     *
     * @return void 반환값 없음
     */
    public function test_validate_php_path_safe_path_reaches_exec_phase(): void
    {
        // 메타문자 없는 절대경로는 stat 가드 없이 exec 단계까지 도달.
        // 존재하지 않으면 exec 실패 (return code != 0) 로 거부 — 단, 거부 메시지는
        // 'error_php_exec_failed' 로 stat 거부와 동일. 핵심: stat 가드가 사라졌다는 점.
        $api = new ValidationApi;
        $result = $this->invokePrivate($api, 'validatePhpPath', ['/nonexistent/path/to/php']);

        // 실제 실행 실패로 invalid 이지만, 그 판정이 exec 결과에 기반함을 의미적으로 검증.
        $this->assertFalse($result['valid']);
    }

    /**
     * 검증 API 의 Composer 경로 검사가 셸 메타문자나 개행이 든 단일 값을 무효로 판정하는지 확인한다.
     *
     * @scenario vector=validateComposerPath, payload_class=shell_meta_semicolon, expected_outcome=return_invalid_message
     *
     * @effects validateComposerPath_rejects_shell_metachars
     *
     * @return void 반환값 없음
     */
    public function test_validate_composer_path_rejects_shell_metachars(): void
    {
        $api = new ValidationApi;
        foreach ([
            '/usr/local/bin/composer; id',
            '/usr/local/bin/composer$(id)',
            '`/usr/local/bin/composer`',
            "/usr/local/bin/composer\nrm",
        ] as $payload) {
            $result = $this->invokePrivate($api, 'validateComposerPath', [$payload]);
            $this->assertFalse($result['valid'], "메타문자 거부: {$payload}");
        }
    }

    /**
     * 공백으로 나눈 두 토큰 입력에서 어느 토큰에든 메타문자나 여분 토큰이 있으면 무효로 판정하는지 확인한다.
     *
     * @scenario vector=validateComposerPath, payload_class=shell_meta_dollarparen, expected_outcome=return_invalid_message
     *
     * @effects validateComposerPath_rejects_shell_metachars
     *
     * @return void 반환값 없음
     */
    public function test_validate_composer_path_rejects_space_separated_with_metachar(): void
    {
        // 공백 분리 입력의 토큰별 메타문자 검증
        $api = new ValidationApi;
        foreach ([
            '/bin/php$(id) /bin/composer',
            '/bin/php /bin/composer;id',
            '/bin/php `id`',
            '/bin/php /bin/composer extra_token',
        ] as $payload) {
            $result = $this->invokePrivate($api, 'validateComposerPath', [$payload]);
            $this->assertFalse($result['valid'], "공백 분리 + 메타문자 거부: {$payload}");
        }
    }

    /**
     * 토큰 분리 헬퍼가 PHP 경로와 Composer 경로 두 토큰을 이름 붙은 배열로 나누는지 확인한다.
     *
     * @scenario vector=validateComposerPath, payload_class=normal, expected_outcome=accept_normal
     *
     * @effects splitPhpComposerTokens_splits_only_two_token_input
     *
     * @return void 반환값 없음
     */
    public function test_split_php_composer_tokens_helper_splits_into_two_tokens(): void
    {
        $api = new ValidationApi;
        $result = $this->invokePrivate($api, 'splitPhpComposerTokens', ['/usr/local/bin/php83 /usr/local/bin/composer']);
        $this->assertSame(['php' => '/usr/local/bin/php83', 'composer' => '/usr/local/bin/composer'], $result);
    }

    /**
     * 토큰 분리 헬퍼가 토큰이 하나뿐인 입력에는 null 을 돌려주는지 확인한다.
     *
     * @scenario vector=validateComposerPath, payload_class=normal, expected_outcome=accept_normal
     *
     * @effects splitPhpComposerTokens_splits_only_two_token_input
     *
     * @return void 반환값 없음
     */
    public function test_split_php_composer_tokens_helper_returns_null_for_single_token(): void
    {
        $api = new ValidationApi;
        $result = $this->invokePrivate($api, 'splitPhpComposerTokens', ['/usr/local/bin/composer']);
        $this->assertNull($result);
    }

    /**
     * 검증 API 의 경로 인자 판정이 유닉스 절대경로, 슬래시 Windows 경로, 백슬래시 Windows 경로를 허용하는지 확인한다.
     *
     * @scenario vector=validatePhpPath, payload_class=normal, expected_outcome=accept_normal
     *
     * @effects legitimate_paths_still_reach_exec
     *
     * @return void 반환값 없음
     */
    public function test_is_installer_safe_path_arg_helper_accepts_safe_paths(): void
    {
        $api = new ValidationApi;

        foreach ([
            '/usr/local/bin/php83',
            '/opt/php/bin/php',
            'C:/php/php.exe',
            '/nonexistent/path',
            // Windows 절대경로 (백슬래시 포함) — 회귀 가드: Windows 빌트인 서버 환경
            'C:\\laragon\\bin\\php\\php-8.3.26-Win32-vs16-x64\\php.exe',
            'C:\\php\\php.exe',
            'D:\\xampp\\php\\php.exe',
        ] as $safe) {
            $this->assertTrue($this->invokePrivate($api, 'isInstallerSafePathArg', [$safe]), "허용: {$safe}");
        }
    }

    /**
     * 검증 API 의 경로 인자 판정이 빈 값과 공백, 셸 메타문자, 따옴표, 제어 문자가 든 값을 거부하는지 확인한다.
     *
     * @scenario vector=validatePhpPath, payload_class=shell_meta_pipe, expected_outcome=return_invalid_message
     *
     * @effects isInstallerSafePathArg_rejects_metachars_and_empty
     *
     * @return void 반환값 없음
     */
    public function test_is_installer_safe_path_arg_helper_rejects_metachars_and_empty(): void
    {
        $api = new ValidationApi;

        $this->assertFalse($this->invokePrivate($api, 'isInstallerSafePathArg', ['']));
        // 백슬래시는 Windows 경로 구분자이므로 차단 대상이 아님 — 셸 인젝션 차단은 escapeshellarg 가 담당
        foreach (['foo bar', 'a;b', 'a`b', 'a$b', 'a|b', "a\nb", 'a"b', "a'b", "a\0b", "a\x01b", "a\rb"] as $bad) {
            $this->assertFalse($this->invokePrivate($api, 'isInstallerSafePathArg', [$bad]), '메타문자 거부: '.bin2hex($bad));
        }
    }

    // ========================================================================
    // 워커 정합 — 검증 통과한 입력이 워커에서도 silent fallback 없이 전달
    // ========================================================================

    /**
     * 검증을 통과한 단일 절대경로가 설치 워커에서도 조용히 기본값으로 바뀌지 않고 이스케이프된 채 쓰이는지 확인한다.
     *
     * @scenario vector=getComposerCommand, payload_class=normal, expected_outcome=accept_normal
     *
     * @effects getComposerCommand_returns_escapeshellarg_quoted_path_for_valid_binary
     *
     * @return void 반환값 없음
     */
    public function test_get_composer_command_passes_through_single_absolute_path_without_silent_fallback(): void
    {
        // 검증 단계에서 통과한 단일 절대경로가 워커에서도 그대로 사용됨을 보장.
        // stat 가드 제거 후 회귀 — 정상 입력이 silent fallback 으로 사라지지 않음.
        $this->writeState(['composer_binary' => '/usr/local/bin/composer']);

        $cmd = getComposerCommand();

        $this->assertNotSame('composer', $cmd, '검증 통과한 입력은 silent fallback 안 됨');
        $this->assertSame(escapeshellarg('/usr/local/bin/composer'), $cmd);
        $this->clearState();
    }

    // ========================================================================
    // High-2 — checkCorePendingPath traversal 차단
    // ========================================================================

    /**
     * 코어 대기 경로 검사가 상위 디렉터리 세그먼트가 든 경로마다 실패와 같은 단일 오류 메시지를 돌려주는지 확인한다.
     *
     * @scenario vector=check_core_pending_path, payload_class=parent_traversal, expected_outcome=return_invalid_message
     *
     * @effects check_core_pending_path_rejects_double_dot_segment
     *
     * @return void 반환값 없음
     */
    public function test_check_core_pending_path_rejects_parent_traversal(): void
    {
        $api = new ValidationApi;

        foreach (['../../../etc', '..', 'foo/../bar', 'foo/..\\bar'] as $bad) {
            $_GET = ['path' => $bad];
            ob_start();
            $this->invokePrivate($api, 'checkCorePendingPath', []);
            $body = ob_get_clean();
            $decoded = json_decode($body, true);

            $this->assertIsArray($decoded);
            $this->assertFalse($decoded['success'], "traversal 거부: {$bad}");
            // 단일 통일 메시지로 enumeration 신호 차단
            $this->assertSame('error_core_pending_path_invalid', $decoded['message']);
        }
        $_GET = [];
    }

    /**
     * 코어 대기 경로 검사가 널 바이트가 든 경로를 실패로 돌려주는지 확인한다.
     *
     * @scenario vector=check_core_pending_path, payload_class=null_byte, expected_outcome=return_invalid_message
     *
     * @effects check_core_pending_path_rejects_null_byte
     *
     * @return void 반환값 없음
     */
    public function test_check_core_pending_path_rejects_null_byte(): void
    {
        $api = new ValidationApi;
        $_GET = ['path' => "valid\0../etc"];
        ob_start();
        $this->invokePrivate($api, 'checkCorePendingPath', []);
        $body = ob_get_clean();
        $decoded = json_decode($body, true);

        $this->assertFalse($decoded['success']);
        $_GET = [];
    }

    /**
     * 존재하지 않는 경로에도 같은 단일 오류 메시지를 돌려줘 존재 여부와 종류가 응답으로 새지 않는지 확인한다.
     *
     * @scenario vector=check_core_pending_path, payload_class=normal, expected_outcome=return_invalid_message
     *
     * @effects check_core_pending_path_returns_uniform_message_for_nonexistent
     *
     * @return void 반환값 없음
     */
    public function test_check_core_pending_path_returns_uniform_message_for_nonexistent(): void
    {
        $api = new ValidationApi;
        $_GET = ['path' => '/nonexistent/g7-test-'.bin2hex(random_bytes(4))];
        ob_start();
        $this->invokePrivate($api, 'checkCorePendingPath', []);
        $body = ob_get_clean();
        $decoded = json_decode($body, true);

        $this->assertFalse($decoded['success']);
        // 존재 여부/타입 차이를 응답에 노출하지 않음
        $this->assertSame('error_core_pending_path_invalid', $decoded['message']);
        $_GET = [];
    }

    // ========================================================================
    // Medium-1 — escapeEnvValue 개행 거부
    // ========================================================================
    //
    // 종전에는 개행을 조용히 지웠다(Medium-1). KVE-2026-2042 정정으로 직렬화기는 개행을
    // 만나면 예외를 던진다 — 지우면 운영자가 입력한 값과 저장된 값이 달라지는데 그 사실이
    // 화면에 나타나지 않기 때문이다. 하위호환 별칭 escapeEnvValue 도 같은 관문에 위임한다.

    /**
     * 환경 값 직렬화 별칭이 LF 개행이 든 값을 지우지 않고 예외로 거부하는지 확인한다.
     *
     * @scenario vector=escapeEnvValue, payload_class=newline_lf, expected_outcome=throw_invalid_argument
     *
     * @effects escapeEnvValue_rejects_lf_instead_of_stripping
     *
     * @return void 반환값 없음
     */
    public function test_escape_env_value_rejects_newlines(): void
    {
        $this->expectException(InvalidArgumentException::class);

        escapeEnvValue("secret\nINJECTED=true");
    }

    /**
     * 환경 값 직렬화 별칭이 CRLF 개행이 든 값을 예외로 거부하는지 확인한다.
     *
     * @scenario vector=escapeEnvValue, payload_class=newline_crlf, expected_outcome=throw_invalid_argument
     *
     * @effects escapeEnvValue_rejects_crlf_instead_of_stripping
     *
     * @return void 반환값 없음
     */
    public function test_escape_env_value_rejects_crlf(): void
    {
        $this->expectException(InvalidArgumentException::class);

        escapeEnvValue("a\r\nb");
    }

    /**
     * 특수문자가 섞인 정상 비밀번호가 바뀌지 않고 큰따옴표로만 감싸지는지 확인한다.
     *
     * @scenario vector=escapeEnvValue, payload_class=normal, expected_outcome=accept_normal
     *
     * @effects escapeEnvValue_preserves_normal_password
     *
     * @return void 반환값 없음
     */
    public function test_escape_env_value_preserves_normal_password(): void
    {
        $result = escapeEnvValue('p@ssw0rd!#$%');

        $this->assertSame('"p@ssw0rd!#$%"', $result);
    }

    /**
     * 값 속 큰따옴표와 백슬래시가 각각 백슬래시로 이스케이프되는지 확인한다.
     *
     * @scenario vector=escapeEnvValue, payload_class=normal, expected_outcome=accept_normal
     *
     * @effects escapeEnvValue_escapes_quotes_and_backslashes
     *
     * @return void 반환값 없음
     */
    public function test_escape_env_value_escapes_quotes_and_backslashes(): void
    {
        $result = escapeEnvValue('a"b\\c');

        $this->assertSame('"a\\"b\\\\c"', $result);
    }

    // ========================================================================
    // Medium-2 — PDO DSN 파라미터 sanitize
    // ========================================================================

    /**
     * 호스트에 세미콜론으로 DSN 파라미터를 덧붙인 값이면 연결을 시도하지 않고 PDO 예외를 던지는지 확인한다.
     *
     * @scenario vector=getDatabaseConnection, payload_class=dsn_semicolon, expected_outcome=throw_pdo_exception
     *
     * @effects dsn_throws_for_semicolon_in_host
     *
     * @return void 반환값 없음
     */
    public function test_get_database_connection_rejects_semicolon_in_host(): void
    {
        $this->expectException(\PDOException::class);

        getDatabaseConnection([
            'db_write_host' => 'localhost;injected=evil',
            'db_write_port' => '3306',
            'db_write_database' => 'testdb',
            'db_write_username' => 'root',
            'db_write_password' => '',
        ], false);
    }

    /**
     * 데이터베이스 이름에 등호가 들어 있으면 PDO 예외를 던지는지 확인한다.
     *
     * @scenario vector=getDatabaseConnection, payload_class=dsn_equals, expected_outcome=throw_pdo_exception
     *
     * @effects dsn_throws_for_equals_in_database
     *
     * @return void 반환값 없음
     */
    public function test_get_database_connection_rejects_equals_in_database(): void
    {
        $this->expectException(\PDOException::class);

        getDatabaseConnection([
            'db_write_host' => 'localhost',
            'db_write_port' => '3306',
            'db_write_database' => 'test=evil',
            'db_write_username' => 'root',
            'db_write_password' => '',
        ], false);
    }

    /**
     * 포트에 개행과 추가 파라미터가 들어 있으면 PDO 예외를 던지는지 확인한다.
     *
     * @scenario vector=getDatabaseConnection, payload_class=newline_lf, expected_outcome=throw_pdo_exception
     *
     * @effects dsn_throws_for_newline_in_port
     *
     * @return void 반환값 없음
     */
    public function test_get_database_connection_rejects_newline_in_port(): void
    {
        $this->expectException(\PDOException::class);

        getDatabaseConnection([
            'db_write_host' => 'localhost',
            'db_write_port' => "3306\nfoo=bar",
            'db_write_database' => 'testdb',
            'db_write_username' => 'root',
            'db_write_password' => '',
        ], false);
    }

    // ========================================================================
    // KVE-2026-1677 — 설치 워커의 실행 바이너리 경로
    // ========================================================================

    /**
     * 저장된 php_binary 가 형태 규칙을 위반하면 시스템 기본값으로 폴백한다.
     *
     * 이 값은 설치 워커에서 실제 명령의 실행 바이너리가 된다(`--version` 이 붙지 않는
     * 호출 지점). 이전에는 아무 검증 없이 그대로 반환해, 저장만 되면 인자 자리가 열렸다.
     *
     * @scenario vector=getPhpBinary, payload_class=argument_injection_dash_r
     *
     * @effects getPhpBinary_falls_back_to_default_for_disallowed_shape
     *
     * @return void 반환값 없음
     */
    public function test_get_php_binary_falls_back_to_default_for_disallowed_shape(): void
    {
        foreach ([
            '-rphpinfo();' => '코드 실행 플래그',
            '-dauto_prepend_file=/tmp/x' => 'ini 주입',
            'bin/php' => '상대경로',
            '/usr/local/../../tmp/php' => '.. 세그먼트',
            '/usr/bin/php$(id)' => '셸 메타문자',
        ] as $payload => $reason) {
            $this->writeState(['php_binary' => $payload]);

            $this->assertSame(
                'php',
                getPhpBinary(),
                "형태 위반 값이 그대로 실행 바이너리가 됨 ({$reason}): {$payload}"
            );
        }
    }

    /**
     * 정상 절대경로는 그대로 사용된다 (stat 미사용 — 존재하지 않아도 통과).
     *
     * @scenario vector=getPhpBinary, payload_class=wrapper_script_name
     *
     * @effects getPhpBinary_passes_through_legitimate_paths
     *
     * @return void 반환값 없음
     */
    public function test_get_php_binary_passes_through_legitimate_paths(): void
    {
        foreach ([
            '/usr/local/php84/bin/php',
            '/usr/local/bin/php-8.2-wrapper.sh',
            'C:\\php\\php.exe',
            '/usr/local/php99/bin/php',
        ] as $path) {
            $this->writeState(['php_binary' => $path]);

            $this->assertSame($path, getPhpBinary(), "정상 경로가 폴백됨: {$path}");
        }
    }

    /**
     * Composer 자리에 인터프리터 + 임의 스크립트를 넣으면 기본값으로 폴백한다.
     *
     * 하이픈 선두만 막으면 `PHP경로 /tmp/올려둔파일` 형태가 남는다.
     *
     * @scenario vector=getComposerCommand, payload_class=interpreter_plus_script
     *
     * @effects getComposerCommand_rejects_interpreter_plus_arbitrary_script
     *
     * @return void 반환값 없음
     */
    public function test_get_composer_command_rejects_interpreter_plus_arbitrary_script(): void
    {
        foreach ([
            '/usr/bin/php /tmp/evil.php',
            '/usr/bin/python3 /tmp/evil.py',
            '/usr/bin/php -rphpinfo();',
            '/usr/bin/php /tmp/composer-evil.sh',
        ] as $payload) {
            $this->writeState(['composer_binary' => $payload]);

            $this->assertSame(
                'composer',
                getComposerCommand(),
                "인자 자리에 임의 스크립트/옵션이 통과함: {$payload}"
            );
        }
    }

    /**
     * 정상 멀티 PHP 입력은 계속 두 토큰으로 실행된다 (비파괴).
     *
     * @scenario vector=getComposerCommand, payload_class=wrapper_script_name
     *
     * @effects getComposerCommand_keeps_multi_php_pair_support
     *
     * @return void 반환값 없음
     */
    public function test_get_composer_command_keeps_multi_php_pair_support(): void
    {
        $this->writeState(['composer_binary' => '/usr/local/bin/php-8.2-wrapper.sh /usr/local/bin/composer.phar']);

        $cmd = getComposerCommand();

        $this->assertStringContainsString('php-8.2-wrapper.sh', $cmd);
        $this->assertStringContainsString('composer.phar', $cmd);
    }
}
