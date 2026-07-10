# Notification Logs API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Notification Logs 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/notification-logs
<!-- @generated:start:api.admin.notification-logs.index -->
- **라우트명**: `api.admin.notification-logs.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationLogController@index`
- **인증/권한**: `auth:sanctum` + `permission:core.notification-logs.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| sender_user_id | query | integer | 아니오 | — | sender user 식별자 |
| recipient_user_id | query | integer | 아니오 | — | recipient user 식별자 |
| search | query | string | 아니오 | max 255 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| channel | query | string | 아니오 | max 50 | 발송 채널 필터 (해당 채널로 발송된 이력만 조회 — mail, database, fcm 등) |
| notification_type | query | string | 아니오 | max 100 | 알림 타입 필터 (해당 타입의 이력만 조회 — welcome, order_confirmed 등) |
| extension_type | query | string | 아니오 | `core`, `module`, `plugin` | 확장 유형 (core/module/plugin/template) |
| status | query | string | 아니오 | — | 상태 필터 (해당 상태의 항목만 조회) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| sort_by | query | string | 아니오 | `id`, `channel`, `notification_type`, `status`, `sent_at`, `created_at` | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.notification_log.filter_index_rules`).

**요청 예시**

```http
GET /api/admin/notification-logs?sender_user_id=1&recipient_user_id=1&search=%EC%98%88%EC%8B%9C%EA%B0%92&channel=%EC%98%88%EC%8B%9C%EA%B0%92&notification_type=%EC%98%88%EC%8B%9C%EA%B0%92&extension_type=core&status=%EC%98%88%EC%8B%9C%EA%B0%92&per_page=1&sort_by=id&sort_order=asc HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `677` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `677` | 기본 키 (내부 식별자) |
| channel | string | `mail` | 채널: mail, database, fcm 등 |
| notification_type | string | `apidoc.sample.event` | 알림 타입: welcome, order_confirmed 등 |
| extension_type | string | `core` | 확장 타입: core, module, plugin |
| extension_identifier | string | `` | 확장 식별자 |
| recipient_user_id | integer | `166` | recipient user 식별자 (연관 리소스 참조) |
| recipient_identifier | string | `apidoc-sample-user@example.com` | 수신자 식별자 (채널별: 이메일, 디바이스토큰, user_id 등) |
| recipient_name | string | `API 문서 샘플 사용자` | 수신자 표시명 (발송 시점 스냅샷) |
| sender_user_id | integer | `166` | sender user 식별자 (연관 리소스 참조) |
| sender | object | `{"uuid":"a231747f-e82e-4cf2-9ae1-a261849dce40","name":"AP…` | 발신자 사용자 객체 (uuid/name/email — senderUser 관계 파생) |
| recipient | object | `{"uuid":"a231747f-e82e-4cf2-9ae1-a261849dce40","name":"AP…` | 수신자 사용자 객체 (uuid/name/email — recipientUser 관계 파생) |
| subject | string | `API 문서 샘플 알림` | 렌더링된 제목 |
| body | string | `문서 실측용 알림 본문입니다.` | 렌더링된 본문 |
| status | string | `sent` | 상태: sent, failed, skipped |
| error_message | string | `해당 채널은 비회원 발송을 허용하지 않아 발송을 건너뛰었습니다.` | 에러 메시지 |
| source | string | `apidoc` | 발송 출처: notification, test_mail 등 |
| sent_at | string | `2026-07-06 18:20:23` | sent 일시 |
| created_at | string | `2026-07-06 19:20:23` | 생성 일시 |
| updated_at | string | `2026-07-06 19:20:23` | 최종 수정 일시 |
| abilities | object | `{"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "알림 발송 이력을 조회했습니다.",
    "data": {
        "data": [
            {
                "number": 1,
                "id": 1,
                "channel": "mail",
                "notification_type": "apidoc.sample.event",
                "extension_type": "core",
                "extension_identifier": "",
                "recipient_user_id": 6,
                "recipient_identifier": "apidoc-sample-user@example.com",
                "recipient_name": "API 문서 샘플 사용자",
                "sender_user_id": 6,
                "sender": {
                    "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                    "name": "API 문서 샘플 사용자",
                    "email": "apidoc-sample-user@example.com"
                },
                "recipient": {
                    "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                    "name": "API 문서 샘플 사용자",
                    "email": "apidoc-sample-user@example.com"
                },
                "subject": "API 문서 샘플 알림",
                "body": "문서 실측용 알림 본문입니다.",
                "status": "sent",
                "error_message": null,
                "source": "apidoc",
                "sent_at": "2026-07-08 09:41:24",
                "created_at": "2026-07-08 10:41:24",
                "updated_at": "2026-07-08 10:41:24",
                "abilities": {
                    "can_delete": true
                }
            }
        ],
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 1,
            "from": 1,
            "to": 1,
            "has_more_pages": false
        },
        "abilities": {
            "can_delete": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.notification-logs.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 알림 발송 이력을 페이지네이션으로 조회합니다. 인증(`auth:sanctum`)과 `core.notification-logs.read` 권한이 필요합니다. 발송자/수신자 ID, `search`, `channel`, `notification_type`, `extension_type`, `status` 로 필터링하고 `sort_by`/`sort_order` 로 정렬하며, 확장이 `core.notification_log.filter_index_rules` 훅으로 필터를 추가할 수 있습니다. 요청 사용자(`request->user()`)를 Service 에 전달해 열람 범위를 결정하며, 관리자 알림 발송 이력 화면을 렌더링할 때 사용합니다.


### POST /api/admin/notification-logs/bulk-delete
<!-- @generated:start:api.admin.notification-logs.bulk-destroy -->
- **라우트명**: `api.admin.notification-logs.bulk-destroy`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationLogController@bulkDestroy`
- **인증/권한**: `auth:sanctum` + `permission:core.notification-logs.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |

**요청 예시**

```http
POST /api/admin/notification-logs/bulk-delete HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        "예시값"
    ]
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
| 403 | Forbidden | 요구 권한(`core.notification-logs.delete`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 알림 발송 이력을 ID 배열(`ids`)로 다건 삭제하고 삭제 건수(`deleted_count`)를 반환합니다. 인증(`auth:sanctum`)과 `core.notification-logs.delete` 권한이 필요합니다. 복구 불가능한 삭제이므로 주의가 필요하며, 관리자가 목록에서 여러 이력을 선택해 일괄 정리할 때 사용합니다.


### DELETE /api/admin/notification-logs/{notificationLog}
<!-- @generated:start:api.admin.notification-logs.destroy -->
- **라우트명**: `api.admin.notification-logs.destroy`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationLogController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:core.notification-logs.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| notificationLog | path | string | 예 | — | 대상 notification log의 식별자 |

**요청 예시**

```http
DELETE /api/admin/notification-logs/1 HTTP/1.1
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
    "message": "알림 발송 이력이 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.notification-logs.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 단일 알림 발송 이력을 삭제합니다. 인증(`auth:sanctum`)과 `core.notification-logs.delete` 권한이 필요합니다. `notificationLog` 경로 파라미터로 대상을 지정하며, 복구 불가능한 삭제입니다. 관리자가 개별 발송 이력 한 건을 제거할 때 사용합니다.


