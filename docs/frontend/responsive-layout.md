# 반응형 레이아웃 개발 (engine-v1.1.0+)

> 그누보드7 템플릿 시스템의 반응형 UI 구현 가이드

---

## TL;DR (5초 요약)

```text
1. 화면 폭 분기는 responsive 속성 하나로만 (프리셋 mobile/tablet/desktop/portable + 커스텀 구간 min-max)
2. Tailwind 반응형 접두(sm: md: lg: xl: 2xl: min-[..]: max-[..]:)는 쓰지 않는다 — 감사 규칙이 막는다
3. 구간 값의 className 은 통째로 교체된다 → 그 구간의 전체 클래스를 쓴다
4. 전역 상태: _global.sidebarOpen 등 사용자 조작 상태는 식으로 분기
5. z-index 계층: modal(50) > dropdown(40) > sidebar(30)
```

---

## 템플릿별 지원 현황

| 템플릿 식별자 | features.responsive | 설명 |
|--------------|-------------------|------|
| `sirsoft-admin_basic` | 미선언 | 데스크톱 중심, `responsive` 속성(프리셋·커스텀 구간)으로 폭 분기 |
| `sirsoft-basic` | `true` | MobileNav 포함, portable preset 지원 |

> 상세 컴포넌트 목록: [sirsoft-admin_basic](../../templates/_bundled/sirsoft-admin_basic/docs/components.md), [sirsoft-basic](../../templates/_bundled/sirsoft-basic/docs/components.md)

---

## 목차

