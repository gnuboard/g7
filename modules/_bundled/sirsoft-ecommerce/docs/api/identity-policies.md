# Identity Policies API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.
>
> 7.1.0 에서 새로 생긴 경로입니다. 응답 예시는 회귀 테스트 `EcommerceIdentityPolicyPermissionGateTest` 가 확인하는 모양입니다.

---

## TL;DR (5초 요약)

```text
1. 이커머스 환경설정 「본인인증 정책」 탭이 쓰는 정책 조회·추가·수정·삭제 API 입니다
2. 조회는 sirsoft-ecommerce.identity.policies.read, 추가·수정·삭제는 sirsoft-ecommerce.identity.policies.update 권한이 필요합니다
3. 코어 정책 표에서 이커머스 몫(source_identifier = sirsoft-ecommerce)만 다룹니다 — 다른 출처의 정책 ID 는 404
4. 목록 응답의 abilities(can_create·can_update·can_delete)와 행 abilities 는 수정 권한 하나로 판정합니다
5. 검증 규칙·저장 동작은 코어 관리자 정책 API(/api/admin/identity/policies)와 같습니다
6. 운영자 정책의 대상은 이커머스 지점만 — 훅·사용자 지정 범위는 `sirsoft-ecommerce.`, 경로 범위는 `api.modules.sirsoft-ecommerce.` 로 시작 (아니면 422)
```

---


