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
| 200 | `FAIL` | 주문/결제 레코드를 찾지 못하거나, 입금 금액을 청구액과 대조할 수 없거나 어긋나거나, 처리 중 예외 발생 (토스는 본문 `FAIL` 을 실패로 간주해 재전송한다) |
| 500 | `FAIL` | 발급 금액 기록이 없는 결제행의 토스 결제 조회 호출이 실패한 경우 — 대조를 건너뛰지 않고 재전송을 받는다 |
| 422 | JSON | 요청 파라미터 검증 실패 — `{success: false, message, errors}`. 직전 페이지(Referer)·요청 Host 로 이동(302)하지 않는다 |

처리 순서:

1. **리플레이 방지** — 이미 결제완료된 거래(`transaction_id` 기준)면 아무것도 하지 않고 `OK` 를 반환한다 (멱등).
2. **secret 대조** — 플러그인 설정 `webhook_secret_verify` 가 켜져 있으면, 본문의 `secret` 을 결제 승인 시 저장해 둔 `payment_meta.toss_secret` 과 대조한다 (`hash_equals`). 불일치 시 401.
3. **취소 주문 가드** — 주문이 이미 `cancelled` 면 처리하지 않고 `OK` 를 반환한다. 고객이 취소한 뒤에 입금해도 통보는 도착하는데, 통과시키면 취소된 주문이 결제완료로 되살아나 재고와 마일리지가 다시 차감된다. 아래 결제상태 가드만으로는 막히지 않는다 — 미입금 취소가 결제행을 `waiting_deposit` 그대로 두던 시절의 행이 그 가드를 통과하기 때문이다.
4. **상태 가드** — 결제가 입금대기(`waiting_deposit`) 상태가 아니면 처리하지 않고 `OK` 를 반환한다.
5. **입금 금액 대조** (`status=DONE`) — 가상계좌 발급 때 청구액과 대조해 저장한 `payment_meta.toss_approved_amount` 를 현재 청구액과 대조한다. 그 기록이 없는 결제행(이 대조가 생기기 전에 발급된 건)은 토스 결제 조회(`GET /v1/payments/{paymentKey}`)로 상태가 `DONE` 이고 `totalAmount` 가 청구액과 같은지 확인한다. 어긋나거나 확인할 수 없으면 결제완료로 전이하지 않는다 — 결제행에 저장해 둔 예정액(`paid_amount_base`)은 금액 근거로 쓰지 않는다.
6. **상태별 처리** — `status=DONE` 이면 대조를 마친 금액으로 결제완료(`completePayment`), `status=CANCELED` 이면 결제실패(`failPayment`) 처리한다.

주의사항:

- 토스는 notify IP 목록·요청 서명을 제공하지 않는다. 공식 위조 방지 수단은 **secret 대조뿐**이므로 `webhook_secret_verify` 를 끄지 않는 것을 권장한다. 끄면 주문번호만 아는 사람도 입금 없이 주문을 결제완료로 만들 수 있고, 끈 상태에서 생긴 보안 사고는 운영자 책임이다(스위치는 운영자 선택으로 남겨 둔다 — 2026-09-29 결정).
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
| 422 | JSON | 요청 파라미터 검증 실패 — `{success: false, message, errors}`. 직전 페이지(Referer)·요청 Host 로 이동(302)하지 않는다 |

주문번호 자리(`data.orderId`)에 클레임 추가결제 결제번호(`CP` 채번)가 실려 오면 대조할 주문이 없으므로 주문 조회를 건너뛰고 토스 측 상태만 로그로 남긴 뒤 `OK` 를 반환한다 — 클레임 결제의 상태 권위는 승인 콜백이며 이 웹훅은 로깅 전용이다.

주의사항:

- 이 엔드포인트는 주문 상태를 **변경하지 않는다**. 실제 상태 전이는 결제 승인 콜백(`/payment/success`)과 입금통보 웹훅(`/webhook/deposit`)이 담당한다.
- 주문을 찾지 못해도 200 `OK` 를 반환한다 (토스의 재전송을 멈추기 위함).
- 토스는 CSRF 토큰을 보내지 않으므로 이 라우트는 `ValidateCsrfToken` 이 면제되어 있다.


### GET /api/plugins/sirsoft-tosspayments/admin/webhook-url
<!-- @generated:start:api.plugins.sirsoft-tosspayments.admin.webhook-url -->
- **라우트명**: `api.plugins.sirsoft-tosspayments.admin.webhook-url`
- **컨트롤러**: 라우트 클로저 (`src/routes/api.php`)
- **인증/권한**: `auth:sanctum`, `permission:admin,sirsoft-ecommerce.settings.read`

**요청 파라미터**

없음

**요청 예시**

```http
GET /api/plugins/sirsoft-tosspayments/admin/webhook-url HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| url | string | `https://example.com/plugins/sirsoft-tosspayments/webhook/deposit` | 가상계좌 입금통보 웹훅 수신 전체 URL (사이트 주소 포함) |

**응답 예시**

```json
{
    "success": true,
    "data": {
        "url": "https://example.com/plugins/sirsoft-tosspayments/webhook/deposit"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 관리자가 아니거나 `sirsoft-ecommerce.settings.read` 권한이 없는 경우 |

<!-- @generated:end -->

**설명**

관리자 설정 화면이 가상계좌 입금통보 웹훅 주소를 보여 주고 「복사」하는 데 쓰는 조회다. 운영자는 이 값을 토스 개발자센터 > 웹훅 메뉴에 DEPOSIT_CALLBACK 이벤트로 그대로 붙여 넣는다.

- 주소는 서버가 코어 사이트 주소(`APP_URL`, `SiteUrl::absolute()`) 기준으로 만든 전체 URL 이라 운영자가 도메인을 손으로 덧붙일 필요가 없다. 관리자가 접속한 주소(요청 Host)는 따르지 않는다. 다른 결제사 플러그인(KG이니시스·NHN KCP·나이스페이먼츠)의 입금통보 URL 조회와 같은 방식이다.
- 설정 화면은 응답이 오기 전·권한 부족으로 조회가 실패한 경우 경로(`/plugins/sirsoft-tosspayments/webhook/deposit`)로 폴백해 표시한다.
- 관리자 화면을 사내 주소·프록시 뒤 주소로 열어도 사이트 주소가 붙는다. 사이트 주소(`APP_URL`)가 토스 서버가 도달할 수 있는 공개 도메인인지 확인한 뒤 복사한다(사이트 주소가 비어 있으면 경로만 내려간다).
