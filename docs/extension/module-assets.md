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
| `trusted_script_hosts` | `string[]` | 선택 | 레이아웃이 로드할 수 있는 외부 스크립트 신뢰 호스트 목록 (아래 참조) |

#### `trusted_script_hosts` — 외부 스크립트 신뢰 호스트

**구동에 필요한 자산은 확장이 함께 담아 자체 제공하는 것이 원칙입니다.** 브라우저가 화면을
그리기 위해 제3자 CDN 에 도달해야 하면, 그 도달 실패는 예외도 로그도 남기지 않고 화면 기능만
조용히 사라집니다 — 폐쇄망·방화벽·광고차단기에서 재현되며 자체 서버 로그에 흔적이 없어
운영자가 원인을 특정할 수 없습니다. 동봉 위치는 `dist/vendor/{라이브러리}/{버전}/` 입니다.

이 필드는 **자체 제공이 불가능한 경우**에만 씁니다 — 라이브러리가 아니라 그 회사 서버와
통신하는 서비스 SDK(주소 검색 등)가 그렇습니다. 자체 호스팅해도 동작하지 않으므로 외부
의존이 남습니다.

레이아웃 보안 정책은 `scripts[].src`·`data_sources[].endpoint` 를 기본적으로 same-origin
경로(`/` 로 시작)만 허용하고, 외부 origin·protocol-relative(`//host`)·scheme 포함 URL 은
저장 시점과 렌더 시점 양쪽에서 차단합니다. 같은 판정은 레이아웃 파일뿐 아니라 **브라우저에
새 `<script>` 를 붙이는 모든 경로**(`loadScript` 액션 · 확장 핸들러 재로드 · 편집기 프리뷰 ·
`G7Core.asset.loadScript`)에 적용됩니다. 확장이 정당하게 외부 CDN 스크립트를 써야 하면
그 호스트를 이 배열에 선언합니다. 활성 확장이 선언한 호스트만 집계되며(편집자는 추가 불가 —
manifest 는 배포물), 코어가 활성 확장 전체의 선언을 모아 allowlist 를 구성합니다.

- 값은 호스트명만(스킴/경로 없이). 예: `"t1.daumcdn.net"`.
- **`trusted_script_hosts_reason` 에 호스트별 사유를 함께 선언합니다.** 사유가 없는 선언은
  자체 제공 원칙의 예외로 인정되지 않습니다 — 왜 외부로 나가는지가 코드에 남아야 합니다.

  ```jsonc
  {
    "trusted_script_hosts": ["t1.daumcdn.net"],
    "trusted_script_hosts_reason": {
      "t1.daumcdn.net": "라이브러리가 아니라 Daum 이 운영하는 서비스 SDK 다. 스크립트가 Daum 서버와 통신하므로 자체 호스팅해도 동작하지 않는다."
    }
  }
  ```
- 외부 의존이 남는 기능은 **그 자산을 못 불러왔을 때의 동작**을 함께 갖춰야 합니다. 예: 주소
  검색 SDK 가 없으면 우편번호·주소를 직접 입력할 수 있게 두고 안내를 띄웁니다.
- 결제 플러그인의 PG SDK 도 같은 부류입니다(그 회사 서버와 통신하므로 자체 호스팅 불가).
  선언 사례: KG 이니시스 `stgstdpay.inicis.com`·`stdpay.inicis.com` / 토스페이먼츠
  `js.tosspayments.com` / 나이스페이먼츠 `web.nicepay.co.kr` / NHN KCP `testpay.kcp.co.kr`·
  `pay.kcp.co.kr`. 이 플러그인들은 **코드에도 같은 호스트 목록을 두고 주입 직전에 확인**하며,
  확인에 실패하면 결제를 진행하지 않습니다(fail-closed). SDK URL 이 확장자로 끝나지 않는
  경우(예: `/v2/standard`)에는 그 런타임 확인이 유일한 게이트입니다. PG사가 호스트를 바꾸면
  manifest 와 코드 상수를 **함께** 갱신해야 하며, 두 목록의 일치는 각 플러그인 테스트가
  고정합니다.
