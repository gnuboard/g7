<?php

namespace App\Console\Commands;

use App\Jobs\GenerateSitemapJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Sitemap XML 생성 Artisan 커맨드
 *
 * 큐 드라이버 설정에 따라 비동기/동기 실행을 자동 선택합니다.
 * --sync 옵션을 명시하면 큐 드라이버와 무관하게 동기 실행합니다.
 */
class SeoGenerateSitemapCommand extends Command
{
    /**
     * @var string 커맨드 시그니처
     */
    protected $signature = 'seo:generate-sitemap {--sync : 큐 드라이버를 무시하고 동기 실행}';

    /**
     * @var string 커맨드 설명
     */
    protected $description = 'Sitemap XML을 생성합니다';

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $forceSync = (bool) $this->option('sync');
        // queue.default 는 SettingsServiceProvider 가 drivers.queue_driver 와 동기화하되,
        // testing 환경에서는 phpunit.xml 값을 보존하므로 격리가 유지된다.
        $connection = (string) Config::get('queue.default', 'sync');
        $isSyncDriver = $connection === 'sync';

        if ($forceSync || $isSyncDriver) {
            GenerateSitemapJob::dispatchSync();
            $this->info('Sitemap이 생성되었습니다.');
        } else {
            GenerateSitemapJob::dispatch();
            $this->info('Sitemap 생성이 큐에 디스패치되었습니다.');
        }

        return Command::SUCCESS;
    }
}
