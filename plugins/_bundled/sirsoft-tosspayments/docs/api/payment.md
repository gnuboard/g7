# Payment API 레퍼런스

> **소유**: plugin `sirsoft-tosspayments` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Payment 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /plugins/sirsoft-tosspayments/payment/fail
<!-- @generated:start:web.plugins.sirsoft-tosspayments.payment.fail -->
- **라우트명**: `web.plugins.sirsoft-tosspayments.payment.fail`
- **컨트롤러**: `Plugins\Sirsoft\Tosspayments\Controllers\PaymentCallbackController@fail`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| code | query | string | 아니오 | — | 토스 실패 코드 (예: `PAY_PROCESS_CANCELED`, `REJECT_CARD_COMPANY`). |
| message | query | string | 아니오 | — | 토스가 내려준 실패 사유 메시지. |
| orderId | query | string | 아니오 | — | 주문번호. 실패 페이지로 함께 전달해 어떤 주문의 결제가 실패했는지 표시한다. |

**요청 예시**

```http
GET /plugins/sirsoft-tosspayments/payment/fail?code=%EC%98%88%EC%8B%9C%EA%B0%92&message=%EC%98%88%EC%8B%9C%EA%B0%92&orderId=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 을 반환하지 않는다. 브라우저를 실패 페이지로 **302 리다이렉트**한다.

**응답 예시**

```http
HTTP/1.1 302 Found
Location: /shop/checkout?error=PAY_PROCESS_CANCELED&orderId=20260711-000001
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

토스 결제창에서 결제가 실패하거나 사용자가 결제를 취소하면 브라우저가 이 URL 로 리다이렉트된다.

JSON 을 반환하지 않는다. 실패 사유를 로그로 기록한 뒤, 실패 페이지(기본 `/shop/checkout`, 설정 `redirect_fail_url`)로 실패 코드·메시지·주문번호를 query 로 실어 **302 리다이렉트**한다. 모든 파라미터가 선택값이라 없어도 4xx 를 반환하지 않는다.

```http
HTTP/1.1 302 Found
Location: /shop/checkout?error=PAY_PROCESS_CANCELED&orderId=20260711-000001
```

주의사항:

- 이 엔드포인트는 **주문 상태를 변경하지 않는다**. 인증도 서명도 없는 GET 이고 `orderId`·`code` 가 전부 쿼리스트링에서 오므로, 실패 처리를 수행하면 링크 하나로 남의 결제대기 주문을 취소시킬 수 있다. 결제 실패 기록은 구매자 정보를 대조하는 `POST /api/plugins/sirsoft-tosspayments/payment/close-report` 가 담당하며, 실패 화면에 도착한 프론트엔드가 그 경로로 보고한다.
- 결제창을 띄우기 전 단계에서 사용자가 취소한 경우(SDK `USER_CANCEL`)는 이 콜백을 타지 않고 프론트엔드에서 직접 처리된다.


### POST /api/plugins/sirsoft-tosspayments/payment/close-report
<!-- @generated:start:api.plugins.sirsoft-tosspayments.payment.close-report -->
- **라우트명**: `api.plugins.sirsoft-tosspayments.payment.close-report`
- **컨트롤러**: `Plugins\Sirsoft\Tosspayments\Controllers\PaymentCloseReportController@store`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderId | body | string | 예 | max 40 | 결제창을 닫거나 결제가 거절된 대상 주문의 주문번호. 서버가 이 값으로 주문을 조회해 결제 실패/취소 이력을 기록한다. |
| amount | body | integer | 예 | min 1 | 결제 금액. 저장된 주문 청구액과 일치하는지 검증한다. |
| buyer_email | body | string | 아니오 | max 255 | 구매자 이메일. 주문의 구매자 정보와 대조해 본인 요청인지 확인한다. |
| buyer_phone | body | string | 아니오 | max 30 | 구매자 전화번호. 주문의 구매자 정보와 대조해 본인 요청인지 확인한다. |
| code | body | string | 아니오 | max 60 | 토스 실패 코드. 값이 있으면 결제 거절로, 없으면 결제창 닫힘으로 기록한다. |
| reason | body | string | 아니오 | max 160 | 사람이 읽을 실패 사유. 취소 이력에 남는다. |

**요청 예시**

