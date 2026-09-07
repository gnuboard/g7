# Payment API 레퍼런스

> **소유**: plugin `sirsoft-pay_nhnkcp` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

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

## 주문 상태를 바꾸는 경로는 하나뿐이다

이 플러그인에서 **주문을 결제 실패로 전이시키는 결제창 경로는 `POST /api/plugins/sirsoft-pay_nhnkcp/payment/close-report` 하나**다.

브라우저 리턴 콜백(`POST /plugins/sirsoft-pay_nhnkcp/payment/callback`)은 PG 서명도 발신 IP 증명도 없고, 주문번호(`ordr_idxx`)·금액(`good_mny`)·결과코드(`res_cd`)가 전부 **요청자가 고른 값**이다. 그 입력을 근거로 주문을 실패 처리하면, 남의 주문번호와 임의 금액·위조 결과코드를 담은 POST 한 번으로 **타인의 결제대기 주문을 취소시킬 수 있다** (KVE-2026-2018).

그래서 콜백은 승인이 성립하기 전의 실패에서는 주문 상태를 건드리지 않고 실패 URL 로 되돌려 보내기만 한다.

| 시점 | 판정 근거 | 주문 상태 |
| --- | --- | --- |
| 금액 불일치 (승인 전) | 브라우저가 보낸 `good_mny` | **변경 없음** — 실패 URL 리다이렉트 |
| 승인 실패 (`res_cd` 비정상) | 브라우저가 보낸 결과코드 | **변경 없음** — 실패 URL 리다이렉트 |
| 승인 이후의 예외 (금액 불일치·후속 처리 실패) | KCP 승인 응답의 `tno` 존재 | **실패 처리 + 자동 취소** |

승인 여부는 `PaymentCallbackController::hasApproval()` 이 `tno`(승인 확정 시에만 채워지는 거래번호) 로 판정한다. `tno` 가 비어 있으면 승인 전에 흐름이 끊긴 것이므로 그 시점까지의 입력은 주문 상태 변경의 근거가 될 수 없다.

정당한 결제창 닫힘은 구매자 대조를 통과한 close-report 요청으로만 기록된다.

> 형제 플러그인도 같은 규약이다 — `sirsoft-pay_kginicis` · `sirsoft-tosspayments` 의 `docs/api/payment.md` 를 참조.

---

## POST /api/plugins/sirsoft-pay_nhnkcp/payment/close-report

