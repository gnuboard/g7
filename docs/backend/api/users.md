# Users API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Users 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/users
<!-- @generated:start:api.admin.users.index -->
- **라우트명**: `api.admin.users.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\UserController@index`
- **인증/권한**: `auth:sanctum` + `permission:core.users.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| filters | query | array | 아니오 | max 10 | 추가 필터 조건 맵 (필드별 조건) |
| start_date | query | date | 아니오 | — | 조회 기간 시작일 (이 날짜 이후 데이터) |
| end_date | query | date | 아니오 | — | 조회 기간 종료일 (이 날짜 이전 데이터) |
| date_filter | query | string | 아니오 | `all`, `week`, `month`, `custom` | 가입 기간 프리셋 (all: 전체, week: 최근 1주, month: 최근 1개월, custom: start_date/end_date 로 직접 지정) |
| sort_by | query | string | 아니오 | `created_at`, `name`, `email`, `last_login_at` | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.user.list_validation_rules`).

**요청 예시**

```http
GET /api/admin/users?page=1&per_page=1&filters=%EC%98%88%EC%8B%9C%EA%B0%92&start_date=2026-01-01&end_date=2026-01-01&date_filter=all&sort_by=created_at&sort_order=asc HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `82` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| uuid | string | `a26b99a4-5a47-4233-ba2e-41d2b7e9d2c9` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| name | string | `설규산` | 사용자 이름 |
| nickname | null | `null` | 닉네임 |
| email | string | `gunho.choi@example.com` | 이메일 주소 |
| language | string | `ko` | 사용자 언어 설정 (ko: 한국어, en: 영어) |
| language_label | string | `한국어` | 언어 코드의 현지화 라벨 (user.language.{code} 번역) |
| country | null | `null` | 국가 코드 (ISO 3166-1 alpha-2) |
| country_flag | null | `null` | 국가 코드의 국기 이모지 (country 값에서 파생) |
| country_name | null | `null` | 국가 코드의 현지화 국가명 (country 값에서 파생) |
| status | string | `active` | 계정 상태 (active: 활성, inactive: 비활성, blocked: 차단, withdrawn: 탈퇴) |
| status_label | string | `활성` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| status_variant | string | `success` | 상태 표시 색상/스타일 변형 키 (상태 Enum variant() 산물 — UI 배지용) |
| mobile | null | `null` | 휴대폰 번호 |
| roles | array | `[{"id":1,"identifier":"admin","name":"관리자"},{"id":892,"id…` | 사용자에게 부여된 역할 목록 (원소: id/identifier/name — 역할 관계 파생) |
| email_verified_at | string | `2026-08-04 16:57:08` | email verified 일시 |
| last_login_at | null | `null` | last login 일시 |
| created_at | string | `2026-08-04` | 생성 일시 |
| is_owner | boolean | `false` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_create":true,"can_update":true,"can…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "사용자 정보를 성공적으로 가져왔습니다.",
    "data": {
        "data": [
            {
                "number": 82,
                "uuid": "a26b99a4-5a47-4233-ba2e-41d2b7e9d2c9",
                "name": "설규산",
                "nickname": null,
                "email": "gunho.choi@example.com",
                "language": "ko",
                "language_label": "한국어",
                "country": null,
                "country_flag": null,
                "country_name": null,
                "status": "active",
                "status_label": "활성",
                "status_variant": "success",
                "mobile": null,
                "roles": [
                    {
                        "id": 1,
                        "identifier": "admin",
                        "name": "관리자"
                    },
                    {
                        "id": 892,
                        "identifier": "playwright_test_6a719b554d5f5",
                        "name": "Playwright 테스트 관리자"
                    }
                ],
                "email_verified_at": "2026-08-04 16:57:08",
                "last_login_at": null,
                "created_at": "2026-08-04",
                "is_owner": false,
                "abilities": {
                    "can_read": true,
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true,
                    "can_assign_roles": true
                }
            },
            {
                "number": 81,
                "uuid": "a26b999a-d541-4865-b170-da2eba823996",
                "name": "채하은",
                "nickname": null,
                "email": "eahn@example.com",
                "language": "ko",
                "language_label": "한국어",
                "country": null,
                "country_flag": null,
                "country_name": null,
                "status": "active",
                "status_label": "활성",
                "status_variant": "success",
                "mobile": null,
                "roles": [
                    {
                        "id": 1,
                        "identifier": "admin",
                        "name": "관리자"
                    },
                    {
                        "id": 891,
                        "identifier": "playwright_test_6a719b4eed13d",
                        "name": "Playwright 테스트 관리자"
                    }
                ],
                "email_verified_at": "2026-08-04 16:57:02",
                "last_login_at": null,
                "created_at": "2026-08-04",
                "is_owner": false,
                "abilities": {
                    "can_read": true,
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true,
                    "can_assign_roles": true
                }
            },
            "... (총 25건 중 2건 표시)"
        ],
        "statistics": {
            "total_users": 82,
            "users_this_week": 82,
            "users_this_month": 82,
            "users_today": 70,
            "active_users_this_week": 3
        },
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true,
            "can_assign_roles": true
        },
        "pagination": {
            "current_page": 1,
            "last_page": 4,
            "per_page": 25,
            "total": 82,
            "from": 1,
            "to": 25,
            "has_more_pages": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.users.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

관리자 사용자 관리 화면(`admin_user_list.json`)의 목록을 제공합니다. `filters` 로 이름/이메일 다중 검색(operator: like, eq, starts_with, ends_with), `date_filter`(all/week/month/custom)와 `start_date`/`end_date` 로 가입 기간 필터, `sort_by`/`sort_order` 로 정렬한다. 응답은 `data.data[]`(항목별 순번 `number` + 요약 필드)와 `data.pagination`(페이지 정보)에 더해 `data.statistics`(통계)와 `data.abilities`(컬렉션 레벨 권한)를 함께 반환한다. 기본값은 `per_page=15`, `page=1`, `sort_by=created_at`, `sort_order=desc`, `date_filter=all` 이다.


### POST /api/admin/users
<!-- @generated:start:api.admin.users.store -->
- **라우트명**: `api.admin.users.store`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\UserController@store`
- **인증/권한**: `auth:sanctum` + `permission:core.users.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| name | body | string | 예 | max 255 | 대상의 이름/명칭 |
| nickname | body | string | 아니오 | max 50 | 닉네임 |
| email | body | email | 예 | max 255 | 이메일 주소 |
| password | body | string | 예 | — | 비밀번호 |
| language | body | string | 아니오 | `ko`, `en` | 언어 코드 |
| country | body | string | 아니오 | — | 국가 코드 (ISO 3166-1 alpha-2) |
| timezone | body | string | 아니오 | — | 타임존 식별자 |
| status | body | string | 아니오 | `active`, `inactive`, `blocked`, `withdrawn` | 계정 상태 (미지정 시 active) |
| homepage | body | string | 아니오 | max 255 | 홈페이지 URL |
| mobile | body | string | 아니오 | max 20 | 휴대전화 번호 |
| phone | body | string | 아니오 | max 20 | 전화번호 |
| zipcode | body | string | 아니오 | max 10 | 우편번호 |
| address | body | string | 아니오 | max 255 | 기본 주소 |
| address_detail | body | string | 아니오 | max 255 | 상세 주소 |
| signature | body | string | 아니오 | max 1000 | 서명 |
| bio | body | string | 아니오 | max 5000 | 자기소개 |
| admin_memo | body | string | 아니오 | max 5000 | 관리자 전용 메모 (해당 사용자에 대한 내부 기록, 사용자에게 노출 안 됨) |
| roles | body | array | 아니오 | min 1 | 부여할 역할 객체 배열 `[{id}]` (role_ids 와 병용 시 role_ids 우선) |
| role_ids | body | array | 아니오 | min 1 | role 식별자 배열 |
| notify_post_complete | body | boolean | 아니오 | — | 게시글 작성 완료 알림 수신 여부 (게시판 모듈 알림 설정) |
| notify_post_reply | body | boolean | 아니오 | — | 내 게시글에 답글이 달릴 때 알림 수신 여부 |
| notify_comment | body | boolean | 아니오 | — | 내 게시글에 댓글이 달릴 때 알림 수신 여부 |
| notify_reply_comment | body | boolean | 아니오 | — | 내 댓글에 대댓글이 달릴 때 알림 수신 여부 |
| email_subscription | body | boolean | 아니오 | — | 광고성 이메일 수신 동의 여부 (marketing 플러그인 채널 동의, marketing_consents 테이블에 저장) |
| marketing_consent | body | boolean | 아니오 | — | 마케팅 정보 수신 전체 동의 마스터 키 (marketing 플러그인이 검증 규칙 주입, 상세는 user-consent-injection.md) |
| third_party_consent | body | boolean | 아니오 | — | 제3자 정보 제공 동의 여부 (marketing 플러그인 법적 동의 항목) |
| info_disclosure | body | boolean | 아니오 | — | 개인정보 이용 안내 동의 여부 (marketing 플러그인 법적 동의 항목) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.user.create_validation_rules`).

**요청 예시**

```http
POST /api/admin/users HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": "예시 이름",
    "nickname": "예시 이름",
    "email": "user@example.com",
    "password": "Password123!",
    "language": "ko",
    "country": "KR",
    "timezone": "Asia/Seoul",
    "status": "active",
    "homepage": "https://example.com",
    "mobile": "010-1234-5678",
    "phone": "010-1234-5678",
    "zipcode": "06234",
    "address": "서울특별시 강남구 테헤란로 1",
    "address_detail": "서울특별시 강남구 테헤란로 1",
    "signature": "예시값",
    "bio": "예시 내용입니다.",
    "admin_memo": "예시값",
    "roles": [
        "예시값"
    ],
    "role_ids": [
        "예시값"
    ],
    "notify_post_complete": true,
    "notify_post_reply": true,
    "notify_comment": true,
    "notify_reply_comment": true,
    "email_subscription": true,
    "marketing_consent": true,
    "third_party_consent": true,
    "info_disclosure": true
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (생성된 사용자 — `UserResource::toArray()`, 관계 미로드이므로 modules/plugins/menus/roles/permissions/consents 키는 응답에 포함되지 않음)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| uuid | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| name | string | `API 문서 샘플 사용자` | 사용자 이름 |
| nickname | string\|null | `null` | 닉네임 |
| email | string | `apidoc-sample-user@example.com` | 이메일 주소 |
| avatar | string\|null | `null` | 프로필 아바타 이미지 URL (미설정 시 null) |
| language | string | `ko` | 사용자 언어 설정 (ko: 한국어, en: English) |
| language_label | string | `한국어` | 언어 코드의 현지화 라벨 (`user.language.{code}` 번역) |
| country | string\|null | `KR` | 국가 코드 (ISO 3166-1 alpha-2) |
| status | string | `active` | 계정 상태 (UserStatus Enum: active/inactive/blocked/withdrawn) |
| status_label | string | `활성` | 상태의 사람이 읽는 라벨 (UserStatus::label()) |
| status_variant | string | `success` | 상태 표시 색상/스타일 변형 키 (UserStatus::variant() — UI 배지용) |
| is_admin | boolean | `false` | 관리자 역할 보유 여부 (User::isAdmin()) |
| homepage | string\|null | `null` | 홈페이지 URL |
| mobile | string\|null | `null` | 휴대폰 번호 |
| phone | string\|null | `null` | 전화번호 |
| zipcode | string\|null | `null` | 우편번호 |
| address | string\|null | `null` | 기본 주소 |
| address_detail | string\|null | `null` | 상세 주소 |
| signature | string\|null | `null` | 서명 |
| bio | string\|null | `null` | 자기소개 |
| last_login_at | string\|null | `null` | 마지막 로그인 일시 (신규 생성 시 null) |
| email_verified_at | string\|null | `null` | 이메일 인증 일시 (미인증 시 null) |
| timezone | string\|null | `Asia/Seoul` | 사용자 시간대 |
| created_at | string | `2026-07-08 10:41:24` | 생성 일시 |
| updated_at | string | `2026-07-08 10:41:24` | 최종 수정 일시 |
| is_owner | boolean | `false` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_create":true,"can_update":true,"can_delete":true,"can_assign_roles":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (슈퍼관리자 대상은 can_delete=false 강제) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "사용자가 성공적으로 생성되었습니다.",
    "data": {
        "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "name": "예시 이름",
        "nickname": null,
        "email": "user@example.com",
        "avatar": null,
        "language": "ko",
        "language_label": "한국어",
        "country": "KR",
        "status": "active",
        "status_label": "활성",
        "status_variant": "success",
        "is_admin": false,
        "homepage": null,
        "mobile": "010-1234-5678",
        "phone": null,
        "zipcode": null,
        "address": null,
        "address_detail": null,
        "signature": null,
        "bio": null,
        "last_login_at": null,
        "email_verified_at": null,
        "timezone": "Asia/Seoul",
        "created_at": "2026-07-08 10:41:24",
        "updated_at": "2026-07-08 10:41:24",
        "is_owner": false,
        "abilities": {
            "can_read": true,
            "can_create": true,
            "can_update": true,
            "can_delete": true,
            "can_assign_roles": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.users.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 사용자 생성 중 예외 발생 (`user.create_failed`, `errors.error` 에 예외 메시지) |

<!-- @generated:end -->

**설명**

관리자가 새 사용자를 생성합니다. `name`, `email`, `password`(8자 이상, `password_confirmation` 확인 필수), 그리고 역할이 필수이다 — 역할은 `roles`(객체 배열 `[{id}]`) 또는 `role_ids`(id 배열) 중 하나로 지정하며 둘 다 보내면 `role_ids` 가 우선한다. `language`(미지정 시 `ko`), `status`(미지정 시 `active`), 연락처/주소/자기소개 등은 선택 항목이다. 성공 시 201 과 함께 생성된 사용자를 `UserResource` 형태로 반환한다. `notify_*`/`marketing_consent`/`third_party_consent`/`info_disclosure`/`email_subscription` 파라미터는 확장(sirsoft-marketing)이 검증 규칙을 주입한 필드로, 상세는 해당 확장 문서를 참조한다.


### PATCH /api/admin/users/bulk-status
<!-- @generated:start:api.admin.users.bulk-status -->
- **라우트명**: `api.admin.users.bulk-status`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\UserController@bulkUpdateStatus`
- **인증/권한**: `auth:sanctum` + `permission:core.users.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |
| status | body | string | 예 | — | 일괄 적용할 계정 상태 (UserStatus Enum 값: active/inactive/blocked/withdrawn/pending_verification) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.user.bulk_update_status_validation_rules`).

**요청 예시**

```http
PATCH /api/admin/users/bulk-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        "예시값"
    ],
    "status": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (UserService::bulkUpdateStatus() 반환값)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| updated_count | integer | `3` | 실제로 상태가 변경된 사용자 수 (요청자 본인 제외 규칙 적용 후 갱신된 행 수) |
| failed_count | integer | `1` | 처리하지 못한 사용자 수 (탈퇴 전환에서 관리자·수퍼관리자 등 차단 대상이 섞인 경우). 탈퇴 외 상태 전환은 항상 `0` |
| failed_reasons | array\<string\> | `["관리자 계정은 탈퇴할 수 없습니다."]` | 처리하지 못한 사유 (중복 제거). 탈퇴 외 상태 전환은 항상 `[]` |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": ":count명의 사용자 상태가 변경되었습니다.",
    "data": {
        "updated_count": 3,
        "failed_count": 0,
        "failed_reasons": []
    }
}
```

> `user.bulk_status_updated` 메시지는 `:count` 치환자를 갖지만 컨트롤러가 messageParams 를 넘기지 않으므로 응답 message 에는 치환되지 않은 원문이 그대로 담긴다. 실제 변경 건수는 `data.updated_count` 로 판단한다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.users.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지 — 예: 요청자 본인 UUID 포함 시 `ExcludeCurrentUser` 위반) |
| 500 | Internal Server Error | 일괄 변경 중 예외 발생 (`user.bulk_update_status_failed`) |

<!-- @generated:end -->

**설명**

여러 사용자의 계정 상태를 한 번에 변경합니다. `ids` 는 대상 사용자 UUID 배열이며, `status` 는 `active`/`inactive`/`blocked`/`withdrawn`/`pending_verification`(UserStatus Enum 값) 중 하나이다. `ExcludeCurrentUser` 규칙으로 요청자 본인은 대상에서 제외된다. 목록 화면의 다중 선택 후 일괄 상태 변경에 사용한다.

`status=withdrawn` 요청은 상태 컬럼만 바꾸지 않고 **건별로 정식 탈퇴 처리**를 수행한다 (익명화 + 연계 데이터 정리 + 탈퇴 훅). 회원이 직접 탈퇴한 경우와 결과가 같다. 관리자 역할을 가진 대상은 건너뛰고 `failed_count` 로 집계되며, 나머지 대상의 처리는 계속된다.

전건이 차단되어도 응답은 200 이다. 소비자는 `updated_count` 와 `failed_count` 를 함께 읽어 결과를 판정해야 한다 — 200 만 보고 성공으로 안내하면 아무 일도 일어나지 않은 요청이 처리된 것으로 표시된다. 사유는 `failed_reasons` 로 전달되므로 그대로 노출하면 된다.


### POST /api/admin/users/check-email
<!-- @generated:start:api.admin.users.check-email -->
- **라우트명**: `api.admin.users.check-email`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\UserController@checkEmail`
- **인증/권한**: `auth:sanctum` + `permission:core.users.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| email | body | email | 예 | max 255 | 이메일 주소 |
| exclude_user_id | body | uuid | 아니오 | — | exclude user 식별자 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.user.check_email_validation_rules`).

**요청 예시**

```http
POST /api/admin/users/check-email HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "email": "user@example.com",
    "exclude_user_id": "9f8b2c1a-4d3e-4a2b-8c1d-0e1f2a3b4c5d"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| available | boolean | `true` | 해당 이메일의 사용 가능 여부 (true: 사용 가능, false: 이미 사용 중). `exclude_user_id` 로 지정한 사용자가 소유한 이메일이면 true 로 판정 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "사용 가능한 이메일입니다.",
    "data": {
        "available": true
    }
}
```

> 이미 사용 중인 이메일이면 동일한 200 응답에 `message` 가 `이미 사용 중인 이메일입니다.` (`user.email_unavailable`), `data.available` 이 `false` 로 반환된다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.users.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지 — 이메일 형식/필수, exclude_user_id UUID 형식·존재 여부) |
| 500 | Internal Server Error | 중복 확인 중 예외 발생 (`user.email_check_failed`) |

<!-- @generated:end -->

**설명**

이메일 주소의 사용 가능 여부(중복 아님)를 확인합니다. `email` 은 필수, `exclude_user_id`(UUID)를 주면 해당 사용자를 중복 검사에서 제외한다 — 사용자 수정 화면에서 자기 자신의 이메일을 유지할 때 사용한다. 응답 `data.available` 이 true 면 사용 가능, false 면 이미 사용 중이다. 사용자 생성/수정 폼의 이메일 실시간 중복 확인에 쓰인다.


### PATCH /api/admin/users/me/language
<!-- @generated:start:api.admin.users.me.language -->
- **라우트명**: `api.admin.users.me.language`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\UserController@updateMyLanguage`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| language | body | string | 예 | `ko`, `en` | 언어 코드 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.user.update_language_validation_rules`).

