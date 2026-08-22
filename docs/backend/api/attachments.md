# Attachments API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Attachments 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/admin/attachments
<!-- @generated:start:api.admin.attachments.upload -->
- **라우트명**: `api.admin.attachments.upload`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\AttachmentController@upload`
- **인증/권한**: `auth:sanctum` + `permission:core.attachments.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| file | body | file | 예 | max 10240 | 업로드 파일 |
| attachmentable_type | body | string | 아니오 | max 255 | 첨부를 연결할 대상 모델의 다형성 타입 (attachmentable morph type, 예 User·Post 등 모델 클래스명). attachmentable_id와 짝을 이뤄 대상을 지정하며 미지정 시 미연결 상태로 저장 |
| attachmentable_id | body | integer | 아니오 | min 1 | attachmentable 식별자 |
| collection | body | string | 아니오 | max 100 | 첨부 컬렉션 그룹명 (첨부를 용도별로 묶는 키, 미지정 시 default) |
| source_type | body | string | 아니오 | — | 첨부 생성 출처 구분 (AttachmentSourceType Enum — core: 코어 시스템, module: 모듈, plugin: 플러그인). 미지정 시 core로 기본 설정 |
| source_identifier | body | string | 아니오 | max 255 | 출처 식별자 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.attachment.upload_validation_rules`, `core.attachment.allowed_extensions`).

**요청 예시**

```http
POST /api/admin/attachments HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: multipart/form-data; boundary=----G7ExampleBoundary

------G7ExampleBoundary
Content-Disposition: form-data; name="file"; filename="example.pdf"
Content-Type: application/octet-stream

(바이너리 파일 내용)
------G7ExampleBoundary
Content-Disposition: form-data; name="attachmentable_type"

예시값
------G7ExampleBoundary
Content-Disposition: form-data; name="attachmentable_id"

1
------G7ExampleBoundary
Content-Disposition: form-data; name="collection"

예시값
------G7ExampleBoundary
Content-Disposition: form-data; name="source_type"

예시값
------G7ExampleBoundary
Content-Disposition: form-data; name="source_identifier"

example-key
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`AttachmentResource`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 첨부파일 ID (기본 키) |
| hash | string | `"aB3xY9kLmQ7z"` | URL용 고유 해시 (12자). 다운로드 URL 식별자로 사용 |
| original_filename | string | `"example.pdf"` | 업로드된 원본 파일명 |
| mime_type | string | `"application/pdf"` | MIME 타입 (예: `image/jpeg`, `application/pdf`) |
| size | integer | `102400` | 파일 크기 (바이트) |
| size_formatted | string | `"100 KB"` | 사람이 읽기 쉬운 형식으로 포맷된 파일 크기 (B/KB/MB/GB) |
| collection | string | `"default"` | 첨부파일 컬렉션/그룹명 (미지정 시 `default`) |
| order | integer | `0` | 컬렉션 내 정렬 순서 |
| download_url | string | `"/api/attachment/aB3xY9kLmQ7z"` | 해시 기반 다운로드 경로 |
| is_image | boolean | `false` | MIME 타입이 `image/` 로 시작하는지 여부 |
| meta | object\|null | `{"width": 800, "height": 600}` | 추가 메타데이터. 이미지 업로드 시 width/height 자동 기록, 그 외에는 빈 객체 |
| source_type | string | `"core"` | 첨부 생성 출처 (`core` / `module` / `plugin`) |
| source_identifier | string\|null | `"sirsoft-board"` | 모듈/플러그인 식별자 (코어 업로드 시 `null`) |
| creator | object\|없음 | `{"uuid": "...", "name": "관리자"}` | 업로더 정보(`uuid`, `name`). `creator` 관계가 eager load 된 경우에만 포함되며, 업로드 응답에는 포함되지 않음 |
| created_at | string | `"2026-07-14 10:23:41"` | 생성 일시 (사용자 타임존 기준 `Y-m-d H:i:s`) |
| updated_at | string | `"2026-07-14 10:23:41"` | 수정 일시 (사용자 타임존 기준 `Y-m-d H:i:s`) |
| is_owner | boolean | `true` | 요청자가 업로더(`created_by`)인지 여부 |
| abilities | object | `{"can_update": true, "can_delete": true}` | 요청자의 권한 맵 (`can_update` = `core.attachments.update`, `can_delete` = `core.attachments.delete`) |

**응답 예시**

