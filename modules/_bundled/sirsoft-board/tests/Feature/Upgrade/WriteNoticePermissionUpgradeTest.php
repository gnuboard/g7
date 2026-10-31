<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Upgrade;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\UpgradeContext;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Modules\Sirsoft\Board\Upgrades\Upgrade_1_2_0;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * 1.2.0 공지 등록 권한(posts.write-notice) 소급 업그레이드 테스트.
 *
 * 배경: 1.2.0 부터 사용자 글 작성·수정의 공지 지정은 게시판 관리 권한 또는 공지 등록 권한
 * 보유자만 할 수 있다. 게시판별 권한 행은 게시판 저장 때만 만들어지고, 기존 설치본은 기본 권한
 * 설정을 통째로 저장하고 있어 새 키가 병합되지 않는다. 1.2.0 스텝이 둘 다 채운다.
 *
 * 검증 목적:
 * - 기존 게시판마다 권한 행이 생기고 admin·게시판 관리자·스텝 역할이 붙는다
 * - 재실행해도 역할 연결 건수가 그대로다 (멱등, 운영자 조정 보존)
 * - 저장된 기본 권한 설정에 키가 기입되고, 이미 있는 키(운영자 조정)는 건드리지 않는다
 */
#[Group('board')]
#[Group('upgrade')]
class WriteNoticePermissionUpgradeTest extends BoardTestCase
{
    /**
     * 테스트 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'write-notice-upgrade';
    }

    /**
     * 테스트가 만든 기본 권한 설정 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::delete($this->settingsPath());

        parent::tearDown();
    }

    /**
     * 기존 게시판에 권한 행과 기본 역할이 생기고, 재실행해도 건수가 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=upgrade_seeds_existing_boards
     *
     * @effects existing_board_gets_notice_permission_with_default_roles, upgrade_seed_is_idempotent
     */
    #[Test]
    public function existing_board_gets_notice_permission_with_default_roles(): void
    {
        $slug = $this->board->slug;
        $category = Permission::create([
            'identifier' => "sirsoft-board.{$slug}",
            'name' => ['ko' => '게시판', 'en' => 'Board'],
            'type' => 'admin',
        ]);

        foreach (['manager', 'step'] as $kind) {
            Role::create(['identifier' => "sirsoft-board.{$slug}.{$kind}", 'name' => ['ko' => $kind, 'en' => $kind]]);
        }

        $this->runUpgrade();

        $permission = DB::table('permissions')->where('identifier', "sirsoft-board.{$slug}.posts.write-notice")->first();
        $this->assertNotNull($permission, '기존 게시판에 공지 등록 권한 행이 생겨야 합니다.');
        $this->assertSame($category->id, (int) $permission->parent_id);
        $this->assertSame('user', $permission->type);
        $this->assertSame('공지 등록', json_decode($permission->name, true)['ko']);

        $roles = DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('role_permissions.permission_id', $permission->id)
            ->pluck('roles.identifier')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['admin', "sirsoft-board.{$slug}.manager", "sirsoft-board.{$slug}.step"], $roles);

        $this->runUpgrade();

        $this->assertSame(3, DB::table('role_permissions')->where('permission_id', $permission->id)->count(), '재실행해도 역할 연결이 늘면 안 됩니다.');
        $this->assertSame(1, DB::table('permissions')->where('identifier', "sirsoft-board.{$slug}.posts.write-notice")->count());
    }

    /**
     * 저장된 기본 권한 설정에 키가 기준 키 뒤로 기입되고, 운영자가 이미 둔 값은 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=upgrade_backfills_saved_defaults
     *
     * @effects saved_default_permissions_gain_notice_key, operator_adjusted_default_left_untouched
     */
    #[Test]
    public function saved_default_permissions_gain_notice_key(): void
    {
        $this->writeSettings([
            'per_page' => 20,
            'default_board_permissions' => [
                'posts.read' => ['admin', 'user', 'guest'],
                'posts.read-secret' => ['admin'],
                'comments.read' => ['admin', 'user', 'guest'],
            ],
        ]);

        $this->runUpgrade();

        $saved = json_decode(File::get($this->settingsPath()), true);
        $this->assertSame(['admin'], $saved['default_board_permissions']['posts.write-notice']);
        $this->assertSame(
            ['posts.read', 'posts.read-secret', 'posts.write-notice', 'comments.read'],
            array_keys($saved['default_board_permissions']),
            '권한 설정 화면 순서와 맞게 posts.read-secret 바로 뒤에 들어가야 합니다.'
        );
        $this->assertSame(20, $saved['per_page'], '다른 설정은 그대로여야 합니다.');

        // 운영자가 이미 조정한 값은 덮지 않는다
        $saved['default_board_permissions']['posts.write-notice'] = ['admin', 'user'];
        $this->writeSettings($saved);

        $this->runUpgrade();

        $this->assertSame(
            ['admin', 'user'],
            json_decode(File::get($this->settingsPath()), true)['default_board_permissions']['posts.write-notice']
        );
    }

    /**
     * 1.2.0 업그레이드 스텝을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runUpgrade(): void
    {
        (new Upgrade_1_2_0)->run(new UpgradeContext('1.1.2', '1.2.0', '1.2.0', 'extension-upgrade'));
    }

    /**
     * 테스트 저장소의 기본 권한 설정 파일 경로를 반환합니다.
     *
     * @return string 설정 파일 절대 경로
     */
    private function settingsPath(): string
    {
        return ExtensionStoragePath::module('sirsoft-board', 'settings').'/basic_defaults.json';
    }

    /**
     * 기본 권한 설정 파일을 씁니다.
     *
     * @param  array<string, mixed>  $settings  저장할 설정
     * @return void 반환값 없음
     */
    private function writeSettings(array $settings): void
    {
        File::ensureDirectoryExists(dirname($this->settingsPath()));
        File::put($this->settingsPath(), json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
