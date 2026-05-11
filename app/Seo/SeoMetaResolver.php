<?php

namespace App\Seo;

use App\Seo\Concerns\LocalizesSeoValues;
use App\Seo\Concerns\SubstitutesSeoVariables;

class SeoMetaResolver
{
    use LocalizesSeoValues;
    use SubstitutesSeoVariables;

    public function __construct(
        private readonly ExpressionEvaluator $evaluator,
    ) {}

    /**
     * 3계층 캐스케이드로 SEO 메타데이터를 해석합니다.
     *
     * @param  array  $seoConfig  레이아웃 meta.seo 설정
     * @param  array  $context  데이터 컨텍스트 (DataSourceResolver 결과)
     * @param  string|null  $moduleIdentifier  모듈 식별자 (이커머스/게시판 등)
     * @param  string|null  $pluginIdentifier  플러그인 식별자
     * @param  array  $routeParams  라우트 파라미터
     * @return array 해석된 메타 데이터
     */
    public function resolve(array $seoConfig, array $context, ?string $moduleIdentifier, ?string $pluginIdentifier, array $routeParams): array
    {
        // Tier 1: 코어 설정 (null 저장값 대비 빈 문자열 보장)
        $coreTitleSuffix = g7_core_settings('seo.meta_title_suffix') ?? '';
        $coreDescription = g7_core_settings('seo.meta_description') ?? '';
        $coreKeywords = g7_core_settings('seo.meta_keywords') ?? '';

        // 페이지 유형별 타이틀/설명 결정
        $title = $this->resolveTitle($seoConfig, $context, $moduleIdentifier, $pluginIdentifier, $routeParams);
        $description = $this->resolveDescription($seoConfig, $context, $moduleIdentifier, $pluginIdentifier, $routeParams);
        $keywords = $this->resolveKeywords($context, $moduleIdentifier);

        // fallback: 레이아웃 meta → 코어 설정
        if ($title === '') {
            $title = $this->resolveLayoutMetaTitle($seoConfig, $context);
        }
        if ($description === '') {
            $description = $this->resolveLayoutMetaDescription($seoConfig, $context);
        }
        if ($description === '') {
            $description = $coreDescription;
        }
        if ($keywords === '') {
            $keywords = $coreKeywords;
        }

        // 코어 title suffix 항상 추가
        $titleSuffix = $coreTitleSuffix !== '' ? $coreTitleSuffix : '';

        // null 방어 (OG 태그 string 타입힌트 충족)
        $title = $title ?? '';
        $description = $description ?? '';

        // OG / Twitter / Structured Data 배열 해석 (HTML 렌더 지연)
        $og = $this->resolveOgData($seoConfig, $context, $title, $description);
        $twitter = $this->resolveTwitterData($seoConfig, $context, $og);
        $structuredData = $this->resolveStructuredDataArray($seoConfig, $context);

        return [
            'title' => $title,
            'titleSuffix' => $titleSuffix,
            'description' => $description,
            'keywords' => $keywords,

            // 신설: 배열 형태 (확장이 hook 으로 수정 가능)
            'og' => $og,
            'twitter' => $twitter,
            'structured_data' => $structuredData,
            'extraMetaTags' => [],

            // 후방 호환: HTML/JSON 문자열도 함께 채움 (filter_meta 후 SeoRenderer 가 재계산)
            'ogTags' => $this->renderOgHtml($og),
            'twitterTags' => $this->renderTwitterHtml($twitter),
            'jsonLd' => $this->renderStructuredJson($structuredData),

            'googleAnalyticsId' => g7_core_settings('seo.google_analytics_id', ''),
            'googleVerification' => g7_core_settings('seo.google_site_verification', ''),
            'naverVerification' => g7_core_settings('seo.naver_site_verification', ''),
        ];
    }

