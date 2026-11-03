# Vbank(가상계좌 관리자) API 레퍼런스

> **소유**: 플러그인 `sirsoft-pay_nicepayments`. 관리자 화면이 쓰는 가상계좌 JSON API 두 경로(입금통보 수신 주소 조회, 입금 완료 건 환불)를 서술한다. 나이스페이먼츠 서버가 직접 POST 로 보내는 입금통보 수신 경로(`payment/vbank-notify`)는 [payment.md](payment.md) 에 있다.

---

## TL;DR (5초 요약)

```text
1. 관리자 설정 화면에 나이스페이먼츠 상점관리자에 등록할 입금통보 주소를 보여 준다
2. 그 주소는 요청 Host 가 아니라 사이트 주소(APP_URL) 기준 절대 URL 이다
3. 입금이 끝난 가상계좌는 환불 계좌 정보를 받아 이 플러그인의 환불 경로로 취소한다
4. 환불 경로는 PG 취소와 주문 취소까지 하므로 sirsoft-ecommerce.claims.execute-refund 권한이 필요하다
5. 입금통보 수신 경로의 발신 IP 제한과 입금 확인 절차는 payment.md 에 있다
```

---

## 입금통보 수신 경로

나이스페이먼츠 서버가 보내는 가상계좌 입금통보(`POST /plugins/sirsoft-pay_nicepayments/payment/vbank-notify`, 라우트명 `web.plugins.sirsoft-pay_nicepayments.payment.vbank-notify`)의 요청 규격, 발신 IP 제한, 입금 확인 절차는 [payment.md](payment.md) 의 같은 경로 절에서 다룬다.

---

## 관리자 JSON API

