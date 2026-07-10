# Notification Definitions API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Notification Definitions 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
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
| is_active | query | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| sort_by | query | string | 아니오 | `id`, `type`, `extension_type`, `is_active`, `created_at`, `updated_at` | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.notification_definition.filter_index_rules`).

**요청 예시**

```http
GET /api/admin/notification-definitions?search=%EC%98%88%EC%8B%9C%EA%B0%92&extension_type=core&extension_identifier=example-key&channel=%EC%98%88%EC%8B%9C%EA%B0%92&is_active=1&per_page=1&sort_by=id&sort_order=asc HTTP/1.1
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
| type | string | `welcome` | 알림 타입 (welcome, order_confirmed 등) |
| hook_prefix | string | `core.auth` | 훅 접두사 (core.auth, sirsoft-ecommerce 등) |
| extension_type | string | `core` | 확장 타입: core, module, plugin |
| extension_identifier | string | `core` | 확장 식별자: core, sirsoft-board 등 |
| name | object | `{"ko":"회원가입 환영","en":"Welcome","ja":"会員登録 ウェルカム"}` | 다국어 이름 ({"ko": "회원가입 환영", "en": "Welcome"}) |
| description | object | `{"ko":"회원가입 완료 시 발송되는 환영 알림","en":"Welcome notification s…` | 다국어 설명 |
| variables | array | `[{"key":"name","description":"수신자 이름"},{"key":"app_name",…` | 사용 가능 변수 메타데이터 ([{key, description}]) |
| channels | array | `["mail","database"]` | 활성 채널 (["mail", "database"]) |
| hooks | array | `["core.auth.after_register"]` | 트리거 훅 목록 (["core.auth.after_register"]) |
| is_active | boolean | `true` | active 여부 |
| is_default | boolean | `true` | default 여부 |
| templates | array | `[{"id":1,"definition_id":1,"channel":"mail","subject":{"k…` | 채널별 알림 템플릿 목록 (templates 관계 로드 시 NotificationTemplateResource 배열, 미로드 시 null) |
| created_at | string | `2026-05-27 15:20:18` | 생성 일시 |
| updated_at | string | `2026-06-30 13:33:16` | 최종 수정 일시 |
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
                "updated_at": "2026-07-08 10:41:24",
                "abilities": {
                    "can_update": true,
                    "can_delete": true
                }
            },
            {
                "number": 2,
                "id": 2,
                "type": "order_confirmed",
                "hook_prefix": "sirsoft-ecommerce",
                "extension_type": "module",
                "extension_identifier": "sirsoft-ecommerce",
                "name": {
                    "ko": "결제 완료",
                    "en": "Payment Completed"
                },
                "description": {
                    "ko": "결제 완료 시 고객에게 발송",
                    "en": "Sent to customer when payment is completed"
                },
                "variables": [
                    {
                        "key": "name",
                        "description": "수신자 이름"
                    },
                    {
                        "key": "app_name",
                        "description": "사이트 이름"
                    },
                    {
                        "key": "order_number",
                        "description": "주문번호"
                    },
                    {
                        "key": "total_amount",
                        "description": "결제 금액"
                    },
                    {
                        "key": "order_url",
                        "description": "주문 상세 URL"
                    },
                    {
                        "key": "site_url",
                        "description": "사이트 URL"
                    },
                    {
                        "key": "shipping_recipient_name",
                        "description": "수취인 이름"
                    },
                    {
                        "key": "shipping_country_name",
                        "description": "배송국가명"
                    },
                    {
                        "key": "shipping_address",
                        "description": "배송지 주소"
                    }
                ],
                "channels": [
                    "mail",
                    "database"
                ],
                "hooks": [
                    "sirsoft-ecommerce.order.after_confirm"
                ],
                "is_active": true,
                "is_default": true,
                "templates": [
                    {
                        "id": 2,
                        "definition_id": 2,
                        "channel": "mail",
                        "subject": {
                            "ko": "[{app_name}] 결제가 완료되었습니다 (주문번호: {order_number})",
                            "en": "[{app_name}] Your payment has been completed (Order #{order_number})"
                        },
                        "body": {
                            "ko": "<div style=\"font-family:'Malgun Gothic',sans-serif;max-width:600px;margin:0 auto;padding:20px\"><h2 style=\"color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px\">결제 완료</h2><p style=\"color:#555;line-height:1.6\">{name}님, 결제가 완료되었습니다. 주문해 주셔서 감사합니다.</p><div style=\"background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0\"><p style=\"margin:5px 0\"><strong>주문번호:</strong> {order_number}</p><p style=\"margin:5px 0\"><strong>결제금액:</strong> {total_amount}</p><p style=\"margin:5px 0\"><strong>받는분:</strong> {shipping_recipient_name}</p><p style=\"margin:5px 0\"><strong>배송국가:</strong> {shipping_country_name}</p><p style=\"margin:5px 0\"><strong>배송지:</strong> {shipping_address}</p></div><p style=\"color:#555;line-height:1.6\">주문 상세 내용은 아래 버튼을 클릭하여 확인하실 수 있습니다.</p><div style=\"text-align:center;margin:25px 0\"><a href=\"{order_url}\" style=\"display:inline-block;padding:12px 30px;background-color:#4F46E5;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold\">주문 상세 보기</a></div><hr style=\"border:none;border-top:1px solid #eee;margin:20px 0\"><p style=\"color:#999;font-size:12px\">본 메일은 {app_name}에서 발송되었습니다.</p></div>",
                            "en": "<div style=\"font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px\"><h2 style=\"color:#333;border-bottom:2px solid #4F46E5;padding-bottom:10px\">Payment Completed</h2><p style=\"color:#555;line-height:1.6\">Dear {name}, your payment has been completed. Thank you for your order.</p><div style=\"background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0\"><p style=\"margin:5px 0\"><strong>Order Number:</strong> {order_number}</p><p style=\"margin:5px 0\"><strong>Total Amount:</strong> {total_amount}</p><p style=\"margin:5px 0\"><strong>Recipient:</strong> {shipping_recipient_name}</p><p style=\"margin:5px 0\"><strong>Shipping Country:</strong> {shipping_country_name}</p><p style=\"margin:5px 0\"><strong>Shipping Address:</strong> {shipping_address}</p></div><p style=\"color:#555;line-height:1.6\">Click the button below to view your order details.</p><div style=\"text-align:center;margin:25px 0\"><a href=\"{order_url}\" style=\"display:inline-block;padding:12px 30px;background-color:#4F46E5;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold\">View Order</a></div><hr style=\"border:none;border-top:1px solid #eee;margin:20px 0\"><p style=\"color:#999;font-size:12px\">This email was sent from {app_name}.</p></div>"
                        },
                        "click_url": null,
                        "recipients": [
                            {
                                "type": "trigger_user"
                            }
                        ],
                        "is_active": true,
                        "is_default": true,
                        "user_overrides": null,
                        "updated_by": null,
                        "created_at": "2026-07-08 10:43:32",
                        "updated_at": "2026-07-08 10:43:32",
                        "abilities": {
                            "can_update": true,
                            "can_delete": true
                        }
                    },
                    {
                        "id": 3,
                        "definition_id": 2,
                        "channel": "database",
                        "subject": {
                            "ko": "결제가 완료되었습니다",
                            "en": "Your payment has been completed"
                        },
                        "body": {
                            "ko": "{name}님, 주문번호 {order_number}의 결제가 완료되었습니다.",
                            "en": "{name}, payment for your order {order_number} has been completed."
                        },
                        "click_url": "{order_url}",
                        "recipients": [
                            {
                                "type": "trigger_user"
                            }
                        ],
                        "is_active": true,
                        "is_default": true,
                        "user_overrides": null,
                        "updated_by": null,
                        "created_at": "2026-07-08 10:43:32",
                        "updated_at": "2026-07-08 10:43:32",
                        "abilities": {
                            "can_update": true,
                            "can_delete": true
                        }
                    }
                ],
                "created_at": "2026-07-08 10:43:32",
                "updated_at": "2026-07-08 10:43:32",
                "abilities": {
                    "can_update": true,
                    "can_delete": true
                }
            },
            "... (총 18건 중 2건 표시)"
        ],
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 18,
            "from": 1,
            "to": 18,
            "has_more_pages": false
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

