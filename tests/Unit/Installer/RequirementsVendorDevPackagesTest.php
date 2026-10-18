<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 설치 2단계(설치 환경 확인)의 Composer 의존성 구성 카드 회귀 테스트 (dev-g7 #658).
 *
 * 이 항목은 **선택 항목**이다 — 개발용 패키지가 섞여 있어도 설치는 진행되어야 한다(설계 결정 D1).
 * 게이트로 승격되면 이미 dev vendor 로 준비해 둔 운영자가 설치를 시작조차 못 한다.
 *
 * `check-configuration.php` 는 `CHECK_CONFIGURATION_LIBRARY` 모드로 로드해 요청 처리를 막고,
 * 판정 메서드만 리플렉션으로 호출한다 — `RequirementsResponseUtf8Test` 와 같은 방식.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class RequirementsVendorDevPackagesTest extends TestCase
{
    private static string $sharedBase = '';

    private static string $skipReason = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — '.
                    '격리 실행 필요: php vendor/bin/phpunit --filter=RequirementsVendorDevPackagesTest';

                return;
            }
            self::$sharedBase = (string) BASE_PATH;
        } else {
            self::$sharedBase = sys_get_temp_dir().'/g7-installer-vendor-dev-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$sharedBase);
        }

        foreach (['/storage/logs', '/storage/app', '/bootstrap/cache'] as $dir) {
            if (! is_dir(self::$sharedBase.$dir)) {
                mkdir(self::$sharedBase.$dir, 0755, true);
            }
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/index.php';
        }

        // 라이브러리 모드 — 파일 로드만으로 요청을 처리하지 않도록 한다.
        if (! defined('CHECK_CONFIGURATION_LIBRARY')) {
            define('CHECK_CONFIGURATION_LIBRARY', true);
        }

        require_once dirname(__DIR__, 3).'/public/install/includes/config.php';
        require_once dirname(__DIR__, 3).'/public/install/includes/functions.php';
        require_once dirname(__DIR__, 3).'/public/install/api/check-configuration.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }
    }

    /**
     * vendor 가 없는 신규 설치: 판정 불가로 두고, 마법사가 자동 설치한다는 안내만 낸다.
     *
     * @effects installer_env_check_shows_dev_vendor_card_without_gating
     */
    #[Test]
    public function vendor_가_없으면_자동_설치_안내로_보고한다(): void
    {
        $result = $this->invokeCheck();

        $this->assertFalse($result['required'], 'Composer 의존성 구성은 선택 항목이다');
        $this->assertFalse($result['vendor_exists']);
        $this->assertNull($result['dev'], 'vendor 가 없으면 판정 불가다');
        $this->assertSame([], $result['packages']);
        $this->assertNotSame('', trim($result['message']));
    }

    /**
     * dev vendor 를 감지하면 목록과 함께 보고하되 `required` 는 그대로 false 다.
     */
    #[Test]
    public function dev_vendor_를_감지해도_필수_항목으로_승격되지_않는다(): void
    {
        $this->writeVendor(['packages' => [], 'dev' => true, 'dev-package-names' => ['laravel/boost', 'laravel/mcp']]);

        $result = $this->invokeCheck();

        $this->assertFalse($result['required'], 'dev 감지는 설치를 차단하지 않는다 (D1)');
        $this->assertTrue($result['vendor_exists']);
        $this->assertTrue($result['dev']);
        $this->assertSame(['laravel/boost', 'laravel/mcp'], $result['packages']);
    }

    /**
     * `--no-dev` vendor 는 통과로 보고한다.
     */
    #[Test]
    public function 운영용_vendor_는_통과로_보고한다(): void
    {
        $this->writeVendor(['packages' => [], 'dev' => false, 'dev-package-names' => []]);

        $result = $this->invokeCheck();

        $this->assertFalse($result['required']);
        $this->assertTrue($result['vendor_exists']);
        $this->assertFalse($result['dev']);
        $this->assertSame([], $result['packages']);
    }

    /**
     * 게이트(`isAllRequiredPassed`)가 이 항목을 보지 않는다 — 소스 계약.
     *
     * 반환값 검사만으로는 부족하다: 게이트가 나중에 이 키를 참조하도록 바뀌어도
     * `required=false` 자체는 그대로라 행위 테스트가 통과한다.
     */
    #[Test]
    public function 필수_통과_판정이_이_항목을_참조하지_않는다(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/api/check-configuration.php');

        $start = strpos($source, 'private function isAllRequiredPassed(');
        $this->assertNotFalse($start, '전제: 게이트 메서드가 존재한다');

        $end = strpos($source, "\n    }", $start);
        $this->assertNotFalse($end, '전제: 게이트 메서드 본문을 잘라낼 수 있다');

        $body = substr($source, $start, $end - $start);

        $this->assertStringNotContainsString(
            'vendor_dev_packages',
            $body,
            '선택 항목이 필수 통과 판정에 들어가면 dev vendor 로 준비한 설치가 시작조차 되지 않는다'
        );
    }

    /**
     * 화면 카드가 존재하고 실패 목록에 넣지 않는다 — 소스 계약.
     */
    #[Test]
    public function 설치_화면_카드가_실패_목록에_넣지_않는다(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/assets/js/installer.js');

        $start = strpos($js, 'data.vendor_dev_packages && data.vendor_dev_packages.vendor_exists');
        $this->assertNotFalse($start, 'vendor_dev_packages 카드 렌더 블록이 있어야 한다');

        $end = strpos($js, '// 10. 자산 URL 방식 카드', $start);
        $this->assertNotFalse($end, '전제: 다음 카드 블록이 경계를 이룬다');

        $block = substr($js, $start, $end - $start);

        $this->assertStringContainsString("lang('vendor_dev_packages')", $block, '카드 제목을 언어 키로 그려야 한다');
        $this->assertStringNotContainsString('failedRequirements.push', $block, '선택 항목은 실패 목록에 넣지 않는다');
    }

    /**
     * `checkVendorDevPackages()` 를 리플렉션으로 호출합니다.
     *
     * @return array<string, mixed> 판정 결과
     */
    private function invokeCheck(): array
    {
        $api = new \ValidationApi;
        $method = new \ReflectionMethod(\ValidationApi::class, 'checkVendorDevPackages');
        $method->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $method->invoke($api);

        return $result;
    }

    /**
     * 임시 BASE_PATH 아래에 vendor 픽스처를 만듭니다.
     *
     * @param  array<string, mixed>  $installedJson  installed.json 내용
     */
    private function writeVendor(array $installedJson): void
    {
        $vendor = self::$sharedBase.'/vendor';
        if (! is_dir($vendor.'/composer')) {
            mkdir($vendor.'/composer', 0755, true);
        }

        file_put_contents($vendor.'/autoload.php', "<?php // test fixture\n");
        file_put_contents($vendor.'/composer/installed.json', json_encode($installedJson));
    }
}
