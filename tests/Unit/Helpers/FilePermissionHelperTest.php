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
}
