# Dashboard API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Dashboard 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
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
| icon | string | `right-to-bracket` | 아이콘 식별자 (아이콘 클래스/이름) |
| icon_color | string | `green` | 분류별 색상 (log_type Enum variant() 파생 — admin: blue, user: green, system: gray) |
| title | string | `관리자 로그인` | 제목 |
| description | string | `최고관리자` | 설명 (다국어 필드는 로케일별 값 객체) |
| time | string | `2시간 전` | 상대 시각 표시 (예: "24초 전" — diffForHumans() 산물) |
| timestamp | string | `2026-08-04T19:00:10+09:00` | 활동 발생 절대 시각 (created_at 을 사용자 타임존으로 변환한 ISO 8601) |

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
            "type": "user",
            "icon": "right-to-bracket",
            "icon_color": "green",
            "title": "관리자 로그인",
            "description": "최고관리자",
            "time": "2시간 전",
            "timestamp": "2026-08-04T19:00:10+09:00"
        },
        {
            "type": "admin",
            "icon": "pen-to-square",
            "icon_color": "blue",
            "title": "레이아웃 수정 (home)",
            "description": "최고관리자",
            "time": "4시간 전",
            "timestamp": "2026-08-04T17:40:37+09:00"
        },
        {
            "type": "admin",
            "icon": "circle-info",
            "icon_color": "blue",
            "title": "사용자 목록 조회",
            "description": "최고관리자",
            "time": "4시간 전",
            "timestamp": "2026-08-04T17:37:43+09:00"
        },
        {
            "type": "user",
            "icon": "right-to-bracket",
            "icon_color": "green",
            "title": "관리자 로그인",
            "description": "최고관리자",
            "time": "4시간 전",
            "timestamp": "2026-08-04T17:35:37+09:00"
        },
        {
            "type": "user",
            "icon": "circle-info",
            "icon_color": "green",
            "title": "주문 옵션 부분 취소 (옵션 ID: 1440)",
            "description": "최고관리자",
            "time": "4시간 전",
            "timestamp": "2026-08-04T17:23:44+09:00"
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

_목록 응답: `data` 배열 항목의 필드. `data` 는 `core.dashboard.alerts` 필터 훅의 결과이며, 알릴 항목이 없으면 빈 배열(`[]`)입니다. 코어 기본 리스너(`ExtensionCompatibilityAlertListener`)가 주입하는 항목의 필드는 다음과 같습니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | string | `compat_plugins_sirsoft-gdpr` | 알림 식별자 (`compat_{type}_{identifier}` = 자동 비활성화, `recover_{type}_{identifier}` = 재호환. 알림 닫기(dismiss) 상태 판정 키) |
| type | string | `warning` | 알림 등급 (`warning`: 코어 비호환 자동 비활성화, `info`: 재호환 복구 가능) |
| subtype | string | `incompatible_core` | 알림 세부 분류 (`incompatible_core`: 코어 버전 비호환으로 자동 비활성화됨, `recovery_available`: 코어 업그레이드 후 다시 활성화 가능) |
| icon | string | `exclamation-triangle` | 아이콘 식별자 (warning: `exclamation-triangle`, info: `check-circle`) |
| title | string | `플러그인 "sirsoft-gdpr" 자동 비활성화됨` | 알림 제목 (다국어 문구 — `extensions.alerts.incompatible_deactivated` / `recovered_title`) |
| message | string | `필요 버전: 7.0.0-beta.9, 현재 설치됨: 7.0.0-beta.8` | 알림 본문 (다국어 문구 — `extensions.alerts.incompatible_message` / `recovered_body`) |
| extension_type | string | `plugin` | 대상 확장 종류 (`module` / `plugin` / `template`) |
| identifier | string | `sirsoft-gdpr` | 대상 확장 식별자 |
| recover_endpoint | string | `/api/admin/extensions/plugin/sirsoft-gdpr/recover` | 재활성화 호출 엔드포인트 (재호환 알림 `subtype=recovery_available` 에만 존재) |
| time | string\|null | `3시간 전` | 자동 비활성화 시각의 상대 표시 (diffForHumans() 산물, 비활성화 시각이 없으면 `null`) |
| read | boolean | `false` | 읽음 여부 (주입 시점에는 항상 `false`) |

**응답 예시**

```http
HTTP/1.1 200
```

알릴 항목이 없는 경우 (기본):

```json
{
    "success": true,
    "message": "시스템 알림을 성공적으로 조회했습니다.",
    "data": []
}
```

코어 비호환으로 자동 비활성화된 확장이 있는 경우:

```json
{
    "success": true,
    "message": "시스템 알림을 성공적으로 조회했습니다.",
    "data": [
        {
            "id": "compat_plugins_sirsoft-gdpr",
            "type": "warning",
            "subtype": "incompatible_core",
            "icon": "exclamation-triangle",
            "title": "플러그인 \"sirsoft-gdpr\" 자동 비활성화됨",
            "message": "필요 버전: 7.0.0-beta.9, 현재 설치됨: 7.0.0-beta.8",
            "extension_type": "plugin",
            "identifier": "sirsoft-gdpr",
            "time": "3시간 전",
            "read": false
        },
        {
            "id": "recover_modules_sirsoft-board",
            "type": "info",
            "subtype": "recovery_available",
            "icon": "check-circle",
            "title": "모듈 \"sirsoft-board\" 다시 호환 가능",
            "message": "코어 업그레이드 후 호환됩니다 (이전 요구: 7.0.0-beta.9). 다시 활성화할 수 있습니다.",
            "extension_type": "module",
            "identifier": "sirsoft-board",
            "recover_endpoint": "/api/admin/extensions/module/sirsoft-board/recover",
            "time": "2일 전",
            "read": false
        }
    ]
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
| id | integer | `7559` | 기본 키 (내부 식별자) |
| type | string | `apidoc.sample.event` | 알림 유형 식별자 (notification_type — 발송을 유발한 알림 정의 키) |
| channel | string | `mail` | 발송 채널 (mail: 이메일, database: 인앱, sms 등 알림이 전달된 매체) |
| recipient | string | `API 문서 샘플 사용자` | 수신자 표시명 (recipientUser 관계의 name → recipient_name → recipient_identifier 순 폴백) |
| subject | string | `API 문서 샘플 알림` | 알림 제목 (subject 를 50자로 절삭한 값) |
| status | string | `sent` | 발송 상태 (status Enum 값 — sent: 발송 성공, failed: 발송 실패, skipped: 발송 건너뜀) |
| time | string | `3일 전` | 상대 시각 표시 (예: "24초 전" — diffForHumans() 산물) |
| timestamp | string | `2026-07-31T21:55:00+09:00` | 발송 절대 시각 (sent_at, 없으면 created_at 을 사용자 타임존으로 변환한 ISO 8601) |

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
            "id": 7559,
            "type": "apidoc.sample.event",
            "channel": "mail",
            "recipient": "API 문서 샘플 사용자",
            "subject": "API 문서 샘플 알림",
            "status": "sent",
            "time": "3일 전",
            "timestamp": "2026-07-31T21:55:00+09:00"
        },
        {
            "id": 7558,
            "type": "inquiry_received",
            "channel": "mail",
            "recipient": "송민성",
            "subject": "",
            "status": "skipped",
            "time": "4일 전",
            "timestamp": "2026-07-31T21:41:59+09:00"
        },
        {
            "id": 7557,
            "type": "inquiry_received",
            "channel": "mail",
            "recipient": "석지은",
            "subject": "",
            "status": "skipped",
            "time": "4일 전",
            "timestamp": "2026-07-31T21:41:59+09:00"
        },
        {
            "id": 7556,
            "type": "inquiry_received",
            "channel": "mail",
            "recipient": "심상수",
            "subject": "",
            "status": "skipped",
            "time": "4일 전",
            "timestamp": "2026-07-31T21:41:59+09:00"
        },
        {
            "id": 7555,
            "type": "inquiry_received",
            "channel": "mail",
            "recipient": "임아름",
            "subject": "",
            "status": "skipped",
            "time": "4일 전",
            "timestamp": "2026-07-31T21:41:59+09:00"
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
| cpu | object | `{"percentage":21,"color":"green"}` | CPU 사용률 정보 (percentage: 0~100 사용률, color: 임계 색상 — green<50, blue 50~69, yellow 70~89, red≥90) |
| memory | object | `{"percentage":77,"used":"24.3 GB","total":"31.5 GB","colo…` | 메모리 사용량 정보 (percentage 사용률, used/total: 사용량·총량 형식화 문자열, color: 임계 색상). 수집 불가 시 percentage 0·"알 수 없음"·color gray 폴백 |
| disk | object | `{"percentage":86,"used":"408.2 GB","total":"474.7 GB","co…` | 디스크 사용량 정보 (percentage 사용률, used/total: 사용량·총량 형식화 문자열, color: 임계 색상). 수집 불가 시 percentage 0·"알 수 없음"·color gray 폴백 |

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
            "percentage": 21,
            "color": "green"
        },
        "memory": {
            "percentage": 77,
            "used": "24.3 GB",
            "total": "31.5 GB",
            "color": "yellow"
        },
        "disk": {
            "percentage": 86,
            "used": "408.2 GB",
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
| total_users | object | `{"count":82,"change_percent":0,"change_display":"+82","tr…` | 전체 사용자 수 (통계 객체는 count/추이 포함) |
| installed_modules | object | `{"total":4,"active":4}` | 설치된 모듈 집계 객체 (total/active) |
| active_plugins | object | `{"total":8,"active":8}` | 활성 플러그인 집계 객체 (total/active) |
| installed_templates | object | `{"total":2,"active":2}` | 설치된 템플릿 집계 객체 (total/active) |
| language_packs | object | `{"total":22,"active":2}` | 언어팩 집계 객체 (active: 현재 활성 언어팩 수, total: 활성 + 미설치 번들 팩 수) |
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
            "count": 82,
            "change_percent": 0,
            "change_display": "+82",
            "trend": "up"
        },
        "installed_modules": {
            "total": 4,
            "active": 4
        },
        "active_plugins": {
            "total": 8,
            "active": 8
        },
        "installed_templates": {
            "total": 2,
            "active": 2
        },
        "language_packs": {
            "total": 22,
            "active": 2
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


