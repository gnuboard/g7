# Inquiries API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Inquiries 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### DELETE /api/modules/sirsoft-ecommerce/admin/inquiries/{inquiryId}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.inquiries.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.inquiries.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductInquiryController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.inquiries.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| inquiryId | path | string | 예 | — | 대상 inquiry의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/inquiries/{inquiryId} HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.inquiries.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 상품 1:1 문의 1건을 삭제합니다. `sirsoft-ecommerce.inquiries.delete` 권한이 필요하며, `ProductInquiryService::deleteInquiry()` 가 해당 문의를 제거하고 `{deleted: true}` 를 반환합니다. 문의가 존재하지 않는 등 삭제 불가 상황에서는 서비스가 `RuntimeException` 을 던져 422 로 응답합니다. 관리자 문의 관리 화면에서 부적절하거나 중복된 문의를 정리할 때 사용합니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/inquiries/{inquiryId}/reply
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.inquiries.reply.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.inquiries.reply.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductInquiryController@destroyReply`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.inquiries.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| inquiryId | path | string | 예 | — | 대상 inquiry의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/inquiries/{inquiryId}/reply HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.inquiries.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 문의에 등록된 답변을 삭제합니다. `sirsoft-ecommerce.inquiries.update` 권한이 필요하며, `ProductInquiryService::deleteReply()` 가 답변을 제거하고 문의를 미답변 상태로 되돌린 뒤 `{deleted: true}` 를 반환합니다. 문의 자체는 유지되며, 잘못 등록한 답변을 회수할 때 사용합니다.


### POST /api/modules/sirsoft-ecommerce/admin/inquiries/{inquiryId}/reply
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.inquiries.reply -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.inquiries.reply`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductInquiryController@reply`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.inquiries.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| inquiryId | path | string | 예 | — | 대상 inquiry의 식별자 |
| content | body | string | 예 | min 1, max 5000 | 본문 내용 |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/inquiries/{inquiryId}/reply HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "content": "예시 내용입니다."
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.inquiries.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 상품 문의에 답변을 등록합니다. `sirsoft-ecommerce.inquiries.update` 권한이 필요하며, `ProductInquiryService::createReply()` 가 `content`(1~5000자)로 답변을 저장하고 문의를 답변완료 상태로 전환한 뒤 `{id, is_answered}` 를 201 로 반환합니다. 이미 답변이 있는 등 등록 불가 상황에서는 `RuntimeException` 이 던져져 422 로 응답합니다. 관리자가 고객 문의에 응대할 때 사용합니다.


### PUT /api/modules/sirsoft-ecommerce/admin/inquiries/{inquiryId}/reply
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.inquiries.reply.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.inquiries.reply.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductInquiryController@updateReply`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.inquiries.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| inquiryId | path | string | 예 | — | 대상 inquiry의 식별자 |
| content | body | string | 예 | min 1, max 5000 | 본문 내용 |

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/inquiries/{inquiryId}/reply HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "content": "예시 내용입니다."
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.inquiries.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 문의에 이미 등록된 답변 내용을 수정합니다. `sirsoft-ecommerce.inquiries.update` 권한이 필요하며, `ProductInquiryService::updateReply()` 가 `content`(1~5000자)로 기존 답변을 갱신하고 `{id}` 를 반환합니다. 답변이 없는 문의 등 수정 불가 상황에서는 `RuntimeException` 이 던져져 422 로 응답합니다. 오탈자 정정 등 답변 내용을 고칠 때 사용합니다.


