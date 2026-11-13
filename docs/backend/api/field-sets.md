# Field Sets API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 항목 집합(회원 가입 항목 등)의 입력 항목을 운영자가 관리하는 엔드포인트 레퍼런스입니다 — 목록·상세·추가·수정·삭제·순서·미입력 회원 수
2. `{set}` 은 집합 식별자입니다 — 코어 회원은 `core.user`, 확장이 가진 집합은 `{확장 식별자}.{이름}`
3. 권한은 집합마다 다릅니다 — 회원 집합은 조회 `core.settings.read`, 변경 `core.settings.update`
4. 표의 변경은 즉시 저장입니다 — 변경 요청에 `fields_version` 을 실으면 다른 관리자가 먼저 바꾼 경우 409 `fields_changed` 로 거부합니다
5. 기본 항목과 확장이 선언한 항목은 삭제할 수 없고(사용을 끌 수 있을 뿐), 바꿀 수 있는 속성도 운영자 추가 항목보다 좁습니다
```

---

공통 규칙

- **집합과 권한**: 라우트의 `field_set.permission` 미들웨어가 `{set}` 으로 집합을 찾아 그 집합의 조회 권한(읽기 요청) 또는 수정 권한(변경 요청)을 확인합니다. 집합이 없거나 그 집합을 가진 확장이 꺼져 있으면 404, 권한이 없으면 403 입니다.
- **항목의 출처**: `source_type` 이 `core`·`module`·`plugin` 이면 선언 항목, `custom` 이면 운영자가 추가한 항목입니다. 선언 항목은 `name`·`description`·`placeholder`·`enabled`·`required`·`show_on_signup`·`edit_scope`·`require_existing_members`·`public_profile`·`admin_list_column`·`admin_searchable`·`idv_lock` 만 바뀝니다. 그 밖의 값은 보내도 무시합니다. 선언한 쪽이 잠근 속성(`locks`)도 무시합니다.
- **요청 속성 경로**: 응답의 `attribute` 는 회원 폼 요청에서 이 항목이 쓰는 키입니다. 컬럼 저장 항목과 확장 선언 항목은 최상위 키, 운영자 추가 항목은 `custom_fields.{key}` 입니다.
- **정의 버전**: `fields_version` 은 집합의 항목이 하나라도 바뀌면 달라지는 12자 문자열입니다. 변경 요청이 이 값을 싣고 지금 값과 다르면 저장하지 않고 409 와 `error_code: fields_changed`, `data.fields_version`(지금 값)을 돌려줍니다. 싣지 않은 요청은 대조 없이 처리합니다.
- **서로 얽힌 속성**: 범위(`edit_scope`)가 `self` 가 아니면 `require_existing_members` 는 꺼진 값으로 저장됩니다. 유형이 지원하지 않는 기능(`display_name_usable`·`admin_list_column`·`admin_searchable`·`constraints.unique`)은 켜서 보내도 꺼진 값으로 저장됩니다.
- **유형**: 코어 유형은 `text`·`textarea`·`number`·`boolean`·`date`·`datetime`·`date_range`·`choice`·`multi_choice`·`phone`·`email`·`url`·`address`·`file`·`image` 15종이고 확장이 더할 수 있습니다. 유형을 제공한 확장이 꺼지면 그 유형의 항목은 `available: false` 로 목록에 남고 사용자 화면에서는 빠집니다.
- **다국어 값**: `name`·`description`·`placeholder`·선택지 `label` 은 로케일 키 객체입니다. `name` 과 선택지 `label` 은 사이트 기본 언어 값이 필수이고, 지원하지 않는 로케일 키는 422 입니다. 응답의 `label` 은 요청 로케일로 푼 이름입니다.


### GET /api/admin/field-sets/{set}/fields
<!-- @generated:start:api.admin.field-sets.fields.index -->
- **라우트명**: `api.admin.field-sets.fields.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\FieldDefinitionController@index`
- **인증/권한**: `auth:sanctum`, `admin`, `field_set.permission:read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| set | path | string | 예 | 영문·숫자·`.`·`_`·`-` | 집합 식별자 |

**요청 예시**

