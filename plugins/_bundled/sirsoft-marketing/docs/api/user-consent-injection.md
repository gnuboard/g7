# 코어 User 응답 마케팅 동의 필드 주입 (Hook Injection)

> **소유**: plugin `sirsoft-marketing` · 이 문서는 `api:docgen` 생성 대상이 아닌 **훅 주입 계약** 레퍼런스다(플러그인이 코어 응답에 필드를 주입하므로 라우트 단위 문서로 표현되지 않는다). 코어 API 문서(`docs/backend/api/users.md`, `me.md`, `profile.md`)의 응답 필드 표에서 "확장 소유"로 표기된 `*_consent`/`notify_*`/`{channel}_*` 필드의 실제 계약이 여기에 있다.

---

## TL;DR (5초 요약)

```text
1. sirsoft-marketing 은 core.user.filter_resource_data 훅으로 코어 User 응답에 마케팅 동의 필드를 병합한다
2. 주입 대상: /me, /auth/user, /admin/users/{user}, 프로필 등 User 리소스를 반환하는 모든 코어 엔드포인트
3. 주입 필드는 활성 채널·법적 항목·마스터 키에 따라 동적 — 고정 스키마가 아니다
4. 각 동의 항목마다 상태({key})·시각({key}_at)·활성화 플래그({key}_enabled)·약관 slug 3종을 함께 내린다
5. 이 필드들이 코어 문서에서 TODO 로 남은 이유: 코어가 아니라 이 플러그인이 소유하기 때문
```

---

## 왜 이 문서가 필요한가

코어 `UserResource` 등 User 계열 리소스는 `core.user.filter_resource_data` 필터 훅을 발화한다.
`sirsoft-marketing` 플러그인의 `MarketingConsentListener::filterResourceData()` 가 이 훅을 구독해
**코어 User 응답 `data` 에 마케팅 동의 관련 필드를 병합**한다. 따라서 `docs/backend/api/` 의 코어 User
문서에 나타나는 `marketing_consent`, `third_party_consent_at`, `email_subscription_enabled`,
`channels`, `consent_histories` 같은 필드는 코어 코드에는 없고 이 플러그인이 런타임에 주입한 것이다.

코어 문서 생성기(`api:docgen`)는 정적 리플렉션으로 코어 Resource `toArray()` 만 읽으므로 이 필드들을
설명하지 못하고 TODO 로 남긴다. 그 설명의 SSoT 가 이 문서다.

## 주입 지점

`core.user.filter_resource_data` 훅을 발화하는 모든 코어 엔드포인트에서 주입된다. 대표적으로:

| 엔드포인트 | 문서 |
|---|---|
| `GET /api/me` | `docs/backend/api/me.md` |
| `GET /api/auth/user`, `GET /api/user/auth/user` | `docs/backend/api/auth.md` |
| `GET /api/admin/users/{user}` | `docs/backend/api/users.md` |
| 프로필 조회/수정 응답 | `docs/backend/api/profile.md` |

## 주입 필드 계약

주입 필드 집합은 **활성 채널 목록 + 법적 필수 항목 + 마스터 키**에 따라 동적으로 결정된다. 고정 스키마가
아니므로 아래는 필드 **패턴**으로 기술한다.

### 동의 상태 필드 (동의 키별 2개)

동의 키 집합 = 활성 채널 키(`channels` JSON 의 `enabled` 채널) ∪ 마스터 키 `marketing_consent`
∪ 활성화된 법적 키(`third_party_consent`, `info_disclosure` 중 `*_enabled` 인 것).

| 필드 | 타입 | 용도/설명 |
|---|---|---|
| `{consent_key}` | boolean | 해당 항목에 동의했는지. 동의 레코드 없으면 `false` |
| `{consent_key}_at` | string\|null | 동의 시각(ISO 8601). 미동의면 `null` |

- `marketing_consent` (마스터 키): 마케팅 채널 전체 동의/철회를 제어하는 상위 키.
- `third_party_consent`, `info_disclosure`: 법적 필수 동의 항목(고정 키, 활성화 시에만 주입).
- 그 외 키는 관리자가 정의한 동적 채널 키(예: `email_subscription`, `sms_subscription`).

### 활성화·약관 플래그 필드 (프론트 조건부 렌더링용)

마스터·법적·채널 각각에 대해 3종 플래그를 내린다. 값 규칙은 공개 `GET /settings` 응답과 동일하다.

| 필드 패턴 | 타입 | 용도/설명 |
|---|---|---|
| `marketing_consent_enabled` | boolean | 마케팅 전체 동의 UI 노출 여부(기본 `true`) |
| `marketing_consent_terms_slug` | string\|null | 마케팅 약관 slug(미설정 `null`) |
| `marketing_consent_terms_slug_set` | boolean | 마케팅 약관 존재 여부 |
| `third_party_consent_enabled` / `_terms_slug` / `_terms_slug_set` | boolean/string·null/boolean | 제3자 제공 동의 항목 플래그 |
| `info_disclosure_enabled` / `_terms_slug` / `_terms_slug_set` | boolean/string·null/boolean | 정보 이용 안내 동의 항목 플래그 |
| `{channel_key}_enabled` / `_terms_slug` / `_terms_slug_set` | boolean/string·null/boolean | 동적 채널별 노출·약관 플래그(모든 채널에 대해, 활성 여부 무관) |

> 약관 slug 은 `*_terms_slug`(값)와 `*_terms_slug_set`(존재 플래그)을 함께 내린다. 프론트는 링크 표시를
> `*_terms_slug_set` 으로 판정한다. 공개 `settings.md` 계약과 동일하다.

### 컬렉션 필드

| 필드 | 타입 | 용도/설명 |
|---|---|---|
| `channels` | array | 관리자 정의 전체 채널 목록(iteration 렌더링용). 원소: `key`, 현재 로케일 `label`, `enabled`, `terms_slug`, `terms_slug_set` |
| `consent_histories` | array | 이 사용자의 동의 변경 이력. 원소: `channel_key`, `action`, `source`(register/admin/profile), `created_at` |

## 주의사항

- **책임 분리**: 이 필드들의 값 규칙·존재 여부는 전적으로 `sirsoft-marketing` 이 결정한다. 플러그인을
  비활성화하면 코어 User 응답에서 이 필드들이 사라진다. 코어 문서가 이들을 TODO 로 남긴 것은 오류가
  아니라 책임 분리의 결과다.
- **동적 스키마**: 채널 추가/삭제(`PUT /admin/channels`)나 법적 항목 활성화 설정에 따라 주입 필드 집합이
  바뀐다. 소비처는 고정 키 목록을 가정하지 말고 `channels` 배열을 순회하는 것을 권장한다.
- **쓰기 경로**: 이 필드들은 조회 시 주입되지만, 저장은 회원가입(`agree_{key}`)·사용자 생성/수정
  (`{key}`) 요청 필드를 같은 리스너가 validation rule 훅으로 추가하고 action 훅에서 별도 저장한다.
  User 테이블 컬럼이 아니라 별도 `marketing_consents` EAV 테이블에 보관된다.

## 관련

- [settings.md](settings.md) — 비로그인 공개 설정(동일한 `*_terms_slug_set`·`channels` 계약)
- [channels.md](channels.md) — 채널 목록 저장(동적 채널 키의 출처)
- `docs/backend/api/users.md`, `me.md`, `profile.md`, `auth.md` — 주입 대상 코어 문서
- `docs/extension/hooks.md` — Filter 훅(`core.user.filter_resource_data`) 계약
