# 확장 CSS 도구

확장(모듈·플러그인) 화면이 템플릿의 CSS 라이브러리에 기대지 않고 **자기 CSS 로 스타일을 싣도록** 옮길 때 쓰는 도구 모음이다.

| 도구 | 하는 일 |
| --- | --- |
| `style-fingerprint.js` | 브라우저에서 실행하는 화면 스타일 지문 함수(계산값·상자 크기를 DOM 경로별로 뜬다) |
| `capture-style-fingerprints.mjs` | 화면 목록을 라이트·다크 × 너비 조합으로 열어 지문을 일괄 채취한다(Playwright) |
| `compare-style-fingerprints.mjs` | 전환 전후 지문을 비교한다. 전환은 모양을 바꾸지 않는 작업이므로 기대값은 차이 0 건이다 |
| `convert-layout-classes.mjs` | 확장 레이아웃·조각(·PHP)의 클래스 토큰을 확장 자기 이름으로 바꾸고, 필요한 CSS 를 생성한다 |
| `deprecated-responsive-classes.mjs` | 공개 출시본 빌드 CSS 의 반응형 접두 클래스를 뽑아 템플릿·확장 입력 CSS 의 폐지 예정 보존 블록(`DEPRECATED-RESPONSIVE-START`~`END`)에 쓴다. 확장 출시본 CSS 의 접두 없는 반응형 클래스는 지금 접두 빌드가 만들 수 없어 관리자 템플릿 블록에 더한다. `utilities` 명령은 출시본 빌드 CSS 에 있던 일반 유틸리티 중 레이아웃이 더 쓰지 않아 지금 빌드에서 빠진 이름(관리자 42, 사용자 34)을 기준 픽스처에 더해 같은 블록에 싣는다(7.2.0 한정, 다음 출시 이후 블록과 함께 지운다) |

권장 순서는 다음과 같다.

1. 전환 전 화면 지문을 채취한다(기준선).
2. `convert-layout-classes.mjs --dry-run --report` 로 보고서를 먼저 본다. 미분류·수동 확인 목록을 확정한다.
3. 실제로 변환하고, 보고서의 남은 항목을 손으로 정리한다.
4. 확장을 빌드·반영한 뒤 같은 조합으로 다시 채취해 비교한다. 차이가 0 건이 될 때까지 원인을 고친다.

## 지문 채취·비교

```bash
node scripts/extension-css/capture-style-fingerprints.mjs --base https://example.test \
  --screens screens.json --out fingerprints/before \
  --token admin=admin-token.txt --token user=user-token.txt [--only <확장식별자,…>] [--schemes light,dark] [--widths 1440,768,390]

node scripts/extension-css/compare-style-fingerprints.mjs fingerprints/before fingerprints/after [--json diff.json] [--boxes]
```

- 화면 목록 형식과 인증 토큰 파일 규칙은 `capture-style-fingerprints.mjs` 머리 주석에 있다.
- 클래스명은 전환으로 바뀌므로 지문의 키로 쓰지 않는다(태그와 순번으로 만든 DOM 경로가 키다).
- `style-fingerprint.js` 의 함수 본문은 DevTools 콘솔에 붙여 넣어도 같은 결과를 낸다.

## 클래스 변환

```bash
node scripts/extension-css/convert-layout-classes.mjs <확장경로> [--dry-run] [--report <json>] \
  [--namespace <ns>] [--php <glob>]… [--php-context admin|user]
```

| 옵션 | 뜻 |
| --- | --- |
| `--dry-run` | 아무 파일도 쓰지 않는다. `--report` 보고서와 표준출력 요약만 낸다 |
| `--report <json>` | 파일별·분류별 건수와 목록을 JSON 으로 쓴다 |
| `--namespace <ns>` | manifest 에 `assets.css.namespace` 가 없을 때 쓴다. 둘 다 있고 값이 다르면 오류 |
| `--php <glob>` | 확장 경로 기준 PHP 글롭. `'class' =>`·`'className' =>`·`'*ClassName' =>` 문자열 값도 같은 규칙으로 바꾼다(여러 번 가능) |
| `--php-context admin\|user` | PHP 클래스 문자열이 쓰이는 화면 쪽. 없으면 경로에 `admin` 이 있을 때 관리자, 아니면 양쪽으로 본다 |
| `--admin-template`·`--user-template` | 의미 클래스 원본으로 쓸 템플릿 경로(기본: 번들 `sirsoft-admin_basic`·`sirsoft-basic`) |

