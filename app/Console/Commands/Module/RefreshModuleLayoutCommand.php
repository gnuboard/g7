<?php

namespace App\Console\Commands\Module;

use App\Models\Module;
use App\Services\ModuleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 모듈 레이아웃 갱신 커맨드
 *
 * 레이아웃 JSON 파일만 수정된 경우, 빌드 없이 레이아웃만 갱신합니다.
 */
class RefreshModuleLayoutCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:refresh-layout
        {identifier? : 특정 모듈의 식별자 (생략 시 모든 활성 모듈)}';

    /**
     * The console command description.
     */
    protected $description = '모듈 레이아웃을 갱신합니다 (빌드 없이 JSON만 DB에 동기화)';

    public function __construct(
        private ModuleService $moduleService
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
            Log::error('모듈 레이아웃 갱신 실패', [
                'module' => $identifier ?? 'all',
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * 특정 모듈의 레이아웃을 갱신합니다.
     */
    private function refreshSingle(string $identifier): int
    {
        $this->info("모듈 '{$identifier}' 레이아웃 갱신 중...");

        $result = $this->moduleService->refreshModuleLayouts($identifier);

        if ($result) {
            $this->info("✅ 모듈 '{$identifier}' 레이아웃 갱신 완료");
            Log::info('모듈 레이아웃 갱신 완료', ['module' => $identifier]);

            return Command::SUCCESS;
        }

        $this->error("❌ 모듈 '{$identifier}' 레이아웃 갱신 실패");

        return Command::FAILURE;
    }

    /**
     * 모든 활성 모듈의 레이아웃을 갱신합니다.
     */
    private function refreshAll(): int
    {
        $modules = Module::where('status', 'active')->pluck('identifier')->toArray();

        if (empty($modules)) {
            $this->line('활성화된 모듈이 없습니다.');

            return Command::SUCCESS;
        }

        $this->info('모든 활성 모듈 레이아웃 갱신 중...');

        $successCount = 0;
        $failCount = 0;

        foreach ($modules as $moduleIdentifier) {
            $this->line("  {$moduleIdentifier} 갱신 중...");

            try {
                $result = $this->moduleService->refreshModuleLayouts($moduleIdentifier);

                if ($result) {
                    $this->info("  ✅ {$moduleIdentifier} 완료");
                    $successCount++;
                } else {
                    $this->warn("  ⚠️ {$moduleIdentifier} 실패");
                    $failCount++;
                }
            } catch (\Exception $e) {
                $this->warn("  ⚠️ {$moduleIdentifier}: ".$e->getMessage());
                $failCount++;
            }
        }

        $this->newLine();
        $this->info("✅ 총 {$successCount}개 성공, {$failCount}개 실패");

        Log::info('모듈 레이아웃 일괄 갱신 완료', [
            'success' => $successCount,
            'failed' => $failCount,
        ]);

        return $failCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
