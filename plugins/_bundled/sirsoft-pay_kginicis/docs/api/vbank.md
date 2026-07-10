# Vbank API 레퍼런스

> **소유**: plugin `sirsoft-pay_kginicis` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Vbank 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-pay_kginicis/admin/vbank-notify-url
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.admin.vbank.notify.url -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.admin.vbank.notify.url`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_kginicis/admin/vbank-notify-url HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| url | string | `https://api.example.com/plugins/sirsoft-pay_…` | PC 웹용 가상계좌 입금통보(NOTI) 수신 콜백 URL(`/plugins/sirsoft-pay_kginicis/payment/vbank-notify`)의 절대 주소. 운영자가 이 값을 KG 이니시스 가맹점 설정에 입금통보 URL로 등록하면, 구매자가 가상계좌에 실제 입금했을 때 KG 이니시스가 이 주소로 통보한다. |
| mobile_url | string | `https://api.example.com/plugins/sirsoft-pay_…` | mobile URL |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "data": {
        "url": "https://api.example.com/plugins/sirsoft-pay_kginicis/payment/vbank-notify",
        "mobile_url": "https://api.example.com/plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

가상계좌 입금통보를 받을 콜백 URL(PC 웹용 `url`, 모바일용 `mobile_url`)을 관리자 설정 페이지에 표시하기 위해 반환하는 엔드포인트입니다. 라우트 파일 내 클로저로 정의되어 있으며, `url()` 헬퍼로 현재 사이트 도메인 기준의 절대 URL(`/plugins/sirsoft-pay_kginicis/payment/vbank-notify` 및 `.../payment/mobile/vbank-notify`)을 조합해 내려줍니다. 운영자는 이 URL 을 KG 이니시스 가맹점 설정에 입금통보 URL 로 등록해, 구매자가 가상계좌에 실제로 입금했을 때 KG 이니시스가 이 주소로 통보를 보내도록 합니다. 관리자 인증(`auth:sanctum`)과 `sirsoft-ecommerce.settings.read` 권한이 필요하며, 토큰 누락·만료는 401, 권한 부족은 403 으로 응답합니다.


