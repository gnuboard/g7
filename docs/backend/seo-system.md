# SEO 페이지 생성기 시스템 (SEO Page Generator)

> 그누보드7 SEO 시스템의 전체 규정 문서 (SSoT)

## TL;DR (5초 요약)

```text
1. SeoMiddleware: 봇 요청 감지 → ?locale= 파라미터 해석 → SeoRenderer가 정적 HTML 생성 (캐시 우선)
2. 봇 감지: jaybizzle/crawler-detect 라이브러리(약 1,000종) + G7 보강 패턴(미커버 3종) + 운영자 커스텀 패턴 + core.seo.resolve_is_bot 훅 — 4-레이어 체인
3. 다국어 SEO: ?locale=en 쿼리 기반 + hreflang 태그 + 다국어 sitemap (supported_locales 2개 이상 시 자동)
4. meta.seo: 레이아웃 JSON에서 SEO 렌더링 대상 선언 (enabled, og, structured_data, vars, page_type, toggle_setting)
5. seo-config.json: 텍스트 추출(text_props), 속성 매핑(attr_map), 허용 속성(allowed_attrs), 컴포넌트→HTML 매핑 — 모두 템플릿 선언
6. 훅 시스템: core.seo.filter_context/filter_og_data/filter_twitter_data/filter_structured_data/filter_meta/filter_view_data + 봇 감지 훅 core.seo.resolve_is_bot
7. 도메인 ownership: 모듈/플러그인이 `seoOgDefaults`/`seoTwitterDefaults`/`seoStructuredData` 메서드로 자기 도메인 OG/Twitter/JSON-LD 를 owned (이커머스 Product, 게시판 Article 등)
8. Sitemap: 스트리밍 writer(유계 메모리)로 비공개 디스크에 분할 커밋(sitemapindex + sitemap-{n}.xml) 후 서빙 — 요청 스레드 생성 없음, 미스 시 잡 디스패치 + stale/503. 증분 저장소(sitemap_urls) + 재생성 모드(SitemapGenerationMode full/auto/incremental) + 진행상황(SitemapProgress, Reverb 실시간/OFF 폴링)
9. Artisan: seo:warmup, seo:clear, seo:stats, seo:generate-sitemap(--rebuild/--mode)
```

## 아키텍처 개요

```
Request → web.php catch-all → SeoMiddleware (봇 감지)
                                  │
                    ┌─────────────┴─────────────┐
                    │                           │
              [검색 봇]                    [일반 사용자]
                    │                           │
              [캐시 확인]                  기존 SPA 응답
               /        \                (app.blade.php)
            HIT         MISS
             │            │
       캐시 HTML      SeoRenderer 실행
        반환          1. TemplateRouteResolver (URL→레이아웃)
                      2. LayoutService.getLayout() (병합된 JSON)
                      3. DataSourceResolver (API 호출)
                     ─── 훅: core.seo.filter_context ───
                      4. ExpressionEvaluator (바인딩 평가)
                      5. SeoMetaResolver.resolve (배열 형태 og/twitter/structured_data)
                      6. 모듈/플러그인 declaration cascade
                         (seoOgDefaults / seoTwitterDefaults / seoStructuredData)
                     ─── 훅: core.seo.filter_og_data / filter_twitter_data / filter_structured_data ───
                      7. HTML/JSON 재렌더 (renderOgHtml / renderTwitterHtml / renderStructuredJson)
                     ─── 훅: core.seo.filter_meta (통합) ───
                      8. ComponentHtmlMapper (컴포넌트→HTML)
                     ─── 훅: core.seo.filter_view_data ───
                      9. seo.blade.php (최종 HTML — og + twitter + jsonLd 슬롯)
                     10. SeoCacheManager (결과 캐시)
```

## 코어 클래스 및 인터페이스

### Contracts (`app/Seo/Contracts/`)

| 인터페이스 | 용도 |
|-----------|------|
| SeoRendererInterface | SEO HTML 렌더링 |
| SeoCacheManagerInterface | 캐시 저장/조회/무효화 |
| SitemapContributorInterface | 확장별 Sitemap URL 기여 |

### 코어 클래스 (`app/Seo/`)

| 클래스 | 역할 |
|--------|------|
| SeoRenderer | 전체 렌더링 파이프라인 오케스트레이션 |
| SeoCacheManager | 캐시 관리 (file/redis) |
| BotDetector | User-Agent 기반 봇 감지 |
| ExpressionEvaluator | `{{}}` 바인딩 표현식 평가 |
| ComponentHtmlMapper | 컴포넌트→HTML 태그 매핑 |
| DataSourceResolver | 내부 HTTP API 호출 |
| TemplateRouteResolver | URL→레이아웃 매핑 |
| SeoMetaResolver | 3계층 캐스케이드 메타 해석 |
| SeoMiddleware | 미들웨어 (봇 감지 → 렌더링) |
| SeoServiceProvider | DI 바인딩 |
| SitemapGenerator | Sitemap 수집기 (contributor 드레인 → writer 스트리밍) |
| SitemapManager | Sitemap 재생성 오케스트레이션 (모드 분기 + 진행상황 + 훅) |
| SitemapWriter | 자식파일당 in-memory 버퍼 flush + 분할 + atomic commit |
| SitemapFileStore | 디스크 세트 읽기/서빙 (manifest·index·child 응답) |
| SitemapXmlRenderer | XML escape/`<url>` 블록/hreflang 단일 출처 |
| SitemapIndexer | 리소스→sitemap_urls index/deindex (리스너 경유) |
| SitemapProgress | 진행상황 스토어 (phase 기반 + 방송) |
| AbstractSitemapContributor | contributor 브리지 base (getUrls↔getUrlsLazy) |
| SeoInvalidationRegistry | 무효화 규칙 레지스트리 |
| SeoDeclarationCollector | 레이아웃 SEO 선언 수집 |
| SeoCacheStatsService | 캐시 통계 집계 |

### Admin API (`app/Http/Controllers/Api/Admin/SeoCacheController.php`)

| URL | 메서드 | 라우트명 | 설명 |
|-----|--------|---------|------|
| /api/admin/seo/stats | GET | api.admin.seo.stats | 캐시 통계 |
| /api/admin/seo/clear-cache | POST | api.admin.seo.clear-cache | 캐시 삭제 |
| /api/admin/seo/warmup | POST | api.admin.seo.warmup | 워밍업 |
| /api/admin/seo/cached-urls | GET | api.admin.seo.cached-urls | 캐시 URL 목록 |
| /api/admin/seo/sitemap/regenerate | POST | api.admin.seo.sitemap.regenerate | Sitemap 전체 재생성 (큐 예약, mode=full) |
| /api/admin/seo/sitemap/status | GET | api.admin.seo.sitemap.status | Sitemap 생성 진행상황 + realtime_enabled |

## SeoMiddleware 동작 규칙

| 항목 | 값 |
|------|-----|
| 클래스 | `App\Seo\SeoMiddleware` |
| 별칭 | `seo` |
| 등록 위치 | User catch-all 라우트 그룹에만 |
| 금지 | 전역 등록 / Admin 라우트 부착 |

- 봇 감지: `BotDetector` 4-레이어 체인 (아래 "봇 감지 구조" 섹션 참조)
- 렌더링 실패 시: SPA fallback (기존 응답 통과)

## 봇 감지 구조

`BotDetector::isBot()` 는 다음 체인을 순서대로 평가합니다 — 어느 레이어에서든 결정이 나면 즉시 반환:

| # | 레이어 | 동작 | 비고 |
| - | ----- | ---- | ---- |
| 1 | `seo.bot_detection_enabled` | false 면 즉시 false | 전역 비활성 |
| 2 | `_escaped_fragment_` 쿼리 | 존재하면 true | 구형 크롤러 호환 |
| 3 | UA 빈 문자열 | false | UA 없는 요청 |
| 4 | `core.seo.resolve_is_bot` 훅 | 결과가 non-null 이면 즉시 결정 | 확장 슬롯 |
| 5 | `seo.bot_detection_library_enabled` (기본 true) | jaybizzle/crawler-detect + G7 보강 + 사용자 패턴을 단일 정규식으로 평가 | 주 경로 |
| 6 | 라이브러리 비활성 시 | `seo.bot_user_agents` stripos 매칭만 | 레거시 모드 |

### jaybizzle/crawler-detect 통합

- 라이브러리: `jaybizzle/crawler-detect` ^1.3.9 — MIT, 약 1,000종의 검색·링크 미리보기·AI 봇 패턴 + 76종 정상 브라우저 Exclusions
- 유지보수: `composer update`로 상류 패치 흡수 (월 단위 릴리스)
- 확장 지점: `App\Seo\BotDetectorCustomProvider` 가 `CrawlerDetect` 를 서브클래싱하여 `Crawlers` fixture 의 `$data` 배열에 G7 보강 패턴(미커버 3종) + 사용자 입력 패턴(`preg_quote()` 리터럴 이스케이프)을 병합
- G7 보강 패턴 (jaybizzle 미커버, 상류 PR 후 단계적 제거 예정):
  - `kakaotalk-scrap` — 카카오톡 링크 미리보기
  - `Meta-ExternalAgent` — Meta(Facebook) 학습 크롤러
  - `ChatGPT-User` — ChatGPT 브라우징

### `seo.bot_user_agents` 의 역할

운영자가 "추가 봇 패턴" UI 에 입력하는 값. 라이브러리가 놓치는 조직별 봇만 등록. UA 부분 문자열로 처리되며, 라이브러리 정규식 배열에 `preg_quote()` 로 이스케이프 후 병합되므로 정규식 메타문자(`.`, `+`, `?` 등)는 리터럴로 취급.

### 라이브러리 비활성화 (레거시)

`seo.bot_detection_library_enabled = false` 설정 시 라이브러리 경로를 우회하고 `seo.bot_user_agents` stripos 매칭만 수행. 잘 알려진 봇이라도 사용자 목록에 없으면 감지하지 못함.

### 확장 훅 `core.seo.resolve_is_bot`

플러그인이 IP 범위 검증·역방향 DNS·Cloudflare 봇 점수 등을 주입할 수 있는 슬롯:

```php
HookManager::addFilter('core.seo.resolve_is_bot', function ($prev, array $ctx) {
    /** @var \Illuminate\Http\Request $request */
    $request = $ctx['request'];
    $userAgent = $ctx['userAgent'];

    if (ipInGoogleRange($request->ip())) {
        return true;  // 즉시 봇 결정
    }

    return $prev;  // null 반환 시 다음 레이어로 fallthrough
});
```

반환 규약:

- `true` / `false` → 즉시 봇 결정 (체인 종료)
- `null` → 다음 레이어로 진행 (jaybizzle 라이브러리 평가)
- 여러 리스너가 등록되면 `applyFilters` 우선순위 순으로 평가, 마지막 non-null 값이 채택됨

## SeoRenderer `_global` 컨텍스트 주입

SEO 렌더링 시 `_global` 컨텍스트에 프론트엔드 설정 데이터를 주입합니다. Header/Footer 등 공통 영역이 `_global` 경로의 데이터(사이트명, 네비게이션 등)를 참조할 수 있도록 합니다.

### 자동 주입 데이터

| 키 | 소스 | 설명 |
|----|------|------|
| `_global.settings` | `SettingsService` | 코어 프론트엔드 설정 (사이트명, 로고 등) |
| `_global.modules` | `SettingsService` | 활성 모듈 목록 |
| `_global.pluginSettings` | `PluginSettingsService` | 플러그인 설정 데이터 |
| `_global.site_name` | `general.site_name` | 사이트 이름. `resolveLocalizedValue` 통과 (다국어 array 안전) |
| `_global.site_url` | `general.site_url` | 사이트 URL |

### site_name SSoT 체인 (다국어 일관성)

`general.site_name` 은 사이트 식별 기준값(SSoT)이며, 다음 경로에서 사용된다. 운영자가 다국어 JSON array 로 저장할 수 있으므로 **모든 경로가 현재 로케일 string 으로 정규화**해야 한다 (array 를 그대로 `(string)`/Blade `e()` 캐스팅하면 "Array to string conversion" TypeError 발생).

| 경로 | 출처 | 정규화 |
|------|------|--------|
| OG `og:site_name` | `seo.og_default_site_name ?? general.site_name` (`SeoMetaResolver`) | `resolveLocalizedValue` ✅ |
| JSON-LD `WebSite.name` / `{site_name}` 치환 | `_global.site_name` ← `general.site_name` (`SeoRenderer::buildGlobalContext`) | `resolveLocalizedValue` ✅ |
| SPA `<title>` | `config('app.name')` ← `general.site_name` (`SettingsServiceProvider::applyAppConfig`) | `localizeSettingValue` ✅ |
| canonical | URL 기반, site_name 미사용 (`seo.blade.php`) | N/A (무관) |

> **OG 직접입력은 의도된 override**: `seo.og_default_site_name` 에 값을 입력하면 그 값이 우선하고, 비우면 `general.site_name` 을 따른다. admin SEO 탭의 OG 입력칸 라벨/안내가 이 "선택 재정의" 위상을 명시한다. 자동 동기화/칸 제거는 하지 않는다 (drift 는 명시적 override 로 허용).
>
> `resolveLocalizedValue` 의 SSoT 는 `app/Seo/Concerns/LocalizesSeoValues` 트레이트. `SettingsServiceProvider` 는 `register()` 단계(DI 전)라 트레이트 대신 동일 의미의 `localizeSettingValue` 를 인라인한다.

### initGlobal 매핑

데이터소스의 `initGlobal` 설정에 따라 API 응답 데이터를 `_global` 경로에 매핑합니다.

```json
// 레이아웃 data_source 예시
{
    "id": "boards",
    "endpoint": "/api/boards",
    "initGlobal": "boards"
}
```

위 설정은 `boards` API 응답의 `data` 키를 `_global.boards`에 매핑합니다.

| initGlobal 값 | 동작 |
|---------------|------|
| 문자열 (`"boards"`) | API 응답 `data` 키 전체를 `_global.{값}`에 매핑 |
| 객체 (`{ "settingsAbilities": "data.abilities" }`) | API 응답의 특정 경로를 `_global.{키}`에 개별 매핑 |

