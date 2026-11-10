# Test Connection API 레퍼런스

> **소유**: plugin `sirsoft-cdn_cloudfront` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 플러그인 설정 화면의 「연결 시험」 버튼
2. 권한: core.plugins.update (외부로 요청을 보내므로 수정 권한)
3. 요청 본문 없음 — 저장된 배포 ID 와 무효화용 키로 GetDistribution 을 보낸다
4. 자격 증명 값은 응답에 담기지 않는다
5. 분당 20회까지 — 넘으면 429 이고 CloudFront 를 부르지 않는다
```

---

### POST /api/plugins/sirsoft-cdn_cloudfront/admin/test-connection
<!-- @generated:start:api.plugins.sirsoft-cdn_cloudfront.admin.test-connection -->
- **라우트명**: `api.plugins.sirsoft-cdn_cloudfront.admin.test-connection`
- **컨트롤러**: `Plugins\Sirsoft\CdnCloudfront\Http\Controllers\Admin\CloudFrontAdminController@testConnection`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.update`

**요청 파라미터**

_요청 파라미터 없음._ 저장된 설정으로만 시험합니다 — 화면에서 고친 값은 먼저 저장해야 반영됩니다.

**요청 예시**

```http
POST /api/plugins/sirsoft-cdn_cloudfront/admin/test-connection HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| ok | boolean | `true` | 배포 조회 성공 여부 |
| reason | string | `null` | 실패 사유 코드 (성공이면 null) |
| error | string | `null` | 실패 상세 (성공이면 null) |
| status | string | `Deployed` | 배포 상태 (`Deployed`, `InProgress`) |
| domain_name | string | `d111111abcdef8.cloudfront.net` | 배포 도메인 |
| enabled | boolean | `true` | 배포 사용 여부 |
| cdn_host_matches | boolean | `true` | 설정한 CDN 주소의 호스트가 이 배포의 도메인이나 대체 도메인인지. CDN 주소가 비었으면 null |

**실패 응답** (`422`)

| `errors.reason` | 뜻 |
| --- | --- |
| `missing_credentials` | 배포 ID, 무효화용 액세스 키, 비밀 키 가운데 빈 값이 있다 — CloudFront 를 부르지 않는다 |
| `request_failed` | CloudFront 가 조회를 거절했다 (`errors.error` 에 AWS 오류 코드와 메시지, `message` 에도 포함) |

**응답 예시**

```json
{
    "success": true,
    "message": "CloudFront 배포에 연결했습니다.",
    "data": {
        "ok": true,
        "reason": null,
        "error": null,
        "status": "Deployed",
        "domain_name": "d111111abcdef8.cloudfront.net",
        "enabled": true,
        "cdn_host_matches": true
    }
}
```
<!-- @generated:end -->
