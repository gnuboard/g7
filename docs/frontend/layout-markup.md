# 레이아웃 마크업 (`.g7.html`) 저작 포맷

## TL;DR (5초 요약)

```text
1. 런타임 SSoT 는 JSON 레이아웃 그대로 — `.g7.html` 은 저작 포맷이고 설치 시 서버(PHP)가 JSON 으로 컴파일한다
2. 문법 3원칙: 접두어 없음(지시 속성만 data-*) / 소문자·kebab 태그 / 속성값에 JSON 금지(구조체=자식 태그, 배열=반복 태그)
3. 태그명이 컴포넌트를 정한다(레지스트리 kebab 해석). 조건표시=data-if, 반복=data-each, 참조=data-ref (무접두 if/each/ref·v-if 는 컴파일 에러)
4. 컴파일러: app/Support/LayoutMarkup/* — 토큰→트리→문서(강검증→lint). 커맨드 `layout:compile {path} --check`
5. 문서루트 4갈래(layout/partial/extension/override). 태그·속성명은 명명 3순위 규칙으로 확정(S8 동결, 2026-07-18)
```

> 문법 동결: 2026-07-18 (계획 D35, PO 승인). 이 문서가 태그·속성명 SSoT. 기존 JSON 스키마는 [layout-json.md](layout-json.md)(런타임 계약) — 산출 JSON 키는 불변이다.

## 위치

- 저작 파일: `.g7.html` (본 레이아웃·partial·확장 주입·오버라이드)
- 컴파일러: `app/Support/LayoutMarkup/`
- 산출물: 짝 `.json` (기존 레이아웃 엔진이 그대로 소비). `meta.authoring` provenance 표식으로 병존 판별
- 검증/컴파일 커맨드: `php artisan layout:compile {path} --check` (건조 실행) / `--all` / `--template={id}`

---

## 1. 문법 3원칙

1. **접두어 없음** — 무접두 속성은 컴포넌트 props 로 그대로 전달. 예외는 지시 속성(HTML5 표준 `data-*`)과 타입 리터럴(`:attr`).
2. **소문자·kebab 태그** — `<data-source>`, `<set-state>`, `<on-code>`.
3. **속성값에 JSON 금지** — 구조체는 1급 자식 태그(`<field>`, `<column>`, `<prop>`), 배열은 반복 태그.

### 속성 3분할

| 형태 | 뜻 | 예 |
|---|---|---|
| 무접두 `attr="v"` | props verbatim (문자열) | `className="btn"` `variant="primary"` |
| `:attr="v"` | 타입 리터럴 props (JSON: 불리언·숫자·배열·객체·null) | `:disabled="true"` `:count="3"` `:items="[]"` |
| `data-*` | 지시 속성 (노드 키 — props 아님) | `data-if` `data-each` `data-ref` `data-key` |

불리언 속성은 정적=존재/부재(`"false"` 금지 → 대신 `:attr="false"`), 동적=`{{}}`.

### void 요소 (닫는 태그 없음)

`<br>` `<hr>` `<img>` `<input>` `<area>` `<base>` `<col>` `<embed>` `<link>` `<param>` `<source>` `<track>` `<wbr>` (13종 — 표준 14종 − `<meta>`; G7 의 `<meta>` 는 `<seo>` 를 담는 컨테이너다). void 요소에 종료 태그를 쓰면 `void_end_tag` 에러.

---

## 2. 문서 루트 (4갈래)

| JSON | `.g7.html` | 근거 |
|---|---|---|
| 본 레이아웃 | `<layout name="…" version="1.0.0" extends="_admin_base">` | 개념어 |
| partial (루트=노드 하나, `meta.is_partial:true`) | `<partial>…</partial>` | 개념어(React Fragment 는 `<fragment>`) |
| 확장 주입 (`target_layout`/`extension_point`) | `<extension>` | 개념어 |
| 템플릿→확장 레이아웃 대체 (`override_target`) | `<override>` | 어휘 계승 |

---

## 3. 최상위 섹션 (`<layout>` 직속)

