# Webhook Url API 레퍼런스

> **소유**: plugin `sirsoft-tosspayments` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Webhook Url 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-tosspayments/admin/webhook-url
<!-- @generated:start:api.plugins.sirsoft-tosspayments.admin.webhook-url -->
- **라우트명**: `api.plugins.sirsoft-tosspayments.admin.webhook-url`
- **인증/권한**: `auth:sanctum`, `permission:admin,sirsoft-ecommerce.settings.read`

**요청 파라미터**

없음

**요청 예시**

```http
GET /api/plugins/sirsoft-tosspayments/admin/webhook-url HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| url | string | `https://example.com/plugins/sirsoft-tosspayments/webhook/deposit` | 가상계좌 입금통보 웹훅 수신 전체 URL (사이트 주소 포함) |

**응답 예시**

```json
{
    "success": true,
    "data": {
        "url": "https://example.com/plugins/sirsoft-tosspayments/webhook/deposit"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 관리자가 아니거나 `sirsoft-ecommerce.settings.read` 권한이 없는 경우 |

<!-- @generated:end -->

**설명**

관리자 설정 화면이 가상계좌 입금통보 웹훅 주소를 보여 주고 「복사」하는 데 쓰는 조회다. 운영자는 이 값을 토스 개발자센터 > 웹훅 메뉴에 DEPOSIT_CALLBACK 이벤트로 그대로 붙여 넣는다.

- 주소는 서버가 코어 사이트 주소(`APP_URL`, `SiteUrl::absolute()`) 기준으로 만든 전체 URL 이라 운영자가 도메인을 손으로 덧붙일 필요가 없다. 관리자가 접속한 주소(요청 Host)는 따르지 않는다. 다른 결제사 플러그인(KG이니시스·NHN KCP·나이스페이먼츠)의 입금통보 URL 조회와 같은 방식이다.
- 설정 화면은 응답이 오기 전·권한 부족으로 조회가 실패한 경우 경로(`/plugins/sirsoft-tosspayments/webhook/deposit`)로 폴백해 표시한다.
- 관리자 화면을 사내 주소·프록시 뒤 주소로 열어도 사이트 주소가 붙는다. 사이트 주소(`APP_URL`)가 토스 서버가 도달할 수 있는 공개 도메인인지 확인한 뒤 복사한다(사이트 주소가 비어 있으면 경로만 내려간다).
