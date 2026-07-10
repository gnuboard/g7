# Board API 레퍼런스

> **소유**: module `sirsoft-board` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Board 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

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
| temp_key | body | string | 아니오 | max 64 | 게시글 작성 전 임시 업로드 세션 키. `post_id`가 없을 때 이 키로 첨부를 임시 보관했다가 게시글 저장 시점에 연결합니다 (쿼리스트링으로 보내면 body로 병합). |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-board.attachment.upload_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-board/admin/board/apidoc-sample-board/attachments HTTP/1.1
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

예시값
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.attachments.upload`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시판 관리자가 게시글 첨부파일 1건을 업로드합니다. `auth:sanctum` + admin + 게시판별 `attachments.upload` 권한이 필요하며, `AttachmentService::upload()`가 게시판별 동적 첨부 테이블에 저장합니다. `post_id`가 있으면 해당 게시글에 즉시 귀속되고, 없으면 `temp_key`로 임시 업로드되어 게시글 작성/수정 저장 시점에 연결됩니다. 응답은 FileUploader 컴포넌트 호환을 위해 `data.data`로 한 번 더 감싸 파일 메타(hash·url·order 등)를 반환합니다.


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
GET /api/modules/sirsoft-board/admin/board/apidoc-sample-board/attachments/download/apidocsmpl1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: http-404 — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-404 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.attachments.download`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

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
PATCH /api/modules/sirsoft-board/admin/board/apidoc-sample-board/attachments/reorder HTTP/1.1
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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.attachments.upload`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시판 관리자가 게시글 첨부파일들의 표시 순서를 일괄 변경합니다. `auth:sanctum` + admin + 게시판별 `attachments.upload` 권한이 필요하며, FileUploader가 보낸 `[{id, order}]` 배열을 `[ID => order]` 매핑으로 변환해 `AttachmentService::reorder()`가 게시판별 첨부 테이블의 order 값을 갱신합니다.


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
DELETE /api/modules/sirsoft-board/admin/board/apidoc-sample-board/attachments/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)



<!-- 실측 응답에 필드 없음(빈 목록 등) — 데이터가 있는 상태로 재실측하거나 사람이 작성. -->

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

**설명** 게시판 관리자가 첨부파일 1건을 삭제합니다. `auth:sanctum` + admin + 게시판별 `attachments.upload` 권한이 필요하며, `AttachmentService::getById()`로 대상 존재를 확인한 뒤 `delete()`가 게시판별 첨부 테이블 레코드와 실제 파일을 함께 제거합니다. 첨부가 없으면 404, 삭제 실패 시 500을 반환합니다.


### GET /api/modules/sirsoft-board/admin/board/{slug}/posts
<!-- @generated:start:api.modules.sirsoft-board.admin.board.posts.index -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.posts.index`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\PostController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.posts.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/board/apidoc-sample-board/posts HTTP/1.1
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
| attachment_count | integer | `0` | attachment 개수 (집계) |
| has_attachment | boolean | `false` | attachment 여부 |
| thumbnail | string | `/api/modules/sirsoft-board/boards/api…` | 썸네일 이미지 URL/경로 |
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

<!-- @generated:end -->

