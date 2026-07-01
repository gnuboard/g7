<?php

namespace App\Seo\Editor;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Extension\HookManager;
use App\Seo\SeoMetaResolver;

/**
 * OG/Twitter/구조화 미리보기 서비스 — 편집기 [검색엔진] 탭.
 *
 * og 각 란의 **기본값·출처·필터잠김**은 편집기가 정적 추정할 수 없고, 현재 데이터·
 * 설정·활성 확장 조합으로 서버가 실제 계산해야만 안다. 본 서비스가 운영 SeoRenderer 와
 * 동일한 cascade(`resolveOgData` → 모듈 `seoOgDefaults` fillEmptyKeys → 필터)를 실행하고,
 * **필터 적용 전/후 2회 계산해 diff** 한다:
 *
 *  - og/twitter: 키별 `{key, effectiveValue, source, overriddenByLayout, lockedByFilter}`.
 *    `source` ∈ core/module:{id}/layout/filter. 코어 기본값이 슬롯 선점하면 `core` 로 정확히
 *  표기(메커니즘). 필터 전/후 값이 달라진 키만 `lockedByFilter=true`.
 *  - structured: 통 덮어쓰기 모델 — autoBlock(모듈 자동)/hasLayoutBlock/lockedByFilter
 *    +filteredBlock.
 *
 * 기본값은 extensions ∧ page_type 둘 다에 종속(SeoRenderer.php:941-943) — 둘 다 있을 때만
 * 모듈 declaration 을 수집한다(`defaultsAvailable`/`missing`). 편집기 캐시 우회.
 *
 * @since 7.0.0-beta.?
 */
class SeoOgPreviewService
{
    public function __construct(
        private readonly SeoMetaResolver $metaResolver,
        private readonly ModuleManagerInterface $moduleManager,
        private readonly PluginManagerInterface $pluginManager,
    ) {}

