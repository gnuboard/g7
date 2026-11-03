# Admin Wishlists API 레퍼런스 (sirsoft-ecommerce)

> **소유**: sirsoft-ecommerce 모듈 · 이 문서는 **손으로 작성**했습니다.
> `api:docgen` 의 파괴적 재생성 가드(`docs/backend/api-documentation.md`)를 따라 스캐폴딩
> 덮어쓰기를 하지 않았습니다.

---

## TL;DR (5초 요약)

```text
1. 회원이 남긴 찜 를 전 회원 범위로 조회·삭제하는 관리자 API 입니다 (이슈 #524 WP-E)
2. 찜·장바구니·배송지·상품문의 네 목록이 같은 뼈대를 씁니다 — 검색·회원한정·기간·일괄삭제
3. 관리자 목록은 숨김 상품의 찜도 보여 줍니다 — 회원 화면과 반대 방향입니다
4. 권한: sirsoft-ecommerce.wishlists.read(조회) · sirsoft-ecommerce.wishlists.manage(삭제)
5. 회원 상세 「쇼핑 데이터」 탭이 네 갈래의 건수를 함께 보여 주고 각 목록으로 넘깁니다
```

---

## 이 목록만의 차이

회원 화면의 찜 목록은 **숨김 상품을 뺍니다**(`Product::scopeVisible`) — 눌러도 갈 수 없는
상품을 남겨 두면 회원이 판매 종료인지 오류인지 알 수 없기 때문입니다.

관리자 목록은 반대로 **그대로 보여 줍니다**. 운영자가 「숨긴 상품인데 아직 찜에 남아 있다」를
알아보고 정리할 수 있어야 하기 때문입니다. 일시 품절·판매 중지 상품은 양쪽 모두에 남습니다 —
다시 팔릴 수 있고, 회원이 기다리는 중일 수 있습니다.

---


## 권한 범위(본인만·소유역할)

관리자 역할에 `sirsoft-ecommerce.wishlists.read`·`.manage` 를 「본인만」 또는 「소유역할」 범위로 주면 그 범위가
목록과 삭제 모두에 적용됩니다(판정 기준은 찜 행의 회원).

- 목록: 범위 밖 회원의 행은 목록·건수에서 빠집니다. 비회원 행(회원 없음)은 「전체」 범위에서만 보입니다.
- 단건 삭제: 범위 밖 행이면 403(`auth.scope_denied`), 없는 행이면 404 입니다.
- 일괄 삭제: 범위 밖 행이 하나라도 섞이면 403(`auth.scope_denied`)으로 전체를 거부하고 아무것도 지우지 않습니다.

---

### GET /api/modules/sirsoft-ecommerce/admin/wishlists
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.wishlists.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.wishlists.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\AdminWishlistController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.wishlists.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| sort | query | string | 아니오 | `created_at_desc`(기본), `created_at_asc` | 정렬 |
| search_field | query | string | 아니오 | `member`, `member_id`, `email`, `product` | 검색 대상 |
| search_keyword | query | string | 아니오 | max 100 | 검색어 |
| user_id | query | integer | 아니오 | min 1 | 회원 ID 로 한정. 함께 오면 `user_uuid` 보다 우선한다 |
| user_uuid | query | string(uuid) | 아니오 | uuid | 회원 한정. 없는 uuid 면 빈 결과 |
| start_date | query | date | 아니오 | — | 조회 기간 시작일 (이 날짜 이후 데이터) |
| end_date | query | date | 아니오 | — | 조회 기간 종료일 (이 날짜 이전 데이터) |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/wishlists?page=1&per_page=1&sort=created_at_desc&search_field=member&search_keyword=%EC%98%88%EC%8B%9C%EA%B0%92&user_id=1&user_uuid=%EC%98%88%EC%8B%9C%EA%B0%92&start_date=2026-01-01&end_date=2026-01-01 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.abilities` + `data.pagination`._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `42` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `318` | 식별자 (삭제가 받는 값) |
| member | object\|null | `{"uuid":"…","name":"홍길동","email":"user@example.com","is_withdrawn":false}` | 회원. 회원 관계가 없으면 `null` |
| member.uuid | string | `a26f0fb0-8b47-4bb9-835a-685b76efc963` | 회원 UUID |
| member.name | string | `홍길동` | 회원 이름. 탈퇴 회원(`status=withdrawn`)이면 이름 대신 「탈퇴한 사용자」(`user.withdrawn_user`) |
| member.email | string\|null | `user@example.com` | 회원 이메일. 회원 이메일 노출 게이트(`canExposeMemberEmail`)를 통과할 때만 싣고, 아니면 `null` |
| member.is_withdrawn | boolean | `false` | 탈퇴 회원 여부 |
| product | object\|null | `{"id":415,"name":"베이직 티셔츠",…}` | 찜한 상품. 상품이 지워져 관계가 없으면 `null`. 숨김 상품과 판매중지 상품도 그대로 싣는다 |
| product.id | integer | `415` | 상품 ID |
| product.name | string | `베이직 티셔츠` | 상품명 (요청 로케일 기준 다국어 해석값) |
| product.display_status | string\|null | `hidden` | 전시 상태 (`visible` 전시 / `hidden` 숨김) |
| product.display_status_label | string\|null | `숨김` | 전시 상태 라벨 |
| product.sales_status | string\|null | `on_sale` | 판매 상태 (`on_sale` 판매중 / `suspended` 판매중지 / `sold_out` 품절 / `coming_soon` 출시예정) |
| product.sales_status_label | string\|null | `판매중` | 판매 상태 라벨 |
| created_at | string | `2026-10-29 12:54:55` | 찜한 시각 (뷰어 시간대) |
| abilities.can_manage | boolean | `true` | 컬렉션 단위 삭제 권한(`sirsoft-ecommerce.wishlists.manage`) — 화면의 체크 칸과 일괄 버튼이 이 값으로 열립니다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "찜 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "number": 42,
                "id": 318,
                "member": {
                    "uuid": "a26f0fb0-8b47-4bb9-835a-685b76efc963",
                    "name": "홍길동",
                    "email": "user@example.com",
                    "is_withdrawn": false
                },
                "product": {
                    "id": 415,
                    "name": "베이직 티셔츠",
                    "display_status": "hidden",
                    "display_status_label": "숨김",
                    "sales_status": "on_sale",
                    "sales_status_label": "판매중"
                },
                "created_at": "2026-10-29 12:54:55"
            },
            "... (총 20건 중 1건 표시)"
        ],
        "abilities": {
            "can_manage": true
        },
        "pagination": {
            "current_page": 1,
            "per_page": 20,
            "from": 1,
            "to": 20,
            "has_more_pages": true,
            "last_page": 3,
            "total": 42,
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.wishlists.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

- **인증/권한**: `auth:sanctum` + `permission:admin,sirsoft-ecommerce.wishlists.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page / per_page | query | integer | 아니오 | per_page max 100 | 페이지네이션 |
| sort | query | string | 아니오 | `created_at_desc`(기본), `created_at_asc` | 정렬 |
| search_field | query | string | 아니오 | `member`, `member_id`, `email`, `product` | 검색 대상 |
| search_keyword | query | string | 아니오 | max 255 | 검색어 |
| user_uuid | query | string(uuid) | 아니오 | uuid | 회원 한정. 없는 uuid 면 빈 결과 |
| start_date / end_date | query | date | 아니오 | `Y-m-d` | 생성일 범위 |

