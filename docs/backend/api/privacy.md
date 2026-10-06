# Privacy API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 개인정보 조치 대상(보유기간 파기·휴면 단계)의 명단을 한 페이지씩 돌려주는 관리자 API 입니다
2. 명단은 건수 미리보기·배치 커맨드와 같은 쿼리에서 나옵니다 — 모달에서 본 명단 = 배치가 처리할 명단
3. 기능이 꺼져 있어도 저장된 기간값 기준으로 돌려줍니다 (켜기 전에 확인하는 자리)
4. 권한: 공급자가 선언한 「설정 읽기 + 도메인 읽기」를 요청 시점에 검사 (없으면 403)
5. 확장 핸들러는 core.privacy.retention_handlers 로 등록만 하면 retention-targets/{handler} 에 자동으로 붙습니다
6. 직권 실행(manual-runs): 설정 화면의 「지금 실행」이 큐에 접수(202)하고, 상태 API 가 진행 상태·마지막 결과를 돌려줍니다
7. 직권 실행 권한은 대상이 선언한 「설정 수정 + 도메인 수정」(전체 범위) — 자동 실행 토글과 무관하게 처리합니다. 단 비밀번호 만료(password_expiry:*)는 꺼져 있으면 접수하지 않습니다(409)
8. 방문자 동의 스냅샷(GET /api/consent)은 [consent.md](consent.md) 에 있습니다
```

---

### GET /api/admin/privacy/retention-targets/{handler}
<!-- @generated:start:api.admin.privacy.retention-targets -->
- **라우트명**: `api.admin.privacy.retention-targets`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PrivacyTargetController@retention`
- **인증/권한**: `auth:sanctum` + `admin` + 핸들러가 선언한 권한(`targetPermissions()`, 모두 필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| handler | path | string | 예 | 등록된 핸들러 식별자 | 보유기간 핸들러 식별자 (예: `user-withdrawn`, `sirsoft-ecommerce.order`, `sirsoft-board.ip`, `sirsoft-board.guest`) |
| page | query | integer | 아니오 | 1 이상 | 페이지 번호 (기본 1) |
| per_page | query | integer | 아니오 | 1~100 | 페이지 크기 (기본 20) |

**요청 예시**

```http
GET /api/admin/privacy/retention-targets/user-withdrawn?page=1&per_page=20 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| data | array | `[{…}]` | 대상 행 목록 — 기준일 오름차순(가장 먼저 대상이 된 것부터) |
| data[].id | integer\|string | `12` | 행 식별자 |
| data[].title | string | `"홍길동"` | 첫 열 — 회원 이름 · 주문번호 · 게시글 제목 등 |
| data[].subtitle | string\|null | `"user@example.com"` | 둘째 열 — 이메일 · 주문자 · 작성자 등 |
| data[].context | string\|null | `null` | 셋째 열 — 게시판 이름 · 글/댓글 구분 등 |
| data[].date | string\|null | `"2025-05-10"` | 기준일(사용자 시간대 `Y-m-d`) — 탈퇴일 · 종결일 · 작성일 |
| data[].url | string\|null | `"/admin/users/9f…"` | 상세 화면 경로 (새 탭으로 연다) |
| columns | object | `{"title": "이름", "subtitle": "이메일", "date": "탈퇴일"}` | 열 이름 => 라벨. 없는 열은 화면이 그리지 않는다 |
| pagination | object | — | 표준 페이지 메타(`current_page`·`per_page`·`from`·`to`·`has_more_pages`·`last_page`·`total`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "data": [
            {
                "id": 12,
                "title": "홍길동_탈퇴_20250101",
                "subtitle": "gildong_withdrawn_20250101@example.com",
                "context": null,
                "date": "2025-01-01",
                "url": "/admin/users/9f0c1b2e-4a57-4b8e-9d0a-6f3e2c1d0a11"
            }
        ],
        "columns": {
            "title": "이름",
            "subtitle": "이메일",
            "date": "탈퇴일"
        },
        "pagination": {
            "current_page": 1,
            "per_page": 20,
            "from": 1,
            "to": 1,
            "has_more_pages": false,
            "last_page": 1,
            "total": 1
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 관리자가 아니거나, 핸들러가 선언한 권한(설정 읽기 + 도메인 읽기) 중 하나라도 없는 경우 |
| 404 | Not Found | 등록되지 않은 핸들러 식별자 (확장이 비활성이면 그 핸들러는 등록되지 않는다) |
| 422 | Unprocessable Entity | `page`·`per_page` 가 범위를 벗어난 경우 |
| 500 | Server Error | 핸들러의 목록 조회가 실패한 경우 (로그 참조) |

<!-- @generated:end -->

**설명**

관리자 설정 화면의 「대상 N건」 배지 옆 「더보기」 모달이 무한스크롤로 한 페이지씩 부릅니다. 명단은 그 핸들러의 건수(`preview()`)·실행(`process()`)과 **같은 조건식**에서 나오므로, 모달에서 본 명단이 곧 다음 배치가 처리할 명단입니다.

코어는 핸들러를 모른 채 식별자로 찾습니다 — 확장이 `core.privacy.retention_handlers` 필터로 핸들러를 등록하면 코어 코드 수정 없이 이 API 가 그 확장의 명단도 내려줍니다. 명단에는 이름·이메일 같은 개인정보가 그대로 나오므로, 권한은 라우트가 아니라 **핸들러가 선언한 권한 목록**(예: 이커머스는 `sirsoft-ecommerce.settings.read` + `sirsoft-ecommerce.orders.read`)을 요청 시점에 모두 검사합니다. 설정 화면을 볼 수 있다는 이유만으로 명단을 보는 우회 경로를 막기 위함입니다. 각 권한의 적용 범위도 **전체**여야 합니다 — 「본인만」(`self`)·「같은 역할만」(`role`)으로 좁혀 위임받은 관리자는 목록 화면에서 자기 범위만 보는데, 명단은 도메인 전체를 모은 것이라 열어 주면 목록보다 넓게 보게 됩니다. 이때 건수 API 의 `can_view_targets` 는 `false`, 이 API 는 403 입니다. 핸들러 계약은 [개인정보 보유기간 처리 가이드](../privacy-retention.md)를 참조하세요.

### GET /api/admin/privacy/dormant-targets/{stage}
<!-- @generated:start:api.admin.privacy.dormant-targets -->
- **라우트명**: `api.admin.privacy.dormant-targets`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PrivacyTargetController@dormant`
- **인증/권한**: `auth:sanctum` + `admin` + `core.settings.read` + `core.users.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| stage | path | string | 예 | `pre_notice`, `convert`, `withdraw_notice`, `withdraw` | 휴면 생명주기 단계 |
| page | query | integer | 아니오 | 1 이상 | 페이지 번호 (기본 1) |
| per_page | query | integer | 아니오 | 1~100 | 페이지 크기 (기본 20) |

**요청 예시**

```http
GET /api/admin/privacy/dormant-targets/convert?page=1&per_page=20 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

`retention-targets` 와 같은 행 형태입니다(`data[]` · `columns` · `pagination`). 기준일은 앞 두 단계(`pre_notice`·`convert`)가 **마지막 활동일**(판정과 같이 로그인 — 없으면 가입일 — 과 토큰 마지막 사용 중 늦은 쪽, 정렬도 같은 값), 뒤 두 단계(`withdraw_notice`·`withdraw`)가 **휴면 전환일**이며 `columns.date` 라벨이 그에 맞춰 바뀝니다. 권한은 `core.settings.read` + `core.users.read` 를 **전체 범위로** 가져야 합니다(범위가 좁으면 403). 두 권한은 `retention-targets` 와 달리 고정이라 라우트의 권한 미들웨어가 먼저 확인하고(없으면 단계 식별자와 무관하게 403), 적용 범위는 컨트롤러가 확인합니다.

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "data": [
            {
                "id": 34,
                "title": "김휴면",
                "subtitle": "sleepy@example.com",
                "context": null,
                "date": "2023-08-14",
                "url": "/admin/users/1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d"
            }
        ],
        "columns": {
            "title": "이름",
            "subtitle": "이메일",
            "date": "마지막 활동일"
        },
        "pagination": {
            "current_page": 1,
            "per_page": 20,
            "from": 1,
            "to": 1,
            "has_more_pages": false,
            "last_page": 1,
            "total": 1
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 관리자가 아니거나 `core.settings.read`·`core.users.read` 중 하나라도 없는 경우 |
| 404 | Not Found | 알 수 없는 단계 |
| 422 | Unprocessable Entity | `page`·`per_page` 가 범위를 벗어난 경우 |

<!-- @generated:end -->

**설명**

관리자 > 환경설정 > 개인정보 탭의 휴면 카드에서 단계별 「더보기」 모달이 부릅니다. 휴면은 보유기간 핸들러가 아니라 코어 전용 서비스라 별도 경로이지만 행 형태는 같습니다. 명단은 `privacy:process-dormant` 가 그 단계에서 처리할 회원과 같은 쿼리에서 나오며, 휴면·자동 탈퇴가 꺼져 있어도 저장된 기간값으로 돌려줍니다. 관리자 계정은 자동 탈퇴 대상에서 제외되므로 뒤 두 단계 명단에도 나오지 않습니다.

### GET /api/admin/privacy/password-expiry-targets/{stage}
<!-- @generated:start:api.admin.privacy.password-expiry-targets -->
- **라우트명**: `api.admin.privacy.password-expiry-targets`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PrivacyTargetController@passwordExpiry`
- **인증/권한**: `auth:sanctum` + `admin` + `core.settings.read` + `core.users.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| stage | path | string | 예 | `notice`, `expire` | 비밀번호 만료 처리 단계 (사전 안내 / 만료 처리) |
| page | query | integer | 아니오 | 1 이상 | 페이지 번호 (기본 1) |
| per_page | query | integer | 아니오 | 1~100 | 페이지 크기 (기본 20) |

**요청 예시**

```http
GET /api/admin/privacy/password-expiry-targets/notice?page=1&per_page=20 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

`retention-targets` 와 같은 행 형태입니다(`data[]` · `columns` · `pagination`). `context` 는 구분(관리자·회원), `date` 는 기준 날짜(사용자 시간대) — 열 이름이 단계를 따라 사전 안내(`notice`)는 **만료 예정일**(`user.password_expiry_target.column_expires_due`), 만료 처리(`expire`)는 **만료일**(`column_expires_at`)이며, **만료가 가까운 순**(기준일 오름차순, 같으면 ID 순)으로 정렬합니다 — 1회 실행당 상한이 있으면 배치가 이 순서대로 처리합니다. 권한은 `core.settings.read` + `core.users.read` 를 **전체 범위로** 가져야 합니다(범위가 좁으면 403).

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "data": [
            {
                "id": 41,
                "title": "홍길동",
                "subtitle": "hong@example.com",
                "context": "회원",
                "date": "2026-10-05",
                "url": "/admin/users/1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d"
            }
        ],
        "columns": {
            "title": "이름",
            "subtitle": "이메일",
            "context": "구분",
            "date": "만료 예정일"
        },
        "pagination": {
            "current_page": 1,
            "per_page": 20,
            "from": 1,
            "to": 1,
            "has_more_pages": false,
            "last_page": 1,
            "total": 1
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 관리자가 아니거나 `core.settings.read`·`core.users.read` 중 하나라도 없거나 범위가 좁은 경우 |
| 404 | Not Found | 알 수 없는 단계 |
| 422 | Unprocessable Entity | `page`·`per_page` 가 범위를 벗어난 경우 |

<!-- @generated:end -->

**설명**

관리자 > 환경설정 > 보안 탭 「비밀번호 만료」 카드의 「대상 보기」가 개인정보 탭과 같은 명단 창으로 부릅니다 (#736). 명단은 `auth:process-password-expiry` 가 그 단계에서 처리할 회원과 같은 쿼리에서 나오며, 만료 기능이 꺼져 있어도 저장된 값으로 돌려줍니다.

### GET /api/admin/privacy/manual-runs
<!-- @generated:start:api.admin.privacy.manual-runs.index -->
- **라우트명**: `api.admin.privacy.manual-runs.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PrivacyManualRunController@index`
- **인증/권한**: `auth:sanctum` + `admin` (대상별 실행 권한은 응답에서 가린다)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| targets | query | string | 예 | 쉼표로 이은 대상 키, 최대 2000자 | 상태를 볼 직권 실행 대상 키 (예: `retention:user-withdrawn,dormant:convert`, 보안 탭은 `password_expiry:notice,password_expiry:expire`) |
| since | query | date | 아니오 | ISO 8601 | 이 시각 이후 끝난 실행이 있는지 판정할 기준 — 화면이 직전 응답의 `checked_at` 을 그대로 보낸다 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.privacy.manual_run_status_validation_rules`).

**요청 예시**

```http
GET /api/admin/privacy/manual-runs?targets=retention:user-withdrawn,dormant:convert&since=2026-09-25T13:40:31+00:00 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| targets | object | `{"dormant:convert": {…}}` | 대상 키 => 상태 |
| targets.*.can_run | boolean | `true` | 요청한 관리자가 직권 실행할 수 있는지 — `false` 면 `label`·`enabled`·`run` 이 가려진다(`null`). 단 실행기가 열람 권한을 선언(`PrivacyManualRunReadableInterface::readPermissions()`, 비밀번호 만료 = `core.settings.read`)했고 관리자가 그 권한을 가지면 `can_run=false` 여도 `run`(접수자 이름 포함)을 준다 — `label`·`enabled` 는 그대로 `null` 이다. 화면은 버튼을 보이되 잠근다 |
| targets.*.label | string\|null | `"휴면 전환"` | 대상 이름 |
| targets.*.enabled | boolean\|null | `false` | 자동 실행 토글 — 보유기간·휴면은 안내용(직권 실행은 이 값과 무관), 비밀번호 만료(`password_expiry:*`)는 `false` 면 접수가 409 로 거절되므로 화면이 「지금 실행」을 비활성으로 그린다 |
| targets.*.supports_skip_waiting | boolean | `true` | 「대기기간 생략」 지원 여부 (휴면 전환·탈퇴 단계만) |
| targets.*.run | object\|null | — | 가장 최근 실행 기록 (없으면 `null`) |
| targets.*.run.status | string | `"completed"` | `queued`·`running`·`completed`·`failed` |
| targets.*.run.status_label | string | `"완료"` | 상태 라벨 |
| targets.*.run.is_active | boolean | `false` | 처리 대기·처리 중이면 `true` |
| targets.*.run.skip_waiting | boolean | `true` | 대기기간 생략으로 접수했는지 |
| targets.*.run.processed_count | integer\|null | `3` | 처리 건수 (완료 시) |
| targets.*.run.remaining_count | integer\|null | `0` | 처리 후 남은 건수 (발송 상한이 있는 조치만 — gdpr 연간 통지) |
| targets.*.run.mode | string\|null | `"convert"` | 처리 방식 (`anonymize`·`delete`·`erase_ip`·`notify`·휴면 단계명) |
| targets.*.run.error | string\|null | `null` | 실패 사유 |
| targets.*.run.requester_name | string\|null | `"표준"` | 접수한 관리자 이름 (회원이 삭제됐으면 `null`) |
| targets.*.run.queued_at / started_at / finished_at | string\|null | `"2026-09-25 22:40:31"` | 관리자 시간대 `Y-m-d H:i:s` |
| has_active | boolean | `true` | 처리 대기·처리 중인 대상이 있는지 — 화면이 주기 조회를 계속할지 정한다 |
| finished_since | boolean | `false` | `since` 이후 끝난 실행이 있는지 — `true` 면 화면이 대상 건수를 다시 받는다 |
| checked_at | string | `"2026-09-25T13:40:34+00:00"` | 서버 시각 — 다음 조회의 `since` 로 돌려보낸다 |

**응답 예시**

```json
{
    "success": true,
    "message": "성공",
    "data": {
        "targets": {
            "dormant:convert": {
                "key": "dormant:convert",
                "label": "휴면 전환",
                "can_run": true,
                "enabled": false,
                "supports_skip_waiting": true,
                "run": {
                    "id": 2,
                    "target": "dormant:convert",
                    "status": "queued",
                    "status_label": "처리 대기",
                    "is_active": true,
                    "skip_waiting": true,
                    "processed_count": null,
                    "remaining_count": null,
                    "mode": null,
                    "error": null,
                    "requester_name": "표준",
                    "queued_at": "2026-09-25 22:40:31",
                    "started_at": null,
                    "finished_at": null
                }
            }
        },
        "has_active": true,
        "finished_since": false,
        "checked_at": "2026-09-25T13:40:34+00:00"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 관리자가 아닌 경우 |
| 422 | Unprocessable Entity | `targets` 가 없거나 너무 길거나, `since` 가 날짜가 아닌 경우 |

<!-- @generated:end -->

**설명**

설정 화면(코어 개인정보 탭 · 이커머스 보유기간 탭 · 게시판 보유기간 탭 · gdpr 설정)의 카드가 「지금 실행」 버튼과 상태 줄(처리 대기/처리 중 · 마지막 결과)을 그리려고 부릅니다. 진행 중인 대상이 있으면(`has_active`) 화면이 3초마다 다시 부르고, 없으면 멈춥니다. 대상별 실행 권한이 없으면 그 대상의 기록을 가립니다 — 기록에는 접수한 관리자 이름이 있기 때문입니다. 워커가 멈춰 기준 시간(6시간)을 넘긴 처리 대기·처리 중 기록은 이 조회가 실패로 닫습니다.

### GET /api/admin/privacy/manual-runs/{target}/preview
<!-- @generated:start:api.admin.privacy.manual-runs.preview -->
- **라우트명**: `api.admin.privacy.manual-runs.preview`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PrivacyManualRunController@preview`
- **인증/권한**: `auth:sanctum` + `admin` + 대상이 선언한 실행 권한(모두 필요, 전체 범위)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| target | path | string | 예 | 등록된 대상 키 | 직권 실행 대상 키 (`retention:{핸들러}`·`dormant:{단계}`·확장 등록 키) |
| skip_waiting | query | boolean | 아니오 | `0`·`1` | 대기기간 생략 모집단으로 셀지 (휴면 전환·탈퇴 단계만, 그 밖에는 무시) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.privacy.manual_run_validation_rules`).

**요청 예시**

```http
GET /api/admin/privacy/manual-runs/dormant:convert/preview?skip_waiting=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| key | string | `"dormant:convert"` | 대상 키 |
| label | string | `"휴면 전환"` | 대상 이름 |
| count | integer | `3` | 지금 실행하면 처리될 건수 (명단·배치와 같은 조건식) |
| mode | string\|null | `"convert"` | 처리 방식 |
| enabled | boolean | `false` | 자동 실행 토글 (보유기간·휴면은 꺼져 있어도 직권 실행을 처리하고, `password_expiry:*` 는 꺼져 있으면 접수가 409) |
| supports_skip_waiting | boolean | `true` | 대기기간 생략 지원 여부 |
| skip_waiting | boolean | `true` | 이 건수가 생략 모집단인지 — 생략을 지원하지 않는 대상이면 요청값과 무관하게 `false` |

**응답 예시**

```json
{
    "success": true,
    "message": "성공",
    "data": {
        "key": "dormant:convert",
        "label": "휴면 전환",
        "count": 3,
        "mode": "convert",
        "enabled": false,
        "supports_skip_waiting": true,
        "skip_waiting": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 대상이 선언한 실행 권한 중 하나라도 없거나 적용 범위가 전체가 아닌 경우 |
| 404 | Not Found | 등록되지 않은 대상 키 (확장이 비활성이면 그 대상은 등록되지 않는다) |
| 422 | Unprocessable Entity | `skip_waiting` 이 boolean 이 아닌 경우 |
| 500 | Server Error | 대상의 건수 조회가 실패한 경우 (로그 참조) |

<!-- @generated:end -->

**설명**

「지금 실행」 확인 창이 열릴 때와 「대기기간 생략」을 바꿀 때 부릅니다. 데이터를 바꾸지 않습니다.

### POST /api/admin/privacy/manual-runs/{target}
<!-- @generated:start:api.admin.privacy.manual-runs.store -->
- **라우트명**: `api.admin.privacy.manual-runs.store`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\PrivacyManualRunController@store`
- **인증/권한**: `auth:sanctum` + `admin` + 대상이 선언한 실행 권한(모두 필요, 전체 범위)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| target | path | string | 예 | 등록된 대상 키 | 직권 실행 대상 키 |
| skip_waiting | body | boolean | 아니오 | — | 대기기간 생략 (휴면 전환·탈퇴 단계만, 그 밖에는 무시) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.privacy.manual_run_validation_rules`).

**요청 예시**

```http
POST /api/admin/privacy/manual-runs/dormant:convert HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "skip_waiting": true
}
```

**응답 필드** (`data` 내부)

접수된 실행 기록 — 상태 API 의 `targets.*.run` 과 같은 모양입니다. 큐가 동기(`sync`) 드라이버면 응답 시점에 이미 `completed` 입니다.

**응답 예시** (202)

```json
{
    "success": true,
    "message": "실행을 큐에 접수했습니다. 처리가 끝나면 카드에 결과가 표시됩니다.",
    "data": {
        "id": 2,
        "target": "dormant:convert",
        "status": "queued",
        "status_label": "처리 대기",
        "is_active": true,
        "skip_waiting": true,
        "processed_count": null,
        "remaining_count": null,
        "mode": null,
        "error": null,
        "requester_name": "표준",
        "queued_at": "2026-09-25 22:40:31",
        "started_at": null,
        "finished_at": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 대상이 선언한 실행 권한 중 하나라도 없거나 적용 범위가 전체가 아닌 경우 |
| 404 | Not Found | 등록되지 않은 대상 키 |
| 409 | Conflict | 같은 대상에 처리 대기·처리 중인 실행이 있음 — `errors.run` 에 그 기록 |
| 409 | Conflict | 토글이 켜져 있어야 하는 대상(`password_expiry:{notice\|expire}`)인데 기능이 꺼져 있음 — `errors.run` 없음, 메시지 `exceptions.privacy_manual_run_disabled` (#736) |
| 422 | Unprocessable Entity | `skip_waiting` 이 boolean 이 아닌 경우 |
| 500 | Server Error | 접수가 실패한 경우 (로그 참조) |

<!-- @generated:end -->

**설명**

설정 화면 확인 창의 「실행」이 부릅니다. 작업을 큐(`RunPrivacyManualActionJob`)에 넣고 곧바로 응답하며, 처리는 큐 워커가 합니다. 자동 실행 토글이 꺼져 있어도 저장된 설정값으로 처리합니다(자동 배치는 여전히 토글을 봅니다). 단 처리 효력이 토글에 묶인 비밀번호 만료(`password_expiry:{notice|expire}`)는 꺼져 있으면 접수하지 않고 409 를 돌려주며, 접수 뒤 실행 전에 꺼지면 처리하지 않습니다 — 꺼져 있으면 로그인 관문이 변경을 요구하지 않아 "다음 로그인 때 변경" 안내가 사실과 달라지기 때문입니다. 같은 대상의 작업은 하나만 받으며, 새벽 배치가 같은 대상을 처리하는 동안에는 작업이 1분 간격으로 다시 시도합니다(반대로 직권 실행이 처리 중이면 배치가 그 대상을 건너뜁니다). 접수와 종료는 관리자 활동 로그(`privacy.manual_run_queued`·`privacy.manual_run_finished`)에 남습니다. 대상 계약은 [개인정보 보유기간 처리 가이드](../privacy-retention.md#10-관리자-직권-실행)를 참조하세요.

