# API 레퍼런스 문서 목차

> **소유**: 플러그인 `sirsoft-gdpr` · **생성**: `php artisan api:docgen` (실측 기반).
> 아래 표는 자동 생성됩니다. 각 문서를 열면 엔드포인트별 파라미터·응답·예시를 볼 수 있습니다.

<!-- @generated:start:api-readme-index -->
- **문서 수**: 6 · **엔드포인트 수**: 17

| 문서 | 도메인 | 엔드포인트 |
| --- | --- | --- |
| [annual-notice.md](annual-notice.md) | `annual-notice` | 1 |
| [consent.md](consent.md) | `consent` | 7 |
| [consent-log.md](consent-log.md) | `consent-log` | 1 |
| [notice-log.md](notice-log.md) | `notice-log` | 1 |
| [policy-versions.md](policy-versions.md) | `policy-versions` | 4 |
| [settings.md](settings.md) | `settings` | 3 |

<!-- @generated:end -->


## 회원 이메일 노출 범위

응답에 실리는 회원 이메일(`author.email`, `user.email`, `reporter.email` 등)은 **관리자
엔드포인트 응답**과 **본인이 자기 정보를 조회할 때**만 값이 채워집니다. 그 밖의 경로에서는
필드가 그대로 있고 값만 `null` 이므로 응답 구조는 변하지 않습니다.

인증 없이 열람되는 목록·상세 응답에 회원 이메일을 실으면, 화면이 그 값을 그리지 않더라도
응답 본문에서 회원 주소록을 수집할 수 있기 때문입니다. 이메일이 필요한 화면은 관리자
엔드포인트를 사용하세요.

공통 규약: [코어 API 레퍼런스 진입점](../../../../../docs/backend/api/README.md#회원-이메일-노출-범위)
