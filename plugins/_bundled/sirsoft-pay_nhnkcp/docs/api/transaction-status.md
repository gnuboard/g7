# 거래 조회 API 레퍼런스

> **소유**: 플러그인 `sirsoft-pay_nhnkcp`. 관리자 주문 상세에서 이 주문의 NHN KCP 거래 상태·취소·환불 현황을 조회한다.

---

## TL;DR (5초 요약)

```text
1. 관리자 주문 상세의 "거래 조회" 가 호출하는 단일 엔드포인트다
2. 이 주문에 nhnkcp 결제가 없으면 data 는 null (오류 아님)
3. 금액 표기(*_formatted)는 주문 시점 기준 통화를 따른다 (원화 고정 아님)
4. 취소·환불 금액 원본은 숫자 필드로 함께 제공된다
5. 관리자 인증 + 주문 조회 권한이 필요하다
```

---

## 거래 상태 조회

| 항목 | 값 |
| --- | --- |
| 메서드/URI | `GET /api/plugins/sirsoft-pay_nhnkcp/admin/orders/{orderNumber}/transaction-status` |
| 인증 | Bearer 토큰 (관리자) |
| 권한 | 주문 조회 권한 (`sirsoft-ecommerce.orders.read`) — 미보유 시 403 |

### 경로 파라미터

| 이름 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| `orderNumber` | string | 필수 | 조회할 주문번호 |

### 응답 필드 (`data`)

이 주문에 NHN KCP 결제 기록이 없으면 `data` 는 `null` 이다.

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| `tno` | string | KCP 거래번호 |
| `app_no` | string\|null | 승인번호 |
| `use_pay_method` | string\|null | 실제 사용된 결제수단 코드 |
| `app_time` | string\|null | 승인 일시 (KCP 원본 형식) |
| `res_cd` | string | KCP 응답 코드 (`0000` = 정상) |
| `card_name` | string\|null | 카드사명 또는 은행명 |
| `account` | string\|null | 가상계좌 번호 |
| `bank_name` | string\|null | 입금 은행명 |
| `payment_status` | string | 결제 상태 코드 |
| `payment_status_label` | string | 결제 상태 표시명 (요청 언어) |
| `payment_status_variant` | string | 상태 뱃지 색상 구분 |
| `cancelled_amount` | number | 취소 금액 (주문 시점 기준 통화) |
| `cancelled_amount_formatted` | string | 취소 금액 표기 — **주문 시점 기준 통화의 기호·자릿수** |
| `cancelled_at` | string\|null | 취소 일시 |
| `cancel_history` | array | 부분취소 이력 |
| `refund_number` | string\|null | 환불 번호 |
| `refund_status` | string\|null | 환불 상태 코드 |
| `refund_status_label` | string\|null | 환불 상태 표시명 |
| `refund_status_variant` | string\|null | 환불 상태 뱃지 색상 구분 |
| `refund_amount` | number | 환불 금액 (주문 시점 기준 통화) |
| `refund_amount_formatted` | string | 환불 금액 표기 — **주문 시점 기준 통화의 기호·자릿수** |
| `refunded_at` | string\|null | 환불 완료 일시 |
| `refund_pg_transaction_id` | string\|null | 환불 거래번호 |
| `payment_method_display_label` | string | 화면 표시용 결제수단명 |
| `_is_test_mode` | boolean | 테스트 결제 여부 |

> **금액 표기 통화**: `*_formatted` 는 주문 스냅샷의 기준 통화(`currency_snapshot.base_currency`)로 포맷한다. 운영자가 이후 상점 기본 통화를 바꿔도 과거 주문의 표기는 변하지 않는다. 스냅샷이 없는 예전 주문은 현재 기본 통화로 표기한다.

### 요청 예시

```http
GET /api/plugins/sirsoft-pay_nhnkcp/admin/orders/20260807-0001/transaction-status
Authorization: Bearer {token}
```

### 응답 예시

```json
{
  "success": true,
  "message": "성공적으로 처리되었습니다.",
  "data": {
    "tno": "26438048818473",
    "app_no": "00812345",
    "use_pay_method": "CARD",
    "app_time": "20260807153012",
    "res_cd": "0000",
    "card_name": "신한카드",
    "account": null,
    "bank_name": null,
    "payment_status": "cancelled",
    "payment_status_label": "결제취소",
    "payment_status_variant": "danger",
    "cancelled_amount": 1100,
    "cancelled_amount_formatted": "1,100원",
    "cancelled_at": "2026-08-07 15:40:22",
    "cancel_history": [],
    "refund_number": "RF-20260807-0001",
    "refund_status": "completed",
    "refund_status_label": "환불완료",
    "refund_status_variant": "success",
    "refund_amount": 1100,
    "refund_amount_formatted": "1,100원",
    "refunded_at": "2026-08-07 15:41:03",
    "refund_pg_transaction_id": "26438048818473",
    "payment_method_display_label": "신용카드 (NHN KCP)",
    "_is_test_mode": true
  }
}
```

### 결제 기록이 없을 때

```json
{
  "success": true,
  "message": "성공적으로 처리되었습니다.",
  "data": null
}
```
