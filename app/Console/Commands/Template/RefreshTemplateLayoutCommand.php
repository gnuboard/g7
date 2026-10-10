<?php

namespace App\Console\Commands\Template;

use App\Console\Commands\Concerns\SelectsLayoutInstallSource;
use App\Console\Commands\Traits\RendersLayoutSyncResult;
use App\Models\Template;
use App\Services\TemplateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 템플릿 레이아웃 갱신 커맨드
 *
 * 레이아웃만 수정된 경우(소스 SSoT `.g7.html` 또는 짝 `.json`), 빌드 없이 레이아웃만 갱신합니다.
 *
 * 설치 소스는 `--layout-source` 가 결정한다 — 기본 `auto` 는 `.g7.html` 우선이며
 * `json` 은 짝 `.json` 이 있으면 그것으로 뒤로 물러선다(진단·복구용).
 */
class RefreshTemplateLayoutCommand extends Command
{
    use RendersLayoutSyncResult;
    use SelectsLayoutInstallSource;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'template:refresh-layout
        {identifier? : 특정 템플릿의 식별자 (생략 시 모든 활성 템플릿)}
        {--layout-source=auto : 레이아웃 설치 소스 (auto|json) — json 은 짝 .json 이 있으면 .g7.html 대신 원본 JSON 으로 등록(진단·복구용)}';

    /**
     * The console command description.
     */
    protected $description = '템플릿 레이아웃을 갱신합니다 (빌드 없이 레이아웃만 DB에 동기화)';

    /**
     * 커맨드를 생성합니다.
     *
     * @param  TemplateService  $templateService  레이아웃 갱신을 수행하는 서비스
     */
    public function __construct(
        private TemplateService $templateService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        // `--layout-source` 검증 + 런타임 반영 (알 수 없는 값이면 즉시 실패).
        if (! $this->applyLayoutInstallSource()) {
            return self::FAILURE;
        }

        $identifier = $this->argument('identifier');

        try {
            if ($identifier) {
                return $this->refreshSingle($identifier);
            }

            return $this->refreshAll();
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('템플릿 레이아웃 갱신 실패', [
                'template' => $identifier ?? 'all',
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * 특정 템플릿의 레이아웃을 갱신합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return int 종료 코드
     */
    private function refreshSingle(string $identifier): int
    {
        $this->info("템플릿 '{$identifier}' 레이아웃 갱신 중...");

        $result = $this->templateService->refreshTemplateLayouts($identifier);

        if ($result) {
            $this->info("✅ 템플릿 '{$identifier}' 레이아웃 갱신 완료");
            $this->renderLayoutIncludeWarnings($result['layout_warnings'] ?? []);
            Log::info('템플릿 레이아웃 갱신 완료', ['template' => $identifier]);

            return Command::SUCCESS;
        }

        $this->error("❌ 템플릿 '{$identifier}' 레이아웃 갱신 실패");

        return Command::FAILURE;
    }

    /**
     * 모든 활성 템플릿의 레이아웃을 갱신합니다.
     *
     * @return int 종료 코드
     */
    private function refreshAll(): int
    {
        $templates = Template::where('status', 'active')->pluck('identifier')->toArray();

        if (empty($templates)) {
            $this->line('활성화된 템플릿이 없습니다.');

            return Command::SUCCESS;
        }

        $this->info('모든 활성 템플릿 레이아웃 갱신 중...');

        $successCount = 0;
        $failCount = 0;

        foreach ($templates as $templateIdentifier) {
            $this->line("  {$templateIdentifier} 갱신 중...");

            try {
                $result = $this->templateService->refreshTemplateLayouts($templateIdentifier);

                if ($result) {
                    $this->info("  ✅ {$templateIdentifier} 완료");
                    $this->renderLayoutIncludeWarnings($result['layout_warnings'] ?? []);
                    $successCount++;
                } else {
                    $this->warn("  ⚠️ {$templateIdentifier} 실패");
                    $failCount++;
                }
            } catch (\Exception $e) {
                $this->warn("  ⚠️ {$templateIdentifier}: ".$e->getMessage());
                $failCount++;
            }
        }

        $this->newLine();
        $this->info("✅ 총 {$successCount}개 성공, {$failCount}개 실패");

        Log::info('템플릿 레이아웃 일괄 갱신 완료', [
            'success' => $successCount,
            'failed' => $failCount,
        ]);

        return $failCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
