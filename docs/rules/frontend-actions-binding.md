# 프론트엔드 액션·바인딩·상태 규칙

루트 AGENTS.md 의 절대 금지 패턴 중 **프론트엔드 액션·바인딩·상태 규칙** 영역. 표의 왼쪽은 금지, 오른쪽이 올바른 사용이다.

## TL;DR (5초 요약)

```text
1. 프론트엔드 핵심 원칙
2. API/핸들러 호출
3. 데이터 바인딩
4. iteration/반복 렌더링
5. 컴포넌트 Props
```

> 원본: 이 규칙들은 2026-09-10 까지 루트 AGENTS.md 「CRITICAL RULES」 에 있었다. 제목·표는 그대로 옮겼고 앵커는 바뀌지 않았다. 색인: [docs/rules/README.md](README.md)

## 프론트엔드 핵심 원칙

> 상세: [docs/frontend/](../frontend/)

```text
필수: 기본 컴포넌트만 사용 (Div, Button, H2 등 — HTML 태그 직접 사용 금지)
필수: 집합 컴포넌트 재사용 우선
필수: 다크 모드 light/dark variant 함께 지정
필수: HtmlEditor 사용 (RichTextEditor 미구현)
```

## API/핸들러 호출

| 금지 | 올바른 사용 |
|------|------------|
| `G7Core.actions.execute` | `G7Core.dispatch` |
| `G7Core.api.call` | `G7Core.dispatch({ handler: 'apiCall', ... })` |
| `handler: "api"` | `handler: "apiCall"` |
| `handler: "nav"` | `handler: "navigate"` |
| `handler: "setLocalState"` | `handler: "setState"` + `target: "local"` |
| `navigate` + `replace: true` (URL만 변경 시) | `handler: "replaceUrl"` |
| `navigate` `params.path: "back"` (동작 키워드로 착각) | `handler: "navigateBack"` — path 는 주소로 해석되어 조용히 `/back` 으로 이동한다 |
| `navigate` `params.url` / `href` / `to` 로 목적지 전달 | `params.path` (또는 액션 `target`) — 엔진은 이 둘만 읽는다. 다른 이름은 무시되어 목적지가 `undefined` 가 되고, 예외도 404 도 없이 버튼만 동작하지 않는다 |
| apiCall `params.target` (params 내부) | `target` 은 액션 top-level. params 내부 위치 시 URL 미해석 |
| apiCall `params.onSuccess` / `params.onError` (params 내부) | 액션 top-level. params 내부면 무시됨 |
| `refetchDataSource` `params.id` | `params.dataSourceId` 사용 |
| `handler: "showToast"` | `handler: "toast"` |
| 모달 안에서 부모 `_local.*` 참조 | 데이터소스 응답 필드 또는 `_global` 사용 (모달은 별도 컨텍스트) |

## 데이터 바인딩

| 금지 | 올바른 사용 |
|------|------------|
| `{{products.data}}` | `{{products?.data?.data}}` (배열 경로 확인) |
| `{{value}}` | `{{value ?? ''}}` (fallback 필수) |
| `{{error.data}}` | `{{error.errors}}` (API 응답 구조) |
| `{{error.data?.errors ?? {}}}` | `{{error.errors}}` (`{}}}` 파서 모호성 회피) |
| `$value` (이벤트 값) | `$event.target.value` |
| `{{props.xxx}}` (Partial) | data_sources ID 직접 참조 |
| `{{$response.xxx}}` (onSuccess) | `{{response.xxx}}` ($ 접두사 없음) |

## iteration/반복 렌더링

| 금지 | 올바른 사용 |
|------|------------|
| `"item"`, `"index"` | `"item_var"`, `"index_var"` |
| iteration 내 if 순서 무시 | if가 iteration보다 먼저 평가됨 |

## 컴포넌트 Props

