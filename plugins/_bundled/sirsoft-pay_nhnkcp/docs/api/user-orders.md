# 사용자 주문 보조 조회 API 레퍼런스

> **소유**: 플러그인 `sirsoft-pay_nhnkcp`. 마이페이지·주문 완료 화면이 호출하는 사용자 측 NHN KCP 연동 경로 — 영수증 URL 조회와 테스트 모드 가상계좌 모의입금 정보 — 를 서술한다. 관리자 경로는 [admin-orders.md](admin-orders.md), 결제창 경로는 [payment.md](payment.md) 가 소유한다.

---

## TL;DR (5초 요약)

```text
1. 영수증 조회는 회원이면 본인 주문만, 비회원이면 X-Guest-Order-Token 또는 결제 직후 발급된 영수증 쿠키로 접근 제어
2. 영수증 URL 은 KCP 매출전표(카드·계좌이체·가상계좌) + 현금영수증(계좌이체·가상계좌만) 두 종류
3. 모의입금 정보는 테스트 모드 + 가상계좌 발급 + 입금 전 + 본인(또는 관리자) 일 때만 available: true
4. 모의입금 조회는 조건 불충족을 404 가 아니라 200 공통 형식의 data.available=false 로 답한다 — 화면이 버튼을 감추기만 하면 된다
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

**응답 (200)** — 공통 형식(`ResponseHelper`: `{ success, message, data }`). 아래 필드는 모두 `data` 안에 있다

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| receipt_url | string | KCP 매출전표 URL. 휴대폰결제는 `mcash_bill`, 그 밖(카드·계좌이체·가상계좌)은 `card_bill` 명령으로 거래번호·주문번호·금액을 실어 만든다 |
| cash_receipt_url | string \| null | 현금영수증 URL. 계좌이체·가상계좌만 채워지고 그 밖은 `null` |
| is_test_mode | boolean | 테스트 모드 여부. URL 의 호스트(테스트/운영 관리자)가 이 값으로 갈린다 |
| payment_method_label · payment_method_display_label · selected_payment_method · embedded_pg_provider · embedded_pg_provider_label | string \| null | 화면 표기용 결제수단·간편결제 라벨 |

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "receipt_url": "https://…/assist/bill.BillActionNew.do?cmd=card_bill&tno=…&order_no=ORD20260917001&trade_mony=10000",
        "cash_receipt_url": null,
        "is_test_mode": true,
        "payment_method_label": "신용카드",
        "payment_method_display_label": "신용카드",
        "selected_payment_method": "card",
        "embedded_pg_provider": null,
        "embedded_pg_provider_label": null
    }
}
```

**에러 응답**

| 상태코드 | 발생 조건 |
| --- | --- |
| 404 | 주문이 없거나, 소유 증명(회원 본인·게스트 토큰·영수증 쿠키)에 실패했거나, NHN KCP 결제 레코드에 거래번호가 없는 경우. 소유권 실패도 존재 여부를 드러내지 않도록 같은 404 다. 본문은 공통 형식 `{ "success": false, "message": "…" }` |

**설명**

주문 완료·마이페이지 화면이 「영수증 보기」 버튼에 넣을 KCP 영수증 주소를 받는 경로다. 화면은 `data` 에서 주소를 읽는다(레이아웃 조각이 `response.data.*` · 직전 apiCall 결과 `$prev.data.*` 로 읽는다). 영수증 URL 이 결제 레코드에 저장돼 있지 않아도 거래번호(`tno`)로 동적으로 만든다. 현금영수증은 승인번호(`app_no` → `receipt_no` → `tno` 순 폴백)와 상점 코드(`site_cd`)로 만든다.

---

## GET /api/plugins/sirsoft-pay_nhnkcp/user/orders/{orderNumber}/vbank-mock-deposit-info

- **라우트명**: `api.plugins.sirsoft-pay_nhnkcp.user.orders.vbank-mock-deposit-info`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\UserVbankMockDepositController@show`
- **인증/권한**: `auth:sanctum` — 주문 소유자 본인 또는 관리자(`isAdmin()`)만 데이터를 받는다

**경로 파라미터**

| 이름 | 타입 | 용도 |
| --- | --- | --- |
| orderNumber | string | 가상계좌가 발급된 주문의 주문번호 |

**응답 (200)** — 공통 형식(`ResponseHelper`: `{ success, message, data }`). 아래 필드는 모두 `data` 안에 있다. 아래 조건 중 하나라도 어긋나면 `data` 에 `{ "available": false }` 만 담는다 (404 아님)

- 플러그인 테스트 모드가 켜져 있다 (미설정 기본값 `true`)
- 주문이 존재하고 요청자가 소유자이거나 관리자다
- 결제가 가상계좌이고 계좌번호가 발급됐으며 아직 입금 완료가 아니다

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| available | boolean | 모의입금을 안내할 수 있는 상태인지 |
| trade_no | string | 거래번호. `transaction_id` 가 비어 있으면 결제 메타의 `tno` 로 폴백 |
| account_no | string | 발급된 가상계좌 번호 |
| notify_url | string | 이 사이트의 입금통보 수신 주소 — KCP 테스트 페이지에 넣을 값. 요청 Host 가 아니라 사이트 주소(`APP_URL`) 기준 절대 URL ([vbank.md](vbank.md)) |
| mock_url | string | KCP 테스트 관리자의 가상계좌 모의입금 페이지 주소 |
| is_admin_view | boolean | 관리자 자격으로 조회했는지 (화면 문구 분기용) |

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "available": true,
        "trade_no": "…",
        "account_no": "…",
        "notify_url": "https://example.com/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify",
        "mock_url": "https://testadmin.kcp.co.kr/Modules/Noti/TEST_Vcnt_Noti.jsp",
        "is_admin_view": false
    }
}
```

**설명**

테스트 환경에서 가상계좌 입금을 시뮬레이션하려면 KCP 테스트 관리자 페이지에 거래번호·계좌번호·입금통보 주소를 넣어야 한다. 이 경로는 그 폼에 채울 값을 모아 주는 조회일 뿐이며, 실제 입금 처리는 KCP 가 `notify_url` 로 보내는 입금통보를 [vbank.md](vbank.md) 경로가 받아 수행한다. 운영 모드에서는 항상 `data.available: false` 다. 화면(레이아웃 조각 `user_order_show` 의 데이터 소스 `nhnkcp_mock_deposit`)은 응답 본문의 `data` 를 읽는다.

로그인 전용 경로라 그 데이터 소스는 `auth_mode="required"` 이고, 비회원 주문 상세(같은 결제 정보 카드 슬롯을 쓴다)에서는 요청을 보내지 않는다. 엔진은 건너뛴 요청을 401 로 알리므로 데이터 소스가 401·403 을 `suppress` 로 받는다 — 처리기가 없으면 템플릿 기본 401 처리(로그인 이동)로 떨어져 비회원 주문 상세가 로그인 화면으로 튕긴다. 데이터 소스 조건(`if`)은 주소·쿼리·전역 상태만 보므로 결제대행사·결제수단은 이 경로의 `available` 이 가린다.
