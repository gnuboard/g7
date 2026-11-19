# SSO 연동 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_`getSettingsSchema()` 선언이 없습니다._

설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.g7.html`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
`getSettingsSchema()` 는 비어 있습니다. 정책은 `sso_site_policies` 1행, IdP 설정은 `sso_identity_providers` 에 플러그인 API(`/api/plugins/sirsoft-sso/admin/policy`, `admin/idps`)로 저장합니다. 코어 설정 저장(`core.plugins.update`)으로는 최고관리자 전용 검사를 403 으로 낼 수 없고, 같은 키를 코어 경로로 우회 저장할 수 있기 때문입니다. 정책 키와 기본값(`PolicyService::DEFAULTS`):

| 키 | 기본값 | 의미 |
|---|---|---|
| `enforce_site` | `false` | 사이트 전체 강제(최고관리자만). 켜려면 활성 IdP 가 하나 이상 있어야 한다. 켜면 셀프 가입도 닫힌다 |
| `enforce_roles` | `[]` | 강제할 역할(최고관리자만) |
| `enforce_linked` | `false` | 회사 계정이 연결된 회원 전원 강제 |
| `emergency_user_ids` | 가장 먼저 만든 최고관리자 | 비상 계정(최고관리자만, 0명으로 저장할 수 없다). 비밀번호가 없는 계정은 화면에 경고가 뜨고 비상 계정 구실을 하지 못한다 |
| `auto_redirect_idp` | `null` | 로그인 화면 자동 이동 IdP(사이트 전체 강제가 켜졌을 때만, LDAP 제외) |
| `session_max_minutes` | `0` | 사이트 세션 수명 상한(0 이면 끔). IdP 값과 작은 쪽을 쓴다 |
| `discovery_enabled` | `true` | 이메일 먼저 입력 판정 |
| `state_ttl_minutes`, `pending_ttl_minutes` | `10`, `10` | 로그인 요청, 동의와 연결 확인 대기(분) |
| `attempts_retention_days`, `scim_retention_days`, `session_retention_days` | `90`, `90`, `30` | 이력 보존(일) |
| `failure_alert_count`, `failure_alert_window_minutes` | `5`, `10` | IdP 연속 실패 알림 기준 |
| `cert_expiry_notice_days` | `[30, 7]` | 인증서 만료 알림(며칠 전) |

`enforcement_backup` 은 저장 API 가 받지 않는 칸으로, `sirsoft-sso:enforcement --disable` 이 이전 강제 값을 보관합니다. IdP 칸은 드라이버 `configSchema()` 가 정의하고 화면이 그 정의로 폼을 그립니다. 비밀값은 응답에 마스크로만 나가며, 마스크나 빈 값을 그대로 보내면 유지, `null` 이면 지웁니다. 앱 키 교체와 비밀값은 [운영자 가이드](operator-guide.md) 에 있습니다.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 카테고리 | 이름 | 액션 | 라우트 키 |
|---|---|---|---|
| `settings` | SSO 설정 | `view`, `update` | - |
| `accounts` | 회원 연결 | `view`, `unlink`, `provision` | - |
| `logs` | SSO 이력 | `view` | - |
<!-- @generated:permissions END -->

<!-- @intent START -->
권한 6종은 모두 관리자 권한(`type=admin`)입니다. 기본으로 `admin` 역할이 모두 갖고, 플러그인 역할 「SSO 운영자」(`sirsoft-sso.manager`)는 회원 연결 열람, 해제, 추가와 이력 열람만 갖습니다(설정 변경 없음).

권한과 별개로 서비스가 최고관리자만 허용하는 일이 있습니다. 위임은 권한으로 하되 관리자 권한으로 번지는 일은 위임할 수 없게 하려는 것입니다.

- 정책의 사이트 전체 강제, 역할 강제, 비상 계정 변경 — 아니면 403 `super_only`
- 관리자 권한 역할로 이어지는 역할 매핑 규칙의 추가, 수정, 삭제, 그리고 관리자가 연결된 IdP 의 역할 매핑 방식과 그룹 출처 변경
- 관리자, 최고관리자 계정을 대상으로 하는 연결 추가 — 아니면 403 `admin_target_manual_only`(CSV 는 그 행을 건너뜀)

설정 화면 진입은 코어 자동 경로라 코어 `core.plugins.read` 가 필요하고, 화면 안의 조회와 저장은 `settings.view`, `settings.update` 가 막습니다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 구분 | slug | 이름 | URL | 하위 |
|---|---|---|---|---|
| 관리자 | `sirsoft-sso` | SSO 연동 | - | 2개 |
<!-- @generated:menus END -->

<!-- @intent START -->
부모 「SSO 연동」 아래 「회원 연결」(`/admin/plugins/sirsoft-sso/accounts`, `accounts.view`)과 「이력」(`/admin/plugins/sirsoft-sso/logs`, `logs.view`) 둘입니다. 설정 화면은 코어 「플러그인 관리 → 설정」 자동 경로라 메뉴를 두지 않습니다. 화면 권한은 `resources/routes.json` 의 `meta.permission` 과 API 미들웨어가 집행합니다. 활성화 때 선언에서 빠진 옛 메뉴를 거두고, 비활성화는 메뉴를 남기며, 삭제 때 메뉴를 지웁니다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `api` | `src/routes/api.php` | `/api/plugins/sirsoft-sso/...` |
| `web` | `src/routes/web.php` | `/plugins/sirsoft-sso/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
웹 라우트(`/plugins/sirsoft-sso/…`)는 브라우저와 IdP 가 오가는 주소라 JSON 이 아니라 302, 자동 제출 폼, XML 로 답합니다. IdP 콘솔에 넣는 주소는 모두 사이트 주소(`SiteUrl`)로 만듭니다.

