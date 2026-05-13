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

```json
{
  "className": "{{row.status === 'success' ? 'bg-green-100 text-green-800' : row.status === 'danger' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-600'}} px-2 py-1 rounded-full text-xs font-medium"
}
```

**개선 (단순)**:

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
      "key": "status",
      "label": "$t:common.status",
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

**목적**: 레이아웃 수준에서 재사용 가능한 계산된 값을 정의합니다. 복잡한 표현식을 한 번 정의하고 `$computed.xxx`로 여러 곳에서 참조할 수 있습니다.

### 핵심 원칙

```text
✅ 레이아웃 최상위에 computed 섹션 정의
✅ $computed.키이름 으로 컴포넌트에서 접근
✅ 문자열 표현식 또는 $switch 객체 지원
✅ 각 렌더링마다 새로 평가 (캐싱 없음)
```

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
✅ computed는 매 렌더링마다 새로 평가됨 (캐싱 없음)
✅ iteration 컨텍스트에서 각 아이템별 독립 평가
✅ $switch 객체 형태도 지원
init_actions 시점에는 computed가 아직 평가되지 않음
computed 내에서 다른 $computed 참조 불가 (순환 참조 방지)
과도하게 복잡한 computed는 성능 저하 가능
```

---

## 관련 문서

- [레이아웃 JSON 기능 인덱스](layout-json-features.md)
- [에러 핸들링](layout-json-features-error.md)
- [초기화 및 모달](layout-json-features-actions.md)
- [다크 모드](dark-mode.md)
