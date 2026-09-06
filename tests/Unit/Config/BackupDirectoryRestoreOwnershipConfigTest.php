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
     * 정적 게시 트리(`public/build/ext`)가 두 목록에 포함된다 (#651 F22).
     *
     * root 프로세스는 자동 게시를 예약하지 않으므로 이 항목의 목적은 "terminating 게시가 만든 root
     * 디렉토리" 가 아니라 **실존하는** 게시 트리(웹 계정이 게시한 사이트)의 소유권을 코어 업데이트
     * 뒤 되돌리는 것이다 — 빠지면 재게시·GC 가 영구 실패해 정적 fast path 가 꺼진다.
     */
    public function test_restore_ownership_includes_static_publish_tree(): void
    {
        $this->assertContains('public/build/ext', config('app.update.restore_ownership'));
        $this->assertContains('public/build/ext', config('app.update.restore_ownership_group_writable'));
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
