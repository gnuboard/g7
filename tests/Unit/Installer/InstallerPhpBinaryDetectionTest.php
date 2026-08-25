<?php

namespace Tests\Unit\Installer;

use App\Support\ExecutionEnvironment;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ValidationApi;

/**
 * 인스톨러 PHP 바이너리 감지·검증의 SAPI 인지 회귀 테스트 (9차 #M8 · #L7).
 *
 * `php-fpm --version` · `php-cgi --version` 은 CLI 와 똑같이 정상 종료하고 같은 형태로
 * 버전을 찍는다 — 구분은 괄호 안 SAPI 이름뿐이다. 코어(`ExecutionEnvironment::detect`)는
 * 이 판정을 3·5차 라운드에서 닫았는데 인스톨러의 사본(`validatePhpPath`·`detectPhpBinaries`)
 * 에는 그 판정이 없었고, 화면은 감지 1순위를 입력란에 자동 대입한다(3-configuration.php).
 * mod_php/FPM 에서 `PHP_BINARY` 는 그 웹 SAPI 자신이므로, 정렬이 없으면 운영자가 아무것도
 * 입력하지 않아도 artisan 을 받지 못하는 바이너리가 실행 경로로 굳는다.
 *
 * 고정하는 계약:
 *  - 검증 결과가 `sapi` 를 함께 돌려준다 (없으면 CLI 여부를 판정할 근거가 사라진다)
 *  - 감지 결과는 CLI 후보가 앞에 온다 (화면 자동 대입이 그 첫 후보를 쓴다)
 *  - 요구사항 화면의 CLI 버전 비교도 같은 probe 를 쓰고 SAPI 를 응답에 남긴다
 *
 * 커버 축: php_binary_sapi cli·non_cli·unknown / php_binary_source detected_autodetect
 *
 * @effects installer_php_validation_reports_sapi, installer_php_candidates_are_cli_first, installer_cli_version_check_reports_sapi
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerPhpBinaryDetectionTest extends TestCase
{
    private static bool $loaded = false;

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

        $projectRoot = dirname(__DIR__, 3);
        if (! defined('BASE_PATH')) {
            define('BASE_PATH', $projectRoot);
        }
        if (! defined('STATE_PATH')) {
            define('STATE_PATH', BASE_PATH.'/storage/installer-state.json');
        }

        require_once $projectRoot.'/public/install/api/check-configuration.php';
        require_once $projectRoot.'/public/install/includes/functions.php';

        self::$loaded = true;
    }

    /**
     * @param  array<int, mixed>  $args
     */
    private function invoke(string $method, array $args = []): mixed
    {
        $api = new ValidationApi;
        $ref = new ReflectionClass($api);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($api, $args);
    }

    /**
     * #M8 — 검증 결과가 SAPI 를 함께 돌려준다.
     */
    public function test_validation_reports_sapi_for_the_running_php(): void
    {
        if (! ExecutionEnvironment::hasFunction('exec')) {
            $this->markTestSkipped('exec 차단 환경 — 바이너리 probe 는 측정 대상이 아니다');
        }

        $result = $this->invoke('validatePhpPath', [PHP_BINARY]);

        $this->assertArrayHasKey('sapi', $result, 'SAPI 가 없으면 FPM/CGI 바이너리를 CLI 와 구분할 수 없다');
        $this->assertTrue($result['valid']);
        $this->assertSame('cli', $result['sapi'], '테스트는 CLI 에서 돌므로 PHP_BINARY 는 CLI 판이다');
    }

    /**
     * #M8 — 감지 결과 정렬은 CLI 후보를 앞에 둔다.
     *
     * 탐색은 실제 프로세스를 스폰하므로 이 저장소 환경에서는 CLI 후보만 나온다 — 정렬을
     * 지워도 탐색 경로는 통과한다. 순수 함수에 비-CLI 가 섞인 목록을 직접 주입해 고정한다.
     */
    public function test_detected_candidates_put_cli_first(): void
    {
        $found = [
            ['path' => '/usr/sbin/php-fpm', 'version' => '8.3.26', 'sapi' => 'fpm-fcgi'],
            ['path' => '/usr/bin/php-cgi', 'version' => '8.3.26', 'sapi' => 'cgi-fcgi'],
            ['path' => '/usr/bin/php', 'version' => '8.3.26', 'sapi' => 'cli'],
        ];

        $ordered = $this->invoke('orderPhpBinaryCandidates', [$found]);

        $this->assertSame('/usr/bin/php', $ordered[0]['path'], '화면은 첫 후보를 입력란에 자동으로 채운다 — 웹 SAPI 바이너리가 첫 후보가 되면 전 태스크가 실패한다');
    }

    /**
     * #L7 — 요구사항 화면의 CLI 버전 비교도 같은 probe 를 쓰고 SAPI 를 남긴다.
     */
    public function test_cli_version_check_reports_sapi(): void
    {
        $result = $this->invoke('checkPhpCliVersion');

        $this->assertArrayHasKey('cli_sapi', $result, '버전만 맞고 SAPI 가 다른 경우를 응답만 보고 구분할 수 있어야 한다');
        $this->assertSame('php', $result['cli_path'], '경로 기본값은 실행 모드 헬퍼가 SSoT 다');

        // 키만 보면 값을 늘 null 로 채워도 초록이다 (11차 #D15) — 8차 #38 이 세 지점의
        // 반환 shape 을 동형화한 그 키가 정확히 그 형태로만 지켜지고 있었다.
        if ($result['cli_sapi'] !== null) {
            $this->assertIsString($result['cli_sapi']);
            $this->assertNotSame('', $result['cli_sapi'], 'SAPI 를 읽었으면 빈 문자열이 아니어야 한다');
        }
    }
}