**요청 예시**

```http
PATCH /api/admin/users/me/language HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "language": "ko"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| uuid | string | `a26219fc-94a0-4f63-9404-04c2a6ac99e4` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| name | string | `최고관리자` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| nickname | string | `최고관리자` | 닉네임 |
| email | string | `heuristing@gmail.com` | 이메일 주소 |
| avatar | null | `null` | 아바타 이미지 URL (미등록 시 null) |
| language | string | `ko` | 사용자 언어 설정 (ko: 한국어, en: 영어) |
| language_label | string | `한국어` | 언어 코드의 현지화 라벨 (user.language.{code} 번역) |
| country | null | `null` | 국가 코드 (ISO 3166-1 alpha-2) |
| status | string | `active` | 상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조) |
| status_label | string | `활성` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| status_variant | string | `success` | 상태 표시 색상/스타일 변형 키 (상태 Enum variant() 산물 — UI 배지용) |
| is_admin | boolean | `true` | 관리자 역할 보유 여부 (User::isAdmin() — 역할 관계 기반 파생) |
| homepage | null | `null` | 홈페이지 URL |
| mobile | null | `null` | 휴대폰 번호 |
| phone | null | `null` | 전화번호 |
| zipcode | null | `null` | 우편번호 |
| address | null | `null` | 기본 주소 |
| address_detail | null | `null` | 상세 주소 |
| signature | null | `null` | 서명 |
| bio | null | `null` | 자기소개 |
| last_login_at | string | `2026-08-04 19:00:10` | last login 일시 |
| email_verified_at | string | `2026-07-30 23:37:44` | email verified 일시 |
| timezone | string | `Asia/Seoul` | 사용자 시간대 (예: Asia/Seoul, UTC) |
| created_at | string | `2026-07-30 23:37:44` | 생성 일시 |
| updated_at | string | `2026-08-04 19:00:10` | 최종 수정 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_create":true,"can_update":true,"can…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "언어 설정이 성공적으로 변경되었습니다.",
    "data": {
        "uuid": "a26219fc-94a0-4f63-9404-04c2a6ac99e4",
        "name": "최고관리자",
        "nickname": "최고관리자",
        "email": "heuristing@gmail.com",
        "avatar": null,
        "language": "ko",
        "language_label": "한국어",
        "country": null,
        "status": "active",
        "status_label": "활성",
        "status_variant": "success",
        "is_admin": true,
        "homepage": null,
        "mobile": null,
        "phone": null,
        "zipcode": null,
        "address": null,
        "address_detail": null,
        "signature": null,
        "bio": null,
        "last_login_at": "2026-08-04 19:00:10",
        "email_verified_at": "2026-07-30 23:37:44",
        "timezone": "Asia/Seoul",
        "created_at": "2026-07-30 23:37:44",
        "updated_at": "2026-08-04 19:00:10",
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_create": true,
            "can_update": true,
            "can_delete": false,
            "can_assign_roles": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.users.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

현재 로그인한 사용자 본인의 언어 설정을 변경합니다. 별도 권한 없이 `auth:sanctum` 인증만 요구하며(다른 사용자를 대상으로 하지 않음), `language` 는 `config('app.supported_locales')`(예: ko, en, fr, ja) 중 하나여야 한다. 성공 시 갱신된 사용자를 `UserResource` 로 반환하며, 관리자 UI 의 언어 전환에 사용한다.


### GET /api/admin/users/recent
<!-- @generated:start:api.admin.users.recent -->
- **라우트명**: `api.admin.users.recent`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\UserController@recent`
- **인증/권한**: `auth:sanctum` + `permission:core.users.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/users/recent HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| uuid | string | `a26b99a4-5a47-4233-ba2e-41d2b7e9d2c9` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| name | string | `설규산` | 사용자 이름 |
| nickname | null | `null` | 닉네임 |
| email | string | `gunho.choi@example.com` | 이메일 주소 |
| avatar | null | `null` | 프로필 아바타 이미지 URL (미설정 시 null) |
| language | string | `ko` | 사용자 언어 설정 (ko: 한국어, en: 영어) |
| language_label | string | `한국어` | 언어 코드의 현지화 라벨 (user.language.{code} 번역) |
| country | null | `null` | 국가 코드 (ISO 3166-1 alpha-2) |
| status | string | `active` | 계정 상태 (active: 활성, inactive: 비활성, blocked: 차단, withdrawn: 탈퇴) |
| status_label | string | `활성` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| status_variant | string | `success` | 상태 표시 색상/스타일 변형 키 (상태 Enum variant() 산물 — UI 배지용) |
| is_admin | boolean | `true` | 관리자 역할 보유 여부 (User::isAdmin() — 역할 관계 기반 파생) |
| homepage | null | `null` | 홈페이지 URL |
| mobile | null | `null` | 휴대폰 번호 |
| phone | null | `null` | 전화번호 |
| zipcode | null | `null` | 우편번호 |
| address | null | `null` | 기본 주소 |
| address_detail | null | `null` | 상세 주소 |
| signature | null | `null` | 서명 |
| bio | null | `null` | 자기소개 |
| last_login_at | null | `null` | last login 일시 |
| email_verified_at | string | `2026-08-04 16:57:08` | email verified 일시 |
| timezone | string | `Asia/Seoul` | 사용자 시간대 (예: Asia/Seoul, UTC) |
| created_at | string | `2026-08-04 16:57:09` | 생성 일시 |
| updated_at | string | `2026-08-04 16:57:09` | 최종 수정 일시 |
| is_owner | boolean | `false` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_create":true,"can_update":true,"can…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "사용자 정보를 성공적으로 가져왔습니다.",
    "data": [
        {
            "uuid": "a26b99a4-5a47-4233-ba2e-41d2b7e9d2c9",
            "name": "설규산",
            "nickname": null,
            "email": "gunho.choi@example.com",
            "avatar": null,
            "...": "(22개 키 생략, 총 27개)"
        },
        {
            "uuid": "a26b999a-d541-4865-b170-da2eba823996",
            "name": "채하은",
            "nickname": null,
            "email": "eahn@example.com",
            "avatar": null,
            "...": "(22개 키 생략, 총 27개)"
        },
        {
            "uuid": "a26b999a-d1c1-4157-a72f-0a1fc9f95c26",
            "name": "기종훈",
            "nickname": null,
            "email": "uno@example.com",
            "avatar": null,
            "...": "(22개 키 생략, 총 27개)"
        },
        {
            "uuid": "a26b999a-78a7-4565-a24f-9b9b49d3b47a",
            "name": "추나은",
            "nickname": null,
            "email": "jihoo.lee@example.org",
            "avatar": null,
            "...": "(22개 키 생략, 총 27개)"
        },
        {
            "uuid": "a26b999a-7521-4c6c-a03d-68eb72702c22",
            "name": "주영호",
            "nickname": null,
            "email": "doyoun93@example.net",
            "avatar": null,
            "...": "(22개 키 생략, 총 27개)"
        },
        "... (총 10건 중 5건 표시)"
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.users.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

최근 가입한 사용자 10명을 최신순으로 반환합니다. 파라미터는 없으며, `UserResource` 전체 필드(관계형 데이터는 미로드)를 담은 컬렉션을 반환한다. 관리자 대시보드의 최근 가입자 위젯이 소비한다.


### GET /api/admin/users/search
<!-- @generated:start:api.admin.users.search -->
- **라우트명**: `api.admin.users.search`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\UserController@search`
- **인증/권한**: `auth:sanctum` + `permission:core.users.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| keyword | query | string | 아니오 | max 255 | 검색 키워드 (부분 일치) |
| uuid | query | uuid | 아니오 | — | 특정 사용자 UUID 로 단건 조회 (지정 시 keyword 무시하고 해당 UUID 사용자만 반환) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.user.search_validation_rules`).

