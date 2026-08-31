# Admin Basic — 핸들러

> 템플릿 전용 핸들러와 부트스트랩 · 진입점: [AGENTS.md](../AGENTS.md)

## 템플릿 전용 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 17개 (정의: `src/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `detectAssetUrlMode` | (템플릿 전용 — 네임스페이스 없음) |
| `checkAssetUrlModeDrift` | (템플릿 전용 — 네임스페이스 없음) |
| `setTheme` | (템플릿 전용 — 네임스페이스 없음) |
| `initTheme` | (템플릿 전용 — 네임스페이스 없음) |
| `scrollToSection` | (템플릿 전용 — 네임스페이스 없음) |
| `initMenuFromUrl` | (템플릿 전용 — 네임스페이스 없음) |
| `initFilterVisibility` | (템플릿 전용 — 네임스페이스 없음) |
| `saveFilterVisibility` | (템플릿 전용 — 네임스페이스 없음) |
| `toggleFilterVisibility` | (템플릿 전용 — 네임스페이스 없음) |
| `resetFilterVisibility` | (템플릿 전용 — 네임스페이스 없음) |
| `saveMultilingualTag` | (템플릿 전용 — 네임스페이스 없음) |
| `cancelMultilingualTag` | (템플릿 전용 — 네임스페이스 없음) |
| `updateMultilingualTagValue` | (템플릿 전용 — 네임스페이스 없음) |
| `setDateRange` | (템플릿 전용 — 네임스페이스 없음) |
| `toggleSidebar` | (템플릿 전용 — 네임스페이스 없음) |
| `initSidebar` | (템플릿 전용 — 네임스페이스 없음) |
| `downloadAttachment` | (템플릿 전용 — 네임스페이스 없음) |
<!-- @generated:handlers END -->

<!-- @intent START -->
`setLocale` 이 이 목록에 없는 것이 정상입니다 — 로케일 전환은 엔진 레벨(ActionDispatcher)
빌트인으로 승격되어 더 이상 이 템플릿이 등록하지 않습니다(`src/handlers/index.ts` 상단 주석
참고). 과거 버전 문서가 `setLocale` 을 이 템플릿의 핸들러로 적었다면 그것은 이관 전 버전
기준이므로 따르지 않습니다. 새 핸들러를 추가할 때는 `src/handlers/index.ts` 의 맵 객체 한
곳에만 등록하면 자동으로 ActionDispatcher 에 반영됩니다 — 다른 곳에 흩어 등록하지 않습니다.

이 핸들러들은 **이 템플릿에서만** 등록되므로 다른 템플릿에서는 미지원일 수 있습니다. 범용
핸들러(`navigate`/`apiCall`/`setState` 등)는 [actions-handlers.md](../../../../docs/frontend/actions-handlers.md)
를 참고하고, 여기서는 이 템플릿 전용 핸들러만 다룹니다.

### setTheme / initTheme

다크/라이트/자동(`auto`, 시스템 설정 따름) 테마를 전환·복원합니다. `setTheme` 은 localStorage
저장 + `document.documentElement` 클래스 적용(Tailwind `dark:` variant 활성화)을,
`initTheme` 은 params 없이 `init_actions` 에서 호출해 저장된 테마를 앱 시작 시 복원합니다.

```json
{ "type": "click", "handler": "setTheme", "params": { "theme": "{{_global.theme === 'dark' ? 'light' : 'dark'}}" } }
```

### scrollToSection

`params.selector`(CSS 선택자, 필수) 로 지정한 요소로 부드럽게 스크롤합니다. `params.offset`
(기본 `0`, 음수면 위로)은 고정 헤더 높이를 보상할 때 씁니다.

```json
{ "type": "click", "handler": "scrollToSection", "params": { "selector": "#features", "offset": -80 } }
```

### initMenuFromUrl

현재 URL 경로를 사이드바 메뉴 항목과 매칭해 활성 메뉴(및 부모 메뉴의 펼침 상태)를 자동
설정합니다. params 없이 `_admin_base.json` 의 `init_actions` 에서 호출합니다.

### 필터 가시성 핸들러 4종

