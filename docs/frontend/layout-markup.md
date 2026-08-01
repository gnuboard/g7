# 레이아웃 마크업 (`.g7.html`) 저작 포맷

## TL;DR (5초 요약)

```text
1. 런타임 SSoT 는 JSON 레이아웃 그대로 — `.g7.html` 은 저작 SSoT 이고 설치 시 서버(PHP)가 설치 시점에 메모리로 JSON 컴파일해 DB 등록(디스크 산출 JSON 미기록)
2. 문법 3원칙: 접두어 없음(지시 속성만 data-*) / 소문자·kebab 태그 / 속성값에 JSON 금지(구조체=자식 태그, 배열=반복 태그)
3. 태그명이 컴포넌트를 정한다(레지스트리 kebab 해석). 조건표시=data-if, 반복=data-each, 참조=data-ref (무접두 if/each/ref·v-if 는 컴파일 에러)
4. 용어(D38): compile=JSON→HTML(저작 소스 산출) / decompile=HTML→JSON(설치·검증). 컴파일러: app/Support/LayoutMarkup/* — 토큰→트리→문서(강검증→lint)
5. 문서루트 4갈래(layout/partial/extension/override). 태그·속성명은 명명 3순위 규칙으로 확정(S8 동결, 2026-07-18)
6. 주석 `<!-- -->` 은 바로 다음 태그의 `comment` 가 된다 — 산출에 자리가 없는 태그(`<on>`·값/구조 태그·섹션 컨테이너) 앞에 쓰면 컴파일 에러(§10.5)
```

> 문법 동결: 2026-07-18 (계획 D35, PO 승인). 이 문서가 태그·속성명 SSoT. 기존 JSON 스키마는 [layout-json.md](layout-json.md)(런타임 계약) — 산출 JSON 키는 불변이다.

## 위치

- 저작 파일: `.g7.html` (본 레이아웃·partial·확장 주입·오버라이드) — 저작 SSoT
- 컴파일러: `app/Support/LayoutMarkup/`
- 설치(D38·D39): 디스크에는 `.g7.html`(기본) + 원본 `.json`(선택 설치원) 병존. 설치·갱신은 `.g7.html` 을 **설치 시점에 메모리로 컴파일**해 DB(template_layouts)에 등록하고 **산출 JSON 을 디스크에 쓰지 않는다**. `.g7.html` 이 없으면 `.json` 을 직접 등록. 둘이 나란히 있으면 저작 SSoT 인 `.g7.html` 이 이긴다
- 검증 커맨드: `php artisan layout:decompile {path|--all}` (HTML→JSON 강검증·lint, 산출 JSON 미기록 — `--out` 지정 시 단일 파일 진단 덤프) / `--template={id}`
- 이관(생성) 커맨드: `php artisan layout:compile {path|--all}` (JSON→HTML 저작 소스 산출) / `--roundtrip` (왕복 동등성 검증)
- 코퍼스(왕복 하네스·`<json>` 예산·이관 게이트의 모집단): 본 레이아웃(`layouts/`) **+ 레이아웃 확장 fragment(`extensions/`)**. 정의는 `LayoutCorpus` 가 SSoT — 스캔 글롭을 곳곳에 복제하면 한쪽만 넓힐 때 나머지가 반쪽 코퍼스로 남고 그 위에서 "escape-0" 같은 완료 판정이 나온다
- 강검증 축(`LayoutValidator`): ① 구조(레이아웃/fragment) ② 엔드포인트 화이트리스트 ③ 컴포넌트 존재 ④ `wait_for` cross-field ⑤ `<script src>` 호스트 화이트리스트. **외부 URL·위험 스킴(`NoExternalUrls`)은 돌리지 않는다** — 설치 경로(`ValidatesLayoutFiles`)가 원래 돌리지 않아 `.json` 설치와 패리티를 맞춘 것이고(PO 확정 07-20), 번들 결제 플러그인이 PG사 공식 주소를 정상 기능으로 들고 있다. 저작 API 경로(`Store/Update LayoutRequest`·`UpdateLayout{Content,ExtensionContent}Request`)에는 규칙이 그대로 살아 있다
- overlay fragment 는 `injections` 없이도 성립한다 — `modals`·`data_sources`·`scripts`·`init_actions` 중 **하나 이상**을 기여하면 유효(런타임 병합 목록과 같은 판정). 아무것도 기여하지 않을 때만 거부
- 설치 소스 강제: `{module|plugin|template}:{install|update|refresh-layout} --layout-source=json` — 컴파일러 회귀가 의심될 때 짝 `.json` 이 있는 레이아웃을 원본 JSON 기준으로 되돌려 설치하는 진단·복구 탈출구(기본 `auto`). 짝 `.json` 이 없는 마크업 전용 레이아웃은 대안이 없으므로 그대로 컴파일하고 로그에 미이행을 남긴다(조용히 누락시키면 등록이 사라져 빈 화면). 환경 기본값은 `LAYOUT_INSTALL_SOURCE`, SSoT 는 `config/template.php` 의 `layout_markup.install_source`. `{type}:update` 의 기존 `--source=auto|bundled|github`(업데이트 패키지 출처)와는 별개 옵션이다

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

`<br>` `<hr>` `<area>` `<base>` `<col>` `<embed>` `<link>` `<param>` `<source>` `<track>` `<wbr>` (11종 — 표준 14종 − `<meta>`·`<img>`·`<input>`). `<meta>` 는 `<seo>` 를 담는 컨테이너다. **`<img>`·`<input>` 은 void 에서 뺐다(D36)** — Img·Input 은 `<on>` 액션(클릭 이미지·onChange 폼필드)을 실을 수 있어야 하므로 자식을 갖는다. 빈 경우는 자가닫힘(`<input type="text"/>`), 액션이 있으면 열고 닫아 담는다(`<input type="text"><on type="change">…</on></input>`). void 요소에 종료 태그를 쓰면 `void_end_tag` 에러.

