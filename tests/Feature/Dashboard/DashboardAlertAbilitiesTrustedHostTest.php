<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ExtensionOwnerType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\TrustedHosts;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 허용 도메인(TrustHosts)이 켜진 운영 환경에서 대시보드 경고 [복구]·[닫기] 판정.
 *
 * 경고의 abilities 는 버튼이 부르는 엔드포인트를 라우트 표에서 찾아 그 권한 미들웨어로 판정한다.
 * 운영 환경은 라우트 캐시(컴파일된 라우트 표)를 쓰고, 컴파일된 표는 매칭할 때 요청 Host 를 읽는다.
 * 판정용 요청을 Host 없이(`localhost`) 만들면 허용 도메인 밖이라 매칭이 예외로 끝나고, 판정이
 * 잠금으로 떨어져 최고관리자에게도 버튼이 잠겼다. 테스트 환경은 라우트 캐시도 허용 도메인도 쓰지
 * 않아 기존 시험이 이 경로를 못 봤다 — 여기서는 둘 다 운영과 같이 켠다.
 *
 * 시나리오 매니페스트: tests/scenarios/dashboard-alert-abilities-trusted-host.yaml
 */
class DashboardAlertAbilitiesTrustedHostTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 사이트 주소 (허용 도메인의 기준)
     */
    private const SITE_URL = 'https://g7-site.test';

    /**
     * 테스트용 경고 공급 콜백
     *
     * @var callable|null
     */
    private $supplier = null;

    /**
     * 운영과 같은 조건(사이트 주소 기준 허용 도메인 + 컴파일된 라우트 표)과 경고 공급 필터를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => self::SITE_URL,
            'app.installer_completed' => true,
            'trustedhosts.enabled' => true,
        ]);

        // 운영의 TrustHosts 미들웨어가 하는 일 — 테스트 환경에서는 미들웨어가 스스로 건너뛴다.
        Request::setTrustedHosts(TrustedHosts::patterns());

        // 운영의 라우트 캐시와 같은 컴파일된 라우트 표로 바꾼다(매칭이 요청 Host 를 읽는 경로).
        $router = app('router');
        $router->setCompiledRoutes($router->getRoutes()->compile());

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
            [
                'id' => 'unknown_endpoint_alert',
                'type' => 'warning',
                'recover_endpoint' => '/api/admin/no-such-endpoint/recover',
            ],
        ]);
        HookManager::addFilter('core.dashboard.alerts', $this->supplier, 99);
    }

    /**
     * 필터와 전역 허용 도메인을 걷어냅니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::removeFilter('core.dashboard.alerts', $this->supplier);
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    /**
     * 주어진 권한을 가진 운영자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @param  bool  $isSuper  최고관리자 여부
     * @return User 운영자
     */
    private function operatorWith(array $permissions, bool $isSuper = false): User
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
        $role = Role::create(['identifier' => 'dash_alert_host_'.uniqid(), 'name' => ['ko' => 'r', 'en' => 'r'], 'is_active' => true]);
        $role->permissions()->sync($ids);
        $user = User::factory()->create(['is_super' => $isSuper]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * 사이트 주소로 경고를 조회해 경고별 abilities 를 id 로 모읍니다.
     *
     * @param  User  $user  요청 사용자
     * @return array<string, array<string, bool>|null> 경고 id => abilities
     */
    private function alertAbilities(User $user): array
    {
        $alerts = $this->actingAs($user)
            ->getJson(self::SITE_URL.'/api/admin/dashboard/alerts')
            ->assertOk()
            ->json('data');

        return collect($alerts)
            ->filter(fn ($a) => in_array($a['id'] ?? null, ['recover_plugins_test-plugin', 'static_publish_failure', 'unknown_endpoint_alert'], true))
            ->mapWithKeys(fn ($a) => [$a['id'] => $a['abilities'] ?? null])
            ->all();
    }

    /**
     * 허용 도메인이 켜진 환경에서도 권한을 가진 최고관리자에게는 [복구]·[닫기]가 열린다
     *
     * @scenario trusted_hosts=enabled,route_table=compiled,permission=granted
     *
     * @effects dashboard_alert_actions_open_under_trusted_hosts
     *
     * @return void 반환값 없음
     */
    public function test_super_admin_can_recover_and_dismiss_under_trusted_hosts(): void
    {
        $abilities = $this->alertAbilities($this->operatorWith(
            ['core.dashboard.read', 'core.dashboard.alerts', 'core.plugins.activate', 'core.settings.update'],
            true
        ));

        $this->assertSame(['can_recover' => true, 'can_dismiss' => true], $abilities['recover_plugins_test-plugin']);
        $this->assertSame(['can_recover' => true, 'can_dismiss' => false], $abilities['static_publish_failure']);
    }

    /**
     * 같은 환경에서 대상 권한이 없거나 라우트가 없는 엔드포인트는 여전히 잠긴다(fail-closed)
     *
     * @scenario trusted_hosts=enabled,route_table=compiled,permission=missing
     *
     * @effects dashboard_alert_actions_stay_locked_without_permission_or_route
     *
     * @return void 반환값 없음
     */
    public function test_actions_stay_locked_without_permission_or_route_under_trusted_hosts(): void
    {
        $alertsOnly = $this->alertAbilities($this->operatorWith(['core.dashboard.read', 'core.dashboard.alerts']));
        $this->assertSame(['can_recover' => false, 'can_dismiss' => false], $alertsOnly['recover_plugins_test-plugin']);
        $this->assertSame(['can_recover' => false, 'can_dismiss' => false], $alertsOnly['static_publish_failure']);

        $super = $this->alertAbilities($this->operatorWith(
            ['core.dashboard.read', 'core.dashboard.alerts', 'core.plugins.activate', 'core.settings.update'],
            true
        ));
        $this->assertSame(['can_recover' => false, 'can_dismiss' => false], $super['unknown_endpoint_alert']);
    }
}
