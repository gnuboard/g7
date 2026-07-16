# Permissions API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Permissions 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/permissions
<!-- @generated:start:api.admin.permissions.index -->
- **라우트명**: `api.admin.permissions.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PermissionController@index`
- **인증/권한**: `auth:sanctum` + `permission:core.permissions.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/permissions HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| admin | object | `{"label":"관리자 권한","icon":"cog","permissions":[{"id":1,"id…` | 관리자(admin) 타입 권한 그룹. `label`·`icon`(PermissionType::Admin 의 label()/icon() 산물)과 admin 타입으로 필터링된 권한 트리(`permissions`)를 담는다. |
| user | object | `{"label":"사용자 권한","icon":"user","permissions":[{"id":1,"i…` | 사용자(user) 타입 권한 그룹. `label`·`icon`(PermissionType::User 의 label()/icon() 산물)과 user 타입으로 필터링된 권한 트리(`permissions`)를 담는다. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "권한 정보를 성공적으로 가져왔습니다.",
    "data": {
        "data": {
            "permissions": {
                "admin": {
                    "label": "관리자 권한",
                    "icon": "cog",
                    "permissions": [
                        {
                            "id": 115,
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
                            "type": "admin",
                            "is_assignable": false,
                            "resource_route_key": null,
                            "owner_key": null,
                            "children": [
                                {
                                    "id": 116,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 117,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "user",
                                            "owner_key": "id",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 118,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 119,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "user",
                                            "owner_key": "id",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 120,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "user",
                                            "owner_key": "id",
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 4
                                },
                                {
                                    "id": 121,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 122,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "menu",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 123,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 124,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "menu",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 125,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "menu",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 4
                                },
                                {
                                    "id": 126,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 127,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 128,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 129,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 130,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 4
                                },
                                {
                                    "id": 131,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 132,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 133,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 134,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 135,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 136,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 5
                                },
                                {
                                    "id": 137,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 138,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 139,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 140,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 141,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 142,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 5
                                },
                                {
                                    "id": 143,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 144,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 145,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 146,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 147,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 4
                                },
                                {
                                    "id": 148,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 149,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 150,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 2
                                },
                                {
                                    "id": 151,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 152,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 153,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 154,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 3
                                },
                                {
                                    "id": 163,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 164,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 165,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 166,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 167,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 168,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 169,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 170,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 171,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 8
                                },
                                {
                                    "id": 172,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 173,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 174,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 2
                                },
                                {
                                    "id": 175,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 176,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 177,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 178,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 179,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "activityLog",
                                            "owner_key": "user_id",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 180,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 5
                                },
                                {
                                    "id": 181,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 182,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "activityLog",
                                            "owner_key": "user_id",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 183,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 2
                                },
                                {
                                    "id": 184,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 185,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 186,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "attachment",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 187,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "attachment",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 3
                                },
                                {
                                    "id": 188,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 189,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 190,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 191,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 192,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 193,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 5
                                },
                                {
                                    "id": 194,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 195,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 196,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 197,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 198,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 4
                                }
                            ],
                            "leaf_count": 60
                        },
                        {
                            "id": 1,
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
                            "type": "admin",
                            "is_assignable": false,
                            "resource_route_key": null,
                            "owner_key": null,
                            "children": [
                                {
                                    "id": 2,
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
                                    "type": "admin",
                                    "is_assignable": true,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [],
                                    "leaf_count": 1
                                }
                            ],
                            "leaf_count": 1
                        },
                        {
                            "id": 3,
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
                            "type": "admin",
                            "is_assignable": false,
                            "resource_route_key": null,
                            "owner_key": null,
                            "children": [
                                {
                                    "id": 4,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 5,
                                            "identifier": "sirsoft-board.apidoc-sample-board.admin.posts.read",
                                            "name": "게시글 조회 (관리자)",
                                            "name_raw": {
                                                "ko": "게시글 조회 (관리자)",
                                                "en": "View Posts (Admin)"
                                            },
                                            "description": "관리자 페이지에서 게시글 조회",
                                            "description_raw": {
                                                "ko": "관리자 페이지에서 게시글 조회",
                                                "en": "View posts in admin page"
                                            },
                                            "extension_type": "module",
                                            "extension_identifier": "sirsoft-board",
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "post",
                                            "owner_key": "user_id",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 6,
                                            "identifier": "sirsoft-board.apidoc-sample-board.admin.posts.write",
                                            "name": "게시글 작성/수정/삭제 (관리자)",
                                            "name_raw": {
                                                "ko": "게시글 작성/수정/삭제 (관리자)",
                                                "en": "Create/Edit/Delete Posts (Admin)"
                                            },
                                            "description": "관리자 페이지에서 게시글 작성 및 수정/삭제",
                                            "description_raw": {
                                                "ko": "관리자 페이지에서 게시글 작성 및 수정/삭제",
                                                "en": "Create and edit/delete posts in admin page"
                                            },
                                            "extension_type": "module",
                                            "extension_identifier": "sirsoft-board",
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 7,
                                            "identifier": "sirsoft-board.apidoc-sample-board.admin.posts.read-secret",
                                            "name": "비밀글 조회 (관리자)",
                                            "name_raw": {
                                                "ko": "비밀글 조회 (관리자)",
                                                "en": "View Secret Posts (Admin)"
                                            },
                                            "description": "관리자 페이지에서 비밀글 조회",
                                            "description_raw": {
                                                "ko": "관리자 페이지에서 비밀글 조회",
                                                "en": "View secret posts in admin page"
                                            },
                                            "extension_type": "module",
                                            "extension_identifier": "sirsoft-board",
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "post",
                                            "owner_key": "user_id",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 8,
                                            "identifier": "sirsoft-board.apidoc-sample-board.admin.comments.read",
                                            "name": "댓글 조회 (관리자)",
                                            "name_raw": {
                                                "ko": "댓글 조회 (관리자)",
                                                "en": "View Comments (Admin)"
                                            },
                                            "description": "관리자 페이지에서 댓글 조회",
                                            "description_raw": {
                                                "ko": "관리자 페이지에서 댓글 조회",
                                                "en": "View comments in admin page"
                                            },
                                            "extension_type": "module",
                                            "extension_identifier": "sirsoft-board",
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "comment",
                                            "owner_key": "user_id",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 9,
                                            "identifier": "sirsoft-board.apidoc-sample-board.admin.comments.write",
                                            "name": "댓글 작성/수정/삭제 (관리자)",
                                            "name_raw": {
                                                "ko": "댓글 작성/수정/삭제 (관리자)",
                                                "en": "Create/Edit/Delete Comments (Admin)"
                                            },
                                            "description": "관리자 페이지에서 댓글 작성 및 수정/삭제",
                                            "description_raw": {
                                                "ko": "관리자 페이지에서 댓글 작성 및 수정/삭제",
                                                "en": "Create and edit/delete comments in admin page"
                                            },
                                            "extension_type": "module",
                                            "extension_identifier": "sirsoft-board",
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 10,
                                            "identifier": "sirsoft-board.apidoc-sample-board.admin.attachments.upload",
                                            "name": "파일 업로드/삭제 (관리자)",
                                            "name_raw": {
                                                "ko": "파일 업로드/삭제 (관리자)",
                                                "en": "Upload/Delete Files (Admin)"
                                            },
                                            "description": "관리자 페이지에서 파일 업로드 및 삭제",
                                            "description_raw": {
                                                "ko": "관리자 페이지에서 파일 업로드 및 삭제",
                                                "en": "Upload and delete files in admin page"
                                            },
                                            "extension_type": "module",
                                            "extension_identifier": "sirsoft-board",
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "attachment",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 11,
                                            "identifier": "sirsoft-board.apidoc-sample-board.admin.attachments.download",
                                            "name": "파일 다운로드 (관리자)",
                                            "name_raw": {
                                                "ko": "파일 다운로드 (관리자)",
                                                "en": "Download Files (Admin)"
                                            },
                                            "description": "관리자 페이지에서 파일 다운로드",
                                            "description_raw": {
                                                "ko": "관리자 페이지에서 파일 다운로드",
                                                "en": "Download files in admin page"
                                            },
                                            "extension_type": "module",
                                            "extension_identifier": "sirsoft-board",
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "attachment",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 12,
                                            "identifier": "sirsoft-board.apidoc-sample-board.admin.manage",
                                            "name": "게시판 관리 (관리자)",
                                            "name_raw": {
                                                "ko": "게시판 관리 (관리자)",
                                                "en": "Manage Board (Admin)"
                                            },
                                            "description": "타인 게시글/댓글 관리, 블라인드, 복원, 공지 설정",
                                            "description_raw": {
                                                "ko": "타인 게시글/댓글 관리, 블라인드, 복원, 공지 설정",
                                                "en": "Manage others posts/comments, blind, restore, set notice"
                                            },
                                            "extension_type": "module",
                                            "extension_identifier": "sirsoft-board",
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "board",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 8
                                },
                                {
                                    "id": 95,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 96,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "board",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 97,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 98,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "board",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 99,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "board",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 4
                                },
                                {
                                    "id": 100,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 101,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 102,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 2
                                },
                                {
                                    "id": 103,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 104,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 105,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 2
                                },
                                {
                                    "id": 106,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 107,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "report",
                                            "owner_key": "reporter_id",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 108,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "report",
                                            "owner_key": "reporter_id",
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 2
                                }
                            ],
                            "leaf_count": 18
                        },
                        {
                            "id": 21,
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
                            "type": "admin",
                            "is_assignable": false,
                            "resource_route_key": null,
                            "owner_key": null,
                            "children": [
                                {
                                    "id": 22,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 23,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "product",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 24,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 25,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "product",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 26,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "product",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 4
                                },
                                {
                                    "id": 27,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 28,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "order",
                                            "owner_key": "user_id",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 29,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "order",
                                            "owner_key": "user_id",
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 2
                                },
                                {
                                    "id": 30,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 31,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 32,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 33,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 34,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 4
                                },
                                {
                                    "id": 35,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 36,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "brand",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 37,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 38,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "brand",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 39,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "brand",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 4
                                },
                                {
                                    "id": 40,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 41,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 42,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 43,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 44,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 4
                                },
                                {
                                    "id": 45,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 46,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 47,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 48,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 49,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 4
                                },
                                {
                                    "id": 50,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 51,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 52,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 2
                                },
                                {
                                    "id": 53,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 54,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "coupon",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 55,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 56,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "coupon",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 57,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "coupon",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 4
                                },
                                {
                                    "id": 58,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 59,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "shippingPolicy",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 60,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 61,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "shippingPolicy",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 62,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "shippingPolicy",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 4
                                },
                                {
                                    "id": 63,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 64,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 65,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 66,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 67,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 4
                                },
                                {
                                    "id": 68,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 69,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 70,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 2
                                },
                                {
                                    "id": 71,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 72,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "review",
                                            "owner_key": "user_id",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 73,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "review",
                                            "owner_key": "user_id",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 74,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "review",
                                            "owner_key": "user_id",
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 3
                                },
                                {
                                    "id": 75,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 76,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 77,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 2
                                },
                                {
                                    "id": 78,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 79,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 1
                                },
                                {
                                    "id": 88,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 89,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "mileage-transaction",
                                            "owner_key": "user_id",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 90,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "mileage-transaction",
                                            "owner_key": "user_id",
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 2
                                },
                                {
                                    "id": 91,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 92,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 1
                                },
                                {
                                    "id": 93,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 94,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 1
                                }
                            ],
                            "leaf_count": 48
                        },
                        {
                            "id": 109,
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
                            "type": "admin",
                            "is_assignable": false,
                            "resource_route_key": null,
                            "owner_key": null,
                            "children": [
                                {
                                    "id": 110,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 111,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "page",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 112,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 113,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "page",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 114,
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
                                            "type": "admin",
                                            "is_assignable": true,
                                            "resource_route_key": "page",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 4
                                }
                            ],
                            "leaf_count": 4
                        }
                    ]
                },
                "user": {
                    "label": "사용자 권한",
                    "icon": "user",
                    "permissions": [
                        {
                            "id": 115,
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
                            "type": "admin",
                            "is_assignable": false,
                            "resource_route_key": null,
                            "owner_key": null,
                            "children": [
                                {
                                    "id": 155,
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
                                    "type": "user",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 156,
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
                                            "type": "user",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 157,
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
                                            "type": "user",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 158,
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
                                            "type": "user",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 3
                                },
                                {
                                    "id": 159,
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
                                    "type": "user",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 160,
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
                                            "type": "user",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 161,
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
                                            "type": "user",
                                            "is_assignable": true,
                                            "resource_route_key": "challenge",
                                            "owner_key": "user_id",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 162,
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
                                            "type": "user",
                                            "is_assignable": true,
                                            "resource_route_key": "challenge",
                                            "owner_key": "user_id",
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 3
                                }
                            ],
                            "leaf_count": 6
                        },
                        {
                            "id": 3,
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
                            "type": "admin",
                            "is_assignable": false,
                            "resource_route_key": null,
                            "owner_key": null,
                            "children": [
                                {
                                    "id": 4,
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
                                    "type": "admin",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 13,
                                            "identifier": "sirsoft-board.apidoc-sample-board.posts.read",
                                            "name": "게시글 조회",
                                            "name_raw": {
                                                "ko": "게시글 조회",
                                                "en": "View Posts"
                                            },
                                            "description": "게시글 목록 및 상세 조회",
                                            "description_raw": {
                                                "ko": "게시글 목록 및 상세 조회",
                                                "en": "View post list and details"
                                            },
                                            "extension_type": "module",
                                            "extension_identifier": "sirsoft-board",
                                            "type": "user",
                                            "is_assignable": true,
                                            "resource_route_key": "post",
                                            "owner_key": "user_id",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 14,
                                            "identifier": "sirsoft-board.apidoc-sample-board.posts.write",
                                            "name": "게시글 작성/수정/삭제",
                                            "name_raw": {
                                                "ko": "게시글 작성/수정/삭제",
                                                "en": "Create/Edit/Delete Posts"
                                            },
                                            "description": "게시글 작성 및 본인 글 수정/삭제",
                                            "description_raw": {
                                                "ko": "게시글 작성 및 본인 글 수정/삭제",
                                                "en": "Create posts and edit/delete own posts"
                                            },
                                            "extension_type": "module",
                                            "extension_identifier": "sirsoft-board",
                                            "type": "user",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 15,
                                            "identifier": "sirsoft-board.apidoc-sample-board.posts.read-secret",
                                            "name": "비밀글 조회",
                                            "name_raw": {
                                                "ko": "비밀글 조회",
                                                "en": "View Secret Posts"
                                            },
                                            "description": "비밀글 조회",
                                            "description_raw": {
                                                "ko": "비밀글 조회",
                                                "en": "View secret posts"
                                            },
                                            "extension_type": "module",
                                            "extension_identifier": "sirsoft-board",
                                            "type": "user",
                                            "is_assignable": true,
                                            "resource_route_key": "post",
                                            "owner_key": "user_id",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 16,
                                            "identifier": "sirsoft-board.apidoc-sample-board.comments.read",
                                            "name": "댓글 조회",
                                            "name_raw": {
                                                "ko": "댓글 조회",
                                                "en": "View Comments"
                                            },
                                            "description": "댓글 목록 조회",
                                            "description_raw": {
                                                "ko": "댓글 목록 조회",
                                                "en": "View comment list"
                                            },
                                            "extension_type": "module",
                                            "extension_identifier": "sirsoft-board",
                                            "type": "user",
                                            "is_assignable": true,
                                            "resource_route_key": "comment",
                                            "owner_key": "user_id",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 17,
                                            "identifier": "sirsoft-board.apidoc-sample-board.comments.write",
                                            "name": "댓글 작성/수정/삭제",
                                            "name_raw": {
                                                "ko": "댓글 작성/수정/삭제",
                                                "en": "Create/Edit/Delete Comments"
                                            },
                                            "description": "댓글 작성 및 본인 댓글 수정/삭제",
                                            "description_raw": {
                                                "ko": "댓글 작성 및 본인 댓글 수정/삭제",
                                                "en": "Create comments and edit/delete own comments"
                                            },
                                            "extension_type": "module",
                                            "extension_identifier": "sirsoft-board",
                                            "type": "user",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 18,
                                            "identifier": "sirsoft-board.apidoc-sample-board.attachments.upload",
                                            "name": "파일 업로드/삭제",
                                            "name_raw": {
                                                "ko": "파일 업로드/삭제",
                                                "en": "Upload/Delete Files"
                                            },
                                            "description": "게시글에 파일 첨부 및 삭제",
                                            "description_raw": {
                                                "ko": "게시글에 파일 첨부 및 삭제",
                                                "en": "Attach and delete files to posts"
                                            },
                                            "extension_type": "module",
                                            "extension_identifier": "sirsoft-board",
                                            "type": "user",
                                            "is_assignable": true,
                                            "resource_route_key": "attachment",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 19,
                                            "identifier": "sirsoft-board.apidoc-sample-board.attachments.download",
                                            "name": "파일 다운로드",
                                            "name_raw": {
                                                "ko": "파일 다운로드",
                                                "en": "Download Files"
                                            },
                                            "description": "첨부파일 다운로드",
                                            "description_raw": {
                                                "ko": "첨부파일 다운로드",
                                                "en": "Download attached files"
                                            },
                                            "extension_type": "module",
                                            "extension_identifier": "sirsoft-board",
                                            "type": "user",
                                            "is_assignable": true,
                                            "resource_route_key": "attachment",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 20,
                                            "identifier": "sirsoft-board.apidoc-sample-board.manager",
                                            "name": "게시판 관리 (사용자)",
                                            "name_raw": {
                                                "ko": "게시판 관리 (사용자)",
                                                "en": "Manage Board (User)"
                                            },
                                            "description": "사용자 페이지에서 게시판 관리",
                                            "description_raw": {
                                                "ko": "사용자 페이지에서 게시판 관리",
                                                "en": "Manage board in user page"
                                            },
                                            "extension_type": "module",
                                            "extension_identifier": "sirsoft-board",
                                            "type": "user",
                                            "is_assignable": true,
                                            "resource_route_key": "board",
                                            "owner_key": "created_by",
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 8
                                }
                            ],
                            "leaf_count": 8
                        },
                        {
                            "id": 21,
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
                            "type": "admin",
                            "is_assignable": false,
                            "resource_route_key": null,
                            "owner_key": null,
                            "children": [
                                {
                                    "id": 80,
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
                                    "type": "user",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 81,
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
                                            "type": "user",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 1
                                },
                                {
                                    "id": 82,
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
                                    "type": "user",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 83,
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
                                            "type": "user",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 84,
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
                                            "type": "user",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        },
                                        {
                                            "id": 85,
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
                                            "type": "user",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 3
                                },
                                {
                                    "id": 86,
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
                                    "type": "user",
                                    "is_assignable": false,
                                    "resource_route_key": null,
                                    "owner_key": null,
                                    "children": [
                                        {
                                            "id": 87,
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
                                            "type": "user",
                                            "is_assignable": true,
                                            "resource_route_key": null,
                                            "owner_key": null,
                                            "children": [],
                                            "leaf_count": 1
                                        }
                                    ],
                                    "leaf_count": 1
                                }
                            ],
                            "leaf_count": 5
                        }
                    ]
                }
            },
            "types": [
                "admin",
                "user"
            ],
            "0": "... (총 4건 중 2건 표시)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.permissions.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

시스템의 전체 권한을 계층형 트리로 조회한다. 역할 생성·편집 화면(`admin_role_form.json`)의 권한 선택 트리를 채우는 데 사용된다. 권한은 모듈 → 카테고리 → 개별 권한 순으로 중첩되며(각 노드의 `children`), 코어 권한이 먼저 오도록 정렬된다. 응답의 `permissions` 는 권한 타입별(admin/user)로 그룹화되고, 각 그룹은 `label`·`icon` 메타와 필터링된 권한 트리를 담는다. 함께 반환되는 `types`(권한 타입 목록), `default_type`(기본 탭), `scope_options`(scope_type 선택지: 전체/역할/본인)는 편집 UI 구성에 쓰인다. 리프 노드만 실제 부여 가능한 권한(`is_assignable`)이다. `core.permissions.read` 권한이 필요하다.


