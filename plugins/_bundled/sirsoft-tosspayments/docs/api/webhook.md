# Webhook API 레퍼런스

> **소유**: plugin `sirsoft-tosspayments` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Webhook 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /plugins/sirsoft-tosspayments/webhook/deposit
<!-- @generated:start:web.plugins.sirsoft-tosspayments.webhook.deposit -->
- **라우트명**: `web.plugins.sirsoft-tosspayments.webhook.deposit`
- **컨트롤러**: `Plugins\Sirsoft\Tosspayments\Controllers\WebhookController@deposit`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderId | body | string | 예 | max 100 | 주문번호 (그누보드7 `orders.order_number`). 이 값으로 주문·결제 레코드를 조회한다. |
| status | body | string | 예 | `DONE`, `CANCELED` | 입금 결과. `DONE`=입금완료 → 결제완료 처리, `CANCELED`=입금취소 → 결제실패 처리. |
| secret | body | string | 아니오 | max 255 | 결제 승인 응답에서 발급받아 `payment_meta.toss_secret` 에 저장해 둔 값. 위조 방지 대조에 사용. |
| transactionKey | body | string | 아니오 | max 255 | 토스 거래 키. 결제완료 처리 시 `transaction_id` 로 기록한다 (없으면 기존 값 유지). |
| createdAt | body | string | 아니오 | max 64 | 토스가 이벤트를 생성한 시각. `payment_meta.deposit_confirmed_at` 에 기록한다. |

**요청 예시**

```http
POST /plugins/sirsoft-tosspayments/webhook/deposit HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "orderId": "예시값",
    "status": "DONE",
    "secret": "예시값",
    "transactionKey": "예시값",
    "createdAt": "예시값"
}
```

**응답 필드** (`data` 내부)

이 엔드포인트는 코어 API 응답 봉투(`{success, data, message}`)를 쓰지 않는다. 토스 웹훅 규약에 맞춰 `text/plain` 본문만 반환한다.

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: text/plain

OK
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

가상계좌 입금통보(DEPOSIT_CALLBACK)를 수신한다. 구매자가 발급받은 가상계좌에 입금하면 토스 서버가 이 엔드포인트를 호출한다.

이 엔드포인트는 코어 API 응답 봉투(`{success, data, message}`)를 쓰지 않는다. 토스 웹훅 규약에 맞춰 `text/plain` 본문만 반환한다.

| 상태코드 | 본문 | 발생 조건 |
| --- | --- | --- |
| 200 | `OK` | 입금 반영 성공 / 이미 결제완료된 거래(리플레이) / 입금대기 상태가 아닌 주문 — 토스의 재전송을 멈추기 위해 200 을 반환한다 |
| 401 | `UNAUTHORIZED` | `webhook_secret_verify` 가 켜진 상태에서 본문 `secret` 이 저장된 `payment_meta.toss_secret` 과 다른 경우 |
| 200 | `FAIL` | 주문/결제 레코드를 찾지 못하거나 처리 중 예외 발생 (토스는 본문 `FAIL` 을 실패로 간주해 재전송한다) |
| 422 | JSON | 요청 파라미터 검증 실패 |

처리 순서:

1. **리플레이 방지** — 이미 결제완료된 거래(`transaction_id` 기준)면 아무것도 하지 않고 `OK` 를 반환한다 (멱등).
2. **secret 대조** — 플러그인 설정 `webhook_secret_verify` 가 켜져 있으면, 본문의 `secret` 을 결제 승인 시 저장해 둔 `payment_meta.toss_secret` 과 대조한다 (`hash_equals`). 불일치 시 401.
3. **상태 가드** — 결제가 입금대기(`waiting_deposit`) 상태가 아니면 처리하지 않고 `OK` 를 반환한다.
4. **상태별 처리** — `status=DONE` 이면 결제완료(`completePayment`), `status=CANCELED` 이면 결제실패(`failPayment`) 처리한다.

