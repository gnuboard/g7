<?php

namespace App\Console\Commands\Plugin;

use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\ExtensionManager;
use App\Extension\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SeedPluginCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'plugin:seed
                            {identifier? : 시더를 실행할 플러그인 식별자 (생략 시 모든 활성 플러그인)}
                            {--class= : 실행할 특정 시더 클래스명}
                            {--count=* : 시더에 전달할 카운트 옵션 (형식: key=value, 예: --count=products=1000)}
                            {--sample : 샘플 데이터 시더도 함께 실행}
                            {--force : 프로덕션 환경에서도 강제 실행}';

    /**
     * The console command description.
     */
    protected $description = '플러그인의 데이터베이스 시더를 실행합니다';

    /**
     * 플러그인 관리자 및 리포지토리
     *
     * @param  PluginManager  $pluginManager  플러그인 관리자 (플러그인 적재·활성 플러그인 조회)
     * @param  PluginRepositoryInterface  $pluginRepository  플러그인 설치 레코드를 조회하는 리포지토리
     */
    public function __construct(
        private PluginManager $pluginManager,
        private PluginRepositoryInterface $pluginRepository
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
        $identifier = $this->argument('identifier');
        $seederClass = $this->option('class');
        $force = $this->option('force');

        // 프로덕션 환경 확인
        if (app()->environment('production') && ! $force) {
            $this->error('❌ '.__('plugins.commands.seed.production_requires_force'));

            return Command::FAILURE;
        }

        try {
            // 플러그인 로드
            $this->pluginManager->loadPlugins();

            if ($identifier) {
                return $this->seedPlugin($identifier, $seederClass);
            }

            return $this->seedAllActivePlugins($seederClass);
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('플러그인 시더 실행 실패', [
                'plugin' => $identifier ?? 'all',
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * 단일 플러그인 시더 실행
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  string|null  $seederClass  특정 시더 클래스명
     * @return int 명령 실행 결과
     */
    private function seedPlugin(string $identifier, ?string $seederClass): int
    {
        // 플러그인 존재 여부 확인
        $plugin = $this->pluginManager->getPlugin($identifier);

        if (! $plugin) {
            $this->error('❌ '.__('plugins.not_found', ['plugin' => $identifier]));

            return Command::FAILURE;
        }

        // 활성화 상태 확인
        $pluginRecord = $this->pluginRepository->findByIdentifier($identifier);

        if (! $pluginRecord || $pluginRecord->status !== ExtensionStatus::Active->value) {
            $this->error('❌ '.__('plugins.commands.seed.not_active', ['identifier' => $identifier]));
            $this->line('   '.__('plugins.commands.seed.activate_hint', ['identifier' => $identifier]));

            return Command::FAILURE;
        }

        // 시더 경로 확인
        $pluginPath = base_path("plugins/{$identifier}");
        $seederPath = $pluginPath.'/database/seeders';

        if (! is_dir($seederPath)) {
            $this->warn('⚠️  '.__('plugins.commands.seed.no_seeder_directory', ['identifier' => $identifier]));

            return Command::SUCCESS;
        }

        // 시더 클래스 결정
        $seederClassName = $this->resolveSeederClass($identifier, $seederClass);

        if (! class_exists($seederClassName)) {
            $this->error('❌ '.__('plugins.commands.seed.seeder_class_not_found', ['class' => $seederClassName]));

            return Command::FAILURE;
        }

        $this->info('🌱 '.__('plugins.commands.seed.start', ['identifier' => $identifier]));
        $this->line('   '.__('plugins.commands.seed.class', ['class' => $seederClassName]));
        $this->line('');

        // 시더 실행
        $seeder = app($seederClassName);
        $seeder->setCommand($this);

        // --sample 옵션 전파
        if (method_exists($seeder, 'setIncludeSample')) {
            $seeder->setIncludeSample((bool) $this->option('sample'));
        }

        // --count 옵션 전파
        $counts = $this->parseCountOptions();
        if (! empty($counts) && method_exists($seeder, 'setSeederCounts')) {
            $seeder->setSeederCounts($counts);
        }

        $seeder->run();

        $this->line('');
        $this->info('✅ '.__('plugins.commands.seed.done', ['identifier' => $identifier]));

        return Command::SUCCESS;
    }

    /**
     * 모든 활성 플러그인 시더 실행
     *
     * @param  string|null  $seederClass  특정 시더 클래스명
     * @return int 명령 실행 결과
     */
    private function seedAllActivePlugins(?string $seederClass): int
    {
        $activePlugins = $this->pluginManager->getActivePlugins();

        if (empty($activePlugins)) {
            $this->warn('⚠️  '.__('plugins.commands.seed.no_active'));

            return Command::SUCCESS;
        }

        // 시더가 있는 플러그인만 필터링
        $seedablePlugins = [];

        foreach ($activePlugins as $identifier => $plugin) {
            $seederPath = base_path("plugins/{$identifier}/database/seeders");
            $seederClassName = $this->resolveSeederClass($identifier, $seederClass);

            if (is_dir($seederPath) && class_exists($seederClassName)) {
                $seedablePlugins[$identifier] = $plugin;
            }
        }

        if (empty($seedablePlugins)) {
            $this->warn('⚠️  '.__('plugins.commands.seed.no_seedable'));

            return Command::SUCCESS;
        }

        $this->info('🌱 '.__('plugins.commands.seed.start_all'));
        $this->line('   '.__('plugins.commands.seed.targets', ['list' => implode(', ', array_keys($seedablePlugins))]));
        $this->line('');

        $successCount = 0;
        $failCount = 0;

        foreach ($seedablePlugins as $identifier => $plugin) {
            $this->line("━━━ [{$identifier}] ━━━");
            $result = $this->seedPlugin($identifier, $seederClass);

            if ($result === Command::SUCCESS) {
                $successCount++;
            } else {
                $failCount++;
            }

            $this->line('');
        }

        $this->info('📊 '.__('plugins.commands.seed.summary', ['success' => $successCount, 'failed' => $failCount]));

        return $failCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * --count 옵션을 파싱하여 연관 배열로 반환합니다.
     *
     * 입력: ['products=1000', 'orders=500']
     * 출력: ['products' => 1000, 'orders' => 500]
     *
     * @return array<string, int>
     */
    public function parseCountOptions(): array
    {
        $countOptions = $this->option('count');
        $counts = [];

        foreach ($countOptions as $option) {
            if (str_contains($option, '=')) {
                [$key, $value] = explode('=', $option, 2);
                $key = trim($key);
                if ($key !== '') {
                    $counts[$key] = (int) trim($value);
                }
            }
        }

        return $counts;
    }

    /**
     * 시더 클래스명 해석
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  string|null  $seederClass  사용자 지정 시더 클래스명
     * @return string 완전한 시더 클래스명
     */
    private function resolveSeederClass(string $identifier, ?string $seederClass): string
    {
        $namespace = ExtensionManager::pluginIdentifierToNamespace($identifier);

        if ($seederClass) {
            // 사용자가 지정한 클래스명 사용
            if (str_contains($seederClass, '\\')) {
                return $seederClass;
            }

            return $namespace.'Database\\Seeders\\'.$seederClass;
        }

        // 기본 DatabaseSeeder 사용
        return $namespace.'Database\\Seeders\\DatabaseSeeder';
    }
}
