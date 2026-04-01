<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * SEO 캐시 워밍업 Artisan 커맨드
 *
 * 모든 SEO 레이아웃을 사전 렌더링하여 캐시를 준비합니다.
 * Phase 5의 SeoDeclarationCollector 구현 후 실제 워밍업 로직이 추가됩니다.
 */
class SeoCacheWarmupCommand extends Command
{
    /**
     * @var string 커맨드 시그니처
     */
    protected $signature = 'seo:warmup {--layout= : 특정 레이아웃만 워밍업}';

    /**
     * @var string 커맨드 설명
     */
    protected $description = 'SEO 캐시 워밍업 - 모든 SEO 레이아웃을 사전 렌더링합니다';

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $layout = $this->option('layout');

        if ($layout) {
            $this->info(__('seo.warmup_started_layout', ['layout' => $layout]));
        } else {
            $this->info(__('seo.warmup_started'));
        }

        // Phase 5의 SeoDeclarationCollector 구현 후 실제 워밍업 로직 추가 예정
        $this->info(__('seo.warmup_started'));

        return Command::SUCCESS;
    }
}
