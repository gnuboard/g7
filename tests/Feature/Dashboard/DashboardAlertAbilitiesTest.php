<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ExtensionOwnerType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 대시보드 경고의 [복구]·[닫기] 권한 (G23, 제품 결정 2026-10-09).
 *
 * 경고는 시스템 알림 권한(core.dashboard.alerts)으로 보이지만, 복구·닫기는 그 경고가 실어 온
 * 엔드포인트의 라우트 권한(확장 복구 = core.plugins.activate, 정적 캐시 재발행 = core.settings.update)
 * 이 필요하다. 응답이 경고마다 abilities 를 실어 화면이 버튼을 잠그고 안내를 띄운다.
 */
class DashboardAlertAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트용 경고 공급 콜백
     *
     * @var callable|null
     */
    private $supplier = null;

    /**
     * 확장 복구 경고와 정적 캐시 재발행 경고를 공급하는 필터를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = static fn (array $alerts): array => array_merge($alerts, [
            [
                'id' => 'recover_plugins_test-plugin',
                'type' => 'warning',
                'extension_type' => 'plugin',
                'identifier' => 'test-plugin',
                'recover_endpoint' => '/api/admin/extensions/plugin/test-plugin/recover',
            ],
            [
                'id' => 'static_publish_failure',
                'type' => 'warning',
                'recover_endpoint' => '/api/admin/settings/static-cache/republish',
            ],
        ]);
        HookManager::addFilter('core.dashboard.alerts', $this->supplier, 99);
    }

    /**
     * 등록한 필터를 걷어냅니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::removeFilter('core.dashboard.alerts', $this->supplier);
        parent::tearDown();
    }

    /**
     * 주어진 권한만 가진 운영자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @return User 운영자
     */
    private function operatorWith(array $permissions): User
    {
        $ids = [];
        foreach ($permissions as $identifier) {
            $ids[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'description' => ['ko' => $identifier, 'en' => $identifier],
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }
        $role = Role::create(['identifier' => 'dash_alert_'.uniqid(), 'name' => ['ko' => 'r', 'en' => 'r'], 'is_active' => true]);
        $role->permissions()->sync($ids);
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * 경고별 abilities 를 id 로 모읍니다.
     *
     * @param  User  $user  요청 사용자
     * @return array<string, array<string, bool>> 경고 id => abilities
     */
    private function alertAbilities(User $user): array
    {
        $alerts = $this->actingAs($user)->getJson('/api/admin/dashboard/alerts')->assertOk()->json('data');

        return collect($alerts)->filter(fn ($a) => in_array($a['id'] ?? null, ['recover_plugins_test-plugin', 'static_publish_failure'], true))
            ->mapWithKeys(fn ($a) => [$a['id'] => $a['abilities'] ?? null])->all();
    }

    /**
     * 경고 조회 권한만 있으면 복구·닫기 모두 false 다
     *
     * @scenario endpoint=admin.dashboard.alerts,permission=alerts_only
     *
     * @effects dashboard_alert_actions_locked_without_target_permission
     *
     * @return void 반환값 없음
     */
    public function test_alerts_only_operator_cannot_recover_or_dismiss(): void
    {
        $abilities = $this->alertAbilities($this->operatorWith(['core.dashboard.read', 'core.dashboard.alerts']));

        $this->assertSame(['can_recover' => false, 'can_dismiss' => false], $abilities['recover_plugins_test-plugin']);
        $this->assertSame(['can_recover' => false, 'can_dismiss' => false], $abilities['static_publish_failure']);
    }

    /**
     * 각 경고는 자기 엔드포인트의 라우트 권한으로 판정된다 — 플러그인 활성화 권한은 확장 경고만, 설정 수정 권한은 정적 캐시 경고만 연다
     *
     * @scenario endpoint=admin.dashboard.alerts,permission=plugins_activate|settings_update
     *
     * @effects dashboard_alert_actions_follow_endpoint_route_permission
     *
     * @return void 반환값 없음
     */
    public function test_each_alert_follows_its_endpoint_permission(): void
    {
        $plugin = $this->alertAbilities($this->operatorWith(['core.dashboard.read', 'core.dashboard.alerts', 'core.plugins.activate']));
        $this->assertSame(['can_recover' => true, 'can_dismiss' => true], $plugin['recover_plugins_test-plugin']);
        $this->assertSame(['can_recover' => false, 'can_dismiss' => false], $plugin['static_publish_failure']);

        $settings = $this->alertAbilities($this->operatorWith(['core.dashboard.read', 'core.dashboard.alerts', 'core.settings.update']));
        $this->assertSame(['can_recover' => false, 'can_dismiss' => false], $settings['recover_plugins_test-plugin']);
        $this->assertSame(['can_recover' => true, 'can_dismiss' => false], $settings['static_publish_failure']);
    }

    /**
     * 경고 API 는 시스템 알림 권한(core.dashboard.alerts)을 요구한다 — 화면 블록 게이트와 같은 권한 (backend#8)
     *
     * @scenario endpoint=admin.dashboard.alerts,permission=dashboard_read_only
     *
     * @effects dashboard_alerts_route_requires_alerts_permission
     *
     * @return void 반환값 없음
     */
    public function test_alerts_route_requires_alerts_permission(): void
    {
        $this->actingAs($this->operatorWith(['core.dashboard.read']))
            ->getJson('/api/admin/dashboard/alerts')
            ->assertForbidden();
    }
}
