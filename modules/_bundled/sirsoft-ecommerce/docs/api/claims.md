# Claims API 레퍼런스

> **소유**: 모듈 `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반).
> 이 문서는 회원의 클레임(교환/반품/취소·환불) 신청내역 엔드포인트와, 클레임 응답 리소스의 공통 필드 표를 다룹니다.
> 주문 단위 신청 경로(`user/orders/{id}/…`)는 [orders.md](orders.md#클레임-신청-주문-단위), 비회원 경로는 [guest.md](guest.md), 추가결제는 [claim-payments.md](claim-payments.md), 첨부 서빙은 [claim-image.md](claim-image.md), 관리자 경로는 [admin-claims.md](admin-claims.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 클레임 4종(교환 exchange / 반품 return / 환불신청 refund / 취소 cancel)은 한 응답 형태(ClaimResource)로 통일되고 claim_type 으로 구분한다
2. 신청내역 목록은 유형 서브탭 단위 페이지네이션 — type 파라미터 하나로 한 유형만 받는다 (기본 exchange)
3. 상세·수정·철회는 claim_number 로 찾고, 남의 신청서는 존재 여부를 드러내지 않고 404
4. 수정(PATCH)은 승인 전(is_editable)에만 — 사유·상세·청구 방식·첨부·재발송지. 배송비가 바뀌면 차액 결제 건을 새로 만들거나 초과분을 환급한다
5. 철회는 대기 중인 결제 건을 닫고 이미 낸 추가결제를 환급한다
```

## 오류 응답의 두 형태 (422)

클레임 경로의 422 는 **두 층**에서 나오고 본문 형태가 다르다. 화면은 두 형태를 모두 다뤄야 한다.

| 층 | 언제 | 본문 | 화면 처리 |
| --- | --- | --- | --- |
| 요청 형태 검증 | 필수 누락·타입·길이·허용 목록 위반 | `{"success":false,"message":"<첫 오류 문장>","errors":{"필드":["메시지"]}}` | `errors` 로 필드 인라인 표시 |
| 도메인 규칙 | 수량 초과·수량 잠금·사유 사용 불가·첨부 자격·수정 불가·결제 상태 | `{"success":false,"message":"<안내 문장>"}` — **`errors` 없음** | `message` 를 폼 상단/토스트로 표시 |

`message` 는 두 층 모두 사용자에게 그대로 보여도 되는 문장이다. 필드 정보는 `errors` 에만 있으므로,
`errors` 가 없다고 해서 실패를 삼키면 안 된다(도메인 거절이 조용히 사라진다).

404 와 500 도 같은 봉투(`success`/`message`)를 쓰며 `errors` 는 없다. 500 의 `message` 는 항상 고정
문구이고 예외 원문·PG 원문은 담기지 않는다(원문은 서버 로그에만 남는다).

## 엔드포인트 목록

| 메서드 | URI | 라우트명 |
| --- | --- | --- |
| GET | /api/modules/sirsoft-ecommerce/user/claims | `api.modules.sirsoft-ecommerce.user.claims.index` |
| GET | /api/modules/sirsoft-ecommerce/user/claims/{claimNumber} | `api.modules.sirsoft-ecommerce.user.claims.show` |
| PATCH | /api/modules/sirsoft-ecommerce/user/claims/{claimNumber} | `api.modules.sirsoft-ecommerce.user.claims.update` |
| POST | /api/modules/sirsoft-ecommerce/user/claims/{claimNumber}/withdraw | `api.modules.sirsoft-ecommerce.user.claims.withdraw` |
| DELETE | /api/modules/sirsoft-ecommerce/user/claims/images/{image} | `api.modules.sirsoft-ecommerce.user.claims.images.destroy` |

---

## 공통 리소스

<a id="claim-resource"></a>

### ClaimResource (신청서)