| 주소 | 용도 | 비고 |
|---|---|---|
| GET `login/{idp}` | 로그인 왕복 시작 | 로그인 외 의도(연결, 재확인, 테스트)는 1회용 `ticket` 만 받음 |
| GET, POST `oidc/{idp}/callback` | OIDC Redirect URI | CSRF 면제, state + 묶음 쿠키 |
| POST `oidc/{idp}/backchannel-logout` | OIDC back-channel 로그아웃 | CSRF 면제, `logout_token` 서명과 `jti` 1회 |
| GET `saml/{idp}/metadata` | SP 메타데이터 | 서명하지 않음 |
| POST `saml/{idp}/acs` | SAML ACS | CSRF 면제 |
| GET, POST `saml/{idp}/sls` | SAML 단일 로그아웃 | CSRF 면제, 서명된 요청만 |
| GET `logout/{idp}`, `logout/return` | IdP 로그아웃 이동과 복귀(Post logout redirect URI) | 1회용 티켓 |
| GET `test-result/{code}` | 브라우저 로그인 테스트 결과 | 한 번 보면 지워짐 |
| `mock/oidc/*`, `mock/saml/*` | 모의 IdP | 게이트가 꺼지면 404 |

API(`/api/plugins/sirsoft-sso/…`)는 공개 흐름(`idps`, `pending/*`, `link/*`), 마이페이지(`me/*`), 관리자(`admin/idps`, `admin/policy`, `admin/accounts`, `admin/logs`), SCIM(`scim/v2/{idp}/*`)으로 나뉩니다. SCIM 응답은 G7 응답 봉투가 아니라 `application/scim+json` 자원 그대로이고 오류도 SCIM 오류 스키마입니다. 요청 제한은 라우트마다 버킷을 따로 셉니다.

플러그인이 비활성이면 이 주소들이 모두 404 가 됩니다. IdP 가 보내는 SAML ACS, SCIM, back-channel 요청도 그동안 유실됩니다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

없음 — 코어만으로 동작합니다.

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

없음.
<!-- @generated:dependencies END -->

<!-- @intent START -->
다른 확장에 의존하지 않습니다. 코어 7.2.0 의 외부 인증 확장점(브라우저에 묶인 로그인 인계, 비밀번호 인증 정책, 외부 자격 증명 검증자, 진입 판정, 자동 이동, 로그아웃 이동, 칸 잠금, 가입 닫기, 회원 차단과 강제 로그아웃, 시스템 작업 문맥, 회원별 본인인증 수단)을 전제로 합니다. 외부 패키지 `onelogin/php-saml`, `firebase/php-jwt` 는 vendor-bundle 로 함께 싣습니다. GDPR 플러그인이 있으면 묶음 쿠키를 필수 저장소로 알리고, 없어도 동작합니다. 서버에 `ext-ldap` 이 없으면 LDAP 만 쓸 수 없습니다.
<!-- @intent END -->
