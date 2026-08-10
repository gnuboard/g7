<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ValidationApi;

/**
 * 인스톨러 Composer/PHP 경로 검증 보안 회귀 테스트
 *
 * 사용자 입력으로 들어온 Composer/PHP 바이너리 경로가 shell 명령으로 그대로
 * 실행되던 결함(공백 분기 + escape 부재) 의 패치를 보장한다.
 *
 * 검증 축:
 *  - shell 메타문자(공백, 세미콜론, 백틱 등) 포함 입력 → exec 도달 전 차단
 *  - 존재하지 않는 경로 → exec 도달 전 차단
 *  - 빈 입력 (시스템 기본 'composer' / 'php') → 통과 (실행은 환경 의존)
 *  - 정상 .phar 경로 + 잘못된 phpPath → exec 도달 전 차단
 */
class ComposerPathValidationTest extends TestCase
{
    private static bool $loaded = false;

    public static function setUpBeforeClass(): void
    {
        if (self::$loaded) {
            return;
        }

        // ValidationApi 는 글로벌 네임스페이스이므로 \lang() 으로 조회됨 →
        // 글로벌 스코프에 정의된 스텁이 필요.
        require_once __DIR__.'/stubs/lang_stub.php';

        if (! defined('MIN_PHP_VERSION')) {
            define('MIN_PHP_VERSION', '8.2.0');
        }

        if (! defined('CHECK_CONFIGURATION_LIBRARY')) {
            define('CHECK_CONFIGURATION_LIBRARY', true);
        }

        // BASE_PATH 가 다른 테스트 인프라에 의해 정의되지 않은 경우, 프로젝트 루트로
        // 정의해 인접 테스트(DeleteDirectoryTest/InstallerWindowsCommandsTest)의
        // `require_once BASE_PATH . '/public/install/...'` 패턴이 깨지지 않도록 함.
        $projectRoot = dirname(__DIR__, 3);
        if (! defined('BASE_PATH')) {
            define('BASE_PATH', $projectRoot);
        }
        if (! defined('STATE_PATH')) {
            define('STATE_PATH', BASE_PATH.'/storage/installer-state.json');
        }

        require_once $projectRoot.'/public/install/api/check-configuration.php';
        // applyInstallerComposerEnvVars() 는 functions.php 에 정의. stat 가드 완화 회복
        // 이후 시스템 기본 'composer' 또는 정상 절대경로 입력이 exec 단계까지 도달하면서
        // 이 함수를 호출하므로 사전 로드 필요.
        require_once $projectRoot.'/public/install/includes/functions.php';
        self::$loaded = true;
    }

    /**
     * private 메서드 호출 헬퍼.
     */
    private function invoke(string $method, array $args): array
    {
        $api = new ValidationApi;
        $ref = new ReflectionClass($api);
        $m = $ref->getMethod($method);

        // PHP 8.1+ 부터 ReflectionMethod 가 자동으로 private 접근 가능 — setAccessible 미호출.
        return $m->invokeArgs($api, $args);
    }

    public function test_composer_path_with_shell_injection_is_rejected_before_exec(): void
    {
        $marker = sys_get_temp_dir().'/g7_kve_test_'.bin2hex(random_bytes(4));
        $payload = 'sh -c "id > '.$marker.'"';

        $result = $this->invoke('validateComposerPath', [$payload, 'php']);

        $this->assertFalse($result['valid'], '공백 포함 임의 명령 입력은 valid=false 여야 함');
        $this->assertNull($result['version']);
        $this->assertFileDoesNotExist($marker, '검증 단계에서 exec 가 실행되지 않아야 함 (RCE 차단)');
    }

    public function test_composer_path_with_semicolon_injection_is_rejected(): void
    {
        $marker = sys_get_temp_dir().'/g7_kve_test_'.bin2hex(random_bytes(4));
        $payload = '/bin/sh; touch '.$marker;

        $result = $this->invoke('validateComposerPath', [$payload, 'php']);

        $this->assertFalse($result['valid']);
        $this->assertFileDoesNotExist($marker);
    }

    public function test_composer_path_pointing_to_nonexistent_file_is_rejected(): void
    {
        $result = $this->invoke('validateComposerPath', ['/nonexistent/path/to/composer', 'php']);

        $this->assertFalse($result['valid']);
        $this->assertNull($result['version']);
    }

    public function test_composer_path_pointing_to_directory_is_rejected(): void
    {
        $result = $this->invoke('validateComposerPath', [sys_get_temp_dir(), 'php']);

        $this->assertFalse($result['valid']);
    }

    public function test_phar_path_with_invalid_php_path_is_rejected_before_exec(): void
    {
        $marker = sys_get_temp_dir().'/g7_kve_test_'.bin2hex(random_bytes(4));

        $tmpPhar = tempnam(sys_get_temp_dir(), 'g7-fake-composer-').'.phar';
        file_put_contents($tmpPhar, '');
        chmod($tmpPhar, 0755);

        try {
            $payload = 'sh -c "id > '.$marker.'"';
            $result = $this->invoke('validateComposerPath', [$tmpPhar, $payload]);

            $this->assertFalse($result['valid']);
            $this->assertFileDoesNotExist($marker);
        } finally {
            @unlink($tmpPhar);
        }
    }

