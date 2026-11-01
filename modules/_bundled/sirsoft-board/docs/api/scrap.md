# Scrap API 안내

> **소유**: module `sirsoft-board`. 회원용 스크랩 엔드포인트가 어디에 있는지 안내하는 문서입니다.
> 엔드포인트별 파라미터, 응답, 오류, 동작 설명은 아래 생성 절에 있습니다.

---

## TL;DR (5초 요약)

```text
1. 회원용 게시글 스크랩(북마크) 엔드포인트는 토글, 목록, 개별 삭제, 선택 해제 4종입니다
2. 토글은 상세 버튼 전용입니다. 있으면 해제, 없으면 등록하며 added 로 결과를 구분합니다
3. 목록은 마이페이지 스크랩 탭용이며 최근 스크랩순이고, 삭제된 원글도 포함합니다(is_deleted)
4. 개별 삭제는 스크랩 레코드 ID 기준이며 본인 소유만 지웁니다
5. 모두 auth:sanctum 회원 전용입니다
```

---

## 엔드포인트 위치

| 메서드 | 경로 | 라우트명 | 문서 |
| --- | --- | --- | --- |
| POST | `/api/modules/sirsoft-board/boards/{slug}/posts/{postId}/scrap/toggle` | `api.modules.sirsoft-board.boards.posts.scrap.toggle` | [boards.md](boards.md) |
| GET | `/api/modules/sirsoft-board/me/scraps` | `api.modules.sirsoft-board.me.scraps.index` | [scraps.md](scraps.md) |
| DELETE | `/api/modules/sirsoft-board/me/scraps/{id}` | `api.modules.sirsoft-board.me.scraps.destroy` | [scraps.md](scraps.md) |
| DELETE | `/api/modules/sirsoft-board/me/scraps` | `api.modules.sirsoft-board.me.scraps.bulk-destroy` | [scraps.md](scraps.md) |

관리자 스크랩 관리 3종과 스크랩 행의 네 상태는 [scraps.md](scraps.md) 에 있습니다.
