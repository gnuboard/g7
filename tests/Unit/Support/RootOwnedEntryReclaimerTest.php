<?php

namespace Tests\Unit\Support;

use App\Support\RootOwnedEntryReclaimer;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * root 로 돈 업데이트 계열 명령이 설치 트리에 새로 만든 root 소유 폴더·파일을 상위 폴더 소유·그룹 권한으로 되돌린다.
 *
 * 실측(7.1.6 → 7.2.0, root 로 실행한 코어 업데이트): 업데이트가 처음 만든 경로는 업데이트 전 소유권 스냅샷에 없어서
 * 복원 대상에서 빠졌다 —
 *  - `storage/app/templates` root:root 700 (템플릿 환경설정 저장소 — 디스크를 여는 순간 PRIVATE 0700 으로 생성) →
 *    웹서버가 템플릿 설정을 읽지도 저장하지도 못함
 *  - `storage/app/core-update/`(출하 목록·deploy 원장) root:root 755 → 관리자 화면 코어 업데이트가 진행 상태를 못 씀
 *  - `storage/app/extension-carried-class-files.json.lock` root:root 664 → 웹 확장 갱신이 잠금을 못 엶
 * 경로마다 목록에 더하는 방식은 다음에 새 경로가 생기면 재발하므로, 업데이트 계열 명령이 root 로 끝날 때 설치 트리의
 * root 소유 항목을 일반 규칙으로 되돌린다.
 */
class RootOwnedEntryReclaimerTest extends TestCase
{
    private string $root;

    /** @var array<string, array{uid: int, gid: int, mode: int}> 상대 경로 → 가짜 stat */
    private array $stats = [];

    /** @var array<string, array{uid: int, gid: int, mode: int, link: bool}> 상대 경로 → 적용 기록 */
    private array $applied = [];

    /**
     * 시험용 임시 트리와 대체 함수를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/root-reclaim-'.uniqid());
        File::ensureDirectoryExists($this->root);

        RootOwnedEntryReclaimer::resetForTesting();
        RootOwnedEntryReclaimer::useStatReaderForTesting(function (string $path): ?array {
            $relative = $this->relative($path);

            return $this->stats[$relative] ?? (is_dir($path)
                ? ['uid' => 1000, 'gid' => 33, 'mode' => 040775]
                : ['uid' => 1000, 'gid' => 33, 'mode' => 0100664]);
        });
        RootOwnedEntryReclaimer::useApplierForTesting(function (string $path, int $uid, int $gid, int $mode, bool $isLink): bool {
            $this->applied[$this->relative($path)] = ['uid' => $uid, 'gid' => $gid, 'mode' => $mode, 'link' => $isLink];

            return true;
        });
    }

    /**
     * 임시 트리와 대체 함수를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        RootOwnedEntryReclaimer::resetForTesting();
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 검수 서버에서 실측한 세 경로가 상위 폴더(사이트 계정:웹서버 그룹 0775)의 소유·그룹 권한으로 돌아오고, 웹서버 소유 비공개 폴더는 그대로다.
     *
     * @scenario case=root_update_left_new_paths
     *
     * @effects root_created_paths_inherit_parent_owner_and_group_perms
     *
     * @return void 반환값 없음
     */
    public function test_root_created_paths_inherit_parent_owner_and_group_permissions(): void
    {
        $this->makeTree([
            'storage/app/templates/sirsoft-basic/settings/branding.json',
            'storage/app/core-update/bundled-shipped.json',
            'storage/app/extension-carried-class-files.json.lock',
            'storage/app/settings/core.json',
        ]);

        $this->stats = [
            '.' => ['uid' => 1000, 'gid' => 1000, 'mode' => 040775],
            'storage/app/templates' => ['uid' => 0, 'gid' => 0, 'mode' => 040700],
            'storage/app/templates/sirsoft-basic' => ['uid' => 0, 'gid' => 0, 'mode' => 040700],
            'storage/app/templates/sirsoft-basic/settings' => ['uid' => 0, 'gid' => 0, 'mode' => 040700],
            'storage/app/templates/sirsoft-basic/settings/branding.json' => ['uid' => 0, 'gid' => 0, 'mode' => 0100600],
            'storage/app/core-update' => ['uid' => 0, 'gid' => 0, 'mode' => 040755],
            'storage/app/core-update/bundled-shipped.json' => ['uid' => 0, 'gid' => 0, 'mode' => 0100644],
            'storage/app/extension-carried-class-files.json.lock' => ['uid' => 0, 'gid' => 0, 'mode' => 0100664],
            // 웹서버가 만든 비공개 설정 폴더 — root 소유가 아니므로 손대지 않는다
            'storage/app/settings' => ['uid' => 33, 'gid' => 33, 'mode' => 040700],
            'storage/app/settings/core.json' => ['uid' => 33, 'gid' => 33, 'mode' => 0100600],
        ];

        RootOwnedEntryReclaimer::fakeRootProcessForTesting(true);
        $report = RootOwnedEntryReclaimer::reclaim($this->root);

        $this->assertSame(['uid' => 1000, 'gid' => 33, 'mode' => 0775, 'link' => false], $this->applied['storage/app/templates']);
        $this->assertSame(['uid' => 1000, 'gid' => 33, 'mode' => 0775, 'link' => false], $this->applied['storage/app/templates/sirsoft-basic']);
        $this->assertSame(['uid' => 1000, 'gid' => 33, 'mode' => 0775, 'link' => false], $this->applied['storage/app/templates/sirsoft-basic/settings']);
        $this->assertSame(['uid' => 1000, 'gid' => 33, 'mode' => 0664, 'link' => false], $this->applied['storage/app/templates/sirsoft-basic/settings/branding.json']);
        $this->assertSame(['uid' => 1000, 'gid' => 33, 'mode' => 0775, 'link' => false], $this->applied['storage/app/core-update']);
        $this->assertSame(['uid' => 1000, 'gid' => 33, 'mode' => 0664, 'link' => false], $this->applied['storage/app/core-update/bundled-shipped.json']);
        $this->assertSame(['uid' => 1000, 'gid' => 33, 'mode' => 0664, 'link' => false], $this->applied['storage/app/extension-carried-class-files.json.lock']);

        $this->assertArrayNotHasKey('storage/app/settings', $this->applied);
        $this->assertArrayNotHasKey('storage/app/settings/core.json', $this->applied);
        $this->assertSame(7, $report['changed']);
        $this->assertSame(0, $report['failed']);
    }