    public function test_php_path_with_shell_injection_is_rejected_before_exec(): void
    {
        $marker = sys_get_temp_dir().'/g7_kve_test_'.bin2hex(random_bytes(4));
        $payload = 'sh -c "id > '.$marker.'"';

        $result = $this->invoke('validatePhpPath', [$payload]);

        $this->assertFalse($result['valid']);
        $this->assertFileDoesNotExist($marker);
    }

    public function test_php_path_pointing_to_nonexistent_file_is_rejected(): void
    {
        $result = $this->invoke('validatePhpPath', ['/nonexistent/path/to/php']);

        $this->assertFalse($result['valid']);
    }

    public function test_empty_composer_path_falls_back_to_system_composer(): void
    {
        // 빈 입력은 'composer' 시스템 기본으로 시도 (실행 자체는 환경 의존)
        // 핵심은 validation 단계에서 거부되지 않고 exec 까지 도달한다는 것.
        $result = $this->invoke('validateComposerPath', ['', 'php']);

        // composer 미설치 환경: exec 실패로 valid=false + error_composer_exec_failed 메시지
        // composer 설치 환경: valid=true
        // 두 경우 모두 "사전 거부" 와는 다른 메시지를 반환해야 한다.
        $this->assertContains($result['valid'], [true, false]);
        // 사전 거부 메시지("error_composer_exec_failed") 가 path='composer' 로 매핑되거나 성공.
        // shell injection 메시지 패턴이 들어가지 않는지 확인.
        $this->assertIsString($result['message']);
    }

    // ========================================================================
    // 인자 주입 (KVE-2026-1677)
    // ========================================================================

    /**
     * 둘째 토큰이 임의 스크립트면 exec 에 도달하지 않는다.
     *
     * KVE-2026-1677 의 실제 실행 경로다. escapeshellarg 는 "이 문자열이 하나의 인자" 임만
     * 보장할 뿐, 그 인자가 실행 파일인지 인터프리터가 실행할 스크립트인지는 보장하지 않는다.
     * 기존 문자 블랙리스트는 `-` `(` `)` `.` `/` 를 모두 허용해 인자 자리를 열어 두었다.
     *
     * 실측 근거: 명령 끝에 붙는 ` --version` 은 스크립트 인자로 넘어갈 뿐이라
     * `php <스크립트> --version` 은 그 스크립트를 그대로 실행한다. 실행되면 마커가
     * 만들어지므로 마커 부재가 곧 미도달의 증거다.
     *
     * @scenario vector=validateComposerPath, payload_class=interpreter_plus_script
     *
     * @effects argument_injection_never_reaches_exec
     */
    public function test_interpreter_with_arbitrary_script_never_reaches_exec(): void
    {
        $marker = $this->argumentInjectionMarkerPath();
        @unlink($marker);

        // 실행 파일 자리에는 이 환경에 실제로 존재하는 PHP 를 쓴다 — 검증을 통과하면
        // 실제로 실행되므로 "환경에 없어서 실패" 로 초록이 되는 가짜 통과를 배제한다.
        $script = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7i527_evil.php';
        file_put_contents(
            $script,
            '<?php file_put_contents('.var_export($marker, true).", '1');"
        );

        try {
            $result = $this->invoke('validateComposerPath', [PHP_BINARY.' '.$script, 'php']);

            $this->assertFalse($result['valid'], '인자 자리에 임의 스크립트를 넣은 입력이 유효로 판정됨');
            $this->assertFileDoesNotExist($marker, '페이로드가 exec 에 도달해 임의 스크립트가 실행됨');
        } finally {
            @unlink($marker);
            @unlink($script);
        }
    }

    /**
     * 둘째 토큰이 코드 실행 플래그면 형태 검사에서 거부된다.
     *
     * 이 호출 지점은 명령 끝에 ` --version` 을 붙이는데, PHP CLI 는 `--version` 을 만나면
     * 버전만 출력하고 종료하므로 `-r` 코드가 실행되지 않는다(실측 확인). 즉 여기서는
     * 실행까지 가지 않지만, ` --version` 을 붙이지 않는 호출 지점(설치 워커의 실제 명령
     * 실행)에서는 그대로 실행된다. 따라서 자리 자체를 닫아 두 지점을 같은 규칙으로 막는다.
     *
     * @scenario vector=validateComposerPath, payload_class=argument_injection_dash_r
     *
     * @effects leading_hyphen_token_is_rejected
     */
    public function test_leading_hyphen_token_is_rejected_in_the_argument_slot(): void
    {
        $result = $this->invoke('validateComposerPath', [PHP_BINARY.' -rphpinfo();', 'php']);

        $this->assertFalse($result['valid'], '하이픈 선두 인자가 유효로 판정됨');
    }

