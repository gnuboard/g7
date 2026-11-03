<?php

namespace Tests\Unit\Installer;

use App\Support\ExecutionEnvironment;
use App\Support\PhpBinaryResolver;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Concerns\ScansPhpSourceFunctions;
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
 * 감지·CLI 버전 판정 본문은 웹(check-configuration.php)과 터미널 설치가 함께 쓰는
 * `public/install/includes/requirements.php` 의 공유 함수(`installer_detect_php_binaries`,
 * `installer_requirement_php_cli_version`)로 옮겨졌다. 그래서 그 두 계약은 공유 함수를
 * 직접 부르거나 그 본문을 읽어 고정한다.
 *
 * 커버 축: php_binary_sapi cli·non_cli·unknown / php_binary_source detected_autodetect
 *
 * 효과 요약(마커 아님 — 평문): 검증 결과의 SAPI 보고, CLI 후보 우선 정렬, CLI 버전 검사의 SAPI 보고. 표식은 각 테스트 메서드에 있다.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerPhpBinaryDetectionTest extends TestCase
{
    use ScansPhpSourceFunctions;

    private static bool $loaded = false;

    /**
     * 클래스 시작 때 한 번 번역 대역과 설치기 상수를 갖추고 요구사항 검사 API 와 공용 함수 파일을 불러온다.
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
     * 요구사항 검사 API 의 비공개 메서드를 리플렉션으로 불러 결과를 돌려준다.
     *
     * @param  string  $method  부를 메서드 이름
     * @param  array<int, mixed>  $args  메서드에 넘길 인자 목록
     * @return mixed 메서드가 돌려준 값
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
     *
     * @scenario php_binary_source=configured
     *
     * @effects installer_php_validation_reports_sapi
     *
     * @return void 반환값 없음
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
     *
     * @scenario php_binary_source=detected_autodetect
     *
     * @effects installer_php_candidates_are_cli_first
     *
     * @return void 반환값 없음
     */
    public function test_detected_candidates_put_cli_first(): void
    {
        $found = [
            ['path' => '/usr/sbin/php-fpm', 'version' => '8.3.26', 'sapi' => 'fpm-fcgi'],
            ['path' => '/usr/bin/php-cgi', 'version' => '8.3.26', 'sapi' => 'cgi-fcgi'],
            ['path' => '/usr/bin/php', 'version' => '8.3.26', 'sapi' => 'cli'],
        ];

        // 감지 함수가 정렬에 쓰는 규칙 그 자체에 비-CLI 가 섞인 목록을 주입한다.
        $ordered = PhpBinaryResolver::sortCliFirst($found);

        $this->assertSame('/usr/bin/php', $ordered[0]['path'], '화면은 첫 후보를 입력란에 자동으로 채운다 — 웹 SAPI 바이너리가 첫 후보가 되면 전 태스크가 실패한다');

        // 그 규칙이 감지 결과에 실제로 걸리는가 — 정렬 줄이 사라지면 함수는 멀쩡한 채로
        // 감지 결과만 탐색 순서(PHP_BINARY 먼저)로 돌아간다. 정렬은 Composer 짝 PHP 를
        // 고르기(첫 후보) **전**이어야 한다.
        $source = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/includes/requirements.php');
        $body = $this->functionBody($source, 'installer_detect_php_binaries');
        $this->assertNotSame('', $body, 'installer_detect_php_binaries() 를 찾지 못했다 — 이 검사의 기준점이 사라졌다');

        $sortAt = strpos($body, 'PhpBinaryResolver::sortCliFirst($found)');
        $firstPickAt = strpos($body, "\$found[0]['path']");
        $this->assertNotFalse($sortAt, '감지 결과가 CLI 우선으로 정렬되지 않는다 — 웹 SAPI 바이너리가 입력란에 자동으로 채워진다');
        $this->assertNotFalse($firstPickAt, 'Composer 짝 PHP 선택(첫 후보)을 찾지 못했다 — 이 검사의 기준점이 사라졌다');
        $this->assertLessThan($firstPickAt, $sortAt, '정렬이 첫 후보 선택보다 뒤에 있다 — Composer 가 웹 SAPI 바이너리와 짝지어진다');
    }

    /**
     * #L7 — 요구사항 화면의 CLI 버전 비교도 같은 probe 를 쓰고 SAPI 를 남긴다.
     *
     * @scenario php_binary_source=detected_autodetect
     *
     * @effects installer_cli_version_check_reports_sapi
     *
     * @return void 반환값 없음
     */
    public function test_cli_version_check_reports_sapi(): void
    {
        if (! ExecutionEnvironment::hasFunction('exec')) {
            $this->markTestSkipped('exec 차단 환경 — CLI 버전 조회는 건너뜀 응답만 낸다');
        }

        // 요구사항 화면(check-configuration.php)과 터미널 설치가 함께 부르는 공유 판정이다.
        $result = installer_requirement_php_cli_version();

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
