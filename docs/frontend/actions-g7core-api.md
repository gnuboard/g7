# 액션 시스템 - G7Core API (React 컴포넌트용)

> **메인 문서**: [actions.md](actions.md)
> **관련 문서**: [actions-handlers.md](actions-handlers.md) | [state-management.md](state-management.md) | [g7core-api.md](g7core-api.md)

---

## 목차

1. [G7Core.dispatch](#g7coredispatch)
2. [G7Core 편의 API](#g7core-편의-api)
3. [이벤트 헬퍼](#이벤트-헬퍼)
4. [API 전체 목록](#g7core-api-전체-목록)

---

## G7Core.dispatch

템플릿 컴포넌트(React)에서 레이아웃 JSON과 동일한 방식으로 액션을 실행할 수 있습니다.

```text
중요: 템플릿은 코어와 별도 빌드되므로 import 불가
✅ 필수: window.G7Core.dispatch 전역 함수 사용
✅ 형식: 레이아웃 JSON의 액션 정의와 동일
```

### 기본 사용법

```tsx
// 네비게이션
(window as any).G7Core?.dispatch({
  handler: 'navigate',
  params: { path: '/admin/users/1/edit' },
});

// API 호출
(window as any).G7Core?.dispatch({
  handler: 'apiCall',
  target: '/api/admin/users/1',
  params: { method: 'GET' },
  auth_required: true,
});

// 상태 변경
(window as any).G7Core?.dispatch({
  handler: 'setState',
  params: { target: 'global', selectedIds: [] },
});

// 토스트 알림
(window as any).G7Core?.dispatch({
  handler: 'toast',
  params: { type: 'success', message: '저장되었습니다' },
});
```

### 후속 액션 (onSuccess/onError)

```tsx
(window as any).G7Core?.dispatch({
  handler: 'apiCall',
  target: '/api/admin/users/1',
  params: { method: 'DELETE' },
  auth_required: true,
  onSuccess: [
    { handler: 'toast', params: { type: 'success', message: '삭제 완료' } },
    { handler: 'navigate', params: { path: '/admin/users' } },
  ],
  onError: [
    { handler: 'toast', params: { type: 'error', message: '삭제 실패' } },
  ],
});
```

### 확인 다이얼로그

```tsx
(window as any).G7Core?.dispatch({
  handler: 'apiCall',
  target: '/api/admin/users/1',
  params: { method: 'DELETE' },
  confirm: '정말 삭제하시겠습니까?',  // 확인 후 실행
});
```

### 반환값

```tsx
const result = await (window as any).G7Core?.dispatch({
  handler: 'apiCall',
  target: '/api/admin/users',
  params: { method: 'GET' },
});

if (result?.success) {
  console.log('성공:', result.data);
} else {
  console.error('실패:', result?.error);
}
```

### 실제 사용 예시 (UserProfile 컴포넌트)

```tsx
const UserProfile: React.FC<UserProfileProps> = ({ user }) => {
  const handleProfileClick = () => {
    // SPA 네비게이션으로 사용자 수정 페이지 이동
    (window as any).G7Core?.dispatch({
      handler: 'navigate',
      params: { path: `/admin/users/${user.id}/edit` },
    });
  };

  return (
    <Button onClick={handleProfileClick}>
      프로필 설정
    </Button>
  );
};
```

### 사용 시 주의사항

1. **Optional Chaining 필수**: `G7Core?.dispatch` - 엔진 초기화 전 호출 방지
2. **타입 캐스팅**: `(window as any)` - TypeScript 타입 오류 방지
3. **비동기 처리**: `dispatch`는 Promise 반환 - 필요시 `await` 사용
4. **레이아웃 JSON과 동일**: 모든 핸들러와 파라미터 형식이 레이아웃 JSON과 같음

---

## G7Core 편의 API

`dispatch` 외에도 자주 사용하는 기능들을 편의 API로 제공합니다.

### G7Core.state - 전역 상태 관리

```tsx
// 현재 전역 상태 조회
const state = (window as any).G7Core?.state.get();
console.log(state._global?.selectedIds);

// 전역 상태 업데이트
(window as any).G7Core?.state.set({
  _global: { selectedIds: [1, 2, 3] }
});

// 상태 변경 구독
const unsubscribe = (window as any).G7Core?.state.subscribe((newState) => {
  console.log('상태 변경:', newState);
});

// 구독 해제
unsubscribe();
```

### G7Core.locale - 로케일 관리

```tsx
// 현재 로케일 조회
const currentLocale = (window as any).G7Core?.locale.current();
// 'ko'

// 지원하는 로케일 목록
const locales = (window as any).G7Core?.locale.supported();
// ['ko', 'en']

// 로케일 변경 (비동기)
await (window as any).G7Core?.locale.change('en');
```

### G7Core.toast - 토스트 알림

```tsx
// 기본 사용
(window as any).G7Core?.toast.show('메시지', { type: 'info', duration: 3000 });

// 편의 메서드
(window as any).G7Core?.toast.success('저장되었습니다');
(window as any).G7Core?.toast.error('오류가 발생했습니다');
(window as any).G7Core?.toast.warning('주의가 필요합니다');
(window as any).G7Core?.toast.info('정보 메시지');

// duration 지정 (ms)
(window as any).G7Core?.toast.success('저장 완료', 5000);
```

### G7Core.modal - 모달 관리

```tsx
// 모달 열기
(window as any).G7Core?.modal.open('confirm_modal');

// 모달 닫기 (특정 모달)
(window as any).G7Core?.modal.close('confirm_modal');

// 최상위 모달 닫기
(window as any).G7Core?.modal.close();

// 모든 모달 닫기
(window as any).G7Core?.modal.closeAll();

// 모달 열림 상태 확인
const isOpen = (window as any).G7Core?.modal.isOpen('confirm_modal');

// 현재 열린 모달 스택 조회
const stack = (window as any).G7Core?.modal.getStack();
// ['parent_modal', 'child_modal']
```

### G7Core.navigation - 페이지 전환 상태

페이지 전환(네비게이션) 상태를 감지하고 전환 완료 시점에 콜백을 실행할 수 있습니다.

```tsx
// 현재 페이지 전환 진행 중인지 확인
const isPending = (window as any).G7Core?.navigation.isPending();

// 페이지 전환 완료 후 콜백 실행 (1회성)
const unsubscribe = (window as any).G7Core?.navigation.onComplete(() => {
  console.log('페이지 로드 완료!');
  // 사이드바 닫기, 스크롤 위치 초기화 등
});

// 필요시 구독 해제
unsubscribe();
```

**onComplete 동작 방식:**

- 전환이 **시작된 후 완료**될 때 콜백 실행 (true → false 상태 변화 감지)
- 콜백은 1회만 실행되고 자동으로 구독 해제됨
- `TransitionManager`를 직접 사용하지 않고도 전환 완료를 감지할 수 있음

**실전 예시 (모바일 사이드바):**

```tsx
const handleNavigate = (event: React.MouseEvent, url: string) => {
  event.preventDefault();

  // 페이지 전환 완료 후 사이드바 닫기
  G7Core().navigation?.onComplete(() => {
    G7Core().dispatch({
      handler: 'setState',
      params: { target: 'global', sidebarOpen: false },
    });
  });

  G7Core().dispatch({
    handler: 'navigate',
    params: { path: url },
  });
};
```

---

## 이벤트 헬퍼

컴포넌트에서 가짜 이벤트를 생성할 때 ActionDispatcher가 인식할 수 있는 표준 형식의 이벤트 객체를 생성합니다.

```text
중요: 컴포넌트에서 직접 가짜 이벤트를 생성하면 ActionDispatcher의 isStandardEvent 체크 실패
✅ 필수: G7Core.createChangeEvent 등 헬퍼 함수 사용
```

### 사용 가능한 이벤트 헬퍼

| 함수 | 설명 | 주요 옵션 |
|------|------|----------|
| `createChangeEvent(options)` | change 이벤트 생성 | `checked`, `value`, `name` |
| `createClickEvent(options)` | click 이벤트 생성 | `button`, `clientX`, `clientY` |
| `createSubmitEvent()` | submit 이벤트 생성 | - |
| `createKeyboardEvent(key, type)` | 키보드 이벤트 생성 | `key`, `eventType` |

### 사용 예시 (Toggle 컴포넌트)

```tsx
// G7Core 전역 객체 참조
const G7Core = (window as any).G7Core;

// Toggle 컴포넌트 내부
const handleClick = () => {
  const newChecked = !checked;
  setChecked(newChecked);
  if (onChange) {
    // ActionDispatcher 호환 이벤트 생성
    (onChange as any)(G7Core.createChangeEvent({ checked: newChecked, name }));
  }
};
```

### createChangeEvent 옵션

| 필드 | 타입 | 설명 |
|------|------|------|
| `checked` | boolean | 체크박스/토글의 체크 상태 |
| `value` | string | input의 값 |
| `name` | string | input의 name 속성 |
| `type` | string | input의 type 속성 (기본값: "checkbox") |

### 왜 이벤트 헬퍼가 필요한가?

ActionDispatcher의 `bindActionsToProps`는 이벤트 객체에 `preventDefault` 메서드가 있는지 확인합니다:

```typescript
// ActionDispatcher.ts
const isStandardEvent = firstArg && typeof firstArg === 'object' && 'preventDefault' in firstArg;
```

단순 객체 `{ target: { checked: true } }`는 이 체크를 통과하지 못하므로, 헬퍼 함수가 `preventDefault`, `stopPropagation` 등 필수 메서드를 포함한 완전한 이벤트 객체를 생성합니다.

---

## G7Core API 전체 목록

| API | 설명 | 주요 메서드 |
|-----|------|-----------|
| `G7Core.dispatch` | 액션 실행 | `dispatch(action)` |
| `G7Core.state` | 전역 상태 관리 | `get()`, `set(updates)`, `subscribe(listener)` |
| `G7Core.locale` | 로케일 관리 | `current()`, `supported()`, `change(locale)` |
| `G7Core.toast` | 토스트 알림 | `show()`, `success()`, `error()`, `warning()`, `info()` |
| `G7Core.modal` | 모달 관리 | `open()`, `close()`, `closeAll()`, `isOpen()`, `getStack()` |
| `G7Core.navigation` | 페이지 전환 상태 | `isPending()`, `onComplete(callback)` |
| `G7Core.createChangeEvent` | change 이벤트 생성 | `createChangeEvent(options)` |
| `G7Core.createClickEvent` | click 이벤트 생성 | `createClickEvent(options)` |
| `G7Core.createSubmitEvent` | submit 이벤트 생성 | `createSubmitEvent()` |
| `G7Core.createKeyboardEvent` | 키보드 이벤트 생성 | `createKeyboardEvent(key, type)` |
| `G7Core.AuthManager` | 인증 관리 | `getInstance()`, `isAuthenticated()`, `getUser()` |

---

## 관련 문서

- [액션 시스템 개요](actions.md) - 액션 정의, 핸들러 목록
- [핸들러 상세](actions-handlers.md) - 각 핸들러별 상세 사용법
- [상태 관리](state-management.md) - `_global` 전역 상태
- [G7Core API 전체](g7core-api.md) - 렌더링, 인증, 반응형 등
