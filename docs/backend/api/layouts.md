# Layouts API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Layouts 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/layouts/preview/{token}
<!-- @generated:start:api.public.layouts.preview.serve.extensionless -->
- **라우트명**: `api.public.layouts.preview.serve.extensionless`
- **컨트롤러**: `App\Http\Controllers\Api\Public\LayoutPreviewController@serve`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| token | path | string | 예 | — | 인증/검증 토큰 |

**요청 예시**

```http
GET /api/layouts/preview/{token} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

이 엔드포인트는 `ResponseHelper` 봉투(`success`/`message`/`data`)를 쓰지 않는다. **레이아웃 JSON 자체**를 최상위로 그대로 반환하므로 `data` 래퍼가 없다 — 템플릿 엔진이 실제 레이아웃 응답과 동일하게 소비할 수 있어야 하기 때문이다.

최상위 키는 레이아웃 JSON 스키마를 따르며, 상속(`extends`) 병합과 모듈/플러그인 layout extension 적용이 **끝난 결과물**이다.

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| version | string | 레이아웃 스키마 버전 |
| layout_name | string | 레이아웃 식별명 |
| meta | object | 제목·설명·`auth_required`·`is_base`·SEO 설정 등 페이지 메타 |
| components | array | 렌더링 컴포넌트 트리 (상속 병합 + extension 주입 완료 상태) |
| slots | object | 슬롯 정의 (베이스 레이아웃일 때) |
| data_sources | array | API 데이터 소스 정의 |
| computed | object | 계산된 값 정의 |
| state / init_state / initLocal / initGlobal / initIsolated | object | 초기 상태 정의 |
| init_actions | array | 최초 렌더 시 실행할 액션 |
| actions / named_actions | object | 재사용 액션 정의 |
| modals | object | 모달 정의 |
| errorHandling | object | 에러 핸들링 정의 |
| globalHeaders | array | 공통 요청 헤더 (`pattern` + `headers` 쌍) |
| permissions | object | 레이아웃 권한 선언 |

> `extends` 키는 병합 후 결과에는 남지 않는다 (병합 입력으로만 쓰인다). 실제 포함되는 키 집합은 저장된 레이아웃 내용에 따라 달라진다.

**응답 예시**

```http
HTTP/1.1 200
Content-Type: application/json
```

```json
{
    "version": "1.0",
    "layout_name": "product_list",
    "meta": {
        "title": "상품 목록",
        "auth_required": false
    },
    "data_sources": [
        {
            "id": "products",
            "endpoint": "/api/modules/sirsoft-ecommerce/products",
            "method": "GET"
        }
    ],
    "components": [
        {
            "type": "basic",
            "name": "Div",
            "props": { "className": "container mx-auto" },
            "children": []
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 토큰에 해당하는 미리보기가 없거나 **만료된 경우** (`templates.layout_not_found`). 조회 시 `notExpired()` 스코프가 적용되므로 만료 토큰은 미존재와 동일하게 404 다 |

<!-- @generated:end -->

**설명**

레이아웃 편집기에서 **저장하지 않은 편집 중 내용**을 실제 화면으로 확인하기 위한 미리보기 서빙 엔드포인트다. 인증이 아니라 **토큰 자체가 보안 메커니즘**이며(`optional.sanctum` — 비회원도 접근 가능), 토큰 유효기간은 발급 시점부터 **30분**이다.

미리보기 종류는 두 가지다.

| `preview_type` | 동작 |
| --- | --- |
| `layout` (기본) | 편집 중인 레이아웃 content 를 기준으로 `extends` 상속을 병합한 뒤 모듈/플러그인 extension 을 적용한다 |
| `extension` | 대표 레이아웃을 먼저 병합하고, 편집 중인 **확장 content** 를 그 확장 자리에 임시 치환한 상태로 extension 을 적용한다 |

주의사항:

- 토큰은 만료되면 되살릴 수 없다. 편집기에서 미리보기를 다시 열면 새 토큰이 발급된다.
- 확장 미리보기의 임시 치환은 요청 처리 중에만 유효하며, `finally` 로 항상 해제되므로 다른 요청에 새지 않는다.
- 응답이 봉투 없는 원본 JSON 이므로, 이 URL 을 `data_sources` 로 소비할 때 `{{x?.data?.…}}` 가 아니라 최상위 키를 직접 참조한다.


### GET /api/layouts/preview/{token}.json
<!-- @generated:start:api.public.layouts.preview.serve -->
- **라우트명**: `api.public.layouts.preview.serve`
- **컨트롤러**: `App\Http\Controllers\Api\Public\LayoutPreviewController@serve`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| token | path | string | 예 | — | 미리보기 토큰(UUID). `LayoutPreviewService::createPreview` 가 발급하며 기본 30분 후 만료 |

**요청 예시**

```http
GET /api/layouts/preview/{token}.json HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `success`/`message`/`data` 봉투를 사용하지 않습니다. `LayoutPreviewController@serve` 가 `response()->json($layout)` 로 **병합된 레이아웃 JSON 객체를 최상위(root)에 그대로** 반환합니다 (상속 병합 + 모듈/플러그인 확장 적용 완료 상태). 아래는 레이아웃 JSON의 최상위 키이며, 실제 포함 여부는 저장된 레이아웃 content 에 따라 달라집니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| version | string | `"1.0"` | 레이아웃 스키마 버전 (저장 시 필수) |
| layout_name | string | `"product_list"` | 레이아웃 이름 (식별자) |
| extends | string\|null | `"_user_base"` | 상속할 베이스 레이아웃 이름 (미리보기 서빙 시 이미 병합됨) |
| slots | object | `{"content": [ ... ]}` | 베이스 레이아웃 슬롯에 삽입할 컴포넌트 배열 (extends 레이아웃) |
| components | array | `[{"type":"basic","name":"Div", ...}]` | 렌더링할 컴포넌트 트리 (병합·확장 적용 결과) |
| endpoint | string\|null | `"/api/products"` | 화면이 주로 fetch 하는 데이터 API 경로 (레거시·선택) |
| data_sources | array | `[{"id":"products","endpoint":"/api/products","method":"GET"}]` | 화면이 로드하는 API 데이터 소스 정의 |
| meta | object | `{"title":"상품 목록","auth_required":false}` | 메타 정보 (title, description, keywords, auth_required, is_base, guest_only, is_error_layout, error_code, seo) |
| permissions | array\|object | `["core.templates.layouts.edit"]` | 레이아웃 접근에 필요한 권한 (flat 배열=AND, 구조화 객체=OR/AND 중첩) |
| modals | object | `{}` | 모달 컴포넌트 정의 |
| state / init_state / initLocal / initGlobal / initIsolated / global_state | object | `{}` | 레이아웃 레벨 초기 상태 정의 |
| init_actions | array | `[{"handler":"apiCall", ...}]` | 레이아웃 로드 시 실행할 초기화 액션 |
| actions / named_actions | array\|object | `{}` | 재사용 가능한 액션 정의 |
| computed | object | `{}` | 계산된 값 정의 |
| defines | object | `{}` | 재사용 가능한 컴포넌트 조각 정의 |
| errorHandling | object | `{"404":{"handler":"navigate"}}` | 상태 코드별 에러 처리 정책 |
| globalHeaders | array | `[{"pattern":"*","headers":{"X-Foo":"bar"}}]` | API 호출 시 자동 적용되는 HTTP 헤더 규칙 |
| transition_overlay | object | `{"enabled":true,"style":"blur"}` | 페이지 전환 오버레이 설정 |
| routes | array | `[]` | 라우트 정의 |
| pageConfig / schema | object | `{}` | 플러그인 설정 레이아웃 전용 (설정 UI 안내/스키마) |

**응답 예시**

```json
{
    "version": "1.0",
    "layout_name": "product_list",
    "meta": {
        "title": "상품 목록",
        "auth_required": false
    },
    "data_sources": [
        {
            "id": "products",
            "endpoint": "/api/products",
            "method": "GET"
        }
    ],
    "components": [
        {
            "type": "basic",
            "name": "Div",
            "props": {
                "className": "p-4"
            },
            "children": []
        }
    ]
}
```

404 응답은 봉투 형식입니다 (`BaseApiController::notFound` → `templates.layout_not_found`).

```json
{
    "success": false,
    "message": "레이아웃을 찾을 수 없습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 토큰에 해당하는 미리보기가 없거나 만료된 경우 (`templates.layout_not_found`) |

<!-- @generated:end -->

**설명** 편집 중인 레이아웃을 미리보기 토큰(UUID)으로 조회해 JSON으로 서빙합니다. 토큰으로 대상 레이아웃을 찾은 뒤 상속 병합과 확장(extension) 적용까지 마친 결과를 반환하며, 토큰이 유효하지 않으면 404를 반환합니다. 인증 미들웨어가 적용되지만 실질적 보안 메커니즘은 토큰 자체이며, 레이아웃 편집기에서 저장 전 변경분을 실제 렌더링으로 확인하는 용도입니다.


### GET /api/layouts/{templateIdentifier}/{layoutName}
<!-- @generated:start:api.public.layouts.serve.extensionless -->
- **라우트명**: `api.public.layouts.serve.extensionless`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicLayoutController@serve`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| templateIdentifier | path | string | 예 | — | 대상 template의 식별자 |
| layoutName | path | string | 예 | — | 대상 layout의 이름 (식별자) |

**요청 예시**

```http
GET /api/layouts/{templateIdentifier}/{layoutName} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

확장자 없는 형태(`GET /api/layouts/preview/{token}`)와 **같은 컨트롤러 메서드**이므로 응답이 동일하다. `ResponseHelper` 봉투 없이 병합·확장 적용이 끝난 **레이아웃 JSON 자체**를 최상위로 반환한다. 필드 표는 위 항목을 참조한다.

**응답 예시**

```http
HTTP/1.1 200
Content-Type: application/json
```

```json
{
    "version": "1.0",
    "layout_name": "product_list",
    "meta": {
        "title": "상품 목록",
        "auth_required": false
    },
    "data_sources": [
        {
            "id": "products",
            "endpoint": "/api/modules/sirsoft-ecommerce/products",
            "method": "GET"
        }
    ],
    "components": [
        {
            "type": "basic",
            "name": "Div",
            "props": { "className": "container mx-auto" },
            "children": []
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 토큰에 해당하는 미리보기가 없거나 **만료된 경우** (`templates.layout_not_found`). 서버의 정적 최적화 블록이 `.json` 확장자를 가로채도 404 가 되므로, 이 경우 확장자 없는 형태로 재요청한다 |

<!-- @generated:end -->

**설명**

확장자 붙은 형태의 미리보기 서빙이다. 동작·토큰 수명(30분)·미리보기 종류는 확장자 없는 `GET /api/layouts/preview/{token}` 항목과 동일하다.

두 형태가 함께 등록되는 이유는 서버 설정 차이 때문이다. 정규식 location(`location ~* \.(js|css|json)$`)이 프리픽스 location 보다 먼저 매칭되는 nginx 구성에서는 `.json` 으로 끝나는 동적 응답이 `try_files ... /index.php` 폴백 없이 404 가 된다. 그래서 라우트는 `Route::dualSuffix()` 로 두 형태를 동시에 등록하고, 클라이언트는 `/api/system/asset-probe` 프로브 결과에 따라 어느 쪽을 쓸지 결정한다. URL 조립은 서버측 `App\Support\AssetUrl`, 프론트측 `resources/js/core/support/assetUrl.ts` 가 담당하며 직접 문자열로 조립하지 않는다.


### GET /api/layouts/{templateIdentifier}/{layoutName}.json
<!-- @generated:start:api.public.layouts.serve -->
- **라우트명**: `api.public.layouts.serve`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicLayoutController@serve`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| templateIdentifier | path | string | 예 | — | 레이아웃을 서빙할 활성 템플릿의 식별자 (예: `sirsoft-basic`) — 미존재/비활성 시 404 |
| layoutName | path | string | 예 | — | 서빙할 레이아웃 이름 (예: `product_list`) |

**요청 예시**

```http
GET /api/layouts/{templateIdentifier}/{layoutName}.json HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 는 병합된 레이아웃 JSON 객체입니다 (상속 병합 + 모듈/플러그인 확장 적용 + 사용자별 컴포넌트 권한 필터링 완료). 실제 포함 키는 저장된 레이아웃 content 에 따라 달라집니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| version | string | `"1.0"` | 레이아웃 스키마 버전 |
| layout_name | string | `"product_list"` | 레이아웃 이름 (식별자) |
| extends | string\|null | `"_user_base"` | 상속한 베이스 레이아웃 이름 (서빙 시 이미 병합 반영됨) |
| slots | object | `{"content": [ ... ]}` | 베이스 슬롯에 삽입되는 컴포넌트 배열 (extends 레이아웃) |
| components | array | `[{"type":"basic","name":"Div", ...}]` | 렌더링할 컴포넌트 트리 (병합·확장·권한 필터 적용 결과) |
| endpoint | string\|null | `"/api/products"` | 화면이 주로 fetch 하는 데이터 API 경로 (레거시·선택) |
| data_sources | array | `[{"id":"products","endpoint":"/api/products","method":"GET"}]` | 화면이 로드하는 API 데이터 소스 정의 |
| meta | object | `{"title":"상품 목록","auth_required":false}` | 메타 정보 (title, description, keywords, auth_required, is_base, guest_only, is_error_layout, error_code, seo) |
| permissions | array\|object | `["core.templates.layouts.edit"]` | 레이아웃 접근에 필요한 권한 (flat 배열=AND, 구조화 객체=OR/AND 중첩) |
| modals | object | `{}` | 모달 컴포넌트 정의 |
| state / init_state / initLocal / initGlobal / initIsolated / global_state | object | `{}` | 레이아웃 레벨 초기 상태 정의 |
| init_actions | array | `[{"handler":"apiCall", ...}]` | 레이아웃 로드 시 실행할 초기화 액션 |
| actions / named_actions | array\|object | `{}` | 재사용 가능한 액션 정의 |
| computed | object | `{}` | 계산된 값 정의 |
| defines | object | `{}` | 재사용 가능한 컴포넌트 조각 정의 |
| errorHandling | object | `{"404":{"handler":"navigate"}}` | 상태 코드별 에러 처리 정책 |
| globalHeaders | array | `[{"pattern":"*","headers":{"X-Foo":"bar"}}]` | API 호출 시 자동 적용되는 HTTP 헤더 규칙 |
| transition_overlay | object | `{"enabled":true,"style":"blur"}` | 페이지 전환 오버레이 설정 |
| routes | array | `[]` | 라우트 정의 |
| pageConfig / schema | object | `{}` | 플러그인 설정 레이아웃 전용 (설정 UI 안내/스키마) |
| lock_version | integer | `3` | 낙관적 잠금 버전. `with_source_meta=1` 일 때만 부착 (편집기 저장 시 `expected_lock_version` 으로 되돌려 보냄) |
| __editor | object | `{"original": { ... }}` | 자식 레이아웃의 저장 원본 content. `with_source_meta=1` 일 때만 부착 (편집기 전용) |
| __source (각 노드 내부) | object | `{"kind":"base","layout":"_user_base"}` | 각 컴포넌트/데이터소스 노드의 출처 메타 (`base` / `extension` / `partial` / `route`). `with_source_meta=1` 일 때만 부착 |

응답 헤더: `ETag`(본문 md5), `Cache-Control: public, max-age=3600`, `Vary: Accept-Encoding, Accept-Language`. 클라이언트 `If-None-Match` 가 일치하면 본문 없이 `304 Not Modified` 를 반환합니다.

**응답 예시**

```json
{
    "success": true,
    "message": "레이아웃이 제공되었습니다.",
    "data": {
        "version": "1.0",
        "layout_name": "product_list",
        "meta": {
            "title": "상품 목록",
            "auth_required": false
        },
        "data_sources": [
            {
                "id": "products",
                "endpoint": "/api/products",
                "method": "GET"
            }
        ],
        "components": [
            {
                "type": "basic",
                "name": "Div",
                "props": {
                    "className": "p-4"
                },
                "children": []
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 304 | Not Modified | 요청의 `If-None-Match` 가 현재 ETag 와 일치 (본문 없음) |
| 401 | Unauthorized | 비회원이 `permissions` 요구 레이아웃에 접근 (`auth.layout_guest_permission_denied`), 또는 비회원이 `with_source_meta=1` 요청 |
| 403 | Forbidden | 회원이지만 레이아웃 `permissions` 미충족 (`auth.layout_permission_denied`), 또는 `with_source_meta=1` 요청 시 `core.templates.layouts.edit` 권한 없음 |
| 404 | Not Found | 템플릿이 없거나 비활성 상태, 또는 레이아웃/부모 레이아웃 미존재 (`templates.layout_not_found`) |

<!-- @generated:end -->

**설명** 활성 템플릿의 병합된 레이아웃 JSON을 프론트엔드에 서빙합니다. 템플릿이 존재하고 활성 상태여야 하며, 상속 병합·확장 적용을 마친 결과를 ETag·Cache-Control 헤더와 함께 반환하고 미변경 시 304로 응답합니다. 레이아웃의 `permissions`에 따라 접근을 제한하고(비회원 401, 권한 부족 403), 컴포넌트 단위 권한 필터링을 사용자별로 적용합니다. 쿼리 `v`(정수 캐시 버전)로 캐시를 구분하며, `with_source_meta=1`은 `core.templates.layouts.edit` 권한이 있어야 노드별 출처 메타(편집기 전용)를 포함해 반환합니다.


