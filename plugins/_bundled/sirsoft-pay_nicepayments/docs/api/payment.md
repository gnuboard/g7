# Payment(결제창 서명, 닫힘 보고, 리턴 콜백, 입금통보 수신) API 레퍼런스

> **소유**: plugin `sirsoft-pay_nicepayments`. 사람이 서술한 레퍼런스. 결제 흐름이 부르는 네 경로를 서술한다. 결제창(브라우저) 흐름의 서명값 발급(web), 결제창 닫힘 보고 close-report(api), 브라우저 리턴 콜백(web)과, 나이스페이먼츠 서버가 직접 보내는 가상계좌 입금통보 수신(web)이다. 관리자 화면의 입금통보 주소 조회와 입금 완료 건 환불은 [vbank.md](vbank.md) 에 있다.

---

## TL;DR (5초 요약)

```text
1. 결제창 호출 직전 브라우저가 서명값(SignData)을 발급받는 경로가 있다
2. amt(정수), moid(max 64) 형식 위반은 422 (SignDataRequest, 종전 400 에서 422 로 표준화)
3. 주문 실존, 구매자 일치, 나이스페이 결제 건 여부, 청구 금액을 서버가 다시 검증한다 (금액 조작 방지)
4. 서명값 발급의 과도 호출은 429 (같은 IP 와 주문번호 조합으로 60초에 20회, 라우트 요청 제한 분당 30회)
5. 서명값 응답은 봉투 없이 { ediDate, signData, mid } 원시 JSON (결제창 SDK 계약)
6. 주문을 바꾸는 결제창 경로는 close-report 하나뿐이다. 주문 소유자 확인과 금액 대조, 같은 IP 와 주문번호 조합으로 60초에 20회 제한
7. 리턴 콜백은 어떤 실패에서도 주문 상태를 바꾸지 않는다. 서버 승인 이후의 실패는 망취소만 한다
8. 가상계좌 입금통보는 나이스페이먼츠 공식 발신 IP 만 받고, 거래 조회로 입금을 확인한 뒤에만 결제완료로 바꾼다
```

---

## 주문 상태를 바꾸는 결제창 경로는 close-report 하나뿐이다

브라우저 리턴 콜백(`POST /plugins/sirsoft-pay_nicepayments/payment/callback`)의 1단계 입력인 인증 결과코드(`AuthResultCode`), 주문번호(`Moid`), 금액(`Amt`)은 전부 **요청자가 고른 값**이다. 그 입력만 근거로 주문을 실패 처리하면 남의 주문번호를 담은 POST 한 번으로 **타인의 결제대기 주문을 취소시킬 수 있다** (KVE-2026-2018 계열, 형제 플러그인과 같은 규약).

그래서 콜백은 인증 단계 실패(`AuthResultCode != '0000'`)에서는 주문 상태를 건드리지 않고 실패 URL 로 되돌려 보내기만 한다. 주문을 실패로 전이시키는 것은 주문 소유자(회원 본인 로그인 / 비회원 조회 토큰)가 보낸 close-report 요청 하나뿐이다. 서명(`AuthToken+MID+Amt`)은 주문번호를 묶지 않아, 자기 결제창에서 얻은 인증값에 남의 주문번호를 붙여 서버 승인 단계까지 갈 수 있다. 그래서 승인 이후의 실패(승인 거절, 금액 불일치, 예외)도 망취소만 하고 주문은 바꾸지 않으며, 승인에 성공해도 승인 응답의 `Moid`, `Amt` 가 콜백과 같을 때만 반영한다 (KVE-2026-2434). 승인 응답에 금액(`Amt`)이 없거나 숫자가 아니면 불일치로 보고 망취소한다. 결제완료와 가상계좌 발급 기록의 금액 근거는 브라우저가 보낸 콜백 금액이 아니라 승인 응답의 금액이며, 그 금액을 주문 청구액과 다시 대조한다. 가상계좌 발급은 주문의 결제수단이 가상계좌이고 발급 금액이 주문 청구액과 같을 때만 기록하고, 아니면 발급 승인을 망취소한다(`error=amount_mismatch` / `order_not_payable`).

