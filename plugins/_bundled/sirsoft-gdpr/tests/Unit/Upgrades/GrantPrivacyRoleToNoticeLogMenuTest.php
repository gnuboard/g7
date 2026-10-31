<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Upgrades\Data\Ext\Plugins\SirsoftGdpr\V1_1_0\Migrations\GrantPrivacyRoleToNoticeLogMenu;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// audit:allow test-extension-base-class reason: 메뉴·역할 행(코어 테이블)만 쓰는 업그레이드 스텝 테스트라 트랜잭션 격리로 충분하다 — PluginTestCase 의 RefreshDatabase(migrate:fresh)는 로컬 MySQL OOM 규율(mem:migrate-fresh-ddl-crashes-local-mysql)에 걸린다

/**
 * 1.1.0 업그레이드 스텝 — 「개인정보 통지 이력」 메뉴에 개인정보 담당 역할 부여 (PU-4)
 *
 * 개인정보 담당 역할의 메뉴 권한은 활성화(activate) 때만 붙는데 업데이트 경로는 activate 를
 * 부르지 않는다. 그래서 1.0.x 에서 올린 사이트는 새 메뉴가 개인정보 담당자에게 보이지 않았다.
 *
 * 검사 축: 붙인다 · 하위 메뉴까지 · 운영자가 뗀 메뉴는 두기 · 재실행 멱등 · 역할·메뉴 없으면 skip.
 * 스텝을 비우거나 user_overrides 판정을 빼면 각각 red 가 된다.
 *
 * 시나리오 SSoT: tests/scenarios/upgrade-grant-privacy-role-notice-log-menu.yaml
 */
class GrantPrivacyRoleToNoticeLogMenuTest extends TestCase
{
    use DatabaseTransactions;

    /** @var string 개인정보 담당 역할 식별자 */
    private const ROLE = 'sirsoft-gdpr.privacy';

    /** @var string 통지 이력 메뉴 슬러그 */
    private const SLUG = 'sirsoft-gdpr-notice-log';

    /**
     * 스텝 파일을 불러오고 통지 이력 메뉴를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // DataMigration 은 AbstractUpgradeStep 이 동적으로 require 하므로 일반 autoload 대상이 아니다.
        require_once base_path('plugins/_bundled/sirsoft-gdpr/upgrades/data/1.1.0/migrations/06_GrantPrivacyRoleToNoticeLogMenu.php');

        DB::table('menus')->where('slug', 'like', self::SLUG.'%')->delete();
    }

    /**
     * 통지 이력 메뉴와 그 하위 메뉴에 개인정보 담당 역할의 읽기 권한을 붙인다.
     *
     * @scenario menu_state=no_privacy_role
     *
     * @effects privacy_role_granted_on_notice_log_menu_tree
     *
     * @return void 반환값 없음
     */
    public function test_통지_이력_메뉴_트리에_개인정보_담당_역할을_붙인다(): void
    {
        $roleId = $this->privacyRole();
        $root = $this->menu(self::SLUG);
        $child = $this->menu(self::SLUG.'-child', $root);

        $this->runStep();

        $this->assertTrue($this->granted($roleId, $root), '통지 이력 메뉴에 역할이 붙지 않았다');
        $this->assertTrue($this->granted($roleId, $child), '하위 메뉴에 역할이 붙지 않았다');
    }

    /**
     * 운영자가 뗀 메뉴(user_overrides 에 역할 식별자)에는 붙이지 않는다.
     *
     * @scenario menu_state=operator_removed_role
     *
     * @effects privacy_role_not_granted_after_operator_removal
     *
     * @return void 반환값 없음
     */
    public function test_운영자가_뗀_메뉴에는_붙이지_않는다(): void
    {
        $roleId = $this->privacyRole();
        $root = $this->menu(self::SLUG, null, [self::ROLE]);

        $this->runStep();

        $this->assertFalse($this->granted($roleId, $root), '운영자가 뗀 역할을 다시 붙였다');
    }

    /**
     * 두 번 실행해도 행이 하나다(멱등).
     *
     * @scenario menu_state=already_granted
     *
     * @effects privacy_role_grant_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_두_번_실행해도_한_행이다(): void
    {
        $roleId = $this->privacyRole();
        $root = $this->menu(self::SLUG);

        $this->runStep();
        $this->runStep();

        $this->assertSame(1, DB::table('role_menus')->where('role_id', $roleId)->where('menu_id', $root)->count());
    }

    /**
     * 역할이나 메뉴가 없으면 아무것도 하지 않는다.
     *
     * @scenario menu_state=role_or_menu_missing
     *
     * @effects privacy_role_grant_skips_without_role_or_menu
     *
     * @return void 반환값 없음
     */
    public function test_역할이나_메뉴가_없으면_건너뛴다(): void
    {
        DB::table('roles')->where('identifier', self::ROLE)->delete();
        $root = $this->menu(self::SLUG);

        $this->runStep();
        $this->assertSame(0, DB::table('role_menus')->where('menu_id', $root)->count());

        DB::table('menus')->where('id', $root)->delete();
        $this->privacyRole();

        $this->runStep();
        $this->assertSame(0, DB::table('menus')->where('slug', self::SLUG)->count());
    }

    /**
     * 스텝을 실행합니다. (공개판 1.0.4 에서 올리는 경로)
     *
     * @return void 반환값 없음
     */
    private function runStep(): void
    {
        (new GrantPrivacyRoleToNoticeLogMenu)->run(new UpgradeContext(
            fromVersion: '1.0.4',
            toVersion: '1.1.0',
            currentStep: '1.1.0',
        ));
    }

    /**
     * 개인정보 담당 역할을 확보하고 ID 를 돌려줍니다.
     *
     * @return int 역할 ID
     */
    private function privacyRole(): int
    {
        $existing = DB::table('roles')->where('identifier', self::ROLE)->value('id');
        if ($existing !== null) {
            DB::table('role_menus')->where('role_id', $existing)->delete();

            return (int) $existing;
        }

        return (int) DB::table('roles')->insertGetId([
            'identifier' => self::ROLE,
            'name' => json_encode(['ko' => '개인정보 담당', 'en' => 'Privacy']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 플러그인 소속 메뉴 행을 하나 만들고 ID 를 돌려줍니다.
     *
     * @param  string  $slug  메뉴 슬러그
     * @param  int|null  $parentId  상위 메뉴 ID
     * @param  array<int, string>  $userOverrides  운영자 수정 기록
     * @return int 메뉴 ID
     */
    private function menu(string $slug, ?int $parentId = null, array $userOverrides = []): int
    {
        return (int) DB::table('menus')->insertGetId([
            'name' => json_encode(['ko' => '개인정보 통지 이력', 'en' => 'Privacy Notice Log']),
            'slug' => $slug,
            'url' => '/admin/plugins/sirsoft-gdpr/notice-log',
            'parent_id' => $parentId,
            'order' => 51,
            'is_active' => true,
            'extension_type' => 'plugin',
            'extension_identifier' => 'sirsoft-gdpr',
            'user_overrides' => $userOverrides === [] ? null : json_encode($userOverrides),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 역할에 메뉴 읽기 권한이 붙어 있는지 확인합니다.
     *
     * @param  int  $roleId  역할 ID
     * @param  int  $menuId  메뉴 ID
     * @return bool 붙어 있으면 true
     */
    private function granted(int $roleId, int $menuId): bool
    {
        return DB::table('role_menus')
            ->where('role_id', $roleId)
            ->where('menu_id', $menuId)
            ->where('permission_type', 'read')
            ->exists();
    }
}