교환·반품·환불신청·취소 4종을 같은 형태로 내보냅니다. 공통 필드 뒤에 유형별 블록이 붙고, 상세 응답의 교환·반품에는 결제 블록이 더 붙습니다.

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `31` | 신청서 기본 키 |
| claim_number | string | `RT20260823000007` | 클레임 번호 (교환 `EX…`, 반품 `RT…`, 환불신청 `RF…`, 취소 `CN…`) |
| claim_type | string | `return` | 클레임 유형 (`exchange`, `return`, `refund`, `cancel`) |
| claim_type_label | string | `반품` | 유형 라벨 |
| order_id | integer | `120` | 주문 ID |
| order_number | string\|null | `20260823-0012` | 주문번호 (`order` 관계 로드 시) |
| status | string | `requested` | 유형별 상태 값 (교환 `ExchangeStatusEnum`, 반품 `ReturnStatusEnum`, 환불신청 `RefundStatusEnum`, 취소 `CancelStatusEnum`) |
| status_label | string\|null | `반품신청` | 상태 라벨 (유형 접두가 붙는다 — 교환 `교환신청`, 반품 `반품신청`, 환불 `환불신청`) |
| status_variant | string\|null | `info` | 상태 뱃지 variant |
| is_final | boolean | `false` | 종결 상태인지 (철회·반려·완료) |
| is_withdrawable | boolean | `true` | 사용자가 철회할 수 있는 상태인지 (승인 전) |
| is_editable | boolean | `true` | 사용자가 수정할 수 있는지 — 철회 가능 경계와 같고, 취소(`cancel`)는 항상 `false` |
| reason_code | string\|null | `changed_mind` | 사유 코드 (취소는 `cancel_reason_type`) |
| reason_label | string\|null | `단순 변심` | 사유 라벨 |
| reason_detail | string\|null | `사이즈가 작아요` | 사유 상세 (취소는 `cancel_reason`) |
| fault_type | string\|null | `customer` | 귀책 구분 (`customer`, `seller`, `carrier`) |
| fault_type_label | string\|null | `고객 귀책` | 귀책 라벨 |
| reject_reason | string\|null | `null` | 반려 사유 |
| requested_by | integer\|null | `7` | 신청 회원 ID (비회원 신청은 `null`) |
| is_guest_request | boolean | `false` | 비회원 신청인지 |
| currency_code | string\|null | `KRW` | 금액 통화 (주문 통화 스냅샷의 기본 통화) |
| items | array | — | 신청 상품 행 ([ClaimItemResource](#claim-item-resource)) |
| images | array | — | 첨부 이미지 ([ClaimImageResource](#claim-image-resource), 취소는 항상 빈 배열) |
| requested_at | string\|null | `2026-08-23T13:40:11+09:00` | 신청 일시 (ISO 8601) |
| requested_at_formatted | string\|null | `2026-08-23 13:40` | 신청 일시 (사용자 타임존 표기) |
| approved_at_formatted | string\|null | `null` | 승인 일시 |
| rejected_at_formatted | string\|null | `null` | 반려 일시 |
| withdrawn_at_formatted | string\|null | `null` | 철회 일시 |
| completed_at_formatted | string\|null | `null` | 완료(환불·취소) 일시 |

**교환·반품 공통 블록** (`claim_type` = `exchange` / `return`)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| charge_method | string\|null | `pay_on_request` | 배송비 청구 방식 (`deduct_from_refund`, `pay_on_request`, `pay_on_approval` — 환경설정 `claim.allowed_charge_methods`). 청구액이 0 이면 `null` |
| charge_method_label | string\|null | `신청 시 결제` | 청구 방식 라벨 |
| shipping_fee_mode | string\|null | `fixed` | 배송비 산정 모드 (`fixed` 고정액, `original_policy` 원 배송정책 재계산) |
| claim_shipping_fee | number | `3000` | 클레임 배송비 (반품 편도 / 교환 왕복) |
| claim_shipping_fee_formatted | string | `3,000원` | 클레임 배송비 표기 |
| mc_claim_shipping_fee | object | `{"KRW":{"amount":3000,"formatted":"3,000원"}}` | 배송비 다중 통화 스냅샷 (값이 없으면 `null` 이 아니라 빈 객체 `{}` — 모든 `mc_*` 가 같다) |
| shipping_fee_snapshot | object\|null | — | 배송비 산정 근거 스냅샷 (`ClaimShippingFee`) |
| total_charge_amount | number | `3000` | 사용자에게 청구하는 총액 (배송비 + 교환 옵션 차액 중 양수) |
| total_charge_amount_formatted | string | `3,000원` | 총 청구액 표기 |
| mc_total_charge_amount | object | — | 총 청구액 다중 통화 스냅샷 |
| requires_additional_payment | boolean | `true` | 추가결제가 필요한 건인지 (청구액 > 0 이고 청구 방식이 결제형) |
| return_address_snapshot | object\|null | — | 회수지(반송지) 스냅샷 |
| collection_carrier_id | integer\|null | `null` | 회수 택배사 ID |
| collection_carrier_name | string\|null | `null` | 회수 택배사명 |
| collection_tracking_number | string\|null | `null` | 회수 송장번호 |
| collection_tracking_url | string\|null | `null` | 회수 배송 추적 URL (택배사 템플릿으로 서버가 조립. 택배사·송장번호 중 하나라도 없거나 택배사에 추적 주소가 등록돼 있지 않으면 `null`) |
| collection_completed_at_formatted | string\|null | `null` | 회수 완료 일시 |
| refund_bank_code | string\|null | `004` | 구매자가 이 신청서에 적어 낸 환불 계좌 은행 코드. 계좌 이체로 환불되는 주문에서만 실린다 (교환은 저가 옵션 교환으로 차액이 돌아올 때) |
| refund_bank_name | string\|null | `국민은행` | 그 은행 코드의 이름. 운영자가 주문설정에 등록한 목록에서 서버가 해석한다 — 화면이 그 코드표를 읽으려면 설정 데이터소스가 필요한데 그 게이트는 클레임 화면의 라우트 권한보다 좁다 |
| refund_bank_account | string\|null | `*******7890` | 환불 계좌번호. `sirsoft-ecommerce.orders.read` 권한이 없는 열람자에게는 뒤 4자리만 남는다 |
| refund_bank_holder | string\|null | `홍*` | 환불 계좌 예금주. 같은 게이트로 마스킹된다 |

**교환 전용 블록** (`claim_type` = `exchange`)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| additional_product_amount | number | `0` | 옵션 변경으로 추가되는 상품 금액 (차액 합 중 양수) |
| additional_product_amount_formatted | string | `0원` | 추가 상품 금액 표기 |
| refund_product_amount | number | `0` | 옵션 변경으로 돌려줄 상품 금액 (차액 합 중 음수) |
| refund_product_amount_formatted | string | `0원` | 환불 상품 금액 표기 |
| redelivery_address_snapshot | object\|null | — | 재발송지 스냅샷 (`recipient_name`, `phone`, `zipcode`, `address1`, `address2`, `country_code`, `changed_by_user`) |
| redelivery_carrier_id | integer\|null | `null` | 재발송 택배사 ID |
| redelivery_carrier_name | string\|null | `null` | 재발송 택배사명 |
| redelivery_tracking_number | string\|null | `null` | 재발송 송장번호 |
| redelivery_tracking_url | string\|null | `null` | 재발송 배송 추적 URL (조립 규칙은 `collection_tracking_url` 과 같다) |
| redelivered_at_formatted | string\|null | `null` | 재발송 완료 일시 |

**환불신청 전용 블록** (`claim_type` = `refund`)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| refund_source_type | string\|null | `direct` | 환불 발생 출처 (`direct` 직접 신청 / `cancel` / `return` / `exchange`) |
| refund_method | string\|null | `pg` | 환불 수단 (`pg`, `bank`, `points`) |
| refund_method_label | string\|null | `PG사 환불` | 환불 수단 라벨 |
| refund_amount | number | `29000` | PG 환불 금액 |
| refund_amount_formatted | string | `29,000원` | PG 환불 금액 표기 |
| refund_points_amount | number | `0` | 마일리지 환불 금액 |
| refund_points_amount_formatted | string | `0원` | 마일리지 환불 표기 |
| refund_shipping_amount | number | `0` | 환불 배송비 |
| refund_shipping_amount_formatted | string | `0원` | 환불 배송비 표기 |
| refund_total | number | `29000` | PG + 마일리지 환불 합계 |
| refund_total_formatted | string | `29,000원` | 환불 합계 표기 |
| mc_refund_amount | object | — | PG 환불 다중 통화 스냅샷 |
| refund_bank_code | string\|null | `004` | 이 환불이 나갈 계좌의 은행 코드. 직접 환불신청은 구매자 입력분이고, 교환·반품에서 넘어온 환불은 그 신청서의 값을 이어받는다 |
| refund_bank_name | string\|null | `국민은행` | 그 은행 코드의 이름 (서버 해석) |
| refund_bank_account | string\|null | `*********3456` | 환불 계좌번호 (마스킹) |
| refund_bank_holder | string\|null | `null` | 환불 계좌 예금주 |
| refunded_at_formatted | string\|null | `null` | 환불 완료 일시 |

**취소 전용 블록** (`claim_type` = `cancel`)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| cancel_type | string\|null | `partial` | 취소 유형 (`full`, `partial`) |
| cancel_type_label | string\|null | `부분취소` | 취소 유형 라벨 |
| refund_amount | number\|null | `29000` | 연결 환불 건의 PG 환불 금액 (`refund` 관계 로드 시) |
| refund_amount_formatted | string\|null | `29,000원` | PG 환불 금액 표기 |
| refund_points_amount | number\|null | `0` | 연결 환불 건의 마일리지 환불 금액 |
| refund_status | string\|null | `completed` | 연결 환불 건의 상태 |
| refund_status_label | string\|null | `환불완료` | 환불 상태 라벨 |
| cancelled_at_formatted | string\|null | `2026-08-23 13:41` | 취소 일시 |

**결제 블록** (상세·신청·수정·철회 응답의 교환·반품에만)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| payments | array | — | 이 신청서의 결제 건 목록 ([ClaimPaymentResource](#claim-payment-resource)) |
| outstanding_amount | number | `3000` | 아직 수납되지 않은 청구액 (총 청구액 − 순수납액) |
| outstanding_amount_formatted | string | `3,000원` | 미수납액 표기 |
| pending_payment | object\|null | — | 결제 대기 중인 건 (없으면 `null`) |
| payment_entry | object\|null | — | 결제 대기 건이 있으면 결제 진입 4키(`requires_pg_payment`, `pg_provider`, `pg_payment_handler`, `pg_payment_data`) — [claim-payments.md](claim-payments.md) 참조 |

<a id="claim-item-resource"></a>

### ClaimItemResource (신청 상품 행)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `44` | 상품 행 기본 키 |
| order_option_id | integer | `310` | 대상 주문 옵션 ID |
| quantity | integer | `1` | 신청 수량 |
| original_quantity | integer\|null | `2` | 신청 전 주문 옵션 수량 (부분 수량이면 분할 전 값) |
| status | string\|null | `requested` | 상품 행 상태 (유형별 옵션 상태 Enum) |
| status_label | string\|null | `반품신청` | 상태 라벨 (헤더와 같은 유형별 어휘) |
| status_variant | string\|null | `info` | 상태 뱃지 variant |
| unit_price | number | `29000` | 단가 |
| unit_price_formatted | string | `29,000원` | 단가 표기 |
| subtotal_amount | number | `29000` | 단가 × 수량 |
| subtotal_amount_formatted | string | `29,000원` | 소계 표기 |
| product_name | string\|null | `반팔 티셔츠` | 상품명 (현재 로케일) |
| product_option_name | string\|null | `화이트 / M` | 상품 옵션명 |
| option_name | string\|null | `화이트 / M` | 옵션 표시명 |
| thumbnail_url | string\|null | `/storage/products/1/thumb.jpg` | 상품 썸네일 |
| product_id | integer\|null | `12` | 상품 ID |
| product_option_id | integer\|null | `55` | 상품 옵션 ID |
| completed_at | string\|null | `null` | 상품 행 완료 일시 |
| refund_amount | number | `29000` | (환불신청 행 전용) 행 단위 환불 금액 — 취소 행에는 이 컬럼이 없어 두 키가 **응답에 나오지 않는다**. 취소의 환불액은 헤더 `refund_amount` 를 읽는다 |
| refund_amount_formatted | string | `29,000원` | 행 환불 금액 표기 |
| is_option_change | boolean | `true` | (교환 행) 다른 옵션으로의 교환인지 |
| new_product_option_id | integer\|null | `56` | (교환 행) 교환 대상 상품 옵션 ID (동일 옵션 교환이면 `null`) |
| new_option_snapshot | object\|null | — | (교환 행) 교환 대상 옵션 스냅샷 |
| new_option_label | string\|null | `화이트 / L` | (교환 행) 교환 대상 옵션 표시명 |
| new_unit_price | number | `31000` | (교환 행) 교환 대상 단가 |
| new_unit_price_formatted | string | `31,000원` | 교환 대상 단가 표기 |
| price_difference | number | `2000` | (교환 행) 단위 차액 (양수면 추가 결제, 음수면 환불) |
| price_difference_formatted | string | `2,000원` | 차액 표기 |
| mc_price_difference | object | — | 차액 다중 통화 스냅샷 |
| new_order_option_id | integer\|null | `null` | (교환 행) 교환 완료 후 생성된 새 주문 옵션 ID |

<a id="claim-payment-resource"></a>

### ClaimPaymentResource (추가결제 건)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `12` | 결제 건 기본 키 |
| payment_number | string | `CP20260823000001` | 결제번호 (`CP` 접두사 — PG 결제창의 주문번호 자리에 쓰인다) |
| payable_type | string | `return` | 결제 대상 클레임 유형 (`exchange`, `return`) |
| payable_id | integer | `31` | 결제 대상 클레임 ID |
| payment_method | string\|null | `card` | 결제 수단 (`card`, `point`, … 환경설정 `claim.charge_payment_methods`) |
| payment_method_label | string\|null | `신용카드` | 결제 수단 라벨 |
| pg_provider | string\|null | `kginicis` | PG provider id |
| payment_status | string | `pending` | 결제 상태 (`pending`, `paid`, `failed`, `cancelled`, `refunded`) |
| payment_status_label | string\|null | `결제대기` | 상태 라벨 |
| payment_status_variant | string\|null | `warning` | 상태 뱃지 variant |
| amount | number | `3000` | PG 청구액 |
| amount_formatted | string | `3,000원` | PG 청구액 표기 |
| points_amount | number | `0` | 마일리지 결제액 |
| points_amount_formatted | string | `0원` | 마일리지 결제액 표기 |
| total_amount | number | `3000` | 청구 총액 (PG + 마일리지) |
| total_amount_formatted | string | `3,000원` | 청구 총액 표기 |
| refunded_amount | number | `0` | 부분 환급 누적액 (전액 환급은 `payment_status=refunded`) |
| refunded_amount_formatted | string | `0원` | 환급 누적액 표기 |
| net_paid_amount | number | `0` | 순수납액 (수납 총액 − 환급 누적액, 미결제면 0) |
| net_paid_amount_formatted | string | `0원` | 순수납액 표기 |
| currency_code | string\|null | `KRW` | 결제 통화 |
| mc_amount | object | — | 청구액 다중 통화 스냅샷 |
| transaction_id | string\|null | `null` | PG 거래번호 (승인 후) |
| pg_error_code | string\|null | `null` | 마지막 PG 오류 코드 |
| is_pending | boolean | `true` | 결제 대기 중인지 |
| is_paid | boolean | `false` | 결제 완료인지 |
| paid_at | string\|null | `null` | 결제 완료 일시 (ISO 8601) |
| paid_at_formatted | string\|null | `null` | 결제 완료 일시 (사용자 타임존) |
| refunded_at | string\|null | `null` | 마지막 환급 일시 (ISO 8601) |
| refunded_at_formatted | string\|null | `null` | 마지막 환급 일시 (사용자 타임존) |
| created_at | string\|null | `2026-08-23 13:40` | 생성 일시 |

<a id="claim-image-resource"></a>

### ClaimImageResource (첨부 이미지)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `9` | 첨부 기본 키 |
| hash | string | `a1B2c3D4e5F6` | 서빙용 해시 (12자) |
| claimable_type | string | `return` | 귀속 대상 (`pending` 은 신청서 작성 중 임시 업로드, 그 밖에 `exchange`, `return`, `refund`) |
| claimable_id | integer | `31` | 귀속 대상 ID (`pending` 이면 주문 ID) |
| original_name | string\|null | `defect.jpg` | 원본 파일명 |
| mime_type | string\|null | `image/jpeg` | MIME 타입 |
| size | integer\|null | `183204` | 바이트 크기 |
| width | integer\|null | `800` | 가로 픽셀 |
| height | integer\|null | `600` | 세로 픽셀 |
| sort_order | integer | `1` | 정렬 순서 |
| download_url | string | `/api/modules/sirsoft-ecommerce/claim-image/a1B2c3D4e5F6?expires=…&signature=…` | 한시 서명 URL (30분) — [claim-image.md](claim-image.md) |
| created_at | string\|null | `2026-08-23 13:39` | 업로드 일시 |

---

### GET /api/modules/sirsoft-ecommerce/user/claims
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.claims.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.claims.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ClaimController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-claims.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| type | query | string | 아니오 | `exchange`, `return`, `refund`, `cancel` | 조회할 클레임 유형 (서브탭). 미전달 시 `exchange` |
| status | query | string | 아니오 | 해당 `type` 의 상태 값 (교환 14 / 반품 11 / 환불 7 / 취소 2종) | 상태 필터. 그 유형에 없는 값이면 422 |
| from | query | date | 아니오 | — | 신청일 시작 (포함) |
| to | query | string | 아니오 | 날짜, `from` 이후 | 신청일 끝. 시각이 붙어 있어도 그날 23:59:59.999999 까지로 해석합니다 |
| page | query | integer | 아니오 | min 1, 운영 설정의 페이지 상한 이하 | 페이지 번호 (상한은 초대형 OFFSET 남용 차단용이며 기본 1000. 정상 탐색은 `pagination.last_page` 범위 안에서 열려 있다) |
| per_page | query | integer | 아니오 | min 1, max 50 | 페이지당 건수 (기본 20) |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/claims?type=return&page=1&per_page=20 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data` 배열의 각 항목은 [ClaimResource](#claim-resource)(결제 블록 없음), `pagination` 은 페이지 정보._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| data | array | — | 신청서 목록 (최신순) |
| pagination.current_page | integer | `1` | 현재 페이지 |
| pagination.last_page | integer | `1` | 마지막 페이지 |
| pagination.per_page | integer | `20` | 페이지당 건수 |
| pagination.total | integer | `2` | 총 건수 |

**응답 예시**

```json
{
    "success": true,
    "message": "신청 내역을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 31,
                "claim_number": "RT20260823000007",
                "claim_type": "return",
                "status": "requested",
                "status_label": "반품신청",
                "is_withdrawable": true,
                "is_editable": true,
                "reason_label": "단순 변심",
                "total_charge_amount": 3000,
                "total_charge_amount_formatted": "3,000원",
                "items": [
                    { "order_option_id": 310, "quantity": 1, "product_name": "반팔 티셔츠", "option_name": "화이트 / M" }
                ],
                "images": [],
                "requested_at_formatted": "2026-08-23 13:40"
            }
        ],
        "pagination": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 }
    }
}
```

**오류 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-claims.read`)이 없는 경우 |
| 422 | Unprocessable Entity | `type` 이 4종 밖(`claims.type.in`), `status` 가 그 유형의 상태 어휘 밖(`claims.status.in`), `per_page` 가 50 초과, `page` 가 페이지 상한 초과 등 검증 위반 |
| 500 | Server Error | 조회 중 예기치 못한 오류 |

<!-- @generated:end -->

**설명** 마이페이지 신청내역 화면의 유형 서브탭 단위 목록입니다. 한 번에 한 유형만 받고(`type`), 취소 탭은 취소 이력(`OrderCancel`)을 같은 형태로 돌려줍니다 — 취소 이력의 `claim_number` 는 취소번호이고 `is_editable`/`is_withdrawable` 은 항상 `false` 입니다. 환불신청 탭에는 즉시취소로 자동 생성된 환불(`refund_source_type=cancel`)을 뺀 나머지가 실립니다 — 사용자가 직접 낸 환불신청(`direct`)과 반품·교환 처리에서 파생된 환불 건이 함께 보이고, 즉시취소분은 취소 탭에서 봅니다. 목록 항목에는 결제 블록이 없으므로 미수납액·결제 진입은 상세(`GET user/claims/{claimNumber}`)에서 읽습니다.

---

### GET /api/modules/sirsoft-ecommerce/user/claims/{claimNumber}
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.claims.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.claims.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ClaimController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-claims.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claimNumber | path | string | 예 | — | 클레임 번호 (교환 `EX…` / 반품 `RT…` / 환불신청 `RF…` / 취소 `CN…`. 접두사는 유형을 내포하지만 서버는 접두사를 파싱하지 않고 교환→반품→환불→취소 순으로 조회한다) |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/claims/RT20260823000007 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| claim | object | — | 신청서 상세 ([ClaimResource](#claim-resource)) — 교환·반품은 결제 블록(`payments`, `outstanding_amount`, `pending_payment`, `payment_entry`) 포함 |

**응답 예시**

```json
{
    "success": true,
    "message": "신청서를 조회했습니다.",
    "data": {
        "claim": {
            "id": 31,
            "claim_number": "RT20260823000007",
            "claim_type": "return",
            "order_number": "20260823-0012",
            "status": "payment_pending",
            "status_label": "반품결제대기",
            "is_editable": true,
            "charge_method": "pay_on_request",
            "total_charge_amount": 3000,
            "requires_additional_payment": true,
            "items": [ { "order_option_id": 310, "quantity": 1 } ],
            "images": [ { "id": 9, "hash": "a1B2c3D4e5F6", "download_url": "/api/modules/sirsoft-ecommerce/claim-image/a1B2c3D4e5F6?expires=1787000000&signature=0f3c" } ],
            "payments": [ { "payment_number": "CP20260823000001", "payment_status": "pending", "total_amount": 3000 } ],
            "outstanding_amount": 3000,
            "outstanding_amount_formatted": "3,000원",
            "pending_payment": { "payment_number": "CP20260823000001", "payment_status": "pending" },
            "payment_entry": {
                "requires_pg_payment": true,
                "pg_provider": "sirsoft-kginicis",
                "pg_payment_handler": "sirsoft-pay_kginicis.requestPayment",
                "pg_payment_data": { "order_number": "CP20260823000001", "amount": 3000, "currency": "KRW" }
            }
        }
    }
}
```

**오류 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-claims.read`)이 없는 경우 |
| 404 | Not Found | 번호에 해당하는 신청서가 없거나 본인 주문의 신청서가 아닌 경우 (`claim_not_found` — 두 경우를 구분하지 않는다) |
| 500 | Server Error | 조회 중 예기치 못한 오류 |

<!-- @generated:end -->

**설명** 클레임 번호 하나로 4종 어느 신청서든 조회합니다. 교환·반품 상세에는 결제 블록이 붙어 결제 대기 건이 있으면 `payment_entry` 로 바로 결제창을 열 수 있습니다(주문 결제와 같은 4키 계약). 다른 회원의 신청서는 404 이며 존재 여부를 드러내지 않습니다.

---

### PATCH /api/modules/sirsoft-ecommerce/user/claims/{claimNumber}
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.claims.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.claims.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ClaimController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-claims.request`

**요청 파라미터** (보낸 키만 반영 — 생략한 항목은 그대로 둔다)

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claimNumber | path | string | 예 | — | 클레임 번호 (교환·반품·환불신청만 — 취소는 수정 불가) |
| reason_code | body | string | 아니오 | max 50, 그 유형의 활성·사용자 선택 가능 사유 | 사유 코드 (해당 유형의 활성·사용자 선택 가능 사유). 귀책 구분이 함께 바뀐다 |
| reason_detail | body | string | 아니오 | max 1000 | 사유 상세 |
| charge_method | body | string | 아니오 | 환경설정 `claim.allowed_charge_methods` | 배송비 청구 방식 (교환·반품) |
| add_image_ids | body | integer[] | 아니오 | max = 환경설정 `claim.max_images`(기본 5) | 귀속시킬 임시 업로드(`pending`) 첨부 ID 목록 |
| remove_image_ids | body | integer[] | 아니오 | max = 환경설정 `claim.max_images`(기본 5) | 떼어낼 첨부 ID 목록 (이 신청서 소속만). 이 신청서 소속이 아닌 ID 는 조용히 무시된다 |
| redelivery_address | body | object | 아니오 | — | (교환) 재발송지 — `recipient_name`(max 50), `phone`(max 20), `zipcode`(max 20), `address1`/`address2`(max 255), `country_code`(2자, 원 배송지와 같은 국가만), `changed_by_user`(boolean) |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/user/claims/RT20260823000007 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "reason_code": "defective_product",
    "reason_detail": "봉제가 터져 있습니다",
    "add_image_ids": [11],
    "remove_image_ids": [9]
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| claim | object | — | 수정된 신청서 상세 ([ClaimResource](#claim-resource), 결제 블록 포함) |
| payment | object | — | (있을 때만) 차액 정산으로 새로 만들어진 결제 건 ([ClaimPaymentResource](#claim-payment-resource)) |
| requires_pg_payment | boolean | `true` | (새 결제 건이 있을 때만) 결제창을 열어야 하는지 |
| pg_provider | string\|null | `sirsoft-kginicis` | (새 결제 건이 있을 때만) PG provider id — 결제 진입 페이로드는 `sirsoft-` 접두를 붙인다(리소스 컬럼은 접두 없는 raw id) |
| pg_payment_handler | string\|null | `sirsoft-pay_kginicis.requestPayment` | (새 결제 건이 있을 때만) 결제창 핸들러 |
| pg_payment_data | object\|null | — | (새 결제 건이 있을 때만) 결제창 파라미터 |

**응답 예시**

```json
{
    "success": true,
    "message": "신청 내용이 수정되었습니다.",
    "data": {
        "claim": {
            "claim_number": "RT20260823000007",
            "reason_code": "defective_product",
            "fault_type": "seller",
            "charge_method": null,
            "total_charge_amount": 0,
            "requires_additional_payment": false,
            "outstanding_amount": 0,
            "pending_payment": null,
            "payment_entry": null
        }
    }
}
```

**오류 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-claims.request`)이 없는 경우 |
| 404 | Not Found | 번호에 해당하는 신청서가 없거나 본인 신청서가 아닌 경우 (`claim_not_found`) |
| 422 | Unprocessable Entity | 검증 위반, 승인 뒤라 수정 불가(`claim_not_editable` — 취소 이력도 동일), 사유 사용 불가(`claim_reason_unavailable`), 청구 방식 불허(`claim_charge_method_not_allowed`), 재발송지 국가 변경(`claim_redelivery_country_change_not_allowed`), 첨부 부재·타 신청서 소속(`claim_image_not_found`), 첨부 장수 초과(`claim_image_limit_exceeded`), 주문의 거래 시점 통화 스냅샷을 읽을 수 없어 차액 결제의 결제 통화 기준을 세울 수 없는 경우(`claim_payment_currency_unavailable`) |
| 500 | Server Error | 수정 처리 중 예기치 못한 오류 |

<!-- @generated:end -->

**설명** 승인 전(`is_editable=true`)에만 신청 내용을 고칩니다. 사유를 바꾸면 귀책 구분이 따라 바뀌고, 교환·반품은 배송비를 다시 계산해 총 청구액을 갱신합니다. 청구액이 달라지면 서버가 **차액을 정산**합니다 — 늘어난 만큼은 새 결제 건(`payment` + 결제 진입 4키)을 만들어 응답 최상위에 싣고, 이미 낸 금액이 새 청구액보다 크면 그 초과분을 결제 건에서 환급합니다(환급 결과는 `claim.payments` 의 `refunded_amount` 로 드러난다). 청구액이 0 이 되면 `charge_method` 는 `null` 로 비워지고 대기 중이던 결제 건은 취소됩니다. 재발송지는 원 배송지와 같은 국가 안에서만 바꿀 수 있습니다(국가가 바뀌면 배송비 산정 근거가 달라지므로 거절).

---

### POST /api/modules/sirsoft-ecommerce/user/claims/{claimNumber}/withdraw
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.claims.withdraw -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.claims.withdraw`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ClaimController@withdraw`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-claims.withdraw`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claimNumber | path | string | 예 | — | 클레임 번호 (교환·반품·환불신청) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/user/claims/RT20260823000007/withdraw HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| claim | object | — | 철회된 신청서 상세 ([ClaimResource](#claim-resource), `status=withdrawn`, `is_final=true`) |

**응답 예시**

```json
{
    "success": true,
    "message": "신청이 철회되었습니다.",
    "data": {
        "claim": {
            "claim_number": "RT20260823000007",
            "status": "withdrawn",
            "status_label": "반품철회",
            "is_final": true,
            "is_withdrawable": false,
            "is_editable": false,
            "withdrawn_at_formatted": "2026-08-23 14:10",
            "payments": [ { "payment_number": "CP20260823000001", "payment_status": "cancelled" } ],
            "outstanding_amount": 0
        }
    }
}
```

**오류 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-claims.withdraw`)이 없는 경우 |
| 404 | Not Found | 번호에 해당하는 신청서가 없거나 본인 신청서가 아닌 경우 (`claim_not_found`) |
| 422 | Unprocessable Entity | 철회할 수 없는 상태(`claim_not_withdrawable` — 교환·반품·환불신청 전 유형이 같은 키를 씁니다. 취소 이력은 철회 대상이 아니라 같은 키로 거절됩니다), 추가결제 환급 실패(`claim_payment_refund_failed`) |
| 500 | Server Error | 철회 처리 중 예기치 못한 오류 |

<!-- @generated:end -->

**설명** 승인 전 신청서를 사용자가 거둬들입니다. 교환·반품은 상품 행을 원 상태로 되돌리고(수량 분할이 있었으면 병합), 결제 대기 중인 추가결제 건은 닫고 이미 낸 추가결제는 전액 환급합니다 — 마일리지 결제분은 마일리지로, PG 결제분은 `sirsoft-ecommerce.claim_payment.refund` 훅을 통해 PG 로 돌아갑니다. 환급이 실패하면 422 로 거절되지만 **철회 자체는 이미 반영된 뒤입니다** — 신청서는 철회 상태로, 결제 건은 `paid` 로 남아 운영자가 환급을 마저 처리해야 합니다. 환불신청은 상품 행을 반려 처리하고 수량 잠금을 풀어 같은 상품을 다시 신청할 수 있게 합니다.

---

### DELETE /api/modules/sirsoft-ecommerce/user/claims/images/{image}
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.claims.images.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.claims.images.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ClaimImageController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-claims.request`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| image | path | integer | 예 | — | 첨부 ID (`ClaimImageResource.id`) |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/user/claims/images/9 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted | boolean | `true` | 삭제 결과 |

**응답 예시**

```json
{
    "success": true,
    "message": "첨부 이미지를 삭제했습니다.",
    "data": { "deleted": true }
}
```

**오류 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-claims.request`)이 없는 경우 |
| 404 | Not Found | 첨부가 없거나 본인이 올린 첨부가 아닌 경우 (`claim_image_not_found`) |
| 500 | Server Error | 파일 삭제 중 예기치 못한 오류 |

<!-- @generated:end -->

**설명** 본인이 올린 첨부(임시 업로드 또는 본인 신청서 소속)를 지웁니다. 스토리지 파일과 행을 함께 지우며, 다른 회원의 첨부는 존재 여부를 드러내지 않고 404 입니다. 승인 뒤 신청서의 첨부를 정리하려면 이 엔드포인트가 아니라 신청서 수정(`remove_image_ids`)의 편집 가능 경계를 따릅니다 — 수정이 막힌 신청서의 첨부도 이 엔드포인트로는 지울 수 있으므로 화면은 `is_editable` 이 `false` 면 삭제 버튼을 숨깁니다.
