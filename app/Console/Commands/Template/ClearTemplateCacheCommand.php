<?php

namespace App\Console\Commands\Template;

use App\Extension\TemplateManager;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Models\Template;
use App\Services\ExtensionBundleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ClearTemplateCacheCommand extends Command
{
    use ClearsTemplateCaches;

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
        private ExtensionBundleService $bundleService
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
     *
     * 라이프사이클(update/deactivate/uninstall)과 동일한 무효화 단일 지점
     * (`TemplateManager::clearTemplateCache()`)을 경유한다 — 커맨드가 키 목록을
     * 별도로 유지하면 키 규약 변경 시 유령 forget 으로 사문화된다 (#588, 공개 #119).
     */
    private function clearSingleTemplateCache(string $identifier): void
    {
        // 템플릿 존재 확인
        $template = $this->templateManager->getTemplate($identifier);
        if (! $template) {
            throw new \Exception(__('templates.errors.not_found', ['template' => $identifier]));
        }

        $this->info(__('templates.commands.cache_clear.clearing_single', ['template' => $identifier]));

        // 고정 키(config/components_manifest) + 현재 버전 routes/language + 레이아웃 캐시
        $clearedCount = $this->templateManager->clearTemplateCache($identifier);

        // 상태 키(ext.templates.*) + 버전 포함 키 무효화 (버전 bump)
        TemplateManager::invalidateTemplateStatusCache();
        $this->incrementExtensionCacheVersion();

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

        // 모든 설치된 템플릿의 캐시 삭제 (설치 레코드 기준 — 라이프사이클과 동일 지점)
        foreach (Template::all() as $templateRecord) {
            $clearedCount += $this->templateManager->clearTemplateCache($templateRecord->identifier);
        }

        // 상태 키 + 버전 포함 키 무효화는 전체에서 1회면 충분
        TemplateManager::invalidateTemplateStatusCache();
        $this->incrementExtensionCacheVersion();

        // 확장 프론트엔드 병합 번들 파일 전체 삭제 (템플릿 캐시 정리는 페이지 전면 갱신)
        $clearedCount += $this->bundleService->clearBundles();

        $this->info('✅ '.__('templates.commands.cache_clear.success_all', [
            'count' => $clearedCount,
        ]));

        Log::info(__('templates.commands.cache_clear.success_all', [
            'count' => $clearedCount,
        ]));
    }
}