**설명** 게시판 관리자가 특정 게시판의 게시글 목록을 조회합니다. `auth:sanctum` + admin + 게시판별 `posts.read` 권한이 필요하며, 요청 파라미터로 검색·상태·정렬·페이지네이션이 적용됩니다(`PostService::buildListParams`). 추가로 `admin.manage` 권한이 있으면 소프트 삭제된 게시글까지 포함해 조회하며, 응답에는 공지 고정 처리 후의 일반 게시글 총 건수(캐시 기반)와 관리자용 게시판 정보(`boardInfo`)가 함께 담깁니다.


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
POST /api/modules/sirsoft-board/admin/board/apidoc-sample-board/posts HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.posts.write`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시판 관리자가 새 게시글을 작성합니다. `auth:sanctum` + admin + 게시판별 `posts.write` 권한이 필요하며, `StorePostRequest` 검증을 거친 값에 작성자(`Auth::id()`)와 요청 IP가 자동으로 채워집니다. 업로드 파일과 첨부파일 ID 배열은 본문에서 분리되어 `PostService::createPost()`로 전달되고, 성공 시 생성된 게시글 리소스를 201로 반환합니다.


### GET /api/modules/sirsoft-board/admin/board/{slug}/posts/form-data
<!-- @generated:start:api.modules.sirsoft-board.admin.board.posts.form-data -->
- **라우트명**: `api.modules.sirsoft-board.admin.board.posts.form-data`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\PostController@getFormData`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.{slug}.admin.posts.write`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/board/apidoc-sample-board/posts/form-data HTTP/1.1
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
GET /api/modules/sirsoft-board/admin/board/apidoc-sample-board/posts/form-meta HTTP/1.1
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

**설명** 게시글 폼 화면 표시용 메타 데이터(읽기 전용)를 반환합니다. `auth:sanctum` + admin + 게시판별 `posts.write` 권한이 필요하며, 게시판 정보와 사용자 권한(abilities)을 항상 포함합니다. `post_id`가 있으면 작성자·작성일·첨부파일과 원글 정보를 덧붙이고(수정 모드), `parent_id`가 있으면 원글 정보를 포함하되 블라인드/삭제된 원글에는 답글 작성이 차단됩니다(각각 403).


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
DELETE /api/modules/sirsoft-board/admin/board/apidoc-sample-board/posts/1 HTTP/1.1
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
| attachment_count | integer | `0` | attachment 개수 (집계) |
| has_attachment | boolean | `false` | attachment 여부 |
| thumbnail | null | `null` | 썸네일 이미지 URL/경로 |
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
| ip_address | string | `127.0.0.1` | 요청/행위가 발생한 IP 주소 |
| action_logs | array | `[{"action":"delete","reason":null,"admin_name":"API 문서 샘플…` | 블라인드/복원/삭제 등 처리 이력 목록(항목별 action·reason·admin_name·created_at). `admin.manage` 권한 보유자에게만 노출되며, 민감 필드(admin_id·ip_address)는 제외됩니다. 비권한자에게는 null. |
| board | null | `null` | 소속 게시판 정보 객체(슬러그·이름·유형·댓글/답글/신고 사용 여부·조회수 표시·최대 답글/댓글 깊이·신고 사유 목록). board 관계가 로드된 경우에만 채워지며, 아니면 null. |
| navigation | null | `null` | 이전/다음 게시글 이동 정보(`prev`·`next`). 상세 로드 시 계산되며, 쓰기 응답처럼 인접 게시글을 계산하지 않은 경우 null. |
| parent | null | `null` | 상위 항목 객체 (parent 관계 파생) |
| comments | null | `null` | 게시글에 달린 댓글 목록(CommentResource 컬렉션). comments 관계가 로드된 경우에만 채워지며, 아니면 null. |
| attachments | null | `null` | 게시글 첨부파일 목록(AttachmentResource 컬렉션). attachments 관계가 로드된 경우에만 채워지며, 아니면 null(비밀글·삭제글은 권한에 따라 빈 배열 또는 연쇄 삭제분만 노출). |
| replies | null | `null` | 이 게시글에 달린 답변글 목록(PostResource 컬렉션, 재귀). replies 관계가 로드된 경우에만 채워지며, 아니면 null. |
| is_already_reported | boolean | `false` | already reported 여부 |
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
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.posts.write|sirsoft-board.{slug}.admin.manage`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시판 관리자가 게시글 1건을 소프트 삭제합니다. `auth:sanctum` + admin 인증이 필요하며, 라우트 권한은 `posts.write` 또는 `manage`입니다. 컨트롤러가 대상 게시글을 조회한 뒤 세분화된 권한 분기를 적용합니다: `admin.manage`는 모든 글(비회원 글 포함)을, `admin.posts.write`는 본인 글만 삭제할 수 있으며 이미 삭제된 글의 재처리는 `admin.manage`가 필요합니다. `PostService::deletePost()`가 'admin' 컨텍스트로 소프트 삭제를 수행합니다.


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
GET /api/modules/sirsoft-board/admin/board/apidoc-sample-board/posts/1 HTTP/1.1
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
| attachment_count | integer | `0` | attachment 개수 (집계) |
| has_attachment | boolean | `false` | attachment 여부 |
| thumbnail | string | `/api/modules/sirsoft-board/boards/api…` | 썸네일 이미지 URL/경로 |
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
| ip_address | string | `127.0.0.1` | 요청/행위가 발생한 IP 주소 |
| action_logs | array | `[]` | 블라인드/복원/삭제 등 처리 이력 목록(항목별 action·reason·admin_name·created_at). `admin.manage` 권한 보유자에게만 노출되며, 민감 필드(admin_id·ip_address)는 제외됩니다. 비권한자에게는 null. |
| board | object | `{"slug":"apidoc-sample-board","name":"API 문서 샘플 게시판","typ…` | 소속 게시판 정보 객체(슬러그·이름·유형·댓글/답글/신고 사용 여부·조회수 표시·최대 답글/댓글 깊이·신고 사유 목록). board 관계가 로드된 경우에만 채워지며, 아니면 null. |
| navigation | object | `{"prev":null,"next":null}` | 이전/다음 게시글 이동 정보. `prev`·`next` 키에 인접 게시글 요약(없으면 null)이 담기며, 상세 로드 시 함께 계산됩니다. |
| parent | null | `null` | 상위 항목 객체 (parent 관계 파생) |
| comments | array | `[{"id":760,"post_id":237,"parent_id":null,"content":"API …` | 게시글에 달린 댓글 목록(CommentResource 컬렉션). comments 관계가 로드된 경우에만 채워지며, 각 항목에 신고 여부가 사전 로드되어 담깁니다. |
| attachments | array | `[{"id":155,"hash":"apidocsmpl1","original_filename":"apid…` | 게시글 첨부파일 목록(AttachmentResource 컬렉션). 비밀글은 열람 권한이 없으면 빈 배열, 삭제된 게시글은 관리 권한이 없으면 연쇄 삭제된 첨부만 노출됩니다. |
| replies | array | `[]` | 이 게시글에 달린 답변글 목록(PostResource 컬렉션, 재귀). replies 관계가 로드된 경우에만 채워지며, 아니면 null. |
| is_already_reported | boolean | `false` | already reported 여부 |
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
PUT /api/modules/sirsoft-board/admin/board/apidoc-sample-board/posts/1 HTTP/1.1
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
| attachment_count | integer | `0` | attachment 개수 (집계) |
| has_attachment | boolean | `false` | attachment 여부 |
| thumbnail | null | `null` | 썸네일 이미지 URL/경로 |
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
| ip_address | string | `127.0.0.1` | 요청/행위가 발생한 IP 주소 |
| action_logs | array | `[]` | 블라인드/복원/삭제 등 처리 이력 목록(항목별 action·reason·admin_name·created_at). `admin.manage` 권한 보유자에게만 노출되며, 민감 필드(admin_id·ip_address)는 제외됩니다. 비권한자에게는 null. |
| board | null | `null` | 소속 게시판 정보 객체(슬러그·이름·유형·댓글/답글/신고 사용 여부·조회수 표시·최대 답글/댓글 깊이·신고 사유 목록). board 관계가 로드된 경우에만 채워지며, 아니면 null. |
| navigation | null | `null` | 이전/다음 게시글 이동 정보(`prev`·`next`). 상세 로드 시 계산되며, 쓰기 응답처럼 인접 게시글을 계산하지 않은 경우 null. |
| parent | null | `null` | 상위 항목 객체 (parent 관계 파생) |
| comments | null | `null` | 게시글에 달린 댓글 목록(CommentResource 컬렉션). comments 관계가 로드된 경우에만 채워지며, 아니면 null. |
| attachments | null | `null` | 게시글 첨부파일 목록(AttachmentResource 컬렉션). attachments 관계가 로드된 경우에만 채워지며, 아니면 null(비밀글·삭제글은 권한에 따라 빈 배열 또는 연쇄 삭제분만 노출). |
| replies | null | `null` | 이 게시글에 달린 답변글 목록(PostResource 컬렉션, 재귀). replies 관계가 로드된 경우에만 채워지며, 아니면 null. |
| is_already_reported | boolean | `false` | already reported 여부 |
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시판 관리자가 게시글을 수정합니다. `auth:sanctum` + admin + 게시판별 `posts.write` 권한이 필요하며, 컨트롤러가 대상 게시글을 조회한 뒤 세분화된 권한을 적용합니다: 일반 글은 `admin.manage`(타인 글) 또는 `admin.write`(본인 글), 이미 삭제된 글은 `admin.manage`가 필요합니다. `UpdatePostRequest` 검증 값에서 첨부파일 ID 배열을 분리해 `PostService::updatePost()`로 전달하고, 갱신된 게시글 리소스를 반환합니다.


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
PATCH /api/modules/sirsoft-board/admin/board/apidoc-sample-board/posts/1/blind HTTP/1.1
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
| attachment_count | integer | `0` | attachment 개수 (집계) |
| has_attachment | boolean | `false` | attachment 여부 |
| thumbnail | null | `null` | 썸네일 이미지 URL/경로 |
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
| ip_address | string | `127.0.0.1` | 요청/행위가 발생한 IP 주소 |
| action_logs | array | `[{"action":"blind","reason":"실측 예시값","admin_name":"API 문서…` | 블라인드/복원/삭제 등 처리 이력 목록(항목별 action·reason·admin_name·created_at). `admin.manage` 권한 보유자에게만 노출되며, 민감 필드(admin_id·ip_address)는 제외됩니다. 비권한자에게는 null. |
| board | null | `null` | 소속 게시판 정보 객체(슬러그·이름·유형·댓글/답글/신고 사용 여부·조회수 표시·최대 답글/댓글 깊이·신고 사유 목록). board 관계가 로드된 경우에만 채워지며, 아니면 null. |
| navigation | null | `null` | 이전/다음 게시글 이동 정보(`prev`·`next`). 상세 로드 시 계산되며, 쓰기 응답처럼 인접 게시글을 계산하지 않은 경우 null. |
| parent | null | `null` | 상위 항목 객체 (parent 관계 파생) |
| comments | null | `null` | 게시글에 달린 댓글 목록(CommentResource 컬렉션). comments 관계가 로드된 경우에만 채워지며, 아니면 null. |
| attachments | null | `null` | 게시글 첨부파일 목록(AttachmentResource 컬렉션). attachments 관계가 로드된 경우에만 채워지며, 아니면 null(비밀글·삭제글은 권한에 따라 빈 배열 또는 연쇄 삭제분만 노출). |
| replies | null | `null` | 이 게시글에 달린 답변글 목록(PostResource 컬렉션, 재귀). replies 관계가 로드된 경우에만 채워지며, 아니면 null. |
| is_already_reported | boolean | `false` | already reported 여부 |
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시판 관리자가 게시글을 블라인드 처리합니다. `auth:sanctum` + admin + 게시판별 `admin.manage` 권한이 필요하며, 선택적 `reason`(최대 1000자)을 사유로 받아 `PostService::blindPost()`가 게시글 상태를 블라인드로 전환합니다. 소프트 삭제와 달리 게시글을 숨기되 관리 목적으로 보존하는 처리이며, 복원(restore)으로 되돌릴 수 있습니다.


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
PATCH /api/modules/sirsoft-board/admin/board/apidoc-sample-board/posts/1/restore HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "reason": "예시값"
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
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.manage`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

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

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-board.comment.store_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-board/admin/board/apidoc-sample-board/posts/1/comments HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.comments.write`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

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
DELETE /api/modules/sirsoft-board/admin/board/apidoc-sample-board/posts/1/comments/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)



<!-- 실측 응답에 필드 없음(빈 목록 등) — 데이터가 있는 상태로 재실측하거나 사람이 작성. -->

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
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.comments.write|sirsoft-board.{slug}.admin.manage`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시판 관리자가 댓글 1건을 삭제합니다. `auth:sanctum` + admin 인증이 필요하며, 라우트 권한은 `comments.write` 또는 `manage`입니다. 컨트롤러가 댓글을 조회한 뒤 권한을 적용합니다: `admin.manage`는 모든 댓글(비회원 댓글 포함), `admin.write`는 본인 댓글만 삭제할 수 있습니다. 게시판의 `use_comment`가 꺼져 있으면 403이며, `CommentService::deleteComment()`가 'admin' 컨텍스트로 삭제를 수행합니다.


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

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-board.comment.update_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-board/admin/board/apidoc-sample-board/posts/1/comments/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.comments.write`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시판 관리자가 댓글 1건을 수정합니다. `auth:sanctum` + admin + 게시판별 `comments.write` 권한이 필요하며, 컨트롤러가 댓글을 조회한 뒤 권한을 적용합니다: `admin.manage`는 모든 댓글, `admin.write`는 본인 댓글만 수정할 수 있습니다. 게시판의 `use_comment`가 꺼져 있으면 403이며, `UpdateCommentRequest` 검증 값으로 `CommentService::updateComment()`가 갱신을 수행합니다.


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
PATCH /api/modules/sirsoft-board/admin/board/apidoc-sample-board/posts/1/comments/1/blind HTTP/1.1
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
| is_author | boolean | `true` | author 여부 |
| is_guest_comment | boolean | `false` | guest comment 여부 |
| is_already_reported | boolean | `false` | already reported 여부 |
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시판 관리자가 댓글을 블라인드 처리합니다. `auth:sanctum` + admin + 게시판별 `admin.manage` 권한이 필요하며, 게시판의 `use_comment`가 꺼져 있으면 403으로 차단됩니다. 선택적 `reason`(최대 1000자)을 사유로 받아 `CommentService::blindComment()`가 댓글을 숨김 처리하되 관리 목적으로 보존하며, 복원(restore)으로 되돌릴 수 있습니다.


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

**요청 예시**

```http
PATCH /api/modules/sirsoft-board/admin/board/apidoc-sample-board/posts/1/comments/1/restore HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.admin.manage`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시판 관리자가 블라인드 처리된 댓글을 복원합니다. `auth:sanctum` + admin + 게시판별 `admin.manage` 권한이 필요하며, 게시판의 `use_comment`가 꺼져 있으면 403으로 차단됩니다. 요청 본문의 선택적 `reason`을 사유로 받아 `CommentService::restoreComment()`가 블라인드 상태를 해제해 댓글을 다시 노출합니다.


