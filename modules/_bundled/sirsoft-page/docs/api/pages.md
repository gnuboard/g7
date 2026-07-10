# Pages API 레퍼런스

> **소유**: module `sirsoft-page` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Pages 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-page/admin/pages
<!-- @generated:start:api.modules.sirsoft-page.admin.pages.index -->
- **라우트명**: `api.modules.sirsoft-page.admin.pages.index`
- **컨트롤러**: `Modules\Sirsoft\Page\Http\Controllers\Admin\PageController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-page.pages.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| published | query | boolean | 아니오 | — | 발행 여부 (발행된 항목만 필터) |
| search | query | string | 아니오 | max 100 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| search_field | query | string | 아니오 | `all`, `title`, `slug` | 검색 대상 필드명 (검색어를 적용할 컬럼) |
| filters | query | array | 아니오 | — | 추가 필터 조건 맵 (필드별 조건) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| sort_by | query | string | 아니오 | `created_at`, `published_at` | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |

**요청 예시**

```http
GET /api/modules/sirsoft-page/admin/pages?published=1&search=%EC%98%88%EC%8B%9C%EA%B0%92&search_field=all&filters=%EC%98%88%EC%8B%9C%EA%B0%92&per_page=1&page=1&sort_by=created_at&sort_order=asc HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `29` | 기본 키 (내부 식별자) |
| slug | string | `apidoc-sample-page` | URL 슬러그 (고유) |
| title | string | `API 문서 샘플 페이지` | 페이지 제목 (다국어 JSON) |
| published | boolean | `true` | 발행 여부 (true: 발행, false: 미발행) |
| published_at | string | `2026-07-06 23:57:18` | published 일시 |
| current_version | integer | `2` | 현재 버전 번호 |
| creator | object | `{"uuid":"a231747f-e82e-4cf2-9ae1-a261849dce40","name":"AP…` | 생성자 정보 객체 (uuid/name/email — creator 관계 파생) |
| created_at | string | `2026-07-06 23:57:18` | 생성 일시 |
| updated_at | string | `2026-07-06 23:57:18` | 최종 수정 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "페이지 정보를 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 10,
                "slug": "apidoc-sample-page",
                "title": "API 문서 샘플 페이지",
                "published": true,
                "published_at": "2026-07-08 10:51:59",
                "current_version": 2,
                "creator": {
                    "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                    "name": "API 문서 샘플 사용자"
                },
                "created_at": "2026-07-08 10:51:59",
                "updated_at": "2026-07-08 10:51:59",
                "is_owner": true,
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true
                }
            },
            {
                "id": 4,
                "slug": "terms",
                "title": "이용약관",
                "published": true,
                "published_at": "2026-07-08 10:44:43",
                "current_version": 1,
                "creator": null,
                "created_at": "2026-07-08 10:44:43",
                "updated_at": "2026-07-08 10:44:43",
                "is_owner": false,
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true
                }
            },
            "... (총 7건 중 2건 표시)"
        ],
        "meta": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 7
        },
        "abilities": {
            "can_create": true,
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
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자 페이지 목록을 조회합니다. `published`, `search`/`search_field`(제목·슬러그), `filters` 로 필터링하고 `sort_by`(created_at, published_at)·`sort_order` 로 정렬하며 `per_page`·`page` 로 페이지네이션합니다. `PageService::getPages()` 가 반환하는 `PageCollection` 으로 래핑되어 목록 항목마다 `is_owner`·`abilities` 권한 메타가 함께 내려갑니다. 관리자 페이지 관리 화면(목록 그리드)의 데이터 소스입니다.


### POST /api/modules/sirsoft-page/admin/pages
<!-- @generated:start:api.modules.sirsoft-page.admin.pages.store -->
- **라우트명**: `api.modules.sirsoft-page.admin.pages.store`
- **컨트롤러**: `Modules\Sirsoft\Page\Http\Controllers\Admin\PageController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-page.pages.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | body | string | 예 | max 255 | URL 친화 식별자 (slug) |
| title | body | array | 예 | — | 제목 |
| content | body | string | 아니오 | — | 본문 내용 |
| content_mode | body | string | 아니오 | `html`, `text` | 본문 편집 모드. `html` 은 리치 에디터 HTML, `text` 는 평문으로 저장·렌더링됩니다 (미지정 시 `html`) |
| published | body | boolean | 아니오 | — | 발행 여부 (발행된 항목만 필터) |
| seo_meta | body | array | 아니오 | — | SEO 메타 정보 맵. 하위 키 `title`(max 255)·`description`(max 500)·`keywords`(max 500)를 담습니다 |
| temp_key | body | string | 아니오 | max 64 | 저장 전 첨부 업로드 시 발급받은 임시 키. 생성된 페이지에 임시 첨부를 귀속시키는 데 사용합니다 |

**요청 예시**

```http
POST /api/modules/sirsoft-page/admin/pages HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "slug": "example-key",
    "title": [
        "예시 제목"
    ],
    "content": "예시 내용입니다.",
    "content_mode": "html",
    "published": true,
    "seo_meta": [
        "예시값"
    ],
    "temp_key": "예시값"
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
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 새 페이지를 생성합니다. `slug`(고유 필수)·`title`(다국어 배열 필수)·`content`·`content_mode`(html/text)·`published`·`seo_meta` 를 받아 `PageService::createPage()` 로 저장하고, `creator`/`updater`/`attachments` 를 eager load 한 `PageResource` 를 201 로 반환합니다. `temp_key` 는 페이지 저장 전 임시 업로드한 첨부(첨부 업로드 시 발급받은 키)를 생성된 페이지에 귀속시키는 데 씁니다.


### PATCH /api/modules/sirsoft-page/admin/pages/bulk-publish
<!-- @generated:start:api.modules.sirsoft-page.admin.pages.bulk-publish -->
- **라우트명**: `api.modules.sirsoft-page.admin.pages.bulk-publish`
- **컨트롤러**: `Modules\Sirsoft\Page\Http\Controllers\Admin\PageController@bulkPublish`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-page.pages.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |
| published | body | boolean | 예 | — | 발행 여부 (발행된 항목만 필터) |

**요청 예시**

```http
PATCH /api/modules/sirsoft-page/admin/pages/bulk-publish HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        "예시값"
    ],
    "published": true
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

**설명** 여러 페이지의 발행 상태를 한 번에 변경합니다. `ids`(대상 페이지 ID 배열, 최소 1개)와 `published`(true=발행, false=미발행)를 받아 `PageService::bulkChangePublishStatus()` 로 일괄 적용하고, 실제 변경된 건수를 `count` 로 반환합니다. 목록 화면의 다중 선택 후 일괄 발행/미발행 액션에 사용합니다.


### POST /api/modules/sirsoft-page/admin/pages/check-slug
<!-- @generated:start:api.modules.sirsoft-page.admin.pages.check-slug -->
- **라우트명**: `api.modules.sirsoft-page.admin.pages.check-slug`
- **컨트롤러**: `Modules\Sirsoft\Page\Http\Controllers\Admin\PageController@checkSlug`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-page.pages.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | body | string | 예 | max 255 | URL 친화 식별자 (slug) |
| exclude_id | body | integer | 아니오 | min 1 | exclude 식별자 |

**요청 예시**

```http
POST /api/modules/sirsoft-page/admin/pages/check-slug HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "slug": "example-key",
    "exclude_id": 1
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
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 슬러그 중복 여부를 확인합니다. `slug`(필수)와 선택 `exclude_id`(수정 화면에서 자기 자신을 중복 검사에서 제외)를 받아 `{ "exists": true|false }` 를 반환합니다. 페이지 생성/수정 폼에서 슬러그 입력 시 실시간 중복 검증에 사용합니다. 권한은 `pages.create` 를 요구합니다.


### DELETE /api/modules/sirsoft-page/admin/pages/{page}
<!-- @generated:start:api.modules.sirsoft-page.admin.pages.destroy -->
- **라우트명**: `api.modules.sirsoft-page.admin.pages.destroy`
- **컨트롤러**: `Modules\Sirsoft\Page\Http\Controllers\Admin\PageController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-page.pages.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | path | string | 예 | — | 조회할 페이지 번호 (1부터 시작) |

**요청 예시**

```http
DELETE /api/modules/sirsoft-page/admin/pages/10 HTTP/1.1
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
    "message": "페이지가 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 페이지를 소프트 삭제합니다. `{page}` 는 페이지 ID 입니다(라우트-모델 바인딩이 아닌 `int $id` 로 서비스에서 조회). 존재하지 않으면 404, 스코프 권한 밖이면 403 을 반환합니다. 삭제는 DB CASCADE 가 아니라 `PageService::deletePage()` 를 통해 수행되어 관련 훅·정리 로직이 보장됩니다. 권한은 `pages.delete` 를 요구합니다.


### GET /api/modules/sirsoft-page/admin/pages/{page}
<!-- @generated:start:api.modules.sirsoft-page.admin.pages.show -->
- **라우트명**: `api.modules.sirsoft-page.admin.pages.show`
- **컨트롤러**: `Modules\Sirsoft\Page\Http\Controllers\Admin\PageController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-page.pages.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | path | string | 예 | — | 조회할 페이지 번호 (1부터 시작) |

**요청 예시**

```http
GET /api/modules/sirsoft-page/admin/pages/10 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `29` | 기본 키 (내부 식별자) |
| slug | string | `apidoc-sample-page` | URL 슬러그 (고유) |
| title | object | `{"ko":"API 문서 샘플 페이지","en":"API Doc Sample Page"}` | 페이지 제목 (다국어 JSON) |
| content | object | `{"ko":"<p>API 레퍼런스 실측용 완전 샘플 페이지 본문입니다.<\/p>","en":"<p>Co…` | 페이지 본문 (다국어 JSON) |
| content_mode | string | `html` | 본문 형식 (html, text) |
| published | boolean | `true` | 발행 여부 (true: 발행, false: 미발행) |
| published_at | string | `2026-07-06 23:57:18` | published 일시 |
| seo_meta | object | `{"title":"Et qui sapiente veritatis.","description":"Et m…` | SEO 메타 정보 (title, description, keywords) |
| current_version | integer | `2` | 현재 버전 번호 |
| creator | object | `{"uuid":"a231747f-e82e-4cf2-9ae1-a261849dce40","name":"AP…` | 생성자 정보 객체 (uuid/name/email — creator 관계 파생) |
| updater | object | `{"uuid":"a231747f-e82e-4cf2-9ae1-a261849dce40","name":"AP…` | 최종 수정자 정보 객체 (uuid/name — updater 관계 파생, 로드된 경우에만 포함) |
| attachments | array | `[{"id":9,"hash":"xtfalwomchas","original_filename":"deser…` | 페이지에 귀속된 첨부파일 목록 (PageAttachmentResource 배열 — id/hash/원본파일명/URL 등, 로드된 경우에만 포함) |
| created_at | string | `2026-07-06 23:57:18` | 생성 일시 |
| updated_at | string | `2026-07-06 23:57:18` | 최종 수정 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "페이지 정보를 조회했습니다.",
    "data": {
        "id": 10,
        "slug": "apidoc-sample-page",
        "title": {
            "ko": "API 문서 샘플 페이지",
            "en": "API Doc Sample Page"
        },
        "content": {
            "ko": "<p>API 레퍼런스 실측용 완전 샘플 페이지 본문입니다.</p>",
            "en": "<p>Complete sample page body for API reference probing.</p>"
        },
        "content_mode": "html",
        "published": true,
        "published_at": "2026-07-08 10:51:59",
        "seo_meta": {
            "title": "Dolorum et aut officia ipsam doloribus inventore.",
            "description": "Sint ipsa impedit enim natus tempore quasi dignissimos praesentium numquam rerum tempore nam.",
            "keywords": "illo,pariatur,quis,necessitatibus,aperiam"
        },
        "current_version": 2,
        "creator": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자"
        },
        "updater": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자"
        },
        "attachments": [
            {
                "id": 4,
                "hash": "ninrdtsmhhta",
                "original_filename": "nesciunt.jpg",
                "mime_type": "image/jpeg",
                "size": 7124557,
                "collection": "attachments",
                "order": 0,
                "is_image": true,
                "download_url": "/api/modules/sirsoft-page/pages/attachment/ninrdtsmhhta",
                "preview_url": "/api/modules/sirsoft-page/pages/attachment/ninrdtsmhhta/preview",
                "created_at": "2026-07-08 10:51:59"
            }
        ],
        "created_at": "2026-07-08 10:51:59",
        "updated_at": "2026-07-08 10:51:59",
        "is_owner": true,
        "abilities": {
            "can_create": true,
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
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자용 페이지 상세를 조회합니다. `{page}` 는 페이지 ID 입니다. `creator`·`updater`·`attachments` 관계를 eager load 한 `PageResource` 를 반환하며, 목록과 달리 `content`(다국어 본문)·`seo_meta` 전체를 포함합니다. 페이지 편집 화면 진입 시 폼 초기값 로딩에 사용합니다. 미존재 404, 스코프 밖 403.


### PUT /api/modules/sirsoft-page/admin/pages/{page}
<!-- @generated:start:api.modules.sirsoft-page.admin.pages.update -->
- **라우트명**: `api.modules.sirsoft-page.admin.pages.update`
- **컨트롤러**: `Modules\Sirsoft\Page\Http\Controllers\Admin\PageController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-page.pages.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | path | string | 예 | — | 조회할 페이지 번호 (1부터 시작) |
| slug | body | string | 아니오 | max 255 | URL 친화 식별자 (slug) |
| title | body | array | 예 | — | 제목 |
| content | body | string | 아니오 | — | 본문 내용 |
| content_mode | body | string | 아니오 | `html`, `text` | 본문 편집 모드. `html` 은 리치 에디터 HTML, `text` 는 평문으로 저장·렌더링됩니다 (미지정 시 `html`) |
| published | body | boolean | 아니오 | — | 발행 여부 (발행된 항목만 필터) |
| seo_meta | body | array | 아니오 | — | SEO 메타 정보 맵. 하위 키 `title`(max 255)·`description`(max 500)·`keywords`(max 500)를 담습니다 |
| temp_key | body | string | 아니오 | max 64 | 저장 전 첨부 업로드 시 발급받은 임시 키. 새로 업로드한 첨부를 이 페이지에 귀속시키는 데 사용합니다 |

**요청 예시**

```http
PUT /api/modules/sirsoft-page/admin/pages/10 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "slug": "example-key",
    "title": [
        "예시 제목"
    ],
    "content": "예시 내용입니다.",
    "content_mode": "html",
    "published": true,
    "seo_meta": [
        "예시값"
    ],
    "temp_key": "예시값"
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
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 페이지를 수정합니다. `{page}` 는 페이지 ID 입니다. `title`(필수)·`slug`·`content`·`content_mode`·`published`·`seo_meta` 를 받아 `PageService::updatePage()` 로 반영하고, 수정 시 이전 상태가 버전 이력으로 적재됩니다(`current_version` 증가). `creator`/`updater`/`attachments` 를 eager load 한 `PageResource` 를 반환합니다. 미존재 404, 스코프 밖 403.


### PATCH /api/modules/sirsoft-page/admin/pages/{page}/publish
<!-- @generated:start:api.modules.sirsoft-page.admin.pages.publish -->
- **라우트명**: `api.modules.sirsoft-page.admin.pages.publish`
- **컨트롤러**: `Modules\Sirsoft\Page\Http\Controllers\Admin\PageController@publish`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-page.pages.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | path | string | 예 | — | 조회할 페이지 번호 (1부터 시작) |
| published | body | boolean | 예 | — | 발행 여부 (발행된 항목만 필터) |

**요청 예시**

```http
PATCH /api/modules/sirsoft-page/admin/pages/10/publish HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "published": true
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `10` | 기본 키 (내부 식별자) |
| slug | string | `apidoc-sample-page` | URL 친화 식별자 (slug) |
| title | object | `{"ko":"API 문서 샘플 페이지","en":"API Doc Sample Page"}` | 제목 |
| content | object | `{"ko":"<p>API 레퍼런스 실측용 완전 샘플 페이지 본문입니다.<\/p>","en":"<p>Co…` | 본문 내용 |
| content_mode | string | `html` | 본문 형식 (html, text) |
| published | boolean | `true` | 발행 여부 (true: 발행, false: 미발행) |
| published_at | string | `2026-07-08 15:03:15` | published 일시 |
| seo_meta | object | `{"title":"Dolorum et aut officia ipsam doloribus inventor…` | SEO 메타 정보 (title, description, keywords) |
| current_version | integer | `2` | 현재 버전 번호 |
| created_at | string | `2026-07-08 10:51:59` | 생성 일시 |
| updated_at | string | `2026-07-08 15:03:15` | 최종 수정 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "페이지 발행 상태가 변경되었습니다.",
    "data": {
        "id": 10,
        "slug": "apidoc-sample-page",
        "title": {
            "ko": "API 문서 샘플 페이지",
            "en": "API Doc Sample Page"
        },
        "content": {
            "ko": "<p>API 레퍼런스 실측용 완전 샘플 페이지 본문입니다.</p>",
            "en": "<p>Complete sample page body for API reference probing.</p>"
        },
        "content_mode": "html",
        "published": true,
        "published_at": "2026-07-08 15:03:15",
        "seo_meta": {
            "title": "Dolorum et aut officia ipsam doloribus inventore.",
            "description": "Sint ipsa impedit enim natus tempore quasi dignissimos praesentium numquam rerum tempore nam.",
            "keywords": "illo,pariatur,quis,necessitatibus,aperiam"
        },
        "current_version": 2,
        "created_at": "2026-07-08 10:51:59",
        "updated_at": "2026-07-08 15:03:15",
        "is_owner": true,
        "abilities": {
            "can_create": true,
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
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 단일 페이지의 발행 상태만 토글합니다. `{page}` 는 페이지 ID, `published`(필수 boolean)로 발행/미발행을 전환합니다. 본문·슬러그 등 다른 필드는 건드리지 않으며 변경된 `PageResource` 를 반환합니다. 목록/상세 화면의 발행 토글 스위치에 사용합니다. 미존재 404, 스코프 밖 403.


### GET /api/modules/sirsoft-page/admin/pages/{page}/versions
<!-- @generated:start:api.modules.sirsoft-page.admin.pages.versions.index -->
- **라우트명**: `api.modules.sirsoft-page.admin.pages.versions.index`
- **컨트롤러**: `Modules\Sirsoft\Page\Http\Controllers\Admin\PageController@versions`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-page.pages.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | path | string | 예 | — | 조회할 페이지 번호 (1부터 시작) |

**요청 예시**

```http
GET /api/modules/sirsoft-page/admin/pages/10/versions HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `40` | 기본 키 (내부 식별자) |
| page_id | integer | `29` | page 식별자 (연관 리소스 참조) |
| version | integer | `2` | 버전 순번 (1부터 증가, 페이지 수정 시마다 채번) |
| title | object | `{"ko":"API 문서 샘플 페이지 (v2)","en":"API Doc Sample Page (v2)"}` | 페이지 제목 (다국어 JSON) |
| content | object | `{"ko":"<p>수정 버전 본문.<\/p>","en":"<p>Revised version body.<…` | 페이지 본문 (다국어 JSON) |
| content_mode | string | `html` | 본문 형식 (html, text) |
| seo_meta | null | `null` | SEO 메타 정보 (title, description, keywords) |
| changes_summary | string | `본문 보강` | 이 버전에서 무엇이 바뀌었는지 요약한 변경 설명 (버전 생성 시 기록, 없으면 null) |
| creator | object | `{"uuid":"a231747f-e82e-4cf2-9ae1-a261849dce40","name":"AP…` | 생성자 정보 객체 (uuid/name/email — creator 관계 파생) |
| created_at | string | `2026-07-06 23:57:18` | 생성 일시 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "페이지 정보를 조회했습니다.",
    "data": [
        {
            "id": 14,
            "page_id": 10,
            "version": 2,
            "title": {
                "ko": "API 문서 샘플 페이지 (v2)",
                "en": "API Doc Sample Page (v2)"
            },
            "content": {
                "ko": "<p>수정 버전 본문.</p>",
                "en": "<p>Revised version body.</p>"
            },
            "content_mode": "html",
            "seo_meta": null,
            "changes_summary": "본문 보강",
            "creator": {
                "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                "name": "API 문서 샘플 사용자"
            },
            "created_at": "2026-07-08 10:51:59"
        },
        {
            "id": 13,
            "page_id": 10,
            "version": 1,
            "title": {
                "ko": "API 문서 샘플 페이지 (v1)",
                "en": "API Doc Sample Page (v1)"
            },
            "content": {
                "ko": "<p>초기 버전 본문.</p>",
                "en": "<p>Initial version body.</p>"
            },
            "content_mode": "html",
            "seo_meta": null,
            "changes_summary": "최초 작성",
            "creator": {
                "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                "name": "API 문서 샘플 사용자"
            },
            "created_at": "2026-07-08 10:51:59"
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 페이지의 버전 이력 목록을 조회합니다. `{page}` 는 페이지 ID 입니다. 각 항목은 그 시점의 `title`·`content`·`content_mode`·`seo_meta` 스냅샷과 `version` 번호·`changes_summary`(변경 요약)·`creator`(작성자)를 담습니다. 편집 화면의 버전 이력 패널에서 과거 버전 목록을 보여 주는 데이터 소스입니다. 미존재 404, 스코프 밖 403.


### GET /api/modules/sirsoft-page/admin/pages/{page}/versions/{versionId}
<!-- @generated:start:api.modules.sirsoft-page.admin.pages.versions.show -->
- **라우트명**: `api.modules.sirsoft-page.admin.pages.versions.show`
- **컨트롤러**: `Modules\Sirsoft\Page\Http\Controllers\Admin\PageController@showVersion`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-page.pages.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | path | string | 예 | — | 조회할 페이지 번호 (1부터 시작) |
| versionId | path | string | 예 | — | 대상 version의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-page/admin/pages/10/versions/{versionId} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: unresolved-path-param — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 특정 버전의 상세 스냅샷을 조회합니다. `{page}` 는 페이지 ID, `{versionId}` 는 버전 ID 입니다. 해당 버전의 전체 본문(`content`)과 메타를 담은 `PageVersionResource` 를 반환하며, 복원 전 미리보기·버전 간 비교에 사용합니다. `{versionId}` 는 라우트-모델 바인딩이 아니어서 문서 생성 시 자동 실측에서 제외되며, 응답 shape 은 `versions.index` 항목과 동일합니다. 미존재 404, 스코프 밖 403.


### POST /api/modules/sirsoft-page/admin/pages/{page}/versions/{versionId}/restore
<!-- @generated:start:api.modules.sirsoft-page.admin.pages.versions.restore -->
- **라우트명**: `api.modules.sirsoft-page.admin.pages.versions.restore`
- **컨트롤러**: `Modules\Sirsoft\Page\Http\Controllers\Admin\PageController@restoreVersion`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-page.pages.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | path | string | 예 | — | 조회할 페이지 번호 (1부터 시작) |
| versionId | path | string | 예 | — | 대상 version의 식별자 |

**요청 예시**

```http
POST /api/modules/sirsoft-page/admin/pages/10/versions/{versionId}/restore HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 페이지를 특정 버전 시점으로 복원합니다. `{page}` 는 페이지 ID, `{versionId}` 는 복원 대상 버전 ID 입니다. `PageService::restoreVersion()` 이 그 버전의 내용을 현재 페이지에 반영하고(복원 자체도 새 버전으로 적재), 복원된 `PageResource` 를 반환합니다. 편집 화면 버전 이력 패널의 "이 버전으로 복원" 액션에 사용합니다. 미존재 404, 스코프 밖 403.


### GET /api/modules/sirsoft-page/pages/attachment/{hash}
<!-- @generated:start:api.modules.sirsoft-page.pages.attachment.download -->
- **라우트명**: `api.modules.sirsoft-page.pages.attachment.download`
- **컨트롤러**: `Modules\Sirsoft\Page\Http\Controllers\User\PublicPageAttachmentController@download`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| hash | path | string | 예 | — | 대상 리소스의 해시 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-page/pages/attachment/{hash} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: unresolved-path-param — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 공개 첨부파일을 다운로드합니다(해시 기반, 12자). 실제 미들웨어는 `optional.sanctum` 으로, 비로그인 사용자도 접근할 수 있습니다(위 표의 `auth:sanctum` 은 생성기가 sanctum 미들웨어를 인식한 표기이며, 실제로는 토큰이 없어도 통과). 발행된 페이지의 첨부는 누구나, 미발행 페이지의 첨부는 `sirsoft-page.pages.read` 관리자만 다운로드할 수 있고, 그 외에는 404 로 존재를 숨깁니다. 브라우저 직접 GET(토큰 미탑재)을 위해 해시 라우트로 단일화되었습니다. 파일 스트리밍 응답이므로 실측 대상이 아닙니다.


### GET /api/modules/sirsoft-page/pages/attachment/{hash}/preview
<!-- @generated:start:api.modules.sirsoft-page.pages.attachment.preview -->
- **라우트명**: `api.modules.sirsoft-page.pages.attachment.preview`
- **컨트롤러**: `Modules\Sirsoft\Page\Http\Controllers\User\PublicPageAttachmentController@preview`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| hash | path | string | 예 | — | 대상 리소스의 해시 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-page/pages/attachment/{hash}/preview HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: unresolved-path-param — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 이미지 첨부 썸네일을 인라인으로 미리봅니다(해시 기반, 12자). 썸네일 `<img>` 는 토큰을 실을 수 없으므로 발행/미발행과 무관하게 공개 서빙합니다. 미발행 콘텐츠의 썸네일은 해시를 보유해야만 조회 가능(비추측성)하며, 실제 파일 다운로드는 `download` 의 권한 게이트로 보호됩니다. 이미지 스트리밍 응답이므로 실측 대상이 아닙니다.


### GET /api/modules/sirsoft-page/pages/{slug}
<!-- @generated:start:api.modules.sirsoft-page.pages.show -->
- **라우트명**: `api.modules.sirsoft-page.pages.show`
- **컨트롤러**: `Modules\Sirsoft\Page\Http\Controllers\User\PublicPageController@show`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |

**요청 예시**

```http
GET /api/modules/sirsoft-page/pages/terms HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `4` | 기본 키 (내부 식별자) |
| slug | string | `terms` | URL 친화 식별자 (slug) |
| title | string | `이용약관` | 제목 |
| content | string | `<h2 style="font-size: 1.25rem; font-w…` | 본문 내용 |
| content_mode | string | `html` | 본문 형식 (html, text) |
| is_preview | boolean | `false` | preview 여부 |
| published_at | string | `2026-07-08 10:44:43` | published 일시 |
| seo_meta | null | `null` | SEO 메타 정보 (title, description, keywords) |
| current_version | integer | `1` | 현재 버전 번호 |
| attachments | array | `[]` | 페이지에 귀속된 첨부파일 목록 (PageAttachmentResource 배열 — id/hash/원본파일명/URL 등, 로드된 경우에만 포함) |
| created_at | string | `2026-07-08 10:44:43` | 생성 일시 |
| updated_at | string | `2026-07-08 10:44:43` | 최종 수정 일시 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "페이지 정보를 조회했습니다.",
    "data": {
        "id": 4,
        "slug": "terms",
        "title": "이용약관",
        "content": "<h2 style=\"font-size: 1.25rem; font-weight: 700; margin-top: 0; margin-bottom: 1rem;\">제1조 (목적)</h2>\n<p style=\"font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;\">[이용약관의 목적을 입력하세요.]</p>\n\n<h2 style=\"font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;\">제2조 (정의)</h2>\n<p style=\"font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;\">[주요 용어의 정의를 입력하세요.]</p>\n\n<h2 style=\"font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;\">제3조 (약관의 효력 및 변경)</h2>\n<p style=\"font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;\">[약관의 효력 및 변경에 관한 내용을 입력하세요.]</p>\n\n<h2 style=\"font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;\">제4조 (서비스의 제공 및 변경)</h2>\n<p style=\"font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;\">[서비스 제공 및 변경에 관한 내용을 입력하세요.]</p>\n\n<h2 style=\"font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;\">제5조 (회원의 의무)</h2>\n<p style=\"font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;\">[회원의 의무에 관한 내용을 입력하세요.]</p>",
        "content_mode": "html",
        "is_preview": false,
        "published_at": "2026-07-08 10:44:43",
        "seo_meta": null,
        "current_version": 1,
        "attachments": [],
        "created_at": "2026-07-08 10:44:43",
        "updated_at": "2026-07-08 10:44:43"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 발행된 페이지를 슬러그로 조회하는 공개 엔드포인트입니다. 실제 미들웨어는 `optional.sanctum` 으로 비로그인 사용자도 발행 페이지를 볼 수 있습니다(위 표의 `auth:sanctum` 은 생성기 표기이며 토큰 없이도 통과). `sirsoft-page.pages.read` 권한을 가진 관리자는 미발행 페이지도 사용자 화면에서 미리볼 수 있으며(이때 응답에 preview 표시가 실림), 비로그인·일반 회원은 미발행 시 404 를 받습니다. `PublicPageResource` 는 관리자용 상세보다 축소된 공개 표면을 노출하고 `attachments` 를 포함합니다. `{slug}` 는 문자열 라우트 파라미터라 문서 생성 시 자동 실측에서 제외됩니다.


