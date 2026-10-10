# Scraps API 레퍼런스 (sirsoft-board)

> **소유**: sirsoft-board 모듈 · 이 문서는 **손으로 작성**했습니다. `api:docgen` 의 파괴적 재생성
> 가드(`docs/backend/api-documentation.md`)를 따라 스캐폴딩 덮어쓰기를 하지 않았습니다.
> 값은 개발 환경에서 실제 호출해 관측한 것입니다.

---

## TL;DR (5초 요약)

```text
1. 스크랩은 회원이 게시글을 개인 보관함에 담는 기능입니다 (이슈 #524)
2. 회원용 3개 + 관리자용 3개 엔드포인트가 있습니다
3. 스크랩 레코드는 원글·게시판이 지워져도 남습니다 — 목록이 그것을 감추지 않는 것이 설계입니다
4. 검색 대상을 고를 수 있습니다 (회원 3갈래 / 관리자 6갈래)
5. 권한: sirsoft-board.scraps.view(조회) · .manage(삭제)
6. 처리 중 서버 오류는 500 으로 답합니다(입력 오류로 위장하지 않음 — 종전 400)
```

---

## 스크랩 행의 네 상태

목록은 아래 넷을 **모두** 보여 줍니다. 감추면 정리할 대상을 찾지 못하기 때문입니다.

| 상태 | 판정 | 화면 표기 |
| --- | --- | --- |
| 정상 | 게시판 살아 있고 `use_scrap` on, 원글 살아 있음 | 제목 클릭 → 원글 |
| 스크랩 중지 | 게시판의 `use_scrap` 가 꺼짐(또는 비활성) | 「스크랩 중지」 배지, 링크 없음 |
| 삭제된 원글 | 원글 소프트 삭제 | 「삭제된 게시글」 + 「삭제됨」, 제목 비노출 |
| 게시판 삭제됨 | `board_id` 가 가리키는 게시판이 사라짐 (`board: null`) | 「게시판 삭제됨」 배지 |

검색은 이 중 «스크랩 중지»·«삭제된 원글» 을 **대상에서 뺍니다** — 링크가 없는 행을 검색으로
찾아 내도 할 수 있는 일이 없습니다.

---

## 회원용

### GET /api/modules/sirsoft-board/me/scraps

- **인증**: `auth:sanctum` (본인 것만)

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 페이지 번호 |
| search | query | string | 아니오 | max 255 | 검색어 |
| search_field | query | string | 아니오 | `title_content`(기본), `title`, `content` | 검색 대상. 값이 규칙에 없으면 기본값 `title_content` 로 처리 |

**응답 필드** (`data.data[]`)

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| id | integer | 스크랩 식별자 (삭제가 받는 값) |
| post_id | integer | 원글 식별자 |
| board_name | string | 현재 로케일 문자열 (다국어 객체가 아닙니다 — 객체를 그대로 주면 카드 렌더가 깨집니다) |
| title | string\|null | 원글 제목. 삭제된 원글이면 노출하지 않습니다 |
| author_name | string | 작성자. 탈퇴 회원이면 익명 표기 |
| comment_count | integer\|null | 댓글 수 |
| scrapped_at | string | 스크랩 시각 (서버가 포맷한 상대 시간) |
| is_deleted | boolean | 원글 삭제 여부 |
| scrap_enabled | boolean | 그 게시판이 지금도 스크랩을 쓰는가 |
| board_deleted | boolean | 게시판이 지워졌는가 |

### DELETE /api/modules/sirsoft-board/me/scraps/{id}

본인 소유가 아니면 `404` — 남의 스크랩 id 를 넣어 존재 여부를 떠보지 못하게 합니다.

### DELETE /api/modules/sirsoft-board/me/scraps

선택 해제(일괄).

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | 1~100개 | 해제할 스크랩 식별자 |
| ids.* | body | integer | 예 | — | **`exists` 규칙을 두지 않습니다** — 두면 남의 id 를 넣어 존재 여부를 알아낼 수 있습니다. 소유 검사는 삭제 쿼리가 합니다 |

응답 `deleted_count` 는 **실제로 지워진 내 것의 수**입니다(남의 id 를 섞어 보내도 그것은 세지 않습니다).

---

## 관리자용

### GET /api/modules/sirsoft-board/admin/scraps

- **인증/권한**: `auth:sanctum` + `permission:admin,sirsoft-board.scraps.view`

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page / per_page | query | integer | 아니오 | per_page max 100 | 페이지네이션 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 스크랩일 정렬 방향 (정렬 축이 하나라 `sort_by` 를 받지 않습니다) |
| board_id | query | integer | 아니오 | — | 게시판 한정 |
| user_id | query | integer | 아니오 | — | 회원 한정 (ID) |
| user_uuid | query | string(uuid) | 아니오 | uuid | 회원 한정 (uuid). 없는 uuid 면 빈 결과 |
| scrapped_at_from / _to | query | date | 아니오 | `Y-m-d` | 스크랩일 범위 (보는 사람의 시간대 하루로 해석) |
| filters[0][field] | query | string | 아니오 | `all`, `post_title`, `post_content`, `board_name`, `member`, `author_name` | 검색 대상 |
| filters[0][value] | query | string | 아니오 | max 255 | 검색어 |
| filters[0][operator] | query | string | 아니오 | `like` | 연산자 |