## 레이아웃 JSON meta.seo 스키마

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| seo.enabled | boolean | X | SEO 렌더링 활성화 (기본: false) |
| seo.data_sources | string[] | X | SEO 시 사전 로드할 data_sources ID |
| seo.page_type | string | X | 모듈 설정 템플릿 키 결정 (예: `"product"` → `seo.meta_product_title`) |
| seo.toggle_setting | string | X | SEO 활성화 토글 설정 경로 (예: `"$module_settings:seo.seo_product_detail"`) |
| seo.vars | object | X | SEO 변수 선언 — 모듈 설정 title/description 템플릿의 `{key}` 치환용 |
| seo.priority | number | X | sitemap priority (0.0~1.0) |
| seo.changefreq | string | X | sitemap changefreq (daily/weekly 등) |
| seo.og | object | X | Open Graph 메타태그 |
| seo.structured_data | object | X | JSON-LD 구조화 데이터 |

### structured_data 빈 객체 자동 제거

`structured_data`에서 `@type`이 있는 하위 객체 중 **하나라도 빈 값(`""` 또는 `null`)인 필드가 있으면** 해당 객체 전체가 JSON-LD에서 제거됩니다. Google 구조화 데이터 검증기가 필수 필드 누락을 에러로 처리하기 때문입니다.

```json
// 리뷰가 없는 상품 → aggregateRating 자동 제거
"structured_data": {
    "@type": "Product",
    "name": "{{product.data.name}}",
    "aggregateRating": {
        "@type": "AggregateRating",
        "ratingValue": "{{reviews.data.rating_stats.avg ?? ''}}",
        "reviewCount": "{{reviews.data.reviews.total ?? ''}}",
        "bestRating": "5",
        "worstRating": "1"
    }
}
// ratingValue="" → aggregateRating 블록 전체 제거됨
```

- `@type`이 없는 일반 객체는 제거 대상 아님
- 모든 필드가 채워진 경우 정상 출력

### 상속 규칙

| 키 유형 | 병합 전략 | 예시 |
|---------|----------|------|
| 스칼라 (enabled, priority, changefreq, page_type) | 자식 우선 오버라이드 | 부모 `0.5` + 자식 `0.8` → `0.8` |
| 연관 배열 (og, vars, structured_data) | deep merge (array_replace_recursive) | 부모 `og.type` + 자식 `og.title` → 양쪽 보존 |
| data_sources (숫자 배열) | 합집합 + 중복 제거 (permissions와 동일) | 부모 `["stats"]` + 자식 `["product"]` → `["stats", "product"]` |

- 부모 base 레이아웃에서 공통 SEO 기본값 정의 가능 (`enabled: false` + `og.type: "website"`)
- 자식이 `enabled: true`로 오버라이드하여 SEO 활성화
- 3단계 이상 상속 시 각 레벨의 data_sources가 누적됨
- Partial에 meta.seo 정의 금지 (무시됨)
- meta.seo 추가/변경 시 `UpdateLayoutContentRequest` 검증 규칙 동기화 필수

## 3계층 캐스케이드 메타 해석

```
우선순위: 리소스 개별 설정 > 모듈 설정 > 코어 설정 + 코어 suffix 항상 추가

상품 상세 페이지 title 결정:
1순위: 상품 meta_title 있으면 → 사용
2순위: 모듈 meta_product_title 템플릿 → "{commerce_name} - {product_name}" 치환
3순위: 코어 meta_description → fallback
최종: + 코어 meta_title_suffix 항상 추가
```

| 페이지 | title 우선순위 | description 우선순위 |
|--------|---------------|---------------------|
| 상품 상세 | 상품 `meta_title` → 모듈 `meta_product_title` → 코어 | 상품 `meta_description` → 모듈 → 코어 |
| 카테고리 | 카테고리 `meta_title` → 모듈 `meta_category_title` → 코어 | 카테고리 `meta_description` → 모듈 → 코어 |
| 검색 결과 | 모듈 `meta_search_title` → 코어 | 모듈 `meta_search_description` → 코어 |
| 페이지 | 페이지 `seo_meta.title` → 코어 | 페이지 `seo_meta.description` → 코어 |
| 메인/게시판 | 코어 title (레이아웃 meta.title) | 코어 meta_description |

## 레이아웃 meta.seo 선언적 설정

SEO 엔진은 특정 모듈/템플릿 지식을 갖지 않습니다. 모든 SEO 변수, 페이지 유형, 토글 설정은 **레이아웃 JSON이 `meta.seo`에 선언**합니다.

### vars — SEO 변수 선언

모듈 설정 title/description 템플릿의 `{key}` 플레이스홀더를 치환할 변수를 선언합니다.

```json
"seo": {
    "vars": {
        "product_name": "{{product.data.name ?? ''}}",
        "product_description": "{{product.data.short_description ?? product.data.description ?? ''}}",
        "commerce_name": "$module_settings:basic_info.shop_name",
        "site_name": "$core_settings:general.site_name",
        "keyword_name": "$query:q"
    }
}
```

#### vars 접두사 문법

| 접두사 | 의미 | 예시 |
|--------|------|------|
| `{{expr}}` | 표현식 (context 데이터) | `"{{product.data.name ?? ''}}"` |
| `$module_settings:` | 모듈 설정 값 (컨텍스트 모듈) | `"$module_settings:basic_info.shop_name"` |
| `$module_settings:ID:` | 모듈 설정 값 (명시적 모듈 ID) | `"$module_settings:sirsoft-ecommerce:basic_info.shop_name"` |
| `$plugin_settings:` | 플러그인 설정 값 (컨텍스트 플러그인) | `"$plugin_settings:basic.payment_name"` |
| `$plugin_settings:ID:` | 플러그인 설정 값 (명시적 플러그인 ID) | `"$plugin_settings:sirsoft-payment:basic.payment_name"` |
| `$core_settings:` | 코어 설정 값 | `"$core_settings:general.site_name"` |
| `$query:` | 쿼리 파라미터 | `"$query:q"` |

- `vars` 미선언 시: 치환 없이 원본 템플릿 반환 (빈 문자열)
- 다국어 객체 반환 시: 현재 로케일 값 자동 해석
- 명시적 확장 ID (`$module_settings:MODULE_ID:key`): `moduleIdentifier`가 null인 템플릿 레벨 레이아웃에서도 특정 모듈 설정 참조 가능

### page_type — 확장 설정 키 결정

모듈/플러그인 설정에서 어떤 meta title/description 템플릿을 사용할지 결정합니다.

```json
"seo": {
    "page_type": "product"
}
```

| page_type | 모듈/플러그인 설정 키 |
|-----------|----------------------|
| `product` | `seo.meta_product_title`, `seo.meta_product_description` |
| `category` | `seo.meta_category_title`, `seo.meta_category_description` |
| `search` | `seo.meta_search_title`, `seo.meta_search_description` |
| `checkout` | `seo.meta_checkout_title`, `seo.meta_checkout_description` (플러그인 예시) |

- `page_type` 미선언 시: Tier 2 (확장 설정 템플릿) 스킵, Tier 1 (코어) fallback 사용
- 모듈 레이아웃 → `g7_module_settings()`, 플러그인 레이아웃 → `g7_plugin_settings()` 사용

### toggle_setting — 확장별 SEO 토글

확장(모듈/플러그인) 관리자 설정에서 특정 페이지의 SEO를 비활성화할 수 있습니다.

```json
// 모듈 레이아웃 예시
"seo": {
    "toggle_setting": "$module_settings:seo.seo_product_detail"
}

// 플러그인 레이아웃 예시
"seo": {
    "toggle_setting": "$plugin_settings:seo.enabled"
}
```

- 설정값이 `false` → SEO 렌더링 건너뜀 (null 반환)
- `toggle_setting` 미선언 → 무조건 활성
- `$module_settings:` / `$plugin_settings:` / `$core_settings:` 접두사 지원
- 명시적 모듈 ID도 지원: `"$module_settings:sirsoft-ecommerce:seo.seo_index"` (템플릿 레벨 레이아웃용)

### 레이아웃 예시

```json
{
    "meta": {
        "seo": {
            "enabled": true,
            "data_sources": ["product"],
            "page_type": "product",
            "toggle_setting": "$module_settings:seo.seo_product_detail",
            "vars": {
                "product_name": "{{product.data.name ?? ''}}",
                "commerce_name": "$module_settings:basic_info.shop_name",
                "site_name": "$core_settings:general.site_name"
            },
            "og": { ... },
            "structured_data": { ... }
        }
    }
}
```

## SEO 렌더러 훅 시스템

확장(모듈/플러그인)이 SEO 렌더링 파이프라인에 개입할 수 있는 Filter 훅 3종.
선언적 커스터마이징(`seo-config.json`, `meta.seo`)으로 불가능한 **런타임 데이터 변환**이 필요한 경우에만 사용.

### 훅 목록

| 훅 이름 | 타입 | 필터 대상 | 위치 |
|---------|------|----------|------|
| `core.seo.filter_context` | Filter | 데이터 컨텍스트 전체 | DataSource 해석 후, 메타 해석 전 |
| `core.seo.filter_og_data` | Filter | OG 데이터 배열 (분기별) | resolveOgData + 모듈 declaration 후 |
| `core.seo.filter_twitter_data` | Filter | Twitter 카드 배열 (분기별) | resolveTwitterData + 모듈 declaration 후 |
| `core.seo.filter_structured_data` | Filter | JSON-LD 배열 (분기별) | resolveStructuredDataArray + 모듈 declaration 후 |
| `core.seo.filter_meta` | Filter | 메타 태그 통합 배열 | 모든 분기 결합 후 |
| `core.seo.filter_view_data` | Filter | View 변수 배열 | View::make() 직전 |

**확장 변경 슬롯 선택 가이드**:

- OG 만 손대고 싶다 → `filter_og_data`
- Twitter 카드만 → `filter_twitter_data`
- JSON-LD review 배열 추가 등 → `filter_structured_data`
- 통합 결과를 한 번에 → `filter_meta` (마지막 단계)

### core.seo.filter_context

**위치**: `SeoRenderer.render()` — DataSource + initGlobal + _local 초기화 완료 후, vars 해석 전

```php
$context = HookManager::applyFilters('core.seo.filter_context', $context, [
    'layoutName' => $layoutName,
    'moduleIdentifier' => $moduleIdentifier,
    'pluginIdentifier' => $pluginIdentifier,
    'routeParams' => $routeParams,
    'locale' => $locale,
]);
```

- **필터 대상**: `$context` — 전체 데이터 컨텍스트 (`_global`, `_local`, DataSource 결과, `route`, `query`)
- **추가 인수**: 메타 정보 (읽기 전용 참고용)
- **유즈케이스**: 리뷰 플러그인이 `$context['reviews_aggregate']` 추가, 쿠폰 플러그인이 상품 데이터에 `priceValidUntil` 보강

### core.seo.filter_meta

**위치**: `SeoRenderer.render()` — SeoMetaResolver.resolve() 직후

```php
$meta = HookManager::applyFilters('core.seo.filter_meta', $meta, [
    'layoutName' => $layoutName,
    'moduleIdentifier' => $moduleIdentifier,
    'pluginIdentifier' => $pluginIdentifier,
    'context' => $context,
    'locale' => $locale,
]);
```

- **필터 대상**: `$meta` — `title`, `titleSuffix`, `description`, `keywords`, `og` (배열), `twitter` (배열), `structured_data` (배열|null), `ogTags`/`twitterTags`/`jsonLd` (HTML/JSON 직렬화 결과), `googleAnalyticsId` 등
- **유즈케이스**: SEO 플러그인이 title suffix 변경, 리뷰 플러그인이 JSON-LD에 review 배열 주입
- **재렌더 규칙**: 청취자가 `og`/`twitter`/`structured_data` 배열을 수정하면 SeoRenderer 가 `ogTags`/`twitterTags`/`jsonLd` 를 자동 재렌더 (수정한 키만 보면 됨)

### core.seo.filter_og_data / filter_twitter_data / filter_structured_data

**위치**: `SeoRenderer.render()` — 모듈/플러그인 `seoOgDefaults`/`seoTwitterDefaults`/`seoStructuredData` cascade 후, `filter_meta` 전

```php
$meta['og'] = HookManager::applyFilters('core.seo.filter_og_data', $meta['og'], [
    'layoutName' => $layoutName,
    'moduleIdentifier' => $moduleIdentifier,
    'pluginIdentifier' => $pluginIdentifier,
    'context' => $context,
    'locale' => $locale,
    'pageType' => $pageType,
]);
// 동일 페이로드 + ctx 로 filter_twitter_data, filter_structured_data 호출
```

**`$og` 배열 스키마**:

| 키 | 타입 | 설명 |
|----|-----|-----|
| `type` | string | og:type (product/article/website 등) |
| `title` / `description` | string | 텍스트 |
| `image` | string (절대 URL) | og:image |
| `image_secure_url` | string | og:image:secure_url (HTTPS) |
| `image_width` / `image_height` | int\|null | 픽셀 크기 |
| `image_type` | string | image/jpeg 등 MIME |
| `image_alt` | string | og:image:alt |
| `site_name` | string | og:site_name |
| `locale` | string | og:locale |
| `extra` | array<{property,content}> | 자유 og:* 메타태그 (예: product:price:amount) |

**`$twitter` 배열 스키마**:

| 키 | 타입 | 설명 |
|----|-----|-----|
| `card` | string | summary / summary_large_image / app / player |
| `site` / `creator` | string | @핸들 |
| `title` / `description` / `image` / `image_alt` | string | OG fallback |
| `extra` | array<{name,content}> | 자유 twitter:* |

**`$structured_data`**: Schema.org JSON-LD 배열 (`@type` 필수). 청취자가 자유롭게 키 추가/수정 가능. SeoRenderer 가 `@context: https://schema.org` 자동 prepend.

**유즈케이스**:

```php
HookManager::addFilter('core.seo.filter_og_data', function (array $og, array $ctx) {
    if ($ctx['pageType'] === 'product') {
        $og['extra'][] = ['property' => 'product:availability', 'content' => 'in stock'];
    }
    return $og;
}, 10, 2);
```

### core.seo.filter_view_data

**위치**: `SeoRenderer.render()` — View::make() 직전

```php
$viewData = HookManager::applyFilters('core.seo.filter_view_data', $viewData, [
    'layoutName' => $layoutName,
    'moduleIdentifier' => $moduleIdentifier,
    'pluginIdentifier' => $pluginIdentifier,
]);
```

- **필터 대상**: View 변수 배열 전체 (`locale`, `title`, `bodyHtml`, `extraHeadTags`, `extraBodyEnd` 등)
- **유즈케이스**: Analytics 플러그인이 `extraHeadTags`에 추적 스크립트 삽입, PWA 플러그인이 manifest 링크 주입

