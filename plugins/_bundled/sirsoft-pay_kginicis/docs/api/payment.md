# Payment API 레퍼런스

> **소유**: plugin `sirsoft-pay_kginicis` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

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


### POST /api/plugins/sirsoft-pay_kginicis/payment/cbt/checkout-token
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.payment.cbt.checkout-token -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.payment.cbt.checkout-token`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\CbtCheckoutTokenController@issue`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| oid | body | string | 예 | max 40 | 주문번호 (`CbtCheckoutTokenRequest` 검증 — 40자 초과·문자열 아님은 422) |
| price | body | integer | 예 | min 1 | 결제 금액 (하한은 국내 결제와 동일한 `PaymentLimits::MIN_PRICE` SSoT — JPY 도 1 단위 결제 성립) |
| buyer_email | body | string | 아니오 | max 255 | 구매자 이메일 (주문 구매자 대조용) |
| buyer_phone | body | string | 아니오 | max 30 | 구매자 전화번호 (주문 구매자 대조용) |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_kginicis/payment/cbt/checkout-token HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "oid": "ORD20260714001",
    "price": 10000,
    "buyer_email": "buyer@example.com"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| checkout_token | string | `eyJvaWQiOiJPUkQyMDI2MDcxNDAwMSIsInByaWNlIjoxMDAwMH0.5f1c…` | 후속 hash-data 요청에 그대로 실어 보내는 단기 체크아웃 토큰. 주문번호·금액·구매자(이메일/전화)·요청 IP·User-Agent 를 HMAC-SHA256 으로 봉인한 서명값이다. |

**응답 예시**

```json
{
    "success": true,
    "data": {
        "checkout_token": "eyJvaWQiOiJPUkQyMDI2MDcxNDAwMSIsInByaWNlIjoxMDAwMH0.5f1c…"
    }
}
```

_이 엔드포인트는 `ResponseHelper` 봉투를 쓰지 않고 `response()->json()` 으로 `success`·`data` 만 반환합니다 (`message` 없음)._

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요청의 구매자 정보(`buyer_email` / `buyer_phone`)가 주문의 구매자와 일치하지 않는 경우 |
| 404 | Not Found | `oid` 에 해당하는 주문이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 `CbtCheckoutTokenRequest` 검증 규칙(oid 필수·max 40 / price 필수·정수·min 1 / buyer_email max 255 / buyer_phone max 30)을 위반한 경우 (표준 validation 응답, `error.errors` 에 필드별 메시지) / 일본 CBT 결제 비활성화·미설정, 주문이 결제 가능 상태가 아님, 주문 통화가 JPY 가 아님, 금액이 주문 청구액과 불일치 |
| 429 | Too Many Requests | 동일 IP·`oid` 조합에서 분당 10회를 초과해 요청한 경우 |

<!-- @generated:end -->

**설명**

일본 CBT(국경 간) 결제 흐름의 첫 단계로, 프론트엔드 결제창이 후속 해시 생성 요청에 사용할 단기 체크아웃 토큰을 발급합니다. `CbtCheckoutTokenService::issue()` 가 주문번호·금액·구매자(이메일/전화)·요청 IP·User-Agent 를 HMAC-SHA256 으로 봉인한 서명 토큰을 만들어 반환하며, 이 토큰은 hash-data 단계에서 결제 컨텍스트가 위변조되지 않았는지 검증하는 데 쓰입니다. 인증은 필요 없고(결제창에서 직접 호출), 대신 `oid` 기준 IP별 분당 10회 레이트리밋과 일본 결제 활성화·설정 여부, 주문 존재·결제 가능 상태·통화 JPY 여부·구매자 일치·금액 일치를 순차 검증합니다. 요청 형식·길이는 `CbtCheckoutTokenRequest` 가 국내 짝(`SignatureRequest`)과 동일 강도로 검증하며(`oid` max 40, `price` 하한은 `PaymentLimits::MIN_PRICE`), 위반 시 표준 validation 422, 레이트리밋 초과 시 429, 주문 미존재 시 404, 구매자 검증 실패 시 403, 그 외 결제 불가 조건은 422 로 응답합니다.


### POST /api/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.payment.cbt.hash-data -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.payment.cbt.hash-data`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\CbtHashDataController@generate`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| oid | body | string | 예 | max 40 | 주문번호 (`CbtHashDataRequest` 검증) |
| price | body | integer | 예 | min 1 | 결제 금액 (하한은 `PaymentLimits::MIN_PRICE` SSoT) |
| timestamp | body | string | 예 | max 20 | 결제창 타임스탬프 (해시 재료 — 신선도 검사 대상) |
| checkout_token | body | string | 예 | max 1024 | checkout-token 단계에서 발급받은 체크아웃 토큰. **필수 검증으로 승격** — 누락·빈 값은 422 (종전에는 토큰 검증 단계에서 403) |
| buyer_email | body | string | 아니오 | max 255 | 구매자 이메일 (주문 구매자 대조용) |
| buyer_phone | body | string | 아니오 | max 30 | 구매자 전화번호 (주문 구매자 대조용) |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "oid": "ORD20260714001",
    "price": 10000,
    "timestamp": "20260714120000123",
    "checkout_token": "eyJvaWQiOiJPUkQyMDI2MDcxNDAwMSIsInByaWNlIjoxMDAwMH0.5f1c…"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| hash_data | string | `a1b2c3d4e5f6…` | 일본 CBT 결제창이 KG 이니시스에 전송할 위변조 방지 해시(P_HASHDATA). 일본 가맹점 MID·타임스탬프·금액·주문번호로 생성된다. |

**응답 예시**

```json
{
    "success": true,
    "data": {
        "hash_data": "a1b2c3d4e5f6…"
    }
}
```

_이 엔드포인트는 `ResponseHelper` 봉투를 쓰지 않고 `response()->json()` 으로 `success`·`data` 만 반환합니다 (`message` 없음)._

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 구매자 정보가 주문과 불일치하거나, checkout-token 검증에 실패한 경우(토큰 위조·컨텍스트 불일치) |
| 404 | Not Found | `oid` 에 해당하는 주문이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 `CbtHashDataRequest` 검증 규칙(oid 필수·max 40 / price 필수·정수·min 1 / timestamp 필수·max 20 / checkout_token 필수·max 1024)을 위반한 경우 (표준 validation 응답 — **계약 변경**: 종전에는 `checkout_token` 이 비어 있으면 토큰 검증 단계에서 403 이었으나 필수 검증 승격으로 422 가 된다) / 타임스탬프 만료·형식 오류(재생 공격 방지), 일본 CBT 결제 비활성화·미설정, 주문이 결제 가능 상태가 아님, 주문 통화가 JPY 가 아님, 금액이 주문 청구액과 불일치 |
| 429 | Too Many Requests | 동일 IP·`oid` 조합에서 분당 10회를 초과해 요청한 경우 |

<!-- @generated:end -->

**설명**

일본 CBT 결제창이 실제로 KG 이니시스에 전송할 위변조 방지 해시(P_HASHDATA)를 생성해 반환합니다. `KgInicisApiService::generateCbtHashData()` 가 일본 가맹점 MID·타임스탬프·금액·주문번호로 해시를 만들며, 인증은 불필요하지만 checkout-token 단계에서 발급한 토큰을 `CbtCheckoutTokenService::verify()` 로 재검증해 동일한 결제 컨텍스트(주문·금액·구매자·IP·UA)에서 온 요청임을 보장합니다. 재생 공격을 막기 위해 타임스탬프 신선도(`isTimestampFresh`)를 확인하고, `oid` 기준 IP별 분당 10회 레이트리밋과 일본 결제 활성화·설정·주문 상태·통화 JPY·구매자 일치·금액 일치를 검증합니다. 요청 형식·길이는 `CbtHashDataRequest` 가 검증합니다 — 파라미터(`oid`, `price`, `timestamp`, `checkout_token`) 누락·길이 초과·타임스탬프 만료·금액 불일치 등은 422, 레이트리밋 초과 429, 주문 미존재 404, 구매자 검증 또는 (비어 있지 않은 토큰의) 토큰 검증 실패는 403 으로 응답합니다. 계약 변경: 종전에는 `checkout_token` 이 빈 값이면 토큰 검증 단계에서 403 이었으나, 필수 검증으로 승격되어 지금은 422 입니다 (클라이언트는 응답의 `.ok` 만 검사하므로 영향 없음).


### POST /api/plugins/sirsoft-pay_kginicis/payment/close-report
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.payment.close-report -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.payment.close-report`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\PaymentCloseReportController@store`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| oid | body | string | 예 | max 40 | 결제창을 닫은 대상 주문의 주문번호. 서버가 이 값으로 주문을 조회해 결제 실패/취소 이력을 기록한다. |
| price | body | integer | 예 | min 1 | 주문 결제 금액. 저장된 주문 청구액과 일치하는지 검증해 위변조된 닫힘 보고를 차단한다. |
| buyer_email | body | string | 아니오 | max 255 | 구매자 이메일. 제공 시 주문의 구매자 정보와 대조해 본인 요청인지 확인하는 데 사용된다. |
| buyer_phone | body | string | 아니오 | max 30 | 구매자 전화번호. 제공 시 주문의 구매자 정보와 대조해 본인 요청인지 확인하는 데 사용된다. |
| payment_method | body | string | 아니오 | max 50 | 사용자가 결제창에서 선택했던 간편결제 등 결제수단 식별값. 결제 메타에 병합해 어떤 수단에서 창을 닫았는지 남긴다. |
| reason | body | string | 아니오 | max 80 | 결제창 닫힘 사유 문자열. 실패/취소 이력에 참고 정보로 기록된다. |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_kginicis/payment/close-report HTTP/1.1
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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| status | string | `recorded` \| `ignored` | 닫힘 보고 처리 결과. `recorded` = 주문을 `USER_CANCEL` 로 실패 처리하고 취소 이력을 남김. `ignored` = 결제 성공 콜백과의 경쟁 등으로 처리하지 않고 무시. |
| reason | string | `order_not_payable` \| `payment_already_paid` | `status: ignored` 일 때만 포함되는 무시 사유. 주문이 이미 결제 가능 상태가 아니거나(`order_not_payable`), 결제가 이미 완료됨(`payment_already_paid`). |

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
| 403 | Forbidden | 요청의 구매자 정보(`buyer_email` / `buyer_phone`)가 주문의 구매자와 일치하지 않는 경우 |
| 404 | Not Found | `oid` 에 해당하는 주문이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지), 주문 통화가 KRW 가 아님, 금액이 주문 청구액과 불일치 |
| 429 | Too Many Requests | 동일 IP·`oid` 조합에서 분당 20회를 초과해 요청한 경우 |

<!-- @generated:end -->

**설명**

