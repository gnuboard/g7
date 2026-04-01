# 템플릿 라우트/언어 파일 규칙

> **관련 문서**: [index.md](index.md) | [template-basics.md](template-basics.md) | [template-security.md](template-security.md)

---

## 목차

1. [라우트 정의 (routes.json)](#라우트-정의-routesjson)
2. [템플릿 언어 파일 경로 규칙](#템플릿-언어-파일-경로-규칙)
3. [TemplateService 구현](#templateservice-구현)
4. [API Controller 구현](#api-controller-구현)
5. [라우트 정의](#라우트-정의)
6. [프론트엔드에서 사용](#프론트엔드에서-사용)
7. [보안 규칙](#보안-규칙)
8. [에러 처리](#에러-처리)
9. [모범 사례](#모범-사례)
10. [template.json과의 관계](#templatejson과의-관계)

---

## 라우트 정의 (routes.json)

**위치**: `/templates/[vendor-template]/routes.json`

### 구조

```json
{
  "routes": [
    {
      "path": "/admin/dashboard",
      "layout_name": "admin_dashboard",
      "permissions": ["core.dashboard.view"],
      "meta": {
        "title": "대시보드",
        "icon": "dashboard"
      }
    },
    {
      "path": "/admin/users",
      "layout_name": "admin_users_list",
      "permissions": ["core.users.view"],
      "meta": {
        "title": "사용자 관리",
        "icon": "users"
      }
    }
  ]
}
```

### 필드 설명

| 필드 | 설명 |
|------|------|
| `path` | 라우트 경로 (Laravel 라우트와 일치) |
| `layout_name` | `template_layouts` 테이블의 레이아웃명 |
| `permissions` | 필요한 권한 배열 (빈 배열 시 인증만 필요) |
| `meta` | 메타 정보 (제목, 아이콘 등) |

---

## 템플릿 언어 파일 경로 규칙

```
중요: 템플릿 언어 파일은 API를 통해 제공
✅ 필수: 경로 트래버설 방지 및 로케일 검증
```

### 핵심 원칙

| 항목 | 규칙 |
|------|------|
| **파일 위치** | `/templates/{vendor-template}/lang/{locale}.json` |
| **로케일 형식** | ISO 639-1 (2자리 소문자, 예: `ko`, `en`, `ja`) |
| **제공 방식** | API 엔드포인트 (`/api/templates/{identifier}/lang/{locale}`) |
| **검증** | supported_locales 확인, 경로 탈출 방지 |

### 언어 파일 구조

```json
// /templates/sirsoft-admin_basic/lang/ko.json
{
  "dashboard": {
    "title": "대시보드",
    "subtitle": "시스템 개요",
    "stats": {
      "users": "총 사용자 수",
      "orders": "주문 수"
    }
  },
  "templates": {
    "list": "템플릿 목록",
    "install": "템플릿 설치",
    "activate": "활성화"
  }
}
```

```json
// /templates/sirsoft-admin_basic/lang/en.json
{
  "dashboard": {
    "title": "Dashboard",
    "subtitle": "System Overview",
    "stats": {
      "users": "Total Users",
      "orders": "Orders"
    }
  },
  "templates": {
    "list": "Template List",
    "install": "Install Template",
    "activate": "Activate"
  }
}
```

---

## TemplateService 구현

```php
<?php

namespace App\Services\Template;

use Illuminate\Support\Facades\File;

class TemplateService
{
    /**
     * 템플릿 언어 파일 경로 반환
     *
     * @param string $identifier 템플릿 식별자
     * @param string $locale 로케일 (ko, en 등)
     * @return array{success: bool, filePath?: string, error?: string}
     */
    public function getLanguageFilePath(string $identifier, string $locale): array
    {
        // 1. 템플릿 활성화 여부 확인
        if (!$this->isTemplateActive($identifier)) {
            return ['success' => false, 'error' => 'Template not active'];
        }

        // 2. 로케일 형식 검증 (ISO 639-1: 2자리 소문자)
        if (!preg_match('/^[a-z]{2}$/', $locale)) {
            return ['success' => false, 'error' => 'Invalid locale format'];
        }

        // 3. supported_locales 확인
        $supportedLocales = config('app.supported_locales', ['ko', 'en']);
        if (!in_array($locale, $supportedLocales)) {
            return ['success' => false, 'error' => 'Locale not supported'];
        }

        // 4. 파일 경로 생성
        $basePath = base_path("templates/{$identifier}/lang");
        $filePath = "{$basePath}/{$locale}.json";

        // 5. 경로 트래버설 방지
        $realPath = realpath($filePath);
        $realBasePath = realpath($basePath);

        if (!$realPath || !str_starts_with($realPath, $realBasePath)) {
            return ['success' => false, 'error' => 'Invalid file path'];
        }

        // 6. 파일 존재 확인
        if (!File::exists($realPath) || !is_file($realPath)) {
            return ['success' => false, 'error' => 'Language file not found'];
        }

        return ['success' => true, 'filePath' => $realPath];
    }

    /**
     * 템플릿 언어 데이터 로드
     *
     * @param string $identifier 템플릿 식별자
     * @param string $locale 로케일
     * @return array
     */
    public function getLanguageData(string $identifier, string $locale): array
    {
        $result = $this->getLanguageFilePath($identifier, $locale);

        if (!$result['success']) {
            return [];
        }

        $content = File::get($result['filePath']);
        return json_decode($content, true) ?? [];
    }
}
```

---

## API Controller 구현

```php
<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Services\Template\TemplateService;
use Illuminate\Http\JsonResponse;

class TemplateLanguageController extends PublicBaseController
{
    public function __construct(
        private TemplateService $templateService
    ) {}

    /**
     * 템플릿 언어 파일 제공
     *
     * @param string $identifier 템플릿 식별자
     * @param string $locale 로케일 (ko, en 등)
     * @return JsonResponse
     */
    public function getLanguageFile(string $identifier, string $locale): JsonResponse
    {
        $data = $this->templateService->getLanguageData($identifier, $locale);

        if (empty($data)) {
            return $this->notFound('messages.template.language_file_not_found');
        }

        return response()->json($data, 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=3600',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}
```

---

## 라우트 정의

```php
// routes/api.php
Route::get('/templates/{identifier}/lang/{locale}', [TemplateLanguageController::class, 'getLanguageFile'])
    ->where('locale', '[a-z]{2}')
    ->name('api.templates.language');
```

**주요 포인트**:
- `where('locale', '[a-z]{2}')`: ISO 639-1 형식만 허용
- 라우트 레벨에서 잘못된 형식 차단

---

## 프론트엔드에서 사용

### React 컴포넌트

```javascript
// React 컴포넌트에서 언어 파일 로드
const loadLanguageFile = async (identifier, locale) => {
  const response = await fetch(`/api/templates/${identifier}/lang/${locale}`);

  if (!response.ok) {
    console.error('Failed to load language file');
    return {};
  }

  return await response.json();
};

// 사용 예시
const translations = await loadLanguageFile('sirsoft-admin_basic', 'ko');
console.log(translations.dashboard.title); // "대시보드"
```

### Blade 템플릿

```php
<!-- resources/views/app.blade.php -->
<script>
    window.TEMPLATE_LANG_URL = "{{ route('api.templates.language', [
        'identifier' => $activeTemplate->identifier,
        'locale' => app()->getLocale()
    ]) }}";
</script>
```

---

## 보안 규칙

### 허용 (✅)

- 활성화된 템플릿만 접근 가능
- ISO 639-1 형식 검증 (정규표현식: `/^[a-z]{2}$/`)
- supported_locales 화이트리스트 확인
- 경로 트래버설 방지 (`realpath()`, `str_starts_with()`)
- 파일 확장자 검증 (`.json`만 허용)
- 적절한 캐싱 헤더 (`Cache-Control: max-age=3600`)

### 금지 (❌)

- 비활성 템플릿 언어 파일 접근 금지
- 임의의 파일 경로 접근 금지
- supported_locales 외 로케일 접근 금지

---

## 에러 처리

| 요청 | 응답 | 사유 |
|------|------|------|
| `GET /api/templates/sirsoft-admin_basic/lang/ko-KR` | 404 Not Found | 라우트 패턴 불일치 |
| `GET /api/templates/sirsoft-admin_basic/lang/ja` | 404 Not Found | supported_locales에 없음 |
| `GET /api/templates/inactive-template/lang/ko` | 404 Not Found | 템플릿 미활성화 |
| `GET /api/templates/sirsoft-admin_basic/lang/fr` | 404 Not Found | 파일 존재하지 않음 |

---

## 모범 사례

### 권장 사항 (✅)

- 모든 템플릿은 최소 `ko.json`, `en.json` 필수 제공
- JSON 파일은 UTF-8 인코딩 사용
- 키 구조는 계층적으로 구성 (`dashboard.stats.users`)
- API를 통한 동적 로딩으로 초기 로딩 시간 최적화
- 브라우저 캐싱 활용 (max-age=3600)

### 금지 사항 (❌)

- 언어 파일에 HTML 포함 금지 (XSS 방지)
- 하드코딩된 텍스트 사용 금지 (모든 텍스트는 언어 파일에서)

---

## template.json과의 관계

```json
// template.json
{
  "identifier": "sirsoft-admin_basic",
  "locales": ["ko", "en"],  // 이 배열에 포함된 언어만 제공
  // ...
}
```

### 언어 결정 규칙

- `template.json`의 `locales` 배열이 제공 가능한 언어 명세
- `config('app.supported_locales')`와 교집합이 실제 제공 언어
- 예: `template.json`에 `["ko", "en", "ja"]`, `supported_locales`에 `["ko", "en"]`이면 `ko`, `en`만 제공

---

## 관련 문서

- [index.md](index.md) - 확장 시스템 가이드 인덱스
- [template-basics.md](template-basics.md) - 템플릿 기초
- [template-security.md](template-security.md) - 템플릿 보안 정책
- [template-caching.md](template-caching.md) - 템플릿 캐싱 전략