### seo.blade.php 확장 슬롯

| 변수명 | 위치 | 용도 |
|--------|------|------|
| `extraHeadTags` | `</head>` 직전 | 커스텀 메타 태그, 스크립트, 스타일 |
| `extraBodyEnd` | `</body>` 직전 | 추적 스크립트, 위젯 |

### 역할 분담

| 커스터마이징 | 방법 |
|------------|------|
| 컴포넌트 HTML 매핑, 렌더 모드, 속성, 스타일 | **seo-config.json** (선언적) |
| 페이지별 title/description/OG/JSON-LD 스키마 | **meta.seo** in 레이아웃 JSON (선언적) |
| Sitemap URL 기여 | **SitemapContributorInterface** (인터페이스) |
| DataSource 결과 보강/컨텍스트 데이터 주입 | **core.seo.filter_context** (훅) |
| 메타 태그 동적 수정 | **core.seo.filter_meta** (훅) |
| View 변수 추가 (스크립트, 스타일 등) | **core.seo.filter_view_data** (훅) |

### 성능 영향

- **캐시 HIT**: 훅 비용 = 0 (렌더링 자체 스킵)
- **캐시 MISS**: 이미 DataSource API 호출(네트워크) + 레이아웃 로드(디스크) 수행 중. `applyFilters()` 수 회 추가는 무시 가능
- **리스너 미등록**: HookManager는 등록된 리스너 없으면 즉시 원본 반환 (오버헤드 ~0)

## 모듈 SEO 기여 패턴

### 도메인 스키마 ownership — seoOgDefaults / seoTwitterDefaults / seoStructuredData

모듈/플러그인은 `AbstractModule` / `AbstractPlugin` 의 다음 메서드를 오버라이드하여 자기 도메인의 OG/Twitter/JSON-LD 를 owned 한다. 레이아웃 JSON 직접 선언은 페이지별 override 가 꼭 필요할 때만 사용 (도메인 스키마는 모듈로 이전 — `seo-domain-schema-in-layout` 정적 검사가 자동 차단).

```php
class EcommerceModule extends AbstractModule
{
    public function seoOgDefaults(string $pageType, array $context, array $routeParams = []): array
    {
        if ($pageType === 'product') {
            $product = data_get($context, 'product.data', []);
            return [
                'type' => 'product',
                'image' => $product['thumbnail_url'] ?? '',
                'image_width' => (int) ($product['thumbnail_width'] ?? 0) ?: null,
                'image_height' => (int) ($product['thumbnail_height'] ?? 0) ?: null,
                'image_alt' => $product['name'] ?? '',
                'extra' => [
                    ['property' => 'product:price:amount', 'content' => (string) $product['selling_price']],
                    ['property' => 'product:price:currency', 'content' => 'KRW'],
                ],
            ];
        }
        return [];
    }

    public function seoStructuredData(string $pageType, array $context, array $routeParams = []): array
    {
        if ($pageType !== 'product') return [];
        $product = data_get($context, 'product.data', []);
        return [
            '@type' => 'Product',
            'name' => $product['name'] ?? '',
            'image' => $product['thumbnail_url'] ?? '',
            'offers' => [
                '@type' => 'Offer',
                'price' => (string) ($product['selling_price'] ?? ''),
                'priceCurrency' => 'KRW',
            ],
        ];
    }
}
```

**캐스케이드 우선순위 (낮음 → 높음)**:

1. 코어 설정 (`seo.og_default_site_name`, `og_image_default_width` 등)
2. 모듈/플러그인 declaration (`seoOgDefaults` / `seoTwitterDefaults` / `seoStructuredData`)
3. 레이아웃 `meta.seo.og` / `meta.seo.twitter` / `meta.seo.structured_data` (페이지별 override)
4. `core.seo.filter_og_data` / `filter_twitter_data` / `filter_structured_data` 훅 (런타임)
5. `core.seo.filter_meta` 훅 (통합 최종)

**레이아웃 활성화 조건**: 모듈 declaration 이 호출되려면 레이아웃 `meta.seo.extensions` 에 `[{ "type": "module", "id": "sirsoft-ecommerce" }]` 선언 + `meta.seo.page_type` 명시 필요.

### SitemapContributorInterface 구현 (지연 스트리밍 권장)

대용량 도메인은 `AbstractSitemapContributor` 를 상속해 `getUrlsLazy()`(제너레이터)를 구현합니다. base 가 `getUrls()↔getUrlsLazy()` 를 양방향 브리지하므로 둘 중 하나만 구현하면 됩니다. 인터페이스 자체는 변경되지 않아 base 를 상속하지 않은 제3자 raw 구현체(`implements SitemapContributorInterface` + `getUrls()`)도 그대로 동작합니다.

```php
// modules/_bundled/[module]/src/Seo/[Module]SitemapContributor.php
class EcommerceSitemapContributor extends AbstractSitemapContributor
{
    public function getIdentifier(): string { return 'sirsoft-ecommerce'; }

    // 한 건씩 yield — 1.4M 배열을 메모리에 만들지 않음. 쿼리는 Repository 의
    // stream*ForSitemap() 위임(lazyById, service-direct-data-access 규율).
    // 리소스 단위 증분(SitemapIndexer) 매칭을 위해 resource_type/resource_id 를 함께 emit.
    public function getUrlsLazy(): iterable
    {
        foreach ($this->products->streamVisibleForSitemap() as $p) {
            yield ['loc' => url("/shop/{$p->id}"), 'lastmod' => $p->updated_at?->toW3cString(),
                   'resource_type' => 'product', 'resource_id' => (string) $p->id];
        }
    }
}
```

`SitemapGenerator::drain()` 이 `instanceof AbstractSitemapContributor || method_exists($c, 'getUrlsLazy')` 로 capability 를 감지해 지연 경로를 우선합니다.

#### changefreq 는 `App\Enums\SitemapChangeFreq` (폐쇄 어휘)

entry 의 `changefreq` 는 sitemaps.org 의 폐쇄 어휘(`always`/`hourly`/`daily`/`weekly`/`monthly`/`yearly`/`never`)로, `App\Enums\SitemapChangeFreq` 가 SSoT 입니다. 기여자·리스너·정적 URL 은 리터럴 문자열 대신 `SitemapChangeFreq::Weekly->value` 처럼 case 값을 사용해 오타를 작성 시점에 잡습니다. 규격에 없는 값은 저장 경계(`SitemapIndexer`)와 렌더 경계(`SitemapXmlRenderer`)에서 `SitemapChangeFreq::normalize()` 로 정규화되어 `null` 로 떨어지므로 사이트맵 XML 에 비표준 값이 출력되지 않습니다(대소문자·앞뒤 공백은 흡수). 제3자 raw 구현체가 임의 문자열을 emit 해도 이 두 경계에서 안전하게 걸러집니다.

### ServiceProvider 등록

```php
// ServiceProvider::boot()
if (app()->bound(\App\Seo\SitemapGenerator::class)) {
    app(\App\Seo\SitemapGenerator::class)
        ->registerContributor(new EcommerceSitemapContributor());
}
```

### SEO 캐시 무효화 리스너

#### 콘텐츠 변경 리스너 (모듈별)

| 훅 | 무효화 대상 |
|----|-----------|
| `[module].product.after_create/update/delete` | 해당 URL + 목록/카테고리 |
| `[module].post.after_create/update/delete` | 게시글 + 게시판 |
| `[module].page.after_create/update/delete` | 페이지 + 홈 |

#### 확장 라이프사이클 리스너 (코어)

| 훅 | 무효화 대상 | 사유 |
|----|-----------|------|
| `core.modules.after_install/activate/update` | 전체 SEO 캐시 + sitemap | 레이아웃 등록/변경 |
| `core.plugins.after_install/activate/update` | 전체 SEO 캐시 + sitemap | layout_extensions 변경 |
| `core.templates.after_install/activate/version_update` | 전체 SEO 캐시 + sitemap + SEO config 병합 캐시 | seo-config.json/컴포넌트 맵 변경 |

> 확장 라이프사이클은 드문 이벤트이므로 전체 캐시 클리어(`clearAll()`)로 안전하게 처리합니다.

캐시 무효화 시 `app(CacheInterface::class)->forget('seo.sitemap')` + `SeoConfigMerger::clearCache()` 도 함께 호출 (드라이버가 `g7:core:` 접두사 자동 적용)

## Artisan 커맨드

```bash
php artisan seo:warmup              # SEO 캐시 워밍업
php artisan seo:warmup --layout=shop/show  # 특정 레이아웃만
php artisan seo:clear               # 전체 SEO 캐시 삭제
php artisan seo:clear --layout=home # 특정 레이아웃만
php artisan seo:stats               # 캐시 통계 출력
php artisan seo:generate-sitemap    # Sitemap 생성 (큐 디스패치, mode=auto)
php artisan seo:generate-sitemap --sync  # Sitemap 동기 생성
php artisan seo:generate-sitemap --rebuild            # 전체 재생성 (mode=full 상당)
php artisan seo:generate-sitemap --mode=full|auto|incremental  # 재생성 모드 지정
```

- `--mode=full`: 도메인 전량을 다시 읽어 `sitemap_urls` 를 전면 replace 후 파일 재작성(자식 균등 재분배).
- `--mode=incremental`: 도메인 재쿼리 없이 `sitemap_urls` 스트림만으로 파일 재작성(리스너가 반영한 델타 그대로).
- `--mode=auto`(기본): 저장소가 비어 있으면 full, 채워져 있으면 incremental.
- `--rebuild` 은 `--mode=full` 의 별칭입니다.

## Sitemap 분할 생성과 서빙

Sitemap 은 비공개 디스크(StorageInterface, `cache` 카테고리)에 분할 파일로 커밋되고, 컨트롤러가 스트리밍으로 서빙합니다. `public/storage` 심볼릭 링크에 의존하지 않으며 자식 파일을 메모리에 적재하지 않습니다.

**디스크 레이아웃** (SSoT: `SitemapFileStore` 상수):

```text
sitemap/manifest.json      커밋 마커 + 자식 목록 메타 (이 파일 존재 = 서빙 가능)
sitemap/sitemap.xml        sitemapindex (항상 비압축)
sitemap/sitemap-{n}.xml    자식 sitemap (gzip 시 .xml.gz)
sitemap/_tmp/              생성 중 임시 디렉토리 (커밋 시 정리)
```

**라우트**: `/sitemap.xml`(인덱스) · `/sitemap-{n}.xml`, `/sitemap-{n}.xml.gz`(자식). 자식 경로는 `SitemapFileStore::childUrl()` 이 인덱스 `<loc>` 에 기록하는 값과 일치해야 합니다. gzip 여부는 manifest 가 결정하므로 두 경로를 같은 액션이 처리합니다.

**서빙 시맨틱** (요청 스레드에서 생성하지 않음):

| 상태 | 동작 |
|------|------|
| 메타 캐시 신선 + 세트 존재 | 디스크 세트 스트리밍 |
| 메타 캐시 만료 | `GenerateSitemapJob` 디스패치 후 기존 세트 서빙 (`sitemap_serve_stale_on_miss=true`) |
| 메타 캐시 만료 + stale 서빙 off | 잡 디스패치 후 503 + `Retry-After: 120` |
| 세트 전무 (신규 설치) | 잡 디스패치 후 503 + `Retry-After: 120` |

봇 요청 스레드에서 동기 생성하면 대용량(수백만 URL)에서 메모리 초과·타임아웃이 발생하므로 생성은 항상 큐가 담당합니다. 잡은 유니크 락(`seo-sitemap`)을 쓰므로 캐시 미스가 몰려도 동시에 여러 건이 실행되지 않습니다.

**전용 큐(`GenerateSitemapJob::QUEUE = 'sitemap'`)**: 사이트맵 생성 잡은 `sitemap` 전용 큐로 라우팅됩니다. 배포 시 이 큐 워커를 별도로 띄우되 **워커를 1개만** 배치해야 합니다(`php artisan queue:work --queue=sitemap` — supervisor 프로그램을 기본 큐와 분리해 등록). 이유: ①긴 생성 작업이 기본(`default`) 큐의 방송·알림을 막지 않아 진행상황이 실시간으로 흐르고, ②워커가 1개뿐이라 잡이 동시에 두 번 실행되지 않습니다(큐 `retry_after` 가 잡 실행보다 짧아도 단일 워커라 재예약 중복 실행이 발생하지 않음). `sitemap` 큐 워커가 없으면 재생성 잡이 처리되지 않으니 주의합니다.

**완료/실패 알림**: 관리자 수동 재생성(위 API)은 실행한 관리자 ID 를 잡에 실어, 완료 시 `sitemap_regenerated`, **최종 실패(재시도 소진, `Job::failed()`) 시** `sitemap_regenerate_failed` 알림을 그 관리자에게 발송합니다(기본 채널: 앱 내 알림). 매 시도 실패마다 발화하는 `core.seo.sitemap.after_regenerate_failed` 훅이 아니라 최종 실패 시 1회만 발화하는 `core.seo.sitemap.regenerate_failed_final` 훅에 알림이 연결되어, 재시도 중 실패가 반복되거나 결국 성공해도 실패 알림이 중복/오발송되지 않습니다. 스케줄러·증분·봇 재생성은 실행 관리자가 없어 알림을 보내지 않습니다.

**분할 기준**: `sitemap_urls_per_file`(기본 50000) 또는 파일 크기 임계(`SitemapWriter::MAX_FILE_BYTES`, 45MB) 중 먼저 도달하는 쪽. 둘 다 sitemaps.org 프로토콜 제한(50,000 URL / 50MB)을 지키기 위한 것입니다.

**관리자 수동 재생성**: `POST /api/admin/seo/sitemap/regenerate` 는 큐에 `mode=full` 로 예약만 하고 즉시 진행상황(`getStatus()`)을 응답합니다. 완료 여부는 진행상황(아래) 또는 `sitemap_last_updated_at` 갱신으로 확인합니다.

## 증분 저장소 (sitemap_urls)

리소스 단위 공개/비공개/삭제를 사이트맵에 반영하기 위해 URL 을 `sitemap_urls` 테이블에 지속합니다(주안점 1·3). 전체 재생성 없이 바뀐 리소스 행만 upsert/remove 합니다.