PC 표준결제창(KRW)에서 사용자가 결제를 완료하지 않고 창을 닫았을 때 프론트엔드가 이를 서버에 보고하는 엔드포인트로, 해당 주문의 결제 실패/취소 이력을 기록합니다. `OrderProcessingService::failPayment()` 로 주문을 `USER_CANCEL` 사유로 실패 처리하고 `recordPaymentCancellation()` 으로 취소 이력을 남기며, 간편결제 선택 정보가 있으면 결제 메타에 병합합니다. 인증은 불필요하나(결제창 컨텍스트에서 호출) FormRequest 검증과 `oid` 기준 IP별 분당 20회 레이트리밋, 주문 존재·통화 KRW·구매자 일치·금액 일치를 검증하고, 이미 결제 가능 상태가 아니거나(`order_not_payable`) 이미 결제 완료(`payment_already_paid`)면 성공 응답에 `status: ignored` 로 무시 처리해 결제 성공 콜백과의 경쟁 상태를 차단합니다. 검증 규칙 위반 시 422, 레이트리밋 초과 429, 주문 미존재 404, 구매자 검증 실패 403 으로 응답합니다.


### POST /api/plugins/sirsoft-pay_kginicis/payment/mobile/signature
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.payment.mobile.signature -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.payment.mobile.signature`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\MobileSignatureController@generate`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| oid | body | string | 예 | max 40 | 결제 대상 주문의 주문번호. 서버가 주문을 조회해 결제 가능 상태·통화·금액을 검증하고 이 값을 해시 생성 입력으로 사용한다. |
| price | body | integer | 예 | min 1 | 모바일 결제 금액. 저장된 주문 청구액과 일치하는지 검증한 뒤 P_CHKFAKE 해시 생성에 반영한다. |
| timestamp | body | string | 예 | max 20 | 결제창이 생성한 요청 타임스탬프. 재생 공격 방지를 위해 신선도(만료 여부)를 확인하고 해시 계산에 함께 사용한다. |
| buyer_email | body | string | 아니오 | max 255 | 구매자 이메일. 제공 시 주문의 구매자 정보와 대조해 본인 결제 요청인지 확인하는 데 사용된다. |
| buyer_phone | body | string | 아니오 | max 30 | 구매자 전화번호. 제공 시 주문의 구매자 정보와 대조해 본인 결제 요청인지 확인하는 데 사용된다. |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_kginicis/payment/mobile/signature HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "oid": "예시값",
    "price": 1,
    "timestamp": "예시값",
    "buyer_email": "user@example.com",
    "buyer_phone": "010-1234-5678"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| chkfake | string | `9f86d081884c7d65…` | 모바일 결제창이 KG 이니시스에 전송할 위변조 방지 해시(P_CHKFAKE). 주문번호·금액·타임스탬프로 생성된다. |
| mobile_payment_url | string | `https://mobile.inicis.com/smart/payment/` | 모바일 결제창 진입 URL. 테스트/운영 모드 설정에 따라 달라진다. |

**응답 예시**

```json
{
    "data": {
        "chkfake": "9f86d081884c7d65…",
        "mobile_payment_url": "https://mobile.inicis.com/smart/payment/"
    }
}
```

_이 엔드포인트는 `ResponseHelper` 봉투를 쓰지 않고 `response()->json()` 으로 `data` 만 반환합니다 (성공 시 `success`·`message` 없음)._

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요청의 구매자 정보(`buyer_email` / `buyer_phone`)가 주문의 구매자와 일치하지 않는 경우 |
| 404 | Not Found | `oid` 에 해당하는 주문이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지), 타임스탬프 만료·형식 오류, 주문이 결제 가능 상태가 아님, 주문 통화가 KRW 가 아님, 금액 불일치, 모바일 결제 자격증명 미설정 |
| 429 | Too Many Requests | 동일 IP·`oid` 조합에서 분당 20회를 초과해 요청한 경우 |

<!-- @generated:end -->

**설명**

모바일 KRW 결제창이 요구하는 위변조 방지 해시(P_CHKFAKE)와 모바일 결제 URL 을 생성해 반환합니다. `KgInicisApiService::generateMobileChkfake()` 로 주문번호·금액·타임스탬프 기반 해시를 만들고 `getMobilePaymentUrl()` 로 결제창 진입 URL 을 함께 내려줍니다. 인증은 불필요하지만(결제창에서 직접 호출) FormRequest 검증에 더해 재생 공격 방지를 위한 타임스탬프 신선도 확인, `oid` 기준 IP별 분당 20회 레이트리밋, 그리고 주문 존재·결제 가능 상태·통화 KRW·구매자 일치·금액 일치를 검증하며 모바일 결제 자격증명 설정 여부도 확인합니다. 파라미터 검증 실패·타임스탬프 만료·통화 불일치·금액 불일치·자격증명 미설정은 422, 레이트리밋 초과 429, 주문 미존재 404, 구매자 검증 실패 403 으로 응답합니다.


### POST /api/plugins/sirsoft-pay_kginicis/payment/signature
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.payment.signature -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.payment.signature`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\PaymentSignatureController@generate`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| oid | body | string | 예 | max 40 | 결제 대상 주문의 주문번호. 서버가 주문을 조회해 결제 가능 상태·통화·금액을 검증하고 이 값을 서명 생성 입력으로 사용한다. |
| price | body | integer | 예 | min 1 | PC 표준결제창 결제 금액. 저장된 주문 청구액과 일치하는지 검증한 뒤 signature 생성에 반영한다. 하한은 모바일 요청과 동일하며(`PaymentLimits::MIN_PRICE`), 실제 청구 가능 최소 금액은 PG 계약에 따른다. |
| timestamp | body | string | 예 | max 20 | 결제창이 생성한 요청 타임스탬프. 재생 공격 방지를 위해 신선도(만료 여부)를 확인하고 서명 계산에 함께 사용한다. |
| buyer_email | body | string | 아니오 | max 255 | 구매자 이메일. 제공 시 주문의 구매자 정보와 대조해 본인 결제 요청인지 확인하는 데 사용된다. |
| buyer_phone | body | string | 아니오 | max 30 | 구매자 전화번호. 제공 시 주문의 구매자 정보와 대조해 본인 결제 요청인지 확인하는 데 사용된다. |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_kginicis/payment/signature HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "oid": "예시값",
    "price": 1,
    "timestamp": "예시값",
    "buyer_email": "user@example.com",
    "buyer_phone": "010-1234-5678"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| signature | string | `4d2f8a9c1b7e…` | PC 표준결제창에 전달할 위변조 방지 서명. 주문번호·금액·타임스탬프로 생성된다. |
| verification | string | `7c1a3e5b9d0f…` | 표준결제창 검증값. signature 와 함께 결제창 호출 파라미터로 전달된다. |
| mKey | string | `e3b0c44298fc1c14…` | 가맹점 키 해시(mKey). KG 이니시스 표준결제창이 가맹점 식별에 사용한다. |

**응답 예시**

```json
{
    "data": {
        "signature": "4d2f8a9c1b7e…",
        "verification": "7c1a3e5b9d0f…",
        "mKey": "e3b0c44298fc1c14…"
    }
}
```

_이 엔드포인트는 `ResponseHelper` 봉투를 쓰지 않고 `response()->json()` 으로 `data` 만 반환합니다 (성공 시 `success`·`message` 없음)._

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요청의 구매자 정보(`buyer_email` / `buyer_phone`)가 주문의 구매자와 일치하지 않는 경우 |
| 404 | Not Found | `oid` 에 해당하는 주문이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지), 타임스탬프 만료·형식 오류, 주문이 결제 가능 상태가 아님, 주문 통화가 KRW 가 아님, 금액 불일치, 표준결제 자격증명 미설정 |
| 429 | Too Many Requests | 동일 IP·`oid` 조합에서 분당 20회를 초과해 요청한 경우 |

<!-- @generated:end -->

**설명**

PC 표준결제창(KRW)이 요구하는 서명(signature)·검증값(verification)·mKey 를 생성해 반환하는, PC 결제 흐름의 시작점입니다. `KgInicisApiService` 의 `generateSignature()`·`generateVerification()`·`getMKey()` 를 호출해 주문번호·금액·타임스탬프로 만든 서명 세트를 내려주며, 프론트엔드는 이 값으로 KG 이니시스 표준결제창을 호출합니다. 인증은 불필요하나(결제창에서 직접 호출) FormRequest 검증, 재생 공격 방지용 타임스탬프 신선도 확인, `oid` 기준 IP별 분당 20회 레이트리밋, 주문 존재·결제 가능 상태·통화 KRW·구매자 일치·금액 일치 검증과 표준결제 자격증명 설정 여부를 확인합니다. 파라미터 검증 실패·타임스탬프 만료·통화/금액 불일치·자격증명 미설정은 422, 레이트리밋 초과 429, 주문 미존재 404, 구매자 검증 실패 403 으로 응답합니다.


### POST /plugins/sirsoft-pay_kginicis/payment/callback
<!-- @generated:start:web.plugins.sirsoft-pay_kginicis.payment.callback -->
- **라우트명**: `web.plugins.sirsoft-pay_kginicis.payment.callback`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\PaymentCallbackController@authCallback`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| resultCode | body | string | 예 | — | 결제 인증 결과 코드 (`0000` = 성공). `2001`/`0021`/`0022`/빈값은 사용자 취소로 간주해 에러 없이 체크아웃으로 복귀한다. |
| resultMsg | body | string | 아니오 | — | 결제 인증 결과 메시지. 실패 시 실패 URL 의 `message` 파라미터로 전달되며 '취소'/'사용자' 포함 여부로 사용자 취소를 판별한다. |
| authToken | body | string | 아니오 | — | 결제 인증 토큰. 서버 승인 API(`authUrl`) 호출과 승인 취소(net cancel) 호출에 사용된다. |
| authUrl | body | string | 아니오 | — | 서버 승인 API 요청 URL. `idc_name` 과 함께 화이트리스트 검증(SSRF 방어)을 통과해야 호출된다. |
| checkAckUrl | body | string | 아니오 | — | `authUrl` 과 동일 역할의 승인 URL (KG 이니시스 버전에 따라 이 키로 전달됨). `authUrl` 미제공 시 대체로 사용한다. |
| netCancelUrl | body | string | 아니오 | — | 망취소(net cancel) 요청 URL. 실제 호출에는 `idc_name` 으로 재해석한 서버 화이트리스트 URL 을 사용한다. |
| idc_name | body | string | 아니오 | — | KG 이니시스 IDC 센터 코드(fc/ks/stg). 승인/망취소 URL 화이트리스트 검증의 기준값. |
| MOID | body | string | 아니오 | — | 주문번호 (구버전 필드명). 주문 조회 키로 사용되며 `orderNumber` 보다 우선한다. |
| orderNumber | body | string | 아니오 | — | 주문번호 (신버전 필드명). `MOID` 가 없을 때 주문 조회 키로 사용된다. |
| TotPrice | body | integer | 아니오 | min 0 | 결제 총액. 콜백에 없으면 서버 승인 응답의 `TotPrice` 로 보완해 결제 완료 처리에 사용한다. |

**요청 예시**

```http
POST /plugins/sirsoft-pay_kginicis/payment/callback HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "resultCode": "예시값",
    "resultMsg": "예시값",
    "authToken": "{YOUR_TOKEN}",
    "authUrl": "https://example.com",
    "checkAckUrl": "https://example.com",
    "netCancelUrl": "https://example.com",
    "idc_name": "예시 이름",
    "MOID": "예시값",
    "orderNumber": "예시값",
    "TotPrice": 1
}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON `data` 를 반환하지 않습니다. 브라우저 리다이렉트(302)로만 응답하며, 결과는 리다이렉트 URL 과 그 쿼리스트링으로 전달됩니다._

