# Reactions API 레퍼런스 (sirsoft-board · 회원/관리자 반응 내역)

> **소유**: sirsoft-board 모듈 · 이 문서는 **손으로 작성**했습니다. `api:docgen` 의 파괴적 재생성
> 가드(`docs/backend/api-documentation.md`)를 따라 스캐폴딩 덮어쓰기를 하지 않았습니다.
> 값은 개발 환경에서 실제 호출해 관측한 것입니다.
>
> 반응을 **남기는** 엔드포인트(글/댓글 토글)는 [reaction.md](reaction.md) 를,
> 반응 **유형** 관리·아이콘 업로드는 [reaction-types.md](reaction-types.md) 를 보세요.

---

## TL;DR (5초 요약)

```text
1. 회원이 남긴 반응 내역을 조회·해제하고(마이페이지), 운영자가 전 회원 반응을 관리합니다 (이슈 #525)
2. 회원용 3개 + 관리자용 3개 엔드포인트가 있습니다
3. 게시글 반응과 댓글 반응이 한 목록에 섞여 나옵니다 (target_type 으로 구분)
4. 반응 행은 원글·댓글·게시판이 지워져도 남습니다 — 해제(회수)는 언제나 가능합니다
5. 권한: sirsoft-board.reactions.view(조회) · .manage(삭제)
6. 관리자 목록·삭제의 처리 중 서버 오류는 500 으로 답합니다(입력 오류로 위장하지 않음 — 종전 400)
```

---

## 반응 행의 네 상태

목록은 아래 넷을 **모두** 보여 줍니다. 감추면 정리할 대상을 찾지 못하기 때문입니다.

| 상태 | 판정 | 화면 표기 |
| --- | --- | --- |
| 정상 | 게시판 살아 있고 `use_reaction` on(댓글이면 `use_comment_reaction` 도), 대상 살아 있음 | 제목 클릭 → 대상 |
| 반응 꺼짐 | 게시판의 `use_reaction`(또는 `use_comment_reaction`)가 꺼짐·비활성 | 「반응 꺼짐」 배지, 링크 없음 |
| 삭제된 대상 | 글/댓글 소프트 삭제 또는 미게시 | 「삭제된 게시글/댓글」, 제목·발췌 비노출 |
| 게시판 삭제됨 | `board_id` 가 가리키는 게시판이 사라짐 (`board: null`) | 「게시판 삭제됨」 배지 |

어느 상태든 **해제는 허용**합니다 (설계 결정 R11) — 남긴 기록을 회수하는 것은 게시판 설정과
무관한 회원의 권리입니다. 마이페이지 검색은 «삭제된 대상» 을 **대상에서 뺍니다**.

---

## 회원용 (마이페이지 「내 반응」)

### GET /api/modules/sirsoft-board/me/reactions

- **인증**: `auth:sanctum` (본인 것만)

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 페이지 번호 |
| per_page | query | integer | 아니오 | 1~100 (기본 10) | 페이지당 건수 |
| search | query | string | 아니오 | max 255 | 검색어 |
| search_field | query | string | 아니오 | `title_content`(기본), `title`, `content` | 검색 대상. 규칙 밖의 값은 기본값 `title_content` 로 처리 |
| reaction_type_id | query | integer | 아니오 | min 1 | 반응 유형 필터 |
| target_type | query | string | 아니오 | `all`(기본), `post`, `comment` | 대상 구분 필터 |
| board_slug | query | string | 아니오 | max 50 | 게시판 필터 |
| sort | query | string | 아니오 | `latest`(기본), `oldest` | 정렬 |

> 제목 전용 검색(`title`)은 댓글 분기를 켜지 않습니다 — 댓글에는 제목이 없습니다.

