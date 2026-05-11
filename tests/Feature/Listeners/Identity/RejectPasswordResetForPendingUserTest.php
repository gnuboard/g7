<?php

namespace Tests\Feature\Listeners\Identity;

use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Listeners\Identity\RejectPasswordResetForPendingUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PR#3 — Mode C 에서 pending_verification 계정의 비밀번호 재설정 거부 Listener 테스트.
 *
 * 계획서 요구: "Mode C 에서 pending 상태 계정의 비밀번호 재설정 요청이 들어오면:
 * '먼저 가입 인증을 완료하세요' 로 안내 (password_reset purpose 허용 금지)."
 */
class RejectPasswordResetForPendingUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_is_subscribed_to_before_reset_password_hook(): void
    {
        $subscriptions = RejectPasswordResetForPendingUser::getSubscribedHooks();
        $this->assertArrayHasKey('core.auth.before_reset_password', $subscriptions);
        $this->assertTrue($subscriptions['core.auth.before_reset_password']['sync'] ?? false);
    }

    public function test_pending_user_is_rejected(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::PendingVerification->value,
        ]);

        $listener = app(RejectPasswordResetForPendingUser::class);

        $this->expectException(AuthorizationException::class);
        $listener->handle($user);
    }

    public function test_active_user_passes_through(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::Active->value,
        ]);

        $listener = app(RejectPasswordResetForPendingUser::class);

        // no exception means pass-through
        $listener->handle($user);
        $this->assertTrue(true);
    }

    public function test_listener_resolves_user_from_array_payload(): void
    {
        User::factory()->create([
            'email' => 'pending@example.com',
            'status' => UserStatus::PendingVerification->value,
        ]);

        $listener = app(RejectPasswordResetForPendingUser::class);

        $this->expectException(AuthorizationException::class);
        $listener->handle(['email' => 'pending@example.com']);
    }

    public function test_hook_chain_blocks_when_pending_user_hits_before_reset_password(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::PendingVerification->value,
            'email' => 'pending2@example.com',
        ]);

        // Register listener explicitly (in case ServiceProvider has not wired in test env)
        HookManager::addAction(
            'core.auth.before_reset_password',
            [app(RejectPasswordResetForPendingUser::class), 'handle'],
            5,
        );

        $this->expectException(AuthorizationException::class);
        HookManager::doAction('core.auth.before_reset_password', $user);
    }
}
