# Admin Claims API 레퍼런스

> **소유**: 모듈 `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반).
> 이 문서는 관리자 클레임(취소/반품/교환 관리) 엔드포인트를 다룹니다.
> 회원 경로는 [claims.md](claims.md), 비회원 경로는 [guest.md](guest.md), 추가결제는 [claim-payments.md](claim-payments.md), 사유 카탈로그는 [claim-reasons.md](claim-reasons.md) 를 보세요.
> 응답 리소스의 공통 필드 표(ClaimResource/ClaimItemResource/ClaimPaymentResource/ClaimImageResource)는 [claims.md](claims.md#공통-리소스) 가 정본입니다.

## TL;DR (5초 요약)

```text
1. 목록은 유형 서브탭 단위 — type 파라미터(기본 exchange)로 한 유형만 받고, counts 로 4유형 건수 뱃지를 함께 준다
2. 유형 스코프 경로는 /admin/{exchanges|returns|refunds}/{id} — 취소(cancel)는 목록에만 있고 상세·전이 대상이 아니다
3. 상태 쓰기 어휘는 유형별 adminAssignableValues() — requested/paid/withdrawn 직접 지정 불가 (환불은 approved/on_hold/rejected 만)
4. 완료 전이(status=completed)는 completion 객체 필수 — create_refund_request 는 필수, restore_stock_by_item 은 선택 (D26 — 기본값 없음)
5. 미수납 청구가 남은 승인: pay_on_approval 은 결제 요청으로 우회(payment_requested=true), pay_on_request 는 422 로 거절
```

## 인증·권한

전 엔드포인트 `auth:sanctum` + 관리자(admin) 미들웨어. 세부 권한:

| 권한 | 대상 |
| --- | --- |
| `sirsoft-ecommerce.claims.read` | 목록·상세 조회 |
| `sirsoft-ecommerce.claims.update-status` | 전이(단건·일괄·상품 단위)·송장·첨부 |

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
