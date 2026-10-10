<?php

namespace Plugins\Sirsoft\Totp\Tests\Feature;

use App\Enums\ExtensionOwnerType;
use App\Models\Menu;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Plugins\Sirsoft\Totp\Plugin;
use Tests\Concerns\MenuRoleFixtures;
use Tests\TestCase;

// audit:allow test-extension-base-class reason: 메뉴 행(코어 테이블)만 쓰는 생명주기 테스트라 트랜잭션 격리로 충분하다 — PluginTestCase 의 RefreshDatabase(migrate:fresh)는 로컬 MySQL OOM 규율(mem:migrate-fresh-ddl-crashes-local-mysql)에 걸린다

/**
 * TOTP 플러그인 생명주기와 관리자 메뉴 (#708)
 *
 * 이 플러그인은 관리자 메뉴를 선언하지 않는다. 번들 플러그인 공통 관례에 맞춰 비활성화는 메뉴
 * 행을 지우지 않고, 옛 판본이 심은 잔재는 활성화가, 전체 삭제는 제거가 맡는다.
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
            'slug' => 'sirsoft-totp-security',
            'extension_type' => ExtensionOwnerType::Plugin,
            'extension_identifier' => 'sirsoft-totp',
        ]);
    }

    /**
     * 이 플러그인 소속 메뉴 수.
     *
     * @return int 메뉴 수
     */
    private function menuCount(): int
    {
        return Menu::where('extension_type', ExtensionOwnerType::Plugin)->where('extension_identifier', 'sirsoft-totp')->count();
    }

    /**
     * 비활성화는 메뉴 행을 지우지 않고, 활성화가 선언에서 빠진 잔재를 회수한다.
     *
     * @scenario plugin=totp, transition=deactivate_activate
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
     * 제거는 이 플러그인 소속 메뉴를 모두 지운다.
     *
     * @scenario plugin=totp, transition=uninstall
     *
     * @effects menus_removed_on_uninstall
     *
     * @return void 반환값 없음
     */
    public function test_uninstall_removes_menus(): void
    {
        $this->staleMenu();

        (new Plugin)->uninstall();

        $this->assertSame(0, $this->menuCount());
    }
}
