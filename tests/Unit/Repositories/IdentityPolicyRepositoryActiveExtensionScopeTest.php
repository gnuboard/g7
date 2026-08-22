<?php

namespace Tests\Unit\Repositories;

use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Models\IdentityPolicy;
use App\Models\Module;
use App\Models\Plugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IdentityPolicyRepository 의 비활성 확장 정책 제외(applyActiveExtensionScope) 검증.
 *
 * 회귀 배경: 모듈/플러그인을 비활성화해도 그 확장이 선언한 IDV 정책이 enforce 쿼리에
 * 그대로 남아 계속 강제되던 결함. enforce 두 경로(resolveByScopeTarget=hook,
 * getRouteScopeIndex=route)가 source_type/source_identifier 기준으로 비활성 확장
 * 정책을 제외하는지, 그리고 재활성화 시 다시 포함되는지(3-state)를 검증한다.
 *
 * @group identity
 * @group unit
 */
class IdentityPolicyRepositoryActiveExtensionScopeTest extends TestCase
{
    use RefreshDatabase;

    private IdentityPolicyRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(IdentityPolicyRepositoryInterface::class);
    }

    /**
     * 모듈 레코드를 생성합니다.
     */
    private function makeModule(string $identifier, string $status): Module
    {
        return Module::create([
            'identifier' => $identifier,
            'vendor' => 'sirsoft',
            'name' => ['ko' => $identifier, 'en' => $identifier],
            'version' => '1.0.0',
            'status' => $status,
        ]);
    }

    /**
     * 플러그인 레코드를 생성합니다.
     */
    private function makePlugin(string $identifier, string $status): Plugin
    {
        return Plugin::create([
            'identifier' => $identifier,
            'vendor' => 'sirsoft',
            'name' => ['ko' => $identifier, 'en' => $identifier],
            'version' => '1.0.0',
            'status' => $status,
        ]);
    }

    /**
     * 정책을 생성합니다.
     */
    private function makePolicy(array $overrides = []): IdentityPolicy
    {
        return IdentityPolicy::create(array_merge([
            'key' => 'test.policy.'.uniqid(),
            'scope' => 'hook',
            'target' => 'test.hook.target',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 5,
            'enabled' => true,
            'priority' => 0,
            'source_type' => 'core',
            'source_identifier' => 'core',
            'applies_to' => 'both',
            'fail_mode' => 'block',
        ], $overrides));
    }

    /**
     * hook scope: 활성 모듈의 정책은 enforce 대상에 포함된다
     */
    public function test_hook_scope_includes_active_module_policy(): void
    {
        $this->makeModule('sirsoft-fake', ExtensionStatus::Active->value);
        $this->makePolicy([
            'scope' => 'hook',
            'target' => 'fake.hook',
            'source_type' => 'module',
            'source_identifier' => 'sirsoft-fake',
        ]);

        $result = $this->repository->resolveByScopeTarget('hook', 'fake.hook');

        $this->assertCount(1, $result);
    }

    /**
     * hook scope: 비활성 모듈의 정책은 enforce 대상에서 제외된다 (회귀)
     */
    public function test_hook_scope_excludes_inactive_module_policy(): void
    {
        $this->makeModule('sirsoft-fake', ExtensionStatus::Inactive->value);
        $this->makePolicy([
            'scope' => 'hook',
            'target' => 'fake.hook',
            'source_type' => 'module',
            'source_identifier' => 'sirsoft-fake',
        ]);

        $result = $this->repository->resolveByScopeTarget('hook', 'fake.hook');

        $this->assertCount(0, $result);
    }

    /**
     * hook scope: 모듈 재활성화 시 정책이 다시 enforce 대상에 포함된다 (3-state)
     */
    public function test_hook_scope_reincludes_policy_after_reactivation(): void
    {
        $module = $this->makeModule('sirsoft-fake', ExtensionStatus::Inactive->value);
        $this->makePolicy([
            'scope' => 'hook',
            'target' => 'fake.hook',
            'source_type' => 'module',
            'source_identifier' => 'sirsoft-fake',
        ]);

        // 비활성: 제외
        $this->assertCount(0, $this->repository->resolveByScopeTarget('hook', 'fake.hook'));

        // 재활성화: 다시 포함
        $module->update(['status' => ExtensionStatus::Active->value]);
        $this->assertCount(1, $this->repository->resolveByScopeTarget('hook', 'fake.hook'));
    }

    /**
     * hook scope: 비활성 플러그인의 정책도 제외된다
     */
    public function test_hook_scope_excludes_inactive_plugin_policy(): void
    {
        $this->makePlugin('sirsoft-fakeplugin', ExtensionStatus::Inactive->value);
        $this->makePolicy([
            'scope' => 'hook',
            'target' => 'fakeplugin.hook',
            'source_type' => 'plugin',
            'source_identifier' => 'sirsoft-fakeplugin',
        ]);

        $result = $this->repository->resolveByScopeTarget('hook', 'fakeplugin.hook');

        $this->assertCount(0, $result);
    }

    /**
     * core/admin 정책은 확장 상태와 무관하게 항상 포함된다 (비파괴)
     */
    public function test_hook_scope_always_includes_core_and_admin_policies(): void
    {
        $this->makePolicy([
            'scope' => 'hook',
            'target' => 'core.hook',
            'source_type' => 'core',
            'source_identifier' => 'core',
        ]);
        $this->makePolicy([
            'scope' => 'hook',
            'target' => 'admin.hook',
            'source_type' => 'admin',
            'source_identifier' => 'admin',
        ]);

        $this->assertCount(1, $this->repository->resolveByScopeTarget('hook', 'core.hook'));
        $this->assertCount(1, $this->repository->resolveByScopeTarget('hook', 'admin.hook'));
    }

    /**
     * route scope: 활성 모듈의 정책은 라우트 인덱스에 포함된다
     */
    public function test_route_scope_includes_active_module_policy(): void
    {
        $this->makeModule('sirsoft-fake', ExtensionStatus::Active->value);
        $this->makePolicy([
            'scope' => 'route',
            'target' => 'api.fake.delete',
            'source_type' => 'module',
            'source_identifier' => 'sirsoft-fake',
        ]);

        IdentityPolicy::flushRouteScopeCache();
        $index = $this->repository->getRouteScopeIndex();

        $this->assertArrayHasKey('api.fake.delete', $index);
        $this->assertCount(1, $index['api.fake.delete']);
    }

    /**
     * route scope: 비활성 모듈의 정책은 라우트 인덱스에서 제외된다 (회귀)
     */
    public function test_route_scope_excludes_inactive_module_policy(): void
    {
        $this->makeModule('sirsoft-fake', ExtensionStatus::Inactive->value);
        $this->makePolicy([
            'scope' => 'route',
            'target' => 'api.fake.delete',
            'source_type' => 'module',
            'source_identifier' => 'sirsoft-fake',
        ]);

        IdentityPolicy::flushRouteScopeCache();
        $index = $this->repository->getRouteScopeIndex();

        $this->assertArrayNotHasKey('api.fake.delete', $index);
    }

    /**
     * route scope: 모듈 재활성화 시 라우트 인덱스에 다시 포함된다 (3-state, 캐시 무효화 동반)
     */
    public function test_route_scope_reincludes_policy_after_reactivation(): void
    {
        $module = $this->makeModule('sirsoft-fake', ExtensionStatus::Inactive->value);
        $this->makePolicy([
            'scope' => 'route',
            'target' => 'api.fake.delete',
            'source_type' => 'module',
            'source_identifier' => 'sirsoft-fake',
        ]);

        // 비활성: 제외
        IdentityPolicy::flushRouteScopeCache();
        $this->assertArrayNotHasKey('api.fake.delete', $this->repository->getRouteScopeIndex());

        // 재활성화 + 캐시 무효화: 다시 포함
        $module->update(['status' => ExtensionStatus::Active->value]);
        IdentityPolicy::flushRouteScopeCache();
        $this->assertArrayHasKey('api.fake.delete', $this->repository->getRouteScopeIndex());
    }
}