| 시점 | 판정 근거 | 주문 상태 |
| --- | --- | --- |
| 인증 단계 실패, 사용자 취소 (`AuthResultCode != '0000'`) | 브라우저가 보낸 결과코드 | **변경 없음**. 실패 URL 로 조용히 이동 (`error` 쿼리 없음) |
| MID 불일치, 서명 불일치 | 설정 MID, `AuthToken`+`MID`+`Amt` 로 다시 계산한 서명 | **변경 없음**. `error=mid_mismatch` / `signature_mismatch` |
| 주문 없음, 결제 전 상태 아님 | 서버 조회 | **변경 없음**. `error=order_not_found` / `order_not_payable` |
| 서버 승인 실패, 승인 응답 불일치, 금액 불일치, 통화 오류, 예외 (승인 호출 이후) | 나이스페이 승인 응답 | **변경 없음 + 망취소**. `error=authorize_failed` / `amount_mismatch` / `invalid_payment_currency` / `order_not_payable` |

> 형제 플러그인도 같은 규약이다. `sirsoft-pay_kginicis`, `sirsoft-pay_nhnkcp`, `sirsoft-tosspayments` 의 `docs/api/payment.md` 를 참조.

---

### POST /api/plugins/sirsoft-pay_nicepayments/payment/close-report
<!-- @generated:start:api.plugins.sirsoft-pay_nicepayments.payment.close-report -->
- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.payment.close-report`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\PaymentCloseReportController@store`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| oid | body | string | 예 | max 40 | 결제창을 닫은 주문의 주문번호(MOID). 클레임 추가결제 번호(`CP` 채번)면 기록 없이 성공 응답한다 |
| price | body | integer | 예 | min 1 | 주문 결제 금액. 주문 청구액(결제 통화 기준)과 같아야 하며, 다르면 422 |
| payment_method | body | string | 아니오 | max 50 | 사용자가 결제창에서 고른 결제수단 식별값. 형식만 검증하고 처리에는 쓰지 않는다 |
| reason | body | string | 아니오 | max 160 | 결제창 닫힘 사유. 결제 취소 이력의 사유로 남는다. 비어 있으면 기본 문구(「사용자가 나이스페이먼츠 결제창을 닫았습니다.」) |
| X-Guest-Order-Token | header | string | 비회원 주문이면 예 | — | 비회원 주문 조회 토큰(주문 생성 응답의 `guest_order_token`). 회원 주문은 주문한 회원의 Bearer 토큰으로 증명한다 |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_nicepayments/payment/close-report HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
Content-Type: application/json

