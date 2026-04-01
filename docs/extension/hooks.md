# 훅 시스템 (Hook System)

> G7의 훅 시스템은 WordPress 스타일로 구현되어 코어 수정 없이 비즈니스 로직을 확장할 수 있습니다.

---

## TL;DR (5초 요약)

```text
1. Action 훅: doAction() - 부가 작업 (로그, 알림, 캐시)
2. Filter 훅: applyFilters() - 데이터 변형 (type => 'filter' 필수!)
3. 네이밍: [vendor-module].[entity].[action]_[timing]
4. 우선순위: 1-5(높음), 10(기본), 15+(낮음)
5. Service 패턴: before_* → filter_* → 로직 → after_*
```

---

## 목차

- [개요](#개요)
- [훅 타입](#훅-타입)
- [훅 네이밍 규칙](#훅-네이밍-규칙)
- [리스너 구현](#리스너-구현)
- [코어 리스너 자동 발견](#코어-리스너-자동-발견)
- [우선순위 가이드라인](#우선순위-가이드라인)
- [모듈에 리스너 등록](#모듈에-리스너-등록)
- [Service에서 훅 사용 패턴](#service에서-훅-사용-패턴)
- [필터 훅 리스너 예시](#필터-훅-리스너-예시)
- [훅 권한 시스템](#훅-권한-시스템)
- [관련 문서](#관련-문서)

---

## 개요

G7의 훅 시스템은 WordPress 스타일로 구현되어 코어 수정 없이 비즈니스 로직을 확장할 수 있습니다.

**핵심 파일 위치**: `app/Extension/HookManager.php`

---

## 훅 타입

### 1. Action Hooks (액션 훅)

**목적**: 특정 시점에 부가 작업 실행

**사용 예시**:
- 이메일 발송
- 활동 로그 기록 (→ [활동 로그 시스템](../backend/activity-log.md) 참조)
- 캐시 무효화
- 알림 전송

**패턴**:

```php
// Service에서 훅 실행
HookManager::doAction('sirsoft-ecommerce.product.after_create', $product, $data);
```

### 2. Filter Hooks (필터 훅)

**목적**: 데이터 변형 및 가공

**사용 예시**:
- 가격 계산
- 데이터 포맷 변경
- 권한 확인

**패턴**:

```php
// Service에서 필터 적용
$finalPrice = HookManager::applyFilters('sirsoft-ecommerce.product.calculate_price', $basePrice, $product);
```

---

## 훅 네이밍 규칙

### 표준 패턴

```text
[vendor-module].[entity].[action]_[timing]
```

### 타이밍 접미사

| 접미사     | 설명               |
| ---------- | ------------------ |
| `before_*` | 작업 실행 전       |
| `after_*`  | 작업 실행 후       |
| `on_*`     | 특정 이벤트 발생 시 |
| `filter_*` | 데이터 변형용 필터 |

### 예시

```text
# 생성/수정/삭제 (변경 작업)
sirsoft-ecommerce.product.before_create
sirsoft-ecommerce.product.after_create
sirsoft-ecommerce.product.after_update
sirsoft-ecommerce.product.filter_create_data

# 조회 작업
sirsoft-ecommerce.product.before_list
sirsoft-ecommerce.product.after_list
sirsoft-ecommerce.product.filter_list_query
sirsoft-ecommerce.product.filter_list_result
sirsoft-ecommerce.product.before_show
sirsoft-ecommerce.product.after_show
sirsoft-ecommerce.product.filter_show_result

# 이벤트/기타
sirsoft-ecommerce.order.on_payment_success
sirsoft-ecommerce.product.calculate_price

# 코어 훅
core.attachment.download
core.attachment.update
core.attachment.delete

# FormRequest Validation Rules 훅 (Filter)
core.user.create_validation_rules
core.user.update_validation_rules
core.role.store_validation_rules
core.role.update_validation_rules
core.permission.store_validation_rules
core.permission.update_validation_rules
core.menu.store_validation_rules
core.menu.update_validation_rules

# Layout Extension 훅
core.layout_extension.before_apply
core.layout_extension.after_apply

# SEO 렌더링 훅 (Filter)
core.seo.filter_context
core.seo.filter_meta
core.seo.filter_view_data
```

---

## 리스너 구현

### HookListenerInterface 사용

```php
<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProductCacheInvalidationListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // Action 훅: type 생략 시 기본값 'action'
            'sirsoft-ecommerce.product.after_create' => ['method' => 'handleProductChange', 'priority' => 10],
            'sirsoft-ecommerce.product.after_update' => ['method' => 'handleProductChange', 'priority' => 10],
            'sirsoft-ecommerce.product.after_delete' => ['method' => 'handleProductChange', 'priority' => 10],
        ];
    }

    /**
     * 상품 변경 시 캐시 무효화
     *
     * @param mixed ...$args
     * @return void
     */
    public function handleProductChange(...$args): void
    {
        // 모든 상품 목록 캐시 삭제
        Cache::forget('products.all');
        Cache::forget('products.active');

        // 특정 상품 캐시 삭제
        if (isset($args[0]->id)) {
            Cache::forget("product.{$args[0]->id}");
        }

        Log::info('상품 캐시가 무효화되었습니다.', [
            'product_id' => $args[0]->id ?? null,
        ]);
    }
}
```

---

## 코어 리스너 자동 발견

코어 애플리케이션의 훅 리스너는 `app/Listeners/` 디렉토리에서 **자동 발견**됩니다. `HookListenerInterface`를 구현한 클래스는 별도 등록 없이 자동으로 HookManager에 등록됩니다.

### 자동 발견 조건

| 조건 | 설명 |
|------|------|
| 위치 | `app/Listeners/` 및 모든 하위 디렉토리 |
| 인터페이스 | `HookListenerInterface` 구현 필수 |
| 등록 방식 | `CoreServiceProvider::boot()`에서 재귀 스캔 |

### 디렉토리 구조 예시

```text
app/Listeners/
├── ActivityLogListener.php           ✅ 자동 발견
├── CoreActivityLogListener.php       ✅ 자동 발견
├── ExtensionCompatibilityAlertListener.php  ✅ 자동 발견
├── Dashboard/
│   ├── DashboardModuleListener.php   ✅ 자동 발견 (하위 디렉토리)
│   └── DashboardStatsListener.php    ✅ 자동 발견 (하위 디렉토리)
└── UserLogin/
    └── UpdateLastLoginListener.php   ✅ 자동 발견 (하위 디렉토리)
```

### CoreServiceProvider 구현

```php
// app/Providers/CoreServiceProvider.php

private function registerCoreHookListeners(): void
{
    $listenersPath = app_path('Listeners');

    if (! is_dir($listenersPath)) {
        return;
    }

    // 재귀적으로 모든 PHP 파일 스캔
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($listenersPath, \RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // 파일 경로에서 클래스명 추출
        $relativePath = str_replace($listenersPath.DIRECTORY_SEPARATOR, '', $file->getPathname());
        $relativePath = str_replace('.php', '', $relativePath);
        $relativePath = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);
        $listenerClass = 'App\\Listeners\\'.$relativePath;

        // 클래스 존재 여부 확인
        if (! class_exists($listenerClass)) {
            Log::warning("코어 훅 리스너 클래스를 찾을 수 없습니다: {$listenerClass}");
            continue;
        }

        // HookListenerInterface 구현 여부 확인
        if (! in_array(HookListenerInterface::class, class_implements($listenerClass))) {
            continue;
        }

        $this->registerCoreHookListener($listenerClass);
    }
}
```

### 장점

| 장점 | 설명 |
|------|------|
| 자동 활성화 | 리스너 파일 생성만으로 훅 구독 시작 |
| ServiceProvider 수정 불필요 | 새 리스너 추가 시 코드 변경 없음 |
| 모듈/플러그인과 동일 패턴 | 일관된 아키텍처 |
| 메모리 효율 | 클로저 래퍼로 지연 인스턴스 생성 |

### 모듈/플러그인과의 차이점

| 항목 | 코어 리스너 | 모듈/플러그인 리스너 |
|------|------------|-------------------|
| 발견 방식 | 디렉토리 재귀 스캔 | `getHookListeners()` 메서드 |
| 위치 | `app/Listeners/**/*.php` | `modules/**/Listeners/`, `plugins/**/Listeners/` |
| 등록 주체 | `CoreServiceProvider` | `ModuleServiceProvider`, `PluginServiceProvider` |

---

## 우선순위 가이드라인

**우선순위 범위**: 1 ~ 100 (낮을수록 먼저 실행)

| 우선순위 | 용도       | 예시               |
| -------- | ---------- | ------------------ |
| 1-5      | 매우 높음  | 데이터 검증, 보안 체크 |
| 10       | 기본값     | 일반 리스너        |
| 15-20    | 낮음       | 알림, 로깅         |
| 25+      | 매우 낮음  | 분석, 통계         |

### 정렬 메커니즘

HookManager는 `ksort()`를 사용하여 우선순위별 정렬 후 실행합니다:

| 규칙 | 설명 |
| ---- | ---- |
| 정렬 방식 | `ksort()` — 숫자 오름차순 (낮을수록 먼저 실행) |
| 기본값 | `$priority = 10` (`addAction`, `addFilter` 공통) |
| 동일 우선순위 | 등록 순서대로 실행 (FIFO — 배열 push 순서 유지) |

```php
// 우선순위 5 → 10 → 20 순서로 실행
HookManager::addAction('order.created', $securityCheck, 5);
HookManager::addAction('order.created', $businessLogic, 10);
HookManager::addAction('order.created', $notification, 20);
```

---

## 모듈에 리스너 등록

### module.php에서 등록

```php
<?php

namespace Modules\Sirsoft\Ecommerce;

use App\Contracts\Extension\ModuleInterface;
use Modules\Sirsoft\Ecommerce\Listeners\ProductCacheInvalidationListener;
use Modules\Sirsoft\Ecommerce\Listeners\OrderNotificationListener;

class Module implements ModuleInterface
{
    // ... 기타 메서드들 ...

    /**
     * 훅 리스너 목록 반환
     *
     * @return array
     */
    public function getHookListeners(): array
    {
        return [
            ProductCacheInvalidationListener::class,
            OrderNotificationListener::class,
        ];
    }
}
```

---

## Service에서 훅 사용 패턴

```php
<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Repositories\ProductRepository;

class ProductService
{
    public function __construct(
        private ProductRepository $productRepository
    ) {}

    public function createProduct(array $data): Product
    {
        // 1. Before 훅 - 데이터 검증, 전처리
        HookManager::doAction('sirsoft-ecommerce.product.before_create', $data);

        // 2. 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-ecommerce.product.filter_create_data', $data);

        // 3. 비즈니스 로직 실행
        $product = $this->productRepository->create($data);

        // 4. After 훅 - 후처리, 알림, 캐시 등
        HookManager::doAction('sirsoft-ecommerce.product.after_create', $product, $data);

        return $product;
    }

    public function calculateProductPrice(Product $product): float
    {
        $basePrice = $product->price;

        // 필터 훅으로 가격 계산 (할인, 세금 등)
        $finalPrice = HookManager::applyFilters(
            'sirsoft-ecommerce.product.calculate_price',
            $basePrice,
            $product
        );

        return $finalPrice;
    }
}
```

---

## 필터 훅 리스너 예시

### `type` 필드 사용법

`getSubscribedHooks()` 메서드에서 `type` 필드를 사용하여 훅 타입을 명시해야 합니다.

| type 값 | 설명 | 기본값 |
|---------|------|--------|
| `action` | Action 훅 - 반환값 없음 | ✅ (생략 시 기본값) |
| `filter` | Filter 훅 - 반환값 필수 | |

**중요**: Filter 훅 리스너는 반드시 `'type' => 'filter'`를 명시해야 합니다. 명시하지 않으면 Action 훅으로 처리되어 **반환값이 무시**됩니다.

### Filter 훅 리스너 구현

```php
<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;

class ProductPriceCalculationListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            // Filter 훅은 반드시 'type' => 'filter' 명시
            'sirsoft-ecommerce.product.calculate_price' => [
                'method' => 'applyDiscounts',
                'priority' => 10,
                'type' => 'filter',  // 필수!
            ],
        ];
    }

    /**
     * 할인 적용
     *
     * @param float $price 기본 가격
     * @param Product $product 상품 객체
     * @return float 할인 적용된 가격
     */
    public function applyDiscounts(float $price, $product): float
    {
        // 10% 할인 적용 (예시)
        if ($product->category->name === 'Special') {
            $price = $price * 0.9;
        }

        return $price;  // 반드시 값을 반환해야 함
    }
}
```

### Action과 Filter 혼합 사용

하나의 리스너에서 Action 훅과 Filter 훅을 함께 구독할 수 있습니다:

```php
<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;

class UserNotificationSettingsListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            // Filter 훅: 데이터 변형 (type 필수)
            'core.user.filter_create_data' => [
                'method' => 'filterCreateData',
                'priority' => 10,
                'type' => 'filter',
            ],
            'core.user.filter_update_data' => [
                'method' => 'filterUpdateData',
                'priority' => 10,
                'type' => 'filter',
            ],
            'core.user.filter_resource_data' => [
                'method' => 'filterResourceData',
                'priority' => 10,
                'type' => 'filter',
            ],

            // Action 훅: 부가 작업 (type 생략 가능)
            'core.user.after_create' => [
                'method' => 'afterCreate',
                'priority' => 10,
                // type 생략 = 'action'
            ],
        ];
    }

    /**
     * 생성 데이터 필터: 모듈 필드 추출 후 제거
     *
     * @param array $data 요청 데이터
     * @return array 모듈 필드가 제거된 데이터
     */
    public function filterCreateData(array $data): array
    {
        // 모듈 필드 추출 및 임시 저장
        $moduleData = $this->extractModuleData($data);
        session(['module_data' => $moduleData]);

        // 모듈 필드 제거 후 반환
        return $this->removeModuleFields($data);
    }

    /**
     * 생성 후 액션: 임시 저장된 데이터로 모듈 데이터 저장
     *
     * @param User $user 생성된 사용자
     * @param array $originalData 원본 요청 데이터
     * @return void
     */
    public function afterCreate(User $user, array $originalData): void
    {
        $moduleData = session('module_data', []);
        session()->forget('module_data');

        if (!empty($moduleData)) {
            $this->saveModuleData($user->id, $moduleData);
        }
    }

    /**
     * API 응답 필터: 모듈 데이터 병합
     *
     * @param array $data API 응답 데이터
     * @param User $user 조회 대상 사용자
     * @return array 모듈 데이터가 병합된 응답
     */
    public function filterResourceData(array $data, User $user): array
    {
        $moduleData = $this->getModuleData($user->id);
        return array_merge($data, $moduleData);
    }
}
```

### SEO 훅 활용 예시 — 리뷰 플러그인이 JSON-LD에 리뷰 데이터 주입

```php
<?php

namespace Plugins\Sirsoft\Reviews\Listeners;

use App\Extension\Contracts\HookListenerInterface;

class SeoReviewMetaListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            'core.seo.filter_meta' => [
                'method' => 'injectReviewJsonLd',
                'priority' => 10,
                'type' => 'filter',  // 필수!
            ],
        ];
    }

    /**
     * SEO 메타에 리뷰 JSON-LD를 주입합니다.
     *
     * @param array $meta 메타 태그 배열
     * @param array $hookMeta 훅 메타 정보 (layoutName, moduleIdentifier 등)
     * @return array 수정된 메타 배열
     */
    public function injectReviewJsonLd(array $meta, array $hookMeta): array
    {
        // 상품 상세 페이지에서만 동작
        if ($hookMeta['moduleIdentifier'] !== 'sirsoft-ecommerce') {
            return $meta;
        }

        $context = $hookMeta['context'] ?? [];
        $productId = $context['route']['id'] ?? null;
        if (! $productId) {
            return $meta;
        }

        // 리뷰 데이터 조회 및 JSON-LD 주입
        $reviews = $this->getReviewAggregate($productId);
        if ($reviews && $meta['jsonLd']) {
            $jsonLd = json_decode($meta['jsonLd'], true);
            $jsonLd['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => $reviews['average'],
                'reviewCount' => $reviews['count'],
            ];
            $meta['jsonLd'] = json_encode($jsonLd, JSON_UNESCAPED_UNICODE);
        }

        return $meta;
    }
}
```

### 훅 타입별 동작 비교

| 구분 | Action 훅 | Filter 훅 |
|------|----------|-----------|
| `type` 필드 | 생략 가능 (`'action'` 기본값) | **필수** (`'type' => 'filter'`) |
| 반환값 | 무시됨 | **필수** (다음 필터로 전달) |
| HookManager 메서드 | `doAction()` | `applyFilters()` |
| 사용 목적 | 부가 작업 (로그, 알림, 캐시 등) | 데이터 변형 (필터링, 병합, 제거 등) |

---

## 훅 내부 메커니즘

### 중복 실행 방지 (가드 플래그)

HookManager는 `$dispatching` 배열을 사용하여 동일 훅의 중복 실행을 방지합니다. Laravel Event 시스템과의 하이브리드 동작에서 발생할 수 있는 무한 루프를 차단합니다.

```text
doAction('hook.name')
  → $dispatching['hook.name'] = true  (가드 설정)
  → Event::dispatch('hook.name')
  → 리스너 실행 중 doAction('hook.name') 재호출 시
    → $dispatching 체크 → 스킵 (중복 방지)
  → unset($dispatching['hook.name'])  (가드 해제)
```

### addAction/addFilter 내부 동작

```text
addAction()  → Event::listen() 등록 + 리스너 배열 관리
addFilter()  → Event::listen() 등록 + 리스너 배열 관리 (동일)
removeAction() / removeFilter() → Event::forget() + 배열에서 제거
```

---

## 훅 권한 시스템

G7은 훅 실행 시 **권한 체크**를 지원합니다. `permission_hooks` 테이블을 통해 특정 훅을 실행하려면 어떤 권한이 필요한지 매핑할 수 있습니다.

### 하이브리드 권한 체계

```text
┌─────────────────────────────────────────────────────────────┐
│                    권한 체크 레이어                          │
├─────────────────────────────────────────────────────────────┤
│  1단계: permission_hooks (기능 레벨)                        │
│         → 'core.attachment.download' 훅 실행 권한           │
│         → 이 기능 자체를 사용할 수 있는가?                   │
│         → 미매핑 시 모든 사용자 허용                         │
│         → 매핑 시 해당 퍼미션 보유자만 허용                  │
├─────────────────────────────────────────────────────────────┤
│  2단계: role_* (리소스 레벨)                                │
│         → 특정 파일/메뉴에 대한 접근 권한                    │
│         → 이 리소스를 읽을/수정할/삭제할 수 있는가?          │
│         → 현행 로직 유지                                    │
└─────────────────────────────────────────────────────────────┘
```

### permission_hooks 테이블

```php
Schema::create('permission_hooks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
    $table->string('hook_name')->comment('훅 이름 (예: core.attachment.download)');
    $table->timestamps();

    $table->unique(['permission_id', 'hook_name']);
    $table->index('hook_name');
});
```

### PermissionHook 모델

```php
// app/Models/PermissionHook.php

class PermissionHook extends Model
{
    protected $fillable = ['permission_id', 'hook_name'];

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    /**
     * 특정 훅에 매핑된 권한 ID 목록 조회
     */
    public static function getPermissionsForHook(string $hookName): array
    {
        return static::where('hook_name', $hookName)
            ->pluck('permission_id')
            ->toArray();
    }

    /**
     * 훅에 권한이 매핑되어 있는지 확인
     */
    public static function hasPermissionMapping(string $hookName): bool
    {
        return static::where('hook_name', $hookName)->exists();
    }
}
```

### HookManager의 권한 체크 메서드

```php
// app/Extension/HookManager.php

class HookManager
{
    /**
     * 훅 실행 전 권한 체크
     *
     * @param string $hookName 훅 이름
     * @param User|null $user 사용자 (null이면 비로그인)
     * @return bool 실행 허용 여부
     */
    public static function checkHookPermission(string $hookName, ?User $user): bool
    {
        // 1. 훅에 매핑된 권한이 없으면 모든 사용자 허용
        if (!PermissionHook::hasPermissionMapping($hookName)) {
            return true;
        }

        // 2. 비로그인 사용자는 권한 매핑된 훅 실행 불가
        if ($user === null) {
            return false;
        }

        // 3. 관리자는 모든 훅 실행 가능
        if ($user->hasRole('admin')) {
            return true;
        }

        // 4. 사용자의 역할에 해당 권한이 있는지 확인
        $requiredPermissionIds = PermissionHook::getPermissionsForHook($hookName);

        return $user->roles()
            ->whereHas('permissions', function ($query) use ($requiredPermissionIds) {
                $query->whereIn('permissions.id', $requiredPermissionIds);
            })
            ->exists();
    }

    /**
     * 권한 체크 후 Action 실행
     *
     * @param string $hookName 훅 이름
     * @param User|null $user 사용자
     * @param mixed ...$args 훅 인자
     * @return bool 실행 성공 여부
     */
    public static function doActionWithPermission(string $hookName, ?User $user, ...$args): bool
    {
        if (!static::checkHookPermission($hookName, $user)) {
            return false;
        }

        static::doAction($hookName, ...$args);
        return true;
    }

    /**
     * 권한 체크 후 Filter 적용
     *
     * @param string $hookName 훅 이름
     * @param User|null $user 사용자
     * @param mixed $value 필터링할 값
     * @param mixed ...$args 추가 인자
     * @return mixed 필터링된 값 (권한 없으면 원본 반환)
     */
    public static function applyFiltersWithPermission(string $hookName, ?User $user, $value, ...$args)
    {
        if (!static::checkHookPermission($hookName, $user)) {
            return $value;
        }

        return static::applyFilters($hookName, $value, ...$args);
    }
}
```

### 훅에 권한 매핑하기

```php
// 시더 또는 관리자 기능에서

// 'attachment.download' 권한을 'core.attachment.download' 훅에 매핑
$permission = Permission::where('identifier', 'attachment.download')->first();

PermissionHook::create([
    'permission_id' => $permission->id,
    'hook_name' => 'core.attachment.download',
]);
```

### 권한 매핑 해제 (모든 사용자 허용)

```php
PermissionHook::where('hook_name', 'core.attachment.download')->delete();
```

### Service에서 사용 예시

```php
// app/Services/AttachmentService.php

public function download(int $id, ?User $user): ?Attachment
{
    // 1단계: 기능 레벨 권한 체크 (permission_hooks)
    if (!HookManager::checkHookPermission('core.attachment.download', $user)) {
        throw new AuthorizationException(__('attachment.download_permission_denied'));
    }

    $attachment = $this->repository->findById($id);

    // 2단계: 리소스 레벨 권한 체크 (role_attachments)
    if (!$this->checkResourcePermission($user, $attachment, AttachmentPermissionType::Read)) {
        throw new AuthorizationException(__('attachment.resource_access_denied'));
    }

    return $attachment;
}
```

### 권한 체크 흐름 요약

```text
┌─────────────────────────────────────────────────────────────┐
│  checkHookPermission() 흐름                                 │
├─────────────────────────────────────────────────────────────┤
│  1. permission_hooks에 훅 매핑 확인                         │
│     → 매핑 없음: 모든 사용자 허용 (return true)             │
├─────────────────────────────────────────────────────────────┤
│  2. 비로그인 사용자                                          │
│     → 매핑 있으면 거부 (return false)                       │
├─────────────────────────────────────────────────────────────┤
│  3. 관리자(admin) 역할                                       │
│     → 모든 훅 실행 허용 (return true)                       │
├─────────────────────────────────────────────────────────────┤
│  4. 사용자 역할의 권한 확인                                  │
│     → 매핑된 권한 보유 여부 반환                            │
└─────────────────────────────────────────────────────────────┘
```

---

### SEO 캐시 무효화 리스너

모듈의 CRUD 훅에서 SEO 캐시를 무효화하는 패턴:

| 훅 | 리스너 메서드 | 무효화 대상 |
|----|-------------|-----------|
| `[module].product.after_create` | `onProductChange` | 해당 URL + 목록/카테고리 |
| `[module].product.after_update` | `onProductUpdate` | 해당 URL + 목록 |
| `[module].*.after_delete` | `onProductChange` | 전체 관련 캐시 |

캐시 무효화 시 `Cache::forget('seo:sitemap')`도 함께 호출

> 구현 상세: [seo-system.md](../backend/seo-system.md)

---

## 관련 문서

- [모듈 개발 기초](module-basics.md) - 모듈에서 훅 리스너 등록
- [Service-Repository 패턴](../backend/service-repository.md) - Service에서 훅 실행 패턴
- [활동 로그 시스템](../backend/activity-log.md) - 활동 로그 Listener 작성 패턴, Per-Item Bulk 로깅 규칙
- [권한 시스템](permissions.md) - Permission, Role 체계
- [확장 시스템 인덱스](index.md) - 전체 확장 시스템 개요
