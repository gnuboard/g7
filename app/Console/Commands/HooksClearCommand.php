<?php

namespace App\Console\Commands;

use App\Extension\HookCacheManager;
use Illuminate\Console\Command;

/**
 * 정적 훅 매핑 캐시 삭제 Artisan 커맨드.
 *
 * bootstrap/cache/hooks.php 를 삭제한다. 캐시 삭제 후에는 부팅 시 스캔 폴백으로
 * 동작하므로 항상 안전하다 (route:clear 동형).
 */
class HooksClearCommand extends Command
{
    /**
     * @var string 커맨드 시그니처
     */
    protected $signature = 'hooks:clear';

    /**
     * @var string 커맨드 설명
     */
    protected $description = '정적 훅 매핑 캐시 삭제 (bootstrap/cache/hooks.php)';

    /**
     * 커맨드를 실행합니다.
     *
     * @param  HookCacheManager  $hookCacheManager  훅 캐시 매니저
     * @return int 종료 코드
     */
    public function handle(HookCacheManager $hookCacheManager): int
    {
        if (! $hookCacheManager->isCached()) {
            $this->info(__('hooks.cache_absent'));

            return Command::SUCCESS;
        }

        $hookCacheManager->clear();
        $this->info(__('hooks.cache_cleared'));

        return Command::SUCCESS;
    }
}