- 이 기능은 코어 7.0.7 에서 도입되었습니다. 선언하는 확장은 `g7_version` 을 `>=7.0.7` 로 두는
  것이 계약상 정확합니다(하위 코어에서는 필드가 무시되어 무해).
- 관련 보안 정책 상세: [frontend/security.md](../frontend/security.md).

플러그인(`plugin.json`)·템플릿(`template.json`)도 동일 필드를 지원합니다.

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

`assets.js` · `assets.css` 는 **객체**이며 `output` 을 가져야 합니다. 목록형(`"js": ["dist/js/module.iife.js"]`)은
어느 소비자도 읽지 않아 **그 자산이 영영 로드되지 않는데, 오류도 경고도 남지 않습니다.**

선언한 `output` 이 가리키는 파일은 디스크에 실재해야 합니다. 내용이 비어 있는 것(0바이트)은
정상입니다 — 스타일 규칙이 아직 없는 확장이 CSS 를 선언하는 것은 어긋남이 아닙니다. 정적 검사가
번들 확장 전수를 대상으로 두 조건(객체 형식 · 산출물 실재)을 확인합니다.

---

## 모듈 프론트엔드 구조

### 디렉토리 구조

```
modules/_bundled/sirsoft-ecommerce/
├── module.json              ← 에셋 매니페스트
├── package.json             ← npm 패키지 정의
├── vite.config.ts           ← Vite 빌드 설정
├── tsconfig.json            ← TypeScript 설정
├── dist/                    ← 빌드 출력 (_bundled 은 Git 추적 — 배포 산출물, `*.map` 만 ignore)
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

// 코어 재초기화 시 재등록 진입점 노출 (아래 "코어 재초기화 시 핸들러 재등록" 참조)
(window as any).__SirsoftEcommerce = {
    identifier: MODULE_IDENTIFIER,
    initModule,
};
```

### 코어 재초기화 시 핸들러 재등록

로케일 전환처럼 `TemplateApp` 이 다시 초기화되는 시점에 ActionDispatcher 는 **새 인스턴스로 교체**된다.
이때 앞서 등록해 둔 확장 핸들러는 전부 사라지므로, 코어가 각 확장에 재등록을 요청한다.
요청 방식은 **window 전역 객체에서 약속된 이름의 함수를 찾아 호출**하는 것 하나뿐이다.

| 확장 타입 | 전역 객체 | 재등록 진입점 |
| --- | --- | --- |
| 모듈 | `window.__[ModuleName]` | `initModule()` |
| 플러그인 | `window.__[PluginName]` | `initPlugin()` |
| 템플릿 | `window.G7TemplateHandlers` | 코어가 직접 재등록 (확장 작업 불필요) |

```typescript
// 플러그인 엔트리 파일 (index.ts)
function initPlugin(): void {
    registerHandlersWithRetry();   // 핸들러 재등록만 수행
}

initPlugin();

(window as any).__SirsoftDaumPostcode = {
    identifier: PLUGIN_IDENTIFIER,
    initPlugin,
};
```

이름은 고정이다. 전역 객체를 노출하지 않거나 진입점 이름이 다르면(`init`, `bootstrap`, `setup` 등)
코어는 그 확장을 재등록 대상에서 조용히 건너뛴다. 그 결과는 다음과 같다:

- 사용자가 언어를 한 번 바꾼 뒤부터 해당 확장의 모든 액션이 **무반응**이 된다
- 핸들러가 없으므로 dispatch 는 그대로 무시된다 — 콘솔 에러도, 토스트도, 네트워크 요청도 없다
- 새로고침하면 정상으로 돌아오므로 재현 조건을 모르면 원인 추적이 어렵다

진입점은 **핸들러 재등록만** 수행한다. 최초 진입 1회로 충분한 작업(리다이렉트 복귀 처리,
MutationObserver·인터셉터 설치, DOM 주입 등)은 넣지 않는다 — 재초기화마다 중복 실행된다.

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

> 프로덕션에서는 이 병합 산출물의 사본이 정적 게시본(`public/build/ext/{v}/bundles/`)으로 함께 게시되어 웹서버가 직접 서빙할 수 있다 — [static-asset-publishing.md](../backend/static-asset-publishing.md) 참조. 번들의 생성·정렬·구분자 규율은 계속 본 문서가 소유한다.

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

