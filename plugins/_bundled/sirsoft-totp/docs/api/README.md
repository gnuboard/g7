# API 레퍼런스 문서 목차

> **소유**: 플러그인 `sirsoft-totp` · **생성**: `php artisan api:docgen` (실측 기반).
> 아래 표는 자동 생성됩니다. 각 문서를 열면 엔드포인트별 파라미터·응답·예시를 볼 수 있습니다.

<!-- @generated:start:api-readme-index -->
- **문서 수**: 3 · **엔드포인트 수**: 6

| 문서 | 도메인 | 엔드포인트 |
| --- | --- | --- |
| [backup-codes.md](backup-codes.md) | `backup-codes` | 1 |
| [credentials.md](credentials.md) | `credentials` | 1 |
| [enrollment.md](enrollment.md) | `enrollment` | 4 |

<!-- @generated:end -->

## 문서 고르기

[totp.md](totp.md) 는 이 플러그인 API 의 개요다. 확정, 해제, 재발급이 함께 쓰는 `code` 입력 규칙과 공통 에러는 「공통 사항」에, 로그인 2단계 인증의 코드 확인이 코어 API 몫이라는 안내는 「여기에 없는 것」에 있고, 엔드포인트 6개가 각각 어느 문서에 있는지는 「엔드포인트 문서」 표가 메서드와 경로로 짚어 준다. 생성 블록이 없는 손 작성 문서라 생성기가 다시 쓰지 않으며, 위 자동 생성 목차에도 들지 않는다.

엔드포인트별 요청 파라미터, 응답 필드, 에러 표는 아래 도메인 문서에 있다.

| 쓰는 곳 | 문서 |
| --- | --- |
| 본인 인증 앱 등록 상태 조회, 발급, 확정, 해제 | [enrollment.md](enrollment.md) |
| 본인 백업 코드 재발급 | [backup-codes.md](backup-codes.md) |
| 관리자의 회원 인증 앱 강제 해제 | [credentials.md](credentials.md) |
