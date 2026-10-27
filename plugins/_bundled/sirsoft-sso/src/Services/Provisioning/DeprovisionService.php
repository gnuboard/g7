<?php

namespace Plugins\Sirsoft\Sso\Services\Provisioning;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\CacheInterface;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\UserService;
use App\Support\Auth\SystemOperation;
use Illuminate\Support\Carbon;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Models\SsoSession;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\ProvisioningAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Services\AccountResolver;
use Plugins\Sirsoft\Sso\Services\SessionService;

/**
 * 퇴사 처리와 재활성, 퇴사 N일 뒤 탈퇴.
 *
 * 퇴사 = 연결 비활성 + (회원에게 다른 활성 연결이 없으면) 회원 차단과 토큰 전량 회수. 회원을 지우지 않는다(게시글, 주문 기록 보존).
 * 다른 IdP 연결이 살아 있으면 차단하지 않는다(여러 회사 겸직) — 그 IdP 로 받은 로그인만 끊는다.
 *
 * 차단을 풀 때는 회원의 차단 시각이 이 연결이 차단한 시각(`blocked_user_at`)과 같을 때만 푼다. 퇴사 전에 이미 다른 이유로 차단돼
 * 있었으면 이 연결이 차단한 것이 아니므로 기록하지 않고, 퇴사 뒤 관리자가 다시 차단했으면 시각이 달라진다.
 *
 * 최고관리자는 코어가 차단을 거절하므로 토큰 전량 회수 + 알림 훅만 한다. 모든 경로는 행위자 없는 시스템 작업 문맥에서 돈다
 * (운영자가 건 사람용 본인인증 정책이 대상 회원으로 판정돼 멈추지 않게).
 *
 * @since 1.0.0
 */
class DeprovisionService
{
    use ResolvesActivityLogType;

    /** 퇴사 처리 직후 훅 */
    public const ACTION_AFTER_DEPROVISION = 'sirsoft-sso.account.after_deprovision';

    /** 재활성 직후 훅 (로그인 재활성과 같은 이름) */
    public const ACTION_AFTER_REACTIVATE = AccountResolver::ACTION_AFTER_REACTIVATE;

    /** 차단할 수 없는 최고관리자 계정의 퇴사 직후 훅 (알림) */
    public const ACTION_SUPER_ADMIN_DEPROVISIONED = 'sirsoft-sso.account.super_admin_deprovisioned';

    /** 퇴사 N일 탈퇴에서 관리자 계정이라 건너뛴 직후 훅 (알림, 연결마다 1회) */
    public const ACTION_ADMIN_WITHDRAW_SKIPPED = 'sirsoft-sso.account.admin_withdraw_skipped';

    /** @var array<int, string> 퇴사 출처 */
    public const SOURCES = ['scim', 'periodic', 'login', 'admin'];

    /** 회원 차단 사유 문구 키 */
    public const BLOCK_REASON_KEY = 'sirsoft-sso::provisioning.block_reason.deprovisioned';

    /** 퇴사 후 탈퇴 일수 상한 (10년) */
    public const MAX_WITHDRAW_DAYS = 3650;

    /** 한 회차 N일 뒤 탈퇴 처리 상한 (IdP 마다) */
    public const WITHDRAW_BATCH = 500;