요구: Node.js 22 이상, 저장소 루트 의존성 설치(`npm install`). 변환기는 루트의 `@tailwindcss/node`·`@tailwindcss/oxide`(`@tailwindcss/vite` 의존성)와 `postcss` 를 쓰고, typography 플러그인은 확장 → 저장소 루트 → 번들 템플릿 순으로 찾는다.

같은 입력에 다시 돌려도 결과가 같다(멱등). 이미 바뀐 `{namespace}-…` 토큰에서 복사할 의미 클래스를 다시 찾으므로, 생성 CSS 도 그대로 유지된다.

### 앞글자

- `namespace`(ns): manifest `assets.css.namespace`. 형식 `^[a-z][a-z0-9]*(-[a-z0-9]+)*$`. 손으로 쓴 CSS 클래스 앞글자(`pay-kginicis-tone`)다.
- `letters`: ns 에서 영문 소문자만 남긴 것. Tailwind 접두(`paykginicis:flex`)다. Tailwind 접두는 a-z 만 허용한다.

### 대상

- `resources/layouts/**`·`resources/extensions/**` 의 `.g7.html`·`.json`
  - `.g7.html`: `class`·`className`·`*ClassName` 속성, `<class-map>` 의 `base`·`default` 속성과 `<variant>`·`<default>` 본문. 주석 안은 보지 않는다.
  - `.json`: 키 `className`·`*ClassName` 의 문자열 값, `classMap` 의 `base`·`default`·`variants.*`. 텍스트 위치 치환이라 들여쓰기·키 순서가 그대로 남는다.
  - `.g7.html` 과 짝 `.json` 에 같은 매핑이 적용된다.
- `--php` 로 준 PHP 파일

### 토큰 분류

| 분류 | 판정 | 변환 |
| --- | --- | --- |
| 자기 것 | `{ns}-…`·`{letters}:…` | 유지 |
| Tailwind 표지 | `group`·`peer`·`group/x`·`peer/x` | `{letters}:group` 등 |
| Tailwind 유틸리티 | 접두 진입 CSS 로 실제 컴파일해 그 후보의 선택자가 나옴(테마 합집합·typography 포함) | `{letters}:<원 토큰>` — 변형·`!` 중요 표시·음수·임의값 그대로(예: `dark:hover:bg-x` → `{letters}:dark:hover:bg-x`) |
| 템플릿 의미 클래스 | 템플릿 원본 CSS(`src/styles/**/*.css`)에 클래스 선택자로 정의된, Tailwind 유틸리티가 아닌 이름 | `{ns}-{token}`(이름을 나눈 사용자 쪽은 `{ns}-user-{token}`) |
| FontAwesome | `fa`·`fas`·`far`·`fab`·`fa-*` | 바꾸지 않고 보고한다. 아이콘 컴포넌트로 손수 바꾼다 |
| 미분류 | 그 밖(정의 없음) | 바꾸지 않고 보고한다. 의도를 확인해 정의를 만들거나 지운다 |

- 「겹침」: Tailwind 유틸리티이면서 템플릿 원본 CSS 에도 같은 이름이 있는 토큰(예: `table`·`hidden`·`sr-only`). 그 화면 쪽 템플릿의 빌드 산출에 이 토큰을 선택자에 포함한 **복사 가능한** 규칙(템플릿 밖 클래스가 섞이지 않고 `.dark` 위치가 지원 형태인 것)이 하나라도 있으면 두 이름을 함께 준다 — `table` → `{letters}:table {ns}-table` — 그리고 그 정의도 생성 CSS 로 복사한다(`.table th` 같은 하위 규칙이 빠지지 않게). 복사 가능한 규칙이 없으면 Tailwind 형만 준다. 어느 쪽으로 처리했는지와 근거 선택자는 보고서 `overlap` 에 남는다.
- 의미 클래스는 사용처에 따라 원본이 다르다. 관리자 화면(`layouts/admin/**`, 또는 대상 레이아웃·확장 포인트가 관리자 레이아웃에 있는 조각)은 관리자 템플릿 정의, 사용자 화면은 사용자 템플릿 정의를 쓴다. 한쪽 템플릿에만 정의된 이름을 다른 쪽 화면에서 쓰면 미분류로 보고한다.
- 같은 이름을 두 쪽이 다른 정의로 쓰면 이름을 나눈다: 관리자 `{ns}-{token}`, 사용자 `{ns}-user-{token}`.