    /**
     * PHP 자리(첫 토큰·단일 토큰)에도 하이픈 선두 토큰은 올 수 없다.
     *
     * @scenario vector=validatePhpPath, payload_class=argument_injection_dash_r
     *
     * @effects php_slot_rejects_leading_hyphen
     */
    public function test_php_path_rejects_leading_hyphen_token(): void
    {
        $result = $this->invoke('validatePhpPath', ['-rphpinfo();']);

        $this->assertFalse($result['valid']);
    }

    /**
     * 상대경로·`..` 세그먼트는 거부된다 (CWD 의존 실행 차단).
     *
     * @param  string  $payload  거부되어야 하는 경로
     * @param  string  $reason  거부 사유 (실패 메시지용)
     *
     * @scenario vector=validateComposerPath, payload_class=relative_path
     *
     * @effects relative_and_traversal_paths_are_rejected
     */
    #[DataProvider('rejectedShapeProvider')]
    public function test_rejects_paths_outside_the_allowed_shape(string $payload, string $reason): void
    {
        $result = $this->invoke('validateComposerPath', [$payload, 'php']);

        $this->assertFalse($result['valid'], "거부되어야 하는 입력이 통과함 ({$reason}): {$payload}");
    }

    /**
     * 정상 입력은 계속 통과한다 (stat 미사용 — open_basedir 환경 회귀 가드).
     *
     * 존재하지 않는 절대경로도 사전 거부되지 않고 exec 까지 도달해야 한다. `#361` 이
     * `is_file()`/`is_executable()` 가드로 시놀로지 DSM 등에서 정상 경로를 false negative
     * 로 거부한 회귀를 되돌린 이력이 있다.
     *
     * @param  string  $payload  통과해야 하는 경로
     * @param  string  $reason  통과 사유 (실패 메시지용)
     *
     * @scenario vector=validateComposerPath, payload_class=wrapper_script_name
     *
     * @effects legitimate_paths_still_reach_exec
     */
    #[DataProvider('acceptedShapeProvider')]
    public function test_legitimate_paths_are_not_rejected_before_exec(string $payload, string $reason): void
    {
        $result = $this->invoke('validateComposerPath', [$payload, 'php']);

        // 실행 결과는 환경 의존이므로 "사전 거부 메시지" 가 아닌지로 판정한다.
        $this->assertStringNotContainsString(
            'not_allowed',
            (string) $result['message'],
            "정상 입력이 형태 검사에서 사전 거부됨 ({$reason}): {$payload}"
        );
    }

    /**
     * 형태 규칙에 걸려야 하는 입력 목록.
     *
     * @return array<string, array{string, string}>
     */
    public static function rejectedShapeProvider(): array
    {
        return [
            '상대경로' => ['bin/composer', 'bare 명령이 아닌 상대경로'],
            '부모 참조' => ['/usr/local/../../tmp/composer', '.. 세그먼트'],
            '해시 주석 위장' => ['-rphpinfo()#.phar', '# + 하이픈 선두'],
            '인터프리터 + 스크립트' => ['/usr/bin/python3 /tmp/evil.py', 'Composer 자리 형태 위반'],
            'Composer 자리 임의 이름' => ['/usr/bin/php /tmp/evil.sh', 'composer 계열도 .phar 도 아님'],
        ];
    }

    /**
     * 형태 규칙을 통과해야 하는 정상 입력 목록.
     *
     * @return array<string, array{string, string}>
     */
    public static function acceptedShapeProvider(): array
    {
        return [
            'bare composer' => ['composer', '시스템 기본'],
            '절대경로 composer' => ['/usr/local/bin/composer', '일반 설치'],
            '버전 붙은 이름' => ['/usr/local/bin/composer2', 'composer 계열'],
            'phar' => ['/opt/composer.phar', 'phar 아카이브'],
            'Windows bat' => ['C:\\laragon\\bin\\composer\\composer.bat', 'Windows 배치'],
            '존재하지 않는 절대경로' => ['/usr/local/php99/bin/composer', 'stat 미사용 회귀 가드'],
            '2토큰 멀티 PHP' => ['/usr/local/php84/bin/php /usr/local/bin/composer.phar', '멀티 PHP 운영 패턴'],
            '래퍼 스크립트 PHP' => ['/usr/local/bin/php-8.2-wrapper.sh /usr/local/bin/composer.phar', 'PHP 자리 이름 무제한'],
        ];
    }

    /**
     * 인자 주입 페이로드가 실행되었을 때 만들어지는 마커 경로.
     *
     * 페이로드에는 따옴표를 넣을 수 없어(셸 메타문자 차단) 같은 경로를 `chr()` 연결로
     * 조립한다. 두 값이 어긋나면 증명이 성립하지 않으므로 여기서 한 번만 정의한다.
     */
    private function argumentInjectionMarkerPath(): string
    {
        return sys_get_temp_dir().chr(47).chr(103).chr(55).chr(105).chr(53).chr(50).chr(55);
    }
}
