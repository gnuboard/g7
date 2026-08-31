# Basic — 핸들러

> 템플릿 전용 핸들러와 부트스트랩 · 진입점: [AGENTS.md](../AGENTS.md)

## 템플릿 전용 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 32개 (정의: `src/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `addSelectedItemIfComplete` | `sirsoft-basic.addSelectedItemIfComplete` |
| `updateSelectedItemQuantity` | `sirsoft-basic.updateSelectedItemQuantity` |
| `removeSelectedItem` | `sirsoft-basic.removeSelectedItem` |
| `updateNoOptionQuantity` | `sirsoft-basic.updateNoOptionQuantity` |
| `setBlockAdditionalOption` | `sirsoft-basic.setBlockAdditionalOption` |
| `getDisplayPrice` | `sirsoft-basic.getDisplayPrice` |
| `formatCurrency` | `sirsoft-basic.formatCurrency` |
| `getCurrencySymbol` | `sirsoft-basic.getCurrencySymbol` |
| `loadPreferredCurrency` | `sirsoft-basic.loadPreferredCurrency` |
| `savePreferredCurrency` | `sirsoft-basic.savePreferredCurrency` |
| `setTheme` | (템플릿 전용 — 네임스페이스 없음) |
| `initTheme` | (템플릿 전용 — 네임스페이스 없음) |
| `redirectToLoginWithReturn` | (템플릿 전용 — 네임스페이스 없음) |
| `downloadAttachment` | (템플릿 전용 — 네임스페이스 없음) |
| `toggleCartItemSelection` | (템플릿 전용 — 네임스페이스 없음) |
| `selectAllCartItems` | (템플릿 전용 — 네임스페이스 없음) |
| `setCartOption` | (템플릿 전용 — 네임스페이스 없음) |
| `openCartDeleteModal` | (템플릿 전용 — 네임스페이스 없음) |
| `openCartOptionModal` | (템플릿 전용 — 네임스페이스 없음) |
| `recalculateCart` | (템플릿 전용 — 네임스페이스 없음) |
| `findMatchingOption` | (템플릿 전용 — 네임스페이스 없음) |
| `initCartOptionSelection` | (템플릿 전용 — 네임스페이스 없음) |
| `initCartKey` | (템플릿 전용 — 네임스페이스 없음) |
| `getCartKey` | (템플릿 전용 — 네임스페이스 없음) |
| `clearCartKey` | (템플릿 전용 — 네임스페이스 없음) |
| `regenerateCartKey` | (템플릿 전용 — 네임스페이스 없음) |
| `saveToStorage` | (템플릿 전용 — 네임스페이스 없음) |
| `loadFromStorage` | (템플릿 전용 — 네임스페이스 없음) |
| `initGuestOrderToken` | (템플릿 전용 — 네임스페이스 없음) |
| `saveGuestOrderToken` | (템플릿 전용 — 네임스페이스 없음) |
| `clearGuestOrderToken` | (템플릿 전용 — 네임스페이스 없음) |
| `clearGuestTokenOnEntry` | (템플릿 전용 — 네임스페이스 없음) |
<!-- @generated:handlers END -->

<!-- @intent START -->
32개가 **두 종류로 갈립니다** — 네임스페이스를 붙여 등록한 10개(`sirsoft-basic.*`)와 붙이지
않은 22개. 레이아웃에서 부를 때 전자는 전체 이름을 그대로 써야 하고, 후자는 이름만 씁니다.