| 리다이렉트 대상 | 조건 | 쿼리 파라미터 |
| --- | --- | --- |
| 성공 URL (`redirect_success_url`, 기본 `{shopBase}/orders/{orderId}/complete`) | 서버 승인 성공(결제완료 또는 가상계좌 발급), 이미 결제완료된 거래(재전송) | 없음 (주문번호는 경로에 치환) |
| 실패 URL (`redirect_fail_url`, 기본 `{shopBase}/checkout`) | 인증 실패·검증 실패·승인 실패 | `error` (`invalid_params` \| `missing_fields` \| `auth_url_invalid` \| `order_not_found` \| `amount_mismatch` 등), `message` (PG 결과 메시지), `orderId` |
| 실패 URL (쿼리 없음) | 사용자가 결제창을 닫은 취소(`2001` / `0021` / `0022` / 빈값, 또는 결과 메시지에 '취소'·'사용자' 포함) | 없음 (조용한 복귀) |

**응답 예시**

```http
HTTP/1.1 302 Found
Location: https://example.com/shop/orders/ORD20260714001/complete
```

실패 시:

```http
HTTP/1.1 302 Found
Location: https://example.com/shop/checkout?error=amount_mismatch&orderId=ORD20260714001
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

PC 표준결제창(KRW)의 인증 결과를 브라우저 POST 로 수신해 서버 승인(2단계)까지 마무리하는 콜백입니다. KG 이니시스 표준결제는 결제창 인증(1단계)과 서버 승인(2단계)이 분리되어 있어, 이 엔드포인트가 콜백으로 받은 `authToken` 을 `authUrl` 로 재전송해 최종 승인을 얻은 뒤 주문을 결제완료 처리하고 결과 페이지로 리다이렉트합니다. 응답은 항상 JSON 이 아니라 리다이렉트(302)이며, 성공 시 결제완료 URL, 실패 시 체크아웃 URL 에 `error`·`message`·`orderId` 쿼리를 붙여 보냅니다.

주의사항: (1) `authUrl`·`netCancelUrl` 은 콜백으로 들어온 값을 그대로 신뢰하지 않고 `idc_name` 기준 화이트리스트로 검증해 SSRF 를 차단하며, 검증 실패 시 `error=auth_url_invalid` 로 되돌립니다. (2) 승인 응답의 결제수단이 가상계좌(`VBank`)면 결제완료 처리를 하지 않고 계좌 정보만 저장해 입금대기 상태로 두며, 실제 완료는 입금통보(vbank-notify) 시점에 이뤄집니다. (3) 같은 거래번호가 이미 결제완료인 경우 재처리하지 않고 성공 페이지로 복귀합니다(재전송 방어). (4) 승인 후 금액 불일치나 예외가 발생하면 즉시 망취소(net cancel)를 보내 PG 측 승인 잔존을 해제합니다. (5) 사용자가 결제창을 닫은 취소(`2001`/`0021`/`0022`/빈값, 또는 메시지에 '취소'·'사용자' 포함)는 실패가 아니므로 에러 쿼리 없이 체크아웃으로 조용히 복귀합니다.

예시 시나리오: 구매자가 PC 결제창에서 카드 결제를 승인 → KG 이니시스가 이 콜백으로 `resultCode=0000` + `authToken` + `authUrl` 전송 → 서버가 승인 API 호출 후 주문 결제완료 → `/shop/orders/{orderId}/complete` 로 리다이렉트.


### GET /plugins/sirsoft-pay_kginicis/payment/cbt/callback
<!-- @generated:start:web.plugins.sirsoft-pay_kginicis.payment.cbt.callback::get -->
- **라우트명**: `web.plugins.sirsoft-pay_kginicis.payment.cbt.callback`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\CbtCallbackController@handle`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| sid | query | string | 아니오 | max 255 | CBT 인증 세션 ID. 서버가 `/cbtapprove` 최종 승인 요청에 MID 와 함께 전송하는 승인 키이며, 결제 메타에 `cbt_sid` 로 저장된다. |
| resultCode | query | string | 아니오 | max 100 | CBT 인증 결과 코드 (`OK`/`00`/`0000` = 성공). `2001`/`0021`/`0022` 또는 취소 문구 포함 시 사용자 취소로 간주한다. |
| resultMsg | query | string | 아니오 | max 500 | CBT 인증 결과 메시지. 사용자 취소 판별(일본어/한국어 취소 문구)과 실패 리다이렉트 메시지에 사용된다. |
| orderID | query | string | 아니오 | max 100 | 주문번호 (1순위 키). `orderId` → `oid` 순으로 폴백해 주문을 조회한다. |
| orderId | query | string | 아니오 | max 100 | 주문번호 (2순위 폴백 키). |
| oid | query | string | 아니오 | max 100 | 주문번호 (3순위 폴백 키). |
| mid | query | string | 아니오 | max 30 | 일본 CBT 가맹점 MID. 설정된 일본 MID 와 불일치하면 결제를 중단한다(위조 콜백 차단). |
| paymethod | query | string | 아니오 | max 50 | CBT 결제수단 (CARD / PAYPAY / CVS). 승인 응답의 결제수단과 대조하며, CVS 는 편의점 입금대기 처리로 분기한다. |
| selectedPaymentMethod | query | string | 아니오 | max 50 | 체크아웃에서 사용자가 선택한 결제수단 식별값(`card` / `kginicis_japan_paypay` / `kginicis_japan_cvs`). 기대 결제수단 검증과 결제 메타 기록에 사용된다. |

**요청 예시**

```http
GET /plugins/sirsoft-pay_kginicis/payment/cbt/callback?sid=%EC%98%88%EC%8B%9C%EA%B0%92&resultCode=%EC%98%88%EC%8B%9C%EA%B0%92&resultMsg=%EC%98%88%EC%8B%9C%EA%B0%92&orderID=%EC%98%88%EC%8B%9C%EA%B0%92&orderId=%EC%98%88%EC%8B%9C%EA%B0%92&oid=%EC%98%88%EC%8B%9C%EA%B0%92&mid=%EC%98%88%EC%8B%9C%EA%B0%92&paymethod=%EC%98%88%EC%8B%9C%EA%B0%92&selectedPaymentMethod=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON `data` 를 반환하지 않습니다. 브라우저 리다이렉트(302)로만 응답하며, 결과는 리다이렉트 URL 과 그 쿼리스트링으로 전달됩니다._

| 리다이렉트 대상 | 조건 | 쿼리 파라미터 |
| --- | --- | --- |
| 성공 URL (`redirect_success_url`, 기본 `{shopBase}/orders/{orderId}/complete`) | CBT 서버 승인 성공(결제완료 또는 편의점 입금대기 등록), 이미 결제완료된 거래(재전송) | 없음 (주문번호는 경로에 치환) |
| 실패 URL (`redirect_fail_url`, 기본 `{shopBase}/checkout`) | 인증 실패·MID 불일치·검증 실패·승인 실패 | `error` (`invalid_params` \| `mid_mismatch` \| `order_not_found` \| `cbt_failed` 등), `message` (PG 결과 메시지), `orderId` |
| 실패 URL (쿼리 없음) | 사용자 취소(취소 코드 또는 일본어·한국어 취소 문구) | 없음 (조용한 복귀) |

**응답 예시**

```http
HTTP/1.1 302 Found
Location: https://example.com/shop/orders/ORD20260714001/complete
```

실패 시:

```http
HTTP/1.1 302 Found
Location: https://example.com/shop/checkout?error=mid_mismatch&orderId=ORD20260714001
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

일본 CBT(국경 간 결제) 인증 결과를 수신해 서버 승인(`/cbtapprove`)까지 마무리하는 콜백입니다(GET·POST 동일 처리). 브라우저가 KG 이니시스 일본 결제창에서 인증을 마치면 `sid` 와 주문번호가 붙어 이 주소로 되돌아오고, 서버는 그 `sid` 로 최종 승인을 요청한 뒤 결제완료 또는 편의점 입금대기 처리를 하고 결과 페이지로 리다이렉트합니다. GET 은 결제창이 리다이렉트로 되돌려보내는 경로, POST 는 폼 전송으로 되돌려보내는 경로이며 파라미터 위치만 다릅니다.

주의사항: (1) 브라우저가 전달한 인증 결과는 무인증 값이므로 그것만으로 주문 상태를 바꾸지 않고, 반드시 서버 승인 응답을 권위 있는 결과로 사용합니다. (2) 콜백의 `mid` 가 설정된 일본 가맹점 MID 와 다르면 위조 콜백으로 보고 즉시 중단합니다(`error=mid_mismatch`). (3) 승인 응답의 주문번호·MID·통화(JPY)·결제수단이 주문·체크아웃 선택값과 모두 일치해야 하며, 하나라도 어긋나면 승인 이후라도 실패로 처리합니다. (4) 결제수단이 `CVS`(편의점)면 결제완료가 아니라 입금대기로 저장하고 확인번호·접수번호·입금기한을 기록하며, 실제 완료는 편의점 입금통보(cvs-notify) 시점입니다. (5) 승인이 확정된 뒤 후속 처리에서 예외가 나면 자동 환불을 시도하고, 환불까지 실패하면 수동 환불 필요 상태로 대사 레코드를 남깁니다. (6) 사용자 취소(취소 코드 또는 일본어·한국어 취소 문구)는 실패가 아니므로 에러 없이 체크아웃으로 복귀하며, PayPay 업스트림 처리 실패는 별도 안내 메시지로 치환됩니다.

예시 시나리오: 일본 구매자가 PayPay 로 결제 → 결제창이 `sid` 를 붙여 이 콜백으로 복귀 → 서버가 승인 요청 → 성공 시 주문 결제완료 후 완료 페이지로 리다이렉트. 편의점결제를 선택했다면 같은 흐름이지만 입금대기 상태로 남고, 구매자가 편의점에서 입금하면 그때 완료됩니다.


