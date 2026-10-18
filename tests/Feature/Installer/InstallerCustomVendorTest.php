<?php

namespace Tests\Feature\Installer;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 설치기의 개발자 전용 composer(custom/) vendor 처리 테스트 (#792 F0-5)
 *
 * vendor 묶음 모드 설치에서 custom/vendor-bundle.zip 이 있고 custom/vendor 가 없으면 풀어 주고, 맞춰져 있으면
 * 건너뛰며, custom/ 이 없으면 아무 일도 하지 않는지 잠근다. 설치 표식 형식은 코어 CustomVendorService 와 같다.
 */
class InstallerCustomVendorTest extends TestCase
{
    private string $base;

    /**
     * 설치기 함수 파일과 임시 설치 루트를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once base_path('public/install/includes/vendor-bundle-installer.php');
        $this->base = storage_path('framework/testing/installer_custom_vendor_'.uniqid());
        File::ensureDirectoryExists($this->base);
    }

    /**
     * 임시 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);

        parent::tearDown();
    }

    /**
     * custom/ 이 없으면 건너뜁니다.
     *
     * @scenario case=installer_no_custom
     *
     * @effects installer_skips_without_custom
     *
     * @return void 반환값 없음
     */
    public function test_skips_without_custom(): void
    {
        $this->assertSame(['success' => true, 'skipped' => true], installCustomVendorIfNeeded($this->base, true));
    }

    /**
     * 묶음 모드에서 custom/vendor 가 없으면 풀고 표식을 남기며, 다시 부르면 맞춰져 있어 건너뜁니다.
     *
     * @scenario case=installer_bundle
     *
     * @effects installer_extracts_custom_bundle, installer_skips_in_sync
     *
     * @return void 반환값 없음
     */
    public function test_extracts_bundle_then_skips_in_sync(): void
    {
        $custom = $this->base.'/custom';
        File::ensureDirectoryExists($custom);
        File::put($custom.'/composer.json', '{"require":{"acme/thing":"^1.0"}}');
        File::put($custom.'/composer.lock', '{"packages":[{"name":"acme/thing","version":"1.0.0"}]}');
        $zip = new \ZipArchive;
        $zip->open($custom.'/vendor-bundle.zip', \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('vendor/autoload.php', "<?php\n");
        $zip->close();
        File::put($custom.'/vendor-bundle.json', (string) json_encode([
            'schema_version' => '1.0',
            'composer_json_sha256' => hash_file('sha256', $custom.'/composer.json'),
            'composer_lock_sha256' => hash_file('sha256', $custom.'/composer.lock'),
            'zip_sha256' => hash_file('sha256', $custom.'/vendor-bundle.zip'),
            'package_count' => 1,
        ]));

        $first = installCustomVendorIfNeeded($this->base, true);
        $this->assertTrue($first['success']);
        $this->assertSame('bundled', $first['mode'] ?? null);
        $this->assertFileExists($custom.'/vendor/autoload.php');
        $this->assertSame(hash_file('sha256', $custom.'/composer.lock'), trim((string) file_get_contents($custom.'/vendor/.g7-custom-lock.sha256')));

        $second = installCustomVendorIfNeeded($this->base, true);
        $this->assertSame('in-sync', $second['mode'] ?? null);
    }
}