- **테이블**: `resource_type`/`resource_id`/`loc`/`loc_hash`(=`sha256(loc)`)/`lastmod`/`changefreq`/`priority`/`contributor`/`is_visible`. unique 는 `(resource_type, resource_id, loc_hash)` — `loc string(2048)` utf8mb4 는 InnoDB 키 길이(3072 byte)를 초과하므로 ascii 64 해시로 identity 를 유지합니다(상세: docs/database-guide.md).
- **Repository**: `SitemapUrlRepositoryInterface`(`upsertForResource`=delete-후-insert 멱등 / `removeForResource` / `streamVisible`(lazyById) / `countVisible` / `replaceAllForContributor`). `SeoServiceProvider` 가 singleton 바인딩.
- **인덱서**: `SitemapIndexer::indexResource($type,$model,$entries)` / `deindexResource($type,$model)` — 리스너가 직접 모델/DB 를 만지지 않고 이 서비스를 경유합니다(`service-direct-data-access` 규율). 제3자 확장은 filter 훅 `sitemap.index.collect_for_resource` 로 리소스→entries 를 가공/추가할 수 있습니다(docs/extension/hooks.md).
- **리스너**: 모듈 SEO 리스너가 `after_create/update/delete` 에서 공개상태를 판정해 index/deindex 하고 `GenerateSitemapJob::dispatch()`(유니크 락 디바운스)를 겁니다. 리소스 단위 증분에서 자식 파일 URL 수가 임계 미만으로 줄어드는 것(5000→4999)은 감안하며, 균등 재분배(리밸런싱)는 full 재생성 때만 합니다.

## 재생성 모드 (SitemapGenerationMode)

`App\Enums\SitemapGenerationMode`(Backed Enum: `full`/`auto`/`incremental`)가 재생성 정책을 캡슐화합니다. `resolve(int $visibleCount)` 가 `auto` 를 저장소 상태로 full/incremental 판정합니다.

| 트리거 | 모드 | 동작 |
|--------|------|------|
| 관리자 수동 재생성 | 항상 `full` | 도메인 전량 → 테이블 replace → 파일 전량 재작성 |
| 스케줄러 | `auto` | 테이블 비었으면 full, 아니면 incremental |
| 리소스 변경(리스너) | 해당 리소스만 | 행 upsert/remove 후 잡 디스패치 |

`Command → GenerateSitemapJob → SitemapManager::regenerate(SitemapGenerationMode)` 전 경로가 enum 시그니처를 공유합니다(잡 직렬화는 enum 프로퍼티 그대로).

## 진행상황 가시화 (Reverb 실시간 / OFF 폴링)

`SitemapProgress`(캐시 키 `seo.sitemap.progress`, TTL 3600)가 phase 기반 진행상황을 기록합니다: `queued → running(기여자별 phase + 누적 URL) → writing → completed/failed`. 사전 count 쿼리 없이 스트림 누적치를 표기합니다(1.4M 에서 count 자체가 부담).

- **상태 API**: `GET /api/admin/seo/sitemap/status`(`core.settings.read`)가 `{last_updated_at, progress, realtime_enabled}` 를 반환합니다. `realtime_enabled = drivers.websocket_enabled 설정 && config('broadcasting.default') !== 'null'`(설정 SSoT + 실제 적용 config 양쪽 확인).
- **방송**: `SitemapProgress` 가 `core.admin.seo.sitemap` 채널로 `sitemap.progress.updated` 를 방송합니다(payload 는 상태 API `data` 와 동형). Reverb OFF 면 `HookManager::broadcast` 가 자동 skip 하고 캐시만 기록되어 폴링 폴백이 성립합니다. 방송은 N URL(5000) 간격으로 스로틀합니다.
- **채널 인증**: `routes/channels.php` 에 `core.admin.seo.sitemap` → `core.settings.read`(docs/backend/broadcasting.md).
- **잡 실패**: `GenerateSitemapJob::failed()` 가 `SitemapProgress::fail()` 을 호출해 무한 running 을 방지합니다(TTL 만료 시 idle 복귀).
- **프론트**: SEO 탭이 상태 API 초기 로드 후 `realtime_enabled` 로 분기합니다 — true 면 websocket 데이터소스(`target_source`)로 갱신, false 면 `startInterval`/`stopInterval` 로 3초 폴링(완료/실패 전이 시 중단).

## 설정값 (코어 seo.*)

| 키 | 타입 | 기본값 | 설명 |
|----|------|-------|------|
| bot_user_agents | array | [...] | 봇 UA 패턴 목록 |
| bot_detection_enabled | boolean | true | 봇 감지 ON/OFF |
| cache_enabled | boolean | true | SEO 캐시 ON/OFF |
| cache_ttl | integer | 7200 | 캐시 TTL (초) |
| sitemap_enabled | boolean | true | sitemap.xml 생성 ON/OFF |
| sitemap_cache_ttl | integer\|null | null | Sitemap 캐시 TTL 오버라이드 (초). null=고급 탭 `cache.seo_sitemap_ttl` 을 따름 |
| sitemap_urls_per_file | integer | 50000 | 자식 파일당 URL 수 (분할 기준). 1000~50000, 상한은 sitemaps.org 프로토콜 제한 |
| sitemap_gzip | boolean | false | 자식 파일 gzip 압축. 인덱스 파일은 항상 비압축 |
| sitemap_serve_stale_on_miss | boolean | true | 신선도 만료 시 기존 세트를 그대로 서빙(true) / 503 반환(false) |
| sitemap_max_urls_per_contributor | integer | 0 | 수집기당 URL 상한 (0=무제한). 초과 시 경고 로그 + truncate |
| sitemap_hreflang_enabled | boolean | true | 다국어 alternate(hreflang) 링크 출력 ON/OFF. 로케일 수가 `SitemapXmlRenderer::MAX_HREFLANG`(50) 초과 시 alternate 생략 |
| sitemap_schedule | string | "daily" | 생성 주기 (hourly/daily/weekly) |
| sitemap_schedule_time | string | "02:00" | 생성 시각 |
| og_default_site_name | string | "" | og:site_name 기본값. 비면 `general.site_name` fallback |
| og_image_default_width | integer | 1200 | og:image:width 기본값 (픽셀) |
| og_image_default_height | integer | 630 | og:image:height 기본값 (픽셀) |
| twitter_default_card | string | "summary_large_image" | twitter:card 기본 (summary/summary_large_image/app/player) |
| twitter_default_site | string | "" | twitter:site 핸들 (예: @gnuboard). 비면 출력 생략 |

## SEO Config 동적 확장 시스템

SEO 엔진은 컴포넌트 지식을 갖지 않습니다. 모든 컴포넌트→HTML 매핑, 렌더 모드, 셀프 클로징 태그, 외부 스타일시트는 `seo-config.json`으로 제공됩니다.

### 다중 소스 병합 (`SeoConfigMerger`)

`SeoConfigMerger`가 활성 모듈/플러그인/템플릿의 `seo-config.json`을 수집·병합합니다.

**파일 위치**:
- 모듈: `modules/{identifier}/resources/seo-config.json`
- 플러그인: `plugins/{identifier}/resources/seo-config.json`
- 템플릿: `templates/{identifier}/seo-config.json`

**우선순위** (나중이 우선): 모듈 → 플러그인 → 템플릿

동일 우선순위 내에서는 식별자 알파벳순 정렬 (결정론적 병합).

### 병합 전략

| 키 | 병합 방식 | 설명 |
|----|----------|------|
| `component_map` | deep merge (키 단위, 후순위 우선) | 모듈이 추가, 템플릿이 오버라이드 |
| `render_modes` | deep merge (키 단위, 후순위 우선) | 동일 |
| `attr_map` | shallow merge (후순위 우선) | 충돌 드묾 |
| `text_props` | array union (중복 제거) | 누적 |
| `allowed_attrs` | array union (중복 제거) | 누적 |
| `self_closing` | array union (중복 제거) | 누적 |
| `stylesheets` | array append (중복 제거) | 순서 유지 |
| `seo_overrides` | shallow merge (후순위 우선) | - |

### 캐싱

- 병합 결과는 24시간 TTL로 캐시 (`seo:config:merged:{templateIdentifier}`)
- 확장 설치/활성화/업데이트 시 `SeoExtensionCacheListener`가 자동 무효화

### 모듈/플러그인 seo-config.json 예시

```json
{
    "component_map": {
        "CustomWidget": {
            "tag": "section",
            "render": "iterate"
        }
    },
    "render_modes": {
        "custom_view": {
            "fields": ["title", "description"]
        }
    }
}
```

모듈/플러그인의 config는 프래그먼트입니다. 모든 키가 선택적이며, 필요한 항목만 선언합니다.

## 템플릿 seo-config.json (컴포넌트→HTML 매핑)

**위치**: `templates/{identifier}/seo-config.json`

템플릿의 `seo-config.json`은 최종 우선순위로 병합됩니다. 모듈/플러그인이 추가한 매핑을 오버라이드할 수 있습니다.

### 스키마

| 필드 | 타입 | 설명 |
|------|------|------|
| `text_props` | string[] | 텍스트 추출 우선순위 (예: `["text", "label", "value", "title"]`) |
| `attr_map` | object | props→HTML 속성 매핑 (예: `{"className": "class", "htmlFor": "for"}`) |
| `allowed_attrs` | string[] | 허용 HTML 속성 목록 (목록에 없는 속성은 출력 안됨) |
| `stylesheets` | string[] | 외부 CSS URL (예: Font Awesome CDN) |
| `self_closing` | string[] | 셀프 클로징 태그 목록 (예: `["img", "input", "hr", "br"]`) |
| `component_map` | object | 컴포넌트명 → HTML 태그 매핑 |
| `render_modes` | object | 렌더 모드 정의 |
| `seo_overrides` | object | SEO 렌더링 시 `_local`/`_global` 상태 오버라이드 (접혀있는 콘텐츠 강제 펼침 등) |

### component_map 엔트리

| 키 | 타입 | 설명 |
|----|------|------|
| `tag` | string (필수) | HTML 태그명. 빈 문자열(`""`)이면 Fragment (래퍼 없이 children만 렌더링) |
| `skip` | boolean | `true`이면 렌더링 생략 |
| `render` | string | `render_modes`에 정의된 모드명 참조 |
| `props_source` | string | 렌더 모드에서 데이터를 가져올 props 키 |
| `format` | string | format 모드에서 사용할 포맷 문자열 |
| `defaults` | object | format 모드에서 사용할 기본값 |

`text_props`/`attr_map`/`allowed_attrs` 미선언 시 엔진 내장 기본값(범용 HTML/React 매핑)이 사용됩니다. 빈 배열로 **명시**하면 해당 기능이 비활성화됩니다.

config에 없는 컴포넌트는 `<div>` fallback으로 렌더링됩니다.

### ExpressionEvaluator — 가상 프로퍼티 해석

표현식 경로에서 PHP 배열/문자열에 존재하지 않는 JavaScript 가상 프로퍼티(예: `.length`)를 타입 기반으로 동적 해석합니다.

| 부모 타입 | 프로퍼티 | 해석 |
|-----------|----------|------|
| array | `length` | `count($array)` |
| string | `length` | `mb_strlen($string)` |

새 프로퍼티 추가 시 `resolveVirtualProperty()` 메서드의 `match` 문에 케이스를 추가합니다.

### ExpressionEvaluator — JavaScript 메서드 호출 평가

`{{expr.method(args)}}` 형태의 JavaScript 메서드 호출을 PHP로 평가합니다. 메서드 체이닝(`expr.method1().method2()`)과 메서드 결과에 대한 프로퍼티 접근(`expr.method().length`)도 지원합니다.

#### 정적 메서드

| 클래스 | 메서드 | 설명 |
|--------|--------|------|
| `Object` | `keys`, `values`, `entries`, `assign` | 객체 키/값/엔트리/병합 |
| `Math` | `min`, `max`, `floor`, `ceil`, `round`, `abs`, `random` | 수학 함수 |
| `Array` | `isArray`, `from` | 배열 판별/생성 |
| `JSON` | `stringify`, `parse` | JSON 직렬화/역직렬화 |
| `Number` | `isNaN`, `isFinite`, `parseInt`, `parseFloat` | 숫자 판별/변환 |

#### 전역 함수

`Number()`, `String()`, `Boolean()`, `parseInt()`, `parseFloat()`, `isNaN()`, `isFinite()`, `encodeURIComponent()`, `decodeURIComponent()`

#### 배열 인스턴스 메서드

| 메서드 | 콜백 | 설명 |
|--------|------|------|
| `join(sep)` | - | 구분자로 결합 |
| `slice(start, end)` | - | 부분 배열 (끝 인덱스 기반) |
| `includes(val)` | - | 포함 여부 |
| `indexOf(val)` | - | 인덱스 검색 |
| `flat(depth)` | - | 중첩 배열 평탄화 |
| `reverse()` | - | 역순 |
| `concat(arr)` | - | 배열 병합 |
| `at(idx)` | - | 음수 인덱스 지원 접근 |
| `map(cb)` | ✓ | 변환 |
| `filter(cb)` | ✓ | 조건 필터 |
| `find(cb)` | ✓ | 첫 매칭 요소 |
| `findIndex(cb)` | ✓ | 첫 매칭 인덱스 |
| `some(cb)` | ✓ | 하나라도 충족 |
| `every(cb)` | ✓ | 모두 충족 |
| `flatMap(cb)` | ✓ | map + flat |
| `reduce(cb, init)` | ✓ | 누적 |
| `sort(cb?)` | 선택 | 정렬 |

콜백 지원 형태: `item => item.prop`, `(item, idx) => body`

#### 문자열 인스턴스 메서드

`split`, `trim`, `trimStart/End`, `toLowerCase`, `toUpperCase`, `substring`, `substr`, `slice`, `includes`, `indexOf`, `lastIndexOf`, `startsWith`, `endsWith`, `replace`, `replaceAll`, `repeat`, `padStart/End`, `charAt`, `charCodeAt`, `at`, `concat`, `toString`

#### 숫자 인스턴스 메서드

| 메서드 | 설명 |
|--------|------|
| `toLocaleString()` | 천 단위 구분 (예: `64000` → `64,000`) |
| `toFixed(digits)` | 소수점 고정 (예: `3.14159` → `3.14`) |
| `toString(base)` | 진수 변환 (예: `255.toString(16)` → `ff`) |

### ExpressionEvaluator — 산술 연산

`{{expr + N}}`, `{{expr - N}}` 형태의 정수 산술 연산을 지원합니다. 페이지네이션 링크 생성 등에 사용됩니다.

