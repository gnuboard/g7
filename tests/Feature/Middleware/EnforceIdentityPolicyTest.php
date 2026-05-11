<?php

namespace Tests\Feature\Middleware;

use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * EnforceIdentityPolicy 미들웨어 E2E 테스트.
 *
 * 정책이 비활성이거나 grace 내 verified 가 있으면 200, 없으면 428 을 검증합니다.
 */
class EnforceIdentityPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['identifier' => 'user'], ['name' => ['ko' => '사용자', 'en' => 'User']]);
        $this->user = User::factory()->create(['email' => 'mw@example.com']);

        Route::middleware(['auth:sanctum', 'identity.policy:test.mw.policy'])
            ->get('/api/test/idv-guarded', fn () => response()->json(['ok' => true]));
    }

    public function test_policy_not_found_passes_through(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/test/idv-guarded');

        $response->assertStatus(200);
    }

    public function test_enabled_policy_without_verified_log_returns_428(): void
    {
        Sanctum::actingAs($this->user);

        IdentityPolicy::create([
            'key' => 'test.mw.policy',
            'scope' => 'route',
            'target' => 'api.test.mw',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'source_type' => 'admin',
            'source_identifier' => 'admin',
            'applies_to' => 'both',
            'fail_mode' => 'block',
        ]);

        $response = $this->getJson('/api/test/idv-guarded');

        $response->assertStatus(428)
            ->assertJsonPath('error_code', 'identity_verification_required')
            ->assertJsonPath('verification.policy_key', 'test.mw.policy');
    }

    public function test_grace_window_with_recent_verified_allows_request(): void
    {
        Sanctum::actingAs($this->user);

        IdentityPolicy::create([
            'key' => 'test.mw.policy',
            'scope' => 'route',
            'target' => 'api.test.mw',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 5,
            'enabled' => true,
            'source_type' => 'admin',
            'source_identifier' => 'admin',
            'applies_to' => 'both',
            'fail_mode' => 'block',
        ]);

        IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'sensitive_action',
            'channel' => 'email',
            'user_id' => $this->user->id,
            'target_hash' => hash('sha256', $this->user->email),
            'status' => IdentityVerificationStatus::Verified->value,
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->getJson('/api/test/idv-guarded');

        $response->assertStatus(200);
    }

    public function test_disabled_policy_passes_through(): void
    {
        Sanctum::actingAs($this->user);

        IdentityPolicy::create([
            'key' => 'test.mw.policy',
            'scope' => 'route',
            'target' => 'api.test.mw',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => false,
            'source_type' => 'admin',
            'source_identifier' => 'admin',
            'applies_to' => 'both',
            'fail_mode' => 'block',
        ]);

        $response = $this->getJson('/api/test/idv-guarded');

        $response->assertStatus(200);
    }
}