    /**
     * 타이틀을 3계층 캐스케이드로 해석합니다.
     *
     * @param  array  $seoConfig  SEO 설정
     * @param  array  $context  데이터 컨텍스트
     * @param  string|null  $moduleIdentifier  모듈 식별자
     * @param  string|null  $pluginIdentifier  플러그인 식별자
     * @param  array  $routeParams  라우트 파라미터
     * @return string 해석된 타이틀
     */
    private function resolveTitle(array $seoConfig, array $context, ?string $moduleIdentifier, ?string $pluginIdentifier, array $routeParams): string
    {
        // Tier 3: 리소스 개별 meta_title
        $resourceTitle = $this->getResourceMetaField($context, 'meta_title');
        if ($resourceTitle !== '') {
            return $resourceTitle;
        }

        // Tier 3: 페이지 seo_meta.title (sirsoft-page)
        $pageSeoTitle = $this->getPageSeoMetaField($context, 'title');
        if ($pageSeoTitle !== '') {
            return $pageSeoTitle;
        }

        // Tier 2: _seo context (SeoRenderer가 extensions 기반으로 주입)
        $pageType = $seoConfig['page_type'] ?? null;
        if ($pageType) {
            $seoTitle = data_get($context, "_seo.{$pageType}.title", '');
            if ($seoTitle !== '') {
                return $seoTitle;
            }
        }

        // Tier 2 하위호환: moduleIdentifier/pluginIdentifier 기반 (extensions 미선언 시)
        if ($moduleIdentifier) {
            $templateTitle = $this->resolveModuleTemplate($moduleIdentifier, 'title', $seoConfig, $context);
            if ($templateTitle !== '') {
                return $templateTitle;
            }
        } elseif ($pluginIdentifier) {
            $templateTitle = $this->resolvePluginTemplate($pluginIdentifier, 'title', $seoConfig, $context);
            if ($templateTitle !== '') {
                return $templateTitle;
            }
        }

        return '';
    }

    /**
     * 설명을 3계층 캐스케이드로 해석합니다.
     *
     * @param  array  $seoConfig  SEO 설정
     * @param  array  $context  데이터 컨텍스트
     * @param  string|null  $moduleIdentifier  모듈 식별자
     * @param  string|null  $pluginIdentifier  플러그인 식별자
     * @param  array  $routeParams  라우트 파라미터
     * @return string 해석된 설명
     */
    private function resolveDescription(array $seoConfig, array $context, ?string $moduleIdentifier, ?string $pluginIdentifier, array $routeParams): string
    {
        // Tier 3: 리소스 개별 meta_description
        $resourceDesc = $this->getResourceMetaField($context, 'meta_description');
        if ($resourceDesc !== '') {
            return $resourceDesc;
        }

        // Tier 3: 페이지 seo_meta.description
        $pageSeoDesc = $this->getPageSeoMetaField($context, 'description');
        if ($pageSeoDesc !== '') {
            return $pageSeoDesc;
        }

        // Tier 2: _seo context (SeoRenderer가 extensions 기반으로 주입)
        $pageType = $seoConfig['page_type'] ?? null;
        if ($pageType) {
            $seoDesc = data_get($context, "_seo.{$pageType}.description", '');
            if ($seoDesc !== '') {
                return $seoDesc;
            }
        }

        // Tier 2 하위호환: moduleIdentifier/pluginIdentifier 기반 (extensions 미선언 시)
        if ($moduleIdentifier) {
            $templateDesc = $this->resolveModuleTemplate($moduleIdentifier, 'description', $seoConfig, $context);
            if ($templateDesc !== '') {
                return $templateDesc;
            }
        } elseif ($pluginIdentifier) {
            $templateDesc = $this->resolvePluginTemplate($pluginIdentifier, 'description', $seoConfig, $context);
            if ($templateDesc !== '') {
                return $templateDesc;
            }
        }

        return '';
    }

    /**
     * 키워드를 해석합니다.
     *
     * @param  array  $context  데이터 컨텍스트
     * @param  string|null  $moduleIdentifier  모듈 식별자
     * @return string 키워드 (쉼표 구분)
     */
    private function resolveKeywords(array $context, ?string $moduleIdentifier): string
    {
        // Tier 3: 리소스 개별 meta_keywords
        foreach ($context as $dsData) {
            $keywords = data_get($dsData, 'data.meta_keywords');
            if (! empty($keywords)) {
                if (is_array($keywords)) {
                    // 다국어 JSON ({"ko": "...", "en": "..."}) 분기 — locale 추출
                    if ($this->isLocalizedArray($keywords)) {
                        return $this->resolveLocalizedValue($keywords);
                    }

                    // 키워드 list 인 경우 (정수 키 배열)
                    return implode(',', $keywords);
                }

                return (string) $keywords;
            }
        }

        // Tier 3: 페이지 seo_meta.keywords
        $pageKeywords = $this->getPageSeoMetaField($context, 'keywords');
        if ($pageKeywords !== '') {
            return $pageKeywords;
        }

        return '';
    }