    /**
     * og/twitter/structured 미리보기를 계산합니다.
     *
     * @param  array  $seoConfig  현재 편집 중 meta.seo (병합본 — base 상속분 포함, dirty)
     * @param  array  $context  편집기 샘플 컨텍스트
     * @param  array  $routeParams  샘플 라우트 파라미터
     * @param  array|null  $ownSeo  이 레이아웃이 직접 선언한 meta.seo(base 병합 전, `__editor.original`).
     *  병합본에는 있으나 own 에는 없는 og/twitter 키 = base 상속(SEO-B).
     *                              미전달 시 종전 동작(상속/자체 구분 없이 모두 layout 출처).
     * @param  string|null  $locale  자동값 연결 칩 라벨(seoOgDefaultMeta) 해석 로케일. null 이면 app locale.
     * @return array{defaultsAvailable: bool, missing: array, og: array, twitter: array, structured: array}
     */
    public function preview(array $seoConfig, array $context, array $routeParams, ?array $ownSeo = null, ?string $locale = null): array
    {
        $pageType = $seoConfig['page_type'] ?? null;
        $extensions = $seoConfig['extensions'] ?? [];
        $locale ??= app()->getLocale();

        $missing = [];
        if (empty($extensions)) {
            $missing[] = 'extensions';
        }
        if ($pageType === null || $pageType === '') {
            $missing[] = 'page_type';
        }
        $defaultsAvailable = empty($missing);

        // 1. 레이아웃 + 코어 cascade(모듈 declaration 전).
        $fallbackTitle = (string) ($seoConfig['title'] ?? '');
        $fallbackDescription = (string) ($seoConfig['description'] ?? '');
        $layoutOg = $this->metaResolver->resolveOgData($seoConfig, $context, $fallbackTitle, $fallbackDescription);
        $layoutTwitter = $this->metaResolver->resolveTwitterData($seoConfig, $context, $layoutOg);
        $layoutDeclaredOg = $seoConfig['og'] ?? [];
        $layoutDeclaredTwitter = $seoConfig['twitter'] ?? [];

        // 2. 모듈/플러그인 declaration (extensions ∧ page_type 둘 다 충족 시만).
        $extOg = [];
        $extTwitter = [];
        $extStructured = null;
        $extOgOwner = [];      // key => 'module:{id}' (어느 확장이 그 키를 채웠나)
        $extOgMeta = [];       // key => {expr, label} (연결 칩)
        $extTwitterMeta = [];
        $extStructuredMeta = []; // 점 경로 key => {expr, label}
        if ($defaultsAvailable) {
            foreach ($extensions as $extDef) {
                $instance = $this->resolveInstance($extDef['type'] ?? null, $extDef['id'] ?? null);
                if (! $instance) {
                    continue;
                }
                $ownerTag = ($extDef['type']).':'.($extDef['id']);
                $declOg = $this->safeCall($instance, 'seoOgDefaults', [$pageType, $context, $routeParams]);
                $declOgMeta = $this->normalizeMeta($this->safeCall($instance, 'seoOgDefaultMeta', [$pageType]), $locale);
                foreach ((array) $declOg as $k => $v) {
                    if ($k === 'extra') {
                        continue;
                    }
                    if (($v !== null && $v !== '') && ! isset($extOg[$k])) {
                        $extOg[$k] = $v;
                        $extOgOwner[$k] = $ownerTag;
                        if (isset($declOgMeta[$k]) && ! isset($extOgMeta[$k])) {
                            $extOgMeta[$k] = $declOgMeta[$k];
                        }
                    }
                }
                $declTwitter = $this->safeCall($instance, 'seoTwitterDefaults', [$pageType, $context, $routeParams]);
                $declTwitterMeta = $this->normalizeMeta($this->safeCall($instance, 'seoTwitterDefaultMeta', [$pageType]), $locale);
                foreach ((array) $declTwitter as $k => $v) {
                    if ($k !== 'extra' && ($v !== null && $v !== '') && ! isset($extTwitter[$k])) {
                        $extTwitter[$k] = $v;
                        if (isset($declTwitterMeta[$k]) && ! isset($extTwitterMeta[$k])) {
                            $extTwitterMeta[$k] = $declTwitterMeta[$k];
                        }
                    }
                }
                $declStructured = $this->safeCall($instance, 'seoStructuredData', [$pageType, $context, $routeParams]);
                if (! empty($declStructured)) {
                    $extStructured = $declStructured; // 마지막 확장 우선.
                    // 메타도 같은 확장(마지막 우선) 기준으로 — 통 덮어쓰기 모델과 정합.
                    $extStructuredMeta = $this->normalizeMeta($this->safeCall($instance, 'seoStructuredDataMeta', [$pageType]), $locale);
                }
            }
        }

        // 3. effective og — 레이아웃 값(채워졌으면) ?? 모듈 declaration. 출처 계산.
        // own(자체 선언)분을 함께 넘겨 병합본 키가 base 상속인지 자체 override 인지 구분(SEO-B).
        $ownDeclaredOg = is_array($ownSeo) ? ($ownSeo['og'] ?? []) : null;
        $ownDeclaredTwitter = is_array($ownSeo) ? ($ownSeo['twitter'] ?? []) : null;
        $og = $this->buildKeyCascade($layoutOg, $layoutDeclaredOg, $extOg, $extOgOwner, 'core.seo.filter_og_data', $context, $seoConfig, $ownDeclaredOg, $extOgMeta);
        $twitter = $this->buildKeyCascade($layoutTwitter, $layoutDeclaredTwitter, $extTwitter, [], 'core.seo.filter_twitter_data', $context, $seoConfig, $ownDeclaredTwitter, $extTwitterMeta);

        // 4. structured — 통 덮어쓰기 모델.
        $structured = $this->buildStructuredPreview($seoConfig, $extStructured, $context, $extStructuredMeta);

        return [
            'defaultsAvailable' => $defaultsAvailable,
            'missing' => $missing,
            'og' => $og,
            'twitter' => $twitter,
            'structured' => $structured,
        ];
    }

