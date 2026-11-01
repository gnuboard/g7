# 에스크로 API 레퍼런스

> **소유**: 플러그인 `sirsoft-pay_nicepayments`. 관리자 주문 상세의 에스크로 배송 등록 화면이 쓰는 JSON API 표면이다. 결제대행사 서버가 직접 호출하는 입금통보 경로([payment.md](payment.md) 의 `payment/vbank-notify`)와는 인증과 접근 제어가 다르다.

---

## TL;DR (5초 요약)

```text
1. 에스크로 결제 건의 배송 정보를 나이스페이먼츠에 등록한다. 에스크로 결제 목록 조회는 orders.md 에 있다
2. Bearer 토큰 + admin 미들웨어 + 이커머스 주문 권한(sirsoft-ecommerce.orders.update)이 필요하다
3. 배송 등록이 성공하면 구매자에게 구매확정 안내가 결제대행사를 통해 자동 발송된다
4. 결제대행사 호출 실패는 502 다. message 는 common.failed 이고 errors.message 에 예외 메시지 원문이 실린다
5. 대상 TID 의 등록 가능한 에스크로 결제(이 플러그인, 에스크로, 결제완료)를 찾지 못하면 422
```

---

## 공통 규약

| 항목 | 값 |
| --- | --- |
| 인증 | `Authorization: Bearer {token}` (`auth:sanctum` + `admin`) |
| 응답 봉투 | 코어 `ResponseHelper` 표준 (`success` / `message` / `data`) |
| 권한 | 배송 등록 `sirsoft-ecommerce.orders.update`, 에스크로 결제 목록 조회 `sirsoft-ecommerce.orders.read` ([orders.md](orders.md)) |

---

## 에스크로 결제 목록 조회

배송 등록 화면이 결제를 고를 때 쓰는 `GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/{orderNumber}/escrow-payments` 는 [orders.md](orders.md) 에서 다룬다.

---

## 에스크로 배송 등록

### POST /api/plugins/sirsoft-pay_nicepayments/admin/escrow/register-delivery
<!-- @generated:start:api.plugins.sirsoft-pay_nicepayments.admin.escrow.register-delivery -->
- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.admin.escrow.register-delivery`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\AdminEscrowController@registerDelivery`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| tid | body | string | 예 | max 30 | 배송을 등록할 에스크로 결제의 나이스페이먼츠 거래번호(TID, 30자). 이 플러그인으로 결제돼 결제완료(`paid`) 상태이고 에스크로(`is_escrow`)로 저장된 결제 레코드의 `transaction_id` 와 같아야 한다 |
| delivery_name | body | string | 예 | max 100 | 택배사명. 나이스페이먼츠 `DeliveryCoNm` 으로 보낸다 |
| tracking_number | body | string | 예 | max 100 | 송장번호. 나이스페이먼츠 `InvoiceNum` 으로 보낸다 |
| buyer_address | body | string | 예 | max 200 | 구매자 배송지 주소. 나이스페이먼츠 `BuyerAddr` 로 보낸다 |
| register_name | body | string | 예 | max 50 | 배송 정보를 등록하는 사람의 이름. 나이스페이먼츠 `RegisterName` 으로 보낸다 |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_nicepayments/admin/escrow/register-delivery HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "tid": "nicepay20260814012345",
    "delivery_name": "CJ대한통운",
    "tracking_number": "TRACK123456",
    "buyer_address": "서울특별시 강남구 테헤란로 1",
    "register_name": "운영자"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

`data` 는 나이스페이먼츠 배송 등록 응답 원본을 그대로 싣는다(키 이름도 원본 그대로).

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| ResultCode | string | `C000` | 나이스페이먼츠 배송 등록 결과 코드. 성공 응답에서는 항상 `C000` 이다(그 밖의 코드는 502 실패로 돌아간다) |
| ResultMsg | string | `정상처리` | 나이스페이먼츠 배송 등록 결과 메시지 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "ResultCode": "C000",
        "ResultMsg": "정상처리"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 관리자가 아니거나 요구 권한(`sirsoft-ecommerce.orders.update`)이 없는 경우. 권한을 「본인만」 또는 「소유역할」 범위로 받은 관리자가 범위 밖 주문의 거래번호를 보냈거나 이 사이트 주문에 없는 거래번호를 보낸 경우 `auth.scope_denied` |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 422 | Unprocessable Entity | `tid` 로 배송 등록할 수 있는 결제(이 플러그인, 에스크로, 결제완료)를 찾지 못한 경우. `message` 는 `common.failed`, `errors.message` 에 사유. 결제대행사를 부르지 않는다 |
| 502 | Bad Gateway | 결제대행사 호출이 실패했거나 결과 코드가 `C000` 이 아닌 경우. `message` 는 `common.failed`, `errors.message` 에 결제대행사 메시지 또는 호출 오류 |

<!-- @generated:end -->

**설명**

관리자 주문 상세의 에스크로 배송 등록 화면이 부른다. 거래번호로 결제 레코드를 먼저 찾고, 그 결제에 저장된 상점 아이디(MID)와 테스트 모드 여부로 자격을 맞춘 뒤 나이스페이먼츠 배송 등록(요청 구분 `03`, 구매확정 메일 발송 켬)을 호출한다. 등록에 성공하면 구매자에게 구매확정 안내가 결제대행사를 통해 발송된다.

권한 범위 판정은 결제 레코드 조회보다 먼저 한다. 범위가 「전체」인 관리자는 판정하지 않는다.

502 응답의 `message` 는 언제나 `common.failed` 번역문(「처리에 실패했습니다.」)이다. 예외 메시지를 메시지 키 자리에 넘기지 않는 것은, 이미 번역된 문장을 키로 넘기면 키 해석에 실패해 원문이 그대로 `message` 로 나가기 때문이다. 대신 `errors.message` 배열의 첫 항목에 예외 메시지 원문을 그대로 싣는다. 관리자 화면이 서버 로그 없이도 실패 원인을 보여 주게 하려는 것이다. 들어가는 값은 다음과 같다.

| 실패 | `errors.message[0]` |
| --- | --- |
| 결과 코드가 `C000` 이 아님 | 결제대행사 응답의 `ResultMsg` (없으면 `NicePayments escrow delivery registration failed`) |
| 결제대행사가 HTTP 오류로 응답 | `NicePayments delivery reg API error: HTTP {상태코드}` |
| 통신 예외(시간 초과, 연결 실패 등) | HTTP 클라이언트 예외 메시지 원문 |

같은 메시지가 서버 로그(`NicePayments: escrow delivery registration failed`)에도 남는다. 상세: [docs/backend/exceptions.md "예외 → 응답 매핑"](../../../../../docs/backend/exceptions.md).