---

## 2. 문서 루트 (4갈래)

| JSON | `.g7.html` | 근거 |
|---|---|---|
| 본 레이아웃 | `<layout name="…" version="1.0.0" extends="_admin_base">` | 개념어 |
| partial (루트=노드 하나, `meta.is_partial:true`) | `<partial name="…">…</partial>` | 개념어(React Fragment 는 `<fragment>`) |
| 확장 주입 (`target_layout`/`extension_point`) | `<extension>` | 개념어 |
| 템플릿→확장 레이아웃 대체 (`override_target`) | `<override>` | 어휘 계승 |

> `<partial name>` 은 `<layout name>` 과 같은 규율 — 최상위 `layout_name` 으로 접히고 **meta 로 흘리지 않는다**. `name` 을 뺀 나머지 `<partial>` 속성은 `meta` 로 접히며, `is_partial: true` 는 컴파일러가 항상 주입한다(저작 불요). partial 의 루트는 노드 **하나**이고, 모달 조각은 그 루트 노드에 `<data-source>` 자식을 얹어 오픈 시 fetch 를 선언할 수 있다.

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
| `modals` | `<modal src="partials/_modal_delete.json"/>` 또는 인라인 `<modal>노드</modal>` — `src` 는 **참조 경로**이고 실제 소스는 해석기가 고른다: 짝 `.g7.html` 저작본이 있으면 그것이 이기고, 없을 때만 `.json` 을 읽는다(`--layout-source=json` 이면 역전). `.g7.html` 을 직접 가리켜도 동작한다. 코퍼스 관례는 `.json` 표기 유지 — `<modal src>` 213 · `<include src>` 272 전량 |

### permissions — 평면 배열 + 조건형 (S8 A2)

평면 AND (전부 필요):

```html
<permissions>
  <permission>users.read</permission>
  <permission>users.write</permission>
</permissions>
```

조건형 OR (`{"or": ["core.users.create","core.users.update"]}`):

