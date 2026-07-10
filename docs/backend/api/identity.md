# Identity API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Identity 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/identity/logs
<!-- @generated:start:api.admin.identity.logs.index -->
- **라우트명**: `api.admin.identity.logs.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Identity\AdminIdentityLogController@index`
- **인증/권한**: `auth:sanctum` + `permission:core.admin.identity.logs.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| provider_id | query | string | 아니오 | max 64 | provider 식별자 |
| purpose | query | string | 아니오 | max 64 | 인증 목적 필터 (signup/password_reset/self_update/sensitive_action 또는 모듈 정의 목적) |
| status | query | string | 아니오 | — | 상태 필터 (해당 상태의 항목만 조회) |
| channel | query | string | 아니오 | max 16 | 전송 채널 필터 (email 등 — 해당 채널로 시도한 이력만) |
| origin_type | query | string | 아니오 | — | 인증 트리거 출처 유형 필터 (route/hook/policy/middleware/api/custom/system — IdentityOriginType) |
| source_type | query | string | 아니오 | — | 정책 출처 필터 (core/module/plugin/admin — 어느 확장이 인증을 요구했는지, IdentityPolicySourceType) |
| source_identifier | query | string | 아니오 | max 100 | 출처 식별자 |
| provider_ids | query | array | 아니오 | — | provider 식별자 배열 |
| purposes | query | array | 아니오 | — | 인증 목적 다중선택 필터 (여러 목적 중 하나라도 일치) |
| statuses | query | array | 아니오 | — | 상태 다중선택 필터 (여러 상태 중 하나라도 일치) |
| channels | query | array | 아니오 | — | 전송 채널 다중선택 필터 (여러 채널 중 하나라도 일치) |
| origin_types | query | array | 아니오 | — | 출처 유형 다중선택 필터 (여러 origin_type 중 하나라도 일치) |
| user_id | query | integer | 아니오 | min 1 | user 식별자 |
| target_hash | query | string | 아니오 | — | 인증 대상 해시 필터 (SHA256(email\|phone), PII 원본 대신 해시로 추적) |
| search | query | string | 아니오 | max 64 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| search_type | query | string | 아니오 | `auto`, `user_id`, `target_hash`, `ip_address`, `policy_key` | 검색 유형 (검색 대상/방식 구분) |
| sort_by | query | string | 아니오 | `created_at`, `attempts` | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| date_from | query | date | 아니오 | — | 조회 기간 시작일 |
| date_to | query | date | 아니오 | — | 조회 기간 종료일 |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |

**요청 예시**

```http
GET /api/admin/identity/logs?provider_id=%EC%98%88%EC%8B%9C%EA%B0%92&purpose=%EC%98%88%EC%8B%9C%EA%B0%92&status=%EC%98%88%EC%8B%9C%EA%B0%92&channel=%EC%98%88%EC%8B%9C%EA%B0%92&origin_type=%EC%98%88%EC%8B%9C%EA%B0%92&source_type=%EC%98%88%EC%8B%9C%EA%B0%92&source_identifier=example-key&provider_ids=%EC%98%88%EC%8B%9C%EA%B0%92&purposes=%EC%98%88%EC%8B%9C%EA%B0%92&statuses=%EC%98%88%EC%8B%9C%EA%B0%92&channels=%EC%98%88%EC%8B%9C%EA%B0%92&origin_types=%EC%98%88%EC%8B%9C%EA%B0%92&user_id=1&target_hash=%EC%98%88%EC%8B%9C%EA%B0%92&search=%EC%98%88%EC%8B%9C%EA%B0%92&search_type=auto&sort_by=created_at&sort_order=asc&date_from=2026-01-01&date_to=2026-01-01&per_page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | string | `e6ab6cd6-cdff-46cd-b4c2-b89dfda8745b` | 기본 키 (내부 식별자) |
| provider_id | string | `inicis` | provider 식별자 (연관 리소스 참조) |
| purpose | string | `sensitive_action` | 인증 목적 (signup/password_reset/self_update/sensitive_action 또는 모듈 정의 목적) |
| channel | string | `ipin` | 인증에 사용된 전송 채널 (email 등 코어 채널 또는 모듈 provider 자체 식별자) |
| user_id | integer | `130` | user 식별자 (연관 리소스 참조) |
| target_hash | string | `d88d36166bbeffc41eb6994e390f4715280c1…` | 인증 대상 해시 (SHA256(email\|phone) — PII 원본 저장 회피) |
| status | string | `cancelled` | 인증 시도 결과 상태 (requested/sent/processing/verified/failed/expired/cancelled/policy_violation_logged) |
| attempts | integer | `0` | 현재까지 누적된 검증 시도 횟수 |
| max_attempts | integer | `0` | 허용되는 최대 검증 시도 횟수 (초과 시 실패 처리) |
| ip_address | string | `127.0.0.1` | 요청/행위가 발생한 IP 주소 |
| user_agent | string | `Mozilla/5.0 (Windows NT 10.0; Win64; …` | 요청 클라이언트의 User-Agent 문자열 |
| origin_type | string | `api` | 인증 트리거 출처 유형 (route/hook/policy/middleware/api/custom/system — IdentityOriginType) |
| origin_identifier | string | `/api/identity/challenges` | 실제 트리거 경로/훅명 (예: PUT /api/me/password, core.user.before_update) |
| origin_policy_key | null | `null` | 정책이 인증을 강제한 경우 해당 identity_policies.key (정책 외 트리거는 null) |
| properties | null | `null` | 요청 페이로드 요약 (감사용 부가 정보, 없으면 null) |
| metadata | object | `{"mid":"INIiasTest","reqSvcCd":"03","mtxid_hash":"981546e…` | 프로바이더 내부 데이터 (코드 해시·외부 인증 식별자 등, PII 원본 미포함) |
| created_at | string | `2026-06-27 19:06:17` | 생성 일시 |
| verified_at | string | `2026-06-27 18:58:30` | verified 일시 |
| expires_at | string | `2026-06-27 19:21:17` | expires 일시 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "data": [],
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 0,
            "from": null,
            "to": null,
            "has_more_pages": false
        },
        "abilities": {
            "can_purge": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.admin.identity.logs.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 본인인증 시도 이력(성공/실패/취소/정책위반)을 관리자 화면에서 필터·검색·정렬하여 페이지네이션 조회합니다. `auth:sanctum` + `core.admin.identity.logs.read` 관리자 권한이 필요합니다. `IdentityLogService::search` 로 프로바이더·목적·상태·채널·기간 등 다중 필터를 적용하며, 응답의 `abilities.can_purge` 로 파기 권한 보유 여부를 함께 내려 UI 버튼 노출을 제어합니다. 관리자 IDV 이력 대시보드에서 특정 사용자(`user_id`)나 대상 해시(`target_hash`)로 감사 추적할 때 사용합니다.


### POST /api/admin/identity/logs/purge
<!-- @generated:start:api.admin.identity.logs.purge -->
- **라우트명**: `api.admin.identity.logs.purge`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Identity\AdminIdentityLogController@purge`
- **인증/권한**: `auth:sanctum` + `permission:core.admin.identity.logs.purge`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| older_than_days | body | integer | 아니오 | min 1, max 3650 | 파기 기준 보관일수 (지정 일수보다 오래된 이력만 삭제, 미지정 시 기본 180일) |

**요청 예시**

```http
POST /api/admin/identity/logs/purge HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "older_than_days": 1
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| purged_count | integer | `0` | purged 개수 (집계) |
| older_than_days | integer | `1` | 기준 경과 일수 (이 일수보다 오래된 대상 필터/집계) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "purged_count": 0,
        "older_than_days": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.admin.identity.logs.purge`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 지정한 보관주기(`older_than_days`, 기본 180일) 를 경과한 본인인증 이력을 일괄 삭제하고 삭제된 행 수를 반환합니다. `auth:sanctum` + `core.admin.identity.logs.purge` 관리자 권한이 필요합니다. `IdentityLogService::purge` 가 실제 삭제를 수행하며 되돌릴 수 없으므로, 개인정보 보관기간 정책 준수를 위해 오래된 인증 시도 로그를 정리할 때 사용합니다.


### GET /api/admin/identity/messages/definitions
<!-- @generated:start:api.admin.identity.messages.definitions.index -->
- **라우트명**: `api.admin.identity.messages.definitions.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Identity\AdminIdentityMessageDefinitionController@index`
- **인증/권한**: `auth:sanctum` + `permission:core.admin.identity.messages.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| search | query | string | 아니오 | max 255 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| provider_id | query | string | 아니오 | max 64 | provider 식별자 |
| scope_type | query | string | 아니오 | — | 메시지 정의 스코프 필터 (provider_default/purpose/policy — 어느 계층 템플릿인지, IdentityMessageScopeType) |
| scope_value | query | string | 아니오 | max 120 | 스코프 값 필터 (provider_default 빈값 / purpose 키 / policy 키) |
| extension_type | query | string | 아니오 | — | 확장 유형 (core/module/plugin/template) |
| extension_identifier | query | string | 아니오 | max 100 | 확장 식별자 |
| channel | query | string | 아니오 | max 20 | 메시지 채널 필터 (mail 등 — 해당 채널 템플릿을 가진 정의만) |
| is_active | query | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| sort_by | query | string | 아니오 | — | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | — | 정렬 방향 (asc 오름차순 / desc 내림차순) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.identity.message_definition.filter_index_rules`).

**요청 예시**

```http
GET /api/admin/identity/messages/definitions?search=%EC%98%88%EC%8B%9C%EA%B0%92&provider_id=%EC%98%88%EC%8B%9C%EA%B0%92&scope_type=%EC%98%88%EC%8B%9C%EA%B0%92&scope_value=%EC%98%88%EC%8B%9C%EA%B0%92&extension_type=%EC%98%88%EC%8B%9C%EA%B0%92&extension_identifier=example-key&channel=%EC%98%88%EC%8B%9C%EA%B0%92&is_active=1&per_page=1&sort_by=%EC%98%88%EC%8B%9C%EA%B0%92&sort_order=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
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
| provider_id | string | `g7:core.mail` | provider 식별자 (연관 리소스 참조) |
| scope_type | string | `provider_default` | 메시지 정의 스코프 (provider_default: 프로바이더 기본 / purpose: 목적별 / policy: 정책별 — IdentityMessageScopeType) |
| scope_value | string | `` | 스코프 값 (provider_default 빈 문자열 / purpose 목적 키 / policy 정책 키) |
| name | object | `{"ko":"메일 본인 확인 (기본)","en":"Mail Verification (default)",…` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | object | `{"ko":"특정 목적이 매칭되지 않을 때 사용되는 기본 메일 템플릿","en":"Fallback ma…` | 설명 (다국어 필드는 로케일별 값 객체) |
| channels | array | `["mail"]` | 이 정의가 지원하는 활성 채널 목록 (현재 mail, 향후 sms 등 확장) |
| variables | array | `[{"key":"code","description":"인증 코드 (text_code 흐름)"},{"ke…` | 템플릿에서 치환 가능한 변수 메타데이터 목록 (원소 key/description) |
| extension_type | string | `core` | 이 리소스를 소유한 확장의 타입 (core/module/plugin/template) |
| extension_identifier | string | `core` | 이 리소스를 소유한 확장의 식별자 |
| is_active | boolean | `true` | active 여부 |
| is_default | boolean | `true` | default 여부 |
| user_overrides | array | `["name.ja"]` | 운영자가 시드 기본값에서 수정한 필드 경로 목록 (예: name.ja — 시더 재실행 시 보존 대상) |
| templates | array | `[{"id":1,"definition_id":1,"channel":"mail","subject":{"k…` | 이 정의에 속한 채널별 하위 메시지 템플릿 목록 (원소 id/channel/subject/body 등, eager load 시에만 포함) |
| created_at | string | `2026-05-27 15:20:18` | 생성 일시 |
| updated_at | string | `2026-06-30 13:33:16` | 최종 수정 일시 |
| abilities | object | `{"can_update":true,"can_delete":false}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "IDV 메시지 정의 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "number": 1,
                "id": 1,
                "provider_id": "g7:core.mail",
                "scope_type": "purpose",
                "scope_value": "checkout_verification",
                "name": {
                    "ko": "결제 시 본인 확인",
                    "en": "Checkout Verification"
                },
                "description": {
                    "ko": "결제 진행 전 본인/성인 확인 인증 코드 메일",
                    "en": "Identity/adult verification code mail before checkout"
                },
                "channels": [
                    "mail"
                ],
                "variables": [
                    {
                        "key": "code",
                        "description": "인증 코드 (text_code 흐름)"
                    },
                    {
                        "key": "expire_minutes",
                        "description": "만료까지 남은 분"
                    },
                    {
                        "key": "purpose_label",
                        "description": "인증 목적 라벨"
                    },
                    {
                        "key": "app_name",
                        "description": "사이트명"
                    },
                    {
                        "key": "site_url",
                        "description": "사이트 URL"
                    },
                    {
                        "key": "recipient_email",
                        "description": "수신자 이메일"
                    }
                ],
                "extension_type": "module",
                "extension_identifier": "sirsoft-ecommerce",
                "is_active": true,
                "is_default": true,
                "user_overrides": null,
                "templates": [
                    {
                        "id": 1,
                        "definition_id": 1,
                        "channel": "mail",
                        "subject": {
                            "ko": "[{app_name}] 결제 본인 확인 인증 코드",
                            "en": "[{app_name}] Checkout Verification Code"
                        },
                        "body": {
                            "ko": "<h1>결제 본인 확인</h1><p>결제를 진행하기 위해 본인 확인이 필요합니다. 아래 인증 코드를 입력해 주세요.</p><p style=\"font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;\">{code}</p><p>이 코드는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p><p><strong>본인이 결제를 진행하지 않았다면 이 메일을 무시하고 즉시 비밀번호를 변경해 주세요.</strong></p><p>감사합니다,<br><a href=\"{site_url}\">{app_name}</a></p>",
                            "en": "<h1>Checkout Verification</h1><p>Please enter the code below to proceed with payment.</p><p style=\"font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;\">{code}</p><p>This code will expire in <strong>{expire_minutes} minutes</strong>.</p><p><strong>If you did not initiate this payment, please ignore this email and change your password immediately.</strong></p><p>Thank you,<br><a href=\"{site_url}\">{app_name}</a></p>"
                        },
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
                    "can_delete": false
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
            "can_create": true,
            "can_update": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.admin.identity.messages.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 본인인증 알림 메시지 정의(프로바이더별·목적별·정책별 메일/SMS 템플릿 묶음) 목록을 필터·검색하여 페이지네이션 조회합니다. `auth:sanctum` + `core.admin.identity.messages.read` 관리자 권한이 필요합니다. 확장이 `core.identity.message_definition.filter_index_rules` 필터 훅으로 추가 검색 파라미터를 등록할 수 있습니다. 응답 각 항목의 `user_overrides` 로 운영자가 시드 기본값에서 수정한 필드를, `templates` 로 채널별 하위 템플릿을 함께 내려 관리자 메시지 설정 화면을 구성할 때 사용합니다.


### POST /api/admin/identity/messages/definitions
<!-- @generated:start:api.admin.identity.messages.definitions.store -->
- **라우트명**: `api.admin.identity.messages.definitions.store`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Identity\AdminIdentityMessageDefinitionController@store`
- **인증/권한**: `auth:sanctum` + `permission:core.admin.identity.messages.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| provider_id | body | string | 예 | max 64 | provider 식별자 |
| scope_type | body | string | 예 | — | 메시지 정의 스코프 (관리자 생성은 policy 만 허용 — provider_default/purpose 는 시드 영역) |
| scope_value | body | string | 예 | max 120 | 스코프 값 (source_type='admin' 인 IdentityPolicy.key 와 일치해야 함) |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| description | body | array | 아니오 | — | 설명 |
| channels | body | array | 예 | min 1 | 지원 채널 목록 (최소 1개, 현재 mail 만 허용) |
| variables | body | array | 아니오 | — | 템플릿 치환 변수 메타데이터 목록 (원소 key/description, key 는 영문 식별자) |
| templates | body | array | 예 | min 1 | 채널별 하위 템플릿 배열 (최소 1개, 원소 channel/subject/body 다국어 배열) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.identity.message_definition.filter_store_rules`).

