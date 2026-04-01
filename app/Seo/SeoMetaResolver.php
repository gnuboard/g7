<?php

namespace App\Seo;

class SeoMetaResolver
{
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

        // OG 태그 해석
        $ogTags = $this->resolveOgTags($seoConfig, $context, $title, $description);

        // 구조화 데이터 (JSON-LD) 해석
        $jsonLd = $this->resolveStructuredData($seoConfig, $context);

        return [
            'title' => $title,
            'titleSuffix' => $titleSuffix,
            'description' => $description,
            'keywords' => $keywords,
            'ogTags' => $ogTags,
            'jsonLd' => $jsonLd,
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

        // Tier 2: 모듈 설정 템플릿 (page_type 기반)
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

        // Tier 2: 모듈 설정 템플릿 (page_type 기반)
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
                return (string) $value;
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
                return (string) $value;
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
        if (str_starts_with($expr, '$module_settings:')) {
            $rest = substr($expr, strlen('$module_settings:'));
            [$effectiveId, $key] = $this->parseExtensionSettingsKey($rest, $moduleIdentifier);
            if ($effectiveId) {
                return (string) g7_module_settings($effectiveId, $key, '');
            }
        }

        // $plugin_settings:key 또는 $plugin_settings:plugin-id:key
        if (str_starts_with($expr, '$plugin_settings:')) {
            $rest = substr($expr, strlen('$plugin_settings:'));
            [$effectiveId, $key] = $this->parseExtensionSettingsKey($rest, $pluginIdentifier);
            if ($effectiveId) {
                return (string) g7_plugin_settings($effectiveId, $key, '');
            }
        }

        // $core_settings:key
        if (str_starts_with($expr, '$core_settings:')) {
            $key = substr($expr, strlen('$core_settings:'));

            return (string) g7_core_settings($key, '');
        }

        // $query:key
        if (str_starts_with($expr, '$query:')) {
            $key = substr($expr, strlen('$query:'));

            return (string) request()->query($key, '');
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
     * 해석된 변수로 템플릿 문자열을 치환합니다.
     *
     * @param  string  $template  템플릿 문자열 ({var_name} 플레이스홀더 포함)
     * @param  array  $resolvedVars  해석된 변수 (키 → 값)
     * @return string 치환된 문자열
     */
    private function substituteVars(string $template, array $resolvedVars): string
    {
        $replacements = [];
        foreach ($resolvedVars as $key => $value) {
            $replacements['{'.$key.'}'] = $value;
        }

        return str_replace(array_keys($replacements), array_values($replacements), $template);
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
        // meta.seo.og.title이 있으면 사용
        $ogTitle = data_get($seoConfig, 'og.title', '');
        if ($ogTitle !== '') {
            return $this->evaluator->evaluate($ogTitle, $context);
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
        $ogDescription = data_get($seoConfig, 'og.description', '');
        if ($ogDescription !== '') {
            return $this->stripHtml($this->evaluator->evaluate($ogDescription, $context));
        }

        return '';
    }

    /**
     * OG 태그를 생성합니다.
     *
     * @param  array  $seoConfig  SEO 설정
     * @param  array  $context  데이터 컨텍스트
     * @param  string  $fallbackTitle  fallback 타이틀
     * @param  string  $fallbackDescription  fallback 설명
     * @return string OG 메타태그 HTML
     */
    private function resolveOgTags(array $seoConfig, array $context, string $fallbackTitle, string $fallbackDescription): string
    {
        $og = $seoConfig['og'] ?? [];
        if (empty($og)) {
            return '';
        }

        $tags = '';
        $ogType = $this->evaluator->evaluate($og['type'] ?? 'website', $context);
        $ogTitle = $this->stripHtml($this->evaluator->evaluate($og['title'] ?? '', $context)) ?: $fallbackTitle;
        $ogDescription = $this->stripHtml($this->evaluator->evaluate($og['description'] ?? '', $context)) ?: $fallbackDescription;
        $ogImage = $this->evaluator->evaluate($og['image'] ?? '', $context);

        $tags .= '<meta property="og:type" content="'.e($ogType).'">'."\n";

        if ($ogTitle !== '') {
            $tags .= '    <meta property="og:title" content="'.e($ogTitle).'">'."\n";
        }

        if ($ogDescription !== '') {
            $tags .= '    <meta property="og:description" content="'.e($ogDescription).'">'."\n";
        }

        if ($ogImage !== '') {
            $absoluteImage = str_starts_with($ogImage, 'http') ? $ogImage : url($ogImage);
            $tags .= '    <meta property="og:image" content="'.e($absoluteImage).'">'."\n";
        }

        return $tags;
    }

    /**
     * 구조화 데이터 (JSON-LD)를 생성합니다.
     *
     * @param  array  $seoConfig  SEO 설정
     * @param  array  $context  데이터 컨텍스트
     * @return string|null JSON-LD 문자열 또는 null
     */
    private function resolveStructuredData(array $seoConfig, array $context): ?string
    {
        $structuredData = $seoConfig['structured_data'] ?? null;
        if (empty($structuredData)) {
            return null;
        }

        // 구조화 데이터 내 표현식 재귀 평가
        $resolved = $this->resolveStructuredDataRecursive($structuredData, $context);

        // @context 추가
        $resolved = array_merge(['@context' => 'https://schema.org'], $resolved);

        return json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
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
     * 다국어 객체에서 현재 로케일 값을 추출합니다.
     *
     * @param  mixed  $value  다국어 객체 또는 문자열
     * @return string 현재 로케일 값
     */
    private function resolveLocalizedValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            $locale = app()->getLocale();
            if (isset($value[$locale])) {
                return (string) $value[$locale];
            }
            $fallbackLocale = config('app.fallback_locale', 'en');
            if (isset($value[$fallbackLocale])) {
                return (string) $value[$fallbackLocale];
            }
        }

        return (string) ($value ?? '');
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
