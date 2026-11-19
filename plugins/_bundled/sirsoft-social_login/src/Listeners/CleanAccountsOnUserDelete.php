<?php

namespace Plugins\Sirsoft\SocialLogin\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\Sirsoft\SocialLogin\Enums\UnlinkReason;
use Plugins\Sirsoft\SocialLogin\Services\AccountLinkService;

/**
 * 회원 삭제 직전에 소셜 연결을 지웁니다.
 *
 * 연결 표는 회원을 외래키로 가리키되 연쇄 삭제를 걸지 않았다 — 회원 행이 사라지기 전에 여기서 지우지 않으면 삭제가
 * 외래키에 걸려 실패한다. 설정(`revoke_on_unlink`)이 켜져 있으면 제공자 권한 회수도 시도한다(실패해도 삭제는 진행).
 *
 * @since 1.0.0
 */
class CleanAccountsOnUserDelete implements HookListenerInterface
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
            'core.user.before_delete' => ['method' => 'handle', 'priority' => 50, 'sync' => true],
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
            $this->links->removeAllFor($user, UnlinkReason::Delete);
        }
    }
}