| 무리 | 예 | 무엇을 하는가 |
|---|---|---|
| 상품 옵션 (`sirsoft-basic.*` 5) | `addSelectedItemIfComplete` · `updateSelectedItemQuantity` · `removeSelectedItem` · `updateNoOptionQuantity` · `setBlockAdditionalOption` | 상품 상세에서 옵션 조합이 완성될 때마다 선택 목록을 갱신 |
| 통화 (`sirsoft-basic.*` 5) | `getDisplayPrice` · `formatCurrency` · `getCurrencySymbol` · `loadPreferredCurrency` · `savePreferredCurrency` | 표시 통화 전환과 금액 포맷 |
| 테마 2 | `setTheme` · `initTheme` | 다크/라이트 전환 (관리자 템플릿과 **같은 localStorage 키**를 공유) |
| 장바구니 8 | `toggleCartItemSelection` · `setCartOption` · `recalculateCart` · `findMatchingOption` 등 | 선택·옵션 변경·재계산 |
| 저장소 6 | `initCartKey` · `getCartKey` · `saveToStorage` 등 | 비회원 장바구니 키 관리(localStorage + API 발급) |
| 비회원 주문 4 | `initGuestOrderToken` · `saveGuestOrderToken` · `clearGuestOrderToken` · `clearGuestTokenOnEntry` | 비회원 주문 조회 토큰 보관·폐기 |
| 기타 2 | `redirectToLoginWithReturn` · `downloadAttachment` | 로그인 후 원래 자리 복귀, 첨부 내려받기 |

**비회원 토큰 4종이 이 템플릿에서 가장 조심스러운 자리입니다.** 그 토큰이 곧 신원이므로
(서버측 `VerifyGuestOrderToken` 이 그것만 보고 주문을 엽니다), 브라우저에 남아 있으면 다음
사용자가 남의 주문을 열 수 있습니다. `clearGuestTokenOnEntry` 가 진입 시점에 정리하는 것이
그 방어입니다.

`setLocale` 은 이 템플릿의 핸들러가 아닙니다 — 엔진(`ActionDispatcher`) 빌트인이라 등록이
필요 없습니다.
<!-- @intent END -->

## 부트스트랩

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `src/index.ts` |
| 전역 객체 | **미노출** |
| 재등록 진입점 | `initTemplate()` |

재등록 진입점이 전역에 고정 이름으로 노출되지 않으면 로케일 전환 후 이 확장의 액션이 전부 무반응이 됩니다 (오류·토스트 없음).
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
전역 객체가 **미노출**입니다. 모듈·플러그인은 `window.__[Name].initModule/initPlugin` 을 고정
이름으로 노출해야 하지만(로케일 전환 후 코어가 그것을 다시 부릅니다), 템플릿은 코어가 부트스트랩
경로를 직접 알고 있어 전역 노출이 필요하지 않습니다.

`initTemplate()` 이 그 진입점이며 모듈 로드 시점에 스스로 실행됩니다. 하는 일이 셋입니다:

1. **핸들러 등록** — `handlerMap` 전량을 `ActionDispatcher` 에 올립니다. 개별 등록이 아니라
   맵을 순회하므로, 핸들러를 추가할 때 등록 코드를 함께 고칠 필요가 없습니다.
2. **IDV launcher 등록** — `window.G7Core.identity.setLauncher()` 로 본인인증 화면을 여는
   방법을 코어에 알립니다. 이것이 없으면 428 응답을 받은 화면이 인증 창을 띄우지 못합니다.
3. **iOS 판정 보정** — 서버 UA 판정이 놓치는 iPadOS(데스크탑 UA)를 클라이언트 신호로 바로잡아
   `appConfig.isIos` 에 반영합니다. 체크아웃의 애플페이 노출이 이 값을 봅니다.

**`ActionDispatcher` 가용을 기다리는 재시도 루프**(100ms × 최대 50회)가 들어 있습니다.
`window.load` 이후에 시작하며, 그 안에서 세 작업이 함께 일어납니다.

레이아웃 편집기 위젯 등록만은 **이 함수 밖**에서 모듈 로드 즉시 실행됩니다. 편집기 URL 을 직접
하드로드한 경로에서는 `window.load` 게이트를 기다리면 등록이 편집기 셸 마운트보다 늦어 위젯이
누락되기 때문입니다("Unsupported control"). 진입 경로와 무관하게 결정적이어야 하는 등록은 이
자리에 둡니다.

