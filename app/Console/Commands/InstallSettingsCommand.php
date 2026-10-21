<?php

namespace App\Console\Commands;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * 환경설정 설치/초기화 커맨드
 *
 * 그누보드7 설치 시 또는 설정 초기화가 필요할 때 사용합니다.
 * config/settings/defaults.json의 기본값을 storage/app/settings/에 복사합니다.
 */
class InstallSettingsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'settings:install
                            {--force : 기존 설정 파일을 덮어씁니다}
                            {--merge : 기존 설정과 병합합니다 (새 키만 추가)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '환경설정 기본값을 설치합니다 (config/settings/defaults.json → storage/app/settings/)';

    /**
     * 커맨드를 생성합니다.
     *
     * @param  ConfigRepositoryInterface  $configRepository  카테고리별 설정을 읽고 저장하는 저장소
     */
    public function __construct(
        private ConfigRepositoryInterface $configRepository
    ) {
        parent::__construct();
    }

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $force = $this->option('force');
        $merge = $this->option('merge');

        $defaultsPath = config_path('settings/defaults.json');

        // defaults.json 파일 존재 확인
        if (! File::exists($defaultsPath)) {
            $this->error(__('settings.console.install.defaults_not_found', ['path' => $defaultsPath]));
            $this->error(__('settings.console.install.defaults_check_hint', ['path' => 'config/settings/defaults.json']));

            return Command::FAILURE;
        }

        // defaults.json 로드
        $content = File::get($defaultsPath);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error(__('settings.console.install.defaults_parse_failed', ['error' => json_last_error_msg()]));

            return Command::FAILURE;
        }

        $defaults = $data['defaults'] ?? [];
        $categories = $data['_meta']['categories'] ?? [];

        if (empty($defaults)) {
            $this->error(__('settings.console.install.defaults_section_missing'));

            return Command::FAILURE;
        }

        $this->info(__('settings.console.install.start'));
        $this->newLine();

        $installedCount = 0;
        $skippedCount = 0;
        $mergedCount = 0;

        foreach ($categories as $category) {
            $categoryDefaults = $defaults[$category] ?? [];

            if (empty($categoryDefaults)) {
                $this->warn("  [{$category}] ".__('settings.console.install.no_defaults'));

                continue;
            }

            $settingsPath = storage_path("app/settings/{$category}.json");
            $exists = File::exists($settingsPath);

            if ($exists && ! $force && ! $merge) {
                $this->line("  <comment>[{$category}]</comment> ".__('settings.console.install.already_exists'));
                $skippedCount++;

                continue;
            }

            if ($exists && $merge) {
                // 병합 모드: 기존 설정과 병합 (기존 값 유지, 새 키만 추가)
                $existingSettings = $this->configRepository->getCategory($category);
                $mergedSettings = array_merge($categoryDefaults, $existingSettings);

                if ($this->configRepository->saveCategory($category, $mergedSettings)) {
                    $newKeysCount = count(array_diff_key($categoryDefaults, $existingSettings));
                    $this->info("  <info>[{$category}]</info> ".__('settings.console.install.merged', ['count' => $newKeysCount]));
                    $mergedCount++;
                } else {
                    $this->error("  [{$category}] ".__('settings.console.install.merge_failed'));
                }

                continue;
            }

            // 새로 설치 또는 덮어쓰기
            if ($this->configRepository->saveCategory($category, $categoryDefaults)) {
                $action = $exists ? __('settings.console.install.action_overwritten') : __('settings.console.install.action_installed');
                $this->info("  <info>[{$category}]</info> {$action}");
                $installedCount++;
            } else {
                $this->error("  [{$category}] ".__('settings.console.install.install_failed'));
            }
        }

        $this->newLine();
        $this->info(__('settings.console.install.done'));
        $this->line('  - '.__('settings.console.install.summary_installed', ['count' => $installedCount]));
        $this->line('  - '.__('settings.console.install.summary_merged', ['count' => $mergedCount]));
        $this->line('  - '.__('settings.console.install.summary_skipped', ['count' => $skippedCount]));

        return Command::SUCCESS;
    }
}