```http
GET /api/admin/field-sets/core.user/fields HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| set.key | string | `core.user` | 집합 식별자 |
| set.label | string | `회원 항목` | 집합 이름(요청 로케일) |
| set.owner_type | string | `core` | 집합을 가진 쪽의 종류(core, module, plugin) |
| set.owner | string | `core` | 집합을 가진 쪽의 식별자 |
| set.allows_custom | boolean | `true` | 운영자가 항목을 추가할 수 있는 집합인지 |
| set.surfaces | array | `["signup","admin_create","admin_update","self_update","required_info"]` | 이 집합이 쓰는 표면 |
| fields[] | array | — | 표시 순서의 항목 목록(꺼진 항목과 사용 불가 유형 포함) |
| fields[].id | integer | `769` | 항목 ID |
| fields[].key | string | `name` | 항목 키 |
| fields[].attribute | string | `name` | 회원 폼 요청에서 쓰는 키 |
| fields[].source_type | string | `core` | 출처(core, module, plugin, custom) |
| fields[].source_identifier | string | `core` | 출처 식별자 |
| fields[].is_custom | boolean | `false` | 운영자 추가 항목인지 |
| fields[].type | string | `text` | 유형 키 |
| fields[].type_label | string | `한 줄 글` | 유형 이름 |
| fields[].type_owner | string\|null | `core` | 유형을 제공한 쪽(유형을 쓸 수 없으면 null) |
| fields[].available | boolean | `true` | 유형을 지금 쓸 수 있는지 |
| fields[].storage | string | `column` | 저장 방식(column, handler, value) |
| fields[].name | object | `{"ko":"이름","en":"Name"}` | 이름(편집용 원본) |
| fields[].label | string | `이름` | 이름(요청 로케일) |
| fields[].description | object | `{}` | 안내 문구 |
| fields[].placeholder | object | `{}` | 예시 글 |
| fields[].options | array | `[]` | 선택지 `[{value, label}]` |
| fields[].constraints | object | `{"max_length":255}` | 입력 제한과 표시 옵션 |
| fields[].enabled | boolean | `true` | 사용 |
| fields[].required | boolean | `true` | 필수 |
| fields[].show_on_signup | boolean | `true` | 가입 화면 표시 |
| fields[].edit_scope | string | `self` | 범위(self, admin_edit, admin_only) |
| fields[].require_existing_members | boolean | `false` | 기존 회원에게도 입력 요구 |
| fields[].public_profile | boolean | `false` | 공개 프로필 노출 |
| fields[].admin_list_column | boolean | `false` | 회원 목록 열 |
| fields[].admin_searchable | boolean | `false` | 회원 검색 대상 |
| fields[].display_name_usable | boolean | `false` | 표시 이름 종류로 사용 가능 |
| fields[].idv_claim | string\|null | `name` | 본인인증 값 연결 |
| fields[].idv_lock | boolean | `false` | 인증 값으로 채우고 잠금 |
| fields[].locks | object | `{}` | 선언한 쪽이 잠근 속성과 사유 문구 |
| fields[].lock_reasons | object | `{"enabled":"nickname_display"}` | 잠긴 속성과 사유 코드. `nickname_display` 는 표시 이름 설정이 닉네임을 쓰고 있어 잠긴 것이고(닉네임 항목의 `enabled`, `required`), `declared` 는 선언한 쪽이 잠근 것 |
| fields[].ui | object | `{"group":"basic"}` | 화면 배치 |
| fields[].sort_order | integer | `10` | 표시 순서 |
| fields[].supports | object | `{"signup":true,"unique":false,"search":true,"list_column":true,"display_name":true,"options":false}` | 이 항목에서 켤 수 있는 기능 |
| fields[].user_overrides | array | `[]` | 운영자가 고친 속성 목록 |
| fields_version | string | `06425d431332` | 정의 버전 |
| types[] | array | — | 이 집합에서 고를 수 있는 유형 |
| types[].key | string | `text` | 유형 키 |
| types[].label | string | `한 줄 글` | 유형 이름 |
| types[].description | string | `이름, 직함처럼 짧은 글을 받습니다.` | 유형 설명 |
| types[].category | string | `text` | 분류(text, number, datetime, choice, contact, address, file, extension) |
| types[].renderer | string | `builtin` | 그리기 갈래(builtin, address, file, extension) |
| types[].owner | string | `core` | 유형을 제공한 쪽 |
| abilities.can_update | boolean | `true` | 요청자가 이 집합을 바꿀 수 있는지 |
| notices.signup_layout_modified | boolean | `false` | 가입 화면 레이아웃이 운영자 수정본으로 유지되고 있고 그 안에 항목 반복 구획이 없는 경우 true (회원 집합만. 그 밖의 집합은 항상 false) |

**응답 예시**

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "set": {
            "key": "core.user",
            "label": "회원 항목",
            "owner_type": "core",
            "owner": "core",
            "allows_custom": true,
            "surfaces": ["signup", "admin_create", "admin_update", "self_update", "required_info"]
        },
        "fields": [
            {
                "id": 769,
                "field_set": "core.user",
                "key": "name",
                "attribute": "name",
                "source_type": "core",
                "source_identifier": "core",
                "is_custom": false,
                "type": "text",
                "type_label": "한 줄 글",
                "type_owner": "core",
                "type_owner_type": "core",
                "available": true,
                "storage": "column",
                "name": { "en": "Name", "ko": "이름" },
                "label": "이름",
                "description": {},
                "placeholder": {},
                "options": [],
                "constraints": { "max_length": 255 },
                "enabled": true,
                "required": true,
                "show_on_signup": true,
                "edit_scope": "self",
                "require_existing_members": false,
                "public_profile": false,
                "admin_list_column": false,
                "admin_searchable": false,
                "display_name_usable": false,
                "idv_claim": "name",
                "idv_lock": false,
                "locks": {},
                "ui": { "group": "basic" },
                "sort_order": 10,
                "supports": { "signup": true, "unique": false, "search": true, "list_column": true, "display_name": true, "options": false },
                "user_overrides": [],
                "created_at": "2026-11-11 16:00:06",
                "updated_at": "2026-11-11 16:00:06"
            }
        ],
        "fields_version": "06425d431332",
        "types": [
            {
                "key": "text",
                "label": "한 줄 글",
                "description": "이름, 직함처럼 짧은 글을 받습니다.",
                "category": "text",
                "renderer": "builtin",
                "owner_type": "core",
                "owner": "core",
                "available": true
            }
        ],
        "abilities": { "can_update": true },
        "notices": { "signup_layout_modified": false }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 집합의 조회 권한이 없는 경우 |
| 404 | Not Found | 집합이 없거나 그 집합을 가진 확장이 꺼진 경우 |

<!-- @generated:end -->

**설명** 집합의 항목 표를 그리는 데 필요한 것을 한 번에 줍니다 — 항목 목록, 낡은 표 판정용 정의 버전, 「항목 추가」에서 고를 유형. 응답 예시는 항목과 유형을 1건씩으로 줄인 것입니다(회원 집합의 기본 항목은 11건, 코어 유형은 15종).


### GET /api/admin/field-sets/{set}/fields/{field}
<!-- @generated:start:api.admin.field-sets.fields.show -->
- **라우트명**: `api.admin.field-sets.fields.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\FieldDefinitionController@show`
- **인증/권한**: `auth:sanctum`, `admin`, `field_set.permission:read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| set | path | string | 예 | 영문·숫자·`.`·`_`·`-` | 집합 식별자 |
| field | path | integer | 예 | 숫자 | 항목 ID |

