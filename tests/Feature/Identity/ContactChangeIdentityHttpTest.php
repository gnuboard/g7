<?php

namespace Tests\Feature\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Database\Seeders\IdentityPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 연락처 변경 본인인증 정책(`core.profile.contact_change`)의 HTTP 경로 테스트 (#736 D21)
 *
 * 정책은 `core.user.before_update` 훅에 걸리고 조건이 "바뀐 항목에 이메일·전화·휴대폰이 있다" 이다. 리스너가 바뀐
 * 항목을 저장 전 모델의 `getDirty()` 로 읽어(훅은 모델을 채우기 전에 발행된다) 항상 빈 목록이 되어 기본 켜진 정책이
 * 한 번도 발동하지 않았다. 바뀐 항목은 이제 "요청값 vs 기존값" 으로 계산한다.
 */
class ContactChangeIdentityHttpTest extends TestCase
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
     * 정보수정 API 로 회원 정보를 저장합니다.
     *
     * @param  User  $user  회원
     * @param  array<string, mixed>  $changes  바꿀 입력
     * @return TestResponse 응답
     */
    private function updateProfile(User $user, array $changes): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->putJson('/api/me', array_merge([
            'name' => $user->name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'phone' => $user->phone,
        ], $changes));
    }

    /**
     * 본인인증 완료 기록을 남깁니다.
     *
     * @param  User  $user  회원
     * @param  int  $minutesAgo  몇 분 전에 인증했는지
     * @param  string|null  $token  재요청에 붙일 인증 토큰
     * @return void 반환값 없음
     */
    private function recordVerified(User $user, int $minutesAgo = 1, ?string $token = null): void
    {
        $when = now()->subMinutes($minutesAgo);

        IdentityVerificationLog::create([
            'id' => Str::uuid()->toString(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'sensitive_action',
            'channel' => 'email',
            'user_id' => $token === null ? $user->id : null,
            'target_hash' => hash('sha256', mb_strtolower($user->email)),
            'status' => IdentityVerificationStatus::Verified->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 5,
            'verification_token' => $token,
            'verified_at' => $when,
            'expires_at' => $when->copy()->addMinutes(15),
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }

    /**
     * 휴대폰·전화·이메일을 바꾸면 본인인증을 요구하고(428) 값은 저장되지 않는다.
     *
     * @scenario entry_point=update_profile, changed=mobile|phone|email, idv=none, actor=member
     *
     * @effects contact_change_requires_identity_verification
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function changing_contact_fields_requires_identity_verification(): void
    {
        $user = $this->makeMember(['mobile' => '010-1111-2222', 'phone' => '02-111-2222']);

        $this->updateProfile($user, ['mobile' => '010-9999-8888'])
            ->assertStatus(428)
            ->assertJsonPath('error_code', 'identity_verification_required');
        $this->assertSame('010-1111-2222', $user->fresh()->mobile, '428 인데 휴대폰 번호가 저장됐다');

        $this->updateProfile($user, ['phone' => '02-999-8888'])->assertStatus(428);
        $this->assertSame('02-111-2222', $user->fresh()->phone);

        $this->updateProfile($user, ['email' => 'changed-'.$user->email])->assertStatus(428);
        $this->assertNotSame('changed-'.$user->email, $user->fresh()->email);
    }

    /**
     * 연락처가 아닌 항목(닉네임)만 바꾸거나, 연락처를 같은 값으로 다시 보내면 정책이 걸리지 않는다.
     * 빈 문자열과 null 은 같은 값으로 본다(폼이 빈 칸을 빈 문자열로 보낸다).
     *
     * @scenario entry_point=update_profile, changed=nickname|same_values, idv=none, actor=member
     *
     * @effects non_contact_changes_pass_without_verification
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function non_contact_changes_and_unchanged_values_pass(): void
    {
        $user = $this->makeMember(['mobile' => '010-1111-2222', 'phone' => null]);

        $this->updateProfile($user, ['nickname' => '새닉네임'])->assertOk();
        $this->assertSame('새닉네임', $user->fresh()->nickname);

        $this->updateProfile($user, ['mobile' => ' 010-1111-2222 ', 'phone' => ''])->assertOk();
    }

    /**
     * 비밀번호만 바꿀 때는 연락처 정책이 걸리지 않는다(비밀번호 정책은 따로 끈 상태로 측정).
     *
     * @scenario entry_point=update_profile, changed=password, idv=none, actor=member
     *
     * @effects non_contact_changes_pass_without_verification
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function password_only_change_does_not_trigger_the_contact_policy(): void
    {
        IdentityPolicy::where('key', 'core.profile.password_change')->update(['enabled' => false]);
        $user = $this->makeMember();

        $this->updateProfile($user, [
            'current_password' => 'Passw0rd!old',
            'password' => 'Passw0rd!new',
            'password_confirmation' => 'Passw0rd!new',
        ])->assertOk();

        $this->assertTrue(Hash::check('Passw0rd!new', $user->fresh()->password));
    }

    /**
     * 유효시간(5분) 안에 본인인증을 마쳤으면 다시 묻지 않는다.
     *
     * @scenario entry_point=update_profile, changed=mobile, idv=recent, actor=member
     *
     * @effects contact_change_honours_grace_and_retry_token
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function recent_verification_within_grace_passes(): void
    {
        $user = $this->makeMember(['mobile' => '010-1111-2222']);
        $this->recordVerified($user, minutesAgo: 4);

        $this->updateProfile($user, ['mobile' => '010-9999-8888'])->assertOk();
        $this->assertSame('010-9999-8888', $user->fresh()->mobile);
    }

    /**
     * 본인인증 창이 인증을 마친 뒤 원 요청을 인증 토큰과 함께 다시 보내면 저장된다.
     *
     * @scenario entry_point=update_profile, changed=mobile, idv=retry_token, actor=member
     *
     * @effects contact_change_honours_grace_and_retry_token
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function retry_with_verification_token_passes(): void
    {
        $user = $this->makeMember(['mobile' => '010-1111-2222']);

        $this->updateProfile($user, ['mobile' => '010-9999-8888'])->assertStatus(428);

        $token = 'tok-'.bin2hex(random_bytes(8));
        $this->recordVerified($user, minutesAgo: 0, token: $token);

        $this->updateProfile($user, ['mobile' => '010-9999-8888', 'verification_token' => $token])->assertOk();
        $this->assertSame('010-9999-8888', $user->fresh()->mobile);
    }

    /**
     * 관리자는 정책 적용 대상(본인 회원)이 아니다 — 본인 정보수정도, 회원 수정 화면에서 다른 회원의 연락처를 바꾸는 것도
     * 통과한다. 회원 수정 요청에는 역할 배열이 함께 실리는데, 그 배열 값을 비교하다 500 이 나지 않아야 한다.
     *
     * @scenario entry_point=update_profile|admin_update_user, changed=mobile, idv=none, actor=admin
     *
     * @effects admins_skip_the_self_contact_policy
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function admins_skip_the_policy_and_role_arrays_do_not_break_the_diff(): void
    {
        $admin = $this->makeAdmin(['mobile' => '010-1111-2222']);
        $this->updateProfile($admin, ['mobile' => '010-3333-4444'])->assertOk();

        $member = $this->makeMember(['mobile' => '010-5555-6666']);
        $token = $this->issueToken($admin);

        $this->withToken($token)->putJson('/api/admin/users/'.$member->uuid, [
            'name' => $member->name,
            'email' => $member->email,
            'mobile' => '010-7777-8888',
            'role_ids' => $member->roles->pluck('id')->all(),
        ])->assertOk();

        $this->assertSame('010-7777-8888', $member->fresh()->mobile);
    }
}