### 바인딩 표현식 안 리터럴

값의 정적 부분과 `{{ }}` 안 문자열 리터럴(작은따옴표·큰따옴표·템플릿 리터럴의 정적 조각)을 토큰으로 본다. 다음은 클래스가 아니므로 바꾸지 않는다(보고서 `excludedLiterals` 에 건수가 남는다).

| 제외 | 예 |
| --- | --- |
| 비교 피연산자(`===`·`!==`·`==`·`!=`·`<`·`>` 양쪽, 비교되는 괄호 그룹 안 포함) | `row.status === 'done'`, `(x ?? 'standard') === 'standard'` |
| 호출 인자 | `.includes('hidden')`, `.startsWith('a.' + i)` |
| 객체 리터럴 키(`{`·`,` 뒤, `:` 앞) | `{'pending': 'bg-yellow-100'}` 의 `'pending'` |
| 대괄호 속성 접근 | `row.map['block']` |

삼항 결과(`? 'a' : 'b'`)와 객체 리터럴 값은 클래스로 본다. 다음은 바꾸지 않고 「수동 확인」으로 보고한다.

- 조립형: 다른 값과 공백 없이 이어지는 토큰(`'bg-' + x`, `` `bg-${x}` ``, `fi-{{code}}`). 상대 리터럴이 공백으로 끝나면(`'base ' + …`) 조립형이 아니다.
- 판정 불가: 리터럴에 바로 메서드·속성 접근이 붙은 경우(`'a b'.split(' ')`), 이스케이프가 든 리터럴, 닫히지 않은 바인딩, HTML 엔티티 따옴표, PHP 보간 문자열.

제외했지만 Tailwind·의미 클래스처럼 보이는 리터럴은 보고서 `excludedClassLike` 에 따로 올린다(오판 확인용).

### 생성물

**`<확장>/resources/css/g7-extension.generated.css`** — 의미 클래스 정의를 템플릿 빌드 산출(`<템플릿>/dist/css/components.css`)에서 복사해 `@layer g7-extension { … }` 에 담는다. 템플릿이 같은 이름을 덮을 수 있도록 가장 낮은 칸이다. 복사할 때:

- 선택자의 템플릿 클래스를 확장 이름으로 바꾼다. 선택자에 함께 걸린 다른 의미 클래스도 같은 규칙으로 바꾸고 복사 대상에 넣는다. Tailwind 표지·유틸리티가 끼어 있으면 `{letters}:` 이름으로 바꾸고 보고하며, 템플릿 밖 클래스(라이브러리 등)가 끼어 있으면 그 선택자는 버리고 보고한다.
- `@media`·`@supports` 감싸기를 보존한다.
- 층 위치를 보존한다. 템플릿에서 어느 `@layer` 에도 속하지 않던 규칙(예: 표 칸·반응형 배치처럼 유틸리티를 이겨야 해서 층 밖에 둔 규칙)은 `@layer g7-extension` **밖**, 파일 끝에 둔다. 진입 블록에서 생성 파일이 유틸리티 뒤에 실리므로 같은 특이도의 확장 유틸리티를 이긴다(템플릿에서와 같은 결과). 위치 판단은 빌드 산출의 층 위치를 따르며, 원본 CSS 의 층 밖 규칙과 빌드 산출의 층 밖 규칙이 같다는 것을 테스트로 확인한다.
- 레이아웃의 아이콘 컴포넌트(`.g7.html` 의 `<icon>`, `.json` 의 `"name": "Icon"`)에 줄 높이를 정하는 유틸리티(`{letters}:text-xs`…`text-9xl`, `text-*/n`, `leading-*`)가 붙으면 파일 끝 층 밖에 `i:is(.{letters}\:text-…) { line-height: 1; }` 를 낸다. 템플릿이 아이콘을 아이콘 글꼴 `<i>` 로 그릴 때 그 글꼴 CSS(층 밖 `line-height: 1`)가 템플릿 층 안 유틸리티를 이기던 결과를 보존하기 위해서다. 임의값 글자 크기(`text-[2rem]`)는 줄 높이를 정하지 않아 넣지 않는다.
- 다크 규칙(`.dark .x`)은 `.dark` 를 떼고 주체 복합 선택자(의사 요소 앞)에 중립 표식 규격을 붙인다. 특이도는 템플릿과 같은 (0,2,0) 이다.

  ```css
  .ns-x:is([data-g7-color-scheme="dark"] *, [data-g7-color-scheme="dark"]):not(:where([data-g7-color-scheme="dark"] [data-g7-color-scheme="light"] *, [data-g7-color-scheme="dark"] [data-g7-color-scheme="light"])) { … }
  ```

