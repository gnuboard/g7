# 마케팅 동의 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 키 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `marketing_consent_enabled` | `boolean` | `true` | 마케팅 동의 사용 |
| `marketing_consent_terms_slug` | `string` | `marketing-terms` | 마케팅 동의 약관 페이지 Slug |
| `channels` | `json` | `[]` | 채널 목록 |
| `third_party_consent_enabled` | `boolean` | `true` | 제3자 제공 동의 사용 |
| `third_party_consent_terms_slug` | `string` | - | 제3자 제공 동의 약관 페이지 Slug |
| `info_disclosure_enabled` | `boolean` | `true` | 정보 공개 동의 사용 |
| `info_disclosure_terms_slug` | `string` | - | 정보 공개 동의 약관 페이지 Slug |

기본값 파일: `config/settings/defaults.json` · 설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.json`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
7개 항목이 두 무리입니다.

- **법정 동의 3종** — `marketing_consent_*` · `third_party_consent_*` · `info_disclosure_*`.
  각각 사용 여부(boolean)와 약관 페이지 slug(string) 쌍입니다. 이 셋은 성격이 정해져 있어
  코드에 이름이 박혀 있습니다.
- **`channels`** — 운영자가 늘리는 수신 채널 목록(JSON). 이것이 이 플러그인의 핵심 설정이며,
  여기에 항목을 더하면 가입 폼·마이페이지·회원 화면에 **즉시** 나타납니다.

`channels` 만 `frontend_schema` 에서 `expose: false` 이고 타입이 `string` 입니다 — 실제 값은
JSON 문자열이며, 화면이 그대로 그릴 수 있는 형태가 아니라 서비스가 해석해 내려줍니다. 저장
형태 정규화는 `core.plugin_settings.filter_save_data` 훅에서 이루어집니다.

약관 slug 셋은 **페이지 모듈의 문서**를 가리킵니다(manifest 의존 `sirsoft-page >=1.0.0`).
문서가 없으면 링크가 열리지 않을 뿐 동의 자체는 동작하므로, 도입 시 약관을 먼저 작성하는
순서를 안내합니다.

채널을 **사용중지**로 바꾸면 새 노출에서는 빠지지만 기존 동의 기록은 남습니다 —
`getRegisteredChannels()`(활성만)와 `getAllChannels()`(전체)가 나뉘어 있는 이유입니다. 다시
켰을 때 그 회원이 재동의할 필요가 없도록 하기 위한 것입니다.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_선언된 권한이 없습니다._
<!-- @generated:permissions END -->

<!-- @intent START -->
선언하지 않습니다. 이 플러그인의 데이터는 **회원 자신의 것**이라 회원 권한 체계에 얹히고,
운영자 조작은 코어 회원 권한이 이미 관장합니다.

관리자 채널 저장 라우트만 코어 권한(`permission:admin,core.plugins.update`)을 직접 지정합니다 —
"플러그인 설정을 고칠 수 있는 사람" 이라는 기존 권한에 얹는 방식입니다.

자기 권한을 새로 만들지 않은 것은 의도입니다. 권한을 늘리면 운영자가 역할마다 그 권한을
배정해야 하는데, "마케팅 동의만 따로 관리하는 담당자" 라는 역할 구분이 실제로 필요해지기
전까지는 그 부담이 이득보다 큽니다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 메뉴가 없습니다._
<!-- @generated:menus END -->

<!-- @intent START -->
등록하지 않습니다. 이 플러그인은 자기 관리 화면을 갖지 않고, 동의 상태는 **회원 관리 화면
안에서** 조각으로 보입니다.

설정은 코어의 플러그인 목록에서 이 플러그인의 설정으로 들어가는 공통 경로를 씁니다 — 코어가
`resources/layouts/admin/plugin_settings.json` 을 찾아 그리므로 자체 메뉴가 필요 없습니다.

동의 현황을 회원과 분리해 따로 보는 화면(예: 채널별 동의자 목록)이 필요해지면 그때 메뉴가
생길 자리입니다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `api` | `src/routes/api.php` | `/api/plugins/sirsoft-marketing/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
2개뿐입니다.

| 경로 | 용도 |
|---|---|
| `GET /settings` | 프론트가 동의 항목 구성(활성 채널·약관 slug·사용 여부)을 조회 |
| `PUT admin/channels` | 운영자가 채널 목록을 저장 (`permission:admin,core.plugins.update`) |

**동의 값 자체를 읽고 쓰는 엔드포인트가 없습니다.** 그 일은 코어 회원 API 를 타고 이루어지며,
이 플러그인은 `core.user.filter_update_data` / `filter_resource_data` 훅으로 그 흐름에
끼어듭니다. 그래서 이 플러그인을 비활성화하면 회원 API 응답에서 동의 필드가 함께 사라집니다.

라우트를 바꾼 뒤에는 라우트 캐시를 다시 굽습니다. 확장 라우트는 활성 상태인 확장의 것만
등록되고, 캐시에 없는 라우트는 예외도 경고도 없이 404 가 됩니다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

| 확장 | 유형 | 버전 제약 | 번들 |
|---|---|---|---|
| `sirsoft-page` | 모듈 | `>=1.0.0` | ✅ |

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

없음.
<!-- @generated:dependencies END -->

<!-- @intent START -->
`sirsoft-page` 모듈에 의존합니다(`>=1.0.0`). 동의 항목의 **약관 문서**가 페이지 모듈의
콘텐츠이기 때문입니다.

manifest 의존으로 올린 것은 약관 링크가 이 플러그인의 기능 일부가 아니라 **법적 요건**이기
때문입니다 — 회원이 동의 내용을 확인할 수 없으면 그 동의는 유효하지 않습니다. 훅 구독처럼
"없으면 그 기능만 비는" 관계가 아니라, 없으면 이 플러그인의 존재 이유가 성립하지 않습니다.

이 플러그인에 의존하는 확장은 없습니다. 다만 발행 훅
(`user.subscribed`/`unsubscribed`/`consent_changed`)을 구독해 수신 목록을 관리하는 확장이
생기면, 그 확장은 이 훅 이름에 묶입니다 — 훅 이름·페이로드를 바꿀 때는 구독 확장을 전수
확인하고 최소 버전 상향을 검토합니다.
<!-- @intent END -->