```json
{
    "success": true,
    "message": "파일이 업로드되었습니다.",
    "data": {
        "id": 1,
        "hash": "aB3xY9kLmQ7z",
        "original_filename": "example.pdf",
        "mime_type": "application/pdf",
        "size": 102400,
        "size_formatted": "100 KB",
        "collection": "default",
        "order": 0,
        "download_url": "/api/attachment/aB3xY9kLmQ7z",
        "is_image": false,
        "meta": {},
        "source_type": "core",
        "source_identifier": null,
        "created_at": "2026-07-14 10:23:41",
        "updated_at": "2026-07-14 10:23:41",
        "is_owner": true,
        "abilities": {
            "can_update": true,
            "can_delete": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.attachments.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 파일 저장/DB 기록 중 예외 발생 시 (`attachment.upload_failed` — "파일 업로드에 실패했습니다.") |

<!-- @generated:end -->

**설명** 단일 파일을 업로드해 첨부파일(Attachment) 레코드로 등록합니다. `attachmentable_type`/`attachmentable_id`로 대상 모델과의 다형성 연결을, `collection`으로 그룹을 지정하며 미지정 시 각각 미연결·`default` 컬렉션으로 저장됩니다. `core.attachments.create` 권한이 필요하며, 성공 시 201과 함께 생성된 첨부파일 리소스를 반환합니다. 확장은 `core.attachment.upload_validation_rules` 훅으로 검증 규칙을 추가할 수 있고, `source_type`/`source_identifier`로 업로드 출처(코어/확장)를 식별합니다.


### POST /api/admin/attachments/batch
<!-- @generated:start:api.admin.attachments.upload_batch -->
- **라우트명**: `api.admin.attachments.upload_batch`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\AttachmentController@uploadBatch`
- **인증/권한**: `auth:sanctum` + `permission:core.attachments.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| files | body | array | 예 | min 1 | 업로드 파일 배열 |
| attachmentable_type | body | string | 아니오 | max 255 | 첨부를 연결할 대상 모델의 다형성 타입 (attachmentable morph type, 예 User·Post 등 모델 클래스명). attachmentable_id와 짝을 이뤄 대상을 지정하며 미지정 시 미연결 상태로 저장 |
| attachmentable_id | body | integer | 아니오 | min 1 | attachmentable 식별자 |
| collection | body | string | 아니오 | max 100 | 첨부 컬렉션 그룹명 (첨부를 용도별로 묶는 키, 미지정 시 default) |
| source_type | body | string | 아니오 | — | 첨부 생성 출처 구분 (AttachmentSourceType Enum — core: 코어 시스템, module: 모듈, plugin: 플러그인). 미지정 시 core로 기본 설정 |
| source_identifier | body | string | 아니오 | max 255 | 출처 식별자 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.attachment.upload_batch_validation_rules`, `core.attachment.allowed_extensions`).

**요청 예시**

```http
POST /api/admin/attachments/batch HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "files": [
        "예시값"
    ],
    "attachmentable_type": "예시값",
    "attachmentable_id": 1,
    "collection": "예시값",
    "source_type": "예시값",
    "source_identifier": "example-key"
}
```

**응답 필드** (`data` 내부)

_배열 응답: `data` 는 `AttachmentResource` 객체의 배열입니다 (페이지네이션 없음). 각 항목의 필드는 단일 업로드 응답과 동일합니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| data[].id | integer | `1` | 첨부파일 ID (기본 키) |
| data[].hash | string | `"aB3xY9kLmQ7z"` | URL용 고유 해시 (12자) |
| data[].original_filename | string | `"photo1.jpg"` | 업로드된 원본 파일명 |
| data[].mime_type | string | `"image/jpeg"` | MIME 타입 |
| data[].size | integer | `204800` | 파일 크기 (바이트) |
| data[].size_formatted | string | `"200 KB"` | 포맷된 파일 크기 |
| data[].collection | string | `"default"` | 첨부파일 컬렉션/그룹명 |
| data[].order | integer | `0` | 컬렉션 내 정렬 순서 (배치 내 파일마다 순차 증가) |
| data[].download_url | string | `"/api/attachment/aB3xY9kLmQ7z"` | 해시 기반 다운로드 경로 |
| data[].is_image | boolean | `true` | MIME 타입이 `image/` 로 시작하는지 여부 |
| data[].meta | object\|null | `{"width": 1920, "height": 1080}` | 이미지 업로드 시 width/height 자동 기록 |
| data[].source_type | string | `"core"` | 첨부 생성 출처 (`core` / `module` / `plugin`) |
| data[].source_identifier | string\|null | `null` | 모듈/플러그인 식별자 |
| data[].created_at | string | `"2026-07-14 10:23:41"` | 생성 일시 (사용자 타임존 기준) |
| data[].updated_at | string | `"2026-07-14 10:23:41"` | 수정 일시 (사용자 타임존 기준) |
| data[].is_owner | boolean | `true` | 요청자가 업로더인지 여부 |
| data[].abilities | object | `{"can_update": true, "can_delete": true}` | 요청자의 권한 맵 |

**응답 예시**