- **라우트명**: `api.plugins.sirsoft-pay_nhnkcp.payment.close-report`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\PaymentCloseReportController@store`
- **인증/권한**: 공개 (인증 불필요 — 결제창 컨텍스트에서 호출)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| oid | body | string | 예 | max 40 | 결제창을 닫은 대상 주문의 주문번호. 서버가 이 값으로 주문을 조회해 결제 실패/취소 이력을 기록한다. |
| price | body | integer | 예 | min 1 | 주문 결제 금액. 저장된 주문 청구액과 일치하는지 검증해 위변조된 닫힘 보고를 차단한다. |
| buyer_email | body | string | 아니오 | max 255 | 구매자 이메일. 주문 배송지의 주문자 이메일이 있으면 **일치해야 한다**(불일치·미제공 시 403). |
| buyer_phone | body | string | 아니오 | max 30 | 구매자 전화번호. 주문 배송지의 주문자 전화가 있으면 숫자만 추출해 **일치해야 한다**(불일치·미제공 시 403). |
| payment_method | body | string | 아니오 | max 50 | 사용자가 결제창에서 선택했던 간편결제 등 결제수단 식별값. 결제 메타에 병합해 어떤 수단에서 창을 닫았는지 남긴다. |
| reason | body | string | 아니오 | max 160 | 결제창 닫힘 사유 문자열. 비어 있으면 기본 문구로 대체되어 취소 이력에 기록된다. |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_nhnkcp/payment/close-report HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "oid": "ORD20260902001",
    "price": 10000,
    "buyer_email": "buyer@example.com",
    "buyer_phone": "010-1234-5678",
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
| 403 | Forbidden | 요청의 구매자 정보(`buyer_email` / `buyer_phone`)가 주문의 주문자와 일치하지 않는 경우 |
| 404 | Not Found | `oid` 에 해당하는 주문이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우, 주문 통화가 청구 가능한 통화가 아닌 경우, 금액이 주문 청구액과 불일치하는 경우 |
| 429 | Too Many Requests | 동일 IP·`oid` 조합에서 분당 20회를 초과해 요청한 경우 |

**설명**

PC 표준결제창에서 사용자가 결제를 완료하지 않고 창을 닫았을 때 프론트엔드가 이를 서버에 보고하는 엔드포인트다. `OrderProcessingService::failPayment()` 로 주문을 `USER_CANCEL` 사유로 실패 처리하고 취소 이력을 남기며, 간편결제 선택 정보가 있으면 결제 메타에 병합한다.

인증은 불필요하지만 **이 경로가 주문 상태를 바꾸는 유일한 결제창 경로**이므로 방어가 네 겹이다 — FormRequest 검증, `oid` 기준 IP별 분당 20회 레이트리밋, 주문의 주문자 정보(이메일·전화) 대조, 주문 청구액과의 금액 대조.

승인 콜백(`authCallback`)과 같은 주문 락을 공유한다. 카드 주문은 승인 직전까지 주문 상태가 결제 전이라 상태 가드를 통과하므로, 결제가 이미 완료된 경우(`payment_already_paid`)를 별도로 차단해 결제 성공과 닫힘 보고가 경쟁할 때 옵션 상태가 어긋나는 것을 막는다. 락 획득에 실패하면 `callback_in_progress` 로 무시한다.

---

## POST /api/plugins/sirsoft-pay_nhnkcp/payment/retry

- **라우트명**: `api.plugins.sirsoft-pay_nhnkcp.payment.retry`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\PaymentRetryController@store`
- **인증/권한**: 공개 (인증 불필요 — 결제 실패 화면에서 호출)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| oid | body | string | 예 | max 40 | 재결제할 주문의 주문번호. |
| price | body | integer | 예 | min 1 | 주문 결제 금액. 저장된 주문 청구액과 일치해야 한다. |
| buyer_email | body | string | 아니오 | max 255 | 구매자 이메일. close-report 와 동일한 주문자 대조에 사용된다. |
| buyer_phone | body | string | 아니오 | max 30 | 구매자 전화번호. 동일. |
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
| 403 | Forbidden | 요청의 구매자 정보가 주문의 주문자와 일치하지 않는 경우 |
| 404 | Not Found | `oid` 에 해당하는 주문이 없는 경우 |
| 409 | Conflict | 주문이 재결제 가능한 상태가 아닌 경우 (이미 결제 완료·배송 진행 등) |
| 422 | Unprocessable Entity | 요청 파라미터 검증 위반, 청구 불가 통화, 금액 불일치 |
| 429 | Too Many Requests | 동일 IP·`oid` 조합에서 분당 20회 초과 |

**설명**

결제에 실패했거나 결제창을 닫아 취소된 주문을 **같은 주문번호로** 다시 결제할 수 있는 상태로 되돌린다. close-report 와 동일한 대조(주문자·금액·레이트리밋)를 거치므로, 남의 주문번호만으로 상태를 되돌릴 수 없다.

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
| tno | string | 아니오 | 거래번호. **승인이 확정된 뒤에만 채워진다** — 주문 상태 변경 가부의 판정 기준 |
| good_mny | numeric | 아니오 | 결제 금액 (min 1) |
| use_pay_method | string | 아니오 | 사용된 결제수단 |
| nhnkcp_easy_pay_method | string | 아니오 | 간편결제 수단 식별값 (max 50) |
| param_opt_2 | string | 아니오 | 모바일 가상계좌 세션 확인값 (max 50). 승인키 발급 시점에 실어 보낸 일회성 값을 KCP 가 그대로 되돌려준다 — 모바일 가상계좌 분기는 이 값이 주문에 저장된 값과 일치할 때만 계좌를 저장한다 |
| bankname · bank_name · account · depositor · account_holder · va_date | string | 아니오 | 모바일 가상계좌 콜백이 평문으로 전달하는 계좌 정보 변종 키 |

