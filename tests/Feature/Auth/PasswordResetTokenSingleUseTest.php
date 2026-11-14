<?php

namespace Tests\Feature\Auth;

use App\Contracts\Repositories\PasswordResetTokenRepositoryInterface;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Repositories\PasswordResetTokenRepository;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 같은 재설정 링크(토큰)로 비밀번호를 두 번 바꿀 수 없는지 검증한다 (KVE 동형 O6).
 *
 * 단일 프로세스에서는 진짜 동시 요청을 만들 수 없으므로 「첫 요청이 토큰을 읽은 뒤 다른 요청이 먼저 소비한」
 * 상황을 낡은 토큰 레코드를 돌려주는 Repository 로 재현한다.
 */
class PasswordResetTokenSingleUseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 다른 요청이 이미 쓴 재설정 토큰(낡은 레코드)으로는 비밀번호가 바뀌지 않는다.
     *
     * @effects reset_token_single_use, reset_password_keeps_first_value
     *
     * @return void 반환값 없음
     */
    public function test_second_reset_with_stale_token_record_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'reset-single@example.test']);
        $plain = 'reset-token-plain-value';
        $repository = app(PasswordResetTokenRepositoryInterface::class);
        $repository->updateOrCreateByEmail($user->email, ['token' => Hash::make($plain), 'created_at' => now()]);

        // 두 번째 요청이 먼저 읽어 둔 레코드 — 첫 요청이 소비한 뒤에도 메모리에 남아 있다.
        $stale = $repository->findByEmail($user->email);

        app(AuthService::class)->resetPassword($plain, $user->email, 'FirstPassw0rd!x');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

        $this->app->instance(PasswordResetTokenRepositoryInterface::class, new class($stale) extends PasswordResetTokenRepository
        {
            /**
             * 낡은 토큰 레코드를 들고 있는 대역 저장소를 만듭니다.
             *
             * @param  PasswordResetToken  $stale  낡은 토큰 레코드
             */
            public function __construct(private PasswordResetToken $stale) {}

            /**
             * 낡은 레코드를 그대로 돌려줍니다.
             *
             * @param  string  $email  이메일
             * @return PasswordResetToken|null 낡은 레코드
             */
            public function findByEmail(string $email): ?PasswordResetToken
            {
                return $this->stale;
            }
        });
        $this->app->forgetInstance(AuthService::class);

        try {
            app(AuthService::class)->resetPassword($plain, $user->email, 'SecondPassw0rd!y');
            $this->fail('이미 쓴 재설정 토큰으로 두 번째 재설정이 통과했습니다.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('token', $e->errors());
        }

        $this->assertTrue(Hash::check('FirstPassw0rd!x', $user->fresh()->password));
        $this->assertFalse(Hash::check('SecondPassw0rd!y', $user->fresh()->password));
    }
}