{
    "oid": "ORD20260917001",
    "price": 10000,
    "payment_method": "CARD",
    "reason": "사용자가 결제창을 닫음"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| status | string | `recorded` | 처리 결과. `recorded` 는 주문을 결제 실패(`USER_CANCEL`)로 처리하고 결제를 취소(`cancelled`)로 바꾸며 결제 취소 이력을 남겼다는 뜻, `ignored` 는 처리하지 않고 무시했다는 뜻 |
| reason | string | `payment_already_paid` | `status` 가 `ignored` 일 때만 실린다. `order_not_payable`(주문이 결제 전 상태가 아님), `payment_already_paid`(결제가 이미 완료됨), `payment_not_ready`(결제 레코드가 없거나 `ready` 가 아님, 가상계좌 발급 뒤 입금 대기 등) |
| recorded | boolean | `false` | 클레임 추가결제 번호(`CP` 채번)를 보냈을 때만 실린다. 언제나 `false` 이고 이때 `reason` 은 `claim_payment`, `status` 는 없다 |

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
| 403 | Forbidden | 주문 소유자 확인 실패. 비로그인 요청이 회원 주문을 보고했거나, 다른 회원이 보고했거나, 비회원 조회 토큰이 없거나 맞지 않는 경우 (`errors.message.0`: `Order ownership verification failed.`) |
| 404 | Not Found | `oid` 에 해당하는 주문이 없는 경우 (`errors.message.0`: `Order not found.`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 422 | Unprocessable Entity | 주문 통화가 청구할 수 없는 통화인 경우 (`Payment currency is not chargeable.`), `price` 가 주문 청구액과 다른 경우 (`Payment amount does not match the order amount.`) |
| 429 | Too Many Requests | 같은 IP 와 `oid` 조합으로 60초에 20회를 넘긴 경우 |

<!-- @generated:end -->

**설명**

PC 결제창을 사용자가 결제를 마치지 않고 닫았을 때 결제 화면이 서버에 알리는 경로다. 결제창 쪽에서 주문 상태를 바꾸는 유일한 경로라서(위 「주문 상태를 바꾸는 결제창 경로는 close-report 하나뿐이다」) 로그인을 강제하지는 않지만 방어가 네 겹이다. 요청 검증(`PaymentCloseReportRequest`), 같은 IP 와 `oid` 조합의 60초 20회 제한, 주문 소유자 확인, 주문 청구액과의 금액 대조다. 금액 대조는 주문 잠금 밖에서 한 번, 주문 잠금을 쥔 뒤 한 번 더 한다.

주문 소유자 확인은 회원 주문이면 주문한 회원 본인의 로그인(Bearer, `optional.sanctum`), 비회원 주문이면 `X-Guest-Order-Token` 헤더(주문 생성 응답의 `guest_order_token`, `{만료ts}|{HMAC-SHA256}` 형식)다. 토큰은 이 주문의 것이고 만료 전이어야 한다. 주문자 이메일이나 전화번호는 제3자도 알 수 있어 소유 증명으로 쓰지 않으며, 맞춰 보내도 통과하지 않는다 (KVE-2026-2434). 비회원 요청 예시는 다음과 같다.

```http
POST /api/plugins/sirsoft-pay_nicepayments/payment/close-report HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json
X-Guest-Order-Token: 1759999999|6f1c9a…(64자 HEX)

{
    "oid": "ORD20260917001",
    "price": 10000,
    "reason": "사용자가 결제창을 닫음"
}
```

`status: recorded` 는 주문과 주문 옵션을 취소(`cancelled`)로, 결제를 취소(`payment_status=cancelled`)로 바꾸고 결제 취소 이력(`cancel_history`) 1건을 남겼다는 뜻이다(다른 PG 와 같다). 취소 이력의 사유는 요청 `reason`, 비어 있으면 기본 문구다. 실패 코드 `USER_CANCEL` 은 주문 메타와 결제 메타에 남아 같은 주문번호 재결제 복구(SignData)의 판정 근거가 된다. `payment_method` 는 형식만 검증하고 저장하거나 처리에 쓰지 않는다.

`oid` 가 클레임 추가결제 번호(`CP` 채번)면 기록할 주문 결제 이력이 없으므로 아무것도 바꾸지 않고 `{"recorded": false, "reason": "claim_payment"}` 로 성공 응답한다. 결제 건은 결제 대기로 남아 신청서 상세에서 다시 시도한다. 요청 제한 판정은 이 분기보다 먼저 하므로 `CP` 번호 보고도 같은 제한을 받는다.

승인 콜백(`authCallback`)과 같은 주문 행 잠금을 쓴다. 카드 주문은 승인 직전까지 주문 상태가 결제 전이라 상태 가드를 통과하므로, 결제가 이미 완료된 경우(`payment_already_paid`)와 결제 레코드가 `ready` 를 벗어난 경우(`payment_not_ready`, 가상계좌 발급 뒤 입금 대기 등)를 따로 막아 뒤늦게 도착한 닫힘 보고가 결제 상태를 덮어쓰지 못하게 한다. 이때는 `status: ignored` 로 성공 응답한다.

에러 응답의 `message` 는 `common.failed` 번역문이고, 사유 문장은 `errors.message` 배열 첫 항목에 영어로 실린다. 429 의 사유는 `Too many NicePayments payment close reports. Please try again later.` 다.

### POST /plugins/sirsoft-pay_nicepayments/payment/callback
<!-- @generated:start:web.plugins.sirsoft-pay_nicepayments.payment.callback -->
- **라우트명**: `web.plugins.sirsoft-pay_nicepayments.payment.callback`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\PaymentCallbackController@authCallback`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| AuthResultCode | body | string | 예 | — | 결제창 인증 결과코드. `0000` 만 성공이다. 그 밖의 값(사용자 취소 등)이면 아래의 성공 시 필수 항목 검증을 풀고, 주문을 바꾸지 않은 채 실패 화면으로 보낸다 |
| AuthResultMsg | body | string | 아니오 | — | 인증 결과 메시지. 로그에만 남긴다 |
| NextAppURL | body | string | 예 | URL | 서버가 승인을 요청할 나이스페이먼츠 주소. 나이스페이먼츠 공식 도메인이 아니면 승인하지 않는다 (인증 성공일 때만 필수) |
| TxTid | body | string | 예 | — | 결제창이 발급한 임시 거래번호. 승인 요청과 망취소에 쓴다 (인증 성공일 때만 필수) |
| AuthToken | body | string | 예 | — | 결제창 인증 토큰. 서명 검증 입력이며 `MID`, `TxTid` 와 묶어 60초 재전송 판정 키가 된다 (인증 성공일 때만 필수) |
| PayMethod | body | string | 예 | — | 결제수단 (인증 성공일 때만 필수). 승인 응답의 결제수단이 `VBANK` 면 가상계좌 발급으로 처리한다 |
| MID | body | string | 예 | — | 상점 아이디. 설정된 MID 와 같아야 한다 (인증 성공일 때만 필수) |
| Moid | body | string | 예 | — | 주문번호. 클레임 추가결제 번호(`CP` 채번)면 클레임 결제로 처리한다 (인증 성공일 때만 필수) |
| Amt | body | integer | 예 | min 1 | 결제 금액. 서명 검증 입력이자 승인 요청 금액이다 (인증 성공일 때만 필수) |
| NetCancelURL | body | string | 예 | URL | 승인 뒤 실패 시 승인을 되돌리는 망취소 주소 (인증 성공일 때만 필수) |
| Signature | body | string | 예 | — | `AuthToken`, `MID`, `Amt` 와 가맹점 키로 만든 서명. 다르면 승인하지 않는다 (인증 성공일 때만 필수) |
| MallReserved | body | string | 아니오 | max 1000 | 가맹점 예약 필드. 간편결제 수단 식별에 쓴다 |
| MallReserved1 | body | string | 아니오 | max 20 | 가맹점 예약 필드. 결제 화면이 고른 간편결제 수단 코드(`nicepay_kakaopay` 등)를 싣는다 |

**요청 예시**

```http
POST /plugins/sirsoft-pay_nicepayments/payment/callback HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "AuthResultCode": "0000",
    "AuthResultMsg": "인증 성공",
    "NextAppURL": "https://pay.nicepay.co.kr/v1/authorize",
    "TxTid": "nictest00m01011708171043226571",
    "AuthToken": "{YOUR_TOKEN}",
    "PayMethod": "CARD",
    "MID": "nictest00m",
    "Moid": "20260817-1043226571",
    "Amt": 30000,
    "NetCancelURL": "https://pay.nicepay.co.kr/v1/netcancel",
    "Signature": "…SHA-256 서명…",
    "MallReserved": "",
    "MallReserved1": ""
}
```

나이스페이먼츠 결제창이 브라우저 폼 전송(`application/x-www-form-urlencoded`)으로 보낸다. 위 JSON 은 필드를 보이기 위한 표기다.

**응답 필드** (`data` 내부)

JSON 응답이 없다. 언제나 302 리다이렉트로 끝난다.

| 항목 | 값 | 설명 |
| --- | --- | --- |
| 성공 | `Location: {성공 화면}` | 플러그인 설정 `redirect_success_url`(기본 `{shopBase}/orders/{orderId}/complete`) |
| 실패 | `Location: {실패 화면}?error=…&orderId=…` | 플러그인 설정 `redirect_fail_url`(기본 `{shopBase}/checkout`). `error` 값은 `mid_mismatch`, `signature_mismatch`, `order_not_found`, `order_not_payable`, `authorize_failed`, `amount_mismatch`, `invalid_payment_currency`, `claim_payment_not_found`(클레임 추가결제 건을 찾지 못함). 인증 단계 실패와 사용자 취소는 쿼리 없이 보낸다 |
| 클레임 결제 | `Location: {신청서 상세}?claim_payment=…` | 클레임 추가결제 번호(`CP` 채번)면 주문 화면이 아니라 신청서 상세로 보낸다 |

상대 경로 설정값은 사용자 화면 주소(환경설정 「사용자 화면 URL」, 비어 있으면 사이트 주소) 기준 절대 URL 로 바뀐다.

**응답 예시**

```http
HTTP/1.1 302
Location: https://example.com/shop/orders/20260817-1043226571/complete
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 302 | Found | 요청 파라미터가 검증 규칙을 위반한 경우. 422 JSON 이 아니라 실패 화면으로 `error=invalid_params` 를 붙여 이동한다 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

나이스페이 결제창이 인증을 마치고 브라우저를 통해 가맹점으로 되돌려 보내는 경로다. 공개 경로이고 CSRF 검증을 뺀다. **발신 IP 확인을 적용하지 않는다.** 정상 사용자의 브라우저가 임의 IP 에서 도달하기 때문이다. IP 확인은 아래 `payment/vbank-notify` 에만 걸린다.

서버는 MID 와 서명을 검증한 뒤 `NextAppURL` 로 승인을 요청한다. `NextAppURL` 이 나이스페이먼츠 공식 도메인이 아니면 승인을 부르지 않고 예외로 끝나 `authorize_failed` 로 이동한다. 승인 결과코드가 성공 코드(`3001`, `4000`, `4100`, `A000`, `7001`)이고 승인 응답이 콜백 주문과 맞으면, 카드 등은 주문을 결제완료로 확정하고 가상계좌(승인 응답 `PayMethod=VBANK`)는 발급 계좌를 저장해 입금 대기로 전이시킨다.

위 「주문 상태를 바꾸는 결제창 경로는 close-report 하나뿐이다」가 이 경로의 핵심 계약이다. 어떤 실패에서도 주문 상태를 바꾸지 않는다. 승인 호출 전의 실패(인증 결과코드, MID, 서명, 주문 조회)는 물론, 승인 호출 이후의 실패(승인 거절, 금액 불일치, 통화 오류, 예외)도 `NetCancelURL` 로 남은 승인을 자동 취소하기만 한다(미승인 상태의 망취소는 아무 일도 하지 않는다). 서명이 주문번호를 묶지 않아 승인 단계의 실패도 브라우저가 고른 주문번호로 만들어 낼 수 있기 때문이다 (KVE-2026-2434). 승인에 성공해도 승인 응답의 `Moid`, `Amt` 가 콜백과 다르면 망취소하고 반영하지 않는다.

인증 단계 실패는 쿼리 없이 실패 URL 로만 보낸다. 사용자 취소(모바일 결제창 종료 `0021` 등)를 오류 안내로 보이지 않게 하려는 규약이다. 클레임 추가결제 번호(`CP` 채번)의 인증 단계 실패는 신청서 상세로 돌려보낸다.

재전송 방어는 두 겹이다. 같은 `MID`+`AuthToken`+`TxTid` 가 60초 안에 다시 오면 승인 호출 없이 성공 URL 로 보낸다. 승인 이후에는 주문 잠금 안에서 이미 결제 완료(`already_paid`), 다른 TID 로 결제됨(`paid_with_other_tid`), 가상계좌 이미 발급(`already_waiting`)을 가려낸다. 다른 TID 로 이미 결제된 주문은 현재 승인을 망취소한 뒤 성공 URL 로 보낸다. 주문 성공 URL 로 보낼 때는 영수증 조회용 5분짜리 쿠키(`nicepayments_receipt_token`)를 함께 발급한다([orders.md](orders.md) 의 `user/orders/{orderNumber}/receipt` 가 비회원 확인에 쓴다).

이 경로를 고칠 때는 실패 분기마다 **「이 판정의 근거가 브라우저가 보낸 값인가」**를 먼저 확인한다. 근거가 브라우저 입력뿐이면 주문 상태를 바꾸지 않는다.

### POST /plugins/sirsoft-pay_nicepayments/payment/sign-data
<!-- @generated:start:web.plugins.sirsoft-pay_nicepayments.payment.sign-data -->
- **라우트명**: `web.plugins.sirsoft-pay_nicepayments.payment.sign-data`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\PaymentCallbackController@signData`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| amt | body | integer | 예 | 1 ~ 999999999999 | 결제 금액. 서버가 주문 금액과 대조하므로 임의 값은 서명 발급이 거절된다 |
| moid | body | string | 예 | max 64 | 주문번호(MOID) |

**요청 예시**

```http
POST /plugins/sirsoft-pay_nicepayments/payment/sign-data HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
Content-Type: application/json

{
    "amt": 30000,
    "moid": "20260817-1043226571"
}
```

**응답 필드** (`data` 내부)

코어 응답 봉투(`success`, `message`, `data`)를 쓰지 않는다. 나이스페이먼츠 결제창 스크립트가 바로 읽는 원시 JSON 이다.

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| ediDate | string | `20260817143000` | 서명 시각(`YYYYMMDDHHmmss`, 숫자 14자리). 결제창 `EdiDate` 로 넘긴다 |
| signData | string | `…SHA-256 서명…` | `EdiDate`, `MID`, `Amt`, 가맹점 키로 만든 64자 hex 서명. 결제창 `SignData` 로 넘긴다 |
| mid | string | `nictest00m` | 현재 설정(테스트 모드 여부에 따른)의 상점 아이디 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "ediDate": "20260817143000",
    "signData": "…SHA-256 서명…",
    "mid": "nictest00m"
}
```

**에러 응답**

실패 응답도 봉투 없이 `{"error": "…"}` 하나다.

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 구매자 대조 실패(다른 사람의 주문번호로 서명 요청), 또는 재결제 복구가 필요한 주문인데 주문 소유자(회원 본인 로그인, 비회원 `X-Guest-Order-Token`)가 아닌 경우 (`error`: 잘못된 요청입니다.) |
| 422 | Unprocessable Entity | `amt`, `moid` 형식 위반 (코어 검증 오류 응답, `errors` 에 필드별 메시지). JSON 을 요구하지 않은 요청도 이동하지 않고 422 |
| 422 | Unprocessable Entity | 주문이 없음 (`error`: 주문을 찾을 수 없습니다.), 결제가 나이스페이먼츠 결제가 아님, 주문 통화가 청구할 수 없음, 재결제 복구 불가, 상점 아이디(MID) 미설정 (`error`: 잘못된 요청입니다.), `amt` 가 주문 청구액과 다름 (`error`: 요청 금액이 유효하지 않습니다.) |
| 429 | Too Many Requests | 같은 IP 와 `moid` 조합으로 60초에 20회를 넘긴 경우 (`error`: 잘못된 요청입니다.), 또는 라우트 요청 제한(`throttle:30,1`)을 넘긴 경우 |

<!-- @generated:end -->

**설명**

결제창을 열기 직전 결제 화면이 부른다. 비회원 결제도 이 경로를 쓰므로 로그인을 강제하지 않는다(`optional.sanctum`). 서명 발급 자체는 주문 상태를 바꾸지 않아 구매자 대조(요청의 `buyer_email`, `buyer_phone` 과 주문자 연락처)로 판정한다. 다만 결제창 닫힘이나 결제 실패로 취소된 주문을 결제대기로 되살리는 재결제 복구는 주문 상태 변경이라 주문 소유자(회원 본인 로그인 / 비회원 `X-Guest-Order-Token`)만 할 수 있다 (KVE-2026-2434).

응답은 코어 봉투를 쓰지 않는다. 나이스페이 결제창 SDK 가 바로 읽는 원시 JSON 계약이며 클라이언트는 `response.ok` 만 검사한다. 형식 검증 실패는 종전 400 응답을 422 로 표준화한 것이고, JSON 을 요구하지 않은 요청도 422 로 끝나며 직전 페이지나 요청 Host 로 이동하지 않는다.

`moid` 가 클레임 추가결제 번호(`CP` 채번)면 주문 대신 그 결제 건으로 검증한다. 결제 건이 없으면 422(`error`: 주문을 찾을 수 없습니다.), 부모 주문의 구매자 대조 실패는 403, 결제 대기 상태가 아니거나 이 플러그인 결제가 아니거나 금액이 결제 건 청구액과 다르면 422(`error`: 잘못된 요청입니다.)다.

### POST /plugins/sirsoft-pay_nicepayments/payment/vbank-notify
<!-- @generated:start:web.plugins.sirsoft-pay_nicepayments.payment.vbank-notify -->
- **라우트명**: `web.plugins.sirsoft-pay_nicepayments.payment.vbank-notify`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\PaymentCallbackController@vbankNotify`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| TID | body | string | 예 | max 30 | 거래번호. 가상계좌 발급 때 저장한 거래번호와 같아야 입금완료로 반영한다 |
| MOID | body | string | 예 | max 64 | 주문번호. 이 값으로 주문을 찾는다 (`Moid` 로 와도 받는다) |
| Amt | body | integer | 예 | min 1 | 통보 금액. 주문 청구액과 같아야 입금완료로 반영한다 |
| ResultCode | body | string | 예 | max 4 | 통보 결과코드. `4110`(입금완료)만 결제완료로 처리하고, 그 밖의 코드(계좌발급, 입금취소 등)는 이력으로만 남긴다 |
| PG | body | string | 아니오 | max 10 | 결제대행사 구분 (나이스페이먼츠 통보 규격 필드). 허용 키만 남긴 통보 원문(`raw`)에 남는다 |
| PayMethod | body | string | 아니오 | max 10 | 결제수단 (통보 규격 필드). 통보 원문에 남는다 |
| MID | body | string | 아니오 | max 10 | 상점 아이디. 검증 규칙상 비워도 되지만, 입금완료 반영에는 발급 당시 MID(없으면 현재 설정 MID)와 같은 값이 있어야 한다 |
| MallUserID | body | string | 아니오 | max 20 | 가맹점 회원 아이디 (통보 규격 필드). 저장하지 않는다 |
| AuthDate | body | string | 아니오 | max 14 | 입금(승인) 일시. 결제 메타와 통보 이력에 남긴다 |
| AuthCode | body | string | 아니오 | max 30 | 승인번호. 결제 메타와 통보 이력에 남긴다 |
| StateCd | body | string | 아니오 | max 1 | 상태 코드. `1`, `2` 이면 입금취소 통보로 분류한다 |
| ResultMsg | body | string | 아니오 | max 200 | 통보 결과 메시지. 통보 이력에 남긴다 (EUC-KR 이면 UTF-8 로 바꿔 받는다) |
| GoodsName | body | string | 아니오 | max 80 | 상품명 (통보 규격 필드). 저장하지 않는다 |
| name | body | string | 아니오 | max 60 | 구매자명 (통보 규격 필드). 저장하지 않는다 |
| BuyerEmail | body | string | 아니오 | max 60 | 구매자 이메일 (통보 규격 필드). 저장하지 않는다 |
| VbankName | body | string | 아니오 | max 40 | 가상계좌 은행명. 결제 메타와 통보 이력에 남긴다 |
| VbankNum | body | string | 아니오 | max 30 | 가상계좌 번호. 발급 때 저장한 계좌번호와 같아야 입금완료로 반영한다 |
| VbankInputName | body | string | 아니오 | max 40 | 입금자명. 결제 메타와 통보 이력(`depositor`)에 남긴다 |
| FnCd | body | string | 아니오 | max 4 | 은행 코드. 결제 메타에 남긴다 |
| FnName | body | string | 아니오 | max 40 | 은행명. 결제 메타에 남긴다 |
| CancelDate | body | string | 아니오 | max 14 | 입금취소 일시. 값이 있으면 입금취소 통보로 분류한다 |
| CancelMOID | body | string | 아니오 | max 64 | 취소 주문번호 (통보 규격 필드). 통보 원문에 남는다 |
| MallReserved | body | string | 아니오 | max 1000 | 가맹점 예약 필드. 통보 원문에 남는다 |
| MallReserved1 | body | string | 아니오 | max 20 | 가맹점 예약 필드. 통보 원문에 남는다 |
| TransType | body | string | 아니오 | max 1 | 거래 유형 (통보 규격 필드). 통보 원문에 남는다 |
| CartCnt | body | string | 아니오 | max 10 | 장바구니 상품 수 (통보 규격 필드). 통보 원문에 남는다 |
| Moid | body | string | 아니오 | — | `MOID` 의 호환 표기. `MOID` 가 없으면 이 값을 `MOID` 로 쓴다 |
| Signature | body | string | 아니오 | — | 통보 규격에 없는 필드. 일부 환경에서 실려 와도 막지 않으려고 받으며 검증에 쓰지 않는다 |

**요청 예시**

```http
POST /plugins/sirsoft-pay_nicepayments/payment/vbank-notify HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "TID": "nictest00m02012104191651325596",
    "MOID": "20260817-1043226571",
    "Amt": 30000,
    "ResultCode": "4110",
    "PG": "nicepay",
    "PayMethod": "VBANK",
    "MID": "nictest00m",
    "MallUserID": "",
    "AuthDate": "260817104322",
    "AuthCode": "AUTH1",
    "StateCd": "0",
    "ResultMsg": "입금완료",
    "GoodsName": "예시 상품",
    "name": "홍길동",
    "BuyerEmail": "user@example.com",
    "VbankName": "국민은행",
    "VbankNum": "1234567890",
    "VbankInputName": "홍길동",
    "FnCd": "004",
    "FnName": "국민은행",
    "CancelDate": "",
    "CancelMOID": "",
    "MallReserved": "",
    "MallReserved1": "",
    "TransType": "0",
    "CartCnt": "1"
}
```

나이스페이먼츠 서버가 폼 전송으로 보낸다. 위 JSON 은 필드를 보이기 위한 표기다.

**응답 필드** (`data` 내부)

JSON 응답이 아니다. 형식 검증을 통과한 통보에는 언제나 HTTP 200 `text/plain` 본문 하나로 답한다.

| 본문 | 뜻 |
| --- | --- |
| `OK` | 통보를 받아 처리를 끝냈다. 입금완료 반영, 같은 거래번호로 이미 반영된 재통보, 입금완료가 아닌 통보의 이력 기록, 취소된 주문의 입금이 거래 조회로 확인돼 기록한 경우 |
| `FAIL` | 처리하지 않았다. 주문 없음, 결제 레코드 없음, 발급 정보(거래번호, MID, 계좌번호)나 금액이 다름, 거래 조회로 입금이 확인되지 않음, 처리 중 예외. 나이스페이먼츠가 통보를 다시 보낸다 |

**응답 예시**

```http
HTTP/1.1 200
Content-Type: text/plain

