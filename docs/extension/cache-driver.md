# 캐시 드라이버 시스템 (CacheInterface)

> **코어/모듈/플러그인에서 캐시를 관리하기 위한 표준화된 인터페이스**

## TL;DR (5초 요약)

```text
1. 모든 캐시 저장은 CacheInterface 사용 (Cache:: 직접 호출 금지)
2. BaseModuleServiceProvider에서 cacheServices 배열에 Service 클래스 등록
3. 접두사로 캐시 키 격리: g7:core:{key}, g7:module.{id}:{key}, g7:plugin.{id}:{key}
4. TTL은 g7_core_settings('cache.*')로 중앙 관리 (하드코딩 금지)
5. Service 생성자에서 CacheInterface 타입힌트하면 자동 주입
```

---

## 목차

- [개요](#개요)
- [아키텍처](#아키텍처)
- [모듈에서 사용하기](#모듈에서-사용하기)
- [코어 서비스에서 사용하기](#코어-서비스에서-사용하기)
- [자동 무효화 (CacheInvalidatable)](#자동-무효화-cacheinvalidatable)
- [TTL 중앙 관리](#ttl-중앙-관리)
- [API 레퍼런스](#api-레퍼런스)
- [StorageDriver와의 대응](#storagedriver와의-대응)

---

## 개요

G7 캐시 드라이버 시스템은 StorageDriver 패턴을 참고하여 설계되었습니다.
코어, 모듈, 플러그인별로 캐시 키를 접두사로 격리하고, DI를 통해 자동 주입됩니다.

### 핵심 원칙

- **접두사 격리**: 각 확장의 캐시 키가 충돌하지 않음
- **DI 자동 주입**: Service 생성자에서 `CacheInterface` 타입힌트만으로 해당 확장의 캐시 드라이버를 받음
- **TTL 중앙 관리**: 모든 TTL은 `g7_core_settings('cache.*')`에서 관리
- **확장 라이프사이클 통합**: 모듈/플러그인 비활성화/삭제 시 캐시 자동 정리

---

## 아키텍처

```
CacheInterface (인터페이스 — app/Contracts/Extension/)
    ↑ implements
AbstractCacheDriver (공통 구현 — app/Extension/Cache/)
    ↑ extends
├── CoreCacheDriver      접두사: g7:core:{key}
├── ModuleCacheDriver    접두사: g7:module.{identifier}:{key}
└── PluginCacheDriver    접두사: g7:plugin.{identifier}:{key}

CacheInvalidatable (트레이트 — app/Extension/Traits/)
    → 모델에 적용, saved/deleted 시 관련 태그 캐시 자동 무효화
```

### 키 접두사 체계

| 드라이버 | 접두사 패턴 | 예시 |
|---------|-----------|------|
| CoreCacheDriver | `g7:core:{key}` | `g7:core:system_settings` |
| ModuleCacheDriver | `g7:module.{id}:{key}` | `g7:module.sirsoft-ecommerce:products:list` |
| PluginCacheDriver | `g7:plugin.{id}:{key}` | `g7:plugin.sirsoft-payment:gateways` |

---

## 모듈에서 사용하기

### 1단계: ServiceProvider에 cacheServices 등록

```php
class EcommerceServiceProvider extends BaseModuleServiceProvider
{
    protected string $moduleIdentifier = 'sirsoft-ecommerce';

    protected array $cacheServices = [
        ProductService::class,
        CategoryService::class,
    ];
}
```

### 2단계: Service 생성자에서 CacheInterface 주입

```php
class ProductService
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CacheInterface $cache,
    ) {}

    public function getProducts(array $filters): array
    {
        $queryHash = md5(json_encode($filters));

        return $this->cache->rememberQuery(
            $queryHash,
            fn () => $this->productRepository->list($filters),
            ttl: 3600,
            tags: ['products'],
        );
    }

    public function getProduct(int $id): ?Product
    {
        return $this->cache->remember(
            "product:{$id}",
            fn () => $this->productRepository->find($id),
            ttl: 3600,
            tags: ['products', "product:{$id}"],
        );
    }
}
```

### 3단계 (선택): 캐시 스토어 오버라이드

모듈 클래스에서 `getCacheStore()`를 오버라이드하면 다른 캐시 드라이버를 사용할 수 있습니다.

```php
class EcommerceModule extends AbstractModule
{
    public function getCacheStore(): string
    {
        return 'redis'; // 기본값(config('cache.default')) 대신 redis 사용
    }
}
```

---

## 코어 서비스에서 사용하기

코어 서비스는 `CoreServiceProvider`에서 `CacheInterface`를 바인딩합니다.

```php
// CoreServiceProvider
$this->app->bind(CacheInterface::class, function () {
    return new CoreCacheDriver(config('cache.default'));
});
```

코어 서비스에서도 동일하게 생성자 주입으로 사용합니다.

```php
class LayoutService
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    public function loadAndMergeLayout(string $name, int $templateId): array
    {
        $ttl = (int) g7_core_settings('cache.layout_ttl', 3600);

        return $this->cache->remember(
            "layout.{$templateId}.{$name}",
            fn () => $this->mergeLayout($name, $templateId),
            $ttl,
            ['layout'],
        );
    }
}
```

---

## 자동 무효화 (CacheInvalidatable)

모델에 `CacheInvalidatable` 트레이트를 적용하면 `saved`/`deleted` 이벤트 시 관련 태그 캐시를 자동 삭제합니다.

```php
use App\Extension\Traits\CacheInvalidatable;

class Product extends Model
{
    use CacheInvalidatable;

    protected function getCacheInvalidationTags(): array
    {
        return ['products', 'product:' . $this->id];
    }

    protected function getCacheDriver(): ?CacheInterface
    {
        return app(ModuleManager::class)
            ->getModule('sirsoft-ecommerce')?->getCache();
    }
}
```

**흐름**: Product 수정 → `CacheInvalidatable`이 `saved` 이벤트 감지 → `flushTags(['products', 'product:123'])` → 해당 태그 캐시 삭제 → 다음 조회 시 `remember()` 콜백 실행으로 재생성

---

## TTL 중앙 관리

모든 캐시 TTL은 `g7_core_settings('cache.*')`로 중앙 관리합니다. 하드코딩 금지.

| 설정 키 | 기본값 | 용도 |
|---------|--------|------|
| `cache.default_ttl` | 86400 | 기본 TTL (24h) |
| `cache.layout_ttl` | 3600 | 레이아웃 캐시 TTL |
| `cache.seo_ttl` | 7200 | SEO 페이지 캐시 TTL |
| `cache.seo_sitemap_ttl` | 86400 | Sitemap 캐시 TTL |
| `cache.notification_ttl` | 3600 | 알림 정의/템플릿 캐시 TTL |
| `cache.extension_status_ttl` | 86400 | 확장 상태 캐시 TTL |
| `cache.geoip_ttl` | 86400 | GeoIP 캐시 TTL |
| `cache.version_check_ttl` | 3600 | 버전 검증 캐시 TTL |

```php
// 사용 예시
$ttl = (int) g7_core_settings('cache.layout_ttl', 3600);
$this->cache->remember($key, $callback, $ttl, $tags);
```

---

## API 레퍼런스

### 기본 CRUD

| 메서드 | 설명 |
|--------|------|
| `get(key, default)` | 캐시 조회 |
| `put(key, value, ttl)` | 캐시 저장 |
| `has(key)` | 존재 확인 |
| `forget(key)` | 삭제 |

### Remember 패턴

| 메서드 | 설명 |
|--------|------|
| `remember(key, callback, ttl, tags)` | 캐시 미스 시 콜백 실행 + 저장 |
| `rememberQuery(queryHash, callback, ttl, tags)` | 쿼리 해시 기반 캐싱 (`query:` 접두사) |
| `refresh(key, callback, ttl, tags)` | forget + remember 원자적 실행 |

### 벌크 연산

| 메서드 | 설명 |
|--------|------|
| `many(keys)` | 여러 키 한 번에 조회 |
| `putMany(values, ttl)` | 여러 키-값 한 번에 저장 |

### 무효화

| 메서드 | 설명 |
|--------|------|
| `flush()` | 이 드라이버 소속 전체 캐시 삭제 |
| `flushTags(tags)` | 특정 태그의 캐시만 삭제 |

### 메타

| 메서드 | 설명 |
|--------|------|
| `supportsTags()` | 태그 지원 여부 |
| `getStore()` | 현재 스토어 이름 |
| `withStore(store)` | 스토어 변경 (불변 복제) |
| `resolveKey(key)` | 전체 키 확인 (디버깅용) |

---

## StorageDriver와의 대응

| StorageDriver | CacheDriver | 비고 |
|--------------|-------------|------|
| `StorageInterface` | `CacheInterface` | 계약 |
| `CoreStorageDriver` | `CoreCacheDriver` | 코어용 |
| `ModuleStorageDriver` | `ModuleCacheDriver` | 모듈용 |
| `PluginStorageDriver` | `PluginCacheDriver` | 플러그인용 |
| `getStorage()` | `getCache()` | 추상 클래스 메서드 |
| `getStorageDisk()` | `getCacheStore()` | 오버라이드 가능 |
| `withDisk()` | `withStore()` | 불변 복제 |
| `$storageServices` | `$cacheServices` | ServiceProvider 배열 |
| `registerStorageBindings()` | `registerCacheBindings()` | DI 자동 바인딩 |