목록 화면 필터 패널의 표시/숨김을 localStorage 에 저장해 새로고침 후에도 유지합니다.

| 핸들러 | params | 설명 |
|---|---|---|
| `initFilterVisibility` | 없음 | localStorage → `_local` 복원 (`init_actions`에서 호출) |
| `saveFilterVisibility` | `{ filters }` | `_local` → localStorage 저장 |
| `toggleFilterVisibility` | `{ key }` | 특정 필터 키 가시성 토글 |
| `resetFilterVisibility` | 없음 | 전체 초기화 |

### 다국어 태그 핸들러 3종

`MultilingualInput` 컴포넌트가 쓰는 태그 편집 핸들러입니다.

| 핸들러 | params | 설명 |
|---|---|---|
| `saveMultilingualTag` | `{ field, locale }` | 태그 저장 |
| `cancelMultilingualTag` | 없음 | 편집 취소 |
| `updateMultilingualTagValue` | `{ field, locale, value }` | 값 업데이트 |

### setDateRange

날짜 필터 프리셋 버튼(`today`/`week`/`month`/`3months`/`6months`/`1year`) 클릭 시 시작·종료일을
`YYYY-MM-DDTHH:mm:ss` 형식으로 계산해 **반환**합니다(자체적으로 상태를 갱신하지 않음) — 레이아웃
JSON 이 `sequence` + `setState` 로 반환값(`$prev.startDate` 등)을 원하는 필드에 반영합니다.

```json
{ "type": "click", "handler": "sequence", "actions": [
  { "handler": "setDateRange", "params": { "preset": "today" } },
  { "handler": "setState", "params": { "target": "local", "filter.dateFrom": "{{$prev.startDate}}", "filter.dateTo": "{{$prev.endDate}}" } }
] }
```

### 사이드바 접힘 핸들러 2종

데스크톱 좌측 사이드바 접힘 상태를 `localStorage`(`g7_admin_sidebar_collapsed`) 와
`_global.sidebarCollapsed` 에 반영합니다. 모바일 슬라이드 사이드바 상태(`_global.sidebarOpen`)와는
**독립된 상태**이므로 데스크톱 접힘과 모바일 열림/닫힘을 같은 상태로 착각하지 않습니다.

| 핸들러 | params | 설명 |
|---|---|---|
| `initSidebar` | 없음 | 저장된 접힘 상태 복원 (`init_actions`에서 호출) |
| `toggleSidebar` | 없음 | 접힘 상태 반전 + 저장 |

### downloadAttachment

관리자 화면에서 첨부파일을 `<a href>` 직접 링크가 아니라 `G7Core.api.get(url, {responseType:
'blob'})` 로 요청한 뒤 objectURL 로 변환해 다운로드합니다. `<a>` 네비게이션은 Authorization
헤더가 실리지 않아 요청이 guest 로 통과하고 활동이력의 행위자(`user_id`)가 비게 되므로, 코어
ApiClient 경유로 토큰을 자동 첨부해야 다운로드 행위가 관리자 본인 이력으로 정확히 남습니다.

```json
{ "type": "click", "handler": "downloadAttachment", "params": { "url": "{{attachment.download_url}}", "filename": "{{attachment.original_name}}" } }
```

### 자산 URL 방식 재감지 2종

관리자 환경설정 화면에서 정적 자산 URL 방식(확장자 있음/없음)을 **브라우저에서** 재감지합니다.
서버가 자기 자신에게 curl 하면 nginx vhost·프록시 체인을 우회해 오판할 수 있어, 실제 방문자
경로를 재현하려면 브라우저가 프로브를 던져야 합니다.

| 핸들러 | 호출 시점 | 동작 |
|---|---|---|
| `detectAssetUrlMode` | 관리자가 "재감지" 버튼 클릭 | 프로브 쌍(확장자 있음/없음)을 던져 판정한 뒤 폼 상태(`general.asset_url_mode`)와 감지 상태 문구를 갱신 — **저장은 하지 않음**(관리자가 결과를 보고 직접 저장) |
| `checkAssetUrlModeDrift` | 대시보드 진입 시 자동 | 저장된 설정값과 실제 감지 결과를 대조해 어긋나면 전역 상태(`assetUrlModeDrift`)에 실어 대시보드에 경고 노출 |

