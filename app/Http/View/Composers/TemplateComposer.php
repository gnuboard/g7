<?php

namespace App\Http\View\Composers;

use App\Exceptions\TemplateNotFoundException;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Http\View\Composers\Traits\CollectsActiveExtensionMeta;
use App\Http\View\Composers\Traits\CollectsCustomAssets;
use App\Http\View\Composers\Traits\CollectsExtensionAssets;
use App\Http\View\Composers\Traits\CollectsExtensionCssContract;
use App\Http\View\Composers\Traits\CollectsTemplateExternals;
use App\Http\View\Composers\Traits\CollectsTemplateStylesheets;
use App\Services\FrontendConfigService;
use App\Services\TemplateService;
use App\Support\StateContracts;
use App\Support\TrustedScriptHosts;
use Illuminate\View\View;

class TemplateComposer
{
    use CollectsActiveExtensionMeta;
    use CollectsCustomAssets;
    use CollectsExtensionAssets;
    use CollectsExtensionCssContract;
    use CollectsTemplateExternals;
    use CollectsTemplateStylesheets;

    /**
     * 서비스 주입
     *
     * @param  TemplateService  $templateService  템플릿 서비스
     * @param  ModuleManager  $moduleManager  모듈 매니저
     * @param  PluginManager  $pluginManager  플러그인 매니저
     * @param  TemplateManager  $templateManager  템플릿 매니저
     * @param  FrontendConfigService  $frontendConfigService  프론트엔드 공개 설정 조립
     */
    public function __construct(
        private TemplateService $templateService,
        private ModuleManager $moduleManager,
        private PluginManager $pluginManager,
        private TemplateManager $templateManager,
        private FrontendConfigService $frontendConfigService,
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
            $activeTemplate = $this->templateService->getActiveTemplateIdentifier('admin');
        } catch (TemplateNotFoundException $e) {
            $activeTemplate = null;
        }

        // 프론트엔드 공개 설정 — 관리자 공개 설정 API(`GET /api/admin/frontend-config`)와 같은 함수로 조립한다(#792 D8)
        $bundle = $this->frontendConfigService->settingsBundle(FrontendConfigService::SCOPE_ADMIN);
        $frontendSettings = $bundle['settings'];
        $pluginSettings = $bundle['plugins'];
        $templateSettings = $bundle['templateSettings'];
        $moduleSettings = $bundle['modules'];

        // 활성화된 모듈의 프론트엔드 에셋 정보 수집
        $moduleAssets = $this->collectModuleAssets();

        // 활성화된 플러그인의 프론트엔드 에셋 정보 수집
        $pluginAssets = $this->collectPluginAssets();

        // 활성 확장(모듈/플러그인) 메타 — 레이아웃 편집기 SSoT
        // 기존 modules/plugins 키는 hasSettings() 필터로 활성 전수가 아님
        $activeModulesMeta = $this->collectActiveModulesMeta();
        $activePluginsMeta = $this->collectActivePluginsMeta();

        // 프론트엔드에 노출할 앱 config 값 (공개 설정 API 와 같은 값)
        $appConfig = $bundle['appConfig'];

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

        $view->with('activeAdminTemplate', $activeTemplate);
        $view->with('extensionCacheVersion', $extensionCacheVersion);
        // 카테고리 값은 화면이 이름표로 읽는 맵이다 — 빈 카테고리(`identity`)를 `{}` 로 바꾸는 일은 공개 설정 API 와 같은 조립 함수가 한다
        $view->with('frontendSettings', $frontendSettings);
        $view->with('pluginSettings', $pluginSettings);
        $view->with('templateSettings', $templateSettings);
        $view->with('moduleSettings', $moduleSettings);
        $view->with('moduleAssets', $moduleAssets);
        $view->with('pluginAssets', $pluginAssets);
        $view->with('bundleUrls', $bundleUrls);
        $view->with('activeModulesMeta', $activeModulesMeta);
        $view->with('activePluginsMeta', $activePluginsMeta);
        $view->with('appConfig', $appConfig);
        $view->with('templateExternals', $templateExternals);
        // 템플릿이 선언하고 산출물이 있는 CSS 만 링크한다 — CSS 없는 템플릿에 없는 파일 요청을 남기지 않는다
        $view->with('templateStylesheets', $this->collectTemplateStylesheets($activeTemplate));
        $view->with('customAssets', $this->collectCustomAssets($activeTemplate));
        // 확장 CSS 자기 공급 계약 — 템플릿 다크 방식 선언(중립 표식용)과 확장 CSS 덮기 파일
        $view->with('colorScheme', $this->collectColorScheme($activeTemplate));
        $view->with('extensionCssOverrides', $this->collectExtensionCssOverrides($activeTemplate, $extensionCacheVersion));
        $view->with('extensionClassPrefixes', $this->collectExtensionClassPrefixes());
        $view->with('customAssetsDisabled', $this->customAssetsDisabledByRequest());
        $view->with('trustedScriptHosts', $trustedScriptHosts);
        // 전역 상태 공개 계약 — G7Core.stateContracts 가 읽는다 (#639)
        $view->with('stateContracts', StateContracts::forFrontend());
        // 엔진이 전체 페이지 이동으로 넘길 custom 주소(routes/custom.php, #792 W5) · 실시간 알림 접속정보
        $view->with('customRoutes', $this->frontendConfigService->customRoutes());
        $view->with('websocketConfig', $this->frontendConfigService->websocket());
        $view->with('frontendLocale', $this->frontendConfigService->locale());
    }
}
