<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;

/**
 * 가입 적립금 지급 리스너
 *
 * 스스로 가입한 회원에게 환경설정(마일리지 → 가입 적립금)의 금액을 한 번 지급한다.
 * 본인인증 대기로 가입한 회원은 가입 때는 건너뛰고 활성화될 때 지급한다.
 * 관리자가 만든 회원(`core.user.after_create`)은 대상이 아니라 구독하지 않는다.
 * 가입 훅을 발행하지 않고 회원을 만드는 경로에는 지급되지 않는다.
 */
class GrantSignupMileageListener implements HookListenerInterface
{
    /**
     * 의존성을 주입받습니다.
     *
     * @param  UserMileageService  $mileageService  마일리지 서비스
     */
    public function __construct(
        protected UserMileageService $mileageService,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, priority: int, sync: bool}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // sync: 가입 직후 마이페이지 적립금에 바로 보여야 한다. 한 회원 한 번 가드로 멱등이다.
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
     * 회원가입 후 가입 적립금 지급 (core.auth.after_register)
     *
     * 가입 직후 상태가 활성일 때만 지급한다 — 본인인증 대기 가입은 활성화 훅에서 지급한다.
     *
     * @param  User  $user  가입한 회원
     * @param  array  $context  가입 컨텍스트
     * @return void 반환값 없음
     */
    public function handleRegister(User $user, array $context = []): void
    {
        if ($this->statusValue($user) !== UserStatus::Active->value) {
            return;
        }

        $this->grant($user);
    }

    /**
     * 본인인증 대기 회원이 활성화된 뒤 가입 적립금 지급 (core.auth.after_register_activated)
     *
     * @param  User  $user  활성화된 회원
     * @return void 반환값 없음
     */
    public function handleActivated(User $user): void
    {
        $this->grant($user);
    }

    /**
     * 가입 적립금을 지급합니다. 실패해도 가입 흐름을 막지 않는다.
     *
     * @param  User  $user  대상 회원
     * @return void 반환값 없음
     */
    private function grant(User $user): void
    {
        try {
            $this->mileageService->grantSignupMileage((int) $user->id);
        } catch (\Throwable $e) {
            Log::warning('가입 적립금 지급 실패', [
                'user_id' => $user->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 회원 상태 값을 문자열로 돌려줍니다 (모델 형변환 여부와 무관하게).
     *
     * @param  User  $user  회원
     * @return string 상태 값
     */
    private function statusValue(User $user): string
    {
        $status = $user->status;

        return $status instanceof UserStatus ? $status->value : (string) $status;
    }
}
