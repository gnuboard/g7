<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Services\CouponAutoIssueService;

/**
 * 가입 쿠폰 자동발급 리스너 (#952)
 *
 * 가입 적립금({@see GrantSignupMileageListener})과 같은 규칙이다 — 스스로 가입한 회원에게 발급하고, 본인인증 대기로 가입한
 * 회원은 활성화될 때 발급한다. 관리자가 만든 회원(`core.user.after_create`)은 구독하지 않는다.
 * 재가입은 회원번호가 다른 새 회원이라 다시 받는다. 실패해도 가입 흐름을 막지 않는다.
 */
class GrantSignupCouponListener implements HookListenerInterface
{
    /**
     * @param  CouponAutoIssueService  $autoIssueService  쿠폰 자동발급 서비스
     */
    public function __construct(
        protected CouponAutoIssueService $autoIssueService,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, priority: int, sync: bool}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // sync: 가입 직후 쿠폰함에 바로 보여야 한다. 발급 키 유니크로 두 훅이 함께 와도 1회다.
            'core.auth.after_register' => ['method' => 'handleRegister', 'priority' => 30, 'sync' => true],
            'core.auth.after_register_activated' => ['method' => 'handleActivated', 'priority' => 30, 'sync' => true],
        ];
    }

    /**
     * 기본 핸들러 (method 매핑 사용 — 직접 호출되지 않음)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // method 매핑(handleRegister/handleActivated)을 사용
    }

    /**
     * 회원가입 후 가입 쿠폰 발급 (core.auth.after_register) — 가입 직후 활성일 때만
     *
     * @param  User  $user  가입한 회원
     * @param  array  $context  가입 컨텍스트
     * @return void 반환값 없음
     */
    public function handleRegister(User $user, array $context = []): void
    {
        $status = $user->status instanceof UserStatus ? $user->status->value : (string) $user->status;
        if ($status !== UserStatus::Active->value) {
            return;
        }

        $this->grant($user);
    }

    /**
     * 본인인증 대기 회원이 활성화된 뒤 가입 쿠폰 발급 (core.auth.after_register_activated)
     *
     * @param  User  $user  활성화된 회원
     * @return void 반환값 없음
     */
    public function handleActivated(User $user): void
    {
        $this->grant($user);
    }

    /**
     * 가입 쿠폰을 발급합니다. 실패해도 가입 흐름을 막지 않는다.
     *
     * @param  User  $user  대상 회원
     * @return void 반환값 없음
     */
    private function grant(User $user): void
    {
        try {
            $this->autoIssueService->issueSignupCoupons((int) $user->id);
        } catch (\Throwable $e) {
            Log::warning('가입 쿠폰 발급 실패', [
                'user_id' => $user->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
