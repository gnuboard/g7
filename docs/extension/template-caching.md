# 템플릿 캐싱 전략

> **관련 문서**: [cache-driver.md](./cache-driver.md) (공통 캐시 인터페이스 · 권장) | [template-basics.md](./template-basics.md) | [template-security.md](./template-security.md) | [index.md](./index.md)

> **⚠️ 중요**: 본 문서는 템플릿 캐싱의 **개념적 계층**을 설명합니다.
> 실제 구현은 [`CacheInterface`](./cache-driver.md) 를 사용해야 하며, `Cache::` 파사드 직접 호출은 금지되었습니다.
> 모든 캐시 키는 드라이버 접두사(`g7:core:` / `g7:module.{id}:` / `g7:plugin.{id}:`)가 자동 적용됩니다.
> TTL 은 `g7_core_settings('cache.*_ttl')` 중앙 관리를 따릅니다.

---

## 목차

1. [핵심 원칙](#핵심-원칙)
2. [캐싱 계층](#캐싱-계층)
3. [확장 기능 캐시 버전 (Cache Busting)](#확장-기능-캐시-버전-cache-busting)
4. [캐시 무효화 구현](#캐시-무효화-구현)
5. [서비스에서 캐시 무효화 호출](#서비스에서-캐시-무효화-호출)
6. [캐싱 모범 사례](#캐싱-모범-사례)
7. [성능 벤치마크](#성능-벤치마크)

---

## 핵심 원칙

```
중요: 다층 캐싱으로 성능 최적화
✅ 필수: 캐시 무효화 규칙 준수
```

템플릿 시스템은 여러 계층의 캐싱을 통해 성능을 최적화합니다. 각 캐시는 적절한 TTL(Time To Live)을 가지며, 데이터 변경 시 정확한 무효화가 필수입니다.

---

## 캐싱 계층

### 1. 활성 템플릿 캐시

```php
// 키: templates.active.{type}
// TTL: 3600초 (1시간)
// 값: Template 모델 객체

Cache::remember("templates.active.admin", 3600, function () {
    return Template::where('type', 'admin')
        ->where('is_active', true)
        ->first();
});
```

**무효화 시점**:
- 템플릿 활성화/비활성화 시
- 템플릿 삭제 시

---

### 2. 레이아웃 JSON 캐시

```php
// 키: template.layout.{layout_name}.{locale}
// TTL: 1800초 (30분)
// 값: 병합된 레이아웃 JSON 배열

Cache::remember("template.layout.dashboard.ko", 1800, function () use ($layoutName, $locale) {
    return $this->layoutService->getLayout($layoutName, $locale);
});
```

**무효화 시점**:
- 레이아웃 생성/수정/삭제 시
- 상위 레이아웃(extends) 변경 시
- 로케일 변경 시

---

### 3. 병합된 레이아웃 캐시

상속 체인 처리 결과를 캐싱합니다.

```php
// 키: template.layout.merged.{layout_name}.{locale}
// TTL: 3600초 (1시간)
// 값: extends/slots 병합 완료된 최종 레이아웃

Cache::remember("template.layout.merged.dashboard.ko", 3600, function () use ($layoutName, $locale) {
    return $this->layoutService->mergeLayouts($layoutName, [], 0, $locale);
});
```

**무효화 시점**:
- 레이아웃 JSON 캐시 무효화 시 함께 무효화
- 상속 체인의 어느 레이아웃이든 변경 시

---

### 4. 컴포넌트 레지스트리 캐시

```php
// 키: template.components.{identifier}
// TTL: 무제한 (수동 무효화)
// 값: components.json 파싱 결과

Cache::rememberForever("template.components.sirsoft-admin_basic", function () use ($identifier) {
    $componentsPath = base_path("templates/{$identifier}/components.json");
    return json_decode(file_get_contents($componentsPath), true);
});
```

**무효화 시점**:
- 템플릿 재설치 시
- components.json 수정 시 (개발 중)

---

## 확장 기능 캐시 버전 (Cache Busting)

### 개요

모듈/플러그인/템플릿 활성화/비활성화 시 프론트엔드가 최신 데이터를 즉시 반영하도록 버전 기반 캐시 버스팅을 구현합니다.

```
문제 상황: 브라우저 HTTP 캐시로 인해 모듈 활성화 후 새로고침(Ctrl+F5) 전까지
             다국어/라우트 데이터가 반영되지 않음
✅ 해결책: 캐시 버전(타임스탬프) 기반 쿼리 파라미터로 캐시 무효화
```

### 백엔드 구현

#### ClearsTemplateCaches Trait

모듈/플러그인/템플릿 매니저에서 사용하는 공통 trait입니다. 내부적으로 `CacheInterface` 를 lazy resolve 해서 사용합니다.

```php
<?php

namespace App\Extension\Traits;

use App\Contracts\Extension\CacheInterface;
use App\Extension\Cache\CoreCacheDriver;

trait ClearsTemplateCaches
{
    // 드라이버 접두사 `g7:core:` 가 자동 적용되어 실제 저장 키는 `g7:core:ext.cache_version`
    private static string $extensionCacheVersionKey = 'ext.cache_version';

    /**
     * 확장 기능 캐시 버전을 증가시킵니다.
     */
    protected function incrementExtensionCacheVersion(): void
    {
        self::resolveExtensionCache()->put(self::$extensionCacheVersionKey, time());
    }

    /**
     * 현재 확장 기능 캐시 버전을 반환합니다.
     */
    public static function getExtensionCacheVersion(): int
    {
        return (int) self::resolveExtensionCache()->get(self::$extensionCacheVersionKey, 0);
    }

    private static function resolveExtensionCache(): CacheInterface
    {
        try {
            return app(CacheInterface::class);
        } catch (\Throwable $e) {
            return new CoreCacheDriver(config('cache.default', 'array'));
        }
    }

    /**
     * 모든 활성 템플릿의 언어 캐시를 무효화합니다.
     */
    protected function clearAllTemplateLanguageCaches(): void
    {
        $locales = config('app.supported_locales', ['ko', 'en']);
        $templates = Template::where('status', ExtensionStatus::Active->value)->get();

        foreach ($templates as $template) {
            foreach ($locales as $locale) {
                Cache::forget("template.language.{$template->identifier}.{$locale}");
            }
        }
    }

    /**
     * 모든 활성 템플릿의 routes 캐시를 무효화합니다.
     */
    protected function clearAllTemplateRoutesCaches(): void
    {
        $templates = Template::where('status', ExtensionStatus::Active->value)->get();

        foreach ($templates as $template) {
            Cache::forget("template.routes.{$template->identifier}");
        }
    }
}
```

#### 캐시 버전 증가 트리거 시점

| 매니저 | 메서드 | 트리거 상황 |
|--------|--------|------------|
| ModuleManager | `activate()` | 모듈 활성화 |
| ModuleManager | `deactivate()` | 모듈 비활성화 |
| ModuleManager | `refreshModuleLayouts()` | 레이아웃 갱신 시 변경 발생 |
| PluginManager | `activate()` | 플러그인 활성화 |
| PluginManager | `deactivate()` | 플러그인 비활성화 |
| TemplateManager | `activate()` | 템플릿 활성화 |
| TemplateManager | `refreshTemplateLayouts()` | 레이아웃 갱신 시 변경 발생 |

### 프론트엔드 구현

#### 캐시 버전 저장 및 관리

```typescript
// LayoutLoader.ts
private cacheVersion: number = 0;

constructor() {
    // localStorage에서 캐시 버전 복원
    const stored = localStorage.getItem('g7_extension_cache_version');
    this.cacheVersion = stored ? parseInt(stored, 10) : 0;
}

setCacheVersion(version: number): void {
    const oldVersion = this.cacheVersion;
    this.cacheVersion = version;
    localStorage.setItem('g7_extension_cache_version', String(version));

    // 버전 변경 시 캐시 무효화
    if (oldVersion !== 0 && oldVersion !== version) {
        this.clearCache();
    }
}
```

#### API 요청 시 버전 파라미터 추가

```typescript
// LayoutLoader.ts
async loadLayout(layoutName: string): Promise<LayoutConfig> {
    let url = `/api/layout/${layoutName}`;
    if (this.cacheVersion > 0) {
        url += `?v=${this.cacheVersion}`;
    }
    // ...
}

// TranslationEngine.ts
async loadTranslations(identifier: string, locale: string): Promise<void> {
    let url = `/api/template/${identifier}/language/${locale}`;
    const params: string[] = [];

    if (this.cacheVersion > 0) {
        params.push(`v=${this.cacheVersion}`);
    }

    if (params.length > 0) {
        url += `?${params.join('&')}`;
    }
    // ...
}
```

#### 앱 초기화 시 캐시 버전 설정

```typescript
// TemplateApp.ts
async init(): Promise<void> {
    // 서버에서 캐시 버전 조회
    const response = await fetch('/api/extension/cache-version');
    const { version } = await response.json();

    // 각 엔진에 버전 설정
    this.layoutLoader.setCacheVersion(version);
    this.translationEngine.setCacheVersion(version);
}
```

### 캐시 버전 API 엔드포인트

```php
// routes/api.php
Route::get('/extension/cache-version', function () {
    return response()->json([
        'version' => ClearsTemplateCaches::getExtensionCacheVersion(),
    ]);
});
```

### 동작 흐름

```
1. 사용자가 모듈 활성화 버튼 클릭
2. 백엔드: ModuleManager->activate() 실행
3. 백엔드: incrementExtensionCacheVersion() 호출 → 캐시 버전 = 현재 타임스탬프
4. 백엔드: clearAllTemplateLanguageCaches() 호출 → 서버 캐시 무효화
5. 프론트엔드: 페이지 새로고침 또는 SPA 라우트 이동
6. 프론트엔드: /api/extension/cache-version 조회
7. 프론트엔드: 버전 변경 감지 → localStorage 업데이트 + 내부 캐시 클리어
8. 프론트엔드: API 요청 시 ?v={version} 파라미터 추가
9. 브라우저: 새로운 URL로 인식하여 캐시 우회 → 최신 데이터 수신
```

### 캐시 버전 키 요약

| 저장소 | 키 | 값 | 용도 |
|--------|-----|-----|------|
| Laravel Cache | `g7:core:ext.cache_version` | 타임스탬프 | 서버 측 버전 관리 (CacheInterface 경유) |
| localStorage | `g7_extension_cache_version` | 타임스탬프 | 클라이언트 측 버전 유지 |

---

## 캐시 무효화 구현

### TemplateCacheService

```php
<?php

namespace App\Services\Template;

use Illuminate\Support\Facades\Cache;

class TemplateCacheService
{
    /**
     * 템플릿 활성화 캐시 무효화
     */
    public function invalidateActiveTemplate(string $type): void
    {
        Cache::forget("templates.active.{$type}");
    }

    /**
     * 레이아웃 캐시 무효화
     */
    public function invalidateLayout(string $layoutName): void
    {
        $locales = config('app.supported_locales', ['ko', 'en']);

        foreach ($locales as $locale) {
            Cache::forget("template.layout.{$layoutName}.{$locale}");
            Cache::forget("template.layout.merged.{$layoutName}.{$locale}");
        }
    }

    /**
     * 레이아웃 상속 체인 캐시 무효화
     */
    public function invalidateLayoutChain(string $layoutName): void
    {
        // 현재 레이아웃 무효화
        $this->invalidateLayout($layoutName);

        // 이 레이아웃을 extends하는 모든 자식 레이아웃 무효화
        $children = TemplateLayout::where('extends', $layoutName)->get();

        foreach ($children as $child) {
            $this->invalidateLayoutChain($child->layout_name);
        }
    }

    /**
     * 컴포넌트 레지스트리 캐시 무효화
     */
    public function invalidateComponentRegistry(string $identifier): void
    {
        Cache::forget("template.components.{$identifier}");
    }

    /**
     * 템플릿 전체 캐시 무효화
     */
    public function invalidateAllTemplateCache(string $identifier): void
    {
        // 활성 템플릿 캐시 무효화
        $template = Template::where('identifier', $identifier)->first();
        if ($template) {
            $this->invalidateActiveTemplate($template->type);
        }

        // 모든 레이아웃 캐시 무효화
        $layouts = TemplateLayout::where('template_id', $template->id)->get();
        foreach ($layouts as $layout) {
            $this->invalidateLayoutChain($layout->layout_name);
        }

        // 컴포넌트 레지스트리 캐시 무효화
        $this->invalidateComponentRegistry($identifier);
    }
}
```

---

## 서비스에서 캐시 무효화 호출

### LayoutService 예시

```php
<?php

namespace App\Services\Template;

class LayoutService
{
    public function __construct(
        private TemplateCacheService $cacheService
    ) {}

    /**
     * 레이아웃 수정
     */
    public function updateLayout(int $id, array $data): TemplateLayout
    {
        $layout = TemplateLayout::findOrFail($id);
        $oldExtends = $layout->extends;

        $layout->update($data);

        // 캐시 무효화
        $this->cacheService->invalidateLayoutChain($layout->layout_name);

        // extends 변경 시 이전 부모의 자식들도 무효화
        if ($oldExtends && $oldExtends !== $layout->extends) {
            $this->cacheService->invalidateLayoutChain($oldExtends);
        }

        return $layout->fresh();
    }

    /**
     * 템플릿 활성화
     */
    public function activateTemplate(string $identifier): Template
    {
        $template = Template::where('identifier', $identifier)->firstOrFail();

        // 동일 타입의 기존 활성 템플릿 비활성화
        Template::where('type', $template->type)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $template->update(['is_active' => true]);

        // 캐시 무효화
        $this->cacheService->invalidateActiveTemplate($template->type);

        return $template->fresh();
    }
}
```

### 레이아웃 편집(관리자 커스터마이징) 시 캐시 무효화

관리자 UI에서 레이아웃을 직접 편집/복원할 때도 캐시 무효화가 필수입니다.

`LayoutService`의 저장(`updateLayoutContent`) 및 버전 복원(`restoreVersion`) 메서드에서:

1. **`clearDependentLayoutsCache()`** 호출 — 해당 레이아웃 + 이를 extends하는 자식 레이아웃의 병합 캐시 + `PublicLayoutController` 서빙 캐시(`layout.{identifier}.{name}.v{version}`)를 재귀적으로 무효화
2. **`extension_cache_version` 증가** (`Cache::put('extension_cache_version', time())`) — CDN/브라우저 HTTP 캐시 busting. `PublicLayoutController`가 `?v={version}` 기반 캐싱을 사용하므로, 버전 변경 시 브라우저가 새 URL로 인식하여 캐시를 우회합니다.

```php
// LayoutService::updateLayoutContent() 내부
$layout = $this->layoutRepository->update($layout->id, $updateData);

// 캐시 무효화 (현재 레이아웃 + 자식 레이아웃 + PublicLayoutController 서빙 캐시)
$this->clearDependentLayoutsCache($templateId, $name);

// 프론트엔드 브라우저 캐시 무효화
Cache::put('extension_cache_version', time());
```

```php
// LayoutService::restoreVersion() 내부
$newVersion = $this->versionRepository->restoreVersion($layout->id, $versionId);

// 동일하게 캐시 무효화
$this->clearDependentLayoutsCache($templateId, $name);
Cache::put('extension_cache_version', time());
```

이 과정이 누락되면 편집/복원 내용이 실제 사이트에 반영되지 않습니다.

> 참고: `clearDependentLayoutsCache()`는 내부적으로 `clearLayoutCache()`를 호출하며, 이 메서드는 병합 캐시 삭제 + `clearPublicServingCache()`(기본 v0 + 현재 extension_cache_version 캐시 삭제) + 모듈 레이아웃인 경우 `LayoutResolverService` 캐시도 무효화합니다.

---

## 캐싱 모범 사례

### DO (권장)

- ✅ 읽기 빈도가 높은 데이터는 캐싱 필수 (활성 템플릿, 레이아웃 JSON)
- ✅ TTL은 데이터 변경 빈도에 따라 조정
- ✅ 상속 체인 무효화는 재귀적으로 처리
- ✅ 로케일별 캐싱으로 다국어 지원
- ✅ 캐시 키에 의미 있는 이름 사용

### DON'T (금지)

- ❌ 캐시 무효화 누락 금지 (데이터 불일치 발생)
- ❌ 과도한 캐싱으로 메모리 낭비 금지
- ❌ TTL 없이 무제한 캐싱 남용 금지 (컴포넌트 레지스트리 제외)

---

## 성능 벤치마크

참고용 성능 비교 데이터입니다.

| 상태 | 응답 시간 | 개선율 |
|------|----------|--------|
| 캐싱 없음 | 120ms | - |
| 캐싱 적용 | 5ms | 95% ↓ |

---

## 캐시 키 요약

| 캐시 타입 | 키 패턴 | TTL |
|----------|---------|-----|
| 활성 템플릿 | `templates.active.{type}` | 3600초 (1시간) |
| 레이아웃 JSON | `template.layout.{name}.{locale}` | 1800초 (30분) |
| 병합된 레이아웃 | `template.layout.merged.{name}.{locale}` | 3600초 (1시간) |
| 컴포넌트 레지스트리 | `template.components.{identifier}` | 무제한 |

---

## 관련 문서

- [템플릿 기초](./template-basics.md) - 템플릿 타입, 메타데이터
- [템플릿 보안](./template-security.md) - API 서빙, 화이트리스트
- [템플릿 커맨드](./template-commands.md) - Artisan 커맨드
- [확장 시스템 인덱스](./index.md) - 전체 문서 목록