**요청 예시**

```http
GET /api/admin/field-sets/core.user/fields/780 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| field | object | — | 목록의 항목과 같은 필드에 아래 세 필드가 더해집니다 |
| field.values_count | integer | `0` | 이 항목에 값이 있는 소유자 수(값 테이블 저장 항목. 그 밖은 0) |
| field.options[].usage_count | integer | `0` | 그 선택지를 고른 소유자 수 |
| field.other_usage_count | integer | `0` | 「기타(직접 입력)」를 고른 소유자 수 |
| fields_version | string | `7924fdac78bf` | 정의 버전 |

**응답 예시**

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "field": {
            "id": 780,
            "field_set": "core.user",
            "key": "job",
            "attribute": "custom_fields.job",
            "source_type": "custom",
            "is_custom": true,
            "type": "choice",
            "storage": "value",
            "name": { "en": "Job", "ko": "직업" },
            "label": "직업",
            "options": [
                { "label": { "ko": "학생" }, "value": "student", "usage_count": 0 },
                { "label": { "ko": "직장인" }, "value": "worker", "usage_count": 0 }
            ],
            "enabled": true,
            "required": true,
            "show_on_signup": true,
            "edit_scope": "self",
            "values_count": 0,
            "other_usage_count": 0
        },
        "fields_version": "7924fdac78bf"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 집합의 조회 권한이 없는 경우 |
| 404 | Not Found | 집합이 없거나, 항목이 없거나, 항목이 다른 집합에 속한 경우 |

<!-- @generated:end -->

**설명** 수정 창을 열 때 부릅니다. 값 수와 선택지별 사용 수는 「이 값을 고른 회원 N명의 값이 비워집니다」 같은 안내에 씁니다. 응답 예시는 목록과 겹치는 필드를 줄였습니다.


### POST /api/admin/field-sets/{set}/fields
<!-- @generated:start:api.admin.field-sets.fields.store -->
- **라우트명**: `api.admin.field-sets.fields.store`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\FieldDefinitionController@store`
- **인증/권한**: `auth:sanctum`, `admin`, `field_set.permission:manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| set | path | string | 예 | 영문·숫자·`.`·`_`·`-` | 집합 식별자 |
| key | body | string | 예 | 영문 소문자로 시작, 소문자·숫자·밑줄, 2~50자. 집합 안에서 유일 | 항목 키(만든 뒤 바꿀 수 없음) |
| type | body | string | 예 | 집합이 받는 유형 가운데 지금 쓸 수 있는 유형 | 유형(만든 뒤 바꿀 수 없음) |
| name | body | object | 예 | 로케일 키 객체. 사이트 기본 언어 값 필수, 값마다 max 100 | 이름 |
| description | body | object | 아니오 | 로케일 키 객체, 값마다 max 500 | 안내 문구 |
| placeholder | body | object | 아니오 | 로케일 키 객체, 값마다 max 255 | 예시 글 |
| options | body | array | 조건부 | 선택 유형은 1~50개 필수(`constraints.allow_other` 를 켠 하나 선택은 생략 가능) | 선택지 |
| options[].value | body | string | 예 | 영문 소문자·숫자로 시작, 소문자·숫자·밑줄·하이픈, max 50, 겹치지 않음 | 선택지 값 |
| options[].label | body | object | 예 | 로케일 키 객체. 사이트 기본 언어 값 필수 | 선택지 이름 |
| constraints | body | object | 아니오 | 유형마다 다름(아래 표) | 입력 제한과 표시 옵션 |
| constraints.unique | body | boolean | 아니오 | — | 중복 금지(지원하는 유형만) |
| enabled | body | boolean | 아니오 | 기본 true | 사용 |
| required | body | boolean | 아니오 | 기본 false | 필수 |
| show_on_signup | body | boolean | 아니오 | 기본 true | 가입 화면 표시 |
| edit_scope | body | string | 아니오 | `self`, `admin_edit`, `admin_only`. 기본 `self` | 범위 |
| require_existing_members | body | boolean | 아니오 | 기본 false | 기존 회원에게도 입력 요구 |
| public_profile | body | boolean | 아니오 | 기본 false | 공개 프로필 노출 |
| admin_list_column | body | boolean | 아니오 | 기본 false | 회원 목록 열 |
| admin_searchable | body | boolean | 아니오 | 기본 false | 회원 검색 대상 |
| display_name_usable | body | boolean | 아니오 | 기본 false | 표시 이름 종류로 사용 가능 |
| idv_claim | body | string | 아니오 | `name`, `mobile`, `birth_date`, `gender` | 본인인증 값 연결 |
| idv_lock | body | boolean | 아니오 | 기본 false | 인증 값으로 채우고 잠금 |
| ui.group | body | string | 아니오 | 영문 소문자·숫자·밑줄, max 50 | 화면 묶음 |
| ui.width | body | string | 아니오 | `full`, `half` | 너비 |
| fields_version | body | string | 아니오 | max 64 | 낡은 표 판정용 정의 버전 |

유형별 `constraints`

| 유형 | 키 |
| --- | --- |
| text | `min_length`, `max_length`(1~255), `regex`(구분자를 포함한 정규식), `regex_message`(로케일 키 객체) |
| textarea | `min_length`, `max_length`(1~5000), `rows`(2~20) |
| number | `min`, `max`, `decimals`(0~6), `unit`(로케일 키 객체) |
| boolean | `widget`(`toggle`, `checkbox`), `inline_label`(로케일 키 객체), `default` |
| date, datetime, date_range | `min`, `max` — 날짜(`2026-01-31`) 또는 `today`(오늘 포함), `after_today`(오늘 제외). 「오늘」은 사이트 시간대 기준 |
| choice | `display`(`select`, `radio`), `allow_other` |
| multi_choice | `display`(`checkbox`, `select`), `min_select`, `max_select`(1~50) |
| file, image | `extensions`(확장자 목록), `max_count`(1~10), `max_size_mb`(1~50) |

**요청 예시**

```http
POST /api/admin/field-sets/core.user/fields HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json
Authorization: Bearer {YOUR_TOKEN}

