<?php

namespace Tests\Feature\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Extension\IdentityVerification\Providers\MailIdentityProvider;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\IdentityVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 메일 인증이 회원의 실명 확인 기록(identity_hash)을 지우지 않는지 검증한다 (KVE 동형 B7).
 */
class IdentityHashPreservationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 실명 정보를 돌려주지 않는 메일 인증을 마쳐도 기존 identity_hash 는 그대로다 (수정 전: null 로 덮임).
     *
     * @effects identity_hash_preserved
     *
     * @return void 반환값 없음
     */
    public function test_mail_verification_keeps_existing_identity_hash(): void
    {
        $user = User::factory()->create(['identity_hash' => str_repeat('a', 64)]);
        $log = IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => MailIdentityProvider::ID,
            'purpose' => 'sensitive_action',
            'channel' => 'email',
            'user_id' => $user->id,
            'target_hash' => hash('sha256', mb_strtolower($user->email)),
            'status' => IdentityVerificationStatus::Sent->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 5,
            'metadata' => ['code_hash' => Hash::make('246810')],
            'expires_at' => now()->addMinutes(15),
        ]);

        $result = app(IdentityVerificationService::class)->verify($log->id, ['code' => '246810']);

        $this->assertTrue($result->success);
        $fresh = $user->fresh();
        $this->assertSame(str_repeat('a', 64), $fresh->identity_hash);
        $this->assertSame(MailIdentityProvider::ID, $fresh->identity_verified_provider);
    }
}
