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

### GET /api/modules/sirsoft-ecommerce/admin/wishlists

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

- **권한**: `sirsoft-ecommerce.wishlists.manage`

---

### DELETE /api/modules/sirsoft-ecommerce/admin/wishlists/bulk

- **권한**: `sirsoft-ecommerce.wishlists.manage`
- 라우트는 `{id}` 보다 **먼저** 선언되어 있습니다 — 그러지 않으면 `bulk` 가 id 로 잡힙니다.

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | 1~100개 | 삭제할 식별자 |
| ids.* | body | integer | 예 | 존재하는 행 | 식별자 |

응답 `deleted_count` 는 실제로 지워진 건수입니다. 일괄 삭제는 활동 로그를 **한 번만** 남깁니다 —
건마다 남기면 100건 삭제가 로그 100줄이 됩니다.