    /**
     * root 가 아닌 프로세스(웹 업데이트·CLI 불가 호스팅·일반 계정 CLI)에서는 아무것도 하지 않는다.
     *
     * @scenario case=non_root_process
     *
     * @effects non_root_process_is_noop
     *
     * @return void 반환값 없음
     */
    public function test_non_root_process_is_noop(): void
    {
        $this->makeTree(['storage/app/core-update/bundled-shipped.json']);
        $this->stats = ['storage/app/core-update' => ['uid' => 0, 'gid' => 0, 'mode' => 040755]];

        RootOwnedEntryReclaimer::fakeRootProcessForTesting(false);
        $report = RootOwnedEntryReclaimer::reclaim($this->root);

        $this->assertSame([], $this->applied);
        $this->assertSame(0, $report['changed']);
    }

    /**
     * 설치 루트 자체가 root 소유인 서버(모든 파일을 root 로 운영)는 그 구성을 존중해 손대지 않는다.
     *
     * @scenario case=root_owned_install
     *
     * @effects root_owned_install_is_left_alone
     *
     * @return void 반환값 없음
     */
    public function test_install_owned_by_root_is_left_alone(): void
    {
        $this->makeTree(['storage/app/core-update/bundled-shipped.json']);
        $this->stats = [
            '.' => ['uid' => 0, 'gid' => 0, 'mode' => 040755],
            'storage/app/core-update' => ['uid' => 0, 'gid' => 0, 'mode' => 040755],
        ];

        RootOwnedEntryReclaimer::fakeRootProcessForTesting(true);
        RootOwnedEntryReclaimer::reclaim($this->root);

        $this->assertSame([], $this->applied);
    }

    /**
     * `.git`·`node_modules` 는 훑지 않는다.
     *
     * @scenario case=excluded_directories
     *
     * @effects excluded_directories_not_walked
     *
     * @return void 반환값 없음
     */
    public function test_excluded_directories_are_not_walked(): void
    {
        $this->makeTree(['node_modules/pkg/index.js', '.git/HEAD']);
        $this->stats = [
            'node_modules/pkg' => ['uid' => 0, 'gid' => 0, 'mode' => 040755],
            'node_modules/pkg/index.js' => ['uid' => 0, 'gid' => 0, 'mode' => 0100644],
            '.git/HEAD' => ['uid' => 0, 'gid' => 0, 'mode' => 0100644],
        ];

        RootOwnedEntryReclaimer::fakeRootProcessForTesting(true);
        RootOwnedEntryReclaimer::reclaim($this->root);

        $this->assertSame([], $this->applied);
    }

