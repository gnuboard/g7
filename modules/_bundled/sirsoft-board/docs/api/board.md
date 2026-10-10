# Board API 레퍼런스

> **소유**: module `sirsoft-board` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Board 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---

## 비밀글 서버측 게이팅 (KVE-2026-1914)

비밀글(`is_secret`)의 원문은 작성자 본인 또는 게시판 관리 권한(`posts.read-secret`/`manager`)을 가진 요청에만 제공됩니다. 판정은 `SecretContentGate`(SSoT)가 담당하며 게시글 상세 외 다음 경로에도 동일하게 적용됩니다.

- **댓글 목록**(`GET .../posts/{postId}/comments`): 부모 게시글이 비밀글이고 열람 권한이 없으면 빈 목록(`200`)을 반환합니다.
- **첨부 서빙**(`GET .../attachment/{hash}`, `.../attachment/{hash}/preview`): 부모 게시글이 비밀글이고 열람 권한이 없으면 `403`. 첨부 요청은 상세와 분리된 요청이라 비밀번호 검증(`password_verified`)은 적용되지 않으며 작성자/관리 권한만 인정합니다.
- **상세/목록 응답**: 비열람자에게 `content`·`title`·`reply`·`attachments`가 마스킹됩니다.

---

## 목록·검색의 총 건수와 답변·댓글 상한

게시판 목록에 `search` 를 얹으면 내부 검색이 수행됩니다. 매칭이 아주 많을 수 있으므로 총
건수는 상한까지만 세며, 상한을 넘으면 응답의 `pagination` 에 정확도가 함께 실립니다
(`total_relation` / `total_is_exact` / `result_cap`). 이때 `last_page` 는 `null` 이고
`has_more_pages` 는 그대로 정확하므로, 마지막 페이지 점프만 감춰지고 다음 페이지 이동은
끝까지 열려 있습니다. 상세 규약은 [pagination.md](../../../../../docs/backend/pagination.md) 를 참고하세요.

검색어에 `+` `-` `*` `"` `<` `>` 같은 문자가 들어와도 오류가 나지 않습니다. 코어 정제기가
FULLTEXT 연산자를 제거한 뒤 검색하며, 연산자만 입력한 경우에는 오류 대신 빈 결과를 돌려줍니다.

게시글 상세 응답의 답변 트리와 댓글 목록에도 같은 상한이 적용됩니다. 한 글에 답변·댓글이
극단적으로 많은 경우 그 지점에서 끊기며, 총 건수는 목록 응답의 집계로 확인할 수 있습니다.

---


