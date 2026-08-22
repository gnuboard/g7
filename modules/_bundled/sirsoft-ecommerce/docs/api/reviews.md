# Reviews API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Reviews 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/admin/reviews
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.reviews.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.reviews.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductReviewController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.reviews.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| search_field | query | string | 아니오 | `all`, `product_name`, `reviewer`, `content`, `order_number`, `option_name` | 검색 대상 필드명 (검색어를 적용할 컬럼) |
| search_keyword | query | string | 아니오 | max 200 | 검색 키워드 (부분 일치) |
| rating | query | string | 아니오 | `1`, `2`, `3`, `4`, `5`, `` | 별점 필터 (해당 별점의 리뷰만 조회, 빈 값은 전체) |
| reply_status | query | string | 아니오 | `all`, `replied`, `unreplied` | 답변 상태 필터 (답변완료/미답변) |
| photo | query | string | 아니오 | `photo`, `normal`, `` | 포토 리뷰 필터 (이미지 첨부 여부, 빈 값은 전체) |
| has_photo | query | boolean | 아니오 | — | photo 여부 |
| status | query | string | 아니오 | — | 상태 필터 (해당 상태의 항목만 조회) |
| start_date | query | date | 아니오 | — | 조회 기간 시작일 (이 날짜 이후 데이터) |
| end_date | query | date | 아니오 | — | 조회 기간 종료일 (이 날짜 이전 데이터) |
| sort | query | string | 아니오 | `created_at_desc`, `created_at_asc`, `rating_desc`, `rating_asc` | 정렬 기준 (필드명, `-` 접두 시 내림차순) |
| sort_by | query | string | 아니오 | `created_at`, `rating`, `reply_status` | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| per_page | query | integer | 아니오 | min 10, max 100 | 페이지당 항목 수 |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.review.list_validation_rules`, `sirsoft-ecommerce.review.list_validation_messages`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/reviews?search_field=all&search_keyword=%EC%98%88%EC%8B%9C%EA%B0%92&rating=1&reply_status=all&photo=photo&has_photo=1&status=%EC%98%88%EC%8B%9C%EA%B0%92&start_date=2026-01-01&end_date=2026-01-01&sort=created_at_desc&sort_by=created_at&sort_order=asc&per_page=1&page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `99` | 기본 키 (내부 식별자) |
| product_id | integer | `320` | product 식별자 (연관 리소스 참조) |
| order_option_id | integer | `859` | order option 식별자 (연관 리소스 참조) |
| user_id | string | `a231747f-e82e-4cf2-9ae1-a261849dce40` | user 식별자 (연관 리소스 참조) |
| user | object | `{"uuid":"a231747f-e82e-4cf2-9ae1-a261849dce40","name":"AP…` | 작성자 정보 (uuid·name·email, `user` 관계 로드 시) |
| product | object | `{"id":320,"name":"API 문서 샘플 상품","thumbnail_url":null}` | 리뷰 대상 상품 정보 (id·현지화 상품명·썸네일 URL) |
| option_snapshot | string | `{"id":104,"option_code":"FWACBAVCBKCD…` | 주문 시점 옵션 스냅샷 (옵션명 보존용) |
| option_snapshot_label | string | `` | `option_snapshot` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| rating | integer | `5` | 별점 (1~5) |
| content | string | `Molestiae repellendus accusantium omn…` | 리뷰 내용 |
| content_mode | string | `text` | 콘텐츠 모드: text / html |
| status | string | `visible` | 리뷰 상태: visible(전시중) / hidden(숨김) |
| status_label | string | `전시중` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| status_badge_color | string | `blue` | 상태 뱃지 색상 (visible=blue / hidden=gray) |
| images | array | `[]` | 첨부 이미지 목록 (이미지 리소스 배열, `images` 관계 로드 시) |
| image_count | integer | `0` | image 개수 (집계) |
| orderOption | object | `{"id":859,"order_id":455,"order_number":"ORD-20260707-000…` | 리뷰가 연결된 주문 옵션 정보 (주문 ID·주문번호·수량·주문일) |
| has_reply | boolean | `false` | reply 여부 |
| has_reply_label | string | `미답변` | `has_reply` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| has_reply_badge_color | string | `gray` | 답변 여부 뱃지 색상 (답변완료=green / 미답변=gray) |
| reply_content | string | `소중한 리뷰 감사드립니다! 항상 최선을 다하겠습니다.` | 판매자 답변 내용 (없으면 null) |
| reply_content_mode | string | `text` | 답변 콘텐츠 모드: text / html |
| reply_admin_uuid | string | `a2397737-f8da-4451-a9c0-2616e7cd2002` | 답변 작성 관리자 UUID (`replyAdmin` 관계 로드 시) |
| reply_admin | object | `{"uuid":"a2397737-f8da-4451-a9c0-2616e7cd2002","name":"관리…` | 답변 작성 관리자 정보 (uuid·name·email, `replyAdmin` 관계 로드 시) |
| replied_at | string | `2026-07-06 19:08:45` | replied 일시 |
| reply_updated_at | string | `2026-07-07 19:08:45` | reply updated 일시 |
| created_at | string | `2026-07-07 14:47:31` | 생성 일시 |
| updated_at | string | `2026-07-07 14:47:31` | 최종 수정 일시 |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "리뷰 목록을 조회했습니다.",
    "data": {
        "data": [],
        "links": {
            "first": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/reviews?page=1",
            "last": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/reviews?page=1",
            "prev": null,
            "next": null
        },
        "meta": {
            "current_page": 1,
            "from": null,
            "last_page": 1,
            "links": [
                {
                    "url": null,
                    "label": "pagination.previous",
                    "page": null,
                    "active": false
                },
                {
                    "url": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/reviews?page=1",
                    "label": "1",
                    "page": 1,
                    "active": true
                },
                {
                    "url": null,
                    "label": "pagination.next",
                    "page": null,
                    "active": false
                }
            ],
            "path": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/reviews",
            "per_page": 25,
            "to": null,
            "total": 0
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.reviews.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 전체 상품 리뷰를 페이지네이션으로 조회합니다. `sirsoft-ecommerce.reviews.read` 권한이 필요하며, `ProductReviewService::getAdminList()`가 검색어·별점·답변 여부·포토 여부·상태·기간 등 필터와 정렬을 적용해 목록을 반환합니다. 각 항목에는 작성자·상품·주문옵션·이미지·답변 정보가 함께 로드되고, `abilities` 로 현재 관리자의 수정/삭제 가능 여부가 내려옵니다. 리뷰 관리 화면의 목록 표를 채우는 데 사용합니다.


### POST /api/modules/sirsoft-ecommerce/admin/reviews/bulk
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.reviews.bulk -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.reviews.bulk`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductReviewController@bulk`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.reviews.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |
| action | body | string | 예 | `delete`, `change_status` | 일괄 작업 종류 (delete=삭제, change_status=상태 변경) |
| status | body | string | 아니오 | — | 변경할 리뷰 상태 (visible/hidden, `action=change_status` 시 필수) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.review.bulk_validation_rules`, `sirsoft-ecommerce.review.bulk_validation_messages`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/reviews/bulk HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        "예시값"
    ],
    "action": "delete",
    "status": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드. `action` 값에 따라 반환 키가 달라집니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted_count | integer | `3` | 삭제된 리뷰 건수 (`action=delete` 일 때만 반환) |
| updated_count | integer | `3` | 상태가 변경된 리뷰 건수 (`action=change_status` 일 때만 반환) |

**응답 예시**

`action=delete` (message: `messages.reviews.bulk_deleted`)

```json
{
    "success": true,
    "message": "선택한 리뷰가 삭제되었습니다.",
    "data": {
        "deleted_count": 3
    }
}
```

`action=change_status` (message: `messages.reviews.bulk_updated`)

```json
{
    "success": true,
    "message": "선택한 리뷰의 상태가 변경되었습니다.",
    "data": {
        "updated_count": 3
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.reviews.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`ids` 누락/빈 배열, `ids.*` 가 존재하지 않는 리뷰 ID, `action` 이 `delete`/`change_status` 외의 값, `action=change_status` 인데 `status` 누락, `status` 가 `visible`/`hidden` 외의 값) |
| 500 | Internal Server Error | 일괄 처리 중 예외 발생 (`messages.reviews.bulk_failed` — "일괄 처리에 실패했습니다.") |

<!-- @generated:end -->

**설명** 관리자가 선택한 여러 리뷰를 한 번에 일괄 처리합니다. `sirsoft-ecommerce.reviews.update` 권한이 필요하며, `action` 이 `delete` 이면 `ProductReviewService::bulkDelete()` 로 삭제하고 `deleted_count` 를, `change_status` 이면 `bulkUpdateStatus()` 로 `status` 값으로 상태를 변경하고 `updated_count` 를 반환합니다. `change_status` 를 선택했다면 `status` 값이 반드시 필요합니다. 목록 화면에서 체크박스로 다건 선택 후 삭제/전시상태 변경 시 사용합니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/reviews/{review}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.reviews.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.reviews.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductReviewController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.reviews.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| review | path | string | 예 | — | 대상 review의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/reviews/{review} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: 삭제 성공 플래그만 반환한다 (Resource 로 감싸지 않는다)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted | boolean | `true` | 삭제 성공 여부. 성공 응답에서는 항상 `true` 이며, 실패는 `500` 으로 갈린다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "리뷰가 삭제되었습니다.",
    "data": {
        "deleted": true
    }
}
```

> 삭제 전 컨트롤러가 `images` 관계를 로드한다 — 첨부 이미지 파일까지 함께 정리하기 위해서다. DB CASCADE 에 맡기지 않고 Service 가 명시적으로 삭제하므로 훅 발화와 파일 정리가 보장된다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.reviews.delete`)이 없는 경우 |
| 404 | Not Found | 해당 `review` 의 리뷰가 없는 경우 (라우트 모델 바인딩이 해석에 실패) |
| 500 | Internal Server Error | 삭제 처리 중 예외 발생 (`messages.reviews.delete_failed`) |

<!-- @generated:end -->

**설명** 관리자가 리뷰 1건을 삭제합니다. `sirsoft-ecommerce.reviews.delete` 권한이 필요하며, 삭제 전 `images` 관계를 로드한 뒤 `ProductReviewService::deleteReview()` 가 첨부 이미지 파일까지 함께 정리하며 리뷰를 제거합니다. 라우트 모델 바인딩으로 존재하지 않는 리뷰는 404 를 반환합니다. 부적절한 리뷰를 관리자 화면에서 개별 삭제할 때 사용합니다.


### GET /api/modules/sirsoft-ecommerce/admin/reviews/{review}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.reviews.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.reviews.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductReviewController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.reviews.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| review | path | string | 예 | — | 대상 review의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/reviews/{review} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `99` | 기본 키 (내부 식별자) |
| product_id | integer | `320` | 상품 ID |
| order_option_id | integer | `859` | 주문 옵션 ID |
| user_id | string | `a231747f-e82e-4cf2-9ae1-a261849dce40` | 작성자 ID |
| user | object | `{"uuid":"a231747f-e82e-4cf2-9ae1-a261849dce40","name":"AP…` | 작성자 정보 (uuid·name·email, `user` 관계 로드 시) |
| product | object | `{"id":320,"name":"API 문서 샘플 상품","thumbnail_url":null}` | 리뷰 대상 상품 정보 (id·현지화 상품명·썸네일 URL) |
| option_snapshot | string | `{"id":104,"option_code":"FWACBAVCBKCD…` | 주문 시점 옵션 스냅샷 (옵션명 보존용) |
| option_snapshot_label | string | `` | `option_snapshot` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| rating | integer | `5` | 별점 (1~5) |
| content | string | `Molestiae repellendus accusantium omn…` | 리뷰 내용 |
| content_mode | string | `text` | 콘텐츠 모드: text / html |
| status | string | `visible` | 리뷰 상태: visible / hidden |
| status_label | string | `전시중` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| status_badge_color | string | `blue` | 상태 뱃지 색상 (visible=blue / hidden=gray) |
| images | array | `[]` | 첨부 이미지 목록 (이미지 리소스 배열, `images` 관계 로드 시) |
| image_count | integer | `0` | image 개수 (집계) |
| orderOption | object | `{"id":859,"order_id":455,"order_number":"ORD-20260707-000…` | 리뷰가 연결된 주문 옵션 정보 (주문 ID·주문번호·수량·주문일) |
| has_reply | boolean | `false` | reply 여부 |
| has_reply_label | string | `미답변` | `has_reply` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| has_reply_badge_color | string | `gray` | 답변 여부 뱃지 색상 (답변완료=green / 미답변=gray) |
| reply_content | null | `null` | 판매자 답변 내용 |
| reply_content_mode | string | `text` | 답변 콘텐츠 모드: text / html |
| reply_admin_uuid | null | `null` | 답변 작성 관리자 UUID (`replyAdmin` 관계 로드 시) |
| reply_admin | null | `null` | 답변 작성 관리자 정보 (uuid·name·email, `replyAdmin` 관계 로드 시) |
| replied_at | null | `null` | replied 일시 |
| reply_updated_at | null | `null` | reply updated 일시 |
| created_at | string | `2026-07-07 14:47:31` | 생성 일시 |
| updated_at | string | `2026-07-07 14:47:31` | 최종 수정 일시 |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "리뷰를 조회했습니다.",
    "data": {
        "id": 99,
        "product_id": 320,
        "order_option_id": 859,
        "user_id": "a231747f-e82e-4cf2-9ae1-a261849dce40",
        "user": {
            "uuid": "a231747f-e82e-4cf2-9ae1-a261849dce40",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com"
        },
        "product": {
            "id": 320,
            "name": "API 문서 샘플 상품",
            "thumbnail_url": null
        },
        "option_snapshot": "{\"id\":104,\"option_code\":\"FWACBAVCBKCD\"}",
        "option_snapshot_label": "",
        "rating": 5,
        "content": "Molestiae repellendus accusantium omnis.",
        "content_mode": "text",
        "status": "visible",
        "status_label": "전시중",
        "status_badge_color": "blue",
        "images": [],
        "image_count": 0,
        "orderOption": {
            "id": 859,
            "order_id": 455,
            "order_number": "ORD-20260707-000123",
            "quantity": 1,
            "ordered_at": "2026-07-07 14:40:00"
        },
        "has_reply": false,
        "has_reply_label": "미답변",
        "has_reply_badge_color": "gray",
        "reply_content": null,
        "reply_content_mode": "text",
        "reply_admin_uuid": null,
        "reply_admin": null,
        "replied_at": null,
        "reply_updated_at": null,
        "created_at": "2026-07-07 14:47:31",
        "updated_at": "2026-07-07 14:47:31",
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.reviews.read`)이 없는 경우 |
| 404 | Not Found | 해당 `review` 의 리뷰가 없는 경우 (라우트 모델 바인딩이 해석에 실패) |
| 500 | Internal Server Error | 조회 중 예외 발생 (`messages.reviews.fetch_failed`) |

<!-- @generated:end -->

**설명** 관리자가 리뷰 1건의 상세 정보를 조회합니다. `sirsoft-ecommerce.reviews.read` 권한이 필요하며, 컨트롤러가 `user`·`product`·`images`·`replyAdmin`·`orderOption.order` 관계를 함께 로드해 작성자·상품·이미지·판매자 답변·주문 정보까지 포함한 단건 리소스를 반환합니다. 관리자 리뷰 상세/답변 작성 화면 진입 시 사용합니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/reviews/{review}/reply
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.reviews.reply.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.reviews.reply.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductReviewController@destroyReply`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.reviews.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| review | path | string | 예 | — | 대상 review의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/reviews/{review}/reply HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| product_id | integer | `1` | product 식별자 (연관 리소스 참조) |
| order_option_id | integer | `1` | order option 식별자 (연관 리소스 참조) |
| user_id | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | user 식별자 (연관 리소스 참조) |
| user | object | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"AP…` | 대상 사용자 정보 객체 (uuid/name/email 등 — user 관계 파생) |
| option_snapshot | string | `{"id":104,"option_code":"FWACBAVCBKCD…` | 주문 시점 옵션 스냅샷 (옵션명 보존용) |
| option_snapshot_label | string | `` | `option_snapshot` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| rating | integer | `5` | 별점 (1~5) |
| content | string | `Alias quas iusto dolorem eum eveniet …` | 본문 내용 |
| content_mode | string | `text` | 콘텐츠 모드: text / html |
| status | string | `visible` | 상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조) |
| status_label | string | `전시중` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| status_badge_color | string | `blue` | 상태 뱃지 색상 (visible=blue / hidden=gray) |
| has_reply | boolean | `false` | reply 여부 |
| has_reply_label | string | `미답변` | `has_reply` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| has_reply_badge_color | string | `gray` | 답변 여부 뱃지 색상 (답변완료=green / 미답변=gray) |
| reply_content | null | `null` | 판매자 답변 내용 |
| reply_content_mode | string | `text` | 답변 콘텐츠 모드: text / html |
| replied_at | null | `null` | replied 일시 |
| reply_updated_at | null | `null` | reply updated 일시 |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 10:44:49` | 최종 수정 일시 |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "판매자 답변이 삭제되었습니다.",
    "data": {
        "id": 1,
        "product_id": 1,
        "order_option_id": 1,
        "user_id": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "user": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com"
        },
        "option_snapshot": "{\"id\":104,\"option_code\":\"FWACBAVCBKCD\"}",
        "option_snapshot_label": "",
        "rating": 5,
        "content": "Alias quas iusto dolorem eum eveniet.",
        "content_mode": "text",
        "status": "visible",
        "status_label": "전시중",
        "status_badge_color": "blue",
        "has_reply": false,
        "has_reply_label": "미답변",
        "has_reply_badge_color": "gray",
        "reply_content": null,
        "reply_content_mode": "text",
        "replied_at": null,
        "reply_updated_at": null,
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-08-16 01:30:00",
        "abilities": {
            "can_update": true,
            "can_delete": true
        }
    }
}
```

> 답변이 지워진 결과가 응답에 그대로 반영된다 — `has_reply` 는 `false`, `reply_content`·`replied_at`·`reply_updated_at` 은 `null` 로 비워진다. 리뷰 본문(`content`·`rating`·`status`)은 그대로 유지된다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.reviews.update`)이 없는 경우 |
| 404 | Not Found | 해당 `review` 의 리뷰가 없는 경우 (라우트 모델 바인딩이 해석에 실패) |
| 500 | Internal Server Error | 답변 삭제 처리 중 예외 발생 (`messages.reviews.reply_delete_failed`) |

<!-- @generated:end -->

**설명** 관리자가 리뷰에 등록한 판매자 답변을 삭제합니다. `sirsoft-ecommerce.reviews.update` 권한이 필요하며, `ProductReviewService::deleteReply()` 가 답변 내용·작성자·작성 일시를 비우고 답변이 제거된 리뷰 리소스를 반환합니다. 잘못 작성한 답변을 회수할 때 사용하며, 리뷰 자체는 유지됩니다.


### POST /api/modules/sirsoft-ecommerce/admin/reviews/{review}/reply
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.reviews.reply.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.reviews.reply.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductReviewController@storeReply`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.reviews.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| review | path | string | 예 | — | 대상 review의 식별자 |
| reply_content | body | string | 예 | min 1, max 2000 | 판매자 답변 내용 (1~2000자) |
| reply_content_mode | body | string | 아니오 | `text`, `html` | 답변 콘텐츠 모드 (평문/HTML, 미지정 시 text) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.review.store_reply_validation_rules`, `sirsoft-ecommerce.review.store_reply_validation_messages`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/reviews/{review}/reply HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "reply_content": "예시 내용입니다.",
    "reply_content_mode": "text"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| product_id | integer | `1` | product 식별자 (연관 리소스 참조) |
| order_option_id | integer | `1` | order option 식별자 (연관 리소스 참조) |
| user_id | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | user 식별자 (연관 리소스 참조) |
| user | object | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"AP…` | 대상 사용자 정보 객체 (uuid/name/email 등 — user 관계 파생) |
| option_snapshot | string | `{"id":104,"option_code":"FWACBAVCBKCD…` | 주문 시점 옵션 스냅샷 (옵션명 보존용) |
| option_snapshot_label | string | `` | `option_snapshot` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| rating | integer | `5` | 별점 (1~5) |
| content | string | `Alias quas iusto dolorem eum eveniet …` | 본문 내용 |
| content_mode | string | `text` | 콘텐츠 모드: text / html |
| status | string | `visible` | 상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조) |
| status_label | string | `전시중` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| status_badge_color | string | `blue` | 상태 뱃지 색상 (visible=blue / hidden=gray) |
| has_reply | boolean | `true` | reply 여부 |
| has_reply_label | string | `답변완료` | `has_reply` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| has_reply_badge_color | string | `green` | 답변 여부 뱃지 색상 (답변완료=green / 미답변=gray) |
| reply_content | string | `실측 예시값` | 판매자 답변 내용 |
| reply_content_mode | string | `text` | 답변 콘텐츠 모드: text / html |
| replied_at | string | `2026-07-08 15:00:32` | replied 일시 |
| reply_updated_at | null | `null` | reply updated 일시 |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 |
| updated_at | string | `2026-07-08 15:00:32` | 최종 수정 일시 |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "판매자 답변이 저장되었습니다.",
    "data": {
        "id": 1,
        "product_id": 1,
        "order_option_id": 1,
        "user_id": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "user": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com"
        },
        "option_snapshot": "{\"id\":104,\"option_code\":\"FWACBAVCBKCD\"}",
        "option_snapshot_label": "",
        "rating": 5,
        "content": "Alias quas iusto dolorem eum eveniet.",
        "content_mode": "text",
        "status": "visible",
        "status_label": "전시중",
        "status_badge_color": "blue",
        "has_reply": true,
        "has_reply_label": "답변완료",
        "has_reply_badge_color": "green",
        "reply_content": "소중한 후기 감사합니다. 앞으로도 좋은 상품으로 보답하겠습니다.",
        "reply_content_mode": "text",
        "replied_at": "2026-08-16 01:30:00",
        "reply_updated_at": "2026-08-16 01:30:00",
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-08-16 01:30:00",
        "abilities": {
            "can_update": true,
            "can_delete": true
        }
    }
}
```

> 답변 저장 결과가 응답에 반영된다 — `has_reply` 는 `true`, `has_reply_badge_color` 는 `green` 으로 바뀌고 `replied_at`·`reply_updated_at` 이 채워진다. 이미 답변이 있던 리뷰를 다시 호출하면 내용이 **갱신**되며(중복 답변이 생기지 않는다) `reply_updated_at` 만 새로 갱신된다. 답변 작성자는 요청한 관리자(`Auth::id()`)로 기록된다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.reviews.update`)이 없는 경우 |
| 404 | Not Found | 해당 `review` 의 리뷰가 없는 경우 (라우트 모델 바인딩이 해석에 실패) |
| 422 | Unprocessable Entity | `reply_content` 가 비었거나 1~2000자 범위를 벗어난 경우, `reply_content_mode` 가 `text`/`html` 이 아닌 경우 |
| 500 | Internal Server Error | 답변 저장 중 예외 발생 (`messages.reviews.reply_save_failed`) |

