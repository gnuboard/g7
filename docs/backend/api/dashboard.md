# Dashboard API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Dashboard 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/dashboard/activities
<!-- @generated:start:api.admin.dashboard.activities -->
- **라우트명**: `api.admin.dashboard.activities`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\DashboardController@activities`
- **인증/권한**: `auth:sanctum` + `permission:core.dashboard.activities`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/dashboard/activities HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| type | string | `user` | 활동 분류 (log_type Enum 값 — admin: 관리자, user: 사용자, system: 시스템) |
| icon | string | `circle-info` | 아이콘 식별자 (아이콘 클래스/이름) |
| icon_color | string | `green` | 분류별 색상 (log_type Enum variant() 파생 — admin: blue, user: green, system: gray) |
| title | string | `첨부파일 다운로드 (게시물: 237)` | 제목 |
| description | string | `API 문서 샘플 사용자` | 설명 (다국어 필드는 로케일별 값 객체) |
| time | string | `4시간 전` | 상대 시각 표시 (예: "24초 전" — diffForHumans() 산물) |
| timestamp | string | `2026-07-07T10:00:47+09:00` | 활동 발생 절대 시각 (created_at 을 사용자 타임존으로 변환한 ISO 8601) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "최근 활동을 성공적으로 조회했습니다.",
    "data": [
        {
            "type": "admin",
            "icon": "circle-info",
            "icon_color": "blue",
            "title": "사용자 목록 조회",
            "description": "API 문서 샘플 사용자",
            "time": "2분 전",
            "timestamp": "2026-07-08T12:12:12+09:00"
        },
        {
            "type": "admin",
            "icon": "circle-info",
            "icon_color": "blue",
            "title": "사용자 목록 조회",
            "description": "API 문서 샘플 사용자",
            "time": "6분 전",
            "timestamp": "2026-07-08T12:08:26+09:00"
        },
        {
            "type": "admin",
            "icon": "circle-info",
            "icon_color": "blue",
            "title": "사용자 목록 조회",
            "description": "API 문서 샘플 사용자",
            "time": "9분 전",
            "timestamp": "2026-07-08T12:04:38+09:00"
        },
        {
            "type": "admin",
            "icon": "circle-info",
            "icon_color": "blue",
            "title": "사용자 목록 조회",
            "description": "API 문서 샘플 사용자",
            "time": "43분 전",
            "timestamp": "2026-07-08T11:31:03+09:00"
        },
        {
            "type": "admin",
            "icon": "circle-info",
            "icon_color": "blue",
            "title": "사용자 목록 조회",
            "description": "API 문서 샘플 사용자",
            "time": "45분 전",
            "timestamp": "2026-07-08T11:29:30+09:00"
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.dashboard.activities`)이 없는 경우 |

<!-- @generated:end -->

**설명** 관리자 대시보드에 표시할 최근 활동 내역(사용자 등록, 모듈 활성화 등)을 조회합니다. 인증(`auth:sanctum`)과 `core.dashboard.activities` 권한이 필요합니다. 각 항목은 유형·아이콘·제목·설명과 상대 시간(`time`)·절대 시각(`timestamp`)을 포함하며, 대시보드 최근 활동 카드를 렌더링할 때 사용합니다.


### GET /api/admin/dashboard/alerts
<!-- @generated:start:api.admin.dashboard.alerts -->
- **라우트명**: `api.admin.dashboard.alerts`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\DashboardController@alerts`
- **인증/권한**: `auth:sanctum` + `permission:core.dashboard.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/dashboard/alerts HTTP/1.1
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
    "message": "시스템 알림을 성공적으로 조회했습니다.",
    "data": []
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.dashboard.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** 시스템 업데이트·경고 등 관리자에게 알릴 시스템 알림 목록을 조회합니다. 인증(`auth:sanctum`)과 `core.dashboard.read` 권한이 필요합니다. 알릴 항목이 없으면 빈 목록을 반환하며(위 실측이 빈 상태였던 이유), 대시보드 상단 시스템 알림 영역을 렌더링할 때 사용합니다.


