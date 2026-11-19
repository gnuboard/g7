# Admin Carts API 레퍼런스 (sirsoft-ecommerce)

> **소유**: sirsoft-ecommerce 모듈 · 이 문서는 **손으로 작성**했습니다.
> `api:docgen` 의 파괴적 재생성 가드(`docs/backend/api-documentation.md`)를 따라 스캐폴딩
> 덮어쓰기를 하지 않았습니다.

---

## TL;DR (5초 요약)

```text
1. 회원이 남긴 장바구니 를 전 회원 범위로 조회·삭제하는 관리자 API 입니다 (이슈 #524 WP-E)
2. 찜·장바구니·배송지·상품문의 네 목록이 같은 뼈대를 씁니다 — 검색·회원한정·기간·일괄삭제
3. 비회원 장바구니가 섞이므로 `owner` 로 가릅니다
4. 권한: sirsoft-ecommerce.carts.read(조회) · sirsoft-ecommerce.carts.manage(삭제)
5. 회원 상세 「쇼핑 데이터」 탭이 네 갈래의 건수를 함께 보여 주고 각 목록으로 넘깁니다
```

---

## 이 목록만의 차이

장바구니에는 **비회원 것이 섞입니다**(`user_id` 가 없고 `cart_key` 만 있는 행).
정리 기준이 서로 달라서 — 비회원 것은 기간이 지나면 버리는 대상입니다 — `owner` 필터로
두 쪽을 가릅니다. 회원 한정(`user_uuid`)으로 들어오면 당연히 회원 것만 남습니다.

---


## 권한 범위(본인만·소유역할)

관리자 역할에 `sirsoft-ecommerce.carts.read`·`.manage` 를 「본인만」 또는 「소유역할」 범위로 주면 그 범위가
목록과 삭제 모두에 적용됩니다(판정 기준은 장바구니 행의 회원).

- 목록: 범위 밖 회원의 행은 목록·건수에서 빠집니다. 비회원 행(회원 없음)은 「전체」 범위에서만 보입니다.
- 단건 삭제: 범위 밖 행이면 403(`auth.scope_denied`), 없는 행이면 404 입니다.
- 일괄 삭제: 범위 밖 행이 하나라도 섞이면 403(`auth.scope_denied`)으로 전체를 거부하고 아무것도 지우지 않습니다.

---

