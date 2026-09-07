# Vbank API 레퍼런스

> **소유**: plugin `sirsoft-pay_nhnkcp` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Vbank 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---

## 통보 수신 경로

| 라우트명 | 메서드/URI | 용도 |
| --- | --- | --- |
| `web.plugins.sirsoft-pay_nhnkcp.payment.vbank-notify` | `POST /plugins/sirsoft-pay_nhnkcp/payment/vbank-notify` | 가상계좌 입금통보(NOTI) 수신 |
| `web.plugins.sirsoft-pay_nhnkcp.payment.escrow-common-notify` | `POST /plugins/sirsoft-pay_nhnkcp/payment/escrow-common-notify` | 에스크로 공통통보 수신 |

**설명**

위 두 경로는 NHN KCP 서버가 구매자의 가상계좌 실입금·에스크로 상태 변경을 가맹점에 알리기 위해 직접 POST 로 호출한다. 브라우저를 거치지 않는 서버 대 서버 통신이므로, 위변조·재처리 요청을 막기 위해 **NHN KCP 공식 발신 IP 만 허용**한다.

이 IP 화이트리스트 검사는 코어의 확장 미들웨어 self-gate(`Plugin::getMiddleware()` 의 `targets` 로 위 두 통보 라우트명에만 정밀 타게팅)로 수행된다. 브라우저가 접속하는 결제 콜백(`payment.callback`)에는 적용되지 않는다 — 콜백은 정상 사용자의 브라우저에서 임의 IP 로 도달하므로 IP 로 제한하면 결제가 끊긴다. 검사 자체의 동작·허용 범위는 라우트 파일에서 직접 부착하던 이전 방식과 동일하다.

상세: [docs/backend/middleware.md "확장 미들웨어 선언 (self-gate)"](../../../../../docs/backend/middleware.md).


### GET /api/plugins/sirsoft-pay_nhnkcp/admin/vbank-notify-url
<!-- @generated:start:api.plugins.sirsoft-pay_nhnkcp.admin.vbank.notify.url -->
- **라우트명**: `api.plugins.sirsoft-pay_nhnkcp.admin.vbank.notify.url`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_nhnkcp/admin/vbank-notify-url HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| url | string | `https://example.com/plugins/sirsoft-pay_…` | 결제사가 입금 결과를 통보할 주소. 사이트가 하위 폴더에 설치돼 있어도 그 접두사를 포함한 절대 주소로 내려간다. |
| escrow_common_notify_url | string | `https://example.com/plugins/sirsoft-pay_…` | escrow common notify URL |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "data": {
        "url": "https://api.example.com/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify",
        "escrow_common_notify_url": "https://api.example.com/plugins/sirsoft-pay_nhnkcp/payment/escrow-common-notify"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->


