# Checkout API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Checkout 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### DELETE /api/modules/sirsoft-ecommerce/checkout
<!-- @generated:start:api.modules.sirsoft-ecommerce.checkout.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.checkout.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CheckoutController@destroy`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/checkout HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만) — `CheckoutController@destroy` 는 `ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.checkout.deleted')` 를 데이터 인수 없이 호출합니다._

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "주문서가 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 삭제할 임시 주문이 없음 (`TempOrderService::deleteTempOrder()` 가 false 반환 — 이미 만료·미존재). 메시지: `임시 주문 정보를 찾을 수 없습니다.` |
| 500 | Internal Server Error | 삭제 처리 중 예외 발생. 메시지: `주문서 삭제에 실패했습니다.` |

<!-- @generated:end -->

**설명** 주문서 페이지를 이탈할 때 현재 회원/비회원의 임시 주문(temp order)을 삭제합니다. `optional.sanctum`으로 회원은 `Auth::id()`, 비회원은 cart_key(`X-Cart-Key`)로 대상을 식별하며, `CheckoutController@destroy`가 `TempOrderService::deleteTempOrder()`를 호출합니다. 삭제할 임시 주문이 없으면 404를 반환합니다. 주문 확정 없이 주문서에서 뒤로가기·페이지 이탈 시 미완료 임시 데이터를 정리하는 용도입니다.


