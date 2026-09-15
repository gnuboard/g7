<?php

namespace Plugins\Sirsoft\Koreaexim\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Plugins\Sirsoft\Koreaexim\Enums\RunStatus;
use Plugins\Sirsoft\Koreaexim\Enums\RunTrigger;
use Plugins\Sirsoft\Koreaexim\Services\ExchangeRateUpdateService;

class UpdateExchangeRatesCommand extends Command
{
    protected $signature = 'sirsoft-koreaexim:update {--scheduled} {--retry} {--date=} {--dry-run}';

    protected $description = '수출입은행 환율을 갱신합니다';

    /**
     * 등록된 처리를 실행합니다.
     *
     * @param  ExchangeRateUpdateService  $service  service 입력값
     * @return int 처리 결과
     */
    public function handle(ExchangeRateUpdateService $service): int
    {
        $date = $this->option('date');
        if ($date !== null && (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || ! Carbon::hasFormatWithModifiers($date, 'Y-m-d'))) {
            $this->error(__('sirsoft-koreaexim::validation.date'));

            return self::FAILURE;
        }
        try {
            $trigger = $this->option('retry') ? RunTrigger::Retry : ($this->option('scheduled') ? RunTrigger::Scheduled : RunTrigger::Manual);
            $run = $service->execute($trigger, $date, (bool) $this->option('dry-run'));
            if ($run === null) {
                return self::SUCCESS;
            }
            $this->line($run->status->label());

            return in_array($run->status, [RunStatus::Success, RunStatus::Partial, RunStatus::NoData, RunStatus::Skipped, RunStatus::DryRun], true) ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable) {
            // 외부 통신 예외에 키를 포함한 URL이 들어갈 수 있어 원문을 출력하지 않습니다.
            $this->error(__('sirsoft-koreaexim::messages.execution_failed'));

            return self::FAILURE;
        }
    }
}