| 규율 | 내용 | 정적 검사 |
|------|------|---------|
| priority 순서 | manifest `loading.priority` 오름차순만. 확장 이름 하드코딩 금지 | (선언형) |
| `\n;\n` 구분자 | IIFE 사이는 `\n;\n`(JS)/`\n`(CSS). 미사용 시 ASI 붕괴 → 전체 파싱 에러 | `extension-bundle-concat-separator` |
| 소스맵 | prod strip, dev 는 개별 에셋 서빙 절대 URL 로 rewrite | - |
| same-origin | 번들 URL 은 `/api/...` 만 (CDN 금지 — gdpr preblocker 자기차단 방지) | `extension-bundle-url-same-origin` |
| 절대경로 게터 | `getBuiltAssetAbsolutePaths()` 사용. `base_path("modules"\|"plugins")` 직접 조립 금지. 소실 판정만 선언 축 게터 `getDeclaredAssetAbsolutePaths()` | `extension-bundle-asset-path-getter` |
| 확장별 try/catch | 파일 읽기 실패 시 해당 확장만 skip, 나머지 병합 지속 | (메모리+회귀테스트) |
| CSS url() | 상대 `url()`·`@import` 참조는 그 확장의 절대 자산 URL 로 **치환**해 병합. 병합본의 주소는 어느 확장의 dist 디렉토리도 아니라 상대 해석이 반드시 어긋난다 | (계약 테스트) |
| 디스크 캐시 fail-soft | 캐시 쓰기 실패는 **500 이 아니다** — 메모리 병합 결과를 그대로 200 으로 서빙 | (계약 테스트) |
| 빈 번들 판정 | 선언한 산출물이 소실·판독 불가면 **503**, 존재하되 비었으면 빈 200 (선언 0 도 빈 200) | (계약 테스트) |

### 병합 CSS 의 상대 참조

병합본은 `/api/{타입}/bundle.css`(또는 정적 게시본)로 서빙되는데, 그 주소는 **어느 확장의 `dist` 디렉토리도 아니다.** 브라우저는 CSS 안의 상대 `url()` 을 스타일시트 URL 의 디렉토리 기준으로 풀므로, 상대 참조는 URL 모드와 무관하게 반드시 어긋난다. 그래서 병합 시점에 각 확장의 절대 자산 URL 로 치환한다 — 개별 자산 서빙과 **같은 해석 규칙**을 쓴다. 두 경로가 서로 다른 코드로 갈라지면 한쪽만 고쳐진 채 남는다.

치환 대신 그런 CSS 를 가진 확장을 번들에서 제외하지 않는다. 번들 URL 이 내려오면 프론트는 개별 로딩 경로를 아예 타지 않으므로, 제외는 곧 **그 확장의 스타일이 하나도 적용되지 않음**을 뜻한다. 개별 로딩 폴백은 번들 URL 자체가 없을 때만 동작한다.

이 실패는 예외도 서버 로그 흔적도 남기지 않는다 — 정상 404 로 기록되고 화면에서는 글꼴이 기본 서체로 대체되거나 아이콘이 빈칸이 될 뿐이다.

### 디스크 캐시 실패와 빈 번들 (응답 계약)

번들 디스크 캐시는 **최적화**다. `ext-bundles` 디스크는 `throw => true` 라 권한 문제(먼저 touch 한 프로세스가 `0700` 으로 독점하는 경우 등)에서 `UnableToWriteFile` 이 그대로 올라오는데, 그것이 공개 엔드포인트의 500 이 되면 **모든 확장의 프론트엔드 JS/CSS 가 통째로 나가지 못한다.** 병합 결과는 이미 메모리에 있으므로 그것을 그대로 응답한다(코어 캐시 드라이버의 fail-soft 와 같은 원칙). 예방 지점은 디스크 선언이다 — `config/filesystems.php` 의 `ext-bundles` 에 `permissions`(dir `0775` / file `0664`)를 선언해 Flysystem 이 root 를 `0700` 으로 만들지 않게 한다.

빈 번들은 두 가지를 구분해야 한다.