### POST /plugins/sirsoft-pay_kginicis/payment/cbt/callback
<!-- @generated:start:web.plugins.sirsoft-pay_kginicis.payment.cbt.callback::post -->
- **라우트명**: `web.plugins.sirsoft-pay_kginicis.payment.cbt.callback`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\CbtCallbackController@handle`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| sid | body | string | 아니오 | max 255 | CBT 인증 세션 ID. 서버가 `/cbtapprove` 최종 승인 요청에 MID 와 함께 전송하는 승인 키이며, 결제 메타에 `cbt_sid` 로 저장된다. |
| resultCode | body | string | 아니오 | max 100 | CBT 인증 결과 코드 (`OK`/`00`/`0000` = 성공). `2001`/`0021`/`0022` 또는 취소 문구 포함 시 사용자 취소로 간주한다. |
| resultMsg | body | string | 아니오 | max 500 | CBT 인증 결과 메시지. 사용자 취소 판별(일본어/한국어 취소 문구)과 실패 리다이렉트 메시지에 사용된다. |
| orderID | body | string | 아니오 | max 100 | 주문번호 (1순위 키). `orderId` → `oid` 순으로 폴백해 주문을 조회한다. |
| orderId | body | string | 아니오 | max 100 | 주문번호 (2순위 폴백 키). |
| oid | body | string | 아니오 | max 100 | 주문번호 (3순위 폴백 키). |
| mid | body | string | 아니오 | max 30 | 일본 CBT 가맹점 MID. 설정된 일본 MID 와 불일치하면 결제를 중단한다(위조 콜백 차단). |
| paymethod | body | string | 아니오 | max 50 | CBT 결제수단 (CARD / PAYPAY / CVS). 승인 응답의 결제수단과 대조하며, CVS 는 편의점 입금대기 처리로 분기한다. |
| selectedPaymentMethod | body | string | 아니오 | max 50 | 체크아웃에서 사용자가 선택한 결제수단 식별값(`card` / `kginicis_japan_paypay` / `kginicis_japan_cvs`). 기대 결제수단 검증과 결제 메타 기록에 사용된다. |

**요청 예시**

```http
POST /plugins/sirsoft-pay_kginicis/payment/cbt/callback HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "sid": "예시값",
    "resultCode": "예시값",
    "resultMsg": "예시값",
    "orderID": "예시값",
    "orderId": "예시값",
    "oid": "예시값",
    "mid": "예시값",
    "paymethod": "예시값",
    "selectedPaymentMethod": "예시값"
}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON `data` 를 반환하지 않습니다. 브라우저 리다이렉트(302)로만 응답하며, 결과는 리다이렉트 URL 과 그 쿼리스트링으로 전달됩니다 (GET 경로와 동일)._

| 리다이렉트 대상 | 조건 | 쿼리 파라미터 |
| --- | --- | --- |
| 성공 URL (`redirect_success_url`, 기본 `{shopBase}/orders/{orderId}/complete`) | CBT 서버 승인 성공(결제완료 또는 편의점 입금대기 등록), 이미 결제완료된 거래(재전송) | 없음 (주문번호는 경로에 치환) |
| 실패 URL (`redirect_fail_url`, 기본 `{shopBase}/checkout`) | 인증 실패·MID 불일치·검증 실패·승인 실패 | `error` (`invalid_params` \| `mid_mismatch` \| `order_not_found` \| `cbt_failed` 등), `message` (PG 결과 메시지), `orderId` |
| 실패 URL (쿼리 없음) | 사용자 취소(취소 코드 또는 일본어·한국어 취소 문구) | 없음 (조용한 복귀) |

**응답 예시**

```http
HTTP/1.1 302 Found
Location: https://example.com/shop/orders/ORD20260714001/complete
```

실패 시:

```http
HTTP/1.1 302 Found
Location: https://example.com/shop/checkout?error=cbt_failed&orderId=ORD20260714001
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

일본 CBT(국경 간 결제) 인증 결과를 수신해 서버 승인(`/cbtapprove`)까지 마무리하는 콜백입니다(GET·POST 동일 처리). 브라우저가 KG 이니시스 일본 결제창에서 인증을 마치면 `sid` 와 주문번호가 붙어 이 주소로 되돌아오고, 서버는 그 `sid` 로 최종 승인을 요청한 뒤 결제완료 또는 편의점 입금대기 처리를 하고 결과 페이지로 리다이렉트합니다. GET 은 결제창이 리다이렉트로 되돌려보내는 경로, POST 는 폼 전송으로 되돌려보내는 경로이며 파라미터 위치만 다릅니다.

주의사항: (1) 브라우저가 전달한 인증 결과는 무인증 값이므로 그것만으로 주문 상태를 바꾸지 않고, 반드시 서버 승인 응답을 권위 있는 결과로 사용합니다. (2) 콜백의 `mid` 가 설정된 일본 가맹점 MID 와 다르면 위조 콜백으로 보고 즉시 중단합니다(`error=mid_mismatch`). (3) 승인 응답의 주문번호·MID·통화(JPY)·결제수단이 주문·체크아웃 선택값과 모두 일치해야 하며, 하나라도 어긋나면 승인 이후라도 실패로 처리합니다. (4) 결제수단이 `CVS`(편의점)면 결제완료가 아니라 입금대기로 저장하고 확인번호·접수번호·입금기한을 기록하며, 실제 완료는 편의점 입금통보(cvs-notify) 시점입니다. (5) 승인이 확정된 뒤 후속 처리에서 예외가 나면 자동 환불을 시도하고, 환불까지 실패하면 수동 환불 필요 상태로 대사 레코드를 남깁니다. (6) 사용자 취소(취소 코드 또는 일본어·한국어 취소 문구)는 실패가 아니므로 에러 없이 체크아웃으로 복귀하며, PayPay 업스트림 처리 실패는 별도 안내 메시지로 치환됩니다.

예시 시나리오: 일본 구매자가 PayPay 로 결제 → 결제창이 `sid` 를 붙여 이 콜백으로 복귀 → 서버가 승인 요청 → 성공 시 주문 결제완료 후 완료 페이지로 리다이렉트. 편의점결제를 선택했다면 같은 흐름이지만 입금대기 상태로 남고, 구매자가 편의점에서 입금하면 그때 완료됩니다.


### POST /plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify
<!-- @generated:start:web.plugins.sirsoft-pay_kginicis.payment.cbt.cvs-notify -->
- **라우트명**: `web.plugins.sirsoft-pay_kginicis.payment.cbt.cvs-notify`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\CbtCvsNotifyController@handle`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| tid | body | string | 아니오 | max 80 | CBT 편의점 결제 거래번호. 결제행에 저장된 발급 시점 TID 와 대조해 위조 통보를 차단한다(불일치 시 FAIL). |
| mid | body | string | 아니오 | max 20 | 일본 CBT 가맹점 MID. 결제 메타의 `cbt_mid` 와 대조해 불일치 시 통보를 거부한다. |
| applDt | body | string | 아니오 | max 8 | 입금 승인 일자(YYYYMMDD). `applTm` 과 결합해 결제 메타의 `auth_date` 로 저장된다. |
| applTm | body | string | 아니오 | max 6 | 입금 승인 시각(HHMMSS). `applDt` 와 결합해 결제 메타의 `auth_date` 로 저장된다. |
| status | body | string | 아니오 | max 10 | 편의점 입금 통보 상태 코드 (`00` = 입금 완료). 그 외 값은 무시(ignored)하고 OK 로 응답한다. |
| payNm | body | string | 아니오 | max 50 | 결제수단명(편의점 결제는 `CVS`). 통보 이력에 원문 그대로 보존된다. |
| orderId | body | string | 아니오 | max 100 | 주문번호. 이 값으로 주문·결제행을 조회한다(미존재 시 FAIL). |
| applNo | body | string | 아니오 | max 80 | 입금 승인번호. 통보 이력에 원문 그대로 보존된다. |
| sid | body | string | 아니오 | max 100 | CBT 인증 세션 ID. 결제 메타의 `cbt_sid`(발급 시점 값)와 대조해 불일치 시 통보를 거부한다. |
| convenience | body | string | 아니오 | max 30 | 입금한 편의점 구분값. 결제 메타 `cvs_convenience` 로 갱신된다. |
| confNo | body | string | 아니오 | max 80 | 편의점 결제 확인번호. 결제 메타 `cvs_conf_no` 로 갱신된다. |
| receiptNo | body | string | 아니오 | max 80 | 편의점 수납 영수증번호. 결제 메타 `cvs_receipt_no` 로 갱신된다. |
| paymentTerm | body | string | 아니오 | max 20 | 편의점 입금 기한(YYYYMMDDHHMMSS, 일본 시각). 결제 메타 `cvs_payment_term` 으로 갱신되며 만료 판정 기준이 된다. |
| amount | body | string | 아니오 | — | 입금 금액. 주문의 결제 통화(JPY) 청구액과 일치해야 하며(불일치 시 FAIL), 결제 완료 금액으로 사용된다. |
| currencyCd | body | string | 아니오 | max 10 | 통보 통화 코드. `JPY` 가 아니면 통보를 거부한다. |

**요청 예시**

