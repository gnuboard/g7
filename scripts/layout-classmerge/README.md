# classMerge 변환 도구

responsive 구간에서 기본 className 을 거의 다시 적은 경우(감사 P10), 바뀐 토큰만 `class-merge` 로 남기도록 `.g7.html` 원본을 고치는 도구다. 엔진 문법은 `docs/frontend/responsive-layout.md` 의 classMerge 절을 따른다(노드 `responsive.{key}.classMerge`, 마크업 `<responsive on="portable" class-merge="p-6"/>`).

```html
<!-- 전 -->
<div class="w-full max-w-md p-8 bg-white rounded-lg shadow">
  <responsive on="portable" class="w-full max-w-none p-6 bg-white rounded-lg shadow"/>
</div>

<!-- 후 -->
<div class="w-full max-w-md p-8 bg-white rounded-lg shadow">
  <responsive on="portable" class-merge="max-w-none p-6"/>
</div>
```

바꾸는 것은 `<responsive>` 의 `class="V"` 속성 하나뿐이다. 주석, 줄 배치, 따옴표, 다른 속성은 그대로 둔다. 엔진 병합 함수로 같은 결과가 증명된 구간만 바꾼다.

## 사용법

```bash
node scripts/layout-classmerge/convert.mjs <루트> [--dry-run] [--report <json>] [--verify] [--no-sync] [--jobs N] [--min-overlap R]
```

| 옵션 | 뜻 |
| --- | --- |
| `--dry-run` | 아무 파일도 쓰지 않는다. 표준출력 요약과 `--report` 보고서만 낸다 |
| `--report <json>` | 파일별, 구간별 판정을 JSON 으로 쓴다 |
| `--verify` | 변환 후 JSON 으로 구간마다 실효 className 을 다시 계산해 변환 전과 대조한다. 쓰기 실행이면 다시 구운 짝 `.json` 을, `--dry-run` 이나 `--no-sync` 면 변환 후 원문을 메모리에서 실제 컴파일한 결과를 쓴다 |
| `--no-sync` | 바꾼 파일의 짝 `.json` 을 다시 굽지 않는다 |
| `--jobs N` | 굽기 동시 실행 수(기본 4) |
| `--min-overlap R` | 기본 토큰 재기재 비율(`overlap`)이 R(0~1) 미만이면 같은 결과여도 바꾸지 않는다. 기본 0(제한 없음). 감사 P10 기준은 0.8 |

권장 순서는 다음과 같다.

1. `--dry-run --verify --report` 로 보고서를 먼저 본다. verify 차이가 0 이어야 한다.
2. 쓰기 실행: `node scripts/layout-classmerge/convert.mjs <루트> --verify --report <json>`. 바꾼 파일마다 `php artisan layout:decompile <파일> --sync` 로 짝 `.json` 을 다시 굽고, 다시 구운 `.json` 으로 verify 한다.
3. 다시 돌려 바뀌는 파일이 0 인지 확인한다(멱등).

종료 코드는 0 정상, 1 verify 차이 또는 굽기 실패 또는 컴파일 실패, 2 사용법 오류다.

요구: Node.js 22 이상, 저장소 루트 의존성(`npm install`, esbuild 사용), PHP(`PHP_BINARY` 환경변수로 바꿀 수 있다)와 부팅 가능한 Laravel 앱(굽기와 메모리 컴파일에 쓴다).

## 판정

루트 아래 `.g7.html` 마다 짝 `.json`(컴파일 산출)을 읽어, 노드의 `responsive.{key}` 오버라이드 중 `props.className` 이 있는 구간을 모은다. 오버라이드 안 `children` 노드의 구간도 모은다.

구간 하나는 다음을 모두 만족할 때 변환 가능이다.

1. 기본 className 과 구간 className 이 모두 문자열이고 `{{` 가 없다. 기본 className 이 없으면 빈 문자열로 본다.
2. 구간에 아직 `classMerge` 가 없다.
3. diff = 구간 토큰 집합에서 기본 토큰 집합을 뺀 것(구간에 적힌 순서). diff 가 비어 있지 않다.
4. `applyResponsiveClassMerge(기본, diff)` 의 토큰 집합이 구간 토큰 집합과 정확히 같다.
5. 노드나 구간에 classMap 이 있으면, classMap 이 낼 수 있는 값(base + 각 variant, default, default 가 없으면 base 만)마다 「구간 className + classMap 값」과 `applyResponsiveClassMerge(기본 + classMap 값, diff)` 의 토큰 집합이 같다. 엔진은 classMap 결과를 className 에 붙인 뒤 classMerge 를 덧붙이므로, diff 가 classMap 클래스를 지우면 화면이 달라진다.

