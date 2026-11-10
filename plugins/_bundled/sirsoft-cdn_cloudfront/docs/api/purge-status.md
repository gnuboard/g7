# Purge Status API 레퍼런스

> **소유**: plugin `sirsoft-cdn_cloudfront` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 플러그인 설정 화면의 「무효화 대기 현황」 데이터 소스
2. 권한: core.plugins.read (설정 화면 조회 권한과 같다)
3. 재시도 대기·멈춤 건수, 마지막 오류·실패·성공 시각, 지금 무효화를 보낼 수 있는지
```

---

### GET /api/plugins/sirsoft-cdn_cloudfront/admin/purge-status
<!-- @generated:start:api.plugins.sirsoft-cdn_cloudfront.admin.purge-status -->
- **라우트명**: `api.plugins.sirsoft-cdn_cloudfront.admin.purge-status`
- **컨트롤러**: `Plugins\Sirsoft\CdnCloudfront\Http\Controllers\Admin\CloudFrontAdminController@purgeStatus`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-cdn_cloudfront/admin/purge-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| pending | integer | `1` | 재시도를 기다리는 실패한 무효화 건수 |
| abandoned | integer | `0` | 실패가 5번에 닿아 재시도를 멈춘 건수 (설정 화면에 남는다) |
| last_error | string | `TooManyInvalidationsInProgress: Processing your request will cause you to exceed …` | 가장 최근에 실패한 건의 사유 (AWS 오류 코드와 메시지). 없으면 null |
| last_failed_at | string | `2026-11-05T10:00:00+09:00` | 가장 최근 실패 시각. 없으면 null |
| last_success_at | string | `2026-11-05T09:55:12+09:00` | 마지막으로 무효화가 성공한 시각. 없거나 캐시를 비웠으면 null |
| purge_ready | boolean | `true` | 지금 무효화를 보낼 수 있는지 — 배포 ID, 무효화용 키, CDN 주소가 모두 있고 자동 무효화가 켜져 있으면 true |

**응답 예시**

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "pending": 1,
        "abandoned": 0,
        "last_error": "TooManyInvalidationsInProgress: Processing your request will cause you to exceed the maximum number of in-progress wildcard invalidations.",
        "last_failed_at": "2026-11-05T10:00:00+09:00",
        "last_success_at": "2026-11-05T09:55:12+09:00",
        "purge_ready": true
    }
}
```
<!-- @generated:end -->

