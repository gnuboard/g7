<?php

namespace App\Extension;

use App\Exceptions\ExtensionUpdateFailedException;
use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\UpgradeStepInterface;
use App\Contracts\Repositories\LayoutExtensionRepositoryInterface;
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
use App\Enums\LayoutSyncMode;
use App\Enums\PermissionType;
use App\Exceptions\ExtensionOperationInProgressException;
use App\Extension\Concerns\ResolvesExtensionIcons;
use App\Extension\Concerns\ResolvesExtensionSharedRecords;
use App\Extension\Concerns\ResolvesUpdateCoreCompatibility;
use App\Extension\Helpers\DependencyEnricher;
use App\Extension\Helpers\DependencyVersionWarnings;
use App\Extension\Helpers\ExtensionBackupHelper;
use App\Extension\Helpers\ExtensionRollbackCacheRepair;
use App\Extension\Helpers\ExtensionInstallRollbackHelper;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use App\Extension\Helpers\ExtensionOperationLock;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\Helpers\ExtensionRoleSyncHelper;
use App\Extension\Helpers\ExtensionStatusGuard;
use App\Extension\Helpers\ExtensionUpgradeGuardHelper;
use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Helpers\GithubHelper;
use App\Extension\Helpers\IdentityMessageDeclarationConverter;
use App\Extension\Helpers\IdentityPolicySyncHelper;
use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\Layout\DTO\LayoutSyncEntry;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Extension\Layout\LayoutSyncReport;
use App\Extension\Testing\ExtensionTestAllowlist;
use App\Extension\Traits\CollectsFragmentSources;
use App\Extension\Vendor\Exceptions\VendorInstallException;
use App\Extension\Vendor\VendorInstallContext;
use App\Extension\Vendor\VendorInstallResult;
use App\Extension\Vendor\VendorMode;
use App\Extension\Vendor\VendorResolver;
use App\Models\IdentityPolicy;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use App\Providers\CoreServiceProvider;
use App\Services\LayoutExtensionService;
use App\Support\AssetUrl;
use App\Support\ExtensionStoragePath;
use App\Support\RouteCacheHelper;
use Illuminate\Support\Arr;
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
    use ResolvesExtensionIcons;
    use ResolvesExtensionSharedRecords;
    use ResolvesUpdateCoreCompatibility;
    use Traits\CachesModuleStatus;
    use Traits\ClearsTemplateCaches;
    use Traits\ComputesLayoutContentHash;
    use Traits\InspectsUninstallData;
    use Traits\InvalidatesLayoutCache;
    use Traits\RefreshesLayoutExtensions;
    use Traits\ReloadsChangedExtensionClass;
    use Traits\SynchronizesLayoutRows;
    use Traits\ValidatesLayoutFiles;
    use Traits\ValidatesPermissionStructure;
    use Traits\ValidatesSeoVariables;
    use Traits\ValidatesStateContracts;
    use Traits\ValidatesTranslationPath;

    /**
     * 아이콘 해석기에 넘길 확장 유형을 반환합니다.
     *
     * @return string 확장 유형 ('module')
     */
    protected function extensionIconType(): string
    {
        return 'module';
    }

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

    /**
     * @param  ExtensionManager  $extensionManager  설치·활성 상태 등 확장 공통 관리
     * @param  ModuleRepositoryInterface  $moduleRepository  모듈 행 조회·저장
     * @param  PermissionRepositoryInterface  $permissionRepository  권한 행 조회·저장 (설치 시 권한 동기화)
     * @param  RoleRepositoryInterface  $roleRepository  역할 행 조회·저장 (설치 시 역할 동기화)
     * @param  MenuRepositoryInterface  $menuRepository  관리자 메뉴 행 조회·저장
     * @param  TemplateRepositoryInterface  $templateRepository  템플릿 행 조회·저장
     * @param  PluginRepositoryInterface  $pluginRepository  플러그인 행 조회·저장
     * @param  LayoutRepositoryInterface  $layoutRepository  레이아웃 행 조회·저장
     * @param  LayoutExtensionService  $layoutExtensionService  확장 조각 등록·해제 (설치·제거에 동반)
     * @param  LayoutRowSynchronizer  $layoutRowSynchronizer  레이아웃·확장 조각 행 동기화기 (3자 병합·기준 보관)
     * @param  LayoutExtensionRepositoryInterface  $layoutExtensionRepository  확장 조각 행 조회 (수정한 레이아웃 목록)
     * @param  ExtensionRoleSyncHelper  $roleSyncHelper  선언된 역할·권한을 DB 에 반영
     * @param  ExtensionMenuSyncHelper  $menuSyncHelper  선언된 관리자 메뉴를 DB 에 반영
     */
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
        protected LayoutRowSynchronizer $layoutRowSynchronizer,
        protected LayoutExtensionRepositoryInterface $layoutExtensionRepository,
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
     *
     * @return void 반환값 없음
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
                    $this->rememberLoadedExtensionClassFile($moduleClass, $moduleFile);
                }

                if (class_exists($moduleClass)) {
                    // 같은 프로세스에서 진입 파일이 바뀌었으면(업데이트 직후) 새 정의로 만든다 — 구버전 인스턴스의
                    // getHookListeners() 로 훅 캐시를 구우면 새 리스너가 빠진다(mem:hook-cache-misses-last-updated-extension).
                    $module = $this->instantiateCurrentExtensionClass(
                        $moduleClass,
                        $moduleFile,
                        fn () => $this->evalFreshModule($moduleFile, $moduleClass, $directory)
                    );
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     * @param  string|null  $failureReason  실패 시 사유가 담기는 out 파라미터 (성공 시 null)
     * @return bool 설치 성공 여부
     *
     * @throws \Exception 모듈을 찾을 수 없거나 의존성 문제 시
     */
    public function installModule(
        string $moduleName,
        ?\Closure $onProgress = null,
        VendorMode $vendorMode = VendorMode::Auto,
        bool $force = false,
        ?string &$failureReason = null,
    ): bool {
        // 직전 훅 — CLI·어드민 UI 공통으로 한 번 발화한다(예전에는 Service 에서만 발화해 CLI 가 빠졌다, #138 후속)
        HookManager::doAction('core.modules.before_install', $moduleName);

        $failureReason = null;

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
                    $onProgress?->__invoke('composer', __('extensions.progress.pending_vendor_install'));
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
        $rollbackActivePath = $this->modulesPath.DIRECTORY_SEPARATOR.$moduleName;
        // 검증은 로드된 확장 인스턴스를 요구해 복사보다 뒤에 온다. 그래서 검증이 실패하면
        // 방금 만든 활성 디렉토리가 고아로 남는다 — DB 행이 없어 목록에도 뜨지 않고 오류도
        // 남지 않은 채 디스크만 점유한다. 이번 호출이 만든 것이면 되돌린다.
        $rollbackDirExisted = File::isDirectory($rollbackActivePath);

        $onProgress?->__invoke('copy', __('extensions.progress.copy_files'));
        $this->copyFromPendingOrBundled($moduleName, $onProgress, $force);

        // 설치 시점에는 autoload-extensions.php 가 아직 갱신되지 않았다. module.php 의
        // getConfigValues()/getSettingsSchema() 등이 자기 src/ 클래스를 호출하면 그 클래스가
        // 해석되지 않아 "Class not found" 로 설치가 중단된다 (업그레이드 경로는 기설치본의
        // 매핑이 이미 있어 재현되지 않는다). 시더 실행 직전이 아니라 진입 파일을 로드하기
        // 전에 그 확장의 PSR-4 매핑을 등록한다.
        ExtensionManager::registerExtensionAutoloadPaths('modules', $moduleName);

        try {
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
            $onProgress?->__invoke('validate', __('extensions.progress.validate'));
            $this->checkDependencies($module);

            // 권한 구조 검증 (계층형 구조 필수)
            $this->validatePermissionStructure($module, 'module');

            // 언어 파일 경로 검증 (src/lang 경로 필수)
            $this->validateTranslationPath($module, 'module');

            // SEO 변수명 중복 검증
            $this->validateSeoVariables($module, 'module');

            // 전역 상태 공개 계약 이름 충돌 검증 (#639)
            $this->validateStateContracts('module:'.$module->getIdentifier(), $module->getStateContracts());

            // 모듈 설치 실행
            $module->clearLifecycleFailureReason();
            $result = $module->install();

            if (! $result) {
                $failureReason = $module->getLifecycleFailureReason() ?? __('modules.errors.unknown_error');

                return false;
            }

            // Phase 1: 마이그레이션 실행 (DDL - 트랜잭션 외부)
            // MySQL에서 CREATE TABLE 등 DDL 문은 암시적 커밋을 유발하므로 트랜잭션 외부에서 실행
            $onProgress?->__invoke('migration', __('extensions.progress.migration'));
            $this->runMigrations($module);

            // Phase 2: 데이터 작업 (DML - 트랜잭션 내부)
            $onProgress?->__invoke('db', __('extensions.progress.db_register'));
            try {
                DB::beginTransaction();

                // GitHub에서 최신 버전 정보 가져오기
                $latestVersion = $this->fetchLatestVersion($module);
                $updateAvailable = $latestVersion ? version_compare($latestVersion, $module->getVersion(), '>') : false;

                // 다국어 name, description 처리 (역호환성 지원)
                $name = $this->convertToMultilingual($module->getName());
                $description = $this->convertToMultilingual($module->getDescription());

                // 활성 언어팩의 manifest seed(ja 등)를 name/description 다국어 필드에 주입
                $manifest = HookManager::applyFilters(
                    "module.{$module->getIdentifier()}.manifest.translations",
                    ['name' => $name, 'description' => $description]
                );
                $name = $manifest['name'] ?? $name;
                $description = $manifest['description'] ?? $description;

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

                // 알림 정의/템플릿 자동 동기화 (notification_definitions / notification_templates)
                $this->syncModuleNotificationDefinitions($module);

                DB::commit();

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

            // Phase 3: 시더 실행 (트랜잭션 외부)
            // 시더 내부에서 별도 트랜잭션을 사용할 수 있으므로 외부에서 실행
            $onProgress?->__invoke('seed', __('extensions.progress.seed'));
            $this->runModuleSeeders($module);

            // Phase 4: 기본 설정 파일 생성
            $onProgress?->__invoke('settings', __('extensions.progress.environment_settings_init'));
            $this->initializeModuleSettings($module);

            // Phase 4.5: Composer 의존성 설치 (외부 패키지가 있는 경우에만)
            // _pending에서 이미 설치한 경우 스킵 (vendor/가 활성 디렉토리에 복사됨)
            if (! $composerDoneInPending) {
                $onProgress?->__invoke('composer', __('extensions.progress.composer_install'));
                if (! app()->environment('testing')
                    && $this->extensionManager->hasComposerDependencies('modules', $moduleName)) {
                    $composerResult = $this->extensionManager->runComposerInstall('modules', $moduleName);
                    if (! $composerResult) {
                        Log::warning('모듈 Composer 의존성 설치 실패', ['module' => $moduleName]);
                    }
                }
            }

            // Phase 5: 오토로드 병합 실행 (트랜잭션 외부)
            $onProgress?->__invoke('autoload', __('extensions.progress.autoload'));
            $this->extensionManager->updateComposerAutoload();

            // 모듈 상태 캐시 무효화
            self::invalidateModuleStatusCache();

            // 확장 캐시 버전 증가 (프론트엔드가 새로운 캐시로 요청하도록)
            $this->incrementExtensionCacheVersion();
            RouteCacheHelper::rebuild();

            // 확장 미들웨어 인덱스 무효화 — 새 모듈의 미들웨어 선언이 즉시 게이트에 반영.
            ExtensionMiddlewareRegistry::flush();

            // 훅 발행: 모듈 설치 완료
            // @deprecated 'core.modules.installed' — identifier 1개만 전달하는 구훅. 정규 훅은 아래
            //   after_install 이다. 구독자(SEO 캐시 등·사외 확장)가 있어 다음 메이저까지 유지한다.
            HookManager::doAction('core.modules.installed', $moduleName);
        } catch (\Throwable $e) {
            ExtensionInstallRollbackHelper::removeIfCreatedByThisInstall(
                $rollbackActivePath,
                $rollbackDirExisted,
                $moduleName,
                'module',
            );

            throw $e;
        }

        // 정규 훅 — Service 경유 여부와 무관하게 CLI 에서도 리스너(큐 재시작·활동 로그·언어팩)가
        // 돈다(#138). 설치 롤백 try 밖에서 발화해, 리스너 예외가 방금 끝난 설치를 되돌리지 않게 한다.
        HookManager::doAction('core.modules.after_install', $moduleName, $this->getModuleInfo($moduleName) ?? []);

        return true;
    }

    /**
     * 지정된 모듈을 활성화합니다.
     *
     * @param  string  $moduleName  활성화할 모듈명
     * @param  bool  $force  필요 의존성이 충족되지 않아도 강제 활성화 여부
     * @return array{success: bool, layouts_registered: int, layout_warnings?: array<int, array{file: string, partial: string, reason: string, message: string}>, warning?: bool, missing_modules?: array, missing_plugins?: array, message?: string} 활성화 결과·등록된 레이아웃 개수·빈 자리로 등록한 조각 참조 경고
     */
    public function activateModule(string $moduleName, bool $force = false): array
    {
        // 직전 훅 — CLI·어드민 UI 공통으로 한 번 발화한다(예전에는 Service 에서만 발화해 CLI 가 빠졌다, #138 후속)
        HookManager::doAction('core.modules.before_activate', $moduleName, $force);

        $module = $this->getModule($moduleName);
        if (! $module) {
            return [
                'success' => false,
                'layouts_registered' => 0,
                'reason' => __('modules.errors.not_found', ['module' => $moduleName]),
            ];
        }

        $module->clearLifecycleFailureReason();

        // 상태 가드: 진행 중 상태 체크
        $record = $this->moduleRepository->findByIdentifier($module->getIdentifier());
        if ($record) {
            ExtensionStatusGuard::assertNotInProgress(
                ExtensionStatus::from($record->status),
                $module->getIdentifier()
            );
        }

        // 코어 버전 호환성 사전 검증
        if (! $force && ! CoreServiceProvider::isCoreUpdateInProgress()) {
            CoreVersionChecker::validateExtension(
                $module->getRequiredCoreVersion(),
                $module->getIdentifier(),
                'module'
            );
        }

        // 전역 상태 공개 계약 이름 충돌 검증 — 꺼져 있던 사이 다른 확장이 같은 이름을 가졌을 수 있다 (#639)
        $this->validateStateContracts('module:'.$module->getIdentifier(), $module->getStateContracts());

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

        // 레이아웃 파일 검증을 상태 쓰기보다 먼저 한다 — 엄격한 검증 실패(데이터 소스 id 중복·layouts 밖 참조·
        // .g7.html 컴파일 오류)면 여기서 예외가 올라가 상태·soft delete 행이 그대로 남는다. 예전에는 active 로 쓴 뒤
        // 등록 단계에서 실패해, 레이아웃이 하나도 없는 활성 모듈이 남았다(#712 실측). 결과는 등록 단계가 그대로 쓴다.
        $validatedLayouts = $this->validateModuleLayoutFiles($moduleName);

        $result = $module->activate();
        $layoutsRegistered = 0;
        $layoutWarnings = [];

        if ($result) {
            $this->moduleRepository->updateByIdentifier($module->getIdentifier(), [
                'status' => ExtensionStatus::Active->value,
                'deactivated_reason' => null,
                'deactivated_at' => null,
                'incompatible_required_version' => null,
                'updated_by' => Auth::id(),
                'updated_at' => now(),
            ]);

            // 모듈 상태 캐시 무효화 — DB 상태 쓰기 직후에 둔다.
            // 뒤따르는 굽기(RouteCacheHelper::rebuild() 의 route:cache, 훅 캐시 재생성)는
            // 새 애플리케이션을 부팅해 "캐시된" 활성 모듈 목록을 읽는다. 여기서 비우지 않으면
            // 방금 활성으로 바뀐 이 모듈이 목록에서 빠진 채 라우트가 박제되고,
            // 라우트 캐시에는 스캔 폴백이 없어 오류·경고 없이 그 엔드포인트만 404 가 된다.
            self::invalidateModuleStatusCache();

            // soft deleted된 모듈 레이아웃 복원 (재활성화 시)
            $this->restoreModuleLayouts($module->getIdentifier());

            // 모듈 레이아웃 등록 (새로운 레이아웃 또는 업데이트)
            $layoutsRegistered = $this->registerModuleLayouts($moduleName, $validatedLayouts);

            // 7.0 JSON 레이아웃의 깨진 조각 참조는 빈 자리로 등록됐다 — 결과에 실어 화면·CLI 가 알린다 (#712).
            // 검증이 돌지 않았으면(레이아웃 디렉토리 없음) 보관소에 남은 다른 확장의 경고를 싣지 않는다.
            $layoutWarnings = $this->takeLayoutIncludeWarnings();
            if ($validatedLayouts === null) {
                $layoutWarnings = [];
            }

            // 모듈 레이아웃 확장 복원 (재활성화 시)
            $this->restoreLayoutExtensions($module);

            // 모듈 레이아웃 확장 등록
            $this->registerLayoutExtensions($module);

            // 호스트가 나중에 설치된 조각을 메운다 — 전 활성 템플릿에 활성 확장 조각 재등록(멱등).
            $this->reregisterActiveExtensionsToAllTemplates();

            // 모듈 다국어 데이터가 변경되므로 템플릿 언어 캐시 삭제
            $this->clearAllTemplateLanguageCaches();

            // 모듈 routes 데이터가 변경되므로 템플릿 routes 캐시 삭제
            $this->clearAllTemplateRoutesCaches();

            // 확장 기능 캐시 버전 증가 (프론트엔드 캐시 무효화)
            $this->incrementExtensionCacheVersion();
            RouteCacheHelper::rebuild();

            // 본인인증 route scope 캐시 무효화 — 재활성화 시 이 모듈이 선언한 정책이
            // 다시 enforce 대상에 포함되도록 한다 (applyActiveExtensionScope 재평가).
            IdentityPolicy::flushRouteScopeCache();

            // 확장 미들웨어 인덱스 무효화 — 재활성화 시 이 모듈이 선언한 미들웨어가
            // 게이트 매칭 후보에 다시 포함되도록 한다.
            ExtensionMiddlewareRegistry::flush();
        }

        // 훅 발행: 모듈 활성화 완료
        if ($result) {
            // @deprecated 'core.modules.activated' — identifier 1개만 전달하는 구훅. 정규 훅은 after_activate.
            HookManager::doAction('core.modules.activated', $moduleName);

            // 정규 훅 — CLI·어드민 UI 공통 단일 발화(#138)
            HookManager::doAction('core.modules.after_activate', $moduleName, $this->getModuleInfo($moduleName) ?? []);
        }

        if (! $result) {
            // 모듈이 스스로 활성화를 거부했다. 사유를 남겼으면 그대로 싣고,
            // 남기지 않았으면 일반 문구로 대체한다 — 원인 자리를 비워 두면
            // 관리자 화면에 치환되지 않은 자리표시자가 그대로 노출된다.
            return [
                'success' => false,
                'layouts_registered' => $layoutsRegistered,
                'layout_warnings' => $layoutWarnings,
                'reason' => $module->getLifecycleFailureReason() ?? __('modules.errors.unknown_error'),
            ];
        }

        return ['success' => true, 'layouts_registered' => $layoutsRegistered, 'layout_warnings' => $layoutWarnings];
    }

    /**
     * soft deleted된 모듈 레이아웃을 복원합니다.
     *
     * 모듈 재활성화 시 이전에 soft delete된 레이아웃을 복원합니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return void 반환값 없음
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
        // 직전 훅 — CLI·어드민 UI 공통으로 한 번 발화한다(예전에는 Service 에서만 발화해 CLI 가 빠졌다, #138 후속)
        HookManager::doAction('core.modules.before_deactivate', $moduleName, $force);

        $module = $this->getModule($moduleName);
        if (! $module) {
            return [
                'success' => false,
                'layouts_deleted' => 0,
                'reason' => __('modules.errors.not_found', ['module' => $moduleName]),
            ];
        }

        $module->clearLifecycleFailureReason();

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
                    // 경고 창이 의존 확장마다 「v{버전}」 배지를 그린다 — 버전이 없으면 「v」 만 남는다.
                    return [
                        'identifier' => $module->identifier,
                        'name' => $module->getLocalizedName(),
                        'version' => $module->version,
                    ];
                })->toArray(),
                'dependent_plugins' => $dependentPlugins->map(function (Plugin $plugin) {
                    return [
                        'identifier' => $plugin->identifier,
                        'name' => $plugin->getLocalizedName(),
                        'version' => $plugin->version,
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

            // 모듈 상태 캐시 무효화 — DB 상태 쓰기 직후에 둔다.
            // 뒤따르는 RouteCacheHelper::rebuild() 가 캐시된 활성 모듈 목록을 읽으므로,
            // 여기서 비우지 않으면 방금 비활성으로 바꾼 모듈의 라우트가 그대로 박제되어
            // 비활성 상태에서도 그 API 가 계속 호출 가능한 상태로 남는다.
            self::invalidateModuleStatusCache();

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
            RouteCacheHelper::rebuild();

            // 모듈 자체 캐시 전체 정리
            $this->flushModuleCache($module);

            // 본인인증 route scope 캐시 무효화 — 비활성 모듈이 선언한 정책이 enforce 대상에서
            // 즉시 제외되도록 한다. 정책 행 자체는 변경하지 않으므로(enabled 운영자 설정 보존)
            // IdentityPolicy 모델 이벤트가 발화하지 않아, 라이프사이클에서 명시적으로 호출한다.
            IdentityPolicy::flushRouteScopeCache();

            // 확장 미들웨어 인덱스 무효화 — 비활성 모듈의 미들웨어가 게이트 매칭에서 즉시 제외.
            ExtensionMiddlewareRegistry::flush();

            // 요구사항 #6: 비활성화 후 훅 발행 — 언어팩 cascade 등 후속 처리
            HookManager::doAction('core.modules.after_deactivate', $module->getIdentifier());
        }

        if (! $result) {
            return [
                'success' => false,
                'layouts_deleted' => $layoutsDeleted,
                'reason' => $module->getLifecycleFailureReason() ?? __('modules.errors.unknown_error'),
            ];
        }

        return ['success' => true, 'layouts_deleted' => $layoutsDeleted];
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
     * @param  string|null  $failureReason  실패 시 사유가 담기는 out 파라미터 (성공 시 null)
     * @param  array<int, array{directory: string, archive: string}>|null  $preservedBackups  삭제 전에 보관한 운영자 소유 디렉토리(`custom/`)의 사본 경로가 담기는 out 파라미터.
     *                                                                                        운영자에게 "지웠지만 사본은 여기 있다" 를 알리기 위한 것이므로 호출부가 노출해야 한다.
     * @return bool 제거 성공 여부
     *
     * @throws \Exception 모듈을 찾을 수 없을 때
     */
    public function uninstallModule(
        string $moduleName,
        bool $deleteData = false,
        ?\Closure $onProgress = null,
        ?string &$failureReason = null,
        ?array &$preservedBackups = null,
    ): bool {
        // 직전 훅 — CLI·어드민 UI 공통으로 한 번 발화한다(예전에는 Service 에서만 발화해 CLI 가 빠졌다, #138 후속)
        HookManager::doAction('core.modules.before_uninstall', $moduleName, $deleteData);

        $failureReason = null;
        $preservedBackups = [];

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

        // 켜진 확장이 의존하면 제거하지 않는다 — 비활성화는 경고 뒤 강제로 진행할 수 있지만 제거는 되돌릴 수 없고,
        // 남은 의존 확장이 필요한 모듈 없이 켜진 채로 남는다(#138 실측 ①).
        $activeDependents = $this->activeDependentIdentifiers($moduleName);
        if ($activeDependents !== []) {
            throw new \Exception(__('modules.errors.uninstall_blocked_by_dependents', [
                'module' => $moduleName,
                'extensions' => implode(', ', $activeDependents),
            ]));
        }

        // after_uninstall 훅에 실을 정보는 파괴 단계(DB 행 삭제·디렉토리 삭제) 이전에 캡처한다.
        $moduleInfo = $this->getModuleInfo($moduleName) ?? [];

        // Phase 1: 데이터 삭제 옵션이 true인 경우 동적 데이터 정리 및 마이그레이션 롤백 (DDL - 트랜잭션 외부)
        // MySQL에서 DROP TABLE 등 DDL 문은 암시적 커밋을 유발하므로 트랜잭션 외부에서 실행
        $onProgress?->__invoke('data_cleanup', __('extensions.progress.data_cleanup'));
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
            $onProgress?->__invoke('db', __('extensions.progress.db_cleanup'));
            DB::beginTransaction();

            // 모듈 제거 실행
            $module->clearLifecycleFailureReason();
            $result = $module->uninstall();

            if (! $result) {
                $failureReason = $module->getLifecycleFailureReason() ?? __('modules.errors.unknown_error');
            }

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
                // 모듈 상태 캐시 무효화 — DB 에서 모듈 행을 지운 직후(커밋 직후)에 둔다.
                // 뒤따르는 굽기(오토로드 갱신 내 훅 캐시 재생성, RouteCacheHelper::rebuild())가
                // 캐시된 활성 모듈 목록을 읽으므로, 여기서 비우지 않으면 이미 제거된 모듈이
                // 목록에 남은 채로 라우트·훅이 박제된다.
                self::invalidateModuleStatusCache();

                $onProgress?->__invoke('autoload', __('extensions.progress.autoload'));
                $this->extensionManager->updateComposerAutoload();

                // 모듈 다국어 데이터가 변경되므로 템플릿 언어 캐시 삭제
                $onProgress?->__invoke('cache', __('extensions.progress.cache_cleanup'));
                $this->clearAllTemplateLanguageCaches();

                // 모듈 routes 데이터가 변경되므로 템플릿 routes 캐시 삭제
                $this->clearAllTemplateRoutesCaches();

                // 확장 기능 캐시 버전 증가 (프론트엔드 캐시 무효화)
                $this->incrementExtensionCacheVersion();
                RouteCacheHelper::rebuild();

                // 모듈 자체 캐시 전체 정리
                $this->flushModuleCache($module);

                // 확장 미들웨어 인덱스 무효화 — 제거된 모듈의 미들웨어가 게이트 매칭에서 즉시 제외.
                ExtensionMiddlewareRegistry::flush();

                // 활성 모듈 디렉토리 전체 삭제 (_pending/_bundled에 원본 보존되므로 재설치 가능)
                $onProgress?->__invoke('files', __('extensions.progress.delete_files'));
                $preservedBackups = ExtensionPendingHelper::deleteExtensionDirectory(
                    $this->modulesPath,
                    $module->getIdentifier()
                );

                // 메모리에서 모듈 제거
                unset($this->modules[$module->getIdentifier()]);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        // 정규 훅 — CLI·어드민 UI 공통 단일 발화(#138). 커밋·파일 삭제가 끝난 뒤 try 밖에서 발화해
        // 리스너 예외가 이미 커밋된 제거를 롤백 경로로 보내지 않게 한다.
        if ($result) {
            HookManager::doAction('core.modules.after_uninstall', $moduleName, $moduleInfo, $deleteData);
        }

        return $result;
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
                            'js' => isset($builtPaths['js']) ? AssetUrl::moduleAsset($identifier, $builtPaths['js']) : null,
                            'css' => isset($builtPaths['css']) ? AssetUrl::moduleAsset($identifier, $builtPaths['css']) : null,
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
                    'integrations' => $this->enrichIntegrations($module->getIntegrations()),
                    'requires_core' => $module->getRequiredCoreVersion(),
                    'status' => 'uninstalled',
                    'is_pending' => false,
                    'is_bundled' => false,
                    'hidden' => $module->isHidden(),
                    ...$this->resolveIconFields($identifier, $module->getVersion()),
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
                    'integrations' => $this->enrichIntegrations($metadata['integrations'] ?? []),
                    'requires_core' => $metadata['g7_version'] ?? null,
                    'status' => 'uninstalled',
                    'is_pending' => true,
                    'is_bundled' => false,
                    'hidden' => (bool) ($metadata['hidden'] ?? false),
                    ...$this->resolveIconFields($identifier, $metadata['version'] ?? '0.0.0'),
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
                    'integrations' => $this->enrichIntegrations($metadata['integrations'] ?? []),
                    'requires_core' => $metadata['g7_version'] ?? null,
                    'status' => 'uninstalled',
                    'is_pending' => false,
                    'is_bundled' => true,
                    'hidden' => (bool) ($metadata['hidden'] ?? false),
                    ...$this->resolveIconFields($identifier, $metadata['version'] ?? '0.0.0'),
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
                            'js' => isset($builtPaths['js']) ? AssetUrl::moduleAsset($identifier, $builtPaths['js']) : null,
                            'css' => isset($builtPaths['css']) ? AssetUrl::moduleAsset($identifier, $builtPaths['css']) : null,
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
                    'integrations' => $this->enrichIntegrations($module->getIntegrations()),
                    'requires_core' => $module->getRequiredCoreVersion(),
                    'status' => $record->status,
                    'update_available' => $updateAvailable,
                    'latest_version' => $latestVersion,
                    'file_version' => $fileVersion,
                    'update_source' => $record->update_source ?? null,
                    // 업데이트 대상 버전의 코어 호환 — 업데이트 창의 경고·강제 업데이트 체크가 읽는다(업데이트가 있을 때만).
                    ...($updateAvailable ? $this->buildUpdateCompatibilityFields($this->resolveUpdateTargetRequiredCoreVersion(
                        $record->update_source ?? null,
                        $this->findBundledModuleManifest($identifier),
                        $module->getRequiredCoreVersion()
                    )) : []),
                    'github_url' => $module->getGithubUrl(),
                    'github_changelog_url' => $record->github_changelog_url ?? null,
                    'hidden' => $module->isHidden(),
                    ...$this->resolveIconFields($identifier, $record->version),
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
                    'js' => isset($builtPaths['js']) ? AssetUrl::moduleAsset($identifier, $builtPaths['js']) : null,
                    'css' => isset($builtPaths['css']) ? AssetUrl::moduleAsset($identifier, $builtPaths['css']) : null,
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
            'integrations' => $this->enrichIntegrations($module->getIntegrations()),
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
            ...$this->resolveIconFields($identifier, $module->getVersion()),
        ];
    }

    /**
     * 아직 설치되지 않은 모듈(pending·bundled)의 표시용 정보를 메타데이터에서 읽습니다.
     *
     * 설치 전이라 DB 행이 없으므로 목록 화면은 이 경로로만 이름·버전·설명을 얻는다. 권한·역할·훅
     * 같은 상세는 PHP 클래스를 임시 로드해야 알 수 있고, 로드가 실패해도 목록이 통째로 비지 않도록
     * 메타데이터만으로 만든 축소본을 돌려준다.
     *
     * @param  string  $moduleName  모듈 식별자
     * @return array<string, mixed>|null 모듈 정보, pending·bundled 어느 쪽도 아니면 null
     */
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
                'integrations' => $this->enrichIntegrations($module->getIntegrations()),
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
                ...$this->resolveIconFields($module->getIdentifier(), $module->getVersion()),
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
            'integrations' => $this->enrichIntegrations($metadata['integrations'] ?? []),
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
            ...$this->resolveIconFields($metadata['identifier'] ?? $moduleName, $metadata['version'] ?? '0.0.0'),
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

        $namespace = $this->convertDirectoryToNamespace($moduleName);
        $moduleClass = "Modules\\{$namespace}\\Module";

        try {
            // 같은 모듈의 활성 디렉토리 사본이 이미 로드돼 있으면 `_bundled`/`_pending`
            // 파일을 그대로 require 할 수 없다 — 같은 FQN 을 두 번 선언하게 되어
            // "Cannot declare class ..., because the name is already in use" 로 죽는다.
            // 이것은 Error 라 아래 catch(\Exception) 에 걸리지 않아 프로세스가 그대로 종료된다.
            //
            // 파일 내용을 임시 클래스명으로 eval 해 번들 쪽 메타데이터를 얻는다
            // (loadModuleFromDirectory / getFreshModuleInstance 와 동일한 처리).
            if (class_exists($moduleClass, false)) {
                return $this->evalFreshModule($moduleFile, $moduleClass, dirname($moduleFile));
            }

            require_once $moduleFile;

            if (class_exists($moduleClass)) {
                $module = new $moduleClass;
                if ($module instanceof ModuleInterface) {
                    return $module;
                }
            }
        } catch (\Throwable $e) {
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

    /**
     * 모듈이 선언한 의존성이 모두 충족됐는지 확인하고, 아니면 예외로 세웁니다.
     *
     * 선언은 `['modules' => [...], 'plugins' => [...]]` 중첩 구조라 타입별로 순회한다 — 이름만
     * 보면 같은 식별자를 가진 다른 종류의 확장이 의존성을 충족한 것으로 오판한다.
     *
     * @param  ModuleInterface  $module  검사 대상 모듈
     * @return void 반환값 없음
     *
     * @throws \Exception 충족되지 않은 의존성이 하나라도 있을 때
     */
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
     * @return \Generator<string, string> identifier => 'module'|'plugin'
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
     * 선택 연동 선언(`integrations`)을 화면 표시용으로 보강합니다 (#639).
     *
     * @param  mixed  $integrations  manifest 의 integrations 값
     * @return array<int, array<string, mixed>> 보강된 연동 목록
     */
    protected function enrichIntegrations(mixed $integrations): array
    {
        return DependencyEnricher::enrichIntegrations($integrations);
    }

    /**
     * 모듈의 마이그레이션 파일들을 실행합니다.
     *
     * @param  ModuleInterface  $module  마이그레이션을 실행할 모듈 인스턴스
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
        $settingsDir = ExtensionStoragePath::module($identifier, 'settings');

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

        // 디렉토리 생성 — sudo 코어 업데이트(번들 확장 업데이트 프롬프트) 경로에서 root 로 만들어지면
        // `storage/app/modules` 는 restore_ownership 제외 경로라 되돌려지지 않는다 → 부모 소유권 상속 (#651 F13)
        if (! File::isDirectory($settingsDir)) {
            File::makeDirectory($settingsDir, 0755, true);
            FilePermissionHelper::inheritOwnershipFromParent($settingsDir);
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
            FilePermissionHelper::inheritOwnershipFromParent($filePath);
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
     */
    protected function deleteModuleStorage(ModuleInterface $module): void
    {
        $moduleStoragePath = ExtensionStoragePath::module($module->getIdentifier());

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
     * @return void 반환값 없음
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
            ExtensionStoragePath::module($identifier)
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     *   7. 알림 정의/템플릿 (`getNotificationDefinitions`) — 본인인증 알림 포함.
     *      옛 `getIdentityMessages()` 선언은 7.1.0 부터 여기서 알림 정의로 변환·합류한다(호환층).
     *
     * `installModule` / `updateModule` 트랜잭션 내부에서 호출되어 모듈 디스크 상태와 DB 를
     * 정합 상태로 유지한다. 외부 호출 진입점으로도 노출되어 코어 업그레이드 사후 보정
     * (`Upgrade_7_0_0_beta_4` 등) 이나 운영자 수동 재시드 도구가 사용 가능.
     *
     * 각 sync 메서드는 helper 내부의 user_overrides 보존 패턴을 따르므로 정상 환경 재호출
     * 무해 (멱등).
     *
     * @param  ModuleInterface  $module  대상 모듈 인스턴스
     * @return void 반환값 없음
     */
    public function syncDeclarativeArtifacts(ModuleInterface $module): void
    {
        $this->createModuleRoles($module);
        $this->createModulePermissions($module);
        $this->assignPermissionsToRoles($module);
        $this->createModuleMenus($module);
        $this->cleanupStaleModuleEntries($module);
        $this->syncModuleIdentityPolicies($module);
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
     * @return array{synced: array<int, string>, skipped: array<int, string>, failed: array<string, string>} 동기화 결과 — 성공·건너뜀 식별자와 실패 사유
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
     * 설치·활성 상태인 모듈 전체의 알림 정의만 다시 동기화합니다 (7.1.0).
     *
     * 알림 채널을 제공하는 확장이 켜지는 순간, 이미 저장된 다른 확장의 알림 정의에도 공통 시딩 훅
     * (`NotificationSyncHelper::SEED_DEFINITIONS_FILTER`)이 다시 발화하도록 하는 공개 진입점이다.
     * 설치본(활성 디렉토리에서 로드된 인스턴스)의 `getNotificationDefinitions()` 만 읽고 `_bundled` 는
     * 읽지 않는다. 역할·권한·메뉴 같은 다른 선언형 산출물은 건드리지 않는다.
     *
     * 동기화는 user_overrides 보존 upsert 이므로 운영자가 고친 제목·본문·수신자는 그대로 남는다.
     * 한 모듈의 실패는 로그만 남기고 나머지를 계속 처리한다.
     *
     * @return array{synced: array<int, string>, failed: array<string, string>} 동기화한 식별자와 실패 사유
     */
    public function resyncActiveModuleNotificationDefinitions(): array
    {
        $synced = [];
        $failed = [];

        foreach ($this->getActiveModules() as $module) {
            $identifier = $module->getIdentifier();

            try {
                $this->syncModuleNotificationDefinitions($module);
                $synced[] = $identifier;
            } catch (\Throwable $e) {
                $failed[$identifier] = $e->getMessage();
                Log::warning('[resyncActiveModuleNotificationDefinitions] 알림 정의 재동기화 실패', [
                    'module' => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['synced' => $synced, 'failed' => $failed];
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
     * @return void 반환값 없음
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
     * (호환층) 확장의 `getIdentityMessages()` 선언을 알림 정의 선언에 합칩니다.
     *
     * 7.1.0 부터 본인인증 메시지는 알림 정의(category=identity)다. 옛 계약으로 선언한 확장도 동작하도록
     * 변환해 합치되, 같은 type 을 `getNotificationDefinitions()` 가 이미 선언했으면 그쪽을 따른다.
     *
     * @deprecated 7.1.0 — 8.0 에서 `getIdentityMessages()` 지원과 함께 제거.
     *
     * @param  ModuleInterface  $module  대상 모듈
     * @param  array<int|string, array<string, mixed>>  $definitions  알림 정의 선언
     * @return array<int|string, array<string, mixed>> 본인인증 메시지가 합쳐진 선언
     */
    protected function mergeLegacyIdentityMessages(ModuleInterface $module, array $definitions): array
    {
        if (! method_exists($module, 'getIdentityMessages')) {
            return $definitions;
        }

        $messages = $module->getIdentityMessages();
        if (! is_array($messages) || $messages === []) {
            return $definitions;
        }

        $declaredTypes = array_filter(array_map(
            static fn ($d) => is_array($d) ? ($d['type'] ?? null) : null,
            $definitions
        ));

        $converted = app(IdentityMessageDeclarationConverter::class)
            ->convert($messages, 'module', $module->getIdentifier());

        foreach ($converted as $declaration) {
            if (! in_array($declaration['type'], $declaredTypes, true)) {
                $definitions[] = $declaration;
            }
        }

        return $definitions;
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
     * 저장 직전 언어팩 필터(`seed.{id}.notifications.translations`) 다음에 공통 시딩 훅
     * (`NotificationSyncHelper::SEED_DEFINITIONS_FILTER`)을 거친다 — 코어 시더와 같은 훅이다.
     *
     * @param  ModuleInterface  $module  알림 정의를 동기화할 모듈 인스턴스
     * @return void 반환값 없음
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

        // (호환층, @deprecated 7.1.0 — 8.0 제거) 7.0.x 계약 `getIdentityMessages()` 로 선언한 본인인증 메시지를
        // 알림 정의(category=identity)로 바꿔 합류시킨다. 언어팩 필터보다 먼저 합쳐야 type 키로 번역이 붙는다.
        $definitions = $this->mergeLegacyIdentityMessages($module, $definitions);

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

        // 공통 시딩 훅 — 채널 제공 확장이 소유자와 무관하게 자기 채널 template 을 더한다.
        // 도메인별 훅(seed.{id}.notifications.translations)은 언어팩 병합 전용이다.
        $definitions = $helper->applySeedDefinitionsFilter($definitions, 'module', $module->getIdentifier());

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
     * @param  string  $sourceType  정책 등록 주체 유형
     * @param  string  $sourceIdentifier  정책 등록 주체 식별자
     * @return bool 기존 정책이 있으면 true
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
     * 해당 source 가 기존에 등록한 알림 정의가 있는지 확인합니다 (데이터 손실 방어용).
     *
     * @param  string  $extensionType  확장 유형
     * @param  string  $extensionIdentifier  확장 식별자
     * @return bool 기존 알림 정의가 있으면 true
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
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return bool 기존 메뉴가 있으면 true
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
     * @param  ModuleInterface  $module  정의를 읽을 모듈 인스턴스
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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

        return $this->countInstallableLayouts(base_path($basePath));
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * 모듈이 선언한 훅 리스너를 등록합니다.
     *
     * 리스너를 등록해 두고 ServiceProvider 가 없으면 훅 발화 시 Repository 바인딩을 찾지 못해
     * 컨테이너 해석이 실패한다. 테스트에서는 등록 행이 프로세스 간 DB 에 남을 수 있어 "활성"
     * 판정만으로는 부족하므로 allowlist 를 함께 본다.
     *
     * @param  ModuleInterface  $module  대상 모듈
     * @return void 반환값 없음
     */
    protected function registerModuleHookListeners(ModuleInterface $module): void
    {
        // 모듈이 getHookListeners 메서드를 가지고 있는지 확인
        if (! method_exists($module, 'getHookListeners')) {
            return;
        }

        // 테스트 allowlist 확인 — allowlist 밖 모듈은 ServiceProvider 가 등록되지 않으므로
        // 리스너만 등록하면 훅 발화 시 Repository 바인딩이 없어 컨테이너 해석이 실패한다.
        // (모듈 등록 행은 테스트 프로세스 간 DB 에 남을 수 있어 활성 판정만으로는 부족하다)
        if (ExtensionTestAllowlist::isActive() && ! ExtensionTestAllowlist::isAllowed('module', $module->getIdentifier())) {
            return;
        }

        // 모듈 활성화 상태 확인 (비활성화된 모듈의 훅은 등록하지 않음)
        $activeIdentifiers = self::getActiveModuleIdentifiers();
        if (! in_array($module->getIdentifier(), $activeIdentifiers, true)) {
            return;
        }

        // 캐시 우선: 사전 계산된 훅 매핑이 있으면 클래스 로딩 없이 등록.
        if ($this->registerExtensionHookListenersFromCache('modules', $module->getIdentifier())) {
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
     * 훅 캐시에서 확장(모듈/플러그인)의 정적 훅 리스너를 등록합니다.
     *
     * 캐시 파일(bootstrap/cache/hooks.php)에 해당 확장 식별자의 항목이 있으면
     * getSubscribedHooks() 클래스 로딩·리플렉션 없이 사전 계산 매핑으로 등록한다.
     * 테스트 환경은 매 setUp 스캔이 정확·격리 우선이므로 캐시 미사용(항상 스캔 폴백).
     *
     * @param  string  $bucket  캐시 버킷 ('modules' 또는 'plugins')
     * @param  string  $identifier  확장 식별자
     * @return bool 캐시로 등록했으면 true, 캐시 부재/해당 항목 없음/테스트 환경이면 false (스캔 폴백)
     */
    protected function registerExtensionHookListenersFromCache(string $bucket, string $identifier): bool
    {
        if (app()->environment('testing')) {
            return false;
        }

        $cache = app(HookCacheManager::class)->read();

        if ($cache === null || ! isset($cache[$bucket][$identifier])) {
            return false;
        }

        foreach ($cache[$bucket][$identifier] as $entry) {
            try {
                HookListenerRegistrar::registerFromCache($entry['listener'], $entry['hooks'], $identifier);
            } catch (\Throwable $e) {
                Log::error('확장 훅 리스너 캐시 등록 중 오류 발생', [
                    'listener' => $entry['listener'] ?? null,
                    'identifier' => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return true;
    }

    /**
     * 모듈의 브로드캐스트 채널을 등록합니다.
     *
     * 모듈의 getChannels() 메서드에서 정의한 채널을
     * Broadcast::channel()로 자동 등록합니다.
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     * @return void 반환값 없음
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
     * 모듈 레이아웃 파일을 등록 없이 검증합니다.
     *
     * 활성화가 상태를 쓰기 전에 부른다 — 등록 단계와 같은 검증({@see validateLayoutFiles()})이라 결과를
     * {@see registerModuleLayouts()} 에 넘겨 다시 검증하지 않는다. 빈 자리로 등록할 조각 참조 경고도 여기서 쌓인다.
     *
     * @param  string  $moduleName  모듈 디렉토리명 (vendor-name 형식)
     * @return array<int, array{file: string, data: array<string, mixed>, original: array<string, mixed>, layout_name: string}>|null 검증된 레이아웃, 모듈이 없거나 레이아웃 디렉토리가 없으면 null
     *
     * @throws \Exception 레이아웃 검증 실패 시
     */
    protected function validateModuleLayoutFiles(string $moduleName): ?array
    {
        $module = $this->getModule($moduleName);
        $layoutsPath = base_path("modules/{$moduleName}/resources/layouts");

        if (! $module || ! File::exists($layoutsPath)) {
            return null;
        }

        return $this->validateLayoutFiles($layoutsPath, $module->getIdentifier(), 'module', true);
    }

    /**
     * 모듈의 레이아웃을 활성화된 admin/user 템플릿에 등록합니다.
     *
     * 레이아웃 파일의 디렉토리 위치에 따라 대상 템플릿 타입이 결정됩니다
     * (소스는 `.g7.html` 소스 우선 — {@see ValidatesLayoutFiles::collectLayoutSources()}):
     * - layouts/admin/*.{g7.html,json} → admin 타입 템플릿에 등록
     * - layouts/user/*.{g7.html,json} → user 타입 템플릿에 등록
     * - layouts/*.{g7.html,json} (루트) → 스킵 (경고 로그 출력)
     *
     * @param  string  $moduleName  모듈 디렉토리명 (vendor-module 형식)
     * @param  array<int, array{file: string, data: array<string, mixed>, original: array<string, mixed>, layout_name: string}>|null  $validatedLayouts  미리 검증한 결과 ({@see validateModuleLayoutFiles()}) — null 이면 여기서 검증
     * @return int 등록된 레이아웃 개수
     *
     * @throws \Exception 레이아웃 검증 실패 시
     */
    protected function registerModuleLayouts(string $moduleName, ?array $validatedLayouts = null): int
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
        $validatedLayouts ??= $this->validateLayoutFiles($layoutsPath, $identifier, 'module', true);

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

                // 해당 타입 템플릿에 레이아웃 등록 — 활성화·템플릿 전환은 keep 규칙(D3): 운영자 수정은 보존하고,
                // 파일이 원본과 같으면(버전 변경 없음) 그 원본을 기준으로 삼아 운영자 판을 그대로 둔다.
                foreach ($targetTemplates as $template) {
                    $entry = $this->registerLayoutToTemplate($template, $layoutName, $layoutData, $identifier, $validatedLayout['original']);
                    $this->warnActivationConflict($entry, $identifier);
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
     * 레이아웃을 특정 템플릿에 등록합니다 — 행 쓰기는 {@see LayoutRowSynchronizer} 를 지납니다.
     *
     * @param  Template  $template  템플릿 모델
     * @param  string  $layoutName  레이아웃 이름
     * @param  array  $layoutData  레이아웃 데이터 (서비스용)
     * @param  string  $moduleIdentifier  모듈 식별자
     * @param  array|null  $layoutOriginal  조각 출처 표식이 있는 원본 (없으면 $layoutData)
     * @param  LayoutSyncMode  $mode  동기화 모드 (활성화는 Keep)
     * @param  array|null  $backupOriginal  업데이트 백업을 컴파일한 이전 판 원본
     * @return LayoutSyncEntry 결과
     */
    protected function registerLayoutToTemplate(Template $template, string $layoutName, array $layoutData, string $moduleIdentifier, ?array $layoutOriginal = null, LayoutSyncMode $mode = LayoutSyncMode::Keep, ?array $backupOriginal = null): LayoutSyncEntry
    {
        return $this->layoutSynchronizer()->syncLayout(
            [
                'template_id' => $template->id,
                'template' => (string) $template->identifier,
                'name' => $layoutName,
                'source_type' => LayoutSourceType::Module,
                'source_identifier' => $moduleIdentifier,
                'track_extends' => true,
            ],
            $layoutData,
            $layoutOriginal ?? $layoutData,
            $mode,
            $backupOriginal
        );
    }

    /**
     * 모듈 레이아웃 관련 캐시를 무효화합니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return void 반환값 없음
     */
    protected function invalidateLayoutCache(string $moduleIdentifier): void
    {
        $this->invalidateExtensionLayoutCache($moduleIdentifier, 'module');
    }

    /**
     * 모듈의 레이아웃을 파일에서 다시 읽어 DB에 갱신합니다.
     *
     * 활성화된 모듈의 레이아웃 파일을 다시 스캔해 admin/user 디렉토리 위치에 맞는 템플릿에 동기화한다. 모든 행 쓰기는
     * {@see LayoutRowSynchronizer} 를 지난다 — keep 이면 운영자가 고친 화면은 조각 단위로 병합하고, 새 버전에서 빠진
     * 화면(layouts 디렉토리째 빠진 경우 포함)이라도 운영자 수정본이면 남긴다(D4). 삭제 후보는 이전 판 파일 목록에 있던
     * 이름뿐이다(W15). overwrite 면 새 판으로 덮는다(수동 「레이아웃 갱신」).
     *
     * @param  string  $moduleName  모듈명
     * @param  bool  $preserveModified  운영자가 고친 화면을 보존(병합)할지 여부 (keep)
     * @param  string|null  $backupPath  업데이트 백업 디렉토리 — 원본 본문이 없는 행의 비교 기준을 만든다
     * @param  LayoutSyncReport|null  $report  결과를 모을 보고 (업데이트 경로가 조각 사전 재등록 결과와 합칠 때)
     * @return array{success: bool, layouts_refreshed: int, created: int, updated: int, deleted: int, unchanged: int, skipped: int, extensions_refreshed?: int, layout_sync: array<string, mixed>, layout_warnings: array<int, array<string, string>>} 갱신 결과 (빈 자리로 등록한 조각 참조 경고 포함)
     *
     * @throws \Exception 모듈을 찾을 수 없거나 레이아웃 갱신 실패 시
     */
    public function refreshModuleLayouts(string $moduleName, bool $preserveModified = false, ?string $backupPath = null, ?LayoutSyncReport $report = null): array
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
        $mode = LayoutSyncMode::fromPreserve($preserveModified);
        $report ??= new LayoutSyncReport($mode);

        // 활성화된 admin/user 타입 템플릿 조회
        $adminTemplates = $this->templateRepository->getActiveByType('admin');
        $userTemplates = $this->templateRepository->getActiveByType('user');

        if ($adminTemplates->isEmpty() && $userTemplates->isEmpty()) {
            Log::warning("활성화된 admin/user 템플릿이 없어 모듈 레이아웃을 갱신할 수 없습니다: {$moduleName}");

            return ['success' => true, 'layouts_refreshed' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0, 'skipped' => 0, 'layout_sync' => $report->toArray(), 'layout_warnings' => []];
        }

        $stats = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0, 'skipped' => 0];
        $synchronizer = $this->layoutSynchronizer();
        $backupOriginals = $synchronizer->compileBackupOriginals($backupPath, 'modules', $identifier);
        $previousNames = $backupOriginals !== [] ? array_keys($backupOriginals) : null;

        // 레이아웃 검증 (등록 전 모든 레이아웃 파일 유효성 검사). 디렉토리째 사라진 새 버전이면 빈 목록 —
        // 모든 행이 "새 버전에서 빠진 행" 규칙을 탄다(L17, 종전엔 수정본까지 전부 영구 삭제).
        $validatedLayouts = [];
        $layoutWarnings = [];

        if (File::exists($layoutsPath)) {
            $validatedLayouts = $this->validateLayoutFiles($layoutsPath, $identifier, 'module', true);

            // 7.0 JSON 레이아웃의 깨진 조각 참조는 빈 자리로 등록된다 — 결과에 실어 알린다 (#712).
            // 검증이 돈 경우에만 꺼낸다 — 디렉토리가 없을 때 꺼내면 다른 확장이 남긴 경고가 섞인다.
            $layoutWarnings = $this->takeLayoutIncludeWarnings();
        }

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
            $typeLayoutNames = array_map(fn ($layout) => $identifier.'.'.$layout['layout_name'], $typeLayouts);

            foreach ($templates as $template) {
                foreach ($typeLayouts as $validatedLayout) {
                    $layoutName = $identifier.'.'.$validatedLayout['layout_name'];

                    try {
                        $entry = $this->registerLayoutToTemplate($template, $layoutName, $validatedLayout['data'], $identifier, $validatedLayout['original'], $mode, $backupOriginals[$layoutName] ?? null);
                        $this->tallyLayoutSync($stats, $entry);
                        $report->add($entry);
                    } catch (\Exception $e) {
                        Log::error("모듈 레이아웃 동기화 실패: {$validatedLayout['file']}", [
                            'module' => $identifier,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // DB에만 있고 파일에 없는 레이아웃 — 이전 판 파일 목록에 있던 이름만 삭제 후보, 운영자 수정본은 keep 에서 남긴다.
                $existingLayouts = $this->layoutRepository->getByTemplateIdWithFilter($template->id, 'module', $identifier);

                foreach ($existingLayouts as $layout) {
                    if (in_array($layout->name, $typeLayoutNames, true)) {
                        continue;
                    }

                    $entry = $synchronizer->removeMissingLayout(
                        $layout,
                        (string) $template->identifier,
                        $mode,
                        $synchronizer->isRemovalCandidate($layout, $previousNames, $identifier.'.')
                    );
                    $this->tallyLayoutSync($stats, $entry);
                    $report->add($entry);
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
            'skipped' => $stats['skipped'],
        ]);

        // 레이아웃 확장(extension)은 모든 활성 템플릿에 적용될 수 있으므로
        // admin 템플릿뿐만 아니라 모든 활성 템플릿에 대해 갱신
        $allActiveTemplates = $this->templateRepository->getActive();
        $extensionStats = $this->refreshLayoutExtensions($module, $allActiveTemplates, $preserveModified, $backupPath, $report);

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
            'skipped' => $stats['skipped'] + ($extensionStats['skipped'] ?? 0),
            'extensions_refreshed' => $extensionStats['refreshed'] ?? 0,
            'layout_sync' => $report->toArray(),
            'layout_warnings' => $layoutWarnings,
        ];
    }

    /**
     * 모듈의 레이아웃 확장을 파일에서 다시 읽어 DB에 갱신합니다.
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     * @param  Collection  $adminTemplates  admin 템플릿 컬렉션
     * @param  bool  $preserveModified  관리자가 편집한 확장을 보존(병합)할지 여부 (--layout-strategy=keep)
     * @param  string|null  $backupPath  업데이트 백업 디렉토리
     * @param  LayoutSyncReport|null  $report  결과를 모을 보고
     * @return array{refreshed: int, created: int, updated: int, deleted: int, skipped: int} 갱신 통계
     */
    protected function refreshLayoutExtensions(ModuleInterface $module, $adminTemplates, bool $preserveModified = false, ?string $backupPath = null, ?LayoutSyncReport $report = null): array
    {
        return $this->refreshExtensionLayoutExtensions($module, $adminTemplates, LayoutSourceType::Module, $preserveModified, $backupPath, $report);
    }

    /**
     * 모듈의 레이아웃 확장을 등록합니다.
     *
     * 모듈의 resources/extensions 디렉토리에서 fragment 소스를 읽어
     * 활성화된 모든 템플릿(admin, user)에 확장을 등록합니다 — 붙을 자리가 있는 템플릿에만(컴파일은 템플릿 무관).
     *
     * 소스 선택·읽기는 {@see CollectsFragmentSources::loadFragmentDefinitions()}
     * 가 담당한다 — 종전에는 여기서 `File::get`+`json_decode` 로 직행해 소스(`.g7.html`)을
     * 보지 않았고, 그래서 활성화 경로만 규약 밖에 남아 있었다.
     *
     * @param  ModuleInterface  $module  모듈 인스턴스
     * @param  bool  $preserveModified  관리자 수정 보존(병합) 여부 — 활성화는 keep, 업데이트는 그 업데이트의 전략
     * @param  string|null  $backupPath  업데이트 백업 디렉토리 (원본 본문이 없는 조각 행의 비교 기준)
     * @param  LayoutSyncReport|null  $report  결과를 모을 보고
     * @return void 반환값 없음
     */
    protected function registerLayoutExtensions(ModuleInterface $module, bool $preserveModified = true, ?string $backupPath = null, ?LayoutSyncReport $report = null): void
    {
        $extensionFiles = $module->getLayoutExtensions();
        $identifier = $module->getIdentifier();

        // 활성화된 모든 템플릿 조회 (admin + user)
        $allTemplates = $this->templateRepository->getActive();

        if ($allTemplates->isEmpty()) {
            Log::warning("활성화된 템플릿이 없어 모듈 레이아웃 확장을 등록할 수 없습니다: {$identifier}");

            return;
        }

        // 붙을 자리(호스트 레이아웃·확장점)가 없는 템플릿에는 등록하지 않는다 (C10). 종전엔 모든 활성 템플릿에
        // 무조건 등록해 사용자 템플릿에 관리자 대상 조각(죽은 행)이 쌓였다. 조각 컴파일 자체는 템플릿과 무관하다
        // (JSON 과 동일 — 2026-09-28 결정). 호스트가 나중에 설치되는 순서 의존은 호출부가
        // `registerAllActiveExtensionsToTemplate` 재호출로 메운다.
        // 소스 디렉토리에 실재하는 조각 키 — 고아 정리는 이 집합으로 판정한다(컴파일 실패 조각의 행은 보존).
        $liveKeys = $this->fragmentSourceKeys($module->getExtensionsPath(), $extensionFiles);

        foreach ($allTemplates as $template) {
            $backupFragments = $this->layoutSynchronizer()->compileBackupFragments($backupPath, $identifier, (string) $template->identifier);
            $definitions = $this->loadFragmentDefinitions(
                $module->getExtensionsPath(),
                $extensionFiles,
                ['module' => $identifier, 'template' => $template->identifier],
                (string) $template->identifier
            );

            // 등록 + 백필된 옛 키 행 잇기 + 오등록·고아 행 정리를 한 번에 한다 (#703) — 등록만 하면 7.0.11 에서
            // 올라온 설치의 옛 키 행과 재활성화가 되살린 행이 새 행과 함께 살아 같은 조각이 두 번 주입된다.
            // 활성화는 keep(운영자 수정 보존), 업데이트는 그 업데이트의 전략(keep/overwrite)을 따르고 백업의 이전 판을 병합 기준으로 쓴다.
            $stats = $this->layoutExtensionService->syncSourceFragmentsToTemplate(
                $definitions,
                $liveKeys,
                LayoutSourceType::Module,
                $identifier,
                $template->id,
                $preserveModified,
                $backupFragments,
                $report
            );

            Log::info('모듈 레이아웃 확장 등록 완료', [
                'module' => $identifier,
                'template' => $template->identifier,
                'registered' => $stats['registered'],
                'skipped_no_host' => $stats['inapplicable'],
                'deleted' => $stats['deleted'],
            ]);
        }
    }

    /**
     * 활성 확장 전부의 조각을 모든 활성 템플릿에 다시 등록합니다 (순서 의존 완화, 멱등).
     *
     * 적용 필터는 "호스트 레이아웃이 아직 없으면 건너뜀" 이라, 호스트를 가진 확장이 나중에 설치되면 그 호스트를
     * 겨냥한 다른 확장의 조각이 빠진 채 남는다. 그래서 확장 활성화·업데이트는 자기 등록을 마친 직후 이 메서드로
     * 전 템플릿을 한 번 더 훑는다 — 등록은 `updateOrCreate` 라 여러 번 불러도 같은 결과다.
     *
     * @return void 반환값 없음
     */
    protected function reregisterActiveExtensionsToAllTemplates(): void
    {
        foreach ($this->templateRepository->getActive() as $template) {
            try {
                $this->layoutExtensionService->registerAllActiveExtensionsToTemplate($template->id);
            } catch (\Throwable $e) {
                Log::error('활성 확장 조각 재등록 실패', [
                    'template' => $template->identifier,
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
     * @return void 반환값 없음
     */
    protected function restoreLayoutExtensions(ModuleInterface $module): void
    {
        try {
            // 소스에 실재하는 조각 키의 행만 되살린다 — 고아 정리가 지운 옛 행까지 살리면 두 번 주입된다 (#703).
            // 키 집합은 조각 디렉토리를 규약(CollectsFragmentSources)으로 직접 훑어 얻는다. 목록 밖 행은 뒤따르는
            // 등록이 withTrashed 조회로 필요한 만큼 되살린다.
            $restoredCount = $this->layoutExtensionService->restoreBySource(
                LayoutSourceType::Module,
                $module->getIdentifier(),
                $this->fragmentSourceKeys($module->getExtensionsPath(), [])
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * @param  bool  $force  이미 활성 디렉터리에 있어도 덮어쓸지 여부
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
            if ($module !== null) {
                $this->rememberFreshExtensionInstance($moduleClass, $moduleFile, $module);
            }
        } else {
            // 최초 로드 (클래스가 아직 없는 경우)
            require_once $moduleFile;
            $this->rememberLoadedExtensionClassFile($moduleClass, $moduleFile);

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

            // 확장 미들웨어 인덱스 무효화 — 재로드된 모듈의 갱신된 미들웨어 선언 반영.
            ExtensionMiddlewareRegistry::flush();
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
     * 일반 업데이트 우선순위:
     *   1. GitHub URL 존재 + API 조회 성공
     *      a. 소스 미지정이고 번들 판이 GitHub 최신·현재보다 높음 → 'bundled' 소스 반환 (코어와 함께 배포된 번들이 호환 기준 —
     *         확장 공개 릴리스가 코어 출시보다 늦을 때 「업데이트 없음」으로 끝나던 문제)
     *      b. GitHub 버전 > 현재 → 'github' 소스 반환
     *      c. 그 밖 → "업데이트 없음"
     *   2. GitHub URL 없음 OR API 조회 실패 → _bundled 폴백 (안전망, `github` 지정이면 폴백하지 않음)
     *   `bundled` 지정이면 GitHub 를 보지 않고 번들만 비교한다 (CLI `--source`).
     *
     * --force 업데이트 우선순위는 resolveForceUpdateSource() 참조 (번들 우선).
     *
     * 참고: _pending 디렉토리는 install 경로에서만 사용되며 update 에서는 참조하지 않음.
     *
     * @param  string  $identifier  모듈 식별자
     * @param  string|null  $source  소스 지정 (`bundled`·`github`, null 이면 자동 — GitHub·번들 중 높은 버전)
     * @return array{update_available: bool, update_source: string|null, latest_version: string|null, current_version: string|null} 업데이트 확인 결과
     */
    public function checkModuleUpdate(string $identifier, ?string $source = null): array
    {
        $record = $this->moduleRepository->findByIdentifier($identifier);
        if (! $record) {
            return $this->buildModuleUpdateResponse(false, null, null, null, null);
        }

        $currentVersion = $record->version;
        $module = $this->getModule($identifier);
        $activeRequiredCoreVersion = $module ? $module->getRequiredCoreVersion() : null;

        // 1. GitHub URL이 있으면 GitHub에서 최신 버전 확인 (번들 지정이면 건너뜀)
        if ($source !== 'bundled' && $module && $module->getGithubUrl()) {
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
                // 소스를 지정하지 않았으면 코어와 함께 배포된 번들이 공개 최신보다 새 판일 때 번들을 후보로 본다 — 확장 공개
                // 릴리스가 코어 출시보다 늦으면 GitHub 결과만 믿어 「업데이트 없음」으로 끝났다(W3 실측: 번들 1.1.0, 공개 1.0.4).
                $bundled = $source === null ? $this->bundledModuleUpdateCandidate($identifier, $activeRequiredCoreVersion) : null;
                if ($bundled !== null
                    && version_compare($bundled['version'], $latestVersion, '>')
                    && version_compare($bundled['version'], $currentVersion, '>')) {
                    return $this->buildModuleUpdateResponse(true, 'bundled', $bundled['version'], $currentVersion, $bundled['required']);
                }

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

        // github 지정 — 번들로 폴백하지 않는다(문서: GitHub 단독).
        if ($source === 'github') {
            return $this->buildModuleUpdateResponse(false, null, $currentVersion, $currentVersion, $activeRequiredCoreVersion);
        }

        // 2. _bundled에서 업데이트 확인
        if (isset($this->bundledModules[$identifier])) {
            $bundledVersion = $this->bundledModules[$identifier]['version'] ?? null;
            $bundledRequired = $this->resolveUpdateTargetRequiredCoreVersion('bundled', $this->bundledModules[$identifier], $activeRequiredCoreVersion);
            if ($bundledVersion && version_compare($bundledVersion, $currentVersion, '>')) {
                return $this->buildModuleUpdateResponse(
                    true, 'bundled', $bundledVersion, $currentVersion, $bundledRequired
                );
            }
        } else {
            $bundledMeta = ExtensionPendingHelper::loadBundledExtensions($this->modulesPath, 'module.json');
            if (isset($bundledMeta[$identifier])) {
                $bundledVersion = $bundledMeta[$identifier]['version'] ?? null;
                $bundledRequired = $this->resolveUpdateTargetRequiredCoreVersion('bundled', $bundledMeta[$identifier], $activeRequiredCoreVersion);
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
     * 번들(`_bundled`) 판 모듈의 버전과 코어 요구 버전을 돌려줍니다 — 번들에 없으면 null.
     *
     * @param  string  $identifier  모듈 식별자
     * @param  string|null  $activeRequiredCoreVersion  설치본의 코어 요구 버전 (번들 manifest 에 없을 때의 폴백)
     * @return array{version: string, required: string|null}|null 번들 후보
     */
    private function bundledModuleUpdateCandidate(string $identifier, ?string $activeRequiredCoreVersion): ?array
    {
        $meta = $this->bundledModules[$identifier]
            ?? (ExtensionPendingHelper::loadBundledExtensions($this->modulesPath, 'module.json')[$identifier] ?? null);
        $version = is_array($meta) ? ($meta['version'] ?? null) : null;

        if (! is_string($version) || $version === '') {
            return null;
        }

        return ['version' => $version, 'required' => $this->resolveUpdateTargetRequiredCoreVersion('bundled', $meta, $activeRequiredCoreVersion)];
    }

    /**
     * checkModuleUpdate 응답 페이로드 빌더 (호환성 메타 부착).
     *
     * @param  bool  $updateAvailable  업데이트 가용 여부
     * @param  string|null  $updateSource  업데이트 소스 (github|bundled|null)
     * @param  string|null  $latestVersion  최신 버전
     * @param  string|null  $currentVersion  현재 설치된 버전
     * @param  string|null  $requiredCoreVersion  요구 코어 버전 제약
     * @return array{update_available: bool, update_source: ?string, latest_version: ?string, current_version: ?string, required_core_version: ?string, is_compatible: bool, current_core_version: string} 업데이트 확인 응답(코어 호환 필드 포함)
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
            ...$this->buildUpdateCompatibilityFields($requiredCoreVersion),
        ];
    }

    /**
     * 설치된 모든 모듈의 업데이트를 확인하고 DB를 갱신합니다.
     *
     * @return array{updated_count: int, details: array} 갱신 건수와 모듈별 결과
     */
    public function checkAllModulesForUpdates(): array
    {
        $moduleRecords = $this->moduleRepository->getAllKeyedByIdentifier();
        $details = [];
        $updatedCount = 0;
        $checkedCount = 0;

        foreach ($moduleRecords as $identifier => $record) {
            $result = $this->checkModuleUpdate($identifier);
            $checkedCount++;

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
                    'update_available' => true,
                    'current_version' => $result['current_version'],
                    'latest_version' => $result['latest_version'],
                    'update_source' => $result['update_source'],
                ];
            }
        }

        return [
            'updated_count' => $updatedCount,
            'checked_count' => $checkedCount,
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
            ExtensionPendingHelper::ensureUpdateTempDirectory($tempDir);

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
     * @return void 반환값 없음
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

        // 확장 업그레이드는 코어(sudo/root)와 다른 실행 주체(php-fpm/www-data)로 실행되므로
        // 코어 'upgrade' 채널과 로그 파일을 분리한다. 같은 파일 공유 시 root 소유 파일에
        // www-data 가 append 하지 못해 Permission denied 로 실패하던 결함을 원천 차단.
        $context = new UpgradeContext(
            fromVersion: $fromVersion,
            toVersion: $toVersion,
            logChannel: 'extension-upgrade',
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
     * @return array{has_modified_layouts: bool, modified_count: int, modified_layouts: array} 수정한 레이아웃 존재 여부·개수·목록
     */
    public function hasModifiedLayouts(string $identifier): array
    {
        // 레이아웃 + 이 모듈의 확장 조각 수정본을 한 목록으로 (§5.9 — 종전 API 목록에는 조각이 없었다)
        return $this->buildModifiedLayoutsReport(
            $this->layoutRepository->getBySourceIdentifier($identifier, LayoutSourceType::Module),
            $this->layoutExtensionRepository->getBySource(LayoutSourceType::Module, $identifier)
        );
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
     * @return array{success: bool, from_version: string|null, to_version: string|null, message: string} 업데이트 결과(성공 여부·이전/이후 버전·메시지)
     *
     * @throws \RuntimeException 업데이트 실패 시
     * @throws ExtensionOperationInProgressException 같은 확장의 업데이트가 이미 진행 중일 때 (409)
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
    ): array {
        // 같은 모듈의 업데이트는 한 번에 하나만 — 상태 가드(status=updating)가 서기 전 구간에 들어온 두 번째 요청이
        // 함께 통과해 업데이트가 두 번 수행됐다(#138 실측 ② 버튼 더블클릭). 직전 훅보다 먼저 잠그고, 이 지역 변수가
        // 메서드를 빠져나갈 때(정상 반환·예외 모두) 잠금이 풀린다.
        $operationLock = ExtensionOperationLock::acquire('module', $identifier);

        // 직전 훅 — CLI·어드민 UI 공통으로 한 번 발화한다(예전에는 Service 에서만 발화해 CLI 가 빠졌다, #138 후속)
        HookManager::doAction('core.modules.before_update', $identifier);

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
            $result = [
                'success' => false,
                'from_version' => $fromVersion,
                'to_version' => $fromVersion,
                'message' => __('modules.no_update_available'),
            ];

            // "업데이트할 것 없음" 도 정규 훅을 발화한다 — 어드민 UI 는 종전부터 이 결과를 활동 로그에
            // 남겨 왔다(Service 발화 시절 동작 보존). 구훅(updated)은 발화하지 않는다.
            HookManager::doAction('core.modules.after_update', $identifier, $result, $this->getModuleInfo($identifier) ?? []);

            return $result;
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
        $layoutSync = null;
        $layoutWarnings = [];
        $recordedColumns = [];
        $filesRestored = false;

        try {
            // 1. 백업 생성
            $onProgress?->__invoke('backup', __('extensions.progress.backup'));
            $backupPath = ExtensionBackupHelper::createBackup('modules', $identifier, $onProgress);

            // 2. 상태 → updating
            $onProgress?->__invoke('status', __('extensions.progress.status_change'));
            $this->moduleRepository->updateByIdentifier($identifier, [
                'status' => ExtensionStatus::Updating->value,
                'updated_at' => now(),
            ]);

            // 3. 스테이징 (소스에 따라 분기)
            $onProgress?->__invoke('staging', __('extensions.progress.staging'));
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
                        $onProgress?->__invoke('composer', __('extensions.progress.composer_unchanged'));
                        Log::info('모듈 업데이트: composer 변경 없음, 스킵', ['module' => $identifier]);
                        ExtensionPendingHelper::copyVendorFromActive($activePath, $stagingPath, $onProgress);
                        // 모드는 이전 설치 모드를 유지
                        $resolvedVendorMode = $previousMode ?? VendorMode::Auto;
                    } else {
                        $onProgress?->__invoke('composer', __('extensions.progress.vendor_install'));
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
                $onProgress?->__invoke('files', __('extensions.progress.replace_files'));
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
            $onProgress?->__invoke('reload', __('extensions.progress.module_reload'));
            $this->reloadModule($identifier);
            $module = $this->getModule($identifier);

            // 4. 마이그레이션 실행
            $onProgress?->__invoke('migration', __('extensions.progress.migration'));
            if ($module) {
                $this->runMigrations($module);
            }

            // 5. 오토로드 갱신 (DB 트랜잭션 진입 전)
            //
            // 순서 중요: cleanupStaleModuleEntries 의 동적 hook(getDynamicPermissionIdentifiers 등)이
            // 모듈 클래스(예: sirsoft-board 의 Board 모델)를 참조하므로, 새 파일에 대한 PSR-4 매핑이
            // 현재 프로세스 ClassLoader 에 등록되어 있어야 한다. updateComposerAutoload() 는
            // 파일 재기록 + 런타임 ClassLoader 재등록을 동시에 수행한다.
            $onProgress?->__invoke('autoload', __('extensions.progress.autoload'));
            $this->extensionManager->updateComposerAutoload();

            // 6. 트랜잭션: DB 정보 갱신
            $onProgress?->__invoke('db', __('extensions.progress.db_update'));
            DB::beginTransaction();
            try {
                $name = $module ? $this->convertToMultilingual($module->getName()) : $record->name;
                $description = $module ? $this->convertToMultilingual($module->getDescription()) : $record->description;

                // 활성 언어팩의 manifest seed(ja 등)를 name/description 다국어 필드에 주입 (install 경로와 동일)
                if ($module) {
                    $manifest = HookManager::applyFilters(
                        "module.{$identifier}.manifest.translations",
                        ['name' => $name, 'description' => $description]
                    );
                    $name = $manifest['name'] ?? $name;
                    $description = $manifest['description'] ?? $description;
                }

                $recorded = [
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
                ];
                $this->moduleRepository->updateByIdentifier($identifier, $recorded);

                // 선언형 산출물 (역할/권한/메뉴/IDV/알림) 일괄 동기화 + stale cleanup
                if ($module) {
                    $this->syncDeclarativeArtifacts($module);
                }

                DB::commit();
                // 실패 경로가 되돌릴 칸 — 이 배열 하나가 기록·복원의 SSoT 다(갱신 시각은 복원이 새로 찍는다).
                $recordedColumns = array_keys(Arr::except($recorded, ['updated_at']));
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            // 7. 업그레이드 스텝 실행
            $onProgress?->__invoke('upgrade', __('extensions.progress.upgrade_steps'));
            if ($module) {
                $this->runUpgradeSteps($module, $fromVersion, $toVersion, $force, $onUpgradeStep);
            }

            // 8. 상태 복원 (refreshModuleLayouts()가 active 상태를 요구하므로 먼저 복원)
            $onProgress?->__invoke('restore_status', __('extensions.progress.restore_status'));
            $this->moduleRepository->updateByIdentifier($identifier, [
                'status' => $previousStatus,
                'updated_at' => now(),
            ]);

            // 모듈 상태 캐시 무효화 — 상태 복원 쓰기 직후에 둔다.
            // Updating 전이 직후에는 비우지 않는다: 그러면 Updating 창 안의
            // updateComposerAutoload() 가 DB 를 재조회해 이 모듈을 비활성으로 판정하고
            // 훅 캐시에서 리스너를 떨군다(지금 없는 결함을 새로 만든다).
            // 복원 직후에 비워야 뒤따르는 굽기(라우트·훅)가 복원된 상태를 읽는다.
            self::invalidateModuleStatusCache();

            // 9. 레이아웃 갱신 (이전 상태가 active였으면)
            // refreshModuleLayouts()는 캐시 무효화 + 캐시 버전 증가를 포함
            $onProgress?->__invoke('layout', __('extensions.progress.layout_update'));
            if ($previousStatus === ExtensionStatus::Active->value && $module) {
                $preserveModified = ($layoutStrategy === 'keep');
                // registerModuleLayouts() 를 여기서 호출하지 않는다 — 그 메서드는 전략을
                // 모른 채 모든 레이아웃의 content 와 original_content_hash 를 파일 기준으로
                // 덮어써서, 뒤따르는 refreshModuleLayouts($preserveModified) 가 비교할
                // "사용자 수정본" 을 이미 지워버린다(= keep 전략이 항상 무효화).
                // 신규 레이아웃 생성은 refreshModuleLayouts 의 created 분기가 담당한다.
                // 조각 사전 재등록도 이 업데이트의 전략·백업을 쓴다 — 종전엔 false 고정이라 원본 해시까지 파일로
                // 덮어써 뒤따르는 keep 비교가 늘 "미수정" 이었다(L2). 두 경로가 같은 행을 지나도 보고는 처음 결과를 남긴다.
                $layoutReport = new LayoutSyncReport(LayoutSyncMode::fromPreserve($preserveModified));
                $this->registerLayoutExtensions($module, $preserveModified, $backupPath, $layoutReport);
                $this->reregisterActiveExtensionsToAllTemplates();
                $refreshed = $this->refreshModuleLayouts($identifier, $preserveModified, $backupPath, $layoutReport);
                $layoutSync = $refreshed['layout_sync'] ?? null;
                $layoutWarnings = $refreshed['layout_warnings'] ?? [];
            } elseif ($backupPath !== null) {
                // 꺼 둔 채 업데이트(D11) — 레이아웃은 켤 때 갱신되므로, 백업을 지우기 전에 이전 판 원본을 미리 저장해
                // 켤 때 운영자 수정은 두고 새 판을 병합할 수 있게 한다.
                $this->layoutSynchronizer()->stashBaseForInactiveUpdate($backupPath, 'modules', $identifier);
            }

            // 10. 백업 삭제 + 캐시 삭제
            $onProgress?->__invoke('cleanup', __('extensions.progress.cleanup'));
            ExtensionBackupHelper::deleteBackup($backupPath);
            // 백업을 지운 뒤의 실패(완료 훅·캐시 재생성)는 파일을 되돌릴 수 없다 — 파일이 새 버전이므로 DB 버전 칸도 두어야
            // 둘이 맞는다. catch 가 지워진 백업으로 복원을 시도하지 않게 비운다.
            $backupPath = null;

            // 캐시 무효화
            $this->clearAllTemplateLanguageCaches();
            $this->clearAllTemplateRoutesCaches();
            $this->incrementExtensionCacheVersion();
            RouteCacheHelper::rebuild();

            // 훅 캐시 재생성 — Updating 창 안의 updateComposerAutoload() 가 구운 훅 캐시에는
            // 그 시점 이 모듈이 Updating(=비활성)으로 판정되어 리스너가 통째로 빠져 있을 수 있다.
            // 훅 캐시 폴백은 파일 부재/손상에만 작동하므로 내용이 stale 한 경우는 조용히 통과한다.
            // 상태를 복원하고 상태 캐시를 비운 지금 다시 구워야 그 누락이 교정된다.
            // updateComposerAutoload() 전체를 재호출하지 않는다 — composer autoload 병합은 이미 끝났고 비싸다.
            $this->extensionManager->regenerateHookCache();

            // 훅 발행: 모듈 업데이트 완료 (Artisan 직접 호출 시에도 리스너 트리거)
            // @deprecated 'core.modules.updated' — identifier 1개만 전달하는 구훅. 정규 훅은 after_update.
            HookManager::doAction('core.modules.updated', $identifier);

            Log::info('모듈 업데이트 완료', [
                'module' => $identifier,
                'from' => $fromVersion,
                'to' => $toVersion,
                'source' => $updateSource,
            ]);

            // 새 판이 요구하는 의존 확장 버전 검사 — 모자라면 경고만 하고 갱신은 그대로 둔다(일괄 업데이트처럼 의존 확장이
            // 곧 따라 올라오는 흐름을 막지 않는다). 결과·완료 문구·로그로 알린다.
            $dependencyWarnings = DependencyVersionWarnings::afterUpdate(
                'module',
                $identifier,
                $this->modulesPath.DIRECTORY_SEPARATOR.$identifier.DIRECTORY_SEPARATOR.'module.json'
            );

            $result = [
                'success' => true,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'layout_strategy' => $layoutStrategy,
                'layout_sync' => $layoutSync,
                'layout_warnings' => $layoutWarnings,
                'dependency_warnings' => $dependencyWarnings,
                'message' => DependencyVersionWarnings::appendToMessage(__('modules.update_success', [
                    'module' => $identifier,
                    'version' => $toVersion,
                ]), $dependencyWarnings),
            ];
        } catch (\Throwable $e) {
            // 실패 시: 백업 복원 + 상태 복원
            Log::error('모듈 업데이트 실패', [
                'module' => $identifier,
                'error' => $e->getMessage(),
            ]);

            // 실패 시점에 되돌릴 백업이 있었는지 — 백업을 지운 뒤(마무리 단계) 실패면 새 판이 남는다. 실패 안내가 실제 결과를 말하게 한다.
            $hadBackup = $backupPath !== null;

            if ($backupPath) {
                try {
                    ExtensionBackupHelper::restoreFromBackup('modules', $identifier, $backupPath, $onProgress);
                    $filesRestored = true;
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

            // 상태 복원 — 새 버전 기록이 이미 저장된 뒤 실패했고 파일을 백업으로 되돌렸으면 버전 칸도 업데이트 전 값으로
            // 되돌린다. 파일은 옛 버전인데 DB 만 새 버전이면 관리자 화면이 설치되지 않은 버전을 보여 주고, "업데이트 없음" 으로
            // 판정돼 같은 업데이트를 다시 시도할 수도 없다. 파일을 되돌리지 못했으면(백업 삭제 뒤 실패·복원 실패) 새 버전
            // 파일에 맞춰 버전 칸을 그대로 둔다 — 반대 방향 불일치를 만들지 않는다. 업그레이드 스텝은 멱등이 규정이라
            // (upgrade-step-guide 원칙 4) 다시 시도할 때 처음부터 돌아도 안전하다.
            $this->moduleRepository->updateByIdentifier($identifier, [
                'status' => $previousStatus,
                'updated_at' => now(),
                ...($filesRestored && $recordedColumns !== [] ? Arr::only($record->getRawOriginal(), $recordedColumns) : []),
            ]);

            // 상태 캐시 무효화 (성공 경로와 동일) — updating 창에서 누군가 활성 목록을 읽었다면
            // 그 목록에는 이 모듈이 빠져 있다. 여기서 비우지 않으면 상태를 되돌려 놓고도
            // 캐시 TTL(기본 하루) 동안 이 모듈의 화면이 계속 404 로 남는다.
            self::invalidateModuleStatusCache();

            // 업데이트 도중 새 판 기준으로 다시 만든 훅 캐시·확장 autoload 를 지금 디스크(되돌린 판) 기준으로 맞춘다 — 남겨 두면 되돌린
            // 판에 없는 리스너를 부팅 때 불러 사이트 전체가 500 이 된다(W3 리허설). 새 프로세스로 굽고, 못 띄우면 훅 캐시를 지운다.
            ExtensionRollbackCacheRepair::rebuild($this->extensionManager);

            throw ExtensionUpdateFailedException::fromFailure(
                __('modules.errors.update_failed', [
                    'module' => $identifier,
                    'error' => $e->getMessage(),
                ]),
                $filesRestored,
                $hadBackup,
                $recordedColumns !== [],
                $e
            );
        }

        // 정규 훅 — CLI·어드민 UI 공통 단일 발화(#138). 업데이트 롤백(백업 복원) try 밖에서 발화해,
        // 리스너 예외가 이미 끝난 업데이트를 되돌리는 경로로 들어가지 않게 한다.
        HookManager::doAction('core.modules.after_update', $identifier, $result, $this->getModuleInfo($identifier) ?? []);

        return $result;
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
     * 같은 식별자의 _bundled module 매니페스트를 반환합니다 (업데이트 대상 코어 호환 판정용).
     *
     * @param  string  $identifier  module 식별자
     * @return array<string, mixed>|null _bundled 매니페스트 또는 null
     */
    private function findBundledModuleManifest(string $identifier): ?array
    {
        if (isset($this->bundledModules[$identifier])) {
            return $this->bundledModules[$identifier];
        }

        $meta = ExtensionPendingHelper::loadBundledExtensions($this->modulesPath, 'module.json');

        return $meta[$identifier] ?? null;
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
     * @return VendorInstallResult vendor 구성 결과
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
            $onProgress?->__invoke('composer', __('extensions.progress.composer_install_running'));
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
     *
     * @param  string  $identifier  모듈 식별자
     * @return ?VendorMode 이전 설치 모드 (기록이 없으면 null)
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

    /**
     * 이 모듈에 의존하는 켜진 확장(템플릿·모듈·플러그인) 식별자 목록을 반환합니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @return array<int, string> 의존 확장 식별자 목록 (없으면 빈 배열)
     */
    private function activeDependentIdentifiers(string $identifier): array
    {
        return array_values(array_unique(array_merge(
            $this->templateRepository->findActiveByModuleDependency($identifier)->pluck('identifier')->all(),
            $this->moduleRepository->findActiveByModuleDependency($identifier)->pluck('identifier')->all(),
            $this->pluginRepository->findActiveByModuleDependency($identifier)->pluck('identifier')->all(),
        )));
    }
}
