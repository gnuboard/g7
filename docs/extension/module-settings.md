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
9. [관련 문서](#9-관련-문서)

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

---

## 9. 관련 문서

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
