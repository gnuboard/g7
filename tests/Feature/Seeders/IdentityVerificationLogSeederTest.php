<?php

namespace Tests\Feature\Seeders;

use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\IdentityPolicySeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\Sample\IdentityVerificationLogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 코어 IdentityVerificationLogSeeder 통합 테스트.
 *
 * 코어 정책(source_type='core')만 채워지는지 + 라이프사이클 일관성 검증.
 */
class IdentityVerificationLogSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 샘플 시더 의존성(역할/사용자/IdentityPolicy)을 부트스트랩한다.
     */
    private function bootstrapDependencies(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(IdentityPolicySeeder::class);

        $userRole = Role::query()->where('identifier', 'user')->firstOrFail();
        User::factory()
            ->count(15)
            ->create()
            ->each(fn (User $u) => $u->roles()->attach($userRole->id, ['assigned_at' => now()]));
    }

    public function test_seeder_creates_default_count(): void
    {
        $this->bootstrapDependencies();

        $this->seed(IdentityVerificationLogSeeder::class);

        $this->assertSame(100, IdentityVerificationLog::count(), '코어 시더 기본 100건이 생성되어야 합니다');
    }

    public function test_seeder_respects_count_option(): void
    {
        $this->bootstrapDependencies();

        $this->artisan('db:seed', [
            '--class' => IdentityVerificationLogSeeder::class,
            '--count' => ['core_identity_verification_logs=30'],
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(30, IdentityVerificationLog::count());
    }

    public function test_seeder_only_seeds_core_scope(): void
    {
        $this->bootstrapDependencies();

        $this->seed(IdentityVerificationLogSeeder::class);

        // 코어 시더는 source_type='core' 정책에서만 origin_policy_key 를 채우므로
        // origin_policy_key 가 코어 정책 키 집합 안에 있어야 한다.
        $corePolicyKeys = IdentityPolicy::query()
            ->where('source_type', 'core')
            ->pluck('key')
            ->all();

        $logs = IdentityVerificationLog::query()->get();
        $this->assertCount(100, $logs);
        foreach ($logs as $log) {
            $this->assertContains(
                $log->origin_policy_key,
                $corePolicyKeys,
                '코어 시더가 채운 로그의 origin_policy_key 는 코어 정책 집합에 속해야 합니다',
            );
        }
    }

    public function test_seeder_uses_real_users_and_core_policies(): void
    {
        $this->bootstrapDependencies();

        $this->seed(IdentityVerificationLogSeeder::class);

        $userIds = User::query()->pluck('id')->all();
        $corePolicyKeys = IdentityPolicy::query()->where('source_type', 'core')->pluck('key')->all();

        $logs = IdentityVerificationLog::query()->get();

        $this->assertNotEmpty($logs);
        foreach ($logs as $log) {
            $this->assertContains($log->user_id, $userIds);
            $this->assertContains($log->origin_policy_key, $corePolicyKeys);
            $this->assertSame('email', $log->channel);
            $this->assertSame('g7:core.mail', $log->provider_id);
        }
    }

    public function test_lifecycle_consistency_per_status(): void
    {
        $this->bootstrapDependencies();

        $this->seed(IdentityVerificationLogSeeder::class);

        foreach (IdentityVerificationLog::query()->where('status', IdentityVerificationStatus::Verified->value)->get() as $log) {
            $this->assertNotNull($log->verified_at);
            $this->assertGreaterThanOrEqual(1, $log->attempts);
            $this->assertNotNull($log->verification_token);
        }

        foreach (IdentityVerificationLog::query()->where('status', IdentityVerificationStatus::Failed->value)->get() as $log) {
            $this->assertSame($log->max_attempts, $log->attempts);
            $this->assertNull($log->verified_at);
        }

        foreach (IdentityVerificationLog::query()->where('status', IdentityVerificationStatus::PolicyViolationLogged->value)->get() as $log) {
            $this->assertNull($log->expires_at);
        }
    }

    public function test_distribution_skews_toward_verified(): void
    {
        $this->bootstrapDependencies();

        $this->seed(IdentityVerificationLogSeeder::class);

        $statuses = IdentityVerificationLog::query()->pluck('status')->countBy();

        $verified = $statuses[IdentityVerificationStatus::Verified->value] ?? 0;
        $failed = $statuses[IdentityVerificationStatus::Failed->value] ?? 0;

        $this->assertGreaterThan($failed, $verified);
    }

    /**
     * 회귀: origin_type 컬럼은 IdentityOriginType enum cast 적용.
     * 시더가 source_type 값(core/module/plugin/admin)을 origin_type 에 잘못 저장하면
     * 이력 조회 시 ValueError throw — 관리자 화면 전체 마비.
     *
     * 본 테스트는 시더 산출물 전체에 대해 enum cast 가 정상 작동하는지(= 모든 origin_type 값이
     * IdentityOriginType 에 정의된 케이스) 검증한다.
     */
    public function test_seeded_origin_types_are_valid_enum_cases(): void
    {
        $this->bootstrapDependencies();

        $this->seed(IdentityVerificationLogSeeder::class);

        $logs = IdentityVerificationLog::query()->get();
        $this->assertNotEmpty($logs);
        foreach ($logs as $log) {
            // cast 가 enum 으로 변환 — 잘못된 값이면 here 에서 ValueError throw
            $this->assertInstanceOf(\App\Enums\IdentityOriginType::class, $log->origin_type);
        }
    }

    public function test_target_hash_matches_user_email(): void
    {
        $this->bootstrapDependencies();

        $this->seed(IdentityVerificationLogSeeder::class);

        $logs = IdentityVerificationLog::query()->with('user')->take(20)->get();

        foreach ($logs as $log) {
            $this->assertNotNull($log->user);
            $expected = hash('sha256', mb_strtolower($log->user->email));
            $this->assertSame($expected, $log->target_hash);
        }
    }
}