{
    "key": "job",
    "type": "choice",
    "name": { "ko": "직업", "en": "Job" },
    "required": true,
    "options": [
        { "value": "student", "label": { "ko": "학생" } },
        { "value": "worker", "label": { "ko": "직장인" } }
    ],
    "fields_version": "06425d431332"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| field | object | — | 만든 항목(목록의 항목과 같은 필드) |
| field.source_type | string | `custom` | 운영자 추가 항목은 늘 `custom` |
| field.storage | string | `value` | 운영자 추가 항목은 늘 공용 값 테이블에 저장 |
| field.attribute | string | `custom_fields.job` | 회원 폼 요청에서 쓰는 키 |
| field.sort_order | integer | `120` | 맨 뒤에 놓입니다 |
| fields_version | string | `7924fdac78bf` | 새 정의 버전 |

**응답 예시**

```json
{
    "success": true,
    "message": "생성되었습니다.",
    "data": {
        "field": {
            "id": 780,
            "field_set": "core.user",
            "key": "job",
            "attribute": "custom_fields.job",
            "source_type": "custom",
            "source_identifier": "custom",
            "is_custom": true,
            "type": "choice",
            "type_label": "하나 선택",
            "available": true,
            "storage": "value",
            "name": { "ko": "직업", "en": "Job" },
            "label": "직업",
            "options": [
                { "value": "student", "label": { "ko": "학생" } },
                { "value": "worker", "label": { "ko": "직장인" } }
            ],
            "enabled": true,
            "required": true,
            "show_on_signup": true,
            "edit_scope": "self",
            "require_existing_members": false,
            "sort_order": 120,
            "supports": { "signup": true, "unique": false, "search": true, "list_column": true, "display_name": false, "options": true }
        },
        "fields_version": "7924fdac78bf"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 집합의 수정 권한이 없는 경우, 운영자 추가를 받지 않는 집합인 경우(`fields.errors.custom_not_allowed`) |
| 404 | Not Found | 집합이 없는 경우 |
| 409 | Conflict | `fields_version` 이 지금 값과 다른 경우 — `error_code: fields_changed`, `data.fields_version` 에 지금 값 |
| 422 | Unprocessable Entity | 키 형식·중복, 고를 수 없는 유형, 사이트 기본 언어 이름 누락, 선택지 없는 선택 항목, 겹치는 선택지 값, 잘못된 정규식 등 |

<!-- @generated:end -->

**설명** 운영자 추가 항목을 만듭니다. 422 응답의 오류 키는 요청 속성 경로와 같습니다. 예: 키 형식이 틀리고 선택지가 없는 하나 선택 요청은 `errors.key`("키는 영문 소문자로 시작하고 영문 소문자, 숫자, 밑줄만 쓸 수 있습니다(2~50자).")와 `errors.options`("선택지를 1개 이상 넣어 주세요.")를 함께 돌려줍니다.


### PUT /api/admin/field-sets/{set}/fields/{field}
<!-- @generated:start:api.admin.field-sets.fields.update -->
- **라우트명**: `api.admin.field-sets.fields.update`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\FieldDefinitionController@update`
- **인증/권한**: `auth:sanctum`, `admin`, `field_set.permission:manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| set | path | string | 예 | 영문·숫자·`.`·`_`·`-` | 집합 식별자 |
| field | path | integer | 예 | 숫자 | 항목 ID |
| (본문) | body | — | 아니오 | 추가 요청과 같은 필드에서 `key`·`type` 을 뺀 것. 모두 선택 | 보낸 속성만 바꿉니다 |
| fields_version | body | string | 아니오 | max 64 | 낡은 표 판정용 정의 버전 |

**요청 예시**

```http
PUT /api/admin/field-sets/core.user/fields/771 HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json
Authorization: Bearer {YOUR_TOKEN}

{
    "required": true,
    "require_existing_members": true
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| field | object | — | 수정한 항목(목록의 항목과 같은 필드) |
| field.required | boolean | `true` | 바꾼 값 |
| field.require_existing_members | boolean | `true` | 바꾼 값 |
| fields_version | string | `af4909561d31` | 새 정의 버전 |

**응답 예시**

```json
{
    "success": true,
    "message": "수정되었습니다.",
    "data": {
        "field": {
            "id": 771,
            "field_set": "core.user",
            "key": "mobile",
            "attribute": "mobile",
            "source_type": "core",
            "is_custom": false,
            "type": "phone",
            "type_label": "전화번호",
            "storage": "column",
            "label": "휴대폰",
            "enabled": true,
            "required": true,
            "show_on_signup": true,
            "edit_scope": "self",
            "require_existing_members": true
        },
        "fields_version": "af4909561d31"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 집합의 수정 권한이 없는 경우 |
| 404 | Not Found | 집합이나 항목이 없거나, 항목이 다른 집합에 속한 경우 |
| 409 | Conflict | `fields_version` 이 지금 값과 다른 경우 — `error_code: fields_changed`("다른 관리자가 항목을 바꿨습니다. 다시 불러왔습니다.") |
| 422 | Unprocessable Entity | 값 형식 오류, 선택지가 하나도 없는 선택 항목을 필수로 켜는 경우(`errors.options`) |

<!-- @generated:end -->

**설명** 표의 스위치 한 칸 저장과 수정 창 저장이 같은 엔드포인트를 씁니다. 보낸 속성만 바뀌고, 운영자가 바꾼 속성은 `user_overrides` 에 기록되어 코어·확장을 업데이트해도 유지됩니다. 선택지 목록을 보내면서 기존 선택지를 뺐다면, 그 값을 고른 소유자의 값이 같은 요청에서 비워집니다(여러 개 선택은 그 값만 빠집니다).


### DELETE /api/admin/field-sets/{set}/fields/{field}
<!-- @generated:start:api.admin.field-sets.fields.destroy -->
- **라우트명**: `api.admin.field-sets.fields.destroy`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\FieldDefinitionController@destroy`
- **인증/권한**: `auth:sanctum`, `admin`, `field_set.permission:manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| set | path | string | 예 | 영문·숫자·`.`·`_`·`-` | 집합 식별자 |
| field | path | integer | 예 | 숫자 | 항목 ID |
| fields_version | query | string | 아니오 | max 64 | 낡은 표 판정용 정의 버전 |

**요청 예시**

```http
DELETE /api/admin/field-sets/core.user/fields/780?fields_version=55e0c42db93a HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| removed_values | integer | `0` | 함께 지운 값 행 수 |
| fields_version | string | `b6e22c94dfe3` | 새 정의 버전 |

**응답 예시**

```json
{
    "success": true,
    "message": "삭제되었습니다.",
    "data": {
        "removed_values": 0,
        "fields_version": "b6e22c94dfe3"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 집합의 수정 권한이 없는 경우, 기본 항목이나 확장이 선언한 항목인 경우(`fields.errors.cannot_delete_declared` — "기본 항목과 확장이 제공한 항목은 삭제할 수 없습니다. 사용을 끄면 화면에서 사라집니다.") |
| 404 | Not Found | 집합이나 항목이 없는 경우 |
| 409 | Conflict | `fields_version` 이 다른 경우(`error_code: fields_changed`), 다른 설정이 이 항목을 쓰고 있는 경우(`error_code: field_in_use`, `data.usages` 와 `errors.usages` 에 사용처 목록 `[{type, label, area}]`. `type` 은 `selectable_kind`(회원이 고를 수 있는 종류), `member_default_kind`(회원 기본 종류), `area`(그 종류로 고정된 영역), `area_instance`(그 종류로 고정된 영역의 인스턴스)이고 `area` 에는 영역 키가 온다) |

<!-- @generated:end -->

**설명** 운영자 추가 항목과 그 항목에 입력된 값을 함께 지웁니다. 되돌릴 수 없습니다. 삭제 전에 필터 훅 `core.field_definition.filter_usages` 로 사용처를 모으며, 사용처가 하나라도 있으면 지우지 않습니다.


### PUT /api/admin/field-sets/{set}/fields/order
<!-- @generated:start:api.admin.field-sets.fields.reorder -->
- **라우트명**: `api.admin.field-sets.fields.reorder`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\FieldDefinitionController@reorder`
- **인증/권한**: `auth:sanctum`, `admin`, `field_set.permission:manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| set | path | string | 예 | 영문·숫자·`.`·`_`·`-` | 집합 식별자 |
| order | body | array | 예 | 1~500개 | 새 순서의 항목 ID 목록 |
| order[] | body | integer | 예 | 이 집합의 항목 ID, 겹치지 않음 | 항목 ID |
| fields_version | body | string | 아니오 | max 64 | 낡은 표 판정용 정의 버전 |

**요청 예시**

```http
PUT /api/admin/field-sets/core.user/fields/order HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json
Authorization: Bearer {YOUR_TOKEN}

{
    "order": [780, 779, 778, 777, 776, 775, 774, 773, 772, 771, 770, 769]
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| fields[] | array | — | 새 순서의 항목 목록(목록 응답과 같은 모양) |
| fields[].sort_order | integer | `10` | 보낸 순서대로 10 단위로 다시 매깁니다 |
| fields_version | string | `55e0c42db93a` | 새 정의 버전 |
| set, types, abilities, notices | — | — | 목록 응답과 같습니다 |

**응답 예시**

```json
{
    "success": true,
    "message": "수정되었습니다.",
    "data": {
        "set": { "key": "core.user", "label": "회원 항목", "owner_type": "core", "owner": "core", "allows_custom": true, "surfaces": ["signup", "admin_create", "admin_update", "self_update", "required_info"] },
        "fields": [
            { "id": 780, "key": "job", "sort_order": 10 },
            { "id": 779, "key": "bio", "sort_order": 20 }
        ],
        "fields_version": "55e0c42db93a",
        "types": [],
        "abilities": { "can_update": true }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 집합의 수정 권한이 없는 경우 |
| 404 | Not Found | 집합이 없는 경우 |
| 409 | Conflict | `fields_version` 이 지금 값과 다른 경우 — `error_code: fields_changed` |
| 422 | Unprocessable Entity | 이 집합에 없는 항목 ID, 겹친 ID, 빈 목록 |

<!-- @generated:end -->

**설명** 끌어서 순서를 바꾼 직후 부릅니다. 목록에 없는 항목은 지금 순서를 유지합니다. 응답 예시는 항목의 필드와 유형 목록을 줄였습니다.


### GET /api/admin/field-sets/{set}/fields/{field}/missing-count
<!-- @generated:start:api.admin.field-sets.fields.missing-count -->
- **라우트명**: `api.admin.field-sets.fields.missing-count`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\FieldDefinitionController@missingCount`
- **인증/권한**: `auth:sanctum`, `admin`, `field_set.permission:read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| set | path | string | 예 | 영문·숫자·`.`·`_`·`-` | 집합 식별자 |
| field | path | integer | 예 | 숫자 | 항목 ID |

**요청 예시**

```http
GET /api/admin/field-sets/core.user/fields/771/missing-count HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| missing_count | integer | `1` | 이 항목이 비어 있는 회원 수(탈퇴 회원 제외) |

**응답 예시**

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "missing_count": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 집합의 조회 권한이 없는 경우 |
| 404 | Not Found | 집합이나 항목이 없는 경우 |

<!-- @generated:end -->

**설명** 선택 항목을 필수로 바꾸기 전에 「비어 있는 기존 회원 N명」을 보여 주는 데 씁니다. 회원 집합(`core.user`)의 컬럼 저장 항목과 값 테이블 저장 항목만 세며, 그 밖의 집합과 확장 핸들러 저장 항목은 0 입니다. 주소 항목은 우편번호나 기본 주소가 빈 회원을 셉니다.


---

## 회원 탭 안내 값

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