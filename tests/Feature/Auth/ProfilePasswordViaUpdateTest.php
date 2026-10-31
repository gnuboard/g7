<?php

namespace Tests\Feature\Auth;

use App\Enums\IdentityVerificationStatus;
use App\Jobs\DispatchHookListenerJob;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Database\Seeders\IdentityPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 정보수정 API 의 비밀번호 변경 보강 테스트 (#736 D18)
 *
 * `PUT /api/me` 로 비밀번호를 함께 바꿀 때도 전용 경로와 같은 본인인증 정책(`core.profile.password_change`)을
 * 같은 판정으로 걸고, 현재 비밀번호 원문이 훅 인자(큐 작업 표)와 서버 로그에 남지 않는지 본다.
 */
class ProfilePasswordViaUpdateTest extends TestCase
{
    use PasswordPolicyFixtures;
    use RefreshDatabase;

    /**
     * 본인인증 정책을 시드합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IdentityPolicySeeder::class);
    }

    /**
     * 정보수정 API 로 비밀번호를 함께 바꿉니다.
     *
     * @param  User  $user  회원
     * @return TestResponse 응답
     */
    private function updateWithPassword(User $user): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->putJson('/api/me', [
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => 'Passw0rd!old',
            'password' => 'Passw0rd!new',
            'password_confirmation' => 'Passw0rd!new',
        ]);
    }

    /**
     * 본인인증 없이 비밀번호를 바꾸려 하면 428 이고, 유효시간 안의 본인인증이 있으면 통과한다.
     *
     * @scenario entry_point=update_profile, idv=none|recent, actor=member
     *
     * @effects profile_update_password_enforces_idv_policy
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function password_change_via_profile_update_requires_identity_verification(): void
    {
        $user = $this->makeMember();

        $this->updateWithPassword($user)->assertStatus(428);
        $this->assertTrue(Hash::check('Passw0rd!old', $user->fresh()->password), '428 인데 비밀번호가 바뀌었다');

        $this->recordVerified($user);
        $this->updateWithPassword($user)->assertOk();
        $this->assertTrue(Hash::check('Passw0rd!new', $user->fresh()->password));
    }

    /**
     * 관리자는 정책 적용 대상(본인 회원)이 아니라 통과한다 — 라우트 미들웨어와 같은 판정.
     *
     * @scenario entry_point=update_profile, idv=none, actor=admin
     *
     * @effects profile_update_password_enforces_idv_policy
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function admins_are_not_subject_to_the_self_policy(): void
    {
        $this->updateWithPassword($this->makeAdmin())->assertOk();
    }

    /**
     * 비밀번호 없이 정보만 바꾸면 정책을 걸지 않는다.
     *
     * @scenario entry_point=update_profile, idv=none, password=absent
     *
     * @effects profile_update_without_password_unaffected
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function profile_updates_without_a_password_are_unaffected(): void
    {
        $user = $this->makeMember();
        Sanctum::actingAs($user);

        $this->putJson('/api/me', ['name' => '새 이름', 'email' => $user->email])->assertOk();
    }

    /**
     * 현재 비밀번호 원문이 수정 완료 훅의 큐 작업 인자와 서버 로그에 남지 않는다.
     *
     * @scenario entry_point=update_profile, idv=recent, surface=queue_payload|info_log
     *
     * @effects password_originals_never_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function password_originals_are_kept_out_of_hook_payloads_and_logs(): void
    {
        $user = $this->makeMember();
        $this->recordVerified($user);

        Queue::fake();
        Log::spy();

        $this->updateWithPassword($user)->assertOk();

        Queue::assertPushed(DispatchHookListenerJob::class, function (DispatchHookListenerJob $job) {
            $payload = json_encode($job->serializedArgs);

            $this->assertStringNotContainsString('Passw0rd!old', (string) $payload, '현재 비밀번호 원문이 큐 작업에 실렸다');
            $this->assertStringNotContainsString('Passw0rd!new', (string) $payload, '새 비밀번호 원문이 큐 작업에 실렸다');

            return true;
        });

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) {
            if ($message === 'User Activity: profile.update') {
                $this->assertArrayNotHasKey('current_password', $context['data'] ?? []);
                $this->assertArrayNotHasKey('password', $context['data'] ?? []);
                $this->assertArrayNotHasKey('password_confirmation', $context['data'] ?? []);
            }

            return true;
        });
    }

    /**
     * 회원의 최근 본인인증 성공 기록을 남깁니다.
     *
     * @param  User  $user  회원
     * @return void 반환값 없음
     */
    private function recordVerified(User $user): void
    {
        $when = now()->subMinute();

        IdentityVerificationLog::create([
            'id' => Str::uuid()->toString(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'sensitive_action',
            'channel' => 'email',
            'user_id' => $user->id,
            'target_hash' => hash('sha256', mb_strtolower($user->email)),
            'status' => IdentityVerificationStatus::Verified->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 5,
            'verified_at' => $when,
            'expires_at' => $when->copy()->addMinutes(15),
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }
}
