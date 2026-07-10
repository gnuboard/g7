# Dashboard API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Dashboard 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/admin/dashboard/overview
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.dashboard.overview -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.dashboard.overview`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\DashboardController@overview`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.dashboard.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/dashboard/overview HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| pending_payment | integer | `0` | 오늘 결제대기 상태 주문상품 수량 |
| payment_complete | integer | `0` | 오늘 결제완료 상태 주문상품 수량 |
| preparing | integer | `0` | 오늘 상품준비중 상태 주문상품 수량 |
| shipping_ready | integer | `0` | 오늘 배송준비 상태 주문상품 수량 |
| shipping | integer | `0` | 오늘 배송중 상태 주문상품 수량 |
| cancellations | integer | `0` | 오늘 취소 상태 주문상품 수량 (전체취소 기준, 부분취소 포함) |
| returns | integer | `0` | 오늘 반품 상태 주문상품 수량 (환불 도메인 미반영으로 현재 항상 0) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "대시보드 데이터를 조회했습니다.",
    "data": {
        "pending_payment": 0,
        "payment_complete": 0,
        "preparing": 0,
        "shipping_ready": 0,
        "shipping": 0,
        "cancellations": 0,
        "returns": 0
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.dashboard.view`)이 없는 경우 |

<!-- @generated:end -->

**설명** 관리자 대시보드 상단의 "오늘 주문 현황" 배지를 채우는 조회 엔드포인트입니다. `auth:sanctum` + admin + `sirsoft-ecommerce.dashboard.view` 권한이 필요하며, `EcommerceDashboardService::getOverview()`가 오늘자 주문을 상태별(결제대기/결제완료/상품준비중/배송준비/배송중/취소/반품)로 집계해 각 건수를 반환합니다. 모든 값은 오늘 하루 범위의 집계이며, 관리자가 처리해야 할 주문 흐름을 한눈에 파악하는 용도입니다.


### GET /api/modules/sirsoft-ecommerce/admin/dashboard/pending-inquiries
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.dashboard.pending-inquiries -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.dashboard.pending-inquiries`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\DashboardController@pendingInquiries`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.dashboard.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| limit | query | integer | 아니오 | min 1, max 50 | 반환할 최대 항목 수 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/dashboard/pending-inquiries?limit=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| items | array | `[{"id":1,"product_id":320,"inquirable_id":79762,"product_…` | 미답변 상품문의 목록 (최신순, PendingInquiryResource — 문의 id/상품/작성자/게시판 글 id 등) |
| total | integer | `1` | 전체 개수 (집계) |
| board_slug | null | `null` | 문의가 저장된 연동 게시판의 slug (관리자 문의 상세 링크용, 미연동 시 null) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "대시보드 데이터를 조회했습니다.",
    "data": {
        "items": [
            {
                "id": 1,
                "product_id": 1,
                "inquirable_id": 59404,
                "product_name": "iste et inventore",
                "author_name": "API 문서 샘플 사용자",
                "created_at": "2026-07-08 10:44:49"
            }
        ],
        "total": 1,
        "board_slug": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.dashboard.view`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 전체 상품에 달린 미답변 상품문의 목록과 총 건수를 조회합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.dashboard.view` 권한이 필요하며, `EcommerceDashboardService::getPendingInquiries()`가 답변 대기 문의를 최신순으로 조회해 `items`(문의 목록)·`total`(전체 미답변 건수)·`board_slug`(연동 게시판 slug, 미연동 시 null)를 반환합니다. `limit` 쿼리로 표시 건수를 1~50 사이에서 조정할 수 있고, 미지정 시 모듈 설정 `dashboard.recent_limit`(기본 5) 값이 적용됩니다.


### GET /api/modules/sirsoft-ecommerce/admin/dashboard/recent-reviews
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.dashboard.recent-reviews -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.dashboard.recent-reviews`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\DashboardController@recentReviews`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.dashboard.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| limit | query | integer | 아니오 | min 1, max 50 | 반환할 최대 항목 수 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/dashboard/recent-reviews?limit=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `99` | 기본 키 (내부 식별자) |
| product_id | integer | `320` | product 식별자 (연관 리소스 참조) |
| product_name | string | `API 문서 샘플 상품` | 리뷰 대상 상품의 현재 로케일 상품명 (product 관계 파생) |
| rating | integer | `5` | 리뷰 평점 (별점 정수) |
| author_name | string | `API 문서 샘플 사용자` | 리뷰 작성자 이름 (user 관계 파생) |
| created_at | string | `2026-07-07 14:47:31` | 생성 일시 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "대시보드 데이터를 조회했습니다.",
    "data": [
        {
            "id": 1,
            "product_id": 1,
            "product_name": "API 문서 샘플 상품",
            "rating": 5,
            "author_name": "API 문서 샘플 사용자",
            "created_at": "2026-07-08 10:44:49"
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.dashboard.view`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 전체 상품에 등록된 최신 노출 리뷰를 조회해 대시보드 "최신 리뷰" 카드를 채웁니다. `auth:sanctum` + admin + `sirsoft-ecommerce.dashboard.view` 권한이 필요하며, `EcommerceDashboardService::getRecentReviews()`가 노출 상태의 리뷰를 최신순으로 가져와 `RecentReviewResource`로 상품명·평점·작성자명·작성일시 등을 반환합니다. `limit` 쿼리로 1~50 건 범위에서 표시 개수를 지정할 수 있으며, 미지정 시 모듈 설정 `dashboard.recent_limit`(기본 5)이 적용됩니다.


### GET /api/modules/sirsoft-ecommerce/admin/dashboard/sales-graph
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.dashboard.sales-graph -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.dashboard.sales-graph`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\DashboardController@salesGraph`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.dashboard.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/dashboard/sales-graph HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| days | array | `[{"date":"2026-07-01","sales_quantity":0,"sales_amount":0…` | 일자별 판매 집계 배열 (각 항목 `{date, sales_quantity, sales_amount}` — 그래프 막대 데이터) |
| total_quantity | integer | `0` | 표시 기간 판매 수량 합계 |
| total_sales | integer | `0` | 표시 기간 순매출 합계 (기본 통화 자릿수로 라운딩) |
| quantity_change | null | `null` | 직전 동일 기간 대비 판매 수량 증감율(%) (직전 합계 0 이면 null) |
| sales_change | null | `null` | 직전 동일 기간 대비 순매출 증감율(%) (직전 합계 0 이면 null) |
| updated_at | null | `null` | 최종 수정 일시 |
| updated_at_display | string | `` | 집계 마지막 갱신 시각의 사용자 타임존 HH:mm 캡션 (갱신 이력 없으면 빈 문자열) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "대시보드 데이터를 조회했습니다.",
    "data": {
        "days": [
            {
                "date": "2026-07-02",
                "sales_quantity": 0,
                "sales_amount": 0
            },
            {
                "date": "2026-07-03",
                "sales_quantity": 0,
                "sales_amount": 0
            },
            {
                "date": "2026-07-04",
                "sales_quantity": 0,
                "sales_amount": 0
            },
            {
                "date": "2026-07-05",
                "sales_quantity": 0,
                "sales_amount": 0
            },
            {
                "date": "2026-07-06",
                "sales_quantity": 0,
                "sales_amount": 0
            },
            {
                "date": "2026-07-07",
                "sales_quantity": 0,
                "sales_amount": 0
            },
            {
                "date": "2026-07-08",
                "sales_quantity": 0,
                "sales_amount": 0
            }
        ],
        "total_quantity": 0,
        "total_sales": 0,
        "quantity_change": null,
        "sales_change": null,
        "updated_at": null,
        "updated_at_display": ""
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.dashboard.view`)이 없는 경우 |

<!-- @generated:end -->

**설명** 최근 N일간의 판매 추세 막대 그래프 데이터를 조회합니다. `auth:sanctum` + admin + `sirsoft-ecommerce.dashboard.view` 권한이 필요하며, `EcommerceDashboardService::getSalesGraph()`가 일자별 판매 수량·금액(`days`)과 기간 합계(`total_quantity`, `total_sales`), 직전 기간 대비 변화율(`quantity_change`, `sales_change`)을 반환합니다. 그래프 표시 일수는 모듈 설정 `dashboard.graph_days`(기본 7일)로 결정되며 별도 파라미터는 받지 않습니다.


