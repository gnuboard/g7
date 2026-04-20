<?php

namespace App\Console\Commands\Module;

use App\Console\Commands\Traits\HasProgressBar;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Extension\ModuleManager;
use App\Extension\Vendor\VendorMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateModuleCommand extends Command
{
    use HasProgressBar;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:update
        {identifier : 업데이트할 모듈 식별자}
        {--force : 버전 비교 없이 강제 업데이트}
        {--vendor-mode=auto : Vendor 설치 모드 (auto|composer|bundled)}
        {--layout-strategy=overwrite : 레이아웃 전략 (overwrite|keep)}
        {--source=auto : 업데이트 소스 (auto|bundled|github) — bundled 는 _bundled 만 사용(GitHub 우회)}
        {--zip= : 외부 ZIP 파일 경로 (지정 시 GitHub/번들 우회 + 버전은 module.json 기준)}';

    /**
     * The console command description.
     */
    protected $description = '모듈을 최신 버전으로 업데이트합니다';

    /**
     * 모듈 관리자 및 리포지토리
     */
    public function __construct(
        private ModuleManager $moduleManager,
        private ModuleRepositoryInterface $moduleRepository
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $identifier = $this->argument('identifier');
        $force = $this->option('force');
        $layoutStrategy = (string) $this->option('layout-strategy');
        $sourceOption = (string) $this->option('source');
        $zipPath = $this->option('zip');

        if (! in_array($layoutStrategy, ['overwrite', 'keep'], true)) {
            $this->error('❌ --layout-strategy 는 overwrite 또는 keep 이어야 합니다.');

            return Command::FAILURE;
        }

        if (! in_array($sourceOption, ['auto', 'bundled', 'github'], true)) {
            $this->error('❌ --source 는 auto, bundled, github 중 하나여야 합니다.');

            return Command::FAILURE;
        }

        // --zip 과 --source 동시 지정 금지 (zip 이 우선 대상이므로)
        if ($zipPath !== null && $sourceOption !== 'auto') {
            $this->error('❌ --zip 은 --source 와 동시에 지정할 수 없습니다.');

            return Command::FAILURE;
        }

        if ($zipPath !== null) {
            $resolvedZip = realpath($zipPath);
            if (! $resolvedZip || ! is_file($resolvedZip)) {
                $this->error('❌ 지정된 ZIP 파일이 존재하지 않습니다: '.$zipPath);

                return Command::FAILURE;
            }
            $zipPath = $resolvedZip;
        }

        $sourceOverride = $sourceOption === 'auto' ? null : $sourceOption;

        try {
            $this->moduleManager->loadModules();

            // 모듈 존재 확인
            $module = $this->moduleRepository->findByIdentifier($identifier);

            if (! $module) {
                $this->error('❌ '.__('modules.commands.update.not_installed', ['module' => $identifier]));

                return Command::FAILURE;
            }

            // 업데이트 확인 (--zip 모드는 GitHub/번들 비교를 우회하므로 스킵)
            if ($zipPath === null) {
                $checkResult = $this->moduleManager->checkModuleUpdate($identifier);

                if (! $checkResult['update_available'] && ! $force) {
                    $this->info('✅ '.__('modules.commands.update.no_update', ['module' => $identifier]));

                    return Command::SUCCESS;
                }

                // 업데이트 정보 표시
                $this->info(__('modules.commands.update.current_version', ['version' => $checkResult['current_version']]));

                if ($force && ! $checkResult['update_available']) {
                    $this->warn('⚠️  '.__('modules.commands.update.force_mode'));
                } else {
                    $this->info(__('modules.commands.update.latest_version', ['version' => $checkResult['latest_version']]));
                    $this->info(__('modules.commands.update.update_source', ['source' => $checkResult['update_source']]));
                }
            } else {
                $this->info(__('modules.commands.update.current_version', ['version' => $module->version]));
                $this->info('업데이트 소스: ZIP ('.$zipPath.')');
                $this->info('업데이트 버전: (module.json 추출 후 판별)');
            }

            $this->newLine();

            // 확인 프롬프트 (--force 시 건너뜀)
            if (! $force && ! $this->confirm(__('modules.commands.update.confirm_question'), false)) {
                $this->info(__('modules.commands.update.aborted'));

                return Command::SUCCESS;
            }

            // 업데이트 실행
            $vendorMode = VendorMode::fromStringOrAuto((string) $this->option('vendor-mode'));
            $onProgress = $this->createProgressCallback(ModuleManager::UPDATE_STEPS);

            // upgrade step 콘솔 출력 콜백: progress bar 를 잠시 지우고 별도 줄에 출력
            $onUpgradeStep = function (string $version) use ($identifier): void {
                if ($this->progressBar) {
                    $this->progressBar->clear();
                }
                $this->line("  • [{$identifier}] upgrade step 실행: {$version}");
                if ($this->progressBar) {
                    $this->progressBar->display();
                }
            };

            try {
                $updateResult = $this->moduleManager->updateModule(
                    $identifier,
                    $force,
                    $onProgress,
                    $vendorMode,
                    $layoutStrategy,
                    $onUpgradeStep,
                    $sourceOverride,
                    $zipPath,
                );
                $this->finishProgress();
            } catch (\Exception $e) {
                $this->finishProgress();
                throw $e;
            }

            if ($updateResult['success']) {
                $this->newLine();
                $this->info('✅ '.__('modules.commands.update.success', ['module' => $identifier]));
                $this->info('   '.__('modules.commands.update.version_change', [
                    'from' => $updateResult['from_version'],
                    'to' => $updateResult['to_version'],
                ]));

                Log::info('모듈 업데이트 완료', [
                    'module' => $identifier,
                    'from' => $updateResult['from_version'],
                    'to' => $updateResult['to_version'],
                    'layout_strategy' => $layoutStrategy,
                ]);

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            $this->warn('💡 '.__('modules.commands.update.backup_restored'));

            Log::error('모듈 업데이트 실패', [
                'module' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
