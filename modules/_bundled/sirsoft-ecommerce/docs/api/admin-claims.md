# Admin Claims API 레퍼런스

> **소유**: 모듈 `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반).
> 이 문서는 관리자 클레임(취소/반품/교환 관리) 엔드포인트를 다룹니다.
> 회원 경로는 [claims.md](claims.md), 비회원 경로는 [guest.md](guest.md), 추가결제는 [claim-payments.md](claim-payments.md), 사유 카탈로그는 [claim-reasons.md](claim-reasons.md) 를 보세요.
> 응답 리소스의 공통 필드 표(ClaimResource/ClaimItemResource/ClaimPaymentResource/ClaimImageResource)는 [claims.md](claims.md#공통-리소스) 가 정본입니다.

## TL;DR (5초 요약)

```text
1. 목록은 유형 서브탭 단위 — type 파라미터(기본 exchange)로 한 유형만 받고, counts 로 4유형 건수 뱃지를 함께 준다
2. 유형 스코프 경로는 /admin/{exchanges|returns|refunds}/{id} — 취소(cancel)는 전이 대상이 아니다. 관리자 상세 화면은 번호로 들어온다: /admin/claims/{claimNumber}(+/logs) 가 유형을 해석하고 화면이 쓰는 봉투(세그먼트·능력·완료 기본값·진행단계·택배사)를 함께 준다. 취소 번호(CN…)도 같은 경로로 열리며 조회 전용 봉투(세그먼트 null · 택배사/청구 방식/환불 수단 빈 배열 · 정산/취소 가능 금액 null)를 받는다
3. 상태 쓰기 어휘는 유형별 adminAssignableValues() — requested/paid/withdrawn 직접 지정 불가 (환불은 approved/on_hold/rejected 만). 미수납 승인은 pay_on_approval 결제 요청 우회 / pay_on_request 422
4. 완료 전이(status=completed)는 completion 객체 필수 — 모달이 실행 전에 보여줄 값은 claims/completion-preview 가 SSoT (D26)
5. 금액 수정({claimType}/{id}/amounts)·환불 실행(refunds/{id}/execute)의 상한은 취소 가능 금액(D19) — 초과는 저장·실행 모두 422, 재실행은 409, 근거는 orders/{order}/cancellable-amount 가 같은 식으로 답한다
6. 관리자 [주문취소](POST /admin/orders/{order}/cancel)는 **환불신청서를 만든다** — 즉시 취소하지 않는다. auto_advance_refund 를 켜야 승인·환불완료까지 한 번에 간다
7. 송장은 **상품 행**마다 있다 — 「송장만 저장」 은 PATCH {claimType}/{id}/options/tracking(상태 불변), 운송장 단위 도착은 POST {claimType}/{id}/tracking/deliver. 헤더 송장 API(collection-tracking·redelivery-tracking)는 없앴다
8. 관리자 직권 접수 — POST /admin/orders/{order}/{exchanges|returns|cancel-refund} (claims.create). 게이트는 물리 게이트 하나, 신청 기간·신청 가능 상태 설정은 따르지 않는다
```

## 관리자 [주문취소]의 결과 계약

`POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/cancel` 은 취소를 **즉시 확정하지 않는다**.
이 경로는 [관리자 직권 접수](#관리자-직권-접수-교환반품취소환불)의 취소·환불 분기로 위임된다 — 게이트도
물리 게이트(아직 보내지 않은 상품)이며 `order_settings.cancellable_statuses` 를 보지 않는다.
운영자 대리 접수(`refund_source_type=admin`)로 환불신청서를 만들고, 금액확인 → 승인 → 환불완료를
운영자가 하나씩 밟는 것이 기본 운영이다.

| 요청 필드 | 기본 | 동작 |
| --- | --- | --- |
| `auto_advance_refund` | `false` | 켜면 승인·환불완료까지 자동 전이한다. 끄면 환불신청서만 만들고 멈춘다 |

응답 `data.claim` 이 그 결과를 말한다.

| 키 | 뜻 |
| --- | --- |
| `branch` | `auto`(자동 전이) / `request`(접수에서 정지) |
| `claim_number` | 만들어진 신청서 번호 — 화면은 이 번호로 상세로 이동한다 |
| `cancelled` | 취소가 **확정**됐는지. 취소신청서는 환불이 집행된 순간에만 생기므로, 계좌 환불처럼 사람이 송금해야 하는 수단은 운영자가 [환불하기]를 누를 때까지 `false` 다 |

응답 `message` 도 같은 사실을 따른다 — `cancelled=true` 이면 `messages.orders.cancelled`(주문이
취소되었습니다), 신청서만 생겼으면 `messages.orders.cancel_refund_requested`(환불신청서가
접수되었습니다. 환불이 완료되면 주문이 취소됩니다)다. 예전에는 두 경우 모두 "취소되었습니다" 로 답해
운영자가 방금 만든 신청서를 보지 않았다.

신청 자격 위반(`OrderClaimException`)의 `422` 는 `message` 가 `messages.orders.cancel_failed`, 사유가
`errors.detail` 이다(사유 키+치환 파라미터로 다시 만든 문장).

> `cancel_pg` 파라미터는 없앴다. 끈 채로 카드 취소를 하면 PG 를 부르지 않고도 환불이 완료로
> 찍혀 **고객 카드는 그대로인데 장부만 환불완료**가 됐다. "PG 없이 장부만" 이 필요하면 환불수단을
> 계좌 환불로 바꿔 수동 처리한다.

## 관리자 직권 접수 (교환·반품·취소·환불)

운영자가 주문 상세에서 고객을 대신해 접수합니다(§3-11). 입구는 주문 상세 상품 표의 선택 바 하나이고,
종전 [주문취소](`POST /admin/orders/{order}/cancel`)는 [취소·환불 접수]로 흡수됐습니다 — 두 경로는 같은
서비스(`AdminClaimRequestService`)를 탑니다.

| 메서드 | URI | 라우트명 | 권한 |
| --- | --- | --- | --- |
| GET | /api/modules/sirsoft-ecommerce/admin/orders/{order}/claims/request-context | `api.modules.sirsoft-ecommerce.admin.orders.claims.request-context` | `claims.create` |
| POST | /api/modules/sirsoft-ecommerce/admin/orders/{order}/claims/preview | `api.modules.sirsoft-ecommerce.admin.orders.claims.preview` | `claims.create` |
| POST | /api/modules/sirsoft-ecommerce/admin/orders/{order}/exchanges | `api.modules.sirsoft-ecommerce.admin.orders.exchanges.store` | `claims.create` |
| POST | /api/modules/sirsoft-ecommerce/admin/orders/{order}/returns | `api.modules.sirsoft-ecommerce.admin.orders.returns.store` | `claims.create` |
| POST | /api/modules/sirsoft-ecommerce/admin/orders/{order}/cancel-refund | `api.modules.sirsoft-ecommerce.admin.orders.cancel-refund.store` | `claims.create` |

**게이트 — 물리 게이트 하나(D5).** 신청 기간(`claim.request_period_days`)·신청 가능 상태·취소 가능 상태
(`order_settings.cancellable_statuses`) 설정은 고객 축이라 따르지 않습니다. 주문 상품 상태로만 판정합니다.

| 유형 | 받는 주문 상품 상태 |
| --- | --- |
| 교환·반품 | `shipping` · `delivered` · `confirmed` |
| 취소·환불 | `pending_order` · `pending_payment` · `payment_complete` · `shipping_hold` · `preparing` · `shipping_ready` |

반대쪽 상태면 `422`(`claim_*_not_requestable`). 다른 신청서가 잡은 수량(수량 잠금)은 그대로 적용됩니다.

**교환·반품 본문**

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| items[].order_option_id / items[].quantity | integer | 예 | 대상 주문 상품과 수량 |
| items[].new_product_option_id | integer | 아니오 | 교환 옵션 (교환만) |
| reason_code | string | 예 | 사유 — 판매자 귀책(고객 화면 비노출) 사유도 받는다 |
| reason_detail | string | 아니오 | 상세 사유 (max 1000) |
| charge_method | string | 아니오 | `pay_on_request` / `pay_on_approval` / `deduct_from_refund` / `free`(배송비 0, 청구 방식 없음 — D7) |
| claim_shipping_fee | numeric | 아니오 | 사유로 자동 계산한 배송비를 덮어쓴다 (min 0) |
| refund_priority · refund_bank | — | 아니오 | 고객 신청과 같다 |
| redelivery_address | object | 아니오 | 교환 재발송지 — 고객 신청과 같은 칸(해외 주문의 도시·주 `city`·`state` 포함) |
| approve | boolean | 아니오 | 「접수와 함께 승인」(기본 `true`, D6). 추가결제가 필요한 건은 승인 대신 결제대기로 간다 |

**응답 `201`**: `data.claim`(ClaimResource) · `data.claim_number` · `data.payment_requested` ·
`data.approval_skipped`(승인이 막혔으면 `{reason}` — 접수는 남는다). 신청서에는 `requested_by_admin` 이
남고 `requested_by` 는 비어 있습니다. 고객은 판매자 접수 건을 철회·수정할 수 없고(`claim_admin_requested_not_editable`),
접수 알림에 「판매자가 고객님을 대신해 접수했습니다」(`request_origin_notice`)가 붙습니다(D8). 접수 훅은
기존 `claim.{type}.requested` 에 세 번째 인자 `['source' => 'admin']` 이 실립니다.

**취소·환불 본문**은 종전 주문취소와 같습니다(`type` full/partial · `reason` · `items[].cancel_quantity` ·
`auto_advance_refund` · `refund_priority` · `refund_bank`). 결과는 `refund_source_type = admin` 인
환불신청서이고, 응답은 `data.branch` · `data.claim_number` · `data.cancelled` 입니다.

**접수 문맥**(`claims/request-context`)은 주문 상세 선택 바와 접수 창이 읽는 선택지입니다(DB 변경 없음).
응답 `data`: `order_id` · `gates`(`exchange`·`return`·`refund` → 받는 주문 상품 상태 목록 — 위 게이트 표와 같은 값,
화면은 고른 상품의 상태를 이 목록과 대조해 버튼을 켜고 끈다) · `reasons`(`exchange`·`return` → `{value, label,
fault_type}` 목록, 판매자 귀책 사유 포함) · `charge_methods`(`{value, label}` 목록 — 설정의 청구 방식 + `free`).
`claims.create` 권한이 없으면 `403` 이고, 화면은 이때 버튼 셋을 꺼 둔 채 권한 안내를 세웁니다.

**미리보기**(`claims/preview`)는 `type`(`exchange`/`return`/`refund`)과 위 본문으로 청구·환불 예정액을
계산합니다(DB 변경 없음). 사유는 입력 중일 수 있어 선택입니다.

## 인증·권한

전 엔드포인트 `auth:sanctum` + 관리자(admin) 미들웨어. 세부 권한:

| 권한 | 대상 |
| --- | --- |
| `sirsoft-ecommerce.claims.read` | 목록·상세 조회·취소 가능 금액 |
| `sirsoft-ecommerce.claims.create` | 관리자 직권 접수 (교환·반품·취소·환불 — 주문 상세 선택 바) |
| `sirsoft-ecommerce.claims.update-status` | 전이(단건·일괄·상품 단위)·송장·도착 처리·진행 방식·첨부·완료 미리보기·환불신청서 수동 접수·재고 복구·대리 철회·신청 내용 수정 |
| `sirsoft-ecommerce.claims.update-amounts` | 금액 수정 (D18) |
| `sirsoft-ecommerce.claims.execute-refund` | 환불 실행 ('환불하기') |

### 권한 스코프 — 주문 스코프를 물려받는다

`claims` 권한은 `orders` 와 같은 스코프 축(`resource_route_key = order` · `owner_key = user_id`)을
선언한다. 역할의 `scope_type` 이 `self`·`role` 이면 **그 신청서가 속한 주문**이 운영자의 스코프 안에
있을 때만 다룰 수 있다 — 주문을 볼 수 없는 운영자가 신청서로 같은 주문의 내용·환불 계좌를 보거나
환불을 실행하지 못하게 한다.

판정은 **그 라우트의 클레임 권한 범위와 운영자의 주문 조회(`sirsoft-ecommerce.orders.read`) 범위의
교집합**이다. 주문 조회를 `self` 로 묶은 운영자는 클레임 권한을 전체(null)로 두어도 자기 주문 조회 범위
밖 신청서를 아래 어느 경로로도 다루지 못한다. 주문 조회 권한을 **보유하지 않은** 운영자(클레임 전담
역할)는 클레임 권한 범위만으로 판정한다 — 보유하지 않은 권한은 범위 진술이 아니다.

| 경로 | 판정 지점 | 스코프 밖일 때 |
| --- | --- | --- |
| `GET /admin/claims` (목록·`counts`) | 저장소가 쿼리에 조건을 건다 | 행·건수에서 빠진다 |
| `/admin/{claimType}/{id}/…`, `/admin/claims/{claimNumber}(/logs)`, `/admin/refunds/{id}/execute` 등 단건 | 신청서를 찾은 뒤 **그 라우트의 권한 식별자**로 판정 | `403 auth.scope_denied` |
| `POST /admin/claims/bulk-status`, `POST /admin/claims/completion-preview` | 목록과 같은 조건으로 대상 전부를 센다 | 하나라도 밖이면 전량 `403` |
| `/admin/orders/{order}/claims`, `/admin/orders/{order}/cancellable-amount` | 권한 미들웨어가 바인딩된 주문으로 클레임 권한을, 컨트롤러가 주문 조회 범위를 판정 | `403 auth.scope_denied` |
| `/admin/orders/{order}/claims/request-context`, `…/claims/preview`, `…/exchanges`, `…/returns`, `…/cancel-refund` (직권 접수) | 위와 같다(`claims.create` + 주문 조회 범위) | `403 auth.scope_denied` |

클레임 권한과 주문 조회 모두 범위가 전체(null)인 역할에는 아무 조건도 붙지 않는다.

### 500 응답

관리자 클레임 엔드포인트의 `500` 은 `message` 가 `messages.claims.operation_failed` 이고 원인 요약이
`errors.detail` 에 실린다 — 운영자가 서버 로그 없이도 조치 방향을 알 수 있게 한다. 회원·비회원 경로의
`500` 에는 이 값이 없다.

## 엔드포인트 목록

`{claimType}` 은 `exchanges` / `returns` / `refunds` 중 하나입니다 (표기된 예외 제외).

| 메서드 | URI | 라우트명 |
| --- | --- | --- |
| GET | /api/modules/sirsoft-ecommerce/admin/claims | `api.modules.sirsoft-ecommerce.admin.claims.index` |
| POST | /api/modules/sirsoft-ecommerce/admin/claims/bulk-status | `api.modules.sirsoft-ecommerce.admin.claims.bulk-status` |
| GET | /api/modules/sirsoft-ecommerce/admin/claims/{claimNumber} | `api.modules.sirsoft-ecommerce.admin.claims.show-by-number` |
| GET | /api/modules/sirsoft-ecommerce/admin/claims/{claimNumber}/logs | `api.modules.sirsoft-ecommerce.admin.claims.logs` |
| GET | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id} | `api.modules.sirsoft-ecommerce.admin.claims.show` |
| PATCH | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/status | `api.modules.sirsoft-ecommerce.admin.claims.status` |
| POST | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/images | `api.modules.sirsoft-ecommerce.admin.claims.images.store` |
| DELETE | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/images | `api.modules.sirsoft-ecommerce.admin.claims.images.clear` |
| DELETE | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/images/{image} | `api.modules.sirsoft-ecommerce.admin.claims.images.destroy` |
| PATCH | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/options/bulk-status | `api.modules.sirsoft-ecommerce.admin.claims.options.bulk-status` (교환·반품·환불) |
| PATCH | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/options/tracking | `api.modules.sirsoft-ecommerce.admin.claims.options.tracking` (교환·반품만) |
| POST | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/tracking/deliver | `api.modules.sirsoft-ecommerce.admin.claims.tracking.deliver` (교환·반품만) |
| PUT | /api/modules/sirsoft-ecommerce/admin/exchanges/{id}/exchange-mode | `api.modules.sirsoft-ecommerce.admin.claims.exchange-mode` (교환만) |
| POST | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/items/restore-stock | `api.modules.sirsoft-ecommerce.admin.claims.items.restore-stock` (교환·반품만) |
| POST | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/withdraw | `api.modules.sirsoft-ecommerce.admin.claims.withdraw` |
| PATCH | /api/modules/sirsoft-ecommerce/admin/{claimType}/{id} | `api.modules.sirsoft-ecommerce.admin.claims.update` |
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
| request_source | string | 아니오 | 접수 출처 (`admin` = 판매자 접수 / `customer`). 교환·반품은 `requested_by_admin`, 환불은 `refund_source_type = admin` 으로 가른다. `type=cancel` 에는 사용 불가(422) |
| collection_overdue | boolean | 아니오 | 선교환 반송 기한 경과 — 끝나지 않은 행 중 회수 송장·도착·미회수 표시가 모두 없는 행이 있는 선교환. `type=exchange` 전용(그 밖 422) |
| pending_refund | boolean | 아니오 | 환불 미접수(D9) — 완료됐는데 환불신청서가 연결되지 않은 상품이 있는 신청서(반품은 행 `refund_id` 없음·연결 신청서 반려/철회, 교환은 저가 차액 완료 행 + 살아 있는 차액 환불신청서 없음). 금액 0 판정은 상세 `pending_refund` 가 한다. `type=exchange`/`return` 전용(그 밖 422) |
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
| claim_number | string | `RT20260825-0931447182` | 클레임 번호 |
| claim_type / claim_type_label | string | `return` / `반품` | 유형과 라벨 |
| order_id / order_number | integer / string\|null | `120` / `20260825-1121449057` | 주문 |
| requester_name | string\|null | `홍길동` | 신청자 표기 (주문자명 — 비회원 신청도 값이 있다) |
| requested_by | integer\|null | `7` | 신청 회원 ID (비회원 `null`, 취소는 `cancelled_by`) |
| is_guest_request | boolean | `false` | 비회원 신청 여부 |
| status / status_label / status_variant / status_icon | string / string\|null / string\|null / string\|null | `requested` / `반품신청` / `secondary` / `status-request` | 유형별 상태 (아이콘은 상태 Enum `icon()` 의 의미 토큰 — 진행단계 칸과 같은 그림) |
| is_final | boolean | `false` | 종결 여부 |
| reason_code / reason_label | string\|null | `changed_mind` / `단순 변심` | 사유 |
| fault_type / fault_type_label | string\|null | `customer` / `고객 귀책` | 귀책 (취소는 `null`) |
| currency_code | string\|null | `KRW` | 금액 통화 (주문 통화 스냅샷 기준) |
| item_summary | object | `{"product_name":"티셔츠","item_count":2,"total_quantity":3}` | 상품 요약 (첫 상품명·건수·수량 합계) |
| requested_at / requested_at_formatted | string\|null | ISO 8601 / 사용자 타임존 표기 | 신청 일시 |
| completed_at_formatted | string\|null | `null` | 종결 일시 |
| charge_method / charge_method_label | string\|null | `pay_on_request` / `신청 시 결제` | 청구 방식 (교환·반품만) |
| total_charge_amount / _formatted | number / string | `3000` / `3,000원` | 청구액 (교환·반품만) |
| tracking_summary | object | `{"collection":{"first":"CJ-1","count":2},"redelivery":{"first":null,"count":0}}` | 방향별 서로 다른 운송장 수와 첫 번호 — 목록 「송장」 칸이 「CJ-1 외 1건」 으로 그린다 (교환·반품만) |
| tracking_prefill | object | `{"collection":{"carrier_id":3,"tracking_number":"CJ-1"},"redelivery":null}` | 회수중·발송중 일괄 모달의 미리 채움 — 살아 있는 행이 **모두 같은 송장**일 때만 값이 선다 (교환·반품만) |
| is_admin_requested | boolean | `false` | 판매자 접수(D8) 여부 — 목록 「판매자 접수」 뱃지 |
| refund_progress | object\|null | `{"claim_number": "RF…", "status": "requested", "status_label": "환불신청", "status_variant": "info", "status_icon": "status-requested", "count": 2}` | 교환·반품 행 전용(보완 5 · Q7) — 이 신청서에서 생긴 최신 환불신청서의 진행 상태와 전체 건수. 환불이 없으면 `null`. 페이지당 한 번에 싣는다(출처 축 eager load) |
| exchange_mode / exchange_mode_label / is_advance_exchange | string / string / boolean | `advance` / `선교환` / `true` | 교환 진행 방식 (교환만) |
| is_collection_overdue / collection_overdue_days | boolean / integer | `true` / `3` | 선교환 반송 기한 경과 여부·경과 일수 (교환만) |
| outstanding_amount / _formatted | number\|null / string\|null | `3000` / `3,000원` | 미수납 청구액 (교환·반품만) — **미수납 판정은 상태 라벨이 아니라 이 값으로 한다** (분할 수납 건은 결제대기 라벨 그대로다) |
| refund_source_type | string | `direct` | 환불 출처 (환불만) — `direct` 고객 신청 / `admin` 운영자 대리 접수 / `return`·`exchange` 반품·교환 발 / `cancel` 구형 취소 파이프라인 발. `direct` 만 구매자가 수정·철회할 수 있다 |
| refund_source_type_label | string | `고객 신청` | 목록의 출처 뱃지가 읽는 라벨 (환불만) |
| refund_total / _formatted | number\|null / string\|null | `29000` / `29,000원` | 환불 합계 (환불·취소 — 취소는 환불 행 로드 시) |
| cancel_type / cancel_type_label | string\|null | `full` / `전체취소` | 취소 구분 (취소만) |
| refund_status / refund_status_label | string\|null | `completed` / `환불완료` | 취소 발 환불 상태 (취소만) |
| reactivated_at_formatted | string\|null | `2026-10-05 14:00` | 이 취소로 빠진 상품을 나중에 다시 판매 상태로 되살린 가장 최근 시각 (취소만) — 목록 「재활성됨」 뱃지. 기록 이전의 재활성은 `null` |
| is_partially_reactivated | boolean | `false` | 취소 수량 중 일부만 되살렸는지 (취소만) — `true` 면 「일부 재활성됨」 뱃지 |

**응답 예시 (200)**

```json
{
  "success": true,
  "message": "신청 내역을 조회했습니다.",
  "data": {
    "data": [
      {
        "id": 31,
        "claim_number": "RT20260825-0931447182",
        "claim_type": "return",
        "claim_type_label": "반품",
        "order_id": 120,
        "order_number": "20260825-1121449057",
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
    "complete_defaults": {"create_refund_request": true, "restore_stock": true},
    "carriers": [{"value": 3, "label": "CJ대한통운"}],
    "abilities": {"can_update_status": true, "can_update_amounts": true, "can_execute_refund": true},
    "pagination": {"current_page": 1, "per_page": 20, "from": 1, "to": 5, "has_more_pages": false, "last_page": 1, "total": 5}
  }
}
```

`counts` 는 유형 공통 필터(기간·검색·회원구분·금액·통화)만 반영합니다 — 상태·귀책·청구 필터는 유형마다
어휘가 달라 다른 탭 건수에 적용할 수 없습니다. 관리자 목록 화면은 서브탭을 바꿀 때 이 공통 필터를 그대로
이어 보내므로, 다른 탭의 뱃지 숫자가 곧 그 탭을 눌렀을 때 보이는 건수입니다(현재 탭 뱃지는 유형 종속
필터까지 반영한 목록 건수).

`carriers` 는 회수중·발송중 일괄 전이 모달의 택배사 선택지입니다(교환·반품 탭만, 그 외 빈 배열).
배송사 엔드포인트는 `settings.read` 게이트라 클레임 권한만 가진 운영자에게는 이 응답이 유일한
통로입니다. 교환·반품 행은 저장된 송장(`collection_carrier_id`·`collection_tracking_number`, 교환은
`redelivery_*` 도)을 함께 실어 모달이 미리 채웁니다.

`complete_defaults` 는 완료 처리 모달의 **초기 선택값**입니다(설정 `claim.complete_defaults`).
설정 조회 엔드포인트는 `sirsoft-ecommerce.settings.read` 게이트라 클레임 권한만 가진 관리자에게는
열리지 않으므로, 이 목록 응답이 그 값의 전달 통로입니다. 설정이 비어 있어도 두 키는 항상 실립니다
(계약 기본값 = 둘 다 `true`). 초기 선택값일 뿐이며 완료 처리를 자동으로 수행하지 않습니다.

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
| requested_by_admin_name | string\|null | `김운영` | 판매자 접수(`requested_by_admin`)를 한 운영자 이름 — 교환·반품 전용, 고객이 낸 신청이면 `null`. 고객 응답에는 실리지 않는다 |
| withdrawn_by_admin_name | string\|null | `김운영` | 고객 요청으로 대신 철회(`withdrawn_by_admin`)한 운영자 이름 — 교환·반품 전용, 대리 철회가 아니면 `null`. 고객 응답에는 실리지 않는다 |

상세 표현에는 유저 상세와 같은 **주문금액 비교 블록** `calculation_comparison`(`original` / `recalculated` / `mc_original` /
`mc_recalculated` / `substituted_items`, 스냅샷이 없는 구형 신청서는 `null`)이 들어 있다 — '이 클레임의 정산' 카드와
취소 신청서 카드가 그 표를 그린다. 형태는 [claims.md 주문금액 비교 블록](claims.md#claim-resource) 이 정본이다.

**응답**: `200` `data.claim` — 위 구조 / `404` 부재.

---

### GET /api/modules/sirsoft-ecommerce/admin/claims/{claimNumber}

클레임 번호로 신청서 상세를 조회합니다. 관리자 상세 화면
(`/admin/ecommerce/claims/{claimNumber}`)의 진입 경로이며, 번호에서 유형을 읽어내는 해석은
서버가 합니다 — 채번 접두는 운영자가 바꿀 수 있어 화면이 추측하면 규칙의 두 번째 출처가
됩니다. 취소(`CN…`)도 같은 경로로 열립니다 — 주문 상세 클레임 내역과 클레임 목록 취소 탭의
신청번호가 이 화면으로 이어집니다. 취소에는 전이·송장·청구·환불 실행 축이 없어 봉투의 그 자리가
빕니다(`claim_path_segment: null` · `carriers`/`charge_methods`/`refund_methods` 빈 배열 ·
`settlement`/`cancellable`/`redelivery_address_differs` null). `data.claim` 은 취소 표현
(`cancel_type`·`refund_status`·`refund_amount`·`cancelled_at_formatted` — 환불 축은 취소 발 환불
행에서 읽는다)이고 `progress_steps` 는 취소 상태 Enum 의 두 칸(신청 → 완료)입니다. 활동 로그
(`/logs`)는 취소 행 자신의 이력에 취소 발 환불 행의 이력을 합쳐 돌려줍니다(추가결제·첨부 축은 없다).

취소 상품을 나중에 다시 판매 상태로 되살렸으면(주문·상품 재활성) `data.claim` 에 재활성 축이 더해집니다 —
`reactivated_at`(ISO)·`reactivated_at_formatted`·`reactivated_quantity`(되살린 수량)·`total_cancel_quantity`(이
취소로 빠진 수량)·`is_partially_reactivated`. 취소·환불 기록은 지우지 않습니다. 이 축은 관리자 응답에만 실리고
구매자 신청서 상세(`user/claims/{claimNumber}`)에는 없습니다.

`data.claim` 은 유형 스코프 상세(`GET {claimType}/{id}`)와 같은 표현이고, 그 위에 상세 화면이
쓰는 봉투 축이 더해집니다. 이 봉투가 그 값들의 전달 통로인 이유는 게이트 폭입니다 — 설정
조회와 배송사 목록은 `sirsoft-ecommerce.settings.read` 게이트라, 클레임 권한만 가진 관리자
(기본 역할 `manager`)가 화면에서 직접 부르면 403 이 되어 값이 조용히 빕니다.

**권한**: `sirsoft-ecommerce.claims.read`

**봉투 필드** (`data` 직속)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| claim | object | — | 신청서 상세 ([ClaimResource](claims.md#claim-resource) + 관리자 확장 필드 + 교환·반품 결제 블록) |
| claim_path_segment | string\|null | `exchanges` | 유형 스코프 API 의 경로 세그먼트. 화면이 상태 전이·송장·금액 요청 URL 을 만들 때 쓴다 |
| abilities | object | `{"can_update_status": true, …}` | 화면 컨트롤 게이팅. 목록 응답과 **같은 매핑**이다 (`can_update_status` / `can_update_amounts` / `can_execute_refund`) |
| complete_defaults | object | `{"create_refund_request": true, "restore_stock": true}` | 완료 처리 모달(D26)의 **초기 선택값**. 설정 `claim.complete_defaults` — 완료 처리를 자동으로 수행하지 않는다 |
| progress_steps | array | — | 진행단계 스텝퍼 (아래) |
| transition_options | array | `[{"value": "approved", "label": "반품승인"}]` | 상세 「신청서 상태 변경」 칸의 선택지. **심사 어휘**(결제 요청·승인·보류·반려) 중 지금 상태에서 갈 수 있는 것만이고, 그중 **눌러도 거절될 값은 싣지 않는다** — 고객이 낼 돈이 없는 신청서의 `payment_pending`(→ `claim_payment_not_required`), 신청 시점 결제 미수납 건의 `approved`(→ `claim_payment_outstanding`), 상품 일부가 신청서보다 앞선 건의 `rejected`(→ `claim_transition_not_allowed`). 회수·발송 단계는 상품 칸, 완료는 [완료 처리] 가 입구라 여기 서지 않는다. 취소신청서는 빈 배열 |
| completable_item_count | integer | `1` | 지금 완료할 수 있는 신청 상품 수 — 완료 절차가 대상으로 삼는 상품과 같은 판정(반품은 회수중·회수도착, 교환은 발송중·발송도착). 상세 [완료 처리] 버튼은 0 이면 꺼지고 안내 건수도 이 값이다. 환불·취소는 `0` |
| redelivery_address_differs | boolean\|null | `false` | 최종 재발송지가 주문 배송지와 다른가. 저장된 두 주소의 비교로 도출하며 별도 컬럼이 없다. 스냅샷에 없는 칸(도시·주 칸이 생기기 전에 저장된 신청서의 `city`·`state`)은 비교하지 않는다. 재발송 축이 없는 유형은 `null` — `false`(같다)와 구분된다 |
| carriers | array | `[{"value": 3, "label": "CJ대한통운", …}]` | 회수·재발송 송장의 택배사 선택지. 형태는 `admin/shipping-carriers/active` 와 같다. 환불은 회수·재발송 축이 없어 빈 배열 |
| order | object\|null | — | 결제 정보 섹션(D17-③)이 그리는 **원 주문의 결제 축**. 주문 상세 엔드포인트는 `orders.read` 게이트라 이 화면이 직접 부르면 클레임 권한만 가진 관리자에게만 403 이 되어 탭이 통째로 빈다. 이 화면이 실제로 그리는 축만 실린다 (아래) |
| settlement | object\|null | — | '이 클레임의 정산' 의 **완료 시 환불 예정**. 교환·반품에만 있고 환불신청서는 `null`(자기 금액이 헤더 컬럼이다). 값은 완료 처리 모달의 미리보기와 **같은 경로**에서 나온다 (아래) |
| charge_methods | array | `[{"value": "pay_on_request", "label": "신청 시 결제"}]` | 청구 방식 선택지 (D18). 허용 목록은 설정 `claim.allowed_charge_methods` 가 정하고 그 조회는 `settings.read` 게이트다. 환불신청서는 빈 배열 |
| refund_methods | array | `[{"value": "pg", "label": "PG사 환불"}]` | 환불 수단 선택지 (`RefundMethodEnum`). 환불신청서에만 있고 그 밖은 빈 배열. `points`(「마일리지로 환불」)는 비회원 주문과, 마일리지 기능이 꺼진 상점의 현금 환불(이 신청서의 `refund_amount` > 0)에서 빠진다 |
| cancellable | object\|null | — | 취소 가능 금액 산정 결과 (D19). 환불신청서에만 있으며 **그 신청서 문맥**(자기 자신을 선점 집계에서 제외)으로 계산한다. 형태는 [cancellable-amount](#get-apimodulessirsoft-ecommerceadminordersordercancellable-amount) 응답의 `cancellable` 과 동일하다 — 두 응답이 같은 지점에서 만들어진다 |
| tracking_groups | object\|null | `{"collection":[…],"redelivery":[…]}` | 배송 정보 탭의 운송장 묶음 — 방향마다 `[{key, direction, no_tracking, carrier_id, carrier_name, tracking_number, tracking_url, items[{claim_item_id, product_name, option_name, quantity, status_label, arrived}], all_arrived, can_deliver, blocked_reason}]`. 송장 없이 도착한 행은 `no_tracking: true` 묶음으로 선다. 환불·취소는 `null` (`data.claim.tracking_groups`) |
| collection_lane | array\|null | — | 선교환의 「회수」 줄 — 수량 기준 회수 진행(송장마다 행이 갈려도 개수로 센다)·반송 기한·지연. 일반 교환·반품은 `null` |
| exchange_mode_abilities | object\|null | `{"can_set_advance": true, "can_set_standard": false, "blocked_reason": null, "advance_enabled": true, "collection_due_days": 14}` | 「진행 방식」 줄의 버튼 게이트 — 서비스가 실제로 거절하는 조건과 같은 판정. 교환이 아니면 `null` |
| pending_refund | object\|null | `{"amount": 12000, "amount_formatted": "12,000원", "item_count": 1}` | 「환불 미접수」(D9) — 완료됐는데 환불신청서가 없고 환불액이 0 보다 클 때만. 상단 배너·완료 카드·[환불신청서 접수] 버튼이 같은 값을 읽는다 |
| linked_claims | object | `{"source": null, "refunds": [{"claim_number": "RF…", "status": "completed", "is_live": true, "detail_url": "…", "refund_method_label": "PG사 환불", "refund_total_formatted": "24,000원"}], "latest_refund": {…}, "result_cancel": null}` | 연결 신청서(보완 5). 교환·반품은 이 신청서에서 생긴 환불신청서 전부(출처 축 `refund_source_type`·`refund_source_id` — 반려·철회 후 재접수 이력 포함, id 오름차순), 환불은 원 신청서(`source` — 반품·교환·구형 취소 출처일 때)와 처리 결과 취소 내역(`result_cancel`), 취소는 원 신청서(= 환불신청서). 같은 주문의 신청서만 싣는다. 항목: `claim_type`·`claim_type_label`·`claim_number`·`status`·`status_label`·`status_variant`·`status_icon`·`is_live`(반려·철회 환불이면 false)·`requested_at_formatted`·`detail_url`, 환불이면 `refund_method`·`refund_method_label`·`refund_total`·`refund_total_formatted`·`mc_refund_total`·`refunded_at_formatted`. `detail_url` 은 보는 사람에 맞는 경로다(관리자 `/admin/ecommerce/claims/…`, 회원 `/mypage/claims/…`, 비회원 `{shop}/guest/claims/…`) |
| attachment_settings | object\|null | `{"max_images": 5, "image_max_size_kb": 2048, "allowed_extensions": ["jpeg","jpg","png","gif","webp"], "accept": ".jpeg,.jpg,.png,.gif,.webp"}` | 첨부 상한·허용 확장자. 화면이 리터럴로 적으면 운영자가 설정을 바꾼 순간 화면과 서버가 갈려 이유 없는 `422` 만 남는다. 취소신청서는 첨부 축이 없어 `null` |

**order 축** (`data.order`)

결제 정보 섹션이 그리는 축과 주문 식별·통화 축만 싣습니다 — 주문 옵션·배송·주소는 이 화면이
그리지 않으므로 실리지 않습니다.

| 필드 | 설명 |
| --- | --- |
| order_status · is_partially_cancelled | 적립예정 행의 노출 조건 |
| id · order_number | 주문 식별. 결제 정보 섹션은 읽지 않으며, 이 봉투를 소비하는 화면이 원 주문을 가리킬 때 쓴다 |
| base_currency · payment_currency · is_cross_currency | 주문 레벨 통화. 결제 통화 병기 여부는 결제행 단위(`payments[].is_cross_currency`)가 판정하므로 결제 카드는 이 세 값을 읽지 않는다 |
| payments | 결제수단 카드 ([OrderPaymentResource](orders.md)) |
| subtotal_amount_formatted · total_shipping_amount_formatted · total_discount_amount(_formatted) · total_product_coupon_discount_amount(_formatted) · total_code_discount_amount(_formatted) · total_points_used_amount(_formatted) · total_deposit_used_amount(_formatted) · total_paid_amount_formatted | 금액 행 세트 |
| total_cancelled_amount(_formatted) · total_refunded_amount(_formatted) · total_refunded_points_amount(_formatted) | 누적 취소·환불 블록. 환불 두 행은 **클레임 발 환불에서만** 0 을 넘는다 (취소 발은 취소금액 행이 담당) |
| total_tax_amount_formatted · total_vat_amount_formatted · total_earned_points_amount · total_earned_points_amount_formatted | 과세·부가세·적립예정. 적립 표기(`…_formatted`, 예 `1,234P`)는 주문 응답과 같은 공용 포맷터가 만들며 단위 글자는 다국어 키를 따른다 — 화면이 숫자에 'P' 를 붙이지 않는다 |

**settlement 축** (`data.settlement`)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| refund_amount / refund_amount_formatted | number / string | `55000` / `55,000원` | 지금 완료 처리하면 접수될 환불 예정액 |
| refund_points_amount / refund_points_amount_formatted | number / string | `0` / `0원` | 환불 예정 마일리지 (주문 때 쓴 마일리지 반환분 — 화면 문구 「사용 마일리지 반환」) |
| deducted_shipping_fee / deducted_shipping_fee_formatted | number / string | `3000` / `3,000원` | 환불금 차감 방식일 때 환불액에서 이미 빠진 회수배송비 |
| refund_product_amount / refund_product_amount_formatted | number\|null / string\|null | `41000` / `41,000원` | 환불 예정액의 상품 몫 (반품만 — 교환은 `null`). 합계만으로는 그 금액의 근거를 알 수 없어 가산 축을 함께 낸다 |
| refund_shipping_amount / refund_shipping_amount_formatted | number\|null / string\|null | `0` / `0원` | 환불 예정액의 배송비 몫 (반품만 — 교환은 `null`) |
| blocked_reason | string\|null | `claim_refund_request_exists` | 접수 불가 사유. `claim_refund_request_exists` 이미 접수됨 / `claim_refund_not_applicable` 환불 대상 없음 — 둘은 뜻이 달라 화면이 구분해 알린다 |
| already_filed | boolean | `false` | 이 신청서에 살아 있는 환불신청서가 있는지 |
| earn_recovery | boolean | `true` | 완료 시 적립 예정분이 사라지는지 — 회원 주문이고, 완료 대상 중 **아직 적립이 나가지 않은** 주문옵션이 있을 때만 `true`. 이미 적립된 상품은 완료해도 되돌리지 않으므로(#599 T2-D13) `false` |
| currency_code | string | `KRW` | 표기 통화 (거래 시점 동결) |

**progress_steps 항목**

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| value | string | `collecting` | 상태 값 (유형별 상태 Enum) |
| label | string | `교환회수중` | 상태 라벨 (표시 시점 로케일) |
| state | string | `current` | `done` 지나감 / `current` 현재 / `upcoming` 남음 / `off_path` 이탈(보류·반려·철회) |
| icon | string | `status-pickup` | 상태를 상징하는 **아이콘 의미 토큰** (아이콘 라이브러리 중립). 상태 Enum 의 `icon()` — 상태 뱃지(`status_icon`)와 같은 값. 어휘(21종 — 클레임 15 + 주문 전용 6)는 아래 표 |
| variant | string | `primary` | 상태 색 (상태 뱃지와 같은 `variant()` 팔레트 — `success`/`warning`/`info`/`primary`/`secondary`/`danger`/`dark`). 화면은 칸 색을 이 값으로만 고른다 |

순서는 유형별 상태 Enum 이 소유합니다(교환 11칸 · 반품 8칸 · 환불 4칸이 최대). 두 가지가
그 순서를 신청서에 맞게 추립니다:

- **결제 두 칸**(`payment_pending`/`paid`)은 **결제형 청구**(신청 시 결제·승인 후 결제)에서만
  실립니다. 청구액이 있어도 환불금 차감이면 결제 창이 열리지 않으므로 그 두 칸이 빠집니다 —
  금액만 보면 배송비 차감 건에 영영 지나가지 않는 칸이 남습니다.
- **보류·반려·철회**는 순서 안에 없습니다. 흐름을 따라가는 단계가 아니라 멈추는 사건이라
  마지막에 `state: "off_path"` 칸 하나로 붙고, 그때 순서 안에는 `current` 가 없습니다.

도달 지점은 현재 상태의 위치와 신청서가 박아 둔 시각(승인·회수도착·재발송도착·완료) 중 더
나아간 쪽입니다 — 이탈 상태에서는 현재 상태만으로 어디까지 갔는지 알 수 없기 때문입니다.

이 배열은 회원·비회원 상세(`user/claims/{claimNumber}` · `guest/orders/{orderNumber}/claims/{claimNumber}`)도
**같은 자리(`data.progress_steps`)에 같은 규칙으로** 받습니다 — 한 곳(`ClaimProgressSteps`)이 만듭니다.

**아이콘 의미 토큰 어휘** — 클레임 상태 Enum 4종과 주문 상태 Enum(`OrderStatusEnum::icon()`, [orders.md](orders.md) 의 `order_status_icon`·`progress_steps`·`option_status_icon`·`status_summary`)이 함께 쓰는 어휘입니다. 백엔드는 아이콘 라이브러리를 알지 않습니다. 토큰을 실제 글리프로 바꾸는 표는
아이콘 세트를 소유한 템플릿의 Icon 컴포넌트가 가지며(번들 템플릿은 Font Awesome 6 Free 로 해석), 어휘 밖
토큰이나 표가 없는 템플릿에서는 그 자리가 빈 네모로 그려집니다.

| 토큰 | 단계 | 번들 템플릿 글리프 |
| --- | --- | --- |
| `status-request` | 신청 접수 | `file-lines` |
| `status-payment-pending` | 결제 대기 | `credit-card` |
| `status-paid` | 결제 완료 | `money-bill` |
| `status-approved` | 승인 | `thumbs-up` |
| `status-on-hold` | 보류 | `pause` |
| `status-rejected` | 반려 | `ban` |
| `status-pickup-ready` | 회수 준비 | `box-open` |
| `status-pickup` | 회수 중 | `truck` |
| `status-received` | 회수 도착 | `warehouse` |
| `status-dispatch-ready` | 재발송 준비 | `box` |
| `status-dispatch` | 재발송 중 | `truck-fast` |
| `status-delivered` | 재발송 도착 | `house` |
| `status-completed` | 완료 | `circle-check` |
| `status-withdrawn` | 철회 | `rotate-left` |
| `status-processing` | 처리 중(환불 PG 요청) | `hourglass-half` |
| `status-preparing` | 상품준비중 (주문) | `boxes-packing` |
| `status-cancelled` | 주문취소 (주문) | `circle-xmark` |
| `status-exchange` | 교환진행중 (주문) | `right-left` |
| `status-return` | 반품진행중 (주문) | `reply` |
| `status-exchanged` | 교환완료 (주문) | `arrows-rotate` |
| `status-returned` | 반품완료 (주문) | `box-archive` |

**응답**: `200` `data` — 위 구조 / `404` 부재·취소 유형.

---

### GET /api/modules/sirsoft-ecommerce/admin/claims/{claimNumber}/logs

신청서 한 건의 활동 로그를 조회합니다. 신청서 자신뿐 아니라 그 신청서의 **추가결제·첨부**에
남은 로그까지 한 목록으로 합칩니다 — 운영자에게는 셋이 같은 신청서의 이력입니다.

상세와 같은 번호 축을 쓰므로, 상세 화면의 두 데이터소스가 같은 URL 파라미터만 읽습니다
(로그 조회가 상세 응답의 도착을 기다리지 않습니다).

**권한**: `sirsoft-ecommerce.claims.read`

**쿼리 파라미터**

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| per_page | integer | 아니오 | 페이지당 건수 (1~100, 기본 10) |
| sort_order | string | 아니오 | 정렬 (`asc` / `desc` — 기본 `desc`) |

**응답**: `200` — 코어 `ActivityLogResource` 컬렉션(페이지네이션 동반). 항목 구조는
[활동 로그 시스템](../../../../docs/backend/activity-log.md) 과 같습니다 / `404` 부재 /
`422` 어휘 밖 정렬.

첨부 삭제 로그는 그 첨부 행이 이미 지워졌으므로 이 목록에 실리지 않습니다 — 로그 자체는
`properties` 스냅샷과 함께 `activity_logs` 에 남습니다.

사람이 누르지 않은 전이 — 환불 자동 진행(승인·완료), 청구 0원 자동 승인, 결제 뒤 자동 승인 — 는
작업자(`user_id`)가 비어 있고 화면은 그것을 시스템으로 읽습니다. 작업자는 코어가 `Auth::id()` 로
채우므로 모듈은 이 전이들을 `Support\SystemActor::run()` 안에서 실행합니다. 접수 줄과 결제 확정은
고객이 한 일이라 고객이 작업자입니다. 운영자가 [주문취소] 로 시작한 자동 진행은 그 운영자가
작업자입니다. 이 범위에서 발화해 큐로 간 훅은 `SystemActorContextListener` 가 컨텍스트에 표지를
실어(`hook.context.capture`) 워커가 복원할 때 작업자를 비웁니다(`hook.context.restore`).

---

### PATCH /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/status

신청서 상태를 전이합니다.

**권한**: `sirsoft-ecommerce.claims.update-status`

**본문 파라미터**

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| status | string | 예 | 목표 상태 — 어휘는 유형별 `adminAssignableValues()`. 교환·반품은 `requested`/`paid`/`withdrawn` 제외 전체(11/8종), 환불은 `approved`/`on_hold`/`rejected` 3종 |
| reject_reason | string | `status=rejected` 일 때 필수 | 반려 사유 (max 2000). 비우면 `422` — 구매자 화면에 그대로 안내되는 문구다 |
| carrier_id | integer | 아니오 | 택배사 ID. 회수중·발송중·회수도착·발송도착 전이가 싣는 송장은 **옮겨지는 상품 행**에 쓰인다 |
| tracking_number | string | 아니오 | 운송장 번호 (max 50 — DB 칸 길이) |
| admin_memo | string | 아니오 | 관리자 메모 (max 2000 — 보낸 요청만 갱신, 미포함 시 기존값 유지). **교환·반품 전용** — 환불은 저장 컬럼이 없어 반영되지 않는다 |
| completion | object | `status=completed` 일 때 필수 | 완료 처리 옵션 (D26) |
| completion.restore_stock_by_item | object | 아니오 | 신청 상품 행 ID → 재고 복구 여부 맵 (`{"55": true}`) — 목록에 없는 행은 복구하지 않음 |
| completion.create_refund_request | boolean | completion 과 함께 필수 | 환불신청서를 지금 접수할지 — `false` 면 `order_refunds` 를 만들지 않는다. 반품은 **회차마다** 환불이 연결되지 않은 완료 상품으로 접수한다(S7) |
| completion.unreturned | object | 아니오 | 선교환 「미회수로 완료」 — 행 ID → `{reason}`(필수, max 2000). 그 행은 재고를 복구하지 않고 `unreturned_at`·`unreturned_reason` 이 남는다 |

**회수중·발송중은 송장이 있어야 들어갑니다**(#599 후속, R6). 택배가 물건을 싣고 가는 중이라는 사실이라,
송장 없이 들어가면 고객 알림의 운송장 칸이 빈 채로 나갑니다. 판정은 **옮겨지는 상품 행마다**입니다 — 요청이
송장을 싣지 않으면 각 행이 이미 가진 자기 송장이 있어야 하고, 다른 행의 송장을 빌려 쓰지 않습니다. 세 입구
(이 API·목록 일괄·상품 단위)가 같은 규칙이며 서비스(`RecordsClaimTracking`)도 한 번 더 막습니다(`claim_tracking_required`).

**회수·재발송 단계는 앞으로 건너뛸 수 있습니다** — 승인 → 회수준비중·회수중·회수도착, 회수준비중 →
회수중·회수도착, 교환 회수도착 → 발송준비중·발송중·발송도착, 발송준비중 → 발송중·발송도착. 승인은
건너뛰지 않습니다(추가결제 확인·교환품 재고 차감). 건너뛴 단계의 기록(회수도착·발송도착 시각)과
부수효과(교환품 새 주문상품 생성·배송완료)는 목표 단계가 수행하고, 고객 알림은 목표 상태 한 통입니다.

`reject_reason`/`admin_memo` 는 `status=completed` 전이에는 적용되지 않습니다 — 완료 분기는
`completion` 만 처리합니다 (완료 모달 D26 에도 해당 입력이 없다).

**승인과 추가결제의 상호작용**

| 신청서 상태 | 요청 | 결과 |
| --- | --- | --- |
| 미수납 청구 있음 + `pay_on_approval` | `status=approved` | 승인 대신 **결제 요청** — 결제 건(CP) 생성 + 결제대기 전이, 응답 `payment_requested=true`. 결제 확정 시 `paid` 를 거쳐 자동 승인 |
| 미수납 청구 있음 + `pay_on_request` | `status=approved` | `422` `claim_payment_outstanding` — 결제 완료 후 다시 승인 |
| 청구 없음(전액 수납·환불금 차감 포함) | `status=payment_pending` | `422` `claim_payment_not_required` |
| 청구 있음 | `status=payment_pending` | 결제 건 생성(없으면) + 결제대기 전이, `payment_requested=true` |
| 결제 건 있음(대기·결제완료) | `status=rejected` | 반려 전이 직후 **구매자 철회와 같은 정리** — 대기 건은 취소(`cancelled`), 결제된 건은 환급한다(`ClaimPaymentService::refundPaymentsForClaim()`). 반려된 신청서에 결제가 열린 채 남지 않게 하는 계약이다. 환급이 PG 를 거치면 응답이 그 왕복만큼 늦어지고, 환급이 실패하면 신청서는 반려된 채 오류가 돌아온다(철회와 같은 설계) |

**응답 예시 (200)**

```json
{
  "success": true,
  "message": "신청 상태가 변경되었습니다.",
  "data": {
    "claim": { "id": 31, "claim_number": "RT20260825-0931447182", "status": "approved", "...": "ClaimResource 전체" },
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
| reject_reason | string | `status=rejected` 일 때 필수 | 단건 전이와 동일 — 고른 신청서 전부에 같은 사유가 들어간다 |
| trackings[] | object[] | `status` 가 `collecting`/`redelivering` 일 때 필수 | 신청서별 송장 — `ids[]` 의 **모든** 신청서가 한 줄씩 있어야 한다(빠지거나 중복이면 `422` `trackings`). 송장은 신청서마다 달라 한 쌍을 여러 건에 나눠 쓰지 않는다 |
| trackings[].id | integer | 예 | 신청서 ID |
| trackings[].carrier_id | integer | 예 | 택배사 ID |
| trackings[].tracking_number | string | 예 | 운송장 번호 (max 50) |
| admin_memo | string | 아니오 | 단건 전이와 동일 |
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
      {"id": 35, "claim_number": "RT20260825-0947115036", "message": "반품반려 에서 반품승인 로는 상태를 변경할 수 없습니다."},
      {"id": 999999, "claim_number": null, "message": "해당 신청서를 찾을 수 없습니다."}
    ]
  }
}
```

`payment_requested` 는 승인 지시가 결제 요청으로 우회된 신청서 목록입니다 — 승인됨과 결제
요청됨은 운영자에게 다른 결과라 따로 보고합니다.

---

### PATCH /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/options/bulk-status

신청 상품 단위로 상태를 일괄 전이합니다 (D25 — 체크한 상품 배열). 교환·반품·환불.
취소신청서는 이 축이 없습니다(생성이 곧 확정이라 옮길 상태가 없습니다) — `404`.

**권한**: `sirsoft-ecommerce.claims.update-status`

**본문 파라미터**

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| items[] | object[] | 예 | 전이 지시 (1~200건). **`{id, quantity?}` 한 모양만 받습니다** — 스칼라 배열(`[55, 56]`)은 `422` 입니다 |
| items[].id | integer | 예 | 신청 상품 행 ID — **이 신청서에 속한 행만** 통과(다른 신청서의 행은 422 `errors`). 같은 id 를 두 줄로 보내도 `422` |
| items[].quantity | integer | 아니오 | 이번에 전이할 수량 (min 1, 생략하면 전량). 행 수량보다 적으면 **그 행이 갈라져** 전이분이 원래 행에 남고 잔여가 새 행으로 나갑니다 |
| status | string | 예 | 목표 상태 — 유형별 관리자 어휘에서 `payment_pending` 제외 (결제는 신청서 단위 축) |
| reject_reason | string | `status=rejected` 일 때 필수 | 반려 사유 (max 2000). 신청서의 `reject_reason` 한 칸에 남는다 — 상품 전부를 반려해 신청서가 따라 반려될 때도, 일부만 반려해 신청서가 그대로일 때도 |
| carrier_id | integer | `status` 가 `collecting`/`redelivering` 일 때 필수 | 택배사 ID — 송장은 신청서 한 칸에 남는다(회수중은 회수 송장, 발송중은 재발송 송장) |
| tracking_number | string | `status` 가 `collecting`/`redelivering` 일 때 필수 | 운송장 번호 (max 50) |
| completion | object | `status=completed` 일 때 필수 | 단건 전이와 동일 계약 (선택 상품만 이번 라운드에 완료 — 부분 완료). 환불신청서의 완료는 환불 실행의 소유라 `422` 입니다 |

**스칼라 배열을 하위호환으로 남기지 않은 이유**: 두 모양을 받으면 `items` 의 상위 스코프
검사가 둘로 갈라져 "다른 신청서의 행이 통과" 하는 결함이 되살아나고, 배포 중 두 형태가
섞여도 아무 데도 드러나지 않습니다. 스칼라가 오면 `422` 로 드러나야 합니다.

상품 단위 전이에는 메모 파라미터가 없습니다 — 상품 행에는 저장 지점이 없고, 신청서(헤더)
메모는 단건 전이(`{id}/status`)가 담당합니다. 반려 사유는 받습니다 — 상품 행에는 사유 칸이
없어 신청서의 `reject_reason` 에 남고, 구매자 화면은 그 칸이 차 있으면 신청서 상태와 무관하게
보여 줍니다(예전에는 받지 않아 상품을 모두 반려하면 신청서가 사유 없이 반려됐다).

**요청 예시**

```json
{
  "items": [{"id": 55, "quantity": 2}, {"id": 56}],
  "status": "rejected"
}
```

**응답 예시 (200)**

```json
{
  "success": true,
  "message": "신청 상품 상태 변경을 처리했습니다.",
  "data": {
    "succeeded": [55],
    "skipped": [{"id": 56, "message": "반품반려 에서 반품회수중 로는 상태를 변경할 수 없습니다."}],
    "split": [{"id": 55, "quantity": 2, "remainder_id": 91, "remainder_quantity": 1}],
    "claim": { "id": 31, "status": "collecting", "...": "ClaimResource 전체" }
  }
}
```

`split` 은 이번 지시로 **갈라진 행**입니다 — 없으면 운영자는 표가 한 줄 늘어난 것을 스스로
해석해야 합니다. `id` 는 전이된 쪽(운영자가 체크한 행 ID 그대로), `remainder_id` 는 신청
상태로 남은 새 행입니다.

헤더 상태는 살아 있는 상품이 모두 같은 상태에 도달했을 때 따라갑니다(`claim` 으로 확인).
환불신청서의 헤더는 `processing`·`completed` 로는 따라가지 않습니다 — 둘 다 환불 실행이
소유하는 상태라, 따라가면 돈이 움직이지 않았는데 신청서가 처리중으로 보입니다.

환불신청서에서 상품이 반려되면 신청서 환불액이 **반려분만큼** 다시 계산되고(D18 저장 경로를
그대로 탑니다 — 활동로그에 전/후가 남습니다), 반려 행의 `refund_amount` 는 0 이 됩니다.

---

### PATCH /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/options/tracking

「송장만 저장」 — 고른 **상품 행**에 한 방향 송장을 적습니다 (교환·반품). 상태는 옮기지 않습니다(R7).
송장은 상품 행마다 있습니다 — 한 신청서 안에서 상품마다 다른 택배로 오갈 수 있고(다중 송장), 한 행을
고쳐도 같은 운송장을 쓰는 다른 행은 바뀌지 않습니다(R3). 수량을 지정하면 그 행을 먼저 쪼개 지정 수량
행에만 송장을 적고, 잔여 행은 원래 값을 그대로 가집니다(R4). 둘 다 비우고 저장하면 그 행의 송장을
지웁니다 — 단, 회수중·발송중 행의 송장은 지울 수 없습니다(R6·R7).

**권한**: `sirsoft-ecommerce.claims.update-status`

**본문 파라미터**

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| direction | string | 예 | `collection`(회수) / `redelivery`(재발송 — 교환만, 반품이면 `422 claim_tracking_direction_not_allowed`) |
| carrier_id | integer | 아니오* | 택배사 ID (`ecommerce_shipping_carriers`) |
| tracking_number | string | 아니오* | 운송장 번호 (max 50 — DB 칸 길이) |
| items | array | 예 | 대상 상품 행 (1~200) |
| items[].id | integer | 예 | 신청 상품 행 ID. **이 신청서의 행이 아니면 `404`**(다른 신청서의 행이 있다는 사실을 422 로 드러내지 않는다) |
| items[].quantity | integer | 아니오 | 지정 수량 (행 수량보다 작으면 행을 쪼갠다) |

\* 둘 중 하나만 보내면 `422` `claim_tracking_incomplete`(R2).

쓸 수 있는 행 상태(R5): 반품 = 승인·회수준비중·회수중, 교환 회수 = 승인·회수준비중·회수중,
교환 재발송 = 회수도착·발송준비중·발송중(선교환은 승인부터). 밖이면 `422 claim_tracking_item_not_eligible`.
회수지가 다른 상품을 한 운송장으로 묶으면 `422 claim_tracking_mixed_return_address`(R8).
발송중 행의 재발송 송장을 고치면 그 행의 교환품 새 주문상품 배송 행 송장도 함께 바뀝니다(R11).
그 새 배송 행은 원 배송 행의 정책·송장만 승계하고 **배송비 칸은 0** 이다 — 배송비는 원 행에 남는다(재발송은 고객이 낸 배송이 아니다).

**응답**: `200` `data.claim`(상세 ClaimResource — 행마다 `collection_*`/`redelivery_*` 송장 필드 갱신) ·
`data.split`(쪼갠 행 `[{id, quantity, remainder_id, remainder_quantity}]`) / `404` / `422`.

훅: `sirsoft-ecommerce.claim.{exchange|return}.before_tracking_update` → `filter_tracking_update_data`
(`carrier_id`·`tracking_number` 만 조정) → 커밋 뒤 `after_tracking_update`(행별 전/후 변경 목록).

### POST /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/tracking/deliver

운송장 한 개에 묶인 상품 행을 한꺼번에 도착 처리합니다 (교환·반품 — 배송 정보 탭 [도착 처리], R9).
일반 흐름은 행을 회수도착(`collected`)·발송도착(`redelivered`)으로 옮기고, 선교환의 회수 방향은 상태를
옮기지 않고 행 `collected_at` 만 찍습니다. 옮길 수 없는 행은 건너뛰고 사유와 함께 돌려줍니다.

**권한**: `sirsoft-ecommerce.claims.update-status`

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| direction | string | 예 | `collection` / `redelivery` |
| carrier_id | integer | 예 | 택배사 ID |
| tracking_number | string | 예 | 운송장 번호 |

**응답**: `200` `data.claim` · `data.succeeded`(행 ID) · `data.skipped`(`[{id, message}]` — 이미 도착·전이 불가) /
`422 claim_tracking_not_found`(그 운송장을 가진 행이 없음).

### PUT /api/modules/sirsoft-ecommerce/admin/exchanges/{id}/exchange-mode

교환의 진행 방식을 바꿉니다 — 일반 교환(`standard`) ↔ 선교환(`advance`, 헌 물건 회수 전에 새 물건을 먼저
보냄). 설정 `claim.advance_exchange_enabled` 가 꺼져 있으면 `422 claim_advance_exchange_disabled`.
재발송 송장이 붙은 행이 있거나 승인 뒤 단계로 간 신청서는 `422 claim_exchange_mode_not_changeable`,
선교환에서 회수 송장이 붙은 행이 있으면 일반으로 되돌릴 수 없습니다(`claim_exchange_mode_locked_by_collection`).
바꿀 수 있는지는 상세 봉투의 `exchange_mode_abilities` 가 같은 판정으로 미리 알려 줍니다.

선교환은 전이표가 다릅니다 — 승인에서 곧바로 발송준비중·발송중·발송도착으로 가고, 회수는 상태가 아니라
행 송장·`collected_at` 으로 추적합니다. 발송이 시작되면 반송 기한(`collection_due_at` = 발송일 +
`claim.advance_exchange_collection_due_days`)이 잡히고, 기한이 지나면 일 배치
`sirsoft-ecommerce:claims:notify-overdue-collections` 가 관리자에게 한 번 알립니다
(`claim.exchange.collection_overdue`). 완료 조건은 「발송중·발송도착 **그리고** 회수 이동중·도착」 이며,
끝내 오지 않은 물건은 완료 본문 `completion.unreturned` 로 「미회수로 완료」 합니다(재고 불변).

**권한**: `sirsoft-ecommerce.claims.update-status`

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| mode | string | 예 | `standard` / `advance` |

**응답**: `200` `data.claim` / `404` / `422`. 훅: `claim.exchange.before_mode_change` · `after_mode_change`.

### POST /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/items/restore-stock

완료됐지만 재고를 복구하지 않은 상품 행의 재고를 뒤늦게 복구합니다 (교환·반품, S13). 완료 모달에서
복구를 끄거나 잊은 뒤의 입구입니다. 멱등합니다 — 행을 잠근 뒤 `is_stock_restored` 를 다시 읽어 이미
복구된 행은 건너뜁니다. 선교환에서 「미회수로 완료」 한 행은 대상이 아닙니다.

**권한**: `sirsoft-ecommerce.claims.update-status`

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| items | integer[] | 아니오 | 복구할 상품 행 ID (비우면 복구 가능한 행 전부) |

**응답**: `200` `data.claim` · `data.restored`(`[{id, quantity}]`) / `422 claim_stock_restore_not_eligible`(복구할 행 없음).
훅: `sirsoft-ecommerce.claim.{exchange|return}.after_stock_restore`(신청서, 복구 행, 처리자).

### POST /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/withdraw

고객 요청으로 신청을 대신 철회합니다 (교환·반품·환불, S14). 예전에는 운영자가 반려만 할 수 있어 고객에게
「반려되었습니다」 가 나갔습니다. 철회할 수 있는 단계는 고객 철회와 같고(`isWithdrawable()`), 판매자가
접수한 건도 거둘 수 있습니다. 결제 환급은 기존 철회 경로를 그대로 탑니다. 고객 알림에는
「고객님 요청으로 판매자가 철회했습니다」(`withdraw_origin_notice`)가 붙습니다.

**권한**: `sirsoft-ecommerce.claims.update-status`

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| reason | string | 예 | 철회 사유 (max 2000) — `withdraw_reason` 에 남고 철회자는 `withdrawn_by_admin` |

**응답**: `200` `data.claim` / `404` / `422 claim_not_withdrawable`.

### PATCH /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}

신청 내용(사유·상세 사유·교환 재발송지)을 운영자가 고칩니다 (교환·반품·환불, S15). 고객 수정과 같은
경로를 운영자 축으로 씁니다 — 판매자 귀책(고객 비노출) 사유도 고를 수 있고, 판매자가 접수한 건도
고칠 수 있습니다. 수정 가능 단계는 고객 수정과 같습니다. 사유가 바뀌어 배송비가 다시 계산되면 결제
대기 건·기수납액을 그 값에 맞춥니다.

**권한**: `sirsoft-ecommerce.claims.update-status`

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| reason_code | string | 아니오 | 사유 코드 (그 유형의 활성 사유) |
| reason_detail | string | 아니오 | 상세 사유 (max 1000) |
| redelivery_address | object | 아니오 | 교환 재발송지 (국가는 바꿀 수 없다, 해외 주문의 도시·주 `city`·`state` 포함) |

**응답**: `200` `data.claim` / `404` / `422 claim_not_editable`·사유 부재.

---

### POST /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/images

운영자 대리 첨부를 업로드합니다 (이미 귀속된 신청서에 추가 — 예: 회수 상품 검수 사진).

**권한**: `sirsoft-ecommerce.claims.update-status`

**본문 파라미터** (multipart/form-data)

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| file | file | 예 | 이미지 파일. 허용 확장자·최대 용량은 상세 응답의 `attachment_settings` 가 알려 줍니다 |
| image | file | — | `file` 의 별칭 (종전 계약). 둘 중 하나만 보냅니다 |

업로드된 첨부는 **운영자 업로드**(`uploader_type = "admin"`)로 기록됩니다 — 분쟁 시 어느 쪽이
낸 증거인지가 판정을 가르기 때문입니다.

**응답 예시 (201)**

첨부 하나를 `data` 에 그대로 싣습니다. `data.image` 는 종전 계약을 위한 별칭입니다.

```json
{
  "success": true,
  "message": "이미지를 첨부했습니다.",
  "data": {
    "id": 12,
    "hash": "a1b2c3d4e5f6",
    "claimable_type": "return",
    "claimable_id": 31,
    "original_name": "evidence.jpg",
    "original_filename": "evidence.jpg",
    "size": 184320,
    "size_formatted": "180 KB",
    "is_image": true,
    "order": 1,
    "uploader_type": "admin",
    "uploader_type_label": "운영자",
    "download_url": "/api/modules/sirsoft-ecommerce/claim-image/a1b2c3d4e5f6?...signature=...",
    "image": {"id": 12, "hash": "a1b2c3d4e5f6", "claimable_type": "return", "claimable_id": 31}
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

### DELETE /api/modules/sirsoft-ecommerce/admin/{claimType}/{id}/images/{image}

첨부 **한 장**을 파일까지 삭제합니다. 개인정보가 찍힌 사진 한 장을 지우려고 정상 증빙까지
함께 잃지 않게 합니다. 되돌릴 수 없습니다.

**권한**: `sirsoft-ecommerce.claims.update-status`

**경로 파라미터**

| 파라미터 | 타입 | 설명 |
| --- | --- | --- |
| image | int | 첨부 ID. **그 신청서에 귀속된 첨부**여야 합니다 |

**응답 예시 (200)**

```json
{"success": true, "message": "첨부 이미지를 삭제했습니다.", "data": {"deleted": 1}}
```

**오류**: `404` 신청서 부재 / 그 신청서의 첨부가 아님(`claim_image_not_found`).
권한 게이트는 "클레임을 다룰 수 있다" 만 말하므로 소유권을 따로 검사합니다 — 그러지 않으면
아무 신청서 상세에서나 남의 첨부 id 를 넣어 지울 수 있습니다.

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

**본문 파라미터 — 환불신청서** (`refunds` — 출처 제한 없음: 구형 취소 발 행도 수정·실행할 수 있다)

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
      "claim_number": "RF20260825-0918336241",
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
| 422 | `claim_amount_invalid` | 음수 금액 / 알 수 없는 환불 수단 |
| 422 | `claim_charge_method_required` | 청구 0원 신청서의 배송비를 올리면서 청구 방식을 함께 보내지 않음 |
| 422 | `claim_refund_exceeds_cancellable` | 환불액 합이 취소 가능 금액(D19) 초과 |
| 422 | `claim_refund_points_requires_member` | 비회원 주문에 마일리지 환불액 지정 |
| 422 | (검증) | 다른 유형의 축을 보냄 (`errors` 에 필드별 사유) |

수정 전후 금액과 작업자는 `sirsoft-ecommerce.claim.{type}.amounts_updated` 훅으로 발행되어
활동 로그의 기록 근거가 됩니다.

교환·반품의 청구액을 고치면 **아직 내지 않은 결제 대기 건**이 그 금액을 따라갑니다 — 올리면 차액 대기 건이 생기고,
내리면 대기 건 금액이 줄거나 닫힙니다. **이미 낸 결제 건은 자동 환급하지 않습니다**(초과 수납은 운영이 처리). 받은 뒤
이미 낸 금액 이하로 내리면 남아 있던 차액 대기 건만 닫힙니다(`charge_settled`).

---

### GET /api/modules/sirsoft-ecommerce/admin/orders/{order}/cancellable-amount

주문의 취소 가능 금액(D19)을 산정합니다 — 환불 상세 화면의 근거 표와 주문상세 환불 모달이
공용으로 씁니다. 서버는 환불 저장·실행 직전에 **같은 식**으로 재계산하므로, 이 응답이 보여준
한도와 실행 가드의 한도는 같은 값입니다.

```text
amount = max(0, min(domain_refundable, payment_capacity))
```

한도를 강제하는 것은 이 둘이고, 진행 중인 다른 신청서의 환불 예정액(`reserved`)은 **한도에서
빼지 않는 참고 값**입니다. 실지급 가능액은 `payment_capacity` 가 이미 실제 취소 누적을 뺀 값이고
같은 상품을 다시 신청하는 경로는 신청 단계에서 막히므로, 예정액까지 빼면 승인조차 되지 않은 다른
신청서가 이 건의 정당한 환불을 0 원으로 막습니다.

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
      "amount_formatted": "24,000원",
      "domain_refundable": 29000,
      "domain_refundable_formatted": "29,000원",
      "payment_capacity": 24000,
      "payment_capacity_formatted": "24,000원",
      "reserved": 5000,
      "reserved_formatted": "5,000원",
      "binding": "payment_capacity",
      "binding_label_key": "sirsoft-ecommerce::messages.claims.cancellable_binding.payment_capacity",
      "binding_label": "결제수단 잔여 한도가 한도입니다.",
      "methods": [
        {"type": "payment", "payment_id": 88, "method": "card", "method_label": "신용카드", "currency": "KRW", "remaining_local": 24000, "remaining_base": 24000, "remaining_local_formatted": "24,000원", "remaining_base_formatted": "24,000원"}
      ],
      "reserved_claims": [
        {"type": "refund", "type_label": "환불", "id": 12, "number": "RF20260825-1006538924", "amount": 5000, "amount_formatted": "5,000원"}
      ],
      "currency": "KRW",
      "mc_amount": {"KRW": 24000}
    }
  }
}
```

`binding` 은 한도를 정한 축입니다 — 값은 `domain_refundable` · `payment_capacity` 둘이며,
`reserved` 는 한도를 깎지 않으므로 `binding` 으로 나오지 않습니다.
`methods` 는 수단별 잔여 한도(결제 통화 원값 `remaining_local` 병기 — D18 화면의 수단별 한도),
`reserved` 와 `reserved_claims` 는 "진행 중인 다른 신청서가 앞으로 얼마를 환불할 예정인가" 를
운영자에게 알리는 참고 값입니다.

금액의 표기 문자열(`*_formatted`)과 라벨(`binding_label` · `method_label` · `type_label`)은
서버가 만듭니다 — 통화 기호와 소수 자릿수는 설정과 **거래 시점 스냅샷**이 정하므로 화면이
조립하면 기본 통화가 원이 아닌 상점에서 값은 맞고 단위만 틀린 금액이 나갑니다. `binding_label_key`
는 백엔드 네임스페이스 키라 화면이 해석할 수 없어 표시 문자열을 함께 싣습니다. 이 페이로드는
클레임 상세 응답의 `data.cancellable` 과 **같은 지점**에서 만들어져 두 화면이 같은 문자열을
봅니다.

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

- `counts` — `{exchange, return, refund, cancel}` 유형별 건수. 환불 축은 **출처로 거르지
  않습니다** — 취소신청서와 환불신청서는 같은 사건의 두 사본이 아니라 다른 사실을 기록하는
  별개 문서이므로 한쪽이 다른 쪽을 대표하지 않습니다. 그래서 반품 1건이 반품·환불·취소 세
  탭에 모두 뜨는 것이 정상입니다. **현재 탭의 건수만 목록과 같은 필터로 셉니다** — 다른 탭은
  "그 탭으로 가면 몇 건인가" 라는 원 의미를 지킵니다(상태·귀책·청구·사유는 유형 종속 어휘라
  다른 탭에 걸면 그 탭 건수가 언제나 0 이 됩니다).
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
  - `source_claim_number` — 환불 행이 반품·교환에서 파생됐으면 그 원 신청서 번호, 아니면 `null`.
    클레임 내역의 환불 행 옆 「원 신청서」 표시용이며, 목록 전체에 대해 한 번에 채워 추가 쿼리가 없습니다.
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
        "claim_number": "EX20260825-1012238374",
        "claim_type": "exchange",
        "status": "collecting",
        "status_label": "교환회수중",
        "requested_at_formatted": "2026-08-25 10:12",
        "requester_name": "김지은",
        "reason_label": "단순 변심",
        "fault_type": "customer",
        "reject_reason": null,
        "detail_url": "/admin/ecommerce/claims/EX20260825-1012238374",
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
        "claim_number": "CN20260825-0938517265",
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
같은 검증을 거쳐 **화면에 입력된 값으로 실행**합니다(저장 없이 실행 가능).

출처 제한은 없습니다. 구형 취소 파이프라인이 남긴 행(`refund_source_type=cancel`)도 운영자가
완료 처리할 수 있습니다 — 막아 두면 그 행들을 완료로 옮길 API 가 저장소 어디에도 없어 `approved`
에서 영구 정지합니다.

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
| refund_bank | object | 아니오 | 실행하며 적어 넣는 환불 계좌 — `bank_code`(max 10) · `account_number`(max 50) · `holder`(max 50). **세 값이 함께** 와야 하며 일부만 실으면 `validation.claims.refund_bank.incomplete` 로 거절됩니다. 실행과 같은 트랜잭션에서 신청서에 저장되므로 「계좌는 저장됐는데 실행은 실패한」 반쪽 상태가 생기지 않습니다 |
| force_without_refund_bank | boolean | 아니오 | 계좌 없이 완료로 기록하겠다는 **명시 동의**. 참이면 계좌 게이트를 통과합니다 — 이미 다른 경로로 송금했거나 지급하지 않기로 한 건을 위한 길이며, 송금은 일어나지 않고 신청서만 닫힙니다 |

#### 환불 수단 게이트 — [금액 수정]과 같은 강도

`refund_method` 는 [금액 수정](`PUT …/amounts`)과 **같은 선택지**로 받습니다(상세 봉투의
`refund_methods` 와 같은 판정). 「마일리지로 환불」(`points`)은 회원 주문에서만, 현금을 마일리지로 돌려줄 때는
마일리지 기능이 켜진 상점에서만 받습니다(본문 `refund_amount` 가 있으면 그 값으로 판정 · 비회원 `422`
`exceptions.claim_refund_points_requires_member` · 기능 꺼짐 `422` `exceptions.claim_refund_method_unavailable`).
저장된 수단이 `points` 인데 본문 없이 실행해도 같은 판정을 거칩니다. PG 결제창을 쓰지 않은 주문(무통장 등)의 `pg` 는 `422`
(`exceptions.claim_refund_method_unavailable`)이고, PG 플러그인이 빠져 계좌 환불로 떨어진 주문의
`pg` 도 막습니다(응답할 리스너가 없습니다). 이때는 카드처럼 PG 를 거친 결제이므로 거절 사유가 일반 문장이 아니라
`exceptions.pg_provider_inactive`(그 결제 플러그인이 꺼져 있으니 켜거나 환불 수단을 계좌 환불로 바꾸라는 안내)입니다 —
[금액 수정] 저장도 같은 문장입니다. 반대로 플러그인이 꺼진 동안 계좌로 폴백 접수된 카드 신청서는 플러그인이 돌아오면
상세의 `refund_method` 가 실행 때 쓰일 수단(`pg`)을 보이고, 운영자가 그와 다른 수단을 고른 때만 그 선택이 고정됩니다. **카드 주문의 계좌 수동 환불(`bank`)은 받습니다** —
PG 취소가 불가능한 상황에서 운영자가 계좌로 돌려주고 장부를 닫는 운영 경로이며, 그 대신 아래
계좌 게이트가 계좌를 요구합니다. 예전에는 실효 수단과 다른 값을 전부 `payment_mismatch` 로 막아,
[금액 수정]으로 저장한 계좌 수동 환불을 실행할 길이 없었습니다.

#### 실행 순서와 실패 처리 (D2 · P-D5)

실행은 한 트랜잭션에서 「되돌릴 수 있는 것은 모두 앞에, PG 는 맨 뒤에」 순서로 갑니다 — 쓴 마일리지
반환 → (`points` 면) 현금 환불액 마일리지 지급(유효기간 없음) → 재고·쿠폰 복원 → 장부·상품 행·취소신청서·
결제행 → PG. 마일리지·쿠폰·재고 처리가 실패하면 PG 를 부르지 않고 전체를 되돌린 뒤 `422`
(`messages.claims.refund_execute_failed`, `errors.detail` 에 원인 문장 — `exceptions.mileage_refund_failed` /
`coupon_restore_failed` / `stock_restore_failed`)로 답합니다.

결제수단과 다른 수단(마일리지·계좌 송금)으로 돌려준 환불은 결제행의 상태·취소 누적을 바꾸지 않고, 결제행
취소 이력에만 `refund_method`·`settled_on_payment=false` 와 실제 집행 금액을 남깁니다. 실제로 차감된 적 없는
사용 마일리지는 반환하지 않고 신청서의 `refund_points_amount` 도 0 으로 확정합니다.

PG 가 승인한 직후 장부 확정이 실패하면 신청서는 `approved` 로 되돌아가되 `pg_transaction_id` 와
`pg_error_code = LEDGER_COMMIT_FAILED` 표식이 남고(상세 `claim.pg_settled_pending_ledger = true`), 다음
[환불하기]는 PG 를 다시 부르지 않고 장부만 확정합니다.

#### 환불 계좌 게이트 (T2-D6)

**계좌로 송금해야 끝나는 환불은 보낼 계좌가 있어야 실행됩니다.** 없으면 `422` 이고 오류 키는
`refund_bank` 입니다. 세 조건을 모두 만족할 때만 요구합니다.

1. 신청서에 계좌가 아직 없다 (`refund_bank_code` 가 비었다)
2. 계좌로 나갈 **현금이 있다** (`refund_amount` > 0 — 마일리지로만 돌려주는 환불은 회원 원장으로
   들어가 보낼 계좌가 없습니다)
3. 그 현금이 계좌로 나간다 — 실행 수단(본문 `refund_method`, 없으면 저장값)이 `bank` 면 그렇고
   (카드 주문의 계좌 수동 환불 포함), `points` 면 아니며, 그 밖에는 이 주문이 계좌로 환불되는
   주문인지(구매자 접수·신청 화면과 같은 술어 `refundsToBankAccount()` — 무통장·입금된 가상계좌)로 정합니다

같은 판정을 상세 응답이 `claim.refund_account_required`(저장된 수단 기준)로 미리 답해 주므로, 화면은
결제수단 코드로 규칙을 재현하지 않습니다. 운영자는 상세에서 환불 수단을 바꾼 뒤 실행하므로, 상세 응답은
같은 술어를 **수단마다** 판정한 `claim.refund_account_by_method` 도 함께 냅니다(목록 행은 `null`).

```json
"refund_account_by_method": {
    "pg":     { "used": true,  "required": true,  "required_if_cash": true },
    "bank":   { "used": true,  "required": true,  "required_if_cash": true },
    "points": { "used": false, "required": false, "required_if_cash": false }
}
```

`used` 는 그 수단으로 실행하면 현금이 고객 계좌로 나가는가(위 3번 — 계좌 유무·금액과 무관), `required` 는
위 1~3을 모두 만족해 계좌를 받아야 실행되는가(저장된 현금액 기준), `required_if_cash` 는 2번을 빼고 1·3만 본 값(현금이 있다면
계좌가 필요한가)입니다. 실행 게이트는 본문 `refund_amount` 로 2번을 판정하므로, 환불 실행 확인 창은 지금 고른 수단의 `used` 로
저장 계좌 줄을, `required_if_cash` 와 **지금 입력한 현금액**(> 0)으로 계좌 입력 칸과 실행 버튼 잠금을 정합니다(저장하지 않은
금액으로 실행해도 창과 서버가 같은 판정을 냅니다) — 「마일리지로 환불」·카드 취소에서는 저장 계좌가
있어도 줄이 서지 않고, 입금된 가상계좌의 PG 환불은 PG 가 고객 계좌로 송금하므로 줄과 칸이 섭니다.

막지 않던 동안에는 계좌가 빈 채로 실행돼 **돈은 나가지 않았는데 신청서만 `completed` 로
닫혔고**, 그 뒤에는 되돌릴 경로도 계좌를 적을 자리도 없었습니다(2026-09-22 #599 3차 실측 L-8).

**응답 예시 (200)**

```json
{
  "success": true,
  "message": "환불을 실행했습니다.",
  "data": {
    "claim": { "id": 12, "claim_number": "RF20260825-1006538924", "status": "completed", "refunded_at": "2026-08-25T14:02:11+09:00", "...": "ClaimResource 전체" }
  }
}
```

**오류**

| 코드 | 사유 |
| --- | --- |
| `404` | 환불신청서 부재 |
| `409` | 이미 실행됐거나 실행 중 (`claim_refund_already_executed` — 더블클릭). 화면은 새로고침을 안내한다 |
| `422` | 환불 계좌 부재(`refund_bank` — 계좌도 동의도 없이 계좌 환불을 실행) / 일부만 실린 계좌(`validation.claims.refund_bank.incomplete`) / 승인 전 상태(`claim_transition_not_allowed`) / D19 상한 초과(`claim_refund_exceeds_cancellable` — 실행 직전 재계산 가드) / 그 주문에서 쓸 수 없는 환불수단(`exceptions.claim_refund_method_unavailable`) / PG 환불 거절(`errors.detail` 에 PG 사유 — 관리자 전용 면의 조치 근거) |

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
| claims[].unreturned_item_ids | integer[] | 아니오 | 선교환 「미회수로 완료」 로 고른 상품 행 ID — 실행과 같게 완료 대상(`completable_item_ids`)에 넣고 재고 복구 수에서는 뺀다. 그 신청서의 후보(`unreturned_eligible_items`)가 아닌 ID 는 버린다 |

**응답 예시 (200)**

```json
{
  "success": true,
  "message": "완료 처리 요약을 계산했습니다.",
  "data": {
    "claims": [
      {
        "id": 31,
        "claim_number": "RT20260825-0931447182",
        "currency_code": "KRW",
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
          "refund_amount_formatted": "26,000원",
          "refund_points_amount": 0,
          "refund_points_amount_formatted": "0원",
          "deducted_shipping_fee": 3000,
          "deducted_shipping_fee_formatted": "3,000원"
        }
      }
    ],
    "skipped": [{"id": 999999, "message": "해당 신청서를 찾을 수 없습니다."}],
    "totals": {
      "claim_count": 1,
      "completable_item_count": 1,
      "refund_amount_total": 26000,
      "refund_points_amount_total": 0,
      "currency_code": "KRW",
      "refund_amount_total_formatted": "26,000원",
      "refund_points_amount_total_formatted": "0원",
      "deducted_shipping_fee_total": 3000,
      "deducted_shipping_fee_total_formatted": "3,000원"
    }
  }
}
```

- 금액은 원시값과 표기 문자열(`*_formatted`)을 함께 싣습니다. 표기 통화는 신청서의 통화
  (교환·반품 `currency_code`)이고, 없으면 주문 스냅샷의 기준 통화입니다 — 소수 자릿수·절사도
  스냅샷이 정합니다(행마다 `currency_code` 로 확인할 수 있습니다). `totals` 의 표기 합계는
  대상이 **한 통화로 모일 때만** 나오며, 여러 통화가 섞이면 `totals.currency_code` 와 두
  `*_formatted` 가 `null` 입니다 — 화면은 그때 합계 표기를 접고 건별 금액만 보여줍니다.
- `totals.deducted_shipping_fee_total` 은 이번 라운드에 **실제로 접수되는** 신청서
  (`refund.applicable`)의 회수배송비 차감 합계입니다 — 완료 처리 모달의 "함께 실행되는 처리"
  요약이 이 값을 그립니다. `create_refund_request=false` 면 접수가 없으므로 0 이고 표기는
  `null` 입니다.
- `has_stock_restore_target` 은 요청의 체크 상태 기준으로 "이 신청서에 재고를 복구할 상품이
  하나라도 있는가" 를 답합니다. 실제 복구는 완료 실행이 이번 라운드 대상만 수행합니다.
  완료 처리 모달의 "함께 실행되는 처리" 요약은 이 값이 아니라 건수
  (`stock_restore_count` / `stock_discard_count`)를 그리고, 그 줄의 노출은
  `completable_item_count` 합이 0 보다 큰지로 판정합니다 — 전건을 해제해도 "0복구 N폐기" 가
  남아야 폐기 예고가 화면에서 사라지지 않기 때문입니다. `has_stock_restore_target` 은 목록
  모달이 소비하지 않으며, 신청서 단위로 재고 축을 접을지 판단해야 하는 화면(상세)이 쓰는 값입니다.
- 재고 맵(`claims[].restore_stock_by_item`)의 키는 **상품 행 ID** 이고, 그 목록은 이 응답의
  `completable_item_ids` 에만 있습니다. 없는 키는 '복구 안 함' 으로 읽으므로, 상품 행을 아직
  모르는 첫 호출은 빈 맵으로 보내고 응답을 받은 뒤 실제 체크 상태로 한 번 더 호출해야
  요약이 화면의 체크와 일치합니다.
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
    "refund": { "id": 12, "claim_number": "RF20260825-1006538924", "claim_type": "refund", "status": "requested", "...": "ClaimResource 전체" }
  }
}
```

**오류**: `404` 부재 / `422` 완료된 상품 없음(`claim_completion_items_invalid`) · 전부
접수됨(`claim_refund_request_exists`) · 환불 대상 없음(`claim_refund_not_applicable`) —
화면은 두 사유를 구분해 안내합니다.