```html
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

> **구조값은 통짜 JSON 으로 적지 않는다 (D42).** `<params>`·`<headers>`·`<data>`·`<fallback>` 과 `<prop>`·`<props>`, 액션의 구조값 파라미터는 값 형태에 맞는 1급 표기를 쓴다 — 평면 스칼라 맵은 **속성**(`<params page="{{query.page}}" :per_page="20"/>`), 중첩 맵은 **`<field>` 트리**(`<fallback><field name="data"><field name="items" :value="[]"/></field></fallback>`), 평면 객체의 리스트는 **`<item>`**(`<prop name="rowActions"><item id="view" label="$t:…" iconName="eye"/></prop>`), 문자열의 리스트는 **`<item>` 본문**(`<field name="slug"><item>$t:…required</item></field>`), 확장 주입의 병합 지시자는 **`<append>`**(`<props><prop name="tabs"><append><item id="ext_x" …/></append></prop></props>`). JSON 본문 표기도 계속 컴파일되지만(하위호환) 역변환은 위 표기로만 되돌린다. 빈 컬렉션(`[]`·`{}`)은 구조가 없는 값 리터럴이라 `:attr="[]"` 그대로 쓴다.
>
> `<field>`/`<item>` 은 **어느 깊이에서도 같은 규칙**이다(`StructuredValueCompiler` 단일 SSoT). `<item>` 의 세 형태는 서로 배타적이라 모호하지 않다 — 자식이 있으면 중첩 구조, 속성이 있으면 평면 객체, 본문 텍스트만 있으면 문자열이다. 문자열 아닌 스칼라 원소(`[1, true]`)에는 표기를 주지 않는다: `<item :value="1"/>` 은 객체 `{"value": 1}` 과 구별되지 않아 왕복이 깨진다.

> **확장 주입 `<props>` 의 DataGrid 컬럼 트리 (D42 G7).** 주입 fragment 에는 부모 컴포넌트가 없어(대상 노드가 다른 레이아웃에 있다) `<column>` 의 소유자를 DataGrid 로 확인할 수단이 없다. 그 자리에서는 **`<prop name="columns">` 컨테이너가 소유자를 대신**하고, 안에서 노드 자리와 똑같은 `<column>`/`<cell>` 문법을 쓴다.
>
> ```html
> <inject target-id="order_datagrid" position="inject_props">
>   <props>
>     <prop name="columns">
>       <column field="no" header="NO" width="60px" :sortable="false"><cell><span>{{row.no}}</span></cell></column>
>     </prop>
>   </props>
> </inject>
> ```
>
> 게이트는 이 컨테이너 안으로만 열린다 — 노드 자리의 `<prop name="columns">` 에 `<column>` 을 두면 종전대로 거부된다(`unexpected_structural_child`).

> **데이터소스의 구조값 키도 `<field name>` 으로 적는다 (D42 G11).** `<data-source>` 는 `data`·`params`·`headers`·`fallback` 넷만 전용 태그를 갖고 나머지 키는 속성으로 나간다. 값이 객체·배열이면 그 속성이 통짜 JSON 이 되므로, 액션의 구조값 파라미터와 **같은 표기**로 자식에 적는다.
>
> ```html
> <data-source id="roles" endpoint="/api/admin/roles" method="GET">
>   <field name="depends_on"><item>users</item><item>menus</item></field>
>   <field name="errorCondition"><field name="if" value="{{response.data.length === 0}}"/><field name="errorCode" :value="404"/></field>
> </data-source>
> ```

> **`<define>` 본문도 구조값이면 태그 트리로 (D42 G12).** `<define>` 은 JSON 본문 블록이었으나, 표현 가능한 값은 다른 자리와 같은 `<field>`/`<item>` 으로 적는다. 표기를 주지 않은 형태(혼합 스칼라 리스트 등)만 JSON 본문으로 남는다.
>
> ```html
> <defines>
>   <define name="countryList">
>     <item code="KR" name="$t:countries.KR"/>
>     <item code="US" name="$t:countries.US"/>
>   </define>
> </defines>
> ```

> **`<seo>` 의 구조값 자식도 같은 표기다 (D51 G14).** `og`·`vars`·`extensions`·`structured_data`·`data_sources` 는 `<seo>` 속성으로 펼 수 없어 자식 태그로 나가는데, 그 본문 표기는 다른 구조값 자리와 같다 — 평면 스칼라 맵은 속성 field-map, 그 밖은 `<field>`/`<item>` 트리다. JSON 본문 표기도 계속 받는다(표기를 주지 않은 형태의 탈출구).
>
> ```html
> <meta title="{{page.data.title}}">
>   <seo :enabled="true" page_type="board" :priority="0.6">
>     <og type="website" title="{{_seo.board.title ?? ''}}"/>
>     <vars site_name="$core_settings:general.site_name" board_name="{{posts.data.board.name ?? ''}}"/>
>     <extensions><item type="module" id="sirsoft-board"/></extensions>
>     <data-sources><item>posts</item></data-sources>
>     <structured-data><field name="@type" value="ItemList"/></structured-data>
>   </seo>
> </meta>
> ```

> **액션의 top-level 구조값 키도 `<field name>` 이다 (D51 G15).** `resultTo`·`identity_target` 은 값이 객체인데 배치가 top-level 이라 종전에는 `:resultTo='{…}'` 속성 JSON 으로 나갔다. 표기는 params 구조값과 같은 `<field name>` 하나이고, **배치는 핸들러 매니페스트가 정한다** — 매니페스트가 top-level 로 아는 이름이면 top-level, 아니면 params 다(속성 배치 규칙과 같은 한 곳만 본다).
>
> ```html
> <load-from-local-storage key="recentProductIds" :defaultValue="[]">
>   <field name="resultTo"><field name="target" value="_global"/><field name="key" value="recentProductIds"/></field>
> </load-from-local-storage>
> ```

> **`<blur-until-loaded>` 의 데이터소스 목록은 `<item>` 이다 (D51 G16).** 하나면 `sources="a"`, 여럿이면 `<item>` 리스트로 쓴다. `:sources` 는 표기를 주지 않은 형태(빈 리스트·표현식 배열)의 탈출구로만 남는다.
>
> ```html
> <blur-until-loaded><item>myPosts</item><item>myComments</item></blur-until-loaded>
> ```

> **구조값 리스트는 어느 자리에서든 `<item>` 으로 나간다 (D51 G17).** 종전 방출기는 맵만 `<field>` 트리로 풀고 리스트는 무조건 JSON 본문으로 냈다 — 표기가 있는데도 `<data-sources>["posts"]</data-sources>` 처럼 통짜 JSON 이 남던 비대칭이다. 표기 불가한 리스트(빈 리스트·문자열 아닌 스칼라 원소)만 JSON 본문으로 간다.

> **상태 트리의 `<field>` 도 `<item>` 자식을 받는다 (D53 G18).** `<init-local>`/`<init-global>`/`<isolated-state>` 안의 `<field>` 는 종전에 `<field>` 자식만 받아, 문자열 리스트가 `:value='["a","b"]'` 통짜 JSON 으로 남았다. 같은 `<field>` 태그가 자리마다 다른 능력을 갖지 않도록 상태 트리도 구조값 SSoT(`StructuredValueCompiler`)를 그대로 탄다 — `<item>` 자식이 있으면 리스트, `<field>` 자식이면 매핑이다. 표기 불가 형태(빈 문자열 원소·문자열 아닌 스칼라 원소·빈 리스트)만 `:value` 리터럴로 남는다.
>
> ```html
> <init-local>
>   <field name="visibleColumns">
>     <item>order_no</item>
>     <item>buyer</item>
>   </field>
>   <field name="selectedCategories" :value="[null,null,null,null]"/>
> </init-local>
> ```

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
| `partial`(노드 전체 치환) | `<include src="…"/>` — `src` 는 **참조 경로**이고 실제 소스는 해석기가 고른다(`<modal src>` 와 같은 규약, §3 표 `modals` 행 참조). 짝 `.g7.html` 저작본이 있으면 그것이 이기고, 없을 때만 `.json` 을 읽는다 |
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
| `props.cardColumns[0].cellChildren`(CardGrid) | `<card>…</card>` (그리드 부모일 때만; 그 밖은 Card 컴포넌트 — 셋 다 admin 전용) |
| `props.titlePrefix`/`titleSuffix`(Modal) | `<title-prefix>…</title-prefix>`/`<title-suffix>` (⚠️ 템플릿 비대칭 — 아래 참조) |
| **`props.footer`(Modal, S8)** | **`<footer>…</footer>`** (Modal 부모일 때만; 그 밖은 Footer 컴포넌트 — **`Footer` 는 `sirsoft-basic` 전용**이고 admin 은 `AdminFooter` 다. ⚠️ 템플릿 비대칭 — 아래 참조) |
| `$switch` 값 props | `<switch-value prop="…" on="…"><case when>…<default></switch-value>` |
| Select 정적 옵션 | `<select><option value="…" label="…"/></select>` (multiple/size 없을 때만 리프트) |

### Modal footer (S8 신설)

```html
<modal title="재고 조정">
  <div text="{{_local.stockMessage}}"/>
  <footer>
    <button type="button" text="$t:common.cancel"><on event="click"><close-modal target="…"/></on></button>
    <button variant="primary" text="$t:common.save"><on event="click">…</on></button>
  </footer>