OK
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 발신 IP 가 나이스페이먼츠 공식 발신 IP 가 아닌 경우 (로컬, 시험 환경 제외) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지). 이전 페이지나 요청 Host 로 이동하지 않는다 |

<!-- @generated:end -->

**설명**

나이스페이먼츠 서버가 구매자의 가상계좌 실입금을 가맹점에 알리려고 직접 POST 로 호출하는 서버 대 서버 경로다. 브라우저를 거치지 않으므로, 위변조와 재처리 요청을 막기 위해 **나이스페이먼츠 공식 발신 IP 만 허용**한다. 관리자 설정 화면에 보여 줄 이 경로의 전체 주소는 [vbank.md](vbank.md) 의 `admin/vbank-notify-url` 이 돌려준다.

발신 IP 검사는 코어의 확장 미들웨어 self-gate(`Plugin::getMiddleware()` 의 `targets` 로 이 경로의 라우트명 `web.plugins.sirsoft-pay_nicepayments.payment.vbank-notify` 에만 정밀 타게팅)로 한다. 브라우저가 접속하는 결제 콜백(`payment.callback`)에는 적용되지 않는다. 콜백은 정상 사용자의 브라우저에서 임의 IP 로 도달하므로 IP 로 제한하면 결제가 끊긴다. 검사 자체의 동작과 허용 범위는 라우트 파일에서 직접 붙이던 이전 방식과 같다. 상세: [docs/backend/middleware.md "확장 미들웨어 선언 (self-gate)"](../../../../../docs/backend/middleware.md).