**응답 필드** (`data.data[]`)

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| id | integer | 반응 식별자 (해제가 받는 값) |
| target_type | string | `post` \| `comment` |
| target_id | integer | 대상 식별자 |
| post_id | integer\|null | 이동 대상 글 ID (댓글 반응도 부모 글로 갑니다) |
| comment_id | integer\|null | 댓글 반응일 때의 댓글 ID |
| title | string\|null | 글 제목 (댓글 반응도 부모 글 제목) |
| excerpt | string\|null | 댓글 본문 발췌 80자. 삭제된 댓글이면 null |
| board | object\|null | `{slug,name,is_active,use_reaction,use_comment_reaction}`. 게시판 삭제 시 null |
| board_deleted | boolean | 게시판 소실 여부 |
| reaction_type | object\|null | `{id,code,name,icon_name,icon_url}` |
| author_name | string\|null | 대상 작성자. 탈퇴 회원이면 익명 표기 |
| target_deleted | boolean | 대상 소실·미게시 여부 |
| reaction_enabled | boolean | 지금도 반응이 열려 있는 상태인지 (링크·버튼 노출 판정) |
| reacted_at | string | 반응 시각 — 모듈 표시 설정(`display.date_display_format`)을 따르므로 「2시간 전」 같은 상대 표기일 수 있습니다 |
| created_at | string | 반응 시각의 절대 표기(초 단위). 저장은 UTC 이고 이 값은 회원 표시 시간대로 환산됩니다 — 관리 목록은 선후를 가려야 하므로 이 필드를 씁니다 |

**응답 메타**

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| data.reaction_types[] | array | 필터 셀렉트 옵션. **비활성 유형 포함** — 이미 끈 유형으로 남긴 과거 반응도 골라내야 합니다 |
| data.boards[] | array | 게시판 필터 셀렉트 옵션(`id`·`slug`·`name`, 비활성 게시판 포함, 최근 생성 순). 게시판 목록 API 는 게시판 관리 권한이 있어야 열리므로 `reactions.view` 만 가진 매니저도 고를 수 있게 여기 싣습니다 |
| data.query | object | 적용된 필터 에코 (화면 상태 복원용) |
| data.pagination | object | 표준 페이지네이션 메타 |

### DELETE /api/modules/sirsoft-board/me/reactions/{id}

- **인증**: `auth:sanctum` · 본인 소유만
- 남의 반응·없는 ID → **404** (존재 여부를 흘리지 않습니다)
- 해제 후 대상의 `reaction_counts` 캐시를 실제 행 수로 다시 집계합니다
- 대상이 이미 하드 삭제됐으면 재집계를 건너뜁니다 (행만 사라지면 됩니다)

### DELETE /api/modules/sirsoft-board/me/reactions

선택 해제(일괄). 본문 JSON 으로 `ids[]` 를 받습니다.

| 이름 | 타입 | 필수 | 허용값 |
| --- | --- | --- | --- |
| ids | array | 예 | 1~100개, 정수, 중복 불가 |

남의 ID·없는 ID 는 **조용히 건너뛰고** 실제 해제 건수만 돌려줍니다 — `exists` 를 걸면
"그 ID 가 존재하는가" 가 422/200 으로 갈려 남의 반응 ID 를 탐색하는 통로가 됩니다.

응답: `data.deleted_count` (integer)

---

## 관리자용 (게시판 관리 › 반응 관리)

권한은 게시판별이 아니라 **모듈 레벨**입니다 — 반응은 회원 소유 데이터라 게시판 경계를
넘어 조회됩니다 (스크랩 관리와 동형).

### GET /api/modules/sirsoft-board/admin/reactions

