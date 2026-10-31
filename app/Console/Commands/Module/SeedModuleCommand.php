<?php

namespace App\Console\Commands\Module;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\ExtensionManager;
use App\Extension\ModuleManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SeedModuleCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:seed
                            {identifier? : 시더를 실행할 모듈 식별자 (생략 시 모든 활성 모듈)}
                            {--class= : 실행할 특정 시더 클래스명}
                            {--count=* : 시더에 전달할 카운트 옵션 (형식: key=value, 예: --count=products=1000)}
                            {--sample : 샘플 데이터 시더도 함께 실행}
                            {--force : 프로덕션 환경에서도 강제 실행}';

    /**
     * The console command description.
     */
    protected $description = '모듈의 데이터베이스 시더를 실행합니다';

    /**
     * 모듈 관리자 및 리포지토리
     *
     * @param  ModuleManager  $moduleManager  모듈 관리자 (모듈 적재·활성 모듈 조회)
     * @param  ModuleRepositoryInterface  $moduleRepository  모듈 설치 레코드를 조회하는 리포지토리
     */
    public function __construct(
        private ModuleManager $moduleManager,
        private ModuleRepositoryInterface $moduleRepository
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
            $this->error('❌ '.__('modules.commands.seed.production_requires_force'));

            return Command::FAILURE;
        }

        try {
            // 모듈 로드
            $this->moduleManager->loadModules();

            if ($identifier) {
                return $this->seedModule($identifier, $seederClass);
            }

            return $this->seedAllActiveModules($seederClass);
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('모듈 시더 실행 실패', [
                'module' => $identifier ?? 'all',
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * 단일 모듈 시더 실행
     *
     * @param  string  $identifier  모듈 식별자
     * @param  string|null  $seederClass  특정 시더 클래스명
     * @return int 명령 실행 결과
     */
    private function seedModule(string $identifier, ?string $seederClass): int
    {
        // 모듈 존재 여부 확인
        $module = $this->moduleManager->getModule($identifier);

        if (! $module) {
            $this->error('❌ '.__('modules.errors.not_found', ['module' => $identifier]));

            return Command::FAILURE;
        }

        // 활성화 상태 확인
        $moduleRecord = $this->moduleRepository->findByIdentifier($identifier);

        if (! $moduleRecord || $moduleRecord->status !== ExtensionStatus::Active->value) {
            $this->error('❌ '.__('modules.commands.seed.not_active', ['identifier' => $identifier]));
            $this->line('   '.__('modules.commands.seed.activate_hint', ['identifier' => $identifier]));

            return Command::FAILURE;
        }

        // 시더 경로 확인
        $modulePath = base_path("modules/{$identifier}");
        $seederPath = $modulePath.'/database/seeders';

        if (! is_dir($seederPath)) {
            $this->warn('⚠️  '.__('modules.commands.seed.no_seeder_directory', ['identifier' => $identifier]));

            return Command::SUCCESS;
        }

        // 시더 클래스 결정
        $seederClassName = $this->resolveSeederClass($identifier, $seederClass);

        if (! class_exists($seederClassName)) {
            $this->error('❌ '.__('modules.commands.seed.seeder_class_not_found', ['class' => $seederClassName]));

            return Command::FAILURE;
        }

        $this->info('🌱 '.__('modules.commands.seed.start', ['identifier' => $identifier]));
        $this->line('   '.__('modules.commands.seed.class', ['class' => $seederClassName]));
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
        $this->info('✅ '.__('modules.commands.seed.done', ['identifier' => $identifier]));

        return Command::SUCCESS;
    }

    /**
     * 모든 활성 모듈 시더 실행
     *
     * @param  string|null  $seederClass  특정 시더 클래스명
     * @return int 명령 실행 결과
     */
    private function seedAllActiveModules(?string $seederClass): int
    {
        $activeModules = $this->moduleManager->getActiveModules();

        if (empty($activeModules)) {
            $this->warn('⚠️  '.__('modules.commands.seed.no_active'));

            return Command::SUCCESS;
        }

        // 시더가 있는 모듈만 필터링
        $seedableModules = [];

        foreach ($activeModules as $identifier => $module) {
            $seederPath = base_path("modules/{$identifier}/database/seeders");
            $seederClassName = $this->resolveSeederClass($identifier, $seederClass);

            if (is_dir($seederPath) && class_exists($seederClassName)) {
                $seedableModules[$identifier] = $module;
            }
        }

        if (empty($seedableModules)) {
            $this->warn('⚠️  '.__('modules.commands.seed.no_seedable'));

            return Command::SUCCESS;
        }

        $this->info('🌱 '.__('modules.commands.seed.start_all'));
        $this->line('   '.__('modules.commands.seed.targets', ['list' => implode(', ', array_keys($seedableModules))]));
        $this->line('');

        $successCount = 0;
        $failCount = 0;

        foreach ($seedableModules as $identifier => $module) {
            $this->line("━━━ [{$identifier}] ━━━");
            $result = $this->seedModule($identifier, $seederClass);

            if ($result === Command::SUCCESS) {
                $successCount++;
            } else {
                $failCount++;
            }

            $this->line('');
        }

        $this->info('📊 '.__('modules.commands.seed.summary', ['success' => $successCount, 'failed' => $failCount]));

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
     * @param  string  $identifier  모듈 식별자
     * @param  string|null  $seederClass  사용자 지정 시더 클래스명
     * @return string 완전한 시더 클래스명
     */
    private function resolveSeederClass(string $identifier, ?string $seederClass): string
    {
        $namespace = ExtensionManager::moduleIdentifierToNamespace($identifier);

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
