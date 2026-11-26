<?php

namespace Tests\Feature\Identity;

use App\Exceptions\IdentityVerificationRequiredException;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Identity\IdentityCheckpointRegistry;
use App\Models\IdentityPolicy;
use App\Models\User;
use App\Services\IdentityPolicyService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\Support\Concerns\GrantsExtensionAdminToken;
use Tests\TestCase;

/**
 * 본인인증 「사용자 지정」 체크포인트 (7.2.1)
 */
class IdentityCheckpointTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsExtensionAdminToken;

    /** 테스트 체크포인트 키 */
    private const KEY = 'i952-fixture.withdraw';

    /** @var callable|null 걸어 둔 필터 */
    private $filter = null;

    /**
     * 체크포인트 하나를 필터로 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = function (array $checkpoints) {
            $checkpoints[self::KEY] = [
                'key' => self::KEY,
                'name' => ['ko' => '픽스처 탈퇴', 'en' => 'Fixture withdraw'],
                'source_type' => 'module',
                'source_identifier' => 'i952-fixture',
            ];

            return $checkpoints;
        };
        HookManager::addFilter('core.identity.filter_checkpoints', $this->filter, 10);
        app(IdentityCheckpointRegistry::class)->flush();
    }

    /**
     * 필터를 걷습니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::removeFilter('core.identity.filter_checkpoints', $this->filter);
        app(IdentityCheckpointRegistry::class)->flush();
        parent::tearDown();
    }

    /**
     * 활성 확장의 getIdentityCheckpoints() 선언을 모은다 — 형식이 틀린 키는 버리고, 같은 키는 먼저 선언한 확장을 둔다.
     *
     * @scenario case=identity_checkpoint_collect
     *
     * @effects identity_checkpoints_collected
     *
     * @return void 반환값 없음
     */
    public function test_registry_collects_extension_declarations(): void
    {
        $extension = fn (string $id, array $declared) => new class($id, $declared)
        {
            /**
             * 확장 식별자와 체크포인트 선언을 받는다.
             *
             * @param  string  $id  확장 식별자
             * @param  array<int, array<string, mixed>>  $declared  체크포인트 선언
             */
            public function __construct(private string $id, private array $declared) {}

            /**
             * 확장 식별자를 반환한다.
             *
             * @return string 확장 식별자
             */
            public function getIdentifier(): string
            {
                return $this->id;
            }

            /**
             * 체크포인트 선언을 반환한다.
             *
             * @return array<int, array<string, mixed>> 선언
             */
            public function getIdentityCheckpoints(): array
            {
                return $this->declared;
            }
        };
        $modules = Mockery::mock(ModuleManager::class);
        $modules->shouldReceive('getActiveModules')->andReturn([
            $extension('vendor-a', [['key' => 'vendor-a.checkout', 'name' => '결제'], ['key' => 'BAD KEY']]),
            $extension('vendor-b', [['key' => 'vendor-a.checkout', 'name' => '중복']]),
        ]);
        $plugins = Mockery::mock(PluginManager::class);
        $plugins->shouldReceive('getActivePlugins')->andReturn([$extension('plugin-c', [['key' => 'plugin-c.export']])]);

        $registry = new IdentityCheckpointRegistry($modules, $plugins);
        $all = $registry->all();

        $this->assertArrayHasKey('vendor-a.checkout', $all);
        $this->assertSame('vendor-a', $all['vendor-a.checkout']['source_identifier']);
        $this->assertSame('plugin', $all['plugin-c.export']['source_type']);
        $this->assertArrayNotHasKey('BAD KEY', $all);
        $this->assertArrayHasKey(self::KEY, $all, '필터로 더한 체크포인트도 포함된다');
    }

    /**
     * 목록 API 는 등록된 체크포인트를 준다.
     *
     * @scenario case=identity_checkpoint_list_api
     *
     * @effects identity_checkpoints_listed
     *
     * @return void 반환값 없음
     */
    public function test_checkpoints_api_lists_registered_keys(): void
    {
        $token = $this->extensionAdminToken(['core.admin.identity.policies.read']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/admin/identity/checkpoints')->assertOk();

        $this->assertContains(self::KEY, array_column($response->json('data.checkpoints') ?? [], 'key'));
    }

    /**
     * 「사용자 지정」 정책은 등록된 키만 대상으로 저장할 수 있다 — 미등록 키는 422, 등록 키는 201 이고 checkpoint_registered=true.
     *
     * @scenario case=identity_checkpoint_policy_validation
     *
     * @effects identity_custom_policy_target_validated
     *
     * @return void 반환값 없음
     */
    public function test_custom_policy_target_must_be_registered(): void
    {
        $token = $this->extensionAdminToken(['core.admin.identity.policies.read', 'core.admin.identity.policies.update', 'core.admin.identity.policies.create']);
        $body = fn (string $key, string $target) => [
            'key' => $key,
            'scope' => 'custom',
            'target' => $target,
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'applies_to' => 'both',
            'fail_mode' => 'block',
        ];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/identity/policies', $body('i952.custom.bad', 'nobody.declared'))
            ->assertStatus(422)->assertJsonValidationErrors('target');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/identity/policies', $body('i952.custom.ok', self::KEY))
            ->assertStatus(201)
            ->assertJsonPath('data.checkpoint_registered', true);
    }

    /**
     * enforceCheckpoint — 연결된 정책이 있으면 강제하고, 없으면 지나가며, 등록되지 않은 키는 개발자 오류로 예외다.
     *
     * @scenario case=identity_checkpoint_enforce
     *
     * @effects identity_checkpoint_enforced
     *
     * @return void 반환값 없음
     */
    public function test_enforce_checkpoint(): void
    {
        $service = app(IdentityPolicyService::class);
        $user = User::factory()->create();

        // 연결된 정책이 없으면 통과
        $service->enforceCheckpoint(self::KEY, $user);

        try {
            $service->enforceCheckpoint('nobody.declared', $user);
            $this->fail('미등록 키가 예외 없이 지나갔다');
        } catch (\App\Exceptions\IdentityCheckpointNotRegisteredException $e) {
            $this->assertSame('nobody.declared', $e->checkpointKey);
            $this->assertStringContainsString('nobody.declared', $e->getMessage());
        }

        IdentityPolicy::create([
            'key' => 'i952.custom.enforce',
            'scope' => 'custom',
            'target' => self::KEY,
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'source_type' => 'admin',
            'source_identifier' => 'admin',
            'applies_to' => 'both',
            'fail_mode' => 'block',
        ]);

        $this->expectException(IdentityVerificationRequiredException::class);
        $service->enforceCheckpoint(self::KEY, $user);
    }
}
