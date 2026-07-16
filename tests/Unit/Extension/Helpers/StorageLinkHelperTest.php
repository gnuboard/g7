<?php

namespace Tests\Unit\Extension\Helpers;

use App\Extension\Helpers\StorageLinkHelper;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `StorageLinkHelper::ensurePublicStorageLink` 멱등 복구 동작 회귀 가드.
 *
 * 코어 업데이트 `--prune` 이후 `public/storage` symlink 가 부재/손상 상태로 남아 업로드
 * 파일이 404 되던 결함(#43)의 종료 시점 방어 복구를 검증한다. `app()->setBasePath()` 로
 * 격리된 fake base 를 구성하여 `public_path()`/`storage_path()` 가 임시 트리를 가리키게 한다.
 *
 * Windows: PHP `symlink()` 는 `SeCreateSymbolicLink` 권한 필요. 권한 부족 환경에서는
 * junction 폴백이 작동하거나(둘 다 실패 시) markTestSkipped 로 건너뛴다.
 */
class StorageLinkHelperTest extends TestCase
{
    private string $fakeBase;

    private ?string $originalBasePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBasePath = base_path();
        $this->fakeBase = storage_path('app/testing/storage_link_helper_'.uniqid('', true));
        File::ensureDirectoryExists($this->fakeBase);
        app()->setBasePath($this->fakeBase);
    }

    protected function tearDown(): void
    {
        if ($this->originalBasePath !== null) {
            app()->setBasePath($this->originalBasePath);
        }

        if (File::isDirectory($this->fakeBase)) {
            // symlink/junction 은 target 을 따라가지 않도록 링크만 먼저 제거 (재귀 삭제 사고 차단).
            // 백업으로 생긴 `storage.broken.*` 링크까지 포함해 public/ 하위 링크를 모두 정리한다.
            $publicDir = $this->fakeBase.'/public';
            if (is_dir($publicDir)) {
                foreach (scandir($publicDir) as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $path = $publicDir.'/'.$entry;
                    if (is_link($path)) {
                        @unlink($path);
                    } elseif (PHP_OS_FAMILY === 'Windows' && ! is_file($path) && @readlink($path) !== false) {
                        // Windows junction — rmdir 로 링크만 제거 (target 미추적)
                        @rmdir($path);
                    }
                }
            }
            File::deleteDirectory($this->fakeBase);
        }

        parent::tearDown();
    }

    /**
     * symlink 생성 권한이 없으면 테스트를 건너뛴다 (Windows 일반 사용자).
     */
    private function skipIfNoSymlink(): void
    {
        $probe = $this->fakeBase.'/symlink_probe';
        $target = $this->fakeBase.'/symlink_probe_target';
        File::ensureDirectoryExists($target);
        if (! @symlink($target, $probe)) {
            $this->markTestSkipped('symlink 생성 권한 부족 (Windows SeCreateSymbolicLink)');
        }
        @unlink($probe);
        File::deleteDirectory($target);
    }

    #[Test]
    public function 이미_정상_symlink_이면_no_op_으로_보존합니다(): void
    {
        $this->skipIfNoSymlink();

        File::ensureDirectoryExists($this->fakeBase.'/public');
        File::ensureDirectoryExists($this->fakeBase.'/storage/app/public');
        @symlink($this->fakeBase.'/storage/app/public', $this->fakeBase.'/public/storage');

        $targetBefore = readlink($this->fakeBase.'/public/storage');

        StorageLinkHelper::ensurePublicStorageLink();

        $this->assertTrue(is_link($this->fakeBase.'/public/storage'), '정상 symlink 는 보존되어야 한다');
        $this->assertSame($targetBefore, readlink($this->fakeBase.'/public/storage'), 'target 미변경 (멱등)');
    }

    #[Test]
    public function public_storage_부재_시_symlink_를_신규_생성합니다(): void
    {
        $this->skipIfNoSymlink();

        File::ensureDirectoryExists($this->fakeBase.'/public');
        File::ensureDirectoryExists($this->fakeBase.'/storage/app/public');
        // public/storage 의도적 미생성 — prune 이 삭제한 시나리오

        StorageLinkHelper::ensurePublicStorageLink();

        $this->assertTrue(is_link($this->fakeBase.'/public/storage'), '부재 시 symlink 가 신규 생성되어야 한다');
        $this->assertSame($this->fakeBase.'/storage/app/public', readlink($this->fakeBase.'/public/storage'));
    }

    #[Test]
    public function 일반_디렉토리_손상_시_broken_백업_후_재생성합니다(): void
    {
        $this->skipIfNoSymlink();

        File::ensureDirectoryExists($this->fakeBase.'/public/storage');
        File::ensureDirectoryExists($this->fakeBase.'/storage/app/public');

        // 손상 상태: public/storage 가 dereferenced 콘텐츠를 가진 일반 디렉토리
        File::put($this->fakeBase.'/public/storage/uploaded.txt', 'dereferenced content');

        StorageLinkHelper::ensurePublicStorageLink();

        // 1) symlink 로 재생성
        $this->assertTrue(is_link($this->fakeBase.'/public/storage'), 'symlink 로 재생성되어야 한다');
        $this->assertSame($this->fakeBase.'/storage/app/public', readlink($this->fakeBase.'/public/storage'));

        // 2) .broken.{timestamp} 백업 존재 + 콘텐츠 보존
        $backups = array_values(array_filter(
            scandir($this->fakeBase.'/public'),
            fn ($e) => str_starts_with($e, 'storage.broken.'),
        ));
        $this->assertCount(1, $backups, '.broken.{timestamp} 백업 1개 존재');
        $this->assertFileExists($this->fakeBase.'/public/'.$backups[0].'/uploaded.txt');
        $this->assertSame('dereferenced content', File::get($this->fakeBase.'/public/'.$backups[0].'/uploaded.txt'));
    }

    #[Test]
    public function storage_app_public_source_부재_시_skip_합니다(): void
    {
        File::ensureDirectoryExists($this->fakeBase.'/public/storage');
        File::put($this->fakeBase.'/public/storage/custom.txt', 'operator content');
        // storage/app/public 의도적 미생성 — Laravel 표준 미사용 환경

        StorageLinkHelper::ensurePublicStorageLink();

        $this->assertFalse(is_link($this->fakeBase.'/public/storage'), 'source 부재 시 symlink 생성 안 함');
        $this->assertTrue(is_dir($this->fakeBase.'/public/storage'), '일반 디렉토리 그대로 유지');
        $this->assertFileExists($this->fakeBase.'/public/storage/custom.txt', '운영자 콘텐츠 보존');

        // rename 백업도 없어야 함
        $backups = array_values(array_filter(
            scandir($this->fakeBase.'/public'),
            fn ($e) => str_starts_with($e, 'storage.broken.'),
        ));
        $this->assertEmpty($backups, 'source 부재 시 rename 백업도 생성되지 않아야 한다');
    }

    #[Test]
    public function 로거_null_이면_upgrade_채널_폴백으로_예외없이_동작합니다(): void
    {
        $this->skipIfNoSymlink();

        File::ensureDirectoryExists($this->fakeBase.'/public');
        File::ensureDirectoryExists($this->fakeBase.'/storage/app/public');

        // null 로거로 호출 — 폴백 채널로 예외 없이 완료되어야 함
        StorageLinkHelper::ensurePublicStorageLink(null);

        $this->assertTrue(is_link($this->fakeBase.'/public/storage'));
    }

    /**
     * 재생성된 링크의 소유자/그룹이 부모 public/ 디렉토리를 상속합니다 (#43 sudo 후속).
     *
     * sudo 로 실행된 종료 시점 복구가 `symlink()` 로 링크를 만들면 링크 소유자가 root:root 로
     * 남아, 원래 앱 실행 유저 소유였던 링크의 소유권이 바뀌던 문제. 링크 생성 직후 부모
     * 디렉토리의 owner/group 으로 `lchown`/`lchgrp` 보정하는지 검증한다.
     *
     * 실측 방법: uid 변경은 root 권한이 필요하므로, 부모 public/ 의 그룹을 프로세스의 보조
     * 그룹 중 하나로 바꾼 뒤 링크를 재생성 → 링크 그룹이 부모 그룹을 상속하는지로 검증한다
     * (sudo 상황에서 링크만 다른 소유로 남는 회귀와 동형). Windows / 보조 그룹 부재 시 skip.
     */
    #[Test]
    public function 재생성된_링크는_부모_디렉토리의_소유권을_상속합니다(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('lchgrp') || ! function_exists('posix_getgroups')) {
            $this->markTestSkipped('링크 소유권 상속 검증은 POSIX(lchgrp/posix_getgroups) 환경 전용');
        }

        $this->skipIfNoSymlink();

        $publicDir = $this->fakeBase.'/public';
        File::ensureDirectoryExists($publicDir);
        File::ensureDirectoryExists($this->fakeBase.'/storage/app/public');

        // 프로세스가 chgrp 가능한 그룹 = 현재 그룹과 다른 보조 그룹을 찾는다.
        $currentGid = posix_getgid();
        $candidateGids = array_values(array_filter(posix_getgroups(), fn ($g) => $g !== $currentGid));
        if ($candidateGids === []) {
            $this->markTestSkipped('보조 그룹이 없어 소유권 상속 검증 불가');
        }

        // 부모 public/ 를 보조 그룹으로 변경 — 링크가 이 그룹을 상속해야 함.
        $targetGid = $candidateGids[0];
        if (! @chgrp($publicDir, $targetGid)) {
            $this->markTestSkipped('부모 디렉토리 chgrp 실패 (권한 부족)');
        }

        // public/storage 의도적 미생성 → 신규 생성 경로가 링크를 만든다.
        StorageLinkHelper::ensurePublicStorageLink();

        $linkPath = $this->fakeBase.'/public/storage';
        $this->assertTrue(is_link($linkPath), '링크가 생성되어야 한다');

        $linkStat = lstat($linkPath);
        $this->assertSame(
            $targetGid,
            $linkStat['gid'],
            '재생성된 링크의 그룹은 부모 public/ 의 그룹을 상속해야 한다 (sudo 후 root 소유 잔존 차단, #43)',
        );
    }

    /**
     * public/storage 부재 시 junction 폴백으로 재생성 (Windows — symlink 권한 불요).
     *
     * Windows 일반 사용자는 symlink 를 못 만들지만 junction(`mklink /J`)은 만들 수 있으므로,
     * `createLink` 의 junction 폴백이 부재 케이스를 실제로 복구하는지 검증한다.
     */
    #[Test]
    public function public_storage_부재_시_junction_폴백으로_재생성합니다(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('junction 은 Windows 전용 (mklink /J)');
        }

        File::ensureDirectoryExists($this->fakeBase.'/public');
        File::ensureDirectoryExists($this->fakeBase.'/storage/app/public');
        File::put($this->fakeBase.'/storage/app/public/uploaded.txt', 'user upload');
        // public/storage 의도적 미생성

        StorageLinkHelper::ensurePublicStorageLink();

        $this->assertTrue(is_dir($this->fakeBase.'/public/storage'), '부재 시 junction 으로 재생성되어야 한다');
        $this->assertFileExists($this->fakeBase.'/public/storage/uploaded.txt', 'junction 통과 시 업로드 파일 접근 가능');
    }

    /**
     * 일반 디렉토리 손상 시 junction 폴백으로 재생성 + .broken 백업 (Windows).
     */
    #[Test]
    public function 일반_디렉토리_손상_시_junction_폴백으로_재생성합니다(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('junction 은 Windows 전용 (mklink /J)');
        }

        File::ensureDirectoryExists($this->fakeBase.'/public/storage');
        File::ensureDirectoryExists($this->fakeBase.'/storage/app/public');
        File::put($this->fakeBase.'/public/storage/uploaded.txt', 'dereferenced content');

        // 재생성 전 source 에 마커 파일을 넣어, 재생성된 링크가 source 를 통과하는지로 검증한다.
        File::put($this->fakeBase.'/storage/app/public/marker.txt', 'from source');

        StorageLinkHelper::ensurePublicStorageLink();

        // junction 으로 재생성 → source(storage/app/public) 를 통과해 marker.txt 접근 가능
        $this->assertTrue(is_dir($this->fakeBase.'/public/storage'), 'junction 으로 재생성되어야 한다');
        $this->assertSame(
            'from source',
            File::get($this->fakeBase.'/public/storage/marker.txt'),
            '재생성된 링크는 storage/app/public 을 가리켜야 한다 (source 마커 접근 가능)',
        );

        // .broken 백업 존재 + dereferenced 콘텐츠 보존
        $backups = array_values(array_filter(
            scandir($this->fakeBase.'/public'),
            fn ($e) => str_starts_with($e, 'storage.broken.'),
        ));
        $this->assertCount(1, $backups, '.broken 백업 1개 존재');
        $this->assertFileExists($this->fakeBase.'/public/'.$backups[0].'/uploaded.txt');
        $this->assertSame('dereferenced content', File::get($this->fakeBase.'/public/'.$backups[0].'/uploaded.txt'));
    }

    /**
     * 이미 junction(정상 링크)이면 no-op — rename 백업하지 않고 보존 (Windows).
     */
    #[Test]
    public function 이미_junction_이면_no_op_으로_보존합니다(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('junction 은 Windows 전용 (mklink /J)');
        }

        File::ensureDirectoryExists($this->fakeBase.'/public');
        File::ensureDirectoryExists($this->fakeBase.'/storage/app/public');
        File::put($this->fakeBase.'/storage/app/public/uploaded.txt', 'user upload');

        exec('cmd /c mklink /J '.escapeshellarg($this->fakeBase.'/public/storage').' '.escapeshellarg($this->fakeBase.'/storage/app/public').' 2>&1', $out, $code);
        if ($code !== 0 || ! is_dir($this->fakeBase.'/public/storage')) {
            $this->markTestSkipped('junction 생성 실패: '.implode("\n", $out));
        }

        StorageLinkHelper::ensurePublicStorageLink();

        // .broken 백업이 생기지 않아야 함 (no-op)
        $backups = array_values(array_filter(
            scandir($this->fakeBase.'/public'),
            fn ($e) => str_starts_with($e, 'storage.broken.'),
        ));
        $this->assertEmpty($backups, '이미 정상 junction 은 rename 백업 없이 보존되어야 한다');
        $this->assertFileExists($this->fakeBase.'/public/storage/uploaded.txt');
    }
}
