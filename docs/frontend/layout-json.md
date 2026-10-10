# 레이아웃 JSON 스키마

> **관련 문서**: [컴포넌트 개발](components.md) | [데이터 바인딩](data-binding.md) | [데이터 소스](data-sources.md)
>
> **마크업 형식**: 이 문서는 런타임 JSON 계약(엔진이 소비하는 스키마)이다. 사람·AI 가 직접 작성하는 마크업 형식은 `.g7.html`([layout-markup.md](layout-markup.md), 2026-07-18 문법 동결)이며 설치 시 서버가 이 JSON 으로 컴파일한다. 산출 JSON 키는 이 스키마 그대로다 — 여기 키 이름 ↔ `.g7.html` 태그·속성명 매핑은 layout-markup.md 참조.

---

## TL;DR (5초 요약)

```text
1. HTML 태그 직접 사용 금지 → 기본 컴포넌트 사용 (Div, Button, Span)
2. 텍스트: text 속성 또는 children 내 Span 사용 (텍스트 직접 배치 금지)
3. 다국어: "$t:key" 형식, 파라미터: "$t:key|param={{value}}"
4. 데이터 바인딩: "{{path.to.data}}" 형식
5. 다크 모드: light/dark 클래스 쌍 필수 (bg-white dark:bg-gray-800)
6. 새 속성 추가 시: UpdateLayoutContentRequest rules에도 추가 필수
7. 권한 제어: permissions 필드로 접근 권한 설정 (AND 로직, 401/403 응답)
8. globalHeaders: 패턴 기반 공통 헤더 자동 주입 (engine-v1.16.0+)
```

---

## 분리된 문서

이 문서는 가독성을 위해 다음과 같이 분리되었습니다:

| 문서 | 내용 |
|------|------|
| **layout-json.md** (현재) | 개요, 필수 필드, 컴포넌트 정의, 텍스트 렌더링 |
| [layout-json-features.md](layout-json-features.md) | 에러 핸들링, 초기화 액션, 모달 시스템, 액션 시스템 |
| [layout-json-components.md](layout-json-components.md) | 반복 렌더링(iteration), Blur 효과, 컴포넌트 생명주기 |
| [layout-json-inheritance.md](layout-json-inheritance.md) | 레이아웃 상속, Partial, 병합 구조, 상속 체인 검증 |

---

## 목차

