# 편집기 스펙 (editor-spec.json)

> 레이아웃 편집기가 템플릿/모듈/플러그인을 편집할 때 사용하는 편집 역량 선언 파일입니다.

## TL;DR (5초 요약)

```text
1. editor-spec.json = 편집기 팔레트/스타일 컨트롤/중첩 규칙/샘플 데이터/레시피의 선언 (수작업 작성)
2. 타입 SSoT = resources/js/core/template-engine/layout-editor/spec/specTypes.ts (EditorSpec)
3. 분할 형식: manifest(editor-spec.json) + `$include` 맵 → editor-spec/{block}.json. 서버가 합본해 단일 spec 으로 서빙
4. 서빙은 활성 디렉토리만 기준 (_bundled 폴백 없음). _bundled 작업분은 {type}:update 로 활성 반영 후 런타임 노출
5. 친화 라벨은 $t: 다국어 키, comment 는 작성자 메모(엔진 무시), 알려진 필드 외 자유 필드는 보존만
```

## 개요

`editor-spec.json` 은 레이아웃 편집기가 한 확장(템플릿/모듈/플러그인)을 편집할 때 필요한 메타데이터를 선언한다. 어떤 컴포넌트를 팔레트에 노출할지, 각 컴포넌트에 어떤 스타일 컨트롤·이벤트·표시조건 편집을 허용할지, 무엇을 어디에 중첩할 수 있는지, 캔버스 프리뷰에 어떤 샘플 데이터를 쓸지 등을 담는다.

이 파일은 자동 생성 산출물이 아니라 **수작업으로 작성**한다. 확장 루트(`module.json`/`plugin.json`/`template.json` 옆)에 둔다.

- 타입 SSoT: `resources/js/core/template-engine/layout-editor/spec/specTypes.ts` 의 `EditorSpec` 인터페이스 — 각 블록의 의도·병합 규칙·플레이스홀더 문법이 JSDoc 으로 명시되어 있다. 본 문서와 타입이 어긋나면 타입이 정본이다.
- 서빙 엔드포인트: `/api/{templates|modules|plugins}/{id}/editor-spec`
- 로더: `editorSpecLoader.ts` — 편집 대상 템플릿 + 활성 모듈/플러그인 스펙을 fetch 해 네임스페이스 병합한다.

## 파일 구조 — manifest + 분할 블록

