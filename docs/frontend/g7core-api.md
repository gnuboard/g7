# G7Core 전역 API 레퍼런스

> **버전**: engine-v1.4.0+
> **관련 문서**: [state-management.md](state-management.md) | [components.md](components.md) | [data-binding.md](data-binding.md)

---

## TL;DR (5초 요약)

```text
1. G7Core.state: get/set/subscribe 전역 상태 관리
2. G7Core.toast: success/error/info/warning 알림
3. G7Core.modal: open/close 모달 제어
4. G7Core.t(): 다국어 번역 함수
5. G7Core.dispatch(): 액션 프로그래밍 실행
```

---

## 분리된 문서

이 문서는 가독성을 위해 다음과 같이 분리되었습니다:

| 문서 | 내용 |
|------|------|
| **g7core-api.md** (현재) | 개요, 상태 관리, 토스트 알림, 모달 관리, 네비게이션, 스타일 헬퍼, 플러그인/모듈 설정, 위지윅 편집기 |
| [g7core-api-advanced.md](g7core-api-advanced.md) | 다국어, 액션 실행, 컴포넌트 이벤트, 이벤트 생성 헬퍼, 렌더링 헬퍼, 인증/API, WebSocket, 반응형, React Hooks, 타입 정의 |

---

## 목차

