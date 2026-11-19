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

    /**
     * 임시 기저 경로로 앱 기준 경로를 옮기고, 공개 디스크 루트도 같은 트리 아래로 맞춘다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBasePath = base_path();
        $this->fakeBase = storage_path('app/testing/storage_link_helper_'.uniqid('', true));
        File::ensureDirectoryExists($this->fakeBase);
        app()->setBasePath($this->fakeBase);

        // 공개 디스크 루트도 함께 옮긴다. 이 값은 **복구기와 판정기가 공유하는 출처**이고
        // (`PublicStorageService::rootPath()`), 기저 경로만 바꾸면 둘이 서로 다른 트리를
        // 보게 되어 픽스처가 재려는 상황 자체가 성립하지 않는다 — 종전에는 복구기만
        // `storage_path()` 리터럴을 써서 그 불일치가 가려져 있었다.
        config(['filesystems.disks.public.root' => $this->fakeBase.'/storage/app/public']);
    }

    /**
     * 링크 대상을 따라가지 않도록 public 아래 링크를 먼저 걷은 뒤 임시 트리를 지우고 기준 경로를 되돌린다.
     *
     * @return void 반환값 없음
     */
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
     *
     * @return void 반환값 없음
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

    /**
     * public/storage 가 이미 올바른 symlink 이면 복구기가 링크와 대상을 바꾸지 않는지 확인한다.
     *
     * @scenario storage_link=usable, link_kind=symlink
     *
     * @effects public_storage_link_recovery_keeps_a_healthy_link
     *
     * @return void 반환값 없음
     */
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

    /**
     * public/storage 가 없으면 복구기가 storage/app/public 을 가리키는 symlink 를 새로 만드는지 확인한다.
     *
     * @scenario storage_link=missing, link_kind=symlink
     *
     * @effects public_storage_link_recovery_recreates_a_missing_link
     *
     * @return void 반환값 없음
     */
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

    /**
     * public/storage 가 일반 디렉토리로 손상돼 있으면 내용을 보존한 .broken 백업 하나를 남기고 symlink 로 다시 만드는지 확인한다.
     *
     * @scenario storage_link=plain_dir, link_kind=symlink
     *
     * @effects public_storage_link_recovery_backs_up_a_plain_directory_and_relinks
     *
     * @return void 반환값 없음
     */
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

    /**
     * storage/app/public 원본이 없으면 링크도 백업도 만들지 않고 운영자 디렉토리를 그대로 두는지 확인한다.
     *
     * @scenario storage_link=plain_dir
     *
     * @effects public_storage_link_recovery_skips_when_source_is_absent
     *
     * @return void 반환값 없음
     */
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

    /**
     * 로거를 넘기지 않아도 예외 없이 링크를 만드는지 확인한다.
     *
     * @scenario storage_link=missing
     *
     * @effects public_storage_link_recovery_runs_without_an_injected_logger
     *
     * @return void 반환값 없음
     */
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
     *
     * @effects public_storage_link_recovery_inherits_parent_ownership
     *
     * @return void 반환값 없음
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
     *
     * @scenario storage_link=missing
     *
     * @effects public_storage_link_recovery_recreates_a_missing_link
     *
     * @return void 반환값 없음
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
     *
     * @scenario storage_link=plain_dir
     *
     * @effects public_storage_link_recovery_backs_up_a_plain_directory_and_relinks
     *
     * @return void 반환값 없음
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

        // 백업 존재 + dereferenced 콘텐츠 보존.
        //
        // 백업 위치는 **문서 루트 밖**(`storage/app/public-storage-broken-*`)이다. 종전에는
        // `public/storage.broken.*` 였는데, 그 사본이 제2 URL 로 무기한 서빙되어 운영자가 지운
        // 공개 파일이 계속 열렸다(정리 주체도 없다).
        //
        // 구현에는 볼륨이 달라 이동이 불가능한 환경을 위한 폴백(종전 위치)이 있지만, **이
        // 테스트 환경은 같은 볼륨**이라 그 분기에 들어가지 않는다 — 아래 단언이 그것을
        // 못박는다(`$insideDocroot` 는 비어 있어야 한다). 두 후보를 모두 훑는 것은 실패했을
        // 때 어느 쪽에 남았는지 메시지로 드러내기 위해서이지, 폴백을 허용하기 위해서가 아니다.
        $outsideDocroot = array_values(array_filter(
            scandir($this->fakeBase.'/storage/app'),
            fn ($e) => str_starts_with($e, 'public-storage-broken-'),
        ));
        $insideDocroot = array_values(array_filter(
            scandir($this->fakeBase.'/public'),
            fn ($e) => str_starts_with($e, 'storage.broken.'),
        ));

        $this->assertCount(1, array_merge($outsideDocroot, $insideDocroot), '백업 1개 존재');

        $backupPath = $outsideDocroot !== []
            ? $this->fakeBase.'/storage/app/'.$outsideDocroot[0]
            : $this->fakeBase.'/public/'.$insideDocroot[0];

        $this->assertSame([], $insideDocroot, '같은 볼륨이면 문서 루트 밖으로 옮겨져야 한다');
        $this->assertFileExists($backupPath.'/uploaded.txt');
        $this->assertSame('dereferenced content', File::get($backupPath.'/uploaded.txt'));
    }

    /**
     * 이미 junction(정상 링크)이면 no-op — rename 백업하지 않고 보존 (Windows).
     *
     * @scenario storage_link=usable
     *
     * @effects public_storage_link_recovery_keeps_a_healthy_link
     *
     * @return void 반환값 없음
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

    /**
     * 운영자가 공개 디스크 루트를 **옮긴** 사이트에서, 복구기가 그 루트를 가리키는 링크를
     * 만드는지 잰다.
     *
     * 앞서 복구기의 리터럴(`storage_path('app/public')`)을 판정기와 같은 출처로 바꿨다고
     * 기록했지만, 픽스처가 공개 루트를 `storage/app/public` 과 **같은 값**으로 두어
     * 「옮긴」 상황을 한 번도 만들지 않았다 — 리터럴로 되돌려도 전 케이스가 초록이었다.
     * 그 축을 여기서 연다.
     *
     * @scenario storage_link=missing
     *
     * @effects public_storage_link_recovery_targets_the_configured_public_root
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 공개_루트를_옮긴_사이트에서_설정된_루트를_가리키는_링크를_만듭니다(): void
    {
        $movedRoot = $this->fakeBase.'/custom-public-root';
        config(['filesystems.disks.public.root' => $movedRoot]);

        File::ensureDirectoryExists($this->fakeBase.'/public');
        File::ensureDirectoryExists($movedRoot);
        File::put($movedRoot.'/uploaded.txt', 'user upload');

        // 종전 리터럴이 가리키던 자리. 여기에도 트리를 두어, 복구기가 **어느 쪽을 골랐는지**가
        // 결과로 드러나게 한다 — 없으면 `is_dir()` 가드에 걸려 그냥 skip 이라 축이 죽는다.
        File::ensureDirectoryExists($this->fakeBase.'/storage/app/public');
        File::put($this->fakeBase.'/storage/app/public/stale.txt', 'old location');

        StorageLinkHelper::ensurePublicStorageLink();

        if (! StorageLinkHelper::isLinkOrJunction($this->fakeBase.'/public/storage')) {
            $this->markTestSkipped('이 환경에서 symlink·junction 둘 다 생성 실패');
        }

        $this->assertFileExists(
            $this->fakeBase.'/public/storage/uploaded.txt',
            '복구기가 **설정된** 공개 스토리지 루트를 가리켜야 한다',
        );
        $this->assertFileDoesNotExist(
            $this->fakeBase.'/public/storage/stale.txt',
            '복구기가 옛 리터럴 위치를 가리키면 그 사이트의 공개 자산이 전부 404 가 된다',
        );
    }

    /**
     * **이미** 옛 위치를 가리키는 살아 있는 링크를 복구기가 고치는지 잰다.
     *
     * 앞선 수정은 그 상태를 「생산」하지 않게만 고쳤다. 멱등 게이트가 `is_link()` 하나였으므로
     * 이미 그 상태인 사이트는 복구기가 그대로 지나쳤고 — 링크는 살아 있으니 true 다 —
     * 판정기는 같은 상태를 대상 불일치로 「불가」로 읽었다. 생산은 막혔는데 잔존은 영구였다.
     *
     * @effects public_storage_link_recovery_targets_the_configured_public_root
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 옛_위치를_가리키는_살아있는_링크를_설정된_루트로_재연결합니다(): void
    {
        $movedRoot = $this->fakeBase.'/custom-public-root';
        $staleRoot = $this->fakeBase.'/storage/app/public';

        File::ensureDirectoryExists($this->fakeBase.'/public');
        File::ensureDirectoryExists($movedRoot);
        File::ensureDirectoryExists($staleRoot);
        File::put($movedRoot.'/uploaded.txt', 'user upload');
        File::put($staleRoot.'/stale.txt', 'old location');

        // 먼저 옛 위치를 가리키는 **정상** 링크를 만든다 (설정은 아직 옛 위치).
        StorageLinkHelper::ensurePublicStorageLink();

        if (! StorageLinkHelper::isLinkOrJunction($this->fakeBase.'/public/storage')) {
            $this->markTestSkipped('이 환경에서 symlink·junction 둘 다 생성 실패');
        }

        $this->assertFileExists($this->fakeBase.'/public/storage/stale.txt', '전제: 옛 위치를 가리키는 링크가 서 있다');

        // 운영자가 공개 루트를 옮겼다.
        config(['filesystems.disks.public.root' => $movedRoot]);
        StorageLinkHelper::resetPublicStorageLinkMemo();

        $this->assertFalse(
            StorageLinkHelper::isUsableStorageLink($this->fakeBase.'/public/storage', $movedRoot),
            '전제: 판정기는 이 상태를 「불가」로 읽는다',
        );

        StorageLinkHelper::ensurePublicStorageLink();

        $this->assertFileExists(
            $this->fakeBase.'/public/storage/uploaded.txt',
            '복구기가 옛 위치를 가리키는 링크를 설정된 루트로 재연결해야 한다',
        );
        $this->assertFileDoesNotExist($this->fakeBase.'/public/storage/stale.txt');

        // 링크만 걷어냈으므로 옛 트리 자체는 그대로 남아 있어야 한다 (데이터 무손실).
        $this->assertFileExists($staleRoot.'/stale.txt', '링크 재연결이 옛 트리를 지워서는 안 된다');

        // 링크를 걷는 것은 백업 대상이 아니다 — 일반 디렉토리 손상과 성질이 다르다.
        $backups = array_values(array_filter(
            scandir($this->fakeBase.'/public'),
            fn ($e) => str_starts_with($e, 'storage.broken.'),
        ));
        $this->assertEmpty($backups, '링크 재연결은 rename 백업을 만들지 않는다');
    }

    /**
     * 끊어진(dangling) 링크도 복구 대상이다 — `is_link()` 는 대상을 보지 않는다.
     *
     * @scenario storage_link=broken
     *
     * @effects public_storage_link_recovery_targets_the_configured_public_root
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 끊어진_링크를_재연결합니다(): void
    {
        File::ensureDirectoryExists($this->fakeBase.'/public');
        File::ensureDirectoryExists($this->fakeBase.'/storage/app/public');
        File::put($this->fakeBase.'/storage/app/public/uploaded.txt', 'user upload');

        $gone = $this->fakeBase.'/vanished-root';
        File::ensureDirectoryExists($gone);
        if (! @symlink($gone, $this->fakeBase.'/public/storage')) {
            $this->markTestSkipped('이 환경에서 symlink 생성 불가');
        }
        File::deleteDirectory($gone);

        StorageLinkHelper::ensurePublicStorageLink();

        $this->assertFileExists(
            $this->fakeBase.'/public/storage/uploaded.txt',
            '끊어진 링크는 no-op 대상이 아니다 — 그대로 두면 공개 자산이 전부 404 다',
        );
    }
}