editor-spec.json 이 커지면(코어 admin 템플릿은 단일 파일 18,000줄을 넘었다) 상위 블록 단위로 분할한다. manifest 에 메타와 소형 설정을 인라인으로 두고, 대형 블록은 `$include` 맵으로 `editor-spec/{block}.json` 을 참조한다.

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "templateId": "sirsoft-basic",
  "version": "1.0.0",
  "description": "...",
  "styleSystem": "tailwind",
  "darkMode": { "strategy": "ancestor-class", "...": "소형 — 인라인 유지" },
  "$include": {
    "componentPalette": "editor-spec/componentPalette.json",
    "nesting": "editor-spec/nesting.json",
    "controls": "editor-spec/controls.json",
    "componentCapabilities": "editor-spec/componentCapabilities.json",
    "actionRecipes": "editor-spec/actionRecipes.json",
    "conditionRecipes": "editor-spec/conditionRecipes.json",
    "sampleGlobal": "editor-spec/sampleGlobal.json",
    "sampleData": "editor-spec/sampleData.json",
    "states": "editor-spec/states.json"
  }
}
```

### 합본 규칙

- `$include` 의 **키 = 합본 spec 의 top-level 키**, 값 = manifest 위치 기준 상대 경로. 블록 파일 내용이 그 키의 값이 된다(top-level merge).
- 서버는 `App\Extension\Helpers\EditorSpecAssembler` 로 manifest + 블록을 합본해 단일 spec 으로 서빙한다. 합본 결과에는 `$include` 키가 남지 않는다.
- `$include` 가 없는 단일 파일(미분할)은 그대로 spec 이 된다 — 분할은 선택이며, 작은 확장은 단일 파일로 두어도 된다.
- include 파일이 없거나 파싱 실패하면 그 키만 누락하고 나머지는 합본한다(무손실 디그레이드, 서버 로그 경고).
- 분할은 값을 변형하지 않는 기계적 이동이다. 분할 전 단일 파일과 합본 결과는 키-값이 동일해야 한다.

### 서빙은 활성 디렉토리 기준

런타임 서빙은 **활성 디렉토리(`templates/{id}/...`)만** 읽는다. `_bundled` 폴백은 없다. `_bundled` 에서 작업한 분할본은 `{type}:update {id} --force` 로 활성 디렉토리에 반영된 뒤에만 편집기에 나타난다. JSON 만 바뀐 경우 빌드는 불필요하고 update 만 실행한다.

## 블록별 작성 규칙

### componentPalette — 요소 추가 팔레트

`groups`(사이드바 카테고리 + 소속 컴포넌트 이름) + `entries`(컴포넌트별 친화 라벨 + `defaultNode` 골격). 네임스페이스 병합 시 `groups` 는 concat, `entries` 는 key 병합된다. `defaultNode` 는 컴포넌트 트리 노드와 동일 구조(`type`/`name`/`props`/`text`/`children`)이며 새 노드 삽입 시 골격으로 쓰인다.

### controls — 재사용 스타일 컨트롤

위젯 종류(`widget`)·친화 라벨(`label`)·적용 방식(`apply`)·대상(`target`)·선택지(`options`)를 한 곳에 선언하고 `componentCapabilities.styleControls`(스타일 탭) 또는 `propControls`(속성 탭)가 키로 참조한다. `apply` 타입은 `classToken`(클래스 토큰 교체) / `styleProp`(인라인 스타일 속성) / `cssVar` / `propValue`(임의 `node.props[propKey]` 기입 — 속성 탭의 기본 적용 방식). 색·크기 등 라이브러리 대응 컨트롤은 프리셋 토큰 + 자유값(tokenTemplate)을 함께 선언한다. 상/우/하/좌 여백처럼 측마다 다른 값이 공존하는 속성은 다값을 1급으로 다룬다(일괄/개별 2모드, 단일 토큰 교체 금지).

**`propControls` 전용 위젯 종류**:

- `icon-picker` — 아이콘 prop(예 `iconName`/`triggerIcon`)을 카탈로그 그리드에서 검색·선택한다. **카탈로그는 템플릿 소유**(라이브러리 종속) — Font Awesome 등 아이콘셋을 쓰는 템플릿이 `G7Core.layoutEditor.registerWidget('icon-picker', ...)` 로 자기 위젯을 등록한다(코어는 위젯명만 디스패치, 아이콘 라이브러리 토큰 0). 프리뷰 폴백은 `preview.html`(코어 비해석) → `preview.className` → raw value 순. 카탈로그 미공급 시 자유 텍스트 입력으로 디그레이드.
- `options-list` — `Select`/`RadioGroup` 등의 정적 `options` 배열(value/label 행)을 추가/삭제/이동 편집한다. 값이 `{{바인딩}}` 이면 "바인딩됨(코드 편집)" 디그레이드(덮어쓰기 차단). 데이터소스 바인딩(dataProps `options`)과 직교 공존(위 "선택지 직교 완화").
- `array-items` / `array-group` / `array-cell-tree` / `number-list` — 정적 배열 prop 편집(탭/차트 데이터/카드 컬럼 등). `nodeEditor` 슬롯 또는 propControl 로 결선한다(아래 "데이터 정의 빌트인" 참조).
- `i18n-text` vs `text` — **표시 텍스트**(사용자가 화면에서 읽는 라벨/메시지/플레이스홀더)는 `i18n-text` 를 쓴다. `i18n-text` 는 평문 입력 시 `createCustomKey` 로 다국어 키를 자동 생성해 `$t:custom.*` 로 치환하고(미리보기에 raw 키 미노출), 🌐 펼침으로 ko/en/ja 동시 편집을 제공하며, `{{바인딩}}` 값이면 읽기전용 디그레이드한다(공통 위젯 `I18nTextField`). **비-표시값**(URL/id/수치/색/통화코드/separator 등)만 `text` 를 쓴다. 표시 텍스트에 `text` 를 쓰면 raw `$t:` 노출·다국어 누락 회귀가 발생한다(audit `editor-array-label-field-i18n` 가 배열 라벨 필드의 `text` 사용을 차단).

### componentCapabilities — 컴포넌트별 편집 역량

컴포넌트별로 `propControls`(속성 탭 컨트롤 화이트리스트 — `controls.json` 의 `apply.type==="propValue"` 컨트롤 참조, 비-스타일 prop 편집) / `dataProps`(데이터 연결 선언 — 아래) / `styleControls`(스타일 탭 컨트롤 화이트리스트) / `advanced`(고급 속성) / `events`(액션 편집 이벤트 — 미선언 시 동작 탭 숨김) / `flexEditor`(`container`/`item`/`auto` — 정렬 박스 편집 역할) / `visibilityCondition`(표시조건 탭 허용 여부) / `nodeEditor`·`canvasOverlay`(구조 에디터 일반 슬롯 `{kind,params}` — 종류별 고정 키 금지, kind 핸들러 레지스트리 디스패치)을 선언한다.

**데이터 연결(`dataProps`)**: "데이터를 바라보는 prop"(컬렉션의 `data`/`items`/`options` 배열, 입력 `value`·텍스트 등 단일값)을 데이터소스/상태에 바인딩하는 선언이다. 각 항목은 `{propKey, shape:'scalar'|'array', label?, itemFields?, required?, sources?}`. [속성] 탭 최상단 "데이터 연결" 영역이 각 항목을 검색형 바인딩 행으로 렌더하고, 선택 시 `{{<source>.<path>}}` 가 `node.props[propKey]` 에 기입된다(런타임 동일). 데이터 prop 은 **항상 바인딩 모드**(정적↔바인딩 토글 없음). **구조/수치 prop**(열 수·간격·페이지크기·크기)·**enum 선택**(variant/position)·**boolean config**(required/disabled)는 dataProps 비대상 — `propControls` 정적 편집이다. `dataProps` 와 `propControls` 는 직교한다(한 prop 은 한쪽만). `shape: 'array'` 면 `itemFields`(항목 객체 주요 필드)를 권장한다(검색 피커 항목 미리보기). `shape: 'object'` 는 컴포넌트가 객체 1건을 통째로 바라보는 prop(예 ProductCard.product, Avatar.author)용이다. 후보 풀은 편집기 샘플 데이터(data_sources `sampleData` shape + `sampleGlobal`) 가 SSoT 다(런타임 응답 추측 금지). audit `editor-datapropspec-shape-valid` 가 스키마를 가드한다.

**선택지(options) 직교 완화**: 선택 컴포넌트(Select/RadioGroup/TagInput/SearchableDropdown 등)의 `options` 배열은 정적 목록 직접 편집(propControls `selectOptions` → `apply.propKey: "options"`)과 데이터소스 바인딩(dataProps `options` array)을 **둘 다** 노출한다 — 같은 `options` prop 에 정적·동적 두 입력을 모두 제공한다(부록6 "한 prop 은 한쪽만" 규칙의 명시적 완화). audit 룰의 propControls 비교는 controlKey 문자열 기준이라 `selectOptions`(controlKey) ≠ `options`(dataProps propKey)로 자동 통과한다.

**반복(iteration) 데이터 연결**: `node.iteration.source`(반복 렌더링의 배열 소스)는 `props` 가 아니라 **노드 최상위 구조 키**라 컴포넌트 capability(`dataProps`)와 무관하다. iteration 은 Div/Span/Button/Li 등 거의 모든 컴포넌트에 붙을 수 있으므로, 편집기는 `dataProps` 선언 여부와 별개로 **iteration 을 가진 모든 노드**에 [속성] 탭 최상단 "반복 데이터 연결" 공용 영역(`IterationBindingSection`)을 노출한다(항상 array shape 바인딩). `item_var`/`index_var` 는 안쪽 자식 바인딩과 연동되어 읽기전용 힌트로만 표시한다(변경은 코드 편집).

**안전 바인딩 정규화**: G7 표준 바인딩은 `{{products?.data?.data ?? []}}` 처럼 옵셔널 체이닝(`?.`)과 널 병합 폴백(`?? []`/`?? ''`)을 쓴다. `parseBindingExpression`(역해석)은 이를 `?.`→`.` 흡수·폴백 분리(단순 리터럴만)·외곽 괄호 제거로 정규화해 소스/경로를 추출하고 연결됨으로 인식한다(`conditionRecipeEngine.normalizeConditionExpr` 선례와 동형 — 읽기 전용, 저장값 무변경). 후보 선택 시 `buildBindingExpression` 이 shape 별 안전 형태(`{{src?.path ?? []}}` array / `?? ''` scalar / `?? {}` object)로 재기입해 데이터 미도착 시 런타임 에러를 막는다. 진짜 복합식(삼항·파이프·다중 바인딩)은 "복합 바인딩(코드 편집)"으로 디그레이드한다.

**구조 에디터 슬롯(`nodeEditor`/`canvasOverlay`) — 종류별 고정 키 금지**: 목록(Ul/Ol/Nav/Form)·표(Table)·배열(탭/차트/카드 컬럼) 같은 구조 데이터의 전용 에디터는 `nodeEditor:{kind,params}`(속성 탭 본체) / `canvasOverlay:{kind,params}`(캔버스 인플레이스)로 선언한다. `kind` 는 레지스트리 키(예 `"children"`/`"table"`/`"array"`/`"array-group"`/`"array-cell-tree"`/`"tabnav"`)이고, `params` 는 그 kind 에디터가 해석하는 **불투명 객체**(children=아래 항목, table=행/셀/span 역할 매핑, array=항목 스키마)다. `tableEditor`/`childrenEditor` 같은 **종류별 고정 키를 capability 스키마에 두지 않는다** — 코어 모달/오버레이는 kind 만 보고 레지스트리에서 핸들러를 찾아 디스패치하므로(`if(kind==="table")` 분기 0), 새 구조 에디터는 코어 수정 없이 kind 등록만으로 추가된다.

**array kind params — `defaultItems`(선택)**: 컴포넌트가 그 prop 미지정 시 **내장 기본 목록**으로 렌더하는 경우(예 IconSelect 기본 아이콘 20종), 그 내장 목록을 `params.defaultItems` 에 그대로 선언한다. 에디터는 prop 이 미정의(undefined)일 때 그 목록을 시작 상태로 표시하고, 첫 변경 커밋 시 전체 목록+변경분을 함께 기록한다 — 항목 1개 추가가 내장 목록 전체를 교체하는 함정 차단. 명시적 빈 배열(`[]`)은 작성자 의도로 존중(시드 안 함). 컴포넌트 내장 목록과 스펙 선언의 드리프트는 동기화 가드 테스트로 차단할 것(컴포넌트가 기본 목록을 export → 테스트가 spec JSON 과 대조).

**children kind params** — `{childComponent, childLabel?, childTemplate?, itemFields?}`:

- `childComponent`: 추가할 자식 컴포넌트명(팔레트 `defaultNode` → 매니페스트 `props.default` 폴백으로 골격 생성).
- `childTemplate`(선택): "추가" 버튼이 만들 골격 노드 JSON 전체. 선언 시 `childComponent` 골격보다 우선한다 — 폼처럼 "라벨+입력칸 묶음" 행 단위 추가를 템플릿이 선언하는 용도. 골격 안에서 `"text": ""` 를 **선언한** 텍스트 노드에만 기본 안내 텍스트("새 항목")가 시드된다. `text` 미선언 자식(Input 등 void element)에는 시드하지 않는다(React error #137 크래시 차단).
- `childLabel`(선택): 추가 버튼 친화 명칭(`$t:` 토큰 가능). 미선언 시 `childComponent` 이름 그대로.
- `itemFields`(선택): 각 항목 행의 편집 필드 선언 배열. 미선언 시 `[{"kind":"text"}]`(항목의 의미 텍스트 자손 1필드 — 종전 동작). 필드 종류는 `{"kind":"text","label?"}` = 텍스트 자손 편집, `{"kind":"prop","prop":"<propKey>","label?"}` = 그 prop 을 가진 첫 자손의 `props[propKey]` 편집(예 Form 항목의 `placeholder`). **코어는 prop 이름을 모른다** — 어떤 prop 을 항목 편집에 노출할지는 전적으로 스펙 선언이다. 모든 필드는 동적 다국어 공통 위젯(`$t:custom.*` 키 생성/로케일 값 갱신)으로 편집되며, 항목에 해당 위치(텍스트 자손/prop 자손)가 없으면 그 필드는 그 행에서 숨겨진다. 편집 가능 필드가 전무한 구조 자식은 컴포넌트명 라벨 + 정렬/삭제만 제공한다.

**역할 식별 (이름 가정 금지)**: 코어는 컴포넌트 **이름**(`name==="Table"`)으로 표/목록/배열을 식별하지 않는다. 어떤 컴포넌트든 capability 의 `nodeEditor.kind`/`canvasOverlay.kind`/`dataProps`/`flexEditor` **역할 선언**으로 편집 동작을 결정한다. 따라서 `div`-그리드로 만든 표도 `nodeEditor:{kind:"table",params:{...역할 매핑}}` 만 선언하면 표 에디터가 붙고, 커스텀 목록 컴포넌트도 `nodeEditor:{kind:"children"}` 으로 목록 에디터가 붙는다. `params` 의 역할 매핑(rowContainer/row/cell 등)은 컴포넌트 이름이 아니라 그 컴포넌트가 어떤 자식 구조를 쓰는지를 적는다.

**데이터 정의 빌트인(옵션/배열/차트 수동 데이터)**: 정적으로 편집 가능한 "데이터 정의" 표면(Select.options, TabNavigation.tabs, BarChart.labels+datasets, DonutChart.data, CardGrid.cardColumns, DynamicFieldList.columns, SocialLoginButtons.providers 등)은 모두 `nodeEditor`(array/array-group/array-cell-tree) 또는 `propControls`(options-list) 로 편집 가능하게 선언한다. 전 draggable 컴포넌트는 데이터 표면을 가지면 편집 슬롯을 보유하거나(누락 0), 보유하지 않으면 비대상 allowlist(flat scalar / 표시 텍스트 / 레이아웃 / 런타임 scalar 바인딩전용)에 등록되어야 한다 — audit `editor-all-draggable-data-editable` 가 둘 다 아닌 draggable 을 차단한다.

**코어 제공 속성(요소 ID) + opt-out**: 코어는 모든 draggable 컴포넌트의 [속성] 탭 최상단에 "요소 ID" 컨트롤을 일괄 제공한다(값 = 표준 `node.props.id`, 강제 DOM 주입 없음 — 컴포넌트 passthrough 책임). 기존 `elemId`(→id) 같은 템플릿 propControl 은 코어로 이전(중복 선언 금지 — 코어 우선). 인라인 루트가 없는 컴포넌트(서드파티 모달/Portal)는 capability 에 `"coreProps": false` 로 opt-out 한다. `"coreProps": ["id"]` 처럼 부분집합 선언도 가능(미선언 = 코어 기본 전체). 코어 id 컨트롤은 `{{바인딩}}` 값이면 "바인딩됨(코드 편집)" 디그레이드, HTML 안전 문자만 허용(한글·공백 자동 제거). 컴포넌트 측 id passthrough 규약은 `docs/frontend/components-types.md` "요소 id 패스스루" 참조.

### nesting — 중첩 규칙

`draggable`(드래그/팔레트 배치 가능한 컴포넌트 이름) + `containers`(컨테이너별 `accepts` 자식 허용 목록). 병합 시 `draggable` 은 union, `containers` 는 key 병합. `accepts: []` 는 명시적 자식 거부, 엔트리 없는 컴포넌트는 어떤 자식도 받지 않는다(폴백 없음).

### sampleData — 캔버스 프리뷰 샘플 데이터

편집 모드 캔버스 렌더 시 데이터소스 응답을 결정한다. `byDataSourceId`(데이터소스 id 우선) → `byEndpointPattern`(엔드포인트 패턴 매칭) 순. 로더가 출처별 `bySource` 맵으로도 보존해 같은 id 를 여러 확장이 다른 shape 로 정의한 충돌을 해소한다. 샘플의 shape 은 실제 API Resource 가 바인딩하는 필드와 일치시킨다.

### sampleGlobal — `_global.*` baseline 시드

편집 모드 격리 store 의 `_global.*` baseline(헤더/푸터 등이 의존하는 `currentUser`/`settings`/`site` 등)을 선언한다. 코어 시드 → 모듈 → 플러그인 → 템플릿 순으로 deep merge 되며, 코어 keyspace leaf 충돌 시 코어가 이기고 dev 콘솔에 경고를 남긴다. 배열은 통째 교체된다.

### states — 페이지 상태 변종

한 라우트/base/modal 이 진입 맥락·사용자·검증 결과에 따라 가질 수 있는 변종 화면을 선언한다. `groups`(scope `route`/`base`/`modal` + items) 구조이며 병합 시 concat. 각 item 은 `sampleDataOverrides`(통째 교체) + `initialState`(local/global/query/route) + `formErrors`(필드 검증 실패 시뮬레이션) 결합이다. 그룹당 `default: true` 1개. items 가 2개 이상인 scope 에서만 편집기 캔버스 툴바에 상태 드롭다운(PageStateSwitcher)이 표시되며, 1개 이하/미선언/scope 미매칭이면 토글은 미표시되고 시뮬레이터는 no-op 으로 디그레이드한다.

`scope.kind: route` 매칭은 라우트 path 정확 일치 또는 `*` 세그먼트 glob 1단계(한 path 세그먼트). `base`/`modal` 은 정확 일치다. `scope.match` 는 `routes.json` 의 **실제 path 와 문자 단위로 일치**해야 토글이 표시된다 — admin 라우트는 실제 path 에 `*/admin/...` 프리픽스가 붙으므로(예 `*/admin/users`) scope.match 도 그 프리픽스를 포함해야 한다. 작성 시 추측하지 말고 `routes.json` 의 실제 path 를 확인한다.

`initialState` 의 네 갈래:

- `local`/`global`: `_local`/`_global` baseline 위 부분 머지(사용자/검증 결과에 따른 화면 변종).
- `query`: URL 쿼리 컨텍스트 오버라이드 — baseline(빈 객체) 위 부분 머지. 편집기는 평소 query 가 비어 있어 `{{query.tab}}`/`{{query.error}}` 같은 진입 맥락 분기 변종을 이 패치 없이는 미리볼 수 없다(예 `settings` 화면의 `query.tab=general|seo` 서브탭).
- `route`: path param 컨텍스트 오버라이드(`{{route.id}}` 유무 = 수정↔신규 작성 모드 변종). `null` 값을 주면 해당 토큰을 **제거**해 `sampleRouteParams` 자동 채움을 무력화한다(예 `users/:id/edit` 의 `route.id: null` → 신규 작성 모드). 통째 교체가 아니라 baseline 위 머지다.

`formErrors` 의 **키는 화면이 실제로 읽는 상태 경로**를 그대로 적는다 (`_local.errors.email`, `_global.loginErrors.email` 등). 값은 그 경로가 기대하는 형태(대개 표시 메시지 문자열 또는 `[message]` 배열 — 화면이 `?.[0]` 으로 읽으면 배열)다. 코어는 키를 dot-path 로 해석해 `_local.`/`_global.` 접두를 보고 해당 상태에 deep set 하며, 접두 없는 키는 `_local` 에 그대로 주입한다. FormContext 같은 별도 주입 메커니즘은 없다 — 레이아웃마다 검증 오류를 보관하는 경로가 제각각(`errors`/`loginErrors`/`addressErrors`/`formErrors` 등)이므로, 작성자가 정확한 경로를 지정해야 사용자 페이지와 동일하게 적색 오류가 표현된다. 메시지 값의 `$t:` 키는 편집 대상 사전으로 해석된 뒤 주입된다.

**점이 박힌 필드명은 대괄호 표기로 감싼다.** 점은 기본적으로 칸막이(경로 구분자)로 해석되지만, 키 자체에 점이 들어 있는 필드(예 `/shop/checkout` 의 주문자 입력칸은 `_local.errors?.['orderer.name']` 을 읽는다)는 `_local.errors['orderer.name']` 처럼 `['...']`(또는 `["..."]`)로 감싸 **리터럴 단일 키**로 지정한다. 대괄호 안의 점은 칸막이가 아니라 키의 일부로 보존된다 — 결과는 `{ errors: { "orderer.name": [...] } }`(중첩 `errors.orderer.name` 아님). 일반 점 구분자와 대괄호 표기는 한 키 안에서 혼용할 수 있다.

### actionRecipes — 친화 명칭 → 핸들러 JSON

`params`(친화 입력란) + `build`(핸들러 JSON 생성 틀)로 구성한다. `build` 값의 `{{paramKey}}`(중괄호 2개)가 입력값으로 치환된다. `onSuccess`/`onError` 가 `{{key}}` 이고 그 파라미터가 `action-list` 면 치환 결과는 중첩 액션 **배열**이 된다. 핸들러명/파라미터 키는 사용자에게 노출하지 않는다 — 사용자는 `label` 친화 명칭만 본다. `build.handler` 는 코어 핸들러 규칙의 올바른 핸들러(`navigate`/`apiCall`/`setState`/...)를 쓴다. `comment` 키는 레시피가 아닌 작성자 메모다.

`build.handler` 는 리터럴 핸들러명 대신 `{{paramKey}}` **placeholder** 도 가능하다 — 호출할 핸들러를 응답값 등 데이터로 결정하는 provider-agnostic 액션(예: ecommerce 모듈 `requestPgPayment` "결제 진입" — 핸들러를 `{{response.data.pg_payment_handler}}` 데이터 칩으로 연결)에 쓴다. 결제 같은 **도메인 동작은 코어가 아니라 그 도메인 확장(모듈/플러그인/템플릿)이 자기 editor-spec `actionRecipes` 에 소유**한다 — 코어는 PG/도메인을 모른다. 확장 레시피는 로더가 `__source` 메타를 붙여 〔확장식별자〕 배지·'extension' 그룹으로 자동 분류한다. 라벨은 그 확장의 lang 네임스페이스를 명시한다(예: `$t:sirsoft-ecommerce.editor.action.request_pg_payment.label`).

이때 `matchAction`(역해석)은 placeholder-aware 로 동작하되, recipe 의 고유 `build.params` 키(예: `pgPaymentData`)가 실제 액션에 실재할 때만 후보로 인정한다(placeholder 핸들러가 무관 액션을 흡수하는 greedy 매칭 방지). 핸들러 필드를 데이터 칩으로 바꿔 쓸 수 있어야 하므로(`feedback_editor_handler_field_must_be_data_chip_bindable`), `buildAction` 은 ① placeholder 핸들러가 미입력으로 사라지면 build 토큰을 복원하고 ② **placeholder 핸들러 recipe 한정** `required` 입력 토큰도 미입력 시 보존한다 — 그래야 핸들러를 임의 데이터 칩으로 바꾸고 다른 required 값을 비워둬도 `params` fingerprint 가 유지돼 친화 카드가 [고급]으로 강등되지 않는다(저장·새로고침 후에도 친화 복원). 일반 recipe(리터럴 핸들러)는 미입력 required 를 그대로 떨궈 깔끔한 JSON 을 유지한다. 런타임에서 `ActionDispatcher` 가 `{{...}}` 핸들러를 컨텍스트로 먼저 해석한 뒤 라우팅한다(engine-v1.50.0).

#### actionChipCandidates — 동작 데이터 칩 컨텍스트 후보(도메인 응답 필드)

데이터 소스 onSuccess(`response.*`)·onError(`error.*`)·onReceive(`message.*`)·컴포넌트 동작 apiCall onSuccess 의 data-chip 입력칸이 고를 수 있는 표현식 칩 후보를 컨텍스트별로 선언한다. 코어는 도메인 중립 루트(`response.data`/`error.status` 등)만 알고, 확장이 내려주는 **도메인 응답 필드**(예: PG 결제 응답의 `data.pg_payment_handler`)는 그 확장이 본 블록으로 선언해 코어가 도메인을 모른 채 병합·노출한다.

```json
"actionChipCandidates": {
  "response": [
    { "path": "data.pg_payment_handler", "labelKey": "sirsoft-ecommerce.editor.action_chip.response_pg_handler", "shape": "scalar" }
  ]
}
```

- 컨텍스트 키: `response` / `error` / `payload`. 각 값은 `{ path, labelKey, shape? }[]`. `path` 는 컨텍스트 루트 이하 점 경로(표현식은 `{{response.<path>}}`), `labelKey` 는 그 확장 lang 네임스페이스의 친화 라벨 키(`$t:` 없이 키만), `shape` 는 위젯 필터용(scalar/object/array, 미지정 scalar).
- 병합: 로더가 컨텍스트별 배열을 **concat**(코어 기본 칩 뒤에 확장 후보). 같은 path 는 코어 우선으로 1회만 노출(확장이 코어를 덮지 않음).

#### 중첩 액션 컨테이너 위젯 — `action-list` / `branch-list`

응답 후속·다단·분기 동작을 코드 없이 친화 편집하기 위한 두 위젯이 있다.

- `widget: 'action-list'` — 평면 액션 배열(`onSuccess`/`onError`/sequence·parallel `actions`)을 중첩 `ActionListBuilder` 로 재귀 편집(추가/순서/속성·데이터 칩). 코어 `apiCall`(onSuccess/onError)·`sequence`·`parallel` 이 사용. build 값은 `'{{onSuccess}}'` 처럼 sole-binding 으로 두면 치환 시 중첩 액션 **배열**이 그대로 들어간다(문자열 보간 아님). `switch.cases` 는 런타임이 객체맵(`Record<string,…>`)이라 action-list 로 표현 불가 → `advanced: true` 잠금 유지.
- `widget: 'branch-list'` — `conditions` 핸들러의 분기 배열(`[{if?, then}]`)을 분기별 실행조건(조건식 데이터 칩)·동작(중첩 `action-list`, 단일/배열 both) + 분기 추가/삭제/순서로 편집. 코어 `conditions` recipe 가 사용한다. 분기는 액션이 아니라 조건+동작 묶음이라 `action-list` 가 아닌 전용 위젯이 필요하다. recipe build 는 `conditions` 를 **액션 최상위 키**(`{ handler:'conditions', conditions:'{{branches}}' }`)로 둔다 — `handleConditions` 가 `action.conditions` 만 읽으므로 `params` 아래 두면 런타임이 인식하지 못한다. sole-binding 통째 캡처라 `then` 구조(단일/배열, placeholder 핸들러 포함)가 무손실 왕복된다.

`summarizeAction`(카드 한 줄 요약)은 `advanced` 또는 `action-list` 위젯 param 을 요약 꼬리에서 제외한다(중첩 배열이 `[N]` 토큰으로 새지 않도록). 이 위젯들로 `sequence → apiCall.onSuccess → conditions → then` 깊이의 동작도 특정 템플릿/모듈 의존 없이 코어 전역에서 친화 편집된다.

### conditionRecipes — 친화 조건 → `if` 표현식

`operators` 배열에 조건 후보를 둔다. 각 항목의 `expr` 은 **`{{}}` 없이** 식 본문만 적고, 파라미터 자리는 레시피 전용 플레이스홀더 `{paramKey}`(중괄호 1개)를 쓴다. `ConditionBuilder` 가 파라미터를 치환하고 여러 조건을 결합한 뒤 **최종 결과 전체를 단일 `{{ }}` 한 쌍**으로 감싼다(중첩 보간 `{{ {{x}} }}` 미발생). `actionRecipes` 의 `{{paramKey}}`(2개)와 혼동하지 않는다.

### stateLabels — 상태값 명칭 카탈로그 (데이터 연결 검색 피커용)

`_global.currentUser`·`query.q` 등 **템플릿 공통 상태값**의 로케일별 친화 명칭을 한 곳에서 선언한다. `[{ key, scope, label_key }]` 배열 — `key` 는 scope 루트 이하 점 경로(예 `currentUser.data.name`), `scope` 는 `data_source`/`_global`/`_local`/`route`/`query`/`_computed`, `label_key` 는 `$t:` 다국어 키. 데이터 연결(`dataProps`) 검색 피커가 후보를 표시할 때 raw 상태 키 대신 친화 명칭을 보여 주는 데 쓴다. 명칭이 `$t:` 키라 언어팩으로 로케일이 동적 추가되면 그 로케일 명칭도 자동 대응한다. 병합 시 concat(같은 key+scope 충돌은 뒤 단계=템플릿 우선). 카탈로그가 커버하지 못하는 상태값(레이아웃마다 의미가 다른 `_local.*` 등)은 키를 그대로 폴백 표시한다 — 마지막 세그먼트 가공·추측 명칭 생성은 하지 않는다. data_source 명칭은 `stateLabels` 가 아니라 data_source 정의의 `label_key` 로 단다.

### data_source `label_key` — 데이터 소스 친화 명칭 (검색 피커용, 레이아웃 JSON)

`stateLabels` 가 상태값(`_global`/`query` 등) 명칭을 editor-spec 카탈로그에서 다는 것과 달리, **data_source 의 친화 명칭은 그 소스를 선언한 레이아웃 JSON 의 `data_sources[]` 항목에 `label_key` 로 직접 단다**(`$t:` 다국어 키 — 예 `"label_key": "$t:editor.data_source.products"`). 데이터 연결(`dataProps`) 검색 피커가 data_source 후보를 표시할 때 현재 로케일의 친화 명칭으로 보여 주며, 미지정 시 소스 id 로 폴백한다. 명칭 키는 편집 대상 템플릿의 편집기 i18n(`lang/partial/{locale}/editor.json` 의 `data_source.<id>`)에 정의한다 — 언어팩으로 로케일이 동적 추가되면 자동 대응한다.

`label_key` 는 레이아웃 편집기의 **데이터 소스 편집기**(툴바 `⚙데이터` → `DataSourcesPanel`)가 관리한다. 번들 템플릿의 모든 레이아웃은 모든 data_source 에 `label_key` 를 보유해야 하며(누락 0), audit `data-source-label-key-coverage` 가 신규 레이아웃의 누락·비-`$t:` 값을 차단한다.

### darkMode — 다크 모드 표현 (소형, manifest 인라인 권장)

템플릿이 다크 모드를 어떻게 표현하는지 선언한다. `strategy`: `ancestor-class`(조상 클래스, Tailwind `.dark`) / `media-query` / `none`(다크 미지원 — 편집기 다크 탭 비노출). `previewIsolation`(`rewriteSelector` → `replaceWith`, `flattenLayers`)은 편집기 프리뷰를 admin 호스트의 `html.dark` 조상과 독립적으로 격리하기 위한 셀렉터 치환 규칙이다. 코어 CSS 서빙 API 가 이 선언대로 편집기용 CSS 의 다크 셀렉터를 프리뷰 전용 마커로 치환해 서빙한다(사용자 페이지 CSS 는 원본 그대로 — 무영향).

## 편집기 확장점 — `G7Core.layoutEditor`

템플릿은 커스텀 속성 위젯·노드 에디터·캔버스 인플레이스 오버레이를 코어 레지스트리에 등록해, 코어 수정 없이 자기 컴포넌트의 편집 UI 를 확장한다. 코어는 위젯명/kind 만 디스패치하고(메커니즘만 제공), 등록된 핸들러를 찾아 렌더한다.

| API | 용도 | capability 참조 |
| --- | --- | --- |
| `G7Core.layoutEditor.registerWidget(name, Comp)` | 속성/스타일 컨트롤 위젯 | `controls.json` 의 `widget: "<name>"` |
| `G7Core.layoutEditor.registerNodeEditor(kind, Comp)` | 노드 에디터(속성 탭 본체) | capability `nodeEditor.kind` |
| `G7Core.layoutEditor.registerCanvasOverlay(kind, Overlay)` | 캔버스 인플레이스 오버레이 | capability `canvasOverlay.kind` |
| `G7Core.layoutEditor.onReady(cb)` | 편집기 셸 로드 완료 콜백 | — |

**등록 시점 = 템플릿 module-load(최상위), `initTemplate`/`window.load` 게이트 밖.** 편집기 셸 번들은 lazy 로드라 템플릿 부트스트랩 시점에는 아직 실제 레지스트리가 없을 수 있다. 코어 메인 번들이 `G7Core.layoutEditor` **stub(예약 접수함)**을 항상 노출해 register* 호출을 큐에 적재하고, 편집기 셸 로드 시 실제 레지스트리로 교체하며 큐를 일괄 flush 한다. 따라서 템플릿은 module-load 시점에 즉시 등록해도 누락되지 않는다 — `initTemplate` 안에 두거나 `window.load` 를 기다리면 편집기 직접 진입(SPA 전환 없이 하드 로드) 시 위젯이 누락되는 간헐 회귀가 발생한다. `G7Core?.layoutEditor?.registerWidget` 옵셔널 체이닝으로 가드한다(identity launcher 패턴과 동형).

작성 예시(`templates/_bundled/sirsoft-admin_basic/src/layout-editor/registerEditorWidgets.ts`):

```ts
// 템플릿 index.ts 최상위(module-load)에서 호출 — initTemplate/window.load 밖
export function registerSirsoftAdminBasicEditorWidgets(): void {
  if (typeof window === 'undefined') return;
  const layoutEditor = (window as any).G7Core?.layoutEditor;
  if (!layoutEditor?.registerWidget) return; // stub 부재 → 다음 호출/큐가 보존
  layoutEditor.registerWidget('icon-picker', IconPickerWidget);
  // 부록4-bis 레퍼런스: TabNavigation tabs 캔버스 인플레이스(+추가/✕삭제/◀▶이동).
  // capability `canvasOverlay.kind:"tabnav"` 노드 선택 시 코어가 본 오버레이를 마운트.
  layoutEditor.registerCanvasOverlay?.('tabnav', TabNavInplaceOverlay);
}
```

캔버스 인플레이스 오버레이는 속성 패널의 같은 구조 prop 에디터(예 `ArrayItemsEditor`)와 **동일한 노드 패치 경로(SSoT)**를 써야 양방향 동기가 보장된다(같은 `node.props.tabs` 패치). 편집 모드 전용 마커(`data-editor-item-path` 등)는 런타임에 주입하지 않는다(사용자 페이지 누출 0).

## 편집기 UI 규약 (일관성 잠금)

레이아웃 편집기 코어/템플릿 UI 는 아래 규약을 따른다. 신규 에디터/컨트롤 작성 시 위반하면 회귀(선택 불가/잘못된 탭/라이브러리 종속/클릭 가로채기 등)가 재발한다.

1. **kind-agnostic 레지스트리** — 구조 에디터는 `nodeEditor`/`canvasOverlay` 의 `{kind,params}` 로만 선언·디스패치한다. 종류별 고정 capability 키(`tableEditor` 등) 금지, 코어에 `if(kind===...)` 분기 금지.
2. **탭 책임 분리** — 구조/배열/노드 에디터와 데이터 연결은 **[속성] 탭**, [스타일] 탭은 CSS(클래스/인라인 스타일) 전용. 구조 편집을 스타일 탭에 두지 않는다.
3. **항목/셀 텍스트 다국어** — 목록 항목·표 셀의 텍스트 다국어는 직접 `text` 직독이 아니라 공용 `nodeTextPath`(`findTextNodePath`) 로 텍스트 자손까지 탐색해 `createCustomKey` 로 키화한다(복합 HTML/컴포넌트 셀 구조 보존). 표시 텍스트 입력은 공통 `I18nTextField` SSoT.
4. **모드 탭은 묶음 상단 단일 공용 탭** — 라이트/다크처럼 직교 축 모드 탭은 관련 컨트롤(테두리색+배경색 등)마다 복제하지 않고, 색상 섹션 상단에 단일 공용 탭(`ColorSchemeTabs`) 1개로 두어 묶음 전체에 적용한다. 모드 상태는 부모 단일 상태, 컨트롤엔 읽기전용 prop.
5. **색 적용 = 라이브러리 중립** — 프리셋 색은 `classToken`(스킴별, 다크 `dark:` 공존), 자유 HEX 는 인라인 스타일(라이트 전용 — 다크 빌드 불가). 편집기 코어는 Tailwind/Bootstrap 클래스 토큰을 직접 쓰지 않고 `g7le-*` BEM + 표준 CSS/inline style 만 쓴다. layout flow 판정은 computed style 기반.
6. **어포던스는 대상 바깥 전용 레일 + 코어 위 z-index** — 거터/핸들/이동 버튼을 대상(셀/노드/항목) 위에 겹치지 않고 대상 바깥 전용 레인에 두고, 코어 오버레이 위 전용 z-index 밴드에 둔다(클릭 가로채기 0 — `elementFromPoint` topmost=self 로 실측). 빈 셀 찌부러짐은 편집기 전용 CSS(td height) 1회 주입(content 무오염).
7. **탭/인플레이스 본체는 노드 파생 무상태** — 속성 모달은 패치마다 content 를 재마운트하므로, 탭 본체(ConditionBuilder/배열 에디터 등)는 자체 `useState` 로 값을 들고 있지 않고 매 렌더 노드 prop 에서 재구성한다(또는 노드 path 로 keying + 자유값 state 는 `useEffect` 재동기화). stale state 로 인한 오저장/409 회귀를 막는다.

## 작성자 메모와 자유 필드

- `comment` 키는 어느 블록에서나 작성자 메모이며 엔진이 무시한다(레시피/컨트롤로 해석되지 않음).
- 코어는 알려진 필드 외의 자유 필드를 그대로 보존만 한다. 알려지지 않은 키를 추가해도 손실되지 않지만, 엔진이 해석하지도 않는다.

## 다국어

친화 라벨(`label`)·설명(`description`)·`formErrors` 메시지는 `$t:...` 다국어 키로 작성한다. 새 키는 해당 확장의 프론트엔드 다국어 파일에 정의되어야 한다.

## 관련 파일

- 타입 SSoT: `resources/js/core/template-engine/layout-editor/spec/specTypes.ts`
- 로더(fetch + 병합): `resources/js/core/template-engine/layout-editor/spec/editorSpecLoader.ts`
- 서버 합본 헬퍼: `app/Extension/Helpers/EditorSpecAssembler.php`
- 서빙: `app/Http/Controllers/Api/Public/PublicTemplateController.php`, `app/Http/Controllers/Api/Admin/AdminTemplateAssetController.php`, `app/Services/ModuleService.php`, `app/Services/PluginService.php`
