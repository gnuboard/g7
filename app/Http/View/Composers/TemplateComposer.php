<?php

namespace App\Http\View\Composers;

use App\Exceptions\TemplateNotFoundException;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Http\View\Composers\Traits\CollectsActiveExtensionMeta;
use App\Http\View\Composers\Traits\CollectsExtensionAssets;
use App\Http\View\Composers\Traits\CollectsTemplateExternals;
use App\Services\ModuleSettingsService;
use App\Services\PluginSettingsService;
use App\Services\SettingsService;
use App\Services\TemplateService;
use App\Support\TrustedScriptHosts;
use Illuminate\View\View;

class TemplateComposer
{
    use CollectsActiveExtensionMeta;
    use CollectsExtensionAssets;
    use CollectsTemplateExternals;

    /**
     * 서비스 주입
     *
     * @param  TemplateService  $templateService  템플릿 서비스
     * @param  SettingsService  $settingsService  코어 설정 서비스
     * @param  ModuleSettingsService  $moduleSettingsService  모듈 설정 서비스
     * @param  PluginSettingsService  $pluginSettingsService  플러그인 설정 서비스
     * @param  ModuleManager  $moduleManager  모듈 매니저
     * @param  PluginManager  $pluginManager  플러그인 매니저
     * @param  TemplateManager  $templateManager  템플릿 매니저
     */
    public function __construct(
        private TemplateService $templateService,
        private SettingsService $settingsService,
        private ModuleSettingsService $moduleSettingsService,
        private PluginSettingsService $pluginSettingsService,
        private ModuleManager $moduleManager,
        private PluginManager $pluginManager,
        private TemplateManager $templateManager
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

        // 활성 확장(모듈/플러그인) 메타 — 레이아웃 편집기 SSoT
        // 기존 modules/plugins 키는 hasSettings() 필터로 활성 전수가 아님
        $activeModulesMeta = $this->collectActiveModulesMeta();
        $activePluginsMeta = $this->collectActivePluginsMeta();

        // 프론트엔드에 노출할 앱 config 값 조회
        try {
            $appConfig = $this->settingsService->getAppConfigForFrontend();
        } catch (\Exception $e) {
            $appConfig = [];
        }

        // 템플릿의 외부 리소스 정보 수집
        $templateExternals = $this->collectTemplateExternals($activeTemplate);

        // 확장 기능 캐시 버전 (브라우저 캐시 무효화용)
        $extensionCacheVersion = ClearsTemplateCaches::getExtensionCacheVersion();

        // 확장 프론트엔드 병합 번들 URL (상시 ON — 활성 에셋이 없으면 null)
        $bundleUrls = $this->buildExtensionBundleUrls($moduleAssets, $pluginAssets, $extensionCacheVersion);

        // 신뢰 외부 스크립트 호스트 — 레이아웃 scripts[].src same-origin 예외 허용목록
        // (KVE-2026-1915: 확장이 manifest 로 선언한 CDN 호스트만 런타임 로더가 허용)
        $trustedScriptHosts = TrustedScriptHosts::hosts();

        $view->with('activeAdminTemplate', $activeTemplate);
        $view->with('extensionCacheVersion', $extensionCacheVersion);
        $view->with('frontendSettings', $frontendSettings);
        $view->with('pluginSettings', $pluginSettings);
        $view->with('moduleSettings', $moduleSettings);
        $view->with('moduleAssets', $moduleAssets);
        $view->with('pluginAssets', $pluginAssets);
        $view->with('bundleUrls', $bundleUrls);
        $view->with('activeModulesMeta', $activeModulesMeta);
        $view->with('activePluginsMeta', $activePluginsMeta);
        $view->with('appConfig', $appConfig);
        $view->with('templateExternals', $templateExternals);
        $view->with('trustedScriptHosts', $trustedScriptHosts);
    }
}
