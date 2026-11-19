<?php

namespace Tests\Unit\Extension\Helpers;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Helpers\StorageLinkHelper;
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

    /**
     * 테스트마다 storage 아래에 임시 작업 루트를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = storage_path('app/testing/file_permission_symlink_'.uniqid('', true));
        File::ensureDirectoryExists($this->tempRoot);
    }

    /**
     * 임시 작업 루트를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempRoot)) {
            File::deleteDirectory($this->tempRoot);
        }

        parent::tearDown();
    }

    /**
     * source 안의 symlink 를 복사하면 dest 에도 같은 대상을 가리키는 symlink 로 남고 대상 내용이 실제 디렉토리로 복사되지 않는지 확인한다.
     *
     * @scenario symlink_target_state=valid
     *
     * @effects copyDirectory_preserves_symlink_target_pointer_on_linux
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function copy_directory_는_symlink_를_target_추적_없이_그대로_보존합니다(): void
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

    /**
     * dest 에 같은 이름의 일반 디렉토리가 있을 때 source 의 symlink 로 교체되는지 확인한다.
     *
     * @scenario symlink_target_state=valid
     *
     * @effects copyDirectory_replaces_existing_directory_with_symlink_when_source_is_symlink
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function copy_directory_는_기존_dest_디렉토리를_symlink_로_교체할_때_dest_먼저_정리합니다(): void
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

    /**
     * dest 에만 있는 orphan symlink 를 지울 때 링크만 제거하고 링크 대상의 파일은 남기는지 확인한다.
     *
     * @scenario update_mode=prune
     *
     * @effects removeOrphanItems_unlinks_orphan_symlinks_without_recursive_delete
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function remove_orphan_items_는_source_부재_symlink_를_unlink_로_삭제합니다(): void
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

    /**
     * 보호 화이트리스트(`preserveLinkPaths`)에 매칭되는 orphan symlink 는 삭제하지 않고 보존.
     *
     * 코어 업데이트 prune 모드에서 `public/storage` symlink 가 릴리즈 소스에 없다는 이유로
     * orphan 삭제되어 업로드 파일이 404 되던 결함(#43) 회귀 가드.
     *
     * @scenario update_mode=prune
     *
     * @effects removeOrphanItems_preserves_whitelisted_orphan_links
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function remove_orphan_items_는_화이트리스트_symlink_를_orphan_이어도_보존합니다(): void
    {
        $source = $this->tempRoot.'/src';
        $dest = $this->tempRoot.'/dst';
        $linkTarget = $this->tempRoot.'/link_target';

        File::ensureDirectoryExists($source);
        File::ensureDirectoryExists($dest);
        File::ensureDirectoryExists($linkTarget);
        File::put($linkTarget.'/uploaded.txt', '업로드 파일');

        // dest 에만 존재하는 storage symlink (source 에는 없음 → orphan)
        if (! @symlink($linkTarget, $dest.'/storage')) {
            $this->markTestSkipped('symlink 생성 실패');
        }

        // preserveLinkPaths=['storage'] 로 prune 실행 → 보호되어야 함
        FilePermissionHelper::copyDirectory($source, $dest, null, [], '', true, false, null, ['storage']);

        $this->assertTrue(is_link($dest.'/storage'), '화이트리스트 symlink 는 orphan 이어도 보존되어야 한다');
        $this->assertSame($linkTarget, readlink($dest.'/storage'));
        $this->assertFileExists($linkTarget.'/uploaded.txt', 'symlink target 파일 보존');
    }

    /**
     * 보호 경로가 **일반 디렉토리**여도 보존한다 — 링크 여부에 묶이지 않는다.
     *
     * 종전 판정은 `in_array(...) && (is_link || isReparsePoint)` 였다. 그래서 심볼릭
     * 링크를 만들 수 없어 `public/storage` 를 **복사 디렉토리로 운영**하는 사이트
     * (이 안이 대상으로 삼은 바로 그 호스팅)와 7.1.0 정리 대상 상태에서는 보호가 통째로
     * 빠져 `deleteDirectory()` 로 **공개 업로드 전량이 영구 삭제**됐다. 그 경로는 릴리즈
     * 소스에 없는 것이 정상이라 orphan 판정은 언제나 참이다.
     *
     * @scenario storage_link=plain_directory, update_mode=prune
     *
     * @effects prune_preserves_public_storage_even_when_not_a_link
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function remove_orphan_items_는_보호_경로가_일반_디렉토리여도_보존합니다(): void
    {
        $source = $this->tempRoot.'/src2';
        $dest = $this->tempRoot.'/dst2';

        File::ensureDirectoryExists($source);
        File::ensureDirectoryExists($dest.'/storage');
        File::put($dest.'/storage/uploaded.txt', '업로드 파일');

        // 손상 백업도 같은 이유로 보존 대상이다 — 7.1.0 스텝이 문서 루트 밖으로 옮길
        // 때까지 운영자에게 남은 유일한 사본일 수 있는데, 그 스텝은 이 정리보다 뒤에 돈다.
        File::ensureDirectoryExists($dest.'/storage.broken.20260101010101');
        File::put($dest.'/storage.broken.20260101010101/old.txt', '이전 사본');

        FilePermissionHelper::copyDirectory($source, $dest, null, [], '', true, false, null, ['storage']);

        $this->assertFileExists(
            $dest.'/storage/uploaded.txt',
            '보호 경로가 링크가 아니라는 이유로 공개 업로드가 삭제되면 안 된다'
        );
        $this->assertFileExists(
            $dest.'/storage.broken.20260101010101/old.txt',
            '손상 백업은 7.1.0 스텝이 옮길 대상이다 — 그 전에 지우면 옮길 것이 없다'
        );
    }

    /**
     * 화이트리스트 밖 orphan symlink 는 `preserveLinkPaths=['storage']` 여도 여전히 삭제.
     *
     * 정밀 보호(무조건 보존 아님)임을 보장 — `public/storage` 만 좁게 보존한다는 설계 확정.
     *
     * @scenario update_mode=prune
     *
     * @effects removeOrphanItems_still_deletes_orphan_links_outside_whitelist
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function remove_orphan_items_는_화이트리스트_밖_symlink_는_여전히_삭제합니다(): void
    {
        $source = $this->tempRoot.'/src';
        $dest = $this->tempRoot.'/dst';
        $linkTarget = $this->tempRoot.'/link_target';

        File::ensureDirectoryExists($source);
        File::ensureDirectoryExists($dest);
        File::ensureDirectoryExists($linkTarget);
        File::put($linkTarget.'/precious.txt', '중요한 파일');

        // dest 에만 존재하는 다른 이름의 orphan symlink
        if (! @symlink($linkTarget, $dest.'/other_link')) {
            $this->markTestSkipped('symlink 생성 실패');
        }

        // 화이트리스트는 'storage' 만 — 'other_link' 는 보호 대상 아님
        FilePermissionHelper::copyDirectory($source, $dest, null, [], '', true, false, null, ['storage']);

        $this->assertFalse(is_link($dest.'/other_link'), '화이트리스트 밖 orphan symlink 는 삭제되어야 한다');
        $this->assertFileExists($linkTarget.'/precious.txt', 'symlink target 파일은 보존 (재귀 삭제 사고 차단)');
    }

    /**
     * 보호 화이트리스트에 매칭되는 orphan junction 도 보존 (Windows 전용).
     *
     * @scenario update_mode=prune
     *
     * @effects removeOrphanItems_preserves_whitelisted_orphan_links
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function remove_orphan_items_는_화이트리스트_junction_을_orphan_이어도_보존합니다(): void
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
        File::put($linkTarget.'/uploaded.txt', '업로드 파일');

        exec('cmd /c mklink /J '.escapeshellarg($dest.'/storage').' '.escapeshellarg($linkTarget).' 2>&1', $out, $code);
        if ($code !== 0 || ! is_dir($dest.'/storage')) {
            $this->markTestSkipped('junction 생성 실패: '.implode("\n", $out));
        }

        FilePermissionHelper::copyDirectory($source, $dest, null, [], '', true, false, null, ['storage']);

        $this->assertDirectoryExists($dest.'/storage', '화이트리스트 junction 은 orphan 이어도 보존되어야 한다');
        $this->assertFileExists($dest.'/storage/uploaded.txt', 'junction 통과 시 target 파일 접근 가능');
    }

    /**
     * 링크가 없는 일반 디렉토리 트리는 하위 파일까지 내용 그대로 복사되는지 확인한다.
     *
     * @effects copyDirectory_copies_plain_directories_unchanged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function copy_directory_는_심볼릭링크_없는_일반_디렉토리는_기존_동작을_유지합니다(): void
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
     *
     * @effects copyDirectory_preserves_windows_junction_as_link
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function copy_directory_는_windows_junction_을_파일로_오판하지_않고_보존합니다(): void
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

        // 위 두 단언은 **보존과 역참조 심층복사를 구분하지 못한다** — 내용을 통째로 베껴도
        // 둘 다 통과한다. 복사 뒤에 target 에 새 파일을 넣어 dest 에서 보이는지로 가른다.
        File::put($linkTarget.'/after_copy.txt', '복사 후 target 에 생긴 파일');
        $this->assertFileExists(
            $dest.'/storage/after_copy.txt',
            'junction 이 보존되지 않고 내용이 복사됐다 — target 변경이 dest 에 비치지 않는다'
        );
    }

    /**
     * junction 판정이 저장소의 실측 사실과 어긋나면 안 됩니다.
     *
     * `StorageLinkHelper` 가 PHP 8.3/Windows 실측으로 확정한 사실은 「정상 junction 은
     * `is_dir`·`file_exists` 둘 다 true」다. 그런데 `FilePermissionHelper` 의 판정은 한때
     * 그 반대(`is_dir` 이면 junction 아님)를 전제로 조기 반환해, **모든 정상 junction 을
     * 일반 디렉토리로 오판**했다. 그러면 `removeOrphanItems` 가 링크만 지우는 분기를 놓치고
     * `File::deleteDirectory` 로 내려가 **target 안의 파일을 전부 지운다**(#43 이 막으려던 사고).
     *
     * @effects junction_detection_matches_storage_link_helper_ssot
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function junction_은_링크_판정_ssot_와_같은_결과여야_합니다(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('junction 은 Windows 전용 (mklink /J)');
        }

        $linkTarget = $this->tempRoot.'/ssot_target';
        $junctionPath = $this->tempRoot.'/ssot_junction';

        File::ensureDirectoryExists($linkTarget);
        exec('cmd /c mklink /J '.escapeshellarg($junctionPath).' '.escapeshellarg($linkTarget).' 2>&1', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('junction 생성 실패: '.implode("\n", $out));
        }

        $reflection = new \ReflectionMethod(FilePermissionHelper::class, 'isReparsePoint');

        $this->assertTrue(
            $reflection->invoke(null, $junctionPath),
            'FilePermissionHelper 가 정상 junction 을 인식하지 못한다 — 링크 보존·링크만 제거 분기가 전부 무력화된다'
        );
        $this->assertTrue(
            StorageLinkHelper::isLinkOrJunction($junctionPath),
            '링크 판정 SSoT 도 같은 결과여야 한다'
        );
        $this->assertFalse(
            $reflection->invoke(null, $linkTarget),
            '일반 디렉토리를 junction 으로 오판하면 안 된다'
        );
    }

    /**
     * removeOrphanItems 가 source 부재 junction 을 재귀 삭제(target 내용 삭제)하지 않고
     * 링크 자체만 제거하는지 검증 (junction 은 isDir() 이 false 라 별도 rmdir 경로 필요).
     *
     * @scenario update_mode=prune
     *
     * @effects removeOrphanItems_unlinks_orphan_symlinks_without_recursive_delete
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function remove_orphan_items_는_source_부재_junction_을_target_추적_없이_링크만_제거합니다(): void
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
