# Reaction Types API 레퍼런스 (sirsoft-board · 반응 유형·아이콘)

> **소유**: sirsoft-board 모듈 · 이 문서는 **손으로 작성**했습니다. `api:docgen` 의 파괴적 재생성
> 가드(`docs/backend/api-documentation.md`)를 따라 스캐폴딩 덮어쓰기를 하지 않았습니다.
> 값은 개발 환경에서 실제 호출해 관측한 것입니다.

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