**설명** 등록된 알림 정의 목록을 페이지네이션으로 조회합니다. 인증(`auth:sanctum`)과 `core.settings.read` 권한이 필요합니다. `search`, `extension_type`, `extension_identifier`, `channel`, `is_active` 로 필터링하고 `sort_by`/`sort_order` 로 정렬하며, 확장이 `core.notification_definition.filter_index_rules` 훅으로 필터를 추가할 수 있습니다. 관리자 알림 정의 관리 목록 화면을 렌더링할 때 사용합니다.


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
GET /api/admin/notification-definitions/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `21` | 기본 키 (내부 식별자) |
| type | string | `apidoc-sample.event` | 알림 타입 (welcome, order_confirmed 등) |
| hook_prefix | string | `core` | 훅 접두사 (core.auth, sirsoft-ecommerce 등) |
| extension_type | string | `core` | 확장 타입: core, module, plugin |
| extension_identifier | string | `` | 확장 식별자: core, sirsoft-board 등 |
| name | object | `{"ko":"API 문서 샘플 알림","en":"API Doc Sample Notification"}` | 다국어 이름 ({"ko": "회원가입 환영", "en": "Welcome"}) |
| description | object | `{"ko":"문서 실측용 알림 정의","en":"Sample notification"}` | 다국어 설명 |
| variables | array | `[]` | 사용 가능 변수 메타데이터 ([{key, description}]) |
| channels | array | `["database","mail"]` | 활성 채널 (["mail", "database"]) |
| hooks | array | `[]` | 트리거 훅 목록 (["core.auth.after_register"]) |
| is_active | boolean | `true` | active 여부 |
| is_default | boolean | `false` | default 여부 |
| templates | array | `[{"id":41,"definition_id":21,"channel":"mail","subject":"…` | 채널별 알림 템플릿 목록 (templates 관계 로드 시 NotificationTemplateResource 배열, 미로드 시 null) |
| created_at | string | `2026-07-06 19:15:16` | 생성 일시 |
| updated_at | string | `2026-07-06 19:15:16` | 최종 수정 일시 |
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
        "updated_at": "2026-07-08 10:41:24",
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
| channels | body | array | 아니오 | min 1 | 활성 채널 목록 — 이 정의가 발송에 사용할 채널 배열 (각 원소 최대 50자, mail·database 등). 지정 시 최소 1개 필요 |
| hooks | body | array | 아니오 | — | 트리거 훅 목록 — 이 알림을 발송시키는 훅 이름 배열 (각 원소 최대 255자, core.auth.after_register 등) |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.notification_definition.filter_update_rules`).

**요청 예시**

```http
PUT /api/admin/notification-definitions/1 HTTP/1.1
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