</modal>
```

`<footer>` 는 Modal 자식일 때만 `props.footer` 로 hoist 된다(`Footer` 컴포넌트와 이름이 겹쳐 부모 문맥이 가른다 — `<card>` 와 동일). Modal 밖의 `<footer>` 는 `Footer` 컴포넌트로 해석.

> **이 세 표기는 문법이지 보편 표면이 아니다 — 템플릿을 확인하고 쓴다.** `<footer>`·`<title-prefix>`·`<title-suffix>` 는 **컴파일러가 어느 템플릿에서든 `props.footer`/`titlePrefix`/`titleSuffix` 로 hoist** 하지만, 그 props 를 **렌더하는 `Modal` 은 `sirsoft-admin_basic` 뿐**이다. `sirsoft-basic` 의 [Modal.tsx](../../templates/_bundled/sirsoft-basic/src/components/composite/Modal.tsx) 는 세 prop 을 구조분해하지 않고 rest spread 도 없어 **컴파일·렌더 모두 통과한 뒤 버튼이 조용히 사라진다**. 사용자 템플릿 모달의 확인/취소 버튼은 `children` 안에 둔다. 컴포넌트 측 계약은 [component-props.md §Modal](component-props.md#modal-컴포넌트) · [modal-usage.md §적용 범위](modal-usage.md) 참조.
>
> **너비는 `<modal size="…">` 로 고른다** — `sm`/`small` 400px · `md`/`medium` 500px · `lg`/`large` 720px · `xl` 900px · `2xl` 1120px. `width` 를 함께 주면 `width` 가 이기고, 모르는 값은 기본 500px 으로 떨어진다. 좁은 화면 넘침은 컨테이너의 `max-w-[calc(100vw-2rem)]` 이 막는다.

---

## 7. 상태 초기화 (`<field>`/`<from-source>`)

리프 타입은 `:attr` 규칙을 그대로 쓴다 — `:value` 는 JSON 타입(`0`·`[]`·`null`·객체), `value` 는 문자열.

아래에서 `keyword` 는 문자열 default(`value`), `page`·`filters` 는 JSON 타입 default(`:value`)다.

```html
<init-local>
  <field name="keyword" value=""/>
  <field name="page" :value="1"/>
  <field name="filters" :value="[]"/>
</init-local>
```

`<field>` 는 `:value`/`value` 속성(또는 중첩 `<field>` 트리)을 필요로 한다 — 본문에 표현식을 넣는 형태(`<field name="x">{{…}}</field>`)는 지원하지 않는다(`missing_required_attribute`). 문자열 표현식 default 는 `value="{{…}}"`.

### 응답 값으로 상태를 채우는 것은 데이터소스 쪽에 적는다

문서 루트 `<init-local>` 은 **정적 기본값**이다. 응답에서 값을 끌어오는 것은 데이터소스의 몫이라 `<data-source>` 안에 적으며, 코퍼스에 있는 형태는 아래 둘뿐이다.

```html
<!-- 전체 응답을 통째로 담는다 (산출: "initLocal": "form") -->
<data-source id="role" endpoint="/api/admin/roles/{{route?.id}}" method="GET" :auto_fetch="true" initLocal="form"/>
```

```html
<!-- 응답의 특정 경로만 골라 담는다 (산출: "initLocal": {"tokenValid": "{{data.valid ?? false}}"}) -->
<data-source id="tokenValidation" endpoint="/api/auth/validate-reset-token" method="POST" :auto_fetch="true">
  <init-local>
    <field name="tokenValid" value="{{data.valid ?? false}}"/>
  </init-local>
