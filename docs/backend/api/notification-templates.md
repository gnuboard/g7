# Notification Templates API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Notification Templates 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
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
| subject | body | array | 예 | — | 다국어 제목. 컬럼이 nullable 이므로 미전송·`null` 이 허용된다(제목 개념이 없는 채널 대응). 전송하는 경우 로케일별 문자열 배열이어야 하며 각 값은 500자 이하 |
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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| subject | string | `[사이트명] 회원가입을 환영합니다` | 요청 `locale`(미지정 시 현재 앱 로케일) 의 제목에서 `{변수}` 를 정의(`definition_id`)의 변수 설명(`[설명]`)으로 치환한 결과 |
| body | string | `<h1>[회원 이름]님, 환영합니다!</h1>...` | 동일 로케일 본문에서 `{변수}` 를 정의의 변수 설명으로 치환한 결과. 해당 로케일 값이 없으면 빈 문자열 |

**응답 예시**

```json
{
    "success": true,
    "message": "미리보기를 생성했습니다.",
    "data": {
        "subject": "[사이트명] 회원가입을 환영합니다",
        "body": "<h1>[회원 이름]님, 환영합니다!</h1><p>[사이트명]에 가입해 주셔서 감사합니다.</p>"
    }
}
```

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
| subject | body | array | 아니오 | — | 다국어 제목. 컬럼이 nullable 이므로 미전송·`null` 이 허용된다(제목 개념이 없는 채널 대응). 전송하는 경우 로케일별 문자열 배열이어야 하며 각 값은 500자 이하 |
| body | body | array | 예 | — | 본문 |
| click_url | body | string | 아니오 | max 500 | 알림 클릭 시 이동할 대상 URL (미설정 시 이동 없음) |
| recipients | body | array | 아니오 | — | 수신자 규칙 목록. 각 원소는 type(trigger_user: 이벤트 유발 사용자, related_user: 연관 사용자, role: 역할 대상, specific_users: 지정 사용자), value(대상 식별값), relation(연관 사용자 관계명), exclude_trigger_user(유발 사용자 제외 여부)로 구성 |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.notification_template.update_validation_rules`).

**요청 예시**

```http
PUT /api/admin/notification-templates/{template} HTTP/1.1
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

