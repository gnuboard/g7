<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Signup;

use App\Models\Role;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 소셜 가입과 사이트의 닉네임 정책 — 가입 창에 적은 닉네임과 제공자가 준 닉네임 모두 중복 금지와 금지어 판정을 거치고,
 * 걸리면 닉네임 칸의 검증 오류(422)로 돌아와 가입 창이 그 칸 아래에 문구를 보여 준다.
 *
 * @since 1.0.0
 */
class SignupNicknamePolicyTest extends PluginTestCase
{
    /**
     * 카카오를 켜고 메일, 알림, 큐를 막습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Queue::fake();
        Role::firstOrCreate(['identifier' => 'user'], ['name' => ['ko' => '사용자', 'en' => 'User']]);
        $this->enableKakao();
    }

    /**
     * 카카오 콜백까지 마치고 가입 대기 토큰을 꺼냅니다.
     *
     * @param  array<string, mixed>  $profile  카카오 프로필
     * @return string 대기 토큰
     */
    private function beginSignup(array $profile): string
    {
        return $this->redirectQuery($this->kakaoRoundTrip($profile))['social_pending'];
    }

    /**
     * 가입 완료를 요청합니다.
     *
     * @param  string  $token  대기 토큰
     * @param  array<string, mixed>  $input  추가 입력
     * @return TestResponse 응답
     */
    private function complete(string $token, array $input = []): TestResponse
    {
        return $this->postJson('/api/plugins/sirsoft-social_login/signup/complete', $input + [
            'pending_token' => $token,
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);
    }

    /**
     * 다른 회원이 쓰는 닉네임을 등록해 둡니다.
     *
     * @param  string  $nickname  닉네임
     * @return void 반환값 없음
     */
    private function takeNickname(string $nickname): void
    {
        app(AuthService::class)->register(
            ['name' => '먼저 쓴 회원', 'nickname' => $nickname, 'email' => $this->uniqueEmail('taken')],
            ['issue_token' => false, 'record_consents' => false],
        );
    }

    /**
     * 제공자가 준 닉네임이 중복이면 가입이 닉네임 칸의 오류로 막히고, 가입 창에서 다른 닉네임을 적으면 가입된다.
     *
     * @scenario nickname_source=provider
     *
     * @effects provider_nickname_checked_against_policy, signup_retry_with_new_nickname
     *
     * @return void 반환값 없음
     */
    public function test_duplicate_provider_nickname_returns_nickname_error(): void
    {
        config(['g7_settings.core.member.nickname_unique' => true]);
        $this->takeNickname('카카오닉');
        $email = $this->uniqueEmail('nick');
        $token = $this->beginSignup($this->kakaoProfile('k-nick1', $email));

        $this->complete($token)
            ->assertStatus(422)
            ->assertJsonPath('errors.nickname.0', __('user.validation.nickname_duplicate'));
        $this->assertFalse(User::query()->where('email', $email)->exists(), '닉네임 오류인데 회원이 만들어졌다');

        // 가입 대기는 남아 있어 닉네임만 바꿔 다시 보낼 수 있다
        $this->complete($token, ['nickname' => '다른닉'])->assertOk();
        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame(['다른닉', '다른닉'], [$user->nickname, $user->nickname_unique_key]);
    }

    /**
     * 가입 창에 적은 닉네임이 금지어나 중복이면 요청 검증에서 막힌다.
     *
     * @scenario nickname_source=input
     *
     * @effects input_nickname_checked_against_policy
     *
     * @return void 반환값 없음
     */
    public function test_input_nickname_is_validated_by_policy(): void
    {
        config(['g7_settings.core.member.nickname_unique' => true, 'g7_settings.core.member.nickname_banned_words' => ['운영자']]);
        $this->takeNickname('이미있음');
        $email = $this->uniqueEmail('nick');
        $token = $this->beginSignup($this->kakaoProfile('k-nick2', $email, nickname: null));

        $this->complete($token, ['name' => '홍길동', 'nickname' => '운 영자'])
            ->assertStatus(422)
            ->assertJsonPath('errors.nickname.0', __('user.validation.nickname_forbidden_word', ['word' => '운영자']));
        $this->complete($token, ['name' => '홍길동', 'nickname' => '이미 있음'])
            ->assertStatus(422)->assertJsonValidationErrors(['nickname']);
        $this->assertFalse(User::query()->where('email', $email)->exists());

        $this->complete($token, ['name' => '홍길동', 'nickname' => '길동'])->assertOk();
        $this->assertSame('길동', User::query()->where('email', $email)->value('nickname'));
    }
}
