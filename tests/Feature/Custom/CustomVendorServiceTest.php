<?php

namespace Tests\Feature\Custom;

use App\Extension\Custom\CustomVendorService;
use App\Extension\Vendor\VendorIntegrityChecker;
use App\Extension\Vendor\VendorMode;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 개발자 전용 composer(custom/) vendor 설치 테스트 (#792 WP-K3 — F0-4·F0-5)
 *
 * composer 가 없는 서버(vendor 묶음 모드)에서 custom/vendor-bundle.zip 을 무결성 검사 후 풀고, 설치본이
 * lock 과 맞으면 건너뛰며, 묶음이 lock 과 어긋나면 설치하지 않는지 잠근다. custom/ 이 없으면 아무 일도 없다.
 */
class CustomVendorServiceTest extends TestCase
{
    private string $fakeBase;

    private string $originalBasePath;

    /**
     * 격리 base 를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBasePath = base_path();
        $this->fakeBase = storage_path('framework/testing/custom_vendor_'.uniqid());
        File::ensureDirectoryExists($this->fakeBase);
        app()->setBasePath($this->fakeBase);
    }

    /**
     * base 를 되돌리고 임시 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app()->setBasePath($this->originalBasePath);
        File::deleteDirectory($this->fakeBase);

        parent::tearDown();
    }

    /**
     * custom/ 이 없으면 아무 일도 하지 않습니다.
     *
     * @scenario case=no_custom
     *
     * @effects no_custom_is_noop
     *
     * @return void 반환값 없음
     */
    public function test_no_custom_composer_is_noop(): void
    {
        $this->assertSame('skipped:no-composer', app(CustomVendorService::class)->ensureInstalled(VendorMode::Bundled));
        $this->assertDirectoryDoesNotExist($this->fakeBase.'/custom');
    }

    /**
     * 묶음 모드에서 vendor 가 없으면 묶음을 풀고 표식을 남기며, 다시 부르면 맞춰져 있어 건너뜁니다.
     *
     * @scenario case=bundle_install
     *
     * @effects bundle_extracted_when_vendor_missing, in_sync_vendor_skipped
     *
     * @return void 반환값 없음
     */
    public function test_bundle_is_extracted_then_skipped_when_in_sync(): void
    {
        $this->writeCustomWithBundle();
        $service = app(CustomVendorService::class);

        $this->assertSame('bundled', $service->ensureInstalled(VendorMode::Bundled));
        $this->assertFileExists($this->fakeBase.'/custom/vendor/autoload.php');
        $this->assertFileExists($this->fakeBase.'/custom/vendor/'.CustomVendorService::INSTALLED_MARKER);
        $this->assertTrue($service->isInstalledInSync());

        $this->assertSame('skipped:in-sync', $service->ensureInstalled(VendorMode::Bundled));
    }

    /**
     * lock 이 바뀌어 묶음과 어긋나면(무결성 불일치) 풀지 않고 실패로 알립니다 — 설치본도 맞지 않는 것으로 판정합니다.
     *
     * @scenario case=integrity_mismatch
     *
     * @effects integrity_mismatch_not_installed
     *
     * @return void 반환값 없음
     */
    public function test_integrity_mismatch_is_not_installed(): void
    {
        $this->writeCustomWithBundle();
        $service = app(CustomVendorService::class);
        $this->assertSame('bundled', $service->ensureInstalled(VendorMode::Bundled));

        File::put($this->fakeBase.'/custom/composer.lock', '{"packages":[{"name":"acme/changed","version":"9.9.9"}]}');

        $this->assertFalse($service->isInstalledInSync(), 'lock 이 바뀌면 설치본은 맞지 않는다');
        $result = $service->ensureInstalled(VendorMode::Bundled);
        $this->assertStringStartsWith('failed:', $result);
        $this->assertFalse($service->verifyBundle()->valid);
    }

    /**
     * 설치본이 lock 과 어긋나면(개발자가 패키지를 올리고 새 묶음을 만든 경우) 새 묶음으로 다시 풉니다 — 계획 WP-K3
     * 「무결성 불일치 재설치」의 정방향.
     *
     * @scenario case=integrity_mismatch
     *
     * @effects out_of_sync_vendor_reinstalled_from_new_bundle
     *
     * @return void 반환값 없음
     */
    public function test_out_of_sync_vendor_is_reinstalled_from_new_bundle(): void
    {
        $this->writeCustomWithBundle('1.0.0');
        $service = app(CustomVendorService::class);
        $this->assertSame('bundled', $service->ensureInstalled(VendorMode::Bundled));
        $this->assertStringContainsString('1.0.0', (string) file_get_contents($this->fakeBase.'/custom/vendor/acme/thing/src/Thing.php'));

        $this->writeCustomWithBundle('1.1.0');
        $this->assertFalse($service->isInstalledInSync());

        $this->assertSame('bundled', $service->ensureInstalled(VendorMode::Bundled));
        $this->assertStringContainsString('1.1.0', (string) file_get_contents($this->fakeBase.'/custom/vendor/acme/thing/src/Thing.php'));
        $this->assertTrue($service->isInstalledInSync());
        $this->assertSame('skipped:in-sync', $service->ensureInstalled(VendorMode::Bundled));
    }

    /**
     * custom/composer.json·lock 과 그 해시가 맞는 가짜 vendor 묶음을 만듭니다.
     *
     * @param  string  $version  묶음에 담을 패키지 버전
     * @return void 반환값 없음
     */
    private function writeCustomWithBundle(string $version = '1.0.0'): void
    {
        $custom = $this->fakeBase.'/custom';
        File::ensureDirectoryExists($custom);
        File::put($custom.'/composer.json', '{"require":{"acme/thing":"^1.0"}}');
        File::put($custom.'/composer.lock', '{"packages":[{"name":"acme/thing","version":"'.$version.'"}]}');

        $zip = new \ZipArchive;
        $zip->open($custom.'/'.VendorIntegrityChecker::ZIP_FILENAME, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('vendor/autoload.php', "<?php // custom vendor autoload\n");
        $zip->addFromString('vendor/acme/thing/src/Thing.php', "<?php namespace Acme; class Thing {} // {$version}\n");
        $zip->close();

        $checker = app(VendorIntegrityChecker::class);
        File::put($custom.'/'.VendorIntegrityChecker::MANIFEST_FILENAME, (string) json_encode([
            'schema_version' => '1.0',
            'target' => 'custom',
            'composer_json_sha256' => $checker->computeFileHash($custom.'/composer.json'),
            'composer_lock_sha256' => $checker->computeFileHash($custom.'/composer.lock'),
            'zip_sha256' => $checker->computeFileHash($custom.'/'.VendorIntegrityChecker::ZIP_FILENAME),
            'zip_size' => filesize($custom.'/'.VendorIntegrityChecker::ZIP_FILENAME),
            'package_count' => 1,
            'packages' => [['name' => 'acme/thing', 'version' => $version]],
        ]));
    }
}
