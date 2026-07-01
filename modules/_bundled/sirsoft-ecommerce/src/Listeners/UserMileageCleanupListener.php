<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;

/**
 * 회원 탈퇴/삭제 시 마일리지 데이터 정리 리스너 (PO 확정 #8)
 *
 * 코어 UserService 의 탈퇴(before_withdraw)·삭제(before_delete) 훅을 구독해,
 * 활성 lot 을 expired 거래로 소멸 기록(감사 흐름 보존)한 뒤 원장·잔액 캐시 행을 명시 삭제합니다.
 * 탈퇴는 soft 상태 변경이라 cascadeOnDelete 가 발화하지 않으므로 명시 정리가 필수입니다
 * (CLAUDE.md "DB CASCADE 의존 삭제 금지"). cascade 는 삭제 경로의 안전망으로만 둡니다.
 */
class UserMileageCleanupListener implements HookListenerInterface
{
    /**
     * @param  UserMileageService  $mileageService  마일리지 서비스
     */
    public function __construct(
        private UserMileageService $mileageService,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, priority: int}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.before_withdraw' => ['method' => 'handleUserRemoval', 'priority' => 10],
            'core.user.before_delete' => ['method' => 'handleUserRemoval', 'priority' => 10],
        ];
    }

    /**
     * 기본 핸들러 (getSubscribedHooks 의 method 매핑 사용)
     *
     * @param  mixed  ...$args  훅 인수
     */
    public function handle(...$args): void
    {
        // method 매핑을 사용하므로 직접 호출되지 않음
    }

    /**
     * 회원 탈퇴/삭제 직전에 마일리지 데이터를 정리합니다.
     *
     * @param  User  $user  탈퇴/삭제 대상 회원
     */
    public function handleUserRemoval(User $user): void
    {
        $this->mileageService->purgeForUser($user->id);
    }
}
