# Notification Definitions API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Notification Definitions 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/notification-definitions
<!-- @generated:start:api.admin.notification-definitions.index -->
- **라우트명**: `api.admin.notification-definitions.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationDefinitionController@index`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| search | query | string | 아니오 | max 255 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| extension_type | query | string | 아니오 | `core`, `module`, `plugin` | 확장 유형 (core/module/plugin/template) |
| extension_identifier | query | string | 아니오 | max 100 | 확장 식별자 |
| channel | query | string | 아니오 | max 50 | 채널 필터 — 활성 채널(`channels`) 배열에 이 채널을 포함하는 정의만 조회 (mail, database 등) |
| template_channel | query | string | 아니오 | max 50 | 목록에 실을 템플릿을 한 채널로 좁힙니다. `channel` 과 달리 정의 행은 그대로 두고 각 행의 `templates` 만 좁힙니다. **지정하지 않으면 `templates` 키 자체가 응답에 없습니다** — 정의 하나당 채널 × 로케일만큼의 제목·본문이 실리는 것을 막기 위한 기본값이며, 전 채널이 필요하면 단건 조회를 이용하세요 |
| is_active | query | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| sort_by | query | string | 아니오 | `id`, `type`, `extension_type`, `is_active`, `created_at`, `updated_at` | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.notification_definition.index_validation_rules`).

**요청 예시**

```http
GET /api/admin/notification-definitions?search=%EC%98%88%EC%8B%9C%EA%B0%92&extension_type=core&extension_identifier=example-key&channel=%EC%98%88%EC%8B%9C%EA%B0%92&template_channel=%EC%98%88%EC%8B%9C%EA%B0%92&is_active=1&per_page=1&sort_by=id&sort_order=asc HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `1` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `1` | 기본 키 (내부 식별자) |
| type | string | `new_comment` | 알림 타입 (welcome, order_confirmed 등) |
| hook_prefix | string | `sirsoft-board` | 훅 접두사 (core.auth, sirsoft-ecommerce 등) |
| extension_type | string | `module` | 확장 타입: core, module, plugin |
| extension_identifier | string | `sirsoft-board` | 확장 식별자: core, sirsoft-board 등 |
| name | object | `{"ko":"새 댓글 알림","en":"New Comment Notification"}` | 다국어 이름 ({"ko": "회원가입 환영", "en": "Welcome"}) |
| description | object | `{"ko":"게시글에 새 댓글이 작성되면 게시글 작성자에게 발송","en":"Sent to post a…` | 다국어 설명 |
| variables | array | `[{"key":"name","description":"수신자 이름"},{"key":"app_name",…` | 사용 가능 변수 메타데이터 ([{key, description}]) |
| channels | array | `["mail","database"]` | 활성 채널 (["mail", "database"]) |
| hooks | array | `["sirsoft-board.comment.after_create"]` | 트리거 훅 목록 (["core.auth.after_register"]) |
| is_active | boolean | `true` | active 여부 |
| is_default | boolean | `true` | default 여부 |
| templates | array | `[{"id":1,"definition_id":1,"channel":"mail","subject":{"k…` | 채널별 알림 템플릿 목록 (templates 관계 로드 시 NotificationTemplateResource 배열, 미로드 시 null) |
| has_customized_templates | boolean | `false` | 기본값에서 수정된 템플릿이 하나라도 있는지. `template_channel` 로 좁혀도 **모든 채널**을 기준으로 판정하므로, 지금 보고 있지 않은 채널만 수정된 경우에도 true 다 (되돌리기 버튼 노출 조건) |
| created_at | string | `2026-07-30 18:45:11` | 생성 일시 |
| updated_at | string | `2026-07-30 18:45:11` | 최종 수정 일시 |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "알림 정의 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "number": 1,
                "id": 1,
                "type": "new_comment",
                "hook_prefix": "sirsoft-board",
                "extension_type": "module",
                "...": "(13개 키 생략, 총 18개)"
            },
            {
                "number": 2,
                "id": 2,
                "type": "reply_comment",
                "hook_prefix": "sirsoft-board",
                "extension_type": "module",
                "...": "(13개 키 생략, 총 18개)"
            },
            "... (총 23건 중 2건 표시)"
        ],
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 23,
            "from": 1,
            "...": "(2개 키 생략, 총 7개)"
        },
        "abilities": {
            "can_update": true
        }
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

