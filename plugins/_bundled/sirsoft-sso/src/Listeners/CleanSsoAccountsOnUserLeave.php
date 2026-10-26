<?php

namespace Plugins\Sirsoft\Sso\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookManager;
use App\Models\User;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoAccountRepositoryInterface;

/**
 * 회원 탈퇴, 삭제 때 그 회원의 IdP 연결을 지웁니다.
 *
 * 연결 표는 회원 FK 에 CASCADE 를 두지 않는다 — 이 리스너가 없으면 회원 삭제가 FK 오류로 실패하고, 탈퇴 뒤에도 IdP 신원(개인정보)이
 * 남고, 같은 IdP 신원으로 다시 가입할 수 없다(UNIQUE). 탈퇴는 익명화 뒤(`after_withdraw`), 삭제는 행을 지우기 전(`before_delete`)에 돈다.
 *
 * 연결을 가리키는 다른 표(속성, 역할 기록, 세션, SCIM 그룹 소속)는 그 표의 영역이 같은 훅을 더 높은 우선순위(더 작은 수)로
 * 구독해 먼저 지운다.
 *
 * @since 1.0.0
 */
class CleanSsoAccountsOnUserLeave implements HookListenerInterface
{
    /** 연결 해제 직전 훅 */
    public const ACTION_BEFORE_UNLINK = 'sirsoft-sso.account.before_unlink';

    /** 연결 해제 직후 훅 */
    public const ACTION_AFTER_UNLINK = 'sirsoft-sso.account.after_unlink';

    /** 이 리스너의 우선순위 — 연결을 가리키는 표의 정리는 이보다 작은 수로 */
    public const PRIORITY = 50;

    /**
     * @param  SsoAccountRepositoryInterface  $accounts  연결 저장소
     */
    public function __construct(private readonly SsoAccountRepositoryInterface $accounts) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.after_withdraw' => ['method' => 'onWithdraw', 'priority' => self::PRIORITY, 'sync' => true],
            'core.user.before_delete' => ['method' => 'onDelete', 'priority' => self::PRIORITY, 'sync' => true],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 탈퇴 뒤 연결을 지웁니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫째 User)
     * @return void
     */
    public function onWithdraw(...$args): void
    {
        $this->clean($args[0] ?? null, 'withdraw');
    }

    /**
     * 삭제 전 연결을 지웁니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫째 User)
     * @return void
     */
    public function onDelete(...$args): void
    {
        $this->clean($args[0] ?? null, 'delete');
    }

    /**
     * 회원의 연결마다 해제 훅을 내고 지웁니다.
     *
     * @param  mixed  $user  회원
     * @param  string  $reason  사유 (withdraw, delete)
     * @return void
     */
    private function clean(mixed $user, string $reason): void
    {
        if (! $user instanceof User || (int) $user->getKey() <= 0) {
            return;
        }

        $accounts = $this->accounts->forUser((int) $user->getKey());
        if ($accounts->isEmpty()) {
            return;
        }

        foreach ($accounts as $account) {
            HookManager::doAction(self::ACTION_BEFORE_UNLINK, $account, $user, $reason);
        }

        $this->accounts->deleteForUser((int) $user->getKey());

        foreach ($accounts as $account) {
            HookManager::doAction(self::ACTION_AFTER_UNLINK, $account, $user, $reason);
        }
    }
}
