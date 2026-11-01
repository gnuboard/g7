<?php

namespace App\Console\Commands\Module;

use App\Console\Commands\Concerns\RunsCascadeInstall;
use App\Console\Commands\Concerns\SelectsLayoutInstallSource;
use App\Console\Commands\Traits\HasProgressBar;
use App\Console\Commands\Traits\LocalizesDescription;
use App\Console\Commands\Traits\RebuildsSearchIndex;
use App\Console\Commands\Traits\ReportsIntegrationNotices;
use App\Console\Commands\Traits\ReportsLifecycleWarnings;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\ModuleManager;
use App\Extension\Vendor\VendorMode;
use App\Models\Menu;
use App\Models\Permission;
use App\Rules\ValidExtensionIdentifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class InstallModuleCommand extends Command
{
    use HasProgressBar;
    use LocalizesDescription;
    use RebuildsSearchIndex;
    use ReportsIntegrationNotices;
    use ReportsLifecycleWarnings;
    use RunsCascadeInstall;
    use SelectsLayoutInstallSource;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:install
        {identifier : 설치할 모듈 식별자}
        {--vendor-mode=auto : Vendor 설치 모드 (auto|composer|bundled)}
        {--force : 이미 설치된 경우에도 _bundled/_pending 원본으로 활성 디렉토리를 덮어쓰고 재설치 (불완전 설치 복구)}
        {--layout-source= : 레이아웃 설치 소스 (auto|json) — 생략하면 설정(LAYOUT_INSTALL_SOURCE)을 따름. json 은 짝 .json 이 있으면 .g7.html 대신 원본 JSON 으로 등록(진단·복구용)}
        {--rebuild-search-index : 완료 후 색인이 누락된 검색 인덱스를 재생성 (인덱스가 잠기거나 재색인됩니다 — 운영 중에는 유지보수 시간에 수행하세요)}
        {--with-dependencies : 필요한 의존 확장(의존의 의존 포함)을 같은 vendor 방식으로 함께 설치·활성화}
        {--with-language-packs=none : 함께 설치할 번들 언어팩 (none|all|식별자,…) — 관리자 설치 창의 후보와 같음}';

    /**
     * 모듈 관리자 및 리포지토리
     *
     * @param  ModuleManager  $moduleManager  모듈 관리자
     * @param  ModuleRepositoryInterface  $moduleRepository  모듈 리포지토리
     */
    public function __construct(
        private ModuleManager $moduleManager,
        private ModuleRepositoryInterface $moduleRepository
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int 종료 코드 (성공 0, 실패 1)
     */
    public function handle(): int
    {
        // `--layout-source` 검증 + 런타임 반영 (알 수 없는 값이면 즉시 실패).
        if (! $this->applyLayoutInstallSource()) {
            return self::FAILURE;
        }

        $identifier = $this->argument('identifier');

        // 식별자 형식 검증
        $validator = Validator::make(
            ['identifier' => $identifier],
            ['identifier' => [new ValidExtensionIdentifier]]
        );

        if ($validator->fails()) {
            $this->error('❌ '.$validator->errors()->first('identifier'));

            return Command::FAILURE;
        }

        $force = (bool) $this->option('force');

        try {
            // 모듈 디렉토리 스캔 및 로드
            $this->moduleManager->loadModules();

            // 이미 설치된 모듈인지 확인 (force 시 스킵하여 재설치/복구 허용)
            $existingModule = $this->moduleRepository->findByIdentifier($identifier);
            if ($existingModule && ! $force) {
                $this->warn('⚠️  '.__('modules.commands.install.already_installed', ['module' => $identifier]));

                return Command::FAILURE;
            }

            if ($existingModule && $force) {
                $this->warn('⚠️  '.__('modules.commands.install.force_reinstall', ['module' => $identifier]));
            }

            // 모듈 설치
            $vendorMode = VendorMode::fromStringOrAuto((string) $this->option('vendor-mode'));

            // 의존 확장 확인 — --with-dependencies 면 같은 vendor 방식으로 함께 설치·활성화 (관리자 설치 창과 같은 서비스)
            if (! $this->runCascadeDependencies('module', $identifier, $vendorMode)) {
                return Command::FAILURE;
            }

            $onProgress = $this->createProgressCallback(ModuleManager::INSTALL_STEPS);
            try {
                $result = $this->moduleManager->installModule($identifier, $onProgress, $vendorMode, $force);
                $this->finishProgress();
            } catch (\Exception $e) {
                $this->finishProgress();
                throw $e;
            }

            if ($result) {
                // 설치된 모듈 정보 조회
                $module = $this->moduleRepository->findByIdentifier($identifier);

                // 모듈 인스턴스에서 생성된 role 개수 조회
                $moduleInstance = $this->moduleManager->getModule($identifier);
                $rolesCount = 0;
                if ($moduleInstance && method_exists($moduleInstance, 'getRoles')) {
                    $rolesCount = count($moduleInstance->getRoles());
                }

                // 권한 및 메뉴 개수 조회
                $permissionsCount = Permission::byExtension(ExtensionOwnerType::Module, $identifier)->count();
                $menusCount = Menu::byExtension(ExtensionOwnerType::Module, $identifier)->count();

                // 성공 메시지
                $this->info($this->lifecycleDoneIcon().' '.__('modules.commands.install.success', ['module' => $identifier]));
                $this->reportIntegrationNotices('module', $identifier);
                $this->info('   - '.__('modules.commands.install.vendor', ['vendor' => $module->vendor]));
                $this->info('   - '.__('modules.commands.install.version', ['version' => $module->version]));
                $this->info('   - '.trans_choice('modules.commands.install.roles_created', $rolesCount));
                $this->info('   - '.trans_choice('modules.commands.install.permissions_created', $permissionsCount));
                $this->info('   - '.trans_choice('modules.commands.install.menus_created', $menusCount));

                Log::info(__('modules.commands.install.success', ['module' => $identifier]));

                // 동반 번들 언어팩 (--with-language-packs) — 하나가 실패해도 나머지는 계속 설치하고, 실패가 있으면 종료 코드로 알린다(본 확장 설치는 유지)
                $languagePacksInstalled = $this->runCascadeLanguagePacks();

                // 검색 인덱스 재생성은 운영자가 선택했을 때만 수행한다 (인덱스 잠금·재색인 비용)
                $this->handleSearchIndexRebuild();

                return $languagePacksInstalled ? Command::SUCCESS : Command::FAILURE;
            }

            $this->reportKeptCascadeDependencies();

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            $this->reportKeptCascadeDependencies();
            Log::error('모듈 설치 실패', [
                'module' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
