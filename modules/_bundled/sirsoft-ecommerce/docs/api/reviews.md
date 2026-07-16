# Reviews API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Reviews 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
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
| option_snapshot | null | `null` | 주문 시점 옵션 스냅샷 (옵션명 보존용) |
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
| reply_content | null | `null` | 판매자 답변 내용 (없으면 null) |
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
    "message": "리뷰 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 1,
                "product_id": 1,
                "order_option_id": 1,
                "user_id": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                "user": {
                    "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                    "name": "API 문서 샘플 사용자",
                    "email": "apidoc-sample-user@example.com"
                },
                "product": {
                    "id": 1,
                    "name": "API 문서 샘플 상품",
                    "thumbnail_url": null
                },
                "option_snapshot": null,
                "option_snapshot_label": "",
                "rating": 5,
                "content": "Alias quas iusto dolorem eum eveniet ad omnis. Id neque consequatur fuga ut. Enim cum mollitia nisi. Adipisci sunt tenetur et tempora tempora eius rerum.",
                "content_mode": "text",
                "status": "visible",
                "status_label": "전시중",
                "status_badge_color": "blue",
                "images": [],
                "image_count": 0,
                "orderOption": {
                    "id": 1,
                    "order_id": 5,
                    "order_number": "ORD-20260708-000002",
                    "quantity": 2,
                    "created_at": "2026-07-08 10:44:49"
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
                "created_at": "2026-07-08 10:44:49",
                "updated_at": "2026-07-08 10:44:49",
                "abilities": {
                    "can_update": true,
                    "can_delete": true
                }
            }
        ],
        "links": {
            "first": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/reviews?page=1",
            "last": "https://api.example.com/api/modules/sirsoft-ecommerce/admin/reviews?page=1",
            "prev": null,
            "next": null
        },
        "meta": {
            "current_page": 1,
            "from": 1,
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
            "to": 1,
            "total": 1
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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.reviews.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

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
DELETE /api/modules/sirsoft-ecommerce/admin/reviews/1 HTTP/1.1
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

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.reviews.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

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
GET /api/modules/sirsoft-ecommerce/admin/reviews/1 HTTP/1.1
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
| option_snapshot | null | `null` | 주문 시점 옵션 스냅샷 (옵션명 보존용) |
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
    "message": "리뷰 목록을 조회했습니다.",
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
        "product": {
            "id": 1,
            "name": "API 문서 샘플 상품",
            "thumbnail_url": null
        },
        "option_snapshot": null,
        "option_snapshot_label": "",
        "rating": 5,
        "content": "Alias quas iusto dolorem eum eveniet ad omnis. Id neque consequatur fuga ut. Enim cum mollitia nisi. Adipisci sunt tenetur et tempora tempora eius rerum.",
        "content_mode": "text",
        "status": "visible",
        "status_label": "전시중",
        "status_badge_color": "blue",
        "images": [],
        "image_count": 0,
        "orderOption": {
            "id": 1,
            "order_id": 5,
            "order_number": "ORD-20260708-000002",
            "quantity": 2,
            "created_at": "2026-07-08 10:44:49"
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
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 10:44:49",
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
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

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
DELETE /api/modules/sirsoft-ecommerce/admin/reviews/1/reply HTTP/1.1
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
| option_snapshot | null | `null` | 주문 시점 옵션 스냅샷 (옵션명 보존용) |
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
    "message": "답변이 삭제되었습니다.",
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
        "content": "Alias quas iusto dolorem eum eveniet ad omnis. Id neque consequatur fuga ut. Enim cum mollitia nisi. Adipisci sunt tenetur et tempora tempora eius rerum.",
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
        "updated_at": "2026-07-08 10:44:49",
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
POST /api/modules/sirsoft-ecommerce/admin/reviews/1/reply HTTP/1.1
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
| option_snapshot | null | `null` | 주문 시점 옵션 스냅샷 (옵션명 보존용) |
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
    "message": "답변이 저장되었습니다.",
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
        "content": "Alias quas iusto dolorem eum eveniet ad omnis. Id neque consequatur fuga ut. Enim cum mollitia nisi. Adipisci sunt tenetur et tempora tempora eius rerum.",
        "content_mode": "text",
        "status": "visible",
        "status_label": "전시중",
        "status_badge_color": "blue",
        "has_reply": true,
        "has_reply_label": "답변완료",
        "has_reply_badge_color": "green",
        "reply_content": "실측 예시값",
        "reply_content_mode": "text",
        "replied_at": "2026-07-08 15:00:32",
        "reply_updated_at": null,
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 15:00:32",
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

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
PATCH /api/modules/sirsoft-ecommerce/admin/reviews/1/status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "status": "예시값"
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.reviews.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-reviews.write`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

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
GET /api/modules/sirsoft-ecommerce/user/reviews/can-write/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| can_write | boolean | `false` | write 수행 가능 여부 (권한 기반) |
| reason | string | `not_own_order` | 리뷰 작성 불가 사유 코드 (`can_write` 가 false 일 때만 값이 있고 가능하면 null. order_option_not_found 주문옵션 없음, not_own_order 본인 주문 아님, not_confirmed 구매확정 전, deadline_passed 작성 기한 초과, already_written 이미 작성함) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "리뷰 작성 가능 여부를 확인했습니다.",
    "data": {
        "can_write": false,
        "reason": "not_own_order"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

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
DELETE /api/modules/sirsoft-ecommerce/user/reviews/1 HTTP/1.1
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

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-reviews.write`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

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
POST /api/modules/sirsoft-ecommerce/user/reviews/1/images HTTP/1.1
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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-reviews.write`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

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
DELETE /api/modules/sirsoft-ecommerce/user/reviews/1/images/{image} HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-reviews.write`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 로그인 회원이 자신의 리뷰에서 첨부 이미지 1건을 삭제합니다. `sirsoft-ecommerce.user-reviews.write` 권한이 필요하며, 컨트롤러가 `review->user_id` 로 본인 소유를 확인(불일치 시 403)하고 `image->review_id` 가 해당 리뷰에 속하는지 대조(불일치 시 404)한 뒤 `ProductReviewImageService::delete()` 로 파일과 레코드를 함께 제거합니다. 포토 리뷰에서 잘못 올린 이미지를 개별 삭제할 때 사용합니다.