### GET /api/modules/sirsoft-ecommerce/admin/carts
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.carts.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.carts.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\AdminCartController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.carts.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| sort | query | string | 아니오 | `created_at_desc`(기본), `created_at_asc` | 정렬 |
| search_field | query | string | 아니오 | `member`, `member_id`, `email`, `product` | 검색 대상 |
| search_keyword | query | string | 아니오 | max 100 | 검색어 |
| user_id | query | integer | 아니오 | min 1 | user 식별자 |
| user_uuid | query | string(uuid) | 아니오 | uuid | 회원 한정. 없는 uuid 면 빈 결과 |
| start_date | query | date | 아니오 | — | 조회 기간 시작일 (이 날짜 이후 데이터) |
| end_date | query | date | 아니오 | — | 조회 기간 종료일 (이 날짜 이전 데이터) |
| owner | query | string | 아니오 | `all`(기본), `member`, `guest` | 회원/비회원 장바구니 구분 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/carts?page=1&per_page=1&sort=created_at_desc&search_field=member&search_keyword=%EC%98%88%EC%8B%9C%EA%B0%92&user_id=1&user_uuid=%EC%98%88%EC%8B%9C%EA%B0%92&start_date=2026-01-01&end_date=2026-01-01&owner=all HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `358` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `1074` | 식별자 (삭제가 받는 값) |
| member | null | `null` | 회원. 지워졌으면 `null` |
| product | object | `{"id":424,"name":"프리미엄 샤인머스캣 2kg #10","display_status":"v…` | 담은 상품 |
| option_name | null | `null` | 선택한 옵션 |
| quantity | integer | `2` | 수량 |
| owner | string | `guest` | 행의 소유 구분. 회원 행(`user_id` 있음)은 `member`, 비회원 세션 행은 `guest` |
| guest_key | string | `ck_v7OEx` | 비회원 행의 `cart_key` 앞 8자리 (같은 비회원 세션의 행을 알아보는 용도). 키 전체는 싣지 않습니다. 회원 행은 `null` |
| created_at | string | `2026-10-10 17:19:04` | 생성 시각 (뷰어 시간대) |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "장바구니 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "number": 358,
                "id": 1074,
                "member": null,
                "product": {
                    "id": 424,
                    "name": "프리미엄 샤인머스캣 2kg #10",
                    "display_status": "visible",
                    "display_status_label": "전시",
                    "sales_status": "on_sale",
                    "sales_status_label": "판매중"
                },
                "option_name": null,
                "quantity": 2,
                "owner": "guest",
                "guest_key": "ck_v7OEx",
                "created_at": "2026-10-10 17:19:04"
            },
            {
                "number": 357,
                "id": 1073,
                "member": null,
                "product": {
                    "id": 471,
                    "name": "무선 마우스 세트 #57",
                    "display_status": "visible",
                    "display_status_label": "전시",
                    "sales_status": "on_sale",
                    "sales_status_label": "판매중"
                },
                "option_name": null,
                "quantity": 3,
                "owner": "guest",
                "guest_key": "ck_v7OEx",
                "created_at": "2026-10-10 17:19:04"
            },
            "... (총 25건 중 2건 표시)"
        ],
        "abilities": {
            "can_manage": true
        },
        "pagination": {
            "current_page": 1,
            "per_page": 25,
            "from": 1,
            "to": 25,
            "has_more_pages": true,
            "last_page": 15,
            "total": 358,
            "total_relation": "exact",
            "total_is_exact": true,
            "result_cap": 10000
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.carts.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

- **인증/권한**: `auth:sanctum` + `permission:admin,sirsoft-ecommerce.carts.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page / per_page | query | integer | 아니오 | per_page max 100 | 페이지네이션 |
| sort | query | string | 아니오 | `created_at_desc`(기본), `created_at_asc` | 정렬 |
| search_field | query | string | 아니오 | `member`, `member_id`, `email`, `product` | 검색 대상 |
| search_keyword | query | string | 아니오 | max 255 | 검색어 |
| user_uuid | query | string(uuid) | 아니오 | uuid | 회원 한정. 없는 uuid 면 빈 결과 |
| start_date / end_date | query | date | 아니오 | `Y-m-d` | 생성일 범위 |
| owner | query | string | 아니오 | `all`(기본), `member`, `guest` | 회원/비회원 장바구니 구분 |

**응답 필드** (`data.data[]` + `data.abilities` + `data.pagination`)

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| id | integer | 식별자 (삭제가 받는 값) |
| member | object\|null | 회원. 지워졌으면 `null` |
| member.uuid / name | string | 회원 식별자·이름 (탈퇴면 익명 표기) |
| member.email | string\|null | 관리자 응답에만 실립니다 |
| created_at | string | 생성 시각 (뷰어 시간대) |
| product | object\|null | 담은 상품 |
| option_name | string\|null | 선택한 옵션 |
| quantity | integer | 수량 |
| abilities.can_manage | boolean | 삭제 권한 — 화면의 체크 칸·일괄 버튼이 이 값으로 열립니다 |

---

### DELETE /api/modules/sirsoft-ecommerce/admin/carts/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.carts.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.carts.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\AdminCartController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.carts.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/carts/{id} HTTP/1.1
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
    "message": "장바구니 항목을 삭제했습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.carts.manage`)이 없거나, 대상 행의 회원이 요청자의 권한 범위 밖인 경우 (`auth.scope_denied`) |
| 404 | Not Found | 해당 `id` 의 장바구니 행이 없는 경우 (`common.not_found`) |

<!-- @generated:end -->

**설명**

- **권한**: `sirsoft-ecommerce.carts.manage`

---

### DELETE /api/modules/sirsoft-ecommerce/admin/carts/bulk
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.carts.bulk-destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.carts.bulk-destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\AdminCartController@bulkDestroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.carts.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | query | array | 예 | min 1, max 100 | 대상 리소스 식별자 배열 (대량 작업 대상) |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/carts/bulk?ids=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted_count | integer | `2` | 실제로 삭제된 행 수 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "2개의 장바구니 항목을 삭제했습니다.",
    "data": {
        "deleted_count": 2
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.carts.manage`)이 없거나, 대상 중 하나라도 요청자의 권한 범위 밖인 경우 (`auth.scope_denied` — 전체 거부, 아무것도 지우지 않음) |
| 422 | Unprocessable Entity | `ids` 가 비었거나 100개를 넘는 경우, 중복 ID 또는 없는 ID 가 섞인 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

- **권한**: `sirsoft-ecommerce.carts.manage`
- 라우트는 `{id}` 보다 **먼저** 선언되어 있습니다 — 그러지 않으면 `bulk` 가 id 로 잡힙니다.

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | 1~100개 | 삭제할 식별자 |
| ids.* | body | integer | 예 | 존재하는 행 | 식별자 |

응답 `deleted_count` 는 실제로 지워진 건수입니다. 일괄 삭제는 활동 로그를 **한 번만** 남깁니다 —
건마다 남기면 100건 삭제가 로그 100줄이 됩니다.
