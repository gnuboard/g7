# Mobile API 레퍼런스

> **소유**: plugin `sirsoft-pay_nhnkcp` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Mobile 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key
<!-- @generated:start:api.plugins.sirsoft-pay_nhnkcp.mobile.approval-key -->
- **라우트명**: `api.plugins.sirsoft-pay_nhnkcp.mobile.approval-key`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\MobileApprovalController@getApprovalKey`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| X-Guest-Order-Token | header | string | 비회원 주문이면 예 | `{만료ts}|{HMAC-SHA256}` | 비회원 주문 조회 토큰(주문 생성 응답의 `guest_order_token`, 또는 비회원 주문 조회로 받은 토큰). 결제할 주문(클레임 추가결제면 그 결제 건이 딸린 주문)의 것이고 만료 전이어야 한다. 회원 주문은 이 헤더를 보지 않고 로그인(Bearer)으로만 판정한다. |
| order_number | body | string | 예 | max 40 | 주문번호. 클레임 추가결제(교환/반품 추가결제)이면 결제번호(`CP` 채번)다. |
| amount | body | integer | 예 | min 100 | 결제 금액(KRW). 주문 청구액(클레임이면 결제 건 청구액)과 대조해 다르면 422. |
| good_name | body | string | 예 | max 100 | 결제창에 보일 상품명. |
| pay_method | body | string | 예 | `card` · `bank`(`bank_transfer`) · `vbank`(`virtual_account`) · `phone`(`mobile`) · `nhnkcp_*` 간편결제 | 결제수단. 간편결제(`nhnkcp_` 접두)와 목록에 없는 값은 카드로 연다. 간편결제는 direct 값을 싣는다. |
| buyr_name | body | string | 아니오 | max 50 | 구매자 이름(결제창 표시용). 판정에 쓰지 않는다. |
| buyr_mail | body | string | 아니오 | email, max 100 | 구매자 이메일(결제창 표시용). 판정에 쓰지 않는다. |
| buyr_tel1 | body | string | 아니오 | max 20 | 구매자 전화(결제창 표시용). 판정에 쓰지 않는다. |
| ret_url | body | string | 아니오 | — | 구버전 프런트 호환으로만 받고 쓰지 않는다(서버가 사이트 주소로 `Ret_URL` 을 만든다). |
| currency | body | string | 아니오 | 3자 | 결제 통화. KRW 가 아니면 422. |

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 생략하고 X-Guest-Order-Token 을 싣는다)
X-Guest-Order-Token: {GUEST_ORDER_TOKEN}
Content-Type: application/json

{
    "order_number": "CP20261114-0819147738",
    "amount": 3000,
    "good_name": "반품 배송비",
    "pay_method": "card",
    "currency": "KRW"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| pay_url | string | 모바일 결제창 주소. 화면은 아래 `fields` 를 이 주소로 POST 해 결제창을 연다. |
| fields | object | 결제창에 그대로 넘길 폼 값 묶음. 주문번호·금액·상품명·복귀 주소와 서버가 결제사에서 받아 온 `approval_key` 가 들어 있다. 가상계좌 결제이면 입금기한(`vcnt_expire_term`)과 **일회성 확인값**(`param_opt_2`)이 더 실린다 — 그 확인값은 콜백에서 되돌아온 값과 대조해 그 콜백이 이 결제 세션에서 비롯됐음을 확인하는 데 쓰이며, 한 번 쓰면 소멸한다. |

**응답 예시**

```json
{
    "success": true,
    "data": {
        "pay_url": "https://mobile-payment.example.com/pay",
        "fields": {
            "ordr_idxx": "20260902-0557051431",
            "good_mny": "10000",
            "approval_key": "…",
            "Ret_URL": "https://example.com/shop/order/complete"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Guest order verification failed. | 비회원 주문(클레임이면 결제 건이 딸린 주문이 비회원 주문)인데 `X-Guest-Order-Token` 이 없거나 만료됐거나 다른 주문의 것. 토큰 없이 보낸 401 이라 화면은 로그인으로 옮기지 않고 「주문 확인 정보가 만료되었습니다. 주문 조회에서 다시 결제해 주세요.」를 띄운다 |
| 403 | You cannot request a NHN KCP approval key for this order. / … for this claim payment. | 회원 주문인데 로그인하지 않았거나 다른 회원(비회원 토큰을 붙여도 같다), 또는 클레임 결제 건의 주문이 사라짐 |
| 404 | Order not found. / Claim payment not found. | 주문 또는 클레임 결제 건이 없음 |
| 409 | Order is not retryable for NHN KCP payment. | 결제 전 단계를 지났고 다시 결제할 수 있는 실패 건도 아닌 주문 |
| 422 | (검증·금액·통화·결제 상태) | 필수 필드 누락, 금액 불일치, KRW 아님, 결제 대기가 아닌 클레임 결제 건, 클레임 결제의 가상계좌, SOAP 발급 실패 |

<!-- @generated:end -->

**설명**

모바일 결제창을 열기 전에 KCP 에서 승인키와 결제창 주소를 받아 오는 지점이다. 주문번호로 주문을 찾아 요청자가 주문 주인인지 확인하고 — 회원 주문은 로그인한 본인(아니면 403), 비회원 주문은 그 주문의 조회 토큰 `X-Guest-Order-Token`(없거나 틀리면 401) — 금액을 주문 청구액과 대조하며(다르면 422), KRW 주문만 받는다. 결제 전 단계를 지난 주문은 다시 결제할 수 있는 실패 건이면 결제 대기로 되돌린 뒤 발급하고, 그렇지 않으면 409 다. 결제창 복귀 주소(`Ret_URL`)는 요청 값이 아니라 서버가 사이트 주소로 만든다.

`order_number` 가 클레임 추가결제 결제번호(`CP` 채번)이면 주문이 아니라 클레임 결제 건을 조회해 같은 강도로 검증한다 — 대상 존재(404, `Claim payment not found.`) / 결제 대기 상태(422, `Claim payment is not payable.`) / 결제 건이 딸린 주문의 주인(주문 경로와 같은 판정 — 회원 주문은 로그인 본인, 아니면 403 · 비회원 주문은 그 주문의 조회 토큰, 아니면 401 · 주문이 사라졌으면 403) / 청구 통화 KRW(422) / 청구액 일치(422, `Payment amount does not match the claim payment amount.`). 기대 금액은 쇼핑몰 모듈의 클레임 결제 청구액(스냅샷 환산 최소 화폐단위)이다. 가상계좌로 매핑되는 `pay_method` 는 클레임 결제 상태 모델에 입금 대기 상태가 없어 검증 전에 422(`Claim additional payments do not support deposit-pending methods (virtual account).`)로 거절한다. 클레임 결제 건에는 재결제 복원이 없고, 응답 `fields` 에 주문 기준의 복합과세 필드(`tax_flag` 등)가 실리지 않는다. 결제창이 끝난 뒤의 승인과 신청서 상세로의 복귀는 결제 콜백(`POST /plugins/sirsoft-pay_nhnkcp/payment/callback`)이 맡는다 — [payment.md](payment.md) 참조. 계약 상세는 쇼핑몰 모듈 `modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md` 의 "결제 플러그인 연동 계약 (필터 훅)" 절을 참조한다.

