<?php

namespace Tests\Unit\Extension\Helpers;

use App\Extension\Helpers\FilePermissionHelper;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `FilePermissionHelper::copyDirectory` / `removeOrphanItems` 의 symlink 보존 동작 회귀 가드.
 *
 * 회귀 시나리오 (gnuboard/g7#28):
 *   - 코어 자동 롤백 시 `public/storage` symlink 가 target 디렉토리 내용으로 추적 복사되어
 *     symlink 가 일반 디렉토리로 변질 → 운영자가 `storage:link` 재실행 필요
 *   - `removeOrphanItems` 가 source 부재 symlink 를 디렉토리로 인식하여 target 의 모든 파일을
 *     재귀 삭제하는 사고 가능성
 *
 * Windows: PHP `symlink()` 는 `SeCreateSymbolicLink` 권한 필요. 일반 사용자 환경에서는
 * 권한 부족으로 symlink 생성 자체가 실패할 가능성이 높으므로 symlink 가 생성되지 않으면 skip.
 */
class FilePermissionHelperSymlinkTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = storage_path('app/testing/file_permission_symlink_'.uniqid('', true));
        File::ensureDirectoryExists($this->tempRoot);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempRoot)) {
            File::deleteDirectory($this->tempRoot);
        }

        parent::tearDown();
    }

    #[Test]
    public function copyDirectory_는_symlink_를_target_추적_없이_그대로_보존합니다(): void
    {
        $source = $this->tempRoot.'/src';
        $dest = $this->tempRoot.'/dst';
        $linkTarget = $this->tempRoot.'/link_target';

        File::ensureDirectoryExists($source);
        File::ensureDirectoryExists($linkTarget);
        File::put($linkTarget.'/inside.txt', '링크 대상 안 파일');

        $linkPath = $source.'/storage';
        if (! @symlink($linkTarget, $linkPath)) {
            $this->markTestSkipped('symlink 생성 실패 (Windows SeCreateSymbolicLink 권한 부족 가능)');
        }

        FilePermissionHelper::copyDirectory($source, $dest);

        $this->assertTrue(is_link($dest.'/storage'), 'dest 의 storage 는 symlink 로 유지되어야 한다');
        $this->assertSame($linkTarget, readlink($dest.'/storage'));

        // target 내용이 dest 의 storage 디렉토리에 복사되어선 안 된다 — symlink 통한 간접 접근만 허용
        // 검증: dest/storage 를 link 가 아닌 일반 디렉토리로 직접 열어보면 0 entry
        $entries = scandir($dest.'/storage');
        $entries = array_values(array_diff($entries, ['.', '..']));
        // 링크가 살아있다면 symlink 통과해서 inside.txt 가 보임 (정상)
        $this->assertContains('inside.txt', $entries, 'symlink 통과 시 target 의 inside.txt 접근 가능');
        // 그러나 dest/storage 자체는 디렉토리 inode 가 아닌 link
        $this->assertTrue(is_link($dest.'/storage'));
    }

    #[Test]
    public function copyDirectory_는_기존_dest_디렉토리를_symlink_로_교체할_때_dest_먼저_정리합니다(): void
    {
        $source = $this->tempRoot.'/src';
        $dest = $this->tempRoot.'/dst';
        $linkTarget = $this->tempRoot.'/link_target';

        File::ensureDirectoryExists($source);
        File::ensureDirectoryExists($linkTarget);
        File::put($linkTarget.'/inside.txt', 'x');

        $linkPath = $source.'/storage';
        if (! @symlink($linkTarget, $linkPath)) {
            $this->markTestSkipped('symlink 생성 실패');
        }

        // dest 에 동일 이름의 일반 디렉토리 미리 생성 (백업 복원 직전 상태 시뮬)
        File::ensureDirectoryExists($dest.'/storage');
        File::put($dest.'/storage/stale.txt', '구버전 잔재');

        FilePermissionHelper::copyDirectory($source, $dest);

        $this->assertTrue(is_link($dest.'/storage'), '기존 디렉토리는 symlink 로 교체되어야 한다');
    }

    #[Test]
    public function removeOrphanItems_는_source_부재_symlink_를_unlink_로_삭제합니다(): void
    {
        $source = $this->tempRoot.'/src';
        $dest = $this->tempRoot.'/dst';
        $linkTarget = $this->tempRoot.'/link_target';

        File::ensureDirectoryExists($source);
        File::ensureDirectoryExists($dest);
        File::ensureDirectoryExists($linkTarget);
        File::put($linkTarget.'/precious.txt', '중요한 파일');

        // dest 에만 존재하는 symlink (source 에는 없음)
        if (! @symlink($linkTarget, $dest.'/orphan_link')) {
            $this->markTestSkipped('symlink 생성 실패');
        }

        FilePermissionHelper::copyDirectory($source, $dest, null, [], '', true);

        $this->assertFalse(is_link($dest.'/orphan_link'), 'orphan symlink 는 제거되어야 한다');
        $this->assertFileExists($linkTarget.'/precious.txt', 'symlink target 의 파일은 보존되어야 한다 (재귀 삭제 사고 차단)');
    }

    #[Test]
    public function copyDirectory_는_심볼릭링크_없는_일반_디렉토리는_기존_동작을_유지합니다(): void
    {
        $source = $this->tempRoot.'/src';
        $dest = $this->tempRoot.'/dst';

        File::ensureDirectoryExists($source.'/sub');
        File::put($source.'/file.txt', 'hi');
        File::put($source.'/sub/nested.txt', 'world');

        FilePermissionHelper::copyDirectory($source, $dest);

        $this->assertSame('hi', File::get($dest.'/file.txt'));
        $this->assertSame('world', File::get($dest.'/sub/nested.txt'));
    }

    /**
     * Windows JUNCTION (`mklink /J`) 는 PHP 의 `SplFileInfo::isLink()` / `isDir()` 이
     * 모두 false 를 반환하는 reparse point 다. `php artisan storage:link` 가 Windows 에서
     * 생성하는 `public/storage` 가 이 형태이며, 이를 파일로 오판하면 `copyFile` 이
     * 디렉토리를 `copy()` 에 넘겨 `copy(): The first argument ... cannot be a directory` 로
     * 코어 업데이트가 실패한다 (회귀 가드).
     *
     * junction 은 Windows 전용이므로 비-Windows 환경에서는 skip.
     */
    #[Test]
    public function copyDirectory_는_windows_junction_을_파일로_오판하지_않고_보존합니다(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('junction 은 Windows 전용 (mklink /J)');
        }

        $source = $this->tempRoot.'/src';
        $dest = $this->tempRoot.'/dst';
        $linkTarget = $this->tempRoot.'/link_target';

        File::ensureDirectoryExists($source);
        File::ensureDirectoryExists($linkTarget);
        File::put($linkTarget.'/inside.txt', 'junction 대상 안 파일');

        $junctionPath = $source.'/storage';
        // mklink /J 는 관리자 권한 없이 생성 가능 (심볼릭 링크와 달리 SeCreateSymbolicLink 불필요)
        exec('cmd /c mklink /J '.escapeshellarg($junctionPath).' '.escapeshellarg($linkTarget).' 2>&1', $out, $code);
        if ($code !== 0 || ! is_dir($junctionPath)) {
            $this->markTestSkipped('junction 생성 실패: '.implode("\n", $out));
        }

        // 사전 조건: PHP 가 junction 을 link/dir 어느 쪽으로도 인식하지 못함을 확인
        $this->assertFalse(is_link($junctionPath), 'junction 은 is_link() 가 false (전제)');

        // copyFile 이 디렉토리를 받아 던지던 예외 없이 완료되어야 한다
        FilePermissionHelper::copyDirectory($source, $dest);

        // junction 은 dest 에도 junction 으로 보존되어 target 내용에 접근 가능해야 한다
        $this->assertTrue(is_dir($dest.'/storage'), 'dest 의 storage 는 디렉토리(junction)로 접근 가능해야 한다');
        $this->assertFileExists($dest.'/storage/inside.txt', 'junction 통과 시 target 파일 접근 가능');
    }

    /**
     * removeOrphanItems 가 source 부재 junction 을 재귀 삭제(target 내용 삭제)하지 않고
     * 링크 자체만 제거하는지 검증 (junction 은 isDir() 이 false 라 별도 rmdir 경로 필요).
     */
    #[Test]
    public function removeOrphanItems_는_source_부재_junction_을_target_추적_없이_링크만_제거합니다(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('junction 은 Windows 전용 (mklink /J)');
        }

        $source = $this->tempRoot.'/src';
        $dest = $this->tempRoot.'/dst';
        $linkTarget = $this->tempRoot.'/link_target';

        File::ensureDirectoryExists($source);
        File::ensureDirectoryExists($dest);
        File::ensureDirectoryExists($linkTarget);
        File::put($linkTarget.'/precious.txt', '중요한 파일');

        // dest 에만 존재하는 junction (source 에는 없음)
        exec('cmd /c mklink /J '.escapeshellarg($dest.'/orphan_link').' '.escapeshellarg($linkTarget).' 2>&1', $out, $code);
        if ($code !== 0 || ! is_dir($dest.'/orphan_link')) {
            $this->markTestSkipped('junction 생성 실패: '.implode("\n", $out));
        }

        FilePermissionHelper::copyDirectory($source, $dest, null, [], '', true);

        $this->assertDirectoryDoesNotExist($dest.'/orphan_link', 'orphan junction 은 제거되어야 한다');
        $this->assertFileExists($linkTarget.'/precious.txt', 'junction target 의 파일은 보존되어야 한다 (재귀 삭제 사고 차단)');
    }
}
