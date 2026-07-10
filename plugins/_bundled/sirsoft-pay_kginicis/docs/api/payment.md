# Payment API 레퍼런스

> **소유**: plugin `sirsoft-pay_kginicis` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Payment 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
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

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_kginicis/payment/cbt/checkout-token HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

<!-- @generated:end -->

**설명**

일본 CBT(국경 간) 결제 흐름의 첫 단계로, 프론트엔드 결제창이 후속 해시 생성 요청에 사용할 단기 체크아웃 토큰을 발급합니다. `CbtCheckoutTokenService::issue()` 가 주문번호·금액·구매자(이메일/전화)·요청 IP·User-Agent 를 HMAC-SHA256 으로 봉인한 서명 토큰을 만들어 반환하며, 이 토큰은 hash-data 단계에서 결제 컨텍스트가 위변조되지 않았는지 검증하는 데 쓰입니다. 인증은 필요 없고(결제창에서 직접 호출), 대신 `oid` 기준 IP별 분당 10회 레이트리밋과 일본 결제 활성화·설정 여부, 주문 존재·결제 가능 상태·통화 JPY 여부·구매자 일치·금액 일치를 순차 검증합니다. 필수 파라미터(`oid`, `price`) 누락 시 422, 레이트리밋 초과 시 429, 주문 미존재 시 404, 구매자 검증 실패 시 403, 그 외 결제 불가 조건은 422 로 응답합니다.


### POST /api/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.payment.cbt.hash-data -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.payment.cbt.hash-data`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\CbtHashDataController@generate`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

<!-- @generated:end -->

**설명**

일본 CBT 결제창이 실제로 KG 이니시스에 전송할 위변조 방지 해시(P_HASHDATA)를 생성해 반환합니다. `KgInicisApiService::generateCbtHashData()` 가 일본 가맹점 MID·타임스탬프·금액·주문번호로 해시를 만들며, 인증은 불필요하지만 checkout-token 단계에서 발급한 토큰을 `CbtCheckoutTokenService::verify()` 로 재검증해 동일한 결제 컨텍스트(주문·금액·구매자·IP·UA)에서 온 요청임을 보장합니다. 재생 공격을 막기 위해 타임스탬프 신선도(`isTimestampFresh`)를 확인하고, `oid` 기준 IP별 분당 10회 레이트리밋과 일본 결제 활성화·설정·주문 상태·통화 JPY·구매자 일치·금액 일치를 검증합니다. 파라미터(`oid`, `price`, `timestamp`) 누락·타임스탬프 만료·금액 불일치 등은 422, 레이트리밋 초과 429, 주문 미존재 404, 구매자 검증 또는 토큰 검증 실패는 403 으로 응답합니다.


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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-404 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

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
| price | body | integer | 예 | min 100 | PC 표준결제창 결제 금액(최소 100원). 저장된 주문 청구액과 일치하는지 검증한 뒤 signature 생성에 반영한다. |
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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

PC 표준결제창(KRW)이 요구하는 서명(signature)·검증값(verification)·mKey 를 생성해 반환하는, PC 결제 흐름의 시작점입니다. `KgInicisApiService` 의 `generateSignature()`·`generateVerification()`·`getMKey()` 를 호출해 주문번호·금액·타임스탬프로 만든 서명 세트를 내려주며, 프론트엔드는 이 값으로 KG 이니시스 표준결제창을 호출합니다. 인증은 불필요하나(결제창에서 직접 호출) FormRequest 검증, 재생 공격 방지용 타임스탬프 신선도 확인, `oid` 기준 IP별 분당 20회 레이트리밋, 주문 존재·결제 가능 상태·통화 KRW·구매자 일치·금액 일치 검증과 표준결제 자격증명 설정 여부를 확인합니다. 파라미터 검증 실패·타임스탬프 만료·통화/금액 불일치·자격증명 미설정은 422, 레이트리밋 초과 429, 주문 미존재 404, 구매자 검증 실패 403 으로 응답합니다.