</data-source>
```

> `<from-source key path>` 는 엔진이 스스로 **레거시**라 부르는 `{key, path}` 형태를 겨냥한 태그다. `<init-local key="…">` 로 묶어야만 컴파일되는데 그때 산출이 `{묶은키: {key, path}}` 라, 엔진의 레거시 분기(`initLocal.key` 존재 여부로 판정)에 걸리지 않고 맵 형태로 읽힌다. 코퍼스 사용 0건이며 위 두 형태로 갈음한다.

---

## 8. 액션

top-level 속성/자식: `handler`(태그명) `event`(`<on>`) `target` `if`(`data-if`) `confirm="메시지"`(진입점만) `auth_mode`/`auth_required` `identity_target` `resultTo` `debounce` `actionRef` `render` `onLoad` `stopPropagation`(진입점만). **그 외 전부 `params`**(자식 `<body>`/`<query>` 또는 속성).

> `:stopPropagation="true"` 는 engine-v1.55.0 에서 신설된 **액션 키**다(불리언이므로 타입 리터럴 `:attr` 로 적는다)(핸들러가 아니다 — `stopPropagation` 이라는 핸들러는 등록된 적이 없다). `true` 일 때만 진입점 액션에서 `event.stopPropagation()` 을 부르는 opt-in 이라, 목록 행 안의 토글처럼 부모 클릭까지 발화되던 자리에 쓴다. `change` 이벤트에서 `preventDefault` 는 부르지 않는다(토글의 네이티브 상태 변경이 취소되면 React 상태와 어긋난다). 배치는 {@see \App\Support\LayoutMarkup\ActionHandlerManifest} 의 `UNIVERSAL_TOP_LEVEL_KEYS` 가 SSoT 다.
>
> **같은 이름의 컴포넌트 prop 과 혼동하지 않는다.** `sirsoft-basic` 의 `UserInfo` 는 자체 prop `stopPropagation` 을 갖는다(구현 [UserInfo.tsx](../../templates/_bundled/sirsoft-basic/src/components/composite/UserInfo.tsx) `:172`·`:355`, 저작 4파일). **자리가 둘을 가른다** — 노드 태그 속성(`<user-info :stopPropagation="true"/>`)이면 컴포넌트 prop, `<on>` 아래 핸들러 태그 속성이면 액션 키다.

> `key` 는 액션 top-level 이 **아니다** — `<on event="keydown" key="Enter">` 처럼 **`<on>` 의 트리거 한정자**로만 쓴다. 액션 속성으로 두면 `saveToLocalStorage`/`loadFromLocalStorage` 처럼 `params.key` 를 인자로 받는 핸들러에서 그 인자가 트리거 키로 오해석돼 **조용히 소실된다**(실제로 32건이 이 충돌로 깨져 있었다).

| 개념 | `.g7.html` |
|---|---|
| 이벤트 바인딩 | `<on event="click">…</on>` · 키 한정 `<on event="keydown" key="Enter">…</on>` |
| 구조값 파라미터 | `<field name="filter"><field name="searchType" value="email"/></field>` (맵·중첩 가능) · `<field name="rows"><item id="a" label="…"/></field>` (객체 리스트) · `<field name="slug"><item>$t:…</item></field>` (문자열 리스트) |
| named_actions 참조 | `<action-ref name="saveDraft"/>` |
| apiCall 요청 본문/쿼리 | `<body name="{{…}}"/>` / `<query page="1"/>` |
| switch | `<switch on="{{$args[0]}}"><case when="edit">…</case><default>…</default></switch>` — 분기 대상은 **`on`**(=`params.value`, 엔진이 읽는 자리). `value` 속성은 top-level `value` 로 가는데 엔진이 읽지 않아 `switch_value_not_consumed` lint 경고 대상이다(이관 보존용 표기) |
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
>
> **`<action-ref name>` 은 핸들러 없이 성립하는 유일한 액션이다.** `named_actions` 에 정의해 둔 액션을 이름으로 불러 쓰는 형태(`actionRef`)로, `<action>` 은 `handler` 가 필수라 이 형태를 지을 수 없다. 같은 액션을 여러 진입점에서 재사용하거나, `init_actions` 루트처럼 키 화이트리스트가 좁은 자리에서 복잡한 액션을 우회 실행할 때 쓴다.
>
> **조건 분기의 실행 블록도 같은 블록 규칙이다 (G9).** `<conditions>` 의 `<when if>`/`<else>` 본문은 `<case>`/`<default>` 와 같다 — 액션이 하나면 `then` 이 단일 객체, 둘 이상이면 배열이 된다. 엔진은 두 형태를 모두 받으므로(배열은 sequence 처럼 순차 실행) 저작에서는 자식 수만 신경 쓰면 된다.
>
> ```html
> <on event="change">
>   <conditions>
>     <when if="{{$event.target.checked}}"><set-state target="global" flag/></when>
>     <else><set-state target="global" :flag="false"/></else>
>   </conditions>
> </on>
> ```
>
> **주기 실행 액션 블록 `<actions>` (G10).** `startInterval` 처럼 `params.actions` 를 실행 목록으로 읽는 핸들러는 그 목록을 `<actions>` 자식 블록으로 적는다. 제어 태그(`<sequence>`·`<parallel>`)는 top-level `actions` 가 정본이라 이 블록을 쓰지 않는다.
>
> ```html
> <start-interval id="ticker" :intervalMs="1000">
>   <actions>
>     <set-state target="local" remainingSeconds="{{_local.remainingSeconds - 1}}"/>
>   </actions>
> </start-interval>
> ```
>
> **블록 본문의 암묵 sequence 는 자식이 둘 이상일 때만 펼친다.** `<on>`·`<success>`·`<fail>` 같은 블록 본문에 액션을 여러 개 나열하면 컴파일 시 `sequence` 로 묶이고, 되돌릴 때 `<sequence>` 태그 없이 형제 태그로 펼쳐진다. 그러나 자식이 **하나뿐인** sequence 는 `<sequence>…</sequence>` 래퍼를 명시해야 한다 — 컴파일러는 블록 자식이 하나면 sequence 로 감싸지 않고 그 액션에 트리거만 얹으므로, 단일 자식을 펼치면 되컴파일 때 sequence 의미가 사라져 왕복이 깨진다. 즉 "액션 2개 이상 = 래퍼 생략, 액션 1개인데 sequence 를 의도 = `<sequence>` 명시".

```html
<on event="click">
  <api-call target="/api/admin/users" method="POST" confirm="$t:admin.users.confirm_save">
    <body name="{{_local.name}}" email="{{_local.email}}"/>
    <success>
      <toast message="$t:common.saved" type="success"/>
    </success>
    <fail>
      <toast message="{{error.message}}" type="error"/>
    </fail>
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

## 10.5 주석 (`<!-- … -->`) — 무엇을 어디에 쓰는가 (S21)

주석은 **바로 다음 태그의 설명**이 되어 그 태그가 만드는 JSON 객체의 `comment` 키로 실린다. 서빙 시
strip 되고 편집 모드에서만 보존되므로 런타임 비용은 없다.

### 다는 자리 — 산출에 `comment` 자리가 있는 태그만

| 앵커 | 다는 자리 | 산출 키 |
|---|---|---|
| 파일 헤더 | 문서 봉투(`<layout>`·`<partial>`·`<extension>`·`<override>`) **앞** | 문서 루트 `comment` |
| 데이터소스 | `<data-source>` 앞 | `data_sources[].comment` |
| 모달 — 참조 | `<modal src="…"/>` 앞 | `modals[].comment` |
| 모달 — 인라인 | 래퍼 `<modal>` 앞(안쪽 노드로 올라감) 또는 안쪽 노드 앞 | `modals[].comment` |
| 명명 액션 | 봉투 직속 `<action name="…">` 앞 | `named_actions.{이름}.comment` |
| 노드 | 그 컴포넌트 태그 앞 | 노드 `comment` |
| 액션 블록 — 자식 1개 | `<on>` **안** 첫 액션 앞 | `actions[].comment` |
| 액션 블록 — 자식 2개 이상 | `<on>` 안 명시 `<sequence>` 앞 | `actions[].comment`(+`handler:sequence`) |