| 상태 | 판정 | 응답 |
|---|---|---|
| 에셋을 선언한 활성 확장이 0개 | 정상 | 빈 200 |
| 선언은 있고 그 산출물이 **전부 존재**하되 비어 있음 | 정상 (스타일이 비어 있는 확장) | 빈 200 |
| 선언한 산출물이 **소실·판독 불가** | 장애 (배포 중 `dist` 가 잠깐 빔, 경로 어긋남) | **503** + `Log::error`(소실 경로 목록) |

장애를 정상으로 흘리면 프론트는 404 도 오류도 받지 못한 채 한참 뒤 "Unknown action handler" 로 죽는다 — 그 시점에는 원인이 번들이라는 사실이 화면에도 로그에도 남아 있지 않다. 반대로 정상을 장애로 잡으면 스타일 소스가 자리표시 주석뿐인 확장만 설치된 기본 구성이 통째로 503 이 되어 **사용자 화면마다 실패 안내가 뜬다.** 판정은 **kind 별**이다(js 만 선언한 확장이 있는 상태에서 css 번들이 비는 것은 정상).

두 축이 다르다는 점이 핵심이다.

- **선언 축**의 근거는 manifest 의 `assets.{kind}.output` 이며 **산출물 파일의 존재를 보지 않는다.** 병합 경로가 쓰는 게터들(`getOrderedGlobalAssetPaths()` · `hasAssets()` · `getBuiltAssetPaths()`)은 모두 `file_exists()` 로 거르므로, 그 경로로 선언 수를 세면 "`dist` 가 잠깐 빔" 이 곧 "선언 0" 이 된다. 이 축은 로그 컨텍스트와 진단이 근거로 삼는다.
- **소실 축**은 선언된 산출물 중 실제로 없거나 읽을 수 없는 것을 센다. 경로는 `getDeclaredAssetAbsolutePaths()` 로 얻는다 — 존재 게이트가 없어야 부재를 셀 수 있다. **503 의 판정은 이 축이 한다.**

0바이트 산출물은 정당한 상태다. 스타일 규칙이 아직 없는 확장이 CSS 를 선언하는 것은 어긋남이 아니며, 그 상태를 배포 장애로 등치하면 정상 사이트가 서비스 불능으로 보고된다.

빈 번들은 정적 게시 대상이 아니다(게시할 사본이 없다). 그 결과 `AssetUrl::extensionBundle()` 이 정적 URL 대신 API URL 을 방출하므로 브라우저에는 정적 404 폴백이 생기지 않고, 그 API 가 위 표대로 빈 200 을 낸다.

두 판정은 모듈·플러그인 컨트롤러가 **공유하는 단일 지점**(`ServesExtensionBundles::bundleResponse()`)에 둔다. 각자 구현하면 한쪽만 고쳐진 채 다른 쪽이 옛 동작으로 남는다.

`clearBundles()`(cache-clear 커맨드)는 **현재 버전을 보존**한다 — `cleanupStaleBundles()` 와 같은 정책이다. 현재 버전까지 지우면 같은 순간 서빙 중인 요청이 "존재함" 판정 직후 `filemtime()` 에서 500 을 낸다. 원자적 쓰기의 임시 파일(`*.tmp.{pid}`)도 GC 대상이되 10분 나이 가드를 받는다(pid 는 재사용되므로 생사로는 진행 여부를 판정할 수 없다).

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
> 다만 `*.map` 의 **실제 서빙은 `local` 환경에서만** 허용된다 — 소스맵에는 원본 코드 전문이
> 담기므로 운영에서는 확장자 화이트리스트가 차단한다. 상세: [template-security.md](template-security.md) "소스맵 (`map`) — 로컬 개발 환경 전용".

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

라이브러리는 확장이 함께 담아 자체 제공합니다(`dist/vendor/{라이브러리}/{버전}/`). 외부 CDN
실시간 로드는 그 CDN 에 도달하지 못하는 환경에서 기능이 조용히 사라지게 만듭니다.

아래 조건부 외부 로드는 **자체 제공이 불가능한 서비스 SDK** 에만 씁니다. 그 경우 manifest 에
`trusted_script_hosts` 와 `trusted_script_hosts_reason` 을 함께 선언해야 하며, 자산을 못
불러왔을 때의 동작도 함께 갖춰야 합니다.

### 동봉 자산의 URL 생성

