# Admin User Addresses API 레퍼런스 (sirsoft-ecommerce)

> **소유**: sirsoft-ecommerce 모듈 · 이 문서는 **손으로 작성**했습니다.
> `api:docgen` 의 파괴적 재생성 가드(`docs/backend/api-documentation.md`)를 따라 스캐폴딩
> 덮어쓰기를 하지 않았습니다.

---

## TL;DR (5초 요약)

```text
1. 회원이 남긴 배송지 를 전 회원 범위로 조회·삭제하는 관리자 API 입니다
2. 찜·장바구니·배송지·상품문의 네 목록이 같은 뼈대를 씁니다 — 검색·회원한정·기간·일괄삭제
3. 받는 사람·주소로도 찾을 수 있습니다 (회원 이름과 다를 수 있습니다)
4. 권한: sirsoft-ecommerce.user-addresses.read(조회) · sirsoft-ecommerce.user-addresses.manage(삭제)
5. 회원 상세 「쇼핑 데이터」 탭이 네 갈래의 건수를 함께 보여 주고 각 목록으로 넘깁니다
```

---

## 이 목록만의 차이

받는 사람 이름과 주소는 **회원 이름과 다를 수 있습니다** — 선물·대리 수령이 흔하기 때문입니다.
주문 문의가 「그 주소로 받은 사람」 이름으로 들어오는 경우가 있어 `recipient`·`address`
검색 갈래를 따로 둡니다.

`is_default` 는 회원의 기본 배송지 여부입니다.

---


## 권한 범위(본인만·소유역할)

관리자 역할에 `sirsoft-ecommerce.user-addresses.read`·`.manage` 를 「본인만」 또는 「소유역할」 범위로 주면 그 범위가
목록과 삭제 모두에 적용됩니다(판정 기준은 배송지 행의 회원).

- 목록: 범위 밖 회원의 행은 목록·건수에서 빠집니다. 비회원 행(회원 없음)은 「전체」 범위에서만 보입니다.
- 단건 삭제: 범위 밖 행이면 403(`auth.scope_denied`), 없는 행이면 404 입니다.
- 일괄 삭제: 범위 밖 행이 하나라도 섞이면 403(`auth.scope_denied`)으로 전체를 거부하고 아무것도 지우지 않습니다.

---