```json
{
    "success": true,
    "message": "파일이 일괄 업로드되었습니다.",
    "data": [
        {
            "id": 1,
            "hash": "aB3xY9kLmQ7z",
            "original_filename": "photo1.jpg",
            "mime_type": "image/jpeg",
            "size": 204800,
            "size_formatted": "200 KB",
            "collection": "default",
            "order": 0,
            "download_url": "/api/attachment/aB3xY9kLmQ7z",
            "is_image": true,
            "meta": {
                "width": 1920,
                "height": 1080
            },
            "source_type": "core",
            "source_identifier": null,
            "created_at": "2026-07-14 10:23:41",
            "updated_at": "2026-07-14 10:23:41",
            "is_owner": true,
            "abilities": {
                "can_update": true,
                "can_delete": true
            }
        },
        {
            "id": 2,
            "hash": "Zq1WeRt5YuIo",
            "original_filename": "photo2.jpg",
            "mime_type": "image/jpeg",
            "size": 153600,
            "size_formatted": "150 KB",
            "collection": "default",
            "order": 1,
            "download_url": "/api/attachment/Zq1WeRt5YuIo",
            "is_image": true,
            "meta": {
                "width": 1280,
                "height": 720
            },
            "source_type": "core",
            "source_identifier": null,
            "created_at": "2026-07-14 10:23:41",
            "updated_at": "2026-07-14 10:23:41",
            "is_owner": true,
            "abilities": {
                "can_update": true,
                "can_delete": true
            }
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.attachments.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 배치 중 파일 저장/DB 기록 예외 발생 시 (`attachment.upload_failed` — "파일 업로드에 실패했습니다.") |

<!-- @generated:end -->

**설명** 여러 파일을 한 번의 요청으로 일괄 업로드합니다. `files` 배열의 각 파일이 개별 첨부파일 레코드로 등록되며, `attachmentable_type`/`attachmentable_id`/`collection` 등의 옵션은 배치 전체에 공통 적용됩니다. `core.attachments.create` 권한이 필요하고, 성공 시 201과 함께 생성된 첨부파일 리소스 컬렉션을 반환합니다. 갤러리·다중 이미지 첨부처럼 한 대상에 여러 파일을 붙이는 시나리오에 사용합니다.


### PATCH /api/admin/attachments/reorder
<!-- @generated:start:api.admin.attachments.reorder -->
- **라우트명**: `api.admin.attachments.reorder`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\AttachmentController@reorder`
- **인증/권한**: `auth:sanctum` + `permission:core.attachments.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| order | body | array | 예 | min 1 | 재정렬 대상 목록. 각 원소는 `id`(기존 첨부파일 식별자)와 `order`(새 정렬 순서값, 0 이상 정수)를 가진 객체이며, 이 매핑대로 각 첨부의 정렬 값이 갱신됨 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.attachment.reorder_validation_rules`).

**요청 예시**

```http
PATCH /api/admin/attachments/reorder HTTP/1.1
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

_이 엔드포인트는 데이터를 반환하지 않습니다 (`data` 는 `null`, 성공 메시지만 반환)._

**응답 예시**

```json
{
    "success": true,
    "message": "파일 순서가 변경되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.attachments.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). `order[].id` 가 존재하지 않는 첨부파일이면 "존재하지 않는 첨부파일입니다." |
| 500 | Internal Server Error | 순서 갱신 중 예외 발생 시 (`attachment.reorder_failed` — "파일 순서 변경에 실패했습니다.") |

<!-- @generated:end -->

**설명** 첨부파일의 표시 순서를 재정렬합니다. `order` 배열에 담긴 순서대로 각 첨부파일의 정렬 값이 갱신됩니다. `core.attachments.update` 권한이 필요합니다. 갤러리에서 드래그 앤 드롭으로 이미지 순서를 바꾸는 등 이미 등록된 첨부파일의 나열 순서만 변경할 때 사용합니다.


### DELETE /api/admin/attachments/{attachment}
<!-- @generated:start:api.admin.attachments.destroy -->
- **라우트명**: `api.admin.attachments.destroy`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\AttachmentController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:core.attachments.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| attachment | path | string | 예 | — | 대상 attachment의 식별자 |

**요청 예시**

```http
DELETE /api/admin/attachments/{attachment} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 데이터를 반환하지 않습니다 (`data` 는 `null`, 성공 메시지만 반환)._

**응답 예시**

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
| 400 | Bad Request | 삭제 처리가 실패로 반환된 경우 (`attachment.delete_failed` — "파일 삭제에 실패했습니다.") |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.attachments.delete`)이 없는 경우 |
| 404 | Not Found | `{attachment}` 에 해당하는 첨부파일이 없는 경우 (라우트 모델 바인딩 실패) |
| 500 | Internal Server Error | 파일/DB 삭제 중 예외 발생 시 (`attachment.delete_failed` — "파일 삭제에 실패했습니다.") |

<!-- @generated:end -->

**설명** 지정한 첨부파일을 삭제합니다. 경로의 `{attachment}`는 라우트 모델 바인딩으로 첨부파일 ID를 받으며, 서비스가 DB 레코드와 실제 저장 파일을 함께 제거합니다. `core.attachments.delete` 권한이 필요합니다. 존재하지 않는 ID면 404가 반환됩니다.