| 금지 | 올바른 사용 |
|------|------------|
| `Icon className="w-4 h-4"` | `className="text-base"` (아래 등가표) 또는 `size` prop |
| `Select valueKey/labelKey` | computed로 `{ value, label }` 변환 |
| Form 내 `Button` type 없음 | `type="button"` 명시 (submit 방지) |
| `options={{options}}` | `options={{options ?? []}}` (fallback) |
| boolean 필드를 `RadioGroup`/`Select` 의 `name` 자동바인딩만으로 폼에 묶기 | `autoBinding: false` + `value: "{{String(_local.form?.필드 ?? 기본값)}}"` + `change` 액션 `"{{$event.target.value === 'true'}}"` 캐스팅. 자동바인딩 value 경로는 `e.target.value` 문자열을 그대로 저장해 서버 `boolean` 규칙에서 422 가 된다 (표시만 보면 정상이라 저장 시점에야 드러남) |
| `options` 지정 커스텀 `Select`(composite) 에 `defaultValue` | `value: "{{상태 ?? 기본값}}"` + `change` 액션 + 열기 지점 상태 시드 — 커스텀 Select 는 value-제어 전용이라 `defaultValue` 는 렌더되지 않고(빈 표시) 숨은 input 도 없어 값이 조용히 미전송된다 (options 없는 네이티브 렌더 경로만 defaultValue 유효) |
| 폼 밖 제출 버튼 `props.form: "X"` 만 선언 | 참조 대상 `Form` 에 `props.id: "X"` 동반 필수 — id 가 없으면 버튼이 어떤 폼에도 연결되지 않아 클릭이 무반응이 된다 (오류 없음) |

Icon 은 `<i>` 글리프라 박스 크기가 곧 `font-size` 다. `w-N h-N` 은 박스만 정하고 글리프는 부모 `font-size` 를 상속하므로 어긋난다. 기존 `w-N h-N` 을 옮길 때는 아래 등가표를 쓴다 (Chrome 실측).

| `w-N h-N` | px | 등가 `className` |
|---|---|---|
| `w-3 h-3` | 12 | `text-xs` |
| `w-3.5 h-3.5` | 14 | `text-sm` |
| `w-4 h-4` | 16 | `text-base` |
| `w-5 h-5` | 20 | `text-xl` |
| `w-6 h-6` | 24 | `text-2xl` |
| `w-12 h-12` | 48 | `text-5xl` |

`size` prop 은 Font Awesome `fa-*` 클래스로 매핑되며 등가가 아니다 — `size="sm"` → `fa-sm` → `font-size: 0.875em`(상대값) + `line-height` 붕괴로 16px 이 12.25×0.88px 이 된다. 새 아이콘에는 써도 되지만, 기존 `w-N h-N` 의 치환용으로는 쓰지 않는다.

## 상태 관리

| 금지 | 올바른 사용 |
|------|------------|
| 스냅샷 기반 setState | 함수형 업데이트 또는 `stateRef.current` |
| closeModal 후 setState | setState 후 closeModal (순서 중요) |
| sortable 내 폼 자동바인딩 | `parentFormContextProp={undefined}` |
| await 후 캡처된 상태 사용 | await 후 `G7Core.state.getLocal()` 재조회 |
| setState params 키에 `{{}}` 사용 | 키는 정적 경로만, 배열 조작은 `.map()`/`.filter()` |

## 저장소 B 통째 교체 금지

엔진은 폼 상태를 React `localDynamicState`(저장소 A)와 `globalState._local`(저장소 B)에 이중 저장한다. `TemplateApp.setGlobalState` 는 최상위 키를 **얕게** 병합하므로 `setGlobalState({ _local: X })` 는 B 를 patch 가 아니라 **통째 교체**한다. X 가 A 계열 스냅샷이면, A 가 아직 받지 못한 값이 조용히 사라진다.

