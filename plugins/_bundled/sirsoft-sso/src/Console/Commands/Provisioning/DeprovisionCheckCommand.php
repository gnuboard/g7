<?php

namespace Plugins\Sirsoft\Sso\Console\Commands\Provisioning;

use Illuminate\Console\Command;
use Plugins\Sirsoft\Sso\Services\Provisioning\PeriodicCheckService;

/**
 * 주기 점검 — 재직 여부를 IdP 에 다시 묻습니다 (스케줄 5분마다, 실제 점검 간격은 IdP 설정 `periodic_minutes`).
 *
 * 실패해도 예외를 밖으로 내지 않는다 — 같은 스케줄러에서 도는 다른 확장의 작업을 막지 않게.
 *
 * @since 1.0.0
 */
class DeprovisionCheckCommand extends Command
{
    /** @var string 명령 시그니처 */
    protected $signature = 'sirsoft-sso:deprovision-check';

    /** @var string 명령 설명 */
    protected $description = 'SSO 주기 점검 — 퇴사한 회원을 IdP 에 다시 물어 차단하고 복귀한 회원을 다시 켭니다';

    /**
     * 명령을 실행합니다.
     *
     * @param  PeriodicCheckService  $checks  주기 점검
     * @return int 종료 코드
     */
    public function handle(PeriodicCheckService $checks): int
    {
        try {
            $summary = $checks->run();
        } catch (\Throwable $e) {
            report($e);
            $this->error(__('sirsoft-sso::provisioning.command.failed', ['command' => $this->getName()]));

            return self::FAILURE;
        }

        $this->info(__('sirsoft-sso::provisioning.command.deprovision_check', $summary));

        return self::SUCCESS;
    }
}