**설명** 등록된 알림 정의 목록을 페이지네이션으로 조회합니다. 인증(`auth:sanctum`)과 `core.settings.read` 권한이 필요합니다. `search`, `extension_type`, `extension_identifier`, `channel`, `is_active` 로 필터링하고 `sort_by`/`sort_order` 로 정렬하며, 확장이 `core.notification_definition.index_validation_rules` 훅으로 필터를 추가할 수 있습니다. 관리자 알림 정의 관리 목록 화면을 렌더링할 때 사용합니다.


### GET /api/admin/notification-definitions/{definition}
<!-- @generated:start:api.admin.notification-definitions.show -->
- **라우트명**: `api.admin.notification-definitions.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationDefinitionController@show`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| definition | path | string | 예 | — | 대상 definition의 식별자 |

**요청 예시**

```http
GET /api/admin/notification-definitions/{definition} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| type | string | `new_comment` | 알림 타입 (welcome, order_confirmed 등) |
| hook_prefix | string | `sirsoft-board` | 훅 접두사 (core.auth, sirsoft-ecommerce 등) |
| extension_type | string | `module` | 확장 타입: core, module, plugin |
| extension_identifier | string | `sirsoft-board` | 확장 식별자: core, sirsoft-board 등 |
| name | object | `{"ko":"새 댓글 알림","en":"New Comment Notification"}` | 다국어 이름 ({"ko": "회원가입 환영", "en": "Welcome"}) |
| description | object | `{"ko":"게시글에 새 댓글이 작성되면 게시글 작성자에게 발송","en":"Sent to post a…` | 다국어 설명 |
| variables | array | `[{"key":"name","description":"수신자 이름"},{"key":"app_name",…` | 사용 가능 변수 메타데이터 ([{key, description}]) |
| channels | array | `["mail","database"]` | 활성 채널 (["mail", "database"]) |
| hooks | array | `["sirsoft-board.comment.after_create"]` | 트리거 훅 목록 (["core.auth.after_register"]) |
| is_active | boolean | `true` | active 여부 |
| is_default | boolean | `true` | default 여부 |
| templates | array | `[{"id":2,"definition_id":1,"channel":"database","subject"…` | 채널별 알림 템플릿 목록 (templates 관계 로드 시 NotificationTemplateResource 배열, 미로드 시 null) |
| created_at | string | `2026-07-30 18:45:11` | 생성 일시 |
| updated_at | string | `2026-07-30 18:45:11` | 최종 수정 일시 |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "알림 정의를 조회했습니다.",
    "data": {
        "id": 1,
        "type": "new_comment",
        "hook_prefix": "sirsoft-board",
        "extension_type": "module",
        "extension_identifier": "sirsoft-board",
        "name": {
            "ko": "새 댓글 알림",
            "en": "New Comment Notification"
        },
        "description": {
            "ko": "게시글에 새 댓글이 작성되면 게시글 작성자에게 발송",
            "en": "Sent to post author when a new comment is posted"
        },
        "variables": [
            {
                "key": "name",
                "description": "수신자 이름"
            },
            {
                "key": "app_name",
                "description": "사이트명"
            },
            {
                "key": "board_name",
                "description": "게시판 이름"
            },
            {
                "key": "post_title",
                "description": "게시글 제목"
            },
            {
                "key": "comment_author",
                "description": "댓글 작성자"
            },
            {
                "key": "comment_content",
                "description": "댓글 내용 (200자)"
            },
            {
                "key": "post_url",
                "description": "게시글 URL"
            },
            {
                "key": "site_url",
                "description": "사이트 URL"
            }
        ],
        "channels": [
            "mail",
            "database"
        ],
        "hooks": [
            "sirsoft-board.comment.after_create"
        ],
        "is_active": true,
        "is_default": true,
        "templates": [
            {
                "id": 2,
                "definition_id": 1,
                "channel": "database",
                "subject": {
                    "ko": "게시글에 새 댓글이 달렸습니다",
                    "en": "New comment on your post"
                },
                "body": {
                    "ko": "{comment_author}님이 '{board_name}' 게시글 '{post_title}'에 댓글을 남겼습니다.",
                    "en": "{comment_author} commented on your post '{post_title}' in '{board_name}'."
                },
                "click_url": "{post_url}",
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
            },
            {
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
        ],
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
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 단일 알림 정의의 상세 정보를 조회하며, 응답에 소속 템플릿(`templates`)을 함께 로드합니다. 인증(`auth:sanctum`)과 `core.settings.read` 권한이 필요합니다. `definition` 경로 파라미터로 대상을 지정하며, 정의 편집 화면 진입 시 채널별 템플릿을 포함한 전체 구성을 불러올 때 사용합니다.


### PUT /api/admin/notification-definitions/{definition}
<!-- @generated:start:api.admin.notification-definitions.update -->
- **라우트명**: `api.admin.notification-definitions.update`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationDefinitionController@update`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| definition | path | string | 예 | — | 대상 definition의 식별자 |
| channels | body | array | 아니오 | min 1 | 활성 채널 목록 — 이 정의가 발송에 사용할 채널 배열 (각 원소 최대 50자, mail·database 등). 지정 시 최소 1개 필요. 허용 채널은 `config('notification.default_channels')` 에 `core.notification.filter_available_channels` 훅으로 확장이 추가한 채널을 더해 런타임에 결정된다. 목록에 없는 채널은 거부되지만, 해당 정의에 이미 저장되어 있던 채널은 통과한다(채널 제공 확장을 비활성화해도 기존 레코드 수정이 막히지 않도록) |
| hooks | body | array | 아니오 | — | 트리거 훅 목록 — 이 알림을 발송시키는 훅 이름 배열 (각 원소 최대 255자, core.auth.after_register 등) |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.notification_definition.update_validation_rules`).

**요청 예시**

```http
PUT /api/admin/notification-definitions/{definition} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "channels": [
        "예시값"
    ],
    "hooks": [
        "예시값"
    ],
    "is_active": true
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드. 수정된 알림 정의를 `templates` 관계와 함께 반환합니다 (`NotificationDefinitionResource`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| type | string | `apidoc-sample.event` | 알림 타입 (welcome, order_confirmed 등) |
| hook_prefix | string | `core` | 훅 접두사 (core.auth, sirsoft-ecommerce 등) |
| extension_type | string | `core` | 이 정의를 소유한 확장의 타입 (core/module/plugin) |
| extension_identifier | string | `` | 이 정의를 소유한 확장의 식별자 (코어는 빈 문자열) |
| name | object | `{"ko":"API 문서 샘플 알림","en":"API Doc Sample Notification"}` | 다국어 이름 (로케일별 값 객체) |
| description | object | `{"ko":"문서 실측용 알림 정의","en":"Sample notification"}` | 다국어 설명 (로케일별 값 객체) |
| variables | array | `[]` | 사용 가능 변수 메타데이터 ([{key, description}]) |
| channels | array | `["database","mail"]` | 수정 후의 활성 채널 (["mail", "database"]) |
| hooks | array | `[]` | 수정 후의 트리거 훅 목록 (["core.auth.after_register"]) |
| is_active | boolean | `true` | active 여부 |
| is_default | boolean | `false` | default 여부 (사용자 수정 시 false) |
| templates | array | `[{"id":1,"definition_id":1,"channel":"mail", ...}]` | 채널별 알림 템플릿 목록 (`templates` 관계를 로드해 반환 — NotificationTemplateResource 배열) |
| created_at | string | `2026-07-08 10:41:24` | 생성 일시 |
| updated_at | string | `2026-07-08 12:14:43` | 최종 수정 일시 |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update/can_delete — 모두 `core.settings.update` 기준) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "알림 정의가 수정되었습니다.",
    "data": {
        "id": 1,
        "type": "apidoc-sample.event",
        "hook_prefix": "core",
        "extension_type": "core",
        "extension_identifier": "",
        "name": {
            "ko": "API 문서 샘플 알림",
            "en": "API Doc Sample Notification"
        },
        "description": {
            "ko": "문서 실측용 알림 정의",
            "en": "Sample notification"
        },
        "variables": [],
        "channels": [
            "database",
            "mail"
        ],
        "hooks": [],
        "is_active": true,
        "is_default": false,
        "templates": [
            {
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
                "is_active": true,
                "is_default": false,
                "user_overrides": null,
                "updated_by": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                "created_at": "2026-07-08 10:41:24",
                "updated_at": "2026-07-08 10:41:24",
                "abilities": {
                    "can_update": true,
                    "can_delete": true
                }
            }
        ],
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

**설명** 알림 정의의 활성 채널(`channels`), 트리거 훅(`hooks`), 활성 상태(`is_active`)를 수정합니다. 인증(`auth:sanctum`)과 `core.settings.update` 권한이 필요합니다. Service 계층에서 수정 후 템플릿을 다시 로드해 반환하며, 확장이 `core.notification_definition.update_validation_rules` 훅으로 추가 파라미터를 검증에 넣을 수 있습니다. 발송 채널 구성이나 훅 연결을 변경할 때 사용합니다.


### POST /api/admin/notification-definitions/{definition}/reset
<!-- @generated:start:api.admin.notification-definitions.reset -->
- **라우트명**: `api.admin.notification-definitions.reset`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationDefinitionController@reset`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| definition | path | string | 예 | — | 대상 definition의 식별자 |

**요청 예시**

```http
POST /api/admin/notification-definitions/{definition}/reset HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| type | string | `new_comment` | 알림 타입 (welcome, order_confirmed 등) |
| hook_prefix | string | `sirsoft-board` | 훅 접두사 (core.auth, sirsoft-ecommerce 등) |
| extension_type | string | `module` | 이 리소스를 소유한 확장의 타입 (core/module/plugin/template) |
| extension_identifier | string | `sirsoft-board` | 이 리소스를 소유한 확장의 식별자 |
| name | object | `{"ko":"새 댓글 알림","en":"New Comment Notification"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | object | `{"ko":"게시글에 새 댓글이 작성되면 게시글 작성자에게 발송","en":"Sent to post a…` | 설명 (다국어 필드는 로케일별 값 객체) |
| variables | array | `[{"key":"name","description":"수신자 이름"},{"key":"app_name",…` | 사용 가능 변수 메타데이터 ([{key, description}]) |
| channels | array | `["mail","database"]` | 활성 채널 (["mail", "database"]) |
| hooks | array | `["sirsoft-board.comment.after_create"]` | 트리거 훅 목록 (["core.auth.after_register"]) |
| is_active | boolean | `true` | active 여부 |
| is_default | boolean | `true` | default 여부 |
| templates | array | `[{"id":2,"definition_id":1,"channel":"database","subject"…` | 템플릿 목록 (각 원소 identifier/name 등 — 템플릿 관계 파생) |
| created_at | string | `2026-07-30 18:45:11` | 생성 일시 |
| updated_at | string | `2026-07-30 18:45:11` | 최종 수정 일시 |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "알림 정의의 모든 템플릿이 기본값으로 복원되었습니다.",
    "data": {
        "id": 1,
        "type": "new_comment",
        "hook_prefix": "sirsoft-board",
        "extension_type": "module",
        "extension_identifier": "sirsoft-board",
        "name": {
            "ko": "새 댓글 알림",
            "en": "New Comment Notification"
        },
        "description": {
            "ko": "게시글에 새 댓글이 작성되면 게시글 작성자에게 발송",
            "en": "Sent to post author when a new comment is posted"
        },
        "variables": [
            {
                "key": "name",
                "description": "수신자 이름"
            },
            {
                "key": "app_name",
                "description": "사이트명"
            },
            {
                "key": "board_name",
                "description": "게시판 이름"
            },
            {
                "key": "post_title",
                "description": "게시글 제목"
            },
            {
                "key": "comment_author",
                "description": "댓글 작성자"
            },
            {
                "key": "comment_content",
                "description": "댓글 내용 (200자)"
            },
            {
                "key": "post_url",
                "description": "게시글 URL"
            },
            {
                "key": "site_url",
                "description": "사이트 URL"
            }
        ],
        "channels": [
            "mail",
            "database"
        ],
        "hooks": [
            "sirsoft-board.comment.after_create"
        ],
        "is_active": true,
        "is_default": true,
        "templates": [
            {
                "id": 2,
                "definition_id": 1,
                "channel": "database",
                "subject": {
                    "ko": "게시글에 새 댓글이 달렸습니다",
                    "en": "New comment on your post"
                },
                "body": {
                    "ko": "{comment_author}님이 '{board_name}' 게시글 '{post_title}'에 댓글을 남겼습니다.",
                    "en": "{comment_author} commented on your post '{post_title}' in '{board_name}'."
                },
                "click_url": "{post_url}",
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
            },
            {
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
        ],
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

**설명** 알림 정의에 속한 모든 채널 템플릿을 기본값(default) 데이터로 일괄 복원하고, 정의 자체를 default 상태로 표시합니다. 인증(`auth:sanctum`)과 `core.settings.update` 권한이 필요합니다. 각 템플릿의 제목·본문을 기본값으로 덮어쓰는 파괴적 작업이므로 사용자 편집분이 사라집니다. 관리자가 커스터마이징한 알림 문구를 초기 상태로 되돌릴 때 사용합니다.


### PATCH /api/admin/notification-definitions/{definition}/toggle-active
<!-- @generated:start:api.admin.notification-definitions.toggle-active -->
- **라우트명**: `api.admin.notification-definitions.toggle-active`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationDefinitionController@toggleActive`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| definition | path | string | 예 | — | 대상 definition의 식별자 |

**요청 예시**

```http
PATCH /api/admin/notification-definitions/{definition}/toggle-active HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| type | string | `new_comment` | 알림 타입 (welcome, order_confirmed 등) |
| hook_prefix | string | `sirsoft-board` | 훅 접두사 (core.auth, sirsoft-ecommerce 등) |
| extension_type | string | `module` | 이 리소스를 소유한 확장의 타입 (core/module/plugin/template) |
| extension_identifier | string | `sirsoft-board` | 이 리소스를 소유한 확장의 식별자 |
| name | object | `{"ko":"새 댓글 알림","en":"New Comment Notification"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | object | `{"ko":"게시글에 새 댓글이 작성되면 게시글 작성자에게 발송","en":"Sent to post a…` | 설명 (다국어 필드는 로케일별 값 객체) |
| variables | array | `[{"key":"name","description":"수신자 이름"},{"key":"app_name",…` | 사용 가능 변수 메타데이터 ([{key, description}]) |
| channels | array | `["mail","database"]` | 활성 채널 (["mail", "database"]) |
| hooks | array | `["sirsoft-board.comment.after_create"]` | 트리거 훅 목록 (["core.auth.after_register"]) |
| is_active | boolean | `false` | active 여부 |
| is_default | boolean | `true` | default 여부 |
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
    "message": "알림 정의 활성 상태가 변경되었습니다.",
    "data": {
        "id": 1,
        "type": "new_comment",
        "hook_prefix": "sirsoft-board",
        "extension_type": "module",
        "extension_identifier": "sirsoft-board",
        "name": {
            "ko": "새 댓글 알림",
            "en": "New Comment Notification"
        },
        "description": {
            "ko": "게시글에 새 댓글이 작성되면 게시글 작성자에게 발송",
            "en": "Sent to post author when a new comment is posted"
        },
        "variables": [
            {
                "key": "name",
                "description": "수신자 이름"
            },
            {
                "key": "app_name",
                "description": "사이트명"
            },
            {
                "key": "board_name",
                "description": "게시판 이름"
            },
            {
                "key": "post_title",
                "description": "게시글 제목"
            },
            {
                "key": "comment_author",
                "description": "댓글 작성자"
            },
            {
                "key": "comment_content",
                "description": "댓글 내용 (200자)"
            },
            {
                "key": "post_url",
                "description": "게시글 URL"
            },
            {
                "key": "site_url",
                "description": "사이트 URL"
            }
        ],
        "channels": [
            "mail",
            "database"
        ],
        "hooks": [
            "sirsoft-board.comment.after_create"
        ],
        "is_active": false,
        "is_default": true,
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

**설명** 알림 정의의 활성 상태(`is_active`)를 현재 값의 반대로 토글합니다. 인증(`auth:sanctum`)과 `core.settings.update` 권한이 필요합니다. 비활성 정의는 해당 알림 발송이 중단되므로, 관리자가 목록에서 특정 알림을 켜거나 끄는 스위치 조작에 사용합니다.