| JSON 키 | `.g7.html` |
|---|---|
| `version`/`layout_name`/`extends` | `<layout version="…" name="…" extends="…">` 속성 |
| `slots` | `<slot name="content">…</slot>` |
| `meta` | `<meta title="…" …/>` (속성→키), 하위 `<seo>`+`<og>/<vars>/<extensions>/<structured-data>` |
| `components`(standalone) | `<layout>` 직속 노드 (slots 없이) |
| `data_sources` | `<data-source …/>` (반복) — §4 |
| `computed` | `<computed name="total">{{a+b}}</computed>` (반복) |
| `initLocal`/`initGlobal`/`initIsolated` | `<init-local>`/`<init-global>`/`<init-isolated>` + `<field>`/`<from-source>` 트리 — §7 |
| `init_actions` | `<init>…</init>` (자식 액션, 축소 화이트리스트) |
| `named_actions` | `<action name="searchOrders">…</action>` |
| `defines` | `<defines><define name="x">JSON</define></defines>` |
| `permissions` | `<permissions>` — 아래 참조 |
| `errorHandling` | `<error-handling><on-code code="403">…</on-code></error-handling>` |
| `globalHeaders` | `<global-headers><match pattern="*">…</match></global-headers>` |
| `transition_overlay` | `<transition-overlay>` (bool은 `:enabled`) |
| `scripts` | `<script src="…" id="…" :async data-if="…"/>` (반복, src 화이트리스트) |
| `modals` | `<modal src="partials/_modal_delete.g7.html"/>` 또는 인라인 `<modal>노드</modal>` |

### permissions — 평면 배열 + 조건형 (S8 A2)

```html
<!-- 평면 AND (전부 필요) -->
<permissions>
  <permission>users.read</permission>
  <permission>users.write</permission>
</permissions>

<!-- 조건형 OR — {"or": ["core.users.create","core.users.update"]} -->
<permissions>
  <any-of>
    <permission>core.users.create</permission>
    <permission>core.users.update</permission>
  </any-of>
</permissions>
```

`<all-of>` → `{and:[...]}`. `<any-of>` → `{or:[...]}`. 중첩 가능. 평면 `<permission>` 과 조건 블록을 섞으면 에러(둘 중 하나만).

---

## 4. data_sources (`<data-source>`)

속성 구동(임의 이름 verbatim 방출): `id` `type` `endpoint` `method` `:auto_fetch` `:auth_required`/`auth_mode` `loading_strategy` `content-type` `label_key` `:refetch_on_mount` `data-if` `channel`/`channel_type`/`event`/`target_source`(websocket).

자식 태그: `<data>`(static) `<params>` `<headers>` `<fallback>` `<success>`/`<fail>`(→onSuccess/onError) `<error-handling>` `<init-local>`… `<all-of>/<any-of>`(→conditions) `<on-receive>`(ws) `<error-condition if error-code>`.

```html
<data-source id="users" endpoint="/api/admin/users" method="GET" :auto_fetch="true" loading_strategy="progressive"/>
```

---

## 5. 노드 + 지시 속성

태그명이 컴포넌트를 정한다(레지스트리 kebab→PascalCase 해석). 미등록 태그는 `unknown_tag_with_suggestion`(근접 제안).

| JSON 노드 키 | `.g7.html` |
|---|---|
| `type`/`name` | 태그명 자동 판정 (`<data-grid>` `<button>`) |
| `props` | 무접두 속성 + `<prop name="…">JSON</prop>` |
| `children` / `text` | 자식 태그 / 태그 본문 (둘 공존 = `mixed_text_and_children` 에러) |
| `id` | `data-ref="…"` (HTML `id` 는 props.id 로 감) |
| `if`/`condition` | `data-if="{{…}}"` (불리언은 `:data-if="false"`) |
| `iteration` | `data-each="item, i in {{source}}"` |
| `conditions` | `<all-of>/<any-of>` |
| `slot`(+`slotOrder`) | `data-slot="…"` (+`data-slot-order`) |
| `dataKey`(+`trackChanges`+`debounce`) | `data-key`/`data-track-changes`/`data-debounce` |
| `blur_until_loaded` | `data-blur-until-loaded` 또는 `<blur-until-loaded>` |
| `classMap` | `<class-map key base default><variant when>…</class-map>` |
| `isolatedState`(+`isolatedScopeId`) | `<isolated-state scope-id="…">` |
| `responsive` | `<responsive on="portable">…</responsive>` (bp: portable/mobile/tablet/desktop) |
| `lifecycle` | `<on event="mount">`/`<on event="unmount">` |
| `onComponentEvent` | `<on listen="…">` |
| `actions` | `<on event="click">…</on>` — §6 |
| `sortable`(+`itemTemplate`) | `<sortable source="…"><item-template>노드1개</item-template></sortable>` |
| `component_layout` | `<template slot="item">…</template>` |
| `default`/`callbacks`(extension_point) | `<default>…</default>`/`<callback name="…">` |
| `partial`(노드 전체 치환) | `<include src="…"/>` |
| `comment` | `<!-- … -->` |