1. [개요](#개요)
2. [필수 필드](#필수-필드)
3. [레이아웃 권한 (permissions)](#레이아웃-권한-permissions)
4. [컴포넌트 정의](#컴포넌트-정의)
5. [레이아웃 최상위 initLocal](#레이아웃-최상위-initlocal)
6. [격리된 상태 (isolatedState)](#격리된-상태-isolatedstate)
7. [텍스트 렌더링](#텍스트-렌더링)

---

## 개요

**레이아웃 JSON**은 화면 구성을 정의한 JSON 파일로, 데이터베이스 `template_layouts` 테이블에 저장됩니다.

그누보드7 템플릿 시스템에서 레이아웃 JSON은 다음 역할을 합니다:
- 화면에 표시될 컴포넌트 구조 정의
- API 데이터 소스 연결
- 사용자 인터랙션(액션) 처리
- 다국어 및 데이터 바인딩 지원

---

## 필수 필드

```html
<layout name="dashboard" version="1.0.0">
  <meta title="$t:dashboard.title" description="$t:dashboard.description"/>
  …
</layout>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "version": "1.0.0",
  "layout_name": "dashboard",
  "meta": {
    "title": "$t:dashboard.title",
    "description": "$t:dashboard.description"
  },
  "data_sources": [],
  "components": []
}
```

**필드 설명**:

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `version` | string | ✅ | 스키마 버전 (예: "1.0.0") |
| `layout_name` | string | ✅ | 레이아웃 식별자 (아래 네이밍 규칙 참조) |
| `meta` | object | ❌ | 메타 정보 (title, description) |
| `data_sources` | array | ✅ | API 데이터 소스 정의 (각 항목에 `label_key` `$t:` 키 — 편집기 데이터 연결 피커 친화 명칭) |
| `init_actions` | array | ❌ | 초기화 액션 (레이아웃 로드 시 실행) |
| `modals` | array | ❌ | 모달 컴포넌트 정의. 인라인 항목에 `layout_name` 이 있으면 설치가 `partial` 참조 조각을 인라인하며 남긴 조각 이름이다(마크업 `<modal name>`) |
| `scripts` | array | ❌ | 외부 스크립트 동적 로드 (engine-v1.8.0+) |
| `errorHandling` | object | ❌ | 레이아웃 레벨 에러 핸들링 설정 (engine-v1.6.0+) |
| `permissions` | string[] | ❌ | 레이아웃 접근 권한 식별자 배열 (engine-v1.15.0+) |
| `globalHeaders` | array | ❌ | 전역 HTTP 헤더 규칙 배열 (engine-v1.16.0+) |
| `meta.seo` | object | ❌ | SEO 페이지 생성기 설정 (아래 참조) |
| `components` | array | ✅ | 컴포넌트 배열 |

### 한 객체에 같은 키를 두 번 쓰지 않는다

JSON 은 중복 키를 문법 오류로 보지 않는다. 브라우저(`JSON.parse`)도 서버 등록(`json_decode`)도
**뒤에 온 값이 앞의 값을 덮는다.** 그래서 앞에 쓴 선언은 예외도 경고도 없이 사라지는데,
파일에는 그대로 남아 있으므로 코드를 읽는 사람에게는 반영된 것처럼 보인다.

가장 자주 걸리는 자리는 `props` 다 — 노드가 `children` **뒤**에 `"props": {}` 를 이미 갖고 있는데
작성자가 노드 앞머리에 `"props": { ... }` 를 새로 적는 경우다. 노드가 길면 앞머리와 꼬리가
한 화면에 들어오지 않아 눈으로는 발견되지 않고, 그 속성만 화면에 영영 나타나지 않는다.

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| 한 노드에 `props`(또는 `comment`·`actions` 등)를 두 번 선언 | 하나로 합친다 — 값을 추가할 때는 그 노드에 **이미 있는** 키를 찾아 거기에 넣는다 |
| 노드 앞머리에 키를 추가하기 전에 꼬리를 확인하지 않음 | 노드 전체에서 그 키의 존재를 먼저 확인한다 |

의도적으로 재선언해야 하는 예외는 그 객체의 `comment` 에
`audit:allow layout-json-duplicate-object-key <사유>` 를 남긴다 (JSON 은 주석을 담을 수 없으므로
표식 자리가 `comment` 값이다). 자동 검증이 차단한다.

### layout_name 네이밍 규칙

```text
✅ 허용 문자: 영문(a-z, A-Z), 숫자(0-9), 언더스코어(_), 슬래시(/), 하이픈(-), 점(.)
✅ 슬래시 사용: 계층 구조 표현 가능 (예: "board/popular", "mypage/orders/show")
✅ 언더스코어 사용: 플랫 구조 표현 가능 (예: "admin_dashboard", "admin_user_list")
파일 경로와 일치: layout_name은 파일 경로(확장자 제외)와 동일해야 함
```

**예시**:

| 파일 경로 | layout_name |
|----------|-------------|
| `layouts/home.g7.html` | `home` |
| `layouts/board/popular.g7.html` | `board/popular` |
| `layouts/mypage/orders/show.g7.html` | `mypage/orders/show` |
| `layouts/admin_dashboard.g7.html` | `admin_dashboard` |

> **상세 문서**:
> - `errorHandling`: [layout-json-features.md](layout-json-features.md#에러-핸들링-설정-errorhandling)
> - `init_actions`: [layout-json-features.md](layout-json-features.md#초기화-액션-init_actions)
> - `modals`: [layout-json-features.md](layout-json-features.md#모달-시스템-modals)
> - `scripts`: [layout-json-features.md](layout-json-features.md#외부-스크립트-로드-scripts)
> - `permissions`: [아래 섹션](#레이아웃-권한-permissions)
> - `globalHeaders`: [아래 섹션](#전역-헤더-globalheaders)
> - `meta.seo`: [아래 섹션](#metaseo-seo-페이지-생성기)

### meta.seo (SEO 페이지 생성기)

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| seo.enabled | boolean | X | SEO 렌더링 활성화 (기본: false) |
| seo.data_sources | string[] | X | SEO 시 사전 로드할 data_sources ID |
| seo.extensions | array | X | SEO 변수 제공 확장 목록 (아래 참조) |
| seo.page_type | string | X | 확장 설정 SEO 템플릿 키 결정 (예: `"product"`) |
| seo.toggle_setting | string | X | SEO 활성화 토글 설정 경로 |
| seo.vars | object | X | SEO 변수 선언 — data 소스 변수의 표현식 매핑 |
| seo.priority | number | X | sitemap priority (0.0~1.0) |
| seo.changefreq | string | X | sitemap changefreq (daily/weekly 등) |
| seo.og | object | X | Open Graph 메타태그 |
| seo.structured_data | object | X | JSON-LD 구조화 데이터 |

#### seo.extensions

SEO 변수를 제공하는 확장(모듈/플러그인)을 선언합니다. 여기에 선언된 확장의 `seoVariables()` 메서드가 호출되어, `page_type`에 해당하는 변수가 자동 해석됩니다.

```json
"extensions": [
    { "type": "module", "id": "sirsoft-ecommerce" },
    { "type": "plugin", "id": "sirsoft-payment" }
]
```

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| type | `"module"` \| `"plugin"` | ✅ | 확장 타입 |
| id | string | ✅ | 확장 식별자 (예: `"sirsoft-ecommerce"`) |

이 방식은 레이아웃 이름에 dot-notation `moduleIdentifier`를 포함할 필요 없이, 레이아웃이 어떤 확장의 SEO 변수를 사용하는지 명시적으로 선언합니다.

Partial에 meta.seo 정의 금지 (무시됨)
meta.seo 추가/변경 시 UpdateLayoutContentRequest 검증 규칙 동기화 필수
data_sources는 부모-자식 간 합집합 병합 (permissions와 동일 전략)

> 상세: [seo-system.md](../backend/seo-system.md)

---

## 레이아웃 권한 (permissions)

> **버전**: engine-v1.15.0+

레이아웃 접근에 필요한 권한을 정의합니다. 권한이 없는 사용자는 레이아웃 서빙 API에서 401/403 응답을 받습니다.

### 기본 문법

```html
<layout name="admin_dashboard" version="1.0.0">
  <meta title="$t:admin.dashboard.title"/>
  <permissions>
    <permission>core.dashboard.read</permission>
  </permissions>
  …
</layout>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "version": "1.0.0",
  "layout_name": "admin_dashboard",
  "permissions": ["core.dashboard.read"],
  "meta": {
    "title": "$t:admin.dashboard.title"
  },
  "components": [...]
}
```

### 동작 규칙

| 조건 | 동작 |
|------|------|
| `permissions` 필드 없음 또는 빈 배열 | 공개 레이아웃 (권한 체크 없음) |
| `permissions`에 값이 있음 | 모든 권한 필요 (AND 로직) |
| 비회원 + 권한 없음 | 401 Unauthorized |
| 회원 + 권한 없음 | 403 Forbidden |
| Admin 역할 | 모든 권한 자동 통과 |

### 권한 식별자 형식

```text
✅ 권한 식별자 규칙: [모듈명].[엔티티].[액션]
✅ 허용 문자: 영문(a-z), 숫자(0-9), 하이픈(-), 언더스코어(_), 점(.)
✅ 예시: core.dashboard.read, sirsoft-ecommerce.products.read
```

### 상속 시 권한 병합

부모-자식 레이아웃 상속 시 permissions는 **합집합**으로 병합됩니다:

```text
부모: { "permissions": ["core.admin.access"] }
자식: { "permissions": ["core.users.read"] }
결과: { "permissions": ["core.admin.access", "core.users.read"] }
```

- 중복 제거 적용 (`array_unique`)
- 병합 결과의 모든 권한을 만족해야 접근 가능 (AND 로직)

### 예시

#### 관리자 레이아웃

```html
<layout name="admin_user_list" version="1.0.0">
  <meta title="$t:admin.users.title"/>
  <permissions>
    <permission>core.users.read</permission>
  </permissions>
  …
</layout>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "version": "1.0.0",
  "layout_name": "admin_user_list",
  "permissions": ["core.users.read"],
  "meta": { "title": "$t:admin.users.title" },
  "components": [...]
}
```

#### 모듈 관리 레이아웃

```html
<layout name="admin_ecommerce_product_list" version="1.0.0">
  <meta title="$t:admin.ecommerce.products.title"/>
  <permissions>
    <permission>sirsoft-ecommerce.products.read</permission>
  </permissions>
  …
</layout>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "version": "1.0.0",
  "layout_name": "admin_ecommerce_product_list",
  "permissions": ["sirsoft-ecommerce.products.read"],
  "meta": { "title": "$t:admin.ecommerce.products.title" },
  "components": [...]
}
```

#### 공개 레이아웃 (권한 없음)

```html
<layout name="home" version="1.0.0">
  <meta title="$t:common.home"/>
  …
</layout>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "version": "1.0.0",
  "layout_name": "home",
  "meta": { "title": "$t:common.home" },
  "components": [...]
}
```

### 주의사항

```text
권한 식별자는 DB의 permissions 테이블에 존재해야 함
빈 배열 []은 권한 체크 없음과 동일 (공개 레이아웃)
프론트엔드는 401/403 응답 시 적절한 UI 처리 필요 (로그인 페이지 리다이렉트 등)
```

---

## 컴포넌트별 권한 (Component Permissions)

> **버전**: engine-v1.17.0+

개별 컴포넌트에 `permissions` 속성을 추가하면, **서버 사이드에서 해당 컴포넌트를 JSON에서 제거**하여 서빙합니다. 프론트엔드 `if` 조건부 렌더링과 달리, 권한 없는 컴포넌트의 구조 자체가 클라이언트에 노출되지 않습니다.

### 기본 문법

```html
<div data-ref="admin_widget">
  <permissions>
    <permission>core.dashboard.admin-widget</permission>
  </permissions>
  <h2>관리자 전용 위젯</h2>
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "basic",
  "name": "Div",
  "id": "admin_widget",
  "permissions": ["core.dashboard.admin-widget"],
  "children": [
    { "type": "basic", "name": "H2", "text": "관리자 전용 위젯" }
  ]
}
```

### 동작 규칙

| 조건 | 동작 |
|------|------|
| `permissions` 없음 또는 빈 배열 | 항상 포함 (권한 체크 없음) |
| `permissions`에 값이 있음 | 모든 권한 필요 (AND 로직) |
| 권한 없음 | 컴포넌트 + 하위 children 전체 제거 |
| Admin 역할 | 모든 컴포넌트 자동 통과 |

### 필터링 대상 영역

| 영역 | 설명 |
|------|------|
| `components` | 메인 컴포넌트 트리 (재귀 children 포함) |
| `modals[]` | 모달 자체에 permissions → 모달 전체 제거 |
| `modals[].components` | 모달 내부 컴포넌트 트리 |
| `defines` | 재사용 컴포넌트 정의 |

### 상위-하위 중복 선언

각 노드는 자기 permissions만 독립 평가합니다. 별도 병합 정책 없음:

```html
<div>
  <permissions>
    <permission>core.users.read</permission>
  </permissions>
  <button>
    <permissions>
      <permission>core.users.delete</permission>
    </permissions>
    삭제
  </button>
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "basic", "name": "Div",
  "permissions": ["core.users.read"],
  "children": [
    {
      "type": "basic", "name": "Button",
      "permissions": ["core.users.delete"],
      "text": "삭제"
    }
  ]
}
```

| 시나리오 | 상위 | 하위 | 결과 |
|---------|------|------|------|
| read 없음 | 전체 제거 | 평가 안 됨 | 둘 다 없음 |
| read 있음 + delete 없음 | 통과 | 제거 | Div만 남음 |
| read 있음 + delete 있음 | 통과 | 통과 | 둘 다 남음 |

### 레이아웃 최상위 permissions와의 차이

| 구분 | 레이아웃 최상위 | 컴포넌트별 |
|------|---------------|-----------|
| **범위** | 레이아웃 전체 접근 제어 | 개별 컴포넌트 표시/숨김 |
| **실패 시** | 401/403 에러 응답 | 해당 컴포넌트만 JSON에서 제거 |
| **캐싱** | 캐시 전 체크 | post-cache 필터링 (캐시 영향 없음) |

### 확장(모듈/플러그인) 컴포넌트

Extension Point 또는 Overlay로 주입된 컴포넌트에도 `permissions`를 선언할 수 있습니다. 필터링은 `applyExtensions()` 이후에 수행되므로 자동 처리됩니다.

### 주의사항

```text
권한 식별자는 DB의 permissions 테이블에 존재해야 함
보안 목적: 프론트엔드 if 조건과 달리 JSON에 컴포넌트 구조가 노출되지 않음
필터링 후 permissions 속성 자체도 제거됨 (클라이언트 노출 방지)
post-cache filtering — 기존 캐시 구조에 영향 없음
```

---

## 전역 헤더 (globalHeaders)

> **버전**: engine-v1.16.0+

레이아웃에서 정의한 모든 API 호출(data_sources, apiCall 핸들러)에 공통 헤더를 자동으로 주입합니다.

### 기본 문법

```html
<layout name="shop_cart" version="1.0.0">
  <global-headers>
    <match pattern="*" X-Request-Source="user-template"/>
    <match pattern="/api/modules/sirsoft-ecommerce/*" X-Cart-Key="{{_global.cartKey}}"/>
  </global-headers>
  …
</layout>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "version": "1.0.0",
  "layout_name": "shop_cart",
  "globalHeaders": [
    {
      "pattern": "*",
      "headers": { "X-Request-Source": "user-template" }
    },
    {
      "pattern": "/api/modules/sirsoft-ecommerce/*",
      "headers": { "X-Cart-Key": "{{_global.cartKey}}" }
    }
  ],
  "components": [...]
}
```

### globalHeaders 배열 구조

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `pattern` | string | ✅ | 엔드포인트 매칭 패턴 (`*`, `/api/modules/sirsoft-ecommerce/*` 등) |
| `headers` | object | ✅ | 헤더 키-값 쌍 (표현식 지원) |

### 패턴 매칭 규칙

| 패턴 | 설명 | 매칭 예시 |
|------|------|----------|
| `*` | 모든 API | 전체 적용 |
| `/api/modules/sirsoft-ecommerce/*` | ecommerce 모듈 API | `/api/modules/sirsoft-ecommerce/cart`, `/api/modules/sirsoft-ecommerce/products/123` |
| `/api/modules/sirsoft-ecommerce/*` | shop prefix API | `/api/modules/sirsoft-ecommerce/products`, `/api/modules/sirsoft-ecommerce/categories` |
| `/api/modules/sirsoft-ecommerce/cart` | 정확히 일치 | `/api/modules/sirsoft-ecommerce/cart`만 (하위 경로 제외) |

### 표현식 지원

헤더 값에서 데이터 바인딩 표현식을 사용할 수 있습니다:

```html
<global-headers>
  <match pattern="/api/modules/sirsoft-ecommerce/*" X-Cart-Key="{{_global.cartKey}}" X-User-Locale="{{$locale}}"/>
</global-headers>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "globalHeaders": [
    {
      "pattern": "/api/modules/sirsoft-ecommerce/*",
      "headers": {
        "X-Cart-Key": "{{_global.cartKey}}",
        "X-User-Locale": "{{$locale ?? 'ko'}}"
      }
    }
  ]
}
```

**지원되는 컨텍스트**:

| 컨텍스트 | 설명 |
|----------|------|
| `{{_global.xxx}}` | 전역 상태 값 |
| `{{_local.xxx}}` | 로컬 상태 값 |
| `{{route.xxx}}` | 라우트 파라미터 |
| `{{query.xxx}}` | 쿼리 파라미터 |

### 헤더 우선순위

```text
낮음 ← globalHeaders ← source.headers / params.headers → 높음
```

개별 API의 `headers` 속성이 `globalHeaders`의 동일한 키를 덮어씁니다:

```html
<data-source id="products" endpoint="/api/modules/sirsoft-ecommerce/products">
  <headers X-Custom="source-value"/>
</data-source>
<global-headers>
  <match pattern="*" X-Custom="global-value"/>
</global-headers>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "globalHeaders": [
    { "pattern": "*", "headers": { "X-Custom": "global-value" } }
  ],
  "data_sources": [
    {
      "id": "products",
      "endpoint": "/api/modules/sirsoft-ecommerce/products",
      "headers": { "X-Custom": "source-value" }  // globalHeaders보다 우선
    }
  ]
}
```

### 적용 범위

| 적용 대상 | 설명 |
|----------|------|
| `data_sources` | 레이아웃의 data_sources 배열에서 정의한 API |
| `apiCall` 핸들러 | actions에서 `handler: "apiCall"`로 호출하는 API |
| `refetchDataSource` 핸들러 | DataSourceManager를 통해 호출 (자동 적용) |

### 상속 시 병합

부모-자식 레이아웃 상속 시 globalHeaders는 **pattern 기준으로 병합**됩니다:

```text
부모: [{ pattern: "*", headers: { "X-Template": "basic" } }]
자식: [{ pattern: "*", headers: { "X-Page": "cart" } }]
결과: [{ pattern: "*", headers: { "X-Template": "basic", "X-Page": "cart" } }]
```

- 동일 pattern: headers가 병합됨 (자식이 동일 키 덮어씀)
- 다른 pattern: 별도로 유지

> **상세 문서**: [layout-json-inheritance.md](layout-json-inheritance.md#globalheaders-병합)

### 주의사항

```text
표현식 값이 빈 문자열/undefined/null이면 해당 헤더는 전송되지 않음
_isolated 컨텍스트는 지원하지 않음 (data_sources fetch 시점에 격리 스코프 미확정)
시스템 내부 API(레이아웃 로드, 번역 파일 등)에는 적용되지 않음
```

---

## 새 속성 추가 규정

```text
주의: 레이아웃 JSON에 새로운 최상위 속성 추가 시 백엔드 작업 필수
필수: UpdateLayoutContentRequest rules()에도 새 속성 추가
프론트엔드만 수정하고 백엔드 누락 시 저장 후 데이터 사라짐
```

### 왜 필요한가?

Laravel의 `FormRequest::validated()` 메서드는 `rules()`에 정의된 필드만 반환합니다. 따라서:

1. 레이아웃 JSON에 새 속성을 추가해도
2. `UpdateLayoutContentRequest::rules()`에 없으면
3. 저장 시 해당 속성이 **삭제**됩니다

### 새 속성 추가 체크리스트

```
□ 프론트엔드: 레이아웃 JSON에 새 속성 추가
□ 백엔드: UpdateLayoutContentRequest::rules()에 규칙 추가
□ 백엔드: UpdateLayoutContentRequest::messages()에 에러 메시지 추가
□ 다국어: lang/ko/validation.php, lang/en/validation.php 메시지 추가
□ 테스트: 해당 속성이 저장되는지 테스트 추가
```

### 파일 위치

- FormRequest: `app/Http/Requests/Layout/UpdateLayoutContentRequest.php`
- 다국어: `lang/ko/validation.php`, `lang/en/validation.php`
- 테스트: `tests/Feature/Api/Admin/LayoutControllerTest.php`

### 예시: 새 속성 `custom_field` 추가

```php
// UpdateLayoutContentRequest::rules()
'content.custom_field' => ['nullable', 'array'],

// UpdateLayoutContentRequest::messages()
'content.custom_field.array' => __('validation.layout.custom_field.array'),
```

---

## 컴포넌트 정의

작성에서는 **태그명이 곧 컴포넌트**이고(`name`), `type`(basic/composite/layout)은 레지스트리에서 파생하므로 적지 않는다. `id` 는 `data-ref`, props 는 무접두 속성, 액션은 `<on>` 블록이다.

```html
<button data-ref="unique_id" title="$t:dashboard.title" value="{{data.field}}">
  <on type="click">
    <navigate path="/path"/>
  </on>
</button>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다. 태그 자리에는 등록된 컴포넌트의 kebab 이름이 온다(`<button>`·`<data-grid>` 등) — 아래 JSON 의 `name` 이 그 태그에서 나오고, `type` 은 레지스트리에서 파생된다.

```json
{
  "id": "unique_id",
  "type": "basic|composite|layout",
  "name": "ComponentName",
  "props": {
    "title": "$t:dashboard.title",
    "value": "{{data.field}}"
  },
  "children": [],
  "actions": [
    {
      "type": "click",
      "handler": "navigate",
      "target": "/path"
    }
  ]
}
```

**필드 설명**:

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `id` | string | ✅ | 컴포넌트 고유 ID |
| `type` | string | ✅ | 컴포넌트 타입 (basic/composite/layout) |
| `name` | string | ✅ | 컴포넌트 이름 (components.json에 등록된 이름) |
| `comment` | string | ✅ | 시안 추적 코드 및 역할 설명 (예: "F-002: 상품명 검색 필드"). 공개 서빙 응답에서는 제거됨 (아래 참조) |
| `props` | object | ❌ | 컴포넌트에 전달할 props |
| `text` | string | ❌ | 텍스트 콘텐츠 (최우선 렌더링) |
| `children` | array | ❌ | 자식 컴포넌트 배열 (레이아웃/집합 컴포넌트에서 사용) |
| `iteration` | object | ❌ | 반복 렌더링 설정 (source, item_var, index_var) |
| `actions` | array | ❌ | 액션 핸들러 정의 (클릭, 키보드, 폼 제출 등) |
| `lifecycle` | object | ❌ | 생명주기 핸들러 (onMount, onUnmount) |
| ~~`data_binding`~~ | object | — | **사어(死語)** — 렌더러가 타입만 선언하고 읽지 않으며 코퍼스 사용 0건이다. 마크업 태그로 승격되지 않는다([layout-markup.md 명명 3순위](layout-markup.md)). 데이터는 props 표현식(`value="{{data.field}}"`)으로 바인딩한다 |
| `blur_until_loaded` | boolean \| string | ❌ | 데이터 로딩 중 blur 효과 적용 (boolean 또는 표현식 문자열) |
| `component_layout` | object | ❌ | 컴포넌트 내부 커스텀 렌더링 레이아웃 정의 (RichSelect 등에서 사용) |
| `isolatedState` | object | ❌ | 격리된 상태 초기값 정의 (engine-v1.14.0+) |
| `isolatedScopeId` | string | ❌ | 격리된 스코프 식별자 (engine-v1.14.0+) |
| `autoBinding` | boolean | ❌ | `false`로 설정 시 해당 필드의 폼 자동 바인딩을 명시적으로 비활성화. 커스텀 핸들러나 G7Core API로 상태 관리 시 사용 (engine-v1.17.6+) |

> **상세 문서**:
> - `iteration`: [layout-json-components.md](layout-json-components.md#반복-렌더링-iteration)
> - `blur_until_loaded`: [layout-json-components.md](layout-json-components.md#데이터-로딩-중-blur-효과-blur_until_loaded)
> - `lifecycle`: [layout-json-components.md](layout-json-components.md#컴포넌트-생명주기-lifecycle)
> - `actions`: [layout-json-features.md](layout-json-features.md#액션-시스템-actions)
> - `isolatedState`: [아래 섹션](#격리된-상태-isolatedstate)

### `comment` / `_comment` — 화면 렌더 응답에서 제거

`comment` / `_comment` 는 편집기·개발자용 설명 주석이며 런타임 렌더러는 참조하지 않는다. **브라우저가 화면을 그리는 데 쓰는 JSON 을 내보내는 응답**에서는 전송 크기 절감을 위해 이 두 키가 노드 트리 전체에서 재귀적으로 제거된다(`LayoutService::stripDeveloperComments`).

- 편집 모드 서빙(`?with_source_meta=1`, 편집 권한 필요)에서는 편집기가 `comment` 를 편집 가능한 속성으로 노출하므로 **보존**된다. 공개/편집 응답은 별도 캐시 키로 분리되어 있어 조건부 제거가 안전하다.
- 따라서 `comment` 는 편집기·저장본에서는 유지되고, 일반 사용자에게 서빙되는 응답에서만 사라진다. 레이아웃 로직이 `comment` 값에 의존해서는 안 된다.
- 화면 조각의 설명(`<partial description="…">` → 조각 루트 `meta: {is_partial: true, description}`)도 개발자용 설명이므로 같은 응답에서 제거된다. 레이아웃 최상단 `meta.description` 은 화면 설명(SEO)이라 보존된다 — 조각 표식(`is_partial: true`)이 있는 `meta` 만 대상이다.

#### 제거는 소비자마다 서야 한다

이 계약을 지는 응답은 하나가 아니다. 현재 소비자는 둘이다.

| 소비자 | 엔드포인트 | 제거 지점 |
| --- | --- | --- |
| 공개 레이아웃 서빙 | `GET /api/layouts/{template}/{layout}.json` | `LayoutService::getLayout` — 병합 안(`loadAndMergeLayout`) **+ 확장 주입 뒤** 두 번 |
| 미리보기 서빙 | `GET /api/layouts/preview/{token}.json` (무인증 토큰) | `LayoutPreviewService::getPreviewLayout` 진입점 한 자리 (분기 둘을 함께 덮음) |

새 소비자를 만들 때는 재귀 제거를 새로 짜지 않고 `LayoutService::stripDeveloperComments`(public) 를 부른다 — 각자 구현하면 한쪽만 키를 늘렸을 때 조용히 갈린다.

제거 지점을 **병합 함수 안에만** 두면 그 뒤에 얹히는 것이 새어 나간다. 확장 주입 조각(`resources/extensions/`)이 실제로 그렇게 빠져나갔다 — 주석 제거는 병합 시점에 일어나는데 `applyExtensions` 는 그 뒤에 얹히므로 조각의 주석은 제거를 지나쳤고, 같은 응답 안에서 본문에는 주석이 없고 주입 조각에만 있는 상태로 서빙되었다. 회귀 잠금: `tests/Feature/Api/Public/{LayoutServingTest,LayoutPreviewControllerTest}.php`.

---

## 레이아웃 최상위 initLocal

레이아웃 최상위 `initLocal`(구 `state`, engine-v1.11.0+ 별칭)은 `_local` 의 **정적 초기값**을 세운다.
데이터 소스의 `initLocal`(응답을 `_local` 로 옮기는 옵션)과는 다른 것이다.

<!-- pair:skip initLocal 설명용 발췌 — 소스는 templates/_bundled/sirsoft-basic/layouts/shop/guest_claim_show.g7.html -->
```json
{
  "layout_name": "shop/guest_claim_show",
  "initLocal": {
    "isGuest": true,
    "withdrawRefreshDataSourceId": "claim"
  }
}
```

### 값은 그대로 복사된다 — 표현식은 해석되지 않는다

엔진은 값을 JSON 깊은 복사로 옮긴다. 그래서 `{{...}}` 를 쓰면 **그 문자열 자체**가 `_local` 에 들어간다.

| ❌ 금지 | ✅ 올바른 사용 |
| --- | --- |
| `"initLocal": { "apiBase": "/api/orders/{{_global.orderNumber}}/claims" }` | 표현식이 필요한 값은 `init_actions` 의 `setState` 로 세운다 |

어긋나도 예외가 나지 않는다 — 주소에 `{{_global.orderNumber}}` 가 그대로 실려 요청이 404 가 되거나,
화면이 그 문자열을 그대로 출력한다. 정적 리터럴(불리언·숫자·고정 문자열·고정 배열)만 여기 둔다.

### 이미 값이 있는 키는 덮지 않는다

`_local[key]` 가 `undefined` 일 때만 채운다. 앞 화면이 같은 키를 남겨 두었다면 그 값이 유지되므로,
화면마다 초기값이 달라야 하는 키는 `init_actions` 에서 명시적으로 세운다.

### 실행 순서

```text
blocking 데이터 소스 조회
  → 레이아웃 initLocal/initGlobal/initIsolated
    → 데이터 소스의 initLocal/initGlobal/initIsolated
      → init_actions (부모 → 자식 순)
```

`blocking` 데이터 소스는 이 사슬의 **맨 앞**에서 조회된다. 그래서 `init_actions` 나 `initLocal` 이
세우는 값을 blocking 데이터 소스의 주소·헤더에서 읽을 수 없다 — 그 자리에는 아직 아무것도 없다.
그 값이 필요한 데이터 소스는 `progressive` 로 두고 `blur_until_loaded` 로 로딩 중을 가린다.

`extends` 로 상속하면 부모의 `init_actions` 가 자식보다 먼저 실행되므로, 부모가 세운 `_global` 값은
자식의 `init_actions` 에서 읽을 수 있다.

---

## 격리된 상태 (isolatedState)

> **버전**: engine-v1.14.0+

특정 컴포넌트 영역 내에서만 유효한 격리된 상태를 정의합니다. 해당 영역의 상태 변경 시 전체 레이아웃이 아닌 격리된 영역만 리렌더링되어 성능이 향상됩니다.

### 기본 문법

```html
<div>
  <isolated-state scope-id="category-selector">
    <field name="selectedCategories" :value="[]"/>
    <field name="currentStep" :value="1"/>
    <field name="isExpanded" :value="false"/>
  </isolated-state>
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "basic",
  "name": "Div",
  "isolatedState": {
    "selectedCategories": [],
    "currentStep": 1,
    "isExpanded": false
  },
  "isolatedScopeId": "category-selector",
  "children": [...]
}
```

### 속성 설명

| 속성 | 타입 | 필수 | 설명 |
| ---- | ---- | ---- | ---- |
| `isolatedState` | object | ✅ | 격리된 상태의 초기값을 정의하는 객체 |
| `isolatedScopeId` | string | ❌ | 외부에서 접근할 때 사용하는 스코프 식별자 |

### 상태 접근 방법

격리된 상태는 `_isolated` 접두사로 접근합니다:

```html
<button disabled="{{_isolated.currentStep === 1}}">{{_isolated.selectedCategories.length}}개 선택됨</button>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "basic",
  "name": "Button",
  "props": {
    "disabled": "{{_isolated.currentStep === 1}}"
  },
  "text": "{{_isolated.selectedCategories.length}}개 선택됨"
}
```

### 상태 업데이트

`setState` 핸들러에서 `target: "isolated"` 옵션을 사용합니다:

```html
<on type="click">
  <set-state target="isolated" currentStep="{{_isolated.currentStep + 1}}"/>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "click",
  "handler": "setState",
  "params": {
    "target": "isolated",
    "currentStep": "{{_isolated.currentStep + 1}}"
  }
}
```

### 사용 시점

| 상황 | 권장 상태 | 이유 |
| ---- | --------- | ---- |
| 빈번한 사용자 인터랙션 | `_isolated` | 리렌더링 최소화 |
| 독립적인 UI 영역 | `_isolated` | 다른 영역과 무관한 상태 |
| 레이아웃 전체에서 공유 필요 | `_local` | 여러 컴포넌트에서 접근 |
| 페이지 이동 후에도 유지 필요 | `_global` | 앱 전역 상태 |

### 라이프사이클

```text
1. 생성: isolatedState 속성이 있는 컴포넌트 마운트 시
2. 업데이트: setState target:"isolated" 또는 G7Core.state.setIsolated()
3. 소멸: 해당 컴포넌트 언마운트 시
4. 페이지 이동: 초기화됨 (언마운트 → 재마운트)
```

### 주의사항

```text
isolatedState 외부에서 target: "isolated" 사용 시 → _local로 폴백 + 경고 로그
isolatedScopeId 중복 시 → 마지막 등록된 스코프 우선 + 경고 로그
중첩 isolatedState 시 → 가장 가까운 스코프 사용
```

### 관련 문서

- [상태 관리 - _isolated](state-management.md#격리된-상태-_isolated)
- [액션 핸들러 - target: isolated](actions-handlers-state.md#격리된-상태-변경-target-isolated)
- [G7Core API - getIsolated/setIsolated](g7core-api.md#getisolated--setisolated-v1140)

---

## 텍스트 렌더링

```
중요: 컴포넌트에 텍스트를 표시할 때는 text 속성을 사용해야 합니다.
필수: text 속성 사용 (DynamicRenderer가 최우선 처리)
필수: children 배열 사용 (하위 컴포넌트 포함)
주의: props.children 사용 불가 (무시됨)
```

### 핵심 원칙

그누보드7 템플릿 엔진의 DynamicRenderer는 다음 우선순위로 렌더링합니다:

1. **`text` 속성** (최우선) - 텍스트 콘텐츠 표시
2. **`children` 배열** - 하위 컴포넌트 렌더링
3. **`props`** - 일반 속성 전달

### 올바른 패턴

```html
<h1 data-ref="page_title" class="text-2xl font-bold">대시보드</h1>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "page_title",
  "type": "basic",
  "name": "H1",
  "props": {
    "className": "text-2xl font-bold"
  },
  "text": "대시보드"
}
```

```html
<span data-ref="logo_text" class="text-white font-bold">G7</span>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "logo_text",
  "type": "basic",
  "name": "Span",
  "props": {
    "className": "text-white font-bold"
  },
  "text": "G7"
}
```

### 잘못된 패턴

```json
{
  "id": "page_title",
  "type": "basic",
  "name": "H1",
  "props": {
    "className": "text-2xl font-bold",
    "children": "대시보드"  // ❌ props.children은 무시됨
  }
}
```

### 다국어 지원

`text` 속성에서도 다국어 키를 사용할 수 있습니다:

```html
<h1 data-ref="page_title" class="text-2xl font-bold">$t:dashboard.title</h1>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "page_title",
  "type": "basic",
  "name": "H1",
  "props": {
    "className": "text-2xl font-bold"
  },
  "text": "$t:dashboard.title"
}
```

### 데이터 바인딩 지원

`text` 속성에서 데이터 바인딩도 가능합니다:

```html
<span data-ref="user_name">{{user.name}}</span>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "user_name",
  "type": "basic",
  "name": "Span",
  "text": "{{user.name}}"
}
```

### children 배열과의 차이

```html
<!-- ✅ text 속성: 단순 텍스트 표시 -->
<h1 data-ref="title">제목</h1>
<!-- ✅ children 배열: 하위 컴포넌트 포함 -->
<card data-ref="card">
  <h2 data-ref="card_title">카드 제목</h2>
  <p data-ref="card_content">카드 내용</p>
</card>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
// ✅ text 속성: 단순 텍스트 표시
{
  "id": "title",
  "type": "basic",
  "name": "H1",
  "text": "제목"
}

// ✅ children 배열: 하위 컴포넌트 포함
{
  "id": "card",
  "type": "composite",
  "name": "Card",
  "children": [
    {
      "id": "card_title",
      "type": "basic",
      "name": "H2",
      "text": "카드 제목"
    },
    {
      "id": "card_content",
      "type": "basic",
      "name": "P",
      "text": "카드 내용"
    }
  ]
}
```

### DynamicRenderer 동작 방식

```typescript
// DynamicRenderer.tsx (코어 엔진)
const renderChildren = useMemo(() => {
  // 1. text 속성이 있으면 최우선으로 사용
  if (componentDef.text !== undefined) {
    return componentDef.text;
  }

  // 2. children이 없으면 null
  if (!componentDef.children || componentDef.children.length === 0) {
    return null;
  }

  // 3. children 배열 렌더링
  return componentDef.children.map((childDef, index) => {
    // ...
  });
}, [componentDef.text, componentDef.children]);
```

### 주의사항

- ❌ `props.children`은 DynamicRenderer에서 완전히 무시됩니다
- ✅ React 컴포넌트 개발 시 `children` prop은 정상 작동 (TSX 내부)
- ✅ 레이아웃 JSON에서는 `text` 속성과 `children` 배열만 사용

### 실제 사용 예시

```html
<div data-ref="sidebar_logo" class="flex items-center gap-3">
  <div data-ref="logo_circle" class="flex items-center justify-center w-10 h-10 bg-black rounded-full">
    <span data-ref="logo_text" class="text-white font-bold text-sm">G7</span>
  </div>
  <span data-ref="app_name" class="font-semibold text-lg">G7</span>
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "sidebar_logo",
  "type": "basic",
  "name": "Div",
  "props": {
    "className": "flex items-center gap-3"
  },
  "children": [
    {
      "id": "logo_circle",
      "type": "basic",
      "name": "Div",
      "props": {
        "className": "flex items-center justify-center w-10 h-10 bg-black rounded-full"
      },
      "children": [
        {
          "id": "logo_text",
          "type": "basic",
          "name": "Span",
          "props": {
            "className": "text-white font-bold text-sm"
          },
          "text": "G7"
        }
      ]
    },
    {
      "id": "app_name",
      "type": "basic",
      "name": "Span",
      "props": {
        "className": "font-semibold text-lg"
      },
      "text": "G7"
    }
  ]
}
```

---

## Extension Point 데이터 전달 (engine-v1.15.0+, callbacks: engine-v1.28.0+)

`extension_point`에서 주입되는 컴포넌트에 데이터와 콜백을 전달합니다.

- `props`: 데이터 전달용 — 표현식이 평가되어 전달됨 (`resolveObject` 재귀 평가)
- `callbacks`: 액션 객체 전달용 — 평가 없이 그대로 전달 (ActionDispatcher가 실행 시점에 평가)

### 호스트 레이아웃 (extension_point 정의)

```html
<extension-point name="address_search_slot" productId="{{route.id}}">
  <prop name="readOnlyFields">
    <item>zipcode</item>
    <item>address</item>
  </prop>
  <callback name="onAddressSelect">
    <set-state target="local" form.zipcode="{{$event.zipcode}}"/>
  </callback>
</extension-point>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "extension_point",
  "name": "address_search_slot",
  "props": {
    "productId": "{{route.id}}",
    "readOnlyFields": ["zipcode", "address"]
  },
  "callbacks": {
    "onAddressSelect": {
      "handler": "setState",
      "params": { "target": "local", "form.zipcode": "{{$event.zipcode}}" }
    }
  }
}
```

### 플러그인 extension JSON (주입되는 컴포넌트)

```html
<on type="click">
  <action handler="myPlugin.doSomething" id="{{extensionPointProps.productId}}" fields="{{extensionPointProps.readOnlyFields}}" callbackAction="{{extensionPointCallbacks.onAddressSelect}}"/>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "handler": "myPlugin.doSomething",
  "params": {
    "id": "{{extensionPointProps.productId}}",
    "fields": "{{extensionPointProps.readOnlyFields}}",
    "callbackAction": "{{extensionPointCallbacks.onAddressSelect}}"
  }
}
```

- `{{extensionPointProps.xxx}}`: 호스트의 `props`에서 전달된 데이터 (표현식 평가됨)
- `{{extensionPointCallbacks.xxx}}`: 호스트의 `callbacks`에서 전달된 액션 객체 (그대로 전달)

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `props` | object | ❌ | 주입 컴포넌트에 전달할 데이터 (표현식 평가) |
| `callbacks` | object | ❌ | 주입 컴포넌트에 전달할 액션 객체 (평가 없이 전달) |

전달된 값은 주입 컴포넌트와 그 자손 전체에서 참조할 수 있습니다.

검색 봇용 화면에서는 `extensionPointProps` 만 해석되고 `extensionPointCallbacks` 는 해석되지 않습니다. 색인되어야 할 내용은 `props` 로 전달하세요. 사용자 작성 콘텐츠를 넘길 때는 평문/HTML 판정 값(`isHtml`)을 함께 전달합니다 — 두 화면 모두 이 값에 따라 이스케이프하거나 위험 요소를 제거한 뒤 출력합니다. 봇 화면의 노드 문법 지원 범위는 [seo-system.md "SEO 렌더러 지원 노드 키"](../backend/seo-system.md), 정화 규칙은 같은 문서의 "봇 화면의 HTML 정화" 를 참조하세요.

---

## Deprecated 속성

하위 호환성을 위해 별칭이 유지되는 속성입니다. 새 코드에서는 권장 속성을 사용하세요.

| Deprecated | 권장 속성 | 호환성 | 비고 |
|-----------|----------|--------|------|
| `init_actions` | `initActions` | 별칭 유지 (engine-v1.0.0+) | 카멜케이스 통일 |
| `state` | `initLocal` | 별칭 유지 (engine-v1.11.0+) | `initLocal`이 더 명시적 |
| `auth_required` | `auth_mode` | 별칭 유지 (engine-v1.0.0+) | `auth_mode`가 더 세밀한 제어 지원 |

```text
기존 레이아웃에서 deprecated 속성 사용 시 정상 동작 (별칭 유지)
✅ 신규 레이아웃에서는 권장 속성만 사용
✅ 리팩토링 시 권장 속성으로 변경 권장
```

---

## 관련 문서

- [컴포넌트 개발 규칙](components.md) - basic, composite, layout 컴포넌트
- [데이터 바인딩](data-binding.md) - `{{}}` 표현식, `$t:` 다국어
- [데이터 소스](data-sources.md) - API 데이터 자동 fetch
- [상태 관리](state-management.md) - `_global` 전역 상태

## 확장이 제공하는 컴포넌트

마크업 태그는 식별자의 `_` 를 `-` 로 접은 프리픽스가 앞에 붙은 kebab 이름이고, 노드 `name` 에는 `{확장식별자}.{이름}` 이 그대로 들어간다.

```html
<sirsoft-ecommerce-product-card title="{{product.name}}"/>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "composite",
  "name": "sirsoft-ecommerce.ProductCard",
  "props": { "title": "{{product.name}}" }
}
```

마크업 어휘는 템플릿 `components.json` 단독이 아니라 템플릿 + 활성 모듈 + 활성 플러그인 3계통이다. 템플릿이 그 컴포넌트를 오버라이드했더라도 노드 `name` 은 바뀌지 않는다 — 대체는 같은 이름 아래에서 일어난다.

규약 전문: [../extension/extension-components.md](../extension/extension-components.md)

## 레이아웃 구현 규칙 (작성 `.g7.html` / 런타임 계약 `.json`)
```
0. 소스 SSoT 는 `.g7.html` 마크업 — 문법은 layout-markup.md, 산출 JSON 키 계약은 layout-json.md
1. 새로운 기능 사용 전 → 반드시 해당 규정 문서에서 지원 여부 확인
2. 지원되지 않는 문법 사용 금지 → 추측/가정으로 구현하지 않음
3. 불확실한 경우 → 기존 레이아웃 패턴 참조
4. 규정 문서에 없는 기능 → 절대 사용 금지
```

### 레이아웃 작성 체크리스트

```
□ 소스(`.g7.html`) 문법이 layout-markup.md 와 일치하는가? (산출 JSON 키 계약은 layout-json.md)
□ 레이아웃 구조가 layout-json.md 스키마와 일치하는가? (`.json` 직접 편집 또는 컴파일 산출 확인 시)
□ 사용할 컴포넌트가 3소스 중 하나에 정의되어 있는가?
  → 템플릿 소유: components.md
  → 확장 제공: {확장식별자}.{이름} — extension-components.md
  → 템플릿이 대체한 확장 컴포넌트: 이름은 확장 것 그대로 (마크업 표면 불변)
□ 컴포넌트 props가 component-props.md에 정의된 것만 사용하는가?
□ 사용할 핸들러가 actions.md에 정의되어 있는가?
□ 핸들러의 params 구조가 actions-handlers.md와 일치하는가?
□ 데이터 바인딩 문법이 data-binding.md에 정의된 형식인가?
□ 다크 모드 클래스가 dark-mode.md 규칙을 따르는가?
□ 기존 유사 레이아웃에서 동일 패턴이 사용되고 있는가?
```

### 주의 사항

```text
필수: 규정 문서에 정의된 핸들러/props/바인딩 문법만 사용 (API 응답 구조도 확인 후 바인딩)
필수: Partial은 컴포넌트 치환만 수행 (computed, data_sources, modals, state 미지원)
필수: data_sources ID 고유성 유지, 조건부 렌더링은 if 속성만 사용 (type: "conditional" 미지원)
```

### 독립 레이아웃(extends 없음)의 글로벌 호스트 컴포넌트

`toast`, `openModal` 등 글로벌 상태 기반 핸들러(`_global.toasts`, `_global.modal`)는 호스트 컴포넌트(`Toast`, `ModalRoot` 등)가 마운트되어야 화면에 렌더된다. 베이스 레이아웃(`_user_base`, `_admin_base`)은 일반적으로 이들을 마운트하므로 자식 레이아웃은 별도 작업이 필요 없지만, `extends` 없이 정의된 독립 레이아웃(예: `admin_login.g7.html`)은 호스트 컴포넌트가 자동 주입되지 않는다.

```text
필수: 독립 레이아웃에서 toast/modal 사용 시 components 최상단에 호스트 컴포넌트를 직접 추가
  - Toast: { type: "composite", name: "Toast", props: { toasts: "{{_global.toasts}}", ... } }
  - 누락 시 핸들러는 success 로 기록되나 화면에는 미노출 (조용한 실패)

필수: 의심 시 베이스 레이아웃의 호스트 컴포넌트 정의를 그대로 복사
  - sirsoft-admin_basic: layouts/_admin_base.g7.html 의 global_toast 블록
  - sirsoft-basic: layouts/_user_base.g7.html 의 토스트 컴포넌트 블록
```
