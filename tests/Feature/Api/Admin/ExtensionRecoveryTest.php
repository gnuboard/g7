<?php

namespace Tests\Feature\Api\Admin;

use App\Contracts\Extension\PluginInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Enums\DeactivationReason;
use App\Enums\ExtensionOwnerType;
use App\Enums\ExtensionStatus;
use App\Enums\PermissionType;
use App\Extension\Support\LifecycleWarnings;
use App\Models\Permission;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\User;
use App\Services\ExtensionCompatibilityAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 확장 호환성 복구/조회/dismiss API 테스트.
 *
 * GET    /api/admin/extensions/auto-deactivated
 * POST   /api/admin/extensions/{type}/{identifier}/recover
 * POST   /api/admin/extensions/{type}/{identifier}/dismiss
 */
class ExtensionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    /**
     * 관리자 계정·토큰을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser([
            'core.plugins.activate',
            'core.plugins.update',
            'core.plugins.read',
        ]);
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 지정한 권한을 가진 관리자 사용자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  부여할 권한 식별자 목록
     * @return User 역할이 연결된 사용자
     */
    private function createAdminUser(array $permissions): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach ($permissions as $permIdentifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $permIdentifier],
                [
                    'name' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                    'description' => json_encode(['ko' => $permIdentifier.' 권한', 'en' => $permIdentifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => PermissionType::Admin,
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $testRole = Role::create([
            'identifier' => 'admin_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $testRole->permissions()->sync($permissionIds);

        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);
        $user->roles()->attach($testRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }

    /**
     * 관리자 토큰을 실은 요청 빌더를 돌려줍니다.
     *
     * @return static 인증 헤더를 붙인 테스트 케이스
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 자동 비활성 목록이 확장 종류별로 묶인 구조를 돌려준다.
     *
     * @scenario case=list_grouped
     *
     * @effects auto_deactivated_lists_grouped_items
     *
     * @return void 반환값 없음
     */
    public function test_auto_deactivated_endpoint_returns_grouped_items_structure(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/extensions/auto-deactivated');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'items' => ['plugins', 'modules', 'templates'],
                'current_core_version',
            ],
        ]);
    }

    /**
     * 자동 비활성 목록은 인증이 필요하다.
     *
     * @scenario case=list_guest
     *
     * @effects unauthenticated_request_rejected
     *
     * @return void 반환값 없음
     */
    public function test_auto_deactivated_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/extensions/auto-deactivated');

        $response->assertStatus(401);
    }

    /**
     * 알 수 없는 확장 종류의 복구는 라우트 제약으로 404 다.
     *
     * @scenario case=recover_invalid_type
     *
     * @effects recover_rejects_unknown_target
     *
     * @return void 반환값 없음
     */
    public function test_recover_with_invalid_type_returns_404_due_to_route_constraint(): void
    {
        // route where('type', 'plugin|module|template') 가 invalid 타입을 경로 매칭 단계에서 차단합니다.
        $response = $this->authRequest()->postJson('/api/admin/extensions/invalid/foo/recover');

        $response->assertStatus(404);
    }

    /**
     * 없는 확장의 복구는 404 다.
     *
     * @scenario case=recover_unknown_identifier
     *
     * @effects recover_rejects_unknown_target
     *
     * @return void 반환값 없음
     */
    public function test_recover_with_unknown_identifier_returns_404(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/extensions/plugin/nonexistent-plugin/recover');

        $response->assertStatus(404);
        $response->assertJson(['success' => false]);
    }

    /**
     * 알림 닫기는 닫은 알림 식별자를 돌려준다.
     *
     * @scenario case=dismiss_admin
     *
     * @effects dismiss_returns_alert_id
     *
     * @return void 반환값 없음
     */
    public function test_dismiss_returns_success_for_known_route(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/extensions/plugin/some-plugin/dismiss');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => ['alert_id'],
        ]);
    }

    /**
     * 알림 닫기는 인증이 필요하다.
     *
     * @scenario case=dismiss_guest
     *
     * @effects unauthenticated_request_rejected
     *
     * @return void 반환값 없음
     */
    public function test_dismiss_requires_authentication(): void
    {
        $response = $this->postJson('/api/admin/extensions/plugin/some-plugin/dismiss');

        $response->assertStatus(401);
    }

    /**
     * 식별자에 isHidden=true 를 반환하는 fake PluginInterface 를 등록합니다.
     *
     * 실제 PluginManager 의 in-memory $plugins 배열에 fake 를 주입해 controller 의
     * isHiddenExtension 분기를 검증합니다 (loadPlugins 는 active 디렉토리만 스캔하므로
     * 파일 fixture 만으로는 manager 인식이 어려움).
     *
     * @param  string  $identifier  숨김으로 등록할 플러그인 식별자
     * @return void 반환값 없음
     */
    private function registerHiddenPluginStub(string $identifier): void
    {
        $manager = app(PluginManagerInterface::class);

        $fakePlugin = \Mockery::mock(PluginInterface::class);
        $fakePlugin->shouldReceive('isHidden')->andReturn(true);
        $fakePlugin->shouldReceive('getIdentifier')->andReturn($identifier);

        $reflect = new \ReflectionClass($manager);
        $prop = $reflect->getProperty('plugins');
        $prop->setAccessible(true);
        $plugins = $prop->getValue($manager);
        $plugins[$identifier] = $fakePlugin;
        $prop->setValue($manager, $plugins);
    }

    /**
     * Mockery 기대를 닫습니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * K.5 정합 — hidden 확장은 autoDeactivated 응답에서 제외되어야 한다.
     *
     * 회귀 차단: 학습용 샘플 등 hidden 확장이 자동 비활성화되어도 배너/대시보드 카드에
     * 노출되지 않아야 한다 (계획서 B.7 / K.5).
     *
     * @scenario case=list_hidden_extension
     *
     * @effects hidden_extension_excluded
     *
     * @return void 반환값 없음
     */
    public function test_auto_deactivated_excludes_hidden_extensions(): void
    {
        $hiddenIdentifier = 'audit-hidden-plg-'.uniqid();
        $visibleIdentifier = 'audit-visible-plg-'.uniqid();

        $this->registerHiddenPluginStub($hiddenIdentifier);
        // visibleIdentifier 는 stub 등록하지 않음 → manager->getPlugin() 이 null →
        // isHiddenExtension 이 false 반환 → 응답에 포함됨

        Plugin::create([
            'identifier' => $hiddenIdentifier,
            'vendor' => 'audit',
            'name' => json_encode(['ko' => '히든', 'en' => 'Hidden']),
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
            'is_installed' => true,
            'deactivated_reason' => DeactivationReason::IncompatibleCore->value,
            'deactivated_at' => now(),
            'incompatible_required_version' => '>=99.0.0',
        ]);
        Plugin::create([
            'identifier' => $visibleIdentifier,
            'vendor' => 'audit',
            'name' => json_encode(['ko' => '일반', 'en' => 'Visible']),
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
            'is_installed' => true,
            'deactivated_reason' => DeactivationReason::IncompatibleCore->value,
            'deactivated_at' => now(),
            'incompatible_required_version' => '>=99.0.0',
        ]);

        $response = $this->authRequest()->getJson('/api/admin/extensions/auto-deactivated');
        $response->assertStatus(200);

        $items = $response->json('data.items.plugins') ?? [];
        $identifiers = array_column($items, 'identifier');

        $this->assertContains($visibleIdentifier, $identifiers, 'visible 확장은 응답에 포함되어야 한다');
        $this->assertNotContains($hiddenIdentifier, $identifiers, 'hidden 확장은 응답에서 제외되어야 한다 (B.7 / K.5)');
    }

    /**
     * K.5 정합 — recover 엔드포인트는 hidden 확장을 거부해야 한다.
     *
     * @scenario case=recover_hidden_extension
     *
     * @effects hidden_extension_excluded
     *
     * @return void 반환값 없음
     */
    public function test_recover_rejects_hidden_extension(): void
    {
        $identifier = 'audit-hidden-recover-'.uniqid();

        $this->registerHiddenPluginStub($identifier);

        Plugin::create([
            'identifier' => $identifier,
            'vendor' => 'audit',
            'name' => json_encode(['ko' => '히든', 'en' => 'Hidden']),
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
            'is_installed' => true,
            'deactivated_reason' => DeactivationReason::IncompatibleCore->value,
            'deactivated_at' => now(),
            'incompatible_required_version' => '>=99.0.0',
        ]);

        $response = $this->authRequest()->postJson("/api/admin/extensions/plugin/{$identifier}/recover");

        $response->assertStatus(422);
        $response->assertJsonPath('errors.error_code', 'hidden_extension');
    }

    /**
     * 복구(재활성화) 중 일부 항목이 실패하면 그 경고를 응답 data.warnings 로 싣는다 (코어 7.1.3).
     *
     * 회귀 차단: 복구 응답이 경고를 붙이는 공용 성공 응답을 거치지 않아, 대시보드 「복구」 버튼이
     * 레이아웃 등록 실패 같은 부분 실패를 운영자에게 보이지 못했다(다른 확장 화면은 노란 경고 창).
     *
     * @scenario case=recover_partial_failure
     *
     * @effects recover_response_carries_lifecycle_warnings
     *
     * @return void 반환값 없음
     */
    public function test_recover_response_carries_lifecycle_warnings(): void
    {
        $identifier = 'audit-recover-warn-'.uniqid();

        Plugin::create([
            'identifier' => $identifier,
            'vendor' => 'audit',
            'name' => json_encode(['ko' => '복구', 'en' => 'Recover']),
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
            'is_installed' => true,
            'deactivated_reason' => DeactivationReason::IncompatibleCore->value,
            'deactivated_at' => now(),
            'incompatible_required_version' => '>=7.0.0',
        ]);

        // 재활성화가 레이아웃 하나를 등록하지 못하고 끝난 상황 — 매니저는 성공을 돌려주고 경고만 쌓는다
        $manager = \Mockery::mock(PluginManagerInterface::class);
        $manager->shouldReceive('getPlugin')->andReturn(null);
        $manager->shouldReceive('activatePlugin')->once()->with($identifier)->andReturnUsing(function () use ($identifier) {
            LifecycleWarnings::current()->add('layout', "{$identifier}:index", '레이아웃 JSON 형식 오류');

            return ['success' => true];
        });
        $this->app->instance(PluginManagerInterface::class, $manager);

        $response = $this->authRequest()->postJson("/api/admin/extensions/plugin/{$identifier}/recover");

        $response->assertStatus(200);
        $response->assertJsonPath('data.identifier', $identifier);
        $response->assertJsonCount(1, 'data.warnings');
        $response->assertJsonPath('data.warnings.0.step', 'layout');
        $response->assertJsonPath('data.warnings.0.target', "{$identifier}:index");
    }

    /**
     * A.7 / K.5 정합 — autoDeactivated 응답은 현재 사용자가 dismiss 한 항목을 제외해야 한다.
     *
     * 회귀 차단: dismiss POST 가 캐시에 기록되지만 autoDeactivated 응답이 같은 캐시를
     * 읽지 않아 X 클릭 후에도 배너 항목이 그대로 노출되던 문제 (배너 dismiss 무력화).
     *
     * @scenario case=list_dismissed_item
     *
     * @effects dismissed_items_excluded
     *
     * @return void 반환값 없음
     */
    public function test_auto_deactivated_excludes_user_dismissed_items(): void
    {
        $dismissedIdentifier = 'audit-dismissed-plg-'.uniqid();
        $visibleIdentifier = 'audit-not-dismissed-plg-'.uniqid();

        Plugin::create([
            'identifier' => $dismissedIdentifier,
            'vendor' => 'audit',
            'name' => json_encode(['ko' => 'dismiss됨', 'en' => 'Dismissed']),
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
            'is_installed' => true,
            'deactivated_reason' => DeactivationReason::IncompatibleCore->value,
            'deactivated_at' => now(),
            'incompatible_required_version' => '>=99.0.0',
        ]);
        Plugin::create([
            'identifier' => $visibleIdentifier,
            'vendor' => 'audit',
            'name' => json_encode(['ko' => '미dismiss', 'en' => 'Not dismissed']),
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
            'is_installed' => true,
            'deactivated_reason' => DeactivationReason::IncompatibleCore->value,
            'deactivated_at' => now(),
            'incompatible_required_version' => '>=99.0.0',
        ]);

        // dismiss 캐시에 직접 기록 (Service 단일 진입점)
        app(ExtensionCompatibilityAlertService::class)
            ->dismissAlert("compat_plugins_{$dismissedIdentifier}", $this->admin->id);

        $response = $this->authRequest()->getJson('/api/admin/extensions/auto-deactivated');
        $response->assertStatus(200);

        $items = $response->json('data.items.plugins') ?? [];
        $identifiers = array_column($items, 'identifier');

        $this->assertContains($visibleIdentifier, $identifiers, 'dismiss 안 된 확장은 응답에 포함되어야 한다');
        $this->assertNotContains($dismissedIdentifier, $identifiers, '사용자가 dismiss 한 확장은 응답에서 제외되어야 한다 (A.7 / K.5)');
    }
}