> `data-if`/`data-each`/`data-key` 등 지시 키를 **무접두 속성**(`if=`·`each=`·`condition=`·`iteration=`)으로 쓰면 `foreign_idiom`/`node_key_as_attribute` 에러가 정확한 형태를 제안한다. AI·인간 저작 실측(S8)에서 자연 성향이 무접두 `if=` 였으나 이 에러가 1스텝 교정한다.

---

## 6. 임베딩 (DataGrid·Modal 서브트리)

| JSON 위치 | `.g7.html` |
|---|---|
| `props.columns[]`(+`cellChildren`) | `<column field="name" header="이름"><cell>…</cell></column>` |
| `props.expandChildren` | `<expand>…</expand>` |
| `props.subRowChildren`(+`subRowCondition`) | `<sub-row condition="{{…}}">…</sub-row>` |
| `props.footerCells[]` | `<footer-cell field="…">…</footer-cell>` |
| `props.footerCardChildren` | `<footer-card>…</footer-card>` |
| `props.cardColumns[0].cellChildren`(CardGrid) | `<card>…</card>` (그리드 부모일 때만; 그 밖은 Card 컴포넌트) |
| `props.titlePrefix`/`titleSuffix`(Modal) | `<title-prefix>…</title-prefix>`/`<title-suffix>` |
| **`props.footer`(Modal, S8)** | **`<footer>…</footer>`** (Modal 부모일 때만; 그 밖은 Footer 컴포넌트) |
| `$switch` 값 props | `<switch-value prop="…" on="…"><case when>…<default></switch-value>` |
| Select 정적 옵션 | `<select><option value="…" label="…"/></select>` (multiple/size 없을 때만 리프트) |

### Modal footer (S8 신설)

```html
<modal title="재고 조정">
  <div><!-- 본문 --></div>
  <footer>
    <button type="button" text="$t:common.cancel"><on event="click"><close-modal target="…"/></on></button>
    <button variant="primary" text="$t:common.save"><on event="click">…</on></button>
  </footer>
</modal>
```

`<footer>` 는 Modal 자식일 때만 `props.footer` 로 hoist 된다(`Footer` 컴포넌트와 이름이 겹쳐 부모 문맥이 가른다 — `<card>` 와 동일). Modal 밖의 `<footer>` 는 `Footer` 컴포넌트로 해석.

---

## 7. 상태 초기화 (`<field>`/`<from-source>`)

리프 타입은 `:attr` 규칙을 그대로 쓴다 — `:value` 는 JSON 타입(`0`·`[]`·`null`·객체), `value` 는 문자열.

```html
<init-local>
  <field name="keyword" value=""/>            <!-- 문자열 default -->
  <field name="page" :value="1"/>             <!-- JSON 타입 default -->
  <field name="filters" :value="[]"/>
  <from-source key="user" path="me.data"/>    <!-- 데이터소스 값 매핑 -->
</init-local>
```

`<field>` 는 `:value`/`value` 속성(또는 중첩 `<field>` 트리)을 필요로 한다 — 본문에 표현식을 넣는 형태(`<field name="x">{{…}}</field>`)는 지원하지 않는다(`missing_required_attribute`). 문자열 표현식 default 는 `value="{{…}}"`.

---

## 8. 액션

top-level 속성/자식: `handler`(태그명) `event`(`<on>`) `target` `if`(`data-if`) `confirm="메시지"`(진입점만) `key` `auth_mode`/`auth_required` `identity_target` `resultTo` `debounce` `actionRef` `render` `onLoad`. **그 외 전부 `params`**(자식 `<body>`/`<query>` 또는 속성).

