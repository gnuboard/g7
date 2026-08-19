# Uploads API 레퍼런스

> **소유**: plugin `sirsoft-ckeditor5` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 에디터 업로드 이미지 관리(조회·삭제) 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 응답 필드 표 + 응답 예시(envelope)
3. 조회(uploads.read)와 삭제(uploads.delete)는 별도 권한입니다 — 삭제는 파일을 실제로 파기합니다
4. 참조 상태(referenced)는 저장 컬럼이 아니라 조회 시점에 판정한 값입니다
5. 갱신: 코드 변경 후 php artisan api:docgen 재실행
```

---

### GET /api/plugins/sirsoft-ckeditor5/admin/uploads

<!-- @generated:start:api.plugins.sirsoft-ckeditor5.admin.uploads.index -->

- **라우트명**: `api.plugins.sirsoft-ckeditor5.admin.uploads.index`
- **컨트롤러**: `Plugins\Sirsoft\Ckeditor5\Http\Controllers\Admin\ImageUploadAdminController@index`
- **인증/권한**: `auth:sanctum`, `permission:admin,sirsoft-ckeditor5.uploads.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 페이지 번호 (기본 1) |
| per_page | query | integer | 아니오 | 1 ~ 100 | 페이지 크기 (기본 20). 참조 판정을 페이지 단위로 수행하므로 이 값이 곧 판정 비용의 상한이다. |
| search | query | string | 아니오 | max 255 | 원본 파일명 또는 해시 부분 일치 |
| referenced | query | string | 아니오 | `all`, `referenced`, `unreferenced` | 참조 상태 필터 (기본 `all`) |
| date_from | query | date | 아니오 | — | 업로드 시작일 (그날 00:00:00 포함) |
| date_to | query | date | 아니오 | — | 업로드 종료일 (그날 23:59:59 포함) |
| sort_by | query | string | 아니오 | `created_at`, `file_size`, `original_name` | 정렬 컬럼 (기본 `created_at`). 목록 밖 컬럼은 422 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (기본 `desc`) |

**요청 예시**

