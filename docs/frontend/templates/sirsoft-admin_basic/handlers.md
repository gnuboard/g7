# sirsoft-admin_basic 핸들러

> **템플릿 식별자**: `sirsoft-admin_basic` (type: admin)
> **관련 문서**: [액션 핸들러 개요](../../actions-handlers.md) | [컴포넌트](./components.md) | [레이아웃](./layouts.md)

---

## TL;DR (5초 요약)

```text
1. setLocale: 앱 언어 변경 (locale 파라미터)
2. setTheme/initTheme: 다크/라이트 모드 전환 및 초기화
3. scrollToSection: 특정 섹션으로 스크롤 이동 (offset 지원)
4. initMenuFromUrl: URL 기반 메뉴 활성 상태 초기화
5. filterVisibility 4종: 필터 패널 가시성 저장/토글/초기화
6. multilingualTag 3종: 다국어 태그 저장/취소/업데이트
```

---

## 목차

1. [setLocale](#setlocale)
2. [setTheme / initTheme](#settheme--inittheme)
3. [scrollToSection](#scrolltosection)
4. [initMenuFromUrl](#initmenuefromurl)
5. [필터 가시성 핸들러](#필터-가시성-핸들러)
6. [다국어 태그 핸들러](#다국어-태그-핸들러)
7. [핸들러 소스 파일 매핑](#핸들러-소스-파일-매핑)

---

## setLocale

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

### params

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `locale` | string | ✅ | 변경할 로케일 코드 (예: `"ko"`, `"en"`, `"ja"`) |

### 동작

```text
1. 로케일 변경 → 번역 파일 다시 로드
2. _global.locale 자동 업데이트
3. 모든 $t: 표현식 재평가
```

### 사용 예시

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

## setTheme / initTheme

### setTheme

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

### setTheme params

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `theme` | string | ✅ | `"light"`, `"dark"`, `"auto"` (시스템 설정 따름) |

### 동작

```text
1. localStorage에 테마 설정 저장
2. document.documentElement에 class 적용 (dark/light)
3. Tailwind dark: variant 활성화/비활성화
```

### initTheme

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

### 사용 예시

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

## scrollToSection

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

### params

| 필드 | 타입 | 필수 | 기본값 | 설명 |
|------|------|------|--------|------|
| `selector` | string | ✅ | - | CSS 선택자 (예: `"#section-id"`, `".class-name"`) |
| `offset` | number | ❌ | `0` | 스크롤 오프셋 (음수: 위로, 양수: 아래로). 고정 헤더 높이 보상에 사용 |

### 동작

```text
1. document.querySelector(selector)로 대상 요소 검색
2. 요소의 위치 계산 + offset 적용
3. window.scrollTo({ top, behavior: 'smooth' })로 부드러운 스크롤
```

### 사용 예시

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

## initMenuFromUrl

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

### params

없음. 현재 URL 경로를 메뉴 항목과 매칭하여 활성 메뉴를 자동 설정합니다.

### 동작

```text
1. 현재 URL 경로 (window.location.pathname) 추출
2. 사이드바 메뉴 데이터에서 URL 매칭
3. 매칭된 메뉴 항목의 is_active 상태 설정
4. 부모 메뉴도 자동으로 펼침 상태 설정
```

### 사용 예시 (_admin_base.json)

```json
{
  "init_actions": [
    { "handler": "initTheme" },
    { "handler": "initMenuFromUrl" }
  ]
}
```

---

## 필터 가시성 핸들러

목록 화면에서 필터 패널의 가시성(표시/숨김)을 관리합니다. localStorage에 상태를 저장하여 새로고침 후에도 유지합니다.

**소스**: `src/handlers/filterVisibilityHandler.ts`

### initFilterVisibility

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

### saveFilterVisibility

현재 필터 가시성 상태를 localStorage에 저장합니다.

```json
{
  "handler": "saveFilterVisibility",
  "params": {
    "filters": "{{_local.filterVisibility}}"
  }
}
```

### toggleFilterVisibility

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

### resetFilterVisibility

모든 필터 가시성을 초기 상태로 리셋합니다.

```json
{
  "type": "click",
  "handler": "resetFilterVisibility"
}
```

### 핸들러 params 요약

| 핸들러 | params | 설명 |
|--------|--------|------|
| `initFilterVisibility` | 없음 | localStorage → `_local` 복원 |
| `saveFilterVisibility` | `{ filters }` | `_local` → localStorage 저장 |
| `toggleFilterVisibility` | `{ key }` | 특정 키 토글 |
| `resetFilterVisibility` | 없음 | 전체 초기화 |

### 사용 예시 (목록 페이지)

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

## 다국어 태그 핸들러

다국어 입력 컴포넌트(MultilingualInput)에서 사용하는 태그 관리 핸들러입니다.

**소스**: `src/handlers/multilingualTagHandler.ts`

### saveMultilingualTag

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

### cancelMultilingualTag

다국어 태그 편집을 취소합니다.

```json
{
  "handler": "cancelMultilingualTag"
}
```

### updateMultilingualTagValue

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

### 태그 핸들러 params 요약

| 핸들러 | params | 설명 |
|--------|--------|------|
| `saveMultilingualTag` | `{ field, locale }` | 태그 저장 |
| `cancelMultilingualTag` | 없음 | 편집 취소 |
| `updateMultilingualTagValue` | `{ field, locale, value }` | 값 업데이트 |

---

## 핸들러 소스 파일 매핑

| 핸들러명 | 소스 파일 | 등록 함수 |
|---------|----------|----------|
| `setLocale` | `src/handlers/setLocaleHandler.ts` | `setLocaleHandler` |
| `setTheme`, `initTheme` | `src/handlers/setThemeHandler.ts` | `initTheme` |
| `scrollToSection` | `src/handlers/scrollToSectionHandler.ts` | `scrollToSectionHandler` |
| `initMenuFromUrl` | `src/handlers/initMenuFromUrlHandler.ts` | `initMenuFromUrlHandler` |
| `initFilterVisibility`, `saveFilterVisibility`, `toggleFilterVisibility`, `resetFilterVisibility` | `src/handlers/filterVisibilityHandler.ts` | `initFilterVisibilityHandler` |
| `saveMultilingualTag`, `cancelMultilingualTag`, `updateMultilingualTagValue` | `src/handlers/multilingualTagHandler.ts` | `saveMultilingualTagHandler` |

---

## 주의사항

```text
이 핸들러들은 sirsoft-admin_basic 템플릿에서만 등록됨 (다른 템플릿에서 미지원 가능)
범용 핸들러(navigate, apiCall, setState 등)와 달리 템플릿 의존적
✅ 커스텀 핸들러이므로 template.json의 핸들러 등록 확인 필요
✅ 범용 핸들러는 actions-handlers.md 참조
```

---

## 관련 문서

- [액션 핸들러 개요](../../actions-handlers.md)
- [sirsoft-admin_basic 컴포넌트](./components.md)
- [sirsoft-admin_basic 레이아웃](./layouts.md)
- [sirsoft-basic 핸들러](../sirsoft-basic/handlers.md)
