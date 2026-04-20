# 액션 핸들러 - 네비게이션

> **메인 문서**: [actions-handlers.md](actions-handlers.md)

---

## 목차

1. [navigate](#navigate)
2. [navigateBack](#navigateback)
3. [openWindow](#openwindow)
4. [reloadExtensions](#reloadextensions) ⭐ NEW (engine-v1.38.0+)
5. [reloadRoutes](#reloadroutes) (deprecated)
6. [refresh](#refresh)

---

## navigate

페이지 이동을 처리합니다.

```json
{
  "type": "click",
  "handler": "navigate",
  "params": {
    "path": "/admin/users/{{row.id}}"
  }
}
```

### navigate params 구조

| 필드 | 타입 | 기본값 | 설명 |
|------|------|--------|------|
| `path` | string | - | 이동할 경로 |
| `query` | object | - | 쿼리 파라미터 객체 |
| `mergeQuery` | boolean | false | 기존 쿼리 파라미터와 병합 |
| `replace` | boolean | false | URL만 변경 (페이지 리로드 없음) |
| `transition_overlay_target` | string | - | `replace: true` 호출 시 `transition_overlay.target` 을 동적으로 override (engine-v1.36.0+) |
| `scroll` | string \| number \| object | `"top"` | 이동 후 스크롤 위치 (engine-v1.37.0+). `"top"` / `"preserve"` / 숫자 / `{x,y}` / `"#selector"` |
| `scrollBehavior` | string | `"instant"` | 스크롤 애니메이션 (engine-v1.37.0+). `"instant"` (즉시) / `"smooth"` (부드럽게). 기본값 `"instant"`는 템플릿 CSS의 `scroll-behavior: smooth` 전역 설정을 무시하고 페이지 전환 시 즉시 이동 |
| `fallback` | `false \| string \| object` | `"openWindow"` | 대상 경로가 현재 템플릿의 `routes.json`에 없을 때 실행할 fallback 핸들러 (engine-v1.40.0+). 기본값은 `openWindow`(새 창). `false` 시 비활성화(기존 동작), 문자열은 핸들러명만 지정, 객체는 `{handler, params}` 상세 지정. 관리자 ↔ 사용자 템플릿 교차 경로에 사용 |

### transition_overlay_target 옵션 (engine-v1.36.0+)

`replace: true` 로 탭 전환/페이지네이션 시 `updateQueryParams` 경로에 진입할 때, 레이아웃의 `transition_overlay.target` 대신 **이 호출에 한해** 다른 영역에만 spinner 를 표시하도록 한다.

**주 용도**: 탭 속 서브 탭(환경설정 > 알림 탭 > 채널 탭 등) 클릭 시 서브 탭 콘텐츠 영역에만 spinner 가 표시되어야 할 때.

```json
{
  "handler": "navigate",
  "params": {
    "path": "/admin/settings",
    "replace": true,
    "mergeQuery": true,
    "query": { "channel": "{{ch.id}}" },
    "transition_overlay_target": "notif_channel_content"
  }
}
```

**동작**:

- 미지정 시: 레이아웃의 `transition_overlay.target` 사용 (탭 콘텐츠 wrapper 등)
- 지정 시: 해당 ID 의 DOM 요소에만 spinner mount. 요소 미발견 시 `transition_overlay.fallback_target` → `#app` 순으로 3단계 폴백
- `replace: true` 아닌 일반 navigate(다른 path)는 `handleRouteChange` 경로로 가며 이 옵션은 효과 없음

**DataGrid body 영역 한정 spinner 컨벤션**:

목록 페이지 페이지네이션 시 **pagination 영역은 제외하고 그리드 본문만** spinner 를 표시하려면 `transition_overlay_target` 에 DataGrid 의 `${id}__body` suffix 를 사용한다. DataGrid composite 컴포넌트는 root Div 에 `id` 를, 테이블/카드 목록을 감싸는 내부 wrapper Div 에 `${id}__body` 를 자동으로 부여한다 (pagination 은 body wrapper 밖의 형제).

```json
// DataGrid 컴포넌트 정의
{ "type": "composite", "name": "DataGrid", "id": "users_data_grid", "props": {...} }

// 페이지네이션 navigate
{
  "handler": "navigate",
  "params": {
    "path": "/admin/users",
    "replace": true,
    "mergeQuery": true,
    "query": { "page": "{{$args[0]}}" },
    "transition_overlay_target": "users_data_grid__body"
  }
}
```

이 컨벤션으로 pagination 버튼은 spinner 위에 표시되어 사용자가 연속 클릭 가능하다.

> 상세: [layout-json-components-loading.md#wait_for — 데이터소스 가드](../frontend/layout-json-components-loading.md#wait_for--데이터소스-가드-engine-v1340)

### fallback 옵션 (engine-v1.40.0+)

대상 경로가 현재 템플릿의 `routes.json`에 등록되어 있지 않을 때(예: 관리자 화면에서 사용자 페이지 URL로 navigate 시도), 404 라우트가 아닌 다른 핸들러로 분기시킨다. **기본값은 `openWindow`** — 미지정 시 자동으로 새 창에서 열림.

**주 용도**: 알림센터에서 알림 클릭 시 관리자 ↔ 사용자 템플릿 교차 경로 처리.

**판정 흐름**:

1. `params.fallback === false` → fallback 비활성화 (SPA navigate 강행, 기존 동작)
2. `params.replace === true` → 쿼리 갱신 전용이므로 fallback 미적용
3. Router 미초기화 또는 routes 미로드 → fallback 미적용 (정상 navigate)
4. `Router.match(pathname)` 성공 → fallback 미적용 (정상 navigate)
5. 매칭 실패 → fallback 핸들러로 dispatch

**파라미터 형태**:

```jsonc
// 1. 미지정 (기본값: openWindow)
{ "handler": "navigate", "params": { "path": "/shop/products" } }

// 2. 비활성화 (기존 동작 — 미등록 경로 그대로 SPA navigate)
{ "handler": "navigate", "params": { "path": "/admin/x", "fallback": false } }

// 3. 핸들러명만 지정 (string)
{ "handler": "navigate", "params": { "path": "/shop/products", "fallback": "openWindow" } }

// 4. 상세 지정 (object) — params는 finalPath 위에 merge
{
  "handler": "navigate",
  "params": {
    "path": "/shop/products",
    "fallback": { "handler": "openWindow", "params": { "target": "_blank" } }
  }
}
```

**알림센터 클릭 패턴 (parallel + fallback)**:

알림센터에서 알림 클릭 시 mark-as-read API 호출과 navigate를 **`parallel`로 묶어** 동시 실행하면, openWindow fallback이 즉시 새 창을 열고 mark-as-read는 백그라운드에서 진행된다. `sequence`로 묶으면 mark-as-read 완료(1~2초)를 기다린 후에야 새 창이 열려 사용자 체감이 나빠진다.

```json
{
  "event": "onNotificationClick",
  "handler": "parallel",
  "params": {
    "actions": [
      {
        "handler": "navigate",
        "params": { "path": "{{$args[0].url ?? '/admin/notification-logs'}}" }
      },
      {
        "handler": "apiCall",
        "target": "/api/admin/notifications/{{$args[0].id}}/read",
        "params": { "method": "PATCH" }
      }
    ]
  }
}
```

### mergeQuery 옵션

기존 쿼리 파라미터를 유지하면서 새 파라미터를 병합합니다.

```json
{
  "handler": "navigate",
  "params": {
    "path": "/admin/users",
    "mergeQuery": true,
    "query": {
      "page": "2"
    }
  }
}
```

### 배열 쿼리 파라미터

> **버전**: engine-v1.10.0+

배열 값은 자동으로 `key[]=value1&key[]=value2` 형태로 변환됩니다.

```json
{
  "handler": "navigate",
  "params": {
    "path": "/admin/products",
    "mergeQuery": true,
    "query": {
      "sales_status[]": "{{_local.salesStatus}}"
    }
  }
}
```

**입력 예시**:

- `_local.salesStatus = ["on_sale", "sold_out"]`

**생성되는 쿼리스트링**:

- `sales_status[]=on_sale&sales_status[]=sold_out`

```text
중요: 배열 값이 빈 배열([])이거나 null이면 해당 파라미터는 쿼리스트링에서 제거됩니다.
```

**백엔드 Enum 값과 일치 필수**:

프론트엔드 필터에서 사용하는 값은 반드시 백엔드 Enum의 `value`와 동일해야 합니다.

```php
// 백엔드 Enum (ProductSalesStatus.php)
enum ProductSalesStatus: string
{
    case OnSale = 'on_sale';
    case Suspended = 'suspended';
    case SoldOut = 'sold_out';
    case ComingSoon = 'coming_soon';
}
```

```json
// ✅ 올바른 사용 (Enum 값과 일치)
"salesStatus": "{{(_local.salesStatus || []).includes('sold_out') ? ... }}"

// ❌ 잘못된 사용 (Enum에 없는 값)
"salesStatus": "{{(_local.salesStatus || []).includes('out_of_stock') ? ... }}"
```

번역 키도 Enum과 일관되게 사용:

```json
// ✅ 권장: Enum 기반 번역 키
"text": "$t:sirsoft-ecommerce.enums.sales_status.sold_out"

// 비권장: 별도의 필터용 번역 키
"text": "$t:sirsoft-ecommerce.admin.product.filter.sales_status_options.out_of_stock"
```

### replace 옵션

> **버전**: engine-v1.3.0+ (engine-v1.12.0에서 동작 방식 변경)

`replace: true`를 사용하면 **컴포넌트 리마운트 없이** URL을 변경하고 데이터를 갱신합니다. 같은 페이지 내에서 검색/필터/정렬/페이지네이션 등의 쿼리 파라미터만 변경할 때 사용합니다.

```json
{
  "handler": "navigate",
  "params": {
    "path": "/admin/products",
    "mergeQuery": true,
    "replace": true,
    "query": {
      "page": "{{_local.page}}",
      "sort": "{{_local.sortField}}",
      "order": "{{_local.sortOrder}}"
    }
  }
}
```

**동작 방식** (engine-v1.12.0+):

1. `window.history.replaceState()`로 URL 업데이트 (히스토리 교체)
2. 내부 쿼리 컨텍스트 (`query`) 업데이트
3. `auto_fetch: true`인 모든 데이터 소스 자동 refetch
4. UI 리렌더링 (컴포넌트 리마운트 없음)

**사용 사례**:

- 검색 버튼 클릭 시 필터 적용 (깜빡임 없이 데이터만 갱신)
- 정렬 드롭다운 변경 시 목록 갱신
- 페이지당 표시 개수 변경
- 페이지네이션 (같은 페이지 내 이동)

**replace vs 일반 navigate 비교**:

| 특성 | `replace: false` (기본값) | `replace: true` |
|------|--------------------------|-----------------|
| 컴포넌트 리마운트 | 발생 (전체 라우트 전환) | 발생 안 함 |
| 데이터 소스 refetch | 라우트 전환 시 자동 | 즉시 자동 refetch |
| 히스토리 스택 | 새 항목 추가 | 현재 항목 교체 |
| UI 깜빡임 | 발생 가능 | 없음 |
| 쿼리 컨텍스트 업데이트 | React Router 통해 자동 | 내부적으로 즉시 반영 |

**주의사항**:

```text
replace: true는 같은 페이지 내에서만 사용
   다른 페이지로 이동할 때는 replace: false (기본값) 사용

✅ 사용 권장: 검색, 필터, 정렬, 페이지네이션
❌ 사용 금지: 다른 페이지로 이동, 상세 페이지 진입
```

**검색 필터 예시**:

```json
{
  "id": "search_button",
  "type": "basic",
  "name": "Button",
  "text": "$t:common.search",
  "actions": [
    {
      "type": "click",
      "handler": "navigate",
      "params": {
        "path": "/admin/products",
        "replace": true,
        "mergeQuery": true,
        "query": {
          "page": 1,
          "search_field": "{{_local.filter.searchField}}",
          "search_keyword": "{{_local.filter.searchKeyword}}"
        }
      }
    }
  ]
}
```

**정렬 드롭다운 예시**:

```json
{
  "type": "basic",
  "name": "Select",
  "props": {
    "value": "{{query.sort || 'created_at'}}",
    "options": [
      { "value": "created_at", "label": "$t:common.sort.created_at" },
      { "value": "name", "label": "$t:common.sort.name" }
    ]
  },
  "actions": [
    {
      "type": "change",
      "handler": "navigate",
      "params": {
        "path": "/admin/products",
        "replace": true,
        "mergeQuery": true,
        "query": {
          "sort": "{{$event.target.value}}"
        }
      }
    }
  ]
}
```

### scroll 옵션

> **버전**: engine-v1.37.0+

페이지 이동 후 스크롤 위치를 제어합니다. **기본값은 `"top"`** 으로, 일반적인 하이퍼링크 이동 UX와 동일하게 새 페이지가 최상단에서 시작됩니다.

#### 단축 문법

| 값 | 동작 |
|----|------|
| `"top"` (기본) | `#app` 내부의 모든 스크롤 컨테이너 + `window`를 상단으로 리셋 |
| `"preserve"` | 이전 스크롤 위치 유지 (엔진이 건드리지 않음) |
| `number` | `window`를 해당 Y 좌표로 이동 |
| `{ x, y }` | `window`를 특정 좌표로 이동 |
| `"#selector"` / `".class"` | 해당 엘리먼트로 `scrollIntoView({ block: 'start' })` |

#### 확장 객체 문법

특정 스크롤 컨테이너를 지정하거나, sticky 헤더 오프셋, `scrollIntoView`의 `block` 위치 등을 세밀하게 제어할 때 사용합니다.

```ts
{
  container?: string;                       // 스크롤 컨테이너 선택자 (생략 시 window)
  to?: string | number | { x?, y? } | "top"; // 이동 대상 (생략 시 "top")
  block?: "start" | "center" | "end" | "nearest"; // scrollIntoView block (기본 "start")
  offset?: number;                          // sticky 헤더 보정 (px, 양수 = 위쪽 여유)
}
```

| 필드 | 타입 | 기본값 | 설명 |
|------|------|--------|------|
| `container` | string | `window` | 스크롤 컨테이너 CSS 선택자. 관리자 템플릿처럼 내부 div가 실제 스크롤 영역인 경우 지정 (예: `"#right_content_area"`) |
| `to` | string / number / object / `"top"` | `"top"` | 이동 대상. 엘리먼트 선택자 / Y 좌표 / `{x, y}` 좌표 / `"top"` |
| `block` | string | `"start"` | `to`가 엘리먼트 선택자일 때 스크롤 위치 지정. 네이티브 `scrollIntoView`와 동일한 의미 |
| `offset` | number | `0` | 최종 스크롤 위치에서 차감할 픽셀 수. sticky 헤더가 있는 경우 헤더 높이만큼 지정하면 대상이 헤더 아래로 가려지지 않음 |

**동작 방식**:

- 스크롤은 `requestAnimationFrame`을 통해 다음 tick에 적용되어 새 레이아웃이 DOM에 반영된 뒤 정확한 위치로 이동합니다
- `replace: true` 분기 (`updateQueryParams`)에도 동일하게 기본 `"top"`이 적용됩니다. 검색/필터/페이지네이션에서 스크롤 유지를 원하면 `"preserve"` 명시
- `scrollBehavior: "smooth"`와 조합하면 부드러운 스크롤 애니메이션이 적용됩니다
- 관리자 템플릿(`sirsoft-admin_basic`)처럼 `html/body`가 `overflow: hidden`이고 내부 div(`#right_content_area`)가 실제 스크롤 컨테이너인 구조에서는 `"top"` 단축 문법이 자동으로 모든 스크롤 컨테이너를 리셋합니다. 숫자/좌표/선택자 단축 문법은 `window`만 스크롤하므로, 내부 컨테이너 제어가 필요하면 **확장 객체 문법에서 `container` 지정**

#### 예시

**검색 필터에서 스크롤 유지**:

```json
{
  "handler": "navigate",
  "params": {
    "path": "/admin/products",
    "replace": true,
    "mergeQuery": true,
    "query": { "search_keyword": "{{_local.keyword}}" },
    "scroll": "preserve"
  }
}
```

**특정 엘리먼트로 이동 (단축)**:

```json
{
  "handler": "navigate",
  "params": {
    "path": "/admin/docs",
    "scroll": "#section-api"
  }
}
```

**관리자 템플릿 내부 컨테이너의 Y 좌표로 이동 (확장)**:

```json
{
  "handler": "navigate",
  "params": {
    "path": "/admin/users",
    "scroll": {
      "container": "#right_content_area",
      "to": 500
    }
  }
}
```

**sticky 헤더가 있는 페이지에서 섹션으로 이동 (확장)**:

```json
{
  "handler": "navigate",
  "params": {
    "path": "/admin/docs",
    "scroll": {
      "to": "#section-api",
      "offset": 80
    }
  }
}
```

**특정 엘리먼트를 화면 중앙에 위치 (확장)**:

```json
{
  "handler": "navigate",
  "params": {
    "path": "/admin/report",
    "scroll": {
      "container": "#right_content_area",
      "to": "#chart-revenue",
      "block": "center"
    }
  }
}
```

---

## openWindow

> **버전**: engine-v1.19.0+

새 브라우저 탭/창에서 URL을 엽니다. `window.open(path, '_blank')`을 호출합니다.

```json
{
  "type": "click",
  "handler": "openWindow",
  "params": {
    "path": "/admin/users/{{row.created_by}}"
  }
}
```

### openWindow params 구조

| 필드 | 타입 | 기본값 | 설명 |
|------|------|--------|------|
| `path` | string | - | 새 창에서 열 경로 (필수) |

### 사용 사례

- 회원 정보를 새 창에서 조회
- 외부 링크를 새 탭에서 열기
- 현재 페이지를 유지하면서 다른 페이지 참조

### navigate와의 차이

| 특성 | `navigate` | `openWindow` |
|------|-----------|--------------|
| 현재 페이지 유지 | X (이동) | O (유지) |
| 새 탭/창 | X | O |
| 히스토리 | 현재 탭 히스토리 변경 | 변경 없음 |

### 예시: 등록자 정보 새 창으로 보기

```json
{
  "type": "basic",
  "name": "Button",
  "props": {
    "variant": "ghost",
    "size": "sm"
  },
  "text": "$t:common.view_member",
  "actions": [
    {
      "type": "click",
      "handler": "openWindow",
      "params": {
        "path": "/admin/users/{{row.created_by}}"
      }
    }
  ]
}
```

---

## navigateBack

브라우저 히스토리에서 뒤로 이동합니다. `window.history.back()`을 호출합니다.

```json
{
  "type": "click",
  "handler": "navigateBack"
}
```

### 사용 사례

- 상세 페이지에서 목록으로 돌아가기
- 폼 페이지에서 취소 버튼 클릭 시 이전 페이지로 이동

```json
{
  "id": "back_button",
  "type": "basic",
  "name": "Button",
  "actions": [
    {
      "type": "click",
      "handler": "navigateBack"
    }
  ],
  "children": [
    {
      "type": "basic",
      "name": "Icon",
      "props": { "name": "arrow-left", "className": "w-4 h-4" }
    },
    {
      "type": "basic",
      "name": "Span",
      "text": "$t:common.back"
    }
  ]
}
```

---

## reloadExtensions

> **버전**: engine-v1.38.0+

확장(모듈/플러그인/템플릿) install/activate/deactivate/uninstall 직후 onSuccess 체인에서 호출하여 **페이지 전체 새로고침 없이** 확장 상태를 원자적으로 재동기화합니다.

내부적으로 다음을 순차 수행합니다:

1. `/api/templates/{id}/config.json` 에서 최신 `cache_version` 획득
2. localStorage 의 확장 캐시 버전 갱신
3. `Router.loadRoutes(newVersion)` — 신 버전 쿼리로 routes.json 재fetch
4. LayoutLoader 캐시 버전 갱신 및 클리어
5. TranslationEngine 재로드 — 활성 사전은 원자 교체되어 `$t:` 해석 경합 없음

```json
{
  "handler": "reloadExtensions"
}
```

### 선택 파라미터 (모듈/플러그인 에셋 동적 로드/제거)

모듈 또는 플러그인의 JS/CSS 에셋을 동시에 로드/제거하려면 `moduleInfo` 또는 `pluginInfo` 와 `action` 을 전달합니다.

```json
{
  "handler": "reloadExtensions",
  "params": {
    "moduleInfo": "{{result}}",
    "action": "add"
  }
}
```

| 파라미터 | 타입 | 설명 |
|---------|------|------|
| `moduleInfo` | object | 모듈 activate/deactivate API 응답 (`{{result}}`) — `identifier` 및 `assets` 포함 |
| `pluginInfo` | object | 플러그인 activate/deactivate API 응답 |
| `action` | 문자열 | `"add"` 또는 `"remove"` — 에셋 로드 또는 제거 |

### 사용 사례

- 모듈/플러그인/템플릿 install/activate/deactivate/uninstall onSuccess
- 관리자가 같은 세션 안에서 확장을 활성화한 뒤 새 라우트로 즉시 이동하는 UX
- 전체 페이지 새로고침(`refresh`) 을 SPA 친화적으로 대체

### 올바른 사용

```json
"onSuccess": [
  { "handler": "toast", "params": { "type": "success", "message": "$t:admin.modules.activate_success" } },
  { "handler": "refetchDataSource", "params": { "dataSourceId": "modules" } },
  {
    "handler": "reloadExtensions",
    "params": {
      "moduleInfo": "{{result}}",
      "action": "add"
    }
  }
]
```

### 주의

- 기존에 3~4건을 병렬 호출하던 `reloadRoutes` + `reloadTranslations` + `reloadModuleHandlers` / `reloadPluginHandlers` 패턴을 이 단일 핸들러로 대체하세요.
- 템플릿 activate/force_activate 에서 `refresh`(전체 새로고침)로 해결하던 케이스도 `reloadExtensions` 로 교체 가능.

---

## reloadRoutes

> **Deprecated** (engine-v1.38.0+): `reloadExtensions` 사용을 권장합니다. 하위 호환을 위해 유지되며 내부적으로 `reloadExtensions` 와 동일한 `TemplateApp.reloadExtensionState()` 로 위임됩니다.

라우트(routes.json)를 다시 로드합니다.

```json
{
  "handler": "reloadRoutes"
}
```

### 사용 사례

- (deprecated) 모듈/플러그인 설치 후 새 라우트 적용 → `reloadExtensions` 사용
- (deprecated) 동적으로 라우트 설정이 변경된 경우

---

## refresh

현재 페이지를 새로고침합니다.

```json
{
  "handler": "refresh"
}
```

### 사용 사례

- 전체 페이지 새로고침이 필요한 경우
- 상태 초기화가 필요한 경우

---

## replaceUrl

> **버전**: engine-v1.18.0+

URL만 변경하고 데이터소스 refetch나 컴포넌트 리마운트를 수행하지 않습니다. 리스트 항목 선택 시 URL에 상태를 반영할 때 사용합니다.

```json
{
  "handler": "replaceUrl",
  "params": {
    "path": "/admin/menus",
    "query": { "menu": "{{$args[0].slug}}", "mode": "view" }
  }
}
```

### replaceUrl params 구조

| 필드 | 타입 | 기본값 | 설명 |
|------|------|--------|------|
| `path` | string | 현재 경로 | 변경할 경로 (생략 시 `window.location.pathname`) |
| `query` | object | - | 쿼리 파라미터 객체 |
| `mergeQuery` | boolean | false | 기존 쿼리 파라미터와 병합 |

### replaceUrl vs navigate replace 비교

| 특성 | `navigate` (`replace: true`) | `replaceUrl` |
| ------ | ------------------------------ | -------------- |
| URL 변경 | O | O |
| 데이터소스 refetch | O (auto_fetch 전체) | **X** |
| 컴포넌트 리마운트 | X | **X** |
| 히스토리 | 현재 항목 교체 | 현재 항목 교체 |

### 사용 사례

- 리스트 항목 선택 시 URL에 선택 상태 반영 (깜빡임 없이)
- 편집/보기 모드 전환 시 URL 업데이트
- URL 복사/공유 시 현재 상태 복원 가능하도록

### path 생략 예시

`path`를 생략하면 현재 페이지 경로가 자동으로 사용됩니다.

```json
{
  "handler": "replaceUrl",
  "params": {
    "query": { "id": "{{$args[0].id}}", "mode": "view" }
  }
}
```

---

## 관련 문서

- [액션 핸들러 인덱스](actions-handlers.md)
- [상태 핸들러](actions-handlers-state.md)
- [UI 핸들러](actions-handlers-ui.md)
- [상태 관리](state-management.md)
