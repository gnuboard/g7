<?php

namespace Tests\Unit\Extension\Vendor;

use App\Extension\Vendor\Exceptions\VendorInstallException;
use App\Extension\Vendor\VendorBundleInstaller;
use App\Extension\Vendor\VendorBundler;
use App\Extension\Vendor\VendorIntegrityChecker;
use App\Extension\Vendor\VendorMode;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class VendorBundleInstallerTest extends TestCase
{
    private VendorBundleInstaller $installer;

    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installer = new VendorBundleInstaller(new VendorIntegrityChecker);
        $this->testDir = storage_path('app/test-vendor-installer-'.uniqid());
        File::ensureDirectoryExists($this->testDir);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }
        parent::tearDown();
    }

    /**
     * 테스트용 vendor-bundle.zip + manifest 생성.
     */
    private function createValidBundle(array $files = ['vendor/test/pkg/file.php' => '<?php // test']): void
    {
        $zipPath = $this->testDir.'/vendor-bundle.zip';
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        $manifest = [
            'schema_version' => VendorBundler::SCHEMA_VERSION,
            'generated_at' => date('c'),
            'generator' => 'test',
            'target' => 'test',
            'zip_sha256' => hash_file('sha256', $zipPath),
            'zip_size' => filesize($zipPath),
            'package_count' => 1,
            'packages' => [['name' => 'test/pkg', 'version' => '1.0.0', 'type' => 'library']],
        ];
        File::put($this->testDir.'/vendor-bundle.json', json_encode($manifest));
    }

    public function test_install_throws_when_zip_missing(): void
    {
        $this->expectException(VendorInstallException::class);
        $this->installer->install($this->testDir, $this->testDir);
    }

    public function test_install_extracts_zip_successfully(): void
    {
        $this->createValidBundle();

        $result = $this->installer->install($this->testDir, $this->testDir);

        $this->assertSame(VendorMode::Bundled, $result->mode);
        $this->assertSame('bundled', $result->strategy);
        $this->assertSame(1, $result->packageCount);
        $this->assertFileExists($this->testDir.'/vendor/test/pkg/file.php');
    }

    public function test_install_throws_on_hash_mismatch(): void
    {
        $this->createValidBundle();

        // manifest 조작 — 잘못된 해시
        $manifest = json_decode(file_get_contents($this->testDir.'/vendor-bundle.json'), true);
        $manifest['zip_sha256'] = hash('sha256', 'tampered');
        File::put($this->testDir.'/vendor-bundle.json', json_encode($manifest));

        $this->expectException(VendorInstallException::class);
        $this->installer->install($this->testDir, $this->testDir);
    }

    public function test_install_rejects_unsafe_paths(): void
    {
        // zip slip 시도
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

        $this->expectException(VendorInstallException::class);
        $this->installer->install($this->testDir, $this->testDir);
    }

    public function test_install_backs_up_existing_vendor_and_deletes_on_success(): void
    {
        // 기존 vendor/ 배치
        File::ensureDirectoryExists($this->testDir.'/vendor/old');
        File::put($this->testDir.'/vendor/old/marker.txt', 'old vendor');

        $this->createValidBundle();

        $this->installer->install($this->testDir, $this->testDir);

        // 새 vendor/test/pkg/file.php 존재
        $this->assertFileExists($this->testDir.'/vendor/test/pkg/file.php');

        // 기존 vendor/old/ 는 zip 추출에 의해 덮어써지지 않으므로 제거되지 않음
        // (백업에서 복원되지는 않으나, 이는 zip 이 해당 경로를 포함하지 않기 때문)
        // 중요: 백업 디렉토리 (vendor/.bundle_backup_*) 는 성공 시 삭제되어야 함
        $backups = glob($this->testDir.'/vendor/.bundle_backup_*');
        $this->assertEmpty($backups, '성공 시 백업 디렉토리는 삭제되어야 함');

        // targetDir (최상위) 에는 vendor.old.* 가 절대 생성되지 않아야 함 (공유 호스팅 호환)
        $legacyBackups = glob($this->testDir.'/vendor.old.*');
        $this->assertEmpty($legacyBackups, 'targetDir 에 legacy vendor.old.* 디렉토리가 생성되면 안 됨');
    }

    public function test_install_succeeds_when_only_vendor_dir_is_writable(): void
    {
        // 공유 호스팅 시나리오 재현: targetDir 은 있지만 vendor/ 만 쓰기 가능한 환경.
        // 실제 권한 변경은 Windows 에서 검증 어려우므로, 대신 새 구조의 동작 원리를 검증:
        // vendor/ 가 이미 존재하고 zip 추출이 vendor/ 내부 항목만 조작하는지 확인.
        File::ensureDirectoryExists($this->testDir.'/vendor');
        File::put($this->testDir.'/vendor/legacy.txt', 'preexisting');

        $this->createValidBundle();

        $result = $this->installer->install($this->testDir, $this->testDir);

        $this->assertSame('bundled', $result->strategy);
        $this->assertFileExists($this->testDir.'/vendor/test/pkg/file.php');

        // 백업 디렉토리는 성공 후 정리되어야 함
        $backups = glob($this->testDir.'/vendor/.bundle_backup_*');
        $this->assertEmpty($backups);

        // targetDir 루트에는 어떠한 백업/임시 디렉토리도 만들어지지 않아야 함
        // (vendor/ 내부 백업 방식이므로 루트는 오염되지 않음)
        $topLevelEntries = array_filter(scandir($this->testDir), fn ($e) => ! in_array($e, ['.', '..', 'vendor', 'vendor-bundle.zip', 'vendor-bundle.json'], true));
        $this->assertEmpty($topLevelEntries, 'targetDir 루트가 오염되지 않아야 함');
    }
}