**응답 필드** (`data.data[]`)

| 필드 | 타입 | 실측 예시값 | 용도 |
| --- | --- | --- | --- |
| id | integer | `248` | 스크랩 식별자 |
| post_id | integer | `869` | 원글 식별자 |
| board_id | integer\|null | `null` | 게시판 식별자. 게시판이 지워졌으면 `null` |
| member.uuid | string(uuid) | `a2be5dfd-…` | 스크랩한 회원 |
| member.name | string | `스크랩회원A` | 회원 이름 (탈퇴면 익명) |
| member.email | string\|null | `e2e-scrap-a@example.test` | 관리자 응답에만 실립니다 |
| board | object\|null | `null` | 게시판. 지워졌으면 `null` |
| board.slug / name | string | `e2e-scrap-notice` / `공지사항(E2E)` | 게시판 식별자·이름 |
| board.is_active | boolean | `true` | 게시판 활성 여부 |
| board.use_scrap | boolean | `false` | **지금도 스크랩을 쓰는가** — 화면의 「스크랩 꺼짐」 배지가 읽습니다 |
| post_title | string | `알파 제목 — 공지` | 원글 제목. 관리자 목록은 **삭제된 원글의 제목도 보여 줍니다**(회원 목록과 다릅니다 — 운영자는 무엇을 정리하는지 알아야 합니다) |
| post_status | string | `published` | 원글 상태 |
| post_status_label | string | `게시됨` | 상태 표시 문자열 |
| is_deleted | boolean | `false` | 원글 삭제 여부 |
| author_name | string | `스크랩회원B` | 원글 작성자 (스크랩한 회원과 다를 수 있습니다) |
| scrapped_at | string | `방금 전` | 스크랩 시각 — 모듈 표시 설정(`display.date_display_format`)을 따르므로 「방금 전」 같은 상대 표기일 수 있습니다 |
| created_at | string | `2026-09-14 15:05:31` | 스크랩 시각의 절대 표기(초 단위). 저장은 UTC 이고 이 값은 회원 표시 시간대로 환산됩니다 — 관리 목록은 선후를 가려야 하므로 이 필드를 씁니다 |
| abilities.can_delete | boolean | `true` | 삭제 권한 — 화면의 체크 칸이 이 값으로 열립니다 |

**응답 메타**: `data.abilities{can_view,can_manage}` + `data.boards[]`(게시판 필터 선택지 — `id`·`slug`·`name`, 비활성 게시판 포함, 최근 생성 순) + `data.pagination`.
게시판 선택지를 목록 응답에 싣는 이유는 게시판 목록 API 가 게시판 관리 권한이 있어야 열려,
`scraps.view` 만 가진 매니저가 그 API 로는 게시판을 고를 수 없기 때문입니다.

### DELETE /api/modules/sirsoft-board/admin/scraps/{id}

- **권한**: `sirsoft-board.scraps.manage`

삭제하면 원글의 `scrap_count` 가 줄고 훅 `after_toggle` 이 **스크랩 소유자 id** 로 발화합니다.
활동 로그의 `user_id` 는 그와 별개로 **로그인한 관리자**입니다 — 로그는 «누가 했나» 를 남기고,
훅 인자는 «누구 것이 바뀌었나» 를 알립니다.

### DELETE /api/modules/sirsoft-board/admin/scraps/bulk

- **권한**: `sirsoft-board.scraps.manage`
- 라우트는 `{id}` 보다 **먼저** 선언되어 있습니다 — 그러지 않으면 `bulk` 가 id 로 잡힙니다.

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | 1~100개 | 삭제할 스크랩 식별자 |
| ids.* | body | integer | 예 | 존재하는 스크랩 | 관리자 경로는 `exists` 를 둡니다 — 회원 경로와 달리 열람 권한이 이미 전제되어 있어 존재 여부가 비밀이 아닙니다 |

---

## 회원 상세 응답의 요약

`GET /api/admin/users/{uuid}` 의 관리자 응답에 `board_scraps` 가 실립니다
(`UserScrapInfoListener` 가 관리자 요청에만 주입 — 프로필 응답에는 없습니다). 보는 관리자에게
`sirsoft-board.scraps.view` 권한이 없으면 싣지 않습니다 — 실으면 회원 상세가 건수를 그리고,
「이 회원 스크랩 목록 보기」는 403 화면으로 끝납니다.

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| board_scraps.count | integer | 스크랩 총 건수 |
| board_scraps.latest_scrapped_at | string\|null | 최근 스크랩 시각 |
