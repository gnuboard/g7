<?php

namespace Plugins\Sirsoft\Totp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\Sirsoft\Totp\Repositories\Contracts\TotpCredentialRepositoryInterface;

/**
 * 사용자 탈퇴 시 인증 앱 자격증명을 파기한다.
 *
 * 시크릿과 백업 코드 해시는 그 계정에만 쓰이는 인증 수단이므로 계정이 사라지면 남길
 * 이유가 없다. 코어 `core.user.after_withdraw` 를 구독한다 — 탈퇴 트랜잭션이 끝난 뒤
 * 호출되므로 탈퇴가 실패한 경우에 자격증명만 지워지는 일이 없다 (nhnkcp 선례와 같은 이유).
 *
 * 테이블에 CASCADE 를 걸지 않고 이 리스너가 명시 삭제한다 — database-guide 규정.
 *
 * @since 1.0.0
 */
class CleanTotpCredentialOnUserWithdraw implements HookListenerInterface
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
            'core.user.after_withdraw' => [
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
