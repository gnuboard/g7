<?php

namespace Plugins\Sirsoft\Totp\Tests\Feature;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Totp\Listeners\CleanTotpCredentialOnUserDelete;
use Plugins\Sirsoft\Totp\Listeners\CleanTotpCredentialOnUserWithdraw;
use Plugins\Sirsoft\Totp\Services\TotpEnrollmentService;
use Plugins\Sirsoft\Totp\Support\Totp;
use Plugins\Sirsoft\Totp\Tests\PluginTestCase;

/**
 * 사용자 탈퇴·삭제 시 자격증명 파기 리스너 테스트.
 *
 * 시크릿과 백업 코드 해시는 그 계정에만 쓰이는 인증 수단이므로 계정이 사라지면 남을
 * 이유가 없습니다. 테이블에 CASCADE 를 걸지 않으므로(database-guide 규정) 이 리스너가
 * 유일한 파기 경로입니다 — 빠지면 탈퇴한 회원의 인증 수단이 DB 에 그대로 남습니다.
 *
 * @since 1.0.0
 */
class TotpCleanupListenerTest extends PluginTestCase
{
    /**
     * 확정된 자격증명을 가진 사용자를 만듭니다.
     *
     * @return User 등록을 마친 사용자
     */
    private function enrolledUser(): User
    {
        $user = User::factory()->create();

        $enrollment = app(TotpEnrollmentService::class);
        $secret = $enrollment->start($user, 'G7 Test')['secret'];
        $enrollment->confirm($user, Totp::codeAt($secret, Totp::timestepAt()));

        $this->assertDatabaseHas('totp_credentials', ['user_id' => $user->id]);

        return $user;
    }

    /**
     * 탈퇴 시 자격증명이 파기되는지 확인합니다.
     *
     * @scenario listener=withdraw, hook=core.user.after_withdraw
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function withdrawing_a_user_destroys_the_credential(): void
    {
        $user = $this->enrolledUser();

        app(CleanTotpCredentialOnUserWithdraw::class)->handle($user);

        $this->assertDatabaseMissing('totp_credentials', ['user_id' => $user->id]);
    }

    /**
     * 삭제 시 자격증명이 파기되는지 확인합니다.
     *
     * @scenario listener=delete, hook=core.user.before_delete
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function deleting_a_user_destroys_the_credential(): void
    {
        $user = $this->enrolledUser();

        app(CleanTotpCredentialOnUserDelete::class)->handle($user);

        $this->assertDatabaseMissing('totp_credentials', ['user_id' => $user->id]);
    }

    /**
     * 파기 대상이 그 사용자로 한정되고 다른 회원의 자격증명은 남는지 확인합니다.
     *
     * @scenario listener=withdraw, subject=other_user
     *
     * @effects other_users_credentials_are_untouched
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_only_destroys_the_target_users_credential(): void
    {
        $target = $this->enrolledUser();
        $bystander = $this->enrolledUser();

        app(CleanTotpCredentialOnUserWithdraw::class)->handle($target);

        $this->assertDatabaseMissing('totp_credentials', ['user_id' => $target->id]);
        $this->assertDatabaseHas('totp_credentials', ['user_id' => $bystander->id]);
    }

    /**
     * 훅 인자가 비거나 다른 타입이어도 터지지 않아 탈퇴 흐름이 멈추지 않는지 확인합니다.
     *
     * @scenario listener=withdraw, payload=invalid
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_payload_without_a_user_is_ignored(): void
    {
        $user = $this->enrolledUser();

        // 훅 인자가 비거나 다른 타입이어도 터지지 않아야 한다 — 탈퇴 흐름 전체가 멈춘다.
        app(CleanTotpCredentialOnUserWithdraw::class)->handle();
        app(CleanTotpCredentialOnUserWithdraw::class)->handle(null);
        app(CleanTotpCredentialOnUserWithdraw::class)->handle('not-a-user');

        $this->assertDatabaseHas('totp_credentials', ['user_id' => $user->id]);
    }

    /**
     * 두 리스너가 각각 탈퇴 후·삭제 전 훅을 구독하는지 확인합니다.
     *
     * @scenario listener=hook_registration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_listeners_subscribe_to_the_lifecycle_hooks_the_core_publishes(): void
    {
        // 탈퇴는 after — 트랜잭션이 실패했는데 자격증명만 지워지면 안 된다.
        $this->assertArrayHasKey(
            'core.user.after_withdraw',
            CleanTotpCredentialOnUserWithdraw::getSubscribedHooks()
        );

        // 삭제는 before — 사용자 행이 사라진 뒤에는 외래키가 걸린 자격증명을 지울 수 없다.
        $this->assertArrayHasKey(
            'core.user.before_delete',
            CleanTotpCredentialOnUserDelete::getSubscribedHooks()
        );
    }
}
