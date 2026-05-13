<?php

namespace App\Seo;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Extension\HookManager;
use App\Seo\Concerns\LocalizesSeoValues;
use App\Seo\Concerns\SubstitutesSeoVariables;
use App\Seo\Contracts\SeoRendererInterface;
use App\Services\LayoutService;
use App\Services\PluginSettingsService;
use App\Services\SettingsService;
use App\Services\TemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

class SeoRenderer implements SeoRendererInterface
{
    use LocalizesSeoValues;
    use SubstitutesSeoVariables;

    public function __construct(
        private readonly TemplateRouteResolver $routeResolver,
        private readonly LayoutService $layoutService,
        private readonly TemplateService $templateService,
        private readonly DataSourceResolver $dataSourceResolver,
        private readonly SeoMetaResolver $metaResolver,
        private readonly ComponentHtmlMapper $htmlMapper,
        private readonly ExpressionEvaluator $evaluator,
        private readonly SeoConfigMerger $seoConfigMerger,
        private readonly SettingsService $settingsService,
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly ModuleManagerInterface $moduleManager,
        private readonly PluginManagerInterface $pluginManager,
    ) {}

    /**
     * 요청 URL에 매핑된 SEO HTML 을 렌더링합니다.
     *
     * @param  Request  $request  유입된 HTTP 요청
     * @return string|null  렌더된 HTML, SEO 비활성/매핑 없음/예외 발생 시 null
     */
    public function render(Request $request): ?string
    {
        $url = $request->getPathInfo();

        // SeoMiddleware가 ?locale= 파라미터 기반으로 이미 설정한 로케일 사용
        $locale = app()->getLocale();

        // 1. URL → 레이아웃 매핑
        $routeInfo = $this->routeResolver->resolve($url);
        if (! $routeInfo) {
            return null;
        }

        $templateIdentifier = $routeInfo['templateIdentifier'];
        $layoutName = $routeInfo['layoutName'];
        $routeParams = $routeInfo['routeParams'];
        $moduleIdentifier = $routeInfo['moduleIdentifier'];
        $pluginIdentifier = $routeInfo['pluginIdentifier'] ?? null;

        // 2. 병합된 레이아웃 JSON 로드
        try {
            $mergedLayout = $this->layoutService->getLayout($templateIdentifier, $layoutName);
        } catch (\Throwable $e) {
            Log::warning('[SEO] Layout load failed', [
                'layout' => $layoutName,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (empty($mergedLayout)) {
            return null;
        }

        // 3. meta.seo 확인
        $seoConfig = $mergedLayout['meta']['seo'] ?? null;
        if (! $seoConfig || ! ($seoConfig['enabled'] ?? false)) {
            return null;
        }

        // 4. 확장(모듈/플러그인) 페이지별 SEO 활성화 확인 (레이아웃 toggle_setting 기반)
        if (! $this->isExtensionSeoEnabled($moduleIdentifier, $pluginIdentifier, $seoConfig)) {
            return null;
        }

        // 5. DataSource 호출 (seo.data_sources + initGlobal 데이터소스)
        $seoDataSourceIds = $seoConfig['data_sources'] ?? [];
        $allDataSources = $mergedLayout['data_sources'] ?? [];

        $queryParams = $request->query();

        $context = [];
        if (! empty($seoDataSourceIds)) {
            $context = $this->dataSourceResolver->resolve(
                $allDataSources,
                $seoDataSourceIds,
                $routeParams,
                $locale,
                $queryParams
            );
        }

        // route 정보를 context에 추가 (path: 현재 URL 경로, + 동적 파라미터)
        $context['route'] = array_merge($routeParams, ['path' => $url]);

        // query 파라미터도 context에 추가
        $context['query'] = $queryParams;

        // SEO 컨텍스트에 _global/_local 추가
        // 프론트엔드에서 window.G7Config로 주입되는 설정을 서버사이드에서도 동일하게 제공
        $context['_global'] = $this->buildGlobalContext();

        // init_actions의 setState(target: local)을 평가하여 _local 초기화
        // 프론트엔드에서 init_actions로 설정하는 _local 상태를 SEO 렌더링에서도 동일하게 반영
        // initActions (camelCase, LayoutService 병합 결과) 또는 init_actions (snake_case, 원본 JSON) 둘 다 지원
        $initActions = $mergedLayout['initActions'] ?? $mergedLayout['init_actions'] ?? [];
        $context['_local'] = $this->resolveInitLocalState($initActions, $context);

        // 5.1. initGlobal 매핑: 데이터소스 결과를 _global 경로에 주입
        // 프론트엔드에서 data_source의 initGlobal 설정으로 _global에 매핑하는 것과 동일
        $this->applyInitGlobalMapping($allDataSources, $context);

        // 5.2. 훅: 확장이 컨텍스트 데이터를 보강할 수 있는 필터
        // 유즈케이스: 리뷰 플러그인이 reviews_aggregate 주입, 쿠폰 플러그인이 priceValidUntil 보강
        $context = HookManager::applyFilters('core.seo.filter_context', $context, [
            'layoutName' => $layoutName,
            'moduleIdentifier' => $moduleIdentifier,
            'pluginIdentifier' => $pluginIdentifier,
            'routeParams' => $routeParams,
            'locale' => $locale,
        ]);

        // 5.3. computed 속성 해석
        // 프론트엔드에서 TemplateApp.calculateComputed()로 처리하는 것과 동일
        // 결과를 _computed 및 $computed(별칭)에 저장하여 컴포넌트에서 참조 가능
        $computedDefs = $mergedLayout['computed'] ?? [];
        if (! empty($computedDefs)) {
            $computed = $this->resolveComputed($computedDefs, $context);
            $context['_computed'] = $computed;
            $context['$computed'] = $computed;
        }

        // 5.5. 템플릿 번역 데이터 로드 ($t: 키 해석용) + 파이프 로케일 설정
        $this->loadTemplateTranslations($templateIdentifier, $locale);
        $this->evaluator->getPipeRegistry()->setLocale($locale);

        // 5.8. 템플릿 SEO 설정 로드 (component_map, render_modes, self_closing)
        $seoTemplateConfig = $this->seoConfigMerger->getMergedConfig($templateIdentifier);
        if (! empty($seoTemplateConfig['component_map'])) {
            $this->htmlMapper->setComponentMap($seoTemplateConfig['component_map']);
        }
        if (! empty($seoTemplateConfig['render_modes'])) {
            $this->htmlMapper->setRenderModes($seoTemplateConfig['render_modes']);
        }
        if (! empty($seoTemplateConfig['self_closing'])) {
            $this->htmlMapper->setSelfClosing($seoTemplateConfig['self_closing']);
        }
        if (! empty($seoTemplateConfig['text_props'])) {
            $this->htmlMapper->setTextProps($seoTemplateConfig['text_props']);
        }
        if (! empty($seoTemplateConfig['attr_map'])) {
            $this->htmlMapper->setAttrMap($seoTemplateConfig['attr_map']);
        }
        if (! empty($seoTemplateConfig['allowed_attrs'])) {
            $this->htmlMapper->setAllowedAttrs($seoTemplateConfig['allowed_attrs']);
        }
        if (! empty($seoTemplateConfig['seo_overrides'])) {
            $this->evaluator->setSeoOverrides($seoTemplateConfig['seo_overrides']);
        }

        // 5.9. _global 표현식 해석 콜백 설정 (navigate 링크 생성용)
        $this->htmlMapper->setGlobalResolver(function (string $globalExpr): ?string {
            // _global.modules?.['module-id']?.key ?? 'default' 패턴
            if (preg_match("/modules\\?\\.\\['([^']+)'\\]\\?\\.([\\w?.]+)\\s*\\?\\?\\s*'(.+?)'/", $globalExpr, $matches)) {
                $moduleId = $matches[1];
                $settingKey = str_replace('?.', '.', $matches[2]);
                $default = $matches[3];

                return g7_module_settings($moduleId, $settingKey) ?? $default;
            }

            // _global.modules?.['module-id']?.key 패턴 (fallback 없음)
            if (preg_match("/modules\\?\\.\\['([^']+)'\\]\\?\\.([\\w?.]+)/", $globalExpr, $matches)) {
                $moduleId = $matches[1];
                $settingKey = str_replace('?.', '.', $matches[2]);

                return g7_module_settings($moduleId, $settingKey);
            }

            // _global.plugins?.['plugin-id']?.key ?? 'default' 패턴
            if (preg_match("/plugins\\?\\.\\['([^']+)'\\]\\?\\.([\\w?.]+)\\s*\\?\\?\\s*'(.+?)'/", $globalExpr, $matches)) {
                $pluginId = $matches[1];
                $settingKey = str_replace('?.', '.', $matches[2]);
                $default = $matches[3];

                return g7_plugin_settings($pluginId, $settingKey) ?? $default;
            }

            // _global.plugins?.['plugin-id']?.key 패턴 (fallback 없음)
            if (preg_match("/plugins\\?\\.\\['([^']+)'\\]\\?\\.([\\w?.]+)/", $globalExpr, $matches)) {
                $pluginId = $matches[1];
                $settingKey = str_replace('?.', '.', $matches[2]);

                return g7_plugin_settings($pluginId, $settingKey);
            }

            return null;
        });

        // 5.95. meta.seo.vars를 해석하여 ComponentHtmlMapper에 전달 (format 모드용)
        $seoVarsDecl = $seoConfig['vars'] ?? [];
        if (! empty($seoVarsDecl)) {
            $resolvedVars = $this->resolveSeoVars($seoVarsDecl, $context, $moduleIdentifier, $pluginIdentifier);
            $this->htmlMapper->setSeoVars($resolvedVars);
        }

        // 5.96. meta.seo.extensions 기반 _seo context 주입
        // extensions 배열에 선언된 확장의 seoVariables()를 수집하고
        // 자동 해석 변수(setting/core_setting/query/route) + data 변수(vars 매핑)를 처리하여
        // 설정 템플릿(meta_{page_type}_title/description)에 적용한 결과를 _seo.{page_type}에 주입
        $this->resolveSeoContext($seoConfig, $context, $routeParams, $resolvedVars ?? []);

        // 6. SeoMetaResolver로 3계층 캐스케이드 메타 해석 (배열 형태)
        $meta = $this->metaResolver->resolve($seoConfig, $context, $moduleIdentifier, $pluginIdentifier, $routeParams);

        // 6.05. $meta 가 신구 양식 모두 처리 가능하도록 og/twitter/structured_data 키 정규화
        // (구버전 Mock/Stub 호환 — 키 없으면 빈 배열로 보강)
        $meta['og'] = is_array($meta['og'] ?? null) ? $meta['og'] : [];
        $meta['twitter'] = is_array($meta['twitter'] ?? null) ? $meta['twitter'] : [];
        $meta['structured_data'] = $meta['structured_data'] ?? null;

        // 6.06. 모듈/플러그인 declaration 캐스케이드:
        //   코어설정 < 모듈/플러그인 declaration < 레이아웃 override < hook
        // resolveOgData 는 이미 (코어설정 + 레이아웃) 을 처리한 결과를 반환했으므로,
        // 모듈 declaration 은 "레이아웃에서 비어있는 키" 만 채우는 fallback 으로 적용한다.
        $pageType = $seoConfig['page_type'] ?? null;
        $extensions = $seoConfig['extensions'] ?? [];
        if ($pageType && ! empty($extensions)) {
            $extOg = [];
            $extTwitter = [];
            $extStructured = null;

            foreach ($extensions as $extDef) {
                $extType = $extDef['type'] ?? null;
                $extId = $extDef['id'] ?? null;
                if (! $extType || ! $extId) {
                    continue;
                }
                $extInstance = $this->getExtensionInstance($extType, $extId);
                if (! $extInstance) {
                    continue;
                }

                // 원천봉쇄: 한 모듈/플러그인의 declaration throw 가 전체 SEO 렌더를 망치지 않도록 격리.
                // 다국어 JSON array 캐스팅 같은 모듈 내부 회귀가 SPA fallback 까지 가지 않고 부분 누락만 발생.
                $extOg = $this->mergeOgData($extOg, $this->safeInvokeExtensionMethod(
                    $extInstance, 'seoOgDefaults', [$pageType, $context, $routeParams], $extType, $extId
                ));
                $extTwitter = $this->mergeTwitterData($extTwitter, $this->safeInvokeExtensionMethod(
                    $extInstance, 'seoTwitterDefaults', [$pageType, $context, $routeParams], $extType, $extId
                ));

                $declared = $this->safeInvokeExtensionMethod(
                    $extInstance, 'seoStructuredData', [$pageType, $context, $routeParams], $extType, $extId
                );
                if (! empty($declared)) {
                    $extStructured = $declared; // 마지막 확장이 우선 (배열 보유 시)
                }
            }

            // 레이아웃 비어있는 og 필드만 모듈 declaration 으로 채움 (레이아웃 override 우선)
            $cascadeChanged = false;
            if (! empty($extOg)) {
                $meta['og'] = $this->fillEmptyKeys($meta['og'], $extOg);
                $cascadeChanged = true;
            }
            if (! empty($extTwitter)) {
                $meta['twitter'] = $this->fillEmptyKeys($meta['twitter'], $extTwitter);
                $cascadeChanged = true;
            }
            // structured_data: 레이아웃 미선언 시 모듈 declaration 사용
            if ($meta['structured_data'] === null && $extStructured !== null) {
                $meta['structured_data'] = $extStructured;
                $cascadeChanged = true;
            }

            // 회귀: SeoMetaResolver.resolve() 가 layout-only og 로 미리 만든 ogTags/twitterTags/jsonLd 가
            // 모듈 declaration cascade 결과를 반영 못해 og:image 등이 누락됨 → cascade 후 즉시 재렌더.
            if ($cascadeChanged) {
                $meta['ogTags'] = $this->metaResolver->renderOgHtml($meta['og']);
                $meta['twitterTags'] = $this->metaResolver->renderTwitterHtml($meta['twitter']);
                $meta['jsonLd'] = $this->metaResolver->renderStructuredJson($meta['structured_data']);
            }
        }

        $hookCtx = [
            'layoutName' => $layoutName,
            'moduleIdentifier' => $moduleIdentifier,
            'pluginIdentifier' => $pluginIdentifier,
            'context' => $context,
            'locale' => $locale,
            'pageType' => $pageType,
        ];

        // 6.1. 분기별 훅: og / twitter / structured_data 각각 가로채서 수정 가능
        // 빈 배열/null 인 경우 hook 으로 청취자가 새 데이터 주입 가능하도록 호출은 항상 수행.
        $ogBefore = $meta['og'];
        $twitterBefore = $meta['twitter'];
        $structuredBefore = $meta['structured_data'];

        $meta['og'] = HookManager::applyFilters('core.seo.filter_og_data', $meta['og'], $hookCtx);
        $meta['twitter'] = HookManager::applyFilters('core.seo.filter_twitter_data', $meta['twitter'], $hookCtx);
        $meta['structured_data'] = HookManager::applyFilters('core.seo.filter_structured_data', $meta['structured_data'], $hookCtx);

        // 6.15. og/twitter/structured 가 hook 으로 변경되었거나 원본이 비어있지 않을 때만 재렌더.
        // (mock 테스트 호환: 비어있고 hook 도 변경 안 했으면 기존 ogTags/jsonLd 문자열 유지)
        if (! empty($meta['og']) && $meta['og'] !== $ogBefore) {
            $meta['ogTags'] = $this->metaResolver->renderOgHtml($meta['og']);
        } elseif (! empty($meta['og']) && ! isset($meta['ogTags'])) {
            $meta['ogTags'] = $this->metaResolver->renderOgHtml($meta['og']);
        }
        if (! empty($meta['twitter']) && $meta['twitter'] !== $twitterBefore) {
            $meta['twitterTags'] = $this->metaResolver->renderTwitterHtml($meta['twitter']);
        } elseif (! empty($meta['twitter']) && ! isset($meta['twitterTags'])) {
            $meta['twitterTags'] = $this->metaResolver->renderTwitterHtml($meta['twitter']);
        }
        if ($meta['structured_data'] !== null && $meta['structured_data'] !== $structuredBefore) {
            $meta['jsonLd'] = $this->metaResolver->renderStructuredJson($meta['structured_data']);
        } elseif ($meta['structured_data'] !== null && ! isset($meta['jsonLd'])) {
            $meta['jsonLd'] = $this->metaResolver->renderStructuredJson($meta['structured_data']);
        }

        // 6.2. 통합 훅: 모든 분기 결합 후 최종 메타 수정
        $metaBeforeFilter = $meta;
        $meta = HookManager::applyFilters('core.seo.filter_meta', $meta, $hookCtx);

        // 6.25. filter_meta 가 og/twitter/structured 배열을 수정했을 수 있으므로 변경된 것만 재렌더
        if (is_array($meta['og'] ?? null) && ! empty($meta['og']) && $meta['og'] !== ($metaBeforeFilter['og'] ?? null)) {
            $meta['ogTags'] = $this->metaResolver->renderOgHtml($meta['og']);
        }
        if (is_array($meta['twitter'] ?? null) && ! empty($meta['twitter']) && $meta['twitter'] !== ($metaBeforeFilter['twitter'] ?? null)) {
            $meta['twitterTags'] = $this->metaResolver->renderTwitterHtml($meta['twitter']);
        }
        if (array_key_exists('structured_data', $meta) && $meta['structured_data'] !== ($metaBeforeFilter['structured_data'] ?? null)) {
            $meta['jsonLd'] = $this->metaResolver->renderStructuredJson($meta['structured_data']);
        }

        // 6.5. 레이아웃명을 request attribute로 저장 (SeoMiddleware에서 putWithLayout에 사용)
        $request->attributes->set('seo_layout_name', $layoutName);

        // 7. ComponentHtmlMapper로 components → HTML 변환
        $bodyHtml = '';
        $components = $mergedLayout['components'] ?? [];
        if (! empty($components)) {
            try {
                $bodyHtml = $this->htmlMapper->render($components, $context, $this->evaluator);
            } catch (\Throwable $e) {
                Log::warning('[SEO] Component rendering failed', [
                    'layout' => $layoutName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 8. seo.blade.php로 최종 HTML 조립
        // SeoMiddleware가 setLocale() 전에 저장한 기본 로케일 사용
        // (setLocale()이 config('app.locale')을 변경하므로 request attribute로 전달)
        $defaultLocale = $request->attributes->get('seo_default_locale', config('app.locale'));
        $canonicalUrl = $locale === $defaultLocale
            ? url($url)
            : url($url).'?locale='.$locale;
        $ogUrl = '<meta property="og:url" content="'.e($canonicalUrl).'">'."\n";

        // hreflang 태그 생성 (다국어 SEO)
        $hreflangTags = $this->buildHreflangTags($url, $defaultLocale);

        // stylesheets: 템플릿 자체 CSS + seo-config.json 선언 stylesheets 병합
        $templateCssUrls = $this->getTemplateCssUrls($templateIdentifier);
        $configStylesheets = $seoTemplateConfig['stylesheets'] ?? [];
        $allStylesheets = array_merge($templateCssUrls, $configStylesheets);

        $viewData = [
            'locale' => $locale,
            'title' => $meta['title'],
            'titleSuffix' => $meta['titleSuffix'],
            'description' => $meta['description'],
            'keywords' => $meta['keywords'],
            'canonicalUrl' => $canonicalUrl,
            'hreflangTags' => $hreflangTags,
            'ogTags' => $meta['ogTags'].'    '.$ogUrl,
            'twitterTags' => $meta['twitterTags'] ?? '',
            'jsonLd' => $meta['jsonLd'],
            'bodyHtml' => $bodyHtml,
            'googleAnalyticsId' => $meta['googleAnalyticsId'],
            'googleVerification' => $meta['googleVerification'],
            'naverVerification' => $meta['naverVerification'],
            'cssPath' => $this->getCssPath(),
            'stylesheets' => $allStylesheets,
            'extraHeadTags' => '',
            'extraBodyEnd' => '',
            'generatorTag' => g7_meta_generator_tag(),
        ];

        // 8.1. 훅: 확장이 View 변수를 추가/수정할 수 있는 필터
        // 유즈케이스: Analytics 플러그인이 extraHeadTags에 추적 스크립트, PWA 플러그인이 manifest 링크 주입
        $viewData = HookManager::applyFilters('core.seo.filter_view_data', $viewData, [
            'layoutName' => $layoutName,
            'moduleIdentifier' => $moduleIdentifier,
            'pluginIdentifier' => $pluginIdentifier,
        ]);

        return View::make('seo', $viewData)->render();
    }

    /**
     * meta.seo.vars 선언을 해석합니다.
     *
     * $core_settings:, $module_settings:, $plugin_settings:, $query: 접두사를 해석하고,
     * 그 외 표현식은 ExpressionEvaluator로 평가합니다.
     * $module_settings:MODULE_ID:key 형식으로 명시적 모듈 지정도 지원합니다.
     *
     * @param  array  $varsDecl  변수 선언 (키 → 표현식)
     * @param  array  $context  데이터 컨텍스트
     * @param  string|null  $moduleIdentifier  모듈 식별자
     * @param  string|null  $pluginIdentifier  플러그인 식별자
     * @return array 해석된 변수 (키 → 값)
     */
    private function resolveSeoVars(array $varsDecl, array $context, ?string $moduleIdentifier, ?string $pluginIdentifier = null): array
    {
        $resolved = [];

        foreach ($varsDecl as $name => $expr) {
            $expr = (string) $expr;

            // 설정값이 다국어 JSON 배열일 수 있으므로 resolveLocalizedValue 헬퍼 통과
            if (str_starts_with($expr, '$module_settings:')) {
                $rest = substr($expr, strlen('$module_settings:'));
                [$effectiveModuleId, $key] = $this->parseExtensionSettingsKey($rest, $moduleIdentifier);
                if ($effectiveModuleId) {
                    $resolved[$name] = $this->resolveLocalizedValue(g7_module_settings($effectiveModuleId, $key, ''));
                } else {
                    $resolved[$name] = $this->evaluator->evaluate($expr, $context);
                }
            } elseif (str_starts_with($expr, '$plugin_settings:')) {
                $rest = substr($expr, strlen('$plugin_settings:'));
                [$effectivePluginId, $key] = $this->parseExtensionSettingsKey($rest, $pluginIdentifier);
                if ($effectivePluginId) {
                    $resolved[$name] = $this->resolveLocalizedValue(g7_plugin_settings($effectivePluginId, $key, ''));
                } else {
                    $resolved[$name] = $this->evaluator->evaluate($expr, $context);
                }
            } elseif (str_starts_with($expr, '$core_settings:')) {
                $key = substr($expr, strlen('$core_settings:'));
                $resolved[$name] = $this->resolveLocalizedValue(g7_core_settings($key, ''));
            } elseif (str_starts_with($expr, '$query:')) {
                $key = substr($expr, strlen('$query:'));
                $resolved[$name] = $this->resolveLocalizedValue(request()->query($key, ''));
            } else {
                $resolved[$name] = $this->evaluator->evaluate($expr, $context);
            }
        }

        return $resolved;
    }

    /**
     * 확장(모듈/플러그인) SEO가 활성화되어 있는지 확인합니다.
     *
     * 레이아웃 meta.seo.toggle_setting 선언 기반으로 판단합니다.
     * $module_settings:MODULE_ID:key 형식으로 명시적 모듈 지정도 지원합니다.
     * toggle_setting 미선언 시 무조건 활성화됩니다.
     *
     * @param  string|null  $moduleIdentifier  모듈 식별자
     * @param  string|null  $pluginIdentifier  플러그인 식별자
     * @param  array  $seoConfig  레이아웃 meta.seo 설정
     * @return bool 활성화 여부
     */
    private function isExtensionSeoEnabled(?string $moduleIdentifier, ?string $pluginIdentifier, array $seoConfig): bool
    {
        $toggleSetting = $seoConfig['toggle_setting'] ?? null;
        if (! $toggleSetting) {
            return true;
        }

        // $module_settings:key 또는 $module_settings:module-id:key 접두사 해석
        if (str_starts_with($toggleSetting, '$module_settings:')) {
            $rest = substr($toggleSetting, strlen('$module_settings:'));
            [$effectiveModuleId, $key] = $this->parseExtensionSettingsKey($rest, $moduleIdentifier);
            if ($effectiveModuleId) {
                return (bool) g7_module_settings($effectiveModuleId, $key, true);
            }

            return true;
        }

        // $plugin_settings:key 또는 $plugin_settings:plugin-id:key 접두사 해석
        if (str_starts_with($toggleSetting, '$plugin_settings:')) {
            $rest = substr($toggleSetting, strlen('$plugin_settings:'));
            [$effectivePluginId, $key] = $this->parseExtensionSettingsKey($rest, $pluginIdentifier);
            if ($effectivePluginId) {
                return (bool) g7_plugin_settings($effectivePluginId, $key, true);
            }

            return true;
        }

        // $core_settings:key 접두사 해석
        if (str_starts_with($toggleSetting, '$core_settings:')) {
            $key = substr($toggleSetting, strlen('$core_settings:'));

            return (bool) g7_core_settings($key, true);
        }

        return true;
    }

    /**
     * 확장 설정 키를 파싱합니다.
     *
     * 'key.path' 형식이면 컨텍스트 식별자를 사용하고,
     * 'extension-id:key.path' 형식이면 명시된 확장 식별자를 사용합니다.
     * 템플릿 레벨 레이아웃에서 모듈/플러그인 설정을 참조할 때 명시적 ID가 필요합니다.
     *
     * @param  string  $rest  접두사 제거 후 나머지 문자열
     * @param  string|null  $contextIdentifier  라우트 컨텍스트에서 추출한 식별자
     * @return array{0: string|null, 1: string} [식별자, 설정 키]
     */
    private function parseExtensionSettingsKey(string $rest, ?string $contextIdentifier): array
    {
        // 'extension-id:key.path' 형식 — 명시적 확장 ID 포함
        if (str_contains($rest, ':')) {
            [$explicitId, $key] = explode(':', $rest, 2);

            return [$explicitId, $key];
        }

        // 'key.path' 형식 — 컨텍스트 식별자 사용
        return [$contextIdentifier, $rest];
    }

    /**
     * 템플릿 번역 데이터를 로드하여 ExpressionEvaluator에 설정합니다.
     *
     * @param  string  $templateIdentifier  템플릿 식별자
     * @param  string  $locale  로케일
     */
    private function loadTemplateTranslations(string $templateIdentifier, string $locale): void
    {
        try {
            $result = $this->templateService->getLanguageDataWithModules($templateIdentifier, $locale);

            if ($result['success'] && ! empty($result['data'])) {
                $this->evaluator->setTranslations($result['data']);
            }
        } catch (\Throwable $e) {
            Log::debug('[SEO] Template translation load failed', [
                'template' => $templateIdentifier,
                'locale' => $locale,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 템플릿의 CSS 에셋 URL 목록을 반환합니다.
     *
     * template.json의 assets.css 경로를 서빙 URL로 변환합니다.
     * 예: "dist/css/components.css" → "/api/templates/assets/{id}/css/components.css"
     *
     * @param  string  $templateIdentifier  템플릿 식별자
     * @return array CSS URL 배열
     */
    private function getTemplateCssUrls(string $templateIdentifier): array
    {
        $templateJsonPath = base_path("templates/{$templateIdentifier}/template.json");
        if (! file_exists($templateJsonPath)) {
            return [];
        }

        $templateJson = json_decode(file_get_contents($templateJsonPath), true);
        if (! is_array($templateJson)) {
            return [];
        }

        $cssPaths = $templateJson['assets']['css'] ?? [];
        if (empty($cssPaths)) {
            return [];
        }

        $urls = [];
        foreach ($cssPaths as $cssPath) {
            // dist/ 접두사 제거 (서빙 경로에서는 dist가 자동 추가됨)
            $servePath = preg_replace('#^dist/#', '', $cssPath);
            $urls[] = '/api/templates/assets/'.$templateIdentifier.'/'.$servePath;
        }

        return $urls;
    }

    /**
     * 다국어 hreflang 태그를 생성합니다.
     *
     * supported_locales를 순회하며 각 로케일별 alternate 태그를 생성합니다.
     * 기본 로케일은 쿼리 파라미터 없는 clean URL, 비기본은 ?locale=xx를 포함합니다.
     * x-default는 기본 로케일 URL(파라미터 없음)을 가리킵니다.
     *
     * @param  string  $url  요청 경로 (예: /products/123)
     * @param  string  $defaultLocale  기본 로케일
     * @return string hreflang 태그 HTML
     */
    private function buildHreflangTags(string $url, string $defaultLocale): string
    {
        $supportedLocales = config('app.supported_locales', [$defaultLocale]);

        // 로케일이 1개뿐이면 hreflang 불필요
        if (count($supportedLocales) <= 1) {
            return '';
        }

        $baseUrl = url($url);
        $tags = '';

        foreach ($supportedLocales as $loc) {
            $href = $loc === $defaultLocale
                ? $baseUrl
                : $baseUrl.'?locale='.$loc;
            $tags .= '    <link rel="alternate" hreflang="'.e($loc).'" href="'.e($href).'">'."\n";
        }

        // x-default = 기본 로케일 URL (파라미터 없음)
        $tags .= '    <link rel="alternate" hreflang="x-default" href="'.e($baseUrl).'">'."\n";

        return $tags;
    }

    /**
     * Vite 빌드 CSS 경로를 반환합니다.
     *
     * @return string CSS 경로
     */
    private function getCssPath(): string
    {
        $manifestPath = public_path('build/manifest.json');
        if (file_exists($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            foreach ($manifest as $entry) {
                if (isset($entry['css'])) {
                    foreach ($entry['css'] as $css) {
                        return '/build/'.$css;
                    }
                }
            }
        }

        return '/build/assets/app.css';
    }

    /**
     * SEO 렌더링용 _global 컨텍스트를 구성합니다.
     *
     * 프론트엔드에서 window.G7Config로 주입되는 설정을
     * 서버사이드 SEO 렌더링에서도 동일하게 제공합니다.
     *
     * @return array _global 컨텍스트 배열
     */
    private function buildGlobalContext(): array
    {
        $global = [];

        // settings: SettingsService에서 프론트엔드용 설정 로드
        try {
            $global['settings'] = $this->settingsService->getFrontendSettings();
        } catch (\Throwable $e) {
            Log::warning('[SEO] Failed to load frontend settings', ['error' => $e->getMessage()]);
            $global['settings'] = [];
        }

        // 코어 설정에서 사이트 기본 정보 주입 (structured_data 등에서 참조)
        $global['site_name'] = g7_core_settings('general.site_name', '');
        $global['site_url'] = g7_core_settings('general.site_url', url('/'));

        // modules: 모듈별 설정 (config에서 로드)
        $global['modules'] = config('g7_settings.modules', []);

        // plugins: 플러그인별 설정
        try {
            $global['plugins'] = $this->pluginSettingsService->getAllActiveSettings();
        } catch (\Throwable $e) {
            Log::warning('[SEO] Failed to load plugin settings', ['error' => $e->getMessage()]);
            $global['plugins'] = [];
        }

        return $global;
    }

    /**
     * 데이터소스의 initGlobal 설정을 기반으로 결과를 _global에 매핑합니다.
     *
     * 프론트엔드에서 data_source의 initGlobal 옵션이
     * API 응답을 _global 경로에 매핑하는 것과 동일한 처리를 수행합니다.
     *
     * initGlobal 형식:
     * - 문자열: "currentUser" → _global.currentUser = response.data
     * - 객체: { "key": "cartCount", "path": "count" } → _global.cartCount = response.data.count
     *
     * @param  array  $allDataSources  전체 data_source 정의 배열
     * @param  array  &$context  현재 컨텍스트 (참조 전달)
     */
    private function applyInitGlobalMapping(array $allDataSources, array &$context): void
    {
        foreach ($allDataSources as $ds) {
            $dsId = $ds['id'] ?? '';
            $initGlobal = $ds['initGlobal'] ?? null;

            // initGlobal이 없거나 해당 데이터소스의 결과가 컨텍스트에 없으면 스킵
            if ($initGlobal === null || ! isset($context[$dsId])) {
                continue;
            }

            $responseData = $context[$dsId]['data'] ?? $context[$dsId];

            if (is_string($initGlobal)) {
                // 문자열: _global.{key} = response.data
                $context['_global'][$initGlobal] = $responseData;
            } elseif (is_array($initGlobal) && isset($initGlobal['key'])) {
                // 객체: _global.{key} = response.data.{path}
                $key = $initGlobal['key'];
                $path = $initGlobal['path'] ?? null;

                if ($path !== null) {
                    $context['_global'][$key] = data_get($responseData, $path);
                } else {
                    $context['_global'][$key] = $responseData;
                }
            }
        }
    }

    /**
     * 레이아웃의 computed 속성을 평가합니다.
     *
     * 프론트엔드 TemplateApp.calculateComputed()와 동일한 로직:
     * - 문자열 표현식: "{{expr}}" → ExpressionEvaluator로 평가
     * - $switch 객체: { "$switch": "{{expr}}", "$cases": {...}, "$default": "..." }
     * - 일반 문자열: 그대로 사용
     *
     * 1차 범위: 단순 표현식 computed만 지원
     * (reduce+스프레드 같은 복잡한 표현식은 ExpressionEvaluator 확장 후 지원)
     *
     * @param  array  $computedDefs  computed 정의 (키 → 표현식 또는 $switch 객체)
     * @param  array  $context  데이터 컨텍스트
     * @return array 평가된 computed 값
     */
    private function resolveComputed(array $computedDefs, array $context): array
    {
        $result = [];

        foreach ($computedDefs as $key => $definition) {
            try {
                if (is_array($definition) && isset($definition['$switch'])) {
                    // $switch 형식: 조건부 값 매핑
                    $result[$key] = $this->resolveComputedSwitch($definition, $context);
                } elseif (is_string($definition)) {
                    if (str_contains($definition, '{{')) {
                        // {{expr}} 표현식 → evaluateRaw로 원본 타입 유지
                        $result[$key] = $this->evaluator->evaluateRaw($definition, $context);
                    } else {
                        // 일반 문자열은 그대로 사용
                        $result[$key] = $definition;
                    }
                } else {
                    $result[$key] = $definition;
                }

                // 계산된 값을 _computed에 추가하여 후속 computed에서 참조 가능
                $context['_computed'][$key] = $result[$key];
                $context['$computed'][$key] = $result[$key];
            } catch (\Throwable $e) {
                Log::debug('[SEO] Computed evaluation failed', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
                $result[$key] = null;
            }
        }

        return $result;
    }

    /**
     * $switch 형식의 computed 값을 해석합니다.
     *
     * 프론트엔드 DataBindingEngine.resolveSwitch()와 동일:
     * 1. $switch 키 표현식 평가
     * 2. $cases에서 일치하는 값 찾기
     * 3. 없으면 $default 사용
     *
     * @param  array  $definition  $switch 정의 { "$switch", "$cases", "$default" }
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 해석된 값
     */
    private function resolveComputedSwitch(array $definition, array $context): mixed
    {
        $switchExpr = $definition['$switch'] ?? '';
        $cases = $definition['$cases'] ?? [];
        $default = $definition['$default'] ?? null;

        // $switch 표현식 평가
        $switchValue = $this->evaluator->evaluate($switchExpr, $context);

        // $cases에서 매칭
        if (isset($cases[$switchValue])) {
            $caseValue = $cases[$switchValue];

            // case 값도 표현식일 수 있음
            if (is_string($caseValue) && str_contains($caseValue, '{{')) {
                return $this->evaluator->evaluate($caseValue, $context);
            }

            return $caseValue;
        }

        // $default 반환
        if ($default !== null && is_string($default) && str_contains($default, '{{')) {
            return $this->evaluator->evaluate($default, $context);
        }

        return $default;
    }

    /**
     * init_actions의 setState(target: local)을 평가하여 _local 초기값을 반환합니다.
     *
     * 프론트엔드에서 init_actions 실행 시 setState로 설정하는 _local 상태를
     * SEO 렌더링에서도 동일하게 반영합니다. 이를 통해 탭 상태, 페이지네이션 초기값 등
     * _local 기반 조건부 렌더링이 SEO에서도 정상 동작합니다.
     *
     * 처리 대상:
     * - handler: "setState" + params.target: "local" (또는 target 미지정)
     * - params 내 {{}} 표현식을 ExpressionEvaluator로 평가
     * - 배열 리터럴, 객체 리터럴 등 정적 값은 그대로 사용
     *
     * 스킵 대상:
     * - handler가 setState가 아닌 항목 (loadFromLocalStorage, closeModal 등)
     * - target이 "global"인 항목
     *
     * @param  array  $initActions  레이아웃의 init_actions 배열
     * @param  array  $context  현재 컨텍스트 (route, query 등 포함)
     * @return array _local 초기값
     */
    private function resolveInitLocalState(array $initActions, array $context): array
    {
        $local = [];

        // setState에서 제외할 메타 키 (상태 값이 아닌 핸들러 제어용 키)
        $metaKeys = ['target', 'handler', 'comment'];

        foreach ($initActions as $action) {
            $handler = $action['handler'] ?? '';
            if ($handler !== 'setState') {
                continue;
            }

            $params = $action['params'] ?? [];
            $target = $params['target'] ?? 'local';

            // global 대상은 스킵 (_global은 buildGlobalContext + applyInitGlobalMapping이 담당)
            if ($target === 'global') {
                continue;
            }

            foreach ($params as $key => $value) {
                if (in_array($key, $metaKeys, true)) {
                    continue;
                }

                $local[$key] = $this->resolveInitActionValue($value, $context);
            }
        }

        return $local;
    }

    /**
     * init_actions setState의 개별 값을 평가합니다.
     *
     * - 문자열이고 {{}} 표현식이면 ExpressionEvaluator로 평가
     * - 배열이면 각 요소를 재귀적으로 평가
     * - 스칼라 값(int, bool, null)은 그대로 반환
     *
     * @param  mixed  $value  원본 값
     * @param  array  $context  데이터 컨텍스트
     * @return mixed 평가된 값
     */
    private function resolveInitActionValue(mixed $value, array $context): mixed
    {
        if (is_string($value) && str_contains($value, '{{')) {
            $evaluated = $this->evaluator->evaluate($value, $context);

            // 빈 문자열은 표현식 평가 실패 가능성 → 원본 반환 대신 빈 문자열 유지
            return $evaluated;
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = $this->resolveInitActionValue($v, $context);
            }

            return $result;
        }

        return $value;
    }

    /**
     * meta.seo.extensions 기반으로 SEO 변수를 해석하고 _seo context에 주입합니다.
     *
     * 처리 흐름:
     * 1. extensions 배열에서 확장 인스턴스 조회 → seoVariables() 수집
     * 2. 자동 해석 변수(setting/core_setting/query/route) 처리
     * 3. data 변수: meta.seo.vars 매핑 결과 사용 (이미 resolveSeoVars에서 해석됨)
     * 4. 확장 설정 템플릿(meta_{page_type}_title/description) 조회 → {var} 치환
     * 5. 결과를 context['_seo'][$pageType] = ['title' => ..., 'description' => ...] 주입
     *
     * @param  array  $seoConfig  레이아웃 meta.seo 설정
     * @param  array  &$context  데이터 컨텍스트 (참조 전달 — _seo 주입)
     * @param  array  $routeParams  라우트 파라미터
     * @param  array  $resolvedVars  이미 해석된 vars (resolveSeoVars 결과)
     */
    private function resolveSeoContext(array $seoConfig, array &$context, array $routeParams, array $resolvedVars): void
    {
        $extensions = $seoConfig['extensions'] ?? [];
        $pageType = $seoConfig['page_type'] ?? null;

        if (empty($extensions) || ! $pageType) {
            return;
        }

        // 확장별 seoVariables() 수집 및 해석
        $allResolvedVars = [];
        foreach ($extensions as $extDef) {
            $extType = $extDef['type'] ?? null;
            $extId = $extDef['id'] ?? null;

            if (! $extType || ! $extId) {
                continue;
            }

            // 확장 인스턴스 조회
            $extInstance = $this->getExtensionInstance($extType, $extId);
            if (! $extInstance) {
                continue;
            }

            $seoVarsDef = $extInstance->seoVariables();
            if (empty($seoVarsDef)) {
                continue;
            }

            // _common + page_type별 변수 병합
            $commonVars = $seoVarsDef['_common'] ?? [];
            $pageTypeVars = $seoVarsDef[$pageType] ?? [];
            $mergedVarsDef = array_merge($commonVars, $pageTypeVars);

            if (empty($mergedVarsDef)) {
                continue;
            }

            // 변수 자동 해석
            foreach ($mergedVarsDef as $varName => $varDef) {
                $source = $varDef['source'] ?? 'data';
                $key = $varDef['key'] ?? $varName;

                $resolved = match ($source) {
                    'setting' => $this->resolveSettingVar($extType, $extId, $key),
                    'core_setting' => $this->resolveLocalizedValue(g7_core_settings($key, '')),
                    'query' => $this->resolveLocalizedValue(request()->query($key, '')),
                    'route' => (string) ($routeParams[$key] ?? ''),
                    'data' => $resolvedVars[$varName] ?? '',
                    default => '',
                };

                // required인데 값이 비어있으면 경고
                if (($varDef['required'] ?? false) && $resolved === '') {
                    Log::warning('[SEO] Required variable not resolved', [
                        'variable' => $varName,
                        'page_type' => $pageType,
                        'extension' => $extId,
                    ]);
                }

                $allResolvedVars[$varName] = $resolved;
            }

            // 설정 템플릿 해석 (확장별)
            $this->applySettingsTemplate($extType, $extId, $pageType, $allResolvedVars, $context);
        }
    }

    /**
     * 확장 설정의 메타 템플릿을 해석하여 _seo context에 주입합니다.
     *
     * @param  string  $extType  확장 타입 ('module' 또는 'plugin')
     * @param  string  $extId  확장 식별자
     * @param  string  $pageType  페이지 타입
     * @param  array  $vars  해석된 변수 맵
     * @param  array  &$context  데이터 컨텍스트 (참조)
     */
    private function applySettingsTemplate(string $extType, string $extId, string $pageType, array $vars, array &$context): void
    {
        // 다국어 JSON array 설정값 안전 변환 — 다국어 입력 환경에서 회귀 방지
        $titleTemplate = $this->resolveLocalizedValue($this->getExtensionSetting($extType, $extId, "seo.meta_{$pageType}_title"));
        $descTemplate = $this->resolveLocalizedValue($this->getExtensionSetting($extType, $extId, "seo.meta_{$pageType}_description"));

        $title = $this->substituteVars($titleTemplate, $vars);
        $description = $this->substituteVars($descTemplate, $vars);

        if ($title !== '' || $description !== '') {
            $context['_seo'][$pageType] = [
                'title' => $title,
                'description' => $description,
            ];
        }
    }

    /**
     * 설정 변수(source: setting)를 해석합니다.
     *
     * @param  string  $extType  확장 타입
     * @param  string  $extId  확장 식별자
     * @param  string  $key  설정 키
     * @return string 해석된 값
     */
    private function resolveSettingVar(string $extType, string $extId, string $key): string
    {
        return $this->resolveLocalizedValue($this->getExtensionSetting($extType, $extId, $key));
    }

    /**
     * 확장 설정 값을 타입에 따라 조회합니다.
     *
     * @param  string  $extType  확장 타입 ('module' 또는 'plugin')
     * @param  string  $extId  확장 식별자
     * @param  string  $key  설정 키
     * @return mixed 설정 값
     */
    private function getExtensionSetting(string $extType, string $extId, string $key): mixed
    {
        return $extType === 'module'
            ? g7_module_settings($extId, $key, '')
            : g7_plugin_settings($extId, $key, '');
    }

    /**
     * 확장 인스턴스를 조회합니다.
     *
     * @param  string  $extType  확장 타입 ('module' 또는 'plugin')
     * @param  string  $extId  확장 식별자
     * @return object|null 확장 인스턴스
     */
    private function getExtensionInstance(string $extType, string $extId): ?object
    {
        if ($extType === 'module') {
            return $this->moduleManager->getModule($extId);
        }

        if ($extType === 'plugin') {
            return $this->pluginManager->getPlugin($extId);
        }

        return null;
    }

    /**
     * 두 OG 데이터 배열을 병합합니다 (확장 declaration 누적용).
     *
     * 후속 데이터의 비어있지 않은 키만 덮어쓰기. extra 배열은 concat.
     *
     * @param  array  $base  기존 데이터
     * @param  array  $additions  추가 데이터
     * @return array 병합 결과
     */
    private function mergeOgData(array $base, array $additions): array
    {
        foreach ($additions as $key => $value) {
            if ($key === 'extra' && is_array($value)) {
                $base['extra'] = array_merge((array) ($base['extra'] ?? []), $value);
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * 두 Twitter 데이터 배열을 병합합니다.
     */
    private function mergeTwitterData(array $base, array $additions): array
    {
        return $this->mergeOgData($base, $additions);
    }

    /**
     * target 배열의 비어있는 키를 source 값으로 채웁니다 (target 우선).
     *
     * 모듈 declaration 을 fallback 으로 적용할 때 사용 — 레이아웃 override 가 우선.
     * 정수 0 / int 값은 비어있지 않은 것으로 간주.
     *
     * @param  array  $target  채울 대상 (레이아웃 결과)
     * @param  array  $source  fallback 소스 (모듈 declaration)
     * @return array
     */
    private function fillEmptyKeys(array $target, array $source): array
    {
        foreach ($source as $key => $value) {
            if ($key === 'extra' && is_array($value)) {
                $target['extra'] = array_merge($value, (array) ($target['extra'] ?? []));
                continue;
            }
            $current = $target[$key] ?? null;
            $isEmpty = ($current === null || $current === '' || $current === []);
            if ($isEmpty && $value !== null && $value !== '' && $value !== []) {
                $target[$key] = $value;
            }
        }

        return $target;
    }

    /**
     * 확장 declaration 메서드를 안전하게 호출.
     *
     * 모듈/플러그인의 seoOgDefaults / seoTwitterDefaults / seoStructuredData 가 throw 해도
     * 전체 SEO 렌더 파이프라인을 죽이지 않도록 try/catch 로 격리.
     * throw 시 빈 배열 반환 + 경고 로그 — 한 확장 회귀가 SPA fallback 으로 이어지는 회귀 차단.
     *
     * @param  object  $instance  확장 인스턴스 (Module/Plugin)
     * @param  string  $method  메서드명
     * @param  array  $args  메서드 인자
     * @param  string  $extType  로깅용 확장 타입
     * @param  string  $extId  로깅용 확장 식별자
     * @return array 메서드 결과 또는 빈 배열
     */
    private function safeInvokeExtensionMethod(
        object $instance,
        string $method,
        array $args,
        string $extType,
        string $extId,
    ): array {
        try {
            $result = $instance->{$method}(...$args);

            return is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            Log::warning("[SEO] {$extType} {$extId}::{$method}() threw — declaration 무시, SEO 부분 누락", [
                'extension' => $extId,
                'method' => $method,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return [];
        }
    }
}
