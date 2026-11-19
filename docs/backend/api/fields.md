# Fields API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 로그인한 회원 본인의 회원 항목 계약과 파일 항목 업로드, 삭제 엔드포인트 레퍼런스입니다
2. GET /api/me/fields — 내 정보 수정, 보기, 추가 정보 입력 화면이 그릴 항목 (context=profile|required)
3. POST·DELETE /api/me/fields/{key}/file — 파일, 이미지 항목은 PUT /api/me 본문이 아니라 이 API 로 바로 저장합니다
4. 값 자체는 GET /api/me 가 줍니다(컬럼 항목은 최상위 키, 운영자 추가 항목은 custom_fields)
5. 추가 정보 저장은 required-fields.md, 운영자의 항목 관리는 field-sets.md
```

---


### GET /api/me/fields
<!-- @generated:start:api.me.fields -->
- **라우트명**: `api.me.fields`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\ProfileController@fields`
- **인증/권한**: `auth:sanctum`, `check.user_status`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| context | query | string | 아니오 | `profile`, `required` | 어느 화면의 항목인지. 생략하면 `profile`. `required` 는 추가 정보 입력 화면(비어 있는 필수 항목만) |
| preview_name | query | string | 아니오 | 최대 255자 | 표시 이름 종류 미리보기에 쓸 입력 중인 이름 (저장하지 않는다). `context=profile` 에서만 쓰인다 |
| preview_nickname | query | string | 아니오 | 최대 50자 | 미리보기에 쓸 입력 중인 닉네임 |
| preview_email | query | string | 아니오 | 최대 255자 | 미리보기에 쓸 입력 중인 이메일 (아이디 가림 종류) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.user.profile_fields_validation_rules`).

**요청 예시**

```http
GET /api/me/fields?context=profile HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| fields[] | array | — | 내 정보 수정과 보기 화면이 그릴 항목. 표시 순서대로 옵니다. 항목의 모양은 `GET /api/auth/register/fields` 와 같고 `locked_until` 이 더 있습니다. 아래는 이 화면에서 값이 달라지는 필드입니다 |
| fields[].readonly | boolean | `true` | 본인이 고칠 수 없는 칸이면 true |
| fields[].locked_reason | string\|null | `nickname_interval` | 잠긴 사유. `admin_only`(관리자만 수정 항목), `nickname_interval`(닉네임 변경 주기), `identity`(본인인증 값으로 채워져 잠긴 항목. 다른 값을 보내면 422), `sso`, `locked` 등 확장이 잠근 사유 |
| fields[].locked_until | string\|null | `2026-12-11 17:23` | 닉네임을 다시 바꿀 수 있는 시각(회원 시간대). 주기 잠금이 아니면 null |
| fields[].display_value | string\|null | `직장인` | 보기 화면용 문자열(선택지는 이름, 파일은 파일 이름) |
| fields[].files[] | array\|null | `[{"id":812,"name":"biz.pdf"}]` | 파일·이미지 항목에 올라가 있는 파일. 그 밖의 유형은 null |
| fields[].file_rules | object\|null | `{"extensions":["pdf"],"max_count":1,"max_size_mb":10}` | 파일·이미지 항목의 업로드 제한. 그 밖의 유형은 null |
| fields_version | string | `55e0c42db93a` | 항목 정의 버전 |
| pending_required_fields | array | `["job"]` | `context=required` 에서만. 비어 있는 필수 항목의 키. 이때 `display_name` 묶음은 오지 않는다 |
| display_name.can_choose | boolean | `true` | 종류 선택 칸을 보일지 (권한이 있고 고를 종류가 둘 이상) |
| display_name.value | string\|null | `nickname` | 회원이 고른 종류. 고르지 않았거나 고른 종류가 허용에서 빠졌으면 null (가입 화면은 항상 null) |
| display_name.value_unavailable | boolean | `false` | 전에 고른 종류가 허용에서 빠졌는지. true 면 화면이 「종류를 다시 골라 주세요」를 보인다 |
| display_name.unavailable_kind | object\|null | `{"value":"field:alias","label":"활동명"}` | 허용에서 빠진, 전에 고른 종류와 그 이름 |
| display_name.default_kind | string | `name` | 고르지 않았을 때 적용되는 회원 기본 종류 |
| display_name.default_kind_label | string | `이름` | 회원 기본 종류의 이름 |
| display_name.effective_kind | string | `nickname` | 지금 이 회원에게 적용되는 종류 |
| display_name.current | string\|null | `길동이` | 지금 다른 회원에게 보이는 이름 (가입 화면은 null) |
| display_name.options[] | array | — | 고를 수 있는 종류. 고를 수 없으면 빈 배열 |
| display_name.options[].value | string | `masked_name` | 종류 키. 요청의 `display_name_kind` 로 보낸다 |
| display_name.options[].label | string | `가린 이름` | 종류 이름 |
| display_name.options[].preview | string\|null | `홍*동` | 그 종류를 고르면 보일 글자. 가림 규칙이 서버와 같게 서버가 계산한다 |
| display_name.options[].requires_nickname | boolean | `false` | 닉네임이 필요한 종류인지. 고르면 닉네임이 필수가 된다 |
| display_name.options[].is_default | boolean | `false` | 회원 기본 종류인지 |
| display_name.preview_is_sample | boolean | `false` | 미리보기를 표본 회원(홍길동, 길동이)으로 계산했는지 |
| display_name.nickname_requirement | string | `chosen` | 표시 이름 설정이 닉네임에 거는 요구 (`all` 전원 필수, `chosen` 닉네임 종류로 보이는 회원만 필수, `none`) |