### GET /api/admin/dashboard/recent-notifications
<!-- @generated:start:api.admin.dashboard.recent-notifications -->
- **라우트명**: `api.admin.dashboard.recent-notifications`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\DashboardController@recentNotifications`
- **인증/권한**: `auth:sanctum` + `permission:core.notification-logs.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/dashboard/recent-notifications HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `677` | 기본 키 (내부 식별자) |
| type | string | `apidoc.sample.event` | 알림 유형 식별자 (notification_type — 발송을 유발한 알림 정의 키) |
| channel | string | `mail` | 발송 채널 (mail: 이메일, database: 인앱, sms 등 알림이 전달된 매체) |
| recipient | string | `API 문서 샘플 사용자` | 수신자 표시명 (recipientUser 관계의 name → recipient_name → recipient_identifier 순 폴백) |
| subject | string | `API 문서 샘플 알림` | 알림 제목 (subject 를 50자로 절삭한 값) |
| status | string | `sent` | 발송 상태 (status Enum 값 — sent: 발송 성공, failed: 발송 실패, skipped: 발송 건너뜀) |
| time | string | `19시간 전` | 상대 시각 표시 (예: "24초 전" — diffForHumans() 산물) |
| timestamp | string | `2026-07-06T18:20:23+09:00` | 발송 절대 시각 (sent_at, 없으면 created_at 을 사용자 타임존으로 변환한 ISO 8601) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "최근 알림을 성공적으로 조회했습니다.",
    "data": [
        {
            "id": 1,
            "type": "apidoc.sample.event",
            "channel": "mail",
            "recipient": "API 문서 샘플 사용자",
            "subject": "API 문서 샘플 알림",
            "status": "sent",
            "time": "2시간 전",
            "timestamp": "2026-07-08T09:41:24+09:00"
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.notification-logs.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** 대시보드 "최근 알림" 카드에 표시할 최근 알림 발송 이력을 조회합니다. 인증(`auth:sanctum`)과 `core.notification-logs.read` 권한이 필요합니다. 각 항목은 알림 타입·채널·수신자·제목·상태와 상대 시간(`time`)·절대 시각(`timestamp`)을 포함하며, 전체 이력 목록(notification-logs)의 요약 뷰를 대시보드에 노출할 때 사용합니다.


### GET /api/admin/dashboard/resources
<!-- @generated:start:api.admin.dashboard.resources -->
- **라우트명**: `api.admin.dashboard.resources`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\DashboardController@resources`
- **인증/권한**: `auth:sanctum` + `permission:core.dashboard.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/dashboard/resources HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| cpu | object | `{"percentage":6,"color":"green"}` | CPU 사용률 정보 (percentage: 0~100 사용률, color: 임계 색상 — green<50, blue 50~69, yellow 70~89, red≥90) |
| memory | object | `{"percentage":96,"used":"30.1 GB","total":"31.5 GB","colo…` | 메모리 사용량 정보 (percentage 사용률, used/total: 사용량·총량 형식화 문자열, color: 임계 색상). 수집 불가 시 percentage 0·"알 수 없음"·color gray 폴백 |
| disk | object | `{"percentage":76,"used":"360.2 GB","total":"474.7 GB","co…` | 디스크 사용량 정보 (percentage 사용률, used/total: 사용량·총량 형식화 문자열, color: 임계 색상). 수집 불가 시 percentage 0·"알 수 없음"·color gray 폴백 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "시스템 리소스 정보를 성공적으로 조회했습니다.",
    "data": {
        "cpu": {
            "percentage": 52,
            "color": "blue"
        },
        "memory": {
            "percentage": 86,
            "used": "27 GB",
            "total": "31.5 GB",
            "color": "yellow"
        },
        "disk": {
            "percentage": 76,
            "used": "362.6 GB",
            "total": "474.7 GB",
            "color": "yellow"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.dashboard.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** 서버의 CPU·메모리·디스크 사용량을 조회합니다. 인증(`auth:sanctum`)과 `core.dashboard.read` 권한이 필요합니다. 각 항목은 사용률(`percentage`)과 상태 색상(`color`), 메모리·디스크의 경우 사용량/총량 문자열을 포함하며, 대시보드 시스템 리소스 게이지를 렌더링할 때 사용합니다.


### GET /api/admin/dashboard/stats
<!-- @generated:start:api.admin.dashboard.stats -->
- **라우트명**: `api.admin.dashboard.stats`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\DashboardController@stats`
- **인증/권한**: `auth:sanctum` + `permission:core.dashboard.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/dashboard/stats HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| total_users | object | `{"count":156,"change_percent":15500,"change_display":"+15…` | 전체 사용자 수 (통계 객체는 count/추이 포함) |
| installed_modules | object | `{"total":3,"active":3}` | 설치된 모듈 집계 객체 (total/active) |
| active_plugins | object | `{"total":9,"active":9}` | 활성 플러그인 집계 객체 (total/active) |
| installed_templates | object | `{"total":2,"active":2}` | 설치된 템플릿 집계 객체 (total/active) |
| language_packs | object | `{"total":20,"active":16}` | 언어팩 집계 객체 (active: 현재 활성 언어팩 수, total: 활성 + 미설치 번들 팩 수) |
| system_status | object | `{"status":"normal","label":"정상","all_services_running":true}` | 시스템 상태 객체 (status: normal 정상 / warning 경고, label: 상태 다국어 라벨, all_services_running: 전체 서비스 정상 동작 여부) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "대시보드 통계를 성공적으로 조회했습니다.",
    "data": {
        "total_users": {
            "count": 2,
            "change_percent": 0,
            "change_display": "+2",
            "trend": "up"
        },
        "installed_modules": {
            "total": 3,
            "active": 3
        },
        "active_plugins": {
            "total": 9,
            "active": 0
        },
        "installed_templates": {
            "total": 2,
            "active": 0
        },
        "language_packs": {
            "total": 17,
            "active": 1
        },
        "system_status": {
            "status": "normal",
            "label": "정상",
            "all_services_running": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.dashboard.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** 대시보드 상단 통계 카드에 표시할 집계 데이터를 조회합니다. 인증(`auth:sanctum`)과 `core.dashboard.read` 권한이 필요합니다. 총 사용자 수(증감률 포함), 설치/활성 모듈·플러그인·템플릿·언어팩 수, 시스템 상태를 객체 형태로 반환하며, 대시보드 진입 시 요약 지표를 렌더링할 때 사용합니다.


