<?php

namespace Tests\Feature\Api\Admin\Identity;

use App\Models\IdentityPolicy;
use App\Models\Role;
use App\Models\User;
use App\Services\IdentityPolicyService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S1d "기본값으로 되돌리기" (↺) 회귀 테스트.
 *
 * 계획서 요구 (plan L1296):
 *   운영자가 S1d 편집 모달에서 특정 필드의 ↺ 클릭 시
 *   → user_overrides 에서 해당 필드명 제거
 *   → 선언 기본값(config/core.php / module.php) 즉시 복원
 *
 * 버그 수정 프로토콜: 이 테스트는 기능 구현 전 fail 상태로 작성됨.
 */
class AdminIdentityPolicyResetOverrideTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    private IdentityPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(\Database\Seeders\IdentityPolicySeeder::class);

        $this->service = $this->app->make(IdentityPolicyService::class);

        $this->admin = User::factory()->create(['is_super' => true]);
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id, [
                'assigned_at' => now(),
                'assigned_by' => null,
            ]);
        }
        $this->admin = $this->admin->fresh();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    public function test_service_resetFieldOverride_removes_field_from_user_overrides_and_restores_default(): void
    {
        // core 기본 정책: core.profile.password_change 는 grace_minutes=5, enabled=true
        $policy = IdentityPolicy::where('key', 'core.profile.password_change')->first();
        $this->assertNotNull($policy, '코어 기본 정책이 시드되어야 함');

        // 운영자가 grace_minutes 를 15 로 변경 (user_overrides 기록됨)
        $this->service->updatePolicy($policy, ['grace_minutes' => 15]);
        $policy->refresh();
        $this->assertSame(15, (int) $policy->grace_minutes);
        $this->assertContains('grace_minutes', $policy->user_overrides ?? [], 'override 등록 선행 조건');

        // ↺ 기본값으로 되돌리기 — grace_minutes 만 복원
        $result = $this->service->resetFieldOverride($policy, 'grace_minutes');
        $this->assertTrue($result);

        $policy->refresh();
        // 복원된 기본값은 config/core.php 의 core.profile.password_change.grace_minutes = 5
        $this->assertSame(5, (int) $policy->grace_minutes, '선언 기본값으로 즉시 복원');
        $this->assertNotContains('grace_minutes', $policy->user_overrides ?? [], 'user_overrides 에서 제거');
    }

    public function test_service_resetFieldOverride_preserves_other_overridden_fields(): void
    {
        $policy = IdentityPolicy::where('key', 'core.profile.password_change')->first();

        // enabled 와 grace_minutes 둘 다 override
        $this->service->updatePolicy($policy, ['enabled' => false, 'grace_minutes' => 30]);
        $policy->refresh();
        $this->assertContains('enabled', $policy->user_overrides ?? []);
        $this->assertContains('grace_minutes', $policy->user_overrides ?? []);

        // enabled 만 리셋
        $this->service->resetFieldOverride($policy, 'enabled');
        $policy->refresh();

        $this->assertTrue((bool) $policy->enabled, 'enabled 기본값 복원');
        $this->assertSame(30, (int) $policy->grace_minutes, 'grace_minutes 는 override 유지');
        $this->assertNotContains('enabled', $policy->user_overrides ?? []);
        $this->assertContains('grace_minutes', $policy->user_overrides ?? []);
    }

    public function test_service_resetFieldOverride_rejects_unknown_field(): void
    {
        $policy = IdentityPolicy::where('key', 'core.profile.password_change')->first();

        $this->assertFalse($this->service->resetFieldOverride($policy, 'nonexistent_field'));
    }

    public function test_service_resetFieldOverride_only_permits_safe_fields(): void
    {
        $policy = IdentityPolicy::where('key', 'core.profile.password_change')->first();

        // key / scope / target 같은 구조 필드는 reset 대상에서 제외 (원래 override 도 불가)
        $this->assertFalse($this->service->resetFieldOverride($policy, 'key'));
        $this->assertFalse($this->service->resetFieldOverride($policy, 'scope'));
        $this->assertFalse($this->service->resetFieldOverride($policy, 'target'));
    }

    public function test_http_reset_field_requires_authentication(): void
    {
        $policy = IdentityPolicy::where('key', 'core.profile.password_change')->first();

        $this->postJson("/api/admin/identity/policies/{$policy->id}/reset-field", [
            'field' => 'grace_minutes',
        ])->assertStatus(401);
    }

    public function test_http_reset_field_resets_and_returns_fresh_policy(): void
    {
        $policy = IdentityPolicy::where('key', 'core.profile.password_change')->first();

        // 사전: 운영자 override 상태 세팅
        $this->service->updatePolicy($policy, ['grace_minutes' => 99]);

        $response = $this->authRequest()->postJson("/api/admin/identity/policies/{$policy->id}/reset-field", [
            'field' => 'grace_minutes',
        ]);

        $response->assertStatus(200);

        $policy->refresh();
        $this->assertSame(5, (int) $policy->grace_minutes);
        $this->assertNotContains('grace_minutes', $policy->user_overrides ?? []);
    }

    public function test_http_reset_field_validates_field_parameter(): void
    {
        $policy = IdentityPolicy::where('key', 'core.profile.password_change')->first();

        $this->authRequest()->postJson("/api/admin/identity/policies/{$policy->id}/reset-field", [])
            ->assertStatus(422);
    }
}