**응답**

JSON 이 아니라 **리다이렉트**다. 성공 시 상점 성공 페이지로, 실패 시 실패 페이지로 이동하며 실패 사유를 쿼리스트링(`error` · `message` · `orderId`)으로 전달한다. 쿼리 규약과 예외 원문 비노출 원칙은 [vbank.md "결제 실패 리다이렉트 규약"](vbank.md) 에 있다.

고정 `error` 값: `amount_mismatch` · `callback_locked` · `cli_exception` · `confirm_failed` · `currency_not_supported` · `invalid_payment_currency` · `order_not_found` · `order_not_retryable` · `vbank_save_failed` · `vbank_session_mismatch`.

이 밖에 KCP 가 돌려준 결과코드(`res_cd`)가 그대로 실리는 분기가 있다. 화면이 `error` 로 분기할 때는 위 고정값만 신뢰하고, 그 밖의 값은 미확정 실패로 다룬다.

**설명**

KCP 표준결제창이 인증을 마치고 브라우저를 통해 가맹점으로 되돌려 보내는 경로다. 서버는 여기서 승인(approve)을 요청하고, 성공하면 주문을 결제 완료로 확정한다.

이 문서 상단 **"주문 상태를 바꾸는 경로는 하나뿐이다"** 가 이 엔드포인트의 핵심 계약이다 — 승인 전에 판정되는 실패(금액 불일치·인증 결과코드 비정상)에서는 주문 상태를 바꾸지 않는다. 승인이 이미 일어난 뒤(`tno` 존재)의 실패만 주문에 반영하고, 그 경우 KCP 측에 잔존한 승인을 자동 취소한다.

이 엔드포인트를 수정할 때는 실패 분기마다 **"이 판정의 근거가 브라우저가 보낸 값인가"** 를 먼저 확인한다. 근거가 브라우저 입력뿐이면 주문 상태를 바꾸지 않는다.

모바일 가상계좌 분기는 서버-서버 승인이 없어 계좌 정보의 유일한 출처가 브라우저 평문이다. 그래서 평문을 읽기 **전에** `param_opt_2` 가 주문에 저장된 세션 확인값과 일치하는지 대조하고, 일치하지 않으면 `vbank_session_mismatch` 로 되돌린다. 확인값은 저장 시 소멸하므로 같은 값으로 다시 올 수 없다. 발급 지점은 [vbank.md](vbank.md) 참조.


