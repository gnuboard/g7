# 레이아웃 JSON - 스타일 및 계산된 값

> **메인 문서**: [layout-json-features.md](layout-json-features.md)

---

## 목차

1. [classMap - 조건부 스타일](#classmap---조건부-스타일-v190)
2. [computed - 계산된 값](#computed---계산된-값-v190)

---

## classMap - 조건부 스타일 (engine-v1.9.0+)

**목적**: 중첩 삼항 연산자를 사용한 조건부 CSS 클래스 할당을 선언적으로 단순화합니다.

### 핵심 원칙

```text
✅ base: 항상 적용되는 기본 클래스
✅ variants: key 값에 따라 선택되는 클래스 매핑
✅ key: 동적으로 평가되는 표현식 (상태 값, row 데이터 등)
✅ default: 일치하는 variant가 없을 때 기본 클래스
```

### 구조

```html
<div>
  <class-map base="px-2 py-1 rounded-full text-xs font-medium" key="{{row.status}}" default="bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
    <variant when="success">bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400</variant>
    <variant when="danger">bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400</variant>
    <variant when="warning">bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400</variant>
  </class-map>
  본문
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "classMap": {
    "base": "px-2 py-1 rounded-full text-xs font-medium",
    "variants": {
      "success": "bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400",
      "danger": "bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400",
      "warning": "bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400"
    },
    "key": "{{row.status}}",
    "default": "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300"
  }
}
```

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `base` | string | ❌ | 항상 적용되는 기본 CSS 클래스 |
| `variants` | object | ✅ | 키-클래스 매핑 (key 값과 일치하는 키의 클래스 적용) |
| `key` | string | ✅ | 평가할 표현식 (결과가 variants의 키와 매칭) |
| `default` | string | ❌ | 일치하는 variant가 없을 때 적용할 클래스 |

### 비교: 기존 방식 vs classMap

**기존 (복잡)**:

```html
<div class="{{row.status === 'success' ? 'bg-green-100 text-green-800' : row.status === 'danger' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-600'}} px-2 py-1 rounded-full text-xs font-medium"/>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "className": "{{row.status === 'success' ? 'bg-green-100 text-green-800' : row.status === 'danger' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-600'}} px-2 py-1 rounded-full text-xs font-medium"
}
```

**개선 (단순)**:

```html
<div>
  <class-map base="px-2 py-1 rounded-full text-xs font-medium" key="{{row.status}}" default="bg-gray-100 text-gray-600">
    <variant when="success">bg-green-100 text-green-800</variant>
    <variant when="danger">bg-red-100 text-red-800</variant>
  </class-map>
  본문
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "classMap": {
    "base": "px-2 py-1 rounded-full text-xs font-medium",
    "variants": {
      "success": "bg-green-100 text-green-800",
      "danger": "bg-red-100 text-red-800"
    },
    "key": "{{row.status}}",
    "default": "bg-gray-100 text-gray-600"
  }
}
```

### 실제 사용 예시

**DataGrid 상태 배지**:

```json
{
  "columns": [
    {
      "field": "status",
      "header": "$t:common.status",
      "cellChildren": [
        {
          "type": "basic",
          "name": "Span",
          "classMap": {
            "base": "inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium",
            "variants": {
              "active": "bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400",
              "inactive": "bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300",
              "pending": "bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400",
              "blocked": "bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400"
            },
            "key": "{{row.status}}",
            "default": "bg-gray-100 text-gray-600"
          },
          "text": "{{row.status_label}}"
        }
      ]
    }
  ]
}
```

### className과 classMap 함께 사용

`className`과 `classMap`을 함께 사용할 경우, 두 클래스가 병합됩니다:

```html
<div class="cursor-pointer hover:opacity-80">
  <class-map base="px-2 py-1" key="{{status}}" default="bg-gray-300">
    <variant when="active">bg-blue-500</variant>
    <variant when="inactive">bg-gray-500</variant>
  </class-map>
  본문
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "className": "cursor-pointer hover:opacity-80",
  "classMap": {
    "base": "px-2 py-1",
    "variants": { "active": "bg-blue-500", "inactive": "bg-gray-500" },
    "key": "{{status}}",
    "default": "bg-gray-300"
  }
}
```

결과: `cursor-pointer hover:opacity-80 px-2 py-1 bg-blue-500` (status가 'active'인 경우)

### 주의사항

```text
✅ classMap은 className과 함께 사용 가능 (클래스 병합)
✅ iteration/cellChildren 컨텍스트에서 각 아이템별 독립 평가
✅ 다크 모드 클래스는 variants 내에 포함 권장
key 평가 결과가 variants에 없으면 default 적용
default도 없으면 base 클래스만 적용
```

---

## computed - 계산된 값 (engine-v1.9.0+)

**목적**: 레이아웃 수준에서 **두 곳 이상에서 쓰는 파생값**을 한 번 정의하고 `$computed.xxx`(또는 `_computed.xxx`)로 참조합니다. 한 곳에서만 쓰는 식은 computed 로 빼지 말고 그 자리에 바로 적습니다 — 이름만 늘고 읽는 사람이 정의를 찾아 오가야 합니다.

### 핵심 원칙

```text
✅ 레이아웃 최상위에 computed 섹션 정의
✅ $computed.키이름 으로 컴포넌트에서 접근
✅ 문자열 표현식 또는 $switch 객체 지원
✅ 상태가 바뀔 때마다 새로 평가 (값은 캐시하지 않음)
✅ 같은 상태 범위의 컴포넌트는 한 번 계산한 값을 나눠 씀 (engine-v1.74.0+)
✅ 앞에 정의한 computed 를 뒤의 computed 가 참조 가능 (engine-v1.74.0+)
✅ 두 곳 이상에서 쓰는 값만 computed 로 — 한 곳에서만 쓰면 인라인
```

### 평가 모델 (engine-v1.74.0+)

- **상태 범위마다 한 번**: 페이지 루트, 반복(`iteration`) 항목, 모달, 격리 상태 영역처럼 상태가 갈리는 경계에서 computed 를 한 번 계산하고, 그 안의 자식 컴포넌트는 같은 값을 그대로 씁니다. 반복 항목·모달·격리 영역은 각자 계산하므로 항목별 값이 섞이지 않습니다.
- **정의 순서대로 참조**: computed 는 위에서 아래 순서로 계산되고, 앞에서 계산한 키는 같은 계산 안에서 바로 최신값으로 읽힙니다.

  ```html
  <computed name="subtotal">{{(_local.qty ?? 0) * product.price}}</computed>
  <computed name="total">{{$computed.subtotal + shippingFee}}</computed>
  ```

  > 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

  ```json
  {
    "computed": {
      "subtotal": "{{(_local.qty ?? 0) * product.price}}",
      "total": "{{$computed.subtotal + shippingFee}}"
    }
  }
  ```

- **뒤쪽 키를 읽으면 값 공유가 꺼집니다**: 위 예에서 `total` 을 `subtotal` 보다 위에 두면 `total` 은 직전 계산의 `subtotal` 을 읽습니다. 이 경우 엔진은 틀린 값이 자식에게 퍼지지 않도록 그 레이아웃에서 값 공유를 끄고 컴포넌트마다 계산하며(수정 전과 같은 동작), 디버그 모드에서 경고를 한 번 남깁니다. 참조하는 키를 **아래**에 두세요.
- **앞뒤 공백**: 정의 문자열의 앞뒤 공백은 무시하고 `{{ }}` 식으로 평가합니다.
- **비결정 식**: `$uuid`·`Date.now()` 처럼 부를 때마다 값이 바뀌는 식도 한 상태 범위 안에서는 한 번 계산한 값을 모든 자식이 나눠 씁니다. 컴포넌트마다 다른 값이 필요하면 computed 가 아니라 그 컴포넌트에 직접 적습니다.
- **평가 시점**: 라우트 진입 시 `init_actions` 실행 **전에** 진입 시점 상태로 한 번 계산하고, `init_actions` 가 끝난 뒤 다시 계산합니다. 따라서 `init_actions` 안에서 읽는 `$computed` 는 `init_actions` 가 바꾼 값을 아직 반영하지 않습니다. 액션 `sequence` 안에서는 `setState` 직후 computed 가 다시 계산되어 다음 단계가 최신값을 읽습니다.

### 구조

```json
{
  "version": "1.0.0",
  "layout_name": "product_detail",
  "computed": {
    "displayPrice": "{{$get(product.prices, [_global.currency, 'formatted'], product.price_formatted)}}",
    "canEdit": "{{!product.deleted_at && product.permissions?.can_edit}}",
    "statusClass": {
      "$switch": "{{product.status}}",
      "$cases": {
        "active": "text-green-500",
        "inactive": "text-gray-500",
        "discontinued": "text-red-500"
      },
      "$default": "text-gray-400"
    }
  },
  "components": [
    {
      "type": "basic",
      "name": "Span",
      "text": "{{$computed.displayPrice}}"
    }
  ]
}
```

### 필드 설명

| 필드 | 타입 | 설명 |
|------|------|------|
| `computed` | object | 키-표현식 쌍의 객체 |
| 각 키 | string \| object | 문자열 표현식 또는 $switch 객체 |

### 지원되는 값 타입

**1. 문자열 표현식**:

```html
<computed name="fullName">{{user.firstName}} {{user.lastName}}</computed>
<computed name="isAdmin">{{user.role === 'admin'}}</computed>
<computed name="formattedDate">{{created_at | date('YYYY-MM-DD')}}</computed>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "computed": {
    "fullName": "{{user.firstName}} {{user.lastName}}",
    "isAdmin": "{{user.role === 'admin'}}",
    "formattedDate": "{{created_at | date('YYYY-MM-DD')}}"
  }
}
```

**2. $switch 객체**:

```json
{
  "computed": {
    "statusIcon": {
      "$switch": "{{status}}",
      "$cases": {
        "success": "check-circle",
        "error": "x-circle",
        "warning": "alert-triangle"
      },
      "$default": "info"
    }
  }
}
```

### 실제 사용 예시

**다중 통화 가격 표시**:

```html
<computed name="displayPrice">{{$get(product.multi_currency_price, [_global.preferredCurrency ?? 'KRW', 'formatted'], product.price_formatted)}}</computed>
<computed name="originalPrice">{{$get(product.multi_currency_original_price, [_global.preferredCurrency ?? 'KRW', 'formatted'], product.original_price_formatted)}}</computed>
<span class="text-2xl font-bold">{{$computed.displayPrice}}</span>
<span data-if="{{product.has_discount}}" class="text-sm line-through text-gray-500">{{$computed.originalPrice}}</span>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "computed": {
    "displayPrice": "{{$get(product.multi_currency_price, [_global.preferredCurrency ?? 'KRW', 'formatted'], product.price_formatted)}}",
    "originalPrice": "{{$get(product.multi_currency_original_price, [_global.preferredCurrency ?? 'KRW', 'formatted'], product.original_price_formatted)}}"
  },
  "components": [
    {
      "type": "basic",
      "name": "Span",
      "props": { "className": "text-2xl font-bold" },
      "text": "{{$computed.displayPrice}}"
    },
    {
      "type": "basic",
      "name": "Span",
      "props": { "className": "text-sm line-through text-gray-500" },
      "text": "{{$computed.originalPrice}}",
      "if": "{{product.has_discount}}"
    }
  ]
}
```

**권한 기반 UI 제어**:

```html
<computed name="canEdit">{{!post.deleted_at &amp;&amp; post.permissions?.can_edit}}</computed>
<computed name="canDelete">{{post.permissions?.can_delete}}</computed>
<computed name="canRestore">{{post.deleted_at &amp;&amp; post.permissions?.can_restore}}</computed>
<button data-if="{{$computed.canEdit}}">$t:common.edit</button>
<button data-if="{{$computed.canDelete}}">$t:common.delete</button>
<button data-if="{{$computed.canRestore}}">$t:common.restore</button>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "computed": {
    "canEdit": "{{!post.deleted_at && post.permissions?.can_edit}}",
    "canDelete": "{{post.permissions?.can_delete}}",
    "canRestore": "{{post.deleted_at && post.permissions?.can_restore}}"
  },
  "components": [
    {
      "type": "basic",
      "name": "Button",
      "text": "$t:common.edit",
      "if": "{{$computed.canEdit}}"
    },
    {
      "type": "basic",
      "name": "Button",
      "text": "$t:common.delete",
      "if": "{{$computed.canDelete}}"
    },
    {
      "type": "basic",
      "name": "Button",
      "text": "$t:common.restore",
      "if": "{{$computed.canRestore}}"
    }
  ]
}
```

### 주의사항

```text
✅ 상태가 바뀌면 새로 평가됨 (식의 해석 결과만 재사용하고 값은 캐시하지 않음)
✅ iteration 항목·모달·격리 상태 영역은 각자 평가
✅ $switch 객체 형태도 지원
✅ 앞에 정의한 $computed 참조 가능 — 뒤에 정의한 키를 읽으면 값 공유가 꺼짐
⚠️ init_actions 안의 $computed 는 init_actions 실행 전 상태 기준 (끝난 뒤 재계산)
⚠️ 한 곳에서만 쓰는 식은 computed 로 빼지 말 것 (인라인)
⚠️ 서로를 참조하는 순환 정의는 금지 — 먼저 계산된 쪽이 상대의 직전 값을 읽음
```

---

## 관련 문서

- [레이아웃 JSON 기능 인덱스](layout-json-features.md)
- [에러 핸들링](layout-json-features-error.md)
- [초기화 및 모달](layout-json-features-actions.md)
- [다크 모드](dark-mode.md)