**응답 예시**

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "fields": [
            {
                "key": "nickname",
                "name": "nickname",
                "type": "text",
                "renderer": "builtin",
                "owner": "core",
                "owner_type": "core",
                "source_label": null,
                "label": "닉네임",
                "placeholder": "닉네임을 입력하세요",
                "help": null,
                "required": false,
                "readonly": true,
                "locked_reason": "nickname_interval",
                "locked_until": "2026-12-11 17:23",
                "deferred": false,
                "options": [],
                "constraints": { "max_length": 50 },
                "default": null,
                "ui": { "group": "basic" },
                "parts": null,
                "display_value": "길동이",
                "files": null,
                "file_rules": null
            }
        ],
        "fields_version": "55e0c42db93a"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | `context` 가 허용값이 아니거나 미리보기 인자가 길이 제한을 넘은 경우 |

<!-- @generated:end -->

**설명** 본인이 고칠 수 있는 항목(범위가 「본인 수정」)과 볼 수만 있는 항목(범위가 「관리자만 수정」)을 함께 돌려줍니다. 「관리자만 열람」 항목은 오지 않습니다. 값 자체는 `GET /api/me` 가 주고(컬럼 항목은 최상위 키, 운영자 추가 항목은 `custom_fields`), 이 응답은 칸을 그리는 데 필요한 계약입니다. 조회 인자는 필터 `core.user.profile_fields_validation_rules` 로 늘릴 수 있습니다. `display_name` 은 표시 이름 종류 선택 칸의 묶음입니다. 조회 인자 `preview_name`, `preview_nickname`, `preview_email`(모두 선택)로 입력 중인 값을 주면 `options[].preview` 를 그 값으로 계산합니다. 운영자가 회원이 고른 종류를 허용에서 빼면 `value_unavailable` 이 true 가 되고 그동안은 회원 기본 종류로 보입니다.

`context=required` 는 추가 정보 입력 화면용입니다. 그 회원에게 비어 있는 필수 항목만 `fields` 로 오고, 그 키 목록이 `pending_required_fields` 로 함께 옵니다. 미입력이 없으면 `fields` 는 빈 배열입니다. 항목의 모양은 `profile` 과 같되 `locked_until` 이 없고, `display_name` 묶음은 오지 않습니다. 본인인증으로 채워져 잠긴 항목은 `profile` 응답에서 `readonly: true`, `locked_reason: identity` 로 옵니다.

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "fields": [
            {
                "key": "job",
                "name": "custom_fields.job",
                "type": "text",
                "renderer": "builtin",
                "label": "직업",
                "required": true,
                "readonly": false,
                "locked_reason": null,
                "options": [],
                "display_value": null
            }
        ],
        "fields_version": "55e0c42db93a",
        "pending_required_fields": ["job"]
    }
}
```


### POST /api/me/fields/{key}/file
<!-- @generated:start:api.me.fields.file.store -->
- **라우트명**: `api.me.fields.file.store`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\ProfileFieldFileController@store`
- **인증/권한**: `auth:sanctum`, `check.user_status`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| key | path | string | 예 | 항목 키 | 파일·이미지 항목의 키 |
| file | body (multipart) | file | 예 | 항목이 정한 확장자와 크기 | 올릴 파일 1개 |