**요청 예시**

```http
GET /api/admin/users/search?keyword=%EC%98%88%EC%8B%9C%EA%B0%92&uuid=9f8b2c1a-4d3e-4a2b-8c1d-0e1f2a3b4c5d HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data[]` 배열 항목의 필드 (페이지네이션 없는 단순 컬렉션 — `UserResource::collection()`, 관계 미로드이므로 modules/plugins/menus/roles/permissions/consents 키는 포함되지 않음). 일치하는 사용자가 없으면 `data` 는 빈 배열._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| uuid | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| name | string | `API 문서 샘플 사용자` | 사용자 이름 |
| nickname | string\|null | `null` | 닉네임 |
| email | string | `apidoc-sample-user@example.com` | 이메일 주소 |
| avatar | string\|null | `null` | 프로필 아바타 이미지 URL (미설정 시 null) |
| language | string | `ko` | 사용자 언어 설정 (ko: 한국어, en: English) |
| language_label | string | `한국어` | 언어 코드의 현지화 라벨 (`user.language.{code}` 번역) |
| country | string\|null | `KR` | 국가 코드 (ISO 3166-1 alpha-2) |
| status | string | `active` | 계정 상태 (UserStatus Enum: active/inactive/blocked/withdrawn) |
| status_label | string | `활성` | 상태의 사람이 읽는 라벨 (UserStatus::label()) |
| status_variant | string | `success` | 상태 표시 색상/스타일 변형 키 (UserStatus::variant() — UI 배지용) |
| is_admin | boolean | `true` | 관리자 역할 보유 여부 (User::isAdmin()) |
| homepage | string\|null | `null` | 홈페이지 URL |
| mobile | string\|null | `null` | 휴대폰 번호 |
| phone | string\|null | `null` | 전화번호 |
| zipcode | string\|null | `null` | 우편번호 |
| address | string\|null | `null` | 기본 주소 |
| address_detail | string\|null | `null` | 상세 주소 |
| signature | string\|null | `null` | 서명 |
| bio | string\|null | `null` | 자기소개 |
| last_login_at | string\|null | `2026-07-07 10:41:24` | 마지막 로그인 일시 (미로그인 시 null) |
| email_verified_at | string\|null | `2026-07-08 10:41:24` | 이메일 인증 일시 (미인증 시 null) |
| timezone | string\|null | `Asia/Seoul` | 사용자 시간대 |
| created_at | string | `2026-07-08 10:41:24` | 생성 일시 |
| updated_at | string | `2026-07-08 10:41:24` | 최종 수정 일시 |
| is_owner | boolean | `false` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_create":true,"can_update":true,"can_delete":true,"can_assign_roles":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "사용자 검색을 성공적으로 완료했습니다.",
    "data": [
        {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "nickname": null,
            "email": "apidoc-sample-user@example.com",
            "avatar": null,
            "language": "ko",
            "language_label": "한국어",
            "country": "KR",
            "status": "active",
            "status_label": "활성",
            "status_variant": "success",
            "is_admin": true,
            "homepage": null,
            "mobile": null,
            "phone": null,
            "zipcode": null,
            "address": null,
            "address_detail": null,
            "signature": null,
            "bio": null,
            "last_login_at": "2026-07-07 10:41:24",
            "email_verified_at": "2026-07-08 10:41:24",
            "timezone": "Asia/Seoul",
            "created_at": "2026-07-08 10:41:24",
            "updated_at": "2026-07-08 10:41:24",
            "is_owner": true,
            "abilities": {
                "can_read": true,
                "can_create": true,
                "can_update": true,
                "can_delete": true,
                "can_assign_roles": true
            }
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.users.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지 — `keyword`/`uuid` 를 모두 생략한 경우 포함) |
| 500 | Internal Server Error | 검색 중 예외 발생 (`user.search_failed`) |

<!-- @generated:end -->

**설명**

사용자를 검색해 `UserResource` 컬렉션으로 반환합니다. `uuid` 를 주면 해당 UUID 의 단일 사용자(존재 시 1건, 없으면 빈 배열)를, 없으면 `keyword` 로 이름·닉네임·이메일을 부분 일치 검색한다(`keyword` 와 `uuid` 중 하나는 필수). 알림 템플릿 수신자 지정이나 활동 로그 필터의 사용자 선택 UI 에서 사용한다.


### GET /api/admin/users/statistics
<!-- @generated:start:api.admin.users.statistics -->
- **라우트명**: `api.admin.users.statistics`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\UserController@statistics`
- **인증/권한**: `auth:sanctum` + `permission:core.users.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/users/statistics HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| total_users | integer | `82` | 전체 사용자 수 (통계 객체는 count/추이 포함) |
| users_this_week | integer | `82` | 이번 주 신규 가입자 수 |
| users_this_month | integer | `82` | 이번 달 신규 가입자 수 |
| users_today | integer | `70` | 오늘 신규 가입자 수 |
| active_users_this_week | integer | `3` | 이번 주 활동(로그인) 사용자 수 |
| language_distribution | object | `{"ko":82}` | 언어별 사용자 분포 (언어 코드 => 사용자 수) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "사용자 통계를 성공적으로 가져왔습니다.",
    "data": {
        "total_users": 82,
        "users_this_week": 82,
        "users_this_month": 82,
        "users_today": 70,
        "active_users_this_week": 3,
        "language_distribution": {
            "ko": 82
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.users.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자 대시보드의 사용자 통계 위젯이 소비하는 집계 API. 캐시 없이 실시간 집계.


### DELETE /api/admin/users/{user}
<!-- @generated:start:api.admin.users.destroy -->
- **라우트명**: `api.admin.users.destroy`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\UserController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:core.users.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| user | path | string | 예 | — | 대상 user의 식별자 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.user.delete_validation_rules`).

**요청 예시**

```http
DELETE /api/admin/users/{user} HTTP/1.1
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
    "message": "사용자가 성공적으로 삭제되었습니다.",
    "data": null
}
```

> 슈퍼 관리자 계정을 대상으로 하면 삭제되지 않고 `422`(`exceptions.cannot_delete_super_admin`)로 거부된다. 삭제는 CASCADE 에 의존하지 않고 연관 데이터를 명시적으로 정리한 뒤 수행되며, 정리 단계에서 실패하면 `422` 와 함께 `error.errors.general[0]` 에 상세 사유가 담긴다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.users.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

지정한 사용자(경로 파라미터는 UUID 로 바인딩)를 삭제합니다. 슈퍼관리자 계정은 삭제할 수 없으며 시도 시 422(`exceptions.cannot_delete_super_admin`)를 반환한다. 그 외 삭제 실패 시에는 실패 상세 사유가 담긴 422 를, 나머지 오류는 500 을 반환한다. 삭제는 Service 계층에서 관련 데이터 정리와 훅을 거쳐 처리된다.

삭제는 원자적으로 처리된다 — 역할 연결 해제·약관 동의 이력 삭제·토큰 삭제·계정 삭제가 하나의 트랜잭션이며, 마지막 삭제가 외래키 제약 등으로 실패하면 앞 단계도 전부 취소된다(연결만 끊긴 활성 계정이 남지 않는다). 아바타 파일 삭제는 커밋 후에 수행된다.


### GET /api/admin/users/{user}
<!-- @generated:start:api.admin.users.show -->
- **라우트명**: `api.admin.users.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\UserController@show`
- **인증/권한**: `auth:sanctum` + `permission:core.users.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| user | path | string | 예 | — | 대상 user의 식별자 |

**요청 예시**

```http
GET /api/admin/users/{user} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| uuid | string | `a26219fc-94a0-4f63-9404-04c2a6ac99e4` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| name | string | `최고관리자` | 사용자 이름 |
| nickname | string | `최고관리자` | 닉네임 |
| email | string | `heuristing@gmail.com` | 이메일 주소 |
| avatar | null | `null` | 프로필 아바타 이미지 URL (미설정 시 null) |
| language | string | `ko` | 사용자 언어 설정 (ko: 한국어, en: 영어) |
| language_label | string | `한국어` | 언어 코드의 현지화 라벨 (user.language.{code} 번역) |
| country | null | `null` | 국가 코드 (ISO 3166-1 alpha-2) |
| status | string | `active` | 계정 상태 (active: 활성, inactive: 비활성, blocked: 차단, withdrawn: 탈퇴) |
| status_label | string | `활성` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| status_variant | string | `success` | 상태 표시 색상/스타일 변형 키 (상태 Enum variant() 산물 — UI 배지용) |
| is_admin | boolean | `true` | 관리자 역할 보유 여부 (User::isAdmin() — 역할 관계 기반 파생) |
| homepage | null | `null` | 홈페이지 URL |
| mobile | null | `null` | 휴대폰 번호 |
| phone | null | `null` | 전화번호 |
| zipcode | null | `null` | 우편번호 |
| address | null | `null` | 기본 주소 |
| address_detail | null | `null` | 상세 주소 |
| signature | null | `null` | 서명 |
| bio | null | `null` | 자기소개 |
| last_login_at | string | `2026-08-04 19:00:10` | last login 일시 |
| email_verified_at | string | `2026-07-30 23:37:44` | email verified 일시 |
| timezone | string | `Asia/Seoul` | 사용자 시간대 (예: Asia/Seoul, UTC) |
| modules_count | integer | `0` | modules 개수 (집계) |
| plugins_count | integer | `0` | plugins 개수 (집계) |
| menus_count | integer | `0` | menus 개수 (집계) |
| modules | array | `[]` | 이 사용자가 접근 권한을 가진 모듈 목록 (역할 경유 권한 관계 파생) |
| plugins | array | `[]` | 이 사용자가 접근 권한을 가진 플러그인 목록 (역할 경유 권한 관계 파생) |
| menus | array | `[]` | 이 사용자가 접근 가능한 관리자 메뉴 목록 (원소: id/title/url — 역할 경유 메뉴 관계 파생) |
| roles | array | `[{"id":1,"identifier":"admin","name":"관리자"}]` | 사용자에게 부여된 역할 목록 (원소: id/identifier/name — 역할 관계 파생) |
| permissions | array | `[]` | 연결된 권한 목록 (id/identifier/name — 역할 경유 권한 관계 파생) |
| consents | array | `[]` | 사용자가 동의한 약관 동의 레코드 목록 (약관 관계 파생) |
| terms_consent | null | `null` | 이용약관 동의 정보 (동의 시각 등, 미동의 시 null) |
| privacy_consent | null | `null` | 개인정보 처리방침 동의 정보 (동의 시각 등, 미동의 시 null) |
| created_at | string | `2026-07-30 23:37:44` | 생성 일시 |
| updated_at | string | `2026-08-04 19:00:10` | 최종 수정 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_create":true,"can_update":true,"can…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |
| admin_memo | null | `null` | 관리자 메모 |
| ip_address | null | `null` | 마지막 접속 IP 주소 |
| withdrawn_at | null | `null` | withdrawn 일시 |
| blocked_at | null | `null` | blocked 일시 |
| failed_login_attempts | integer | `0` | 연속 로그인 실패 횟수 |
| locked_permanently | boolean | `false` | 영구 잠금 여부. true 면 `locked_until` 과 무관하게 잠금이 유지되며, 해제는 성공 로그인 또는 관리자의 잠금 해제로만 이뤄진다 (잠금 시간 설정이 `0`= 무기한일 때 세워진다) |
| locked_until | null | `null` | 계정 잠금 해제 시각 (NULL = 잠금 없음) |
| is_locked | boolean | `false` | locked 여부 |
| notify_post_complete | boolean | `false` | 게시글 작성 완료 알림 수신 여부 (게시판 모듈 알림 설정) |
| notify_post_reply | boolean | `false` | 내 게시글 답글 알림 수신 여부 |
| notify_comment | boolean | `false` | 내 게시글 댓글 알림 수신 여부 |
| notify_reply_comment | boolean | `false` | 내 댓글 대댓글 알림 수신 여부 |
| email_subscription | boolean | `false` | 광고성 이메일 수신 동의 여부 (marketing 플러그인 채널 동의) |
| email_subscription_at | null | `null` | email subscription 일시 |
| marketing_consent | boolean | `false` | 마케팅 정보 수신 전체 동의 여부 (marketing 플러그인 마스터 키, 미동의 시 false) |
| marketing_consent_at | null | `null` | marketing consent 일시 |
| third_party_consent | boolean | `false` | 제3자 정보 제공 동의 여부 (marketing 플러그인 법적 동의 항목) |
| third_party_consent_at | null | `null` | third party consent 일시 |
| info_disclosure | boolean | `false` | 개인정보 이용 안내 동의 여부 (marketing 플러그인 법적 동의 항목) |
| info_disclosure_at | null | `null` | info disclosure 일시 |
| marketing_consent_enabled | boolean | `true` | 마케팅 동의 UI 노출 여부 (marketing 플러그인 활성화 플래그, 기본 true) |
| marketing_consent_terms_slug | string | `marketing-terms` | 마케팅 약관 slug (연결된 약관 페이지 식별자, 미설정 시 null) |
| marketing_consent_terms_slug_set | boolean | `true` | 마케팅 약관 연결 존재 여부 (프론트 약관 링크 표시 판정용) |
| third_party_consent_enabled | boolean | `true` | 제3자 제공 동의 항목 노출 여부 (marketing 플러그인 활성화 플래그) |
| third_party_consent_terms_slug | null | `null` | 제3자 제공 약관 slug (미설정 시 null) |
| third_party_consent_terms_slug_set | boolean | `false` | 제3자 제공 약관 연결 존재 여부 |
| info_disclosure_enabled | boolean | `true` | 정보 이용 안내 동의 항목 노출 여부 (marketing 플러그인 활성화 플래그) |
| info_disclosure_terms_slug | null | `null` | 정보 이용 안내 약관 slug (미설정 시 null) |
| info_disclosure_terms_slug_set | boolean | `false` | 정보 이용 안내 약관 연결 존재 여부 |
| email_subscription_enabled | boolean | `true` | 이메일 수신 채널 노출 여부 (marketing 플러그인 활성화 플래그) |
| email_subscription_terms_slug | null | `null` | 이메일 수신 약관 slug (미설정 시 null) |
| email_subscription_terms_slug_set | boolean | `false` | 이메일 수신 약관 연결 존재 여부 |
| channels | array | `[{"key":"email_subscription","label":"광고성 이메일 수신","enable…` | 관리자 정의 전체 마케팅 채널 목록 (원소: key/label/enabled/terms_slug — marketing 플러그인 주입, iteration 렌더링용) |
| consent_histories | array | `[]` | 사용자 동의 변경 이력 (원소: channel_key/action/source/created_at — marketing 플러그인 주입) |
| ecommerce_mileage | object | `{"enabled":false}` | 이커머스 마일리지 정보 (enabled 및 잔액 등 — sirsoft-ecommerce 모듈 주입) |
| ecommerce_preferred_currency | string | `KRW` | 선호 결제 통화 (sirsoft-ecommerce 모듈 주입, 미설정 시 null) |
| ecommerce_preferred_shipping_country | null | `null` | 선호 배송 국가 코드 (sirsoft-ecommerce 모듈 주입, 미설정 시 null) |
| ecommerce_preferred_shipping_country_name | null | `null` | 선호 배송 국가명 (배송 국가 코드에서 파생, sirsoft-ecommerce 모듈 주입) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "사용자 정보를 성공적으로 가져왔습니다.",
    "data": {
        "uuid": "a26219fc-94a0-4f63-9404-04c2a6ac99e4",
        "name": "최고관리자",
        "nickname": "최고관리자",
        "email": "heuristing@gmail.com",
        "avatar": null,
        "language": "ko",
        "language_label": "한국어",
        "country": null,
        "status": "active",
        "status_label": "활성",
        "status_variant": "success",
        "is_admin": true,
        "homepage": null,
        "mobile": null,
        "phone": null,
        "zipcode": null,
        "address": null,
        "address_detail": null,
        "signature": null,
        "bio": null,
        "last_login_at": "2026-08-04 19:00:10",
        "email_verified_at": "2026-07-30 23:37:44",
        "timezone": "Asia/Seoul",
        "modules_count": 0,
        "plugins_count": 0,
        "menus_count": 0,
        "modules": [],
        "plugins": [],
        "menus": [],
        "roles": [
            {
                "id": 1,
                "identifier": "admin",
                "name": "관리자"
            }
        ],
        "permissions": [],
        "consents": [],
        "terms_consent": null,
        "privacy_consent": null,
        "created_at": "2026-07-30 23:37:44",
        "updated_at": "2026-08-04 19:00:10",
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_create": true,
            "can_update": true,
            "can_delete": false,
            "can_assign_roles": true
        },
        "admin_memo": null,
        "ip_address": null,
        "withdrawn_at": null,
        "blocked_at": null,
        "failed_login_attempts": 0,
        "locked_permanently": false,
        "locked_until": null,
        "is_locked": false,
        "notify_post_complete": false,
        "notify_post_reply": false,
        "notify_comment": false,
        "notify_reply_comment": false,
        "email_subscription": false,
        "email_subscription_at": null,
        "marketing_consent": false,
        "marketing_consent_at": null,
        "third_party_consent": false,
        "third_party_consent_at": null,
        "info_disclosure": false,
        "info_disclosure_at": null,
        "marketing_consent_enabled": true,
        "marketing_consent_terms_slug": "marketing-terms",
        "marketing_consent_terms_slug_set": true,
        "third_party_consent_enabled": true,
        "third_party_consent_terms_slug": null,
        "third_party_consent_terms_slug_set": false,
        "info_disclosure_enabled": true,
        "info_disclosure_terms_slug": null,
        "info_disclosure_terms_slug_set": false,
        "email_subscription_enabled": true,
        "email_subscription_terms_slug": null,
        "email_subscription_terms_slug_set": false,
        "channels": [
            {
                "key": "email_subscription",
                "label": "광고성 이메일 수신",
                "enabled": true,
                "terms_slug": null,
                "terms_slug_set": false
            }
        ],
        "consent_histories": [],
        "ecommerce_mileage": {
            "enabled": false
        },
        "ecommerce_preferred_currency": null,
        "ecommerce_preferred_shipping_country": null,
        "ecommerce_preferred_shipping_country_name": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.users.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

특정 사용자의 상세 정보를 조회합니다(경로 파라미터는 UUID 로 바인딩). `withAdminInfo()` 를 통해 기본 필드에 더해 관리자 전용 필드(admin_memo, ip_address, withdrawn_at, blocked_at)와 관계형 데이터(modules, plugins, menus, roles, permissions, consents 및 개수 필드)를 함께 반환한다. `core.user.filter_resource_data` 필터로 확장이 자신의 필드(sirsoft-marketing 의 알림/동의 설정, sirsoft-ecommerce 의 마일리지/선호 통화·배송국 등)를 병합한다. 관리자 사용자 상세/수정 화면(`admin_user_detail.json`/`admin_user_form.json`)이 소비한다.


### PUT /api/admin/users/{user}
<!-- @generated:start:api.admin.users.update -->
- **라우트명**: `api.admin.users.update`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\UserController@update`
- **인증/권한**: `auth:sanctum` + `permission:core.users.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| user | path | string | 예 | — | 대상 user의 식별자 |
| name | body | string | 예 | max 255 | 대상의 이름/명칭 |
| nickname | body | string | 아니오 | max 50 | 닉네임 |
| email | body | email | 예 | max 255 | 이메일 주소 |
| password | body | string | 아니오 | — | 비밀번호 |
| language | body | string | 아니오 | `ko`, `en` | 언어 코드 |
| country | body | string | 아니오 | — | 국가 코드 (ISO 3166-1 alpha-2) |
| timezone | body | string | 아니오 | — | 타임존 식별자 |
| status | body | string | 아니오 | `active`, `inactive`, `blocked`, `withdrawn` | 계정 상태 (미지정 시 active) |
| homepage | body | string | 아니오 | max 255 | 홈페이지 URL |
| mobile | body | string | 아니오 | max 20 | 휴대전화 번호 |
| phone | body | string | 아니오 | max 20 | 전화번호 |
| zipcode | body | string | 아니오 | max 10 | 우편번호 |
| address | body | string | 아니오 | max 255 | 기본 주소 |
| address_detail | body | string | 아니오 | max 255 | 상세 주소 |
| signature | body | string | 아니오 | max 1000 | 서명 |
| bio | body | string | 아니오 | max 5000 | 자기소개 |
| admin_memo | body | string | 아니오 | max 5000 | 관리자 전용 메모 (해당 사용자에 대한 내부 기록, 사용자에게 노출 안 됨) |
| roles | body | array | 아니오 | min 1 | 부여할 역할 객체 배열 `[{id}]` (role_ids 와 병용 시 role_ids 우선) |
| role_ids | body | array | 아니오 | min 1 | role 식별자 배열 |
| notify_post_complete | body | boolean | 아니오 | — | 게시글 작성 완료 알림 수신 여부 (게시판 모듈 알림 설정) |
| notify_post_reply | body | boolean | 아니오 | — | 내 게시글에 답글이 달릴 때 알림 수신 여부 |
| notify_comment | body | boolean | 아니오 | — | 내 게시글에 댓글이 달릴 때 알림 수신 여부 |
| notify_reply_comment | body | boolean | 아니오 | — | 내 댓글에 대댓글이 달릴 때 알림 수신 여부 |
| email_subscription | body | boolean | 아니오 | — | 광고성 이메일 수신 동의 여부 (marketing 플러그인 채널 동의, marketing_consents 테이블에 저장) |
| marketing_consent | body | boolean | 아니오 | — | 마케팅 정보 수신 전체 동의 마스터 키 (marketing 플러그인이 검증 규칙 주입, 상세는 user-consent-injection.md) |
| third_party_consent | body | boolean | 아니오 | — | 제3자 정보 제공 동의 여부 (marketing 플러그인 법적 동의 항목) |
| info_disclosure | body | boolean | 아니오 | — | 개인정보 이용 안내 동의 여부 (marketing 플러그인 법적 동의 항목) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.user.update_validation_rules`).

**요청 예시**

```http
PUT /api/admin/users/{user} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": "예시 이름",
    "nickname": "예시 이름",
    "email": "user@example.com",
    "password": "Password123!",
    "language": "ko",
    "country": "KR",
    "timezone": "Asia/Seoul",
    "status": "active",
    "homepage": "https://example.com",
    "mobile": "010-1234-5678",
    "phone": "010-1234-5678",
    "zipcode": "06234",
    "address": "서울특별시 강남구 테헤란로 1",
    "address_detail": "서울특별시 강남구 테헤란로 1",
    "signature": "예시값",
    "bio": "예시 내용입니다.",
    "admin_memo": "예시값",
    "roles": [
        "예시값"
    ],
    "role_ids": [
        "예시값"
    ],
    "notify_post_complete": true,
    "notify_post_reply": true,
    "notify_comment": true,
    "notify_reply_comment": true,
    "email_subscription": true,
    "marketing_consent": true,
    "third_party_consent": true,
    "info_disclosure": true
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (수정된 사용자 — `UserResource::toArray()`, 관계 미로드이므로 modules/plugins/menus/roles/permissions/consents 키는 응답에 포함되지 않음)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| uuid | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| name | string | `API 문서 샘플 사용자` | 사용자 이름 |
| nickname | string\|null | `null` | 닉네임 |
| email | string | `apidoc-sample-user@example.com` | 이메일 주소 |
| avatar | string\|null | `null` | 프로필 아바타 이미지 URL (미설정 시 null) |
| language | string | `ko` | 사용자 언어 설정 (ko: 한국어, en: English) |
| language_label | string | `한국어` | 언어 코드의 현지화 라벨 (`user.language.{code}` 번역) |
| country | string\|null | `KR` | 국가 코드 (ISO 3166-1 alpha-2) |
| status | string | `active` | 계정 상태 (UserStatus Enum: active/inactive/blocked/withdrawn) |
| status_label | string | `활성` | 상태의 사람이 읽는 라벨 (UserStatus::label()) |
| status_variant | string | `success` | 상태 표시 색상/스타일 변형 키 (UserStatus::variant() — UI 배지용) |
| is_admin | boolean | `true` | 관리자 역할 보유 여부 (User::isAdmin()) |
| homepage | string\|null | `https://example.com` | 홈페이지 URL |
| mobile | string\|null | `010-1234-5678` | 휴대폰 번호 |
| phone | string\|null | `null` | 전화번호 |
| zipcode | string\|null | `null` | 우편번호 |
| address | string\|null | `null` | 기본 주소 |
| address_detail | string\|null | `null` | 상세 주소 |
| signature | string\|null | `null` | 서명 |
| bio | string\|null | `null` | 자기소개 |
| last_login_at | string\|null | `2026-07-07 10:41:24` | 마지막 로그인 일시 (미로그인 시 null) |
| email_verified_at | string\|null | `2026-07-08 10:41:24` | 이메일 인증 일시 (미인증 시 null) |
| timezone | string\|null | `Asia/Seoul` | 사용자 시간대 |
| created_at | string | `2026-07-08 10:41:24` | 생성 일시 |
| updated_at | string | `2026-07-08 11:02:07` | 최종 수정 일시 |
| is_owner | boolean | `false` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_create":true,"can_update":true,"can_delete":true,"can_assign_roles":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (슈퍼관리자 대상은 can_delete=false 강제) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "사용자 정보가 성공적으로 업데이트되었습니다.",
    "data": {
        "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "name": "예시 이름",
        "nickname": null,
        "email": "user@example.com",
        "avatar": null,
        "language": "ko",
        "language_label": "한국어",
        "country": "KR",
        "status": "active",
        "status_label": "활성",
        "status_variant": "success",
        "is_admin": false,
        "homepage": "https://example.com",
        "mobile": "010-1234-5678",
        "phone": null,
        "zipcode": null,
        "address": null,
        "address_detail": null,
        "signature": null,
        "bio": null,
        "last_login_at": null,
        "email_verified_at": null,
        "timezone": "Asia/Seoul",
        "created_at": "2026-07-08 10:41:24",
        "updated_at": "2026-07-08 11:02:07",
        "is_owner": false,
        "abilities": {
            "can_read": true,
            "can_create": true,
            "can_update": true,
            "can_delete": true,
            "can_assign_roles": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.users.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지 — 마지막 관리자 본인의 admin 역할 제거 시도 시 `user.last_admin_role_cannot_remove` 포함) |
| 500 | Internal Server Error | 수정 중 예외 발생 (`user.update_failed`, `errors.error` 에 예외 메시지) |

<!-- @generated:end -->

**설명**

기존 사용자 정보를 수정합니다(경로 파라미터는 UUID 로 바인딩). `name`, `email` 은 필수이며 `email` 은 해당 사용자를 제외한 고유성 검사를 거친다. `password` 는 선택이며 값을 주면 8자 이상·`password_confirmation` 확인을 요구한다(미전송 시 기존 비밀번호 유지). 역할은 `roles` 또는 `role_ids` 중 하나로 지정하고 둘 다 오면 `role_ids` 가 우선한다. 성공 시 갱신된 사용자를 `UserResource` 로 반환한다. `notify_*`/`marketing_consent`/`third_party_consent`/`info_disclosure`/`email_subscription` 파라미터는 확장(sirsoft-marketing)이 검증 규칙을 주입한 필드로, 상세는 해당 확장 문서를 참조한다.


### POST /api/admin/users/{user}/unlock
<!-- @generated:start:api.admin.users.unlock -->
- **라우트명**: `api.admin.users.unlock`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\UserController@unlock`
- **인증/권한**: `auth:sanctum` + `permission:core.users.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| user | path | string | 예 | — | 대상 user 의 UUID |

**요청 예시**

```http
POST /api/admin/users/a26219fc-94a0-4f63-9404-04c2a6ac99e4/unlock HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| uuid | string | `a26219fc-94a0-4f63-9404-04c2a6ac99e4` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| name | string | `최고관리자` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| nickname | string | `최고관리자` | 닉네임 |
| email | string | `heuristing@gmail.com` | 이메일 주소 |
| avatar | null | `null` | 아바타 이미지 URL (미등록 시 null) |
| language | string | `ko` | 사용자 언어 설정 (ko: 한국어, en: 영어) |
| language_label | string | `한국어` | 언어 코드의 현지화 라벨 (user.language.{code} 번역) |
| country | null | `null` | 국가 코드 (ISO 3166-1 alpha-2) |
| status | string | `active` | 상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조) |
| status_label | string | `활성` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| status_variant | string | `success` | 상태 표시 색상/스타일 변형 키 (상태 Enum variant() 산물 — UI 배지용) |
| is_admin | boolean | `true` | 관리자 역할 보유 여부 (User::isAdmin() — 역할 관계 기반 파생) |
| homepage | null | `null` | 홈페이지 URL |
| mobile | null | `null` | 휴대폰 번호 |
| phone | null | `null` | 전화번호 |
| zipcode | null | `null` | 우편번호 |
| address | null | `null` | 기본 주소 |
| address_detail | null | `null` | 상세 주소 |
| signature | null | `null` | 서명 |
| bio | null | `null` | 자기소개 |
| last_login_at | string | `2026-08-04 19:00:10` | last login 일시 |
| email_verified_at | string | `2026-07-30 23:37:44` | email verified 일시 |
| timezone | string | `Asia/Seoul` | 사용자 시간대 (예: Asia/Seoul, UTC) |
| created_at | string | `2026-07-30 23:37:44` | 생성 일시 |
| updated_at | string | `2026-08-04 19:00:10` | 최종 수정 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_create":true,"can_update":true,"can…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "계정 잠금이 해제되었습니다.",
    "data": {
        "uuid": "a26219fc-94a0-4f63-9404-04c2a6ac99e4",
        "name": "최고관리자",
        "nickname": "최고관리자",
        "email": "heuristing@gmail.com",
        "avatar": null,
        "language": "ko",
        "language_label": "한국어",
        "country": null,
        "status": "active",
        "status_label": "활성",
        "status_variant": "success",
        "is_admin": true,
        "homepage": null,
        "mobile": null,
        "phone": null,
        "zipcode": null,
        "address": null,
        "address_detail": null,
        "signature": null,
        "bio": null,
        "last_login_at": "2026-08-04 19:00:10",
        "email_verified_at": "2026-07-30 23:37:44",
        "timezone": "Asia/Seoul",
        "created_at": "2026-07-30 23:37:44",
        "updated_at": "2026-08-04 19:00:10",
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_create": true,
            "can_update": true,
            "can_delete": false,
            "can_assign_roles": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.users.update`)이 없는 경우 |
| 404 | Not Found | 해당 UUID 의 사용자가 없는 경우 |

<!-- @generated:end -->

**설명**

로그인 실패 누적으로 잠긴 계정의 잠금을 관리자가 수동으로 해제합니다. 로그인 시도 추적 값
(`failed_login_attempts` / `locked_until` / `locked_permanently`)을 모두 초기화합니다.

보안 환경설정의 잠금 시간(`security.login_lockout_time`)을 `0`(무한대)으로 두면 계정이 무기한
잠기며, 이 경우 자동 해제도 성공 로그인도 불가능하므로 **이 엔드포인트가 유일한 복구 경로**입니다.
해제는 활동 로그(`auth.account_unlocked`)에 기록됩니다.


### GET /api/users/{user}/profile
<!-- @generated:start:api.public.users.profile -->
- **라우트명**: `api.public.users.profile`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicProfileController@show`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| user | path | string | 예 | — | 대상 user의 식별자 |

**요청 예시**

```http
GET /api/users/{user}/profile HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| uuid | string | `a26219fc-94a0-4f63-9404-04c2a6ac99e4` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| name | string | `최고관리자` | 사용자 이름 |
| status | string | `active` | 계정 상태 (active: 활성, inactive: 비활성, blocked: 차단, withdrawn: 탈퇴) |
| status_label | string | `활성` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| avatar | null | `null` | 프로필 아바타 이미지 URL (미설정 시 null) |
| bio | null | `null` | 자기소개 |
| created_at | string | `2026-07-30` | 생성 일시 |
| is_withdrawn | boolean | `false` | withdrawn 여부 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "프로필 정보를 성공적으로 가져왔습니다.",
    "data": {
        "uuid": "a26219fc-94a0-4f63-9404-04c2a6ac99e4",
        "name": "최고관리자",
        "status": "active",
        "status_label": "활성",
        "avatar": null,
        "bio": null,
        "created_at": "2026-07-30",
        "is_withdrawn": false
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.users.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

타인의 공개 프로필을 인증 없이 조회합니다(경로 파라미터는 UUID 로 바인딩, `users/show.json` 이 소비). 사용자 상태에 따라 노출 필드가 달라진다 — active 는 name/avatar/bio/created_at 전체, inactive 는 bio 를 제외, blocked 는 avatar/bio/created_at 를 모두 제외한다. withdrawn 사용자는 이름을 익명 표기로 대체하고 `is_withdrawn=true` 로 반환하며, 미존재 사용자는 404(`user.not_found`)를 반환한다. 게시글 통계는 게시판 모듈 API 로 별도 조회한다.


