# Attachments API 레퍼런스

> **소유**: module `sirsoft-page` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Attachments 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/modules/sirsoft-page/admin/attachments
<!-- @generated:start:api.modules.sirsoft-page.admin.attachments.upload -->
- **라우트명**: `api.modules.sirsoft-page.admin.attachments.upload`
- **컨트롤러**: `Modules\Sirsoft\Page\Http\Controllers\Admin\PageAttachmentController@upload`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-page.pages.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| file | body | file | 예 | max: 첨부 최대 용량 설정(MB→KB 환산, 기본 10MB) · mimetypes: 허용 형식 설정 | 업로드 파일 (용량·형식 모두 페이지 모듈 환경설정이 SSoT) |
| page_id | body | integer | 아니오 | min 1 | page 식별자 |
| collection | body | string | 아니오 | max 100 | 첨부 컬렉션 그룹명 (첨부를 용도별로 묶는 키, 미지정 시 default) |
| temp_key | body | string | 아니오 | max 64 | 저장 전 임시 귀속 키. 아직 저장되지 않은 페이지의 첨부를 임시로 묶어 두고, 이후 페이지 저장 시 이 키로 확정 귀속합니다 (`page_id` 미지정 시 사용) |

**요청 예시**

```http
POST /api/modules/sirsoft-page/admin/attachments HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: multipart/form-data; boundary=----G7ExampleBoundary

------G7ExampleBoundary
Content-Disposition: form-data; name="file"; filename="example.pdf"
Content-Type: application/octet-stream

(바이너리 파일 내용)
------G7ExampleBoundary
Content-Disposition: form-data; name="page_id"

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

_단건 응답: FileUploader 컴포넌트 규약에 맞춰 `data.data` 객체에 업로드된 첨부 1건(`PageAttachmentResource`)이 담깁니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| data.id | integer | `1` | 첨부파일 ID (기본 키) |
| data.hash | string | `"a1b2c3d4e5f6"` | URL용 고유 해시 (12자, 생성 시 자동 부여). 공개 다운로드/미리보기 라우트의 식별자 |
| data.original_filename | string | `"example.pdf"` | 업로드된 원본 파일명 |
| data.mime_type | string | `"application/pdf"` | MIME 타입 (예: `image/jpeg`, `application/pdf`) |
| data.size | integer | `102400` | 파일 크기 (바이트) |
| data.collection | string | `"attachments"` | 첨부 컬렉션 그룹명 (미지정 시 `attachments`) |
| data.order | integer | `0` | 정렬 순서 (0 이상) |
| data.is_image | boolean | `false` | 이미지 파일 여부 (`mime_type` 이 `image/` 로 시작하는지) |
| data.download_url | string | `"/api/modules/sirsoft-page/pages/attachment/a1b2c3d4e5f6"` | 공개 hash 다운로드 URL |
| data.preview_url | string\|null | `null` | 공개 hash 이미지 미리보기 URL (이미지가 아니면 `null`) |
| data.created_at | string\|null | `"2026-07-14 10:30:00"` | 업로드 일시 (사용자 타임존 기준 포맷) |

**응답 예시**

```json
{
    "success": true,
    "message": "첨부파일이 업로드되었습니다.",
    "data": {
        "data": {
            "id": 1,
            "hash": "a1b2c3d4e5f6",
            "original_filename": "example.pdf",
            "mime_type": "application/pdf",
            "size": 102400,
            "collection": "attachments",
            "order": 0,
            "is_image": false,
            "download_url": "/api/modules/sirsoft-page/pages/attachment/a1b2c3d4e5f6",
            "preview_url": null,
            "created_at": "2026-07-14 10:30:00"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). 파일 누락/파일 아님/10MB 초과/허용되지 않은 확장자(`jpg,jpeg,png,gif,webp,pdf,zip,doc,docx,xls,xlsx,ppt,pptx,hwp,txt` 외) |
| 500 | Internal Server Error | 저장 중 예외 발생 시 `첨부파일 업로드에 실패했습니다.` (`error` 에 예외 메시지) |

<!-- @generated:end -->

**설명** 페이지 첨부파일을 업로드합니다. `file`(필수)과 함께 이미 저장된 페이지에 귀속시키려면 `page_id`, 아직 저장 전이면 `temp_key`(임시 귀속 후 페이지 저장 시 `store`/`update` 의 `temp_key` 로 확정)를 보냅니다. `collection` 으로 첨부 그룹을 구분합니다. 응답은 FileUploader 컴포넌트 규약(`data.data`)에 맞춰 `PageAttachmentResource` 를 201 로 반환합니다. 권한은 `pages.create` 를 요구합니다.

업로드 정책(최대 개수 · 최대 용량 · 허용 형식)은 모듈 환경설정 `attachment.*` 가 SSoT 입니다. 용량은 `attachment.max_size_mb`(MB), 형식은 `attachment.allowed_types`(MIME 목록)로 판정하며, 형식 목록을 비우면 형식을 제한하지 않습니다(설정 키 자체가 없으면 모듈 기본 목록으로 폴백). 개수는 `attachment.max_count` 를 기준으로 **직접 업로드 · 임시 업로드(`temp_key`) 연결 · 이미 연결된 첨부** 를 합산해 판정하며, 초과 시 `422`(`errors.code = attachment_limit_exceeded`)를 반환하며, 안내 문구(`message`)는 허용 개수와 시도한 개수를 담아 응답 시점의 언어로 만들어집니다. 이 판정은 업로드 엔드포인트뿐 아니라 페이지 생성·수정 저장 시점에도 동일하게 적용됩니다. `max_count` 가 0 이면 개수를 제한하지 않습니다.


### PATCH /api/modules/sirsoft-page/admin/attachments/reorder
<!-- @generated:start:api.modules.sirsoft-page.admin.attachments.reorder -->
- **라우트명**: `api.modules.sirsoft-page.admin.attachments.reorder`
- **컨트롤러**: `Modules\Sirsoft\Page\Http\Controllers\Admin\PageAttachmentController@reorder`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-page.pages.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | body | array | 예 | min 1 | 새 정렬 순서 배열. 각 원소는 `{"id": 첨부ID(정수), "order": 순서값(0 이상 정수)}` 형태이며, 이 순서대로 첨부 표시 순위가 갱신됩니다 |

**요청 예시**

```http
PATCH /api/modules/sirsoft-page/admin/attachments/reorder HTTP/1.1
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

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만, `data` 는 `null`)._

**응답 예시**

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
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). `order` 누락/배열 아님/빈 배열, 각 원소의 `id`(정수) 또는 `order`(0 이상 정수) 누락 |
| 500 | Internal Server Error | 순서 갱신 중 예외 발생 시 `첨부파일 순서 변경에 실패했습니다.` (`error` 에 예외 메시지) |

<!-- @generated:end -->

**설명** 첨부파일 정렬 순서를 변경합니다. `order` 는 FileUploader 가 보내는 `[{id, order}]` 형태의 배열이며, 컨트롤러가 `[ID => order]` 매핑으로 변환해 `PageAttachmentService::reorder()` 에 전달합니다. 편집 화면에서 첨부 목록을 드래그로 재정렬할 때 사용합니다. 권한은 `pages.update` 를 요구합니다.


### DELETE /api/modules/sirsoft-page/admin/attachments/{id}
<!-- @generated:start:api.modules.sirsoft-page.admin.attachments.destroy -->
- **라우트명**: `api.modules.sirsoft-page.admin.attachments.destroy`
- **컨트롤러**: `Modules\Sirsoft\Page\Http\Controllers\Admin\PageAttachmentController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-page.pages.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-page/admin/attachments/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만, `data` 는 `null`)._

**응답 예시**

```json
{
    "success": true,
    "message": "첨부파일이 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.update`)이 없는 경우 |
| 404 | Not Found | `{id}` 에 해당하는 첨부파일이 없는 경우 (`첨부파일을 찾을 수 없습니다.`) |
| 500 | Internal Server Error | 삭제 실패 또는 삭제 중 예외 발생 시 `첨부파일 삭제에 실패했습니다.` |

<!-- @generated:end -->

**설명** 첨부파일을 삭제합니다. `{id}` 는 첨부파일 ID 입니다(라우트-모델 바인딩이 아닌 `int $id`). `PageAttachmentService::deleteAttachment()` 가 DB 레코드와 실제 저장 파일을 함께 정리합니다. 미존재 시 404 를 반환합니다. 권한은 `pages.update` 를 요구합니다.