| 표현식 | 결과 | 설명 |
|--------|------|------|
| `{{query.page + 1}}` | `3` (page=2) | 다음 페이지 계산 |
| `{{query.page - 1}}` | `1` (page=2) | 이전 페이지 계산 |
| `{{query.page ?? 1 + 1}}` | `2` (page 없음) | null coalescing + 산술 |

- 우측 피연산자: 정수 리터럴만 지원 (`+ 1`, `- 10`)
- 좌측 결과가 숫자가 아니면(빈 문자열 등): 산술 없이 원본 반환
- 소수점 지원: `{{price + 0.5}}`

### ExpressionEvaluator — evaluateRaw `??` null coalescing

`evaluateRaw()`는 표현식 결과를 원본 타입(배열/객체)으로 반환합니다. 단일 `{{expr ?? fallback}}` 패턴에서 `??` 연산자를 감지하여 null coalescing을 수행합니다.

| 표현식 | 좌측 값 | 결과 |
|--------|--------|------|
| `{{boards ?? []}}` | `[{name: "자유"}]` | `[{name: "자유"}]` (배열 타입 유지) |
| `{{boards ?? []}}` | `null` / 미존재 | `[]` (fallback) |
| `{{settings.site_name ?? 'G7'}}` | `"My Site"` | `"My Site"` |

- 좌측이 `null`/빈 문자열이면 우측 fallback 반환
- 좌측이 배열/객체면 원본 타입 유지 (문자열 변환 없음)
- 복합 표현식 (`&&`, `||` 포함) 시에는 일반 `evaluate()` 위임

### ExpressionEvaluator — `||` / `&&` 논리 연산자

일반 화면과 동일하게 **값을 반환**합니다. `true` / `false` 라는 글자로 바뀌지 않습니다.

| 표현식 | 좌측 값 | 결과 |
|--------|--------|------|
| `{{query.q \|\| ''}}` | `"검색어"` | `"검색어"` |
| `{{query.q \|\| ''}}` | 빈 값 | `""` |
| `{{a \|\| b}}` | `a` 가 빈 값 | `b` 의 값 |
| `{{a && b}}` | `a` 가 빈 값 | `a` 의 값 |
| `{{a && b}}` | `a` 가 값 있음 | `b` 의 값 |

- 빈 값 판정: `''`, `'false'`, `'0'`
- 이 규칙은 데이터소스 엔드포인트 보간(`?q={{query?.q \|\| ''}}`)에서도 그대로 적용됩니다. 값 대신 `true` 가 들어가면 봇이 엉뚱한 목록을 받게 됩니다.

### ExpressionEvaluator — 삼항 연산자

`condition ? trueExpr : falseExpr` 형태의 삼항 연산자를 지원합니다. JS 우선순위에 맞게 `||`/`&&`보다 먼저 분리됩니다.

| 표현식 | 결과 | 설명 |
|--------|------|------|
| `{{status === 'active' ? '활성' : '비활성'}}` | `활성` | 비교 조건 |
| `{{count > 99 ? '99+' : count}}` | `99+` | 숫자 비교 |
| `{{a ? b : c ? d : e}}` | 우측 결합 | `a ? b : (c ? d : e)` |
| `{{user?.name ? user.name : '비회원'}}` | `?.` 구분 | optional chaining과 구분 |

- `?.` (optional chaining)와 `?` (삼항) 자동 구분
- 중첩 삼항은 우측 결합(right-associative)
- `evaluateRaw()`에서도 원본 타입 유지 지원

### ExpressionEvaluator — $t() / $localized() 전역 함수

| 함수 | 설명 | 예시 |
|------|------|------|
| `$t('key')` | 번역 키 해석 (`$t:key`와 동일) | `{{$t('shop.product.sold_out')}}` |
| `$localized(expr)` | 다국어 객체 → 현재 로케일 값 | `{{$localized(product.name)}}` |

- `$t()`: 기존 `$t:key` prefix 방식의 함수 호출 구문. 삼항 내부에서 사용 가능
- `$localized()`: `{ko: "상품", en: "Product"}` → 현재 로케일(`ko`) → `"상품"`. fallback: ko → 첫 번째 값

### ExpressionEvaluator — 객체 리터럴 / 스프레드 연산자

객체 리터럴 `{key: value}` 및 스프레드 연산자 `{...obj}` / `[...arr]`를 지원합니다.

**객체 리터럴**:

| 표현식 | 결과 | 설명 |
|--------|------|------|
| `{status: 'active', count: 3}` | `['status' => 'active', 'count' => 3]` | 기본 |
| `{...defaults, size: 'sm'}` | 스프레드 + 오버라이드 | 객체 병합 |
| `{[item.id]: 'value'}` | 동적 키 | computed key |

**배열 스프레드**:

| 표현식 | 결과 | 설명 |
|--------|------|------|
| `[...items, 'new']` | 기존 배열 + 새 요소 | 배열 확장 |
| `[...arr1, ...arr2]` | 두 배열 병합 | 다중 스프레드 |

### SeoRenderer — computed 속성

레이아웃 JSON의 `computed` 섹션을 평가하여 `_computed` / `$computed`에 저장합니다.

**문자열 표현식**:
```json
{
  "computed": {
    "totalPrice": "{{product.data.price * 2}}",
    "label": "static text"
  }
}
```

**$switch 형식**:
```json
{
  "computed": {
    "badgeClass": {
      "$switch": "{{product.data.status}}",
      "$cases": {
        "active": "bg-green-100 text-green-800",
        "sold_out": "bg-red-100 text-red-800"
      },
      "$default": "bg-gray-100 text-gray-600"
    }
  }
}
```

- 순차 평가: 후속 computed에서 `_computed.xxx`로 이전 결과 참조 가능
- `_computed`와 `$computed`는 동일 (별칭)
- 평가 실패 시 null 설정 후 렌더링 계속

### ComponentHtmlMapper — classMap (조건부 CSS)

`classMap` 속성으로 조건부 CSS 클래스를 선언적으로 적용합니다.

```json
{
  "classMap": {
    "base": "px-2 py-1 rounded-full text-xs",
    "variants": {
      "active": "bg-green-100 text-green-800",
      "inactive": "bg-gray-100 text-gray-600"
    },
    "key": "{{product.status}}",
    "default": "bg-gray-100"
  }
}
```

- `base`: 항상 적용되는 기본 클래스
- `variants`: key 값에 따라 선택되는 클래스
- `key`: 평가할 표현식
- `default`: 매칭 없을 때 기본 클래스
- 기존 `className`과 병합 가능

### ComponentHtmlMapper — Extension Point props 해석

레이아웃의 `extension_point` 노드를 확장이 교체(또는 추가)하면, 호스트가 선언한 `props` 가 주입 컴포넌트의 최상위 키 `extensionPointProps` 로 전달됩니다. 주입 컴포넌트는 `{{extensionPointProps.content}}` 형태로 그 값을 참조합니다.

SEO 렌더링은 `renderComponent()` 진입 시점에 이 값을 해석해 데이터 컨텍스트의 `extensionPointProps` 에 넣습니다.

- 해석 시점: `responsive` 병합·`if` 판정·`iteration` 전개보다 **먼저** — 조건식과 반복 소스도 이 값을 참조할 수 있어야 하기 때문입니다.
- 적용 범위: 해당 노드와 **자손 전체**. 형제 노드에는 전달되지 않습니다.
- 반복 내부: 반복 항목마다 재해석하지 않고 바깥 컨텍스트 기준으로 한 번만 해석한 뒤 상속합니다.
- 값 해석: 문자열 표현식은 원본 타입을 유지한 채 해석됩니다(불리언·숫자·배열 보존). 중첩 객체는 재귀 해석합니다.

`extensionPointCallbacks` 는 **의도적으로 해석하지 않습니다**. SEO 파이프라인에는 액션 실행기가 없고, 액션 정의 배열을 컨텍스트에 넣으면 HTML 에 직렬화 파편이 노출될 수 있습니다. 봇에게 보여야 할 내용을 콜백 경유로 만들지 마세요.

미해석 시 증상: 확장이 교체한 본문 영역이 내용 없는 빈 요소(`<div></div>`)로 출력되고, 메타 태그에는 본문이 들어가 **메타와 화면 본문이 어긋납니다**.

호스트가 넘기는 판정 값(예: `isHtml`)은 비교식으로 쓰이는 경우가 많습니다(`{{(post.data?.content_mode ?? 'text') === 'html'}}`). 비교·논리 연산의 결과는 참/거짓 값으로 해석되므로 주입 컴포넌트의 `{{extensionPointProps.isHtml ?? true}}` 같은 기본값 폴백이 의도대로 동작합니다.

### 봇 화면의 HTML 정화

사용자가 작성한 본문(게시글·답변글·페이지·상품 설명)은 **일반 화면과 같은 강도로 정화한 뒤** 봇 화면에 넣습니다. 정화를 생략하면 봇 화면에서만 저장된 스크립트가 살아남아, 주소에 봇 파라미터를 붙이는 것만으로 실행됩니다.

판정 규칙은 일반 화면의 `HtmlContent` 컴포지트와 같습니다.

| `isHtml` | 봇 화면 출력 | 일반 화면 |
|---|---|---|
| `false` | 전체 이스케이프 — 태그가 글자로 보입니다 | 동일 (평문 렌더링) |
| `true` 또는 미지정 | 위험 요소를 제거한 뒤 HTML 로 출력 | 동일 (DOMPurify) |

정화 규칙 (`app/Seo/HtmlSanitizer.php`)

- 스크립트 실행·외부 콘텐츠 삽입·문서 구조 조작 태그는 제거합니다. 본문 텍스트를 품을 수 있는 태그는 요소만 벗기고 글자는 남깁니다.
- `on*` 이벤트 핸들러 속성은 전부 제거합니다(목록에 없는 신규 이벤트 포함).
- `href`/`src` 등 URL 속성은 허용 스킴만 남깁니다. `javascript:` 는 제어문자를 끼워 넣은 우회 형태까지 차단하고, `data:` 는 이미지 형식만 허용합니다.
- 외부 링크에는 `rel="noopener noreferrer"` 를 보강합니다.
- 파싱에 실패하면 정화되지 않은 HTML 을 내보내지 않고 전체를 이스케이프합니다.

차단 목록의 기준은 일반 화면 컴포넌트의 DOMPurify 설정입니다(`templates/_bundled/sirsoft-basic/src/components/composite/HtmlContent.tsx`). **한쪽만 바꾸면 두 화면의 정화 강도가 어긋나므로 함께 갱신**하세요. 계약은 `tests/Unit/Seo/HtmlSanitizerTest.php` 와 `tests/Unit/Seo/ExtensionPointPropsRenderingTest.php` 가 잠급니다.

`purifyConfig` prop(일반 화면의 DOMPurify 설정 오버라이드)은 봇 화면에서 해석하지 않습니다 — 기본 정화 규칙만 적용됩니다.

### 데이터소스 화이트리스트

봇 렌더링은 `meta.seo.data_sources` 에 적힌 id 만 미리 조회합니다. **화면이 쓰는 데이터소스는 빠짐없이 선언**하세요. 빠지면 그 값은 늘 비어 있고, 그 값을 조건으로 삼는 블록이 통째로 사라져 머리말과 꼬리말만 있는 화면이 색인됩니다.

봇에게 의미가 없어 일부러 제외하는 경우(브라우저 저장값이 필요한 목록, 로그인 사용자 전용 데이터 등)는 `tests/Unit/Seo/SeoLayoutDataSourceDeclarationTest.php` 의 면제 목록에 사유와 함께 등록합니다. 그 테스트가 번들 템플릿 레이아웃을 전수 순회해 미선언 참조를 차단합니다.

### SEO 렌더러 지원 노드 키 (SSoT)

일반 화면(React)과 봇 화면(PHP)은 같은 레이아웃 JSON 을 각각 렌더합니다. 한쪽에만 기능을 추가하면 봇 화면에서 그 부분이 조용히 사라지므로, 지원 범위를 아래 표로 고정합니다. 이 표가 두 렌더러 지원 범위의 단일 기준(SSoT)입니다.

"봇 화면 처리 위치" 는 파일 경로 + 메서드로 적습니다. 줄 번호는 리팩터링마다 어긋나 오히려 잘못된 근거가 되므로 넣지 않습니다 — 메서드명으로 찾으세요.

