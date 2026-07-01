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

    /**
     * 백업 디렉토리가 부모(php-fpm) 소유권을 상속한다 (POSIX best-effort).
     *
     * 회귀 가드 (버그 ③): sudo 업데이트가 백업 부모/디렉토리를 root 소유로 잔존시키면
     * 이후 www-data 의 mkdir 이 실패한다. createBackup 은 부모 소유권을 상속해 이를
     * 차단해야 한다. sudo 없는 일반 환경에서 상속은 no-op(이미 부모와 동일 owner) 이므로
     * "백업 owner == 부모 owner" 가 항상 성립해야 한다 — 상속이 owner 를 잘못 바꾸면 깨짐.
     */
    public function test_create_backup_inherits_parent_ownership(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! function_exists('fileowner')) {
            $this->markTestSkipped('소유권 검증은 POSIX 환경 전용 (Windows 로컬 자동 스킵)');
        }

        $backupPath = ExtensionBackupHelper::createBackup('modules', 'test-backup-mod');

        // 백업 루트(extension_backups/modules) 의 owner 가 부모(extension_backups) 와 일치
        $backupTypeDir = dirname($backupPath);
        $this->assertSame(
            fileowner(dirname($backupTypeDir)),
            fileowner($backupTypeDir),
            '백업 타입 디렉토리는 부모 소유권을 상속해야 함',
        );

        // 백업 타임스탬프 디렉토리도 부모 owner 와 일치
        $this->assertSame(
            fileowner($backupTypeDir),
            fileowner($backupPath),
            '백업 디렉토리는 부모 소유권을 상속해야 함',
        );
    }

    /**
     * 백업 부모 디렉토리(extension_backups/{type})가 g-w(0755)로 미리 존재해도 백업 생성이
     * 성공하고, 부모 체인이 g+w(0775)로 정상화된다 (POSIX best-effort).
     *
     * 회귀 가드 (버그 ③): sudo 코어 업데이트가 extension_backups/modules 등 백업 부모
     * 디렉토리를 g-w(0755)로 만들어 두면 이후 php-fpm 그룹이 그 안에 백업을 mkdir 하지
     * 못해 "mkdir(): Permission denied" 로 module:update 가 백업 생성 단계에서 실패한다.
     * createBackup 은 부모 체인을 g+w 로 보장한 뒤 백업을 생성해야 한다.
     */
    public function test_create_backup_succeeds_when_parent_dir_is_group_unwritable(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! function_exists('fileperms')) {
            $this->markTestSkipped('권한 검증은 POSIX 환경 전용 (Windows 로컬 자동 스킵)');
        }

        // 백업 부모 체인을 미리 g-w(0755)로 만들어 둔다 (sudo 업데이트 잔재 재현)
        $backupRoot = storage_path('app/extension_backups');
        $backupTypeDir = $backupRoot.'/modules';
        File::ensureDirectoryExists($backupTypeDir);
        @chmod($backupRoot, 0755);
        @chmod($backupTypeDir, 0755);

        // g-w 상태에서도 백업 생성이 mkdir 오류 없이 성공해야 한다
        $backupPath = ExtensionBackupHelper::createBackup('modules', 'test-backup-mod');

        $this->assertDirectoryExists($backupPath);

        // 부모 체인이 g+w(0775)로 정상화됨 — group write 비트(0020) 확인
        $this->assertSame(0020, fileperms($backupRoot) & 0020, 'extension_backups 가 g+w 여야 함');
        $this->assertSame(0020, fileperms($backupTypeDir) & 0020, 'extension_backups/modules 가 g+w 여야 함');
    }
}
