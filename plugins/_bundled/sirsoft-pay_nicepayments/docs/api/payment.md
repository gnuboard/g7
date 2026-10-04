# Payment(결제창 서명·닫힘 보고·리턴 콜백) API 레퍼런스

> **소유**: plugin `sirsoft-pay_nicepayments` · 사람이 서술한 레퍼런스. 결제창(브라우저) 흐름이 호출하는 세 경로 — 서명값 발급(web)·결제창 닫힘 보고(api)·브라우저 리턴 콜백(web) — 를 서술한다. 나이스페이 서버가 직접 보내는 입금통보는 [vbank.md](vbank.md) 가 소유한다.

---

## TL;DR (5초 요약)

```text
1. 결제창 호출 직전 브라우저가 서명값(SignData)을 발급받는 경로다
2. amt(정수)·moid(max 64) 형식 위반은 422 (SignDataRequest — 종전 400 에서 422 로 표준화)
3. 주문 실존·구매자 일치·나이스페이 결제 건 여부를 서버가 재검증한다 (금액 조작 방지)
4. 과도 호출은 429 (주문번호 기준 rate limit)
5. 응답은 envelope 없이 { ediDate, signData, mid } 원시 JSON (결제창 SDK 계약)
6. 주문을 실패로 전이시키는 결제창 경로는 close-report 하나뿐 — 주문 소유자·금액 확인 + IP·주문번호별 분당 20회. 승인 콜백은 실패해도 주문을 바꾸지 않는다
7. 리턴 콜백은 인증 단계 실패에서 주문 상태를 바꾸지 않는다 — 서명 검증 뒤 서버 승인 이후의 실패만 반영 + 망취소
```

---

### POST /plugins/sirsoft-pay_nicepayments/payment/sign-data

