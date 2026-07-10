# Cart API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Cart 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### DELETE /api/modules/sirsoft-ecommerce/cart
<!-- @generated:start:api.modules.sirsoft-ecommerce.cart.destroy-multiple -->
- **라우트명**: `api.modules.sirsoft-ecommerce.cart.destroy-multiple`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CartController@destroyMultiple`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | query | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.cart.delete_items_validation_rules`).

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/cart?ids=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 장바구니에서 선택한 여러 아이템(`ids`)을 한 번에 삭제합니다. `optional.sanctum`으로 회원은 `Auth::id()`, 비회원은 cart_key(`X-Cart-Key`)로 소유를 식별하며, `CartController@destroyMultiple`이 `CartService::deleteItems()`를 호출해 삭제된 건수(`deleted_count`)를 반환합니다. 장바구니 화면에서 체크박스로 선택한 항목들을 "선택 삭제"할 때 사용합니다.


### GET /api/modules/sirsoft-ecommerce/cart
<!-- @generated:start:api.modules.sirsoft-ecommerce.cart.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.cart.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CartController@index`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| selected_ids | query | array | 아니오 | — | selected 식별자 배열 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.cart.get_validation_rules`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/cart?selected_ids=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| items | array | `[{"id":962,"product_id":201,"product_option_id":1086,"qua…` | 장바구니 라인 아이템 목록 (상품·옵션·수량 등 — CartItemResource 파생) |
| item_ids | array | `[962]` | item 식별자 배열 (연관 리소스 참조) |
| item_count | integer | `1` | item 개수 (집계) |
| calculation | object | `{"items":[{"product_id":201,"product_option_id":1086,"pro…` | 선택 아이템 기준 금액 계산 결과 (상품 소계·할인·배송비 등 — OrderCalculationResult 파생) |
| has_unshippable_items | boolean | `false` | unshippable items 여부 |
| selected_shipping_country | string | `KR` | 배송비 계산에 적용된 배송 국가 코드 (ResolveShippingCountry 해석 결과) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "장바구니 목록을 조회했습니다.",
    "data": {
        "items": [],
        "item_ids": [],
        "item_count": 0,
        "calculation": {
            "items": [],
            "summary": {
                "subtotal": 0,
                "subtotal_formatted": "0원",
                "product_coupon_discount": 0,
                "product_coupon_discount_formatted": "0원",
                "code_discount": 0,
                "code_discount_formatted": "0원",
                "order_coupon_discount": 0,
                "order_coupon_discount_formatted": "0원",
                "total_coupon_discount": 0,
                "total_coupon_discount_formatted": "0원",
                "total_discount": 0,
                "discount_formatted": "0원",
                "base_shipping_total": 0,
                "extra_shipping_total": 0,
                "total_shipping": 0,
                "total_shipping_fee": 0,
                "shipping_fee_formatted": "0원",
                "shipping_discount": 0,
                "shipping_discount_formatted": "0원",
                "taxable_amount": 0,
                "tax_free_amount": 0,
                "points_earning": 0,
                "total_mileage": 0,
                "mileage_formatted": "0P",
                "points_used": 0,
                "points_used_formatted": "0원",
                "payment_amount": 0,
                "payment_amount_formatted": "0원",
                "final_amount": 0,
                "final_amount_formatted": "0원",
                "coupon_discount": 0,
                "coupon_discount_formatted": "0원",
                "order_discount": 0,
                "order_discount_formatted": "0원"
            },
            "promotions": {
                "coupon_issue_ids": [],
                "item_coupons": [],
                "discount_code": null,
                "product_promotions": {
                    "coupons": [],
                    "discount_codes": [],
                    "events": []
                },
                "order_promotions": {
                    "coupons": [],
                    "discount_codes": [],
                    "events": []
                }
            },
            "validation_errors": []
        },
        "has_unshippable_items": false,
        "selected_shipping_country": "KR"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 장바구니 목록과 함께 가격 정보(소계·할인·배송비 등 `calculation`)를 계산해 반환합니다. `optional.sanctum`으로 회원은 `Auth::id()`, 비회원은 cart_key(`X-Cart-Key`)로 장바구니를 식별하며, `CartController@index`가 `CartService::getCartWithCalculation()`을 호출합니다. `selected_ids`를 전달하면 해당 아이템만 계산에 포함되고(미전달=전체, 빈 배열=계산 생략), 선택된 배송 국가로 배송 불가한 상품이 있으면 `has_unshippable_items`가 true가 됩니다. 비회원인데 cart_key가 없거나 형식(`ck_`+32자)이 틀리면 400을 반환합니다.


### POST /api/modules/sirsoft-ecommerce/cart
<!-- @generated:start:api.modules.sirsoft-ecommerce.cart.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.cart.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CartController@store`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| product_id | body | integer | 예 | — | product 식별자 |
| items | body | array | 예 | min 1 | 처리 대상 항목 배열 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.cart.bulk_add_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/cart HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
Content-Type: application/json

{
    "product_id": 1,
    "items": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 하나의 상품에 대해 단일 또는 여러 옵션 조합을 `items[]` 배열로 한 번에 장바구니에 담습니다. `optional.sanctum`으로 회원/비회원 모두 접근하며, `CartController@store`가 `CartService::bulkAddToCart()`를 호출한 뒤 추가된 아이템 목록과 총 담긴 수량(`cart_count`)을 201로 반환합니다. 재고 부족·판매 중지·구매 대상 제한·구매 수량 한도 위반은 사유별 422(cart_unavailable/purchase_not_allowed), 항목/권한/옵션 문제는 404/403/422로 매핑됩니다. 비회원은 cart_key(`X-Cart-Key`) 검증을 통과해야 합니다.


### DELETE /api/modules/sirsoft-ecommerce/cart/all
<!-- @generated:start:api.modules.sirsoft-ecommerce.cart.destroy-all -->
- **라우트명**: `api.modules.sirsoft-ecommerce.cart.destroy-all`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CartController@destroyAll`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/cart/all HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted_count | integer | `0` | deleted 개수 (집계) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "장바구니가 비워졌습니다. 0개 상품이 삭제되었습니다.",
    "data": {
        "deleted_count": 0
    }
}
```

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

<!-- @generated:end -->

**설명** 현재 회원/비회원 장바구니의 모든 아이템을 삭제해 장바구니를 비웁니다. `optional.sanctum`으로 회원은 `Auth::id()`, 비회원은 cart_key로 대상을 식별하며, `CartController@destroyAll`이 `CartService::deleteAll()`을 호출해 삭제된 건수(`deleted_count`)를 반환합니다. 장바구니 화면의 "전체 비우기" 동작에 사용합니다.


### GET /api/modules/sirsoft-ecommerce/cart/count
<!-- @generated:start:api.modules.sirsoft-ecommerce.cart.count -->
- **라우트명**: `api.modules.sirsoft-ecommerce.cart.count`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CartController@count`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| selected_ids | query | array | 아니오 | — | selected 식별자 배열 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.cart.get_validation_rules`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/cart/count?selected_ids=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| count | integer | `1` | 장바구니에 담긴 아이템 개수 (`selected_ids` 지정 시 해당 항목만 집계) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "장바구니 수량을 조회했습니다.",
    "data": {
        "count": 0
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 장바구니에 담긴 아이템 개수(`count`)만 가볍게 조회합니다. `optional.sanctum`으로 회원/비회원 모두 접근하며, `CartController@count`가 `CartService::getItemCount()`를 호출합니다. 전체 목록·계산 결과가 필요 없는 헤더의 장바구니 배지 카운트 갱신 등에 사용하며, `selected_ids`로 특정 아이템만 집계할 수도 있습니다.


### POST /api/modules/sirsoft-ecommerce/cart/key
<!-- @generated:start:api.modules.sirsoft-ecommerce.cart.key -->
- **라우트명**: `api.modules.sirsoft-ecommerce.cart.key`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CartController@issueCartKey`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/cart/key HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| cart_key | string | `ck_QBQT80EINHHXLO8HYHntMpmUZrPujPka` | 비회원 장바구니 식별 키 (`ck_` + 32자 영숫자. 이후 요청의 `X-Cart-Key` 헤더에 실어 자신의 장바구니를 식별) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "장바구니 키가 발급되었습니다.",
    "data": {
        "cart_key": "ck_QBQT80EINHHXLO8HYHntMpmUZrPujPka"
    }
}
```

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

<!-- @generated:end -->

**설명** 비회원 장바구니를 식별하기 위한 cart_key(`ck_`+32자 영숫자)를 발급합니다. 인증이 필요 없으며(`optional.sanctum`), `CartController@issueCartKey`가 `CartService::issueCartKey()`로 키를 생성해 반환합니다. 비회원은 이 키를 `X-Cart-Key` 헤더에 실어 이후 장바구니 담기·조회·수정 요청에서 자신의 장바구니를 식별합니다. 비회원 쇼핑 시작 시점(첫 장바구니 담기 전)에 한 번 호출해 클라이언트에 저장해 둡니다.


### POST /api/modules/sirsoft-ecommerce/cart/merge
<!-- @generated:start:api.modules.sirsoft-ecommerce.cart.merge -->
- **라우트명**: `api.modules.sirsoft-ecommerce.cart.merge`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CartController@merge`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/cart/merge HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