### GET /api/modules/sirsoft-ecommerce/admin/user-addresses
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.user-addresses.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.user-addresses.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\AdminUserAddressController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-addresses.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| sort | query | string | 아니오 | `created_at_desc`(기본), `created_at_asc` | 정렬 |
| search_field | query | string | 아니오 | `all`, `member`, `member_id`, `email`, `recipient`, `address` | 검색 대상 |
| search_keyword | query | string | 아니오 | max 100 | 검색어 |
| user_id | query | integer | 아니오 | min 1 | 회원 ID 로 한정. 함께 오면 `user_uuid` 보다 우선한다 |
| user_uuid | query | string(uuid) | 아니오 | uuid | 회원 한정(단건, 레거시 링크 호환). 없는 uuid 면 빈 결과 |
| user_uuids | query | array\|string | 아니오 | uuid 최대 50개 | 회원 한정(다중). `user_uuids[]=…` 반복 또는 쉼표로 이어 붙인 문자열. 고른 uuid 가 전부 없는 회원이어도 빈 결과 — 필터가 풀려 전 회원이 노출되지 않습니다. 이 값이 있으면 `user_uuid` 는 무시합니다(둘을 함께 걸면 교집합이 빕니다) |
| start_date | query | date | 아니오 | — | 조회 기간 시작일 (이 날짜 이후 데이터) |
| end_date | query | date | 아니오 | — | 조회 기간 종료일 (이 날짜 이전 데이터) |
| country_code | query | string | 아니오 | max 2 | 국가 코드 (ISO 3166-1 alpha-2) |
| is_default | query | boolean | 아니오 | `0`, `1` | 기본 배송지만/아닌 것만 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/user-addresses?page=1&per_page=1&sort=created_at_desc&search_field=all&search_keyword=%EC%98%88%EC%8B%9C%EA%B0%92&user_id=1&user_uuid=%EC%98%88%EC%8B%9C%EA%B0%92&user_uuids=%EC%98%88%EC%8B%9C%EA%B0%92&start_date=2026-01-01&end_date=2026-01-01&country_code=KR&is_default=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `121` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `3347` | 식별자 (삭제가 받는 값) |
| member | object | `{"uuid":"a3172221-e180-46c2-a161-819cc0df5d15","name":"홍…` | 회원. 지워졌으면 `null` |
| member.uuid | string | `a3172221-e180-46c2-a161-819cc0df5d15` | 회원 UUID |
| member.name | string | `홍길동` | 회원 이름. 탈퇴 회원(`status=withdrawn`)이면 이름 대신 「탈퇴한 사용자」(`user.withdrawn_user`) |
| member.is_withdrawn | boolean | `false` | 탈퇴 회원 여부 |
| label | string | `회사` | 배송지 별칭 (`name` 컬럼 — 예: 집, 회사) |
| recipient_name | string | `김수령` | 수령인 이름. 회원 이름과 다를 수 있습니다 |
| recipient_phone | string | `010-1234-5678` | 수령인 연락처 (국제 형식 포함). 배송 사고 처리에 원문이 필요해 마스킹하지 않습니다 |
| country_code | string | `KR` | 배송지 국가 코드 (ISO 3166-1 alpha-2). `KR` 이면 `address_summary` 가 국내 주소 칸(우편번호, 주소, 상세)으로, 그 밖이면 해외 주소 칸(`address_line_1`, `address_line_2`, `city`, `state`, 국가 코드)으로 만들어집니다 |
| address_summary | string | `06236 서울 강남구 테헤란로 152 8층` | 주소 요약 (우편번호 + 주소 + 상세) |
| is_default | boolean | `true` | 기본 배송지 여부 |
| created_at | string | `2026-10-29 12:54:55` | 생성 시각 (뷰어 시간대) |
| abilities.can_manage | boolean | `true` | 컬렉션 단위 삭제 권한(`sirsoft-ecommerce.user-addresses.manage`) — 화면의 체크 칸과 일괄 버튼이 이 값으로 열립니다 |
| filters.members | array | `[]` | 적용된 회원 한정(`user_uuids`)을 `uuid`, `name`, `email` 까지 해석한 목록. 한정이 없으면 빈 배열 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "회원 배송지 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "number": 121,
                "id": 3347,
                "member": {
                    "uuid": "a3172221-e180-46c2-a161-819cc0df5d15",
                    "name": "홍길동",
                    "email": "user@example.com",
                    "is_withdrawn": false
                },
                "label": "회사",
                "recipient_name": "김수령",
                "recipient_phone": "010-1234-5678",
                "country_code": "KR",
                "address_summary": "06236 서울 강남구 테헤란로 152 8층",
                "is_default": true,
                "created_at": "2026-10-29 12:54:55"
            },
            {
                "number": 120,
                "id": 3334,
                "member": {
                    "uuid": "40abd432-8070-42c7-a93c-afe6a30aafc1",
                    "name": "이영희",
                    "email": "member@example.com",
                    "is_withdrawn": false
                },
                "label": "학교",
                "recipient_name": "이영희",
                "recipient_phone": "010-3367-7051",
                "country_code": "KR",
                "address_summary": "04524 서울특별시 중구 세종대로 110",
                "is_default": false,
                "created_at": "2026-10-10 17:19:13"
            },
            "... (총 20건 중 2건 표시)"
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
            "last_page": 7,
            "total": 121,
            "total_relation": "exact",
            "total_is_exact": true,
            "result_cap": 10000
        },
        "filters": {
            "members": []
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-addresses.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

- **인증/권한**: `auth:sanctum` + `permission:admin,sirsoft-ecommerce.user-addresses.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page / per_page | query | integer | 아니오 | per_page max 100 | 페이지네이션 |
| sort | query | string | 아니오 | `created_at_desc`(기본), `created_at_asc` | 정렬 |
| search_field | query | string | 아니오 | `member`, `member_id`, `email`, `recipient`, `address` | 검색 대상 |
| search_keyword | query | string | 아니오 | max 255 | 검색어 |
| user_uuid | query | string(uuid) | 아니오 | uuid | 회원 한정(단건, 레거시 링크 호환). 없는 uuid 면 빈 결과 |
| user_uuids | query | array\|string | 아니오 | uuid 최대 50개 | 회원 한정(다중). `user_uuids[]=…` 반복 또는 쉼표로 이어 붙인 문자열. 고른 uuid 가 전부 없는 회원이어도 빈 결과 — 필터가 풀려 전 회원이 노출되지 않습니다. 이 값이 있으면 `user_uuid` 는 무시합니다(둘을 함께 걸면 교집합이 빕니다) |
| start_date / end_date | query | date | 아니오 | `Y-m-d` | 생성일 범위 |
| is_default | query | boolean | 아니오 | `0`, `1` | 기본 배송지만/아닌 것만 |

**응답 필드** (`data.data[]` + `data.abilities` + `data.pagination` + `data.filters`)

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| id | integer | 식별자 (삭제가 받는 값) |
| member | object\|null | 회원. 지워졌으면 `null` |
| member.uuid / name | string | 회원 식별자·이름 (탈퇴면 익명 표기) |
| member.email | string\|null | 관리자 응답에만 실립니다 |
| created_at | string | 생성 시각 (뷰어 시간대) |
| recipient | object | 받는 사람 (이름 · 연락처) |
| address_summary | string | 주소 요약 (우편번호 + 주소 + 상세) |
| is_default | boolean | 기본 배송지 여부 |
| abilities.can_manage | boolean | 삭제 권한 — 화면의 체크 칸·일괄 버튼이 이 값으로 열립니다 |
| filters.members[] | array | **적용된 회원 한정을 이름까지 해석한 목록**(`uuid`·`name`·`email`). 한정이 없으면 빈 배열입니다. 화면의 회원 필터가 이 값으로 이름표를 세웁니다 — 코어 회원 검색(`/api/admin/users/search`)은 uuid 를 한 건씩만 받아, 한정이 세 명이면 왕복이 세 번 나기 때문입니다 |

---

### DELETE /api/modules/sirsoft-ecommerce/admin/user-addresses/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.user-addresses.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.user-addresses.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\AdminUserAddressController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-addresses.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | — | 삭제할 배송지 ID (목록 응답의 `id`. 라우트가 숫자만 받는다 — `whereNumber`) |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/user-addresses/{id} HTTP/1.1
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
    "message": "배송지를 삭제했습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-addresses.manage`)이 없는 경우, 또는 역할의 권한 범위가 「본인만」 또는 「소유역할」 이라 그 행의 회원이 범위 밖인 경우 (`auth.scope_denied`) |
