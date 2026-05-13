<?php

namespace App\Http\View\Composers;

use App\Exceptions\TemplateNotFoundException;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Services\ModuleSettingsService;
use App\Services\PluginSettingsService;
use App\Services\SettingsService;
use App\Services\TemplateService;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class TemplateComposer
{
    /**
     * 서비스 주입
     *
     * @param  TemplateService  $templateService  템플릿 서비스
     * @param  SettingsService  $settingsService  코어 설정 서비스
     * @param  ModuleSettingsService  $moduleSettingsService  모듈 설정 서비스
     * @param  PluginSettingsService  $pluginSettingsService  플러그인 설정 서비스
     * @param  ModuleManager  $moduleManager  모듈 매니저
     * @param  PluginManager  $pluginManager  플러그인 매니저
     */
    public function __construct(
        private TemplateService $templateService,
        private SettingsService $settingsService,
        private ModuleSettingsService $moduleSettingsService,
        private PluginSettingsService $pluginSettingsService,
        private ModuleManager $moduleManager,
        private PluginManager $pluginManager
    ) {}

    /**
     * 뷰에 데이터 바인딩
     */
    public function compose(View $view): void
    {
        try {
            $activeTemplate = $this->templateService->getActiveTemplateIdentifier('admin');
        } catch (TemplateNotFoundException $e) {
            $activeTemplate = null;
        }

        // 프론트엔드 전역 변수로 사용할 설정 조회
        // SettingsService의 공통 메서드 사용
        try {
            $frontendSettings = $this->settingsService->getFrontendSettings();
        } catch (\Exception $e) {
            $frontendSettings = [];
        }

        // 활성화된 플러그인 설정 조회
        try {
            $pluginSettings = $this->pluginSettingsService->getAllActiveSettings();
        } catch (\Exception $e) {
            $pluginSettings = [];
        }

        // 활성화된 모듈 설정 조회 (frontend_schema 기반 필터링 적용)
        try {
            $moduleSettings = $this->moduleSettingsService->getAllActiveSettings();
        } catch (\Exception $e) {
            $moduleSettings = [];
        }

        // 활성화된 모듈의 프론트엔드 에셋 정보 수집
        $moduleAssets = $this->collectModuleAssets();

        // 활성화된 플러그인의 프론트엔드 에셋 정보 수집
        $pluginAssets = $this->collectPluginAssets();

        // 프론트엔드에 노출할 앱 config 값 조회
        try {
            $appConfig = $this->settingsService->getAppConfigForFrontend();
        } catch (\Exception $e) {
            $appConfig = [];
        }

        $view->with('activeAdminTemplate', $activeTemplate);
        $view->with('frontendSettings', $frontendSettings);
        $view->with('pluginSettings', $pluginSettings);
        $view->with('moduleSettings', $moduleSettings);
        $view->with('moduleAssets', $moduleAssets);
        $view->with('pluginAssets', $pluginAssets);
        $view->with('appConfig', $appConfig);
    }

    /**
     * 활성화된 모듈의 프론트엔드 에셋 정보를 수집합니다.
     *
     * @return array<string, array{js?: string, css?: string, priority: int, external?: array}>
     */
    private function collectModuleAssets(): array
    {
        $assets = [];

        try {
            // ModuleManager에서 활성화된 모듈 목록 조회
            $activeModules = $this->moduleManager->getActiveModules();

            // 캐시 버전 조회 (브라우저 캐시 무효화용)
            $cacheVersion = ClearsTemplateCaches::getExtensionCacheVersion();

            foreach ($activeModules as $identifier => $module) {
                // 모듈에 에셋이 있는지 확인
                if (! $module->hasAssets()) {
                    continue;
                }

                $builtPaths = $module->getBuiltAssetPaths();
                $loadingConfig = $module->getAssetLoadingConfig();
                $assetConfig = $module->getAssets();

                // global 전략인 경우에만 수집 (layout, lazy는 레이아웃에서 처리)
                if ($loadingConfig['strategy'] !== 'global') {
                    continue;
                }

                $moduleAsset = [
                    'priority' => $loadingConfig['priority'],
                ];

                // JS 빌드 경로 (캐시 버전 파라미터 추가)
                if (! empty($builtPaths['js'])) {
                    $moduleAsset['js'] = "/api/modules/assets/{$identifier}/".$builtPaths['js']."?v={$cacheVersion}";
                }

                // CSS 빌드 경로 (캐시 버전 파라미터 추가)
                if (! empty($builtPaths['css'])) {
                    $moduleAsset['css'] = "/api/modules/assets/{$identifier}/".$builtPaths['css']."?v={$cacheVersion}";
                }

                // 외부 스크립트 (조건부 로드용)
                if (! empty($assetConfig['external'])) {
                    $moduleAsset['external'] = $assetConfig['external'];
                }

                $assets[$identifier] = $moduleAsset;
            }

            // 우선순위 기준 정렬 (낮을수록 먼저)
            uasort($assets, fn ($a, $b) => $a['priority'] <=> $b['priority']);
        } catch (\Exception $e) {
            // 에러 발생 시 빈 배열 반환 (에셋 로드 실패해도 앱 진행)
            Log::warning('Failed to collect module assets: '.$e->getMessage());
        }

        return $assets;
    }

    /**
     * 활성화된 플러그인의 프론트엔드 에셋 정보를 수집합니다.
     *
     * @return array<string, array{js?: string, css?: string, priority: int, external?: array}>
     */
    private function collectPluginAssets(): array
    {
        $assets = [];

        try {
            // PluginManager에서 활성화된 플러그인 목록 조회
            $activePlugins = $this->pluginManager->getActivePlugins();

            // 캐시 버전 조회 (브라우저 캐시 무효화용)
            $cacheVersion = ClearsTemplateCaches::getExtensionCacheVersion();

            foreach ($activePlugins as $identifier => $plugin) {
                // 플러그인에 에셋이 있는지 확인
                if (! $plugin->hasAssets()) {
                    continue;
                }

                $builtPaths = $plugin->getBuiltAssetPaths();
                $loadingConfig = $plugin->getAssetLoadingConfig();
                $assetConfig = $plugin->getAssets();

                // global 전략인 경우에만 수집 (layout, lazy는 레이아웃에서 처리)
                if ($loadingConfig['strategy'] !== 'global') {
                    continue;
                }

                $pluginAsset = [
                    'priority' => $loadingConfig['priority'],
                ];

                // JS 빌드 경로 (캐시 버전 파라미터 추가)
                if (! empty($builtPaths['js'])) {
                    $pluginAsset['js'] = "/api/plugins/assets/{$identifier}/".$builtPaths['js']."?v={$cacheVersion}";
                }

                // CSS 빌드 경로 (캐시 버전 파라미터 추가)
                if (! empty($builtPaths['css'])) {
                    $pluginAsset['css'] = "/api/plugins/assets/{$identifier}/".$builtPaths['css']."?v={$cacheVersion}";
                }

                // 외부 스크립트 (조건부 로드용)
                if (! empty($assetConfig['external'])) {
                    $pluginAsset['external'] = $assetConfig['external'];
                }

                $assets[$identifier] = $pluginAsset;
            }

            // 우선순위 기준 정렬 (낮을수록 먼저)
            uasort($assets, fn ($a, $b) => $a['priority'] <=> $b['priority']);
        } catch (\Exception $e) {
            // 에러 발생 시 빈 배열 반환 (에셋 로드 실패해도 앱 진행)
            Log::warning('Failed to collect plugin assets: '.$e->getMessage());
        }

        return $assets;
    }
}