| 금지 | 올바른 사용 |
|------|------------|
| `globalStateUpdater({ _local: <A 계열 스냅샷> })` (저장소 B 통째 교체) | live B(`getGlobalState()._local`)를 base 로 변경 키만 얹기 |
| sequence 반환값을 stale base 로 구성 | 반환값도 live B 기반 + `addMissingLeafKeys` 로 A 전용 키 보충 |
| 두 쓰기 경로(B 쓰기 / 반환값)에 서로 다른 병합 규칙 | 같은 규칙 — 갈라지면 나중에 소비자가 생길 때 어느 경로를 탔느냐로 결과가 달라진다 |
| `__g7ForcedLocalFields` 오버레이가 있으니 `context.state` 도 최신이라고 가정 | 그 오버레이는 `extendedDataContext` **useMemo 안에서 읽는 window 전역**이라 deps 가 아니다 — memo 가 재계산되지 않으면 실리지 않는다 |
| 자동바인딩이 `__g7PendingLocalState` 에 저장소 A 스냅샷을 그대로 대입 | 렌더러와 같은 순서로 `__g7ForcedLocalFields` 를 얹고 방금 입력한 경로를 다시 적용 — pending 은 `getLocal()` 이 읽는 "화면과 같은 전체 스냅샷" 이다 |
| 저장소 A 에만 쓰는 `_local` 경로 (`context.setState(payload)` 단독) | 같은 지배 분기 안에서 B 도 갱신 — `G7Core.state.setLocal(payload, { render: false })`. B 에 이미 키가 있으면 보충 대상에서 빠져 A 의 값이 조용히 유실된다 |
| 미러를 **형제 분기**에 두고 이 분기도 지켜진다고 간주 | 미러는 그 쓰기를 **지배하는 분기 안**에 둔다 — 긴 함수를 통째로 보면 한 분기의 미러가 다른 분기를 면죄한다 |

A 가 값을 못 받는 대표 경로는 `setLocal({ render: false, selfManaged: true })`(CKEditor 등 자체 DOM 관리 플러그인)다. `render:false` 는 `updateTemplateData` 앞에서 조기 return 하고 액션 밖이라 `__g7ActionContext` 도 없으므로 **React 렌더가 0회** — memo 가 재계산되지 않아 `context.state` 가 입력 이전 스냅샷으로 고정된다. 여기에 폭 변경 리렌더가 `__g7PendingLocalState` 를 null 로 지우면(의존성 배열 없는 `useLayoutEffect`) base 가 stale A 로 떨어진다.

pending 은 저장소 B 의 base 가 된다 — `setLocal` 이 `currentSnapshot = pendingState || baseLocal` 로 pending 을 우선 채택하기 때문이다. 그래서 A 스냅샷을 그대로 실으면 위와 같은 통째 교체가 **저장 클릭 전, 키입력 시점에** 일어난다. 방아쇠는 memo deps 와 무관한 리렌더(폭 변경 등)가 선행하는 것이고, 그것이 없으면 성립하지 않는다.

이 결함군은 예외도 콘솔 에러도 남기지 않는다 — 화면에는 본문이 그대로 보이는데 요청 body 만 비어 나가고(작성 화면 422), 수정 화면에서는 성공 토스트와 함께 **직전 본문이 저장되어 편집분이 사라진다**. 정적 검사가 `_local` 동기화 호출의 base 를 검사한다.

## 핸들러 정의

| 금지 | 올바른 사용 |
|------|------------|
| `{{handler()}}` (표현식에서 호출) | `actions: [{ handler: "xxx" }]` |

## globalHeaders 사용 (engine-v1.16.0+)

| 금지 | 올바른 사용 |
|------|------------|
| `"globalHeaders": { "X-Key": "value" }` | `"globalHeaders": [{ "pattern": "*", "headers": {...} }]` |
| 모든 API에 개별 headers 설정 | globalHeaders로 공통 헤더 정의 |
| pattern 없이 헤더 정의 | pattern 필수 (`*`, `/api/shop/*` 등) |
