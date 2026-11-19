<?php

namespace Plugins\Sirsoft\Sso\Console\Commands\Provisioning;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Plugins\Sirsoft\Sso\Services\Provisioning\DeprovisionService;

/**
 * 퇴사 N일 뒤 탈퇴(익명화) — IdP 설정 `deprovision.withdraw_after_days` 가 있는 IdP 만 (스케줄 매일).
 *
 * `--as-of` 로 기준 시각을 앞당겨 시험, 실측한다 — 운영 환경에서는 거절한다. 실패해도 예외를 밖으로 내지 않는다.
 *
 * @since 1.0.0
 */
class WithdrawDeprovisionedCommand extends Command
{
    /** @var string 명령 시그니처 */
    protected $signature = 'sirsoft-sso:withdraw-deprovisioned
        {--as-of= : 기준 시각 (시험, 실측 전용 — 운영 환경 거절)}';

    /** @var string 명령 설명 */
    protected $description = 'SSO 퇴사 처리 뒤 정한 날이 지난 회원을 탈퇴(익명화)시킵니다';

    /**
     * 명령을 실행합니다.
     *
     * @param  DeprovisionService  $deprovision  퇴사 처리
     * @return int 종료 코드
     */
    public function handle(DeprovisionService $deprovision): int
    {
        $asOf = $this->option('as-of');
        if (is_string($asOf) && $asOf !== '') {
            if (app()->environment('production')) {
                $this->error(__('sirsoft-sso::provisioning.command.production_as_of'));

                return self::FAILURE;
            }

            try {
                $at = Carbon::parse($asOf);
            } catch (\Throwable) {
                $this->error(__('sirsoft-sso::provisioning.command.invalid_as_of'));

                return self::FAILURE;
            }
        } else {
            $at = now();
        }

        try {
            $summary = $deprovision->withdrawDue($at);
        } catch (\Throwable $e) {
            report($e);
            $this->error(__('sirsoft-sso::provisioning.command.failed', ['command' => $this->getName()]));

            return self::FAILURE;
        }

        $this->info(__('sirsoft-sso::provisioning.command.withdraw', $summary));

        return self::SUCCESS;
    }
}
