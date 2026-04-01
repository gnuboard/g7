# 액션 핸들러 - 네비게이션

> **메인 문서**: [actions-handlers.md](actions-handlers.md)

---

## 목차

1. [navigate](#navigate)
2. [navigateBack](#navigateback)
3. [openWindow](#openwindow)
4. [reloadRoutes](#reloadroutes)
5. [refresh](#refresh)

---

## navigate

페이지 이동을 처리합니다.

```json
{
  "type": "click",
  "handler": "navigate",
  "params": {
    "path": "/admin/users/{{row.id}}"
  }
}
```

### navigate params 구조

| 필드 | 타입 | 기본값 | 설명 |
|------|------|--------|------|
| `path` | string | - | 이동할 경로 |
| `query` | object | - | 쿼리 파라미터 객체 |
| `mergeQuery` | boolean | false | 기존 쿼리 파라미터와 병합 |
| `replace` | boolean | false | URL만 변경 (페이지 리로드 없음) |

### mergeQuery 옵션

기존 쿼리 파라미터를 유지하면서 새 파라미터를 병합합니다.

```json
{
  "handler": "navigate",
  "params": {
    "path": "/admin/users",
    "mergeQuery": true,
    "query": {
      "page": "2"
    }
  }
}
```

### 배열 쿼리 파라미터

> **버전**: engine-v1.10.0+

배열 값은 자동으로 `key[]=value1&key[]=value2` 형태로 변환됩니다.

```json
{
  "handler": "navigate",
  "params": {
    "path": "/admin/products",
    "mergeQuery": true,
    "query": {
      "sales_status[]": "{{_local.salesStatus}}"
    }
  }
}
```

**입력 예시**:

- `_local.salesStatus = ["on_sale", "sold_out"]`

**생성되는 쿼리스트링**:

- `sales_status[]=on_sale&sales_status[]=sold_out`

```text
중요: 배열 값이 빈 배열([])이거나 null이면 해당 파라미터는 쿼리스트링에서 제거됩니다.
```

**백엔드 Enum 값과 일치 필수**:

프론트엔드 필터에서 사용하는 값은 반드시 백엔드 Enum의 `value`와 동일해야 합니다.

```php
// 백엔드 Enum (ProductSalesStatus.php)
enum ProductSalesStatus: string
{
    case OnSale = 'on_sale';
    case Suspended = 'suspended';
    case SoldOut = 'sold_out';
    case ComingSoon = 'coming_soon';
}
```

```json
// ✅ 올바른 사용 (Enum 값과 일치)
"salesStatus": "{{(_local.salesStatus || []).includes('sold_out') ? ... }}"

// ❌ 잘못된 사용 (Enum에 없는 값)
"salesStatus": "{{(_local.salesStatus || []).includes('out_of_stock') ? ... }}"
```

번역 키도 Enum과 일관되게 사용:

```json
// ✅ 권장: Enum 기반 번역 키
"text": "$t:sirsoft-ecommerce.enums.sales_status.sold_out"

// 비권장: 별도의 필터용 번역 키
"text": "$t:sirsoft-ecommerce.admin.product.filter.sales_status_options.out_of_stock"
```

### replace 옵션

> **버전**: engine-v1.3.0+ (engine-v1.12.0에서 동작 방식 변경)

`replace: true`를 사용하면 **컴포넌트 리마운트 없이** URL을 변경하고 데이터를 갱신합니다. 같은 페이지 내에서 검색/필터/정렬/페이지네이션 등의 쿼리 파라미터만 변경할 때 사용합니다.

```json
{
  "handler": "navigate",
  "params": {
    "path": "/admin/products",
    "mergeQuery": true,
    "replace": true,
    "query": {
      "page": "{{_local.page}}",
      "sort": "{{_local.sortField}}",
      "order": "{{_local.sortOrder}}"
    }
  }
}
```

**동작 방식** (engine-v1.12.0+):

1. `window.history.replaceState()`로 URL 업데이트 (히스토리 교체)
2. 내부 쿼리 컨텍스트 (`query`) 업데이트
3. `auto_fetch: true`인 모든 데이터 소스 자동 refetch
4. UI 리렌더링 (컴포넌트 리마운트 없음)

**사용 사례**:

- 검색 버튼 클릭 시 필터 적용 (깜빡임 없이 데이터만 갱신)
- 정렬 드롭다운 변경 시 목록 갱신
- 페이지당 표시 개수 변경
- 페이지네이션 (같은 페이지 내 이동)

**replace vs 일반 navigate 비교**:

| 특성 | `replace: false` (기본값) | `replace: true` |
|------|--------------------------|-----------------|
| 컴포넌트 리마운트 | 발생 (전체 라우트 전환) | 발생 안 함 |
| 데이터 소스 refetch | 라우트 전환 시 자동 | 즉시 자동 refetch |
| 히스토리 스택 | 새 항목 추가 | 현재 항목 교체 |
| UI 깜빡임 | 발생 가능 | 없음 |
| 쿼리 컨텍스트 업데이트 | React Router 통해 자동 | 내부적으로 즉시 반영 |

**주의사항**:

```text
replace: true는 같은 페이지 내에서만 사용
   다른 페이지로 이동할 때는 replace: false (기본값) 사용

✅ 사용 권장: 검색, 필터, 정렬, 페이지네이션
❌ 사용 금지: 다른 페이지로 이동, 상세 페이지 진입
```

**검색 필터 예시**:

```json
{
  "id": "search_button",
  "type": "basic",
  "name": "Button",
  "text": "$t:common.search",
  "actions": [
    {
      "type": "click",
      "handler": "navigate",
      "params": {
        "path": "/admin/products",
        "replace": true,
        "mergeQuery": true,
        "query": {
          "page": 1,
          "search_field": "{{_local.filter.searchField}}",
          "search_keyword": "{{_local.filter.searchKeyword}}"
        }
      }
    }
  ]
}
```

**정렬 드롭다운 예시**:

```json
{
  "type": "basic",
  "name": "Select",
  "props": {
    "value": "{{query.sort || 'created_at'}}",
    "options": [
      { "value": "created_at", "label": "$t:common.sort.created_at" },
      { "value": "name", "label": "$t:common.sort.name" }
    ]
  },
  "actions": [
    {
      "type": "change",
      "handler": "navigate",
      "params": {
        "path": "/admin/products",
        "replace": true,
        "mergeQuery": true,
        "query": {
          "sort": "{{$event.target.value}}"
        }
      }
    }
  ]
}
```

---

## openWindow

> **버전**: engine-v1.19.0+

새 브라우저 탭/창에서 URL을 엽니다. `window.open(path, '_blank')`을 호출합니다.

```json
{
  "type": "click",
  "handler": "openWindow",
  "params": {
    "path": "/admin/users/{{row.created_by}}"
  }
}
```

### openWindow params 구조

| 필드 | 타입 | 기본값 | 설명 |
|------|------|--------|------|
| `path` | string | - | 새 창에서 열 경로 (필수) |

### 사용 사례

- 회원 정보를 새 창에서 조회
- 외부 링크를 새 탭에서 열기
- 현재 페이지를 유지하면서 다른 페이지 참조

### navigate와의 차이

| 특성 | `navigate` | `openWindow` |
|------|-----------|--------------|
| 현재 페이지 유지 | X (이동) | O (유지) |
| 새 탭/창 | X | O |
| 히스토리 | 현재 탭 히스토리 변경 | 변경 없음 |

### 예시: 등록자 정보 새 창으로 보기

```json
{
  "type": "basic",
  "name": "Button",
  "props": {
    "variant": "ghost",
    "size": "sm"
  },
  "text": "$t:common.view_member",
  "actions": [
    {
      "type": "click",
      "handler": "openWindow",
      "params": {
        "path": "/admin/users/{{row.created_by}}"
      }
    }
  ]
}
```

---

## navigateBack

브라우저 히스토리에서 뒤로 이동합니다. `window.history.back()`을 호출합니다.

```json
{
  "type": "click",
  "handler": "navigateBack"
}
```

### 사용 사례

- 상세 페이지에서 목록으로 돌아가기
- 폼 페이지에서 취소 버튼 클릭 시 이전 페이지로 이동

```json
{
  "id": "back_button",
  "type": "basic",
  "name": "Button",
  "actions": [
    {
      "type": "click",
      "handler": "navigateBack"
    }
  ],
  "children": [
    {
      "type": "basic",
      "name": "Icon",
      "props": { "name": "arrow-left", "className": "w-4 h-4" }
    },
    {
      "type": "basic",
      "name": "Span",
      "text": "$t:common.back"
    }
  ]
}
```

---

## reloadRoutes

라우트(routes.json)를 다시 로드합니다.

```json
{
  "handler": "reloadRoutes"
}
```

### 사용 사례

- 모듈/플러그인 설치 후 새 라우트 적용
- 동적으로 라우트 설정이 변경된 경우

---

## refresh

현재 페이지를 새로고침합니다.

```json
{
  "handler": "refresh"
}
```

### 사용 사례

- 전체 페이지 새로고침이 필요한 경우
- 상태 초기화가 필요한 경우

---

## replaceUrl

> **버전**: engine-v1.18.0+

URL만 변경하고 데이터소스 refetch나 컴포넌트 리마운트를 수행하지 않습니다. 리스트 항목 선택 시 URL에 상태를 반영할 때 사용합니다.

```json
{
  "handler": "replaceUrl",
  "params": {
    "path": "/admin/menus",
    "query": { "menu": "{{$args[0].slug}}", "mode": "view" }
  }
}
```

### replaceUrl params 구조

| 필드 | 타입 | 기본값 | 설명 |
|------|------|--------|------|
| `path` | string | 현재 경로 | 변경할 경로 (생략 시 `window.location.pathname`) |
| `query` | object | - | 쿼리 파라미터 객체 |
| `mergeQuery` | boolean | false | 기존 쿼리 파라미터와 병합 |

### replaceUrl vs navigate replace 비교

| 특성 | `navigate` (`replace: true`) | `replaceUrl` |
| ------ | ------------------------------ | -------------- |
| URL 변경 | O | O |
| 데이터소스 refetch | O (auto_fetch 전체) | **X** |
| 컴포넌트 리마운트 | X | **X** |
| 히스토리 | 현재 항목 교체 | 현재 항목 교체 |

### 사용 사례

- 리스트 항목 선택 시 URL에 선택 상태 반영 (깜빡임 없이)
- 편집/보기 모드 전환 시 URL 업데이트
- URL 복사/공유 시 현재 상태 복원 가능하도록

### path 생략 예시

`path`를 생략하면 현재 페이지 경로가 자동으로 사용됩니다.

```json
{
  "handler": "replaceUrl",
  "params": {
    "query": { "id": "{{$args[0].id}}", "mode": "view" }
  }
}
```

---

## 관련 문서

- [액션 핸들러 인덱스](actions-handlers.md)
- [상태 핸들러](actions-handlers-state.md)
- [UI 핸들러](actions-handlers-ui.md)
- [상태 관리](state-management.md)