_단건 응답: `data` 객체의 필드 (`NotificationTemplateResource`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| definition_id | integer | `1` | 소속 알림 정의 식별자 |
| channel | string | `mail` | 발송 채널 (`mail`, `database`, `fcm`) |
| subject | object | `{"ko":"[{app_name}] 회원가입을 환영합니다","en":"..."}` | 다국어 제목 (로케일 키 → 문자열, 로케일당 최대 500자) |
| body | object | `{"ko":"<h1>{name}님, 환영합니다!</h1>...","en":"..."}` | 다국어 본문 (로케일 키 → 문자열, 로케일당 최대 65535자) |
| click_url | string \| null | `null` | 알림 클릭 시 이동할 대상 URL (미설정 시 `null`) |
| recipients | array | `[{"type":"role","value":"admin","display_name":"관리자"}]` | 수신자 규칙 목록. `type` 은 `trigger_user`/`related_user`/`role`/`specific_users`. `role` 이면 `display_name`, `specific_users` 면 `display_names` 가 표시용으로 부가됨 |
| is_active | boolean | `true` | 활성 여부 (false 면 해당 채널 발송 중단) |
| is_default | boolean | `false` | 기본값 상태 여부 (수정 시 항상 `false` 로 전환됨) |
| user_overrides | array \| null | `["subject","body"]` | 사용자가 직접 수정한 필드명 목록 (`HasUserOverrides`) |
| updated_by | string \| null | `a234c2b1-cde8-437f-b28b-23323be2b98d` | 최종 수정한 사용자 UUID (없으면 `null`) |
| created_at | string | `2026-07-08 10:41:24` | 생성 일시 |
| updated_at | string | `2026-07-08 12:14:43` | 최종 수정 일시 |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (모두 `core.settings.update` 권한 기반) |

**응답 예시**

```json
{
    "success": true,
    "message": "알림 템플릿이 수정되었습니다.",
    "data": {
        "id": 1,
        "definition_id": 1,
        "channel": "mail",
        "subject": {
            "ko": "[{app_name}] 회원가입을 환영합니다",
            "en": "[{app_name}] Welcome to Our Service"
        },
        "body": {
            "ko": "<h1>{name}님, 환영합니다!</h1>",
            "en": "<h1>Welcome, {name}!</h1>"
        },
        "click_url": "/admin/notifications",
        "recipients": [
            {
                "type": "role",
                "value": "admin",
                "display_name": "관리자"
            }
        ],
        "is_active": true,
        "is_default": false,
        "user_overrides": [
            "subject",
            "body"
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 단일 채널 알림 템플릿의 다국어 제목(`subject`)·본문(`body`)과 클릭 URL, 수신자(`recipients`), 활성 상태를 수정합니다. 인증(`auth:sanctum`)과 `core.settings.update` 권한이 필요합니다. `template` 경로 파라미터로 대상을 지정하며, 확장이 `core.notification_template.update_validation_rules` 훅으로 추가 파라미터를 검증에 넣을 수 있습니다. 관리자가 특정 채널의 알림 문구를 편집해 저장할 때 사용합니다.


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
POST /api/admin/notification-templates/{template}/reset HTTP/1.1
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
| subject | object | `{"ko":"[{board_name}] 게시글에 새 댓글이 등록되었습니다","en":"[{board_n…` | 다국어 제목 ({"ko": "...", "en": "..."}) |
| body | object | `{"ko":"<h1>{name}님, 안녕하세요.<\/h1><p><strong>{board_name}<\…` | 다국어 본문 ({"ko": "...", "en": "..."}) |
| click_url | null | `null` | click URL |
| recipients | array | `[{"type":"related_user","relation":"post_author","exclude…` | 수신자 규칙 JSON ([{type, value, relation, exclude_trigger_user}]) |
| is_active | boolean | `true` | active 여부 |
| is_default | boolean | `true` | default 여부 |
| user_overrides | null | `null` | 사용자가 수정한 필드명 목록 |
| updated_by | null | `null` | 최종 수정한 사용자 정보 (uuid/name — updated_by 관계 파생, 없으면 null) |
| created_at | string | `2026-07-30 18:45:11` | 생성 일시 |
| updated_at | string | `2026-07-30 18:45:11` | 최종 수정 일시 |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "알림 템플릿이 기본값으로 복원되었습니다.",
    "data": {
        "id": 1,
        "definition_id": 1,
        "channel": "mail",
        "subject": {
            "ko": "[{board_name}] 게시글에 새 댓글이 등록되었습니다",
            "en": "[{board_name}] New comment on your post"
        },
        "body": {
            "ko": "<h1>{name}님, 안녕하세요.</h1><p><strong>{board_name}</strong> 게시판의 게시글에 <strong>{comment_author}</strong>님이 댓글을 남겼습니다.</p><blockquote style=\"border-left: 3px solid #cbd5e0; padding-left: 12px; color: #718096;\">{comment_content}</blockquote><table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"margin: 24px 0;\"><tr><td align=\"center\"><a href=\"{post_url}\" style=\"display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;\">게시글 보기</a></td></tr></table><p>감사합니다,<br><a href=\"{site_url}\">{app_name}</a></p>",
            "en": "<h1>Hello, {name}.</h1><p><strong>{comment_author}</strong> commented on your post in <strong>{board_name}</strong>.</p><blockquote style=\"border-left: 3px solid #cbd5e0; padding-left: 12px; color: #718096;\">{comment_content}</blockquote><table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"margin: 24px 0;\"><tr><td align=\"center\"><a href=\"{post_url}\" style=\"display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;\">View Post</a></td></tr></table><p>Thank you,<br><a href=\"{site_url}\">{app_name}</a></p>"
        },
        "click_url": null,
        "recipients": [
            {
                "type": "related_user",
                "relation": "post_author",
                "exclude_trigger_user": true
            }
        ],
        "is_active": true,
        "is_default": true,
        "user_overrides": null,
        "updated_by": null,
        "created_at": "2026-07-30 18:45:11",
        "updated_at": "2026-07-30 18:45:11",
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

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
PATCH /api/admin/notification-templates/{template}/toggle-active HTTP/1.1
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
| subject | object | `{"ko":"[{board_name}] 게시글에 새 댓글이 등록되었습니다","en":"[{board_n…` | 다국어 제목 ({"ko": "...", "en": "..."}) |
| body | object | `{"ko":"<h1>{name}님, 안녕하세요.<\/h1><p><strong>{board_name}<\…` | 다국어 본문 ({"ko": "...", "en": "..."}) |
| click_url | null | `null` | click URL |
| recipients | array | `[{"type":"related_user","relation":"post_author","exclude…` | 수신자 규칙 JSON ([{type, value, relation, exclude_trigger_user}]) |
| is_active | boolean | `false` | active 여부 |
| is_default | boolean | `true` | default 여부 |
| user_overrides | array | `["is_active"]` | 사용자가 수정한 필드명 목록 |
| updated_by | null | `null` | 최종 수정한 사용자 정보 (uuid/name — updated_by 관계 파생, 없으면 null) |
| created_at | string | `2026-07-30 18:45:11` | 생성 일시 |
| updated_at | string | `2026-08-04 21:53:43` | 최종 수정 일시 |
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
        "subject": {
            "ko": "[{board_name}] 게시글에 새 댓글이 등록되었습니다",
            "en": "[{board_name}] New comment on your post"
        },
        "body": {
            "ko": "<h1>{name}님, 안녕하세요.</h1><p><strong>{board_name}</strong> 게시판의 게시글에 <strong>{comment_author}</strong>님이 댓글을 남겼습니다.</p><blockquote style=\"border-left: 3px solid #cbd5e0; padding-left: 12px; color: #718096;\">{comment_content}</blockquote><table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"margin: 24px 0;\"><tr><td align=\"center\"><a href=\"{post_url}\" style=\"display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;\">게시글 보기</a></td></tr></table><p>감사합니다,<br><a href=\"{site_url}\">{app_name}</a></p>",
            "en": "<h1>Hello, {name}.</h1><p><strong>{comment_author}</strong> commented on your post in <strong>{board_name}</strong>.</p><blockquote style=\"border-left: 3px solid #cbd5e0; padding-left: 12px; color: #718096;\">{comment_content}</blockquote><table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"margin: 24px 0;\"><tr><td align=\"center\"><a href=\"{post_url}\" style=\"display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;\">View Post</a></td></tr></table><p>Thank you,<br><a href=\"{site_url}\">{app_name}</a></p>"
        },
        "click_url": null,
        "recipients": [
            {
                "type": "related_user",
                "relation": "post_author",
                "exclude_trigger_user": true
            }
        ],
        "is_active": false,
        "is_default": true,
        "user_overrides": [
            "is_active"
        ],
        "updated_by": null,
        "created_at": "2026-07-30 18:45:11",
        "updated_at": "2026-08-04 21:53:43",
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 단일 채널 알림 템플릿의 활성 상태(`is_active`)를 현재 값의 반대로 토글합니다. 인증(`auth:sanctum`)과 `core.settings.update` 권한이 필요합니다. 비활성 템플릿은 해당 채널로의 발송이 중단되므로, 정의는 유지한 채 특정 채널만 켜거나 끌 때 사용합니다.


