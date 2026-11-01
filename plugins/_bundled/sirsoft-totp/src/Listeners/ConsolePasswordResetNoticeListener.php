<?php

namespace Plugins\Sirsoft\Totp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Illuminate\Database\QueryException;
use Plugins\Sirsoft\Totp\Repositories\Contracts\TotpCredentialRepositoryInterface;

/**
 * 터미널 비밀번호 재설정(`user:reset-password`) 결과에 인증 앱 초기화 안내를 덧붙이는 리스너 (filter 훅).
 *
 * 관리자 계정을 셸에서 복구하는 운영자는 비밀번호를 되찾아도 인증 앱을 잃었으면 여전히 로그인하지 못한다.
 * 코어는 어느 확장이 설치돼 있는지 묻지 않으므로(`core.auth.console_password_reset_notices`), 그 회원이
 * 인증 앱 등록을 마친 경우에만 이 플러그인이 `totp:reset` 안내 한 줄을 답한다.
 *
 * @since 1.0.2
 */
class ConsolePasswordResetNoticeListener implements HookListenerInterface
{
    /**
     * @param  TotpCredentialRepositoryInterface  $credentials  자격증명 저장소
     */
    public function __construct(
        private readonly TotpCredentialRepositoryInterface $credentials,
    ) {}

    /**
     * 구독할 훅 목록 반환.
     *
     * @return array<string, array{method: string, type: string, priority: int}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.console_password_reset_notices' => [
                'method' => 'appendResetNotice',
                'type' => 'filter',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 기본 핸들러 (getSubscribedHooks 의 method 매핑을 쓰므로 직접 호출되지 않음).
     *
     * @param  mixed  ...$args  훅 인수
     * @return void
     */
    public function handle(...$args): void
    {
        // method 매핑(appendResetNotice)을 사용한다.
    }

    /**
     * 인증 앱 등록을 마친 회원이면 초기화 명령 안내를 더합니다.
     *
     * @param  array<int, string>  $notices  지금까지 모인 안내 문구
     * @param  User  $user  비밀번호를 재설정한 회원
     * @return array<int, string> 안내 문구
     */
    public function appendResetNotice(array $notices, User $user): array
    {
        try {
            $enrolled = $this->credentials->hasConfirmed((int) $user->id);
        } catch (QueryException) {
            // 테이블이 없거나 조회가 실패해도 비밀번호 재설정 결과 출력은 끝나야 한다 — 모르면 덧붙이지 않는다.
            return $notices;
        }

        if ($enrolled) {
            $notices[] = __('sirsoft-totp::messages.console.password_reset_notice', ['email' => $user->email]);
        }

        return $notices;
    }
}
