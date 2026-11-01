# 개인정보 통지 시험 발송 API

### POST /api/plugins/sirsoft-gdpr/admin/annual-notice/test
<!-- @generated:start:api.plugins.sirsoft-gdpr.admin.annual-notice.test -->
- **라우트명**: `api.plugins.sirsoft-gdpr.admin.annual-notice.test`
- **컨트롤러**: `Plugins\Sirsoft\Gdpr\Http\Controllers\Admin\GdprAdminNoticeController@sendTest`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-gdpr.privacy.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| channels | body | array | 서버가 채움 | min 1, max 2, 항목은 `mail`, `database` 중 중복 없이 | 클라이언트가 보내지 않습니다(요청 본문은 빈 객체). 검증 직전에 서버가 저장된 설정 `annual_notice.channels`(없으면 `mail`, `database`)로 이 값을 덮어쓰므로 보낸 값은 무시됩니다 |

**요청 예시**

```http
POST /api/plugins/sirsoft-gdpr/admin/annual-notice/test HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| attempt_id | string | `1c2bf71c-4815-403d-9e52-7c05ca0ec1d4` | UUID 접수 식별자. 성공 응답은 전달 완료가 아닌 접수 완료이며, 결과는 통지 이력에서 확인합니다 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "시험 발송을 접수했습니다. 이력에서 결과를 확인하세요.",
    "data": {
        "attempt_id": "1c2bf71c-4815-403d-9e52-7c05ca0ec1d4"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-gdpr.privacy.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 저장된 채널 설정이 비었거나 `mail`, `database` 밖의 값을 담은 경우 (`error.errors.channels` 에 메시지) |
| 422 | Unprocessable Entity | 요청한 관리자 본인이 활성 회원이 아니라 접수하지 못한 경우 `발송 가능한 활성 회원과 채널 설정을 확인하세요.` |
| 429 | Too Many Requests | 분당 6회를 초과해 요청한 경우. 제한 키는 시험 발송 전용 접두사(`gdpr-annual-notice:`)를 써서 다른 API 의 제한과 분리됩니다 |

<!-- @generated:end -->

**설명** 현재 인증된 활성 회원 자신에게 저장된 채널로 시험 발송을 접수합니다. 수신자 ID를 입력받지 않습니다.
시험 발송은 자동 통지 토글·가입일과 독립이며 정기 통지 이력 계산에서 제외됩니다.

### GET /api/plugins/sirsoft-gdpr/admin/annual-notice/preview
<!-- @generated:start:api.plugins.sirsoft-gdpr.admin.annual-notice.preview -->
- **라우트명**: `api.plugins.sirsoft-gdpr.admin.annual-notice.preview`
- **컨트롤러**: `Plugins\Sirsoft\Gdpr\Http\Controllers\Admin\GdprAdminNoticeController@preview`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-gdpr.privacy.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-gdpr/admin/annual-notice/preview HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| enabled | boolean | `true` | 자동 통지가 켜져 있는지 |
| count | integer | `0` | 이번 주기 대상 회원 수 |
| can_view_targets | boolean | `true` | 요청 관리자가 명단을 볼 권한(`sirsoft-gdpr.privacy.view` + `core.users.read`)이 있는지 — 없으면 화면이 「더보기」를 그리지 않는다 |

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
        "enabled": true,
        "count": 0,
        "can_view_targets": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-gdpr.privacy.view`)이 없는 경우 |

<!-- @generated:end -->

**설명** 이번 주기 연간 통지 대상 회원 수를 돌려줍니다. 통지 탭의 「대상 N명」 배지가 씁니다. **자동 통지가 꺼져 있어도 셉니다** — 켜기 전에 규모를 확인하는 자리입니다.
대상은 발송 판정과 같습니다: 활성 회원 · 가입 1년 경과 · 설정된 채널 중 하나라도 최근 1년 안에 발송 완료 기록이 없고 결과를 기다리는 접수도 없는 회원. 한 번 실행에 보내는 수는 발송 상한(`batch_size`)으로 잘리므로 이 숫자가 한 번에 다 나가지는 않습니다.

### GET /api/plugins/sirsoft-gdpr/admin/annual-notice/targets
<!-- @generated:start:api.plugins.sirsoft-gdpr.admin.annual-notice.targets -->
- **라우트명**: `api.plugins.sirsoft-gdpr.admin.annual-notice.targets`
- **컨트롤러**: `Plugins\Sirsoft\Gdpr\Http\Controllers\Admin\GdprAdminNoticeController@targets`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-gdpr.privacy.view|core.users.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | 1 이상 | 페이지 번호 (기본 1) |
| per_page | query | integer | 아니오 | 1~100 | 페이지 크기 (기본 20) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.privacy.target_list_validation_rules`).

**요청 예시**

```http
GET /api/plugins/sirsoft-gdpr/admin/annual-notice/targets?page=1&per_page=20 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `7` | 회원 ID |
| title | string | `홍길동` | 회원 이름 |
| subtitle | string | `hong@example.com` | 회원 이메일 |
| context | null | `null` | 이 명단은 보조 문구를 쓰지 않아 항상 `null` |
| date | string | `2024-03-02` | 가입일 (사용자 시간대 `Y-m-d`) |
| url | string | `/admin/users/9f0c1b2e-4a57-4b8e-9d0a-6f3e2c1d0a11` | 회원 상세 화면 경로 (`/admin/users/{회원 UUID}`) |

**응답 예시**

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "data": [
            {
                "id": 7,
                "title": "홍길동",
                "subtitle": "hong@example.com",
                "context": null,
                "date": "2024-03-02",
                "url": "/admin/users/9f0c1b2e-4a57-4b8e-9d0a-6f3e2c1d0a11"
            }
        ],
        "columns": {
            "title": "이름",
            "subtitle": "이메일",
            "date": "가입일"
        },
        "pagination": {
            "current_page": 1,
            "per_page": 20,
            "from": 1,
            "to": 1,
            "has_more_pages": false,
            "last_page": 1,
            "total": 1
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-gdpr.privacy.view\|core.users.read`)이 없는 경우 (두 권한 모두 필요) |
| 403 | Forbidden | 두 권한의 적용 범위가 **전체**가 아닌 경우 — 회원 조회를 「본인만」, 「같은 역할만」으로 위임받은 관리자는 건수만 보고(`can_view_targets=false`) 이 API 는 403 입니다(코어 `PrivacyRetentionManager::canViewTargetList()` 판정) |
| 422 | Unprocessable Entity | `page`, `per_page` 가 범위를 벗어난 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 이번 주기 연간 통지 대상 명단을 한 페이지씩 돌려줍니다. 통지 탭의 「더보기」 모달이 무한스크롤로 부릅니다. 명단에 회원 이름·이메일이 그대로 나오므로 회원 읽기 권한까지 요구합니다.
행 형태는 코어의 개인정보 조치 대상 목록과 같습니다. `data.columns` 는 화면이 그릴 열 이름과 라벨(`title` 이름, `subtitle` 이메일, `date` 가입일)이고, `data.pagination` 은 표준 메타입니다. 회원 ID 순 — 발송 루프와 같은 순서입니다.