<!-- @generated:end -->

**설명** 관리자가 리뷰에 판매자 답변을 등록하거나 기존 답변을 수정합니다. `sirsoft-ecommerce.reviews.update` 권한이 필요하며, `ProductReviewService::saveReply()` 가 로그인 관리자 UUID(`Auth::id()`)를 답변 작성자로 기록하고 `reply_content`(1~2000자)와 `reply_content_mode`(text/html)를 저장합니다. 답변이 이미 있으면 갱신되고 작성 일시가 채워집니다. 고객 리뷰에 판매자가 응대할 때 사용합니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/reviews/{review}/status
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.reviews.update-status -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.reviews.update-status`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductReviewController@updateStatus`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.reviews.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| review | path | string | 예 | — | 대상 review의 식별자 |
| status | body | string | 예 | — | 변경할 리뷰 전시 상태 (visible=전시중 / hidden=숨김) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.review.update_status_validation_rules`, `sirsoft-ecommerce.review.update_status_validation_messages`).

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/reviews/{review}/status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "status": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (상태가 변경된 리뷰 리소스). 컨트롤러가 관계를 추가로 로드하지 않으므로 `product`·`images`·`image_count`·`orderOption`·`reply_admin_uuid`·`reply_admin` 등 관계 의존 키는 응답에 포함되지 않습니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 리뷰 ID (기본 키) |
| product_id | integer | `1` | 리뷰 대상 상품 ID |
| order_option_id | integer | `1` | 리뷰가 연결된 주문 옵션 ID |
| user_id | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | 작성자 UUID |
| user | object | `{"uuid":"a234c2b1-...","name":"API 문서 샘플 사용자","email":"apidoc-sample-user@example.com"}` | 작성자 정보 (uuid·name·email, `user` 관계 로드 시) |
| option_snapshot | object\|null | `null` | 주문 시점 옵션 스냅샷 (옵션명 보존용, 주문 옵션에서 복사) |
| option_snapshot_label | string | `` | `option_snapshot.option_name` 의 현재 로케일 표시 문자열 |
| rating | integer | `5` | 별점 (1~5) |
| content | string | `Alias quas iusto dolorem eum eveniet …` | 리뷰 내용 |
| content_mode | string | `text` | 콘텐츠 모드: text / html |
| status | string | `hidden` | 변경된 리뷰 상태: `visible`(전시중) / `hidden`(숨김) |
| status_label | string | `숨김` | 상태의 사람이 읽는 라벨 (ReviewStatus::label()) |
| status_badge_color | string | `gray` | 상태 뱃지 색상 (visible=blue / hidden=gray) |
| has_reply | boolean | `false` | 판매자 답변 존재 여부 (`reply_content` 유무) |
| has_reply_label | string | `미답변` | `has_reply` 의 사람이 읽는 라벨 (답변완료 / 미답변) |
| has_reply_badge_color | string | `gray` | 답변 여부 뱃지 색상 (답변완료=green / 미답변=gray) |
| reply_content | string\|null | `null` | 판매자 답변 내용 (없으면 null) |
| reply_content_mode | string | `text` | 답변 콘텐츠 모드: text / html |
| replied_at | string\|null | `null` | 답변 최초 작성 일시 |
| reply_updated_at | string\|null | `null` | 답변 최종 수정 일시 |
| created_at | string | `2026-07-08 10:44:49` | 리뷰 생성 일시 |
| updated_at | string | `2026-07-08 15:10:07` | 리뷰 최종 수정 일시 (상태 변경으로 갱신됨) |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 리뷰에 수행 가능한 작업 불리언 맵 (can_update, can_delete) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "리뷰가 수정되었습니다.",
    "data": {
        "id": 1,
        "product_id": 1,
        "order_option_id": 1,
        "user_id": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "user": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com"
        },
        "option_snapshot": null,
        "option_snapshot_label": "",
        "rating": 5,
        "content": "Alias quas iusto dolorem eum eveniet ad omnis. Id neque consequatur fuga ut.",
        "content_mode": "text",
        "status": "hidden",
        "status_label": "숨김",
        "status_badge_color": "gray",
        "has_reply": false,
        "has_reply_label": "미답변",
        "has_reply_badge_color": "gray",
        "reply_content": null,
        "reply_content_mode": "text",
        "replied_at": null,
        "reply_updated_at": null,
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 15:10:07",
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.reviews.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | `status` 누락 또는 `visible`/`hidden` 외의 값 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 상태 변경 중 예외 발생 (`messages.reviews.update_failed` — "리뷰 수정에 실패했습니다.") |

<!-- @generated:end -->

**설명** 관리자가 리뷰의 전시 상태를 변경합니다. `sirsoft-ecommerce.reviews.update` 권한이 필요하며, `ProductReviewService::updateStatus()` 가 `status` 값(예: visible/hidden)으로 리뷰를 전시하거나 숨기고 갱신된 리뷰 리소스를 반환합니다. 신고되었거나 부적절한 리뷰를 노출에서 제외하거나 다시 노출할 때 사용합니다.


### POST /api/modules/sirsoft-ecommerce/user/reviews
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.reviews.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.reviews.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ProductReviewController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-reviews.write`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| product_id | body | integer | 예 | — | product 식별자 |
| order_option_id | body | integer | 예 | — | order option 식별자 |
| rating | body | integer | 예 | min 1, max 5 | 별점 (1~5) |
| content | body | string | 예 | min 10, max 2000 | 리뷰 내용 (10~2000자) |
| content_mode | body | string | 아니오 | `text`, `html` | 콘텐츠 모드 (평문/HTML, 미지정 시 text) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.review.store_validation_rules`, `sirsoft-ecommerce.review.store_validation_messages`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/user/reviews HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "product_id": 1,
    "order_option_id": 1,
    "rating": 1,
    "content": "예시 내용입니다.",
    "content_mode": "text"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (생성된 리뷰 리소스, HTTP 201). 서비스가 생성 직후의 모델을 그대로 반환하므로 `product`·`images`·`image_count`·`orderOption`·`reply_admin_uuid`·`reply_admin` 등 관계 의존 키는 응답에 포함되지 않습니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 생성된 리뷰 ID (기본 키) |
| product_id | integer | `1` | 리뷰 대상 상품 ID (요청값 그대로) |
| order_option_id | integer | `1` | 리뷰가 연결된 주문 옵션 ID (요청값 그대로) |
| user_id | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | 작성자 UUID (로그인 사용자) |
| option_snapshot | object\|null | `null` | 주문 시점 옵션 스냅샷 (주문 옵션의 `option_snapshot` 을 복사) |
| option_snapshot_label | string | `` | `option_snapshot.option_name` 의 현재 로케일 표시 문자열 |
| rating | integer | `5` | 별점 (1~5, 요청값 그대로) |
| content | string | `예시 내용입니다.` | 리뷰 내용 (10~2000자, 요청값 그대로) |
| content_mode | string | `text` | 콘텐츠 모드: text / html (미지정 시 text) |
| status | string | `visible` | 생성 시 상태 — 항상 `visible`(전시중) |
| status_label | string | `전시중` | 상태의 사람이 읽는 라벨 (ReviewStatus::label()) |
| status_badge_color | string | `blue` | 상태 뱃지 색상 (visible=blue / hidden=gray) |
| has_reply | boolean | `false` | 판매자 답변 존재 여부 (생성 직후는 항상 false) |
| has_reply_label | string | `미답변` | `has_reply` 의 사람이 읽는 라벨 (답변완료 / 미답변) |
| has_reply_badge_color | string | `gray` | 답변 여부 뱃지 색상 (답변완료=green / 미답변=gray) |
| reply_content | null | `null` | 판매자 답변 내용 (생성 직후는 null) |
| reply_content_mode | string | `text` | 답변 콘텐츠 모드: text / html |
| replied_at | null | `null` | 답변 최초 작성 일시 (생성 직후는 null) |
| reply_updated_at | null | `null` | 답변 최종 수정 일시 (생성 직후는 null) |
| created_at | string | `2026-07-08 15:20:11` | 리뷰 생성 일시 |
| updated_at | string | `2026-07-08 15:20:11` | 리뷰 최종 수정 일시 |
| abilities | object | `{"can_update":false,"can_delete":false}` | 현재 사용자가 이 리뷰에 수행 가능한 작업 불리언 맵 (관리자 권한 `sirsoft-ecommerce.reviews.update`/`.delete` 기준이므로 일반 회원은 false) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "리뷰가 작성되었습니다.",
    "data": {
        "id": 1,
        "product_id": 1,
        "order_option_id": 1,
        "user_id": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "option_snapshot": null,
        "option_snapshot_label": "",
        "rating": 5,
        "content": "예시 내용입니다. 배송도 빠르고 품질도 만족스럽습니다.",
        "content_mode": "text",
        "status": "visible",
        "status_label": "전시중",
        "status_badge_color": "blue",
        "has_reply": false,
        "has_reply_label": "미답변",
        "has_reply_badge_color": "gray",
        "reply_content": null,
        "reply_content_mode": "text",
        "replied_at": null,
        "reply_updated_at": null,
        "created_at": "2026-07-08 15:20:11",
        "updated_at": "2026-07-08 15:20:11",
        "abilities": {
            "can_update": false,
            "can_delete": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-reviews.write`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) 또는 작성 자격 미충족 시 `ReviewNotWritableException`(RuntimeException) 이 발생해 사유 코드가 담긴 메시지로 응답 (`order_option_not_found` / `not_own_order` / `not_confirmed` / `deadline_passed` / `already_written`) |
