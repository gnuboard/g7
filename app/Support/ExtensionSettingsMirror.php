<?php

namespace App\Support;

use App\Contracts\Extension\ModuleSettingsInterface;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Repositories\JsonConfigRepository;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * 설정 config 미러(`g7_settings.*`) 관리자
 *
 * `g7_core_settings()` / `g7_module_settings()` / `g7_plugin_settings()` 가 읽는
 * in-memory config 미러를 채웁니다.
 *
 * 종전에는 이 미러가 **부팅 때 한 번만** 채워졌습니다. FPM 은 요청마다 부팅하므로
 * 문제가 드러나지 않지만, 큐 워커·schedule:work·Reverb 처럼 프로세스가 상주하는
 * 환경에서는 관리자가 설정을 저장해도 그 프로세스의 미러가 영원히 옛 값으로 남습니다
 * (공개이슈 #109). 저장 경로에서 이 클래스의 refresh* 를 호출해 같은 프로세스의
 * 미러를 즉시 다시 채웁니다.
 *
 * 민감 항목은 미러에 담지 않습니다 — 민감값이 필요한 서버 코드는 전용 게터
 * (`plugin_setting()` 등)를 씁니다. 자세한 근거는 docs/backend/admin-settings-access.md 참조.
 */
class ExtensionSettingsMirror
{
    /**
     * 코어 설정 카테고리 목록
     *
     * @var array<int, string>
     */
    public const CORE_CATEGORIES = [
        'mail',
        'general',
        'security',
        'debug',
        'drivers',
        'cache',
        'upload',
        'core_update',
        'geoip',
        'seo',
        'identity',
        'pagination',
        'analytics',
        // 빠뜨리면 관리자가 토글을 켜고 저장에 성공하고 privacy.json 에 true 가 찍혀도
        // 커맨드는 영원히 기본값(false)을 읽어 "대상 0건" 이라는 정상 출력을 낸다 —
        // 실패가 아니라 정상처럼 보이므로 아무도 알아채지 못한다.
        'privacy',
        // 본문 콘텐츠(#883) — 봇 정화·쇼핑몰 저장 정화·본문 미디어 업로드 허용·재생 헤더·서명 주소 유효 시간이 읽는다.
        // 빠지면 저장·화면 규칙은 바뀌는데 서버만 기본값을 읽는다(위 privacy 와 같은 함정).
        'content',
    ];

    /**
     * 코어 설정 미러(`g7_settings.core`)를 다시 채웁니다.
     *
     * @param  JsonConfigRepository|null  $configRepository  설정 저장소 (미지정 시 새로 생성)
     * @return void
     */
    public function refreshCore(?JsonConfigRepository $configRepository = null): void
    {
        // 이 메서드는 SettingsServiceProvider::register() 에서도 호출된다 —
        // 그 시점은 DI 컨테이너 사용 전이므로 직접 인스턴스화가 유일한 선택지다.
        $configRepository ??= new JsonConfigRepository;

        $coreSettings = [];

        foreach (self::CORE_CATEGORIES as $category) {
            $settings = $configRepository->getCategory($category);

            if (! empty($settings)) {
                $coreSettings[$category] = $settings;
            }
        }

        Config::set('g7_settings.core', $coreSettings);
    }

    /**
     * 활성 모듈 전체의 설정 미러를 다시 채웁니다.
     *
     * @return void
     */
    public function refreshAllModules(): void
    {
        $moduleManager = app(ModuleManager::class);
        $moduleSettings = [];

        foreach (array_keys($moduleManager->getActiveModules()) as $identifier) {
            $settings = $this->resolveModuleSettings($identifier);

            if (! empty($settings)) {
                $moduleSettings[$identifier] = $settings;
            }
        }

        Config::set('g7_settings.modules', $moduleSettings);
    }

    /**
     * 모듈 하나의 설정 미러를 다시 채웁니다.
     *
     * @param  string  $identifier  모듈 식별자 (예: sirsoft-ecommerce)
     * @return void
     */
    public function refreshModule(string $identifier): void
    {
        $settings = $this->resolveModuleSettings($identifier);

        if (empty($settings)) {
            Config::set('g7_settings.modules.'.$identifier, []);

            return;
        }

        Config::set('g7_settings.modules.'.$identifier, $settings);
    }

    /**
     * 활성 플러그인 전체의 설정 미러를 다시 채웁니다.
     *
     * @return void
     */
    public function refreshAllPlugins(): void
    {
        $pluginManager = app(PluginManager::class);
        $pluginSettings = [];

        foreach (array_keys($pluginManager->getActivePlugins()) as $identifier) {
            $settings = $this->resolvePluginSettings($identifier);

            if (! empty($settings)) {
                $pluginSettings[$identifier] = $settings;
            }
        }

        Config::set('g7_settings.plugins', $pluginSettings);
    }

    /**
     * 플러그인 하나의 설정 미러를 다시 채웁니다.
     *
     * @param  string  $identifier  플러그인 식별자 (예: sirsoft-gdpr)
     * @return void
     */
    public function refreshPlugin(string $identifier): void
    {
        Config::set('g7_settings.plugins.'.$identifier, $this->resolvePluginSettings($identifier));
    }

    /**
     * 모듈 설정 서비스에서 미러에 담을 값을 만듭니다.
     *
     * 확장 하나가 실패해도 부팅/저장을 중단시키지 않습니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @return array<string, mixed> 미러 값 (민감 항목 제외)
     */
    private function resolveModuleSettings(string $identifier): array
    {
        try {
            $settingsService = $this->resolveModuleSettingsService($identifier);

            if (! $settingsService instanceof ModuleSettingsInterface) {
                return [];
            }

            return SensitiveSettingMask::strip(
                $settingsService->getAllSettings(),
                $this->resolveModuleSchema($identifier),
            );
        } catch (\Throwable $e) {
            Log::warning("모듈 환경설정 로딩 실패: {$identifier}", [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * 플러그인 설정에서 미러에 담을 값을 만듭니다.
     *
     * 종전에는 setting.json 을 raw 로 읽어 defaults 병합도 정규화도 없었고,
     * 암호문이 그대로 실렸습니다. 이제 PluginSettingsService 를 경유해 값의 형태를
     * 전용 게터와 맞추되, 민감 항목은 **키 자체를 담지 않습니다**.
     *
     * @param  string  $identifier  플러그인 식별자
     * @return array<string, mixed> 미러 값 (민감 항목 제외)
     */
    private function resolvePluginSettings(string $identifier): array
    {
        try {
            $pluginManager = app(PluginManager::class);
            $plugin = $pluginManager->getPlugin($identifier);

            if (! $plugin) {
                return [];
            }

            // 민감 필드는 담지 않으므로 복호화부터 하지 않는다 — 값의 형태는 get() 에서 민감 키를 뺀 것과 같다(#860)
            return app(PluginSettingsService::class)->getForMirror($identifier);
        } catch (\Throwable $e) {
            Log::warning("플러그인 환경설정 로딩 실패: {$identifier}", [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * 모듈의 설정 스키마를 조회합니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @return array<string, mixed> 설정 스키마 (없으면 빈 배열)
     */
    private function resolveModuleSchema(string $identifier): array
    {
        $module = app(ModuleManager::class)->getModule($identifier);

        if (! $module || ! method_exists($module, 'getSettingsSchema')) {
            return [];
        }

        $schema = $module->getSettingsSchema();

        return is_array($schema) ? $schema : [];
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
    private function resolveModuleSettingsService(string $identifier): ?ModuleSettingsInterface
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

        if (app()->bound($interfaceClass)) {
            $service = app($interfaceClass);

            if ($service instanceof ModuleSettingsInterface) {
                return $service;
            }
        }

        // 2. 구체 클래스 확인
        $concreteClass = "Modules\\{$vendor}\\{$moduleName}\\Services\\{$moduleName}SettingsService";

        if (class_exists($concreteClass)) {
            $service = app($concreteClass);

            if ($service instanceof ModuleSettingsInterface) {
                return $service;
            }
        }

        return null;
    }
}