    /**
     * og/twitter 키별 cascade + 필터 전/후 diff 로 출처·잠김을 계산합니다.
     *
     * @param  array  $base  resolveOgData/resolveTwitterData 결과(레이아웃+코어)
     * @param  array  $layoutDeclared  레이아웃이 직접 선언한 og/twitter 키(override 표시)
     * @param  array  $extDefaults  모듈 declaration (빈 키 채움)
     * @param  array  $extOwner  key => 'type:id' (모듈 출처)
     * @param  string  $filterHook  필터 훅명
     * @param  array  $context  컨텍스트
     * @param  array  $seoConfig  meta.seo
     * @param  array|null  $ownDeclared  이 레이아웃이 직접 선언한 키(base 병합 전). 병합본에는
     *                                   있으나 own 에 없는 키 = base 상속(source='inherited').
     *                                   null 이면 상속/자체 구분 안 함(종전 동작).
     * @param  array  $extMeta  key => {expr, label} — 모듈 자동값의 데이터 경로 메타(연결 칩).
     *                          출처가 module 인 키에만 sourceExpr/label 동반.
     * @return array<int, array{key, effectiveValue, source, overriddenByLayout, inheritedFromBase, lockedByFilter, sourceExpr?, label?}>
     */
    private function buildKeyCascade(array $base, array $layoutDeclared, array $extDefaults, array $extOwner, string $filterHook, array $context, array $seoConfig, ?array $ownDeclared = null, array $extMeta = []): array
    {
        // fillEmptyKeys 정합 — base 의 빈 키를 모듈 declaration 으로 채움.
        $filled = $base;
        foreach ($extDefaults as $k => $v) {
            if ($this->isEmpty($filled[$k] ?? null) && ! $this->isEmpty($v)) {
                $filled[$k] = $v;
            }
        }

        // 필터 전/후 diff 로 잠긴 키 식별.
        $hookCtx = ['context' => $context, 'pageType' => $seoConfig['page_type'] ?? null];
        $afterFilter = HookManager::applyFilters($filterHook, $filled, $hookCtx);

        $out = [];
        foreach ($filled as $key => $value) {
            if ($key === 'extra') {
                continue;
            }
            $overriddenByLayout = array_key_exists($key, $layoutDeclared) && ! $this->isEmpty($layoutDeclared[$key]);
            // base 상속 = 병합본(layoutDeclared)엔 있으나 이 레이아웃 own 엔 없는 키.
            // own 미전달($ownDeclared === null) 이면 상속/자체 구분 안 함(종전 동작).
            $inheritedFromBase = $overriddenByLayout
                && $ownDeclared !== null
                && ! (array_key_exists($key, $ownDeclared) && ! $this->isEmpty($ownDeclared[$key]));
            $locked = is_array($afterFilter) && array_key_exists($key, $afterFilter) && $afterFilter[$key] !== $value;

            // 출처 — filter 잠김 > base 상속 > layout override > module declaration > core(base 선점).
            if ($locked) {
                $source = 'filter';
            } elseif ($inheritedFromBase) {
                $source = 'inherited';
            } elseif ($overriddenByLayout) {
                $source = 'layout';
            } elseif (isset($extOwner[$key]) && ($filled[$key] ?? null) === ($extDefaults[$key] ?? null)) {
                $source = 'module:'.explode(':', $extOwner[$key])[1];
            } else {
                $source = 'core';
            }

            $row = [
                'key' => $key,
                'effectiveValue' => $locked ? ($afterFilter[$key] ?? $value) : $value,
                'source' => $source,
                // overriddenByLayout = 이 레이아웃이 직접 덮었는가(상속은 false). 프론트의
                // "값 채우면 출처칩 사라짐" 판정이 이 플래그(+inherited 출처)에 종속.
                'overriddenByLayout' => $overriddenByLayout && ! $inheritedFromBase,
                'inheritedFromBase' => $inheritedFromBase,
                'lockedByFilter' => $locked,
            ];

            // 출처가 module 이고 그 키에 데이터 경로 메타가 있으면 연결 칩 정보 동반.
            // 편집기는 effectiveValue(평문) 대신 sourceExpr 칩 + label 로 "어느 데이터인지" 표시하고
            // 그 자리에서 다른 데이터로 교체할 수 있게 한다. 레이아웃 override/잠김 키엔 미동반.
            if (str_starts_with($source, 'module:') && isset($extMeta[$key])) {
                $row['sourceExpr'] = $extMeta[$key]['expr'];
                $row['label'] = $extMeta[$key]['label'];
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * structured_data 미리보기 — 통 덮어쓰기 모델.
     *
     * @param  array  $seoConfig  meta.seo
     * @param  array|null  $extStructured  모듈 declaration(마지막 확장 우선)
     * @param  array  $context  컨텍스트
     * @param  array  $autoMeta  점 경로 key => {expr, label} — 자동 블록의 데이터 경로 메타(연결 칩)
     * @return array{autoBlock: array|null, autoMeta: array, hasLayoutBlock: bool, lockedByFilter: bool, filteredBlock: array|null}
     */
    private function buildStructuredPreview(array $seoConfig, ?array $extStructured, array $context, array $autoMeta = []): array
    {
        $layoutBlock = $seoConfig['structured_data'] ?? null;
        $hasLayoutBlock = ! empty($layoutBlock);

        // 통 덮어쓰기: 레이아웃 선언 있으면 그것, 없으면 모듈 declaration.
        $effective = $hasLayoutBlock ? $layoutBlock : $extStructured;

        // 필터 전/후 전체 블록 diff(통 override — 등록=전체 lock).
        $hookCtx = ['context' => $context, 'pageType' => $seoConfig['page_type'] ?? null];
        $afterFilter = HookManager::applyFilters('core.seo.filter_structured_data', $effective, $hookCtx);
        $locked = $afterFilter !== $effective && ! empty($afterFilter);

        return [
            // 토글 OFF 시 보여줄 모듈 자동 블록(읽기전용 미리보기).
            'autoBlock' => $extStructured,
            // 자동 블록 점 경로 키별 데이터 경로 메타(연결 칩) — 모듈 자동값일 때만 비지 않음.
            'autoMeta' => $autoMeta,
            'hasLayoutBlock' => $hasLayoutBlock,
            'lockedByFilter' => $locked,
            'filteredBlock' => $locked ? $afterFilter : null,
        ];
    }

    /**
     * 모듈 *Meta 선언을 정규화합니다 — label 을 현재 로케일 평문으로 해석.
     *
     * label 은 두 형태를 허용한다(권장 순):
     *  1. **번역 키 문자열**(`'sirsoft-ecommerce::seo.product_name'`) — `__()` 로 해석한다.
     *     모듈 lang 파일(+번들 언어팩)이 그 키를 번역하므로 **추가 언어(ja 등)에 자동 대응**한다(권장).
     *  2. 인라인 다국어 맵(`['ko' => '상품 이름', 'en' => '...']`) — 맵에서 로케일 픽(하위호환).
     *     ko/en 만 담기면 그 외 로케일은 en 폴백(언어팩 미대응 — 권장하지 않음).
     *
     * `['image' => ['expr' => '{{...}}', 'label' => 'pkg::seo.image']]`
     * → `['image' => ['expr' => '{{...}}', 'label' => '상품 이미지'(해석값)]]`. expr 누락/비문자열 키는 버림.
     *
     * @param  mixed  $meta  모듈 *Meta 반환값(키 => {expr, label})
     * @param  string  $locale  라벨 해석 로케일
     * @return array<string, array{expr: string, label: string}> 정규화된 메타
     */
    private function normalizeMeta(mixed $meta, string $locale): array
    {
        if (! is_array($meta)) {
            return [];
        }
        $out = [];
        foreach ($meta as $key => $def) {
            if (! is_array($def) || ! is_string($def['expr'] ?? null) || $def['expr'] === '') {
                continue;
            }
            $label = $def['label'] ?? '';
            if (is_string($label) && $label !== '') {
                // 번역 키 — 요청 로케일로 __() 해석(언어팩 대응). 미등록 키면 Laravel 폴백
                // (fallback_locale) 후에도 못 찾으면 키 원문을 반환하므로, 그 경우만 빈 문자열로 둔다.
                $resolved = __($label, [], $locale);
                $label = (is_string($resolved) && $resolved !== $label) ? $resolved : '';
            } elseif (is_array($label)) {
                // 하위호환 — 인라인 다국어 맵.
                $label = $label[$locale] ?? $label['en'] ?? reset($label) ?: '';
            }
            $out[$key] = ['expr' => $def['expr'], 'label' => (string) $label];
        }

        return $out;
    }

    /**
     * 확장 인스턴스를 조회합니다.
     *
     * @param  string|null  $type  module|plugin
     * @param  string|null  $id  식별자
     * @return object|null 인스턴스 또는 null
     */
    private function resolveInstance(?string $type, ?string $id): ?object
    {
        if (! is_string($type) || ! is_string($id)) {
            return null;
        }
        if ($type === 'module') {
            return $this->moduleManager->getModule($id);
        }
        if ($type === 'plugin') {
            return $this->pluginManager->getPlugin($id);
        }

        return null;
    }

    /**
     * 확장 메서드를 격리 호출합니다.
     *
     * @param  object  $instance  확장 인스턴스
     * @param  string  $method  메서드명
     * @param  array  $args  인자
     * @return mixed 결과 또는 빈 배열
     */
    private function safeCall(object $instance, string $method, array $args): mixed
    {
        if (! method_exists($instance, $method)) {
            return [];
        }
        try {
            return $instance->{$method}(...$args);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 값이 비었는지(null/빈 문자열/빈 배열) 판정합니다.
     *
     * @param  mixed  $value  값
     * @return bool 비었으면 true
     */
    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
