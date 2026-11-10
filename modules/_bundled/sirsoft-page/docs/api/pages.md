# Pages API 레퍼런스

> **소유**: module `sirsoft-page` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Pages 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |

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
| seo_meta.title | body | string | 아니오 | max 255 | SEO 메타 제목 (검색엔진/소셜 공유 표시 제목) |
| seo_meta.description | body | string | 아니오 | max 500 | SEO 메타 설명 (검색엔진/소셜 공유 표시 요약) |
| seo_meta.keywords | body | string | 아니오 | max 500 | SEO 메타 키워드 (검색엔진 노출 키워드, 쉼표 구분) |
| temp_key | body | string | 아니오 | UUID | 저장 전 첨부 업로드 시 발급받은 임시 키. 생성된 페이지에 임시 첨부를 귀속시키는 데 사용합니다 |

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
    "seo_meta.title": "예시 제목",
    "seo_meta.description": "예시 내용입니다.",
    "seo_meta.keywords": "예시값",
    "temp_key": "5f0c3a3e-1b2c-4d5e-8f90-a1b2c3d4e5f6"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`PageResource`, HTTP 201)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `11` | 기본 키 (내부 식별자) |
| slug | string | `example-key` | URL 슬러그 (고유, 소문자·숫자·하이픈) |
| title | object | `{"ko":"예시 제목","en":"Example Title"}` | 페이지 제목 (다국어 JSON) |
| content | object | `{"ko":"<p>예시 내용입니다.</p>"}` | 페이지 본문 (다국어 JSON, 미입력 시 null) |
| content_mode | string | `html` | 본문 형식 (`html`, `text` — 미지정 시 `html`) |
| published | boolean | `true` | 발행 여부 (true: 발행, false: 미발행) |
| published_at | string\|null | `2026-07-08 10:51:59` | 발행 일시 (미발행 시 null) |
| seo_meta | object\|null | `{"title":"...","description":"...","keywords":"..."}` | SEO 메타 정보 (title, description, keywords) |
| current_version | integer | `1` | 현재 버전 번호 |
| creator | object | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"관리자"}` | 생성자 정보 (uuid/name — creator 관계 eager load) |
| updater | object\|null | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"관리자"}` | 최종 수정자 정보 (uuid/name — updater 관계 eager load) |
| attachments | array | `[]` | 페이지에 귀속된 첨부파일 목록 (`temp_key` 로 귀속된 임시 첨부 포함) |
| created_at | string | `2026-07-08 10:51:59` | 생성 일시 |
| updated_at | string | `2026-07-08 10:51:59` | 최종 수정 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자(`created_by`)인지 여부 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "페이지가 생성되었습니다.",
    "data": {
        "id": 11,
        "slug": "example-key",
        "title": {
            "ko": "예시 제목",
            "en": "Example Title"
        },
        "content": {
            "ko": "<p>예시 내용입니다.</p>",
            "en": "<p>Example body.</p>"
        },
        "content_mode": "html",
        "published": true,
        "published_at": "2026-07-08 10:51:59",
        "seo_meta": {
            "title": "예시 제목",
            "description": "예시 내용입니다.",
            "keywords": "예시,키워드"
        },
        "current_version": 1,
        "creator": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자"
        },
        "updater": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자"
        },
        "attachments": [],
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
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). 대표 케이스: `slug` 중복/형식 위반(소문자·숫자·하이픈만), `title` 누락 또는 기본 로케일 값 누락, `content_mode` 가 html/text 이외. 첨부 개수 상한 초과 시에도 422 이며 `errors.code = attachment_limit_exceeded` 와 함께 허용/시도 개수를 담은 안내 문구가 응답 시점의 언어로 내려갑니다 |
| 500 | Internal Server Error | 생성 처리 중 예외 (`페이지 생성에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 새 페이지를 생성합니다. `slug`(고유 필수)·`title`(다국어 배열 필수)·`content`·`content_mode`(html/text)·`published`·`seo_meta` 를 받아 `PageService::createPage()` 로 저장하고, `creator`/`updater`/`attachments` 를 eager load 한 `PageResource` 를 201 로 반환합니다. `temp_key` 는 페이지 저장 전 임시 업로드한 첨부(첨부 업로드 시 발급받은 키)를 생성된 페이지에 귀속시키는 데 씁니다. 다국어 `title` 에서 사이트 기본 언어(환경설정 「기본 언어」) 값을 비우면 422 `errors.title` 이 칸 이름과 언어 이름을 함께 알린다(「페이지 제목의 한국어(ko) 값은 필수입니다.」 / "The English (en) value of the page title field is required.").


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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| count | integer | `3` | 실제로 발행 상태가 변경된 페이지 건수 (메시지의 `:count` 에도 동일 값이 치환됨) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "3개 페이지의 발행 상태가 변경되었습니다.",
    "data": {
        "count": 3
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). 대표 케이스: `ids` 누락/빈 배열, `ids.*` 가 존재하지 않는 페이지 ID, `published` 누락 또는 boolean 아님 |
| 500 | Internal Server Error | 일괄 처리 중 예외 (`일괄 발행 상태 변경에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 여러 페이지의 발행 상태를 한 번에 변경합니다. `ids`(대상 페이지 ID 배열, 최소 1개)와 `published`(true=발행, false=미발행)를 받아 `PageService::bulkChangePublishStatus()` 로 일괄 적용하고, 실제 변경된 건수를 `count` 로 반환합니다. 목록 화면의 다중 선택 후 일괄 발행/미발행 액션에 사용합니다.


### POST /api/modules/sirsoft-page/admin/pages/check-slug
<!-- @generated:start:api.modules.sirsoft-page.admin.pages.check-slug -->
- **라우트명**: `api.modules.sirsoft-page.admin.pages.check-slug`
- **컨트롤러**: `Modules\Sirsoft\Page\Http\Controllers\Admin\PageController@checkSlug`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-page.pages.create|sirsoft-page.pages.update,false` (OR)

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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| exists | boolean | `false` | 해당 슬러그가 이미 사용 중인지 여부 (true: 중복 — 사용 불가, false: 사용 가능). `exclude_id` 를 넘기면 그 페이지는 중복 검사에서 제외 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "슬러그 중복 확인이 완료되었습니다.",
    "data": {
        "exists": false
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.create\|sirsoft-page.pages.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). 대표 케이스: `slug` 누락, `exclude_id` 가 정수가 아님 |

<!-- @generated:end -->

**설명** 슬러그 중복 여부를 확인합니다. `slug`(필수)와 선택 `exclude_id`(수정 화면에서 자기 자신을 중복 검사에서 제외)를 받아 `{ "exists": true|false }` 를 반환합니다. 페이지 생성/수정 폼에서 슬러그 입력 시 실시간 중복 검증에 사용합니다. 권한은 `pages.create` 를 요구합니다. 7.1.0 부터 등록 화면과 수정 화면이 함께 쓰므로 등록·수정 권한 중 하나만 있어도 됩니다.


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
DELETE /api/modules/sirsoft-page/admin/pages/{page} HTTP/1.1
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
GET /api/modules/sirsoft-page/admin/pages/{page} HTTP/1.1
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
| seo_meta.title | body | string | 아니오 | max 255 | SEO 메타 제목 (검색엔진/소셜 공유 표시 제목) |
| seo_meta.description | body | string | 아니오 | max 500 | SEO 메타 설명 (검색엔진/소셜 공유 표시 요약) |
| seo_meta.keywords | body | string | 아니오 | max 500 | SEO 메타 키워드 (검색엔진 노출 키워드, 쉼표 구분) |
| temp_key | body | string | 아니오 | UUID | 저장 전 첨부 업로드 시 발급받은 임시 키. 새로 업로드한 첨부를 이 페이지에 귀속시키는 데 사용합니다 |

**요청 예시**

```http
PUT /api/modules/sirsoft-page/admin/pages/{page} HTTP/1.1
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
    "seo_meta.title": "예시 제목",
    "seo_meta.description": "예시 내용입니다.",
    "seo_meta.keywords": "예시값",
    "temp_key": "5f0c3a3e-1b2c-4d5e-8f90-a1b2c3d4e5f6"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`PageResource` — `show` 와 동일 shape)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `10` | 기본 키 (내부 식별자) |