| 500 | Internal Server Error | 리뷰 생성 중 예외 발생 (`messages.reviews.create_failed` — "리뷰 작성에 실패했습니다.") |

<!-- @generated:end -->

**설명** 로그인 회원이 구매한 상품에 리뷰를 작성합니다. `sirsoft-ecommerce.user-reviews.write` 권한이 필요하며, `ProductReviewService::createReview()` 가 로그인 사용자(`Auth::id()`)를 작성자로 하여 `product_id`·`order_option_id`·별점(1~5)·내용(10~2000자)으로 리뷰를 생성하고 201 로 반환합니다. 본인 주문이 아니거나 이미 작성했거나 작성 조건을 만족하지 못하면 서비스가 `RuntimeException` 을 던져 422 로 응답합니다. 마이페이지 리뷰 작성 폼에서 사용합니다.


### GET /api/modules/sirsoft-ecommerce/user/reviews/can-write/{orderOptionId}
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.reviews.can-write -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.reviews.can-write`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ProductReviewController@canWrite`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderOptionId | path | string | 예 | — | 대상 order option의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/user/reviews/can-write/{orderOptionId} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: 작성 자격 판정 결과 (`ProductReviewService::canWrite()` 가 반환한 배열 — Resource 로 감싸지 않는다)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| can_write | boolean | `true` | 이 주문 옵션에 리뷰를 쓸 수 있는지. 화면은 이 값으로 작성 버튼 노출을 결정한다 |
| reason | string \| null | `null` | 쓸 수 없는 사유 키. `can_write` 가 `true` 면 `null` |

