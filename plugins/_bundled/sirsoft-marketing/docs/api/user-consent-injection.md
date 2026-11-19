# 코어 User 응답 마케팅 동의 필드 주입 (Hook Injection)

> **소유**: plugin `sirsoft-marketing` · 이 문서는 `api:docgen` 생성 대상이 아닌 **훅 주입 계약** 레퍼런스다(플러그인이 코어 응답에 필드를 주입하므로 라우트 단위 문서로 표현되지 않는다). 코어 API 문서(`docs/backend/api/users.md`, `me.md`, `profile.md`)의 응답 필드 표에서 "확장 소유"로 표기된 동의 필드의 실제 계약이 여기에 있다.

---

## TL;DR (5초 요약)

```text
1. sirsoft-marketing 은 core.user.filter_resource_data 훅으로 코어 User 응답에 동의 값을 병합한다
2. 주입 대상: /me, /auth/user, /admin/users/{user}, 프로필 등 User 리소스를 반환하는 모든 코어 엔드포인트
3. 지금 받는 동의 항목마다 값({키})과 동의 시각({키}_at)을 싣고, 채널 목록(channels)과 동의 이력(consent_histories)을 함께 싣는다
4. 항목을 그릴지와 약관 링크는 응답이 아니라 회원 항목 계약(fields[])에서 읽는다 — 사용 여부, 약관 slug 필드는 응답에서 지웠다
5. 쓰기는 가입, 관리자 회원 추가와 수정, 내 정보 수정 모두 {키} 로 보낸다(가입은 옛 키 agree_{키} 도 받는다)
```

---

## 왜 이 문서가 필요한가

코어 `UserResource` 등 User 계열 리소스는 `core.user.filter_resource_data` 필터 훅을 발화한다.
`sirsoft-marketing` 플러그인의 `MarketingConsentListener::filterResourceData()` 가 이 훅을 구독해
**코어 User 응답 `data` 에 동의 관련 필드를 병합**한다. 따라서 `docs/backend/api/` 의 코어 User
문서에 나타나는 `marketing_consent`, `third_party_consent_at`, `channels`, `consent_histories` 같은
필드는 코어 코드에는 없고 이 플러그인이 런타임에 주입한 것이다.

회원 폼은 회원 항목 계약의 `name` 과 같은 키의 응답 값을 초기값으로 쓴다. 그래서 동의 값은 계약의
항목 키와 같은 이름으로 응답 최상위에 실린다.

## 주입 지점

`core.user.filter_resource_data` 훅을 발화하는 모든 코어 엔드포인트에서 주입된다. 대표적으로:

| 엔드포인트 | 문서 |
|---|---|
| `GET /api/me` | `docs/backend/api/me.md` |
| `GET /api/auth/user`, `GET /api/user/auth/user` | `docs/backend/api/auth.md` |
| `GET /api/admin/users/{user}` | `docs/backend/api/users.md` |
| 프로필 조회/수정 응답 | `docs/backend/api/profile.md` |

## 주입 필드 계약

주입 필드 집합은 **지금 받는 동의 항목**에 따라 달라진다. 고정 스키마가 아니므로 필드 **패턴**으로 적는다.

### 동의 값 필드 (동의 키마다 2개)

지금 받는 동의 키는 다음과 같다.

| 항목 | 키 | 받는 조건 |
|---|---|---|
| 전체 동의 | `marketing_consent` | 설정 `marketing_consent_enabled` 가 켜져 있을 때 |
| 수신 채널 | 운영자가 정한 채널 키(예: `email_subscription`, `sms_subscription`) | 전체 동의를 쓰고 그 채널이 사용 중일 때 |
| 법적 동의 | `third_party_consent`, `info_disclosure` | 각 `{키}_enabled` 설정이 켜져 있을 때 |

| 필드 | 타입 | 용도/설명 |
|---|---|---|
| `{키}` | boolean | 그 항목에 지금 동의한 상태인지. 동의 기록이 없으면 `false` |
| `{키}_at` | string\|null | 동의 시각(ISO 8601). 동의한 적이 없거나 철회했으면 `null` |

### 컬렉션 필드

| 필드 | 타입 | 용도/설명 |
|---|---|---|
| `channels` | array | 운영자가 정한 전체 채널 목록(끈 채널 포함 — 지난 이력에 남아 있을 수 있다). 원소: `key`, 현재 로케일 `label`, `enabled` |
| `consent_histories` | array | 이 사용자의 동의 변경 이력. 원소: `channel_key`, `action`(granted/revoked), `source`(register/admin/profile), `created_at`(보는 사람 시간대 `Y-m-d H:i:s` — 같은 화면의 가입일과 같은 기준) |

### 지운 필드

화면의 조건부 렌더링용으로 싣던 다음 필드는 더 이상 응답에 없다. 항목을 그릴지와 약관 링크는 아래
회원 항목 계약에서 읽는다.

| 지운 필드 | 대신 읽을 곳 |
|---|---|
| `marketing_consent_enabled`, `third_party_consent_enabled`, `info_disclosure_enabled`, `{채널 키}_enabled` | 회원 항목 계약의 `fields[]` 에 그 항목이 있는지 |
| `marketing_consent_terms_slug`, `third_party_consent_terms_slug`, `info_disclosure_terms_slug`, `{채널 키}_terms_slug` 와 각 `*_terms_slug_set` | 회원 항목 계약의 `ui.link` |
| `channels[]` 의 `terms_slug`, `terms_slug_set` | 회원 항목 계약의 `ui.link` |

