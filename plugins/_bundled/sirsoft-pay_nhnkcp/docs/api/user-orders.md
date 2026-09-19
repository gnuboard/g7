# 사용자 주문 보조 조회 API 레퍼런스

> **소유**: 플러그인 `sirsoft-pay_nhnkcp`. 마이페이지·주문 완료 화면이 호출하는 사용자 측 NHN KCP 연동 경로 — 영수증 URL 조회와 테스트 모드 가상계좌 모의입금 정보 — 를 서술한다. 관리자 경로는 [admin-orders.md](admin-orders.md), 결제창 경로는 [payment.md](payment.md) 가 소유한다.

---

## TL;DR (5초 요약)

```text
1. 영수증 조회는 회원이면 본인 주문만, 비회원이면 X-Guest-Order-Token 또는 결제 직후 발급된 영수증 쿠키로 접근 제어
2. 영수증 URL 은 KCP 매출전표(카드·계좌이체·가상계좌) + 현금영수증(계좌이체·가상계좌만) 두 종류
3. 모의입금 정보는 테스트 모드 + 가상계좌 발급 + 입금 전 + 본인(또는 관리자) 일 때만 available: true
4. 모의입금 조회는 조건 불충족을 404 가 아니라 200 { available: false } 로 답한다 — 화면이 버튼을 감추기만 하면 된다
5. 두 경로 모두 주문 상태를 바꾸지 않는다 (모의입금 실행은 KCP 테스트 페이지 → 입금통보 경로가 담당)
```

---

## GET /api/plugins/sirsoft-pay_nhnkcp/user/orders/{orderNumber}/receipt

- **라우트명**: `api.plugins.sirsoft-pay_nhnkcp.user.orders.receipt`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\UserReceiptController@show`
- **인증/권한**: `optional.sanctum` — 회원이면 본인 주문만. 비회원이면 `X-Guest-Order-Token` 헤더(코어 `GuestOrderAuthService`) 또는 결제 콜백 성공 직후 발급된 서명 쿠키 `nhnkcp_receipt_token`(주문번호·만료·서명 3분절) 중 하나로 소유를 증명한다

**경로 파라미터**

| 이름 | 타입 | 용도 |
| --- | --- | --- |
| orderNumber | string | 주문번호. `pg_provider = nhnkcp` 이고 거래번호(`transaction_id`)가 있는 결제 레코드만 대상 |

**응답 (200)** — 코어 envelope 없이 원시 JSON

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| receipt_url | string | KCP 매출전표 URL. 휴대폰결제는 `mcash_bill`, 그 밖(카드·계좌이체·가상계좌)은 `card_bill` 명령으로 거래번호·주문번호·금액을 실어 만든다 |
| cash_receipt_url | string \| null | 현금영수증 URL. 계좌이체·가상계좌만 채워지고 그 밖은 `null` |
| is_test_mode | boolean | 테스트 모드 여부. URL 의 호스트(테스트/운영 관리자)가 이 값으로 갈린다 |
| payment_method_label · payment_method_display_label · selected_payment_method · embedded_pg_provider · embedded_pg_provider_label | string \| null | 화면 표기용 결제수단·간편결제 라벨 |

```json
{
    "receipt_url": "https://…/assist/bill.BillActionNew.do?cmd=card_bill&tno=…&order_no=ORD20260917001&trade_mony=10000",
    "cash_receipt_url": null,
    "is_test_mode": true,
    "payment_method_label": "신용카드",
    "payment_method_display_label": "신용카드",
    "selected_payment_method": "card",
    "embedded_pg_provider": null,
    "embedded_pg_provider_label": null
}
```

**에러 응답**

| 상태코드 | 발생 조건 |
| --- | --- |
| 404 | 주문이 없거나, 소유 증명(회원 본인·게스트 토큰·영수증 쿠키)에 실패했거나, NHN KCP 결제 레코드에 거래번호가 없는 경우. 소유권 실패도 존재 여부를 드러내지 않도록 같은 404 다 |

**설명**

주문 완료·마이페이지 화면이 「영수증 보기」 버튼에 넣을 KCP 영수증 주소를 받는 경로다. 영수증 URL 이 결제 레코드에 저장돼 있지 않아도 거래번호(`tno`)로 동적으로 만든다. 현금영수증은 승인번호(`app_no` → `receipt_no` → `tno` 순 폴백)와 상점 코드(`site_cd`)로 만든다.

---

## GET /api/plugins/sirsoft-pay_nhnkcp/user/orders/{orderNumber}/vbank-mock-deposit-info

- **라우트명**: `api.plugins.sirsoft-pay_nhnkcp.user.orders.vbank-mock-deposit-info`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\UserVbankMockDepositController@show`
- **인증/권한**: `auth:sanctum` — 주문 소유자 본인 또는 관리자(`isAdmin()`)만 데이터를 받는다

**경로 파라미터**

| 이름 | 타입 | 용도 |
| --- | --- | --- |
| orderNumber | string | 가상계좌가 발급된 주문의 주문번호 |

**응답 (200)** — 코어 envelope 없이 원시 JSON. 아래 조건 중 하나라도 어긋나면 `{ "available": false }` 만 돌려준다 (404 아님)

- 플러그인 테스트 모드가 켜져 있다 (미설정 기본값 `true`)
- 주문이 존재하고 요청자가 소유자이거나 관리자다
- 결제가 가상계좌이고 계좌번호가 발급됐으며 아직 입금 완료가 아니다

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| available | boolean | 모의입금을 안내할 수 있는 상태인지 |
| trade_no | string | 거래번호. `transaction_id` 가 비어 있으면 결제 메타의 `tno` 로 폴백 |
| account_no | string | 발급된 가상계좌 번호 |
| notify_url | string | 이 사이트의 입금통보 수신 주소 — KCP 테스트 페이지에 넣을 값 ([vbank.md](vbank.md)) |
| mock_url | string | KCP 테스트 관리자의 가상계좌 모의입금 페이지 주소 |
| is_admin_view | boolean | 관리자 자격으로 조회했는지 (화면 문구 분기용) |

```json
{
    "available": true,
    "trade_no": "…",
    "account_no": "…",
    "notify_url": "https://example.com/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify",
    "mock_url": "https://testadmin.kcp.co.kr/Modules/Noti/TEST_Vcnt_Noti.jsp",
    "is_admin_view": false
}
```

**설명**

테스트 환경에서 가상계좌 입금을 시뮬레이션하려면 KCP 테스트 관리자 페이지에 거래번호·계좌번호·입금통보 주소를 넣어야 한다. 이 경로는 그 폼에 채울 값을 모아 주는 조회일 뿐이며, 실제 입금 처리는 KCP 가 `notify_url` 로 보내는 입금통보를 [vbank.md](vbank.md) 경로가 받아 수행한다. 운영 모드에서는 항상 `available: false` 다.
