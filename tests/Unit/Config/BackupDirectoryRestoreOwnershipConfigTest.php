<?php

namespace Tests\Unit\Config;

use App\Services\CoreUpdateService;
use Tests\TestCase;

/**
 * 백업·기록 디렉토리가 코어 업데이트 소유권·그룹 쓰기 복원 대상에 포함되는지 검증 (OS 무관).
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
     *
     * @scenario directory=backup
     *
     * @effects core_update_ownership_restores_backup_directories
     *
     * @return void 반환값 없음
     */
    public function test_restore_ownership_includes_backup_directories(): void
    {
        $paths = config('app.update.restore_ownership');

        $this->assertIsArray($paths);
        $this->assertContains('storage/app/extension_backups', $paths);
        $this->assertContains('storage/app/core_backups', $paths);
    }

    /**
     * 정적 게시 트리(`public/build/ext`)가 두 목록에 포함된다.
     *
     * root 프로세스는 자동 게시를 예약하지 않으므로 이 항목의 목적은 "terminating 게시가 만든 root
     * 디렉토리" 가 아니라 **실존하는** 게시 트리(웹 계정이 게시한 사이트)의 소유권을 코어 업데이트
     * 뒤 되돌리는 것이다 — 빠지면 재게시·GC 가 영구 실패해 정적 fast path 가 꺼진다.
     *
     * @scenario directory=static_publish
     *
     * @effects core_update_ownership_restores_static_publish_tree
     *
     * @return void 반환값 없음
     */
    public function test_restore_ownership_includes_static_publish_tree(): void
    {
        $this->assertContains('public/build/ext', config('app.update.restore_ownership'));
        $this->assertContains('public/build/ext', config('app.update.restore_ownership_group_writable'));
    }

    /**
     * 업데이트 기록 폴더 2곳(`storage/app/core_update`·`storage/app/core-update`)이 그룹 쓰기 정상화 대상이고,
     * 루트가 g-w 여도 승격하는 강제 대상이다.
     *
     * 회귀 가드: 7.2.0 → 7.2.1 웹 업데이트 실측에서 `storage/app/core_update` 가 0755 로 만들어졌다(생성 코드가
     * 기본 모드). 소유자(배포 계정)와 웹서버 계정이 같은 그룹으로 갈린 서버에서는 다음 업데이트가 이 폴더에
     * 미배치 번들 기록을 쓰지 못한다. 두 폴더는 백업 폴더처럼 업데이트가 만들고 쓰는 기록 영역이라
     * 운영자 차단 정책 보존 대상이 아니다.
     *
     * @scenario directory=update_record
     *
     * @effects core_update_group_writable_forces_update_record_directories
     *
     * @return void 반환값 없음
     */
    public function test_update_record_directories_are_forced_group_writable(): void
    {
        $paths = config('app.update.restore_ownership_group_writable');

        $this->assertContains('storage/app/core_update', $paths);
        $this->assertContains('storage/app/core-update', $paths);

        foreach (['storage/app/extension_backups', 'storage/app/core_backups', 'storage/app/core_update', 'storage/app/core-update'] as $forced) {
            $this->assertContains($forced, CoreUpdateService::FORCE_GROUP_WRITABLE_PATHS);
        }
    }

    /**
     * restore_ownership_group_writable 목록에 백업 2경로가 포함됨.
     *
     * @scenario directory=backup
     *
     * @effects core_update_group_writable_includes_backup_directories
     *
     * @return void 반환값 없음
     */
    public function test_restore_ownership_group_writable_includes_backup_directories(): void
    {
        $paths = config('app.update.restore_ownership_group_writable');

        $this->assertIsArray($paths);
        $this->assertContains('storage/app/extension_backups', $paths);
        $this->assertContains('storage/app/core_backups', $paths);
    }
}
