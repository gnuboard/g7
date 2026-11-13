# Member Fields API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 환경설정 > 회원 탭이 닉네임 정책과 표시 이름 구획에서 쓰는 읽기 전용 안내 값 엔드포인트 레퍼런스입니다
2. nickname-duplicates·nickname-empty-count — 닉네임 정책을 바꾸기 전 영향받는 회원 수
3. nickname-recompute-status — 닉네임 정규화 재계산 작업의 진행 상태
4. display-name-options — 표시 이름에 고를 수 있는 종류와 영역
5. 권한은 모두 core.settings.read 이고, 항목 표 자체의 관리는 field-sets.md 입니다
```

---

환경설정 > 회원 탭이 닉네임 정책 구획에서 쓰는 읽기 전용 값입니다. 권한은 `core.settings.read` 입니다.

### GET /api/admin/member-fields/nickname-duplicates
<!-- @generated:start:api.admin.member-fields.nickname-duplicates -->
- **라우트명**: `api.admin.member-fields.nickname-duplicates`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\MemberFieldController@nicknameDuplicates`
- **인증/권한**: `auth:sanctum`, `admin`, `permission:admin,core.settings.read`

**요청 파라미터**

없음

**요청 예시**

```http
GET /api/admin/member-fields/nickname-duplicates HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| duplicate_nicknames | integer | `2` | 두 회원 이상이 쓰는 닉네임의 종 수 |
| duplicate_members | integer | `5` | 그 닉네임을 쓰는 회원 수 |

**응답 예시**

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "duplicate_nicknames": 2,
        "duplicate_members": 5
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 환경설정 조회 권한이 없는 경우 |

<!-- @generated:end -->

**설명** 닉네임 「중복 금지」를 켜기 전에 「겹치는 닉네임 N종, 회원 M명」을 보여 주는 데 씁니다. 같은 닉네임인지는 비교용 정규화 값(전각과 반각, 대소문자, 공백 차이를 없앤 값)으로 가립니다. 겹치는 회원은 정책을 켠 뒤에도 지금 닉네임을 그대로 쓰고, 닉네임을 바꿀 때부터 중복 검사를 받습니다.


### GET /api/admin/member-fields/nickname-recompute-status
<!-- @generated:start:api.admin.member-fields.nickname-recompute-status -->
- **라우트명**: `api.admin.member-fields.nickname-recompute-status`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\MemberFieldController@nicknameRecomputeStatus`
- **인증/권한**: `auth:sanctum`, `admin`, `permission:admin,core.settings.read`

**요청 파라미터**

없음

**요청 예시**

```http
GET /api/admin/member-fields/nickname-recompute-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| status | string | `running` | `idle`(돈 적 없음), `running`(도는 중), `done`(끝남) |
| processed | integer | `500` | 지금까지 처리한 회원 수 |
| total | integer | `1200` | 닉네임이 있는 전체 회원 수 |
| unique | boolean\|null | `true` | 이 작업이 맞추고 있는 「중복 금지」 값 (돈 적 없으면 null) |
| started_at | string\|null | `2026-11-11T09:00:00+09:00` | 시작 시각 |
| finished_at | string\|null | `null` | 끝난 시각 (도는 중이면 null) |

**응답 예시**

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "status": "running",
        "processed": 500,
        "total": 1200,
        "unique": true,
        "started_at": "2026-11-11T09:00:00+09:00",
        "finished_at": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 환경설정 조회 권한이 없는 경우 |

<!-- @generated:end -->

**설명** 「중복 금지」 값을 바꿔 저장하면 회원의 중복 검사용 키를 다시 계산하는 작업이 큐에 들어갑니다. 화면은 이 값을 주기적으로 읽어 진행 막대를 그립니다. 진행 상태는 하루 동안 보관됩니다.


### GET /api/admin/member-fields/nickname-empty-count
<!-- @generated:start:api.admin.member-fields.nickname-empty-count -->
- **라우트명**: `api.admin.member-fields.nickname-empty-count`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\MemberFieldController@nicknameEmptyCount`
- **인증/권한**: `auth:sanctum`, `admin`, `permission:admin,core.settings.read`

**요청 파라미터**

없음

**요청 예시**

