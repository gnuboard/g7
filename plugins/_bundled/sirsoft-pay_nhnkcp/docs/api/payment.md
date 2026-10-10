# Payment API 레퍼런스

> **소유**: 플러그인 `sirsoft-pay_nhnkcp`. 이 문서는 결제창과 주고받는 세 경로 — 브라우저 리턴 콜백, 결제창 닫힘 보고, 결제 재시도 준비 — 를 서술한다. NHN KCP 서버가 직접 보내는 통보(가상계좌·에스크로) 경로는 [vbank.md](vbank.md) 가 소유한다.

---

## TL;DR (5초 요약)

```text
1. 브라우저 리턴 콜백은 주문 상태를 바꾸지 않는다 — 승인 전 실패는 실패 URL 로 되돌려 보내기만 한다
2. 주문을 실패로 전이시키는 결제창 경로는 close-report 하나뿐이다 (주문 소유자·금액 확인을 통과한 요청)
3. 승인(tno) 이후에 터진 실패만 콜백이 주문에 반영한다
4. close-report / retry 는 주문 소유자(회원 로그인 / 비회원 조회 토큰)만 — 금액 대조 + IP·주문번호별 분당 20회 제한
5. 결제 성공 콜백과의 경쟁은 주문 락 + `status: ignored` 로 차단한다
```

---

## 주문 상태를 바꾸는 경로는 하나뿐이다

이 플러그인에서 **주문을 결제 실패로 전이시키는 결제창 경로는 `POST /api/plugins/sirsoft-pay_nhnkcp/payment/close-report` 하나**다.

브라우저 리턴 콜백(`POST /plugins/sirsoft-pay_nhnkcp/payment/callback`)은 PG 서명도 발신 IP 증명도 없고, 주문번호(`ordr_idxx`)·금액(`good_mny`)·결과코드(`res_cd`)가 전부 **요청자가 고른 값**이다. 그 입력을 근거로 주문을 실패 처리하면, 남의 주문번호와 임의 금액·위조 결과코드를 담은 POST 한 번으로 **타인의 결제대기 주문을 취소시킬 수 있다** (KVE-2026-2018).

그래서 콜백은 승인이 성립하기 전의 실패에서는 주문 상태를 건드리지 않고 실패 URL 로 되돌려 보내기만 한다.

| 시점 | 판정 근거 | 주문 상태 |
| --- | --- | --- |
| 금액 불일치 (승인 전) | 브라우저가 보낸 `good_mny` | **변경 없음** — 실패 URL 리다이렉트 |
| 승인 실패 (`res_cd` 비정상) | 브라우저가 보낸 결과코드 | **변경 없음** — 실패 URL 리다이렉트 |
| 승인 이후의 예외 (승인 금액 불일치·금액 누락·후속 처리 실패) | KCP 승인 응답의 `tno` 존재 | **실패 처리 + 자동 취소** |

### 승인 금액은 서버-서버 응답으로만 확정한다

결제완료와 가상계좌 발급 기록의 금액 근거는 브라우저 값이 아니라 KCP 서버 승인이다.

1. 승인(발급) 요청에 주문 저장값에서 산정한 청구액을 `ordr_mony` 로 싣는다. KCP 는 결제창에서 인증된 금액과 다르면 승인을 거절한다.
2. 승인 응답의 `amount` 가 청구액과 정확히 같을 때만 결제완료로 확정한다. 다르거나 비어 있거나 숫자가 아니면 승인분을 취소하고 `amount_mismatch` 로 되돌린다.
3. 거래번호는 승인 응답의 `tno` 만 쓴다. 승인 성공 응답에 `tno` 가 없으면 확정하지 않는다.
4. 브라우저가 보낸 `good_mny` 는 승인 **전** 거절 판정에만 쓰고, 승인·기록 금액의 근거나 대체값으로 쓰지 않는다.

PC 가상계좌 발급도 같다 — 발급 응답의 `amount` 가 청구액과 다르거나 비어 있으면 발급된 계좌를 취소하고 계좌 정보를 기록하지 않는다. 모바일 가상계좌 콜백은 서버-서버 응답이 없어 발급 시점에 금액을 대조할 출처가 없다. 금액은 승인키 발급(소유자 인증 + 청구액 대조) 때 KCP 에 등록되고, 결제완료 전이는 입금통보가 통보 금액을 청구액과 대조한 뒤에만 일어난다. 클레임 추가결제 승인(`ClaimPaymentApproveListener`)도 같은 규칙이다.

