# 템플릿 개발 가이드라인

> 그누보드7 템플릿 개발을 위한 디렉토리 구조, 개발 프로세스, 빌드 과정 가이드

---

## TL;DR (5초 요약)

```text
1. 디렉토리: templates/[vendor-template]/ (예: sirsoft-admin_basic)
2. 필수 파일: template.json, routes.json, components.json
3. 컴포넌트: src/components/{basic,composite,layout}/
4. 빌드: php artisan template:build [identifier]
5. 핸들러: src/handlers/에 커스텀 액션 핸들러 정의
```

---

## 목차

1. [디렉토리 구조](#1-디렉토리-구조)
2. [개발 프로세스](#2-개발-프로세스)
3. [커스텀 핸들러 개발](#3-커스텀-핸들러-개발)
4. [빌드 프로세스](#4-빌드-프로세스)
5. [템플릿 네이밍 규칙](#5-템플릿-네이밍-규칙)
6. [코어 엔진과 템플릿의 역할 분리](#6-코어-엔진과-템플릿의-역할-분리)

---

## 1. 디렉토리 구조

```
templates/_bundled/[vendor-template]/
├── template.json          # 템플릿 메타데이터
├── routes.json            # 기본 라우트 정의
├── components.json        # 컴포넌트 매니페스트
├── /src/
│   ├── /components/
│   │   ├── /basic/        # 기본 컴포넌트
│   │   ├── /composite/    # 집합 컴포넌트
│   │   └── /layout/       # 레이아웃 컴포넌트
│   ├── /handlers/         # 커스텀 액션 핸들러
│   │   ├── index.ts       # handlerMap export
│   │   └── *.ts           # 개별 핸들러 파일
│   └── index.ts           # 컴포넌트 export
├── /dist/                 # 빌드 결과
│   └── components.js
└── /tests/                # Vitest 테스트
```

### 주요 파일 설명

| 파일 | 설명 |
|------|------|
| `template.json` | 템플릿 이름, 버전, 작성자 등 메타데이터 |
| `routes.json` | URL 경로와 레이아웃 매핑 정의 |
| `components.json` | 등록된 컴포넌트 목록 (basic, composite, layout) |
| `/src/components/` | 컴포넌트 소스 코드 |
| `/src/handlers/` | 커스텀 액션 핸들러 (init_actions, lifecycle, actions에서 사용) |
| `/dist/components.js` | 빌드된 컴포넌트 번들 |
| `/tests/` | 컴포넌트 테스트 파일 (Vitest) |

---

## 2. 개발 프로세스

### Step 1: 컴포넌트 구현

`/src/components/` 디렉토리에 컴포넌트 개발:

- 기본/집합/레이아웃 컴포넌트 개발
- **HTML 태그 직접 사용 금지**
- 기존 컴포넌트 재사용 우선

```tsx
// ✅ DO: 기본 컴포넌트 사용
import { Div, H2, Button } from '../basic';

export const Card: React.FC<CardProps> = ({ title, onClick }) => (
  <Div className="p-4 bg-white dark:bg-gray-800">
    <H2>{title}</H2>
    <Button onClick={onClick}>확인</Button>
  </Div>
);

// ❌ DON'T: HTML 태그 직접 사용
export const Card = ({ title }) => (
  <div className="p-4">  {/* 금지! */}
    <h2>{title}</h2>      {/* 금지! */}
  </div>
);
```

### Step 2: 컴포넌트 등록

`components.json`에 컴포넌트 등록:

```json
{
  "basic": ["Button", "Input", "Div", "Span", "H1", "H2"],
  "composite": ["Card", "Modal", "PageHeader", "DataGrid"],
  "layout": ["Container", "Grid", "AdminLayout"]
}
```

### Step 3: 기본 레이아웃 정의

`/layouts/*.json`에 기본 레이아웃 정의:

- JSON 스키마 준수
- 데이터 바인딩/다국어 활용

```json
{
  "version": "1.0.0",
  "layout": {
    "name": "AdminLayout",
    "children": [
      {
        "name": "PageHeader",
        "props": {
          "title": "$t:dashboard.title"
        }
      }
    ]
  }
}
```

### Step 4: 빌드

```bash
# Artisan 명령어 사용 (권장)
php artisan template:build sirsoft-admin_basic

# 모든 템플릿 빌드
php artisan template:build --all

# 파일 감시 모드
php artisan template:build sirsoft-admin_basic --watch
```

- `/dist/components.js` 생성
- 코어 엔진과 독립적으로 빌드

### Step 5: 테스트

#### 테스트 실행 규칙

```text
템플릿은 자체 vitest.config.ts를 가지며, 루트의 setup 파일과 alias를 참조함
✅ 템플릿 디렉토리에서 실행해도 루트와 동일하게 동작
✅ server.fs.allow로 루트 디렉토리 접근 허용
```

#### 템플릿 vitest.config.ts 표준

각 템플릿 디렉토리에 `vitest.config.ts` 파일이 필요합니다:

```typescript
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'path';

const rootDir = path.resolve(__dirname, '../..');

export default defineConfig({
  plugins: [react()],
  server: {
    fs: {
      allow: [rootDir],  // 루트 디렉토리 접근 허용
    },
  },
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: [path.resolve(rootDir, 'resources/js/tests/setup.ts')],
    include: ['src/**/*.{test,spec}.{ts,tsx}'],
  },
  resolve: {
    alias: {
      '@': path.resolve(rootDir, 'resources/js'),
    },
  },
});
```

#### 테스트 실행 방법

**루트에서 실행:**

```powershell
# 전체 테스트 (모든 템플릿 포함)
powershell -Command "npm run test:run"

# 특정 템플릿 테스트
powershell -Command "npm run test:run -- templates/sirsoft-admin_basic"

# 특정 테스트 필터
powershell -Command "npm run test:run -- DataGrid"
```

**템플릿 디렉토리에서 실행:**

```powershell
cd templates/sirsoft-admin_basic
powershell -Command "npm run test:run"              # 해당 템플릿 전체 테스트
powershell -Command "npm run test:run -- DataGrid"  # 특정 테스트 필터
```

#### 왜 이 설정이 필요한가?

| 문제 | 해결 |
|------|------|
| 루트 setup 파일 참조 필요 | `server.fs.allow`로 루트 디렉토리 접근 허용 |
| alias(@) 미적용 | `resolve.alias`에 루트 기준 경로 설정 |
| 테스트 범위 제한 | `include`로 해당 템플릿만 테스트 |

- 모든 컴포넌트 테스트 작성 필수
- Vitest 사용

---

## 3. 커스텀 핸들러 개발

커스텀 핸들러는 `init_actions`, `lifecycle`, `actions`에서 호출하는 템플릿 전용 함수입니다.

### 핸들러 파일 구조

```
/src/handlers/
├── index.ts              # handlerMap export
├── setThemeHandler.ts    # 테마 관련 핸들러
├── setLocaleHandler.ts   # 언어 관련 핸들러
└── ...
```

### 핸들러 작성 규칙

```typescript
// handlers/setThemeHandler.ts

/**
 * 핸들러 함수 시그니처
 * @param action - 액션 정의 (handler, target, params 등)
 * @param context - 액션 컨텍스트 (data, event, navigate 등)
 */
export async function setThemeHandler(
  action: any,
  context?: any
): Promise<void> {
  // action.target: 바인딩이 해결된 값
  const theme = action?.target;

  if (theme) {
    localStorage.setItem('g7_color_scheme', theme);
    applyTheme(theme);
  }
}
```

### handlerMap 등록

```typescript
// handlers/index.ts
import { setThemeHandler, initThemeHandler } from './setThemeHandler';
import { setLocaleHandler, updateMyLanguageHandler } from './setLocaleHandler';

export const handlerMap = {
  // 테마 핸들러
  setTheme: setThemeHandler,
  initTheme: initThemeHandler,

  // 언어 핸들러
  setLocale: setLocaleHandler,
  updateMyLanguage: updateMyLanguageHandler,
} as const;

export type HandlerName = keyof typeof handlerMap;
```

### 레이아웃 JSON에서 사용

**init_actions (레이아웃 로드 시)**:

```json
{
  "init_actions": [
    {
      "handler": "initTheme",
      "target": "{{query.theme}}"
    }
  ]
}
```

**lifecycle (컴포넌트 마운트/언마운트 시)**:

```json
{
  "id": "my_component",
  "lifecycle": {
    "onMount": [
      { "type": "click", "handler": "loadData", "target": "{{route.id}}" }
    ],
    "onUnmount": [
      { "type": "click", "handler": "cleanup" }
    ]
  }
}
```

**actions (이벤트 기반)**:

```json
{
  "actions": [
    {
      "type": "click",
      "handler": "setTheme",
      "target": "dark"
    }
  ]
}
```

### 핸들러에서 접근 가능한 데이터

| 데이터 | 접근 방법 | 설명 |
|--------|----------|------|
| target 값 | `action.target` | 바인딩 해결된 target |
| params | `action.params` | 바인딩 해결된 params 객체 |
| 이벤트 | `context.event` | DOM 이벤트 객체 |
| 데이터 컨텍스트 | `context.data` | route, query, _global 등 |
| navigate 함수 | `context.navigate` | 페이지 이동 함수 |

### 주의사항

- ✅ 핸들러는 반드시 `handlerMap`에 등록해야 함
- ✅ 핸들러는 async 함수로 작성 가능
- ✅ `action.target`은 바인딩이 해결된 값으로 전달됨
- ❌ 등록되지 않은 핸들러는 경고 로그 출력 후 무시됨
- ❌ 핸들러 내에서 직접 DOM 조작은 최소화

---

## 4. 빌드 프로세스

### Artisan 빌드 명령어 (권장)

G7은 크로스 플랫폼 호환을 위해 Artisan 빌드 명령어를 제공합니다:

```bash
# 코어 템플릿 엔진 빌드 (기본)
php artisan core:build

# 코어 전체 빌드 (npm run build)
php artisan core:build --full

# 코어 파일 감시 모드
php artisan core:build --watch

# 템플릿 빌드
php artisan template:build sirsoft-admin_basic

# 모든 템플릿 빌드
php artisan template:build --all

# 템플릿 파일 감시 모드
php artisan template:build sirsoft-admin_basic --watch
```

### 빌드 명령어 요약

| 명령어 | 설명 | 결과물 |
|--------|------|--------|
| `php artisan core:build` | 코어 템플릿 엔진 빌드 | `/public/build/core/template-engine.min.js` |
| `php artisan core:build --full` | 코어 전체 빌드 | `/public/build/` |
| `php artisan template:build [id]` | 템플릿 빌드 | `/templates/[id]/dist/components.js` |
| `php artisan module:build [id]` | 모듈 빌드 | `/modules/[id]/dist/` |

### 빌드 순서 (권장)

```bash
# 1. 코어 빌드
php artisan core:build

# 2. 템플릿 빌드
php artisan template:build sirsoft-admin_basic

# 3. 모듈 빌드 (필요시)
php artisan module:build sirsoft-ecommerce
```

### 직접 npm 사용 시 (Windows)

Windows 환경에서 npm을 직접 사용해야 하는 경우 PowerShell 래퍼를 사용합니다:

```powershell
# 코어 빌드
powershell -Command "npm run build:core"

# 템플릿 빌드
powershell -Command "cd templates/sirsoft-admin_basic; npm run build"
```

### Vite 설정 주의사항

외부 라이브러리(react-select, lodash 등)를 사용하는 경우, 브라우저 환경에서 `process is not defined` 오류가 발생할 수 있습니다. 이를 방지하려면 `vite.config.ts`에 다음 설정이 필수입니다:

```typescript
// vite.config.ts
export default defineConfig({
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  // ... 기타 설정
});
```

**왜 필요한가?**

- 많은 npm 패키지가 Node.js 환경의 `process.env.NODE_ENV`를 참조
- 브라우저에서는 `process` 객체가 존재하지 않음
- IIFE 빌드 시 런타임 오류 발생 가능
- Vite의 `define` 옵션으로 빌드 시점에 문자열로 치환

---

## 5. 템플릿 네이밍 규칙

### 형식

`[vendor-template]` (GitHub 스타일)

### 디렉토리 예시

- `/templates/sirsoft-admin_basic/` - sirsoft의 기본 관리자 템플릿
- `/templates/johndoe-shop/` - johndoe의 쇼핑몰 템플릿

### 네임스페이스 매핑

| 항목 | 값 |
|------|------|
| 디렉토리 | `/templates/sirsoft-admin_basic/` |
| 식별자 | `sirsoft-admin_basic` |

---

## 6. 코어 엔진과 템플릿의 역할 분리

### 핵심 원칙

```
✅ 코어 = 렌더링 로직만 제공
✅ 템플릿 = 컴포넌트만 제공
✅ 데이터 바인딩, 다국어, 액션 = 코어가 자동 처리
```

### 코어 엔진 (그누보드7 Core 제공)

**위치**: `/resources/js/core/template-engine/`

**빌드 결과**: `/public/build/core/template-engine.js`

**구성 요소**:

| 모듈 | 역할 |
|------|------|
| `ComponentRegistry` | 컴포넌트 등록 및 조회 |
| `DataBindingEngine` | `{{data}}` 바인딩 처리 |
| `TranslationEngine` | `$t:key` 다국어 처리 |
| `ActionDispatcher` | `actions` 액션 핸들러 변환 |
| `DynamicRenderer` | 레이아웃 JSON → React 컴포넌트 렌더링 |
| `TemplateRenderer` | 템플릿 전체 렌더링 관리 |

**금지 사항**:
- ❌ 템플릿에서 렌더링 엔진 구현 금지
- ❌ 템플릿에서 코어 엔진 파일 수정 금지

### 템플릿 (창작자 제공)

**위치**: `/templates/[vendor-template]/src/components/`

**빌드 결과**: `/templates/[vendor-template]/dist/components.js`

**제공 항목**:
- 기본 컴포넌트 (Button, Input, Div 등)
- 집합 컴포넌트 (Card, Modal, PageHeader 등)
- 레이아웃 컴포넌트 (Container, Grid 등)

**금지 사항**:
- ❌ 렌더링 엔진 구현 금지
- ❌ 데이터 바인딩 로직 금지 (코어가 처리)
- ❌ 액션 디스패칭 로직 금지 (코어가 처리)
- ❌ HTML 태그 직접 사용 금지

### 동작 방식

**1. 레이아웃 JSON 정의** (사용자):

```json
{
  "name": "Card",
  "props": {
    "title": "{{user.name}}",
    "onClick": "navigate:/users/:id"
  }
}
```

**2. 코어 엔진 처리** (자동):

- `{{user.name}}` → DataBindingEngine이 실제 값으로 치환
- `onClick` → ActionDispatcher가 함수로 변환
- 처리된 props를 컴포넌트에 전달

**3. 컴포넌트 렌더링** (템플릿):

```tsx
export const Card: React.FC<CardProps> = ({ title, onClick }) => (
  <Div onClick={onClick}>
    <H2>{title}</H2>
  </Div>
);
```

---

## 템플릿 부트스트랩 의무 (engine-v1.46.0+)

`src/index.ts` 의 `initTemplate()` 함수에서 다음을 등록해야 합니다:

1. 커스텀 핸들러 등록 — `actionDispatcher.registerHandler(name, handler)` (모든 템플릿 공통)
2. **IDV 모달 launcher 등록** — `window.G7Core.identity.setLauncher(launcher)` (engine-v1.46.0+ 신규)

본인인증 정책이 활성화되면 코어 `IdentityGuardInterceptor` 가 428 응답을 가로채 launcher 를 호출합니다. launcher 미등록 시 코어 `defaultLauncher` 가 토스트 + `/identity/challenge` 풀페이지 navigate 로 폴백합니다.

```typescript
// src/index.ts
import { handlerMap } from './handlers';
import { registerMyTemplateIdentityLauncher } from './handlers/identityLauncher';

export function initTemplate(): void {
  if (typeof window === 'undefined') return;

  const registerHandlers = () => {
    const actionDispatcher = (window as any).G7Core?.getActionDispatcher?.();
    if (actionDispatcher) {
      Object.entries(handlerMap).forEach(([name, handler]) => {
        actionDispatcher.registerHandler(name, handler);
      });
      // IDV launcher 는 핸들러 등록 직후 — G7Core.identity 준비됨
      registerMyTemplateIdentityLauncher();
    }
    // ...
  };
  // ...
}
```

상세 가이드: [../extension/template-idv-bootstrap.md](../extension/template-idv-bootstrap.md).

---

## 레이아웃 편집기 스펙(editor-spec.json)의 스타일 컨트롤

레이아웃 편집기 속성 모달의 스타일 컨트롤은 `editor-spec.json` 의 `controls` 에서
정의한다. 코어 편집기는 위젯 UI 와 적용/역해석 메커니즘(recipeEngine)만 제공하고,
**어떤 코드를 생성할지는 컨트롤의 `apply` 선언이 정한다**(스타일 시스템 비종속, 원칙 4.8).
`apply` 종류는 `classToken`(클래스 토큰), `styleProp`(인라인 style), `cssVar`, `propValue`.

### 색/크기/배경 컨트롤은 템플릿 스타일 라이브러리 대응을 apply 로 선언한다

색상(글자색/배경색)·크기(가로/세로)·배경 컨트롤은 **그 템플릿이 사용하는 스타일
라이브러리 표현**으로 적용·역해석되도록 `apply` 를 선언해야 한다.

- **토큰형 라이브러리(Tailwind)**: 색 컨트롤을 `classToken` 으로 선언한다.
  - 프리셋 색은 `options` 에 고정 토큰(`apply.tokens`)으로 — 예 `text-gray-900`.
    프리셋 토큰은 라이트·다크 모두 동작한다(편집기 다크 탭이 `dark:` prefix 부여).
  - 자유 색(컬러 피커/HEX)은 control-level `apply.tokenTemplate`(예 `text-[{value}]`)로.
  - 각 `options` 항목에 `swatch`(표시용 HEX) 를 주면 색 위젯이 토큰 스와치를 렌더한다.
- **인라인/CSS 변수 라이브러리**: `styleProp`/`cssVar` 로 선언(다크 표현 한계 명시).

#### 자유값(임의 색/크기)의 다크 한계

Tailwind 는 빌드 시 safelist 에 없는 임의값 클래스(`dark:text-[#hex]`)를 CSS 에 넣지
못한다. 따라서 **자유값(직접 입력한 HEX/임의 px)은 라이트 전용**이다. 편집기 다크 탭에서는
자유 입력칸이 비활성화되고 "패널 프리셋 색을 고르면 다크에도 적용됩니다" 안내가 표시된다
(프리셋 토큰만 다크 적용). 프리셋 토큰의 `dark:` 변형은 빌드에 포함되도록 safelist 에
보장한다 — `sirsoft-admin_basic` 은 `scripts/extract-safelist.cjs` 가 editor-spec 의
`apply.tokens` 에서 자동 도출, `sirsoft-basic` 은 `src/styles/safelist.txt` 에 명시.

### 다크 모드 표현 선언(darkMode)과 편집기 프리뷰 격리

편집기 프리뷰는 어드민 환경이 다크 테마(`html.dark`)여도 라이트/다크를 독립적으로
보여줘야 한다. 이를 위해 템플릿은 editor-spec 최상위에 `darkMode` 를 선언한다:

```json
"darkMode": {
  "strategy": "ancestor-class",
  "ancestorSelector": ".dark",
  "previewIsolation": {
    "rewriteSelector": ".dark",
    "replaceWith": ".g7le-preview-dark",
    "flattenLayers": true
  }
}
```

코어 CSS 서빙 API(`/api/admin/templates/{id}/editor/components.css`)가 편집기 진입
시에만 이 선언대로 CSS 의 다크 조상 셀렉터(`rewriteSelector`)를 프리뷰 전용 마커
(`replaceWith`)로 치환해 서빙한다. 일반 사용자 페이지 CSS 는 원본 그대로다
(사용자 페이지 무영향). `strategy: "none"` 또는 미선언이면 편집기 다크 탭이 비노출된다.

`flattenLayers` 는 CSS cascade-layer(`@layer`)를 쓰는 라이브러리(Tailwind v4 등)
전용 옵트인이다. 편집기 CSS 가 어드민 호스트 CSS 와 같은 `@layer` 이름을 공유하면
cross-build 레이어 우선순위 충돌로 프리뷰 다크 규칙이 적용되지 않을 수 있는데,
`true` 로 두면 서빙 시 편집기 CSS 의 `@layer` 래퍼를 평탄화(unlayered)해 프리뷰
규칙이 호스트 레이어드 규칙을 이기게 한다. `@layer` 비사용 라이브러리(Bootstrap 등)는
미선언/`false`. `rewriteSelector` 도 라이브러리에 맞게 선언한다(예 Bootstrap
`[data-bs-theme=dark]`) — 코어는 선언값을 그대로 치환할 뿐 특정 라이브러리를 가정하지 않는다.

---

## 관련 문서

- [컴포넌트 개발 규칙](./components.md)
- [레이아웃 JSON 스키마](./layout-json.md)
- [데이터 바인딩](./data-binding.md)
- [다크 모드 지원](./dark-mode.md)
- [본인인증 모달 UI 표준 (engine-v1.46.0+)](./identity-verification-ui.md)
- [본인인증 인터셉터 API 레퍼런스](./identity-guard-interceptor.md)
- [외부 템플릿 IDV launcher 등록](../extension/template-idv-bootstrap.md)
- [프론트엔드 가이드 인덱스](./index.md)
