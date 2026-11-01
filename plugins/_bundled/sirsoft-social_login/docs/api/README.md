# API 레퍼런스 문서 목차

> **소유**: 플러그인 `sirsoft-social_login`, **생성**: `php artisan api:docgen` (실측 기반).
> 아래 목차 표는 자동 생성됩니다. 도메인 문서를 열면 엔드포인트별 파라미터, 응답, 예시를 볼 수 있습니다.

<!-- @generated:start:api-readme-index -->
- **문서 수**: 15 · **엔드포인트 수**: 49

| 문서 | 도메인 | 엔드포인트 |
| --- | --- | --- |
| [accounts.md](accounts.md) | `accounts` | 3 |
| [attempts.md](attempts.md) | `attempts` | 1 |
| [data-deletion.md](data-deletion.md) | `data-deletion` | 1 |
| [diagnostics.md](diagnostics.md) | `diagnostics` | 1 |
| [linked-providers.md](linked-providers.md) | `linked-providers` | 1 |
| [mock.md](mock.md) | `mock` | 9 |
| [oauth.md](oauth.md) | `oauth` | 3 |
| [providers.md](providers.md) | `providers` | 10 |
| [reauth.md](reauth.md) | `reauth` | 3 |
| [signup.md](signup.md) | `signup` | 7 |
| [stats.md](stats.md) | `stats` | 1 |
| [test-result.md](test-result.md) | `test-result` | 1 |
| [users.md](users.md) | `users` | 2 |
| [webhook-events.md](webhook-events.md) | `webhook-events` | 1 |
| [webhooks.md](webhooks.md) | `webhooks` | 5 |

<!-- @generated:end -->

## 문서 고르기

[social-login.md](social-login.md) 는 이 플러그인 API 전체를 한 문서로 정리한 개요다. 응답 봉투, 시각 표기, 실패 코드(`errors.code`) 표, 웹 엔드포인트 요약처럼 여러 경로에 걸친 규약은 이 문서에만 있다. 생성 블록이 없는 손 작성 문서라 위 목차에는 나오지 않는다.

엔드포인트별 요청 파라미터, 응답 필드, 에러 표는 아래 도메인 문서에 있다.

| 쓰는 곳 | 문서 |
| --- | --- |
| 로그인, 가입 화면의 제공자 버튼 (공개) | [providers.md](providers.md) 의 `GET providers` |
| 로그인 왕복 (웹, 302 리다이렉트) | [oauth.md](oauth.md) |
| 가입 모달 (게스트, 가입 대기 토큰) | [signup.md](signup.md) |
| 마이페이지 연결 관리 (로그인 회원 본인) | [accounts.md](accounts.md), [reauth.md](reauth.md) |
| 관리자 제공자 설정, 연결 테스트 | [providers.md](providers.md), [test-result.md](test-result.md) |
| 관리자 진단, 기록, 통계 | [diagnostics.md](diagnostics.md), [attempts.md](attempts.md), [webhook-events.md](webhook-events.md), [stats.md](stats.md), [linked-providers.md](linked-providers.md) |
| 관리자 회원 상세의 소셜 계정 탭 | [users.md](users.md) |
| 제공자 웹훅 수신 (카카오, 네이버, 페이스북) | [webhooks.md](webhooks.md), [data-deletion.md](data-deletion.md) |
| 개발과 시험용 모의 제공자 (서버에서 켰을 때만 응답) | [mock.md](mock.md) |
