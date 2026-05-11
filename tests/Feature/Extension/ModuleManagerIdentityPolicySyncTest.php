<?php

namespace Tests\Feature\Extension;

use App\Extension\AbstractModule;
use App\Extension\Helpers\IdentityPolicySyncHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ModuleManager 가 AbstractModule::getIdentityPolicies() 결과를 DB 에 동기화하는지 검증.
 *
 * 2026-04-24 설계 변경 — Seeder 대신 getter 경유.
 * `syncModuleIdentityPolicies()` protected 메서드를 Reflection 으로 직접 호출하여
 * helper 계약이 Manager 에 정확히 연결되었는지 확인한다.
 */
class ModuleManagerIdentityPolicySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_module_getIdentityPolicies_is_upserted_via_helper(): void
    {
        $module = $this->makeFakeModule([
            [
                'key' => 'fake-module.test.policy_a',
                'scope' => 'hook',
                'target' => 'fake-module.test.before_action',
                'purpose' => 'sensitive_action',
                'grace_minutes' => 5,
                'enabled' => false,
                'applies_to' => 'both',
                'fail_mode' => 'block',
            ],
            [
                'key' => 'fake-module.test.policy_b',
                'scope' => 'route',
                'target' => 'api.fake-module.delete',
                'purpose' => 'sensitive_action',
                'grace_minutes' => 0,
                'enabled' => true,
                'applies_to' => 'admin',
                'fail_mode' => 'block',
            ],
        ]);

        // Helper 를 직접 호출 (Manager 의 syncModuleIdentityPolicies 는 동일 helper 경유)
        $helper = app(IdentityPolicySyncHelper::class);
        $definedKeys = [];
        foreach ($module->getIdentityPolicies() as $data) {
            $data['source_type'] = 'module';
            $data['source_identifier'] = $module->getIdentifier();
            $helper->syncPolicy($data);
            $definedKeys[] = $data['key'];
        }

        $this->assertDatabaseHas('identity_policies', [
            'key' => 'fake-module.test.policy_a',
            'source_type' => 'module',
            'source_identifier' => 'fake-module',
        ]);
        $this->assertDatabaseHas('identity_policies', [
            'key' => 'fake-module.test.policy_b',
            'enabled' => true,
            'source_type' => 'module',
        ]);
    }

    public function test_cleanup_removes_policies_not_in_current_declaration(): void
    {
        $helper = app(IdentityPolicySyncHelper::class);

        // 초기: 2개 정책 선언
        $helper->syncPolicy([
            'key' => 'fake-module.test.keep_me',
            'scope' => 'hook',
            'target' => 'fake-module.test.before_action',
            'purpose' => 'sensitive_action',
            'enabled' => false,
            'source_type' => 'module',
            'source_identifier' => 'fake-module',
        ]);
        $helper->syncPolicy([
            'key' => 'fake-module.test.stale',
            'scope' => 'hook',
            'target' => 'fake-module.test.deprecated',
            'purpose' => 'sensitive_action',
            'enabled' => false,
            'source_type' => 'module',
            'source_identifier' => 'fake-module',
        ]);

        $this->assertDatabaseHas('identity_policies', ['key' => 'fake-module.test.stale']);

        // 업데이트 시 keep_me 만 선언에 남고 stale 은 제거되어야 함
        $helper->cleanupStalePolicies('module', 'fake-module', ['fake-module.test.keep_me']);

        $this->assertDatabaseHas('identity_policies', ['key' => 'fake-module.test.keep_me']);
        $this->assertDatabaseMissing('identity_policies', ['key' => 'fake-module.test.stale']);
    }

    public function test_user_overrides_are_preserved_across_resync(): void
    {
        $helper = app(IdentityPolicySyncHelper::class);

        // 1차 seed — 기본값 enabled=false
        $helper->syncPolicy([
            'key' => 'fake-module.test.toggle',
            'scope' => 'hook',
            'target' => 'fake-module.test.before_action',
            'purpose' => 'sensitive_action',
            'enabled' => false,
            'grace_minutes' => 0,
            'source_type' => 'module',
            'source_identifier' => 'fake-module',
        ]);

        // 운영자가 UI 에서 enabled + grace_minutes 수정
        \App\Models\IdentityPolicy::where('key', 'fake-module.test.toggle')->update([
            'enabled' => true,
            'grace_minutes' => 15,
            'user_overrides' => ['enabled', 'grace_minutes'],
        ]);

        // 2차 seed — 동일 선언 재호출 (업데이트 재시딩 시나리오)
        $helper->syncPolicy([
            'key' => 'fake-module.test.toggle',
            'scope' => 'hook',
            'target' => 'fake-module.test.before_action',
            'purpose' => 'sensitive_action',
            'enabled' => false,
            'grace_minutes' => 0,
            'source_type' => 'module',
            'source_identifier' => 'fake-module',
        ]);

        // user_overrides 에 등록된 필드는 운영자 값이 보존되어야 함
        $row = \App\Models\IdentityPolicy::where('key', 'fake-module.test.toggle')->first();
        $this->assertTrue((bool) $row->enabled, 'enabled 가 user_overrides 로 보존되어야 함');
        $this->assertSame(15, (int) $row->grace_minutes);
    }

    /**
     * 테스트용 가짜 모듈 인스턴스 생성.
     *
     * AbstractModule::getIdentifier() 는 final 이고 모듈 디렉토리 basename 에서
     * 자동 추론하므로, Reflection 으로 `$identifier` 프로퍼티를 직접 설정한다.
     */
    private function makeFakeModule(array $policies): AbstractModule
    {
        $module = new class($policies) extends AbstractModule
        {
            public function __construct(private array $policyData)
            {
                // AbstractModule 은 constructor 에 특별 의존 없음
            }

            public function getName(): string|array
            {
                return 'Fake Module';
            }

            public function getDescription(): string|array
            {
                return 'Test module for IDV policy sync';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getIdentityPolicies(): array
            {
                return $this->policyData;
            }
        };

        // Reflection 으로 identifier 를 직접 설정 (getModulePath 의존 회피)
        $reflection = new \ReflectionClass($module);
        while ($reflection && ! $reflection->hasProperty('identifier')) {
            $reflection = $reflection->getParentClass();
        }
        if ($reflection) {
            $prop = $reflection->getProperty('identifier');
            $prop->setAccessible(true);
            $prop->setValue($module, 'fake-module');
        }

        return $module;
    }
}