**형식이 틀린 통보**: 필수 값이 없거나 형식이 다른 요청은 `422` JSON(`{success: false, message, errors}`)으로 끝난다. 직전 페이지(Referer)나 요청 Host 로 이동(302)하지 않는다. PG 에는 302 와 같은 실패라 재전송 동작은 바뀌지 않는다.

**입금 확인**

나이스페이먼츠 입금통보 규격에는 위변조를 확인할 서명이 없다. 그래서 입금완료 통보(`ResultCode=4110`)는 다음을 모두 통과해야 주문을 결제완료로 바꾼다. 하나라도 어긋나면 주문은 그대로 두고 `FAIL` 을 응답한다(나이스페이먼츠가 통보를 다시 보낸다).

1. 주문의 결제가 가상계좌 결제이고 입금 대기(`waiting_deposit`) 상태다. 통보의 거래번호, MID, 가상계좌 번호가 발급 때 기록한 값과 같고, 통보 금액이 주문 청구액과 같다.
2. 가맹점 키로 서명한 거래 조회(`trans_status.jsp`)를 계좌 발급 당시의 MID 와 모드로 보내, 그 거래번호가 승인(입금 완료, `Status=0`)으로 조회된다. 조회 실패, 거래 없음, 취소, 다른 거래번호는 거절한다. 조회 응답이 금액을 실어 오면 그 금액도 통보 금액과 같아야 한다.
3. 같은 거래번호로 이미 반영된 재통보는 거래 조회 없이 `OK` 로 응답한다(멱등).

이미 취소된 주문에 발급 거래번호와 같은 입금통보가 오고 거래 조회로 입금이 확인되면, 주문은 되살리지 않고 취소 후 입금으로 기록한 뒤 `OK` 를 응답한다. 입금완료가 아닌 통보(계좌발급, 입금취소 등)는 결제 메타의 통보 이력(`vbank_notifications`)에만 쌓고 `OK` 를 응답한다. 관리자 주문 상세는 이 이력을 [orders.md](orders.md) 의 `admin/orders/{orderNumber}/vbank-notifications` 로 읽는다.
