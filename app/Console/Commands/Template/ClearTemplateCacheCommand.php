<?php

namespace App\Console\Commands\Template;

use App\Contracts\Extension\CacheInterface;
use App\Extension\TemplateManager;
use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ClearTemplateCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'template:cache-clear
        {identifier? : 특정 템플릿의 캐시만 삭제 (생략 시 모든 템플릿)}';

    /**
     * The console command description.
     */
    protected $description = '템플릿 관련 캐시를 삭제합니다';

    /**
     * 템플릿 관리자
     */
    public function __construct(
        private TemplateManager $templateManager,
        private CacheInterface $cache
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $identifier = $this->argument('identifier');

        try {
            // 템플릿 디렉토리 스캔 및 로드
            $this->templateManager->loadTemplates();

            if ($identifier) {
                // 특정 템플릿 캐시만 삭제
                $this->clearSingleTemplateCache($identifier);
            } else {
                // 모든 템플릿 캐시 삭제
                $this->clearAllTemplateCache();
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('템플릿 캐시 삭제 실패', [
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * 특정 템플릿의 캐시 삭제
     */
    private function clearSingleTemplateCache(string $identifier): void
    {
        // 템플릿 존재 확인
        $template = $this->templateManager->getTemplate($identifier);
        if (! $template) {
            throw new \Exception(__('templates.errors.not_found', ['template' => $identifier]));
        }

        $this->info(__('templates.commands.cache_clear.clearing_single', ['template' => $identifier]));

        $clearedCount = 0;

        // 1. 레이아웃 캐시 삭제
        $templateRecord = Template::where('identifier', $identifier)->first();
        if ($templateRecord) {
            $layouts = TemplateLayout::where('template_id', $templateRecord->id)->get();
            foreach ($layouts as $layout) {
                $this->cache->forget("layout.{$identifier}.{$layout->name}");
                $clearedCount++;
            }
        }

        // 2. Routes 캐시 삭제
        $this->cache->forget("template.routes.{$identifier}");
        $clearedCount++;

        // 3. 다국어 파일 캐시 삭제
        $supportedLocales = config('app.supported_locales', ['ko', 'en']);
        foreach ($supportedLocales as $locale) {
            $this->cache->forget("template.language.{$identifier}.{$locale}");
            $clearedCount++;
        }

        // 4. 활성 템플릿 타입 캐시 삭제
        if ($templateRecord) {
            $this->cache->forget("templates.active.{$templateRecord->type}");
            $clearedCount++;
        }

        $this->info('✅ '.__('templates.commands.cache_clear.success_single', [
            'template' => $identifier,
            'count' => $clearedCount,
        ]));

        Log::info(__('templates.commands.cache_clear.success_single', [
            'template' => $identifier,
            'count' => $clearedCount,
        ]));
    }

    /**
     * 모든 템플릿 캐시 삭제
     */
    private function clearAllTemplateCache(): void
    {
        $this->info(__('templates.commands.cache_clear.clearing_all'));

        $clearedCount = 0;
        $supportedLocales = config('app.supported_locales', ['ko', 'en']);

        // 모든 설치된 템플릿의 캐시 삭제
        $templates = Template::all();

        foreach ($templates as $templateRecord) {
            // 1. 레이아웃 캐시 삭제
            $layouts = TemplateLayout::where('template_id', $templateRecord->id)->get();
            foreach ($layouts as $layout) {
                $this->cache->forget("layout.{$templateRecord->identifier}.{$layout->name}");
                $clearedCount++;
            }

            // 2. Routes 캐시 삭제
            $this->cache->forget("template.routes.{$templateRecord->identifier}");
            $clearedCount++;

            // 3. 다국어 파일 캐시 삭제
            foreach ($supportedLocales as $locale) {
                $this->cache->forget("template.language.{$templateRecord->identifier}.{$locale}");
                $clearedCount++;
            }
        }

        // 4. 활성 템플릿 타입 캐시 삭제
        $this->cache->forget('templates.active.admin');
        $this->cache->forget('templates.active.user');
        $clearedCount += 2;

        $this->info('✅ '.__('templates.commands.cache_clear.success_all', [
            'count' => $clearedCount,
        ]));

        Log::info(__('templates.commands.cache_clear.success_all', [
            'count' => $clearedCount,
        ]));
    }
}