**응답 필드** (`data.data[]` + `data.abilities` + `data.pagination`)

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| id | integer | 식별자 (삭제가 받는 값) |
| member | object\|null | 회원. 지워졌으면 `null` |
| member.uuid / name | string | 회원 식별자·이름 (탈퇴면 익명 표기) |
| member.email | string\|null | 관리자 응답에만 실립니다 |
| created_at | string | 생성 시각 (뷰어 시간대) |
| product | object\|null | 찜한 상품 (id · 이름 · 상태) |
| abilities.can_manage | boolean | 삭제 권한 — 화면의 체크 칸·일괄 버튼이 이 값으로 열립니다 |

---

### DELETE /api/modules/sirsoft-ecommerce/admin/wishlists/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.wishlists.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.wishlists.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\AdminWishlistController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.wishlists.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | — | 삭제할 찜 ID (목록 응답의 `id`. 라우트가 숫자만 받는다 — `whereNumber`) |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/wishlists/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — 컨트롤러가 `ResponseHelper::moduleSuccess()` 에 데이터를 넘기지 않아 `data` 는 `null`)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "찜을 삭제했습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.wishlists.manage`)이 없는 경우, 또는 역할의 권한 범위가 「본인만」 또는 「소유역할」 이라 그 행의 회원이 범위 밖인 경우 (`auth.scope_denied`) |
| 404 | Not Found | 해당 ID 의 찜이 없는 경우 (`common.not_found`) |

<!-- @generated:end -->

**설명**

- **권한**: `sirsoft-ecommerce.wishlists.manage`

---

### DELETE /api/modules/sirsoft-ecommerce/admin/wishlists/bulk
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.wishlists.bulk-destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.wishlists.bulk-destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\AdminWishlistController@bulkDestroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.wishlists.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | query | array | 예 | min 1, max 100 | 삭제할 찜 ID 배열. 원소는 정수이고 중복 없이(`distinct`) 실제로 있는 행이어야 한다(`exists`). 쿼리 문자열(`ids[]=1&ids[]=2`)과 JSON 본문 어느 쪽으로 보내도 같은 검증을 탄다 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/wishlists/bulk?ids=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted_count | integer | `3` | 실제로 지워진 건수 (`message` 의 `:count` 자리에도 같은 값이 들어갑니다) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "3개의 찜을 삭제했습니다.",
    "data": {
        "deleted_count": 3
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.wishlists.manage`)이 없는 경우, 또는 고른 행 중 하나라도 역할의 권한 범위(「본인만」 또는 「소유역할」) 밖 회원의 것인 경우 (`auth.scope_denied` — 전체를 거부하고 아무것도 지우지 않음) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) — `ids` 누락, 빈 배열, 100개 초과, 정수가 아닌 원소, 중복 ID, 이미 지워졌거나 없는 ID(`ids.N` 의 `exists` 위반. 목록이 낡았다는 신호라 조용히 건너뛰지 않음) |

<!-- @generated:end -->

**설명**

- **권한**: `sirsoft-ecommerce.wishlists.manage`
- 라우트는 `{id}` 보다 **먼저** 선언되어 있습니다 — 그러지 않으면 `bulk` 가 id 로 잡힙니다.

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | 1~100개 | 삭제할 식별자 |
| ids.* | body | integer | 예 | 존재하는 행 | 식별자 |

응답 `deleted_count` 는 실제로 지워진 건수입니다. 일괄 삭제는 활동 로그를 **한 번만** 남깁니다 —
건마다 남기면 100건 삭제가 로그 100줄이 됩니다.
