# 개인정보 통지 이력 API

### GET /api/plugins/sirsoft-gdpr/admin/notice-log
<!-- @generated:start:api.plugins.sirsoft-gdpr.admin.notice-log.index -->
- **라우트명**: `api.plugins.sirsoft-gdpr.admin.notice-log.index`
- **컨트롤러**: `Plugins\Sirsoft\Gdpr\Http\Controllers\Admin\GdprAdminNoticeController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-gdpr.privacy.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작, 기본 1) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 (기본 20) |

**요청 예시**

```http
GET /api/plugins/sirsoft-gdpr/admin/notice-log?page=1&per_page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `79` | 이력 ID (접수, 채널 결과 이벤트마다 한 행) |
| user_id | integer\|null | `3925` | 수신 회원, 완전 삭제 후 null |
| status | string | `sent` | queued 접수, sent 성공, failed 실패 |
| status_label | string | `발송 완료` | 현재 로케일의 상태 문구 |
| channels | array | `["database"]` | 해당 이벤트의 채널 |
| channels_label | string | `사이트내 알림` | 화면용 채널 이름 목록(현재 언어, 쉼표 구분). 지금 없는 채널은 식별자 그대로 |
| is_test | boolean | `true` | 시험 발송 여부 |
| sent_at | string\|null | `2026-10-07 17:37:43` | 사용자 시간대 기준 발송 시각. 접수(`queued`) 이벤트는 `null` |
| created_at | string | `2026-10-07 17:37:43` | 사용자 시간대 기준 이벤트 시각 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "data": [
            {
                "id": 79,
                "user_id": 3925,
                "status": "sent",
                "status_label": "발송 완료",
                "channels": [
                    "database"
                ],
                "channels_label": "사이트내 알림",
                "is_test": true,
                "sent_at": "2026-10-07 17:37:43",
                "created_at": "2026-10-07 17:37:43"
            },
            {
                "id": 78,
                "user_id": 3925,
                "status": "queued",
                "status_label": "접수",
                "channels": [
                    "database"
                ],
                "channels_label": "사이트내 알림",
                "is_test": true,
                "sent_at": null,
                "created_at": "2026-10-07 17:37:43"
            },
            "... (총 25건 중 2건 표시)"
        ],
        "pagination": {
            "current_page": 1,
            "last_page": 3,
            "per_page": 25,
            "total": 66,
            "from": 1,
            "to": 25
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-gdpr.privacy.view`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** `data.pagination`은 current_page, last_page, per_page, total, from, to를 반환합니다.
total은 수신 회원 수가 아니라 접수·채널 결과 이벤트의 합계입니다. 스냅샷 본문은 목록에서 제외합니다.
