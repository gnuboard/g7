# 템플릿 시스템 기초

> **위치**: `docs/extension/template-basics.md`
> **관련 문서**: [index.md](./index.md) | [template-routing.md](./template-routing.md) | [template-security.md](./template-security.md)

---

## TL;DR (5초 요약)

```text
1. 타입: Admin (관리자용), User (일반사용자용)
2. 디렉토리: templates/vendor-template (예: sirsoft-admin_basic)
3. 필수: template.json, routes.json, components.json
4. 코어/템플릿 분리: 코어는 엔진, 템플릿은 컴포넌트
5. 버전 히스토리: Admin만 지원, User는 미지원
```

---

## 목차

1. [개요](#개요)
2. [템플릿 타입](#템플릿-타입)
   - [Admin 템플릿](#admin-템플릿)
   - [User 템플릿](#user-템플릿)
3. [템플릿 타입별 버전 히스토리 규칙](#템플릿-타입별-버전-히스토리-규칙)
4. [템플릿 네이밍 규칙](#템플릿-네이밍-규칙)
5. [템플릿 메타데이터 (template.json)](#템플릿-메타데이터-templatejson)
6. [에러 페이지 설정 (error_config)](#에러-페이지-설정-error_config)
7. [레이아웃 등록 스캔 범위](#레이아웃-등록-스캔-범위)
8. [역호환성 (다국어 필드)](#역호환성-다국어-필드)

---

## 개요

G7의 템플릿 시스템은 JSON 기반 레이아웃 정의를 통해 동적으로 UI를 생성하는 프론트엔드 아키텍처입니다. 코어는 렌더링 엔진만 제공하고, 실제 컴포넌트는 템플릿별로 독립적으로 구현됩니다.

**핵심 원칙**:
- 코어와 템플릿의 완전한 분리
- JSON 기반 레이아웃 정의
- 컴포넌트 재사용성 극대화
- 다국어 및 권한 기반 렌더링

---

## 템플릿 타입

### Admin 템플릿

**용도**: 관리자 페이지 UI 제공

**특징**:
- 레이아웃은 **읽기 전용** (DB 저장)
- 관리자만 접근 가능
- `/admin/*` 경로 전용
- 예: `sirsoft-admin_basic`

### User 템플릿

**용도**: 일반 사용자 페이지 UI 제공

**특징**:
- 레이아웃 **편집 가능** (사용자 커스터마이징)
- 공개 접근 가능
- `/` 루트 경로 전용
- 예: `sirsoft-user_theme1` (추후 구현 예정)

---

## 템플릿 타입별 버전 히스토리 규칙

```
중요: 템플릿 타입에 따라 버전 히스토리 동작이 다름
✅ 필수: Admin과 User 템플릿의 버전 관리 차이 이해
```

### Admin 템플릿 (`type: "admin"`)

**버전 히스토리**: ❌ **사용하지 않음**

- 레이아웃은 읽기 전용 (수정 불가)
- `template_layout_versions` 테이블 미사용
- 레이아웃 변경 시 직접 `template_layouts.content` 덮어쓰기
- 이유: 디자인 변경만 가능, 레이아웃 구조는 템플릿 교체로만 변경

**구현 예시**:
```php
// Admin 템플릿 레이아웃 업데이트
public function updateAdminLayout(int $id, array $data): TemplateLayout
{
    $layout = TemplateLayout::findOrFail($id);

    // 버전 생성 없이 직접 수정
    $layout->update([
        'content' => $data['content'],
    ]);

    return $layout;
}
```

### User 템플릿 (`type: "user"`)

**버전 히스토리**: ✅ **자동 관리**

- 레이아웃 편집 가능 (사용자 커스터마이징)
- `template_layout_versions` 테이블 사용
- 레이아웃 수정 시 자동으로 새 버전 생성
- 버전별 저장/복원 기능 제공

**구현 예시**:
```php
// User 템플릿 레이아웃 업데이트 (버전 자동 생성)
public function updateUserLayout(int $id, array $data): TemplateLayout
{
    $layout = TemplateLayout::findOrFail($id);

    // 현재 버전 번호 조회
    $latestVersion = $layout->versions()->max('version') ?? 0;
    $newVersion = $latestVersion + 1;

    // 새 버전 생성
    TemplateLayoutVersion::create([
        'layout_id' => $layout->id,
        'version' => $newVersion,
        'content' => $data['content'],
        'created_by' => auth()->id(),
    ]);

    // 레이아웃 content도 업데이트
    $layout->update([
        'content' => $data['content'],
    ]);

    return $layout;
}
```

**버전 복원**:
```php
// 특정 버전으로 복원
public function restoreVersion(int $layoutId, int $version): TemplateLayout
{
    $layout = TemplateLayout::findOrFail($layoutId);
    $versionRecord = $layout->getVersion($version);

    if (!$versionRecord) {
        throw new Exception('버전을 찾을 수 없습니다.');
    }

    // 복원 시 새 버전으로 저장
    $latestVersion = $layout->versions()->max('version') ?? 0;

    TemplateLayoutVersion::create([
        'layout_id' => $layout->id,
        'version' => $latestVersion + 1,
        'content' => $versionRecord->content,
        'created_by' => auth()->id(),
    ]);

    $layout->update([
        'content' => $versionRecord->content,
    ]);

    return $layout;
}
```

### 비교표

| 항목 | Admin 템플릿 | User 템플릿 |
|------|------------|-----------|
| 레이아웃 편집 | ❌ 불가 | ✅ 가능 |
| 버전 히스토리 | ❌ 미사용 | ✅ 자동 생성 |
| `template_layout_versions` | ❌ 미사용 | ✅ 사용 |
| 레이아웃 복원 | ❌ 불가 | ✅ 가능 |
| 변경 방법 | 템플릿 교체 | 직접 편집 |

---

## 템플릿 네이밍 규칙

**형식**: `[vendor-template]` (GitHub 스타일)

- 소문자 사용
- 하이픈(-) 구분
- vendor: 개발자/조직 식별자
- template: 템플릿명

**디렉토리 예시**:
- `/templates/_bundled/sirsoft-admin_basic/` - sirsoft가 개발한 기본 관리자 템플릿
- `/templates/_bundled/johndoe-admin_dark/` - johndoe가 개발한 다크 모드 관리자 템플릿

### 식별자 검증 규칙

모듈/플러그인/템플릿 공통 식별자 검증 규칙은 [extension-manager.md](./extension-manager.md#식별자-검증-규칙-validextensionidentifier)를 참조하세요.

---

## 템플릿 메타데이터 (template.json)

**위치**: `/templates/_bundled/[vendor-template]/template.json`

### 구조

```json
{
  "identifier": "sirsoft-admin_basic",
  "vendor": "sirsoft",
  "name": "Admin Basic",
  "version": "1.0.0",
  "license": "MIT",
  "description": "Basic admin template for Gnuboard7 platform",
  "type": "admin",
  "locales": ["ko", "en"],
  "dependencies": [],
  "features": {
    "responsive": true,
    "darkMode": true,
    "rtl": false
  },
  "assets": {
    "css": ["dist/bundle.css"],
    "js": ["dist/bundle.js"]
  }
}
```

### 필수 필드

| 필드 | 설명 |
|------|------|
| `identifier` | 템플릿 고유 식별자 (vendor-template 형식) |
| `vendor` | 벤더명 |
| `name` | 템플릿 표시명 |
| `version` | 버전 (Semantic Versioning) |
| `license` | 라이선스 유형 (예: `"MIT"`) — API 리소스의 `license` 필드로 노출 |
| `type` | "admin" 또는 "user" |
| `locales` | 지원 언어 배열 (`config('app.supported_locales')`와 매칭) |

---

## 에러 페이지 설정 (error_config)

```
필수: 모든 템플릿은 에러 페이지 설정을 포함해야 함
✅ 규칙: DB에는 layout_name만 저장되므로 경로 없이 이름만 사용
```

### template.json 설정

```json
{
  "error_config": {
    "layouts": {
      "404": "404",
      "403": "403",
      "500": "500"
    }
  }
}
```

### 에러 레이아웃 파일 위치

에러 레이아웃은 반드시 `layouts/errors/` 디렉토리에 위치해야 합니다:

```
templates/_bundled/[vendor-template]/
├── template.json               # 메타데이터 (이름, 버전, 라이선스 등 SSoT)
├── seo-config.json             # SEO 컴포넌트→HTML 매핑 설정 (선택)
├── LICENSE                     # 라이선스 전문 (MIT) — API 엔드포인트 `GET /api/admin/templates/{identifier}/license`로 제공
├── layouts/
│   ├── dashboard.json        # 일반 레이아웃
│   ├── _admin_base.json      # 베이스 레이아웃
│   └── errors/               # 에러 레이아웃 전용
│       ├── 404.json
│       ├── 403.json
│       └── 500.json
```

### 에러 레이아웃 구조

에러 레이아웃도 일반 레이아웃과 동일하게 `extends` + `slots` 패턴을 사용합니다:

```json
{
  "extends": "_admin_base",
  "slots": {
    "content": [
      {
        "type": "composite",
        "name": "EmptyState",
        "props": {
          "icon": "alert-triangle",
          "title": "$t:errors.404.title",
          "description": "$t:errors.404.description"
        }
      }
    ]
  }
}
```

### 설치 시 검증

`TemplateManager::installTemplate()` 호출 시 다음 사항이 자동 검증됩니다:

1. `error_config` 섹션 존재 여부
2. 필수 에러 코드(404, 403, 500) 레이아웃 정의 여부
3. 레이아웃 파일 실제 존재 여부 (`layouts/errors/` 디렉토리 내)

---

## 레이아웃 등록 스캔 범위

```
중요: registerLayouts()는 특정 디렉토리만 스캔
✅ 포함: layouts/*.json (루트) + layouts/errors/*.json
❌ 제외: layouts/partials/ 등 기타 하위 디렉토리
```

### 스캔 대상

| 디렉토리 | 스캔 여부 | 용도 |
|----------|----------|------|
| `layouts/*.json` | ✅ 스캔 | 일반 레이아웃 |
| `layouts/errors/*.json` | ✅ 스캔 | 에러 페이지 레이아웃 |
| `layouts/partials/*.json` | ❌ 제외 | extends로 참조되는 부분 레이아웃 |

### 이유

- `partials/` 디렉토리의 파일은 메인 레이아웃에서 `extends`로 참조되어 병합됨
- 직접 라우팅되지 않으므로 DB에 별도 등록 불필요
- 에러 레이아웃은 `ErrorPageHandler`에서 직접 조회하므로 DB 등록 필요

### TemplateManager.php 구현

```php
// 루트 layouts 디렉토리의 JSON 파일만 스캔
$layoutFiles = File::glob("{$layoutsPath}/*.json");

// errors/ 디렉토리만 추가 스캔 (에러 페이지 레이아웃용)
$errorsPath = "{$layoutsPath}/errors";
if (File::exists($errorsPath)) {
    $errorLayoutFiles = File::glob("{$errorsPath}/*.json");
    $layoutFiles = array_merge($layoutFiles, $errorLayoutFiles);
}
```

### 모듈 레이아웃 자동 등록

모듈 활성화 시 레이아웃은 디렉토리 위치에 따라 해당 타입의 템플릿에 등록됩니다:

- `modules/{module}/resources/layouts/admin/*.json` → Admin 타입 템플릿에 등록
- `modules/{module}/resources/layouts/user/*.json` → User 타입 템플릿에 등록

이 동작은 `ModuleManager::registerModuleLayouts()`에서 처리됩니다.

---

## 역호환성 (다국어 필드)

```
중요: 다국어 필드의 역호환성 지원
✅ 필수: 문자열 → 다국어 배열 자동 변환
```

### 지원 필드

- `name`: 템플릿명
- `description`: 템플릿 설명

### ModuleInterface/PluginInterface와 동일한 패턴

```php
// ✅ 권장: 다국어 배열 반환
public function getName(): array
{
    return [
        'ko' => '기본 관리자 템플릿',
        'en' => 'Basic Admin Template',
    ];
}

// 역호환: 문자열 반환 (자동 변환됨)
public function getName(): string
{
    return 'Basic Admin Template';  // 자동으로 ['ko' => '...', 'en' => '...']로 변환
}
```

### TemplateManager 자동 변환

```php
// app/Extension/TemplateManager.php

/**
 * 문자열을 다국어 배열로 자동 변환
 */
protected function convertToMultilingual($value): array
{
    // 이미 배열이면 그대로 반환
    if (is_array($value)) {
        return $value;
    }

    // 문자열이면 모든 로케일에 동일한 값 설정
    if (is_string($value)) {
        $locales = config('app.translatable_locales', ['ko', 'en']);
        $result = [];
        foreach ($locales as $locale) {
            $result[$locale] = $value;
        }
        return $result;
    }

    // 그 외 타입은 빈 배열 반환
    return [];
}
```

### template.json 예시

```json
{
  "identifier": "sirsoft-admin_basic",
  "vendor": "sirsoft",

  // ✅ 권장: 다국어 객체
  "name": {
    "ko": "기본 관리자 템플릿",
    "en": "Basic Admin Template"
  },
  "description": {
    "ko": "그누보드7용 기본 관리자 템플릿",
    "en": "Basic admin template for Gnuboard7 platform"
  }

  // 역호환: 문자열 (자동 변환됨)
  // "name": "Basic Admin Template",
  // "description": "Basic admin template for Gnuboard7 platform"
}
```

### DB 저장 형식

```php
// templates 테이블
[
    'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
    'description' => ['ko' => 'G7용...', 'en' => 'Basic admin template...'],
]
```

### 역호환성 보장 사항

- ✅ 기존 문자열 기반 template.json 정상 작동
- ✅ 문자열 → 다국어 배열 자동 변환
- ✅ 변환된 값은 모든 로케일에 동일하게 적용
- ✅ Model Accessor (`getLocalizedName()`)에서 올바른 폴백 처리

---

## 템플릿 업데이트 레이아웃 충돌 전략

템플릿 버전 업데이트 시, 관리자가 수정한 레이아웃과 새 버전의 레이아웃이 충돌할 수 있습니다.

### 사전 확인

업데이트 전 수정된 레이아웃을 확인할 수 있습니다:

```text
GET /api/admin/templates/{templateName}/check-modified-layouts
```

응답:

```json
{
    "has_modified": true,
    "modified_layouts": [
        { "id": 1, "name": "dashboard", "updated_at": "2026-02-24T10:00:00Z" }
    ]
}
```

### 충돌 해결 전략

업데이트 실행 시 `layout_strategy` 파라미터로 전략을 선택합니다:

| 전략 | 동작 |
|------|------|
| `apply_new` | 새 버전의 레이아웃으로 덮어쓰기 (관리자 수정 사항 유실) |
| `keep_current` | 기존 레이아웃 유지 (새 버전 레이아웃 미적용) |

```text
POST /api/admin/templates/{templateName}/update
Body: { "layout_strategy": "apply_new" }
```

> 상세: [extension-update-system.md](./extension-update-system.md)

---

## 번들 디렉토리 작업 규칙

```text
필수: 템플릿 수정/개발은 _bundled 디렉토리에서만 작업 (활성 디렉토리 직접 수정 금지)
필수: _bundled 작업 완료 후 반영/검증은 업데이트 프로세스 사용
```

### 개발 워크플로우

```text
1. templates/_bundled/{identifier}/ 에서 코드 수정
2. template.json 버전 올리기
3. php artisan template:update {identifier} 로 활성 디렉토리에 반영
4. 테스트 실행으로 검증
```

### 왜 활성 디렉토리 직접 수정이 금지되는가?

- 활성 디렉토리는 `.gitignore` 대상 → Git에 변경 기록 불가
- 다음 업데이트 시 `_bundled` 소스로 덮어쓰기 → 직접 수정 사항 유실
- 업데이트 프로세스 미수행 시 레이아웃 갱신/캐시 무효화 누락

### 예외: 초기 개발 (아직 _bundled에 미등록)

```text
✅ 허용: 신규 템플릿 초기 개발 시 활성 디렉토리에서 직접 작업
전환점: _bundled에 최초 반영한 이후부터는 반드시 _bundled에서만 작업
```

> 상세: [extension-update-system.md](./extension-update-system.md) "번들 디렉토리 개발 워크플로우" 참조

---

## 코드 변경 시 버전 변경 필수

```text
필수: 템플릿 코드를 변경한 경우 버전을 올려야 합니다.
버전 변경 없이 _bundled에 반영하면, 이미 설치된 환경에서 업데이트가 감지되지 않습니다.
참고: 템플릿은 모듈/플러그인과 달리 업그레이드 스텝(upgrades/)을 사용하지 않습니다.
```

### 필수 작업

1. **`template.json` 버전 올리기**: `version` 필드를 Semantic Versioning에 따라 증가
2. **`_bundled` 동기화**: `templates/_bundled/{identifier}/` 디렉토리에 변경 사항 반영

### 템플릿 vs 모듈/플러그인 차이

| 항목 | 템플릿 | 모듈/플러그인 |
|------|--------|-------------|
| manifest 버전 올리기 | ✅ 필수 | ✅ 필수 |
| _bundled 동기화 | ✅ 필수 | ✅ 필수 |
| 업그레이드 스텝 (upgrades/) | ❌ 해당 없음 | 조건부 필요 |
| DB 마이그레이션 | ❌ 해당 없음 | 조건부 필요 |
| 레이아웃 충돌 전략 | ✅ `layout_strategy` 파라미터 | ❌ 해당 없음 |

> 상세: [extension-update-system.md](./extension-update-system.md) "개발자 버전 업데이트 가이드" 참조

---

## SEO 설정 (seo-config.json)

**위치**: `templates/_bundled/[vendor-template]/seo-config.json` (선택 파일)

SEO 페이지 생성기가 컴포넌트를 HTML로 변환할 때 사용하는 매핑 설정입니다. 이 파일이 없으면 모든 컴포넌트가 `<div>` fallback으로 렌더링됩니다.

### 주요 섹션

| 섹션 | 설명 |
|------|------|
| `text_props` | 텍스트 추출 우선순위 (예: `["text", "label", "value", "title"]`) |
| `attr_map` | props→HTML 속성 매핑 (예: `{"className": "class", "htmlFor": "for"}`) |
| `allowed_attrs` | 허용 HTML 속성 목록 (목록에 없는 속성은 출력 안됨) |
| `component_map` | 컴포넌트명 → HTML 태그 매핑 (기본 30개 + 커스텀) |
| `render_modes` | 렌더 모드 정의 (iterate/format/raw 타입) |
| `self_closing` | 셀프 클로징 태그 목록 (`["img", "input", "hr", "br"]`) |
| `stylesheets` | SEO 페이지에 포함할 외부 CSS URL |

### Graceful Degradation

- `text_props`/`attr_map`/`allowed_attrs` 미선언 → 엔진 내장 기본값 사용 (범용 HTML/React 매핑)
- `text_props` 빈 배열로 명시 → props에서 텍스트 추출 불가 (`component.text`는 여전히 동작)
- `attr_map` 빈 객체로 명시 → 속성명 변환 없음 (className→class 미적용)
- `allowed_attrs` 빈 배열로 명시 → 모든 속성 출력 차단
- seo-config.json 미존재 → 모든 컴포넌트 `<div>` fallback, 기본 속성 매핑으로 최소한의 HTML 생성

### 검증

`TemplateManager.validateSeoConfig()`가 설치/업데이트 시 자동 검증합니다:
- JSON 파싱, `component_map.*.tag` 필수, `render` → `render_modes` 교차 참조
- 검증 실패 시 설치/업데이트 차단
- 파일 미존재 시 경고만 (설치 허용)

> 상세 스키마: [seo-system.md](../backend/seo-system.md) "템플릿 seo-config.json" 섹션 참조

## 관련 문서

- [index.md](./index.md) - 확장 시스템 인덱스
- [template-routing.md](./template-routing.md) - 템플릿 라우트/언어 파일 규칙
- [template-security.md](./template-security.md) - 템플릿 보안 정책
- [template-caching.md](./template-caching.md) - 템플릿 캐싱 전략
- [template-commands.md](./template-commands.md) - 템플릿 Artisan 커맨드
- [extension-update-system.md](./extension-update-system.md) - 확장 업데이트 시스템
