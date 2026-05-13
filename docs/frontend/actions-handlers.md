# 액션 핸들러 - 핸들러별 상세 사용법

> **메인 문서**: [actions.md](actions.md)
> **관련 문서**: [actions-g7core-api.md](actions-g7core-api.md) | [layout-json.md](layout-json.md) | [state-management.md](state-management.md)

---

## TL;DR (5초 요약)

```text
1. navigate: 페이지 이동 (path, query, mergeQuery 옵션)
2. apiCall: API 호출 (method, endpoint, body, onSuccess/onError)
3. setState: 상태 변경 (_global/_local/_isolated 경로, target 옵션)
4. openModal/closeModal: 모달 열기/닫기
5. sequence/parallel: 여러 액션 순차/병렬 실행
```

---

## 하위 문서 안내

이 문서는 규모가 커서 카테고리별로 분할되었습니다. 아래 링크를 통해 각 핸들러에 대한 상세 내용을 확인하세요.

| 하위 문서 | 주요 핸들러 | 설명 |
|----------|------------|------|
| [actions-handlers-navigation.md](actions-handlers-navigation.md) | navigate, navigateBack, openWindow, replaceUrl, **reloadExtensions**, reloadRoutes, refresh | 페이지 이동 및 라우트 관리 |
| [actions-handlers-state.md](actions-handlers-state.md) | apiCall, setState, setError, refetchDataSource, remount | API 호출 및 상태 관리 |
| [actions-handlers-ui.md](actions-handlers-ui.md) | login/logout, openModal/closeModal, toast, switch, sequence/parallel, loadScript, callExternal | UI 인터랙션 및 외부 스크립트 |

---

## 목차

### 네비게이션 핸들러 → [상세 문서](actions-handlers-navigation.md)