- **인증**: `auth:sanctum` + `admin` · **권한**: `sirsoft-board.reactions.view`

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page / per_page | query | integer | 아니오 | per_page 1~100 (기본 20) | 페이지네이션 |
| filters[0][field] | query | string | 아니오 | `all`(기본), `target_title`, `target_content`, `board_name`, `member`, `author_name` | 검색 대상 |
| filters[0][value] | query | string | 아니오 | max 255 | 검색어 |
| board_id | query | integer | 아니오 | min 1 | 게시판 필터 |
| user_id | query | integer | 아니오 | min 1 | 회원 필터 |
| user_uuid | query | string | 아니오 | uuid | 회원 필터(uuid). 없는 회원이면 **빈 결과** |
| reaction_type_id | query | integer | 아니오 | 존재하는 유형 | 유형 필터 |
| target_type | query | string | 아니오 | `post`, `comment` | 대상 구분 |
| reacted_at_from / _to | query | string | 아니오 | `Y-m-d`, to ≥ from | 반응일 범위 (보는 사람의 시간대 하루로 해석) |
| sort_order | query | string | 아니오 | `asc`, `desc`(기본) | 정렬 |

> 관리자 검색은 삭제된 대상도 **포함**합니다 — 정리할 대상을 찾는 화면이기 때문입니다.

**응답 필드** (`data.data[]`) — 회원용과 다른 부분만

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| member | object\|null | `{uuid,name,email,is_withdrawn}`. 이메일은 관리자 응답에만, 탈퇴 회원 이름은 익명화 |
| target_title / target_excerpt | string\|null | 삭제된 대상의 제목도 냅니다 (상태는 배지가 구분) |
| target_status / target_status_label | string | 삭제면 `deleted` |
| is_target_deleted | boolean | 대상 소실 여부 |
| reaction_type.is_active | boolean | 비활성 유형 배지 판정 |
| abilities.can_delete | boolean | = `reactions.manage` |

**응답 메타**: `data.abilities{can_view,can_manage}` + `data.reaction_types[]`(비활성 포함) + `data.boards[]`(게시판 선택지).
유형 옵션을 목록 응답에 싣는 이유는 `reactions.view` 만 가진 매니저가 `settings.read` 가 없어
유형 API 를 부를 수 없기 때문입니다.

### DELETE /api/modules/sirsoft-board/admin/reactions/{id}

- **권한**: `sirsoft-board.reactions.manage` (view 만 있으면 403)
- 소유자와 무관하게 삭제하고 대상의 `reaction_counts` 를 재집계합니다
- 없는 ID → 404

### DELETE /api/modules/sirsoft-board/admin/reactions/bulk

`/{id}` 보다 **먼저** 선언되어 `bulk` 가 id 로 잡히지 않습니다.

| 이름 | 타입 | 필수 | 허용값 |
| --- | --- | --- | --- |
| ids | array | 예 | 1~100개, 정수, 중복 불가, **존재하는 반응** |

회원용과 달리 여기서는 `exists` 를 겁니다 — 관리자는 전 회원 반응을 다루므로 탐색 통로가
되지 않고, 잘못된 ID 를 조용히 넘기면 "지웠다고 생각한 건"과 실제 건수가 어긋납니다.

응답: `data.deleted_count` (integer)

---

## 회원 상세 요약 (코어 응답에 주입)

`UserReactionInfoListener` 가 `core.user.filter_resource_data` 필터 훅(priority 31)을 구독해
**관리자 회원 상세 응답에만** 아래 블록을 병합합니다. 보는 관리자에게 `sirsoft-board.reactions.view` 권한이
없으면 싣지 않습니다 — 실으면 회원 상세가 건수를 그리고, 「이 회원 반응 목록 보기」는 403 화면으로 끝납니다.

```json
"board_reactions": { "count": 12, "post_count": 9, "comment_count": 3, "latest_reacted_at": "..." }
```

프로필(`/api/me`)에는 싣지 않습니다 — 마이페이지 「내 반응」 탭이 목록 API 로 더 정확한
정보를 내고, 프로필 응답은 모든 화면 부팅 경로에서 읽히므로 집계 비용을 얹지 않습니다.
회원 목록에는 이 훅이 발화하지 않아 N+1 이 구조적으로 없습니다.
