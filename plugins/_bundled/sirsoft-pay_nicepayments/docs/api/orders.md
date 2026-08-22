# Orders(주문 보조 조회) API 레퍼런스

> **소유**: plugin `sirsoft-pay_nicepayments` · 사람이 서술한 레퍼런스. 관리자 주문 화면과 사용자 영수증 화면이 쓰는 보조 조회 경로를 다룬다. 에스크로 결제 목록은 [escrow.md](escrow.md), 거래 조회는 [transaction.md](transaction.md) 참조.

---

## TL;DR (5초 요약)

```text
1. 관리자 주문 목록 배지용 맵 2종(테스트모드 / 간편결제 표시)과 입금통보 이력, 사용자 영수증 조회
2. 관리자 경로는 auth:sanctum + permission:sirsoft-ecommerce.orders.read
3. 맵 2종은 최근 6개월의 나이스페이 결제 건만 집계한다 (주문 목록 배지 표시용)
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
{ "success": true, "data": { "ORD-20260817-000001": true } }
```

### GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/easy-pay-display-map

- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.orders.easy-pay-display-map`
- **컨트롤러**: `AdminOrderListController@easyPayDisplayMap`
- **인증/권한**: `auth:sanctum` + `permission:admin,sirsoft-ecommerce.orders.read`

최근 6개월 나이스페이 결제 건 중 **간편결제(내장 PG) 표시 라벨**이 있는 주문의 맵을 반환한다. 주문 목록이 결제수단 열에 간편결제 명칭(네이버페이 등)을 표시하는 데 쓴다.

### GET /api/plugins/sirsoft-pay_nicepayments/user/orders/{orderNumber}/receipt

- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.user.orders.receipt`
- **컨트롤러**: `UserReceiptController@show`
- **인증/권한**: `optional.sanctum` — 회원이면 본인 주문만, 비회원이면 `X-Guest-Order-Token` 헤더 검증

주문의 나이스페이 **매출전표(영수증) URL** 을 반환한다. 소유권 검증에 실패하면 대상 없음으로 처리된다(다른 사용자의 주문번호로는 조회 불가).
