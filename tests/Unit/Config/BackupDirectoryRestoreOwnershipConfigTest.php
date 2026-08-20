<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * 백업 디렉토리가 코어 업데이트 소유권 복원 대상에 포함되는지 검증 (OS 무관).
 *
 * 회귀 가드 (버그 ③): sudo 업데이트가 백업 디렉토리(storage/app/{extension,core}_backups)
 * 를 root 소유로 잔존시켜 이후 www-data 의 mkdir 이 "mkdir(): Permission denied" 로
 * 실패하던 결함. config 의 restore_ownership / restore_ownership_group_writable 목록에
 * 백업 2경로가 포함되어야 Step 11 restoreOwnership 이 이를 정상화한다.
 */
class BackupDirectoryRestoreOwnershipConfigTest extends TestCase
{
    /**
     * restore_ownership 목록에 백업 2경로가 포함됨.
     */
    public function test_restore_ownership_includes_backup_directories(): void
    {
        $paths = config('app.update.restore_ownership');

        $this->assertIsArray($paths);
        $this->assertContains('storage/app/extension_backups', $paths);
        $this->assertContains('storage/app/core_backups', $paths);
    }

    /**
     * restore_ownership_group_writable 목록에 백업 2경로가 포함됨.
     */
    public function test_restore_ownership_group_writable_includes_backup_directories(): void
    {
        $paths = config('app.update.restore_ownership_group_writable');

        $this->assertIsArray($paths);
        $this->assertContains('storage/app/extension_backups', $paths);
        $this->assertContains('storage/app/core_backups', $paths);
    }
}
