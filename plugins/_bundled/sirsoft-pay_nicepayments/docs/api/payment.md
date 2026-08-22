# Payment(결제창 서명) API 레퍼런스

> **소유**: plugin `sirsoft-pay_nicepayments` · 사람이 서술한 레퍼런스. 이 경로는 결제창(브라우저) 흐름이 호출하는 web 라우트로, 표준 관리자 JSON API 표면이 아니다.

---

## TL;DR (5초 요약)

```text
1. 결제창 호출 직전 브라우저가 서명값(SignData)을 발급받는 경로다
2. amt(정수)·moid(max 64) 형식 위반은 422 (SignDataRequest — 종전 400 에서 422 로 표준화)
3. 주문 실존·구매자 일치·나이스페이 결제 건 여부를 서버가 재검증한다 (금액 조작 방지)
4. 과도 호출은 429 (주문번호 기준 rate limit)
5. 응답은 envelope 없이 { ediDate, signData, mid } 원시 JSON (결제창 SDK 계약)
```

---

### POST /plugins/sirsoft-pay_nicepayments/payment/sign-data

- **라우트명**: `web.plugins.sirsoft-pay_nicepayments.payment.sign-data`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\PaymentCallbackController@signData`
- **인증/권한**: web 라우트 (세션/비회원 주문 검증은 구매자 대조로 수행)

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
| 422 | `amt`/`moid` 형식 위반 (`SignDataRequest` — 종전 400 응답을 422 로 표준화) / 주문 없음 / 나이스페이 결제 건이 아님 |
| 403 | 구매자 대조 실패 (다른 사용자의 주문번호로 서명 요청) |
| 429 | 주문번호 기준 rate limit 초과 (60초 내 20회) |
