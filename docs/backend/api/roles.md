# Roles API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Roles 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/roles
<!-- @generated:start:api.admin.roles.index -->
- **라우트명**: `api.admin.roles.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\RoleController@index`
- **인증/권한**: `auth:sanctum` + `permission:core.permissions.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| search | query | string | 아니오 | max 255 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| is_active | query | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |

**요청 예시**

```http
GET /api/admin/roles?page=1&per_page=1&search=%EC%98%88%EC%8B%9C%EA%B0%92&is_active=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `23` | 기본 키 (내부 식별자) |
| identifier | string | `sirsoft-board.archive.manager` | 역할명 (예: admin, user, manager) |
| name | string | `아카이브 게시판 관리자` | 역할 이름 (다국어 JSON) |
| name_raw | object | `{"ko":"아카이브 게시판 관리자","en":"Archive Board Manager"}` | `name` 의 원본 값 (현재 로케일 미해석 원본 JSON/문자열) |
| description | string | `아카이브 게시판의 관리자 역할` | 역할 설명 (다국어 JSON) |
| description_raw | object | `{"ko":"아카이브 게시판의 관리자 역할","en":"Manager role for Archive b…` | `description` 의 원본 값 (현재 로케일 미해석 원본 JSON/문자열) |
| extension_type | string | `module` | 확장 소유 타입: core(코어), module(모듈), plugin(플러그인), NULL(사용자 정의) |
| extension_identifier | string | `sirsoft-board` | 확장 식별자 (예: core, sirsoft-board, sirsoft-payment) |
| extension_name | string | `게시판` | 이 리소스를 소유한 확장의 표시 이름 (manifest name) |
| is_deletable | boolean | `false` | deletable 여부 |
| is_active | boolean | `true` | active 여부 |
| users_count | integer | `1` | users 개수 (집계) |
| permission_ids | array | `[312,313,314,315,316,317,318,319,320,321,322,323,324,325,…` | permission 식별자 배열 (연관 리소스 참조) |
| permission_values | array | `[{"id":312,"scope_type":null},{"id":313,"scope_type":null…` | 할당된 각 권한의 id와 적용 범위만 담은 경량 목록 (원소 id/scope_type — 역할-권한 pivot 파생). scope_type: null=전체, role=역할 범위, self=본인 범위 |
| permissions | array | `[{"id":85,"parent_id":null,"identifier":"sirsoft-board","…` | 연결된 권한 목록 (id/identifier/name — 역할 경유 권한 관계 파생) |
| created_at | string | `2026-06-04 09:35:35` | 생성 일시 |
| updated_at | string | `2026-06-04 09:35:35` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true,"c…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "역할 정보를 성공적으로 가져왔습니다.",
    "data": {
        "data": [
            {
                "id": 6,
                "identifier": "manager",
                "name": "매니저",
                "name_raw": {
                    "ko": "매니저",
                    "en": "Manager"
                },
                "description": "콘텐츠 및 사용자 관리 권한을 가진 관리자입니다.",
                "description_raw": {
                    "ko": "콘텐츠 및 사용자 관리 권한을 가진 관리자입니다.",
                    "en": "Manager with content and user management permissions."
                },
                "extension_type": "core",
                "extension_identifier": "core",
                "extension_name": null,
                "is_deletable": false,
                "is_active": true,
                "users_count": 0,
                "permission_ids": [
                    117,
                    118,
                    119,
                    122,
                    123,
                    124,
                    149,
                    176,
                    177,
                    179,
                    182,
                    185,
                    186,
                    187
                ],
                "permission_values": [
                    {
                        "id": 117,
                        "scope_type": "self"
                    },
                    {
                        "id": 118,
                        "scope_type": null
                    },
                    {
                        "id": 119,
                        "scope_type": "self"
                    },
                    {
                        "id": 122,
                        "scope_type": null
                    },
                    {
                        "id": 123,
                        "scope_type": null
                    },
                    {
                        "id": 124,
                        "scope_type": null
                    },
                    {
                        "id": 149,
                        "scope_type": "self"
                    },
                    {
                        "id": 176,
                        "scope_type": null
                    },
                    {
                        "id": 177,
                        "scope_type": null
                    },
                    {
                        "id": 179,
                        "scope_type": null
                    },
                    {
                        "id": 182,
                        "scope_type": "self"
                    },
                    {
                        "id": 185,
                        "scope_type": null
                    },
                    {
                        "id": 186,
                        "scope_type": "self"
                    },
                    {
                        "id": 187,
                        "scope_type": "self"
                    }
                ],
                "permissions": [
                    {
                        "id": 115,
                        "parent_id": null,
                        "identifier": "core",
                        "name": "코어",
                        "name_raw": {
                            "ko": "코어",
                            "en": "Core"
                        },
                        "description": "코어 시스템 권한",
                        "description_raw": {
                            "ko": "코어 시스템 권한",
                            "en": "Core system permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 1,
                        "is_assigned": false,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 116,
                                "parent_id": 115,
                                "identifier": "core.users",
                                "name": "사용자 관리",
                                "name_raw": {
                                    "ko": "사용자 관리",
                                    "en": "User Management"
                                },
                                "description": "사용자 관리 권한",
                                "description_raw": {
                                    "ko": "사용자 관리 권한",
                                    "en": "User management permissions"
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 117,
                                        "parent_id": 116,
                                        "identifier": "core.users.read",
                                        "name": "사용자 조회",
                                        "name_raw": {
                                            "ko": "사용자 조회",
                                            "en": "View Users"
                                        },
                                        "description": "사용자 목록 및 상세 정보를 조회할 수 있습니다.",
                                        "description_raw": {
                                            "ko": "사용자 목록 및 상세 정보를 조회할 수 있습니다.",
                                            "en": "Can view user list and details."
                                        },
                                        "extension_type": "core",
                                        "extension_identifier": "core",
                                        "resource_route_key": "user",
                                        "owner_key": "id",
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": "self"
                                    },
                                    {
                                        "id": 118,
                                        "parent_id": 116,
                                        "identifier": "core.users.create",
                                        "name": "사용자 생성",
                                        "name_raw": {
                                            "ko": "사용자 생성",
                                            "en": "Create Users"
                                        },
                                        "description": "새로운 사용자를 생성할 수 있습니다.",
                                        "description_raw": {
                                            "ko": "새로운 사용자를 생성할 수 있습니다.",
                                            "en": "Can create new users."
                                        },
                                        "extension_type": "core",
                                        "extension_identifier": "core",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 2,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 119,
                                        "parent_id": 116,
                                        "identifier": "core.users.update",
                                        "name": "사용자 수정",
                                        "name_raw": {
                                            "ko": "사용자 수정",
                                            "en": "Update Users"
                                        },
                                        "description": "사용자 정보를 수정할 수 있습니다.",
                                        "description_raw": {
                                            "ko": "사용자 정보를 수정할 수 있습니다.",
                                            "en": "Can update user information."
                                        },
                                        "extension_type": "core",
                                        "extension_identifier": "core",
                                        "resource_route_key": "user",
                                        "owner_key": "id",
                                        "order": 3,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": "self"
                                    }
                                ]
                            },
                            {
                                "id": 121,
                                "parent_id": 115,
                                "identifier": "core.menus",
                                "name": "메뉴 관리",
                                "name_raw": {
                                    "ko": "메뉴 관리",
                                    "en": "Menu Management"
                                },
                                "description": "메뉴 관리 권한",
                                "description_raw": {
                                    "ko": "메뉴 관리 권한",
                                    "en": "Menu management permissions"
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 122,
                                        "parent_id": 121,
                                        "identifier": "core.menus.read",
                                        "name": "메뉴 조회",
                                        "name_raw": {
                                            "ko": "메뉴 조회",
                                            "en": "View Menus"
                                        },
                                        "description": "메뉴 목록을 조회할 수 있습니다.",
                                        "description_raw": {
                                            "ko": "메뉴 목록을 조회할 수 있습니다.",
                                            "en": "Can view menu list."
                                        },
                                        "extension_type": "core",
                                        "extension_identifier": "core",
                                        "resource_route_key": "menu",
                                        "owner_key": "created_by",
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 123,
                                        "parent_id": 121,
                                        "identifier": "core.menus.create",
                                        "name": "메뉴 생성",
                                        "name_raw": {
                                            "ko": "메뉴 생성",
                                            "en": "Create Menus"
                                        },
                                        "description": "새로운 메뉴를 생성할 수 있습니다.",
                                        "description_raw": {
                                            "ko": "새로운 메뉴를 생성할 수 있습니다.",
                                            "en": "Can create new menus."
                                        },
                                        "extension_type": "core",
                                        "extension_identifier": "core",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 2,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 124,
                                        "parent_id": 121,
                                        "identifier": "core.menus.update",
                                        "name": "메뉴 수정",
                                        "name_raw": {
                                            "ko": "메뉴 수정",
                                            "en": "Update Menus"
                                        },
                                        "description": "메뉴 정보를 수정할 수 있습니다.",
                                        "description_raw": {
                                            "ko": "메뉴 정보를 수정할 수 있습니다.",
                                            "en": "Can update menu information."
                                        },
                                        "extension_type": "core",
                                        "extension_identifier": "core",
                                        "resource_route_key": "menu",
                                        "owner_key": "created_by",
                                        "order": 3,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 148,
                                "parent_id": 115,
                                "identifier": "core.notification-logs",
                                "name": "알림 발송 이력",
                                "name_raw": {
                                    "ko": "알림 발송 이력",
                                    "en": "Notification Logs"
                                },
                                "description": "알림 발송 이력 관리 권한",
                                "description_raw": {
                                    "ko": "알림 발송 이력 관리 권한",
                                    "en": "Notification log management permissions"
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 7,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 149,
                                        "parent_id": 148,
                                        "identifier": "core.notification-logs.read",
                                        "name": "발송 이력 조회",
                                        "name_raw": {
                                            "ko": "발송 이력 조회",
                                            "en": "View Notification Logs"
                                        },
                                        "description": "알림 발송 이력을 조회할 수 있습니다.",
                                        "description_raw": {
                                            "ko": "알림 발송 이력을 조회할 수 있습니다.",
                                            "en": "Can view notification logs."
                                        },
                                        "extension_type": "core",
                                        "extension_identifier": "core",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": "self"
                                    }
                                ]
                            },
                            {
                                "id": 175,
                                "parent_id": 115,
                                "identifier": "core.dashboard",
                                "name": "대시보드",
                                "name_raw": {
                                    "ko": "대시보드",
                                    "en": "Dashboard"
                                },
                                "description": "대시보드 접근 권한",
                                "description_raw": {
                                    "ko": "대시보드 접근 권한",
                                    "en": "Dashboard access permissions"
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 9,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 176,
                                        "parent_id": 175,
                                        "identifier": "core.dashboard.read",
                                        "name": "대시보드 조회",
                                        "name_raw": {
                                            "ko": "대시보드 조회",
                                            "en": "View Dashboard"
                                        },
                                        "description": "대시보드 통계 및 정보를 조회할 수 있습니다.",
                                        "description_raw": {
                                            "ko": "대시보드 통계 및 정보를 조회할 수 있습니다.",
                                            "en": "Can view dashboard statistics and information."
                                        },
                                        "extension_type": "core",
                                        "extension_identifier": "core",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 177,
                                        "parent_id": 175,
                                        "identifier": "core.dashboard.system-status",
                                        "name": "시스템 상태",
                                        "name_raw": {
                                            "ko": "시스템 상태",
                                            "en": "System Status"
                                        },
                                        "description": "시스템 상태 정보를 조회할 수 있습니다.",
                                        "description_raw": {
                                            "ko": "시스템 상태 정보를 조회할 수 있습니다.",
                                            "en": "Can view system status information."
                                        },
                                        "extension_type": "core",
                                        "extension_identifier": "core",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 2,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 179,
                                        "parent_id": 175,
                                        "identifier": "core.dashboard.activities",
                                        "name": "최근 활동",
                                        "name_raw": {
                                            "ko": "최근 활동",
                                            "en": "Recent Activities"
                                        },
                                        "description": "최근 활동 이력을 조회할 수 있습니다.",
                                        "description_raw": {
                                            "ko": "최근 활동 이력을 조회할 수 있습니다.",
                                            "en": "Can view recent activity history."
                                        },
                                        "extension_type": "core",
                                        "extension_identifier": "core",
                                        "resource_route_key": "activityLog",
                                        "owner_key": "user_id",
                                        "order": 4,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 181,
                                "parent_id": 115,
                                "identifier": "core.activities",
                                "name": "활동 로그",
                                "name_raw": {
                                    "ko": "활동 로그",
                                    "en": "Activity Logs"
                                },
                                "description": "활동 로그 조회 권한",
                                "description_raw": {
                                    "ko": "활동 로그 조회 권한",
                                    "en": "Activity log access permissions"
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 10,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 182,
                                        "parent_id": 181,
                                        "identifier": "core.activities.read",
                                        "name": "활동 로그 조회",
                                        "name_raw": {
                                            "ko": "활동 로그 조회",
                                            "en": "View Activity Logs"
                                        },
                                        "description": "활동 로그를 조회할 수 있습니다.",
                                        "description_raw": {
                                            "ko": "활동 로그를 조회할 수 있습니다.",
                                            "en": "Can view activity logs."
                                        },
                                        "extension_type": "core",
                                        "extension_identifier": "core",
                                        "resource_route_key": "activityLog",
                                        "owner_key": "user_id",
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": "self"
                                    }
                                ]
                            },
                            {
                                "id": 184,
                                "parent_id": 115,
                                "identifier": "core.attachments",
                                "name": "첨부파일 관리",
                                "name_raw": {
                                    "ko": "첨부파일 관리",
                                    "en": "Attachment Management"
                                },
                                "description": "첨부파일 관리 권한",
                                "description_raw": {
                                    "ko": "첨부파일 관리 권한",
                                    "en": "Attachment management permissions"
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 11,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 185,
                                        "parent_id": 184,
                                        "identifier": "core.attachments.create",
                                        "name": "첨부파일 업로드",
                                        "name_raw": {
                                            "ko": "첨부파일 업로드",
                                            "en": "Upload Attachments"
                                        },
                                        "description": "첨부파일을 업로드할 수 있습니다.",
                                        "description_raw": {
                                            "ko": "첨부파일을 업로드할 수 있습니다.",
                                            "en": "Can upload attachments."
                                        },
                                        "extension_type": "core",
                                        "extension_identifier": "core",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 186,
                                        "parent_id": 184,
                                        "identifier": "core.attachments.update",
                                        "name": "첨부파일 수정",
                                        "name_raw": {
                                            "ko": "첨부파일 수정",
                                            "en": "Update Attachments"
                                        },
                                        "description": "첨부파일 정보 및 순서를 수정할 수 있습니다.",
                                        "description_raw": {
                                            "ko": "첨부파일 정보 및 순서를 수정할 수 있습니다.",
                                            "en": "Can update attachment information and order."
                                        },
                                        "extension_type": "core",
                                        "extension_identifier": "core",
                                        "resource_route_key": "attachment",
                                        "owner_key": "created_by",
                                        "order": 2,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": "self"
                                    },
                                    {
                                        "id": 187,
                                        "parent_id": 184,
                                        "identifier": "core.attachments.delete",
                                        "name": "첨부파일 삭제",
                                        "name_raw": {
                                            "ko": "첨부파일 삭제",
                                            "en": "Delete Attachments"
                                        },
                                        "description": "첨부파일을 삭제할 수 있습니다.",
                                        "description_raw": {
                                            "ko": "첨부파일을 삭제할 수 있습니다.",
                                            "en": "Can delete attachments."
                                        },
                                        "extension_type": "core",
                                        "extension_identifier": "core",
                                        "resource_route_key": "attachment",
                                        "owner_key": "created_by",
                                        "order": 3,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": "self"
                                    }
                                ]
                            }
                        ]
                    }
                ],
                "created_at": "2026-07-08 10:47:51",
                "updated_at": "2026-07-08 10:47:51",
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true,
                    "can_toggle_status": true
                }
            },
            {
                "id": 5,
                "identifier": "sirsoft-ecommerce.manager",
                "name": "이커머스 관리자",
                "name_raw": {
                    "ko": "이커머스 관리자",
                    "en": "Ecommerce Manager"
                },
                "description": "이커머스 관리 권한을 가진 역할",
                "description_raw": {
                    "ko": "이커머스 관리 권한을 가진 역할",
                    "en": "Role with ecommerce management permissions"
                },
                "extension_type": "module",
                "extension_identifier": "sirsoft-ecommerce",
                "extension_name": "이커머스",
                "is_deletable": false,
                "is_active": true,
                "users_count": 0,
                "permission_ids": [
                    23,
                    24,
                    25,
                    26,
                    28,
                    29,
                    31,
                    32,
                    33,
                    34,
                    36,
                    37,
                    38,
                    39,
                    41,
                    42,
                    43,
                    44,
                    46,
                    47,
                    48,
                    49,
                    51,
                    52,
                    54,
                    55,
                    56,
                    57,
                    59,
                    60,
                    61,
                    62,
                    64,
                    65,
                    66,
                    67,
                    72,
                    73,
                    74,
                    76,
                    77,
                    79,
                    81,
                    83,
                    84,
                    85,
                    87,
                    89,
                    90,
                    92,
                    94
                ],
                "permission_values": [
                    {
                        "id": 23,
                        "scope_type": null
                    },
                    {
                        "id": 24,
                        "scope_type": null
                    },
                    {
                        "id": 25,
                        "scope_type": null
                    },
                    {
                        "id": 26,
                        "scope_type": null
                    },
                    {
                        "id": 28,
                        "scope_type": null
                    },
                    {
                        "id": 29,
                        "scope_type": null
                    },
                    {
                        "id": 31,
                        "scope_type": null
                    },
                    {
                        "id": 32,
                        "scope_type": null
                    },
                    {
                        "id": 33,
                        "scope_type": null
                    },
                    {
                        "id": 34,
                        "scope_type": null
                    },
                    {
                        "id": 36,
                        "scope_type": null
                    },
                    {
                        "id": 37,
                        "scope_type": null
                    },
                    {
                        "id": 38,
                        "scope_type": null
                    },
                    {
                        "id": 39,
                        "scope_type": null
                    },
                    {
                        "id": 41,
                        "scope_type": null
                    },
                    {
                        "id": 42,
                        "scope_type": null
                    },
                    {
                        "id": 43,
                        "scope_type": null
                    },
                    {
                        "id": 44,
                        "scope_type": null
                    },
                    {
                        "id": 46,
                        "scope_type": null
                    },
                    {
                        "id": 47,
                        "scope_type": null
                    },
                    {
                        "id": 48,
                        "scope_type": null
                    },
                    {
                        "id": 49,
                        "scope_type": null
                    },
                    {
                        "id": 51,
                        "scope_type": null
                    },
                    {
                        "id": 52,
                        "scope_type": null
                    },
                    {
                        "id": 54,
                        "scope_type": null
                    },
                    {
                        "id": 55,
                        "scope_type": null
                    },
                    {
                        "id": 56,
                        "scope_type": null
                    },
                    {
                        "id": 57,
                        "scope_type": null
                    },
                    {
                        "id": 59,
                        "scope_type": null
                    },
                    {
                        "id": 60,
                        "scope_type": null
                    },
                    {
                        "id": 61,
                        "scope_type": null
                    },
                    {
                        "id": 62,
                        "scope_type": null
                    },
                    {
                        "id": 64,
                        "scope_type": null
                    },
                    {
                        "id": 65,
                        "scope_type": null
                    },
                    {
                        "id": 66,
                        "scope_type": null
                    },
                    {
                        "id": 67,
                        "scope_type": null
                    },
                    {
                        "id": 72,
                        "scope_type": null
                    },
                    {
                        "id": 73,
                        "scope_type": null
                    },
                    {
                        "id": 74,
                        "scope_type": null
                    },
                    {
                        "id": 76,
                        "scope_type": null
                    },
                    {
                        "id": 77,
                        "scope_type": null
                    },
                    {
                        "id": 79,
                        "scope_type": null
                    },
                    {
                        "id": 81,
                        "scope_type": null
                    },
                    {
                        "id": 83,
                        "scope_type": null
                    },
                    {
                        "id": 84,
                        "scope_type": null
                    },
                    {
                        "id": 85,
                        "scope_type": null
                    },
                    {
                        "id": 87,
                        "scope_type": null
                    },
                    {
                        "id": 89,
                        "scope_type": null
                    },
                    {
                        "id": 90,
                        "scope_type": null
                    },
                    {
                        "id": 92,
                        "scope_type": null
                    },
                    {
                        "id": 94,
                        "scope_type": null
                    }
                ],
                "permissions": [
                    {
                        "id": 21,
                        "parent_id": null,
                        "identifier": "sirsoft-ecommerce",
                        "name": "이커머스",
                        "name_raw": {
                            "ko": "이커머스",
                            "en": "Ecommerce"
                        },
                        "description": "이커머스 모듈 권한",
                        "description_raw": {
                            "ko": "이커머스 모듈 권한",
                            "en": "Ecommerce module permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 100,
                        "is_assigned": false,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 22,
                                "parent_id": 21,
                                "identifier": "sirsoft-ecommerce.products",
                                "name": "상품 관리",
                                "name_raw": {
                                    "ko": "상품 관리",
                                    "en": "Product Management"
                                },
                                "description": "상품 관리 권한",
                                "description_raw": {
                                    "ko": "상품 관리 권한",
                                    "en": "Product management permissions"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 23,
                                        "parent_id": 22,
                                        "identifier": "sirsoft-ecommerce.products.read",
                                        "name": "상품 조회",
                                        "name_raw": {
                                            "ko": "상품 조회",
                                            "en": "Read Products"
                                        },
                                        "description": "상품 목록 및 상세 조회",
                                        "description_raw": {
                                            "ko": "상품 목록 및 상세 조회",
                                            "en": "Read product list and details"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": "product",
                                        "owner_key": "created_by",
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 24,
                                        "parent_id": 22,
                                        "identifier": "sirsoft-ecommerce.products.create",
                                        "name": "상품 생성",
                                        "name_raw": {
                                            "ko": "상품 생성",
                                            "en": "Create Product"
                                        },
                                        "description": "새 상품 등록",
                                        "description_raw": {
                                            "ko": "새 상품 등록",
                                            "en": "Create new product"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 2,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 25,
                                        "parent_id": 22,
                                        "identifier": "sirsoft-ecommerce.products.update",
                                        "name": "상품 수정",
                                        "name_raw": {
                                            "ko": "상품 수정",
                                            "en": "Update Product"
                                        },
                                        "description": "상품 정보 수정",
                                        "description_raw": {
                                            "ko": "상품 정보 수정",
                                            "en": "Update product information"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": "product",
                                        "owner_key": "created_by",
                                        "order": 3,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 26,
                                        "parent_id": 22,
                                        "identifier": "sirsoft-ecommerce.products.delete",
                                        "name": "상품 삭제",
                                        "name_raw": {
                                            "ko": "상품 삭제",
                                            "en": "Delete Product"
                                        },
                                        "description": "상품 삭제",
                                        "description_raw": {
                                            "ko": "상품 삭제",
                                            "en": "Delete product"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": "product",
                                        "owner_key": "created_by",
                                        "order": 4,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 27,
                                "parent_id": 21,
                                "identifier": "sirsoft-ecommerce.orders",
                                "name": "주문 관리",
                                "name_raw": {
                                    "ko": "주문 관리",
                                    "en": "Order Management"
                                },
                                "description": "주문 관리 권한",
                                "description_raw": {
                                    "ko": "주문 관리 권한",
                                    "en": "Order management permissions"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 28,
                                        "parent_id": 27,
                                        "identifier": "sirsoft-ecommerce.orders.read",
                                        "name": "주문 조회",
                                        "name_raw": {
                                            "ko": "주문 조회",
                                            "en": "Read Orders"
                                        },
                                        "description": "주문 목록 및 상세 조회",
                                        "description_raw": {
                                            "ko": "주문 목록 및 상세 조회",
                                            "en": "Read order list and details"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": "order",
                                        "owner_key": "user_id",
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 29,
                                        "parent_id": 27,
                                        "identifier": "sirsoft-ecommerce.orders.update",
                                        "name": "주문 수정",
                                        "name_raw": {
                                            "ko": "주문 수정",
                                            "en": "Update Order"
                                        },
                                        "description": "주문 상태 변경",
                                        "description_raw": {
                                            "ko": "주문 상태 변경",
                                            "en": "Update order status"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": "order",
                                        "owner_key": "user_id",
                                        "order": 2,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 30,
                                "parent_id": 21,
                                "identifier": "sirsoft-ecommerce.categories",
                                "name": "카테고리 관리",
                                "name_raw": {
                                    "ko": "카테고리 관리",
                                    "en": "Category Management"
                                },
                                "description": "카테고리 관리 권한",
                                "description_raw": {
                                    "ko": "카테고리 관리 권한",
                                    "en": "Category management permissions"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 3,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 31,
                                        "parent_id": 30,
                                        "identifier": "sirsoft-ecommerce.categories.read",
                                        "name": "카테고리 조회",
                                        "name_raw": {
                                            "ko": "카테고리 조회",
                                            "en": "Read Categories"
                                        },
                                        "description": "카테고리 목록 및 상세 조회",
                                        "description_raw": {
                                            "ko": "카테고리 목록 및 상세 조회",
                                            "en": "Read category list and details"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 32,
                                        "parent_id": 30,
                                        "identifier": "sirsoft-ecommerce.categories.create",
                                        "name": "카테고리 생성",
                                        "name_raw": {
                                            "ko": "카테고리 생성",
                                            "en": "Create Category"
                                        },
                                        "description": "카테고리 생성",
                                        "description_raw": {
                                            "ko": "카테고리 생성",
                                            "en": "Create category"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 2,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 33,
                                        "parent_id": 30,
                                        "identifier": "sirsoft-ecommerce.categories.update",
                                        "name": "카테고리 수정",
                                        "name_raw": {
                                            "ko": "카테고리 수정",
                                            "en": "Update Category"
                                        },
                                        "description": "카테고리 수정",
                                        "description_raw": {
                                            "ko": "카테고리 수정",
                                            "en": "Update category"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 3,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 34,
                                        "parent_id": 30,
                                        "identifier": "sirsoft-ecommerce.categories.delete",
                                        "name": "카테고리 삭제",
                                        "name_raw": {
                                            "ko": "카테고리 삭제",
                                            "en": "Delete Category"
                                        },
                                        "description": "카테고리 삭제",
                                        "description_raw": {
                                            "ko": "카테고리 삭제",
                                            "en": "Delete category"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 4,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 35,
                                "parent_id": 21,
                                "identifier": "sirsoft-ecommerce.brands",
                                "name": "브랜드 관리",
                                "name_raw": {
                                    "ko": "브랜드 관리",
                                    "en": "Brand Management"
                                },
                                "description": "브랜드 관리 권한",
                                "description_raw": {
                                    "ko": "브랜드 관리 권한",
                                    "en": "Brand management permissions"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 4,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 36,
                                        "parent_id": 35,
                                        "identifier": "sirsoft-ecommerce.brands.read",
                                        "name": "브랜드 조회",
                                        "name_raw": {
                                            "ko": "브랜드 조회",
                                            "en": "Read Brands"
                                        },
                                        "description": "브랜드 목록 및 상세 조회",
                                        "description_raw": {
                                            "ko": "브랜드 목록 및 상세 조회",
                                            "en": "Read brand list and details"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": "brand",
                                        "owner_key": "created_by",
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 37,
                                        "parent_id": 35,
                                        "identifier": "sirsoft-ecommerce.brands.create",
                                        "name": "브랜드 생성",
                                        "name_raw": {
                                            "ko": "브랜드 생성",
                                            "en": "Create Brand"
                                        },
                                        "description": "브랜드 생성",
                                        "description_raw": {
                                            "ko": "브랜드 생성",
                                            "en": "Create brand"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 2,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 38,
                                        "parent_id": 35,
                                        "identifier": "sirsoft-ecommerce.brands.update",
                                        "name": "브랜드 수정",
                                        "name_raw": {
                                            "ko": "브랜드 수정",
                                            "en": "Update Brand"
                                        },
                                        "description": "브랜드 수정",
                                        "description_raw": {
                                            "ko": "브랜드 수정",
                                            "en": "Update brand"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": "brand",
                                        "owner_key": "created_by",
                                        "order": 3,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 39,
                                        "parent_id": 35,
                                        "identifier": "sirsoft-ecommerce.brands.delete",
                                        "name": "브랜드 삭제",
                                        "name_raw": {
                                            "ko": "브랜드 삭제",
                                            "en": "Delete Brand"
                                        },
                                        "description": "브랜드 삭제",
                                        "description_raw": {
                                            "ko": "브랜드 삭제",
                                            "en": "Delete brand"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": "brand",
                                        "owner_key": "created_by",
                                        "order": 4,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 40,
                                "parent_id": 21,
                                "identifier": "sirsoft-ecommerce.product-notice-templates",
                                "name": "상품정보제공고시 관리",
                                "name_raw": {
                                    "ko": "상품정보제공고시 관리",
                                    "en": "Product Notice Template Management"
                                },
                                "description": "상품정보제공고시 템플릿 관리 권한",
                                "description_raw": {
                                    "ko": "상품정보제공고시 템플릿 관리 권한",
                                    "en": "Product notice template management permissions"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 5,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 41,
                                        "parent_id": 40,
                                        "identifier": "sirsoft-ecommerce.product-notice-templates.read",
                                        "name": "조회",
                                        "name_raw": {
                                            "ko": "조회",
                                            "en": "Read"
                                        },
                                        "description": "상품정보제공고시 조회",
                                        "description_raw": {
                                            "ko": "상품정보제공고시 조회",
                                            "en": "Read product notice templates"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 42,
                                        "parent_id": 40,
                                        "identifier": "sirsoft-ecommerce.product-notice-templates.create",
                                        "name": "생성",
                                        "name_raw": {
                                            "ko": "생성",
                                            "en": "Create"
                                        },
                                        "description": "상품정보제공고시 생성",
                                        "description_raw": {
                                            "ko": "상품정보제공고시 생성",
                                            "en": "Create product notice template"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 2,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 43,
                                        "parent_id": 40,
                                        "identifier": "sirsoft-ecommerce.product-notice-templates.update",
                                        "name": "수정",
                                        "name_raw": {
                                            "ko": "수정",
                                            "en": "Update"
                                        },
                                        "description": "상품정보제공고시 수정",
                                        "description_raw": {
                                            "ko": "상품정보제공고시 수정",
                                            "en": "Update product notice template"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 3,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 44,
                                        "parent_id": 40,
                                        "identifier": "sirsoft-ecommerce.product-notice-templates.delete",
                                        "name": "삭제",
                                        "name_raw": {
                                            "ko": "삭제",
                                            "en": "Delete"
                                        },
                                        "description": "상품정보제공고시 삭제",
                                        "description_raw": {
                                            "ko": "상품정보제공고시 삭제",
                                            "en": "Delete product notice template"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 4,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 45,
                                "parent_id": 21,
                                "identifier": "sirsoft-ecommerce.product-common-infos",
                                "name": "공통정보 관리",
                                "name_raw": {
                                    "ko": "공통정보 관리",
                                    "en": "Product Common Info Management"
                                },
                                "description": "상품 공통정보 관리 권한",
                                "description_raw": {
                                    "ko": "상품 공통정보 관리 권한",
                                    "en": "Product common info management permissions"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 6,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 46,
                                        "parent_id": 45,
                                        "identifier": "sirsoft-ecommerce.product-common-infos.read",
                                        "name": "조회",
                                        "name_raw": {
                                            "ko": "조회",
                                            "en": "Read"
                                        },
                                        "description": "공통정보 조회",
                                        "description_raw": {
                                            "ko": "공통정보 조회",
                                            "en": "Read product common info"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 47,
                                        "parent_id": 45,
                                        "identifier": "sirsoft-ecommerce.product-common-infos.create",
                                        "name": "생성",
                                        "name_raw": {
                                            "ko": "생성",
                                            "en": "Create"
                                        },
                                        "description": "공통정보 생성",
                                        "description_raw": {
                                            "ko": "공통정보 생성",
                                            "en": "Create product common info"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 2,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 48,
                                        "parent_id": 45,
                                        "identifier": "sirsoft-ecommerce.product-common-infos.update",
                                        "name": "수정",
                                        "name_raw": {
                                            "ko": "수정",
                                            "en": "Update"
                                        },
                                        "description": "공통정보 수정",
                                        "description_raw": {
                                            "ko": "공통정보 수정",
                                            "en": "Update product common info"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 3,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 49,
                                        "parent_id": 45,
                                        "identifier": "sirsoft-ecommerce.product-common-infos.delete",
                                        "name": "삭제",
                                        "name_raw": {
                                            "ko": "삭제",
                                            "en": "Delete"
                                        },
                                        "description": "공통정보 삭제",
                                        "description_raw": {
                                            "ko": "공통정보 삭제",
                                            "en": "Delete product common info"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 4,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 50,
                                "parent_id": 21,
                                "identifier": "sirsoft-ecommerce.settings",
                                "name": "환경설정",
                                "name_raw": {
                                    "ko": "환경설정",
                                    "en": "Settings"
                                },
                                "description": "이커머스 환경설정 권한",
                                "description_raw": {
                                    "ko": "이커머스 환경설정 권한",
                                    "en": "Ecommerce settings permissions"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 7,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 51,
                                        "parent_id": 50,
                                        "identifier": "sirsoft-ecommerce.settings.read",
                                        "name": "환경설정 조회",
                                        "name_raw": {
                                            "ko": "환경설정 조회",
                                            "en": "View Settings"
                                        },
                                        "description": "이커머스 환경설정 조회",
                                        "description_raw": {
                                            "ko": "이커머스 환경설정 조회",
                                            "en": "View ecommerce settings"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 52,
                                        "parent_id": 50,
                                        "identifier": "sirsoft-ecommerce.settings.update",
                                        "name": "환경설정 수정",
                                        "name_raw": {
                                            "ko": "환경설정 수정",
                                            "en": "Update Settings"
                                        },
                                        "description": "이커머스 환경설정 수정",
                                        "description_raw": {
                                            "ko": "이커머스 환경설정 수정",
                                            "en": "Update ecommerce settings"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 2,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 53,
                                "parent_id": 21,
                                "identifier": "sirsoft-ecommerce.promotion-coupon",
                                "name": "쿠폰 관리",
                                "name_raw": {
                                    "ko": "쿠폰 관리",
                                    "en": "Coupon Management"
                                },
                                "description": "쿠폰 관리 권한",
                                "description_raw": {
                                    "ko": "쿠폰 관리 권한",
                                    "en": "Coupon management permissions"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 8,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 54,
                                        "parent_id": 53,
                                        "identifier": "sirsoft-ecommerce.promotion-coupon.read",
                                        "name": "쿠폰 조회",
                                        "name_raw": {
                                            "ko": "쿠폰 조회",
                                            "en": "Read Coupons"
                                        },
                                        "description": "쿠폰 목록 및 상세 조회",
                                        "description_raw": {
                                            "ko": "쿠폰 목록 및 상세 조회",
                                            "en": "Read coupon list and details"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": "coupon",
                                        "owner_key": "created_by",
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 55,
                                        "parent_id": 53,
                                        "identifier": "sirsoft-ecommerce.promotion-coupon.create",
                                        "name": "쿠폰 생성",
                                        "name_raw": {
                                            "ko": "쿠폰 생성",
                                            "en": "Create Coupon"
                                        },
                                        "description": "새 쿠폰 등록",
                                        "description_raw": {
                                            "ko": "새 쿠폰 등록",
                                            "en": "Create new coupon"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 2,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 56,
                                        "parent_id": 53,
                                        "identifier": "sirsoft-ecommerce.promotion-coupon.update",
                                        "name": "쿠폰 수정",
                                        "name_raw": {
                                            "ko": "쿠폰 수정",
                                            "en": "Update Coupon"
                                        },
                                        "description": "쿠폰 정보 수정",
                                        "description_raw": {
                                            "ko": "쿠폰 정보 수정",
                                            "en": "Update coupon information"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": "coupon",
                                        "owner_key": "created_by",
                                        "order": 3,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 57,
                                        "parent_id": 53,
                                        "identifier": "sirsoft-ecommerce.promotion-coupon.delete",
                                        "name": "쿠폰 삭제",
                                        "name_raw": {
                                            "ko": "쿠폰 삭제",
                                            "en": "Delete Coupon"
                                        },
                                        "description": "쿠폰 삭제",
                                        "description_raw": {
                                            "ko": "쿠폰 삭제",
                                            "en": "Delete coupon"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": "coupon",
                                        "owner_key": "created_by",
                                        "order": 4,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 58,
                                "parent_id": 21,
                                "identifier": "sirsoft-ecommerce.shipping-policies",
                                "name": "배송정책 관리",
                                "name_raw": {
                                    "ko": "배송정책 관리",
                                    "en": "Shipping Policy Management"
                                },
                                "description": "배송정책 관리 권한",
                                "description_raw": {
                                    "ko": "배송정책 관리 권한",
                                    "en": "Shipping policy management permissions"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 9,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 59,
                                        "parent_id": 58,
                                        "identifier": "sirsoft-ecommerce.shipping-policies.read",
                                        "name": "배송정책 조회",
                                        "name_raw": {
                                            "ko": "배송정책 조회",
                                            "en": "Read Shipping Policies"
                                        },
                                        "description": "배송정책 목록 및 상세 조회",
                                        "description_raw": {
                                            "ko": "배송정책 목록 및 상세 조회",
                                            "en": "Read shipping policy list and details"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": "shippingPolicy",
                                        "owner_key": "created_by",
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 60,
                                        "parent_id": 58,
                                        "identifier": "sirsoft-ecommerce.shipping-policies.create",
                                        "name": "배송정책 생성",
                                        "name_raw": {
                                            "ko": "배송정책 생성",
                                            "en": "Create Shipping Policy"
                                        },
                                        "description": "새 배송정책 등록",
                                        "description_raw": {
                                            "ko": "새 배송정책 등록",
                                            "en": "Create new shipping policy"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 2,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 61,
                                        "parent_id": 58,
                                        "identifier": "sirsoft-ecommerce.shipping-policies.update",
                                        "name": "배송정책 수정",
                                        "name_raw": {
                                            "ko": "배송정책 수정",
                                            "en": "Update Shipping Policy"
                                        },
                                        "description": "배송정책 정보 수정",
                                        "description_raw": {
                                            "ko": "배송정책 정보 수정",
                                            "en": "Update shipping policy information"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": "shippingPolicy",
                                        "owner_key": "created_by",
                                        "order": 3,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 62,
                                        "parent_id": 58,
                                        "identifier": "sirsoft-ecommerce.shipping-policies.delete",
                                        "name": "배송정책 삭제",
                                        "name_raw": {
                                            "ko": "배송정책 삭제",
                                            "en": "Delete Shipping Policy"
                                        },
                                        "description": "배송정책 삭제",
                                        "description_raw": {
                                            "ko": "배송정책 삭제",
                                            "en": "Delete shipping policy"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": "shippingPolicy",
                                        "owner_key": "created_by",
                                        "order": 4,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 63,
                                "parent_id": 21,
                                "identifier": "sirsoft-ecommerce.product-labels",
                                "name": "상품 라벨 관리",
                                "name_raw": {
                                    "ko": "상품 라벨 관리",
                                    "en": "Product Label Management"
                                },
                                "description": "상품 라벨 관리 권한",
                                "description_raw": {
                                    "ko": "상품 라벨 관리 권한",
                                    "en": "Product label management permissions"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 10,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 64,
                                        "parent_id": 63,
                                        "identifier": "sirsoft-ecommerce.product-labels.read",
                                        "name": "상품 라벨 조회",
                                        "name_raw": {
                                            "ko": "상품 라벨 조회",
                                            "en": "Read Product Labels"
                                        },
                                        "description": "상품 라벨 목록 및 상세 조회",
                                        "description_raw": {
                                            "ko": "상품 라벨 목록 및 상세 조회",
                                            "en": "Read product label list and details"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 65,
                                        "parent_id": 63,
                                        "identifier": "sirsoft-ecommerce.product-labels.create",
                                        "name": "상품 라벨 생성",
                                        "name_raw": {
                                            "ko": "상품 라벨 생성",
                                            "en": "Create Product Label"
                                        },
                                        "description": "새 상품 라벨 등록",
                                        "description_raw": {
                                            "ko": "새 상품 라벨 등록",
                                            "en": "Create new product label"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 2,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 66,
                                        "parent_id": 63,
                                        "identifier": "sirsoft-ecommerce.product-labels.update",
                                        "name": "상품 라벨 수정",
                                        "name_raw": {
                                            "ko": "상품 라벨 수정",
                                            "en": "Update Product Label"
                                        },
                                        "description": "상품 라벨 정보 수정",
                                        "description_raw": {
                                            "ko": "상품 라벨 정보 수정",
                                            "en": "Update product label information"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 3,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 67,
                                        "parent_id": 63,
                                        "identifier": "sirsoft-ecommerce.product-labels.delete",
                                        "name": "상품 라벨 삭제",
                                        "name_raw": {
                                            "ko": "상품 라벨 삭제",
                                            "en": "Delete Product Label"
                                        },
                                        "description": "상품 라벨 삭제",
                                        "description_raw": {
                                            "ko": "상품 라벨 삭제",
                                            "en": "Delete product label"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 4,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 71,
                                "parent_id": 21,
                                "identifier": "sirsoft-ecommerce.reviews",
                                "name": "리뷰 관리",
                                "name_raw": {
                                    "ko": "리뷰 관리",
                                    "en": "Review Management"
                                },
                                "description": "상품 리뷰 관리 권한",
                                "description_raw": {
                                    "ko": "상품 리뷰 관리 권한",
                                    "en": "Product review management permissions"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 12,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 72,
                                        "parent_id": 71,
                                        "identifier": "sirsoft-ecommerce.reviews.read",
                                        "name": "리뷰 조회",
                                        "name_raw": {
                                            "ko": "리뷰 조회",
                                            "en": "Read Reviews"
                                        },
                                        "description": "리뷰 목록 및 상세 조회",
                                        "description_raw": {
                                            "ko": "리뷰 목록 및 상세 조회",
                                            "en": "Read review list and details"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": "review",
                                        "owner_key": "user_id",
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 73,
                                        "parent_id": 71,
                                        "identifier": "sirsoft-ecommerce.reviews.update",
                                        "name": "리뷰 처리",
                                        "name_raw": {
                                            "ko": "리뷰 처리",
                                            "en": "Manage Review"
                                        },
                                        "description": "리뷰 상태 변경, 답변 등록/수정/삭제, 일괄 처리",
                                        "description_raw": {
                                            "ko": "리뷰 상태 변경, 답변 등록/수정/삭제, 일괄 처리",
                                            "en": "Update review status, manage replies, bulk actions"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": "review",
                                        "owner_key": "user_id",
                                        "order": 2,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 74,
                                        "parent_id": 71,
                                        "identifier": "sirsoft-ecommerce.reviews.delete",
                                        "name": "리뷰 삭제",
                                        "name_raw": {
                                            "ko": "리뷰 삭제",
                                            "en": "Delete Review"
                                        },
                                        "description": "리뷰 삭제",
                                        "description_raw": {
                                            "ko": "리뷰 삭제",
                                            "en": "Delete review"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": "review",
                                        "owner_key": "user_id",
                                        "order": 3,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 75,
                                "parent_id": 21,
                                "identifier": "sirsoft-ecommerce.inquiries",
                                "name": "문의 관리",
                                "name_raw": {
                                    "ko": "문의 관리",
                                    "en": "Inquiry Management"
                                },
                                "description": "상품 1:1 문의 관리 권한",
                                "description_raw": {
                                    "ko": "상품 1:1 문의 관리 권한",
                                    "en": "Product inquiry management permissions"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 13,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 76,
                                        "parent_id": 75,
                                        "identifier": "sirsoft-ecommerce.inquiries.update",
                                        "name": "문의 처리",
                                        "name_raw": {
                                            "ko": "문의 처리",
                                            "en": "Manage Inquiry"
                                        },
                                        "description": "답변 등록/수정/삭제, 비밀글 내용 열람",
                                        "description_raw": {
                                            "ko": "답변 등록/수정/삭제, 비밀글 내용 열람",
                                            "en": "Create, update, and delete inquiry replies; view secret inquiry contents"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 77,
                                        "parent_id": 75,
                                        "identifier": "sirsoft-ecommerce.inquiries.delete",
                                        "name": "문의 삭제",
                                        "name_raw": {
                                            "ko": "문의 삭제",
                                            "en": "Delete Inquiry"
                                        },
                                        "description": "고객이 작성한 문의 자체를 삭제",
                                        "description_raw": {
                                            "ko": "고객이 작성한 문의 자체를 삭제",
                                            "en": "Delete inquiries submitted by customers"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 2,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 78,
                                "parent_id": 21,
                                "identifier": "sirsoft-ecommerce.dashboard",
                                "name": "대시보드",
                                "name_raw": {
                                    "ko": "대시보드",
                                    "en": "Dashboard"
                                },
                                "description": "관리자 대시보드 이커머스 영역 조회 권한",
                                "description_raw": {
                                    "ko": "관리자 대시보드 이커머스 영역 조회 권한",
                                    "en": "Admin dashboard commerce area view permissions"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 14,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 79,
                                        "parent_id": 78,
                                        "identifier": "sirsoft-ecommerce.dashboard.view",
                                        "name": "대시보드 조회",
                                        "name_raw": {
                                            "ko": "대시보드 조회",
                                            "en": "View Dashboard"
                                        },
                                        "description": "관리자 대시보드의 이커머스 판매 현황/리뷰/문의 위젯 조회",
                                        "description_raw": {
                                            "ko": "관리자 대시보드의 이커머스 판매 현황/리뷰/문의 위젯 조회",
                                            "en": "View commerce sales/review/inquiry widgets on the admin dashboard"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 80,
                                "parent_id": 21,
                                "identifier": "sirsoft-ecommerce.user-products",
                                "name": "사용자 상품",
                                "name_raw": {
                                    "ko": "사용자 상품",
                                    "en": "User Products"
                                },
                                "description": "사용자 상품 접근 권한 (블랙컨슈머 차단용)",
                                "description_raw": {
                                    "ko": "사용자 상품 접근 권한 (블랙컨슈머 차단용)",
                                    "en": "User product access permissions (for blocking malicious consumers)"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 15,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 81,
                                        "parent_id": 80,
                                        "identifier": "sirsoft-ecommerce.user-products.read",
                                        "name": "상품 조회",
                                        "name_raw": {
                                            "ko": "상품 조회",
                                            "en": "View Products"
                                        },
                                        "description": "상품 목록 및 상세 조회",
                                        "description_raw": {
                                            "ko": "상품 목록 및 상세 조회",
                                            "en": "View product list and details"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 82,
                                "parent_id": 21,
                                "identifier": "sirsoft-ecommerce.user-orders",
                                "name": "사용자 주문",
                                "name_raw": {
                                    "ko": "사용자 주문",
                                    "en": "User Orders"
                                },
                                "description": "사용자 주문 관련 권한 (블랙컨슈머 차단용)",
                                "description_raw": {
                                    "ko": "사용자 주문 관련 권한 (블랙컨슈머 차단용)",
                                    "en": "User order permissions (for blocking malicious consumers)"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 16,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 83,
                                        "parent_id": 82,
                                        "identifier": "sirsoft-ecommerce.user-orders.create",
                                        "name": "주문하기",
                                        "name_raw": {
                                            "ko": "주문하기",
                                            "en": "Create Order"
                                        },
                                        "description": "주문 생성",
                                        "description_raw": {
                                            "ko": "주문 생성",
                                            "en": "Create a new order"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 84,
                                        "parent_id": 82,
                                        "identifier": "sirsoft-ecommerce.user-orders.cancel",
                                        "name": "주문 취소",
                                        "name_raw": {
                                            "ko": "주문 취소",
                                            "en": "Cancel Order"
                                        },
                                        "description": "주문 취소 요청",
                                        "description_raw": {
                                            "ko": "주문 취소 요청",
                                            "en": "Request order cancellation"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 2,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 85,
                                        "parent_id": 82,
                                        "identifier": "sirsoft-ecommerce.user-orders.confirm",
                                        "name": "구매확정",
                                        "name_raw": {
                                            "ko": "구매확정",
                                            "en": "Confirm Purchase"
                                        },
                                        "description": "주문 상품 구매확정",
                                        "description_raw": {
                                            "ko": "주문 상품 구매확정",
                                            "en": "Confirm purchase of order item"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 3,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 86,
                                "parent_id": 21,
                                "identifier": "sirsoft-ecommerce.user-reviews",
                                "name": "사용자 리뷰",
                                "name_raw": {
                                    "ko": "사용자 리뷰",
                                    "en": "User Reviews"
                                },
                                "description": "사용자 리뷰 관련 권한 (블랙컨슈머 차단용)",
                                "description_raw": {
                                    "ko": "사용자 리뷰 관련 권한 (블랙컨슈머 차단용)",
                                    "en": "User review permissions (for blocking malicious consumers)"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 17,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 87,
                                        "parent_id": 86,
                                        "identifier": "sirsoft-ecommerce.user-reviews.write",
                                        "name": "리뷰 작성",
                                        "name_raw": {
                                            "ko": "리뷰 작성",
                                            "en": "Write Review"
                                        },
                                        "description": "상품 리뷰 작성",
                                        "description_raw": {
                                            "ko": "상품 리뷰 작성",
                                            "en": "Write product review"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 88,
                                "parent_id": 21,
                                "identifier": "sirsoft-ecommerce.mileage",
                                "name": "마일리지 관리",
                                "name_raw": {
                                    "ko": "마일리지 관리",
                                    "en": "Mileage Management"
                                },
                                "description": "마일리지 내역 조회 및 수동 지급/차감 권한",
                                "description_raw": {
                                    "ko": "마일리지 내역 조회 및 수동 지급/차감 권한",
                                    "en": "Mileage history and manual grant/deduct permissions"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 18,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 89,
                                        "parent_id": 88,
                                        "identifier": "sirsoft-ecommerce.mileage.read",
                                        "name": "마일리지 내역 조회",
                                        "name_raw": {
                                            "ko": "마일리지 내역 조회",
                                            "en": "Read Mileage"
                                        },
                                        "description": "마일리지 내역 목록 및 상세 조회",
                                        "description_raw": {
                                            "ko": "마일리지 내역 목록 및 상세 조회",
                                            "en": "Read mileage history list and details"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": "mileage-transaction",
                                        "owner_key": "user_id",
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    },
                                    {
                                        "id": 90,
                                        "parent_id": 88,
                                        "identifier": "sirsoft-ecommerce.mileage.manage",
                                        "name": "마일리지 수동 처리",
                                        "name_raw": {
                                            "ko": "마일리지 수동 처리",
                                            "en": "Manage Mileage"
                                        },
                                        "description": "마일리지 수동 지급/차감 및 일괄 유효기간 연장",
                                        "description_raw": {
                                            "ko": "마일리지 수동 지급/차감 및 일괄 유효기간 연장",
                                            "en": "Manual grant/deduct and bulk expiry extension"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": "mileage-transaction",
                                        "owner_key": "user_id",
                                        "order": 2,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 91,
                                "parent_id": 21,
                                "identifier": "sirsoft-ecommerce.user-currency",
                                "name": "회원 결제 통화 관리",
                                "name_raw": {
                                    "ko": "회원 결제 통화 관리",
                                    "en": "User Currency Management"
                                },
                                "description": "관리자가 회원별 결제 통화를 변경하는 권한",
                                "description_raw": {
                                    "ko": "관리자가 회원별 결제 통화를 변경하는 권한",
                                    "en": "Permission for admins to change a user's payment currency"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 19,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 92,
                                        "parent_id": 91,
                                        "identifier": "sirsoft-ecommerce.user-currency.manage",
                                        "name": "회원 결제 통화 변경",
                                        "name_raw": {
                                            "ko": "회원 결제 통화 변경",
                                            "en": "Manage User Currency"
                                        },
                                        "description": "회원별 결제 통화 변경",
                                        "description_raw": {
                                            "ko": "회원별 결제 통화 변경",
                                            "en": "Change a user's payment currency"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            },
                            {
                                "id": 93,
                                "parent_id": 21,
                                "identifier": "sirsoft-ecommerce.user-shipping-country",
                                "name": "회원 배송국가 관리",
                                "name_raw": {
                                    "ko": "회원 배송국가 관리",
                                    "en": "User Shipping Country Management"
                                },
                                "description": "관리자가 회원별 배송국가를 변경하는 권한",
                                "description_raw": {
                                    "ko": "관리자가 회원별 배송국가를 변경하는 권한",
                                    "en": "Permission for admins to change a user's shipping country"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 20,
                                "is_assigned": false,
                                "is_assignable": false,
                                "children": [
                                    {
                                        "id": 94,
                                        "parent_id": 93,
                                        "identifier": "sirsoft-ecommerce.user-shipping-country.manage",
                                        "name": "회원 배송국가 변경",
                                        "name_raw": {
                                            "ko": "회원 배송국가 변경",
                                            "en": "Manage User Shipping Country"
                                        },
                                        "description": "회원별 배송국가 변경",
                                        "description_raw": {
                                            "ko": "회원별 배송국가 변경",
                                            "en": "Change a user's shipping country"
                                        },
                                        "extension_type": "module",
                                        "extension_identifier": "sirsoft-ecommerce",
                                        "resource_route_key": null,
                                        "owner_key": null,
                                        "order": 1,
                                        "is_assigned": true,
                                        "is_assignable": true,
                                        "scope_type": null
                                    }
                                ]
                            }
                        ]
                    }
                ],
                "created_at": "2026-07-08 10:43:31",
                "updated_at": "2026-07-08 10:43:31",
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true,
                    "can_toggle_status": true
                }
            },
            "... (총 6건 중 2건 표시)"
        ],
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        },
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 6,
            "from": 1,
            "to": 6,
            "has_more_pages": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.permissions.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

역할 관리 화면(`admin_role_list.json`)의 목록 데이터를 제공하는 페이지네이션 조회 엔드포인트다. `search`(identifier/name 텍스트 검색)와 `is_active`(활성 여부)로 필터링하며 `per_page` 로 페이지 크기를 조절한다. 응답에는 각 역할의 할당 권한(permission_ids/permission_values/permissions), 소유 확장 정보, 사용자 수, 현재 사용자의 조작 가능 여부(abilities)가 포함된다. `core.permissions.read` 권한이 필요하다.


### POST /api/admin/roles
<!-- @generated:start:api.admin.roles.store -->
- **라우트명**: `api.admin.roles.store`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\RoleController@store`
- **인증/권한**: `auth:sanctum` + `permission:core.permissions.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| identifier | body | string | 예 | max 100 | 대상 확장/리소스의 식별자 |
| name | body | string | 예 | — | 대상의 이름/명칭 |
| description | body | string | 아니오 | — | 설명 |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| permissions | body | array | 아니오 | — | 역할에 부여할 권한 목록. 각 원소는 `{id, scope_type}` (id=권한 식별자, scope_type=적용 범위: null 전체 / role 역할 범위 / self 본인 범위). 전달된 목록 기준으로 역할의 권한 집합이 재설정됨 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.role.store_validation_rules`).

**요청 예시**

```http
POST /api/admin/roles HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "identifier": "example-key",
    "name": "예시 이름",
    "description": "예시 내용입니다.",
    "is_active": true,
    "permissions": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `14` | 기본 키 (내부 식별자) |
| identifier | string | `probe_6a4dc0a720350` | 역할명 (예: admin, user, manager) |
| name | string | `실측 예시값` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| name_raw | object | `{"ko":"실측 예시값","en":"실측 예시값","fr":"실측 예시값"}` | `name` 의 원본 값 (현재 로케일 미해석 원본 JSON/문자열) |
| description | string | `실측 예시값` | 설명 (다국어 필드는 로케일별 값 객체) |
| description_raw | object | `{"ko":"실측 예시값","en":"실측 예시값","fr":"실측 예시값"}` | `description` 의 원본 값 (현재 로케일 미해석 원본 JSON/문자열) |
| extension_type | null | `null` | 이 리소스를 소유한 확장의 타입 (core/module/plugin/template) |
| extension_identifier | null | `null` | 이 리소스를 소유한 확장의 식별자 |
| extension_name | null | `null` | 이 리소스를 소유한 확장의 표시 이름 (manifest name) |
| is_deletable | boolean | `true` | deletable 여부 |
| is_active | boolean | `true` | active 여부 |
| created_at | string | `2026-07-08 12:14:47` | 생성 일시 |
| updated_at | string | `2026-07-08 12:14:47` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true,"c…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "역할이 성공적으로 생성되었습니다.",
    "data": {
        "id": 14,
        "identifier": "probe_6a4dc0a720350",
        "name": "실측 예시값",
        "name_raw": {
            "ko": "실측 예시값",
            "en": "실측 예시값",
            "fr": "실측 예시값"
        },
        "description": "실측 예시값",
        "description_raw": {
            "ko": "실측 예시값",
            "en": "실측 예시값",
            "fr": "실측 예시값"
        },
        "extension_type": null,
        "extension_identifier": null,
        "extension_name": null,
        "is_deletable": true,
        "is_active": true,
        "created_at": "2026-07-08 12:14:47",
        "updated_at": "2026-07-08 12:14:47",
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true,
            "can_toggle_status": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.permissions.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

새 역할을 생성한다. `identifier` 는 소문자로 시작하는 영숫자·언더스코어 형식(`^[a-z][a-z0-9_]*$`)이어야 하고 전역 고유해야 한다. `name`·`description` 은 다국어 필드로, 문자열로 보내면 설정된 로케일 전체에 동일 값이 채워지고 객체(`{"ko":..., "en":...}`)로도 보낼 수 있다. `permissions` 는 `[{id, scope_type}]` 형식으로 부여할 권한과 각 권한의 적용 범위(scope_type: null=전체, role, self)를 지정한다. 검증 규칙은 `core.role.store_validation_rules` 필터 훅으로 확장이 확장할 수 있다. `core.permissions.create` 권한이 필요하며 성공 시 201 로 생성된 역할을 반환한다.


### GET /api/admin/roles/active
<!-- @generated:start:api.admin.roles.active -->
- **라우트명**: `api.admin.roles.active`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\RoleController@active`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/roles/active HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| identifier | string | `admin` | 역할명 (예: admin, user, manager) |
| name | string | `관리자` | 역할 이름 (다국어 JSON) |
| name_raw | object | `{"ko":"관리자","en":"Administrator"}` | `name` 의 원본 값 (현재 로케일 미해석 원본 JSON/문자열) |
| description | string | `시스템의 모든 기능에 접근할 수 있는 최고 관리자입니다.` | 역할 설명 (다국어 JSON) |
| description_raw | object | `{"ko":"시스템의 모든 기능에 접근할 수 있는 최고 관리자입니다.","en":"Super admin…` | `description` 의 원본 값 (현재 로케일 미해석 원본 JSON/문자열) |
| extension_type | string | `core` | 확장 소유 타입: core(코어), module(모듈), plugin(플러그인), NULL(사용자 정의) |
| extension_identifier | string | `core` | 확장 식별자 (예: core, sirsoft-board, sirsoft-payment) |
| extension_name | string | `이커머스` | 이 리소스를 소유한 확장의 표시 이름 (manifest name) |
| is_deletable | boolean | `false` | deletable 여부 |
| is_active | boolean | `true` | active 여부 |
| created_at | string | `2026-05-27 15:20:18` | 생성 일시 |
| updated_at | string | `2026-06-30 13:41:48` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true,"c…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "역할 정보를 성공적으로 가져왔습니다.",
    "data": {
        "data": [
            {
                "id": 1,
                "identifier": "admin",
                "name": "관리자",
                "name_raw": {
                    "ko": "관리자",
                    "en": "Administrator"
                },
                "description": "시스템의 모든 기능에 접근할 수 있는 최고 관리자입니다.",
                "description_raw": {
                    "ko": "시스템의 모든 기능에 접근할 수 있는 최고 관리자입니다.",
                    "en": "Super administrator with access to all system features."
                },
                "extension_type": "core",
                "extension_identifier": "core",
                "extension_name": null,
                "is_deletable": false,
                "is_active": true,
                "created_at": "2026-07-07 16:38:39",
                "updated_at": "2026-07-08 10:47:51",
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true,
                    "can_toggle_status": true
                }
            },
            {
                "id": 2,
                "identifier": "user",
                "name": "일반 사용자",
                "name_raw": {
                    "ko": "일반 사용자",
                    "en": "User"
                },
                "description": "기본 사용자 역할입니다.",
                "description_raw": {
                    "ko": "기본 사용자 역할입니다.",
                    "en": "Default user role."
                },
                "extension_type": "core",
                "extension_identifier": "core",
                "extension_name": null,
                "is_deletable": false,
                "is_active": true,
                "created_at": "2026-07-07 16:38:39",
                "updated_at": "2026-07-08 10:47:51",
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true,
                    "can_toggle_status": true
                }
            },
            "... (총 6건 중 2건 표시)"
        ],
        "abilities": {
            "can_assign_roles": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명**

셀렉트 UI(사용자 폼·메뉴 편집의 역할 선택 등)에 채울 활성 역할 목록을 제공한다. 별도 권한 미들웨어가 없어 인증만 되면 호출 가능하지만, 내부에서 권한에 따라 범위가 갈린다. `core.permissions.read` 권한 보유자는 전체 활성 역할을 받고(사용자에게 역할을 부여하는 관리 용도), 미보유자는 자신에게 부여된 활성 역할만 받는다(자기 정보 폼 표시 용도). 응답의 `abilities.can_assign_roles` 는 `core.permissions.update` 권한 보유 여부를 나타낸다.


### DELETE /api/admin/roles/{role}
<!-- @generated:start:api.admin.roles.destroy -->
- **라우트명**: `api.admin.roles.destroy`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\RoleController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:core.permissions.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| role | path | string | 예 | — | 대상 role의 식별자 |

**요청 예시**

```http
DELETE /api/admin/roles/4 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)



<!-- 실측 응답에 필드 없음(빈 목록 등) — 데이터가 있는 상태로 재실측하거나 사람이 작성. -->

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "역할이 성공적으로 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.permissions.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**

역할을 삭제한다. 코어 소유 역할(admin/user 등)은 403(`role.system_role_delete_error`)으로, 모듈·플러그인이 소유한 확장 역할은 403(`role.extension_owned_role_delete_error`)으로 거부된다. 삭제 가능한(사용자 정의) 역할만 제거되며, CASCADE 에 의존하지 않고 권한·메뉴·사용자 매핑을 명시적으로 해제한 뒤 역할을 삭제한다. `core.permissions.delete` 권한이 필요하다.


### GET /api/admin/roles/{role}
<!-- @generated:start:api.admin.roles.show -->
- **라우트명**: `api.admin.roles.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\RoleController@show`
- **인증/권한**: `auth:sanctum` + `permission:core.permissions.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| role | path | string | 예 | — | 대상 role의 식별자 |

**요청 예시**

```http
GET /api/admin/roles/4 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `66` | 기본 키 (내부 식별자) |
| identifier | string | `apidoc-sample-role` | 역할명 (예: admin, user, manager) |
| name | string | `API 문서 샘플 역할` | 역할 이름 (다국어 JSON) |
| name_raw | object | `{"ko":"API 문서 샘플 역할","en":"API Doc Sample Role"}` | `name` 의 원본 값 (현재 로케일 미해석 원본 JSON/문자열) |
| description | string | `문서 실측용 역할` | 역할 설명 (다국어 JSON) |
| description_raw | object | `{"ko":"문서 실측용 역할","en":"Sample role for API docs"}` | `description` 의 원본 값 (현재 로케일 미해석 원본 JSON/문자열) |
| extension_type | null | `null` | 확장 소유 타입: core(코어), module(모듈), plugin(플러그인), NULL(사용자 정의) |
| extension_identifier | null | `null` | 확장 식별자 (예: core, sirsoft-board, sirsoft-payment) |
| extension_name | null | `null` | 이 리소스를 소유한 확장의 표시 이름 (manifest name) |
| is_deletable | boolean | `true` | deletable 여부 |
| is_active | boolean | `true` | active 여부 |
| users_count | integer | `0` | users 개수 (집계) |
| permission_ids | array | `[1,85,100]` | permission 식별자 배열 (연관 리소스 참조) |
| permission_values | array | `[{"id":1,"scope_type":null},{"id":85,"scope_type":null},{…` | 할당된 각 권한의 id와 적용 범위만 담은 경량 목록 (원소 id/scope_type — 역할-권한 pivot 파생). scope_type: null=전체, role=역할 범위, self=본인 범위 |
| permissions | array | `[{"id":1,"parent_id":null,"identifier":"core","name":"코어"…` | 연결된 권한 목록 (id/identifier/name — 역할 경유 권한 관계 파생) |
| created_at | string | `2026-07-06 19:15:16` | 생성 일시 |
| updated_at | string | `2026-07-06 19:15:16` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true,"c…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "역할 정보를 성공적으로 가져왔습니다.",
    "data": {
        "id": 4,
        "identifier": "apidoc-sample-role",
        "name": "API 문서 샘플 역할",
        "name_raw": {
            "ko": "API 문서 샘플 역할",
            "en": "API Doc Sample Role"
        },
        "description": "문서 실측용 역할",
        "description_raw": {
            "ko": "문서 실측용 역할",
            "en": "Sample role for API docs"
        },
        "extension_type": null,
        "extension_identifier": null,
        "extension_name": null,
        "is_deletable": true,
        "is_active": true,
        "users_count": 1,
        "permission_ids": [
            1,
            2,
            3,
            4,
            21,
            22,
            23,
            24,
            25,
            26,
            27,
            28,
            29,
            30,
            31,
            32,
            33,
            34,
            35,
            36,
            37,
            38,
            39,
            40,
            41,
            42,
            43,
            44,
            45,
            46,
            47,
            48,
            49,
            50,
            51,
            52,
            53,
            54,
            55,
            56,
            57,
            58,
            59,
            60,
            61,
            62,
            63,
            64,
            65,
            66,
            67,
            68,
            69,
            70,
            71,
            72,
            73,
            74,
            75,
            76,
            77,
            78,
            79,
            80,
            81,
            82,
            83,
            84,
            85,
            86,
            87,
            88,
            89,
            90,
            91,
            92,
            93,
            94,
            95,
            96,
            97,
            98,
            99,
            100,
            101,
            102,
            103,
            104,
            105,
            106,
            107,
            108,
            109,
            110,
            111,
            112,
            113,
            114,
            115,
            116,
            117,
            118,
            119,
            120,
            121,
            122,
            123,
            124,
            125,
            126,
            127,
            128,
            129,
            130,
            131,
            132,
            133,
            134,
            135,
            136,
            137,
            138,
            139,
            140,
            141,
            142,
            143,
            144,
            145,
            146,
            147,
            148,
            149,
            150,
            151,
            152,
            153,
            154,
            155,
            156,
            157,
            158,
            159,
            160,
            161,
            162,
            163,
            164,
            165,
            166,
            167,
            168,
            169,
            170,
            171,
            172,
            173,
            174,
            175,
            176,
            177,
            178,
            179,
            180,
            181,
            182,
            183,
            184,
            185,
            186,
            187,
            188,
            189,
            190,
            191,
            192,
            193,
            194,
            195,
            196,
            197,
            198
        ],
        "permission_values": [
            {
                "id": 1,
                "scope_type": null
            },
            {
                "id": 2,
                "scope_type": null
            },
            {
                "id": 3,
                "scope_type": null
            },
            {
                "id": 4,
                "scope_type": null
            },
            {
                "id": 21,
                "scope_type": null
            },
            {
                "id": 22,
                "scope_type": null
            },
            {
                "id": 23,
                "scope_type": null
            },
            {
                "id": 24,
                "scope_type": null
            },
            {
                "id": 25,
                "scope_type": null
            },
            {
                "id": 26,
                "scope_type": null
            },
            {
                "id": 27,
                "scope_type": null
            },
            {
                "id": 28,
                "scope_type": null
            },
            {
                "id": 29,
                "scope_type": null
            },
            {
                "id": 30,
                "scope_type": null
            },
            {
                "id": 31,
                "scope_type": null
            },
            {
                "id": 32,
                "scope_type": null
            },
            {
                "id": 33,
                "scope_type": null
            },
            {
                "id": 34,
                "scope_type": null
            },
            {
                "id": 35,
                "scope_type": null
            },
            {
                "id": 36,
                "scope_type": null
            },
            {
                "id": 37,
                "scope_type": null
            },
            {
                "id": 38,
                "scope_type": null
            },
            {
                "id": 39,
                "scope_type": null
            },
            {
                "id": 40,
                "scope_type": null
            },
            {
                "id": 41,
                "scope_type": null
            },
            {
                "id": 42,
                "scope_type": null
            },
            {
                "id": 43,
                "scope_type": null
            },
            {
                "id": 44,
                "scope_type": null
            },
            {
                "id": 45,
                "scope_type": null
            },
            {
                "id": 46,
                "scope_type": null
            },
            {
                "id": 47,
                "scope_type": null
            },
            {
                "id": 48,
                "scope_type": null
            },
            {
                "id": 49,
                "scope_type": null
            },
            {
                "id": 50,
                "scope_type": null
            },
            {
                "id": 51,
                "scope_type": null
            },
            {
                "id": 52,
                "scope_type": null
            },
            {
                "id": 53,
                "scope_type": null
            },
            {
                "id": 54,
                "scope_type": null
            },
            {
                "id": 55,
                "scope_type": null
            },
            {
                "id": 56,
                "scope_type": null
            },
            {
                "id": 57,
                "scope_type": null
            },
            {
                "id": 58,
                "scope_type": null
            },
            {
                "id": 59,
                "scope_type": null
            },
            {
                "id": 60,
                "scope_type": null
            },
            {
                "id": 61,
                "scope_type": null
            },
            {
                "id": 62,
                "scope_type": null
            },
            {
                "id": 63,
                "scope_type": null
            },
            {
                "id": 64,
                "scope_type": null
            },
            {
                "id": 65,
                "scope_type": null
            },
            {
                "id": 66,
                "scope_type": null
            },
            {
                "id": 67,
                "scope_type": null
            },
            {
                "id": 68,
                "scope_type": null
            },
            {
                "id": 69,
                "scope_type": null
            },
            {
                "id": 70,
                "scope_type": null
            },
            {
                "id": 71,
                "scope_type": null
            },
            {
                "id": 72,
                "scope_type": null
            },
            {
                "id": 73,
                "scope_type": null
            },
            {
                "id": 74,
                "scope_type": null
            },
            {
                "id": 75,
                "scope_type": null
            },
            {
                "id": 76,
                "scope_type": null
            },
            {
                "id": 77,
                "scope_type": null
            },
            {
                "id": 78,
                "scope_type": null
            },
            {
                "id": 79,
                "scope_type": null
            },
            {
                "id": 80,
                "scope_type": null
            },
            {
                "id": 81,
                "scope_type": null
            },
            {
                "id": 82,
                "scope_type": null
            },
            {
                "id": 83,
                "scope_type": null
            },
            {
                "id": 84,
                "scope_type": null
            },
            {
                "id": 85,
                "scope_type": null
            },
            {
                "id": 86,
                "scope_type": null
            },
            {
                "id": 87,
                "scope_type": null
            },
            {
                "id": 88,
                "scope_type": null
            },
            {
                "id": 89,
                "scope_type": null
            },
            {
                "id": 90,
                "scope_type": null
            },
            {
                "id": 91,
                "scope_type": null
            },
            {
                "id": 92,
                "scope_type": null
            },
            {
                "id": 93,
                "scope_type": null
            },
            {
                "id": 94,
                "scope_type": null
            },
            {
                "id": 95,
                "scope_type": null
            },
            {
                "id": 96,
                "scope_type": null
            },
            {
                "id": 97,
                "scope_type": null
            },
            {
                "id": 98,
                "scope_type": null
            },
            {
                "id": 99,
                "scope_type": null
            },
            {
                "id": 100,
                "scope_type": null
            },
            {
                "id": 101,
                "scope_type": null
            },
            {
                "id": 102,
                "scope_type": null
            },
            {
                "id": 103,
                "scope_type": null
            },
            {
                "id": 104,
                "scope_type": null
            },
            {
                "id": 105,
                "scope_type": null
            },
            {
                "id": 106,
                "scope_type": null
            },
            {
                "id": 107,
                "scope_type": null
            },
            {
                "id": 108,
                "scope_type": null
            },
            {
                "id": 109,
                "scope_type": null
            },
            {
                "id": 110,
                "scope_type": null
            },
            {
                "id": 111,
                "scope_type": null
            },
            {
                "id": 112,
                "scope_type": null
            },
            {
                "id": 113,
                "scope_type": null
            },
            {
                "id": 114,
                "scope_type": null
            },
            {
                "id": 115,
                "scope_type": null
            },
            {
                "id": 116,
                "scope_type": null
            },
            {
                "id": 117,
                "scope_type": null
            },
            {
                "id": 118,
                "scope_type": null
            },
            {
                "id": 119,
                "scope_type": null
            },
            {
                "id": 120,
                "scope_type": null
            },
            {
                "id": 121,
                "scope_type": null
            },
            {
                "id": 122,
                "scope_type": null
            },
            {
                "id": 123,
                "scope_type": null
            },
            {
                "id": 124,
                "scope_type": null
            },
            {
                "id": 125,
                "scope_type": null
            },
            {
                "id": 126,
                "scope_type": null
            },
            {
                "id": 127,
                "scope_type": null
            },
            {
                "id": 128,
                "scope_type": null
            },
            {
                "id": 129,
                "scope_type": null
            },
            {
                "id": 130,
                "scope_type": null
            },
            {
                "id": 131,
                "scope_type": null
            },
            {
                "id": 132,
                "scope_type": null
            },
            {
                "id": 133,
                "scope_type": null
            },
            {
                "id": 134,
                "scope_type": null
            },
            {
                "id": 135,
                "scope_type": null
            },
            {
                "id": 136,
                "scope_type": null
            },
            {
                "id": 137,
                "scope_type": null
            },
            {
                "id": 138,
                "scope_type": null
            },
            {
                "id": 139,
                "scope_type": null
            },
            {
                "id": 140,
                "scope_type": null
            },
            {
                "id": 141,
                "scope_type": null
            },
            {
                "id": 142,
                "scope_type": null
            },
            {
                "id": 143,
                "scope_type": null
            },
            {
                "id": 144,
                "scope_type": null
            },
            {
                "id": 145,
                "scope_type": null
            },
            {
                "id": 146,
                "scope_type": null
            },
            {
                "id": 147,
                "scope_type": null
            },
            {
                "id": 148,
                "scope_type": null
            },
            {
                "id": 149,
                "scope_type": null
            },
            {
                "id": 150,
                "scope_type": null
            },
            {
                "id": 151,
                "scope_type": null
            },
            {
                "id": 152,
                "scope_type": null
            },
            {
                "id": 153,
                "scope_type": null
            },
            {
                "id": 154,
                "scope_type": null
            },
            {
                "id": 155,
                "scope_type": null
            },
            {
                "id": 156,
                "scope_type": null
            },
            {
                "id": 157,
                "scope_type": null
            },
            {
                "id": 158,
                "scope_type": null
            },
            {
                "id": 159,
                "scope_type": null
            },
            {
                "id": 160,
                "scope_type": null
            },
            {
                "id": 161,
                "scope_type": null
            },
            {
                "id": 162,
                "scope_type": null
            },
            {
                "id": 163,
                "scope_type": null
            },
            {
                "id": 164,
                "scope_type": null
            },
            {
                "id": 165,
                "scope_type": null
            },
            {
                "id": 166,
                "scope_type": null
            },
            {
                "id": 167,
                "scope_type": null
            },
            {
                "id": 168,
                "scope_type": null
            },
            {
                "id": 169,
                "scope_type": null
            },
            {
                "id": 170,
                "scope_type": null
            },
            {
                "id": 171,
                "scope_type": null
            },
            {
                "id": 172,
                "scope_type": null
            },
            {
                "id": 173,
                "scope_type": null
            },
            {
                "id": 174,
                "scope_type": null
            },
            {
                "id": 175,
                "scope_type": null
            },
            {
                "id": 176,
                "scope_type": null
            },
            {
                "id": 177,
                "scope_type": null
            },
            {
                "id": 178,
                "scope_type": null
            },
            {
                "id": 179,
                "scope_type": null
            },
            {
                "id": 180,
                "scope_type": null
            },
            {
                "id": 181,
                "scope_type": null
            },
            {
                "id": 182,
                "scope_type": null
            },
            {
                "id": 183,
                "scope_type": null
            },
            {
                "id": 184,
                "scope_type": null
            },
            {
                "id": 185,
                "scope_type": null
            },
            {
                "id": 186,
                "scope_type": null
            },
            {
                "id": 187,
                "scope_type": null
            },
            {
                "id": 188,
                "scope_type": null
            },
            {
                "id": 189,
                "scope_type": null
            },
            {
                "id": 190,
                "scope_type": null
            },
            {
                "id": 191,
                "scope_type": null
            },
            {
                "id": 192,
                "scope_type": null
            },
            {
                "id": 193,
                "scope_type": null
            },
            {
                "id": 194,
                "scope_type": null
            },
            {
                "id": 195,
                "scope_type": null
            },
            {
                "id": 196,
                "scope_type": null
            },
            {
                "id": 197,
                "scope_type": null
            },
            {
                "id": 198,
                "scope_type": null
            }
        ],
        "permissions": [
            {
                "id": 3,
                "parent_id": null,
                "identifier": "sirsoft-board",
                "name": "게시판",
                "name_raw": {
                    "ko": "게시판",
                    "en": "Board"
                },
                "description": "게시판 모듈 권한",
                "description_raw": {
                    "ko": "게시판 모듈 권한",
                    "en": "Board module permissions"
                },
                "extension_type": "module",
                "extension_identifier": "sirsoft-board",
                "resource_route_key": null,
                "owner_key": null,
                "order": 100,
                "is_assigned": true,
                "is_assignable": false,
                "children": [
                    {
                        "id": 95,
                        "parent_id": 3,
                        "identifier": "sirsoft-board.boards",
                        "name": "게시판 관리",
                        "name_raw": {
                            "ko": "게시판 관리",
                            "en": "Board Management"
                        },
                        "description": "게시판 관리 권한",
                        "description_raw": {
                            "ko": "게시판 관리 권한",
                            "en": "Board management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-board",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 1,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 96,
                                "parent_id": 95,
                                "identifier": "sirsoft-board.boards.read",
                                "name": "게시판 조회",
                                "name_raw": {
                                    "ko": "게시판 조회",
                                    "en": "View Boards"
                                },
                                "description": "게시판 목록 및 상세 조회",
                                "description_raw": {
                                    "ko": "게시판 목록 및 상세 조회",
                                    "en": "View board list and details"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-board",
                                "resource_route_key": "board",
                                "owner_key": "created_by",
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 97,
                                "parent_id": 95,
                                "identifier": "sirsoft-board.boards.create",
                                "name": "게시판 생성",
                                "name_raw": {
                                    "ko": "게시판 생성",
                                    "en": "Create Board"
                                },
                                "description": "새 게시판 생성",
                                "description_raw": {
                                    "ko": "새 게시판 생성",
                                    "en": "Create new board"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-board",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 98,
                                "parent_id": 95,
                                "identifier": "sirsoft-board.boards.update",
                                "name": "게시판 수정",
                                "name_raw": {
                                    "ko": "게시판 수정",
                                    "en": "Update Board"
                                },
                                "description": "게시판 설정 수정",
                                "description_raw": {
                                    "ko": "게시판 설정 수정",
                                    "en": "Update board settings"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-board",
                                "resource_route_key": "board",
                                "owner_key": "created_by",
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 99,
                                "parent_id": 95,
                                "identifier": "sirsoft-board.boards.delete",
                                "name": "게시판 삭제",
                                "name_raw": {
                                    "ko": "게시판 삭제",
                                    "en": "Delete Board"
                                },
                                "description": "게시판 삭제",
                                "description_raw": {
                                    "ko": "게시판 삭제",
                                    "en": "Delete board"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-board",
                                "resource_route_key": "board",
                                "owner_key": "created_by",
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 100,
                        "parent_id": 3,
                        "identifier": "sirsoft-board.settings",
                        "name": "환경설정",
                        "name_raw": {
                            "ko": "환경설정",
                            "en": "Settings"
                        },
                        "description": "게시판 환경설정 권한",
                        "description_raw": {
                            "ko": "게시판 환경설정 권한",
                            "en": "Board settings permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-board",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 2,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 101,
                                "parent_id": 100,
                                "identifier": "sirsoft-board.settings.read",
                                "name": "환경설정 조회",
                                "name_raw": {
                                    "ko": "환경설정 조회",
                                    "en": "View Settings"
                                },
                                "description": "게시판 환경설정 조회",
                                "description_raw": {
                                    "ko": "게시판 환경설정 조회",
                                    "en": "View board settings"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-board",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 102,
                                "parent_id": 100,
                                "identifier": "sirsoft-board.settings.update",
                                "name": "환경설정 수정",
                                "name_raw": {
                                    "ko": "환경설정 수정",
                                    "en": "Update Settings"
                                },
                                "description": "게시판 환경설정 수정",
                                "description_raw": {
                                    "ko": "게시판 환경설정 수정",
                                    "en": "Update board settings"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-board",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 103,
                        "parent_id": 3,
                        "identifier": "sirsoft-board.identity.policies",
                        "name": "게시판 본인인증 정책",
                        "name_raw": {
                            "ko": "게시판 본인인증 정책",
                            "en": "Board Identity Policies"
                        },
                        "description": "게시판 컨텍스트의 본인인증 정책 관리 권한",
                        "description_raw": {
                            "ko": "게시판 컨텍스트의 본인인증 정책 관리 권한",
                            "en": "Manage identity verification policies in board context"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-board",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 3,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 104,
                                "parent_id": 103,
                                "identifier": "sirsoft-board.identity.policies.read",
                                "name": "본인인증 정책 조회",
                                "name_raw": {
                                    "ko": "본인인증 정책 조회",
                                    "en": "View Identity Policies"
                                },
                                "description": "게시판 본인인증 정책 조회",
                                "description_raw": {
                                    "ko": "게시판 본인인증 정책 조회",
                                    "en": "View board identity policies"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-board",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 105,
                                "parent_id": 103,
                                "identifier": "sirsoft-board.identity.policies.update",
                                "name": "본인인증 정책 수정",
                                "name_raw": {
                                    "ko": "본인인증 정책 수정",
                                    "en": "Update Identity Policies"
                                },
                                "description": "게시판 본인인증 정책 수정/추가/삭제",
                                "description_raw": {
                                    "ko": "게시판 본인인증 정책 수정/추가/삭제",
                                    "en": "Update, add, delete board identity policies"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-board",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 106,
                        "parent_id": 3,
                        "identifier": "sirsoft-board.reports",
                        "name": "게시판 신고 관리",
                        "name_raw": {
                            "ko": "게시판 신고 관리",
                            "en": "Report Management"
                        },
                        "description": "게시판 신고 관리 권한",
                        "description_raw": {
                            "ko": "게시판 신고 관리 권한",
                            "en": "Board report management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-board",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 4,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 107,
                                "parent_id": 106,
                                "identifier": "sirsoft-board.reports.view",
                                "name": "신고 조회",
                                "name_raw": {
                                    "ko": "신고 조회",
                                    "en": "View Reports"
                                },
                                "description": "신고 목록 및 상세 조회",
                                "description_raw": {
                                    "ko": "신고 목록 및 상세 조회",
                                    "en": "View report list and details"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-board",
                                "resource_route_key": "report",
                                "owner_key": "reporter_id",
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 108,
                                "parent_id": 106,
                                "identifier": "sirsoft-board.reports.manage",
                                "name": "신고 처리",
                                "name_raw": {
                                    "ko": "신고 처리",
                                    "en": "Manage Reports"
                                },
                                "description": "신고 상태 변경 및 처리",
                                "description_raw": {
                                    "ko": "신고 상태 변경 및 처리",
                                    "en": "Update report status and process"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-board",
                                "resource_route_key": "report",
                                "owner_key": "reporter_id",
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    }
                ]
            },
            {
                "id": 21,
                "parent_id": null,
                "identifier": "sirsoft-ecommerce",
                "name": "이커머스",
                "name_raw": {
                    "ko": "이커머스",
                    "en": "Ecommerce"
                },
                "description": "이커머스 모듈 권한",
                "description_raw": {
                    "ko": "이커머스 모듈 권한",
                    "en": "Ecommerce module permissions"
                },
                "extension_type": "module",
                "extension_identifier": "sirsoft-ecommerce",
                "resource_route_key": null,
                "owner_key": null,
                "order": 100,
                "is_assigned": true,
                "is_assignable": false,
                "children": [
                    {
                        "id": 22,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.products",
                        "name": "상품 관리",
                        "name_raw": {
                            "ko": "상품 관리",
                            "en": "Product Management"
                        },
                        "description": "상품 관리 권한",
                        "description_raw": {
                            "ko": "상품 관리 권한",
                            "en": "Product management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 1,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 23,
                                "parent_id": 22,
                                "identifier": "sirsoft-ecommerce.products.read",
                                "name": "상품 조회",
                                "name_raw": {
                                    "ko": "상품 조회",
                                    "en": "Read Products"
                                },
                                "description": "상품 목록 및 상세 조회",
                                "description_raw": {
                                    "ko": "상품 목록 및 상세 조회",
                                    "en": "Read product list and details"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": "product",
                                "owner_key": "created_by",
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 24,
                                "parent_id": 22,
                                "identifier": "sirsoft-ecommerce.products.create",
                                "name": "상품 생성",
                                "name_raw": {
                                    "ko": "상품 생성",
                                    "en": "Create Product"
                                },
                                "description": "새 상품 등록",
                                "description_raw": {
                                    "ko": "새 상품 등록",
                                    "en": "Create new product"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 25,
                                "parent_id": 22,
                                "identifier": "sirsoft-ecommerce.products.update",
                                "name": "상품 수정",
                                "name_raw": {
                                    "ko": "상품 수정",
                                    "en": "Update Product"
                                },
                                "description": "상품 정보 수정",
                                "description_raw": {
                                    "ko": "상품 정보 수정",
                                    "en": "Update product information"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": "product",
                                "owner_key": "created_by",
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 26,
                                "parent_id": 22,
                                "identifier": "sirsoft-ecommerce.products.delete",
                                "name": "상품 삭제",
                                "name_raw": {
                                    "ko": "상품 삭제",
                                    "en": "Delete Product"
                                },
                                "description": "상품 삭제",
                                "description_raw": {
                                    "ko": "상품 삭제",
                                    "en": "Delete product"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": "product",
                                "owner_key": "created_by",
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 27,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.orders",
                        "name": "주문 관리",
                        "name_raw": {
                            "ko": "주문 관리",
                            "en": "Order Management"
                        },
                        "description": "주문 관리 권한",
                        "description_raw": {
                            "ko": "주문 관리 권한",
                            "en": "Order management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 2,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 28,
                                "parent_id": 27,
                                "identifier": "sirsoft-ecommerce.orders.read",
                                "name": "주문 조회",
                                "name_raw": {
                                    "ko": "주문 조회",
                                    "en": "Read Orders"
                                },
                                "description": "주문 목록 및 상세 조회",
                                "description_raw": {
                                    "ko": "주문 목록 및 상세 조회",
                                    "en": "Read order list and details"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": "order",
                                "owner_key": "user_id",
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 29,
                                "parent_id": 27,
                                "identifier": "sirsoft-ecommerce.orders.update",
                                "name": "주문 수정",
                                "name_raw": {
                                    "ko": "주문 수정",
                                    "en": "Update Order"
                                },
                                "description": "주문 상태 변경",
                                "description_raw": {
                                    "ko": "주문 상태 변경",
                                    "en": "Update order status"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": "order",
                                "owner_key": "user_id",
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 30,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.categories",
                        "name": "카테고리 관리",
                        "name_raw": {
                            "ko": "카테고리 관리",
                            "en": "Category Management"
                        },
                        "description": "카테고리 관리 권한",
                        "description_raw": {
                            "ko": "카테고리 관리 권한",
                            "en": "Category management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 3,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 31,
                                "parent_id": 30,
                                "identifier": "sirsoft-ecommerce.categories.read",
                                "name": "카테고리 조회",
                                "name_raw": {
                                    "ko": "카테고리 조회",
                                    "en": "Read Categories"
                                },
                                "description": "카테고리 목록 및 상세 조회",
                                "description_raw": {
                                    "ko": "카테고리 목록 및 상세 조회",
                                    "en": "Read category list and details"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 32,
                                "parent_id": 30,
                                "identifier": "sirsoft-ecommerce.categories.create",
                                "name": "카테고리 생성",
                                "name_raw": {
                                    "ko": "카테고리 생성",
                                    "en": "Create Category"
                                },
                                "description": "카테고리 생성",
                                "description_raw": {
                                    "ko": "카테고리 생성",
                                    "en": "Create category"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 33,
                                "parent_id": 30,
                                "identifier": "sirsoft-ecommerce.categories.update",
                                "name": "카테고리 수정",
                                "name_raw": {
                                    "ko": "카테고리 수정",
                                    "en": "Update Category"
                                },
                                "description": "카테고리 수정",
                                "description_raw": {
                                    "ko": "카테고리 수정",
                                    "en": "Update category"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 34,
                                "parent_id": 30,
                                "identifier": "sirsoft-ecommerce.categories.delete",
                                "name": "카테고리 삭제",
                                "name_raw": {
                                    "ko": "카테고리 삭제",
                                    "en": "Delete Category"
                                },
                                "description": "카테고리 삭제",
                                "description_raw": {
                                    "ko": "카테고리 삭제",
                                    "en": "Delete category"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 35,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.brands",
                        "name": "브랜드 관리",
                        "name_raw": {
                            "ko": "브랜드 관리",
                            "en": "Brand Management"
                        },
                        "description": "브랜드 관리 권한",
                        "description_raw": {
                            "ko": "브랜드 관리 권한",
                            "en": "Brand management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 4,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 36,
                                "parent_id": 35,
                                "identifier": "sirsoft-ecommerce.brands.read",
                                "name": "브랜드 조회",
                                "name_raw": {
                                    "ko": "브랜드 조회",
                                    "en": "Read Brands"
                                },
                                "description": "브랜드 목록 및 상세 조회",
                                "description_raw": {
                                    "ko": "브랜드 목록 및 상세 조회",
                                    "en": "Read brand list and details"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": "brand",
                                "owner_key": "created_by",
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 37,
                                "parent_id": 35,
                                "identifier": "sirsoft-ecommerce.brands.create",
                                "name": "브랜드 생성",
                                "name_raw": {
                                    "ko": "브랜드 생성",
                                    "en": "Create Brand"
                                },
                                "description": "브랜드 생성",
                                "description_raw": {
                                    "ko": "브랜드 생성",
                                    "en": "Create brand"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 38,
                                "parent_id": 35,
                                "identifier": "sirsoft-ecommerce.brands.update",
                                "name": "브랜드 수정",
                                "name_raw": {
                                    "ko": "브랜드 수정",
                                    "en": "Update Brand"
                                },
                                "description": "브랜드 수정",
                                "description_raw": {
                                    "ko": "브랜드 수정",
                                    "en": "Update brand"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": "brand",
                                "owner_key": "created_by",
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 39,
                                "parent_id": 35,
                                "identifier": "sirsoft-ecommerce.brands.delete",
                                "name": "브랜드 삭제",
                                "name_raw": {
                                    "ko": "브랜드 삭제",
                                    "en": "Delete Brand"
                                },
                                "description": "브랜드 삭제",
                                "description_raw": {
                                    "ko": "브랜드 삭제",
                                    "en": "Delete brand"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": "brand",
                                "owner_key": "created_by",
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 40,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.product-notice-templates",
                        "name": "상품정보제공고시 관리",
                        "name_raw": {
                            "ko": "상품정보제공고시 관리",
                            "en": "Product Notice Template Management"
                        },
                        "description": "상품정보제공고시 템플릿 관리 권한",
                        "description_raw": {
                            "ko": "상품정보제공고시 템플릿 관리 권한",
                            "en": "Product notice template management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 5,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 41,
                                "parent_id": 40,
                                "identifier": "sirsoft-ecommerce.product-notice-templates.read",
                                "name": "조회",
                                "name_raw": {
                                    "ko": "조회",
                                    "en": "Read"
                                },
                                "description": "상품정보제공고시 조회",
                                "description_raw": {
                                    "ko": "상품정보제공고시 조회",
                                    "en": "Read product notice templates"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 42,
                                "parent_id": 40,
                                "identifier": "sirsoft-ecommerce.product-notice-templates.create",
                                "name": "생성",
                                "name_raw": {
                                    "ko": "생성",
                                    "en": "Create"
                                },
                                "description": "상품정보제공고시 생성",
                                "description_raw": {
                                    "ko": "상품정보제공고시 생성",
                                    "en": "Create product notice template"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 43,
                                "parent_id": 40,
                                "identifier": "sirsoft-ecommerce.product-notice-templates.update",
                                "name": "수정",
                                "name_raw": {
                                    "ko": "수정",
                                    "en": "Update"
                                },
                                "description": "상품정보제공고시 수정",
                                "description_raw": {
                                    "ko": "상품정보제공고시 수정",
                                    "en": "Update product notice template"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 44,
                                "parent_id": 40,
                                "identifier": "sirsoft-ecommerce.product-notice-templates.delete",
                                "name": "삭제",
                                "name_raw": {
                                    "ko": "삭제",
                                    "en": "Delete"
                                },
                                "description": "상품정보제공고시 삭제",
                                "description_raw": {
                                    "ko": "상품정보제공고시 삭제",
                                    "en": "Delete product notice template"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 45,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.product-common-infos",
                        "name": "공통정보 관리",
                        "name_raw": {
                            "ko": "공통정보 관리",
                            "en": "Product Common Info Management"
                        },
                        "description": "상품 공통정보 관리 권한",
                        "description_raw": {
                            "ko": "상품 공통정보 관리 권한",
                            "en": "Product common info management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 6,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 46,
                                "parent_id": 45,
                                "identifier": "sirsoft-ecommerce.product-common-infos.read",
                                "name": "조회",
                                "name_raw": {
                                    "ko": "조회",
                                    "en": "Read"
                                },
                                "description": "공통정보 조회",
                                "description_raw": {
                                    "ko": "공통정보 조회",
                                    "en": "Read product common info"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 47,
                                "parent_id": 45,
                                "identifier": "sirsoft-ecommerce.product-common-infos.create",
                                "name": "생성",
                                "name_raw": {
                                    "ko": "생성",
                                    "en": "Create"
                                },
                                "description": "공통정보 생성",
                                "description_raw": {
                                    "ko": "공통정보 생성",
                                    "en": "Create product common info"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 48,
                                "parent_id": 45,
                                "identifier": "sirsoft-ecommerce.product-common-infos.update",
                                "name": "수정",
                                "name_raw": {
                                    "ko": "수정",
                                    "en": "Update"
                                },
                                "description": "공통정보 수정",
                                "description_raw": {
                                    "ko": "공통정보 수정",
                                    "en": "Update product common info"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 49,
                                "parent_id": 45,
                                "identifier": "sirsoft-ecommerce.product-common-infos.delete",
                                "name": "삭제",
                                "name_raw": {
                                    "ko": "삭제",
                                    "en": "Delete"
                                },
                                "description": "공통정보 삭제",
                                "description_raw": {
                                    "ko": "공통정보 삭제",
                                    "en": "Delete product common info"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 50,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.settings",
                        "name": "환경설정",
                        "name_raw": {
                            "ko": "환경설정",
                            "en": "Settings"
                        },
                        "description": "이커머스 환경설정 권한",
                        "description_raw": {
                            "ko": "이커머스 환경설정 권한",
                            "en": "Ecommerce settings permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 7,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 51,
                                "parent_id": 50,
                                "identifier": "sirsoft-ecommerce.settings.read",
                                "name": "환경설정 조회",
                                "name_raw": {
                                    "ko": "환경설정 조회",
                                    "en": "View Settings"
                                },
                                "description": "이커머스 환경설정 조회",
                                "description_raw": {
                                    "ko": "이커머스 환경설정 조회",
                                    "en": "View ecommerce settings"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 52,
                                "parent_id": 50,
                                "identifier": "sirsoft-ecommerce.settings.update",
                                "name": "환경설정 수정",
                                "name_raw": {
                                    "ko": "환경설정 수정",
                                    "en": "Update Settings"
                                },
                                "description": "이커머스 환경설정 수정",
                                "description_raw": {
                                    "ko": "이커머스 환경설정 수정",
                                    "en": "Update ecommerce settings"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 53,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.promotion-coupon",
                        "name": "쿠폰 관리",
                        "name_raw": {
                            "ko": "쿠폰 관리",
                            "en": "Coupon Management"
                        },
                        "description": "쿠폰 관리 권한",
                        "description_raw": {
                            "ko": "쿠폰 관리 권한",
                            "en": "Coupon management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 8,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 54,
                                "parent_id": 53,
                                "identifier": "sirsoft-ecommerce.promotion-coupon.read",
                                "name": "쿠폰 조회",
                                "name_raw": {
                                    "ko": "쿠폰 조회",
                                    "en": "Read Coupons"
                                },
                                "description": "쿠폰 목록 및 상세 조회",
                                "description_raw": {
                                    "ko": "쿠폰 목록 및 상세 조회",
                                    "en": "Read coupon list and details"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": "coupon",
                                "owner_key": "created_by",
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 55,
                                "parent_id": 53,
                                "identifier": "sirsoft-ecommerce.promotion-coupon.create",
                                "name": "쿠폰 생성",
                                "name_raw": {
                                    "ko": "쿠폰 생성",
                                    "en": "Create Coupon"
                                },
                                "description": "새 쿠폰 등록",
                                "description_raw": {
                                    "ko": "새 쿠폰 등록",
                                    "en": "Create new coupon"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 56,
                                "parent_id": 53,
                                "identifier": "sirsoft-ecommerce.promotion-coupon.update",
                                "name": "쿠폰 수정",
                                "name_raw": {
                                    "ko": "쿠폰 수정",
                                    "en": "Update Coupon"
                                },
                                "description": "쿠폰 정보 수정",
                                "description_raw": {
                                    "ko": "쿠폰 정보 수정",
                                    "en": "Update coupon information"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": "coupon",
                                "owner_key": "created_by",
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 57,
                                "parent_id": 53,
                                "identifier": "sirsoft-ecommerce.promotion-coupon.delete",
                                "name": "쿠폰 삭제",
                                "name_raw": {
                                    "ko": "쿠폰 삭제",
                                    "en": "Delete Coupon"
                                },
                                "description": "쿠폰 삭제",
                                "description_raw": {
                                    "ko": "쿠폰 삭제",
                                    "en": "Delete coupon"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": "coupon",
                                "owner_key": "created_by",
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 58,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.shipping-policies",
                        "name": "배송정책 관리",
                        "name_raw": {
                            "ko": "배송정책 관리",
                            "en": "Shipping Policy Management"
                        },
                        "description": "배송정책 관리 권한",
                        "description_raw": {
                            "ko": "배송정책 관리 권한",
                            "en": "Shipping policy management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 9,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 59,
                                "parent_id": 58,
                                "identifier": "sirsoft-ecommerce.shipping-policies.read",
                                "name": "배송정책 조회",
                                "name_raw": {
                                    "ko": "배송정책 조회",
                                    "en": "Read Shipping Policies"
                                },
                                "description": "배송정책 목록 및 상세 조회",
                                "description_raw": {
                                    "ko": "배송정책 목록 및 상세 조회",
                                    "en": "Read shipping policy list and details"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": "shippingPolicy",
                                "owner_key": "created_by",
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 60,
                                "parent_id": 58,
                                "identifier": "sirsoft-ecommerce.shipping-policies.create",
                                "name": "배송정책 생성",
                                "name_raw": {
                                    "ko": "배송정책 생성",
                                    "en": "Create Shipping Policy"
                                },
                                "description": "새 배송정책 등록",
                                "description_raw": {
                                    "ko": "새 배송정책 등록",
                                    "en": "Create new shipping policy"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 61,
                                "parent_id": 58,
                                "identifier": "sirsoft-ecommerce.shipping-policies.update",
                                "name": "배송정책 수정",
                                "name_raw": {
                                    "ko": "배송정책 수정",
                                    "en": "Update Shipping Policy"
                                },
                                "description": "배송정책 정보 수정",
                                "description_raw": {
                                    "ko": "배송정책 정보 수정",
                                    "en": "Update shipping policy information"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": "shippingPolicy",
                                "owner_key": "created_by",
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 62,
                                "parent_id": 58,
                                "identifier": "sirsoft-ecommerce.shipping-policies.delete",
                                "name": "배송정책 삭제",
                                "name_raw": {
                                    "ko": "배송정책 삭제",
                                    "en": "Delete Shipping Policy"
                                },
                                "description": "배송정책 삭제",
                                "description_raw": {
                                    "ko": "배송정책 삭제",
                                    "en": "Delete shipping policy"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": "shippingPolicy",
                                "owner_key": "created_by",
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 63,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.product-labels",
                        "name": "상품 라벨 관리",
                        "name_raw": {
                            "ko": "상품 라벨 관리",
                            "en": "Product Label Management"
                        },
                        "description": "상품 라벨 관리 권한",
                        "description_raw": {
                            "ko": "상품 라벨 관리 권한",
                            "en": "Product label management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 10,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 64,
                                "parent_id": 63,
                                "identifier": "sirsoft-ecommerce.product-labels.read",
                                "name": "상품 라벨 조회",
                                "name_raw": {
                                    "ko": "상품 라벨 조회",
                                    "en": "Read Product Labels"
                                },
                                "description": "상품 라벨 목록 및 상세 조회",
                                "description_raw": {
                                    "ko": "상품 라벨 목록 및 상세 조회",
                                    "en": "Read product label list and details"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 65,
                                "parent_id": 63,
                                "identifier": "sirsoft-ecommerce.product-labels.create",
                                "name": "상품 라벨 생성",
                                "name_raw": {
                                    "ko": "상품 라벨 생성",
                                    "en": "Create Product Label"
                                },
                                "description": "새 상품 라벨 등록",
                                "description_raw": {
                                    "ko": "새 상품 라벨 등록",
                                    "en": "Create new product label"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 66,
                                "parent_id": 63,
                                "identifier": "sirsoft-ecommerce.product-labels.update",
                                "name": "상품 라벨 수정",
                                "name_raw": {
                                    "ko": "상품 라벨 수정",
                                    "en": "Update Product Label"
                                },
                                "description": "상품 라벨 정보 수정",
                                "description_raw": {
                                    "ko": "상품 라벨 정보 수정",
                                    "en": "Update product label information"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 67,
                                "parent_id": 63,
                                "identifier": "sirsoft-ecommerce.product-labels.delete",
                                "name": "상품 라벨 삭제",
                                "name_raw": {
                                    "ko": "상품 라벨 삭제",
                                    "en": "Delete Product Label"
                                },
                                "description": "상품 라벨 삭제",
                                "description_raw": {
                                    "ko": "상품 라벨 삭제",
                                    "en": "Delete product label"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 68,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.identity.policies",
                        "name": "이커머스 본인인증 정책",
                        "name_raw": {
                            "ko": "이커머스 본인인증 정책",
                            "en": "Ecommerce Identity Policies"
                        },
                        "description": "이커머스 컨텍스트의 본인인증 정책 관리 권한",
                        "description_raw": {
                            "ko": "이커머스 컨텍스트의 본인인증 정책 관리 권한",
                            "en": "Manage identity verification policies in ecommerce context"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 11,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 69,
                                "parent_id": 68,
                                "identifier": "sirsoft-ecommerce.identity.policies.read",
                                "name": "본인인증 정책 조회",
                                "name_raw": {
                                    "ko": "본인인증 정책 조회",
                                    "en": "View Identity Policies"
                                },
                                "description": "이커머스 본인인증 정책 조회",
                                "description_raw": {
                                    "ko": "이커머스 본인인증 정책 조회",
                                    "en": "View ecommerce identity policies"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 70,
                                "parent_id": 68,
                                "identifier": "sirsoft-ecommerce.identity.policies.update",
                                "name": "본인인증 정책 수정",
                                "name_raw": {
                                    "ko": "본인인증 정책 수정",
                                    "en": "Update Identity Policies"
                                },
                                "description": "이커머스 본인인증 정책 수정/추가/삭제",
                                "description_raw": {
                                    "ko": "이커머스 본인인증 정책 수정/추가/삭제",
                                    "en": "Update, add, delete ecommerce identity policies"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 71,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.reviews",
                        "name": "리뷰 관리",
                        "name_raw": {
                            "ko": "리뷰 관리",
                            "en": "Review Management"
                        },
                        "description": "상품 리뷰 관리 권한",
                        "description_raw": {
                            "ko": "상품 리뷰 관리 권한",
                            "en": "Product review management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 12,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 72,
                                "parent_id": 71,
                                "identifier": "sirsoft-ecommerce.reviews.read",
                                "name": "리뷰 조회",
                                "name_raw": {
                                    "ko": "리뷰 조회",
                                    "en": "Read Reviews"
                                },
                                "description": "리뷰 목록 및 상세 조회",
                                "description_raw": {
                                    "ko": "리뷰 목록 및 상세 조회",
                                    "en": "Read review list and details"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": "review",
                                "owner_key": "user_id",
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 73,
                                "parent_id": 71,
                                "identifier": "sirsoft-ecommerce.reviews.update",
                                "name": "리뷰 처리",
                                "name_raw": {
                                    "ko": "리뷰 처리",
                                    "en": "Manage Review"
                                },
                                "description": "리뷰 상태 변경, 답변 등록/수정/삭제, 일괄 처리",
                                "description_raw": {
                                    "ko": "리뷰 상태 변경, 답변 등록/수정/삭제, 일괄 처리",
                                    "en": "Update review status, manage replies, bulk actions"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": "review",
                                "owner_key": "user_id",
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 74,
                                "parent_id": 71,
                                "identifier": "sirsoft-ecommerce.reviews.delete",
                                "name": "리뷰 삭제",
                                "name_raw": {
                                    "ko": "리뷰 삭제",
                                    "en": "Delete Review"
                                },
                                "description": "리뷰 삭제",
                                "description_raw": {
                                    "ko": "리뷰 삭제",
                                    "en": "Delete review"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": "review",
                                "owner_key": "user_id",
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 75,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.inquiries",
                        "name": "문의 관리",
                        "name_raw": {
                            "ko": "문의 관리",
                            "en": "Inquiry Management"
                        },
                        "description": "상품 1:1 문의 관리 권한",
                        "description_raw": {
                            "ko": "상품 1:1 문의 관리 권한",
                            "en": "Product inquiry management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 13,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 76,
                                "parent_id": 75,
                                "identifier": "sirsoft-ecommerce.inquiries.update",
                                "name": "문의 처리",
                                "name_raw": {
                                    "ko": "문의 처리",
                                    "en": "Manage Inquiry"
                                },
                                "description": "답변 등록/수정/삭제, 비밀글 내용 열람",
                                "description_raw": {
                                    "ko": "답변 등록/수정/삭제, 비밀글 내용 열람",
                                    "en": "Create, update, and delete inquiry replies; view secret inquiry contents"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 77,
                                "parent_id": 75,
                                "identifier": "sirsoft-ecommerce.inquiries.delete",
                                "name": "문의 삭제",
                                "name_raw": {
                                    "ko": "문의 삭제",
                                    "en": "Delete Inquiry"
                                },
                                "description": "고객이 작성한 문의 자체를 삭제",
                                "description_raw": {
                                    "ko": "고객이 작성한 문의 자체를 삭제",
                                    "en": "Delete inquiries submitted by customers"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 78,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.dashboard",
                        "name": "대시보드",
                        "name_raw": {
                            "ko": "대시보드",
                            "en": "Dashboard"
                        },
                        "description": "관리자 대시보드 이커머스 영역 조회 권한",
                        "description_raw": {
                            "ko": "관리자 대시보드 이커머스 영역 조회 권한",
                            "en": "Admin dashboard commerce area view permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 14,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 79,
                                "parent_id": 78,
                                "identifier": "sirsoft-ecommerce.dashboard.view",
                                "name": "대시보드 조회",
                                "name_raw": {
                                    "ko": "대시보드 조회",
                                    "en": "View Dashboard"
                                },
                                "description": "관리자 대시보드의 이커머스 판매 현황/리뷰/문의 위젯 조회",
                                "description_raw": {
                                    "ko": "관리자 대시보드의 이커머스 판매 현황/리뷰/문의 위젯 조회",
                                    "en": "View commerce sales/review/inquiry widgets on the admin dashboard"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 80,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.user-products",
                        "name": "사용자 상품",
                        "name_raw": {
                            "ko": "사용자 상품",
                            "en": "User Products"
                        },
                        "description": "사용자 상품 접근 권한 (블랙컨슈머 차단용)",
                        "description_raw": {
                            "ko": "사용자 상품 접근 권한 (블랙컨슈머 차단용)",
                            "en": "User product access permissions (for blocking malicious consumers)"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 15,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 81,
                                "parent_id": 80,
                                "identifier": "sirsoft-ecommerce.user-products.read",
                                "name": "상품 조회",
                                "name_raw": {
                                    "ko": "상품 조회",
                                    "en": "View Products"
                                },
                                "description": "상품 목록 및 상세 조회",
                                "description_raw": {
                                    "ko": "상품 목록 및 상세 조회",
                                    "en": "View product list and details"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 82,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.user-orders",
                        "name": "사용자 주문",
                        "name_raw": {
                            "ko": "사용자 주문",
                            "en": "User Orders"
                        },
                        "description": "사용자 주문 관련 권한 (블랙컨슈머 차단용)",
                        "description_raw": {
                            "ko": "사용자 주문 관련 권한 (블랙컨슈머 차단용)",
                            "en": "User order permissions (for blocking malicious consumers)"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 16,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 83,
                                "parent_id": 82,
                                "identifier": "sirsoft-ecommerce.user-orders.create",
                                "name": "주문하기",
                                "name_raw": {
                                    "ko": "주문하기",
                                    "en": "Create Order"
                                },
                                "description": "주문 생성",
                                "description_raw": {
                                    "ko": "주문 생성",
                                    "en": "Create a new order"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 84,
                                "parent_id": 82,
                                "identifier": "sirsoft-ecommerce.user-orders.cancel",
                                "name": "주문 취소",
                                "name_raw": {
                                    "ko": "주문 취소",
                                    "en": "Cancel Order"
                                },
                                "description": "주문 취소 요청",
                                "description_raw": {
                                    "ko": "주문 취소 요청",
                                    "en": "Request order cancellation"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 85,
                                "parent_id": 82,
                                "identifier": "sirsoft-ecommerce.user-orders.confirm",
                                "name": "구매확정",
                                "name_raw": {
                                    "ko": "구매확정",
                                    "en": "Confirm Purchase"
                                },
                                "description": "주문 상품 구매확정",
                                "description_raw": {
                                    "ko": "주문 상품 구매확정",
                                    "en": "Confirm purchase of order item"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 86,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.user-reviews",
                        "name": "사용자 리뷰",
                        "name_raw": {
                            "ko": "사용자 리뷰",
                            "en": "User Reviews"
                        },
                        "description": "사용자 리뷰 관련 권한 (블랙컨슈머 차단용)",
                        "description_raw": {
                            "ko": "사용자 리뷰 관련 권한 (블랙컨슈머 차단용)",
                            "en": "User review permissions (for blocking malicious consumers)"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 17,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 87,
                                "parent_id": 86,
                                "identifier": "sirsoft-ecommerce.user-reviews.write",
                                "name": "리뷰 작성",
                                "name_raw": {
                                    "ko": "리뷰 작성",
                                    "en": "Write Review"
                                },
                                "description": "상품 리뷰 작성",
                                "description_raw": {
                                    "ko": "상품 리뷰 작성",
                                    "en": "Write product review"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 88,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.mileage",
                        "name": "마일리지 관리",
                        "name_raw": {
                            "ko": "마일리지 관리",
                            "en": "Mileage Management"
                        },
                        "description": "마일리지 내역 조회 및 수동 지급/차감 권한",
                        "description_raw": {
                            "ko": "마일리지 내역 조회 및 수동 지급/차감 권한",
                            "en": "Mileage history and manual grant/deduct permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 18,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 89,
                                "parent_id": 88,
                                "identifier": "sirsoft-ecommerce.mileage.read",
                                "name": "마일리지 내역 조회",
                                "name_raw": {
                                    "ko": "마일리지 내역 조회",
                                    "en": "Read Mileage"
                                },
                                "description": "마일리지 내역 목록 및 상세 조회",
                                "description_raw": {
                                    "ko": "마일리지 내역 목록 및 상세 조회",
                                    "en": "Read mileage history list and details"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": "mileage-transaction",
                                "owner_key": "user_id",
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 90,
                                "parent_id": 88,
                                "identifier": "sirsoft-ecommerce.mileage.manage",
                                "name": "마일리지 수동 처리",
                                "name_raw": {
                                    "ko": "마일리지 수동 처리",
                                    "en": "Manage Mileage"
                                },
                                "description": "마일리지 수동 지급/차감 및 일괄 유효기간 연장",
                                "description_raw": {
                                    "ko": "마일리지 수동 지급/차감 및 일괄 유효기간 연장",
                                    "en": "Manual grant/deduct and bulk expiry extension"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": "mileage-transaction",
                                "owner_key": "user_id",
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 91,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.user-currency",
                        "name": "회원 결제 통화 관리",
                        "name_raw": {
                            "ko": "회원 결제 통화 관리",
                            "en": "User Currency Management"
                        },
                        "description": "관리자가 회원별 결제 통화를 변경하는 권한",
                        "description_raw": {
                            "ko": "관리자가 회원별 결제 통화를 변경하는 권한",
                            "en": "Permission for admins to change a user's payment currency"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 19,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 92,
                                "parent_id": 91,
                                "identifier": "sirsoft-ecommerce.user-currency.manage",
                                "name": "회원 결제 통화 변경",
                                "name_raw": {
                                    "ko": "회원 결제 통화 변경",
                                    "en": "Manage User Currency"
                                },
                                "description": "회원별 결제 통화 변경",
                                "description_raw": {
                                    "ko": "회원별 결제 통화 변경",
                                    "en": "Change a user's payment currency"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 93,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.user-shipping-country",
                        "name": "회원 배송국가 관리",
                        "name_raw": {
                            "ko": "회원 배송국가 관리",
                            "en": "User Shipping Country Management"
                        },
                        "description": "관리자가 회원별 배송국가를 변경하는 권한",
                        "description_raw": {
                            "ko": "관리자가 회원별 배송국가를 변경하는 권한",
                            "en": "Permission for admins to change a user's shipping country"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 20,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 94,
                                "parent_id": 93,
                                "identifier": "sirsoft-ecommerce.user-shipping-country.manage",
                                "name": "회원 배송국가 변경",
                                "name_raw": {
                                    "ko": "회원 배송국가 변경",
                                    "en": "Manage User Shipping Country"
                                },
                                "description": "회원별 배송국가 변경",
                                "description_raw": {
                                    "ko": "회원별 배송국가 변경",
                                    "en": "Change a user's shipping country"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-ecommerce",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    }
                ]
            },
            {
                "id": 109,
                "parent_id": null,
                "identifier": "sirsoft-page",
                "name": "페이지",
                "name_raw": {
                    "ko": "페이지",
                    "en": "Page"
                },
                "description": "페이지 모듈 권한",
                "description_raw": {
                    "ko": "페이지 모듈 권한",
                    "en": "Page module permissions"
                },
                "extension_type": "module",
                "extension_identifier": "sirsoft-page",
                "resource_route_key": null,
                "owner_key": null,
                "order": 100,
                "is_assigned": true,
                "is_assignable": false,
                "children": [
                    {
                        "id": 110,
                        "parent_id": 109,
                        "identifier": "sirsoft-page.pages",
                        "name": "페이지 관리",
                        "name_raw": {
                            "ko": "페이지 관리",
                            "en": "Page Management"
                        },
                        "description": "페이지 관리 권한",
                        "description_raw": {
                            "ko": "페이지 관리 권한",
                            "en": "Page management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-page",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 1,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 111,
                                "parent_id": 110,
                                "identifier": "sirsoft-page.pages.read",
                                "name": "페이지 조회",
                                "name_raw": {
                                    "ko": "페이지 조회",
                                    "en": "View Pages"
                                },
                                "description": "페이지 목록 및 상세 조회",
                                "description_raw": {
                                    "ko": "페이지 목록 및 상세 조회",
                                    "en": "View page list and details"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-page",
                                "resource_route_key": "page",
                                "owner_key": "created_by",
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 112,
                                "parent_id": 110,
                                "identifier": "sirsoft-page.pages.create",
                                "name": "페이지 생성",
                                "name_raw": {
                                    "ko": "페이지 생성",
                                    "en": "Create Page"
                                },
                                "description": "새 페이지 생성",
                                "description_raw": {
                                    "ko": "새 페이지 생성",
                                    "en": "Create new page"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-page",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 113,
                                "parent_id": 110,
                                "identifier": "sirsoft-page.pages.update",
                                "name": "페이지 수정",
                                "name_raw": {
                                    "ko": "페이지 수정",
                                    "en": "Update Page"
                                },
                                "description": "페이지 내용 수정",
                                "description_raw": {
                                    "ko": "페이지 내용 수정",
                                    "en": "Update page content"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-page",
                                "resource_route_key": "page",
                                "owner_key": "created_by",
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 114,
                                "parent_id": 110,
                                "identifier": "sirsoft-page.pages.delete",
                                "name": "페이지 삭제",
                                "name_raw": {
                                    "ko": "페이지 삭제",
                                    "en": "Delete Page"
                                },
                                "description": "페이지 삭제",
                                "description_raw": {
                                    "ko": "페이지 삭제",
                                    "en": "Delete page"
                                },
                                "extension_type": "module",
                                "extension_identifier": "sirsoft-page",
                                "resource_route_key": "page",
                                "owner_key": "created_by",
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    }
                ]
            },
            {
                "id": 115,
                "parent_id": null,
                "identifier": "core",
                "name": "코어",
                "name_raw": {
                    "ko": "코어",
                    "en": "Core"
                },
                "description": "코어 시스템 권한",
                "description_raw": {
                    "ko": "코어 시스템 권한",
                    "en": "Core system permissions"
                },
                "extension_type": "core",
                "extension_identifier": "core",
                "resource_route_key": null,
                "owner_key": null,
                "order": 1,
                "is_assigned": true,
                "is_assignable": false,
                "children": [
                    {
                        "id": 116,
                        "parent_id": 115,
                        "identifier": "core.users",
                        "name": "사용자 관리",
                        "name_raw": {
                            "ko": "사용자 관리",
                            "en": "User Management"
                        },
                        "description": "사용자 관리 권한",
                        "description_raw": {
                            "ko": "사용자 관리 권한",
                            "en": "User management permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 1,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 117,
                                "parent_id": 116,
                                "identifier": "core.users.read",
                                "name": "사용자 조회",
                                "name_raw": {
                                    "ko": "사용자 조회",
                                    "en": "View Users"
                                },
                                "description": "사용자 목록 및 상세 정보를 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "사용자 목록 및 상세 정보를 조회할 수 있습니다.",
                                    "en": "Can view user list and details."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": "user",
                                "owner_key": "id",
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 118,
                                "parent_id": 116,
                                "identifier": "core.users.create",
                                "name": "사용자 생성",
                                "name_raw": {
                                    "ko": "사용자 생성",
                                    "en": "Create Users"
                                },
                                "description": "새로운 사용자를 생성할 수 있습니다.",
                                "description_raw": {
                                    "ko": "새로운 사용자를 생성할 수 있습니다.",
                                    "en": "Can create new users."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 119,
                                "parent_id": 116,
                                "identifier": "core.users.update",
                                "name": "사용자 수정",
                                "name_raw": {
                                    "ko": "사용자 수정",
                                    "en": "Update Users"
                                },
                                "description": "사용자 정보를 수정할 수 있습니다.",
                                "description_raw": {
                                    "ko": "사용자 정보를 수정할 수 있습니다.",
                                    "en": "Can update user information."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": "user",
                                "owner_key": "id",
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 120,
                                "parent_id": 116,
                                "identifier": "core.users.delete",
                                "name": "사용자 삭제",
                                "name_raw": {
                                    "ko": "사용자 삭제",
                                    "en": "Delete Users"
                                },
                                "description": "사용자를 삭제할 수 있습니다.",
                                "description_raw": {
                                    "ko": "사용자를 삭제할 수 있습니다.",
                                    "en": "Can delete users."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": "user",
                                "owner_key": "id",
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 121,
                        "parent_id": 115,
                        "identifier": "core.menus",
                        "name": "메뉴 관리",
                        "name_raw": {
                            "ko": "메뉴 관리",
                            "en": "Menu Management"
                        },
                        "description": "메뉴 관리 권한",
                        "description_raw": {
                            "ko": "메뉴 관리 권한",
                            "en": "Menu management permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 2,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 122,
                                "parent_id": 121,
                                "identifier": "core.menus.read",
                                "name": "메뉴 조회",
                                "name_raw": {
                                    "ko": "메뉴 조회",
                                    "en": "View Menus"
                                },
                                "description": "메뉴 목록을 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "메뉴 목록을 조회할 수 있습니다.",
                                    "en": "Can view menu list."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": "menu",
                                "owner_key": "created_by",
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 123,
                                "parent_id": 121,
                                "identifier": "core.menus.create",
                                "name": "메뉴 생성",
                                "name_raw": {
                                    "ko": "메뉴 생성",
                                    "en": "Create Menus"
                                },
                                "description": "새로운 메뉴를 생성할 수 있습니다.",
                                "description_raw": {
                                    "ko": "새로운 메뉴를 생성할 수 있습니다.",
                                    "en": "Can create new menus."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 124,
                                "parent_id": 121,
                                "identifier": "core.menus.update",
                                "name": "메뉴 수정",
                                "name_raw": {
                                    "ko": "메뉴 수정",
                                    "en": "Update Menus"
                                },
                                "description": "메뉴 정보를 수정할 수 있습니다.",
                                "description_raw": {
                                    "ko": "메뉴 정보를 수정할 수 있습니다.",
                                    "en": "Can update menu information."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": "menu",
                                "owner_key": "created_by",
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 125,
                                "parent_id": 121,
                                "identifier": "core.menus.delete",
                                "name": "메뉴 삭제",
                                "name_raw": {
                                    "ko": "메뉴 삭제",
                                    "en": "Delete Menus"
                                },
                                "description": "메뉴를 삭제할 수 있습니다.",
                                "description_raw": {
                                    "ko": "메뉴를 삭제할 수 있습니다.",
                                    "en": "Can delete menus."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": "menu",
                                "owner_key": "created_by",
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 126,
                        "parent_id": 115,
                        "identifier": "core.modules",
                        "name": "모듈 관리",
                        "name_raw": {
                            "ko": "모듈 관리",
                            "en": "Module Management"
                        },
                        "description": "모듈 관리 권한",
                        "description_raw": {
                            "ko": "모듈 관리 권한",
                            "en": "Module management permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 3,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 127,
                                "parent_id": 126,
                                "identifier": "core.modules.read",
                                "name": "모듈 조회",
                                "name_raw": {
                                    "ko": "모듈 조회",
                                    "en": "View Modules"
                                },
                                "description": "모듈 목록을 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "모듈 목록을 조회할 수 있습니다.",
                                    "en": "Can view module list."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 128,
                                "parent_id": 126,
                                "identifier": "core.modules.install",
                                "name": "모듈 설치",
                                "name_raw": {
                                    "ko": "모듈 설치",
                                    "en": "Install Modules"
                                },
                                "description": "새로운 모듈을 설치할 수 있습니다.",
                                "description_raw": {
                                    "ko": "새로운 모듈을 설치할 수 있습니다.",
                                    "en": "Can install new modules."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 129,
                                "parent_id": 126,
                                "identifier": "core.modules.activate",
                                "name": "모듈 활성화",
                                "name_raw": {
                                    "ko": "모듈 활성화",
                                    "en": "Activate Modules"
                                },
                                "description": "모듈을 활성화/비활성화할 수 있습니다.",
                                "description_raw": {
                                    "ko": "모듈을 활성화/비활성화할 수 있습니다.",
                                    "en": "Can activate/deactivate modules."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 130,
                                "parent_id": 126,
                                "identifier": "core.modules.uninstall",
                                "name": "모듈 삭제",
                                "name_raw": {
                                    "ko": "모듈 삭제",
                                    "en": "Uninstall Modules"
                                },
                                "description": "모듈을 삭제할 수 있습니다.",
                                "description_raw": {
                                    "ko": "모듈을 삭제할 수 있습니다.",
                                    "en": "Can uninstall modules."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 131,
                        "parent_id": 115,
                        "identifier": "core.plugins",
                        "name": "플러그인 관리",
                        "name_raw": {
                            "ko": "플러그인 관리",
                            "en": "Plugin Management"
                        },
                        "description": "플러그인 관리 권한",
                        "description_raw": {
                            "ko": "플러그인 관리 권한",
                            "en": "Plugin management permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 4,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 132,
                                "parent_id": 131,
                                "identifier": "core.plugins.read",
                                "name": "플러그인 조회",
                                "name_raw": {
                                    "ko": "플러그인 조회",
                                    "en": "View Plugins"
                                },
                                "description": "플러그인 목록을 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "플러그인 목록을 조회할 수 있습니다.",
                                    "en": "Can view plugin list."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 133,
                                "parent_id": 131,
                                "identifier": "core.plugins.install",
                                "name": "플러그인 설치",
                                "name_raw": {
                                    "ko": "플러그인 설치",
                                    "en": "Install Plugins"
                                },
                                "description": "새로운 플러그인을 설치할 수 있습니다.",
                                "description_raw": {
                                    "ko": "새로운 플러그인을 설치할 수 있습니다.",
                                    "en": "Can install new plugins."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 134,
                                "parent_id": 131,
                                "identifier": "core.plugins.activate",
                                "name": "플러그인 활성화",
                                "name_raw": {
                                    "ko": "플러그인 활성화",
                                    "en": "Activate Plugins"
                                },
                                "description": "플러그인을 활성화/비활성화할 수 있습니다.",
                                "description_raw": {
                                    "ko": "플러그인을 활성화/비활성화할 수 있습니다.",
                                    "en": "Can activate/deactivate plugins."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 135,
                                "parent_id": 131,
                                "identifier": "core.plugins.update",
                                "name": "플러그인 설정",
                                "name_raw": {
                                    "ko": "플러그인 설정",
                                    "en": "Configure Plugins"
                                },
                                "description": "플러그인 환경설정을 수정할 수 있습니다.",
                                "description_raw": {
                                    "ko": "플러그인 환경설정을 수정할 수 있습니다.",
                                    "en": "Can update plugin settings."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 136,
                                "parent_id": 131,
                                "identifier": "core.plugins.uninstall",
                                "name": "플러그인 삭제",
                                "name_raw": {
                                    "ko": "플러그인 삭제",
                                    "en": "Uninstall Plugins"
                                },
                                "description": "플러그인을 삭제할 수 있습니다.",
                                "description_raw": {
                                    "ko": "플러그인을 삭제할 수 있습니다.",
                                    "en": "Can uninstall plugins."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 5,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 137,
                        "parent_id": 115,
                        "identifier": "core.templates",
                        "name": "템플릿 관리",
                        "name_raw": {
                            "ko": "템플릿 관리",
                            "en": "Template Management"
                        },
                        "description": "템플릿 관리 권한",
                        "description_raw": {
                            "ko": "템플릿 관리 권한",
                            "en": "Template management permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 5,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 138,
                                "parent_id": 137,
                                "identifier": "core.templates.read",
                                "name": "템플릿 조회",
                                "name_raw": {
                                    "ko": "템플릿 조회",
                                    "en": "View Templates"
                                },
                                "description": "템플릿 목록을 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "템플릿 목록을 조회할 수 있습니다.",
                                    "en": "Can view template list."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 139,
                                "parent_id": 137,
                                "identifier": "core.templates.install",
                                "name": "템플릿 설치",
                                "name_raw": {
                                    "ko": "템플릿 설치",
                                    "en": "Install Templates"
                                },
                                "description": "새로운 템플릿을 설치할 수 있습니다.",
                                "description_raw": {
                                    "ko": "새로운 템플릿을 설치할 수 있습니다.",
                                    "en": "Can install new templates."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 140,
                                "parent_id": 137,
                                "identifier": "core.templates.activate",
                                "name": "템플릿 활성화",
                                "name_raw": {
                                    "ko": "템플릿 활성화",
                                    "en": "Activate Templates"
                                },
                                "description": "템플릿을 활성화/비활성화할 수 있습니다.",
                                "description_raw": {
                                    "ko": "템플릿을 활성화/비활성화할 수 있습니다.",
                                    "en": "Can activate/deactivate templates."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 141,
                                "parent_id": 137,
                                "identifier": "core.templates.uninstall",
                                "name": "템플릿 삭제",
                                "name_raw": {
                                    "ko": "템플릿 삭제",
                                    "en": "Uninstall Templates"
                                },
                                "description": "템플릿을 삭제할 수 있습니다.",
                                "description_raw": {
                                    "ko": "템플릿을 삭제할 수 있습니다.",
                                    "en": "Can uninstall templates."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 142,
                                "parent_id": 137,
                                "identifier": "core.templates.layouts.edit",
                                "name": "레이아웃 편집",
                                "name_raw": {
                                    "ko": "레이아웃 편집",
                                    "en": "Edit Layouts"
                                },
                                "description": "템플릿 레이아웃을 편집할 수 있습니다.",
                                "description_raw": {
                                    "ko": "템플릿 레이아웃을 편집할 수 있습니다.",
                                    "en": "Can edit template layouts."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 5,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 143,
                        "parent_id": 115,
                        "identifier": "core.permissions",
                        "name": "권한 관리",
                        "name_raw": {
                            "ko": "권한 관리",
                            "en": "Permission Management"
                        },
                        "description": "역할 및 권한 관리 권한",
                        "description_raw": {
                            "ko": "역할 및 권한 관리 권한",
                            "en": "Role and permission management permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 6,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 144,
                                "parent_id": 143,
                                "identifier": "core.permissions.read",
                                "name": "권한 조회",
                                "name_raw": {
                                    "ko": "권한 조회",
                                    "en": "View Permissions"
                                },
                                "description": "역할 및 권한 목록을 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "역할 및 권한 목록을 조회할 수 있습니다.",
                                    "en": "Can view roles and permissions."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 145,
                                "parent_id": 143,
                                "identifier": "core.permissions.create",
                                "name": "역할 생성",
                                "name_raw": {
                                    "ko": "역할 생성",
                                    "en": "Create Roles"
                                },
                                "description": "새로운 역할을 생성할 수 있습니다.",
                                "description_raw": {
                                    "ko": "새로운 역할을 생성할 수 있습니다.",
                                    "en": "Can create new roles."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 146,
                                "parent_id": 143,
                                "identifier": "core.permissions.update",
                                "name": "역할 수정",
                                "name_raw": {
                                    "ko": "역할 수정",
                                    "en": "Update Roles"
                                },
                                "description": "역할 정보와 권한을 수정할 수 있습니다.",
                                "description_raw": {
                                    "ko": "역할 정보와 권한을 수정할 수 있습니다.",
                                    "en": "Can update role information and permissions."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 147,
                                "parent_id": 143,
                                "identifier": "core.permissions.delete",
                                "name": "역할 삭제",
                                "name_raw": {
                                    "ko": "역할 삭제",
                                    "en": "Delete Roles"
                                },
                                "description": "역할을 삭제할 수 있습니다.",
                                "description_raw": {
                                    "ko": "역할을 삭제할 수 있습니다.",
                                    "en": "Can delete roles."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 148,
                        "parent_id": 115,
                        "identifier": "core.notification-logs",
                        "name": "알림 발송 이력",
                        "name_raw": {
                            "ko": "알림 발송 이력",
                            "en": "Notification Logs"
                        },
                        "description": "알림 발송 이력 관리 권한",
                        "description_raw": {
                            "ko": "알림 발송 이력 관리 권한",
                            "en": "Notification log management permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 7,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 149,
                                "parent_id": 148,
                                "identifier": "core.notification-logs.read",
                                "name": "발송 이력 조회",
                                "name_raw": {
                                    "ko": "발송 이력 조회",
                                    "en": "View Notification Logs"
                                },
                                "description": "알림 발송 이력을 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "알림 발송 이력을 조회할 수 있습니다.",
                                    "en": "Can view notification logs."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 150,
                                "parent_id": 148,
                                "identifier": "core.notification-logs.delete",
                                "name": "발송 이력 삭제",
                                "name_raw": {
                                    "ko": "발송 이력 삭제",
                                    "en": "Delete Notification Logs"
                                },
                                "description": "알림 발송 이력을 삭제할 수 있습니다.",
                                "description_raw": {
                                    "ko": "알림 발송 이력을 삭제할 수 있습니다.",
                                    "en": "Can delete notification logs."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 151,
                        "parent_id": 115,
                        "identifier": "core.notifications",
                        "name": "알림 (관리자)",
                        "name_raw": {
                            "ko": "알림 (관리자)",
                            "en": "Notifications (Admin)"
                        },
                        "description": "관리자용 알림 관리 권한 (관리자 화면에서 사용)",
                        "description_raw": {
                            "ko": "관리자용 알림 관리 권한 (관리자 화면에서 사용)",
                            "en": "Admin notification management permissions (used in admin UI)"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 8,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 152,
                                "parent_id": 151,
                                "identifier": "core.notifications.read",
                                "name": "알림 조회",
                                "name_raw": {
                                    "ko": "알림 조회",
                                    "en": "View Notifications"
                                },
                                "description": "알림 목록 및 읽지 않은 수를 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "알림 목록 및 읽지 않은 수를 조회할 수 있습니다.",
                                    "en": "Can view notification list and unread count."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 153,
                                "parent_id": 151,
                                "identifier": "core.notifications.update",
                                "name": "알림 읽음 처리",
                                "name_raw": {
                                    "ko": "알림 읽음 처리",
                                    "en": "Mark Notifications Read"
                                },
                                "description": "알림을 읽음 처리할 수 있습니다.",
                                "description_raw": {
                                    "ko": "알림을 읽음 처리할 수 있습니다.",
                                    "en": "Can mark notifications as read."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 154,
                                "parent_id": 151,
                                "identifier": "core.notifications.delete",
                                "name": "알림 삭제",
                                "name_raw": {
                                    "ko": "알림 삭제",
                                    "en": "Delete Notifications"
                                },
                                "description": "알림을 삭제할 수 있습니다.",
                                "description_raw": {
                                    "ko": "알림을 삭제할 수 있습니다.",
                                    "en": "Can delete notifications."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 155,
                        "parent_id": 115,
                        "identifier": "core.user-notifications",
                        "name": "알림 (사용자)",
                        "name_raw": {
                            "ko": "알림 (사용자)",
                            "en": "Notifications (User)"
                        },
                        "description": "사용자용 알림 권한 (사용자 화면에서 본인 알림 관리)",
                        "description_raw": {
                            "ko": "사용자용 알림 권한 (사용자 화면에서 본인 알림 관리)",
                            "en": "User notification permissions (managing own notifications in user UI)"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 8,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 156,
                                "parent_id": 155,
                                "identifier": "core.user-notifications.read",
                                "name": "알림 조회",
                                "name_raw": {
                                    "ko": "알림 조회",
                                    "en": "View Notifications"
                                },
                                "description": "본인의 알림 목록 및 읽지 않은 수를 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "본인의 알림 목록 및 읽지 않은 수를 조회할 수 있습니다.",
                                    "en": "Can view own notification list and unread count."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 157,
                                "parent_id": 155,
                                "identifier": "core.user-notifications.update",
                                "name": "알림 읽음 처리",
                                "name_raw": {
                                    "ko": "알림 읽음 처리",
                                    "en": "Mark Notifications Read"
                                },
                                "description": "본인의 알림을 읽음 처리할 수 있습니다.",
                                "description_raw": {
                                    "ko": "본인의 알림을 읽음 처리할 수 있습니다.",
                                    "en": "Can mark own notifications as read."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 158,
                                "parent_id": 155,
                                "identifier": "core.user-notifications.delete",
                                "name": "알림 삭제",
                                "name_raw": {
                                    "ko": "알림 삭제",
                                    "en": "Delete Notifications"
                                },
                                "description": "본인의 알림을 삭제할 수 있습니다.",
                                "description_raw": {
                                    "ko": "본인의 알림을 삭제할 수 있습니다.",
                                    "en": "Can delete own notifications."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 159,
                        "parent_id": 115,
                        "identifier": "core.identity",
                        "name": "본인인증 (사용자)",
                        "name_raw": {
                            "ko": "본인인증 (사용자)",
                            "en": "Identity Verification (User)"
                        },
                        "description": "로그인 사용자의 본인인증 challenge 요청/검증/취소 권한",
                        "description_raw": {
                            "ko": "로그인 사용자의 본인인증 challenge 요청/검증/취소 권한",
                            "en": "Permissions for authenticated users to request/verify/cancel IDV challenges"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 8,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 160,
                                "parent_id": 159,
                                "identifier": "core.identity.request",
                                "name": "IDV 요청",
                                "name_raw": {
                                    "ko": "IDV 요청",
                                    "en": "Request IDV Challenge"
                                },
                                "description": "본인인증 challenge 를 요청할 수 있습니다.",
                                "description_raw": {
                                    "ko": "본인인증 challenge 를 요청할 수 있습니다.",
                                    "en": "Can request identity verification challenges."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 161,
                                "parent_id": 159,
                                "identifier": "core.identity.verify",
                                "name": "IDV 검증",
                                "name_raw": {
                                    "ko": "IDV 검증",
                                    "en": "Verify IDV Challenge"
                                },
                                "description": "본인의 본인인증 challenge 를 검증할 수 있습니다.",
                                "description_raw": {
                                    "ko": "본인의 본인인증 challenge 를 검증할 수 있습니다.",
                                    "en": "Can verify own identity verification challenges."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": "challenge",
                                "owner_key": "user_id",
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 162,
                                "parent_id": 159,
                                "identifier": "core.identity.cancel",
                                "name": "IDV 취소",
                                "name_raw": {
                                    "ko": "IDV 취소",
                                    "en": "Cancel IDV Challenge"
                                },
                                "description": "본인의 본인인증 challenge 를 취소할 수 있습니다.",
                                "description_raw": {
                                    "ko": "본인의 본인인증 challenge 를 취소할 수 있습니다.",
                                    "en": "Can cancel own identity verification challenges."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": "challenge",
                                "owner_key": "user_id",
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 163,
                        "parent_id": 115,
                        "identifier": "core.admin.identity",
                        "name": "본인인증 관리 (관리자)",
                        "name_raw": {
                            "ko": "본인인증 관리 (관리자)",
                            "en": "Identity Verification Management (Admin)"
                        },
                        "description": "관리자용 IDV 프로바이더/정책/로그 관리 권한",
                        "description_raw": {
                            "ko": "관리자용 IDV 프로바이더/정책/로그 관리 권한",
                            "en": "Admin permissions for IDV providers, policies, and logs"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 8,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 164,
                                "parent_id": 163,
                                "identifier": "core.admin.identity.providers.read",
                                "name": "프로바이더 설정 조회",
                                "name_raw": {
                                    "ko": "프로바이더 설정 조회",
                                    "en": "View IDV Providers"
                                },
                                "description": "본인인증 프로바이더 설정을 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "본인인증 프로바이더 설정을 조회할 수 있습니다.",
                                    "en": "Can view identity verification providers."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 165,
                                "parent_id": 163,
                                "identifier": "core.admin.identity.providers.update",
                                "name": "프로바이더 설정 수정",
                                "name_raw": {
                                    "ko": "프로바이더 설정 수정",
                                    "en": "Update IDV Providers"
                                },
                                "description": "본인인증 프로바이더 설정을 수정할 수 있습니다.",
                                "description_raw": {
                                    "ko": "본인인증 프로바이더 설정을 수정할 수 있습니다.",
                                    "en": "Can update identity verification providers."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 166,
                                "parent_id": 163,
                                "identifier": "core.admin.identity.policies.read",
                                "name": "정책 조회",
                                "name_raw": {
                                    "ko": "정책 조회",
                                    "en": "View IDV Policies"
                                },
                                "description": "본인인증 정책(라우트/훅별)을 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "본인인증 정책(라우트/훅별)을 조회할 수 있습니다.",
                                    "en": "Can view identity verification policies by route/hook."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 167,
                                "parent_id": 163,
                                "identifier": "core.admin.identity.policies.update",
                                "name": "정책 수정",
                                "name_raw": {
                                    "ko": "정책 수정",
                                    "en": "Update IDV Policies"
                                },
                                "description": "본인인증 정책(라우트/훅별)을 CRUD 할 수 있습니다.",
                                "description_raw": {
                                    "ko": "본인인증 정책(라우트/훅별)을 CRUD 할 수 있습니다.",
                                    "en": "Can CRUD identity verification policies by route/hook."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 168,
                                "parent_id": 163,
                                "identifier": "core.admin.identity.logs.read",
                                "name": "로그 열람",
                                "name_raw": {
                                    "ko": "로그 열람",
                                    "en": "View IDV Logs"
                                },
                                "description": "본인인증 이력을 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "본인인증 이력을 조회할 수 있습니다.",
                                    "en": "Can view identity verification logs."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 5,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 169,
                                "parent_id": 163,
                                "identifier": "core.admin.identity.logs.purge",
                                "name": "로그 파기",
                                "name_raw": {
                                    "ko": "로그 파기",
                                    "en": "Purge IDV Logs"
                                },
                                "description": "본인인증 이력을 파기(보관주기 외)할 수 있습니다.",
                                "description_raw": {
                                    "ko": "본인인증 이력을 파기(보관주기 외)할 수 있습니다.",
                                    "en": "Can purge identity verification logs (retention-based)."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 6,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 170,
                                "parent_id": 163,
                                "identifier": "core.admin.identity.messages.read",
                                "name": "메시지 템플릿 조회",
                                "name_raw": {
                                    "ko": "메시지 템플릿 조회",
                                    "en": "View IDV Message Templates"
                                },
                                "description": "본인인증 메시지 정의/템플릿을 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "본인인증 메시지 정의/템플릿을 조회할 수 있습니다.",
                                    "en": "Can view identity verification message definitions and templates."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 7,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 171,
                                "parent_id": 163,
                                "identifier": "core.admin.identity.messages.update",
                                "name": "메시지 템플릿 수정",
                                "name_raw": {
                                    "ko": "메시지 템플릿 수정",
                                    "en": "Update IDV Message Templates"
                                },
                                "description": "본인인증 메시지 정의/템플릿을 수정할 수 있습니다.",
                                "description_raw": {
                                    "ko": "본인인증 메시지 정의/템플릿을 수정할 수 있습니다.",
                                    "en": "Can update identity verification message definitions and templates."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 8,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 172,
                        "parent_id": 115,
                        "identifier": "core.settings",
                        "name": "환경설정",
                        "name_raw": {
                            "ko": "환경설정",
                            "en": "Settings"
                        },
                        "description": "시스템 환경설정 권한",
                        "description_raw": {
                            "ko": "시스템 환경설정 권한",
                            "en": "System settings permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 8,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 173,
                                "parent_id": 172,
                                "identifier": "core.settings.read",
                                "name": "설정 조회",
                                "name_raw": {
                                    "ko": "설정 조회",
                                    "en": "View Settings"
                                },
                                "description": "시스템 설정을 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "시스템 설정을 조회할 수 있습니다.",
                                    "en": "Can view system settings."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 174,
                                "parent_id": 172,
                                "identifier": "core.settings.update",
                                "name": "설정 수정",
                                "name_raw": {
                                    "ko": "설정 수정",
                                    "en": "Update Settings"
                                },
                                "description": "시스템 설정을 수정할 수 있습니다.",
                                "description_raw": {
                                    "ko": "시스템 설정을 수정할 수 있습니다.",
                                    "en": "Can update system settings."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 175,
                        "parent_id": 115,
                        "identifier": "core.dashboard",
                        "name": "대시보드",
                        "name_raw": {
                            "ko": "대시보드",
                            "en": "Dashboard"
                        },
                        "description": "대시보드 접근 권한",
                        "description_raw": {
                            "ko": "대시보드 접근 권한",
                            "en": "Dashboard access permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 9,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 176,
                                "parent_id": 175,
                                "identifier": "core.dashboard.read",
                                "name": "대시보드 조회",
                                "name_raw": {
                                    "ko": "대시보드 조회",
                                    "en": "View Dashboard"
                                },
                                "description": "대시보드 통계 및 정보를 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "대시보드 통계 및 정보를 조회할 수 있습니다.",
                                    "en": "Can view dashboard statistics and information."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 177,
                                "parent_id": 175,
                                "identifier": "core.dashboard.system-status",
                                "name": "시스템 상태",
                                "name_raw": {
                                    "ko": "시스템 상태",
                                    "en": "System Status"
                                },
                                "description": "시스템 상태 정보를 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "시스템 상태 정보를 조회할 수 있습니다.",
                                    "en": "Can view system status information."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 178,
                                "parent_id": 175,
                                "identifier": "core.dashboard.resources",
                                "name": "시스템 리소스",
                                "name_raw": {
                                    "ko": "시스템 리소스",
                                    "en": "System Resources"
                                },
                                "description": "CPU, 메모리, 디스크 사용량을 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "CPU, 메모리, 디스크 사용량을 조회할 수 있습니다.",
                                    "en": "Can view CPU, memory, and disk usage."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 179,
                                "parent_id": 175,
                                "identifier": "core.dashboard.activities",
                                "name": "최근 활동",
                                "name_raw": {
                                    "ko": "최근 활동",
                                    "en": "Recent Activities"
                                },
                                "description": "최근 활동 이력을 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "최근 활동 이력을 조회할 수 있습니다.",
                                    "en": "Can view recent activity history."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": "activityLog",
                                "owner_key": "user_id",
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 180,
                                "parent_id": 175,
                                "identifier": "core.dashboard.alerts",
                                "name": "시스템 알림",
                                "name_raw": {
                                    "ko": "시스템 알림",
                                    "en": "System Alerts"
                                },
                                "description": "시스템 알림을 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "시스템 알림을 조회할 수 있습니다.",
                                    "en": "Can view system alerts."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 5,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 181,
                        "parent_id": 115,
                        "identifier": "core.activities",
                        "name": "활동 로그",
                        "name_raw": {
                            "ko": "활동 로그",
                            "en": "Activity Logs"
                        },
                        "description": "활동 로그 조회 권한",
                        "description_raw": {
                            "ko": "활동 로그 조회 권한",
                            "en": "Activity log access permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 10,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 182,
                                "parent_id": 181,
                                "identifier": "core.activities.read",
                                "name": "활동 로그 조회",
                                "name_raw": {
                                    "ko": "활동 로그 조회",
                                    "en": "View Activity Logs"
                                },
                                "description": "활동 로그를 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "활동 로그를 조회할 수 있습니다.",
                                    "en": "Can view activity logs."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": "activityLog",
                                "owner_key": "user_id",
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 183,
                                "parent_id": 181,
                                "identifier": "core.activities.delete",
                                "name": "활동 로그 삭제",
                                "name_raw": {
                                    "ko": "활동 로그 삭제",
                                    "en": "Delete Activity Logs"
                                },
                                "description": "활동 로그를 삭제할 수 있습니다.",
                                "description_raw": {
                                    "ko": "활동 로그를 삭제할 수 있습니다.",
                                    "en": "Can delete activity logs."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 184,
                        "parent_id": 115,
                        "identifier": "core.attachments",
                        "name": "첨부파일 관리",
                        "name_raw": {
                            "ko": "첨부파일 관리",
                            "en": "Attachment Management"
                        },
                        "description": "첨부파일 관리 권한",
                        "description_raw": {
                            "ko": "첨부파일 관리 권한",
                            "en": "Attachment management permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 11,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 185,
                                "parent_id": 184,
                                "identifier": "core.attachments.create",
                                "name": "첨부파일 업로드",
                                "name_raw": {
                                    "ko": "첨부파일 업로드",
                                    "en": "Upload Attachments"
                                },
                                "description": "첨부파일을 업로드할 수 있습니다.",
                                "description_raw": {
                                    "ko": "첨부파일을 업로드할 수 있습니다.",
                                    "en": "Can upload attachments."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 186,
                                "parent_id": 184,
                                "identifier": "core.attachments.update",
                                "name": "첨부파일 수정",
                                "name_raw": {
                                    "ko": "첨부파일 수정",
                                    "en": "Update Attachments"
                                },
                                "description": "첨부파일 정보 및 순서를 수정할 수 있습니다.",
                                "description_raw": {
                                    "ko": "첨부파일 정보 및 순서를 수정할 수 있습니다.",
                                    "en": "Can update attachment information and order."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": "attachment",
                                "owner_key": "created_by",
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 187,
                                "parent_id": 184,
                                "identifier": "core.attachments.delete",
                                "name": "첨부파일 삭제",
                                "name_raw": {
                                    "ko": "첨부파일 삭제",
                                    "en": "Delete Attachments"
                                },
                                "description": "첨부파일을 삭제할 수 있습니다.",
                                "description_raw": {
                                    "ko": "첨부파일을 삭제할 수 있습니다.",
                                    "en": "Can delete attachments."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": "attachment",
                                "owner_key": "created_by",
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 188,
                        "parent_id": 115,
                        "identifier": "core.schedules",
                        "name": "스케줄 관리",
                        "name_raw": {
                            "ko": "스케줄 관리",
                            "en": "Schedule Management"
                        },
                        "description": "스케줄 작업 관리 권한",
                        "description_raw": {
                            "ko": "스케줄 작업 관리 권한",
                            "en": "Schedule task management permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 12,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 189,
                                "parent_id": 188,
                                "identifier": "core.schedules.read",
                                "name": "스케줄 조회",
                                "name_raw": {
                                    "ko": "스케줄 조회",
                                    "en": "View Schedules"
                                },
                                "description": "스케줄 목록 및 상세 정보를 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "스케줄 목록 및 상세 정보를 조회할 수 있습니다.",
                                    "en": "Can view schedule list and details."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 190,
                                "parent_id": 188,
                                "identifier": "core.schedules.create",
                                "name": "스케줄 생성",
                                "name_raw": {
                                    "ko": "스케줄 생성",
                                    "en": "Create Schedules"
                                },
                                "description": "새로운 스케줄을 생성할 수 있습니다.",
                                "description_raw": {
                                    "ko": "새로운 스케줄을 생성할 수 있습니다.",
                                    "en": "Can create new schedules."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 191,
                                "parent_id": 188,
                                "identifier": "core.schedules.update",
                                "name": "스케줄 수정",
                                "name_raw": {
                                    "ko": "스케줄 수정",
                                    "en": "Update Schedules"
                                },
                                "description": "스케줄 정보를 수정할 수 있습니다.",
                                "description_raw": {
                                    "ko": "스케줄 정보를 수정할 수 있습니다.",
                                    "en": "Can update schedule information."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 192,
                                "parent_id": 188,
                                "identifier": "core.schedules.delete",
                                "name": "스케줄 삭제",
                                "name_raw": {
                                    "ko": "스케줄 삭제",
                                    "en": "Delete Schedules"
                                },
                                "description": "스케줄을 삭제할 수 있습니다.",
                                "description_raw": {
                                    "ko": "스케줄을 삭제할 수 있습니다.",
                                    "en": "Can delete schedules."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 193,
                                "parent_id": 188,
                                "identifier": "core.schedules.run",
                                "name": "스케줄 실행",
                                "name_raw": {
                                    "ko": "스케줄 실행",
                                    "en": "Run Schedules"
                                },
                                "description": "스케줄을 수동으로 실행할 수 있습니다.",
                                "description_raw": {
                                    "ko": "스케줄을 수동으로 실행할 수 있습니다.",
                                    "en": "Can manually run schedules."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 5,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    },
                    {
                        "id": 194,
                        "parent_id": 115,
                        "identifier": "core.language_packs",
                        "name": "언어팩 관리",
                        "name_raw": {
                            "ko": "언어팩 관리",
                            "en": "Language Pack Management"
                        },
                        "description": "언어팩 설치/제거/활성화 권한",
                        "description_raw": {
                            "ko": "언어팩 설치/제거/활성화 권한",
                            "en": "Language pack install/uninstall/activation permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 13,
                        "is_assigned": true,
                        "is_assignable": false,
                        "children": [
                            {
                                "id": 195,
                                "parent_id": 194,
                                "identifier": "core.language_packs.read",
                                "name": "언어팩 조회",
                                "name_raw": {
                                    "ko": "언어팩 조회",
                                    "en": "View Language Packs"
                                },
                                "description": "설치된 언어팩 목록 및 상세 정보를 조회할 수 있습니다.",
                                "description_raw": {
                                    "ko": "설치된 언어팩 목록 및 상세 정보를 조회할 수 있습니다.",
                                    "en": "Can view installed language pack list and details."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 1,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 196,
                                "parent_id": 194,
                                "identifier": "core.language_packs.install",
                                "name": "언어팩 설치",
                                "name_raw": {
                                    "ko": "언어팩 설치",
                                    "en": "Install Language Packs"
                                },
                                "description": "ZIP/GitHub/URL 로 언어팩을 설치할 수 있습니다.",
                                "description_raw": {
                                    "ko": "ZIP/GitHub/URL 로 언어팩을 설치할 수 있습니다.",
                                    "en": "Can install language packs from ZIP/GitHub/URL."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 2,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 197,
                                "parent_id": 194,
                                "identifier": "core.language_packs.manage",
                                "name": "언어팩 관리",
                                "name_raw": {
                                    "ko": "언어팩 관리",
                                    "en": "Manage Language Packs"
                                },
                                "description": "언어팩을 활성화/비활성화/제거할 수 있습니다.",
                                "description_raw": {
                                    "ko": "언어팩을 활성화/비활성화/제거할 수 있습니다.",
                                    "en": "Can activate/deactivate/uninstall language packs."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 3,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            },
                            {
                                "id": 198,
                                "parent_id": 194,
                                "identifier": "core.language_packs.update",
                                "name": "언어팩 업데이트",
                                "name_raw": {
                                    "ko": "언어팩 업데이트",
                                    "en": "Update Language Packs"
                                },
                                "description": "설치된 언어팩의 업데이트를 확인하고 실행할 수 있습니다.",
                                "description_raw": {
                                    "ko": "설치된 언어팩의 업데이트를 확인하고 실행할 수 있습니다.",
                                    "en": "Can check and apply updates for installed language packs."
                                },
                                "extension_type": "core",
                                "extension_identifier": "core",
                                "resource_route_key": null,
                                "owner_key": null,
                                "order": 4,
                                "is_assigned": true,
                                "is_assignable": true,
                                "scope_type": null
                            }
                        ]
                    }
                ]
            },
            {
                "id": 1,
                "parent_id": null,
                "identifier": "apidoc-sample.parent",
                "name": "API 문서 샘플 권한",
                "name_raw": {
                    "ko": "API 문서 샘플 권한",
                    "en": "API Doc Sample Permission"
                },
                "description": "문서 실측용 권한",
                "description_raw": {
                    "ko": "문서 실측용 권한",
                    "en": "Sample permission"
                },
                "extension_type": null,
                "extension_identifier": null,
                "resource_route_key": null,
                "owner_key": null,
                "order": 0,
                "is_assigned": true,
                "is_assignable": false,
                "children": [
                    {
                        "id": 2,
                        "parent_id": 1,
                        "identifier": "apidoc-sample.child",
                        "name": "하위 권한",
                        "name_raw": {
                            "ko": "하위 권한",
                            "en": "Child Permission"
                        },
                        "description": "Quod consequatur sunt necessitatibus quam quia voluptas quod aperiam.",
                        "description_raw": {
                            "ko": "Quod consequatur sunt necessitatibus quam quia voluptas quod aperiam.",
                            "en": "Mollitia incidunt dolor rem maxime."
                        },
                        "extension_type": null,
                        "extension_identifier": null,
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 0,
                        "is_assigned": true,
                        "is_assignable": true,
                        "scope_type": null
                    }
                ]
            },
            {
                "id": 3,
                "parent_id": null,
                "identifier": "sirsoft-board",
                "name": "게시판",
                "name_raw": {
                    "ko": "게시판",
                    "en": "Board"
                },
                "description": "게시판 모듈 권한",
                "description_raw": {
                    "ko": "게시판 모듈 권한",
                    "en": "Board module permissions"
                },
                "extension_type": "module",
                "extension_identifier": "sirsoft-board",
                "resource_route_key": null,
                "owner_key": null,
                "order": 100,
                "is_assigned": true,
                "is_assignable": false,
                "children": [
                    {
                        "id": 4,
                        "parent_id": 3,
                        "identifier": "sirsoft-board.apidoc-sample-board",
                        "name": "API 문서 샘플 게시판 게시판",
                        "name_raw": {
                            "ko": "API 문서 샘플 게시판 게시판",
                            "en": "API Doc Sample Board board"
                        },
                        "description": "API 문서 샘플 게시판 게시판 권한",
                        "description_raw": {
                            "ko": "API 문서 샘플 게시판 게시판 권한",
                            "en": "API Doc Sample Board board permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-board",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 0,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 95,
                        "parent_id": 3,
                        "identifier": "sirsoft-board.boards",
                        "name": "게시판 관리",
                        "name_raw": {
                            "ko": "게시판 관리",
                            "en": "Board Management"
                        },
                        "description": "게시판 관리 권한",
                        "description_raw": {
                            "ko": "게시판 관리 권한",
                            "en": "Board management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-board",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 1,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 100,
                        "parent_id": 3,
                        "identifier": "sirsoft-board.settings",
                        "name": "환경설정",
                        "name_raw": {
                            "ko": "환경설정",
                            "en": "Settings"
                        },
                        "description": "게시판 환경설정 권한",
                        "description_raw": {
                            "ko": "게시판 환경설정 권한",
                            "en": "Board settings permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-board",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 2,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 103,
                        "parent_id": 3,
                        "identifier": "sirsoft-board.identity.policies",
                        "name": "게시판 본인인증 정책",
                        "name_raw": {
                            "ko": "게시판 본인인증 정책",
                            "en": "Board Identity Policies"
                        },
                        "description": "게시판 컨텍스트의 본인인증 정책 관리 권한",
                        "description_raw": {
                            "ko": "게시판 컨텍스트의 본인인증 정책 관리 권한",
                            "en": "Manage identity verification policies in board context"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-board",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 3,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 106,
                        "parent_id": 3,
                        "identifier": "sirsoft-board.reports",
                        "name": "게시판 신고 관리",
                        "name_raw": {
                            "ko": "게시판 신고 관리",
                            "en": "Report Management"
                        },
                        "description": "게시판 신고 관리 권한",
                        "description_raw": {
                            "ko": "게시판 신고 관리 권한",
                            "en": "Board report management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-board",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 4,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    }
                ]
            },
            {
                "id": 21,
                "parent_id": null,
                "identifier": "sirsoft-ecommerce",
                "name": "이커머스",
                "name_raw": {
                    "ko": "이커머스",
                    "en": "Ecommerce"
                },
                "description": "이커머스 모듈 권한",
                "description_raw": {
                    "ko": "이커머스 모듈 권한",
                    "en": "Ecommerce module permissions"
                },
                "extension_type": "module",
                "extension_identifier": "sirsoft-ecommerce",
                "resource_route_key": null,
                "owner_key": null,
                "order": 100,
                "is_assigned": true,
                "is_assignable": false,
                "children": [
                    {
                        "id": 22,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.products",
                        "name": "상품 관리",
                        "name_raw": {
                            "ko": "상품 관리",
                            "en": "Product Management"
                        },
                        "description": "상품 관리 권한",
                        "description_raw": {
                            "ko": "상품 관리 권한",
                            "en": "Product management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 1,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 27,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.orders",
                        "name": "주문 관리",
                        "name_raw": {
                            "ko": "주문 관리",
                            "en": "Order Management"
                        },
                        "description": "주문 관리 권한",
                        "description_raw": {
                            "ko": "주문 관리 권한",
                            "en": "Order management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 2,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 30,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.categories",
                        "name": "카테고리 관리",
                        "name_raw": {
                            "ko": "카테고리 관리",
                            "en": "Category Management"
                        },
                        "description": "카테고리 관리 권한",
                        "description_raw": {
                            "ko": "카테고리 관리 권한",
                            "en": "Category management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 3,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 35,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.brands",
                        "name": "브랜드 관리",
                        "name_raw": {
                            "ko": "브랜드 관리",
                            "en": "Brand Management"
                        },
                        "description": "브랜드 관리 권한",
                        "description_raw": {
                            "ko": "브랜드 관리 권한",
                            "en": "Brand management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 4,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 40,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.product-notice-templates",
                        "name": "상품정보제공고시 관리",
                        "name_raw": {
                            "ko": "상품정보제공고시 관리",
                            "en": "Product Notice Template Management"
                        },
                        "description": "상품정보제공고시 템플릿 관리 권한",
                        "description_raw": {
                            "ko": "상품정보제공고시 템플릿 관리 권한",
                            "en": "Product notice template management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 5,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 45,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.product-common-infos",
                        "name": "공통정보 관리",
                        "name_raw": {
                            "ko": "공통정보 관리",
                            "en": "Product Common Info Management"
                        },
                        "description": "상품 공통정보 관리 권한",
                        "description_raw": {
                            "ko": "상품 공통정보 관리 권한",
                            "en": "Product common info management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 6,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 50,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.settings",
                        "name": "환경설정",
                        "name_raw": {
                            "ko": "환경설정",
                            "en": "Settings"
                        },
                        "description": "이커머스 환경설정 권한",
                        "description_raw": {
                            "ko": "이커머스 환경설정 권한",
                            "en": "Ecommerce settings permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 7,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 53,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.promotion-coupon",
                        "name": "쿠폰 관리",
                        "name_raw": {
                            "ko": "쿠폰 관리",
                            "en": "Coupon Management"
                        },
                        "description": "쿠폰 관리 권한",
                        "description_raw": {
                            "ko": "쿠폰 관리 권한",
                            "en": "Coupon management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 8,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 58,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.shipping-policies",
                        "name": "배송정책 관리",
                        "name_raw": {
                            "ko": "배송정책 관리",
                            "en": "Shipping Policy Management"
                        },
                        "description": "배송정책 관리 권한",
                        "description_raw": {
                            "ko": "배송정책 관리 권한",
                            "en": "Shipping policy management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 9,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 63,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.product-labels",
                        "name": "상품 라벨 관리",
                        "name_raw": {
                            "ko": "상품 라벨 관리",
                            "en": "Product Label Management"
                        },
                        "description": "상품 라벨 관리 권한",
                        "description_raw": {
                            "ko": "상품 라벨 관리 권한",
                            "en": "Product label management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 10,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 68,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.identity.policies",
                        "name": "이커머스 본인인증 정책",
                        "name_raw": {
                            "ko": "이커머스 본인인증 정책",
                            "en": "Ecommerce Identity Policies"
                        },
                        "description": "이커머스 컨텍스트의 본인인증 정책 관리 권한",
                        "description_raw": {
                            "ko": "이커머스 컨텍스트의 본인인증 정책 관리 권한",
                            "en": "Manage identity verification policies in ecommerce context"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 11,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 71,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.reviews",
                        "name": "리뷰 관리",
                        "name_raw": {
                            "ko": "리뷰 관리",
                            "en": "Review Management"
                        },
                        "description": "상품 리뷰 관리 권한",
                        "description_raw": {
                            "ko": "상품 리뷰 관리 권한",
                            "en": "Product review management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 12,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 75,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.inquiries",
                        "name": "문의 관리",
                        "name_raw": {
                            "ko": "문의 관리",
                            "en": "Inquiry Management"
                        },
                        "description": "상품 1:1 문의 관리 권한",
                        "description_raw": {
                            "ko": "상품 1:1 문의 관리 권한",
                            "en": "Product inquiry management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 13,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 78,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.dashboard",
                        "name": "대시보드",
                        "name_raw": {
                            "ko": "대시보드",
                            "en": "Dashboard"
                        },
                        "description": "관리자 대시보드 이커머스 영역 조회 권한",
                        "description_raw": {
                            "ko": "관리자 대시보드 이커머스 영역 조회 권한",
                            "en": "Admin dashboard commerce area view permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 14,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 80,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.user-products",
                        "name": "사용자 상품",
                        "name_raw": {
                            "ko": "사용자 상품",
                            "en": "User Products"
                        },
                        "description": "사용자 상품 접근 권한 (블랙컨슈머 차단용)",
                        "description_raw": {
                            "ko": "사용자 상품 접근 권한 (블랙컨슈머 차단용)",
                            "en": "User product access permissions (for blocking malicious consumers)"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 15,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 82,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.user-orders",
                        "name": "사용자 주문",
                        "name_raw": {
                            "ko": "사용자 주문",
                            "en": "User Orders"
                        },
                        "description": "사용자 주문 관련 권한 (블랙컨슈머 차단용)",
                        "description_raw": {
                            "ko": "사용자 주문 관련 권한 (블랙컨슈머 차단용)",
                            "en": "User order permissions (for blocking malicious consumers)"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 16,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 86,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.user-reviews",
                        "name": "사용자 리뷰",
                        "name_raw": {
                            "ko": "사용자 리뷰",
                            "en": "User Reviews"
                        },
                        "description": "사용자 리뷰 관련 권한 (블랙컨슈머 차단용)",
                        "description_raw": {
                            "ko": "사용자 리뷰 관련 권한 (블랙컨슈머 차단용)",
                            "en": "User review permissions (for blocking malicious consumers)"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 17,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 88,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.mileage",
                        "name": "마일리지 관리",
                        "name_raw": {
                            "ko": "마일리지 관리",
                            "en": "Mileage Management"
                        },
                        "description": "마일리지 내역 조회 및 수동 지급/차감 권한",
                        "description_raw": {
                            "ko": "마일리지 내역 조회 및 수동 지급/차감 권한",
                            "en": "Mileage history and manual grant/deduct permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 18,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 91,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.user-currency",
                        "name": "회원 결제 통화 관리",
                        "name_raw": {
                            "ko": "회원 결제 통화 관리",
                            "en": "User Currency Management"
                        },
                        "description": "관리자가 회원별 결제 통화를 변경하는 권한",
                        "description_raw": {
                            "ko": "관리자가 회원별 결제 통화를 변경하는 권한",
                            "en": "Permission for admins to change a user's payment currency"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 19,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 93,
                        "parent_id": 21,
                        "identifier": "sirsoft-ecommerce.user-shipping-country",
                        "name": "회원 배송국가 관리",
                        "name_raw": {
                            "ko": "회원 배송국가 관리",
                            "en": "User Shipping Country Management"
                        },
                        "description": "관리자가 회원별 배송국가를 변경하는 권한",
                        "description_raw": {
                            "ko": "관리자가 회원별 배송국가를 변경하는 권한",
                            "en": "Permission for admins to change a user's shipping country"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-ecommerce",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 20,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    }
                ]
            },
            {
                "id": 109,
                "parent_id": null,
                "identifier": "sirsoft-page",
                "name": "페이지",
                "name_raw": {
                    "ko": "페이지",
                    "en": "Page"
                },
                "description": "페이지 모듈 권한",
                "description_raw": {
                    "ko": "페이지 모듈 권한",
                    "en": "Page module permissions"
                },
                "extension_type": "module",
                "extension_identifier": "sirsoft-page",
                "resource_route_key": null,
                "owner_key": null,
                "order": 100,
                "is_assigned": true,
                "is_assignable": false,
                "children": [
                    {
                        "id": 110,
                        "parent_id": 109,
                        "identifier": "sirsoft-page.pages",
                        "name": "페이지 관리",
                        "name_raw": {
                            "ko": "페이지 관리",
                            "en": "Page Management"
                        },
                        "description": "페이지 관리 권한",
                        "description_raw": {
                            "ko": "페이지 관리 권한",
                            "en": "Page management permissions"
                        },
                        "extension_type": "module",
                        "extension_identifier": "sirsoft-page",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 1,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    }
                ]
            },
            {
                "id": 115,
                "parent_id": null,
                "identifier": "core",
                "name": "코어",
                "name_raw": {
                    "ko": "코어",
                    "en": "Core"
                },
                "description": "코어 시스템 권한",
                "description_raw": {
                    "ko": "코어 시스템 권한",
                    "en": "Core system permissions"
                },
                "extension_type": "core",
                "extension_identifier": "core",
                "resource_route_key": null,
                "owner_key": null,
                "order": 1,
                "is_assigned": true,
                "is_assignable": false,
                "children": [
                    {
                        "id": 116,
                        "parent_id": 115,
                        "identifier": "core.users",
                        "name": "사용자 관리",
                        "name_raw": {
                            "ko": "사용자 관리",
                            "en": "User Management"
                        },
                        "description": "사용자 관리 권한",
                        "description_raw": {
                            "ko": "사용자 관리 권한",
                            "en": "User management permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 1,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 121,
                        "parent_id": 115,
                        "identifier": "core.menus",
                        "name": "메뉴 관리",
                        "name_raw": {
                            "ko": "메뉴 관리",
                            "en": "Menu Management"
                        },
                        "description": "메뉴 관리 권한",
                        "description_raw": {
                            "ko": "메뉴 관리 권한",
                            "en": "Menu management permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 2,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 126,
                        "parent_id": 115,
                        "identifier": "core.modules",
                        "name": "모듈 관리",
                        "name_raw": {
                            "ko": "모듈 관리",
                            "en": "Module Management"
                        },
                        "description": "모듈 관리 권한",
                        "description_raw": {
                            "ko": "모듈 관리 권한",
                            "en": "Module management permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 3,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 131,
                        "parent_id": 115,
                        "identifier": "core.plugins",
                        "name": "플러그인 관리",
                        "name_raw": {
                            "ko": "플러그인 관리",
                            "en": "Plugin Management"
                        },
                        "description": "플러그인 관리 권한",
                        "description_raw": {
                            "ko": "플러그인 관리 권한",
                            "en": "Plugin management permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 4,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 137,
                        "parent_id": 115,
                        "identifier": "core.templates",
                        "name": "템플릿 관리",
                        "name_raw": {
                            "ko": "템플릿 관리",
                            "en": "Template Management"
                        },
                        "description": "템플릿 관리 권한",
                        "description_raw": {
                            "ko": "템플릿 관리 권한",
                            "en": "Template management permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 5,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 143,
                        "parent_id": 115,
                        "identifier": "core.permissions",
                        "name": "권한 관리",
                        "name_raw": {
                            "ko": "권한 관리",
                            "en": "Permission Management"
                        },
                        "description": "역할 및 권한 관리 권한",
                        "description_raw": {
                            "ko": "역할 및 권한 관리 권한",
                            "en": "Role and permission management permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 6,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 148,
                        "parent_id": 115,
                        "identifier": "core.notification-logs",
                        "name": "알림 발송 이력",
                        "name_raw": {
                            "ko": "알림 발송 이력",
                            "en": "Notification Logs"
                        },
                        "description": "알림 발송 이력 관리 권한",
                        "description_raw": {
                            "ko": "알림 발송 이력 관리 권한",
                            "en": "Notification log management permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 7,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 151,
                        "parent_id": 115,
                        "identifier": "core.notifications",
                        "name": "알림 (관리자)",
                        "name_raw": {
                            "ko": "알림 (관리자)",
                            "en": "Notifications (Admin)"
                        },
                        "description": "관리자용 알림 관리 권한 (관리자 화면에서 사용)",
                        "description_raw": {
                            "ko": "관리자용 알림 관리 권한 (관리자 화면에서 사용)",
                            "en": "Admin notification management permissions (used in admin UI)"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 8,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 155,
                        "parent_id": 115,
                        "identifier": "core.user-notifications",
                        "name": "알림 (사용자)",
                        "name_raw": {
                            "ko": "알림 (사용자)",
                            "en": "Notifications (User)"
                        },
                        "description": "사용자용 알림 권한 (사용자 화면에서 본인 알림 관리)",
                        "description_raw": {
                            "ko": "사용자용 알림 권한 (사용자 화면에서 본인 알림 관리)",
                            "en": "User notification permissions (managing own notifications in user UI)"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 8,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 159,
                        "parent_id": 115,
                        "identifier": "core.identity",
                        "name": "본인인증 (사용자)",
                        "name_raw": {
                            "ko": "본인인증 (사용자)",
                            "en": "Identity Verification (User)"
                        },
                        "description": "로그인 사용자의 본인인증 challenge 요청/검증/취소 권한",
                        "description_raw": {
                            "ko": "로그인 사용자의 본인인증 challenge 요청/검증/취소 권한",
                            "en": "Permissions for authenticated users to request/verify/cancel IDV challenges"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 8,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 163,
                        "parent_id": 115,
                        "identifier": "core.admin.identity",
                        "name": "본인인증 관리 (관리자)",
                        "name_raw": {
                            "ko": "본인인증 관리 (관리자)",
                            "en": "Identity Verification Management (Admin)"
                        },
                        "description": "관리자용 IDV 프로바이더/정책/로그 관리 권한",
                        "description_raw": {
                            "ko": "관리자용 IDV 프로바이더/정책/로그 관리 권한",
                            "en": "Admin permissions for IDV providers, policies, and logs"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 8,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 172,
                        "parent_id": 115,
                        "identifier": "core.settings",
                        "name": "환경설정",
                        "name_raw": {
                            "ko": "환경설정",
                            "en": "Settings"
                        },
                        "description": "시스템 환경설정 권한",
                        "description_raw": {
                            "ko": "시스템 환경설정 권한",
                            "en": "System settings permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 8,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 175,
                        "parent_id": 115,
                        "identifier": "core.dashboard",
                        "name": "대시보드",
                        "name_raw": {
                            "ko": "대시보드",
                            "en": "Dashboard"
                        },
                        "description": "대시보드 접근 권한",
                        "description_raw": {
                            "ko": "대시보드 접근 권한",
                            "en": "Dashboard access permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 9,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 181,
                        "parent_id": 115,
                        "identifier": "core.activities",
                        "name": "활동 로그",
                        "name_raw": {
                            "ko": "활동 로그",
                            "en": "Activity Logs"
                        },
                        "description": "활동 로그 조회 권한",
                        "description_raw": {
                            "ko": "활동 로그 조회 권한",
                            "en": "Activity log access permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 10,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 184,
                        "parent_id": 115,
                        "identifier": "core.attachments",
                        "name": "첨부파일 관리",
                        "name_raw": {
                            "ko": "첨부파일 관리",
                            "en": "Attachment Management"
                        },
                        "description": "첨부파일 관리 권한",
                        "description_raw": {
                            "ko": "첨부파일 관리 권한",
                            "en": "Attachment management permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 11,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 188,
                        "parent_id": 115,
                        "identifier": "core.schedules",
                        "name": "스케줄 관리",
                        "name_raw": {
                            "ko": "스케줄 관리",
                            "en": "Schedule Management"
                        },
                        "description": "스케줄 작업 관리 권한",
                        "description_raw": {
                            "ko": "스케줄 작업 관리 권한",
                            "en": "Schedule task management permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 12,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    },
                    {
                        "id": 194,
                        "parent_id": 115,
                        "identifier": "core.language_packs",
                        "name": "언어팩 관리",
                        "name_raw": {
                            "ko": "언어팩 관리",
                            "en": "Language Pack Management"
                        },
                        "description": "언어팩 설치/제거/활성화 권한",
                        "description_raw": {
                            "ko": "언어팩 설치/제거/활성화 권한",
                            "en": "Language pack install/uninstall/activation permissions"
                        },
                        "extension_type": "core",
                        "extension_identifier": "core",
                        "resource_route_key": null,
                        "owner_key": null,
                        "order": 13,
                        "is_assigned": true,
                        "is_assignable": false,
                        "scope_type": null
                    }
                ]
            }
        ],
        "created_at": "2026-07-08 10:41:24",
        "updated_at": "2026-07-08 10:41:24",
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true,
            "can_toggle_status": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.permissions.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**

단일 역할의 상세 정보를 조회한다. 역할 편집 화면과 복제(clone_from) 시 원본 값을 채우는 데 사용된다. 목록 응답과 달리 permissions 관계를 pivot(scope_type)과 함께 로드하므로 `permission_ids`·`permission_values`·`permissions`(계층 트리)와 `users_count` 가 항상 포함된다. `core.permissions.read` 권한이 필요하다.


### PUT /api/admin/roles/{role}
<!-- @generated:start:api.admin.roles.update -->
- **라우트명**: `api.admin.roles.update`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\RoleController@update`
- **인증/권한**: `auth:sanctum` + `permission:core.permissions.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| role | path | string | 예 | — | 대상 role의 식별자 |
| name | body | string | 예 | — | 대상의 이름/명칭 |
| description | body | string | 아니오 | — | 설명 |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| permissions | body | array | 아니오 | — | 역할에 부여할 권한 목록. 각 원소는 `{id, scope_type}` (id=권한 식별자, scope_type=적용 범위: null 전체 / role 역할 범위 / self 본인 범위). 전달된 목록 기준으로 역할의 권한 집합이 재설정됨 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.role.update_validation_rules`).

**요청 예시**

```http
PUT /api/admin/roles/4 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": "예시 이름",
    "description": "예시 내용입니다.",
    "is_active": true,
    "permissions": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `4` | 기본 키 (내부 식별자) |
| identifier | string | `apidoc-sample-role` | 역할명 (예: admin, user, manager) |
| name | string | `실측 예시값` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| name_raw | object | `{"ko":"실측 예시값","en":"실측 예시값","fr":"실측 예시값"}` | `name` 의 원본 값 (현재 로케일 미해석 원본 JSON/문자열) |
| description | string | `실측 예시값` | 설명 (다국어 필드는 로케일별 값 객체) |
| description_raw | object | `{"ko":"실측 예시값","en":"실측 예시값","fr":"실측 예시값"}` | `description` 의 원본 값 (현재 로케일 미해석 원본 JSON/문자열) |
| extension_type | null | `null` | 이 리소스를 소유한 확장의 타입 (core/module/plugin/template) |
| extension_identifier | null | `null` | 이 리소스를 소유한 확장의 식별자 |
| extension_name | null | `null` | 이 리소스를 소유한 확장의 표시 이름 (manifest name) |
| is_deletable | boolean | `true` | deletable 여부 |
| is_active | boolean | `true` | active 여부 |
| created_at | string | `2026-07-08 10:41:24` | 생성 일시 |
| updated_at | string | `2026-07-08 12:14:48` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true,"c…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "역할이 성공적으로 수정되었습니다.",
    "data": {
        "id": 4,
        "identifier": "apidoc-sample-role",
        "name": "실측 예시값",
        "name_raw": {
            "ko": "실측 예시값",
            "en": "실측 예시값",
            "fr": "실측 예시값"
        },
        "description": "실측 예시값",
        "description_raw": {
            "ko": "실측 예시값",
            "en": "실측 예시값",
            "fr": "실측 예시값"
        },
        "extension_type": null,
        "extension_identifier": null,
        "extension_name": null,
        "is_deletable": true,
        "is_active": true,
        "created_at": "2026-07-08 10:41:24",
        "updated_at": "2026-07-08 12:14:48",
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true,
            "can_toggle_status": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.permissions.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**

기존 역할의 `name`·`description`·`is_active`·`permissions` 를 수정한다. 생성과 달리 `identifier` 는 변경 대상이 아니며, 각 필드는 `sometimes` 규칙이라 전달된 항목만 갱신된다. `permissions` 를 보내면 `[{id, scope_type}]` 형식으로 역할의 권한 집합 전체가 동기화된다(전달된 목록 기준으로 재설정). `name`·`description` 은 문자열/다국어 객체 양쪽을 받는다. 검증 규칙은 `core.role.update_validation_rules` 필터 훅으로 확장할 수 있다. `core.permissions.update` 권한이 필요하다.


### PATCH /api/admin/roles/{role}/toggle-status
<!-- @generated:start:api.admin.roles.toggle-status -->
- **라우트명**: `api.admin.roles.toggle-status`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\RoleController@toggleStatus`
- **인증/권한**: `auth:sanctum` + `permission:core.permissions.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| role | path | string | 예 | — | 대상 role의 식별자 |

**요청 예시**

```http
PATCH /api/admin/roles/4/toggle-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `4` | 기본 키 (내부 식별자) |
| identifier | string | `apidoc-sample-role` | 역할명 (예: admin, user, manager) |
| name | string | `API 문서 샘플 역할` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| name_raw | object | `{"ko":"API 문서 샘플 역할","en":"API Doc Sample Role"}` | `name` 의 원본 값 (현재 로케일 미해석 원본 JSON/문자열) |
| description | string | `문서 실측용 역할` | 설명 (다국어 필드는 로케일별 값 객체) |
| description_raw | object | `{"ko":"문서 실측용 역할","en":"Sample role for API docs"}` | `description` 의 원본 값 (현재 로케일 미해석 원본 JSON/문자열) |
| extension_type | null | `null` | 이 리소스를 소유한 확장의 타입 (core/module/plugin/template) |
| extension_identifier | null | `null` | 이 리소스를 소유한 확장의 식별자 |
| extension_name | null | `null` | 이 리소스를 소유한 확장의 표시 이름 (manifest name) |
| is_deletable | boolean | `true` | deletable 여부 |
| is_active | boolean | `false` | active 여부 |
| users_count | integer | `1` | users 개수 (집계) |
| created_at | string | `2026-07-08 10:41:24` | 생성 일시 |
| updated_at | string | `2026-07-08 12:14:48` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true,"c…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "역할이 성공적으로 수정되었습니다.",
    "data": {
        "id": 4,
        "identifier": "apidoc-sample-role",
        "name": "API 문서 샘플 역할",
        "name_raw": {
            "ko": "API 문서 샘플 역할",
            "en": "API Doc Sample Role"
        },
        "description": "문서 실측용 역할",
        "description_raw": {
            "ko": "문서 실측용 역할",
            "en": "Sample role for API docs"
        },
        "extension_type": null,
        "extension_identifier": null,
        "extension_name": null,
        "is_deletable": true,
        "is_active": false,
        "users_count": 1,
        "created_at": "2026-07-08 10:41:24",
        "updated_at": "2026-07-08 12:14:48",
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true,
            "can_toggle_status": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.permissions.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**

역할의 `is_active` 상태를 반대로 토글한다(활성↔비활성). 목록 화면의 상태 스위치에서 호출되며, 별도 본문 없이 대상 역할만 지정하면 된다. 성공 시 사용자 수를 다시 집계한 갱신된 역할 리소스를 반환한다. `core.permissions.update` 권한이 필요하다.