**요청 예시**

```http
POST /api/admin/identity/messages/definitions HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "provider_id": "예시값",
    "scope_type": "예시값",
    "scope_value": "예시값",
    "name": [
        "예시 이름"
    ],
    "description": [
        "예시 내용입니다."
    ],
    "channels": [
        "예시값"
    ],
    "variables": [
        "예시값"
    ],
    "templates": [
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
| 403 | Forbidden | 요구 권한(`core.admin.identity.messages.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 운영자가 정책(policy) 매핑용 메시지 정의를 신규 생성합니다. `auth:sanctum` + `core.admin.identity.messages.update` 관리자 권한이 필요합니다. `IdentityMessageDefinitionService::createAdminDefinition` 이 처리하며 `scope_type='policy'` + `scope_value` 가 admin policy.key 와 매칭되는 경우만 허용됩니다(FormRequest 검증). `channels`·`templates` 를 최소 1개 이상 포함해야 하며, 확장은 `core.identity.message_definition.filter_store_rules` 필터 훅으로 검증 규칙을 확장할 수 있습니다. 특정 인증 정책에 전용 메일/SMS 문구를 붙이고자 할 때 사용하며 성공 시 201 로 응답합니다.


### DELETE /api/admin/identity/messages/definitions/{definition}
<!-- @generated:start:api.admin.identity.messages.definitions.destroy -->
- **라우트명**: `api.admin.identity.messages.definitions.destroy`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Identity\AdminIdentityMessageDefinitionController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:core.admin.identity.messages.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| definition | path | string | 예 | — | 대상 definition의 식별자 |

**요청 예시**

```http
DELETE /api/admin/identity/messages/definitions/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-403 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.admin.identity.messages.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 운영자가 추가한 메시지 정의를 삭제합니다. `auth:sanctum` + `core.admin.identity.messages.update` 관리자 권한이 필요합니다. 시드로 제공되는 `is_default=true` 정의는 삭제가 거부되어 403 을 반환하며(선언형 보호), 삭제 시 FK cascade 로 자식 템플릿이 함께 제거됩니다. 잘못 만들었거나 더 이상 쓰지 않는 정책 전용 메시지 정의를 정리할 때 사용합니다.


### GET /api/admin/identity/messages/definitions/{definition}
<!-- @generated:start:api.admin.identity.messages.definitions.show -->
- **라우트명**: `api.admin.identity.messages.definitions.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Identity\AdminIdentityMessageDefinitionController@show`
- **인증/권한**: `auth:sanctum` + `permission:core.admin.identity.messages.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| definition | path | string | 예 | — | 대상 definition의 식별자 |

**요청 예시**

