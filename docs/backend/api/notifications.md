# Notifications API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Notifications 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/notifications
<!-- @generated:start:api.admin.notifications.index -->
- **라우트명**: `api.admin.notifications.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationController@index`
- **인증/권한**: `auth:sanctum` + `permission:core.notifications.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| read | query | string | 아니오 | `unread`, `read`, `all` | 읽음 상태 필터 (unread: 미읽음(`read_at` null)만, read: 읽음(`read_at` not null)만, all: 전체). 미지정 시 전체 |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.notification.index_validation_rules`).

**요청 예시**

```http
GET /api/admin/notifications?read=unread&per_page=1&page=1&sort_order=asc HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `92` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | string | `02ee1121-ec63-4812-b77a-a0ffb4040acb` | 기본 키 (내부 식별자) |
| type | string | `new_order_admin` | 알림 유형 식별자 (알림 정의의 type — 발송 트리거를 구분) |
| type_label | string | `신규 주문 관리자 알림` | `type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| subject | string | `새로운 주문이 접수되었습니다` | 알림 제목 (알림 템플릿의 subject 를 변수 치환해 렌더한 문자열) |
| body | string | `소경은님이 주문번호 20260713-0038038875 (결제금액:…` | 알림 본문 (알림 템플릿의 body 를 변수 치환해 렌더한 문자열) |
| url | string | `https://g7.dev/admin/ecommerce/orders…` | 알림 클릭 시 이동할 URL (템플릿의 click_url 우선, 없으면 메일 CTA action_url) |
| data | object | `{"type":"new_order_admin","subject":"새로운 주문이 접수되었습니다","bo…` | 알림 원본 페이로드 객체 (type/subject/body/click_url 과 발송 시 전달된 변수 data 를 포함) |
| read_at | null | `null` | read 일시 |
| created_at | string | `2026-07-13 09:38:04` | 생성 일시 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "알림 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "number": 23,
                "id": "518b9c94-5853-4b68-8193-83af1851bbc6",
                "type": "inquiry_received",
                "type_label": "상품 문의 접수",
                "subject": "새로운 상품 문의가 접수되었습니다",
                "body": "옥혜진님이 \"면 손수건 3매입 #1\" 상품에 문의를 남겼습니다.",
                "url": "https://api.example.com/admin/ecommerce/product-inquiries",
                "data": {
                    "type": "inquiry_received",
                    "subject": "새로운 상품 문의가 접수되었습니다",
                    "body": "옥혜진님이 \"면 손수건 3매입 #1\" 상품에 문의를 남겼습니다.",
                    "click_url": "https://api.example.com/admin/ecommerce/product-inquiries",
                    "data": {
                        "name": "최고관리자",
                        "app_name": "그누보드7",
                        "product_name": "면 손수건 3매입 #1",
                        "customer_name": "옥혜진",
                        "inquiry_content": "타인 계정 문의 3 입니다. 스코프 격리 검증용.",
                        "inquiry_url": "https://api.example.com/admin/ecommerce/product-inquiries",
                        "site_url": "https://api.example.com"
                    }
                },
                "read_at": null,
                "created_at": "2026-07-31 21:41:58"
            },
            {
                "number": 22,
                "id": "75da60b8-3ec9-457f-ad8c-b52652f7adcf",
                "type": "inquiry_received",
                "type_label": "상품 문의 접수",
                "subject": "새로운 상품 문의가 접수되었습니다",
                "body": "옥혜진님이 \"면 손수건 3매입 #1\" 상품에 문의를 남겼습니다.",
                "url": "https://api.example.com/admin/ecommerce/product-inquiries",
                "data": {
                    "type": "inquiry_received",
                    "subject": "새로운 상품 문의가 접수되었습니다",
                    "body": "옥혜진님이 \"면 손수건 3매입 #1\" 상품에 문의를 남겼습니다.",
                    "click_url": "https://api.example.com/admin/ecommerce/product-inquiries",
                    "data": {
                        "name": "최고관리자",
                        "app_name": "그누보드7",
                        "product_name": "면 손수건 3매입 #1",
                        "customer_name": "옥혜진",
                        "inquiry_content": "타인 계정 문의 2 입니다. 스코프 격리 검증용.",
                        "inquiry_url": "https://api.example.com/admin/ecommerce/product-inquiries",
                        "site_url": "https://api.example.com"
                    }
                },
                "read_at": null,
                "created_at": "2026-07-31 21:41:55"
            },
            "... (총 23건 중 2건 표시)"
        ],
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 23,
            "from": 1,
            "to": 23,
            "has_more_pages": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.notifications.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