`<on>` **앞**에는 달지 않는다 — 트리거 태그는 산출 JSON 에서 액션 객체의 키(`type`/`event`)로 접히므로
그 자신을 위한 주석 자리가 없다. 같은 이유로 값·구조를 담는 태그(`<field>`·`<item>`·`<prop>`·`<column>`·
`<case>`·`<when>`·`<query>`·`<body>` …)와 섹션 컨테이너(`<permissions>`·`<global-headers>`·`<init-local>`·
`<defines>` …)에도 자리가 없다.

```html
<button data-ref="save_btn">
  <on type="click">
    <!-- 저장 후 목록으로 — 실패는 폼 아래 오류 영역에서 처리한다 -->
    <api-call target="/api/admin/users" method="POST"/>
  </on>
</button>
```

액션이 둘 이상이면 `<sequence>` 로 감싸고 그 앞에 쓴다(암묵 sequence 를 명시로 바꾸는 것뿐이라 산출
JSON 은 `comment` 키만 늘어난다).

### 자리가 없는 곳에 쓰면 컴파일 에러다

`comment_not_attachable` 로 거부하며 정본 자리를 함께 안내한다. 판정은 태그 목록이 아니라 **그 컴파일이
실제로 낸 산출과의 대조**다 — 같은 태그라도 자리에 따라 갈리기 때문이다(Modal 자식 `<footer>` 는
`props.footer` 로 접혀 자리가 없고, 같은 이름의 `Footer` 컴포넌트 노드에는 있다).

이 검사가 없던 동안 `<on>` 앞 주석은 경고도 에러도 없이 사라졌다. 왕복 게이트는 JSON→마크업→JSON 이라
**마크업에만 있던 주석의 소실을 구조적으로 볼 수 없다** — 그 사각을 `CommentAttachmentGuardTest` 가 덮는다.

### 무엇을 쓰는가

코드가 이미 말하는 것을 되풀이하지 않는다. 다음 사람이 코드만 봐서는 알 수 없는 것을 쓴다.

- **화면 맥락** — 이 화면이 무엇이고 누가 보는가(파일 헤더)
- **데이터 출처와 갱신 시점** — 어디서 무엇을 받아오고 언제 다시 받는가(`<data-source>`)
- **띄우는 조건** — 언제 뜨는 창인가(`<modal>`)
- **의도** — 이 이벤트가 무엇을 하는가, 왜 이 순서인가(액션 블록)

**제외** — 아래 셋은 주석이 코드를 되풀이하거나 담을 자리가 아예 없으므로 달지 않는다. 되풀이하는
주석이 깔리면 다음 사람이 코드도 주석도 안 읽는다. 기계 판정식을 함께 적는다 — 계량이 사람의 기억에
달려 있으면 세는 사람마다 다른 수가 나온다.

| | 제외 대상 | 기계 판정식 |
| --- | --- | --- |
| **ⓐ** | 한 줄로 자명한 단발 액션 | `<on>` 의 자식이 **1개**이고 그 자식이 **자기 자식 없이 속성 ≤1** — 인자 없는 `<navigate path>` · `<close-modal target>` · `<open-modal target>` 등 |
| **ⓑ** | 부모 노드가 이미 그 자리를 설명했을 때 | 그 노드에 주석이 있고 그 노드의 `<on>` 블록이 **정확히 1개** |
| **ⓒ** | `<partial>` 의 루트 노드가 이미 주석을 가진 파일 | 봉투 주석과 루트 노드 주석이 **같은 `comment` 키**를 다투므로 자리가 하나다 — 어느 쪽이든 차 있으면 닫힌 자리 |

ⓑ 는 블록이 둘 이상이면(예: `onChange` + `onClick`) 한 주석이 둘을 설명할 수 없으므로 적용하지 않는다.

ⓑ 의 예 — 버튼 위 주석이 곧 그 이벤트의 설명이라 안쪽에 다시 쓰지 않는다.

```html
<!-- 네이버페이 -->
<button class="btn">
  <on type="click">
    <action handler="sirsoft-ecommerce.setPaymentMethod" paymentMethod="nicepay_naverpay"/>
  </on>
</button>
```

ⓒ 의 예 — 봉투 앞에 쓰면 루트 노드 주석과 같은 키를 다툰다. 둘 중 한 자리에만 쓴다.

```html
<partial description="정책 버전 발행 모달">
  <!-- 발행 즉시 적용되고 전 회원이 재동의 대상이 된다 -->
  <modal data-ref="policy_version_publish_modal" title="버전 발행">
    <div class="space-y-3"/>
  </modal>
</partial>
```

❌ 코드를 되풀이하는 주석 — 아래는 ⓐ 에 해당해 달지 않는 자리다.

```html
<button class="btn">
  <on type="click">
    <!-- 모달을 닫는다 -->
    <close-modal target="delete_confirm_modal"/>
  </on>
</button>
```

### 문서·예시에 쓸 때