구간 props 에 className 말고 다른 키가 있으면 className 만 classMerge 로 옮기고 나머지 props 는 남긴다.

병합 함수는 엔진 원본 `resources/js/core/template-engine/helpers/StyleHelpers.ts` 를 실행할 때마다 esbuild 로 번들해 쓴다(복사본이 없다). 확장 앞글자(`ecommerce:` 등)는 `modules/_bundled/*/module.json`, `plugins/_bundled/*/plugin.json` 의 `assets.css.namespace` 에서 영문 소문자만 남긴 목록이다(PHP `App\Support\Style\ExtensionClassPrefixes` 와 같은 계산).

### 구간 건너뜀 사유

| 사유 | 뜻 |
| --- | --- |
| `binding` | 기본 또는 구간 className 에 바인딩이 있다 |
| `non-string` | className 이 문자열이 아니다(조건부 값 객체 등) |
| `has-class-merge` | 구간에 이미 classMerge 가 있다 |
| `same-as-base` | 구간과 기본의 토큰 집합이 같다(diff 가 비어 class-merge 로 쓸 수 없다) |
| `pure-removal` | 구간이 기본 토큰을 지우기만 한다 |
| `removed-base-token` | 구간에서 지운 기본 토큰이 병합 후에도 남는다(같은 축 토큰이 diff 에 없다. 예: `text-gray-500` 을 지우고 `text-xs` 만 둔 구간) |
| `merge-drops-token` | 병합 규칙이 구간에 있어야 할 토큰을 지운다(예: 구간에 `grid-cols-1` 과 `grid-cols-2` 가 함께 있다) |
| `class-map-binding` | classMap 값에 바인딩이 있어 결과를 셀 수 없다 |
| `class-map-mismatch` | classMap 결과가 diff 와 충돌해 실효 className 이 달라진다 |
| `below-min-overlap` | 같은 결과지만 `overlap` 이 `--min-overlap` 미만이다 |

엔진 색 그룹 정규식은 단계가 있는 색(`bg-gray-50`)만 그룹으로 본다. `bg-white`, `text-black` 같은 단계 없는 색은 그룹이 없어 서로 교체되지 않으므로, 이런 색을 바꾼 구간은 보통 `removed-base-token` 으로 남는다.

## 마크업 대조

`.g7.html` 의 `<responsive ... on="K" ... class="V">` 를 문서 순서로 찾는다(주석 안 태그는 세지 않는다). 속성값의 문자 참조(`&amp;` 등, 세미콜론으로 끝나는 것)는 컴파일러처럼 풀어서 비교한다.

- (구간 키, class 값) 묶음마다 `.json` 구간 수와 마크업 태그 수가 같아야 한다. 하나라도 다르면 그 파일 전체를 건너뛴다(`count-mismatch`).
- 같은 묶음 안 구간의 판정이 모두 같으면(모두 같은 diff 로 변환 가능, 또는 모두 건너뜀) 묶음의 모든 태그에 같은 결과를 쓴다.
- 같은 묶음 안에서 판정이 갈리면(같은 class 값이 기본이 다른 노드 여러 곳에 있다) `.json` 깊이 우선 순서와 마크업 위치 순서로 짝을 짓는다. 이 파일은 변환 후 원문을 실제 컴파일러로 메모리에서 컴파일해 verify 가 0 차이일 때만 받아들이고, 아니면 파일 전체를 건너뛴다(`ambiguous-group`).
- 원문이 문자 참조를 쓴 속성이면 새 값도 같은 관례로 인코딩한다.

### 파일 건너뜀 사유

| 사유 | 뜻 |
| --- | --- |
| `no-json` | 짝 `.json` 이 없다 |
| `json-parse-error` | 짝 `.json` 을 읽지 못했다 |
| `count-mismatch` | 묶음별 개수가 1:1 로 맞지 않는다(짝 `.json` 이 낡았거나 class 를 `<prop>` 등 다른 표기로 적었다) |
| `ambiguous-group` | 순서로 짝지은 결과가 확인 컴파일의 verify 를 통과하지 못했다 |

## 굽기와 verify