| 개념 | `.g7.html` |
|---|---|
| 이벤트 바인딩 | `<on event="click">…</on>` |
| apiCall 요청 본문/쿼리 | `<body name="{{…}}"/>` / `<query page="1"/>` |
| switch | `<switch value="{{$args[0]}}"><case when="edit">…</case><default>…</default></switch>` |
| conditions | `<conditions><when if="{{…}}">…</when><else>…</else></conditions>` |
| sequence/parallel | `<sequence>…</sequence>` / `<parallel>…</parallel>` |
| 성공/실패 | `<success>…</success>` / `<fail>…</fail>` |

### 핸들러 태그 (kebab) + 대표 params

| 핸들러 | 태그 | 대표 params (속성) |
|---|---|---|
| navigate | `<navigate>` | `path` `query`(자식 `<query>`) `:replace` `:mergeQuery` |
| apiCall | `<api-call>` | `target`(=요청 URL) `method` 본문 `<body>` 쿼리 `<query>` |
| setState | `<set-state>` | `target`(_local/_global) + 점표기 키맵 |
| openModal/closeModal | `<open-modal>`/`<close-modal>` | `target`(modal id) |
| toast | `<toast>` | `message` `type`(success/error/…) `duration` |
| refetchDataSource | `<refetch-data-source>` | `dataSourceId` |
| setLocale | `<set-locale>` | `locale` |

> `<api-call>` 의 `target` 이 곧 요청 URL(엔드포인트)이다. `method` 는 params 로 전달. 핸들러 44종은 kebab↔camel 자동 변환(`<set-state>`↔setState). 확장/점 핸들러는 `<action handler="sirsoft-ecommerce.toggleArrayValue">`.

```html
<on event="click">
  <api-call target="/api/admin/users" method="POST" confirm="$t:admin.users.confirm_save">
    <body name="{{_local.name}}" email="{{_local.email}}"/>
    <success><toast message="$t:common.saved" type="success"/></success>
    <fail><toast message="{{error.message}}" type="error"/></fail>
  </api-call>
</on>
```

---

## 9. 확장 주입 (`<extension>`)

```html
<!-- target_layout 형: 기존 레이아웃에 끼워넣기 -->
<extension target-layout="admin_user_list" :priority="50">
  <init>                                <!-- 주입 시 실행할 초기 액션 (S8 A1) -->
    <set-state target="_local" currency="{{…}}"/>
  </init>
  <inject target-id="header" position="append_child">
    <div>…</div>
  </inject>
</extension>

<!-- extension_point 형: 지정 확장 지점에 컴포넌트 공급 -->
<extension extension-point="dashboard.widgets" mode="append" :priority="10">
  <div>…</div>
</extension>
```

부수 섹션(`<data-source>`/`<script>`/`<modal>`)과 `<init>` 은 두 형태 모두 허용.

---

## 10. 외래 관용구 거부

`v-if`/`v-for`/`x-if`/`@click`/`v-on:*`/무접두 `if`·`each`·`ref` → `foreign_idiom`(정확한 대안 제시). 노드 키의 bare 속성(`condition`/`iteration`/`comment`/`classMap`/`dataKey`…) → `node_key_as_attribute`. `is` 속성 거부. `if` 혼합형(`{{x}} === 'y'`) → `mixed_if_expression`(전체를 `{{…}}` 한 쌍으로).

---

## 명명 3순위 규칙 (동결 근거)

1. **개념어 보존** — G7 도메인 개념어를 태그·속성명으로 그대로(partial·slot·extends·modal·data-source·computed·permissions·responsive·class-map·isolated-state·item-template·default·callback).
2. **HTML 자연스러움 충돌 시 짧은 표준풍 태그 + 대응 명시** — `<cell>`↔cellChildren·`<on event>`↔actions·`<success>`↔onSuccess·`<all-of>`↔and.
3. **결함 키는 살리지 않는다** — 엔진 미소비 사어·오기(`data_binding`·`iterator`·`bindings`·`html`·`name_attr`)는 태그 승격 금지(결함 인벤토리 대상).

W3C 표준 요소명이 G7 개념과 일치하면 표준명 우선(`<option>`·`<script>`·`<template>`).