| slug | string | `apidoc-sample-page` | URL 슬러그 (고유) |
| title | object | `{"ko":"API 문서 샘플 페이지","en":"API Doc Sample Page"}` | 페이지 제목 (다국어 JSON) |
| content | object | `{"ko":"<p>수정된 본문.</p>"}` | 페이지 본문 (다국어 JSON) |
| content_mode | string | `html` | 본문 형식 (`html`, `text`) |
| published | boolean | `true` | 발행 여부 (true: 발행, false: 미발행) |
| published_at | string\|null | `2026-07-08 10:51:59` | 발행 일시 (미발행 시 null) |
| seo_meta | object\|null | `{"title":"...","description":"...","keywords":"..."}` | SEO 메타 정보 (title, description, keywords) |
| current_version | integer | `3` | 현재 버전 번호 (수정 시 이전 상태가 버전 이력으로 적재되며 증가) |
| creator | object | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"관리자"}` | 생성자 정보 (uuid/name — creator 관계 eager load) |
| updater | object | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"관리자"}` | 최종 수정자 정보 (uuid/name — updater 관계 eager load) |
| attachments | array | `[{"id":4,"hash":"ninrdtsmhhta","original_filename":"nesciunt.jpg", ...}]` | 페이지에 귀속된 첨부파일 목록 (`PageAttachmentResource` 배열) |
| created_at | string | `2026-07-08 10:51:59` | 생성 일시 |
| updated_at | string | `2026-07-08 15:03:15` | 최종 수정 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자(`created_by`)인지 여부 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "페이지가 수정되었습니다.",
    "data": {
        "id": 10,
        "slug": "apidoc-sample-page",
        "title": {
            "ko": "API 문서 샘플 페이지",
            "en": "API Doc Sample Page"
        },
        "content": {
            "ko": "<p>수정된 본문.</p>",
            "en": "<p>Revised body.</p>"
        },
        "content_mode": "html",
        "published": true,
        "published_at": "2026-07-08 10:51:59",
        "seo_meta": {
            "title": "Dolorum et aut officia ipsam doloribus inventore.",
            "description": "Sint ipsa impedit enim natus tempore quasi dignissimos praesentium numquam rerum tempore nam.",
            "keywords": "illo,pariatur,quis,necessitatibus,aperiam"
        },
        "current_version": 3,
        "creator": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자"
        },
        "updater": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자"
        },
        "attachments": [],
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
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.update`)이 없거나, 스코프 밖 리소스인 경우 (`auth.scope_denied`) |
| 404 | Not Found | `{page}` ID 에 해당하는 페이지가 없는 경우 (`페이지를 찾을 수 없습니다.`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). 대표 케이스: `title` 누락, `slug` 중복(자기 자신은 제외)/형식 위반, `content_mode` 가 html/text 이외. 첨부 개수 상한 초과 시에도 422 이며 `errors.code = attachment_limit_exceeded` 와 함께 허용/시도 개수를 담은 안내 문구가 응답 시점의 언어로 내려갑니다 |
| 500 | Internal Server Error | 수정 처리 중 예외 (`페이지 수정에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 페이지를 수정합니다. `{page}` 는 페이지 ID 입니다. `title`(필수)·`slug`·`content`·`content_mode`·`published`·`seo_meta` 를 받아 `PageService::updatePage()` 로 반영하고, 수정 시 이전 상태가 버전 이력으로 적재됩니다(`current_version` 증가). `creator`/`updater`/`attachments` 를 eager load 한 `PageResource` 를 반환합니다. 미존재 404, 스코프 밖 403. 다국어 `title` 에서 사이트 기본 언어(환경설정 「기본 언어」) 값을 비우면 422 `errors.title` 이 칸 이름과 언어 이름을 함께 알린다(「페이지 제목의 한국어(ko) 값은 필수입니다.」).


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
PATCH /api/modules/sirsoft-page/admin/pages/{page}/publish HTTP/1.1
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
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |

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
GET /api/modules/sirsoft-page/admin/pages/{page}/versions HTTP/1.1
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
| versionId | path | integer | 예 | — | 대상 version의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-page/admin/pages/{page}/versions/{versionId} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`PageVersionResource` — `versions.index` 항목과 동일 shape)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `14` | 버전 레코드의 기본 키 (내부 식별자) |
| page_id | integer | `10` | 이 버전이 속한 페이지의 ID |
| version | integer | `2` | 버전 순번 (1부터 증가, 페이지 수정 시마다 채번) |
| title | object | `{"ko":"API 문서 샘플 페이지 (v2)","en":"API Doc Sample Page (v2)"}` | 그 시점의 페이지 제목 스냅샷 (다국어 JSON) |
| content | object | `{"ko":"<p>수정 버전 본문.</p>"}` | 그 시점의 페이지 본문 스냅샷 (다국어 JSON) |
| content_mode | string | `html` | 그 시점의 본문 형식 (`html`, `text` — 미지정 시 `html`) |
| seo_meta | object\|null | `null` | 그 시점의 SEO 메타 스냅샷 (title, description, keywords) |
| changes_summary | string\|null | `본문 보강` | 이 버전에서 무엇이 바뀌었는지 요약한 변경 설명 (없으면 null) |
| creator | object | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"관리자"}` | 이 버전을 만든 사용자 정보 (uuid/name — creator 관계 eager load) |
| created_at | string | `2026-07-08 10:51:59` | 버전 생성 일시 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "페이지 정보를 조회했습니다.",
    "data": {
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
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.read`)이 없거나, 스코프 밖 리소스인 경우 (`auth.scope_denied`) |
| 404 | Not Found | `{page}` 페이지 또는 `{versionId}` 버전이 없는 경우 (`페이지를 찾을 수 없습니다.`) |
| 500 | Internal Server Error | 조회 처리 중 예외 (`페이지 정보 조회에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 특정 버전의 상세 스냅샷을 조회합니다. `{page}` 는 페이지 ID, `{versionId}` 는 버전 ID 입니다. 해당 버전의 전체 본문(`content`)과 메타를 담은 `PageVersionResource` 를 반환하며, 복원 전 미리보기·버전 간 비교에 사용합니다. `{versionId}` 는 라우트-모델 바인딩이 아니어서 문서 생성 시 자동 실측에서 제외되며, 응답 shape 은 `versions.index` 항목과 동일합니다. 미존재 404, 스코프 밖 403. `{versionId}`는 경로의 `{page}`에 속한 버전이어야 합니다 — 다른 페이지의 버전 ID를 지정하면 404를 반환합니다.


### POST /api/modules/sirsoft-page/admin/pages/{page}/versions/{versionId}/restore
<!-- @generated:start:api.modules.sirsoft-page.admin.pages.versions.restore -->
- **라우트명**: `api.modules.sirsoft-page.admin.pages.versions.restore`
- **컨트롤러**: `Modules\Sirsoft\Page\Http\Controllers\Admin\PageController@restoreVersion`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-page.pages.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | path | string | 예 | — | 조회할 페이지 번호 (1부터 시작) |
| versionId | path | integer | 예 | — | 대상 version의 식별자 |

**요청 예시**

```http
POST /api/modules/sirsoft-page/admin/pages/{page}/versions/{versionId}/restore HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (복원된 페이지의 `PageResource` — `show` 와 동일 shape)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `10` | 기본 키 (내부 식별자) |
| slug | string | `apidoc-sample-page` | URL 슬러그 (고유) |
| title | object | `{"ko":"API 문서 샘플 페이지 (v1)","en":"API Doc Sample Page (v1)"}` | 복원된 페이지 제목 (다국어 JSON — 대상 버전의 스냅샷) |
| content | object | `{"ko":"<p>초기 버전 본문.</p>"}` | 복원된 페이지 본문 (다국어 JSON — 대상 버전의 스냅샷) |
| content_mode | string | `html` | 본문 형식 (`html`, `text`) |
| published | boolean | `true` | 발행 여부 (true: 발행, false: 미발행) |
| published_at | string\|null | `2026-07-08 10:51:59` | 발행 일시 (미발행 시 null) |
| seo_meta | object\|null | `null` | SEO 메타 정보 (title, description, keywords — 대상 버전의 스냅샷) |
| current_version | integer | `3` | 현재 버전 번호 (복원 자체도 새 버전으로 적재되어 증가) |
| creator | object | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"관리자"}` | 생성자 정보 (uuid/name — creator 관계 eager load) |
| updater | object | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"관리자"}` | 최종 수정자(복원 수행자) 정보 (uuid/name — updater 관계 eager load) |
| attachments | array | `[]` | 페이지에 귀속된 첨부파일 목록 (`PageAttachmentResource` 배열) |
| created_at | string | `2026-07-08 10:51:59` | 생성 일시 |
| updated_at | string | `2026-07-08 15:10:02` | 최종 수정 일시 (복원 시각) |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자(`created_by`)인지 여부 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "이전 버전으로 복원되었습니다.",
    "data": {
        "id": 10,
        "slug": "apidoc-sample-page",
        "title": {
            "ko": "API 문서 샘플 페이지 (v1)",
            "en": "API Doc Sample Page (v1)"
        },
        "content": {
            "ko": "<p>초기 버전 본문.</p>",
            "en": "<p>Initial version body.</p>"
        },
        "content_mode": "html",
        "published": true,
        "published_at": "2026-07-08 10:51:59",
        "seo_meta": null,
        "current_version": 3,
        "creator": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자"
        },
        "updater": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자"
        },
        "attachments": [],
        "created_at": "2026-07-08 10:51:59",
        "updated_at": "2026-07-08 15:10:02",
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
| 403 | Forbidden | 요구 권한(`sirsoft-page.pages.update`)이 없거나, 스코프 밖 리소스인 경우 (`auth.scope_denied`) |
| 404 | Not Found | `{page}` 페이지 또는 `{versionId}` 버전이 없는 경우 (`페이지를 찾을 수 없습니다.`) |
| 500 | Internal Server Error | 복원 처리 중 예외 — 지정된 버전이 해당 페이지에 속하지 않는 경우 포함 (`버전 복원에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 페이지를 특정 버전 시점으로 복원합니다. `{page}` 는 페이지 ID, `{versionId}` 는 복원 대상 버전 ID 입니다. `PageService::restoreVersion()` 이 그 버전의 내용을 현재 페이지에 반영하고(복원 자체도 새 버전으로 적재), 복원된 `PageResource` 를 반환합니다. 편집 화면 버전 이력 패널의 "이 버전으로 복원" 액션에 사용합니다. 미존재 404, 스코프 밖 403. `{versionId}`는 경로의 `{page}`에 속한 버전이어야 합니다 — 다른 페이지의 버전 ID를 지정하면 `versions.show` 와 동일하게 404를 반환합니다.


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

_이 엔드포인트는 JSON 봉투를 반환하지 않습니다. 성공 시 원본 파일 바이트를 그대로 내려주는 파일 스트리밍 응답(`StreamedResponse`, `Content-Disposition: attachment`)이며, 실패 시에만 JSON 오류 봉투를 반환합니다._

**응답 예시**

```http
HTTP/1.1 200
Content-Type: image/jpeg
Content-Disposition: attachment; filename="nesciunt.jpg"

(파일 바이너리 스트림)
```

실패 시(첨부 미존재 · 미발행 페이지의 첨부를 권한 없이 요청 · 스토리지에 파일 없음):

```json
{
    "success": false,
    "message": "첨부파일을 찾을 수 없습니다.",
    "errors": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 해시에 해당하는 첨부가 없거나(`첨부파일을 찾을 수 없습니다.`), 미발행 페이지의 첨부를 `sirsoft-page.pages.read` 권한 없이 요청한 경우(존재를 숨기기 위해 동일하게 404), 또는 DB 레코드는 있으나 스토리지에 실제 파일이 없는 경우(`첨부파일이 스토리지에 존재하지 않습니다.`) |

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

_이 엔드포인트는 JSON 봉투를 반환하지 않습니다. 성공 시 이미지 바이트를 인라인으로 내려주는 스트리밍 응답(`StreamedResponse`, `Content-Disposition: inline`, `X-Content-Type-Options: nosniff`)이며, 인라인 미리보기는 래스터 이미지(JPEG·PNG·GIF·WebP 등)만 대상입니다(SVG 는 이미지로 취급하지 않음 — KVE-2026-2366). 실패 시에만 JSON 오류 봉투를 반환합니다._

**응답 예시**

```http
HTTP/1.1 200
Content-Type: image/jpeg
Content-Disposition: inline; filename=photo.jpg
X-Content-Type-Options: nosniff
Cache-Control: public, max-age=31536000, must-revalidate
ETag: "9f2c1d5a0b7e4c3f8a6d2e1b9c0f7a5d"
Last-Modified: Tue, 14 Nov 2023 22:13:20 GMT
Expires: Wed, 13 Nov 2024 22:13:20 GMT

(이미지 바이너리 스트림)
```

조건부 요청 (HTTP 304) — `If-None-Match` 가 현재 ETag 와 일치하거나, `If-None-Match` 없이 `If-Modified-Since` 가 수정 시각 이후이면 본문 없이 응답합니다. 304 는 200 이 실었을 `ETag`·`Last-Modified`·`Cache-Control`·`Expires` 를 그대로 싣습니다:

```http
GET /api/modules/sirsoft-page/pages/attachment/{hash}/preview HTTP/1.1
If-None-Match: "9f2c1d5a0b7e4c3f8a6d2e1b9c0f7a5d"

HTTP/1.1 304 Not Modified
ETag: "9f2c1d5a0b7e4c3f8a6d2e1b9c0f7a5d"
Last-Modified: Tue, 14 Nov 2023 22:13:20 GMT
Cache-Control: public, max-age=31536000, must-revalidate
Expires: Wed, 13 Nov 2024 22:13:20 GMT
```

실패 시(첨부 미존재 · 이미지가 아닌 첨부 · 스토리지에 파일 없음):

```json
{
    "success": false,
    "message": "첨부파일이 스토리지에 존재하지 않습니다.",
    "errors": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 해시에 해당하는 첨부가 없는 경우(`첨부파일을 찾을 수 없습니다.`), 미발행 페이지의 첨부를 `sirsoft-page.pages.read` 권한·유효 서명 없이 요청한 경우(존재를 숨기기 위해 동일하게 404), 첨부가 미리보기 대상 이미지(JPG·PNG·GIF·WebP 등)가 아닌 경우(`미리보기를 제공하지 않는 파일 형식입니다.` — SVG·TXT 등, 미발행 첨부는 권한 판정을 먼저 거쳐 형식이 드러나지 않음), 이미지인데 스토리지에 실제 파일이 없는 경우(`첨부파일이 스토리지에 존재하지 않습니다.`) |

<!-- @generated:end -->

**설명** 이미지 첨부 썸네일을 인라인으로 미리봅니다(해시 기반, 12자). `download` 와 동일한 발행상태 게이트를 적용합니다 — 발행된 페이지의 썸네일은 누구나, 미발행 페이지의 썸네일은 `sirsoft-page.pages.read` 권한 관리자만 미리볼 수 있습니다. 무인가 사용자에게는 미발행 썸네일이 차단됩니다.

브라우저 `<img src>` 는 Authorization 헤더를 실을 수 없으므로, 미발행 페이지의 관리자 응답(관리자 상세·미발행 미리보기)에는 `expires`·`signature` 쿼리가 붙은 **한시 서명 URL** 이 `preview_url` 로 직렬화되며, 이 엔드포인트는 유효한 서명을 권한과 동등한 자격으로 허용합니다. 서명이 변조·만료된 요청은 404 로 차단됩니다. 발행 페이지의 `preview_url` 은 종전과 동일한 무서명 공개 경로입니다. 사이트를 하위 폴더(예: `/g7`)에 설치하면 이 서명 URL 은 설치 폴더 접두사를 실은 경로(`/g7/api/…`)로 내려간다 — 서명은 접두사를 뺀 경로에 걸리고 검증도 접두사를 뺀 요청 경로로 하므로, 그 주소 그대로 열면 서명이 유효하다. 무서명 `preview_url`·`download_url` 도 같은 접두사를 싣는다. 이미지 스트리밍 응답이므로 실측 대상이 아닙니다.

**조건부 요청**: 응답은 행 메타(디스크·경로·수정 시각·크기) 기반 `ETag` 와 `Last-Modified` 를 싣고, 되돌아온 `If-None-Match`(우선) / `If-Modified-Since` 를 판정해 변경이 없으면 본문 없는 304 로 응답합니다. 판정은 코어 파일 서빙 헬퍼(`streamedFileResponse()`)가 하므로 다중값·`W/` 약한 검증자·압축 표현의 `-gzip` 접미사가 모두 인정되며, 304 에도 200 과 같은 캐시 헤더가 실립니다. 주소에 자산 버전(`?v=`)이 없으므로 `immutable` 은 붙지 않고(비운영 환경은 `no-cache`), 같은 주소의 이미지가 교체되면 재검증으로 도달합니다. 파일 이름 선언(`Content-Disposition`)은 스토리지 계층이 원본 파일명으로 만듭니다.

**캐시**: 미발행 페이지 첨부의 원본은 `Cache-Control: private` 로 냅니다(공유 캐시 저장 금지 — 운영 환경 `private, max-age={TTL}`, 그 외 `private, no-cache`). 발행 페이지 원본은 종전과 같습니다. 썸네일을 요청했는데 원본을 낸 응답(`X-G7-Thumbnail: bypass:{사유}`)은 운영 환경에서 `max-age` 가 썸네일과 같은 코어 레이아웃 캐시 유지 시간(`cache.layout_ttl`, 1년 이하)으로 줄고, 생성 제한·일시 오류면 `no-store` 입니다.

**썸네일 (코어 7.2.0+)**: 쿼리에 규격을 붙이면 발행상태 게이트를 지난 뒤 환경설정 > 업로드 「썸네일 규격」 크기로 줄인 이미지를 냅니다. 둘 다 없으면 원본입니다. 페이지 첨부는 권한이 걸린 원본이라 썸네일은 항상 `Cache-Control: private` 이고, 게이트에 막히는 요청은 썸네일을 붙여도 원본과 같은 404 입니다. 서명은 `size`·`w` 를 빼고 검증하므로 서명 주소 뒤에 `size` 를 덧붙여도 유효합니다(변조·만료는 그대로 404). 저장소에서 원본 파일만 사라진 첨부는 썸네일 파생본이 남아 있어도 원본과 같은 404(`첨부파일이 스토리지에 존재하지 않습니다.`)입니다 — 원본 확인을 썸네일 판정보다 먼저 합니다.

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| size | query | string | 아니오 | 규격 이름 (`xs`·`sm`·`md`·`lg`·`xl`·`og`·확장·운영자 규격) | 그 규격으로 줄인 이미지. `w` 보다 우선 |
| w | query | integer | 아니오 | 1~10000 | 「숫자 요청 대상」 규격 중 가로가 이 값 이상인 가장 작은 규격(없으면 가장 큰 규격) |

| 응답 헤더 | 값 | 의미 |
| --- | --- | --- |
| `X-G7-Thumbnail` | `hit` · `miss` | 썸네일을 냄 — 이미 있던 파일(`hit`) 또는 이번에 만든 파일(`miss`) |
| `X-G7-Thumbnail` | `bypass:{사유}` | 썸네일을 요청했지만 원본을 냄. 사유: `disabled`·`no-engine`·`not-raster`·`animated`·`unknown-preset`(미정의 규격·배열 파라미터·같은 키 두 번)·`smaller-than-preset`·`rate-limited`·`too-large`·`error` |

`size`·`w` 를 붙이지 않은 요청에는 이 헤더가 없습니다. 자세한 규약은 [이미지 썸네일](../../../../../docs/backend/image-thumbnails.md) 문서를 봅니다.


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
GET /api/modules/sirsoft-page/pages/{slug} HTTP/1.1
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
| content_thumbnail_url | string \| null | `null` | 본문 첫 내부 이미지 URL 캐시 — 페이지 표시 레이아웃의 공유 미리보기(og:image)가 소비한다. html 모드에서 본문에 내부 이미지가 있으면 채워지고, 외부 주소 이미지·text 모드·이미지 없음이면 `null` (1.1.0+) |
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

**설명** 발행된 페이지를 슬러그로 조회하는 공개 엔드포인트입니다. 실제 미들웨어는 `optional.sanctum` 으로 비로그인 사용자도 발행 페이지를 볼 수 있습니다(위 표의 `auth:sanctum` 은 생성기 표기이며 토큰 없이도 통과). `sirsoft-page.pages.read` 권한을 가진 관리자는 미발행 페이지도 사용자 화면에서 미리볼 수 있으며(이때 응답에 preview 표시가 실림), 비로그인·일반 회원은 미발행 시 404 를 받습니다. `PublicPageResource` 는 관리자용 상세보다 축소된 공개 표면을 노출하고 `attachments` 를 포함합니다. `{slug}` 는 문자열 라우트 파라미터라 문서 생성 시 자동 실측에서 제외됩니다. 응답에 `abilities.can_update`(페이지 수정 권한·스코프)를 싣고, 페이지가 없으면 404 응답의 `errors.abilities.can_create`(페이지 등록 권한)를 싣습니다 — 공개 화면의 [편집]·[페이지 만들기] 를 그 권한자에게만 여는 근거입니다(7.1.0).


