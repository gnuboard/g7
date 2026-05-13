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

    // ========================================================================
    // chownRecursiveDetailed / syncGroupWritabilityDetailed — Stage 3
    // 권한 정상화 실패 항목 누적 반환 검증
    // ========================================================================

    /**
     * chownRecursiveDetailed — chown 미지원 환경 (Windows) 에서는 supported=false.
     *
     * @return void
     */
    public function test_chown_recursive_detailed_returns_supported_false_when_chown_missing(): void
    {
        if (function_exists('chown')) {
            $this->markTestSkipped('chown 지원 환경 — supported=false 케이스 검증 불가');
        }

        $root = $this->createTempDir();
        $report = FilePermissionHelper::chownRecursiveDetailed($root, 0, false);

        $this->assertFalse($report['supported']);
        $this->assertSame(0, $report['changed']);
        $this->assertSame([], $report['failed_paths']);
    }

    /**
     * chownRecursiveDetailed — POSIX 환경에서 자기 자신을 owner 로 호출 시 changed=0, failed=0.
     *
     * 자기 소유자와 일치하면 chown 호출 자체가 발생하지 않으므로 멱등.
     *
     * @return void
     */
    public function test_chown_recursive_detailed_idempotent_when_owner_matches(): void
    {
        $this->assertPosixOrSkip();

        $root = $this->createTempDir();
        $owner = fileowner($root);
        $this->assertNotFalse($owner);

        $report = FilePermissionHelper::chownRecursiveDetailed($root, $owner, false);

        $this->assertTrue($report['supported']);
        $this->assertSame(0, $report['changed']);
        $this->assertSame(0, $report['failed']);
        $this->assertSame([], $report['failed_paths']);
    }

    /**
     * syncGroupWritabilityDetailed — 루트 g-w 환경은 skipped=true 로 보고.
     *
     * @return void
     */
    public function test_sync_group_writability_detailed_skipped_when_root_no_group_write(): void
    {
        $this->assertPosixOrSkip();

        $root = $this->createTempDir();
        chmod($root, 0755);

        $report = FilePermissionHelper::syncGroupWritabilityDetailed($root);

        $this->assertTrue($report['skipped']);
        $this->assertSame(0, $report['changed']);
        $this->assertSame(0, $report['failed']);
    }

    /**
     * syncGroupWritabilityDetailed — 정상 승격 시 changed>0, failed=0, skipped=false.
     *
     * @return void
     */
    public function test_sync_group_writability_detailed_reports_changed(): void
    {
        $this->assertPosixOrSkip();

        $root = $this->createTempDir();
        chmod($root, 0775);

        $child = $root.DIRECTORY_SEPARATOR.'sub';
        mkdir($child, 0755);

        $report = FilePermissionHelper::syncGroupWritabilityDetailed($root);

        $this->assertFalse($report['skipped']);
        $this->assertSame(1, $report['changed']);
        $this->assertSame(0, $report['failed']);
        $this->assertSame([], $report['failed_paths']);
    }

    // ========================================================================
    // 트랙 2-A — `.preserve-ownership` 마커 + chownRecursiveDetailed 가드
    // (코어 7.0.0-beta.4+)
    //
    // 사용자 데이터 디렉토리 (storage/app/{modules,plugins,attachments,...}) 가
    // 마커 파일을 보유하면, sudo update 의 chownRecursive 가 그 서브트리 전체를
    // 자동 skip 하여 시드 시점 owner/perms 보존. 미래 release transition 의
    // 권한 회귀를 구조적으로 차단.
    // ========================================================================

    /**
     * `chownRecursiveDetailed` 가 `respectPreservationMarker` 옵션을 지원해야 함.
     *
     * @return void
     */
    public function test_chown_recursive_detailed_supports_respect_preservation_marker_option(): void
    {
        $reflection = new \ReflectionMethod(FilePermissionHelper::class, 'chownRecursiveDetailed');
        $params = $reflection->getParameters();
        $names = array_map(fn ($p) => $p->getName(), $params);

        $this->assertContains(
            'respectPreservationMarker',
            $names,
            'chownRecursiveDetailed 가 respectPreservationMarker 옵션을 받아야 함 (마커 인식 가드)',
        );
    }

    /**
     * 마커가 있는 디렉토리 트리는 chownRecursiveDetailed 가 skip — 자기 자신/하위 모두 chown 안 함.
     *
     * @return void
     */
    public function test_chown_recursive_detailed_skips_subtree_when_preservation_marker_present(): void
    {
        $this->assertPosixOrSkip();

        $root = $this->createTempDir();
        $protectedDir = $root.DIRECTORY_SEPARATOR.'modules';
        mkdir($protectedDir, 0755);
        $imageDir = $protectedDir.DIRECTORY_SEPARATOR.'images';
        mkdir($imageDir, 0700);
        file_put_contents($imageDir.DIRECTORY_SEPARATOR.'test.jpg', 'data');

        // 마커 파일 작성 → protectedDir 전체 chown 비대상
        file_put_contents($protectedDir.DIRECTORY_SEPARATOR.'.preserve-ownership', '');

        $ownerBefore = fileowner($protectedDir);
        $imageDirOwnerBefore = fileowner($imageDir);

        // 자기 자신 owner 로 호출 (실제 chown 시도 — 일반 user 권한 환경에서도 호출 가능)
        // 그러나 마커 가드로 skip 되어야 함. owner 가 동일해도 changed 카운트가 마커 가드 분기 검증
        $owner = $ownerBefore;
        $report = FilePermissionHelper::chownRecursiveDetailed(
            $root,
            $owner,
            false,
            respectPreservationMarker: true,
        );

        $this->assertTrue($report['supported']);
        $this->assertSame($ownerBefore, fileowner($protectedDir), '마커 디렉토리 owner 무변경');
        $this->assertSame($imageDirOwnerBefore, fileowner($imageDir), '마커 하위 owner 무변경');
        $this->assertArrayHasKey('skipped_subtrees', $report, 'skipped_subtrees 카운트 보고');
        $this->assertGreaterThanOrEqual(1, $report['skipped_subtrees'], '최소 1개 서브트리 skip');
    }

    /**
     * 마커 옵션이 false 이면 (기본값) — 기존 동작 그대로 (호환성 유지).
     *
     * @return void
     */
    public function test_chown_recursive_detailed_backward_compatible_without_marker_option(): void
    {
        $this->assertPosixOrSkip();

        $root = $this->createTempDir();
        $owner = fileowner($root);

        // respectPreservationMarker 인자 없이 호출 → 기존 동작 (default false)
        $report = FilePermissionHelper::chownRecursiveDetailed($root, $owner, false);

        $this->assertTrue($report['supported']);
        $this->assertSame(0, $report['failed']);
    }
}
