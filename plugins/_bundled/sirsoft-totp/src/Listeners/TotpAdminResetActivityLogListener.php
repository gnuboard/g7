<?php

namespace Plugins\Sirsoft\Totp\Listeners;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;

/**
 * 관리자의 인증 앱 강제 해제를 활동 로그에 남긴다.
 *
 * 강제 해제는 코드 확인 없이 남의 2단계 인증을 푸는 경로다 — 누가 누구의 2단계 인증을
 * 언제 풀었는지 남지 않으면 그 경로 자체가 권한 상승 수단이 된다. 발행 지점
 * (`TotpAdminCredentialController::destroy()`)은 훅만 쏘고, 기록은 형제 확장
 * (sirsoft-pay_* 의 `CancelActivityLogListener`) 과 같은 결로 이 리스너가 맡는다.
 *
 * 실제로 지워진 경우에만 훅이 발행되므로(자격증명이 없던 회원은 발행 자체가 없다)
 * 이 리스너는 "해제가 일어났다" 만 기록하면 된다.
 *
 * @since 1.0.0
 */
class TotpAdminResetActivityLogListener implements HookListenerInterface
{
    use ResolvesActivityLogType;

    /**
     * 구독 훅 메타데이터.
     *
     * @return array<string, array<string, mixed>> 훅 정의
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-totp.credential.admin_reset' => [
                'method' => 'handle',
                'priority' => 50,
                'sync' => true,
            ],
        ];
    }

    /**
     * 훅 진입점 — 강제 해제 사실을 활동 로그에 기록합니다.
     *
     * @param  mixed  ...$args  [$user, $context]
     * @return void
     */
    public function handle(...$args): void
    {
        $user = $args[0] ?? null;

        if (! $user instanceof User) {
            return;
        }

        $context = is_array($args[1] ?? null) ? $args[1] : [];

        $this->logActivity('credential.admin_reset', [
            'loggable' => $user,
            'description_key' => 'sirsoft-totp::activity_log.description.credential_admin_reset',
            'description_params' => [
                'user_name' => (string) ($user->name ?? ''),
                'user_id' => (int) $user->id,
            ],
            'properties' => [
                'target_user_id' => (int) $user->id,
                'actor_id' => $context['actor_id'] ?? null,
                'ip_address' => $context['ip_address'] ?? null,
            ],
        ]);
    }
}