- 템플릿 테마 변수(`--color-*`·`--spacing`·`--font-*`·`--text-*`·`--radius-*`·`--shadow-*`·`--animate-*` 등)는 템플릿 `:root` 값으로 풀어 쓴다. `--tw-*` 는 그대로 두고, 참조한 `@property --tw-*` 와 초기값 폴백을 함께 싣는다. `@keyframes` 는 `{ns}-{이름}` 으로 바꿔 함께 싣는다.

**`<확장>/resources/css/main.css` 진입 블록** — 파일 맨 앞에 표지 주석으로 감싼 블록을 만들고, 다시 돌리면 블록만 바꾼다. 기존 손 CSS 는 블록 아래에 그대로 둔다. 파일이 없으면 만든다.

```css
/* g7-extension-css:start — … */
@import "tailwindcss/theme.css" layer(theme) prefix(<letters>);
@import "tailwindcss/utilities.css" layer(utilities) prefix(<letters>) source(none);
@import "./g7-extension.generated.css";
@source "../layouts";
@source "../extensions";
@source "../js";
@source not "../js/**/__tests__";
@source "../../src";
@theme { /* 두 번들 템플릿 @theme 합집합 */ }
@plugin "@tailwindcss/typography"; /* prose 계열을 쓸 때만 */
@custom-variant dark (&:is([data-g7-color-scheme="dark"] *, [data-g7-color-scheme="dark"]):not(:where([data-g7-color-scheme="dark"] [data-g7-color-scheme="light"] *, [data-g7-color-scheme="dark"] [data-g7-color-scheme="light"])));
/* g7-extension-css:end */
```

