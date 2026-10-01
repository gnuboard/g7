# 관리자 목록 필터 컨벤션

> **관련 문서**: [layout-markup.md](layout-markup.md) | [data-binding.md](data-binding.md) | [../extension/layout-extensions.md](../extension/layout-extensions.md) | [../extension/hooks.md](../extension/hooks.md) | [../rules/list-context.md](../rules/list-context.md)

---

## TL;DR (5초 요약)

```text
1. 조건이 4개 이상인 관리자 목록은 슬롯 기반 필터(기본/상세 + 검색편집 + 빠른 기간 + 초기화)를 쓴다 — 원형: 쿠폰·상품·주문·회원관리
2. 필터 행은 화면 밖 등록 영역에 한 번만 두고 data-slot 이 기본/상세를 고른다. 두 slot-container 는 filter-grid 필수
3. 배치 저장은 filter-visibility-selector 가 스스로 한다(g7_filters_{id}_{uuid}). 행 체크박스는 상태만 바꾸고, 되읽기는 loadVisibleFilters
4. 조건은 전부 URL 쿼리로 — 「전체」는 빈 값, 배열은 키[] , 쪽은 page="" 로 떨군다, 빠른 기간 표시(*DateQuick)는 URL 에 싣지 않는다
5. 슬롯으로 옮겨 그려지는 행 안에서는 _defines 를 직접 읽지 않는다 — 선택지는 본체 computed 로 넘긴다
```

---

## 목차