    /**
     * 리소스 데이터에서 메타 필드를 추출합니다.
     *
     * @param  array  $context  데이터 컨텍스트
     * @param  string  $field  필드명
     * @return string 필드 값
     */
    private function getResourceMetaField(array $context, string $field): string
    {
        foreach ($context as $dsData) {
            $value = data_get($dsData, "data.{$field}");
            if ($value !== null && $value !== '') {
                return $this->resolveLocalizedValue($value);
            }
        }

        return '';
    }

    /**
     * 페이지 seo_meta 필드를 추출합니다.
     *
     * @param  array  $context  데이터 컨텍스트
     * @param  string  $field  필드명
     * @return string 필드 값
     */
    private function getPageSeoMetaField(array $context, string $field): string
    {
        foreach ($context as $dsData) {
            $value = data_get($dsData, "data.seo_meta.{$field}");
            if ($value !== null && $value !== '') {
                return $this->resolveLocalizedValue($value);
            }
        }
        return '';

    }

    /**
     * 모듈 설정 템플릿을 해석합니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @param  string  $type  'title' 또는 'description'
     * @param  array  $seoConfig  레이아웃 meta.seo 설정
     * @param  array  $context  데이터 컨텍스트
     * @return string 해석된 템플릿
     */
    private function resolveModuleTemplate(string $moduleIdentifier, string $type, array $seoConfig, array $context): string
    {
        // 페이지 유형: 레이아웃 JSON의 meta.seo.page_type에서 직접 제공
        $pageType = $seoConfig['page_type'] ?? null;
        if (! $pageType) {
            return '';
        }

        $settingKey = "seo.meta_{$pageType}_{$type}";
        $template = g7_module_settings($moduleIdentifier, $settingKey);

        if (empty($template)) {
            return '';
        }

        // 모듈 SEO 활성화 확인은 SeoRenderer::isModuleSeoEnabled()에서
        // toggle_setting 기반으로 이미 수행됨 (render() 단계 4)

        // 레이아웃 vars 기반 변수 치환
        $varsDecl = $seoConfig['vars'] ?? [];
        if (empty($varsDecl)) {
            return $template;
        }

        $resolvedVars = $this->resolveVars($varsDecl, $context, $moduleIdentifier);

        return $this->substituteVars($template, $resolvedVars);
    }

    /**
     * 플러그인 설정 템플릿을 해석합니다.
     *
     * @param  string  $pluginIdentifier  플러그인 식별자
     * @param  string  $type  'title' 또는 'description'
     * @param  array  $seoConfig  레이아웃 meta.seo 설정
     * @param  array  $context  데이터 컨텍스트
     * @return string 해석된 템플릿
     */
    private function resolvePluginTemplate(string $pluginIdentifier, string $type, array $seoConfig, array $context): string
    {
        $pageType = $seoConfig['page_type'] ?? null;
        if (! $pageType) {
            return '';
        }

        $settingKey = "seo.meta_{$pageType}_{$type}";
        $template = g7_plugin_settings($pluginIdentifier, $settingKey);

        if (empty($template)) {
            return '';
        }

        $varsDecl = $seoConfig['vars'] ?? [];
        if (empty($varsDecl)) {
            return $template;
        }

        $resolvedVars = $this->resolveVars($varsDecl, $context, null, $pluginIdentifier);

        return $this->substituteVars($template, $resolvedVars);
    }

    /**
     * 레이아웃 meta.seo.vars 선언을 해석합니다.
     *
     * 접두사 문법:
     * - {{expr}} → ExpressionEvaluator로 평가
     * - $module_settings:key → 모듈 설정 값
     * - $core_settings:key → 코어 설정 값
     * - $query:key → 쿼리 파라미터
     *
     * @param  array  $varsDecl  vars 선언 (키 → 표현식)
     * @param  array  $context  데이터 컨텍스트
     * @param  string|null  $moduleIdentifier  모듈 식별자
     * @param  string|null  $pluginIdentifier  플러그인 식별자
     * @return array 해석된 변수 (키 → 값)
     */
    private function resolveVars(array $varsDecl, array $context, ?string $moduleIdentifier, ?string $pluginIdentifier = null): array
    {
        $resolved = [];
        foreach ($varsDecl as $name => $expr) {
            $resolved[$name] = $this->resolveVarExpression((string) $expr, $context, $moduleIdentifier, $pluginIdentifier);
        }

        return $resolved;
    }