동봉한 자산을 런타임에 불러올 때는 URL 을 문자열로 조립하지 않고 `G7Core.asset` 을 씁니다.
확장 번들은 코어 모듈을 import 할 수 없으므로 이 전역이 유일한 통로입니다.

```javascript
// 플러그인 — 확장 루트 기준이라 dist/ 를 포함한다
const url = G7Core.asset.plugin('sirsoft-ckeditor5', 'dist/vendor/ckeditor5/43.3.1/ckeditor5.umd.js');
await G7Core.asset.loadScript(url, { id: 'ckeditor5' });

// 모듈
G7Core.asset.module('sirsoft-board', 'dist/vendor/chart.js/4.4.0/chart.umd.js');

// 템플릿 — 서버가 dist/ 를 자동으로 붙이므로 path 에 포함하지 않는다
G7Core.asset.template('sirsoft-admin_basic', 'vendor/flag-icons/7.2.3/css/flag-icons.min.css');
```

자산 URL 은 서버 설정과 정적 게시 상태에 따라 확장자 형태(`.../lib.js`), 쿼리 형태
(`...?file=...`), 정적 게시본 경로(`/build/ext/{버전}/...`) 중 하나로 해석됩니다. 문자열로
조립하면 그 판정을 건너뛰어, 정규식 location 이 확장자를 먼저 가로채는 서버에서 그 자산만
조용히 404 가 됩니다.

AMD 로더나 워커처럼 디렉토리 접두 뒤에 파일명을 이어 붙이는 소비자는 `templateDir()` 을
씁니다 — 쿼리 형태는 뒤에 파일명을 이어 붙일 수 없기 때문입니다. 확장자 없는 모드에서 404
일 수 있으므로 그 소비자는 폴백을 갖춰야 합니다.

로드에 끝내 실패하면 `G7Core.assets.notifyFailure({ id, label, retry })` 로 사용자에게
알립니다. `console.error` 한 줄로 끝내면 사용자에게는 빈 자리로만 나타나고 자체 서버 로그에도
흔적이 남지 않아 운영자가 원인을 특정할 수 없습니다.

전체 시그니처와 `AssetFailure` 필드는
[G7Core 전역 API 레퍼런스](../frontend/g7core-api.md)의 「확장 자산」 절을 참조하세요.

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

## 사용자 추가 에셋 (`custom/`)

운영자가 자기 CSS·JS·정적 파일을 덧붙일 자리를 각 확장이 제공한다.

종전에는 그런 자리가 없었다. CSS 한 줄을 더하려면 확장 소스(`src/styles/`)를 고치고 Node.js 로 빌드해야 했고 — 그렇게 넣은 파일은 다음 확장 업데이트에 **통째로 사라졌다**(확장 교체가 활성 디렉토리를 전부 갈아끼우기 때문이다). 빌드 산출물(`dist/`)을 직접 고치는 것도 다음 빌드에 사라진다.

### 자리

```
templates/{id}/custom/          ← 운영자 소유. 확장 교체가 보존한다
modules/{id}/custom/
plugins/{id}/custom/
├── custom.css                  규약 자동 로드 (파일명 오름차순)
├── 10-override.css
├── custom.js
├── fonts/MyFont.woff2          정적 파일 — CSS 가 상대 경로로 참조
└── assets.json                 (선택) 선언이 있으면 이것이 우선
```

- 빌드하지 않는다. 파일을 놓으면 다음 요청부터 적용된다.
- 확장 업데이트·재설치가 이 디렉토리만은 보존한다.
- 확장을 **삭제**하면 확장 디렉토리와 함께 사라진다. 삭제 전 백업을 안내한다.
- 번들 확장은 `custom/` 을 담아 배포하지 않는다 — 보존 계층이 덮어쓰지 않으므로 그 파일은 기존 설치본에 영영 반영되지 않는다. 확장이 담을 자산은 `dist/vendor/{lib}/{version}/` 에 둔다.

### 선언 파일 (`custom/assets.json`)

순서를 바꾸거나, 일부만 싣거나, 외부 URL 을 등록할 때만 쓴다. 필드는 `template.json` 의 `externals` 와 같은 어휘다.

