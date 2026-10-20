<?php

namespace Plugins\Sirsoft\SocialLogin\Listeners;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;

/**
 * 이 플러그인의 연결·가입 훅을 활동 로그로 남깁니다.
 *
 * 발행 지점(서비스)은 훅만 쏘고 기록은 이 리스너가 맡는다. 활동 로그에는 제공자 키와 연결 경로만 남기고 제공자 사용자
 * ID·토큰은 싣지 않는다.
 *
 * @since 1.0.0
 */
class ActivityLogListener implements HookListenerInterface
{
    use ResolvesActivityLogType;

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-social_login.account.after_link' => ['method' => 'onLinked', 'priority' => 50, 'sync' => true],
            'sirsoft-social_login.account.after_unlink' => ['method' => 'onUnlinked', 'priority' => 50, 'sync' => true],
            'sirsoft-social_login.auth.after_social_signup' => ['method' => 'onSignup', 'priority' => 50, 'sync' => true],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 연결을 기록합니다 — 자동 연결은 따로 남긴다(운영자가 위험을 알고 켠 정책의 실행 기록).
     *
     * @param  SocialAccount  $account  연결
     * @param  User  $user  회원
     * @param  array<string, mixed>  $context  `method` (link·existing·auto·signup)
     * @return void
     */
    public function onLinked(SocialAccount $account, User $user, array $context = []): void
    {
        $method = (string) ($context['method'] ?? 'link');
        if ($method === 'signup') {
            // 가입과 함께 생긴 연결은 가입 기록 하나로 남긴다.
            return;
        }

        $type = $method === 'auto' ? 'account_auto_linked' : 'account_linked';

        $this->write($type, $user, $account->provider_key, ['method' => $method]);
    }

    /**
     * 해제를 기록합니다 (사유별 유형).
     *
     * @param  SocialAccount  $account  연결
     * @param  User  $user  회원
     * @param  string  $reason  사유 (user·admin·webhook·withdraw·delete)
     * @return void
     */
    public function onUnlinked(SocialAccount $account, User $user, string $reason = 'user'): void
    {
        $type = match ($reason) {
            'admin' => 'account_unlinked_by_admin',
            'webhook' => 'account_unlinked_by_webhook',
            default => 'account_unlinked',
        };

        $this->write($type, $user, $account->provider_key, ['reason' => $reason]);
    }

    /**
     * 소셜 가입을 기록합니다.
     *
     * @param  User  $user  회원
     * @param  SocialAccount  $account  연결
     * @return void
     */
    public function onSignup(User $user, SocialAccount $account): void
    {
        $this->write('signup', $user, $account->provider_key, []);
    }

    /**
     * 활동 로그 1건을 씁니다.
     *
     * @param  string  $type  유형 (activity_log.description 키)
     * @param  User  $user  대상 회원
     * @param  string  $providerKey  제공자 키
     * @param  array<string, mixed>  $properties  추가 속성
     * @return void
     */
    private function write(string $type, User $user, string $providerKey, array $properties): void
    {
        $this->logActivity($type, [
            'loggable' => $user,
            'description_key' => 'sirsoft-social_login::activity_log.description.'.$type,
            'description_params' => [
                'user_name' => (string) ($user->name ?? ''),
                'provider' => $providerKey,
            ],
            'properties' => ['target_user_id' => (int) $user->getKey(), 'provider_key' => $providerKey] + $properties,
        ]);
    }
}
