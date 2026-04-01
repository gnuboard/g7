<?php

namespace App\Providers;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\UniqueIdServiceInterface;
use App\Contracts\Extension\ModuleSettingsInterface;
use App\Contracts\Extension\StorageInterface;
use App\Contracts\Repositories\ActivityLogRepositoryInterface;
use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Contracts\Repositories\LayoutExtensionRepositoryInterface;
use App\Contracts\Repositories\LayoutPreviewRepositoryInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\LayoutVersionRepositoryInterface;
use App\Contracts\Repositories\MailSendLogRepositoryInterface;
use App\Contracts\Repositories\MailTemplateRepositoryInterface;
use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PasswordResetTokenRepositoryInterface;
use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\ScheduleHistoryRepositoryInterface;
use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Contracts\Repositories\SystemConfigRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Contracts\Repositories\UserConsentRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\CoreVersionChecker;
use App\Extension\ExtensionManager;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Storage\CoreStorageDriver;
use App\Extension\TemplateManager;
use App\Repositories\ActivityLogRepository;
use App\Repositories\AttachmentRepository;
use App\Repositories\JsonConfigRepository;
use App\Repositories\LayoutExtensionRepository;
use App\Repositories\LayoutPreviewRepository;
use App\Repositories\LayoutRepository;
use App\Repositories\LayoutVersionRepository;
use App\Repositories\MailSendLogRepository;
use App\Repositories\MailTemplateRepository;
use App\Repositories\MenuRepository;
use App\Repositories\ModuleRepository;
use App\Repositories\PasswordResetTokenRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\PluginRepository;
use App\Repositories\RoleRepository;
use App\Repositories\ScheduleHistoryRepository;
use App\Repositories\ScheduleRepository;
use App\Repositories\SystemConfigRepository;
use App\Repositories\TemplateRepository;
use App\Repositories\UserConsentRepository;
use App\Repositories\UserRepository;
use App\Services\AttachmentService;
use App\Services\UniqueIdService;
use App\Services\LayoutExtensionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    /**
     * 코어 서비스들을 등록합니다.
     */
    public function register(): void
    {
        // Extension autoload 등록 (진입점에서 누락된 경우를 대비)
        $this->registerExtensionAutoload();

        $this->registerRepositoryBindings();
        $this->registerExtensionManagers();
        // ActivityLogManager 제거됨 — Monolog 채널(config/logging.php 'activity')로 대체
    }

    /**
     * Extension(모듈/플러그인) PSR-4 오토로드를 등록합니다.
     *
     * index.php 또는 artisan에서 이미 등록된 경우 스킵합니다.
     * 진입점에서 등록되지 않은 경우(예: queue worker)에만 실행됩니다.
     */
    private function registerExtensionAutoload(): void
    {
        // 이미 진입점(index.php, artisan)에서 등록된 경우 스킵
        if (defined('G7_EXTENSION_AUTOLOAD_REGISTERED')) {
            return;
        }

        $extensionAutoloadFile = base_path('bootstrap/cache/autoload-extensions.php');

        if (! file_exists($extensionAutoloadFile)) {
            return;
        }

        $loader = require base_path('vendor/autoload.php');
        $extensionAutoloads = require $extensionAutoloadFile;

        // PSR-4 네임스페이스 등록
        if (! empty($extensionAutoloads['psr4'])) {
            foreach ($extensionAutoloads['psr4'] as $namespace => $paths) {
                $paths = (array) $paths;
                foreach ($paths as $path) {
                    $absolutePath = base_path($path);
                    if (is_dir($absolutePath)) {
                        $loader->addPsr4($namespace, $absolutePath);
                    }
                }
            }
        }

        // Classmap 파일 로드 (module.php, plugin.php)
        if (! empty($extensionAutoloads['classmap'])) {
            foreach ($extensionAutoloads['classmap'] as $file) {
                $absolutePath = base_path($file);
                if (file_exists($absolutePath)) {
                    require_once $absolutePath;
                }
            }
        }

        // Files 로드 (헬퍼 함수 등)
        if (! empty($extensionAutoloads['files'])) {
            foreach ($extensionAutoloads['files'] as $file) {
                $absolutePath = base_path($file);
                if (file_exists($absolutePath)) {
                    require_once $absolutePath;
                }
            }
        }
    }

    /**
     * Repository 인터페이스 바인딩을 등록합니다.
     */
    private function registerRepositoryBindings(): void
    {
        $this->app->bind(ActivityLogRepositoryInterface::class, ActivityLogRepository::class);
        $this->app->bind(AttachmentRepositoryInterface::class, AttachmentRepository::class);
        $this->app->bind(PasswordResetTokenRepositoryInterface::class, PasswordResetTokenRepository::class);
        $this->app->bind(LayoutExtensionRepositoryInterface::class, LayoutExtensionRepository::class);
        $this->app->singleton(ConfigRepositoryInterface::class, JsonConfigRepository::class);
        $this->app->bind(LayoutPreviewRepositoryInterface::class, LayoutPreviewRepository::class);
        $this->app->bind(LayoutRepositoryInterface::class, LayoutRepository::class);
        $this->app->bind(LayoutVersionRepositoryInterface::class, LayoutVersionRepository::class);
        $this->app->bind(MenuRepositoryInterface::class, MenuRepository::class);
        $this->app->bind(ModuleRepositoryInterface::class, ModuleRepository::class);
        $this->app->bind(PermissionRepositoryInterface::class, PermissionRepository::class);
        $this->app->bind(PluginRepositoryInterface::class, PluginRepository::class);
        $this->app->bind(RoleRepositoryInterface::class, RoleRepository::class);
        $this->app->bind(ScheduleHistoryRepositoryInterface::class, ScheduleHistoryRepository::class);
        $this->app->bind(ScheduleRepositoryInterface::class, ScheduleRepository::class);
        $this->app->bind(SystemConfigRepositoryInterface::class, SystemConfigRepository::class);
        $this->app->bind(TemplateRepositoryInterface::class, TemplateRepository::class);
        $this->app->bind(UserConsentRepositoryInterface::class, UserConsentRepository::class);
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(MailTemplateRepositoryInterface::class, MailTemplateRepository::class);
        $this->app->bind(MailSendLogRepositoryInterface::class, MailSendLogRepository::class);

        // UniqueIdService 바인딩
        $this->app->singleton(UniqueIdServiceInterface::class, UniqueIdService::class);

        // AttachmentService용 CoreStorageDriver 바인딩
        $this->app->when(AttachmentService::class)
            ->needs(StorageInterface::class)
            ->give(function () {
                return new CoreStorageDriver(config('attachment.disk', 'local'));
            });
    }

    /**
     * 확장 매니저들을 등록합니다.
     */
    private function registerExtensionManagers(): void
    {
        // 확장 매니저 등록 (모듈/플러그인 공통 기능)
        $this->app->singleton(ExtensionManager::class, function ($app) {
            return new ExtensionManager(
                $app->make(ModuleRepositoryInterface::class),
                $app->make(PluginRepositoryInterface::class)
            );
        });

        // 모듈 매니저 등록
        $this->app->singleton(ModuleManager::class, function ($app) {
            return new ModuleManager(
                $app->make(ExtensionManager::class),
                $app->make(ModuleRepositoryInterface::class),
                $app->make(PermissionRepositoryInterface::class),
                $app->make(RoleRepositoryInterface::class),
                $app->make(MenuRepositoryInterface::class),
                $app->make(TemplateRepositoryInterface::class),
                $app->make(PluginRepositoryInterface::class),
                $app->make(LayoutRepositoryInterface::class),
                $app->make(LayoutExtensionService::class)
            );
        });

        // 플러그인 매니저 등록
        $this->app->singleton(PluginManager::class, function ($app) {
            return new PluginManager(
                $app->make(ExtensionManager::class),
                $app->make(PluginRepositoryInterface::class),
                $app->make(PermissionRepositoryInterface::class),
                $app->make(RoleRepositoryInterface::class),
                $app->make(TemplateRepositoryInterface::class),
                $app->make(ModuleRepositoryInterface::class),
                $app->make(LayoutRepositoryInterface::class),
                $app->make(LayoutExtensionService::class)
            );
        });

        // 템플릿 매니저 등록
        $this->app->singleton(TemplateManager::class, function ($app) {
            return new TemplateManager(
                $app->make(ExtensionManager::class),
                $app->make(TemplateRepositoryInterface::class),
                $app->make(LayoutRepositoryInterface::class),
                $app->make(ModuleRepositoryInterface::class),
                $app->make(PluginRepositoryInterface::class),
                $app->make(LayoutExtensionService::class)
            );
        });

        // 템플릿 매니저 인터페이스 바인딩
        $this->app->singleton(\App\Contracts\Extension\TemplateManagerInterface::class, function ($app) {
            return $app->make(TemplateManager::class);
        });
    }

    /**
     * 코어 서비스들을 부트스트랩합니다.
     */
    public function boot(): void
    {
        // 코어 훅 리스너 자동 발견 및 등록 (환경 무관)
        $this->registerCoreHookListeners();

        // 시스템 라우트 주입 (프리뷰 등 코어 전역 라우트)
        $this->registerSystemRouteFilters();

        // .env 파일이 없으면 스킵 (인스톨러 실행 전)
        if (! File::exists(base_path('.env'))) {
            return;
        }

        // DB 연결 유효성 검증 (root 사용자 접속 방지)
        if (! $this->isDatabaseConnectionValid()) {
            Log::error('Database connection invalid: using root user or missing credentials. Skipping extension loading.');

            return;
        }

        // 콘솔 환경에서는 템플릿 로딩 건너뛰기 (성능 최적화)
        $skipTemplateLoading = $this->app->runningInConsole() &&
                              ! in_array(request()->server('argv')[1] ?? '', ['serve', 'test']);

        // 모듈 로드 및 버전 검증
        $moduleManager = $this->app->make(ModuleManager::class);
        $moduleManager->loadModules();
        $this->validateAndDeactivateIncompatibleExtensions($moduleManager, 'modules');

        // 모듈 환경설정 로딩 (활성화된 모듈만)
        $this->loadModuleSettingsToConfig($moduleManager);

        // 플러그인 로드 및 버전 검증
        $pluginManager = $this->app->make(PluginManager::class);
        $pluginManager->loadPlugins();
        $this->validateAndDeactivateIncompatibleExtensions($pluginManager, 'plugins');

        // 플러그인 환경설정 로딩 (활성화된 플러그인만)
        $this->loadPluginSettingsToConfig($pluginManager);

        // 템플릿 로드 (콘솔 환경에서는 조건부 실행)
        if (! $skipTemplateLoading) {
            $templateManager = $this->app->make(TemplateManager::class);
            $templateManager->loadTemplates();
            $this->validateAndDeactivateIncompatibleTemplates($templateManager);
        }
    }

    /**
     * 데이터베이스 연결이 유효한지 검증합니다.
     *
     * root 사용자로 접속하거나 비밀번호가 없는 경우를 감지하여
     * 잘못된 설정으로 인한 접속 오류를 방지합니다.
     *
     * @return bool 연결이 유효하면 true
     */
    protected function isDatabaseConnectionValid(): bool
    {
        try {
            $config = DB::connection()->getConfig();

            // read/write 분리 설정인 경우 read 설정 확인
            $username = $config['username'] ?? null;

            // read 설정이 배열로 있는 경우
            if (isset($config['read']['username'])) {
                $username = $config['read']['username'];
            }

            // root 사용자 또는 빈 username인 경우 무효
            if (empty($username) || $username === 'root') {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Database configuration check failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * 호환되지 않는 확장(모듈/플러그인)을 자동 비활성화합니다.
     *
     * 코어 버전 업데이트 후 기존 확장이 새 코어 버전과 호환되지 않는 경우
     * 자동으로 비활성화하고 로그를 기록합니다.
     *
     * @param  ModuleManager|PluginManager  $manager  확장 매니저
     * @param  string  $type  확장 타입 (modules, plugins)
     */
    protected function validateAndDeactivateIncompatibleExtensions($manager, string $type): void
    {
        $cacheKey = CoreVersionChecker::getCacheKey($type);

        // 캐시가 있으면 이미 검증된 것으로 간주
        if (Cache::has($cacheKey)) {
            return;
        }

        $deactivated = [];
        $activeMethod = $type === 'modules' ? 'getActiveModules' : 'getActivePlugins';
        $deactivateMethod = $type === 'modules' ? 'deactivateModule' : 'deactivatePlugin';

        foreach ($manager->$activeMethod() as $identifier => $extension) {
            $requiredVersion = $extension->getRequiredCoreVersion();

            if (! CoreVersionChecker::isCompatible($requiredVersion)) {
                $manager->$deactivateMethod($identifier);
                $deactivated[] = [
                    'identifier' => $identifier,
                    'required' => $requiredVersion,
                ];

                Log::warning(__('extensions.warnings.auto_deactivated'), [
                    'type' => $type,
                    'identifier' => $identifier,
                    'required_version' => $requiredVersion,
                    'core_version' => config('app.version'),
                ]);
            }
        }

        // 비활성화된 확장이 있으면 세션에 저장 (관리자 알림용)
        if (! empty($deactivated)) {
            $this->storeDeactivatedExtensionsAlert($type, $deactivated);
        }

        Cache::put($cacheKey, true, CoreVersionChecker::getCacheTtl());
    }

    /**
     * 호환되지 않는 템플릿을 자동 비활성화합니다.
     *
     * 코어 버전 업데이트 후 템플릿이 새 코어 버전과 호환되지 않는 경우
     * 자동으로 비활성화하고 로그를 기록합니다.
     *
     * @param  TemplateManager  $templateManager  템플릿 매니저
     */
    protected function validateAndDeactivateIncompatibleTemplates(TemplateManager $templateManager): void
    {
        $cacheKey = CoreVersionChecker::getCacheKey('templates');

        // 캐시가 있으면 이미 검증된 것으로 간주
        if (Cache::has($cacheKey)) {
            return;
        }

        $deactivated = [];
        $allTemplates = $templateManager->getAllTemplates();
        $templateRepository = $this->app->make(TemplateRepositoryInterface::class);

        foreach ($allTemplates as $identifier => $template) {
            // 설치되고 활성화된 템플릿만 검증
            $templateRecord = $templateRepository->findByIdentifier($identifier);
            if (! $templateRecord || $templateRecord->status !== 'active') {
                continue;
            }

            $requiredVersion = $template['g7_version'] ?? null;

            if (! CoreVersionChecker::isCompatible($requiredVersion)) {
                $templateManager->deactivateTemplate($identifier);
                $deactivated[] = [
                    'identifier' => $identifier,
                    'required' => $requiredVersion,
                ];

                Log::warning(__('extensions.warnings.auto_deactivated'), [
                    'type' => 'templates',
                    'identifier' => $identifier,
                    'required_version' => $requiredVersion,
                    'core_version' => config('app.version'),
                ]);
            }
        }

        // 비활성화된 템플릿이 있으면 세션에 저장 (관리자 알림용)
        if (! empty($deactivated)) {
            $this->storeDeactivatedExtensionsAlert('templates', $deactivated);
        }

        Cache::put($cacheKey, true, CoreVersionChecker::getCacheTtl());
    }

    /**
     * 비활성화된 확장 알림을 캐시에 저장합니다.
     *
     * 관리자 대시보드에서 비활성화된 확장에 대한 알림을 표시하기 위해
     * 캐시에 정보를 저장합니다.
     *
     * @param  string  $type  확장 타입 (modules, plugins, templates)
     * @param  array  $deactivated  비활성화된 확장 목록
     */
    protected function storeDeactivatedExtensionsAlert(string $type, array $deactivated): void
    {
        $alerts = Cache::get('extension_compatibility_alerts', []);
        $alerts[$type] = [
            'deactivated' => $deactivated,
            'core_version' => config('app.version'),
            'timestamp' => now()->toIso8601String(),
        ];
        Cache::put('extension_compatibility_alerts', $alerts, 86400); // 24시간
    }

    /**
     * 활성화된 모듈의 환경설정을 Config에 로드합니다.
     *
     * ModuleSettingsInterface를 구현한 서비스가 바인딩된 모듈의 경우
     * getAllSettings()를 호출하여 g7_settings.modules.{identifier}에 저장합니다.
     *
     * @param  ModuleManager  $moduleManager  모듈 매니저
     */
    protected function loadModuleSettingsToConfig(ModuleManager $moduleManager): void
    {
        $moduleSettings = [];

        foreach (array_keys($moduleManager->getActiveModules()) as $identifier) {
            try {
                // 모듈별 환경설정 서비스 조회
                $settingsService = $this->resolveModuleSettingsService($identifier);

                if ($settingsService instanceof ModuleSettingsInterface) {
                    $settings = $settingsService->getAllSettings();
                    if (! empty($settings)) {
                        $moduleSettings[$identifier] = $settings;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("모듈 환경설정 로딩 실패: {$identifier}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Config::set('g7_settings.modules', $moduleSettings);
    }

    /**
     * 모듈의 환경설정 서비스를 찾아 인스턴스화합니다.
     *
     * 다음 순서로 설정 서비스를 찾습니다:
     * 1. 인터페이스 바인딩: Modules\Vendor\Module\Contracts\ModuleSettingsServiceInterface
     * 2. 구체 클래스: Modules\Vendor\Module\Services\ModuleSettingsService
     *
     * @param  string  $identifier  모듈 식별자 (예: sirsoft-ecommerce)
     * @return ModuleSettingsInterface|null 설정 서비스 인스턴스
     */
    protected function resolveModuleSettingsService(string $identifier): ?ModuleSettingsInterface
    {
        // vendor-module 형식을 네임스페이스로 변환
        $parts = explode('-', $identifier);
        if (count($parts) < 2) {
            return null;
        }

        $vendor = ucfirst($parts[0]);
        $moduleName = ucfirst($parts[1]);

        // 1. 인터페이스 바인딩 확인
        $interfaceClass = "Modules\\{$vendor}\\{$moduleName}\\Contracts\\{$moduleName}SettingsServiceInterface";
        if ($this->app->bound($interfaceClass)) {
            $service = $this->app->make($interfaceClass);
            if ($service instanceof ModuleSettingsInterface) {
                return $service;
            }
        }

        // 2. 구체 클래스 확인
        $concreteClass = "Modules\\{$vendor}\\{$moduleName}\\Services\\{$moduleName}SettingsService";
        if (class_exists($concreteClass)) {
            $service = $this->app->make($concreteClass);
            if ($service instanceof ModuleSettingsInterface) {
                return $service;
            }
        }

        return null;
    }

    /**
     * 활성화된 플러그인의 환경설정을 Config에 로드합니다.
     *
     * storage/app/plugins/{identifier}/settings/setting.json 파일을 읽어
     * g7_settings.plugins.{identifier}에 저장합니다.
     *
     * @param  PluginManager  $pluginManager  플러그인 매니저
     */
    protected function loadPluginSettingsToConfig(PluginManager $pluginManager): void
    {
        $pluginSettings = [];

        foreach (array_keys($pluginManager->getActivePlugins()) as $identifier) {
            try {
                $settingsPath = storage_path("app/plugins/{$identifier}/settings/setting.json");

                if (File::exists($settingsPath)) {
                    $content = File::get($settingsPath);
                    $settings = json_decode($content, true);

                    if (json_last_error() === JSON_ERROR_NONE && ! empty($settings)) {
                        $pluginSettings[$identifier] = $settings;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("플러그인 환경설정 로딩 실패: {$identifier}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Config::set('g7_settings.plugins', $pluginSettings);
    }

    // registerActivityLogManager() 제거됨 — Monolog 채널(config/logging.php 'activity')로 대체

    /**
     * app/Listeners/ 디렉토리에서 HookListenerInterface 구현체를 자동 발견하여 등록합니다.
     * 하위 디렉토리까지 재귀적으로 스캔합니다.
     */
    private function registerCoreHookListeners(): void
    {
        $listenersPath = app_path('Listeners');

        if (! is_dir($listenersPath)) {
            return;
        }

        // 재귀적으로 모든 PHP 파일 스캔
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($listenersPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // 파일 경로에서 클래스명 추출
            $relativePath = str_replace($listenersPath.DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relativePath = str_replace('.php', '', $relativePath);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);
            $listenerClass = 'App\\Listeners\\'.$relativePath;

            // 클래스 존재 여부 확인
            if (! class_exists($listenerClass)) {
                Log::warning("코어 훅 리스너 클래스를 찾을 수 없습니다: {$listenerClass}");

                continue;
            }

            // HookListenerInterface 구현 여부 확인
            if (! in_array(HookListenerInterface::class, class_implements($listenerClass))) {
                continue;
            }

            $this->registerCoreHookListener($listenerClass);
        }
    }

    /**
     * 단일 코어 리스너를 HookManager에 등록합니다.
     *
     * @param  string  $listenerClass  리스너 클래스명
     */
    private function registerCoreHookListener(string $listenerClass): void
    {
        try {
            // 구독할 훅 정보 가져오기
            $subscribedHooks = $listenerClass::getSubscribedHooks();

            foreach ($subscribedHooks as $hookName => $config) {
                $method = $config['method'] ?? 'handle';
                $priority = $config['priority'] ?? 10;
                $type = $config['type'] ?? 'action';

                if ($type === 'filter') {
                    // Filter 훅: 반환값을 전달 (클로저 래퍼로 지연 인스턴스 생성)
                    HookManager::addFilter($hookName, function ($value, ...$args) use ($listenerClass, $method) {
                        $listener = app($listenerClass);

                        return $listener->{$method}($value, ...$args);
                    }, $priority);
                } else {
                    // Action 훅 (클로저 래퍼로 지연 인스턴스 생성)
                    HookManager::addAction($hookName, function (...$args) use ($listenerClass, $method) {
                        $listener = app($listenerClass);
                        $listener->{$method}(...$args);
                    }, $priority);
                }

                Log::info('코어 훅 리스너 등록 완료', [
                    'hook' => $hookName,
                    'listener' => $listenerClass,
                    'method' => $method,
                    'priority' => $priority,
                    'type' => $type,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('코어 훅 리스너 등록 중 오류 발생', [
                'listener' => $listenerClass,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 시스템 전역 라우트를 주입하는 필터를 등록합니다.
     *
     * core.routes.filter_merged 필터를 통해 프리뷰 등 코어 시스템 라우트를
     * 모든 템플릿의 routes.json 응답에 자동 주입합니다.
     *
     * @return void
     */
    private function registerSystemRouteFilters(): void
    {
        HookManager::addFilter('core.routes.filter_merged', function (array $routes, string $templateType, string $identifier) {
            $basePath = $templateType === 'admin' ? '*/admin' : '*';

            $routes[] = [
                'path' => "{$basePath}/preview/:token",
                'layout' => '__preview__',
                'auth_required' => false,
                'meta' => [
                    'title' => 'Preview',
                    'is_system_route' => true,
                ],
            ];

            return $routes;
        }, 100);
    }
}
