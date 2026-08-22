# NHN KCP 휴대폰 본인확인 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 키 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `is_test_mode` | `boolean` | `true` | 테스트 모드 |
| `test_site_cd` | `string` | `AO7F3` | 테스트 사이트코드 |
| `test_enc_key` | `string` | `c2a22fa3ebe4698075bcac6b433d52e351c881b02fb83488d4283a43385b1f8e` | 테스트 암호화 키 |
| `live_site_cd` | `string` | - | 운영 사이트코드 |
| `live_enc_key` | `string` | - | 운영 암호화 키 |
| `web_siteid` | `string` | - | 웹사이트 ID |
| `duplicate_field` | `enum` | `di` | 중복 판정 필드 |
| `duplicate_block_enabled` | `boolean` | `true` | 중복 가입 차단 |

기본값 파일: `config/settings/defaults.json` · 설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.json`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
`sirsoft-verification_kginicis`가 `mid`+`api_key` 2종 키 쌍을 쓰는 것과 달리 이 플러그인은
`site_cd`+`enc_key`(대칭 암호화 키 1개)만 씁니다 — NHN KCP 본인확인 API 는 서명 검증용
별도 API 키가 없고 site_cd/enc_key 조합만으로 인증합니다. `web_siteid`는 두 자격증명
쌍(`test_*`/`live_*`)과 별개로 테스트·라이브 모드 공통으로 쓰이는 웹사이트 식별자입니다.
`live_site_cd`는 `is_test_mode=false`일 때만 `ValidateKcpSettingsListener`가 필수로
강제하고, 저장 시점에 `SM` 프리픽스를 자동으로 붙입니다(§AGENTS.md "핵심 흐름" — kginicis
와 달리 DB 에 프리픽스가 붙은 값이 저장됩니다). `duplicate_field`/`duplicate_block_enabled`는
kginicis 와 동일한 개념이지만 서로 다른 provider 를 통해 확인한 사용자에게 각자 독립
적용됩니다.
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
| `api` | `src/routes/api.php` | `/api/plugins/sirsoft-verification_nhnkcp/...` |
| `web` | `src/routes/web.php` | `/plugins/sirsoft-verification_nhnkcp/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
`sirsoft-verification_kginicis`와 동일한 3-엔드포인트 구조입니다 — `web`의 콜백(CSRF 제외,
KCP 인증 화면이 직접 POST)은 데스크톱 팝업과 모바일 리다이렉트 양쪽 경로가 **같은
엔드포인트로 수렴**합니다(§AGENTS.md "핵심 흐름" — 기기별 진입 방식만 다를 뿐 콜백 처리는
공통). `bridge`는 팝업-opener 간 postMessage 중계 페이지, `api`는 로그인 사용자가 자기
본인확인 상태를 조회하는 마이페이지 엔드포인트입니다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

없음 — 코어만으로 동작합니다.

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

없음.
<!-- @generated:dependencies END -->

<!-- @intent START -->
`sirsoft-verification_kginicis`와 마찬가지로 이 플러그인은 `sirsoft-ecommerce`를 비롯한
어떤 확장에도 의존하지 않습니다 — 본인확인은 결제와 무관하게 회원가입 등 코어 인증 흐름
전반에 쓰이는 기능이라, 이커머스가 설치되지 않은 사이트에서도 단독으로 동작해야 합니다.
<!-- @intent END -->
