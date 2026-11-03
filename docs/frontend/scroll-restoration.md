# 뒤로/앞으로 가기·새로고침 스크롤 복원

> **관련 문서**: [layout-json.md](layout-json.md) (`meta.scroll_restoration`) · [actions-handlers-navigation.md](actions-handlers-navigation.md) (`navigate` 의 `scroll`·`returnScroll`) · [actions-handlers-state.md](actions-handlers-state.md) (`appendDataSource`) · [layout-json-inheritance.md](layout-json-inheritance.md) (meta 병합)
>
> **도입**: 템플릿 엔진 engine-v1.85.0

---

## TL;DR (5초 요약)

```text
1. 뒤로/앞으로 가기로 돌아온 화면은 떠날 때 보던 위치로 돌아온다(엔진 기본값). 데이터가 늦게 와도 문서가 그 위치를 담을 때까지 기다린다.
2. 새 화면으로 가는 이동(navigate)은 종전대로 navigate 의 scroll(기본 맨 위)을 따른다 — 복원과 무관하다.
3. 설정 `scroll_restoration` 은 엔진 기본값 → template.json → 베이스 레이아웃 meta → 화면 meta → navigate returnScroll 순으로 적은 키만 덮어쓴다.
4. 창이 아니라 화면 안 영역에서 스크롤되면(관리자 본문 등) `containers` 에 그 영역 선택자를 적는다 — 배열은 통째로 교체되니 상속값도 함께 적는다.
5. 더보기(appendDataSource)로 늘린 목록은 스냅샷으로 되살아난다. 다음 쪽 번호 같은 진행 상태는 `snapshot.state` 에 경로를 적어야 함께 돌아온다.
```

---

## 목차

