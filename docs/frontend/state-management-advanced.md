# 상태 관리 - 고급 기능

> **메인 문서**: [state-management.md](state-management.md)
> **관련 문서**: [state-management-forms.md](state-management-forms.md) | [g7core-api.md](g7core-api.md)

---

## 목차

1. [예약된 전역 상태](#예약된-전역-상태)
2. [내부 전역 컨텍스트 변수](#내부-전역-컨텍스트-변수)
3. [실제 사용 사례](#실제-사용-사례)
4. [조건부 렌더링과의 조합](#조건부-렌더링과의-조합)
5. [별도 모듈 상태 동기화 패턴](#별도-모듈-상태-동기화-패턴)
6. [G7Core.state JavaScript API](#g7corestate-javascript-api)
7. [G7Core 편의 API](#g7core-편의-api)

---

## 예약된 전역 상태

템플릿 엔진이 특수한 용도로 사용하는 예약된 전역 상태 속성입니다. 이 속성들은 시스템에서 자동으로 관리되며, 특정 기능과 연동됩니다.

### 예약 속성 목록

| 속성 | 타입 | 용도 | 관련 기능 |
|------|------|------|----------|
| `toasts` | `ToastItem[]` | 토스트 알림 스택 | 토스트 시스템 |
| `modalStack` | `string[]` | 열린 모달 ID 스택 (engine-v1.2.0+) | 멀티 모달 시스템 |
| `activeModal` | `string \| null` | 현재 최상위 모달 ID | 모달 시스템 (하위 호환) |
| `sidebarOpen` | `boolean` | 사이드바 열림 상태 | 반응형 레이아웃 |
| `errorMessage` | `string \| null` | 에러 메시지 (에러 모달용) | 에러 처리 |

### toasts (토스트 시스템)

`_global.toasts`는 화면에 표시할 토스트 알림들을 배열로 관리합니다.

#### ToastItem 구조

```typescript
interface ToastItem {
  id: string;           // 고유 ID (자동 생성)
  type: 'success' | 'error' | 'warning' | 'info';
  message: string;      // 표시할 메시지
  icon?: string;        // 커스텀 아이콘 (선택)
  duration?: number;    // 자동 닫힘 시간 ms (선택)
}
```

#### 동작 방식

1. `toast` 핸들러 호출 시 `toasts` 배열에 새 토스트 객체 추가
2. Toast 컴포넌트가 배열을 구독하여 스택 형태로 렌더링
3. 각 토스트는 개별 타이머로 자동 제거되거나 닫기 버튼으로 수동 제거
4. 제거 시 `G7Core.state.update()`로 배열에서 해당 항목 필터링

#### 레이아웃 JSON 설정 예시

`_admin_base.json`에서 Toast 컴포넌트 바인딩:

```json
{
  "id": "global_toast",
  "type": "composite",
  "name": "Toast",
  "props": {
    "toasts": "{{_global.toasts}}",
    "position": "top-right",
    "duration": 5000
  }
}
```

#### JavaScript API로 토스트 표시

```typescript
// 편의 API (권장)
G7Core.toast.success('저장되었습니다');
G7Core.toast.error('오류가 발생했습니다');
G7Core.toast.warning('주의가 필요합니다');
G7Core.toast.info('정보 메시지');

// duration 지정
G7Core.toast.success('저장 완료', 5000);

// 상세 옵션
G7Core.toast.show('메시지', { type: 'info', icon: 'bell', duration: 3000 });
```

> **참고**: 토스트 핸들러에 대한 자세한 내용은 [actions-handlers.md](actions-handlers.md#toast)를 참조하세요.

### modalStack / activeModal (모달 시스템)

`_global.modalStack`은 열린 모달들의 ID를 스택 형태로 관리합니다. `_global.activeModal`은 하위 호환성을 위해 최상위 모달 ID를 저장합니다.

#### 동작 방식

1. `openModal` 핸들러 호출 시 `modalStack`에 모달 ID가 push됨
2. 동시에 `activeModal`도 해당 ID로 설정됨 (하위 호환성)
3. 템플릿 엔진이 `modals` 배열의 각 모달에 `isOpen` props를 자동 주입
4. `closeModal` 핸들러 호출 시 스택에서 최상위 모달만 제거됨
5. 스택에 이전 모달이 있으면 해당 모달이 다시 표시됨

#### 멀티 모달 (중첩 모달) 지원

```text
✅ 모달 A 열림 → 모달 B 열림 → 모달 B 닫힘 → 모달 A 다시 표시
✅ 에러 모달이 확인 모달 위에 중첩 표시 가능
✅ 스택 기반으로 무제한 중첩 지원
```

**스택 동작 예시**:

```text
1. openModal("confirm_modal")
   → modalStack: ["confirm_modal"]
   → activeModal: "confirm_modal"

2. openModal("error_modal")
   → modalStack: ["confirm_modal", "error_modal"]
   → activeModal: "error_modal"

3. closeModal()
   → modalStack: ["confirm_modal"]
   → activeModal: "confirm_modal"

4. closeModal()
   → modalStack: []
   → activeModal: null
```

#### 사용 예시

```json
{
  "id": "activate_button",
  "type": "basic",
  "name": "Button",
  "props": {
    "children": "활성화"
  },
  "actions": [
    {
      "type": "click",
      "handler": "openModal",
      "params": {
        "modalId": "activate_confirm_modal"
      }
    }
  ]
}
```

#### 모달 정의와의 연동

레이아웃 JSON의 `modals` 배열에 정의된 모달은 `activeModal` 값에 따라 자동으로 표시됩니다:

```json
{
  "modals": [
    {
      "id": "activate_confirm_modal",
      "type": "composite",
      "name": "Modal",
      "props": {
        "title": "확인",
        "width": "400px"
      },
      "children": [...]
    }
  ]
}
```

위 모달은 `_global.activeModal === "activate_confirm_modal"`일 때 자동으로 열립니다.

---

## 내부 전역 컨텍스트 변수

> **버전**: engine-v1.17.4+
> **주의**: 이 변수들은 템플릿 엔진 내부 동작을 위한 것으로, 일반적인 개발에서는 직접 사용하지 않습니다.

템플릿 엔진이 비동기 콜백과 상태 동기화를 위해 사용하는 `window` 전역 변수입니다.

### 내부 변수 목록

| 변수 | 용도 | 설정 시점 | 초기화 시점 |
| ------ | ------ | ---------- | ------------ |
| `__g7ActionContext` | 액션 디스패치 컨텍스트 (setState 함수 포함) | 액션 실행 시 | 페이지 전환 시 |
| `__g7ForcedLocalFields` | 비동기 setLocal fallback에서 업데이트된 필드만 저장 | setLocal fallback 시 | 페이지 전환 시 |
| `__g7PendingLocalState` | 비동기 콜백에서 즉시 읽기 지원용 pending 상태 | setLocal 호출 시 | 페이지 전환 시 |

### __g7ActionContext

액션 디스패치 시 현재 컴포넌트의 컨텍스트를 저장합니다. 커스텀 핸들러에서 `G7Core.state.setLocal()` 호출 시 이 컨텍스트를 사용하여 올바른 컴포넌트 상태를 업데이트합니다.

```typescript
interface ActionContext {
  state: Record<string, any>;    // 현재 컴포넌트 상태 스냅샷
  setState: (updates) => void;   // 컴포넌트 상태 업데이트 함수
  data: Record<string, any>;     // 데이터 컨텍스트 ($event 등)
}
```

**동작 흐름**:

1. 액션 핸들러 실행 시 `__g7ActionContext` 설정
2. 핸들러 내에서 `setLocal()` 호출 시 `setState` 사용
3. 핸들러 완료 후 컨텍스트 복원

### __g7ForcedLocalFields

비동기 콜백(예: 외부 API 팝업의 oncomplete)에서 `setLocal()`을 호출하면 `__g7ActionContext`가 없어 fallback 경로를 사용합니다. 이때 **업데이트된 필드만** 이 변수에 저장됩니다.

```typescript
// 예시: 주소 검색 팝업에서 선택 시
G7Core.state.setLocal({
  'form.basic_info.zipcode': '12345',
  'form.basic_info.base_address': '서울시 강남구'
});

// __g7ForcedLocalFields에 저장됨:
{
  form: {
    basic_info: {
      zipcode: '12345',
      base_address: '서울시 강남구'
    }
  }
}
```

**DynamicRenderer에서의 병합 순서**:

```text
1. dataContext._local (전역 상태)
2. dynamicState (Form 자동 바인딩 - 사용자 입력)
3. __g7ForcedLocalFields (비동기 setLocal - 최우선)
```

이 순서를 통해:

- 비동기 콜백에서 설정한 값이 stale dynamicState를 덮어씀
- 다른 필드의 사용자 입력은 dynamicState에서 보존됨

### __g7PendingLocalState

`setLocal()` 호출 직후 같은 동기 흐름에서 `getLocal()`로 값을 읽을 때 최신 값을 반환하기 위한 pending 상태입니다.

```typescript
// setLocal 후 즉시 getLocal 호출 시
G7Core.state.setLocal({ 'checkout.zipcode': '12345' });
const current = G7Core.state.getLocal();  // __g7PendingLocalState에서 최신 값 반환
```

### __g7LastSetLocalSnapshot (engine-v1.21.2+)

`setLocal()` 호출 시 설정되는 스냅샷으로, `__g7PendingLocalState`가 클리어된 후에도 `getLocal()`이 최신 값을 반환할 수 있도록 보장합니다.

**배경**: `await` 경계에서 React 18 마이크로태스크 배칭이 렌더를 플러시하면, `useLayoutEffect`가 `__g7PendingLocalState = null`로 클리어합니다. 이후 `getLocal()` 호출 시 stale한 `globalLocal`만 반환되는 문제가 있었습니다.

**동작 원리**:

```typescript
// getLocal() fallback 우선순위:
// 1. __g7PendingLocalState (동일 이벤트 루프)
// 2. __g7LastSetLocalSnapshot (await 이후에도 유지)
// 3. globalLocal (기본값)
```

**클리어 시점**: `dataContext._local`이 갱신되면(= React 커밋 완료) `queueMicrotask`로 클리어됩니다. `handleLocalSetState`에서는 참조하지 않으므로 기존 stale 오염 방지 로직(`__g7SetLocalOverrideKeys`)과 충돌하지 않습니다.

### 페이지 전환 시 초기화

페이지 전환(navigate) 시 모든 내부 컨텍스트 변수가 초기화됩니다:

```typescript
// TemplateApp.handleRouteChange()에서
(window as any).__g7ForcedLocalFields = undefined;
(window as any).__g7ActionContext = undefined;
(window as any).__g7PendingLocalState = undefined;
```

이 초기화가 없으면 이전 페이지의 stale 컨텍스트가 새 페이지에 영향을 줄 수 있습니다.

### 디버깅 시 활용

브라우저 콘솔에서 현재 상태 확인:

```javascript
// 현재 액션 컨텍스트 확인
console.log(window.__g7ActionContext);

// 강제 적용된 로컬 필드 확인
console.log(window.__g7ForcedLocalFields);

// pending 상태 확인
console.log(window.__g7PendingLocalState);
```

#### 자동 주입되는 Props

템플릿 엔진이 모달 컴포넌트에 자동으로 주입하는 props:

| prop | 설명 |
|------|------|
| `isOpen` | `_global.activeModal === modalId` 평가 결과 |
| `onClose` | `closeModal` 핸들러 호출 함수 |

#### 직접 상태 조작 (비권장)

`setState`로 직접 조작할 수도 있지만, `openModal`/`closeModal` 핸들러 사용을 권장합니다:

```json
{
  "actions": [
    {
      "event": "onClick",
      "type": "setState",
      "target": "global",
      "payload": {
        "activeModal": "my_modal_id"
      }
    }
  ]
}
```

> **참고**: 모달 시스템에 대한 자세한 내용은 [layout-json-features.md](layout-json-features.md#모달-시스템-modals)를 참조하세요.

---

## 실제 사용 사례

### 1. 모바일 사이드바 토글

```json
{
  "id": "mobile_menu_button",
  "type": "basic",
  "name": "Button",
  "props": {
    "className": "p-2 hover:bg-gray-100 rounded-lg"
  },
  "actions": [
    {
      "event": "onClick",
      "type": "setState",
      "target": "global",
      "payload": {
        "sidebarOpen": "{{!_global.sidebarOpen}}"
      }
    }
  ]
}
```

### 2. 오버레이 닫기

```json
{
  "id": "overlay",
  "type": "basic",
  "name": "Div",
  "if": "{{_global.sidebarOpen}}",
  "props": {
    "className": "fixed inset-0 bg-black bg-opacity-50 z-40"
  },
  "actions": [
    {
      "event": "onClick",
      "type": "setState",
      "target": "global",
      "payload": {
        "sidebarOpen": false
      }
    }
  ]
}
```

### 3. 다크모드 토글

```json
{
  "id": "theme_toggle",
  "type": "basic",
  "name": "Button",
  "props": {
    "className": "p-2 rounded-lg"
  },
  "actions": [
    {
      "event": "onClick",
      "type": "setState",
      "target": "global",
      "payload": {
        "theme": "{{_global.theme == 'dark' ? 'light' : 'dark'}}"
      }
    }
  ]
}
```

### 주요 사용 사례 목록

- 모바일 사이드바 토글 상태
- 다크모드 on/off 상태
- 전역 로딩 상태
- 모달/드로어 열림/닫힘 상태
- 사용자 선택 언어/테마
- 탭/메뉴 선택 상태

---

## 조건부 렌더링과의 조합

전역 상태와 `if` 조건을 조합하여 동적 UI를 구현할 수 있습니다.

```json
{
  "id": "mobile_overlay",
  "type": "basic",
  "name": "Div",
  "if": "{{_global.sidebarOpen}}",
  "props": {
    "className": "fixed inset-0 bg-black bg-opacity-50 z-40"
  },
  "actions": [
    {
      "event": "onClick",
      "type": "setState",
      "target": "global",
      "payload": {
        "sidebarOpen": false
      }
    }
  ]
}
```

위 예시에서:

1. `if`: `_global.sidebarOpen`이 `true`일 때만 렌더링
2. `actions`: 클릭 시 `sidebarOpen`을 `false`로 설정하여 오버레이 닫기

---

## 별도 모듈 상태 동기화 패턴

```text
중요: 별도 모듈에서 상태를 캐싱하는 경우 동기화 필요
✅ 필수: update 메서드를 제공하여 최신 값 반영
```

### 문제 상황

별도 클래스나 모듈에서 `locale`, `globalState` 등을 생성자에서 받아 저장하면, 나중에 상태가 변경되어도 초기화 시점의 값이 계속 사용됩니다.

```typescript
// ❌ 문제: 초기화 시점의 locale이 고정됨
class ErrorPageHandler {
    private locale: string;

    constructor(options: { locale: string }) {
        this.locale = options.locale;  // 초기화 시점 값 고정
    }

    async renderError() {
        // this.locale은 항상 초기값 사용 (언어 변경 미반영)
        await this.renderFunction({
            translationContext: { locale: this.locale }
        });
    }
}
```

### 해결 패턴

update 메서드를 제공하여 상태 변경 시점에 명시적으로 동기화합니다:

```typescript
// ✅ 해결: update 메서드로 동기화
class ErrorPageHandler {
    private locale: string;
    private globalState: Record<string, unknown>;

    constructor(options: { locale: string; globalState?: Record<string, unknown> }) {
        this.locale = options.locale;
        this.globalState = options.globalState || {};
    }

    // 상태 업데이트 메서드 제공
    updateLocale(locale: string): void {
        this.locale = locale;
    }

    updateGlobalState(state: Record<string, unknown>): void {
        this.globalState = { ...this.globalState, ...state };
    }
}
```

### 호출 시점

상태가 변경되거나 모듈을 사용하기 전에 update 메서드를 호출합니다:

```typescript
// 1. 상태 변경 시점에서 동기화
async changeLocale(locale: string): Promise<void> {
    this.config.locale = locale;

    // 관련 모듈에 변경 사항 전파
    if (this.errorPageHandler) {
        this.errorPageHandler.updateLocale(locale);
    }
}

// 2. 모듈 사용 직전에 최신 상태 전달
async handleRouteNotFound(path: string): Promise<void> {
    if (this.errorPageHandler) {
        // 사용 전 최신 상태로 동기화
        this.errorPageHandler.updateGlobalState(this.globalState);
        this.errorPageHandler.updateLocale(this.config.locale);

        await this.errorPageHandler.renderError(404, 'app');
    }
}
```

### 적용 대상

이 패턴이 필요한 경우:

| 대상 | 동기화 항목 | update 메서드 |
|------|------------|---------------|
| ErrorPageHandler | locale, globalState | `updateLocale()`, `updateGlobalState()` |
| 커스텀 핸들러 | 설정값, 컨텍스트 | 필요에 따라 구현 |

### 대안: 참조 전달

매번 동기화하는 대신, 객체 참조를 전달하여 자동 동기화할 수도 있습니다:

```typescript
// 대안: 설정 객체 참조 전달
class ErrorPageHandler {
    private config: TemplateAppConfig;  // 참조로 전달

    constructor(options: { config: TemplateAppConfig }) {
        this.config = options.config;  // 원본 객체 참조
    }

    async renderError() {
        // this.config.locale은 항상 최신 값 (원본 객체 참조)
        await this.renderFunction({
            translationContext: { locale: this.config.locale }
        });
    }
}
```

> **주의**: 참조 전달 방식은 의도치 않은 부작용이 발생할 수 있으므로, 명시적 update 메서드 방식을 권장합니다.

---

## G7Core.state JavaScript API

컴포넌트 코드(TypeScript/JavaScript)에서 전역 상태에 직접 접근해야 할 때 사용하는 API입니다.

### API 목록

| 메서드 | 설명 | 반환값 |
|--------|------|--------|
| `G7Core.state.get()` | 현재 전역 상태 반환 | `Record<string, any>` |
| `G7Core.state.set(updates)` | 상태 객체로 업데이트 | `void` |
| `G7Core.state.update(updater)` | 함수형 업데이트 (현재 상태 기반) | `void` |
| `G7Core.state.subscribe(listener)` | 상태 변경 구독 | 구독 해제 함수 |
| `G7Core.state.getDataSource(id)` | 데이터 소스 값 조회 | `DataSourceValue \| undefined` |

### G7Core.state.get()

현재 전역 상태를 반환합니다.

```typescript
const state = G7Core.state.get();
console.log(state.toasts);      // 토스트 배열
console.log(state.sidebarOpen); // 사이드바 상태
```

### G7Core.state.set(updates)

상태 객체를 병합하여 업데이트합니다.

**dot notation 지원** (engine-v1.2.0+): 중첩된 객체 경로를 dot notation으로 표현할 수 있습니다.

```typescript
// 단순 값 설정
G7Core.state.set({ sidebarOpen: true });

// 여러 값 동시 설정
G7Core.state.set({
  theme: 'dark',
  locale: 'en'
});

// dot notation 지원 (중첩 객체)
G7Core.state.set({
  "filter.orderStatus": ["paid", "shipped"]
});
// 결과: { filter: { orderStatus: ["paid", "shipped"] } }

// 기존 중첩 객체와 깊은 병합
// 현재 상태: { filter: { dateRange: "2024-01-01~2024-12-31" } }
G7Core.state.set({
  "filter.orderStatus": ["paid"]
});
// 결과: { filter: { dateRange: "2024-01-01~2024-12-31", orderStatus: ["paid"] } }
```

**주의사항**:

- dot notation 경로는 자동으로 중첩 객체로 변환됩니다
- 기존 중첩 객체와 깊은 병합(deep merge)되므로 다른 속성이 유지됩니다
- 배열은 병합되지 않고 교체됩니다

### G7Core.state.update(updater)

현재 상태를 기반으로 새 상태를 계산해야 할 때 사용합니다. React의 함수형 setState와 유사합니다.

```typescript
// 배열에서 항목 제거
G7Core.state.update(prev => ({
  toasts: prev.toasts.filter(t => t.id !== targetId)
}));

// 카운터 증가
G7Core.state.update(prev => ({
  count: (prev.count || 0) + 1
}));

// 배열에 항목 추가
G7Core.state.update(prev => ({
  items: [...(prev.items || []), newItem]
}));
```

### G7Core.state.subscribe(listener)

상태 변경을 구독합니다. 반환된 함수를 호출하면 구독이 해제됩니다.

```typescript
// 구독 시작
const unsubscribe = G7Core.state.subscribe((state) => {
  console.log('상태 변경:', state);
});

// 구독 해제
unsubscribe();
```

### G7Core.state.getDataSource(dataSourceId)

> **버전**: engine-v1.3.0+

데이터 소스의 현재 값을 반환합니다. 커스텀 핸들러나 컴포넌트에서 데이터 소스에 접근할 때 사용합니다.

```typescript
// 데이터 소스 조회
const menus = G7Core.state.getDataSource('menus');
console.log(menus?.data);  // API 응답 데이터

// 로딩 상태 확인
const users = G7Core.state.getDataSource('users');
if (users?.loading) {
  console.log('로딩 중...');
}
```

#### 반환값 구조

```typescript
interface DataSourceValue {
  data: any;           // API 응답 데이터
  loading: boolean;    // 로딩 중 여부
  error?: Error;       // 에러 객체 (실패 시)
  lastFetched?: Date;  // 마지막 fetch 시간
}
```

#### 사용 사례

- **커스텀 핸들러**에서 데이터 소스 값 참조
- **URL 파라미터 초기화** 시 데이터 로드 대기
- **컴포넌트 코드**에서 데이터 소스 직접 접근

#### 데이터 소스 대기 패턴

데이터 소스가 로드될 때까지 대기해야 하는 경우:

```typescript
async function waitForDataSource(
  dataSourceId: string,
  maxAttempts: number = 30,
  interval: number = 100
): Promise<any[] | null> {
  for (let i = 0; i < maxAttempts; i++) {
    const dataSource = G7Core.state.getDataSource(dataSourceId);
    const data = dataSource?.data;
    if (Array.isArray(data) && data.length > 0) {
      return data;
    }
    await new Promise((resolve) => setTimeout(resolve, interval));
  }
  return null;
}

// 사용
const menusData = await waitForDataSource('menus');
```

#### get()과 getDataSource()의 차이

| 메서드 | 대상 | 반환값 | 사용 시점 |
|--------|------|--------|----------|
| `get()` | 전역 상태 (`_global`) | `Record<string, any>` | UI 상태 (사이드바, 모달 등) |
| `getDataSource(id)` | 데이터 소스 | `DataSourceValue` | API 응답 데이터 |

#### getDataSource 주의사항

| 항목 | 설명 |
|------|------|
| undefined 반환 가능 | 데이터 소스가 없거나 아직 로드되지 않은 경우 |
| `get()`과 다름 | `get()`은 전역 상태, `getDataSource()`는 데이터 소스 |
| ✅ 실시간 값 | 항상 최신 데이터 소스 값 반환 |

### JavaScript API 사용 시점

| 사용 O | 사용 X |
|--------|--------|
| 컴포넌트 내부에서 전역 상태 접근 | 레이아웃 JSON에서 상태 접근 (→ `{{_global.xxx}}` 사용) |
| 복잡한 상태 계산이 필요한 경우 | 단순 토글 (→ setState 액션 사용) |
| 외부 라이브러리와 상태 연동 | 일반적인 UI 상태 관리 |

---

## G7Core 편의 API

템플릿 엔진은 자주 사용하는 기능에 대한 편의 API를 제공합니다.

> 전체 API 레퍼런스: [g7core-api.md](g7core-api.md)

### G7Core.toast - 토스트 알림

```typescript
// 편의 메서드
G7Core.toast.success('저장되었습니다');
G7Core.toast.error('오류가 발생했습니다');
G7Core.toast.warning('주의가 필요합니다');
G7Core.toast.info('정보 메시지');

// duration 지정 (밀리초)
G7Core.toast.success('저장 완료', 3000);

// 상세 옵션
G7Core.toast.show('메시지', { type: 'info', duration: 5000 });
```

### G7Core.modal - 모달 관리

```typescript
// 모달 열기/닫기
G7Core.modal.open('confirm_modal');
G7Core.modal.close();  // 최상위 모달 닫기
G7Core.modal.close('confirm_modal');  // 특정 모달 닫기
G7Core.modal.closeAll();  // 모든 모달 닫기

// 상태 확인
G7Core.modal.isOpen('confirm_modal');  // boolean
G7Core.modal.getStack();  // ['modal1', 'modal2']
```

### G7Core.navigation - 네비게이션 상태

```typescript
// 전환 중 여부 확인
G7Core.navigation.isPending();  // boolean

// 전환 완료 시 콜백 실행
const unsubscribe = G7Core.navigation.onComplete(() => {
  console.log('페이지 전환 완료');
});
```

### G7Core.componentEvent - 컴포넌트 간 이벤트

```typescript
// 이벤트 구독
const unsubscribe = G7Core.componentEvent.on('eventName', (data) => {
  console.log('이벤트 수신:', data);
});

// 이벤트 발생
await G7Core.componentEvent.emit('eventName', { key: 'value' });

// 리스너 제거
G7Core.componentEvent.off('eventName');
G7Core.componentEvent.clear();  // 모든 리스너 제거
```

---

## 관련 문서

- [상태 관리 개요](state-management.md) - _global, _local 기본 개념
- [폼 자동 바인딩](state-management-forms.md) - FormContext, setState, 깊은 병합
- [G7Core API 전체](g7core-api.md) - 렌더링, 인증, 반응형 등
- [액션 핸들러](actions-handlers.md) - toast, openModal, setState 핸들러
