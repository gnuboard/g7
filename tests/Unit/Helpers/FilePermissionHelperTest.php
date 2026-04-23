<?php

namespace Tests\Unit\Helpers;

use App\Extension\Helpers\FilePermissionHelper;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * FilePermissionHelper 단위 테스트
 *
 * copyDirectory의 퍼미션 보존, excludes 처리, removeOrphans 동작을 검증합니다.
 */
class FilePermissionHelperTest extends TestCase
{
    /**
     * 테스트에서 사용하는 임시 디렉토리 목록 (tearDown에서 정리)
     *
     * @var array<string>
     */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }

    /**
     * 테스트용 임시 디렉토리를 생성합니다.
     *
     * @return string 생성된 디렉토리 경로
     */
    private function createTempDir(): string
    {
        $dir = storage_path('test_fileperm_'.uniqid());
        File::ensureDirectoryExists($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    // ========================================================================
    // removeOrphans 기본 동작 (false) — 소스에 없는 파일 유지
    // ========================================================================

    /**
     * removeOrphans 기본값(false)일 때 소스에 없는 대상 파일이 유지되는지 검증합니다.
     *
     * @return void
     */
    public function test_copy_directory_does_not_remove_orphans_by_default(): void
    {
        $source = $this->createTempDir();
        $dest = $this->createTempDir();

        // 소스: file_a.txt만 존재
        File::put($source.DIRECTORY_SEPARATOR.'file_a.txt', 'source_a');

        // 대상: file_a.txt + orphan.txt (소스에 없는 파일)
        File::put($dest.DIRECTORY_SEPARATOR.'file_a.txt', 'old_a');
        File::put($dest.DIRECTORY_SEPARATOR.'orphan.txt', 'orphan_content');

        FilePermissionHelper::copyDirectory($source, $dest);

        // file_a.txt는 소스 내용으로 덮어쓰기
        $this->assertEquals('source_a', File::get($dest.DIRECTORY_SEPARATOR.'file_a.txt'));

        // orphan.txt는 유지 (기본 동작)
        $this->assertTrue(File::exists($dest.DIRECTORY_SEPARATOR.'orphan.txt'));
        $this->assertEquals('orphan_content', File::get($dest.DIRECTORY_SEPARATOR.'orphan.txt'));
    }

    // ========================================================================
    // removeOrphans=true — 소스에 없는 파일 삭제
    // ========================================================================

    /**
     * removeOrphans=true일 때 소스에 없는 파일이 삭제되는지 검증합니다.
     *
     * @return void
     */
    public function test_copy_directory_removes_orphans_when_enabled(): void
    {
        $source = $this->createTempDir();
        $dest = $this->createTempDir();

        // 소스: file_a.txt만 존재
        File::put($source.DIRECTORY_SEPARATOR.'file_a.txt', 'source_a');

        // 대상: file_a.txt + orphan.txt
        File::put($dest.DIRECTORY_SEPARATOR.'file_a.txt', 'old_a');
        File::put($dest.DIRECTORY_SEPARATOR.'orphan.txt', 'orphan_content');

        FilePermissionHelper::copyDirectory($source, $dest, removeOrphans: true);

        // file_a.txt는 소스 내용으로 덮어쓰기
        $this->assertEquals('source_a', File::get($dest.DIRECTORY_SEPARATOR.'file_a.txt'));

        // orphan.txt는 삭제됨
        $this->assertFalse(File::exists($dest.DIRECTORY_SEPARATOR.'orphan.txt'));
    }

    // ========================================================================
    // removeOrphans=true — 소스에 없는 디렉토리도 재귀 삭제
    // ========================================================================

    /**
     * removeOrphans=true일 때 소스에 없는 디렉토리가 재귀 삭제되는지 검증합니다.
     *
     * @return void
     */
    public function test_copy_directory_removes_orphan_directories(): void
    {
        $source = $this->createTempDir();
        $dest = $this->createTempDir();

        // 소스: subdir_a/file.txt만 존재
        File::ensureDirectoryExists($source.DIRECTORY_SEPARATOR.'subdir_a');
        File::put($source.DIRECTORY_SEPARATOR.'subdir_a'.DIRECTORY_SEPARATOR.'file.txt', 'content');

        // 대상: subdir_a/ + orphan_dir/ (소스에 없는 디렉토리)
        File::ensureDirectoryExists($dest.DIRECTORY_SEPARATOR.'subdir_a');
        File::put($dest.DIRECTORY_SEPARATOR.'subdir_a'.DIRECTORY_SEPARATOR.'file.txt', 'old');
        File::ensureDirectoryExists($dest.DIRECTORY_SEPARATOR.'orphan_dir');
        File::put($dest.DIRECTORY_SEPARATOR.'orphan_dir'.DIRECTORY_SEPARATOR.'deep.txt', 'deep_content');

        FilePermissionHelper::copyDirectory($source, $dest, removeOrphans: true);

        // subdir_a는 유지되고 내용 덮어쓰기
        $this->assertTrue(File::isDirectory($dest.DIRECTORY_SEPARATOR.'subdir_a'));
        $this->assertEquals('content', File::get($dest.DIRECTORY_SEPARATOR.'subdir_a'.DIRECTORY_SEPARATOR.'file.txt'));

        // orphan_dir는 재귀 삭제
        $this->assertFalse(File::isDirectory($dest.DIRECTORY_SEPARATOR.'orphan_dir'));
    }

    // ========================================================================
    // removeOrphans=true — 하위 디렉토리 내 orphan 파일도 삭제
    // ========================================================================

    /**
     * removeOrphans=true일 때 하위 디렉토리 내 소스에 없는 파일도 삭제되는지 검증합니다.
     *
     * @return void
     */
    public function test_copy_directory_removes_orphans_in_subdirectories(): void
    {
        $source = $this->createTempDir();
        $dest = $this->createTempDir();

        // 소스: sub/keep.txt만 존재
        File::ensureDirectoryExists($source.DIRECTORY_SEPARATOR.'sub');
        File::put($source.DIRECTORY_SEPARATOR.'sub'.DIRECTORY_SEPARATOR.'keep.txt', 'keep');

        // 대상: sub/keep.txt + sub/orphan.txt
        File::ensureDirectoryExists($dest.DIRECTORY_SEPARATOR.'sub');
        File::put($dest.DIRECTORY_SEPARATOR.'sub'.DIRECTORY_SEPARATOR.'keep.txt', 'old');
        File::put($dest.DIRECTORY_SEPARATOR.'sub'.DIRECTORY_SEPARATOR.'orphan.txt', 'orphan');

        FilePermissionHelper::copyDirectory($source, $dest, removeOrphans: true);

        // keep.txt는 덮어쓰기
        $this->assertEquals('keep', File::get($dest.DIRECTORY_SEPARATOR.'sub'.DIRECTORY_SEPARATOR.'keep.txt'));

        // sub/orphan.txt는 삭제
        $this->assertFalse(File::exists($dest.DIRECTORY_SEPARATOR.'sub'.DIRECTORY_SEPARATOR.'orphan.txt'));
    }

    // ========================================================================
    // removeOrphans=true + excludes — 제외 대상은 삭제하지 않음
    // ========================================================================

    /**
     * removeOrphans=true이더라도 excludes 대상은 삭제하지 않는지 검증합니다.
     *
     * @return void
     */
    public function test_copy_directory_preserves_excluded_orphans(): void
    {
        $source = $this->createTempDir();
        $dest = $this->createTempDir();

        // 소스: file_a.txt만 존재
        File::put($source.DIRECTORY_SEPARATOR.'file_a.txt', 'source_a');

        // 대상: file_a.txt + vendor/ + node_modules/ (excludes 대상)
        File::put($dest.DIRECTORY_SEPARATOR.'file_a.txt', 'old_a');
        File::ensureDirectoryExists($dest.DIRECTORY_SEPARATOR.'vendor');
        File::put($dest.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php', 'vendor_content');
        File::ensureDirectoryExists($dest.DIRECTORY_SEPARATOR.'node_modules');
        File::put($dest.DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR.'package.json', 'nm_content');

        $excludes = ['vendor', 'node_modules'];

        FilePermissionHelper::copyDirectory($source, $dest, excludes: $excludes, removeOrphans: true);

        // excludes 대상은 삭제되지 않음
        $this->assertTrue(File::isDirectory($dest.DIRECTORY_SEPARATOR.'vendor'));
        $this->assertTrue(File::isDirectory($dest.DIRECTORY_SEPARATOR.'node_modules'));
        $this->assertTrue(File::exists($dest.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php'));
    }

    // ========================================================================
    // 기존 파일 퍼미션 보존 확인
    // ========================================================================

    /**
     * 기존 파일의 퍼미션이 보존되는지 검증합니다. (Linux/Mac에서만 의미있음)
     *
     * @return void
     */
    public function test_copy_file_preserves_permissions_on_existing_files(): void
    {
        $source = $this->createTempDir();
        $dest = $this->createTempDir();

        $srcFile = $source.DIRECTORY_SEPARATOR.'script.sh';
        $destFile = $dest.DIRECTORY_SEPARATOR.'script.sh';

        File::put($srcFile, '#!/bin/bash\necho new');
        File::put($destFile, '#!/bin/bash\necho old');

        // Windows에서는 chmod가 제한적이므로 기본 동작만 검증
        $originalPerms = fileperms($destFile);

        FilePermissionHelper::copyFile($srcFile, $destFile);

        // 내용은 소스로 교체
        $this->assertStringContainsString('new', File::get($destFile));

        // 퍼미션 복원 시도 확인 (Windows에서는 값이 같을 수 있음)
        $this->assertEquals($originalPerms, fileperms($destFile));
    }

    // ========================================================================
    // syncGroupWritability — sudo 업데이트 시 발생한 그룹 쓰기 권한 비대칭 정상화
    // (코어 7.0.0-beta.3 도입)
    //
    // 배경: sudo root 로 실행된 코어 업데이트가 storage/framework/cache 하위에
    // umask 022 로 신규 디렉토리(0755 drwxr-xr-x) 를 생성한 뒤 chownRecursive 가
    // 소유자만 jjh:www-data 로 복원하면, www-data 그룹에 쓰기 권한이 없어
    // php-fpm 이 cache 파일 생성 실패 (Permission denied).
    //
    // 정책: 루트가 g+w 면 하위 항목 중 g-w 인 디렉토리·파일을 g+w 로 승격.
    // 다른 비트 무변경. 루트가 g-w 면 no-op (운영자 정책 보존).
    //
    // 검증 대상은 chmod / fileperms 가 의미를 갖는 POSIX 환경 전용. Windows
    // 로컬은 자동 스킵하며, 실제 Linux CI / 운영 서버에서 의미 있는 검증 수행.
    // ========================================================================

    /**
     * Linux/macOS 환경 감지. Windows / chmod 미지원 환경은 스킵.
     */
    private function assertPosixOrSkip(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! function_exists('posix_getuid')) {
            $this->markTestSkipped('chmod 검증은 POSIX 환경 전용 (Windows 로컬 자동 스킵)');
        }
    }

    /**
     * 루트 0775 + 자식·손자 0755 + 파일 0644 → 호출 후 자식·손자·파일 모두 g+w 승격.
     *
     * @return void
     */
    public function test_sync_group_writability_recovers_child_dirs_and_files(): void
    {
        $this->assertPosixOrSkip();

        $root = $this->createTempDir();
        chmod($root, 0775);

        $child = $root.DIRECTORY_SEPARATOR.'cache_hash_2c';
        mkdir($child, 0755);

        $grandchild = $child.DIRECTORY_SEPARATOR.'ab';
        mkdir($grandchild, 0755);

        $file = $grandchild.DIRECTORY_SEPARATOR.'cachekey';
        file_put_contents($file, 'data');
        chmod($file, 0644);

        $changed = FilePermissionHelper::syncGroupWritability($root);

        // 루트 정책 (0775) 그대로 + 하위 모두 g+w 승격
        $this->assertSame(0775, fileperms($root) & 0777, '루트는 변경되지 않음');
        $this->assertSame(0775, fileperms($child) & 0777, '자식 디렉토리 g+w 승격');
        $this->assertSame(0775, fileperms($grandchild) & 0777, '손자 디렉토리 g+w 승격');
        $this->assertSame(0664, fileperms($file) & 0777, '파일 g+w 승격 (0644 → 0664)');
        $this->assertSame(3, $changed, 'changed 카운트 = 자식 + 손자 + 파일');
    }

    /**
     * 루트가 g-w (0755) 인 경우 — no-op. 운영자 정책 보존.
     *
     * @return void
     */
    public function test_sync_group_writability_respects_root_policy_without_group_write(): void
    {
        $this->assertPosixOrSkip();

        $root = $this->createTempDir();
        chmod($root, 0755);

        $child = $root.DIRECTORY_SEPARATOR.'sub';
        mkdir($child, 0755);

        $changed = FilePermissionHelper::syncGroupWritability($root);

        $this->assertSame(0755, fileperms($root) & 0777);
        $this->assertSame(0755, fileperms($child) & 0777, '루트가 g-w 면 자식 변경 없음');
        $this->assertSame(0, $changed);
    }

    /**
     * 이미 정상 (자식·손자 모두 g+w) → no-op (멱등).
     *
     * @return void
     */
    public function test_sync_group_writability_is_idempotent(): void
    {
        $this->assertPosixOrSkip();

        $root = $this->createTempDir();
        chmod($root, 0775);

        $child = $root.DIRECTORY_SEPARATOR.'ok';
        mkdir($child, 0775);

        $file = $root.DIRECTORY_SEPARATOR.'fine.txt';
        file_put_contents($file, 'x');
        chmod($file, 0664);

        $changed = FilePermissionHelper::syncGroupWritability($root);

        $this->assertSame(0775, fileperms($child) & 0777);
        $this->assertSame(0664, fileperms($file) & 0777);
        $this->assertSame(0, $changed, '이미 정상 → changed=0');
    }

    /**
     * 다른 비트(other, owner, sticky 등) 무변경 — g+w 만 OR.
     *
     * @return void
     */
    public function test_sync_group_writability_only_adds_group_write_bit(): void
    {
        $this->assertPosixOrSkip();

        $root = $this->createTempDir();
        chmod($root, 0775);

        // 0700 = owner rwx만, group/other 무권한. 그룹에 w만 추가되어 0720이 되어야 함
        $file = $root.DIRECTORY_SEPARATOR.'restricted.bin';
        file_put_contents($file, '');
        chmod($file, 0700);

        $changed = FilePermissionHelper::syncGroupWritability($root);

        // 0700 → 0720 (g+w 만 OR, owner/other 비트 무변경)
        $this->assertSame(0720, fileperms($file) & 0777, 'g+w 만 추가, 다른 비트 무변경');
        $this->assertSame(1, $changed);
    }
}
