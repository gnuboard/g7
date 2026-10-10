# Admin User Addresses API 레퍼런스 (sirsoft-ecommerce)

> **소유**: sirsoft-ecommerce 모듈 · 이 문서는 **손으로 작성**했습니다.
> `api:docgen` 의 파괴적 재생성 가드(`docs/backend/api-documentation.md`)를 따라 스캐폴딩
> 덮어쓰기를 하지 않았습니다.

---

## TL;DR (5초 요약)

```text
1. 회원이 남긴 배송지 를 전 회원 범위로 조회·삭제하는 관리자 API 입니다 (이슈 #524 WP-E)
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

### GET /api/modules/sirsoft-ecommerce/admin/user-addresses

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

- **권한**: `sirsoft-ecommerce.user-addresses.manage`

---

### DELETE /api/modules/sirsoft-ecommerce/admin/user-addresses/bulk

- **권한**: `sirsoft-ecommerce.user-addresses.manage`
- 라우트는 `{id}` 보다 **먼저** 선언되어 있습니다 — 그러지 않으면 `bulk` 가 id 로 잡힙니다.

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | 1~100개 | 삭제할 식별자 |
| ids.* | body | integer | 예 | 존재하는 행 | 식별자 |

응답 `deleted_count` 는 실제로 지워진 건수입니다. 일괄 삭제는 활동 로그를 **한 번만** 남깁니다 —
건마다 남기면 100건 삭제가 로그 100줄이 됩니다.