주의사항:

- 토스는 notify IP 목록·요청 서명을 제공하지 않는다. 공식 위조 방지 수단은 **secret 대조뿐**이므로 `webhook_secret_verify` 를 끄지 않는 것을 권장한다.
- 토스는 CSRF 토큰을 보내지 않으므로 이 라우트는 `ValidateCsrfToken` 이 면제되어 있다.
- 미응답 시 토스가 최대 7회 재전송한다. 부수 작업(알림·재고·적립금 등)은 `completePayment` 내부 훅 리스너에 위임하고 컨트롤러는 빠르게 응답한다.


### POST /plugins/sirsoft-tosspayments/webhook/payment-status
<!-- @generated:start:web.plugins.sirsoft-tosspayments.webhook.payment-status -->
- **라우트명**: `web.plugins.sirsoft-tosspayments.webhook.payment-status`
- **컨트롤러**: `Plugins\Sirsoft\Tosspayments\Controllers\WebhookController@paymentStatus`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| eventType | body | string | 아니오 | max 64 | 토스 이벤트 종류 (예: `PAYMENT_STATUS_CHANGED`). |
| createdAt | body | string | 아니오 | max 64 | 토스가 이벤트를 생성한 시각. |
| data | body | array | 예 | — | 결제 정보 객체. `data.orderId`(주문번호)와 `data.status`(토스 결제상태)를 읽어 로컬 상태와 대조한다. |
| data.orderId | body | string | 예 | max 100 | 주문번호 (그누보드7 `orders.order_number`). 이 값으로 주문·결제 레코드를 조회해 로컬 결제상태를 확인한다. |
| data.status | body | string | 예 | max 40 | 토스 측 결제상태 (예: `DONE`, `CANCELED`, `WAITING_FOR_DEPOSIT`). 로컬 `payments.payment_status` 와 함께 로그에 기록해 불일치를 추적한다. |
| data.paymentKey | body | string | 아니오 | max 255 | 토스 결제 키. 수신만 하며 이 엔드포인트에서 상태 전이에 사용하지 않는다. |

**요청 예시**

```http
POST /plugins/sirsoft-tosspayments/webhook/payment-status HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "eventType": "예시값",
    "createdAt": "예시값",
    "data": [
        "예시값"
    ],
    "data.orderId": "예시값",
    "data.status": "예시값",
    "data.paymentKey": "예시값"
}
```

**응답 필드** (`data` 내부)

이 엔드포인트는 코어 API 응답 봉투를 쓰지 않는다. 토스 웹훅 규약에 맞춰 `text/plain` 본문만 반환한다.

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: text/plain

OK
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

결제상태 변경(PAYMENT_STATUS_CHANGED) 이벤트를 수신해 **상태 동기화 로깅**만 수행한다. 토스 측 결제상태(`data.status`)와 로컬 결제상태를 함께 기록해, 두 값이 어긋난 경우를 로그로 추적할 수 있게 한다.

이 엔드포인트도 코어 API 응답 봉투를 쓰지 않고 `text/plain` 본문만 반환한다.

| 상태코드 | 본문 | 발생 조건 |
| --- | --- | --- |
| 200 | `OK` | 정상 수신 / 주문을 찾지 못한 경우 모두 200 (재전송 중단) |
| 422 | JSON | 요청 파라미터 검증 실패 |

주의사항:

- 이 엔드포인트는 주문 상태를 **변경하지 않는다**. 실제 상태 전이는 결제 승인 콜백(`/payment/success`)과 입금통보 웹훅(`/webhook/deposit`)이 담당한다.
- 주문을 찾지 못해도 200 `OK` 를 반환한다 (토스의 재전송을 멈추기 위함).
- 토스는 CSRF 토큰을 보내지 않으므로 이 라우트는 `ValidateCsrfToken` 이 면제되어 있다.