**요청 예시**

```http
POST /api/me/fields/t490_doc/file HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: multipart/form-data; boundary=----g7

------g7
Content-Disposition: form-data; name="file"; filename="biz.pdf"
Content-Type: application/pdf

(파일 내용)
------g7--
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| file.id | integer | `812` | 첨부파일 ID (삭제 요청의 `attachment_id`) |
| file.hash | string | `a1b2c3d4e5f6` | 내려받기 주소에 쓰는 해시 |
| file.name | string | `biz.pdf` | 올린 파일 이름 |
| file.size | integer | `122880` | 크기(바이트) |
| file.mime_type | string | `application/pdf` | MIME 유형 |
| file.url | string | `/api/attachment/a1b2c3d4e5f6` | 내려받기 주소 |
| files[] | array | — | 그 항목에 지금 올라가 있는 파일 전체(같은 모양) |

**응답 예시**

```json
{
    "success": true,
    "message": "파일을 올렸습니다.",
    "data": {
        "file": {
            "id": 812,
            "hash": "a1b2c3d4e5f6",
            "name": "biz.pdf",
            "size": 122880,
            "mime_type": "application/pdf",
            "url": "/api/attachment/a1b2c3d4e5f6"
        },
        "files": [
            {
                "id": 812,
                "hash": "a1b2c3d4e5f6",
                "name": "biz.pdf",
                "size": 122880,
                "mime_type": "application/pdf",
                "url": "/api/attachment/a1b2c3d4e5f6"
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | 그 키의 항목이 없거나, 파일 항목이 아니거나, 꺼져 있거나, 본인 수정 범위가 아닌 경우 |
| 422 | Unprocessable Entity | 확장자나 크기가 항목의 제한에 맞지 않거나, 이미 최대 개수만큼 올라가 있는 경우(`errors.file`) |

<!-- @generated:end -->

**설명** 내 정보 수정 화면이 파일 항목에 파일을 올릴 때 부릅니다. 파일 항목은 `PUT /api/me` 의 본문으로 오가지 않고 이 API 로 바로 저장됩니다. 한 번에 한 개를 올리며, 허용 확장자, 최대 크기, 최대 개수는 운영자가 그 항목에 정한 값입니다(`GET /api/me/fields` 의 `file_rules`). 실행될 수 있는 확장자는 운영자가 적어도 받지 않습니다. 올린 파일은 본인과 회원 조회 권한이 있는 관리자만 내려받을 수 있고, 공개 프로필에 노출하는 이미지 항목만 누구나 볼 수 있습니다. 업로드 규칙은 필터 `core.user.field_file_upload_validation_rules` 로 조정할 수 있습니다.


### DELETE /api/me/fields/{key}/file
<!-- @generated:start:api.me.fields.file.destroy -->
- **라우트명**: `api.me.fields.file.destroy`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\ProfileFieldFileController@destroy`
- **인증/권한**: `auth:sanctum`, `check.user_status`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| key | path | string | 예 | 항목 키 | 파일·이미지 항목의 키 |
| attachment_id | body | integer | 예 | 1 이상 | 지울 첨부파일 ID |

**요청 예시**

```http
DELETE /api/me/fields/t490_doc/file HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{ "attachment_id": 812 }
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| files[] | array | `[]` | 지운 뒤 그 항목에 남은 파일 |

**응답 예시**

```json
{
    "success": true,
    "message": "파일을 삭제했습니다.",
    "data": {
        "files": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | 항목을 다룰 수 없거나, 그 첨부파일이 본인의 그 항목에 올린 파일이 아닌 경우 |
| 422 | Unprocessable Entity | `attachment_id` 가 없거나 숫자가 아닌 경우 |

<!-- @generated:end -->

**설명** 본인이 그 항목에 올린 파일만 지울 수 있습니다. 다른 회원의 첨부파일 ID 나 다른 항목의 첨부파일 ID 를 보내면 404 입니다.
