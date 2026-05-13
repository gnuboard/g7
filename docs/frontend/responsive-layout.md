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

> 상세 컴포넌트 목록: [sirsoft-admin_basic](templates/sirsoft-admin_basic/components.md), [sirsoft-basic](templates/sirsoft-basic/components.md)

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

### portable 프리셋 사용 규칙

`portable` 프리셋은 `mobile`과 `tablet`을 합친 범위입니다. 다음 규칙을 준수하세요:

```text
✅ 사용: portable만 단독 사용 (mobile+tablet 동일 처리)
❌ 금지: portable과 mobile/tablet 혼용
```

**올바른 사용 예시:**

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
      "event": "onClick",
      "type": "setState",
      "target": "global",
      "payload": {
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
                  "event": "onClick",
                  "type": "setState",
                  "target": "global",
                  "payload": {
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
              "event": "onClick",
              "type": "setState",
              "target": "global",
              "payload": {
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
