<?php

namespace App\Http\View\Composers;

use App\Exceptions\TemplateNotFoundException;
use App\Extension\Consent\ConsentManager;
use App\Extension\Consent\ConsentSnapshot;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Http\View\Composers\Traits\CollectsActiveExtensionMeta;
use App\Http\View\Composers\Traits\CollectsCustomAssets;
use App\Http\View\Composers\Traits\CollectsExtensionAssets;
use App\Http\View\Composers\Traits\CollectsTemplateExternals;
use App\Services\ModuleSettingsService;
use App\Services\PluginSettingsService;
use App\Services\SettingsService;
use App\Services\TemplateService;
use App\Support\StateContracts;
use App\Support\TrustedScriptHosts;
use Illuminate\View\View;

/**
 * 사용자 템플릿 View Composer
 *
 * app.blade.php 뷰에 사용자 템플릿 관련 데이터를 바인딩합니다.
 */
class UserTemplateComposer
{
    use CollectsActiveExtensionMeta;
    use CollectsCustomAssets;
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
     *
     * @param  View  $view  뷰 인스턴스
     * @return void 반환값 없음
     */
    public function compose(View $view): void
    {
        try {
            $activeTemplate = $this->templateService->getActiveTemplateIdentifier('user');
        } catch (TemplateNotFoundException $e) {
            $activeTemplate = null;
        }

        // 프론트엔드 전역 변수로 사용할 설정 조회
        try {
            $frontendSettings = $this->settingsService->getFrontendSettings();
        } catch (\Exception $e) {
            $frontendSettings = [];
        }

        // 동의 연동 모드 트래커가 「동의를 확인할 곳이 있는가」를 알아야 한다. 코어는 어느 확장이
        // 답하는지 모르고, 동의 제공자 선택 상태가 none 이 아닌지만 계산해 싣는다(설정이 아니라 계산값).
        $governsConsent = false;
        try {
            $governsConsent = app(ConsentManager::class)->selection()->governsConsent();
        } catch (\Throwable $e) {
            $governsConsent = false;
        }

        if (isset($frontendSettings['analytics']) && is_array($frontendSettings['analytics'])) {
            $frontendSettings['analytics']['consent_provider'] = $governsConsent;
        }

        // 코어 동의 런타임의 첫 값 — 회원 토큰은 브라우저 저장소에 있어 이 시점에는 누구의 동의인지 모른다.
        // 그래서 `ready` 를 싣지 않고, 제공자가 없으면 `none`, 있으면 `unknown` 으로 두고 런타임이 곧바로 조회한다.
        $consentInitial = $governsConsent ? ConsentSnapshot::unknown()->toArray() : ConsentSnapshot::none()->toArray();

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

        // 확장 기능 캐시 버전 (브라우저 캐시 무효화용)
        $extensionCacheVersion = ClearsTemplateCaches::getExtensionCacheVersion();

        // 템플릿의 외부 리소스 정보 수집
        // (자체 제공 `asset` 항목의 URL 을 만들 때 캐시 버전이 필요해 뒤로 옮겼다)
        $templateExternals = $this->collectTemplateExternals($activeTemplate, $extensionCacheVersion);

        // 확장 프론트엔드 병합 번들 URL (상시 ON — 활성 에셋이 없으면 null)
        $bundleUrls = $this->buildExtensionBundleUrls($moduleAssets, $pluginAssets, $extensionCacheVersion);

        // 신뢰 외부 스크립트 호스트 — 레이아웃 scripts[].src same-origin 예외 허용목록
        // (KVE-2026-1915: 확장이 manifest 로 선언한 CDN 호스트만 런타임 로더가 허용)
        $trustedScriptHosts = TrustedScriptHosts::hosts();

        $view->with('activeUserTemplate', $activeTemplate);
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
        $view->with('customAssets', $this->collectCustomAssets($activeTemplate));
        $view->with('customAssetsDisabled', $this->customAssetsDisabledByRequest());
        $view->with('trustedScriptHosts', $trustedScriptHosts);
        // 전역 상태 공개 계약 — G7Core.stateContracts 가 읽는다 (#639)
        $view->with('stateContracts', StateContracts::forFrontend());
        $view->with('consentInitial', $consentInitial);
    }
}
