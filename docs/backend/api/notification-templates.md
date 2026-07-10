# Notification Templates API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Notification Templates 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/admin/notification-templates/preview
<!-- @generated:start:api.admin.notification-templates.preview -->
- **라우트명**: `api.admin.notification-templates.preview`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationTemplateController@preview`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| definition_id | body | integer | 예 | — | definition 식별자 |
| subject | body | array | 예 | — | 제목 |
| body | body | array | 예 | — | 본문 |
| locale | body | string | 아니오 | max 10 | 로케일 코드 (표시 언어/지역) |

**요청 예시**

```http
POST /api/admin/notification-templates/preview HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "definition_id": 1,
    "subject": [
        "예시값"
    ],
    "body": [
        "예시값"
    ],
    "locale": "ko"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 저장 전 알림 템플릿의 렌더링 결과를 미리 확인합니다. `definition_id` 와 다국어 `subject`/`body`, 선택적 `locale` 을 받아 샘플 변수로 치환된 제목·본문을 반환합니다. 인증(`auth:sanctum`)과 `core.settings.read` 권한이 필요하며, 실제 발송이나 저장은 일어나지 않습니다. 템플릿 편집 화면에서 변수 치환 결과를 실시간으로 확인할 때 사용합니다.


### PUT /api/admin/notification-templates/{template}
<!-- @generated:start:api.admin.notification-templates.update -->
- **라우트명**: `api.admin.notification-templates.update`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationTemplateController@update`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| template | path | string | 예 | — | 대상 template의 식별자 |
| subject | body | array | 예 | — | 제목 |
| body | body | array | 예 | — | 본문 |
| click_url | body | string | 아니오 | max 500 | 알림 클릭 시 이동할 대상 URL (미설정 시 이동 없음) |
| recipients | body | array | 아니오 | — | 수신자 규칙 목록. 각 원소는 type(trigger_user: 이벤트 유발 사용자, related_user: 연관 사용자, role: 역할 대상, specific_users: 지정 사용자), value(대상 식별값), relation(연관 사용자 관계명), exclude_trigger_user(유발 사용자 제외 여부)로 구성 |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.notification_template.filter_update_rules`).

**요청 예시**

```http
PUT /api/admin/notification-templates/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "subject": [
        "예시값"
    ],
    "body": [
        "예시값"
    ],
    "click_url": "https://example.com",
    "recipients": [
        "예시값"
    ],
    "is_active": true
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 단일 채널 알림 템플릿의 다국어 제목(`subject`)·본문(`body`)과 클릭 URL, 수신자(`recipients`), 활성 상태를 수정합니다. 인증(`auth:sanctum`)과 `core.settings.update` 권한이 필요합니다. `template` 경로 파라미터로 대상을 지정하며, 확장이 `core.notification_template.filter_update_rules` 훅으로 추가 파라미터를 검증에 넣을 수 있습니다. 관리자가 특정 채널의 알림 문구를 편집해 저장할 때 사용합니다.


### POST /api/admin/notification-templates/{template}/reset
<!-- @generated:start:api.admin.notification-templates.reset -->
- **라우트명**: `api.admin.notification-templates.reset`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationTemplateController@reset`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| template | path | string | 예 | — | 대상 template의 식별자 |

**요청 예시**

```http
POST /api/admin/notification-templates/1/reset HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-404 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 단일 채널 템플릿을 소속 정의의 기본값 데이터로 복원합니다. 인증(`auth:sanctum`)과 `core.settings.update` 권한이 필요합니다. 소속 정의가 없으면 404, 해당 채널의 기본 데이터가 없으면 404 를 반환합니다. 편집한 문구를 버리고 기본값 하나만 되돌릴 때 사용하며, 정의 전체를 복원하는 정의 reset 과 달리 대상 템플릿에만 적용됩니다.


### PATCH /api/admin/notification-templates/{template}/toggle-active
<!-- @generated:start:api.admin.notification-templates.toggle-active -->
- **라우트명**: `api.admin.notification-templates.toggle-active`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationTemplateController@toggleActive`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| template | path | string | 예 | — | 대상 template의 식별자 |

**요청 예시**

```http
PATCH /api/admin/notification-templates/1/toggle-active HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| definition_id | integer | `1` | definition 식별자 (연관 리소스 참조) |
| channel | string | `mail` | 채널: mail, database, fcm |
| subject | string | `API 문서 샘플 템플릿 제목` | 다국어 제목 ({"ko": "...", "en": "..."}) |
| body | string | `안녕하세요 {{name}} 님, 문서 실측용 본문입니다.` | 다국어 본문 ({"ko": "...", "en": "..."}) |
| click_url | string | `/admin/apidoc-sample` | click URL |
| recipients | array | `[{"type":"role","value":"admin","display_name":"관리자"}]` | 수신자 규칙 JSON ([{type, value, relation, exclude_trigger_user}]) |
| is_active | boolean | `false` | active 여부 |
| is_default | boolean | `false` | default 여부 |
| user_overrides | array | `["is_active"]` | 사용자가 수정한 필드명 목록 |
| updated_by | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | 최종 수정한 사용자 정보 (uuid/name — updated_by 관계 파생, 없으면 null) |
| created_at | string | `2026-07-08 10:41:24` | 생성 일시 |
| updated_at | string | `2026-07-08 12:14:43` | 최종 수정 일시 |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "알림 템플릿 활성 상태가 변경되었습니다.",
    "data": {
        "id": 1,
        "definition_id": 1,
        "channel": "mail",
        "subject": "API 문서 샘플 템플릿 제목",
        "body": "안녕하세요 {{name}} 님, 문서 실측용 본문입니다.",
        "click_url": "/admin/apidoc-sample",
        "recipients": [
            {
                "type": "role",
                "value": "admin",
                "display_name": "관리자"
            }
        ],
        "is_active": false,
        "is_default": false,
        "user_overrides": [
            "is_active"
        ],
        "updated_by": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "created_at": "2026-07-08 10:41:24",
        "updated_at": "2026-07-08 12:14:43",
        "abilities": {
            "can_update": true,
            "can_delete": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 단일 채널 알림 템플릿의 활성 상태(`is_active`)를 현재 값의 반대로 토글합니다. 인증(`auth:sanctum`)과 `core.settings.update` 권한이 필요합니다. 비활성 템플릿은 해당 채널로의 발송이 중단되므로, 정의는 유지한 채 특정 채널만 켜거나 끌 때 사용합니다.