`reason` 이 가질 수 있는 값:

| 값 | 의미 |
| --- | --- |
| `order_option_not_found` | 해당 주문 옵션이 존재하지 않음 |
| `not_own_order` | 본인 주문이 아님 |
| `not_confirmed` | 구매확정(`CONFIRMED`) 상태가 아님 |
| `deadline_passed` | 작성 가능 기간이 지남 (판정 규칙은 `ReviewWritePolicy` 단일 SSoT) |
| `already_written` | 이 주문 옵션으로 이미 리뷰를 작성함 |

**응답 예시**

작성 가능한 경우:

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "리뷰 작성 가능 여부를 확인했습니다.",
    "data": {
        "can_write": true,
        "reason": null
    }
}
```

작성 불가한 경우 (이미 작성함):

```json
{
    "success": true,
    "message": "리뷰 작성 가능 여부를 확인했습니다.",
    "data": {
        "can_write": false,
        "reason": "already_written"
    }
}
```

> 작성 불가는 **에러가 아니라 정상 응답**이다. 존재하지 않는 주문 옵션이나 남의 주문도 `404`/`403` 이 아니라 `200` + `can_write: false` 로 응답하므로, 이 엔드포인트로 주문 존재 여부를 탐색할 수 없다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 500 | Internal Server Error | 판정 중 예외 발생 (`messages.reviews.can_write_check_failed`) |

<!-- @generated:end -->

**설명** 로그인 회원이 특정 주문 옵션에 대해 리뷰를 쓸 수 있는지 확인합니다. `auth:sanctum` 인증만 요구하며, `ProductReviewService::canWrite()` 가 본인 주문 여부·구매 완료·중복 작성 여부 등을 판정해 `can_write` 불리언과 불가 시 `reason`(예: `not_own_order`)을 반환합니다. 리뷰 작성 버튼 노출 여부를 결정하기 위해 상품/주문 화면에서 사전 호출합니다.


### DELETE /api/modules/sirsoft-ecommerce/user/reviews/{review}
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.reviews.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.reviews.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ProductReviewController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-reviews.write`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| review | path | string | 예 | — | 대상 review의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/user/reviews/{review} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: 삭제 성공 플래그만 반환한다 (관리자 삭제 엔드포인트와 같은 형태)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted | boolean | `true` | 삭제 성공 여부. 성공 응답에서는 항상 `true` |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "리뷰가 삭제되었습니다.",
    "data": {
        "deleted": true
    }
}
```

> 삭제 전 `images` 관계를 로드해 첨부 이미지 파일까지 함께 정리한다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-reviews.write`)이 없는 경우, 또는 **본인이 작성한 리뷰가 아닌 경우** (`messages.reviews.forbidden`) |
| 404 | Not Found | 해당 `review` 의 리뷰가 없는 경우 (라우트 모델 바인딩이 해석에 실패) |
| 500 | Internal Server Error | 삭제 처리 중 예외 발생 (`messages.reviews.delete_failed`) |

