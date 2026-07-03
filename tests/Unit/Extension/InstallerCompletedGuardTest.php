<?php

namespace Tests\Unit\Extension;

use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Listeners\NotificationHookListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `config('app.installer_completed')` 가드가 Schema::hasTable() 호출을
 * 건너뛰는지 검증합니다.
 *
 * 성능 회귀 방지 목적: 설치 완료 상태에서 매 요청 `information_schema.tables`
 * 쿼리가 실행되지 않아야 합니다.
 */
class InstallerCompletedGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config(['app.installer_completed' => false]);
        parent::tearDown();
    }

    /**
     * 주어진 콜백 실행 중 발생한 information_schema.tables 쿼리만 수집합니다.
     *
     * @param  callable  $callback  계측할 코드
     * @return array<int, array<string, mixed>> information_schema.tables 쿼리 목록
     */
    private function captureSchemaTableQueries(callable $callback): array
    {
        DB::enableQueryLog();
        $callback();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return array_filter(
            $queries,
            fn ($q) => str_contains($q['query'], 'information_schema.tables')
        );
    }

    public function test_module_trait_skips_has_table_when_installer_completed(): void
    {
        config(['app.installer_completed' => true]);

        $schemaQueries = $this->captureSchemaTableQueries(
            fn () => ModuleManager::getActiveModuleIdentifiers()
        );

        $this->assertCount(0, $schemaQueries, 'installer_completed=true 에서는 information_schema.tables 쿼리가 발생하지 않아야 합니다');
    }

    public function test_plugin_trait_skips_has_table_when_installer_completed(): void
    {
        config(['app.installer_completed' => true]);

        $schemaQueries = $this->captureSchemaTableQueries(
            fn () => PluginManager::getActivePluginIdentifiers()
        );

        $this->assertCount(0, $schemaQueries);
    }

    public function test_template_trait_skips_has_table_when_installer_completed(): void
    {
        config(['app.installer_completed' => true]);

        $schemaQueries = $this->captureSchemaTableQueries(
            fn () => TemplateManager::getActiveTemplateIdentifiers()
        );

        $this->assertCount(0, $schemaQueries);
    }

    public function test_module_trait_falls_back_to_has_table_when_installer_not_completed(): void
    {
        config(['app.installer_completed' => false]);

        $schemaQueries = $this->captureSchemaTableQueries(
            fn () => ModuleManager::getActiveModuleIdentifiers()
        );

        $this->assertGreaterThan(0, count($schemaQueries), 'installer_completed=false 에서는 기존 hasTable 경로가 실행되어야 합니다');
    }

    /**
     * NotificationHookListener::registerDynamicHooks() 의 hasTable 가드 검증.
     *
     * 부팅 시점 동적 훅 등록 경로 — 설치 완료 상태에서 매 요청
     * `information_schema.tables` 조회(notification_definitions)가 사라져야 합니다.
     */
    public function test_notification_listener_skips_has_table_when_installer_completed(): void
    {
        config(['app.installer_completed' => true]);

        $listener = app(NotificationHookListener::class);

        $schemaQueries = $this->captureSchemaTableQueries(
            fn () => $listener->registerDynamicHooks()
        );

        $this->assertCount(0, $schemaQueries, 'installer_completed=true 에서는 notification_definitions hasTable 조회가 발생하지 않아야 합니다');
    }

    public function test_notification_listener_falls_back_to_has_table_when_installer_not_completed(): void
    {
        config(['app.installer_completed' => false]);

        $listener = app(NotificationHookListener::class);

        $schemaQueries = $this->captureSchemaTableQueries(
            fn () => $listener->registerDynamicHooks()
        );

        $this->assertGreaterThan(0, count($schemaQueries), 'installer_completed=false 에서는 기존 hasTable 경로가 실행되어야 합니다');
    }

    /**
     * IdentityPolicyRepository::listHookTargets() 의 hasTable 가드 검증.
     *
     * 부팅 시점 IDV 동적 hook target 동기화 경로 — 설치 완료 상태에서
     * `information_schema.tables` 조회(identity_policies)가 사라져야 합니다.
     */
    public function test_identity_policy_list_hook_targets_skips_has_table_when_installer_completed(): void
    {
        config(['app.installer_completed' => true]);

        $repository = app(IdentityPolicyRepositoryInterface::class);

        $schemaQueries = $this->captureSchemaTableQueries(
            fn () => $repository->listHookTargets()
        );

        $this->assertCount(0, $schemaQueries, 'installer_completed=true 에서는 identity_policies hasTable 조회가 발생하지 않아야 합니다');
    }

    public function test_identity_policy_list_hook_targets_falls_back_when_installer_not_completed(): void
    {
        config(['app.installer_completed' => false]);

        $repository = app(IdentityPolicyRepositoryInterface::class);

        $schemaQueries = $this->captureSchemaTableQueries(
            fn () => $repository->listHookTargets()
        );

        $this->assertGreaterThan(0, count($schemaQueries), 'installer_completed=false 에서는 기존 hasTable 경로가 실행되어야 합니다');
    }

    /**
     * IdentityPolicyRepository::activeExtensionIdentifiers() 의 hasTable 가드 검증.
     *
     * `resolveByScopeTarget()` → `applyActiveExtensionScope()` → `activeExtensionIdentifiers()`
     * 경로로 modules/plugins 테이블 hasTable 조회가 트리거되며, 설치 완료 상태에서는
     * 해당 `information_schema.tables` 조회가 사라져야 합니다.
     */
    public function test_active_extension_scope_skips_has_table_when_installer_completed(): void
    {
        config(['app.installer_completed' => true]);

        $repository = app(IdentityPolicyRepositoryInterface::class);

        $schemaQueries = $this->captureSchemaTableQueries(
            fn () => $repository->resolveByScopeTarget('hook', 'core.auth.signup_before_submit')
        );

        $this->assertCount(0, $schemaQueries, 'installer_completed=true 에서는 modules/plugins hasTable 조회가 발생하지 않아야 합니다');
    }

    public function test_active_extension_scope_falls_back_when_installer_not_completed(): void
    {
        config(['app.installer_completed' => false]);

        $repository = app(IdentityPolicyRepositoryInterface::class);

        $schemaQueries = $this->captureSchemaTableQueries(
            fn () => $repository->resolveByScopeTarget('hook', 'core.auth.signup_before_submit')
        );

        $this->assertGreaterThan(0, count($schemaQueries), 'installer_completed=false 에서는 기존 hasTable 경로가 실행되어야 합니다');
    }
}
