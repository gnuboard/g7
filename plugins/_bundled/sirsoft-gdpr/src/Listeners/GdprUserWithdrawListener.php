<?php

namespace Plugins\Sirsoft\Gdpr\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentService;

/**
 * GDPR 회원탈퇴 처리 리스너
 *
 * 코어 `core.user.after_withdraw` 훅 구독 — 사용자가 회원탈퇴할 때 GDPR 도메인의
 * 정합성을 회복합니다. 코어가 user 행 자체를 보존하므로 plugin 데이터도 보존하되
 * 활성 동의는 모두 종료한다 (탈퇴 = 의사 표시 종료).
 *
 * 동작:
 * - 활성 동의(`is_consented=true`)를 모두 false로 UPDATE
 *   + history 에 source=`withdraw` revoked 행 INSERT
 */
class GdprUserWithdrawListener implements HookListenerInterface
{
    /**
     * GdprUserWithdrawListener 생성자
     *
     * @param  GdprConsentService  $consentService  동의 서비스
     */
    public function __construct(
        private readonly GdprConsentService $consentService,
    ) {}

    /**
     * 구독할 훅 목록.
     *
     * @return array<string, array{method?: string, priority?: int, type?: string}>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.after_withdraw' => [
                'method' => 'handleWithdraw',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 인터페이스 요구 메서드 (개별 메서드 사용 시 빈 구현).
     *
     * @param  mixed  ...$args
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드 분기: getSubscribedHooks 의 method 키 참조
    }

    /**
     * 회원탈퇴 후 GDPR 정합성 처리.
     *
     * @param  User  $user  탈퇴한 사용자
     * @return void
     */
    public function handleWithdraw(User $user): void
    {
        // 활성 동의 모두 철회 (status UPDATE + history INSERT, source=withdraw)
        $this->consentService->revokeAllOnWithdraw($user->id);
    }
}
