# Vbank / 통보 수신 API 레퍼런스

> **소유**: 플러그인 `sirsoft-pay_nicepayments`. 이 문서는 결제대행사(나이스페이먼츠) 서버가 직접 POST 로 보내는 통보 수신 경로를 서술한다. 브라우저가 접속하는 결제 콜백과는 접근 제어가 다르다.

---

## TL;DR (5초 요약)

```text
1. 나이스페이먼츠 서버가 직접 보내는 가상계좌 입금통보를 받는 경로다
2. 통보 수신 경로는 나이스페이먼츠 공식 발신 IP 만 허용한다 (위변조·재처리 방어)
3. IP 화이트리스트는 코어 확장 미들웨어 self-gate 로 통보 라우트명에만 적용된다
4. 브라우저 결제 콜백(payment.callback)에는 IP 확인이 적용되지 않는다
5. 검사 동작·허용 범위는 라우트 파일 직접 부착 시절과 동일하다
```

---

## 통보 수신 경로

| 라우트명 | 메서드/URI | 용도 |
| --- | --- | --- |
| `web.plugins.sirsoft-pay_nicepayments.payment.vbank-notify` | `POST /plugins/sirsoft-pay_nicepayments/payment/vbank-notify` | 가상계좌 입금통보 수신 |

**설명**

위 경로는 나이스페이먼츠 서버가 구매자의 가상계좌 실입금을 가맹점에 알리기 위해 직접 POST 로 호출한다. 브라우저를 거치지 않는 서버 대 서버 통신이므로, 위변조·재처리 요청을 막기 위해 **나이스페이먼츠 공식 발신 IP 만 허용**한다.

이 IP 화이트리스트 검사는 코어의 확장 미들웨어 self-gate(`Plugin::getMiddleware()` 의 `targets` 로 위 통보 라우트명에만 정밀 타게팅)로 수행된다. 브라우저가 접속하는 결제 콜백(`payment.callback`)에는 적용되지 않는다 — 콜백은 정상 사용자의 브라우저에서 임의 IP 로 도달하므로 IP 로 제한하면 결제가 끊긴다. 검사 자체의 동작·허용 범위는 라우트 파일에서 직접 부착하던 이전 방식과 동일하다.

상세: [docs/backend/middleware.md "확장 미들웨어 선언 (self-gate)"](../../../../../docs/backend/middleware.md).

---

## 입금 완료 건 환불 (관리자 JSON API)

### POST /api/plugins/sirsoft-pay_nicepayments/admin/vbank-refund

- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.vbank.refund`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\AdminVbankRefundController@refund`
- **인증/권한**: `auth:sanctum` + `permission:admin,sirsoft-ecommerce.orders.update`

입금이 완료된 가상계좌는 일반 환불 훅으로 처리할 수 없다 — 나이스페이먼츠가 환불받을 계좌 정보(계좌번호·은행코드·예금주)를 요구하기 때문이다. 이 경로가 해당 정보를 수집해 취소 API 를 호출하고 주문 취소·결제 상태 반영까지 수행한다.

**요청 파라미터** (형식·길이 위반은 PG 호출 전에 422 — `VbankRefundRequest`)

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| tid | body | string | 예 | max 30 | 거래번호(TID) |
| moid | body | string | 예 | max 64 | 주문번호(MOID) |
| cancel_amt | body | integer | 예 | 1 ~ 999999999999 | 취소 금액. 결제 금액과 불일치 시 422 |
| cancel_msg | body | string | 아니오 | max 100 | 취소 사유. 미입력 시 기본 문구 사용 |
| refund_acct_no | body | string | 예 | 숫자만, max 16 | 환불 계좌번호 |
| refund_bank_cd | body | string | 예 | 숫자 3자리 | 환불 은행코드 |
| refund_acct_nm | body | string | 예 | max 10 | 환불 예금주명 |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_nicepayments/admin/vbank-refund HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "tid": "nictest00m02012104191651325596",
    "moid": "ORD-20260817-000001",
    "cancel_amt": 30000,
    "cancel_msg": "구매자 요청 환불",
    "refund_acct_no": "1234567890123",
    "refund_bank_cd": "004",
    "refund_acct_nm": "홍길동"
}
```

**응답**

- 성공(200): `common.success` envelope, `data` 는 나이스페이 취소 API 원본 응답.
- 실패:

| 상태 | 조건 |
| --- | --- |
| 422 | 입력 형식 위반 (`VbankRefundRequest` — PG 미호출) / 결제 상태가 환불 대상 아님 / 취소 금액 불일치 |
| 404 | 주문번호에 해당하는 주문 없음 |
| 409 | 동일 건 환불이 이미 처리 중 |
| 502 | PG 취소 API 실패 또는 취소 후 주문 반영 실패 (서버 로그에 원문 기록) |
