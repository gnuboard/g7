# 반응형 레이아웃 개발 (engine-v1.1.0+)

> 그누보드7 템플릿 시스템의 반응형 UI 구현 가이드

---

## TL;DR (5초 요약)

```text
1. responsive 속성: 컴포넌트 레벨 breakpoint 오버라이드 (권장)
2. Tailwind breakpoint: sm/md/lg/xl/2xl 클래스
3. 전역 상태: _global.isMobile 등으로 조건부 렌더링
4. z-index 계층: modal(50) > dropdown(40) > sidebar(30)
5. 모바일 우선: 기본 스타일 후 md:, lg: 추가
```

---

## 템플릿별 지원 현황

| 템플릿 식별자 | features.responsive | 설명 |
|--------------|-------------------|------|
| `sirsoft-admin_basic` | 미선언 | 데스크톱 중심, Tailwind responsive 클래스는 동작 |
| `sirsoft-basic` | `true` | MobileNav 포함, portable preset 지원 |

> 상세 컴포넌트 목록: [sirsoft-admin_basic](../../templates/_bundled/sirsoft-admin_basic/docs/components.md), [sirsoft-basic](../../templates/_bundled/sirsoft-basic/docs/components.md)

---

## 목차

1. [개요](#개요)
2. [responsive 속성 (권장)](#1-responsive-속성-권장)
3. [Tailwind Breakpoint 활용](#2-tailwind-breakpoint-활용)
4. [전역 상태 기반 동적 스타일](#3-전역-상태-기반-동적-스타일)
5. [조건부 렌더링](#4-조건부-렌더링)
6. [모범 사례](#5-모범-사례)
7. [완전한 반응형 레이아웃 예시](#6-완전한-반응형-레이아웃-예시)
8. [임의의 breakpoint 사용](#7-임의의-breakpoint-사용)
9. [z-index 계층 관리](#8-z-index-계층-관리)

---

## 개요

그누보드7 템플릿 시스템은 **`responsive` 속성**, Tailwind CSS breakpoint, 전역 상태를 활용하여 순수 레이아웃 JSON으로 반응형 UI를 구현합니다.

### 반응형 구현 방법 비교

| 방법 | 사용 시점 | 특징 |
|------|----------|------|
| **`responsive` 속성** | props, children, text 변경 시 | 컴포넌트 레벨 오버라이드, 권장 |
| **Tailwind breakpoint** | className만 변경 시 | CSS 레벨, 간단한 스타일 변경 |
| **전역 상태** | 사용자 인터랙션 기반 | 토글 버튼 등 상태 제어 |

---

## 1. responsive 속성 (권장)

`responsive` 속성을 사용하면 화면 크기에 따라 컴포넌트의 props, children, text, if, iteration을 오버라이드할 수 있습니다.

### Breakpoint 프리셋

| 프리셋 | 화면 너비 | 설명 |
|--------|----------|------|
| `mobile` | 0 ~ 767px | 모바일 전용 |
| `tablet` | 768 ~ 1023px | 태블릿 전용 |
| `desktop` | 1024px 이상 | 데스크톱 전용 |
| `portable` | 0 ~ 1023px | 모바일 + 태블릿 (비데스크톱) |

### 확장 코드에서 같은 경계를 판정할 때

모듈·플러그인이 화면 폭에 따라 다른 동작을 고르는 경우(팝업 대신 페이지 전환 등), 그 판정은
레이아웃이 `responsive` 로 띄우는 안내와 **같은 값을 같은 방법으로** 읽어야 한다.

```ts
// ✅ 엔진과 동일 — ResponsiveManager 가 window.innerWidth 로 breakpoint 를 정한다
const PORTABLE_MAX_WIDTH = 1023;
const isPortable = window.innerWidth <= PORTABLE_MAX_WIDTH;

// ❌ 경계에서 어긋난다
const isPortable = window.matchMedia('(max-width: 1023px)').matches;
```

`matchMedia` 는 CSS 픽셀 기준이라 `devicePixelRatio` 가 정수가 아닌 환경에서 `window.innerWidth`
와 1px 어긋난다. 실측(Chrome, dPR 1.0000000447)에서 `innerWidth === 1023` 인데
`matchMedia('(max-width: 1023px)')` 가 `false` 였다. 이 어긋남은 딱 경계 폭에서만 나타나므로
개발 중에는 드러나지 않고, 화면은 "페이지로 이동합니다" 라고 안내해 놓고 실제로는 팝업이 열리는
형태로 사용자에게만 보인다.

경계값 자체도 상수로 두고 SSoT(`ResponsiveManager` 의 `portable` 정의)를 주석에 밝힌다 —
프리셋 범위가 바뀌면 확장도 함께 고쳐야 한다는 신호가 코드에 남는다.

이 판정은 **실제 기기에서 일어나는 동작**(팝업 대신 페이지 이동, 결제창 형태 등)에 쓴다. 컴포넌트가 화면에
그릴 모양을 폭으로 고를 때는 아래 「레이아웃 편집기 미리보기에서의 화면 폭」을 따른다.

### 레이아웃 편집기 미리보기에서의 화면 폭

레이아웃 편집기의 미리보기는 iframe 이 아니라 관리자 화면 안의 영역이다. 브라우저 창은 하나뿐이라,
창 폭(`window.innerWidth`)과 미리보기 기기 폭(모바일 390·태블릿 820·데스크톱 1280·사용자 지정)이 다르다.
미리보기가 기기 폭대로 보이려면 컴포넌트가 다음을 지킨다.

```text
✅ 컴포넌트 코드의 폭 분기: G7Core.useResponsive() 의 width·isMobile 등 사용 (미리보기에서는 기기 폭)
❌ 컴포넌트 코드의 폭 분기: window.innerWidth · window.matchMedia (언제나 브라우저 창 폭)
✅ 자식을 직접 그리는 컴포넌트: 받은 __componentContext 를 넘기거나 { renderEnv: __componentContext?.renderEnvRef?.current } 를 넘긴다
❌ 자식을 직접 그리면서 아무것도 넘기지 않음 (셀의 responsive 분기가 창 폭으로, 문구가 관리자 언어로 그려진다)
```

```tsx
// 폭으로 형태를 고르는 컴포넌트
const responsive = G7Core?.useResponsive?.();
const isNarrow = (responsive?.width ?? window.innerWidth) < 768; // 훅이 없을 때만 창 폭으로 폴백

// 셀을 직접 그리는 컴포넌트 (G7Core.renderItemChildren·renderComponentLayout·renderExpandContent)
G7Core.renderItemChildren(cellChildren, { row }, G7Core.getComponentMap(), key, {
  renderEnv: __componentContext?.renderEnvRef?.current,
});
```

렌더 환경(`renderEnv`)에는 폭·콘텐츠 언어·액션 처리기·컴포넌트 목록·편집 모드가 들어 있다. 편집 모드에서는
셀 안 버튼의 동작이 실행되지 않는다. 일반 화면에서는 이 값이 곧 화면의 값이라 결과가 같다.

다른 컴포넌트가 직접 그린 셀 안에 들어간 컴포넌트는 `__componentContext` 를 받지 못한다. 그런 컴포넌트도 폭은
`G7Core.useResponsive()` 로 미리보기 기기 폭을 따르지만, 그 안에서 다시 자식을 그릴 때의 콘텐츠 언어·액션 처리기는
관리자 화면 값으로 돌아간다.

**CSS 는 자동으로 맞춰진다.** 편집기가 떠 있는 동안 화면 폭 조건 `@media`(`min-width`·`max-width`·`width`
범위식, rem/em 포함)와 화면 폭 단위 `vw` 는 미리보기 영역 폭으로 판정되도록 바뀌고, 편집기를 벗어나면
원래대로 돌아온다. 선택자 이름이 아니라 조건식만 보므로 일반 CSS·Bootstrap·Tailwind·CSS-in-JS 모두 같다.
설정할 것은 없다. 다음은 바뀌지 않는다.

| 바뀌지 않는 것 | 이유 · 대안 |
|---|---|
| `document.body` 로 띄우는 드롭다운·라이트박스 | 미리보기 영역 밖에 그려진다 — 창 기준 |
| `:root`·`html` 선택자의 폭 `@media`(루트 글꼴 크기 등) | 미리보기 영역의 자손이 아니다 |
| 높이·`aspect-ratio`·`orientation` 조건, `vh` | 폭 판정이 아니다 |
| `<picture media>`·`sizes`, JS `matchMedia`·`window.innerWidth` | 브라우저가 창 기준으로 판정한다 — JS 는 `G7Core.useResponsive()` 사용 |
| 다른 사이트에서 불러온 스타일시트, `<link media>`·`<style media>` 로 시트 전체에 조건이 걸린 경우 | 규칙을 읽거나 나눌 수 없다 |
| 이름 없는 `@container` 쿼리 | 미리보기에서는 미리보기 영역에 반응할 수 있다 — 컨테이너를 직접 지정해 쓴다 |

특정 스타일시트를 이 처리에서 빼려면 그 `<style>`·`<link>` 에 `data-g7le-no-media-isolation` 속성을 단다.

### portable 프리셋 사용 규칙

`portable` 프리셋은 `mobile`과 `tablet`을 합친 범위입니다. 다음 규칙을 준수하세요:

```text
✅ 사용: portable만 단독 사용 (mobile+tablet 동일 처리)
❌ 금지: portable과 mobile/tablet 혼용
```

**올바른 사용 예시:**

```html
<div>
  <responsive on="portable" class="flex-col"/>
  본문
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "responsive": {
    "portable": {
      "props": { "className": "flex-col" }
    }
  }
}
```

**잘못된 사용 예시:**

```json
{
  "responsive": {
    "portable": { "props": { "className": "hidden" } },
    "tablet": { "props": { "className": "flex" } }
  }
}
```

**mobile과 tablet을 구분해야 하는 경우:**

```html
<div>
  <responsive on="mobile" class="grid-cols-1"/>
  <responsive on="tablet" class="grid-cols-2"/>
  본문
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "responsive": {
    "mobile": { "props": { "className": "grid-cols-1" } },
    "tablet": { "props": { "className": "grid-cols-2" } }
  }
}
```

### 커스텀 범위

| 형식 | 설명 | 예시 |
|------|------|------|
| `min-max` | 범위 지정 | `0-599`, `600-899` |
| `min-` | 최소값 이상 | `1200-` (1200px 이상) |
| `-max` | 최대값 이하 | `-599` (599px 이하) |

### 기본 사용법

```html
<button data-ref="responsive_button" class="px-4 py-2" variant="primary" size="large">
  <responsive on="mobile" class="px-2 py-1" size="small">모바일 버튼</responsive>
  <responsive on="tablet" size="medium">태블릿 버튼</responsive>
  데스크톱 버튼
</button>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "responsive_button",
  "type": "basic",
  "name": "Button",
  "props": {
    "className": "px-4 py-2",
    "variant": "primary",
    "size": "large"
  },
  "text": "데스크톱 버튼",
  "responsive": {
    "mobile": {
      "props": {
        "className": "px-2 py-1",
        "size": "small"
      },
      "text": "모바일 버튼"
    },
    "tablet": {
      "props": {
        "size": "medium"
      },
      "text": "태블릿 버튼"
    }
  }
}
```

### Props 머지 규칙

**얕은 머지 (Shallow Merge)**: 지정한 props만 대체, 미지정 props는 유지

```html
<div class="base-class" variant="primary" size="large">
  <responsive on="mobile" class="mobile-class"/>
  본문
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "props": {
    "className": "base-class",
    "variant": "primary",
    "size": "large"
  },
  "responsive": {
    "mobile": {
      "props": {
        "className": "mobile-class"
      }
    }
  }
}
```

**모바일에서 적용되는 props**:

- `className`: `"mobile-class"` (대체됨)
- `variant`: `"primary"` (유지)
- `size`: `"large"` (유지)

### children 완전 교체

```html
<card data-ref="card">
  <responsive on="mobile">
    <div data-ref="mobile-content">모바일 요약</div>
  </responsive>
  <div data-ref="desktop-content">데스크톱 전용</div>
  <div data-ref="desktop-details">상세 정보</div>
</card>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "card",
  "type": "composite",
  "name": "Card",
  "children": [
    { "id": "desktop-content", "type": "basic", "name": "Div", "text": "데스크톱 전용" },
    { "id": "desktop-details", "type": "basic", "name": "Div", "text": "상세 정보" }
  ],
  "responsive": {
    "mobile": {
      "children": [
        { "id": "mobile-content", "type": "basic", "name": "Div", "text": "모바일 요약" }
      ]
    }
  }
}
```

### if 조건 오버라이드

```html
<div data-ref="sidebar" data-if="{{true}}">
  <responsive on="mobile" data-if="{{_global.sidebarOpen}}"/>
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "sidebar",
  "type": "basic",
  "name": "Div",
  "if": "{{true}}",
  "responsive": {
    "mobile": {
      "if": "{{_global.sidebarOpen}}"
    }
  }
}
```

### 커스텀 범위 사용

```html
<div data-ref="grid" class="grid grid-cols-4">
  <responsive on="0-599" class="grid grid-cols-1"/>
  <responsive on="600-899" class="grid grid-cols-2"/>
  <responsive on="900-1199" class="grid grid-cols-3"/>
  <responsive on="1200-" class="grid grid-cols-4"/>
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "grid",
  "type": "basic",
  "name": "Div",
  "props": {
    "className": "grid grid-cols-4"
  },
  "responsive": {
    "0-599": {
      "props": { "className": "grid grid-cols-1" }
    },
    "600-899": {
      "props": { "className": "grid grid-cols-2" }
    },
    "900-1199": {
      "props": { "className": "grid grid-cols-3" }
    },
    "1200-": {
      "props": { "className": "grid grid-cols-4" }
    }
  }
}
```

### 우선순위

1. **커스텀 범위 > 프리셋**: 커스텀 범위가 프리셋보다 우선 적용
2. **좁은 범위 > 넓은 범위**: 여러 범위에 매칭되면 좁은 범위가 우선

```html
<div>
  <responsive on="mobile">모바일 프리셋</responsive>
  <responsive on="0-480">작은 모바일</responsive>
  본문
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "responsive": {
    "mobile": { "text": "모바일 프리셋" },
    "0-480": { "text": "작은 모바일" }
  }
}
```

**400px에서**: `"작은 모바일"` (커스텀 범위 우선)
**600px에서**: `"모바일 프리셋"` (프리셋만 매칭)

---

## 2. Tailwind Breakpoint 활용

### 기본 Breakpoint

| Breakpoint | 최소 너비 |
|------------|----------|
| `sm` | 640px |
| `md` | 768px |
| `lg` | 1024px |
| `xl` | 1280px |
| `2xl` | 1536px |

### 사용 예시

```html
<div data-ref="mobile_header" class="flex items-center h-16 border-b px-4 md:hidden"/>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "mobile_header",
  "type": "basic",
  "name": "Div",
  "props": {
    "className": "flex items-center h-16 border-b px-4 md:hidden"
  }
}
```

**해석**: 기본(모바일)에서는 `flex items-center h-16 border-b px-4`, md(768px) 이상에서는 `hidden`

### 다중 breakpoint

```html
<div class="w-full sm:w-1/2 md:w-1/3 lg:w-1/4 xl:w-1/6">본문</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "props": {
    "className": "w-full sm:w-1/2 md:w-1/3 lg:w-1/4 xl:w-1/6"
  }
}
```

---

## 3. 전역 상태 기반 동적 스타일

### 모바일 사이드바 예시

```html
<div data-ref="sidebar" class="{{_global.sidebarOpen ? 'translate-x-0' : '-translate-x-full'}} fixed inset-y-0 left-0 z-50 w-64 transition-transform duration-300 lg:relative lg:translate-x-0 bg-white"/>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "sidebar",
  "type": "basic",
  "name": "Div",
  "props": {
    "className": "{{_global.sidebarOpen ? 'translate-x-0' : '-translate-x-full'}} fixed inset-y-0 left-0 z-50 w-64 transition-transform duration-300 lg:relative lg:translate-x-0 bg-white"
  }
}
```

### 해석

- **모바일**: `_global.sidebarOpen` 상태에 따라 슬라이드 인/아웃
  - `true`: `translate-x-0` (제자리)
  - `false`: `-translate-x-full` (왼쪽으로 완전히 숨김)
- **데스크톱 (lg 이상)**: 항상 표시 (`lg:relative lg:translate-x-0`)
- **애니메이션**: `transition-transform duration-300` (300ms)

---

## 4. 조건부 렌더링

### 오버레이 예시

```html
<div data-ref="overlay" data-if="{{_global.sidebarOpen}}" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden">
  <on type="click">
    <set-state target="global" :sidebarOpen="false"/>
  </on>
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "overlay",
  "type": "basic",
  "name": "Div",
  "if": "{{_global.sidebarOpen}}",
  "props": {
    "className": "fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden"
  },
  "actions": [
    {
      "type": "click",
      "handler": "setState",
      "params": {
        "target": "global",
        "sidebarOpen": false
      }
    }
  ]
}
```

### 특징

- `if` 속성으로 조건부 렌더링 (사이드바 열릴 때만 DOM에 추가)
- 모바일에서만 표시 (`lg:hidden`)
- 클릭 시 사이드바 닫기

---

## 5. 모범 사례

### DO

- Tailwind breakpoint 적극 활용
- 전역 상태로 UI 상태 관리
- 조건부 렌더링으로 불필요한 요소 제거
- CSS transition으로 부드러운 애니메이션
- 레이아웃 JSON만으로 반응형 구현

### DON'T

- 플랫폼별 집합 컴포넌트 생성 금지 (MobileHeader, MobileSidebar 등)
- JavaScript로 직접 DOM 조작 금지
- 인라인 스타일 과도한 사용 지양
- 미디어 쿼리 직접 작성 지양 (Tailwind 사용)

---

## 6. 완전한 반응형 레이아웃 예시

모바일 사이드바 토글이 있는 관리자 레이아웃:

```html
<div data-ref="admin_layout_root" class="flex h-screen overflow-hidden">
  <div data-ref="mobile_header" class="flex items-center justify-between h-16 border-b px-4 md:hidden">
    <button data-ref="mobile_menu_button" class="p-2 hover:bg-gray-100 rounded-lg">
      <on type="click">
        <set-state target="global" sidebarOpen="{{!_global.sidebarOpen}}"/>
      </on>
      <icon name="bars"/>
    </button>
  </div>
  <div data-ref="mobile_overlay" data-if="{{_global.sidebarOpen}}" class="fixed inset-0 bg-black bg-opacity-50 z-40 md:hidden">
    <on type="click">
      <set-state target="global" :sidebarOpen="false"/>
    </on>
  </div>
  <div data-ref="sidebar" class="{{_global.sidebarOpen ? 'translate-x-0' : '-translate-x-full'}} fixed inset-y-0 left-0 z-50 w-64 transition-transform duration-300 md:relative md:translate-x-0 flex flex-col border-r bg-white"/>
  <div data-ref="content" class="flex-1 overflow-auto"/>
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "components": [
    {
      "id": "admin_layout_root",
      "type": "basic",
      "name": "Div",
      "props": {
        "className": "flex h-screen overflow-hidden"
      },
      "children": [
        {
          "id": "mobile_header",
          "type": "basic",
          "name": "Div",
          "props": {
            "className": "flex items-center justify-between h-16 border-b px-4 md:hidden"
          },
          "children": [
            {
              "id": "mobile_menu_button",
              "type": "basic",
              "name": "Button",
              "props": {
                "className": "p-2 hover:bg-gray-100 rounded-lg"
              },
              "actions": [
                {
                  "type": "click",
                  "handler": "setState",
                  "params": {
                    "target": "global",
                    "sidebarOpen": "{{!_global.sidebarOpen}}"
                  }
                }
              ],
              "children": [
                {
                  "type": "basic",
                  "name": "Icon",
                  "props": {
                    "name": "bars"
                  }
                }
              ]
            }
          ]
        },
        {
          "id": "mobile_overlay",
          "type": "basic",
          "name": "Div",
          "if": "{{_global.sidebarOpen}}",
          "props": {
            "className": "fixed inset-0 bg-black bg-opacity-50 z-40 md:hidden"
          },
          "actions": [
            {
              "type": "click",
              "handler": "setState",
              "params": {
                "target": "global",
                "sidebarOpen": false
              }
            }
          ]
        },
        {
          "id": "sidebar",
          "type": "basic",
          "name": "Div",
          "props": {
            "className": "{{_global.sidebarOpen ? 'translate-x-0' : '-translate-x-full'}} fixed inset-y-0 left-0 z-50 w-64 transition-transform duration-300 md:relative md:translate-x-0 flex flex-col border-r bg-white"
          },
          "children": []
        },
        {
          "id": "content",
          "type": "basic",
          "name": "Div",
          "props": {
            "className": "flex-1 overflow-auto"
          },
          "children": []
        }
      ]
    }
  ]
}
```

---

## 7. 임의의 breakpoint 사용

Tailwind v3부터는 임의의 값을 직접 지정할 수 있습니다:

```html
<div class="hidden min-[900px]:flex max-[1200px]:grid">본문</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "props": {
    "className": "hidden min-[900px]:flex max-[1200px]:grid"
  }
}
```

---

## 8. z-index 계층 관리

반응형 레이아웃에서 z-index 계층 구조:

| z-index | 용도 | 설명 |
|---------|------|------|
| `z-50` | 사이드바 | 최상위 |
| `z-40` | 오버레이 | 사이드바 아래, 컨텐츠 위 |
| `z-auto` | 컨텐츠 | 기본값 |

---

## 관련 문서

- [컴포넌트 개발 규칙](./components.md)
- [전역 상태 관리](./state-management.md)
- [다크 모드 지원](./dark-mode.md)
- [레이아웃 JSON 스키마](./layout-json.md)