봇은 JavaScript 를 실행하지 않으므로 클라이언트측 자가 복구가 있어도 저장값이 틀린 채로
남으면 SEO 렌더링은 계속 깨집니다 — `checkAssetUrlModeDrift` 가 그 간극을 관리자에게 드러내는
유일한 지점입니다. 판정 불가(`unavailable`)일 때는 아무 것도 표시하지 않습니다 — 일시적
네트워크 장애를 결함 신호로 오인시키지 않기 위함입니다.
<!-- @intent END -->

## 부트스트랩

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `src/index.ts` |
| 전역 객체 | **미노출** |
| 재등록 진입점 | `initTemplate()` |

재등록 진입점이 전역에 고정 이름으로 노출되지 않으면 로케일 전환 후 이 확장의 액션이 전부 무반응이 됩니다 (오류·토스트 없음).
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
전역 객체가 "미노출"인 것은 실수가 아니라 템플릿과 모듈/플러그인의 재등록 규약 차이입니다 —
모듈/플러그인은 여러 개가 동시에 활성화되므로 서로를 식별할 `window.__[Name]` 고정 이름이
필요하지만, 템플릿은 사이트에 활성 템플릿이 항상 하나뿐이라 그런 식별 필요가 없습니다.
`initTemplate()` 을 고칠 때는 핸들러 재등록
외의 1회성 부팅 작업(예: 사이드바 초기 상태 복원)을 섞지 않습니다 — 로케일 전환마다 재실행되면
안 되는 작업이기 때문입니다(사이드바 복원은 `initSidebar` 를 레이아웃 `init_actions` 에서
별도로 호출하는 이유이기도 합니다).
<!-- @intent END -->

## 이관 원문 상세