## 회원 항목 계약에서의 모양

동의 항목은 코어 회원 항목 집합(`core.user`)의 항목으로 선언되어, 화면이 받는 항목 계약의 `fields[]` 에
다른 회원 항목과 함께 실린다.

| 엔드포인트 | 화면 |
|---|---|
| `GET /api/auth/register/fields` | 가입 화면 |
| `GET /api/me/fields` | 내 정보 수정과 보기 |
| `GET /api/admin/users/form-fields` | 관리자 회원 추가 |
| `GET /api/admin/users/{user}/form-fields` | 관리자 회원 수정과 상세 |

이 플러그인의 항목은 계약에서 다음 모양이다.

| 계약 키 | 값 |
|---|---|
| `key`, `name` | 항목 키(요청 키이자 응답 값의 키. 예: `marketing_consent`) |
| `type` | `boolean` |
| `owner_type`, `owner` | `plugin`, `sirsoft-marketing` |
| `label` | 항목 이름(요청 언어). 채널은 채널 설정의 항목명 |
| `help` | 안내 문구(전체 동의와 법적 동의. 채널은 없음) |
| `required` | 운영자가 회원 항목 표에서 정한 필수 여부(기본 `false`). 필수면 「예」여야 통과한다 |
| `constraints.widget` | `checkbox` |
| `ui.group` | `agreements` — 약관 동의 묶음 |
| `ui.role` | `master`(전체 동의), `channel`(수신 채널), `legal`(제3자 제공, 정보 공개) |
| `ui.parent` | 채널 항목만 `marketing_consent` |
| `ui.link` | 약관 페이지를 정한 항목만 실린다(아래 표). 정하지 않았으면 키가 없다 |
| `ui.notice` | 내 정보 항목 API(`GET /api/me/fields`)에서 본인이 동의한 항목만 실린다. 동의일 문구(예: `2026-11-12 동의`, 사용자 시간대 날짜). 동의하지 않은 항목과 다른 화면의 계약에는 키가 없다 |

`ui.link` 의 모양:

| 키 | 타입 | 설명 |
|---|---|---|
| `label` | string | 단추 글자(요청 언어. 예: 「보기」) |
| `title` | string | 약관 창의 제목(요청 언어) |
| `url` | string | 약관 페이지 주소 `/page/{slug}` |
| `content_api` | string | 약관 본문 API `/api/modules/sirsoft-page/pages/{slug}` — 창 안에 띄울 때 쓴다 |
| `requires_module` | string | `sirsoft-page`. 이 모듈이 꺼진 사이트에서는 단추를 그리지 않는다 |

가입 화면에 나타나는지는 운영자가 회원 항목 표에서 정한 「가입 화면 표시」를 따른다(기본 켜짐).
설정에서 끈 동의와 채널은 어느 화면의 계약에도 실리지 않는다.

## 쓰기 경로

| 경로 | 요청 키 |
|---|---|
| 가입 `POST /api/auth/register` | `{키}`. 옛 키 `agree_{키}` 도 받는다(둘이 함께 오면 `{키}` 의 값을 쓴다) |
| 관리자 회원 추가 `POST /api/admin/users` | `{키}` |
| 관리자 회원 수정 `PUT /api/admin/users/{user}` | `{키}` |
| 내 정보 수정 `PUT /api/me` | `{키}` |

- 값은 boolean 이다. 키를 보내지 않거나 값이 비어 있으면 그 항목은 건드리지 않는다.
- 바뀐 항목만 저장한다. 기록이 없는 항목에 `false` 가 오면 아무것도 남기지 않는다.
- 저장은 User 테이블 컬럼이 아니라 `user_marketing_consents`(지금 상태)와
  `user_marketing_consent_histories`(이력)에 한다. 회원 저장과 같은 트랜잭션에서 일어난다.
- 이력의 `source` 는 경로에 따라 `register`(가입), `admin`(관리자 회원 추가와 수정), `profile`(내 정보 수정)이다.
- 전체 동의를 끄면 채널 동의가 함께 꺼지고, 채널 하나에 동의하면 전체 동의가 함께 켜진다.
- 가입 완료 훅 `core.auth.after_register` 의 `registration_data` 에는 `{키}` 가 실리고, 옛 키로 보낸 요청이면
  `agree_{키}` 도 함께 실린다.

## 주의사항

- **책임 분리**: 이 필드들의 값 규칙과 존재 여부는 전적으로 `sirsoft-marketing` 이 결정한다. 플러그인을
  비활성화하면 코어 User 응답과 회원 항목 계약에서 이 항목들이 사라지고, 요청에 실린 값은 조용히 무시된다.
- **동적 스키마**: 채널 추가와 삭제(`PUT /admin/channels`), 사용 여부 설정에 따라 주입 필드 집합이 바뀐다.
  소비처는 고정 키 목록을 가정하지 말고 회원 항목 계약의 `fields[]` 를 따른다.

## 관련

- [settings.md](settings.md) — 비로그인 공개 설정(호환을 위해 남아 있는 엔드포인트)
- [channels.md](channels.md) — 채널 목록 저장(동적 채널 키의 출처)
- `docs/backend/api/users.md`, `me.md`, `profile.md`, `auth.md` — 주입 대상 코어 문서
- `docs/extension/hooks.md` — Filter 훅(`core.user.filter_resource_data`) 계약
