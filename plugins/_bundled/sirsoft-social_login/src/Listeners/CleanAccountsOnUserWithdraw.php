<?php

namespace Plugins\Sirsoft\SocialLogin\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\Sirsoft\SocialLogin\Enums\UnlinkReason;
use Plugins\Sirsoft\SocialLogin\Services\AccountLinkService;

/**
 * 회원 탈퇴 뒤 소셜 연결을 지웁니다.
 *
 * 탈퇴 회원 행은 남지만, 연결이 남으면 같은 제공자 계정으로 다시 가입할 수 없고 그 계정으로 탈퇴 회원에 로그인 인계가
 * 시도된다. 탈퇴와 함께 끊는다.
 *
 * @since 1.0.0
 */
class CleanAccountsOnUserWithdraw implements HookListenerInterface
{
    /**
     * @param  AccountLinkService  $links  연결 관리
     */
    public function __construct(private readonly AccountLinkService $links) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.after_withdraw' => ['method' => 'handle', 'priority' => 50, 'sync' => true],
        ];
    }

    /**
     * 대상 회원의 연결을 지웁니다.
     *
     * @param  mixed  ...$args  [$user]
     * @return void
     */
    public function handle(...$args): void
    {
        $user = $args[0] ?? null;

        if ($user instanceof User && (int) $user->getKey() > 0) {
            $this->links->removeAllFor($user, UnlinkReason::Withdraw);
        }
    }
}