### GET /api/modules/sirsoft-ecommerce/admin/identity-policies
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.identity-policies.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.identity-policies.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\IdentityPolicyController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.identity.policies.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| scope | query | string | 아니오 | route, hook, custom | 적용 시점 필터 |
| purpose | query | string | 아니오 | max 64 | 인증 목적 필터 |
| source_type | query | string | 아니오 | core, module, plugin, admin | 출처 유형 필터 |
| source_identifier | query | string | 아니오 | max 100 | 무시됩니다 — 서버가 언제나 `sirsoft-ecommerce` 로 좁힙니다 |
| applies_to | query | string | 아니오 | self, admin, both | 적용 대상 필터 |
| fail_mode | query | string | 아니오 | block, log_only | 실패 처리 필터 |
| enabled | query | boolean | 아니오 | — | 활성 여부 필터 |
| search | query | string | 아니오 | max 255 | 키·대상 검색어 |
| per_page | query | integer | 아니오 | 1~100 | 쪽당 개수 (기본 20) |
| page | query | integer | 아니오 | — | 쪽 번호 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/identity-policies?per_page=10 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| data | array | `[{...}]` | 정책 행 목록 (행 필드는 코어 정책 리소스와 같음: id·key·scope·target·purpose·provider_id·grace_minutes·enabled·priority·conditions·source_type·source_identifier·applies_to·fail_mode·user_overrides·created_at·updated_at·abilities) |
| data[].abilities.can_update | boolean | `true` | 이 행을 고칠 수 있는지 (`sirsoft-ecommerce.identity.policies.update`) |
| data[].abilities.can_delete | boolean | `true` | 이 행을 지울 수 있는지 (같은 권한, 운영자 정책만 실제 삭제 가능) |
| abilities.can_create | boolean | `false` | 「정책 추가」 가능 여부 (`sirsoft-ecommerce.identity.policies.update`) |
| abilities.can_update | boolean | `false` | 활성 토글·편집 가능 여부 (같은 권한) |
| abilities.can_delete | boolean | `false` | 삭제 가능 여부 (같은 권한) |
| meta.current_page | integer | `1` | 현재 쪽 |
| meta.per_page | integer | `10` | 쪽당 개수 |
| meta.total | integer | `9` | 전체 개수 |
| meta.last_page | integer | `1` | 마지막 쪽 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공",
    "data": {
        "data": [
            {
                "id": 12,
                "key": "sirsoft-ecommerce.payment.cancel",
                "scope": "hook",
                "target": "sirsoft-ecommerce.payment.before_cancel",
                "purpose": "sensitive_action",
                "provider_id": null,
                "grace_minutes": 0,
                "enabled": false,
                "priority": 100,
                "conditions": null,
                "source_type": "module",
                "source_identifier": "sirsoft-ecommerce",
                "applies_to": "both",
                "fail_mode": "block",
                "user_overrides": [],
                "abilities": {
                    "can_update": false,
                    "can_delete": false
                }
            }
        ],
        "abilities": {
            "can_create": false,
            "can_update": false,
            "can_delete": false
        },
        "meta": {
            "current_page": 1,
            "per_page": 10,
            "total": 1,
            "last_page": 1
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.identity.policies.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 필터 값이 허용값 밖인 경우 |

<!-- @generated:end -->

**설명** 이커머스 환경설정 「본인인증 정책」 탭의 목록입니다. 코어 정책 표에서 이커머스 몫만 돌려주며, 요청이 다른 `source_identifier` 를 보내도 이커머스 몫으로 좁힙니다. 조회 권한만 있는 운영자는 `abilities` 가 모두 `false` 로 와서 화면이 읽기 전용이 되고, 조회 권한이 없으면 403 이라 탭에 「권한 없음」 안내가 뜹니다.


### POST /api/modules/sirsoft-ecommerce/admin/identity-policies
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.identity-policies.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.identity-policies.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\IdentityPolicyController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.identity.policies.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| key | body | string | 예 | max 120, 고유 | 정책 키 |
| scope | body | string | 예 | route, hook, custom | 적용 시점 |
| target | body | string | 예 | max 255 | 적용 위치 (라우트 이름·훅 이름 등) |
| purpose | body | string | 예 | max 64 | 인증 목적 |
| provider_id | body | string | 아니오 | max 64 | 인증 제공자 (비우면 기본 제공자) |
| grace_minutes | body | integer | 예 | 0~43200 | 인증 후 유예 시간(분) |
| enabled | body | boolean | 아니오 | — | 활성 여부 |
| priority | body | integer | 아니오 | 0~65535, 같은 대상의 활성 정책과 겹치지 않음 | 우선순위 |
| conditions | body | object | 아니오 | — | 추가 조건 |
| applies_to | body | string | 예 | self, admin, both | 적용 대상 |
| fail_mode | body | string | 예 | block, log_only | 실패 처리 |
| source_identifier | body | string | 아니오 | — | 무시됩니다 — 언제나 `sirsoft-ecommerce` 로 만듭니다 |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/identity-policies HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "key": "ecommerce-custom-bulk",
    "scope": "route",
    "target": "api.modules.sirsoft-ecommerce.admin.orders.bulk",
    "purpose": "sensitive_action",
    "provider_id": null,
    "grace_minutes": 0,
    "enabled": true,
    "priority": 100,
    "applies_to": "admin",
    "fail_mode": "block"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 — 목록 행과 같습니다(`source_type` = `admin`, `source_identifier` = `sirsoft-ecommerce`)._

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "생성되었습니다.",
    "data": {
        "id": 31,
        "key": "ecommerce-custom-bulk",
        "scope": "route",
        "target": "api.modules.sirsoft-ecommerce.admin.orders.bulk",
        "purpose": "sensitive_action",
        "provider_id": null,
        "grace_minutes": 0,
        "enabled": true,
        "priority": 100,
        "conditions": null,
        "source_type": "admin",
        "source_identifier": "sirsoft-ecommerce",
        "applies_to": "admin",
        "fail_mode": "block",
        "user_overrides": [],
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.identity.policies.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 값이 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 운영자 정책을 이커머스 몫으로 새로 만듭니다. 본문의 `source_identifier` 와 무관하게 `source_type=admin`·`source_identifier=sirsoft-ecommerce` 로 저장합니다. 정책은 출처가 아니라 범위·대상으로 적용되므로 대상도 이커머스 지점으로 묶습니다 — 훅·사용자 지정(`hook`·`custom`) 범위는 `sirsoft-ecommerce.` 로, 경로(`route`) 범위는 `api.modules.sirsoft-ecommerce.` 로 시작해야 하며, 그 밖의 대상은 422(`errors.target`, `sirsoft-ecommerce::validation.identity_policy.target_not_owned`)로 거부합니다.


### PUT /api/modules/sirsoft-ecommerce/admin/identity-policies/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.identity-policies.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.identity-policies.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\IdentityPolicyController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.identity.policies.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | — | 정책 ID (이커머스 몫이어야 함) |
| enabled | body | boolean | 아니오 | — | 활성 여부 |
| grace_minutes | body | integer | 아니오 | 0~43200 | 유예 시간(분) |
| provider_id | body | string | 아니오 | max 64 | 인증 제공자 |
| fail_mode | body | string | 아니오 | block, log_only | 실패 처리 |
| purpose | body | string | 아니오 | max 64 | 인증 목적 |
| priority | body | integer | 아니오 | 0~65535 | 우선순위 |
| conditions | body | object | 아니오 | — | 추가 조건 |
| applies_to | body | string | 아니오 | self, admin, both | 적용 대상 |
| key / scope / target | body | string | 아니오 | — | 운영자 정책(`source_type=admin`)일 때만 반영 |

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/identity-policies/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "enabled": false
}
```

**응답 필드** (`data` 내부)

_단건 응답: 고친 정책 — 목록 행과 같은 필드._

**응답 예시**

```json
{
    "success": true,
    "message": "수정되었습니다.",
    "data": {
        "id": 31,
        "key": "ecommerce-custom-bulk",
        "enabled": false,
        "source_type": "admin",
        "source_identifier": "sirsoft-ecommerce",
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.identity.policies.update`)이 없는 경우 |
| 404 | Not Found | 정책이 없거나 이커머스 몫이 아닌 경우 |
| 422 | Unprocessable Entity | 검증 실패 또는 선언형 정책에 고칠 수 있는 칸이 하나도 없는 경우 |

<!-- @generated:end -->

**설명** 이커머스 몫 정책을 고칩니다(목록의 활성 토글도 이 경로). 모듈이 선언한 정책은 키·범위·대상을 바꿀 수 없어 그 칸은 버리고 나머지만 반영합니다. 운영자 정책은 수정 뒤의 범위·대상(보내지 않은 칸은 기존 값)이 이커머스 지점이어야 하며, 아니면 추가와 같은 422(`errors.target`)입니다. 선언형 정책에 고칠 수 있는 칸이 하나도 없으면 422(`sirsoft-ecommerce::validation.identity_policy.nothing_to_update`)입니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/identity-policies/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.identity-policies.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.identity-policies.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\IdentityPolicyController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.identity.policies.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | — | 정책 ID (이커머스 몫이어야 함) |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/identity-policies/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만)._

**응답 예시**

```json
{
    "success": true,
    "message": "삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.identity.policies.update`)이 없거나, 모듈이 선언한 정책을 지우려는 경우 |
| 404 | Not Found | 정책이 없거나 이커머스 몫이 아닌 경우 |

<!-- @generated:end -->

**설명** 운영자가 만든 이커머스 몫 정책을 지웁니다. 모듈이 선언한 정책은 지울 수 없고 비활성으로 대신합니다(403).