| 노드 키 | 일반 화면 | 봇 화면 | 봇 화면 처리 위치 | 비고 |
|---------|----------|--------|------------------|------|
| `name` / `type` / `props` / `children` | ✅ | ✅ | `app/Seo/ComponentHtmlMapper.php::renderComponent` / `renderTag` / `renderChildren` | |
| `text` | ✅ | ✅ | `app/Seo/ComponentHtmlMapper.php::resolveNodeText` | `children` 보다 우선 (양쪽 동일). 키가 있으면 값이 비어도 `children` 으로 폴백하지 않음. `true`/`false`/`null` 로 평가되면 아무것도 출력하지 않음 (JSX 시맨틱). 단, 자체 렌더링을 가진 집합 컴포넌트(아래 `render` 모드)에서는 컴포넌트 출력이 먼저다 — 일반 화면에서도 `Select` 는 `options` 로 스스로 그리고 `text` 를 쓰지 않는다 |
| children 배열 내 문자열 | ✅ | ✅ | `app/Seo/ComponentHtmlMapper.php::render` | 이스케이프만 하고 리터럴 출력 — 표현식·번역 미해석 (React 동일) |
| `if` | ✅ | ✅ | `app/Seo/ComponentHtmlMapper.php::evaluateBooleanExpression` | 거짓 판정: `''`, `false`, `0`, `null`, `undefined` (대소문자·공백 무시) |
| `condition` | ✅ | ✅ | `app/Seo/ComponentHtmlMapper.php::shouldRender` | `if` 의 별칭 |
| `conditions` | ✅ | ✅ | `app/Seo/ComponentHtmlMapper.php::evaluateConditions` | 문자열 / `{and:[]}` / `{or:[]}` / `[{if:…}]` 체인. 빈 AND=참, 빈 OR=거짓. 어느 형식도 아니면 **렌더링**(양쪽 동일 — 숨기면 봇 화면에서만 사라짐) |
| `iteration` | ✅ | ✅ | `app/Seo/ComponentHtmlMapper.php::renderIteration` | `item_var` / `index_var` 별칭 포함 |
| `type: "iterator"` | ✅ | ✅ | `app/Seo/ComponentHtmlMapper.php::normalizeIteratorNode` | `data`/`itemName`/`indexName` → `iteration` 변환 |
| `classMap` | ✅ | ✅ | `app/Seo/ComponentHtmlMapper.php::resolveClassMap` | |
| `responsive` | 전체 브레이크포인트 | 데스크톱 폭만 | `app/Seo/ComponentHtmlMapper.php::applyResponsiveOverrides` / `matchingBreakpointKey` | 봇=데스크톱 고정. `props`/`if`/`text`/`children`/`iteration` 오버라이드 반영. 매칭 키가 여럿이면 **하나만** 적용 — 커스텀 범위 > 프리셋, 좁은 범위 > 넓은 범위 (양쪽 동일) |
| `extensionPointProps` | ✅ | ✅ | `app/Seo/ComponentHtmlMapper.php::injectExtensionPointContext` | 자손 상속 |
| `default` (extension_point 호스트) | ✅ | ✅ | 렌더 전 단계에서 처리 | 확장이 꺼져 있을 때 쓰는 기본 children — 주입 단계에서 교체·전개되어 렌더러에는 남지 않음 |
| `callbacks` (extension_point 호스트) | ✅ | ❌ (의도) | 미처리 | 주입 단계에서 `extensionPointCallbacks` 로 부착 — 봇 화면에는 액션 실행기가 없음 |
| `computed` / `extends` / 파셜 | ✅ | ✅ | `app/Seo/SeoRenderer.php::resolveComputed` / `app/Services/LayoutService.php::getLayout` | 레이아웃 병합·설치 시점에 처리 |
| `$t:` / `$t:defer:` | ✅ | ✅ | `app/Seo/ExpressionEvaluator.php::resolveTranslation` | 봇 화면은 지연 개념이 없어 동일 키로 해석 |
| `actions` | ✅ | 링크만 | `app/Seo/ComponentHtmlMapper.php::extractLinkAction` | 페이지 이동/새 창 열기 액션만 `<a href>` 로 승격 |
| `extensionPointCallbacks` | ✅ | ❌ (의도) | 미처리 | 액션 실행기 부재 |
| `lifecycle` / `dataKey` / `trackChanges` | ✅ | ❌ (무해) | 미처리 | 입력·생명주기 전용 |
| `slot` / `slotOrder` | ✅ | ❌ (무해) | `app/Services/LayoutService.php::replaceSlots` 에서 선처리 | 레이아웃 병합 단계에서 제거 |
| `sortable` / `itemTemplate` / `expandChildren` / `component_layout` | ✅ | ❌ (무해) | 미처리 | 조작 후 노출되는 화면 |
| `isolatedState` / `$parent` / `_isolated` | ✅ | ❌ (무해) | 미처리 | 격리·모달 부모 컨텍스트 |
| `blur_until_loaded` / 노드 최상위 `style` | ✅ | ❌ (무해) | 미처리 | 표현 전용 |
| 노드 최상위에 잘못 놓인 컴포넌트 prop (`size` 등) | ❌ | ❌ | 미처리 | 양쪽 모두 `props` 객체만 읽으므로 무시됨 — 값을 적용하려면 `props` 안으로 옮겨야 함 |
| `props.isHtml` (콘텐츠 노드) | ✅ | ✅ | `app/Seo/ComponentHtmlMapper.php::renderRawMode` | 거짓이면 이스케이프, 참(기본)이면 정화 후 HTML — "봇 화면의 HTML 정화" 참조 |
| `props.value` (폼 제어) | 속성 | 속성 | `app/Seo/ComponentHtmlMapper.php::resolveTextContent` | `select`/`option`/`input`/`textarea` 등에서는 글자로 승계하지 않음 — 선택 목록은 `options` 로 항목 라벨을 그림 |
| `props.purifyConfig` | ✅ | ❌ (의도) | 미처리 | 봇 화면은 기본 정화 규칙만 적용 |
| `modals` | ✅ | ❌ (무해) | `SeoRenderer` 가 `components` 만 렌더 | 봇 화면은 모달을 렌더하지 않음 |
| 레이아웃 최상위 `state` / `initLocal` / `initGlobal` | ✅ | ✅ | `app/Seo/SeoRenderer.php::resolveInitStateBlock` / `resolveInitActionState` | `init_actions` 의 상태 설정(로컬/전역)도 반영 |

이 표는 `tests/Unit/Seo/SeoNodeKeyParityTest.php` 의 분류 목록과 동기 유지합니다. 표를 바꾸면 그 테스트의 목록도 함께 바꿔야 합니다. 반대로 레이아웃에 새 노드 키가 등장하면 그 테스트가 실패하므로, 봇 화면에서 해석이 필요한지 판단한 뒤 양쪽을 갱신하세요.

확장 포인트 쪽 서술은 [layout-extensions.md "Extension Point 데이터 전달"](../extension/layout-extensions.md), 레이아웃 작성자 관점 요약은 [layout-json.md](../frontend/layout-json.md) 를 참조하세요.

### DataSourceResolver — params 쿼리 파라미터 해석

data_source 정의에 `params` 필드가 있으면 해당 값을 해석하여 API 호출 시 쿼리 파라미터로 전달합니다.

```json
{
    "id": "products",
    "endpoint": "/api/products",
    "method": "GET",
    "params": {
        "page": "{{query.page ?? 1}}",
        "per_page": 12,
        "sort": "{{query.sort ?? 'latest'}}",
        "category_slug": "{{route.slug}}"
    }
}
```

| params 값 유형 | 동작 |
|---------------|------|
| `{{query.xxx}}` | URL 쿼리 파라미터에서 해석 |
| `{{route.xxx}}` | 라우트 파라미터에서 해석 |
| 숫자 리터럴 | 그대로 전달 |
| `{{query.xxx ?? 'default'}}` | null coalescing 지원 |

- 빈 문자열로 해석된 값은 전달하지 않음 (선택적 파라미터)
- `params`가 빈 배열이면 쿼리 파라미터 미추가

### iteration — 레이아웃 스키마 호환

`iteration` 속성의 데이터 경로 키는 레이아웃 JSON 스키마 규격인 `source`를 사용합니다. `data` 키는 레거시 호환 fallback입니다.

```json
"iteration": { "source": "{{path.to.array}}", "item_var": "item" }
```

### render_modes — 5가지 렌더 타입

엔진은 5가지 **범용 렌더 타입**을 지원합니다. 렌더 모드 이름(image_gallery 등)은 템플릿이 자유롭게 정의합니다.

| 타입 | 역할 | 설명 |
|------|------|------|
| `iterate` | 배열 데이터 순회 → 아이템별 HTML 생성 | `item_tag`, `item_attrs`, `item_content`, `badge_field`. `item_attrs` 와 `item_content` 를 함께 선언하면 속성과 라벨을 모두 그립니다(`<option value="…">라벨</option>`) |
| `format` | 포맷 문자열 `{key}` 플레이스홀더 치환 | `format`, `defaults` (component_map 엔트리에서 정의) |
| `raw` | 사용자 작성 콘텐츠 출력 | `source`. `isHtml` prop 판정에 따라 이스케이프 또는 정화 후 출력 — "봇 화면의 HTML 정화" 참조 |
| `fields` | 객체 prop에서 필드 추출 → 개별 HTML 생성 | `fields` (컴포지트 컴포넌트 SEO 렌더링용) |
| `pagination` | 페이지네이션 링크 생성 | `max_links` (기본 10), 현재 페이지 `<span>` + 나머지 `<a href="?page=N">` |

#### format 타입 상세

포맷 문자열의 `{key}` 플레이스홀더를 **3단계 우선순위**로 치환합니다:

1. **컴포넌트 props** — 레이아웃 JSON에서 명시적으로 전달한 값 (최우선)
2. **seoVars** — `meta.seo.vars`에서 해석된 값 (사이트명 등 동적 설정값)
3. **defaults** — seo-config.json의 component_map 엔트리에 정의된 기본값 (최종 폴백)

```json
// component_map 예시
"Header": {
    "tag": "header",
    "render": "text_format",
    "format": "{siteName}",
    "defaults": { "siteName": "G7" }
}

// 레이아웃 meta.seo.vars 예시
"vars": {
    "siteName": "$core_settings:general.site_name"
}
```

위 설정에서 Header 렌더링 결과:
- `site_name` 설정값이 `"My Store"`이면 → `<header>My Store</header>`
- `site_name` 설정값이 비어있으면 → `<header>G7</header>` (defaults 폴백)

#### fields 타입 상세

컴포지트 컴포넌트(ProductCard 등)가 받는 객체 prop에서 필드를 추출하여 SEO용 HTML을 생성합니다.

**source 옵션**:

| source 값 | 동작 | 사용 예시 |
|-----------|------|----------|
| `$props_source` | `component_map`의 `props_source` 키에 해당하는 단일 prop을 데이터로 사용 | ProductCard (`props_source: "product"`) |
| `$all_props` | 모든 props 표현식을 해석하여 데이터 객체로 사용 | Header/Footer (siteName, boards 등 다수 props) |

`$all_props`는 컴포넌트의 모든 `{{expression}}` props를 재귀적으로 해석하여 하나의 데이터 객체로 조합합니다. 컴포지트 컴포넌트가 여러 개의 독립적인 데이터를 props로 받는 경우에 적합합니다.

```json
"product_card_view": {
    "type": "fields",
    "source": "$props_source",
    "link": {
        "href": "/products/{id}",
        "base_url": "$global:shopBase"
    },
    "fields": [
        { "tag": "img", "attrs": { "src": "{thumbnail_url}", "alt": "{name_localized|name}" } },
        { "tag": "h3", "content": "{name_localized|name}" },
        {
            "tag": "p",
            "children": [
                { "tag": "span", "content": "{primary_category}", "if": "{primary_category}" },
                { "tag": "span", "content": "{brand_name}", "if": "{brand_name}" }
            ]
        },
        {
            "tag": "p",
            "children": [
                { "tag": "span", "content": "{selling_price_formatted}" },
                { "tag": "del", "content": "{list_price_formatted}", "if": "{discount_rate}" },
                { "tag": "span", "content": "{discount_rate}%", "if": "{discount_rate}" }
            ]
        },
        { "tag": "p", "iterate": "labels", "item_tag": "span", "item_content": "{name}" },
        { "tag": "span", "content": "{sales_status_label}", "if": "{sales_status}" }
    ]
}
```

**렌더 모드 속성**:

| 키 | 타입 | 설명 |
|----|------|------|
| `link` | object | 모든 필드를 `<a>` 태그로 래핑 |
| `link.href` | string | 링크 URL 패턴 — `{field}` 플레이스홀더 사용 (미해석 시 링크 미생성) |
| `link.base_url` | string | URL 접두사 — `$global:key` 패턴으로 globalResolver 해석 가능 |

**필드 속성**:

| 필드 키 | 타입 | 설명 |
|---------|------|------|
| `tag` | string | HTML 태그 |
| `content` | string | `{field\|alt}` 패턴으로 값 추출 (리터럴 혼합 가능: `{discount_rate}%`) |
| `attrs` | object | 속성 기반 렌더링 (img 등) — `{field}` 패턴 |
| `children` | array | 중첩 필드 그룹 — 자식 필드를 재귀 렌더링, 모든 자식이 빈 결과면 래퍼 태그 미출력 |
| `if` | string | 조건부 렌더링 — `{field}` 값이 비어있으면 스킵 |
| `class` | string | CSS class 속성 |
| `iterate` | string | 배열 필드명 — 순회하여 `item_tag`/`item_content`로 렌더링 |
| `item_tag` | string | iterate 내 아이템 태그 (기본: `span`) |
| `item_content` | string | iterate 내 아이템 콘텐츠 패턴 |
| `item_attrs` | object | iterate 내 아이템별 동적 HTML 속성 — `{field}` 패턴 (예: `{ "href": "/board/{slug}" }`) |

**기대 출력** (위 설정 기준):
```html
<article>
  <a href="/shop/products/123">
    <img src="/storage/thumb.jpg" alt="상품명">
    <h3>상품명</h3>
    <p><span>의류</span><span>나이키</span></p>
    <p><span>10,000원</span><del>15,000원</del><span>33%</span></p>
    <p><span>베스트</span><span>무료배송</span></p>
    <span>판매중</span>
  </a>
</article>
```

**`$t:` 번역 키 지원**:

fields 렌더 모드의 `content`에서 `$t:key` 패턴을 사용하면 템플릿 번역 파일의 다국어 텍스트로 치환됩니다. 정적 네비게이션 링크 등 하드코딩 텍스트 대신 다국어 키를 사용할 때 유용합니다.

```json
"header_nav": {
    "type": "fields",
    "source": "$all_props",
    "fields": [
        { "tag": "a", "attrs": { "href": "/" }, "content": "{siteName}" },
        {
            "tag": "nav",
            "children": [
                { "tag": "a", "attrs": { "href": "/" }, "content": "$t:nav.home" },
                { "tag": "a", "attrs": { "href": "/boards/popular" }, "content": "$t:nav.popular" },
                { "tag": "a", "attrs": { "href": "/shop/products" }, "content": "$t:nav.shop" }
            ]
        },
        {
            "tag": "nav",
            "iterate": "boards",
            "item_tag": "a",
            "item_content": "{name}",
            "item_attrs": { "href": "/board/{slug}" }
        }
    ]
}
```

위 설정에서 `$t:nav.home`은 번역 파일(`lang/partial/ko/nav.json`)의 `home` 키 값("홈")으로 치환됩니다.

**`$all_props` + `item_attrs` 사용 예시**:

`iterate` 필드에 `item_attrs`를 지정하면 각 아이템에 `{field}` 패턴으로 동적 속성을 렌더링합니다:

```html
<!-- iterate: "boards", item_attrs: { "href": "/board/{slug}" } -->
<nav>
  <a href="/board/free">자유게시판</a>
  <a href="/board/notice">공지사항</a>
</nav>
```

### seo_overrides — 접혀있는 콘텐츠 강제 펼침

프론트엔드에서 의도적으로 접혀있는(collapsed) 콘텐츠를 SEO 렌더링 시 펼쳐서 표시하기 위한 설정입니다. `_local`/`_global` 상태 경로에 대해 와일드카드(`*`) 기본값을 선언할 수 있습니다.

#### 구조

```json
"seo_overrides": {
    "_local": {
        "collapsedReplies": { "*": false }
    },
    "_global": {
        "expandedSections": { "*": true }
    }
}
```

#### 동작 원리

