# API 레퍼런스 문서 목차

> **소유**: 모듈 `sirsoft-board` · **생성**: `php artisan api:docgen` (실측 기반).
> 아래 표는 자동 생성됩니다. 각 문서를 열면 엔드포인트별 파라미터·응답·예시를 볼 수 있습니다.

<!-- @generated:start:api-readme-index -->
- **문서 수**: 10 · **엔드포인트 수**: 80

| 문서 | 도메인 | 엔드포인트 |
| --- | --- | --- |
| [activity-stats.md](activity-stats.md) | `activity-stats` | 1 |
| [board.md](board.md) | `board` | 18 |
| [board-activities.md](board-activities.md) | `board-activities` | 1 |
| [board-types.md](board-types.md) | `board-types` | 4 |
| [boards.md](boards.md) | `boards` | 37 |
| [dashboard.md](dashboard.md) | `dashboard` | 4 |
| [my-comments.md](my-comments.md) | `my-comments` | 1 |
| [reports.md](reports.md) | `reports` | 7 |
| [settings.md](settings.md) | `settings` | 5 |
| [users.md](users.md) | `users` | 2 |

<!-- @generated:end -->

## 공통 요청 헤더

### `X-Board-Secret-View-Token`

비밀글에 딸린 하위 콘텐츠를 만드는 요청(댓글 작성, 대댓글 작성, 답글 작성, 게시글·댓글 신고)은
부모 게시글의 원문 열람 권한을 다시 확인합니다. 작성자 본인·게시판 관리자·비밀글 읽기 권한자는
그대로 통과하지만, **비밀번호를 입력해 원문을 연 사용자**는 그 사실이 검증 응답 하나에만
남기 때문에 이 헤더로 넘겨야 합니다.

- 발급: `POST /boards/{slug}/posts/{id}/verify-password` 응답 최상위 `secret_view_token`
- 사용: 이후 그 게시글 관련 요청의 `X-Board-Secret-View-Token` 헤더
- 범위: 발급받은 게시글에만 유효하며 다른 글에는 통하지 않습니다
- 수명: 소비되지 않고 `secret_view_expires_at` 까지 여러 번 쓸 수 있습니다
- 미제시: 위 경로에서 `422` 와 함께 "열람 권한이 없는 비밀글" 문구가 반환됩니다.
  비밀글이 아닌 게시글에서는 이 헤더가 평가되지 않습니다.