    /**
     * 단일 변수 표현식을 해석합니다.
     *
     * $module_settings:MODULE_ID:key 형식으로 명시적 모듈 지정도 지원합니다.
     *
     * @param  string  $expr  변수 표현식
     * @param  array  $context  데이터 컨텍스트
     * @param  string|null  $moduleIdentifier  모듈 식별자
     * @param  string|null  $pluginIdentifier  플러그인 식별자
     * @return string 해석된 값
     */
    private function resolveVarExpression(string $expr, array $context, ?string $moduleIdentifier, ?string $pluginIdentifier = null): string
    {
        // $module_settings:key 또는 $module_settings:module-id:key
        // 다국어 JSON 배열 설정값도 안전 처리 (resolveLocalizedValue)
        if (str_starts_with($expr, '$module_settings:')) {
            $rest = substr($expr, strlen('$module_settings:'));
            [$effectiveId, $key] = $this->parseExtensionSettingsKey($rest, $moduleIdentifier);
            if ($effectiveId) {
                return $this->resolveLocalizedValue(g7_module_settings($effectiveId, $key, ''));
            }
        }

        // $plugin_settings:key 또는 $plugin_settings:plugin-id:key
        if (str_starts_with($expr, '$plugin_settings:')) {
            $rest = substr($expr, strlen('$plugin_settings:'));
            [$effectiveId, $key] = $this->parseExtensionSettingsKey($rest, $pluginIdentifier);
            if ($effectiveId) {
                return $this->resolveLocalizedValue(g7_plugin_settings($effectiveId, $key, ''));
            }
        }

        // $core_settings:key
        if (str_starts_with($expr, '$core_settings:')) {
            $key = substr($expr, strlen('$core_settings:'));

            return $this->resolveLocalizedValue(g7_core_settings($key, ''));
        }

        // $query:key
        if (str_starts_with($expr, '$query:')) {
            $key = substr($expr, strlen('$query:'));

            return $this->resolveLocalizedValue(request()->query($key, ''));
        }

        // {{expression}} → ExpressionEvaluator
        $evaluated = $this->evaluator->evaluate($expr, $context);

        return $this->resolveLocalizedValue($evaluated);
    }

    /**
     * 확장 설정 키를 파싱합니다.
     *
     * 'key.path' 형식이면 컨텍스트 식별자를 사용하고,
     * 'extension-id:key.path' 형식이면 명시된 확장 식별자를 사용합니다.
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
     * 레이아웃 meta의 title을 해석합니다 (fallback용).
     *
     * @param  array  $seoConfig  SEO 설정
     * @param  array  $context  데이터 컨텍스트
     * @return string 해석된 타이틀
     */
    private function resolveLayoutMetaTitle(array $seoConfig, array $context): string
    {
        // meta.seo.og.title이 있으면 사용. 다국어 array literal 도 허용 (safeEval).
        $ogTitle = data_get($seoConfig, 'og.title', '');
        if ($ogTitle !== '' && $ogTitle !== null && $ogTitle !== []) {
            return $this->safeEval($ogTitle, $context);
        }

        return '';
    }

    /**
     * 레이아웃 meta의 description을 해석합니다 (fallback용).
     *
     * @param  array  $seoConfig  SEO 설정
     * @param  array  $context  데이터 컨텍스트
     * @return string 해석된 설명
     */
    private function resolveLayoutMetaDescription(array $seoConfig, array $context): string
    {
        // 다국어 array literal 도 허용 (safeEval).
        $ogDescription = data_get($seoConfig, 'og.description', '');
        if ($ogDescription !== '' && $ogDescription !== null && $ogDescription !== []) {
            return $this->stripHtml($this->safeEval($ogDescription, $context));
        }

        return '';
    }

