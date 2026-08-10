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

- 이 엔드포인트는 **주문 상태를 변경하지 않는다**. 결제 취소 이력 기록은 프론트엔드가 별도 API(`/modules/sirsoft-ecommerce/orders/{orderNumber}/cancel-payment`)로 수행한다.
- 결제창을 띄우기 전 단계에서 사용자가 취소한 경우(SDK `USER_CANCEL`)는 이 콜백을 타지 않고 프론트엔드에서 직접 처리된다.


### GET /plugins/sirsoft-tosspayments/payment/success
<!-- @generated:start:web.plugins.sirsoft-tosspayments.payment.success -->
- **라우트명**: `web.plugins.sirsoft-tosspayments.payment.success`
- **컨트롤러**: `Plugins\Sirsoft\Tosspayments\Controllers\PaymentCallbackController@success`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| paymentKey | query | string | 예 | — | 토스가 발급한 결제 키. Confirm API 호출에 사용한다. |
| orderId | query | string | 예 | — | 주문번호 (G7 `orders.order_number`). SDK 호출 시 넘긴 값이 그대로 돌아온다. |
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