1. [개요](#개요)
2. [상태 관리 (G7Core.state)](#상태-관리-g7corestate)
3. [토스트 알림 (G7Core.toast)](#토스트-알림-g7coretoast)
4. [모달 관리 (G7Core.modal)](#모달-관리-g7coremodal)
5. [네비게이션 (G7Core.navigation)](#네비게이션-g7corenavigation)
6. [스타일 헬퍼 (G7Core.style)](#스타일-헬퍼-g7corestyle)
7. [플러그인 설정 (G7Core.plugin)](#플러그인-설정-g7coreplugin)
8. [모듈 설정 (G7Core.module)](#모듈-설정-g7coremodule)
9. [위지윅 편집기 (G7Core.wysiwyg)](#위지윅-편집기-g7corewysiwyg)

---

## 개요

`G7Core`는 템플릿 엔진이 전역으로 노출하는 API 네임스페이스입니다. 컴포넌트 코드(TypeScript/JavaScript)에서 템플릿 엔진의 기능에 접근할 때 사용합니다.

### API 카테고리

| 카테고리 | API | 설명 | 문서 |
|----------|-----|------|------|
| 상태 관리 | `G7Core.state` | 전역 상태 조회/설정/구독 | 현재 문서 |
| UI 알림 | `G7Core.toast` | 토스트 알림 표시 | 현재 문서 |
| 모달 | `G7Core.modal` | 모달 열기/닫기 | 현재 문서 |
| 네비게이션 | `G7Core.navigation` | 페이지 전환 상태 | 현재 문서 |
| 스타일 | `G7Core.style` | Tailwind 클래스 병합 | 현재 문서 |
| 플러그인 | `G7Core.plugin` | 플러그인 설정 조회 | 현재 문서 |
| 모듈 | `G7Core.module` | 모듈 설정 조회 | 현재 문서 |
| 다국어 | `G7Core.locale`, `G7Core.t` | 로케일 관리, 번역 | [고급 API](g7core-api-advanced.md) |
| 액션 | `G7Core.dispatch` | 액션 실행 | [고급 API](g7core-api-advanced.md) |
| 이벤트 | `G7Core.componentEvent` | 컴포넌트 간 이벤트 | [고급 API](g7core-api-advanced.md) |
| 헬퍼 | `G7Core.create*Event` | 이벤트 객체 생성 | [고급 API](g7core-api-advanced.md) |
| 렌더링 | `G7Core.renderItemChildren` | 반복 아이템 렌더링 | [고급 API](g7core-api-advanced.md) |
| 인증 | `G7Core.AuthManager` | 인증 상태 관리 | [고급 API](g7core-api-advanced.md) |
| API | `G7Core.api` | API 클라이언트 | [고급 API](g7core-api-advanced.md) |
| WebSocket | `G7Core.websocket` | 실시간 통신 | [고급 API](g7core-api-advanced.md) |
| 위지윅 | `G7Core.wysiwyg` | 레이아웃 편집기 | 현재 문서 |
| React Hooks | `G7Core.useControllableState` | 상태 패턴 훅 | [고급 API](g7core-api-advanced.md) |

### 사용 컨텍스트

```typescript
// G7Core는 window 객체에 노출됨
const G7Core = (window as any).G7Core;

// 또는 타입 안전하게 접근
const toast = (window as any).G7Core?.toast;
```

---

## 상태 관리 (G7Core.state)

전역 상태를 관리하는 API입니다.

> 상세 내용: [state-management.md](state-management.md#g7corestate-javascript-api)

### API 목록

| 메서드 | 설명 | 반환값 |
|--------|------|--------|
| `get()` | 현재 전역 상태 반환 | `Record<string, any>` |
| `set(updates, options?)` | 상태 객체로 업데이트 (dot notation 지원, merge 옵션) | `void` |
| `getGlobal()` | `get()`의 별칭 (하위 호환성) | `Record<string, any>` |
| `setGlobal(updates)` | `set()`의 별칭 (하위 호환성) | `void` |
| `setLocal(updates, options?)` | 컴포넌트 로컬 상태 업데이트 (dot notation, scope 옵션 지원) | `void` |
| `getLocal()` | 컴포넌트 로컬 상태 조회 | `Record<string, any>` |
| `getIsolated(scopeId)` | 격리된 스코프 상태 조회 (engine-v1.14.0+) | `Record<string, any> \| null` |
| `setIsolated(scopeId, updates, options?)` | 격리된 스코프 상태 업데이트 (engine-v1.14.0+, merge 옵션) | `void` |
| `update(updater)` | 함수형 업데이트 | `void` |
| `subscribe(listener)` | 상태 변경 구독 | 구독 해제 함수 |
| `getDataSource(id)` | 데이터 소스 값 조회 | `DataSourceValue \| undefined` |

### setLocal / getLocal (engine-v1.12.0+)

커스텀 핸들러에서 컴포넌트의 로컬 상태(`_local`)를 직접 업데이트할 때 사용합니다.

**시그니처**:

```typescript
setLocal(updates: Record<string, any>, options?: {
  scope?: 'current' | 'parent' | 'root';
  merge?: 'deep' | 'shallow' | 'replace';  // engine-v1.18.0+ (기본: 'deep')
  debounce?: number;         // engine-v1.41.0+ — 디바운스 지연 시간 (ms)
  debounceKey?: string;      // engine-v1.41.0+ — 디바운스 고유 키
  render?: boolean;          // engine-v1.42.0+ (기본: true) — false이면 React 리렌더 건너뜀
}): void
getLocal(): Record<string, any>
```

**동작 원리**:

- 액션 실행 중: 컴포넌트의 `dynamicState`를 직접 업데이트하여 즉시 UI에 반영
- 액션 외부: 전역 `_local` 업데이트 (fallback)

**render: false** (engine-v1.42.0+): 값은 `globalState._local`에 저장하되 React 리렌더를 건너뜁니다.
외부 라이브러리(CKEditor 등)가 자체 DOM을 관리하는 경우, React 트리 리렌더가 불필요하므로 성능을 대폭 개선합니다.
`flushPendingDebounceTimers` 실행 시(저장 직전)에는 항상 render: true로 강제되어 데이터 정합성이 보장됩니다.

```typescript
// 외부 라이브러리에서 자체 DOM을 관리하는 경우 — 리렌더 없이 값만 저장
G7Core.state.setLocal({
  [`form.${name}.${locale}`]: html,
  hasChanges: true,
}, {
  debounce: 300,
  debounceKey: `editor-sync-${name}`,
  render: false,
});
```

**dot notation 지원** (engine-v1.2.0+): 중첩된 객체 경로를 dot notation으로 표현할 수 있습니다.

```typescript
// 커스텀 핸들러에서 사용
export function toggleOptionHandler(action: any, context: any): void {
  const G7Core = (window as any).G7Core;

  // 현재 로컬 상태 가져오기
  const currentLocal = G7Core.state.getLocal();

  // 로컬 상태 업데이트
  G7Core.state.setLocal({
    selectedItems: [...currentLocal.selectedItems, newItem],
    isLoading: false,
  });

  // dot notation 지원 (중첩 객체)
  G7Core.state.setLocal({
    "filter.orderStatus": ["paid", "shipped"]
  });
  // 결과: { filter: { orderStatus: ["paid", "shipped"] } }
}
```

**scope 옵션 (engine-v1.15.0+)**: 모달이나 중첩 레이아웃에서 부모/루트 컨텍스트의 `_local`에 접근할 때 사용합니다.

| scope 값 | 설명 | 사용 시점 |
|----------|------|----------|
| `'current'` (기본값) | 현재 컨텍스트의 `_local` 업데이트 | 일반적인 상태 업데이트 |
| `'parent'` | 부모 레이아웃의 `_local` 업데이트 | 모달에서 부모 상태 업데이트 시 |
| `'root'` | 최상위 레이아웃의 `_local` 업데이트 | 중첩 모달에서 최상위 상태 업데이트 시 |

```typescript
// 모달 확인 버튼 핸들러에서 부모 상태 업데이트
export function updateParentFormHandler(action: any, context: any): void {
  const G7Core = (window as any).G7Core;
  const form = action.params?.form;

  // 부모 레이아웃의 _local.form.fields 업데이트
  G7Core.state.setLocal({
    'form.fields': updatedFields,
  }, { scope: 'parent' });

  G7Core.toast.success('변경되었습니다');
}
```

**scope 사용 시 주의사항**:

- `openModal` 시 부모 컨텍스트가 `__g7LayoutContextStack`에 push됨
- `closeModal` 시 스택에서 pop됨
- **sequence 순서가 중요**: 핸들러가 `closeModal`보다 먼저 실행되어야 함
  - ❌ `closeModal` → `handler`: 핸들러 실행 시 스택이 비어있음
  - ✅ `handler` → `closeModal`: 핸들러 실행 시 부모 컨텍스트 존재

**주의사항**:

- ActionDispatcher의 액션 실행 중에만 `dynamicState` 업데이트가 동작합니다
- 액션 외부에서 호출하면 전역 `_local`이 업데이트됩니다
- `expandChildren` 내부 액션에서도 정상 동작합니다 (componentContext 자동 전달)
- dot notation 경로는 자동으로 중첩 객체로 변환되어 깊은 병합됩니다
- `scope: 'parent'/'root'` 사용 시 컨텍스트 스택이 비어있으면 `current`로 폴백됩니다
- `merge` 옵션 (engine-v1.18.0+): 병합 방식을 지정합니다 (`'deep'` | `'shallow'` | `'replace'`, 기본: `'deep'`)

**merge 옵션 사용 예시**:

```typescript
// replace: 기존 _local을 완전히 교체
G7Core.state.setLocal({
  formData: { name: '', price: 0 }
}, { merge: 'replace' });

// shallow: 최상위 키만 덮어쓰기
G7Core.state.setLocal({
  filter: { status: 'all', keyword: '' }
}, { merge: 'shallow' });
```

### getIsolated / setIsolated (engine-v1.14.0+)

격리된 스코프의 상태를 조회하거나 업데이트합니다. `scopeId`로 특정 격리 스코프를 식별합니다.

**사용 시점**:

- 커스텀 핸들러에서 특정 격리 스코프의 상태에 접근해야 할 때
- 컴포넌트 외부에서 격리된 상태를 프로그래밍 방식으로 조작해야 할 때

```typescript
// 커스텀 핸들러에서 사용
export function categorySelectHandler(action: any, context: any): void {
  const G7Core = (window as any).G7Core;

  // 격리 상태 조회
  const state = G7Core.state.getIsolated('category-selector');
  console.log(state?.selectedCategories);

  // 격리 상태 업데이트
  G7Core.state.setIsolated('category-selector', {
    currentStep: 2,
    selectedCategories: [...state.selectedCategories, newCategory],
  });
}
```

**주의사항**:

- `scopeId`는 레이아웃 JSON의 `isolatedScopeId` 속성과 일치해야 합니다
- 해당 `scopeId`의 격리 스코프가 마운트되지 않은 경우 `getIsolated`는 `null`을 반환합니다
- `setIsolated`는 스코프가 없으면 경고 로그를 출력하고 무시합니다
- 대부분의 경우 액션 context의 `isolatedContext`를 사용하는 것이 권장됩니다
- `options.merge` (engine-v1.18.0+): 병합 방식 지정 (`'deep'` | `'shallow'` | `'replace'`, 기본: `'deep'`)

**레이아웃에서 scopeId 정의**:

```json
{
  "type": "Div",
  "isolatedState": {
    "selectedCategories": [],
    "currentStep": 1
  },
  "isolatedScopeId": "category-selector",
  "children": [...]
}
```

### 사용 예시

```typescript
// 상태 조회
const state = G7Core.state.get();
console.log(state.sidebarOpen);

// 상태 설정 (기본: 깊은 병합)
G7Core.state.set({ theme: 'dark' });

// merge 옵션 (engine-v1.18.0+)
G7Core.state.set({ theme: 'dark', sidebar: { open: true } }, { merge: 'shallow' });
G7Core.state.set({ theme: 'light' }, { merge: 'replace' });

// 함수형 업데이트 (현재 상태 기반)
G7Core.state.update(prev => ({
  count: (prev.count || 0) + 1
}));

// 상태 변경 구독
const unsubscribe = G7Core.state.subscribe((state) => {
  console.log('상태 변경:', state);
});

// 데이터 소스 조회 (deprecated - G7Core.dataSource.get() 사용 권장)
const users = G7Core.state.getDataSource('users');
console.log(users?.data);
```

---

## 데이터소스 관리 (G7Core.dataSource)

데이터소스의 조회, 설정, refetch를 관리하는 API입니다.

> **engine-v1.5.0+** 추가

### API 목록

| 메서드 | 설명 | 반환값 |
| ------ | ---- | ------ |
| `get(id)` | 데이터소스 값 조회 | `any \| undefined` |
| `set(id, data, options?)` | 데이터소스 값 설정 + UI 리렌더링 | `void` |
| `updateItem(id, path, itemId, updates, options?)` | 배열 내 특정 아이템 부분 업데이트 | `boolean` |
| `refetch(id, options?)` | 서버에서 데이터 다시 가져오기 | `Promise<any>` |

### set() 옵션

| 옵션 | 타입 | 기본값 | 설명 |
| ---- | ---- | ------ | ---- |
| `merge` | `boolean` | `false` | `true`면 기존 데이터와 shallow merge |
| `sync` | `boolean` | `false` | `true`면 동기 업데이트 |

### updateItem() (engine-v1.13.0+)

배열 내 특정 아이템만 업데이트하여 성능을 최적화합니다.

**시그니처**:

```typescript
updateItem(
  dataSourceId: string,
  itemPath: string,
  itemId: string | number,
  updates: Record<string, any>,
  options?: { idField?: string; merge?: boolean; skipRender?: boolean }
): boolean
```

**파라미터**:

| 파라미터 | 타입 | 설명 |
| -------- | ---- | ---- |
| `dataSourceId` | `string` | 데이터소스 ID |
| `itemPath` | `string` | 배열 경로 (예: "data.data", "data.data[0].options") |
| `itemId` | `string \| number` | 아이템 식별자 |
| `updates` | `object` | 업데이트할 필드 |

**옵션**:

| 옵션 | 타입 | 기본값 | 설명 |
| ---- | ---- | ------ | ---- |
| `idField` | `string` | `"id"` | ID 필드명 |
| `merge` | `boolean` | `true` | 깊은 병합 여부 |
| `skipRender` | `boolean` | `false` | 렌더링 스킵 여부 |

**예시**:

```typescript
// 상품 옵션의 판매가 업데이트
G7Core.dataSource.updateItem(
  'products',
  'data.data[0].options',
  123,
  { selling_price: 15000, _modified: true }
);

// uuid 필드 기준 사용자 업데이트
G7Core.dataSource.updateItem(
  'users',
  'data',
  'user-456',
  { name: '홍길동' },
  { idField: 'uuid' }
);
```

### 사용 예시

```typescript
// 데이터소스 조회
const products = G7Core.dataSource.get('products');
console.log(products?.data?.data);  // API 응답 구조에 따라 다름

// 데이터소스 설정 (전체 교체)
G7Core.dataSource.set('products', {
    ...currentData,
    data: {
        ...currentData.data,
        data: updatedProductsArray,
    },
});

// 데이터소스 설정 (병합)
G7Core.dataSource.set('products', { data: updatedData }, { merge: true });

// 서버에서 다시 가져오기
await G7Core.dataSource.refetch('products');

// 캐시 무시하고 다시 가져오기
await G7Core.dataSource.refetch('products', { skipCache: true });
```

### 데이터소스 vs 전역 상태

```text
┌─────────────────────────────────────────────────────────────┐
│  두 가지 데이터 저장소                                        │
├─────────────────────────────────────────────────────────────┤
│  1. 데이터소스 (G7Core.dataSource)                           │
│     - API 응답 저장                                          │
│     - {{products?.data?.data}}로 바인딩                      │
│     - 인라인 편집 등 런타임 수정 시 사용                       │
│                                                              │
│  2. 전역 상태 (G7Core.state)                                 │
│     - _global, _local 상태 저장                              │
│     - UI 상태 (필터, 선택 등) 관리                            │
│     - initGlobal로 데이터소스 복사 가능 (읽기 전용 용도)       │
└─────────────────────────────────────────────────────────────┘
```

### 사용 시점

| 상황 | 사용 API |
| ---- | -------- |
| 인라인 편집, 실시간 데이터 수정 | `G7Core.dataSource.set()` |
| 서버 동기화 필요 | `G7Core.dataSource.refetch()` |
| UI 상태 (필터, 선택, 토글) | `G7Core.state.set()` |
| 초기 데이터 읽기 전용 복사 | `initGlobal` 옵션 |

---

## 토스트 알림 (G7Core.toast)

토스트 알림을 표시하는 편의 API입니다.

### API 목록

| 메서드 | 설명 | 파라미터 |
|--------|------|----------|
| `show(message, options?)` | 토스트 표시 | message, { type, duration } |
| `success(message, duration?)` | 성공 토스트 | message, duration(ms) |
| `error(message, duration?)` | 에러 토스트 | message, duration(ms) |
| `warning(message, duration?)` | 경고 토스트 | message, duration(ms) |
| `info(message, duration?)` | 정보 토스트 | message, duration(ms) |

### 토스트 타입

| 타입 | 설명 | 색상 |
|------|------|------|
| `success` | 성공 메시지 | 녹색 |
| `error` | 에러 메시지 | 빨간색 |
| `warning` | 경고 메시지 | 노란색 |
| `info` | 정보 메시지 | 파란색 |

### 사용 예시

```typescript
// 편의 메서드 (권장)
G7Core.toast.success('저장되었습니다');
G7Core.toast.error('오류가 발생했습니다');
G7Core.toast.warning('주의가 필요합니다');
G7Core.toast.info('정보 메시지');

// duration 지정 (밀리초)
G7Core.toast.success('저장 완료', 3000);  // 3초 후 자동 닫힘

// 상세 옵션
G7Core.toast.show('메시지', {
  type: 'info',
  duration: 5000
});
```

### 내부 동작

토스트 API는 내부적으로 `G7Core.dispatch`를 호출하여 `toast` 핸들러를 실행합니다:

```typescript
// G7Core.toast.success('메시지')는 내부적으로:
G7Core.dispatch({
  handler: 'toast',
  params: {
    message: '메시지',
    type: 'success'
  }
});
```

---

## 모달 관리 (G7Core.modal)

모달을 관리하는 편의 API입니다.

### API 목록

| 메서드 | 설명 | 파라미터 |
|--------|------|----------|
| `open(modalId)` | 모달 열기 | modalId: string |
| `close(modalId?)` | 모달 닫기 | modalId?: string (생략 시 최상위) |
| `closeAll()` | 모든 모달 닫기 | - |
| `isOpen(modalId)` | 모달 열림 확인 | modalId: string |
| `getStack()` | 모달 스택 반환 | - |

### 사용 예시

```typescript
// 모달 열기
G7Core.modal.open('confirm_modal');

// 특정 모달 닫기
G7Core.modal.close('confirm_modal');

// 최상위 모달 닫기 (스택에서 pop)
G7Core.modal.close();

// 모든 모달 닫기
G7Core.modal.closeAll();

// 모달 열림 상태 확인
if (G7Core.modal.isOpen('confirm_modal')) {
  console.log('모달이 열려 있습니다');
}

// 현재 모달 스택 조회
const stack = G7Core.modal.getStack();
// ['first_modal', 'second_modal'] - 순서대로 쌓인 모달 ID
```

### 멀티 모달 (중첩 모달) 지원

G7Core는 스택 기반 멀티 모달을 지원합니다:

```typescript
// 1. 첫 번째 모달 열기
G7Core.modal.open('confirm_modal');
// stack: ['confirm_modal']

// 2. 두 번째 모달 열기 (첫 번째 위에 중첩)
G7Core.modal.open('error_modal');
// stack: ['confirm_modal', 'error_modal']

// 3. 최상위 모달 닫기
G7Core.modal.close();
// stack: ['confirm_modal'] - 첫 번째 모달 다시 표시

// 4. 남은 모달 닫기
G7Core.modal.close();
// stack: []
```

---

## 네비게이션 (G7Core.navigation)

페이지 전환 상태를 관리하는 API입니다.

### API 목록

| 메서드 | 설명 | 반환값 |
|--------|------|--------|
| `isPending()` | 전환 진행 중 여부 | `boolean` |
| `onComplete(callback)` | 전환 완료 시 콜백 실행 | 구독 해제 함수 |

### 사용 예시

```typescript
// 현재 페이지 전환 중인지 확인
if (G7Core.navigation.isPending()) {
  console.log('페이지 전환 중...');
}

// 전환 완료 시 콜백 실행
const unsubscribe = G7Core.navigation.onComplete(() => {
  console.log('페이지 전환 완료');
  // DOM 조작, 포커스 설정 등
});

// 구독 해제
unsubscribe();
```

### onComplete 동작 방식

`onComplete`는 전환이 시작(`isPending=true`)된 후 완료(`isPending=false`)될 때 콜백을 실행합니다:

```typescript
// 페이지 이동 후 특정 요소에 포커스
G7Core.navigation.onComplete(() => {
  document.getElementById('main-content')?.focus();
});

// 네비게이션 실행
G7Core.dispatch({
  handler: 'navigate',
  params: { path: '/users' }
});
```

---

## 스타일 헬퍼 (G7Core.style)

Tailwind CSS 클래스를 런타임에서 병합하는 헬퍼 API입니다.

컴포넌트에서 기본 클래스와 외부 className이 충돌할 때, 외부 클래스가 기본 클래스를 올바르게 오버라이드할 수 있도록 합니다.

### API 목록

| 메서드 | 설명 | 반환값 |
|--------|------|--------|
| `mergeClasses(base, override)` | 충돌하는 Tailwind 클래스 병합 | `string` |
| `conditionalClass(conditions)` | 조건부 클래스 적용 | `string` |
| `joinClasses(...classes)` | 여러 클래스 문자열 결합 | `string` |

### mergeClasses

같은 CSS 속성을 제어하는 클래스가 충돌하면 override 클래스를 우선 적용합니다.

```typescript
// 기본 사용: justify-center가 justify-between으로 대체됨
G7Core.style.mergeClasses('justify-center items-center', 'justify-between')
// 결과: 'items-center justify-between'

// variant가 다르면 충돌하지 않음
G7Core.style.mergeClasses('text-gray-900 dark:text-white', 'text-blue-500')
// 결과: 'dark:text-white text-blue-500'

// 같은 variant는 충돌
G7Core.style.mergeClasses('dark:text-white dark:bg-gray-800', 'dark:text-gray-100')
// 결과: 'dark:bg-gray-800 dark:text-gray-100'
```

### 지원하는 클래스 그룹

| 그룹 | 설명 | 예시 |
|------|------|------|
| justify | Flexbox justify-content | `justify-center`, `justify-between` |
| items | Flexbox align-items | `items-center`, `items-start` |
| textAlign | 텍스트 정렬 | `text-left`, `text-center` |
| display | 디스플레이 속성 | `flex`, `block`, `hidden` |
| bgColor | 배경색 | `bg-white`, `bg-gray-100` |
| textColor | 텍스트색 | `text-gray-900`, `text-blue-500` |
| width/height | 크기 | `w-full`, `h-10` |
| padding/margin | 여백 | `p-4`, `m-2` |
| ... | 대부분의 Tailwind 클래스 지원 | - |

### conditionalClass

조건에 따라 클래스를 적용합니다.

```typescript
G7Core.style.conditionalClass({
  'bg-blue-500': isPrimary,
  'bg-gray-500': !isPrimary,
  'opacity-50': isDisabled,
})
// isPrimary=true, isDisabled=true: 'bg-blue-500 opacity-50'
```

### joinClasses

여러 클래스 문자열을 결합합니다. falsy 값은 무시됩니다.

```typescript
G7Core.style.joinClasses('flex', isActive && 'bg-blue-500', 'p-4')
// isActive=true: 'flex bg-blue-500 p-4'
// isActive=false: 'flex p-4'
```

### 컴포넌트에서 사용 패턴

```tsx
// G7Core 접근 헬퍼
const G7Core = () => (window as any).G7Core;

export const Button: React.FC<Props> = ({ className = '', ...props }) => {
  const baseClasses = 'inline-flex items-center justify-center';

  // 외부 className이 baseClasses를 오버라이드할 수 있음
  const mergedClassName = G7Core()?.style?.mergeClasses?.(baseClasses, className)
    ?? `${baseClasses} ${className}`;

  return <button className={mergedClassName} {...props} />;
};
```

---

## 플러그인 설정 (G7Core.plugin)

플러그인 설정을 조회하는 API입니다. 백엔드에서 전달된 플러그인 설정값을 프론트엔드에서 사용할 수 있습니다.

### API 목록

| 메서드 | 설명 | 반환값 |
|--------|------|--------|
| `getSettings(pluginId)` | 플러그인 전체 설정 반환 | `Record<string, any> \| undefined` |
| `get(pluginId, key, defaultValue?)` | 특정 설정값 반환 | `any` |
| `getAll()` | 모든 플러그인 설정 반환 | `Record<string, Record<string, any>>` |

### 사용 예시

```typescript
// 플러그인 전체 설정 조회
const daumSettings = G7Core.plugin.getSettings('sirsoft-daum_postcode');
// { display_mode: 'layer', popup_width: 900, popup_height: 600 }

// 특정 설정값 조회
const displayMode = G7Core.plugin.get('sirsoft-daum_postcode', 'display_mode');
// 'layer'

// 기본값 지정
const timeout = G7Core.plugin.get('sirsoft-daum_postcode', 'timeout', 3000);
// 3000 (설정이 없을 경우)

// 모든 플러그인 설정 조회
const allPlugins = G7Core.plugin.getAll();
// { 'sirsoft-daum_postcode': {...}, 'sirsoft-analytics': {...} }
```

### 컴포넌트에서 사용 패턴

```tsx
// 주소 검색 컴포넌트
const AddressInput: React.FC = () => {
  const displayMode = G7Core.plugin.get('sirsoft-daum_postcode', 'display_mode', 'popup');

  const handleSearch = () => {
    if (displayMode === 'layer') {
      // 레이어 모드로 표시
    } else {
      // 팝업 모드로 표시
    }
  };

  return <Button onClick={handleSearch}>주소 검색</Button>;
};
```

---

## 모듈 설정 (G7Core.module)

모듈 설정을 조회하는 API입니다. 백엔드에서 전달된 모듈 설정값을 프론트엔드에서 사용할 수 있습니다.

### API 목록

| 메서드 | 설명 | 반환값 |
|--------|------|--------|
| `getSettings(moduleId)` | 모듈 전체 설정 반환 | `Record<string, any> \| undefined` |
| `get(moduleId, key, defaultValue?)` | 특정 설정값 반환 | `any` |
| `getAll()` | 모든 모듈 설정 반환 | `Record<string, Record<string, any>>` |

### 사용 예시

```typescript
// 모듈 전체 설정 조회
const ecommerceSettings = G7Core.module.getSettings('sirsoft-ecommerce');
// { default_currency: 'KRW', tax_rate: 10, shipping_methods: ['standard', 'express'] }

// 특정 설정값 조회
const currency = G7Core.module.get('sirsoft-ecommerce', 'default_currency');
// 'KRW'

// 기본값 지정
const taxRate = G7Core.module.get('sirsoft-ecommerce', 'tax_rate', 0);
// 10

// 모든 모듈 설정 조회
const allModules = G7Core.module.getAll();
// { 'sirsoft-ecommerce': {...}, 'sirsoft-board': {...} }
```

### 컴포넌트에서 사용 패턴

```tsx
// 가격 표시 컴포넌트
const PriceDisplay: React.FC<{ price: number }> = ({ price }) => {
  const currency = G7Core.module.get('sirsoft-ecommerce', 'default_currency', 'KRW');
  const taxRate = G7Core.module.get('sirsoft-ecommerce', 'tax_rate', 0);

  const priceWithTax = price * (1 + taxRate / 100);

  return (
    <Span>
      {priceWithTax.toLocaleString()} {currency}
    </Span>
  );
};
```

---

## 위지윅 편집기 (G7Core.wysiwyg)

> **engine-v1.11.0+** 추가

레이아웃 위지윅 편집기 관련 API입니다.

### API 목록

| 메서드 | 설명 | 반환값 |
|--------|------|--------|
| `isEditMode()` | 편집 모드 여부 반환 | `boolean` |
| `setEditMode(layoutName, templateId)` | 편집 모드 활성화 | `void` |
| `clearEditMode()` | 편집 모드 비활성화 | `void` |
| `getCurrentLayoutName()` | 현재 편집 중인 레이아웃명 | `string \| null` |
| `getCurrentTemplateId()` | 현재 편집 중인 템플릿 ID | `string \| null` |
| `isEditModeFromUrl()` | URL에서 편집 모드 여부 확인 | `boolean` |
| `getEditModeUrl(route, templateId)` | 편집 모드 URL 생성 | `string` |
| `enterEditMode(route, templateId)` | 편집 모드로 페이지 이동 | `void` |
| `exitEditMode()` | 편집 모드 종료 (일반 페이지로) | `void` |
| `getVersion()` | 위지윅 모듈 버전 | `string` |
| `getPhase()` | 현재 구현 Phase | `number` |

### 사용 예시

```typescript
// 편집 모드 확인
if (G7Core.wysiwyg.isEditMode()) {
  console.log('현재 편집 모드입니다');
  console.log('레이아웃:', G7Core.wysiwyg.getCurrentLayoutName());
  console.log('템플릿:', G7Core.wysiwyg.getCurrentTemplateId());
}

// URL에서 편집 모드 여부 확인 (쿼리 파라미터 기반)
if (G7Core.wysiwyg.isEditModeFromUrl()) {
  // ?mode=edit 파라미터가 있는 경우
}

// 편집 모드 URL 생성
const editUrl = G7Core.wysiwyg.getEditModeUrl('/shop', 'sirsoft-basic');
// 결과: 'https://example.com/shop?mode=edit&template=sirsoft-basic'

// 편집 모드로 진입
G7Core.wysiwyg.enterEditMode('/shop', 'sirsoft-basic');

// 편집 모드 종료
G7Core.wysiwyg.exitEditMode();
```

### 편집 모드 상태 관리

```typescript
// 프로그래밍 방식으로 편집 모드 설정
G7Core.wysiwyg.setEditMode('home', 'sirsoft-basic');

// 편집 모드 해제
G7Core.wysiwyg.clearEditMode();
```

---

## 커스텀 핸들러 Stale State 방지 규칙

> **engine-v1.17.0+** 추가

커스텀 핸들러에서 비동기 작업 후 상태를 사용할 때 발생하는 stale state 문제를 방지하는 규칙입니다.

### await 후 상태 재조회 필수

비동기 작업(API 호출, setTimeout 등) 후에는 캡처된 상태가 오래된 값(stale)일 수 있습니다. **반드시 await 후 최신 상태를 재조회**하세요.

```typescript
// ❌ 금지: await 전에 캡처한 상태를 await 후에 사용
const state = G7Core.state.getLocal();
const currentUi = state.ui || {};
await someAsyncOperation();  // 이 동안 다른 곳에서 상태가 변경될 수 있음
G7Core.state.setLocal({ ui: { ...currentUi, loading: false } });  // STALE!

// ✅ 필수: await 후 최신 상태 재조회
await someAsyncOperation();
const latestState = G7Core.state.getLocal() || {};  // 재조회
const latestUi = latestState.ui || {};
G7Core.state.setLocal({ ui: { ...latestUi, loading: false } });
```

### 실전 패턴

#### API 호출 후 상태 업데이트

```typescript
// 복사 중 상태 설정 (await 전)
const initialState = G7Core.state.getLocal() || {};
G7Core.state.setLocal({ ui: { ...initialState.ui, isCopying: true } });

try {
  // API 호출
  const response = await G7Core.api.post('/api/copy', data);

  // await 후 최신 상태 재조회
  const latestState = G7Core.state.getLocal() || {};
  G7Core.state.setLocal({
    ui: { ...latestState.ui, isCopying: false },
    form: { ...latestState.form, ...response.data },
  });
} catch (error) {
  // 에러 시에도 최신 상태 재조회
  const errorState = G7Core.state.getLocal() || {};
  G7Core.state.setLocal({
    ui: { ...errorState.ui, isCopying: false, error: true },
  });
}
```

#### 루프 내 await (순차 처리)

```typescript
// 각 반복에서 최신 상태 조회 (권장)
for (const file of files) {
  // 매번 최신 상태 조회
  const currentState = G7Core.state.getLocal() || {};
  G7Core.state.setLocal({
    ui: { ...currentState.ui, uploadProgress: { [file.name]: 0 } },
  });

  const response = await G7Core.api.upload('/api/upload', file);

  // 업로드 후 최신 상태 재조회
  const successState = G7Core.state.getLocal() || {};
  const existingImages = successState.form?.images || [];
  G7Core.state.setLocal({
    form: { ...successState.form, images: [...existingImages, response.data] },
  });
}
```

### 왜 이 문제가 발생하는가?

1. **JavaScript 클로저**: 함수가 생성될 때 외부 변수를 캡처합니다.
2. **React setState 비동기성**: `setLocal` 호출 후 즉시 상태가 반영되지 않습니다.
3. **비동기 작업 중 상태 변경**: await 중에 사용자가 다른 UI 조작을 할 수 있습니다.

### 관련 엔진 보호 메커니즘

그누보드7 템플릿 엔진은 다음 메커니즘으로 stale state 문제를 완화합니다:

| 메커니즘 | 설명 | 파일 |
|---------|------|------|
| `stateRef.current` | useCallback 캐싱 우회 | DynamicRenderer.tsx |
| `__g7PendingLocalState` | setLocal 후 dispatch 동기화 | G7CoreGlobals.ts |
| `G7Core.state.getLocal()` | 최신 로컬 상태 조회 | G7CoreGlobals.ts |

**하지만 커스텀 핸들러에서는 개발자가 직접 최신 상태 재조회를 구현해야 합니다.**

### 체크리스트

커스텀 핸들러 작성 시 확인:

```
□ await 후 상태를 사용하는 모든 곳에서 G7Core.state.getLocal() 재조회
□ try-catch 블록의 catch에서도 최신 상태 재조회
□ 루프 내 await 시 각 반복에서 상태 재조회
□ 모달 컨텍스트인 경우 getParent()도 재조회 고려
```

---

## 관련 문서

- [고급 API](g7core-api-advanced.md) - 다국어, 액션 실행, 컴포넌트 이벤트, 렌더링 헬퍼, WebSocket, React Hooks
- [state-management.md](state-management.md) - 전역 상태 관리 상세
- [components.md](components.md) - 컴포넌트 개발 규칙
- [data-binding.md](data-binding.md) - 데이터 바인딩 문법
- [auth-system.md](auth-system.md) - 인증 시스템
- [responsive-layout.md](responsive-layout.md) - 반응형 레이아웃