### POST /api/plugins/sirsoft-pay_nhnkcp/payment/close-report
<!-- @generated:start:api.plugins.sirsoft-pay_nhnkcp.payment.close-report -->
- **라우트명**: `api.plugins.sirsoft-pay_nhnkcp.payment.close-report`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\PaymentCloseReportController@store`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| oid | body | string | 예 | max 40 | 주문번호. 이 값으로 주문을 찾는다(없으면 404). 요청자별 속도 제한 키에도 쓰인다. |
| price | body | integer | 예 | min 1 | 결제 금액. 주문의 청구 금액과 대조해 다르면 422 로 거부한다 — 브라우저가 보낸 금액을 그대로 신뢰하지 않기 위한 대조값이다. |
| buyer_email | body | string | 아니오 | max 255 | 주문자 확인값. 주문의 주문자 이메일과 대소문자·공백을 무시하고 비교하며, 주문에 이메일이 있는데 이 값이 없거나 다르면 403 이다. 주문번호만 아는 제3자가 남의 주문을 건드리지 못하게 하는 축이다. |
| buyer_phone | body | string | 아니오 | max 30 | 주문자 확인값. 숫자만 남겨 주문의 주문자 연락처와 비교하며, 주문에 연락처가 있는데 이 값이 없거나 다르면 403 이다. |
| payment_method | body | string | 아니오 | max 50 | 결제창에서 고른 결제수단 식별자. 판정에는 쓰이지 않고 실패·재시도 이력의 문맥으로만 남는다. |
| reason | body | string | 아니오 | max 160 | 닫힘 사유. 비어 있으면 기본 문구(`사용자가 NHN KCP 결제창을 닫았습니다.`)가 대신 기록된다. |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_nhnkcp/payment/close-report HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "oid": "예시값",
    "price": 1,
    "buyer_email": "user@example.com",
    "buyer_phone": "010-1234-5678",
    "payment_method": "예시값",
    "reason": "예시값"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 값 | 용도/설명 |
| --- | --- | --- | --- |
| status | string | `recorded` · `ignored` | `recorded` 는 결제 실패·취소 이력을 남겼다는 뜻이고, `ignored` 는 남기지 않았다는 뜻이다. 둘 다 HTTP 200 이다 — 닫힘 보고는 화면이 뒤로 물러난 뒤에 보내는 통보라 「기록할 상태가 아니었다」를 오류로 돌려주지 않는다. |
| reason | string | `order_not_payable` · `payment_already_paid` · `callback_in_progress` | `ignored` 일 때만 실린다. 차례로 「주문이 이미 결제 전 단계를 지났다」, 「결제가 이미 완료됐다」, 「같은 주문의 승인 콜백이 처리 중이라 잠금을 얻지 못했다」이다. |

**응답 예시**

```json
{
    "success": true,
    "message": "성공했습니다.",
    "data": {
        "status": "recorded"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

PC 결제창을 구매자가 닫았을 때 그 사실을 서버에 남기는 지점이다. 화면이 이미 뒤로 물러난 뒤에 보내는 통보라, 기록할 상태가 아니어도 오류가 아니라 `ignored` 로 답한다.

주문번호만 아는 제3자가 남의 주문을 실패로 만들 수 없도록 **주문자 확인값**(이메일·연락처)과 **금액**을 함께 받아 대조하며, 어긋나면 각각 403·422 다. 승인 콜백과 경쟁할 수 있어 같은 주문 잠금을 공유하고, 이미 결제가 완료된 주문은 건드리지 않는다. 같은 요청자·주문 조합은 1분에 20회로 제한된다.


### POST /api/plugins/sirsoft-pay_nhnkcp/payment/retry
<!-- @generated:start:api.plugins.sirsoft-pay_nhnkcp.payment.retry -->
- **라우트명**: `api.plugins.sirsoft-pay_nhnkcp.payment.retry`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\PaymentRetryController@store`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| oid | body | string | 예 | max 40 | 주문번호. 이 값으로 주문을 찾는다(없으면 404). 요청자별 속도 제한 키에도 쓰인다. |
| price | body | integer | 예 | min 1 | 결제 금액. 주문의 청구 금액과 대조해 다르면 422 로 거부한다 — 브라우저가 보낸 금액을 그대로 신뢰하지 않기 위한 대조값이다. |
| buyer_email | body | string | 아니오 | max 255 | 주문자 확인값. 주문의 주문자 이메일과 대소문자·공백을 무시하고 비교하며, 주문에 이메일이 있는데 이 값이 없거나 다르면 403 이다. 주문번호만 아는 제3자가 남의 주문을 건드리지 못하게 하는 축이다. |
| buyer_phone | body | string | 아니오 | max 30 | 주문자 확인값. 숫자만 남겨 주문의 주문자 연락처와 비교하며, 주문에 연락처가 있는데 이 값이 없거나 다르면 403 이다. |
| payment_method | body | string | 아니오 | max 50 | 결제창에서 고른 결제수단 식별자. 판정에는 쓰이지 않고 실패·재시도 이력의 문맥으로만 남는다. |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_nhnkcp/payment/retry HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "oid": "예시값",
    "price": 1,
    "buyer_email": "user@example.com",
    "buyer_phone": "010-1234-5678",
    "payment_method": "예시값"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 값 | 용도/설명 |
| --- | --- | --- | --- |
| status | string | `ready` · `restored` | `ready` 는 주문이 아직 결제 전 단계라 그대로 다시 결제할 수 있다는 뜻이고, `restored` 는 실패·취소된 주문을 다시 결제할 수 있는 상태로 되돌렸다는 뜻이다. 되돌릴 수 없는 주문이면 409 다. |

**응답 예시**

```json
{
    "success": true,
    "message": "성공했습니다.",
    "data": {
        "status": "ready"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

결제에 실패했던 주문을 다시 결제할 수 있는 상태로 되돌리는 지점이다. 아직 결제 전 단계면 그대로 `ready` 를 답하고, 실패·취소로 넘어간 주문이면 되돌린 뒤 `restored` 를 답한다. 되돌릴 수 없는 주문(이미 결제완료 등)은 409 다.

닫힘 보고와 같은 이유로 **주문자 확인값**과 **금액**을 대조하며(403·422), 같은 요청자·주문 조합은 1분에 20회로 제한된다.


### POST /plugins/sirsoft-pay_nhnkcp/payment/callback
<!-- @generated:start:web.plugins.sirsoft-pay_nhnkcp.payment.callback -->
- **라우트명**: `web.plugins.sirsoft-pay_nhnkcp.payment.callback`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\PaymentCallbackController@authCallback`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| res_cd | body | string | 아니오 | — | 인증 결과코드. `0000` 만 성공이고, `3001`·`3000`·`7777`·빈 값은 구매자가 결제창을 닫은 것으로 보아 조용히 결제 화면으로 되돌린다. 그 밖의 값은 실패다. |
| res_msg | body | string | 아니오 | — | 인증 결과 메시지. 판정에는 쓰지 않고 실패 이력의 사유 문구로만 남는다. |
| enc_data | body | string | 아니오 | — | 서버 승인 요청에 그대로 넘기는 암호화 인증 데이터. 결제가 성립하는 근거는 이 값으로 받은 **서버 승인 결과**이지 브라우저가 보낸 결과코드가 아니다. 모바일 취소 흐름에서는 오지 않는다. |
| enc_info | body | string | 아니오 | — | `enc_data` 와 짝을 이루는 암호화 부가정보. 함께 있어야 서버 승인을 요청할 수 있다. |
| tno | body | string | 아니오 | — | KCP 거래번호. 이 값이 있으면 승인이 이미 일어난 뒤라는 뜻이라, 그 뒤의 실패만 주문에 반영하고 KCP 측에 잔존한 승인을 자동 취소한다. |
| ordr_idxx | body | string | 예 | — | 주문번호. 이 값으로 주문을 찾는다. |
| good_mny | body | number | 아니오 | min 1 | 결제창이 청구한 금액. 주문의 청구 금액과 대조해 다르면 승인을 요청하지 않고 결제 화면으로 되돌린다. |
| use_pay_method | body | string | 아니오 | — | 결제수단 코드(카드·계좌이체·가상계좌 등). 이후 분기가 이 값이 아니라 **주문에 저장된 결제수단**을 따르므로, 위조해도 다른 분기로 넘어가지 않는다. |
| param_opt_1 | body | string | 아니오 | max 50 | 간편결제 수단 식별자를 싣는 자리. 표시용이며 판정에는 쓰지 않는다. |
| param_opt_2 | body | string | 아니오 | max 50 | 모바일 가상계좌 세션 확인값. 승인키 발급 시점에 만들어 주문에 저장한 값을 결제사를 통해 되돌려 받아 대조한다. 없거나 다르면 주문을 전혀 바꾸지 않는다. 한 번 쓰면 소멸한다. |
| nhnkcp_easy_pay_method | body | string | 아니오 | max 50 | 간편결제 수단 식별자(위 `param_opt_1` 보다 우선). 주문 상세의 결제수단 라벨을 만드는 데만 쓴다. |
| bankname | body | string | 아니오 | — | 가상계좌 입금 은행명. 표시용이다. |
| bank_name | body | string | 아니오 | — | bank 이름 (식별자) |
| account | body | string | 아니오 | — | 가상계좌 번호. 표시용이며, 모바일에서는 위 확인값 대조를 통과한 뒤에만 주문에 반영한다. |
| depositor | body | string | 아니오 | — | 입금자명(가상계좌). 표시용이다. |
| account_holder | body | string | 아니오 | — | 예금주명(가상계좌). 표시용이다. |
| va_date | body | string | 아니오 | — | va 날짜 |
| vnbank_expire_date | body | string | 아니오 | — | vnbank expire 날짜 |

**요청 예시**

```http
POST /plugins/sirsoft-pay_nhnkcp/payment/callback HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "res_cd": "예시값",
    "res_msg": "예시값",
    "enc_data": "예시값",
    "enc_info": "예시값",
    "tno": "예시값",
    "ordr_idxx": "예시값",
    "good_mny": 1,
    "use_pay_method": "예시값",
    "param_opt_1": "예시값",
    "param_opt_2": "예시값",
    "nhnkcp_easy_pay_method": "예시값",
    "bankname": "예시 이름",
    "bank_name": "예시 이름",
    "account": "예시값",
    "depositor": "예시값",
    "account_holder": "예시값",
    "va_date": "예시값",
    "vnbank_expire_date": "예시값"
}
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 본문을 돌려주지 않는다. 브라우저를 결제 결과 화면으로 이동시키며(302), 실패·취소는 결제 화면으로 되돌리면서 쿼리스트링에 사유를 싣는다. 사이트가 하위 폴더에 설치돼 있어도 이동 주소는 그 접두사를 포함한다.

**응답 예시**

```http
HTTP/1.1 302 Found
Location: https://example.com/shop/order/complete/20260902-0557051431
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

결제창이 끝난 뒤 **브라우저가** 결과를 실어 보내는 지점이다. 이 값만으로는 결제가 성립하지 않는다 — 서버가 `enc_data`·`enc_info` 로 KCP 에 승인을 다시 확인한 뒤에야 주문 상태를 바꾼다. 승인 전에 판정되는 실패(금액 불일치·인증 결과코드 비정상)에서는 주문을 건드리지 않고 결제 화면으로 되돌려 보내기만 한다. 브라우저가 오는 경로라 응답은 화면 이동(302)이다.

모바일 가상계좌는 서버-서버 승인이 없어 계좌 정보의 유일한 출처가 브라우저 평문이다. 그래서 평문을 읽기 **전에** `param_opt_2` 를 주문에 저장된 확인값과 대조하고, 어긋나면 주문을 전혀 바꾸지 않는다.


### POST /plugins/sirsoft-pay_nhnkcp/payment/escrow-common-notify
<!-- @generated:start:web.plugins.sirsoft-pay_nhnkcp.payment.escrow-common-notify -->
- **라우트명**: `web.plugins.sirsoft-pay_nhnkcp.payment.escrow-common-notify`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\EscrowCommonNotifyController@handle`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| site_cd | body | string | 아니오 | — | KCP 사이트 코드. 통보가 이 상점의 것인지 확인하는 데 쓴다. |
| tno | body | string | 아니오 | — | KCP 거래번호. 통보가 가리키는 결제 건이다. |
| order_no | body | string | 예 | — | 주문번호. 이 값으로 주문을 찾는다. |
| tx_cd | body | string | 예 | — | 통보 종류 코드. 어떤 사건(구매확정·배송등록·취소 등)의 통보인지를 이 값이 정한다. |
| tx_tm | body | string | 아니오 | — | 통보 발생 시각. 이력에만 남는다. |
| cl_status | body | string | 아니오 | — | 에스크로 진행 상태 코드. 이력에만 남는다. |
| st_cd | body | string | 아니오 | — | 처리 상태 코드. 이력에만 남는다. |
| can_msg | body | string | 아니오 | — | 취소 사유 메시지. 취소 통보일 때 이력의 사유로 남는다. |
| waybill_no | body | string | 아니오 | — | 운송장 번호. 배송등록 통보일 때 주문의 배송 정보로 옮겨진다. |
| waybill_corp | body | string | 아니오 | — | 택배사 코드. 위 운송장 번호와 짝이다. |

**요청 예시**

```http
POST /plugins/sirsoft-pay_nhnkcp/payment/escrow-common-notify HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "site_cd": "예시값",
    "tno": "예시값",
    "order_no": "예시값",
    "tx_cd": "예시값",
    "tx_tm": "예시값",
    "cl_status": "예시값",
    "st_cd": "예시값",
    "can_msg": "예시값",
    "waybill_no": "예시값",
    "waybill_corp": "예시값"
}
```

**응답 필드** (`data` 내부)

KCP 가 요구하는 고정 형식의 **평문**을 돌려준다(JSON 아님). 통보를 정상 수신했음을 알리는 응답이며, 형식이 어긋나면 KCP 가 같은 통보를 재전송한다.

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

KCP 가 **서버에서 서버로** 보내는 에스크로 공통통보다. 구매확정·배송등록·취소 같은 사건이 KCP 쪽에서 일어나면 이 주소로 통보가 온다. 브라우저를 거치지 않으므로 결제창 흐름과 무관하게 언제든 도착할 수 있고, 같은 통보가 여러 번 올 수 있어 처리는 멱등이어야 한다. 응답은 KCP 가 요구하는 고정 형식의 평문이며(HTTP 200), 그 형식이 어긋나면 KCP 가 재전송을 반복한다.


### POST /plugins/sirsoft-pay_nhnkcp/payment/vbank-notify
<!-- @generated:start:web.plugins.sirsoft-pay_nhnkcp.payment.vbank-notify -->
- **라우트명**: `web.plugins.sirsoft-pay_nhnkcp.payment.vbank-notify`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\PaymentCallbackController@vbankNotify`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| site_cd | body | string | 아니오 | — | KCP 사이트 코드. 통보가 이 상점의 것인지 확인하는 데 쓴다. |
| tno | body | string | 예 | — | KCP 거래번호. 이 값으로 결제 건을 특정한다. |
| order_no | body | string | 예 | — | 주문번호. 이 값으로 주문을 찾는다. |
| tx_cd | body | string | 아니오 | — | 통보 종류 코드. 입금인지 입금취소인지를 이 값이 가른다. |
| tx_tm | body | string | 아니오 | — | 통보 발생 시각. 이력에만 남는다. |
| ipgm_name | body | string | 아니오 | — | ipgm 이름 (식별자) |
| remitter | body | string | 아니오 | — | 실제 송금인 이름. 발급 시 등록한 입금자명과 다를 수 있어 이력으로 남긴다. |
| ipgm_mnyx | body | number | 아니오 | — | 입금 금액. 주문의 청구 금액과 대조해 다르면 결제완료로 바꾸지 않는다. |
| bank_code | body | string | 아니오 | — | 입금 은행 코드. 이력에만 남는다. |
| account | body | string | 아니오 | — | 입금된 가상계좌 번호. 이력에만 남는다. |
| op_cd | body | string | 아니오 | — | 처리 구분 코드. 이력에만 남는다. |
| noti_id | body | string | 아니오 | — | noti 식별자 |

**요청 예시**

```http
POST /plugins/sirsoft-pay_nhnkcp/payment/vbank-notify HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "site_cd": "예시값",
    "tno": "예시값",
    "order_no": "예시값",
    "tx_cd": "예시값",
    "tx_tm": "예시값",
    "ipgm_name": "예시 이름",
    "remitter": "예시값",
    "ipgm_mnyx": 1,
    "bank_code": "예시값",
    "account": "예시값",
    "op_cd": "예시값",
    "noti_id": "예시값"
}
```

**응답 필드** (`data` 내부)

KCP 가 요구하는 고정 형식의 **평문**을 돌려준다(JSON 아님). 통보를 정상 수신했음을 알리는 응답이며, 형식이 어긋나면 KCP 가 같은 통보를 재전송한다.

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

가상계좌에 입금이 들어왔을 때 KCP 가 **서버에서 서버로** 보내는 입금통보다. 구매자가 계좌로 돈을 넣은 시점에 도착하므로 결제창이 이미 닫힌 뒤이고, 이 통보가 그 주문을 결제완료로 바꾸는 유일한 근거다. 브라우저를 거치지 않아 위조 가능성이 낮은 대신, 같은 통보가 재전송될 수 있어 중복 처리를 막는다. 응답은 KCP 가 요구하는 고정 형식의 평문이다(HTTP 200).


