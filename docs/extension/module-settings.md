# 모듈 환경설정 시스템 개발 가이드

> 이 문서는 모듈에서 환경설정 시스템을 구현하는 방법을 설명합니다.

---

## 목차

1. [개요](#1-개요)
2. [디렉토리 구조](#2-디렉토리-구조)
3. [defaults.json 작성](#3-defaultsjson-작성)
4. [SettingsService 구현](#4-settingsservice-구현)
5. [SettingsController 구현](#5-settingscontroller-구현)
6. [API 라우트 설정](#6-api-라우트-설정)
7. [레이아웃 연동](#7-레이아웃-연동)
8. [백엔드에서 설정 조회](#8-백엔드에서-설정-조회)
9. [카탈로그 병합 설정의 공개 응답](#9-카탈로그-병합-설정의-공개-응답)
10. [항목 단위 보충 병합과 삭제 의도](#10-항목-단위-보충-병합과-삭제-의도)
11. [관련 문서](#11-관련-문서)

---

## 1. 개요

### 1.1 핵심 원칙

```
중요: 모듈 환경설정은 모듈의 책임
✅ 코어는 ModuleSettingsService + 헬퍼 함수(module_setting/module_settings)를 제공
✅ 모듈이 ModuleSettingsInterface를 구현하면 코어가 자동 검색하여 위임
✅ ModuleSettingsInterface 미구현 모듈은 코어 기본 구현(단일 setting.json) 사용
✅ 설정 파일은 모듈별 격리된 경로에 저장
```

### 1.2 설정 조회 흐름

```
module_setting('vendor-module', 'key')
  → ModuleSettingsService::get()
    → resolveModuleService() — 모듈별 설정 서비스 자동 검색
      → 1순위: Modules\{Vendor}\{Module}\Contracts\{Module}SettingsServiceInterface (바인딩)
      → 2순위: Modules\{Vendor}\{Module}\Services\{Module}SettingsService (클래스)
    → 모듈 서비스 발견 시: 위임 (getSetting/getAllSettings)
    → 미발견 시: 코어 기본 구현 (defaults.json + setting.json)
```

### 1.3 설계 원칙

- **격리성**: 모듈별로 독립적인 설정 저장 경로
- **일관성**: 코어 환경설정과 동일한 패턴
- **유연성**: 카테고리별 설정 분리 가능
- **보안성**: frontend_schema를 통한 민감정보 제어

---

## 2. 디렉토리 구조

### 2.1 모듈 설정 파일 위치

```
modules/{vendor-module}/
├── config/
│   └── settings/
│       └── defaults.json    ← 기본 설정값 정의
├── src/
│   ├── Services/
│   │   └── {Module}SettingsService.php    ← 설정 서비스
│   ├── Http/
│   │   └── Controllers/
│   │       └── Admin/
│   │           └── {Module}SettingsController.php    ← 설정 API
│   └── routes/
│       └── api.php    ← API 라우트
└── resources/
    └── layouts/
        └── admin/
            └── admin_{module}_settings.json    ← 설정 UI
```

### 2.2 설정 저장 경로

```
storage/app/modules/{vendor-module}/settings/
├── basic_info.json
├── language_currency.json
└── seo.json
```

---

## 3. defaults.json 작성

### 3.1 기본 구조

```json
{
  "_meta": {
    "version": "1.0.0",
    "description": "모듈 설명",
    "categories": ["basic_info", "language_currency", "seo"]
  },
  "defaults": {
    "basic_info": {
      "field_name": "default_value"
    }
  },
  "frontend_schema": {
    "basic_info": {
      "expose": true,
      "fields": {
        "field_name": { "expose": true }
      }
    }
  }
}
```

### 3.2 _meta 섹션

| 필드 | 타입 | 설명 |
|------|------|------|
| version | string | 설정 스키마 버전 |
| description | string | 모듈 설명 |
| categories | array | 설정 카테고리 목록 |

### 3.3 defaults 섹션

카테고리별 기본값을 정의합니다.

```json
{
  "defaults": {
    "basic_info": {
      "shop_name": "",
      "route_path": "shop",
      "no_route": false
    },
    "seo": {
      "meta_main_title": "{site_name} - {commerce_name}",
      "seo_site_main": true
    }
  }
}
```

### 3.4 frontend_schema 섹션

프론트엔드에 노출할 필드를 제어합니다.

```json
{
  "frontend_schema": {
    "basic_info": {
      "expose": true,
      "fields": {
        "shop_name": { "expose": true },
        "api_key": { "expose": false, "sensitive": true }
      }
    },
    "payment": {
      "expose": false
    }
  }
}
```

| 속성 | 설명 |
|------|------|
| expose | 프론트엔드 노출 여부 |
| sensitive | 민감 정보 여부 |

---

## 4. SettingsService 구현

### 4.1 ModuleSettingsInterface 구현

```php
<?php

namespace Modules\Vendor\Module\Services;

use App\Contracts\Extension\ModuleSettingsInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

class ModuleSettingsService implements ModuleSettingsInterface
{
    private const MODULE_IDENTIFIER = 'vendor-module';
    private ?array $defaults = null;
    private ?array $settings = null;

    public function getSettingsDefaultsPath(): ?string
    {
        $path = $this->getModulePath().'/config/settings/defaults.json';
        return file_exists($path) ? $path : null;
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        $settings = $this->getAllSettings();
        return Arr::get($settings, $key, $default);
    }

    public function setSetting(string $key, mixed $value): bool
    {
        $settings = $this->getAllSettings();
        Arr::set($settings, $key, $value);
        $parts = explode('.', $key);
        $category = $parts[0];
        return $this->saveCategorySettings($category, $settings[$category] ?? []);
    }

    public function getAllSettings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $defaults = $this->getDefaults();
        $categories = $defaults['_meta']['categories'] ?? [];
        $defaultValues = $defaults['defaults'] ?? [];

        $settings = [];
        foreach ($categories as $category) {
            $categoryDefaults = $defaultValues[$category] ?? [];
            $savedSettings = $this->loadCategorySettings($category);
            $settings[$category] = array_merge($categoryDefaults, $savedSettings);
        }

        $this->settings = $settings;
        return $settings;
    }

    public function getSettings(string $category): array
    {
        $allSettings = $this->getAllSettings();
        return $allSettings[$category] ?? [];
    }

    public function saveSettings(array $settings): bool
    {
        $success = true;
        foreach ($settings as $category => $categorySettings) {
            if (str_starts_with($category, '_')) {
                continue;
            }
            if (!$this->saveCategorySettings($category, $categorySettings)) {
                $success = false;
            }
        }
        $this->settings = null;
        return $success;
    }

    public function getFrontendSettings(): array
    {
        $defaults = $this->getDefaults();
        $frontendSchema = $defaults['frontend_schema'] ?? [];
        $allSettings = $this->getAllSettings();

        $frontendSettings = [];
        foreach ($frontendSchema as $category => $schema) {
            if (!($schema['expose'] ?? false)) {
                continue;
            }
            $categorySettings = $allSettings[$category] ?? [];
            $fields = $schema['fields'] ?? [];

            if (empty($fields)) {
                $frontendSettings[$category] = $categorySettings;
                continue;
            }

            $exposedFields = [];
            foreach ($fields as $fieldName => $fieldSchema) {
                if ($fieldSchema['expose'] ?? false) {
                    $exposedFields[$fieldName] = $categorySettings[$fieldName] ?? null;
                }
            }
            if (!empty($exposedFields)) {
                $frontendSettings[$category] = $exposedFields;
            }
        }
        return $frontendSettings;
    }

    private function getModulePath(): string
    {
        return base_path('modules/'.self::MODULE_IDENTIFIER);
    }

    private function getStoragePath(): string
    {
        return storage_path('app/modules/'.self::MODULE_IDENTIFIER.'/settings');
    }

    // 나머지 private 메서드 구현...
}
```

### 4.2 분리 입력 필드 처리

전화번호, 사업자번호 등 분리 입력 필드 처리:

```php
private function processSplitFields(string $category, array $settings): array
{
    if ($category !== 'basic_info') {
        return $settings;
    }

    // 사업자등록번호 병합
    if (isset($settings['business_number_1'])) {
        $parts = [
            $settings['business_number_1'] ?? '',
            $settings['business_number_2'] ?? '',
            $settings['business_number_3'] ?? '',
        ];
        $settings['business_number'] = implode('-', array_filter($parts));
        unset(
            $settings['business_number_1'],
            $settings['business_number_2'],
            $settings['business_number_3']
        );
    }

    return $settings;
}
```

---

## 5. SettingsController 구현

```php
<?php

namespace Modules\Vendor\Module\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Vendor\Module\Services\ModuleSettingsService;

class ModuleSettingsController extends AdminBaseController
{
    public function __construct(
        private ModuleSettingsService $settingsService
    ) {
        parent::__construct();
    }

    public function index(): JsonResponse
    {
        $settings = $this->settingsService->getFrontendSettings();
        return $this->success('settings.fetch_success', $settings);
    }

    public function show(string $category): JsonResponse
    {
        $settings = $this->settingsService->getSettings($category);
        return $this->success('settings.fetch_success', [
            'category' => $category,
            'settings' => $settings,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $result = $this->settingsService->saveSettings($request->all());

        if ($result) {
            $updatedSettings = $this->settingsService->getFrontendSettings();
            return $this->success('settings.save_success', $updatedSettings);
        }

        return $this->error('settings.save_failed');
    }
}
```

---

## 6. API 라우트 설정

### 6.1 라우트 파일 위치

```
modules/{vendor-module}/src/routes/api.php
```

### 6.2 라우트 정의

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Vendor\Module\Http\Controllers\Admin\ModuleSettingsController;

/*
|--------------------------------------------------------------------------
| Module API Routes
|--------------------------------------------------------------------------
|
| URL prefix: 'api/modules/{module-name}'
| Name prefix: 'api.modules.{module-name}.'
|
*/

Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('settings', [ModuleSettingsController::class, 'index'])
        ->name('admin.settings.index');

    Route::put('settings', [ModuleSettingsController::class, 'store'])
        ->name('admin.settings.store');

    Route::get('settings/{category}', [ModuleSettingsController::class, 'show'])
        ->name('admin.settings.show');
});
```

### 6.3 최종 API 경로

| Method | 경로 | 설명 |
|--------|------|------|
| GET | `/api/modules/{id}/admin/settings` | 전체 설정 조회 |
| PUT | `/api/modules/{id}/admin/settings` | 설정 저장 |
| GET | `/api/modules/{id}/admin/settings/{category}` | 카테고리별 조회 |

---

## 7. 레이아웃 연동

### 7.1 data_sources 설정

```json
{
  "data_sources": [
    {
      "id": "settings",
      "type": "api",
      "endpoint": "/api/modules/{module-id}/admin/settings",
      "method": "GET",
      "auto_fetch": true,
      "auth_required": true,
      "initLocal": "form",
      "refetchOnMount": true
    }
  ],
  "state": {
    "isSaving": false,
    "hasChanges": false,
    "form": {}
  }
}
```

### 7.2 저장 버튼 액션

```json
{
  "handler": "apiCall",
  "auth_required": true,
  "target": "/api/modules/{module-id}/admin/settings",
  "params": {
    "method": "PUT",
    "body": "{{_local.form}}"
  }
}
```

---

## 8. 백엔드에서 설정 조회

### 8.1 헬퍼 함수 사용 (권장)

`module_setting()` / `module_settings()`는 내부적으로 `ModuleSettingsService`를 사용합니다.
모듈별 설정 서비스(`{Module}SettingsService`)가 존재하면 자동으로 위임합니다.

```php
// 단일 설정값 조회 (도트 노테이션 지원)
$shopName = module_setting('vendor-module', 'basic_info.shop_name', '기본값');

// 전체 설정 조회
$allSettings = module_setting('vendor-module');

// 카테고리 전체 조회
$seoSettings = module_settings('vendor-module', 'seo');

// 전체 설정 조회
$allSettings = module_settings('vendor-module');
```

### 8.2 ModuleSettingsService 직접 사용

```php
use App\Services\ModuleSettingsService;

$service = app(ModuleSettingsService::class);

// 단일 설정값 조회
$value = $service->get('vendor-module', 'basic_info.shop_name', '기본값');

// 전체 설정 조회
$allSettings = $service->get('vendor-module');
```

### 8.3 모듈 내부에서 자체 서비스 사용

모듈 내부 Service/Controller에서는 자체 설정 서비스를 직접 주입하여 사용할 수 있습니다.
모듈 전용 메서드(예: `getStockDeductionTiming()`)가 필요한 경우 이 방식을 사용합니다.

```php
// 모듈 내부 — 모듈 전용 메서드 접근이 필요한 경우
use Modules\Vendor\Module\Services\ModuleSettingsService;

public function __construct(
    private ModuleSettingsService $settingsService
) {}

// ModuleSettingsInterface 메서드 + 모듈 전용 메서드 모두 사용 가능
$value = $this->settingsService->getSetting('basic_info.shop_name');
$timing = $this->settingsService->getStockDeductionTiming($method);
```

> **판단 기준**: `getSetting()` 단일 호출만 필요하면 `module_setting()` 헬퍼 사용, 모듈 전용 메서드가 필요하면 서비스 직접 주입

### 8.4 저장하면 config 미러를 다시 채운다

`g7_module_settings()` 가 읽는 값은 in-memory config 미러(`g7_settings.modules.{id}`)다. 이 미러는 부팅 때 채워지므로, 저장 경로가 아무것도 하지 않으면 **그 프로세스는 저장 후에도 옛 값을 계속 읽는다.**

php-fpm 은 요청마다 부팅해 문제가 드러나지 않는다. 큐 워커 · `schedule:work` · Reverb 처럼 프로세스가 상주하는 환경에서만 나타나고, 예외도 경고도 로그도 남지 않는다 — 운영자는 저장했는데 배경 작업만 옛 설정으로 도는 상태가 된다.

모듈 설정 저장에는 코어 공통 지점이 없다(각 모듈의 설정 서비스가 직접 저장한다). 그래서 **자기 캐시를 비우는 모든 자리에서** 미러 재채움을 함께 호출한다.

```php
public function saveSettings(array $settings): bool
{
    // ... 저장 ...

    $this->settings = null;                                   // 자기 캐시 비우기
    g7_refresh_module_settings_config('vendor-module');       // config 미러 다시 채우기

    return true;
}

public function clearCache(): void
{
    $this->settings = null;
    g7_refresh_module_settings_config('vendor-module');
}
```

호출 지점은 저장 메서드 하나가 아니다 — 초기화·복원·부분 저장 등 **값을 바꾸는 자리 전부**다. 한 곳만 빠지면 그 경로로 저장했을 때만 미러가 옛 값으로 남는다.

- 이 헬퍼는 코어 7.0.7 부터 제공된다. 사용하는 모듈은 `module.json` 의 `requires.g7_version` 을 그에 맞춰 올린다.
- 스키마에 `sensitive: true` 로 선언한 필드는 미러에 담기지 않는다. 민감값은 자체 설정 서비스(복호화 경로)로 읽는다.
- 배경과 코어 축(코어/플러그인)의 처리는 [admin-settings-access.md](../backend/admin-settings-access.md) "config 미러 갱신 시점" 참조.

### 8.5 저장 완료 훅 발화

모듈 설정 저장을 알리는 코어 훅은 `core.module_settings.after_save` 이며, payload 는
`($identifier, [category => fields], $result)` 다. SEO 캐시 무효화·활동 로그 등 코어와 타 확장의
리스너가 이 훅을 구독한다.

발화 지점은 **관리자 컨트롤러**다 — 설정 서비스가 아니다.

- 훅의 의미가 "관리자가 설정을 저장했다" 이다. 서비스에 두면 내부 저장 호출(시드·마이그레이션·
  테스트 픽스처) 전부가 활동 로그와 캐시 무효화를 유발한다.
- 각 모듈의 설정은 그 모듈의 SettingsService 가 직접 파일에 쓰므로, 코어에는 이 훅을 발화할
  공통 지점이 없다. 모듈이 자기 컨트롤러의 저장 성공 분기에서 직접 발화한다.

```php
if ($result) {
    HookManager::doAction('core.module_settings.after_save', 'vendor-module', $settings, $result);
}
```

단건 저장 경로(dot-key)는 payload 를 카테고리 하위 구조로 되돌려 벌크 저장과 같은 형태로
맞춘다(`Arr::set($payload, $key, $value)`). 구독 리스너가 카테고리 기준으로 관심 키를 찾으므로,
평탄한 dot-key 를 그대로 넘기면 그 리스너들이 아무것도 감지하지 못한다.

---

## 9. 카탈로그 병합 설정의 공개 응답

설정 항목의 목록을 다른 확장이 훅으로 등록하는 경우(결제수단, 배송사, 발급 제공자 등), 저장된 값은 그 카탈로그와 병합되어 응답에 실린다. 운영자가 저장한 값(`is_active`, 정렬 순서 등)과 확장이 제공하는 정의(이름, 아이콘, 지원 범위)가 항목마다 합쳐지는 구조다.

### 9.1 고아 항목이 생기는 조건

병합의 두 입력은 생명주기가 다르다. 저장값은 파일에 남지만 카탈로그는 요청 시점에 훅으로 다시 만들어지므로, 다음 상황에서 **저장값만 남고 카탈로그에서는 사라진 항목**이 생긴다.

- 공급 확장을 삭제했다
- 공급 확장을 비활성화했다
- 확장이 살아 있지만 자기 기능 토글을 꺼서 해당 항목 등록을 중단했다

병합부는 이런 항목에 `_orphaned` 표시를 붙여 돌려준다. 이때 저장값의 `is_active` 는 참 그대로 남아 있다 — 운영자가 끈 적이 없기 때문이다.

### 9.2 관리자 응답과 공개 응답의 처리가 다르다

| 응답 | 고아 항목 | 이유 |
| --- | --- | --- |
| 관리자 설정 조회 | **포함** | 운영자가 그 항목이 남아 있음을 확인하고 지울 수 있어야 한다. 화면은 `_orphaned` 를 읽어 편집 불가로 표시한다 |
| 공개(소비) 조회 | **제거** | 공급 확장이 더 이상 제공하지 않는 항목이다. 내보내면 사용자가 선택할 수 있게 된다 |

```php
public function getPublicPaymentSettings(): array
{
    $orderSettings = $this->getSettings('order_settings');

    if (isset($orderSettings['payment_methods']) && is_array($orderSettings['payment_methods'])) {
        $orderSettings['payment_methods'] = array_values(array_filter(
            $orderSettings['payment_methods'],
            fn ($method) => ! ($method['_orphaned'] ?? false)
        ));
    }

    return $orderSettings;
}
```

`array_values()` 로 인덱스를 재정렬한다. 중간 항목을 지우면 키가 비연속이 되고, 그 배열은 JSON 객체로 직렬화되어 화면의 반복 렌더가 깨진다.

### 9.3 차단 지점은 공개 API 한 곳이다

소비 화면(레이아웃 JSON)의 필터에 맡기지 않는다. 같은 데이터를 그리는 템플릿이 여러 개면 필터가 화면 수만큼 복제되고, 한 곳만 빠져도 같은 결함이 그 화면에서만 남는다.

같은 데이터를 내보내는 공개 엔드포인트가 둘 이상이면 **전부 같은 공개 게터를 경유**해야 한다. 한쪽이 raw `getSettings()` 를 쓰면 그 경로만 조용히 뚫린다.

```php
// ❌ 엔드포인트마다 다른 게터 — checkout 경로만 고아 항목이 새어 나간다
$orderSettings = $this->settingsService->getSettings('order_settings');

// ✅ 공개 엔드포인트는 모두 공개 게터를 경유
$orderSettings = $this->settingsService->getPublicPaymentSettings();
```

### 9.4 진단

이 결함은 예외도 경고도 로그도 남기지 않는다. 관리자 화면은 고아 표시로 정상 차단하고 있어, 관리자 응답과 공개 응답을 나란히 놓고 항목 수를 비교하기 전에는 드러나지 않는다.

```bash
# 두 응답의 항목 id 목록이 다르면, 그 차이가 고아 항목이다
GET /api/modules/{id}/admin/settings      # 고아 포함 (정상)
GET /api/modules/{id}/settings/payment    # 고아 제외 (정상)
```

---

## 10. 항목 단위 보충 병합과 삭제 의도

`defaults.json` 의 목록 설정(정수키 리스트)은 저장 시 `array_merge` 가 병합이 아니라 통째 교체를 수행한다. 그래서 관리자가 목록에서 일부 항목을 빼고 저장하면 defaults 항목이 저장본에서 사라지고, 그 상태가 그대로 유지된다. 이 통째 교체가 "관리자가 편집한 목록이 그대로 남는다" 는 뜻이라 대부분의 목록에는 옳은 동작이다.

문제는 여기에 **항목 단위 보충 병합**(저장본에 없는 defaults 항목을 code/key 기준으로 다시 채워 넣는 처리)을 얹을 때다. 보충은 보통 "의도치 않은 소실 복구" 를 목적으로 도입되는데, 소실과 삭제는 저장본에서 똑같이 "그 항목이 없다" 로 보이기 때문에 구분할 근거가 없다. 그 결과 관리자의 의도적 삭제까지 되돌아간다.

| ❌ 금지 | ✅ 올바른 사용 |
| --- | --- |
| 보충 병합이 저장본에 없는 defaults 항목을 무조건 다시 채움 | 저장 시점에 삭제 의도를 도출해 저장본에 기록하고, 병합이 그 기록을 존중 |
| 삭제 기록을 클라이언트 제출값에서 받음 | 서버가 `defaults 항목 − 제출 항목 − 삭제 불가 항목` 으로 재계산 (제출된 같은 키는 버린다) |
| 그 목록 키가 제출되지 않은 저장에서 기록만 이월 | 목록 값과 기록을 **함께** 이월 — 저장이 파일 통째 교체라 목록 키가 사라지면 defaults 가 다시 들어와 삭제가 전부 부활한다 |
| 항상 살아 있어야 하는 항목(기본 통화 등)까지 기록 대상에 포함 | 그 항목은 기록에서 제외해 보충 경로로 항상 생존시킨다 |
| 삭제 기록을 공개 응답에 그대로 노출 | `frontend_schema` 의 필드 화이트리스트로 차단 (관리자 응답에는 남겨도 무해) |
| 기록 배열을 비연속 키로 저장 | `array_values()` 로 재정렬 — 비연속 키는 JSON 객체로 직렬화되어 화면 반복이 깨진다 |

이 결함은 예외도 경고도 로그도 남기지 않는다. 저장 요청은 200 을 반환하고, 그 응답 본문에 이미 삭제한 항목이 되살아나 있다.

보충 병합과 통째 교체를 혼동하지 않도록, 목록 설정을 다룰 때는 다음 세 가지를 구분해 판정한다.

| 모델 | 조회 시 처리 | 화면 | 삭제 의미 |
| --- | --- | --- | --- |
| 통째 교체 | 저장본을 그대로 사용 | 항목 추가·삭제 | 삭제가 그대로 유지된다 |
| 항목 단위 보충 | 저장본에 없는 defaults 항목을 채움 | 항목 추가·삭제 | **삭제 기록이 없으면 되돌아간다** |
| 카탈로그 병합 | 확장이 등록한 카탈로그와 저장값을 합침 | `is_active` 토글 | 삭제 개념이 없다 (9장 참조) |

---

## 11. 관련 문서

- [모듈 기초](module-basics.md) - 모듈 구조, AbstractModule
- [모듈 라우트](module-routing.md) - API 라우트 규칙
- [모듈 레이아웃](module-layouts.md) - 레이아웃 등록
- [모듈 다국어](module-i18n.md) - 다국어 지원

---

## 체크리스트

모듈 환경설정 구현 시 확인 사항:

- [ ] `config/settings/defaults.json` 생성
- [ ] `ModuleSettingsInterface` 구현 서비스 생성
- [ ] 설정 컨트롤러 생성
- [ ] API 라우트 등록
- [ ] 레이아웃에 `data_sources` 추가
- [ ] 저장 버튼 액션 연결
- [ ] `frontend_schema`로 민감정보 필터링 설정
- [ ] 값을 바꾸는 모든 자리에서 `g7_refresh_module_settings_config()` 호출 (8.4)