승인 여부는 `PaymentCallbackController::hasApproval()` 이 `tno`(승인 확정 시에만 채워지는 거래번호) 로 판정한다. `tno` 가 비어 있으면 승인 전에 흐름이 끊긴 것이므로 그 시점까지의 입력은 주문 상태 변경의 근거가 될 수 없다.

정당한 결제창 닫힘은 주문 소유자(회원 본인 로그인 / 비회원 조회 토큰)가 보낸 close-report 요청으로만 기록된다. 주문자 이메일·전화번호는 제3자도 알 수 있어 소유 증명이 아니다 (KVE-2026-2434).

> 형제 플러그인도 같은 규약이다 — `sirsoft-pay_kginicis` · `sirsoft-tosspayments` 의 `docs/api/payment.md` 를 참조.

---

## POST /api/plugins/sirsoft-pay_nhnkcp/payment/close-report

- **라우트명**: `api.plugins.sirsoft-pay_nhnkcp.payment.close-report`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\PaymentCloseReportController@store`
- **인증/권한**: 주문 소유자만 — 회원 주문은 주문한 회원 본인 로그인(Bearer, `optional.sanctum`), 비회원 주문은 `X-Guest-Order-Token` 헤더(주문 생성 응답의 `guest_order_token`). 주문자 이메일·전화번호는 제3자도 알 수 있어 소유 증명으로 쓰지 않는다 (KVE-2026-2434)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| X-Guest-Order-Token | header | string | 비회원 주문이면 예 | `{만료ts}|{HMAC-SHA256}` | 주문 생성 응답(`guest_order_token`)으로 받은 비회원 조회 토큰. 이 주문의 것이고 만료 전이어야 한다. 회원은 싣지 않고 로그인(Bearer)으로 증명한다. |
| oid | body | string | 예 | max 40 | 결제창을 닫은 대상 주문의 주문번호. 서버가 이 값으로 주문을 조회해 결제 실패/취소 이력을 기록한다. |
| price | body | integer | 예 | min 1 | 주문 결제 금액. 저장된 주문 청구액과 일치하는지 검증해 위변조된 닫힘 보고를 차단한다. |
| payment_method | body | string | 아니오 | max 50 | 사용자가 결제창에서 선택했던 간편결제 등 결제수단 식별값. 결제 메타에 병합해 어떤 수단에서 창을 닫았는지 남긴다. |
| reason | body | string | 아니오 | max 160 | 결제창 닫힘 사유 문자열. 비어 있으면 기본 문구로 대체되어 취소 이력에 기록된다. |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_nhnkcp/payment/close-report HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json
X-Guest-Order-Token: 1759999999|6f1c9a…(64자 HEX)

{
    "oid": "ORD20260902001",
    "price": 10000,
    "payment_method": "kakaopay",
    "reason": "사용자가 결제창을 닫음"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 값 | 용도/설명 |
| --- | --- | --- | --- |
| status | string | `recorded` \| `ignored` | 닫힘 보고 처리 결과. `recorded` = 주문을 `USER_CANCEL` 로 실패 처리하고 취소 이력을 남김. `ignored` = 결제 성공 콜백과의 경쟁 등으로 처리하지 않고 무시. |
| reason | string | `order_not_payable` \| `payment_already_paid` \| `callback_in_progress` | `status: ignored` 일 때만 포함되는 무시 사유. 주문이 이미 결제 가능 상태가 아님 / 결제가 이미 완료됨 / 같은 주문의 승인 콜백이 락을 쥐고 처리 중임. |

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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우, 주문 통화가 청구 가능한 통화가 아닌 경우, 금액이 주문 청구액과 불일치하는 경우 |
| 429 | Too Many Requests | 동일 IP·`oid` 조합에서 분당 20회를 초과해 요청한 경우 |

**설명**

PC 표준결제창에서 사용자가 결제를 완료하지 않고 창을 닫았을 때 프론트엔드가 이를 서버에 보고하는 엔드포인트다. `OrderProcessingService::failPayment()` 로 주문을 `USER_CANCEL` 사유로 실패 처리하고 취소 이력을 남기며, 간편결제 선택 정보가 있으면 결제 메타에 병합한다.

로그인을 강제하지는 않지만 **이 경로가 주문 상태를 바꾸는 유일한 결제창 경로**이므로 방어가 네 겹이다 — FormRequest 검증, `oid` 기준 IP별 분당 20회 레이트리밋, 주문 소유자 확인(회원 본인 로그인 / 비회원 조회 토큰), 주문 청구액과의 금액 대조.

승인 콜백(`authCallback`)과 같은 주문 락을 공유한다. 카드 주문은 승인 직전까지 주문 상태가 결제 전이라 상태 가드를 통과하므로, 결제가 이미 완료된 경우(`payment_already_paid`)를 별도로 차단해 결제 성공과 닫힘 보고가 경쟁할 때 옵션 상태가 어긋나는 것을 막는다. 락 획득에 실패하면 `callback_in_progress` 로 무시한다.

---

## POST /api/plugins/sirsoft-pay_nhnkcp/payment/retry

- **라우트명**: `api.plugins.sirsoft-pay_nhnkcp.payment.retry`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\PaymentRetryController@store`
- **인증/권한**: 주문 소유자만 — 회원 주문은 주문한 회원 본인 로그인(Bearer, `optional.sanctum`), 비회원 주문은 `X-Guest-Order-Token` 헤더(주문 생성 응답의 `guest_order_token`). 주문자 이메일·전화번호는 제3자도 알 수 있어 소유 증명으로 쓰지 않는다 (KVE-2026-2434)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| X-Guest-Order-Token | header | string | 비회원 주문이면 예 | `{만료ts}|{HMAC-SHA256}` | 주문 생성 응답(`guest_order_token`)으로 받은 비회원 조회 토큰. 이 주문의 것이고 만료 전이어야 한다. 회원은 싣지 않고 로그인(Bearer)으로 증명한다. |
| oid | body | string | 예 | max 40 | 재결제할 주문의 주문번호. |
| price | body | integer | 예 | min 1 | 주문 결제 금액. 저장된 주문 청구액과 일치해야 한다. |
| payment_method | body | string | 아니오 | max 50 | 재시도 시 선택한 결제수단 식별값. |

