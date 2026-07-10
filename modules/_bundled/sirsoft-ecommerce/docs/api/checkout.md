# Checkout API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Checkout 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-404 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

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

<!-- 실측 제외: http-404 — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-404 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 현재 유효한 임시 주문을 조회하면서 최신 가격으로 실시간 재계산해 주문서 페이지 데이터를 반환합니다. `optional.sanctum`으로 회원/비회원 모두 접근하며, `CheckoutController@show`가 `TempOrderService::getTempOrderWithCalculation()`으로 재계산하고 `CheckoutDataService::buildResponseData()`가 쿠폰·마일리지·상품·구매불가 상품 정보를 포함해 응답을 구성합니다. 쿼리로 `country_code`/`zipcode`/`region` 등 배송 주소를 전달하면 해당 주소 기준 배송비가 계산되며, 우편번호 없이 배송국가만으로도 미리보기 배송비를 산출합니다. 임시 주문이 만료·미존재면 404를 반환합니다.


### POST /api/modules/sirsoft-ecommerce/checkout
<!-- @generated:start:api.modules.sirsoft-ecommerce.checkout.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.checkout.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CheckoutController@store`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| item_ids | body | array | 아니오 | min 1 | item 식별자 배열 |
| direct_items | body | array | 아니오 | min 1 | 바로 구매 항목 배열 (장바구니 미경유 — 항목별 product_id/option_values/quantity, item_ids와 택일) |
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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 장바구니에서 선택한 아이템으로 임시 주문을 생성해 주문서 작성 단계로 진입합니다. `optional.sanctum`으로 회원/비회원 모두 접근하며, `CheckoutController@store`가 `direct_items`가 있으면 `TempOrderService::createTempOrderFromDirectItems()`(바로 구매, 장바구니 미경유), 없으면 `item_ids`로 `createTempOrderFromSelectedItems()`(장바구니 경유)를 호출합니다. 응답에는 임시 주문 ID·계산 결과·만료 시각(`expires_at`)이 포함됩니다. 재고 부족·판매 중지·구매 제한 상품이 있으면 400(cart_unavailable), 보유 잔액을 넘는 마일리지 사용은 422, 빈 장바구니는 400을 반환합니다.


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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 주문서 작성 중 쿠폰·마일리지·배송 주소가 변경될 때 임시 주문 금액을 재계산합니다. `optional.sanctum`으로 회원/비회원 모두 접근하며, `CheckoutController@update`가 전송된 프로모션 필드(`item_coupons`·`order_coupon_issue_id`·`shipping_coupon_issue_id`)와 `use_points`만 반영하고 미전송 필드는 `TempOrderService::updateTempOrder()`에서 기존 값을 유지합니다. `zipcode`/`country_code`로 배송 주소를 함께 넘기면 배송비가 다시 계산됩니다. 임시 주문이 만료·미존재면 404, 보유 잔액 초과 마일리지는 422를 반환합니다.


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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-404 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

<!-- @generated:end -->

**설명** 임시 주문의 만료 시각을 연장합니다. `optional.sanctum`으로 회원은 `Auth::id()`, 비회원은 cart_key로 대상을 식별하며, `CheckoutController@extend`가 `TempOrderService::extendExpiration()`을 호출해 갱신된 `expires_at`을 반환합니다. 주문서 작성이 길어져 임시 주문이 만료되기 전에 세션을 연장하는 용도이며, 연장할 임시 주문이 이미 만료·미존재면 404를 반환합니다.


