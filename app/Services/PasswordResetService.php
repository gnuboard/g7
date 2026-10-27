<?php

namespace App\Services;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\PasswordChangeSource;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 비밀번호 재설정 실행 — 메일 링크 재설정과 터미널 재설정(`user:reset-password`)이 같은 경로를 쓴다.
 *
 * 한 트랜잭션 안에서: 재설정 직전 훅 → 비밀번호 갱신 → 변경 기록(이력·모든 기기 로그아웃·변경 강제 표지) →
 * 로그인 실패 횟수·잠금 초기화. 커밋 뒤: 잠겨 있었으면 잠금 해제 훅 → 비밀번호 변경 완료 훅(알림 발송).
 *
 * 입력 검증(비밀번호 정책·재사용 금지)은 호출자 몫이다 — 웹은 FormRequest, 터미널은 같은 규칙으로 명령이 검증한다.
 *
 * 재설정 직전 훅 `core.auth.before_reset_password` 는 메일 링크 재설정에서만 발화한다(7.1.3 까지와 같은 범위).
 * 이 훅에는 웹 흐름의 가드(본인인증 정책 `core.auth.password_reset`·인증 대기 회원 거부)가 걸려 있다 —
 * 터미널은 브라우저 본인인증을 마칠 수 없어, 정책을 켠 사이트에서 발화하면 관리자 복구 수단 자체가 막힌다.
 * 셸 접근이 곧 권한이다(`totp:reset` 과 같은 관례).
 */
class PasswordResetService
{
    /**
     * @param  UserRepositoryInterface  $userRepository  회원 저장소
     * @param  PasswordChangeService  $passwordChangeService  비밀번호 변경 기록
     */
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordChangeService $passwordChangeService,
    ) {}

    /**
     * 비밀번호를 재설정한다.
     *
     * @param  User  $user  대상 회원
     * @param  string  $plainPassword  새 비밀번호 (검증된 평문)
     * @param  PasswordChangeSource  $source  재설정 경로 (Reset: 메일 링크, Console: 터미널)
     * @param  bool  $requireChange  다음 로그인 때 바꾸도록 강제
     * @param  callable|null  $insideTransaction  같은 트랜잭션에서 함께 할 일 (메일 재설정의 토큰 삭제 등)
     * @return void 반환값 없음
     */
    public function resetByOperator(
        User $user,
        string $plainPassword,
        PasswordChangeSource $source,
        bool $requireChange = false,
        ?callable $insideTransaction = null,
    ): void {
        // 재설정 메일을 받아 링크를 연 것(또는 서버 셸에 들어온 것) 자체가 본인·운영자 확인이므로 로그인 잠금도 함께 푼다.
        $wasLocked = $this->userRepository->isLocked($user);
        $oldPasswordHash = $user->getRawOriginal('password');

        DB::transaction(function () use ($user, $plainPassword, $source, $requireChange, $insideTransaction, $oldPasswordHash) {
            if ($source !== PasswordChangeSource::Console) {
                HookManager::doAction('core.auth.before_reset_password', $user);
            }

            $this->userRepository->update($user, [
                'password' => Hash::make($plainPassword),
            ]);

            // 변경 기록 + 모든 기기 로그아웃 — 재설정은 탈취 대응일 수 있어 현재 기기도 남기지 않는다.
            $this->passwordChangeService->recordChange(
                $user,
                is_string($oldPasswordHash) ? $oldPasswordHash : null,
                $source,
                requireChange: $requireChange,
            );

            $this->userRepository->resetLoginAttempts($user);

            if ($insideTransaction !== null) {
                $insideTransaction();
            }
        });

        if ($wasLocked) {
            HookManager::doAction('core.auth.account_unlocked', $user->fresh(), [
                'ip_address' => request()->ip(),
                'reason' => 'password_reset',
            ]);
        }

        // 비밀번호 변경 완료 — 알림 발송은 NotificationHookListener 가 처리한다.
        HookManager::doAction('core.auth.after_password_changed', $user);
    }
}