```http
GET /api/plugins/sirsoft-ckeditor5/admin/uploads?referenced=unreferenced&per_page=20 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| data[].id | integer | `12` | 업로드 기록 ID (삭제 요청의 대상 식별자) |
| data[].hash | string | `a1b2c3d4e5f6` | URL용 고유 해시 12자리 hex. 본문에 API 폴백형 URL 로 삽입될 때 이 값이 들어간다 |
| data[].original_name | string | `banner.png` | 업로드 당시 원본 파일명 |
| data[].file_size | integer | `78310` | 파일 크기 (bytes) |
| data[].mime_type | string | `image/png` | MIME 타입 |
| data[].uploaded_by | integer\|null | `1` | 업로드한 사용자 ID (탈퇴·미상이면 null) |
| data[].uploader_name | string\|null | `관리자` | 업로더 표시명. 관계가 로드된 경우에만 채워진다 |
| data[].created_at | string | `2026-08-14 19:20:11` | 업로드 일시 (요청자 시간대 기준 포맷) |
| data[].download_url | string | `/api/plugins/sirsoft-ckeditor5/images/a1b2c3d4e5f6` | 이미지 서빙 URL. 공개 자산 디스크가 직접 URL 을 지원하면 CDN 주소가 반환된다 |
| data[].referenced | boolean | `false` | **조회 시점 판정값**(저장 컬럼 아님). 등록된 참조 소스 본문에 hash 또는 저장 파일명이 등장하면 true. 판정 불가 행은 안전 방향인 true 로 본다 |
| pagination.current_page | integer | `1` | 현재 페이지 |
| pagination.last_page | integer | `1` | 마지막 페이지 |
| pagination.per_page | integer | `20` | 페이지 크기 |
| pagination.total | integer | `3` | 총 건수 |
| pagination.from | integer\|null | `1` | 현재 페이지 첫 항목 순번 (비어 있으면 null) |
| pagination.to | integer\|null | `3` | 현재 페이지 마지막 항목 순번 (비어 있으면 null) |
| meta.scan_limited | boolean | `false` | 참조 상태 필터가 스캔 윈도우 상한에 걸렸는지. true 면 그보다 오래된 이미지는 목록에 포함되지 않는다 |
| meta.scan_window | integer | `500` | 참조 상태 필터가 훑는 최신 업로드 건수 상한 |
| meta.reference_sources_incomplete | boolean | `false` | 설치돼 있으나 비활성인 모듈이 있어 참조 판정이 불완전할 수 있는지. true 면 그 모듈 콘텐츠에서만 쓰이는 이미지가 「미참조」로 오판될 수 있으므로 화면은 경고를 표시해야 한다 |

**응답 예시**

```json
{
    "success": true,
    "message": "요청이 성공적으로 처리되었습니다.",
    "data": {
        "data": [
            {
                "id": 12,
                "hash": "a1b2c3d4e5f6",
                "original_name": "banner.png",
                "file_size": 78310,
                "mime_type": "image/png",
                "uploaded_by": 1,
                "uploader_name": "관리자",
                "created_at": "2026-08-14 19:20:11",
                "download_url": "/api/plugins/sirsoft-ckeditor5/images/a1b2c3d4e5f6",
                "referenced": false
            }
        ],
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 20,
            "total": 1,
            "from": 1,
            "to": 1
        },
        "meta": {
            "scan_limited": false,
            "scan_window": 500,
            "reference_sources_incomplete": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | `sirsoft-ckeditor5.uploads.read` 권한이 없는 경우 |
| 422 | Unprocessable Entity | 허용 목록 밖 `sort_by`/`sort_order`/`referenced`, `per_page` 범위 초과, 날짜 형식 오류 |

<!-- @generated:end -->

**설명**

에디터로 업로드된 이미지 목록을 관리 화면에 제공하는 엔드포인트다.

- **참조 상태는 저장하지 않는다.** 참조 관계는 콘텐츠가 편집될 때마다 바뀌므로 스냅샷을 저장하면 "저장 당시엔 미참조였던" 이미지를 이후에 오판 삭제할 수 있다. 대신 판정 비용에 상한을 둔다 — 기본 목록은 현재 페이지(≤100행)만 판정하고, 참조 상태 필터를 쓸 때만 최신 `meta.scan_window` 건을 훑는다.
- **`meta.scan_limited` 가 true 면 목록이 전수가 아니다.** 화면은 이 값을 근거로 "최근 N건 기준" 안내를 표시해야 한다. 이 값을 무시하면 운영자가 "미참조 이미지가 이게 전부" 로 오해한다.
- 판정 토큰은 `hash` 와 저장 파일명 둘 다이다. 본문에 박히는 URL 이 API 폴백형(hash 포함)과 디스크 직접 URL(저장 파일명 포함) 두 형태이기 때문이며, 한쪽만 검사하면 다른 형태로 저장된 이미지를 미참조로 오판한다.

---

### DELETE /api/plugins/sirsoft-ckeditor5/admin/uploads/{id}

<!-- @generated:start:api.plugins.sirsoft-ckeditor5.admin.uploads.destroy -->

- **라우트명**: `api.plugins.sirsoft-ckeditor5.admin.uploads.destroy`
- **컨트롤러**: `Plugins\Sirsoft\Ckeditor5\Http\Controllers\Admin\ImageUploadAdminController@destroy`
- **인증/권한**: `auth:sanctum`, `permission:admin,sirsoft-ckeditor5.uploads.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자만 | 삭제할 업로드 기록 ID |

**요청 예시**

```http
DELETE /api/plugins/sirsoft-ckeditor5/admin/uploads/12 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

성공 시 `data` 는 `null` 이며, 결과는 `message` 로만 전달된다.

**응답 예시**

```json
{
    "success": true,
    "message": "이미지를 삭제했습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | `sirsoft-ckeditor5.uploads.delete` 권한이 없는 경우 (조회 권한만으로는 삭제 불가) |
| 404 | Not Found | 해당 ID 의 업로드 기록이 없는 경우 |
| 500 | Internal Server Error | 물리 파일이 존재하는데 삭제에 실패한 경우 — 기록은 보존되며 다음 시도에서 재시도할 수 있다 |

<!-- @generated:end -->

**설명**

업로드 이미지 1건을 파일과 기록까지 삭제한다.

- **삭제 순서는 파일 먼저, 기록 나중이다.** 반대로 하면 기록이 사라진 파일은 어떤 경로로도 다시 찾을 수 없어 영구 고아가 된다.
- 파일이 이미 없는 행은 고아 기록으로 보고 기록만 지운다. 그러지 않으면 매 회차 같은 행을 재시도하는 루프가 된다.
- 행마다 `storage_disk` 가 다를 수 있으므로 삭제는 그 행에 기록된 디스크를 향해 수행된다(디스크 전환 이후 혼재 대응).
- 이 경로는 정리 커맨드(`sirsoft-ckeditor5:prune-unused-images`)와 **같은 삭제 구현을 공유**한다 — 화면 삭제와 자동 정리의 동작이 갈리지 않게 하기 위함이다.

---

### POST /api/plugins/sirsoft-ckeditor5/admin/uploads/bulk-delete

<!-- @generated:start:api.plugins.sirsoft-ckeditor5.admin.uploads.bulk-delete -->

- **라우트명**: `api.plugins.sirsoft-ckeditor5.admin.uploads.bulk-delete`
- **컨트롤러**: `Plugins\Sirsoft\Ckeditor5\Http\Controllers\Admin\ImageUploadAdminController@bulkDestroy`
- **인증/권한**: `auth:sanctum`, `permission:admin,sirsoft-ckeditor5.uploads.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | 1 ~ 100개 | 삭제할 업로드 기록 ID 목록 |
| ids.* | body | integer | 예 | 존재하는 업로드 ID | 각 항목은 실재하는 기록이어야 한다 (아니면 422) |

**요청 예시**

```http
POST /api/plugins/sirsoft-ckeditor5/admin/uploads/bulk-delete HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [12, 13, 14]
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted | integer | `3` | 파일·기록이 함께 삭제된 건수 |
| failed | integer | `0` | 물리 파일 삭제 실패로 기록을 보존한 건수 (다음 시도에서 재시도 가능) |

**응답 예시**

```json
{
    "success": true,
    "message": "선택한 이미지 3건을 삭제했습니다.",
    "data": {
        "deleted": 3,
        "failed": 0
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | `sirsoft-ckeditor5.uploads.delete` 권한이 없는 경우 |
| 422 | Unprocessable Entity | `ids` 누락/빈 배열/100개 초과, 또는 존재하지 않는 ID 포함 |
| 500 | Internal Server Error | 요청한 전부가 파일 삭제에 실패한 경우 (일부만 실패하면 200 + `failed` 로 보고) |

<!-- @generated:end -->

**설명**

관리 화면에서 체크한 이미지를 한 번에 삭제한다.

- 선택 범위는 **화면에 보이는 페이지**로 한정된다(화면 계약). 검색·필터·페이지 이동으로 목록에서 빠진 행의 선택이 남아 대상에 실리면, 운영자가 보지도 체크하지도 않은 파일이 지워지기 때문이다.
- 일부만 실패한 경우 요청 자체는 성공(200)으로 응답하고 `failed` 로 보고하며, 응답 메시지도 "N건 삭제, M건 실패" 형태(`messages.uploads.bulk_partially_deleted`)로 실패 사실을 함께 안내한다. 전부 실패한 경우에만 500 이다 — 부분 성공을 실패로 되돌릴 방법이 없기 때문이다.