```json
{
  "assets": [
    { "type": "style",  "file": "10-override.css" },
    { "type": "script", "file": "custom.js" },
    { "type": "style",  "url": "https://fonts.example.com/x.css", "reason": "본문 웹폰트" }
  ]
}
```

- `file` 은 `custom/` 기준 상대 경로다. 상위 디렉토리 이탈은 차단된다.
- `url` 은 운영자가 자기 사이트에 직접 등록하는 외부 자산이다. **`reason`(사유)이 없으면 싣지 않는다** — 왜 외부로 나가는지가 파일에 남아야 한다. 외부 서비스에는 방문자의 IP·UA 가 전달된다.
- 선언 파일이 있으면 규약 스캔은 하지 않는다. 둘을 합치면 "선언에서 뺐는데 왜 아직 로드되나" 가 된다.
- 선언 파일이 깨졌으면(JSON 파싱 실패) **규약 스캔으로 되돌아가지 않고** 그 확장의 custom 을 비운다. 되돌아가면 운영자가 의도적으로 뺀 파일이 되살아난다.

### 로드 순서

```
① 템플릿 외부 리소스 → 코어 엔진 → 템플릿 CSS
② 확장 병합 번들 (모듈 → 플러그인)
③ 사용자 추가 에셋 (모듈 → 플러그인 → 템플릿)   ← 언제나 마지막
```

CSS 는 나중에 온 규칙이 이긴다. 운영자가 덧붙인 스타일이 확장 스타일보다 뒤에 와야 재정의가 성립한다. 템플릿이 ③ 의 마지막인 이유는 화면 외관의 최종 책임이 템플릿에 있어서다.

같은 확장 안에서는 선언 순서(없으면 파일명 오름차순), CSS 를 JS 보다 먼저 싣는다.

사용자 추가 에셋은 JS 부팅 이후에 붙으므로 아주 짧은 스타일 적용 지연이 있다. 이는 확장 번들 CSS 가 이미 갖고 있는 성질이며, 순서 정합을 깨는 것보다 낫다.

### 캐시 무효화

파일을 고치면 URL 이 바뀐다 — 캐시를 지우라고 안내할 필요가 없다. 다만 그 방법이 확장 타입에 따라 다르다.

**세 타입 모두** 확장 자산과 **같은 메커니즘**으로 정적 게시된다. 그래서 URL 도 같은 축(확장 캐시 버전)을 쓴다 — 정적 경로는 언제나 현재 게시 버전이므로, 파일 서명을 URL 에 실으면 버전 일치 게이트에 걸려 정적 분기가 영영 선택되지 않는다. 대신 운영자가 파일을 고치면 뷰 컴포저가 그 변화를 감지해 확장 캐시 버전을 올리고, 그 단일 지점이 재게시까지 예약한다.

이 게시가 필요한 이유는 성능이 아니라 **상대 경로**다. API 경로에서는 CSS 내부 `url('./font.woff2')` 가 해석되지 않는다(기준 URL 이 자산 디렉토리가 아니다). 정적 확장자 URL 은 public 아래 실제 파일일 때만 200 이 되므로, 게시본만이 상대 참조를 성립시킨다.

**모듈·플러그인**도 같다. 다만 게시 대상은 **활성** 확장의 `custom/` 뿐이다 — 자산 서빙이 활성 확장에만 응답하므로 비활성 확장의 파일을 게시해 봐야 아무도 참조하지 않는 사본이 쌓인다. 확장의 빌드 산출물은 개별 파일이 아니라 병합 번들로 게시되므로, `custom/` 이 그 확장에서 유일한 개별 게시 대상이다.

### 확장하기

해석기는 **출처에 의존하지 않는 서술자**를 돌려준다.

```php
['id' => 'custom:templates:sirsoft-basic:10-override.css',
 'type' => 'style',          // style | script
 'url'  => '/api/templates/assets/sirsoft-basic?file=custom%2F10-override.css&v=…',
 'version' => 1787660208,
 'source' => 'file']         // file | url | (확장이 더한 출처)
```

소비자(뷰 컴포저·프론트 로더·서빙·순서 규칙)는 `source` 를 보지 않는다. 다른 출처를 더하려면 해석기 끝의 필터 훅을 쓴다.

