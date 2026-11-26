<?php

namespace Tests\Feature\Identity;

use App\Extension\AbstractModule;
use App\Extension\ModuleManager;
use App\Models\IdentityPolicy;
use App\Services\IdentityPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 모듈이 선언한 본인인증 정책의 「기본값으로 되돌리기」 (7.2.1 Fixed)
 */
class IdentityPolicyModuleDefaultResetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 모듈이 선언한 정책도 되돌리기가 그 모듈의 getIdentityPolicies() 선언값을 찾아 복원한다.
     *
     * 회귀: 선언값 조회가 ModuleManager 에 없는 메서드를 불러 예외가 났고, 그 예외를 \Throwable 로 삼켜
     * 모듈 정책은 늘 「선언 기본값 없음」(false) 으로 끝났다.
     *
     * @scenario case=module_declared_reset
     *
     * @effects identity_policy_module_default_restored
     *
     * @return void 반환값 없음
     */
    public function test_reset_field_override_restores_module_declared_default(): void
    {
        $module = \Mockery::mock(AbstractModule::class)->makePartial();
        $module->shouldReceive('getIdentityPolicies')->andReturn([
            ['key' => 'test.module.reset', 'grace_minutes' => 15, 'enabled' => true],
        ]);
        $manager = $this->app->make(ModuleManager::class);
        $property = new \ReflectionProperty(ModuleManager::class, 'modules');
        $property->setValue($manager, array_merge($property->getValue($manager), ['test-reset-module' => $module]));
        $this->app->instance(ModuleManager::class, $manager);

        $policy = IdentityPolicy::create([
            'key' => 'test.module.reset',
            'scope' => 'route',
            'target' => 'api.test.module.reset',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 60,
            'enabled' => true,
            'priority' => 100,
            'source_type' => 'module',
            'source_identifier' => 'test-reset-module',
            'applies_to' => 'self',
            'fail_mode' => 'block',
            'user_overrides' => ['grace_minutes'],
        ]);

        $this->assertTrue(app(IdentityPolicyService::class)->resetFieldOverride($policy, 'grace_minutes'));

        $fresh = $policy->fresh();
        $this->assertSame(15, (int) $fresh->grace_minutes);
        $this->assertNotContains('grace_minutes', $fresh->user_overrides ?? []);
    }
}
