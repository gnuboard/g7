# 템플릿 보안 정책

> **목적**: 템플릿 시스템의 보안 정책 및 API 서빙 규칙 가이드

---

## 목차

1. [레이아웃 JSON 검증](#1-레이아웃-json-검증)
2. [의존성 검증](#2-의존성-검증)
3. [XSS 방지](#3-xss-방지)
4. [템플릿 API 서빙 규칙](#4-템플릿-api-서빙-규칙)
5. [파일 확장자 화이트리스트](#5-파일-확장자-화이트리스트)

---

## 1. 레이아웃 JSON 검증

### 검증 계층

```
FormRequest (StoreLayoutRequest)
    ↓
Custom Rule: ValidLayoutStructure
    ↓
Custom Rule: WhitelistedEndpoint
    ↓
Custom Rule: NoExternalUrls
    ↓
Custom Rule: ComponentExists
```

### 검증 항목

| 항목 | 설명 |
|------|------|
| JSON 구조 유효성 | 올바른 JSON 형식인지 검증 |
| 최대 중첩 깊이 | 10단계 제한 |
| 엔드포인트 화이트리스트 | `/api/(admin\|auth\|public)/` 패턴만 허용 |
| 외부 URL 금지 | 외부 도메인 URL 차단 |
| 컴포넌트 존재 여부 | components.json 기준으로 검증 |

---

## 2. 의존성 검증

### 템플릿 설치 시 체크

- 필수 의존성 템플릿 설치 여부
- 순환 의존성 방지
- 버전 호환성 (Semantic Versioning)

### 예시

```json
// template.json
{
  "dependencies": {
    "sirsoft-component-library": "^1.0.0"
  }
}
```

---

## 3. XSS 방지

### 데이터 바인딩

- 모든 `{{data.field}}` 값은 자동 이스케이프
- `dangerouslySetInnerHTML` 사용 금지
- HTML 삽입 시 서버 검증 필수

### Translation

- `$t:key` 값은 다국어 파일에서만 로드
- 사용자 입력 키 금지

---

## 4. 템플릿 API 서빙 규칙

```
중요: 템플릿 에셋은 API를 통해 제공
✅ 필수: 파일 복사 대신 API 엔드포인트 사용
```

### 원칙

- 템플릿 설치 시 빌드된 파일(`/templates/[vendor-template]/dist/`)은 그대로 유지
- `/public/build/templates/` 디렉토리에 파일 복사하지 않음
- API 엔드포인트를 통해 동적으로 템플릿 에셋 제공
- 보안: 활성화된 템플릿의 에셋만 접근 가능

### API 엔드포인트

```php
// routes/api.php
Route::get('/templates/{identifier}/assets/{file}', [TemplateAssetController::class, 'serve'])
    ->where('file', '.*')
    ->name('api.templates.assets');
```

### Controller 구현 (TemplateAssetController)

```php
<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Services\Template\TemplateService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TemplateAssetController extends PublicBaseController
{
    public function __construct(
        private TemplateService $templateService
    ) {}

    /**
     * 템플릿 에셋 파일 제공
     *
     * @param string $identifier 템플릿 식별자
     * @param string $file 파일 경로 (예: dist/components.js)
     * @return BinaryFileResponse|Response
     */
    public function serve(string $identifier, string $file): BinaryFileResponse|Response
    {
        // 1. 템플릿 활성화 여부 확인
        if (!$this->templateService->isTemplateActive($identifier)) {
            return $this->notFound('messages.template.not_active');
        }

        // 2. 파일 경로 검증 (보안)
        $allowedExtensions = ['js', 'js.map', 'css', 'css.map', 'woff', 'woff2', 'ttf', 'eot', 'svg', 'png', 'jpg', 'jpeg', 'gif'];
        $extension = pathinfo($file, PATHINFO_EXTENSION);

        if (!in_array($extension, $allowedExtensions)) {
            return $this->forbidden('messages.template.invalid_file_type');
        }

        // 3. 실제 파일 경로 생성
        $basePath = base_path("templates/{$identifier}");
        $filePath = realpath("{$basePath}/{$file}");

        // 4. 디렉토리 탈출 방지
        if (!$filePath || !str_starts_with($filePath, $basePath)) {
            return $this->forbidden('messages.template.invalid_path');
        }

        // 5. 파일 존재 확인
        if (!file_exists($filePath) || !is_file($filePath)) {
            return $this->notFound('messages.template.file_not_found');
        }

        // 6. MIME 타입 설정
        $mimeTypes = [
            'js' => 'application/javascript',
            'css' => 'text/css',
            'map' => 'application/json',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
        ];

        $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';

        // 7. 캐싱 헤더 추가
        return response()->file($filePath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}
```

### Service 메서드

```php
<?php

namespace App\Services\Template;

class TemplateService
{
    /**
     * 템플릿 활성화 여부 확인
     */
    public function isTemplateActive(string $identifier): bool
    {
        return Cache::remember(
            "template.active.{$identifier}",
            3600,
            fn() => Template::where('identifier', $identifier)
                ->where('is_active', true)
                ->exists()
        );
    }
}
```

### Blade 사용 예시

```php
<!-- resources/views/app.blade.php -->
<!DOCTYPE html>
<html>
<head>
    <!-- 활성 템플릿 컴포넌트 로드 -->
    @if($activeTemplate)
        <script src="{{ route('api.templates.assets', [
            'identifier' => $activeTemplate->identifier,
            'file' => 'dist/components.js'
        ]) }}" defer></script>
    @endif
</head>
<body>
    <!-- 앱 컨텐츠 -->
</body>
</html>
```

### 보안 규칙

| 규칙 | 설명 |
|------|------|
| ✅ 활성화된 템플릿만 접근 가능 | 비활성 템플릿 에셋 접근 차단 |
| ✅ 화이트리스트 기반 확장자 검증 | 허용된 확장자만 제공 |
| ✅ 디렉토리 탈출 공격 방지 | `realpath()`, `str_starts_with()` 사용 |
| ✅ MIME 타입 명시적 설정 | Content-Type 헤더 지정 |
| ✅ 적절한 캐싱 헤더 | `Cache-Control`, `immutable` |
| ❌ 비활성 템플릿 에셋 접근 금지 | 활성화 여부 검증 필수 |
| ❌ 임의의 파일 경로 접근 금지 | 경로 검증 필수 |

### 장점

1. **보안 강화**: 활성화된 템플릿만 접근 가능
2. **디스크 공간 절약**: 파일 복사 불필요
3. **동적 제어**: 템플릿 비활성화 시 즉시 접근 차단
4. **캐싱 최적화**: HTTP 캐싱으로 성능 유지
5. **유지보수 용이**: 단일 소스(dist 디렉토리)만 관리

---

## 5. 파일 확장자 화이트리스트

```
중요: 템플릿 에셋 파일 확장자 제한
✅ 필수: 화이트리스트 기반 확장자 검증
```

### 허용 확장자 목록

#### 스크립트 파일

| 확장자 | 설명 |
|--------|------|
| `js` | JavaScript |
| `mjs` | ES Module JavaScript |
| `js.map` | JavaScript 소스맵 |

#### 스타일 파일

| 확장자 | 설명 |
|--------|------|
| `css` | Cascading Style Sheets |
| `css.map` | CSS 소스맵 |

#### 폰트 파일

| 확장자 | 설명 |
|--------|------|
| `woff` | Web Open Font Format |
| `woff2` | Web Open Font Format 2 |
| `ttf` | TrueType Font |
| `otf` | OpenType Font |
| `eot` | Embedded OpenType |

#### 이미지 파일

| 확장자 | 설명 |
|--------|------|
| `png` | Portable Network Graphics |
| `jpg` | JPEG Image |
| `jpeg` | JPEG Image |
| `svg` | Scalable Vector Graphics |
| `webp` | WebP Image |
| `gif` | Graphics Interchange Format |

#### 데이터 파일

| 확장자 | 설명 |
|--------|------|
| `json` | JSON 데이터 (components.json, template.json 등) |

### TemplateService 구현

```php
// app/Services/TemplateService.php

/**
 * 허용된 파일 확장자 화이트리스트
 */
private const ALLOWED_EXTENSIONS = [
    'js', 'mjs', 'css', 'json',
    'png', 'jpg', 'jpeg', 'svg', 'webp', 'gif',
    'woff', 'woff2', 'ttf', 'otf', 'eot',
];

/**
 * 파일 확장자 검증
 */
private function validateFileExtension(string $filePath): bool
{
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    // .js.map, .css.map 처리
    if (str_ends_with($filePath, '.map')) {
        $extension = 'map';
    }

    return in_array($extension, self::ALLOWED_EXTENSIONS);
}

/**
 * MIME 타입 매핑
 */
private function getMimeType(string $extension): string
{
    $mimeTypes = [
        'js' => 'application/javascript',
        'mjs' => 'application/javascript',
        'css' => 'text/css',
        'json' => 'application/json',
        'map' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',
    ];

    return $mimeTypes[$extension] ?? 'application/octet-stream';
}
```

### 보안 규칙

| 규칙 | 설명 |
|------|------|
| ✅ 화이트리스트에 명시된 확장자만 허용 | 목록 외 차단 |
| ✅ 대소문자 구분 없이 검증 | `strtolower()` 사용 |
| ✅ 소스맵 파일 특별 처리 | `.js.map`, `.css.map` |
| 실행 파일 확장자 금지 | `.php`, `.sh`, `.exe` 등 |
| ❌ 서버 설정 파일 금지 | `.htaccess`, `.env` 등 |
| ❌ 화이트리스트 외 모든 확장자 차단 | 기본 거부 정책 |

### 금지 확장자 예시 (절대 추가 금지)

| 카테고리 | 확장자 |
|----------|--------|
| PHP 실행 파일 | `.php`, `.phar` |
| 실행 스크립트 | `.sh`, `.bat`, `.exe` |
| 설정 파일 | `.env`, `.htaccess`, `.conf` |
| 데이터베이스 파일 | `.sql`, `.db` |
| 압축 파일 (보안 위험) | `.zip`, `.tar`, `.gz` |

---

## 관련 문서

- [template-basics.md](template-basics.md) - 템플릿 기초
- [template-routing.md](template-routing.md) - 템플릿 라우트/언어 파일 규칙
- [template-caching.md](template-caching.md) - 템플릿 캐싱 전략
- [template-commands.md](template-commands.md) - 템플릿 Artisan 커맨드