```http
POST /plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "tid": "예시값",
    "mid": "예시값",
    "applDt": "예시값",
    "applTm": "예시값",
    "status": "예시값",
    "payNm": "예시값",
    "orderId": "예시값",
    "applNo": "예시값",
    "sid": "예시값",
    "convenience": "예시값",
    "confNo": "예시값",
    "receiptNo": "예시값",
    "paymentTerm": "예시값",
    "amount": "예시값",
    "currencyCd": "예시값"
}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 을 반환하지 않습니다 (`data` 없음). 응답은 `Content-Type: text/plain` 의 평문 본문 한 줄이며, HTTP 상태코드는 항상 200 입니다._

| 본문 | 의미 | 발생 조건 |
| --- | --- | --- |
| `OK` | 통보 수신 확인 | 검증 통과 후 입금 처리 완료, 또는 처리 불필요(비-성공 `status` 통보 / 이미 결제완료된 중복 통보)로 무시 |
| `FAIL` | 통보 거부 | 주문·결제행 미존재, TID/MID/SID 불일치, 통화가 `JPY` 아님, 금액 불일치, 결제 상태가 입금대기 아님, 결제수단이 CVS 아님 |

`FAIL` 을 반환하면 KG 이니시스가 동일 통보를 재시도합니다.

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: text/plain

OK
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | KG 이니시스 공식 발송 IP 화이트리스트에 없는 IP 에서 요청한 경우 (로컬·테스트 환경 제외) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

일본 CBT 편의점(CVS) 결제의 입금 통보(NOTI)를 KG 이니시스 서버로부터 직접 수신하는 웹훅입니다. 구매자가 편의점에서 실제로 대금을 납부하면 KG 이니시스가 이 주소로 통보를 보내고, 서버는 통보를 검증한 뒤 입금대기 상태의 결제를 결제완료로 전환합니다. 이 URL 은 관리자 화면의 CBT 편의점 운영 정보에 표시되며, 그대로 KG 이니시스 가맹점 설정의 통보 URL 로 등록하면 됩니다.

주의사항: (1) 응답은 JSON 이 아니라 평문 `OK` 또는 `FAIL` 이며(HTTP 200 고정), `OK` 를 돌려주지 않으면 KG 이니시스가 통보를 재시도합니다. 검증 실패는 `FAIL`, 처리 불필요(비-성공 상태 통보·이미 결제완료)는 `OK` 로 응답합니다. (2) 라우트에 KG 이니시스 공식 발송 IP 화이트리스트가 걸려 있어 허용되지 않은 IP 에서 온 요청은 403 으로 차단됩니다(로컬·테스트 환경 제외). (3) 위조 통보를 막기 위해 발급 시점에 저장한 거래번호(TID)·가맹점 MID·세션 ID(SID)와 통보 값을 대조하고, 통화가 `JPY` 인지, 금액이 결제 통화 기준 청구액과 같은지, 결제 상태가 입금대기이고 결제수단이 CVS 인지까지 모두 확인합니다. 어느 하나라도 어긋나면 처리하지 않고 `FAIL` 을 반환합니다. (4) `status` 가 `00`(입금 완료)이 아닌 통보는 무시하고 `OK` 로 응답합니다. (5) 모든 통보는 성공·실패·무시 여부와 사유가 결제 이력에 기록되어 관리자 화면에서 확인할 수 있습니다.

예시 시나리오: 구매자가 편의점결제를 선택해 확인번호를 발급받고 입금대기 상태가 됨 → 며칠 뒤 편의점에서 납부 → KG 이니시스가 `status=00` 통보를 이 주소로 전송 → 검증 통과 시 주문이 결제완료로 전환되고 서버는 `OK` 를 반환.


### GET /plugins/sirsoft-pay_kginicis/payment/close
<!-- @generated:start:web.plugins.sirsoft-pay_kginicis.payment.close -->
- **라우트명**: `web.plugins.sirsoft-pay_kginicis.payment.close`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\PaymentCloseController@show`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /plugins/sirsoft-pay_kginicis/payment/close HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 을 반환하지 않습니다 (`data` 없음). 응답은 `Content-Type: text/html; charset=UTF-8` · `Cache-Control: no-store` 의 HTML 페이지(HTTP 200)이며, KG 이니시스 결제창 close 스크립트를 로드하고 부모/opener 창에 `{ source: 'sirsoft-pay_kginicis', type: 'payment-window-closed', reason: 'inicis-close-url' }` 를 `postMessage` 로 전달합니다. 로드되는 close 스크립트 주소는 플러그인 설정 `is_test_mode` 에 따라 `stgstdpay.inicis.com`(테스트) 또는 `stdpay.inicis.com`(운영) 으로 분기합니다._

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: text/html; charset=UTF-8
Cache-Control: no-store, no-cache, must-revalidate, max-age=0

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>KG 이니시스 결제창 닫기</title>
    <script>/* 부모·opener 창에 payment-window-closed postMessage 전송 */</script>
    <script src="https://stgstdpay.inicis.com/stdjs/INIStdPay_close.js" charset="UTF-8"></script>
</head>
<body></body>
</html>
```

**에러 응답**

_에러 응답이 정의되어 있지 않습니다. 인증·요청 파라미터 없이 정적 HTML(200)만 반환하므로 도메인 특이 에러가 발생하지 않습니다._

<!-- @generated:end -->

**설명**

PC 표준결제창의 닫기(closeUrl) 전용 페이지입니다. 구매자가 결제창을 X 버튼으로 닫으면 KG 이니시스 결제창이 이 주소를 열고, 페이지는 KG 이니시스 공식 close 스크립트를 실행해 결제창을 정리한 뒤 부모(또는 opener) 창에 결제창이 닫혔음을 알리는 메시지를 보냅니다. 체크아웃 화면은 그 메시지를 받아 결제 진행 상태를 해제하고, 별도로 결제창 닫힘을 서버에 보고(close-report)합니다.

주의사항: 응답은 JSON 이 아니라 HTML 페이지이며, 캐시되지 않도록 no-store 헤더가 붙습니다. 로드하는 close 스크립트 주소는 플러그인의 테스트/운영 모드 설정에 따라 달라지므로, 운영 전환 시 결제창 닫기가 정상 동작하는지 함께 확인해야 합니다. 이 엔드포인트는 결제 상태를 바꾸지 않으며, 주문의 취소 이력 기록은 close-report 엔드포인트가 담당합니다.


### GET /plugins/sirsoft-pay_kginicis/payment/escrow-confirm/close
<!-- @generated:start:web.plugins.sirsoft-pay_kginicis.payment.escrow-confirm.close -->
- **라우트명**: `web.plugins.sirsoft-pay_kginicis.payment.escrow-confirm.close`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\UserEscrowConfirmController@close`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /plugins/sirsoft-pay_kginicis/payment/escrow-confirm/close HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 을 반환하지 않습니다 (`data` 없음). 응답은 `Content-Type: text/html; charset=UTF-8` 의 HTML(HTTP 200)이며, 본문은 팝업을 닫는 스크립트(`window.close()`) 한 줄뿐입니다. 결제나 구매결정 상태를 변경하지 않습니다._

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: text/html; charset=UTF-8

<script>window.close();</script>
```

**에러 응답**

_에러 응답이 정의되어 있지 않습니다. 인증·요청 파라미터 없이 팝업 닫기 스크립트(HTML 200)만 반환하므로 도메인 특이 에러가 발생하지 않습니다._

<!-- @generated:end -->

**설명**

에스크로 구매결정 팝업의 닫기(closeUrl) 전용 페이지입니다. 구매자가 PC 에스크로 구매결정 창을 닫으면 KG 이니시스가 이 주소를 열고, 페이지는 창을 닫는 스크립트만 실행합니다. 결제나 구매결정 상태를 바꾸지 않으며 인증도 요구하지 않습니다(팝업 정리 용도). 응답은 JSON 이 아니라 HTML 입니다.


### POST /plugins/sirsoft-pay_kginicis/payment/escrow-confirm/mobile/return
<!-- @generated:start:web.plugins.sirsoft-pay_kginicis.payment.escrow-confirm.mobile-return -->
- **라우트명**: `web.plugins.sirsoft-pay_kginicis.payment.escrow-confirm.mobile-return`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\UserEscrowConfirmController@mobileReturn`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /plugins/sirsoft-pay_kginicis/payment/escrow-confirm/mobile/return HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON `data` 를 반환하지 않습니다. 브라우저 리다이렉트(302)로만 응답합니다._

| 리다이렉트 대상 | 조건 |
| --- | --- |
| `/mypage/orders/{orderNumber}` | `P_ESCROW_TID` 로 KG 이니시스 에스크로 결제행을 찾아 구매결정 결과(`P_STATUS` `00` = 구매확정, 그 외 = 구매거절)를 결제 메타 `escrow_confirm` 에 기록한 경우 |
| `/mypage/orders` | 거래번호가 비었거나 매칭되는 에스크로 결제가 없어 아무것도 기록하지 않은 경우 |

_KG 이니시스가 POST 로 보내는 필드(라우트 FormRequest 로 선언되지 않아 요청 파라미터 표에는 나타나지 않음): `P_STATUS`(구매결정 결과 코드), `P_ESCROW_TID`(에스크로 거래번호), `P_CL_STATUS`(결제 상태 구분), `P_RMESG1`(결과 메시지)._

**응답 예시**

```http
HTTP/1.1 302 Found
Location: https://example.com/mypage/orders/ORD20260714001
```

**에러 응답**

_에러 응답이 정의되어 있지 않습니다. 거래번호가 비었거나 매칭되는 에스크로 결제가 없어도 예외를 던지지 않고 주문 목록(`/mypage/orders`)으로 리다이렉트합니다._

<!-- @generated:end -->

**설명**

모바일 에스크로 구매결정 결과를 KG 이니시스로부터 수신하는 복귀 지점입니다. 구매자가 모바일에서 구매확정 또는 구매거절을 마치면 KG 이니시스가 이 주소로 결과를 전송하고, 서버는 전달된 에스크로 거래번호로 해당 결제를 찾아 구매결정 결과(확정/거절, 결과 코드, 메시지)를 결제 이력에 기록한 뒤 마이페이지 주문 상세로 리다이렉트합니다.

주의사항: 응답은 JSON 이 아니라 리다이렉트(302)이며, 성공 시 해당 주문 상세로, 거래번호로 결제를 찾지 못하면 주문 목록으로 이동합니다. 거래번호가 없거나 매칭되는 에스크로 결제가 없으면 아무것도 기록하지 않고 조용히 넘어갑니다(외부에서 임의 호출해도 상태가 바뀌지 않음). 구매거절이 접수된 경우 판매자 측에서 별도로 구매거절 확인(관리자 에스크로 거절확인) 절차를 진행해야 합니다.


### POST /plugins/sirsoft-pay_kginicis/payment/escrow-confirm/pc/return
<!-- @generated:start:web.plugins.sirsoft-pay_kginicis.payment.escrow-confirm.pc-return -->
- **라우트명**: `web.plugins.sirsoft-pay_kginicis.payment.escrow-confirm.pc-return`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\UserEscrowConfirmController@pcReturn`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /plugins/sirsoft-pay_kginicis/payment/escrow-confirm/pc/return HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 을 반환하지 않습니다 (`data` 없음). 응답은 `Content-Type: text/html; charset=UTF-8` 의 HTML(HTTP 200)이며, 부모 창을 새로고침하고 팝업을 닫는 스크립트뿐입니다. 구매결정 결과(`ResultCode` `00` = 구매확정, 그 외 = 구매거절)와 확정·거절 일시, 거절 사유는 `tid` 로 찾은 에스크로 결제행의 결제 메타 `escrow_confirm` 에 기록됩니다._

_KG 이니시스가 POST 로 보내는 필드(라우트 FormRequest 로 선언되지 않아 요청 파라미터 표에는 나타나지 않음): `ResultCode`(구매결정 결과 코드), `tid`(에스크로 거래번호), `CNF_Date`/`CNF_Time`(구매확정 일시), `DNY_Date`/`DNY_Time`(구매거절 일시), `DNY_DenyMsg`(구매거절 사유)._

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: text/html; charset=UTF-8

<script>try{window.opener&&window.opener.location.reload();}catch(e){}window.close();</script>
```

**에러 응답**

_에러 응답이 정의되어 있지 않습니다. 거래번호가 비었거나 매칭되는 에스크로 결제가 없어도 기록만 생략하고 동일한 HTML(200)을 반환합니다._

<!-- @generated:end -->

**설명**

PC 에스크로 구매결정 결과를 KG 이니시스 팝업으로부터 수신하는 복귀 지점입니다. 구매자가 구매확정 또는 구매거절을 마치면 KG 이니시스 결제창이 이 주소로 결과를 전송하고, 서버는 전달된 에스크로 거래번호로 결제를 찾아 구매결정 결과(확정/거절, 결과 코드, 확정·거절 일시, 거절 사유)를 결제 이력에 기록합니다.

주의사항: 응답은 JSON 이 아니라 HTML 이며, 팝업을 닫고 부모 창을 새로고침하는 스크립트를 반환합니다. 거래번호가 비어 있거나 매칭되는 에스크로 결제가 없으면 아무 기록도 남기지 않습니다. 결과 코드가 확정(`00`)이 아니면 구매거절로 기록되며, 이후 판매자 측 구매거절 확인 절차가 필요합니다.


