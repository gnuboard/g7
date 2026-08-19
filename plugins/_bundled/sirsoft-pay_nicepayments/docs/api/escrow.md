# 에스크로 API 레퍼런스

> **소유**: 플러그인 `sirsoft-pay_nicepayments`. 관리자 주문 상세의 에스크로 배송 등록 화면이 쓰는 JSON API 표면이다. 결제대행사 서버가 직접 호출하는 통보 경로([vbank.md](vbank.md))와는 인증·접근 제어가 다르다.

---

## TL;DR (5초 요약)

```text
1. 에스크로(EscrowYN=Y) 결제 건의 TID/상태를 조회하고, 배송 정보를 나이스페이먼츠에 등록한다
2. 두 경로 모두 Bearer 토큰 + admin 미들웨어 + 이커머스 주문 권한이 필요하다
3. 배송 등록이 성공하면 구매자에게 구매확정 안내가 결제대행사를 통해 자동 발송된다
4. 결제대행사 호출 실패는 502 로 구분된다 — 응답 본문에 예외 원문을 싣지 않는다
5. 대상 TID 를 찾지 못하면 422 (등록 가능한 에스크로 결제가 아님)
```

---

## 공통 규약

| 항목 | 값 |
| --- | --- |
| 인증 | `Authorization: Bearer {token}` (`auth:sanctum` + `admin`) |
| 응답 봉투 | 코어 `ResponseHelper` 표준 (`success` / `message` / `data`) |
| 권한 | 조회 `sirsoft-ecommerce.orders.read` · 등록 `sirsoft-ecommerce.orders.update` |

---

## 에스크로 결제 목록 조회

| 항목 | 값 |
| --- | --- |
| 라우트명 | `api.plugins.sirsoft-pay_nicepayments.admin.orders.escrow-payments` |
| 메서드/URI | `GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/{orderNumber}/escrow-payments` |
| 권한 | `sirsoft-ecommerce.orders.read` |

**경로 파라미터**

| 이름 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| `orderNumber` | string | 예 | 주문번호 |

**응답 필드** (`data.escrow_payments[]`)

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| `id` | integer | 결제 레코드 ID |
| `transaction_id` | string | 나이스페이먼츠 TID |
| `payment_method` | string | 결제수단 (가상계좌·카드 등) |
| `payment_status` | string | 결제 상태 |

해당 주문에 에스크로 결제가 없으면 `escrow_payments` 는 빈 배열이다.

**응답 예시**

```json
{
  "success": true,
  "message": "요청이 성공했습니다.",
  "data": {
    "escrow_payments": [
      {
        "id": 812,
        "transaction_id": "nicepay20260814012345",
        "payment_method": "vbank",
        "payment_status": "paid"
      }
    ]
  }
}
```

---

## 에스크로 배송 등록

| 항목 | 값 |
| --- | --- |
| 라우트명 | `api.plugins.sirsoft-pay_nicepayments.admin.escrow.register-delivery` |
| 메서드/URI | `POST /api/plugins/sirsoft-pay_nicepayments/admin/escrow/register-delivery` |
| 권한 | `sirsoft-ecommerce.orders.update` |

**요청 파라미터**

| 이름 | 타입 | 필수 | 제약 | 설명 |
| --- | --- | --- | --- | --- |
| `tid` | string | 예 | 최대 30자 | 대상 에스크로 결제의 나이스페이먼츠 TID (나이스페이먼츠 TID 규격 30자 — 초과 시 422) |
| `delivery_name` | string | 예 | 최대 100자 | 택배사명 |
| `tracking_number` | string | 예 | 최대 100자 | 송장번호 |
| `buyer_address` | string | 예 | 최대 200자 | 구매자 배송지 |
| `register_name` | string | 예 | 최대 50자 | 등록자명 |

**동작**

나이스페이먼츠에 배송 정보를 등록한다. 등록이 완료되면 구매자에게 구매확정 안내가 결제대행사를 통해 자동 발송된다.

**응답 예시**

```json
{
  "success": true,
  "message": "요청이 성공했습니다.",
  "data": {
    "ResultCode": "0000",
    "ResultMsg": "정상처리"
  }
}
```

**에러**

| 상태코드 | 사유 | 응답 |
| --- | --- | --- |
| 401 | 토큰 누락·만료 | 코어 표준 |
| 403 | `sirsoft-ecommerce.orders.update` 권한 없음 | 코어 표준 |
| 422 | 요청 파라미터 검증 위반, 또는 해당 TID 로 등록 가능한 에스크로 결제를 찾지 못함 | `error.errors.message` 에 사유 |
| 502 | 결제대행사 호출 실패 (네트워크·응답 파싱·자격증명 오류 등) | `common.failed` |

502 응답 본문에는 예외 원문을 싣지 않는다 — 원인은 서버 로그(`NicePayments: escrow delivery registration failed`)에만 기록된다. 이미 번역된 예외 메시지를 응답의 메시지 키 자리에 넘기면 키 해석에 실패해 내부 오류 원문이 그대로 관리자 화면에 노출되기 때문이다. 상세: [docs/backend/exceptions.md "예외 → 응답 매핑"](../../../../../docs/backend/exceptions.md).
