<?php

namespace App\Console\Commands;

use App\Jobs\GenerateSitemapJob;
use Illuminate\Console\Command;

/**
 * Sitemap XML 생성 Artisan 커맨드
 *
 * 큐를 통한 비동기 실행 또는 --sync 옵션으로 동기 실행을 지원합니다.
 */
class SeoGenerateSitemapCommand extends Command
{
    /**
     * @var string 커맨드 시그니처
     */
    protected $signature = 'seo:generate-sitemap {--sync : 동기 실행}';

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
        if ($this->option('sync')) {
            GenerateSitemapJob::dispatchSync();
            $this->info('Sitemap이 생성되었습니다.');
        } else {
            GenerateSitemapJob::dispatch();
            $this->info('Sitemap 생성이 큐에 디스패치되었습니다.');
        }

        return Command::SUCCESS;
    }
}