문서의 ```html 펜스도 같은 규칙을 받는다(`DocMarkupExampleCompilesTest` 가 전건 컴파일한다). 한 펜스에
여러 예시를 나란히 실을 때 각 예시를 구분하는 라벨은 **주석이 아니라 펜스 밖 산문**으로 쓴다 — 소실
자리에 주석을 다는 예시는 그 자체로 잘못된 저작법을 가르친다.

---

## 명명 3순위 규칙 (동결 근거)

1. **개념어 보존** — G7 도메인 개념어를 태그·속성명으로 그대로(partial·slot·extends·modal·data-source·computed·permissions·responsive·class-map·isolated-state·item-template·default·callback).
2. **HTML 자연스러움 충돌 시 짧은 표준풍 태그 + 대응 명시** — `<cell>`↔cellChildren·`<on event>`↔actions·`<success>`↔onSuccess·`<all-of>`↔and.
3. **결함 키는 살리지 않는다** — 엔진 미소비 사어·오기(`data_binding`·`iterator`·`bindings`·`html`·`name_attr`)는 태그 승격 금지(결함 인벤토리 대상).

W3C 표준 요소명이 G7 개념과 일치하면 표준명 우선(`<option>`·`<script>`·`<template>`).

---

## 11. 역변환 (JSON → `.g7.html`) 과 표기 탈출구 (S9)

`layout:compile {path?|--all}`(PO 정의: compile=JSON→HTML) 이 JSON 레이아웃을 짝 `.g7.html` 저작 소스로 산출한다(이관 웨이브용). 내부 구현 클래스는 `LayoutMarkupDecompiler`(JSON→요소 트리 역변환) 로, 각 최상위 키·노드 키를 첫 급부 문법으로 되돌리고 재컴파일이 원본과 **정규화 후 deep-equal** 임을 확인한다(`--roundtrip`). 왕복 하네스는 번들 전 레이아웃을 상시 검사한다(`LayoutMarkupRoundTripCorpusTest`). 이관된 `.g7.html` 은 원본 `.json` 과 100% 동등하고 `<json>` 탈출구가 0 건이어야 한다(회귀 게이트).

### 표기 탈출구 `<json>` — 100% 왕복 보장

첫 급부 문법이 표현하지 못하는 것(결함 키·비정규 shape·미표현 노드)은 `<json>` 으로 원문 그대로 실어 왕복을 보장한다. 역변환기는 노드·문서를 되컴파일해 원본과 대조하고 **어긋나는 키에만** 방출한다(정밀 탈출).

- `<json key="X">JSON</json>` — 최상위(`<layout>`/`<override>` 직속) 또는 노드 구조 자식으로 두면 그 자리의 키 `X` 를 JSON 값 그대로 설정한다(결함 키·비정규 shape 보존).
- `<json>JSON</json>`(무키) — 노드 자리에 두면 그 자식 노드 하나 전체를 JSON 그대로 설정한다(void 태그처럼 노드 자리에서 표현 불가한 경우).

방출은 **결함 인벤토리 키 또는 문법 갭**에 한정된다(정상 지원 키가 탈출구로 새면 회귀). 저작 시 `<json>` 을 직접 쓰지 않는다 — 역변환기가 이관 시에만 방출한다.

### 정규화 인벤토리 ("입력만 받고 정규화")

왕복 비교는 컴파일러가 **결정적으로 파생·생략**하는 값을 양쪽에서 지워 비교한다. 손저작 소스에 낡은 값이 남아 있어도 이관 산출은 컴파일 정본으로 수렴하며, 그 수렴을 왕복 실패로 세지 않는다:

| 정규화 대상 | 컴파일 정본 | 근거 |
| --- | --- | --- |
| 노드 `type`(basic/composite/layout) | 레지스트리(name)에서 파생 — **수렴이 아니라 정정**(아래) | name 비교는 엄격 유지 — 태그 오방출은 name 으로 잡힘 |
| `meta.is_partial` | `<partial>` 이 항상 주입 | 소스 부재해도 산출엔 존재 |
| 빈 `props`/`children`(`[]`) | 항상 생략 | `props:[]` 는 방출되지 않음 |
| 콜백 단일 원소 배열(`onSuccess:[{x}]`) | 객체(`{x}`) | 런타임 동치, compileCallbackActions 1개=객체 |
| 제어 `params.{actions,cases,conditions}` | top-level | 런타임 동치(ActionDispatcher params.actions fallback) |

정규화 목록에 **런타임 동치가 아닌 것을 넣으면 안 된다** — 넣는 순간 이관이 실제로 shape 를 바꿔놓아도 왕복 게이트가 green 이 된다. Select 옵션이 그 사례였다(아래 G13).

#### 노드 `type` 은 수렴이 아니라 정정이다

`type`(basic/composite/layout)은 레지스트리에서 파생하지만, 이것을 "무해한 수렴" 으로 읽으면 안 된다. `DynamicRenderer` 는 이 값을 **레지스트리가 아니라 레이아웃 노드에서** 읽어 세 갈래를 가른다:

1. `dynamicState`(loadingActions·apiError)를 props 로 주입 — composite 만
2. `__componentContext` 자동 전달 — composite 는 무조건
3. `editorAttrs`·`__` 접두 키의 DOM 차단 — basic·layout 만 제거

출하 JSON 실측 결과 **25,551 노드 중 345개**가 레지스트리와 어긋나 있었고, **어긋난 쪽이 결함이다**:

- `Toggle`·`Badge` 가 `basic` 으로 적혀 있었다. 두 컴포넌트는 `editorAttrs` 를 명시 수신하고 `{...props}` 를 펼치지 않으므로, basic 분기에서 `editorAttrs` 가 제거되면 **편집 표식이 DOM 에 도달하지 못한다** — 레이아웃 편집기에서 누락된다.
- 반대로 `Input` 등이 `composite` 로 적혀 있어 `dynamicState` 가 DOM 으로 샜고, `Input.tsx` 가 `const { loadingactions, formdata, ...validProps }` 로 **손수 걷어내는 방어코드**를 달고 있다.

따라서 이관이 `type` 을 레지스트리 값으로 되돌리는 것은 결함 수정이다. 저작 시 `type` 을 적지 않는다 — 태그가 곧 `name` 이고 `type` 은 거기서 나온다.

### `<options>` — Select 옵션 데이터 래퍼 (D42 G13)

Select 는 `props.options` **유무로 렌더 컴포넌트 자체가 갈린다** — 있으면 커스텀 드롭다운(버튼 + 포털), 없으면 네이티브 `<select>` + children. 둘은 런타임 동치가 아니므로 어느 쪽인지를 표기가 결정적으로 말해야 한다:

```html
<!-- 데이터 → props.options (커스텀 드롭다운) -->
<select data-ref="search_field" value="{{_local.field}}">
  <options>
    <option value="all">$t:admin.users.search_fields.all</option>
    <option value="name" disabled>$t:admin.users.search_fields.name</option>
  </options>
  <on type="change">
    <set-state field="{{$event.target.value}}"/>
  </on>
