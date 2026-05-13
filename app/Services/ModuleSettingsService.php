<?php

namespace App\Services;

use App\Contracts\Extension\ModuleSettingsInterface;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\TemplateManager;
use App\Traits\FiltersFrontendSchema;
use App\Traits\NormalizesSettingsData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * 모듈 설정 관리 서비스
 *
 * 모듈의 설정값을 파일 기반으로 조회, 저장하며 민감한 필드는 암호화합니다.
 * 설정 파일은 storage/app/modules/{identifier}/settings/setting.json에 저장됩니다.
 *
 * 모듈 개발자는 공통 설정 시스템 연동 여부를 선택할 수 있습니다:
 * - 연동: getConfigValues() 오버라이드 또는 config/settings/defaults.json 생성
 * - 미연동: 자체 환경설정 페이지/API 구현
 */
class ModuleSettingsService
{
    use FiltersFrontendSchema;
    use NormalizesSettingsData;

    /**
     * 설정 파일명
     */
    private const SETTINGS_FILENAME = 'setting.json';

    /**
     * 설정 캐시 (identifier => settings)
     *
     * @var array<string, array>
     */
    private array $settingsCache = [];

    /**
     * 모듈별 설정 서비스 캐시 (identifier => service|false)
     *
     * false는 검색 완료 + 미발견을 의미합니다.
     *
     * @var array<string, ModuleSettingsInterface|false>
     */
    private array $moduleServiceCache = [];

    /**
     * ModuleSettingsService 생성자
     *
     * @param  ModuleManager  $moduleManager  모듈 매니저
     * @param  TemplateManager  $templateManager  템플릿 매니저 (오버라이드 확인용)
     * @param  LayoutService  $layoutService  레이아웃 서비스 (sanitize 재사용)
     */
    public function __construct(
        private ModuleManager $moduleManager,
        private TemplateManager $templateManager,
        private LayoutService $layoutService
    ) {}

    /**
     * 모듈 설정을 조회합니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @param  string|null  $key  특정 설정 키 (null이면 전체 설정, 도트 노테이션 지원)
     * @param  mixed  $default  기본값
     * @return mixed 설정 값
     */
    public function get(string $identifier, ?string $key = null, mixed $default = null): mixed
    {
        // 모듈별 설정 서비스가 있으면 위임
        $moduleService = $this->resolveModuleService($identifier);
        if ($moduleService !== null) {
            if ($key === null) {
                return $moduleService->getAllSettings();
            }

            return $moduleService->getSetting($key, $default);
        }

        // 캐시된 설정이 있으면 사용
        if (isset($this->settingsCache[$identifier])) {
            $settings = $this->settingsCache[$identifier];

            if ($key === null) {
                return $settings;
            }

            return Arr::get($settings, $key, $default);
        }

        // 모듈 인스턴스 확인
        $moduleInstance = $this->moduleManager->getModule($identifier);
        if (! $moduleInstance) {
            return $default;
        }

        // 기본값 로드
        $defaults = $moduleInstance->getConfigValues();

        // 저장된 설정 파일 로드
        $savedSettings = $this->loadSettingsFromFile($identifier);

        // 기본값과 저장된 설정 병합
        $settings = array_merge($defaults, $savedSettings);

        // 스키마 기반으로 민감한 필드 복호화
        $schema = $moduleInstance->getSettingsSchema();
        $settings = $this->decryptSensitiveFields($settings, $schema);

        // defaults 스키마에 맞게 정규화 (하위호환성)
        $settings = $this->normalizeCategoryData($settings, $defaults);

        // 캐시에 저장
        $this->settingsCache[$identifier] = $settings;

        if ($key === null) {
            return $settings;
        }

        return Arr::get($settings, $key, $default);
    }

    /**
     * 모듈별 설정 서비스를 자동 검색합니다.
     *
     * 다음 순서로 설정 서비스를 찾습니다:
     * 1. 인터페이스 바인딩: Modules\{Vendor}\{Module}\Contracts\{Module}SettingsServiceInterface
     * 2. 구체 클래스: Modules\{Vendor}\{Module}\Services\{Module}SettingsService
     *
     * @param string $identifier 모듈 식별자 (예: sirsoft-ecommerce)
     * @return ModuleSettingsInterface|null 설정 서비스 인스턴스
     */
    private function resolveModuleService(string $identifier): ?ModuleSettingsInterface
    {
        // 캐시 확인 (false = 검색 완료 + 미발견)
        if (array_key_exists($identifier, $this->moduleServiceCache)) {
            $cached = $this->moduleServiceCache[$identifier];

            return $cached === false ? null : $cached;
        }

        $service = $this->discoverModuleService($identifier);
        $this->moduleServiceCache[$identifier] = $service ?? false;

        return $service;
    }

