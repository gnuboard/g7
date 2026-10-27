<?php

namespace Plugins\Sirsoft\Sso\Services\Provisioning;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Sso\Contracts\CredentialDriverInterface;
use Plugins\Sirsoft\Sso\DTO\DirectoryEntry;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\ProvisioningAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Services\RoleSyncService;

/**
 * 주기 점검 — IdP 에 재직 여부를 다시 물어 퇴사자를 막고 복귀자를 다시 켭니다.
 *
 * 프로토콜별 수단:
 *  - LDAP/AD: 디렉터리 `lookup()` — 없거나 비활성(AD ACCOUNTDISABLE 등)이면 퇴사. 그룹 출처가 `ldap` 이면 역할 매핑도 다시 돌린다.
 *  - OIDC: 저장한 갱신 토큰으로 토큰 갱신 — `invalid_grant` 면 퇴사, 성공하면 새 갱신 토큰으로 교체(회전하는 IdP).
 *  - SAML: 표준 수단이 없다 — IdP 설정 `lookup_directory_idp_id`(같은 회사의 LDAP IdP)가 있으면 그 디렉터리로 찾고(`lookup_match`:
 *    `email` 기본 / `subject`), 없으면 건너뛴다.
 *
 * IdP 장애(디렉터리 연결 실패, 토큰 엔드포인트 오류)는 퇴사로 오판하지 않는다 — 그 IdP 의 이번 회차를 멈추고 다음 회차에 다시 본다.
 * 한 회차에 IdP 마다 500 연결까지 보고, 남은 연결은 마지막 점검이 오래된 순으로 다음 회차가 이어서 본다.
 *
 * @since 1.0.0
 */
class PeriodicCheckService
{
    /** 한 회차 IdP 마다 볼 연결 상한 */
    public const BATCH = 500;

    /** 점검 간격 기본값(분) */
    public const DEFAULT_MINUTES = 60;

    /** 점검 간격 상한(분) — 7일 */
    public const MAX_MINUTES = 10080;

    /** 점검 간격 하한(분) — 스케줄이 5분마다 돈다 */
    public const MIN_MINUTES = 5;

    /**
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @param  ProvisioningAccountRepositoryInterface  $accounts  연결 조회
     * @param  DeprovisionService  $deprovision  퇴사, 재활성
     * @param  RoleSyncService  $roles  역할 매핑
     */
    public function __construct(
        private readonly IdentityProviderRepositoryInterface $idps,
        private readonly ProvisioningAccountRepositoryInterface $accounts,
        private readonly DeprovisionService $deprovision,
        private readonly RoleSyncService $roles,
    ) {}

    /**
     * 주기 점검이 켜진 IdP 를 한 회차 점검합니다.
     *
     * @param  Carbon|null  $now  기준 시각 (null 이면 지금)
     * @param  int  $batch  IdP 마다 볼 연결 상한 (시험에서 줄인다)
     * @return array{idps: int, checked: int, deprovisioned: int, reactivated: int, failed: int} 결과
     */
    public function run(?Carbon $now = null, int $batch = self::BATCH): array
    {
        $now ??= now();
        $summary = ['idps' => 0, 'checked' => 0, 'deprovisioned' => 0, 'reactivated' => 0, 'failed' => 0];

        foreach ($this->idps->enabled() as $idp) {
            if (! self::enabledFor($idp)) {
                continue;
            }

            $result = $this->checkIdp($idp, $now, max(1, $batch));
            if ($result === null) {
                continue;
            }

            $summary['idps']++;
            foreach (['checked', 'deprovisioned', 'reactivated', 'failed'] as $key) {
                $summary[$key] += $result[$key];
            }
        }

        return $summary;
    }

    /**
     * IdP 의 주기 점검이 켜져 있는지 확인합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return bool 켜져 있으면 true
     */
    public static function enabledFor(IdentityProvider $idp): bool
    {
        return is_array($idp->deprovision) && (bool) ($idp->deprovision['periodic'] ?? false);
    }

    /**
     * IdP 의 점검 간격(분)을 읽습니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return int 간격
     */
    public static function intervalMinutes(IdentityProvider $idp): int
    {
        $minutes = is_array($idp->deprovision) ? ($idp->deprovision['periodic_minutes'] ?? null) : null;

        // audit:allow service-config-value-clamp reason: 하한은 스케줄 주기(5분), 상한은 7일 — 저장 검증 전 값에서도 점검이 멈추거나 과도하게 돌지 않게 하는 도메인 상수
        return is_numeric($minutes) ? max(self::MIN_MINUTES, min(self::MAX_MINUTES, (int) $minutes)) : self::DEFAULT_MINUTES;
    }