- 굽기: 바꾼 파일마다 `php artisan layout:decompile <파일> --sync` 를 부른다(`--jobs` 개씩 동시에).
- 메모리 컴파일: `compile-batch.php` 가 한 번 부팅으로 여러 원문을 `layout:decompile` 과 같은 경로(MarkupCompileContextResolver, LayoutMarkupCompiler)로 컴파일한다. 파일은 쓰지 않는다. 요청에 `write: true` 를 주면 `--sync` 와 같은 직렬화기(LayoutJsonWriter::write)로 짝 `.json` 을 굽는다(시험 픽스처 준비용).
- verify 는 변환 전 `.json`(실행 시작 때 메모리에 잡아 둔 것)과 변환 후 JSON 의 모든 responsive 오버라이드를 JSON pointer 로 짝지어, 실효 className 의 토큰 집합을 classMap 값마다 대조한다. 실효 className 은 `applyResponsiveClassMerge(구간 className ?? 기본 className, classMerge)` 에 classMap 값을 더한 것이다. 바인딩이 있는 구간은 원문이 그대로인지 본다.
- verify 는 구조도 대조한다. 변환 전 JSON 에 계획한 변환(구간 `props.className` 삭제, 빈 `props` 삭제, `classMerge` 추가)을 적용한 것과 변환 후 JSON 이 키 순서를 빼고 같아야 한다.

## 보고서

| 키 | 내용 |
| --- | --- |
| `root`, `mode` | 대상 루트, 실행 방식(`dryRun`, `sync`, `verify`, `minOverlap`) |
| `prefixes` | 판정에 쓴 확장 앞글자 목록 |
| `reasons` | 구간 사유(`segment`)와 파일 사유(`file`)의 설명 |
| `summary.markupFiles`, `summary.filesWithSegments` | `.g7.html` 수, 구간 className 이 있는 파일 수 |
| `summary.segments`, `summary.convertible`, `summary.converted` | 구간 수, 변환 가능 수, 실제로 바꾼(dry-run 은 바꿀) 수 |
| `summary.segmentSkips` | 구간 건너뜀 사유별 수 |
| `summary.filesChanged`, `summary.filesOrderAligned` | 바뀐(바뀔) 파일 수, 그중 순서로 짝지어 확인한 파일 수 |
| `summary.filesSkipped`, `summary.fileSkips` | 건너뛴 파일 수와 사유별 수 |
| `summary.segmentsInSkippedFiles`, `summary.convertibleInSkippedFiles` | 건너뛴 파일 안의 구간 수와 그중 변환 가능했던 수 |
| `summary.sync` | 굽기 실행 여부, 파일 수, 실패 수 |
| `summary.verify` | 대조 원천(`disk` 또는 `compiled-in-memory`), 파일 수, 대조한 구간 수, 차이 수, 구조 차이 파일 수, 컴파일 실패 수 |
| `files[]` | 파일별 `status`(`changed`, `unchanged`, `skipped`, `no-segments`), `reason`, `detail`, `orderAligned`, `sync`, `verify` |
| `files[].segments[]` | 구간별 `key`, `pointer`(JSON pointer), `status`, `reason`, `converted`, `base`, `segment`, `diff`, `overlap`(기본 토큰 중 구간에 다시 적힌 비율), `extra`(병합 후 남는 군더더기 토큰), `missing`(병합 후 빠지는 토큰), `otherProps`, `classMapValue` |

## 테스트

루트 `vitest.config.ts` 는 브라우저 코드용(jsdom)이라 `scripts/**` 를 포함하지 않는다. 이 도구는 전용 설정으로 돌린다.

```bash
npx vitest run --config scripts/layout-classmerge/vitest.config.mjs
```

Windows 에서는 작업 폴더 드라이브 문자가 대문자(`C:\…`)인 셸에서 돌린다. Git Bash 처럼 `c:/…` 로 잡히면 vitest 가 같은 모듈을 두 경로로 적재해 「failed to find the runner」로 실패한다.

- `scripts/layout-classmerge/__tests__/merge-engine.test.mjs`: 번들 함수가 공용 픽스처 `tests/Fixtures/class-merge/cases.json` 전 사례를 통과하는지, 엔진 원본 TS 를 직접 import 한 결과와 같은지, 전역 `window` 를 만들지 않는지, 확장 앞글자 계산
- `scripts/layout-classmerge/__tests__/analyze.test.mjs`: 구간 판정(변환 가능, 사유별 건너뜀, 다른 props 보존, classMap 충돌, 구간 classMap 우선, 확장 앞글자), 오버라이드 수집 순서, 마크업 스캔(주석, 여러 줄 태그, 작은따옴표, 문자 참조)
- `scripts/layout-classmerge/__tests__/convert.test.mjs`: 임시 폴더의 합성 `.g7.html` 과 짝 `.json` 으로 dry-run(파일 무변경, 사유 집계, 1:1 불일치 파일 건너뜀, 순서 짝 확인, 메모리 verify 0 차이, `--min-overlap`), `--no-sync`, 실제 `php artisan layout:decompile --sync` 굽기와 verify 0 차이, 주석과 줄 배치 보존, 멱등