1. [무엇이 언제 일어나나](#무엇이-언제-일어나나)
2. [설정 키](#설정-키)
3. [설정을 두는 곳과 우선순위](#설정을-두는-곳과-우선순위)
4. [화면 안 스크롤 영역 (containers)](#화면-안-스크롤-영역-containers)
5. [더보기 목록 스냅샷 (snapshot)](#더보기-목록-스냅샷-snapshot)
6. [새로고침과 개인정보 동의](#새로고침과-개인정보-동의)
7. [레이아웃 편집기에서 바꾸기](#레이아웃-편집기에서-바꾸기)
8. [주의할 점](#주의할-점)
9. [잠금 시험](#잠금-시험)

---

## 무엇이 언제 일어나나

| 이동 | 엔진 동작 |
| --- | --- |
| 링크·`navigate` 로 새 화면 | 복원하지 않는다. 도착 위치는 `navigate` 의 `scroll`(기본 `top`) |
| 브라우저 뒤로/앞으로 가기 | 돌아온 화면을 다시 그린 뒤 설정(`mode`)대로 맞춘다 |
| 새로고침(F5)·다른 사이트에서 뒤로 와 문서를 다시 받은 경우 | `on_reload` 가 켜져 있으면 뒤로 가기와 같게, 꺼져 있으면 맨 위 |
| 첫 진입 | 아무것도 하지 않는다 |
| 레이아웃 편집기 편집 모드 | 기억·복원을 하지 않는다(편집 중 위치가 튀지 않게) |

엔진은 브라우저 기본 복원(`history.scrollRestoration = 'auto'`)을 끄고(`manual`), 히스토리 항목마다 위치를 기억한다.
항목은 Navigation API 의 항목 키(`navigation.currentEntry.key` — 같은 항목에서 쿼리만 바꾸는 `replaceState` 에도 유지)로
구분하고, API 가 없는 브라우저는 주소로 구분한다.

복원은 이렇게 진행한다.

1. 돌아온 항목의 기억을 꺼내고, 떠나는 화면이 남은 채 일어나는 스크롤이 그 기억을 덮지 않게 기억을 잠시 멈춘다.
2. 화면을 다시 그린다. `wait: "height"`(기본)면 첫 렌더 직후, `wait: "data"` 면 이 화면의 데이터를 다 받은 뒤 시작한다.
3. 50ms 마다 문서 높이를 보고, 목표 위치를 담을 만큼 자랐으면 맞춘다. 높이가 바뀌면 다시 맞춘다.
4. 목표에 닿고 600ms 동안 높이가 그대로이거나, `timeout_ms` 가 지나거나, 사용자가 휠·터치·키·포인터로 만지면 끝낸다.
   끝내 높이가 모자라면 닿을 수 있는 가장 아래에 둔다.

먼저 스크롤을 가져간 쪽을 따른다 — 뒤로/앞으로 가기·새로고침이 표시된 순간부터 복원이 시작되기 전(데이터를 기다리는 동안)에
사용자가 휠·터치·키·포인터로 만지거나 화면이 스크롤 액션(`scrollIntoView`, `navigate` 의 `scroll`)을 실행하면 복원하지
않고 그 위치를 이 항목의 기억으로 남긴다. 복원 중에 화면이 스크롤 액션을 실행해도 복원을 멈춘다. 이미 다른 곳을 보고 있는
사람을 데이터가 다 온 뒤 기억 위치로 끌어가지 않기 위해서다.

---

## 설정 키

설정 객체 이름은 어디서나 `scroll_restoration` 이다. 모든 키는 선택이며, 적지 않은 키는 앞 단계 값을 이어받는다.

| 키 | 값 | 엔진 기본값 | 뜻 |
| --- | --- | --- | --- |
| `mode` | `restore` · `top` · `none` · `"#선택자"` | `restore` | 돌아왔을 때 동작. `none` 은 엔진이 손대지 않는다(템플릿·레이아웃이 직접 처리). 선택자면 그 요소가 생길 때까지 기다려 그 요소로 간다. 선택자는 `#`·`.`·`[` 로 시작해야 한다 — 그 밖의 값(`Restore`·`auto` 같은 오타)은 콘솔 경고와 함께 버리고 앞 층의 값을 쓴다 |
| `on_reload` | bool | `true` | 새로고침 뒤에도 같은 동작. `false` 면 새로고침은 맨 위 |
| `wait` | `height` · `data` | `height` | 복원 시작 시점 — 높이 대기(첫 렌더 직후부터 문서가 자라는 동안 맞춤) · 데이터 기준(이 화면의 데이터를 다 받은 뒤 한 번) |
| `timeout_ms` | 0 이상 정수 | `5000` | 최대 대기(ms) |
| `containers` | 선택자 배열 | `[]` | 창과 함께 기억·복원할 화면 안 스크롤 영역 |
| `snapshot.enabled` | bool | `true` | 더보기로 늘어난 데이터소스를 항목별로 기억 |
| `snapshot.ttl_seconds` | 0 이상 정수 | `600` | 스냅샷 유효 시간(초). 처음 기억한 때부터 잰다 — 되살린 스냅샷으로 오가도 늘어나지 않는다 |
| `snapshot.revalidate` | bool | `true` | 스냅샷으로 그린 뒤 첫 묶음을 다시 받아 반영 |
| `snapshot.item_key` | 문자열 | `id` | 반영할 때 항목을 맞추는 식별 필드 |
| `snapshot.state` | 상태 경로 배열 | `[]` | 스냅샷과 함께 기억했다가 되돌릴 진행 상태(`_global.…`·`_local.…`) |

---

## 설정을 두는 곳과 우선순위

좁은 쪽이 앞선다.

```text
엔진 기본값
  < template.json 의 scroll_restoration        (템플릿 제작자가 배포하는 초깃값 — /api/templates/{id}/config.json 으로 서빙)
  < 베이스 레이아웃 meta.scroll_restoration     (사이트 운영자가 편집기에서 고치는 「템플릿 기본값」)
  < 화면 레이아웃 meta.scroll_restoration       (화면마다)
  < navigate 액션의 returnScroll                (그 이동으로 떠나는 화면에 돌아올 때 — mode 만)
```

베이스 → 화면 레이아웃 meta 는 서버가 깊게 병합한다 — 화면이 적은 키만 바뀌고(`snapshot` 하위 키도 하나씩),
배열(`containers`·`snapshot.state`)은 통째로 바뀐다.

### template.json (배포 초깃값)

```json
{
  "scroll_restoration": {
    "mode": "restore",
    "on_reload": true,
    "wait": "height",
    "timeout_ms": 5000,
    "containers": ["#right_content_area"]
  }
}
```

template.json 은 템플릿 업데이트 때 바뀐다. 사이트별로 고칠 값은 베이스 레이아웃 meta 에 둔다.

### 화면 레이아웃 meta

주문 완료처럼 돌아와도 맨 위가 맞는 화면:

```html
<meta title="$t:shop.order_complete" :scroll_restoration='{"mode":"top"}'/>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "meta": {
    "title": "$t:shop.order_complete",
    "scroll_restoration": { "mode": "top" }
  }
}
```

데이터가 많아 늦게 자라는 목록:

```json
{ "meta": { "scroll_restoration": { "timeout_ms": 8000 } } }
```

### navigate 의 returnScroll

`returnScroll` 은 **이 이동으로 떠나는 화면**에 뒤로 가기로 돌아올 때의 동작이다(값은 `mode` 와 같은 집합). 결제 화면에서
완료 화면으로 넘어간 뒤 뒤로 가면 결제 화면을 맨 위부터 보이게 하려면:

```html
<navigate path="/shop/orders/{{response.data.order_number}}/complete" returnScroll="top"/>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "handler": "navigate",
  "params": {
    "path": "/shop/orders/{{response.data.order_number}}/complete",
    "returnScroll": "top"
  }
}
```

- 이탈 가드(`meta.leave_guard`)에서 머무르기를 고르면 붙지 않는다.
- 붙은 값은 그 이동 한 번의 것이다 — 뒤로/앞으로 가기로 그 항목을 오가는 동안은 남지만, 그 화면을 다음에 다른
  navigate 로 떠나면 그 이동의 `returnScroll`(없으면 화면 설정)로 바뀐다. 결제 화면에서 한 번 `"top"` 으로 떠났다고
  다음 일반 링크 이동 뒤에도 계속 맨 위로 가지 않는다.
- 도착 화면의 위치는 종전대로 `scroll` 이 정한다(`returnScroll` 과 무관).

---

## 화면 안 스크롤 영역 (containers)

관리자 화면처럼 창이 아니라 화면 안 영역(`overflow-y: auto`)에서 스크롤되면 창 위치만으로는 돌아오지 않는다. 그 영역의
선택자를 `containers` 에 적으면 떠날 때 영역별 `scrollTop` 을 기억했다가, 돌아오면 그 영역이 생기고 목표를 담을 만큼
자랄 때까지 기다려 맞춘다.

`containers` 는 배열이라 통째로 교체된다. 템플릿 기본값(`#right_content_area` 등)을 상속받는 화면에서 영역을 하나 더
적을 때는 상속값도 함께 적는다.

```json
{
  "meta": {
    "scroll_restoration": {
      "containers": ["#right_content_area", "#common_info_list"]
    }
  }
}
```

---

## 더보기 목록 스냅샷 (snapshot)

더보기·무한 스크롤은 `appendDataSource`(→ `G7Core.dataSource.updateData` append·prepend)로 받은 묶음을 데이터소스에
덧붙인다. 뒤로 가기로 돌아오면 화면은 첫 묶음만 다시 받아 짧아지고, 기억한 위치에 닿지 못한다. 엔진은 다음과 같이
되살린다.

1. 화면을 떠날 때 덧붙여 늘어난 데이터소스의 값을 그 히스토리 항목 키로 기억한다(메모리, 최근 10 항목).
2. 뒤로/앞으로 가기로 그 항목에 돌아오면, 아래 조건을 모두 만족할 때만 그 값으로 먼저 그려 높이를 확보한다.
   - `snapshot.enabled` 이고 `mode` 가 `none` 이 아니다
   - 유효 시간(`snapshot.ttl_seconds`) 안이다
   - 화면 언어와 로그인 계정이 기억할 때와 같다
   - 그 데이터소스를 이 화면 진입 때 자동으로 받는다(`auto_fetch` 가 `false` 가 아니다)
3. `snapshot.revalidate` 가 켜져 있으면 첫 묶음을 다시 받아 반영한다.
   - 식별 필드(`snapshot.item_key`)가 같은 항목은 새 값으로 바꾼다.
   - 첫 묶음에서 스냅샷과 처음 겹치는 항목보다 앞에 새로 생긴 항목(최신순 목록의 새 글·새 알림)은 앞에 붙인다.
     하나도 겹치지 않으면 붙이지 않는다(정렬·필터가 다른 응답일 수 있다).
   - 목록 밖 필드(쪽 정보 등)는 스냅샷 것을 유지한다 — 목록 길이와 맞게.
4. 다시 받기가 실패했거나 대체 값(권한 오류의 fallback, 마지막 성공 값)이면 스냅샷을 버린다 — 권한이 바뀐 뒤 앞 계정
   목록이 다시 그려지지 않게.
5. 스냅샷 데이터는 화면 초기화(`init_global`·`init_local`·`init_actions`)가 끝난 뒤 첫 렌더 직전에 심는다.
   `init_actions` 는 앞으로 가기와 같은 값(받기 전)을 본다.
6. 되살린 스냅샷을 쓴 화면을 다시 떠나면, 새 스냅샷은 **처음 기억한 시각**을 그대로 갖는다(이어서 더보기를 해도 마찬가지).
   다시 받는 것은 첫 묶음뿐이라 2쪽 이후 항목은 처음 기억한 때의 값이다 — 오갈 때마다 유효 시간이 늘어나면 그 항목이
   한없이 다시 받지 않은 채 남는다. 유효 시간이 지나면 첫 묶음부터 새로 그린다.

### 진행 상태 함께 되돌리기 (snapshot.state)

더보기는 보통 다음 쪽 번호·더 있는지 같은 진행 상태를 상태(`_global`·`_local`)에 둔다. 목록만 되살아나고 쪽 번호가
1로 돌아가면, 다음 더보기가 이미 받은 쪽을 다시 받아 같은 항목이 두 번 붙는다. 진행 상태 경로를 `snapshot.state` 에
적으면 스냅샷과 함께 기억했다가, 화면 초기화가 끝난 뒤 첫 렌더 직전에 되돌린다.

```html
<meta title="$t:sirsoft-ecommerce.admin.common_info.title" :scroll_restoration='{"containers":["#right_content_area","#common_info_list"],"snapshot":{"state":["_global.infiniteScroll"]}}'/>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "meta": {
    "title": "$t:sirsoft-ecommerce.admin.common_info.title",
    "scroll_restoration": {
      "containers": ["#right_content_area", "#common_info_list"],
      "snapshot": { "state": ["_global.infiniteScroll"] }
    }
  }
}
```

- 경로는 `_global.` 또는 `_local.` 로 시작해야 한다. 다른 형식은 무시된다.
- 스냅샷을 쓰지 못한 경우(유효 시간 경과·문맥 변경)에는 진행 상태도 되돌리지 않는다.
- 여러 화면이 같은 키(`_global.infiniteScroll`)를 나눠 쓰면 다른 화면이 값을 바꾸므로 반드시 적는다.

---

## 새로고침과 개인정보 동의

새로고침 뒤 복원은 sessionStorage `g7_scroll_positions` 에 둔 위치로 한다(탭 수명 동안만, 사용자 식별 정보 없음).
개인정보 동의 플러그인(sirsoft-gdpr 1.1.4+)은 이 키를 필수 저장 허용목록에 넣어 동의 전 방문자도 새로고침하면 보던
위치로 돌아온다. 다른 동의 도구가 저장을 막으면 사이트 안 뒤로/앞으로 가기 복원(메모리)은 그대로 되고 새로고침
복원만 맨 위로 시작한다. 더보기 스냅샷은 메모리에만 있어 새로고침에서는 되살아나지 않는다.

---

## 레이아웃 편집기에서 바꾸기

- **화면별**: 화면을 열고 툴바 「페이지 설정」 → 「스크롤」 탭. 비운 칸은 베이스 레이아웃·template.json 값을 상속한다.
- **템플릿 기본값**: 라우트 트리에서 베이스 레이아웃(`_user_base`·`_admin_base` 등)을 열고 같은 탭에서 고친다.
- **이동 액션별**: 컴포넌트 이벤트의 「페이지 이동」 액션 편집 칸 「돌아올 때 스크롤」.

---

## 주의할 점

- 레이아웃이 스스로 낸 스크롤(예: `init_actions` 의 `scrollIntoView`)이 있으면 그 화면은 뒤로 가기 때 복원하지 않는다(화면이
  먼저 스크롤을 가져간 것으로 본다). 그 요소로 가는 것이 목적이면 `mode` 를 `"#선택자"` 로 두는 편이 의도가 분명하다.
  엔진 액션을 거치지 않고 직접 `window.scrollTo` 를 부르는 커스텀 핸들러는 엔진이 알 수 없어 복원이 덮는다 — 그런 핸들러는
  `scrollIntoView` 액션을 쓰거나 `mode: "none"` 으로 둔다.
- 스냅샷으로 그린 목록은 다시 받는 동안 흐림 표시가 없다(이미 보이는 데이터라서). 첫 묶음이 오면 바뀐 항목만 바뀐다.
- 높이가 늦게 자라는 화면에서 복원 중 위치가 잠깐 움직일 수 있다 — 문서가 다 자라면 목표 위치로 맞는다.
- 템플릿 기본값은 활성 템플릿의 template.json 에서 온다. 번들 template.json 을 고쳤으면 `template:update` 로 반영해야 한다.

---

## 잠금 시험

| 시험 | 범위 |
| --- | --- |
| `resources/js/core/routing/__tests__/Router.scrollRestoration.test.ts` | 이동 종류·기억·복원 실행기·`returnScroll`·이탈 가드·선택자·내부 영역·편집 모드·설정 병합 |
| `resources/js/core/__tests__/TemplateApp.scrollRestoration.test.ts` | 실 TemplateApp·Router — 늦게 자라는 화면·더보기 스냅샷·`snapshot.state`·대체 값·계정 변경·`wait:"data"`·설정 층 |
| `resources/js/core/template-engine/__tests__/dataSourceSnapshot.test.ts` | 스냅샷 기억·가져가기·문맥·유효 시간·첫 묶음 반영 |
| `tests/Playwright/specs/scroll-restoration.spec.ts` | 실제 브라우저 — 목록 → 상품 → 뒤로, 새로고침, 새 이동 |
| `modules/_bundled/sirsoft-ecommerce/resources/js/__tests__/layouts/infinite-list-scroll-restoration.test.ts` | 더보기 목록 화면의 선언(소스에서 기계 도출) |

시나리오 매니페스트: `tests/scenarios/client-router-scroll-restoration.yaml`
