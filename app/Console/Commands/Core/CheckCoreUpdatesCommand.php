<?php

namespace App\Console\Commands\Core;

use App\Services\CoreUpdateService;
use Illuminate\Console\Command;

class CheckCoreUpdatesCommand extends Command
{
    protected $signature = 'core:check-updates';

    protected $description = '그누보드7 코어의 최신 업데이트를 확인합니다';

    /**
     * 커맨드를 실행합니다.
     *
     * @param  CoreUpdateService  $service  코어 업데이트 서비스
     * @return int 종료 코드
     */
    public function handle(CoreUpdateService $service): int
    {
        $this->info(__('common.console.core_check_updates.checking'));

        $result = $service->checkForUpdates();

        $this->newLine();
        $this->info(__('common.console.core_check_updates.current_version', ['version' => $result['current_version']]));
        $this->info(__('common.console.core_check_updates.latest_version', ['version' => $result['latest_version']]));

        if (! empty($result['check_failed'])) {
            $this->newLine();
            $this->error(__('common.console.core_check_updates.failed', [
                'error' => $result['error'] ?? __('common.console.core_check_updates.unknown_error'),
            ]));

            return Command::FAILURE;
        }

        if ($result['update_available']) {
            $this->newLine();
            $this->warn(__('common.console.core_check_updates.update_available'));
            $this->info(__('common.console.core_check_updates.update_hint'));
        } else {
            $this->newLine();
            $this->info(__('common.console.core_check_updates.up_to_date'));
        }

        return Command::SUCCESS;
    }
}
