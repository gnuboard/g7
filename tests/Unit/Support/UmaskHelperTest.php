<?php

namespace Tests\Unit\Support;

use App\Support\UmaskHelper;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * UmaskHelper::configureForGroupSharing 단위 테스트
 *
 * 운영자 의도 존중 umask 조정 헬퍼. 본 테스트는 POSIX 의미의 `fileperms` 와
 * `umask` 동작을 검증하므로 Linux 환경에서만 의미 있는 결과를 낸다. Windows
 * 에서는 자동 스킵.
 */
class UmaskHelperTest extends TestCase
{
    private string $tmpRoot;

    private int $originalUmask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalUmask = umask();
        $this->tmpRoot = storage_path('app/test-umask-helper-'.uniqid());
        File::ensureDirectoryExists($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        // 헬퍼 호출로 변경된 umask 를 원래대로 복원 (다른 테스트 격리)
        umask($this->originalUmask);

        if (File::isDirectory($this->tmpRoot)) {
            File::deleteDirectory($this->tmpRoot);
        }

        parent::tearDown();
    }

    private function assertPosixOrSkip(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! function_exists('posix_getuid')) {
            $this->markTestSkipped('POSIX 전용 — Windows 에서는 fileperms/umask 의미 제한');
        }
    }

    /**
     * storage 디렉토리에 g+w 가 설정되어 있으면 umask 를 0002 로 조정하고
     * 이전 umask 를 반환한다.
     */
    public function test_sets_umask_when_storage_has_group_write(): void
    {
        $this->assertPosixOrSkip();

        chmod($this->tmpRoot, 0775);

        // 초기 umask 를 0022 로 고정해 비교 가능하게
        umask(0022);

        $previous = UmaskHelper::configureForGroupSharing($this->tmpRoot);

        $this->assertSame(0022, $previous, '이전 umask 를 반환해야 한다');
        $this->assertSame(0002, umask(), '현재 umask 는 0002 로 조정되어야 한다');
    }

    /**
     * storage 디렉토리가 g-w 이면 운영자 의도 존중 — null 반환 + umask 변경 없음.
     */
    public function test_skips_when_storage_has_no_group_write(): void
    {
        $this->assertPosixOrSkip();

        chmod($this->tmpRoot, 0755);

        umask(0022);

        $result = UmaskHelper::configureForGroupSharing($this->tmpRoot);

        $this->assertNull($result, 'g-w 환경에서는 null 반환 (no-op)');
        $this->assertSame(0022, umask(), 'umask 는 변경되지 않아야 한다');
    }

    /**
     * 지정한 경로가 디렉토리가 아니면 null.
     */
    public function test_returns_null_when_path_is_not_a_directory(): void
    {
        $this->assertPosixOrSkip();

        $nonExistent = $this->tmpRoot.'/does-not-exist';

        umask(0022);

        $result = UmaskHelper::configureForGroupSharing($nonExistent);

        $this->assertNull($result);
        $this->assertSame(0022, umask(), 'umask 는 변경되지 않아야 한다');
    }

    /**
     * 조정 후 런타임이 만드는 새 디렉토리가 실제로 g+w 를 포함하는지 검증.
     * umask 조정의 최종 효과를 확인.
     */
    public function test_new_directory_inherits_group_write_after_adjustment(): void
    {
        $this->assertPosixOrSkip();

        chmod($this->tmpRoot, 0775);

        umask(0022);
        UmaskHelper::configureForGroupSharing($this->tmpRoot);

        $newDir = $this->tmpRoot.'/new-child';
        mkdir($newDir, 0777);

        $perms = fileperms($newDir) & 0777;
        $this->assertSame(0775, $perms, '조정된 umask(0002) 기준 mkdir 결과는 0775 여야 한다');
    }

    /**
     * 원상 복원 — 헬퍼가 이전 umask 를 반환하므로 호출자가 그 값으로 되돌릴 수 있다.
     */
    public function test_caller_can_restore_previous_umask(): void
    {
        $this->assertPosixOrSkip();

        chmod($this->tmpRoot, 0775);

        umask(0022);
        $previous = UmaskHelper::configureForGroupSharing($this->tmpRoot);

        $this->assertSame(0022, $previous);
        $this->assertSame(0002, umask());

        // 호출자가 반환값으로 원상 복원
        umask($previous);
        $this->assertSame(0022, umask());
    }
}