```http
POST /api/plugins/sirsoft-tosspayments/payment/close-report HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "orderId": "20260711-000001",
    "amount": 10000,
    "buyer_email": "buyer@example.com",
    "buyer_phone": "01012345678",
    "code": "REJECT_CARD_COMPANY",
    "reason": "카드사에서 승인을 거절했습니다."
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| status | string | `recorded` | 처리 결과. 기록했으면 `recorded`, 대상이 아니어서 넘어갔으면 `ignored`. |
| reason | string | `order_not_payable` | `status` 가 `ignored` 일 때만 포함. 무시 사유(`order_not_payable` / `payment_already_paid`). |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "status": "recorded"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요청의 구매자 정보(`buyer_email` / `buyer_phone`)가 주문의 구매자와 일치하지 않는 경우 |
| 404 | Not Found | `orderId` 에 해당하는 주문이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지), 주문 통화가 청구 불가, 금액이 주문 청구액과 불일치 |
| 429 | Too Many Requests | 동일 IP·`orderId` 조합에서 분당 20회를 초과해 요청한 경우 |

<!-- @generated:end -->

**설명**

**주문을 실패로 전이시키는 유일한 결제창 경로**입니다. 브라우저 리턴 콜백(`/payment/fail`)은 인증도 서명도 없고 주문번호가 쿼리스트링으로 오므로 주문 상태를 바꾸지 않습니다. 정당한 결제 실패는 구매자 이메일·전화 대조를 통과한 이 요청으로만 기록되며, 이는 다른 결제사 플러그인(KCP·KG이니시스·나이스페이)의 close-report 와 같은 계약입니다.

프론트엔드는 결제창을 열기 직전에 구매자 정보를 브라우저 세션에 남겨 두었다가, 결제가 거절되어 실패 화면으로 돌아왔을 때 그 정보로 이 엔드포인트를 호출합니다. 결제창은 전체 페이지 이동으로 열리고 돌아오므로 화면의 컨텍스트는 그 사이 소실되기 때문입니다.

`code` 유무로 기록이 갈립니다 — 값이 있으면 결제 거절(`failure_stage=payment_failed`), 없으면 결제창 닫힘(`failure_stage=window_closed`)으로 남아 운영자가 원인을 구분할 수 있습니다.

이미 결제가 성립한 주문(`payment_status=paid`)과 결제 가능 상태가 아닌 주문은 성공 응답에 `status: ignored` 로 무시합니다 — 결제 성공 콜백(`success` → `confirmPayment`)과 경쟁할 때 주문/옵션 상태가 어긋나는 것을 차단합니다.

보고가 끝내 도달하지 못한 주문(브라우저를 바로 닫는 등)은 이커머스 모듈의 만료 주문 자동 정리가 최종 안전망으로 처리합니다.


### GET /plugins/sirsoft-tosspayments/payment/success
<!-- @generated:start:web.plugins.sirsoft-tosspayments.payment.success -->
- **라우트명**: `web.plugins.sirsoft-tosspayments.payment.success`
- **컨트롤러**: `Plugins\Sirsoft\Tosspayments\Controllers\PaymentCallbackController@success`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| paymentKey | query | string | 예 | — | 토스가 발급한 결제 키. Confirm API 호출에 사용한다. |
| orderId | query | string | 예 | — | 주문번호 (그누보드7 `orders.order_number`). SDK 호출 시 넘긴 값이 그대로 돌아온다. |
| amount | query | integer | 예 | min 1 | 결제 금액. 주문의 결제요청 금액과 대조하며, 불일치 시 결제를 승인하지 않는다. |

**요청 예시**

```http
GET /plugins/sirsoft-tosspayments/payment/success?paymentKey=%EC%98%88%EC%8B%9C%EA%B0%92&orderId=%EC%98%88%EC%8B%9C%EA%B0%92&amount=1 HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 을 반환하지 않는다. 브라우저를 SPA 페이지로 **302 리다이렉트**한다.

**응답 예시**

```http
HTTP/1.1 302 Found
Location: /shop/orders/20260711-000001/complete
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

토스 결제창에서 결제가 성공하면 브라우저가 이 URL 로 리다이렉트된다. 결제 **승인(Confirm)** 을 수행하는 지점이다.

JSON 을 반환하지 않고 브라우저를 **302 리다이렉트**한다. 에러 상황에서도 4xx/5xx 를 반환하지 않고 실패 페이지(기본 `/shop/checkout`)로 리다이렉트하며 사유를 query 로 전달한다.

| 리다이렉트 대상 | `error` query | 발생 조건 |
| --- | --- | --- |
| 주문완료 페이지 | — | 결제 승인 성공 (기본 `/shop/orders/{orderId}/complete`) |
| 실패 페이지 | `order_not_found` | `orderId` 로 주문을 찾지 못한 경우 |
| 실패 페이지 | `amount_mismatch` | 콜백 `amount` 가 주문의 결제요청 금액과 다른 경우 (위변조 방어) |
| 실패 페이지 | `confirm_failed` | 토스 Confirm API 호출이 실패한 경우 (`message` query 에 사유) |
| 실패 페이지 | (검증 실패) | 필수 query 파라미터 누락/형식 오류 — 422 대신 실패 페이지로 리다이렉트한다 |

처리 순서:

1. `orderId` 로 주문을 조회한다.
2. 토스 **Confirm API** 를 호출해 결제를 확정한다 (`sirsoft-tosspayments.payment.before_confirm` / `after_confirm` 훅 발화).
3. 응답 `status` 로 분기한다:
   - **`WAITING_FOR_DEPOSIT`(가상계좌)** — 아직 입금 전이므로 결제완료 처리하지 않고 계좌 정보만 저장한다. 실제 결제완료는 입금통보 웹훅(`/webhook/deposit`)이 담당한다. 웹훅 secret 은 **이 Confirm 응답에만** 내려오므로 `payment_meta.toss_secret` 에 저장해 웹훅 대조에 사용한다.
   - **그 외(카드·계좌이체·휴대폰·간편결제)** — 즉시 결제완료(`completePayment`) 처리하고 카드 승인번호·영수증 URL 등을 기록한다.
4. SPA 주문완료 페이지로 리다이렉트한다.

주의사항:

- **금액 대조가 위변조 방어의 핵심**이다. query 의 `amount` 를 그대로 신뢰하지 않고 주문의 결제요청 금액과 대조하며, 불일치 시 `PaymentAmountMismatchException` 으로 승인을 중단한다.
- 리다이렉트 대상 URL 은 플러그인 설정(`redirect_success_url` / `redirect_fail_url`)으로 바꿀 수 있다 (기본 `/shop/orders/{orderId}/complete`, `/shop/checkout`).


