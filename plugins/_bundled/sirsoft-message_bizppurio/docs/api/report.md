# Report API 레퍼런스

> **소유**: plugin `sirsoft-message_bizppurio` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Report 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-message_bizppurio/admin/report-url
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.report.url -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.report.url`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-message_bizppurio/admin/report-url HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| url | string | `http://g7-issue.eh.test/api/plugins/s…` | <!-- TODO: 설명 --> |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "data": {
        "url": "https://api.example.com/api/plugins/sirsoft-message_bizppurio/webhook"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

비즈뿌리오 발송 결과(리포트)를 수신할 콜백 URL을 관리자 설정 페이지에 표시하기 위해 반환하는 엔드포인트입니다. 관리자 환경설정 화면(`admin/plugin_settings.json`)의 `report_url` data_source 가 이 값을 조회해 "리포트 수신 설정" 카드에 readonly 로 표시하고, 운영자가 그대로 복사해 비즈뿌리오 사업팀(또는 관리 콘솔의 리포트 수신 설정)에 URL PUSH 수신 주소로 등록합니다.

URL 은 `url()` 헬퍼가 아니라 `config('app.url')` 을 기준으로 조합합니다 — 리버스 프록시 뒤 PHP-FPM 환경에서 요청 host 가 `localhost` 로 떨어질 수 있어, 운영자가 관리하는 설정값을 신뢰 소스로 삼아 항상 정식 도메인을 노출합니다.

관리자 인증(`auth:sanctum`)과 `core.plugins.read` 권한이 필요하며, 토큰 누락·만료는 401, 권한 부족은 403 으로 응답합니다.

※ 실제 리포트 수신 처리(`POST /webhook`)는 후속 단계에서 제공됩니다. 본 엔드포인트는 표시용 주소 조회만 담당합니다.


