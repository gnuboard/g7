<?php

namespace App\Console\Commands\Core;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Extension\Integrity\BuiltinTrustKeys;
use App\Services\CoreUpdateService;
use Illuminate\Console\Command;

class CheckCoreUpdatesCommand extends Command
{
    use LocalizesDescription;

    protected $signature = 'core:check-updates';

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

        // 코어 패키지는 내장 신뢰 키로만 검증한다 — 쓸 수 있는 키가 없으면(비었거나 전부 폐기) 공식 서명 코어 업데이트도
        // 「신뢰하지 않는 키」로 막힌다. about·extension:trust-key list 와 같은 판정이다
        if (! BuiltinTrustKeys::hasUsableKey()) {
            $this->newLine();
            $this->warn('⚠️  '.__('extensions.commands.trust_key.builtin_empty'));
        }

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
