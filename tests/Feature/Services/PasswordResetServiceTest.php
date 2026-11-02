<?php

namespace Tests\Feature\Services;

use App\Enums\PasswordChangeSource;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\PasswordResetService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * 비밀번호 재설정 공용 서비스 — 메일 재설정(AuthService::resetPassword)과 터미널 재설정이 같은 경로를 쓴다.
 */
class PasswordResetServiceTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 같은 트랜잭션의 추가 작업이 실패하면 비밀번호 변경도 되돌린다 (메일 재설정의 토큰 삭제 원자성).
     *
     * @effects reset_service_rolls_back_with_inside_transaction_failure
     *
     * @return void 반환값 없음
     */
    public function test_inside_transaction_failure_rolls_back_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Old-Passw0rd!x')]);

        try {
            app(PasswordResetService::class)->resetByOperator($user, 'New-Passw0rd!Strong', PasswordChangeSource::Reset, insideTransaction: function () {
                throw new RuntimeException('token delete failed');
            });
            $this->fail('예외가 전파되지 않았다');
        } catch (RuntimeException) {
        }

        $this->assertTrue(Hash::check('Old-Passw0rd!x', $user->fresh()->password));
    }

    /**
     * 재설정 직전 가드 훅은 메일 재설정에서만 발화한다(7.1.3 까지와 같은 범위) — 터미널은 발화하지 않는다.
     *
     * @effects reset_service_fires_before_hook_only_for_mail_reset
     *
     * @return void 반환값 없음
     */
    public function test_before_hook_fires_only_for_mail_reset(): void
    {
        $fired = [];
        $listener = function (User $user) use (&$fired): void {
            $fired[] = $user->id;
        };
        HookManager::addAction('core.auth.before_reset_password', $listener);

        try {
            $service = app(PasswordResetService::class);
            $mail = User::factory()->create();
            $console = User::factory()->create();
            $service->resetByOperator($mail, 'New-Passw0rd!Strong', PasswordChangeSource::Reset);
            $service->resetByOperator($console, 'New-Passw0rd!Strong', PasswordChangeSource::Console);
        } finally {
            HookManager::removeAction('core.auth.before_reset_password', $listener);
        }

        $this->assertSame([$mail->id], $fired);
    }

    /**
     * 메일 재설정 출처는 변경 강제를 켜지 않고, 터미널 출처는 요청할 때만 켠다.
     *
     * @effects reset_service_require_change_only_when_requested
     *
     * @return void 반환값 없음
     */
    public function test_require_change_follows_request(): void
    {
        $service = app(PasswordResetService::class);
        $a = User::factory()->create();
        $b = User::factory()->create();

        $service->resetByOperator($a, 'New-Passw0rd!Strong', PasswordChangeSource::Reset);
        $service->resetByOperator($b, 'New-Passw0rd!Strong', PasswordChangeSource::Console, true);

        $this->assertFalse((bool) $a->fresh()->password_change_required);
        $this->assertTrue((bool) $b->fresh()->password_change_required);
    }
}
