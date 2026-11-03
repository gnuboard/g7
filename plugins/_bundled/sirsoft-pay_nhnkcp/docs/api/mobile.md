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
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
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
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명**

모바일 결제창을 열기 전에 KCP 에서 승인키와 결제창 주소를 받아 오는 지점이다. 주문번호로 주문을 찾아 로그인한 회원이 주문자 본인인지 확인하고(아니면 403), 금액을 주문 청구액과 대조하며(다르면 422), KRW 주문만 받는다. 결제 전 단계를 지난 주문은 다시 결제할 수 있는 실패 건이면 결제 대기로 되돌린 뒤 발급하고, 그렇지 않으면 409 다. 결제창 복귀 주소(`Ret_URL`)는 요청 값이 아니라 서버가 사이트 주소로 만든다.

`order_number` 가 클레임 추가결제 결제번호(`CP` 채번)이면 주문이 아니라 클레임 결제 건을 조회해 같은 강도로 검증한다 — 대상 존재(404, `Claim payment not found.`) / 결제 대기 상태(422, `Claim payment is not payable.`) / 결제 건이 딸린 주문의 회원 본인(403) / 청구 통화 KRW(422) / 청구액 일치(422, `Payment amount does not match the claim payment amount.`). 기대 금액은 쇼핑몰 모듈의 클레임 결제 청구액(스냅샷 환산 최소 화폐단위)이다. 가상계좌로 매핑되는 `pay_method` 는 클레임 결제 상태 모델에 입금 대기 상태가 없어 검증 전에 422(`Claim additional payments do not support deposit-pending methods (virtual account).`)로 거절한다. 클레임 결제 건에는 재결제 복원이 없고, 응답 `fields` 에 주문 기준의 복합과세 필드(`tax_flag` 등)가 실리지 않는다. 결제창이 끝난 뒤의 승인과 신청서 상세로의 복귀는 결제 콜백(`POST /plugins/sirsoft-pay_nhnkcp/payment/callback`)이 맡는다 — [payment.md](payment.md) 참조. 계약 상세는 쇼핑몰 모듈 `modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md` 의 "결제 플러그인 연동 계약 (필터 훅)" 절을 참조한다.

