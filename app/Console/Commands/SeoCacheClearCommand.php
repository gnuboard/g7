<?php

namespace App\Console\Commands;

use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Console\Command;

/**
 * SEO 캐시 삭제 Artisan 커맨드
 *
 * 전체 또는 특정 레이아웃의 SEO 캐시를 삭제합니다.
 */
class SeoCacheClearCommand extends Command
{
    /**
     * @var string 커맨드 시그니처
     */
    protected $signature = 'seo:clear {--layout= : 특정 레이아웃의 캐시만 삭제}';

    /**
     * @var string 커맨드 설명
     */
    protected $description = 'SEO 캐시 삭제';

    /**
     * 커맨드를 실행합니다.
     *
     * @param SeoCacheManagerInterface $cacheManager SEO 캐시 매니저
     * @return int 종료 코드
     */
    public function handle(SeoCacheManagerInterface $cacheManager): int
    {
        $layout = $this->option('layout');

        if ($layout) {
            $count = $cacheManager->invalidateByLayout($layout);
            $this->info(__('seo.cache_cleared_layout', [
                'layout' => $layout,
                'count' => $count,
            ]));
        } else {
            $cacheManager->clearAll();
            $this->info(__('seo.cache_cleared_all'));
        }

        return Command::SUCCESS;
    }
}