    /**
     * 모듈별 설정 서비스를 검색합니다.
     *
     * @param string $identifier 모듈 식별자
     * @return ModuleSettingsInterface|null 설정 서비스 인스턴스
     */
    private function discoverModuleService(string $identifier): ?ModuleSettingsInterface
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
            $service = app()->make($interfaceClass);
            if ($service instanceof ModuleSettingsInterface) {
                return $service;
            }
        }

        // 2. 구체 클래스 확인
        $concreteClass = "Modules\\{$vendor}\\{$moduleName}\\Services\\{$moduleName}SettingsService";
        if (class_exists($concreteClass)) {
            $service = app()->make($concreteClass);
            if ($service instanceof ModuleSettingsInterface) {
                return $service;
            }
        }

        return null;
    }

    /**
     * 설정 파일에서 설정을 로드합니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @return array 설정 배열
     */
    private function loadSettingsFromFile(string $identifier): array
    {
        $moduleInstance = $this->moduleManager->getModule($identifier);
        if (! $moduleInstance) {
            return [];
        }

        $storage = $moduleInstance->getStorage();

        if (! $storage->exists('settings', self::SETTINGS_FILENAME)) {
            return [];
        }

        $content = $storage->get('settings', self::SETTINGS_FILENAME);
        if ($content === null) {
            return [];
        }

        $settings = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('모듈 설정 파일 JSON 파싱 실패', [
                'identifier' => $identifier,
                'error' => json_last_error_msg(),
            ]);

            return [];
        }

        return $settings ?? [];
    }

    public function save(string $identifier, array $settings): bool
    {
        // Before 훅
        HookManager::doAction('core.module_settings.before_save', $identifier, $settings);

        // 필터 훅 - 설정 데이터 변형
        $settings = HookManager::applyFilters('core.module_settings.filter_save_data', $settings, $identifier);

        // 모듈 인스턴스 확인
        $moduleInstance = $this->moduleManager->getModule($identifier);
        if (! $moduleInstance) {
            return false;
        }

        // 기본값 로드
        $defaults = $moduleInstance->getConfigValues();

        // defaults 스키마에 맞게 정규화
        $settings = $this->normalizeCategoryData($settings, $defaults);

        // 스키마 기반으로 민감한 필드 암호화
        $schema = $moduleInstance->getSettingsSchema();
        $settings = $this->encryptSensitiveFields($settings, $schema);

        // 기존 설정과 병합
        $existingSettings = $this->loadSettingsFromFile($identifier);
        $mergedSettings = array_merge($existingSettings, $settings);

        // 파일에 저장
        $result = $this->saveSettingsToFile($identifier, $mergedSettings);

        // 캐시 초기화
        if ($result) {
            unset($this->settingsCache[$identifier]);
        }

        // After 훅
        HookManager::doAction('core.module_settings.after_save', $identifier, $mergedSettings, $result);

        return $result;
    }

    /**
     * 설정을 파일에 저장합니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @param  array  $settings  설정 배열
     * @return bool 저장 성공 여부
     */
    private function saveSettingsToFile(string $identifier, array $settings): bool
    {
        $moduleInstance = $this->moduleManager->getModule($identifier);
        if (! $moduleInstance) {
            return false;
        }

        $storage = $moduleInstance->getStorage();
        $content = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return $storage->put('settings', self::SETTINGS_FILENAME, $content);
    }

    public function reset(string $identifier): bool
    {
        // Before 훅
        HookManager::doAction('core.module_settings.before_reset', $identifier);

        $moduleInstance = $this->moduleManager->getModule($identifier);
        if (! $moduleInstance) {
            // 캐시만 초기화
            unset($this->settingsCache[$identifier]);

            return true;
        }

        $storage = $moduleInstance->getStorage();

        if ($storage->exists('settings', self::SETTINGS_FILENAME)) {
            $storage->delete('settings', self::SETTINGS_FILENAME);
        }

        // 캐시 초기화
        unset($this->settingsCache[$identifier]);

        // After 훅
        HookManager::doAction('core.module_settings.after_reset', $identifier);

        return true;
    }

    public function deleteSettingsDirectory(string $identifier): bool
    {
        // Before 훅
        HookManager::doAction('core.module_settings.before_delete_directory', $identifier);

        $moduleInstance = $this->moduleManager->getModule($identifier);
        if ($moduleInstance) {
            $storage = $moduleInstance->getStorage();

            // 모듈 전체 스토리지 디렉토리 삭제 ({identifier}/)
            $storage->deleteDirectory('', '');
        }

        // 캐시 초기화
        unset($this->settingsCache[$identifier]);

        // After 훅
        HookManager::doAction('core.module_settings.after_delete_directory', $identifier);

        return true;
    }

    /**
     * 설정 캐시를 초기화합니다.
     *
     * @param  string|null  $identifier  특정 모듈만 초기화 (null이면 전체)
     */
    public function clearCache(?string $identifier = null): void
    {
        if ($identifier !== null) {
            unset($this->settingsCache[$identifier]);
        } else {
            $this->settingsCache = [];
        }
    }

    /**
     * 활성화된 모든 모듈의 설정을 조회합니다.
     *
     * 프론트엔드 전역 변수(G7Config.modules)로 주입하기 위해 사용됩니다.
     * defaults.json의 frontend_schema 기반으로 expose: true인 필드만 포함됩니다.
     * 공통 설정 시스템을 사용하는 모듈만 포함됩니다.
     *
     * @return array<string, array> 모듈 식별자를 키로 하는 설정 배열
     */
    public function getAllActiveSettings(): array
    {
        $result = [];
        $activeModules = $this->moduleManager->getActiveModules();

        foreach ($activeModules as $module) {
            $identifier = $module->getIdentifier();

            // 공통 설정 시스템을 사용하는 모듈만 처리
            if (! $module->hasSettings()) {
                continue;
            }

            // 설정 조회 (이미 복호화됨)
            $settings = $this->get($identifier);

            // defaults.json에서 frontend_schema 로드
            $frontendSchema = $this->loadFrontendSchema($module->getSettingsDefaultsPath());

            // frontend_schema 기반으로 expose: true인 필드만 필터링
            $settings = $this->filterByFrontendSchema($settings, $frontendSchema);

            // 필터링 후 설정이 있는 경우만 결과에 포함
            if (! empty($settings)) {
                $result[$identifier] = $settings;
            }
        }

        return $result;
    }

    /**
     * 민감한 필드를 암호화합니다.
     *
     * @param  array  $settings  설정 배열
     * @param  array  $schema  설정 스키마
     * @return array 암호화된 설정 배열
     */
    private function encryptSensitiveFields(array $settings, array $schema): array
    {
        foreach ($schema as $field => $config) {
            if (($config['sensitive'] ?? false) && isset($settings[$field]) && $settings[$field] !== '') {
                // 이미 암호화된 값인지 확인 (eyJ로 시작하는 Base64)
                if (! $this->isEncrypted($settings[$field])) {
                    $settings[$field] = Crypt::encryptString($settings[$field]);
                }
            }
        }

        return $settings;
    }

    /**
     * 민감한 필드를 복호화합니다.
     *
     * @param  array  $config  설정 배열
     * @param  array  $schema  설정 스키마
     * @return array 복호화된 설정 배열
     */
    private function decryptSensitiveFields(array $config, array $schema): array
    {
        foreach ($schema as $field => $fieldConfig) {
            if (($fieldConfig['sensitive'] ?? false) && isset($config[$field]) && $config[$field] !== '') {
                try {
                    $config[$field] = Crypt::decryptString($config[$field]);
                } catch (\Exception $e) {
                    // 복호화 실패 시 원래 값 유지 (암호화되지 않은 레거시 데이터)
                }
            }
        }

        return $config;
    }

    /**
     * 값이 이미 암호화되어 있는지 확인합니다.
     *
     * @param  string  $value  확인할 값
     * @return bool 암호화 여부
     */
    private function isEncrypted(string $value): bool
    {
        // Laravel Crypt는 JSON을 Base64 인코딩하므로 eyJ로 시작
        if (! str_starts_with($value, 'eyJ')) {
            return false;
        }

        try {
            Crypt::decryptString($value);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
