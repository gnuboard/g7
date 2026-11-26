# Claims API 레퍼런스

> **소유**: 모듈 `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반).
> 이 문서는 회원의 클레임(교환/반품/취소·환불) 신청내역 엔드포인트와, 클레임 응답 리소스의 공통 필드 표를 다룹니다.
> 주문 단위 신청 경로(`user/orders/{id}/…`)는 [orders.md](orders.md#클레임-신청-주문-단위), 비회원 경로는 [guest.md](guest.md), 추가결제는 [claim-payments.md](claim-payments.md), 첨부 서빙은 [claim-image.md](claim-image.md), 관리자 경로는 [admin-claims.md](admin-claims.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 클레임 4종(교환 exchange / 반품 return / 환불신청 refund / 취소 cancel)은 한 응답 형태(ClaimResource)로 통일되고 claim_type 으로 구분한다
2. 신청내역 목록은 유형 서브탭 단위 페이지네이션 — type 파라미터 하나로 한 유형만 받는다 (기본 exchange)
3. 상세·수정·철회는 claim_number 로 찾고, 남의 신청서는 존재 여부를 드러내지 않고 404
4. 수정(PATCH)은 승인 전(is_editable)에만 — 사유·상세·청구 방식·첨부·재발송지·환불 계좌. 배송비가 바뀌면 차액 결제 건을 새로 만들거나 초과분을 환급한다
5. 철회는 대기 중인 결제 건을 닫고 이미 낸 추가결제를 환급한다
6. 취소·환불 접수는 **언제나 환불신청서를 만든다** — 응답의 `branch` 는 그 신청서를 어떻게 전이시킬지만 말한다 (`auto` 자동 완료 / `request` 접수에서 정지)
```

## 취소·환불 접수의 결과 계약 (`branch`)

접수 응답(`POST .../orders/{orderNumber}/refund-requests`, 비회원 동일)은 `data.branch` 를 함께 싣는다.
값은 **두 가지뿐이고, 둘 다 환불신청서를 만든다** — 다른 것은 전이 방식뿐이다.

| `branch` | 뜻 | 접수 직후 상태 |
| --- | --- | --- |
| `auto` | 승인·환불완료까지 자동 전이 | 결제수단이 **환불 자동 승인 목록**에 있으면 `completed`, 목록 밖이면 `requested` 에서 정지 |
| `request` | 접수에서 멈춘다 | `requested` (자동승인 설정 대상이고 결제수단이 목록에 있으면 `approved`) |

`branch` 는 **어느 입구로 들어왔는가**(상품 상태)를 말하고, 접수 직후 상태는 **돈이 사람 손
없이 돌아가는가**(결제수단)가 정한다. 둘은 다른 축이라 무통장 결제완료 주문은 `branch=auto`
이면서 `requested` 로 접수된다 — 화면은 `branch` 로 안내 문구를, 상태로 진행 단계를 그린다.

자동 승인 목록은 `claim.auto_approve_refund_payment_methods` 설정이며 기본값은
`card · point · free` 다. 무통장입금·가상계좌·계좌이체·휴대폰·예치금처럼 **사람이 송금해야
끝나는 수단**은 목록 밖이라 결제완료 상품이라도 `requested` 에서 멈추고, 운영자가 승인한 뒤
[환불하기]로 송금을 마친다. 자동 전이가 없으므로 그 건의 고객 알림은 `refund_requested`
1통이 정상이다(자동 전이 건은 마지막 상태에서 1통).

### 접수 시 환불 계좌 요건

**돈이 계좌로 돌아가는 주문은 `refund_bank` 없이 접수되지 않는다** — 무통장입금처럼 사람이
송금하는 수단과 입금이 끝난 가상계좌 둘 다다. 취소·환불뿐 아니라 **반품 신청**과 **돌려줄 차액이 있는
교환 신청**(저가 교환 — 미리보기의 `refund_product_amount > 0`)도 같은 게이트를 지나고, 승인 전 **수정**
(`PATCH …/claims/{claimNumber}`)도 신청서에 계좌가 남지 않게 되면 같은 이유로 거절한다. 같은 가격·
고가 교환은 돌려줄 돈이 없어 계좌를 묻지 않는다. 비면 `422` 이고 오류 키는 `refund_bank`,
문구는 가상계좌면 `validation.order.refund_bank_required_for_vbank`, 그 밖(무통장 등 사람이 송금하는
수단, PG 플러그인이 빠져 계좌 환불로 떨어진 결제 포함)이면
`validation.order.refund_bank_required_for_manual_transfer` 다(관리자 [주문취소]와 같은 두 벌).

**등록 계좌 프리필은 가린 모양이다.** `claimable-options` 의 `refund_account.prefill` 은 주문 시점 등록
계좌를 `account_number` 뒤 4자리·`holder` 첫 글자만 남기고 가려 싣는다(`masked: true`) — 이 응답은 비회원
조회 토큰을 가진 누구에게나 나가므로 원문을 싣지 않는다. 화면이 그 값을 손대지 않고 제출하면
(`refund_bank` 세 값이 가린 모양과 정확히 같으면) 서버가 등록 계좌 원문으로 되돌려 저장한다. 한 칸이라도
고치면 제출값을 그대로 쓰되, 그 제출값의 계좌번호·예금주에 가린 글자(`*`)가 남아 있으면(예금주만 바꿨거나
가린 계좌번호에 덧붙인 경우) `422 refund_bank`(`validation.claims.refund_bank.masked`)로 거절한다 — 별표째
저장되지 않게 한다. 승인 전 수정은 그 신청서에 적힌 계좌를 가린 모양 그대로 되돌려 보내는 것도 "바꾸지 않음" 으로 받는다.
세 칸 중 일부만 채우면 `validation.claims.refund_bank.incomplete` 로 거절된다. 주문 시점에 등록해 둔 계좌
(`order_payments.refund_bank_code`)가 있으면 그것으로 충분해 요청에 다시 싣지 않아도 된다.

판정은 `claimable-options` 응답의 `refund_account.required` 와 **같은 술어**
(`OrderRefundRequestService::refundsToBankAccount()` AND `hasCashToReturn()`)다. 화면은 그 값으로 계좌 칸을 세우므로,
칸이 선 주문은 반드시 이 게이트를 지나고 칸이 없는 주문은 이 게이트에 걸리지 않는다.

**돌려줄 현금이 없는 주문(마일리지로 전액 결제)은 계좌를 묻지 않는다.** 결제수단이 무통장·가상계좌라도
`refund_account.required = false` 이고, 환불신청서는 「마일리지로 환불」(`points`)로 기록되며, 계좌 송금이
없으므로 결제수단 자동승인 게이트를 거치지 않는다(`refund_auto_approved = true` — 즉시 취소 대상 상품이면
접수와 함께 완료되고 쓴 마일리지가 돌아온다). 현금과 마일리지를 함께 쓴 주문은 종전과 같다.

막지 않으면 계좌가 빈 채로 승인·[환불하기]까지 지나 **돈은 나가지 않았는데 장부만
`completed`** 로 닫힌다(2026-09-22 실측).

**돈이 수납된 주문은 예외 없이 환불신청서를 거친다.** 환불할 돈이 없는 주문(미결제·0원)만
환불신청서 없이 취소신청서가 즉시 생기고, 그때는 `data.claim` 이 취소신청서(`CN…`)다.

**취소신청서는 환불이 집행된 순간에만 생긴다.** 사람이 송금해야 하는 수단은 운영자가
[환불하기]를 누를 때까지 취소신청서가 없다 — 취소가 안 된 것이 아니라 환불신청서 단계에 있는
것이며, 진행 상황은 그 신청서가 말한다.

> 구 값 `cancel`/`refund` 는 더 쓰지 않는다. `cancel` 은 "환불신청서 없이 즉시 취소" 를 뜻했고
> 그 경로 자체가 사라졌다 — 지금의 `auto` 는 **신청서를 만들되 자동으로 전이시킨다**는 뜻이다.

## 오류 응답의 두 형태 (422)

클레임 경로의 422 는 **두 층**에서 나오고 본문 형태가 다르다. 화면은 두 형태를 모두 다뤄야 한다.

| 층 | 언제 | 본문 | 화면 처리 |
| --- | --- | --- | --- |
| 요청 형태 검증 | 필수 누락·타입·길이·허용 목록 위반 | `{"success":false,"message":"<첫 오류 문장>","errors":{"필드":["메시지"]}}` | `errors` 로 필드 인라인 표시 |
| 도메인 규칙 | 수량 초과·수량 잠금·사유 사용 불가·첨부 자격·수정 불가·결제 상태 | `{"success":false,"message":"<안내 문장>"}` — **`errors` 없음** | `message` 를 폼 상단/토스트로 표시 |

`message` 는 두 층 모두 사용자에게 그대로 보여도 되는 문장이다. 필드 정보는 `errors` 에만 있으므로,
`errors` 가 없다고 해서 실패를 삼키면 안 된다(도메인 거절이 조용히 사라진다).

요청 형태 검증 문구는 필드를 **키가 아니라 화면의 칸 이름**으로 가리킨다 — 예: `redelivery_address.recipient_name`
이 50자를 넘으면 「받는 분 필드는 50자를 초과하면 안 됩니다」다(`errors` 의 키는 그대로 필드 키). 클레임 요청마다
`attributes()` 가 규칙 키(목록 항목은 `items.*.quantity` 같은 와일드카드)에 라벨을 주고, 문구는 언어 파일
`validation.request_attributes` 에 있다. 주문 목록의 클레임 필터·배송정책의 반품·교환 배송비·주문서의 배송지 저장
요청도 같은 그룹을 쓰고, 환경설정 클레임 정책은 설정 화면 라벨과 같은 문구(`validation.claim_settings_attributes`)를 쓴다.
관리자 직권 접수(미리보기·교환·반품)와 상태 변경(단건·일괄·상품 단위)은 규칙 일부를 트레이트에서 받는다 — 그 키의 라벨은
트레이트의 `adminClaimAttributes()`·`completionAttributes()`·`transitionTrackingAttributes()` 가 주고 요청의
`attributes()` 가 합친다.

404 와 500 도 같은 봉투(`success`/`message`)를 쓰며 `errors` 는 없다. 500 의 `message` 는 항상 고정
문구이고 예외 원문·PG 원문은 담기지 않는다(원문은 서버 로그에만 남는다).

취소·환불 신청(`refund-requests`)이 자동 전이 중 거절되면 `422` 다. 사유 키가 있는 거절(수량 잠금·상태
자격 등)은 그 사유 문장이 `message` 이고, PG 가 환불을 거절한 경우는 `exceptions.order_cancel_failed`
고정 문구다 — **PG 가 돌려준 원문(가맹점 설정·내부 코드)은 `errors.detail` 에도 싣지 않는다.** 회원·비회원
주문 취소(`…/cancel`)도 같은 규칙이다. PG 원문은 서버 로그와 관리자 경로의 `errors.detail` 에만 있다.

## 엔드포인트 목록

관리자 클레임 경로(`api.modules.sirsoft-ecommerce.admin.claims.*`)도 이 문서에 생성 블록으로 함께 실립니다. 경로별 흐름과 상태 전이 설명은 [admin-claims.md](admin-claims.md) 에 있습니다. `{claimType}` 은 `exchanges` / `returns` / `refunds` 중 하나입니다.

| 메서드 | URI | 라우트명 |
| --- | --- | --- |
| GET | /api/modules/sirsoft-ecommerce/user/claims | `api.modules.sirsoft-ecommerce.user.claims.index` |
| GET | /api/modules/sirsoft-ecommerce/user/claims/{claimNumber} | `api.modules.sirsoft-ecommerce.user.claims.show` |
| PATCH | /api/modules/sirsoft-ecommerce/user/claims/{claimNumber} | `api.modules.sirsoft-ecommerce.user.claims.update` |
| POST | /api/modules/sirsoft-ecommerce/user/claims/{claimNumber}/withdraw | `api.modules.sirsoft-ecommerce.user.claims.withdraw` |
| DELETE | /api/modules/sirsoft-ecommerce/user/claims/images/{image} | `api.modules.sirsoft-ecommerce.user.claims.images.destroy` |
| GET | /api/modules/sirsoft-ecommerce/admin/claims | `api.modules.sirsoft-ecommerce.admin.claims.index` |
| POST | /api/modules/sirsoft-ecommerce/admin/claims/bulk-status | `api.modules.sirsoft-ecommerce.admin.claims.bulk-status` |
| POST | /api/modules/sirsoft-ecommerce/admin/claims/completion-preview | `api.modules.sirsoft-ecommerce.admin.claims.completion-preview` |
| GET | /api/modules/sirsoft-ecommerce/admin/claims/{claimNumber} | `api.modules.sirsoft-ecommerce.admin.claims.show-by-number` |
| GET | /api/modules/sirsoft-ecommerce/admin/claims/{claimNumber}/logs | `api.modules.sirsoft-ecommerce.admin.claims.logs` |
| PUT | /api/modules/sirsoft-ecommerce/admin/exchanges/{id}/exchange-mode | `api.modules.sirsoft-ecommerce.admin.claims.exchange-mode` |
| GET | /api/modules/sirsoft-ecommerce/admin/orders/{order}/cancellable-amount | `api.modules.sirsoft-ecommerce.admin.claims.cancellable-amount` |
| GET | /api/modules/sirsoft-ecommerce/admin/orders/{order}/claims | `api.modules.sirsoft-ecommerce.admin.claims.order-claims` |
| POST | /api/modules/sirsoft-ecommerce/admin/refunds/{id}/execute | `api.modules.sirsoft-ecommerce.admin.claims.refunds.execute` |
| GET | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id} | `api.modules.sirsoft-ecommerce.admin.claims.show` |
| PATCH | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id} | `api.modules.sirsoft-ecommerce.admin.claims.update` |
| PUT | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/amounts | `api.modules.sirsoft-ecommerce.admin.claims.amounts` |
| DELETE | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/images | `api.modules.sirsoft-ecommerce.admin.claims.images.clear` |
| POST | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/images | `api.modules.sirsoft-ecommerce.admin.claims.images.store` |
| DELETE | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/images/{image} | `api.modules.sirsoft-ecommerce.admin.claims.images.destroy` |
| POST | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/items/restore-stock | `api.modules.sirsoft-ecommerce.admin.claims.items.restore-stock` |
| PATCH | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/options/bulk-status | `api.modules.sirsoft-ecommerce.admin.claims.options.bulk-status` |
| PATCH | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/options/tracking | `api.modules.sirsoft-ecommerce.admin.claims.options.tracking` |
| POST | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/refund-request | `api.modules.sirsoft-ecommerce.admin.claims.refund-request` |
| PATCH | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/status | `api.modules.sirsoft-ecommerce.admin.claims.status` |
| POST | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/tracking/deliver | `api.modules.sirsoft-ecommerce.admin.claims.tracking.deliver` |
| POST | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/withdraw | `api.modules.sirsoft-ecommerce.admin.claims.withdraw` |

---

## 공통 리소스

<a id="claim-resource"></a>

### ClaimResource (신청서)