    /**
     * OG 메타태그를 배열 형태로 해석합니다 (HTML 렌더 지연).
     *
     * 레이아웃 meta.seo.og 선언 + 코어 설정 fallback 으로 og 데이터를 구성.
     * SeoRenderer 가 모듈 declaration 과 deep-merge 한 뒤 hook 적용 가능하도록 배열 반환.
     *
     * @param  array  $seoConfig  SEO 설정
     * @param  array  $context  데이터 컨텍스트
     * @param  string  $fallbackTitle  fallback 타이틀
     * @param  string  $fallbackDescription  fallback 설명
     * @return array OG 데이터 배열 (type, title, description, image, image_*, site_name, locale, extra)
     */
    public function resolveOgData(array $seoConfig, array $context, string $fallbackTitle, string $fallbackDescription): array
    {
        $og = $seoConfig['og'] ?? [];

        $title = $this->stripHtml($this->safeEval($og['title'] ?? '', $context)) ?: $fallbackTitle;
        $description = $this->stripHtml($this->safeEval($og['description'] ?? '', $context)) ?: $fallbackDescription;
        $image = $this->absoluteUrl($this->safeEval($og['image'] ?? '', $context));
        $secureUrl = isset($og['image_secure_url'])
            ? $this->absoluteUrl($this->safeEval($og['image_secure_url'], $context))
            : ($image !== '' && str_starts_with($image, 'https://') ? $image : '');

        // site_name fallback: 코어 설정이 다국어 array 일 수 있으므로 resolveLocalizedValue 통과
        $explicitSiteName = $this->stripHtml($this->safeEval($og['site_name'] ?? '', $context));
        $siteNameFallback = g7_core_settings('seo.og_default_site_name');
        if ($siteNameFallback === null || $siteNameFallback === '') {
            $siteNameFallback = g7_core_settings('general.site_name', '');
        }

        return [
            'type' => $this->safeEval($og['type'] ?? 'website', $context),
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'image_secure_url' => $secureUrl,
            'image_width' => $this->resolveIntOrSetting($og['image_width'] ?? null, 'seo.og_image_default_width', $context),
            'image_height' => $this->resolveIntOrSetting($og['image_height'] ?? null, 'seo.og_image_default_height', $context),
            'image_type' => $this->safeEval($og['image_type'] ?? '', $context),
            'image_alt' => $this->stripHtml($this->safeEval($og['image_alt'] ?? $og['title'] ?? '', $context)),
            'site_name' => $explicitSiteName !== '' ? $explicitSiteName : $this->resolveLocalizedValue($siteNameFallback),
            'locale' => $this->safeEval($og['locale'] ?? '', $context) ?: app()->getLocale(),
            'extra' => is_array($og['extra'] ?? null) ? $og['extra'] : [],
        ];
    }

    /**
     * Twitter 카드 메타태그를 배열 형태로 해석합니다.
     *
     * 미선언 필드는 OG 데이터를 fallback 으로 사용 (Slack 등이 트위터 카드를 폴백 경로로 활용).
     *
     * @param  array  $seoConfig  SEO 설정
     * @param  array  $context  데이터 컨텍스트
     * @param  array  $ogData  resolveOgData 결과 (fallback 용)
     * @return array Twitter 카드 데이터
     */
    public function resolveTwitterData(array $seoConfig, array $context, array $ogData): array
    {
        $tw = $seoConfig['twitter'] ?? [];
        $hasImage = ($ogData['image'] ?? '') !== '';

        // 코어 설정이 다국어 array 일 가능성 대비 — resolveLocalizedValue 통과
        $cardFallback = g7_core_settings('seo.twitter_default_card', $hasImage ? 'summary_large_image' : 'summary');
        $siteFallback = g7_core_settings('seo.twitter_default_site', '');

        return [
            'card' => isset($tw['card']) ? $this->safeEval((string) $tw['card'], $context) : $this->resolveLocalizedValue($cardFallback),
            'site' => isset($tw['site']) ? $this->safeEval((string) $tw['site'], $context) : $this->resolveLocalizedValue($siteFallback),
            'creator' => $this->safeEval($tw['creator'] ?? '', $context),
            'title' => isset($tw['title'])
                ? $this->stripHtml($this->safeEval($tw['title'], $context))
                : (string) ($ogData['title'] ?? ''),
            'description' => isset($tw['description'])
                ? $this->stripHtml($this->safeEval($tw['description'], $context))
                : (string) ($ogData['description'] ?? ''),
            'image' => isset($tw['image'])
                ? $this->absoluteUrl($this->safeEval($tw['image'], $context))
                : (string) ($ogData['image'] ?? ''),
            'image_alt' => isset($tw['image_alt'])
                ? $this->stripHtml($this->safeEval($tw['image_alt'], $context))
                : (string) ($ogData['image_alt'] ?? ''),
            'extra' => is_array($tw['extra'] ?? null) ? $tw['extra'] : [],
        ];
    }