### GET /plugins/sirsoft-pay_kginicis/payment/escrow-confirm/{orderNumber}
<!-- @generated:start:web.plugins.sirsoft-pay_kginicis.payment.escrow-confirm.show -->
- **라우트명**: `web.plugins.sirsoft-pay_kginicis.payment.escrow-confirm.show`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\UserEscrowConfirmController@show`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 구매결정할 주문의 주문번호. 로그인 사용자 본인의 KG 이니시스 에스크로 결제(`is_escrow=true`, `transaction_id` 존재)를 찾는 키로 사용된다. |

**요청 예시**

```http
GET /plugins/sirsoft-pay_kginicis/payment/escrow-confirm/{orderNumber} HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 을 반환하지 않습니다 (`data` 없음). 응답은 `Content-Type: text/html; charset=UTF-8` 의 HTML 페이지(HTTP 200)이며, KG 이니시스 구매결정 창으로 자동 전송되는 폼을 담고 있습니다. 접속 기기의 User-Agent 로 PC/모바일을 판별해 다음과 같이 분기합니다._

| 분기 | 반환 HTML | 폼 전송 대상 | 주요 hidden 필드 |
| --- | --- | --- | --- |
| PC | `INIStdPay_escrow_conf.js` 로드 후 `INIStdPay.pay('escrow_confirm_form')` 자동 실행 | KG 이니시스 PC 구매결정 팝업 (테스트: `stgstdpay.inicis.com`, 운영: `stdpay.inicis.com`) | `mid`, `tid`, `timestamp`, `mKey`, `currency=WON`, `returnUrl`(pc/return), `closeUrl`(escrow-confirm/close) |
| 모바일 | 폼 자동 submit 스크립트 | `https://mobile.inicis.com/smart/payment/` (accept-charset: euc-kr) | `P_INI_PAYMENT=ESCROWCONFIRM`, `P_MID`, `P_ESCROW_TID`, `P_NEXT_URL`(mobile/return) |

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: text/html; charset=UTF-8

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>에스크로 구매결정</title>
    <script src="https://stgstdpay.inicis.com/stdjs/INIStdPay_escrow_conf.js" charset="UTF-8"></script>
    <script>window.onload = function() { INIStdPay.pay('escrow_confirm_form'); };</script>
</head>
<body>
    <p>에스크로 구매결정 창을 준비 중입니다...</p>
    <form id="escrow_confirm_form" method="post">
        <input type="hidden" name="version" value="1.0">
        <input type="hidden" name="mid" value="INIpayTest">
        <input type="hidden" name="tid" value="StdpayCARDINIpayTest20260714…">
        <input type="hidden" name="timestamp" value="1768348800000">
        <input type="hidden" name="mKey" value="e3b0c44298fc1c14…">
        <input type="hidden" name="currency" value="WON">
        <input type="hidden" name="returnUrl" value="https://example.com/plugins/sirsoft-pay_kginicis/payment/escrow-confirm/pc/return">
        <input type="hidden" name="closeUrl" value="https://example.com/plugins/sirsoft-pay_kginicis/payment/escrow-confirm/close">
        <input type="hidden" name="acceptmethod" value="">
    </form>
</body>
</html>
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthorized | 비로그인 상태로 접근한 경우 (라우트에 `auth` 미들웨어 적용 — 요약의 "공개" 표기는 자동 생성 오차) |
| 404 | Not Found | `orderNumber` 에 해당하는 주문이 접속 사용자 본인 소유가 아니거나, 해당 주문에 거래번호가 있는 KG 이니시스 에스크로 결제(`pg_provider=kginicis`, `is_escrow=true`)가 없는 경우 |

<!-- @generated:end -->

**설명**

구매자가 자신의 에스크로 주문에 대해 구매결정(구매확정/구매거절)을 진행하도록 KG 이니시스 구매결정 창을 띄워 주는 진입 페이지입니다. 마이페이지 주문 상세의 "구매결정" 버튼이 이 주소를 열며, 서버는 접속한 사용자 본인의 KG 이니시스 에스크로 결제를 찾아 거래번호를 담은 폼을 만들고 KG 이니시스 구매결정 창으로 자동 전송합니다. 접속 기기의 User-Agent 로 PC/모바일을 판별해 각각 PC 팝업 방식과 모바일 전송 방식으로 분기합니다.

주의사항: 응답은 JSON 이 아니라 HTML 페이지입니다. 로그인이 필요하며(비로그인 시 로그인 흐름으로 전환), 주문번호에 해당하는 에스크로 결제가 본인 소유가 아니거나 존재하지 않으면 404 로 응답합니다. 구매결정 결과는 이 페이지가 아니라 KG 이니시스가 되돌려보내는 복귀 지점(escrow-confirm PC/모바일 return)에서 기록됩니다.


### GET /plugins/sirsoft-pay_kginicis/payment/mobile/callback
<!-- @generated:start:web.plugins.sirsoft-pay_kginicis.payment.mobile.callback::get -->
- **라우트명**: `web.plugins.sirsoft-pay_kginicis.payment.mobile.callback`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\MobileCallbackController@handle`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| P_STATUS | query | string | 예 | — | 모바일 결제 인증 결과 코드 (`00` = 성공). 그 외 값은 실패이며 `P_RMESG1` 문구로 사용자 취소 여부를 분기한다. |
| P_RMESG1 | query | string | 아니오 | — | 모바일 결제 인증 결과 메시지. '사용자가 결제를 취소' 등 취소 문구 포함 시 오류 없이 체크아웃으로 복귀한다. |
| P_TID | query | string | 아니오 | — | KG 이니시스 거래번호. 서버 승인 요청(`P_REQ_URL`)에 MID 와 함께 전송된다. |
| P_REQ_URL | query | string | 아니오 | — | 서버 승인 API 요청 URL. `idc_name` 과 함께 화이트리스트 검증(SSRF 방어)을 통과해야 호출된다. |
| P_AMT | query | string | 아니오 | — | 결제 금액. 서버 승인 응답의 `P_AMT` 가 없을 때 결제 완료 금액의 폴백으로 사용된다. |
| P_OID | query | string | 아니오 | — | 주문번호. 없으면 콜백 URL 쿼리스트링의 `orderId` 를 폴백으로 사용한다. |
| idc_name | query | string | 아니오 | — | KG 이니시스 IDC 센터 코드(fc/ks/stg). 승인 URL 화이트리스트 검증의 기준값. |

**요청 예시**

```http
GET /plugins/sirsoft-pay_kginicis/payment/mobile/callback?P_STATUS=%EC%98%88%EC%8B%9C%EA%B0%92&P_RMESG1=%EC%98%88%EC%8B%9C%EA%B0%92&P_TID=%EC%98%88%EC%8B%9C%EA%B0%92&P_REQ_URL=https%3A%2F%2Fexample.com&P_AMT=%EC%98%88%EC%8B%9C%EA%B0%92&P_OID=%EC%98%88%EC%8B%9C%EA%B0%92&idc_name=%EC%98%88%EC%8B%9C%20%EC%9D%B4%EB%A6%84 HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON `data` 를 반환하지 않습니다. 브라우저 리다이렉트(302)로만 응답하며, 결과는 리다이렉트 URL 과 그 쿼리스트링으로 전달됩니다._

| 리다이렉트 대상 | 조건 | 쿼리 파라미터 |
| --- | --- | --- |
| 성공 URL (`redirect_success_url`, 기본 `{shopBase}/orders/{orderId}/complete`) | 서버 승인 성공(결제완료 또는 가상계좌 발급), 이미 결제완료된 거래(재전송) | 없음 (주문번호는 경로에 치환) |
| 실패 URL (`redirect_fail_url`, 기본 `{shopBase}/checkout`) | 인증 실패·검증 실패·승인 실패 | `error` (`order_id_missing` \| `missing_fields` \| `req_url_invalid` \| `order_not_found` \| `amount_mismatch` 등), `message` (`P_RMESG1` 결과 메시지), `orderId` |
| 실패 URL (쿼리 없음) | 사용자가 결제창을 닫은 취소(`P_RMESG1` 에 '사용자가 결제를 취소' 등 취소 문구 포함) | 없음 (조용한 복귀) |

**응답 예시**

```http
HTTP/1.1 302 Found
Location: https://example.com/shop/orders/ORD20260714001/complete
```

실패 시:

```http
HTTP/1.1 302 Found
Location: https://example.com/shop/checkout?error=req_url_invalid&orderId=ORD20260714001
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

모바일 표준결제창(KRW)의 인증 결과를 수신해 서버 승인까지 마무리하는 콜백입니다(GET·POST 동일 처리). 모바일 결제는 결제창 인증 후 KG 이니시스가 이 주소로 결과를 돌려보내고, 서버가 전달받은 승인 요청 URL 로 거래번호를 재전송해 최종 승인을 얻은 뒤 주문을 결제완료 처리하고 결과 페이지로 리다이렉트합니다. GET 은 일부 PG 환경의 리다이렉트 복귀 패턴을 위해 함께 열어 둔 경로로, 동작은 POST 와 같습니다.

주의사항: (1) 응답은 JSON 이 아니라 리다이렉트(302)이며, 실패 시 체크아웃 URL 에 `error`·`message`·`orderId` 쿼리가 붙습니다. (2) 승인 요청 URL 은 콜백 값을 그대로 신뢰하지 않고 IDC 코드 기준 화이트리스트로 검증해 SSRF 를 차단합니다(실패 시 `error=req_url_invalid`). (3) 모바일 표준 응답에는 주문번호가 빠질 수 있어, 없으면 콜백 URL 쿼리스트링의 `orderId` 를 대체로 사용합니다. (4) 승인 결과가 가상계좌면 결제완료 처리를 하지 않고 계좌 정보만 저장해 입금대기로 두며, 완료는 모바일 가상계좌 입금통보 시점입니다. (5) 같은 거래번호가 이미 결제완료면 재처리하지 않고 성공 페이지로 복귀합니다. (6) 승인 후 금액 불일치나 예외가 발생하면 자동 취소를 호출해 PG 측 승인 잔존을 해제하며, 자동 취소마저 실패하면 수동 정산이 필요합니다. (7) 사용자가 결제창을 닫은 취소는 결과 코드만으로 일반 실패와 구분되지 않으므로 결과 메시지의 취소 문구로 판별하며, 이 경우 에러 없이 체크아웃으로 조용히 복귀합니다.

예시 시나리오: 모바일 구매자가 카드 결제를 승인 → KG 이니시스가 이 콜백으로 인증 성공과 승인 요청 URL 을 전송 → 서버가 승인 요청 → 성공 시 주문 결제완료 후 완료 페이지로 리다이렉트.


### POST /plugins/sirsoft-pay_kginicis/payment/mobile/callback
<!-- @generated:start:web.plugins.sirsoft-pay_kginicis.payment.mobile.callback::post -->
- **라우트명**: `web.plugins.sirsoft-pay_kginicis.payment.mobile.callback`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\MobileCallbackController@handle`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| P_STATUS | body | string | 예 | — | 모바일 결제 인증 결과 코드 (`00` = 성공). 그 외 값은 실패이며 `P_RMESG1` 문구로 사용자 취소 여부를 분기한다. |
| P_RMESG1 | body | string | 아니오 | — | 모바일 결제 인증 결과 메시지. '사용자가 결제를 취소' 등 취소 문구 포함 시 오류 없이 체크아웃으로 복귀한다. |
| P_TID | body | string | 아니오 | — | KG 이니시스 거래번호. 서버 승인 요청(`P_REQ_URL`)에 MID 와 함께 전송된다. |
| P_REQ_URL | body | string | 아니오 | — | 서버 승인 API 요청 URL. `idc_name` 과 함께 화이트리스트 검증(SSRF 방어)을 통과해야 호출된다. |
| P_AMT | body | string | 아니오 | — | 결제 금액. 서버 승인 응답의 `P_AMT` 가 없을 때 결제 완료 금액의 폴백으로 사용된다. |
| P_OID | body | string | 아니오 | — | 주문번호. 없으면 콜백 URL 쿼리스트링의 `orderId` 를 폴백으로 사용한다. |
| idc_name | body | string | 아니오 | — | KG 이니시스 IDC 센터 코드(fc/ks/stg). 승인/망취소 URL 화이트리스트 검증의 기준값. |