```php
HookManager::addFilter('core.assets.custom_assets', function (array $assets, string $type, string $id): array {
    $assets[] = [
        'id' => 'custom:my-source:'.$id,
        'type' => 'style',
        'url' => '/api/my-endpoint.css',
        'version' => $updatedAt,
        'source' => 'my-source',
    ];

    return $assets;
}, 10, 3);
```

훅으로 더한 항목도 자기 `version` 을 실어야 한다 — 캐시 서명이 항목별 버전의 합성이기 때문이다.

### 화면에서 관리하기 (레이아웃 편집기)

FTP 나 서버 셸이 유일한 경로였다면, 그 접근이 없는 운영자에게는 이 기능이 없는 것과
같다. 레이아웃 편집기 상단의 [커스텀 자산] 버튼이 같은 디렉토리를 화면에서 다룬다 —
텍스트(`css`·`js`·`mjs`·`json`)는 본문을 열어 고치고, 폰트·이미지는 올리고 지운다.
모달 상단의 대상 선택기로 **편집 중인 템플릿과 활성 모듈·플러그인**을 오갈 수 있다.

- 저장·업로드·삭제는 확장 캐시 버전을 올려 **정적 게시본까지 갱신**한다. 파일만 바꾸고
  게시본이 그대로면 운영자에게는 "고쳤는데 화면이 그대로" 로만 나타난다.
- 바이너리는 본문 편집기를 열지 않는다. 텍스트로 열어 저장하면 내용이 손상된다.
- 업로드 허용 확장자는 자산 서빙 화이트리스트와 **같은 목록**이다. 관리 쪽만 넓히면
  올릴 수는 있는데 서빙되지 않는 파일이 생기고, 좁히면 서빙 규칙이 사문화된다.

권한은 레이아웃 편집(`core.templates.layouts.edit`)과 **분리**된
`core.extensions.custom_assets.manage` 하나다. 확장 타입별로 쪼개지 않는다 — 쪼개면 운영자가
셋을 다 부여해야 하고, "모듈 CSS 는 되는데 템플릿 CSS 는 안 되는" 상태가 실질적 의미 없이
생긴다. 여기서 올린 스크립트는 그 레이아웃 한 장이
아니라 사이트 전 화면에서 실행되므로, 레이아웃을 고칠 수 있다는 것이 곧 그 권한이 될 수
없다. 기존 사이트에는 코어 업데이트의 표준 권한 동기화로 도달한다(관리자 역할은 자동 부여).

API 레퍼런스: [docs/backend/api/extensions.md](../backend/api/extensions.md) 의
`custom-assets` 엔드포인트 5종. 세 타입이 한 엔드포인트
(`extensions/{type}/{identifier}/custom-assets`)를 공유한다 — 타입별로 나누면 같은 검증·문서·
테스트가 세 벌로 갈리고, 그중 하나만 약해지면 그 경로가 조용한 우회로가 된다.

### 자기 CSS 에 갇히지 않기 (`?custom=off`)

운영자가 넣은 CSS 한 줄이 화면을 조작 불능으로 만들 수 있다. 그런데 그것을 고칠 관리자
화면에도 같은 CSS 가 실려 있어, 고치러 들어갈 수가 없다.

주소에 `?custom=off` 를 붙여 다시 열면 **서버가 목록을 비운다.** 자산이 페이지에
도달하지 않으므로, 이미 깨진 화면에서 자바스크립트가 돌기를 기대하지 않아도 된다.
레이아웃 편집기 툴바에도 같은 동작의 토글이 있고, 꺼진 동안에는 지금 화면이 평소와 다른
상태임을 버튼이 드러낸다.

이 파라미터는 그 요청 한 번에만 작용하며 저장되지 않는다. 화면을 고친 뒤 파라미터 없이
열면 곧바로 원래대로 돌아온다.

### 지원하지 않는 것

| 방법 | 판정 |
|---|---|
| `dist/css/components.css` 직접 수정 | 다음 빌드·업데이트에 소실된다 |
| `src/styles/custom.css` + 빌드 | 확장 **저작자**의 방법으로는 유효하다. 운영자에게는 부적합(빌드 필요 + 업데이트 소실) |
| `custom/custom.css` 에 파일만 놓기 | 권장 |

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
