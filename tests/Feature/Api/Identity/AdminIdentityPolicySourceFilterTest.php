<?php

namespace Tests\Feature\Api\Identity;

use App\Models\IdentityPolicy;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 코어 정책 인덱스 API 의 source_type / source_identifier 필터 회귀 테스트.
 *
 * 모듈/플러그인 환경설정 탭이 자기 컨텍스트의 정책만 조회하기 위해 사용.
 */
class AdminIdentityPolicySourceFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_filter_policies_by_source_type_module(): void
    {
        // 모듈/admin 정책 혼재 시드
        IdentityPolicy::create($this->makePolicy('sirsoft-board.x.delete', 'module', 'sirsoft-board'));
        IdentityPolicy::create($this->makePolicy('sirsoft-ecommerce.x.cancel', 'module', 'sirsoft-ecommerce'));
        IdentityPolicy::create($this->makePolicy('admin.custom.rule_1', 'admin', 'admin'));

        $token = $this->createAdmin()->createToken('test')->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/identity/policies?source_type=module&source_identifier=sirsoft-board&per_page=50');

        $response->assertStatus(200);
        $keys = collect($response->json('data.data') ?? [])->pluck('key')->all();
        $this->assertContains('sirsoft-board.x.delete', $keys);
        $this->assertNotContains('sirsoft-ecommerce.x.cancel', $keys, 'source_identifier 필터가 작동해 다른 모듈 정책은 제외되어야 함');
        $this->assertNotContains('admin.custom.rule_1', $keys);
    }

    public function test_admin_filter_with_only_source_type_includes_all_modules(): void
    {
        IdentityPolicy::create($this->makePolicy('sirsoft-board.x.delete', 'module', 'sirsoft-board'));
        IdentityPolicy::create($this->makePolicy('sirsoft-ecommerce.x.cancel', 'module', 'sirsoft-ecommerce'));

        $token = $this->createAdmin()->createToken('test')->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/identity/policies?source_type=module&per_page=50');

        $response->assertStatus(200);
        $keys = collect($response->json('data.data') ?? [])->pluck('key')->all();
        $this->assertContains('sirsoft-board.x.delete', $keys);
        $this->assertContains('sirsoft-ecommerce.x.cancel', $keys);
    }

    public function test_store_admin_policy_with_module_source_identifier(): void
    {
        $token = $this->createAdmin()->createToken('test')->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/identity/policies', [
                'key' => 'admin.custom.module_owned',
                'scope' => 'route',
                'target' => 'api.something.update',
                'purpose' => 'sensitive_action',
                'grace_minutes' => 0,
                'enabled' => true,
                'priority' => 100,
                'applies_to' => 'admin',
                'fail_mode' => 'block',
                'source_identifier' => 'sirsoft-board',
            ]);

        $response->assertStatus(201);

        $policy = IdentityPolicy::query()->where('key', 'admin.custom.module_owned')->first();
        $this->assertNotNull($policy);
        $this->assertSame('admin', $policy->source_type->value);
        $this->assertSame('sirsoft-board', $policy->source_identifier);
    }

    public function test_store_admin_policy_rejects_invalid_source_identifier_format(): void
    {
        $token = $this->createAdmin()->createToken('test')->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/identity/policies', [
                'key' => 'admin.custom.bad_format',
                'scope' => 'route',
                'target' => 'api.something.update',
                'purpose' => 'sensitive_action',
                'grace_minutes' => 0,
                'enabled' => true,
                'applies_to' => 'admin',
                'fail_mode' => 'block',
                // 정규식 위반: 대문자 + 공백 + 특수문자 — raw identifier 컨벤션(/^[a-z][a-z0-9_\-]*$/) 미충족.
                'source_identifier' => 'Invalid Source!',
            ]);

        $response->assertStatus(422);
    }

    /**
     * @return array<string, mixed>
     */
    private function makePolicy(string $key, string $sourceType, string $sourceIdentifier): array
    {
        return [
            'key' => $key,
            'scope' => 'route',
            'target' => 'api.test',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => false,
            'priority' => 100,
            'applies_to' => 'admin',
            'fail_mode' => 'block',
            'source_type' => $sourceType,
            'source_identifier' => $sourceIdentifier,
        ];
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create(['is_super' => true]);
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $admin->roles()->attach($adminRole->id, [
                'assigned_at' => now(),
                'assigned_by' => null,
            ]);
        }

        return $admin->fresh();
    }
}