### GET /api/modules/sirsoft-ecommerce/checkout
<!-- @generated:start:api.modules.sirsoft-ecommerce.checkout.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.checkout.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CheckoutController@show`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| country_code | query | string | 아니오 | — | 국가 코드 (ISO 3166-1 alpha-2) |
| zipcode | query | string | 아니오 | max 20 | 우편번호 |
| region | query | string | 아니오 | max 100 | 지역/권역 |
| city | query | string | 아니오 | max 100 | 도시명 (배송비 미리보기 산출용 배송 주소) |
| address | query | string | 아니오 | max 255 | 기본 주소 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/checkout?country_code=KR&zipcode=06234&region=%EC%98%88%EC%8B%9C%EA%B0%92&city=%EC%98%88%EC%8B%9C%EA%B0%92&address=%EC%84%9C%EC%9A%B8%ED%8A%B9%EB%B3%84%EC%8B%9C%20%EA%B0%95%EB%82%A8%EA%B5%AC%20%ED%85%8C%ED%97%A4%EB%9E%80%EB%A1%9C%201 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`CheckoutDataService::buildResponseData()` 가 구성)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| temp_order_id | integer | `1` | 임시 주문 ID (주문 확정 시 사용) |
| items | array | `[...]` | 주문 상품 목록 (`CheckoutItemResource` — 아래 "items[] 항목" 표) |
| calculation | object | `{...}` | 주문 계산 결과 (`OrderCalculationResult::toArray()` — 아래 "calculation" 표) |
| promotions | object | `{"item_coupons":{},"order_coupon_issue_id":null,"shipping_coupon_issue_id":null}` | 현재 임시 주문에 적용된 쿠폰 선택 상태 |
| use_points | integer | `0` | 현재 사용 설정된 마일리지 포인트 |
| shipping_address | object\|null | `{"country_code":"KR","zipcode":"06234","region":null,"city":null,"address":null}` | 배송비 계산에 사용된 배송 주소 (미전달 시 `null`) |
| expires_at | string | `2026-07-14T12:34:56+09:00` | 임시 주문 만료 시각 (ISO 8601) |
| available_coupons | array | `[]` | 주문금액/배송비 대상 사용 가능 쿠폰 목록 (비회원은 빈 배열) |
| mileage | object\|null | `{...}` | 마일리지 잔액 정보 (비회원은 `null` — 아래 "mileage" 표) |
| validation_errors | array | `[]` | 조건 위반으로 자동 제외된 쿠폰의 사유 목록 (min_amount / per_user_limit / not_combinable 등) |
| has_unshippable_items | boolean | `false` | 선택된 배송국가로 배송 불가한 상품이 1개라도 있으면 `true` (주문하기 차단 플래그) |
| selected_shipping_country | string | `KR` | 현재 선택된 배송 국가 코드 |
| free_shipping | object | `{...}` | 무료배송 기준액 정보 (아래 "free_shipping" 표) |
| unavailable_items | array | `[]` | 구매 불가 상품 목록 (존재할 때만 포함) |
| has_stock_issue | boolean | `false` | 구매 불가 사유에 재고 부족이 포함되면 `true` (`unavailable_items` 존재 시에만 포함) |
| has_status_issue | boolean | `false` | 구매 불가 사유에 판매 상태 문제가 포함되면 `true` (`unavailable_items` 존재 시에만 포함) |

`items[]` 항목 (`CheckoutItemResource::toArray()`):

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer\|null | `1` | 장바구니 아이템 ID (바로구매는 `null`) |
| product_id | integer | `1` | 상품 ID |
| product_option_id | integer | `1` | 상품 옵션 ID |
| quantity | integer | `1` | 주문 수량 |
| additional_options | array | `[]` | 선택한 추가 옵션 (`group_name` / `name` / `price_adjustment`) |
| additional_options_total | integer | `0` | 추가 옵션 단가 합계 |
| product | object\|null | `{...}` | 상품 정보 (이름·썸네일 등) |
| product_option | object\|null | `{...}` | 옵션 정보 (옵션명·판매가 등) |
| is_shippable_to_selected_country | boolean | `true` | 선택된 배송국가로 이 상품이 배송 가능한지 |
| subtotal | integer | `10000` | 표시 소계 = (옵션 판매가 + 추가옵션 단가 합계) × 수량 |
| subtotal_formatted | string | `10,000원` | 소계 통화 포맷 |
| multi_currency_subtotal | object | `{"KRW":{...}}` | 소계 다통화 환산 |
| unit_price | integer | `10000` | 계산 기준 단가 |
| unit_price_formatted | string | `10,000원` | 단가 통화 포맷 |
| product_coupon_discount_amount | integer | `0` | 상품 쿠폰 할인액 |
| product_coupon_discount_formatted | string | `0원` | 상품 쿠폰 할인액 포맷 |
| code_discount_amount | integer | `0` | 할인코드 할인액 |
| code_discount_formatted | string | `0원` | 할인코드 할인액 포맷 |
| order_coupon_discount_share | integer | `0` | 주문 쿠폰 할인의 이 라인 분담액 |
| order_coupon_discount_share_formatted | string | `0원` | 분담액 포맷 |
| total_discount | integer | `0` | 이 라인의 총 할인액 |
| total_discount_formatted | string | `0원` | 총 할인액 포맷 |
| points_used_share | integer | `0` | 사용 마일리지의 이 라인 분담액 |
| points_used_share_formatted | string | `0원` | 분담액 포맷 |
| points_earning | integer | `0` | 이 라인의 적립 예정 마일리지 |
| final_amount | integer | `10000` | 이 라인의 최종 금액 |
| final_amount_formatted | string | `10,000원` | 최종 금액 포맷 |
| multi_currency_unit_price | object | `{"KRW":{"amount":10000,"formatted":"10,000원"}}` | 단가 다통화 환산 |
| multi_currency_product_coupon_discount | object | `{"KRW":{"amount":0,"formatted":"0원"}}` | 상품 쿠폰 할인 다통화 환산 |
| multi_currency_total_discount | object | `{"KRW":{"amount":0,"formatted":"0원"}}` | 총 할인 다통화 환산 |
| multi_currency_final_amount | object | `{"KRW":{"amount":10000,"formatted":"10,000원"}}` | 최종 금액 다통화 환산 |
| available_coupons | array | `[]` | 이 상품에 적용 가능한 상품 쿠폰 목록 |
| disabled_coupon_ids | array | `[]` | 다른 상품 라인에서 이미 소진되어(per_user_limit) 이 라인에서는 선택 불가한 쿠폰 발급 ID |

`calculation` (`OrderCalculationResult::toArray()`):

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| items | array | `[...]` | 라인별 계산 결과 |
| summary | object | `{...}` | 합계 정보 (`Summary::toArray()` — 아래 표) |
| promotions | object | `{...}` | 실제 적용된 프로모션 요약 (상품/주문 프로모션 분리 + `coupon_issue_ids` / `item_coupons` / `discount_code` 평탄 키) |
| validation_errors | array | `[]` | 쿠폰 검증 오류 목록 |
| metadata | object | `{}` | 플러그인 확장용 메타데이터 (비어 있으면 생략) |

`calculation.summary` (`Summary::toArray()`):

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| subtotal | integer | `10000` | 상품 금액 소계 (할인 전) |
| subtotal_formatted | string | `10,000원` | 소계 통화 포맷 |
| product_coupon_discount | integer | `0` | 상품/카테고리 쿠폰 할인 합계 |
| product_coupon_discount_formatted | string | `0원` | 상품 쿠폰 할인 포맷 |
| code_discount | integer | `0` | 할인코드 할인 합계 |
| code_discount_formatted | string | `0원` | 할인코드 할인 포맷 |
| order_coupon_discount | integer | `0` | 주문 쿠폰 할인 합계 |
| order_coupon_discount_formatted | string | `0원` | 주문 쿠폰 할인 포맷 |
| total_coupon_discount | integer | `0` | 상품 + 주문 쿠폰 할인 합계 |
| total_coupon_discount_formatted | string | `0원` | 쿠폰 할인 합계 포맷 |
| total_discount | integer | `0` | 총 할인금액 |
| discount_formatted | string | `0원` | 총 할인금액 포맷 |
| base_shipping_total | integer | `3000` | 기본 배송비 합계 |
| extra_shipping_total | integer | `0` | 추가 배송비(도서산간) 합계 |
| total_shipping | integer | `3000` | 총 배송비 (기본 + 추가) |
| total_shipping_fee | integer | `3000` | 총 배송비 (별칭 키) |
| shipping_fee_formatted | string | `3,000원` | 총 배송비 포맷 |
| shipping_discount | integer | `0` | 배송비 할인 |
| shipping_discount_formatted | string | `0원` | 배송비 할인 포맷 |
| taxable_amount | integer | `10000` | 과세 금액 합계 |
| tax_free_amount | integer | `0` | 면세 금액 합계 |
| points_earning | integer | `0` | 적립 예정 마일리지 합계 |
| total_mileage | integer | `0` | 적립 예정 마일리지 (별칭 키) |
| mileage_formatted | string | `0P` | 적립 예정 마일리지 포맷 |
| points_used | integer | `0` | 사용 마일리지 합계 |
| points_used_formatted | string | `0원` | 사용 마일리지 차감액 통화 포맷 |
| payment_amount | integer | `13000` | 결제금액 (마일리지 사용 전) |
| payment_amount_formatted | string | `13,000원` | 결제금액 포맷 |
| final_amount | integer | `13000` | 최종 지불금액 |
| final_amount_formatted | string | `13,000원` | 최종 지불금액 포맷 |
| selected_payment_currency | string | `KRW` | 선택된 결제 통화 (설정된 경우에만 포함) |
| multi_currency | object | `{"KRW":{...}}` | 합계 금액의 다통화 환산 (설정된 경우에만 포함) |
| coupon_discount | integer | `0` | 상품 쿠폰 할인 (deprecated — 하위 호환) |
| coupon_discount_formatted | string | `0원` | 상품 쿠폰 할인 포맷 (deprecated) |
| order_discount | integer | `0` | 주문 쿠폰 할인 (deprecated — 하위 호환) |
| order_discount_formatted | string | `0원` | 주문 쿠폰 할인 포맷 (deprecated) |

`mileage` (`UserMileageService::getBalance()` + 체크아웃 추가 키, 비회원은 `null`):

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| enabled | boolean | `true` | 마일리지 적립 기능 활성화 여부 |
| available | integer | `0` | 사용 가능 마일리지 잔액 |
| pending | integer | `0` | 적립 대기 중인 마일리지 |
| expiring_soon | integer | `0` | 곧 소멸 예정 마일리지 |
| expiring_date | string\|null | `2026-08-31` | 소멸 예정일 (없으면 `null`) |
| total_earned | integer | `0` | 누적 적립 마일리지 |
| total_used | integer | `0` | 누적 사용 마일리지 |
| by_currency | object | `{}` | 통화별 마일리지 내역 |
| max_usable | integer | `0` | 이번 주문에서 사용 가능한 최대 마일리지 |
| usable | boolean | `true` | 마일리지 "사용" 가능 여부 (기본 통화 사용 규칙 미설정 시 `false`) |

`free_shipping` (`CheckoutDataService::buildFreeShippingInfo()`):

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| enabled | boolean | `false` | 무료배송 기준액 기능 활성화 여부 |
| threshold_base | integer | `50000` | 무료배송 기준액 (기본 통화 정수) |
| threshold_multi_currency | object | `{"KRW":{...}}` | 기준액의 결제통화 환산 (기준액 0 이면 `[]`) |
| remaining_base | integer | `40000` | 무료배송까지 남은 금액 (기본 통화) |
| remaining_multi_currency | object | `{"KRW":{...}}` | 남은 금액의 결제통화 환산 (남은 금액 0 이면 `[]`) |

**응답 예시**

```json
{
    "success": true,
    "message": "주문서 정보를 조회했습니다.",
    "data": {
        "temp_order_id": 1,
        "items": [
            {
                "id": 1,
                "product_id": 1,
                "product_option_id": 1,
                "quantity": 1,
                "additional_options": [],
                "additional_options_total": 0,
                "product": { "id": 1, "name": "샘플 상품" },
                "product_option": { "id": 1, "name": "기본 옵션" },
                "is_shippable_to_selected_country": true,
                "subtotal": 10000,
                "subtotal_formatted": "10,000원",
                "multi_currency_subtotal": {},
                "unit_price": 10000,
                "unit_price_formatted": "10,000원",
                "product_coupon_discount_amount": 0,
                "product_coupon_discount_formatted": "0원",
                "code_discount_amount": 0,
                "code_discount_formatted": "0원",
                "order_coupon_discount_share": 0,
                "order_coupon_discount_share_formatted": "0원",
                "total_discount": 0,
                "total_discount_formatted": "0원",
                "points_used_share": 0,
                "points_used_share_formatted": "0원",
                "points_earning": 0,
                "final_amount": 10000,
                "final_amount_formatted": "10,000원",
                "available_coupons": [],
                "disabled_coupon_ids": []
            }
        ],
        "calculation": {
            "items": [],
            "summary": {
                "subtotal": 10000,
                "subtotal_formatted": "10,000원",
                "total_discount": 0,
                "discount_formatted": "0원",
                "base_shipping_total": 3000,
                "extra_shipping_total": 0,
                "total_shipping": 3000,
                "shipping_fee_formatted": "3,000원",
                "points_earning": 0,
                "points_used": 0,
                "points_used_formatted": "0원",
                "payment_amount": 13000,
                "payment_amount_formatted": "13,000원",
                "final_amount": 13000,
                "final_amount_formatted": "13,000원"
            },
            "promotions": {
                "coupon_issue_ids": [],
                "item_coupons": {},
                "discount_code": null
            },
            "validation_errors": []
        },
        "promotions": {
            "item_coupons": {},
            "order_coupon_issue_id": null,
            "shipping_coupon_issue_id": null
        },
        "use_points": 0,
        "shipping_address": {
            "country_code": "KR",
            "zipcode": "06234",
            "region": null,
            "city": null,
            "address": null
        },
        "expires_at": "2026-07-14T12:34:56+09:00",
        "available_coupons": [],
        "mileage": {
            "enabled": true,
            "available": 0,
            "pending": 0,
            "expiring_soon": 0,
            "expiring_date": null,
            "total_earned": 0,
            "total_used": 0,
            "by_currency": {},
            "max_usable": 0,
            "usable": true
        },
        "validation_errors": [],
        "has_unshippable_items": false,
        "selected_shipping_country": "KR",
        "free_shipping": {
            "enabled": false,
            "threshold_base": 0,
            "threshold_multi_currency": [],
            "remaining_base": 0,
            "remaining_multi_currency": []
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 임시 주문이 만료되었거나 존재하지 않음. 메시지: `임시 주문 정보를 찾을 수 없습니다.` |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 조회/재계산 중 예외 발생 |

<!-- @generated:end -->

**설명** 현재 유효한 임시 주문을 조회하면서 최신 가격으로 실시간 재계산해 주문서 페이지 데이터를 반환합니다. `optional.sanctum`으로 회원/비회원 모두 접근하며, `CheckoutController@show`가 `TempOrderService::getTempOrderWithCalculation()`으로 재계산하고 `CheckoutDataService::buildResponseData()`가 쿠폰·마일리지·상품·구매불가 상품 정보를 포함해 응답을 구성합니다. 쿼리로 `country_code`/`zipcode`/`region` 등 배송 주소를 전달하면 해당 주소 기준 배송비가 계산되며, 우편번호 없이 배송국가만으로도 미리보기 배송비를 산출합니다. 임시 주문이 만료·미존재면 404를 반환합니다.

응답의 `mileage_info` 에는 잔액(`available`)·사용 가능 최대(`max_usable`)·사용 가능 여부(`usable`)와 함께 사용 정책 `usage_policy`(`min_use_amount`, `use_unit`, `max_use_amount`)가 포함됩니다. `max_usable` 과 `usage_policy.max_use_amount` 는 저장 API 가 판정하는 기준(마일리지 차감 전 결제금액)과 동일한 기준으로 산출되므로, 입력 UI 는 이 값을 그대로 상·하한으로 사용하면 됩니다. 조회는 사용 한도를 초과한 임시 주문이 있어도 200 을 반환합니다(차단은 저장 시점에서만).


### POST /api/modules/sirsoft-ecommerce/checkout
<!-- @generated:start:api.modules.sirsoft-ecommerce.checkout.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.checkout.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CheckoutController@store`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| item_ids | body | array | 아니오 | min 1 | item 식별자 배열 |
| direct_items | body | array | 아니오 | min 1 | 바로 구매 항목 배열 (장바구니 미경유 — 항목별 product_id/product_option_id/quantity, item_ids와 택일) |
| coupon_issue_ids | body | array | 아니오 | — | coupon issue 식별자 배열 |
| use_points | body | integer | 아니오 | min 0 | 사용할 마일리지(적립금) 포인트 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.checkout.validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/checkout HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
Content-Type: application/json

{
    "item_ids": [
        "예시값"
    ],
    "direct_items": [
        "예시값"
    ],
    "coupon_issue_ids": [
        "예시값"
    ],
    "use_points": 1
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`CheckoutController@store` 가 직접 조립 — 성공 시 HTTP 201)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| temp_order_id | integer | `1` | 생성된 임시 주문 ID (이후 조회/수정/주문 확정에 사용) |
| calculation | object | `{...}` | 주문 계산 결과 (`OrderCalculationResult::toArray()` — `items` / `summary` / `promotions` / `validation_errors`. 상세는 GET /checkout 의 calculation 표 참조) |
| expires_at | string | `2026-07-14T12:34:56+09:00` | 임시 주문 만료 시각 (ISO 8601) |

**응답 예시**

```json
{
    "success": true,
    "message": "주문서가 생성되었습니다.",
    "data": {
        "temp_order_id": 1,
        "calculation": {
            "items": [],
            "summary": {
                "subtotal": 10000,
                "subtotal_formatted": "10,000원",
                "total_discount": 0,
                "total_shipping": 3000,
                "payment_amount": 13000,
                "final_amount": 13000,
                "final_amount_formatted": "13,000원"
            },
            "promotions": {
                "coupon_issue_ids": [],
                "item_coupons": {},
                "discount_code": null
            },
            "validation_errors": []
        },
        "expires_at": "2026-07-14T12:34:56+09:00"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 장바구니가 비어 있음. 메시지: `장바구니가 비어있습니다.` |
| 422 | Unprocessable Entity | 보유 잔액을 초과하는 마일리지 사용 요청 (`error.code: mileage_exceeds_balance`). 메시지: `주문서 생성에 실패했습니다.` |
| 500 | Internal Server Error | 임시 주문 생성 중 예외 발생. 메시지: `주문서 생성에 실패했습니다.` |

<!-- @generated:end -->

**설명** 장바구니에서 선택한 아이템으로 임시 주문을 생성해 주문서 작성 단계로 진입합니다. `optional.sanctum`으로 회원/비회원 모두 접근하며, `CheckoutController@store`가 `direct_items`가 있으면 `TempOrderService::createTempOrderFromDirectItems()`(바로 구매, 장바구니 미경유), 없으면 `item_ids`로 `createTempOrderFromSelectedItems()`(장바구니 경유)를 호출합니다. 응답에는 임시 주문 ID·계산 결과·만료 시각(`expires_at`)이 포함됩니다. 재고 부족·판매 중지·구매 제한 상품이 있으면 400(cart_unavailable), 빈 장바구니는 400을 반환합니다.

마일리지 사용은 보유 잔액뿐 아니라 관리자 설정 사용 정책(최소 사용액 `min_use_amount`, 사용 단위 `use_unit`, 최대 한도 `max_use_percent`/`max_use_value`)까지 서버에서 강제하며, 위반 시 422 를 반환합니다. 한도 초과 응답 메시지에는 사용 가능한 최대 금액이 함께 안내됩니다. 판정 기준 금액은 마일리지 차감 전 결제금액이며, 비회원의 `use_points` 는 예외 없이 0 으로 처리됩니다.


### PUT /api/modules/sirsoft-ecommerce/checkout
<!-- @generated:start:api.modules.sirsoft-ecommerce.checkout.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.checkout.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CheckoutController@update`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| item_coupons | body | array | 아니오 | — | 상품별 적용 쿠폰 맵 (상품 옵션 ID를 키로, 발급 쿠폰 ID 배열을 값으로 — 상품당 최대 2개) |
| order_coupon_issue_id | body | integer | 아니오 | — | order coupon issue 식별자 |
| shipping_coupon_issue_id | body | integer | 아니오 | — | shipping coupon issue 식별자 |
| use_points | body | integer | 아니오 | min 0 | 사용할 마일리지(적립금) 포인트 |
| zipcode | body | string | 아니오 | max 10 | 우편번호 |
| country_code | body | string | 아니오 | — | 국가 코드 (ISO 3166-1 alpha-2) |
| payment_method | body | string | 아니오 | max 50 | 결제 수단 코드 (결제수단별 할인/수수료 계산 확장용) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.checkout.update_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/checkout HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
Content-Type: application/json

{
    "item_coupons": [
        "예시값"
    ],
    "order_coupon_issue_id": 1,
    "shipping_coupon_issue_id": 1,
    "use_points": 1,
    "zipcode": "06234",
    "country_code": "KR",
    "payment_method": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드. GET `/api/modules/sirsoft-ecommerce/checkout` 과 동일한 구조입니다 (`CheckoutDataService::buildResponseData()` 를 그대로 사용)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| temp_order_id | integer | `1` | 임시 주문 ID |
| items | array | `[...]` | 재계산된 주문 상품 목록 (`CheckoutItemResource` — GET 문서의 `items[]` 표와 동일) |
| calculation | object | `{...}` | 재계산된 주문 계산 결과 (`items` / `summary` / `promotions` / `validation_errors`) |
| promotions | object | `{"item_coupons":{},"order_coupon_issue_id":null,"shipping_coupon_issue_id":null}` | 반영된 쿠폰 선택 상태 (미전송 필드는 기존 값 유지) |
| use_points | integer | `0` | 반영된 마일리지 사용 포인트 |
| shipping_address | object\|null | `{"country_code":"KR","zipcode":"06234"}` | 배송비 계산에 사용된 배송 주소 |
| expires_at | string | `2026-07-14T12:34:56+09:00` | 임시 주문 만료 시각 (ISO 8601) |
| available_coupons | array | `[]` | 주문금액/배송비 대상 사용 가능 쿠폰 (비회원은 빈 배열) |
| mileage | object\|null | `{...}` | 마일리지 잔액 정보 (비회원은 `null`) |
| validation_errors | array | `[]` | 조건 위반으로 자동 제외된 쿠폰의 사유 목록 |
| has_unshippable_items | boolean | `false` | 선택된 배송국가로 배송 불가한 상품 존재 여부 |
| selected_shipping_country | string | `KR` | 현재 선택된 배송 국가 코드 |
| free_shipping | object | `{...}` | 무료배송 기준액 정보 (`enabled` / `threshold_base` / `threshold_multi_currency` / `remaining_base` / `remaining_multi_currency`) |

**응답 예시**

```json
{
    "success": true,
    "message": "주문서가 수정되었습니다.",
    "data": {
        "temp_order_id": 1,
        "items": [],
        "calculation": {
            "items": [],
            "summary": {
                "subtotal": 10000,
                "subtotal_formatted": "10,000원",
                "total_discount": 1000,
                "discount_formatted": "1,000원",
                "total_shipping": 3000,
                "shipping_fee_formatted": "3,000원",
                "points_used": 0,
                "payment_amount": 12000,
                "final_amount": 12000,
                "final_amount_formatted": "12,000원"
            },
            "promotions": {
                "coupon_issue_ids": [10],
                "item_coupons": {},
                "discount_code": null
            },
            "validation_errors": []
        },
        "promotions": {
            "item_coupons": {},
            "order_coupon_issue_id": 10,
            "shipping_coupon_issue_id": null
        },
        "use_points": 0,
        "shipping_address": {
            "country_code": "KR",
            "zipcode": "06234"
        },
        "expires_at": "2026-07-14T12:34:56+09:00",
        "available_coupons": [],
        "mileage": {
            "enabled": true,
            "available": 0,
            "max_usable": 0,
            "usable": true
        },
        "validation_errors": [],
        "has_unshippable_items": false,
        "selected_shipping_country": "KR",
        "free_shipping": {
            "enabled": false,
            "threshold_base": 0,
            "threshold_multi_currency": [],
            "remaining_base": 0,
            "remaining_multi_currency": []
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 임시 주문이 만료되었거나 존재하지 않음 (`TempOrderNotFoundException`). 메시지: `임시 주문 정보를 찾을 수 없습니다.` |
| 422 | Unprocessable Entity | 보유 잔액을 초과하는 마일리지 사용 요청 (`error.code: mileage_exceeds_balance`). 메시지: `주문서 수정에 실패했습니다.` |
| 500 | Internal Server Error | 재계산 중 예외 발생. 메시지: `주문서 수정에 실패했습니다.` |

<!-- @generated:end -->

**설명** 주문서 작성 중 쿠폰·마일리지·배송 주소가 변경될 때 임시 주문 금액을 재계산합니다. `optional.sanctum`으로 회원/비회원 모두 접근하며, `CheckoutController@update`가 전송된 프로모션 필드(`item_coupons`·`order_coupon_issue_id`·`shipping_coupon_issue_id`)와 `use_points`만 반영하고 미전송 필드는 `TempOrderService::updateTempOrder()`에서 기존 값을 유지합니다. `zipcode`/`country_code`로 배송 주소를 함께 넘기면 배송비가 다시 계산됩니다. 임시 주문이 만료·미존재면 404, 보유 잔액 초과 또는 사용 정책(최소 사용액·사용 단위·최대 한도) 위반 마일리지는 422를 반환합니다.


### POST /api/modules/sirsoft-ecommerce/checkout/extend
<!-- @generated:start:api.modules.sirsoft-ecommerce.checkout.extend -->
- **라우트명**: `api.modules.sirsoft-ecommerce.checkout.extend`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CheckoutController@extend`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/checkout/extend HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| expires_at | string | `2026-07-14T13:04:56+09:00` | 연장된 임시 주문 만료 시각 (ISO 8601) |

**응답 예시**

```json
{
    "success": true,
    "message": "주문서 유효시간이 연장되었습니다.",
    "data": {
        "expires_at": "2026-07-14T13:04:56+09:00"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 연장할 임시 주문이 이미 만료되었거나 존재하지 않음 (`TempOrderService::extendExpiration()` 이 null 반환). 메시지: `임시 주문 정보를 찾을 수 없습니다.` |
| 500 | Internal Server Error | 연장 처리 중 예외 발생. 메시지: `주문서 유효시간 연장에 실패했습니다.` |

<!-- @generated:end -->

**설명** 임시 주문의 만료 시각을 연장합니다. `optional.sanctum`으로 회원은 `Auth::id()`, 비회원은 cart_key로 대상을 식별하며, `CheckoutController@extend`가 `TempOrderService::extendExpiration()`을 호출해 갱신된 `expires_at`을 반환합니다. 주문서 작성이 길어져 임시 주문이 만료되기 전에 세션을 연장하는 용도이며, 연장할 임시 주문이 이미 만료·미존재면 404를 반환합니다.