```http
GET /api/admin/identity/messages/definitions/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| provider_id | string | `g7:core.mail` | IDV 프로바이더 ID (예: g7:core.mail, kcp, portone) |
| scope_type | string | `provider_default` | 메시지 정의 스코프 (provider_default\|purpose\|policy) — App\Enums\IdentityMessageScopeType enum |
| scope_value | string | `` | 범위 값: provider_default 빈 문자열 / purpose 키 / policy 키 |
| name | object | `{"ko":"메일 본인 확인 (기본)","en":"Mail Verification (default)",…` | 다국어 표시명 ({"ko":"...", "en":"..."}) |
| description | object | `{"ko":"특정 목적이 매칭되지 않을 때 사용되는 기본 메일 템플릿","en":"Fallback ma…` | 다국어 설명 |
| channels | array | `["mail"]` | 활성 채널 (현재 ["mail"], 향후 sms 등 확장) |
| variables | array | `[{"key":"code","description":"인증 코드 (text_code 흐름)"},{"ke…` | 사용 가능 변수 메타데이터 ([{key, description}]) |
| extension_type | string | `core` | 확장 타입: core, module, plugin |
| extension_identifier | string | `core` | 확장 식별자 |
| is_active | boolean | `true` | active 여부 |
| is_default | boolean | `true` | default 여부 |
| user_overrides | array | `["name.ja"]` | 운영자가 수정한 필드명 목록 (예: ["name","is_active"]) |
| templates | array | `[{"id":1,"definition_id":1,"channel":"mail","subject":{"k…` | 이 정의에 속한 채널별 하위 메시지 템플릿 목록 (원소 id/channel/subject/body 등) |
| created_at | string | `2026-05-27 15:20:18` | 생성 일시 |
| updated_at | string | `2026-06-30 13:33:16` | 최종 수정 일시 |
| abilities | object | `{"can_update":true,"can_delete":false}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "IDV 메시지 정의 상세를 조회했습니다.",
    "data": {
        "id": 1,
        "provider_id": "g7:core.mail",
        "scope_type": "purpose",
        "scope_value": "checkout_verification",
        "name": {
            "ko": "결제 시 본인 확인",
            "en": "Checkout Verification"
        },
        "description": {
            "ko": "결제 진행 전 본인/성인 확인 인증 코드 메일",
            "en": "Identity/adult verification code mail before checkout"
        },
        "channels": [
            "mail"
        ],
        "variables": [
            {
                "key": "code",
                "description": "인증 코드 (text_code 흐름)"
            },
            {
                "key": "expire_minutes",
                "description": "만료까지 남은 분"
            },
            {
                "key": "purpose_label",
                "description": "인증 목적 라벨"
            },
            {
                "key": "app_name",
                "description": "사이트명"
            },
            {
                "key": "site_url",
                "description": "사이트 URL"
            },
            {
                "key": "recipient_email",
                "description": "수신자 이메일"
            }
        ],
        "extension_type": "module",
        "extension_identifier": "sirsoft-ecommerce",
        "is_active": true,
        "is_default": true,
        "user_overrides": null,
        "templates": [
            {
                "id": 1,
                "definition_id": 1,
                "channel": "mail",
                "subject": {
                    "ko": "[{app_name}] 결제 본인 확인 인증 코드",
                    "en": "[{app_name}] Checkout Verification Code"
                },
                "body": {
                    "ko": "<h1>결제 본인 확인</h1><p>결제를 진행하기 위해 본인 확인이 필요합니다. 아래 인증 코드를 입력해 주세요.</p><p style=\"font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;\">{code}</p><p>이 코드는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p><p><strong>본인이 결제를 진행하지 않았다면 이 메일을 무시하고 즉시 비밀번호를 변경해 주세요.</strong></p><p>감사합니다,<br><a href=\"{site_url}\">{app_name}</a></p>",
                    "en": "<h1>Checkout Verification</h1><p>Please enter the code below to proceed with payment.</p><p style=\"font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;\">{code}</p><p>This code will expire in <strong>{expire_minutes} minutes</strong>.</p><p><strong>If you did not initiate this payment, please ignore this email and change your password immediately.</strong></p><p>Thank you,<br><a href=\"{site_url}\">{app_name}</a></p>"
                },
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
            "can_delete": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.admin.identity.messages.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 단일 메시지 정의의 상세 정보를 조회합니다(하위 채널 템플릿 eager load 포함). `auth:sanctum` + `core.admin.identity.messages.read` 관리자 권한이 필요합니다. 관리자 편집 모달을 열 때 해당 정의의 다국어 이름/설명·활성 채널·사용 가능 변수·`user_overrides`·`templates` 전체를 로드하기 위해 사용합니다.


### PATCH /api/admin/identity/messages/definitions/{definition}
<!-- @generated:start:api.admin.identity.messages.definitions.update -->
- **라우트명**: `api.admin.identity.messages.definitions.update`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Identity\AdminIdentityMessageDefinitionController@update`
- **인증/권한**: `auth:sanctum` + `permission:core.admin.identity.messages.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| definition | path | string | 예 | — | 대상 definition의 식별자 |
| name | body | array | 아니오 | — | 대상의 이름/명칭 |
| description | body | array | 아니오 | — | 설명 |
| channels | body | array | 아니오 | min 1 | 지원 채널 목록 (최소 1개 — 이 정의가 발송할 채널 조정) |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.identity.message_definition.filter_update_rules`).

**요청 예시**

```http
PATCH /api/admin/identity/messages/definitions/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": [
        "예시 이름"
    ],
    "description": [
        "예시 내용입니다."
    ],
    "channels": [
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
| 403 | Forbidden | 요구 권한(`core.admin.identity.messages.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 메시지 정의의 편집 가능 속성(`name`, `description`, `channels`, `is_active`) 을 수정합니다. `auth:sanctum` + `core.admin.identity.messages.update` 관리자 권한이 필요합니다. `IdentityMessageDefinitionService::updateDefinition` 이 처리하며 수정된 필드는 `user_overrides` 에 기록되어 시더 재실행 시에도 보존됩니다. 확장은 `core.identity.message_definition.filter_update_rules` 필터 훅으로 검증 규칙을 확장할 수 있습니다. 시드 정의의 표시명이나 활성 채널을 운영 상황에 맞게 조정할 때 사용합니다.


### POST /api/admin/identity/messages/definitions/{definition}/reset
<!-- @generated:start:api.admin.identity.messages.definitions.reset -->
- **라우트명**: `api.admin.identity.messages.definitions.reset`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Identity\AdminIdentityMessageDefinitionController@reset`
- **인증/권한**: `auth:sanctum` + `permission:core.admin.identity.messages.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| definition | path | string | 예 | — | 대상 definition의 식별자 |

**요청 예시**

```http
POST /api/admin/identity/messages/definitions/1/reset HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| provider_id | string | `g7:core.mail` | provider 식별자 (연관 리소스 참조) |
| scope_type | string | `purpose` | 메시지 정의 스코프 (provider_default\|purpose\|policy) — App\Enums\IdentityMessageScopeType enum |
| scope_value | string | `checkout_verification` | 범위 값: provider_default 빈 문자열 / purpose 키 / policy 키 |
| name | object | `{"ko":"결제 시 본인 확인","en":"Checkout Verification"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | object | `{"ko":"결제 진행 전 본인\/성인 확인 인증 코드 메일","en":"Identity\/adult …` | 설명 (다국어 필드는 로케일별 값 객체) |
| channels | array | `["mail"]` | 활성 채널 (현재 ["mail"], 향후 sms 등 확장) |
| variables | array | `[{"key":"code","description":"인증 코드 (text_code 흐름)"},{"ke…` | 사용 가능 변수 메타데이터 ([{key, description}]) |
| extension_type | string | `module` | 이 리소스를 소유한 확장의 타입 (core/module/plugin/template) |
| extension_identifier | string | `sirsoft-ecommerce` | 이 리소스를 소유한 확장의 식별자 |
| is_active | boolean | `true` | active 여부 |
| is_default | boolean | `true` | default 여부 |
| user_overrides | null | `null` | 운영자가 수정한 필드명 목록 (예: ["name","is_active"]) |
| templates | array | `[{"id":1,"definition_id":1,"channel":"mail","subject":{"k…` | 템플릿 목록 (각 원소 identifier/name 등 — 템플릿 관계 파생) |
| created_at | string | `2026-07-08 10:43:32` | 생성 일시 |
| updated_at | string | `2026-07-08 10:43:32` | 최종 수정 일시 |
| abilities | object | `{"can_update":true,"can_delete":false}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "IDV 메시지 정의가 기본값으로 복원되었습니다.",
    "data": {
        "id": 1,
        "provider_id": "g7:core.mail",
        "scope_type": "purpose",
        "scope_value": "checkout_verification",
        "name": {
            "ko": "결제 시 본인 확인",
            "en": "Checkout Verification"
        },
        "description": {
            "ko": "결제 진행 전 본인/성인 확인 인증 코드 메일",
            "en": "Identity/adult verification code mail before checkout"
        },
        "channels": [
            "mail"
        ],
        "variables": [
            {
                "key": "code",
                "description": "인증 코드 (text_code 흐름)"
            },
            {
                "key": "expire_minutes",
                "description": "만료까지 남은 분"
            },
            {
                "key": "purpose_label",
                "description": "인증 목적 라벨"
            },
            {
                "key": "app_name",
                "description": "사이트명"
            },
            {
                "key": "site_url",
                "description": "사이트 URL"
            },
            {
                "key": "recipient_email",
                "description": "수신자 이메일"
            }
        ],
        "extension_type": "module",
        "extension_identifier": "sirsoft-ecommerce",
        "is_active": true,
        "is_default": true,
        "user_overrides": null,
        "templates": [
            {
                "id": 1,
                "definition_id": 1,
                "channel": "mail",
                "subject": {
                    "ko": "[{app_name}] 결제 본인 확인 인증 코드",
                    "en": "[{app_name}] Checkout Verification Code"
                },
                "body": {
                    "ko": "<h1>결제 본인 확인</h1><p>결제를 진행하기 위해 본인 확인이 필요합니다. 아래 인증 코드를 입력해 주세요.</p><p style=\"font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;\">{code}</p><p>이 코드는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p><p><strong>본인이 결제를 진행하지 않았다면 이 메일을 무시하고 즉시 비밀번호를 변경해 주세요.</strong></p><p>감사합니다,<br><a href=\"{site_url}\">{app_name}</a></p>",
                    "en": "<h1>Checkout Verification</h1><p>Please enter the code below to proceed with payment.</p><p style=\"font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;\">{code}</p><p>This code will expire in <strong>{expire_minutes} minutes</strong>.</p><p><strong>If you did not initiate this payment, please ignore this email and change your password immediately.</strong></p><p>Thank you,<br><a href=\"{site_url}\">{app_name}</a></p>"
                },
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
            "can_delete": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.admin.identity.messages.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 메시지 정의에 속한 모든 채널 템플릿을 시더 기본값으로 일괄 복원하고 정의를 default 상태로 되돌립니다. `auth:sanctum` + `core.admin.identity.messages.update` 관리자 권한이 필요합니다. 각 하위 템플릿에 대해 `IdentityMessageTemplateService::resetToDefault` 를 호출한 뒤 `markAsDefault` 로 정의를 표시하므로, 운영자가 수정한 문구를 한 번에 원상복구할 때 사용합니다.


### PATCH /api/admin/identity/messages/definitions/{definition}/toggle-active
<!-- @generated:start:api.admin.identity.messages.definitions.toggle-active -->
- **라우트명**: `api.admin.identity.messages.definitions.toggle-active`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Identity\AdminIdentityMessageDefinitionController@toggleActive`
- **인증/권한**: `auth:sanctum` + `permission:core.admin.identity.messages.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| definition | path | string | 예 | — | 대상 definition의 식별자 |

**요청 예시**

```http
PATCH /api/admin/identity/messages/definitions/1/toggle-active HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| provider_id | string | `g7:core.mail` | provider 식별자 (연관 리소스 참조) |
| scope_type | string | `purpose` | 메시지 정의 스코프 (provider_default\|purpose\|policy) — App\Enums\IdentityMessageScopeType enum |
| scope_value | string | `checkout_verification` | 범위 값: provider_default 빈 문자열 / purpose 키 / policy 키 |
| name | object | `{"ko":"결제 시 본인 확인","en":"Checkout Verification"}` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | object | `{"ko":"결제 진행 전 본인\/성인 확인 인증 코드 메일","en":"Identity\/adult …` | 설명 (다국어 필드는 로케일별 값 객체) |
| channels | array | `["mail"]` | 활성 채널 (현재 ["mail"], 향후 sms 등 확장) |
| variables | array | `[{"key":"code","description":"인증 코드 (text_code 흐름)"},{"ke…` | 사용 가능 변수 메타데이터 ([{key, description}]) |
| extension_type | string | `module` | 이 리소스를 소유한 확장의 타입 (core/module/plugin/template) |
| extension_identifier | string | `sirsoft-ecommerce` | 이 리소스를 소유한 확장의 식별자 |
| is_active | boolean | `false` | active 여부 |
| is_default | boolean | `true` | default 여부 |
| user_overrides | array | `["is_active"]` | 운영자가 수정한 필드명 목록 (예: ["name","is_active"]) |
| templates | null | `null` | 템플릿 목록 (각 원소 identifier/name 등 — 템플릿 관계 파생) |
| created_at | string | `2026-07-08 10:43:32` | 생성 일시 |
| updated_at | string | `2026-07-08 12:14:36` | 최종 수정 일시 |
| abilities | object | `{"can_update":true,"can_delete":false}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "IDV 메시지 정의 활성 상태가 변경되었습니다.",
    "data": {
        "id": 1,
        "provider_id": "g7:core.mail",
        "scope_type": "purpose",
        "scope_value": "checkout_verification",
        "name": {
            "ko": "결제 시 본인 확인",
            "en": "Checkout Verification"
        },
        "description": {
            "ko": "결제 진행 전 본인/성인 확인 인증 코드 메일",
            "en": "Identity/adult verification code mail before checkout"
        },
        "channels": [
            "mail"
        ],
        "variables": [
            {
                "key": "code",
                "description": "인증 코드 (text_code 흐름)"
            },
            {
                "key": "expire_minutes",
                "description": "만료까지 남은 분"
            },
            {
                "key": "purpose_label",
                "description": "인증 목적 라벨"
            },
            {
                "key": "app_name",
                "description": "사이트명"
            },
            {
                "key": "site_url",
                "description": "사이트 URL"
            },
            {
                "key": "recipient_email",
                "description": "수신자 이메일"
            }
        ],
        "extension_type": "module",
        "extension_identifier": "sirsoft-ecommerce",
        "is_active": false,
        "is_default": true,
        "user_overrides": [
            "is_active"
        ],
        "templates": null,
        "created_at": "2026-07-08 10:43:32",
        "updated_at": "2026-07-08 12:14:36",
        "abilities": {
            "can_update": true,
            "can_delete": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.admin.identity.messages.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 메시지 정의의 활성/비활성 상태를 토글합니다. `auth:sanctum` + `core.admin.identity.messages.update` 관리자 권한이 필요합니다. `IdentityMessageDefinitionService::toggleActive` 가 현재 `is_active` 값을 반전시키며, 특정 목적/정책용 인증 메시지 발송을 임시로 끄거나 다시 켤 때 사용합니다.


### POST /api/admin/identity/messages/templates/preview
<!-- @generated:start:api.admin.identity.messages.templates.preview -->
- **라우트명**: `api.admin.identity.messages.templates.preview`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Identity\AdminIdentityMessageTemplateController@preview`
- **인증/권한**: `auth:sanctum` + `permission:core.admin.identity.messages.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| template_id | body | integer | 예 | — | template 식별자 |
| data | body | array | 아니오 | — | 데이터 페이로드 |
| locale | body | string | 아니오 | max 10 | 로케일 코드 (표시 언어/지역) |

**요청 예시**

```http
POST /api/admin/identity/messages/templates/preview HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "template_id": 1,
    "data": [
        "예시값"
    ],
    "locale": "ko"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.admin.identity.messages.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 지정한 템플릿(`template_id`) 의 제목/본문에 변수(`data`) 를 치환한 결과를 지정 로케일(`locale`) 로 렌더링해 미리보기를 반환합니다. `auth:sanctum` + `core.admin.identity.messages.read` 관리자 권한이 필요합니다. `IdentityMessageTemplateService::getPreview` 가 실제 발송 없이 렌더 결과만 생성하므로, 운영자가 편집한 문구가 실제 메일/SMS 에서 어떻게 보일지 저장 전에 확인할 때 사용합니다.


### PATCH /api/admin/identity/messages/templates/{template}
<!-- @generated:start:api.admin.identity.messages.templates.update -->
- **라우트명**: `api.admin.identity.messages.templates.update`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Identity\AdminIdentityMessageTemplateController@update`
- **인증/권한**: `auth:sanctum` + `permission:core.admin.identity.messages.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| template | path | string | 예 | — | 대상 template의 식별자 |
| subject | body | array | 아니오 | — | 제목 |
| body | body | array | 예 | — | 본문 |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.identity.message_template.filter_update_rules`).

**요청 예시**

```http
PATCH /api/admin/identity/messages/templates/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "subject": [
        "예시값"
    ],
    "body": [
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
| 403 | Forbidden | 요구 권한(`core.admin.identity.messages.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 개별 채널 메시지 템플릿의 제목(`subject`)·본문(`body`)·활성 여부(`is_active`) 를 수정합니다. `auth:sanctum` + `core.admin.identity.messages.update` 관리자 권한이 필요합니다. `body` 는 필수이며 다국어 배열로 전달합니다. `IdentityMessageTemplateService::updateTemplate` 이 처리하고 확장은 `core.identity.message_template.filter_update_rules` 필터 훅으로 검증을 확장할 수 있습니다. 인증 메일/SMS 의 실제 발송 문구를 편집할 때 사용합니다.


### POST /api/admin/identity/messages/templates/{template}/reset
<!-- @generated:start:api.admin.identity.messages.templates.reset -->
- **라우트명**: `api.admin.identity.messages.templates.reset`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Identity\AdminIdentityMessageTemplateController@reset`
- **인증/권한**: `auth:sanctum` + `permission:core.admin.identity.messages.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| template | path | string | 예 | — | 대상 template의 식별자 |

**요청 예시**

```http
POST /api/admin/identity/messages/templates/1/reset HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| definition_id | integer | `1` | definition 식별자 (연관 리소스 참조) |
| channel | string | `mail` | 메시지 템플릿 채널 (mail 현재 / sms 등 향후) — IdentityVerificationChannel 과는 별개의 도메인 분류 |
| subject | object | `{"ko":"[{app_name}] 결제 본인 확인 인증 코드","en":"[{app_name}] Ch…` | 다국어 제목 ({"ko":"...", "en":"..."}) — mail 채널에서만 의미 |
| body | object | `{"ko":"<h1>결제 본인 확인<\/h1><p>결제를 진행하기 위해 본인 확인이 필요합니다. 아래 …` | 다국어 본문 ({"ko":"...", "en":"..."}) |
| is_active | boolean | `true` | active 여부 |
| is_default | boolean | `true` | default 여부 |
| user_overrides | null | `null` | 운영자가 수정한 필드명 목록 (예: ["subject","body","is_active"]) |
| updated_by | null | `null` | 최종 수정한 사용자 정보 (uuid/name — updated_by 관계 파생, 없으면 null) |
| created_at | string | `2026-07-08 10:43:32` | 생성 일시 |
| updated_at | string | `2026-07-08 10:43:32` | 최종 수정 일시 |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "IDV 메시지 템플릿이 기본값으로 복원되었습니다.",
    "data": {
        "id": 1,
        "definition_id": 1,
        "channel": "mail",
        "subject": {
            "ko": "[{app_name}] 결제 본인 확인 인증 코드",
            "en": "[{app_name}] Checkout Verification Code"
        },
        "body": {
            "ko": "<h1>결제 본인 확인</h1><p>결제를 진행하기 위해 본인 확인이 필요합니다. 아래 인증 코드를 입력해 주세요.</p><p style=\"font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;\">{code}</p><p>이 코드는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p><p><strong>본인이 결제를 진행하지 않았다면 이 메일을 무시하고 즉시 비밀번호를 변경해 주세요.</strong></p><p>감사합니다,<br><a href=\"{site_url}\">{app_name}</a></p>",
            "en": "<h1>Checkout Verification</h1><p>Please enter the code below to proceed with payment.</p><p style=\"font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;\">{code}</p><p>This code will expire in <strong>{expire_minutes} minutes</strong>.</p><p><strong>If you did not initiate this payment, please ignore this email and change your password immediately.</strong></p><p>Thank you,<br><a href=\"{site_url}\">{app_name}</a></p>"
        },
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
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.admin.identity.messages.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 개별 메시지 템플릿을 시더 기본값으로 복원합니다. `auth:sanctum` + `core.admin.identity.messages.update` 관리자 권한이 필요합니다. `IdentityMessageTemplateService::resetToDefault` 가 운영자 수정 내용을 폐기하고 최초 제공 문구로 되돌리므로, 특정 채널 템플릿 하나만 원상복구할 때 사용합니다(정의 전체 복원은 definition reset 사용).


### PATCH /api/admin/identity/messages/templates/{template}/toggle-active
<!-- @generated:start:api.admin.identity.messages.templates.toggle-active -->
- **라우트명**: `api.admin.identity.messages.templates.toggle-active`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Identity\AdminIdentityMessageTemplateController@toggleActive`
- **인증/권한**: `auth:sanctum` + `permission:core.admin.identity.messages.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| template | path | string | 예 | — | 대상 template의 식별자 |

**요청 예시**

```http
PATCH /api/admin/identity/messages/templates/1/toggle-active HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| definition_id | integer | `1` | definition 식별자 (연관 리소스 참조) |
| channel | string | `mail` | 메시지 템플릿 채널 (mail 현재 / sms 등 향후) — IdentityVerificationChannel 과는 별개의 도메인 분류 |
| subject | object | `{"ko":"[{app_name}] 결제 본인 확인 인증 코드","en":"[{app_name}] Ch…` | 다국어 제목 ({"ko":"...", "en":"..."}) — mail 채널에서만 의미 |
| body | object | `{"ko":"<h1>결제 본인 확인<\/h1><p>결제를 진행하기 위해 본인 확인이 필요합니다. 아래 …` | 다국어 본문 ({"ko":"...", "en":"..."}) |
| is_active | boolean | `false` | active 여부 |
| is_default | boolean | `true` | default 여부 |
| user_overrides | array | `["is_active"]` | 운영자가 수정한 필드명 목록 (예: ["subject","body","is_active"]) |
| updated_by | null | `null` | 최종 수정한 사용자 정보 (uuid/name — updated_by 관계 파생, 없으면 null) |
| created_at | string | `2026-07-08 10:43:32` | 생성 일시 |
| updated_at | string | `2026-07-08 12:14:36` | 최종 수정 일시 |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "IDV 메시지 템플릿 활성 상태가 변경되었습니다.",
    "data": {
        "id": 1,
        "definition_id": 1,
        "channel": "mail",
        "subject": {
            "ko": "[{app_name}] 결제 본인 확인 인증 코드",
            "en": "[{app_name}] Checkout Verification Code"
        },
        "body": {
            "ko": "<h1>결제 본인 확인</h1><p>결제를 진행하기 위해 본인 확인이 필요합니다. 아래 인증 코드를 입력해 주세요.</p><p style=\"font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;\">{code}</p><p>이 코드는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p><p><strong>본인이 결제를 진행하지 않았다면 이 메일을 무시하고 즉시 비밀번호를 변경해 주세요.</strong></p><p>감사합니다,<br><a href=\"{site_url}\">{app_name}</a></p>",
            "en": "<h1>Checkout Verification</h1><p>Please enter the code below to proceed with payment.</p><p style=\"font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;\">{code}</p><p>This code will expire in <strong>{expire_minutes} minutes</strong>.</p><p><strong>If you did not initiate this payment, please ignore this email and change your password immediately.</strong></p><p>Thank you,<br><a href=\"{site_url}\">{app_name}</a></p>"
        },
        "is_active": false,
        "is_default": true,
        "user_overrides": [
            "is_active"
        ],
        "updated_by": null,
        "created_at": "2026-07-08 10:43:32",
        "updated_at": "2026-07-08 12:14:36",
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
| 403 | Forbidden | 요구 권한(`core.admin.identity.messages.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 개별 메시지 템플릿의 활성/비활성 상태를 토글합니다. `auth:sanctum` + `core.admin.identity.messages.update` 관리자 권한이 필요합니다. `IdentityMessageTemplateService::toggleActive` 가 현재 `is_active` 값을 반전시키며, 특정 채널(예: 메일) 발송만 임시로 중단하거나 재개할 때 사용합니다.


### GET /api/admin/identity/policies
<!-- @generated:start:api.admin.identity.policies.index -->
- **라우트명**: `api.admin.identity.policies.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Identity\AdminIdentityPolicyController@index`
- **인증/권한**: `auth:sanctum` + `permission:core.admin.identity.policies.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| scope | query | string | 아니오 | — | 조회 범위 한정 키 |
| purpose | query | string | 아니오 | max 64 | 인증 목적 필터 (해당 목적을 요구하는 정책만 조회) |
| source_type | query | string | 아니오 | — | 정책 출처 필터 (core/module/plugin/admin — 선언형 vs 운영자 정책 구분, IdentityPolicySourceType) |
| source_identifier | query | string | 아니오 | max 100 | 출처 식별자 |
| applies_to | query | string | 아니오 | — | 적용 대상 사용자 필터 (self: 일반 사용자 / admin: 관리자 / both: 모두, IdentityPolicyAppliesTo) |
| fail_mode | query | string | 아니오 | — | 실패 시 동작 필터 (block: 428 차단 / log_only: 감사 로그만, IdentityPolicyFailMode) |
| enabled | query | boolean | 아니오 | — | 사용 여부 |
| search | query | string | 아니오 | max 255 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |

**요청 예시**

```http
GET /api/admin/identity/policies?scope=%EC%98%88%EC%8B%9C%EA%B0%92&purpose=%EC%98%88%EC%8B%9C%EA%B0%92&source_type=%EC%98%88%EC%8B%9C%EA%B0%92&source_identifier=example-key&applies_to=%EC%98%88%EC%8B%9C%EA%B0%92&fail_mode=%EC%98%88%EC%8B%9C%EA%B0%92&enabled=1&search=%EC%98%88%EC%8B%9C%EA%B0%92&per_page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `25` | 기본 키 (내부 식별자) |
| key | string | `test` | 정책 식별자 (고유, 예: core.profile.password_change) |
| scope | string | `route` | 정책 적용 범위 (route: 라우트 패턴 / hook: Service 훅 / custom: 모듈 커스텀 키, IdentityPolicyScope) |
| target | string | `sdfsfsf` | 매칭 대상 (scope 에 따라 라우트명/URI 패턴, 훅 이름, 또는 custom key) |
| purpose | string | `inicis.adult_verification` | 이 정책이 요구하는 인증 목적 |
| provider_id | string | `inicis` | provider 식별자 (연관 리소스 참조) |
| grace_minutes | integer | `0` | 재인증 유예 시간(분) — 최근 N분 이내 동일 목적 인증 성공 시 재인증 생략 (0=매번 요구) |
| enabled | boolean | `true` | 정책 사용 여부 (false 시 인증 미강제) |
| priority | integer | `100` | 정책 우선순위 (같은 대상에 여러 정책 매칭 시 작을수록 우선) |
| conditions | array | `[]` | 추가 매칭 조건 (역할/HTTP 메서드/파라미터 매칭 조건 JSON, 없으면 빈 배열) |
| source_type | string | `admin` | 정책 출처 (core/module/plugin: 선언형 / admin: 운영자 직접 등록, IdentityPolicySourceType) |
| source_identifier | string | `sirsoft-board` | 출처 식별자 (선언형 정책의 소유 확장 identifier) |
| applies_to | string | `both` | 적용 대상 사용자 (self: 일반 사용자 / admin: 관리자 / both: 모두, IdentityPolicyAppliesTo) |
| fail_mode | string | `block` | 실패 시 동작 (block: HTTP 428 차단 / log_only: 감사 로그만 남기고 통과, IdentityPolicyFailMode) |
| user_overrides | array | `[]` | 운영자가 선언 기본값에서 재정의한 필드 목록 (선언형 정책만 의미, 시더 재실행 시 보존) |
| created_at | string | `2026-06-26 16:33:04` | 생성 일시 |
| updated_at | string | `2026-06-26 16:33:04` | 최종 수정 일시 |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "data": [
            {
                "id": 12,
                "key": "sirsoft-board.post.user_create",
                "scope": "hook",
                "target": "sirsoft-board.post.before_create",
                "purpose": "sensitive_action",
                "provider_id": null,
                "grace_minutes": 0,
                "enabled": false,
                "priority": 100,
                "conditions": null,
                "source_type": "module",
                "source_identifier": "sirsoft-board",
                "applies_to": "self",
                "fail_mode": "block",
                "user_overrides": [],
                "created_at": "2026-07-08 10:44:35",
                "updated_at": "2026-07-08 10:44:35",
                "abilities": {
                    "can_update": true,
                    "can_delete": true
                }
            },
            {
                "id": 11,
                "key": "sirsoft-board.report.create",
                "scope": "hook",
                "target": "sirsoft-board.report.before_create",
                "purpose": "sensitive_action",
                "provider_id": null,
                "grace_minutes": 30,
                "enabled": false,
                "priority": 100,
                "conditions": null,
                "source_type": "module",
                "source_identifier": "sirsoft-board",
                "applies_to": "self",
                "fail_mode": "block",
                "user_overrides": [],
                "created_at": "2026-07-08 10:44:35",
                "updated_at": "2026-07-08 10:44:35",
                "abilities": {
                    "can_update": true,
                    "can_delete": true
                }
            },
            "... (총 12건 중 2건 표시)"
        ],
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        },
        "meta": {
            "current_page": 1,
            "per_page": 25,
            "total": 12,
            "last_page": 1
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.admin.identity.policies.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 본인인증 정책(어느 시점/위치에서 어떤 목적의 인증을 요구할지) 목록을 필터·검색하여 페이지네이션 조회합니다. `auth:sanctum` + `core.admin.identity.policies.read` 관리자 권한이 필요합니다. `IdentityPolicyService::search` 로 scope·purpose·source_type·enabled 등을 필터링하며, 응답의 `source_type` 으로 선언형(core/module/plugin) 정책과 운영자 정책(admin) 을 구분하고 `user_overrides` 로 운영자가 재정의한 필드를 표시합니다. 관리자 IDV 정책 DataGrid 를 구성할 때 사용합니다.


### POST /api/admin/identity/policies
<!-- @generated:start:api.admin.identity.policies.store -->
- **라우트명**: `api.admin.identity.policies.store`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Identity\AdminIdentityPolicyController@store`
- **인증/권한**: `auth:sanctum` + `permission:core.admin.identity.policies.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| key | body | string | 예 | max 120 | 정책 식별자 (고유, 예: core.profile.password_change) |
| scope | body | string | 예 | — | 조회 범위 한정 키 |
| target | body | string | 예 | max 255 | 매칭 대상 (scope 에 따라 라우트명/URI 패턴, 훅 이름, 또는 custom key) |
| purpose | body | string | 예 | max 64 | 이 정책이 요구하는 인증 목적 |
| provider_id | body | string | 아니오 | max 64 | provider 식별자 |
| grace_minutes | body | integer | 예 | min 0, max 43200 | 재인증 유예 시간(분) — 최근 N분 이내 동일 목적 인증 성공 시 재인증 생략 (0=매번 요구) |
| enabled | body | boolean | 아니오 | — | 사용 여부 |
| priority | body | integer | 아니오 | min 0, max 65535 | 우선순위 (작을수록 우선) |
| conditions | body | array | 아니오 | — | 추가 매칭 조건 (역할/HTTP 메서드/파라미터 매칭 조건 JSON) |
| applies_to | body | string | 예 | — | 적용 대상 사용자 (self: 일반 사용자 / admin: 관리자 / both: 모두, IdentityPolicyAppliesTo) |
| fail_mode | body | string | 예 | — | 실패 시 동작 (block: HTTP 428 차단 / log_only: 감사 로그만 남기고 통과, IdentityPolicyFailMode) |
| source_identifier | body | string | 아니오 | max 100 | 출처 식별자 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.identity_policy.store_validation_rules`).

**요청 예시**

```http
POST /api/admin/identity/policies HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "key": "예시값",
    "scope": "예시값",
    "target": "예시값",
    "purpose": "예시값",
    "provider_id": "예시값",
    "grace_minutes": 1,
    "enabled": true,
    "priority": 1,
    "conditions": [
        "예시값"
    ],
    "applies_to": "예시값",
    "fail_mode": "예시값",
    "source_identifier": "example-key"
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
| 403 | Forbidden | 요구 권한(`core.admin.identity.policies.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 운영자가 새 본인인증 정책을 생성합니다(`source_type='admin'` 고정). `auth:sanctum` + `core.admin.identity.policies.update` 관리자 권한이 필요합니다. `IdentityPolicyService::createAdminPolicy` 가 처리하며 `key`·`scope`·`target`·`purpose`·`grace_minutes`·`applies_to`·`fail_mode` 등을 지정합니다. 확장은 `core.identity_policy.store_validation_rules` 필터 훅으로 검증을 확장할 수 있습니다. 특정 라우트/훅 지점에 코어가 선언하지 않은 인증 요구를 관리자가 직접 추가할 때 사용하며 성공 시 201 로 응답합니다.


### DELETE /api/admin/identity/policies/{id}
<!-- @generated:start:api.admin.identity.policies.destroy -->
- **라우트명**: `api.admin.identity.policies.destroy`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Identity\AdminIdentityPolicyController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:core.admin.identity.policies.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.identity_policy.destroy_validation_rules`).

**요청 예시**

```http
DELETE /api/admin/identity/policies/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.admin.identity.policies.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 본인인증 정책을 삭제합니다. `auth:sanctum` + `core.admin.identity.policies.update` 관리자 권한이 필요합니다. `source_type='admin'` 인 운영자 정책만 삭제할 수 있고, 선언형 정책(core/module/plugin) 은 403 을 반환하므로 삭제 대신 비활성화(update 로 `enabled=false`) 로 대체해야 합니다. 확장은 `core.identity_policy.destroy_validation_rules` 필터 훅으로 검증을 확장할 수 있습니다. 운영자가 잘못 만든 정책을 제거할 때 사용합니다.


### PUT /api/admin/identity/policies/{id}
<!-- @generated:start:api.admin.identity.policies.update -->
- **라우트명**: `api.admin.identity.policies.update`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Identity\AdminIdentityPolicyController@update`
- **인증/권한**: `auth:sanctum` + `permission:core.admin.identity.policies.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| enabled | body | boolean | 아니오 | — | 사용 여부 |
| grace_minutes | body | integer | 아니오 | min 0, max 43200 | 재인증 유예 시간(분) — 최근 N분 이내 동일 목적 인증 성공 시 재인증 생략 (0=매번 요구) |
| provider_id | body | string | 아니오 | max 64 | provider 식별자 |
| fail_mode | body | string | 아니오 | — | 실패 시 동작 (block: HTTP 428 차단 / log_only: 감사 로그만 남기고 통과, IdentityPolicyFailMode) |
| key | body | string | 아니오 | max 120 | 정책 식별자 (admin 정책만 변경 가능, 선언형 정책은 확장 지점 식별자라 변경 차단) |
| scope | body | string | 아니오 | — | 조회 범위 한정 키 |
| target | body | string | 아니오 | max 255 | 매칭 대상 (admin 정책만 변경 가능, 선언형 정책은 변경 차단) |
| purpose | body | string | 아니오 | max 64 | 이 정책이 요구하는 인증 목적 |
| priority | body | integer | 아니오 | min 0, max 65535 | 우선순위 (작을수록 우선) |
| conditions | body | array | 아니오 | — | 추가 매칭 조건 (역할/HTTP 메서드/파라미터 매칭 조건 JSON) |
| applies_to | body | string | 아니오 | — | 적용 대상 사용자 (self: 일반 사용자 / admin: 관리자 / both: 모두, IdentityPolicyAppliesTo) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.identity_policy.update_validation_rules`).

**요청 예시**

```http
PUT /api/admin/identity/policies/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "enabled": true,
    "grace_minutes": 1,
    "provider_id": "예시값",
    "fail_mode": "예시값",
    "key": "예시값",
    "scope": "예시값",
    "target": "예시값",
    "purpose": "예시값",
    "priority": 1,
    "conditions": [
        "예시값"
    ],
    "applies_to": "예시값"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.admin.identity.policies.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 본인인증 정책을 수정합니다. `auth:sanctum` + `core.admin.identity.policies.update` 관리자 권한이 필요합니다. `source_type='admin'` 정책은 모든 필드를 편집할 수 있으나, 선언형 정책(core/module/plugin) 은 `enabled`·`grace_minutes`·`provider_id`·`fail_mode`·`conditions`·`purpose`·`applies_to`·`priority` 화이트리스트("어떻게 인증할지") 만 허용되고 `key`/`scope`/`target`("어디서 인증할지") 은 확장 지점 식별자라 변경이 차단됩니다. 편집한 필드는 `user_overrides` 에 append 되어 시더 재실행 시 보존됩니다. 허용 필드가 하나도 없으면 422(nothing_to_update) 를 반환합니다. 확장은 `core.identity_policy.update_validation_rules` 필터 훅으로 검증을 확장할 수 있습니다.


### POST /api/admin/identity/policies/{id}/reset-field
<!-- @generated:start:api.admin.identity.policies.reset-field -->
- **라우트명**: `api.admin.identity.policies.reset-field`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Identity\AdminIdentityPolicyController@resetField`
- **인증/권한**: `auth:sanctum` + `permission:core.admin.identity.policies.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| field | body | string | 예 | `enabled`, `grace_minutes`, `provider_id`, `fail_mode`, `conditions`, `purpose`, `applies_to`, `priority` | 기본값으로 되돌릴 대상 필드명 (해당 필드의 운영자 재정의 해제 후 선언 기본값 복원) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.identity_policy.reset_field_validation_rules`).

**요청 예시**

```http
POST /api/admin/identity/policies/{id}/reset-field HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "field": "enabled"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.admin.identity.policies.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 선언형 정책의 특정 필드에 대한 운영자 재정의(`user_overrides`) 를 해제하고 선언 기본값으로 즉시 복원합니다. `auth:sanctum` + `core.admin.identity.policies.update` 관리자 권한이 필요합니다. `field` 는 재정의 가능 필드(`enabled`, `grace_minutes`, `provider_id`, `fail_mode`, `conditions`, `purpose`, `applies_to`, `priority`) 중 하나여야 합니다. `source_type='admin'` 정책은 선언 기본값이 없어 403 을 반환하며 선언형 정책(core/module/plugin) 에만 의미가 있습니다. 관리자 편집 화면의 "↺ 기본값으로 되돌리기" 버튼이 호출하는 엔드포인트입니다.


### GET /api/admin/identity/providers
<!-- @generated:start:api.admin.identity.providers.index -->
- **라우트명**: `api.admin.identity.providers.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Identity\AdminIdentityProviderController@index`
- **인증/권한**: `auth:sanctum` + `permission:core.admin.identity.providers.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/identity/providers HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | string | `g7:core.mail` | 기본 키 (내부 식별자) |
| label | string | `이메일` | 표시용 라벨 |
| channels | array | `["email"]` | 이 프로바이더가 지원하는 전송 채널 식별자 목록 |
| channel_labels | object | `{"email":"이메일"}` | 채널 식별자 → 사람이 읽는 표시 라벨 맵 (다국어 처리, UI 표시용) |
| render_hint | string | `text_code` | 프론트 challenge 렌더 방식 힌트 (text_code: 코드 입력 UI / link: 링크 클릭 유도 / external_redirect: 외부 인증 페이지 이동) |
| is_available | boolean | `true` | available 여부 |
| abilities | object | `{"can_update":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |
| settings_schema | object | `{"code_length":{"label":"인증 코드 길이","type":"integer","defa…` | 관리자 설정 UI 반복 렌더용 설정 스키마 (필드별 label/type/default/options/help — 코드 길이·만료 시간 등) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": [
        {
            "id": "g7:core.mail",
            "label": "이메일",
            "channels": [
                "email"
            ],
            "channel_labels": {
                "email": "이메일"
            },
            "render_hint": "text_code",
            "is_available": true,
            "abilities": {
                "can_update": true
            },
            "settings_schema": {
                "code_length": {
                    "label": "인증 코드 길이",
                    "type": "integer",
                    "default": 6,
                    "help": "발송되는 숫자 코드의 자릿수 (기본 6, 최소 4, 최대 10)."
                },
                "from_address": {
                    "label": "발신자 주소",
                    "type": "string",
                    "default": null,
                    "help": "비어 있으면 시스템 기본 발신자를 사용합니다."
                }
            }
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.admin.identity.providers.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** 등록된 IDV 프로바이더 목록을 각 프로바이더의 설정 스키마(`settings_schema`) 와 함께 반환합니다. `auth:sanctum` + `core.admin.identity.providers.read` 관리자 권한이 필요합니다. 각 프로바이더의 `getSettingsSchema()` 결과를 `core.identity.settings_schema` 필터 훅으로 확장 가능하게 통과시키므로, 관리자 프로바이더 설정 카드(코드 길이·만료 시간 등)를 스키마 기반으로 반복 렌더링할 때 사용합니다. 설정 스키마가 없는 공개용 목록은 `GET /api/identity/providers` 를 사용합니다.


### POST /api/identity/callback/{providerId}
<!-- @generated:start:api.identity.callback -->
- **라우트명**: `api.identity.callback`
- **컨트롤러**: `\App\Http\Controllers\Api\Identity\IdentityVerificationController@callback`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| providerId | path | string | 예 | — | 대상 provider의 식별자 |
| challenge_id | body | string | 예 | max 64 | challenge 식별자 |
| code | body | string | 아니오 | max 512 | 외부 프로바이더가 콜백으로 전달한 인가 코드/인증 코드 |
| token | body | string | 아니오 | max 1024 | 인증/검증 토큰 |
| state | body | string | 아니오 | max 512 | 콜백 위변조 방지용 state 값 (요청 시 발급한 값과 대조) |
| redirect_url | body | string | 아니오 | max 2048 | 인증 완료 후 되돌아갈 URL (open redirect 방지 위해 same-origin 만 허용) |

**요청 예시**

```http
POST /api/identity/callback/{providerId} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
Content-Type: application/json

{
    "challenge_id": "예시값",
    "code": "예시값",
    "token": "{YOUR_TOKEN}",
    "state": "예시값",
    "redirect_url": "https://example.com"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 외부 IDV 프로바이더(외부 인증 SDK/OAuth-style provider) 가 사용자 브라우저를 앱으로 되돌려 보내는 redirect 콜백 진입점입니다. `auth:sanctum` 인증이 필요합니다. body/query 의 `challenge_id` 를 추출해 `IdentityVerificationService::handleProviderCallback` 에 위임하며, 클라이언트가 stash 한 `return` 쿼리 유무와 성공 여부에 따라 응답이 갈립니다 — 성공+return: 302 로 `{return}?verification_token=...&challenge_id=...`, 성공+return 없음: 200 JSON `{ verification_token }`, 실패+return: 302 로 `{return}?identity_error={failure_code}`, 실패+return 없음: 422 JSON. `return` URL 은 open redirect 방지를 위해 same-origin(또는 `/` 상대경로) 만 허용하고 protocol-relative(`//`) 는 차단합니다.


### POST /api/identity/challenges
<!-- @generated:start:api.identity.challenges.request -->
- **라우트명**: `api.identity.challenges.request`
- **컨트롤러**: `\App\Http\Controllers\Api\Identity\IdentityVerificationController@request`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.identity.request`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| purpose | body | string | 예 | max 64 | 인증 목적 (signup/password_reset/self_update/sensitive_action 또는 모듈 정의 목적) |
| target | body | array | 아니오 | — | 비로그인 게스트의 인증 대상 (target.email 또는 target.phone — 로그인 사용자는 본인으로 자동 설정) |
| provider_id | body | string | 아니오 | max 64 | provider 식별자 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.identity.request_validation_rules`).

**요청 예시**

```http
POST /api/identity/challenges HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
Content-Type: application/json

{
    "purpose": "예시값",
    "target": [
        "예시값"
    ],
    "provider_id": "예시값"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-429 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`core.identity.request`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 본인인증 challenge(인증 시도) 를 시작합니다. `auth:sanctum` + `core.identity.request` 권한이 필요합니다. 로그인 사용자는 인증 대상이 자동으로 본인이 되며, 비로그인 게스트(Mode B 가입 흐름) 는 `target.email` 또는 `target.phone` 을 반드시 제공해야 하고 없으면 422(missing_target) 를 반환합니다. `provider_id` 미지정 시 목적에 매핑된 기본 프로바이더가 선택됩니다. `IdentityVerificationService::start` 가 IP·User-Agent 등 컨텍스트와 함께 challenge 를 생성하고 성공 시 201 로 challenge 리소스(render_hint 등 포함) 를 반환합니다. 확장은 `core.identity.request_validation_rules` 필터 훅으로 파라미터를 추가할 수 있습니다.


### GET /api/identity/challenges/{challenge}
<!-- @generated:start:api.identity.challenges.show -->
- **라우트명**: `api.identity.challenges.show`
- **컨트롤러**: `\App\Http\Controllers\Api\Identity\IdentityVerificationController@show`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| challenge | path | string | 예 | — | 대상 challenge의 식별자 |

**요청 예시**

```http
GET /api/identity/challenges/{challenge} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | string | `00484973-8cd3-4a1d-85f2-78361feb6f0d` | 기본 키 (내부 식별자) |
| status | string | `verified` | requested\|sent\|processing\|verified\|failed\|expired\|cancelled\|policy_violation_logged |
| provider_id | string | `inicis` | 프로바이더 식별자 (예: g7:core.mail, kcp) |
| purpose | string | `sensitive_action` | 인증 목적 (signup\|password_reset\|self_update\|sensitive_action\|*module-defined*) — 코어 4종은 App\Enums\IdentityVerificationPurpose enum, 모듈/플러그인은 declaredPurposes 레지스트리 |
| render_hint | string | `text_code` | 프론트 렌더 힌트 (text_code\|link\|external_redirect) |
| expires_at | string | `2026-05-12T18:14:19+00:00` | expires 일시 |
| attempts | integer | `3` | 시도 횟수 |
| max_attempts | integer | `5` | 허용 최대 시도 횟수 |
| public_payload | array | `[]` | 프론트 렌더에 필요한 공개 안전 페이로드 (민감 metadata 제외, 프로바이더별 UI 힌트 — 없으면 빈 배열) |

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** challenge 의 공개 상태를 폴링 조회합니다(engine-v1.46.0+). `auth:sanctum` 인증이 필요합니다. Stripe Identity/토스인증 push/외부 redirect 콜백 대기처럼 verify 즉시 응답을 받지 못하는 비동기 검증 흐름에서 클라이언트가 상태(verified/failed/expired 등) 를 추적하기 위한 엔드포인트입니다. `IdentityVerificationService::getStatus` 가 공개 안전 항목만 노출하며(시도 횟수 상세·코드 본체·metadata 미노출), challenge 를 찾지 못하면 404 를 반환합니다.


### POST /api/identity/challenges/{challenge}/cancel
<!-- @generated:start:api.identity.challenges.cancel -->
- **라우트명**: `api.identity.challenges.cancel`
- **컨트롤러**: `\App\Http\Controllers\Api\Identity\IdentityVerificationController@cancel`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.identity.cancel`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| challenge | path | string | 예 | — | 대상 challenge의 식별자 |

**요청 예시**

```http
POST /api/identity/challenges/{challenge}/cancel HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`core.identity.cancel`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 진행 중인 challenge 를 취소합니다. `auth:sanctum` + `core.identity.cancel` 권한이 필요합니다. 라우트 모델 바인딩 + PermissionMiddleware 의 scope=self 가드로 로그인 사용자는 본인 challenge 만 취소할 수 있으며, 비로그인 게스트는 guest 역할 권한으로 진입합니다(모달 취소 시 audit trail 정합용). `IdentityVerificationService::cancel` 이 처리하고 대상 challenge 가 없으면 404 를 반환합니다. 사용자가 인증 모달을 닫을 때 서버 상태를 cancelled 로 남겨 이력 정합성을 맞추는 데 사용합니다.


### POST /api/identity/challenges/{challenge}/verify
<!-- @generated:start:api.identity.challenges.verify -->
- **라우트명**: `api.identity.challenges.verify`
- **컨트롤러**: `\App\Http\Controllers\Api\Identity\IdentityVerificationController@verify`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:core.identity.verify`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| challenge | path | string | 예 | — | 대상 challenge의 식별자 |
| code | body | string | 아니오 | max 16 | 사용자가 입력한 인증 코드 (text_code 흐름 — 메일/SMS 로 받은 숫자 코드) |
| token | body | string | 아니오 | max 256 | 인증/검증 토큰 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.identity.verify_validation_rules`).

**요청 예시**

```http
POST /api/identity/challenges/{challenge}/verify HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
Content-Type: application/json

{
    "code": "예시값",
    "token": "{YOUR_TOKEN}"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`core.identity.verify`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** challenge 를 검증(인증 완료) 합니다. `auth:sanctum` + `core.identity.verify` 권한이 필요합니다. 라우트 모델 바인딩 + PermissionMiddleware 의 scope=self 가드로 로그인 사용자는 본인 challenge 만 검증하며, 비로그인 게스트는 guest 역할 권한으로 진입합니다(Mode B 가입 흐름). `code`(text_code 흐름) 또는 `token`(link/redirect 흐름) 을 전달하고 `IdentityVerificationService::verify` 가 처리합니다. 실패 시 422 로 `failure_code` 와 서버 기준 `attempts`/`max_attempts` 를 함께 내려 클라이언트의 "남은 시도 횟수" UI 를 서버와 동기화하며, 성공 시 후속 민감 작업에 제출할 `verification_token` 을 반환합니다. 확장은 `core.identity.verify_validation_rules` 필터 훅으로 파라미터를 추가할 수 있습니다.


### GET /api/identity/policies/resolve
<!-- @generated:start:api.identity.policies.resolve -->
- **라우트명**: `api.identity.policies.resolve`
- **컨트롤러**: `App\Http\Controllers\Api\Identity\IdentityVerificationController@resolvePolicy`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| scope | query | string | 예 | max 32 | 조회 범위 한정 키 |
| target | query | string | 예 | max 255 | 정책 매칭 대상 (scope 와 함께 해석 — 라우트명/URI 패턴, 훅 이름, 또는 custom key) |

**요청 예시**

```http
GET /api/identity/policies/resolve?scope=%EC%98%88%EC%8B%9C%EA%B0%92&target=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: http-422 — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 지정한 `scope`+`target` 조합에 매칭되는 본인인증 정책 요약을 반환합니다(프론트엔드 프리페치용). `auth:sanctum` 인증이 필요합니다. `IdentityPolicyService::resolve` 로 정책을 찾아 활성(`enabled`) 정책이 없으면 `data: null` 을, 있으면 UI 힌트에 필요한 최소 필드(`policy_key`, `scope`, `target`, `purpose`, `provider_id`, `grace_minutes`, `applies_to`, `fail_mode`) 만 반환하고 민감 필드는 노출하지 않습니다. 레이아웃 마운트 시 "이 페이지에서 IDV 가 요구될 수 있는 API" 를 미리 파악해 버튼 배지("확인 필요") 같은 UI 힌트를 표시할 때 사용합니다.


### GET /api/identity/providers
<!-- @generated:start:api.identity.providers.index -->
- **라우트명**: `api.identity.providers.index`
- **컨트롤러**: `App\Http\Controllers\Api\Identity\IdentityVerificationController@providers`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/identity/providers HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | string | `g7:core.mail` | 기본 키 (내부 식별자) |
| label | string | `이메일` | 표시용 라벨 |
| channels | array | `["email"]` | 이 프로바이더가 지원하는 전송 채널 식별자 목록 |
| channel_labels | object | `{"email":"이메일"}` | 채널 식별자 → 사람이 읽는 표시 라벨 맵 (다국어 처리, UI 표시용) |
| render_hint | string | `text_code` | 프론트 challenge 렌더 방식 힌트 (text_code: 코드 입력 UI / link: 링크 클릭 유도 / external_redirect: 외부 인증 페이지 이동) |
| is_available | boolean | `true` | available 여부 |
| abilities | object | `{"can_update":false}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": [
        {
            "id": "g7:core.mail",
            "label": "이메일",
            "channels": [
                "email"
            ],
            "channel_labels": {
                "email": "이메일"
            },
            "render_hint": "text_code",
            "is_available": true,
            "abilities": {
                "can_update": false
            }
        }
    ]
}
```

**에러 응답**

_대표 에러 없음 (공개 조회). 인증·권한 미요구 엔드포인트로 도메인 특이 에러를 반환하지 않습니다._

<!-- @generated:end -->

**설명** 등록된 IDV 프로바이더의 공개 메타데이터 목록을 반환합니다. 공개 엔드포인트로 인증이 필요하지 않으며, 비로그인 가입 흐름에서도 접근합니다. `IdentityVerificationManager::all()` 의 각 프로바이더를 `ProviderResource` 로 직렬화해 id·label·channels·render_hint·is_available 등만 노출하고, 관리자용과 달리 `settings_schema` 는 포함하지 않습니다. 인증 모달이 사용 가능한 프로바이더 선택지를 표시할 때 사용합니다.


### GET /api/identity/purposes
<!-- @generated:start:api.identity.purposes.index -->
- **라우트명**: `api.identity.purposes.index`
- **컨트롤러**: `App\Http\Controllers\Api\Identity\IdentityVerificationController@purposes`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/identity/purposes HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | string | `signup` | 기본 키 (내부 식별자) |
| label | string | `회원가입 인증` | 표시용 라벨 |
| description | string | `신규 가입자의 이메일/전화번호 소유 확인.` | 설명 (다국어 필드는 로케일별 값 객체) |
| default_provider | string | `g7:core.mail` | 이 목적에 매핑된 기본 프로바이더 ID (요청 시 provider_id 미지정이면 이 값 사용, 미설정 시 null) |
| allowed_channels | array | `["mail","sms"]` | 이 목적에서 사용 가능한 전송 채널 목록 |
| source_type | string | `core` | 목적 출처 (core: 코어 기본 4종 / module / plugin — 어느 확장이 선언했는지) |
| source_identifier | string | `core` | 출처 식별자 (목적을 선언한 확장의 identifier) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": [
        {
            "id": "signup",
            "label": "회원가입 인증",
            "description": "신규 가입자의 이메일/전화번호 소유 확인.",
            "default_provider": "g7:core.mail",
            "allowed_channels": [
                "mail",
                "sms"
            ],
            "source_type": "core",
            "source_identifier": "core"
        },
        {
            "id": "password_reset",
            "label": "비밀번호 재설정",
            "description": "비밀번호를 잊은 사용자가 본인 확인 후 재설정.",
            "default_provider": "g7:core.mail",
            "allowed_channels": [
                "mail",
                "sms"
            ],
            "source_type": "core",
            "source_identifier": "core"
        },
        {
            "id": "self_update",
            "label": "자기 정보 변경",
            "description": "로그인 사용자가 이메일/전화 등 본인 정보를 변경할 때.",
            "default_provider": "g7:core.mail",
            "allowed_channels": [
                "mail",
                "sms"
            ],
            "source_type": "core",
            "source_identifier": "core"
        },
        {
            "id": "sensitive_action",
            "label": "민감 작업",
            "description": "계정 탈퇴·관리자 작업 등 재인증이 필요한 시점.",
            "default_provider": "g7:core.mail",
            "allowed_channels": [
                "mail",
                "sms"
            ],
            "source_type": "core",
            "source_identifier": "core"
        },
        {
            "id": "checkout_verification",
            "label": "결제 시 본인 확인",
            "description": "결제 진행 전 성인/본인 확인이 필요한 경우 사용됩니다.",
            "default_provider": null,
            "allowed_channels": [
                "email",
                "sms",
                "ipin"
            ],
            "source_type": "module",
            "source_identifier": "sirsoft-ecommerce"
        }
    ]
}
```

**에러 응답**

_대표 에러 없음 (공개 조회). 인증·권한 미요구 엔드포인트로 도메인 특이 에러를 반환하지 않습니다._

<!-- @generated:end -->

**설명** 등록된 인증 목적(purpose) 목록을 반환합니다. 공개 엔드포인트로 인증이 필요하지 않습니다. `IdentityVerificationManager::getAllPurposes()` 로 코어 기본 4종(signup/password_reset/self_update/sensitive_action) + 활성 모듈/플러그인의 `getIdentityPurposes()` 선언 + `core.identity.purposes` 필터 훅(서드파티 동적 확장) 을 병합하며, 각 항목의 label/description 은 i18n 키·다국어 배열·평문 세 형태를 현재 로케일 문자열로 정규화해 내려줍니다. 각 목적의 기본 프로바이더·허용 채널·출처(source_type/source_identifier) 를 함께 반환하므로, 인증 UI 가 목적별 선택지와 문구를 구성할 때 사용합니다.