| 404 | Not Found | 해당 ID 의 배송지가 없는 경우 (`common.not_found`) |

<!-- @generated:end -->

**설명**

- **권한**: `sirsoft-ecommerce.user-addresses.manage`

---

### DELETE /api/modules/sirsoft-ecommerce/admin/user-addresses/bulk
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.user-addresses.bulk-destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.user-addresses.bulk-destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\AdminUserAddressController@bulkDestroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.user-addresses.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | query | array | 예 | min 1, max 100 | 삭제할 배송지 ID 배열. 원소는 정수이고 중복 없이(`distinct`) 실제로 있는 행이어야 한다(`exists`). 쿼리 문자열(`ids[]=1&ids[]=2`)과 JSON 본문 어느 쪽으로 보내도 같은 검증을 탄다 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/user-addresses/bulk?ids=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
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
    "message": "3개의 배송지를 삭제했습니다.",
    "data": {
        "deleted_count": 3
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.user-addresses.manage`)이 없는 경우, 또는 고른 행 중 하나라도 역할의 권한 범위(「본인만」 또는 「소유역할」) 밖 회원의 것인 경우 (`auth.scope_denied` — 전체를 거부하고 아무것도 지우지 않음) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) — `ids` 누락, 빈 배열, 100개 초과, 정수가 아닌 원소, 중복 ID, 이미 지워졌거나 없는 ID(`ids.N` 의 `exists` 위반. 목록이 낡았다는 신호라 조용히 건너뛰지 않음) |

<!-- @generated:end -->

**설명**

- **권한**: `sirsoft-ecommerce.user-addresses.manage`
- 라우트는 `{id}` 보다 **먼저** 선언되어 있습니다 — 그러지 않으면 `bulk` 가 id 로 잡힙니다.

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | 1~100개 | 삭제할 식별자 |
| ids.* | body | integer | 예 | 존재하는 행 | 식별자 |

응답 `deleted_count` 는 실제로 지워진 건수입니다. 일괄 삭제는 활동 로그를 **한 번만** 남깁니다 —
건마다 남기면 100건 삭제가 로그 100줄이 됩니다.
