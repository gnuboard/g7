# Attachments API 레퍼런스

> **소유**: module `sirsoft-page` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Attachments 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
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
| file | body | file | 예 | max 10240 | 업로드 파일 |
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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 페이지 첨부파일을 업로드합니다(최대 10MB). `file`(필수)과 함께 이미 저장된 페이지에 귀속시키려면 `page_id`, 아직 저장 전이면 `temp_key`(임시 귀속 후 페이지 저장 시 `store`/`update` 의 `temp_key` 로 확정)를 보냅니다. `collection` 으로 첨부 그룹을 구분합니다. 응답은 FileUploader 컴포넌트 규약(`data.data`)에 맞춰 `PageAttachmentResource` 를 201 로 반환합니다. 권한은 `pages.create` 를 요구합니다.


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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 첨부파일을 삭제합니다. `{id}` 는 첨부파일 ID 입니다(라우트-모델 바인딩이 아닌 `int $id`). `PageAttachmentService::deleteAttachment()` 가 DB 레코드와 실제 저장 파일을 함께 정리합니다. 미존재 시 404 를 반환합니다. 권한은 `pages.update` 를 요구합니다.


