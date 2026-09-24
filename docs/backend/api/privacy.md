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
