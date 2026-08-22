<?php

namespace App\Services;

use App\Extension\HookManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Support\ExtensionSettingsMirror;
use App\Support\SensitiveSettingMask;
use App\Traits\FiltersFrontendSchema;
use App\Traits\NormalizesSettingsData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 플러그인 설정 관리 서비스
 *
 * 플러그인의 설정값을 파일 기반으로 조회, 저장하며 민감한 필드는 암호화합니다.
 * 설정 파일은 storage/app/plugins/{identifier}/settings/setting.json에 저장됩니다.
 * 설정 레이아웃은 플러그인이 제공하는 JSON 파일에서 읽어옵니다.
 */
class PluginSettingsService
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
     * PluginSettingsService 생성자
     *
     * @param  PluginManager  $pluginManager  플러그인 매니저
     * @param  TemplateManager  $templateManager  템플릿 매니저 (오버라이드 확인용)
     * @param  LayoutService  $layoutService  레이아웃 서비스 (sanitize 재사용)
     */
    public function __construct(
        private PluginManager $pluginManager,
        private TemplateManager $templateManager,
        private LayoutService $layoutService
    ) {}

    /**
     * 플러그인 설정을 조회합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  string|null  $key  특정 설정 키 (null이면 전체 설정, 도트 노테이션 지원)
     * @param  mixed  $default  기본값
     * @return mixed 설정 값
     */
    public function get(string $identifier, ?string $key = null, mixed $default = null): mixed
    {
        // 캐시된 설정이 있으면 사용
        if (isset($this->settingsCache[$identifier])) {
            $settings = $this->settingsCache[$identifier];

            if ($key === null) {
                return $settings;
            }

            return Arr::get($settings, $key, $default);
        }

        // 플러그인 인스턴스 확인
        $pluginInstance = $this->pluginManager->getPlugin($identifier);
        if (! $pluginInstance) {
            return $default;
        }

        // 기본값 로드
        $defaults = $pluginInstance->getConfigValues();

        // 저장된 설정 파일 로드
        $savedSettings = $this->loadSettingsFromFile($identifier);

        // 기본값과 저장된 설정 병합
        $settings = array_merge($defaults, $savedSettings);

        // 스키마 기반으로 민감한 필드 복호화
        $schema = $pluginInstance->getSettingsSchema();
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
     * 설정 파일에서 설정을 로드합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @return array 설정 배열
     */
    private function loadSettingsFromFile(string $identifier): array
    {
        $pluginInstance = $this->pluginManager->getPlugin($identifier);
        if (! $pluginInstance) {
            return [];
        }

        $storage = $pluginInstance->getStorage();

        if (! $storage->exists('settings', self::SETTINGS_FILENAME)) {
            return [];
        }

        $content = $storage->get('settings', self::SETTINGS_FILENAME);
        if ($content === null) {
            return [];
        }

        $settings = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('플러그인 설정 파일 JSON 파싱 실패', [
                'identifier' => $identifier,
                'error' => json_last_error_msg(),
            ]);

            return [];
        }

        return $settings ?? [];
    }

    /**
     * 플러그인 설정을 저장합니다.
     *
     * 스키마의 sensitive 필드는 암호화하여 보관하며, 화면이 마스크를 그대로 되돌려 보낸 항목은
     * "변경 없음" 으로 보아 기존 값을 유지합니다. 저장은 기존 설정 위 병합이므로 요청에 없는
     * 키는 종전 값이 남습니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  array<string, mixed>  $settings  저장할 설정 (검증 통과분)
     * @param  string|null  $failureReason  실패 시 사유가 담기는 out 파라미터 (성공 시 null)
     * @return bool 저장 성공 여부
     */
    public function save(string $identifier, array $settings, ?string &$failureReason = null): bool
    {
        $failureReason = null;

        // Before 훅
        HookManager::doAction('core.plugin_settings.before_save', $identifier, $settings);

        // 필터 훅 - 설정 데이터 변형
        $settings = HookManager::applyFilters('core.plugin_settings.filter_save_data', $settings, $identifier);

        // 플러그인 인스턴스 확인
        $pluginInstance = $this->pluginManager->getPlugin($identifier);
        if (! $pluginInstance) {
            $failureReason = __('plugins.errors.not_found', ['plugin' => $identifier]);

            return false;
        }

        // 기본값 로드
        $defaults = $pluginInstance->getConfigValues();

        // defaults 스키마에 맞게 정규화
        $settings = $this->normalizeCategoryData($settings, $defaults);

        // 스키마 기반으로 민감한 필드 암호화
        $schema = $pluginInstance->getSettingsSchema();
        // 화면이 마스크를 그대로 되돌려 보낸 sensitive 필드는 "변경 없음" 이다 — 병합에서 기존 값이
        // 유지되도록 요청에서 제거한다. 빈 문자열은 제거하지 않는다(지우겠다는 명시적 의사).
        $settings = SensitiveSettingMask::stripUnchanged($settings, $schema);
        $settings = $this->encryptSensitiveFields($settings, $schema);

        // 기존 설정과 병합
        $existingSettings = $this->loadSettingsFromFile($identifier);
        $mergedSettings = array_merge($existingSettings, $settings);

        // 파일에 저장
        $result = $this->saveSettingsToFile($identifier, $mergedSettings);

        if (! $result && $failureReason === null) {
            // 파일 쓰기 실패 — 스토리지 드라이버가 사유를 돌려주지 않으므로 일반 문구로 대체한다.
            $failureReason = __('plugins.errors.unknown_error');
        }

        // 캐시 초기화
        if ($result) {
            unset($this->settingsCache[$identifier]);

            // 같은 프로세스의 config 미러도 즉시 다시 채운다 (공개이슈 #109) —
            // 이 호출이 없으면 상주 프로세스가 저장 후에도 옛 값을 계속 읽는다.
            app(ExtensionSettingsMirror::class)->refreshPlugin($identifier);
        }

        // After 훅
        HookManager::doAction('core.plugin_settings.after_save', $identifier, $mergedSettings, $result);

        return $result;
    }

    /**
     * 설정을 파일에 저장합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  array  $settings  설정 배열
     * @return bool 저장 성공 여부
     */
    private function saveSettingsToFile(string $identifier, array $settings): bool
    {
        $pluginInstance = $this->pluginManager->getPlugin($identifier);
        if (! $pluginInstance) {
            return false;
        }

        $storage = $pluginInstance->getStorage();
        $content = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return $storage->put('settings', self::SETTINGS_FILENAME, $content);
    }

    /**
     * 플러그인 설정을 기본값으로 되돌립니다.
     *
     * 설정 파일을 삭제하고 캐시를 비웁니다 — 이후 조회는 플러그인이 선언한 기본값을 돌려줍니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @return bool 초기화 성공 여부
     */
    public function reset(string $identifier): bool
    {
        // Before 훅
        HookManager::doAction('core.plugin_settings.before_reset', $identifier);

        $pluginInstance = $this->pluginManager->getPlugin($identifier);
        if (! $pluginInstance) {
            // 캐시만 초기화
            unset($this->settingsCache[$identifier]);

            return true;
        }

        $storage = $pluginInstance->getStorage();

        if ($storage->exists('settings', self::SETTINGS_FILENAME)) {
            $storage->delete('settings', self::SETTINGS_FILENAME);
        }

        // 캐시 초기화
        unset($this->settingsCache[$identifier]);

        // 초기화도 값을 바꾸는 쓰기다 — 미러를 두면 같은 프로세스가 초기화 전 값을
        // 계속 읽는다 (저장 경로와 동일 결함, 공개이슈 #109).
        app(ExtensionSettingsMirror::class)->refreshPlugin($identifier);

        // After 훅
        HookManager::doAction('core.plugin_settings.after_reset', $identifier);

        return true;
    }

    /**
     * 플러그인 설정 디렉토리를 통째로 삭제합니다.
     *
     * 플러그인 삭제 시 남은 설정 파일을 정리하는 용도입니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @return bool 삭제 성공 여부
     */
    public function deleteSettingsDirectory(string $identifier): bool
    {
        // Before 훅
        HookManager::doAction('core.plugin_settings.before_delete_directory', $identifier);

        $pluginInstance = $this->pluginManager->getPlugin($identifier);
        if ($pluginInstance) {
            $storage = $pluginInstance->getStorage();

            // 플러그인 전체 스토리지 디렉토리 삭제 ({identifier}/)
            $storage->deleteDirectory('', '');
        }

        // 캐시 초기화
        unset($this->settingsCache[$identifier]);

        // After 훅
        HookManager::doAction('core.plugin_settings.after_delete_directory', $identifier);

        return true;
    }

    /**
     * 설정 캐시를 초기화합니다.
     *
     * @param  string|null  $identifier  특정 플러그인만 초기화 (null이면 전체)
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
     * 활성화된 모든 플러그인의 설정을 조회합니다.
     *
     * 프론트엔드 전역 변수(G7Config.plugins)로 주입하기 위해 사용됩니다.
     * 민감한 필드(sensitive: true)는 제외됩니다.
     *
     * @return array<string, array> 플러그인 식별자를 키로 하는 설정 배열
     */
    public function getAllActiveSettings(): array
    {
        $result = [];
        $activePlugins = $this->pluginManager->getActivePlugins();

        foreach ($activePlugins as $plugin) {
            $identifier = $plugin->getIdentifier();

            // 설정이 있는 플러그인만 처리
            if (! $plugin->hasSettings()) {
                continue;
            }

            // 설정 조회 (이미 복호화됨)
            $settings = $this->get($identifier);

            // defaults.json에서 frontend_schema 로드
            $frontendSchema = $this->loadFrontendSchema($plugin->getSettingsDefaultsPath());

            if (! empty($frontendSchema)) {
                // frontend_schema가 있으면 스키마 기반 필터링 (모듈과 동일)
                $settings = $this->filterByFrontendSchema($settings, $frontendSchema);
            } else {
                // frontend_schema가 없으면 기존 동작 유지 (하위 호환성)
                $schema = $plugin->getSettingsSchema();
                $settings = $this->excludeSensitiveFields($settings, $schema);
            }

            if (! empty($settings)) {
                $result[$identifier] = $settings;
            }
        }

        return $result;
    }

    /**
     * 민감한 필드를 설정에서 제외합니다.
     *
     * @param  array  $settings  설정 배열
     * @param  array  $schema  설정 스키마
     * @return array 민감한 필드가 제외된 설정 배열
     */
    private function excludeSensitiveFields(array $settings, array $schema): array
    {
        foreach ($schema as $field => $config) {
            if ($config['sensitive'] ?? false) {
                unset($settings[$field]);
            }
        }

        return $settings;
    }

    /**
     * 플러그인 설정 레이아웃을 조회합니다.
     *
     * 우선순위:
     * 1. 현재 활성 템플릿의 오버라이드 (templates/{template}/layouts/plugins/{identifier}/admin/plugin_settings.json)
     * 2. 플러그인 기본 레이아웃 (plugins/{identifier}/resources/layouts/admin/plugin_settings.json)
     *
     * @param  string  $identifier  플러그인 식별자
     * @return array|null 레이아웃 데이터 또는 null
     */
    public function getLayout(string $identifier): ?array
    {
        $pluginInstance = $this->pluginManager->getPlugin($identifier);

        if (! $pluginInstance) {
            return null;
        }

        // 1순위: 템플릿 오버라이드 확인
        $layout = $this->getTemplateOverrideLayout($identifier);

        // 2순위: 플러그인 기본 레이아웃
        if ($layout === null) {
            $layoutPath = $pluginInstance->getSettingsLayout();

            if (! $layoutPath || ! File::exists($layoutPath)) {
                return null;
            }

            $layout = $this->parseLayoutFile($layoutPath);
        }

        if ($layout === null) {
            return null;
        }

        // LayoutService의 sanitize 재사용 (XSS 방지)
        return $this->layoutService->sanitizeLayoutJson($layout);
    }

    /**
     * 템플릿 오버라이드 레이아웃을 조회합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @return array|null 레이아웃 데이터 또는 null
     */
    private function getTemplateOverrideLayout(string $identifier): ?array
    {
        $activeTemplate = $this->templateManager->getActiveTemplate('admin');

        if (! $activeTemplate) {
            return null;
        }

        // 템플릿 경로에서 오버라이드 파일 확인
        $templatePath = $activeTemplate['_paths']['root'] ?? null;

        if (! $templatePath) {
            return null;
        }

        $overridePath = $templatePath.'/layouts/plugins/'.$identifier.'/admin/plugin_settings.json';

        if (! File::exists($overridePath)) {
            return null;
        }

        Log::debug('플러그인 설정 레이아웃 오버라이드 사용', [
            'plugin' => $identifier,
            'override_path' => $overridePath,
        ]);

        return $this->parseLayoutFile($overridePath);
    }

    /**
     * 레이아웃 파일을 파싱합니다.
     *
     * @param  string  $path  파일 경로
     * @return array|null 파싱된 레이아웃 또는 null
     */
    private function parseLayoutFile(string $path): ?array
    {
        $content = File::get($path);
        $layout = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('플러그인 설정 레이아웃 JSON 파싱 실패', [
                'path' => $path,
                'error' => json_last_error_msg(),
            ]);

            return null;
        }

        return $layout;
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