### GET /api/plugins/sirsoft-pay_nicepayments/admin/vbank-notify-url
<!-- @generated:start:api.plugins.sirsoft-pay_nicepayments.admin.vbank.notify.url -->
- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.admin.vbank.notify.url`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_nicepayments/admin/vbank-notify-url HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| url | string | `https://example.com/plugins/sirsoft-p…` | 가상계좌 입금통보 수신 전체 URL. 사이트 주소(`APP_URL`, `SiteUrl::absolute()`) 기준이며 요청 Host 는 따르지 않는다. 사이트 주소가 비어 있으면 경로(`/plugins/sirsoft-pay_nicepayments/payment/vbank-notify`)만 내려간다 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "data": {
        "url": "https://api.example.com/plugins/sirsoft-pay_nicepayments/payment/vbank-notify"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 관리자가 아니거나 요구 권한(`sirsoft-ecommerce.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

관리자 설정 화면이 나이스페이먼츠 상점관리자에 등록할 가상계좌 **입금통보 수신 주소**를 보여 주는 조회다. 컨트롤러 없이 라우트 클로저(`src/routes/api.php`)가 응답하며 `message` 키 없이 `success` 와 `data` 만 싣는다. 라우트 그룹의 `admin` 미들웨어도 함께 거친다.

주소는 **사이트 설정의 기준 주소**(`APP_URL`)에서 만들어진다(`SiteUrl::absolute()`). 이 요청이 어떤 호스트로 들어왔는지는 보지 않는다. 앞단에 프록시나 로드밸런서를 두었거나 사이트가 하위 폴더(`https://example.com/shop/`)에 설치된 배치에서도 결제대행사가 실제로 접속할 수 있는 하나의 주소가 나오게 하기 위함이다. 사이트 주소가 비어 있으면 경로(`/plugins/sirsoft-pay_nicepayments/payment/vbank-notify`)만 내려간다. 표시된 주소가 바깥에서 닿지 않는다면 먼저 **환경설정의 사이트 주소**가 실제 서비스 주소와 같은지 확인한다.

주소가 가리키는 수신 경로의 동작과 발신 IP 제한은 [payment.md](payment.md) 의 `POST /plugins/sirsoft-pay_nicepayments/payment/vbank-notify` 절을 본다. 같은 설정 화면의 테스트 모드 상태 조회는 [settings.md](settings.md) 에 있다.


### POST /api/plugins/sirsoft-pay_nicepayments/admin/vbank-refund
<!-- @generated:start:api.plugins.sirsoft-pay_nicepayments.admin.vbank.refund -->
- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.admin.vbank.refund`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\AdminVbankRefundController@refund`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.execute-refund`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| tid | body | string | 예 | max 30 | 거래번호(TID). 주문 결제 레코드의 `transaction_id` 와 같아야 한다 |
| moid | body | string | 예 | max 64 | 주문번호(MOID). 이 주문의 결제를 환불한다 |
| cancel_amt | body | integer | 예 | 1 ~ 999999999999 | 취소 금액. 결제 레코드의 취소 가능 금액과 같아야 하며 다르면 422 (전액 취소만 받는다) |
| cancel_msg | body | string | 아니오 | max 100 | 취소 사유. 미입력 시 기본 문구(`가상계좌 환불`) 사용 |
| refund_acct_no | body | string | 예 | 숫자만, max 16 | 환불받을 계좌번호 |
| refund_bank_cd | body | string | 예 | 숫자 3자리 | 환불받을 계좌의 은행코드 (예: `004`) |
| refund_acct_nm | body | string | 예 | max 10 | 환불받을 계좌의 예금주명 |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_nicepayments/admin/vbank-refund HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "tid": "nictest00m02012104191651325596",
    "moid": "20260817-1043226571",
    "cancel_amt": 30000,
    "cancel_msg": "구매자 요청 환불",
    "refund_acct_no": "1234567890123",
    "refund_bank_cd": "004",
    "refund_acct_nm": "홍길동"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

`data` 는 나이스페이먼츠 취소 API 응답 원본을 UTF-8 로 바꿔 그대로 싣는다(키 이름도 원본 그대로).

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| ResultCode | string | `2001` | 취소 결과 코드. 성공 응답은 `2001` 또는 `2211` 이다(그 밖의 코드는 502 실패로 돌아간다) |
| ResultMsg | string | `취소 성공` | 취소 결과 메시지 |
| TID | string | `nictest00m02012104191651325596` | 취소된 거래번호. 값이 있으면 요청 `tid` 와 같아야 하며 다르면 502 |
| CancelAmt | string | `30000` | 취소 금액. 값이 있으면 요청 `cancel_amt` 와 같아야 하며 다르면 502 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "ResultCode": "2001",
        "ResultMsg": "취소 성공",
        "TID": "nictest00m02012104191651325596",
        "CancelAmt": "30000"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 관리자가 아니거나 요구 권한(`sirsoft-ecommerce.claims.execute-refund`)이 없는 경우. 권한을 「본인만」 또는 「소유역할」 범위로 받은 관리자에게 `tid` 의 주문이나 `moid` 의 주문이 범위 밖이면 `auth.scope_denied` (환불과 주문 취소를 하지 않는다) |
| 404 | Not Found | `moid` 에 해당하는 주문이 없는 경우 (`errors.message`: 환불할 주문을 찾을 수 없습니다.) |
| 409 | Conflict | 같은 결제의 환불이 이미 처리 중인 경우 (`errors.message`: 이미 가상계좌 환불 처리가 진행 중입니다.) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). 결제대행사를 부르지 않는다 |
| 422 | Unprocessable Entity | 주문이 결제완료 상태가 아니거나 취소되지 않은 옵션이 없음, 결제가 이 플러그인의 입금 완료 가상계좌 결제가 아님, `tid` 불일치, 이미 환불됨 (`errors.message`: 환불 가능한 나이스페이먼츠 가상계좌 입금완료 결제가 아닙니다.) |
| 422 | Unprocessable Entity | `cancel_amt` 가 취소 가능 금액과 다른 경우 (`errors.message`: 요청 환불 금액이 DB의 취소 가능 금액과 일치하지 않습니다.) |
| 502 | Bad Gateway | 결제대행사 취소 호출이 실패했거나, 응답의 거래번호 또는 금액이 요청과 다르거나, 결제대행사 취소 후 주문 취소 반영이 실패한 경우 (`message` 는 `common.failed`, 원인은 서버 로그와 결제 메타 `vbank_refund_error` 에 남는다) |

<!-- @generated:end -->

**설명**

입금이 완료된 가상계좌는 일반 환불 훅으로 처리할 수 없다. 나이스페이먼츠가 환불받을 계좌 정보(계좌번호, 은행코드, 예금주)를 요구하기 때문이다. 이 경로가 그 정보를 받아 취소 API 를 부르고 주문 취소와 결제 상태 반영까지 한다.

요청 형식과 길이 위반은 결제대행사를 부르기 전에 422 로 끝난다(`VbankRefundRequest`). 성공 응답은 `common.success` 봉투이고 `data` 는 나이스페이먼츠 취소 API 원본 응답이다. 502 응답에는 `errors` 가 없고, 원인은 서버 로그(`NicePayments: admin vbank refund failed`)와 결제 메타 `vbank_refund_error` 에 남는다. 같은 결제 메타의 `vbank_refund_status` 는 결제대행사 취소 전에 실패하면 `failed`, 취소는 됐지만 주문 취소 반영이 실패하면 `pg_cancelled_domain_failed` 다.

7.1.0 부터 PG 취소와 주문 취소까지 하므로 「환불 실행」 권한(`sirsoft-ecommerce.claims.execute-refund`)이 필요하다.
