<?php

namespace Tests\Unit\Support;

use App\Support\ComposerInstallInfo;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * vendor 개발용 설치 판정 SSoT 테스트 (dev-g7 #658).
 *
 * 인스톨러(순수 PHP)와 코어 업데이트가 같은 vendor 를 두고 서로 다른 답을 내놓지 않도록
 * 판정을 이 클래스 하나가 소유한다. "판정 불가"(null)와 "개발용 아님"(false)을 구분하는 것이
 * 핵심이다 — 뭉뚱그리면 installed.json 이 없는 환경이 "운영용 구성 확인됨" 으로 보인다.
 */
class ComposerInstallInfoTest extends TestCase
{
    private string $vendorPath;

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = storage_path('framework/testing/composer-install-info-'.uniqid());
        $this->vendorPath = $this->basePath.'/vendor';

        File::ensureDirectoryExists($this->vendorPath.'/composer');
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->basePath)) {
            File::deleteDirectory($this->basePath);
        }

        parent::tearDown();
    }

    /**
     * `dev: true` + 이름 목록 → 개발용 설치.
     *
     * @effects core_detects_dev_vendor_from_installed_json
     */
    #[Test]
    public function dev_true_와_이름_목록이_있으면_개발용_설치로_판정한다(): void
    {
        $this->writeInstalledJson([
            'packages' => [],
            'dev' => true,
            'dev-package-names' => ['laravel/boost', 'laravel/mcp', 'laravel/pail'],
        ]);

        $info = ComposerInstallInfo::inspect($this->vendorPath);

        $this->assertTrue($info['dev']);
        $this->assertSame(['laravel/boost', 'laravel/mcp', 'laravel/pail'], $info['packages']);
        $this->assertTrue(ComposerInstallInfo::isDevInstall($this->vendorPath));
        $this->assertCount(3, ComposerInstallInfo::devPackageNames($this->vendorPath));
    }

    /**
     * `--no-dev` 설치 → 개발용 아님.
     *
     * @effects core_reports_no_dev_packages_for_no_dev_vendor
     */
    #[Test]
    public function dev_false_이고_이름_목록이_비면_운영용_설치로_판정한다(): void
    {
        $this->writeInstalledJson([
            'packages' => [],
            'dev' => false,
            'dev-package-names' => [],
        ]);

        $info = ComposerInstallInfo::inspect($this->vendorPath);

        $this->assertFalse($info['dev']);
        $this->assertSame([], $info['packages']);
        $this->assertFalse(ComposerInstallInfo::isDevInstall($this->vendorPath));
        $this->assertSame([], ComposerInstallInfo::devPackageNames($this->vendorPath));
    }

    /**
     * `dev` 는 false 인데 이름 목록이 남아 있으면 개발용으로 본다 — 두 신호는 OR 다.
     * Composer 버전에 따라 한쪽만 채워질 수 있고, 등재된 provider 가 있다는 사실이 중요하다.
     */
    #[Test]
    public function dev_false_라도_이름_목록이_비어있지_않으면_개발용으로_판정한다(): void
    {
        $this->writeInstalledJson([
            'packages' => [],
            'dev' => false,
            'dev-package-names' => ['laravel/mcp'],
        ]);

        $this->assertTrue(ComposerInstallInfo::isDevInstall($this->vendorPath));
    }

    /**
     * installed.json 이 없으면 판정 불가(null) — "개발용 아님"(false)과 구분한다.
     *
     * @effects core_returns_unknown_when_installed_json_missing
     */
    #[Test]
    public function installed_json_이_없으면_판정_불가다(): void
    {
        $info = ComposerInstallInfo::inspect($this->vendorPath);

        $this->assertNull($info['dev']);
        $this->assertSame([], $info['packages']);
        $this->assertNull(ComposerInstallInfo::isDevInstall($this->vendorPath));
    }

    /**
     * JSON 파싱 실패도 판정 불가다.
     */
    #[Test]
    public function json_이_깨졌으면_판정_불가다(): void
    {
        File::put($this->vendorPath.'/'.ComposerInstallInfo::INSTALLED_JSON, '{ not json');

        $this->assertNull(ComposerInstallInfo::isDevInstall($this->vendorPath));
    }

    /**
     * Composer 1 형식(최상위가 패키지 배열)은 dev 정보가 없어 판정 불가다.
     */
    #[Test]
    public function composer1_형식은_판정_불가다(): void
    {
        File::put($this->vendorPath.'/'.ComposerInstallInfo::INSTALLED_JSON, json_encode([
            ['name' => 'vendor/pkg', 'version' => '1.0.0'],
        ]));

        $this->assertNull(ComposerInstallInfo::isDevInstall($this->vendorPath));
    }

    /**
     * 이름 목록의 비문자열·공백 항목은 걸러낸다 (건수를 그대로 화면에 표시하므로).
     */
    #[Test]
    public function 이름_목록의_비문자열과_공백_항목을_걸러낸다(): void
    {
        $this->writeInstalledJson([
            'packages' => [],
            'dev' => true,
            'dev-package-names' => ['laravel/mcp', '', '   ', 123, null, ['nested'], 'laravel/pail'],
        ]);

        $this->assertSame(['laravel/mcp', 'laravel/pail'], ComposerInstallInfo::devPackageNames($this->vendorPath));
    }

    /**
     * 경로 끝의 구분자 유무와 무관하게 같은 결과를 낸다.
     */
    #[Test]
    public function 경로_끝_구분자_유무와_무관하게_동작한다(): void
    {
        $this->writeInstalledJson([
            'packages' => [],
            'dev' => true,
            'dev-package-names' => ['laravel/mcp'],
        ]);

        $this->assertTrue(ComposerInstallInfo::isDevInstall($this->vendorPath.'/'));
        $this->assertTrue(ComposerInstallInfo::isDevInstall($this->vendorPath));
    }

    /**
     * installed.json 픽스처를 씁니다.
     *
     * @param  array<string, mixed>  $payload  JSON 으로 직렬화할 내용
     */
    private function writeInstalledJson(array $payload): void
    {
        File::put($this->vendorPath.'/'.ComposerInstallInfo::INSTALLED_JSON, json_encode($payload));
    }
}
