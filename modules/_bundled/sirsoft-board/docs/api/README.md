# API 레퍼런스 문서 목차

> **소유**: 모듈 `sirsoft-board` · **생성**: `php artisan api:docgen` (실측 기반).
> 아래 표는 자동 생성됩니다. 각 문서를 열면 엔드포인트별 파라미터·응답·예시를 볼 수 있습니다.
>
> **응답 문구**: `message` 의 언어와 개수에 따른 단수·복수(영어에서 1이면 단수, 0·2 이상이면 복수) 규칙은 코어와 같습니다 — [코어 API 안내 「로케일」](../../../../../docs/backend/api/README.md#로케일). 검증 오류(`errors`)·예외 문구 중 상한이 설정값인 것(첨부·이미지 개수 상한 등)도 그 값으로 고릅니다.
>
> **이슈 #524 주의**: `scraps.md` 행과 `board.md`·총계의 증분은 **손으로 넣었습니다**. `api:docgen` 기본
> 모드는 파괴적 재생성 가드(`docs/backend/api-documentation.md`)가 막고 있어 이 블록만 다시 굽는 방법이
> 없는데, 표에 없는 문서는 아무도 찾지 못하기 때문입니다. 가드가 풀려 생성기를 다시 돌리면 이 값들은
> 생성기 산식으로 덮여도 무방합니다 — `scraps.md` 의 6개 절 중 회원용 2개는 `scrap.md` 와 겹칩니다.
>
> **이슈 #525 주의**: `reports.md` 행의 7→9 와 총계의 +2 도 같은 이유로 **손으로 넣었습니다**.
> 마이페이지 「내 신고」 2개(`GET /me/reports`·`DELETE /me/reports/{reportLogId}`)는 회원 인증과
> 본인 소유 데이터가 필요해 `api:docgen` 의 실측 대상이 아니라, 생성기가 세지 못합니다.
>
> **2026-09-16 (develop-7.1.0 2차 병합) 실측 대조**: `php artisan api:docgen --scope=module:sirsoft-board --check`
> 결과 **drift 없음** — 이 문서가 코드 실측과 어긋나지 않음을 확인했습니다(병합으로 유입된 라우트 변경 없음).
> 다만 아래 자동 생성 블록의 **선언 총계(문서 12 · 엔드포인트 94)가 표 실물(행 15 · 합 106)과 어긋나** 있습니다.
> #524·#525 가 손으로 더한 행(`scraps`·`reaction*` 3종)이 표에는 들어갔지만 총계에는 반영되지 않은 상태이며,
> 위 두 주의 항목이 밝힌 대로 이 블록은 `--check` 가드 때문에 부분 재생성이 불가합니다. 가드가 풀려
> 생성기를 다시 돌리면 총계가 산식으로 바로잡힙니다 — 엔드포인트 문서 자체의 정확성과는 무관합니다.

<!-- @generated:start:api-readme-index -->
- **문서 수**: 12 · **엔드포인트 수**: 94

| 문서 | 도메인 | 엔드포인트 |
| --- | --- | --- |
| [activity-stats.md](activity-stats.md) | `activity-stats` | 1 |
| [board.md](board.md) | `board` | 21 |
| [board-activities.md](board-activities.md) | `board-activities` | 1 |
| [board-types.md](board-types.md) | `board-types` | 4 |
| [boards.md](boards.md) | `boards` | 37 |
| [dashboard.md](dashboard.md) | `dashboard` | 4 |
| [identity-policies.md](identity-policies.md) | `identity-policies` | 4 |
| [my-comments.md](my-comments.md) | `my-comments` | 1 |
| [reaction.md](reaction.md) | `reaction` | 3 |
| [reaction-types.md](reaction-types.md) | `reaction-types` | 3 |
| [reactions.md](reactions.md) | `reactions` | 6 |
| [reports.md](reports.md) | `reports` | 9 |
| [scrap.md](scrap.md) | `scrap` | 3 |
| [scraps.md](scraps.md) | `scraps` | 6 |
| [settings.md](settings.md) | `settings` | 5 |
| [users.md](users.md) | `users` | 2 |

<!-- @generated:end -->

## 회원 이메일 노출 범위

응답에 실리는 회원 이메일(`author.email`, `user.email`, `reporter.email` 등)은 **관리자
엔드포인트 응답**과 **본인이 자기 정보를 조회할 때**만 값이 채워집니다. 그 밖의 경로에서는
필드가 그대로 있고 값만 `null` 이므로 응답 구조는 변하지 않습니다.

인증 없이 열람되는 목록·상세 응답에 회원 이메일을 실으면, 화면이 그 값을 그리지 않더라도
응답 본문에서 회원 주소록을 수집할 수 있기 때문입니다. 이메일이 필요한 화면은 관리자
엔드포인트를 사용하세요.

예외로 인기글 목록(`GET boards/popular`)은 호출자와 무관하게 캐시에서 제공되므로 작성자 이메일을 담지 않습니다 — 관리자·본인이 불러도 `author.email` 은 `null` 입니다.

공통 규약: [코어 API 레퍼런스 진입점](../../../../../docs/backend/api/README.md#회원-이메일-노출-범위)

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