<!-- @generated:end -->

**설명** 로그인 회원이 본인이 작성한 리뷰를 삭제합니다. `sirsoft-ecommerce.user-reviews.write` 권한이 필요하며, 컨트롤러가 `review->user_id` 와 로그인 사용자를 대조해 본인 소유가 아니면 403 을 반환합니다. 본인 리뷰이면 `images` 관계를 로드한 뒤 `ProductReviewService::deleteReview()` 가 첨부 이미지까지 함께 삭제합니다. 마이페이지에서 자신의 리뷰를 지울 때 사용합니다.


### POST /api/modules/sirsoft-ecommerce/user/reviews/{review}/images
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.reviews.images.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.reviews.images.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ReviewImageController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-reviews.write`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| review | path | string | 예 | — | 대상 review의 식별자 |
| image | body | file | 예 | max 10240 | 첨부할 이미지 파일 (최대 용량은 리뷰 설정 `review_settings.max_image_size_mb` 기반, 폴백 10MB) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/user/reviews/{review}/images HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: multipart/form-data; boundary=----G7ExampleBoundary

------G7ExampleBoundary
Content-Disposition: form-data; name="image"; filename="example.pdf"
Content-Type: application/octet-stream

(바이너리 파일 내용)
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (업로드된 리뷰 이미지 리소스, HTTP 201)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 리뷰 이미지 ID (기본 키) |
| review_id | integer | `1` | 이미지가 속한 리뷰 ID |
| hash | string | `9f3a1c7b20de` | URL 용 고유 해시 (12자, 생성 시 자동 부여) |
| original_filename | string | `example.jpg` | 업로드된 원본 파일명 |
| download_url | string | `/api/modules/sirsoft-ecommerce/review-image/9f3a1c7b20de` | 해시 기반 이미지 서빙 URL (`review-image/{hash}`) |
| mime_type | string | `image/jpeg` | MIME 타입 (예: image/jpeg, image/webp) |
| file_size | integer | `204800` | 파일 크기 (바이트) |
| width | integer\|null | `1200` | 이미지 너비 (px, 이미지가 아니거나 판별 실패 시 null) |
| height | integer\|null | `800` | 이미지 높이 (px, 이미지가 아니거나 판별 실패 시 null) |
| alt_text | object\|null | `null` | 대체 텍스트 (다국어 JSON, 업로드 시점에는 설정하지 않으므로 null) |
| is_thumbnail | boolean | `true` | 대표 이미지 여부 (해당 리뷰의 첫 이미지면 true) |
| sort_order | integer | `1` | 정렬 순서 (기존 최대값 + 1) |
| created_at | string | `2026-07-08 15:30:02` | 업로드 일시 |
| abilities | object | `{"can_delete":false}` | 현재 사용자가 이 이미지에 수행 가능한 작업 불리언 맵 (관리자 권한 `sirsoft-ecommerce.reviews.delete` 기준이므로 일반 회원은 false) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "리뷰 이미지가 업로드되었습니다.",
    "data": {
        "id": 1,
        "review_id": 1,
        "hash": "9f3a1c7b20de",
        "original_filename": "example.jpg",
        "download_url": "/api/modules/sirsoft-ecommerce/review-image/9f3a1c7b20de",
        "mime_type": "image/jpeg",
        "file_size": 204800,
        "width": 1200,
        "height": 800,
        "alt_text": null,
        "is_thumbnail": true,
        "sort_order": 1,
        "created_at": "2026-07-08 15:30:02",
        "abilities": {
            "can_delete": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-reviews.write`)이 없거나, 대상 리뷰가 로그인 사용자의 리뷰가 아닌 경우 (`messages.reviews.forbidden` — "권한이 없습니다.") |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | `image` 파일 누락·허용 형식/용량 위반 (`error.errors` 에 필드별 메시지) 또는 리뷰당 최대 첨부 개수 초과 시 `ReviewImageUploadLimitException`(RuntimeException) — 최대 개수는 리뷰 설정 `review_settings.max_images`(기본 5) 기준 |
