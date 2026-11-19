# Reaction API 안내

> **소유**: module `sirsoft-board`. 게시글 반응과 댓글 반응(추천/비추천) 엔드포인트가 어디에 있는지 안내하는 문서입니다.
> 엔드포인트별 파라미터, 응답, 오류, 동작 설명은 아래 생성 절에 있습니다.

---

## TL;DR (5초 요약)

```text
1. 게시글 반응의 등록, 전환, 해제를 POST 엔드포인트 하나가 처리합니다
2. 반응은 로그인 회원만 가능하며(auth:sanctum), 본인 글에는 반응할 수 없습니다
3. 글당 반응 1개입니다. 같은 유형 재요청은 해제, 다른 유형은 전환(이전 -1, 신규 +1)입니다
4. 댓글 반응도 같은 규칙으로 동작합니다. 게시판이 use_reaction 과 use_comment_reaction 을 둘 다 켰을 때만입니다
5. 관리자 반응 유형 목록 API 는 게시판 설정 화면의 유형 체크박스 옵션 소스입니다
6. 유형 추가, 수정, 삭제와 아이콘 업로드는 reaction-types.md 가 다룹니다
```

---

## 엔드포인트 위치

| 메서드 | 경로 | 라우트명 | 문서 |
| --- | --- | --- | --- |
| POST | `/api/modules/sirsoft-board/boards/{slug}/posts/{postId}/react` | `api.modules.sirsoft-board.boards.posts.react` | [boards.md](boards.md) |
| POST | `/api/modules/sirsoft-board/boards/{slug}/comments/{commentId}/react` | `api.modules.sirsoft-board.boards.comments.react` | [boards.md](boards.md) |
| GET | `/api/modules/sirsoft-board/admin/reaction-types` | `api.modules.sirsoft-board.admin.reaction-types.index` | [reaction-types.md](reaction-types.md) |

회원의 반응 내역 조회와 해제(마이페이지), 관리자의 반응 관리는 [reactions.md](reactions.md) 에 있습니다.