1. [navigate](actions-handlers-navigation.md#navigate) - 페이지 이동
2. [navigateBack](actions-handlers-navigation.md#navigateback) - 뒤로 가기
3. [openWindow](actions-handlers-navigation.md#openwindow) - 새 창/탭에서 열기
4. [replaceUrl](actions-handlers-navigation.md#replaceurl) - URL만 변경 (refetch 없음)
5. [reloadExtensions](actions-handlers-navigation.md#reloadextensions) ⭐ NEW (engine-v1.38.0+) - 확장 상태 원자 재동기화
6. [reloadRoutes](actions-handlers-navigation.md#reloadroutes) (deprecated) - 라우트 재로드
7. [refresh](actions-handlers-navigation.md#refresh) - 페이지 새로고침

### 상태 관리 핸들러 → [상세 문서](actions-handlers-state.md)

5. [apiCall](actions-handlers-state.md#apicall) - API 호출
6. [setState](actions-handlers-state.md#setstate) - 상태 변경
7. [setError](actions-handlers-state.md#seterror) - 에러 상태 설정
8. [refetchDataSource](actions-handlers-state.md#refetchdatasource) - 데이터 소스 재조회
9. [appendDataSource](actions-handlers-state.md#appenddatasource) - 데이터 소스 병합 (무한스크롤)
10. [remount](actions-handlers-state.md#remount) - 컴포넌트 리마운트
11. [onSuccess/onError 후속 액션](actions-handlers-state.md#onsuccessonerror-후속-액션)
12. [API 데이터 바인딩 규칙](actions-handlers-state.md#api-데이터-바인딩-규칙)
13. [에러 핸들링 시스템](actions-handlers-state.md#에러-핸들링-시스템-errorhandling)

### UI 인터랙션 핸들러 → [상세 문서](actions-handlers-ui.md)

14. [login / logout](actions-handlers-ui.md#login--logout) - 인증
15. [openModal / closeModal](actions-handlers-ui.md#openmodal--closemodal) - 모달
16. [showAlert / toast](actions-handlers-ui.md#showalert--toast) - 알림
17. [confirm (액션 속성)](actions-handlers-ui.md#confirm-액션-속성) - 실행 전 확인 대화상자
18. [switch](actions-handlers-ui.md#switch) - 조건부 액션
19. [sequence / parallel](actions-handlers-ui.md#sequence--parallel) - 액션 조합
20. [reloadTranslations](actions-handlers-ui.md#reloadtranslations) (deprecated) - 다국어 재로드 (extension 라이프사이클은 `reloadExtensions` 사용)
21. [showErrorPage](actions-handlers-ui.md#showerrorpage) - 에러 페이지
22. [loadScript](actions-handlers-ui.md#loadscript) ⭐ NEW - 외부 스크립트 로드
23. [callExternal](actions-handlers-ui.md#callexternal) ⭐ NEW - 외부 라이브러리 호출
24. [실전 예시](actions-handlers-ui.md#실전-예시)

---

## 핸들러 빠른 참조

### 자주 사용하는 핸들러

| 핸들러 | 용도 | 상세 문서 |
|--------|------|----------|
| `navigate` | 페이지 이동 | [navigation](actions-handlers-navigation.md#navigate) |
| `openWindow` | 새 창/탭에서 열기 | [navigation](actions-handlers-navigation.md#openwindow) |
| `replaceUrl` | URL만 변경 (refetch 없음) | [navigation](actions-handlers-navigation.md#replaceurl) |
| `apiCall` | API 호출 | [state](actions-handlers-state.md#apicall) |
| `setState` | 상태 변경 | [state](actions-handlers-state.md#setstate) |
| `openModal` | 모달 열기 | [ui](actions-handlers-ui.md#openmodal--closemodal) |
| `closeModal` | 모달 닫기 | [ui](actions-handlers-ui.md#openmodal--closemodal) |
| `toast` | 토스트 알림 | [ui](actions-handlers-ui.md#showalert--toast) |
| `sequence` | 순차 실행 | [ui](actions-handlers-ui.md#sequence--parallel) |
| `suppress` | 에러 전파 방지 (no-op) | [error](layout-json-features-error.md#에러-전파-방지-suppress-핸들러) |

### 핸들러별 필수 속성

| 핸들러 | 필수 속성 | 선택 속성 |
|--------|----------|----------|
| `navigate` | `params.path` | `params.query`, `params.mergeQuery`, `params.replace`, `params.fallback` (engine-v1.40.0+, 미등록 경로 fallback, 기본 `openWindow`) |
| `openWindow` | `params.path` | - |
| `replaceUrl` | - | `params.path`, `params.query`, `params.mergeQuery` |
| `apiCall` | `target` | `params.method`, `params.body`, `params.contentType`, `auth_required`, `onSuccess`, `onError` |
| `setState` | `params.*` | `params.target` (global/local/isolated) |
| `openModal` | `target` (모달 ID) | - |
| `toast` | `params.message` | `params.type`, `params.duration` |
| `switch` | `cases` | `params.value` |
| `sequence` | `actions` | - |
| `parallel` | `actions` | - |
| `suppress` | - | - |

---

## 모듈 커스텀 핸들러 다국어 처리

모듈에서 커스텀 핸들러를 개발할 때, 사용자에게 표시되는 모든 문자열은 반드시 다국어 처리해야 합니다.

### 핵심 원칙

```text
필수: 모든 사용자 표시 문자열은 G7Core.t() 사용
필수: 다국어 키는 moduleIdentifier로 시작
✅ 필수: 영어 폴백 메시지 제공
✅ 필수: 파라미터는 {param} 형식 사용
❌ 금지: 한글 문자열 하드코딩 (toast, 알림 메시지 등)
예외: 로케일별 콘텐츠 생성용 상수맵은 허용 (예: DETAIL_REF_TRANSLATIONS)
```

### 다국어 키 네이밍 규칙

```text
[moduleId].admin.[section].handler.[message_key]
```

**예시**:

- `sirsoft-ecommerce.admin.product.handler.category_max_5`
- `sirsoft-ecommerce.admin.product.handler.options_generated`

**키 접미사 규칙**:

| 접미사 | 용도 | 예시 |
|--------|------|------|
| `_success` | 성공 메시지 | `copy_success` |
| `_error`, `_failed` | 오류 메시지 | `copy_error`, `upload_failed` |
| `_required` | 필수 입력 안내 | `name_required` |
| `_max_N` | 최대 개수 제한 | `category_max_5` |

### 구현 패턴

**기본 패턴**:

```typescript
// ❌ DON'T: 하드코딩
G7Core.toast?.warning?.('최대 5개까지 선택 가능합니다.');

// ✅ DO: G7Core.t() + 영어 폴백
G7Core.toast?.warning?.(
    G7Core.t?.('sirsoft-ecommerce.admin.product.handler.category_max_5')
    ?? 'You can select up to 5 categories.'
);
```

**파라미터 패턴**:

```typescript
// ❌ DON'T: 템플릿 리터럴만 사용
G7Core.toast?.success?.(`${count}개의 옵션이 생성되었습니다.`);

// ✅ DO: 파라미터 전달 + 폴백
G7Core.toast?.success?.(
    G7Core.t?.('sirsoft-ecommerce.admin.product.handler.options_generated', { count })
    ?? `${count} options have been generated.`
);
```

**다국어 파일 등록** (`resources/lang/ko.json`, `en.json`):

```json
// ko.json
{
  "admin": {
    "product": {
      "handler": {
        "category_max_5": "최대 5개까지 선택 가능합니다.",
        "options_generated": "{count}개의 옵션이 생성되었습니다."
      }
    }
  }
}

// en.json
{
  "admin": {
    "product": {
      "handler": {
        "category_max_5": "You can select up to 5 categories.",
        "options_generated": "{count} options have been generated."
      }
    }
  }
}
```

### 예외: 로케일별 콘텐츠 생성용 상수맵

로케일별로 다른 콘텐츠를 **생성**해야 하는 경우, 상수맵은 허용됩니다:

```typescript
// ✅ 허용: 로케일별 콘텐츠 생성용 상수맵
const DETAIL_REF_TRANSLATIONS: Record<string, Record<string, string>> = {
    ko: { goods_name: '상품명', model_name: '모델명' },
    en: { goods_name: 'Product Name', model_name: 'Model Name' },
};

// 사용: 특정 로케일의 콘텐츠 생성
const text = DETAIL_REF_TRANSLATIONS[locale]?.[key] ?? key;
```

### 핸들러 개발 체크리스트

```text
□ 모든 toast 메시지에 G7Core.t() 적용
□ 모든 확인/알림 모달 텍스트에 G7Core.t() 적용
□ 영어 폴백 메시지 제공 (G7Core.t?.() ?? 'fallback')
□ 다국어 파일(ko.json, en.json)에 키 추가
□ 파라미터는 {param} 형식으로 정의
□ 로그 메시지는 다국어 처리 불필요 (logger.log, logger.warn 등)
```

---

## 증상별 문서 찾기

| 증상 | 관련 문서 | 핵심 키워드 |
|------|----------|------------|
| 페이지 이동 안 됨 | [navigation](actions-handlers-navigation.md) | navigate, path, replace |
| API 호출 실패 | [state](actions-handlers-state.md#apicall) | apiCall, auth_required, onError |
| 상태 변경 안 됨 | [state](actions-handlers-state.md#setstate) | setState, target: global/local/isolated |
| 모달 안 열림/안 닫힘 | [ui](actions-handlers-ui.md#openmodal--closemodal) | openModal, closeModal, modalStack |
| 토스트 안 나옴 | [ui](actions-handlers-ui.md#showalert--toast) | toast, params.type |
| 외부 스크립트 로드 | [ui](actions-handlers-ui.md#loadscript) | loadScript, onLoad |
| 조건부 액션 분기 | [ui](actions-handlers-ui.md#switch) | switch, cases, default |

---

## 관련 문서

- [액션 시스템 기초](actions.md)
- [G7Core API](actions-g7core-api.md)
- [상태 관리](state-management.md)
- [데이터 소스](data-sources.md)
- [레이아웃 JSON](layout-json.md)
