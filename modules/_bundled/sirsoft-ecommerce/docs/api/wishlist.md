# Wishlist API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Wishlist 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/wishlist
<!-- @generated:start:api.modules.sirsoft-ecommerce.wishlist.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.wishlist.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\WishlistController@index`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/wishlist HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

<!-- 실측 응답에 필드 없음(빈 목록 등) — 데이터가 있는 상태로 재실측하거나 사람이 작성. -->

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "찜 목록을 불러왔습니다.",
    "data": {
        "data": [],
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 0,
            "from": null,
            "to": null,
            "has_more_pages": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명** 로그인한 회원의 찜(위시리스트) 목록을 페이지네이션으로 조회합니다. `auth:sanctum` 인증이 필요하며, `WishlistController@index`가 `ProductWishlistService::getByUser()`로 본인 찜 목록만 가져와 `WishlistCollection`으로 반환합니다. `per_page`는 기본 20건이며 최대 100건으로 제한됩니다. 마이페이지의 찜 목록 화면에서 사용합니다.


### POST /api/modules/sirsoft-ecommerce/wishlist/toggle
<!-- @generated:start:api.modules.sirsoft-ecommerce.wishlist.toggle -->
- **라우트명**: `api.modules.sirsoft-ecommerce.wishlist.toggle`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\WishlistController@toggle`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| product_id | body | integer | 예 | — | product 식별자 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.wishlist.toggle_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/wishlist/toggle HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "product_id": 1
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| added | boolean | `true` | 토글 후 찜 상태 (true 이면 찜 목록에 추가됨 / false 이면 제거됨) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "상품이 찜 목록에 추가되었습니다.",
    "data": {
        "added": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 특정 상품(`product_id`)의 찜 상태를 토글합니다. `auth:sanctum` 인증이 필요하며, `WishlistController@toggle`이 `ProductWishlistService::toggle()`을 호출해 이미 찜한 상품이면 제거하고 아니면 추가한 뒤 `added` 불리언을 반환합니다. 상품 상세/목록의 찜 하트 버튼이 이 하나의 엔드포인트로 추가·제거를 모두 처리합니다. 응답의 `added` 값으로 현재 찜 여부를 즉시 갱신할 수 있습니다.


### DELETE /api/modules/sirsoft-ecommerce/wishlist/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.wishlist.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.wishlist.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\WishlistController@destroy`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/wishlist/{id} HTTP/1.1
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

**설명** 찜 목록에서 특정 찜 항목(`id`)을 삭제합니다. `auth:sanctum` 인증이 필요하며, `WishlistController@destroy`가 `ProductWishlistService::destroy()`로 본인 소유 찜만 삭제합니다. 상품 ID가 아니라 찜 레코드 ID로 삭제하며, 해당 항목이 본인 것이 아니거나 존재하지 않으면 404를 반환합니다. 상품 상세의 하트 토글과 달리 마이페이지 찜 목록에서 특정 항목을 명시적으로 제거할 때 사용합니다.


