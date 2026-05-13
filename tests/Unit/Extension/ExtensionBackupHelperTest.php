<?php

namespace Tests\Unit\Extension;

use App\Extension\Helpers\ExtensionBackupHelper;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ExtensionBackupHelperTest extends TestCase
{
    private string $testModulePath;

    private string $backupsBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 모듈 디렉토리 생성
        $this->testModulePath = base_path('modules/test-backup-mod');
        $this->backupsBasePath = storage_path('app/extension_backups');

        File::ensureDirectoryExists($this->testModulePath.'/src');
        File::put($this->testModulePath.'/module.json', '{"identifier": "test-backup-mod", "version": "1.0.0"}');
        File::put($this->testModulePath.'/src/Service.php', '<?php class Service {}');
    }

    protected function tearDown(): void
    {
        // 테스트 디렉토리 정리
        if (File::isDirectory($this->testModulePath)) {
            File::deleteDirectory($this->testModulePath);
        }

        if (File::isDirectory($this->backupsBasePath)) {
            File::deleteDirectory($this->backupsBasePath);
        }

        parent::tearDown();
    }

    /**
     * 백업이 storage에 생성되는지 확인합니다.
     */
    public function test_create_backup_copies_directory_to_storage(): void
    {
        $backupPath = ExtensionBackupHelper::createBackup('modules', 'test-backup-mod');

        $this->assertDirectoryExists($backupPath);
        $this->assertStringContainsString('extension_backups/modules/test-backup-mod_', $backupPath);
    }

    /**
     * 백업 파일 내용이 원본과 일치하는지 확인합니다.
     */
    public function test_create_backup_preserves_file_contents(): void
    {
        $backupPath = ExtensionBackupHelper::createBackup('modules', 'test-backup-mod');

        $this->assertFileExists($backupPath.'/module.json');
        $this->assertEquals(
            '{"identifier": "test-backup-mod", "version": "1.0.0"}',
            File::get($backupPath.'/module.json')
        );
    }

    /**
     * 중첩 디렉토리 구조가 보존되는지 확인합니다.
     */
    public function test_create_backup_preserves_nested_structure(): void
    {
        $backupPath = ExtensionBackupHelper::createBackup('modules', 'test-backup-mod');

        $this->assertFileExists($backupPath.'/src/Service.php');
        $this->assertEquals(
            '<?php class Service {}',
            File::get($backupPath.'/src/Service.php')
        );
    }

    /**
     * 백업에서 복원 시 원본이 백업 시점으로 복원되는지 확인합니다.
     */
    public function test_restore_from_backup_replaces_directory(): void
    {
        $backupPath = ExtensionBackupHelper::createBackup('modules', 'test-backup-mod');

        // 원본 수정
        File::put($this->testModulePath.'/module.json', '{"version": "2.0.0"}');

        // 복원
        ExtensionBackupHelper::restoreFromBackup('modules', 'test-backup-mod', $backupPath);

        $this->assertEquals(
            '{"identifier": "test-backup-mod", "version": "1.0.0"}',
            File::get($this->testModulePath.'/module.json')
        );
    }

    /**
     * 복원 후 백업에 없던 파일이 삭제되는지 확인합니다.
     */
    public function test_restore_from_backup_removes_extra_files(): void
    {
        $backupPath = ExtensionBackupHelper::createBackup('modules', 'test-backup-mod');

        // 원본에 새 파일 추가
        File::put($this->testModulePath.'/extra.txt', 'extra content');

        // 복원
        ExtensionBackupHelper::restoreFromBackup('modules', 'test-backup-mod', $backupPath);

        $this->assertFileDoesNotExist($this->testModulePath.'/extra.txt');
    }

    /**
     * 백업 삭제 후 디렉토리가 제거되는지 확인합니다.
     */
    public function test_delete_backup_removes_directory(): void
    {
        $backupPath = ExtensionBackupHelper::createBackup('modules', 'test-backup-mod');

        ExtensionBackupHelper::deleteBackup($backupPath);

        $this->assertDirectoryDoesNotExist($backupPath);
    }

    /**
     * 존재하지 않는 소스로 백업 시 예외가 발생하는지 확인합니다.
     */
    public function test_create_backup_with_nonexistent_source_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        ExtensionBackupHelper::createBackup('modules', 'nonexistent-module');
    }
}
