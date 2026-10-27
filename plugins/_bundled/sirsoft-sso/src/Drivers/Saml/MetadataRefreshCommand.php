<?php

namespace Plugins\Sirsoft\Sso\Drivers\Saml;

use App\Extension\HookManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;

/**
 * SAML IdP 메타데이터를 다시 받아 인증서 만료일을 기록하고, 만료가 가까우면 알림 훅을 냅니다 (매일 스케줄).
 *
 * 남은 날이 정책의 알림 일수(기본 30, 7) 중 하나와 같은 날에 `sirsoft-sso.idp.cert_expiring(IdentityProvider $idp, int $daysLeft)`
 * 을 한 번 발화한다(알림 정의와 수신자는 알림 영역이 구독해 정한다). 한 IdP 의 실패가 다른 IdP 와 다른 확장의 스케줄을 막지 않도록
 * 예외를 밖으로 내지 않는다.
 *
 * @since 1.0.0
 */
class MetadataRefreshCommand extends Command
{
    /** 인증서 만료 임박 훅 */
    public const ACTION_CERT_EXPIRING = 'sirsoft-sso.idp.cert_expiring';

    /** @var array<int, int> 정책이 없을 때의 알림 일수 */
    public const DEFAULT_NOTICE_DAYS = [30, 7];

    /** 정책 서비스 (정책 영역) */
    private const POLICY_SERVICE = 'Plugins\\Sirsoft\\Sso\\Services\\Policy\\PolicyService';

    /** @var string 명령 시그니처 */
    protected $signature = 'sirsoft-sso:metadata-refresh {--idp= : 이 IdP 키만}';

    /** @var string 명령 설명 */
    protected $description = 'SAML IdP 메타데이터 갱신과 서명 인증서 만료 알림';

    /**
     * 명령을 실행합니다.
     *
     * @return int 종료 코드 (실패가 있어도 성공 — 다른 스케줄 보호)
     */
    public function handle(): int
    {
        try {
            $query = IdentityProvider::query()->where('protocol', SamlDriver::KEY);
            if (is_string($this->option('idp')) && $this->option('idp') !== '') {
                $query->where('key', (string) $this->option('idp'));
            }
            $idps = $query->orderBy('id')->get();
        } catch (\Throwable $e) {
            report($e);

            return self::SUCCESS;
        }

        if ($idps->isEmpty()) {
            $this->line(__('sirsoft-sso::saml.metadata_refresh.none'));

            return self::SUCCESS;
        }

        $noticeDays = $this->noticeDays();
        foreach ($idps as $idp) {
            $this->refreshOne($idp, $noticeDays);
        }

        return self::SUCCESS;
    }

    /**
     * IdP 하나를 갱신합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  array<int, int>  $noticeDays  알림 일수
     * @return void 반환값 없음
     */
    private function refreshOne(IdentityProvider $idp, array $noticeDays): void
    {
        try {
            $driver = $idp->driver();
            if (! $driver instanceof SamlDriver) {
                return;
            }

            $expires = $driver->refreshMetadata($idp);
            $idp->forceFill(['metadata_refreshed_at' => now(), 'cert_expires_at' => $expires])->save();
            $this->info(__('sirsoft-sso::saml.metadata_refresh.refreshed', ['key' => $idp->key, 'expires' => $expires?->toDateString() ?? '-']));

            if ($expires !== null) {
                $daysLeft = (int) floor(CarbonImmutable::now()->startOfDay()->diffInDays($expires->startOfDay(), false));
                if (in_array($daysLeft, $noticeDays, true)) {
                    HookManager::doAction(self::ACTION_CERT_EXPIRING, $idp, $daysLeft);
                }
            }
        } catch (SsoException $e) {
            $this->warn(__('sirsoft-sso::saml.metadata_refresh.failed', ['key' => $idp->key, 'reason' => $e->errorCode]));
        } catch (\Throwable $e) {
            report($e);
            $this->warn(__('sirsoft-sso::saml.metadata_refresh.failed', ['key' => $idp->key, 'reason' => class_basename($e)]));
        }
    }

    /**
     * 알림 일수 — 정책 값(`cert_expiry_notice_days`), 없으면 30, 7.
     *
     * @return array<int, int> 일수
     */
    private function noticeDays(): array
    {
        try {
            if (class_exists(self::POLICY_SERVICE) && method_exists(self::POLICY_SERVICE, 'get')) {
                $days = app(self::POLICY_SERVICE)->get('cert_expiry_notice_days');
                if (is_array($days) && $days !== []) {
                    return array_values(array_map('intval', $days));
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return self::DEFAULT_NOTICE_DAYS;
    }
}