| 오버라이드 타입 | 구문 | 예시 |
|----------------|------|------|
| 와일드카드 | `{ "*": value }` | `_local.collapsedReplies[모든키]` → `value` 반환 |
| 배열 다중 값 | `[value1, value2, ...]` | `_local.activeTab === 'reviews'` → 배열 내 값과 매칭 시 `true` |
| 정확 매칭 | `value` (비배열) | `_local.showAllComments` → `value` 반환 |

**와일드카드 매칭 시점**:
1. **브래킷 키 해석 불가** — `$computed`/`_local` 등 SEO에서 미존재하는 경로가 브래킷 내부에 있을 때, prefix 경로에 대한 와일드카드 확인
2. **키 해석 후 값 미존재** — 브래킷 키는 정상 해석되었으나 해당 값이 없을 때, 와일드카드로 폴백

**우선순위**:

- **배열 다중 값 오버라이드**: 컨텍스트 값보다 항상 우선 (모든 조건을 동시에 충족시키기 위함)
- **와일드카드 오버라이드**: 컨텍스트 값이 없을 때만 적용 (null 폴백)
- **정확 매칭**: 컨텍스트 값보다 항상 우선

#### 사용 예시 1: 댓글 대댓글 표시 (와일드카드)

프론트엔드에서 대댓글은 기본적으로 접혀있고 (`_local.collapsedReplies[rootId] === false`일 때만 표시), SEO에서는 모두 펼쳐야 합니다:

```json
// seo-config.json
"seo_overrides": {
    "_local": {
        "collapsedReplies": { "*": false }
    }
}
```

이 설정으로 `_local.collapsedReplies?.[$computed.commentRootMap?.[comment?.id]] === false` 조건이 SEO에서 항상 `true`가 됩니다.

#### 사용 예시 2: 탭 콘텐츠 전체 표시 (배열 다중 값)

프론트엔드에서 탭 UI는 `_local.activeTab === 'reviews'` 조건으로 하나의 탭만 표시하지만, SEO에서는 모든 탭 콘텐츠를 동시에 표시해야 합니다:

```json
// seo-config.json
"seo_overrides": {
    "_local": {
        "activeTab": ["info", "reviews", "qna"]
    }
}
```

**동작 원리**: 배열 오버라이드가 설정된 경로에 대해 `===` 비교 시 `in_array` 매칭을 수행합니다:

- `_local.activeTab === 'reviews'` → `in_array('reviews', ["info", "reviews", "qna"])` → `true`
- `_local.activeTab === 'info'` → `true`
- `_local.activeTab === 'qna'` → `true`
- `_local.activeTab === 'unknown'` → `false`

`!==` 비교는 반대로 동작합니다:

- `_local.activeTab !== 'reviews'` → `false` (매칭되므로)

**null coalescing과의 조합**: `(_local.activeTab ?? 'info') === 'reviews'` 패턴에서도 배열 타입이 보존되어 정상 동작합니다. `evaluateComparisonOperand`가 비교 피연산자의 원본 타입(배열)을 유지합니다.

### init_actions → _local 상태 초기화

SEO 렌더링 시 레이아웃의 `init_actions`에서 `setState` 핸들러를 해석하여 `_local` 상태를 초기화합니다. 탭 UI, 접힘/펼침 등 `_local` 상태에 의존하는 조건부 렌더링이 SEO에서도 정상 동작하도록 합니다.

#### 동작 원리

1. `init_actions` 배열에서 `handler: "setState"` 항목만 추출
2. `target: "global"`인 항목은 스킵 (SEO `_global`은 별도 주입)
3. `params`의 메타 키(`target`, `handler`, `comment`)를 제외한 나머지를 `_local`에 매핑
4. `{{expression}}` 값은 현재 컨텍스트(`query`, `route` 등)로 평가

#### 지원 패턴

| params 값 유형 | 동작 | 예시 |
|---------------|------|------|
| 정적 문자열 | 그대로 `_local`에 설정 | `"activeTab": "info"` |
| `{{expression}}` | 컨텍스트 기반 평가 | `"activeTab": "{{query.tab ?? 'info'}}"` |
| 중첩 배열/객체 | 재귀적으로 `{{}}` 평가 | `{ "filters": { "sort": "{{query.sort ?? 'latest'}}" } }` |

#### 레이아웃 예시

```json
{
    "init_actions": [
        {
            "handler": "setState",
            "comment": "탭 초기 상태",
            "params": {
                "target": "local",
                "activeTab": "{{query.tab ?? 'info'}}"
            }
        }
    ]
}
```

위 설정으로 `?tab=reviews` 요청 시 `_local.activeTab = "reviews"`로 초기화되어, 리뷰 탭의 `if: "{{_local.activeTab === 'reviews'}}"` 조건이 SEO에서도 `true`로 평가됩니다.

#### 처리되지 않는 핸들러

| 핸들러 | 사유 |
|--------|------|
| `loadFromLocalStorage` | 서버 사이드에서 localStorage 미존재 |
| `closeModal` | SEO에서 모달 상태 불필요 |
| `navigate` | SEO 렌더링은 단일 페이지 |
| 기타 비-setState | _local 초기화와 무관 |

### 파이프라인 흐름

```
SeoRenderer.render()
  └─ seoConfigMerger.getMergedConfig(templateIdentifier)
       └─ 모듈 resources/seo-config.json 수집 (활성만, 알파벳순)
       └─ 플러그인 resources/seo-config.json 수집 (활성만, 알파벳순)
       └─ 템플릿 seo-config.json 로드 (최종 우선)
       └─ 병합 결과 캐시 (24h TTL)
  └─ htmlMapper.setComponentMap(config.component_map)
  └─ htmlMapper.setRenderModes(config.render_modes)
  └─ htmlMapper.setSelfClosing(config.self_closing)
  └─ htmlMapper.setTextProps(config.text_props)
  └─ htmlMapper.setAttrMap(config.attr_map)
  └─ htmlMapper.setAllowedAttrs(config.allowed_attrs)
  └─ evaluator.setSeoOverrides(config.seo_overrides)  ← _local/_global 상태 오버라이드
  └─ htmlMapper.setGlobalResolver(closure)  ← _global 표현식 해석
  └─ resolveSeoVars(seoConfig.vars) → $core_settings:, $module_settings:, $plugin_settings: 해석
  └─ htmlMapper.setSeoVars(resolvedVars)  ← format 모드 변수 주입
  └─ isExtensionSeoEnabled(seoConfig.toggle_setting) → false면 null 반환
  └─ metaResolver.resolve(seoConfig, context, moduleId, pluginId, routeParams) → vars 치환
  └─ View::make('seo', [..., 'stylesheets' => config.stylesheets])
```

### navigate 핸들러 링크 자동 생성

SEO 렌더링 시 `navigate`/`openWindow` 핸들러가 정의된 컴포넌트에 `<a href="...">` 하이퍼링크를 자동 생성합니다. 검색 엔진 봇이 내부 페이지를 발견할 수 있도록 링크 구조를 자동 형성합니다.

#### 지원 핸들러

| 핸들러 | 변환 결과 | 비고 |
|--------|----------|------|
| `navigate` | `<a href="...">` | 내부 페이지 이동 |
| `openWindow` | `<a href="..." target="_blank">` | 새 탭/외부 링크 |

#### 지원 패턴

- **정적 경로**: `"path": "/login"` → `<a href="/login">`
- **동적 경로**: `"path": "/posts/{{post.slug}}"` → 데이터소스 컨텍스트에서 해석
- **_global 참조**: `"path": "{{_global.shopBase}}/products"` → globalResolver로 `g7_module_settings()`/`g7_plugin_settings()` 해석
- **query params**: `"query": { "q": "test" }` → `?q=test` 쿼리스트링 빌드
- **sequence 내부**: `handler: "sequence"` 내 navigate/openWindow 자동 추출

#### skip 조건

| 조건 | 이유 |
|------|------|
| `replace: true` | 필터/페이지네이션 — 중복 콘텐츠 방지 |
| click 외 이벤트 (`keydown`, `change` 등) | 크롤러가 발생시킬 수 없는 이벤트 |
| 미해석 `{{}}` 잔존 | `_local.*`, `$event.*` 등 런타임 전용 값 |
| `_global` 참조 + globalResolver 미설정/실패 | SEO 컨텍스트에서 해석 불가 |
| Fragment (빈 태그) | 래퍼 없어 링크 적용 불가 |

#### 태그별 변환 전략

| 원본 태그 | 전략 | 예시 |
|-----------|------|------|
| `button` (Button) | `<a>`로 변환 (class 보존) | `<a href="/page" class="btn">텍스트</a>` |
| `a` (A) + href 없음 | href 주입 | `<a href="/page" class="link">텍스트</a>` |
| `a` (A) + href 있음 | 스킵 (명시적 href 우선) | 변경 없음 |
| `div`, `section` 등 | `<a>`로 래핑 | `<a href="/page"><div>...</div></a>` |
| self-closing (`img`) | `<a>`로 래핑 | `<a href="/page"><img src="..."></a>` |
| Fragment (빈 태그) | 스킵 | 변경 없음 |

### 검증 (TemplateManager)

`TemplateManager.validateSeoConfig()`가 설치/업데이트 시 자동 검증:

| 검증 항목 | 실패 시 |
|----------|---------|
| JSON 파싱 | 설치/업데이트 차단 |
| `component_map.*.tag` 필수 + string | 설치/업데이트 차단 |
| `component_map.*.render` → `render_modes`에 정의 존재 | 설치/업데이트 차단 |
| `render_modes.*.type` ∈ {iterate, format, raw, fields, pagination} | 설치/업데이트 차단 |
| `stylesheets` 배열 여부 | 설치/업데이트 차단 |
| `self_closing` 배열 여부 | 설치/업데이트 차단 |
| `seo_overrides` 객체 + `_local`/`_global` 키만 허용 | 설치/업데이트 차단 |
| 파일 미존재 | 경고만 (설치 허용, div fallback) |

### 확장 식별자 판별 (모듈 vs 플러그인)

레이아웃명에 dot notation(`identifier.layout`)이 사용되면 `TemplateRouteResolver`와 `SeoDeclarationCollector`가 확장 타입을 자동 판별합니다.

**판별 순서**:
1. `ModuleManagerInterface::getModule($id)` — 모듈이면 `moduleIdentifier` 설정
2. `PluginManagerInterface::getPlugin($id)` — 플러그인이면 `pluginIdentifier` 설정
3. fallback — 알 수 없는 확장은 `moduleIdentifier`로 간주 (기존 동작 유지)

**영향 범위**:

| 클래스 | 플러그인 지원 내용 |
|--------|-------------------|
| `TemplateRouteResolver` | `pluginIdentifier` 키 반환 |
| `SeoDeclarationCollector` | `pluginIdentifier` 키 포함 + 그룹핑 지원 |
| `SeoRenderer` | `$plugin_settings:` vars/toggle, globalResolver 플러그인 패턴 |
| `SeoMetaResolver` | `resolvePluginTemplate()`, `$plugin_settings:` 변수 해석 |

**globalResolver 패턴**:

| 패턴 | 해석 |
|------|------|
| `_global.modules?.['id']?.key ?? 'default'` | `g7_module_settings()` (기본값 포함) |
| `_global.modules?.['id']?.key` | `g7_module_settings()` (기본값 없음) |
| `_global.plugins?.['id']?.key ?? 'default'` | `g7_plugin_settings()` (기본값 포함) |
| `_global.plugins?.['id']?.key` | `g7_plugin_settings()` (기본값 없음) |

## 다국어 SEO 지원

### 개요

검색 봇(Googlebot 등)에게 모든 언어 버전의 페이지를 제공하기 위해 `?locale=` 쿼리 파라미터 기반의 다국어 SEO를 지원합니다. Google이 공식 지원하는 3가지 다국어 URL 전략(서브디렉토리, 쿼리 파라미터, 서브도메인) 중 쿼리 파라미터 방식을 채택했습니다.

### URL 규칙

| 로케일 | URL 형식 | 비고 |
|--------|---------|------|
| 기본 (ko) | `https://example.com/products/123` | 파라미터 없는 clean URL |
| 비기본 (en) | `https://example.com/products/123?locale=en` | `?locale=xx` 포함 |
| 기본을 명시 | `?locale=ko` → 301 리다이렉트 | 중복 URL 방지 |
| 미지원 | `?locale=ja` → 기본 로케일 폴백 | `supported_locales` 검증 |

### SeoMiddleware 동작

1. 봇 확인 후 `?locale=` 쿼리 파라미터 해석 (`resolveSeoLocale()`)
2. `config('app.supported_locales')` 검증 → 유효하면 사용, 아니면 기본 로케일
3. `?locale=ko` (기본 로케일 명시) → `?locale` 없는 URL로 301 리다이렉트
4. `app()->setLocale($locale)` 호출로 SEO 렌더링 로케일 설정
5. 기본 로케일을 `seo_default_locale` request attribute로 SeoRenderer에 전달

`?locale=` 처리는 SeoMiddleware에서만 수행 (SetLocale에 추가하면 SPA 사용자 요청에 부작용)

### hreflang 태그

`supported_locales`가 2개 이상일 때 자동 생성됩니다.

```html
<link rel="alternate" hreflang="ko" href="https://example.com/products/123">
<link rel="alternate" hreflang="en" href="https://example.com/products/123?locale=en">
<link rel="alternate" hreflang="x-default" href="https://example.com/products/123">
```

- `x-default`: 기본 로케일 URL (파라미터 없음) — 언어 감지 불가 시 기본 버전으로 안내
- 단일 로케일 시: hreflang 태그 미생성

### 다국어 Sitemap

`supported_locales`가 2개 이상일 때 자동으로 다국어 sitemap을 생성합니다.

```xml
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xhtml="http://www.w3.org/1999/xhtml">
  <url>
    <loc>https://example.com/products/123</loc>
    <xhtml:link rel="alternate" hreflang="ko" href="https://example.com/products/123"/>
    <xhtml:link rel="alternate" hreflang="en" href="https://example.com/products/123?locale=en"/>
    <xhtml:link rel="alternate" hreflang="x-default" href="https://example.com/products/123"/>
  </url>
  <url>
    <loc>https://example.com/products/123?locale=en</loc>
    <xhtml:link rel="alternate" hreflang="ko" href="https://example.com/products/123"/>
    <xhtml:link rel="alternate" hreflang="en" href="https://example.com/products/123?locale=en"/>
    <xhtml:link rel="alternate" hreflang="x-default" href="https://example.com/products/123"/>
  </url>
</urlset>
```

- `SitemapContributorInterface` 변경 없음: contributor는 기본 URL만 반환, SitemapGenerator가 자동으로 다국어 URL 확장
- 단일 로케일 시: 기존 형식 유지 (xhtml 네임스페이스 없음)