**응답 필드** (`data` 내부)

| 필드 | 타입 | 값 | 용도/설명 |
| --- | --- | --- | --- |
| status | string | `ready` \| `restored` | `ready` = 주문이 이미 결제 가능 상태라 복구할 것이 없음. `restored` = 실패/취소 상태였던 주문을 같은 주문번호로 재결제 가능하도록 되돌림. |

**응답 예시**

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "status": "restored"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 주문 소유자 확인 실패 — 비로그인 요청이 회원 주문을 보고함 · 다른 회원이 보고함 · 비회원 조회 토큰이 없거나 만료·위조·다른 주문의 것임 (`errors.message.0` = `Order ownership verification failed.`). 주문자 이메일·전화번호를 맞춰 보내도 통과하지 않는다 |
| 404 | Not Found | `oid` 에 해당하는 주문이 없는 경우 |
| 409 | Conflict | 주문이 재결제 가능한 상태가 아닌 경우 (이미 결제 완료·배송 진행 등) |
| 422 | Unprocessable Entity | 요청 파라미터 검증 위반, 청구 불가 통화, 금액 불일치 |
| 429 | Too Many Requests | 동일 IP·`oid` 조합에서 분당 20회 초과 |

**설명**

결제에 실패했거나 결제창을 닫아 취소된 주문을 **같은 주문번호로** 다시 결제할 수 있는 상태로 되돌린다. close-report 와 동일한 확인(주문 소유자·금액·레이트리밋)을 거치므로, 남의 주문번호나 주문자 연락처만으로 상태를 되돌릴 수 없다 (KVE-2026-2434).

---

## POST /plugins/sirsoft-pay_nhnkcp/payment/callback