1. [등급 — 어느 화면에 무엇을 쓰는가](#등급--어느-화면에-무엇을-쓰는가)
2. [기본/상세 배치 기준](#기본상세-배치-기준)
3. [구조](#구조)
4. [상태와 URL](#상태와-url)
5. [검색편집 배치 저장](#검색편집-배치-저장)
6. [빠른 기간](#빠른-기간)
7. [필터 초기화](#필터-초기화)
8. [확장 필터 주입](#확장-필터-주입)
9. [재사용 자산](#재사용-자산)
10. [하지 말 것](#하지-말-것)

---

## 등급 — 어느 화면에 무엇을 쓰는가

| 등급 | 형태 | 쓰는 곳 |
| --- | --- | --- |
| A | 슬롯 기반 기본/상세 + 검색편집 + 빠른 기간 + 초기화 | 조건이 **4개 이상**인 목록 (쿠폰·상품·주문·회원관리) |
| B | `filter-grid` 에 행을 늘어놓기만 (기본/상세 구분 없음) | 조건이 **3개 이하**인 목록 — 기본/상세를 나누면 오히려 조작이 는다 |
| C | 검색어 한 줄 | 조건이 검색어뿐인 목록 |

"모든 화면을 A 로" 가 아니라 **조건 수에 맞는 등급**을 고른다. 백엔드가 조건을 받는데 화면에 필터가 0개인 상태(서버 기능 사장)는 등급과 무관하게 결함이다.

## 기본/상세 배치 기준

A 등급 화면에서 처음 펼쳐 둘 기본 필터(`defaultFilters`)는 **거의 매 조회에서 쓰는 조건 3~4행**으로 고른다. 나머지는 상세검색에 접는다.

| 기본에 둔다 | 상세에 둔다 |
| --- | --- |
| 목록을 가르는 **상태** 축 한 개 (계정 상태·주문 상태·판매/전시 상태·발급 상태) | 같은 대상의 두 번째·세 번째 상태 축 (잠금·인증 여부 등) |
| 목록을 가르는 **분류** 축 한 개 (권한 그룹·카테고리·쿠폰 대상·클레임) | 언어·국가처럼 특정 운영 상황에서만 쓰는 속성 |
| **대표 기간** 한 개 (가입일·주문일·등록일) | 두 번째 이후의 기간 (최근 로그인·휴면 전환일·상태 변경일) |
| — | 확장(모듈·플러그인)이 주입한 행 — 항상 상세 아래 고정, 검색편집 이동 대상이 아니다 |

- 검색어 줄(`searchField`·`searchKeyword`)은 행이 아니라 카드 머리에 항상 보이지만, `defaultFilters` 목록의 앞 두 칸으로 **함께 적는다**.
- 참조 화면의 기본 배치: 쿠폰 = 대상·발급 상태·등록일, 상품 = 카테고리·날짜·판매 상태·전시 상태, 주문 = 날짜·주문 상태·클레임, 회원관리 = 계정 상태·권한 그룹·가입일.
- 기본이 5행을 넘으면 상세를 나눈 의미가 없어진다 — 그때는 운영자가 「검색편집」으로 스스로 올리게 두고 기본은 줄인다.

## 구조

```html
<div data-ref="filter_card" data-key="filter" class="admin-card">
  <div data-ref="slot_registration_area" class="hidden filter-grid" aria-hidden="true">
    <div data-ref="status_filter_row"
         data-slot="{{(_local.visibleFilters || []).includes('status') ? 'basic_filters' : 'detail_filters'}}"
         data-slot-order="1" class="filter-row">
      <responsive on="portable" class="filter-row flex-col items-start"/>
      <input type="checkbox" class="{{_local.isEditMode ? 'checkbox mr-2' : 'hidden'}}" checked="{{…}}">…</input>
      <span class="filter-label w-32">…</span>
      …
    </div>
  </div>
  <div data-ref="search_row">…검색어 · 열 선택기 · 상세검색 토글…</div>
  <slot-container data-ref="basic_filters_section" slotId="basic_filters" class="filter-grid"/>
  <slot-container data-ref="detail_filters_section" data-if="{{_local.showDetailFilter}}" slotId="detail_filters" class="filter-grid mt-4 pt-4"/>
  <div data-ref="filter_actions">…검색편집 / 검색 / 초기화 (넓은 화면·좁은 화면 두 벌)…</div>
</div>
```

- 행은 N 개를 같은 모양으로 늘어놓고 `data-slot-order` 는 1..N 으로 끊김 없이 잇는다.
- 두 `slot-container` 는 **반드시 `filter-grid`** 를 가진다 — 행 사이 간격은 행이 아니라 이 컨테이너가 준다.
- 행 라벨 폭은 코어 관리자 화면이 `w-32`(한국어 라벨이 길다), 이커머스 화면은 `w-20`~`w-28` 이다.
- 선택지 수가 설치마다 늘어나는 다중선택(역할·카테고리처럼 데이터소스에서 오는 목록)은 체크박스를 늘어놓지 않고 **검색형 `tag-input` 한 칸**으로 고른다(비우면 전체) — 체크박스는 선택지가 고정·소수(계정 상태 6종 등)일 때만 쓴다. `tag-input` 의 `class` 는 안쪽 입력 상자에만 붙으므로 폭은 감싸는 `div`(`flex-1 min-w-0 max-w-2xl`)가 맡고, 선택지 value 는 URL 에서 되읽히는 형태(문자열 id)로 맞춘다. 선택지 데이터소스가 목록보다 늦게 오면 그 전에는 고른 값을 `…` 태그로 두고 `disabled` 로 잠근다(id 가 이름 대신 보이지 않게).
- 검색편집 토글 표현식은 참조 화면과 **문자 단위로 같게** 둔다:
  `isEditMode="{{!_local.isEditMode}}" showDetailFilter="{{!_local.isEditMode ? true : _local.showDetailFilter}}"`
- `filter-visibility-selector` 는 본체 `content` 슬롯 최상단에 둔다.

### 선택지는 computed 로

슬롯으로 옮겨 그려지는 행 안에서는 `_defines` 가 실리지 않는다. 정적 선택지는 본체 `<defines>` 에 두고
`<computed>` 로 받아 행에서 `_computed.*` 로 읽는다. 데이터소스 결과(`roles?.data?.data` 등)는 그대로 읽어도 된다.

```html
<computed name="userStatusOptions">{{(_defines?.userStatusList ?? []).map(d => ({ code: d.code, name: d.name }))}}</computed>
<!-- 행 안 -->
<label data-each="st in (_computed.userStatusOptions ?? [])">…</label>
```

## 상태와 URL

입력 중인 값은 `_local.filter.*` 에 모이고, 검색 버튼·Enter 가 **named action 하나**로 URL 을 바꾼다. 데이터소스는 `query.*` 만 읽으므로 새로고침·뒤로가기·링크 공유가 그대로 된다.

| 규약 | 이유 |
| --- | --- |
| 「전체」는 빈 값으로 떨어뜨린다 (`X !== 'all' ? X : ''`) | `mergeQuery` 가 빈 값을 키 삭제로 처리한다 |
| 다중선택은 `키[]` 로 싣고 데이터소스는 `query['키[]']` 로 읽는다 | 엔진이 `키[]` 를 값 하나일 때도 배열로 되읽는다 |
| 검색은 `page=""` 로 쪽수를 떨군다 (`:page="1"` 아님) | 조건이 바뀌면 3쪽의 의미가 달라진다. 되돌림 형식은 [list-context.md](../rules/list-context.md) 「페이지 되돌림은 빈 값」을 따른다 |
| `*DateQuick` 은 URL 에 싣지 않는다 | 새로고침하면 버튼 하이라이트만 꺼진다(날짜 값은 유지) |
| 기존 쿼리 형식이 있으면 보존하고 새 조건은 평면 snake_case 키로 더한다 | 외부 링크·테스트가 기존 형식에 묶여 있다 |

`<init>` 은 같은 키로 `_local.filter` 를 되채우고, 상세 조건이 URL 에 하나라도 있으면 `showDetailFilter` 를 참으로 둔다.

## 검색편집 배치 저장

- `filter-visibility-selector` 가 자기 `id` 와 로그인 사용자로 만든 키(`g7_filters_{id}_{uuid}`)에 **스스로** 저장한다.
- 행의 편집모드 체크박스는 `_local.visibleFilters` 만 바꾼다. 별도 `save-to-local-storage` 키를 두지 않는다 — 읽는 곳이 없는 사문 키가 된다.
- 진입 시 `loadVisibleFilters`(코어 템플릿 핸들러)로 같은 키를 되읽는다. 저장값이 **배열이면서 비어 있지 않을 때만** 채택한다.
- `defaultFilters` 는 `<init-local>` · `loadVisibleFilters` · `filter-visibility-selector` **세 곳에 같은 목록**을 둔다.

## 빠른 기간

```html
<sequence>
  <action handler="setDateRange" preset="3months" format="date"/>
  <set-state target="_local" filter.createdDateQuick="{{$prev.preset}}" filter.createdStartDate="{{$prev.startDate}}" filter.createdEndDate="{{$prev.endDate}}"/>
</sequence>
```

날짜 칸이 `<input type="date">` 면 반드시 `format="date"` 를 준다 — 생략하면 시각이 붙은 값이 와 칸이 빈다. 날짜 칸을 손으로 고치면 `*DateQuick` 을 비워 버튼 표시와 실제 범위가 갈리지 않게 한다.

## 필터 초기화

`filter` 하위 **전 키를 열거해** 초기값으로 되돌리고, 병합 없이 이동한다. 확장 값 배열도 비운다.

```html
<set-state target="_local" :userFilterExtraQuery="[]">
  <field name="filter">…전 키…</field>
</set-state>
<!-- audit:allow layout-list-context-navigate-merge-query 필터 초기화 — 걸려 있던 조건을 전부 비우는 것이 이 버튼의 목적이므로 병합하지 않는다. -->
<navigate path="/admin/…" :mergeQuery="false" :replace="true"/>
```

면제 주석을 달고 `:mergeQuery="true"` 를 쓰면 감사 룰이 자기모순으로 잡는다.

## 확장 필터 주입

코어 화면이 모듈·플러그인의 필터를 받으려면 네 층이 필요하다(회원관리 목록이 원형).

| 층 | 코어가 여는 것 | 확장이 하는 것 |
| --- | --- | --- |
| ① 표시 | 상세 아웃렛 아래 `<extension-point name="…_filters" data-if="{{_local.showDetailFilter}}">` | `<extension extension-point="…" mode="append">` 로 필터 행을 붙인다 — 템플릿 전용 필터 행 클래스 대신 평범한 유틸로 편다(다른 템플릿에 주입돼도 배치가 유지되게). 노드 `<permissions>` 로 권한 보유자에게만 |
| ② 값 수집 | 검색 액션의 `ext_filters="{{_local.userFilterExtraQuery \|\| []}}"`, 데이터소스의 `ext_filters="{{query['ext_filters[]'] \|\| []}}"` | 자기 네임스페이스로 등록한 핸들러(예: `sirsoft-ecommerce.setUserFilterExt`)로 `{확장id}.{키}={값}` 원소를 넣고 뺀다 — 관리자 템플릿 핸들러를 부르지 않는다 |
| ③ 검증 | FormRequest 가 원소 형식을 1차 방어하고 `ext.{확장id}.{키}` 로 편다 | `core.user.list_validation_rules` 에 키별 규칙을, `core.user.list_validation_attributes` 에 항목명을 더한다 |
| ④ 쿼리 | Service 가 `core.user.filter_list_params`(조건 정규화) 뒤 `core.user.list_query_extensions` 를 발화하고 저장소가 코어 조건 뒤에 클로저를 적용한다 | `core.user.list_query_extensions` 에 `fn(Builder $query, array $filters)` 클로저를 더한다(권한 없으면 무적용) |

코어가 여는 것은 API 형식(`ext_filters[]`)이고, 값을 모으는 핸들러는 화면을 그리는 쪽의 몫이다. 관리자 템플릿은 자기 화면용 핸들러를 갖고, 확장 조각은 어느 관리자 템플릿에 주입될지 모르므로 같은 계약의 핸들러를 자기 네임스페이스로 등록해 부른다. 템플릿과 확장이 공유하는 것은 상태 칸 `_local.userFilterExtraQuery` 와 원소 형식뿐이다.

확장 행은 **기본/상세 이동 대상이 아니다** — 코어가 모르는 키라 `visibleFilters` 순서에 넣을 수 없다. 엔진의 navigate `<query>` 와 데이터소스 `params` 는 속성 맵이라 확장이 키를 늘릴 수 없어서 배열 칸 하나를 쓰는 것이다. 서버 훅은 [hooks.md](../extension/hooks.md#관리자-회원-목록-확장-필터-훅), 조각 예시는 [layout-extensions.md](../extension/layout-extensions.md#예시-관리자-회원-목록-필터에-행-추가) 를 본다.

## 재사용 자산

| 자산 | 위치 | 쓰임 |
| --- | --- | --- |
| `loadVisibleFilters` | 관리자 템플릿 핸들러 | 검색편집 배치 되읽기 (모듈이 꺼져도 코어 화면이 깨지지 않게 코어 템플릿 소유) |
| `setDateRange` `format` 인자 | 관리자 템플릿 핸들러 | `date`(YYYY-MM-DD) / `datetime`(기본) |
| `setUserFilterExt` | 관리자 템플릿 핸들러 | 템플릿 자기 화면의 확장 필터 값 배열 조립 — 확장 조각은 같은 계약의 핸들러를 자기 네임스페이스로 둔다 |
| 회원관리 필터 조각 | 관리자 템플릿 `templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_user_list/_partial_filter_section.g7.html` | 코어 화면용 참조 구현 |

## 하지 말 것

- 행에서 `_defines.*` 를 직접 읽기 — 슬롯 투영 행에는 실리지 않아 선택지가 통째로 빈다.
- 배열 조건을 쉼표 문자열로 싣기 — 서버가 배열 규칙으로 거절하거나 한 값으로 읽는다.
- 모듈 소유 핸들러(`vendor-module.*`)를 코어 화면이 부르기 — 모듈이 꺼지면 화면이 깨진다.
- 확장 값을 확장 조각이 직접 URL 에 싣기 — 코어 검색·초기화와 따로 놀아 조건이 남거나 사라진다.
