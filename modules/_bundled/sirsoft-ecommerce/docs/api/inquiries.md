# Inquiries API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Inquiries 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted | boolean | `true` | 삭제 성공 여부 (컨트롤러가 상수 `true` 로 반환) |

**응답 예시**

```json
{
    "success": true,
    "message": "문의가 삭제되었습니다.",
    "data": {
        "deleted": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.inquiries.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 문의를 찾을 수 없거나(`문의를 찾을 수 없습니다.`) 문의 게시판이 설정되지 않은 경우(`문의 게시판이 설정되지 않았습니다.`) — `ProductInquiryService::deleteInquiry()` 의 `ProductInquiryOperationException` |
| 500 | Internal Server Error | 삭제 처리 중 예기치 못한 예외 (`문의 삭제에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 관리자가 상품 1:1 문의 1건을 삭제합니다. `sirsoft-ecommerce.inquiries.delete` 권한이 필요하며, `ProductInquiryService::deleteInquiry()` 가 게시판 훅으로 질문 게시글을 소프트 삭제한 뒤 문의 피벗(`product_inquiries`)도 **소프트 삭제**하고 `{deleted: true}` 를 반환합니다. 피벗이 소프트 삭제로 유지되므로, 이후 게시판 관리 화면에서 질문 게시글을 복원하면 문의 피벗도 함께 복원되어 문의 목록에 다시 나타납니다. 문의가 존재하지 않는 등 삭제 불가 상황에서는 서비스가 `ProductInquiryOperationException` 을 던지고, 컨트롤러가 그 예외의 다국어 키로 422 응답을 만듭니다. 관리자 문의 관리 화면에서 부적절하거나 중복된 문의를 정리할 때 사용합니다.


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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted | boolean | `true` | 답변 삭제 성공 여부 (컨트롤러가 상수 `true` 로 반환) |

**응답 예시**

```json
{
    "success": true,
    "message": "답변이 삭제되었습니다.",
    "data": {
        "deleted": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.inquiries.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 문의를 찾을 수 없거나(`문의를 찾을 수 없습니다.`) 문의 게시판이 설정되지 않은 경우(`문의 게시판이 설정되지 않았습니다.`) — `ProductInquiryService::deleteReply()` 의 `ProductInquiryOperationException` |
| 500 | Internal Server Error | 답변 삭제 처리 중 예기치 못한 예외 (`답변 삭제에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 관리자가 문의에 등록된 답변을 삭제합니다. `sirsoft-ecommerce.inquiries.update` 권한이 필요하며, `ProductInquiryService::deleteReply()` 가 답변을 제거한 뒤 필터 훅 `sirsoft-ecommerce.inquiry.count_replies`(carry: int, 초기값 `0` / 인자: 부모 문의 Post ID / 반환: 살아있는 답변 수)로 잔여 답변 수를 재확인하여, **살아있는 답변이 0건일 때만** 문의를 미답변 상태(`is_answered=false`)로 되돌리고 `{deleted: true}` 를 반환합니다 — 과거 결함으로 답변이 여러 건 쌓인 문의에서 일부만 삭제해도 답변완료 표기가 유지됩니다. 문의 자체는 유지되며, 잘못 등록한 답변을 회수할 때 사용합니다.


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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 답변이 등록된 문의(피벗 `product_inquiries`)의 기본 키 |
| is_answered | boolean | `true` | 답변 등록 후의 문의 답변 완료 여부 |

**응답 예시**

