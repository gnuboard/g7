<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;

/**
 * 회원 탈퇴/삭제 시 마일리지 데이터 정리 리스너 (정책 확정 #8)
 *
 * 코어 UserService 의 탈퇴(before_withdraw)·삭제(before_delete) 훅을 구독합니다 (E3).
 *
 * - 탈퇴: 남은 마일리지를 「탈퇴로 소멸」 거래로 원장에 기록해 0 으로 만들고, 거래 행은 지우지 않는다.
 *   지급·사용·소멸 경위는 탈퇴 뒤에도 장부로 남아야 한다(회원 행 자체는 코어 보유기간 핸들러가 익명화한다).
 * - 삭제(회원 행 하드 삭제): 남은 마일리지를 소멸 기록한 뒤 거래 행에서 회원 연결만 끊는다(user_id NULL).
 *   잔액 캐시는 파생값이라 지운다. DB 의 nullOnDelete 는 안전망으로만 둔다(코어 규정: CASCADE 의존 금지).
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
     * @return array<string, array{method: string, priority: int, sync: bool}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        // 금전 훅 — 탈퇴·삭제 트랜잭션 안에서 회원 행이 지워지기 전에 돌아야 한다. 큐 기본값(afterCommit)이면
        // 하드 삭제의 FK(nullOnDelete)가 먼저 거래 행의 user_id 를 비워 소멸 기록을 남길 lot 을 찾지 못한다.
        return [
            'core.user.before_withdraw' => ['method' => 'handleUserWithdraw', 'priority' => 10, 'sync' => true],
            'core.user.before_delete' => ['method' => 'handleUserDelete', 'priority' => 10, 'sync' => true],
        ];
    }

    /**
     * 기본 핸들러 (getSubscribedHooks 의 method 매핑 사용)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // method 매핑을 사용하므로 직접 호출되지 않음
    }

    /**
     * 회원 탈퇴 직전에 남은 마일리지를 소멸 기록합니다. (거래 행 보존)
     *
     * @param  User  $user  탈퇴 대상 회원
     * @return void 반환값 없음
     */
    public function handleUserWithdraw(User $user): void
    {
        $this->mileageService->forfeitForWithdrawal((int) $user->id);
    }

    /**
     * 회원 삭제 직전에 남은 마일리지를 소멸 기록하고 거래 행의 회원 연결을 끊습니다.
     *
     * @param  User  $user  삭제 대상 회원
     * @return void 반환값 없음
     */
    public function handleUserDelete(User $user): void
    {
        $this->mileageService->detachForUserDeletion((int) $user->id);
    }
}