### 설정

| 키 | 위치 | 설명 |
|----|------|------|
| `app.locale` | `config/app.php` | 기본 로케일 (예: `ko`) |
| `app.supported_locales` | `config/app.php` | 지원 로케일 배열 (예: `['ko', 'en']`) |

### 캐시

SeoCacheManager는 URL + locale 기반 캐시 키(`md5($cacheUrl.'|'.$locale)`)를 사용하므로 다국어 캐시가 자동 분리됩니다.

SeoMiddleware의 `buildCacheUrl()`이 캐시 키용 URL을 구성합니다:

- **경로 + 쿼리 파라미터 포함**: `/shop/products?page=2&sort=price` → 페이지별 독립 캐시
- **`locale` 파라미터 제외**: locale은 캐시 키의 두 번째 차원(`$locale`)으로 별도 관리
- **쿼리 파라미터 정렬**: `ksort()` — 동일 파라미터 조합 = 동일 캐시 키 보장

## SEO 변수 시스템

확장(모듈/플러그인)이 `seoVariables()` 메서드를 통해 SEO 변수를 선언하면, SeoRenderer가 자동으로 해석하여 설정 템플릿의 `{key}` 플레이스홀더를 치환합니다.

### seoVariables() API

`AbstractModule` / `AbstractPlugin`에서 오버라이드하여 페이지 유형별 SEO 변수를 선언합니다.

```php
public function seoVariables(): array
{
    return [
        '_common' => [
            'site_name' => ['source' => 'core_setting', 'key' => 'general.site_name'],
            'commerce_name' => ['source' => 'setting', 'key' => 'basic_info.shop_name'],
        ],
        'product' => [
            'product_name' => ['source' => 'data', 'key' => 'product.data.name'],
            'product_description' => ['source' => 'data', 'key' => 'product.data.short_description'],
        ],
        'category' => [
            'category_name' => ['source' => 'data', 'key' => 'category.data.name'],
        ],
        'search' => [
            'keyword_name' => ['source' => 'query', 'key' => 'q'],
        ],
    ];
}
```

### _common 키

`_common`에 선언된 변수는 **모든 page_type에 공통 적용**됩니다. 런타임에 page_type별 변수와 병합되며, 동일 키가 있으면 page_type별 선언이 우선합니다.

```text
최종 변수 = _common 변수 + page_type별 변수 (page_type 우선)
```

### 변수 소스 타입

| source | 설명 | 자동 해석 | 예시 |
|--------|------|----------|------|
| `setting` | 해당 확장(모듈/플러그인)의 설정 값 | ✅ | `{ "source": "setting", "key": "basic_info.shop_name" }` |
| `core_setting` | 코어 설정 값 | ✅ | `{ "source": "core_setting", "key": "general.site_name" }` |
| `query` | URL 쿼리 파라미터 | ✅ | `{ "source": "query", "key": "q" }` |
| `route` | URL 라우트 파라미터 | ✅ | `{ "source": "route", "key": "slug" }` |
| `data` | 데이터소스 응답 데이터 | ❌ (레이아웃 `vars`에서 매핑 필요) | `{ "source": "data", "key": "product.data.name" }` |

- `setting`, `core_setting`, `query`, `route` 소스는 SeoRenderer가 **자동으로 해석**합니다.
- `data` 소스는 레이아웃 JSON의 `meta.seo.vars`에서 표현식으로 매핑해야 합니다.

#### source 타입과 값 공급 주체

변수의 `source` 타입이 "값을 누가 채우는지"를 결정합니다. 같은 변수명을 모듈과 레이아웃이 동시에 선언해도, 값의 주인은 `source`가 정합니다.

| source | 값의 주인 | 레이아웃 `vars`로 덮어쓰기 |
|--------|----------|---------------------------|
| `setting` | 모듈/플러그인 설정 | 불가 (출처가 강제) |
| `core_setting` | 코어 설정 | 불가 |
| `query` | URL 쿼리 | 불가 |
| `route` | 라우트 파라미터 | 불가 |
| `data` | 레이아웃 `vars` | 유일하게 레이아웃이 값 공급 |

`source: data` 변수만 레이아웃 `vars`가 값을 공급하고, 나머지 타입은 각 출처(모듈 설정/코어 설정/URL/라우트)가 값을 강제하므로 레이아웃 `vars`로 덮을 수 없습니다.

> 주의: `source: core_setting` 변수도 **정의 주체는 모듈/플러그인**입니다 (코어는 `seoVariables()` 같은 정의 인프라를 갖지 않음). 코어 설정은 그 변수의 "값이 저장된 창고"일 뿐, 변수를 정의하지 않습니다.

#### required 플래그

변수 정의에 `required: true`를 지정하면, 해석 결과가 빈 문자열일 때 경고 로그를 남깁니다.

```php
'product_name' => ['source' => 'data', 'required' => true],
```

- 값이 비면 `Log::warning('[SEO] Required variable not resolved', ...)` 기록 (SeoRenderer)
- 렌더링을 **중단하지 않음** — 빈 값으로 계속 진행 (부분 누락 허용)
- 기본값 주입/예외 발생 없음 — 누락을 감지하는 진단용 표시

SEO 렌더링은 변수 하나가 비어도 나머지 메타태그를 정상 출력해야 하므로, `required`는 강제력 없이 경고만 수행합니다.

### meta.seo.extensions — 확장 변수 로드 선언

레이아웃 JSON에서 SEO 변수를 제공하는 확장을 선언합니다.

```json
{
    "meta": {
        "seo": {
            "enabled": true,
            "extensions": [
                { "type": "module", "id": "sirsoft-ecommerce" },
                { "type": "plugin", "id": "sirsoft-payment" }
            ],
            "page_type": "product",
            "vars": {
                "product_name": "{{product.data.name ?? ''}}",
                "product_description": "{{product.data.short_description ?? ''}}"
            }
        }
    }
}
```

`extensions` 배열에 선언된 확장의 `seoVariables()`가 호출되어, 해당 page_type의 변수가 자동 해석됩니다. `vars`에는 `data` 소스 변수만 표현식으로 매핑하면 됩니다.

### 처리 흐름

```text
1. SeoRenderer: 레이아웃 meta.seo.extensions 확인
2. 각 확장의 seoVariables() 호출 → _common + page_type별 변수 병합
3. 자동 해석 소스(setting, core_setting, query, route) 즉시 해석
4. data 소스 → 레이아웃 vars에서 표현식 매핑 값 적용
5. 확장 설정 title/description 템플릿의 {key} 치환
6. 결과를 _seo.{page_type}.title / _seo.{page_type}.description 컨텍스트에 주입
```

### _seo 컨텍스트 주입

SeoRenderer가 설정 템플릿을 해석한 후 결과를 `_seo` 네임스페이스에 주입합니다.

```text
_seo.{page_type}.title       — 해석된 SEO 제목
_seo.{page_type}.description — 해석된 SEO 설명
```

레이아웃 JSON에서 다음과 같이 참조할 수 있습니다:

```json
"og": {
    "title": "{{_seo.product.title ?? product.data.name ?? ''}}",
    "description": "{{_seo.product.description ?? product.data.short_description ?? ''}}"
}
```

### 변수명 유효성 검증 (ValidatesSeoVariables)

모듈/플러그인 설치 시 `ValidatesSeoVariables` 트레이트가 변수명 고유성을 검증합니다.

- 동일 page_type 내에서 변수명 중복 시 설치 실패
- `_common` 변수와 page_type별 변수 간 중복도 검증 대상
- 서로 다른 확장 간 동일 page_type의 변수명 충돌 시 경고 발생

### vars의 두 출처와 소비처

vars는 "정의 주체"에 따라 두 갈래로 나뉘며, 소비처도 분리됩니다. 두 소비처는 **서로 다른 변수 풀**을 봅니다.

| 정의 주체 | 처리 경로 | 소비처 |
|-----------|----------|--------|
| 모듈/플러그인 `seoVariables()` | `resolveSeoContext` | 확장 SEO 설정 탭의 제목/설명 템플릿 `{key}` 치환 |
| 레이아웃 `meta.seo.vars` | `resolveSeoVars` → `htmlMapper->setSeoVars` | seo-config.json의 `format`/`fields` 렌더 모드 컴포넌트 |

- **확장 SEO 설정 탭** ← 모듈 `seoVariables()` 기준. 운영자가 입력한 `meta_{page_type}_title` 등의 `{key}` 빈칸을 채움 (`data` 소스 변수가 여기로)
- **seo-config.json** ← 레이아웃 `meta.seo.vars` 기준. `format` 모드 `{key}` 치환(ComponentHtmlMapper)과 `fields` 모드 link `base_url`(`$var:xxx`)에 사용

겹치는 경우는 같은 변수를 **양쪽에 모두 선언**했을 때뿐입니다. 모듈이 정의한 변수는 레이아웃 `vars`에도 적지 않는 한 seo-config.json에서 쓸 수 없고, 레이아웃 임의 변수는 확장 SEO 탭에서 쓸 수 없습니다.

> 죽은 변수 주의: 레이아웃 `meta.seo.vars`에 선언만 하고 어느 소비처(확장 제목 템플릿 / seo-config.json 컴포넌트)에서도 참조하지 않으면, 해석은 되지만 출력 어디에도 반영되지 않습니다. 헤더/푸터 등 공통 컴포넌트 변수(`siteName`, `shopBase` 등)는 보통 베이스 레이아웃(`_user_base`)에서 소비되므로, 개별 페이지 레이아웃에 중복 선언된 동일 변수는 해당 페이지에서 미사용 상태로 남을 수 있습니다.

### og / twitter / structured_data 캐스케이드 경합

세 항목 모두 우선순위 서열은 `코어 < 모듈/플러그인 declaration < 레이아웃 override < 훅`이지만, 합쳐지는 단위가 다릅니다.

| 항목 | 코어 fallback | 모듈+플러그인 동시 제공 | 레이아웃 부분 재정의 | 병합 단위 |
|------|--------------|------------------------|---------------------|----------|
| `og` / `twitter` | 일부 키 제공 (site_name, image_width, type / card, site) | 키 단위 병합 (공존) | 가능 (키 단위) | 키 (`fillEmptyKeys`) |
| `structured_data` | 없음 | 마지막 확장 하나가 통째로 (extensions 배열 순서) | 불가 (통째로 전체 작성) | 문서 전체 (`=== null`일 때만 모듈) |

- **og/twitter**: `resolveOgData`가 (코어 fallback + 레이아웃 og)를 먼저 합치고, 모듈 declaration은 `fillEmptyKeys`로 **비어있는 키만** 채웁니다. 따라서 코어가 이미 채운 키(site_name, image_width, type)는 모듈이 못 덮고, 코어가 비워둔 키(image, image_alt, extra)만 모듈이 채웁니다. 레이아웃에 명시한 키는 항상 우선합니다.
- **structured_data**: 코어 fallback이 없습니다. 모듈/플러그인이 둘 다 제공하면 `meta.seo.extensions` 배열에서 **마지막에 선언된 확장**이 통째로 이깁니다(병합 아님). 레이아웃이 `meta.seo.structured_data`를 선언하면 모듈/플러그인 declaration은 전부 무시되며, 부분 재정의가 불가하므로 완성된 전체 구조를 작성해야 합니다.
- 세 항목 모두 마지막에 `core.seo.filter_og_data` / `filter_twitter_data` / `filter_structured_data` 훅이 제약 없이 통째로 덮을 수 있습니다 (이미 채워진 키도 변경 가능).

`og.type` 함정: 모듈 `seoOgDefaults`가 `type => 'product'`를 반환해도, 레이아웃에 `og.type`이 없으면 `resolveOgData`가 코어 기본값 `'website'`를 먼저 채워 모듈 값이 무시됩니다. 도메인 og:type이 필요한 레이아웃은 `og.type`을 직접 명시해야 합니다.

### meta.seo 표현식에서 참조 가능한 컨텍스트

SeoRenderer가 `$context`에 주입하는 데이터를 `og`/`structured_data`/`vars` 표현식에서 참조할 수 있습니다.

| 참조 경로 | 내용 |
|-----------|------|
| data_source ID (`product`, `reviews` 등) | `meta.seo.data_sources`로 로드한 API 응답 |
| `route` | 라우트 파라미터 + `route.path`(현재 URL 경로) |
| `query` | URL 쿼리스트링 |
| `_global` | 코어 설정(`settings`)·모듈(`modules`)·플러그인 설정 + `initGlobal` 매핑 데이터 |
| `_local` | init_actions의 `setState(target: local)` 평가 결과 |
| `_computed` / `$computed` | `computed` 섹션 평가 결과 |
| `_seo.{page_type}` | 엔진이 해석한 SEO 제목/설명 (`extensions` + vars + 운영자 템플릿 결과물) |

- `_seo`는 직접 작성하지 않고 SeoRenderer가 자동 주입합니다. 런타임에는 레이아웃이 선언한 `page_type` 하나만 채워집니다 (`_seo.product` 등). `og.title`에서 `{{_seo.{page_type}.title ?? <fallback>}}` 패턴으로 참조하는 것이 표준입니다.
- `_seo.{page_type}`의 가능한 page_type 목록은 코어 고정값이 아니라, 각 모듈/플러그인의 `seoVariables()` 키 + 설정의 `meta_{page_type}_title` 키에서 도출됩니다 (확장마다 다름).

## 개발 체크리스트

```
□ meta.seo 추가 시 UpdateLayoutContentRequest 검증 규칙 확인했는가?
□ SitemapContributor 구현 시 ServiceProvider에서 등록했는가?
□ 캐시 무효화 리스너에서 `app(CacheInterface::class)->forget('seo.sitemap')` 도 무효화했는가?
□ 봇 감지 패턴 변경 시 BotDetectorTest 통과하는가?
□ 레이아웃 meta.seo.enabled 변경 시 SeoDeclarationCollectorTest 통과하는가?
□ 다국어 SEO 변경 시 SeoMiddlewareTest/SeoRendererTest/SitemapGeneratorTest 통과하는가?
□ 확장 라이프사이클 훅 추가 시 SeoExtensionCacheListener 구독 목록 업데이트했는가?
□ seoVariables() 선언 시 변수명이 기존 확장과 중복되지 않는가?
□ meta.seo.extensions에 변수 제공 확장을 선언했는가?
□ data 소스 변수는 vars에서 표현식 매핑이 완료되었는가?
```
