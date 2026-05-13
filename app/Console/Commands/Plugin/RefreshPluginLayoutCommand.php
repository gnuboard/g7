<?php

namespace App\Console\Commands\Plugin;

use App\Models\Plugin;
use App\Services\PluginService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 플러그인 레이아웃 갱신 커맨드
 *
 * 레이아웃 JSON 파일만 수정된 경우, 빌드 없이 레이아웃만 갱신합니다.
 */
class RefreshPluginLayoutCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'plugin:refresh-layout
        {identifier? : 특정 플러그인의 식별자 (생략 시 모든 활성 플러그인)}';

    /**
     * The console command description.
     */
    protected $description = '플러그인 레이아웃을 갱신합니다 (빌드 없이 JSON만 DB에 동기화)';

    public function __construct(
        private PluginService $pluginService
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
            if ($identifier) {
                return $this->refreshSingle($identifier);
            }

            return $this->refreshAll();
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('플러그인 레이아웃 갱신 실패', [
                'plugin' => $identifier ?? 'all',
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * 특정 플러그인의 레이아웃을 갱신합니다.
     */
    private function refreshSingle(string $identifier): int
    {
        $this->info("플러그인 '{$identifier}' 레이아웃 갱신 중...");

        $result = $this->pluginService->refreshPluginLayouts($identifier);

        if ($result['success'] ?? false) {
            $this->info("✅ 플러그인 '{$identifier}' 레이아웃 갱신 완료");
            $this->line("   생성: {$result['created']}, 수정: {$result['updated']}, 삭제: {$result['deleted']}");
            Log::info('플러그인 레이아웃 갱신 완료', [
                'plugin' => $identifier,
                'stats' => $result,
            ]);

            return Command::SUCCESS;
        }

        $this->error("❌ 플러그인 '{$identifier}' 레이아웃 갱신 실패");

        return Command::FAILURE;
    }

    /**
     * 모든 활성 플러그인의 레이아웃을 갱신합니다.
     */
    private function refreshAll(): int
    {
        $plugins = Plugin::where('status', 'active')->pluck('identifier')->toArray();

        if (empty($plugins)) {
            $this->line('활성화된 플러그인이 없습니다.');

            return Command::SUCCESS;
        }

        $this->info('모든 활성 플러그인 레이아웃 갱신 중...');

        $successCount = 0;
        $failCount = 0;

        foreach ($plugins as $pluginIdentifier) {
            $this->line("  {$pluginIdentifier} 갱신 중...");

            try {
                $result = $this->pluginService->refreshPluginLayouts($pluginIdentifier);

                if ($result['success'] ?? false) {
                    $this->info("  ✅ {$pluginIdentifier} 완료");
                    $successCount++;
                } else {
                    $this->warn("  ⚠️ {$pluginIdentifier} 실패");
                    $failCount++;
                }
            } catch (\Exception $e) {
                $this->warn("  ⚠️ {$pluginIdentifier}: ".$e->getMessage());
                $failCount++;
            }
        }

        $this->newLine();
        $this->info("✅ 총 {$successCount}개 성공, {$failCount}개 실패");

        Log::info('플러그인 레이아웃 일괄 갱신 완료', [
            'success' => $successCount,
            'failed' => $failCount,
        ]);

        return $failCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
