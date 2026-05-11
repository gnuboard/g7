<?php

namespace Tests\Feature\Seeders;

use App\Models\IdentityPolicy;
use Database\Seeders\IdentityPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IdentityPolicySeeder 통합 테스트.
 *
 * config/core.php.identity_policies 의 9종 선언이 DB 로 동기화되는지 검증합니다.
 * 7.0.0-beta.4 통합 후: signup_before_submit + signup_after_create 추가, password_reset 기본 OFF 로 변경.
 */
class IdentityPolicySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_syncs_all_declared_policies(): void
    {
        $this->seed(IdentityPolicySeeder::class);

        $expectedKeys = [
            'core.auth.signup_before_submit',
            'core.auth.signup_after_create',
            'core.auth.password_reset',
            'core.profile.password_change',
            'core.profile.contact_change',
            'core.account.withdraw',
            'core.admin.app_key_regenerate',
            'core.admin.user_delete',
            'core.admin.extension_uninstall',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertDatabaseHas('identity_policies', [
                'key' => $key,
                'source_type' => 'core',
            ]);
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(IdentityPolicySeeder::class);
        $firstCount = IdentityPolicy::count();

        $this->seed(IdentityPolicySeeder::class);
        $secondCount = IdentityPolicy::count();

        $this->assertSame($firstCount, $secondCount, 'Seeder 재실행 시 중복 생성되지 않아야 합니다');
    }

    public function test_default_enabled_policies_are_active(): void
    {
        $this->seed(IdentityPolicySeeder::class);

        foreach ([
            'core.profile.password_change',
            'core.profile.contact_change',
            'core.account.withdraw',
        ] as $key) {
            $policy = IdentityPolicy::where('key', $key)->first();
            $this->assertNotNull($policy);
            $this->assertTrue((bool) $policy->enabled, "{$key} should be enabled by default");
        }
    }

    public function test_default_disabled_policies_are_inactive(): void
    {
        $this->seed(IdentityPolicySeeder::class);

        foreach ([
            'core.auth.signup_before_submit',
            'core.auth.signup_after_create',
            'core.auth.password_reset',
            'core.admin.app_key_regenerate',
            'core.admin.user_delete',
            'core.admin.extension_uninstall',
        ] as $key) {
            $policy = IdentityPolicy::where('key', $key)->first();
            $this->assertNotNull($policy);
            $this->assertFalse((bool) $policy->enabled, "{$key} should be disabled by default (운영자 opt-in)");
        }
    }
}
