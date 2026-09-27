<?php

namespace Tests\Feature\Analytics;

use App\Models\Menu;
use App\Models\NotificationDefinition;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\CoreAdminMenuSeeder;
use Database\Seeders\NotificationDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 권한 3·메뉴 14·알림 정의 5 시딩.
 *
 * config 에 적어 둔 정의가 시더를 거쳐 실제 행이 되는지 본다. 정의만 있고 시더가 그
 * 형식을 모르면(중첩 children 등) 설치 직후 관리자 메뉴에 방문자 통계가 없다.
 */
class PermissionsMenusSeedTest extends TestCase
{
    use RefreshDatabase;

    /** 자식 메뉴 slug 13개 (계획서 §4.2) */
    private const CHILD_SLUGS = [
        'admin-analytics-overview', 'admin-analytics-pages', 'admin-analytics-referrers',
        'admin-analytics-environment', 'admin-analytics-regions', 'admin-analytics-patterns',
        'admin-analytics-actions', 'admin-analytics-realtime', 'admin-analytics-visitors',
        'admin-analytics-bots', 'admin-analytics-diagnostics', 'admin-analytics-privacy',
        'admin-analytics-settings',
    ];

    /**
     * 권한 3건이 시딩되고 admin 역할(all_leaf)이 전부 가진다.
     *
     * @scenario visitor=admin
     *
     * @effects permissions_menus_and_definitions_are_seeded
     *
     * @return void 없음
     */
    public function test_seeds_three_permissions_and_grants_them_to_admin(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $identifiers = ['core.analytics.read', 'core.analytics.update', 'core.analytics.privacy'];

        $this->assertSame(3, Permission::whereIn('identifier', $identifiers)->count());
        $this->assertSame(1, Permission::where('identifier', 'core.analytics')->count(), '카테고리 행');

        $admin = Role::where('identifier', 'admin')->firstOrFail();
        $this->assertSame(3, $admin->permissions()->whereIn('identifier', $identifiers)->count(), 'all_leaf 역할은 새 리프 권한을 자동으로 받는다');
    }

    /**
     * 메뉴 14건(부모 1 + 자식 13)이 slug·부모 연결·순서대로 시딩된다.
     *
     * @scenario visitor=admin
     *
     * @effects permissions_menus_and_definitions_are_seeded
     *
     * @return void 없음
     */
    public function test_seeds_fourteen_menus_with_parent_links(): void
    {
        $this->seed(CoreAdminMenuSeeder::class);

        $parent = Menu::where('slug', 'admin-analytics')->firstOrFail();

        $this->assertNull($parent->parent_id);
        $this->assertSame(2, (int) $parent->order, '대시보드 다음 자리');
        $this->assertSame('/admin/analytics', $parent->url);

        $children = Menu::whereIn('slug', self::CHILD_SLUGS)->get();

        $this->assertCount(13, $children);

        foreach ($children as $child) {
            $this->assertSame($parent->id, $child->parent_id, $child->slug.' 이 부모에 연결되지 않았다');
        }

        $this->assertSame(range(1, 13), $children->sortBy('order')->pluck('order')->map(fn ($o) => (int) $o)->values()->all());
        $this->assertSame(1, Menu::where('slug', 'admin-analytics-overview')->value('order') + 0);
        $this->assertSame('/admin/analytics/privacy-requests', Menu::where('slug', 'admin-analytics-privacy')->value('url'));

        // 밀린 기존 메뉴와 겹치지 않는다 — 같은 order 가 둘이면 표시 순서가 미정이다.
        $topOrders = Menu::whereNull('parent_id')->pluck('order')->map(fn ($o) => (int) $o)->all();
        $this->assertSame(count($topOrders), count(array_unique($topOrders)), '최상위 메뉴 order 가 겹친다');
    }

    /**
     * 알림 정의 5건이 채널·훅과 함께 시딩된다.
     *
     * @scenario visitor=admin
     *
     * @effects permissions_menus_and_definitions_are_seeded
     *
     * @return void 없음
     */
    public function test_seeds_five_notification_definitions(): void
    {
        $this->seed(NotificationDefinitionSeeder::class);

        $expected = [
            'analytics_archive_failed' => ['mail', 'database'],
            'analytics_ledger_limit_exceeded' => ['mail', 'database'],
            'analytics_visit_limit_exceeded' => ['database'],
            'analytics_weekly_report' => ['mail'],
            'analytics_monthly_report' => ['mail'],
        ];

        foreach ($expected as $type => $channels) {
            $definition = NotificationDefinition::where('type', $type)->first();

            $this->assertNotNull($definition, $type.' 정의가 없다');

            $actualChannels = is_array($definition->channels) ? $definition->channels : (array) json_decode((string) $definition->channels, true);
            $actualChannels = array_is_list($actualChannels) ? $actualChannels : array_keys($actualChannels);

            $this->assertEqualsCanonicalizing($channels, $actualChannels, $type.' 채널');
        }

        $this->assertSame(5, NotificationDefinition::where('type', 'like', 'analytics_%')->count());
    }
}