교환·반품·환불신청·취소 4종을 같은 형태로 내보냅니다. 공통 필드 뒤에 유형별 블록이 붙고, 상세 응답의 교환·반품에는 결제 블록이 더 붙습니다.

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `31` | 신청서 기본 키 |
| claim_number | string | `RT20260823-1402331875` | 클레임 번호 (교환 `EX…`, 반품 `RT…`, 환불신청 `RF…`, 취소 `CN…`) |
| claim_type | string | `return` | 클레임 유형 (`exchange`, `return`, `refund`, `cancel`) |
| claim_type_label | string | `반품` | 유형 라벨 |
| order_id | integer | `120` | 주문 ID |
| order_number | string\|null | `20260823-1130558472` | 주문번호 (`order` 관계 로드 시) |
| status | string | `requested` | 유형별 상태 값 (교환 `ExchangeStatusEnum`, 반품 `ReturnStatusEnum`, 환불신청 `RefundStatusEnum`, 취소 `CancelStatusEnum`) |
| status_label | string\|null | `반품신청` | 상태 라벨 (유형 접두가 붙는다 — 교환 `교환신청`, 반품 `반품신청`, 환불 `환불신청`) |
| status_variant | string\|null | `info` | 상태 뱃지 variant |
| status_icon | string\|null | `status-pickup` | 상태를 상징하는 **아이콘 의미 토큰** (아이콘 라이브러리 중립 — 어휘는 [admin-claims.md](admin-claims.md#get-apimodulessirsoft-ecommerceadminclaimsclaimnumber) "아이콘 의미 토큰 어휘" 참조). 상태 Enum 의 `icon()` 단일 출처이며, 토큰을 실제 글리프로 바꾸는 표는 템플릿의 Icon 컴포넌트가 가진다. 상태 뱃지와 진행단계 스텝퍼가 같은 그림을 그린다 |
| is_final | boolean | `false` | 종결 상태인지 (철회·반려·완료) |
| is_withdrawable | boolean | `true` | 구매자가 철회할 수 있는지 — 승인 전 **이고** 출처가 고객 신청(`refund_source_type=direct`)일 때만 `true`. 반품·교환에서 이어진 환불과 운영자가 대리 접수한 건(`admin`)은 `false` 다: 서버가 그 조작을 거절하므로 버튼만 서면 누를 때마다 422 가 된다 |
| is_editable | boolean | `true` | 구매자가 수정할 수 있는지 — 철회 가능 경계와 같고(출처 축 포함), 취소(`cancel`)는 항상 `false` |
| reason_code | string\|null | `changed_mind` | 사유 코드 (취소는 `cancel_reason_type`) |
| reason_label | string\|null | `단순 변심` | 사유 라벨 |
| reason_detail | string\|null | `사이즈가 작아요` | 사유 상세 (취소는 `cancel_reason`) |
| fault_type | string\|null | `customer` | 귀책 구분 (`customer`, `seller`, `carrier`) |
| fault_type_label | string\|null | `고객 귀책` | 귀책 라벨 |
| reject_reason | string\|null | `null` | 반려 사유 |
| requested_by | integer\|null | `7` | 신청 회원 ID (비회원 신청은 `null`) |
| is_guest_request | boolean | `false` | 비회원 신청인지 |
| refund_priority | string\|null | `pg_first` | 접수 때 고른 환불 우선순위(`pg_first` / `points_first`) — 교환·반품·환불신청서의 `refund_priority` 컬럼 저장값이다(고르지 않았으면 접수 계산에 쓴 `pg_first`). 수정 화면이 이 값을 그대로 세운다. 컬럼 도입 전 신청서·취소신청서는 `null` 이며 화면은 종전 추측으로 떨어진다 |
| requester_user_uuid | string\|null | `9b1c…` | (관리자 확장 필드) 신청자 링크의 대상 — **주문의 주인** 회원 uuid. 관리자 회원 화면은 uuid 로 연다(`requester_user_id` 는 숫자 id 라 링크에 쓰면 404). 비회원 주문은 `null` |
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
| return_address_snapshot | object\|null | — | 회수지(반송지) 대표값 스냅샷 (첫 상품의 정책). 상품마다 정책이 다르면 `return_addresses` 가 주소별로 묶어 준다(S4) |
| return_addresses | array | `[{"address_snapshot":{…},"claim_item_ids":[44,45]}]` | 상품 행 회수지의 고유값 목록 — 회수지가 둘 이상이면 화면이 주소별로 나눠 안내한다 |
| collection_completed_at_formatted | string\|null | `null` | **신청서 전체**의 회수 완료 일시 — 헤더가 회수도착에 도달했거나 완료될 때만 찍힌다(S2). 상품별 도착은 행 `collected_at_formatted` |
| is_admin_requested | boolean | `false` | 판매자가 고객을 대신해 접수한 신청인지(D8). `true` 면 구매자 수정·철회가 서지 않는다 (`request_source` 도 `admin`) |
| tracking_summary | object | `{"collection":{"first":"CJ-1","count":2},…}` | (목록) 방향별 서로 다른 운송장 수와 첫 번호 |
| tracking_groups | object\|null | — | (상세) 방향별 운송장 묶음 — [admin-claims.md](admin-claims.md) 상세 봉투의 `tracking_groups` 와 같은 형태. 구매자 응답은 관리자 전용 필드(`can_deliver`·`blocked_reason`)가 빠진다 |
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
| mc_additional_product_amount | object\|null | `{"KRW":3000,"USD":2.25}` | 추가 상품 금액의 보조 통화 맵. 신청 상품 행에 박제된 차액(`mc_price_difference`)을 부호별로 갈라 **서버가 합산**한 값이다 — 화면이 통화별 금액을 직접 더하면 그 규칙이 화면마다 갈리고 환율을 다시 보게 된다. 신청 상품 관계가 로드되지 않은 응답에서는 근거가 없어 `null` 이며 화면은 그 줄을 세우지 않는다 |
| mc_refund_product_amount | object\|null | `{"KRW":1000,"USD":0.75}` | 환불 상품 금액의 보조 통화 맵. 기준 통화 줄이 `−` 를 앞에 붙여 그리므로 **절대값**으로 낸다 |
| redelivery_address_snapshot | object\|null | — | 재발송지 스냅샷 (`recipient_name`, `phone`, `zipcode`, `address1`, `address2`, `city`, `state`, `country_code`, `changed_by_user`). 해외 주문은 국내 칸이 비어 있어 `zipcode`·`address1`·`address2` 를 해외 우편번호·거리 주소 두 줄로 채우고 도시·주를 `city`·`state` 에 담는다(국내 주문은 두 칸 `null`). 두 칸이 생기기 전에 저장된 스냅샷에는 키가 없다 |
| redelivered_at_formatted | string\|null | `null` | **신청서 전체**의 재발송 완료 일시 (S2 — 상품별은 행 `redelivered_at_formatted`) |
| exchange_mode / exchange_mode_label / is_advance_exchange | string / string / boolean | `advance` / `선교환` / `true` | 진행 방식 — 선교환은 새 물건을 먼저 보내고 헌 물건을 나중에 회수한다 |
| collection_due_at_formatted | string\|null | `2026-10-09` | 선교환 헌 물건 반송 기한 (발송이 시작되면 잡힌다) |
| is_collection_overdue / collection_overdue_days | boolean / integer | `false` / `0` | 반송 기한 경과 여부·경과 일수 |

> 송장 필드(`collection_*`·`redelivery_*`)는 헤더에서 **상품 행**([ClaimItemResource](#claim-item-resource))으로 옮겼습니다 —
> 한 신청서 안에서 상품마다 다른 택배로 오갈 수 있습니다.

**환불신청 전용 블록** (`claim_type` = `refund`)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| refund_source_type | string\|null | `direct` | 환불 발생 출처 — `direct` 고객 신청 / `admin` 운영자 대리 접수 / `return`·`exchange` 반품·교환 발 / `cancel` **구형 취소 파이프라인이 남긴 과거 행**(새로 만들지 않는다). `direct` 만 구매자가 수정·철회할 수 있다 |
| refund_method | string\|null | `pg` | 환불 수단 (`pg`, `bank`, `points`) |
| refund_method_label | string\|null | `PG사 환불` | 환불 수단 라벨 |
| refund_amount | number | `29000` | PG 환불 금액 |
| refund_amount_formatted | string | `29,000원` | PG 환불 금액 표기 |
| refund_points_amount | number | `0` | 마일리지 환불 금액 |
| refund_points_amount_formatted | string | `0원` | 사용 마일리지 반환 표기 |
| points_not_deducted | boolean | `false` | 주문의 사용 마일리지가 회원 잔액에서 차감되지 않아 마일리지 몫을 0 으로 낸 것인지 — 참이면 `refund_points_amount` 는 0 이고 화면은 「0 (차감되지 않은 마일리지)」로 이유를 보인다 |
| refund_mileage_credit_amount | number | `0` | 「마일리지로 환불」로 완료돼 회원 마일리지로 지급된 현금 환불액 (완료 전·다른 수단이면 0) |
| refund_mileage_credit_amount_formatted | string | `0원` | `refund_mileage_credit_amount` 표기 |
| exchange_difference_refund_total | number | `5000` | 교환으로 받은 상품의 환불이면 그 교환 때 낸 상위 옵션 차액 중 이 환불이 돌려주는 몫의 합계(카드·마일리지 합, 없으면 0) |
| exchange_difference_refund_total_formatted | string | `5,000원` | `exchange_difference_refund_total` 표기 |
| exchange_difference_refunds_formatted | string\|null | `5,000원 (신용카드)` | 합계와 낸 수단 표기. 수단이 둘이면 `5,000원 (신용카드 3,000원 · 마일리지 2,000원)`. 상세에서만 채우고 목록·차액 없음은 `null` |
| pg_settled_pending_ledger | boolean | `false` | PG 승인 뒤 장부 반영이 실패해 표식만 남은 승인 신청서인지 — 참이면 [환불하기]가 PG 를 부르지 않고 장부만 확정한다 |
| refund_shipping_amount | number | `0` | 환불 배송비 |
| refund_shipping_amount_formatted | string | `0원` | 환불 배송비 표기 |
| refund_total | number | `29000` | PG + 마일리지 환불 합계 |
| refund_total_formatted | string | `29,000원` | 환불 합계 표기 |
| mc_refund_amount | object | `{"USD":{"amount":22.5,"formatted":"$22.50"}}` | **PG 환불분만**의 다중 통화 스냅샷 (마일리지 제외) |
| mc_refund_total | object | `{"USD":{"amount":26.25,"formatted":"$26.25"}}` | **PG + 마일리지 합계**의 다중 통화 표기. `refund_total_formatted` 와 같은 금액의 다른 표기다 — 화면이 두 값을 같은 자리에 나란히 그리므로 보조 통화 줄은 이 필드를 읽는다(`mc_refund_amount` 를 읽으면 마일리지가 섞인 환불에서 위아래 두 줄이 다른 금액이 되는데 둘 다 정상 서식이라 흔적이 남지 않는다). 저장된 두 스냅샷을 통화별로 더한 값이며 환율을 다시 보지 않는다. 통화 스냅샷이 없는 주문은 빈 객체 |
| mc_refund_shipping_amount | object | `{"USD":{"amount":2.55,"formatted":"$2.55"}}` | 환불 배송비(`refund_shipping_amount_formatted`)의 다중 통화 표기. 상세의 배송비 대비 행은 교환·반품에서 `mc_claim_shipping_fee` 를 병기하므로, 환불 분기도 같은 자리에서 이 필드를 읽는다. 통화 스냅샷이 없는 주문은 빈 객체 |
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
| mc_refund_amount | object | `{"USD":{"amount":13.5,"formatted":"$13.50"}}` | 연결 환불 건의 PG 환불분 다중 통화 표기. 취소의 기준 통화 줄이 `refund_amount_formatted`(현금분)이므로 여기도 현금분이다 — 합계를 실으면 화면의 위아래 두 줄이 다른 금액이 된다. 연결 환불이 없으면 빈 객체 |
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
| available_payment_methods | array | `["card", "point"]` | 대기 건을 **지금 낼 수 있는** 결제 수단 값 목록. 허용 목록 `claim.charge_payment_methods` 를 신청자 자격(비회원은 마일리지 제외)으로 거른 것으로 `pay()` 와 같은 게이트다. 대기 건이 없으면 `[]`. 대기 건의 수단을 더는 낼 수 없게 됐을 때(마일리지 잔액 부족·운영자가 수단을 내림) 화면이 다른 수단을 골라 `pay` 에 `payment_method` 로 싣는 근거다 |
| available_payment_method_labels | object | `{"card": "신용카드", "bank": "계좌이체"}` | `available_payment_methods` 의 값마다 현재 언어 표시명(결제수단 카탈로그 이름). 화면은 `card`·`point` 를 자체 문구로, 그 밖의 수단은 이 이름으로 그린다. 대기 건이 없으면 빈 객체 |

**주문금액 비교 블록** (상세 응답 전용 — 목록 행에는 키 자체가 없다)

상세의 "주문금액 비교(전/후)" 표는 **접수 시점**에 동결한 재계산 스냅샷을 그린다. 환불신청서·반품·교환은 자기 행의
`original_calculation_snapshot`/`recalculated_snapshot` 을, 취소는 취소 발 환불 행의 것을 읽는다. 다통화 줄은 저장하지 않고
읽을 때 주문의 동결 환율로 다시 만든다(미리보기 `adjustment.mc_*_snapshot` 과 같은 출처). 두 스냅샷이 없는 구형 신청서
(이 컬럼이 생기기 전에 접수된 반품·교환)는 블록 전체가 `null` 이며 화면은 "접수 시점 비교표가 없습니다" 로 그린다.

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| calculation_comparison | object\|null | — | 아래 다섯 키를 가진 객체. 스냅샷이 없으면 `null` |
| calculation_comparison.original | object | `{"subtotal_amount":58000,"item_count":2,"base_currency":"KRW","formatted":{"subtotal_amount":"58,000원",…},…}` | 재계산 **전** 주문 금액 스냅샷. 미리보기의 `adjustment.original_snapshot` 과 같은 형태(금액 줄 + `base_currency` + 기준 통화 포맷 `formatted`) |
| calculation_comparison.recalculated | object | `{"subtotal_amount":29000,"item_count":1,…}` | 재계산 **후** 스냅샷 (같은 형태). 교환은 옵션 치환을 반영한 "교환 후" 값이다 |
| calculation_comparison.{original,recalculated}.total_earned_points_amount_formatted | string | `1,234P` | 적립 마일리지의 **포인트 표기**(`formatted` 맵은 기준 통화 표기라 적립 줄에 쓰면 「원」이 붙는다). 단위 글자는 다국어 키 `messages.mileage.points_format` 이 정한다. 이 키가 없던 때 접수된 스냅샷도 읽을 때 채운다 |
| calculation_comparison.mc_original | object\|null | `{"mc_total_paid_amount_formatted":{"KRW":"58,000원","USD":"$58.00"},…}` | 원 열의 다통화 줄(정가·판매가·실결제). 통화 스냅샷이 없는 주문은 `null` |
| calculation_comparison.mc_recalculated | object\|null | — | 후 열의 다통화 줄 (같은 형태) |
| calculation_comparison.substituted_items | array | `[{"order_option_id":310,"quantity":1,"product_option_id":77,"unit_price":29000,"new_unit_price":32000,"line_key":-310,"option_label":"색상: 블랙, 사이즈: L"}]` | 교환의 옵션 치환 근거 행(상품별 old→new). 교환이 아니거나 치환이 없으면 `[]` |

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
| mc_unit_price | object | `{"USD":{"amount":21.75,"formatted":"$21.75"}}` | 단가의 통화별 표기. **거래 시점에 동결된 통화별 단가**이며 수량을 곱하지 않는다(그건 `mc_subtotal_amount`). 화면은 `unit_price_formatted` 아래에 이 값을 병기하므로 둘은 같은 금액의 다른 표기여야 한다 — 이 키가 없으면 그 자리가 **다른 필드의 보조 통화로 흘러가** 단가 줄에 교환 차액이 찍힌다(2026-09-15 실측: `28,000원` 줄에 `$-3.00`). 주문 옵션이 함께 로드되지 않은 응답에서는 빈 객체 |
| subtotal_amount | number | `29000` | 단가 × 수량 |
| subtotal_amount_formatted | string | `29,000원` | 소계 표기 |
| mc_subtotal_amount | object | `{"USD":{"amount":21.75,"formatted":"$21.75"}}` | 소계의 통화별 표기. **거래 시점에 동결된 통화별 단가 × 클레임 수량**이며 환율을 다시 보지 않는다. 화면은 `subtotal_amount_formatted` 와 이 값을 같은 자리에 나란히 그리므로 둘은 같은 금액의 다른 표기여야 한다 — 차액(`mc_price_difference`)은 교환 행에만 있는 별개 축이다. 주문 옵션이 함께 로드되지 않은 응답에서는 빈 객체 |
| product_name | string\|null | `반팔 티셔츠` | 상품명 (현재 로케일) |
| product_option_name | string\|null | `화이트 / M` | 상품 옵션명 |
| option_name | string\|null | `화이트 / M` | 옵션 표시명 |
| thumbnail_url | string\|null | `/storage/products/1/thumb.jpg` | 상품 썸네일 |
| product_id | integer\|null | `12` | 상품 ID |
| product_option_id | integer\|null | `55` | 상품 옵션 ID |
| completed_at | string\|null | `null` | 상품 행 완료 일시 |
| is_splittable | boolean | `true` | 이 행을 **수량으로 나눠** 일부만 전이·송장 기록할 수 있는지. `false` 면 화면이 그 행의 수량 입력을 잠근다 — 종결된 행이다. 교환에서 후속 주문옵션이 이미 만들어진 행도 쪼갤 수 있다 — 후속 주문옵션이 같은 수량으로 함께 쪼개진다(R10). 취소 행에는 전이 축이 없어 **키가 나오지 않는다** |
| reject_reason | string\|null | `개봉 상품입니다.` | 이 상품의 반려 사유(S3) — 상품 단위 반려는 행에 남고, 신청서 헤더 `reject_reason` 은 신청서 전체가 반려될 때만 채워진다 |
| collection_carrier_id / collection_carrier_name | integer / string\|null | `3` / `CJ대한통운` | (교환·반품 행) 회수 택배사 |
| collection_tracking_number / collection_tracking_url | string\|null | `651234567890` / `https://…` | (교환·반품 행) 회수 운송장과 추적 URL(택배사 템플릿으로 서버가 조립 — 둘 중 하나라도 없거나 추적 주소가 없으면 `null`) |
| collection_shipped_at_formatted / collected_at_formatted | string\|null | — | (교환·반품 행) 회수 송장 기록·도착 시각 |
| collection_state / collection_state_label / collection_state_variant | string | `in_transit` / `회수중` / `info` | (교환·반품 행) 회수 진행 — `pending`·`in_transit`·`collected`·`unreturned` |
| is_stock_restored | boolean | `true` | (교환·반품 행) 이 행의 재고를 복구했는지(S6) |
| return_address_snapshot | object\|null | — | (교환·반품 행) 이 상품의 회수지(S4 — 자기 배송정책) |
| redelivery_carrier_id / redelivery_carrier_name / redelivery_tracking_number / redelivery_tracking_url | — | — | (교환 행) 재발송 택배사·운송장·추적 URL |
| redelivery_shipped_at_formatted / redelivered_at_formatted | string\|null | — | (교환 행) 재발송 송장 기록·도착 시각 |
| is_unreturned / unreturned_at_formatted / unreturned_reason | boolean / string\|null / string\|null | `false` / `null` / `null` | (선교환 행) 헌 물건이 오지 않아 「미회수로 완료」 됐는지와 그 사유 |
| refund_amount | number | `29000` | (환불신청 행 전용) 행 단위 환불 금액 — 취소 행에는 이 컬럼이 없어 두 키가 **응답에 나오지 않는다**. 취소의 환불액은 헤더 `refund_amount` 를 읽는다 |
| refund_amount_formatted | string | `29,000원` | 행 환불 금액 표기 |
| is_option_change | boolean | `true` | (교환 행) 다른 옵션으로의 교환인지 |
| new_product_option_id | integer\|null | `56` | (교환 행) 교환 대상 상품 옵션 ID (동일 옵션 교환이면 `null`) |
| new_option_snapshot | object\|null | — | (교환 행) 교환 대상 옵션 스냅샷. 옵션 자체(`option_name` · `option_value`)와 함께, 이 교환에 얹은 추가옵션이 신청 시점 이름·추가금으로 동결된다(`additional_options_snapshot` · `additional_options_total`) |
| new_option_label | string\|null | `색상: 화이트, 사이즈: XL` | (교환 행) 교환 대상 옵션 표시명. 스냅샷의 `option_value`(항목명까지)를 우선 읽고, 그 키가 없는 구형 스냅샷은 짧은 `option_name`(`화이트/XL`)으로 떨어진다 |
| new_additional_options | array | `[{"name":"프리미엄 포장","price_adjustment":5000,"price_adjustment_formatted":"5,000원","custom_text":""}]` | (교환 행) 이 교환에 얹은 추가옵션. 신청 시점 동결값이라 운영자가 나중에 선택지를 고치거나 지워도 바뀌지 않는다. 고르지 않았으면 빈 배열 |
| new_additional_options_total | number | `5000` | (교환 행) 추가옵션 **단위당** 합계. 이 값은 `price_difference` 에 이미 반영되어 있다 |
| new_additional_options_total_formatted | string | `5,000원` | 추가옵션 합계 표기 |
| new_unit_price | number | `31000` | (교환 행) 교환 대상 단가 |
| new_unit_price_formatted | string | `31,000원` | 교환 대상 단가 표기 |
| new_subtotal_amount | number | `72000` | (교환 행) 교환 대상 소계 (`new_unit_price × quantity`). 원 금액 자리가 소계(`subtotal_amount`)이므로 화면은 이 값을 짝으로 그린다 — 단가를 그리면 수량 1 에서는 같아 보이고 수량 2 이상에서만 좌우가 다른 단위를 말한다 |
| new_subtotal_amount_formatted | string | `72,000원` | 교환 대상 소계 표기 |
| price_difference | number | `2000` | (교환 행) 차액 (양수면 추가 결제, 음수면 환불). `((신규 옵션가 + 새로 고른 추가옵션 단위합계) − (원 옵션가 + 원 주문의 추가옵션 단위합계)) × 신청 수량` — 추가옵션을 빼고 교환하면 그만큼이 음수로 돌아온다 |
| price_difference_formatted | string | `2,000원` | 차액 표기 |
| mc_price_difference | object | — | 차액 다중 통화 스냅샷 |
| new_order_option_id | integer\|null | `null` | (교환 행) 교환 완료 후 생성된 새 주문 옵션 ID |

<a id="claim-payment-resource"></a>

### ClaimPaymentResource (추가결제 건)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `12` | 결제 건 기본 키 |
| payment_number | string | `CP20260823-1435226549` | 결제번호 (`CP` 접두사 — PG 결제창의 주문번호 자리에 쓰인다) |
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
| original_filename | string\|null | `defect.jpg` | `original_name` 의 별칭 (공용 업로더 컴포넌트가 읽는 이름) |
| mime_type | string\|null | `image/jpeg` | MIME 타입 |
| size | integer\|null | `183204` | 바이트 크기 |
| size_formatted | string | `178.91 KB` | 사람이 읽는 크기 |
| is_image | boolean | `true` | 이미지 파일 여부 |
| width | integer\|null | `800` | 가로 픽셀 |
| height | integer\|null | `600` | 세로 픽셀 |
| sort_order | integer | `1` | 정렬 순서 |
| order | integer | `1` | `sort_order` 의 별칭 (공용 업로더 호환) |
| uploader_type | string\|null | `customer` | 업로드 주체 (`customer` 고객 / `admin` 운영자 / `unknown` 미상). 분쟁 시 어느 쪽이 낸 증거인지를 가른다 |
| uploader_type_label | string\|null | `고객` | 주체 표시 라벨. `unknown` 이면 `null` — 화면은 뱃지를 그리지 않는다(조용한 거짓 대신 공백) |
| uploader_name | string\|null | `홍길동` | 업로더 이름. 관계를 함께 읽은 응답에만 실린다 |
| download_url | string | `/api/modules/sirsoft-ecommerce/claim-image/a1B2c3D4e5F6?expires=…&signature=…` | 한시 서명 URL (30분) — [claim-image.md](claim-image.md) |
| created_at | string\|null | `2026-08-23 13:39` | 업로드 일시 |

---

### GET /api/modules/sirsoft-ecommerce/admin/claims
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| type | query | string | 아니오 | — | 유형 필터 (해당 유형의 항목만 조회) |
| statuses | query | array | 아니오 | — | 상태 다중 필터. 어휘는 조회 유형(`type`)의 상태 전체이며 다른 유형의 상태 값은 422 |
| from | query | date | 아니오 | — | 조회 시작 값 (기간/범위 하한) |
| to | query | date | 아니오 | — | 조회 종료 값 (기간/범위 상한) |
| search_field | query | string | 아니오 | `claim_number`, `order_number`, `requester`, `product_name` | 검색 대상 필드명 (검색어를 적용할 컬럼) |
| search_keyword | query | string | 아니오 | max 200 | 검색 키워드 (부분 일치) |
| fault_types | query | array | 아니오 | — | 귀책 필터 (`customer`, `seller`, `carrier`). `type=cancel` 이면 보낼 수 없다(422) |
| charge_methods | query | array | 아니오 | — | 청구 방식 필터 (`pay_on_request`, `pay_on_approval`, `deduct_from_refund`). `type` 이 `cancel`, `refund` 이면 보낼 수 없다(422) |
| reason_code | query | string | 아니오 | max 50 | 사유 코드 (취소는 취소 사유 유형과 대조) |
| requester_type | query | string | 아니오 | `member`, `guest` | 회원 구분 (`member` 회원 신청, `guest` 비회원 신청) |
| request_source | query | string | 아니오 | `admin`, `customer` | 접수 출처 (`admin` 판매자 접수, `customer` 고객 신청). `type=cancel` 이면 보낼 수 없다(422) |
| collection_overdue | query | boolean | 아니오 | — | 선교환 반송 기한 경과 건만. `type=exchange` 전용 (그 밖 422) |
| pending_refund | query | boolean | 아니오 | — | 완료됐는데 환불신청서가 연결되지 않은 상품이 있는 신청서만. `type` 이 `exchange`, `return` 일 때만 (그 밖 422) |
| min_amount | query | number | 아니오 | min 0 | 금액 하한. 목록 금액 칸과 같은 값으로 비교한다 (교환, 반품은 총 청구액, 환불은 환불액과 환불 마일리지 합) |
| max_amount | query | number | 아니오 | min 0 | 금액 상한. `min_amount` 를 함께 보내면 그 값 이상이어야 한다(422) |
| currency_code | query | string | 아니오 | max 10 | 통화 필터. 상점 등록 통화와 내린 기본 제공 통화, 그리고 그 유형의 신청서에 실제로 기록된 통화만 받는다 (그 밖 422) |
| charge_payment_methods | query | array | 아니오 | — | 추가결제 결제수단 필터. `type` 이 `exchange`, `return` 일 때만. 클레임 설정의 결제수단이나 실제 추가결제 기록이 있는 값만 받는다 |
| page | query | integer | 아니오 | min 1, max 1000 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/claims?type=%EC%98%88%EC%8B%9C%EA%B0%92&statuses=%EC%98%88%EC%8B%9C%EA%B0%92&from=2026-01-01&to=2026-01-01&search_field=claim_number&search_keyword=%EC%98%88%EC%8B%9C%EA%B0%92&fault_types=%EC%98%88%EC%8B%9C%EA%B0%92&charge_methods=%EC%98%88%EC%8B%9C%EA%B0%92&reason_code=%EC%98%88%EC%8B%9C%EA%B0%92&requester_type=member&request_source=admin&collection_overdue=1&pending_refund=1&min_amount=1&max_amount=1&currency_code=%EC%98%88%EC%8B%9C%EA%B0%92&charge_payment_methods=%EC%98%88%EC%8B%9C%EA%B0%92&page=1&per_page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `17` | 기본 키 (내부 식별자) |
| claim_number | string | `EX20261010-0819150226` | 클레임 번호 (유형 접두 + 일자 + 일련번호) |
| claim_type | string | `exchange` | 신청 유형 (`exchange`, `return`, `refund`, `cancel`) |
| claim_type_label | string | `교환` | `claim_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| order_id | integer | `470` | order 식별자 (연관 리소스 참조) |
| order_number | string | `20261010-0819090090` | 원 주문 번호 |
| requester_name | string | `조은서` | 신청자 표기 (주문자명, 비회원 신청도 값이 있다) |
| requested_by | integer | `3967` | 신청 회원 ID (비회원 신청과 판매자 접수는 `null`, 취소는 취소 처리자) |
| is_guest_request | boolean | `false` | guest request 여부 |
| status | string | `withdrawn` | 상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조) |
| status_label | string | `교환철회` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| status_variant | string | `secondary` | 상태 표시 색상/스타일 변형 키 (상태 Enum variant() 산물 — UI 배지용) |
| is_final | boolean | `true` | final 여부 |
| reason_code | string | `color_mismatch` | 신청 사유 코드 |
| reason_label | string | `색상/디자인이 마음에 들지 않음` | `reason` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| fault_type | string | `customer` | 귀책 (`customer`, `seller`, `carrier`, 취소는 `null`) |
| fault_type_label | string | `고객 귀책` | `fault_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| currency_code | string | `JPY` | 금액 통화 (신청 시점 주문 통화 스냅샷) |
| item_summary | object | `{"product_name":"코튼 후드티 #12","item_count":1,"total_quanti…` | 상품 요약 `{product_name, item_count, total_quantity}` (첫 상품명, 상품 행 수, 수량 합계) |
| requested_at | string | `2026-10-03T20:33:09+00:00` | requested 일시 |
| requested_at_formatted | string | `2026-10-04 05:33:09` | `requested_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| completed_at_formatted | string | `2026-10-07 08:51:08` | `completed_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| charge_method | string | `pay_on_approval` | 청구 방식 (`pay_on_request` 신청 시 결제, `pay_on_approval` 승인 후 결제, `deduct_from_refund` 환불금 차감). 교환, 반품만 |
| charge_method_label | string | `승인 후 결제` | `charge_method` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| total_charge_amount | integer | `6000` | 고객에게 청구하는 총액 (클레임 배송비와 교환 차액 합). 교환, 반품만 |
| total_charge_amount_formatted | string | `¥6,000` | `total_charge_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| outstanding_amount | integer | `0` | 아직 수납하지 않은 청구액. 미수납 판정은 상태 라벨이 아니라 이 값으로 한다 (교환, 반품만) |
| outstanding_amount_formatted | string | `¥0` | `outstanding_amount` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| tracking_summary | object | `{"collection":{"first":null,"count":0},"redelivery":{"fir…` | 방향별(`collection` 회수, `redelivery` 재발송) 서로 다른 운송장 수와 첫 번호 `{first, count}` |
| tracking_prefill | object | `{"collection":null,"redelivery":null}` | 회수중, 발송중 일괄 전이 창의 미리 채움 송장. 살아 있는 행이 모두 같은 송장일 때만 `{carrier_id, tracking_number}`, 아니면 `null` |
| is_admin_requested | boolean | `false` | admin requested 여부 |
| has_pending_refund | boolean | `false` | pending refund 여부 |
| refund_progress | null | `null` | 이 신청서에서 생긴 최신 환불신청서의 진행 상태 `{claim_number, status, status_label, status_variant, status_icon, count}`. 환불이 없으면 `null` (교환, 반품만) |
| exchange_mode | string | `standard` | 교환 진행 방식 (`standard` 일반 교환, `advance` 선교환). 교환만 |
| exchange_mode_label | string | `일반 교환` | `exchange_mode` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| is_advance_exchange | boolean | `false` | advance exchange 여부 |
| is_collection_overdue | boolean | `false` | collection overdue 여부 |
| collection_overdue_days | integer | `0` | 선교환 반송 기한을 넘긴 일수 (기한 안이면 0). 교환만 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "신청 내역을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 17,
                "claim_number": "EX20261010-0819150226",
                "claim_type": "exchange",
                "claim_type_label": "교환",
                "order_id": 470,
                "...": "(33개 키 생략, 총 38개)"
            },
            {
                "id": 16,
                "claim_number": "EX20261010-0819148305",
                "claim_type": "exchange",
                "claim_type_label": "교환",
                "order_id": 473,
                "...": "(33개 키 생략, 총 38개)"
            },
            "... (총 7건 중 2건 표시)"
        ],
        "counts": {
            "exchange": 7,
            "return": 6,
            "refund": 11,
            "cancel": 16
        },
        "complete_defaults": {
            "create_refund_request": true,
            "restore_stock": true
        },
        "carriers": [
            {
                "value": 1,
                "label": "CJ대한통운",
                "code": "cj",
                "type": "domestic",
                "tracking_url": "https://trace.cjlogistics.com/next/tracking.html?wblNo={tracking_number}"
            },
            {
                "value": 2,
                "label": "한진택배",
                "code": "hanjin",
                "type": "domestic",
                "tracking_url": "https://www.hanjin.com/kor/CMS/DeliveryMgr/WaybillResult.do?wblnb={tracking_number}"
            },
            {
                "value": 3,
                "label": "롯데택배",
                "code": "lotte",
                "type": "domestic",
                "tracking_url": "https://www.lotteglogis.com/home/reservation/tracking/link498?InvNo={tracking_number}"
            },
            {
                "value": 4,
                "label": "로젠택배",
                "code": "logen",
                "type": "domestic",
                "tracking_url": "https://www.ilogen.com/web/personal/trace/{tracking_number}"
            },
            {
                "value": 5,
                "label": "UPS",
                "code": "ups",
                "type": "international",
                "tracking_url": "https://www.ups.com/track?tracknum={tracking_number}"
            },
            "... (총 12건 중 5건 표시)"
        ],
        "abilities": {
            "can_create": true,
            "can_update_status": true,
            "can_update_amounts": true,
            "can_execute_refund": true
        },
        "...": "(1개 키 생략, 총 6개)"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.read`)이 없거나, 신청서가 속한 주문이 운영자의 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 422 | Unprocessable Entity | 유형에 맞지 않는 필터를 보낸 경우(예: `type=cancel` 에 `fault_types`), 다른 유형의 상태 값, 어휘 밖 통화 등 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

클레임 통합 목록을 유형 서브탭 단위로 조회합니다. `type`(기본 `exchange`)으로 한 유형만 받고, `data.counts` 로 네 유형의 건수 뱃지를 함께 받습니다. 목록 응답에는 완료 처리 창의 초기 선택값(`complete_defaults`)과 회수중, 발송중 일괄 창의 택배사 선택지(`carriers`, 교환과 반품 탭만)가 함께 실립니다.

권한 범위(본인만, 소유 역할)는 신청서가 속한 주문 기준으로 쿼리에 걸려, 범위 밖 신청서는 행과 건수에서 빠집니다. 필드별 의미와 응답 예시는 [admin-claims.md](admin-claims.md#get-apimodulessirsoft-ecommerceadminclaims) 를 봅니다.



### POST /api/modules/sirsoft-ecommerce/admin/claims/bulk-status
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.bulk-status -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.bulk-status`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@bulkStatus`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.update-status`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| type | body | string | 예 | — | 신청 유형 (`exchange`, `return`, `refund`). 취소는 전이 대상이 아니다 |
| ids | body | array | 예 | min 1, max 100 | 대상 리소스 식별자 배열 (대량 작업 대상) |
| status | body | string | 예 | — | 목표 상태. 단건 전이와 같은 유형별 관리자 어휘 |
| reject_reason | body | string | 아니오 | max 2000 | `status=rejected` 일 때 필수. 반려 사유 (고른 신청서 전부에 같은 사유가 들어간다) |
| admin_memo | body | string | 아니오 | max 2000 | 관리자 메모 (교환, 반품만 저장된다) |
| trackings | body | array | 아니오 | max 100 | `status` 가 회수중, 발송중이면 필수. 신청서별 송장 `[{id, carrier_id, tracking_number}]` (`ids` 의 모든 신청서가 한 줄씩 있어야 한다) |
| completion | body | array | 아니오 | — | `status=completed` 일 때 필수. 완료 처리 옵션 객체 |
| completion.restore_stock_by_item | body | array | 아니오 | — | 신청 상품 행 ID 에서 재고 복구 여부로 가는 맵 (`{"55": true}`). 맵에 없는 행은 복구하지 않는다 |
| completion.create_refund_request | body | boolean | 아니오 | — | `completion` 과 함께 필수. 환불신청서를 지금 접수할지 (`false` 면 만들지 않는다) |
| completion.unreturned | body | array | 아니오 | — | 선교환 「미회수로 완료」. 행 ID 에서 사유(필수, 2000자 이하)로 가는 맵. 그 행은 재고를 복구하지 않는다 |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/claims/bulk-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "type": "예시값",
    "ids": [
        "예시값"
    ],
    "status": "예시값",
    "reject_reason": "예시값",
    "admin_memo": "예시값",
    "trackings": [
        "예시값"
    ],
    "completion": [
        "예시값"
    ],
    "completion.restore_stock_by_item": [
        "예시값"
    ],
    "completion.create_refund_request": true,
    "completion.unreturned": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| succeeded | array | `[31]` | 전이에 성공한 신청서 ID |
| payment_requested | array | `[34]` | 승인 지시가 추가결제 요청으로 우회된 신청서 ID (승인 후 결제 방식의 미수납 건) |
| skipped | array | `[{"id":35,"claim_number":"RT20260825-0947115036","message":"…"}]` | 건너뛴 신청서 `{id, claim_number, message}`. 없는 ID 는 `claim_number` 가 `null` |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "일괄 상태 변경을 처리했습니다.",
    "data": {
        "succeeded": [
            31
        ],
        "payment_requested": [
            34
        ],
        "skipped": [
            {
                "id": 35,
                "claim_number": "RT20260825-0947115036",
                "message": "반품반려 에서 반품승인 로는 상태를 변경할 수 없습니다."
            },
            {
                "id": 999999,
                "claim_number": null,
                "message": "해당 신청서를 찾을 수 없습니다."
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.update-status`)이 없거나, 대상 중 하나라도 권한 범위 밖인 경우 (`auth.scope_denied`, 전량 거부) |
| 422 | Unprocessable Entity | 요청 검증 실패 (어휘 밖 상태, `ids` 1~100건 위반, 송장 누락이나 중복, 완료 옵션 누락 등 — `error.errors`), 완료 처리 중 쿠폰, 재고, 마일리지 복원 실패로 되돌린 경우 (`messages.claims.side_effect_failed`) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

여러 신청서를 한 번에 전이합니다. 게이트에 막힌 건은 건너뛰고 `skipped` 에 사유와 함께 보고합니다 — 하나가 막혀도 나머지는 되돌리지 않는 부분 성공 계약입니다. 상세 계약은 [admin-claims.md](admin-claims.md#post-apimodulessirsoft-ecommerceadminclaimsbulk-status) 를 따릅니다.



### POST /api/modules/sirsoft-ecommerce/admin/claims/completion-preview
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.completion-preview -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.completion-preview`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@completionPreview`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.update-status`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claim_type | body | string | 예 | — | 신청 유형 (`exchange`, `return` — 완료 처리 창은 교환, 반품 전용) |
| create_refund_request | body | boolean | 예 | — | 창의 환불신청서 선택 상태 (지금 접수 `true`, 접수하지 않음 `false`). 기본값 없음 |
| claims | body | array | 예 | min 1, max 100 | 대상 신청서 `[{id, item_ids?, restore_stock_by_item?, unreturned_item_ids?}]` (1~100건) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/claims/completion-preview HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "claim_type": "예시값",
    "create_refund_request": true,
    "claims": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| claims | array | `[{"id":26,"claim_number":"RT20261010-0819150734","completable_item_ids":[16],…}]` | 신청서별 요약 `{id, claim_number, currency_code, completable_item_ids, completable_item_count, has_stock_restore_target, stock_restore_count, stock_discard_count, earn_recovery, coupon_restore, refund, unreturned_eligible_items}` |
| claims[].refund | object | `{"applicable":true,"already_filed":false,"blocked_reason":null,"refund_amount":7500,…}` | 환불 접수 예고. `applicable` 이 거짓이면 `blocked_reason`(`claim_refund_request_exists` 이미 접수됨, `claim_refund_not_applicable` 환불 대상 없음)으로 사유를 가른다 |
| skipped | array | `[]` | 찾지 못한 신청서 `{id, message}` (오류가 아니라 보고) |
| totals | object | `{"claim_count":1,"completable_item_count":1,"refund_amount_total":7500,…}` | 합계. 대상이 한 통화로 모일 때만 `currency_code` 와 표기 문자열이 서고, 섞이면 `null` |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "완료 처리 요약을 계산했습니다.",
    "data": {
        "claims": [
            {
                "id": 26,
                "claim_number": "RT20261010-0819150734",
                "currency_code": "JPY",
                "completable_item_ids": [
                    16
                ],
                "completable_item_count": 1,
                "has_stock_restore_target": false,
                "stock_restore_count": 0,
                "stock_discard_count": 1,
                "earn_recovery": false,
                "coupon_restore": false,
                "refund": {
                    "applicable": true,
                    "already_filed": false,
                    "blocked_reason": null,
                    "refund_amount": 7500,
                    "refund_points_amount": 0,
                    "deducted_shipping_fee": 3000,
                    "refund_amount_formatted": "¥7,500",
                    "refund_points_amount_formatted": "¥0",
                    "deducted_shipping_fee_formatted": "¥3,000"
                },
                "unreturned_eligible_items": []
            }
        ],
        "skipped": [],
        "totals": {
            "claim_count": 1,
            "completable_item_count": 1,
            "refund_amount_total": 7500,
            "refund_points_amount_total": 0,
            "currency_code": "JPY",
            "refund_amount_total_formatted": "¥7,500",
            "refund_points_amount_total_formatted": "¥0",
            "deducted_shipping_fee_total": 3000,
            "deducted_shipping_fee_total_formatted": "¥3,000"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.update-status`)이 없거나, 대상 중 하나라도 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 422 | Unprocessable Entity | `claim_type` 이 교환, 반품이 아님, `claims` 누락이나 100건 초과, `item_ids` 200건 초과나 그 신청서 소속이 아닌 행, `create_refund_request` 누락 (`error.errors`) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

완료 처리 창이 실행 전에 보여 줄 값(건별 환불 예정액, 재고 복구와 폐기 건수, 적립 회수, 쿠폰 복원 여부)을 계산합니다. DB 를 바꾸지 않습니다. 목록 일괄, 상품 단위, 상세 버튼 세 입구가 같은 창을 열고 이 응답을 씁니다. 상세 계약은 [admin-claims.md](admin-claims.md#post-apimodulessirsoft-ecommerceadminclaimscompletion-preview) 를 따릅니다.



### GET /api/modules/sirsoft-ecommerce/admin/claims/{claimNumber}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.show-by-number -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.show-by-number`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@showByNumber`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claimNumber | path | string | 예 | — | 클레임 번호 (`EX…` 교환, `RT…` 반품, `RF…` 환불, `CN…` 취소. 접두는 운영자 설정을 따르며 유형 해석은 서버가 한다) |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/claims/{claimNumber} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: 상세 화면 봉투 (`data` 직속)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| claim | object | `{"id":26,"claim_number":"RT20261010-0819150734",…}` | 신청서 상세 ([ClaimResource](#claim-resource) 상세 표현에 관리자 확장 필드와 교환, 반품 결제 블록을 더한 것) |
| claim_path_segment | string\|null | `returns` | 유형 스코프 API 경로 세그먼트 (`exchanges`, `returns`, `refunds`, 취소는 `null`) |
| abilities | object | `{"can_create":true,"can_update_status":true,"can_update_amounts":true,"can_execute_refund":true}` | 화면 버튼 게이트 (목록 응답과 같은 매핑) |
| complete_defaults | object | `{"create_refund_request":true,"restore_stock":true}` | 완료 처리 창의 초기 선택값 (설정 `claim.complete_defaults`) |
| progress_steps | array | `[{"value":"requested","label":"반품신청","state":"done","icon":"status-request","variant":"secondary"}]` | 진행단계 칸 `{value, label, state, icon, variant}`. `state` 는 `done`, `current`, `upcoming`, `off_path` |
| collection_lane | array\|null | `null` | 선교환의 「회수」 줄 (일반 교환, 반품은 `null`) |
| redelivery_address_differs | boolean\|null | `null` | 최종 재발송지가 주문 배송지와 다른지. 재발송 축이 없는 유형은 `null` |
| carriers | array | `[{"value":1,"label":"CJ대한통운","code":"cj","type":"domestic","tracking_url":"…"}]` | 회수, 재발송 송장의 택배사 선택지 (환불, 취소는 빈 배열) |
| order | object\|null | `{"id":472,"order_number":"20261010-0819091587",…}` | 결제 정보 섹션이 그리는 원 주문의 결제 축 (결제수단 카드, 금액 행 세트) |
| settlement | object\|null | `{"refund_amount":7500,"deducted_shipping_fee":3000,…}` | 지금 완료 처리하면 접수될 환불 예정 (교환, 반품만. 환불신청서는 `null`) |
| pending_refund | object\|null | `null` | 완료됐는데 환불신청서가 없고 환불액이 0 보다 클 때만 `{amount, amount_formatted, item_count}` |
| linked_claims | object | `{"source":null,"refunds":[],"latest_refund":null,"result_cancel":null}` | 연결 신청서 (원 신청서, 후속 환불신청서, 처리 결과 취소 내역) |
| reason_options | array | `[{"value":"changed_mind","label":"단순 변심","fault_type":"customer"}]` | 신청 내용 수정의 사유 선택지 (그 유형의 활성 사유 전부, 판매자 귀책 포함) |
| on_behalf | object | `{"can_withdraw":false,"can_edit":false}` | 고객 요청 대리 철회와 신청 내용 수정 버튼의 게이트 (취소는 둘 다 `false`) |
| transition_options | array | `[]` | 「신청서 상태 변경」 칸의 선택지 `{value, label}` (지금 상태에서 갈 수 있고 거절되지 않을 심사 상태만) |
| completable_item_count | integer | `1` | 지금 완료할 수 있는 신청 상품 수 (환불, 취소는 0) |
| unreturned_eligible_count | integer | `0` | 선교환에서 「미회수로 완료」 할 수 있는 행 수 |
| exchange_mode_abilities | object\|null | `null` | 진행 방식 전환 버튼 게이트 `{can_set_advance, can_set_standard, blocked_reason, advance_enabled, collection_due_days}` (교환이 아니면 `null`) |
| charge_methods | array | `[{"value":"pay_on_request","label":"신청 시 결제"}]` | 청구 방식 선택지 (환불, 취소는 빈 배열) |
| refund_methods | array | `[]` | 환불 수단 선택지 (환불신청서에만 있다) |
| cancellable | object\|null | `null` | 취소 가능 금액 산정 결과 (환불신청서에만, `cancellable-amount` 응답의 `cancellable` 과 같은 형태) |
| attachment_settings | object\|null | `{"max_images":5,"image_max_size_kb":2048,…}` | 첨부 상한과 허용 확장자 (취소는 `null`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "신청서를 조회했습니다.",
    "data": {
        "claim": {
            "id": 26,
            "claim_number": "RT20261010-0819150734",
            "claim_type": "return",
            "claim_type_label": "반품",
            "order_id": 472,
            "order_number": "20261010-0819091587",
            "status": "collecting",
            "status_label": "반품회수중",
            "status_variant": "primary",
            "status_icon": "status-pickup",
            "is_final": false,
            "is_withdrawable": false,
            "is_editable": false,
            "can_pay": true,
            "reason_code": "size_mismatch",
            "reason_label": "사이즈가 맞지 않음",
            "...": "(55개 키 생략, 총 71개)"
        },
        "claim_path_segment": "returns",
        "abilities": {
            "can_create": true,
            "can_update_status": true,
            "can_update_amounts": true,
            "can_execute_refund": true
        },
        "complete_defaults": {
            "create_refund_request": true,
            "restore_stock": true
        },
        "progress_steps": [
            {
                "value": "requested",
                "label": "반품신청",
                "state": "done",
                "icon": "status-request",
                "variant": "secondary"
            },
            {
                "value": "approved",
                "label": "반품승인",
                "state": "done",
                "icon": "status-approved",
                "variant": "info"
            },
            "... (총 6건 중 2건 표시)"
        ],
        "collection_lane": null,
        "redelivery_address_differs": null,
        "carriers": [
            {
                "value": 1,
                "label": "CJ대한통운",
                "code": "cj",
                "type": "domestic",
                "tracking_url": "https://trace.cjlogistics.com/next/tracking.html?wblNo={tracking_number}"
            },
            {
                "value": 2,
                "label": "한진택배",
                "code": "hanjin",
                "type": "domestic",
                "tracking_url": "https://www.hanjin.com/kor/CMS/DeliveryMgr/WaybillResult.do?wblnb={tracking_number}"
            },
            "... (총 12건 중 2건 표시)"
        ],
        "order": {
            "id": 472,
            "order_number": "20261010-0819091587",
            "base_currency": "JPY",
            "payment_currency": "JPY",
            "is_cross_currency": false,
            "...": "(28개 키 생략, 총 33개)"
        },
        "settlement": {
            "refund_amount": 7500,
            "refund_amount_formatted": "¥7,500",
            "refund_points_amount": 0,
            "refund_points_amount_formatted": "¥0",
            "deducted_shipping_fee": 3000,
            "...": "(9개 키 생략, 총 14개)"
        },
        "pending_refund": null,
        "linked_claims": {
            "source": null,
            "refunds": [],
            "latest_refund": null,
            "result_cancel": null
        },
        "reason_options": [
            {
                "value": "changed_mind",
                "label": "단순 변심",
                "fault_type": "customer"
            },
            {
                "value": "size_mismatch",
                "label": "사이즈가 맞지 않음",
                "fault_type": "customer"
            },
            "... (총 9건 중 2건 표시)"
        ],
        "on_behalf": {
            "can_withdraw": false,
            "can_edit": false
        },
        "transition_options": [],
        "completable_item_count": 1,
        "unreturned_eligible_count": 0,
        "exchange_mode_abilities": null,
        "charge_methods": [
            {
                "value": "pay_on_request",
                "label": "신청 시 결제"
            },
            {
                "value": "pay_on_approval",
                "label": "승인 후 결제"
            },
            "... (총 3건 중 2건 표시)"
        ],
        "refund_methods": [],
        "cancellable": null,
        "attachment_settings": {
            "max_images": 5,
            "image_max_size_kb": 2048,
            "image_max_size_mb": 2,
            "allowed_extensions": [
                "jpeg",
                "jpg",
                "... (총 5건 중 2건 표시)"
            ],
            "accept": ".jpeg,.jpg,.png,.gif,.webp"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.read`)이 없거나, 신청서가 속한 주문이 운영자의 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 404 | Not Found | 그 번호의 신청서가 없는 경우 (`exceptions.claim_not_found`) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

클레임 번호로 신청서 상세를 조회합니다. 관리자 상세 화면의 진입 경로이며 번호에서 유형을 읽는 일은 서버가 합니다. 취소(`CN…`)도 같은 경로로 열리고, 이때는 전이, 송장, 청구, 환불 실행 축이 비어 조회 전용 봉투가 됩니다. 봉투의 각 축이 왜 이 응답에 실리는지는 [admin-claims.md](admin-claims.md#get-apimodulessirsoft-ecommerceadminclaimsclaimnumber) 를 봅니다.



### GET /api/modules/sirsoft-ecommerce/admin/claims/{claimNumber}/logs
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.logs -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.logs`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@logs`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claimNumber | path | string | 예 | — | 클레임 번호 |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| sort_order | query | string | 아니오 | — | 정렬 방향 (`asc`, `desc` — 기본 `desc`) |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/claims/{claimNumber}/logs?per_page=1&sort_order=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: 코어 `ActivityLogResource` 컬렉션 (`data.data[]` + `data.links` + `data.meta`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `207040` | 활동 로그 ID |
| log_type | string | `admin` | 로그 분류 (`admin`, `user`, `system`) |
| log_type_label | string | `관리자` | 로그 분류 라벨 |
| loggable_type | string | `return` | 로그 대상 (신청서 유형, 추가결제, 첨부) |
| loggable_type_display | string | `return` | 로그 대상 표시 이름 |
| loggable_id | integer | `26` | 로그 대상 ID |
| action | string | `claim_return.status_change` | 활동 키 (`{대상}.{행위}`) |
| action_label | string | `상태 변경` | 활동 라벨 |
| localized_description | string | `반품 신청서 상태 변경 (RT20261010-0819150734)` | 현재 로케일로 해석한 설명 |
| description_key | string | `sirsoft-ecommerce::activity_log.description.claim_return_status_change` | 설명 문구의 다국어 키 |
| properties | object | `{"claim_type":"return","previous_status":"requested","new_status":"collecting"}` | 활동 시점의 부가 정보 (활동마다 키가 다르다) |
| changes | array\|null | `null` | 단건 수정의 변경 내역 `[{field, old, new}]` |
| bulk_changes | array\|null | `null` | 일괄 수정의 변경 내역 |
| has_changes | boolean | `false` | 변경 내역이 있는지 |
| actor_name | string | `관리자` | 작업자 이름. 사람이 누르지 않은 자동 전이는 「시스템」 |
| user | object | `{"uuid":null,"name":"시스템"}` | 작업자 `{uuid, name, email}` |
| ip_address | string\|null | `10.10.10.1` | 요청 IP |
| created_at | string | `2026-10-10 17:19:15` | 기록 일시 (보는 사람 시간대) |
| is_owner | boolean | `false` | 보는 사람이 작업자 본인인지 |
| abilities | object | `{"can_read":true,"can_delete":true}` | 로그 단위 동작 권한 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "활동 로그를 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 207040,
                "log_type": "admin",
                "log_type_label": "관리자",
                "loggable_type": "return",
                "loggable_type_display": "return",
                "loggable_id": 26,
                "action": "claim_return.status_change",
                "action_label": "상태 변경",
                "localized_description": "반품 신청서 상태 변경 (RT20261010-0819150734)",
                "description_key": "sirsoft-ecommerce::activity_log.description.claim_return_status_change",
                "properties": {
                    "claim_type": "return",
                    "previous_status": "requested",
                    "new_status": "collecting"
                },
                "changes": null,
                "bulk_changes": null,
                "has_changes": false,
                "actor_name": "관리자",
                "user": {
                    "uuid": "a2ee133d-646e-471c-8d18-df6ceafa834a",
                    "name": "관리자",
                    "email": "admin@example.com"
                },
                "ip_address": "10.10.10.1",
                "created_at": "2026-10-10 17:19:15",
                "is_owner": false,
                "abilities": {
                    "can_read": true,
                    "can_delete": true
                }
            },
            {
                "id": 207039,
                "log_type": "user",
                "log_type_label": "사용자",
                "loggable_type": "return",
                "loggable_type_display": "return",
                "loggable_id": 26,
                "action": "claim_return.request",
                "action_label": "신청 접수",
                "localized_description": "반품 신청 접수 (RT20261010-0819150734)",
                "description_key": "sirsoft-ecommerce::activity_log.description.claim_return_request",
                "properties": {
                    "claim_type": "return",
                    "status": "requested"
                },
                "changes": null,
                "bulk_changes": null,
                "has_changes": false,
                "actor_name": "시스템",
                "user": {
                    "uuid": null,
                    "name": "시스템"
                },
                "ip_address": "10.0.0.5",
                "created_at": "2026-10-08 13:43:09",
                "is_owner": false,
                "abilities": {
                    "can_read": true,
                    "can_delete": true
                }
            }
        ],
        "links": {
            "first": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/claims/RT20261010-0819150734/logs?page=1",
            "last": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/claims/RT20261010-0819150734/logs?page=1",
            "prev": null,
            "next": null
        },
        "meta": {
            "current_page": 1,
            "from": 1,
            "last_page": 1,
            "links": [
                {
                    "url": null,
                    "label": "pagination.previous",
                    "page": null,
                    "active": false
                },
                {
                    "url": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/claims/RT20261010-0819150734/logs?page=1",
                    "label": "1",
                    "page": 1,
                    "active": true
                },
                "... (총 3건 중 2건 표시)"
            ],
            "path": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/claims/RT20261010-0819150734/logs",
            "per_page": 2,
            "to": 2,
            "total": 2
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.read`)이 없거나, 신청서가 속한 주문이 운영자의 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 422 | Unprocessable Entity | `per_page` 가 1~100 밖이거나 `sort_order` 가 `asc`, `desc` 가 아닌 경우 (`error.errors`) |
| 404 | Not Found | 그 번호의 신청서가 없는 경우 (`exceptions.claim_not_found`) |

<!-- @generated:end -->

**설명**

신청서 한 건의 활동 로그를 조회합니다. 신청서 자신의 로그에 그 신청서의 추가결제와 첨부 로그를 합쳐 한 목록으로 돌려주고, 취소 번호면 취소 발 환불 행의 이력을 합칩니다. 상세와 같은 번호 축을 쓰므로 상세 응답을 기다리지 않고 함께 부를 수 있습니다. 자동 전이의 작업자 표기 규칙은 [admin-claims.md](admin-claims.md#get-apimodulessirsoft-ecommerceadminclaimsclaimnumberlogs) 를 봅니다.



### PUT /api/modules/sirsoft-ecommerce/admin/exchanges/{id}/exchange-mode
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.exchange-mode -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.exchange-mode`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@updateExchangeMode`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.update-status`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | — | 교환 신청서 ID |
| mode | body | string | 예 | — | 진행 방식 (`standard` 일반 교환, `advance` 선교환 — 헌 물건 회수 전에 새 물건을 먼저 보낸다) |

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/exchanges/{id}/exchange-mode HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "mode": "예시값"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| claim | object | `{"id":17,"exchange_mode":"advance",…}` | 바뀐 교환 신청서 상세 ([ClaimResource](#claim-resource) 상세 표현과 관리자 확장 필드) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "진행 방식을 바꿨습니다.",
    "data": {
        "claim": {
            "id": 17,
            "claim_number": "EX20261010-0819150226",
            "claim_type": "exchange",
            "claim_type_label": "교환",
            "status": "approved",
            "status_label": "교환승인",
            "exchange_mode": "advance",
            "exchange_mode_label": "선교환",
            "is_advance_exchange": true,
            "...": "ClaimResource 상세 표현 (관리자 확장 필드 포함)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.update-status`)이 없거나, 신청서가 속한 주문이 운영자의 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 422 | Unprocessable Entity | `mode` 가 어휘 밖인 경우 (`error.errors`), 선교환 설정이 꺼진 경우 (`claim_advance_exchange_disabled`), 바꿀 수 없는 단계이거나 재발송 송장이 붙은 경우 (`claim_exchange_mode_not_changeable`), 회수 송장이 붙은 선교환을 일반으로 되돌리려는 경우 (`claim_exchange_mode_locked_by_collection`) |
| 404 | Not Found | 그 ID 의 교환 신청서가 없는 경우 (`exceptions.claim_not_found`) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

교환의 진행 방식을 일반 교환과 선교환 사이에서 바꿉니다. 바꿀 수 있는지는 상세 봉투의 `exchange_mode_abilities` 가 같은 판정으로 미리 알려 줍니다. 선교환의 전이표와 반송 기한 규칙은 [admin-claims.md](admin-claims.md#put-apimodulessirsoft-ecommerceadminexchangesidexchange-mode) 를 봅니다.



### GET /api/modules/sirsoft-ecommerce/admin/orders/{order}/cancellable-amount
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.cancellable-amount -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.cancellable-amount`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@cancellableAmount`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | path | string | 예 | — | 주문 ID |
| refund_id | query | integer | 아니오 | — | 환불신청서 문맥. 그 신청서의 출처별 산식으로 계산하고 선점 집계에서 자기 자신을 뺀다. `items` 와 함께 보낼 수 없다 (다른 주문의 신청서면 404) |
| items | query | array | 아니오 | max 200 | 대상 상품 `[{order_option_id, cancel_quantity}]` (최대 200). 주문 소속이 아닌 옵션은 422 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/orders/{order}/cancellable-amount?refund_id=1&items=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| cancellable | object | `{"amount":212000,"domain_refundable":212000,"payment_capa…` | 취소 가능 금액 산정 결과. `amount = max(0, min(domain_refundable, payment_capacity))` 이고 `reserved` 는 한도를 깎지 않는 참고 값. `binding` 은 한도를 정한 축, `methods` 는 수단별 잔여 한도, `reserved_claims` 는 진행 중인 다른 신청서 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "취소 가능 금액을 산정했습니다.",
    "data": {
        "cancellable": {
            "amount": 212000,
            "domain_refundable": 212000,
            "payment_capacity": 212000,
            "reserved": 0,
            "binding": "domain_refundable",
            "binding_label_key": "sirsoft-ecommerce::messages.claims.cancellable_binding.domain_refundable",
            "methods": [
                {
                    "type": "payment",
                    "payment_id": 358,
                    "method": "card",
                    "currency": "JPY",
                    "remaining_local": 212000,
                    "remaining_base": 212000,
                    "method_label": "신용카드",
                    "remaining_base_formatted": "¥212,000",
                    "remaining_local_formatted": "¥212,000"
                }
            ],
            "reserved_claims": [],
            "currency": "JPY",
            "mc_amount": {
                "KRW": 201400,
                "USD": 180.2,
                "JPY": 212000,
                "CNY": 1229.6,
                "EUR": 165.36
            },
            "amount_formatted": "¥212,000",
            "domain_refundable_formatted": "¥212,000",
            "payment_capacity_formatted": "¥212,000",
            "reserved_formatted": "¥0",
            "binding_label": "환불 대상 금액이 한도입니다."
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.read`)이 없거나, 주문이 운영자의 주문 조회 범위 밖인 경우 (`auth.scope_denied`) |
| 422 | Unprocessable Entity | `refund_id` 와 `items` 를 함께 보낸 경우, 주문 소속이 아닌 옵션이 섞인 경우 (`error.errors`) |
| 404 | Not Found | 주문이 없거나, `refund_id` 가 그 주문의 환불신청서가 아닌 경우 (`exceptions.claim_not_found`) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

주문의 취소 가능 금액을 산정합니다. 환불 상세 화면의 근거 표와 주문 상세 환불 창이 함께 쓰고, 서버는 환불 저장과 실행 직전에 같은 식으로 다시 계산하므로 이 응답의 한도와 실행 한도가 같습니다. 둘 다 보내지 않으면 미취소 옵션 전량 기준입니다. 산식과 각 키의 의미는 [admin-claims.md](admin-claims.md#get-apimodulessirsoft-ecommerceadminordersordercancellable-amount) 를 봅니다.



### GET /api/modules/sirsoft-ecommerce/admin/orders/{order}/claims
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.order-claims -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.order-claims`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@orderClaims`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | path | string | 예 | — | 주문 ID |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/orders/{order}/claims HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| counts | object | `{"exchange":0,"return":0,"refund":0,"cancel":0}` | 유형별 건수 `{exchange, return, refund, cancel}` (환불 축은 출처로 거르지 않는다) |
| claims | array | `[]` | 그 주문의 전 유형 신청서 (신청 역순). 상세 표현에 관리자 확장 필드와 `detail_url`(취소는 `null`), 상품 행 금액 정규화 값(`items[].amount`, `amount_formatted`, `amount_type`), `source_claim_number` 를 더한 형태 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "신청 내역을 조회했습니다.",
    "data": {
        "counts": {
            "exchange": 0,
            "return": 0,
            "refund": 0,
            "cancel": 0
        },
        "claims": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.read`)이 없거나, 주문이 운영자의 주문 조회 범위 밖인 경우 (`auth.scope_denied`) |
| 404 | Not Found | 주문이 없는 경우 (라우트 모델 바인딩) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

주문 상세의 「클레임 내역」 섹션이 쓰는 주문 단위 클레임 목록입니다. 신청서가 상품 배열을 품는 그룹 구조 하나로 PC 표와 모바일 카드를 함께 그립니다. `amount` 는 늘 0 이상이고 방향(`charge`, `refund`, `none`)은 `amount_type` 이 나릅니다. 상세 규칙은 [admin-claims.md](admin-claims.md#get-apimodulessirsoft-ecommerceadminordersorderclaims) 를 봅니다.



### POST /api/modules/sirsoft-ecommerce/admin/refunds/{id}/execute
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.refunds.execute -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.refunds.execute`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@executeRefund`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.execute-refund`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | — | 환불신청서 ID |
| refund_amount | body | number | 아니오 | min 0 | 이번에 실행할 PG(현금) 환불액. 생략하면 저장된 값으로 실행 |
| refund_points_amount | body | number | 아니오 | min 0 | 마일리지 환불액 (비회원 주문은 422) |
| refund_method | body | string | 아니오 | — | 환불 수단 (`pg`, `bank`, `points`). 금액 수정과 같은 선택지 게이트를 거친다 |
| refund_bank | body | array | 아니오 | — | 실행하며 적어 넣는 환불 계좌 `{bank_code, account_number, holder}`. 세 값이 함께 와야 한다 |
| refund_bank.bank_code | body | string | 아니오 | max 10 | 은행 코드 |
| refund_bank.account_number | body | string | 아니오 | max 50 | 계좌 번호 |
| refund_bank.holder | body | string | 아니오 | max 50 | 예금주 |
| force_without_refund_bank | body | boolean | 아니오 | — | 계좌 없이 완료로 기록하겠다는 명시 동의 (참이면 계좌 게이트를 통과하고 송금 없이 신청서만 닫힌다) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/refunds/{id}/execute HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "refund_amount": 1,
    "refund_points_amount": 1,
    "refund_method": "예시값",
    "refund_bank": [
        "예시값"
    ],
    "refund_bank.bank_code": "예시값",
    "refund_bank.account_number": "예시값",
    "refund_bank.holder": "예시값",
    "force_without_refund_bank": true
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| claim | object | `{"id":41,"status":"completed",…}` | 실행 뒤 환불신청서 상세 ([ClaimResource](#claim-resource) 상세 표현과 관리자 확장 필드) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "환불을 실행했습니다.",
    "data": {
        "claim": {
            "id": 41,
            "claim_number": "RF20261029-0304368688",
            "claim_type": "refund",
            "claim_type_label": "환불",
            "status": "completed",
            "status_label": "환불완료",
            "refund_method": "bank",
            "refund_method_label": "계좌이체 환불",
            "...": "ClaimResource 상세 표현 (관리자 확장 필드 포함)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.execute-refund`)이 없거나, 신청서가 속한 주문이 운영자의 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 422 | Unprocessable Entity | 계좌 환불인데 계좌도 동의도 없음 (`refund_bank`), 일부만 실린 계좌, 승인 전 상태, 취소 가능 금액 초과 (`claim_refund_exceeds_cancellable`), 그 주문에서 쓸 수 없는 환불 수단, 마일리지와 쿠폰과 재고 복원 실패나 PG 거절 (`messages.claims.refund_execute_failed`, 원인은 `errors.detail`) |
| 404 | Not Found | 그 ID 의 환불신청서가 없는 경우 (`exceptions.claim_not_found`) |
| 409 | Conflict | 이미 실행됐거나 실행 중인 경우 (`exceptions.claim_refund_already_executed`) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

승인된 환불신청서의 환불을 실행합니다 (「환불하기」). 본문에 금액이 실려 있으면 금액 수정과 같은 검증을 거쳐 그 값으로 실행합니다. 되돌릴 수 있는 처리(마일리지 반환, 재고와 쿠폰 복원, 장부)를 먼저 하고 PG 를 맨 뒤에 부르며, 앞 단계가 실패하면 PG 를 부르지 않고 전체를 되돌립니다. 환불 수단 게이트와 계좌 게이트는 [admin-claims.md](admin-claims.md#post-apimodulessirsoft-ecommerceadminrefundsidexecute) 를 봅니다.



### GET /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claimType | path | string | 예 | — | 유형 경로 세그먼트 (`exchanges`, `returns`, `refunds`) |
| id | path | integer | 예 | — | 신청서 ID |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/{claimType}/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: 상세 화면 봉투 (`data` 직속)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| claim | object | `{"id":17,"claim_number":"EX20261010-0819150226",…}` | 신청서 상세 ([ClaimResource](#claim-resource) 상세 표현에 관리자 확장 필드와 교환, 반품 결제 블록을 더한 것) |
| claim_path_segment | string\|null | `exchanges` | 유형 스코프 API 경로 세그먼트 (`exchanges`, `returns`, `refunds`, 취소는 `null`) |
| abilities | object | `{"can_create":true,"can_update_status":true,"can_update_amounts":true,"can_execute_refund":true}` | 화면 버튼 게이트 (목록 응답과 같은 매핑) |
| complete_defaults | object | `{"create_refund_request":true,"restore_stock":true}` | 완료 처리 창의 초기 선택값 (설정 `claim.complete_defaults`) |
| progress_steps | array | `[{"value":"requested","label":"반품신청","state":"done","icon":"status-request","variant":"secondary"}]` | 진행단계 칸 `{value, label, state, icon, variant}`. `state` 는 `done`, `current`, `upcoming`, `off_path` |
| collection_lane | array\|null | `null` | 선교환의 「회수」 줄 (일반 교환, 반품은 `null`) |
| redelivery_address_differs | boolean\|null | `null` | 최종 재발송지가 주문 배송지와 다른지. 재발송 축이 없는 유형은 `null` |
| carriers | array | `[{"value":1,"label":"CJ대한통운","code":"cj","type":"domestic","tracking_url":"…"}]` | 회수, 재발송 송장의 택배사 선택지 (환불, 취소는 빈 배열) |
| order | object\|null | `{"id":472,"order_number":"20261010-0819091587",…}` | 결제 정보 섹션이 그리는 원 주문의 결제 축 (결제수단 카드, 금액 행 세트) |
| settlement | object\|null | `{"refund_amount":7500,"deducted_shipping_fee":3000,…}` | 지금 완료 처리하면 접수될 환불 예정 (교환, 반품만. 환불신청서는 `null`) |
| pending_refund | object\|null | `null` | 완료됐는데 환불신청서가 없고 환불액이 0 보다 클 때만 `{amount, amount_formatted, item_count}` |
| linked_claims | object | `{"source":null,"refunds":[],"latest_refund":null,"result_cancel":null}` | 연결 신청서 (원 신청서, 후속 환불신청서, 처리 결과 취소 내역) |
| reason_options | array | `[{"value":"changed_mind","label":"단순 변심","fault_type":"customer"}]` | 신청 내용 수정의 사유 선택지 (그 유형의 활성 사유 전부, 판매자 귀책 포함) |
| on_behalf | object | `{"can_withdraw":false,"can_edit":false}` | 고객 요청 대리 철회와 신청 내용 수정 버튼의 게이트 (취소는 둘 다 `false`) |
| transition_options | array | `[]` | 「신청서 상태 변경」 칸의 선택지 `{value, label}` (지금 상태에서 갈 수 있고 거절되지 않을 심사 상태만) |
| completable_item_count | integer | `1` | 지금 완료할 수 있는 신청 상품 수 (환불, 취소는 0) |
| unreturned_eligible_count | integer | `0` | 선교환에서 「미회수로 완료」 할 수 있는 행 수 |
| exchange_mode_abilities | object\|null | `null` | 진행 방식 전환 버튼 게이트 `{can_set_advance, can_set_standard, blocked_reason, advance_enabled, collection_due_days}` (교환이 아니면 `null`) |
| charge_methods | array | `[{"value":"pay_on_request","label":"신청 시 결제"}]` | 청구 방식 선택지 (환불, 취소는 빈 배열) |
| refund_methods | array | `[]` | 환불 수단 선택지 (환불신청서에만 있다) |
| cancellable | object\|null | `null` | 취소 가능 금액 산정 결과 (환불신청서에만, `cancellable-amount` 응답의 `cancellable` 과 같은 형태) |
| attachment_settings | object\|null | `{"max_images":5,"image_max_size_kb":2048,…}` | 첨부 상한과 허용 확장자 (취소는 `null`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "신청서를 조회했습니다.",
    "data": {
        "claim": {
            "id": 17,
            "claim_number": "EX20261010-0819150226",
            "claim_type": "exchange",
            "claim_type_label": "교환",
            "order_id": 470,
            "order_number": "20261010-0819090090",
            "status": "withdrawn",
            "status_label": "교환철회",
            "status_variant": "secondary",
            "status_icon": "status-withdrawn",
            "is_final": true,
            "is_withdrawable": false,
            "is_editable": false,
            "can_pay": true,
            "reason_code": "color_mismatch",
            "reason_label": "색상/디자인이 마음에 들지 않음",
            "...": "(71개 키 생략, 총 87개)"
        },
        "claim_path_segment": "exchanges",
        "abilities": {
            "can_create": true,
            "can_update_status": true,
            "can_update_amounts": true,
            "can_execute_refund": true
        },
        "complete_defaults": {
            "create_refund_request": true,
            "restore_stock": true
        },
        "progress_steps": [
            {
                "value": "requested",
                "label": "교환신청",
                "state": "done",
                "icon": "status-request",
                "variant": "secondary"
            },
            {
                "value": "approved",
                "label": "교환승인",
                "state": "upcoming",
                "icon": "status-approved",
                "variant": "info"
            },
            "... (총 10건 중 2건 표시)"
        ],
        "collection_lane": null,
        "redelivery_address_differs": false,
        "carriers": [
            {
                "value": 1,
                "label": "CJ대한통운",
                "code": "cj",
                "type": "domestic",
                "tracking_url": "https://trace.cjlogistics.com/next/tracking.html?wblNo={tracking_number}"
            },
            {
                "value": 2,
                "label": "한진택배",
                "code": "hanjin",
                "type": "domestic",
                "tracking_url": "https://www.hanjin.com/kor/CMS/DeliveryMgr/WaybillResult.do?wblnb={tracking_number}"
            },
            "... (총 12건 중 2건 표시)"
        ],
        "order": {
            "id": 470,
            "order_number": "20261010-0819090090",
            "base_currency": "JPY",
            "payment_currency": "JPY",
            "is_cross_currency": false,
            "...": "(28개 키 생략, 총 33개)"
        },
        "settlement": {
            "refund_amount": 0,
            "refund_amount_formatted": "¥0",
            "refund_points_amount": 0,
            "refund_points_amount_formatted": "¥0",
            "deducted_shipping_fee": 0,
            "...": "(9개 키 생략, 총 14개)"
        },
        "pending_refund": null,
        "linked_claims": {
            "source": null,
            "refunds": [],
            "latest_refund": null,
            "result_cancel": null
        },
        "reason_options": [
            {
                "value": "size_mismatch",
                "label": "사이즈가 맞지 않음",
                "fault_type": "customer"
            },
            {
                "value": "color_mismatch",
                "label": "색상/디자인이 마음에 들지 않음",
                "fault_type": "customer"
            },
            "... (총 8건 중 2건 표시)"
        ],
        "on_behalf": {
            "can_withdraw": false,
            "can_edit": false
        },
        "transition_options": [],
        "completable_item_count": 0,
        "unreturned_eligible_count": 0,
        "exchange_mode_abilities": {
            "can_set_advance": false,
            "can_set_standard": false,
            "blocked_reason": "쇼핑몰 설정에서 선교환이 꺼져 있습니다.",
            "advance_enabled": false,
            "collection_due_days": 14
        },
        "charge_methods": [
            {
                "value": "pay_on_request",
                "label": "신청 시 결제"
            },
            {
                "value": "pay_on_approval",
                "label": "승인 후 결제"
            },
            "... (총 3건 중 2건 표시)"
        ],
        "refund_methods": [],
        "cancellable": null,
        "attachment_settings": {
            "max_images": 5,
            "image_max_size_kb": 2048,
            "image_max_size_mb": 2,
            "allowed_extensions": [
                "jpeg",
                "jpg",
                "... (총 5건 중 2건 표시)"
            ],
            "accept": ".jpeg,.jpg,.png,.gif,.webp"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.read`)이 없거나, 신청서가 속한 주문이 운영자의 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 404 | Not Found | 해당 유형에 그 ID 의 신청서가 없는 경우 (`exceptions.claim_not_found`) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

유형과 ID 로 신청서 상세를 조회합니다. 응답은 번호 기반 상세(`GET /admin/claims/{claimNumber}`)와 같은 봉투입니다. 환불 계좌 원문은 `sirsoft-ecommerce.orders.read` 권한이 있을 때만 보이고, 없으면 마스킹됩니다. 관리자 확장 필드는 [admin-claims.md](admin-claims.md#get-apimodulessirsoft-ecommerceadminclaimtypeid) 를 봅니다.



### PATCH /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@updateRequest`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.update-status`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claimType | path | string | 예 | — | 유형 경로 세그먼트 (`exchanges`, `returns`, `refunds`) |
| id | path | integer | 예 | — | 신청서 ID |
| reason_code | body | string | 아니오 | max 50 | 사유 코드 (그 유형의 활성 사유, 판매자 귀책 사유 포함) |
| reason_detail | body | string | 아니오 | max 1000 | 상세 사유 |
| redelivery_address | body | array | 아니오 | — | 교환 재발송지 객체 (국가는 바꿀 수 없다) |
| redelivery_address.recipient_name | body | string | 아니오 | max 50 | 수령인 이름 |
| redelivery_address.phone | body | string | 아니오 | max 20 | 전화번호 |
| redelivery_address.zipcode | body | string | 아니오 | max 20 | 우편번호 |
| redelivery_address.address1 | body | string | 아니오 | max 255 | 기본 주소 |
| redelivery_address.address2 | body | string | 아니오 | max 255 | 상세 주소 |
| redelivery_address.city | body | string | 아니오 | max 100 | 도시 (해외 주문) |
| redelivery_address.state | body | string | 아니오 | max 100 | 주, 도 (해외 주문) |
| redelivery_address.country_code | body | string | 아니오 | — | 국가 코드 (ISO 3166-1 alpha-2) |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/{claimType}/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "reason_code": "예시값",
    "reason_detail": "예시값",
    "redelivery_address": [
        "서울특별시 강남구 테헤란로 1"
    ],
    "redelivery_address.recipient_name": "예시 이름",
    "redelivery_address.phone": "010-1234-5678",
    "redelivery_address.zipcode": "06234",
    "redelivery_address.address1": "서울특별시 강남구 테헤란로 1",
    "redelivery_address.address2": "서울특별시 강남구 테헤란로 1",
    "redelivery_address.city": "서울특별시 강남구 테헤란로 1",
    "redelivery_address.state": "서울특별시 강남구 테헤란로 1",
    "redelivery_address.country_code": "KR"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| claim | object | `{"id":26,"reason_code":"defective_product",…}` | 고친 신청서 상세 ([ClaimResource](#claim-resource) 상세 표현과 관리자 확장 필드) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "신청 내용을 수정했습니다.",
    "data": {
        "claim": {
            "id": 26,
            "claim_number": "RT20261010-0819150734",
            "claim_type": "return",
            "claim_type_label": "반품",
            "status": "requested",
            "status_label": "반품신청",
            "reason_code": "defective_product",
            "reason_label": "상품 불량/파손",
            "fault_type": "seller",
            "...": "ClaimResource 상세 표현 (관리자 확장 필드 포함)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.update-status`)이 없거나, 신청서가 속한 주문이 운영자의 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 422 | Unprocessable Entity | 요청 검증 실패 (`error.errors`), 수정할 수 없는 단계 (`claim_not_editable`), 그 유형에 없는 사유 |
| 404 | Not Found | 해당 유형에 그 ID 의 신청서가 없는 경우 (`exceptions.claim_not_found`) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

신청 내용(사유, 상세 사유, 교환 재발송지)을 운영자가 고칩니다. 고객 수정과 같은 경로를 운영자 축으로 쓰므로 판매자 귀책 사유도 고를 수 있고 판매자가 접수한 건도 고칠 수 있습니다. 사유가 바뀌어 배송비가 다시 계산되면 결제 대기 건과 기수납액을 그 값에 맞춥니다. 자세한 계약은 [admin-claims.md](admin-claims.md#patch-apimodulessirsoft-ecommerceadminclaimtypeid) 를 봅니다.



### PUT /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/amounts
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.amounts -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.amounts`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@updateAmounts`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.update-amounts`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claimType | path | string | 예 | — | 유형 경로 세그먼트 (`exchanges`, `returns`, `refunds`) |
| id | path | integer | 예 | — | 신청서 ID |
| claim_shipping_fee | body | number | 아니오 | min 0 | 클레임 배송비 (교환, 반품 — 반품 편도, 교환 왕복). 총 청구액이 함께 다시 계산된다 |
| charge_method | body | string | 아니오 | — | 청구 방식 (교환, 반품 — `pay_on_request`, `pay_on_approval`, `deduct_from_refund`). 청구 0원 신청서의 배송비를 올릴 때는 함께 보내야 한다 |
| admin_memo | body | string | 아니오 | max 2000 | 관리자 메모 (교환, 반품 — 보낸 요청만 갱신) |
| refund_amount | body | string | 아니오 | — | PG 환불액 (환불신청서만, 0 이상) |
| refund_points_amount | body | string | 아니오 | — | 마일리지 환불액 (환불신청서만, 0 이상, 비회원 주문은 422) |
| refund_method | body | string | 아니오 | — | 환불 수단 (환불신청서만 — `pg`, `bank`, `points`) |

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/amounts HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "claim_shipping_fee": 1,
    "charge_method": "예시값",
    "admin_memo": "예시값",
    "refund_amount": "예시값",
    "refund_points_amount": "예시값",
    "refund_method": "예시값"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| claim | object | `{"id":41,"refund_amount":15000,…}` | 금액을 고친 신청서 상세 ([ClaimResource](#claim-resource) 상세 표현과 관리자 확장 필드) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "금액을 수정했습니다.",
    "data": {
        "claim": {
            "id": 41,
            "claim_number": "RF20261029-0304368688",
            "claim_type": "refund",
            "claim_type_label": "환불",
            "status": "approved",
            "status_label": "환불승인",
            "refund_amount": 15000,
            "refund_points_amount": 3000,
            "refund_method": "pg",
            "...": "ClaimResource 상세 표현 (관리자 확장 필드 포함)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.update-amounts`)이 없거나, 신청서가 속한 주문이 운영자의 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 422 | Unprocessable Entity | 다른 유형의 축을 보낸 경우 (`error.errors`), 종결이나 처리중 신청서 (`claim_amounts_not_editable`), 청구 방식 누락 (`claim_charge_method_required`), 취소 가능 금액 초과 (`claim_refund_exceeds_cancellable`), 비회원 주문의 마일리지 환불 (`claim_refund_points_requires_member`) |
| 404 | Not Found | 해당 유형에 그 ID 의 신청서가 없는 경우 (`exceptions.claim_not_found`) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

신청서 금액을 고칩니다. 교환, 반품은 배송비와 청구 방식과 메모를, 환불신청서는 환불액과 환불 수단을 받고, 다른 유형의 축을 보내면 조용히 버리지 않고 422 로 거절합니다. 교환, 반품의 청구액을 바꾸면 아직 내지 않은 결제 대기 건이 그 금액을 따라갑니다. 오류 키 전체는 [admin-claims.md](admin-claims.md#put-apimodulessirsoft-ecommerceadminclaimtypeidamounts) 를 봅니다.



### DELETE /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/images
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.images.clear -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.images.clear`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@destroyImages`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.update-status`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claimType | path | string | 예 | — | 유형 경로 세그먼트 (`exchanges`, `returns`, `refunds`) |
| id | path | integer | 예 | — | 신청서 ID |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/images HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted | integer | `2` | 지운 첨부 수 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "첨부 이미지를 모두 삭제했습니다.",
    "data": {
        "deleted": 2
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.update-status`)이 없거나, 신청서가 속한 주문이 운영자의 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 404 | Not Found | 해당 유형에 그 ID 의 신청서가 없는 경우 (`exceptions.claim_not_found`) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

신청서의 첨부를 파일까지 모두 지웁니다 (반려, 종결 건 정리). 되돌릴 수 없습니다. 한 장만 지우려면 `DELETE …/images/{image}` 를 씁니다.



### POST /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/images
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.images.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.images.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@storeImage`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.update-status`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claimType | path | string | 예 | — | 유형 경로 세그먼트 (`exchanges`, `returns`, `refunds`) |
| id | path | integer | 예 | — | 신청서 ID |
| file | body | file | 예 | max 2048 | 이미지 파일 (multipart). `image` 이름으로 보내도 받는다. 허용 확장자와 최대 용량은 상세 봉투의 `attachment_settings` 를 따른다 |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/images HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: multipart/form-data; boundary=----G7ExampleBoundary

------G7ExampleBoundary
Content-Disposition: form-data; name="file"; filename="example.pdf"
Content-Type: application/octet-stream

(바이너리 파일 내용)
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

_단건 응답: 올린 첨부 하나 ([ClaimImageResource](#claim-image-resource)) 와 같은 값을 담은 별칭 `image`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `12` | 첨부 ID (한 장 삭제가 받는 값) |
| hash | string | `a1b2c3d4e5f6` | 내려받기 경로 식별자 |
| claimable_type | string | `return` | 귀속 신청서 유형 |
| claimable_id | integer | `26` | 귀속 신청서 ID |
| original_name | string | `evidence.jpg` | 올린 파일 이름 (`original_filename` 은 같은 값의 별칭) |
| mime_type | string | `image/jpeg` | 파일 형식 |
| size | integer | `184320` | 파일 크기 (바이트, 표기는 `size_formatted`) |
| is_image | boolean | `true` | 이미지인지 |
| width | integer\|null | `1200` | 가로 픽셀 |
| height | integer\|null | `900` | 세로 픽셀 |
| sort_order | integer | `1` | 정렬 순서 (`order` 는 같은 값의 별칭) |
| uploader_type | string | `admin` | 올린 쪽. 이 경로는 늘 `admin`(운영자 업로드) |
| uploader_type_label | string\|null | `운영자` | 올린 쪽 라벨 |
| download_url | string | `/api/modules/sirsoft-ecommerce/claim-image/{hash}?expires=…&signature=…` | 서명된 임시 내려받기 주소 |
| created_at | string | `2026-11-01 10:15:00` | 올린 일시 |
| image | object | `{"id":12,"hash":"a1b2c3d4e5f6",…}` | 같은 첨부의 별칭 (종전 계약 호환) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "이미지를 첨부했습니다.",
    "data": {
        "id": 12,
        "hash": "a1b2c3d4e5f6",
        "claimable_type": "return",
        "claimable_id": 26,
        "original_name": "evidence.jpg",
        "original_filename": "evidence.jpg",
        "mime_type": "image/jpeg",
        "size": 184320,
        "size_formatted": "180 KB",
        "is_image": true,
        "width": 1200,
        "height": 900,
        "sort_order": 1,
        "order": 1,
        "uploader_type": "admin",
        "uploader_type_label": "운영자",
        "download_url": "/api/modules/sirsoft-ecommerce/claim-image/a1b2c3d4e5f6?expires=…&signature=…",
        "created_at": "2026-11-01 10:15:00",
        "image": {
            "id": 12,
            "hash": "a1b2c3d4e5f6",
            "...": "(위와 같은 첨부 표현)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.update-status`)이 없거나, 신청서가 속한 주문이 운영자의 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 422 | Unprocessable Entity | 파일 형식, 용량 위반 (`error.errors`), 첨부 장수 상한(`claim.max_images`) 초과 |
| 404 | Not Found | 해당 유형에 그 ID 의 신청서가 없는 경우 (`exceptions.claim_not_found`) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

이미 귀속된 신청서에 운영자가 첨부를 더합니다 (예: 회수 상품 검수 사진). 운영자 업로드(`uploader_type = admin`)로 기록되어 분쟁 때 어느 쪽 증빙인지 가립니다. 응답 모양은 [admin-claims.md](admin-claims.md#post-apimodulessirsoft-ecommerceadminclaimtypeidimages) 와 같습니다.



### DELETE /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/images/{image}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.images.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.images.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@destroyImage`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.update-status`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claimType | path | string | 예 | — | 유형 경로 세그먼트 (`exchanges`, `returns`, `refunds`) |
| id | path | integer | 예 | — | 신청서 ID |
| image | path | integer | 예 | — | 첨부 ID (그 신청서에 귀속된 첨부여야 한다) |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/images/{image} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted | integer | `1` | 지운 첨부 수 (늘 1) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "첨부 이미지를 삭제했습니다.",
    "data": {
        "deleted": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.update-status`)이 없거나, 신청서가 속한 주문이 운영자의 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 404 | Not Found | 신청서가 없는 경우 (`exceptions.claim_not_found`), 그 신청서의 첨부가 아닌 경우 (`exceptions.claim_image_not_found`) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

첨부 한 장을 파일까지 지웁니다. 개인정보가 찍힌 사진 한 장 때문에 정상 증빙까지 잃지 않게 하는 경로이며 되돌릴 수 없습니다. 권한만으로는 소유를 말하지 않으므로 첨부가 그 신청서 것인지 따로 확인합니다.



### POST /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/items/restore-stock
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.items.restore-stock -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.items.restore-stock`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@restoreStock`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.update-status`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claimType | path | string | 예 | — | 유형 경로 세그먼트 (`exchanges`, `returns` — 환불은 이 축이 없다) |
| id | path | integer | 예 | — | 신청서 ID |
| items | body | array | 아니오 | max 200 | 복구할 신청 상품 행 ID 목록 (비우면 복구 가능한 행 전부) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/items/restore-stock HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "items": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| claim | object | `{"id":26,"status":"completed",…}` | 복구 뒤 신청서 상세 ([ClaimResource](#claim-resource) 상세 표현과 관리자 확장 필드) |
| restored | array | `[{"id":16,"quantity":1}]` | 이번에 재고를 복구한 행 `{id, quantity}` |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "재고를 복구했습니다.",
    "data": {
        "claim": {
            "id": 26,
            "claim_number": "RT20261010-0819150734",
            "claim_type": "return",
            "claim_type_label": "반품",
            "status": "completed",
            "status_label": "반품완료",
            "...": "ClaimResource 상세 표현 (관리자 확장 필드 포함)"
        },
        "restored": [
            {
                "id": 16,
                "quantity": 1
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.update-status`)이 없거나, 신청서가 속한 주문이 운영자의 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 422 | Unprocessable Entity | `items` 형식 위반 (`error.errors`), 복구할 행이 없는 경우 (`claim_stock_restore_not_eligible`) |
| 404 | Not Found | 해당 유형에 그 ID 의 신청서가 없는 경우 (`exceptions.claim_not_found`) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

완료됐지만 재고를 복구하지 않은 상품 행의 재고를 뒤늦게 복구합니다 (교환, 반품). 행을 잠근 뒤 복구 여부를 다시 읽어 이미 복구된 행은 건너뛰므로 여러 번 불러도 결과가 같습니다. 선교환에서 「미회수로 완료」 한 행은 대상이 아닙니다.



### PATCH /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/options/bulk-status
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.options.bulk-status -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.options.bulk-status`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@bulkOptionStatus`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.update-status`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claimType | path | string | 예 | — | 유형 경로 세그먼트 (`exchanges`, `returns`, `refunds`) |
| id | path | integer | 예 | — | 신청서 ID |
| items | body | array | 예 | min 1, max 200 | 전이 지시 `[{id, quantity?}]` (1~200건). `id` 는 이 신청서의 상품 행이어야 하고, `quantity` 를 행 수량보다 적게 주면 그 행이 갈라진다 |
| status | body | string | 예 | — | 목표 상태. 유형별 관리자 어휘에서 `payment_pending` 을 뺀 값 (결제는 신청서 단위 축) |
| reject_reason | body | string | 아니오 | max 2000 | `status=rejected` 일 때 필수. 반려 사유 (신청서의 `reject_reason` 칸에 남는다) |
| carrier_id | body | integer | 아니오 | — | 택배사 ID (회수중, 발송중 전이에 필요) |
| tracking_number | body | string | 아니오 | max 50 | 운송장 번호 (회수중, 발송중 전이에 필요) |
| completion | body | array | 아니오 | — | `status=completed` 일 때 필수. 완료 처리 옵션 객체 |
| completion.restore_stock_by_item | body | array | 아니오 | — | 신청 상품 행 ID 에서 재고 복구 여부로 가는 맵 (`{"55": true}`). 맵에 없는 행은 복구하지 않는다 |
| completion.create_refund_request | body | boolean | 아니오 | — | `completion` 과 함께 필수. 환불신청서를 지금 접수할지 (`false` 면 만들지 않는다) |
| completion.unreturned | body | array | 아니오 | — | 선교환 「미회수로 완료」. 행 ID 에서 사유(필수, 2000자 이하)로 가는 맵. 그 행은 재고를 복구하지 않는다 |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/options/bulk-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "items": [
        "예시값"
    ],
    "status": "예시값",
    "reject_reason": "예시값",
    "carrier_id": 1,
    "tracking_number": "예시값",
    "completion": [
        "예시값"
    ],
    "completion.restore_stock_by_item": [
        "예시값"
    ],
    "completion.create_refund_request": true,
    "completion.unreturned": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| succeeded | array | `[55]` | 전이한 상품 행 ID |
| skipped | array | `[{"id":56,"message":"…"}]` | 건너뛴 행 `{id, message}` |
| split | array | `[{"id":55,"quantity":2,"remainder_id":91,"remainder_quantity":1}]` | 이번 지시로 갈라진 행 (`id` 는 전이된 쪽, `remainder_id` 는 신청 상태로 남은 새 행) |
| payment_requested | boolean | `true` | 승인 지시가 추가결제 요청으로 우회된 경우에만 실린다 (그때 `succeeded` 는 빈 배열) |
| claim | object | `{"id":26,"status":"collecting",…}` | 전이 뒤 신청서 상세. 헤더 상태는 살아 있는 상품이 모두 같은 상태에 도달했을 때 따라간다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "신청 상품 상태 변경을 처리했습니다.",
    "data": {
        "succeeded": [
            55
        ],
        "skipped": [
            {
                "id": 56,
                "message": "반품반려 에서 반품회수중 로는 상태를 변경할 수 없습니다."
            }
        ],
        "split": [
            {
                "id": 55,
                "quantity": 2,
                "remainder_id": 91,
                "remainder_quantity": 1
            }
        ],
        "claim": {
            "id": 26,
            "claim_number": "RT20261010-0819150734",
            "claim_type": "return",
            "claim_type_label": "반품",
            "status": "collecting",
            "status_label": "반품회수중",
            "...": "ClaimResource 상세 표현 (관리자 확장 필드 포함)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.update-status`)이 없거나, 신청서가 속한 주문이 운영자의 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 422 | Unprocessable Entity | 요청 검증 실패 (스칼라 배열, 다른 신청서의 행, 중복 행, 어휘 밖 상태 — `error.errors`), 환불신청서의 완료 지시 (`claim_completion_flow_required`), 쿠폰, 재고, 마일리지 복원 실패 (`messages.claims.side_effect_failed`) |
| 404 | Not Found | 신청서가 없거나 취소신청서인 경우 (`exceptions.claim_not_found`) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

체크한 상품 행 단위로 상태를 옮깁니다 (교환, 반품, 환불). 행 수량보다 적은 수량을 주면 그 행이 갈라져 잔여가 새 행으로 남고 `split` 으로 알려 줍니다. 환불신청서에서 상품을 반려하면 신청서 환불액이 반려분만큼 다시 계산됩니다. 상세 계약은 [admin-claims.md](admin-claims.md#patch-apimodulessirsoft-ecommerceadminclaimtypeidoptionsbulk-status) 를 봅니다.



### PATCH /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/options/tracking
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.options.tracking -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.options.tracking`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@updateItemTracking`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.update-status`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claimType | path | string | 예 | — | 유형 경로 세그먼트 (`exchanges`, `returns` — 환불은 이 축이 없다) |
| id | path | integer | 예 | — | 신청서 ID |
| direction | body | string | 예 | — | 송장 방향 (`collection` 회수, `redelivery` 재발송 — 교환만) |
| carrier_id | body | integer | 아니오 | — | 택배사 ID. `tracking_number` 와 둘 다 보내거나 둘 다 비운다 (둘 다 비우면 송장을 지운다) |
| tracking_number | body | string | 아니오 | max 50 | 운송장 번호. `carrier_id` 와 짝으로만 받는다 (하나만 오면 422) |
| items | body | array | 예 | min 1, max 200 | 대상 상품 행 `[{id, quantity?}]` (1~200건). 수량을 행보다 적게 주면 그 행을 쪼개 지정 수량 행에만 적는다 |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/options/tracking HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "direction": "예시값",
    "carrier_id": 1,
    "tracking_number": "예시값",
    "items": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| claim | object | `{"id":26,"items":[…],…}` | 송장을 적은 뒤 신청서 상세 (행마다 `collection_*`, `redelivery_*` 송장 필드 갱신) |
| split | array | `[{"id":16,"quantity":1,"remainder_id":92,"remainder_quantity":2}]` | 쪼갠 행 `{id, quantity, remainder_id, remainder_quantity}` |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "운송장 정보를 저장했습니다.",
    "data": {
        "claim": {
            "id": 26,
            "claim_number": "RT20261010-0819150734",
            "claim_type": "return",
            "claim_type_label": "반품",
            "status": "approved",
            "status_label": "반품승인",
            "...": "ClaimResource 상세 표현 (관리자 확장 필드 포함)"
        },
        "split": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.update-status`)이 없거나, 신청서가 속한 주문이 운영자의 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 422 | Unprocessable Entity | 택배사와 운송장 중 하나만 온 경우 (`claim_tracking_incomplete`), 반품에 재발송 방향 (`claim_tracking_direction_not_allowed`), 송장을 쓸 수 없는 행 상태 (`claim_tracking_item_not_eligible`), 회수지가 다른 상품을 한 운송장으로 묶은 경우 (`claim_tracking_mixed_return_address`) |
| 404 | Not Found | 신청서가 없는 경우 (`exceptions.claim_not_found`), 이 신청서의 상품 행이 아닌 경우 (`exceptions.claim_item_not_found`) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

「송장만 저장」 입니다. 고른 상품 행에 한 방향 송장을 적고 상태는 옮기지 않습니다 (교환, 반품). 송장은 상품 행마다 있어 한 신청서 안에서 상품마다 다른 택배로 오갈 수 있습니다. 회수중, 발송중 행의 송장은 지울 수 없습니다. 쓸 수 있는 행 상태는 [admin-claims.md](admin-claims.md#patch-apimodulessirsoft-ecommerceadminclaimtypeidoptionstracking) 를 봅니다.



### POST /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/refund-request
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.refund-request -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.refund-request`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@storeRefundRequest`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.update-status`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claimType | path | string | 예 | — | 유형 경로 세그먼트 (`exchanges`, `returns` — 환불은 이 축이 없다) |
| id | path | integer | 예 | — | 신청서 ID |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/refund-request HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| refund | object | `{"id":42,"claim_number":"RF…","claim_type":"refund","status":"requested",…}` | 새로 접수한 환불신청서 ([ClaimResource](#claim-resource) 와 관리자 확장 필드) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "환불신청서를 접수했습니다.",
    "data": {
        "refund": {
            "id": 42,
            "claim_number": "RF20261101-1015001234",
            "claim_type": "refund",
            "claim_type_label": "환불",
            "status": "requested",
            "status_label": "환불신청",
            "...": "ClaimResource 표현 (관리자 확장 필드 포함)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.update-status`)이 없거나, 신청서가 속한 주문이 운영자의 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 404 | Not Found | 해당 유형에 그 ID 의 신청서가 없는 경우 (`exceptions.claim_not_found`) |
| 422 | Unprocessable Entity | 완료된 상품이 없는 경우 (`claim_completion_items_invalid`), 이미 모두 접수된 경우 (`claim_refund_request_exists`), 환불 대상이 없는 경우 (`claim_refund_not_applicable`) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

완료 때 접수하지 않은 신청서의 환불신청서를 수동으로 접수합니다 (교환, 반품). 반품은 완료된 상품 전체로 한 건, 교환은 완료됐으나 아직 접수되지 않은 저가 상품을 모아 한 건을 만듭니다. 화면은 이미 접수됨과 환불 대상 없음을 구분해 안내합니다.



### PATCH /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/status
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.status -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.status`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@updateStatus`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.update-status`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claimType | path | string | 예 | — | 유형 경로 세그먼트 (`exchanges`, `returns`, `refunds`) |
| id | path | integer | 예 | — | 신청서 ID |
| status | body | string | 예 | — | 목표 상태. 유형별 관리자 어휘 (교환, 반품은 `requested`, `paid`, `withdrawn` 을 뺀 전체, 환불은 `approved`, `on_hold`, `rejected`) |
| reject_reason | body | string | 아니오 | max 2000 | `status=rejected` 일 때 필수. 구매자 화면에 그대로 안내되는 반려 사유 |
| admin_memo | body | string | 아니오 | max 2000 | 관리자 메모 (교환, 반품만 저장. 보낸 요청만 갱신) |
| carrier_id | body | integer | 아니오 | — | 택배사 ID. 회수중, 발송중, 회수도착, 발송도착 전이가 싣는 송장은 옮겨지는 상품 행에 쓰인다 |
| tracking_number | body | string | 아니오 | max 50 | 운송장 번호. 회수중, 발송중은 송장이 있어야 들어간다 (요청에 없으면 각 행이 이미 가진 송장) |
| completion | body | array | 아니오 | — | `status=completed` 일 때 필수. 완료 처리 옵션 객체 |
| completion.restore_stock_by_item | body | array | 아니오 | — | 신청 상품 행 ID 에서 재고 복구 여부로 가는 맵 (`{"55": true}`). 맵에 없는 행은 복구하지 않는다 |
| completion.create_refund_request | body | boolean | 아니오 | — | `completion` 과 함께 필수. 환불신청서를 지금 접수할지 (`false` 면 만들지 않는다) |
| completion.unreturned | body | array | 아니오 | — | 선교환 「미회수로 완료」. 행 ID 에서 사유(필수, 2000자 이하)로 가는 맵. 그 행은 재고를 복구하지 않는다 |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "status": "예시값",
    "reject_reason": "예시값",
    "admin_memo": "예시값",
    "carrier_id": 1,
    "tracking_number": "예시값",
    "completion": [
        "예시값"
    ],
    "completion.restore_stock_by_item": [
        "예시값"
    ],
    "completion.create_refund_request": true,
    "completion.unreturned": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| claim | object | `{"id":26,"status":"approved",…}` | 전이 뒤 신청서 상세 ([ClaimResource](#claim-resource) 상세 표현과 관리자 확장 필드) |
| payment_requested | boolean | `false` | 승인 지시가 추가결제 요청으로 우회됐는지 (참이면 메시지도 「추가결제를 요청했습니다」) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "신청 상태가 변경되었습니다.",
    "data": {
        "claim": {
            "id": 26,
            "claim_number": "RT20261010-0819150734",
            "claim_type": "return",
            "claim_type_label": "반품",
            "status": "approved",
            "status_label": "반품승인",
            "...": "ClaimResource 상세 표현 (관리자 확장 필드 포함)"
        },
        "payment_requested": false
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.update-status`)이 없거나, 신청서가 속한 주문이 운영자의 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 422 | Unprocessable Entity | 요청 검증 실패 (어휘 밖 상태, 반려 사유나 완료 옵션 누락 — `error.errors`), 전이 게이트 위반 (`claim_transition_not_allowed`), 미수납 승인 (`claim_payment_outstanding`), 청구 없는 결제 요청 (`claim_payment_not_required`), 송장 없는 회수중, 발송중 (`claim_tracking_required`), 쿠폰, 재고, 마일리지 복원 실패 (`messages.claims.side_effect_failed`) |
| 404 | Not Found | 해당 유형에 그 ID 의 신청서가 없는 경우 (`exceptions.claim_not_found`) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

신청서 한 건의 상태를 옮깁니다. 완료(`status=completed`)는 `completion` 객체가 있어야 하고, 승인 후 결제 방식의 미수납 건을 승인하면 승인 대신 추가결제 요청으로 우회합니다. 회수와 재발송 단계는 앞으로 건너뛸 수 있지만 승인은 건너뛰지 않습니다. 승인과 추가결제의 상호작용 표는 [admin-claims.md](admin-claims.md#patch-apimodulessirsoft-ecommerceadminclaimtypeidstatus) 를 봅니다.



### POST /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/tracking/deliver
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.tracking.deliver -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.tracking.deliver`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@deliverTracking`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.update-status`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claimType | path | string | 예 | — | 유형 경로 세그먼트 (`exchanges`, `returns` — 환불은 이 축이 없다) |
| id | path | integer | 예 | — | 신청서 ID |
| direction | body | string | 예 | — | 송장 방향 (`collection` 회수, `redelivery` 재발송) |
| carrier_id | body | integer | 예 | — | 택배사 ID |
| tracking_number | body | string | 예 | max 50 | 도착 처리할 운송장 번호 (이 운송장에 묶인 상품 행 전부가 대상) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/tracking/deliver HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "direction": "예시값",
    "carrier_id": 1,
    "tracking_number": "예시값"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| claim | object | `{"id":26,"status":"collected",…}` | 도착 처리 뒤 신청서 상세 |
| succeeded | array | `[16]` | 도착 처리한 상품 행 ID |
| skipped | array | `[{"id":17,"message":"…"}]` | 건너뛴 행 `{id, message}` (이미 도착, 전이 불가) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "운송장의 상품을 도착 처리했습니다.",
    "data": {
        "claim": {
            "id": 26,
            "claim_number": "RT20261010-0819150734",
            "claim_type": "return",
            "claim_type_label": "반품",
            "status": "collected",
            "status_label": "반품회수도착",
            "...": "ClaimResource 상세 표현 (관리자 확장 필드 포함)"
        },
        "succeeded": [
            16
        ],
        "skipped": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.update-status`)이 없거나, 신청서가 속한 주문이 운영자의 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 422 | Unprocessable Entity | 요청 검증 실패 (`error.errors`), 그 운송장을 가진 행이 없는 경우 (`claim_tracking_not_found`) |
| 404 | Not Found | 해당 유형에 그 ID 의 신청서가 없는 경우 (`exceptions.claim_not_found`) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

운송장 한 개에 묶인 상품 행을 한꺼번에 도착 처리합니다 (교환, 반품 — 배송 정보 탭의 [도착 처리]). 일반 흐름은 행을 회수도착이나 발송도착으로 옮기고, 선교환의 회수 방향은 상태를 옮기지 않고 행의 회수 시각만 찍습니다. 옮길 수 없는 행은 건너뛰고 사유와 함께 돌려줍니다.



### POST /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/withdraw
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.claims.withdraw -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.claims.withdraw`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimController@withdrawOnBehalf`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.claims.update-status`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claimType | path | string | 예 | — | 유형 경로 세그먼트 (`exchanges`, `returns`, `refunds`) |
| id | path | integer | 예 | — | 신청서 ID |
| reason | body | string | 예 | max 2000 | 철회 사유 (신청서 `withdraw_reason` 에 남고 철회자는 운영자로 기록된다) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/withdraw HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "reason": "예시값"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| claim | object | `{"id":26,"status":"withdrawn",…}` | 철회 뒤 신청서 상세 ([ClaimResource](#claim-resource) 상세 표현과 관리자 확장 필드) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "고객 요청으로 신청을 철회했습니다.",
    "data": {
        "claim": {
            "id": 26,
            "claim_number": "RT20261010-0819150734",
            "claim_type": "return",
            "claim_type_label": "반품",
            "status": "withdrawn",
            "status_label": "반품철회",
            "withdrawn_by_admin_name": "관리자",
            "...": "ClaimResource 상세 표현 (관리자 확장 필드 포함)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.claims.update-status`)이 없거나, 신청서가 속한 주문이 운영자의 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 422 | Unprocessable Entity | `reason` 누락이나 2000자 초과 (`error.errors`), 철회할 수 없는 단계 (`claim_not_withdrawable`) |
| 404 | Not Found | 해당 유형에 그 ID 의 신청서가 없는 경우 (`exceptions.claim_not_found`) |
| 500 | Internal Server Error | 처리 중 예외 (`messages.claims.operation_failed`, 원인 요약은 `errors.detail`) |

<!-- @generated:end -->

**설명**

고객 요청으로 신청을 대신 철회합니다 (교환, 반품, 환불). 철회할 수 있는 단계는 고객 철회와 같고 판매자가 접수한 건도 거둘 수 있습니다. 결제 환급은 기존 철회 경로를 그대로 타고, 고객 알림에는 「고객님 요청으로 판매자가 철회했습니다」 가 붙습니다.



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

**목록은 화면이 그 행에서 그리는 것만 싣습니다.** 아래 필드는 목록 표현에서 언제나 `null`(첨부는 빈 배열)로 내려갑니다 — 값 자체는 상세(`GET user/claims/{claimNumber}`)에서 읽습니다. `per_page` 상한이 50 이고 신청서 하나가 상품 200줄까지 가질 수 있어, 그대로 실으면 한 페이지를 여는 것만으로 수천 줄이 응답에 실립니다.

| 목록에서 빠지는 필드 | 대신 읽는 곳 |
| --- | --- |
| `images` (빈 배열) | 상세 |
| `reason_detail` · `reject_reason` | 상세 |
| `shipping_fee_snapshot` · `return_address_snapshot` · `redelivery_address_snapshot` | 상세 |
| `refund_bank_code` · `refund_bank_name` · `refund_bank_account` · `refund_bank_holder` | 상세 |

상품 행(`items`)은 목록에도 실립니다 — 신청내역 화면이 상품마다 썸네일·옵션·수량·금액을 그리기 때문입니다.

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| data | array | — | 신청서 목록 (최신순) |
| pagination.current_page | integer | `1` | 현재 페이지 |
| pagination.last_page | integer\|null | `1` | 마지막 페이지. **총 건수가 상한에 잘리면 `null`** 입니다 — 그때는 마지막 페이지로 바로 뛸 수 없고 `has_more_pages` 로 다음 장 유무만 판단합니다 |
| pagination.per_page | integer | `20` | 페이지당 건수 |
| pagination.total | integer | `2` | 총 건수. `total_relation` 이 함께 오면 그 값이 정확한 수인지 "이상" 인지를 알려 줍니다 |
| pagination.has_more_pages | boolean | `false` | 다음 페이지 존재 여부. `last_page` 가 `null` 일 때 페이지 이동의 유일한 근거입니다 |
| pagination.from | integer\|null | `1` | 이 페이지 첫 항목의 전체 순번 (빈 페이지면 `null`) |
| pagination.to | integer\|null | `2` | 이 페이지 마지막 항목의 전체 순번 (빈 페이지면 `null`) |

**응답 예시**

```json
{
    "success": true,
    "message": "신청 내역을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 31,
                "claim_number": "RT20260823-1402331875",
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
                "reason_detail": null,
                "reject_reason": null,
                "shipping_fee_snapshot": null,
                "return_address_snapshot": null,
                "refund_bank_code": null,
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

**설명** 마이페이지 신청내역 화면의 유형 서브탭 단위 목록입니다. 한 번에 한 유형만 받고(`type`), 취소 탭은 취소 이력(`OrderCancel`)을 같은 형태로 돌려줍니다 — 취소 이력의 `claim_number` 는 취소번호이고 `is_editable`/`is_withdrawable` 은 항상 `false` 입니다. 환불 탭에는 **출처와 무관하게 그 주문의 모든 환불신청서**가 실립니다 — 고객이 직접 낸 신청(`direct`), 운영자가 대리 접수한 건(`admin`), 반품·교환에서 파생된 건, 구형 취소 파이프라인이 만든 건(`cancel`)이 함께 보입니다. 취소신청서와 환불신청서는 같은 사건의 두 사본이 아니라 **다른 사실**을 기록하는 별개 문서이므로 한쪽이 다른 쪽을 대표하지 않습니다 — 그래서 반품 1건이 반품·환불·취소 세 탭에 모두 뜨는 것이 정상입니다. 예전에는 취소 발 환불을 이 목록에서 뺐고, 그래서 결제완료 상품을 환불한 고객에게 환불 이력이 아예 보이지 않았습니다. 목록 항목에는 결제 블록이 없으므로 미수납액·결제 진입은 상세(`GET user/claims/{claimNumber}`)에서 읽습니다.

---

### GET /api/modules/sirsoft-ecommerce/user/claims/{claimNumber}
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.claims.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.claims.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ClaimController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-claims.read`
- **권한 메모(1.3.0)**: `claim.is_withdrawable`·`claim.is_editable` 은 각 권한(`user-claims.withdraw`·`user-claims.request`)을 함께 반영하고, `claim.can_pay` 는 `user-claims.pay` 다.

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| claimNumber | path | string | 예 | — | 클레임 번호 (교환 `EX…` / 반품 `RT…` / 환불신청 `RF…` / 취소 `CN…`. 접두사는 유형을 내포하지만 서버는 접두사를 파싱하지 않고 교환→반품→환불→취소 순으로 조회한다) |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/claims/RT20260823-1402331875 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| claim | object | — | 신청서 상세 ([ClaimResource](#claim-resource)) — 교환·반품은 결제 블록(`payments`, `outstanding_amount`, `pending_payment`, `payment_entry`, `available_payment_methods`, `available_payment_method_labels`) 포함 |
| progress_steps | array | — | 진행단계 스텝퍼. 관리자 상세(`admin/claims/{claimNumber}`)와 **같은 배열**을 같은 규칙으로 만든다 — 항목 필드는 [admin-claims.md](admin-claims.md#get-apimodulessirsoft-ecommerceadminclaimsclaimnumber) "progress_steps 항목" 참조 (`value` / `label` / `state` / `icon` / `variant`). 취소는 신청 → 완료 두 칸 |
| linked_claims | object | — | 연결 신청서(보완 5 · Q8) — 관리자 상세와 같은 구조([admin-claims.md](admin-claims.md) 상세 봉투의 `linked_claims`). `detail_url` 은 회원이면 `/mypage/claims/…`, 비회원 조회면 `{shop}/guest/claims/…` 다. 비회원 조회(`guest/orders/{orderNumber}/claims/{claimNumber}`)도 같은 봉투다 |

**응답 예시**

```json
{
    "success": true,
    "message": "신청서를 조회했습니다.",
    "data": {
        "claim": {
            "id": 31,
            "claim_number": "RT20260823-1402331875",
            "claim_type": "return",
            "order_number": "20260823-1130558472",
            "status": "payment_pending",
            "status_label": "반품결제대기",
            "status_icon": "status-payment-pending",
            "is_editable": true,
            "charge_method": "pay_on_request",
            "total_charge_amount": 3000,
            "requires_additional_payment": true,
            "items": [ { "order_option_id": 310, "quantity": 1 } ],
            "images": [ { "id": 9, "hash": "a1B2c3D4e5F6", "download_url": "/api/modules/sirsoft-ecommerce/claim-image/a1B2c3D4e5F6?expires=1787000000&signature=0f3c" } ],
            "payments": [ { "payment_number": "CP20260823-1435226549", "payment_status": "pending", "total_amount": 3000 } ],
            "outstanding_amount": 3000,
            "outstanding_amount_formatted": "3,000원",
            "pending_payment": { "payment_number": "CP20260823-1435226549", "payment_status": "pending" },
            "payment_entry": {
                "requires_pg_payment": true,
                "pg_provider": "sirsoft-kginicis",
                "pg_payment_handler": "sirsoft-pay_kginicis.requestPayment",
                "pg_payment_data": { "order_number": "CP20260823-1435226549", "amount": 3000, "currency": "KRW" }
            }
        },
        "progress_steps": [
            { "value": "requested", "label": "반품신청", "state": "done", "icon": "status-request", "variant": "secondary" },
            { "value": "payment_pending", "label": "반품결제대기", "state": "current", "icon": "status-payment-pending", "variant": "warning" },
            { "value": "paid", "label": "반품결제완료", "state": "upcoming", "icon": "status-paid", "variant": "success" }
        ]
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

`progress_steps` 는 관리자 상세와 같은 지점(`ClaimProgressSteps`)에서 만들어집니다 — 화면이 순서를 다시 적으면 그 사본이 어휘의 두 번째 출처가 되어 회원 화면만 낡은 채 남습니다. 비회원 조회(`guest/orders/{orderNumber}/claims/{claimNumber}`)도 같은 봉투입니다.

상세 응답은 상품 행의 **원 주문옵션**(`product_name`·`product_option_name`·`thumbnail_url`)과 **행마다의 회수·재발송 배송사**(`items[].collection_carrier_name`·`items[].collection_tracking_url`·`items[].redelivery_carrier_name`·`items[].redelivery_tracking_url`)를 함께 싣고, 운송장 묶음(`claim.tracking_groups`)과 선교환의 회수 줄(`collection_lane`)이 봉투에 더해집니다. 목록과 달리 첨부(`images`)와 사유 상세·반려 사유·주소/배송비 스냅샷·환불 계좌도 모두 실립니다 — 신청서 상세 화면이 그리는 값들입니다.

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
| remove_image_ids | body | integer[] | 아니오 | max = 환경설정 `claim.max_images`(기본 5) | 떼어낼 첨부 ID 목록 (이 신청서 소속만). 이 신청서 소속이 아닌 ID 는 조용히 무시된다. **고객이 올린 첨부만** 뗄 수 있다 — 운영자·주체 미상 첨부가 섞이면 아무것도 바꾸지 않고 422(`claim_image_not_removable`) |
| redelivery_address | body | object | 아니오 | — | (교환) 재발송지 — `recipient_name`(max 50), `phone`(max 20), `zipcode`(max 20), `address1`/`address2`(max 255), `city`/`state`(max 100, 해외 주문의 도시·주), `country_code`(2자, 원 배송지와 같은 국가만), `changed_by_user`(boolean) |
| refund_bank | body | object | 아니오 | 세 값이 **함께** 와야 한다 | 환불 계좌 — `bank_code`(max 10), `account_number`(max 50), `holder`(max 50) — 접수(POST)와 같은 키·같은 상한이다. 셋 중 일부만 채워 보내면 `validation.claims.refund_bank.incomplete` 로 거절된다 (은행만 바뀌고 계좌번호가 옛 값으로 남으면 운영자가 없는 계좌로 이체한다). 셋이 다 빈 채로 보내면 **기록된 계좌를 지우지 않고 그대로 둔다** — 표시 응답은 마스킹된 값이라 화면이 원문을 되돌려 보낼 수 없기 때문이다. 실제 송금 대상(주문 결제행)은 여기서 바뀌지 않는다 |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/user/claims/RT20260823-1402331875 HTTP/1.1
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
            "claim_number": "RT20260823-1402331875",
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
| 422 | Unprocessable Entity | 검증 위반, 승인 뒤라 수정 불가(`claim_not_editable` — 취소 이력도 동일), 사유 사용 불가(`claim_reason_unavailable`), 청구 방식 불허(`claim_charge_method_not_allowed`), 재발송지 국가 변경(`claim_redelivery_country_change_not_allowed`), 첨부 부재·타 신청서 소속(`claim_image_not_found`), 고객이 올리지 않은 첨부 삭제 시도(`claim_image_not_removable`), 첨부 장수 초과(`claim_image_limit_exceeded`), 주문의 거래 시점 통화 스냅샷을 읽을 수 없어 차액 결제의 결제 통화 기준을 세울 수 없는 경우(`claim_payment_currency_unavailable`) |
| 500 | Server Error | 수정 처리 중 예기치 못한 오류 |

<!-- @generated:end -->

**설명** 승인 전(`is_editable=true`)에만 신청 내용을 고칩니다. 사유를 바꾸면 귀책 구분이 따라 바뀌고, 교환·반품은 배송비를 다시 계산해 총 청구액을 갱신합니다. 청구액이 달라지면 서버가 **차액을 정산**합니다 — 늘어난 만큼은 새 결제 건(`payment` + 결제 진입 4키)을 만들어 응답 최상위에 싣고, 이미 낸 금액이 새 청구액보다 크면 그 초과분을 결제 건에서 환급합니다(환급 결과는 `claim.payments` 의 `refunded_amount` 로 드러난다). 청구액이 0 이 되면 `charge_method` 는 `null` 로 비워지고 대기 중이던 결제 건은 취소됩니다. 재발송지는 원 배송지와 같은 국가 안에서만 바꿀 수 있습니다(국가가 바뀌면 배송비 산정 근거가 달라지므로 거절). `redelivery_address` 를 **`null` 로 보내면** 고객이 지정했던 재발송지를 지우고 주문 배송지로 되돌립니다(`changed_by_user=false`) — 수정 화면의 [원 배송지로 되돌리기] 가 이 값을 보냅니다. 키를 아예 보내지 않으면 저장된 재발송지를 그대로 둡니다. 화면은 재발송지를 손대지 않은 저장에서 이 키를 싣지 않습니다(저장값을 다시 보내면 「고객 지정」 표시가 붙기 때문).

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
POST /api/modules/sirsoft-ecommerce/user/claims/RT20260823-1402331875/withdraw HTTP/1.1
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
            "claim_number": "RT20260823-1402331875",
            "status": "withdrawn",
            "status_label": "반품철회",
            "is_final": true,
            "is_withdrawable": false,
            "is_editable": false,
            "withdrawn_at_formatted": "2026-08-23 14:10",
            "payments": [ { "payment_number": "CP20260823-1435226549", "payment_status": "cancelled" } ],
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
| 404 | Not Found | 첨부가 없거나, 본인이 올린 첨부가 아니거나, 이미 신청서에 붙은 첨부인 경우 (`claim_image_not_found`) |
| 500 | Server Error | 파일 삭제 중 예기치 못한 오류 |

<!-- @generated:end -->

**설명** 작성 화면에서 **아직 신청서에 붙지 않은**(임시 업로드) 본인 첨부만 지웁니다. 스토리지 파일과 행을 함께 지우며, 다른 회원의 첨부와 이미 신청서에 붙은 첨부는 존재 여부를 드러내지 않고 404 입니다. 신청서에 붙은 첨부는 신청서 수정(`remove_image_ids`)으로만 뗄 수 있고, 그 경로는 편집 가능 경계(승인 전)와 업로더(고객이 올린 것만)를 함께 봅니다 — 접수가 끝난 신청서의 증빙과 운영자가 올린 사진이 고객 손에 사라지지 않게 하기 위해서입니다.
