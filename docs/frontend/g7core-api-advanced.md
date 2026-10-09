# G7Core 전역 API 레퍼런스 - 고급

> **버전**: engine-v1.4.0+
> **관련 문서**: [g7core-api.md](g7core-api.md) | [state-management.md](state-management.md) | [components.md](components.md)

---

## 목차

1. [다국어 (G7Core.locale, G7Core.t)](#다국어-g7corelocale-g7coret)
2. [액션 실행 (G7Core.dispatch)](#액션-실행-g7coredispatch)
3. [컴포넌트 이벤트 (G7Core.componentEvent)](#컴포넌트-이벤트-g7corecomponentevent)
4. [이벤트 생성 헬퍼](#이벤트-생성-헬퍼)
5. [컴포넌트 렌더링 헬퍼](#컴포넌트-렌더링-헬퍼)
6. [인증 및 API](#인증-및-api)
7. [반응형 및 전환 상태](#반응형-및-전환-상태)
8. [엔진 안전 스위치 (G7Core.engineFlags)](#엔진-안전-스위치-g7coreengineflags)
9. [타입 정의](#타입-정의)

---

## 다국어 (G7Core.locale, G7Core.t)

로케일 관리 및 번역 API입니다.

### G7Core.locale API

| 메서드 | 설명 | 반환값 |
|--------|------|--------|
| `current()` | 현재 로케일 반환 | `string` (예: 'ko', 'en') |
| `supported()` | 지원 로케일 목록 | `string[]` |
| `change(locale, options?)` | 로케일 변경. `options.persist: false` 면 로그인 회원의 언어(`users.language`)를 저장하지 않는다 — 호출자가 이미 저장한 경우 저장 요청이 두 번 나가지 않게 한다 (engine-v1.79.0+). 생략하면 로그인 회원의 언어를 저장한다 | `Promise<void>` |
| `handoff.isSwitching()` | 언어 전환으로 화면을 다시 마운트하는 중인지 (engine-v1.79.0) | `boolean` |
| `handoff.stash(key, value)` | 언어 전환 중 언마운트되는 컴포넌트가 저장 전 상태를 맡긴다. 전환 중이 아니면 무시 | `boolean` (맡겼으면 true) |
| `handoff.take(key)` | 새로 마운트된 컴포넌트가 맡긴 값을 되찾는다. 한 번 꺼내면 지워지고, 다음 화면 이동이 시작되면 남은 값은 버린다 | `unknown \| undefined` |

언어를 바꾸면 엔진이 같은 화면을 새로 마운트한다. `_local` 은 엔진이 그대로 이어 주지만, 컴포넌트 안에만 있는 저장 전 상태(업로더가 고른 대기 파일처럼 직렬화할 수 없는 값)는 `handoff` 로 직접 넘긴다. 키는 같은 화면의 인스턴스를 구분하도록 경로와 컴포넌트 식별값을 섞어 정한다.

```typescript
// 마운트: 맡긴 값이 있으면 되찾는다
const key = `MyUploader:${location.pathname}:${collection}`;
const [pending, setPending] = useState(() => G7Core.locale.handoff?.take(key) ?? []);
// 언마운트: 언어 전환 중일 때만 맡긴다
useEffect(() => () => {
  if (G7Core.locale.handoff?.isSwitching()) G7Core.locale.handoff.stash(key, pendingRef.current);
}, [key]);
```

### G7Core.t() 번역 함수

```typescript
G7Core.t(key: string, params?: Record<string, string | number>): string
```

| 파라미터 | 타입 | 설명 |
|----------|------|------|
| `key` | string | 번역 키 (예: 'common.confirm') |
| `params` | object | 번역 파라미터 (선택) |

### 사용 예시

```typescript
// 로케일 조회
const currentLocale = G7Core.locale.current();  // 'ko'
const supportedLocales = G7Core.locale.supported();  // ['ko', 'en']

// 로케일 변경
await G7Core.locale.change('en');

// 번역 함수 사용
const text = G7Core.t('common.confirm');  // '확인'

// 파라미터 치환
const message = G7Core.t('admin.users.pagination_info', {
  from: 1,
  to: 10,
  total: 100
});
// "1-10 / 총 100건"
```

### 컴포넌트에서 t 함수 선언 패턴

> 상세 내용: [components.md](components.md#6-다국어-번역-g7coret)

```tsx
// 컴포넌트 파일 상단에 선언
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

// 사용
<Button>{t('common.confirm')}</Button>
<Span>{t('admin.users.count', { count: 10 })}</Span>
```

---

## 액션 실행 (G7Core.dispatch)

액션을 프로그래밍 방식으로 실행하는 API입니다.

### 함수 시그니처

```typescript
G7Core.dispatch(action: ActionConfig): Promise<ActionResult>
```

### ActionConfig 구조

```typescript
interface ActionConfig {
  handler: string;           // 핸들러 이름 (예: 'api', 'navigate', 'toast')
  params?: Record<string, any>;  // 핸들러 파라미터
  target?: string;           // 대상 ID (모달 등)
}
```

### ActionResult 구조

```typescript
interface ActionResult {
  success: boolean;
  data?: any;
  error?: Error;
}
```

### 사용 예시

```typescript
// 네비게이션
await G7Core.dispatch({
  handler: 'navigate',
  params: { path: '/users' }
});

// API 호출 — 핸들러명은 `apiCall`, URL 은 액션 top-level `target`
const result = await G7Core.dispatch({
  handler: 'apiCall',
  target: '/api/users',
  params: {
    method: 'POST',
    body: { name: 'John' }
  }
});

// 모달 열기
await G7Core.dispatch({
  handler: 'openModal',
  target: 'confirm_modal'
});

// 토스트 표시
await G7Core.dispatch({
  handler: 'toast',
  params: {
    message: '저장되었습니다',
    type: 'success'
  }
});

// 상태 설정
await G7Core.dispatch({
  handler: 'setState',
  params: {
    target: 'global',
    sidebarOpen: true
  }
});
```

---

## 컴포넌트 이벤트 (G7Core.componentEvent)

컴포넌트 간 통신을 위한 이벤트 시스템입니다.

### API 목록

| 메서드 | 설명 | 반환값 |
|--------|------|--------|
| `on(eventName, callback)` | 이벤트 구독 | 구독 해제 함수 |
| `emit(eventName, data?)` | 이벤트 발생 | `Promise<any[]>` |
| `off(eventName)` | 특정 이벤트 리스너 제거 | `void` |
| `clear()` | 모든 리스너 제거 | `void` |

### 사용 예시

```typescript
// 이벤트 구독
const unsubscribe = G7Core.componentEvent.on('fileSelected', (data) => {
  console.log('파일 선택됨:', data.file);
});

// 이벤트 발생
await G7Core.componentEvent.emit('fileSelected', { file: selectedFile });

// 이벤트 발생 + 결과 수집
const results = await G7Core.componentEvent.emit('triggerUpload:logo_uploader');
// results: 모든 리스너의 반환값 배열

// 구독 해제
unsubscribe();

// 특정 이벤트의 모든 리스너 제거
G7Core.componentEvent.off('fileSelected');

// 모든 리스너 제거
G7Core.componentEvent.clear();
```

### 이벤트 네이밍 규칙

| 패턴 | 설명 | 예시 |
|------|------|------|
| `{action}` | 단순 액션 | `fileSelected`, `dataLoaded` |
| `{action}:{target}` | 특정 대상 지정 | `triggerUpload:logo_uploader` |
| `{component}.{action}` | 컴포넌트별 이벤트 | `sidebar.toggle`, `modal.close` |

### 사용 사례

#### FileUploader 트리거

```typescript
// 파일 업로더 컴포넌트에서 이벤트 구독
useEffect(() => {
  const unsubscribe = G7Core.componentEvent.on(
    `triggerUpload:${uploaderId}`,
    async () => {
      inputRef.current?.click();
    }
  );
  return unsubscribe;
}, [uploaderId]);

// 다른 컴포넌트에서 업로드 트리거
G7Core.componentEvent.emit('triggerUpload:logo_uploader');
```

#### 데이터 동기화

```typescript
// 데이터 변경 알림
G7Core.componentEvent.emit('dataChanged', {
  source: 'userForm',
  data: updatedUser
});

// 다른 컴포넌트에서 수신
G7Core.componentEvent.on('dataChanged', ({ source, data }) => {
  if (source === 'userForm') {
    refreshUserList();
  }
});
```

---

## 이벤트 생성 헬퍼

ActionDispatcher와 호환되는 이벤트 객체를 생성하는 헬퍼 함수들입니다.

### API 목록

| 함수 | 설명 | 반환값 |
|------|------|--------|
| `createChangeEvent(value, name?)` | change 이벤트 생성 | `ChangeEvent` |
| `createClickEvent(data?)` | click 이벤트 생성 | `ClickEvent` |
| `createSubmitEvent(formData?)` | submit 이벤트 생성 | `SubmitEvent` |
| `createKeyboardEvent(key, modifiers?)` | keyboard 이벤트 생성 | `KeyboardEvent` |

### 사용 예시

```typescript
// Change 이벤트 생성
const changeEvent = G7Core.createChangeEvent('newValue', 'fieldName');
// { target: { value: 'newValue', name: 'fieldName' } }

// Click 이벤트 생성
const clickEvent = G7Core.createClickEvent({ itemId: 123 });
// { data: { itemId: 123 } }

// Submit 이벤트 생성
const submitEvent = G7Core.createSubmitEvent({
  name: 'John',
  email: 'john@example.com'
});
// { formData: { name: 'John', email: 'john@example.com' } }

// Keyboard 이벤트 생성
const keyEvent = G7Core.createKeyboardEvent('Enter', { ctrlKey: true });
// { key: 'Enter', ctrlKey: true, shiftKey: false, altKey: false }
```

### 사용 사례

컴포넌트에서 ActionDispatcher 핸들러를 직접 호출할 때 사용합니다:

```typescript
// 컴포넌트에서 프로그래밍 방식으로 change 이벤트 발생
const handleCustomChange = (newValue: string) => {
  const event = G7Core.createChangeEvent(newValue, 'customField');
  onChange?.(event);  // props로 전달된 onChange 호출
};

// Submit 이벤트와 함께 액션 실행
const handleFormSubmit = async () => {
  const submitEvent = G7Core.createSubmitEvent(formData);
  await G7Core.dispatch({
    handler: 'apiCall',
    target: '/api/admin/submit',
    params: {
      method: 'POST',
      body: submitEvent.formData
    }
  });
};
```

---

## 컴포넌트 렌더링 헬퍼

### G7Core.renderItemChildren

반복 렌더링 컴포넌트(CardGrid, DataGrid 등)에서 각 아이템의 자식 컴포넌트를 렌더링하는 헬퍼입니다.

```typescript
G7Core.renderItemChildren(
  children: ComponentDef[],
  itemContext: Record<string, any>,
  componentMap: Record<string, React.ComponentType>,
  keyPrefix?: string,
  options?: RenderOptions
): React.ReactNode[]
```

### 파라미터

| 파라미터 | 타입 | 설명 |
|----------|------|------|
| `children` | array | 렌더링할 자식 컴포넌트 정의 배열 |
| `itemContext` | object | 현재 아이템 컨텍스트 (row, item 등) |
| `componentMap` | object | 컴포넌트 이름 → 컴포넌트 매핑 |
| `keyPrefix` | string | React key 접두사 (선택) |
| `options` | object | 추가 옵션 (선택) — `componentContext`·`renderEnv`·`responsiveWidth`·`isEditMode`·엔진·`actionDispatcher` |

#### 렌더 환경 (engine-v1.75.2+)

래퍼는 번역 컨텍스트·엔진·액션 처리기·컴포넌트 목록을 **명시 `options` > 렌더 환경 > 호스트 값** 순서로 고른다.
렌더 환경은 `options.renderEnv` 또는 `options.componentContext.renderEnvRef.current` 다. 엔진이 컴포넌트에
자동으로 넘기는 `__componentContext.renderEnvRef` 가 그 컴포넌트를 그린 렌더러의 환경(폭·콘텐츠 언어·액션
처리기·컴포넌트 레지스트리·편집 모드)을 담는다. 렌더 환경에 레지스트리가 있으면 그 컴포넌트가 `componentMap`
의 같은 이름보다 우선한다.

레이아웃 편집기 미리보기는 같은 문서 안에서 그려지므로, 렌더 환경을 넘기지 않으면 셀이 미리보기 기기 폭 대신
브라우저 창 폭으로 `responsive` 분기를 고르고 관리자 화면 언어로 번역된다. 일반 화면에서는 결과가 같다.

### 사용 예시

```tsx
// CardGrid 컴포넌트에서 사용
const CardGrid: React.FC<Props> = ({ items, cellChildren, __componentContext }) => {
  const componentMap = G7Core.getComponentMap();
  // 셀의 _local 을 병합할 필요가 없으므로 상태를 병합하지 않는 renderEnv 만 넘긴다
  const renderEnv = __componentContext?.renderEnvRef?.current;

  return (
    <Div className="grid">
      {items.map((item, index) => (
        <Div key={item.id || index}>
          {G7Core.renderItemChildren(
            cellChildren,
            { row: item, index },
            componentMap,
            `card_${index}`,
            { renderEnv }
          )}
        </Div>
      ))}
    </Div>
  );
};
```

### G7Core.getComponentMap

전체 컴포넌트 맵을 반환합니다:

```typescript
const componentMap = G7Core.getComponentMap();
// { Button: ButtonComponent, Input: InputComponent, ... }
```

### G7Core.renderComponentLayout

> **engine-v1.12.0+** 추가

`component_layout` 정의를 받아서 React 요소로 렌더링합니다. RichSelect 등에서 커스텀 아이템 렌더링 시 사용합니다.

```typescript
G7Core.renderComponentLayout(
  layoutDefs: ComponentDef[] | undefined,
  itemContext: Record<string, any>,
  keyPrefix?: string,
  options?: {
    renderEnv?: RenderEnvironment | null;
    componentContext?: { renderEnvRef?: { current: RenderEnvironment | null } };
    responsiveWidth?: number;
    translationContext?: TranslationContext;
    actionDispatcher?: any;
  }
): React.ReactNode
```

#### 파라미터

| 파라미터 | 타입 | 설명 |
|----------|------|------|
| `layoutDefs` | array | component_layout 정의 배열 |
| `itemContext` | object | 항목별 컨텍스트 (item, index, isSelected 등) |
| `keyPrefix` | string | React key prefix (선택) |
| `options` | object | 렌더 환경과 명시값 (선택, engine-v1.75.2+) — 우선순위는 위 「렌더 환경」 절과 같다 |

#### 사용 예시

```tsx
// RichSelect 컴포넌트에서 사용
const RichSelect: React.FC<Props> = ({ options, __componentLayoutDefs, __componentContext }) => {
  const renderEnv = __componentContext?.renderEnvRef?.current;
  return (
    <Div>
      {options.map((option, index) => (
        <Div key={option.value}>
          {G7Core.renderComponentLayout(
            __componentLayoutDefs?.item,
            { item: option, index, isSelected: selectedValue === option.value },
            `item-${index}`,
            { renderEnv }
          )}
        </Div>
      ))}
    </Div>
  );
};
```

### G7Core.renderExpandContent

> **engine-v1.12.0+** 추가

DataGrid, CardGrid 등에서 확장 행(expandChildren) 콘텐츠를 렌더링하는 헬퍼입니다. 60줄 이상의 복잡한 로직을 10줄 이하로 간소화합니다.

```typescript
G7Core.renderExpandContent(config: {
  children: ComponentDef[];
  row: any;
  expandContext?: Record<string, any>;
  componentContext?: {
    state?: Record<string, any>;
    setState?: Function;
    stateRef?: { current: Record<string, any> };
    computedRef?: { current: Record<string, any> };
    renderEnvRef?: { current: RenderEnvironment | null };
  };
  componentMap?: Record<string, React.ComponentType>;
  keyPrefix: string;
  renderEnv?: RenderEnvironment | null;
  responsiveWidth?: number;
}): React.ReactNode
```

#### 파라미터

| 파라미터 | 타입 | 설명 |
|----------|------|------|
| `children` | array | 렌더링할 expandChildren 컴포넌트 정의 |
| `row` | object | 현재 행 데이터 |
| `expandContext` | object | 확장 컨텍스트 (바인딩 표현식 자동 평가) |
| `componentContext` | object | 부모 컴포넌트 컨텍스트 (state, setState). `renderEnvRef` 가 있으면 렌더 환경으로 쓴다 |
| `componentMap` | object | 컴포넌트 맵 (없으면 자동 조회) |
| `keyPrefix` | string | React key prefix |
| `renderEnv` | object | 렌더 환경 명시값 (선택, engine-v1.75.2+) |
| `responsiveWidth` | number | `responsive` 분기 폭 명시값 (선택, engine-v1.75.2+) |

#### 동작 원리

1. 전역 상태 가져오기
2. `componentContext.state`를 `_local`에 자동 병합
3. `expandContext` 바인딩 표현식 자동 평가
4. 최종 컨텍스트 구성 (`row`, `item`, `$item`, `_local`, `_global`, `_computed`)
5. 렌더링

#### 사용 예시

```tsx
// DataGrid에서 확장 행 렌더링 (Before: 60줄+ → After: 10줄)
const renderExpandedContent = useCallback((row: any) => {
  if (expandedRowRender) return expandedRowRender(row);
  if (expandChildren && expandChildren.length > 0) {
    return G7Core.renderExpandContent({
      children: expandChildren,
      row,
      expandContext,
      componentContext: __componentContext,
      keyPrefix: `expand-${row[idField]}`,
    });
  }
  return null;
}, [expandChildren, expandContext, __componentContext, expandedRowRender, idField]);
```

---

## 인증 및 API

### G7Core.AuthManager

인증 상태를 관리하는 클래스입니다.

> 상세 내용: [auth-system.md](auth-system.md)

```typescript
// G7Core.AuthManager 는 클래스다 — 인스턴스를 얻어 쓴다
const authManager = G7Core.AuthManager.getInstance();

// 현재 사용자 정보
const user = authManager.getUser();

// 로그인 상태 확인
const isLoggedIn = authManager.isAuthenticated();

// 현재 인증 상태 사본 ({ isAuthenticated, user, type })
const state = authManager.getState();

// 인증 상태 변경 구독 — 반환값은 해제 함수
const off = authManager.on('authStateChange', (next) => {
  console.log(next.isAuthenticated);
});
off();

// 토큰 조회는 API 클라이언트에서
const token = G7Core.api.getToken();
```

`on()` 은 지난 이벤트를 다시 보내지 않으므로 최초 상태는 `getState()` 로 읽습니다. 이벤트 목록은 [auth-system.md](auth-system.md#이벤트-처리)를 참고합니다.

### G7Core.api

API 클라이언트 인스턴스입니다.

```typescript
// GET 요청
const users = await G7Core.api.get('/api/users');

// POST 요청
const result = await G7Core.api.post('/api/users', {
  name: 'John',
  email: 'john@example.com'
});

// PUT 요청
await G7Core.api.put('/api/users/1', { name: 'Jane' });

// DELETE 요청
await G7Core.api.delete('/api/users/1');

// 확장이 fetch 로 직접 요청하며 토큰을 실을 때 — 같은 출처 대상에만 싣는다
const token = G7Core.api.isSameOriginUrl(endpoint) ? G7Core.api.getToken() : null;
```

`G7Core.api` 자체의 요청(`get`/`post` …)은 교차 출처 대상에 토큰을 싣지 않는다. 교차 출처 응답의 401(토큰 갱신·재시도)과 428(본인인증 요구)도 따르지 않는다.

확장이 `fetch` 로 직접 요청하며 `getToken()` 을 실을 때도 같은 원칙을 따른다. 대상 주소가 레이아웃 prop 이나 응답 본문처럼 바깥에서 정해지는 값이면 먼저 `isSameOriginUrl(url)` 로 확인한다. 이 판정은 `apiCall` 이 토큰을 실을지 정할 때와 같은 기준이다(engine-v1.73.0).

---

## WebSocket (G7Core.websocket)

실시간 데이터 통신을 위한 WebSocket 관리 API입니다.

### API 목록

| 메서드 | 설명 | 반환값 |
|--------|------|--------|
| `subscribe(channel, event, callback, options?)` | 채널/이벤트 구독 | 구독 키 (string) |
| `unsubscribe(subscriptionKey)` | 구독 해제 | `void` |
| `leaveChannel(channel)` | 채널 떠나기 | `void` |
| `disconnect()` | 연결 종료 | `void` |
| `isInitialized()` | 초기화 상태 확인 | `boolean` |
| `getSubscriptionCount()` | 현재 구독 수 | `number` |

### 사용 예시

```typescript
// 채널 구독
const subscriptionKey = G7Core.websocket.subscribe(
  'admin.dashboard',      // 채널명
  'stats.updated',        // 이벤트명
  (data) => {             // 콜백
    console.log('통계 업데이트:', data);
  }
);

// 옵션과 함께 구독
const key = G7Core.websocket.subscribe(
  'private-user.123',
  'notification',
  handleNotification,
  { presence: true }
);

// 구독 해제
G7Core.websocket.unsubscribe(subscriptionKey);

// 채널 떠나기 (해당 채널의 모든 구독 해제)
G7Core.websocket.leaveChannel('admin.dashboard');

// 연결 종료
G7Core.websocket.disconnect();

// 상태 확인
if (G7Core.websocket.isInitialized()) {
  console.log('구독 수:', G7Core.websocket.getSubscriptionCount());
}
```

### 컴포넌트에서 사용 패턴

```tsx
useEffect(() => {
  const key = G7Core.websocket.subscribe(
    'admin.orders',
    'order.created',
    (order) => {
      // 새 주문 처리
      refreshOrderList();
    }
  );

  return () => {
    G7Core.websocket.unsubscribe(key);
  };
}, []);
```

---

## 반응형 및 전환 상태

### G7Core.ResponsiveManager

반응형 상태를 관리합니다.

> 상세 내용: [responsive-layout.md](responsive-layout.md)

```typescript
// 현재 브라우저 창 폭 (전역 싱글톤)
const width = G7Core.ResponsiveManager.getWidth();

// responsive 키 중 주어진 폭에 맞는 것 (프리셋·커스텀 범위 키)
const key = G7Core.ResponsiveManager.getMatchingKey({ mobile: {}, desktop: {} }, width); // 'mobile' | 'desktop' | null

// 창 폭 변화 구독
const unsubscribe = G7Core.ResponsiveManager.subscribe((w) => { /* ... */ });
```

> `ResponsiveManager` 는 **브라우저 창 폭**이다. 컴포넌트가 화면 폭으로 형태를 고를 때는 아래 `useResponsive()`
> 를 쓴다 — 레이아웃 편집기 미리보기에서는 창 폭과 미리보기 기기 폭이 다르다.

### G7Core.useResponsive (React Hook)

```tsx
const { width, isMobile, isTablet, isDesktop, matchedPreset } = G7Core.useResponsive();
// matchedPreset: 'mobile' | 'tablet' | 'desktop'
```

렌더 폭(Provider 폭)을 돌려준다. 일반 화면에서는 창 폭과 같고, 레이아웃 편집기 미리보기에서는 고른 기기 폭이다.

### G7Core.TransitionManager

페이지 전환 상태를 관리합니다.

```typescript
// 전환 중 여부
const isPending = G7Core.TransitionManager.getIsPending();

// 상태 변경 구독
const unsubscribe = G7Core.TransitionManager.subscribe((isPending) => {
  console.log('전환 상태:', isPending);
});
```

### G7Core.useTransitionState (React Hook)

```tsx
const { isPending } = G7Core.useTransitionState();
```

### G7Core.updateQueryParams

같은 페이지 내에서 URL 쿼리 파라미터만 변경하면서 컴포넌트 리마운트 없이 데이터소스를 refetch 한다. 일반적으로 `navigate` 핸들러의 `replace: true` 옵션 사용 시 엔진이 자동 호출하므로 직접 호출은 드물다.

```typescript
G7Core.updateQueryParams(
  newPath: string,
  options?: { transitionOverlayTarget?: string; refetchDataSourceIds?: string[] }
): Promise<void>
```

| 파라미터 | 타입 | 설명 |
|---|---|---|
| `newPath` | `string` | 새 경로 (쿼리스트링 포함). 예: `/admin/products?status=active&page=2` |
| `options.transitionOverlayTarget` | `string` | `transition_overlay.target` 동적 override (engine-v1.36.0+). 탭 속 서브 탭 등 부분 영역만 spinner 표시 |
| `options.refetchDataSourceIds` | `string[]` | 이 호출에서 다시 받을 데이터 소스 id (engine-v1.79.0+). 생략하거나 빈 배열이면 종전처럼 auto_fetch 소스 전부. 다시 받지 않은 소스의 데이터와 `_local` 은 그대로 남는다. `navigate` 의 `refetch_data_sources` 가 이 값으로 전달된다 |

**동작**:

1. `window.history.replaceState` 로 URL 갱신 (페이지 리로드 없음)
2. `auto_fetch: true` 인 데이터소스 refetch (websocket 제외)
3. `transition_overlay` 가 정의되어 있고 `blocking` 또는 `wait_for` 일치 progressive 데이터소스가 1개 이상이면 오버레이 자동 표시 (engine-v1.35.0+)
4. fetch 완료 시 `updateTemplateData` 호출 + `hideTransitionOverlay`
5. `computed` 재계산

**사용 예시**:

```typescript
// 일반: 쿼리 파라미터 변경 + 데이터 refetch
await G7Core.updateQueryParams('/admin/products?status=active&page=2');

// 서브 탭 spinner target 동적 override
await G7Core.updateQueryParams(
  '/admin/settings?tab=notification&channel=email',
  { transitionOverlayTarget: 'notif_channel_content' }
);
```

**관련**:

- `navigate` 핸들러 (`replace: true`) → [actions-handlers-navigation.md](actions-handlers-navigation.md)
- `transition_overlay.wait_for` / `overlay_target` → [layout-json-components-loading.md](layout-json-components-loading.md)

---

## React Hooks

### G7Core.useControllableState

> **engine-v1.12.0+** 추가

Controlled/Uncontrolled 컴포넌트 패턴을 단순화하는 훅입니다. 외부 value prop과 내부 상태를 자동으로 동기화합니다.

```typescript
const [value, setValue] = G7Core.useControllableState<T>(
  controlledValue,  // T | undefined - 외부 controlled value (선택)
  defaultValue,     // T - 초기값 (uncontrolled일 때 사용)
  onChange?,        // (value: T) => void - 값 변경 시 콜백 (선택)
  options?          // { isEqual?: (a: T, b: T) => boolean } - 옵션 (선택)
);
```

#### 파라미터

| 파라미터 | 타입 | 설명 |
|----------|------|------|
| `controlledValue` | `T \| undefined` | 외부 controlled value (있으면 controlled 모드) |
| `defaultValue` | `T` | 초기값 (uncontrolled 모드에서 사용) |
| `onChange` | `(value: T) => void` | 값 변경 시 호출될 콜백 (선택) |
| `options` | `object` | 추가 옵션 (선택) |
| `options.isEqual` | `(a: T, b: T) => boolean` | 커스텀 비교 함수 (기본: `===`) |

#### 반환값

| 인덱스 | 타입 | 설명 |
|--------|------|------|
| `[0]` | `T` | 현재 값 (controlled 또는 uncontrolled) |
| `[1]` | `(value: T \| ((prev: T) => T)) => void` | 값 설정 함수 (함수형 업데이트 지원) |

#### 동작 원리

- **Controlled 모드**: `controlledValue`가 `undefined`가 아니면, 외부 값을 그대로 사용
- **Uncontrolled 모드**: `controlledValue`가 `undefined`면, 내부 상태 사용
- **자동 동기화**: 외부 `controlledValue`가 변경되면 내부 상태도 동기화
- **onChange 호출**: `setValue` 호출 시 `onChange` 콜백 자동 호출
- **함수형 업데이트**: `setValue(prev => newValue)` 형태 지원

#### 사용 예시

```tsx
// DataGrid에서 정렬 상태 관리
const DataGrid: React.FC<Props> = ({
  sortKey: externalSortKey,
  sortOrder: externalSortOrder,
  onSort,
  ...props
}) => {
  const [sortKey, setSortKey] = G7Core.useControllableState(
    externalSortKey,
    null,
    (key) => onSort?.(key, sortOrder)
  );

  const [sortOrder, setSortOrder] = G7Core.useControllableState(
    externalSortOrder,
    'asc',
    (order) => onSort?.(sortKey, order)
  );

  // 함수형 업데이트 예시
  const toggleSortOrder = () => {
    setSortOrder(prev => prev === 'asc' ? 'desc' : 'asc');
  };

  // ...
};
```

```tsx
// PermissionTree에서 선택된 권한 관리 (배열 비교 함수 사용)
const PermissionTree: React.FC<Props> = ({ value, onChange }) => {
  // 배열 비교 함수 정의
  const areArraysEqual = (a: number[], b: number[]): boolean => {
    if (a.length !== b.length) return false;
    const sortedA = [...a].sort((x, y) => x - y);
    const sortedB = [...b].sort((x, y) => x - y);
    return sortedA.every((val, idx) => val === sortedB[idx]);
  };

  const [selectedIds, setSelectedIds] = G7Core.useControllableState<number[]>(
    value,
    [],
    onChange,
    { isEqual: areArraysEqual }
  );

  // 함수형 업데이트로 선택 토글
  const toggleSelection = (id: number) => {
    setSelectedIds(prev =>
      prev.includes(id)
        ? prev.filter(x => x !== id)
        : [...prev, id]
    );
  };

  // ...
};
```

#### G7Core에서 훅 사용 패턴

컴포넌트에서 G7Core의 훅을 사용할 때 타입 안전성을 위한 패턴:

```tsx
// G7Core 전역 객체에서 훅 가져오기
const G7Core = (window as any).G7Core;

// 타입 캐스팅으로 훅 정의
const useControllableState = G7Core?.useControllableState as
  | (<T>(
      controlledValue: T | undefined,
      defaultValue: T,
      onChange?: (value: T) => void,
      options?: { isEqual?: (a: T, b: T) => boolean }
    ) => [T, (value: T | ((prev: T) => T)) => void])
  | undefined;

// 폴백 로직과 함께 사용
const [state, setState] = useControllableState
  ? useControllableState<MyType>(externalValue, defaultValue, handleChange)
  : useState<MyType>(defaultValue);
```

### G7Core.shallowArrayEqual

배열의 얕은 비교를 수행합니다. 배열 길이와 각 인덱스의 요소를 `===`로 비교합니다.

```typescript
G7Core.shallowArrayEqual<T>(a: T[], b: T[]): boolean
```

#### 동작 원리

1. 두 배열이 같은 참조면 `true` 반환
2. 배열 길이가 다르면 `false` 반환
3. 각 인덱스의 요소를 `===`로 비교 (얕은 비교)

#### 사용 예시

```typescript
// 기본 타입 배열
G7Core.shallowArrayEqual([1, 2, 3], [1, 2, 3]);  // true
G7Core.shallowArrayEqual([1, 2], [1, 2, 3]);     // false

// 객체 배열 (참조 비교)
const obj = { id: 1 };
G7Core.shallowArrayEqual([obj], [obj]);          // true (같은 참조)
G7Core.shallowArrayEqual([{ id: 1 }], [{ id: 1 }]); // false (다른 참조)

// useControllableState와 함께 사용
const [selectedIds, setSelectedIds] = G7Core.useControllableState<number[]>(
  value,
  [],
  onChange,
  { isEqual: G7Core.shallowArrayEqual }
);
```

> **주의**: 배열 내 객체는 참조로 비교됩니다. 객체 내용까지 비교하려면 커스텀 비교 함수를 작성하세요.

### G7Core.shallowObjectEqual

객체의 얕은 비교를 수행합니다. 1단계 속성만 `===`로 비교합니다.

```typescript
G7Core.shallowObjectEqual<T extends Record<string, any>>(a: T, b: T): boolean
```

#### 동작 원리

1. 두 객체가 같은 참조면 `true` 반환
2. 키 개수가 다르면 `false` 반환
3. 각 키의 값을 `===`로 비교 (얕은 비교)

#### 사용 예시

```typescript
// 기본 속성 비교
G7Core.shallowObjectEqual({ a: 1, b: 2 }, { a: 1, b: 2 });  // true
G7Core.shallowObjectEqual({ a: 1 }, { a: 1, b: 2 });        // false (키 개수 다름)
G7Core.shallowObjectEqual({ a: 1, b: 2 }, { a: 1, b: 3 });  // false (값 다름)

// 중첩 객체 (참조 비교)
const nested = { x: 1 };
G7Core.shallowObjectEqual({ data: nested }, { data: nested });  // true (같은 참조)
G7Core.shallowObjectEqual({ data: { x: 1 } }, { data: { x: 1 } }); // false (다른 참조)

// useControllableState와 함께 사용
const [formData, setFormData] = G7Core.useControllableState<FormData>(
  value,
  { name: '', email: '' },
  onChange,
  { isEqual: G7Core.shallowObjectEqual }
);
```

> **주의**: 중첩 객체는 참조로 비교됩니다. 깊은 비교가 필요하면 커스텀 비교 함수를 작성하세요.

---

## 엔진 안전 스위치 (G7Core.engineFlags)

engine-v1.74.0 에서 들어간 표현식 성능 개선을 브라우저에서 끄고 켜는 스위치입니다. 화면이 개선 전과 다르게 보이는지 확인할 때만 쓰고, 평소에는 건드리지 않습니다. 서버 설정·관리자 화면은 없으며, 값은 그 브라우저 탭에만 적용되고 새로고침하면 기본값으로 돌아갑니다.

| 키 | 기본값 | 끄면 |
|----|--------|------|
| `expressionCompileCache` | `true` | 표현식 전처리 결과를 재사용하지 않고 매번 다시 처리 |
| `astCache` | `true` | 표현식 구문 해석 결과를 재사용하지 않고 매번 다시 해석 |
| `computedScopeInheritance` | `true` | computed 를 같은 상태 범위에서 나눠 쓰지 않고 컴포넌트마다 계산(개선 전 동작) |
| `verifyComputedInheritance` | `false` | (켜면) 나눠 쓴 값과 직접 계산한 값을 비교해 다르면 콘솔에 `[computed-scope-mismatch]` 를 남김 — 진단용 |

```javascript
G7Core.engineFlags.get();                                   // 현재 값 (읽기 전용 객체)
G7Core.engineFlags.set({ computedScopeInheritance: false }); // 알려진 키의 boolean 값만 반영
```

- 캐시는 식의 해석 결과만 담고 값은 담지 않으므로, 스위치를 바꿔도 따로 비울 것이 없습니다.
- 바꾼 값은 다음 화면 갱신(입력·상태 변경·이동)부터 적용됩니다. 이미 그려진 화면을 곧바로 다시 계산하지는 않습니다.
- 페이지가 뜨기 전에 값을 정하려면 엔진이 읽기 전에 `window.G7Config.engineFlags` 에 같은 모양의 객체를 넣어 둡니다(테스트·확장 스크립트용). 엔진이 처음 값을 읽을 때 한 번만 합쳐집니다.

---

## 타입 정의

### 전역 타입 선언

```typescript
declare global {
  interface Window {
    G7Core: {
      // 상태 관리
      state: {
        get: () => Record<string, any>;
        set: (updates: Record<string, any>) => void;
        update: (updater: (prev: Record<string, any>) => Record<string, any>) => void;
        subscribe: (listener: (state: Record<string, any>) => void) => () => void;
        getDataSource: (id: string) => DataSourceValue | undefined;
      };

      // 토스트
      toast: {
        show: (message: string, options?: ToastOptions) => void;
        success: (message: string, duration?: number) => void;
        error: (message: string, duration?: number) => void;
        warning: (message: string, duration?: number) => void;
        info: (message: string, duration?: number) => void;
      };

      // 모달
      modal: {
        open: (modalId: string) => void;
        close: (modalId?: string) => void;
        closeAll: () => void;
        isOpen: (modalId: string) => boolean;
        getStack: () => string[];
      };

      // 네비게이션
      navigation: {
        isPending: () => boolean;
        onComplete: (callback: () => void) => () => void;
      };

      // 다국어
      locale: {
        current: () => string;
        supported: () => string[];
        change: (locale: string) => Promise<void>;
      };
      t: (key: string, params?: Record<string, string | number>) => string;

      // 확장 자산 URL (engine-v1.62.0+)
      asset: {
        template: (identifier: string, path: string, version?: number | string | null) => string;
        templateDir: (identifier: string, path: string) => string;
        module: (identifier: string, path: string, version?: number | string | null) => string;
        plugin: (identifier: string, path: string, version?: number | string | null) => string;
        convertToCurrentMode: (url: string) => string;
        // 출처 게이트 적용 (engine-v1.64.0+) — 미신뢰 URL 은 reject
        loadScript: (url: string, attrs?: Record<string, string>, options?: Record<string, unknown>) => Promise<void>;
        loadStylesheet: (url: string, attrs?: Record<string, string>, options?: Record<string, unknown>) => Promise<void>;
        // 같은 판정을 직접 물어보는 seam (engine-v1.64.0+)
        isAllowedScriptSrc: (url: string) => boolean;
      };

      // 자산 실패 안내 (engine-v1.62.0+)
      assets: {
        notifyFailure: (failure: AssetFailure) => void;
        clearFailure: (id: string) => void;
        clearAll: () => void;
        getFailures: () => AssetFailure[];
        retryAll: () => Promise<void>;
      };
      // 액션
      dispatch: (action: ActionConfig) => Promise<ActionResult>;

      // 이벤트
      componentEvent: {
        on: (eventName: string, callback: (data?: any) => void | Promise<any>) => () => void;
        emit: (eventName: string, data?: any) => Promise<any[]>;
        off: (eventName: string) => void;
        clear: () => void;
      };

      // 이벤트 헬퍼
      createChangeEvent: (value: any, name?: string) => any;
      createClickEvent: (data?: any) => any;
      createSubmitEvent: (formData?: any) => any;
      createKeyboardEvent: (key: string, modifiers?: KeyModifiers) => any;

      // 렌더링 헬퍼
      renderItemChildren: (children: any[], itemContext: Record<string, any>, componentMap: Record<string, any>, keyPrefix?: string, options?: any) => React.ReactNode[];
      renderComponentLayout: (layoutDefs: any[] | undefined, itemContext: Record<string, any>, keyPrefix?: string, options?: { renderEnv?: any; componentContext?: any; responsiveWidth?: number; translationContext?: any; actionDispatcher?: any }) => React.ReactNode;
      renderExpandContent: (config: { children: any[]; row: any; expandContext?: Record<string, any>; componentContext?: any; componentMap?: Record<string, any>; keyPrefix: string; renderEnv?: any; responsiveWidth?: number }) => React.ReactNode;
      getComponentMap: () => Record<string, React.ComponentType>;

      // 스타일 헬퍼
      style: {
        mergeClasses: (base: string, override: string) => string;
        conditionalClass: (conditions: Record<string, boolean>) => string;
        joinClasses: (...classes: (string | false | undefined)[]) => string;
      };

      // 플러그인 설정
      plugin: {
        getSettings: (pluginId: string) => Record<string, any> | undefined;
        get: (pluginId: string, key: string, defaultValue?: any) => any;
        getAll: () => Record<string, Record<string, any>>;
      };

      // 모듈 설정
      module: {
        getSettings: (moduleId: string) => Record<string, any> | undefined;
        get: (moduleId: string, key: string, defaultValue?: any) => any;
        getAll: () => Record<string, Record<string, any>>;
      };

      // WebSocket
      websocket: {
        subscribe: (channel: string, event: string, callback: (data: any) => void, options?: any) => string;
        unsubscribe: (subscriptionKey: string) => void;
        leaveChannel: (channel: string) => void;
        disconnect: () => void;
        isInitialized: () => boolean;
        getSubscriptionCount: () => number;
      };

      // React Hooks
      useControllableState: <T>(
        controlledValue: T | undefined,
        defaultValue: T,
        onChange?: (value: T) => void,
        options?: { isEqual?: (a: T, b: T) => boolean }
      ) => [T, (value: T | ((prev: T) => T)) => void];
      shallowArrayEqual: <T>(a: T[], b: T[]) => boolean;
      shallowObjectEqual: <T extends Record<string, any>>(a: T, b: T) => boolean;
      useResponsive: () => { width: number; isMobile: boolean; isTablet: boolean; isDesktop: boolean; matchedPreset: 'mobile' | 'tablet' | 'desktop' };
      useTransitionState: () => TransitionState;
      useTranslation: () => { t: TranslationFunction };

      // 방문자 동의 (engine-v1.78.0+) — 사용자 템플릿에서만 런타임이 있다
      consent: Readonly<{
        getSnapshot: () => ConsentSnapshot | null;
        refresh: () => Promise<ConsentSnapshot | null>;
        subscribe: (listener: (snapshot: ConsentSnapshot) => void) => () => void;
      }>;

      // 전역 상태 공개 계약 (engine-v1.78.0+)
      stateContracts: Readonly<{
        get: (name: string) => StateContract | null;
        list: () => Readonly<Record<string, StateContract>>;
        ownerOf: (name: string) => string | null;
      }>;

      // 기타
      AuthManager: AuthManagerType;
      api: ApiClientType;
      ResponsiveManager: ResponsiveManagerType;
      TransitionManager: TransitionManagerType;
      engineFlags: {
        get: () => Readonly<{ expressionCompileCache: boolean; astCache: boolean; computedScopeInheritance: boolean; verifyComputedInheritance: boolean }>;
        set: (patch: Partial<{ expressionCompileCache: boolean; astCache: boolean; computedScopeInheritance: boolean; verifyComputedInheritance: boolean }>) => void;
      };
    };
  }
}

interface ConsentSnapshot {
  contractVersion: number; // 현재 1
  status: 'unknown' | 'ready' | 'none';
  categories: Record<string, boolean>; // necessary 는 항상 true
  needsRenewal: boolean;
  policyVersion: string | null;
}

interface StateContract {
  owner: string; // 'core' | 'module:{id}' | 'plugin:{id}' | 'template:{id}'
  description: Record<string, string>;
  schema: Record<string, any>;
  writers: string[]; // '@template' = 활성 템플릿
}
```

`G7Core.consent` 와 `G7Core.stateContracts` 의 동작은 [G7Core 기본 API](g7core-api.md#방문자-동의-g7coreconsent)를 참고합니다.

---

## 관련 문서

- [G7Core 기본 API](g7core-api.md) - 상태 관리, 토스트, 모달, 네비게이션, 플러그인/모듈 설정, 확장 자산, 방문자 동의, 전역 상태 계약
- [state-management.md](state-management.md) - 전역 상태 관리 상세
- [components.md](components.md) - 컴포넌트 개발 규칙
- [data-binding.md](data-binding.md) - 데이터 바인딩 문법
- [auth-system.md](auth-system.md) - 인증 시스템
- [responsive-layout.md](responsive-layout.md) - 반응형 레이아웃
