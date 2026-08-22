<?php

namespace Plugins\Sirsoft\Gdpr\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentService;

/**
 * GDPR 사용자 완전 삭제 cascade 리스너
 *
 * 코어 `core.user.before_delete` 훅 구독 — 사용자가 완전 삭제(hard delete)되기
 * 전에 GDPR 도메인 데이터를 명시적으로 정리한다.
 *
 * 동작:
 * - status 테이블 (gdpr_user_consents):
 *   행 자체를 명시적 삭제 (DB CASCADE 미의존)
 * - history 테이블 (gdpr_user_consent_histories):
 *   user_id / ip_address / user_agent 만 NULL 익명화하여 행 보존
 *   (GDPR Art.17 + Art.7(1) 양립 — 동의 입증 책임 영구 보존)
 *
 * before_delete 시점에 삭제하는 이유: cascade race 회피 +
 * 다른 리스너가 user 데이터를 참조할 수 있도록 정합성 유지.
 */
class GdprUserDeleteListener implements HookListenerInterface
{
    /**
     * GdprUserDeleteListener 생성자
     *
     * @param  GdprConsentService  $consentService  동의 서비스 (status 삭제 + history 익명화)
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
            'core.user.before_delete' => [
                'method' => 'cascadePluginData',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 인터페이스 요구 메서드.
     *
     * @param  mixed  ...$args
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드 분기
    }

    /**
     * 사용자 완전 삭제 전 GDPR 도메인 데이터 정리.
     *
     * @param  User  $user  삭제될 사용자
     * @return void
     */
    public function cascadePluginData(User $user): void
    {
        // status 테이블 명시적 삭제 + history 익명화 (Service 내부에서 처리)
        $this->consentService->purgeOnUserDelete($user->id);
    }
}
