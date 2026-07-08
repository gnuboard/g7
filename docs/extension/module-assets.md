# 모듈 프론트엔드 에셋 시스템

> 이 문서는 G7의 모듈 프론트엔드 에셋 로딩 시스템을 다룹니다.

---

## TL;DR (5초 요약)

```text
1. module.json에 에셋 매니페스트 정의 (js, css, loading strategy)
2. Vite IIFE 빌드로 dist/에 번들 생성
3. TemplateApp 초기화 시 자동 로드 (global 전략)
4. 핸들러 네이밍: {module-identifier}.{handler-name}
5. 빌드 명령: php artisan module:build [identifier] (기본: _bundled, --active로 활성)
```

---

## 목차

- [개요](#개요)
- [에셋 매니페스트 스키마](#에셋-매니페스트-스키마)
- [모듈 프론트엔드 구조](#모듈-프론트엔드-구조)
- [핸들러 등록](#핸들러-등록)
- [빌드 및 배포](#빌드-및-배포)
- [에셋 로딩 전략](#에셋-로딩-전략)
- [외부 라이브러리](#외부-라이브러리)
- [관련 문서](#관련-문서)

---

## 개요

모듈/플러그인은 자체 프론트엔드 에셋(JS, CSS, 이미지, 폰트 등)을 포함할 수 있습니다.
이 시스템은 활성화된 모듈의 에셋을 동적으로 로드하여 ActionDispatcher에 핸들러를 등록합니다.

### 작동 원리

```
1. admin.blade.php 렌더링
   └─ window.G7Config.moduleAssets 주입

2. TemplateApp.init()
   └─ ModuleAssetLoader.loadActiveExtensionAssets()
       ├─ CSS 로드 (병렬)
       └─ JS 로드 (병렬 fetch + 순차 실행)
           ├─ priority 오름차순 정렬 후 DOM append
           ├─ script.async = false → 삽입 순서대로 실행 보장 (HTML 사양)
           └─ 각 모듈의 initModule() 실행
               └─ ActionDispatcher.registerHandler()

3. 레이아웃 렌더링
   └─ 모듈 핸들러 사용 가능
```

> **성능 참고**: JS 번들은 `Promise.all` 로 병렬 fetch 되며, `script.async = false` 와
> priority 정렬된 DOM append 순서로 **실행 순서는 유지**됩니다. N 개의 확장 IIFE 로딩이
> N × (fetch 시간) 에서 max(fetch 시간) 으로 단축됩니다. 단, 확장 간 런타임 의존성
> (다른 확장의 window 전역/핸들러 참조) 이 발생하면 priority 필드로 실행 순서를 명시해야 합니다.

---

## 에셋 매니페스트 스키마

### module.json (통합 스키마)

모듈 루트에 `module.json` 파일을 생성합니다. 메타데이터와 에셋 설정이 하나의 파일에 통합되어 있습니다.

```json
{
    "identifier": "sirsoft-ecommerce",
    "vendor": "sirsoft",
    "name": {
        "ko": "이커머스",
        "en": "Ecommerce"
    },
    "version": "1.0.0",
    "description": {
        "ko": "상품 및 주문 관리를 위한 이커머스 모듈",
        "en": "E-commerce module for product and order management"
    },
    "g7_version": ">=1.0.0",
    "dependencies": {
        "modules": {},
        "plugins": {}
    },
    "github_url": null,
    "github_changelog_url": null,
    "assets": {
        "js": {
            "entry": "resources/js/index.ts",
            "output": "dist/js/module.iife.js"
        },
        "css": {
            "entry": "resources/css/main.css",
            "output": "dist/css/module.css"
        },
        "handlers": true,
        "static": "resources/assets/"
    },
    "loading": {
        "strategy": "global",
        "priority": 100
    }
}
```

> **참고**: `identifier`, `vendor`는 디렉토리명에서 자동 추론되므로 생략 가능합니다.
> `name`, `version`, `description`은 AbstractModule에서 자동 파싱됩니다.

### 스키마 설명

#### 메타데이터 필드

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `identifier` | `string` | 선택 | 모듈 식별자 (디렉토리명에서 자동 추론) |
| `vendor` | `string` | 선택 | 벤더명 (identifier에서 자동 추론) |
| `name` | `string\|object` | 필수 | 모듈명 (다국어: `{"ko": "...", "en": "..."}`) |
| `version` | `string` | 필수 | 시맨틱 버전 (예: `1.0.0`) |
| `description` | `string\|object` | 필수 | 모듈 설명 (다국어 지원) |
| `g7_version` | `string` | 선택 | 그누보드7 코어 버전 제약 (예: `>=1.0.0`) |
| `dependencies` | `object` | 선택 | 모듈/플러그인 의존성 |
| `github_url` | `string\|null` | 선택 | GitHub 저장소 URL (업데이트 감지용) |
| `github_changelog_url` | `string\|null` | 선택 | GitHub 변경 이력 URL |

#### 에셋 필드

| 필드 | 설명 |
|------|------|
| `assets.js.entry` | JS 소스 엔트리 포인트 |
| `assets.js.output` | 빌드된 JS 출력 경로 |
| `assets.css.entry` | CSS 소스 엔트리 포인트 |
| `assets.css.output` | 빌드된 CSS 출력 경로 |
| `assets.handlers` | 핸들러 포함 여부 |
| `assets.static` | 정적 에셋 소스 디렉토리 |
| `loading.strategy` | 로딩 전략 (global, layout, lazy) |
| `loading.priority` | 로드 우선순위 (낮을수록 먼저) |

---

## 모듈 프론트엔드 구조

### 디렉토리 구조

```
modules/_bundled/sirsoft-ecommerce/
├── module.json              ← 에셋 매니페스트
├── package.json             ← npm 패키지 정의
├── vite.config.ts           ← Vite 빌드 설정
├── tsconfig.json            ← TypeScript 설정
├── dist/                    ← 빌드 출력 (git ignore)
│   ├── js/module.iife.js
│   ├── css/module.css
│   └── assets/
│       ├── fonts/
│       └── images/
├── resources/
│   ├── js/                  ← JS 소스
│   │   ├── index.ts
│   │   ├── types.ts
│   │   └── handlers/
│   │       ├── index.ts
│   │       └── updateProductField.ts
│   ├── css/main.css         ← CSS 소스
│   └── assets/              ← 정적 에셋 소스
│       ├── fonts/
│       └── images/
└── ...
```

### package.json

```json
{
    "name": "sirsoft-ecommerce",
    "version": "1.0.0",
    "private": true,
    "type": "module",
    "scripts": {
        "dev": "vite build --watch",
        "build": "vite build"
    },
    "devDependencies": {
        "typescript": "^5.0.0",
        "vite": "^6.0.0"
    }
}
```

### vite.config.ts

```typescript
import { defineConfig } from 'vite';
import path from 'path';

export default defineConfig({
    build: {
        lib: {
            entry: path.resolve(__dirname, 'resources/js/index.ts'),
            name: 'SirsoftEcommerce',
            fileName: 'module',
            formats: ['iife'],
        },
        outDir: 'dist',
        rollupOptions: {
            output: {
                entryFileNames: 'js/[name].iife.js',
                assetFileNames: (assetInfo) => {
                    if (assetInfo.name?.endsWith('.css')) {
                        return 'css/[name][extname]';
                    }
                    return 'assets/[name][extname]';
                },
            },
        },
        emptyOutDir: true,
        minify: true,
        sourcemap: true,
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
});
```

---

## 핸들러 등록

### 모듈 엔트리 파일 (index.ts)

```typescript
import '../css/main.css';
import { handlerMap } from './handlers';

const MODULE_IDENTIFIER = 'sirsoft-ecommerce';

export function initModule(): void {
    const registerHandlers = () => {
        const actionDispatcher = (window as any).G7Core?.getActionDispatcher?.();

        if (actionDispatcher) {
            Object.entries(handlerMap).forEach(([name, handler]) => {
                const fullName = `${MODULE_IDENTIFIER}.${name}`;
                actionDispatcher.registerHandler(fullName, handler);
            });
            console.log(`[Module:${MODULE_IDENTIFIER}] Handlers registered`);
        } else {
            // ActionDispatcher 초기화 대기
            setTimeout(registerHandlers, 100);
        }
    };

    if (document.readyState === 'complete') {
        registerHandlers();
    } else {
        window.addEventListener('load', registerHandlers);
    }
}

// IIFE 빌드 시 즉시 실행
initModule();
```

### 핸들러 정의

```typescript
// handlers/updateProductField.ts
import type { ActionContext } from '@/types';

interface UpdateProductFieldParams {
    productId: string | number;
    field: string;
    value: string | number | boolean;
    stateKey?: string;
}

export function updateProductFieldHandler(
    params: UpdateProductFieldParams,
    context: ActionContext
): void {
    const { productId, field, value, stateKey = 'products' } = params;

    // setState를 통해 상태 업데이트
    if (context.setState) {
        context.setState({
            [stateKey]: {
                _modified: {
                    [productId]: { [field]: value }
                }
            }
        });
    }
}

// handlers/index.ts
import { updateProductFieldHandler } from './updateProductField';
import { updateOptionFieldHandler } from './updateOptionField';
import type { ActionHandler } from '@/types';

export const handlerMap: Record<string, ActionHandler> = {
    updateProductField: updateProductFieldHandler,
    updateOptionField: updateOptionFieldHandler,
};
```

### 레이아웃에서 핸들러 사용

```json
{
    "component": "Input",
    "props": {
        "type": "number",
        "value": "{{row.stock_quantity}}"
    },
    "events": {
        "onBlur": {
            "handler": "sirsoft-ecommerce.updateProductField",
            "params": {
                "productId": "{{row.id}}",
                "field": "stock_quantity",
                "value": "{{$event.target.value}}"
            }
        }
    }
}
```

---

## 빌드 및 배포

### Artisan 커맨드

```bash
# _bundled에서 빌드 (기본값)
php artisan module:build sirsoft-ecommerce

# 모든 _bundled 모듈 빌드
php artisan module:build --all

# 프로덕션 빌드 (_bundled)
php artisan module:build sirsoft-ecommerce --production

# 파일 감시 모드 (활성 디렉토리에서 자동 실행)
php artisan module:build sirsoft-ecommerce --watch

# 활성 디렉토리에서 빌드
php artisan module:build sirsoft-ecommerce --active

# 빌드 후 활성 디렉토리 반영
php artisan module:update sirsoft-ecommerce
```

### npm 스크립트

```bash
# 모듈 디렉토리에서 직접 실행
cd modules/_bundled/sirsoft-ecommerce
npm install
npm run build

# 개발 모드 (파일 감시)
npm run dev
```

### 에셋 서빙 API

빌드된 에셋은 다음 API를 통해 서빙됩니다:

```
GET /api/modules/assets/{identifier}/{path}

예시:
/api/modules/assets/sirsoft-ecommerce/dist/js/module.iife.js
/api/modules/assets/sirsoft-ecommerce/dist/css/module.css
```

---

## 서버측 번들 병합 (Server-side Bundle)

활성 모듈/플러그인이 늘어날수록 개별 IIFE JS/CSS 요청이 선형 증가한다. 이를 줄이기 위해 코어는 타입별(모듈/플러그인)로 활성 `global` 에셋을 서버에서 하나의 번들로 병합해 서빙한다. 각 확장 IIFE 는 자체 클로저에서 자가등록(레지스트리 + 핸들러/리스너)을 수행하므로, priority 순으로 이어붙여 단일 `<script>` 로 실행해도 등록 동작은 동일하다.

### 서빙 엔드포인트

```
GET /api/modules/bundle.js?v={version}
GET /api/modules/bundle.css?v={version}
GET /api/plugins/bundle.js?v={version}
GET /api/plugins/bundle.css?v={version}
```

`{version}` = 확장 캐시 버전(`ClearsTemplateCaches::getExtensionCacheVersion()`). 활성 조합이 바뀌면 install/activate/deactivate/update 라이프사이클에서 version 이 bump 되어 새 URL → 새 캐시 파일명으로 자동 무효화된다.

### 동작 흐름

```
1. blade → window.G7Config.bundleUrls 주입 (활성 에셋 없는 타입은 null)
2. TemplateApp.loadExtensionAssets()
   └─ ModuleAssetLoader.loadBundle('module', ...) → loadBundle('plugin', ...)
       ├─ 단일 <script async=false> + 단일 <link> append
       └─ 번들 내부 물리 순서(=priority 정렬)로 IIFE 자가등록 실행
```

`bundleUrls` 가 없으면(구버전 blade) `ModuleAssetLoader.loadActiveExtensionAssets` 개별 로딩으로 폴백한다.

### 병합 규율

| 규율 | 내용 | audit 룰 |
|------|------|---------|
| priority 순서 | manifest `loading.priority` 오름차순만. 확장 이름 하드코딩 금지 | (선언형) |
| `\n;\n` 구분자 | IIFE 사이는 `\n;\n`(JS)/`\n`(CSS). 미사용 시 ASI 붕괴 → 전체 파싱 에러 | `extension-bundle-concat-separator` |
| 소스맵 | prod strip, dev 는 개별 에셋 서빙 절대 URL 로 rewrite | - |
| same-origin | 번들 URL 은 `/api/...` 만 (CDN 금지 — gdpr preblocker 자기차단 방지) | `extension-bundle-url-same-origin` |
| 절대경로 게터 | `getBuiltAssetAbsolutePaths()` 사용. `base_path("modules"\|"plugins")` 직접 조립 금지 | `extension-bundle-asset-path-getter` |
| 확장별 try/catch | 파일 읽기 실패 시 해당 확장만 skip, 나머지 병합 지속 | (메모리+회귀테스트) |
| CSS url() | 상대경로 url() 을 가진 CSS 는 번들 제외(개별 폴백) | - |

### 캐시 stale 관리

번들 파일(`storage/app/ext-bundles/{type}.{version}.{js,css}`)은 Laravel 캐시 스토어 밖 파일시스템이라 version bump/`cache:clear` 가 구파일을 지우지 않는다. 정리 경로:

```bash
php artisan ext-bundles:cleanup           # 현재 version 외 구파일 삭제
php artisan module:cache-clear            # 모듈 번들 파일 정리 포함
php artisan plugin:cache-clear            # 플러그인 번들 파일 정리 포함
php artisan template:cache-clear          # 전체 번들 파일 정리 포함
```

프로덕션은 version-in-path 디스크 캐시, 비프로덕션(dev/watch)은 캐시 없이 매 요청 concat(rebuild 즉시 반영). `_bundled` 수정 후에는 `{type}:update {id} --force` 로 활성 반영 후 version bump 로 번들이 재생성된다.

> 개별 에셋 서빙 라우트(`/api/{type}/assets/...`, `*.map` 포함)는 소스맵·static 참조를 위해 존치한다.

### 전송 압축 (gzip)

번들 JS/CSS 는 `fileResponse()`(= `response()->file()` → `BinaryFileResponse`)로 서빙되며, `GzipEncodeResponse` 미들웨어가 gzip 압축을 적용한다. `BinaryFileResponse` 는 `getContent()` 가 `false` 를 반환하므로, 미들웨어는 파일 경로(`getFile()->getPathname()`)에서 본문을 읽어 압축한 뒤 헤더(Content-Type/ETag/Cache-Control)를 승계한 일반 `Response` 로 치환한다.

- 1KB 미만 번들(예: 빈 CSS)은 `MIN_COMPRESS_SIZE` 가드로 압축 생략.
- `Accept-Encoding: gzip` 미포함 요청, 이미 `Content-Encoding` 이 있는 응답, 304 응답은 압축 대상에서 제외.
- 회귀 테스트: `tests/Feature/Middleware/GzipEncodeResponseTest.php` (BinaryFileResponse 압축/헤더 승계/소용량 skip).

> `BinaryFileResponse` 를 압축 대상에 포함하지 않으면 번들이 비압축 전송되는 사각지대가 생긴다(모듈/플러그인 번들은 크기가 커 압축 이득이 특히 크다).

---

## 에셋 로딩 전략

### global (기본값)

앱 초기화 시 자동으로 로드됩니다.

```json
{
    "loading": {
        "strategy": "global",
        "priority": 100
    }
}
```

- TemplateApp.init()에서 자동 로드
- 모든 페이지에서 핸들러 사용 가능
- 우선순위(priority)가 낮을수록 먼저 로드

### layout (향후 지원)

특정 레이아웃에서만 로드됩니다.

```json
{
    "loading": {
        "strategy": "layout"
    }
}
```

- 레이아웃의 scripts 섹션에서 명시적 로드
- 해당 레이아웃 진입 시 로드

### lazy (향후 지원)

필요할 때 동적으로 로드됩니다.

```json
{
    "loading": {
        "strategy": "lazy"
    }
}
```

- 핸들러 호출 시점에 로드
- 초기 로딩 시간 최적화

---

## 외부 라이브러리

외부 CDN 스크립트를 조건부로 로드할 수 있습니다.

### module.json 설정

```json
{
    "assets": {
        "external": [
            {
                "src": "https://cdn.example.com/chart.js",
                "id": "chartjs-cdn",
                "if": "{{_global.settings.useCharts}}"
            }
        ]
    }
}
```

### 레이아웃 scripts 섹션

```json
{
    "scripts": [
        {
            "src": "https://cdn.example.com/lib.js",
            "id": "external-lib",
            "if": "{{_global.modules['sirsoft-ecommerce'].useExternalLib}}",
            "async": true
        }
    ]
}
```

---

## AbstractModule 에셋 메서드

AbstractModule은 에셋 관련 헬퍼 메서드를 제공합니다:

| 메서드 | 설명 |
|--------|------|
| `getAssets()` | module.json의 assets 섹션 반환 |
| `getAssetLoadingConfig()` | loading 설정 반환 (strategy, priority) |
| `hasAssets()` | 에셋 정의 존재 여부 |
| `getBuiltAssetPaths()` | 빌드된 에셋 경로 반환 (js, css) |

---

## 관련 문서

- [module-basics.md](./module-basics.md) - 모듈 개발 기초
- [hooks.md](./hooks.md) - 훅 시스템 (핸들러와 연계)
- [module-commands.md](./module-commands.md) - 모듈 Artisan 커맨드
- [../frontend/components.md](../frontend/components.md) - 컴포넌트 핸들러 호출