### GET /api/modules/sirsoft-ecommerce/user/inquiries
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.inquiries.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.inquiries.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ProductInquiryController@index`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/inquiries HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| items | array | `[]` | 내 문의 항목 배열 (각 항목: id, product 요약, product_name, is_answered, 게시판 연동 시 title/category/content/is_secret/reply/attachments) |
| meta | object | `{"current_page":1,"per_page":25,"total":0,"last_page":1,"…` | 페이지네이션 메타 (current_page/per_page/total/last_page/from/to, 문의 게시판 연동 여부 inquiry_available, abilities 답변·삭제 권한, board_settings) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "문의 목록을 조회했습니다.",
    "data": {
        "items": [
            {
                "id": 1,
                "product_id": 1,
                "product": {
                    "id": 1,
                    "name": "API 문서 샘플 상품",
                    "thumbnail_url": null,
                    "url": "/shop/products/1"
                },
                "product_name": "iste et inventore",
                "is_answered": false,
                "answered_at": null,
                "created_at": "2026-07-08T01:44:49+00:00",
                "updated_at": "2026-07-08T01:44:49+00:00",
                "title": null,
                "category": null,
                "content": null,
                "is_secret": "{MASKED}",
                "reply": null,
                "attachments": []
            }
        ],
        "meta": {
            "current_page": 1,
            "per_page": 25,
            "total": 1,
            "last_page": 1,
            "from": 1,
            "to": 1,
            "inquiry_available": false,
            "abilities": {
                "can_update": true,
                "can_delete": true
            },
            "board_settings": {
                "secret_mode": "{MASKED}",
                "categories": [],
                "use_file_upload": false,
                "max_file_count": 5,
                "max_file_size": 10485760,
                "allowed_extensions": [],
                "min_title_length": 2,
                "max_title_length": 200,
                "min_content_length": 10,
                "max_content_length": 10000
            }
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명** 로그인 회원이 마이페이지에서 자신이 작성한 상품 문의 목록을 조회합니다. `auth:sanctum` 인증만 요구하며, `ProductInquiryService::getUserInquiries()` 가 로그인 사용자(`Auth::id()`)의 문의를 `search`(검색어)·`is_answered`(답변 여부) 필터와 `per_page`(기본 10)로 페이지네이션해 `items`(문의 배열)와 `meta`(페이지 정보)로 반환합니다. 마이페이지 문의 내역 화면을 채우는 데 사용합니다.


### DELETE /api/modules/sirsoft-ecommerce/user/inquiries/{inquiryId}
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.inquiries.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.inquiries.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ProductInquiryController@destroy`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| inquiryId | path | string | 예 | — | 대상 inquiry의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/user/inquiries/{inquiryId} HTTP/1.1
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
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 로그인 회원이 자신이 작성한 문의를 삭제합니다. `auth:sanctum` 인증이 필요하며, 컨트롤러가 문의를 조회해 없으면 404, `inquiry->user_id` 가 로그인 사용자와 다르면 403 을 반환한 뒤 `ProductInquiryService::deleteInquiry()` 로 삭제하고 `{deleted: true}` 를 반환합니다. 마이페이지에서 본인 문의를 취소/삭제할 때 사용합니다.


### PUT /api/modules/sirsoft-ecommerce/user/inquiries/{inquiryId}
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.inquiries.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.inquiries.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ProductInquiryController@update`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| inquiryId | path | string | 예 | — | 대상 inquiry의 식별자 |
| title | body | string | 아니오 | — | 제목 |
| category | body | string | 아니오 | — | 문의 분류 (게시판 설정 기반 유형 슬러그, 연동 게시판 Post 로 저장) |
| content | body | string | 예 | — | 본문 내용 |
| is_secret | body | boolean | 아니오 | — | secret 여부 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.inquiry.update_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/user/inquiries/{inquiryId} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "title": "예시 제목",
    "category": "예시값",
    "content": "예시 내용입니다.",
    "is_secret": true
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 로그인 회원이 자신이 작성한 문의 내용을 수정합니다. `auth:sanctum` 인증이 필요하며, 컨트롤러가 문의를 조회해 없으면 404, `inquiry->user_id` 가 로그인 사용자와 다르면 403 을 반환한 뒤 `ProductInquiryService::updateInquiry()` 로 제목·분류·본문·비밀글 여부를 갱신하고 `{id}` 를 반환합니다. `content` 는 필수이며, 마이페이지에서 아직 답변되지 않은 본인 문의를 고칠 때 사용합니다.


### DELETE /api/modules/sirsoft-ecommerce/user/inquiries/{inquiryId}/reply
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.inquiries.reply.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.inquiries.reply.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ProductInquiryController@destroyReply`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| inquiryId | path | string | 예 | — | 대상 inquiry의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/user/inquiries/{inquiryId}/reply HTTP/1.1
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
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 사용자 표면에서 문의 답변 권한을 가진 회원이 문의 답변을 삭제합니다. `auth:sanctum` 인증에 더해 컨트롤러가 `PermissionHelper::check('sirsoft-ecommerce.inquiries.update')` 로 답변 권한을 확인(없으면 403)한 뒤 `ProductInquiryService::deleteReply()` 로 답변을 제거하고 `{deleted: true}` 를 반환합니다. 답변 권한을 위임받은 사용자(예: 상담원 역할)가 사용자 화면에서 답변을 회수할 때 사용합니다.


### POST /api/modules/sirsoft-ecommerce/user/inquiries/{inquiryId}/reply
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.inquiries.reply -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.inquiries.reply`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ProductInquiryController@reply`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| inquiryId | path | string | 예 | — | 대상 inquiry의 식별자 |
| content | body | string | 예 | min 1, max 5000 | 본문 내용 |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/user/inquiries/{inquiryId}/reply HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "content": "예시 내용입니다."
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 사용자 표면에서 문의 답변 권한을 가진 회원이 문의에 답변을 등록합니다. `auth:sanctum` 인증에 더해 컨트롤러가 `PermissionHelper::check('sirsoft-ecommerce.inquiries.update')` 로 답변 권한을 확인(없으면 403)한 뒤 `ProductInquiryService::createReply()` 가 `content`(1~5000자)로 답변을 저장하고 문의를 답변완료로 전환해 `{id, is_answered}` 를 201 로 반환합니다. 관리자 화면이 아닌 사용자 프론트에서 답변을 처리하는 상담원 역할에 사용합니다.


### PUT /api/modules/sirsoft-ecommerce/user/inquiries/{inquiryId}/reply
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.inquiries.reply.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.inquiries.reply.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ProductInquiryController@updateReply`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| inquiryId | path | string | 예 | — | 대상 inquiry의 식별자 |
| content | body | string | 예 | min 1, max 5000 | 본문 내용 |

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/user/inquiries/{inquiryId}/reply HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "content": "예시 내용입니다."
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 사용자 표면에서 문의 답변 권한을 가진 회원이 기존 문의 답변을 수정합니다. `auth:sanctum` 인증에 더해 컨트롤러가 `PermissionHelper::check('sirsoft-ecommerce.inquiries.update')` 로 답변 권한을 확인(없으면 403)한 뒤 `ProductInquiryService::updateReply()` 가 `content`(1~5000자)로 답변을 갱신하고 `{id}` 를 반환합니다. 사용자 프론트에서 답변을 처리하는 상담원 역할이 답변 내용을 정정할 때 사용합니다.


