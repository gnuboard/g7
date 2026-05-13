# sirsoft-basic 핸들러

> **템플릿 식별자**: `sirsoft-basic` (type: user)
> **관련 문서**: [액션 핸들러 개요](../../actions-handlers.md) | [컴포넌트](./components.md) | [레이아웃](./layouts.md)

---

## TL;DR (5초 요약)

```text
1. setTheme/initTheme: 다크/라이트 모드 전환 (admin과 동일 키 공유)
2. 장바구니 6종: 선택/옵션/삭제/재계산 핸들러
3. 상품 옵션 2종: 옵션 완료 시 자동 추가/수량 변경
4. 다중 통화 5종: 가격 표시/포맷/통화 기호/선호 통화 로드/저장
5. 스토리지 6종: 비회원 장바구니 키 관리 (localStorage + API 발급)
```

---

## 목차

1. [테마 핸들러](#테마-핸들러)
2. [장바구니 핸들러](#장바구니-핸들러)
3. [장바구니 옵션 변경 핸들러](#장바구니-옵션-변경-핸들러)
4. [상품 옵션 핸들러](#상품-옵션-핸들러)
5. [다중 통화 핸들러](#다중-통화-핸들러)
6. [스토리지 핸들러](#스토리지-핸들러)
7. [핸들러 등록 맵](#핸들러-등록-맵)

---

## 테마 핸들러

**소스**: `src/handlers/setThemeHandler.ts`

sirsoft-admin_basic과 동일한 localStorage 키(`g7_color_scheme`)를 사용하여 테마 설정을 공유합니다.

### setTheme

```json
{
  "type": "click",
  "handler": "setTheme",
  "params": {
    "theme": "dark"
  }
}
```

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `theme` | string | ✅ | `"light"`, `"dark"`, `"auto"` (시스템 설정 따름) |

### initTheme

앱 시작 시 `init_actions`에서 호출. params 없음.

```json
{
  "init_actions": [
    { "handler": "initTheme" }
  ]
}
```

---

## 장바구니 핸들러

**소스**: `src/handlers/cartHandlers.ts`

장바구니 페이지에서 상품 선택, 옵션 변경, 삭제 등을 처리합니다.

### toggleCartItemSelection

장바구니 아이템 선택/해제를 토글합니다.

```json
{
  "handler": "toggleCartItemSelection",
  "params": {
    "itemId": "{{item.id}}"
  }
}
```

### selectAllCartItems

모든 장바구니 아이템을 선택/해제합니다.

```json
{
  "handler": "selectAllCartItems",
  "params": {
    "selected": true
  }
}
```

### setCartOption

장바구니 아이템의 옵션을 변경합니다.

```json
{
  "handler": "setCartOption",
  "params": {
    "itemId": "{{item.id}}",
    "optionId": "{{selectedOption.id}}"
  }
}
```

### openCartDeleteModal

장바구니 삭제 확인 모달을 엽니다.

```json
{
  "handler": "openCartDeleteModal",
  "params": {
    "itemId": "{{item.id}}"
  }
}
```

### openCartOptionModal

장바구니 옵션 변경 모달을 엽니다.

```json
{
  "handler": "openCartOptionModal",
  "params": {
    "itemId": "{{item.id}}"
  }
}
```

### recalculateCart

장바구니 합계를 재계산합니다.

```json
{
  "handler": "recalculateCart"
}
```

### 장바구니 핸들러 요약

| 핸들러 | params | 설명 |
|--------|--------|------|
| `toggleCartItemSelection` | `{ itemId }` | 아이템 선택 토글 |
| `selectAllCartItems` | `{ selected }` | 전체 선택/해제 |
| `setCartOption` | `{ itemId, optionId }` | 옵션 변경 |
| `openCartDeleteModal` | `{ itemId }` | 삭제 모달 열기 |
| `openCartOptionModal` | `{ itemId }` | 옵션 변경 모달 열기 |
| `recalculateCart` | 없음 | 합계 재계산 |

---

## 장바구니 옵션 변경 핸들러

**소스**: `src/handlers/cartOptionChange.ts`

장바구니 옵션 변경 모달에서 옵션 선택 및 매칭을 처리합니다.

### findMatchingOption

선택한 옵션 값들로 매칭되는 상품 옵션을 찾습니다.

```json
{
  "handler": "findMatchingOption",
  "params": {
    "options": "{{_local.options}}",
    "selection": "{{_local.optionSelection}}"
  }
}
```

### initCartOptionSelection

옵션 변경 모달 초기화 시 현재 선택된 옵션을 설정합니다.

```json
{
  "handler": "initCartOptionSelection",
  "params": {
    "currentOption": "{{item.option}}"
  }
}
```

---

## 상품 옵션 핸들러

**소스**: `src/handlers/productOptions.ts`

상품 상세 페이지에서 옵션 선택 및 수량 변경을 처리합니다.

### addSelectedItemIfComplete (sirsoft-basic.addSelectedItemIfComplete)

모든 옵션 그룹 선택 완료 시 선택 아이템 목록에 자동 추가합니다.

```json
{
  "handler": "sirsoft-basic.addSelectedItemIfComplete",
  "params": {
    "newGroupName": "{{groupName}}",
    "newValue": "{{selectedValue}}",
    "optionGroups": "{{product?.data?.option_groups}}",
    "options": "{{product?.data?.options}}"
  }
}
```

### updateSelectedItemQuantity (sirsoft-basic.updateSelectedItemQuantity)

선택된 아이템의 수량을 변경합니다.

```json
{
  "handler": "sirsoft-basic.updateSelectedItemQuantity",
  "params": {
    "optionId": "{{option.id}}",
    "quantity": "{{newQuantity}}"
  }
}
```

---

## 다중 통화 핸들러

### getDisplayPrice (sirsoft-basic.getDisplayPrice)

**소스**: `src/handlers/getDisplayPrice.ts`

사용자 선호 통화에 맞는 가격을 반환합니다.

```json
{
  "handler": "sirsoft-basic.getDisplayPrice",
  "params": {
    "product": "{{product.data}}",
    "priceField": "selling_price"
  }
}
```

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `product` | object | ✅ | 상품 객체 |
| `priceField` | string | ✅ | `"selling_price"` 또는 `"list_price"` |
| `currencyCode` | string | ❌ | 통화 코드 (미지정 시 전역 설정 사용) |

### formatCurrency (sirsoft-basic.formatCurrency)

**소스**: `src/handlers/formatCurrency.ts`

숫자 값을 통화 형식 문자열로 변환합니다.

```json
{
  "handler": "sirsoft-basic.formatCurrency",
  "params": {
    "value": 10000,
    "currencyCode": "KRW"
  }
}
```

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `value` | number | ✅ | 포맷팅할 숫자 값 |
| `currencyCode` | string | ❌ | 통화 코드 (KRW, USD, JPY, CNY, EUR) |
| `locale` | string | ❌ | 로케일 (미지정 시 통화 기본 로케일 사용) |

지원 통화: KRW (₩), USD ($), JPY (¥), CNY (¥), EUR (€)

### getCurrencySymbol (sirsoft-basic.getCurrencySymbol)

통화 기호를 반환합니다.

### loadPreferredCurrency (sirsoft-basic.loadPreferredCurrency)

**소스**: `src/handlers/loadPreferredCurrency.ts`

localStorage에서 선호 통화를 로드하여 전역 상태(`_global.preferredCurrency`)에 설정합니다.

```json
{
  "init_actions": [
    {
      "handler": "sirsoft-basic.loadPreferredCurrency",
      "params": { "defaultCurrency": "KRW" }
    }
  ]
}
```

### savePreferredCurrency (sirsoft-basic.savePreferredCurrency)

선호 통화를 localStorage에 저장합니다.

```json
{
  "handler": "sirsoft-basic.savePreferredCurrency",
  "params": {
    "currencyCode": "{{selectedCurrency}}"
  }
}
```

---

## 스토리지 핸들러

**소스**: `src/handlers/storageHandlers.ts`

비로그인 사용자의 장바구니 키 등 클라이언트 스토리지를 관리합니다.

### initCartKey

장바구니 키를 초기화합니다. localStorage에 있으면 로드, 없으면 API를 통해 발급합니다.

```json
{
  "init_actions": [
    { "handler": "initCartKey" }
  ]
}
```

### getCartKey / clearCartKey / regenerateCartKey

```json
{ "handler": "getCartKey" }
{ "handler": "clearCartKey" }
{ "handler": "regenerateCartKey" }
```

### saveToStorage / loadFromStorage

범용 localStorage 저장/로드 핸들러입니다.

```json
{
  "handler": "saveToStorage",
  "params": {
    "key": "g7_some_setting",
    "value": "{{_local.settingValue}}"
  }
}
```

```json
{
  "handler": "loadFromStorage",
  "params": {
    "key": "g7_some_setting",
    "stateKey": "savedSetting"
  }
}
```

### 스토리지 핸들러 요약

| 핸들러 | params | 설명 |
|--------|--------|------|
| `initCartKey` | 없음 | 장바구니 키 초기화 (localStorage + API) |
| `getCartKey` | 없음 | 현재 장바구니 키 반환 |
| `clearCartKey` | 없음 | 장바구니 키 삭제 |
| `regenerateCartKey` | 없음 | 장바구니 키 재발급 |
| `saveToStorage` | `{ key, value }` | localStorage 저장 |
| `loadFromStorage` | `{ key, stateKey }` | localStorage 로드 → 상태에 설정 |

---

## 핸들러 등록 맵

**소스**: `src/handlers/index.ts`

| 등록 키 | 소스 파일 | 설명 |
|---------|----------|------|
| `setTheme` | setThemeHandler.ts | 테마 변경 |
| `initTheme` | setThemeHandler.ts | 테마 초기화 |
| `toggleCartItemSelection` | cartHandlers.ts | 장바구니 아이템 선택 |
| `selectAllCartItems` | cartHandlers.ts | 장바구니 전체 선택 |
| `setCartOption` | cartHandlers.ts | 장바구니 옵션 변경 |
| `openCartDeleteModal` | cartHandlers.ts | 삭제 모달 열기 |
| `openCartOptionModal` | cartHandlers.ts | 옵션 모달 열기 |
| `recalculateCart` | cartHandlers.ts | 장바구니 재계산 |
| `findMatchingOption` | cartOptionChange.ts | 매칭 옵션 검색 |
| `initCartOptionSelection` | cartOptionChange.ts | 옵션 선택 초기화 |
| `sirsoft-basic.addSelectedItemIfComplete` | productOptions.ts | 옵션 완료 시 자동 추가 |
| `sirsoft-basic.updateSelectedItemQuantity` | productOptions.ts | 수량 변경 |
| `sirsoft-basic.getDisplayPrice` | getDisplayPrice.ts | 통화별 가격 표시 |
| `sirsoft-basic.formatCurrency` | formatCurrency.ts | 통화 포맷팅 |
| `sirsoft-basic.getCurrencySymbol` | formatCurrency.ts | 통화 기호 |
| `sirsoft-basic.loadPreferredCurrency` | loadPreferredCurrency.ts | 선호 통화 로드 |
| `sirsoft-basic.savePreferredCurrency` | loadPreferredCurrency.ts | 선호 통화 저장 |
| `initCartKey` | storageHandlers.ts | 장바구니 키 초기화 |
| `getCartKey` | storageHandlers.ts | 장바구니 키 조회 |
| `clearCartKey` | storageHandlers.ts | 장바구니 키 삭제 |
| `regenerateCartKey` | storageHandlers.ts | 장바구니 키 재발급 |
| `saveToStorage` | storageHandlers.ts | localStorage 저장 |
| `loadFromStorage` | storageHandlers.ts | localStorage 로드 |

---

## 주의사항

```text
이 핸들러들은 sirsoft-basic 템플릿에서만 등록됨
sirsoft-basic. 접두사 핸들러는 풀네임으로 호출해야 함
setLocale은 엔진 레벨(ActionDispatcher) 빌트인 — 별도 등록 불필요
✅ 범용 핸들러(navigate, apiCall, setState 등)는 actions-handlers.md 참조
```

---

## 관련 문서

- [액션 핸들러 개요](../../actions-handlers.md)
- [sirsoft-basic 컴포넌트](./components.md)
- [sirsoft-basic 레이아웃](./layouts.md)
- [sirsoft-admin_basic 핸들러](../sirsoft-admin_basic/handlers.md)
