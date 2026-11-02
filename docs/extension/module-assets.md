# 모듈 프론트엔드 에셋 시스템

> 이 문서는 G7의 모듈 프론트엔드 에셋 로딩 시스템을 다룹니다.

---

## TL;DR (5초 요약)

```text
1. module.json에 에셋 매니페스트 정의 (js, css, loading strategy)
2. Vite IIFE 빌드로 dist/에 번들 생성
3. TemplateApp 초기화 시 자동 로드 (global 전략)
4. 핸들러 네이밍: {module-identifier}.{handler-name} — 남의 접두어·엔진 이름 금지, 등록 시 source 에 자기 식별자 (덮으면 [G7] 경고)
5. 빌드 명령: php artisan module:build [identifier] (기본: _bundled, --active로 활성)
6. 화면 스타일은 확장이 자기 CSS 로 싣는다 — assets.css.namespace 앞글자, 세 칸(@layer g7-extension · 층 없는 규칙 · 템플릿 덮기 파일), 다크는 data-g7-color-scheme 표식만
```

---

## 목차

- [개요](#개요)
- [에셋 매니페스트 스키마](#에셋-매니페스트-스키마)
- [확장 CSS 자기 공급 규약](#확장-css-자기-공급-규약)
- [템플릿 확장 CSS 덮기](#템플릿-확장-css-덮기)
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

## 확장 컴포넌트 등록 (self-register)

확장 번들은 액션 핸들러와 같은 방식으로 자기 화면 컴포넌트를 코어 레지스트리에 직접 등록한다 — 템플릿 컴포넌트처럼 매니페스트 + 전역 변수 경로로 실릴 자리가 확장에는 없기 때문이다.

```ts
const registry = (window as any).G7Core?.getComponentRegistry?.();

registry?.registerExtensionComponent(
    `${MODULE_IDENTIFIER}.ProductCard`,
    ProductCard,
    { name: `${MODULE_IDENTIFIER}.ProductCard`, type: 'composite' }
);
```

**등록 시점은 안전하다.** 코어가 확장 번들 로드를 초기 라우트 처리 전에 기다리므로 이 등록은 첫 렌더보다 먼저 끝난다. 번들 로드가 실패하면 등록이 오지 않고 그 컴포넌트 노드는 화면에서 조용히 사라지며(미발견 컴포넌트는 `null` 렌더), 그 침묵은 `registry.getMissingExtensionComponents(names)` 로 관측한다.

React 는 반드시 external 로 둔다 — 번들이 자기 React 사본을 품으면 훅 호출이 다른 인스턴스에 걸려 "Invalid hook call" 로 죽는다.

규약 전문(이름 프리픽스·판본 선택·오버라이드 우선순위): **[extension-components.md](extension-components.md)**

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
            "output": "dist/css/module.css",
            "namespace": "ecommerce"
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
  `pay.kcp.co.kr`(+ SDK 가 이어서 싣는 `npay`·`spay` 계열 — 아래 「콘텐츠 보안 정책」). 이 플러그인들은 **코드에도 같은 호스트 목록을 두고 주입 직전에 확인**하며,
  확인에 실패하면 결제를 진행하지 않습니다(fail-closed). SDK URL 이 확장자로 끝나지 않는
  경우(예: `/v2/standard`)에는 그 런타임 확인이 유일한 게이트입니다. PG사가 호스트를 바꾸면
  manifest 와 코드 상수를 **함께** 갱신해야 하며, 두 목록의 일치는 각 플러그인 테스트가
  고정합니다.
- 이 기능은 코어 7.0.7 에서 도입되었습니다. 선언하는 확장은 `g7_version` 을 `>=7.0.7` 로 두는
  것이 계약상 정확합니다(하위 코어에서는 필드가 무시되어 무해).
- 관련 보안 정책 상세: [frontend/security.md](../frontend/security.md).

##### 콘텐츠 보안 정책(CSP)과의 관계 (코어 7.1.4)

운영자가 환경설정 > 보안에서 CSP 를 켜면 코어는 **켜진 확장의 `trusted_script_hosts` 를 모아**
`script-src` 에 넣습니다([backend/content-security-policy.md](../backend/content-security-policy.md)).
그래서 이 선언은 레이아웃 로더 허용목록이면서 동시에 운영 사이트의 브라우저 정책이 됩니다.

- **SDK 가 이어서 불러오는 호스트까지 선언합니다.** 서비스 SDK 는 자기 호스트 밖의 스크립트를 더
  싣는 경우가 있습니다. 예: KCP `payplus_web.jsp` 는 `npay.kcp.co.kr`(결제 연동)·`spay.kcp.co.kr`
  (간편결제)과 그 테스트 호스트(`testnpay`·`testspay`)에서 스크립트를 싣습니다. 빠지면 강제 모드에서
  결제창이 열리지 않는데, 레이아웃 로더는 SDK 가 스스로 붙이는 스크립트를 보지 않으므로 다른 검사로는
  드러나지 않습니다.
- **확인 방법** — 개발 사이트에서 CSP 를 「점검만」으로 켜고 그 기능을 끝까지 실행(결제창 열기·간편결제
  선택 등)한 뒤 `storage/logs/csp-*.log` 의 `blocked_uri` 를 확인합니다. 찍힌 호스트를 선언하고 사유를
  적습니다. 결제사가 SDK 내부 호스트를 바꾸면 다시 확인합니다.
- **확장이 직접 내는 HTML 에 인라인 실행 코드를 쓰지 않습니다.** 결제 닫기·본인인증 결과·팝업 브리지처럼
  컨트롤러가 HTML 을 돌려주는 화면은 `<script>…</script>` 본문·`onclick=` 속성·`href="javascript:…"` 대신
  서버 값을 `<script type="application/json" id="…">` 데이터 블록으로 싣고, 동작은 확장이 동봉한 정적 JS
  (`assets/bridge/*.js`, 손작성 ES5)를 `<script src>` 로 싣습니다. 빈 iframe 에 `document.write` 로 인라인
  스크립트를 써 넣는 방식도 부모 문서의 정책을 이어받아 막힙니다 — 같은 출처 라우트를 여는 방식으로 바꿉니다.
  번들 확장의 이 규칙은 감사 룰 `no-inline-script-in-html-output` 이 검사합니다.

플러그인(`plugin.json`)·템플릿(`template.json`)도 동일 필드를 지원합니다.

#### 에셋 필드

| 필드 | 설명 |
|------|------|
| `assets.js.entry` | JS 소스 엔트리 포인트 |
| `assets.js.output` | 빌드된 JS 출력 경로 |
| `assets.css.entry` | CSS 소스 엔트리 포인트 |
| `assets.css.output` | 빌드된 CSS 출력 경로 |
| `assets.css.namespace` | 확장 CSS 클래스 앞글자. `assets.css` 를 선언하면 함께 선언한다. 형식 `^[a-z][a-z0-9]*(-[a-z0-9]+)*$` — [확장 CSS 자기 공급 규약](#확장-css-자기-공급-규약) |
| `assets.handlers` | 핸들러 포함 여부 |
| `assets.static` | 정적 에셋 소스 디렉토리 |
| `loading.strategy` | 로딩 전략 (global, layout, lazy) |
| `loading.priority` | 로드 우선순위 (낮을수록 먼저) |

`assets.js` · `assets.css` 는 **객체**이며 `output` 을 가져야 합니다. 목록형(`"js": ["dist/js/module.iife.js"]`)은
어느 소비자도 읽지 않아 **그 자산이 영영 로드되지 않는데, 오류도 경고도 남지 않습니다.**

선언한 `output` 이 가리키는 파일은 디스크에 실재해야 합니다. 내용이 비어 있는 것(0바이트)은
정상입니다 — 스타일 규칙이 아직 없는 확장이 CSS 를 선언하는 것은 어긋남이 아닙니다. 자동 검증이
번들 확장 전수를 대상으로 두 조건(객체 형식 · 산출물 실재)을 확인합니다.

---

## 확장 CSS 자기 공급 규약

확장(모듈·플러그인) 화면의 모양은 **확장이 자기 CSS 로 싣는다**. 레이아웃·조각의 클래스, 확장 컴포넌트(TSX)의 클래스, 핸들러가 실행 중에 붙이는 클래스, PHP 가 내보내는 클래스 문자열이 모두 대상이다(2026-10-22 결정 7·8).

템플릿 CSS 는 템플릿이 **자기 소스만** 훑어 만든다. 확장 화면이 템플릿 CSS 의 클래스에 기대면 그 클래스가 템플릿 산출물에 우연히 있을 때만 그려진다. Tailwind 를 쓰지 않는 템플릿이나 CSS 가 거의 없는 템플릿에서는 확장 화면 모양이 오류 없이 통째로 사라지고, 템플릿이 의미 클래스의 이름·색을 바꾸면 확장 화면도 말없이 따라 바뀐다.

그누보드7 은 **Tailwind 를 전제하지 않는다.** 코어와 정적 검사가 확인하는 것은 「자기 앞글자가 붙은 자기 CSS」 하나다. 그 CSS 를 손으로 쓰든 Tailwind·Sass 로 만들든 확장이 고른다.

| 항목 | 규약 |
| --- | --- |
| 앞글자 | manifest `assets.css.namespace` 로 선언. 손 CSS 는 `{namespace}-…`, 접두 빌드는 `{letters}:…` |
| 우선순위 | 세 칸 — ① `@layer g7-extension`(의미) ② 층 없는 규칙(배치·유틸리티) ③ 템플릿의 확장 CSS 덮기 파일 |
| 다크 모드 | `<html data-g7-color-scheme>` 중립 표식(`dark`·`light`)만 본다. `.dark` 선택자는 쓰지 않는다 |
| 서빙 | 코어가 층을 평탄화한다(`g7-extension`·`properties` 만 남김) |

### 앞글자 선언 — `assets.css.namespace`

```json
"assets": {
    "css": { "entry": "resources/css/main.css", "output": "dist/css/plugin.css", "namespace": "pay-kginicis" }
}
```

- 형식은 `^[a-z][a-z0-9]*(-[a-z0-9]+)*$` 다. 영문 소문자로 시작하고, 소문자·숫자 마디를 하이픈으로 잇는다.
- `letters` 는 namespace 에서 영문 소문자만 남긴 값이다(`pay-kginicis` → `paykginicis`). Tailwind 접두는 a-z 만 받으므로(4.1.16 기준) 접두 빌드는 `letters` 를 쓴다(2026-10-22 결정 11).
- `assets.css` 를 선언한 확장은 namespace 도 선언한다. 이미 쓰던 앞글자가 있으면 그대로 namespace 로 선언한다(2026-10-22 결정 10).

| 작성 방식 | 클래스 표기 | 예 |
| --- | --- | --- |
| 손으로 쓴 CSS(순수 CSS·Sass 등) | `{namespace}-…` | `pay-kginicis-tone-warning` |
| 접두 빌드(Tailwind 등) | `{letters}:…` | `paykginicis:flex`, `paykginicis:dark:bg-gray-800` |

- 사용자 정의 속성과 `@property` 이름도 `--{letters}-*`·`--{namespace}-*` 로 시작한다. Tailwind 가 쓰는 `--tw-*` 는 예외다.
- **겹치면 안 된다.** 다른 확장과 namespace 가 같거나, `letters` 가 같거나, 한쪽이 다른 쪽의 앞마디이면(`acme` 와 `acme-pay` — `acme-` 공간이 `acme-pay-` 를 품는다) 두 확장의 규칙이 서로를 덮는다. 번들 확장끼리는 정적 검사가 막는다. 직접 만드는 확장은 벤더·확장 이름을 넣어 고른다.

### 우선순위 세 칸

화면 `<head>` 와 부팅 뒤에 CSS 가 붙는 순서는 다음과 같다.

```text
템플릿 CSS <link>
<style>@layer g7-extension;</style>          ← 코어가 템플릿 CSS 바로 뒤에 선언
── 부팅 뒤 ──
모듈 번들 CSS → 플러그인 번들 CSS → 템플릿의 확장 CSS 덮기 파일 → 운영자 추가 자산(custom/)
```

CSS 층은 **먼저 선언된 층이 진다.** 그래서 이기는 순서는 다음과 같다.

```text
템플릿의 층 규칙(@layer base·components·utilities 등)
  < ① @layer g7-extension
  < 층 없는 규칙 — 템플릿의 층 없는 CSS · ② 확장 유틸리티 · ③ 덮기 파일 · 운영자 CSS
    (이 안에서는 특이도가 같으면 나중에 실린 것이 이긴다)
```

| 칸 | 어디에 쓰나 | 무엇을 두나 | 결과 |
| --- | --- | --- | --- |
| ① 기본값 칸 | `@layer g7-extension { … }` 안 | 의미 클래스 — 색·테두리·배경·글자 모양. 제목·입력칸·오류 상자처럼 템플릿이 자기 모양으로 바꾸고 싶어 할 만한 것 | 템플릿 층 규칙(예: Tailwind 초기화 `*{margin:0}`·`h1{font-size:inherit}`)보다 위, 층 없는 모든 규칙보다 아래. 템플릿 덮기 파일이 특이도와 무관하게 이긴다 |
| ② 일반 칸 | 층 밖 | 배치·간격·크기·그 화면에만 있는 임의값, 접두 빌드의 유틸리티 | 템플릿 CSS 뒤에 실린다. 앞글자가 달라 템플릿 규칙과 이름이 겹치지 않는다 |
| ③ 덮기 파일 | 템플릿 쪽 파일 | 템플릿이 그 확장 화면을 자기 모양으로 맞추는 규칙 | 모든 확장 CSS 뒤에 실린다 — [템플릿 확장 CSS 덮기](#템플릿-확장-css-덮기) |

- 층 이름은 `g7-extension` 하나만 쓴다. 다른 이름의 층은 서빙할 때 벗겨진다(아래 「서빙 — 층 평탄화」).
- 템플릿 컴포넌트에 넘기는 클래스(예: DataGrid `rowClassName`)에 배경·색을 주면, 그 컴포넌트가 스스로 붙이는 상태 색(마우스 올림·선택)보다 **항상 이긴다** — 확장 클래스는 층 밖, 템플릿 상태 클래스는 층 안이기 때문이다. 상태 색이 보여야 하는 자리면 확장이 상태 색도 함께 넘긴다(예: `board:bg-blue-50 board:hover:bg-gray-50 board:dark:hover:bg-gray-700/30`). 같은 확장 CSS 안에서는 변형(`hover:`)이 기본 클래스 뒤에 생성되므로 상태 색이 이긴다. 선택처럼 CSS 변형으로 나타낼 수 없는 상태는 컴포넌트가 식에 넘기는 값으로 고른다(DataGrid `rowClassName` 의 `$selected`: `($selected ? 'board:bg-blue-50/50' : 'board:bg-blue-50')`).
- 레이아웃 편집기 프리뷰에서도 순서가 같다. 관리자 화면 머리는 관리자 템플릿 CSS 뒤에 `@layer g7-preview, g7-extension;` 을 선언하고, 편집기는 사용자 템플릿 CSS 의 층 이름을 `g7-preview` 하나로 모아 싣는다(층 밖 규칙은 층 밖 그대로). 그래서 프리뷰에서도 「템플릿 층 규칙 < ① 의미 칸 < 층 없는 규칙」이 사용자 화면과 같다.
- 층 선언이 템플릿 CSS **뒤**에 오는 이유: 앞에 두면 템플릿의 초기화 층이 의미 클래스를 이겨 여백·글자 크기·테두리가 사라진다.
- 템플릿의 의미 클래스(`section-heading-md`, `input`, `text-error-strong` 등)에 기대던 확장 화면은 그 정의를 확장 CSS 의 ① 칸으로 옮기고 자기 앞글자를 붙인다(2026-10-22 결정 14). 템플릿은 덮기 파일로 그 모양을 바꿀 수 있다.

### 다크 모드 — 중립 표식

템플릿마다 다크 방식이 다르다(`<html class="dark">`, 시스템 설정, 다크 모드 없음). 확장 CSS 가 그중 하나를 전제하면 다른 템플릿에서 다크가 맞지 않는다. 그래서 템플릿이 `template.json` 의 `features.color_scheme` 으로 다크 방식을 선언하면 코어가 그 방식에 맞춰 `<html data-g7-color-scheme="dark">`(또는 `"light"`)를 달고, **확장 CSS 는 이 표식만 본다**(2026-10-22 결정 9). 선언 형식은 [template-basics.md](template-basics.md#다크-모드-방식-featurescolor_scheme), 표식이 바뀌는 시점은 [dark-mode.md](../frontend/dark-mode.md#확장은-중립-표식을-쓴다) 에 있다.

다크 규칙은 다음 선택자 규격으로 쓴다.

```css
.ns-x:is([data-g7-color-scheme="dark"] *, [data-g7-color-scheme="dark"]):not(:where([data-g7-color-scheme="dark"] [data-g7-color-scheme="light"] *, [data-g7-color-scheme="dark"] [data-g7-color-scheme="light"])) { … }
```

- `:is(…)` — 표식이 dark 인 요소 자신이거나 그 안. 클래스 하나만큼 특이도가 더해져 (0,2,0) 이 된다. 템플릿의 `.dark .x` 와 같은 강도라서 `hover` 같은 변형과 겹쳐도 우선순위가 템플릿 화면과 같다.
- `:not(:where(…))` — 「다크 안의 라이트」만 뺀다. 관리자 화면은 다크인데 레이아웃 편집기 프리뷰를 라이트로 보는 경우다(프리뷰 프레임이 `data-g7-color-scheme="light"` 를 단다). `:where` 는 특이도를 더하지 않는다. 관리자 화면이 라이트이고 프리뷰만 다크이면 프리뷰 프레임의 dark 표식으로 다크가 된다.
- 다크를 선언하지 않은 템플릿에서는 표식이 늘 `light` 라 다크 규칙이 쓰이지 않는다.
- `.dark` 선택자는 쓰지 않는다. 정적 검사가 막는다.

### 서빙 — 층 평탄화

namespace 를 선언한 확장의 CSS 는 코어가 서빙할 때 층을 정리한다.

- `@layer g7-extension { … }` 와 `@layer properties { … }` 블록은 그대로 둔다.
- 그 밖의 층 래퍼(빌드 도구가 만든 `theme`·`utilities` 등)는 벗겨 안쪽 규칙을 층 없는 규칙으로 만든다. 중첩된 층도 같다. `@media`·`@supports` 감싸기는 그대로다.
- `@layer a, b;` 순서 선언에서는 위 두 이름 밖을 지우고, 남는 이름이 없으면 문장을 지운다.
- 주석(`/* … */`)·문자열·백슬래시 이스케이프(`.after\:content-\[\'\'\]`) 안의 `@layer`·중괄호·따옴표는 층으로 읽지 않고 그대로 둔다. 주석에 층 설명을 적어도 된다.

`properties` 층을 남기는 이유: Tailwind 는 `@property` 를 지원하지 않는 브라우저를 위해 `*, ::before, ::after { --tw-*: 초기값 }` 을 이 최하위 층에 둔다. 층을 벗기면 이 전체 선택자 규칙이 템플릿 층의 `--tw-*` 값(예: `.shadow` 의 `--tw-shadow`)을 모든 요소에서 이긴다.

적용 경로는 확장 병합 번들(`/api/{modules|plugins}/bundle.css` 와 그 정적 게시본)과 개별 자산 서빙(`/api/{modules|plugins}/assets/...`) 두 곳이며 처리는 같다. namespace 를 선언하지 않은 확장의 CSS 는 손대지 않고 종전처럼 내보낸다 — 그런 CSS 는 템플릿과 같은 이름의 규칙을 층 안에 둘 수 있어서, 층을 벗기면 템플릿 화면 전체를 이길 수 있다.

### 작성 예시

세 방식 모두 결과는 같다 — 자기 앞글자가 붙은 클래스, ① 칸의 의미 규칙, ② 칸의 배치 규칙, 중립 표식 다크.

**순수 CSS** (namespace `acme-memo`) — 빌드 도구 없이 손으로 쓴다. 번들 학습용 샘플 `modules/_bundled/gnuboard7-hello_module/resources/css/main.css` 가 이 방식이다.

```css
/* resources/css/main.css */

/* ① 기본값 칸 — 의미(색·테두리·배경·글자). 템플릿 덮기 파일이 바꿀 수 있다 */
@layer g7-extension {
    .acme-memo-card {
        background-color: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
    }

    .acme-memo-card:is([data-g7-color-scheme="dark"] *, [data-g7-color-scheme="dark"]):not(:where([data-g7-color-scheme="dark"] [data-g7-color-scheme="light"] *, [data-g7-color-scheme="dark"] [data-g7-color-scheme="light"])) {
        background-color: #1f2937;
        border-color: #374151;
    }

    .acme-memo-card__title {
        font-weight: 600;
        color: #111827;
    }
}

/* ② 일반 칸 — 배치 */
.acme-memo-list {
    display: grid;
    gap: 1rem;
}

@media (min-width: 768px) {
    .acme-memo-list {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
```

```html
<div class="acme-memo-list">
    <div class="acme-memo-card"><h3 class="acme-memo-card__title">{{memo.title}}</h3></div>
</div>
```

**Tailwind 접두 빌드** (namespace `acme-memo`, letters `acmememo`) — Tailwind 를 확장 자신의 작성 도구로 쓴다. 초기화(preflight)는 싣지 않는다. 확장 CSS 가 페이지 전체 기본값을 바꾸면 안 되기 때문이다.

```css
/* resources/css/main.css */
@import "tailwindcss/theme.css" layer(theme) prefix(acmememo);
@import "tailwindcss/utilities.css" layer(utilities) prefix(acmememo) source(none);
@source "../layouts";
@source "../extensions";
@source "../js";
@custom-variant dark (&:is([data-g7-color-scheme="dark"] *, [data-g7-color-scheme="dark"]):not(:where([data-g7-color-scheme="dark"] [data-g7-color-scheme="light"] *, [data-g7-color-scheme="dark"] [data-g7-color-scheme="light"])));

@layer g7-extension {
    .acme-memo-card__title {
        font-weight: 600;
        color: #111827;
    }
}
```

```html
<div class="acmememo:grid acmememo:gap-4 acmememo:md:grid-cols-2">
    <h3 class="acme-memo-card__title acmememo:dark:text-white">{{memo.title}}</h3>
</div>
```

- `@custom-variant dark` 로 `{letters}:dark:` 가 중립 표식을 따르게 한다.
- `theme`·`utilities` 층은 서빙 때 벗겨져 유틸리티가 ② 칸에 실린다.
- 빌드 배선(`@tailwindcss/vite`, `package.json`)과, 기존 레이아웃 클래스를 자동으로 옮기는 변환 도구는 [확장 CSS 도구](../../scripts/extension-css/README.md) 에 있다.

**Sass** (namespace `acme-memo`) — 설정 예다. 확장 `package.json` devDependencies 에 `sass` 를 넣으면 Vite 가 `.scss` 를 처리한다. JS 엔트리는 `import '../css/main.scss';`, manifest `assets.css.entry` 는 `"resources/css/main.scss"` 로 둔다.

```scss
// resources/css/main.scss
$ns: 'acme-memo';

@mixin dark {
    &:is([data-g7-color-scheme="dark"] *, [data-g7-color-scheme="dark"]):not(:where([data-g7-color-scheme="dark"] [data-g7-color-scheme="light"] *, [data-g7-color-scheme="dark"] [data-g7-color-scheme="light"])) {
        @content;
    }
}

// ① 기본값 칸
@layer g7-extension {
    .#{$ns}-card {
        background-color: #ffffff;
        border: 1px solid #e5e7eb;

        @include dark {
            background-color: #1f2937;
            border-color: #374151;
        }
    }
}

// ② 일반 칸
.#{$ns}-list {
    display: grid;
    gap: 1rem;
}
```

Sass 원본(`.scss`)은 CSS 정적 검사가 보지 않는다. 산출물(`dist/css/*.css`)이 검사 대상이다.

### 배선 — 세 곳

manifest 에 `assets.css`(`entry`·`output`·`namespace`)를 선언하고, JS 엔트리가 CSS 를 import 하며, `vite.config.ts` 의 `assetFileNames` 가 CSS 를 `output` 과 같은 경로(예: `{확장}/dist/css/plugin.css`)로 낸다.

```ts
// vite.config.ts — rollupOptions.output
assetFileNames: (info) => (info.name?.endsWith('.css') ? 'css/plugin.css' : 'assets/[name][extname]'),
```

### 확장 화면의 의미 색은 확장 CSS 가 싣는다

경고·성공·위험·안내 글자색처럼 **뜻이 있는 색**도 템플릿의 의미 색 클래스(`text-warning-soft`, `text-success-soft` 등)에 기대지 않는다. 템플릿이 자기 화면에 맞춰 그 클래스의 이름이나 색을 바꾸면 확장 화면의 색이 오류 없이 따라 바뀐다(경고 본문이 초록으로 보이는 식).

- 확장 앞글자를 붙여 ① 칸에 둔다. 번들 결제 플러그인은 `pay-<id>-tone-{warning|success|danger|info}` 와 진한 제목용 `-strong` 짝을 쓴다(예: `pay-kginicis-tone-warning-strong`). 다른 확장의 클래스는 참조하지 않는다.
- 다크 짝을 함께 두고, 위 중립 표식 규격으로 쓴다.

### 정적 검사

그누보드7 번들 확장은 두 규칙으로 소스 단계에서 검사한다. 두 규칙은 manifest 의 namespace 로 판정한다.

| 규칙 | 보는 곳 | 오류로 보는 것 |
| --- | --- | --- |
| `extension-css-namespace` | 확장 CSS 소스(`resources/css/**/*.css`)·산출물(`dist/css/*.css`)·manifest | 선택자의 클래스가 `{namespace}-`·`{letters}:` 로 시작하지 않음 · `.dark` 선택자 · 자기 클래스로 묶이지 않은 요소·전체 선택자(`h1`, `*`, `html`, `:root` 등. `.ns-card h3` 처럼 자기 클래스 아래의 요소, 선언이 모두 `--tw-*`·`--{letters}-*`·`--{namespace}-*` 인 사용자 정의 속성 블록은 허용) · 범위 밖 `@property` 이름 · namespace 형식 위반과 다른 확장과의 겹침 |
| `extension-class-self-contained` | 레이아웃·조각의 `class`·`className`·`*ClassName`·`classMap`(정적 값과 `{{ }}` 안 문자열), 컴포넌트·핸들러의 `className` 과 `classList.add/remove/toggle/replace` 인자, PHP 의 `'class'`·`'className'`·`'*ClassName' =>` 값 | 자기 namespace 형태가 아닌 클래스 · 그 확장 산출 CSS(`dist/css/*.css`)에 선택자로 없는 클래스(Tailwind 표지 `{letters}:group`·`{letters}:peer` 는 실재 확인 제외) |

- 비교 피연산자·함수 인자·객체 키처럼 클래스가 아닌 문자열은 보지 않는다. 문자열을 이어 붙여 만든 이름(`'ns-tone-' + level`)은 이름을 알 수 없어 판정하지 않는다.
- 부득이한 경우 그 줄이나 바로 위 주석에 `audit:allow <규칙 이름> <사유>` 를 남긴다(레이아웃은 노드의 주석).

---

## 템플릿 확장 CSS 덮기

확장 CSS 는 확장이 싣지만 화면 외관의 최종 책임은 템플릿에 있다. 템플릿이 확장 화면을 자기 모양에 맞추려면 확장 CSS 를 고치지 않고 **덮기 파일**을 둔다. 컴포넌트 대체(`components.json` 의 `overrides`)와 같은 관례다 — 대상과 허용 버전을 선언하고, 맞지 않으면 빠져서 확장 원래 모양으로 돌아온다(2026-10-22 결정 15).

`template.json`:

```json
"extension_css_overrides": [
    {
        "target": "gnuboard7-hello_module",
        "targetVersion": "^0.3",
        "path": "dist/css/overrides/gnuboard7-hello_module.css"
    }
]
```

| 필드 | 뜻 |
| --- | --- |
| `target` | 덮을 확장(모듈·플러그인) 식별자 |
| `targetVersion` | 대상 확장의 허용 버전 제약(Semver). 미선언이면 무제한 |
| `path` | 템플릿 빌드 산출물 경로. `dist/` 아래여야 하고 `..` 를 쓸 수 없다 |

- 원본은 템플릿의 `src/styles/overrides/<확장식별자>.css` 에 두고 템플릿 빌드가 `dist/css/overrides/` 로 낸다(관례).
- 덮기 파일은 대상 확장의 클래스(`{namespace}-…`, `{letters}:…`)만 덮는다. 다른 확장이나 요소 전체를 건드리지 않는다.
- 덮기 파일은 층 없이 실리므로 확장의 ① 칸 규칙은 특이도와 무관하게 이기고, ② 칸 규칙은 특이도가 같으면 나중에 실린 덮기 파일이 이긴다.
- 대상 확장이 클래스 이름을 바꾼 새 판을 내면 낡은 덮기 파일은 엉뚱한 곳을 칠하거나 아무것도 덮지 못한다. `targetVersion` 은 그 판에서 덮기 파일을 빼 확장 원래 모양으로 돌려보내는 장치이므로, 확인한 판 범위로 좁게 잡는다.

### 판정

판정은 서버가 한다. 대상 확장의 실제 버전을 아는 것이 서버이기 때문이다.

| 상황 | 결과 |
| --- | --- |
| 대상 확장 활성 + 버전 호환 | 싣는다 |
| 대상 확장 활성 + 버전 비호환 | 싣지 않고 정보 로그를 남긴다 — 화면은 확장 원래 모양 |
| 대상 확장 비활성·미설치 | 조용히 뺀다(나중에 설치될 수 있다) |
| 같은 `target` 을 두 번 선언 | 템플릿 검증 오류 |

- 선언한 `path` 의 파일이 빌드 산출물에 없으면 싣지 않고 정보 로그를 남긴다.
- `extension_css_overrides` 가 배열이 아니거나, 항목에 `target`·`dist/` 아래 `path` 가 없거나, `targetVersion` 을 해석할 수 없어도 템플릿 검증 오류다 — [template-basics.md](template-basics.md#확장-css-덮기-파일-extension_css_overrides).

### 로드 순서와 서빙

```text
모듈 번들 CSS → 플러그인 번들 CSS → 템플릿의 확장 CSS 덮기 파일(선언 순서) → 운영자 추가 자산(custom/)
```

- 서버가 호환 판정을 끝낸 목록을 화면 설정 `G7Config.extensionCssOverrides`(`[{ target, url }]`)로 내려 주고, 엔진이 확장 번들 CSS 뒤·운영자 추가 자산 앞에 붙인다. 번들 대신 확장별 개별 로딩으로 실을 때도 순서가 같다.
- 덮기 파일은 템플릿 자산 API(`/api/templates/assets/...`)로 서빙되고 확장 CSS 와 같은 층 평탄화를 거친다. 템플릿이 선언한 덮기 파일 경로에만 적용되며 템플릿 자기 CSS 는 손대지 않는다. 그래서 덮기 파일 주소는 정적 게시본이 아니라 늘 API 경로다.
- 로드에 실패해도 부팅을 막지 않고 자산 실패 안내로 드러낸다.
- 검색엔진용 화면(SEO 렌더)도 같은 순서로 싣는다 — 템플릿 CSS 뒤에 `@layer g7-extension;` 을 선언하고, 모듈 번들 CSS → 플러그인 번들 CSS → 호환 덮기 파일을 링크한다. 봇 화면은 라이트로 그리므로 `<html data-g7-color-scheme="light">` 다.

번들 학습용 샘플: `templates/_bundled/gnuboard7-hello_user_template/src/styles/overrides/gnuboard7-hello_module.css` 가 `gnuboard7-hello_module` 메모 목록의 제목 색을 바꾼다.

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
                // source 로 등록자(자기 식별자)를 밝힌다 — 아래 "핸들러 이름 규칙과 소유권" 참조
                actionDispatcher.registerHandler(fullName, handler, {
                    category: 'module',          // 플러그인은 'plugin'
                    source: MODULE_IDENTIFIER,
                });
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

### 핸들러 이름 규칙과 소유권

액션 핸들러는 엔진의 한 저장소에 이름으로 담기고, 같은 이름을 다시 등록하면 **마지막 등록이 이깁니다**. 그래서 남의 이름을 쓰면 다른 확장·템플릿·엔진의 동작을 조용히 바꾸게 됩니다. 다음 규칙을 지킵니다.

| 규칙 | 이유 |
| --- | --- |
| 확장(모듈·플러그인)은 `{자기 식별자}.{핸들러명}` 으로 등록한다(예: `sirsoft-ecommerce.updateProductField`) | 점 없는 이름은 템플릿·엔진 이름과 부딪친다 |
| 점 앞 접두어는 **자기 식별자**만 쓴다 | `sirsoft-basic.formatCurrency` 를 플러그인이 등록하면 템플릿 핸들러를 덮는다 |
| 엔진 이름으로 등록하지 않는다 | 아래 표 |
| `registerHandler(name, fn, { category, source })` 의 `source` 에 자기 식별자를 넘긴다 | 경고에 등록자가 정확히 나오고, 같은 주인의 재등록은 경고 대상에서 빠진다 |
| 템플릿은 `handlerMap` 의 키를 그대로 등록한다. 새 핸들러는 `{템플릿 식별자}.{핸들러명}` 을 권장한다 | 템플릿 등록자는 코어가 템플릿 식별자로 표시한다 |

엔진 이름은 두 종류입니다.

| 종류 | 예 | 같은 이름으로 등록하면 |
| --- | --- | --- |
| 엔진이 직접 처리하는 액션 | `navigate`·`apiCall`·`setState`·`toast`·`openModal`·`closeModal`·`sequence`·`login` 등 | **영원히 호출되지 않는다** — 엔진이 등록된 핸들러를 보기 전에 처리한다 |
| 엔진 기본 핸들러 | `refetchDataSource`·`refresh`·`remount`·`emitEvent`·`setLocale`·`suppress` 등 | 엔진 기본 동작이 덮여 이 액션을 쓰는 모든 화면이 바뀐다 |

엔진 액션별 사용법은 [액션 핸들러](../frontend/actions-handlers.md)를 봅니다. 위 표의 이름은 예시이며, 엔진에 새 액션이 늘면 그 이름도 같은 규칙을 받습니다.

**실행 중 경고 (engine-v1.87.0)** — 다음 등록은 디버그 모드와 무관하게 브라우저 콘솔에 `[G7]` 경고를 냅니다. 같은 (이름, 등록자) 쌍은 한 번만 냅니다. 등록 자체는 종전처럼 진행되므로 동작은 바뀌지 않습니다.

- 엔진이 직접 처리하는 이름 등록 — 「엔진이 직접 처리하는 이름이라 등록해도 호출되지 않습니다」
- 엔진 기본 핸들러를 덮음 — 「엔진 기본 핸들러를 {등록자} 가 덮어썼습니다」
- 다른 등록자가 등록한 이름을 다른 함수로 덮음 — 「{기존 등록자} 가 등록한 "{이름}" 을 {등록자} 가 덮어썼습니다」
- 점 앞 접두어가 등록자와 다름 — 「다른 주인의 이름공간({접두어})으로 등록했습니다」
- `unregisterHandler(name, { source })` 로 엔진 기본 핸들러를 지움 — 해제는 그대로 하고 경고만 냅니다

같은 등록자가 같은 이름을 다시 등록하는 경우(언어 전환 뒤 재등록, 확장 활성화 뒤 핸들러 다시 불러오기)와 같은 함수를 다시 등록하는 경우는 경고하지 않습니다. `source` 를 넘기지 않으면 등록자는 `unknown` 으로 기록되고 접두어 판정에서 빠지므로, 경고로 실수를 잡으려면 `source` 를 넘기세요.

**정적 검사** — 그누보드7 번들 확장·템플릿은 위 규칙을 소스 단계에서도 검사합니다. 엔진 이름 등록, 남의 접두어, 확장의 점 없는 이름은 정적 검사가 차단합니다. 부득이한 경우 그 줄이나 바로 위 주석에 `// audit:allow handler-name-ownership reason: …` 로 사유를 남깁니다.

**하지 않는 것** — 템플릿이 확장 핸들러를 대신하는 기능과, 확장이 템플릿마다 다른 핸들러 판본을 내는 기능은 제공하지 않습니다(2026-10-22 결정 1). 화면별 차이는 레이아웃과 컴포넌트에서 다룹니다.

**로드 순서** — 처음 접속할 때와 언어를 바꾼 뒤 모두 모듈 → 플러그인 → 템플릿 순서로 등록됩니다(engine-v1.87.0 부터 언어 전환 뒤 순서도 같음). 규칙을 지키면 이름이 겹치지 않으므로 순서에 기대는 코드를 쓰지 않습니다.

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

```html
<input type="number" value="{{row.stock_quantity}}">
  <on type="blur">
    <action handler="sirsoft-ecommerce.updateProductField" productId="{{row.id}}" field="stock_quantity" value="{{$event.target.value}}"/>
  </on>
</input>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
    "type": "basic",
    "name": "Input",
    "actions": [
        {
            "type": "blur",
            "handler": "sirsoft-ecommerce.updateProductField",
            "params": {
                "productId": "{{row.id}}",
                "field": "stock_quantity",
                "value": "{{$event.target.value}}"
            }
        }
    ],
    "props": {
        "type": "number",
        "value": "{{row.stock_quantity}}"
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

### 아이콘 주소는 별도입니다

확장 아이콘(`assets/icon.svg`)은 위 에셋 주소가 아니라 전용 주소로 서빙됩니다.

```
GET /api/extensions/{type}/{identifier}/icon
```

에셋 주소와 다른 점이 셋 있습니다.

- **로그인이 필요 없습니다.** 아직 설치하지 않은 동봉 확장도 관리자 목록에 그림이 보여야 합니다.
- **설치본만 보지 않습니다.** 설치본 → 대기 → 동봉 순으로 찾으므로 설치 전에도 그림이 나옵니다.
- **정적 게시 대상이 아닙니다.** `?v=` 와 `ETag`(변경 시각·크기 기반, 같으면 304)로 캐시를 다룹니다.

파일이 없거나 규격을 어기면 404 이고, 화면은 종류별 기본 그림으로 표시합니다.
규격과 선언 방법은 [확장 아이콘](extension-icons.md) 에 있습니다.

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
   └─ ModuleAssetLoader.loadExtensionCssOverrides()   ← 템플릿의 확장 CSS 덮기 파일
   └─ ModuleAssetLoader.loadCustomAssets()            ← 운영자 추가 자산(마지막)
```

`bundleUrls` 가 없으면(구버전 blade) `ModuleAssetLoader.loadActiveExtensionAssets` 개별 로딩으로 폴백한다. 폴백에서도 덮기 파일 → 운영자 추가 자산 순서는 같다.

### 병합 규율

| 규율 | 내용 | 자동 검증 |
|------|------|---------|
| priority 순서 | manifest `loading.priority` 오름차순만. 확장 이름 하드코딩 금지 | (선언형) |
| `\n;\n` 구분자 | IIFE 사이는 `\n;\n`(JS)/`\n`(CSS). 미사용 시 ASI 붕괴 → 전체 파싱 에러 | 정적 검사 |
| 소스맵 | prod strip, dev 는 개별 에셋 서빙 절대 URL 로 rewrite | - |
| same-origin | 번들 URL 은 `/api/...` 만 (CDN 금지 — gdpr preblocker 자기차단 방지) | 정적 검사 |
| 절대경로 게터 | `getBuiltAssetAbsolutePaths()` 사용. `base_path("modules"\|"plugins")` 직접 조립 금지. 소실 판정만 선언 축 게터 `getDeclaredAssetAbsolutePaths()` | 정적 검사 |
| 확장별 try/catch | 파일 읽기 실패 시 해당 확장만 skip, 나머지 병합 지속 | (메모리+회귀테스트) |
| CSS url() | 상대 `url()`·`@import` 참조는 그 확장의 절대 자산 URL 로 **치환**해 병합. 병합본의 주소는 어느 확장의 dist 디렉토리도 아니라 상대 해석이 반드시 어긋난다 | (계약 테스트) |
| CSS 층 평탄화 | `assets.css.namespace` 를 선언한 확장의 CSS 만 `g7-extension`·`properties` 층을 남기고 나머지 층 래퍼·순서 선언을 걷어 병합. 개별 자산 서빙과 같은 처리 — [서빙 — 층 평탄화](#서빙--층-평탄화) | (계약 테스트) |
| 디스크 캐시 fail-soft | 캐시 쓰기 실패는 **500 이 아니다** — 메모리 병합 결과를 그대로 200 으로 서빙 | (계약 테스트) |
| 빈 번들 판정 | 선언한 산출물이 소실·판독 불가면 **503**, 존재하되 비었으면 빈 200 (선언 0 도 빈 200) | (계약 테스트) |
| 캐시 우선 | 프로덕션은 `(type, kind, version)` 캐시 파일 존재를 **빌드보다 먼저** 확인한다. 캐시 미스는 같은 키의 잠금으로 1회 빌드에 수렴(잠금 실패는 각자 빌드). 병합 단계에서 건너뛴 확장이 있는 결과는 캐시하지 않는다 | (계약 테스트) |

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
| 선언은 있고 그 산출물이 **전부 존재**하되 비어 있음 | 정상 (스타일이 비어 있는 확장) | 0바이트 캐시 파일 + 정적 게시, 빈 200 |
| 선언한 산출물이 **소실·판독 불가** | 장애 (배포 중 `dist` 가 잠깐 빔, 경로 어긋남) | **503** + `Log::error`(소실 경로 목록) |

장애를 정상으로 흘리면 프론트는 404 도 오류도 받지 못한 채 한참 뒤 "Unknown action handler" 로 죽는다 — 그 시점에는 원인이 번들이라는 사실이 화면에도 로그에도 남아 있지 않다. 반대로 정상을 장애로 잡으면 스타일 소스가 자리표시 주석뿐인 확장만 설치된 기본 구성이 통째로 503 이 되어 **사용자 화면마다 실패 안내가 뜬다.** 판정은 **kind 별**이다(js 만 선언한 확장이 있는 상태에서 css 번들이 비는 것은 정상).

두 축이 다르다는 점이 핵심이다.

- **선언 축**의 근거는 manifest 의 `assets.{kind}.output` 이며 **산출물 파일의 존재를 보지 않는다.** 병합 경로가 쓰는 게터들(`getOrderedGlobalAssetPaths()` · `hasAssets()` · `getBuiltAssetPaths()`)은 모두 `file_exists()` 로 거르므로, 그 경로로 선언 수를 세면 "`dist` 가 잠깐 빔" 이 곧 "선언 0" 이 된다. 이 축은 로그 컨텍스트와 진단이 근거로 삼는다.
- **소실 축**은 선언된 산출물 중 실제로 없거나 읽을 수 없는 것을 센다. 경로는 `getDeclaredAssetAbsolutePaths()` 로 얻는다 — 존재 게이트가 없어야 부재를 셀 수 있다. **503 의 판정은 이 축이 한다.**

0바이트 산출물은 정당한 상태다. 스타일 규칙이 아직 없는 확장이 CSS 를 선언하는 것은 어긋남이 아니며, 그 상태를 배포 장애로 등치하면 정상 사이트가 서비스 불능으로 보고된다.

선언 산출물이 전부 존재하는 빈 번들은 **0바이트 파일로 캐시·정적 게시**되어 브라우저가 웹서버에서 직접 받는다. 게시하지 않으면 `AssetUrl::extensionBundle()` 이 API URL 을 방출해 그 구성의 **모든 페이지 로드**가 PHP 를 거치고, 그 요청마다 컨트롤러가 활성 확장 열거를 세 번 반복한다(경로 조회 → 재빌드 → 소실 판정). 오류도 로그도 남지 않아 드러나지 않는 경로다.

캐시하지 않는 것은 셋뿐이다 — 선언 산출물이 **소실**된 경우(503 판정을 그대로 유지해야 한다), 병합 단계에서 확장을 **건너뛴** 경우(파일은 있는데 읽기·치환이 실패한 상태가 캐시로 굳으면 버전 bump 전까지 그 확장 자산이 사라진 채 고정되므로, 종전처럼 매 요청 재시도에 맡긴다), 그리고 디스크 쓰기 자체가 실패한 경우다.

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

캐시 적중 시에는 원본 에셋을 읽지 않으므로 원본 교체는 반드시 version bump 로 반영한다 — `{type}:update {id} --force` 가 그 bump 를 수행한다. `dist` 를 손으로 덮어쓰고 bump 를 건너뛰면 같은 version 의 캐시가 계속 서빙된다.

> 개별 에셋 서빙 라우트(`/api/{type}/assets/...`, `*.map` 포함)는 소스맵·static 참조를 위해 존치한다.
> 다만 `*.map` 의 **실제 서빙은 `local` 환경에서만** 허용된다 — 소스맵에는 원본 코드 전문이
> 담기므로 운영에서는 확장자 화이트리스트가 차단한다. 상세: [template-security.md](template-security.md) "소스맵 (`map`) — 로컬 개발 환경 전용".

### 요청 제한을 두지 않는 이유

공개 번들 라우트(`/api/{modules,plugins}/bundle.{js,css}`)에는 애플리케이션 수준의 요청 제한을 붙이지 않는다.

- 캐시 우선 순서를 갖춘 뒤로 이 엔드포인트의 응답 비용은 **프레임워크 부팅**이 지배한다. 부팅을 마친 뒤에야 판정할 수 있는 앱 throttle 은 그 비용을 이미 치른 다음이라 방어 효과가 제한적이다.
- 정적 자산 성격의 공개 API 는 요청 제한을 두지 않는 것이 이 저장소의 기존 관행이다(공개 컨트롤러 계층 전반).
- 반복 요청을 실제로 줄이는 것은 앞단 캐시다. 응답이 `Cache-Control: immutable, max-age=31536000, public` 을 내보내므로, 리버스 프록시나 CDN 이 그 헤더를 존중하도록 `/api/*/bundle*` 을 캐시 대상에 넣으면 앱까지 도달하는 요청 자체가 사라진다. 파일명에 캐시 버전이 들어 있어 stale 위험도 없다.

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
씁니다 — `template()` 의 쿼리 형태는 파일 경로를 인코딩해 뒤에 파일명을 이어 붙일 수 없기 때문입니다.
확장자 없는 모드에서 `templateDir()` 은 `?file=` 디렉토리를 돌려주고, 쿼리가 있는 URL 에 `.js` 를 붙이지 않는
AMD 로더를 위해 템플릿 자산 서빙이 같은 이름의 `.js` 파일을 찾아 줍니다. 그래도 자산을 받지 못하는 환경이
있으므로 그 소비자는 폴백을 갖춰야 합니다.

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

외부 호스트(CDN)의 스크립트를 실으려면 그 호스트를 확장 매니페스트 `trusted_script_hosts` 에 선언해야 한다(위 「`trusted_script_hosts`」). 선언 없는 외부 호스트는 컴파일·저장·런타임에서 모두 거부되므로, 가능하면 아래처럼 확장이 동봉한 같은 origin 경로를 쓴다.

```html
<script src="/modules/vendor-sample/vendor/external-lib.js" id="external-lib" :async="true" data-if="{{_global.modules['vendor-sample'].useExternalLib}}"/>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
    "scripts": [
        {
            "src": "/modules/vendor-sample/vendor/external-lib.js",
            "id": "external-lib",
            "if": "{{_global.modules['vendor-sample'].useExternalLib}}",
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

```text
① 템플릿 외부 리소스 → 코어 엔진 → 템플릿 CSS → @layer g7-extension 선언
② 확장 병합 번들 (모듈 → 플러그인)
③ 템플릿의 확장 CSS 덮기 파일 (선언한 템플릿만)
④ 사용자 추가 에셋 (모듈 → 플러그인 → 템플릿)   ← 언제나 마지막
```

CSS 는 나중에 온 규칙이 이긴다. 운영자가 덧붙인 스타일이 확장 스타일과 템플릿 덮기 파일보다 뒤에 와야 재정의가 성립한다. 템플릿이 ④ 의 마지막인 이유는 화면 외관의 최종 책임이 템플릿에 있어서다. ①~③ 의 층 관계는 [우선순위 세 칸](#우선순위-세-칸) 을 본다.

같은 확장 안에서는 선언 순서(없으면 파일명 오름차순), CSS 를 JS 보다 먼저 싣는다.

사용자 추가 에셋은 JS 부팅 이후에 붙으므로 아주 짧은 스타일 적용 지연이 있다. 이는 확장 번들 CSS 가 이미 갖고 있는 성질이며, 순서 정합을 깨는 것보다 낫다.

### 캐시 무효화

파일을 고치면 URL 이 바뀐다 — 캐시를 지우라고 안내할 필요가 없다. 다만 그 방법이 확장 타입에 따라 다르다.

**세 타입 모두** 확장 자산과 **같은 메커니즘**으로 정적 게시된다. 그래서 URL 도 같은 축(확장 캐시 버전)을 쓴다 — 정적 경로는 언제나 현재 게시 버전이므로, 파일 서명을 URL 에 실으면 버전 일치 게이트에 걸려 정적 분기가 영영 선택되지 않는다. 대신 운영자가 파일을 고치면 뷰 컴포저가 그 변화를 감지해 확장 캐시 버전을 올리고, 그 단일 지점이 재게시까지 예약한다.

이 게시가 필요한 이유는 성능이 아니라 **상대 경로**다. API 경로에서는 CSS 내부 `url('./font.woff2')` 가 해석되지 않는다(기준 URL 이 자산 디렉토리가 아니다). 정적 확장자 URL 은 public 아래 실제 파일일 때만 200 이 되므로, 게시본만이 상대 참조를 성립시킨다.

변경 감지 범위는 게시 범위와 같다 — `custom/` 아래 **하위 디렉토리 포함** 허용 확장자 전부(CSS·JS·글꼴·이미지)의 경로·수정 시각·크기를 본다. CSS 를 그대로 두고 참조 대상 글꼴만 교체해도 감지된다. 페이지에 실리는 로드 목록(최상위 CSS·JS)은 별개 규약이며 바뀌지 않았다. 서명은 서버(호스트명)별로 기억하므로 다중 서버가 캐시를 공유해도 서버 간 수정 시각 차이로 재게시가 왕복하지 않는다.

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
| `src/styles/custom.css` + 빌드 | 확장 **작성자**의 방법으로는 유효하다. 운영자에게는 부적합(빌드 필요 + 업데이트 소실) |
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
- [extension-components.md](./extension-components.md) - 확장 컴포넌트 제공·대체와 스타일
- [template-basics.md](./template-basics.md#다크-모드-방식-featurescolor_scheme) - 템플릿의 다크 방식·확장 CSS 덮기 선언
- [../frontend/dark-mode.md](../frontend/dark-mode.md#확장은-중립-표식을-쓴다) - 확장의 다크 중립 표식
- [../../scripts/extension-css/README.md](../../scripts/extension-css/README.md) - 확장 CSS 변환·지문 비교 도구