    /**
     * @param  ProvisioningAccountRepositoryInterface  $accounts  연결 저장소
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @param  SessionService  $sessions  세션 서비스
     * @param  UserService  $users  코어 회원 서비스
     * @param  CacheInterface  $cache  플러그인 캐시 (관리자 탈퇴 건너뜀 알림 1회)
     */
    public function __construct(
        private readonly ProvisioningAccountRepositoryInterface $accounts,
        private readonly IdentityProviderRepositoryInterface $idps,
        private readonly SessionService $sessions,
        private readonly UserService $users,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 연결을 퇴사 처리합니다.
     *
     * 이미 비활성인 연결도 다시 부르면 그 IdP 세션을 끊고 남은 토큰을 회수한다(이 연결이 차단한 회원일 때) — 같은 요청을 두 번
     * 받아도 결과가 같다.
     *
     * @param  SsoAccount  $account  연결
     * @param  string  $source  출처 (scim, periodic, login, admin)
     * @return array{blocked: bool, kept_by_other_link: bool, super_admin: bool} 결과
     */
    public function deprovision(SsoAccount $account, string $source): array
    {
        $source = in_array($source, self::SOURCES, true) ? $source : 'admin';

        return SystemOperation::run('external:sirsoft-sso:deprovision:'.$source, function () use ($account, $source): array {
            $result = ['blocked' => false, 'kept_by_other_link' => false, 'super_admin' => false];
            $user = $account->user;
            $wasActive = $account->active;

            if ($wasActive) {
                $this->accounts->update($account, [
                    'active' => false,
                    'deprovisioned_at' => now(),
                    'deprovision_reason' => $source,
                    'blocked_user_at' => null,
                ]);
            }

            if (! $user instanceof User || $user->isWithdrawn()) {
                return $result;
            }

            $this->sessions->endForUserIdp((int) $account->idp_id, (int) $user->getKey(), SsoSession::END_DEPROVISIONED);

            if ($this->accounts->hasOtherActive((int) $user->getKey(), (int) $account->getKey())) {
                $result['kept_by_other_link'] = true;
            } elseif ($user->is_super === true) {
                $this->users->revokeAllSessions($user, null);
                $result['super_admin'] = true;
                if ($wasActive) {
                    HookManager::doAction(self::ACTION_SUPER_ADMIN_DEPROVISIONED, $user, $account, $source);
                }
            } else {
                $result['blocked'] = $this->block($account, $user, $source);
            }

            if ($wasActive) {
                HookManager::doAction(self::ACTION_AFTER_DEPROVISION, $account, $user, $source);
            }

            return $result;
        });
    }

    /**
     * 비활성 연결을 다시 켜고, 이 연결이 차단한 회원이면 차단을 풉니다 (SCIM `active=true`, 주기 점검 활성 확인).
     *
     * @param  SsoAccount  $account  연결
     * @param  string  $source  출처 (scim, periodic)
     * @return bool 다시 켰으면 true (이미 활성이면 false)
     */
    public function reactivate(SsoAccount $account, string $source): bool
    {
        if ($account->active) {
            return false;
        }

        return SystemOperation::run('external:sirsoft-sso:reactivate:'.$source, function () use ($account, $source): bool {
            $user = $account->user;
            $blockedByThis = $user instanceof User && self::blockedByAccount($account, $user);

            $this->accounts->update($account, [
                'active' => true,
                'deprovisioned_at' => null,
                'deprovision_reason' => null,
                'blocked_user_at' => null,
            ]);

            if ($blockedByThis) {
                $this->users->unblockUser($user, [
                    'reason_key' => 'sirsoft-sso::provisioning.block_reason.reactivated',
                    'source' => 'sirsoft-sso:'.$source,
                ]);
            }

            if ($user instanceof User) {
                HookManager::doAction(self::ACTION_AFTER_REACTIVATE, $account, $user, $source);
            }

            return true;
        });
    }

    /**
     * 퇴사 N일 뒤 탈퇴(익명화)를 처리합니다 — IdP 설정 `deprovision.withdraw_after_days` 가 있는 IdP 만.
     *
     * 이 연결이 차단한 상태가 그대로인 회원만 탈퇴시킨다(그 뒤 재활성, 관리자 해제, 다른 이유 차단이면 건너뜀). 관리자 계정은 코어가
     * 탈퇴를 거절하므로 건너뛰고 한 번만 알린다. 한 회원의 실패가 다른 회원 처리를 막지 않는다.
     *
     * @param  Carbon  $asOf  기준 시각 (시험, 실측은 명령 `--as-of`)
     * @return array{withdrawn: int, skipped_admin: int, skipped: int, failed: int} 결과
     */
    public function withdrawDue(Carbon $asOf): array
    {
        $summary = ['withdrawn' => 0, 'skipped_admin' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($this->idps->enabled() as $idp) {
            $days = self::withdrawAfterDays($idp);
            if ($days === null) {
                continue;
            }

            foreach ($this->accounts->deprovisionedBefore((int) $idp->getKey(), $asOf->copy()->subDays($days), self::WITHDRAW_BATCH) as $account) {
                $outcome = $this->withdrawOne($account, $idp);
                $summary[$outcome]++;
            }
        }

        return $summary;
    }

    /**
     * IdP 의 퇴사 후 탈퇴 일수를 읽습니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return int|null 일수 (끄면 null)
     */
    public static function withdrawAfterDays(IdentityProvider $idp): ?int
    {
        $days = is_array($idp->deprovision) ? ($idp->deprovision['withdraw_after_days'] ?? null) : null;

        // audit:allow service-config-value-clamp reason: 상한 3650일(10년)은 저장 검증 전 값(직접 DB 수정 등)에서 날짜 계산이 넘치지 않게 하는 도메인 상수
        return is_numeric($days) && (int) $days > 0 ? min(self::MAX_WITHDRAW_DAYS, (int) $days) : null;
    }

    /**
     * 회원이 지금 이 연결 때문에 차단돼 있는지 확인합니다.
     *
     * @param  SsoAccount  $account  연결
     * @param  User  $user  회원
     * @return bool 이 연결이 건 차단이 그대로면 true
     */
    public static function blockedByAccount(SsoAccount $account, User $user): bool
    {
        return $account->blocked_user_at !== null
            && $user->status === UserStatus::Blocked->value
            && $user->blocked_at !== null
            && $user->blocked_at->equalTo($account->blocked_user_at);
    }

    /**
     * 회원을 차단하고, 이 연결이 차단했으면 그 시각을 남깁니다.
     *
     * 퇴사 전에 이미 차단돼 있던 회원은 토큰만 다시 회수하고 시각을 남기지 않는다 — 재활성 때 그 차단을 풀지 않게.
     *
     * @param  SsoAccount  $account  연결
     * @param  User  $user  회원
     * @param  string  $source  출처
     * @return bool 이 연결이 차단을 걸었으면 true
     */
    private function block(SsoAccount $account, User $user, string $source): bool
    {
        $alreadyBlocked = $user->status === UserStatus::Blocked->value;
        if ($alreadyBlocked && $account->blocked_user_at === null) {
            $this->users->blockUser($user, ['reason_key' => self::BLOCK_REASON_KEY, 'source' => 'sirsoft-sso:'.$source]);

            return false;
        }

        $blocked = $this->users->blockUser($user, ['reason_key' => self::BLOCK_REASON_KEY, 'source' => 'sirsoft-sso:'.$source]);
        if ($blocked->blocked_at !== null && ! $alreadyBlocked) {
            $this->accounts->update($account, ['blocked_user_at' => $blocked->blocked_at]);
        }

        return ! $alreadyBlocked;
    }

    /**
     * 연결 1건의 N일 뒤 탈퇴를 처리합니다.
     *
     * @param  SsoAccount  $account  연결
     * @param  IdentityProvider  $idp  IdP
     * @return string 결과 키 (withdrawn, skipped_admin, skipped, failed)
     */
    private function withdrawOne(SsoAccount $account, IdentityProvider $idp): string
    {
        $user = $account->user;
        if (! $user instanceof User || $user->isWithdrawn() || ! self::blockedByAccount($account, $user)) {
            return 'skipped';
        }

        if (AccountResolver::isPrivileged($user)) {
            $notified = 'withdraw_admin_notified:'.$account->getKey();
            if (! $this->cache->has($notified)) {
                $this->cache->put($notified, 1, 60 * 60 * 24 * 365);
                HookManager::doAction(self::ACTION_ADMIN_WITHDRAW_SKIPPED, $user, $account);
            }

            return 'skipped_admin';
        }

        $name = (string) $user->name;

        try {
            SystemOperation::run('external:sirsoft-sso:withdraw-deprovisioned', fn () => $this->users->withdrawUser($user));
        } catch (\Throwable $e) {
            report($e);

            return 'failed';
        }

        $this->logActivity('sso.withdrawn_after_deprovision', [
            'loggable' => $user,
            'description_key' => 'sirsoft-sso::activity_log.description.sso.withdrawn_after_deprovision',
            'description_params' => ['user_name' => $name, 'idp' => $idp->localizedLabel()],
            'properties' => [
                'user_id' => (int) $user->getKey(),
                'idp_id' => (int) $idp->getKey(),
                'deprovisioned_at' => $account->deprovisioned_at?->toIso8601String(),
            ],
        ]);

        return 'withdrawn';
    }
}