    /**
     * 정리 대상 명령 판정 — 업데이트·설치 계열만, 일괄 갱신의 항목 프로세스는 제외(일괄 프로세스가 끝에 한 번 한다).
     *
     * @scenario case=command_selection
     *
     * @effects reclaim_targets_update_family_commands_only
     *
     * @return void 반환값 없음
     */
    public function test_reclaim_targets_update_family_commands_only(): void
    {
        foreach ([
            'core:update', 'core:execute-upgrade-steps', 'core:execute-bundled-updates', 'core:resync-declarations',
            'module:update', 'plugin:install', 'template:update', 'language-pack:update', 'extension:update-autoload',
        ] as $command) {
            $this->assertTrue(RootOwnedEntryReclaimer::shouldReclaimAfter($command, $this->input()), $command);
        }

        foreach (['schedule:run', 'queue:work', 'module:list', 'core:check-updates', 'cache:clear', null] as $command) {
            $this->assertFalse(RootOwnedEntryReclaimer::shouldReclaimAfter($command, $this->input()), (string) $command);
        }

        $this->assertFalse(RootOwnedEntryReclaimer::shouldReclaimAfter('core:execute-bundled-updates', $this->input(['--item-process' => true])));
    }

    /**
     * root 로 시작한 업데이트 계열 명령이 끝나면(앱 종료) 설치 트리를 정리한다 — 코어가 이벤트 리스너를 건다.
     *
     * 7.1.x → 7.2.0 은 부모가 옛 코드라 부모 쪽 정리는 돌지 않는다. 새 코드로 뜨는 자식(업그레이드 스텝·번들 일괄 갱신)이
     * 각자 끝날 때 정리하므로 그 구간에 생긴 경로(위 세 경로)가 바로잡힌다.
     *
     * @scenario case=command_end
     *
     * @effects update_command_end_reclaims_install_tree
     *
     * @return void 반환값 없음
     */
    public function test_update_command_end_reclaims_install_tree(): void
    {
        $this->makeTree(['storage/app/core-update/bundled-shipped.json']);
        $this->stats = [
            'storage/app/core-update' => ['uid' => 0, 'gid' => 0, 'mode' => 040755],
            'storage/app/core-update/bundled-shipped.json' => ['uid' => 0, 'gid' => 0, 'mode' => 0100644],
        ];

        RootOwnedEntryReclaimer::fakeRootProcessForTesting(true);
        RootOwnedEntryReclaimer::useRootForTesting($this->root);

        event(new CommandStarting('core:execute-bundled-updates', $this->input(), new NullOutput));
        $this->assertSame([], $this->applied, '명령이 끝나기 전에는 정리하지 않는다');

        $this->app->terminate();

        $this->assertArrayHasKey('storage/app/core-update', $this->applied);
        $this->assertArrayHasKey('storage/app/core-update/bundled-shipped.json', $this->applied);
    }

    /**
     * 빈 파일 트리를 만듭니다.
     *
     * @param  array<int, string>  $files  상대 경로 목록
     * @return void 반환값 없음
     */
    private function makeTree(array $files): void
    {
        foreach ($files as $file) {
            File::ensureDirectoryExists(dirname($this->root.'/'.$file));
            File::put($this->root.'/'.$file, '');
        }
    }

    /**
     * 절대 경로를 시험 루트 기준 상대 경로로 바꿉니다.
     *
     * @param  string  $path  절대 경로
     * @return string 상대 경로 (루트 자신은 `.`)
     */
    private function relative(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', $this->root);

        return $normalized === $root ? '.' : ltrim(substr($normalized, strlen($root)), '/');
    }

    /**
     * `--item-process` 옵션을 아는 입력을 만듭니다.
     *
     * @param  array<string, mixed>  $parameters  입력 값
     * @return ArrayInput 입력
     */
    private function input(array $parameters = []): ArrayInput
    {
        return new ArrayInput($parameters, new InputDefinition([
            new InputOption('item-process', null, InputOption::VALUE_NONE),
        ]));
    }
}
