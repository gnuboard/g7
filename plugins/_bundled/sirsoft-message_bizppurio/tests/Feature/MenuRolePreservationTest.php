<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature;

use App\Enums\ExtensionOwnerType;
use App\Models\Menu;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Plugins\Sirsoft\MessageBizppurio\Plugin;
use Tests\Concerns\MenuRoleFixtures;
use Tests\TestCase;

// audit:allow test-extension-base-class reason: 메뉴 행(코어 테이블)만 쓰는 생명주기 테스트라 트랜잭션 격리로 충분하다 — PluginTestCase 의 RefreshDatabase(migrate:fresh)는 로컬 MySQL OOM 규율(mem:migrate-fresh-ddl-crashes-local-mysql)에 걸린다

/**
 * 비즈뿌리오 플러그인 생명주기와 관리자 메뉴
 *
 * 이 플러그인은 관리자 메뉴를 만들지 않는다(진입은 코어 설정 페이지). 옛 판본 잔재 청소를
 * 비활성화에서 활성화로 옮겨, 비활성화는 메뉴 행을 지우지 않는 번들 플러그인 공통 관례를 따른다.
 *
 * 시나리오 SSoT: tests/scenarios/plugin-menu-role-preservation.yaml
 */
class MenuRolePreservationTest extends TestCase
{
    use DatabaseTransactions;
    use MenuRoleFixtures;

    /**
     * 옛 판본이 심은 메뉴 잔재를 만듭니다.
     *
     * @return Menu 잔재 메뉴
     */
    private function staleMenu(): Menu
    {
        return $this->menu([
            'slug' => 'sirsoft-message_bizppurio-legacy',
            'extension_type' => ExtensionOwnerType::Plugin,
            'extension_identifier' => 'sirsoft-message_bizppurio',
        ]);
    }

    /**
     * 이 플러그인 소속 메뉴 수.
     *
     * @return int 메뉴 수
     */
    private function menuCount(): int
    {
        return Menu::where('extension_type', ExtensionOwnerType::Plugin)->where('extension_identifier', 'sirsoft-message_bizppurio')->count();
    }

    /**
     * 비활성화는 메뉴 행을 지우지 않고, 활성화가 잔재를 회수한다.
     *
     * @scenario plugin=bizppurio, transition=deactivate_activate
     *
     * @effects stale_menus_cleaned_on_activate
     *
     * @return void 반환값 없음
     */
    public function test_deactivate_keeps_rows_and_activate_cleans_stale(): void
    {
        $this->staleMenu();

        (new Plugin)->deactivate();
        $this->assertSame(1, $this->menuCount());

        (new Plugin)->activate();
        $this->assertSame(0, $this->menuCount());
    }

    /**
     * 플러그인의 uninstall() 은 이 플러그인 소속 메뉴를 지우지 않는다 — 데이터 보존 제거에서도 불리므로, 메뉴는 「데이터도 함께
     * 삭제」 제거에서 코어(PluginManager)가 지운다. 코어 쪽 계약은 tests/Feature/Extension/PluginUninstallKeepsMenusTest.php.
     *
     * @scenario plugin=bizppurio, transition=uninstall
     *
     * @effects plugin_uninstall_hook_keeps_menus
     *
     * @return void 반환값 없음
     */
    public function test_uninstall_hook_keeps_menus_for_core(): void
    {
        $this->staleMenu();

        (new Plugin)->uninstall();

        $this->assertSame(1, $this->menuCount(), '데이터 보존 제거에서도 불리는 훅이라 메뉴를 남긴다');
    }
}