- **라우트명**: `web.plugins.sirsoft-pay_nicepayments.payment.sign-data`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\PaymentCallbackController@signData`
- **인증/권한**: web 라우트 (`optional.sanctum`). 서명 발급 자체는 구매자 대조로 판정하지만(상태 불변), 실패 주문을 결제대기로 되살리는 재결제 복구는 주문 소유자(회원 본인 로그인 / 비회원 `X-Guest-Order-Token`)만 가능하다 (KVE-2026-2434)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| amt | body | integer | 예 | 1 ~ 999999999999 | 결제 금액. 서버가 주문 금액과 대조하므로 임의 값은 서명 발급이 거절된다 |
| moid | body | string | 예 | max 64 | 주문번호(MOID) |

**응답 (200)**

```json
{
    "ediDate": "20260817143000",
    "signData": "…SHA-256 서명…",
    "mid": "nictest00m"
}
```

응답은 코어 envelope 를 쓰지 않는다 — 나이스페이 결제창 SDK 가 소비하는 원시 JSON 계약이다. 클라이언트는 `response.ok` 만 검사한다.

**오류 응답**

| 상태 | 조건 |
| --- | --- |
| 422 | `amt`/`moid` 형식 위반 (`SignDataRequest` — 종전 400 응답을 422 로 표준화, JSON 을 요구하지 않은 요청도 422 이며 직전 페이지·요청 Host 로 이동하지 않는다) / 주문 없음 / 나이스페이 결제 건이 아님 |
| 403 | 구매자 대조 실패 (다른 사용자의 주문번호로 서명 요청), 또는 재결제 복구가 필요한 주문인데 주문 소유자가 아님 |
| 429 | 주문번호 기준 rate limit 초과 (60초 내 20회) |

---

## 주문 상태를 바꾸는 결제창 경로는 close-report 하나뿐이다

브라우저 리턴 콜백(`POST /plugins/sirsoft-pay_nicepayments/payment/callback`)의 1단계 입력 — 인증 결과코드(`AuthResultCode`)·주문번호(`Moid`)·금액(`Amt`) — 은 전부 **요청자가 고른 값**이다. 그 입력만 근거로 주문을 실패 처리하면 남의 주문번호를 담은 POST 한 번으로 **타인의 결제대기 주문을 취소시킬 수 있다** (KVE-2026-2018 계열, 형제 플러그인과 같은 규약).

그래서 콜백은 인증 단계 실패(`AuthResultCode != '0000'`)에서는 주문 상태를 건드리지 않고 실패 URL 로 되돌려 보내기만 한다. 주문을 실패로 전이시키는 것은 주문 소유자(회원 본인 로그인 / 비회원 조회 토큰)가 보낸 close-report 요청 하나뿐이다. 서명(`AuthToken+MID+Amt`)은 주문번호를 묶지 않아, 자기 결제창에서 얻은 인증값에 남의 주문번호를 붙여 서버 승인 단계까지 갈 수 있다 — 그래서 승인 이후의 실패(승인 거절·금액 불일치·예외)도 망취소만 하고 주문은 바꾸지 않으며, 승인에 성공해도 승인 응답의 `Moid`·`Amt` 가 콜백과 같을 때만 반영한다 (KVE-2026-2434). 승인 응답에 금액(`Amt`)이 없거나 숫자가 아니면 불일치로 보고 망취소한다 — 결제완료·가상계좌 발급 기록의 금액 근거는 브라우저가 보낸 콜백 금액이 아니라 승인 응답의 금액이며, 그 금액을 주문 청구액과 다시 대조한다. 가상계좌 발급은 주문의 결제수단이 가상계좌이고 발급 금액이 주문 청구액과 같을 때만 기록하고, 아니면 발급 승인을 망취소한다(`error=amount_mismatch` / `order_not_payable`).

| 시점 | 판정 근거 | 주문 상태 |
| --- | --- | --- |
| 인증 단계 실패·사용자 취소 (`AuthResultCode != '0000'`) | 브라우저가 보낸 결과코드 | **변경 없음** — 실패 URL 로 silent redirect (`error` 쿼리 없음) |
| MID 불일치 · 서명 불일치 | 설정 MID · `AuthToken`+`MID`+`Amt` 로 재계산한 서명 | **변경 없음** — `error=mid_mismatch` / `signature_mismatch` |
| 주문 없음 · 결제 전 상태 아님 | 서버 조회 | **변경 없음** — `error=order_not_found` / `order_not_payable` |
| 서버 승인 실패 · 금액 불일치 · 통화 오류 · 예외 (승인 호출 이후) | 나이스페이 승인 응답 | **실패 처리 + net cancel** |

> 형제 플러그인도 같은 규약이다 — `sirsoft-pay_kginicis` · `sirsoft-pay_nhnkcp` · `sirsoft-tosspayments` 의 `docs/api/payment.md` 를 참조.

---

## POST /api/plugins/sirsoft-pay_nicepayments/payment/close-report

- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.payment.close-report`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\PaymentCloseReportController@store`
- **인증/권한**: 주문 소유자만 — 회원 주문은 주문한 회원 본인 로그인(Bearer, `optional.sanctum`), 비회원 주문은 `X-Guest-Order-Token` 헤더(주문 생성 응답의 `guest_order_token`). 주문자 이메일·전화번호는 제3자도 알 수 있어 소유 증명으로 쓰지 않는다 (KVE-2026-2434)

**요청 파라미터** (`PaymentCloseReportRequest`)

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| X-Guest-Order-Token | header | string | 비회원 주문이면 예 | `{만료ts}|{HMAC-SHA256}` | 주문 생성 응답(`guest_order_token`)으로 받은 비회원 조회 토큰. 이 주문의 것이고 만료 전이어야 한다. 회원은 싣지 않고 로그인(Bearer)으로 증명한다. |
| oid | body | string | 예 | max 40 | 결제창을 닫은 대상 주문의 주문번호(MOID). 서버가 이 값으로 주문을 조회해 주문·결제 취소와 결제 취소 이력을 기록한다. |
| price | body | integer | 예 | min 1 | 주문 결제 금액. 저장된 주문 청구액과 일치하는지 검증해 위변조된 닫힘 보고를 차단한다. |
| payment_method | body | string | 아니오 | max 50 | 사용자가 결제창에서 선택했던 결제수단 식별값. 결제 메타에 병합해 어떤 수단에서 창을 닫았는지 남긴다. |
| reason | body | string | 아니오 | max 160 | 결제창 닫힘 사유 문자열. 비어 있으면 기본 문구(「사용자가 나이스페이먼츠 결제창을 닫았습니다.」)로 대체되어 취소 이력에 기록된다. |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_nicepayments/payment/close-report HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json
X-Guest-Order-Token: 1759999999|6f1c9a…(64자 HEX)

{
    "oid": "ORD20260917001",
    "price": 10000,
    "payment_method": "CARD",
    "reason": "사용자가 결제창을 닫음"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 값 | 용도/설명 |
| --- | --- | --- | --- |
| status | string | `recorded` \| `ignored` | 닫힘 보고 처리 결과. `recorded` = 주문·주문 옵션을 취소(`cancelled`)로, 결제를 취소(`payment_status=cancelled`)로 바꾸고 결제 취소 이력(`cancel_history`) 1건을 남김(다른 PG 와 같음). 실패 코드 `USER_CANCEL` 은 주문·결제 메타에 남아 같은 주문번호 재결제 복구(SignData)의 판정 근거가 된다. `ignored` = 결제 성공 콜백과의 경쟁 등으로 처리하지 않고 무시. |
| reason | string | `order_not_payable` \| `payment_already_paid` \| `payment_not_ready` | `status: ignored` 일 때만 포함되는 무시 사유. 주문이 결제 전 상태가 아님 / 결제가 이미 완료됨 / 결제 레코드가 `READY` 가 아님(가상계좌 발급으로 입금 대기 등). |

**응답 예시**

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
| 403 | Forbidden | 주문 소유자 확인 실패 — 비로그인 요청이 회원 주문을 보고함 · 다른 회원이 보고함 · 비회원 조회 토큰이 없거나 만료·위조·다른 주문의 것임 (`errors.message.0` = `Order ownership verification failed.`). 주문자 이메일·전화번호를 맞춰 보내도 통과하지 않는다 |
| 404 | Not Found | `oid` 에 해당하는 주문이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우, 주문 통화가 청구 가능한 통화가 아닌 경우, `price` 가 주문 청구액과 불일치하는 경우 |
| 429 | Too Many Requests | 동일 IP·`oid` 조합에서 분당 20회를 초과해 요청한 경우 |

**설명**

PC 결제창에서 사용자가 결제를 완료하지 않고 창을 닫았을 때 프론트엔드가 이를 서버에 보고하는 엔드포인트다. 주문을 `USER_CANCEL` 사유로 실패 처리하고 취소 이력을 남기며, 결제수단 선택 정보가 있으면 결제 메타에 병합한다.

로그인을 강제하지는 않지만 **이 경로가 주문 상태를 바꾸는 유일한 결제창 측 경로**이므로 방어가 네 겹이다 — FormRequest 검증, `oid` 기준 IP별 분당 20회 레이트리밋, 주문 소유자 확인(회원 본인 로그인 / 비회원 조회 토큰), 주문 청구액과의 금액 대조. 대조는 락 밖에서 한 번, 주문 락을 쥔 뒤 한 번 더 한다.

승인 콜백(`authCallback`)과 같은 주문 락을 공유한다. 카드 주문은 승인 직전까지 주문 상태가 결제 전이라 상태 가드를 통과하므로, 결제가 이미 완료된 경우(`payment_already_paid`)와 결제 레코드가 `READY` 를 벗어난 경우(`payment_not_ready` — 가상계좌 발급 뒤 입금 대기 등)를 별도로 차단해, 뒤늦게 도착한 닫힘 보고가 결제 상태를 덮어쓰지 못하게 한다.

---

## POST /plugins/sirsoft-pay_nicepayments/payment/callback

- **라우트명**: `web.plugins.sirsoft-pay_nicepayments.payment.callback`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\PaymentCallbackController@authCallback`
- **인증/권한**: 공개 · CSRF 검증 제외 (나이스페이 결제창이 브라우저 POST 로 리턴) · **IP 화이트리스트 미적용** (정상 사용자의 임의 IP 에서 도달. 화이트리스트는 `payment.vbank-notify` 에만 걸린다 — [vbank.md](vbank.md))