<!-- @generated:end -->

**설명** 비회원 상태에서 담아 둔 장바구니를 로그인한 회원 계정으로 병합합니다. 로그인 직후 클라이언트가 보유한 cart_key(`X-Cart-Key`)를 실어 호출하면, `CartController@merge`가 `CartService::mergeGuestCartToUser()`로 해당 cart_key의 비회원 아이템을 회원 장바구니로 옮기고 병합된 건수(`merged_count`)를 반환합니다. 비회원으로 담던 상품이 로그인 후 사라지지 않도록 인증 성공 시점에 1회 호출합니다.


### POST /api/modules/sirsoft-ecommerce/cart/query
<!-- @generated:start:api.modules.sirsoft-ecommerce.cart.query -->
- **라우트명**: `api.modules.sirsoft-ecommerce.cart.query`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CartController@index`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| selected_ids | body | array | 아니오 | — | selected 식별자 배열 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.cart.get_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/cart/query HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
Content-Type: application/json

{
    "selected_ids": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| items | array | `[]` | 장바구니 라인 아이템 목록 (상품·옵션·수량 등 — CartItemResource 파생) |
| item_ids | array | `[]` | item 식별자 배열 (연관 리소스 참조) |
| item_count | integer | `0` | item 개수 (집계) |
| calculation | object | `{"items":[],"summary":{"subtotal":0,"subtotal_formatted":…` | 선택 아이템 기준 금액 계산 결과 (상품 소계·할인·배송비 등 — OrderCalculationResult 파생) |
| has_unshippable_items | boolean | `false` | unshippable items 여부 |
| selected_shipping_country | string | `KR` | 배송비 계산에 적용된 배송 국가 코드 (ResolveShippingCountry 해석 결과) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "장바구니 목록을 조회했습니다.",
    "data": {
        "items": [],
        "item_ids": [],
        "item_count": 0,
        "calculation": {
            "items": [],
            "summary": {
                "subtotal": 0,
                "subtotal_formatted": "0원",
                "product_coupon_discount": 0,
                "product_coupon_discount_formatted": "0원",
                "code_discount": 0,
                "code_discount_formatted": "0원",
                "order_coupon_discount": 0,
                "order_coupon_discount_formatted": "0원",
                "total_coupon_discount": 0,
                "total_coupon_discount_formatted": "0원",
                "total_discount": 0,
                "discount_formatted": "0원",
                "base_shipping_total": 0,
                "extra_shipping_total": 0,
                "total_shipping": 0,
                "total_shipping_fee": 0,
                "shipping_fee_formatted": "0원",
                "shipping_discount": 0,
                "shipping_discount_formatted": "0원",
                "taxable_amount": 0,
                "tax_free_amount": 0,
                "points_earning": 0,
                "total_mileage": 0,
                "mileage_formatted": "0P",
                "points_used": 0,
                "points_used_formatted": "0원",
                "payment_amount": 0,
                "payment_amount_formatted": "0원",
                "final_amount": 0,
                "final_amount_formatted": "0원",
                "coupon_discount": 0,
                "coupon_discount_formatted": "0원",
                "order_discount": 0,
                "order_discount_formatted": "0원"
            },
            "promotions": {
                "coupon_issue_ids": [],
                "item_coupons": [],
                "discount_code": null,
                "product_promotions": {
                    "coupons": [],
                    "discount_codes": [],
                    "events": []
                },
                "order_promotions": {
                    "coupons": [],
                    "discount_codes": [],
                    "events": []
                }
            },
            "validation_errors": []
        },
        "has_unshippable_items": false,
        "selected_shipping_country": "KR"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** GET `/cart`와 동일하게 장바구니 목록과 가격 계산 결과를 반환하되, `selected_ids`를 GET 쿼리 대신 POST 본문으로 전달하는 변형 엔드포인트입니다(같은 `CartController@index` 처리). 선택 아이템 배열이 커서 URL 길이 제한이 우려되는 경우에 사용합니다. `optional.sanctum`으로 회원/비회원 모두 접근하며, 응답 구조(`items`·`calculation`·`has_unshippable_items` 등)는 GET 조회와 동일합니다.


### DELETE /api/modules/sirsoft-ecommerce/cart/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.cart.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.cart.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CartController@destroy`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/cart/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 장바구니에서 단일 아이템(`id`)을 삭제합니다. `optional.sanctum`으로 회원은 `Auth::id()`, 비회원은 cart_key(`X-Cart-Key`)로 소유를 식별하며, `CartController@destroy`가 `CartService::deleteItem()`을 호출합니다. 존재하지 않는 항목은 404, 타인 소유 항목 삭제 시도는 403(사유별 `CartOperationException` 매핑)으로 반환합니다. 장바구니 각 행의 개별 삭제 버튼에 사용합니다.


### PUT /api/modules/sirsoft-ecommerce/cart/{id}/option
<!-- @generated:start:api.modules.sirsoft-ecommerce.cart.change-option -->
- **라우트명**: `api.modules.sirsoft-ecommerce.cart.change-option`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CartController@changeOption`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| product_option_id | body | integer | 예 | — | product option 식별자 |
| quantity | body | integer | 예 | min 1, max 9999 | 변경할 구매 수량 (1~9999) |
| additional_option_selections | body | array | 아니오 | — | 추가 옵션 재선택 목록 (항목별 additional_option_id/value_id, 직접입력 custom_text — 미전달 시 기존 선택 유지) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.cart.change_option_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/cart/{id}/option HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
Content-Type: application/json

{
    "product_option_id": 1,
    "quantity": 1,
    "additional_option_selections": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 장바구니 아이템(`id`)의 선택 옵션과 수량을 변경합니다. `optional.sanctum`으로 회원/비회원 모두 접근하며, `CartController@changeOption`이 `CartService::changeOption()`으로 `product_option_id`·`quantity`(및 추가 옵션 선택)를 반영한 뒤 수정된 아이템을 반환합니다. 다른 상품의 옵션으로 바꾸려 하거나 옵션이 없는 경우, 재고/판매상태/구매수량 한도 위반은 사유별 422/404/403으로 매핑됩니다. 장바구니에서 옵션(예: 색상/사이즈)을 바꿀 때 사용합니다.


### PATCH /api/modules/sirsoft-ecommerce/cart/{id}/quantity
<!-- @generated:start:api.modules.sirsoft-ecommerce.cart.update-quantity -->
- **라우트명**: `api.modules.sirsoft-ecommerce.cart.update-quantity`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CartController@updateQuantity`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| quantity | body | integer | 예 | min 1, max 9999 | 변경할 구매 수량 (1~9999) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.cart.update_quantity_validation_rules`).

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/cart/{id}/quantity HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
Content-Type: application/json

{
    "quantity": 1
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 장바구니 아이템(`id`)의 수량만 변경합니다. `optional.sanctum`으로 회원/비회원 모두 접근하며, `CartController@updateQuantity`가 `CartService::updateQuantity()`로 수량을 반영한 뒤 프론트가 별도 refetch 없이 화면을 갱신할 수 있도록 `index`와 동일한 전체 목록·계산 결과(`items`·`calculation`)를 함께 반환합니다. 수량은 1~9999 범위이며, 재고 부족·판매 중지·구매수량 한도 위반은 사유별 422, 항목/권한 문제는 404/403으로 매핑됩니다. 장바구니의 수량 증감(+/-) 컨트롤에 사용합니다.