인증된 관리자 본인의 사이트내 알림 목록을 최신순(`created_at desc`)으로 페이지네이션해 반환합니다. `read` 파라미터로 미읽음(`unread`)·읽음(`read`)·전체(`all`, 기본)를 필터링하고, `per_page` 미지정 시 15건 단위로 반환합니다. 조회 대상은 항상 요청 사용자 본인의 알림으로 한정되며, 다른 사용자의 알림은 조회되지 않습니다. `_admin_base.json` 헤더의 알림 벨이 이 엔드포인트를 auto_fetch 로 소비하며 WebSocket 알림 수신 시 갱신됩니다. 항목 필드는 `id`, `type`, `type_label`, `subject`, `body`, `url`, `read_at`, `created_at` 로 구성됩니다.


### DELETE /api/admin/notifications/all
<!-- @generated:start:api.admin.notifications.destroy-all -->
- **라우트명**: `api.admin.notifications.destroy-all`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationController@destroyAll`
- **인증/권한**: `auth:sanctum` + `permission:core.notifications.delete`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
DELETE /api/admin/notifications/all HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted_count | integer | `23` | deleted 개수 (집계) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "모든 알림이 삭제되었습니다.",
    "data": {
        "deleted_count": 23
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.notifications.delete`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

인증된 관리자 본인의 모든 사이트내 알림(읽음·미읽음 무관)을 삭제합니다. 삭제 대상은 요청 사용자 본인의 알림으로만 한정되며, 응답 `data.deleted_count` 에 삭제된 건수를 반환합니다. 되돌릴 수 없는 작업이므로 UI 에서 확인 절차를 거친 뒤 호출합니다.


