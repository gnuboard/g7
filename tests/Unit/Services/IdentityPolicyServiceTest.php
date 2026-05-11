<?php

namespace Tests\Unit\Services;

use App\Enums\IdentityVerificationStatus;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\IdentityPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * IdentityPolicyService 테스트.
 *
 * resolve/enforce 동작 및 grace_minutes/fail_mode 분기를 검증합니다.
 */
class IdentityPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private IdentityPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(IdentityPolicyService::class);
    }

    public function test_resolve_returns_policy_by_scope_target(): void
    {
        $policy = IdentityPolicy::create([
            'key' => 'test.policy.a',
            'scope' => 'route',
            'target' => 'api.test.one',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'source_type' => 'admin',
            'source_identifier' => 'admin',
            'applies_to' => 'both',
            'fail_mode' => 'block',
        ]);

        $resolved = $this->service->resolve('route', 'api.test.one');

        $this->assertNotNull($resolved);
        $this->assertSame($policy->key, $resolved->key);
    }

    public function test_resolve_returns_null_when_no_match(): void
    {
        $resolved = $this->service->resolve('route', 'api.nonexistent');
        $this->assertNull($resolved);
    }

    public function test_enforce_throws_when_no_recent_verified(): void
    {
        $policy = $this->makePolicy(['grace_minutes' => 0]);

        $this->expectException(IdentityVerificationRequiredException::class);
        $this->service->enforce($policy, null, ['target_email' => 'x@example.com']);
    }

    public function test_enforce_passes_when_recent_verified_exists(): void
    {
        $policy = $this->makePolicy(['grace_minutes' => 5]);
        $user = User::factory()->create();

        IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'sensitive_action',
            'channel' => 'email',
            'user_id' => $user->id,
            'target_hash' => hash('sha256', mb_strtolower($user->email)),
            'status' => IdentityVerificationStatus::Verified->value,
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        // 예외가 발생하지 않으면 통과
        $this->service->enforce($policy, $user, []);
        $this->assertTrue(true);
    }

    public function test_enforce_log_only_does_not_throw_but_records_log(): void
    {
        $policy = $this->makePolicy(['grace_minutes' => 0, 'fail_mode' => 'log_only']);

        $this->service->enforce($policy, null, ['target_email' => 'x@example.com']);

        $this->assertDatabaseHas('identity_verification_logs', [
            'status' => IdentityVerificationStatus::PolicyViolationLogged->value,
            'origin_policy_key' => $policy->key,
        ]);
    }

    public function test_enforce_skips_when_policy_disabled(): void
    {
        $policy = $this->makePolicy(['enabled' => false]);

        // 예외 없이 통과해야 함
        $this->service->enforce($policy, null, ['target_email' => 'x@example.com']);
        $this->assertTrue(true);
    }

    /**
     * applies_to=self 정책은 admin 사용자(context.user_is_admin=true)에게는 enforce 하지 않습니다.
     */
    public function test_enforce_skips_admin_user_when_applies_to_self(): void
    {
        $policy = $this->makePolicy(['grace_minutes' => 0, 'applies_to' => 'self']);

        $this->service->enforce($policy, null, [
            'target_email' => 'x@example.com',
            'user_is_admin' => true,
        ]);
        $this->assertTrue(true);
    }

    /**
     * applies_to=admin 정책은 일반 사용자(user_is_admin=false)에게는 enforce 하지 않습니다.
     */
    public function test_enforce_skips_non_admin_when_applies_to_admin(): void
    {
        $policy = $this->makePolicy(['grace_minutes' => 0, 'applies_to' => 'admin']);

        $this->service->enforce($policy, null, [
            'target_email' => 'x@example.com',
            'user_is_admin' => false,
        ]);
        $this->assertTrue(true);
    }

    /**
     * applies_to=admin + admin context — 일반 enforce 흐름 적용 (verified 없으면 throw).
     */
    public function test_enforce_throws_for_admin_when_applies_to_admin_and_no_verified(): void
    {
        $policy = $this->makePolicy(['grace_minutes' => 0, 'applies_to' => 'admin']);

        $this->expectException(IdentityVerificationRequiredException::class);
        $this->service->enforce($policy, null, [
            'target_email' => 'x@example.com',
            'user_is_admin' => true,
        ]);
    }

    /**
     * applies_to=both — admin/일반 무관 enforce 흐름 적용.
     */
    public function test_enforce_applies_to_both_targets_everyone(): void
    {
        $policy = $this->makePolicy(['grace_minutes' => 0, 'applies_to' => 'both']);

        $this->expectException(IdentityVerificationRequiredException::class);
        $this->service->enforce($policy, null, [
            'target_email' => 'x@example.com',
            'user_is_admin' => true,
        ]);
    }

    /**
     * conditions.signup_stage 매칭 — context 가 일치할 때만 정책 선택됨.
     */
    public function test_resolve_matches_signup_stage_condition(): void
    {
        IdentityPolicy::create([
            'key' => 'test.signup.before',
            'scope' => 'route',
            'target' => 'api.test.signup',
            'purpose' => 'signup',
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 110,
            'source_type' => 'admin',
            'source_identifier' => 'admin',
            'applies_to' => 'self',
            'fail_mode' => 'block',
            'conditions' => ['signup_stage' => 'before_submit'],
        ]);

        $matched = $this->service->resolve('route', 'api.test.signup', ['signup_stage' => 'before_submit']);
        $this->assertNotNull($matched);
        $this->assertSame('test.signup.before', $matched->key);

        $miss = $this->service->resolve('route', 'api.test.signup', ['signup_stage' => 'after_create']);
        $this->assertNull($miss);
    }

    /**
     * conditions.signup_stage 미설정 정책 — context 무관 매칭 (기존 동작).
     */
    public function test_resolve_signup_stage_unset_matches_any_context(): void
    {
        IdentityPolicy::create([
            'key' => 'test.signup.any',
            'scope' => 'route',
            'target' => 'api.test.signup_any',
            'purpose' => 'signup',
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'source_type' => 'admin',
            'source_identifier' => 'admin',
            'applies_to' => 'self',
            'fail_mode' => 'block',
        ]);

        $resolved = $this->service->resolve('route', 'api.test.signup_any', ['signup_stage' => 'any_value']);
        $this->assertNotNull($resolved);
    }

    /**
     * filter 훅이 IdentityPolicy 외 타입(null/string)을 반환해도 원본 정책이 유지되어야 합니다 (보안).
     */
    public function test_resolve_filter_hook_invalid_return_type_keeps_original(): void
    {
        $policy = IdentityPolicy::create([
            'key' => 'test.filter.original',
            'scope' => 'route',
            'target' => 'api.test.filter',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'source_type' => 'admin',
            'source_identifier' => 'admin',
            'applies_to' => 'both',
            'fail_mode' => 'block',
        ]);

        \App\Extension\HookManager::addFilter(
            'core.identity.resolve_policy',
            fn ($current) => null,  // 의도적 우회 시도
            10,
        );

        try {
            $resolved = $this->service->resolve('route', 'api.test.filter');

            $this->assertNotNull($resolved, 'filter 훅이 null 반환해도 원본 정책이 유지되어야 함');
            $this->assertSame($policy->key, $resolved->key);
        } finally {
            \App\Extension\HookManager::clearFilter('core.identity.resolve_policy');
        }
    }

    private function makePolicy(array $overrides = []): IdentityPolicy
    {
        return IdentityPolicy::create(array_merge([
            'key' => 'test.policy.'.Str::random(4),
            'scope' => 'route',
            'target' => 'api.test.any',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'source_type' => 'admin',
            'source_identifier' => 'admin',
            'applies_to' => 'both',
            'fail_mode' => 'block',
        ], $overrides));
    }

    /**
     * resetFieldOverride('conditions') — B안 리팩토링.
     *
     * 운영자가 conditions 를 수정한 후 "↺ 기본값으로 되돌리기" 클릭 시
     * declared default 가 복원되고 user_overrides 에서 'conditions' 가 제거되어야 한다.
     */
    public function test_reset_field_override_restores_declared_conditions(): void
    {
        // 주의: policy key 에 dot 가 포함되므로 config dot-notation 을 회피하기 위해
        // 'core.identity_policies' 블록을 통째로 설정한다 (Service::findDeclaredDefault 와 동형).
        config([
            'core.identity_policies' => [
                'test.reset.conditions' => [
                    'key' => 'test.reset.conditions',
                    'scope' => 'route',
                    'target' => 'api.auth.register',
                    'purpose' => 'signup',
                    'conditions' => ['signup_stage' => 'before_submit'],
                    'grace_minutes' => 0,
                    'enabled' => true,
                    'priority' => 100,
                    'applies_to' => 'self',
                    'fail_mode' => 'block',
                ],
            ],
        ]);

        $policy = IdentityPolicy::create([
            'key' => 'test.reset.conditions',
            'scope' => 'route',
            'target' => 'api.auth.register',
            'purpose' => 'signup',
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'source_type' => 'core',
            'source_identifier' => 'core',
            'applies_to' => 'self',
            'fail_mode' => 'block',
            'conditions' => ['signup_stage' => 'after_create'],
            'user_overrides' => ['conditions'],
        ]);

        $result = $this->service->resetFieldOverride($policy, 'conditions');

        $this->assertTrue($result);

        $fresh = $policy->fresh();
        $this->assertSame(['signup_stage' => 'before_submit'], $fresh->conditions);
        $this->assertNotContains('conditions', $fresh->user_overrides ?? []);
    }
}