**요청 예시**

```http
POST /plugins/sirsoft-pay_kginicis/payment/mobile/callback HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "P_STATUS": "예시값",
    "P_RMESG1": "예시값",
    "P_TID": "예시값",
    "P_REQ_URL": "https://example.com",
    "P_AMT": "예시값",
    "P_OID": "예시값",
    "idc_name": "예시 이름"
}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON `data` 를 반환하지 않습니다. 브라우저 리다이렉트(302)로만 응답하며, 결과는 리다이렉트 URL 과 그 쿼리스트링으로 전달됩니다 (GET 경로와 동일)._

| 리다이렉트 대상 | 조건 | 쿼리 파라미터 |
| --- | --- | --- |
| 성공 URL (`redirect_success_url`, 기본 `{shopBase}/orders/{orderId}/complete`) | 서버 승인 성공(결제완료 또는 가상계좌 발급), 이미 결제완료된 거래(재전송) | 없음 (주문번호는 경로에 치환) |
| 실패 URL (`redirect_fail_url`, 기본 `{shopBase}/checkout`) | 인증 실패·검증 실패·승인 실패 | `error` (`order_id_missing` \| `missing_fields` \| `req_url_invalid` \| `order_not_found` \| `amount_mismatch` 등), `message` (`P_RMESG1` 결과 메시지), `orderId` |
| 실패 URL (쿼리 없음) | 사용자가 결제창을 닫은 취소(`P_RMESG1` 에 취소 문구 포함) | 없음 (조용한 복귀) |

**응답 예시**

```http
HTTP/1.1 302 Found
Location: https://example.com/shop/orders/ORD20260714001/complete
```

실패 시:

```http
HTTP/1.1 302 Found
Location: https://example.com/shop/checkout?error=amount_mismatch&orderId=ORD20260714001
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

모바일 표준결제창(KRW)의 인증 결과를 수신해 서버 승인까지 마무리하는 콜백입니다(GET·POST 동일 처리). 모바일 결제는 결제창 인증 후 KG 이니시스가 이 주소로 결과를 돌려보내고, 서버가 전달받은 승인 요청 URL 로 거래번호를 재전송해 최종 승인을 얻은 뒤 주문을 결제완료 처리하고 결과 페이지로 리다이렉트합니다. GET 은 일부 PG 환경의 리다이렉트 복귀 패턴을 위해 함께 열어 둔 경로로, 동작은 POST 와 같습니다.

주의사항: (1) 응답은 JSON 이 아니라 리다이렉트(302)이며, 실패 시 체크아웃 URL 에 `error`·`message`·`orderId` 쿼리가 붙습니다. (2) 승인 요청 URL 은 콜백 값을 그대로 신뢰하지 않고 IDC 코드 기준 화이트리스트로 검증해 SSRF 를 차단합니다(실패 시 `error=req_url_invalid`). (3) 모바일 표준 응답에는 주문번호가 빠질 수 있어, 없으면 콜백 URL 쿼리스트링의 `orderId` 를 대체로 사용합니다. (4) 승인 결과가 가상계좌면 결제완료 처리를 하지 않고 계좌 정보만 저장해 입금대기로 두며, 완료는 모바일 가상계좌 입금통보 시점입니다. (5) 같은 거래번호가 이미 결제완료면 재처리하지 않고 성공 페이지로 복귀합니다. (6) 승인 후 금액 불일치나 예외가 발생하면 자동 취소를 호출해 PG 측 승인 잔존을 해제하며, 자동 취소마저 실패하면 수동 정산이 필요합니다. (7) 사용자가 결제창을 닫은 취소는 결과 코드만으로 일반 실패와 구분되지 않으므로 결과 메시지의 취소 문구로 판별하며, 이 경우 에러 없이 체크아웃으로 조용히 복귀합니다.

예시 시나리오: 모바일 구매자가 카드 결제를 승인 → KG 이니시스가 이 콜백으로 인증 성공과 승인 요청 URL 을 전송 → 서버가 승인 요청 → 성공 시 주문 결제완료 후 완료 페이지로 리다이렉트.


### POST /plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify
<!-- @generated:start:web.plugins.sirsoft-pay_kginicis.payment.mobile.vbank-notify -->
- **라우트명**: `web.plugins.sirsoft-pay_kginicis.payment.mobile.vbank-notify`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\PaymentCallbackController@mobileVbankNotify`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| P_TID | body | string | 예 | max 40 | KG 이니시스 거래번호. 결제행에 저장된 발급 시점 TID 와 대조하며, 이미 결제완료된 TID 면 중복 통보로 무시한다. |
| P_MID | body | string | 예 | max 10 | 가맹점 MID. 결제 메타에 저장된 발급 시점 MID 와 대조해 위조 통보를 차단한다. |
| P_OID | body | string | 예 | max 100 | 주문번호. 이 값으로 입금대기 주문을 조회한다(미존재 시 FAIL). |
| P_AMT | body | string | 예 | max 12 | 입금 금액. 주문의 결제 통화 청구액과 일치해야 하며(불일치 시 FAIL), 결제 완료 금액으로 사용된다. |
| P_STATUS | body | string | 예 | max 2 | 모바일 통보 상태 코드. `02`(입금통보)일 때만 실제 입금 처리하고, 그 외에는 OK 로 응답 후 무시한다. |
| P_TYPE | body | string | 예 | max 10 | 통보 결제수단 구분. `VBANK` 인 경우에만 가상계좌 입금 처리를 수행한다. |
| P_AUTH_DT | body | string | 아니오 | max 14 | 입금 승인 일시(YYYYMMDDHHMMSS). 결제 메타의 `deposit_date` 로 저장된다. |
| P_FN_CD1 | body | string | 아니오 | max 4 | 입금 은행 코드. 결제 메타의 `bank_code` 로 저장된다. |
| P_FN_NM | body | string | 아니오 | max 50 | 입금 은행명(EUC-KR 수신 시 UTF-8 자동 변환). 결제 메타의 `vbank_name` 으로 저장된다. |
| P_UNAME | body | string | 아니오 | max 30 | 입금자명(EUC-KR 수신 시 UTF-8 자동 변환). 결제 메타의 `depositor_name` 으로 저장된다. |
| P_RMESG1 | body | string | 아니오 | max 500 | 통보 메시지. 파이프(`&#124;`) 구분자의 첫 조각에서 가상계좌번호를 추출해 발급 계좌와 대조하는 데 사용된다. |
| P_FN_CD2 | body | string | 아니오 | — | KG 이니시스 부가 은행 코드. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| P_RMESG2 | body | string | 아니오 | — | KG 이니시스 보조 통보 메시지. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| P_NOTI | body | string | 아니오 | max 600 | 결제 요청 시 가맹점이 전달한 임의 데이터의 회신값. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| P_AUTH_NO | body | string | 아니오 | — | 승인번호. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| P_CSHR_AMT | body | string | 아니오 | max 12 | 현금영수증 발급 금액. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| P_CSHR_TAX | body | string | 아니오 | max 12 | 현금영수증 부가세액. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| P_CSHR_TYPE | body | string | 아니오 | max 14 | 현금영수증 발급 구분(소득공제/지출증빙). 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| P_CSHR_DT | body | string | 아니오 | max 14 | 현금영수증 발급 일시. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |

**요청 예시**

