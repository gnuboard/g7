<?php

namespace Tests\Unit\Extension;

use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Listeners\NotificationHookListener;
use App\Providers\ModuleRouteServiceProvider;
use App\Providers\PluginRouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
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

    /** @var array<int, string>|null 원본 argv 백업 */
    private ?array $originalArgv = null;

    protected function tearDown(): void
    {
        config(['app.installer_completed' => false]);

        if ($this->originalArgv !== null) {
            $_SERVER['argv'] = $this->originalArgv;
            $this->originalArgv = null;
        }

        parent::tearDown();
    }

    /**
     * $_SERVER['argv'] 를 마이그레이션 명령으로 임시 설정하고 콜백을 실행합니다.
     *
     * PHPUnit 은 이미 콘솔 컨텍스트이므로, argv[1] 만 'migrate' 로 바꾸면
     * InstallerContext::isSchemaMutatingCommand() 가 true 가 되어 fast-path 가 무력화된다.
     * argv 는 finally 에서 반드시 원복해 다른 테스트 오염을 막는다.
     *
     * @param  callable  $callback  마이그레이션 컨텍스트에서 실행할 코드
     */
    private function withMigrationArgv(callable $callback): void
    {
        $this->originalArgv = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['artisan', 'migrate'];

        try {
            $callback();
        } finally {
            $_SERVER['argv'] = $this->originalArgv;
            $this->originalArgv = null;
        }
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

    /*
    |--------------------------------------------------------------------------
    | 마이그레이션 컨텍스트 가드 (빈 DB 배포 안전성)
    |--------------------------------------------------------------------------
    |
    | INSTALLER_COMPLETED=true .env 를 빈 DB 새 서버에 복사한 뒤 `php artisan migrate`
    | 부팅 시, fast-path 가 테이블 존재를 잘못 전제해 뒤따르는 쿼리가 table not found 로
    | 부팅을 깨뜨리던 결함을 막는다. 마이그레이션 계열 명령 컨텍스트에서는
    | installer_completed=true 라도 fast-path 를 우회해 hasTable 실검증 경로로 진입해야 한다.
    | (RefreshDatabase 는 테이블을 만들므로 실제 not-found 는 재현 불가 → 가드 발동으로
    |  hasTable 쿼리가 다시 발생하는지를 쿼리카운트로 검증)
    */

    public function test_module_trait_uses_has_table_during_migration_despite_installer_completed(): void
    {
        config(['app.installer_completed' => true]);

        $this->withMigrationArgv(function () {
            $schemaQueries = $this->captureSchemaTableQueries(
                fn () => ModuleManager::getActiveModuleIdentifiers()
            );

            $this->assertGreaterThan(0, count($schemaQueries), '마이그레이션 중에는 installer_completed=true 라도 hasTable 폴백 경로로 진입해야 합니다');
        });
    }

    public function test_plugin_trait_uses_has_table_during_migration_despite_installer_completed(): void
    {
        config(['app.installer_completed' => true]);

        $this->withMigrationArgv(function () {
            $schemaQueries = $this->captureSchemaTableQueries(
                fn () => PluginManager::getActivePluginIdentifiers()
            );

            $this->assertGreaterThan(0, count($schemaQueries), '마이그레이션 중에는 installer_completed=true 라도 hasTable 폴백 경로로 진입해야 합니다');
        });
    }

    public function test_template_trait_uses_has_table_during_migration_despite_installer_completed(): void
    {
        config(['app.installer_completed' => true]);

        $this->withMigrationArgv(function () {
            $schemaQueries = $this->captureSchemaTableQueries(
                fn () => TemplateManager::getActiveTemplateIdentifiers()
            );

            $this->assertGreaterThan(0, count($schemaQueries), '마이그레이션 중에는 installer_completed=true 라도 hasTable 폴백 경로로 진입해야 합니다');
        });
    }

    public function test_module_route_provider_uses_has_table_during_migration_despite_installer_completed(): void
    {
        config(['app.installer_completed' => true]);

        $provider = new ModuleRouteServiceProvider($this->app);
        $method = (new ReflectionClass($provider))->getMethod('loadModuleRoutes');
        $method->setAccessible(true);

        $this->withMigrationArgv(function () use ($provider, $method) {
            $schemaQueries = $this->captureSchemaTableQueries(
                fn () => $method->invoke($provider)
            );

            $this->assertGreaterThan(0, count($schemaQueries), '마이그레이션 중에는 installer_completed=true 라도 modules hasTable 검증 경로로 진입해 무방비 pluck 을 막아야 합니다');
        });
    }

    public function test_plugin_route_provider_uses_has_table_during_migration_despite_installer_completed(): void
    {
        config(['app.installer_completed' => true]);

        $provider = new PluginRouteServiceProvider($this->app);
        $method = (new ReflectionClass($provider))->getMethod('loadPluginRoutes');
        $method->setAccessible(true);

        $this->withMigrationArgv(function () use ($provider, $method) {
            $schemaQueries = $this->captureSchemaTableQueries(
                fn () => $method->invoke($provider)
            );

            $this->assertGreaterThan(0, count($schemaQueries), '마이그레이션 중에는 installer_completed=true 라도 plugins hasTable 검증 경로로 진입해야 합니다');
        });
    }
}