**요청 파라미터** (`AuthCallbackRequest` — 나이스페이 결제창이 브라우저 POST 로 전달)

| 이름 | 타입 | 필수 | 용도 |
| --- | --- | --- | --- |
| AuthResultCode | string | 예 | 인증 결과코드. `0000` 만 성공. 그 밖의 값(사용자 취소 `0021` 등)은 아래 필수 항목의 검증을 풀고 silent redirect 로 끝난다 |
| AuthResultMsg | string | 아니오 | 인증 결과 메시지 (로그 보존용) |
| Moid | string | 성공 시 예 | 주문번호 |
| Amt | integer | 성공 시 예 | 결제 금액 (min 1). 서명 검증 입력이자 서버 승인 요청 금액 |
| MID | string | 성공 시 예 | 상점 ID. 설정된 MID 와 일치해야 한다 |
| TxTid | string | 성공 시 예 | 거래 ID |
| AuthToken | string | 성공 시 예 | 인증 토큰. `TxTid`·`MID` 와 묶어 60초 재전송 디듀프 키가 된다 |
| Signature | string | 성공 시 예 | `AuthToken`+`MID`+`Amt` 기반 서명. 불일치면 승인 호출 없이 실패 URL |
| NextAppURL | string(url) | 성공 시 예 | 서버-서버 승인 호출 주소 |
| NetCancelURL | string(url) | 성공 시 예 | 승인 이후 실패 시 망취소 호출 주소 |
| PayMethod | string | 성공 시 예 | 결제수단. 승인 응답의 `PayMethod` 가 `VBANK` 면 가상계좌 발급 분기 |
| MallReserved · MallReserved1 | string | 아니오 | 가맹점 예약 필드 (max 1000 · 20) |