> 아래는 코어 `docs/frontend/templates/sirsoft-admin_basic/handlers.md` 에 있던 원문을
> 이 문서로 옮긴 것입니다(#601). 핸들러별 params·동작·사용 예시가 여기에 있습니다.
> 이관 시점 그대로 보존하되, 현재 코드와 어긋나는 부분에는 정정 주석을 달았습니다 —
> 등록 핸들러의 SSoT 는 위 「템플릿 전용 핸들러」 블록입니다.

### setLocale

> **정정(#601)**: `setLocale` 은 더 이상 이 템플릿이 등록하는 핸들러가 아닙니다 — 엔진(ActionDispatcher)
> 빌트인으로 승격되어 모든 템플릿에서 동작합니다. 아래 서술은 이관 시점 기록이며, 동작·파라미터는
> 같지만 **소유 주체가 템플릿이 아니라 엔진**입니다.

앱 언어를 변경합니다. 번역 파일을 다시 로드하고 UI를 갱신합니다.

**소스**: `src/handlers/setLocaleHandler.ts`

```json
{
  "type": "click",
  "handler": "setLocale",
  "params": {
    "locale": "en"
  }
}
```

#### params

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `locale` | string | ✅ | 변경할 로케일 코드 (예: `"ko"`, `"en"`, `"ja"`) |

#### 동작

```text
1. 로케일 변경 → 번역 파일 다시 로드
2. _global.locale 자동 업데이트
3. 모든 $t: 표현식 재평가
```

#### 사용 예시

```json
{
  "id": "lang_en_btn",
  "type": "basic",
  "name": "Button",
  "props": {
    "text": "English"
  },
  "actions": [
    {
      "type": "click",
      "handler": "setLocale",
      "params": {
        "locale": "en"
      }
    }
  ]
}
```

---

### setTheme / initTheme

#### setTheme

다크/라이트 모드를 전환합니다.

**소스**: `src/handlers/setThemeHandler.ts`

```json
{
  "type": "click",
  "handler": "setTheme",
  "params": {
    "theme": "dark"
  }
}
```

#### setTheme params

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `theme` | string | ✅ | `"light"`, `"dark"`, `"auto"` (시스템 설정 따름) |

#### 동작

```text
1. localStorage에 테마 설정 저장
2. document.documentElement에 class 적용 (dark/light)
3. Tailwind dark: variant 활성화/비활성화
```

#### initTheme

앱 시작 시 저장된 테마 설정을 적용합니다. 주로 `init_actions`에서 사용합니다.

```json
{
  "init_actions": [
    {
      "handler": "initTheme"
    }
  ]
}
```

params 없이 호출합니다. localStorage에 저장된 테마 설정을 복원합니다.

#### 사용 예시

```json
{
  "id": "theme_toggle",
  "type": "composite",
  "name": "ThemeToggle",
  "actions": [
    {
      "type": "click",
      "handler": "setTheme",
      "params": {
        "theme": "{{_global.theme === 'dark' ? 'light' : 'dark'}}"
      }
    }
  ]
}
```

---

### scrollToSection

특정 섹션으로 스크롤합니다. 오프셋 지원에 특화되어 있습니다.

**소스**: `src/handlers/scrollToSectionHandler.ts`

```json
{
  "type": "click",
  "handler": "scrollToSection",
  "params": {
    "selector": "#features",
    "offset": -80
  }
}
```

#### params

| 필드 | 타입 | 필수 | 기본값 | 설명 |
|------|------|------|--------|------|
| `selector` | string | ✅ | - | CSS 선택자 (예: `"#section-id"`, `".class-name"`) |
| `offset` | number | ❌ | `0` | 스크롤 오프셋 (음수: 위로, 양수: 아래로). 고정 헤더 높이 보상에 사용 |

#### 동작

```text
1. document.querySelector(selector)로 대상 요소 검색
2. 요소의 위치 계산 + offset 적용
3. window.scrollTo({ top, behavior: 'smooth' })로 부드러운 스크롤
```

#### 사용 예시

```json
{
  "id": "nav_features",
  "type": "basic",
  "name": "A",
  "props": {
    "text": "$t:common.features",
    "className": "cursor-pointer"
  },
  "actions": [
    {
      "type": "click",
      "handler": "scrollToSection",
      "params": {
        "selector": "#features",
        "offset": -80
      }
    }
  ]
}
```

---

### initMenuFromUrl

현재 URL을 기반으로 사이드바/네비게이션 메뉴의 활성 상태를 초기화합니다. 주로 관리자 템플릿의 `init_actions`에서 사용합니다.

**소스**: `src/handlers/initMenuFromUrlHandler.ts`

```json
{
  "init_actions": [
    {
      "handler": "initMenuFromUrl"
    }
  ]
}
```

#### params

없음. 현재 URL 경로를 메뉴 항목과 매칭하여 활성 메뉴를 자동 설정합니다.

#### 동작

```text
1. 현재 URL 경로 (window.location.pathname) 추출
2. 사이드바 메뉴 데이터에서 URL 매칭
3. 매칭된 메뉴 항목의 is_active 상태 설정
4. 부모 메뉴도 자동으로 펼침 상태 설정
```

#### 사용 예시 (_admin_base.json)

```json
{
  "init_actions": [
    { "handler": "initTheme" },
    { "handler": "initMenuFromUrl" }
  ]
}
```

---

### 필터 가시성 핸들러

목록 화면에서 필터 패널의 가시성(표시/숨김)을 관리합니다. localStorage에 상태를 저장하여 새로고침 후에도 유지합니다.

**소스**: `src/handlers/filterVisibilityHandler.ts`

#### initFilterVisibility

저장된 필터 가시성 상태를 `_local`에 복원합니다.

```json
{
  "init_actions": [
    {
      "handler": "initFilterVisibility"
    }
  ]
}
```

#### saveFilterVisibility

현재 필터 가시성 상태를 localStorage에 저장합니다.

```json
{
  "handler": "saveFilterVisibility",
  "params": {
    "filters": "{{_local.filterVisibility}}"
  }
}
```

#### toggleFilterVisibility

특정 필터 키의 가시성을 토글합니다.

```json
{
  "type": "click",
  "handler": "toggleFilterVisibility",
  "params": {
    "key": "advancedFilters"
  }
}
```

#### resetFilterVisibility

모든 필터 가시성을 초기 상태로 리셋합니다.

```json
{
  "type": "click",
  "handler": "resetFilterVisibility"
}
```

#### 핸들러 params 요약

| 핸들러 | params | 설명 |
|--------|--------|------|
| `initFilterVisibility` | 없음 | localStorage → `_local` 복원 |
| `saveFilterVisibility` | `{ filters }` | `_local` → localStorage 저장 |
| `toggleFilterVisibility` | `{ key }` | 특정 키 토글 |
| `resetFilterVisibility` | 없음 | 전체 초기화 |

#### 사용 예시 (목록 페이지)

```json
{
  "init_actions": [
    { "handler": "initFilterVisibility" }
  ],
  "components": [
    {
      "id": "filter_toggle_btn",
      "type": "basic",
      "name": "Button",
      "props": {
        "text": "$t:common.toggle_filters"
      },
      "actions": [
        {
          "type": "click",
          "handler": "toggleFilterVisibility",
          "params": { "key": "advancedFilters" }
        }
      ]
    },
    {
      "id": "filter_section",
      "type": "basic",
      "name": "Div",
      "if": "{{_local.filterVisibility?.advancedFilters}}",
      "children": [
        { "comment": "필터 컴포넌트들" }
      ]
    }
  ]
}
```

---

### 다국어 태그 핸들러

다국어 입력 컴포넌트(MultilingualInput)에서 사용하는 태그 관리 핸들러입니다.

**소스**: `src/handlers/multilingualTagHandler.ts`

#### saveMultilingualTag

다국어 태그를 저장합니다.

```json
{
  "handler": "saveMultilingualTag",
  "params": {
    "field": "tags",
    "locale": "{{_global.locale}}"
  }
}
```

#### cancelMultilingualTag

다국어 태그 편집을 취소합니다.

```json
{
  "handler": "cancelMultilingualTag"
}
```

#### updateMultilingualTagValue

다국어 태그 값을 업데이트합니다.

```json
{
  "handler": "updateMultilingualTagValue",
  "params": {
    "field": "tags",
    "locale": "ko",
    "value": "{{$event.target.value}}"
  }
}
```

#### 태그 핸들러 params 요약

| 핸들러 | params | 설명 |
|--------|--------|------|
| `saveMultilingualTag` | `{ field, locale }` | 태그 저장 |
| `cancelMultilingualTag` | 없음 | 편집 취소 |
| `updateMultilingualTagValue` | `{ field, locale, value }` | 값 업데이트 |

---

### 핸들러 소스 파일 매핑

| 핸들러명 | 소스 파일 | 등록 함수 |
|---------|----------|----------|
| `setLocale` | `src/handlers/setLocaleHandler.ts` | `setLocaleHandler` |
| `setTheme`, `initTheme` | `src/handlers/setThemeHandler.ts` | `initTheme` |
| `scrollToSection` | `src/handlers/scrollToSectionHandler.ts` | `scrollToSectionHandler` |
| `initMenuFromUrl` | `src/handlers/initMenuFromUrlHandler.ts` | `initMenuFromUrlHandler` |
| `initFilterVisibility`, `saveFilterVisibility`, `toggleFilterVisibility`, `resetFilterVisibility` | `src/handlers/filterVisibilityHandler.ts` | `initFilterVisibilityHandler` |
| `saveMultilingualTag`, `cancelMultilingualTag`, `updateMultilingualTagValue` | `src/handlers/multilingualTagHandler.ts` | `saveMultilingualTagHandler` |

---

### 주의사항

```text
이 핸들러들은 sirsoft-admin_basic 템플릿에서만 등록됨 (다른 템플릿에서 미지원 가능)
범용 핸들러(navigate, apiCall, setState 등)와 달리 템플릿 의존적
✅ 커스텀 핸들러이므로 template.json의 핸들러 등록 확인 필요
✅ 범용 핸들러는 actions-handlers.md 참조
```

---

### 관련 문서

- [액션 핸들러 개요](../../../../docs/frontend/actions-handlers.md)
- [sirsoft-admin_basic 컴포넌트](components.md)
- [sirsoft-admin_basic 레이아웃](layouts.md)
- [sirsoft-basic 핸들러](../../../../docs/frontend/templates/sirsoft-basic/handlers.md)
