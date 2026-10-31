# 템플릿 라우트/언어 파일 규칙

> **관련 문서**: [README.md](README.md) | [template-basics.md](template-basics.md) | [template-security.md](template-security.md)

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

> 같은 주소가 `routes/custom.php`(개발자 화면, 7.2.0+)에도 등록되어 있으면 서버가 개발자 화면을 먼저 내보내므로 routes.json 의 그 경로는 열리지 않는다. 엔진 화면 안의 이동도 그 주소로는 전체 페이지 이동이 된다. [개발자 화면 가이드](../custom-development.md#2-라우트컨트롤러뷰)

### 구조

```json
{
  "routes": [
    {
      "path": "/admin/dashboard",
      "layout_name": "admin_dashboard",
      "permissions": ["core.dashboard.view"],
      "meta": {
        "title": "$t:admin.dashboard.title",
        "icon": "dashboard"
      }
    },
    {
      "path": "/admin/users",
      "layout_name": "admin_users_list",
      "permissions": ["core.users.view"],
      "meta": {
        "title": "$t:admin.users.page_title",
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
| `meta` | 메타 정보 (제목, 아이콘 등). `title` 은 번역 키(`$t:키`)로 적는다 — 화면 편집기의 라우트 목록과 방문 통계의 페이지 제목에 그대로 보이므로 한국어를 박으면 다른 언어 사이트에서도 한국어로 나온다 |

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
| **로케일 형식** | BCP 47 — 언어팩 설치 규칙과 같다 (예: `ko`, `ja`, `fil`, `zh-Hant`, `fil-PH`, `es-419`, 20자 이하). 판정 기준 클래스는 `App\Support\LocaleTag` |
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

        // 2. 로케일 형식 검증 — 언어팩 설치 규칙(BCP 47, 20자 이하)과 같은 기준
        if (! LocaleTag::isValid($locale)) {
            return ['success' => false, 'error' => 'invalid_locale'];
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
    ->where('locale', LocaleTag::PATTERN_BODY)
    ->name('api.templates.language');
```

**주요 포인트**:
- `where('locale', LocaleTag::PATTERN_BODY)`: 언어팩 설치 규칙(BCP 47)의 정규형만 허용 — `fil-ph`·`ZH-HANT`·`fil_PH` 는 404
- 라우트 레벨에서 잘못된 형식 차단

---

## 프론트엔드에서 사용

### React 컴포넌트

```javascript
// 템플릿 컴포넌트에서 언어 파일 로드
import { apiUrl } from '../support/apiUrl';

const loadLanguageFile = async (identifier, locale) => {
  // 서버를 부르는 주소는 apiUrl() 을 거친다 (아래 주의 참조)
  const response = await fetch(apiUrl(`/api/templates/${identifier}/lang/${locale}`));

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

#### 주소는 루트 상대로 두지 않는다

사이트는 문서 루트가 아니라 하위 폴더에 설치될 수 있다(`https://host/g7/`). 서버가 렌더한 주소에는
마운트 접두사가 이미 붙어 있지만 **브라우저 안에서 만드는 주소**는 루트 상대(`/api/…`)라 접두사가 없다.
`fetch('/api/…')` 를 그대로 쓰면 그 요청이 사이트 밖으로 나가고, 증상은 오류가 아니라
**버튼이 조용히 무반응**인 것이다(문서 루트에 다른 사이트가 있으면 그쪽 응답을 받는다).

확장 번들(템플릿·모듈·플러그인)은 코어와 **별도 IIFE** 라 코어 함수를 `import` 할 수 없다. 그래서 각 확장이
`apiUrl.ts` 헬퍼를 바이트 동일한 사본으로 갖는다 — 코어 `G7Core.url.api` 에 위임하고, 코어 초기화 전에
불릴 수 있는 진입점을 위해 같은 SSoT(`G7Config.basePath`)를 읽는 폴백을 둔다. 템플릿에서의 위치는
`templates/{vendor-template}/src/support/apiUrl.ts` 다.

| 헬퍼 | 부르는 자리 |
|------|-------------|
| `apiUrl(path)` | 서버를 부르는 주소 — `fetch` / `XMLHttpRequest` / `EventSource` |
| `sitePath(path)` | 브라우저가 그대로 여는 주소 — `location.href` 대입 · `location.assign/replace` · `window.open` · `history.pushState`/`replaceState` 의 세 번째 인자 |
| `routePath(path)` | 받아 온 주소를 라우트와 대조할 때 — `location.pathname` 을 패턴과 비교하기 직전에 접두사를 **벗긴다**. 벗기지 않으면 하위 폴더 사이트에서 그 대조가 항상 불일치라 해당 기능이 조용히 사라진다 |

화면 링크(`<a href>`)는 템플릿 `A` 컴포넌트가 접두사를 붙이므로 날 `<a>` 를 직접 쓰지 않는다.
`navigate` 핸들러의 `path` 는 라우터가 붙이므로 확장이 직접 붙이지 않는다.

> 상세: [G7Core 전역 API 레퍼런스 "주소 조립 (G7Core.url)"](../frontend/g7core-api.md)

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
- BCP 47 형식 검증 (`App\Support\LocaleTag::isValid()` — 문자 집합이 `[A-Za-z0-9-]` 뿐이라 경로 문자가 들어갈 수 없다)
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

- [README.md](README.md) - 확장 시스템 가이드 인덱스
- [template-basics.md](template-basics.md) - 템플릿 기초
- [template-security.md](template-security.md) - 템플릿 보안 정책
- [template-caching.md](template-caching.md) - 템플릿 캐싱 전략
- [custom-development.md](../custom-development.md) - 개발자 화면(`routes/custom.php`)과 템플릿 라우트의 우선순위