- **라우트명**: `web.plugins.sirsoft-pay_nhnkcp.payment.callback`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\PaymentCallbackController@authCallback`
- **인증/권한**: 공개 · CSRF 검증 제외 (KCP 표준결제창이 브라우저 POST 로 리턴) · **IP 화이트리스트 미적용** (정상 사용자의 임의 IP 에서 도달)

**요청 파라미터** (KCP 결제창이 브라우저 POST 로 전달)

| 이름 | 타입 | 필수 | 용도 |
| --- | --- | --- | --- |
| ordr_idxx | string | 예 | 주문번호 |
| res_cd | string | 예(nullable 허용) | KCP 인증 결과코드 |
| res_msg | string | 아니오 | KCP 결과 메시지 |
| enc_data / enc_info | string | 아니오 | 승인 요청에 쓰는 암호화 데이터·정보 |
| tno | string | 아니오 | 거래번호. 브라우저가 보낸 값은 기록·취소에 쓰지 않는다 — 결제 기록의 거래번호는 KCP 승인 응답의 `tno` 다 |
| good_mny | numeric | 아니오 | 결제창 금액 (min 1). 승인 전 거절 판정에만 쓰고 승인 금액의 근거로 쓰지 않는다 |
| use_pay_method | string | 아니오 | 사용된 결제수단 |
| nhnkcp_easy_pay_method | string | 아니오 | 간편결제 수단 식별값 (max 50) |
| param_opt_2 | string | 아니오 | 모바일 가상계좌 세션 확인값 (max 50). 승인키 발급 시점에 실어 보낸 일회성 값을 KCP 가 그대로 되돌려준다 — 모바일 가상계좌 분기는 이 값이 주문에 저장된 값과 일치할 때만 계좌를 저장한다 |
| bankname · bank_name · account · depositor · account_holder · va_date | string | 아니오 | 모바일 가상계좌 콜백이 평문으로 전달하는 계좌 정보 변종 키 |

**응답**

JSON 이 아니라 **리다이렉트**다. 성공 시 상점 성공 페이지로, 실패 시 실패 페이지로 이동하며 실패 사유를 쿼리스트링(`error` · `message` · `orderId`)으로 전달한다. 쿼리 규약과 예외 원문 비노출 원칙은 [vbank.md "결제 실패 리다이렉트 규약"](vbank.md) 에 있다.

고정 `error` 값: `amount_mismatch` · `callback_locked` · `cli_exception` · `confirm_failed` · `currency_not_supported` · `invalid_payment_currency` · `order_not_found` · `order_not_retryable` · `vbank_issuance_incomplete` · `vbank_save_failed` · `vbank_session_mismatch`.

이 밖에 KCP 가 돌려준 결과코드(`res_cd`)가 그대로 실리는 분기가 있다. 화면이 `error` 로 분기할 때는 위 고정값만 신뢰하고, 그 밖의 값은 미확정 실패로 다룬다.

**설명**

KCP 표준결제창이 인증을 마치고 브라우저를 통해 가맹점으로 되돌려 보내는 경로다. 서버는 여기서 승인(approve)을 요청하고, 성공하면 주문을 결제 완료로 확정한다.

이 문서 상단 **"주문 상태를 바꾸는 경로는 하나뿐이다"** 가 이 엔드포인트의 핵심 계약이다 — 승인 전에 판정되는 실패(금액 불일치·인증 결과코드 비정상)에서는 주문 상태를 바꾸지 않는다. 승인이 이미 일어난 뒤(`tno` 존재)의 실패만 주문에 반영하고, 그 경우 KCP 측에 잔존한 승인을 자동 취소한다.

이 엔드포인트를 수정할 때는 실패 분기마다 **"이 판정의 근거가 브라우저가 보낸 값인가"** 를 먼저 확인한다. 근거가 브라우저 입력뿐이면 주문 상태를 바꾸지 않는다.

모바일 가상계좌 분기는 서버-서버 승인이 없어 계좌 정보의 유일한 출처가 브라우저 평문이다. 그래서 평문을 읽기 **전에** `param_opt_2` 가 주문에 저장된 세션 확인값과 일치하는지 대조하고, 일치하지 않으면 `vbank_session_mismatch` 로 되돌린다. 확인값은 저장 시 소멸하므로 같은 값으로 다시 올 수 없다. 발급 지점은 [vbank.md](vbank.md) 참조.