```http
GET /api/admin/member-fields/nickname-empty-count HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| nickname_empty_count | integer | `2` | 닉네임이 비어 있는 회원 수 (탈퇴 회원 제외) |
| nickname.enabled | boolean | `true` | 닉네임 항목에 운영자가 저장해 둔 「사용」 값 |
| nickname.required | boolean | `false` | 닉네임 항목에 운영자가 저장해 둔 「필수」 값 |
| nickname.require_existing_members | boolean | `false` | 닉네임 항목의 「기존 회원에게도 입력 요구」 값 |
| nickname_requirement | string | `none` | 지금 표시 이름 설정이 닉네임에 거는 요구 (`all`, `chosen`, `none`) |

**응답 예시**

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "nickname_empty_count": 2,
        "nickname": {
            "enabled": true,
            "required": false,
            "require_existing_members": false
        },
        "nickname_requirement": "none"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 환경설정 조회 권한이 없는 경우 |

<!-- @generated:end -->

**설명** 표시 이름을 닉네임이 필요한 종류로 바꾸기 전에 「닉네임이 사용, 필수로 고정됩니다. 닉네임이 빈 회원 N명」을 보여 주는 데 씁니다. 화면의 「로그인 시 닉네임 입력 요구」를 체크하면 설정 저장 요청에 `member.nickname_require_existing_members: true` 를 함께 보냅니다.


### GET /api/admin/member-fields/display-name-options
<!-- @generated:start:api.admin.member-fields.display-name-options -->
- **라우트명**: `api.admin.member-fields.display-name-options`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\MemberFieldController@displayNameOptions`
- **인증/권한**: `auth:sanctum`, `admin`, `permission:admin,core.settings.read`

**요청 파라미터**

없음

**요청 예시**

```http
GET /api/admin/member-fields/display-name-options HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| kinds[] | array | — | 지금 쓸 수 있는 표시 이름 종류 (코어, 확장, 추가 항목 순) |
| kinds[].key | string | `masked_name` | 종류 키 |
| kinds[].label | string | `가린 이름` | 종류 이름 |
| kinds[].owner_type | string | `core` | 제공한 쪽 (`core`, `module`, `plugin`, 추가 항목은 `custom`) |
| kinds[].owner | string | `core` | 제공한 쪽의 식별자 |
| kinds[].requires_nickname | boolean | `false` | 닉네임이 필요한 종류인지 |
| kinds[].searchable | boolean | `false` | 사용자 화면의 작성자 검색으로 찾을 수 있는 종류인지 |
| kinds[].fallback | string\|null | `masked_id` | 값이 없을 때 대신 쓰는 종류 |
| kinds[].preview | string\|null | `홍*동` | 표본 회원을 그 종류로 계산한 글자 |
| areas[] | array | — | 영역 (상위 영역 아래에 하위 영역이 오는 순서) |
| areas[].key | string | `core:notification:mail` | 영역 키 |
| areas[].label | string | `메일` | 영역 이름 |
| areas[].parent | string\|null | `core:notification` | 상위 영역 키 |
| areas[].depth | integer | `2` | 들여쓰기 깊이 |
| areas[].audience | string | `notification` | 보는 사람 (`user`, `admin`, `notification`) |
| areas[].owner_type, areas[].owner | string | `core` | 등록한 쪽 |
| areas[].can_inherit | boolean | `true` | 「상위 영역 따름」을 고를 수 있는지 (사이트 기본 영역만 false) |
| areas[].mode | string | `inherit` | 지금 방식 (`inherit`, `fixed`, `member`) |
| areas[].kind | string\|null | `null` | 고정한 종류 (`mode` 가 `fixed` 일 때) |
| areas[].default | object | `{"mode":"inherit","kind":null}` | 영역의 선언 기본값 |
| areas[].effective | object | `{"mode":"fixed","kind":"name","source":"core:site"}` | 상속을 다 따라간 결과와 그 값을 정한 영역 |
| selectable_kinds | array | `["name"]` | 회원이 고를 수 있는 종류 |
| member_default_kind | string | `name` | 회원 기본 종류 |
| sample | object | `{"name":"홍길동","nickname":"길동이","id":"gildong"}` | 미리보기에 쓴 표본 회원 (이름, 닉네임, 아이디) |
| nickname_requirement | string | `none` | 지금 설정이 닉네임에 거는 요구 |

**응답 예시**

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "kinds": [
            {
                "key": "name",
                "label": "이름",
                "owner_type": "core",
                "owner": "core",
                "requires_nickname": false,
                "searchable": true,
                "fallback": "nickname",
                "preview": "홍길동"
            },
            {
                "key": "masked_name",
                "label": "가린 이름",
                "owner_type": "core",
                "owner": "core",
                "requires_nickname": false,
                "searchable": false,
                "fallback": "masked_id",
                "preview": "홍*동"
            }
        ],
        "areas": [
            {
                "key": "core:site",
                "label": "사이트 기본",
                "parent": null,
                "depth": 0,
                "audience": "user",
                "owner_type": "core",
                "owner": "core",
                "can_inherit": false,
                "mode": "fixed",
                "kind": "name",
                "default": {
                    "mode": "fixed",
                    "kind": "name"
                },
                "effective": {
                    "mode": "fixed",
                    "kind": "name",
                    "source": "core:site"
                }
            }
        ],
        "selectable_kinds": [
            "name"
        ],
        "member_default_kind": "name",
        "sample": {
            "name": "홍길동",
            "nickname": "길동이",
            "id": "gildong"
        },
        "nickname_requirement": "none"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 환경설정 조회 권한이 없는 경우 |

<!-- @generated:end -->

**설명** 환경설정 > 회원 > 표시 이름 구획이 그릴 종류와 영역입니다. 저장은 `POST /api/admin/settings` 의 `member` 탭으로 합니다.
