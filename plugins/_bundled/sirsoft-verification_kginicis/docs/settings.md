# KG이니시스 본인인증 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 키 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `is_test_mode` | `boolean` | `true` | 테스트 모드 |
| `test_mid` | `string` | `INIiasTest` | 테스트 MID |
| `test_api_key` | `string` | `TGdxb2l3enJDWFRTbTgvREU3MGYwUT09` | 테스트 API 키 |
| `live_mid` | `string` | - | 라이브 MID |
| `live_api_key` | `string` | - | 라이브 API 키 |
| `duplicate_field` | `enum` | `di` | 중복 판정 필드 |
| `duplicate_block_enabled` | `boolean` | `true` | 중복 가입 차단 |

기본값 파일: `config/settings/defaults.json` · 설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.json`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
결제 PG 플러그인들의 `test_*`/`live_*` 쌍과 같은 구조이지만 필드는 단 2개(`test_mid`/
`test_api_key`, `live_mid`/`live_api_key`)뿐입니다 — 본인확인 API 는 결제와 달리 사인키·
INIAPI 키·모바일 해시키처럼 기능별로 분리된 자격증명이 필요 없습니다. `live_mid`는
`InicisIdentityProvider::buildLiveMid()`가 그 값을 요청 조립 시점에 쓸 때 `SRB` 프리픽스를
동적으로 부착하므로(DB 저장값은 원본 그대로) 운영자가 프리픽스를 직접 입력할 필요가
없습니다. `live_mid`/`live_api_key`는 `is_test_mode=false`일 때만
`ValidateInicisSettingsListener`가 `required`로 강제합니다(§AGENTS.md "핵심 흐름").
`duplicate_field`/`duplicate_block_enabled`는 `sirsoft-ecommerce`와 무관한 코어 레벨
회원가입 규칙입니다 — 이 플러그인은 이커머스에 의존하지 않습니다(§의존 관계).
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_선언된 권한이 없습니다._
<!-- @generated:permissions END -->

<!-- @intent START -->
본인인증 설정 접근 권한은 코어의 관리자 권한 체계 안에서 다뤄집니다 — IDV provider 마다
별도 권한을 선언하면 provider 를 여러 개 설치했을 때 "본인인증 설정을 볼 수 있는 사람"이
provider 수만큼 중복 정의됩니다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 메뉴가 없습니다._
<!-- @generated:menus END -->

<!-- @intent START -->
설정 화면(`plugin_settings.json`)은 코어의 "플러그인 관리 > 설정" 공통 진입점을 통해
접근합니다 — IDV provider 마다 전용 사이드바 메뉴를 만들면 provider 를 여러 개 설치했을
때 메뉴가 난립합니다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `api` | `src/routes/api.php` | `/api/plugins/sirsoft-verification_kginicis/...` |
| `web` | `src/routes/web.php` | `/plugins/sirsoft-verification_kginicis/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
`web` 라우트는 CSRF 검증을 명시적으로 제외합니다(`ValidateCsrfToken` 제외 그룹) — 이니시스
결제창이 팝업 안에서 우리 서버로 폼을 직접 POST 하는데, 그 요청은 우리 CSRF 토큰을 모르기
때문입니다. `popup-bridge`는 팝업과 opener 창 사이의 postMessage 중계용 정적 페이지라
별도 인증이 필요 없습니다. `api`는 로그인 사용자가 Bearer 토큰으로 자기 본인확인 상태를
조회하는 마이페이지 엔드포인트(`GET /me/identity/inicis`)입니다 — 챌린지 시작 자체는
코어 `/api/identity/challenges`가 담당하므로 이 플러그인의 `api` 라우트에는 없습니다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

없음 — 코어만으로 동작합니다.

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

없음.
<!-- @generated:dependencies END -->

<!-- @intent START -->
결제 PG 플러그인들과 달리 이 플러그인은 `sirsoft-ecommerce`에 의존하지 않습니다 — 본인확인은
결제와 무관하게 회원가입·비밀번호 찾기 등 코어 인증 흐름 전반에 쓰이는 기능이라, 이커머스가
설치되지 않은 사이트(게시판만 운영하는 등)에서도 단독으로 동작해야 합니다.
<!-- @intent END -->