이 템플릿은 `AuthManager.updateConfig()` 를 부르지 않습니다 — 코어 기본값을 그대로 씁니다.
호출이 필요해지면 **템플릿 부트스트랩에서만** 하고(모듈·플러그인에서 부르면 안 됩니다),
`loginPath` 는 `/` 로 시작하는 동일 origin 경로여야 합니다. `//` 로 시작하거나 외부 origin 을
주면 open redirect 가 됩니다.
<!-- @intent END -->

## 이관 원문 상세

> 아래는 코어 `docs/frontend/templates/sirsoft-basic/handlers.md` 에 있던 원문을 이 문서로 옮긴 것입니다(#601). 이관 시점 그대로
> 보존하되, **코드가 SSoT 인 값과 어긋나는 부분에는 정정 주석**을 달았습니다.

### sirsoft-basic 핸들러

> **템플릿 식별자**: `sirsoft-basic` (type: user)
> **관련 문서**: [액션 핸들러 개요](../../../../docs/frontend/actions-handlers.md) | [컴포넌트](components.md) | [레이아웃](layouts.md)

---

### TL;DR (5초 요약)

```text
1. setTheme/initTheme: 다크/라이트 모드 전환 (admin과 동일 키 공유)
2. 장바구니 6종: 선택/옵션/삭제/재계산 핸들러
3. 상품 옵션 2종: 옵션 완료 시 자동 추가/수량 변경
4. 다중 통화 5종: 가격 표시/포맷/통화 기호/선호 통화 로드/저장
5. 스토리지 6종: 비회원 장바구니 키 관리 (localStorage + API 발급)
```

---

### 목차

1. [테마 핸들러](#테마-핸들러)
2. [장바구니 핸들러](#장바구니-핸들러)
3. [장바구니 옵션 변경 핸들러](#장바구니-옵션-변경-핸들러)
4. [상품 옵션 핸들러](#상품-옵션-핸들러)
5. [다중 통화 핸들러](#다중-통화-핸들러)
6. [스토리지 핸들러](#스토리지-핸들러)
7. [핸들러 등록 맵](#핸들러-등록-맵)

---

### 테마 핸들러

**소스**: `src/handlers/setThemeHandler.ts`

sirsoft-admin_basic과 동일한 localStorage 키(`g7_color_scheme`)를 사용하여 테마 설정을 공유합니다.

#### setTheme

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

#### initTheme

앱 시작 시 `init_actions`에서 호출. params 없음.

```json
{
  "init_actions": [
    { "handler": "initTheme" }
  ]
}
```

---

### 장바구니 핸들러

**소스**: `src/handlers/cartHandlers.ts`

장바구니 페이지에서 상품 선택, 옵션 변경, 삭제 등을 처리합니다.

#### toggleCartItemSelection

장바구니 아이템 선택/해제를 토글합니다.

```json
{
  "handler": "toggleCartItemSelection",
  "params": {
    "itemId": "{{item.id}}"
  }
}
```

#### selectAllCartItems

모든 장바구니 아이템을 선택/해제합니다.

```json
{
  "handler": "selectAllCartItems",
  "params": {
    "selected": true
  }
}
```

#### setCartOption

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

#### openCartDeleteModal

장바구니 삭제 확인 모달을 엽니다.

```json
{
  "handler": "openCartDeleteModal",
  "params": {
    "itemId": "{{item.id}}"
  }
}
```

#### openCartOptionModal

장바구니 옵션 변경 모달을 엽니다.

```json
{
  "handler": "openCartOptionModal",
  "params": {
    "itemId": "{{item.id}}"
  }
}
```

#### recalculateCart

장바구니 합계를 재계산합니다.

```json
{
  "handler": "recalculateCart"
}
```

#### 장바구니 핸들러 요약

| 핸들러 | params | 설명 |
|--------|--------|------|
| `toggleCartItemSelection` | `{ itemId }` | 아이템 선택 토글 |
| `selectAllCartItems` | `{ selected }` | 전체 선택/해제 |
| `setCartOption` | `{ itemId, optionId }` | 옵션 변경 |
| `openCartDeleteModal` | `{ itemId }` | 삭제 모달 열기 |
| `openCartOptionModal` | `{ itemId }` | 옵션 변경 모달 열기 |
| `recalculateCart` | 없음 | 합계 재계산 |

---

### 장바구니 옵션 변경 핸들러

**소스**: `src/handlers/cartOptionChange.ts`

장바구니 옵션 변경 모달에서 옵션 선택 및 매칭을 처리합니다.

#### findMatchingOption

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

#### initCartOptionSelection

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

### 상품 옵션 핸들러

**소스**: `src/handlers/productOptions.ts`

상품 상세 페이지에서 옵션 선택 및 수량 변경을 처리합니다.

#### addSelectedItemIfComplete (sirsoft-basic.addSelectedItemIfComplete)

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

#### updateSelectedItemQuantity (sirsoft-basic.updateSelectedItemQuantity)

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

### 다중 통화 핸들러

#### getDisplayPrice (sirsoft-basic.getDisplayPrice)

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

#### formatCurrency (sirsoft-basic.formatCurrency)

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

#### getCurrencySymbol (sirsoft-basic.getCurrencySymbol)

통화 기호를 반환합니다.

#### loadPreferredCurrency (sirsoft-basic.loadPreferredCurrency)

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

#### savePreferredCurrency (sirsoft-basic.savePreferredCurrency)

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

### 스토리지 핸들러

**소스**: `src/handlers/storageHandlers.ts`

비로그인 사용자의 장바구니 키 등 클라이언트 스토리지를 관리합니다.

#### initCartKey

장바구니 키를 초기화합니다. localStorage에 있으면 로드, 없으면 API를 통해 발급합니다.

```json
{
  "init_actions": [
    { "handler": "initCartKey" }
  ]
}
```

#### getCartKey / clearCartKey / regenerateCartKey

```json
{ "handler": "getCartKey" }
{ "handler": "clearCartKey" }
{ "handler": "regenerateCartKey" }
```

#### saveToStorage / loadFromStorage

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

#### 스토리지 핸들러 요약

| 핸들러 | params | 설명 |
|--------|--------|------|
| `initCartKey` | 없음 | 장바구니 키 초기화 (localStorage + API) |
| `getCartKey` | 없음 | 현재 장바구니 키 반환 |
| `clearCartKey` | 없음 | 장바구니 키 삭제 |
| `regenerateCartKey` | 없음 | 장바구니 키 재발급 |
| `saveToStorage` | `{ key, value }` | localStorage 저장 |
| `loadFromStorage` | `{ key, stateKey }` | localStorage 로드 → 상태에 설정 |

---

### 핸들러 등록 맵

> **정정(#601)**: 아래 표는 이관 시점 값(23개)입니다. 코드 실측은 **32개**이며 위 「템플릿 전용
> 핸들러」 블록이 SSoT 입니다. 아래 표에 없는 9개는
> `sirsoft-basic.removeSelectedItem` · `sirsoft-basic.updateNoOptionQuantity` ·
> `sirsoft-basic.setBlockAdditionalOption` · `redirectToLoginWithReturn` ·
> `downloadAttachment` · `initGuestOrderToken` · `saveGuestOrderToken` ·
> `clearGuestOrderToken` · `clearGuestTokenOnEntry` 입니다.

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

### 주의사항

```text
이 핸들러들은 sirsoft-basic 템플릿에서만 등록됨
sirsoft-basic. 접두사 핸들러는 풀네임으로 호출해야 함
setLocale은 엔진 레벨(ActionDispatcher) 빌트인 — 별도 등록 불필요
✅ 범용 핸들러(navigate, apiCall, setState 등)는 actions-handlers.md 참조
```

---

### 관련 문서

- [액션 핸들러 개요](../../../../docs/frontend/actions-handlers.md)
- [sirsoft-basic 컴포넌트](components.md)
- [sirsoft-basic 레이아웃](layouts.md)
- [sirsoft-admin_basic 핸들러](../../sirsoft-admin_basic/docs/handlers.md)