### POST /api/modules/sirsoft-board/admin/board/{slug}/attachments
<!-- @generated:start:api.modules.sirsoft-board.admin.board.attachments.upload -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.attachments.upload`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\AttachmentController@upload`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.attachments.upload`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| file | body | file | 예 | — | 업로드 파일 |
| post_id | body | integer | 아니오 | min 1 | post 식별자 |
| collection | body | string | 아니오 | max 100 | 첨부 컬렉션 그룹명 (첨부를 용도별로 묶는 키, 미지정 시 default) |
| temp_key | body | string | 아니오 | UUID | 게시글 작성 전 임시 업로드 세션 키(화면이 `$uuid()` 로 발급, `post_id` 와 함께 생략하면 서버가 UUID 를 발급). `post_id`가 없을 때 이 키로 첨부를 임시 보관했다가 게시글 저장 시점에 연결합니다 (쿼리스트링으로 보내면 body로 병합). |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-board.attachment.upload_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-board/admin/board/{slug}/attachments HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: multipart/form-data; boundary=----G7ExampleBoundary

------G7ExampleBoundary
Content-Disposition: form-data; name="file"; filename="example.pdf"
Content-Type: application/octet-stream

(바이너리 파일 내용)
------G7ExampleBoundary
Content-Disposition: form-data; name="post_id"

1
------G7ExampleBoundary
Content-Disposition: form-data; name="collection"

예시값
------G7ExampleBoundary
Content-Disposition: form-data; name="temp_key"

5f0c3a3e-1b2c-4d5e-8f90-a1b2c3d4e5f6
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드. FileUploader 컴포넌트 호환을 위해 파일 메타는 `data.data` 로 한 번 더 감싸집니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| data | object | `{"id":1,"hash":"apidocsmpl1", ...}` | 업로드된 첨부파일 메타 객체 (아래 하위 필드) |
| data.id | integer | `1` | 첨부파일 기본 키 (게시판별 첨부 테이블의 내부 식별자) |
| data.hash | string | `apidocsmpl1` | 첨부파일 해시 식별자 (다운로드/미리보기 URL 에 사용) |
| data.original_filename | string | `apidoc-sample.png` | 사용자가 업로드한 원본 파일명 |
| data.stored_filename | string | `—` | 스토리지에 실제 저장된 파일명 (충돌 방지용 내부 파일명) |
| data.mime_type | string | `image/png` | 업로드 파일의 MIME 타입 |
| data.size | integer | `2048` | 파일 크기 (바이트) |
| data.url | string | `/api/modules/sirsoft-board/boards/apidoc-sample-board/attachment/apidocsmpl1` | 첨부파일 접근 URL (`AttachmentService::getUrl()` 산물 — 비밀글·삭제글 게이트가 걸린 다운로드 서빙 URL. 공개 자산 디스크를 켜도 직접 URL 로 바뀌지 않는다, 7.0.11+) |
| data.order | integer | `0` | 첨부파일 표시 순서 (0 부터 오름차순) |
| data.created_at | string | `2026-07-08 10:41:34` | 업로드(생성) 일시 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "파일이 업로드되었습니다.",
    "data": {
        "data": {
            "id": 1,
            "hash": "apidocsmpl1",
            "original_filename": "apidoc-sample.png",
            "stored_filename": "apidocsmpl1.png",
            "mime_type": "image/png",
            "size": 2048,
            "url": "/api/modules/sirsoft-board/boards/apidoc-sample-board/attachment/apidocsmpl1",
            "order": 0,
            "created_at": "2026-07-08 10:41:34"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.attachments.upload`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 (슬러그에 해당하는 게시판 없음 — `게시판을 찾을 수 없습니다.`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 업로드 처리 실패 (`파일 업로드에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 게시판 관리자가 게시글 첨부파일 1건을 업로드합니다. `auth:sanctum` + admin + 게시판별 `attachments.upload` 권한이 필요하며, `AttachmentService::upload()`가 게시판별 동적 첨부 테이블에 저장합니다. 파일 1개당 용량 상한은 게시판의 `max_file_size`, 비어 있으면 게시판 환경설정 기본값, 그것도 없으면 10MB 순으로 정해지며 게시글 저장 검증과 같은 기준입니다. `post_id`가 있으면 해당 게시글에 즉시 귀속되고, 없으면 `temp_key`로 임시 업로드되어 게시글 작성/수정 저장 시점에 연결됩니다. 응답은 FileUploader 컴포넌트 호환을 위해 `data.data`로 한 번 더 감싸 파일 메타(hash·url·order 등)를 반환합니다.

첨부 개수는 게시판 설정 `max_file_count` 를 기준으로 **직접 업로드 · 미리 올려 둔 파일 연결(`attachment_ids`) · 임시 업로드 연결(`temp_key`) · 이미 연결된 첨부** 를 모두 합산해 판정합니다. 상한을 넘으면 `422`(`errors.code = attachment_limit_exceeded`)를 반환하며, 이 판정은 업로드 엔드포인트뿐 아니라 게시글 생성·수정 저장 시점에도 동일하게 적용됩니다. `max_file_count` 가 0 이면 개수를 제한하지 않습니다.


### GET /api/modules/sirsoft-board/admin/board/{slug}/attachments/download/{hash}
<!-- @generated:start:api.modules.sirsoft-board.admin.board.attachments.download -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.attachments.download`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\AttachmentController@download`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.attachments.download`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| hash | path | string | 예 | — | 대상 리소스의 해시 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/board/{slug}/attachments/download/{hash} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 봉투를 반환하지 않습니다. 성공 시 파일 본문을 그대로 내려주는 `StreamedResponse`(바이너리 스트림)이며, `data` 구조가 없습니다. 실패 시에만 JSON 에러 봉투가 반환됩니다._

**응답 예시**

```http
HTTP/1.1 200
Content-Type: image/png
Content-Disposition: attachment; filename="apidoc-sample.png"

(바이너리 파일 내용)
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.attachments.download`)이 없는 경우 |
| 404 | Not Found | 슬러그에 해당하는 게시판이 없거나(`게시판을 찾을 수 없습니다.`), 해시에 해당하는 첨부가 없거나(`파일을 찾을 수 없습니다.`), 실제 파일이 스토리지에 없는 경우 |
| 500 | Internal Server Error | 다운로드 처리 실패 (`파일 다운로드에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 게시판 관리자가 첨부파일을 해시로 조회해 다운로드합니다. `auth:sanctum` + admin + 게시판별 `attachments.download` 권한이 필요하며, `AttachmentService::getByHash()`로 대상을 찾은 뒤 `download()`가 파일 스트림 응답을 생성합니다. 해시에 해당하는 첨부가 없거나 실제 파일이 없으면 404를 반환하고, JSON이 아닌 `StreamedResponse`로 파일 본문을 직접 전송합니다.


### PATCH /api/modules/sirsoft-board/admin/board/{slug}/attachments/reorder
<!-- @generated:start:api.modules.sirsoft-board.admin.board.attachments.reorder -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.attachments.reorder`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\AttachmentController@reorder`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.attachments.upload`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| order | body | array | 예 | min 1 | 첨부파일 순서 배열. FileUploader가 보내는 `[{id, order}]` 형태로, 각 원소의 `id`(첨부 ID)와 `order`(0 이상 정수)를 담아 표시 순서를 지정합니다. |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-board.attachment.reorder_validation_rules`).

**요청 예시**

```http
PATCH /api/modules/sirsoft-board/admin/board/{slug}/attachments/reorder HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "order": [
        "예시값"
    ]
}
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
    "message": "첨부파일 순서가 변경되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.attachments.upload`)이 없는 경우 |
| 404 | Not Found | 슬러그에 해당하는 게시판이 없는 경우 (`게시판을 찾을 수 없습니다.`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 순서 변경 처리 실패 (`첨부파일 순서 변경에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 게시판 관리자가 게시글 첨부파일들의 표시 순서를 일괄 변경합니다. `auth:sanctum` + admin + 게시판별 `attachments.upload` 권한이 필요하며, FileUploader가 보낸 `[{id, order}]` 배열을 `[ID => order]` 매핑으로 변환해 `AttachmentService::reorder()`가 게시판별 첨부 테이블의 order 값을 갱신합니다. 대상 첨부를 하나씩 찾아 담당 범위를 확인하고, 범위 밖 첨부가 하나라도 섞이면 요청 전체를 403 으로 거부합니다. 아직 글에 붙지 않은 임시 첨부(저장이 실패한 글쓰기 화면에 남은 첨부)도 그 게시판에서 올린 것이면 대상이며, 임시 첨부는 올린 사람만 다룰 수 있습니다(남의 임시 첨부는 403).


### DELETE /api/modules/sirsoft-board/admin/board/{slug}/attachments/{id}
<!-- @generated:start:api.modules.sirsoft-board.admin.board.attachments.destroy -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.attachments.destroy`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\AttachmentController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.attachments.upload`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-board/admin/board/{slug}/attachments/{id} HTTP/1.1
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
    "message": "파일이 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.attachments.upload`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시판 관리자가 첨부파일 1건을 삭제합니다. `auth:sanctum` + admin + 게시판별 `attachments.upload` 권한이 필요하며, `AttachmentService::getById()`로 대상 존재를 확인한 뒤 `delete()`가 게시판별 첨부 테이블 레코드와 실제 파일을 함께 제거합니다. 첨부가 없으면 404, 삭제 실패 시 500을 반환합니다. 아직 글에 붙지 않은 임시 첨부(저장이 실패한 글쓰기 화면에 남은 첨부)도 그 게시판에서 올린 것이면 대상이며, 임시 첨부는 올린 사람만 다룰 수 있습니다(남의 임시 첨부는 403). 실제 파일은 지우지 않고 소프트 삭제합니다.


### GET /api/modules/sirsoft-board/admin/board/{slug}/posts
<!-- @generated:start:api.modules.sirsoft-board.admin.board.posts.index -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.posts.index`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\PostController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.posts.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| search | query | string | 아니오 | max 255 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/board/{slug}/posts?page=1&per_page=1&search=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `237` | 기본 키 (내부 식별자) |
| category | null | `null` | 게시글 분류(카테고리) 문자열. 게시판이 카테고리를 쓰지 않거나 미지정 시 null (최대 50자). |
| author | object | `{"uuid":"a231747f-e82e-4cf2-9ae1-a261849dce40","name":"AP…` | 작성자 사용자 객체 (uuid/name — author 관계 파생) |
| is_notice | boolean | `false` | notice 여부 |
| is_secret | boolean | `false` | secret 여부 |
| content_mode | string | `html` | 본문 편집 모드. `html`(위지윅/HTML) 또는 `text`(평문)이며, 요약·썸네일 추출과 렌더링 방식을 결정합니다. 미지정 시 `text`. |
| is_new | boolean | `true` | new 여부 |
| status | string | `published` | 게시글 상태 코드. `published`(게시됨) / `blinded`(블라인드) / `deleted`(삭제됨) 중 하나이며, `status_label`이 사람이 읽는 라벨입니다. |
| status_label | string | `게시됨` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| view_count | integer | `43` | view 개수 (집계) |
| comment_count | integer | `0` | comment 개수 (집계) |
| reply_count | integer | `0` | reply 개수 (집계) |
| attachment_count | integer | `0` | attachment 개수 (집계). 블라인드 글은 작성자·게시판 관리자가 아니면 `0` (첨부 목록·서빙과 같은 기준) |
| reaction_total | integer | `5` | 게시판이 켠 활성 반응 유형의 개수 합계 (1.2.0+). 관리자 목록의 「반응」 열이 읽는다. 게시판이 반응을 껐거나 활성 유형이 없으면 **키 자체가 실리지 않는다** |
| has_attachment | boolean | `false` | attachment 여부. 블라인드 글은 작성자·게시판 관리자가 아니면 `false` |
| thumbnail | string | `/api/modules/sirsoft-board/boards/api…` | 썸네일 이미지 URL/경로 — `/api/modules/sirsoft-board/boards/{slug}/attachment/{hash}/preview` 형식 (첫 이미지 첨부의 미리보기 서빙 URL). 이미지 첨부가 없으면 본문 첫 내부 이미지 URL 로 폴백한다(외부 주소 이미지는 제외 — 1.1.0+). 비밀글은 열람 권한이 없으면 `null` 로 내려간다(첨부 해시·본문 이미지 URL 노출 차단 — 필드 자체는 유지). 블라인드 글도 작성자·게시판 관리자가 아니면 `null`. 비밀글·블라인드 글을 볼 수 있는 사람에게는 `<img>` 요청에 토큰이 실리지 않으므로 한시 서명 주소(`?expires=…&signature=…`)로 내려간다 |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가) |
| is_reply | boolean | `false` | reply 여부 |
| created_at | string | `2026-07-07 09:34:50` | 생성 일시 |
| created_at_formatted | string | `4시간 전` | `created_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| is_author | boolean | `true` | author 여부 |
| is_guest_post | boolean | `false` | guest post 여부 |
| slug | string | `apidoc-sample-board` | 게시판 슬러그 (URL/테이블명) |
| title | string | `API 문서 샘플 게시글` | 제목 |
| deleted_at | null | `null` | 소프트 삭제 일시 (미삭제 시 null) |
| content_preview | string | `API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.` | 목록용 본문 요약(태그 제거 후 앞 150자). 블라인드·비밀글은 원문 유출 방지를 위해 권한과 무관하게 빈 문자열을 반환합니다. |
| row_type | string | `normal` | 목록 행 유형. `notice`(공지) / `reply`(답변글) / `normal`(일반) 중 하나로, 목록 렌더링 시 행 스타일과 순번 표시를 분기합니다. |
| number | integer | `1` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| show_category | boolean | `false` | 목록에 카테고리 열을 노출할지 여부. 게시판 설정(`show_category`)에서 파생되어 각 행에 부여됩니다. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시글 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 1,
                "category": null,
                "author": {
                    "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                    "name": "API 문서 샘플 사용자",
                    "email": "apidoc-sample-user@example.com",
                    "avatar": null,
                    "status": "active",
                    "status_label": "활성",
                    "is_guest": false
                },
                "is_notice": false,
                "is_secret": "{MASKED}",
                "content_mode": "html",
                "is_new": true,
                "status": "published",
                "status_label": "게시됨",
                "view_count": 43,
                "comment_count": 0,
                "reply_count": 0,
                "attachment_count": 0,
                "has_attachment": false,
                "thumbnail": "/api/modules/sirsoft-board/boards/apidoc-sample-board/attachment/apidocsmpl1/preview",
                "parent_id": null,
                "depth": 0,
                "is_reply": false,
                "created_at": "2026-07-08 10:41:34",
                "created_at_formatted": "4시간 전",
                "is_author": true,
                "is_guest_post": false,
                "slug": "apidoc-sample-board",
                "title": "API 문서 샘플 게시글",
                "deleted_at": null,
                "content_preview": "API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.",
                "row_type": "normal",
                "number": 1,
                "show_category": false
            }
        ],
        "pagination": {
            "total": 1,
            "all_total": 1,
            "count": 1,
            "per_page": 25,
            "current_page": 1,
            "last_page": 1,
            "from": 1,
            "to": 1,
            "has_more_pages": false
        },
        "board": {
            "slug": "apidoc-sample-board",
            "name": "API 문서 샘플 게시판",
            "type": "basic",
            "categories": [],
            "show_category": false,
            "settings": {
                "use_file_upload": true,
                "use_comment": true,
                "use_reply": true,
                "use_report": true,
                "use_scrap": true,
                "secret_mode": "{MASKED}",
                "per_page": 20,
                "per_page_mobile": 15,
                "order_by": "created_at",
                "order_direction": "DESC"
            }
        },
        "abilities": {
            "can_read": true,
            "can_write": true,
            "can_read_secret": "{MASKED}",
            "can_read_comments": true,
            "can_write_comments": true,
            "can_upload": true,
            "can_download": true,
            "can_manage": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.posts.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 게시판 관리자가 특정 게시판의 게시글 목록을 조회합니다. `auth:sanctum` + admin + 게시판별 `posts.read` 권한이 필요하며, 요청 파라미터로 검색·상태·정렬·페이지네이션이 적용됩니다(`PostService::buildListParams`). 추가로 `admin.manage` 권한이 있으면 소프트 삭제된 게시글까지 포함해 조회하며, 응답에는 공지 고정 처리 후의 일반 게시글 총 건수(캐시 기반)와 관리자용 게시판 정보(`boardInfo`)가 함께 담깁니다. 검색(`filters[0][field]`·`filters[0][value]`)은 원글뿐 아니라 답글의 제목·내용·작성자도 찾습니다. 목록은 원글 한 줄 아래 그 답글 줄을 이어 그리고 페이지·총 건수를 원글 단위로 세므로, 답글이 걸리면 그 답글이 달린 맨 위 원글이 결과가 되고 원글 아래 답글 줄(걸린 답글 포함)이 함께 옵니다. 총 건수와 페이지는 같은 원글 기준으로 셉니다(공개 목록은 종전처럼 원글만 검색).


### POST /api/modules/sirsoft-board/admin/board/{slug}/posts
<!-- @generated:start:api.modules.sirsoft-board.admin.board.posts.store -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.posts.store`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\PostController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.posts.write`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-board.post.store_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-board/admin/board/{slug}/posts HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`PostResource` — 게시글 수정(PUT) 응답과 동일한 구조)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| category | null | `null` | 게시글 분류(카테고리) 문자열. 게시판이 카테고리를 쓰지 않거나 미지정 시 null (최대 50자). |
| author | object | `{"uuid":"a234c2b1-…","name":"API 문서 샘플 사용자"}` | 작성자 사용자 객체 (uuid/name — author 관계 파생) |
| is_notice | boolean | `false` | 공지 게시글 여부 |
| is_secret | boolean | `false` | 비밀글 여부 |
| content_mode | string | `html` | 본문 편집 모드. `html`(위지윅/HTML) 또는 `text`(평문). |
| is_new | boolean | `true` | 신규(NEW) 표시 대상 여부 (게시판 `new_display_hours` 기준) |
| status | string | `published` | 게시글 상태 코드. `published` / `blinded` / `deleted` 중 하나. |
| status_label | string | `게시됨` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| view_count | integer | `0` | 조회수 (집계) |
| comment_count | integer | `0` | 댓글 수 (집계) |
| reply_count | integer | `0` | 답변글 수 (집계) |
| attachment_count | integer | `0` | 첨부파일 수 (집계). 블라인드 글은 작성자·게시판 관리자가 아니면 `0` |
| has_attachment | boolean | `false` | 첨부파일 보유 여부. 블라인드 글은 작성자·게시판 관리자가 아니면 `false` |
| thumbnail | null | `null` | 썸네일 이미지 URL/경로 — 첫 이미지 첨부의 미리보기 서빙 URL. 이미지 첨부가 없으면 본문 첫 내부 이미지 URL 로 폴백한다(외부 주소 이미지는 제외 — 1.1.0+). 비밀글은 열람 권한이 없으면 `null`(블라인드 글은 작성자·게시판 관리자가 아니면 `null`). 볼 수 있는 사람에게는 한시 서명 주소 |
| parent_id | null | `null` | 원글 ID (답변글일 때만 값 존재) |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위) |
| is_reply | boolean | `false` | 답변글 여부 |
| created_at | string | `2026-07-08 10:41:34` | 생성 일시 |
| created_at_formatted | string | `방금 전` | `created_at` 값의 표시용 포맷 문자열 |
| is_author | boolean | `true` | 현재 사용자가 작성자인지 여부 |
| is_guest_post | boolean | `false` | 비회원 작성 게시글 여부 |
| title | string | `API 문서 샘플 게시글` | 제목 |
| content | string | `<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>` | 본문 내용 |
| user_id | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | 작성자 사용자 식별자 |
| trigger_type | string | `user` | 상태 변경을 유발한 주체 (`report`/`admin`/`system`/`auto_hide`/`user`/`cascade`) |
| updated_at | string | `2026-07-08 10:41:34` | 최종 수정 일시 |
| deleted_at | null | `null` | 소프트 삭제 일시 (미삭제 시 null) |
| ip_address | string\|null | `127.0.0.1` | 작성 요청이 발생한 IP 주소 (보유기간이 지나 파기되면 null — 게시판 개인정보 보유기간, 이슈 #636) |
| action_logs | array | `[]` | 블라인드/복원/삭제 처리 이력 목록. `admin.manage` 권한 보유자에게만 노출. |
| board | null | `null` | 소속 게시판 정보 객체. board 관계 미로드 시 null. |
| navigation | null | `null` | 이전/다음 게시글 이동 정보. 쓰기 응답에서는 계산하지 않아 null. |
| parent | null | `null` | 원글 객체 (parent 관계 파생) |
| comments | null | `null` | 댓글 목록. comments 관계 미로드 시 null. |
| attachments | null | `null` | 첨부파일 목록. attachments 관계 미로드 시 null. |
| replies | null | `null` | 답변글 목록. replies 관계 미로드 시 null. |
| is_already_reported | boolean | `false` | 현재 사용자가 이미 신고한 게시글인지 여부 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_write":true,…}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "게시글이 등록되었습니다.",
    "data": {
        "id": 1,
        "category": null,
        "author": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "avatar": null,
            "status": "active",
            "status_label": "활성",
            "is_guest": false
        },
        "is_notice": false,
        "is_secret": false,
        "content_mode": "html",
        "is_new": true,
        "status": "published",
        "status_label": "게시됨",
        "view_count": 0,
        "comment_count": 0,
        "reply_count": 0,
        "attachment_count": 0,
        "has_attachment": false,
        "thumbnail": null,
        "parent_id": null,
        "depth": 0,
        "is_reply": false,
        "created_at": "2026-07-08 10:41:34",
        "created_at_formatted": "방금 전",
        "is_author": true,
        "is_guest_post": false,
        "title": "API 문서 샘플 게시글",
        "content": "<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>",
        "user_id": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "trigger_type": "user",
        "updated_at": "2026-07-08 10:41:34",
        "deleted_at": null,
        "ip_address": "127.0.0.1",
        "action_logs": [],
        "board": null,
        "navigation": null,
        "parent": null,
        "comments": null,
        "attachments": null,
        "replies": null,
        "is_already_reported": false,
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_write": true,
            "can_read_secret": true,
            "can_read_comments": true,
            "can_write_comments": true,
            "can_upload": true,
            "can_download": true,
            "can_manage": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.posts.write`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). 제목/본문 길이, 금지 키워드, 작성 쿨다운, 원글(`parent_id`) 유효성 위반 포함 |
| 500 | Internal Server Error | 게시글 생성 처리 실패 (`게시글 등록에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 게시판 관리자가 새 게시글을 작성합니다. `auth:sanctum` + admin + 게시판별 `posts.write` 권한이 필요하며, `StorePostRequest` 검증을 거친 값에 작성자(`Auth::id()`)와 요청 IP가 자동으로 채워집니다. 업로드 파일과 첨부파일 ID 배열은 본문에서 분리되어 `PostService::createPost()`로 전달되고, 성공 시 생성된 게시글 리소스를 201로 반환합니다. `is_notice=true` 는 게시판 관리 권한(`admin.manage`/`manager`) 또는 공지 등록 권한(`posts.write-notice`)이 있어야 합니다(사용자 글쓰기 경로와 같은 판정) — 없으면 403(`messages.permission.notice_denied`, 7.1.0).


### GET /api/modules/sirsoft-board/admin/board/{slug}/posts/form-data
<!-- @generated:start:api.modules.sirsoft-board.admin.board.posts.form-data -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.posts.form-data`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\PostController@getFormData`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.posts.write`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| post_id | query | integer | 아니오 | min 1 | post 식별자 |
| parent_id | query | integer | 아니오 | min 1 | parent 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/board/{slug}/posts/form-data?post_id=1&parent_id=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| title | string | `` | 제목 |
| content | string | `` | 본문 내용 |
| content_mode | string | `text` | 본문 편집 모드. `html`(위지윅/HTML) 또는 `text`(평문)이며, 폼 초기값은 `text`입니다. |
| category | null | `null` | 게시글 분류(카테고리) 문자열. 게시판이 카테고리를 쓰지 않거나 미지정 시 null (최대 50자). |
| is_notice | boolean | `false` | notice 여부 |
| is_secret | boolean | `false` | secret 여부 |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시글 폼 데이터를 조회했습니다.",
    "data": {
        "title": "",
        "content": "",
        "content_mode": "text",
        "category": null,
        "is_notice": false,
        "is_secret": "{MASKED}",
        "parent_id": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.posts.write`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). 제목/본문 길이, 금지 키워드, 작성 쿨다운, 원글(`parent_id`) 유효성 위반 포함 |

<!-- @generated:end -->

**설명** 게시글 작성/수정/답변글 폼에 미리 채울 입력 데이터를 반환합니다. `auth:sanctum` + admin + 게시판별 `posts.write` 권한이 필요하며, 쿼리 파라미터에 따라 분기합니다. `post_id`가 있으면 기존 게시글 데이터(수정 모드), `parent_id`가 있으면 제목에 `Re:`를 붙이고 원글 카테고리·비밀글 여부를 물려받은 답변글 기본값(답글 허용 게시판만, 아니면 404), 둘 다 없으면 빈 폼(게시판 `secret_mode`가 `always`면 비밀글 기본값)을 돌려줍니다.


### GET /api/modules/sirsoft-board/admin/board/{slug}/posts/form-meta
<!-- @generated:start:api.modules.sirsoft-board.admin.board.posts.form-meta -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.posts.form-meta`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\PostController@getFormMeta`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.posts.write`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/board/{slug}/posts/form-meta HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| board | object | `{"id":12,"name":"API 문서 샘플 게시판","slug":"apidoc-sample-boa…` | 폼 화면 표시에 필요한 게시판 정보 객체(이름·슬러그·댓글/답글/비밀글 설정 등). 사용자 권한(abilities)과 함께 항상 포함됩니다. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시글 폼 메타 데이터를 조회했습니다.",
    "data": {
        "board": {
            "id": 1,
            "name": "API 문서 샘플 게시판",
            "slug": "apidoc-sample-board",
            "is_active": true,
            "type": "basic",
            "description": "",
            "per_page": 20,
            "per_page_mobile": 15,
            "order_by": "created_at",
            "order_direction": "DESC",
            "categories": [],
            "show_view_count": true,
            "secret_mode": "{MASKED}",
            "use_comment": true,
            "use_reply": true,
            "max_reply_depth": 5,
            "use_report": true,
            "use_scrap": true,
            "comment_order": "ASC",
            "max_comment_depth": 10,
            "min_title_length": 2,
            "max_title_length": 200,
            "min_content_length": 10,
            "max_content_length": 10000,
            "min_comment_length": 2,
            "max_comment_length": 1000,
            "blocked_keywords": [],
            "use_file_upload": true,
            "max_file_size": 10,
            "max_file_count": 5,
            "allowed_extensions": [
                "jpg",
                "jpeg",
                "png",
                "gif",
                "pdf",
                "zip"
            ],
            "add_to_menu": null,
            "new_display_hours": 24,
            "board_managers": [],
            "board_steps": [],
            "board_manager_ids": [],
            "board_step_ids": [],
            "notify_author": true,
            "notify_admin_on_post": true,
            "created_at": "2026-07-08 10:41:34",
            "updated_at": "2026-07-08 10:41:34",
            "permissions": null,
            "category_post_counts": null,
            "posts_count": 0,
            "user_abilities": {
                "can_read": true,
                "can_write": true,
                "can_read_secret": "{MASKED}",
                "can_read_comments": true,
                "can_write_comments": true,
                "can_upload": true,
                "can_download": true,
                "can_manage": true
            },
            "abilities": {
                "can_create": true,
                "can_update": true,
                "can_delete": true
            }
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.posts.write`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시글 폼 화면 표시용 메타 데이터(읽기 전용)를 반환합니다. `auth:sanctum` + admin + 게시판별 `posts.write` 권한이 필요하며, 게시판 정보와 사용자 권한(abilities)을 항상 포함합니다. `board.max_file_size` 는 게시판 값이 비어 있으면(기본값 따름) 게시판 환경설정 기본값, 그것도 없으면 10(MB)으로 채워 첨부 업로드 검증의 용량 상한과 같은 값을 내려 줍니다. `post_id`가 있으면 작성자·작성일·첨부파일과 원글 정보를 덧붙이고(수정 모드), `parent_id`가 있으면 원글 정보를 포함하되 블라인드/삭제된 원글에는 답글 작성이 차단됩니다(각각 403). 수정 모드(`post_id`)에서는 `can_modify` 를 싣습니다 — 저장(PUT)과 같은 판정(관리 권한이거나 글쓰기 권한 + 본인 글)으로, false 면 화면이 폼을 읽기 전용으로 엽니다(7.1.0). `board.user_abilities.can_write_notice` 는 글 등록·수정 API 의 공지 판정(게시판 관리 권한 `admin.manage`/`manager` 또는 공지 등록 권한 `posts.write-notice`)과 같은 값으로, 화면의 공지 체크박스가 이 값으로 잠깁니다.


### DELETE /api/modules/sirsoft-board/admin/board/{slug}/posts/{id}
<!-- @generated:start:api.modules.sirsoft-board.admin.board.posts.destroy -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.posts.destroy`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\PostController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.posts.write|sirsoft-board.{slug}.admin.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-board/admin/board/{slug}/posts/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| category | null | `null` | 게시글 분류(카테고리) 문자열. 게시판이 카테고리를 쓰지 않거나 미지정 시 null (최대 50자). |
| author | object | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"AP…` | 작성자 사용자 객체 (uuid/name — author 관계 파생) |
| is_notice | boolean | `false` | notice 여부 |
| is_secret | boolean | `false` | secret 여부 |
| content_mode | string | `html` | 본문 편집 모드. `html`(위지윅/HTML) 또는 `text`(평문)이며, 요약·썸네일 추출과 렌더링 방식을 결정합니다. 미지정 시 `text`. |
| is_new | boolean | `true` | new 여부 |
| status | string | `deleted` | 상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조) |
| status_label | string | `삭제됨` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| view_count | integer | `43` | view 개수 (집계) |
| comment_count | integer | `0` | comment 개수 (집계) |
| reply_count | integer | `0` | reply 개수 (집계) |
| attachment_count | integer | `0` | attachment 개수 (집계). 블라인드 글은 작성자·게시판 관리자가 아니면 `0` (첨부 목록·서빙과 같은 기준) |
| has_attachment | boolean | `false` | attachment 여부. 블라인드 글은 작성자·게시판 관리자가 아니면 `false` |
| thumbnail | null | `null` | 썸네일 이미지 URL/경로 — 첫 이미지 첨부의 미리보기 서빙 URL. 이미지 첨부가 없으면 본문 첫 내부 이미지 URL 로 폴백한다(외부 주소 이미지는 제외 — 1.1.0+). 비밀글은 열람 권한이 없으면 `null`(블라인드 글은 작성자·게시판 관리자가 아니면 `null`). 볼 수 있는 사람에게는 한시 서명 주소 |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가) |
| is_reply | boolean | `false` | reply 여부 |
| created_at | string | `2026-07-08 10:41:34` | 생성 일시 |
| created_at_formatted | string | `4시간 전` | `created_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| is_author | boolean | `true` | author 여부 |
| is_guest_post | boolean | `false` | guest post 여부 |
| title | string | `API 문서 샘플 게시글` | 제목 |
| content | string | `<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>` | 본문 내용 |
| user_id | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | user 식별자 (연관 리소스 참조) |
| trigger_type | string | `admin` | 동작을 유발한 방식/주체 구분 값 |
| updated_at | string | `2026-07-08 15:01:43` | 최종 수정 일시 |
| deleted_at | string | `2026-07-08 15:01:43` | 소프트 삭제 일시 (미삭제 시 null) |
| ip_address | string\|null | `127.0.0.1` | 요청/행위가 발생한 IP 주소 (보유기간이 지나 파기되면 null — 게시판 개인정보 보유기간, 이슈 #636) |
| action_logs | array | `[{"action":"delete","reason":null,"admin_name":"API 문서 샘플…` | 블라인드/복원/삭제 등 처리 이력 목록(항목별 action·reason·admin_name·created_at). `admin.manage` 권한 보유자에게만 노출되며, 민감 필드(admin_id·ip_address)는 제외됩니다. 비권한자에게는 null. |
| board | null | `null` | 소속 게시판 정보 객체(슬러그·이름·유형·댓글/답글/신고 사용 여부·조회수 표시·최대 답글/댓글 깊이·신고 사유 목록). board 관계가 로드된 경우에만 채워지며, 아니면 null. |
| navigation | null | `null` | 이전/다음 게시글 이동 정보(`prev`·`next`). 상세 로드 시 계산되며, 쓰기 응답처럼 인접 게시글을 계산하지 않은 경우 null. |
| parent | null | `null` | 상위 항목 객체 (parent 관계 파생) |
| comments | null | `null` | 게시글에 달린 댓글 목록(CommentResource 컬렉션). comments 관계가 로드된 경우에만 채워지며, 아니면 null. |
| attachments | null | `null` | 게시글 첨부파일 목록(AttachmentResource 컬렉션). attachments 관계가 로드된 경우에만 채워지며, 아니면 null(비밀글·삭제글은 권한에 따라 빈 배열 또는 연쇄 삭제분만 노출). |
| replies | null | `null` | 이 게시글에 달린 답변글 목록(PostResource 컬렉션, 재귀). replies 관계가 로드된 경우에만 채워지며, 아니면 null. |
| is_already_reported | boolean | `false` | already reported 여부 |
| reaction_counts | object | `{"1":18,"2":2}` | 게시판이 켠 활성 반응 유형별 개수 맵(JSON 객체). 키는 유형 ID 문자열, 개수 0인 유형도 포함됩니다. board 관계 미로드 시 빈 객체(`{}`). |
| my_reaction_type_id | integer\|null | `1` | 현재 로그인 사용자가 이 게시글에 남긴 반응 유형 ID. 반응이 없으면 null. |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_write":true,"can_read_secret":true,…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시글이 삭제되었습니다.",
    "data": {
        "id": 1,
        "category": null,
        "author": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "avatar": null,
            "status": "active",
            "status_label": "활성",
            "is_guest": false
        },
        "is_notice": false,
        "is_secret": "{MASKED}",
        "content_mode": "html",
        "is_new": true,
        "status": "deleted",
        "status_label": "삭제됨",
        "view_count": 43,
        "comment_count": 0,
        "reply_count": 0,
        "attachment_count": 0,
        "has_attachment": false,
        "thumbnail": null,
        "parent_id": null,
        "depth": 0,
        "is_reply": false,
        "created_at": "2026-07-08 10:41:34",
        "created_at_formatted": "4시간 전",
        "is_author": true,
        "is_guest_post": false,
        "title": "API 문서 샘플 게시글",
        "content": "<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>",
        "user_id": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "trigger_type": "admin",
        "updated_at": "2026-07-08 15:01:43",
        "deleted_at": "2026-07-08 15:01:43",
        "ip_address": "127.0.0.1",
        "action_logs": [
            {
                "action": "delete",
                "reason": null,
                "admin_name": "API 문서 샘플 사용자",
                "created_at": "2026-07-08 06:01:43"
            }
        ],
        "board": null,
        "navigation": null,
        "parent": null,
        "comments": null,
        "attachments": null,
        "replies": null,
        "is_already_reported": false,
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_write": true,
            "can_read_secret": "{MASKED}",
            "can_read_comments": true,
            "can_write_comments": true,
            "can_upload": true,
            "can_download": true,
            "can_manage": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.posts.write\|sirsoft-board.{slug}.admin.manage`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 게시판의 답글 삭제 정책(`reply_delete_policy`)이 `block` 이고 대상 글에 살아 있는 답글이 있는 경우 (`답글이 달린 글은 삭제할 수 없습니다. 답글을 먼저 삭제해 주세요.` — `sirsoft-board::validation.post.delete.has_replies`) |

<!-- @generated:end -->

**설명** 게시판 관리자가 게시글 1건을 소프트 삭제합니다. `auth:sanctum` + admin 인증이 필요하며, 라우트 권한은 `posts.write` 또는 `manage`입니다. 컨트롤러가 대상 게시글을 조회한 뒤 세분화된 권한 분기를 적용합니다: `admin.manage`는 모든 글(비회원 글 포함)을, `admin.posts.write`는 본인 글만 삭제할 수 있으며 이미 삭제된 글의 재처리는 `admin.manage`가 필요합니다. `PostService::deletePost()`가 'admin' 컨텍스트로 소프트 삭제를 수행합니다. 삭제 동작은 게시판의 답글 삭제 정책(`reply_delete_policy`)을 따릅니다 — `cascade`(기본)에서는 원글 삭제 시 살아 있는 답글 트리(및 그 답글들의 댓글·첨부)가 함께 소프트 삭제되고 이후 원글을 복원하면 연쇄 삭제분(`trigger_type='cascade'`)만 선택 복원되며, `block` 에서는 살아 있는 직계 답글이 있으면 `before_delete` 훅 발화 전에 차단되어(부수효과 없음) 422 를 반환합니다.


### GET /api/modules/sirsoft-board/admin/board/{slug}/posts/{id}
<!-- @generated:start:api.modules.sirsoft-board.admin.board.posts.show -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.posts.show`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\PostController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.posts.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/board/{slug}/posts/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `237` | 기본 키 (내부 식별자) |
| category | null | `null` | 게시글 분류(카테고리) 문자열. 게시판이 카테고리를 쓰지 않거나 미지정 시 null (최대 50자). |
| author | object | `{"uuid":"a231747f-e82e-4cf2-9ae1-a261849dce40","name":"AP…` | 작성자 사용자 객체 (uuid/name — author 관계 파생) |
| is_notice | boolean | `false` | notice 여부 |
| is_secret | boolean | `false` | secret 여부 |
| content_mode | string | `html` | 본문 편집 모드. `html`(위지윅/HTML) 또는 `text`(평문)이며, 요약·썸네일 추출과 렌더링 방식을 결정합니다. 미지정 시 `text`. |
| is_new | boolean | `true` | new 여부 |
| status | string | `published` | 게시글 상태 코드. `published`(게시됨) / `blinded`(블라인드) / `deleted`(삭제됨) 중 하나이며, `status_label`이 사람이 읽는 라벨입니다. |
| status_label | string | `게시됨` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| view_count | integer | `43` | view 개수 (집계) |
| comment_count | integer | `0` | comment 개수 (집계) |
| reply_count | integer | `0` | reply 개수 (집계) |
| attachment_count | integer | `0` | attachment 개수 (집계). 블라인드 글은 작성자·게시판 관리자가 아니면 `0` (첨부 목록·서빙과 같은 기준) |
| has_attachment | boolean | `false` | attachment 여부. 블라인드 글은 작성자·게시판 관리자가 아니면 `false` |
| thumbnail | string | `/api/modules/sirsoft-board/boards/api…` | 썸네일 이미지 URL/경로 — `/api/modules/sirsoft-board/boards/{slug}/attachment/{hash}/preview` 형식 (첫 이미지 첨부의 미리보기 서빙 URL). 이미지 첨부가 없으면 본문 첫 내부 이미지 URL 로 폴백한다(외부 주소 이미지는 제외 — 1.1.0+). 비밀글은 열람 권한이 없으면 `null` 로 내려간다(첨부 해시·본문 이미지 URL 노출 차단 — 필드 자체는 유지). 블라인드 글도 작성자·게시판 관리자가 아니면 `null`. 비밀글·블라인드 글을 볼 수 있는 사람에게는 `<img>` 요청에 토큰이 실리지 않으므로 한시 서명 주소(`?expires=…&signature=…`)로 내려간다 |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가) |
| is_reply | boolean | `false` | reply 여부 |
| created_at | string | `2026-07-07 09:34:50` | 생성 일시 |
| created_at_formatted | string | `4시간 전` | `created_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| is_author | boolean | `true` | author 여부 |
| is_guest_post | boolean | `false` | guest post 여부 |
| title | string | `API 문서 샘플 게시글` | 제목 |
| content | string | `<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>` | 본문 내용 |
| user_id | string | `a231747f-e82e-4cf2-9ae1-a261849dce40` | user 식별자 (연관 리소스 참조) |
| trigger_type | string | `user` | 상태 변경(삭제/블라인드 등)을 유발한 주체. `report`(신고) / `admin`(관리자 직권) / `system`(시스템) / `auto_hide`(신고 누적 자동 블라인드) / `user`(사용자 직접) / `cascade`(상위 삭제 연쇄) 중 하나입니다. |
| updated_at | string | `2026-07-07 09:39:03` | 최종 수정 일시 |
| deleted_at | null | `null` | 소프트 삭제 일시 (미삭제 시 null) |
| ip_address | string\|null | `127.0.0.1` | 요청/행위가 발생한 IP 주소 (보유기간이 지나 파기되면 null — 게시판 개인정보 보유기간, 이슈 #636) |
| action_logs | array | `[]` | 블라인드/복원/삭제 등 처리 이력 목록(항목별 action·reason·admin_name·created_at). `admin.manage` 권한 보유자에게만 노출되며, 민감 필드(admin_id·ip_address)는 제외됩니다. 비권한자에게는 null. |
| board | object | `{"slug":"apidoc-sample-board","name":"API 문서 샘플 게시판","typ…` | 소속 게시판 정보 객체(슬러그·이름·유형·댓글/답글/신고 사용 여부·조회수 표시·최대 답글/댓글 깊이·신고 사유 목록). board 관계가 로드된 경우에만 채워지며, 아니면 null. |
| navigation | object | `{"prev":null,"next":null}` | 이전/다음 게시글 이동 정보. `prev`·`next` 키에 인접 게시글 요약(없으면 null)이 담기며, 상세 로드 시 함께 계산됩니다. |
| parent | null | `null` | 상위 항목 객체 (parent 관계 파생) |
| comments | array | `[{"id":760,"post_id":237,"parent_id":null,"content":"API …` | 게시글에 달린 댓글 목록(CommentResource 컬렉션). comments 관계가 로드된 경우에만 채워지며, 각 항목에 신고 여부가 사전 로드되어 담깁니다. |
| comments_truncated | boolean | `false` | 댓글 목록이 상한에서 끊겼는지 여부. `true` 면 `comments` 에 실린 것이 전부가 아닙니다 |
| comments_total | integer\|null | `12` | 댓글 총 건수. 끊기지 않았으면 `comments` 길이와 같고, 끊겼으면 상한값(그 이상)입니다 |
| comments_total_is_exact | boolean | `true` | 위 총 건수가 정확한지 여부. `false` 면 "N건 이상" 으로 표기합니다 |
| attachments | array | `[{"id":155,"hash":"apidocsmpl1","original_filename":"apid…` | 게시글 첨부파일 목록(AttachmentResource 컬렉션). 비밀글은 열람 권한이 없으면 빈 배열, 삭제된 게시글은 관리 권한이 없으면 연쇄 삭제된 첨부만 노출됩니다. |
| replies | array | `[]` | 이 게시글에 달린 답변글 목록(PostResource 컬렉션, 재귀). replies 관계가 로드된 경우에만 채워지며, 아니면 null. |
| is_already_reported | boolean | `false` | already reported 여부 |
| reaction_counts | object | `{"1":18,"2":2}` | 게시판이 켠 활성 반응 유형별 개수 맵(JSON 객체). 키는 유형 ID 문자열, 개수 0인 유형도 포함됩니다. board 관계 미로드 시 빈 객체(`{}`). |
| my_reaction_type_id | integer\|null | `1` | 현재 로그인 사용자가 이 게시글에 남긴 반응 유형 ID. 반응이 없으면 null. |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_write":true,"can_read_secret":true,…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시글 목록을 조회했습니다.",
    "data": {
        "id": 1,
        "category": null,
        "author": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "avatar": null,
            "status": "active",
            "status_label": "활성",
            "is_guest": false
        },
        "is_notice": false,
        "is_secret": "{MASKED}",
        "content_mode": "html",
        "is_new": true,
        "status": "published",
        "status_label": "게시됨",
        "view_count": 44,
        "comment_count": 0,
        "reply_count": 0,
        "attachment_count": 0,
        "has_attachment": false,
        "thumbnail": "/api/modules/sirsoft-board/boards/apidoc-sample-board/attachment/apidocsmpl1/preview",
        "parent_id": null,
        "depth": 0,
        "is_reply": false,
        "created_at": "2026-07-08 10:41:34",
        "created_at_formatted": "4시간 전",
        "is_author": true,
        "is_guest_post": false,
        "title": "API 문서 샘플 게시글",
        "content": "<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>",
        "user_id": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "trigger_type": "user",
        "updated_at": "2026-07-08 15:01:44",
        "deleted_at": null,
        "ip_address": "127.0.0.1",
        "action_logs": [],
        "board": {
            "slug": "apidoc-sample-board",
            "name": "API 문서 샘플 게시판",
            "type": "basic",
            "use_comment": true,
            "use_reply": true,
            "use_report": true,
            "use_scrap": true,
            "show_view_count": true,
            "max_reply_depth": 5,
            "max_comment_depth": 10,
            "report_types": [
                {
                    "value": "abuse",
                    "label": "욕설/비방"
                },
                {
                    "value": "hate_speech",
                    "label": "혐오 발언"
                },
                {
                    "value": "spam",
                    "label": "스팸/광고"
                },
                {
                    "value": "copyright",
                    "label": "저작권 침해"
                },
                {
                    "value": "privacy",
                    "label": "개인정보 노출"
                },
                {
                    "value": "misinformation",
                    "label": "허위정보"
                },
                {
                    "value": "sexual",
                    "label": "성적인 콘텐츠"
                },
                {
                    "value": "violence",
                    "label": "폭력적인 콘텐츠"
                },
                {
                    "value": "other",
                    "label": "기타"
                }
            ]
        },
        "navigation": {
            "prev": null,
            "next": null
        },
        "parent": null,
        "comments": [
            {
                "id": 1,
                "post_id": 1,
                "parent_id": null,
                "content": "API 문서 샘플 댓글입니다.",
                "author": {
                    "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                    "name": "API 문서 샘플 사용자",
                    "email": "apidoc-sample-user@example.com",
                    "avatar": null,
                    "status": "active",
                    "status_label": "활성",
                    "is_guest": false
                },
                "is_secret": "{MASKED}",
                "status": "published",
                "status_label": "게시됨",
                "depth": 0,
                "replies_count": 0,
                "created_at": "2026-07-08 10:41:34",
                "created_at_formatted": "4시간 전",
                "updated_at": "2026-07-08 10:41:34",
                "deleted_at": null,
                "is_cascade_deleted": false,
                "ip_address": null,
                "action_logs": [],
                "is_author": true,
                "is_guest_comment": false,
                "is_already_reported": false,
                "is_owner": true,
                "abilities": {
                    "can_read": true,
                    "can_write": true,
                    "can_manage": true
                }
            }
        ],
        "attachments": [
            {
                "id": 1,
                "hash": "apidocsmpl1",
                "original_filename": "apidoc-sample.png",
                "mime_type": "image/png",
                "size": 2048,
                "size_formatted": "2 KB",
                "collection": "default",
                "order": 0,
                "download_url": "/api/modules/sirsoft-board/boards/apidoc-sample-board/attachment/apidocsmpl1",
                "preview_url": "/api/modules/sirsoft-board/boards/apidoc-sample-board/attachment/apidocsmpl1/preview",
                "is_image": true,
                "meta": null
            }
        ],
        "replies": [],
        "is_already_reported": false,
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_write": true,
            "can_read_secret": "{MASKED}",
            "can_read_comments": true,
            "can_write_comments": true,
            "can_upload": true,
            "can_download": true,
            "can_manage": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.posts.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시판 관리자가 게시글 상세를 조회합니다. `auth:sanctum` + admin + 게시판별 `posts.read` 권한이 필요하며, 삭제된 게시글은 `admin.manage` 권한이 있어야 열람할 수 있습니다(없으면 403). `PostService::loadPostDetail()`이 조회수 증가·댓글·이전/다음 게시글까지 로드하며, 응답에는 댓글별 신고 여부를 N+1 없이 일괄 사전 로드해 담습니다.


### PUT /api/modules/sirsoft-board/admin/board/{slug}/posts/{id}
<!-- @generated:start:api.modules.sirsoft-board.admin.board.posts.update -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.posts.update`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\PostController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.posts.write`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-board.post.update_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-board/admin/board/{slug}/posts/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| category | null | `null` | 게시글 분류(카테고리) 문자열. 게시판이 카테고리를 쓰지 않거나 미지정 시 null (최대 50자). |
| author | object | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"AP…` | 작성자 사용자 객체 (uuid/name — author 관계 파생) |
| is_notice | boolean | `false` | notice 여부 |
| is_secret | boolean | `false` | secret 여부 |
| content_mode | string | `html` | 본문 편집 모드. `html`(위지윅/HTML) 또는 `text`(평문)이며, 요약·썸네일 추출과 렌더링 방식을 결정합니다. 미지정 시 `text`. |
| is_new | boolean | `true` | new 여부 |
| status | string | `published` | 상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조) |
| status_label | string | `게시됨` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| view_count | integer | `44` | view 개수 (집계) |
| comment_count | integer | `0` | comment 개수 (집계) |
| reply_count | integer | `0` | reply 개수 (집계) |
| attachment_count | integer | `0` | attachment 개수 (집계). 블라인드 글은 작성자·게시판 관리자가 아니면 `0` (첨부 목록·서빙과 같은 기준) |
| has_attachment | boolean | `false` | attachment 여부. 블라인드 글은 작성자·게시판 관리자가 아니면 `false` |
| thumbnail | null | `null` | 썸네일 이미지 URL/경로 — 첫 이미지 첨부의 미리보기 서빙 URL. 이미지 첨부가 없으면 본문 첫 내부 이미지 URL 로 폴백한다(외부 주소 이미지는 제외 — 1.1.0+). 비밀글은 열람 권한이 없으면 `null`(블라인드 글은 작성자·게시판 관리자가 아니면 `null`). 볼 수 있는 사람에게는 한시 서명 주소 |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가) |
| is_reply | boolean | `false` | reply 여부 |
| created_at | string | `2026-07-08 10:41:34` | 생성 일시 |
| created_at_formatted | string | `4시간 전` | `created_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| is_author | boolean | `true` | author 여부 |
| is_guest_post | boolean | `false` | guest post 여부 |
| title | string | `API 문서 샘플 게시글` | 제목 |
| content | string | `<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>` | 본문 내용 |
| user_id | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | user 식별자 (연관 리소스 참조) |
| trigger_type | string | `user` | 동작을 유발한 방식/주체 구분 값 |
| updated_at | string | `2026-07-08 15:01:44` | 최종 수정 일시 |
| deleted_at | null | `null` | 소프트 삭제 일시 (미삭제 시 null) |
| ip_address | string\|null | `127.0.0.1` | 요청/행위가 발생한 IP 주소 (보유기간이 지나 파기되면 null — 게시판 개인정보 보유기간, 이슈 #636) |
| action_logs | array | `[]` | 블라인드/복원/삭제 등 처리 이력 목록(항목별 action·reason·admin_name·created_at). `admin.manage` 권한 보유자에게만 노출되며, 민감 필드(admin_id·ip_address)는 제외됩니다. 비권한자에게는 null. |
| board | null | `null` | 소속 게시판 정보 객체(슬러그·이름·유형·댓글/답글/신고 사용 여부·조회수 표시·최대 답글/댓글 깊이·신고 사유 목록). board 관계가 로드된 경우에만 채워지며, 아니면 null. |
| navigation | null | `null` | 이전/다음 게시글 이동 정보(`prev`·`next`). 상세 로드 시 계산되며, 쓰기 응답처럼 인접 게시글을 계산하지 않은 경우 null. |
| parent | null | `null` | 상위 항목 객체 (parent 관계 파생) |
| comments | null | `null` | 게시글에 달린 댓글 목록(CommentResource 컬렉션). comments 관계가 로드된 경우에만 채워지며, 아니면 null. |
| attachments | null | `null` | 게시글 첨부파일 목록(AttachmentResource 컬렉션). attachments 관계가 로드된 경우에만 채워지며, 아니면 null(비밀글·삭제글은 권한에 따라 빈 배열 또는 연쇄 삭제분만 노출). |
| replies | null | `null` | 이 게시글에 달린 답변글 목록(PostResource 컬렉션, 재귀). replies 관계가 로드된 경우에만 채워지며, 아니면 null. |
| is_already_reported | boolean | `false` | already reported 여부 |
| reaction_counts | object | `{"1":18,"2":2}` | 게시판이 켠 활성 반응 유형별 개수 맵(JSON 객체). 키는 유형 ID 문자열, 개수 0인 유형도 포함됩니다. board 관계 미로드 시 빈 객체(`{}`). |
| my_reaction_type_id | integer\|null | `1` | 현재 로그인 사용자가 이 게시글에 남긴 반응 유형 ID. 반응이 없으면 null. |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_write":true,"can_read_secret":true,…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시글이 수정되었습니다.",
    "data": {
        "id": 1,
        "category": null,
        "author": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "avatar": null,
            "status": "active",
            "status_label": "활성",
            "is_guest": false
        },
        "is_notice": false,
        "is_secret": "{MASKED}",
        "content_mode": "html",
        "is_new": true,
        "status": "published",
        "status_label": "게시됨",
        "view_count": 44,
        "comment_count": 0,
        "reply_count": 0,
        "attachment_count": 0,
        "has_attachment": false,
        "thumbnail": null,
        "parent_id": null,
        "depth": 0,
        "is_reply": false,
        "created_at": "2026-07-08 10:41:34",
        "created_at_formatted": "4시간 전",
        "is_author": true,
        "is_guest_post": false,
        "title": "API 문서 샘플 게시글",
        "content": "<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>",
        "user_id": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "trigger_type": "user",
        "updated_at": "2026-07-08 15:01:44",
        "deleted_at": null,
        "ip_address": "127.0.0.1",
        "action_logs": [],
        "board": null,
        "navigation": null,
        "parent": null,
        "comments": null,
        "attachments": null,
        "replies": null,
        "is_already_reported": false,
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_write": true,
            "can_read_secret": "{MASKED}",
            "can_read_comments": true,
            "can_write_comments": true,
            "can_upload": true,
            "can_download": true,
            "can_manage": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.posts.write`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 게시판 관리자가 게시글을 수정합니다. `auth:sanctum` + admin + 게시판별 `posts.write` 권한이 필요하며, 컨트롤러가 대상 게시글을 조회한 뒤 세분화된 권한을 적용합니다: 일반 글은 `admin.manage`(타인 글) 또는 `admin.write`(본인 글), 이미 삭제된 글은 `admin.manage`가 필요합니다. `UpdatePostRequest` 검증 값에서 첨부파일 ID 배열을 분리해 `PostService::updatePost()`로 전달하고, 갱신된 게시글 리소스를 반환합니다. 공지 여부를 바꾸는 요청(`is_notice` 가 현재 값과 다름)은 게시판 관리 권한(`admin.manage`/`manager`) 또는 공지 등록 권한(`posts.write-notice`)이 있어야 합니다 — 없으면 403. 같은 값의 재전송은 막지 않습니다(7.1.0).


### PATCH /api/modules/sirsoft-board/admin/board/{slug}/posts/{id}/blind
<!-- @generated:start:api.modules.sirsoft-board.admin.board.posts.blind -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.posts.blind`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\PostController@blind`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| reason | body | string | 아니오 | max 1000 | 블라인드 처리 사유(최대 1000자). 처리 이력(action_logs)에 기록되며, 미지정 시 빈 문자열로 저장됩니다. |

**요청 예시**

```http
PATCH /api/modules/sirsoft-board/admin/board/{slug}/posts/{id}/blind HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "reason": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| category | null | `null` | 게시글 분류(카테고리) 문자열. 게시판이 카테고리를 쓰지 않거나 미지정 시 null (최대 50자). |
| author | object | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"AP…` | 작성자 사용자 객체 (uuid/name — author 관계 파생) |
| is_notice | boolean | `false` | notice 여부 |
| is_secret | boolean | `false` | secret 여부 |
| content_mode | string | `html` | 본문 편집 모드. `html`(위지윅/HTML) 또는 `text`(평문)이며, 요약·썸네일 추출과 렌더링 방식을 결정합니다. 미지정 시 `text`. |
| is_new | boolean | `true` | new 여부 |
| status | string | `blinded` | 상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조) |
| status_label | string | `블라인드` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| view_count | integer | `44` | view 개수 (집계) |
| comment_count | integer | `0` | comment 개수 (집계) |
| reply_count | integer | `0` | reply 개수 (집계) |
| attachment_count | integer | `0` | attachment 개수 (집계). 블라인드 글은 작성자·게시판 관리자가 아니면 `0` (첨부 목록·서빙과 같은 기준) |
| has_attachment | boolean | `false` | attachment 여부. 블라인드 글은 작성자·게시판 관리자가 아니면 `false` |
| thumbnail | null | `null` | 썸네일 이미지 URL/경로 — 첫 이미지 첨부의 미리보기 서빙 URL. 이미지 첨부가 없으면 본문 첫 내부 이미지 URL 로 폴백한다(외부 주소 이미지는 제외 — 1.1.0+). 비밀글은 열람 권한이 없으면 `null`(블라인드 글은 작성자·게시판 관리자가 아니면 `null`). 볼 수 있는 사람에게는 한시 서명 주소 |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가) |
| is_reply | boolean | `false` | reply 여부 |
| created_at | string | `2026-07-08 10:41:34` | 생성 일시 |
| created_at_formatted | string | `4시간 전` | `created_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| is_author | boolean | `true` | author 여부 |
| is_guest_post | boolean | `false` | guest post 여부 |
| title | string | `API 문서 샘플 게시글` | 제목 |
| content | string | `<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>` | 본문 내용 |
| user_id | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | user 식별자 (연관 리소스 참조) |
| trigger_type | string | `user` | 동작을 유발한 방식/주체 구분 값 |
| updated_at | string | `2026-07-08 15:01:44` | 최종 수정 일시 |
| deleted_at | null | `null` | 소프트 삭제 일시 (미삭제 시 null) |
| ip_address | string\|null | `127.0.0.1` | 요청/행위가 발생한 IP 주소 (보유기간이 지나 파기되면 null — 게시판 개인정보 보유기간, 이슈 #636) |
| action_logs | array | `[{"action":"blind","reason":"실측 예시값","admin_name":"API 문서…` | 블라인드/복원/삭제 등 처리 이력 목록(항목별 action·reason·admin_name·created_at). `admin.manage` 권한 보유자에게만 노출되며, 민감 필드(admin_id·ip_address)는 제외됩니다. 비권한자에게는 null. |
| board | null | `null` | 소속 게시판 정보 객체(슬러그·이름·유형·댓글/답글/신고 사용 여부·조회수 표시·최대 답글/댓글 깊이·신고 사유 목록). board 관계가 로드된 경우에만 채워지며, 아니면 null. |
| navigation | null | `null` | 이전/다음 게시글 이동 정보(`prev`·`next`). 상세 로드 시 계산되며, 쓰기 응답처럼 인접 게시글을 계산하지 않은 경우 null. |
| parent | null | `null` | 상위 항목 객체 (parent 관계 파생) |
| comments | null | `null` | 게시글에 달린 댓글 목록(CommentResource 컬렉션). comments 관계가 로드된 경우에만 채워지며, 아니면 null. |
| attachments | null | `null` | 게시글 첨부파일 목록(AttachmentResource 컬렉션). attachments 관계가 로드된 경우에만 채워지며, 아니면 null(비밀글·삭제글은 권한에 따라 빈 배열 또는 연쇄 삭제분만 노출). |
| replies | null | `null` | 이 게시글에 달린 답변글 목록(PostResource 컬렉션, 재귀). replies 관계가 로드된 경우에만 채워지며, 아니면 null. |
| is_already_reported | boolean | `false` | already reported 여부 |
| reaction_counts | object | `{"1":18,"2":2}` | 게시판이 켠 활성 반응 유형별 개수 맵(JSON 객체). 키는 유형 ID 문자열, 개수 0인 유형도 포함됩니다. board 관계 미로드 시 빈 객체(`{}`). |
| my_reaction_type_id | integer\|null | `1` | 현재 로그인 사용자가 이 게시글에 남긴 반응 유형 ID. 반응이 없으면 null. |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_write":true,"can_read_secret":true,…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시글이 블라인드 처리되었습니다.",
    "data": {
        "id": 1,
        "category": null,
        "author": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "avatar": null,
            "status": "active",
            "status_label": "활성",
            "is_guest": false
        },
        "is_notice": false,
        "is_secret": "{MASKED}",
        "content_mode": "html",
        "is_new": true,
        "status": "blinded",
        "status_label": "블라인드",
        "view_count": 44,
        "comment_count": 0,
        "reply_count": 0,
        "attachment_count": 0,
        "has_attachment": false,
        "thumbnail": null,
        "parent_id": null,
        "depth": 0,
        "is_reply": false,
        "created_at": "2026-07-08 10:41:34",
        "created_at_formatted": "4시간 전",
        "is_author": true,
        "is_guest_post": false,
        "title": "API 문서 샘플 게시글",
        "content": "<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>",
        "user_id": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "trigger_type": "user",
        "updated_at": "2026-07-08 15:01:44",
        "deleted_at": null,
        "ip_address": "127.0.0.1",
        "action_logs": [
            {
                "action": "blind",
                "reason": "실측 예시값",
                "admin_name": "API 문서 샘플 사용자",
                "created_at": "2026-07-08 06:01:44"
            }
        ],
        "board": null,
        "navigation": null,
        "parent": null,
        "comments": null,
        "attachments": null,
        "replies": null,
        "is_already_reported": false,
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_write": true,
            "can_read_secret": "{MASKED}",
            "can_read_comments": true,
            "can_write_comments": true,
            "can_upload": true,
            "can_download": true,
            "can_manage": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.manage`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 게시판 관리자가 게시글을 블라인드 처리합니다. `auth:sanctum` + admin + 게시판별 `admin.manage` 권한이 필요하며, 선택적 `reason`(최대 1000자)을 사유로 받아 `PostService::blindPost()`가 게시글 상태를 블라인드로 전환합니다. 소프트 삭제와 달리 게시글을 숨기되 관리 목적으로 보존하는 처리이며, 복원(restore)으로 되돌릴 수 있습니다. 삭제된 게시글은 블라인드하지 않고 **409** 와 「먼저 복구한 뒤 블라인드」 안내(`sirsoft-board::messages.posts.blind_deleted_not_allowed`)로 거절합니다 — 블라인드가 삭제를 풀지 않게 하기 위해서이며, 게시글 상태는 바뀌지 않습니다(1.2.0+).


### PATCH /api/modules/sirsoft-board/admin/board/{slug}/posts/{id}/restore
<!-- @generated:start:api.modules.sirsoft-board.admin.board.posts.restore -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.posts.restore`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\PostController@restore`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| reason | body | string | 아니오 | max 1000 | 블라인드 복원 사유(최대 1000자). 처리 이력(action_logs)에 기록되며, 미지정 시 null로 전달됩니다. |

**요청 예시**

```http
PATCH /api/modules/sirsoft-board/admin/board/{slug}/posts/{id}/restore HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "reason": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`PostResource` — 복원된 게시글. 게시글 수정(PUT) 응답과 동일한 구조이며, `status` 가 `published` 로 되돌아가고 `action_logs` 에 `restore` 이력이 1건 추가됩니다)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| category | null | `null` | 게시글 분류(카테고리) 문자열 (미지정 시 null) |
| author | object | `{"uuid":"a234c2b1-…","name":"API 문서 샘플 사용자"}` | 작성자 사용자 객체 (uuid/name — author 관계 파생) |
| is_notice | boolean | `false` | 공지 게시글 여부 |
| is_secret | boolean | `false` | 비밀글 여부 |
| content_mode | string | `html` | 본문 편집 모드 (`html` / `text`) |
| is_new | boolean | `true` | 신규(NEW) 표시 대상 여부 |
| status | string | `published` | 복원 후 게시글 상태 (`published` 로 되돌아감) |
| status_label | string | `게시됨` | 상태의 사람이 읽는 라벨 |
| view_count | integer | `44` | 조회수 (집계) |
| comment_count | integer | `0` | 댓글 수 (집계) |
| reply_count | integer | `0` | 답변글 수 (집계) |
| attachment_count | integer | `0` | 첨부파일 수 (집계). 블라인드 글은 작성자·게시판 관리자가 아니면 `0` |
| has_attachment | boolean | `false` | 첨부파일 보유 여부. 블라인드 글은 작성자·게시판 관리자가 아니면 `false` |
| thumbnail | null | `null` | 썸네일 이미지 URL/경로 — 첫 이미지 첨부의 미리보기 서빙 URL. 이미지 첨부가 없으면 본문 첫 내부 이미지 URL 로 폴백한다(외부 주소 이미지는 제외 — 1.1.0+). 비밀글은 열람 권한이 없으면 `null`(블라인드 글은 작성자·게시판 관리자가 아니면 `null`). 볼 수 있는 사람에게는 한시 서명 주소 |
| parent_id | null | `null` | 원글 ID (답변글일 때만 값 존재) |
| depth | integer | `0` | 계층 트리에서의 깊이 |
| is_reply | boolean | `false` | 답변글 여부 |
| created_at | string | `2026-07-08 10:41:34` | 생성 일시 |
| created_at_formatted | string | `4시간 전` | `created_at` 값의 표시용 포맷 문자열 |
| is_author | boolean | `true` | 현재 사용자가 작성자인지 여부 |
| is_guest_post | boolean | `false` | 비회원 작성 게시글 여부 |
| title | string | `API 문서 샘플 게시글` | 제목 |
| content | string | `<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>` | 본문 내용 |
| user_id | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | 작성자 사용자 식별자 |
| trigger_type | string | `admin` | 상태 변경을 유발한 주체 (복원은 관리자 직권이므로 `admin`) |
| updated_at | string | `2026-07-08 15:01:45` | 최종 수정 일시 |
| deleted_at | null | `null` | 소프트 삭제 일시 (복원 후 null) |
| ip_address | string\|null | `127.0.0.1` | 작성 요청이 발생한 IP 주소 (보유기간이 지나 파기되면 null — 게시판 개인정보 보유기간, 이슈 #636) |
| action_logs | array | `[{"action":"restore","reason":null,"admin_name":"API 문서 샘플 사용자","created_at":"2026-07-08 06:01:45"}]` | 블라인드/복원/삭제 처리 이력 목록. `admin.manage` 권한 보유자에게만 노출. |
| board | null | `null` | 소속 게시판 정보 객체 (관계 미로드 시 null) |
| navigation | null | `null` | 이전/다음 게시글 이동 정보 (쓰기 응답은 미계산 → null) |
| parent | null | `null` | 원글 객체 (parent 관계 파생) |
| comments | null | `null` | 댓글 목록 (관계 미로드 시 null) |
| attachments | null | `null` | 첨부파일 목록 (관계 미로드 시 null) |
| replies | null | `null` | 답변글 목록 (관계 미로드 시 null) |
| is_already_reported | boolean | `false` | 현재 사용자가 이미 신고한 게시글인지 여부 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 |
| abilities | object | `{"can_read":true,"can_write":true,…}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시글이 복원되었습니다.",
    "data": {
        "id": 1,
        "category": null,
        "author": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "avatar": null,
            "status": "active",
            "status_label": "활성",
            "is_guest": false
        },
        "is_notice": false,
        "is_secret": false,
        "content_mode": "html",
        "is_new": true,
        "status": "published",
        "status_label": "게시됨",
        "view_count": 44,
        "comment_count": 0,
        "reply_count": 0,
        "attachment_count": 0,
        "has_attachment": false,
        "thumbnail": null,
        "parent_id": null,
        "depth": 0,
        "is_reply": false,
        "created_at": "2026-07-08 10:41:34",
        "created_at_formatted": "4시간 전",
        "is_author": true,
        "is_guest_post": false,
        "title": "API 문서 샘플 게시글",
        "content": "<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>",
        "user_id": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "trigger_type": "admin",
        "updated_at": "2026-07-08 15:01:45",
        "deleted_at": null,
        "ip_address": "127.0.0.1",
        "action_logs": [
            {
                "action": "restore",
                "reason": null,
                "admin_name": "API 문서 샘플 사용자",
                "created_at": "2026-07-08 06:01:45"
            }
        ],
        "board": null,
        "navigation": null,
        "parent": null,
        "comments": null,
        "attachments": null,
        "replies": null,
        "is_already_reported": false,
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_write": true,
            "can_read_secret": true,
            "can_read_comments": true,
            "can_write_comments": true,
            "can_upload": true,
            "can_download": true,
            "can_manage": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.manage`)이 없는 경우 |
| 404 | Not Found | 슬러그에 해당하는 게시판 또는 ID 에 해당하는 게시글이 없는 경우 (`존재하지 않는 게시글입니다.`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`reason` 이 1000자를 초과하는 등) |
| 500 | Internal Server Error | 복원 처리 실패 (`게시글 복원에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 게시판 관리자가 블라인드 처리된 게시글을 복원합니다. `auth:sanctum` + admin + 게시판별 `admin.manage` 권한이 필요하며, 선택적 `reason`(최대 1000자)을 사유로 받아 `PostService::restorePost()`가 블라인드 상태를 해제해 게시글을 다시 노출합니다.


### POST /api/modules/sirsoft-board/admin/board/{slug}/posts/{postId}/comments
<!-- @generated:start:api.modules.sirsoft-board.admin.board.posts.comments.store -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.posts.comments.store`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\CommentController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.comments.write`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| postId | path | string | 예 | — | 대상 post의 식별자 |

> `status` 는 요청으로 `published` 만 지정할 수 있습니다(사용자 경로와 같은 검증 규칙 공유) — 블라인드·복구는 전용 `/blind`·`/restore` 엔드포인트를 씁니다.

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-board.comment.store_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-board/admin/board/{slug}/posts/{postId}/comments HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`CommentResource`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 댓글 기본 키 (내부 식별자) |
| post_id | integer | `1` | 댓글이 속한 게시글 식별자 |
| parent_id | null | `null` | 상위 댓글 식별자 (대댓글일 때만 값 존재) |
| content | string | `API 문서 샘플 댓글입니다.` | 댓글 본문 내용 |
| author | object | `{"uuid":"a234c2b1-…","name":"API 문서 샘플 사용자"}` | 작성자 사용자 객체 (author 관계 파생) |
| is_secret | boolean | `false` | 비밀 댓글 여부 |
| status | string | `published` | 댓글 상태 코드 (`published` / `blinded` / `deleted`) |
| status_label | string | `게시됨` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| depth | integer | `0` | 댓글 계층 깊이 (0 = 최상위 댓글, 서비스에서 자동 계산) |
| replies_count | integer | `0` | 이 댓글에 달린 대댓글 수 (집계) |
| created_at | string | `2026-07-08 10:41:34` | 생성 일시 |
| created_at_formatted | string | `방금 전` | `created_at` 값의 표시용 포맷 문자열 |
| updated_at | string | `2026-07-08 10:41:34` | 최종 수정 일시 |
| deleted_at | null | `null` | 소프트 삭제 일시 (미삭제 시 null) |
| is_cascade_deleted | boolean | `false` | 상위 게시글/댓글 삭제로 연쇄 삭제된 댓글인지 여부 |
| ip_address | string\|null | `127.0.0.1` | 작성 요청이 발생한 IP 주소 (권한자에게만 노출) (보유기간이 지나 파기되면 null — 게시판 개인정보 보유기간, 이슈 #636) |
| action_logs | array | `[]` | 블라인드/복원/삭제 처리 이력 목록. `admin.manage` 권한 보유자에게만 노출. |
| is_author | boolean | `true` | 현재 사용자가 작성자인지 여부 — 회원 댓글만 판정한다(비회원 댓글은 누가 보든 `false`, 로그인하지 않은 방문자도 비회원 댓글의 작성자가 아니다) |
| is_guest_comment | boolean | `false` | 비회원 작성 댓글 여부 |
| is_already_reported | boolean | `false` | 현재 사용자가 이미 신고한 댓글인지 여부 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_write":true,"can_manage":true}` | 현재 사용자가 이 댓글에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "댓글이 등록되었습니다.",
    "data": {
        "id": 1,
        "post_id": 1,
        "parent_id": null,
        "content": "API 문서 샘플 댓글입니다.",
        "author": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "avatar": null,
            "status": "active",
            "status_label": "활성",
            "is_guest": false
        },
        "is_secret": false,
        "status": "published",
        "status_label": "게시됨",
        "depth": 0,
        "replies_count": 0,
        "created_at": "2026-07-08 10:41:34",
        "created_at_formatted": "방금 전",
        "updated_at": "2026-07-08 10:41:34",
        "deleted_at": null,
        "is_cascade_deleted": false,
        "ip_address": "127.0.0.1",
        "action_logs": [],
        "is_author": true,
        "is_guest_comment": false,
        "is_already_reported": false,
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_write": true,
            "can_manage": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.comments.write`)이 없거나, 게시판의 댓글 기능이 꺼진 경우 (`이 게시판은 댓글 기능이 비활성화되어 있습니다.`) |
| 404 | Not Found | 슬러그에 해당하는 게시판이 없는 경우 (`게시판을 찾을 수 없습니다.`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). 댓글 길이, 금지 키워드, 작성 쿨다운, 대상 게시글·상위 댓글 유효성 위반 포함 |
| 500 | Internal Server Error | 댓글 생성 처리 실패 (`댓글 등록에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 게시판 관리자가 특정 게시글에 댓글을 작성합니다. `auth:sanctum` + admin + 게시판별 `comments.write` 권한이 필요하며, 게시판의 `use_comment`가 꺼져 있으면 403으로 차단됩니다. 검증된 값에 게시글 ID·작성자(`Auth::id()`)·요청 IP가 자동으로 채워져 `CommentService::createComment()`로 전달되고, 성공 시 생성된 댓글 리소스를 201로 반환합니다.


### DELETE /api/modules/sirsoft-board/admin/board/{slug}/posts/{postId}/comments/{id}
<!-- @generated:start:api.modules.sirsoft-board.admin.board.posts.comments.destroy -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.posts.comments.destroy`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\CommentController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.comments.write|sirsoft-board.{slug}.admin.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| postId | path | string | 예 | — | 대상 post의 식별자 |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-board/admin/board/{slug}/posts/{postId}/comments/{id} HTTP/1.1
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
    "message": "댓글이 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.comments.write\|sirsoft-board.{slug}.admin.manage`)이 없거나, 타인/비회원 댓글을 `admin.manage` 없이 삭제하려는 경우(`접근 권한이 없습니다.`), 게시판의 댓글 기능이 꺼진 경우(`이 게시판은 댓글 기능이 비활성화되어 있습니다.`) |
| 404 | Not Found | ID 에 해당하는 댓글 또는 슬러그에 해당하는 게시판이 없는 경우 (`댓글을 찾을 수 없습니다.`) |
| 500 | Internal Server Error | 삭제 처리 실패 (`댓글 삭제에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 게시판 관리자가 댓글 1건을 삭제합니다. `auth:sanctum` + admin 인증이 필요하며, 라우트 권한은 `comments.write` 또는 `manage`입니다. 컨트롤러가 댓글을 조회한 뒤 권한을 적용합니다: `admin.manage`는 모든 댓글(비회원 댓글 포함), `admin.write`는 본인 댓글만 삭제할 수 있습니다. 게시판의 `use_comment`가 꺼져 있으면 403이며, `CommentService::deleteComment()`가 'admin' 컨텍스트로 삭제를 수행합니다. 경로의 `{postId}`에 속한 댓글만 대상이 됩니다 — 다른 게시글의 댓글 ID를 지정하면 404를 반환하며 해당 댓글은 변경되지 않습니다.


### PUT /api/modules/sirsoft-board/admin/board/{slug}/posts/{postId}/comments/{id}
<!-- @generated:start:api.modules.sirsoft-board.admin.board.posts.comments.update -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.posts.comments.update`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\CommentController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.comments.write`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| postId | path | string | 예 | — | 대상 post의 식별자 |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

> `status` 는 요청으로 `published` 만 지정할 수 있습니다(사용자 경로와 같은 검증 규칙 공유) — 블라인드·복구는 전용 `/blind`·`/restore` 엔드포인트를 씁니다.

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-board.comment.update_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-board/admin/board/{slug}/posts/{postId}/comments/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`CommentResource` — 댓글 생성/블라인드 응답과 동일한 구조)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 댓글 기본 키 (내부 식별자) |
| post_id | integer | `1` | 댓글이 속한 게시글 식별자 |
| parent_id | null | `null` | 상위 댓글 식별자 (대댓글일 때만 값 존재) |
| content | string | `API 문서 샘플 댓글입니다. (수정)` | 수정된 댓글 본문 내용 |
| author | object | `{"uuid":"a234c2b1-…","name":"API 문서 샘플 사용자"}` | 작성자 사용자 객체 (author 관계 파생) |
| is_secret | boolean | `false` | 비밀 댓글 여부 |
| status | string | `published` | 댓글 상태 코드 (`published` / `blinded` / `deleted`) |
| status_label | string | `게시됨` | 상태의 사람이 읽는 라벨 |
| depth | integer | `0` | 댓글 계층 깊이 (0 = 최상위 댓글) |
| replies_count | integer | `0` | 이 댓글에 달린 대댓글 수 (집계) |
| created_at | string | `2026-07-08 10:41:34` | 생성 일시 |
| created_at_formatted | string | `4시간 전` | `created_at` 값의 표시용 포맷 문자열 |
| updated_at | string | `2026-07-08 15:01:45` | 최종 수정 일시 (수정 시각으로 갱신) |
| deleted_at | null | `null` | 소프트 삭제 일시 (미삭제 시 null) |
| is_cascade_deleted | boolean | `false` | 상위 게시글/댓글 삭제로 연쇄 삭제된 댓글인지 여부 |
| ip_address | null | `null` | 작성 요청이 발생한 IP 주소 (권한자에게만 노출) |
| action_logs | array | `[]` | 블라인드/복원/삭제 처리 이력 목록. `admin.manage` 권한 보유자에게만 노출. |
| is_author | boolean | `true` | 현재 사용자가 작성자인지 여부 — 회원 댓글만 판정한다(비회원 댓글은 누가 보든 `false`, 로그인하지 않은 방문자도 비회원 댓글의 작성자가 아니다) |
| is_guest_comment | boolean | `false` | 비회원 작성 댓글 여부 |
| is_already_reported | boolean | `false` | 현재 사용자가 이미 신고한 댓글인지 여부 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 |
| abilities | object | `{"can_read":true,"can_write":true,"can_manage":true}` | 현재 사용자가 이 댓글에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "댓글이 수정되었습니다.",
    "data": {
        "id": 1,
        "post_id": 1,
        "parent_id": null,
        "content": "API 문서 샘플 댓글입니다. (수정)",
        "author": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "avatar": null,
            "status": "active",
            "status_label": "활성",
            "is_guest": false
        },
        "is_secret": false,
        "status": "published",
        "status_label": "게시됨",
        "depth": 0,
        "replies_count": 0,
        "created_at": "2026-07-08 10:41:34",
        "created_at_formatted": "4시간 전",
        "updated_at": "2026-07-08 15:01:45",
        "deleted_at": null,
        "is_cascade_deleted": false,
        "ip_address": null,
        "action_logs": [],
        "is_author": true,
        "is_guest_comment": false,
        "is_already_reported": false,
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_write": true,
            "can_manage": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.comments.write`)이 없거나, 타인/비회원 댓글을 `admin.manage` 없이 수정하려는 경우(`접근 권한이 없습니다.`), 게시판의 댓글 기능이 꺼진 경우(`이 게시판은 댓글 기능이 비활성화되어 있습니다.`) |
| 404 | Not Found | ID 에 해당하는 댓글 또는 슬러그에 해당하는 게시판이 없는 경우 (`댓글을 찾을 수 없습니다.`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 수정 처리 실패 (`댓글 수정에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 게시판 관리자가 댓글 1건을 수정합니다. `auth:sanctum` + admin + 게시판별 `comments.write` 권한이 필요하며, 컨트롤러가 댓글을 조회한 뒤 권한을 적용합니다: `admin.manage`는 모든 댓글, `admin.write`는 본인 댓글만 수정할 수 있습니다. 게시판의 `use_comment`가 꺼져 있으면 403이며, `UpdateCommentRequest` 검증 값으로 `CommentService::updateComment()`가 갱신을 수행합니다. 경로의 `{postId}`에 속한 댓글만 대상이 됩니다 — 다른 게시글의 댓글 ID를 지정하면 404를 반환하며 해당 댓글은 변경되지 않습니다. 작성자 본인 판정은 댓글 쓰기 권한(`admin.comments.write`)으로 합니다 — 관리 권한이 없어도 본인 댓글은 수정할 수 있습니다(7.1.0 정정).


### PATCH /api/modules/sirsoft-board/admin/board/{slug}/posts/{postId}/comments/{id}/blind
<!-- @generated:start:api.modules.sirsoft-board.admin.board.posts.comments.blind -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.posts.comments.blind`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\CommentController@blind`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| postId | path | string | 예 | — | 대상 post의 식별자 |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| reason | body | string | 아니오 | max 1000 | 댓글 블라인드 처리 사유(최대 1000자). 처리 이력에 기록되며, 미지정 시 빈 문자열로 저장됩니다. |

**요청 예시**

```http
PATCH /api/modules/sirsoft-board/admin/board/{slug}/posts/{postId}/comments/{id}/blind HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "reason": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| post_id | integer | `1` | post 식별자 (연관 리소스 참조) |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |
| content | string | `API 문서 샘플 댓글입니다.` | 본문 내용 |
| author | object | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"AP…` | 작성자 사용자 객체 (uuid/name — author 관계 파생) |
| is_secret | boolean | `false` | secret 여부 |
| status | string | `blinded` | 상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조) |
| status_label | string | `블라인드` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가) |
| replies_count | integer | `0` | replies 개수 (집계) |
| created_at | string | `2026-07-08 10:41:34` | 생성 일시 |
| created_at_formatted | string | `4시간 전` | `created_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| updated_at | string | `2026-07-08 15:01:44` | 최종 수정 일시 |
| deleted_at | null | `null` | 소프트 삭제 일시 (미삭제 시 null) |
| is_cascade_deleted | boolean | `false` | cascade deleted 여부 |
| ip_address | null | `null` | 요청/행위가 발생한 IP 주소 |
| action_logs | array | `[{"action":"blind","reason":"실측 예시값","admin_name":"API 문서…` | 블라인드/복원/삭제 등 처리 이력 목록(항목별 action·reason·admin_name·created_at). `admin.manage` 권한 보유자에게만 노출되며, 민감 필드(admin_id·ip_address)는 제외됩니다. 비권한자에게는 null. |
| is_author | boolean | `true` | 현재 사용자가 작성자인지 여부 — 회원 댓글만 판정한다(비회원 댓글은 누가 보든 `false`, 로그인하지 않은 방문자도 비회원 댓글의 작성자가 아니다) |
| is_guest_comment | boolean | `false` | guest comment 여부 |
| is_already_reported | boolean | `false` | already reported 여부 |
| reaction_counts | object | `{"1":18,"2":2}` | 게시판이 켠 활성 반응 유형별 개수 맵(JSON 객체). 키는 유형 ID 문자열, 개수 0인 유형도 포함됩니다. board 관계 미로드 시 빈 객체(`{}`). |
| my_reaction_type_id | integer\|null | `1` | 현재 로그인 사용자가 이 게시글에 남긴 반응 유형 ID. 반응이 없으면 null. |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_write":true,"can_manage":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "댓글이 블라인드 처리되었습니다.",
    "data": {
        "id": 1,
        "post_id": 1,
        "parent_id": null,
        "content": "API 문서 샘플 댓글입니다.",
        "author": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "avatar": null,
            "status": "active",
            "status_label": "활성",
            "is_guest": false
        },
        "is_secret": "{MASKED}",
        "status": "blinded",
        "status_label": "블라인드",
        "depth": 0,
        "replies_count": 0,
        "created_at": "2026-07-08 10:41:34",
        "created_at_formatted": "4시간 전",
        "updated_at": "2026-07-08 15:01:44",
        "deleted_at": null,
        "is_cascade_deleted": false,
        "ip_address": null,
        "action_logs": [
            {
                "action": "blind",
                "reason": "실측 예시값",
                "admin_name": "API 문서 샘플 사용자",
                "created_at": "2026-07-08 06:01:44"
            }
        ],
        "is_author": true,
        "is_guest_comment": false,
        "is_already_reported": false,
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_write": true,
            "can_manage": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.manage`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 게시판 관리자가 댓글을 블라인드 처리합니다. `auth:sanctum` + admin + 게시판별 `admin.manage` 권한이 필요하며, 게시판의 `use_comment`가 꺼져 있으면 403으로 차단됩니다. 선택적 `reason`(최대 1000자)을 사유로 받아 `CommentService::blindComment()`가 댓글을 숨김 처리하되 관리 목적으로 보존하며, 복원(restore)으로 되돌릴 수 있습니다. 경로의 `{postId}`에 속한 댓글만 대상이 됩니다 — 다른 게시글의 댓글 ID를 지정하면 404를 반환하며 해당 댓글은 변경되지 않습니다. 삭제된 댓글은 블라인드하지 않고 **409** 와 「먼저 복구한 뒤 블라인드」 안내(`sirsoft-board::messages.comment.blind_deleted_not_allowed`)로 거절합니다(1.2.0+).


### PATCH /api/modules/sirsoft-board/admin/board/{slug}/posts/{postId}/comments/{id}/restore
<!-- @generated:start:api.modules.sirsoft-board.admin.board.posts.comments.restore -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.posts.comments.restore`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\CommentController@restore`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| postId | path | string | 예 | — | 대상 post의 식별자 |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| reason | body | string | 아니오 | max 1000 | 댓글 블라인드 처리 사유(최대 1000자). 처리 이력에 기록되며, 미지정 시 빈 문자열로 저장됩니다. |

**요청 예시**

```http
PATCH /api/modules/sirsoft-board/admin/board/{slug}/posts/{postId}/comments/{id}/restore HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "reason": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`CommentResource` — 복원된 댓글. 블라인드 응답과 동일한 구조이며, `status` 가 `published` 로 되돌아가고 `action_logs` 에 `restore` 이력이 1건 추가됩니다)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 댓글 기본 키 (내부 식별자) |
| post_id | integer | `1` | 댓글이 속한 게시글 식별자 |
| parent_id | null | `null` | 상위 댓글 식별자 (대댓글일 때만 값 존재) |
| content | string | `API 문서 샘플 댓글입니다.` | 댓글 본문 내용 (복원되어 원문이 다시 노출됨) |
| author | object | `{"uuid":"a234c2b1-…","name":"API 문서 샘플 사용자"}` | 작성자 사용자 객체 (author 관계 파생) |
| is_secret | boolean | `false` | 비밀 댓글 여부 |
| status | string | `published` | 복원 후 댓글 상태 (`published` 로 되돌아감) |
| status_label | string | `게시됨` | 상태의 사람이 읽는 라벨 |
| depth | integer | `0` | 댓글 계층 깊이 (0 = 최상위 댓글) |
| replies_count | integer | `0` | 이 댓글에 달린 대댓글 수 (집계) |
| created_at | string | `2026-07-08 10:41:34` | 생성 일시 |
| created_at_formatted | string | `4시간 전` | `created_at` 값의 표시용 포맷 문자열 |
| updated_at | string | `2026-07-08 15:01:45` | 최종 수정 일시 |
| deleted_at | null | `null` | 소프트 삭제 일시 (미삭제 시 null) |
| is_cascade_deleted | boolean | `false` | 상위 게시글/댓글 삭제로 연쇄 삭제된 댓글인지 여부 |
| ip_address | null | `null` | 작성 요청이 발생한 IP 주소 (권한자에게만 노출) |
| action_logs | array | `[{"action":"blind","reason":"실측 예시값",…},{"action":"restore","reason":null,…}]` | 블라인드/복원/삭제 처리 이력 목록 (복원 이력이 추가됨). `admin.manage` 권한 보유자에게만 노출. |
| is_author | boolean | `true` | 현재 사용자가 작성자인지 여부 — 회원 댓글만 판정한다(비회원 댓글은 누가 보든 `false`, 로그인하지 않은 방문자도 비회원 댓글의 작성자가 아니다) |
| is_guest_comment | boolean | `false` | 비회원 작성 댓글 여부 |
| is_already_reported | boolean | `false` | 현재 사용자가 이미 신고한 댓글인지 여부 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 |
| abilities | object | `{"can_read":true,"can_write":true,"can_manage":true}` | 현재 사용자가 이 댓글에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "댓글이 복원되었습니다.",
    "data": {
        "id": 1,
        "post_id": 1,
        "parent_id": null,
        "content": "API 문서 샘플 댓글입니다.",
        "author": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "avatar": null,
            "status": "active",
            "status_label": "활성",
            "is_guest": false
        },
        "is_secret": false,
        "status": "published",
        "status_label": "게시됨",
        "depth": 0,
        "replies_count": 0,
        "created_at": "2026-07-08 10:41:34",
        "created_at_formatted": "4시간 전",
        "updated_at": "2026-07-08 15:01:45",
        "deleted_at": null,
        "is_cascade_deleted": false,
        "ip_address": null,
        "action_logs": [
            {
                "action": "blind",
                "reason": "실측 예시값",
                "admin_name": "API 문서 샘플 사용자",
                "created_at": "2026-07-08 06:01:44"
            },
            {
                "action": "restore",
                "reason": null,
                "admin_name": "API 문서 샘플 사용자",
                "created_at": "2026-07-08 06:01:45"
            }
        ],
        "is_author": true,
        "is_guest_comment": false,
        "is_already_reported": false,
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_write": true,
            "can_manage": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.manage`)이 없거나, 게시판의 댓글 기능이 꺼진 경우 (`이 게시판은 댓글 기능이 비활성화되어 있습니다.`) |
| 404 | Not Found | ID 에 해당하는 댓글 또는 슬러그에 해당하는 게시판이 없는 경우 (`댓글을 찾을 수 없습니다.`) |
| 409 | Conflict | 원글이 삭제된 상태에서 댓글만 복구하려 한 경우 (`원글이 삭제된 상태라 댓글만 복구할 수 없습니다. 원글을 먼저 복구해 주세요.`) — 원글을 복구하면 함께 지워진 댓글도 돌아온다(1.2.0+) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 복원 처리 실패 (`댓글 복원에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 게시판 관리자가 블라인드 처리된 댓글을 복원합니다. `auth:sanctum` + admin + 게시판별 `admin.manage` 권한이 필요하며, 게시판의 `use_comment`가 꺼져 있으면 403으로 차단됩니다. 요청 본문의 선택적 `reason`을 사유로 받아 `CommentService::restoreComment()`가 블라인드 상태를 해제해 댓글을 다시 노출합니다. 경로의 `{postId}`에 속한 댓글만 대상이 됩니다 — 다른 게시글의 댓글 ID를 지정하면 404를 반환하며 해당 댓글은 변경되지 않습니다. 원글이 삭제된 상태면 댓글만 살리지 않고 **409** 와 「원글을 먼저 복구」 안내(`sirsoft-board::messages.comment.restore_parent_deleted`)로 거절합니다 — 보이지 않는 글 아래 댓글만 살아나지 않게 하기 위해서이며, 댓글 상태는 바뀌지 않습니다(1.2.0+).



---

## 관리자 게시글 일괄 처리 (삭제 · 블라인드 · 복구)

> 이 절은 **수기 작성**입니다. `api:docgen` 기본 모드는 파괴적 재생성 가드(`docs/backend/api-documentation.md`)로
> 막혀 있어 위쪽 `@generated` 블록처럼 실측 스캐폴딩을 만들 수 없습니다. 값은 전부 코드에서 옮겼고
> 근거 위치를 각 항목에 적었습니다 (이슈 #524).

세 엔드포인트는 같은 요청 형태·같은 응답 형태·같은 권한을 쓰고 동작만 다릅니다.

| 메서드 | 경로 | 라우트명 | 동작 |
| --- | --- | --- | --- |
| DELETE | `/api/modules/sirsoft-board/admin/board/{slug}/posts/bulk` | `…admin.board.posts.bulk-destroy` | 선택한 글을 삭제 |
| PATCH | `/api/modules/sirsoft-board/admin/board/{slug}/posts/bulk-blind` | `…admin.board.posts.bulk-blind` | 선택한 글을 블라인드 |
| PATCH | `/api/modules/sirsoft-board/admin/board/{slug}/posts/bulk-restore` | `…admin.board.posts.bulk-restore` | 블라인드·삭제된 글을 복구 |

- **컨트롤러**: `Admin\PostController@bulkDestroy` / `@bulkBlind` / `@bulkRestore` (`src/Http/Controllers/Admin/PostController.php:623,635,647`)
- **미들웨어**: `auth:sanctum` + `admin` + `permission:admin,sirsoft-board.{slug}.admin.manage`
  셋 다 `admin.manage` 를 요구합니다 — 단건 삭제의 「본인 글이면 `posts.write`」 완화를 일괄에 적용하면
  한 요청에 남의 글이 섞여 들어갈 수 있기 때문입니다 (`src/routes/api.php:330-344`).
- **선언 순서**: 세 라우트는 `/{id}` 계열보다 **먼저** 선언됩니다. 뒤에 두면 `bulk` 가 `{id}` 로 잡힙니다.

### 요청 파라미터

`BulkPostActionRequest` (`src/Http/Requests/Admin/BulkPostActionRequest.php:35-37`)

| 이름 | 위치 | 타입 | 필수 | 제한 | 설명 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | 1~100개 | 대상 게시글 ID 목록 |
| ids.* | body | integer | 예 | distinct | `exists` 를 두지 않습니다 — 없는 ID·다른 게시판의 ID 는 `skipped` 로만 드러나고 422 가 되지 않습니다 |
| reason | body | string | 아니오 | 최대 1000자 | 사유. 블라인드·복구 이력에 남습니다(삭제는 사용하지 않음) |

`DELETE /bulk` 도 **본문 JSON** 으로 `ids` 를 받습니다. 레이아웃 엔진은 GET 이 아닌 모든 메서드에 본문을
실어 보냅니다(`resources/js/core/template-engine/ActionDispatcher.ts`) — 스크랩 일괄 해제와 같은 방식입니다.

### 응답

```json
{
    "success": true,
    "message": "2개의 게시글을 삭제했습니다. (건너뜀 1개)",
    "data": {
        "affected_count": 2,
        "affected_ids": [12, 15],
        "skipped": [
            {
                "id": 19,
                "reason": "has_replies",
                "reason_label": "답글이 달린 글 (답글 삭제 정책상 삭제할 수 없음)",
                "title": "답글이 달린 공지"
            }
        ]
    }
}
```

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| affected_count | integer | 실제로 처리된 건수 |
| affected_ids | array | 처리된 게시글 ID 목록 |
| skipped | array | 건너뛴 항목 목록. 항목마다 `id`(게시글 ID)·`reason`(사유 코드, 아래 표)·`reason_label`(요청 언어로 번역된 사유 — 1.2.0+)·`title`(글 제목, 조회 전에 실패했으면 `null` — 1.2.0+). 관리자 목록 화면은 건너뛴 글이 있으면 이 두 값으로 「건너뛴 게시글」 창을 연다 |

메시지 키는 `sirsoft-board::messages.posts_bulk.{delete,blind,restore}_success` 이며 `:count`·`:skipped`
를 치환합니다 (`src/lang/{ko,en}/messages.php:311-316`).

### 건너뛰는 사유 (`skipped[].reason`)

`PostService::resolveBulkSkipReason()` (`src/Services/PostService.php:711-738`)

| reason | 동작 | 조건 |
| --- | --- | --- |
| `already_deleted` | delete, blind | 이미 삭제 상태이거나 소프트 삭제된 글. 블라인드하면 삭제가 풀리므로 블라인드에서도 건너뜁니다(1.2.0+) — 먼저 복구한 뒤 블라인드합니다 |
| `has_replies` | delete | 게시판의 답글 삭제 정책이 `block` 인데 살아 있는 답글이 있는 글 — 단건 삭제와 같은 판정이라 일괄에서만 규칙이 느슨해지지 않습니다 |
| `already_blinded` | blind | 이미 블라인드된 글 |
| `already_published` | restore | 이미 정상 게시 중인 글 |
| `not_found` | 공통 | 이 게시판에 없는 글(다른 게시판의 글이거나 없는 번호, 1.2.0+). 종전에는 `error` 로 묶였습니다 |
| `unknown_action` | 공통 | 정의되지 않은 처리 종류 (라우트가 세 갈래만 열어 두어 화면에서는 나오지 않음) |
| `error` | 공통 | 항목 처리 중 예외. **한 건의 실패가 나머지를 막지 않습니다** — 사유만 남기고 계속합니다 |

사유 문구는 `sirsoft-board::messages.posts_bulk.skip_reasons.{reason}` 에서 읽습니다.

### 훅과 본인인증(IDV)

- `sirsoft-board.post.before_bulk_action($slug, $action, $ids, $reason)` — **루프에 들어가기 전 1회**만
  발화합니다 (`PostService.php:660`). 항목마다 `before_delete`·`before_blind` 를 태우지 않으므로,
  본인인증이 필요한 상황에서 428 이 루프 **중간**에 터져 일부만 처리되는 일이 없습니다(부수효과 0).
- `sirsoft-board.post.after_bulk_action($slug, $action, $affectedIds, $skipped)` — 처리 뒤 1회 (`:692`).
- 본인인증 정책 `sirsoft-board.post.bulk_action` 이 위 `before_bulk_action` 을 대상으로 선언돼 있습니다
  (`module.php:827-834`, 기본 `enabled=false`). 켜면 미충족 시 **HTTP 428** 과 함께
  `verification.return_request` 가 내려가고, 프론트 인터셉터가 인증 모달을 연 뒤 원요청을 재전송합니다.
  컨트롤러는 `catch (\Exception)` 만 잡습니다 — 428 을 일으키는 예외는 `\Error` 를 상속하므로 삼켜지지 않습니다.

### 에러 응답

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | `sirsoft-board.{slug}.admin.manage` 권한이 없는 경우 |
| 404 | Not Found | 슬러그에 해당하는 게시판이 없는 경우 |
| 422 | Unprocessable Entity | `ids` 가 비었거나 100개를 넘거나 정수가 아닌 경우 |
| 428 | Identity Verification Required | 본인인증 정책이 켜져 있고 미충족인 경우 (아무 항목도 처리되지 않음) |
| 500 | Internal Server Error | 일괄 처리 실패 (`sirsoft-board::messages.posts_bulk.action_failed`) |