    /**
     * IdP 하나를 점검합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  Carbon  $now  기준 시각
     * @param  int  $batch  볼 연결 상한
     * @return array{checked: int, deprovisioned: int, reactivated: int, failed: int}|null 결과 (이 프로토콜은 점검 수단이 없으면 null)
     */
    private function checkIdp(IdentityProvider $idp, Carbon $now, int $batch): ?array
    {
        $checker = $this->checkerFor($idp);
        if ($checker === null) {
            return null;
        }

        $result = ['checked' => 0, 'deprovisioned' => 0, 'reactivated' => 0, 'failed' => 0];
        $due = $this->accounts->dueForPeriodicCheck((int) $idp->getKey(), $now->copy()->subMinutes(self::intervalMinutes($idp)), $batch);

        foreach ($due as $account) {
            if (! $account->user instanceof User || $account->user->isWithdrawn()) {
                $this->accounts->update($account, ['periodic_checked_at' => $now]);

                continue;
            }

            try {
                $verdict = $checker($account);
            } catch (SsoException $e) {
                Log::warning('[sirsoft-sso] 주기 점검을 멈춥니다 — IdP 응답 없음', ['idp_id' => $idp->getKey(), 'code' => $e->errorCode]);
                $result['failed']++;
                break;
            } catch (\Throwable $e) {
                report($e);
                $result['failed']++;

                continue;
            }

            $result['checked']++;
            $this->accounts->update($account, ['periodic_checked_at' => $now]);

            if ($verdict === null) {
                continue;
            }

            if ($verdict === false && $account->active) {
                $this->deprovision->deprovision($account, 'periodic');
                $result['deprovisioned']++;
            } elseif ($verdict === true && ! $account->active && $account->deprovision_reason === 'periodic') {
                $this->deprovision->reactivate($account, 'periodic');
                $result['reactivated']++;
            }
        }

        $this->idps->recordOutcome($idp, $result['failed'] > 0 ? 'periodic_check_failed' : null);
        $this->accounts->markIdpChecked((int) $idp->getKey(), $now);

        return $result;
    }

    /**
     * 프로토콜별 판정 함수를 고릅니다 — 연결 하나를 받아 재직이면 true, 퇴사면 false, 판정 불가면 null.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return (callable(SsoAccount): ?bool)|null 판정 함수 (점검 수단이 없으면 null)
     */
    private function checkerFor(IdentityProvider $idp): ?callable
    {
        if ($idp->protocol === 'ldap') {
            $driver = $this->credentialDriver($idp);

            return $driver === null ? null : function (SsoAccount $account) use ($idp, $driver): bool {
                $entry = $driver->lookup($idp, $account->subject);
                $this->syncDirectoryGroups($idp, $account, $entry);

                return $entry !== null && $entry->active;
            };
        }

        if ($idp->protocol === 'oidc') {
            $driver = $idp->driver();

            return method_exists($driver, 'refresh') ? fn (SsoAccount $account): ?bool => $this->checkOidc($idp, $driver, $account) : null;
        }

        if ($idp->protocol === 'saml') {
            $directory = $this->lookupDirectory($idp);
            $driver = $directory !== null ? $this->credentialDriver($directory) : null;
            if ($directory === null || $driver === null) {
                return null;
            }

            $byEmail = $idp->configValue('lookup_match', 'email') !== 'subject';

            return function (SsoAccount $account) use ($directory, $driver, $byEmail): ?bool {
                $key = $byEmail ? $account->email : $account->subject;
                if (! is_string($key) || $key === '') {
                    return null;
                }

                $entry = $driver->lookup($directory, $key);

                return $entry !== null && $entry->active;
            };
        }

        return null;
    }

    /**
     * OIDC 연결 하나를 갱신 토큰으로 확인합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  object  $driver  OIDC 드라이버 (`refresh()` 보유)
     * @param  SsoAccount  $account  연결
     * @return bool|null 재직 true, 퇴사 false, 갱신 토큰이 없어 판정 불가 null
     */
    private function checkOidc(IdentityProvider $idp, object $driver, SsoAccount $account): ?bool
    {
        $token = $account->refresh_token;
        if (! is_string($token) || $token === '') {
            return null;
        }

        $outcome = $driver->refresh($idp, $token);
        $this->accounts->update($account, ['refresh_token' => $outcome['active'] ? $outcome['refresh_token'] : null]);

        return (bool) $outcome['active'];
    }

    /**
     * 그룹 출처가 디렉터리(`ldap`)인 IdP 면 디렉터리 그룹으로 역할 매핑을 다시 돌립니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  SsoAccount  $account  연결
     * @param  DirectoryEntry|null  $entry  디렉터리 계정
     * @return void
     */
    private function syncDirectoryGroups(IdentityProvider $idp, SsoAccount $account, ?DirectoryEntry $entry): void
    {
        if ($entry === null || ! $entry->active || ! $account->active || $idp->group_source !== 'ldap' || ! $account->user instanceof User) {
            return;
        }

        try {
            $this->roles->sync($account->user, $account, $idp, $entry->groups, RoleSyncService::TRIGGER_UPDATE);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * SAML IdP 의 재직 조회용 디렉터리(같은 사이트의 활성 LDAP IdP)를 찾습니다.
     *
     * @param  IdentityProvider  $idp  SAML IdP
     * @return IdentityProvider|null 디렉터리 IdP
     */
    private function lookupDirectory(IdentityProvider $idp): ?IdentityProvider
    {
        $id = $idp->configValue('lookup_directory_idp_id');
        if (! is_numeric($id)) {
            return null;
        }

        $directory = $this->idps->findById((int) $id);

        return $directory !== null && $directory->enabled && $directory->protocol === 'ldap' ? $directory : null;
    }

    /**
     * IdP 의 디렉터리 드라이버를 꺼냅니다 (서버에서 쓸 수 없으면 null).
     *
     * @param  IdentityProvider  $idp  IdP
     * @return CredentialDriverInterface|null 드라이버
     */
    private function credentialDriver(IdentityProvider $idp): ?CredentialDriverInterface
    {
        try {
            $driver = $idp->driver();
        } catch (SsoException) {
            return null;
        }

        return $driver instanceof CredentialDriverInterface && $driver->isAvailable() ? $driver : null;
    }
}