```json
{
    "success": true,
    "message": "답변이 등록되었습니다.",
    "data": {
        "id": 1,
        "is_answered": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.inquiries.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) / 문의를 찾을 수 없거나(`문의를 찾을 수 없습니다.`) 문의 게시판이 설정되지 않은 경우(`문의 게시판이 설정되지 않았습니다.` — `board_not_configured`) / 이미 답변이 등록된 문의에 재등록을 시도한 경우(`이미 등록된 답변이 있습니다. 기존 답변을 수정하거나 삭제한 후 다시 등록해주세요.` — `reply_already_exists`) / 답변 게시글 생성에 실패한 경우(`답변 등록에 실패했습니다.`) — `ProductInquiryService::createReply()` 의 `ProductInquiryOperationException` |
| 500 | Internal Server Error | 답변 등록 처리 중 예기치 못한 예외 (`답변 등록에 실패했습니다.`) |

**오류 응답 예시** (이미 답변된 문의에 재등록 시 — 422)

```json
{
    "success": false,
    "message": "이미 등록된 답변이 있습니다. 기존 답변을 수정하거나 삭제한 후 다시 등록해주세요."
}
```

<!-- @generated:end -->

**설명** 관리자가 상품 문의에 답변을 등록합니다. `sirsoft-ecommerce.inquiries.update` 권한이 필요하며, `ProductInquiryService::createReply()` 가 `content`(1~5000자)로 답변을 저장하고 문의를 답변완료 상태로 전환한 뒤 `{id, is_answered}` 를 201 로 반환합니다. 문의당 답변은 1건만 유지되는 단일 답변 정책이라, 이미 답변완료(`is_answered=true`)인 문의에 재등록을 시도하면 `reply_already_exists` 사유의 422 로 거절됩니다 — 기존 답변을 수정(PUT)하거나 삭제(DELETE) 후 다시 등록해야 합니다. 문의 게시판이 미설정인 경우에도 `board_not_configured` 사유의 422 가 반환됩니다. 관리자가 고객 문의에 응대할 때 사용합니다.


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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 답변을 수정한 문의(피벗 `product_inquiries`)의 기본 키 (요청 path 의 `inquiryId` 를 그대로 반환) |

**응답 예시**

```json
{
    "success": true,
    "message": "답변이 수정되었습니다.",
    "data": {
        "id": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.inquiries.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) / 문의를 찾을 수 없거나(`문의를 찾을 수 없습니다.`) 문의 게시판이 설정되지 않은 경우(`문의 게시판이 설정되지 않았습니다.`) — `ProductInquiryService::updateReply()` 의 `ProductInquiryOperationException` |
| 500 | Internal Server Error | 답변 수정 처리 중 예기치 못한 예외 (`답변 수정에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 관리자가 문의에 이미 등록된 답변 내용을 수정합니다. `sirsoft-ecommerce.inquiries.update` 권한이 필요하며, `ProductInquiryService::updateReply()` 가 `content`(1~5000자)로 기존 답변을 갱신하고 `{id}` 를 반환합니다. 답변이 없는 문의 등 수정 불가 상황에서는 `ProductInquiryOperationException` 이 던져지고, 컨트롤러가 그 예외의 다국어 키로 422 응답을 만듭니다. 오탈자 정정 등 답변 내용을 고칠 때 사용합니다.


### GET /api/modules/sirsoft-ecommerce/user/inquiries
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.inquiries.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.inquiries.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ProductInquiryController@index`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| search | query | string | 아니오 | max 100 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| is_answered | query | boolean | 아니오 | — | answered 여부 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/inquiries?page=1&per_page=1&search=%EC%98%88%EC%8B%9C%EA%B0%92&is_answered=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| items | array | `[{"id":19,"product_id":1732,"product":{"id":1732,"product…` | 내 문의 항목 배열 (각 항목: id, product 요약, product_name, is_answered, 게시판 연동 시 title/category/content/is_secret/reply/attachments) |
| meta | object | `{"current_page":1,"per_page":25,"total":39,"last_page":2,…` | 페이지네이션 메타 (current_page/per_page/total/last_page/from/to, 문의 게시판 연동 여부 inquiry_available, abilities 답변·삭제 권한, board_settings) |

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
                "id": 19,
                "product_id": 1732,
                "product": {
                    "id": 1732,
                    "product_code": "CJTFHBL8SLRQ8ILM",
                    "name": "면 손수건 3매입 #1",
                    "thumbnail_url": "/api/modules/sirsoft-ecommerce/product-image/7df7761cdf16",
                    "url": "/shop/products/CJTFHBL8SLRQ8ILM"
                },
                "product_name": "면 손수건 3매입 #1",
                "is_answered": true,
                "...": "(9개 키 생략, 총 14개)"
            },
            {
                "id": 20,
                "product_id": 1732,
                "product": {
                    "id": 1732,
                    "product_code": "CJTFHBL8SLRQ8ILM",
                    "name": "면 손수건 3매입 #1",
                    "thumbnail_url": "/api/modules/sirsoft-ecommerce/product-image/7df7761cdf16",
                    "url": "/shop/products/CJTFHBL8SLRQ8ILM"
                },
                "product_name": "면 손수건 3매입 #1",
                "is_answered": true,
                "...": "(9개 키 생략, 총 14개)"
            },
            {
                "id": 21,
                "product_id": 1732,
                "product": {
                    "id": 1732,
                    "product_code": "CJTFHBL8SLRQ8ILM",
                    "name": "면 손수건 3매입 #1",
                    "thumbnail_url": "/api/modules/sirsoft-ecommerce/product-image/7df7761cdf16",
                    "url": "/shop/products/CJTFHBL8SLRQ8ILM"
                },
                "product_name": "면 손수건 3매입 #1",
                "is_answered": false,
                "...": "(9개 키 생략, 총 14개)"
            },
            {
                "id": 22,
                "product_id": 1732,
                "product": {
                    "id": 1732,
                    "product_code": "CJTFHBL8SLRQ8ILM",
                    "name": "면 손수건 3매입 #1",
                    "thumbnail_url": "/api/modules/sirsoft-ecommerce/product-image/7df7761cdf16",
                    "url": "/shop/products/CJTFHBL8SLRQ8ILM"
                },
                "product_name": "면 손수건 3매입 #1",
                "is_answered": true,
                "...": "(9개 키 생략, 총 14개)"
            },
            {
                "id": 23,
                "product_id": 1732,
                "product": {
                    "id": 1732,
                    "product_code": "CJTFHBL8SLRQ8ILM",
                    "name": "면 손수건 3매입 #1",
                    "thumbnail_url": "/api/modules/sirsoft-ecommerce/product-image/7df7761cdf16",
                    "url": "/shop/products/CJTFHBL8SLRQ8ILM"
                },
                "product_name": "면 손수건 3매입 #1",
                "is_answered": false,
                "...": "(9개 키 생략, 총 14개)"
            },
            "... (총 25건 중 5건 표시)"
        ],
        "meta": {
            "current_page": 1,
            "per_page": 25,
            "total": 39,
            "last_page": 2,
            "from": 1,
            "...": "(4개 키 생략, 총 9개)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**요청 파라미터**

| 이름 | 타입 | 필수 | 기본값 | 용도 |
| --- | --- | :---: | --- | --- |
| `page` | integer | - | 1 | 페이지 번호 (최소 1) |
| `per_page` | integer | - | 10 | 페이지당 건수 (1~100). 범위를 벗어나면 422 |
| `search` | string | - | - | 검색어 — **상품명**(`product_name_snapshot` 의 로케일별 값) 대상. 문의 제목·본문은 검색하지 않음 |
| `is_answered` | boolean | - | - | 답변 여부 (`0` 답변대기 / `1` 답변완료) |

**설명** 로그인 회원이 마이페이지에서 자신이 작성한 상품 문의 목록을 조회합니다. `auth:sanctum` 인증만 요구하며, `ProductInquiryService::getUserInquiries()` 가 로그인 사용자(`Auth::id()`)의 문의를 `search`(검색어)·`is_answered`(답변 여부) 필터와 `per_page`(기본 10, 허용 1~100)로 페이지네이션해 `items`(문의 배열)와 `meta`(페이지 정보)로 반환합니다. 마이페이지 문의 내역 화면을 채우는 데 사용합니다.


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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted | boolean | `true` | 삭제 성공 여부 (컨트롤러가 상수 `true` 로 반환) |

**응답 예시**

```json
{
    "success": true,
    "message": "문의가 삭제되었습니다.",
    "data": {
        "deleted": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 문의 작성자(`user_id`)가 로그인 사용자와 다른 경우 (`해당 문의에 대한 권한이 없습니다.`) |
| 404 | Not Found | 해당 ID 의 문의가 존재하지 않는 경우 (`문의를 찾을 수 없습니다.`) |
| 422 | Unprocessable Entity | 삭제할 수 없는 도메인 사유 — 문의 게시판 미설정 등. 응답 `message` 에 사유가 그대로 실린다 (`문의 게시판이 설정되지 않았습니다.`) |
| 500 | Internal Server Error | 삭제 처리 중 예기치 못한 서버 오류 (`문의 삭제에 실패했습니다.`) — 예외 원문은 응답에 포함되지 않는다 |

<!-- @generated:end -->

**설명** 로그인 회원이 자신이 작성한 문의를 삭제합니다. `auth:sanctum` 인증이 필요하며, 컨트롤러가 문의를 조회해 없으면 404, `inquiry->user_id` 가 로그인 사용자와 다르면 403 을 반환한 뒤 `ProductInquiryService::deleteInquiry()` 로 삭제하고 `{deleted: true}` 를 반환합니다. 관리자 삭제 경로와 마찬가지로 문의 피벗은 **소프트 삭제**되며, 게시판에서 질문 게시글이 복원되면 문의도 함께 복원됩니다. 마이페이지에서 본인 문의를 취소/삭제할 때 사용합니다.


### PUT /api/modules/sirsoft-ecommerce/user/inquiries/{inquiryId}
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.inquiries.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.inquiries.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ProductInquiryController@update`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| inquiryId | path | string | 예 | — | 대상 inquiry의 식별자 |
| title | body | string | 아니오 | — | 문의 제목 (연동 게시판 Post 의 제목으로 저장. 게시판 설정에 따라 min/max 길이 규칙이 Filter 훅으로 추가됨) |
| category | body | string | 아니오 | — | 문의 분류 (게시판 설정 기반 유형 슬러그, 연동 게시판 Post 로 저장) |
| content | body | string | 예 | — | 문의 본문 (필수. 게시판 설정에 따라 min/max 길이 규칙이 Filter 훅으로 추가됨) |
| is_secret | body | boolean | 아니오 | — | 비밀글 여부 (게시판 비밀글 모드 설정에 따라 적용) |

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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 수정한 문의(피벗 `product_inquiries`)의 기본 키 (요청 path 의 `inquiryId` 를 그대로 반환) |

**응답 예시**

```json
{
    "success": true,
    "message": "문의가 수정되었습니다.",
    "data": {
        "id": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 문의 작성자(`user_id`)가 로그인 사용자와 다른 경우 (`해당 문의에 대한 권한이 없습니다.`) |
| 404 | Not Found | 해당 ID 의 문의가 존재하지 않는 경우 (`문의를 찾을 수 없습니다.`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) / 문의 게시판이 설정되지 않은 경우(`문의 게시판이 설정되지 않았습니다.`) — `ProductInquiryService::updateInquiry()` 의 `ProductInquiryOperationException` |
| 500 | Internal Server Error | 수정 처리 중 예기치 못한 예외 (`문의 수정에 실패했습니다.`) |

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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted | boolean | `true` | 답변 삭제 성공 여부 (컨트롤러가 상수 `true` 로 반환) |

**응답 예시**

```json
{
    "success": true,
    "message": "답변이 삭제되었습니다.",
    "data": {
        "deleted": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 답변 권한(`sirsoft-ecommerce.inquiries.update`)이 없는 경우 (`해당 문의에 대한 권한이 없습니다.`) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 문의를 찾을 수 없거나(`문의를 찾을 수 없습니다.`) 문의 게시판이 설정되지 않은 경우(`문의 게시판이 설정되지 않았습니다.`) — `ProductInquiryService::deleteReply()` 의 `ProductInquiryOperationException` |
| 500 | Internal Server Error | 답변 삭제 처리 중 예기치 못한 예외 (`답변 삭제에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 사용자 표면에서 문의 답변 권한을 가진 회원이 문의 답변을 삭제합니다. `auth:sanctum` 인증에 더해 컨트롤러가 `PermissionHelper::check('sirsoft-ecommerce.inquiries.update')` 로 답변 권한을 확인(없으면 403)한 뒤 `ProductInquiryService::deleteReply()` 로 답변을 제거하고 `{deleted: true}` 를 반환합니다. 관리자 경로와 동일하게 필터 훅 `sirsoft-ecommerce.inquiry.count_replies`(carry: int, 초기값 `0` / 인자: 부모 문의 Post ID / 반환: 살아있는 답변 수)로 잔여 답변 수를 재확인해 0건일 때만 미답변 상태로 되돌립니다. 답변 권한을 위임받은 사용자(예: 상담원 역할)가 사용자 화면에서 답변을 회수할 때 사용합니다.


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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 답변이 등록된 문의(피벗 `product_inquiries`)의 기본 키 |
| is_answered | boolean | `true` | 답변 등록 후의 문의 답변 완료 여부 |

**응답 예시**

```json
{
    "success": true,
    "message": "답변이 등록되었습니다.",
    "data": {
        "id": 1,
        "is_answered": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 답변 권한(`sirsoft-ecommerce.inquiries.update`)이 없는 경우 (`해당 문의에 대한 권한이 없습니다.`) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) / 문의를 찾을 수 없거나(`문의를 찾을 수 없습니다.`) 문의 게시판이 설정되지 않은 경우(`문의 게시판이 설정되지 않았습니다.` — `board_not_configured`) / 이미 답변이 등록된 문의에 재등록을 시도한 경우(`이미 등록된 답변이 있습니다. 기존 답변을 수정하거나 삭제한 후 다시 등록해주세요.` — `reply_already_exists`) / 답변 게시글 생성에 실패한 경우(`답변 등록에 실패했습니다.`) — `ProductInquiryService::createReply()` 의 `ProductInquiryOperationException` |
| 500 | Internal Server Error | 답변 등록 처리 중 예기치 못한 예외 (`답변 등록에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 사용자 표면에서 문의 답변 권한을 가진 회원이 문의에 답변을 등록합니다. `auth:sanctum` 인증에 더해 컨트롤러가 `PermissionHelper::check('sirsoft-ecommerce.inquiries.update')` 로 답변 권한을 확인(없으면 403)한 뒤 `ProductInquiryService::createReply()` 가 `content`(1~5000자)로 답변을 저장하고 문의를 답변완료로 전환해 `{id, is_answered}` 를 201 로 반환합니다. 관리자 경로와 동일한 단일 답변 정책이 적용되어, 이미 답변완료인 문의에 재등록하면 `reply_already_exists` 사유의 422, 문의 게시판 미설정이면 `board_not_configured` 사유의 422 로 거절됩니다. 관리자 화면이 아닌 사용자 프론트에서 답변을 처리하는 상담원 역할에 사용합니다.


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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 답변을 수정한 문의(피벗 `product_inquiries`)의 기본 키 (요청 path 의 `inquiryId` 를 그대로 반환) |

**응답 예시**

```json
{
    "success": true,
    "message": "답변이 수정되었습니다.",
    "data": {
        "id": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 답변 권한(`sirsoft-ecommerce.inquiries.update`)이 없는 경우 (`해당 문의에 대한 권한이 없습니다.`) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) / 문의를 찾을 수 없거나(`문의를 찾을 수 없습니다.`) 문의 게시판이 설정되지 않은 경우(`문의 게시판이 설정되지 않았습니다.`) — `ProductInquiryService::updateReply()` 의 `ProductInquiryOperationException` |
| 500 | Internal Server Error | 답변 수정 처리 중 예기치 못한 예외 (`답변 수정에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 사용자 표면에서 문의 답변 권한을 가진 회원이 기존 문의 답변을 수정합니다. `auth:sanctum` 인증에 더해 컨트롤러가 `PermissionHelper::check('sirsoft-ecommerce.inquiries.update')` 로 답변 권한을 확인(없으면 403)한 뒤 `ProductInquiryService::updateReply()` 가 `content`(1~5000자)로 답변을 갱신하고 `{id}` 를 반환합니다. 사용자 프론트에서 답변을 처리하는 상담원 역할이 답변 내용을 정정할 때 사용합니다.


