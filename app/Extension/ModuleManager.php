<?php

namespace App\Extension;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\UpgradeStepInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\DeactivationReason;
use App\Enums\ExtensionOwnerType;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutSourceType;
use App\Enums\PermissionType;
use App\Extension\Helpers\DependencyEnricher;
use App\Extension\Helpers\ExtensionBackupHelper;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\Helpers\ExtensionRoleSyncHelper;
use App\Extension\Helpers\ExtensionStatusGuard;
use App\Extension\Helpers\ExtensionUpgradeGuardHelper;
use App\Extension\Helpers\GithubHelper;
use App\Providers\CoreServiceProvider;
use App\Extension\Concerns\ResolvesExtensionSharedRecords;
use App\Extension\Helpers\IdentityMessageSyncHelper;
use App\Extension\Helpers\IdentityPolicySyncHelper;
use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\Vendor\Exceptions\VendorInstallException;
use App\Extension\Vendor\VendorInstallContext;
use App\Extension\Vendor\VendorInstallResult;
use App\Extension\Vendor\VendorMode;
use App\Extension\Vendor\VendorResolver;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use App\Services\LayoutExtensionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ModuleManager implements ModuleManagerInterface
{
    use ResolvesExtensionSharedRecords;
    use Traits\CachesModuleStatus;
    use Traits\ClearsTemplateCaches;
    use Traits\ComputesLayoutContentHash;
    use Traits\InspectsUninstallData;
    use Traits\InvalidatesLayoutCache;
    use Traits\RefreshesLayoutExtensions;
    use Traits\ValidatesLayoutFiles;
    use Traits\ValidatesPermissionStructure;
    use Traits\ValidatesSeoVariables;
    use Traits\ValidatesTranslationPath;

    /** @var int install 프로그레스바 단계 수 */
    public const INSTALL_STEPS = 8;

    /** @var int update 프로그레스바 단계 수 */
    public const UPDATE_STEPS = 11;

    /** @var int uninstall 프로그레스바 단계 수 */
    public const UNINSTALL_STEPS = 5;

    protected array $modules = [];

    protected string $modulesPath;

    /**
     * _pending 디렉토리의 모듈 메타데이터 (identifier => metadata)
     */
    protected array $pendingModules = [];

    /**
     * _bundled 디렉토리의 모듈 메타데이터 (identifier => metadata)
     */
    protected array $bundledModules = [];

    protected string $pendingModulesPath;

    protected string $bundledModulesPath;

    public function __construct(
        protected ExtensionManager $extensionManager,
        protected ModuleRepositoryInterface $moduleRepository,
        protected PermissionRepositoryInterface $permissionRepository,
        protected RoleRepositoryInterface $roleRepository,
        protected MenuRepositoryInterface $menuRepository,
        protected TemplateRepositoryInterface $templateRepository,
        protected PluginRepositoryInterface $pluginRepository,
        protected LayoutRepositoryInterface $layoutRepository,
        protected LayoutExtensionService $layoutExtensionService,
        protected ?ExtensionRoleSyncHelper $roleSyncHelper = null,
        protected ?ExtensionMenuSyncHelper $menuSyncHelper = null
    ) {
        $this->modulesPath = base_path('modules');
        $this->pendingModulesPath = $this->modulesPath.DIRECTORY_SEPARATOR.'_pending';
        $this->bundledModulesPath = $this->modulesPath.DIRECTORY_SEPARATOR.'_bundled';
    }

    /**
     * ExtensionRoleSyncHelper 인스턴스를 반환합니다.
     *
     * @return ExtensionRoleSyncHelper 역할/권한 동기화 헬퍼
     */
    protected function getRoleSyncHelper(): ExtensionRoleSyncHelper
    {
        if ($this->roleSyncHelper === null) {
            $this->roleSyncHelper = app(ExtensionRoleSyncHelper::class);
        }

        return $this->roleSyncHelper;
    }

    /**
     * ExtensionMenuSyncHelper 인스턴스를 반환합니다.
     *
     * @return ExtensionMenuSyncHelper 메뉴 동기화 헬퍼
     */
    protected function getMenuSyncHelper(): ExtensionMenuSyncHelper
    {
        if ($this->menuSyncHelper === null) {
            $this->menuSyncHelper = app(ExtensionMenuSyncHelper::class);
        }

        return $this->menuSyncHelper;
    }

    /**
     * 모든 모듈을 로드하고 초기화합니다.
     */
    public function loadModules(): void
    {
        if (! File::exists($this->modulesPath)) {
            return;
        }

        $directories = File::directories($this->modulesPath);

        foreach ($directories as $directory) {
            $moduleName = basename($directory);

            // _bundled, _pending 등 내부 디렉토리 건너뛰기
            if (str_starts_with($moduleName, '_')) {
                continue;
            }

            $moduleFile = $directory.'/module.php';
            $moduleJson = $directory.'/module.json';

            // 무결성 검사: 활성 디렉토리는 있으나 manifest 누락 감지
            // (업데이트 중 원자적 교체 실패로 src/ 만 남는 등의 불완전 상태)
            if (! File::exists($moduleFile) || ! File::exists($moduleJson)) {
                Log::warning('모듈 활성 디렉토리가 불완전합니다 (manifest 누락)', [
                    'module' => $moduleName,
                    'directory' => $directory,
                    'module_php' => File::exists($moduleFile),
                    'module_json' => File::exists($moduleJson),
                    'hint' => "복구: php artisan module:install {$moduleName} --force",
                ]);
            }

            if (File::exists($moduleFile)) {
                // vendor-module 형식을 네임스페이스로 변환
                $namespace = $this->convertDirectoryToNamespace($moduleName);
                $moduleClass = "Modules\\{$namespace}\\Module";

                // 클래스가 아직 로드되지 않은 경우에만 require
                // (_bundled에서 이미 로드된 경우 중복 선언 방지)
                if (! class_exists($moduleClass, false)) {
                    require_once $moduleFile;
                }

                if (class_exists($moduleClass)) {
                    $module = new $moduleClass;
                    if ($module instanceof ModuleInterface) {
                        $this->modules[$moduleName] = $module;

                        // 모듈 config 파일 로드
                        $this->loadModuleConfig($module);

                        // 훅 리스너 자동 등록
                        $this->registerModuleHookListeners($module);

                        // 브로드캐스트 채널 자동 등록
                        $this->registerModuleChannels($module);
                    }
                }
            }
        }

        // _pending / _bundled 디렉토리의 모듈 메타데이터 로드
        $this->loadPendingModules();
        $this->loadBundledModules();
    }

    /**
     * _pending 디렉토리의 모듈 메타데이터를 로드합니다.
     *
     * 클래스 로드 없이 module.json 메타데이터만 읽어 저장합니다.
     * 이미 활성 디렉토리에 로드된 모듈은 제외합니다.
     */
    protected function loadPendingModules(): void
    {
        $pending = ExtensionPendingHelper::loadPendingExtensions($this->modulesPath, 'module.json');

        foreach ($pending as $identifier => $metadata) {
            // 이미 활성 디렉토리에 로드된 모듈은 제외
            if (isset($this->modules[$identifier])) {
                continue;
            }

            $this->pendingModules[$identifier] = $metadata;
        }
    }

    /**
     * _bundled 디렉토리의 모듈 메타데이터를 로드합니다.
     *
     * 클래스 로드 없이 module.json 메타데이터만 읽어 저장합니다.
     * 이미 활성 디렉토리 또는 _pending에 로드된 모듈은 제외합니다.
     */
    protected function loadBundledModules(): void
    {
        $bundled = ExtensionPendingHelper::loadBundledExtensions($this->modulesPath, 'module.json');

        foreach ($bundled as $identifier => $metadata) {
            // 이미 활성 디렉토리 또는 pending에 로드된 모듈은 제외
            if (isset($this->modules[$identifier]) || isset($this->pendingModules[$identifier])) {
                continue;
            }

            $this->bundledModules[$identifier] = $metadata;
        }
    }

    /**
     * _pending 디렉토리의 모듈 메타데이터를 반환합니다.
     *
     * @return array _pending 모듈 메타데이터 배열
     */
    public function getPendingModules(): array
    {
        return $this->pendingModules;
    }

    /**
     * _bundled 디렉토리의 모듈 메타데이터를 반환합니다.
     *
     * @return array _bundled 모듈 메타데이터 배열
     */
    public function getBundledModules(): array
    {
        return $this->bundledModules;
    }

    /**
     * 디렉토리명(vendor-module)을 네임스페이스(Vendor\Module)로 변환합니다.
     *
     * 하이픈(-)은 네임스페이스 구분자(\)로, 언더스코어(_)는 PascalCase로 변환됩니다.
     * 예: sirsoft-my_module -> Sirsoft\MyModule
     *
     * @param  string  $directoryName  디렉토리명 (예: sirsoft-ecommerce, sirsoft-my_module)
     * @return string 네임스페이스 (예: Sirsoft\Ecommerce, Sirsoft\MyModule)
     */
    protected function convertDirectoryToNamespace(string $directoryName): string
    {
        return ExtensionManager::directoryToNamespace($directoryName);
    }

    /**
     * 활성화된 모듈들만 반환합니다.
     *
     * @return array 활성화된 모듈 배열
     */
    public function getActiveModules(): array
    {
        $activeModules = [];

        // 캐시된 활성화 모듈 identifier 목록 활용
        $activeIdentifiers = self::getActiveModuleIdentifiers();

        foreach ($this->modules as $name => $module) {
            if (in_array($module->getIdentifier(), $activeIdentifiers, true)) {
                $activeModules[$name] = $module;
            }
        }

        return $activeModules;
    }

    /**
     * 지정된 모듈을 시스템에 설치합니다.
     *
     * @param  string  $moduleName  설치할 모듈명
     * @param  \Closure|null  $onProgress  진행 콜백 (?string $step, string $message)
     * @param  VendorMode  $vendorMode  vendor 디렉토리 처리 모드
     * @param  bool  $force  강제 설치 여부
     * @return bool 설치 성공 여부
     *
     * @throws \Exception 모듈을 찾을 수 없거나 의존성 문제 시
     */
    public function installModule(
        string $moduleName,
        ?\Closure $onProgress = null,
        VendorMode $vendorMode = VendorMode::Auto,
        bool $force = false,
    ): bool {
        // identifier 형식 검증 (내부 호출 방어)
        ExtensionManager::validateIdentifierFormat($moduleName);

        // 상태 가드: 이미 설치된 모듈의 진행 중 상태 체크
        $existingRecord = $this->moduleRepository->findByIdentifier($moduleName);
        if ($existingRecord) {
            ExtensionStatusGuard::assertNotInProgress(
                ExtensionStatus::from($existingRecord->status),
                $moduleName
            );
        }

        // _pending 감지: 활성 디렉토리 부재 + _pending 존재 시 스테이징 설치 흐름
        $activePath = $this->modulesPath.DIRECTORY_SEPARATOR.$moduleName;
        $composerDoneInPending = false;
        $resolvedVendorMode = $vendorMode;

        if (! File::isDirectory($activePath)
            && ExtensionPendingHelper::isPending($this->modulesPath, $moduleName)) {
            // _pending에서 Vendor 의존성 설치 (활성 디렉토리 이관 전)
            if (! app()->environment('testing')) {
                $pendingPath = ExtensionPendingHelper::getPendingPath($this->modulesPath, $moduleName);
                if ($this->extensionManager->hasComposerDependenciesAt($pendingPath)) {
                    $onProgress?->__invoke('composer', '_pending에서 Vendor 의존성 설치 중...');
                    try {
                        $vendorResult = $this->installVendorViaResolver(
                            $moduleName,
                            $pendingPath,
                            $vendorMode,
                            'install',
                            null,
                            $onProgress,
                        );
                        $resolvedVendorMode = $vendorResult->mode;
                        $composerDoneInPending = true;
                    } catch (VendorInstallException $e) {
                        Log::warning('모듈 _pending Vendor 의존성 설치 실패', [
                            'module' => $moduleName,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        // _pending 또는 _bundled에서 활성 디렉토리로 복사 (미설치 모듈 설치 시)
        // force=true 시 활성 디렉토리가 있어도 원본으로 덮어씀 (불완전 설치 복구)
        $onProgress?->__invoke('copy', '파일 복사 중...');
        $this->copyFromPendingOrBundled($moduleName, $onProgress, $force);

        // 모듈이 활성 디렉토리에 있지 않으면 로드 시도
        $module = $this->getModule($moduleName);
        if (! $module) {
            // 복사 후 재로드 시도
            $this->reloadModule($moduleName);
            $module = $this->getModule($moduleName);
        }

        if (! $module) {
            throw new \Exception(__('modules.not_found', ['module' => $moduleName]));
        }

        // 그누보드7 코어 버전 호환성 검증
        CoreVersionChecker::validateExtension(
            $module->getRequiredCoreVersion(),
            $module->getIdentifier(),
            'module'
        );

        // 의존성 확인 (트랜잭션 외부에서 먼저 검증)
        $onProgress?->__invoke('validate', '검증 중...');
        $this->checkDependencies($module);

        // 권한 구조 검증 (계층형 구조 필수)
        $this->validatePermissionStructure($module, 'module');

        // 언어 파일 경로 검증 (src/lang 경로 필수)
        $this->validateTranslationPath($module, 'module');

        // SEO 변수명 중복 검증
        $this->validateSeoVariables($module, 'module');

        // 모듈 설치 실행
        $result = $module->install();

        if (! $result) {
            return false;
        }

        // Phase 1: 마이그레이션 실행 (DDL - 트랜잭션 외부)
        // MySQL에서 CREATE TABLE 등 DDL 문은 암시적 커밋을 유발하므로 트랜잭션 외부에서 실행
        $onProgress?->__invoke('migration', '마이그레이션 실행 중...');
        $this->runMigrations($module);

        // Phase 2: 데이터 작업 (DML - 트랜잭션 내부)
        $onProgress?->__invoke('db', 'DB 등록 중...');
        try {
            DB::beginTransaction();

            // GitHub에서 최신 버전 정보 가져오기
            $latestVersion = $this->fetchLatestVersion($module);
            $updateAvailable = $latestVersion ? version_compare($latestVersion, $module->getVersion(), '>') : false;

            // 다국어 name, description 처리 (역호환성 지원)
            $name = $this->convertToMultilingual($module->getName());
            $description = $this->convertToMultilingual($module->getDescription());

            // 데이터베이스에 모듈 정보 저장
            $this->moduleRepository->updateOrCreate(
                ['identifier' => $module->getIdentifier()],
                [
                    'vendor' => $module->getVendor(),
                    'name' => $name,
                    'version' => $module->getVersion(),
                    'latest_version' => $latestVersion,
                    'description' => $description,
                    'github_url' => $module->getGithubUrl(),
                    'github_changelog_url' => $this->buildChangelogUrl($module->getGithubUrl()),
                    'update_available' => $updateAvailable,
                    'metadata' => $module->getMetadata(),
                    'status' => ExtensionStatus::Inactive->value,
                    'vendor_mode' => $resolvedVendorMode->value,
                    'config' => $module->getConfig(),
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // Role 자동 생성
            $this->createModuleRoles($module);

            // 권한 자동 생성
            $this->createModulePermissions($module);

            // 권한-Role 연결
            $this->assignPermissionsToRoles($module);

            // 관리자 메뉴 자동 생성
            $this->createModuleMenus($module);

            // IDV 정책 자동 동기화 (identity_policies 테이블)
            $this->syncModuleIdentityPolicies($module);

            // IDV 메시지 정의/템플릿 자동 동기화 (identity_message_definitions / identity_message_templates)
            $this->syncModuleIdentityMessages($module);

            // 알림 정의/템플릿 자동 동기화 (notification_definitions / notification_templates)
            $this->syncModuleNotificationDefinitions($module);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        // Phase 3: 시더 실행 (트랜잭션 외부)
        // 시더 내부에서 별도 트랜잭션을 사용할 수 있으므로 외부에서 실행
        $onProgress?->__invoke('seed', '시더 실행 중...');
        $this->runModuleSeeders($module);

        // Phase 4: 기본 설정 파일 생성
        $onProgress?->__invoke('settings', '환경설정 초기화 중...');
        $this->initializeModuleSettings($module);

        // Phase 4.5: Composer 의존성 설치 (외부 패키지가 있는 경우에만)
        // _pending에서 이미 설치한 경우 스킵 (vendor/가 활성 디렉토리에 복사됨)
        if (! $composerDoneInPending) {
            $onProgress?->__invoke('composer', 'Composer 의존성 설치 중...');
            if (! app()->environment('testing')
                && $this->extensionManager->hasComposerDependencies('modules', $moduleName)) {
                $composerResult = $this->extensionManager->runComposerInstall('modules', $moduleName);
                if (! $composerResult) {
                    Log::warning('모듈 Composer 의존성 설치 실패', ['module' => $moduleName]);
                }
            }
        }

        // Phase 5: 오토로드 병합 실행 (트랜잭션 외부)
        $onProgress?->__invoke('autoload', '오토로드 갱신 중...');
        $this->extensionManager->updateComposerAutoload();

        // 모듈 상태 캐시 무효화
        self::invalidateModuleStatusCache();

        // 확장 캐시 버전 증가 (프론트엔드가 새로운 캐시로 요청하도록)
        $this->incrementExtensionCacheVersion();

        // 훅 발행: 모듈 설치 완료
        HookManager::doAction('core.modules.installed', $moduleName);

        return true;
    }

    /**
     * 지정된 모듈을 활성화합니다.
     *
     * @param  string  $moduleName  활성화할 모듈명
     * @param  bool  $force  필요 의존성이 충족되지 않아도 강제 활성화 여부
     * @return array{success: bool, layouts_registered: int, warning?: bool, missing_modules?: array, missing_plugins?: array, message?: string} 활성화 결과 및 등록된 레이아웃 개수
     */
    public function activateModule(string $moduleName, bool $force = false): array
    {
        $module = $this->getModule($moduleName);
        if (! $module) {
            return ['success' => false, 'layouts_registered' => 0];
        }

        // 상태 가드: 진행 중 상태 체크
        $record = $this->moduleRepository->findByIdentifier($module->getIdentifier());
        if ($record) {
            ExtensionStatusGuard::assertNotInProgress(
                ExtensionStatus::from($record->status),
                $module->getIdentifier()
            );
        }

        // 코어 버전 호환성 사전 검증 (#306 sync 훅보다 앞쪽)
        if (! $force && ! CoreServiceProvider::isCoreUpdateInProgress()) {
            CoreVersionChecker::validateExtension(
                $module->getRequiredCoreVersion(),
                $module->getIdentifier(),
                'module'
            );
        }

        // 의존성 검증: 필요한 모듈/플러그인이 활성화되어 있는지 확인
        // 중첩 구조 ['modules' => [...], 'plugins' => [...]] 를 순회
        $missingModules = [];
        $missingPlugins = [];

        $dependencies = $module->getDependencies();
        foreach ($this->iterateNestedDependencies($dependencies) as $depIdentifier => $declaredType) {
            if ($declaredType === 'module') {
                $depModule = $this->moduleRepository->findByIdentifier($depIdentifier);
                if (! $depModule) {
                    $missingModules[] = [
                        'identifier' => $depIdentifier,
                        'name' => $depIdentifier,
                        'status' => 'not_installed',
                    ];
                } elseif ($depModule->status !== ExtensionStatus::Active->value) {
                    $missingModules[] = [
                        'identifier' => $depModule->identifier,
                        'name' => $depModule->getLocalizedName(),
                        'status' => 'inactive',
                    ];
                }
            } else {
                $depPlugin = $this->pluginRepository->findByIdentifier($depIdentifier);
                if (! $depPlugin) {
                    $missingPlugins[] = [
                        'identifier' => $depIdentifier,
                        'name' => $depIdentifier,
                        'status' => 'not_installed',
                    ];
                } elseif ($depPlugin->status !== ExtensionStatus::Active->value) {
                    $missingPlugins[] = [
                        'identifier' => $depPlugin->identifier,
                        'name' => $depPlugin->getLocalizedName(),
                        'status' => 'inactive',
                    ];
                }
            }
        }

        $hasMissingDependencies = ! empty($missingModules) || ! empty($missingPlugins);

        if ($hasMissingDependencies && ! $force) {
            // 필요한 의존성이 충족되지 않고 강제 활성화가 아닌 경우 경고 반환
            return [
                'success' => false,
                'warning' => true,
                'missing_modules' => $missingModules,
                'missing_plugins' => $missingPlugins,
                'message' => __('modules.warnings.missing_dependencies'),
                'layouts_registered' => 0,
            ];
        }

        $result = $module->activate();
        $layoutsRegistered = 0;

        if ($result) {
            $this->moduleRepository->updateByIdentifier($module->getIdentifier(), [
                'status' => ExtensionStatus::Active->value,
                'deactivated_reason' => null,
                'deactivated_at' => null,
                'incompatible_required_version' => null,
                'updated_by' => Auth::id(),
                'updated_at' => now(),
            ]);

            // soft deleted된 모듈 레이아웃 복원 (재활성화 시)
            $this->restoreModuleLayouts($module->getIdentifier());

            // 모듈 레이아웃 등록 (새로운 레이아웃 또는 업데이트)
            $layoutsRegistered = $this->registerModuleLayouts($moduleName);

            // 모듈 레이아웃 확장 복원 (재활성화 시)
            $this->restoreLayoutExtensions($module);

            // 모듈 레이아웃 확장 등록
            $this->registerLayoutExtensions($module);

            // 모듈 다국어 데이터가 변경되므로 템플릿 언어 캐시 삭제
            $this->clearAllTemplateLanguageCaches();

            // 모듈 routes 데이터가 변경되므로 템플릿 routes 캐시 삭제
            $this->clearAllTemplateRoutesCaches();

            // 확장 기능 캐시 버전 증가 (프론트엔드 캐시 무효화)
            $this->incrementExtensionCacheVersion();

            // 모듈 상태 캐시 무효화
            self::invalidateModuleStatusCache();
        }

        // 훅 발행: 모듈 활성화 완료
        if ($result) {
            HookManager::doAction('core.modules.activated', $moduleName);
        }

        return ['success' => $result, 'layouts_registered' => $layoutsRegistered];
    }

    /**
     * soft deleted된 모듈 레이아웃을 복원합니다.
     *
     * 모듈 재활성화 시 이전에 soft delete된 레이아웃을 복원합니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     */
    protected function restoreModuleLayouts(string $moduleIdentifier): void
    {
        try {
            $restoredCount = $this->layoutRepository->restoreByModule($moduleIdentifier);

            if ($restoredCount > 0) {
                Log::info('모듈 레이아웃 복원 완료', [
                    'module' => $moduleIdentifier,
                    'restored_count' => $restoredCount,
                ]);
            }
        } catch (\Exception $e) {
            Log::error("모듈 레이아웃 복원 실패: {$moduleIdentifier}", [
                'error' => $e->getMessage(),
            ]);

            // 복원 실패가 모듈 활성화를 중단시키지 않음
        }
    }

    /**
     * 지정된 모듈을 비활성화합니다.
     *
     * @param  string  $moduleName  비활성화할 모듈명
     * @param  bool  $force  의존 확장이 있어도 강제 비활성화 여부
     * @param  string  $reason  비활성화 사유 (DeactivationReason enum value: manual|incompatible_core)
     * @param  string|null  $incompatibleRequiredVersion  incompatible_core 사유 시 요구된 코어 버전 제약
     * @return array{success: bool, layouts_deleted: int, warning?: bool, dependent_templates?: array, dependent_modules?: array, dependent_plugins?: array, message?: string} 비활성화 결과 및 삭제된 레이아웃 개수
     */
    public function deactivateModule(
        string $moduleName,
        bool $force = false,
        string $reason = DeactivationReason::Manual->value,
        ?string $incompatibleRequiredVersion = null,
    ): array {
        $module = $this->getModule($moduleName);
        if (! $module) {
            return ['success' => false, 'layouts_deleted' => 0];
        }

        // 상태 가드: 진행 중 상태 체크
        $record = $this->moduleRepository->findByIdentifier($module->getIdentifier());
        if ($record) {
            ExtensionStatusGuard::assertNotInProgress(
                ExtensionStatus::from($record->status),
                $module->getIdentifier()
            );
        }

        // 역의존성 검증: 이 모듈에 의존하는 활성 템플릿/모듈/플러그인 확인
        $dependentTemplates = $this->templateRepository->findActiveByModuleDependency($moduleName);
        $dependentModules = $this->moduleRepository->findActiveByModuleDependency($moduleName);
        $dependentPlugins = $this->pluginRepository->findActiveByModuleDependency($moduleName);

        $hasDependents = $dependentTemplates->isNotEmpty() ||
                         $dependentModules->isNotEmpty() ||
                         $dependentPlugins->isNotEmpty();

        if ($hasDependents && ! $force) {
            // 의존하는 확장이 있고 강제 비활성화가 아닌 경우 경고 반환
            return [
                'success' => false,
                'warning' => true,
                'dependent_templates' => $dependentTemplates->map(function (Template $template) {
                    return [
                        'identifier' => $template->identifier,
                        'name' => $template->getLocalizedName(),
                        'type' => $template->type,
                    ];
                })->toArray(),
                'dependent_modules' => $dependentModules->map(function (Module $module) {
                    return [
                        'identifier' => $module->identifier,
                        'name' => $module->getLocalizedName(),
                    ];
                })->toArray(),
                'dependent_plugins' => $dependentPlugins->map(function (Plugin $plugin) {
                    return [
                        'identifier' => $plugin->identifier,
                        'name' => $plugin->getLocalizedName(),
                    ];
                })->toArray(),
                'message' => __('modules.warnings.has_dependents'),
                'layouts_deleted' => 0,
            ];
        }

        $result = $module->deactivate();
        $layoutsDeleted = 0;

        if ($result) {
            $this->moduleRepository->updateByIdentifier($module->getIdentifier(), [
                'status' => ExtensionStatus::Inactive->value,
                'deactivated_reason' => $reason,
                'deactivated_at' => now(),
                'incompatible_required_version' => $incompatibleRequiredVersion,
                'updated_by' => Auth::id(),
                'updated_at' => now(),
            ]);

            // 모듈 레이아웃 soft delete
            $layoutsDeleted = $this->softDeleteModuleLayouts($module->getIdentifier());

            // 모듈 레이아웃 확장 soft delete
            $this->unregisterLayoutExtensions($module);

            // 모듈 다국어 데이터가 변경되므로 템플릿 언어 캐시 삭제
            $this->clearAllTemplateLanguageCaches();

            // 모듈 routes 데이터가 변경되므로 템플릿 routes 캐시 삭제
            $this->clearAllTemplateRoutesCaches();

            // 확장 기능 캐시 버전 증가 (프론트엔드 캐시 무효화)
            $this->incrementExtensionCacheVersion();

            // 모듈 자체 캐시 전체 정리
            $this->flushModuleCache($module);

            // 모듈 상태 캐시 무효화
            self::invalidateModuleStatusCache();

            // 요구사항 #6: 비활성화 후 훅 발행 — 언어팩 cascade 등 후속 처리
            HookManager::doAction('core.modules.after_deactivate', $module->getIdentifier());
        }

        return ['success' => $result, 'layouts_deleted' => $layoutsDeleted];
    }

    /**
     * 모듈의 레이아웃을 soft delete합니다.
     *
     * 모듈 비활성화 시 해당 모듈의 레이아웃만 선택적으로 soft delete하고
     * 관련 캐시를 무효화합니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return int 삭제된 레이아웃 개수
     */
    protected function softDeleteModuleLayouts(string $moduleIdentifier): int
    {
        try {
            $deletedCount = $this->layoutRepository->softDeleteByModule($moduleIdentifier);

            if ($deletedCount === 0) {
                Log::info("모듈에 삭제할 레이아웃이 없습니다: {$moduleIdentifier}");

                return 0;
            }

            // 레이아웃 캐시 무효화
            $this->invalidateLayoutCache($moduleIdentifier);

            Log::info('모듈 레이아웃 soft delete 완료', [
                'module' => $moduleIdentifier,
                'deleted_count' => $deletedCount,
            ]);

            return $deletedCount;
        } catch (\Exception $e) {
            Log::error("모듈 레이아웃 soft delete 실패: {$moduleIdentifier}", [
                'error' => $e->getMessage(),
            ]);

            // 레이아웃 삭제 실패가 모듈 비활성화를 중단시키지 않음
            return 0;
        }
    }

    /**
     * 지정된 모듈을 시스템에서 제거합니다.
     *
     * @param  string  $moduleName  제거할 모듈명
     * @param  bool  $deleteData  모듈 데이터(테이블) 삭제 여부
     * @param  \Closure|null  $onProgress  진행 콜백 (?string $step, string $message)
     * @return bool 제거 성공 여부
     *
     * @throws \Exception 모듈을 찾을 수 없을 때
     */
    public function uninstallModule(string $moduleName, bool $deleteData = false, ?\Closure $onProgress = null): bool
    {
        // 상태 가드: 진행 중 상태 체크
        $existingRecord = $this->moduleRepository->findByIdentifier($moduleName);
        if ($existingRecord) {
            ExtensionStatusGuard::assertNotInProgress(
                ExtensionStatus::from($existingRecord->status),
                $moduleName
            );
        }

        $module = $this->getModule($moduleName);
        if (! $module) {
            throw new \Exception(__('modules.not_found', ['module' => $moduleName]));
        }

        // Phase 1: 데이터 삭제 옵션이 true인 경우 동적 데이터 정리 및 마이그레이션 롤백 (DDL - 트랜잭션 외부)
        // MySQL에서 DROP TABLE 등 DDL 문은 암시적 커밋을 유발하므로 트랜잭션 외부에서 실행
        $onProgress?->__invoke('data_cleanup', '데이터 정리 중...');
        if ($deleteData) {
            // 동적 데이터 정리 (마이그레이션 롤백 전에 실행해야 메타 테이블 조회 가능)
            $this->cleanupDynamicModuleData($module);
            $this->rollbackMigrations($module);
            // 모듈 스토리지 디렉토리 전체 삭제
            $this->deleteModuleStorage($module);
            // Composer vendor 디렉토리 및 composer.lock 삭제
            $this->deleteVendorDirectory('modules', $module->getIdentifier());
        }

        try {
            $onProgress?->__invoke('db', 'DB 정리 중...');
            DB::beginTransaction();

            // 모듈 제거 실행
            $result = $module->uninstall();

            if ($result) {
                // 권한·메뉴·역할은 $deleteData=true 시에만 삭제.
                // false 시 보존하여 재설치 시 기존 역할 할당/커스터마이징이 복원 가능하도록 한다.
                // 운영 정책: "동적 권한/메뉴는 '데이터도 함께 삭제' 옵션 체크 시에만 삭제"
                if ($deleteData) {
                    $this->removeModulePermissionsAndMenus($module);

                    // IDV 정책도 data 옵션 선택 시 제거 (동일 정책 — 재설치 시 user_overrides 손실 허용)
                    if (Schema::hasTable('identity_policies')) {
                        try {
                            app(IdentityPolicySyncHelper::class)
                                ->cleanupStalePolicies('module', $module->getIdentifier(), []);
                        } catch (\Throwable $e) {
                            Log::warning('IDV 정책 정리 실패 (uninstall)', [
                                'module' => $module->getIdentifier(),
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    // IDV 메시지 정의/템플릿도 data 옵션 선택 시 제거 (FK cascade 로 templates 자동 정리)
                    if (Schema::hasTable('identity_message_definitions')) {
                        try {
                            app(IdentityMessageSyncHelper::class)
                                ->cleanupStaleDefinitions('module', $module->getIdentifier(), []);
                        } catch (\Throwable $e) {
                            Log::warning('IDV 메시지 정리 실패 (uninstall)', [
                                'module' => $module->getIdentifier(),
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    // 알림 정의/템플릿도 data 옵션 선택 시 제거 (FK cascade 로 templates 자동 정리)
                    if (Schema::hasTable('notification_definitions')) {
                        try {
                            app(NotificationSyncHelper::class)
                                ->cleanupStaleDefinitions('module', $module->getIdentifier(), []);
                        } catch (\Throwable $e) {
                            Log::warning('알림 정의 정리 실패 (uninstall)', [
                                'module' => $module->getIdentifier(),
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }

                // 모듈 레이아웃 영구 삭제
                $this->deleteModuleLayouts($module->getIdentifier());

                // 모듈 레이아웃 확장 영구 삭제
                $this->deleteLayoutExtensions($module);

                // 데이터베이스에서 모듈 정보 제거
                $this->moduleRepository->deleteByIdentifier($module->getIdentifier());
            }

            DB::commit();

            // 오토로드 병합 실행 (트랜잭션 외부에서 실행)
            if ($result) {
                $onProgress?->__invoke('autoload', '오토로드 갱신 중...');
                $this->extensionManager->updateComposerAutoload();

                // 모듈 다국어 데이터가 변경되므로 템플릿 언어 캐시 삭제
                $onProgress?->__invoke('cache', '캐시 정리 중...');
                $this->clearAllTemplateLanguageCaches();

                // 모듈 routes 데이터가 변경되므로 템플릿 routes 캐시 삭제
                $this->clearAllTemplateRoutesCaches();

                // 확장 기능 캐시 버전 증가 (프론트엔드 캐시 무효화)
                $this->incrementExtensionCacheVersion();

                // 모듈 자체 캐시 전체 정리
                $this->flushModuleCache($module);

                // 모듈 상태 캐시 무효화
                self::invalidateModuleStatusCache();

                // 활성 모듈 디렉토리 전체 삭제 (_pending/_bundled에 원본 보존되므로 재설치 가능)
                $onProgress?->__invoke('files', '파일 삭제 중...');
                ExtensionPendingHelper::deleteExtensionDirectory($this->modulesPath, $module->getIdentifier());

                // 메모리에서 모듈 제거
                unset($this->modules[$module->getIdentifier()]);
            }

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 지정된 이름의 모듈 인스턴스를 반환합니다.
     *
     * @param  string  $moduleName  모듈명
     * @return ModuleInterface|null 모듈 인스턴스 또는 null
     */
    public function getModule(string $moduleName): ?ModuleInterface
    {
        return $this->modules[$moduleName] ?? null;
    }

    /**
     * 로드된 모든 모듈 인스턴스들을 반환합니다.
     *
     * @return array 모든 모듈 배열
     */
    public function getAllModules(): array
    {
        return $this->modules;
    }

    /**
     * 모듈의 버전을 반환합니다.
     *
     * 설치된 모듈의 경우 DB에서, 미설치 모듈의 경우 module.php에서 버전을 조회합니다.
     *
     * @param  string  $identifier  모듈 식별자 (예: sirsoft-ecommerce)
     * @return string|null 버전 문자열 또는 null (모듈이 없는 경우)
     */
    public function getModuleVersion(string $identifier): ?string
    {
        // 먼저 DB에서 조회 (설치된 모듈)
        $module = $this->moduleRepository->findByIdentifier($identifier);
        if ($module) {
            return $module->version;
        }

        // DB에 없으면 로드된 모듈 인스턴스에서 조회
        foreach ($this->modules as $module) {
            if ($module->getIdentifier() === $identifier) {
                return $module->getVersion();
            }
        }

        return null;
    }

    /**
     * 설치되지 않은 모듈들을 반환합니다 (목록용 간소화된 정보).
     *
     * 활성 디렉토리, _pending, _bundled의 미설치 모듈을 모두 포함합니다.
     *
     * @return array 미설치 모듈 배열
     */
    public function getUninstalledModules(): array
    {
        $uninstalledModules = [];
        // 캐시된 설치된 모듈 identifier 목록 활용
        $installedModuleIdentifiers = self::getInstalledModuleIdentifiers();
        $locale = app()->getLocale();

        // 1. 활성 디렉토리의 미설치 모듈
        foreach ($this->modules as $name => $module) {
            if (! in_array($module->getIdentifier(), $installedModuleIdentifiers)) {
                $identifier = $module->getIdentifier();

                // 에셋 정보 수집 (API URL 경로 포함)
                $assets = null;
                if ($module->hasAssets()) {
                    $builtPaths = $module->getBuiltAssetPaths();
                    $loadingConfig = $module->getAssetLoadingConfig();

                    if (isset($builtPaths['js']) || isset($builtPaths['css'])) {
                        $assets = [
                            'js' => isset($builtPaths['js']) ? "/api/modules/assets/{$identifier}/".$builtPaths['js'] : null,
                            'css' => isset($builtPaths['css']) ? "/api/modules/assets/{$identifier}/".$builtPaths['css'] : null,
                            'priority' => $loadingConfig['priority'] ?? 100,
                        ];
                    }
                }

                $uninstalledModules[$name] = [
                    'identifier' => $identifier,
                    'vendor' => $module->getVendor(),
                    'name' => $this->getLocalizedValue($module->getName(), $locale),
                    'version' => $module->getVersion(),
                    'description' => $this->getLocalizedValue($module->getDescription(), $locale),
                    'dependencies' => $this->enrichDependencies($module->getDependencies()),
                    'status' => 'uninstalled',
                    'is_pending' => false,
                    'is_bundled' => false,
                    'hidden' => $module->isHidden(),
                    'assets' => $assets,
                ];
            }
        }

        // 2. _pending 디렉토리의 미설치 모듈
        foreach ($this->pendingModules as $identifier => $metadata) {
            if (! in_array($identifier, $installedModuleIdentifiers) && ! isset($uninstalledModules[$identifier])) {
                $uninstalledModules[$identifier] = [
                    'identifier' => $identifier,
                    'vendor' => $metadata['vendor'] ?? '',
                    'name' => $this->getLocalizedValue($metadata['name'] ?? $identifier, $locale),
                    'version' => $metadata['version'] ?? '0.0.0',
                    'description' => $this->getLocalizedValue($metadata['description'] ?? '', $locale),
                    'dependencies' => $this->enrichDependencies($metadata['dependencies'] ?? []),
                    'status' => 'uninstalled',
                    'is_pending' => true,
                    'is_bundled' => false,
                    'hidden' => (bool) ($metadata['hidden'] ?? false),
                    'assets' => null,
                ];
            }
        }

        // 3. _bundled 디렉토리의 미설치 모듈
        foreach ($this->bundledModules as $identifier => $metadata) {
            if (! in_array($identifier, $installedModuleIdentifiers) && ! isset($uninstalledModules[$identifier])) {
                $uninstalledModules[$identifier] = [
                    'identifier' => $identifier,
                    'vendor' => $metadata['vendor'] ?? '',
                    'name' => $this->getLocalizedValue($metadata['name'] ?? $identifier, $locale),
                    'version' => $metadata['version'] ?? '0.0.0',
                    'description' => $this->getLocalizedValue($metadata['description'] ?? '', $locale),
                    'dependencies' => $this->enrichDependencies($metadata['dependencies'] ?? []),
                    'status' => 'uninstalled',
                    'is_pending' => false,
                    'is_bundled' => true,
                    'hidden' => (bool) ($metadata['hidden'] ?? false),
                    'assets' => null,
                ];
            }
        }

        return $uninstalledModules;
    }

    /**
     * 설치된 모듈 정보를 데이터베이스 레코드와 함께 반환합니다 (목록용 간소화된 정보).
     *
     * 업데이트 관련 필드(update_available, latest_version, file_version, github_url)를 포함합니다.
     *
     * @return array 설치된 모듈 배열
     */
    public function getInstalledModulesWithDetails(): array
    {
        $installedModules = [];
        $moduleRecords = $this->moduleRepository->getAllKeyedByIdentifier();
        $locale = app()->getLocale();

        foreach ($this->modules as $name => $module) {
            $identifier = $module->getIdentifier();
            if ($moduleRecords->has($identifier)) {
                $record = $moduleRecords->get($identifier);

                // 에셋 정보 수집 (API URL 경로 포함)
                $assets = null;
                if ($module->hasAssets()) {
                    $builtPaths = $module->getBuiltAssetPaths();
                    $loadingConfig = $module->getAssetLoadingConfig();

                    if (isset($builtPaths['js']) || isset($builtPaths['css'])) {
                        $assets = [
                            'js' => isset($builtPaths['js']) ? "/api/modules/assets/{$identifier}/".$builtPaths['js'] : null,
                            'css' => isset($builtPaths['css']) ? "/api/modules/assets/{$identifier}/".$builtPaths['css'] : null,
                            'priority' => $loadingConfig['priority'] ?? 100,
                        ];
                    }
                }

                // 업데이트 감지: GitHub URL이 있으면 DB latest_version 비교, 없으면 파일 버전 비교
                $fileVersion = $module->getVersion();
                $updateAvailable = $record->update_available ?? false;
                $latestVersion = $record->latest_version ?? null;

                // update_available이 true인데 latest_version이 null이면 _bundled 버전으로 보완
                if ($updateAvailable && $latestVersion === null) {
                    $bundledVersion = $this->bundledModules[$identifier]['version'] ?? null;
                    if ($bundledVersion === null) {
                        $bundledMeta = ExtensionPendingHelper::loadBundledExtensions($this->modulesPath, 'module.json');
                        $bundledVersion = $bundledMeta[$identifier]['version'] ?? null;
                    }
                    $latestVersion = $bundledVersion ?? $fileVersion;
                }

                // 설치된 모듈은 DB row 의 다국어 컬럼 우선 (applyExtensionManifests 가 ja 등 주입).
                $nameJson = $record->name ?: $module->getName();
                $descriptionJson = $record->description ?: $module->getDescription();
                $installedModules[$name] = [
                    'identifier' => $identifier,
                    'vendor' => $module->getVendor(),
                    'name' => $this->getLocalizedValue($nameJson, $locale),
                    'version' => $record->version,
                    'description' => $this->getLocalizedValue($descriptionJson, $locale),
                    'dependencies' => $this->enrichDependencies($module->getDependencies()),
                    'status' => $record->status,
                    'update_available' => $updateAvailable,
                    'latest_version' => $latestVersion,
                    'file_version' => $fileVersion,
                    'update_source' => $record->update_source ?? null,
                    'github_url' => $module->getGithubUrl(),
                    'github_changelog_url' => $record->github_changelog_url ?? null,
                    'hidden' => $module->isHidden(),
                    'assets' => $assets,
                ];
            }
        }

        return $installedModules;
    }

    /**
     * 활성화된 모든 모듈의 커스텀 메뉴를 수집하여 반환합니다.
     *
     * @return array 모듈별 커스텀 메뉴 배열
     *               [
     *               'module-identifier' => [
     *               'module_name' => '모듈명',
     *               'menus' => [...]
     *               ],
     *               ]
     */
    public function getCustomMenusFromModules(): array
    {
        $result = [];
        $activeModules = $this->getActiveModules();
        $locale = app()->getLocale();

        foreach ($activeModules as $name => $module) {
            // getCustomMenus 메서드가 있는지 확인
            if (! method_exists($module, 'getCustomMenus')) {
                continue;
            }

            $customMenus = $module->getCustomMenus();

            // 빈 메뉴는 건너뜀
            if (empty($customMenus)) {
                continue;
            }

            $identifier = $module->getIdentifier();

            $result[$identifier] = [
                'module_name' => $this->getLocalizedValue($module->getName(), $locale),
                'menus' => $this->localizeMenus($customMenus, $locale),
            ];
        }

        return $result;
    }

    /**
     * 메뉴 배열의 다국어 필드를 현재 로케일 값으로 변환합니다.
     *
     * @param  array  $menus  메뉴 배열
     * @param  string  $locale  로케일
     * @return array 변환된 메뉴 배열
     */
    protected function localizeMenus(array $menus, string $locale): array
    {
        $result = [];

        foreach ($menus as $menu) {
            $localizedMenu = $menu;

            // name 필드 로케일 변환
            if (isset($menu['name'])) {
                $localizedMenu['name'] = $this->getLocalizedValue($menu['name'], $locale);
            }

            // 하위 메뉴가 있으면 재귀적으로 처리
            if (isset($menu['children']) && is_array($menu['children'])) {
                $localizedMenu['children'] = $this->localizeMenus($menu['children'], $locale);
            }

            $result[] = $localizedMenu;
        }

        return $result;
    }

    /**
     * 모듈 상세 정보를 반환합니다.
     *
     * @param  string  $moduleName  모듈명
     * @return array|null 모듈 정보 배열 또는 null
     */
    public function getModuleInfo(string $moduleName): ?array
    {
        $module = $this->getModule($moduleName);

        // 활성 디렉토리에 없으면 pending/bundled 메타데이터에서 폴백
        if (! $module) {
            return $this->getModuleInfoFromMetadata($moduleName);
        }

        $identifier = $module->getIdentifier();
        $moduleRecord = $this->moduleRepository->findByIdentifier($identifier);
        $locale = app()->getLocale();
        $metadata = $module->getMetadata();
        $isInstalled = (bool) $moduleRecord;

        // 에셋 정보 수집 (API URL 경로 포함 - 활성화 시 프론트엔드에서 사용)
        $assets = null;
        if ($module->hasAssets()) {
            $builtPaths = $module->getBuiltAssetPaths();
            $loadingConfig = $module->getAssetLoadingConfig();

            if (isset($builtPaths['js']) || isset($builtPaths['css'])) {
                $assets = [
                    'js' => isset($builtPaths['js']) ? "/api/modules/assets/{$identifier}/".$builtPaths['js'] : null,
                    'css' => isset($builtPaths['css']) ? "/api/modules/assets/{$identifier}/".$builtPaths['css'] : null,
                    'priority' => $loadingConfig['priority'] ?? 100,
                ];
            }
        }

        // 다국어 필드는 DB row(applyExtensionManifests 가 활성 언어팩 ja 등을 주입) 우선,
        // 미설치 시 module.json 폴백.
        $nameJson = $moduleRecord?->name ?: $module->getName();
        $descriptionJson = $moduleRecord?->description ?: $module->getDescription();

        return [
            'identifier' => $identifier,
            'vendor' => $module->getVendor(),
            'name' => $this->getLocalizedValue($nameJson, $locale),
            'version' => $module->getVersion(),
            'description' => $this->getLocalizedValue($descriptionJson, $locale),
            'github_url' => $module->getGithubUrl(),
            'metadata' => $metadata,
            'requires_core' => $module->getRequiredCoreVersion(),
            'dependencies' => $this->enrichDependencies($module->getDependencies()),
            'permissions' => $module->getPermissions()['categories'] ?? [],
            'roles' => method_exists($module, 'getRoles') ? $module->getRoles() : [],
            'config' => $module->getConfig(),
            'admin_menus' => method_exists($module, 'getAdminMenus') ? $module->getAdminMenus() : [],
            'license' => $module->getLicense(),
            'layouts_count' => $this->countModuleLayoutFiles($moduleName),
            'status' => $moduleRecord ? $moduleRecord->status : 'uninstalled',
            'is_installed' => $isInstalled,
            'assets' => $assets,
            'created_at' => $moduleRecord?->created_at,
            'updated_at' => $moduleRecord?->updated_at,
        ];
    }

    protected function getModuleInfoFromMetadata(string $moduleName): ?array
    {
        // pending/bundled 메타데이터 확인
        $isPending = isset($this->pendingModules[$moduleName]);
        $isBundled = isset($this->bundledModules[$moduleName]);

        if (! $isPending && ! $isBundled) {
            return null;
        }

        $metadata = $isPending ? $this->pendingModules[$moduleName] : $this->bundledModules[$moduleName];
        $locale = app()->getLocale();

        // PHP 클래스 임시 로드 시도 (permissions, roles, hooks 등 상세 정보 획득)
        $module = $this->tryLoadModuleInstance($moduleName, $isPending);

        if ($module) {
            // 인스턴스 기반 상세 정보 반환 (활성 모듈과 동일 수준)
            return [
                'identifier' => $module->getIdentifier(),
                'vendor' => $module->getVendor(),
                'name' => $this->getLocalizedValue($module->getName(), $locale),
                'version' => $module->getVersion(),
                'description' => $this->getLocalizedValue($module->getDescription(), $locale),
                'github_url' => $module->getGithubUrl(),
                'metadata' => $module->getMetadata(),
                'requires_core' => $module->getRequiredCoreVersion(),
                'dependencies' => $this->enrichDependencies($module->getDependencies()),
                'permissions' => $module->getPermissions()['categories'] ?? [],
                'roles' => method_exists($module, 'getRoles') ? $module->getRoles() : [],
                'config' => $module->getConfig(),
                'admin_menus' => method_exists($module, 'getAdminMenus') ? $module->getAdminMenus() : [],
                'license' => $module->getLicense() ?? $metadata['license'] ?? null,
                'layouts_count' => $this->countModuleLayoutFiles($moduleName, $isPending ? '_pending' : '_bundled'),
                'status' => 'uninstalled',
                'is_installed' => false,
                'assets' => null,
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        // PHP 클래스 로드 실패 시 JSON 메타데이터 기반 폴백
        $subDir = $isPending ? '_pending' : '_bundled';

        return [
            'identifier' => $metadata['identifier'] ?? $moduleName,
            'vendor' => $metadata['vendor'] ?? '',
            'name' => $this->getLocalizedValue($metadata['name'] ?? $moduleName, $locale),
            'version' => $metadata['version'] ?? '0.0.0',
            'description' => $this->getLocalizedValue($metadata['description'] ?? '', $locale),
            'github_url' => $metadata['github_url'] ?? null,
            'metadata' => $metadata,
            'requires_core' => $metadata['g7_version'] ?? null,
            'dependencies' => $this->enrichDependencies($metadata['dependencies'] ?? []),
            'permissions' => $metadata['permissions']['categories'] ?? $metadata['permissions'] ?? [],
            'roles' => $metadata['roles'] ?? [],
            'config' => $metadata['config'] ?? [],
            'admin_menus' => $metadata['admin_menus'] ?? [],
            'license' => $metadata['license'] ?? null,
            'layouts_count' => $this->countModuleLayoutFiles($moduleName, $subDir),
            'status' => 'uninstalled',
            'is_installed' => false,
            'assets' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    /**
     * pending/bundled 디렉토리에서 모듈 PHP 클래스를 임시 로드합니다.
     *
     * 상세 정보 조회 시에만 사용되며, 주 배열($this->modules)에는 등록하지 않습니다.
     *
     * @param  string  $moduleName  모듈명
     * @param  bool  $isPending  pending 디렉토리 여부
     * @return ModuleInterface|null 모듈 인스턴스 또는 null
     */
    protected function tryLoadModuleInstance(string $moduleName, bool $isPending): ?ModuleInterface
    {
        $subDir = $isPending ? '_pending' : '_bundled';
        $moduleFile = $this->modulesPath.'/'.$subDir.'/'.$moduleName.'/module.php';

        if (! File::exists($moduleFile)) {
            return null;
        }

        try {
            require_once $moduleFile;

            $namespace = $this->convertDirectoryToNamespace($moduleName);
            $moduleClass = "Modules\\{$namespace}\\Module";

            if (class_exists($moduleClass)) {
                $module = new $moduleClass;
                if ($module instanceof ModuleInterface) {
                    return $module;
                }
            }
        } catch (\Exception $e) {
            Log::debug("Failed to load bundled module instance for {$moduleName}: ".$e->getMessage());
        }

        return null;
    }

    /**
     * 다국어 값 추출 헬퍼 메서드
     *
     * @param  mixed  $value  추출할 값 (문자열 또는 배열)
     * @param  string|null  $locale  로케일 (null이면 현재 로케일)
     * @return string 추출된 값
     */
    protected function getLocalizedValue($value, ?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        // 문자열인 경우 그대로 반환
        if (is_string($value)) {
            return $value;
        }

        // 배열인 경우 로케일에 맞는 값 반환
        if (is_array($value)) {
            // 요청 언어 → fallback 언어 → 첫 번째 값 → 빈 문자열
            return $value[$locale]
                ?? $value[config('app.fallback_locale')]
                ?? (! empty($value) ? array_values($value)[0] : '')
                ?? '';
        }

        return '';
    }

    /**
     * 문자열을 다국어 배열로 변환 (역호환성)
     *
     * @param  mixed  $value  변환할 값 (문자열 또는 배열)
     * @return array 다국어 배열
     */
    protected function convertToMultilingual($value): array
    {
        // 이미 배열인 경우 그대로 반환
        if (is_array($value)) {
            return $value;
        }

        // 문자열인 경우 모든 지원 언어로 변환
        if (is_string($value)) {
            $locales = config('app.translatable_locales', ['ko', 'en']);
            $result = [];
            foreach ($locales as $locale) {
                $result[$locale] = $value;
            }

            return $result;
        }

        // 그 외의 경우 빈 배열
        $locales = config('app.translatable_locales', ['ko', 'en']);
        $result = [];
        foreach ($locales as $locale) {
            $result[$locale] = '';
        }

        return $result;
    }

    protected function checkDependencies(ModuleInterface $module): void
    {
        $dependencies = $module->getDependencies();
        $unmetDependencies = [];

        // 중첩 구조 ['modules' => [...], 'plugins' => [...]] 를 순회
        foreach ($this->iterateNestedDependencies($dependencies) as $identifier => $declaredType) {
            if (! $this->isDependencySatisfied($identifier, $declaredType)) {
                $unmetDependencies[] = __('modules.dependency_not_active', ['dependency' => $identifier]);
            }
        }

        if (! empty($unmetDependencies)) {
            throw new \Exception(implode("\n", $unmetDependencies));
        }
    }

    /**
     * 중첩 구조 의존성 배열을 순회하며 (identifier => declaredType) 를 yield 합니다.
     *
     * @param  array  $dependencies  ['modules' => [...], 'plugins' => [...]] 형식
     * @return \Generator<string, string>  identifier => 'module'|'plugin'
     */
    private function iterateNestedDependencies(array $dependencies): \Generator
    {
        if (isset($dependencies['modules']) && is_array($dependencies['modules'])) {
            foreach ($dependencies['modules'] as $identifier => $versionConstraint) {
                yield $identifier => 'module';
            }
        }
        if (isset($dependencies['plugins']) && is_array($dependencies['plugins'])) {
            foreach ($dependencies['plugins'] as $identifier => $versionConstraint) {
                yield $identifier => 'plugin';
            }
        }
    }

    /**
     * 의존성이 활성 상태로 설치되어 있는지 확인합니다.
     *
     * declaredType 이 'module' 이면 modules 테이블만, 'plugin' 이면 plugins 테이블만 검색.
     *
     * @param  string  $identifier  확장 식별자
     * @param  string  $declaredType  'module' | 'plugin'
     * @return bool 활성 상태로 설치되어 있으면 true
     */
    private function isDependencySatisfied(string $identifier, string $declaredType): bool
    {
        if ($declaredType === 'module') {
            $record = $this->moduleRepository->findByIdentifier($identifier);
        } else {
            $record = app(PluginRepositoryInterface::class)->findByIdentifier($identifier);
        }

        if (! $record) {
            return false;
        }

        return $record->status === ExtensionStatus::Active->value;
    }

    /**
     * 의존성 배열을 상세 정보로 변환합니다.
     *
     * 단순 문자열 배열을 identifier, name, type 포함 배열로 변환
     *
     * @param  array  $dependencies  의존성 문자열 배열 (모듈/플러그인 identifier)
     * @return array 의존성 상세 정보 배열
     */
    protected function enrichDependencies(array $dependencies): array
    {
        return DependencyEnricher::enrich($dependencies);
    }

    /**
     * 모듈의 마이그레이션 파일들을 실행합니다.
     *
     * @param  ModuleInterface  $module  마이그레이션을 실행할 모듈 인스턴스
     */
    protected function runMigrations(ModuleInterface $module): void
    {
        $migrations = $module->getMigrations();
        $basePath = base_path();

        foreach ($migrations as $migration) {
            // 절대 경로를 상대 경로로 변환 (Laravel migrate는 상대 경로만 지원)
            $relativePath = str_replace($basePath.DIRECTORY_SEPARATOR, '', $migration);
            // Windows 경로 구분자를 Unix 스타일로 변환
            $relativePath = str_replace('\\', '/', $relativePath);

            Artisan::call('migrate', [
                '--path' => $relativePath,
                '--force' => true,
            ]);
        }
    }

    /**
     * 모듈의 마이그레이션을 롤백합니다 (테이블 삭제).
     *
     * 마이그레이션 디렉토리의 파일명을 기반으로 migrations 테이블에서 해당 마이그레이션을 찾아
     * 역순으로 down() 메서드를 실행하여 테이블을 삭제합니다.
     * FK 제약 조건으로 인한 DROP TABLE 실패를 방지하기 위해 롤백 전후로 FK 체크를 비활성화/활성화합니다.
     *
     * @param  ModuleInterface  $module  롤백할 모듈 인스턴스
     */
    protected function rollbackMigrations(ModuleInterface $module): void
    {
        $migrationPaths = $module->getMigrations();

        if (empty($migrationPaths)) {
            Log::info('롤백할 마이그레이션이 없습니다.', [
                'module' => $module->getIdentifier(),
            ]);

            return;
        }

        foreach ($migrationPaths as $migrationPath) {
            // 디렉토리 내 마이그레이션 파일 목록 (역순으로 정렬 - 최신 것부터 롤백)
            $migrationFiles = glob($migrationPath.'/*.php');

            if (empty($migrationFiles)) {
                Log::info('롤백할 마이그레이션 파일이 없습니다.', [
                    'module' => $module->getIdentifier(),
                    'path' => $migrationPath,
                ]);

                continue;
            }

            // 파일명 역순 정렬 (최신 마이그레이션부터 롤백)
            rsort($migrationFiles);

            foreach ($migrationFiles as $filePath) {
                try {
                    $this->rollbackSingleMigration($filePath, $module->getIdentifier());
                } catch (\Exception $e) {
                    // 개별 마이그레이션 실패 시 로그 기록 후 다음 마이그레이션 계속 진행
                    Log::error('모듈 개별 마이그레이션 롤백 실패', [
                        'module' => $module->getIdentifier(),
                        'migration' => basename($filePath),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('모듈 마이그레이션 롤백 완료', [
                'module' => $module->getIdentifier(),
                'path' => $migrationPath,
                'migration_count' => count($migrationFiles),
            ]);
        }
    }

    /**
     * 모듈 설치 시 defaults.json을 읽어 카테고리별 환경설정 파일을 생성합니다.
     *
     * 모듈은 플러그인과 달리 카테고리별로 환경설정 파일이 생성됩니다.
     * - 플러그인: storage/app/plugins/{identifier}/settings/setting.json (단일 파일)
     * - 모듈: storage/app/modules/{identifier}/settings/{category}.json (카테고리별 파일)
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     */
    protected function initializeModuleSettings(ModuleInterface $module): void
    {
        $defaultsPath = $module->getSettingsDefaultsPath();

        // defaults.json이 없으면 스킵
        if ($defaultsPath === null || ! File::exists($defaultsPath)) {
            Log::info('모듈 환경설정 defaults.json이 없습니다.', [
                'module' => $module->getIdentifier(),
            ]);

            return;
        }

        $identifier = $module->getIdentifier();
        $settingsDir = storage_path('app/modules/'.$identifier.'/settings');

        // 이미 환경설정 디렉토리가 있고 파일이 있으면 스킵 (재설치 시 덮어쓰기 방지)
        if (File::isDirectory($settingsDir) && count(File::files($settingsDir)) > 0) {
            Log::info('모듈 환경설정 파일이 이미 존재합니다.', [
                'module' => $identifier,
                'path' => $settingsDir,
            ]);

            return;
        }

        // defaults.json 파싱
        $content = File::get($defaultsPath);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('모듈 defaults.json 파싱 실패', [
                'module' => $identifier,
                'error' => json_last_error_msg(),
            ]);

            return;
        }

        // _meta.categories와 defaults 섹션 확인
        $categories = $data['_meta']['categories'] ?? [];
        $defaults = $data['defaults'] ?? [];

        if (empty($categories) || empty($defaults)) {
            Log::info('모듈 defaults.json에 카테고리 또는 기본값이 없습니다.', [
                'module' => $identifier,
            ]);

            return;
        }

        // 디렉토리 생성
        if (! File::isDirectory($settingsDir)) {
            File::makeDirectory($settingsDir, 0755, true);
        }

        // 카테고리별로 설정 파일 생성
        $createdFiles = [];
        foreach ($categories as $category) {
            if (! isset($defaults[$category])) {
                continue;
            }

            $categoryData = $defaults[$category];
            $filePath = $settingsDir.'/'.$category.'.json';

            $jsonContent = json_encode($categoryData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($filePath, $jsonContent);
            $createdFiles[] = $category.'.json';
        }

        Log::info('모듈 환경설정 파일 생성 완료', [
            'module' => $identifier,
            'path' => $settingsDir,
            'files' => $createdFiles,
        ]);
    }

    /**
     * 모듈의 동적 데이터를 정리합니다.
     *
     * 모듈이 런타임에 생성한 동적 테이블, 파일 등을 삭제합니다.
     * 마이그레이션 롤백 전에 호출되어 메타 테이블이 아직 존재하는 상태에서 실행됩니다.
     * 삭제 실패 시에도 언인스톨 프로세스는 계속 진행됩니다.
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     */
    protected function cleanupDynamicModuleData(ModuleInterface $module): void
    {
        try {
            $tables = $module->getDynamicTables();
        } catch (\Exception $e) {
            Log::error('모듈 동적 테이블 목록 조회 실패 (언인스톨 계속 진행)', [
                'module' => $module->getIdentifier(),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (empty($tables)) {
            return;
        }

        $droppedCount = 0;
        $failedCount = 0;

        foreach ($tables as $table) {
            try {
                Schema::dropIfExists($table);
                $droppedCount++;
            } catch (\Exception $e) {
                $failedCount++;
                Log::error('모듈 동적 테이블 삭제 실패 (계속 진행)', [
                    'module' => $module->getIdentifier(),
                    'table' => $table,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('모듈 동적 테이블 정리 완료', [
            'module' => $module->getIdentifier(),
            'total' => count($tables),
            'dropped' => $droppedCount,
            'failed' => $failedCount,
        ]);
    }

    /**
     * 모듈의 스토리지 디렉토리 전체를 삭제합니다.
     *
     * storage/app/modules/{identifier}/ 하위의 모든 카테고리(settings, attachments, images, cache, temp 등)를 삭제합니다.
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     */
    protected function deleteModuleStorage(ModuleInterface $module): void
    {
        $moduleStoragePath = storage_path('app/modules/'.$module->getIdentifier());

        if (! File::isDirectory($moduleStoragePath)) {
            Log::info('삭제할 모듈 스토리지 디렉토리가 없습니다.', [
                'module' => $module->getIdentifier(),
                'path' => $moduleStoragePath,
            ]);

            return;
        }

        try {
            File::deleteDirectory($moduleStoragePath);

            Log::info('모듈 스토리지 디렉토리 삭제 완료', [
                'module' => $module->getIdentifier(),
                'path' => $moduleStoragePath,
            ]);
        } catch (\Exception $e) {
            Log::error('모듈 스토리지 디렉토리 삭제 실패', [
                'module' => $module->getIdentifier(),
                'path' => $moduleStoragePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 모듈의 캐시를 전체 삭제합니다.
     *
     * 모듈 비활성화/삭제 시 해당 모듈의 격리된 캐시를 정리합니다.
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     */
    protected function flushModuleCache(ModuleInterface $module): void
    {
        try {
            $module->getCache()->flush();
        } catch (\Exception $e) {
            Log::warning("모듈 캐시 정리 실패: {$module->getIdentifier()}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 모듈 삭제 시 삭제될 데이터 정보를 조회합니다.
     *
     * 정적 테이블(마이그레이션) + 동적 테이블(getDynamicTables) 목록과 용량,
     * 스토리지 디렉토리 1-depth 목록과 용량, 확장 디렉토리 정보를 반환합니다.
     *
     * @param  string  $moduleName  모듈명
     * @return array|null 삭제 정보 배열 또는 null (모듈 없음)
     */
    public function getModuleUninstallInfo(string $moduleName): ?array
    {
        $module = $this->getModule($moduleName);
        if (! $module) {
            return null;
        }

        $identifier = $module->getIdentifier();

        // 1. 정적 테이블 목록 (마이그레이션 파일에서 추출)
        $staticTables = $this->extractTablesFromMigrations($module->getMigrations());

        // 2. 동적 테이블 목록
        $dynamicTables = [];
        try {
            $dynamicTables = $module->getDynamicTables();
        } catch (\Exception $e) {
            Log::warning('모듈 동적 테이블 목록 조회 실패 (삭제 정보 조회)', [
                'module' => $identifier,
                'error' => $e->getMessage(),
            ]);
        }

        // 3. 테이블 목록 병합 (중복 제거)
        $allTables = array_unique(array_merge($staticTables, $dynamicTables));

        // 4. 테이블별 용량 조회
        $tablesInfo = $this->getTablesSizeInfo($allTables);

        // 5. 스토리지 디렉토리 1-depth 용량 조회
        $storageInfo = $this->getStorageDirectoriesInfo(
            storage_path('app/modules/'.$identifier)
        );

        // 6. Composer vendor 디렉토리 정보 조회
        $vendorInfo = $this->getVendorDirectoryInfo('modules', $identifier);

        // 7. 확장 설치 디렉토리 정보 조회
        $extensionDirInfo = $this->getExtensionDirectoryInfo('modules', $identifier);

        $totalTableSize = array_sum(array_column($tablesInfo, 'size_bytes'));
        $totalStorageSize = array_sum(array_column($storageInfo, 'size_bytes'));

        // 8. 코어 공유 테이블에 적재된 이 모듈의 데이터 (deleteData=true 시 정리 대상)
        $sharedRecords = $this->resolveExtensionSharedRecords('module', $identifier);

        return [
            'tables' => $tablesInfo,
            'storage_directories' => $storageInfo,
            'vendor_directory' => $vendorInfo,
            'extension_directory' => $extensionDirInfo,
            'shared_records' => $sharedRecords,
            'total_table_size_bytes' => $totalTableSize,
            'total_table_size_formatted' => $this->formatBytes($totalTableSize),
            'total_storage_size_bytes' => $totalStorageSize,
            'total_storage_size_formatted' => $this->formatBytes($totalStorageSize),
        ];
    }


    /**
     * 단일 마이그레이션 파일을 롤백합니다.
     *
     * @param  string  $filePath  마이그레이션 파일 경로
     * @param  string  $moduleIdentifier  모듈 식별자 (로깅용)
     */
    protected function rollbackSingleMigration(string $filePath, string $moduleIdentifier): void
    {
        // 파일명에서 마이그레이션 이름 추출 (확장자 제외)
        $migrationName = pathinfo($filePath, PATHINFO_FILENAME);

        // migrations 테이블에 해당 마이그레이션이 있는지 확인
        $migrationRecord = DB::table('migrations')
            ->where('migration', $migrationName)
            ->first();

        if (! $migrationRecord) {
            Log::info('마이그레이션이 실행되지 않았거나 이미 롤백됨', [
                'module' => $moduleIdentifier,
                'migration' => $migrationName,
            ]);

            return;
        }

        try {
            // 마이그레이션 파일 로드 및 down() 실행
            $migration = require $filePath;

            if (method_exists($migration, 'down')) {
                // 개별 down() 실행 전후로 FK 제약 해제/복원 (DROP TABLE 시 FK 에러 방지)
                Schema::disableForeignKeyConstraints();
                try {
                    $migration->down();
                } finally {
                    Schema::enableForeignKeyConstraints();
                }

                // migrations 테이블에서 기록 삭제
                DB::table('migrations')
                    ->where('migration', $migrationName)
                    ->delete();

                Log::info('마이그레이션 롤백 성공', [
                    'module' => $moduleIdentifier,
                    'migration' => $migrationName,
                ]);
            } else {
                Log::warning('down() 메서드가 없는 마이그레이션', [
                    'module' => $moduleIdentifier,
                    'migration' => $migrationName,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('마이그레이션 롤백 실패', [
                'module' => $moduleIdentifier,
                'migration' => $migrationName,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * 모듈의 역할을 자동으로 생성/동기화합니다.
     *
     * ExtensionRoleSyncHelper를 통해 사용자 커스터마이징을 보존하면서
     * 역할을 안전하게 동기화합니다.
     *
     * @param  ModuleInterface  $module  역할을 생성할 모듈 인스턴스
     */
    protected function createModuleRoles(ModuleInterface $module): void
    {
        if (! method_exists($module, 'getRoles')) {
            return;
        }

        $roles = $module->getRoles();

        // 활성 언어팩이 module 의 roles 다국어 필드(name/description)에 추가 locale 을
        // 주입할 수 있도록 필터 훅 적용 (LanguagePackSeedInjector::injectExtensionRoles 결선).
        $roles = HookManager::applyFilters(
            "module.{$module->getIdentifier()}.roles.translations",
            $roles,
        );

        $syncHelper = $this->getRoleSyncHelper();

        foreach ($roles as $role) {
            $syncHelper->syncRole(
                identifier: $role['identifier'],
                newName: $role['name'],
                newDescription: $role['description'],
                extensionType: ExtensionOwnerType::Module,
                extensionIdentifier: $module->getIdentifier(),
                otherAttributes: ['is_active' => true],
            );
        }
    }

    /**
     * 모듈의 권한을 자동으로 생성/동기화합니다.
     *
     * 계층형 구조: 모듈(1레벨) → 카테고리(2레벨) → 개별 권한(3레벨)
     * ExtensionRoleSyncHelper를 통해 사용자 커스터마이징을 보존하면서
     * 권한을 안전하게 동기화하고, stale 권한을 정리합니다.
     *
     * @param  ModuleInterface  $module  권한을 생성할 모듈 인스턴스
     */
    protected function createModulePermissions(ModuleInterface $module): void
    {
        $permissionConfig = $module->getPermissions();
        $moduleIdentifier = $module->getIdentifier();

        // 활성 언어팩이 권한 트리(module/categories/permissions 의 name/description) 에 추가
        // locale 을 주입할 수 있도록 필터 훅 적용 (LanguagePackSeedInjector 결선).
        $permissionConfig = HookManager::applyFilters(
            "module.{$moduleIdentifier}.permissions.translations",
            $permissionConfig,
        );

        // 계층형 구조 여부 확인
        if (! isset($permissionConfig['categories'])) {
            return;
        }

        $syncHelper = $this->getRoleSyncHelper();
        $allIdentifiers = [];

        // 1레벨: 모듈 권한 노드 생성
        $permName = $permissionConfig['name'] ?? $module->getName();
        $permDesc = $permissionConfig['description'] ?? $module->getDescription();

        // 이름/설명이 문자열인 경우 배열로 변환 (역호환)
        if (is_string($permName)) {
            $permName = array_fill_keys(config('app.translatable_locales', ['ko', 'en']), $permName);
        }
        if (is_string($permDesc)) {
            $permDesc = array_fill_keys(config('app.translatable_locales', ['ko', 'en']), $permDesc);
        }

        $moduleNode = $syncHelper->syncPermission(
            identifier: $moduleIdentifier,
            newName: $permName,
            newDescription: $permDesc,
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: $moduleIdentifier,
            otherAttributes: [
                'type' => isset($permissionConfig['type'])
                    ? PermissionType::from($permissionConfig['type'])
                    : PermissionType::Admin,
                'order' => 100,
                'parent_id' => null,
            ],
        );
        $allIdentifiers[] = $moduleIdentifier;

        // 2레벨 & 3레벨: 카테고리별 권한 생성
        $categoryOrder = 1;
        foreach ($permissionConfig['categories'] as $categoryData) {
            $categoryIdentifier = $moduleIdentifier.'.'.$categoryData['identifier'];

            $catName = $categoryData['name'];
            $catDesc = $categoryData['description'];
            if (is_string($catName)) {
                $catName = array_fill_keys(config('app.translatable_locales', ['ko', 'en']), $catName);
            }
            if (is_string($catDesc)) {
                $catDesc = array_fill_keys(config('app.translatable_locales', ['ko', 'en']), $catDesc);
            }

            // 2레벨: 카테고리 권한 노드
            // 카테고리 type을 하위 권한의 type에서 자동 결정
            // 모든 하위가 user이면 카테고리도 user, 그 외 admin (기존 동작)
            // 카테고리에 명시적 type이 있으면 우선 사용
            $childTypes = collect($categoryData['permissions'] ?? [])
                ->map(fn ($p) => $p['type'] ?? 'admin')
                ->unique();

            $categoryType = ($childTypes->count() === 1 && $childTypes->first() === 'user')
                ? PermissionType::User
                : PermissionType::Admin;

            if (isset($categoryData['type'])) {
                $categoryType = PermissionType::from($categoryData['type']);
            }

            $categoryNode = $syncHelper->syncPermission(
                identifier: $categoryIdentifier,
                newName: $catName,
                newDescription: $catDesc,
                extensionType: ExtensionOwnerType::Module,
                extensionIdentifier: $moduleIdentifier,
                otherAttributes: [
                    'type' => $categoryType,
                    'order' => $categoryOrder++,
                    'parent_id' => $moduleNode->id,
                ],
            );
            $allIdentifiers[] = $categoryIdentifier;

            // 3레벨: 개별 권한
            $permissionOrder = 1;
            foreach ($categoryData['permissions'] as $permData) {
                $permIdentifier = $categoryIdentifier.'.'.$permData['action'];

                $pName = $permData['name'];
                $pDesc = $permData['description'];
                if (is_string($pName)) {
                    $pName = array_fill_keys(config('app.translatable_locales', ['ko', 'en']), $pName);
                }
                if (is_string($pDesc)) {
                    $pDesc = array_fill_keys(config('app.translatable_locales', ['ko', 'en']), $pDesc);
                }

                // 모듈 정의에서 type을 읽거나, 없으면 admin 기본값
                $permissionType = isset($permData['type'])
                    ? PermissionType::from($permData['type'])
                    : PermissionType::Admin;

                // create 액션은 스코프 체크 대상이 아님 (생성 시 기존 리소스 없음)
                $isScopeable = $permData['action'] !== 'create';

                $syncHelper->syncPermission(
                    identifier: $permIdentifier,
                    newName: $pName,
                    newDescription: $pDesc,
                    extensionType: ExtensionOwnerType::Module,
                    extensionIdentifier: $moduleIdentifier,
                    otherAttributes: [
                        'type' => $permissionType,
                        'order' => $permissionOrder++,
                        'parent_id' => $categoryNode->id,
                        'resource_route_key' => $isScopeable ? ($categoryData['resource_route_key'] ?? null) : null,
                        'owner_key' => $isScopeable ? ($categoryData['owner_key'] ?? null) : null,
                    ],
                );
                $allIdentifiers[] = $permIdentifier;
            }
        }

    }

    /**
     * 모듈의 권한을 지정된 역할에 할당합니다.
     *
     * ExtensionRoleSyncHelper를 통해 이전 할당과 비교하여
     * 제거된 역할 할당만 해제하고, 사용자 수동 추가 할당은 보존합니다.
     *
     * @param  ModuleInterface  $module  권한을 할당할 모듈 인스턴스
     */
    protected function assignPermissionsToRoles(ModuleInterface $module): void
    {
        $permissionConfig = $module->getPermissions();
        $moduleIdentifier = $module->getIdentifier();

        // 계층형 구조 여부 확인
        if (! isset($permissionConfig['categories'])) {
            return;
        }

        // 권한→역할 맵 및 전체 권한 식별자 수집
        $permissionRoleMap = [];
        $allPermIdentifiers = [];

        foreach ($permissionConfig['categories'] as $categoryData) {
            $categoryIdentifier = $moduleIdentifier.'.'.$categoryData['identifier'];

            foreach ($categoryData['permissions'] as $permData) {
                $permIdentifier = $categoryIdentifier.'.'.$permData['action'];
                $allPermIdentifiers[] = $permIdentifier;

                $definedRoles = $permData['roles'] ?? [];
                if (! empty($definedRoles)) {
                    // 역할 정규화: 문자열 → ['role' => ..., 'scope_type' => null]
                    $normalizedRoles = array_map(function ($role) {
                        if (is_string($role)) {
                            return ['role' => $role, 'scope_type' => null];
                        }

                        return [
                            'role' => $role['role'],
                            'scope_type' => $role['scope_type'] ?? null,
                        ];
                    }, $definedRoles);

                    $permissionRoleMap[$permIdentifier] = $normalizedRoles;
                }
            }
        }

        $syncHelper = $this->getRoleSyncHelper();
        $syncHelper->syncAllRoleAssignments(
            permissionRoleMap: $permissionRoleMap,
            allExtensionPermIdentifiers: $allPermIdentifiers,
        );
    }

    /**
     * 모듈의 관리자 메뉴를 동기화합니다.
     *
     * ExtensionMenuSyncHelper를 사용하여 사용자 커스터마이징을 보존하면서
     * 메뉴를 안전하게 동기화합니다.
     *
     * @param  ModuleInterface  $module  메뉴를 동기화할 모듈 인스턴스
     */
    protected function createModuleMenus(ModuleInterface $module): void
    {
        if (! method_exists($module, 'getAdminMenus')) {
            return;
        }

        $menus = $module->getAdminMenus();

        // 활성 언어팩이 module 의 admin_menus 다국어 필드(name 등)에 추가 locale 을
        // 주입할 수 있도록 필터 훅을 적용한다 (LanguagePackSeedInjector 가 결선).
        $menus = HookManager::applyFilters(
            "module.{$module->getIdentifier()}.admin_menus.translations",
            $menus,
        );

        $helper = $this->getMenuSyncHelper();

        foreach ($menus as $menuData) {
            $helper->syncMenuRecursive(
                $menuData,
                ExtensionOwnerType::Module,
                $module->getIdentifier(),
            );
        }

    }

    /**
     * 모듈이 선언한 모든 선언형 산출물을 DB 에 동기화합니다.
     *
     * 동기화 대상 (모듈 manifest 가 정의하는 모든 declarative 영역):
     *   1. 역할 (`getRoles`)
     *   2. 권한 (`getPermissions`)
     *   3. 역할-권한 매핑 (`getRolePermissions`)
     *   4. 관리자 메뉴 (`getAdminMenus` / `getMenus`)
     *   5. 위 4종에서 stale 항목 제거 (현재 선언에 없는 기존 레코드)
     *   6. IDV 정책 (`getIdentityPolicies`)
     *   7. IDV 메시지 정의/템플릿 (`getIdentityMessages`)
     *   8. 알림 정의/템플릿 (`getNotificationDefinitions`)
     *
     * `installModule` / `updateModule` 트랜잭션 내부에서 호출되어 모듈 디스크 상태와 DB 를
     * 정합 상태로 유지한다. 외부 호출 진입점으로도 노출되어 코어 업그레이드 사후 보정
     * (`Upgrade_7_0_0_beta_4` 등) 이나 운영자 수동 재시드 도구가 사용 가능.
     *
     * 각 sync 메서드는 helper 내부의 user_overrides 보존 패턴을 따르므로 정상 환경 재호출
     * 무해 (멱등).
     *
     * @param  ModuleInterface  $module  대상 모듈 인스턴스
     */
    public function syncDeclarativeArtifacts(ModuleInterface $module): void
    {
        $this->createModuleRoles($module);
        $this->createModulePermissions($module);
        $this->assignPermissionsToRoles($module);
        $this->createModuleMenus($module);
        $this->cleanupStaleModuleEntries($module);
        $this->syncModuleIdentityPolicies($module);
        $this->syncModuleIdentityMessages($module);
        $this->syncModuleNotificationDefinitions($module);
    }

    /**
     * 활성 모듈 전체를 _bundled 디렉토리에서 fresh-load 하여 declarative sync 를 일괄 호출.
     *
     * 코어 업그레이드 transition 사후 보정용. 이전 코어 버전(예: beta.3)의 활성 dir 에는
     * NEW declaration 인프라(IDV/메시지/알림 등) 가 아직 도입되지 않았으므로 활성 dir
     * fresh-load 는 declaration count 0 을 반환해 시드가 발동하지 않는다. 본 메서드는
     * 새 코어 버전이 _bundled 로 출하한 NEW 코드를 기준으로 declaration 을 재시드해
     * 인프라 초기화 결함을 차단한다.
     *
     * 신규 인프라 도입 시점의 활성 dir 에는 사용자가 거부할 수 있는 OLD declaration 자체가
     * 없으므로 본 보정은 사용자 의지(전역 yes/no/strategy) 와 양립한다. 미래 release 에서
     * 사용자가 모듈 업데이트를 거부한 케이스는 정상 흐름(ExecuteBundledUpdatesCommand →
     * updateModule → syncDeclarativeArtifacts) 이 자동 처리하므로 본 보정의 추가 호출은
     * 무해 (멱등) 하다.
     *
     * _bundled 에 module.php 가 부재한 외부 설치 모듈(GitHub 직접 설치 등) 은 skip.
     *
     * @return array{synced: array<int, string>, skipped: array<int, string>, failed: array<string, string>}
     *
     * @since 7.0.0-beta.4
     */
    public function resyncAllActiveDeclarativeArtifacts(): array
    {
        $synced = [];
        $skipped = [];
        $failed = [];

        foreach (self::getActiveModuleIdentifiers() as $identifier) {
            $moduleDir = $this->bundledModulesPath.DIRECTORY_SEPARATOR.$identifier;
            $moduleFile = $moduleDir.DIRECTORY_SEPARATOR.'module.php';

            // _bundled 에 진입점이 없는 외부 설치 모듈(GitHub 등) 은 skip.
            if (! File::exists($moduleFile)) {
                $skipped[] = $identifier;

                continue;
            }

            try {
                $namespace = $this->convertDirectoryToNamespace($identifier);
                $moduleClass = "Modules\\{$namespace}\\Module";

                if (class_exists($moduleClass, false)) {
                    $module = $this->evalFreshModule($moduleFile, $moduleClass, $moduleDir);
                } else {
                    require_once $moduleFile;
                    $module = class_exists($moduleClass) ? new $moduleClass : null;
                }

                if (! $module instanceof ModuleInterface) {
                    $failed[$identifier] = 'fresh-load 실패 (클래스 미생성)';

                    continue;
                }

                $this->syncDeclarativeArtifacts($module);
                $synced[] = $identifier;
            } catch (\Throwable $e) {
                $failed[$identifier] = $e->getMessage();
                Log::channel('upgrade')->warning('[resyncAllActiveDeclarativeArtifacts] sync 실패', [
                    'module' => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['synced' => $synced, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * 모듈이 선언한 IDV 정책을 `identity_policies` 테이블에 동기화합니다.
     *
     * `AbstractModule::getIdentityPolicies()` 결과를 순회하며
     * `IdentityPolicySyncHelper::syncPolicy()` 로 upsert 하고,
     * 현재 선언에 없는 기존 정책은 `cleanupStalePolicies()` 로 제거합니다.
     *
     * 운영자가 관리자 UI 에서 수정한 필드(`enabled` / `grace_minutes` / `provider_id` / `fail_mode`)
     * 는 `user_overrides` JSON 으로 보존됩니다.
     *
     * @param  ModuleInterface  $module  IDV 정책을 동기화할 모듈 인스턴스
     */
    protected function syncModuleIdentityPolicies(ModuleInterface $module): void
    {
        if (! method_exists($module, 'getIdentityPolicies')) {
            return;
        }

        if (! Schema::hasTable('identity_policies')) {
            return; // 마이그레이션 미실행 환경 (예: 초기 설치) 보호
        }

        $policies = $module->getIdentityPolicies();

        // 데이터 손실 방어막 — declaration 이 빈 배열인 경우 cleanup 호출 자체를 차단.
        // declaration 은 모듈 코드 계약(getIdentityPolicies) 의 결과이므로 빈 배열 = "이 모듈은
        // IDV 정책을 사용하지 않음". 이 경우 첫 install 시점부터 DB row 가 없을 것이라는 전제가
        // 성립한다. DB 에 기존 row 가 존재하면 declaration 이 환경 결함(spawn 자식 PSR-4 stale,
        // fresh-load 의존성 누락, trait 부분 로드 등) 으로 빈 배열을 반환했을 가능성이 높으므로,
        // cleanup 을 건너뛰고 warning 로그만 남긴다 (silent 데이터 손실 차단).
        if (empty($policies)) {
            $existingCount = DB::table('identity_policies')
                ->where('source_type', 'module')
                ->where('source_identifier', $module->getIdentifier())
                ->count();
            if ($existingCount > 0) {
                Log::warning('IDV 정책 cleanup 차단 — declaration 빈 배열인데 DB row 존재 (데이터 손실 방어)', [
                    'module' => $module->getIdentifier(),
                ]);
            }

            return;
        }

        $helper = app(IdentityPolicySyncHelper::class);
        $definedKeys = [];

        foreach ($policies as $data) {
            $data['source_type'] = 'module';
            $data['source_identifier'] = $module->getIdentifier();

            $helper->syncPolicy($data);
            $definedKeys[] = $data['key'];
        }

        $helper->cleanupStalePolicies('module', $module->getIdentifier(), $definedKeys);
    }

    /**
     * 모듈이 선언한 IDV 메시지 정의/템플릿을 동기화합니다.
     *
     * `AbstractModule::getIdentityMessages()` 결과를 순회하며
     * `IdentityMessageSyncHelper::syncDefinition()` + `syncTemplate()` 으로 upsert 하고,
     * 현재 선언에 없는 기존 정의는 `cleanupStaleDefinitions()` 로 제거합니다.
     *
     * `extension_type='module'`, `extension_identifier=$module->getIdentifier()` 는 자동 주입.
     * 운영자 user_overrides 보존은 helper 내부 trait 가 처리.
     *
     * @param  ModuleInterface  $module  IDV 메시지를 동기화할 모듈 인스턴스
     */
    protected function syncModuleIdentityMessages(ModuleInterface $module): void
    {
        if (! method_exists($module, 'getIdentityMessages')) {
            return;
        }

        if (! Schema::hasTable('identity_message_definitions')) {
            return; // 마이그레이션 미실행 환경 보호
        }

        $messages = $module->getIdentityMessages();

        // 언어팩 시스템: 활성 모듈 언어팩의 seed/identity_messages.json 으로 다국어 키 병합.
        $messages = HookManager::applyFilters(
            "seed.{$module->getIdentifier()}.identity_messages.translations",
            $messages
        );

        // 데이터 손실 방어막 — declaration 빈 배열 시 cleanup 호출 자체를 차단.
        // 자세한 배경은 syncModuleIdentityPolicies 의 동일 가드 주석 참조.
        if (empty($messages)) {
            if ($this->hasExistingIdentityMessageDefinitions('module', $module->getIdentifier())) {
                Log::warning('IDV 메시지 cleanup 차단 — declaration 빈 배열인데 DB row 존재 (데이터 손실 방어)', [
                    'module' => $module->getIdentifier(),
                ]);
            }

            return;
        }

        $helper = app(IdentityMessageSyncHelper::class);
        $definedScopes = [];

        foreach ($messages as $data) {
            $data['extension_type'] = 'module';
            $data['extension_identifier'] = $module->getIdentifier();

            $definition = $helper->syncDefinition($data);
            $definedScopes[] = [
                'provider_id' => $definition->provider_id,
                'scope_type' => $definition->scope_type->value,
                'scope_value' => $definition->scope_value,
            ];

            $definedChannels = [];
            foreach ($data['templates'] ?? [] as $template) {
                $helper->syncTemplate($definition->id, $template);
                $definedChannels[] = $template['channel'];
            }
            $helper->cleanupStaleTemplates($definition->id, $definedChannels);
        }

        $helper->cleanupStaleDefinitions('module', $module->getIdentifier(), $definedScopes);
    }

    /**
     * 모듈이 선언한 알림 정의/템플릿을 `notification_definitions` / `notification_templates` 에 동기화합니다.
     *
     * `AbstractModule::getNotificationDefinitions()` 결과를 순회하며
     * `NotificationSyncHelper::syncDefinition()` + `syncTemplate()` 으로 upsert 하고,
     * 현재 선언에 없는 기존 정의는 `cleanupStaleDefinitions()` 로 제거합니다.
     *
     * `extension_type='module'`, `extension_identifier=$module->getIdentifier()` 는 자동 주입.
     * 운영자 user_overrides 보존은 helper 내부 trait 가 처리.
     *
     * @param  ModuleInterface  $module  알림 정의를 동기화할 모듈 인스턴스
     */
    protected function syncModuleNotificationDefinitions(ModuleInterface $module): void
    {
        if (! method_exists($module, 'getNotificationDefinitions')) {
            return;
        }

        if (! Schema::hasTable('notification_definitions')) {
            return; // 마이그레이션 미실행 환경 보호
        }

        $definitions = $module->getNotificationDefinitions();

        // 언어팩 시스템: 활성 모듈 언어팩의 seed/notifications.json 으로 다국어 키 병합.
        $definitions = HookManager::applyFilters(
            "seed.{$module->getIdentifier()}.notifications.translations",
            $definitions
        );

        // 데이터 손실 방어막 — declaration 빈 배열 시 cleanup 호출 자체를 차단.
        // 자세한 배경은 syncModuleIdentityPolicies 의 동일 가드 주석 참조.
        if (empty($definitions)) {
            if ($this->hasExistingNotificationDefinitions('module', $module->getIdentifier())) {
                Log::warning('알림 정의 cleanup 차단 — declaration 빈 배열인데 DB row 존재 (데이터 손실 방어)', [
                    'module' => $module->getIdentifier(),
                ]);
            }

            return;
        }

        $helper = app(NotificationSyncHelper::class);
        $definedTypes = [];

        foreach ($definitions as $data) {
            $data['extension_type'] = 'module';
            $data['extension_identifier'] = $module->getIdentifier();

            $definition = $helper->syncDefinition($data);
            $definedTypes[] = $definition->type;

            $definedChannels = [];
            foreach ($data['templates'] ?? [] as $template) {
                $helper->syncTemplate($definition->id, $template);
                $definedChannels[] = $template['channel'];
            }
            $helper->cleanupStaleTemplates($definition->id, $definedChannels);
        }

        $helper->cleanupStaleDefinitions('module', $module->getIdentifier(), $definedTypes);
    }

    /**
     * 해당 source 가 기존에 등록한 IDV 정책이 있는지 확인합니다.
     *
     * @param  string  $sourceType
     * @param  string  $sourceIdentifier
     * @return bool
     */
    protected function hasExistingIdentityPolicies(string $sourceType, string $sourceIdentifier): bool
    {
        try {
            return DB::table('identity_policies')
                ->where('source_type', $sourceType)
                ->where('source_identifier', $sourceIdentifier)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 해당 source 가 기존에 등록한 IDV 메시지 정의가 있는지 확인합니다 (데이터 손실 방어용).
     */
    protected function hasExistingIdentityMessageDefinitions(string $extensionType, string $extensionIdentifier): bool
    {
        try {
            return DB::table('identity_message_definitions')
                ->where('extension_type', $extensionType)
                ->where('extension_identifier', $extensionIdentifier)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 해당 source 가 기존에 등록한 알림 정의가 있는지 확인합니다 (데이터 손실 방어용).
     */
    protected function hasExistingNotificationDefinitions(string $extensionType, string $extensionIdentifier): bool
    {
        try {
            return DB::table('notification_definitions')
                ->where('extension_type', $extensionType)
                ->where('extension_identifier', $extensionIdentifier)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 해당 모듈이 기존에 등록한 메뉴가 있는지 확인합니다 (데이터 손실 방어용).
     */
    protected function hasExistingModuleMenus(string $moduleIdentifier): bool
    {
        try {
            return DB::table('menus')
                ->where('extension_type', ExtensionOwnerType::Module->value)
                ->where('extension_identifier', $moduleIdentifier)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 모듈 정의 기준으로 stale 권한·메뉴·역할을 정리합니다 (완전 동기화 원칙).
     *
     * `$module` 에서 현재 정의된 식별자/slug 를 수집하여 helper 의 `cleanupStale*` 호출.
     * user_overrides 보존 및 `users.role_id` 참조 역할 삭제 차단은 helper 가 담당.
     *
     * @param  ModuleInterface  $module
     * @return void
     */
    protected function cleanupStaleModuleEntries(ModuleInterface $module): void
    {
        $roleSyncHelper = $this->getRoleSyncHelper();
        $menuSyncHelper = $this->getMenuSyncHelper();

        // 1. 권한 stale 정리 — 모듈·카테고리·leaf 전체 식별자 수집
        //
        // createModulePermissions() 의 저장 포맷과 반드시 동일해야 한다:
        //   - 모듈:   {moduleIdentifier}              예) sirsoft-board
        //   - 카테고리: {moduleIdentifier}.{category.identifier}           예) sirsoft-board.boards
        //   - 권한:    {moduleIdentifier}.{category.identifier}.{permission.action}  예) sirsoft-board.boards.view
        //
        // 모듈 정의에서 개별 권한은 'identifier' 필드가 아닌 'action' 필드를 사용한다.
        //
        // 동적 권한(예: BoardPermissionService 가 게시판 생성 시 runtime 삽입) 은
        // AbstractModule::getDynamicPermissionIdentifiers() 를 통해 수집 → 보존.
        $permissionConfig = $module->getPermissions();
        if (isset($permissionConfig['categories'])) {
            $moduleIdentifier = $module->getIdentifier();
            $expectedPermIds = [$moduleIdentifier];
            foreach ($permissionConfig['categories'] as $cat) {
                $categoryIdentifier = $moduleIdentifier.'.'.($cat['identifier'] ?? '');
                if (! empty($cat['identifier'])) {
                    $expectedPermIds[] = $categoryIdentifier;
                }
                foreach ($cat['permissions'] ?? [] as $p) {
                    if (isset($p['action'])) {
                        $expectedPermIds[] = $categoryIdentifier.'.'.$p['action'];
                    }
                }
            }
            if (method_exists($module, 'getDynamicPermissionIdentifiers')) {
                $expectedPermIds = array_merge($expectedPermIds, $module->getDynamicPermissionIdentifiers());
            }
            $roleSyncHelper->cleanupStalePermissions(
                ExtensionOwnerType::Module,
                $module->getIdentifier(),
                $expectedPermIds,
            );
        }

        // 2. 메뉴 stale 정리 (정적 + 동적 slug 병합)
        // 데이터 손실 방어: declaration 이 빈 배열인데 DB 에 모듈 메뉴 row 가 존재하면 cleanup 차단.
        // declaration 빈 배열은 환경 결함(spawn 자식 PSR-4 stale, fresh-load 의존성 누락 등) 으로
        // 인한 silent 빈 반환일 가능성이 높다. 모듈이 진짜 메뉴를 비웠다면 첫 install 시점부터
        // DB row 가 없을 것이라는 전제가 성립. (역할/권한 cleanup 의 동일 가드와 일관)
        if (method_exists($module, 'getAdminMenus')) {
            $currentSlugs = $menuSyncHelper->collectSlugsRecursive($module->getAdminMenus());
            if (method_exists($module, 'getDynamicMenuSlugs')) {
                $currentSlugs = array_merge($currentSlugs, $module->getDynamicMenuSlugs());
            }
            if (! empty($currentSlugs)) {
                $menuSyncHelper->cleanupStaleMenus(
                    ExtensionOwnerType::Module,
                    $module->getIdentifier(),
                    $currentSlugs,
                );
            } elseif ($this->hasExistingModuleMenus($module->getIdentifier())) {
                Log::warning('모듈 메뉴 cleanup 차단 — declaration 빈 배열인데 DB row 존재 (데이터 손실 방어)', [
                    'module' => $module->getIdentifier(),
                ]);
            }
        }

        // 3. 역할 stale 정리 (정적 getRoles + 동적 역할 병합)
        // 정적·동적 어느 쪽이든 존재하면 cleanup 실행. 둘 다 비어있으면 skip (모든 역할 삭제 방지).
        if (method_exists($module, 'getRoles')) {
            $roles = $module->getRoles();
            $staticRoleIds = array_column($roles, 'identifier');
            $dynamicRoleIds = method_exists($module, 'getDynamicRoleIdentifiers')
                ? $module->getDynamicRoleIdentifiers()
                : [];
            if (! empty($staticRoleIds) || ! empty($dynamicRoleIds)) {
                $roleSyncHelper->cleanupStaleRoles(
                    ExtensionOwnerType::Module,
                    $module->getIdentifier(),
                    array_merge($staticRoleIds, $dynamicRoleIds),
                );
            }
        }
    }

    /**
     * 모듈의 시더를 실행합니다.
     *
     * getSeeders()가 비어있지 않으면 해당 목록만 순서대로 실행합니다.
     * 빈 배열이면 database/seeders/ 디렉토리의 모든 시더를 자동 검색하여 실행합니다. (역호환)
     *
     * @param  ModuleInterface  $module  시더를 실행할 모듈 인스턴스
     */
    protected function runModuleSeeders(ModuleInterface $module): void
    {
        // 모듈 디렉토리명 조회 (오토로드 등록 및 glob 폴백에 필요)
        $moduleDirName = null;
        foreach ($this->modules as $dirName => $moduleInstance) {
            if ($moduleInstance === $module) {
                $moduleDirName = $dirName;
                break;
            }
        }

        if (! $moduleDirName) {
            return;
        }

        // 설치 시점에는 autoload-extensions.php가 아직 갱신되지 않으므로
        // 모듈의 composer.json에서 PSR-4 매핑을 읽어 동적으로 등록
        ExtensionManager::registerExtensionAutoloadPaths('modules', $moduleDirName);

        // 모듈이 명시적으로 시더를 정의한 경우 해당 목록만 실행
        $definedSeeders = $module->getSeeders();

        if (! empty($definedSeeders)) {
            foreach ($definedSeeders as $seederClass) {
                if (class_exists($seederClass)) {
                    try {
                        Artisan::call('db:seed', [
                            '--class' => $seederClass,
                            '--force' => true,
                        ]);

                        Log::info("모듈 시더 실행 완료: {$seederClass}");
                    } catch (\Exception $e) {
                        Log::error("모듈 시더 실행 실패: {$seederClass}", [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            return;
        }

        // 역호환: getSeeders()가 빈 배열이면 기존 glob 방식으로 자동 검색
        $seederPath = base_path("modules/{$moduleDirName}/database/seeders");

        if (! File::exists($seederPath)) {
            return;
        }

        // vendor-module 형식을 네임스페이스로 변환
        $namespace = $this->convertDirectoryToNamespace($moduleDirName);

        // 시더 파일들을 찾아서 실행
        $seederFiles = File::glob($seederPath.'/*Seeder.php');

        foreach ($seederFiles as $seederFile) {
            $fileName = basename($seederFile, '.php');
            $seederClass = "Modules\\{$namespace}\\Database\\Seeders\\{$fileName}";

            // 시더 클래스가 존재하는지 확인
            if (class_exists($seederClass)) {
                try {
                    Artisan::call('db:seed', [
                        '--class' => $seederClass,
                        '--force' => true,
                    ]);

                    Log::info("모듈 시더 실행 완료: {$seederClass}");
                } catch (\Exception $e) {
                    Log::error("모듈 시더 실행 실패: {$seederClass}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * 모듈의 레이아웃 파일 개수를 파일 시스템에서 직접 셉니다.
     *
     * partial 파일(is_partial: true)은 제외합니다.
     *
     * @param  string  $moduleDirName  모듈 디렉토리명 (vendor-module 형식)
     * @param  string|null  $subDir  하위 디렉토리 (_bundled, _pending 등)
     * @return int 레이아웃 파일 개수
     */
    protected function countModuleLayoutFiles(string $moduleDirName, ?string $subDir = null): int
    {
        $basePath = $subDir
            ? "modules/{$subDir}/{$moduleDirName}/resources/layouts"
            : "modules/{$moduleDirName}/resources/layouts";
        $layoutsPath = base_path($basePath);

        if (! File::exists($layoutsPath)) {
            return 0;
        }

        $count = 0;
        $layoutFiles = $this->scanLayoutFilesRecursively($layoutsPath);

        foreach ($layoutFiles as $layoutFile) {
            try {
                $content = File::get($layoutFile);
                $layoutData = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }

                // is_partial이 true면 제외
                if (isset($layoutData['meta']['is_partial']) && $layoutData['meta']['is_partial'] === true) {
                    continue;
                }

                $count++;
            } catch (\Exception $e) {
                // 파일 읽기 실패 시 스킵
                continue;
            }
        }

        return $count;
    }

    /**
     * 모듈의 레이아웃 개수를 반환합니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return int 레이아웃 개수
     */
    public function getModuleLayoutsCount(string $moduleIdentifier): int
    {
        return $this->layoutRepository->countByModule($moduleIdentifier);
    }

    /**
     * 모듈의 레이아웃을 영구 삭제합니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return int 삭제된 레이아웃 개수
     */
    protected function deleteModuleLayouts(string $moduleIdentifier): int
    {
        try {
            // soft deleted 포함 모든 모듈 레이아웃 영구 삭제
            $deletedCount = $this->layoutRepository->forceDeleteByModule($moduleIdentifier);

            // 레이아웃 캐시 무효화
            $this->invalidateLayoutCache($moduleIdentifier);

            Log::info('모듈 레이아웃 영구 삭제 완료', [
                'module' => $moduleIdentifier,
                'deleted_count' => $deletedCount,
            ]);

            return $deletedCount;
        } catch (\Exception $e) {
            Log::error("모듈 레이아웃 삭제 실패: {$moduleIdentifier}", [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * 모듈 제거 시 관련 권한과 메뉴를 삭제합니다.
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     */
    protected function removeModulePermissionsAndMenus(ModuleInterface $module): void
    {
        $identifier = $module->getIdentifier();

        // 모듈 관련 권한에서 role 연결 해제 후 삭제
        $permissions = $this->permissionRepository->getByExtension(ExtensionOwnerType::Module, $identifier);
        foreach ($permissions as $permission) {
            // 먼저 role_permissions 테이블에서 연결 해제
            $permission->roles()->detach();
        }

        // 권한 삭제
        $this->permissionRepository->deleteByExtension(ExtensionOwnerType::Module, $identifier);

        // 모듈 관련 메뉴 삭제
        $this->menuRepository->deleteByExtension(ExtensionOwnerType::Module, $identifier);

        // 모듈 역할 삭제
        $this->removeModuleRoles($module);
    }

    /**
     * 모듈 제거 시 관련 역할을 삭제합니다.
     *
     * 조건:
     * - 모듈이 소유한 역할만 삭제 (extension_type='module')
     * - role에 부여된 permission이 하나도 없어야 삭제
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     */
    protected function removeModuleRoles(ModuleInterface $module): void
    {
        if (! method_exists($module, 'getRoles')) {
            return;
        }

        $roles = $module->getRoles();

        foreach ($roles as $roleData) {
            $role = $this->roleRepository->findExtensionRoleByIdentifier(
                $roleData['identifier'],
                ExtensionOwnerType::Module,
                $module->getIdentifier()
            );

            if ($role) {
                // role에 권한이 남아있는지 확인
                $permissionCount = $this->roleRepository->getPermissionCount($role);
                if ($permissionCount === 0) {
                    $this->roleRepository->delete($role);
                } else {
                    Log::warning('모듈 역할에 권한이 남아있어 삭제하지 않습니다.', [
                        'role' => $roleData['identifier'],
                        'remaining_permissions' => $permissionCount,
                    ]);
                }
            }
        }
    }

    /**
     * 모듈의 config 파일을 Laravel config 시스템에 로드합니다.
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     */
    protected function loadModuleConfig(ModuleInterface $module): void
    {
        if (! method_exists($module, 'getConfig')) {
            return;
        }

        $configs = $module->getConfig();

        foreach ($configs as $key => $path) {
            if (File::exists($path)) {
                try {
                    $configData = require $path;
                    Config::set($key, $configData);

                    Log::debug("모듈 config 로드 완료: {$key}", [
                        'module' => $module->getIdentifier(),
                        'path' => $path,
                    ]);
                } catch (\Exception $e) {
                    Log::error("모듈 config 로드 실패: {$key}", [
                        'module' => $module->getIdentifier(),
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                Log::warning("모듈 config 파일을 찾을 수 없음: {$key}", [
                    'module' => $module->getIdentifier(),
                    'path' => $path,
                ]);
            }
        }
    }

    /**
     * 모듈의 훅 리스너를 자동으로 등록합니다.
     *
     * 비활성화된 모듈의 훅 리스너는 등록하지 않습니다.
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     */
    protected function registerModuleHookListeners(ModuleInterface $module): void
    {
        // 모듈이 getHookListeners 메서드를 가지고 있는지 확인
        if (! method_exists($module, 'getHookListeners')) {
            return;
        }

        // 모듈 활성화 상태 확인 (비활성화된 모듈의 훅은 등록하지 않음)
        $activeIdentifiers = self::getActiveModuleIdentifiers();
        if (! in_array($module->getIdentifier(), $activeIdentifiers, true)) {
            return;
        }

        $listeners = $module->getHookListeners();

        foreach ($listeners as $listenerClass) {
            // 클래스가 존재하는지 확인
            if (! class_exists($listenerClass)) {
                Log::warning("훅 리스너 클래스를 찾을 수 없습니다: {$listenerClass}");

                continue;
            }

            // HookListenerInterface를 구현하는지 확인
            if (! in_array(HookListenerInterface::class, class_implements($listenerClass))) {
                Log::warning("훅 리스너가 HookListenerInterface를 구현하지 않습니다: {$listenerClass}");

                continue;
            }

            try {
                HookListenerRegistrar::register($listenerClass, $module->getIdentifier());
            } catch (\Exception $e) {
                Log::error('훅 리스너 등록 중 오류 발생', [
                    'listener' => $listenerClass,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    /**
     * 모듈의 브로드캐스트 채널을 등록합니다.
     *
     * 모듈의 getChannels() 메서드에서 정의한 채널을
     * Broadcast::channel()로 자동 등록합니다.
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     */
    protected function registerModuleChannels(ModuleInterface $module): void
    {
        if (! method_exists($module, 'getChannels')) {
            return;
        }

        $activeIdentifiers = self::getActiveModuleIdentifiers();
        if (! in_array($module->getIdentifier(), $activeIdentifiers, true)) {
            return;
        }

        $channels = $module->getChannels();

        foreach ($channels as $channelName => $config) {
            $permission = $config['permission'] ?? null;

            Broadcast::channel($channelName, function ($user, ...$params) use ($permission) {
                if ($permission) {
                    return $user->hasPermission($permission);
                }

                return true;
            });

            Log::info('모듈 브로드캐스트 채널 등록 완료', [
                'channel' => $channelName,
                'module' => $module->getIdentifier(),
                'permission' => $permission,
            ]);
        }
    }

    /**
     * GitHub에서 모듈의 최신 버전을 가져옵니다.
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     * @return string|null 최신 버전 또는 null
     */
    protected function fetchLatestVersion(ModuleInterface $module): ?string
    {
        $githubUrl = $module->getGithubUrl();

        if (! $githubUrl) {
            return null;
        }

        try {
            [$owner, $repo] = GithubHelper::parseUrl($githubUrl);
        } catch (\RuntimeException $e) {
            return null;
        }

        try {
            $token = (string) (config('app.update.github_token') ?? '');
            $result = GithubHelper::fetchLatestRelease($owner, $repo, $token);

            return $result['version'];
        } catch (\Exception $e) {
            Log::error('최신 버전 확인 중 오류 발생', [
                'module' => $module->getName(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * GitHub URL로부터 변경 내역 URL을 생성합니다.
     *
     * @param  string|null  $githubUrl  GitHub 저장소 URL
     * @return string|null 변경 내역 URL 또는 null
     */
    protected function buildChangelogUrl(?string $githubUrl): ?string
    {
        if (! $githubUrl) {
            return null;
        }

        // GitHub URL 정규화 (뒤의 .git 제거)
        $githubUrl = rtrim($githubUrl, '/');
        $githubUrl = preg_replace('/\.git$/', '', $githubUrl);

        return $githubUrl.'/releases';
    }

    /**
     * 모든 활성 모듈의 레이아웃을 활성화된 템플릿에 등록합니다.
     *
     * 새 템플릿 활성화 시 기존 활성 모듈의 레이아웃을 등록하기 위해 사용됩니다.
     * registerModuleLayouts()는 updateOrCreate를 사용하므로 기존 등록 중복은 안전합니다.
     *
     * @return int 등록된 레이아웃 총 개수
     */
    public function registerLayoutsForAllActiveModules(): int
    {
        $totalRegistered = 0;

        foreach ($this->getActiveModules() as $module) {
            $totalRegistered += $this->registerModuleLayouts($module->getIdentifier());
        }

        return $totalRegistered;
    }

    /**
     * 모듈의 레이아웃을 활성화된 admin/user 템플릿에 등록합니다.
     *
     * 레이아웃 파일의 디렉토리 위치에 따라 대상 템플릿 타입이 결정됩니다:
     * - layouts/admin/*.json → admin 타입 템플릿에 등록
     * - layouts/user/*.json → user 타입 템플릿에 등록
     * - layouts/*.json (루트) → 스킵 (경고 로그 출력)
     *
     * @param  string  $moduleName  모듈 디렉토리명 (vendor-module 형식)
     * @return int 등록된 레이아웃 개수
     *
     * @throws \Exception 레이아웃 검증 실패 시
     */
    protected function registerModuleLayouts(string $moduleName): int
    {
        $module = $this->getModule($moduleName);
        if (! $module) {
            return 0;
        }

        $layoutsPath = base_path("modules/{$moduleName}/resources/layouts");

        if (! File::exists($layoutsPath)) {
            Log::info("모듈에 레이아웃이 없습니다: {$moduleName}");

            return 0;
        }

        // 활성화된 admin/user 타입 템플릿 조회
        $adminTemplates = $this->templateRepository->getActiveByType('admin');
        $userTemplates = $this->templateRepository->getActiveByType('user');

        if ($adminTemplates->isEmpty() && $userTemplates->isEmpty()) {
            Log::warning("활성화된 admin/user 템플릿이 없어 모듈 레이아웃을 등록할 수 없습니다: {$moduleName}");

            return 0;
        }

        $identifier = $module->getIdentifier();

        // 레이아웃 검증 (등록 전 모든 레이아웃 파일 유효성 검사)
        // 공통 Trait 메서드 사용 (recursive=true: 모듈은 하위 디렉토리 전체 스캔)
        $validatedLayouts = $this->validateLayoutFiles($layoutsPath, $identifier, 'module', true);

        if (empty($validatedLayouts)) {
            Log::info("모듈에 등록할 레이아웃 파일이 없습니다: {$moduleName}");

            return 0;
        }

        $registeredCount = 0;

        // 검증 통과한 레이아웃만 등록
        foreach ($validatedLayouts as $validatedLayout) {
            try {
                $layoutFile = $validatedLayout['file'];
                $layoutData = $validatedLayout['data'];
                $baseLayoutName = $validatedLayout['layout_name'];

                // 대상 템플릿 타입 판별
                $targetType = $this->getLayoutTargetType($layoutsPath, $layoutFile);
                if ($targetType === null) {
                    continue;  // 루트 파일 스킵
                }

                // 모듈 식별자를 접두사로 추가하여 고유한 레이아웃 이름 생성
                // 예: admin_sample_index -> sirsoft-sample.admin_sample_index
                $layoutName = $identifier.'.'.$baseLayoutName;

                // 대상 타입에 해당하는 템플릿 선택
                $targetTemplates = ($targetType === 'user') ? $userTemplates : $adminTemplates;

                if ($targetTemplates->isEmpty()) {
                    Log::info("활성화된 {$targetType} 템플릿이 없어 레이아웃 등록 스킵: {$layoutName}");

                    continue;
                }

                // 해당 타입 템플릿에 레이아웃 등록
                foreach ($targetTemplates as $template) {
                    $this->registerLayoutToTemplate($template, $layoutName, $layoutData, $identifier);
                    $registeredCount++;
                }

                Log::info("모듈 레이아웃 등록 완료: {$layoutName}", [
                    'module' => $identifier,
                    'target_type' => $targetType,
                    'templates_count' => $targetTemplates->count(),
                ]);
            } catch (\Exception $e) {
                Log::error("모듈 레이아웃 등록 실패: {$layoutFile}", [
                    'module' => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 레이아웃 캐시 무효화
        $this->invalidateLayoutCache($identifier);

        Log::info('모듈 레이아웃 전체 등록 완료', [
            'module' => $identifier,
            'registered_count' => $registeredCount,
        ]);

        return $registeredCount;
    }

    /**
     * 레이아웃을 특정 템플릿에 등록합니다.
     *
     * @param  Template  $template  템플릿 모델
     * @param  string  $layoutName  레이아웃 이름
     * @param  array  $layoutData  레이아웃 데이터
     * @param  string  $moduleIdentifier  모듈 식별자
     */
    protected function registerLayoutToTemplate(Template $template, string $layoutName, array $layoutData, string $moduleIdentifier): void
    {
        // content 필드 구성 (TemplateManager와 동일하게 layoutData 전체 저장)
        $content = $layoutData;

        // DB에 레이아웃 등록 (updateOrCreate로 중복 방지)
        // original_content_hash/size: 파일 원본 기준. 이후 사용자가 UI에서
        // 수정하면 현재 content의 hash가 달라지므로 keep 전략에서 이를 감지한다.
        $this->layoutRepository->updateOrCreate(
            [
                'template_id' => $template->id,
                'name' => $layoutName,
            ],
            [
                'content' => $content,
                'extends' => $layoutData['extends'] ?? null,
                'source_type' => LayoutSourceType::Module,
                'source_identifier' => $moduleIdentifier,
                'original_content_hash' => $this->computeContentHash($content),
                'original_content_size' => $this->computeContentSize($content),
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]
        );
    }

    /**
     * 모듈 레이아웃 관련 캐시를 무효화합니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     */
    protected function invalidateLayoutCache(string $moduleIdentifier): void
    {
        $this->invalidateExtensionLayoutCache($moduleIdentifier, 'module');
    }

    /**
     * 모듈의 레이아웃을 파일에서 다시 읽어 DB에 갱신합니다.
     *
     * 활성화된 모듈의 레이아웃 파일을 다시 스캔하여
     * DB에 저장된 레이아웃을 최신 파일 내용으로 갱신합니다.
     * admin/user 디렉토리 위치에 따라 해당 타입 템플릿에 동기화합니다.
     *
     * @param  string  $moduleName  모듈명
     * @param  bool  $preserveModified  true 시 사용자가 UI에서 수정한 레이아웃은 덮어쓰지 않음
     *                                 (original_content_hash 와 현재 content hash 비교)
     * @return array{success: bool, layouts_refreshed: int} 갱신 결과 및 갱신된 레이아웃 개수
     *
     * @throws \Exception 모듈을 찾을 수 없거나 레이아웃 갱신 실패 시
     */
    public function refreshModuleLayouts(string $moduleName, bool $preserveModified = false): array
    {
        $module = $this->getModule($moduleName);

        if (! $module) {
            throw new \Exception(__('modules.errors.module_not_found', ['name' => $moduleName]));
        }

        // 모듈이 활성화 상태인지 확인
        $moduleRecord = $this->moduleRepository->findByIdentifier($moduleName);
        if (! $moduleRecord || $moduleRecord->status !== 'active') {
            throw new \Exception(__('modules.errors.module_not_active', ['name' => $moduleName]));
        }

        $identifier = $module->getIdentifier();
        $layoutsPath = base_path("modules/{$moduleName}/resources/layouts");

        // 활성화된 admin/user 타입 템플릿 조회
        $adminTemplates = $this->templateRepository->getActiveByType('admin');
        $userTemplates = $this->templateRepository->getActiveByType('user');

        if ($adminTemplates->isEmpty() && $userTemplates->isEmpty()) {
            Log::warning("활성화된 admin/user 템플릿이 없어 모듈 레이아웃을 갱신할 수 없습니다: {$moduleName}");

            return ['success' => true, 'layouts_refreshed' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0];
        }

        $stats = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0];

        // 레이아웃 파일이 없는 경우 - admin/user 모두 삭제 처리
        if (! File::exists($layoutsPath)) {
            foreach (['admin' => $adminTemplates, 'user' => $userTemplates] as $type => $templates) {
                foreach ($templates as $template) {
                    $existingLayouts = $this->layoutRepository->getByTemplateIdWithFilter(
                        $template->id,
                        'module',
                        $identifier
                    );
                    foreach ($existingLayouts as $layout) {
                        $layout->forceDelete();
                        $stats['deleted']++;
                    }
                }
            }

            if ($stats['deleted'] > 0) {
                $this->invalidateLayoutCache($identifier);
                $this->incrementExtensionCacheVersion();
                $this->clearAllTemplateRoutesCaches();
                $this->clearAllTemplateLanguageCaches();
                Log::info("모듈 레이아웃 삭제 완료 (파일 없음): {$moduleName}", ['deleted' => $stats['deleted']]);
            }

            return ['success' => true, 'layouts_refreshed' => 0, ...$stats];
        }

        // 레이아웃 검증 (등록 전 모든 레이아웃 파일 유효성 검사)
        $validatedLayouts = $this->validateLayoutFiles($layoutsPath, $identifier, 'module', true);

        // 레이아웃을 타입별로 그룹화
        $layoutsByType = ['admin' => [], 'user' => []];
        foreach ($validatedLayouts as $layout) {
            $targetType = $this->getLayoutTargetType($layoutsPath, $layout['file']);
            if ($targetType === null) {
                continue;  // 루트 파일 스킵
            }
            $layoutsByType[$targetType][] = $layout;
        }

        // 각 타입별로 해당 템플릿에 동기화
        foreach (['admin' => $adminTemplates, 'user' => $userTemplates] as $type => $templates) {
            if ($templates->isEmpty()) {
                continue;
            }

            $typeLayouts = $layoutsByType[$type];

            // 해당 타입의 파일 레이아웃 이름 목록
            $typeLayoutNames = collect($typeLayouts)->map(function ($layout) use ($identifier) {
                return $identifier.'.'.$layout['layout_name'];
            })->toArray();

            foreach ($templates as $template) {
                // DB에 있는 해당 모듈의 레이아웃 조회
                $existingLayouts = $this->layoutRepository->getByTemplateIdWithFilter(
                    $template->id,
                    'module',
                    $identifier
                )->keyBy('name');

                // 파일 기반 레이아웃 동기화
                foreach ($typeLayouts as $validatedLayout) {
                    try {
                        $baseLayoutName = $validatedLayout['layout_name'];
                        $layoutData = $validatedLayout['data'];
                        $layoutName = $identifier.'.'.$baseLayoutName;

                        $existingLayout = $existingLayouts->get($layoutName);

                        if ($existingLayout) {
                            // 기존 레이아웃이 있는 경우 - 내용 비교 후 업데이트
                            $existingContent = is_string($existingLayout->content)
                                ? json_decode($existingLayout->content, true)
                                : $existingLayout->content;

                            if ($existingContent !== $layoutData) {
                                // 사용자 수정 감지: preserveModified 시 original_content_hash 와 현재 hash 비교
                                if ($preserveModified) {
                                    $currentHash = $this->computeContentHash($existingContent);
                                    $originalHash = $existingLayout->original_content_hash;

                                    if ($originalHash && $currentHash !== $originalHash) {
                                        $stats['skipped'] = ($stats['skipped'] ?? 0) + 1;
                                        Log::info("모듈 레이아웃 보존 (사용자 수정): {$layoutName}", ['module' => $identifier]);

                                        continue;
                                    }
                                }

                                // 내용이 다르면 업데이트
                                $this->registerLayoutToTemplate($template, $layoutName, $layoutData, $identifier);
                                $stats['updated']++;
                            } else {
                                // 내용이 같으면 그대로
                                $stats['unchanged']++;
                            }
                        } else {
                            // DB에 없으면 새로 생성
                            $this->registerLayoutToTemplate($template, $layoutName, $layoutData, $identifier);
                            $stats['created']++;
                        }
                    } catch (\Exception $e) {
                        Log::error("모듈 레이아웃 동기화 실패: {$validatedLayout['file']}", [
                            'module' => $identifier,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // DB에만 있고 파일에 없는 레이아웃 삭제
                // 해당 타입의 레이아웃만 비교하여 삭제
                foreach ($existingLayouts as $layoutName => $layout) {
                    if (! in_array($layoutName, $typeLayoutNames)) {
                        $layout->forceDelete();
                        $stats['deleted']++;
                        Log::info("모듈 레이아웃 삭제: {$layoutName}", ['module' => $identifier]);
                    }
                }
            }
        }

        // 레이아웃 캐시 무효화
        $this->invalidateLayoutCache($identifier);

        $totalRefreshed = $stats['created'] + $stats['updated'];

        Log::info('모듈 레이아웃 동기화 완료', [
            'module' => $identifier,
            'created' => $stats['created'],
            'updated' => $stats['updated'],
            'deleted' => $stats['deleted'],
            'unchanged' => $stats['unchanged'],
        ]);

        // 레이아웃 확장(extension)은 모든 활성 템플릿에 적용될 수 있으므로
        // admin 템플릿뿐만 아니라 모든 활성 템플릿에 대해 갱신
        $allActiveTemplates = $this->templateRepository->getActive();
        $extensionStats = $this->refreshLayoutExtensions($module, $allActiveTemplates);

        // 레이아웃 또는 레이아웃 확장이 실제로 변경된 경우에만 캐시 버전 증가
        $extensionChanged = ($extensionStats['created'] ?? 0) > 0 || ($extensionStats['updated'] ?? 0) > 0;
        if ($totalRefreshed > 0 || $stats['deleted'] > 0 || $extensionChanged) {
            $this->incrementExtensionCacheVersion();

            // 레이아웃 변경 시 routes/language 캐시도 함께 무효화
            $this->clearAllTemplateRoutesCaches();
            $this->clearAllTemplateLanguageCaches();
        }

        return [
            'success' => true,
            'layouts_refreshed' => $totalRefreshed,
            'created' => $stats['created'],
            'updated' => $stats['updated'],
            'deleted' => $stats['deleted'],
            'unchanged' => $stats['unchanged'],
            'extensions_refreshed' => $extensionStats['refreshed'] ?? 0,
        ];
    }

    /**
     * 모듈의 레이아웃 확장을 파일에서 다시 읽어 DB에 갱신합니다.
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     * @param  Collection  $adminTemplates  admin 템플릿 컬렉션
     * @return array{refreshed: int, created: int, updated: int, deleted: int} 갱신 통계
     */
    protected function refreshLayoutExtensions(ModuleInterface $module, $adminTemplates): array
    {
        return $this->refreshExtensionLayoutExtensions($module, $adminTemplates, LayoutSourceType::Module);
    }

    /**
     * 모듈의 레이아웃 확장을 등록합니다.
     *
     * 모듈의 resources/extensions 디렉토리에서 JSON 파일을 읽어
     * 활성화된 모든 템플릿(admin, user)에 확장을 등록합니다.
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     */
    protected function registerLayoutExtensions(ModuleInterface $module): void
    {
        $extensionFiles = $module->getLayoutExtensions();

        if (empty($extensionFiles)) {
            return;
        }

        $identifier = $module->getIdentifier();

        // 활성화된 모든 템플릿 조회 (admin + user)
        $allTemplates = $this->templateRepository->getActive();

        if ($allTemplates->isEmpty()) {
            Log::warning("활성화된 템플릿이 없어 모듈 레이아웃 확장을 등록할 수 없습니다: {$identifier}");

            return;
        }

        foreach ($extensionFiles as $extensionFile) {
            try {
                $content = File::get($extensionFile);
                $extensionData = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error("레이아웃 확장 JSON 파싱 실패: {$extensionFile}", [
                        'module' => $identifier,
                        'error' => json_last_error_msg(),
                    ]);

                    continue;
                }

                // 모든 활성 템플릿에 확장 등록
                foreach ($allTemplates as $template) {
                    $this->layoutExtensionService->registerExtension(
                        $extensionData,
                        LayoutSourceType::Module,
                        $identifier,
                        $template->id
                    );
                }

                Log::info("모듈 레이아웃 확장 등록 완료: {$extensionFile}", [
                    'module' => $identifier,
                    'templates_count' => $allTemplates->count(),
                ]);
            } catch (\Exception $e) {
                Log::error("모듈 레이아웃 확장 등록 실패: {$extensionFile}", [
                    'module' => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 모듈의 레이아웃 확장을 복원합니다.
     *
     * 모듈 재활성화 시 soft delete된 확장을 복원합니다.
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     */
    protected function restoreLayoutExtensions(ModuleInterface $module): void
    {
        try {
            $restoredCount = $this->layoutExtensionService->restoreBySource(
                LayoutSourceType::Module,
                $module->getIdentifier()
            );

            if ($restoredCount > 0) {
                Log::info('모듈 레이아웃 확장 복원 완료', [
                    'module' => $module->getIdentifier(),
                    'restored_count' => $restoredCount,
                ]);
            }
        } catch (\Exception $e) {
            Log::error("모듈 레이아웃 확장 복원 실패: {$module->getIdentifier()}", [
                'error' => $e->getMessage(),
            ]);

            // 복원 실패가 모듈 활성화를 중단시키지 않음
        }
    }

    /**
     * 모듈의 레이아웃 확장을 제거합니다 (soft delete).
     *
     * 모듈 비활성화 시 해당 모듈의 확장을 soft delete합니다.
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     */
    protected function unregisterLayoutExtensions(ModuleInterface $module): void
    {
        try {
            $deletedCount = $this->layoutExtensionService->unregisterBySource(
                LayoutSourceType::Module,
                $module->getIdentifier()
            );

            if ($deletedCount > 0) {
                Log::info('모듈 레이아웃 확장 soft delete 완료', [
                    'module' => $module->getIdentifier(),
                    'deleted_count' => $deletedCount,
                ]);
            }
        } catch (\Exception $e) {
            Log::error("모듈 레이아웃 확장 soft delete 실패: {$module->getIdentifier()}", [
                'error' => $e->getMessage(),
            ]);

            // 확장 삭제 실패가 모듈 비활성화를 중단시키지 않음
        }
    }

    /**
     * 모듈의 레이아웃 확장을 영구 삭제합니다.
     *
     * 모듈 삭제(uninstall) 시 호출됩니다.
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     */
    protected function deleteLayoutExtensions(ModuleInterface $module): void
    {
        try {
            $deletedCount = $this->layoutExtensionService->forceDeleteBySource(
                LayoutSourceType::Module,
                $module->getIdentifier()
            );

            if ($deletedCount > 0) {
                Log::info('모듈 레이아웃 확장 영구 삭제 완료', [
                    'module' => $module->getIdentifier(),
                    'deleted_count' => $deletedCount,
                ]);
            }
        } catch (\Exception $e) {
            Log::error("모듈 레이아웃 확장 영구 삭제 실패: {$module->getIdentifier()}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * _pending 또는 _bundled에서 활성 디렉토리로 모듈을 복사합니다.
     *
     * _pending을 우선 확인하고, 없으면 _bundled를 확인합니다.
     * 이미 활성 디렉토리에 존재하면 아무 작업도 하지 않습니다.
     *
     * @param  string  $moduleName  모듈명 (identifier)
     * @param  \Closure|null  $onProgress  진행 콜백 (?string $step, string $message)
     */
    protected function copyFromPendingOrBundled(string $moduleName, ?\Closure $onProgress = null, bool $force = false): void
    {
        $activePath = $this->modulesPath.DIRECTORY_SEPARATOR.$moduleName;

        // 이미 활성 디렉토리에 존재하면 복사 불필요.
        // force=true 시: 활성 디렉토리가 불완전(manifest 누락 등)하거나 재복구 목적으로
        // _bundled/_pending 에서 원본을 덮어쓴다. copyToActive() 가 원자적 교체를 수행한다.
        if (! $force && File::isDirectory($activePath)) {
            return;
        }

        // _pending 우선 확인
        if (ExtensionPendingHelper::isPending($this->modulesPath, $moduleName)) {
            $sourcePath = ExtensionPendingHelper::getPendingPath($this->modulesPath, $moduleName);
            ExtensionPendingHelper::copyToActive($sourcePath, $activePath, $onProgress);
            Log::info('모듈을 _pending에서 활성 디렉토리로 복사', ['module' => $moduleName, 'force' => $force]);

            return;
        }

        // _bundled 확인
        if (ExtensionPendingHelper::isBundled($this->modulesPath, $moduleName)) {
            $sourcePath = ExtensionPendingHelper::getBundledPath($this->modulesPath, $moduleName);
            ExtensionPendingHelper::copyToActive($sourcePath, $activePath, $onProgress);
            Log::info('모듈을 _bundled에서 활성 디렉토리로 복사', ['module' => $moduleName, 'force' => $force]);
        }
    }

    /**
     * 단일 모듈을 다시 로드합니다 (활성 디렉토리에서).
     *
     * _pending/_bundled에서 복사된 후 클래스를 로드하여 $this->modules에 등록합니다.
     *
     * @param  string  $moduleName  모듈명 (identifier)
     */
    protected function reloadModule(string $moduleName): void
    {
        $moduleDir = $this->modulesPath.DIRECTORY_SEPARATOR.$moduleName;
        $moduleFile = $moduleDir.DIRECTORY_SEPARATOR.'module.php';

        if (! File::exists($moduleFile)) {
            return;
        }

        $namespace = $this->convertDirectoryToNamespace($moduleName);
        $moduleClass = "Modules\\{$namespace}\\Module";

        $module = null;

        if (class_exists($moduleClass, false)) {
            // 클래스가 이미 메모리에 있는 경우: 새 파일을 임시 클래스명으로 eval 로드
            // PHP는 동일 프로세스에서 클래스 재정의 불가 → 클래스명을 변경하여 우회
            $module = $this->evalFreshModule($moduleFile, $moduleClass, $moduleDir);
        } else {
            // 최초 로드 (클래스가 아직 없는 경우)
            require_once $moduleFile;

            if (class_exists($moduleClass)) {
                $module = new $moduleClass;
            }
        }

        if ($module instanceof ModuleInterface) {
            $this->modules[$moduleName] = $module;

            // 모듈 config 파일 로드
            $this->loadModuleConfig($module);

            // 훅 리스너 자동 등록
            $this->registerModuleHookListeners($module);

            // pending/bundled 목록에서 제거
            unset($this->pendingModules[$moduleName]);
            unset($this->bundledModules[$moduleName]);
        }
    }

    /**
     * 이미 로드된 Module 클래스를 새 파일에서 eval로 다시 로드합니다.
     *
     * PHP는 동일 프로세스에서 클래스를 재정의할 수 없으므로,
     * 새 파일의 클래스명을 임시로 변경하여 eval로 메모리에 로드합니다.
     * namespace는 유지하므로 use/extends/implements가 정상 작동합니다.
     *
     * @param  string  $moduleFile  module.php 파일 경로
     * @param  string  $moduleClass  원본 클래스명 (FQCN)
     * @param  string  $moduleDir  모듈 디렉토리 경로
     * @return ModuleInterface|null 새로 로드된 모듈 인스턴스
     */
    protected function evalFreshModule(string $moduleFile, string $moduleClass, string $moduleDir): ?ModuleInterface
    {
        $content = file_get_contents($moduleFile);
        if ($content === false) {
            return null;
        }

        $uid = '_fresh_'.uniqid();

        // 클래스명만 변경 (namespace 유지 → use/extends 정상 작동)
        $content = preg_replace('/\bclass\s+Module\b/', 'class Module'.$uid, $content);

        // PHP 여는 태그 제거 후 메모리에서 실행
        $content = preg_replace('/^<\?php\s*/', '', $content);
        eval($content);

        $namespace = substr($moduleClass, 0, strrpos($moduleClass, '\\'));
        $freshClass = $namespace.'\\Module'.$uid;

        if (! class_exists($freshClass)) {
            return null;
        }

        $module = new $freshClass;

        // modulePath 수동 설정 (eval 클래스는 ReflectionClass::getFileName()이 비정상)
        $ref = new \ReflectionClass(AbstractModule::class);
        $prop = $ref->getProperty('modulePath');
        $prop->setAccessible(true);
        $prop->setValue($module, $moduleDir);

        return $module instanceof ModuleInterface ? $module : null;
    }

    /**
     * 단일 모듈의 업데이트 가능 여부를 확인합니다.
     *
     * 일반 업데이트 우선순위 (GitHub 엄격 우선):
     *   1. GitHub URL 존재 + API 조회 성공 → GitHub 결과만 신뢰
     *      a. GitHub 버전 > 현재 → 'github' 소스 반환
     *      b. GitHub 버전 ≤ 현재 → "업데이트 없음" 즉시 반환 (bundled 폴백 없음)
     *   2. GitHub URL 없음 OR API 조회 실패 → _bundled 폴백 (안전망)
     *
     * --force 업데이트 우선순위는 resolveForceUpdateSource() 참조 (번들 우선).
     *
     * 참고: _pending 디렉토리는 install 경로에서만 사용되며 update 에서는 참조하지 않음.
     *
     * @param  string  $identifier  모듈 식별자
     * @return array{update_available: bool, update_source: string|null, latest_version: string|null, current_version: string|null}
     */
    public function checkModuleUpdate(string $identifier): array
    {
        $record = $this->moduleRepository->findByIdentifier($identifier);
        if (! $record) {
            return $this->buildModuleUpdateResponse(false, null, null, null, null);
        }

        $currentVersion = $record->version;
        $module = $this->getModule($identifier);
        $activeRequiredCoreVersion = $module ? $module->getRequiredCoreVersion() : null;

        // 1. GitHub URL이 있으면 GitHub에서 최신 버전 확인 (조회 성공 시 GitHub만 신뢰)
        if ($module && $module->getGithubUrl()) {
            try {
                $latestVersion = $this->fetchLatestVersion($module);
            } catch (\Throwable $e) {
                Log::warning('모듈 GitHub 버전 조회 실패', [
                    'module' => $identifier,
                    'url' => $module->getGithubUrl(),
                    'error' => $e->getMessage(),
                ]);
                $latestVersion = null;
            }

            if ($latestVersion !== null) {
                if (version_compare($latestVersion, $currentVersion, '>')) {
                    return $this->buildModuleUpdateResponse(
                        true, 'github', $latestVersion, $currentVersion, $activeRequiredCoreVersion
                    );
                }

                return $this->buildModuleUpdateResponse(
                    false, null, $currentVersion, $currentVersion, $activeRequiredCoreVersion
                );
            }

            Log::info('모듈 업데이트 확인: GitHub 조회 실패로 bundled 폴백', [
                'module' => $identifier,
            ]);
        }

        // 2. _bundled에서 업데이트 확인
        if (isset($this->bundledModules[$identifier])) {
            $bundledVersion = $this->bundledModules[$identifier]['version'] ?? null;
            $bundledRequired = $this->bundledModules[$identifier]['g7_version'] ?? $activeRequiredCoreVersion;
            if ($bundledVersion && version_compare($bundledVersion, $currentVersion, '>')) {
                return $this->buildModuleUpdateResponse(
                    true, 'bundled', $bundledVersion, $currentVersion, $bundledRequired
                );
            }
        } else {
            $bundledMeta = ExtensionPendingHelper::loadBundledExtensions($this->modulesPath, 'module.json');
            if (isset($bundledMeta[$identifier])) {
                $bundledVersion = $bundledMeta[$identifier]['version'] ?? null;
                $bundledRequired = $bundledMeta[$identifier]['g7_version'] ?? $activeRequiredCoreVersion;
                if ($bundledVersion && version_compare($bundledVersion, $currentVersion, '>')) {
                    return $this->buildModuleUpdateResponse(
                        true, 'bundled', $bundledVersion, $currentVersion, $bundledRequired
                    );
                }
            }
        }

        // 4. 업데이트 없음
        return $this->buildModuleUpdateResponse(
            false, null, $currentVersion, $currentVersion, $activeRequiredCoreVersion
        );
    }

    /**
     * checkModuleUpdate 응답 페이로드 빌더 (호환성 메타 부착).
     *
     * @param  bool  $updateAvailable  업데이트 가용 여부
     * @param  string|null  $updateSource  업데이트 소스 (github|bundled|null)
     * @param  string|null  $latestVersion  최신 버전
     * @param  string|null  $currentVersion  현재 설치된 버전
     * @param  string|null  $requiredCoreVersion  요구 코어 버전 제약
     * @return array{update_available: bool, update_source: ?string, latest_version: ?string, current_version: ?string, required_core_version: ?string, is_compatible: bool, current_core_version: string}
     */
    protected function buildModuleUpdateResponse(
        bool $updateAvailable,
        ?string $updateSource,
        ?string $latestVersion,
        ?string $currentVersion,
        ?string $requiredCoreVersion,
    ): array {
        return [
            'update_available' => $updateAvailable,
            'update_source' => $updateSource,
            'latest_version' => $latestVersion,
            'current_version' => $currentVersion,
            'required_core_version' => $requiredCoreVersion,
            'is_compatible' => CoreVersionChecker::isCompatible($requiredCoreVersion),
            'current_core_version' => CoreVersionChecker::getCoreVersion(),
        ];
    }

    /**
     * 설치된 모든 모듈의 업데이트를 확인하고 DB를 갱신합니다.
     *
     * @return array{updated_count: int, details: array}
     */
    public function checkAllModulesForUpdates(): array
    {
        $moduleRecords = $this->moduleRepository->getAllKeyedByIdentifier();
        $details = [];
        $updatedCount = 0;

        foreach ($moduleRecords as $identifier => $record) {
            $result = $this->checkModuleUpdate($identifier);

            // DB 갱신
            $updateData = [
                'update_available' => $result['update_available'],
                'latest_version' => $result['latest_version'],
                'update_source' => $result['update_source'],
                'updated_at' => now(),
            ];

            // GitHub 출처인 경우 changelog URL 갱신
            if ($result['update_source'] === 'github') {
                $module = $this->getModule($identifier);
                if ($module) {
                    $updateData['github_changelog_url'] = $this->buildChangelogUrl($module->getGithubUrl());
                }
            }

            $this->moduleRepository->updateByIdentifier($identifier, $updateData);

            if ($result['update_available']) {
                $updatedCount++;
                $details[] = [
                    'identifier' => $identifier,
                    'current_version' => $result['current_version'],
                    'latest_version' => $result['latest_version'],
                    'update_source' => $result['update_source'],
                ];
            }
        }

        return [
            'updated_count' => $updatedCount,
            'details' => $details,
        ];
    }

    /**
     * GitHub에서 모듈 업데이트를 다운로드하여 _pending 스테이징에 배치합니다.
     *
     * ExtensionManager의 공용 GitHub 다운로드 유틸리티를 사용하여
     * 코어 업데이트와 동일한 폴백 체인(ZipArchive → unzip)을 적용합니다.
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     * @param  string  $version  다운로드할 버전
     * @return string 스테이징 경로
     *
     * @throws \RuntimeException 다운로드 실패 시
     */
    protected function downloadModuleUpdate(ModuleInterface $module, string $version): string
    {
        $githubUrl = $module->getGithubUrl();
        if (! $githubUrl) {
            throw new \RuntimeException(__('modules.errors.github_url_invalid', ['module' => $module->getIdentifier()]));
        }

        if (! preg_match('#github\.com[/:]([^/]+)/([^/\.]+)#', $githubUrl, $matches)) {
            throw new \RuntimeException(__('modules.errors.invalid_github_url', ['module' => $module->getIdentifier()]));
        }

        $owner = $matches[1];
        $repo = $matches[2];

        // _pending 스테이징 경로 생성
        $stagingPath = ExtensionPendingHelper::createUpdateStagingPath($this->modulesPath, $module->getIdentifier());

        // 임시 디렉토리 (다운로드/추출용)
        $tempDir = storage_path('app/temp/module_update_'.uniqid());

        try {
            File::ensureDirectoryExists($tempDir);

            // GitHub에서 다운로드 및 추출 (코어와 동일한 폴백 체인)
            $extractedDir = $this->extensionManager->downloadAndExtractFromGitHub(
                $owner, $repo, $version, $tempDir, config('app.update.github_token') ?? ''
            );

            // 추출된 파일을 _pending 스테이징으로 복사
            ExtensionPendingHelper::stageForUpdate($extractedDir, $stagingPath);

            Log::info('모듈 업데이트 다운로드 및 스테이징 완료', [
                'module' => $module->getIdentifier(),
                'version' => $version,
                'staging_path' => $stagingPath,
            ]);

            return $stagingPath;

        } catch (\Exception $e) {
            ExtensionPendingHelper::cleanupStaging($stagingPath);
            throw $e;
        } finally {
            // 임시 파일 정리
            if (File::isDirectory($tempDir)) {
                File::deleteDirectory($tempDir);
            }
        }
    }

    /**
     * 버전별 업그레이드 스텝을 순차 실행합니다.
     *
     * $fromVersion 초과 ~ $toVersion 이하 버전의 스텝을 필터링하여 버전순으로 실행합니다.
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     * @param  string  $fromVersion  시작 버전 (이 버전 초과)
     * @param  string  $toVersion  목표 버전 (이 버전 이하)
     * @param  bool  $force  true 시 fromVersion == toVersion이면 해당 버전 스텝도 포함
     * @param  \Closure|null  $onStep  각 step 실행 직전에 호출되는 콜백 (인자: 버전 문자열)
     *
     * @throws \Exception 스텝 실행 실패 시
     */
    protected function runUpgradeSteps(ModuleInterface $module, string $fromVersion, string $toVersion, bool $force = false, ?\Closure $onStep = null): void
    {
        $allSteps = $module->upgrades();

        if (empty($allSteps)) {
            return;
        }

        // force + 동일 버전: 해당 버전의 스텝도 포함 (>= 비교)
        $sameVersion = version_compare($fromVersion, $toVersion, '==');

        // $fromVersion 초과 ~ $toVersion 이하 필터링
        $filteredSteps = [];
        foreach ($allSteps as $stepVersion => $step) {
            $included = $force && $sameVersion
                ? version_compare($stepVersion, $toVersion, '==')
                : version_compare($stepVersion, $fromVersion, '>') && version_compare($stepVersion, $toVersion, '<=');

            if ($included) {
                $filteredSteps[$stepVersion] = $step;
            }
        }

        if (empty($filteredSteps)) {
            return;
        }

        // 버전순 정렬
        uksort($filteredSteps, 'version_compare');

        $context = new UpgradeContext(
            fromVersion: $fromVersion,
            toVersion: $toVersion,
        );

        foreach ($filteredSteps as $stepVersion => $step) {
            $stepContext = $context->withCurrentStep($stepVersion);

            $onStep?->__invoke((string) $stepVersion);

            $stepContext->logger->info("Upgrading {$module->getIdentifier()} step {$stepVersion}...");

            if ($step instanceof UpgradeStepInterface) {
                $sinceVersion = ExtensionUpgradeGuardHelper::resolveSinceVersion(
                    $module->getRequiredCoreVersion(),
                    $module->getVersion(),
                );
                if ($sinceVersion !== null) {
                    ExtensionUpgradeGuardHelper::assertAbstractInheritance(
                        (string) $stepVersion,
                        $sinceVersion,
                        $step,
                        'module',
                        $module->getIdentifier(),
                    );
                }

                $step->run($stepContext);
            } elseif (is_callable($step)) {
                $step($stepContext);
            }

            $stepContext->logger->info("Completed {$module->getIdentifier()} step {$stepVersion}");
        }
    }

    /**
     * 모듈의 레이아웃 중 사용자가 수정한 것이 있는지 확인합니다.
     *
     * original_content_hash 와 현재 DB content 의 hash 를 비교하여
     * 관리자 UI 에서 레이아웃이 수정된 적이 있는지 감지합니다.
     * 이 메서드는 모듈 업데이트 모달에서 layout_strategy 선택 시
     * "보존될 레이아웃 목록" 을 미리 보여주기 위해 사용됩니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @return array{has_modified_layouts: bool, modified_count: int, modified_layouts: array}
     */
    public function hasModifiedLayouts(string $identifier): array
    {
        $layouts = $this->layoutRepository->getBySourceIdentifier(
            $identifier,
            \App\Enums\LayoutSourceType::Module,
        );

        $modifiedLayouts = $layouts->filter(function ($layout) {
            if (! $layout->original_content_hash) {
                return false; // hash 없으면 (레거시 데이터) 미수정 취급
            }

            $currentContent = is_string($layout->content)
                ? json_decode($layout->content, true)
                : $layout->content;
            $currentHash = $this->computeContentHash($currentContent);

            return $currentHash !== $layout->original_content_hash;
        });

        return [
            'has_modified_layouts' => $modifiedLayouts->isNotEmpty(),
            'modified_count' => $modifiedLayouts->count(),
            'modified_layouts' => $modifiedLayouts->map(function ($layout) {
                $currentContent = is_string($layout->content)
                    ? json_decode($layout->content, true)
                    : $layout->content;
                $currentSize = $this->computeContentSize($currentContent);
                $originalSize = $layout->original_content_size ?? $currentSize;

                return [
                    'id' => $layout->id,
                    'name' => $layout->name,
                    'updated_at' => $layout->updated_at?->format('Y-m-d H:i:s'),
                    'size_diff' => $currentSize - $originalSize,
                ];
            })->values()->toArray(),
        ];
    }

    /**
     * 모듈을 업데이트합니다.
     *
     * 프로세스: 백업 → updating 상태 → 파일 교체 → 마이그레이션 → DB 갱신 →
     * 레이아웃 갱신 → 업그레이드 스텝 실행 → 상태 복원 → 백업 삭제
     *
     * @param  string  $identifier  모듈 식별자
     * @param  bool  $force  버전 비교 없이 강제 업데이트
     * @param  \Closure|null  $onProgress  진행 콜백 (?string $step, string $message)
     * @param  VendorMode  $vendorMode  vendor 설치 모드
     * @param  string  $layoutStrategy  레이아웃 전략 ('overwrite' 또는 'keep')
     * @param  \Closure|null  $onUpgradeStep  upgrade step 실행 콜백 (인자: 버전 문자열)
     * @param  string|null  $sourceOverride  업데이트 소스 강제 지정 (auto|bundled|github)
     * @param  string|null  $zipPath  사전 다운로드된 zip 파일 경로
     * @return array{success: bool, from_version: string|null, to_version: string|null, message: string}
     *
     * @throws \RuntimeException 업데이트 실패 시
     */
    public function updateModule(
        string $identifier,
        bool $force = false,
        ?\Closure $onProgress = null,
        VendorMode $vendorMode = VendorMode::Auto,
        string $layoutStrategy = 'overwrite',
        ?\Closure $onUpgradeStep = null,
        ?string $sourceOverride = null,
        ?string $zipPath = null,
    ): array
    {
        $record = $this->moduleRepository->findByIdentifier($identifier);
        if (! $record) {
            throw new \RuntimeException(__('modules.not_found', ['module' => $identifier]));
        }

        // 상태 가드
        ExtensionStatusGuard::assertNotInProgress(
            ExtensionStatus::from($record->status),
            $identifier
        );

        $module = $this->getModule($identifier);
        if (! $module) {
            throw new \RuntimeException(__('modules.not_found', ['module' => $identifier]));
        }

        $previousStatus = $record->status;
        $fromVersion = $record->version;
        $updateInfo = $this->checkModuleUpdate($identifier);

        // ZIP 강제 경로: 외부 ZIP 파일을 직접 추출하여 사용. checkModuleUpdate 결과는 무시.
        // zipTempDir / zipExtractedDir 는 staging 단계에서 사용 후 finally 에서 정리.
        $zipTempDir = null;
        $zipExtractedDir = null;
        if ($zipPath !== null) {
            $prepared = $this->extensionManager->prepareZipSource($zipPath, $identifier, 'module.json');
            $zipTempDir = $prepared['temp_dir'];
            $zipExtractedDir = $prepared['extracted_dir'];
            $updateSource = 'zip';
            $toVersion = $prepared['to_version'];
        }
        // 번들 강제 경로: 코어 업그레이드 / 일괄 업데이트 컨텍스트에서 GitHub 상태와 무관하게
        // _bundled manifest 버전을 강제 사용. checkModuleUpdate 의 GitHub 엄격 우선 정책을 우회.
        elseif ($sourceOverride === 'bundled') {
            $bundled = $this->getBundledVersion($identifier);
            if ($bundled === null) {
                throw new \RuntimeException(
                    __('modules.errors.force_update_no_source', ['module' => $identifier])
                );
            }
            $updateSource = 'bundled';
            $toVersion = $bundled;
        } elseif ($sourceOverride === 'github') {
            // GitHub 강제 경로: _bundled 폴백 없이 GitHub 만 시도.
            // checkModuleUpdate 가 GitHub 응답을 받으면 'github' 을 반환하고, 실패 시 'bundled'
            // 로 폴백하므로 그대로 둘 수 없다. github_url 자체가 없으면 불가능 판정.
            if (! $module->getGithubUrl()) {
                throw new \RuntimeException(
                    __('modules.errors.force_update_no_source', ['module' => $identifier])
                );
            }
            $updateSource = 'github';
            $toVersion = ($updateInfo['update_source'] === 'github' ? $updateInfo['latest_version'] : null)
                ?? $updateInfo['current_version'];
        } elseif (! $updateInfo['update_available'] && ! $force) {
            return [
                'success' => false,
                'from_version' => $fromVersion,
                'to_version' => $fromVersion,
                'message' => __('modules.no_update_available'),
            ];
        } elseif ($force && ! $updateInfo['update_available']) {
            $updateSource = $this->resolveForceUpdateSource($identifier);

            if ($updateSource === null) {
                throw new \RuntimeException(
                    __('modules.errors.force_update_no_source', ['module' => $identifier])
                );
            }

            // 번들 재설치는 번들 manifest 버전 기준, github 재설치는 현재 버전 기준
            if ($updateSource === 'bundled') {
                $toVersion = $this->getBundledVersion($identifier) ?? $updateInfo['current_version'];
            } else {
                $toVersion = $updateInfo['current_version'];
            }
        } else {
            $toVersion = $updateInfo['latest_version'];
            $updateSource = $updateInfo['update_source'];
        }

        // 다운그레이드 차단 — fromVersion > toVersion 인 경우 force=false 면 차단.
        // force=true 는 의도적 다운그레이드 (장애 롤백 등) 허용. lang pack 정책과 일관.
        if ($fromVersion && version_compare($toVersion, $fromVersion, '<') && ! $force) {
            throw new \RuntimeException(__('modules.errors.downgrade_blocked', [
                'from' => $fromVersion,
                'to' => $toVersion,
            ]));
        }

        $backupPath = null;

        try {
            // 1. 백업 생성
            $onProgress?->__invoke('backup', '백업 생성 중...');
            $backupPath = ExtensionBackupHelper::createBackup('modules', $identifier, $onProgress);

            // 2. 상태 → updating
            $onProgress?->__invoke('status', '상태 변경 중...');
            $this->moduleRepository->updateByIdentifier($identifier, [
                'status' => ExtensionStatus::Updating->value,
                'updated_at' => now(),
            ]);

            // 3. 스테이징 (소스에 따라 분기)
            $onProgress?->__invoke('staging', '스테이징 중...');
            $stagingPath = null;

            try {
                if ($updateSource === 'github') {
                    $stagingPath = $this->downloadModuleUpdate($module, $toVersion);
                } elseif ($updateSource === 'bundled') {
                    $sourcePath = ExtensionPendingHelper::getBundledPath($this->modulesPath, $identifier);
                    $stagingPath = ExtensionPendingHelper::createUpdateStagingPath($this->modulesPath, $identifier);
                    ExtensionPendingHelper::stageForUpdate($sourcePath, $stagingPath, $onProgress);
                } elseif ($updateSource === 'zip') {
                    $stagingPath = ExtensionPendingHelper::createUpdateStagingPath($this->modulesPath, $identifier);
                    ExtensionPendingHelper::stageForUpdate($zipExtractedDir, $stagingPath, $onProgress);
                }

                // 3.4. 코어 버전 호환성 사전 검증 (staging manifest 기준)
                if ($stagingPath && ! $force && ! CoreServiceProvider::isCoreUpdateInProgress()) {
                    $stagedManifest = (new Vendor\VendorIntegrityChecker)->readManifest($stagingPath);
                    CoreVersionChecker::validateExtension(
                        $stagedManifest['g7_version'] ?? null,
                        $identifier,
                        'module'
                    );
                }

                // 3.5. Vendor 설치 (의존성 있는 경우만, 변경 시에만)
                $resolvedVendorMode = $vendorMode;
                if ($stagingPath && $this->extensionManager->hasComposerDependenciesAt($stagingPath)) {
                    $activePath = $this->modulesPath.DIRECTORY_SEPARATOR.$identifier;
                    $previousMode = $this->getPreviousVendorMode($identifier);

                    if ($vendorMode === VendorMode::Auto
                        && $previousMode !== VendorMode::Bundled
                        && $this->extensionManager->isComposerUnchanged($stagingPath, $activePath)
                    ) {
                        $onProgress?->__invoke('composer', 'Composer 의존성 변경 없음 — 스킵');
                        Log::info('모듈 업데이트: composer 변경 없음, 스킵', ['module' => $identifier]);
                        ExtensionPendingHelper::copyVendorFromActive($activePath, $stagingPath, $onProgress);
                        // 모드는 이전 설치 모드를 유지
                        $resolvedVendorMode = $previousMode ?? VendorMode::Auto;
                    } else {
                        $onProgress?->__invoke('composer', 'Vendor 설치 중...');
                        $vendorResult = $this->installVendorViaResolver(
                            $identifier,
                            $stagingPath,
                            $vendorMode,
                            'update',
                            $previousMode,
                            $onProgress,
                        );
                        $resolvedVendorMode = $vendorResult->mode;
                    }
                }

                // 4. 원자적 적용 (스테이징 → 활성 디렉토리)
                $onProgress?->__invoke('files', '파일 교체 중...');
                if ($stagingPath) {
                    $targetPath = $this->modulesPath.DIRECTORY_SEPARATOR.$identifier;
                    ExtensionPendingHelper::copyToActive($stagingPath, $targetPath, $onProgress);
                }
            } finally {
                // 스테이징 정리
                if ($stagingPath) {
                    ExtensionPendingHelper::cleanupStaging($stagingPath);
                }
                // ZIP 임시 추출 디렉토리 정리
                if ($zipTempDir && File::isDirectory($zipTempDir)) {
                    File::deleteDirectory($zipTempDir);
                }
            }

            // 모듈 재로드 (새 파일로)
            $onProgress?->__invoke('reload', '모듈 재로드 중...');
            $this->reloadModule($identifier);
            $module = $this->getModule($identifier);

            // 4. 마이그레이션 실행
            $onProgress?->__invoke('migration', '마이그레이션 실행 중...');
            if ($module) {
                $this->runMigrations($module);
            }

            // 5. 오토로드 갱신 (DB 트랜잭션 진입 전)
            //
            // 순서 중요: cleanupStaleModuleEntries 의 동적 hook(getDynamicPermissionIdentifiers 등)이
            // 모듈 클래스(예: sirsoft-board 의 Board 모델)를 참조하므로, 새 파일에 대한 PSR-4 매핑이
            // 현재 프로세스 ClassLoader 에 등록되어 있어야 한다. updateComposerAutoload() 는
            // 파일 재기록 + 런타임 ClassLoader 재등록을 동시에 수행한다.
            $onProgress?->__invoke('autoload', '오토로드 갱신 중...');
            $this->extensionManager->updateComposerAutoload();

            // 6. 트랜잭션: DB 정보 갱신
            $onProgress?->__invoke('db', 'DB 갱신 중...');
            DB::beginTransaction();
            try {
                $name = $module ? $this->convertToMultilingual($module->getName()) : $record->name;
                $description = $module ? $this->convertToMultilingual($module->getDescription()) : $record->description;

                $this->moduleRepository->updateByIdentifier($identifier, [
                    'version' => $toVersion,
                    'latest_version' => $toVersion,
                    'name' => $name,
                    'description' => $description,
                    'update_available' => false,
                    'update_source' => null,
                    'github_url' => $module ? $module->getGithubUrl() : $record->github_url,
                    'github_changelog_url' => $module ? $this->buildChangelogUrl($module->getGithubUrl()) : $record->github_changelog_url,
                    'metadata' => $module ? $module->getMetadata() : $record->metadata,
                    'vendor_mode' => $resolvedVendorMode->value,
                    'updated_by' => Auth::id(),
                    'updated_at' => now(),
                ]);

                // 선언형 산출물 (역할/권한/메뉴/IDV/알림) 일괄 동기화 + stale cleanup
                if ($module) {
                    $this->syncDeclarativeArtifacts($module);
                }

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            // 7. 업그레이드 스텝 실행
            $onProgress?->__invoke('upgrade', '업그레이드 스텝 실행 중...');
            if ($module) {
                $this->runUpgradeSteps($module, $fromVersion, $toVersion, $force, $onUpgradeStep);
            }

            // 8. 상태 복원 (refreshModuleLayouts()가 active 상태를 요구하므로 먼저 복원)
            $onProgress?->__invoke('restore_status', '상태 복원 중...');
            $this->moduleRepository->updateByIdentifier($identifier, [
                'status' => $previousStatus,
                'updated_at' => now(),
            ]);

            // 9. 레이아웃 갱신 (이전 상태가 active였으면)
            // refreshModuleLayouts()는 캐시 무효화 + 캐시 버전 증가를 포함
            $onProgress?->__invoke('layout', '레이아웃 갱신 중...');
            if ($previousStatus === ExtensionStatus::Active->value && $module) {
                $preserveModified = ($layoutStrategy === 'keep');
                $this->registerModuleLayouts($identifier);
                $this->registerLayoutExtensions($module);
                $this->refreshModuleLayouts($identifier, $preserveModified);
            }

            // 10. 백업 삭제 + 캐시 삭제
            $onProgress?->__invoke('cleanup', '정리 중...');
            ExtensionBackupHelper::deleteBackup($backupPath);

            // 캐시 무효화
            $this->clearAllTemplateLanguageCaches();
            $this->clearAllTemplateRoutesCaches();
            $this->incrementExtensionCacheVersion();
            self::invalidateModuleStatusCache();

            // 훅 발행: 모듈 업데이트 완료 (Artisan 직접 호출 시에도 리스너 트리거)
            HookManager::doAction('core.modules.updated', $identifier);

            Log::info('모듈 업데이트 완료', [
                'module' => $identifier,
                'from' => $fromVersion,
                'to' => $toVersion,
                'source' => $updateSource,
            ]);

            return [
                'success' => true,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'message' => __('modules.update_success', [
                    'module' => $identifier,
                    'version' => $toVersion,
                ]),
            ];

        } catch (\Throwable $e) {
            // 실패 시: 백업 복원 + 상태 복원
            Log::error('모듈 업데이트 실패', [
                'module' => $identifier,
                'error' => $e->getMessage(),
            ]);

            if ($backupPath) {
                try {
                    ExtensionBackupHelper::restoreFromBackup('modules', $identifier, $backupPath, $onProgress);
                    ExtensionBackupHelper::deleteBackup($backupPath);

                    // 모듈 재로드 (복원된 파일로)
                    $this->reloadModule($identifier);
                } catch (\Throwable $restoreError) {
                    Log::error('모듈 백업 복원 실패', [
                        'module' => $identifier,
                        'error' => $restoreError->getMessage(),
                    ]);
                }
            }

            // 상태 복원
            $this->moduleRepository->updateByIdentifier($identifier, [
                'status' => $previousStatus,
                'updated_at' => now(),
            ]);

            throw new \RuntimeException(
                __('modules.errors.update_failed', [
                    'module' => $identifier,
                    'error' => $e->getMessage(),
                ]),
                0,
                $e
            );
        }
    }

    /**
     * --force 시 업데이트 소스를 결정합니다.
     *
     * 운영 정책: --force 시에는 번들이 우선, 번들이 없는 경우에만 GitHub 사용.
     * (일반 업데이트의 GitHub 우선과 반대 — 개발자가 로컬 번들로 되돌리려는 의도 존중)
     *
     * 우선순위:
     *   1. _bundled (메모리 캐시) → 'bundled'
     *   2. _bundled (디스크 재조회) → 'bundled'
     *   3. GitHub URL 존재 → 'github'
     *   4. 둘 다 없음 → null (업데이트 불가)
     *
     * @param  string  $identifier  모듈 식별자
     * @return string|null 'bundled' | 'github' | null
     */
    private function resolveForceUpdateSource(string $identifier): ?string
    {
        if (isset($this->bundledModules[$identifier])) {
            return 'bundled';
        }

        $bundledMeta = ExtensionPendingHelper::loadBundledExtensions($this->modulesPath, 'module.json');
        if (isset($bundledMeta[$identifier])) {
            return 'bundled';
        }

        // 번들 없음 → GitHub URL 확인
        $module = $this->getModule($identifier);
        if ($module && $module->getGithubUrl()) {
            return 'github';
        }

        return null;
    }

    /**
     * _bundled 에 등록된 모듈의 버전을 반환합니다 (force 업데이트용).
     *
     * @param  string  $identifier  모듈 식별자
     * @return string|null 버전 문자열 또는 null
     */
    private function getBundledVersion(string $identifier): ?string
    {
        if (isset($this->bundledModules[$identifier]['version'])) {
            return $this->bundledModules[$identifier]['version'];
        }

        $meta = ExtensionPendingHelper::loadBundledExtensions($this->modulesPath, 'module.json');

        return $meta[$identifier]['version'] ?? null;
    }

    /**
     * VendorResolver 경유로 vendor/ 를 구성합니다 (composer 또는 bundled).
     *
     * @param  string  $identifier  모듈 식별자
     * @param  string  $sourceDir  composer.json 및 vendor-bundle.zip 위치
     * @param  VendorMode  $mode  요청된 모드 (auto면 환경 감지)
     * @param  string  $operation  'install' | 'update'
     * @param  VendorMode|null  $previousMode  이전 설치 모드 (update 시 상속용)
     * @param  \Closure|null  $onProgress  진행 콜백
     *
     * @throws VendorInstallException
     */
    private function installVendorViaResolver(
        string $identifier,
        string $sourceDir,
        VendorMode $mode,
        string $operation,
        ?VendorMode $previousMode,
        ?\Closure $onProgress,
    ): VendorInstallResult {
        $resolver = app(VendorResolver::class);

        $context = new VendorInstallContext(
            target: 'module',
            identifier: $identifier,
            sourceDir: $sourceDir,
            targetDir: $sourceDir,
            requestedMode: $mode,
            previousMode: $previousMode,
            composerBinaryHint: config('process.composer_binary'),
            operation: $operation,
        );

        // Composer 모드 콜백 — 기존 ExtensionManager::runComposerInstallAt 위임
        $composerExecutor = function (VendorInstallContext $ctx) use ($onProgress): VendorInstallResult {
            $onProgress?->__invoke('composer', 'Composer install 실행 중...');
            $success = $this->extensionManager->runComposerInstallAt($ctx->sourceDir, true);

            if (! $success) {
                throw new VendorInstallException(
                    errorKey: 'composer_execution_failed',
                    context: ['message' => 'runComposerInstallAt returned false'],
                );
            }

            return new VendorInstallResult(
                mode: VendorMode::Composer,
                strategy: 'composer',
                packageCount: 0,
                details: ['source_dir' => $ctx->sourceDir],
            );
        };

        return $resolver->install($context, $composerExecutor);
    }

    /**
     * DB에 기록된 이전 vendor 설치 모드를 조회합니다 (update 시 상속용).
     */
    private function getPreviousVendorMode(string $identifier): ?VendorMode
    {
        $record = $this->moduleRepository->findByIdentifier($identifier);
        if (! $record) {
            return null;
        }

        $stored = $record->vendor_mode ?? null;
        if ($stored === null) {
            return null;
        }

        return VendorMode::tryFrom((string) $stored);
    }
}
