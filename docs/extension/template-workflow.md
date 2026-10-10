# 템플릿 개발 워크플로우

> **위치**: `docs/extension/template-workflow.md`
> **관련 문서**: [template-basics.md](./template-basics.md) | [template-commands.md](./template-commands.md) | [template-development.md](../frontend/template-development.md)

---

## TL;DR (5초 요약)

```text
1. 필수 파일: template.json, routes.json, _base.g7.html, errors/{404,403,500}.g7.html
2. 빌드 설정: package.json, vite.config.ts (IIFE 빌드, terser 대신 esbuild)
3. 검증 순서: JSON 파싱 → layout_name 필수 → error_config → 에러 레이아웃 파일 존재
4. 컴포넌트: 템플릿에 실제 존재하는 것만 import (없는 컴포넌트 import 시 빌드 실패)
5. 설치: php artisan template:build → php artisan template:install → php artisan template:activate
```

---

## 목차

1. [개요](#개요)
2. [템플릿 생성 체크리스트](#템플릿-생성-체크리스트)
3. [Phase 1: 필수 메타데이터 파일](#phase-1-필수-메타데이터-파일)
4. [Phase 2: 레이아웃 파일](#phase-2-레이아웃-파일)
5. [Phase 3: 빌드 설정](#phase-3-빌드-설정)
6. [Phase 4: 컴포넌트 설정](#phase-4-컴포넌트-설정)
7. [Phase 5: 다국어 파일](#phase-5-다국어-파일)
8. [Phase 6: 빌드 및 설치](#phase-6-빌드-및-설치)
9. [검증 로직 이해](#검증-로직-이해)
10. [자주 발생하는 오류와 해결](#자주-발생하는-오류와-해결)
11. [템플릿 개발 Artisan 명령어](#템플릿-개발-artisan-명령어)

---

## 개요

이 문서는 그누보드7 템플릿을 처음부터 만들 때 필요한 **실전 워크플로우**를 정리합니다.
실제 `sirsoft-user_sample` 템플릿 개발 과정에서 겪은 시행착오를 바탕으로 작성되었습니다.

---

## 확장 컴포넌트 오버라이드

확장이 제공한 컴포넌트를 이 템플릿의 서식으로 갈아끼우려면 `src/components/overrides/{확장식별자}/{이름}.tsx` + `components.json` 의 `overrides[]` 선언을 함께 둔다. 레이아웃 오버라이드(`layouts/overrides/`)와 같은 규약이며, 비호환 버전이면 원본으로 폴백한다.

규약 전문: **[extension-components.md](extension-components.md)**

## 템플릿 생성 체크리스트

새 템플릿 생성 시 아래 체크리스트를 순서대로 완료합니다:

```
Phase 1: 필수 메타데이터
□ template.json 생성 (error_config 포함)
□ template.json externals 점검 (icon 컴포넌트 사용 시 Font Awesome 항목 필수)
□ routes.json 생성 (layout_name 필수)

Phase 2: 레이아웃 파일
□ layouts/_user_base.g7.html 또는 layouts/_admin_base.g7.html 생성
□ layouts/home.g7.html (또는 dashboard.g7.html) 생성
□ layouts/errors/404.g7.html 생성
□ layouts/errors/403.g7.html 생성
□ layouts/errors/500.g7.html 생성

Phase 3: 빌드 설정
□ package.json 생성 (build 스크립트 포함)
□ vite.config.ts 생성 (IIFE 빌드, esbuild minify)
□ tsconfig.json 생성
□ tsconfig.node.json 생성
□ postcss.config.js 생성 (Tailwind v4 — @tailwindcss/postcss)

Phase 4: 컴포넌트 설정
□ src/index.ts 생성 (실제 존재하는 컴포넌트만 import)
□ src/handlers/index.ts 생성
□ src/styles/main.css 생성

Phase 5: 다국어 파일
□ lang/ko.json 생성 (errors 섹션 포함)
□ lang/en.json 생성 (errors 섹션 포함)

Phase 6: 빌드 및 설치
□ php artisan template:build [identifier]
□ php artisan template:install [identifier]
□ php artisan template:activate [identifier]
```

---

## Phase 1: 필수 메타데이터 파일

> `g7_version` / `dependencies` 등 버전 제약 작성 규칙은 [changelog-rules.md](changelog-rules.md#8-코어-버전-제약-정책) 참조.

### 1.1 template.json

```json
{
  "identifier": "vendor-template_name",
  "vendor": "vendor",
  "name": {
    "ko": "템플릿 한국어 이름",
    "en": "Template English Name"
  },
  "version": "1.0.0",
  "description": {
    "ko": "템플릿 설명 (한국어)",
    "en": "Template description (English)"
  },
  "type": "user",
  "locales": ["ko", "en"],
  "author": {
    "name": "vendor",
    "email": "contact@vendor.com"
  },
  "g7_version": ">=1.0.0",
  "dependencies": {
    "modules": {},
    "plugins": {}
  },
  "assets": {
    "css": ["assets/css/main.css"],
    "js": []
  },
  "components": {
    "basic": ["button", "div", "span", "h1", "h2", "p", "icon"],
    "composite": ["header", "footer", "card"],
    "layout": ["container", "flex", "grid"]
  },
  "error_config": {
    "layouts": {
      "404": "404",
      "403": "403",
      "500": "500"
    }
  },
  "externals": [
    {
      "id": "fontawesome",
      "type": "style",
      "asset": "vendor/font-awesome/6.4.0/css/all.inlined.css"
    }
  ]
}
```

**필수 항목**:
- `identifier`: 고유 식별자 (vendor-name 형식)
- `type`: "admin" 또는 "user"
- `locales`: 지원 언어 배열
- `error_config.layouts`: 404, 403, 500 매핑

**`externals` (선택, 단 icon 사용 시 사실상 필수)**:

- 최초 HTML 문서에 정적으로 필요한 외부 스타일·웹폰트·스크립트를 선언한다.
- `components.basic` 에 `icon` 을 등록하면 Icon 컴포넌트가 생성하는
  `<i class="fas fa-...">` 를 렌더링할 Font Awesome CSS 가 반드시 필요하므로
  위 `fontawesome` 항목을 함께 둔다. 누락 시 활성 템플릿에서 아이콘이 표시되지
  않는다. icon 을 쓰지 않으면 `"externals": []` 로 두거나 필드를 생략한다.
- 속성/타입 상세: [template-basics.md "외부 리소스 (externals)"](template-basics.md)

### 1.1.1 hidden 필드 (선택)

`template.json` 에 `"hidden": true` 를 설정하면 관리자 UI 의 템플릿 목록에서 기본 제외됩니다. 학습용 샘플 템플릿, 내부 운영 전용 템플릿을 일반 사용자에게 감출 때 사용합니다.

- 제외 대상: 관리자 UI (`GET /api/admin/templates` 기본 응답)
- 제외 대상 아님: artisan CLI (`template:list`, `template:install`, `template:activate` 등), 설치/제거/업데이트 감지
- 슈퍼관리자는 "숨김 포함" 토글로 일시 조회 가능 (`?include_hidden=1`)
- artisan CLI 에서도 기본 목록에서는 숨기고, `php artisan template:list --hidden` 으로 숨김 포함 목록을 조회할 수 있습니다
- 사용 사례: 학습용 샘플 템플릿(예: `gnuboard7-hello_admin_template`, `gnuboard7-hello_user_template`), 내부 시연용 템플릿

### 1.2 routes.json

```
주의: layout_name 필드가 없으면 설치 실패!
```

<!-- pair:skip routes.json 은 라우트 선언 파일이라 .g7.html 작성 대상이 아니다 -->
```json
{
  "version": "1.0.0",
  "layout_name": "routes",
  "meta": {
    "title": "Routes",
    "description": "Route definitions for template"
  },
  "data_sources": [],
  "components": [],
  "routes": [
    {
      "path": "/",
      "layout": "home",
      "auth": false
    }
  ]
}
```

**필수 필드**:
- `layout_name`: DB 저장용 레이아웃 이름 (필수!)
- `routes`: 라우트 정의 배열

---

## Phase 2: 레이아웃 파일

### 2.1 디렉토리 구조

```
templates/_bundled/vendor-template/
└── layouts/
    ├── _user_base.g7.html    # 베이스 레이아웃 (user 템플릿)
    ├── _admin_base.g7.html   # 베이스 레이아웃 (admin 템플릿)
    ├── home.g7.html          # 홈 레이아웃
    ├── routes.json           # 라우트 정의 (레이아웃이 아니라 라우트 설정 — `.json` 그대로)
    ├── errors/               # 필수 디렉토리
    │   ├── 404.g7.html       # 필수
    │   ├── 403.g7.html       # 필수
    │   └── 500.g7.html       # 필수
    └── partials/             # partial 파일 (DB 저장 안됨)
```

레이아웃 소스 SSoT 는 `.g7.html` 마크업이다 ([layout-markup.md](../frontend/layout-markup.md)). 설치 시 서버가 각 `.g7.html` 을 메모리로 JSON 컴파일해 DB(`template_layouts`)에 등록한다. 아래 예시들의 JSON 은 그 **산출 런타임 계약 키**([layout-json.md](../frontend/layout-json.md))로, `.g7.html` 이 이 키로 컴파일된다.

### 2.2 베이스 레이아웃 (`_user_base.g7.html`)

산출 런타임 계약:

```html
<layout name="_user_base" version="1.0.0">
  <meta :is_base="true"/>
  <slot name="header"/>
  <slot name="content"/>
  <slot name="footer"/>
</layout>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "version": "1.0.0",
  "layout_name": "_user_base",
  "meta": {
    "is_base": true
  },
  "data_sources": [],
  "slots": {
    "header": [],
    "content": [],
    "footer": []
  }
}
```

### 2.3 에러 레이아웃

**`404.g7.html` 예시** (아래는 산출 런타임 계약):
```html
<layout name="404" version="1.0.0" extends="_user_base">
  <meta title="$t:errors.404.title" :is_error_layout="true" :error_code="404"/>
  <slot name="content">
    <div data-ref="error_container" class="flex flex-col items-center justify-center min-h-[60vh] text-center p-8">
      <h1 data-ref="error_code" class="text-6xl font-bold text-gray-900 dark:text-white mb-4">404</h1>
      <h2 data-ref="error_title" class="text-2xl font-semibold text-gray-700 dark:text-gray-300 mb-2">$t:errors.404.title</h2>
      <p data-ref="error_message" class="text-gray-500 dark:text-gray-400 mb-8">$t:errors.404.message</p>
      <button data-ref="back_button" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
        <on type="click">
          <navigate path="/"/>
        </on>
        $t:errors.back_home
      </button>
    </div>
  </slot>
</layout>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "version": "1.0.0",
  "layout_name": "404",
  "extends": "_user_base",
  "meta": {
    "title": "$t:errors.404.title",
    "is_error_layout": true,
    "error_code": 404
  },
  "data_sources": [],
  "slots": {
    "content": [
      {
        "id": "error_container",
        "type": "basic",
        "name": "Div",
        "props": {
          "className": "flex flex-col items-center justify-center min-h-[60vh] text-center p-8"
        },
        "children": [
          {
            "id": "error_code",
            "type": "basic",
            "name": "H1",
            "props": {
              "className": "text-6xl font-bold text-gray-900 dark:text-white mb-4"
            },
            "text": "404"
          },
          {
            "id": "error_title",
            "type": "basic",
            "name": "H2",
            "props": {
              "className": "text-2xl font-semibold text-gray-700 dark:text-gray-300 mb-2"
            },
            "text": "$t:errors.404.title"
          },
          {
            "id": "error_message",
            "type": "basic",
            "name": "P",
            "props": {
              "className": "text-gray-500 dark:text-gray-400 mb-8"
            },
            "text": "$t:errors.404.message"
          },
          {
            "id": "back_button",
            "type": "basic",
            "name": "Button",
            "props": {
              "className": "px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg"
            },
            "text": "$t:errors.back_home",
            "actions": [
              {
                "type": "click",
                "handler": "navigate",
                "params": { "path": "/" }
              }
            ]
          }
        ]
      }
    ]
  }
}
```

**`403.g7.html`, `500.g7.html`**도 동일한 구조로 생성 (error_code와 아이콘만 다름)

---

## Phase 3: 빌드 설정

### 3.1 package.json

```json
{
  "name": "vendor-template",
  "version": "1.0.0",
  "type": "module",
  "scripts": {
    "build": "vite build",
    "dev": "vite build --watch"
  },
  "devDependencies": {
    "@types/react": "^19.0.0",
    "@types/react-dom": "^19.0.0",
    "@tailwindcss/vite": "^4.0.0",
    "@vitejs/plugin-react": "^5.1.0",
    "tailwindcss": "^4.0.0",
    "typescript": "^5.0.0",
    "vite": "^7.0.4"
  }
}
```

> **`react`/`react-dom` 을 dependencies 에 넣지 않는다** — 번들 템플릿 2종 모두 선언하지 않는다(실측).
> 런타임 React 는 코어가 `window.G7Core.__runtime` 으로 제공하고 vite 가 external→window 로 매핑하므로,
> 템플릿이 자기 사본을 번들하면 인스턴스가 둘이 되어 "Invalid hook call"·컨텍스트 미매칭이 난다.
> 타입만 `@types/react`·`@types/react-dom` 로 받는다.

### 3.2 vite.config.ts

```
주의: minify는 'esbuild' 사용 (terser는 별도 설치 필요)
필수: process.env.NODE_ENV 정의 (외부 라이브러리 호환성)
```

```typescript
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [react()],
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  build: {
    lib: {
      entry: path.resolve(__dirname, 'src/index.ts'),
      name: 'VendorTemplate',
      formats: ['iife'],
      fileName: () => 'components.iife.js',
    },
    outDir: 'dist',
    emptyDirBeforeWrite: true,
    minify: 'esbuild',  // terser 대신 esbuild 사용!
    rollupOptions: {
      external: ['react', 'react-dom'],
      output: {
        globals: {
          react: 'React',
          'react-dom': 'ReactDOM',
        },
      },
    },
  },
});
```

### 3.3 tsconfig.json

```json
{
  "compilerOptions": {
    "target": "ES2020",
    "useDefineForClassFields": true,
    "lib": ["ES2020", "DOM", "DOM.Iterable"],
    "module": "ESNext",
    "skipLibCheck": true,
    "moduleResolution": "bundler",
    "allowImportingTsExtensions": true,
    "resolveJsonModule": true,
    "isolatedModules": true,
    "noEmit": true,
    "jsx": "react-jsx",
    "strict": true,
    "noUnusedLocals": true,
    "noUnusedParameters": true,
    "noFallthroughCasesInSwitch": true
  },
  "include": ["src"]
}
```

### 3.4 Tailwind 스캔 설정 (v4 — `tailwind.config.js` 없음)

Tailwind v4 는 JS config 파일을 쓰지 않는다(`tailwind.config.js` 실재 0건). 스캔 대상은 CSS 의
`@source` 로 선언하고, 실제 파일은 [4.3 src/styles/main.css](#43-srcstylesmaincss) 에서 만든다.

플러그인 배선은 번들 템플릿 2종이 서로 다르며 **둘 다 유효**하다 — 신규 템플릿은 ⓐ 를 쓴다.

| 배선 | 사용 템플릿 | 구성 |
| --- | --- | --- |
| ⓐ Vite 플러그인 (권장) | `sirsoft-basic` | `@tailwindcss/vite` 를 `vite.config.ts` `plugins` 에 등록. `postcss.config.js` 불요 |
| ⓑ PostCSS 플러그인 | `sirsoft-admin_basic` | `@tailwindcss/postcss` + [3.5 postcss.config.js](#35-postcssconfigjs). `vite.config.ts` 에는 tailwind 플러그인을 넣지 않는다 |

스캔 소스 선언 방식도 둘로 갈린다 — `sirsoft-admin_basic` 은 레이아웃 글롭을 직접 앵커하고,
`sirsoft-basic` 은 사전 생성한 `safelist.txt` 를 앵커한다(`@source "./safelist.txt"`).
**글롭을 쓰는 경우 아래 규정이 적용된다.**

```text
필수: 레이아웃 스캔은 `.g7.html` 과 `.json` 을 **둘 다** 앵커한다.
`.g7.html` 이 소스 SSoT 이자 설치 우선 소스므로, `.json` 만 스캔하면
소스에만 있는 클래스가 조용히 purge 된다 — 빌드도 단위 테스트도 통과하는데
화면만 무너지는 형태라 jsdom 으로 검출되지 않는다.
```

### 3.5 postcss.config.js

> 배선 ⓑ(`@tailwindcss/postcss`) 를 택한 경우에만 만든다. ⓐ(`@tailwindcss/vite`) 는 이 파일이 없다.

```javascript
export default {
  plugins: {
    '@tailwindcss/postcss': {},
  },
};
```

---

## Phase 4: 컴포넌트 설정

### 4.1 src/index.ts

```
필수: 실제 존재하는 컴포넌트만 import!
존재하지 않는 컴포넌트를 import하면 빌드 실패
```

```typescript
// 실제 존재하는 컴포넌트만 import
import * as basicComponents from './components/basic';
import * as compositeComponents from './components/composite';
import * as layoutComponents from './components/layout';
import { handlerMap } from './handlers';

// 전역 등록
declare global {
  interface Window {
    G7Core: {
      registerComponents: (components: Record<string, unknown>) => void;
      registerHandlers: (handlers: Record<string, unknown>) => void;
    };
  }
}

// 컴포넌트 등록
const allComponents = {
  ...basicComponents,
  ...compositeComponents,
  ...layoutComponents,
};

if (window.G7Core) {
  window.G7Core.registerComponents(allComponents);
  window.G7Core.registerHandlers(handlerMap);
}

export { allComponents, handlerMap };
```

### 4.2 src/handlers/index.ts

```typescript
export const handlerMap = {
  // 커스텀 핸들러 등록
} as const;

export type HandlerName = keyof typeof handlerMap;
```

### 4.3 src/styles/main.css

```css
@import "tailwindcss";

@variant dark (.dark &);

/**
 * 레이아웃 스캔 (Tailwind v4). 경로는 이 CSS 파일 기준 상대 경로다.
 * 두 표기를 모두 앵커한다 — `.g7.html` 이 소스 SSoT 이자 설치 우선 소스므로(D38)
 * `.json` 만 스캔하면 소스에만 있는 클래스가 조용히 purge 된다.
 */
@source "../../layouts/**/*.g7.html";
@source "../../layouts/**/*.json";
```

### 4.4 레이아웃 편집기 확장점 등록 (선택)

템플릿이 자기 컴포넌트의 커스텀 속성 위젯(예 아이콘 피커)·노드 에디터·캔버스 인플레이스 오버레이를 레이아웃 편집기에 추가하려면 `G7Core.layoutEditor` 확장점에 등록한다. 등록은 `src/index.ts` **최상위(module-load)에서 호출**한다 — `initTemplate`/`window.load` 게이트 안에 두면 편집기 직접 진입 시 위젯이 누락되는 회귀가 발생한다(코어 stub 예약 접수함이 큐로 보존하므로 module-load 시점 등록이 안전).

```typescript
import { registerMyTemplateEditorWidgets } from './layout-editor/registerEditorWidgets';

// index.ts 최상위 — initTemplate/window.load 밖
registerMyTemplateEditorWidgets();
```

```typescript
// layout-editor/registerEditorWidgets.ts
export function registerMyTemplateEditorWidgets(): void {
  if (typeof window === 'undefined') return;
  const le = (window as any).G7Core?.layoutEditor;
  if (!le?.registerWidget) return; // stub 부재 → 큐가 보존
  le.registerWidget('icon-picker', IconPickerWidget);
  le.registerCanvasOverlay?.('tabnav', TabNavInplaceOverlay);
}
```

확장점 API·등록 시점·작성 규약 상세: [editor-spec.md "편집기 확장점"](editor-spec.md). identity launcher 등록(`G7Core.identity.setLauncher`)과 동일한 옵셔널 체이닝 가드 패턴이다.

---

## Phase 5: 다국어 파일

### 5.1 lang/ko.json

```
필수: errors 섹션 필수! (에러 레이아웃에서 참조)
```

```json
{
  "site": {
    "title": "사이트 제목"
  },
  "errors": {
    "404": {
      "title": "페이지를 찾을 수 없습니다",
      "message": "요청하신 페이지가 존재하지 않거나 이동되었습니다."
    },
    "403": {
      "title": "접근이 거부되었습니다",
      "message": "이 페이지에 접근할 권한이 없습니다."
    },
    "500": {
      "title": "서버 오류",
      "message": "서버에 문제가 발생했습니다. 잠시 후 다시 시도해 주세요."
    },
    "back_home": "홈으로 돌아가기"
  }
}
```

### 5.2 lang/en.json

```json
{
  "site": {
    "title": "Site Title"
  },
  "errors": {
    "404": {
      "title": "Page Not Found",
      "message": "The page you requested does not exist or has been moved."
    },
    "403": {
      "title": "Access Denied",
      "message": "You do not have permission to access this page."
    },
    "500": {
      "title": "Server Error",
      "message": "Something went wrong on our end. Please try again later."
    },
    "back_home": "Back to Home"
  }
}
```

---

## Phase 6: 빌드 및 설치

### 6.1 빌드

```bash
php artisan template:build vendor-template
```

### 6.2 설치

```bash
php artisan template:install vendor-template
```

### 6.3 활성화

```bash
php artisan template:activate vendor-template
```

---

## 검증 로직 이해

### 설치 시 자동 검증 순서

1. **JSON 파싱 검증**: 모든 레이아웃 파일의 JSON 유효성
2. **layout_name 필수**: 템플릿 레이아웃은 반드시 `layout_name` 필드 필요
3. **error_config 검증**: `template.json`에 `error_config.layouts` 섹션 필수
4. **에러 레이아웃 검증**: 404, 403, 500 레이아웃 파일 존재 확인
5. **partial 병합 검증**: `partial` 참조 파일 존재 및 순환 참조 확인

### ValidatesLayoutFiles 트레이트

```php
// 스캔 대상 디렉토리
layouts/*.{g7.html,json}          // ✅ 스캔 (DB 저장)
layouts/errors/*.{g7.html,json}   // ✅ 스캔 (DB 저장)
layouts/partials/*.{g7.html,json} // ❌ 스캔 안 함 (extends로만 참조)
// `.g7.html` 소스가 있으면 그것을 설치 시점에 컴파일하고, 짝 마크업이 없는 `.json` 만 직접 읽는다
```

### TemplateManager::validateErrorLayouts()

```php
// 필수 에러 코드
$requiredErrorCodes = [404, 403, 500];

// 검증 항목
1. error_config.layouts 섹션 존재
2. 404, 403, 500 키 존재
3. layouts/errors/{code}.g7.html (또는 짝 없는 `.json`) 파일 존재
```

---

## 자주 발생하는 오류와 해결

### 오류 1: layout_name 필드 누락

```
❌ 오류: layout_name 필드가 누락되었습니다
```

**원인**: routes.json 또는 레이아웃 파일에 `layout_name` 필드 없음

**해결**:
```html
<layout name="routes" version="1.0.0">
  …
</layout>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "layout_name": "routes",  // 추가
  // ... 나머지 내용
}
```

### 오류 2: 에러 레이아웃 파일 없음

```
❌ 오류: 404 에러 레이아웃 파일을 찾을 수 없습니다
```

**원인**: `layouts/errors/404.g7.html` 파일 없음

**해결**:
1. `layouts/errors/` 디렉토리 생성
2. `404.g7.html`, `403.g7.html`, `500.g7.html` 파일 생성

### 오류 3: terser not found

```
❌ 오류: Cannot find module 'terser'
```

**원인**: vite.config.ts에서 `minify: 'terser'` 설정했지만 terser 미설치

**해결**:
```typescript
// vite.config.ts
build: {
  minify: 'esbuild',  // terser 대신 esbuild 사용
}
```

### 오류 4: 컴포넌트 import 실패

```
❌ 오류: Module not found: './components/basic/H5'
```

**원인**: 존재하지 않는 컴포넌트를 import

**해결**: 실제 존재하는 컴포넌트만 import
```typescript
// ❌ 잘못된 예
import { H5, H6, Box } from './components/basic';  // H5, H6, Box가 없으면 에러

// ✅ 올바른 예
import { H1, H2, H3, Div, Span } from './components/basic';  // 실제 존재하는 것만
```

### 오류 5: error_config 누락

```
❌ 오류: 에러 페이지 설정(error_config)이 누락되었습니다
```

**원인**: `template.json`에 `error_config` 섹션 없음

**해결**:
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

---

## 템플릿 개발 Artisan 명령어

| 명령어 | 설명 |
|--------|------|
| `php artisan template:list` | 템플릿 목록 조회 |
| `php artisan template:build [id]` | 템플릿 빌드 |
| `php artisan template:build --all` | 모든 템플릿 빌드 |
| `php artisan template:install [id]` | 템플릿 설치 |
| `php artisan template:activate [id]` | 템플릿 활성화 |
| `php artisan template:deactivate [id]` | 템플릿 비활성화 |
| `php artisan template:uninstall [id]` | 템플릿 삭제 |
| `php artisan template:refresh-layout [id]` | 레이아웃 갱신 (빌드 없이) |
| `php artisan template:cache-clear` | 템플릿 캐시 초기화 |

---

## 관련 문서

- [템플릿 기초](./template-basics.md) - 템플릿 타입, 메타데이터
- [템플릿 커맨드](./template-commands.md) - Artisan 커맨드 상세
- [템플릿 개발 가이드](../frontend/template-development.md) - 컴포넌트 개발
- [레이아웃 JSON 스키마](../frontend/layout-json.md) - 레이아웃 작성법
- [컴포넌트 개발 규칙](../frontend/components.md) - 컴포넌트 작성법