**응답**

JSON 이 아니라 **리다이렉트**다. 성공 시 상점 성공 페이지(`redirect_success_url`)로, 실패 시 실패 페이지(`redirect_fail_url`)로 이동하며 실패 사유를 쿼리스트링(`error` · `orderId`)으로 전달한다. 인증 단계 실패는 쿼리 없이 실패 URL 로만 보낸다(사용자 취소를 에러 토스트로 보이지 않게 하려는 규약).

고정 `error` 값: `mid_mismatch` · `signature_mismatch` · `order_not_found` · `order_not_payable` · `authorize_failed` · `amount_mismatch` · `invalid_payment_currency`.

**설명**

나이스페이 결제창이 인증을 마치고 브라우저를 통해 가맹점으로 되돌려 보내는 경로다. 서버는 여기서 MID·서명을 검증한 뒤 `NextAppURL` 로 승인을 요청하고, 성공하면 카드는 주문을 결제 완료로 확정하고 가상계좌는 발급 계좌를 저장해 입금 대기로 전이시킨다.

이 문서 상단 **"주문 상태를 바꾸는 결제창 경로는 close-report 하나뿐이다"** 가 이 엔드포인트의 핵심 계약이다 — 어떤 실패에서도 주문 상태를 바꾸지 않는다 — 승인 호출 전의 실패(인증 결과코드·MID·서명·주문 조회)는 물론, 승인 호출 이후의 실패(승인 거절·금액 불일치·통화 오류·예외)도 `NetCancelURL` 로 잔존 승인을 자동 취소하기만 한다(미승인 상태의 망취소는 safe no-op). 서명이 주문번호를 묶지 않아 승인 단계의 실패도 브라우저가 고른 주문번호로 만들어낼 수 있기 때문이다 (KVE-2026-2434). 승인 성공 시에도 승인 응답의 `Moid`·`Amt` 가 콜백과 다르면 망취소하고 반영하지 않는다.

재전송 방어는 두 겹이다 — 같은 `MID`+`AuthToken`+`TxTid` 가 60초 안에 다시 오면 승인 호출 없이 성공 URL 로 보내고, 승인 이후에는 주문 락 안에서 이미 결제 완료(`already_paid`)·다른 TID 로 결제됨(`paid_with_other_tid`)·가상계좌 이미 발급(`already_waiting`)을 가려낸다. 다른 TID 로 이미 결제된 주문은 현재 승인을 망취소한 뒤 성공 URL 로 보낸다.

이 엔드포인트를 수정할 때는 실패 분기마다 **"이 판정의 근거가 브라우저가 보낸 값인가"** 를 먼저 확인한다. 근거가 브라우저 입력뿐이면 주문 상태를 바꾸지 않는다.
