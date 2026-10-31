<?php

namespace Plugins\Sirsoft\Totp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\Sirsoft\Totp\Repositories\Contracts\TotpCredentialRepositoryInterface;

/**
 * 사용자 삭제 시 인증 앱 자격증명을 파기한다.
 *
 * 탈퇴와 달리 삭제는 `core.user.before_delete` 를 구독한다 — 사용자 행이 사라진 뒤에는
 * 외래키가 걸린 자격증명을 지울 수 없어 삭제 자체가 실패한다 (nhnkcp 선례 동형).
 *
 * @since 1.0.0
 */
class CleanTotpCredentialOnUserDelete implements HookListenerInterface
{
    /**
     * @param  TotpCredentialRepositoryInterface  $credentials  자격증명 저장소
     */
    public function __construct(
        private readonly TotpCredentialRepositoryInterface $credentials,
    ) {}

    /**
     * 구독 훅 메타데이터.
     *
     * @return array<string, array<string, mixed>> 훅 정의
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.before_delete' => [
                'method' => 'handle',
                'priority' => 50,
                'sync' => true,
            ],
        ];
    }

    /**
     * 훅 진입점 — 대상 사용자의 자격증명을 삭제합니다.
     *
     * @param  mixed  ...$args  [$user]
     * @return void
     */
    public function handle(...$args): void
    {
        $user = $args[0] ?? null;

        if (! $user instanceof User) {
            return;
        }

        $userId = (int) $user->id;

        if ($userId <= 0) {
            return;
        }

        $this->credentials->deleteByUserId($userId);
    }
}