```http
POST /plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "P_TID": "예시값",
    "P_MID": "예시값",
    "P_OID": "예시값",
    "P_AMT": "예시값",
    "P_STATUS": "예시값",
    "P_TYPE": "예시값",
    "P_AUTH_DT": "예시값",
    "P_FN_CD1": "예시값",
    "P_FN_NM": "예시값",
    "P_UNAME": "예시 이름",
    "P_RMESG1": "예시값",
    "P_FN_CD2": "예시값",
    "P_RMESG2": "예시값",
    "P_NOTI": "예시값",
    "P_AUTH_NO": "예시값",
    "P_CSHR_AMT": "예시값",
    "P_CSHR_TAX": "예시값",
    "P_CSHR_TYPE": "예시값",
    "P_CSHR_DT": "예시값"
}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 을 반환하지 않습니다 (`data` 없음). 응답은 `Content-Type: text/plain` 의 평문 본문 한 줄이며, HTTP 상태코드는 항상 200 입니다._

| 본문 | 의미 | 발생 조건 |
| --- | --- | --- |
| `OK` | 통보 수신 확인 | 검증 통과 후 입금 처리 완료, 또는 처리 불필요(`P_STATUS` 가 `02` 가 아니거나 `P_TYPE` 이 `VBANK` 가 아닌 통보 / 이미 결제완료된 중복 통보)로 무시 |
| `FAIL` | 통보 거부 | 주문 미존재, 거래번호(TID)·MID·가상계좌번호 불일치, 금액이 결제 통화 기준 청구액과 불일치, 결제가 가상계좌·입금대기 상태가 아님, 처리 중 예외 발생 |

`FAIL` 을 반환하면 KG 이니시스가 동일 통보를 재시도합니다.

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: text/plain

OK
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | KG 이니시스 공식 발송 IP 화이트리스트에 없는 IP 에서 요청한 경우 (로컬·테스트 환경 제외) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

모바일 결제로 발급한 가상계좌의 입금통보를 KG 이니시스 서버로부터 직접 수신하는 웹훅입니다. 구매자가 발급받은 계좌에 실제로 입금하면 KG 이니시스가 이 주소로 통보를 보내고, 서버는 통보를 검증한 뒤 입금대기 상태의 주문을 결제완료로 전환합니다. 이 URL 은 관리자 설정의 가상계좌 통보 URL(모바일용)에 표시되며, 그대로 KG 이니시스 가맹점 설정에 등록하면 됩니다.

주의사항: (1) 응답은 JSON 이 아니라 평문 `OK` 또는 `FAIL` 이며, `OK` 를 돌려주지 않으면 KG 이니시스가 통보를 재시도합니다. (2) 라우트에 KG 이니시스 공식 발송 IP 화이트리스트가 걸려 있어 허용되지 않은 IP 의 요청은 403 으로 차단됩니다(로컬·테스트 환경 제외). (3) 위조 통보를 막기 위해 발급 시점에 저장한 거래번호·가맹점 MID·가상계좌번호(숫자만 비교)·결제 통화 기준 청구액을 통보 값과 대조하고, 결제가 가상계좌·입금대기 상태인지도 확인합니다. 하나라도 어긋나면 처리하지 않고 `FAIL` 을 반환합니다. (4) 통보 상태 코드가 입금통보(`02`)이고 결제수단 구분이 `VBANK` 인 경우에만 실제 입금 처리를 하며, 그 외 통보는 `OK` 로 응답 후 무시합니다. (5) 이미 결제완료된 거래번호의 통보는 중복으로 보고 재처리하지 않습니다. (6) 은행명·입금자명이 EUC-KR 로 들어오면 UTF-8 로 자동 변환해 저장합니다.

예시 시나리오: 구매자가 모바일에서 가상계좌를 선택 → 계좌 발급 후 주문은 입금대기 → 구매자가 계좌 이체 → KG 이니시스가 이 주소로 입금통보 → 검증 통과 시 결제완료 전환 후 `OK` 반환.


### POST /plugins/sirsoft-pay_kginicis/payment/vbank-notify
<!-- @generated:start:web.plugins.sirsoft-pay_kginicis.payment.vbank-notify -->
- **라우트명**: `web.plugins.sirsoft-pay_kginicis.payment.vbank-notify`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\PaymentCallbackController@vbankNotify`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| no_tid | body | string | 예 | max 40 | 가상계좌 입금통보 거래번호. 결제행에 저장된 발급 시점 TID 와 대조하며, 이미 결제완료된 TID 면 중복 통보로 무시한다. |
| no_oid | body | string | 예 | max 40 | 주문번호. 이 값으로 입금대기 주문을 조회한다(미존재 시 FAIL). |
| id_merchant | body | string | 예 | max 10 | 가맹점 MID. 결제 메타에 저장된 발급 시점 MID 와 대조해 위조 통보를 차단한다. |
| dt_trans | body | string | 예 | max 8 | 입금 거래 일자(YYYYMMDD). `tm_trans` 와 결합해 결제 메타의 `deposit_date` 로 저장된다. |
| tm_trans | body | string | 예 | max 6 | 입금 거래 시각(HHMMSS). `dt_trans` 와 결합해 결제 메타의 `deposit_date` 로 저장된다. |
| cd_bank | body | string | 예 | max 8 | 입금 은행 코드. 결제 메타의 `bank_code` 로 저장된다. |
| cd_deal | body | string | 아니오 | max 8 | KG 이니시스 거래 구분 코드. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| no_vacct | body | string | 예 | max 20 | 입금된 가상계좌번호. 발급 시 저장한 계좌번호와 대조해(숫자만 비교) 위조 통보를 차단하고 결제 메타의 `vbank_num` 으로 저장된다. |
| amt_input | body | string | 예 | max 13 | 입금 금액. 주문의 결제 통화 청구액과 일치해야 하며(불일치 시 FAIL), 결제 완료 금액으로 사용된다. |
| nm_inputbank | body | string | 아니오 | max 10 | 입금 은행명(EUC-KR 수신 시 UTF-8 자동 변환). 결제 메타의 `vbank_name` 으로 저장된다. |
| nm_input | body | string | 아니오 | max 20 | 입금자명(EUC-KR 수신 시 UTF-8 자동 변환). 결제 메타의 `depositor_name` 으로 저장된다. |
| dt_inputstd | body | string | 아니오 | max 8 | 입금 기준일자(정산 정보). 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| dt_calculstd | body | string | 아니오 | max 8 | 정산 기준일자(정산 정보). 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| flg_close | body | string | 아니오 | max 1 | 가상계좌 마감 여부 플래그. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| dt_cshr | body | string | 아니오 | max 8 | 현금영수증 발급 일자. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| tm_cshr | body | string | 아니오 | max 6 | 현금영수증 발급 시각. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| no_cshr_appl | body | string | 아니오 | max 9 | 현금영수증 승인번호. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| no_cshr_tid | body | string | 아니오 | max 40 | 현금영수증 거래번호. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| msg_id | body | string | 아니오 | — | KG 이니시스 통보 메시지 ID. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| no_msgseq | body | string | 아니오 | — | KG 이니시스 통보 메시지 일련번호. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| cd_joinorg | body | string | 아니오 | — | KG 이니시스 가입기관 코드. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| dt_transbase | body | string | 아니오 | — | KG 이니시스 거래 기준일자. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| no_transeq | body | string | 아니오 | — | KG 이니시스 거래 일련번호. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| type_msg | body | string | 아니오 | — | KG 이니시스 통보 메시지 유형. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| cl_close | body | string | 아니오 | — | KG 이니시스 마감 구분값. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| cl_kor | body | string | 아니오 | — | KG 이니시스 한글 구분값. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| no_msgmanage | body | string | 아니오 | — | KG 이니시스 통보 메시지 관리번호. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다. |
| amt_check | body | string | 아니오 | — | KG 이니시스 검증용 금액값. 통보 차단을 막기 위해 수신만 허용하며 처리에는 사용하지 않는다(입금 금액 검증은 `amt_input` 기준). |

**요청 예시**

```http
POST /plugins/sirsoft-pay_kginicis/payment/vbank-notify HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "no_tid": "예시값",
    "no_oid": "예시값",
    "id_merchant": "예시값",
    "dt_trans": "예시값",
    "tm_trans": "예시값",
    "cd_bank": "예시값",
    "cd_deal": "예시값",
    "no_vacct": "예시값",
    "amt_input": "예시값",
    "nm_inputbank": "예시값",
    "nm_input": "예시값",
    "dt_inputstd": "예시값",
    "dt_calculstd": "예시값",
    "flg_close": "예시값",
    "dt_cshr": "예시값",
    "tm_cshr": "예시값",
    "no_cshr_appl": "예시값",
    "no_cshr_tid": "예시값",
    "msg_id": "예시값",
    "no_msgseq": "예시값",
    "cd_joinorg": "예시값",
    "dt_transbase": "예시값",
    "no_transeq": "예시값",
    "type_msg": "예시값",
    "cl_close": "예시값",
    "cl_kor": "예시값",
    "no_msgmanage": "예시값",
    "amt_check": "예시값"
}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 을 반환하지 않습니다 (`data` 없음). 응답은 `Content-Type: text/plain` 의 평문 본문 한 줄이며, HTTP 상태코드는 항상 200 입니다._

| 본문 | 의미 | 발생 조건 |
| --- | --- | --- |
| `OK` | 통보 수신 확인 | 검증 통과 후 입금 처리 완료, 또는 이미 결제완료된 거래번호의 중복 통보로 무시 |
| `FAIL` | 통보 거부 | 주문(`no_oid`) 미존재, 거래번호(`no_tid`)·MID(`id_merchant`)·가상계좌번호(`no_vacct`) 불일치, 입금 금액(`amt_input`)이 결제 통화 기준 청구액과 불일치, 결제가 가상계좌·입금대기 상태가 아님, 처리 중 예외 발생 |

`FAIL` 을 반환하면 KG 이니시스가 동일 통보를 최대 10회까지 재시도합니다.

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: text/plain

OK
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | KG 이니시스 공식 발송 IP 화이트리스트에 없는 IP 에서 요청한 경우 (로컬·테스트 환경 제외) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

PC 결제로 발급한 가상계좌의 입금통보를 KG 이니시스 서버로부터 직접 수신하는 웹훅입니다. 구매자가 발급받은 계좌에 실제로 입금하면 KG 이니시스가 이 주소로 통보를 보내고, 서버는 통보를 검증한 뒤 입금대기 상태의 주문을 결제완료로 전환합니다. 이 URL 은 관리자 설정의 가상계좌 통보 URL(PC 웹용)에 표시되며, 그대로 KG 이니시스 가맹점 설정에 등록하면 됩니다.

주의사항: (1) 응답은 JSON 이 아니라 평문 `OK` 또는 `FAIL` 이며, `OK` 를 돌려주지 않으면 KG 이니시스가 최대 10회까지 통보를 재시도합니다. (2) 라우트에 KG 이니시스 공식 발송 IP 화이트리스트가 걸려 있어 허용되지 않은 IP 의 요청은 403 으로 차단됩니다(로컬·테스트 환경 제외). (3) PC 입금통보에는 별도 서명 필드가 없으므로, 발급 시점에 저장한 거래번호·가맹점 MID·가상계좌번호(숫자만 비교)·결제 통화 기준 청구액을 통보 값과 모두 대조하고 결제가 가상계좌·입금대기 상태인지도 확인해 위조 통보를 걸러냅니다. 하나라도 어긋나면 처리하지 않고 `FAIL` 을 반환합니다. (4) 이미 결제완료된 거래번호의 통보는 중복으로 보고 재처리하지 않고 `OK` 를 반환합니다. (5) 은행명·입금자명이 EUC-KR 로 들어오면 UTF-8 로 자동 변환해 저장합니다. (6) 정산·현금영수증 관련 필드들은 통보가 검증 오류로 차단되지 않도록 수신만 허용하며 처리에는 사용하지 않습니다.

예시 시나리오: 구매자가 PC 결제창에서 가상계좌를 선택 → 계좌 발급 후 주문은 입금대기 → 구매자가 계좌 이체 → KG 이니시스가 이 주소로 입금통보 → 검증 통과 시 결제완료 전환 후 `OK` 반환.

---

## 결제 실패 리다이렉트 규약 (브라우저 콜백)

결제창에서 돌아오는 브라우저 콜백은 JSON 응답이 아니라 상점 실패 페이지로 **리다이렉트**하며, 실패 사유를 쿼리스트링으로 전달합니다.

| 쿼리 | 값 | 설명 |
| --- | --- | --- |
| `error` | 실패 코드 | 기계 판독용 고정 식별자 (`authorize_failed` · `approve_failed` 등). 화면 분기·문의 접수의 기준값 |
| `message` | 안내 문구 | 구매자에게 보여 줄 다국어 문구. 상점 실패 페이지가 그대로 출력합니다 |
| `orderId` | 주문번호 | 실패한 주문의 식별자 |

`message` 에는 예외 원문(내부 오류 메시지·SQL 상태코드·클래스명·경로)을 싣지 않습니다. 이 값은 브라우저 주소창과 참조 로그에 남고 실패 페이지에 그대로 출력되므로, 내부 정보가 구매자와 중간 경유지에 노출됩니다. 원인 파악에 필요한 원문은 서버 로그(`Log::error`)에만 기록합니다.