- preflight(tailwindcss 패키지의 preflight.css)는 싣지 않는다. 확장 CSS 가 페이지 전체 기본값을 바꾸면 안 되기 때문이다.
- `@import` 는 CSS 규칙대로 다른 at-rule 보다 앞에 둔다.
- `source(none)` 으로 자동 탐색을 끄고 `@source` 로 훑을 곳을 명시한다. `../../src` 는 PHP 가 내보내는 클래스 문자열을 훑기 위함이다. 시험 디렉터리(`../js/**/__tests__`)는 빼낸다 — 시험 문자열의 클래스(금지 폭 접두 `{접두}:md:` 포함)가 빌드 CSS 에 실렸다(#869).
- 산출에는 접두 클래스(`.<letters>\:flex`)·접두 테마 변수(`--<letters>-color-*`)·`--tw-*` 초기값(`@layer properties` 의 `*, ::before, ::after, ::backdrop` 블록)만 나온다.

### 보고서

| 키 | 내용 |
| --- | --- |
| `summary` | 파일 수·바뀔 파일 수·클래스 값 수, 분류별 토큰 건수(`own`·`marker`·`tailwind`·`semantic`·`fontawesome`·`unclassified`·`manual`), 제외 리터럴 종류별 건수 |
| `files` | 파일별 사용처(`admin`·`user`·`both`)와 판정 근거, 분류별 건수, 변경 여부 |
| `unclassified`·`fontawesome` | 토큰별 건수와 파일 목록 |
| `manual` | 수동 확인 항목(파일·줄·종류·원문) |
| `overlap` | 겹침 토큰별 처리(`decision`: `both`·`tailwind-only`·`mixed`, 쪽별 건수 `decisions`, 부여한 이름 `names`)와 템플릿별 근거(`evidence`: 선택자 수·복사 가능 수·표본·복사 불가 표본) |
| `semantic`·`split` | 바꾼 의미 클래스와 새 이름, 이름을 나눈 토큰 |
| `copiedClasses`·`generatedRules`·`unlayeredRules` | 생성 CSS 에 복사한 의미 클래스(폐포 포함), 규칙 수, 그중 층 밖에 둔 규칙 수 |
| `iconLineHeight` | 아이콘 줄 높이 보존 규칙에 넣은 토큰 |
| `selectorIssues`·`missingDefinitions`·`unresolvedVars` | 복사 중 선택자 문제, 빌드 산출에 없는 정의, 풀지 못한 변수 |
| `excludedClassLike` | 제외했지만 클래스처럼 보이는 리터럴 |

## 확장 빌드 배선

변환한 확장은 Tailwind 를 **확장 자신의 작성 도구**로 써서 접두 CSS 를 빌드한다. 시스템은 Tailwind 를 전제하지 않는다 — 순수 CSS·Sass 로 같은 앞글자 규칙만 지키면 된다.

manifest(`module.json`·`plugin.json`):

```json
{
    "assets": {
        "js": { "entry": "resources/js/index.ts", "output": "dist/js/module.iife.js" },
        "css": { "entry": "resources/css/main.css", "output": "dist/css/module.css", "namespace": "board" }
    }
}
```

`package.json` devDependencies — 저장소 루트와 같은 버전을 쓴다. 진입 블록에 `@plugin "@tailwindcss/typography"` 가 있으면 typography 도 넣는다.

```json
{
    "devDependencies": {
        "tailwindcss": "4.1.16",
        "@tailwindcss/vite": "4.1.16",
        "@tailwindcss/typography": "^0.5.20",
        "vite": "^7.0.4"
    }
}
```

`vite.config.ts` — 플러그인을 더하고, CSS 산출 경로를 manifest `assets.css.output` 과 맞춘다.

```ts
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import path from 'path';

export default defineConfig({
    plugins: [tailwindcss()],
    build: {
        lib: { entry: path.resolve(__dirname, 'resources/js/index.ts'), name: 'SirsoftBoard', fileName: 'module', formats: ['iife'] },
        outDir: 'dist',
        emptyOutDir: false,
        rollupOptions: {
            output: {
                entryFileNames: 'js/module.iife.js',
                // assets.css.output(dist/css/module.css)과 같은 경로
                assetFileNames: (info) => (info.name?.endsWith('.css') ? 'css/module.css' : 'assets/[name][extname]'),
            },
        },
    },
});
```

JS 진입점에서 CSS 를 불러온다.

```ts
// resources/js/index.ts
import '../css/main.css';
```

## 테스트

루트 `vitest.config.ts` 는 브라우저 코드용(jsdom)이라 `scripts/**` 를 포함하지 않는다. 이 도구는 전용 설정으로 돌린다.

```bash
npx vitest run --config scripts/extension-css/vitest.config.mjs scripts/extension-css
```

- `scripts/extension-css/__tests__/class-tokens.test.mjs`: 표현식 리터럴 판정(비교 피연산자·객체 키·인자·대괄호 접근 제외, 조립형), 형식별 스캐너(JSON 의 소속 컴포넌트 이름 포함)
- `scripts/extension-css/__tests__/convert-layout-classes.test.mjs`: 합성 확장·합성 템플릿 픽스처(`__tests__/fixtures/`)로 분류별 변환, 이름 나누기, JSON 형식 보존, 멱등, 의미 클래스 복사(선택자 개명·다크 규격·변수 풀어쓰기), 겹침 토큰 두 이름, 층 밖 규칙 위치, 아이콘 줄 높이, 진입 블록 갱신, PHP, 번들 템플릿의 원본·산출 층 위치 일치
- `scripts/extension-css/__tests__/build-output.test.mjs`: 변환한 픽스처를 실제 Tailwind 로 빌드해 preflight·접두 없는 `.flex`·접두 없는 `--color-*`·`.dark` 가 없는지, 층 밖 규칙이 확장 유틸리티 뒤에 오는지 확인
