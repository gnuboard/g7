# API 레퍼런스 문서 목차

> **소유**: 모듈 `sirsoft-page` · **생성**: `php artisan api:docgen` (실측 기반).
> 아래 표는 자동 생성됩니다. 각 문서를 열면 엔드포인트별 파라미터·응답·예시를 볼 수 있습니다.

<!-- @generated:start:api-readme-index -->
- **문서 수**: 2 · **엔드포인트 수**: 17

| 문서 | 도메인 | 엔드포인트 |
| --- | --- | --- |
| [attachments.md](attachments.md) | `attachments` | 3 |
| [pages.md](pages.md) | `pages` | 14 |

<!-- @generated:end -->

## 다국어 값 필드

페이지 제목 등 `{"ko": …, "en": …}` 원본에서 한 언어를 골라 주는 필드는 코어 규약을 따릅니다 — 요청 언어 → 사이트 기본 언어 → 영어 순으로 비어 있지 않은 값, 셋 다 없으면 저장된 첫 값(`App\Support\LocalizedValue::pick`, 7.1.1). 자세한 내용은 코어 [API 레퍼런스 공통 규약 「로케일」](../../../../../docs/backend/api/README.md#로케일)을 보세요.