### POST /api/admin/notifications/read-all
<!-- @generated:start:api.admin.notifications.read-all -->
- **라우트명**: `api.admin.notifications.read-all`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationController@markAllAsRead`
- **인증/권한**: `auth:sanctum` + `permission:core.notifications.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/notifications/read-all HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| marked_count | integer | `7` | marked 개수 (집계) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "모든 알림을 읽음 처리했습니다.",
    "data": {
        "marked_count": 7
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.notifications.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

인증된 관리자 본인의 미읽음 알림을 모두 읽음 처리합니다. 처리 대상 `read_at` 을 현재 시각으로 갱신하며, 응답 `data.marked_count` 에 읽음 처리된 건수를 반환합니다. 이미 읽음 상태인 알림은 대상에서 제외됩니다.


### POST /api/admin/notifications/read-batch
<!-- @generated:start:api.admin.notifications.read-batch -->
- **라우트명**: `api.admin.notifications.read-batch`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationController@markBatchAsRead`
- **인증/권한**: `auth:sanctum` + `permission:core.notifications.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1, max 100 | 대상 리소스 식별자 배열 (대량 작업 대상) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.notification.batch_read_validation_rules`).

**요청 예시**

```http
POST /api/admin/notifications/read-batch HTTP/1.1
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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| marked_count | integer | `2` | 실제로 읽음 처리된 알림 건수 (요청한 `ids` 중 본인 소유이면서 미읽음 상태였던 항목만 집계) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "알림을 읽음 처리했습니다.",
    "data": {
        "marked_count": 2
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.notifications.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

`ids` 배열로 지정한 알림들을 일괄 읽음 처리합니다. 처리 대상은 요청 사용자 본인의 미읽음 알림 중 지정된 ID 에 해당하는 것으로 한정되며, 이미 읽음 상태이거나 본인 소유가 아닌 ID 는 무시됩니다. 응답 `data.marked_count` 에 실제 읽음 처리된 건수를 반환합니다. `ids` 는 최소 1개, 최대 100개까지 전달할 수 있습니다.


### GET /api/admin/notifications/unread-count
<!-- @generated:start:api.admin.notifications.unread-count -->
- **라우트명**: `api.admin.notifications.unread-count`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationController@unreadCount`
- **인증/권한**: `auth:sanctum` + `permission:core.notifications.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/notifications/unread-count HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| unread_count | integer | `7` | unread 개수 (집계) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "미읽음 알림 수를 조회했습니다.",
    "data": {
        "unread_count": 7
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.notifications.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

인증된 관리자 본인의 미읽음 알림 개수를 집계해 `data.unread_count` 로 반환합니다. `_admin_base.json` 헤더 알림 벨의 미읽음 배지에 사용되며, WebSocket 으로 새 알림이 수신되면 이 값을 재조회해 갱신합니다.


### DELETE /api/admin/notifications/{notification}
<!-- @generated:start:api.admin.notifications.destroy -->
- **라우트명**: `api.admin.notifications.destroy`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:core.notifications.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| notification | path | string | 예 | — | 대상 notification의 식별자 |

**요청 예시**

```http
DELETE /api/admin/notifications/{notification} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — `data` 는 `null`)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "알림이 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.notifications.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

경로의 `{notification}` ID 에 해당하는 알림 1건을 삭제합니다. 대상은 요청 사용자 본인의 알림으로 한정되며, 해당 ID 가 본인 소유로 존재하지 않으면 404(`notification.user.not_found`)를 반환합니다.


### PATCH /api/admin/notifications/{notification}/read
<!-- @generated:start:api.admin.notifications.read -->
- **라우트명**: `api.admin.notifications.read`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationController@markAsRead`
- **인증/권한**: `auth:sanctum` + `permission:core.notifications.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| notification | path | string | 예 | — | 대상 notification의 식별자 |

**요청 예시**

```http
PATCH /api/admin/notifications/{notification}/read HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (읽음 처리 후 갱신된 알림 1건 — `UserNotificationResource`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | string | `02ee1121-ec63-4812-b77a-a0ffb4040acb` | 알림 기본 키 (UUID) |
| type | string | `new_order_admin` | 알림 유형 식별자 (알림 정의의 type — 발송 트리거를 구분) |
| type_label | string | `신규 주문 관리자 알림` | `type` 값의 사람이 읽는 라벨 (알림 정의의 현지화 이름, 정의 미존재 시 빈 문자열) |
| subject | string | `새로운 주문이 접수되었습니다` | 알림 제목 (알림 템플릿의 subject 를 변수 치환해 렌더한 문자열) |
| body | string | `소경은님이 주문번호 20260713-0038038875 (결제금액:…` | 알림 본문 (알림 템플릿의 body 를 변수 치환해 렌더한 문자열) |
| url | string \| null | `https://g7.dev/admin/ecommerce/orders/1` | 알림 클릭 시 이동할 URL (템플릿의 `click_url` 우선, 없으면 메일 CTA `action_url`, 둘 다 없으면 `null`) |
| data | object | `{"type":"new_order_admin","subject":"...","body":"...","click_url":"..."}` | 알림 원본 페이로드 객체 (type/subject/body/click_url 과 발송 시 전달된 변수 data 를 포함) |
| read_at | string \| null | `2026-07-13 09:40:11` | 읽음 처리 일시 (사용자 타임존 기준 `Y-m-d H:i:s`) — 이 엔드포인트 성공 시 항상 값이 채워짐 |
| created_at | string \| null | `2026-07-13 09:38:04` | 알림 생성 일시 (사용자 타임존 기준 `Y-m-d H:i:s`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "알림을 읽음 처리했습니다.",
    "data": {
        "id": "02ee1121-ec63-4812-b77a-a0ffb4040acb",
        "type": "new_order_admin",
        "type_label": "신규 주문 관리자 알림",
        "subject": "새로운 주문이 접수되었습니다",
        "body": "소경은님이 주문번호 20260713-0038038875 주문을 접수했습니다.",
        "url": "https://g7.dev/admin/ecommerce/orders/1",
        "data": {
            "type": "new_order_admin",
            "subject": "새로운 주문이 접수되었습니다",
            "body": "소경은님이 주문번호 20260713-0038038875 주문을 접수했습니다.",
            "click_url": "https://g7.dev/admin/ecommerce/orders/1",
            "data": {
                "action_url": "https://g7.dev/admin/ecommerce/orders/1"
            }
        },
        "read_at": "2026-07-13 09:40:11",
        "created_at": "2026-07-13 09:38:04"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.notifications.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

경로의 `{notification}` ID 에 해당하는 알림 1건을 읽음 처리하고, 갱신된 알림 리소스를 `data` 로 반환합니다. 대상은 요청 사용자(관리자) 본인의 알림으로 한정되며, 본인 소유로 존재하지 않으면 404(`notification.user.not_found`)를 반환합니다. 반환 리소스에는 `id`, `type`, `type_label`, `subject`, `body`, `url`, `read_at`, `created_at` 가 포함됩니다.


### GET /api/user/notifications
<!-- @generated:start:api.user.notifications.index -->
- **라우트명**: `api.user.notifications.index`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\NotificationController@index`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.user-notifications.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| read | query | string | 아니오 | `unread`, `read`, `all` | 읽음 상태 필터 (unread: 미읽음(`read_at` null)만, read: 읽음(`read_at` not null)만, all: 전체). 미지정 시 전체 |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.notification.index_validation_rules`).

**요청 예시**

```http
GET /api/user/notifications?read=unread&per_page=1&page=1&sort_order=asc HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `92` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | string | `02ee1121-ec63-4812-b77a-a0ffb4040acb` | 기본 키 (내부 식별자) |
| type | string | `new_order_admin` | 알림 유형 식별자 (알림 정의의 type — 발송 트리거를 구분) |
| type_label | string | `신규 주문 관리자 알림` | `type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| subject | string | `새로운 주문이 접수되었습니다` | 알림 제목 (알림 템플릿의 subject 를 변수 치환해 렌더한 문자열) |
| body | string | `소경은님이 주문번호 20260713-0038038875 (결제금액:…` | 알림 본문 (알림 템플릿의 body 를 변수 치환해 렌더한 문자열) |
| url | string | `https://g7.dev/admin/ecommerce/orders…` | 알림 클릭 시 이동할 URL (템플릿의 click_url 우선, 없으면 메일 CTA action_url) |
| data | object | `{"type":"new_order_admin","subject":"새로운 주문이 접수되었습니다","bo…` | 알림 원본 페이로드 객체 (type/subject/body/click_url 과 발송 시 전달된 변수 data 를 포함) |
| read_at | null | `null` | read 일시 |
| created_at | string | `2026-07-13 09:38:04` | 생성 일시 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "알림 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "number": 23,
                "id": "518b9c94-5853-4b68-8193-83af1851bbc6",
                "type": "inquiry_received",
                "type_label": "상품 문의 접수",
                "subject": "새로운 상품 문의가 접수되었습니다",
                "body": "옥혜진님이 \"면 손수건 3매입 #1\" 상품에 문의를 남겼습니다.",
                "url": "https://api.example.com/admin/ecommerce/product-inquiries",
                "data": {
                    "type": "inquiry_received",
                    "subject": "새로운 상품 문의가 접수되었습니다",
                    "body": "옥혜진님이 \"면 손수건 3매입 #1\" 상품에 문의를 남겼습니다.",
                    "click_url": "https://api.example.com/admin/ecommerce/product-inquiries",
                    "data": {
                        "name": "최고관리자",
                        "app_name": "그누보드7",
                        "product_name": "면 손수건 3매입 #1",
                        "customer_name": "옥혜진",
                        "inquiry_content": "타인 계정 문의 3 입니다. 스코프 격리 검증용.",
                        "inquiry_url": "https://api.example.com/admin/ecommerce/product-inquiries",
                        "site_url": "https://api.example.com"
                    }
                },
                "read_at": null,
                "created_at": "2026-07-31 21:41:58"
            },
            {
                "number": 22,
                "id": "75da60b8-3ec9-457f-ad8c-b52652f7adcf",
                "type": "inquiry_received",
                "type_label": "상품 문의 접수",
                "subject": "새로운 상품 문의가 접수되었습니다",
                "body": "옥혜진님이 \"면 손수건 3매입 #1\" 상품에 문의를 남겼습니다.",
                "url": "https://api.example.com/admin/ecommerce/product-inquiries",
                "data": {
                    "type": "inquiry_received",
                    "subject": "새로운 상품 문의가 접수되었습니다",
                    "body": "옥혜진님이 \"면 손수건 3매입 #1\" 상품에 문의를 남겼습니다.",
                    "click_url": "https://api.example.com/admin/ecommerce/product-inquiries",
                    "data": {
                        "name": "최고관리자",
                        "app_name": "그누보드7",
                        "product_name": "면 손수건 3매입 #1",
                        "customer_name": "옥혜진",
                        "inquiry_content": "타인 계정 문의 2 입니다. 스코프 격리 검증용.",
                        "inquiry_url": "https://api.example.com/admin/ecommerce/product-inquiries",
                        "site_url": "https://api.example.com"
                    }
                },
                "read_at": null,
                "created_at": "2026-07-31 21:41:55"
            },
            "... (총 23건 중 2건 표시)"
        ],
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 23,
            "from": 1,
            "to": 23,
            "has_more_pages": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.user-notifications.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

인증된 사용자 본인의 사이트내 알림 목록을 최신순(`created_at desc`)으로 페이지네이션해 반환합니다. `read` 파라미터로 미읽음(`unread`)·읽음(`read`)·전체(`all`, 기본)를 필터링하고, `per_page` 미지정 시 20건 단위로 반환합니다. 조회 대상은 항상 요청 사용자 본인의 알림으로 한정됩니다. `_user_base.json` 이 이 엔드포인트를 소비하며 WebSocket 알림 수신 시 갱신됩니다. 관리자 스코프(`/api/admin/notifications`)와 동일한 서비스·리소스를 사용하되 권한(`core.user-notifications.*`)과 기본 페이지 크기(20건)가 다릅니다.


### DELETE /api/user/notifications/all
<!-- @generated:start:api.user.notifications.destroy-all -->
- **라우트명**: `api.user.notifications.destroy-all`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\NotificationController@destroyAll`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.user-notifications.delete`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
DELETE /api/user/notifications/all HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted_count | integer | `23` | deleted 개수 (집계) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "모든 알림이 삭제되었습니다.",
    "data": {
        "deleted_count": 23
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.user-notifications.delete`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

인증된 사용자 본인의 모든 사이트내 알림(읽음·미읽음 무관)을 삭제합니다. 삭제 대상은 요청 사용자 본인의 알림으로만 한정되며, 응답 `data.deleted_count` 에 삭제된 건수를 반환합니다. 되돌릴 수 없는 작업입니다.


### POST /api/user/notifications/read-all
<!-- @generated:start:api.user.notifications.read-all -->
- **라우트명**: `api.user.notifications.read-all`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\NotificationController@markAllAsRead`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.user-notifications.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/user/notifications/read-all HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| marked_count | integer | `7` | marked 개수 (집계) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "모든 알림을 읽음 처리했습니다.",
    "data": {
        "marked_count": 7
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.user-notifications.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

인증된 사용자 본인의 미읽음 알림을 모두 읽음 처리합니다. 처리 대상 `read_at` 을 현재 시각으로 갱신하며, 응답 `data.marked_count` 에 읽음 처리된 건수를 반환합니다. 이미 읽음 상태인 알림은 대상에서 제외됩니다.


### POST /api/user/notifications/read-batch
<!-- @generated:start:api.user.notifications.read-batch -->
- **라우트명**: `api.user.notifications.read-batch`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\NotificationController@markBatchAsRead`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.user-notifications.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1, max 100 | 대상 리소스 식별자 배열 (대량 작업 대상) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.notification.batch_read_validation_rules`).

**요청 예시**

```http
POST /api/user/notifications/read-batch HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
Content-Type: application/json

{
    "ids": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| marked_count | integer | `2` | 실제로 읽음 처리된 알림 건수 (요청한 `ids` 중 본인 소유이면서 미읽음 상태였던 항목만 집계) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "알림을 읽음 처리했습니다.",
    "data": {
        "marked_count": 2
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.user-notifications.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

`ids` 배열로 지정한 알림들을 일괄 읽음 처리합니다. 처리 대상은 요청 사용자 본인의 미읽음 알림 중 지정된 ID 에 해당하는 것으로 한정되며, 이미 읽음 상태이거나 본인 소유가 아닌 ID 는 무시됩니다. 응답 `data.marked_count` 에 실제 읽음 처리된 건수를 반환합니다. `ids` 는 최소 1개, 최대 100개까지 전달할 수 있습니다.


### GET /api/user/notifications/unread-count
<!-- @generated:start:api.user.notifications.unread-count -->
- **라우트명**: `api.user.notifications.unread-count`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\NotificationController@unreadCount`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.user-notifications.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/user/notifications/unread-count HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| unread_count | integer | `7` | unread 개수 (집계) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "미읽음 알림 수를 조회했습니다.",
    "data": {
        "unread_count": 7
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.user-notifications.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

인증된 사용자 본인의 미읽음 알림 개수를 집계해 `data.unread_count` 로 반환합니다. `_user_base.json` 의 알림 미읽음 배지에 사용되며, WebSocket 으로 새 알림이 수신되면 이 값을 재조회해 갱신합니다.


### DELETE /api/user/notifications/{notification}
<!-- @generated:start:api.user.notifications.destroy -->
- **라우트명**: `api.user.notifications.destroy`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\NotificationController@destroy`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.user-notifications.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| notification | path | string | 예 | — | 대상 notification의 식별자 |

**요청 예시**

```http
DELETE /api/user/notifications/{notification} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — `data` 는 `null`)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "알림이 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.user-notifications.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

경로의 `{notification}` ID 에 해당하는 알림 1건을 삭제합니다. 대상은 요청 사용자 본인의 알림으로 한정되며, 해당 ID 가 본인 소유로 존재하지 않으면 404(`notification.user.not_found`)를 반환합니다.


### PATCH /api/user/notifications/{notification}/read
<!-- @generated:start:api.user.notifications.read -->
- **라우트명**: `api.user.notifications.read`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\NotificationController@markAsRead`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.user-notifications.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| notification | path | string | 예 | — | 대상 notification의 식별자 |

**요청 예시**

```http
PATCH /api/user/notifications/{notification}/read HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (읽음 처리 후 갱신된 알림 1건 — `UserNotificationResource`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | string | `9f2c1a44-1b6e-4a55-8b0e-4c8a3f1d90ab` | 알림 기본 키 (UUID) |
| type | string | `order_shipped` | 알림 유형 식별자 (알림 정의의 type — 발송 트리거를 구분) |
| type_label | string | `배송 시작 알림` | `type` 값의 사람이 읽는 라벨 (알림 정의의 현지화 이름, 정의 미존재 시 빈 문자열) |
| subject | string | `주문하신 상품이 발송되었습니다` | 알림 제목 (알림 템플릿의 subject 를 변수 치환해 렌더한 문자열) |
| body | string | `주문번호 20260713-0038038875 상품이 발송되었습니다.` | 알림 본문 (알림 템플릿의 body 를 변수 치환해 렌더한 문자열) |
| url | string \| null | `https://g7.dev/mypage/orders/1` | 알림 클릭 시 이동할 URL (템플릿의 `click_url` 우선, 없으면 메일 CTA `action_url`, 둘 다 없으면 `null`) |
| data | object | `{"type":"order_shipped","subject":"...","body":"...","click_url":"..."}` | 알림 원본 페이로드 객체 (type/subject/body/click_url 과 발송 시 전달된 변수 data 를 포함) |
| read_at | string \| null | `2026-07-13 10:02:33` | 읽음 처리 일시 (사용자 타임존 기준 `Y-m-d H:i:s`) — 이 엔드포인트 성공 시 항상 값이 채워짐 |
| created_at | string \| null | `2026-07-13 09:58:12` | 알림 생성 일시 (사용자 타임존 기준 `Y-m-d H:i:s`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "알림을 읽음 처리했습니다.",
    "data": {
        "id": "9f2c1a44-1b6e-4a55-8b0e-4c8a3f1d90ab",
        "type": "order_shipped",
        "type_label": "배송 시작 알림",
        "subject": "주문하신 상품이 발송되었습니다",
        "body": "주문번호 20260713-0038038875 상품이 발송되었습니다.",
        "url": "https://g7.dev/mypage/orders/1",
        "data": {
            "type": "order_shipped",
            "subject": "주문하신 상품이 발송되었습니다",
            "body": "주문번호 20260713-0038038875 상품이 발송되었습니다.",
            "click_url": "https://g7.dev/mypage/orders/1",
            "data": {
                "action_url": "https://g7.dev/mypage/orders/1"
            }
        },
        "read_at": "2026-07-13 10:02:33",
        "created_at": "2026-07-13 09:58:12"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.user-notifications.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

경로의 `{notification}` ID 에 해당하는 알림 1건을 읽음 처리하고, 갱신된 알림 리소스를 `data` 로 반환합니다. 대상은 요청 사용자 본인의 알림으로 한정되며, 본인 소유로 존재하지 않으면 404(`notification.user.not_found`)를 반환합니다. 반환 리소스에는 `id`, `type`, `type_label`, `subject`, `body`, `url`, `read_at`, `created_at` 가 포함됩니다.