**설명** 알림 정의의 활성 채널(`channels`), 트리거 훅(`hooks`), 활성 상태(`is_active`)를 수정합니다. 인증(`auth:sanctum`)과 `core.settings.update` 권한이 필요합니다. Service 계층에서 수정 후 템플릿을 다시 로드해 반환하며, 확장이 `core.notification_definition.filter_update_rules` 훅으로 추가 파라미터를 검증에 넣을 수 있습니다. 발송 채널 구성이나 훅 연결을 변경할 때 사용합니다.


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
POST /api/admin/notification-definitions/1/reset HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| type | string | `apidoc-sample.event` | 알림 타입 (welcome, order_confirmed 등) |
| hook_prefix | string | `core` | 훅 접두사 (core.auth, sirsoft-ecommerce 등) |
| extension_type | string | `core` | 이 리소스를 소유한 확장의 타입 (core/module/plugin/template) |
| extension_identifier | string | `` | 이 리소스를 소유한 확장의 식별자 |
| name | object | `{"ko":"API 문서 샘플 알림","en":"API Doc Sample Notification"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | object | `{"ko":"문서 실측용 알림 정의","en":"Sample notification"}` | 설명 (다국어 필드는 로케일별 값 객체) |
| variables | array | `[]` | 사용 가능 변수 메타데이터 ([{key, description}]) |
| channels | array | `["database","mail"]` | 활성 채널 (["mail", "database"]) |
| hooks | array | `[]` | 트리거 훅 목록 (["core.auth.after_register"]) |
| is_active | boolean | `true` | active 여부 |
| is_default | boolean | `true` | default 여부 |
| templates | array | `[{"id":1,"definition_id":1,"channel":"mail","subject":"AP…` | 템플릿 목록 (각 원소 identifier/name 등 — 템플릿 관계 파생) |
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
    "message": "알림 정의의 모든 템플릿이 기본값으로 복원되었습니다.",
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
        "is_default": true,
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
PATCH /api/admin/notification-definitions/1/toggle-active HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| type | string | `apidoc-sample.event` | 알림 타입 (welcome, order_confirmed 등) |
| hook_prefix | string | `core` | 훅 접두사 (core.auth, sirsoft-ecommerce 등) |
| extension_type | string | `core` | 이 리소스를 소유한 확장의 타입 (core/module/plugin/template) |
| extension_identifier | string | `` | 이 리소스를 소유한 확장의 식별자 |
| name | object | `{"ko":"API 문서 샘플 알림","en":"API Doc Sample Notification"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | object | `{"ko":"문서 실측용 알림 정의","en":"Sample notification"}` | 설명 (다국어 필드는 로케일별 값 객체) |
| variables | array | `[]` | 사용 가능 변수 메타데이터 ([{key, description}]) |
| channels | array | `["database","mail"]` | 활성 채널 (["mail", "database"]) |
| hooks | array | `[]` | 트리거 훅 목록 (["core.auth.after_register"]) |
| is_active | boolean | `false` | active 여부 |
| is_default | boolean | `false` | default 여부 |
| templates | null | `null` | 템플릿 목록 (각 원소 identifier/name 등 — 템플릿 관계 파생) |
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
    "message": "알림 정의 활성 상태가 변경되었습니다.",
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
        "is_active": false,
        "is_default": false,
        "templates": null,
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

**설명** 알림 정의의 활성 상태(`is_active`)를 현재 값의 반대로 토글합니다. 인증(`auth:sanctum`)과 `core.settings.update` 권한이 필요합니다. 비활성 정의는 해당 알림 발송이 중단되므로, 관리자가 목록에서 특정 알림을 켜거나 끄는 스위치 조작에 사용합니다.


