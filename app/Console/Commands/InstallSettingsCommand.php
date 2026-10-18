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

    public function __construct(
        private ConfigRepositoryInterface $configRepository
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $force = $this->option('force');
        $merge = $this->option('merge');

        $defaultsPath = config_path('settings/defaults.json');

        // defaults.json 파일 존재 확인
        if (! File::exists($defaultsPath)) {
            $this->error("기본 설정 파일을 찾을 수 없습니다: {$defaultsPath}");
            $this->error('config/settings/defaults.json 파일이 존재하는지 확인하세요.');

            return Command::FAILURE;
        }

        // defaults.json 로드
        $content = File::get($defaultsPath);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('기본 설정 파일 JSON 파싱 실패: '.json_last_error_msg());

            return Command::FAILURE;
        }

        $defaults = $data['defaults'] ?? [];
        $categories = $data['_meta']['categories'] ?? [];

        if (empty($defaults)) {
            $this->error('기본 설정 파일에 defaults 섹션이 없습니다.');

            return Command::FAILURE;
        }

        $this->info('환경설정 설치를 시작합니다...');
        $this->newLine();

        $installedCount = 0;
        $skippedCount = 0;
        $mergedCount = 0;

        foreach ($categories as $category) {
            $categoryDefaults = $defaults[$category] ?? [];

            if (empty($categoryDefaults)) {
                $this->warn("  [{$category}] 기본값이 없습니다. 건너뜁니다.");

                continue;
            }

            $settingsPath = storage_path("app/settings/{$category}.json");
            $exists = File::exists($settingsPath);

            if ($exists && ! $force && ! $merge) {
                $this->line("  <comment>[{$category}]</comment> 이미 존재합니다. 건너뜁니다. (--force 또는 --merge 옵션 사용)");
                $skippedCount++;

                continue;
            }

            if ($exists && $merge) {
                // 병합 모드: 기존 설정과 병합 (기존 값 유지, 새 키만 추가)
                $existingSettings = $this->configRepository->getCategory($category);
                $mergedSettings = array_merge($categoryDefaults, $existingSettings);

                if ($this->configRepository->saveCategory($category, $mergedSettings)) {
                    $newKeysCount = count(array_diff_key($categoryDefaults, $existingSettings));
                    $this->info("  <info>[{$category}]</info> 병합 완료 ({$newKeysCount}개 새 키 추가)");
                    $mergedCount++;
                } else {
                    $this->error("  [{$category}] 병합 실패");
                }

                continue;
            }

            // 새로 설치 또는 덮어쓰기
            if ($this->configRepository->saveCategory($category, $categoryDefaults)) {
                $action = $exists ? '덮어씀' : '설치됨';
                $this->info("  <info>[{$category}]</info> {$action}");
                $installedCount++;
            } else {
                $this->error("  [{$category}] 설치 실패");
            }
        }

        $this->newLine();
        $this->info('환경설정 설치 완료!');
        $this->line("  - 설치됨: {$installedCount}개");
        $this->line("  - 병합됨: {$mergedCount}개");
        $this->line("  - 건너뜀: {$skippedCount}개");

        return Command::SUCCESS;
    }
}
