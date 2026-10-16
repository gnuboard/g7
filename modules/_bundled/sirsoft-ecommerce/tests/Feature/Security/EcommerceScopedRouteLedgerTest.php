<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Security;

use App\Enums\PermissionType;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Support\ScopedPermissionEnforcement;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceScopeLedgerListener;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 스코프 강제 지점 원장 감사
 *
 * 권한 미들웨어는 라우트 파라미터 이름이 권한의 `resource_route_key` 와 같고 Model 로 바인딩될 때만
 * 스코프를 검사한다. 스코프 메타가 선언된 권한을 거는 관리자 라우트는 (a) 그렇게 바인딩되거나
 * (b) 원장(`EcommerceScopeLedgerListener`)에 서비스·쿼리 재적용 지점과 함께 적혀 있어야 한다.
 *
 * 이 테스트는 module.php 선언에서 권한 메타를 옮겨 심고(테스트 DB 는 모듈 권한을 동기화하지 않는다)
 * 이커머스 모듈 라우트 전량을 감사한다. 새 라우트를 `{id}` 로 열고 원장에 적지 않으면 여기서 실패한다.
 */
class EcommerceScopedRouteLedgerTest extends ModuleTestCase
{
    /** 감사 대상 라우트 이름 접두 (결제 플러그인 등 다른 확장 라우트는 각자 원장이 맡는다) */
    private const ROUTE_PREFIX = 'api.modules.sirsoft-ecommerce.';

    /**
     * 테스트 준비 — 모듈 선언의 스코프 메타를 권한 행에 옮기고 원장 필터를 보장합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDeclaredScopeMetadata();
        PermissionHelper::clearPermissionScopeCache();

        // 실제 등록 경로(HookListenerRegistrar)를 태운다 — module.php 가 등록하는 것과 같은 구독 선언을 쓴다.
        // 모듈 부팅이 이미 등록했으면 같은 항목이 한 번 더 병합될 뿐이다(키 단위 덮어쓰기)
        HookListenerRegistrar::register(EcommerceScopeLedgerListener::class);
    }

    /**
     * 스코프 메타가 있는 권한을 거는 이커머스 라우트는 모두 바인딩되거나 원장에 있다.
     *
     * @scenario surface=ledger_audit
     *
     * @effects every_scoped_ecommerce_route_is_bound_or_ledgered
     *
     * @return void 반환값 없음
     */
    public function test_every_scoped_ecommerce_route_is_bound_or_ledgered(): void
    {
        $routes = $this->ecommerceRoutes();
        $this->assertNotEmpty($routes, '감사할 이커머스 라우트가 없습니다 — 라우트 로드 실패');

        $violations = ScopedPermissionEnforcement::auditRoutes($routes, 'sirsoft-ecommerce.');

        $this->assertSame([], $violations, "원장 미등록 + 미바인딩 라우트:\n".json_encode($violations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 원장의 강제 메서드는 모두 존재하고 스코프를 실제로 판정한다(이름이 바뀌어 낡으면 실패).
     *
     * @scenario surface=ledger_audit
     *
     * @effects ledger_enforcers_exist_and_check_scope
     *
     * @return void 반환값 없음
     */
    public function test_ledger_enforcers_exist_and_check_scope(): void
    {
        $violations = ScopedPermissionEnforcement::auditEnforcers('sirsoft-ecommerce.');

        $this->assertSame([], $violations, "낡았거나 판정 없는 강제 지점:\n".json_encode($violations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * (음성 대조) 원장 항목을 빼면 감사가 그 라우트를 위반으로 잡는다 — 감사가 공회전하지 않음을 고정한다.
     *
     * @scenario surface=ledger_audit
     *
     * @effects missing_ledger_entry_is_reported
     *
     * @return void 반환값 없음
     */
    public function test_missing_ledger_entry_is_reported(): void
    {
        HookManager::addFilter(
            'core.permission.scope_enforcement_ledger',
            function (array $ledger) {
                unset($ledger['sirsoft-ecommerce.brands.read']);

                return $ledger;
            },
            99
        );

        $violations = ScopedPermissionEnforcement::auditRoutes($this->ecommerceRoutes(), 'sirsoft-ecommerce.');
        $routes = array_column($violations, 'route');

        $this->assertContains(self::ROUTE_PREFIX.'admin.brands.show', $routes);
        $this->assertContains(self::ROUTE_PREFIX.'admin.brands.index', $routes);
    }

    /**
     * module.php 의 권한 단위(카테고리가 아닌) 스코프 선언은 ModuleManager 가 읽지 않는다 — 남기지 않는다.
     *
     * @scenario surface=ledger_audit
     *
     * @effects no_permission_level_scope_keys_in_module_php
     *
     * @return void 반환값 없음
     */
    public function test_module_declares_scope_keys_only_at_category_level(): void
    {
        $offenders = [];
        foreach ((new Module)->getPermissions()['categories'] ?? [] as $category) {
            foreach ($category['permissions'] ?? [] as $permission) {
                if (array_key_exists('resource_route_key', $permission) || array_key_exists('owner_key', $permission)) {
                    $offenders[] = $category['identifier'].'.'.$permission['action'];
                }
            }
        }

        $this->assertSame([], $offenders, '권한 단위 스코프 키는 무시되는 선언입니다');
    }

    /**
     * module.php 가 원장 리스너를 등록한다 — 등록이 빠지면 운영 감사에서 이커머스 원장이 통째로 사라진다.
     *
     * @scenario surface=ledger_audit
     *
     * @effects every_scoped_ecommerce_route_is_bound_or_ledgered
     *
     * @return void 반환값 없음
     */
    public function test_module_registers_ledger_listener(): void
    {
        $this->assertContains(EcommerceScopeLedgerListener::class, (new Module)->getHookListeners());
        $this->assertNotNull(ScopedPermissionEnforcement::ledgerEntry('sirsoft-ecommerce.brands.read'));
    }

    /**
     * 이커머스 모듈 라우트만 고릅니다.
     *
     * @return array<int, Route> 라우트 목록
     */
    private function ecommerceRoutes(): array
    {
        return array_values(array_filter(
            RouteFacade::getRoutes()->getRoutes(),
            fn (Route $route) => str_starts_with((string) $route->getName(), self::ROUTE_PREFIX)
        ));
    }

    /**
     * module.php 카테고리 단위 스코프 메타를 권한 행으로 옮깁니다 (ModuleManager 동기화와 같은 규칙 — create 제외).
     *
     * @return void 반환값 없음
     */
    private function seedDeclaredScopeMetadata(): void
    {
        foreach ((new Module)->getPermissions()['categories'] ?? [] as $category) {
            if (empty($category['resource_route_key']) || empty($category['owner_key'])) {
                continue;
            }

            foreach ($category['permissions'] ?? [] as $permission) {
                if ($permission['action'] === 'create') {
                    continue;
                }

                $identifier = 'sirsoft-ecommerce.'.$category['identifier'].'.'.$permission['action'];
                Permission::updateOrCreate(
                    ['identifier' => $identifier],
                    [
                        'name' => ['ko' => $identifier, 'en' => $identifier],
                        'type' => PermissionType::from($permission['type'] ?? 'admin'),
                        'resource_route_key' => $category['resource_route_key'],
                        'owner_key' => $category['owner_key'],
                    ]
                );
            }
        }
    }
}
