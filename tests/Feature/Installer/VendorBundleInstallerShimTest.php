<?php

namespace Tests\Feature\Installer;

use App\Extension\Vendor\VendorBundleInstaller;
use App\Extension\Vendor\VendorBundler;
use App\Extension\Vendor\VendorIntegrityChecker;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 웹 인스톨러 PHP shim 과 Laravel 클래스의 동등성 검증.
 *
 * public/install/includes/vendor-bundle-installer.php 의 함수들이
 * App\Extension\Vendor\VendorBundleInstaller / VendorIntegrityChecker 와
 * 동일한 입력에 대해 동일한 결과를 반환하는지 검증한다.
 */
class VendorBundleInstallerShimTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = storage_path('app/test-shim-'.uniqid());
        File::ensureDirectoryExists($this->testDir);

        require_once base_path('public/install/includes/vendor-bundle-installer.php');
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }
        parent::tearDown();
    }

    private function createValidBundle(): void
    {
        $zipPath = $this->testDir.'/vendor-bundle.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('vendor/autoload.php', '<?php // shim test');
        $zip->addFromString('vendor/test/lib/file.php', '<?php // test lib');
        $zip->close();

        File::put($this->testDir.'/vendor-bundle.json', json_encode([
            'schema_version' => VendorBundler::SCHEMA_VERSION,
            'zip_sha256' => hash_file('sha256', $zipPath),
            'package_count' => 1,
            'packages' => [['name' => 'test/lib', 'version' => '1.0.0', 'type' => 'library']],
        ]));
    }

    public function test_shim_verify_matches_laravel_class_on_valid_bundle(): void
    {
        $this->createValidBundle();

        $checker = new VendorIntegrityChecker;
        $laravelResult = $checker->verify($this->testDir);

        $shimResult = verifyVendorBundle($this->testDir);

        $this->assertSame($laravelResult->valid, $shimResult['valid']);
        $this->assertSame($laravelResult->errors, $shimResult['errors']);
    }

    public function test_shim_verify_matches_on_missing_zip(): void
    {
        $checker = new VendorIntegrityChecker;
        $laravelResult = $checker->verify($this->testDir);

        $shimResult = verifyVendorBundle($this->testDir);

        $this->assertFalse($laravelResult->valid);
        $this->assertFalse($shimResult['valid']);
        $this->assertContains('bundle_zip_missing', $shimResult['errors']);
    }

    public function test_shim_verify_matches_on_hash_mismatch(): void
    {
        $this->createValidBundle();

        // manifest 조작
        $manifest = json_decode(file_get_contents($this->testDir.'/vendor-bundle.json'), true);
        $manifest['zip_sha256'] = hash('sha256', 'tampered');
        File::put($this->testDir.'/vendor-bundle.json', json_encode($manifest));

        $checker = new VendorIntegrityChecker;
        $laravelResult = $checker->verify($this->testDir);

        $shimResult = verifyVendorBundle($this->testDir);

        $this->assertFalse($laravelResult->valid);
        $this->assertFalse($shimResult['valid']);
        $this->assertContains('zip_hash_mismatch', $shimResult['errors']);
    }

    public function test_shim_extract_produces_same_files_as_laravel_class(): void
    {
        // shim 경로
        $shimDir = $this->testDir.'/shim';
        File::ensureDirectoryExists($shimDir);
        $this->createBundleAt($shimDir);

        // Laravel 경로
        $laravelDir = $this->testDir.'/laravel';
        File::ensureDirectoryExists($laravelDir);
        $this->createBundleAt($laravelDir);

        // 양쪽 추출
        $shimResult = extractVendorBundle($shimDir, $shimDir);
        $this->assertTrue($shimResult['success'], 'shim 추출 실패: '.($shimResult['error'] ?? ''));

        $installer = new VendorBundleInstaller(new VendorIntegrityChecker);
        $installer->install($laravelDir, $laravelDir);

        // 추출된 파일이 동일한지 비교
        $this->assertFileExists($shimDir.'/vendor/autoload.php');
        $this->assertFileExists($laravelDir.'/vendor/autoload.php');
        $this->assertSame(
            file_get_contents($shimDir.'/vendor/autoload.php'),
            file_get_contents($laravelDir.'/vendor/autoload.php'),
        );
    }

    public function test_shim_rejects_unsafe_paths(): void
    {
        $zipPath = $this->testDir.'/vendor-bundle.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('../evil.php', '<?php // evil');
        $zip->close();

        File::put($this->testDir.'/vendor-bundle.json', json_encode([
            'schema_version' => VendorBundler::SCHEMA_VERSION,
            'zip_sha256' => hash_file('sha256', $zipPath),
            'package_count' => 0,
        ]));

        $result = extractVendorBundle($this->testDir, $this->testDir);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('unsafe_path', (string) ($result['error'] ?? ''));
    }

    private function createBundleAt(string $dir): void
    {
        $zipPath = $dir.'/vendor-bundle.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('vendor/autoload.php', '<?php // identical content');
        $zip->close();

        File::put($dir.'/vendor-bundle.json', json_encode([
            'schema_version' => VendorBundler::SCHEMA_VERSION,
            'zip_sha256' => hash_file('sha256', $zipPath),
            'package_count' => 0,
        ]));
    }
}