    /**
     * 구조화 데이터 (JSON-LD)를 배열 형태로 해석합니다.
     *
     * 표현식 평가만 수행하고 JSON 직렬화는 renderStructuredJson 에서.
     *
     * @param  array  $seoConfig  SEO 설정
     * @param  array  $context  데이터 컨텍스트
     * @return array|null 평가된 스키마 배열 또는 null
     */
    public function resolveStructuredDataArray(array $seoConfig, array $context): ?array
    {
        $structuredData = $seoConfig['structured_data'] ?? null;
        if (empty($structuredData)) {
            return null;
        }

        return $this->resolveStructuredDataRecursive($structuredData, $context);
    }

    /**
     * OG 데이터 배열을 HTML 메타태그로 렌더합니다.
     *
     * @param  array  $og  OG 데이터 배열
     * @return string HTML 메타태그
     */
    public function renderOgHtml(array $og): string
    {
        $tags = '';
        $type = (string) ($og['type'] ?? '');
        if ($type === '') {
            return '';
        }

        $tags .= '<meta property="og:type" content="'.e($type).'">'."\n";

        $title = (string) ($og['title'] ?? '');
        if ($title !== '') {
            $tags .= '    <meta property="og:title" content="'.e($title).'">'."\n";
        }
        $description = (string) ($og['description'] ?? '');
        if ($description !== '') {
            $tags .= '    <meta property="og:description" content="'.e($description).'">'."\n";
        }

        $image = (string) ($og['image'] ?? '');
        if ($image !== '') {
            $tags .= '    <meta property="og:image" content="'.e($image).'">'."\n";

            $secure = (string) ($og['image_secure_url'] ?? '');
            if ($secure !== '' && str_starts_with($secure, 'https://')) {
                $tags .= '    <meta property="og:image:secure_url" content="'.e($secure).'">'."\n";
            }
            $imageType = (string) ($og['image_type'] ?? '');
            if ($imageType !== '') {
                $tags .= '    <meta property="og:image:type" content="'.e($imageType).'">'."\n";
            }
            $width = $og['image_width'] ?? null;
            if (is_numeric($width) && (int) $width > 0) {
                $tags .= '    <meta property="og:image:width" content="'.(int) $width.'">'."\n";
            }
            $height = $og['image_height'] ?? null;
            if (is_numeric($height) && (int) $height > 0) {
                $tags .= '    <meta property="og:image:height" content="'.(int) $height.'">'."\n";
            }
            $imageAlt = (string) ($og['image_alt'] ?? '');
            if ($imageAlt !== '') {
                $tags .= '    <meta property="og:image:alt" content="'.e($imageAlt).'">'."\n";
            }
        }

        $siteName = (string) ($og['site_name'] ?? '');
        if ($siteName !== '') {
            $tags .= '    <meta property="og:site_name" content="'.e($siteName).'">'."\n";
        }

        foreach ((array) ($og['extra'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $prop = (string) ($entry['property'] ?? '');
            $content = (string) ($entry['content'] ?? '');
            if ($prop !== '' && $content !== '') {
                $tags .= '    <meta property="'.e($prop).'" content="'.e($content).'">'."\n";
            }
        }

        return $tags;
    }

    /**
     * Twitter 데이터 배열을 HTML 메타태그로 렌더합니다.
     *
     * @param  array  $tw  Twitter 데이터 배열
     * @return string HTML 메타태그
     */
    public function renderTwitterHtml(array $tw): string
    {
        $card = (string) ($tw['card'] ?? '');
        if ($card === '') {
            return '';
        }

        $tags = '<meta name="twitter:card" content="'.e($card).'">'."\n";

        foreach (['site', 'creator', 'title', 'description', 'image'] as $key) {
            $val = (string) ($tw[$key] ?? '');
            if ($val !== '') {
                $tags .= '    <meta name="twitter:'.$key.'" content="'.e($val).'">'."\n";
            }
        }
        $imageAlt = (string) ($tw['image_alt'] ?? '');
        if ($imageAlt !== '') {
            $tags .= '    <meta name="twitter:image:alt" content="'.e($imageAlt).'">'."\n";
        }
        foreach ((array) ($tw['extra'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = (string) ($entry['name'] ?? '');
            $content = (string) ($entry['content'] ?? '');
            if ($name !== '' && $content !== '') {
                $tags .= '    <meta name="'.e($name).'" content="'.e($content).'">'."\n";
            }
        }

        return $tags;
    }

    /**
     * 구조화 데이터 배열을 JSON-LD 문자열로 렌더합니다.
     *
     * @param  array|null  $structuredData  구조화 데이터 배열
     * @return string|null JSON-LD 문자열 또는 null
     */
    public function renderStructuredJson(?array $structuredData): ?string
    {
        if (empty($structuredData)) {
            return null;
        }

        $resolved = array_merge(['@context' => 'https://schema.org'], $structuredData);

        return json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * 표현식을 평가하여 항상 string 으로 반환.
     *
     * - string + {{...}} 표현식 → ExpressionEvaluator 로 평가 (string 보장)
     * - 다국어 array (`["ko" => "...", "en" => "..."]`) → 현재 로케일 → fallback locale 순으로 추출
     * - 그 외 (numeric, bool, null, 기타 array) → resolveLocalizedValue 로 string 변환
     *
     * 'Array to string conversion' / TypeError 회귀 방지 — resolveOgData/resolveTwitterData 에서
     * 모든 표현식 평가 지점은 본 헬퍼를 거쳐야 함.
     */
    private function safeEval(mixed $expr, array $context): string
    {
        if (is_string($expr)) {
            return $this->evaluator->evaluate($expr, $context);
        }

        return $this->resolveLocalizedValue($expr);
    }

    /**
     * 상대 경로 URL 을 절대 URL 로 변환.
     */
    private function absoluteUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        return str_starts_with($url, 'http') ? $url : url($url);
    }

    /**
     * 정수 값 해석 — 명시값 우선, 없으면 코어 설정 fallback.
     *
     * @param  mixed  $value  레이아웃 선언 값 (int|string expr|null)
     * @param  string  $settingKey  코어 설정 키
     * @param  array  $context  데이터 컨텍스트 (표현식 평가용)
     * @return int|null 정수 또는 null
     */
    private function resolveIntOrSetting(mixed $value, string $settingKey, array $context): ?int
    {
        if ($value === null || $value === '') {
            $default = g7_core_settings($settingKey);

            return is_numeric($default) ? (int) $default : null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $evaluated = $this->evaluator->evaluate($value, $context);

            return is_numeric($evaluated) ? (int) $evaluated : null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * 구조화 데이터를 재귀적으로 표현식 평가합니다.
     *
     * @param  array  $data  구조화 데이터
     * @param  array  $context  데이터 컨텍스트
     * @return array 평가된 데이터
     */
    private function resolveStructuredDataRecursive(array $data, array $context): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $resolved = $this->resolveStructuredDataRecursive($value, $context);
                // @type이 있는 하위 객체에서 필수 값이 모두 빈 문자열이면 제거
                // 예: aggregateRating의 ratingValue/reviewCount가 모두 빈 경우
                if ($this->isEmptyStructuredDataObject($resolved)) {
                    continue;
                }
                $result[$key] = $resolved;
            } elseif (is_string($value)) {
                $evaluated = $this->evaluator->evaluate($value, $context);
                // 구조화 데이터의 description 필드는 HTML 태그 제거
                $result[$key] = ($key === 'description') ? $this->stripHtml($evaluated) : $evaluated;
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * 구조화 데이터 하위 객체가 실질적으로 비어있는지 확인합니다.
     *
     * @type 키가 있는 객체에서, @type 외 필드 중 하나라도 빈 문자열이면
     * 해당 객체를 JSON-LD에서 제거합니다.
     * 예: aggregateRating의 ratingValue=""이면, bestRating="5"가 있더라도 제거됩니다.
     * Google 구조화 데이터 검증에서 필수 필드가 빈 값이면 에러로 처리되기 때문입니다.
     *
     * @param  array  $resolved  평가된 구조화 데이터 객체
     * @return bool 비어있으면 true
     */
    private function isEmptyStructuredDataObject(array $resolved): bool
    {
        if (! isset($resolved['@type'])) {
            return false;
        }

        foreach ($resolved as $key => $value) {
            if ($key === '@type') {
                continue;
            }
            if ($value === '' || $value === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * HTML 태그를 제거하고 공백을 정규화합니다.
     *
     * @param  string  $html  HTML 문자열
     * @return string 순수 텍스트
     */
    private function stripHtml(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
}