| 500 | Internal Server Error | 업로드 처리 중 예외 발생 (`messages.reviews.image_upload_failed` — "리뷰 이미지 업로드에 실패했습니다.") |

<!-- @generated:end -->

**설명** 로그인 회원이 자신의 리뷰에 이미지를 첨부합니다. `sirsoft-ecommerce.user-reviews.write` 권한이 필요하며, 컨트롤러가 `review->user_id` 로 본인 소유를 확인(불일치 시 403)한 뒤 `ProductReviewImageService::upload()` 가 업로드된 이미지(최대 10MB)를 저장하고 201 로 이미지 리소스를 반환합니다. 파일 형식/크기 등 제약 위반 시 서비스가 `RuntimeException` 을 던져 422 로 응답합니다. 포토 리뷰 작성 시 이미지를 추가할 때 사용합니다.


### DELETE /api/modules/sirsoft-ecommerce/user/reviews/{review}/images/{image}
<!-- @generated:start:api.modules.sirsoft-ecommerce.user.reviews.images.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.user.reviews.images.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ReviewImageController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-reviews.write`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| review | path | string | 예 | — | 대상 review의 식별자 |
| image | path | string | 예 | — | 대상 image의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/user/reviews/{review}/images/{image} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted | boolean | `true` | 삭제 처리 성공 여부 (true 이면 리뷰와 첨부 이미지가 제거됨) |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "리뷰 이미지가 삭제되었습니다.",
    "data": {
        "deleted": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-reviews.write`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 로그인 회원이 자신의 리뷰에서 첨부 이미지 1건을 삭제합니다. `sirsoft-ecommerce.user-reviews.write` 권한이 필요하며, 컨트롤러가 `review->user_id` 로 본인 소유를 확인(불일치 시 403)하고 `image->review_id` 가 해당 리뷰에 속하는지 대조(불일치 시 404)한 뒤 `ProductReviewImageService::delete()` 로 파일과 레코드를 함께 제거합니다. 포토 리뷰에서 잘못 올린 이미지를 개별 삭제할 때 사용합니다.


