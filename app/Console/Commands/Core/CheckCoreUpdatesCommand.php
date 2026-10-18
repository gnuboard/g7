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
     * @param CoreUpdateService $service 코어 업데이트 서비스
     * @return int 종료 코드
     */
    public function handle(CoreUpdateService $service): int
    {
        $this->info('코어 업데이트를 확인 중...');

        $result = $service->checkForUpdates();

        $this->newLine();
        $this->info("현재 버전: {$result['current_version']}");
        $this->info("최신 버전: {$result['latest_version']}");

        if (! empty($result['check_failed'])) {
            $this->newLine();
            $this->error('업데이트 확인 실패: '.($result['error'] ?? '알 수 없는 오류'));

            return Command::FAILURE;
        }

        if ($result['update_available']) {
            $this->newLine();
            $this->warn('새로운 업데이트가 있습니다!');
            $this->info('업데이트하려면: php artisan core:update');
        } else {
            $this->newLine();
            $this->info('현재 최신 버전입니다.');
        }

        return Command::SUCCESS;
    }
}
