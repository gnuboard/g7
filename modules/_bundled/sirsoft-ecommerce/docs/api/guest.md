# Guest API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Guest 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/modules/sirsoft-ecommerce/guest/orders/verify
<!-- @generated:start:api.modules.sirsoft-ecommerce.guest.orders.verify -->
- **라우트명**: `api.modules.sirsoft-ecommerce.guest.orders.verify`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\OrderController@verify`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order_number | body | string | 예 | max 50 | 조회할 주문번호 (본인 확인 키 ①) |
| orderer_phone | body | string | 예 | max 20 | 주문자 전화번호 (본인 확인 키 ②) |
| guest_lookup_password | body | string | 예 | max 255 | 비회원 주문 조회 비밀번호 (본인 확인 키 ③) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/guest/orders/verify HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "order_number": "예시값",
    "orderer_phone": "010-1234-5678",
    "guest_lookup_password": "Password123!"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| guest_order_token | string | `eyJpdiI6...` | 비회원 주문 조회 토큰 (30분 유효). 이후 비회원 주문 상세/취소/환불예상 호출 시 `X-Guest-Order-Token` 헤더로 전달 |
| expires_at | string | `2026-07-14T15:02:11+09:00` | 토큰 만료 일시 (ISO 8601) |
| order | object | — | 최소 주문 요약 (상세는 토큰 보호 엔드포인트에서 제공) |
| order.order_number | string | `20260714000012` | 인증된 주문의 주문번호 |
| order.order_status | string | `payment_complete` | 주문 상태 (`pending_order`, `pending_payment`, `payment_complete`, `shipping_hold`, `preparing`, `shipping_ready`, `shipping`, `delivered`, `confirmed`, `cancelled`) |

**응답 예시**

```json
{
    "success": true,
    "message": "주문 정보를 조회했습니다.",
    "data": {
        "guest_order_token": "eyJpdiI6...",
        "expires_at": "2026-07-14T15:02:11+09:00",
        "order": {
            "order_number": "20260714000012",
            "order_status": "payment_complete"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 주문 없음 / 회원 주문 / 전화번호 불일치 / 비밀번호 오류 / 잠금 — 정보 노출 방지를 위해 모두 동일한 "주문을 찾을 수 없습니다" 로 처리 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 비회원이 주문번호·주문자 전화번호·조회 비밀번호로 본인 확인을 수행하고, 성공 시 30분 유효한 비회원 주문 조회 토큰을 발급받는 공개 엔드포인트입니다. 인증이 필요 없으며, `OrderController@verify`가 `GuestOrderAuthService::authenticate()`로 검증한 뒤 토큰과 최소 주문 요약(`order_number`, `order_status`)만 반환합니다. 주문 없음·회원 주문·전화번호 불일치·비밀번호 오류·잠금 등 모든 실패는 정보 노출 방지를 위해 동일한 404("주문을 찾을 수 없습니다")로 처리됩니다. 발급된 토큰은 이후 비회원 주문 상세/취소/환불 예상 등 후속 호출의 `X-Guest-Order-Token` 헤더로 사용합니다.


### POST /api/modules/sirsoft-ecommerce/guest/orders/{orderNumber}/cancel
<!-- @generated:start:api.modules.sirsoft-ecommerce.guest.orders.cancel -->
- **라우트명**: `api.modules.sirsoft-ecommerce.guest.orders.cancel`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\OrderController@cancel`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |
| reason | body | string | 예 | — | 취소 사유 코드 (ClaimReason 의 refund 타입·활성·사용자 선택 가능 코드) |
| reason_detail | body | string | 아니오 | max 500 | 사용자 입력 취소 사유 상세 |
| items | body | array | 아니오 | min 1 | 처리 대상 항목 배열 (전달 시 부분취소, 미전달 시 전체취소) |
| refund_priority | body | string | 아니오 | `pg_first`, `points_first` | 환불 배분 우선순위 (PG 우선 / 포인트 우선, 미전달 시 pg_first) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/guest/orders/{orderNumber}/cancel HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "reason": "예시값",
    "reason_detail": "예시값",
    "items": [
        "예시값"
    ],
    "refund_priority": "pg_first"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`GuestOrderResource` — 취소 후 갱신된 주문 상세)._

<a id="guest-order-resource"></a>

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| order_number | string | `20260714000012` | 주문번호 |
| order_status | string | `cancelled` | 주문 상태 (`pending_order`, `pending_payment`, `payment_complete`, `shipping_hold`, `preparing`, `shipping_ready`, `shipping`, `delivered`, `confirmed`, `cancelled`) |
| order_status_label | string\|null | `주문취소` | 주문 상태의 다국어 라벨 |
| order_status_variant | string\|null | `danger` | 주문 상태 뱃지 표시용 variant |
| is_partially_cancelled | boolean | `false` | 일부 옵션만 취소된 주문인지 여부 (별도 상태가 아닌 파생 플래그, 보조 뱃지용) |
| subtotal_amount | number | `12000` | 상품 합계 금액 (주문 시점 통화 기준) |
| subtotal_amount_formatted | string | `12,000원` | 상품 합계 금액 표기 문자열 |
| total_discount_amount | number | `0` | 총 할인 금액 |
| total_discount_amount_formatted | string | `0원` | 총 할인 금액 표기 문자열 |
| total_shipping_amount | number | `3000` | 총 배송비 |
| total_shipping_amount_formatted | string | `3,000원` | 총 배송비 표기 문자열 |
| total_amount | number | `15000` | 주문 총액 |
| total_amount_formatted | string | `15,000원` | 주문 총액 표기 문자열 |
| total_paid_amount | number | `15000` | 실제 결제 금액 |
| total_paid_amount_formatted | string | `15,000원` | 실제 결제 금액 표기 문자열 |
| total_cancelled_amount | number | `15000` | 누적 취소 금액 |
| total_cancelled_amount_formatted | string | `15,000원` | 누적 취소 금액 표기 문자열 |
| total_refunded_amount | number | `15000` | 누적 PG 환불 금액 |
| total_refunded_amount_formatted | string | `15,000원` | 누적 PG 환불 금액 표기 문자열 |
| total_refunded_points_amount | number | `0` | 누적 마일리지 환불 금액 |
| total_refunded_points_amount_formatted | string | `0원` | 누적 마일리지 환불 금액 표기 문자열 |
| total_points_used_amount | number | `0` | 사용한 마일리지 금액 |
| total_points_used_amount_formatted | string | `0원` | 사용 마일리지 표기 문자열 |
| total_deposit_used_amount | number | `0` | 사용한 예치금 금액 |
| total_deposit_used_amount_formatted | string | `0원` | 사용 예치금 표기 문자열 |
| total_earned_points_amount | number | `0` | 적립 예정/확정 마일리지 금액 |
| total_earned_points_amount_formatted | string | `0원` | 적립 마일리지 표기 문자열 |
| mc_subtotal_amount | object\|null | `{"KRW":"12,000원","USD":"$8.60"}` | 상품 합계의 다중 통화 스냅샷 (통화코드 → 표기 문자열) |
| mc_total_discount_amount | object\|null | — | 총 할인의 다중 통화 스냅샷 |
| mc_total_shipping_amount | object\|null | — | 총 배송비의 다중 통화 스냅샷 |
| mc_total_amount | object\|null | — | 주문 총액의 다중 통화 스냅샷 |
| mc_total_points_used_amount | object\|null | — | 사용 마일리지의 다중 통화 스냅샷 |
| mc_total_deposit_used_amount | object\|null | — | 사용 예치금의 다중 통화 스냅샷 |
| item_count | integer | `1` | 주문 품목 종류 수 |
| total_quantity | integer | `2` | 주문 총 수량 (옵션 수량 합) |
| ordered_at | string\|null | `2026-07-14T14:32:11+09:00` | 주문 일시 (ISO 8601) |
| ordered_at_formatted | string\|null | `2026-07-14 14:32` | 주문 일시 (사용자 타임존 표기) |
| paid_at | string\|null | `2026-07-14T14:33:02+09:00` | 결제 완료 일시 (ISO 8601) |
| paid_at_formatted | string\|null | `2026-07-14 14:33` | 결제 완료 일시 (사용자 타임존 표기) |
| confirmed_at | string\|null | `null` | 구매확정 일시 (ISO 8601) |
| confirmed_at_formatted | string\|null | `null` | 구매확정 일시 (사용자 타임존 표기) |
| cancelled_at | string\|null | `2026-07-14T15:01:44+09:00` | 취소 일시 (ISO 8601) |
| cancelled_at_formatted | string\|null | `2026-07-14 15:01` | 취소 일시 (사용자 타임존 표기) |
| orderer_name | string\|null | `홍길동` | 주문자 이름 |
| orderer_phone | string\|null | `010-1234-5678` | 주문자 전화번호 |
| orderer_email | string\|null | `guest@example.com` | 주문자 이메일 |
| recipient_name | string\|null | `홍길동` | 수령인 이름 |
| recipient_phone | string\|null | `010-1234-5678` | 수령인 연락처 |
| recipient_zipcode | string\|null | `06234` | 배송지 우편번호 |
| recipient_address | string\|null | `서울특별시 강남구 테헤란로 1` | 배송지 기본 주소 |
| recipient_detail_address | string\|null | `101호` | 배송지 상세 주소 |
| delivery_memo | string\|null | `부재 시 경비실에 맡겨주세요` | 배송 메모 |
| delivery_memo_label | string\|null | `부재 시 경비실` | 배송 메모 프리셋 라벨 |
| options | array | — | 주문 옵션(품목) 목록 (`OrderOptionResource`, 관계 로드 시에만 포함) |
| shipping_address | object\|null | — | 배송지 상세 (`OrderAddressResource`) |
| payment | object\|null | — | 결제 정보 (`OrderPaymentResource`, 관계 로드 시에만 포함) |
| shippings | array | — | 배송 정보 목록 (`OrderShippingResource`, 관계 로드 시에만 포함) |
| cancels | array | — | 취소 이력 목록 (`OrderCancelResource` — 취소 사유·상세·취소일시) |
| abilities | object | `{"can_cancel": false}` | 주문 상태로 판정한 허용 액션 (회원 권한이 아닌 상태 기반). `can_cancel` 은 환경설정 `order_settings.cancellable_statuses` 기준 |

회원용 `OrderResource` 와 달리 관리자/내부 메모(`admin_memo`, `customer_memo`), 회원 정보(`user`, `user_id`), 내부 계산 스냅샷(`promotions_applied_snapshot` 등)은 노출하지 않습니다.

**응답 예시**

```json
{
    "success": true,
    "message": "주문이 취소되었습니다.",
    "data": {
        "order_number": "20260714000012",
        "order_status": "cancelled",
        "order_status_label": "주문취소",
        "order_status_variant": "danger",
        "is_partially_cancelled": false,
        "subtotal_amount": 12000,
        "subtotal_amount_formatted": "12,000원",
        "total_discount_amount": 0,
        "total_discount_amount_formatted": "0원",
        "total_shipping_amount": 3000,
        "total_shipping_amount_formatted": "3,000원",
        "total_amount": 15000,
        "total_amount_formatted": "15,000원",
        "total_paid_amount": 15000,
        "total_paid_amount_formatted": "15,000원",
        "total_cancelled_amount": 15000,
        "total_cancelled_amount_formatted": "15,000원",
        "total_refunded_amount": 15000,
        "total_refunded_amount_formatted": "15,000원",
        "total_refunded_points_amount": 0,
        "total_refunded_points_amount_formatted": "0원",
        "total_points_used_amount": 0,
        "total_points_used_amount_formatted": "0원",
        "total_deposit_used_amount": 0,
        "total_deposit_used_amount_formatted": "0원",
        "total_earned_points_amount": 0,
        "total_earned_points_amount_formatted": "0원",
        "item_count": 1,
        "total_quantity": 2,
        "ordered_at": "2026-07-14T14:32:11+09:00",
        "ordered_at_formatted": "2026-07-14 14:32",
        "paid_at": "2026-07-14T14:33:02+09:00",
        "paid_at_formatted": "2026-07-14 14:33",
        "confirmed_at": null,
        "confirmed_at_formatted": null,
        "cancelled_at": "2026-07-14T15:01:44+09:00",
        "cancelled_at_formatted": "2026-07-14 15:01",
        "orderer_name": "홍길동",
        "orderer_phone": "010-1234-5678",
        "orderer_email": "guest@example.com",
        "recipient_name": "홍길동",
        "recipient_phone": "010-1234-5678",
        "recipient_zipcode": "06234",
        "recipient_address": "서울특별시 강남구 테헤란로 1",
        "recipient_detail_address": "101호",
        "delivery_memo": null,
        "delivery_memo_label": null,
        "options": [],
        "shipping_address": {},
        "shippings": [],
        "cancels": [],
        "abilities": {
            "can_cancel": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | `X-Guest-Order-Token` 누락·만료·주문번호 불일치 (토큰이 지목한 주문 외에는 접근 불가) |
| 422 | Unprocessable Entity | 요청 파라미터 검증 위반 (`error.errors` 에 필드별 메시지), 또는 취소 처리 실패 (`주문 취소에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 비회원이 조회 토큰으로 인증된 상태에서 자신의 주문을 취소합니다. 주문 소유권은 `VerifyGuestOrderToken` 미들웨어가 `X-Guest-Order-Token` 헤더로 검증하며, `OrderController@cancel`이 회원 취소와 동일한 `OrderCancellationService`를 재사용하되 취소자(`cancelledBy`)는 null로 둡니다. `items`를 전달하면 부분취소, 없으면 전체취소로 처리하고, `refund_priority`로 PG 환불과 포인트 환불 중 우선순위를 지정할 수 있습니다. 취소 후 갱신된 주문 상세를 `GuestOrderResource`로 반환합니다.


### GET /api/modules/sirsoft-ecommerce/guest/orders/{orderNumber}/cash-receipt
<!-- @generated:start:api.modules.sirsoft-ecommerce.guest.orders.cash-receipt.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.guest.orders.cash-receipt.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CashReceiptController@show`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/guest/orders/{orderNumber}/cash-receipt HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| issuable | boolean | 지금 발급이 가능한지 여부 (무통장 + 입금완료 + 미발급 + 현금성 금액 > 0 + 프로바이더 설정됨) |
| cash_receipt | object\|null | 현재 활성 영수증 1건 (`CashReceiptResource`). 발급 전이거나 전액 취소된 경우 `null` |

`cash_receipt` 의 하위 필드 구성은 [발급 API 의 응답 필드 표](orders.md)와 동일합니다. 금액(`amount`)은 **결제 통화 기준 실청구액**입니다 — base 통화와 결제 통화가 다른 상점에서도 구매자가 실제로 낸 금액으로 발행됩니다.

비회원 **주문 상세** 응답(`GET user/orders/{orderNumber}` + `X-Guest-Order-Token`)도 회원과 동일하게 `cash_receipt`(활성 영수증 1건 또는 `null`)와 `cash_receipts`(발급·취소 이력 배열)를 포함합니다. 주문상세 화면의 현금영수증 카드가 이 두 필드로 발급완료/미발급을 가르므로, 누락되면 발급에 성공해도 화면이 계속 미발급으로 표시됩니다.

**응답 예시**

발급 전 (발급 가능):

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "현금영수증 정보를 조회했습니다.",
    "data": {
        "issuable": true,
        "cash_receipt": null
    }
}
```

발급 완료:

```json
{
    "success": true,
    "message": "현금영수증 정보를 조회했습니다.",
    "data": {
        "issuable": false,
        "cash_receipt": {
            "id": 12,
            "provider": "sirsoft-pay_tosspayments",
            "transaction_type": "issue",
            "receipt_type": "income",
            "receipt_type_label": "소득공제용",
            "amount": 12000,
            "amount_formatted": "12,000원",
            "tax_free_amount": 0,
            "tax_free_amount_formatted": "0원",
            "identifier_masked": "010****5678",
            "receipt_url": "https://dashboard.tosspayments.com/receipt/cash/...",
            "issue_number": "CR20260710000012",
            "issue_status": "COMPLETED",
            "error_code": null,
            "error_message": null,
            "issued_at": "2026-07-10T14:32:11+09:00",
            "issued_at_formatted": "2026-07-10 14:32"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

> 요청 예시에는 표시되지 않지만 **`X-Guest-Order-Token` 헤더가 필수**입니다 — 비회원 주문 조회 인증 시 발급받은 토큰을 그대로 전달합니다. 누락하거나 주문번호와 맞지 않으면 404 를 반환합니다.

**설명** 비회원이 주문 조회 후 해당 주문(`orderNumber`)의 현금영수증 발급 상태를 조회합니다. `VerifyGuestOrderToken` 미들웨어가 `X-Guest-Order-Token` 헤더(비회원 주문 조회 인증 시 발급)를 검증하며, **토큰이 지목한 주문 외에는 접근할 수 없습니다** — 토큰이 없거나 주문번호와 맞지 않으면 404 를 반환합니다.

응답 형식은 회원용 조회(`GET user/orders/{id}/cash-receipt`)와 동일합니다 — `data.issuable`(발급 가능 여부)과 `data.cash_receipt`(활성 영수증 또는 `null`). 이 엔드포인트는 요청 본문을 받지 않으므로 발급 폼 검증에 걸리지 않습니다.


### POST /api/modules/sirsoft-ecommerce/guest/orders/{orderNumber}/cash-receipt
<!-- @generated:start:api.modules.sirsoft-ecommerce.guest.orders.cash-receipt.issue -->
- **라우트명**: `api.modules.sirsoft-ecommerce.guest.orders.cash-receipt.issue`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CashReceiptController@issue`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |
| receipt_type | body | string | 예 | `income`, `expense` | 발급 용도 (income 소득공제용 — 개인 연말정산 / expense 지출증빙용 — 사업자 매입세액공제) |
| identifier_type | body | string | 예 | `phone`, `card`, `business` | 발급 수단 (phone 휴대폰번호 / card 현금영수증카드번호 / business 사업자등록번호 — 사업자등록번호는 지출증빙 전용) |
| identifier | body | string | 예 | max 30 | 식별번호 (하이픈·공백 제거 후 검증 — 휴대폰 10~11자리 / 현금영수증카드 13~19자리 / 사업자등록번호 10자리 체크섬) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/guest/orders/{orderNumber}/cash-receipt HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "receipt_type": "income",
    "identifier_type": "phone",
    "identifier": "example-key"
}
```

**응답 필드** (`data` 내부)

`data` 는 발급 이력 1건(`CashReceiptResource`)이며, 필드 구성은 [발급 API 의 응답 필드 표](orders.md)와 동일합니다.

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "현금영수증이 발급되었습니다.",
    "data": {
        "id": 12,
        "provider": "sirsoft-pay_tosspayments",
        "transaction_type": "issue",
        "receipt_type": "income",
        "receipt_type_label": "소득공제용",
        "amount": 12000,
        "amount_formatted": "12,000원",
        "tax_free_amount": 0,
        "tax_free_amount_formatted": "0원",
        "identifier_masked": "010****5678",
        "receipt_url": "https://dashboard.tosspayments.com/receipt/cash/...",
        "issue_number": "CR20260710000012",
        "issue_status": "COMPLETED",
        "error_code": null,
        "error_message": null,
        "issued_at": "2026-07-10T14:32:11+09:00",
        "issued_at_formatted": "2026-07-10 14:32"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

> 요청 예시에는 표시되지 않지만 **`X-Guest-Order-Token` 헤더가 필수**입니다 — 누락하거나 주문번호와 맞지 않으면 404 를 반환합니다.

**설명** 비회원이 주문 당시 신청하지 않은 현금영수증을 주문상세에서 **직접 사후 발급**합니다. `VerifyGuestOrderToken` 미들웨어가 `X-Guest-Order-Token` 헤더를 검증해 소유권을 보장하며, 토큰이 검증한 주문에 대해서만 발급합니다.

요청 본문(`receipt_type` / `identifier_type` / `identifier`), 검증 규칙, 발급 가능 조건, 오류 코드 체계는 관리자·회원 발급 API 와 모두 동일합니다 — 이미 발급된 주문은 409(`ALREADY_ISSUED`), 그 외 발급 불가 사유는 422 에 `errors.error_code` 로 구분해 담깁니다.

**발급 취소는 제공하지 않습니다**(관리자 전용). 회원 경로와 마찬가지로 비회원용 `DELETE` 라우트 자체를 노출하지 않습니다.


### POST /api/modules/sirsoft-ecommerce/guest/orders/{orderNumber}/estimate-refund
<!-- @generated:start:api.modules.sirsoft-ecommerce.guest.orders.estimate-refund -->
- **라우트명**: `api.modules.sirsoft-ecommerce.guest.orders.estimate-refund`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\OrderController@estimateRefund`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |
| items | body | array | 예 | min 1 | 처리 대상 항목 배열 |
| refund_priority | body | string | 아니오 | `pg_first`, `points_first` | 환불 배분 우선순위 (PG 우선 / 포인트 우선, 미전달 시 pg_first) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/guest/orders/{orderNumber}/estimate-refund HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "items": [
        "예시값"
    ],
    "refund_priority": "pg_first"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`AdjustmentResult::toPreviewArray()` — 실제 취소는 수행하지 않는 환불 예상 계산 결과)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| refund_amount | number | `12000` | PG 환불 예상 금액 (음수면 추가결제 필요) |
| refund_points_amount | number | `0` | 마일리지 환불 예상 금액 |
| original_paid_amount | number | `15000` | 원 결제 금액 |
| recalculated_paid_amount | number | `3000` | 취소 후 재계산된 결제 금액 |
| shipping_difference | number | `0` | 배송비 차이 (양수: 환불 / 음수: 추가결제) |
| discount_difference | number | `0` | 할인 차이 (양수: 할인 감소분) |
| additional_payment_amount | number | `0` | 추가결제 필요 금액 (환불액이 음수일 때만 > 0) |
| cancelled_items | array | — | 취소 대상 항목 정보 (`order_option_id`, `cancel_quantity`, `cancel_amount`) |
| refund_priority | string | `pg_first` | 적용된 환불 배분 우선순위 (`pg_first`, `points_first`) |
| remaining_pg_balance | number | `0` | 환불 후 잔여 PG 결제 잔액 |
| remaining_points_balance | number | `0` | 환불 후 잔여 마일리지 잔액 |
| refund_total | number | `12000` | 총 환불 예상액 (PG 환불액 + 마일리지 환불액) |
| refund_formatted | object | — | 환불 총액·잔액의 표기 문자열 (base 통화 + 결제 통화 병기 — 취소 모달 표기 SSoT) |
| restored_coupons | array | — | 취소로 복원되는 쿠폰 정보 (`coupon_name`, `discount_amount`) |
| shipping_details | array | — | 배송비 정책별 상세 (`policy_name`, `base_difference`, `extra_difference`, `total_difference`) |
| mc_refund_amount | object\|null | — | PG 환불 금액의 다중 통화 표기 (통화코드 → 금액) |
| mc_refund_points_amount | object\|null | — | 마일리지 환불 금액의 다중 통화 표기 |
| mc_refund_shipping_amount | object\|null | — | 배송비 환불 금액의 다중 통화 표기 |
| original_snapshot | object | — | 재계산 전 주문 금액 스냅샷 |
| recalculated_snapshot | object | — | 재계산 후 주문 금액 스냅샷 |
| mc_original_snapshot | object\|null | — | 재계산 전 다중 통화 스냅샷 |
| mc_recalculated_snapshot | object\|null | — | 재계산 후 다중 통화 스냅샷 |
| original_coupons | array | — | 원 주문에 적용된 쿠폰 상세 (`name`, `target_type`, `discount_amount`) |
| recalculated_coupons | array | — | 재계산 후 유지되는 쿠폰 상세 |
| cancel_blocked | boolean | `false` | 취소 차단 여부 (부분취소로 쿠폰 조건이 깨져 추가결제가 필요해지는 경우 true) |
| cancel_blocked_reason | string\|null | `null` | 차단 사유 메시지 (차단이 아니면 `null`) |

**응답 예시**

```json
{
    "success": true,
    "message": "환불 예상금액을 조회했습니다.",
    "data": {
        "refund_amount": 12000,
        "refund_points_amount": 0,
        "original_paid_amount": 15000,
        "recalculated_paid_amount": 3000,
        "shipping_difference": 0,
        "discount_difference": 0,
        "additional_payment_amount": 0,
        "cancelled_items": [
            {
                "order_option_id": 34,
                "cancel_quantity": 2,
                "cancel_amount": 12000
            }
        ],
        "refund_priority": "pg_first",
        "remaining_pg_balance": 3000,
        "remaining_points_balance": 0,
        "refund_total": 12000,
        "refund_formatted": {},
        "restored_coupons": [],
        "shipping_details": [],
        "mc_refund_amount": null,
        "mc_refund_points_amount": null,
        "mc_refund_shipping_amount": null,
        "original_snapshot": {},
        "recalculated_snapshot": {},
        "mc_original_snapshot": null,
        "mc_recalculated_snapshot": null,
        "original_coupons": [],
        "recalculated_coupons": [],
        "cancel_blocked": false,
        "cancel_blocked_reason": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | `X-Guest-Order-Token` 누락·만료·주문번호 불일치 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 환불 예상금액 계산 실패 (`환불 예상금액 조회에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 비회원이 취소를 확정하기 전에 특정 옵션(`items`) 취소 시 예상 환불 금액을 미리 계산해 보여줍니다. 조회 토큰(`X-Guest-Order-Token`)으로 주문 소유권이 검증되며, `OrderController@estimateRefund`가 `OrderCancellationService::previewRefund()`로 실제 취소를 수행하지 않고 환불 예상값만 반환합니다. `refund_priority`에 따라 PG 우선/포인트 우선 환불 배분 결과가 달라집니다. 비회원 주문 취소 화면에서 "환불 예정 금액"을 미리 안내하는 용도입니다.


### POST /api/modules/sirsoft-ecommerce/guest/orders/{orderNumber}/options/{optionId}/confirm
<!-- @generated:start:api.modules.sirsoft-ecommerce.guest.orders.confirm-option -->
- **라우트명**: `api.modules.sirsoft-ecommerce.guest.orders.confirm-option`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\OrderController@confirmOption`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |
| optionId | path | string | 예 | — | 대상 option의 식별자 |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/guest/orders/{orderNumber}/options/{optionId}/confirm HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`GuestOrderResource` — 구매확정 후 갱신된 주문 상세). 필드 구성은 [비회원 주문 취소 응답 필드 표](#guest-order-resource)와 동일합니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| order_number | string | `20260714000012` | 주문번호 |
| order_status | string | `confirmed` | 구매확정 반영된 주문 상태 |
| order_status_label | string\|null | `구매확정` | 주문 상태의 다국어 라벨 |
| confirmed_at | string\|null | `2026-07-14T16:10:03+09:00` | 구매확정 일시 (ISO 8601) |
| options | array | — | 주문 옵션 목록 — 확정된 옵션의 상태가 갱신되어 포함 |
| abilities | object | `{"can_cancel": false}` | 주문 상태 기반 허용 액션 |
| _(그 외)_ | — | — | 금액·일시·주문자/수취인·배송지·결제·배송·취소이력 필드는 [비회원 주문 취소 응답 필드 표](#guest-order-resource)와 동일 |

**응답 예시**

```json
{
    "success": true,
    "message": "구매확정이 완료되었습니다.",
    "data": {
        "order_number": "20260714000012",
        "order_status": "confirmed",
        "order_status_label": "구매확정",
        "order_status_variant": "success",
        "is_partially_cancelled": false,
        "total_amount": 15000,
        "total_amount_formatted": "15,000원",
        "confirmed_at": "2026-07-14T16:10:03+09:00",
        "confirmed_at_formatted": "2026-07-14 16:10",
        "options": [],
        "shipping_address": {},
        "shippings": [],
        "cancels": [],
        "abilities": {
            "can_cancel": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | `X-Guest-Order-Token` 누락·만료·주문번호 불일치, 또는 `optionId` 가 해당 주문에 속하지 않는 경우 |
| 422 | Unprocessable Entity | 구매확정 불가 상태 (배송 미완료 등 — `현재 상태에서는 구매확정할 수 없습니다.`) |

<!-- @generated:end -->

**설명** 비회원이 배송 완료된 주문의 개별 옵션(`optionId`)을 구매확정합니다. 조회 토큰(`X-Guest-Order-Token`)으로 주문 소유권이 검증되며, `OrderController@confirmOption`이 토큰으로 검증된 주문에 실제 속한 옵션인지 다시 확인한 뒤 `OrderService::confirmOption()`을 호출합니다. 주문에 속하지 않은 옵션 ID면 404, 확정 불가 상태(배송 미완료 등)면 422를 반환합니다. 구매확정 시 적립 포인트 확정 등 후속 처리가 서비스 계층에서 이어집니다.


### PUT /api/modules/sirsoft-ecommerce/guest/orders/{orderNumber}/shipping-address
<!-- @generated:start:api.modules.sirsoft-ecommerce.guest.orders.update-shipping-address -->
- **라우트명**: `api.modules.sirsoft-ecommerce.guest.orders.update-shipping-address`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\OrderController@updateShippingAddress`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |
| recipient_name | body | string | 예 | max 50 | 수령인 이름 |
| recipient_phone | body | string | 예 | max 20 | 수령인 연락처 |
| recipient_tel | body | string | 아니오 | max 20 | 수령인 일반전화 (선택 연락처) |
| country_code | body | string | 아니오 | — | 국가 코드 (ISO 3166-1 alpha-2) |
| zipcode | body | string | 아니오 | max 10 | 우편번호 |
| address | body | string | 아니오 | max 255 | 기본 주소 |
| address_detail | body | string | 아니오 | max 255 | 상세 주소 |
| address_line_1 | body | string | 아니오 | max 255 | 주소 1행 (기본 주소) |
| address_line_2 | body | string | 아니오 | max 255 | 주소 2행 (상세 주소) |
| intl_city | body | string | 아니오 | max 100 | 도시 (국제 주소) |
| intl_state | body | string | 아니오 | max 100 | 주/도 (국제 주소) |
| intl_postal_code | body | string | 아니오 | max 20 | 우편번호 (국제 주소) |
| delivery_memo | body | string | 아니오 | max 255 | 배송 메모 (배송 시 요청사항) |

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/guest/orders/{orderNumber}/shipping-address HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "recipient_name": "예시 이름",
    "recipient_phone": "010-1234-5678",
    "recipient_tel": "예시값",
    "country_code": "KR",
    "zipcode": "06234",
    "address": "서울특별시 강남구 테헤란로 1",
    "address_detail": "서울특별시 강남구 테헤란로 1",
    "address_line_1": "서울특별시 강남구 테헤란로 1",
    "address_line_2": "서울특별시 강남구 테헤란로 1",
    "intl_city": "예시값",
    "intl_state": "예시값",
    "intl_postal_code": "06234",
    "delivery_memo": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`GuestOrderResource` — 배송지 수정 후 갱신된 주문 상세). 필드 구성은 [비회원 주문 취소 응답 필드 표](#guest-order-resource)와 동일합니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| order_number | string | `20260714000012` | 주문번호 |
| recipient_name | string\|null | `홍길동` | 수정 반영된 수령인 이름 |
| recipient_phone | string\|null | `010-1234-5678` | 수정 반영된 수령인 연락처 |
| recipient_zipcode | string\|null | `06234` | 수정 반영된 우편번호 |
| recipient_address | string\|null | `서울특별시 강남구 테헤란로 1` | 수정 반영된 기본 주소 |
| recipient_detail_address | string\|null | `101호` | 수정 반영된 상세 주소 |
| delivery_memo | string\|null | `부재 시 경비실에 맡겨주세요` | 수정 반영된 배송 메모 |
| shipping_address | object\|null | — | 배송지 상세 (`OrderAddressResource` — 국제 주소 필드 포함) |
| _(그 외)_ | — | — | 상태·금액·일시·옵션·결제·배송·취소이력·abilities 필드는 [비회원 주문 취소 응답 필드 표](#guest-order-resource)와 동일 |

**응답 예시**

```json
{
    "success": true,
    "message": "배송지가 변경되었습니다.",
    "data": {
        "order_number": "20260714000012",
        "order_status": "payment_complete",
        "order_status_label": "결제완료",
        "recipient_name": "홍길동",
        "recipient_phone": "010-1234-5678",
        "recipient_zipcode": "06234",
        "recipient_address": "서울특별시 강남구 테헤란로 1",
        "recipient_detail_address": "101호",
        "delivery_memo": "부재 시 경비실에 맡겨주세요",
        "delivery_memo_label": null,
        "shipping_address": {},
        "options": [],
        "shippings": [],
        "cancels": [],
        "abilities": {
            "can_cancel": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | `X-Guest-Order-Token` 누락·만료·주문번호 불일치 |
| 422 | Unprocessable Entity | 요청 파라미터 검증 위반 (`error.errors` 에 필드별 메시지), 또는 배송이 이미 시작되어 수정 불가 (`배송 전 상태에서만 배송지를 변경할 수 있습니다.`) |

<!-- @generated:end -->

**설명** 비회원이 배송 전 상태의 주문 배송지를 수정합니다. 조회 토큰(`X-Guest-Order-Token`)으로 주문 소유권이 검증되며, 비회원은 저장된 회원 주소(`address_id`)를 쓸 수 없으므로 수취인·연락처·주소 필드를 직접 입력받아 `OrderController@updateShippingAddress`가 회원과 동일한 `OrderService::updateShippingAddress()`로 처리합니다. 국내(`zipcode`/`address`)와 해외(`address_line_1`·`intl_city` 등) 주소 필드를 함께 지원합니다. 이미 배송이 시작된 주문 등 수정 불가 상태면 422를 반환합니다.


