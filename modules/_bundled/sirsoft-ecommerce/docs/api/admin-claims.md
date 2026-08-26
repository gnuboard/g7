# Admin Claims API 레퍼런스

> **소유**: 모듈 `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반).
> 이 문서는 관리자 클레임(취소/반품/교환 관리) 엔드포인트를 다룹니다.
> 회원 경로는 [claims.md](claims.md), 비회원 경로는 [guest.md](guest.md), 추가결제는 [claim-payments.md](claim-payments.md), 사유 카탈로그는 [claim-reasons.md](claim-reasons.md) 를 보세요.
> 응답 리소스의 공통 필드 표(ClaimResource/ClaimItemResource/ClaimPaymentResource/ClaimImageResource)는 [claims.md](claims.md#공통-리소스) 가 정본입니다.

## TL;DR (5초 요약)

```text
1. 목록은 유형 서브탭 단위 — type 파라미터(기본 exchange)로 한 유형만 받고, counts 로 4유형 건수 뱃지를 함께 준다
2. 유형 스코프 경로는 /admin/{exchanges|returns|refunds}/{id} — 취소(cancel)는 목록에만 있고 상세·전이 대상이 아니다
3. 상태 쓰기 어휘는 유형별 adminAssignableValues() — requested/paid/withdrawn 직접 지정 불가 (환불은 approved/on_hold/rejected 만). 미수납 승인은 pay_on_approval 결제 요청 우회 / pay_on_request 422
4. 완료 전이(status=completed)는 completion 객체 필수 — 모달이 실행 전에 보여줄 값은 claims/completion-preview 가 SSoT (D26)
5. 금액 수정({claimType}/{id}/amounts)·환불 실행(refunds/{id}/execute)의 상한은 취소 가능 금액(D19) — 초과는 저장·실행 모두 422, 재실행은 409, 근거는 orders/{order}/cancellable-amount 가 같은 식으로 답한다
```

## 인증·권한

전 엔드포인트 `auth:sanctum` + 관리자(admin) 미들웨어. 세부 권한:

| 권한 | 대상 |
| --- | --- |
| `sirsoft-ecommerce.claims.read` | 목록·상세 조회·취소 가능 금액 |
| `sirsoft-ecommerce.claims.update-status` | 전이(단건·일괄·상품 단위)·송장·첨부·완료 미리보기·환불신청서 수동 접수 |
| `sirsoft-ecommerce.claims.update-amounts` | 금액 수정 (D18) |
| `sirsoft-ecommerce.claims.execute-refund` | 환불 실행 ('환불하기') |

## 엔드포인트 목록

`{claimType}` 은 `exchanges` / `returns` / `refunds` 중 하나입니다 (표기된 예외 제외).

| 메서드 | URI | 라우트명 |
| --- | --- | --- |
| GET | /api/modules/sirsoft-ecommerce/admin/claims | `api.modules.sirsoft-ecommerce.admin.claims.index` |
| POST | /api/modules/sirsoft-ecommerce/admin/claims/bulk-status | `api.modules.sirsoft-ecommerce.admin.claims.bulk-status` |
| GET | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id} | `api.modules.sirsoft-ecommerce.admin.claims.show` |
| PATCH | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/status | `api.modules.sirsoft-ecommerce.admin.claims.status` |
| POST | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/images | `api.modules.sirsoft-ecommerce.admin.claims.images.store` |
| DELETE | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/images | `api.modules.sirsoft-ecommerce.admin.claims.images.clear` |
| PATCH | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/options/bulk-status | `api.modules.sirsoft-ecommerce.admin.claims.options.bulk-status` (교환·반품만) |
| PUT | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/collection-tracking | `api.modules.sirsoft-ecommerce.admin.claims.collection-tracking` (교환·반품만) |
| PUT | /api/modules/sirsoft-ecommerce/admin/exchanges/{id}/redelivery-tracking | `api.modules.sirsoft-ecommerce.admin.claims.redelivery-tracking` (교환만) |
| PUT | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/amounts | `api.modules.sirsoft-ecommerce.admin.claims.amounts` |
| POST | /api/modules/sirsoft-ecommerce/admin/claims/completion-preview | `api.modules.sirsoft-ecommerce.admin.claims.completion-preview` (교환·반품만) |
| POST | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/refund-request | `api.modules.sirsoft-ecommerce.admin.claims.refund-request` (교환·반품만) |
| POST | /api/modules/sirsoft-ecommerce/admin/refunds/{id}/execute | `api.modules.sirsoft-ecommerce.admin.claims.refunds.execute` (환불만) |
| GET | /api/modules/sirsoft-ecommerce/admin/orders/{order}/cancellable-amount | `api.modules.sirsoft-ecommerce.admin.claims.cancellable-amount` |
| GET | /api/modules/sirsoft-ecommerce/admin/orders/{order}/claims | `api.modules.sirsoft-ecommerce.admin.claims.order-claims` |

오류 응답의 두 형태(요청 검증 422 는 `errors` 동반 / 도메인 거절 422 는 `message` 만)는
[claims.md](claims.md#오류-응답의-두-형태-422) 와 같은 계약입니다.

---

### GET /api/modules/sirsoft-ecommerce/admin/claims

클레임 통합 목록을 유형 서브탭 단위로 조회합니다. 응답에 4유형 건수(`counts`)가 함께 실려
서브탭 뱃지를 그립니다.

**권한**: `sirsoft-ecommerce.claims.read`

**쿼리 파라미터**

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| type | string | 아니오 | 조회 유형 (`exchange`/`return`/`refund`/`cancel`, 기본 `exchange`) |
| statuses[] | string[] | 아니오 | 상태 다중 필터 — 어휘는 **조회 유형의 상태 Enum 전체**로 게이트 (교환 14 / 반품 11 / 환불 7 / 취소 2), 다른 유형 값은 422 |
| from | date | 아니오 | 신청일 시작 (그날 00:00:00 부터) |
| to | date | 아니오 | 신청일 끝 (그날 23:59:59 까지, `from` 이후) |
| search_field | string | 아니오 | 검색 항목 (`claim_number`/`order_number`/`requester`/`product_name`) |
| search_keyword | string | 아니오 | 검색어 (max 200, 부분 일치) |
| fault_types[] | string[] | 아니오 | 귀책 필터 (`customer`/`seller`/`carrier`) — `type=cancel` 에는 사용 불가(422) |
| charge_methods[] | string[] | 아니오 | 청구 방식 필터 (`pay_on_request`/`pay_on_approval`/`deduct_from_refund`) — `type=cancel`/`refund` 에는 사용 불가(422) |
| reason_code | string | 아니오 | 사유 코드 (max 50 — 취소는 `cancel_reason_type` 대조) |
| requester_type | string | 아니오 | 회원구분 (`member`/`guest`) |
| min_amount | numeric | 아니오 | 금액 하한 (min 0). 근거 컬럼은 유형마다 다르며 **목록 `amount` 컬럼이 그리는 값과 같다** — 교환·반품은 총 청구액, 환불은 환불액 + 환불 마일리지, 취소는 연결된 환불신청서의 같은 합계 |
| max_amount | numeric | 아니오 | 금액 상한 (min 0). `min_amount` 를 **함께 보낸 경우에만** 그 값 이상이어야 한다(422) — 상한만 거는 조합은 정상이다 |
| currency_code | string | 아니오 | 통화 필터(max 10). 어휘는 상점 설정의 **등록 통화(`language_currency.currencies[].code`) + 운영자가 내린 기본 제공 통화(`language_currency.removed_default_currencies`)** 이고, 그 밖의 코드는 **그 유형의 신청서에 실제로 기록돼 있을 때만** 통과한다(교환·반품은 자기 `currency_code`, 환불·취소는 주문의 결제 통화). 거르는 값이 거래 시점에 동결된 통화라 설정에서 내려간 통화도 계속 조회할 수 있어야 하며, 관리자가 직접 추가했다 삭제한 통화는 설정에 흔적이 남지 않으므로 기록이 마지막 근거다. 어디에도 쓰인 적 없는 코드는 422 |
| charge_payment_methods[] | string[] | 아니오 | 청구 결제수단 필터 — 추가결제(`ecommerce_claim_payments.payment_method`)가 성립하는 `type=exchange`/`return` 에만 있다. `type=cancel`/`refund` 에는 사용 불가(422). 어휘는 클레임 설정 `claim.charge_payment_methods` 이며, 그 밖의 값은 통화와 같은 규칙으로 **실제 추가결제 기록이 있을 때만** 통과한다(운영자가 수단을 내려도 그 수단으로 청구된 건은 남는다). 쓰인 적 없는 값은 422 |
| page | integer | 아니오 | 페이지 (min 1, 상한은 코어 `PaginationLimits::maxPage('ecommerce.claims')`) |
| per_page | integer | 아니오 | 페이지당 건수 (1~100, 기본 20) |

**목록 행 필드 (축소 표현)** — 상세의 `items`/`images`/결제 배열은 싣지 않습니다.

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `31` | 신청서 기본 키 |
| claim_number | string | `RT20260825000007` | 클레임 번호 |
| claim_type / claim_type_label | string | `return` / `반품` | 유형과 라벨 |
| order_id / order_number | integer / string\|null | `120` / `20260825-0012` | 주문 |
| requester_name | string\|null | `홍길동` | 신청자 표기 (주문자명 — 비회원 신청도 값이 있다) |
| requested_by | integer\|null | `7` | 신청 회원 ID (비회원 `null`, 취소는 `cancelled_by`) |
| is_guest_request | boolean | `false` | 비회원 신청 여부 |
| status / status_label / status_variant | string / string\|null / string\|null | `requested` / `반품신청` / `secondary` | 유형별 상태 |
| is_final | boolean | `false` | 종결 여부 |
| reason_code / reason_label | string\|null | `changed_mind` / `단순 변심` | 사유 |
| fault_type / fault_type_label | string\|null | `customer` / `고객 귀책` | 귀책 (취소는 `null`) |
| currency_code | string\|null | `KRW` | 금액 통화 (주문 통화 스냅샷 기준) |
| item_summary | object | `{"product_name":"티셔츠","item_count":2,"total_quantity":3}` | 상품 요약 (첫 상품명·건수·수량 합계) |
| requested_at / requested_at_formatted | string\|null | ISO 8601 / 사용자 타임존 표기 | 신청 일시 |
| completed_at_formatted | string\|null | `null` | 종결 일시 |
| charge_method / charge_method_label | string\|null | `pay_on_request` / `신청 시 결제` | 청구 방식 (교환·반품만) |
| total_charge_amount / _formatted | number / string | `3000` / `3,000원` | 청구액 (교환·반품만) |
| outstanding_amount / _formatted | number\|null / string\|null | `3000` / `3,000원` | 미수납 청구액 (교환·반품만) — **미수납 판정은 상태 라벨이 아니라 이 값으로 한다** (분할 수납 건은 결제대기 라벨 그대로다) |
| refund_source_type | string | `direct` | 환불 출처 (환불만) |
| refund_total / _formatted | number\|null / string\|null | `29000` / `29,000원` | 환불 합계 (환불·취소 — 취소는 환불 행 로드 시) |
| cancel_type / cancel_type_label | string\|null | `full` / `전체취소` | 취소 구분 (취소만) |
| refund_status / refund_status_label | string\|null | `completed` / `환불완료` | 취소 발 환불 상태 (취소만) |

**응답 예시 (200)**

```json
{
  "success": true,
  "message": "신청 내역을 조회했습니다.",
  "data": {
    "data": [
      {
        "id": 31,
        "claim_number": "RT20260825000007",
        "claim_type": "return",
        "claim_type_label": "반품",
        "order_id": 120,
        "order_number": "20260825-0012",
        "requester_name": "홍길동",
        "requested_by": 7,
        "is_guest_request": false,
        "status": "requested",
        "status_label": "반품신청",
        "status_variant": "secondary",
        "is_final": false,
        "reason_code": "changed_mind",
        "reason_label": "단순 변심",
        "fault_type": "customer",
        "fault_type_label": "고객 귀책",
        "currency_code": "KRW",
        "item_summary": {"product_name": "티셔츠", "item_count": 1, "total_quantity": 1},
        "requested_at": "2026-08-25T10:12:00+09:00",
        "requested_at_formatted": "2026-08-25 10:12",
        "completed_at_formatted": null,
        "charge_method": "deduct_from_refund",
        "charge_method_label": "환불금 차감",
        "total_charge_amount": 3000,
        "total_charge_amount_formatted": "3,000원",
        "outstanding_amount": 0,
        "outstanding_amount_formatted": "0원"
      }
    ],
    "counts": {"exchange": 3, "return": 5, "refund": 1, "cancel": 8},
    "abilities": {"can_update_status": true, "can_update_amounts": true, "can_execute_refund": true},
    "pagination": {"current_page": 1, "per_page": 20, "from": 1, "to": 5, "has_more_pages": false, "last_page": 1, "total": 5}
  }
}
```

`counts` 는 유형 공통 필터(기간·검색·회원구분)만 반영합니다 — 상태·귀책·청구 필터는 유형마다
어휘가 달라 다른 탭 건수에 적용할 수 없습니다.

---

### GET /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}

신청서 상세를 조회합니다. 응답은 [ClaimResource](claims.md#claim-resource) 전체 표현에
관리자 확장 필드가 **추가**되고, 교환·반품은 결제 블록(`payments`/`outstanding_amount`/
`pending_payment`/`payment_entry`)이 함께 실립니다. 환불 계좌(`refund_bank_account`/
`refund_bank_holder`)의 원문 노출은 `sirsoft-ecommerce.orders.read` 권한이 게이트합니다 —
`claims.read` 만 가진 관리자에게는 마스킹되어 내려갑니다.

**권한**: `sirsoft-ecommerce.claims.read`

**관리자 확장 필드** (유저 응답 키는 그대로, 아래가 더해진다)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| admin_memo | string\|null | `회수 후 재검수 필요` | 관리자 메모 — 교환·반품 전용 (환불은 저장 컬럼이 없어 항상 `null`) |
| processed_by | integer\|null | `1` | 마지막 처리 관리자 ID |
| requester_name | string\|null | `홍길동` | 신청자 표기 (주문자명) |

**응답**: `200` `data.claim` — 위 구조 / `404` 부재.

---

### PATCH /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/status

신청서 상태를 전이합니다.

**권한**: `sirsoft-ecommerce.claims.update-status`

**본문 파라미터**

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| status | string | 예 | 목표 상태 — 어휘는 유형별 `adminAssignableValues()`. 교환·반품은 `requested`/`paid`/`withdrawn` 제외 전체(11/8종), 환불은 `approved`/`on_hold`/`rejected` 3종 |
| reject_reason | string | 아니오 | 반려 사유 (max 2000 — `status=rejected` 일 때 저장) |
| admin_memo | string | 아니오 | 관리자 메모 (max 2000 — 보낸 요청만 갱신, 미포함 시 기존값 유지). **교환·반품 전용** — 환불은 저장 컬럼이 없어 반영되지 않는다 |
| completion | object | `status=completed` 일 때 필수 | 완료 처리 옵션 (D26) |
| completion.restore_stock_by_item | object | 아니오 | 신청 상품 행 ID → 재고 복구 여부 맵 (`{"55": true}`) — 목록에 없는 행은 복구하지 않음 |
| completion.create_refund_request | boolean | completion 과 함께 필수 | 환불신청서를 지금 접수할지 — `false` 면 `order_refunds` 를 만들지 않는다 |

`reject_reason`/`admin_memo` 는 `status=completed` 전이에는 적용되지 않습니다 — 완료 분기는
`completion` 만 처리합니다 (완료 모달 D26 에도 해당 입력이 없다).

**승인과 추가결제의 상호작용**

| 신청서 상태 | 요청 | 결과 |
| --- | --- | --- |
| 미수납 청구 있음 + `pay_on_approval` | `status=approved` | 승인 대신 **결제 요청** — 결제 건(CP) 생성 + 결제대기 전이, 응답 `payment_requested=true`. 결제 확정 시 `paid` 를 거쳐 자동 승인 |
| 미수납 청구 있음 + `pay_on_request` | `status=approved` | `422` `claim_payment_outstanding` — 결제 완료 후 다시 승인 |
| 청구 없음(전액 수납·환불금 차감 포함) | `status=payment_pending` | `422` `claim_payment_not_required` |
| 청구 있음 | `status=payment_pending` | 결제 건 생성(없으면) + 결제대기 전이, `payment_requested=true` |

**응답 예시 (200)**

```json
{
  "success": true,
  "message": "신청 상태가 변경되었습니다.",
  "data": {
    "claim": { "id": 31, "claim_number": "RT20260825000007", "status": "approved", "...": "ClaimResource 전체" },
    "payment_requested": false
  }
}
```

**오류**: `404` 부재 / `422` 어휘 밖 상태·완료 옵션 누락(요청 검증, `errors` 동반) / `422`
전이 게이트 위반 `claim_transition_not_allowed`·미수납 승인 `claim_payment_outstanding`·청구
없음 `claim_payment_not_required`·완료 절차 위반 `claim_completion_flow_required`(도메인 거절,
`message` 만).

---

### POST /api/modules/sirsoft-ecommerce/admin/claims/bulk-status

여러 신청서를 일괄 전이합니다. 게이트 위반 건은 건너뛰고 사유와 함께 보고합니다(부분 성공
계약) — 하나가 막혔다고 전체를 되돌리지 않습니다.

**권한**: `sirsoft-ecommerce.claims.update-status`

**본문 파라미터**

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| type | string | 예 | 유형 (`exchange`/`return`/`refund` — 취소는 전이 대상이 아님) |
| ids[] | integer[] | 예 | 신청서 ID 목록 (1~100건) |
| status | string | 예 | 목표 상태 (단건 전이와 같은 어휘 게이트) |
| reject_reason / admin_memo | string | 아니오 | 단건 전이와 동일 |
| completion | object | `status=completed` 일 때 필수 | 단건 전이와 동일 계약 — `restore_stock_by_item` 은 전 신청서의 상품 행 ID 를 한 맵에 싣는다 |

**응답 예시 (200)**

```json
{
  "success": true,
  "message": "일괄 상태 변경을 처리했습니다.",
  "data": {
    "succeeded": [31],
    "payment_requested": [34],
    "skipped": [
      {"id": 35, "claim_number": "RT20260825000009", "message": "반품반려 에서 반품승인 로는 상태를 변경할 수 없습니다."},
      {"id": 999999, "claim_number": null, "message": "해당 신청서를 찾을 수 없습니다."}
    ]
  }
}
```

`payment_requested` 는 승인 지시가 결제 요청으로 우회된 신청서 목록입니다 — 승인됨과 결제
요청됨은 운영자에게 다른 결과라 따로 보고합니다.

---

### PATCH /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/options/bulk-status

신청 상품 단위로 상태를 일괄 전이합니다 (D25 — 체크한 상품 배열). 교환·반품만.

**권한**: `sirsoft-ecommerce.claims.update-status`

**본문 파라미터**

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| items[] | integer[] | 예 | 신청 상품 행 ID 목록 (1~200건) — **이 신청서에 속한 행만** 통과(다른 신청서의 행은 422 `errors`) |
| status | string | 예 | 목표 상태 — 유형별 관리자 어휘에서 `payment_pending` 제외 (결제는 신청서 단위 축) |
| completion | object | `status=completed` 일 때 필수 | 단건 전이와 동일 계약 (선택 상품만 이번 라운드에 완료 — 부분 완료) |

상품 단위 전이에는 메모·반려 사유 파라미터가 없습니다 — 상품 행에는 저장 지점이 없고,
신청서(헤더) 메모는 단건 전이(`{id}/status`)가 담당합니다.

**응답 예시 (200)**

```json
{
  "success": true,
  "message": "신청 상품 상태 변경을 처리했습니다.",
  "data": {
    "succeeded": [55],
    "skipped": [{"id": 56, "message": "반품반려 에서 반품회수중 로는 상태를 변경할 수 없습니다."}],
    "claim": { "id": 31, "status": "collecting", "...": "ClaimResource 전체" }
  }
}
```

헤더 상태는 살아 있는 상품이 모두 같은 상태에 도달했을 때 따라갑니다(`claim` 으로 확인).

---

### PUT /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/collection-tracking

회수 운송장을 등록합니다 (교환·반품). 회수준비중이면 회수중 전이가 함께 일어납니다.

**권한**: `sirsoft-ecommerce.claims.update-status`

**본문 파라미터**

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| carrier_id | integer | 아니오* | 택배사 ID (`ecommerce_shipping_carriers`) |
| tracking_number | string | 아니오* | 운송장 번호 (max 100) |

\* 둘 중 하나만 보내면 `422` `claim_tracking_incomplete` — 함께 입력해야 합니다.

**응답**: `200` `data.claim` (ClaimResource — `collection_tracking_number`/`collection_tracking_url` 갱신) / `404` / `422`.

### PUT /api/modules/sirsoft-ecommerce/admin/exchanges/{id}/redelivery-tracking

재발송 운송장을 등록합니다 (교환 전용). 재발송준비중이면 재발송중 전이가 함께 일어납니다.
파라미터·응답·오류는 회수 송장과 동일하며 `redelivery_*` 필드가 갱신됩니다.

---

### POST /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/images

운영자 대리 첨부를 업로드합니다 (이미 귀속된 신청서에 추가 — 예: 회수 상품 검수 사진).

**권한**: `sirsoft-ecommerce.claims.update-status`

**본문 파라미터** (multipart/form-data)

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| image | file | 예 | 이미지 파일 (`jpeg`/`png`/`jpg`/`gif`/`webp`, 최대 용량 `claim.image_max_size_kb` 설정) |

**응답 예시 (201)**

```json
{
  "success": true,
  "message": "이미지를 첨부했습니다.",
  "data": {
    "image": {"id": 12, "hash": "a1b2c3d4e5f6", "claimable_type": "return", "claimable_id": 31, "original_name": "evidence.jpg", "download_url": "/api/modules/sirsoft-ecommerce/claim-image/a1b2c3d4e5f6?...signature=..."}
  }
}
```

**오류**: `404` 신청서 부재 / `422` 파일 형식·용량·장수 상한(`claim.max_images`) 초과.

### DELETE /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/images

신청서의 첨부를 파일까지 일괄 삭제합니다 (반려·종결 건 정리).

**권한**: `sirsoft-ecommerce.claims.update-status`

**응답 예시 (200)**

```json
{"success": true, "message": "첨부 이미지를 모두 삭제했습니다.", "data": {"deleted": 2}}
```

**오류**: `404` 신청서 부재.

---

### PUT /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/amounts

신청서 금액을 수정합니다 (D18). 유형에 따라 받는 축이 다르며, 다른 유형의 축을 보내면
요청 검증 `422`(`errors` 동반)로 명시 거절합니다 — 조용히 버리지 않습니다.

**권한**: `sirsoft-ecommerce.claims.update-amounts`

**본문 파라미터 — 교환·반품** (`exchanges`/`returns`)

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| claim_shipping_fee | number | 아니오 | 클레임 배송비 (min 0 — 반품 편도 / 교환 왕복). 총 청구액·다통화 표기가 함께 다시 계산되고, 산정 근거 스냅샷에 `admin_adjusted` 가 남는다 |
| charge_method | string | 아니오* | 청구 방식 (`pay_on_request`/`pay_on_approval`/`deduct_from_refund`) — 허용 목록 `claim.allowed_charge_methods` 로 게이트 |
| admin_memo | string | 아니오 | 관리자 메모 (max 2000 — 보낸 요청만 갱신) |

\* 접수 시 청구 0원(청구 방식 없음)이던 신청서의 배송비를 양수로 올릴 때는 `charge_method` 를
함께 실어야 합니다 — 없으면 `422` `claim_charge_method_required` (결제할 수 없는 청구가 남는
것을 막는다). 반대로 청구액이 0 이 되면 청구 방식은 자동으로 비워집니다.

**본문 파라미터 — 환불신청서** (`refunds` — 취소 발 `refund_source_type=cancel` 은 수정 불가 422)

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| refund_amount | number | 아니오 | PG 환불액 (min 0, 기본 통화) — 다통화 표기는 주문 스냅샷 환율로 재계산 |
| refund_points_amount | number | 아니오 | 마일리지 환불액 (min 0) — 비회원 주문은 `422` `claim_refund_points_requires_member` |
| refund_method | string | 아니오 | 환불 수단 (`pg`/`bank`/`points`) |

환불액의 합(`refund_amount + refund_points_amount`)은 **취소 가능 금액(D19)** 을 넘을 수
없습니다 — 초과는 `422` `claim_refund_exceeds_cancellable` (저장·실행 모두 같은 식으로 재계산).

**응답 예시 (200)**

```json
{
  "success": true,
  "message": "금액을 수정했습니다.",
  "data": {
    "claim": {
      "id": 12,
      "claim_type": "refund",
      "claim_number": "RF-20260825-0007",
      "refund_amount": 15000,
      "refund_points_amount": 3000,
      "refund_method": "pg",
      "refund_status": "approved",
      "processed_by": 3
    }
  }
}
```

**오류**

| 코드 | 키 | 조건 |
| --- | --- | --- |
| 404 | `claim_not_found` | 신청서 부재 |
| 422 | `claim_amounts_not_editable` | 종결(취소·완료·반려·철회) 신청서 / 환불신청서가 `processing` |
| 422 | `claim_refund_not_executable` | 취소 발(`refund_source_type=cancel`) 환불신청서 — 취소 파이프라인 산출값이라 이 API 로 고칠 수 없다 |
| 422 | `claim_amount_invalid` | 음수 금액 / 알 수 없는 환불 수단 |
| 422 | `claim_charge_method_required` | 청구 0원 신청서의 배송비를 올리면서 청구 방식을 함께 보내지 않음 |
| 422 | `claim_refund_exceeds_cancellable` | 환불액 합이 취소 가능 금액(D19) 초과 |
| 422 | `claim_refund_points_requires_member` | 비회원 주문에 마일리지 환불액 지정 |
| 422 | (검증) | 다른 유형의 축을 보냄 (`errors` 에 필드별 사유) |

수정 전후 금액과 작업자는 `sirsoft-ecommerce.claim.{type}.amounts_updated` 훅으로 발행되어
활동 로그의 기록 근거가 됩니다.

---

### GET /api/modules/sirsoft-ecommerce/admin/orders/{order}/cancellable-amount

주문의 취소 가능 금액(D19)을 산정합니다 — 환불 상세 화면의 근거 표와 주문상세 환불 모달이
공용으로 씁니다. 서버는 환불 저장·실행 직전에 **같은 식**으로 재계산하므로, 이 응답이 보여준
한도와 실행 가드의 한도는 같은 값입니다.

```text
amount = max(0, min(domain_refundable, payment_capacity) − reserved)
```

**권한**: `sirsoft-ecommerce.claims.read`

**쿼리 파라미터**

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| refund_id | integer | 아니오 | 환불신청서 문맥 — 그 신청서의 출처별 산식(직접=취소 재계산 / 반품=반품 재계산 / 교환=동결 차액 잔여)으로 계산하고 선점 집계에서 **자기 자신을 제외**한다. 다른 주문의 신청서면 `404` |
| items[] | object[] | 아니오 | 대상 아이템 `[{order_option_id, cancel_quantity}]` (max 200) — 주문 소속이 아닌 옵션은 `422` `claims.items.not_in_order` |

둘 다 없으면 미취소 옵션 전량 기준으로 계산합니다. 두 문맥은 **배타**입니다 — 함께 보내면
`422` (`refund_id` 필드에 `claims.items.prohibited_with_refund`) 입니다. 한쪽을 조용히
버리면 화면은 선택 상품 기준이라 믿는 자리에 신청서 기준 한도가 표시되기 때문입니다.
빈 값(`?refund_id=`)은 보내지 않은 것으로 취급합니다.

**응답 예시 (200)**

```json
{
  "success": true,
  "message": "취소 가능 금액을 산정했습니다.",
  "data": {
    "cancellable": {
      "amount": 24000,
      "domain_refundable": 29000,
      "payment_capacity": 29000,
      "reserved": 5000,
      "binding": "reserved",
      "binding_label_key": "sirsoft-ecommerce::messages.claims.cancellable_binding.reserved",
      "methods": [
        {"type": "payment", "payment_id": 88, "method": "card", "currency": "KRW", "remaining_local": 29000, "remaining_base": 29000}
      ],
      "reserved_claims": [
        {"type": "refund", "id": 12, "number": "RF20260825000003", "amount": 5000}
      ],
      "currency": "KRW",
      "mc_amount": {"KRW": 24000}
    }
  }
}
```

`binding` 은 한도를 정한 축입니다 (`domain_refundable`/`payment_capacity`/`reserved`).
`methods` 는 수단별 잔여 한도(결제 통화 원값 `remaining_local` 병기 — D18 화면의 수단별 한도),
`reserved_claims` 는 선점 근거(진행 중인 다른 클레임)입니다.

---

### GET /api/modules/sirsoft-ecommerce/admin/orders/{order}/claims

주문 스코프 클레임 내역을 그룹 구조로 조회합니다 (D20 — 주문상세 '클레임 내역' 섹션).
응답은 유형 서브탭 뱃지용 `counts` 와, **신청서가 상품 배열을 품는 그룹 구조**의 `claims`
(전 유형 평면 배열, 신청 역순) 하나입니다 — 화면이 `claim_type` 으로 서브탭을 거르고,
PC 는 1열 `rowSpan={items.length}` 병합·모바일은 신청서 단위 카드를 **같은 응답**으로
그립니다 (별도 데이터소스 없음).

**권한**: `sirsoft-ecommerce.claims.read`

**요청 파라미터**: 없음 (주문 스코프 전량 — 결과 상한은 코어 `resultCap('ecommerce.claims')`)

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/orders/1024/claims HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드**

- `counts` — `{exchange, return, refund, cancel}` 유형별 건수. 환불 축은 취소 발
  (`refund_source_type=cancel`) 신청서만 제외합니다 — 취소 축이 그 건을 대표합니다
  (관리자 목록·비회원 주문상세 섹션과 같은 규칙). 출처가 기록되지 않은 구형 신청서
  (`refund_source_type=null`)는 직접 환불로 보아 포함합니다.
- `claims[]` — [claims.md](claims.md#공통-리소스) 의 ClaimResource 상세 표현에 관리자
  확장 필드(`requester_name`·`admin_memo`·`processed_by`)와 아래 두 축을 더한 형태:
  - `detail_url` — [클레임 신청서 조회하기] 새 창 진입 경로 (`/admin/ecommerce/claims/{claim_number}`).
    **취소는 상세 라우트가 없어 `null`** — 취소 이력은 이 섹션이 종착입니다.
    origin 없이 앱 내 경로만 싣습니다. 이 경로 규칙(회원·비회원·관리자 3종)은 클레임
    결제 콜백 리다이렉트와 클레임 알림 링크도 함께 쓰므로 서버가 한 곳에서 만듭니다 —
    응답 값을 화면에서 다시 조립하지 마세요.
  - `items[].amount` / `amount_formatted` / `amount_type` — 네 유형의 금액 축을 한 컬럼으로
    그리기 위한 정규화 값. 출처는 교환=옵션 차액 소계, 반품·취소=대상 금액(단가×수량),
    환불=행 환불액입니다.
    **`amount` 는 언제나 크기(0 이상)이고 방향은 `amount_type` 이 단독으로 나릅니다** —
    `charge`(추가 청구) / `refund`(환불·대상 금액) / `none`(0원). 저가 교환처럼 차액이 음수인
    경우에도 `amount` 는 양수이고 `amount_type` 이 `refund` 가 됩니다. 화면은 유형 분기 없이
    `amount_type` 으로 색·부호를 그리면 됩니다. `amount` 의 소수 자릿수는 주문 통화 스냅샷이
    정합니다(0자리 통화는 정수). 부호가 필요하면 원본 필드
    (`price_difference`·`subtotal_amount`·`refund_amount`)를 읽으세요 — 그대로 남습니다.
    배송비는 신청서 단위 축이라 행에 안분하지 않습니다 — 신청서 레벨 `claim_shipping_fee`/
    `total_charge_amount` 를 함께 읽으세요.

**응답 예시 (200)**

```json
{
  "success": true,
  "message": "신청 내역을 조회했습니다.",
  "data": {
    "counts": {"exchange": 1, "return": 0, "refund": 0, "cancel": 1},
    "claims": [
      {
        "id": 4,
        "claim_number": "EX20260825-000004",
        "claim_type": "exchange",
        "status": "collecting",
        "status_label": "교환회수중",
        "requested_at_formatted": "2026-08-25 10:12",
        "requester_name": "김지은",
        "reason_label": "단순 변심",
        "fault_type": "customer",
        "reject_reason": null,
        "detail_url": "/admin/ecommerce/claims/EX20260825-000004",
        "claim_shipping_fee": 6000,
        "total_charge_amount": 9000,
        "items": [
          {
            "product_name": "프리미엄 코튼 티셔츠",
            "product_option_name": "차콜/M",
            "quantity": 1,
            "original_quantity": 2,
            "unit_price": 29000,
            "new_option_label": "블랙/L",
            "new_unit_price": 32000,
            "price_difference": 3000,
            "amount": 3000,
            "amount_formatted": "3,000원",
            "amount_type": "charge",
            "status": "collecting",
            "status_label": "교환회수중"
          }
        ]
      },
      {
        "id": 2,
        "claim_number": "CL20260825-000002",
        "claim_type": "cancel",
        "status": "completed",
        "status_label": "취소완료",
        "detail_url": null,
        "items": [
          {"product_name": "소프트 터치 니트", "quantity": 1, "amount": 45000, "amount_type": "refund"}
        ]
      }
    ]
  }
}
```

**오류**

| 코드 | 키 | 조건 |
| --- | --- | --- |
| 403 | `auth.permission_denied` | `claims.read` 권한 없음 |
| 403 | `auth.scope_denied` | 권한은 있으나 열람 스코프 밖의 주문인 경우 |
| 404 | — | 주문 부재 (라우트 모델 바인딩) |

---

### POST /api/modules/sirsoft-ecommerce/admin/refunds/{id}/execute

승인된 환불신청서의 환불을 실행합니다 ('환불하기' — D18). 본문에 금액이 실려 있으면 저장과
같은 검증을 거쳐 **화면에 입력된 값으로 실행**합니다(저장 없이 실행 가능). 취소 발
(`refund_source_type=cancel`) 환불은 취소 파이프라인 소유라 실행 대상이 아닙니다(`422`).

한 트랜잭션에서 수행됩니다: (직접 환불신청서) 선점 수량의 취소 분할·전이 + 재고 복원(설정
준수) + 쿠폰 조건 소멸 복원 → PG 환불 + 주문 환불 누적(`total_refunded_amount`)·결제행 취소
누적 → 사용 마일리지 환급(`order_refund_id` 멱등) → 신청서·상품 행 완료 확정. 비-PG 환불
수단(무통장·마일리지 전액)은 PG 호출 없이 완료로 확정됩니다 — 운영자의 [환불하기]가 곧 수동
송금 완료 선언입니다.

**권한**: `sirsoft-ecommerce.claims.execute-refund`

**본문 파라미터** (전부 선택 — 없으면 저장된 환불액으로 실행)

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| refund_amount | number | 아니오 | PG 환불액 (min 0) |
| refund_points_amount | number | 아니오 | 마일리지 환불액 (min 0 — 비회원 주문 422) |
| refund_method | string | 아니오 | 환불 수단 (`pg`/`bank`/`points`) |

**응답 예시 (200)**

```json
{
  "success": true,
  "message": "환불을 실행했습니다.",
  "data": {
    "claim": { "id": 12, "claim_number": "RF20260825000003", "status": "completed", "refunded_at": "2026-08-25T14:02:11+09:00", "...": "ClaimResource 전체" }
  }
}
```

**오류**

| 코드 | 사유 |
| --- | --- |
| `404` | 환불신청서 부재 |
| `409` | 이미 실행됐거나 실행 중 (`claim_refund_already_executed` — 더블클릭). 화면은 새로고침을 안내한다 |
| `422` | 승인 전 상태(`claim_transition_not_allowed`) / 취소 발 환불(`claim_refund_not_executable`) / D19 상한 초과(`claim_refund_exceeds_cancellable` — 실행 직전 재계산 가드) / PG 환불 거절(`errors.detail` 에 PG 사유 — 관리자 전용 면의 조치 근거) |

---

### POST /api/modules/sirsoft-ecommerce/admin/claims/completion-preview

완료 처리 모달의 미리보기 요약을 계산합니다 (D26). 세 진입점(목록 일괄·상품 단위·상세 버튼)이
여는 **같은 모달**이 실행 전에 보여주는 값 — 건별 환불 예정액·재고 복구/폐기 건수·적립 차감·
쿠폰 복원 여부 — 의 SSoT 입니다. DB 를 변경하지 않습니다.

**권한**: `sirsoft-ecommerce.claims.update-status`

**본문 파라미터**

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| claim_type | string | 예 | 유형 (`exchange`/`return` — 완료 모달은 교환·반품 전용) |
| create_refund_request | boolean | 예 | 모달의 환불신청서 라디오 선택 상태 (지금 접수 / 접수하지 않음) |
| claims[] | object[] | 예 | 대상 신청서 (1~100건) |
| claims[].id | integer | 예 | 신청서 ID |
| claims[].item_ids[] | integer[] | 아니오 | 이번 라운드 완료 대상 상품 행 (없으면 전체 — 상품 단위 진입점만 보낸다) |
| claims[].restore_stock_by_item | object | 아니오 | 상품 행 ID → 재고 복구 체크 상태 맵 |

**응답 예시 (200)**

```json
{
  "success": true,
  "message": "완료 처리 요약을 계산했습니다.",
  "data": {
    "claims": [
      {
        "id": 31,
        "claim_number": "RT20260825000007",
        "completable_item_ids": [55],
        "completable_item_count": 1,
        "has_stock_restore_target": true,
        "stock_restore_count": 1,
        "stock_discard_count": 0,
        "earn_recovery": true,
        "coupon_restore": false,
        "refund": {
          "applicable": true,
          "already_filed": false,
          "blocked_reason": null,
          "refund_amount": 26000,
          "refund_points_amount": 0,
          "deducted_shipping_fee": 3000
        }
      }
    ],
    "skipped": [{"id": 999999, "message": "해당 신청서를 찾을 수 없습니다."}],
    "totals": {"claim_count": 1, "completable_item_count": 1, "refund_amount_total": 26000, "refund_points_amount_total": 0}
  }
}
```

- `has_stock_restore_target` 은 요청의 체크 상태 기준입니다 — 모달이 "함께 실행되는 처리" 에
  재고 복구를 보여줄지의 근거이며, 실제 복구는 완료 실행이 이번 라운드 대상만 수행합니다.
- `refund.applicable` 은 `create_refund_request=true` 이고 접수가 성립할 때만 참입니다.
  접수가 성립하지 않는 사유는 `blocked_reason` 으로 구분합니다 — `claim_refund_request_exists`
  (이미 접수됨) / `claim_refund_not_applicable`(환불 대상 없음 — 동일가 교환·순추가 신청서,
  완료 가능·완료된 상품이 하나도 없는 반품 등). **교환·반품 두 유형 모두** 이 구분을 냅니다 —
  사유 없이 `applicable: true` + `refund_amount: 0` 으로 답하면 모달은 '0원 접수' 를 예고하는데
  실제 접수는 `422` 가 되어 미리보기와 실행이 갈립니다.
- 교환의 환불 예정액은 **완료됐다고 가정한 상품**까지 포함해 접수 산식과 같은 식으로
  계산합니다 — 모달 금액과 실제 접수액이 갈리지 않습니다.

**오류**

| 코드 | 키 | 조건 |
| --- | --- | --- |
| 403 | — | `sirsoft-ecommerce.claims.update-status` 없음 |
| 422 | `claims.type.in` | `claim_type` 이 `exchange`/`return` 이 아님 (환불·취소는 각자 다른 파이프라인이 소유) |
| 422 | `claims.preview_claims.required` | `claims` 누락 또는 빈 배열 |
| 422 | `claims.preview_claims.max` | `claims` 가 100건 초과 |
| 422 | `claims.items.max` | 한 신청서의 `item_ids` 가 200건 초과 |
| 422 | `claims.items.not_in_claim` | `item_ids` 에 그 신청서 소속이 아닌 상품 행 ID 포함 |
| 422 | `claims.completion.create_refund_request_required` | `create_refund_request` 누락 (기본값 없음 — D26) |

존재하지 않는 신청서 ID 는 오류가 아니라 `data.skipped[]` 로 보고합니다 — 목록 일괄 선택과
실제 조회 사이의 시차를 모달이 그대로 안내할 수 있게 하기 위해서입니다. 다만 그 신청서에
`item_ids` 를 함께 실으면 소속 검증이 먼저 걸려 `422` 가 됩니다.

---

### POST /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/refund-request

완료 시 접수하지 않은 신청서의 환불신청서를 수동 접수합니다 (D26 — 교환·반품). 반품은
신청서당 1건(완료된 상품 전체), 교환은 완료 라운드마다 접수할 수 있어 "완료됐으나 아직
접수되지 않은 저가 상품" 을 모아 1건으로 접수합니다.

**권한**: `sirsoft-ecommerce.claims.update-status`

**응답 예시 (201)**

```json
{
  "success": true,
  "message": "환불신청서를 접수했습니다.",
  "data": {
    "refund": { "id": 12, "claim_number": "RF20260825000003", "claim_type": "refund", "status": "requested", "...": "ClaimResource 전체" }
  }
}
```

**오류**: `404` 부재 / `422` 완료된 상품 없음(`claim_completion_items_invalid`) · 전부
접수됨(`claim_refund_request_exists`) · 환불 대상 없음(`claim_refund_not_applicable`) —
화면은 두 사유를 구분해 안내합니다.
