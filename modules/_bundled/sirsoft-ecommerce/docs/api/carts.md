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

### GET /api/modules/sirsoft-ecommerce/admin/carts

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

- **권한**: `sirsoft-ecommerce.carts.manage`

---

### DELETE /api/modules/sirsoft-ecommerce/admin/carts/bulk

- **권한**: `sirsoft-ecommerce.carts.manage`
- 라우트는 `{id}` 보다 **먼저** 선언되어 있습니다 — 그러지 않으면 `bulk` 가 id 로 잡힙니다.

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | 1~100개 | 삭제할 식별자 |
| ids.* | body | integer | 예 | 존재하는 행 | 식별자 |

응답 `deleted_count` 는 실제로 지워진 건수입니다. 일괄 삭제는 활동 로그를 **한 번만** 남깁니다 —
건마다 남기면 100건 삭제가 로그 100줄이 됩니다.
