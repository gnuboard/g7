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

    /**
     * 임시 번들 디렉터리를 만들고 설치 마법사의 실행 모드 파일과 vendor 번들 shim 파일을 싣는다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = storage_path('app/test-shim-'.uniqid());
        File::ensureDirectoryExists($this->testDir);

        // 이 파일은 자기 의존을 require 하지 않는다(호출부가 미리 로드) — 함수 가용 판정 `installerHasFunction()` 은
        // 실행 모드 파일이 정의한다. 설치 마법사 진입점처럼 먼저 싣지 않으면 단독 실행에서 미정의 함수로 죽는다.
        require_once base_path('public/install/includes/execution-mode.php');
        require_once base_path('public/install/includes/vendor-bundle-installer.php');
    }

    /**
     * 임시 번들 디렉터리를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (File::isDirectory($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }
        parent::tearDown();
    }

    /**
     * 임시 디렉터리에 vendor 파일 두 개를 담은 zip 과 그 해시를 적은 정상 vendor-bundle.json 을 만든다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * 정상 번들에서 shim 의 verifyVendorBundle 이 VendorIntegrityChecker 와 같은 valid, errors 를 내는지 검증한다.
     *
     * @scenario bundle_case=valid_verify
     *
     * @effects shim_verify_matches_laravel_checker_on_valid_bundle
     *
     * @return void 반환값 없음
     */
    public function test_shim_verify_matches_laravel_class_on_valid_bundle(): void
    {
        $this->createValidBundle();

        $checker = new VendorIntegrityChecker;
        $laravelResult = $checker->verify($this->testDir);

        $shimResult = verifyVendorBundle($this->testDir);

        $this->assertSame($laravelResult->valid, $shimResult['valid']);
        $this->assertSame($laravelResult->errors, $shimResult['errors']);
    }

    /**
     * zip 이 없을 때 shim 과 Laravel 검사기가 모두 실패하고 shim 이 bundle_zip_missing 을 내는지 검증한다.
     *
     * @scenario bundle_case=zip_missing_verify
     *
     * @effects shim_verify_reports_bundle_zip_missing_like_laravel_checker
     *
     * @return void 반환값 없음
     */
    public function test_shim_verify_matches_on_missing_zip(): void
    {
        $checker = new VendorIntegrityChecker;
        $laravelResult = $checker->verify($this->testDir);

        $shimResult = verifyVendorBundle($this->testDir);

        $this->assertFalse($laravelResult->valid);
        $this->assertFalse($shimResult['valid']);
        $this->assertContains('bundle_zip_missing', $shimResult['errors']);
    }

    /**
     * 매니페스트의 zip 해시를 바꿨을 때 shim 과 Laravel 검사기가 모두 실패하고 shim 이 zip_hash_mismatch 를 내는지 검증한다.
     *
     * @scenario bundle_case=hash_mismatch_verify
     *
     * @effects shim_verify_reports_zip_hash_mismatch_like_laravel_checker
     *
     * @return void 반환값 없음
     */
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

    /**
     * 같은 번들을 shim 의 extractVendorBundle 과 VendorBundleInstaller 로 각각 풀었을 때 vendor/autoload.php 내용이 같은지 검증한다.
     *
     * @scenario bundle_case=valid_extract
     *
     * @effects shim_extract_produces_same_files_as_laravel_installer
     *
     * @return void 반환값 없음
     */
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

    /**
     * 상위 경로 항목이 든 zip 을 shim 으로 풀면 success=false 와 unsafe_path 오류로 거절되는지 검증한다.
     *
     * @scenario bundle_case=unsafe_entry_extract
     *
     * @effects shim_extract_rejects_unsafe_entry_path
     *
     * @return void 반환값 없음
     */
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

    /**
     * 지정 디렉터리에 vendor/autoload.php 하나를 담은 zip 과 그 해시를 적은 vendor-bundle.json 을 만든다.
     *
     * @param  string  $dir  번들을 만들 디렉터리 경로
     * @return void 반환값 없음
     */
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
