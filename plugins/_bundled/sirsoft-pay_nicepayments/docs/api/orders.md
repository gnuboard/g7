# Orders(주문 보조 조회) API 레퍼런스

> **소유**: plugin `sirsoft-pay_nicepayments` · 사람이 서술한 레퍼런스. 관리자 주문 화면과 사용자 영수증 화면이 쓰는 보조 조회 경로를 다룬다. 에스크로 결제 목록은 [escrow.md](escrow.md), 거래 조회는 [transaction.md](transaction.md) 참조.

---

## TL;DR (5초 요약)

```text
1. 관리자 주문 목록 테스트모드 배지용 맵과 입금통보 이력, 사용자 영수증 조회
2. 관리자 경로는 auth:sanctum + permission:sirsoft-ecommerce.orders.read
3. 테스트모드 맵은 최근 6개월의 나이스페이 결제 건만 집계한다 (주문 목록 배지 표시용). 간편결제 표시 라벨은 이커머스 주문 목록 응답이 싣는다
4. 영수증 조회는 회원이면 본인 주문만, 비회원이면 X-Guest-Order-Token 검증으로 접근 제어
5. 응답 data 는 화면 표시용 경량 구조 — 주문 상세 데이터는 이커머스 API 가 담당
```

---

### GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/{orderNumber}/vbank-notifications

- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.orders.vbank-notifications`
- **컨트롤러**: `AdminVbankNotificationController@show`
- **인증/권한**: `auth:sanctum` + `permission:admin,sirsoft-ecommerce.orders.read`

주문의 가상계좌 **입금통보 수신 이력**을 반환한다. 통보 수신 경로 자체는 [vbank.md](vbank.md) 참조. 주문이 없으면 실패 응답(`success: false`).

### GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/test-mode-map

- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.orders.test-mode-map`
- **컨트롤러**: `AdminOrderListController@testModeMap`
- **인증/권한**: `auth:sanctum` + `permission:admin,sirsoft-ecommerce.orders.read`

최근 6개월 나이스페이 결제 건 중 **테스트 모드 자격증명으로 결제된 주문**의 맵을 반환한다. 주문 목록의 "테스트" 배지 표시용.

```json
{ "success": true, "data": { "20260817-1043226571": true } }
```

### 간편결제 표시 라벨 (별도 경로 없음)

주문 목록의 **간편결제(내장 PG) 표시 라벨**은 이커머스 주문 목록 응답의 `payment.payment_method_display_label` 로 전달된다 — 이 플러그인은 필터 훅 `sirsoft-ecommerce.order.filter_payment_method_display_label` 을 구독해 라벨을 채운다(기간 제한 없음). 종전의 `GET .../admin/orders/easy-pay-display-map` 은 1.1.0 에서 제거됐다.

### GET /api/plugins/sirsoft-pay_nicepayments/user/orders/{orderNumber}/receipt

- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.user.orders.receipt`
- **컨트롤러**: `UserReceiptController@show`
- **인증/권한**: `optional.sanctum` — 회원이면 본인 주문만, 비회원이면 `X-Guest-Order-Token` 헤더 검증

주문의 나이스페이 **매출전표(영수증) URL** 을 반환한다. 소유권 검증에 실패하면 대상 없음으로 처리된다(다른 사용자의 주문번호로는 조회 불가).

응답은 공통 형식(`ResponseHelper`: `{ success, message, data }`)이고 아래 필드는 모두 `data` 안에 있다. 대상이 없으면 404 `{ "success": false, "message": "…" }`.

응답 필드: `receipt_url`(매출전표, `type=0`) · `payment_receipt_url`(마이페이지 「결제 영수증」 링크가 여는 주소, `type=2` — 거래번호가 없으면 `null`) · `cash_receipt_url`(현금영수증, `type=1`) · `is_test_mode` · 결제수단 표시 라벨(`payment_method_label` 등). 영수증 주소는 레이아웃에 적지 않고 이 응답에서 받아 쓴다.
