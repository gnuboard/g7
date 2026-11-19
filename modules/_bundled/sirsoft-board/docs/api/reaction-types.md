# Reaction Types API 레퍼런스 (sirsoft-board · 반응 유형·아이콘)

> **소유**: sirsoft-board 모듈, **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며,
> 블록 밖에 사람이 쓴 설명(상태 표, 설계 이유, 보충 표)은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 반응 유형(추천/비추천/…)에는 전용 CRUD 엔드포인트가 없습니다
2. 추가·수정·순서·삭제는 게시판 환경설정 저장(PUT admin/settings)이 한 번에 반영합니다
3. 아이콘은 이미지 업로드만 지원합니다 (SVG 제외) — 시드의 FA 클래스는 관리자가 못 고칩니다
4. 반응이 1건이라도 남은 유형은 삭제 불가, 비활성화만 가능합니다
5. 권한: sirsoft-board.settings.read(조회) · .update(편집·아이콘)
```

---

## 왜 전용 CRUD 가 없는가

유형은 **게시판 환경설정 › 기본 설정 안의 인라인 편집기**로 관리합니다 (결정 R2).
운영자가 "유형을 만들고 그 자리에서 체크해 활성화" 하는 흐름이 한 번의 저장으로 끝나야
하므로, 행 배열을 설정 저장 페이로드에 동승시키고 서버가 DB 와 맞춥니다
(sirsoft-ecommerce 의 배송유형 동기화와 같은 방식).

---

## 아이콘 표시 우선순위

| 순위 | 근거 | 비고 |
| --- | --- | --- |
| 1 | `icon_url` (업로드 이미지) | 관리자가 지정 |
| 2 | `icon_name` (시드 FA 클래스에서 추출한 `fa-*` 토큰) | 관리자 편집 불가 (결정 R4) |
| 3 | 레이아웃 기본 아이콘 (`fa-thumbs-up`) | 둘 다 없을 때 |

---

## 조회

### GET /api/modules/sirsoft-board/admin/reaction-types
<!-- @generated:start:api.modules.sirsoft-board.admin.reaction-types.index -->
- **라우트명**: `api.modules.sirsoft-board.admin.reaction-types.index`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\ReactionTypeController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.settings.read|sirsoft-board.boards.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/reaction-types HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| reaction_types | array | `[{"id":1,"code":"like","name":"추천","icon":"fas fa-thumbs-…` | 활성 반응 유형 목록 (`display_order` 순). 항목마다 `id`, `code`, `name`(현재 로케일 문자열), `icon`(원본 Font Awesome 클래스), `icon_name`(Icon 컴포넌트용 토큰), `icon_url`(업로드 아이콘 URL, 없으면 null) |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "반응 유형 목록을 조회했습니다.",
    "data": {
        "reaction_types": [
            {
                "id": 1,
                "code": "like",
                "name": "추천",
                "icon": "fas fa-thumbs-up",
                "icon_name": "fa-thumbs-up",
                "icon_url": null
            },
            {
                "id": 2,
                "code": "dislike",
                "name": "비추천1",
                "icon": "fas fa-thumbs-down",
                "icon_name": "fa-thumbs-down",
                "icon_url": null
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.settings.read\|sirsoft-board.boards.read`)이 하나도 없는 경우 (둘 중 하나면 됩니다) |
| 500 | Internal Server Error | 조회 실패 (`반응 처리에 실패했습니다.`) |

<!-- @generated:end -->

**설명**

- **인증**: `auth:sanctum` + `admin` · **권한**: `sirsoft-board.settings.read` 또는 `sirsoft-board.boards.read` (OR — 게시판 폼의 「사용 반응 유형」 선택지로도 쓰인다, 7.1.0)
- **활성 유형만** `display_order` 순으로 돌려줍니다 (게시판 폼 체크박스 옵션 소스)

**응답 필드** (`data.reaction_types[]`)

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| id | integer | 유형 식별자 (카운트 캐시의 키) |
| code | string | 불변 식별자 (`^[a-z][a-z0-9_]{1,49}$`) |
| name | string | **현재 로케일 문자열** (미입력 언어는 폴백) |
| icon | string\|null | 원본 FA 클래스 (`fas fa-thumbs-up`) |
| icon_name | string\|null | Icon 컴포넌트용 토큰 (`fa-thumbs-up`) |
| icon_url | string\|null | 업로드 아이콘 URL. 없으면 null → 화면은 `icon_name` 으로 폴백 |

`icon_name` 은 저장된 Font Awesome 클래스에서 스타일 접두사(`fas` 등)를 뗀 토큰입니다. Icon 컴포넌트가 접두사를 스스로 붙이므로 `icon` 을 그대로 넘기면 접두사가 겹칩니다. `name` 은 그 언어 값이 비어 있으면 사이트 기본 언어 값으로 대신합니다. 이 목록은 게시판 설정 화면의 「사용할 반응 유형」 체크박스 옵션 소스입니다.

> 편집기가 쓰는 **전 유형(비활성 포함)** 행은 이 API 가 아니라 설정 응답
> (`data.basic_defaults.reaction_types`)에 실립니다 — 아래를 보세요.

---

## 편집 (환경설정 저장에 동승)

### PUT /api/modules/sirsoft-board/admin/settings

- **권한**: `sirsoft-board.settings.update`
- 유형 행은 `basic_defaults.reaction_types[]` 로 보냅니다. **배열 순서가 곧 `display_order`** 입니다.

| 이름 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- |
| …reaction_types | array | 아니오 | max 50 | 행 배열. 보내지 않으면 유형을 건드리지 않습니다 |
| …[].id | integer\|null | 아니오 | 존재하는 유형 | null 이면 신규 행 |
| …[].code | string | 예 | `^[a-z][a-z0-9_]{1,49}$`, 중복 불가 | **추가 후 불변** — 기존 행의 code 변경은 무시됩니다 (결정 R12) |
| …[].name | object | 예 | 사이트 기본 언어 값 필수, 각 50자 — 게시판 유형 이름과 같은 계약. 빠지면 오류 키는 `…[].name` | 로케일별 라벨 맵 |
| …[].icon_attachment_id | integer\|null | 아니오 | `reaction_type_icon` collection + 모듈 소스의 첨부 | 업로드한 아이콘 |
| …[].display_order | integer\|null | 아니오 | min 0 | 서버가 배열 순서로 재부여합니다 |
| …[].is_active | boolean\|null | 아니오 | — | 끄면 화면에서 감추되 이력·카운트는 보존 |

**동기화 규칙**

1. 페이로드에 **없는** 기존 유형은 삭제 대상입니다.
2. 반응이 1건이라도 남아 있으면 **422** `errors.code = reaction_type_in_use` 로 거부하고,
   메시지에 유형명과 건수를 담습니다. 이때 **설정 JSON 은 저장하지 않습니다**(부분 저장 금지).
3. 삭제된 유형의 code 는 모든 게시판의 `active_reaction_types` 에서 함께 빠집니다.
4. 아이콘을 교체하면 이전 첨부는 커밋 후 삭제됩니다.
5. `reaction_types` 행 배열은 **설정 JSON 에 저장하지 않습니다** — 컨트롤러가 떼어낸 뒤
   DB 에 반영합니다. 떼지 않으면 `basic_defaults.json` 과 config 미러가 오염됩니다.

**응답**: `data.basic_defaults.reaction_types[]` 에 갱신된 전 유형 행이 실립니다.

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| name | object | 편집기는 로케일별 입력을 다루므로 **맵** 입니다 (조회 API 의 문자열과 다름) |
| icon_attachment | object\|null | `<file-uploader>` initialFiles 계약 (`{id,hash,url,download_url,…}`) |
| reaction_count | integer | 이 유형에 남은 반응 수. **0 일 때만 삭제 버튼이 열립니다** |
| is_active | boolean | 비활성 유형도 행으로 내려옵니다 (다시 켤 수 있어야 하므로) |

### GET /api/modules/sirsoft-board/admin/settings · /settings/basic_defaults

두 응답 모두 `basic_defaults.reaction_types` 를 함께 싣습니다. 설정 캐시(`getAllSettings`)
**안에는 넣지 않습니다** — 유형 행은 DB 원본이라 캐시에 얼어붙으면 편집 결과가 늦게 반영됩니다.

---

## 아이콘 업로드·삭제

### POST /api/modules/sirsoft-board/admin/reaction-types/icons
<!-- @generated:start:api.modules.sirsoft-board.admin.reaction-types.icons.upload -->
- **라우트명**: `api.modules.sirsoft-board.admin.reaction-types.icons.upload`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\ReactionTypeController@uploadIcon`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| file | body | file | 예 | 이미지 `jpg`, `jpeg`, `png`, `gif`, `webp`, max 1024 (KB) | 업로드할 아이콘 이미지 (multipart). SVG 는 받지 않습니다 |
| reaction_type_id | body | integer | 아니오 | 존재하는 유형 ID | 아이콘을 연결할 반응 유형 ID. 편집기에서 막 추가해 아직 저장하지 않은 행이면 비워 두고, 환경설정 저장 때 연결됩니다 |

**요청 예시**

```http
POST /api/modules/sirsoft-board/admin/reaction-types/icons HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: multipart/form-data; boundary=----G7ExampleBoundary

------G7ExampleBoundary
Content-Disposition: form-data; name="file"; filename="example.pdf"
Content-Type: application/octet-stream

(바이너리 파일 내용)
------G7ExampleBoundary
Content-Disposition: form-data; name="reaction_type_id"

1
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드. 코어 FileUploader 컴포넌트 계약에 맞춰 파일 메타는 `data.data` 로 한 번 더 감싸집니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| data | object | `{"id":12,"hash":"ab12cd34ef56", ...}` | 업로드된 아이콘 첨부 메타 객체 (아래 하위 필드) |
| data.id | integer | `12` | 첨부 ID. 환경설정 저장의 `reaction_types[].icon_attachment_id` 와 아이콘 삭제가 받는 값 |
| data.hash | string | `ab12cd34ef56` | 첨부 해시 식별자 |
| data.original_filename | string | `wow.png` | 업로드한 원본 파일명 |
| data.mime_type | string | `image/png` | MIME 타입 |
| data.size | integer | `2048` | 파일 크기 (바이트) |
| data.url | string | `/api/attachment/ab12cd34ef56` | 표시용 URL (`/api/attachment/{hash}`, 사이트가 하위 폴더에 설치됐으면 그 접두사가 붙습니다) |
| data.download_url | string | `/api/attachment/ab12cd34ef56` | `url` 과 같은 값 |
| data.is_image | boolean | `true` | 언제나 `true` (이미지만 받습니다) |
| data.order | integer | `1` | 언제나 `1` (아이콘은 유형당 1개) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "아이콘을 업로드했습니다.",
    "data": {
        "data": {
            "id": 12,
            "hash": "ab12cd34ef56",
            "original_filename": "wow.png",
            "mime_type": "image/png",
            "size": 2048,
            "url": "/api/attachment/ab12cd34ef56",
            "download_url": "/api/attachment/ab12cd34ef56",
            "is_image": true,
            "order": 1
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | `file` 이 없거나 이미지가 아니거나 허용 확장자가 아니거나 1MB 를 넘는 경우, `reaction_type_id` 가 없는 유형인 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 업로드 실패 (`아이콘 업로드에 실패했습니다.`) |

<!-- @generated:end -->

**설명**

- **권한**: `sirsoft-board.settings.update`

| 이름 | 위치 | 타입 | 필수 | 허용값 |
| --- | --- | --- | --- | --- |
| file | body(multipart) | file | 예 | 이미지, `jpg,jpeg,png,gif,webp`, **최대 1MB** |
| reaction_type_id | body | integer\|null | 아니오 | 존재하는 유형 |

**SVG 는 제외합니다** (결정 R13) — `/api/attachment/{hash}` 는 인증 없이 인라인
스트리밍이라, SVG 를 허용하면 스크립트가 든 파일이 사이트 오리진에서 실행될 수 있습니다.

유형이 아직 만들어지지 않은 상태(편집기에서 행을 막 추가한 직후)에도 올릴 수 있도록
`reaction_type_id` 는 선택입니다 — 저장 시 `syncTypes()` 가 연결합니다.

**응답 (201)** — 코어 `<file-uploader>` 계약대로 `data.data` 안에 파일 1건:

```json
{ "data": { "data": {
  "id": 12, "hash": "ab12cd34ef56", "original_filename": "wow.png",
  "mime_type": "image/png", "size": 2048,
  "url": "/api/attachment/ab12cd34ef56", "download_url": "/api/attachment/ab12cd34ef56",
  "is_image": true, "order": 1
} } }
```

### DELETE /api/modules/sirsoft-board/admin/reaction-types/icons/{attachmentId}
<!-- @generated:start:api.modules.sirsoft-board.admin.reaction-types.icons.destroy -->
- **라우트명**: `api.modules.sirsoft-board.admin.reaction-types.icons.destroy`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\ReactionTypeController@destroyIcon`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| attachmentId | path | integer | 예 | 숫자 | 삭제할 아이콘 첨부 ID (업로드 응답의 `data.data.id`) |

**요청 예시**

```http
DELETE /api/modules/sirsoft-board/admin/reaction-types/icons/{attachmentId} HTTP/1.1
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
    "message": "아이콘을 삭제했습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.settings.update`)이 없는 경우 |
| 404 | Not Found | 첨부가 없거나, 이 모듈이 만든 아이콘 첨부(`reaction_type_icon` 컬렉션, `sirsoft-board` 소스)가 아닌 경우 (`아이콘을 찾을 수 없습니다.`) |
| 500 | Internal Server Error | 삭제 실패 (`아이콘 삭제에 실패했습니다.`) |

<!-- @generated:end -->

**설명**

- **권한**: `sirsoft-board.settings.update`
- 이 모듈이 만든 아이콘 첨부(`collection = reaction_type_icon` + 모듈 소스)만 지웁니다.
  다른 collection·소스면 **404** — 이 엔드포인트로 남의 첨부를 지울 수 없습니다.
- 삭제하면 그 첨부를 쓰던 유형의 `icon_attachment_id` 가 풀려 FA 폴백으로 되돌아갑니다.

---

## 기본값과 시드

기본 유형(추천·비추천)의 단일 원천은 `src/Support/ReactionTypeDefaults` 입니다.
시더가 여기서 읽고, 1.0.3 업그레이드 백필 상수는 이미 실행된 설치본의 재현성을 위해
그 시점 값으로 **동결**돼 있으며, 둘의 드리프트는 단위 테스트가 감시합니다.

관리자가 끄거나 순서를 바꾼 유형은 시더 재실행(`module:update`)이 되돌리지 않습니다 —
`ReactionType::$trackableFields` 에 `is_active`·`display_order` 가 들어 있기 때문입니다.
