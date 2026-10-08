<?php

namespace Plugins\Sirsoft\Koreaexim\Console\Commands;

use Illuminate\Console\Command;
use Plugins\Sirsoft\Koreaexim\Repositories\Contracts\RateRunRepositoryInterface;

class PruneRunHistoryCommand extends Command
{
    protected $signature = 'sirsoft-koreaexim:prune-history';

    protected $description = '보존기간이 지난 환율 이력을 정리합니다';

    /**
     * 등록된 처리를 실행합니다.
     *
     * @param  RateRunRepositoryInterface  $runs  runs 입력값
     * @return int 처리 결과
     */
    public function handle(RateRunRepositoryInterface $runs): int
    {
        $count = $runs->prune((int) plugin_setting('sirsoft-koreaexim', 'history_retention_days', 365));
        $this->info(trans_choice('sirsoft-koreaexim::messages.pruned', $count));

        return self::SUCCESS;
    }
}
