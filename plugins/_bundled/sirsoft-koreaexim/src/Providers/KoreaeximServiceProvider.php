<?php

namespace Plugins\Sirsoft\Koreaexim\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\Sirsoft\Koreaexim\Console\Commands\PruneRunHistoryCommand;
use Plugins\Sirsoft\Koreaexim\Console\Commands\UpdateExchangeRatesCommand;
use Plugins\Sirsoft\Koreaexim\Repositories\Contracts\RateRunRepositoryInterface;
use Plugins\Sirsoft\Koreaexim\Repositories\RateRunRepository;

class KoreaeximServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'sirsoft-koreaexim';

    protected array $repositories = [RateRunRepositoryInterface::class => RateRunRepository::class];

    /**
     * 리포지토리와 명령을 등록합니다.
     *
     * @return void 반환값 없음
     */
    public function boot(): void
    {
        parent::boot();
        if ($this->app->runningInConsole()) {
            $this->commands([UpdateExchangeRatesCommand::class, PruneRunHistoryCommand::class]);
        }
    }
}
