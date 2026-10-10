<?php

namespace Tests\Feature\Upgrade;

use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_1_0\Migrations\ShiftCoreMenuOrderForAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 7.1.0 업그레이드 — 방문자 통계 자리만큼 코어 메뉴 순서를 민다.
 *
 * 기존 설치본은 대부분 7.0.x 에서 이 버전을 받는다. 순서를 밀지 않으면 방문자 통계와 환경설정이
 * 같은 순위로 겹쳐 표시 순서가 정해지지 않고, 운영자가 직접 바꾼 순서를 덮으면 운영자의 결정이
 * 사라진다.
 */
class ShiftCoreMenuOrderForAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 데이터 마이그레이션 클래스를 불러옵니다(업그레이드 스텝이 동적으로 require 하는 파일).
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once base_path('upgrades/data/7.1.0/migrations/01_ShiftCoreMenuOrderForAnalytics.php');
        DB::table('menus')->delete();
    }

    /**
     * 코어 최상위 메뉴 행을 넣습니다.
     *
     * @param  string  $slug  슬러그
     * @param  int  $order  현재 순서
     * @param  array<int|string, string>|null  $overrides  운영자가 직접 정한 값(user_overrides)
     * @return void 없음
     */
    private function menu(string $slug, int $order, ?array $overrides = null): void
    {
        DB::table('menus')->insert([
            'name' => json_encode(['ko' => $slug, 'en' => $slug]),
            'slug' => $slug,
            'order' => $order,
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'user_overrides' => $overrides === null ? null : json_encode($overrides),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 메뉴 순서를 슬러그 기준으로 읽습니다.
     *
     * @return array<string, int> 슬러그 → 순서
     */
    private function orders(): array
    {
        return DB::table('menus')->pluck('order', 'slug')->map(fn ($order): int => (int) $order)->all();
    }

    /**
     * 마이그레이션을 한 번 돌립니다.
     *
     * @return void 없음
     */
    private function run7_1_0(): void
    {
        (new ShiftCoreMenuOrderForAnalytics)->run(new UpgradeContext('7.0.11', '7.1.0', '7.1.0'));
    }

    /**
     * 7.0.x 순서가 밀리고, 운영자가 지정한 순서는 남고, 두 번 돌려도 같다.
     *
     * 운영자가 「환경설정」을 3 으로 지정해 두면 재배치 뒤 코어 배정값(3)과 같아져 **동률**이 된다 —
     * 그 경계에서도 운영자 행은 건드리지 않는다(값이 같으니 결과가 우연히 맞는 것이 아니라, 보존
     * 판정이 먼저 돈다는 것을 로그 대신 다른 메뉴로 확인한다).
     *
     * @return void 없음
     *
     * @scenario target=menus, row_age=within
     *
     * @effects core_menus_shift_for_analytics_on_upgrade, operator_menu_order_is_preserved_on_upgrade, menu_order_upgrade_is_idempotent
     */
    public function test_shift_preserves_operator_order_and_is_idempotent(): void
    {
        $this->menu('admin-dashboard', 1);
        $this->menu('admin-analytics', 99);
        $this->menu('admin-settings', 2);
        $this->menu('admin-notification-logs', 3);
        $this->menu('admin-member-notifications', 3);
        $this->menu('admin-activity-logs', 7, ['order']);
        $this->menu('admin-users', 20, ['name', 'order']);

        $this->run7_1_0();
        $after = $this->orders();

        $this->assertSame(2, $after['admin-analytics']);
        $this->assertSame(3, $after['admin-settings']);
        $this->assertSame(4, $after['admin-notification-logs']);
        $this->assertSame(5, $after['admin-member-notifications'], '종전 3.5 → int 3 동률이 갈린다');
        $this->assertSame(7, $after['admin-activity-logs'], '운영자 지정값이 코어 배정값과 같아도 그대로');
        $this->assertSame(20, $after['admin-users'], '운영자 지정 순서는 보존');

        $this->run7_1_0();
        $this->assertSame($after, $this->orders(), '두 번째 실행은 아무것도 바꾸지 않는다');
    }

    /**
     * 확장·운영자가 만든 메뉴와 하위 메뉴는 대상이 아니고, 대상이 없으면 건너뛴다.
     *
     * @return void 없음
     *
     * @scenario target=menus, row_age=beyond
     *
     * @effects menu_order_upgrade_skips_when_nothing_matches
     */
    public function test_non_core_menus_are_untouched_and_empty_target_is_skipped(): void
    {
        DB::table('menus')->insert([
            'name' => json_encode(['ko' => '게시판']),
            'slug' => 'admin-settings-board',
            'order' => 2,
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-board',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->run7_1_0();

        $this->assertSame(['admin-settings-board' => 2], $this->orders());
    }
}