1. [개요](#개요)
2. [responsive 속성 (권장)](#1-responsive-속성-권장)
3. [Tailwind 반응형 접두를 쓰지 않는다](#2-tailwind-반응형-접두를-쓰지-않는다)
4. [전역 상태 기반 동적 스타일](#3-전역-상태-기반-동적-스타일)
5. [조건부 렌더링](#4-조건부-렌더링)
6. [모범 사례](#5-모범-사례)
7. [완전한 반응형 레이아웃 예시](#6-완전한-반응형-레이아웃-예시)
8. [임의의 경계 — 커스텀 구간](#7-임의의-경계--커스텀-구간)
9. [z-index 계층 관리](#8-z-index-계층-관리)

---

## 개요

그누보드7 템플릿 시스템은 화면 폭 분기를 **`responsive` 속성** 하나로 하고, 사용자 조작에 따른 변화는 전역 상태로 표현해 순수 레이아웃 JSON으로 반응형 UI를 구현합니다.

### 반응형 구현 방법 비교

| 방법 | 사용 시점 | 특징 |
|------|----------|------|
| **`responsive` 속성** | 화면 폭에 따라 className·props·children·text·if·iteration 을 바꿀 때 | 유일한 폭 분기 수단. 편집기 미리보기 폭과 검색 봇 렌더(데스크톱)도 같은 규칙을 탄다 |
| ~~Tailwind 반응형 접두~~ | 쓰지 않는다 | 폭 판정이 엔진과 CSS 두 곳으로 갈라져 클래스와 속성(`thumbPreset` 등)이 다른 경계에서 바뀐다 — [2절](#2-tailwind-반응형-접두를-쓰지-않는다) |
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
❌ 컴포넌트 클래스 문자열의 Tailwind 반응형 접두: gpp:sm:grid-cols-2 · md:flex-row (엔진 responsive 경계와 갈라진다 — 2절)
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

**셀의 `responsive` 와 다시 그리기.** 셀은 컴포넌트가 렌더하는 순간의 폭으로 분기를 고른다. 엔진은 컴포넌트가 직접 그리는
키(`cellChildren`·`expandChildren`·`expandContext`·`render`, 메타데이터 `skipBindingKeys`) 아래 정의에 `responsive` 가
있으면, 그 분기 판정을 문자열로 묶은 `__responsiveBand` prop 을 composite 컴포넌트에 넘긴다. 값은 구간 경계에서만 바뀌므로
그때만 컴포넌트가 다시 그려지고(레지스트리의 `React.memo` 비교도 이 값에서 깨진다), 같은 구간 안의 폭 변경에는 다시 그리지
않는다(engine-v1.95.0). 셀 responsive 가 없으면 이 prop 은 오지 않는다. 그래서 composite 는 폭을 구독하지 않아도 셀이
구간을 따라간다. 다만 다음 경우는 컴포넌트가 `G7Core.useResponsive()` 를 구독하고 그 폭을 메모 의존성에 넣는다.

```text
❌ 셀을 useMemo([rows]) 처럼 폭이 없는 의존성으로 미리 만들어 두는 컴포넌트 — 다시 그려져도 이전 셀을 쓴다
❌ basic·layout 타입으로 등록해 셀을 그리는 컴포넌트 — 엔진이 `__` 로 시작하는 prop 을 걸러내므로 __responsiveBand 가 오지 않는다
✅ 위 경우: const { width } = G7Core.useResponsive(); 를 부르고 셀 메모 의존성에 width 를 넣는다 (번들 DataGrid·CardGrid 방식)
✅ 받은 props 를 DOM 에 펼치는 composite 는 `__` 로 시작하는 키(__componentContext·__responsiveBand 등)를 빼고 펼친다
```

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
<card data-type="composite" data-ref="card">
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

## 2. Tailwind 반응형 접두를 쓰지 않는다

레이아웃(`.g7.html`·짝 `.json`)의 클래스 값에 Tailwind 반응형 접두를 쓰지 않는다. 확장 접두가 붙은 형태(`ecommerce:md:w-20`)와 상태 변형이 섞인 형태(`dark:md:bg-gray-800`, `md:dark:…`)도 같다. `dark:`·`hover:`·`focus:` 같은 상태 변형은 폭 판정이 아니므로 그대로 쓴다.

**이유**: 폭 판정이 엔진(`responsive`)과 CSS(미디어 쿼리) 두 곳에 있으면 경계가 갈라진다. 예를 들어 이미지 칸 크기는 Tailwind `md:`(768px)로, 썸네일 규격은 `responsive` 의 `portable`(1023px)로 정하면 768~1023px 에서 작은 칸이 큰 썸네일을 받는다. 엔진 하나로 모으면 클래스·속성·자식 교체가 같은 경계로 움직이고, 편집기 미리보기 폭과 검색 봇 렌더도 같은 규칙을 탄다.

정적 검사가 이 접두를 막는다. 면제가 꼭 필요하면 그 요소 바로 앞(또는 같은 줄)의 주석 `<!-- audit:allow layout-no-tailwind-breakpoint-variant reason: … -->` 에 사유를 적는다. 표식은 그 줄과 바로 아랫줄만 덮고, 속성 값 안의 같은 글자나 사유 없는 표식은 면제가 아니다. 짝 `.json` 은 이 주석이 컴파일된 그 노드의 `comment` 로 같은 판정을 받는다.

같은 금지는 레이아웃 파일 밖에서 만드는 노드와 부품에도 걸린다.

- **PHP 로 만드는 레이아웃 노드**: 확장 리스너가 끼워 넣거나 서비스·업그레이드 단계·시더가 조립하는 노드의 클래스 계열 키(`'className' => …`, `$node['props']['className'] = …`) 값에 접두를 쓰지 않고 노드의 `'responsive' => ['640-' => ['props' => ['className' => …]]]` 로 쓴다. 면제는 그 줄이나 바로 윗줄 주석의 `// audit:allow php-layout-node-no-tailwind-breakpoint-variant <사유>`.
- **부품 소스(`.tsx`·`.ts`)**: 번들 템플릿 `src`, 확장 `resources/js` 의 클래스 문자열(속성 값, 상수, 템플릿 리터럴의 고정 조각 모두)에 접두를 쓰지 않고 `G7Core.useResponsive()` 의 폭으로 클래스를 고른다(위 「레이아웃 편집기 미리보기에서의 화면 폭」). 시험 파일은 대상이 아니다. 면제는 그 줄이나 바로 윗줄 주석의 `// audit:allow component-tsx-no-tailwind-breakpoint-variant <사유>`(JSX 안에서는 `{/* … */}`).

### 접두 → responsive 구간 대응표

아래 키는 그 노드의 경계가 **하나뿐일 때** 쓰는 값이다. 경계가 둘 이상이면 표 아래 「함정」대로 닫힌 구간으로 나눈다.

| Tailwind 접두 | 적용 폭 | 경계가 하나일 때 `responsive` 키 | 경계가 둘 이상일 때 |
| --- | --- | --- | --- |
| `sm:` | 640px 이상 | `640-` | 다음 경계 앞에서 닫는다(예 `640-767`) |
| `md:` | 768px 이상 | `768-` | 다음 경계 앞에서 닫는다(예 `768-1023`). `tablet` 프리셋과 섞지 않는다 |
| `lg:` | 1024px 이상 | `desktop` 또는 `1024-` | 다른 구간이 커스텀이면 `1024-`(프리셋 `desktop` 을 쓰지 않는다) |
| `xl:` | 1280px 이상 | `1280-` | 맨 위 구간이면 `1280-`, 아니면 닫는다 |
| `2xl:` | 1536px 이상 | `1536-` | 맨 위 구간 `1536-` |
| `max-md:` | 767px 이하 | `mobile` 또는 `-767` | 다른 구간이 커스텀이면 `-767`(또는 `0-767`) |
| `min-[900px]:` | 900px 이상 | `900-` | 다음 경계 앞에서 닫는다(예 `900-1199`) |
| `max-[600px]:` | 599px 이하 | `-599` | `-599` |

`min-[N]:` 은 `N-`, `max-[N]:` 은 `-(N-1)` 로 옮긴다(Tailwind 의 `max-[N]` 은 N **미만**이다).

한 노드에서 엔진은 **키 하나만** 고르고(커스텀 구간 > 프리셋, 커스텀끼리는 좁은 범위 우선), 그 구간의 `className` 은 기본값을 **통째로** 교체한다. 그래서 각 구간 값에는 기본 클래스 중 그 구간에서도 살아 있는 것과 그 구간의 클래스를 모두 적는다. 같은 CSS 속성끼리는 구간 값이 이긴다(예: 기본 `px-2` + `md:p-4` → `768-` 구간은 `p-4`). 구간 값이 `className` 을 통째로 바꾸므로 `dark:` 짝도 그 구간 값에 다시 적는다([다크 모드](./dark-mode.md)).

### 함정: 열린 구간과 프리셋을 한 노드에 섞지 않는다

열린 커스텀 키(`640-`, `768-`, `1280-`)와 프리셋(`desktop`, `tablet`, `mobile`)을 한 노드에 함께 두면 예상과 다르게 움직인다. 엔진은 맞는 키가 여럿이면 **커스텀 구간을 프리셋보다 늘 먼저** 고르기 때문이다.

```text
❌ responsive: { "640-": …, "desktop": … }
   1300px → "640-" (desktop 도 맞지만 커스텀이 이긴다. desktop 값은 어느 폭에서도 쓰이지 않는다)

❌ responsive: { "768-": …, "1280-": … }
   1300px → 두 구간 모두 위가 열려 있어 넓이로 가리지 못한다. 선언 순서에 따라 결과가 갈린다

✅ responsive: { "640-767": …, "768-1023": …, "1024-": … }
   폭마다 맞는 키가 하나뿐이다
```

경계가 둘 이상이면 프리셋과 열린 구간을 섞지 말고, **겹치지 않는 닫힌 커스텀 구간**으로 나눈다. 위가 열린 구간은 맨 위 하나(`1024-` 등)만 둔다. 예: 기본 `grid-cols-1` + `sm:grid-cols-2` + `md:grid-cols-3` + `lg:grid-cols-4` → 기본 `grid-cols-1`, `640-767` 은 `grid-cols-2`, `768-1023` 은 `grid-cols-3`, `1024-` 는 `grid-cols-4`.

#### 컴파일 경고 `responsive_open_range_overlap`

`.g7.html` 을 구울 때(편집기 저장, `php artisan layout:decompile`) 한 노드의 `responsive` 에서 **열린 커스텀 구간**(한쪽 끝을 비운 키, `768-`, `-1023`)이 다른 열린 커스텀 구간이나 프리셋과 **같은 폭에서 함께 맞으면** 이 경고가 난다. 굽기는 막지 않는다. 경고에는 부품 이름과 겹친 키가 선언 순서대로 실린다.

판정은 엔진의 키 고르기를 그대로 따른다. 두 키가 함께 맞는 폭이 있으면 엔진은 하나만 골라 그 키의 값만 기본값 위에 덮어 쓰므로(`props` 는 얕은 병합, `children`·`text`·`if`·`iteration` 은 교체) 다른 키의 값은 그 폭에서 쓰이지 않는다. 고르는 순서는 커스텀 구간이 넓이와 상관없이 프리셋보다 먼저, 커스텀끼리는 좁은 구간이 먼저다. 위가 열린 구간끼리는 넓이가 둘 다 무한이라 가리지 못하고 먼저 적은 키가 남는다.

```text
⚠ { "768-": …, "1024-": … }    1024px 이상에서 둘 다 맞는다. 먼저 적은 768- 이 늘 이겨 1024- 값은 쓰이지 않는다
⚠ { "-767": …, "-1023": … }    767px 이하에서 둘 다 맞는다. 좁은 -767 이 이겨 그 폭에서 -1023 값은 버려진다
⚠ { "768-": …, "desktop": … }  1024px 이상에서 둘 다 맞는다. 커스텀이 이겨 desktop 값은 쓰이지 않는다
⚠ { "-1023": …, "768-": … }    768~1023px 에서 둘 다 맞는다

✅ { "0-767": …, "768-": … }                      겹치는 폭이 없다
✅ { "-767": …, "768-": … }                       열린 구간끼리라도 겹치지 않는다
✅ { "mobile": …, "tablet": …, "desktop": … }      프리셋끼리는 이 경고의 대상이 아니다
✅ { "640-767": …, "768-1023": …, "1024-1279": … } 닫힌 구간끼리는 대상이 아니다
✅ { "mobile": …, "0-480": … }                     닫힌 구간이 프리셋 안을 덮어쓰는 것은 의도된 사용(우선순위 예시)이다
```

닫힌 커스텀 구간이 낀 겹침(닫힌 구간끼리, 닫힌 구간과 프리셋, 닫힌 구간과 열린 구간)은 이 경고가 보지 않는다. 닫힌 구간끼리와 닫힌 구간과 열린 구간은 좁은 쪽이 이긴다. 닫힌 구간과 프리셋은 커스텀을 넓이와 상관없이 프리셋보다 먼저 고르므로 닫힌 구간이 이기고, 프리셋보다 좁은 닫힌 구간을 함께 두는 것은 의도된 덮어쓰기라 경고하지 않는다. 닫힌 커스텀이 프리셋 구간을 다 덮으면(`{ "tablet": …, "700-1100": … }` 등) 그 프리셋 값은 쓰이지 않는데, 이것은 이 경고의 범위 밖이다. `0-767` 처럼 끝을 적은 키는 값이 `-767` 과 같아도 닫힌 구간으로 본다. 한 문서에 `portable` 과 `mobile`/`tablet` 을 섞는 것은 문서 단위 경고 `responsive_breakpoint_mix` 가 따로 알린다([portable 프리셋 사용 규칙](#portable-프리셋-사용-규칙)).

### 함정: 폭 미디어를 품은 의미 클래스도 쓰지 않는다

레이아웃에 `md:` 를 적지 않아도, 정의 안에 폭 판정을 숨긴 의미 클래스를 쓰면 폭 판정이 다시 CSS 로 돌아간다. 정의에 `@apply … sm:/md:/lg:/xl:/2xl:` 나 `@media (min-width|max-width …)` 가 들어 있는 클래스가 그렇다. 확장 CSS 에 복사된 `{ns}-` 이름도 같다. 이런 클래스는 편집기 미리보기 폭과 엔진 `responsive` 경계를 따르지 않는다.

```text
❌ <div class="filter-row">                    정의: .filter-row { @apply flex flex-col md:flex-row; }
✅ <div class="flex flex-col">
     <responsive on="768-" class="flex flex-row"/>
   </div>
```

폭에 따라 바뀌는 부분은 레이아웃의 구간 값으로 펼치고, 의미 클래스 정의에는 폭과 무관한 부분만 둔다. `prefers-reduced-motion`·`print`·`hover`·`prefers-color-scheme` 같은 폭이 아닌 미디어는 대상이 아니다. 확장 생성 CSS(`g7-extension.generated.css`)는 손으로 고치지 않고, 템플릿 원본 정의를 고친 뒤 생성기를 다시 돌려 맞춘다.

번들 템플릿에 이미 있던 폭 의미 클래스(`filter-row`·`grid-2col-responsive`·`page-title` 등)와 빌드 CSS 의 반응형 접두 클래스 고정 목록은 사이트에서 직접 고친 레이아웃이 그대로 보이도록 이번 버전에 폐지 예정으로 남아 있고, 다음 출시 이후 빠진다. 번들 레이아웃·부품은 더 쓰지 않는다. 직접 고친 레이아웃이 이 클래스를 쓰고 있으면 위 예시처럼 구간 값으로 옮긴다.

관리자 템플릿(`sirsoft-admin_basic`) 부품과 필터 줄은 7.2.0 부터 폭 미디어가 없는 새 이름을 붙이고, 폭에 따른 차이는 렌더 폭(부품)이나 `responsive` 구간 값(레이아웃)으로 더한다. 사이트 CSS 가 옛 이름을 꾸몄다면 새 이름에도 같은 규칙을 준다. 옛 이름은 폐지 예정으로 이번 출시까지 남는다.

| 옛 이름(폭 미디어 포함, 폐지 예정) | 새 이름(폭과 무관) | 붙이는 곳 |
| --- | --- | --- |
| `page-title` | `page-heading` | `PageHeader` 제목 — 640px 이상 글자 크기는 렌더 폭으로 더한다 |
| `page-actions` | `page-action-group` | `PageHeader` 머리 단추 묶음 |
| `admin-footer-row` | `admin-footer-bar` | `AdminFooter` 하단 줄 — 768px 이상 가로 배치는 렌더 폭으로 더한다 |
| `modal-footer-row` | `modal-footer-bar` | `Modal` 바닥 단추 줄 — 640px 이하 세로 쌓기는 렌더 폭으로 더한다 |
| `filter-label` | `filter-field-label` | 관리자 필터 줄 라벨 — 768px 이상 모양은 레이아웃 `responsive` 구간 값으로 준다(곁 라벨 표식은 옛 이름도 계속 받는다) |

정적 검사가 레이아웃 쪽(빌드 CSS 에서 정의가 폭 미디어 안에 있는 클래스를 쓰는 값)과 원본 CSS 쪽(의미 클래스의 반응형 `@apply`·폭 미디어)을 함께 막는다. 면제가 꼭 필요하면 레이아웃에는 그 요소 바로 앞(또는 같은 줄)의 주석에 `audit:allow layout-no-width-media-semantic-class reason: …`(위 접두 면제와 같은 자리 규칙), CSS 에는 그 줄이나 바로 윗줄 주석에 `audit:allow css-no-width-media-semantic-class <사유>` 를 남긴다.

### 전환 예시

```html
<!-- ❌ Tailwind 반응형 접두 -->
<div data-ref="cell" class="w-full aspect-square md:w-20 md:h-20"/>

<!-- ✅ responsive -->
<div data-ref="cell" class="w-full aspect-square">
  <responsive on="768-" class="aspect-square w-20 h-20"/>
</div>
```

> 위는 소스(`.g7.html`), 아래는 ✅ 의 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "cell",
  "type": "basic",
  "name": "Div",
  "props": { "className": "w-full aspect-square" },
  "responsive": {
    "768-": { "props": { "className": "aspect-square w-20 h-20" } }
  }
}
```

같은 노드의 다른 속성도 같은 구간으로 바꿀 수 있다 — 칸 크기와 이미지 규격을 한 경계로 맞추는 것이 이 규칙의 목적이다.

```html
<img src="{{row.thumbnail_url}}" thumbPreset="md" class="w-full aspect-square object-cover">
  <responsive on="768-" thumbPreset="sm" class="w-20 h-20 object-cover"/>
</img>
```

---

## 3. 전역 상태 기반 동적 스타일

### 모바일 사이드바 예시

```html
<div data-ref="sidebar" class="{{_global.sidebarOpen ? 'translate-x-0' : '-translate-x-full'}} fixed inset-y-0 left-0 z-50 w-64 transition-transform duration-300 bg-white dark:bg-gray-800">
  <responsive on="desktop" class="relative w-64 translate-x-0 bg-white dark:bg-gray-800"/>
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "sidebar",
  "type": "basic",
  "name": "Div",
  "props": {
    "className": "{{_global.sidebarOpen ? 'translate-x-0' : '-translate-x-full'}} fixed inset-y-0 left-0 z-50 w-64 transition-transform duration-300 bg-white dark:bg-gray-800"
  },
  "responsive": {
    "desktop": { "props": { "className": "relative w-64 translate-x-0 bg-white dark:bg-gray-800" } }
  }
}
```

### 해석

- **모바일**: `_global.sidebarOpen` 상태에 따라 슬라이드 인/아웃
  - `true`: `translate-x-0` (제자리)
  - `false`: `-translate-x-full` (왼쪽으로 완전히 숨김)
- **데스크톱(1024px 이상)**: `responsive` 의 `desktop` 구간 값으로 교체되어 항상 표시 (`relative translate-x-0`). 교체된 값에도 `bg-white dark:bg-gray-800` 을 다시 적어야 배경과 다크 모드 짝이 남는다
- **애니메이션**: `transition-transform duration-300` (300ms)

---

## 4. 조건부 렌더링

### 오버레이 예시

```html
<div data-ref="overlay" data-if="{{_global.sidebarOpen}}" class="fixed inset-0 bg-black bg-opacity-50 z-40">
  <responsive on="desktop" data-if="{{false}}"/>
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
    "className": "fixed inset-0 bg-black bg-opacity-50 z-40"
  },
  "responsive": {
    "desktop": { "if": "{{false}}" }
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
- 데스크톱에서는 `responsive.desktop.if` 로 아예 그리지 않음 (클래스로 숨기지 않고 DOM 에서 뺀다)
- 클릭 시 사이드바 닫기

---

## 5. 모범 사례

### DO

- 화면 폭 분기는 `responsive`(프리셋·커스텀 구간)로만
- 전역 상태로 UI 상태 관리
- 조건부 렌더링으로 불필요한 요소 제거
- CSS transition으로 부드러운 애니메이션
- 레이아웃 JSON만으로 반응형 구현

### DON'T

- 플랫폼별 집합 컴포넌트 생성 금지 (MobileHeader, MobileSidebar 등)
- JavaScript로 직접 DOM 조작 금지
- 인라인 스타일 과도한 사용 지양
- Tailwind 반응형 접두(`sm:` `md:` `lg:` `xl:` `2xl:` `min-[..]:` `max-[..]:`)와 미디어 쿼리 직접 작성 금지

### 사용자가 입력한 긴 글

사이트 이름·게시판 이름·글 제목·상품명처럼 관리자나 회원이 입력하는 값은 띄어쓰기 없이 길 수 있다. 좁은 화면(297~414px)에서 그 값이 옆 단추를 화면 밖으로 밀거나 문서 폭을 넓히지 않게, 자리에 따라 아래 규칙 중 하나를 붙인다.

| 자리 | 클래스 | 결과 |
| --- | --- | --- |
| 상단 바 이름(옆에 단추 묶음) | 이름 묶음 `min-w-0 flex-1` + 이름 `truncate`, 단추 묶음 `shrink-0` | 이름이 한 줄 … 로 줄고 단추는 제자리 |
| 블록 제목·본문 | `break-words` | 줄을 바꿔 전부 표시 |
| 가로 줄(flex-row) 안 제목 — 배지·단추와 같은 줄 | `min-w-0 wrap-anywhere` | 줄어들면서 줄바꿈. `break-words` 만으로는 flex 항목이 최소 폭 아래로 줄지 않는다 |
| 좁은 라벨·배지·칩 | `truncate` + 폭 상한(`max-w-32` 등) | 한 줄 … |
| 표 셀 | 셀 `w-full max-w-0` + 안쪽 `block truncate` | 자동 표 배치가 셀을 늘리지 않음 |

- `responsive.*.props.className` 은 기본 `className` 을 **대체**한다([Props 머지 규칙](#props-머지-규칙)) — 위 클래스는 반응형 문자열에도 모두 넣는다.
- 레이아웃에만 쓰는 새 클래스는 빌드 CSS 에 없을 수 있다 — [tailwind-safelist.md](tailwind-safelist.md) 로 확인한다.
- 이미지 로고에 `max-w-full` 을 주면 `object-contain` 을 함께 줘 비율이 찌그러지지 않게 한다.

---

## 6. 완전한 반응형 레이아웃 예시

모바일 사이드바 토글이 있는 관리자 레이아웃:

```html
<div data-ref="admin_layout_root" class="flex h-screen overflow-hidden">
  <div data-ref="mobile_header" class="flex items-center justify-between h-16 border-b border-gray-200 dark:border-gray-700 px-4">
    <responsive on="768-" data-if="{{false}}"/>
    <button data-ref="mobile_menu_button" class="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">
      <on type="click">
        <set-state target="global" sidebarOpen="{{!_global.sidebarOpen}}"/>
      </on>
      <icon name="bars"/>
    </button>
  </div>
  <div data-ref="mobile_overlay" data-if="{{_global.sidebarOpen}}" class="fixed inset-0 bg-black bg-opacity-50 z-40">
    <responsive on="768-" data-if="{{false}}"/>
    <on type="click">
      <set-state target="global" :sidebarOpen="false"/>
    </on>
  </div>
  <div data-ref="sidebar" class="{{_global.sidebarOpen ? 'translate-x-0' : '-translate-x-full'}} fixed inset-y-0 left-0 z-50 w-64 transition-transform duration-300 flex flex-col border-r border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
    <responsive on="768-" class="relative w-64 translate-x-0 flex flex-col border-r border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800"/>
  </div>
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
            "className": "flex items-center justify-between h-16 border-b border-gray-200 dark:border-gray-700 px-4"
          },
          "responsive": {
            "768-": { "if": "{{false}}" }
          },
          "children": [
            {
              "id": "mobile_menu_button",
              "type": "basic",
              "name": "Button",
              "props": {
                "className": "p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg"
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
            "className": "fixed inset-0 bg-black bg-opacity-50 z-40"
          },
          "responsive": {
            "768-": { "if": "{{false}}" }
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
            "className": "{{_global.sidebarOpen ? 'translate-x-0' : '-translate-x-full'}} fixed inset-y-0 left-0 z-50 w-64 transition-transform duration-300 flex flex-col border-r border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800"
          },
          "responsive": {
            "768-": { "props": { "className": "relative w-64 translate-x-0 flex flex-col border-r border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800" } }
          }
        },
        {
          "id": "content",
          "type": "basic",
          "name": "Div",
          "props": {
            "className": "flex-1 overflow-auto"
          }
        }
      ]
    }
  ]
}
```

---

## 7. 임의의 경계 — 커스텀 구간

Tailwind 임의 값 접두(`min-[900px]:`, `max-[1200px]:`) 대신 `responsive` 의 커스텀 구간을 쓴다. [대응표](#접두--responsive-구간-대응표)대로 `min-[N]:` 은 `N-`, `max-[N]:` 은 `-(N-1)` 이다. 그래서 `max-[1200px]:` 는 `-1199`, `min-[900px]:` 과 `max-[1200px]:` 를 함께 건 폭은 `900-1199` 다.

```html
<!-- ❌ Tailwind 임의 값 접두: 900px 이상 1200px 미만은 grid, 1200px 이상은 flex -->
<div data-ref="body" class="hidden min-[900px]:max-[1200px]:grid min-[1200px]:flex"/>

<!-- ✅ responsive -->
<div data-ref="body" class="hidden">
  <responsive on="900-1199" class="grid"/>
  <responsive on="1200-" class="flex"/>
</div>
```

> 위는 소스(`.g7.html`), 아래는 ✅ 의 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "body",
  "type": "basic",
  "name": "Div",
  "props": { "className": "hidden" },
  "responsive": {
    "900-1199": { "props": { "className": "grid" } },
    "1200-": { "props": { "className": "flex" } }
  }
}
```

구간은 서로 겹치지 않게 닫아서 나누고, 위가 열린 구간은 맨 위 하나만 둔다. 겹치거나 프리셋과 섞으면 엔진이 커스텀 구간을 프리셋보다, 좁은 구간을 넓은 구간보다 먼저 골라 예상과 다른 값이 나온다([함정](#함정-열린-구간과-프리셋을-한-노드에-섞지-않는다), [우선순위](#우선순위)).

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
