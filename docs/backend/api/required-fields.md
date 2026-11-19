# Required Fields API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 비어 있는 필수 항목을 채우는 추가 정보 입력 화면의 저장 엔드포인트 레퍼런스입니다
2. PUT /api/me/required-fields — 그 회원에게 비어 있는 필수 항목만 받고 나머지 칸은 저장하지 않습니다
3. 미입력이 남은 회원의 쓰기 요청은 403 required_fields_pending 으로 거부됩니다(통과 대상은 설명 참고)
4. 받을 항목과 폼 경로는 GET /api/me/fields?context=required (fields.md)
5. 재확인(비밀번호 확인)을 요구하지 않습니다 — 기존 값을 바꾸지 못하기 때문입니다
```

---


### PUT /api/me/required-fields
<!-- @generated:start:api.me.required-fields.update -->
- **라우트명**: `api.me.required-fields.update`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\ProfileController@completeRequiredFields`
- **인증/권한**: `auth:sanctum`, `check.user_status`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| {항목의 폼 경로} | body | 항목 유형에 따름 | 예 | 항목 설정에 따름 | 비어 있는 필수 항목의 값. 폼 경로는 `GET /api/me/fields?context=required` 응답의 `fields[].name` 입니다(기본 항목은 `mobile` 같은 최상위 키, 운영자 추가 항목은 `custom_fields.{키}`) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.user.complete_required_fields_validation_rules`).

**요청 예시**

```http
PUT /api/me/required-fields HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json
Authorization: Bearer {YOUR_TOKEN}

{
    "mobile": "010-1234-5678",
    "custom_fields": {
        "job": "직장인"
    }
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| user | object | — | 저장 뒤의 본인 정보. 모양은 `GET /api/auth/user` 와 같습니다 |
| pending_required_fields | array | `[]` | 저장 뒤에도 비어 있는 필수 항목 키. 빈 배열이면 화면은 원래 가려던 곳으로 보냅니다. 파일, 이미지 항목처럼 이 요청으로 채우지 못하는 항목이 남으면 그 키가 옵니다 |

**응답 예시**

```json
{
    "success": true,
    "message": "추가 정보를 저장했습니다.",
    "data": {
        "user": {
            "uuid": "0195f3a2-…",
            "name": "홍길동",
            "pending_required_fields": []
        },
        "pending_required_fields": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 비어 있는 필수 항목을 비워 보냈거나 값이 항목의 형식에 맞지 않는 경우. 오류 키는 항목의 폼 경로입니다 |

<!-- @generated:end -->

**설명** 추가 정보 입력 화면의 저장입니다. 받는 것은 그 회원에게 비어 있는 필수 항목뿐입니다. 이미 값이 있는 항목, 필수가 아닌 항목, 이메일이나 비밀번호 같은 그 밖의 칸은 보내도 저장하지 않습니다. 기존 값을 바꾸지 못하므로 재확인(비밀번호 확인)을 요구하지 않습니다. 필수 항목을 건너뛰고 만들어진 회원(외부 계정으로 만들어진 회원 등)이 이 저장으로 미입력을 모두 채우면 그 뒤로는 보통 회원과 같은 기준(「기존 회원에게도 입력 요구」를 켠 항목만)으로 판정합니다.

비어 있는 필수 항목이 있는 회원의 쓰기 요청(POST, PUT, PATCH, DELETE)은 관리자 API 와 아래 통과 대상을 뺀 모든 API 에서 `403` 으로 거부됩니다.

```json
{
    "success": false,
    "message": "추가 정보를 입력해야 이용할 수 있습니다.",
    "error_code": "required_fields_pending",
    "data": {
        "pending_required_fields": ["job"],
        "fields": [
            { "key": "job", "label": "직업" }
        ]
    }
}
```

통과 대상은 로그아웃, 토큰 갱신, `/api/me` 아래의 모든 요청(내 정보 수정, 이 저장, 파일 항목, 비밀번호, 재확인, 탈퇴, 아바타, 검색 기록), `/api/user/profile` 의 수정과 언어 변경, 알림 읽음과 삭제, 본인인증(`/api/identity/*`), 그리고 확장이 필터 `core.required_fields.filter_exempt_routes` 로 보탠 라우트입니다. 읽기 요청(GET)은 막지 않습니다. 본인 응답(`GET /api/auth/user`, `GET /api/user/auth/user`, `GET /api/admin/auth/user`)의 `pending_required_fields` 와, 로그인을 마친 응답(`POST /api/auth/login`, 2단계 인증 확인, 로그인 인계, 비밀번호 변경 단계 완료와 연기)의 `data.pending_required_fields` 가 비어 있지 않으면 화면이 추가 정보 입력 화면으로 보냅니다. 값은 항목 키 배열입니다.