</select>
<!-- 컴포넌트 노드 → children:[Option] (네이티브 select) -->
<select name="toolbar">
  <option value="minimal">$t:x.minimal</option>
</select>
```

- `<options>` 안은 `<option>` 만 온다(다른 태그는 자리 오류). `value=`(문자열)·`:value=`(타입 리터럴)·`disabled`·본문 텍스트(label)만 읽는다.
- `<options>` 와 `:options` 는 같은 자리를 다투므로 함께 쓰면 충돌로 잡힌다.
- 래퍼 밖 `<option>` 은 **절대 접지 않는다**. `multiple`/`size` 여부와 무관하다.

종전에는 래퍼 없이 "`<select>` 직속 자식이 전부 정적 `<option>` 이면 접는다" 는 휴리스틱으로 갈랐다. 그 판정은 양방향 모두 틀렸다 — 형제 `<on>` 하나에 리프트가 통째로 불발했고(원본 `props.options` → `children`, 실측 44건), 반대로 진짜 Option 노드를 접었다(원본 `children` → `props.options`, 실측 2건). 정규화가 그 차이를 덮고 있어 왕복·설치 게이트는 46건 내내 green 이었다.

### 노드 레벨 `<permissions>`·`<meta>` (D36)

문서 최상위 섹션과 **같은 태그를 노드 안에** 두면 그 요소의 것이 된다(`<modal>`·`<slot>` 이 위치로 갈리는 것과 동일):

- **`<permissions>`** — 그 요소의 조건 렌더 권한. 평면·조건형 문법은 §3 permissions 와 동일.
- **`<meta>`** — 그 요소의 편집기 메타(editor_label 등, 평면 스칼라). `<seo>` 같은 중첩 자식은 문서 `<meta>` 전용.

```html
<div data-ref="modules_badge">
  <permissions>
    <permission>core.modules.read</permission>
  </permissions>
  …
</div>
<modal data-ref="delete_modal">
  <meta editor_label="$t:admin.modal_label.delete"/>
  …
</modal>
```

> S9 실측으로 드러난 문법 갭 3건(void `<input>`/`<img>` 액션·노드 permissions·노드 meta)은 **D36 으로 문법 신설해 해소**됐다 — 이제 첫 급부로 저작되며 `<json>` 방출 대상이 아니다.

---

## 12. 문법을 하나 추가할 때 만져야 하는 자리 (S19 ⑤)

새 최상위 키·새 지시 속성·새 핸들러 태그를 도입할 때 **어디를 함께 고쳐야 하는가**를 절차로 둔다.
이 목록이 필요한 이유는 실측이다 — 최상위 키 선언이 네 곳에 흩어져 있고, 실제로 갈려 있었다
(`comment` 가 저작 검증에는 있고 편집기 저장 화이트리스트에는 없어 저장이 조용히 떨궜다).

### 기계가 잡는 자리 (고치지 않으면 게이트가 red 다)

| 대상 | 함께 고칠 곳 | 잡는 게이트 |
| --- | --- | --- |
| **액션 핸들러** | 엔진 `ActionDispatcher`(switch 또는 `registerHandler`) + `ActionHandlerManifest::CORE_HANDLERS` | 하네스 가드 `handler-manifest-drift` — 두 선언을 토크나이즈해 대조. 한쪽만 고치면 red |
| **최상위 키** | `DocumentCompiler::KEY_ORDER` + audit `ALLOWED_ROOT_KEYS` + `UpdateLayoutContentRequest::rules()` | 하네스 가드 `root-key-ledger` — 네 벌 차집합을 등재와 대조. 미등재 신규 차이만 red |
| **저작 문법 일반** | 짝 `.json` 재생성 | `php artisan layout:decompile --all --check` (Stop 훅 상주) |
| **컴파일 계약** | 왕복 보존 | `php artisan layout:compile --all --roundtrip` |
| **규정 ↔ 검사 도구** | 규정 ↔ 룰 매핑 등재 | 매핑 검증기 — `codified` 는 룰 실재, `compiler-enforced` 는 컴파일러 에러 코드 실재를 확인 |

**새 최상위 키를 넣을 때 특히**: `UpdateLayoutContentRequest` 에 `content.<키>` 규칙이 없으면
Laravel `validated()` 가 그 키를 떨군다. 컴파일러와 저작 검증만 고치면 **파일로는 살아 있고
편집기로 저장하는 순간 사라지는** 상태가 된다 — 화면도 게이트도 조용하다.

### 사람이 판단할 자리 (기계가 대신할 수 없다)

- **이름** — 명명 3순위 규칙(§"명명 3순위 규칙")의 적용은 의미 판단이다. "이 낱말이 G7 개념어인가",
  "표준 HTML 어휘와 충돌하는가" 는 정규식으로 답이 나오지 않는다.
- **정당한 차이인가** — 네 벌이 갈리는 것 자체가 결함은 아니다. fragment 전용 키·deprecated
  별칭·DB 컬럼으로 승격된 키는 정당하게 갈린다. 그래서 게이트는 차집합을 0 으로 만들지 않고
  **사유와 함께 등재**하도록 요구한다. 사유를 적는 것이 사람의 일이다.
- **`<json>` 탈출구를 쓸 때인가** — 정식 문법으로 표현되는데 원시 JSON 으로 우회하는 것은
  부채다. 반대로 왕복 보장을 위해 탈출구가 필요한 자리도 실재한다. 왕복 게이트는 사용량을
  계량으로만 보고하고, 개별 정당성은 사람이 본다.
- **엔진이 정말 소비하는가** — 키를 선언만 하고 엔진이 읽지 않으면 그 키는 죽은 키다.
  `root-key-ledger` 는 소비처를 증거 토큰으로 확인하지만, "이 자리에서 읽는 것이 맞는가" 는
  코드를 읽어야 안다(D54).
